<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabCustomFields;
use Illuminate\Support\Facades\DB;

/**
 * Lotes (LABLOT): datos generales y campos autodefinibles de lote (LABAUT
 * tipo L, valores en LABLYA). Réplica de FichaLote/Lotes de Veolab.
 *
 *  - Código de texto (hasta 50) con serie; si no se indica se genera con el
 *    contador de ACCCLT como el resto de tablas (reglas de ACCCFC).
 *  - Estado: 0 activo, 6 completado, 7 archivado (activo al crear).
 *  - Las operaciones se vinculan desde la operación (lote_*, y
 *    lote_relacionado_* para las relacionadas), no desde el lote.
 *  - Borrado (Lotes.Borrar): desvincula sus operaciones, envía los
 *    documentos a la papelera y borra sus autodefinibles.
 */
class LoteController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABLOT';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'LOT1SER',
        'codigo'     => 'LOT1COD',
    ];
    protected array $searchFields = ['LOT1COD', 'LOTCREF', 'LOTCDES'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    protected array $foreignKeys = [
        'cliente' => 'string',
    ];

    protected array $mapping = [
        'delegacion'         => 'DEL3COD',
        'serie'              => 'LOT1SER',
        'codigo'             => 'LOT1COD',
        'referencia'         => 'LOTCREF',
        'descripcion'        => 'LOTCDES',
        'observaciones'      => 'LOTCOBS',
        'comentarios'        => 'LOTCCOM',
        'fecha_registro'     => 'LOTDREG',
        'fecha_recepcion'    => 'LOTTREC',
        'estado'             => 'LOTNEST',
        'cliente_delegacion' => 'CLI2DEL',
        'cliente_codigo'     => 'CLI2COD',
    ];

    /** 'autodefinibles': {"nombre": valor}. */
    protected function rules(): array
    {
        return [
            'delegacion'         => 'nullable|string|max:10',
            'serie'              => 'nullable|string|max:10',
            'codigo'             => 'nullable|string|max:50',
            'referencia'         => 'nullable|string|max:30',
            'descripcion'        => 'nullable|string|max:255',
            'observaciones'      => 'nullable|string|max:255',
            'comentarios'        => 'nullable|string|max:255',
            'fecha_registro'     => 'nullable|date',
            'fecha_recepcion'    => 'nullable|date',
            'estado'             => 'sometimes|integer|in:0,6,7',
            'cliente_delegacion' => 'nullable|string|max:10',
            'cliente_codigo'     => 'nullable|string|max:15',
            'autodefinibles'     => 'nullable|array',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isNew = empty($keys);
        $delegation = (string) ($isNew ? ($data['delegacion'] ?? '') : $keys['delegacion']);
        $customFields = VeolabCustomFields::resolve($delegation, $data['autodefinibles'] ?? null, 'LABLOT');
        unset($data['autodefinibles']);

        if ($isNew) {
            // Valores por defecto de un lote nuevo: activo y registrado ahora.
            $data['estado'] ??= 0;
            if (! array_key_exists('fecha_registro', $data)) {
                $data['fecha_registro'] = (string) DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
            }
        }

        if (! empty($data['fecha_registro'])) {
            $data['fecha_registro'] = (new \DateTime($data['fecha_registro']))->format('Y-m-d H:i:s');
        }
        // La recepción se guarda con fecha y hora:minutos, como en la ficha.
        if (! empty($data['fecha_recepcion'])) {
            $data['fecha_recepcion'] = (new \DateTime($data['fecha_recepcion']))->format('Y-m-d H:i:00');
        }

        $data['_autodefinibles'] = $customFields;
        $data['_nuevo'] = $isNew;

        return $data;
    }

    /** Tras crear/modificar: autodefinibles (LABLYA). */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        VeolabCustomFields::save('LABLOT',
            [(string) $keys['delegacion'], (string) $keys['serie'], (string) $keys['codigo']],
            $data['_autodefinibles'] ?? [], $this->auditRow($keys), $data['_nuevo']);

        return $data;
    }

    /** Cada lote del listado lleva sus autodefinibles ({nombre: valor}). */
    protected function appendRelatedData(array $rows): array
    {
        $values = VeolabCustomFields::valuesFor('LABLOT', array_map(
            fn ($row) => [(string) $row['delegacion'], (string) $row['serie'], (string) $row['codigo']], $rows
        ));

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".$row['serie']."\x1B".$row['codigo'];
            $row['autodefinibles'] = (object) ($values[$key] ?? []);
        }

        return $rows;
    }

    /**
     * Cascada de Lotes.Borrar: desvincula sus operaciones, documentos a la
     * papelera y autodefinibles. Además (Veolab no lo hace) desvincula las
     * operaciones relacionadas y las planificaciones, para no dejarlas
     * apuntando a un lote que ya no existe.
     */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (string) $keys['codigo']];
        $db = DB::connection('dynamic');

        foreach ([['LABOPE', 'LOT2'], ['LABOPE', 'LOT4'], ['LABPLO', 'LOT2']] as [$table, $prefix]) {
            $db->table($table)
                ->where("{$prefix}DEL", $del)->where("{$prefix}SER", $ser)->where("{$prefix}COD", $cod)
                ->update(["{$prefix}DEL" => '', "{$prefix}SER" => '', "{$prefix}COD" => '']);
        }

        $db->table('DOCFAT')->where('DEL3COD', $del)->where('LOT2SER', $ser)->where('LOT2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);

        $db->table('LABLYA')->where('LOT3DEL', $del)->where('LOT3SER', $ser)->where('LOT3COD', $cod)->delete();
    }
}
