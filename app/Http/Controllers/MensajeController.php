<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabCodes;
use App\Support\VeolabLicense;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Mensajes (MENMEN), la Mensajería de Veolab.
 *
 * Desde Veolab 3 es el módulo MEN, con tipo de mensaje (MENCTIP: U entre
 * empleados, C conversación de un usuario cliente con el laboratorio) y
 * lectura en MENTLEI / USL2DEL / USL2COD. El cliente escribe al buzón del
 * laboratorio (destino vacío) y le contesta cualquier empleado con el
 * privilegio de mensajes de clientes; el cliente ve el nombre de quien
 * responde. En bases anteriores a 3.0 es el módulo COM, sin tipo ni fecha
 * de lectura: 'leido' = F mientras el destinatario tenga el aviso pendiente
 * y los clientes no escriben sin destinatario (ver VeolabVersion).
 *
 *  - GET /mensajes: listado (filtros origen_*, destino_*, tipo, fecha...);
 *    'leido' = T cuando el mensaje tiene fecha de lectura.
 *  - POST /mensajes {origen_*, destino_*, texto}: como Mensajeria.Enviar,
 *    código del contador de la delegación del remitente, fecha del servidor y
 *    aviso M al destinatario; sin destino (remitente cliente), aviso a los
 *    empleados que atienden a clientes. Veolab no los audita ni los borra.
 *  - GET /mensajes/conversacion?usuario_*[&con_*][&desde=fecha]: los mensajes
 *    entre los dos usuarios en orden de envío; si 'usuario' es un cliente, toda
 *    su conversación con el laboratorio (sin 'con').
 *  - POST /mensajes/leidos {usuario_*[, con_*]}: marca como leídos los mensajes
 *    recibidos por el usuario (de todos o solo los de 'con'; si 'con' es un
 *    cliente, también los que envió al laboratorio) y borra sus avisos M.
 *
 * Pendiente con el acceso de usuarios de Veolab Web: tomar el remitente y el
 * usuario de la sesión en lugar de la petición (hoy el token es del laboratorio).
 */
class MensajeController extends BaseController
{
    /** Bits de PYFNACC de MEN_MEN: privilegio especial 1 (todos) y 2 (propios y de clientes). */
    private const ACCESO_CLIENTES = 4 | 8;

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
        'lector'  => 'string',
    ];

    protected array $mapping = [
        'delegacion'         => 'DEL3COD',
        'codigo'             => 'MEN1COD',
        'texto'              => 'MENCDES',
        'fecha'              => 'MENTENV',
        'tipo'               => 'MENCTIP',
        'origen_delegacion'  => 'USO2DEL',
        'origen_codigo'      => 'USO2COD',
        'destino_delegacion' => 'USD2DEL',
        'destino_codigo'     => 'USD2COD',
        'fecha_lectura'      => 'MENTLEI',
        'lector_delegacion'  => 'USL2DEL',
        'lector_codigo'      => 'USL2COD',
    ];

    protected array $since = [
        'tipo'              => VeolabVersion::V3,
        'fecha_lectura'     => VeolabVersion::V3,
        'lector_delegacion' => VeolabVersion::V3,
        'lector_codigo'     => VeolabVersion::V3,
    ];

    /** Módulo de la mensajería: MEN desde Veolab 3, COM (Comunicaciones) antes. */
    private function module(): string
    {
        return VeolabVersion::isV3() ? 'MEN' : 'COM';
    }

    protected function rules(): array
    {
        return [
            'texto'              => 'required|string',
            'origen_delegacion'  => 'nullable|string|max:10',
            'origen_codigo'      => 'required|string|max:15',
            'destino_delegacion' => 'nullable|string|max:10',
            // Antes de Veolab 3 no existe el buzón de clientes: siempre hay destinatario
            'destino_codigo'     => (VeolabVersion::isV3() ? 'nullable' : 'required').'|string|max:15',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), $this->module())) {
            throw new BusinessRuleException($this->module() === 'MEN'
                ? 'El módulo de mensajería no está activo'
                : 'El módulo de comunicaciones no está activo');
        }
        if (trim($data['texto']) === '') {
            throw new BusinessRuleException('El mensaje está vacío');
        }

        $origin = $this->user($data['origen_delegacion'] ?? '', $data['origen_codigo']);
        if (! $origin || $origin->USUBBAJ === 'T') {
            throw new BusinessRuleException('El usuario remitente no existe o está de baja');
        }

        if (VeolabVersion::isV3() && $this->isCustomer($origin)) {
            // Un cliente sólo escribe al laboratorio: no elige destinatario
            if (! empty($data['destino_codigo'])) {
                throw new BusinessRuleException('Los clientes escriben al laboratorio, sin indicar destinatario');
            }

            return;
        }

        if (empty($data['destino_codigo'])) {
            throw new BusinessRuleException('Falta el destinatario');
        }
        // La mensajería sólo ofrece destinatarios que no están de baja y de la misma delegación (o comunes)
        $target = $this->user($data['destino_delegacion'] ?? '', $data['destino_codigo']);
        if (! $target || $target->USUBBAJ === 'T') {
            throw new BusinessRuleException('El usuario destinatario no existe o está de baja');
        }
        $originDel = (string) ($data['origen_delegacion'] ?? '');
        if ($originDel !== '' && (string) $target->DEL3COD !== '' && (string) $target->DEL3COD !== $originDel) {
            throw new BusinessRuleException('No se pueden enviar mensajes a usuarios de otra delegación');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        // El mensaje se guarda en la delegación del remitente, con la hora del servidor.
        $data['delegacion'] = (string) ($data['origen_delegacion'] ?? '');
        $data['origen_delegacion'] = $data['delegacion'];
        $data['destino_delegacion'] = (string) ($data['destino_delegacion'] ?? '');
        $data['destino_codigo'] = (string) ($data['destino_codigo'] ?? '');
        $data['fecha'] = (string) DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;

        if ($this->supports('tipo')) {
            $origin = $this->user($data['origen_delegacion'], $data['origen_codigo']);
            $target = $data['destino_codigo'] !== '' ? $this->user($data['destino_delegacion'], $data['destino_codigo']) : null;
            $data['tipo'] = ($this->isCustomer($origin) || ($target && $this->isCustomer($target))) ? 'C' : 'U';
        }
        unset($data['codigo']);

        return $data;
    }

    /** Aviso de mensaje (ACCAVI tipo M) al destinatario o, sin destinatario, a quienes atienden a clientes. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if ($data['destino_codigo'] !== '') {
            $this->notice($data['destino_delegacion'], $data['destino_codigo'], $data['fecha'], $keys);

            return $data;
        }

        $origin = $this->user($data['origen_delegacion'], $data['origen_codigo']);
        $query = DB::connection('dynamic')->table('ACCUSU')
            ->join('ACCPYF', function ($j) {
                $j->on('ACCPYF.PER3COD', '=', 'ACCUSU.PER2COD')->on('ACCPYF.DEL3COD', '=', 'ACCUSU.PER2DEL');
            })
            ->where('ACCPYF.FUN3COD', 'MEN_MEN')
            ->whereRaw('(ACCPYF.PYFNACC & ?) <> 0', [self::ACCESO_CLIENTES])
            ->where(fn ($q) => $q->where('ACCUSU.USUBBAJ', '<>', 'T')->orWhereNull('ACCUSU.USUBBAJ'))
            ->where(fn ($q) => $q->where('ACCUSU.USUNTIP', '<>', 1)->orWhereNull('ACCUSU.USUNTIP'));
        // Empleados de la delegación del cliente y los comunes; un cliente común lo ven todos
        if ((string) $origin->DEL3COD !== '') {
            $query->where(fn ($q) => $q->where('ACCUSU.DEL3COD', $origin->DEL3COD)->orWhere('ACCUSU.DEL3COD', ''));
        }
        foreach ($query->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD']) as $employee) {
            $this->notice((string) $employee->DEL3COD, (string) $employee->USU1COD, $data['fecha'], $keys);
        }

        return $data;
    }

    /** Mensajeria.Enviar no audita. */
    protected function auditCreated(array $data, array $keyParams): void {}

    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }

        if ($this->supports('fecha_lectura')) {
            foreach ($rows as &$row) {
                $row['leido'] = empty($row['fecha_lectura']) ? 'F' : 'T';
            }

            return $rows;
        }

        // Antes de Veolab 3: leído cuando el destinatario ya no tiene el aviso
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

    /** Conversación entre dos usuarios, o de un cliente con el laboratorio, en orden de envío. */
    public function conversation(Request $request)
    {
        $this->prepareForDatabase();

        $validator = Validator::make($request->query(), [
            'usuario_codigo' => 'required|string',
            'con_codigo'     => 'nullable|string',
            'desde'          => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $me = [(string) ($request->query('usuario_delegacion') ?? ''), (string) $request->query('usuario_codigo')];
        $other = [(string) ($request->query('con_delegacion') ?? ''), (string) ($request->query('con_codigo') ?? '')];

        $db = DB::connection('dynamic');
        $meUser = $this->user($me[0], $me[1]);
        if (! $meUser) {
            return response()->json(['message' => 'El usuario no existe'], 422);
        }
        $otherUser = $other[1] !== '' ? $this->user($other[0], $other[1]) : null;

        $query = $db->table('MENMEN')
            ->leftJoin('ACCUSU AS ORI', fn ($j) => $j->on('MENMEN.USO2DEL', '=', 'ORI.DEL3COD')->on('MENMEN.USO2COD', '=', 'ORI.USU1COD'))
            ->orderBy('MENMEN.MENTENV')->orderBy('MENMEN.DEL3COD')->orderBy('MENMEN.MEN1COD');

        // Conversación de un cliente con el laboratorio (desde Veolab 3): todo lo que envió o recibió
        $customer = null;
        if (VeolabVersion::isV3()) {
            $customer = $this->isCustomer($meUser) ? $me : ($otherUser && $this->isCustomer($otherUser) ? $other : null);
        }
        if ($customer) {
            $query->where(function ($q) use ($customer) {
                $q->where(fn ($w) => $w->where('MENMEN.USO2DEL', $customer[0])->where('MENMEN.USO2COD', $customer[1]))
                    ->orWhere(fn ($w) => $w->where('MENMEN.USD2DEL', $customer[0])->where('MENMEN.USD2COD', $customer[1]));
            });
        } else {
            if ($other[1] === '') {
                return response()->json(['message' => 'Datos no válidos', 'errors' => ['con_codigo' => ['Falta el otro usuario de la conversación']]], 422);
            }
            $query->where(function ($q) use ($me, $other) {
                $q->where(fn ($w) => $w->where('MENMEN.USO2DEL', $me[0])->where('MENMEN.USO2COD', $me[1])->where('MENMEN.USD2DEL', $other[0])->where('MENMEN.USD2COD', $other[1]))
                    ->orWhere(fn ($w) => $w->where('MENMEN.USO2DEL', $other[0])->where('MENMEN.USO2COD', $other[1])->where('MENMEN.USD2DEL', $me[0])->where('MENMEN.USD2COD', $me[1]));
            });
        }
        if ($request->filled('desde')) {
            $query->where('MENMEN.MENTENV', '>=', (new \DateTime($request->query('desde')))->format('Y-m-d H:i:s'));
        }

        $columns = ['MENMEN.DEL3COD', 'MENMEN.MEN1COD', 'MENMEN.MENCDES', 'MENMEN.MENTENV',
            'MENMEN.USO2DEL', 'MENMEN.USO2COD', 'MENMEN.USD2DEL', 'MENMEN.USD2COD', 'ORI.USUCNOM AS NOMORI'];
        $v3 = $this->supports('fecha_lectura');
        if ($v3) {
            array_push($columns, 'MENMEN.MENCTIP', 'MENMEN.MENTLEI', 'MENMEN.USL2DEL', 'MENMEN.USL2COD');
        }

        $rows = $query->get($columns)->map(function ($r) use ($v3) {
            $row = [
                'delegacion'         => $r->DEL3COD,
                'codigo'             => $r->MEN1COD,
                'texto'              => $r->MENCDES,
                'fecha'              => $r->MENTENV,
                'origen_delegacion'  => $r->USO2DEL,
                'origen_codigo'      => $r->USO2COD,
                'origen_nombre'      => $r->NOMORI,
                'destino_delegacion' => $r->USD2DEL,
                'destino_codigo'     => $r->USD2COD,
            ];
            if ($v3) {
                $row += [
                    'tipo'               => $r->MENCTIP,
                    'fecha_lectura'      => $r->MENTLEI,
                    'lector_delegacion'  => $r->USL2DEL,
                    'lector_codigo'      => $r->USL2COD,
                ];
            }

            return $row;
        })->all();

        return response()->json(['data' => $this->appendRelatedData($rows), 'meta' => ['total' => count($rows), 'version_bd' => VeolabVersion::current()]]);
    }

    /** Marca como leídos los mensajes recibidos por el usuario y borra sus avisos de tipo M. */
    public function markRead(Request $request)
    {
        $this->prepareForDatabase();

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

        $me = [(string) ($body['usuario_delegacion'] ?? ''), (string) $body['usuario_codigo']];
        $other = ! empty($body['con_codigo']) ? [(string) ($body['con_delegacion'] ?? ''), (string) $body['con_codigo']] : null;

        try {
            $db = DB::connection('dynamic');
            $db->beginTransaction();
            $now = (string) $db->selectOne('SELECT NOW() AS n')->n;

            // Fecha de lectura (desde Veolab 3): mensajes dirigidos al usuario y, si 'con' es un cliente, los que envió al laboratorio
            $read = 0;
            if ($this->supports('fecha_lectura')) {
                $otherUser = $other ? $this->user($other[0], $other[1]) : null;
                $read = $db->table('MENMEN')->whereNull('MENTLEI')
                    ->where(function ($q) use ($me, $other, $otherUser) {
                        $q->where(function ($w) use ($me, $other) {
                            $w->where('USD2DEL', $me[0])->where('USD2COD', $me[1]);
                            if ($other) {
                                $w->where('USO2DEL', $other[0])->where('USO2COD', $other[1]);
                            }
                        });
                        if ($other && $otherUser && $this->isCustomer($otherUser)) {
                            $q->orWhere(fn ($w) => $w->where('USO2DEL', $other[0])->where('USO2COD', $other[1])->where('USD2DEL', '')->where('USD2COD', ''));
                        }
                    })
                    ->update(['MENTLEI' => $now, 'USL2DEL' => $me[0], 'USL2COD' => $me[1]]);
            }

            $query = $db->table('ACCAVI')
                ->where('AVICTIP', 'M')
                ->where('USU2DEL', $me[0])->where('USU2COD', $me[1]);
            if ($other) {
                $query->whereExists(function ($q) use ($other) {
                    $q->select(DB::raw(1))->from('MENMEN')
                        ->whereColumn('MENMEN.DEL3COD', 'ACCAVI.MEN2DEL')->whereColumn('MENMEN.MEN1COD', 'ACCAVI.MEN2COD')
                        ->where('MENMEN.USO2DEL', $other[0])->where('MENMEN.USO2COD', $other[1]);
                });
            }
            $count = $query->delete();
            $db->commit();

            return response()->json(['message' => 'Mensajes marcados como leídos', 'data' => ['leidos' => $read, 'avisos_borrados' => $count]]);
        } catch (\Throwable $e) {
            DB::connection('dynamic')->rollBack();

            return ServerError::response('v2 mensajes leidos', $e, 'Error al marcar los mensajes');
        }
    }

    private function user(string $delegation, string $code): ?object
    {
        return DB::connection('dynamic')->table('ACCUSU')
            ->where('DEL3COD', $delegation)->where('USU1COD', $code)
            ->first(['DEL3COD', 'USU1COD', 'USUCNOM', 'USUBBAJ', 'USUNTIP']);
    }

    private function isCustomer(?object $user): bool
    {
        return $user && (int) $user->USUNTIP === 1;
    }

    private function notice(string $delegation, string $code, string $date, array $keys): void
    {
        DB::connection('dynamic')->table('ACCAVI')->insert([
            'DEL3COD' => $delegation,
            'AVI1COD' => VeolabCodes::next('ACCAVI', '', $delegation),
            'AVITFEC' => $date,
            'AVICTIP' => 'M',
            'USU2DEL' => $delegation,
            'USU2COD' => $code,
            'MEN2DEL' => (string) $keys['delegacion'],
            'MEN2COD' => (int) $keys['codigo'],
        ]);
    }
}
