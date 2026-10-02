<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Avisos emergentes pendientes de los usuarios (ACCAVI): lo que Veolab
 * muestra en la ventana de avisos y borra al mostrarlo. Tipo N notificación
 * (ACCNOT), M mensaje (MENMEN), A agenda (cita; su fecha puede ser futura).
 *
 *  - GET /avisos?usuario_delegacion=&usuario_codigo=[&fecha[lte]=...]: cada
 *    aviso lleva el resumen de lo avisado (tipo y texto de la notificación,
 *    texto y remitente del mensaje, asunto de la cita).
 *  - DELETE /avisos?delegacion=&codigo=: aviso visto.
 *  - POST /avisos/vistos {usuario_*[, tipo]}: todos los avisos ya vencidos
 *    (fecha hasta ahora) del usuario, como el temporizador de Veolab.
 */
class AvisoController extends BaseController
{
    protected string $table = 'ACCAVI';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'AVI1COD',
    ];

    protected array $foreignKeys = [
        'usuario'      => 'string',
        'notificacion' => 'int',
        'mensaje'      => 'int',
    ];

    protected array $mapping = [
        'delegacion'               => 'DEL3COD',
        'codigo'                   => 'AVI1COD',
        'fecha'                    => 'AVITFEC',
        'tipo'                     => 'AVICTIP',
        'usuario_delegacion'       => 'USU2DEL',
        'usuario_codigo'           => 'USU2COD',
        'notificacion_delegacion'  => 'NOT2DEL',
        'notificacion_codigo'      => 'NOT2COD',
        'mensaje_delegacion'       => 'MEN2DEL',
        'mensaje_codigo'           => 'MEN2COD',
        'agenda_delegacion'        => 'AGE2DEL',
        'agenda_usuario'           => 'AGE2USU',
        'agenda_codigo'            => 'AGE2COD',
        'agenda_fecha'             => 'AGE2FEC',
    ];

    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        foreach ($rows as &$row) {
            $row['resumen'] = null;
            if ($row['tipo'] === 'N' && $row['notificacion_codigo'] !== null) {
                $n = $db->table('ACCNOT')->where('DEL3COD', $row['notificacion_delegacion'] ?? '')
                    ->where('NOT1COD', $row['notificacion_codigo'])->first(['NOTCTIP', 'NOTCAVI']);
                $row['resumen'] = $n ? [
                    'tipo'             => $n->NOTCTIP,
                    'tipo_descripcion' => NotificacionController::TYPES[$n->NOTCTIP] ?? null,
                    'texto'            => $n->NOTCAVI,
                ] : null;
            } elseif ($row['tipo'] === 'M' && $row['mensaje_codigo'] !== null) {
                $m = $db->table('MENMEN')->where('DEL3COD', $row['mensaje_delegacion'] ?? '')
                    ->where('MEN1COD', $row['mensaje_codigo'])->first(['MENCDES', 'USO2DEL', 'USO2COD']);
                $row['resumen'] = $m ? [
                    'texto'             => $m->MENCDES,
                    'origen_delegacion' => $m->USO2DEL,
                    'origen_codigo'     => $m->USO2COD,
                ] : null;
            } elseif ($row['tipo'] === 'A' && (int) $row['agenda_codigo'] !== 0) {
                $a = $db->table('AGEAGE')->where('USU3DEL', $row['agenda_delegacion'] ?? '')
                    ->where('USU3COD', $row['agenda_usuario'] ?? '')->where('AGE1COD', $row['agenda_codigo'])
                    ->first(['AGECASU', 'AGECUBI']);
                $row['resumen'] = $a ? ['asunto' => $a->AGECASU, 'ubicacion' => $a->AGECUBI] : null;
            }
        }

        return $rows;
    }

    /** Ver un aviso no se audita en Veolab. */
    protected function auditDeleted(array $before, array $keyParams): void {}

    /** Marca como vistos los avisos vencidos del usuario (opcionalmente de un tipo). */
    public function markSeen(Request $request)
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'usuario_delegacion' => 'nullable|string|max:10',
            'usuario_codigo'     => 'required|string|max:15',
            'tipo'               => 'nullable|string|in:N,M,A',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        try {
            $db = DB::connection('dynamic');
            $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
            $query = $db->table('ACCAVI')
                ->where('USU2DEL', (string) ($body['usuario_delegacion'] ?? ''))->where('USU2COD', $body['usuario_codigo'])
                ->where(fn ($q) => $q->whereNull('AVITFEC')->orWhere('AVITFEC', '<=', $now));
            if (! empty($body['tipo'])) {
                $query->where('AVICTIP', $body['tipo']);
            }
            $count = $query->delete();

            return response()->json(['message' => 'Avisos marcados como vistos', 'data' => ['avisos_borrados' => $count]]);
        } catch (\Throwable $e) {
            Log::error('v2 avisos vistos: '.$e->getMessage());

            return response()->json(['message' => 'Error al marcar los avisos'], 500);
        }
    }
}
