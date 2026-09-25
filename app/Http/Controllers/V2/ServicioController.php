<?php

namespace App\Http\Controllers\V2;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class ServicioController extends BaseController
{
    protected string $table = 'LABSER';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'SER1COD',
    ];
    protected ?string $inactiveField = 'SERBBAJ';
    protected array $searchFields = ['SERCNOM', 'SERCNOI', 'SERCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'                => 'DEL3COD',
        'codigo'                    => 'SER1COD',
        'nombre'                    => 'SERCNOM',
        'nombre_informes'           => 'SERCNOI',
        'id_igeo'                   => 'SERCIGC',
        'descripcion'               => 'SERCDES',
        'observaciones'             => 'SERCOBS',
        'objetivo'                  => 'SERCOBJ',
        'numero_envases'            => 'SERNENV',
        'cantidad'                  => 'SERCCAN',
        'precio'                    => 'SERNPRE',
        'descuento'                 => 'SERCDTO',
        'tiempo_prueba'             => 'SERNTIE',
        'tipo_dia'                  => 'SERCTDI',
        'es_titulo_unico'           => 'SERBTUC',
        'fecha_baja'                => 'SERDBAJ',
        'es_baja'                   => 'SERBBAJ',
        'tipo_operacion_delegacion' => 'TIO2DEL',
        'tipo_operacion_codigo'     => 'TIO2COD',
        'matriz_delegacion'         => 'MAT2DEL',
        'matriz_codigo'             => 'MAT2COD',
        'normativa_delegacion'      => 'NOR2DEL',
        'normativa_codigo'          => 'NOR2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|string|max:20',
            'nombre'                    => 'nullable|string|max:100',
            'nombre_informes'           => 'nullable|string|max:100',
            'id_igeo'                   => 'nullable|string|max:20',
            'descripcion'               => 'nullable|string',
            'observaciones'             => 'nullable|string',
            'objetivo'                  => 'nullable|string',
            'numero_envases'            => 'nullable|numeric',
            'cantidad'                  => 'nullable|string|max:50',
            'precio'                    => 'nullable|numeric',
            'descuento'                 => 'nullable|string|max:15',
            'tiempo_prueba'             => 'nullable|integer',
            'tipo_dia'                  => 'nullable|string|in:L,N|max:1',
            'es_titulo_unico'           => 'nullable|string|in:T,F|max:1',
            'fecha_baja'                => 'nullable|date',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'tipo_operacion_delegacion' => 'nullable|string|max:10',
            'tipo_operacion_codigo'     => 'nullable|integer',
            'matriz_delegacion'         => 'nullable|string|max:10',
            'matriz_codigo'             => 'nullable|integer',
            'normativa_delegacion'      => 'nullable|string|max:10',
            'normativa_codigo'          => 'nullable|string|max:20',
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

        if (! empty($data['matriz_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABMAT')
                ->where('DEL3COD', $data['matriz_delegacion'] ?? '')
                ->where('MAT1COD', $data['matriz_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La matriz no existe');
            }
        }

        if (! empty($data['tipo_operacion_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTIO')
                ->where('DEL3COD', $data['tipo_operacion_delegacion'] ?? '')
                ->where('TIO1COD', $data['tipo_operacion_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El tipo de operación no existe');
            }
        }

        if (! empty($data['normativa_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABNOR')
                ->where('DEL3COD', $data['normativa_delegacion'] ?? '')
                ->where('NOR1COD', $data['normativa_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La normativa no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('LABSER')->where('SERCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('SER1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del servicio ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABSER')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('SER1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del servicio ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        // Referencias como parte de PK (SER3*).
        $refs3 = [
            ['PLAPYS', 'DEL3SER', 'está siendo referenciado en alguna plantilla'],
            ['LABPYS', 'SER3DEL', 'está siendo referenciado en alguna planificación'],
            ['LABOYS', 'SER3DEL', 'está siendo referenciado en alguna operación'],
        ];
        foreach ($refs3 as [$table, $delCol, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where($delCol, $delegation)->where('SER3COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El servicio no puede ser eliminado porque {$reason}");
            }
        }

        // Referencias simples (SER2*).
        $refs2 = [
            ['LABPYT', 'está siendo referenciado en alguna planificación'],
            ['LABPYG', 'está siendo referenciado en alguna planificación'],
            ['LABRES', 'está siendo referenciado en alguna operación'],
            ['LABOYG', 'está siendo referenciado en alguna operación'],
            ['FACLIF', 'está siendo referenciado en alguna línea de factura'],
            ['FACLIC', 'está siendo referenciado en alguna línea de contrato'],
            ['FACLIP', 'está siendo referenciado en alguna línea de presupuesto'],
        ];
        foreach ($refs2 as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('SER2DEL', $delegation)->where('SER2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El servicio no puede ser eliminado porque {$reason}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('LABSYC')
            ->where('SER3DEL', $delegation)->where('SER3COD', $code)->delete();

        DB::connection('dynamic')->table('LABSYF')
            ->where('SER3DEL', $delegation)->where('SER3COD', $code)->delete();

        DB::connection('dynamic')->table('LABSYE')
            ->where('DEL3SER', $delegation)->where('SER3COD', $code)->delete();

        DB::connection('dynamic')->table('LABSYT')
            ->where('DEL3SER', $delegation)->where('SER3COD', $code)->delete();

        DB::connection('dynamic')->table('LABAYS')
            ->where('SER3DEL', $delegation)->where('SER3COD', $code)->delete();
    }
}
