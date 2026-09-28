<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class FamiliaController extends BaseController
{
    protected string $table = 'ALMFAM';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'FAM1COD',
    ];
    protected array $searchFields = ['FAMCDES'];

    protected bool $generatesCode = true;

    protected array $foreignKeys = [
        'familia_padre' => 'int',
    ];

    protected array $mapping = [
        'delegacion'               => 'DEL3COD',
        'codigo'                   => 'FAM1COD',
        'descripcion'              => 'FAMCDES',
        'familia_padre_delegacion' => 'FAM2DEL',
        'familia_padre_codigo'     => 'FAM2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'               => 'nullable|string|max:10',
            'codigo'                   => 'nullable|integer',
            'descripcion'              => 'nullable|string|max:255',
            'familia_padre_delegacion' => 'nullable|string|max:10',
            'familia_padre_codigo'     => 'nullable|integer',
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

        if (! empty($data['familia_padre_codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMFAM')
                ->where('DEL3COD', $data['familia_padre_delegacion'] ?? '')
                ->where('FAM1COD', $data['familia_padre_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La familia padre no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('ALMFAM')->where('FAMCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('FAM1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción de la familia ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMFAM')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('FAM1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de la familia ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('ALMFAM')
            ->where('FAM2DEL', $delegation)->where('FAM2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La familia no puede ser eliminada porque contiene subfamilias');
        }

        $used = DB::connection('dynamic')->table('ALMPRD')
            ->where('FAM2DEL', $delegation)->where('FAM2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La familia no puede ser eliminada porque está vinculada a algún producto');
        }
    }
}
