<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class ParametroController extends BaseController
{
    protected string $table = 'LABTEC';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TEC1COD',
    ];
    protected ?string $inactiveField = 'TECBBAJ';
    protected array $searchFields = ['TECCNOM', 'TECCNOI'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'                  => 'DEL3COD',
        'codigo'                      => 'TEC1COD',
        'nombre'                      => 'TECCNOM',
        'nombre_informes'             => 'TECCNOI',
        'id_igeo'                     => 'TECCIGC',
        'es_cursiva'                  => 'TECBCUR',
        'fecha_acreditacion'          => 'TECDACR',
        'parametro'                   => 'TECCPAR',
        'abreviatura'                 => 'TECCABR',
        'numero_cas'                  => 'TECCCAS',
        'precio'                      => 'TECNPRE',
        'descuento'                   => 'TECCDTO',
        'unidades'                    => 'TECCUNI',
        'leyenda'                     => 'TECCLEY',
        'metodologia'                 => 'TECCMET',
        'metodologia_abreviada'       => 'TECCMEA',
        'normativa'                   => 'TECCNOR',
        'tiempo_prueba'               => 'TECNTIE',
        'tiempo_descarte'             => 'TECNTID',
        'limite_cuantificacion'       => 'TECCLIM',
        'valor_minimo_detectable'     => 'TECCMIN',
        'incertidumbre'               => 'TECCINC',
        'instruccion'                 => 'TECCINS',
        'es_exportable'               => 'TECBEXP',
        'codigo_metodo_sinac'         => 'TECNMET',
        'tipo_metodo_sinac'           => 'TECNTME',
        'numero_norma_sinac'          => 'TECCNUN',
        'es_acreditado_sinac'         => 'TECBACR',
        'es_validado_sinac'           => 'TECBVAL',
        'es_equivalente_sinac'        => 'TECBEQU',
        'es_sin_cualificacion_sinac'  => 'TECBSIC',
        'es_uso_rutina_sinac'         => 'TECBMER',
        'codigo_parametro_sinac'      => 'TECCCOP',
        'exactitud_sinac'             => 'TECNEXA',
        'precision_sinac'             => 'TECNPRC',
        'limite_deteccion_sinac'      => 'TECNLID',
        'limite_cuantificacion_sinac' => 'TECNLIC',
        'codigo_laboratorio_sinac'    => 'TECNCOL',
        'decimales_sinac'             => 'TECNDEC',
        'fecha_baja'                  => 'TECDBAJ',
        'es_baja'                     => 'TECBBAJ',
        'seccion_delegacion'          => 'SEC2DEL',
        'seccion_codigo'              => 'SEC2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'                  => 'nullable|string|max:10',
            'codigo'                      => 'nullable|string|max:30',
            'nombre'                      => 'nullable|string|max:255',
            'nombre_informes'             => 'nullable|string|max:255',
            'id_igeo'                     => 'nullable|string|max:20',
            'es_cursiva'                  => 'nullable|string|in:T,F|max:1',
            'fecha_acreditacion'          => 'nullable|date',
            'parametro'                   => 'nullable|string|max:100',
            'abreviatura'                 => 'nullable|string|max:50',
            'numero_cas'                  => 'nullable|string|max:50',
            'precio'                      => 'nullable|numeric|min:0',
            'descuento'                   => 'nullable|string|max:15',
            'unidades'                    => 'nullable|string|max:50',
            'leyenda'                     => 'nullable|string|max:100',
            'metodologia'                 => 'nullable|string|max:255',
            'metodologia_abreviada'       => 'nullable|string|max:255',
            'normativa'                   => 'nullable|string|max:100',
            'tiempo_prueba'               => 'nullable|integer|min:0',
            'tiempo_descarte'             => 'nullable|integer|min:0',
            'limite_cuantificacion'       => 'nullable|string|max:50',
            'valor_minimo_detectable'     => 'nullable|string|max:50',
            'incertidumbre'               => 'nullable|string|max:50',
            'instruccion'                 => 'nullable|string',
            'es_exportable'               => 'nullable|string|in:T,F|max:1',
            'codigo_metodo_sinac'         => 'nullable|integer|min:0',
            'tipo_metodo_sinac'           => 'nullable|integer|min:0',
            'numero_norma_sinac'          => 'nullable|string|max:50',
            'es_acreditado_sinac'         => 'nullable|string|in:T,F|max:1',
            'es_validado_sinac'           => 'nullable|string|in:T,F|max:1',
            'es_equivalente_sinac'        => 'nullable|string|in:T,F|max:1',
            'es_sin_cualificacion_sinac'  => 'nullable|string|in:T,F|max:1',
            'es_uso_rutina_sinac'         => 'nullable|string|in:T,F|max:1',
            'codigo_parametro_sinac'      => 'nullable|string|max:10',
            'exactitud_sinac'             => 'nullable|numeric|min:0',
            'precision_sinac'             => 'nullable|numeric|min:0',
            'limite_deteccion_sinac'      => 'nullable|numeric|min:0',
            'limite_cuantificacion_sinac' => 'nullable|numeric|min:0',
            'codigo_laboratorio_sinac'    => 'nullable|numeric|min:0',
            'decimales_sinac'             => 'nullable|integer|min:0',
            'fecha_baja'                  => 'nullable|date',
            'es_baja'                     => 'nullable|string|in:T,F|max:1',
            'seccion_delegacion'          => 'nullable|string|max:10',
            'seccion_codigo'              => 'nullable|integer|min:0',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        if (! empty($data['delegacion'])) {
            $exists = DB::connection('dynamic')->table('ACCDEL')
                ->where('DEL1COD', $data['delegacion'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La delegación no existe');
            }
        }

        if (! empty($data['seccion_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABSEC')
                ->where('DEL3COD', $data['seccion_delegacion'] ?? '')
                ->where('SEC1COD', $data['seccion_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La sección no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('LABTEC')->where('TECCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('TEC1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del parámetro ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTEC')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('TEC1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del parámetro ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        // Relaciones donde el parámetro es referencia parte de PK (TEC3*).
        $refs3 = [
            ['LABRES', 'está siendo referenciado en algún resultado'],
            ['LABCYT', 'está siendo referenciado en alguna carta de control'],
        ];
        foreach ($refs3 as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('TEC3DEL', $delegation)->where('TEC3COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El parámetro no puede ser eliminado porque {$reason}");
            }
        }

        $used = DB::connection('dynamic')->table('LABSYT')
            ->where('DEL3TEC', $delegation)->where('TEC3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El parámetro no puede ser eliminado porque está siendo referenciado en algún servicio');
        }

        // Relaciones donde el parámetro es referencia simple (TEC2*).
        $refs2 = [
            ['FACLIF', 'está siendo referenciado en alguna factura'],
            ['FACLIC', 'está siendo referenciado en algún contrato'],
            ['FACLIP', 'está siendo referenciado en algún presupuesto'],
            ['ALMMOV', 'está siendo referenciado en algún movimiento de inventario'],
            ['LABOPE', 'está siendo referenciado en alguna operación'],
            ['LABORD', 'está siendo referenciado en alguna orden'],
            ['LABRED', 'está siendo referenciado en algún residuo'],
        ];
        foreach ($refs2 as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('TEC2DEL', $delegation)->where('TEC2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El parámetro no puede ser eliminado porque {$reason}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        // Vínculos donde la delegación del parámetro va en DEL3TEC.
        DB::connection('dynamic')->table('PLAPYT')
            ->where('DEL3TEC', $delegation)->where('TEC3COD', $code)->delete();
        DB::connection('dynamic')->table('LABTYM')
            ->where('DEL3TEC', $delegation)->where('TEC3COD', $code)->delete();

        // Vínculos donde la delegación del parámetro va en TEC3DEL.
        $relatedTec3 = ['LABPYT', 'LABTYN', 'LABTYC', 'LABTYF', 'LABTYE', 'LABTYQ', 'LABTYP', 'LABCOT', 'LABCYR'];
        foreach ($relatedTec3 as $table) {
            DB::connection('dynamic')->table($table)
                ->where('TEC3DEL', $delegation)->where('TEC3COD', $code)->delete();
        }

        // Documentos del parámetro a la papelera.
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('TEC2COD', $code)
            ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);
    }
}
