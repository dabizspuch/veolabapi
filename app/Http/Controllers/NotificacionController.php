<?php

namespace App\Http\Controllers;

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
        'O' => 'Error en carta de control',
        'V' => 'Aviso en carta de control',
        'N' => 'Nueva carta de control',
    ];

    protected array $foreignKeys = [
        'usuario'       => 'string',
        'operacion'     => 'int',
        'informe'       => 'int',
        'producto'      => 'string',
        'carta_control' => 'int',
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
