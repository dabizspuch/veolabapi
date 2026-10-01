<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\AuditsOwnerRecord;
use App\Support\VeolabResults;
use Illuminate\Support\Facades\DB;

/**
 * Estructura de resultados de una técnica: columnas (LABCOT) con sus
 * intervalos por rango (LABCYR), la plantilla con la que se crean las
 * columnas de resultado (LABCOR) de cada operación. Como la rejilla de
 * formato de FichaTecnica:
 *  - La columna es su posición (A = 1). Se añaden al final y solo se borra la
 *    última; las demás se desactivan (es_activa = F). La API no inserta ni
 *    mueve columnas, así que no reescribe las letras de las fórmulas.
 *  - "rangos" sustituye los intervalos de la columna. Veolab carga los
 *    intervalos por posición (uno por cada rango de la delegación de la
 *    técnica y generales, en orden), así que se mantiene una fila de LABCYR
 *    por rango y columna, vacía si no tiene intervalo.
 *  - Los cambios no afectan a las operaciones ya creadas (salvo fórmula,
 *    formato, tipo, seleccionables y predeterminado, que los resultados leen
 *    de aquí).
 *  - Auditoría como la ficha: suceso de fila de la técnica (nivel 2) o de
 *    campo "LABCOT" (nivel 3).
 */
class ParametroColumnaController extends BaseController
{
    use AuditsOwnerRecord;

    protected string $table = 'LABCOT';
    protected array $keys = [
        'tecnica_delegacion' => 'TEC3DEL',
        'tecnica_codigo'     => 'TEC3COD',
        'columna'            => 'COT1COD',
    ];
    protected string $codeKey = 'columna';
    protected ?string $delegationKey = null;
    protected array $searchFields = ['COTCTIT'];

    protected array $mapping = [
        'tecnica_delegacion'    => 'TEC3DEL',
        'tecnica_codigo'        => 'TEC3COD',
        'columna'               => 'COT1COD',
        'titulo'                => 'COTCTIT',
        'titulo2'               => 'COTCTI2',
        'titulo3'               => 'COTCTI3',
        'tipo'                  => 'COTCTIP',
        'formato'               => 'COTCFOR',
        'seleccionables'        => 'COTCSEL',
        'predeterminado'        => 'COTCPRE',
        'formula'               => 'COTCFOM',
        'es_activa'             => 'COTBACT',
        'es_editable'           => 'COTBEDI',
        'es_visible_informe'    => 'COTBINF',
        'es_visible_resultados' => 'COTBRES',
        'es_control_exactitud'  => 'COTBCON',
        'es_control_precision'  => 'COTBCOP',
    ];

    /** Valores de una columna nueva (NuevaColumna de FichaTecnica). */
    private const DEFAULTS = [
        'titulo'                => '',
        'titulo2'               => '',
        'titulo3'               => '',
        'tipo'                  => 'T',
        'formato'               => '',
        'seleccionables'        => '',
        'predeterminado'        => '',
        'formula'               => '',
        'es_activa'             => 'T',
        'es_editable'           => 'T',
        'es_visible_informe'    => 'T',
        'es_visible_resultados' => 'T',
        'es_control_exactitud'  => 'F',
        'es_control_precision'  => 'F',
    ];

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'tecnica_delegacion'          => 'nullable|string|max:10',
            'tecnica_codigo'              => ($isCreating ? 'required' : 'sometimes').'|string|max:30',
            'columna'                     => 'nullable|integer|min:1',
            'titulo'                      => 'nullable|string|max:100',
            'titulo2'                     => 'nullable|string|max:100',
            'titulo3'                     => 'nullable|string|max:100',
            'tipo'                        => 'nullable|string|in:N,T,F,H,C',
            'formato'                     => 'nullable|string|max:30',
            'seleccionables'              => 'nullable|string',
            'predeterminado'              => 'nullable|string|max:100',
            'formula'                     => 'nullable|string',
            'es_activa'                   => 'nullable|string|in:T,F',
            'es_editable'                 => 'nullable|string|in:T,F',
            'es_visible_informe'          => 'nullable|string|in:T,F',
            'es_visible_resultados'       => 'nullable|string|in:T,F',
            'es_control_exactitud'        => 'nullable|string|in:T,F',
            'es_control_precision'        => 'nullable|string|in:T,F',
            'rangos'                      => 'nullable|array',
            'rangos.*'                    => 'array',
            'rangos.*.rango_delegacion'   => 'nullable|string|max:10',
            'rangos.*.rango_codigo'       => 'required|integer|min:1',
            'rangos.*.intervalo'          => 'nullable|string|max:100',
            'rangos.*.marca_delegacion'   => 'nullable|string|max:10',
            'rangos.*.marca_codigo'       => 'nullable|integer',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        if (! isset($data['tecnica_codigo'])) {
            return;
        }
        $exists = DB::connection('dynamic')->table('LABTEC')
            ->where('DEL3COD', (string) ($data['tecnica_delegacion'] ?? ''))
            ->where('TEC1COD', $data['tecnica_codigo'])
            ->exists();
        if (! $exists) {
            throw new BusinessRuleException('La técnica no existe');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        // Veolab graba los textos y marcas vacíos como '' y 'F', no como NULL.
        foreach (self::DEFAULTS as $field => $default) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = in_array($default, ['T', 'F'], true) ? 'F' : ($field === 'tipo' ? 'T' : '');
            }
        }

        if ($keys) {
            return $data;
        }

        $data['tecnica_delegacion'] = (string) ($data['tecnica_delegacion'] ?? '');
        $data += self::DEFAULTS;

        // Siguiente columna, con la técnica bloqueada hasta el commit.
        $db = DB::connection('dynamic');
        $db->table('LABTEC')->where('DEL3COD', $data['tecnica_delegacion'])->where('TEC1COD', $data['tecnica_codigo'])
            ->lockForUpdate()->first();
        $next = (int) $db->table('LABCOT')->where('TEC3DEL', $data['tecnica_delegacion'])
            ->where('TEC3COD', $data['tecnica_codigo'])->max('COT1COD') + 1;
        if (! empty($data['columna']) && (int) $data['columna'] !== $next) {
            throw new BusinessRuleException('Las columnas se añaden al final: la siguiente es la '
                .VeolabResults::letter($next).' ('.$next.')');
        }
        $data['columna'] = $next;

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $last = (int) DB::connection('dynamic')->table('LABCOT')
            ->where('TEC3DEL', (string) $keys['tecnica_delegacion'])->where('TEC3COD', $keys['tecnica_codigo'])
            ->max('COT1COD');
        if ((int) $keys['columna'] !== $last) {
            throw new BusinessRuleException('Solo se puede borrar la última columna (las fórmulas usan las letras); '
                .'para dejar de usar esta, desactívala (es_activa = F)');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('LABCYR')
            ->where('TEC3DEL', (string) $keys['tecnica_delegacion'])->where('TEC3COD', $keys['tecnica_codigo'])
            ->where('COT3COD', $keys['columna'])->delete();
    }

    /** Intervalos de la columna y una fila de LABCYR por rango y columna de la técnica. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $tecDel = (string) $keys['tecnica_delegacion'];
        $tecCod = (string) $keys['tecnica_codigo'];

        if (array_key_exists('rangos', $data)) {
            $this->saveRanges($tecDel, $tecCod, (int) $keys['columna'], $data['rangos'] ?? []);
        }
        $this->fillRanges($tecDel, $tecCod);
        $this->auditTechnique($keys);

        return $data;
    }

    /** Sustituye los intervalos de la columna (los rangos no indicados quedan vacíos). */
    private function saveRanges(string $tecDel, string $tecCod, int $column, array $ranges): void
    {
        $db = DB::connection('dynamic');
        $available = $this->availableRanges($tecDel);

        $values = [];
        foreach ($ranges as $range) {
            $key = VeolabResults::key($range['rango_delegacion'] ?? '', (int) $range['rango_codigo']);
            if (! isset($available[$key])) {
                throw new BusinessRuleException("El rango {$range['rango_codigo']} no existe o no es de la delegación de la técnica");
            }
            if (isset($values[$key])) {
                throw new BusinessRuleException("El rango {$range['rango_codigo']} está repetido");
            }

            $markCod = (int) ($range['marca_codigo'] ?? 0);
            $markDel = $tecDel;
            if ($markCod !== 0) {
                $markDel = (string) ($range['marca_delegacion'] ?? '');
                $mark = $db->table('LABMAR')->where('DEL3COD', $markDel)->where('MAR1COD', $markCod);
                if (($tecDel !== '' && $markDel !== '' && $markDel !== $tecDel) || ! $mark->exists()) {
                    throw new BusinessRuleException("La marca del rango {$range['rango_codigo']} no existe o no es de la delegación de la técnica");
                }
            }
            $values[$key] = ['CYRCVAR' => (string) ($range['intervalo'] ?? ''), 'MAR2DEL' => $markDel, 'MAR2COD' => $markCod];
        }

        $db->table('LABCYR')->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)->where('COT3COD', $column)->delete();
        foreach ($available as $key => [$ranDel, $ranCod]) {
            $db->table('LABCYR')->insert([
                'TEC3DEL' => $tecDel, 'TEC3COD' => $tecCod, 'COT3COD' => $column,
                'RAN3DEL' => $ranDel, 'RAN3COD' => $ranCod,
            ] + ($values[$key] ?? ['CYRCVAR' => '', 'MAR2DEL' => $tecDel, 'MAR2COD' => 0]));
        }
    }

    /** Filas vacías de LABCYR para los rangos y columnas que no las tengan (como al grabar la ficha). */
    private function fillRanges(string $tecDel, string $tecCod): void
    {
        $db = DB::connection('dynamic');
        $columns = $db->table('LABCOT')->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)->pluck('COT1COD');
        $existing = [];
        foreach ($db->table('LABCYR')->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)
            ->get(['COT3COD', 'RAN3DEL', 'RAN3COD']) as $row) {
            $existing[(int) $row->COT3COD."\x1B".VeolabResults::key($row->RAN3DEL, (int) $row->RAN3COD)] = true;
        }

        foreach ($columns as $column) {
            foreach ($this->availableRanges($tecDel) as $key => [$ranDel, $ranCod]) {
                if (! isset($existing[(int) $column."\x1B".$key])) {
                    $db->table('LABCYR')->insert([
                        'TEC3DEL' => $tecDel, 'TEC3COD' => $tecCod, 'COT3COD' => (int) $column,
                        'RAN3DEL' => $ranDel, 'RAN3COD' => $ranCod,
                        'CYRCVAR' => '', 'MAR2DEL' => $tecDel, 'MAR2COD' => 0,
                    ]);
                }
            }
        }
    }

    /** Rangos que muestra la ficha: los de la delegación de la técnica y los generales, en orden. */
    private function availableRanges(string $tecDel): array
    {
        $rows = DB::connection('dynamic')->table('LABRAN')
            ->whereIn('DEL3COD', array_unique([$tecDel, '']))
            ->orderBy('DEL3COD')->orderBy('RAN1COD')
            ->get(['DEL3COD', 'RAN1COD']);

        $out = [];
        foreach ($rows as $row) {
            $out[VeolabResults::key($row->DEL3COD, (int) $row->RAN1COD)] = [(string) $row->DEL3COD, (int) $row->RAN1COD];
        }

        return $out;
    }

    /** Cada columna lleva su letra y sus intervalos (los rangos con intervalo o marca). */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }

        $ranges = DB::connection('dynamic')->table('LABCYR')
            ->leftJoin('LABRAN', function ($join) {
                $join->on('LABCYR.RAN3DEL', '=', 'LABRAN.DEL3COD')->on('LABCYR.RAN3COD', '=', 'LABRAN.RAN1COD');
            })
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('LABCYR.TEC3DEL', (string) $row['tecnica_delegacion'])
                        ->where('LABCYR.TEC3COD', $row['tecnica_codigo'])
                        ->where('LABCYR.COT3COD', $row['columna']));
                }
            })
            ->orderBy('LABCYR.RAN3DEL')->orderBy('LABCYR.RAN3COD')
            ->get(['LABCYR.*', 'LABRAN.RANCNOM']);

        $grouped = [];
        foreach ($ranges as $range) {
            if ((string) $range->CYRCVAR === '' && (int) $range->MAR2COD === 0) {
                continue;
            }
            $grouped[$range->TEC3DEL."\x1B".$range->TEC3COD."\x1B".(int) $range->COT3COD][] = [
                'rango_delegacion' => (string) $range->RAN3DEL,
                'rango_codigo'     => (int) $range->RAN3COD,
                'rango_nombre'     => $range->RANCNOM,
                'intervalo'        => (string) $range->CYRCVAR,
                'marca_delegacion' => (int) $range->MAR2COD === 0 ? null : (string) $range->MAR2DEL,
                'marca_codigo'     => (int) $range->MAR2COD === 0 ? null : (int) $range->MAR2COD,
            ];
        }

        foreach ($rows as &$row) {
            $row['letra'] = VeolabResults::letter((int) $row['columna']);
            $row['rangos'] = $grouped[$row['tecnica_delegacion']."\x1B".$row['tecnica_codigo']."\x1B".(int) $row['columna']] ?? [];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Auditoría: sobre la ficha de la técnica
    // ------------------------------------------------------------------

    protected function auditCreated(array $data, array $keyParams): void
    {
        // Se audita en updateAdditionalData, también cuando solo cambian los intervalos.
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        $this->auditTechnique($keyParams);
    }

    private function auditTechnique(array $keys): void
    {
        $this->recordOwnerChange('LABTEC', 'TEC1COD', (string) $keys['tecnica_codigo'],
            (string) $keys['tecnica_delegacion'], 'LABCOT');
    }
}
