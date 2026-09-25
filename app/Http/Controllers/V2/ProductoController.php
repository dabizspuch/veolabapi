<?php

namespace App\Http\Controllers\V2;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class ProductoController extends BaseController
{
    protected string $table = 'ALMPRD';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'PRD1COD',
    ];
    protected ?string $inactiveField = 'PRDBBAJ';
    protected array $searchFields = ['PRDCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'            => 'DEL3COD',
        'codigo'                => 'PRD1COD',
        'descripcion'           => 'PRDCDES',
        'marca'                 => 'PRDCMAR',
        'modelo'                => 'PRDCMOD',
        'fabricante'            => 'PRDCFAB',
        'ano_fabricacion'       => 'PRDCANF',
        'codigo_barras'         => 'PRDCCOB',
        'es_equipo'             => 'PRDBEQU',
        'es_consumible'         => 'PRDBCON',
        'permite_operaciones'   => 'PRDBOPE',
        'unidades'              => 'PRDCUNI',
        'stock_minimo'          => 'PRDNSMI',
        'stock_maximo'          => 'PRDNSMA',
        'existencias_unidades'  => 'PRDNEXI',
        'existencias_cantidad'  => 'PRDNCAE',
        'observaciones'         => 'PRDCOBS',
        'es_baja'               => 'PRDBBAJ',
        'referencia'            => 'PRDCREF',
        'precio'                => 'PRDNPRE',
        'familia_delegacion'    => 'FAM2DEL',
        'familia_codigo'        => 'FAM2COD',
        'proveedor_delegacion'  => 'PRO2DEL',
        'proveedor_codigo'      => 'PRO2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'           => 'nullable|string|max:10',
            'codigo'               => 'nullable|string|max:15',
            'descripcion'          => 'nullable|string|max:255',
            'marca'                => 'nullable|string|max:100',
            'modelo'               => 'nullable|string|max:100',
            'fabricante'           => 'nullable|string|max:100',
            'ano_fabricacion'      => 'nullable|string|max:10',
            'codigo_barras'        => 'nullable|string|max:100',
            'es_equipo'            => 'nullable|string|in:T,F|max:1',
            'es_consumible'        => 'nullable|string|in:T,F|max:1',
            'permite_operaciones'  => 'nullable|string|in:T,F|max:1',
            'unidades'             => 'nullable|string|max:20',
            'stock_minimo'         => 'nullable|numeric|min:0',
            'stock_maximo'         => 'nullable|numeric|min:0',
            'existencias_unidades' => 'nullable|numeric|min:0',
            'existencias_cantidad' => 'nullable|numeric|min:0',
            'observaciones'        => 'nullable|string',
            'es_baja'              => 'nullable|string|in:T,F|max:1',
            'referencia'           => 'nullable|string|max:30',
            'precio'               => 'nullable|numeric|min:0',
            'familia_delegacion'   => 'nullable|string|max:10',
            'familia_codigo'       => 'nullable|integer',
            'proveedor_delegacion' => 'nullable|string|max:10',
            'proveedor_codigo'     => 'nullable|string|max:15',
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

        if (! empty($data['familia_codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMFAM')
                ->where('DEL3COD', $data['familia_delegacion'] ?? '')
                ->where('FAM1COD', $data['familia_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La familia no existe');
            }
        }

        if (! empty($data['proveedor_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINPRO')
                ->where('DEL3COD', $data['proveedor_delegacion'] ?? '')
                ->where('PRO1COD', $data['proveedor_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El proveedor no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('ALMPRD')->where('PRDCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('PRD1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del producto ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMPRD')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('PRD1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del producto ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque contiene series o lotes');
        }

        $used = DB::connection('dynamic')->table('LABTYQ')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque está vinculado a algún parámetro (equipo)');
        }

        $used = DB::connection('dynamic')->table('LABTYP')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque está vinculado a algún parámetro (consumible)');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('ALMPYP')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->delete();

        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('PRD2COD', $code)
            ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);
    }
}
