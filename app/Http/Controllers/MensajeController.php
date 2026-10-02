<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabCodes;
use App\Support\VeolabLicense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Mensajes entre usuarios (MENMEN), la Mensajería de Veolab (módulo COM).
 *
 *  - GET /mensajes: listado (filtros origen_*, destino_*, fecha...); 'leido'
 *    = F mientras el destinatario tenga su aviso (ACCAVI tipo M) pendiente.
 *  - POST /mensajes {origen_*, destino_*, texto}: como Mensajeria.Enviar,
 *    código del contador de la delegación del remitente, fecha del
 *    servidor y aviso M al destinatario. Veolab no los audita ni los borra.
 *  - GET /mensajes/conversacion?usuario_*&con_*[&desde=fecha]: los mensajes
 *    entre los dos usuarios en orden de envío.
 *  - POST /mensajes/leidos {usuario_*[, con_*]}: borra los avisos de mensajes
 *    del usuario (de todos o solo los de 'con'), como al abrir la conversación.
 */
class MensajeController extends BaseController
{
    protected string $table = 'MENMEN';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'MEN1COD',
    ];
    protected array $searchFields = ['MENCDES'];

    protected bool $generatesCode = true;

    protected array $foreignKeys = [
        'origen'  => 'string',
        'destino' => 'string',
    ];

    protected array $mapping = [
        'delegacion'         => 'DEL3COD',
        'codigo'             => 'MEN1COD',
        'texto'              => 'MENCDES',
        'fecha'              => 'MENTENV',
        'origen_delegacion'  => 'USO2DEL',
        'origen_codigo'      => 'USO2COD',
        'destino_delegacion' => 'USD2DEL',
        'destino_codigo'     => 'USD2COD',
    ];

    protected function rules(): array
    {
        return [
            'texto'              => 'required|string',
            'origen_delegacion'  => 'nullable|string|max:10',
            'origen_codigo'      => 'required|string|max:15',
            'destino_delegacion' => 'nullable|string|max:10',
            'destino_codigo'     => 'required|string|max:15',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'COM')) {
            throw new BusinessRuleException('El módulo de comunicaciones no está activo');
        }
        if (trim($data['texto']) === '') {
            throw new BusinessRuleException('El mensaje está vacío');
        }

        $origin = $db->table('ACCUSU')->where('DEL3COD', $data['origen_delegacion'] ?? '')->where('USU1COD', $data['origen_codigo'])->first(['USUBBAJ']);
        if (! $origin) {
            throw new BusinessRuleException('El usuario remitente no existe');
        }
        // Mensajeria solo ofrece destinatarios que no están de baja.
        $target = $db->table('ACCUSU')->where('DEL3COD', $data['destino_delegacion'] ?? '')->where('USU1COD', $data['destino_codigo'])->first(['USUBBAJ']);
        if (! $target || $target->USUBBAJ === 'T') {
            throw new BusinessRuleException('El usuario destinatario no existe o está de baja');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        // El mensaje se guarda en la delegación del remitente, con la hora del servidor.
        $data['delegacion'] = (string) ($data['origen_delegacion'] ?? '');
        $data['origen_delegacion'] = $data['delegacion'];
        $data['destino_delegacion'] = (string) ($data['destino_delegacion'] ?? '');
        $data['fecha'] = (string) DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
        unset($data['codigo']);

        return $data;
    }

    /** Aviso de mensaje (ACCAVI tipo M) al destinatario. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $destDel = $data['destino_delegacion'];
        DB::connection('dynamic')->table('ACCAVI')->insert([
            'DEL3COD' => $destDel,
            'AVI1COD' => VeolabCodes::next('ACCAVI', '', $destDel),
            'AVITFEC' => $data['fecha'],
            'AVICTIP' => 'M',
            'USU2DEL' => $destDel,
            'USU2COD' => $data['destino_codigo'],
            'MEN2DEL' => (string) $keys['delegacion'],
            'MEN2COD' => (int) $keys['codigo'],
        ]);

        return $data;
    }

    /** Mensajeria.Enviar no audita. */
    protected function auditCreated(array $data, array $keyParams): void {}

    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }

        $pending = DB::connection('dynamic')->table('ACCAVI')
            ->where('AVICTIP', 'M')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('MEN2DEL', $row['delegacion'] ?? '')->where('MEN2COD', $row['codigo']));
                }
            })
            ->get(['MEN2DEL', 'MEN2COD'])
            ->map(fn ($a) => $a->MEN2DEL.'|'.$a->MEN2COD)->flip()->all();

        foreach ($rows as &$row) {
            $row['leido'] = isset($pending[($row['delegacion'] ?? '').'|'.$row['codigo']]) ? 'F' : 'T';
        }

        return $rows;
    }

    /** Conversación entre dos usuarios, en orden de envío. */
    public function conversation(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'usuario_codigo' => 'required|string',
            'con_codigo'     => 'required|string',
            'desde'          => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $me = [(string) ($request->query('usuario_delegacion') ?? ''), (string) $request->query('usuario_codigo')];
        $other = [(string) ($request->query('con_delegacion') ?? ''), (string) $request->query('con_codigo')];

        $query = DB::connection('dynamic')->table('MENMEN')
            ->where(function ($q) use ($me, $other) {
                $q->where(fn ($w) => $w->where('USO2DEL', $me[0])->where('USO2COD', $me[1])->where('USD2DEL', $other[0])->where('USD2COD', $other[1]))
                    ->orWhere(fn ($w) => $w->where('USO2DEL', $other[0])->where('USO2COD', $other[1])->where('USD2DEL', $me[0])->where('USD2COD', $me[1]));
            })
            ->orderBy('MENTENV')->orderBy('DEL3COD')->orderBy('MEN1COD');
        if ($request->filled('desde')) {
            $query->where('MENTENV', '>=', (new \DateTime($request->query('desde')))->format('Y-m-d H:i:s'));
        }

        $rows = $query->get()->map(fn ($r) => [
            'delegacion'         => $r->DEL3COD,
            'codigo'             => $r->MEN1COD,
            'texto'              => $r->MENCDES,
            'fecha'              => $r->MENTENV,
            'origen_delegacion'  => $r->USO2DEL,
            'origen_codigo'      => $r->USO2COD,
            'destino_delegacion' => $r->USD2DEL,
            'destino_codigo'     => $r->USD2COD,
        ])->all();

        return response()->json(['data' => $this->appendRelatedData($rows), 'meta' => ['total' => count($rows)]]);
    }

    /** Marca como leídos los mensajes del usuario (borra sus avisos de tipo M). */
    public function markRead(Request $request)
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'usuario_delegacion' => 'nullable|string|max:10',
            'usuario_codigo'     => 'required|string|max:15',
            'con_delegacion'     => 'nullable|string|max:10',
            'con_codigo'         => 'nullable|string|max:15',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        try {
            $query = DB::connection('dynamic')->table('ACCAVI')
                ->where('AVICTIP', 'M')
                ->where('USU2DEL', (string) ($body['usuario_delegacion'] ?? ''))->where('USU2COD', $body['usuario_codigo']);
            if (! empty($body['con_codigo'])) {
                $query->whereExists(function ($q) use ($body) {
                    $q->select(DB::raw(1))->from('MENMEN')
                        ->whereColumn('MENMEN.DEL3COD', 'ACCAVI.MEN2DEL')->whereColumn('MENMEN.MEN1COD', 'ACCAVI.MEN2COD')
                        ->where('MENMEN.USO2DEL', (string) ($body['con_delegacion'] ?? ''))->where('MENMEN.USO2COD', $body['con_codigo']);
                });
            }
            $count = $query->delete();

            return response()->json(['message' => 'Mensajes marcados como leídos', 'data' => ['avisos_borrados' => $count]]);
        } catch (\Throwable $e) {
            Log::error('v2 mensajes leidos: '.$e->getMessage());

            return response()->json(['message' => 'Error al marcar los mensajes'], 500);
        }
    }
}
