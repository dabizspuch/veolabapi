<?php

namespace App\Http\Controllers;

use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones de los usuarios (ACCNOT), la lista de Notificaciones de
 * Veolab. Las crean los procesos (recepción, fecha de compromiso, firmas,
 * marcas, stock, cartas de control...): la API las lee y las borra. Para las
 * de un usuario: ?usuario_delegacion=&usuario_codigo=.
 *
 * 'pendiente' = T si aún tiene el aviso emergente (ACCAVI) sin mostrar.
 * Al borrarla se borran también sus avisos (Veolab los deja huérfanos).
 */
class NotificacionController extends BaseController
{
    protected string $table = 'ACCNOT';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'NOT1COD',
    ];
    protected array $searchFields = ['NOTCAVI'];

    /** Tipos (Comunicaciones.bas, COM_NOTIF_*). */
    public const TYPES = [
        'B' => 'Muestra recibida',
        'C' => 'Fecha de compromiso',
        'A' => 'Analista asignado',
        'F' => 'Firma pendiente',
        'R' => 'Resultado rechazado',
        'I' => 'Nuevo informe',
        'M' => 'Marca en resultados',
        'S' => 'Stock mínimo',
        'O' => 'Error en gráfico de control',
        'V' => 'Aviso en gráfico de control',
        'N' => 'Nuevo gráfico de control',
        'P' => 'Fecha de seguimiento de presupuesto',   // Veolab 3
        'G' => 'Error al recibir una muestra de iGEO',   // Veolab 3 (las crea el servicio de iGEO)
        'Q' => 'Paso de una incidencia de calidad',      // Veolab 3
        'K' => 'Fecha prevista de una acción de calidad', // Veolab 3
        'L' => 'Nueva solicitud de cliente',             // Veolab 3
        'T' => 'Solicitud de cliente sin decidir',       // Veolab 3
        'D' => 'Solicitud de cliente decidida',          // Veolab 3
        'H' => 'Próxima evaluación de un proveedor',     // Veolab 3
        'J' => 'Fecha límite de envío de un ensayo de aptitud', // Veolab 3
        'W' => 'Próxima revisión de un documento controlado',    // Veolab 3
    ];

    /**
     * Desde Veolab 3: presupuesto (P), incidencia de calidad (Q y K),
     * solicitud de cliente (L, T y D), proveedor (H), ensayo de aptitud (J) y
     * documento controlado (W).
     */
    protected array $since = [
        'presupuesto_delegacion' => VeolabVersion::V3,
        'presupuesto_serie'      => VeolabVersion::V3,
        'presupuesto_codigo'     => VeolabVersion::V3,
        'incidencia_delegacion'  => VeolabVersion::V3,
        'incidencia_serie'       => VeolabVersion::V3,
        'incidencia_codigo'      => VeolabVersion::V3,
        'solicitud_delegacion'   => VeolabVersion::V3,
        'solicitud_serie'        => VeolabVersion::V3,
        'solicitud_codigo'       => VeolabVersion::V3,
        'proveedor_delegacion'   => VeolabVersion::V3,
        'proveedor_codigo'       => VeolabVersion::V3,
        'ensayo_delegacion'      => VeolabVersion::V3,
        'ensayo_serie'           => VeolabVersion::V3,
        'ensayo_codigo'          => VeolabVersion::V3,
        'documento_delegacion'   => VeolabVersion::V3,
        'documento_codigo'       => VeolabVersion::V3,
    ];

    protected array $foreignKeys = [
        'usuario'       => 'string',
        'operacion'     => 'int',
        'informe'       => 'int',
        'producto'      => 'string',
        'carta_control' => 'int',
        'presupuesto'   => 'int',
        'incidencia'    => 'int',
        'solicitud'     => 'int',
        'proveedor'     => 'string',
        'ensayo'        => 'int',
        'documento'     => 'int',
    ];

    protected array $mapping = [
        'delegacion'               => 'DEL3COD',
        'codigo'                   => 'NOT1COD',
        'fecha'                    => 'NOTTFEC',
        'tipo'                     => 'NOTCTIP',
        'texto'                    => 'NOTCAVI',
        'usuario_delegacion'       => 'USU2DEL',
        'usuario_codigo'           => 'USU2COD',
        'operacion_delegacion'     => 'OPE2DEL',
        'operacion_serie'          => 'OPE2SER',
        'operacion_codigo'         => 'OPE2COD',
        'informe_delegacion'       => 'INF2DEL',
        'informe_serie'            => 'INF2SER',
        'informe_codigo'           => 'INF2COD',
        'producto_delegacion'      => 'PRD2DEL',
        'producto_codigo'          => 'PRD2COD',
        'carta_control_delegacion' => 'CDC2DEL',
        'carta_control_codigo'     => 'CDC2COD',
        'presupuesto_delegacion'   => 'PRE2DEL',
        'presupuesto_serie'        => 'PRE2SER',
        'presupuesto_codigo'       => 'PRE2COD',
        'incidencia_delegacion'    => 'INC2DEL',
        'incidencia_serie'         => 'INC2SER',
        'incidencia_codigo'        => 'INC2COD',
        'solicitud_delegacion'     => 'SOL2DEL',
        'solicitud_serie'          => 'SOL2SER',
        'solicitud_codigo'         => 'SOL2COD',
        'proveedor_delegacion'     => 'PRO2DEL',
        'proveedor_codigo'         => 'PRO2COD',
        'ensayo_delegacion'        => 'EAP2DEL',
        'ensayo_serie'             => 'EAP2SER',
        'ensayo_codigo'            => 'EAP2COD',
        'documento_delegacion'     => 'FAT2DEL',
        'documento_codigo'         => 'FAT2COD',
    ];

    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }

        $pending = DB::connection('dynamic')->table('ACCAVI')
            ->where('AVICTIP', 'N')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('NOT2DEL', $row['delegacion'] ?? '')->where('NOT2COD', $row['codigo']));
                }
            })
            ->get(['NOT2DEL', 'NOT2COD'])
            ->map(fn ($a) => $a->NOT2DEL.'|'.$a->NOT2COD)->flip()->all();

        foreach ($rows as &$row) {
            $row['tipo_descripcion'] = self::TYPES[$row['tipo']] ?? null;
            $row['pendiente'] = isset($pending[($row['delegacion'] ?? '').'|'.$row['codigo']]) ? 'T' : 'F';
        }

        return $rows;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('ACCAVI')
            ->where('NOT2DEL', $keys['delegacion'] ?? '')->where('NOT2COD', $keys['codigo'])->delete();
    }
}
