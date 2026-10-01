<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class TipoEquipoController extends BaseController
{
    protected string $table = 'LABTEQ';
    protected ?string $auditDescription = 'TEQCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TEQ1COD',
    ];
    protected array $searchFields = ['TEQCDES'];

    protected bool $generatesCode = true;

    protected array $foreignKeys = [
        'tipo_equipo' => 'int',
    ];

    protected array $mapping = [
        'delegacion'             => 'DEL3COD',
        'codigo'                 => 'TEQ1COD',
        'descripcion'            => 'TEQCDES',
        'tipo_equipo_delegacion' => 'TEQ2DEL',
        'tipo_equipo_codigo'     => 'TEQ2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'             => 'nullable|string|max:10',
            'codigo'                 => 'nullable|integer',
            'descripcion'            => 'nullable|string|max:100',
            'tipo_equipo_delegacion' => 'nullable|string|max:10',
            'tipo_equipo_codigo'     => 'nullable|integer',
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

        if (! empty($data['tipo_equipo_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTEQ')
                ->where('DEL3COD', $data['tipo_equipo_delegacion'] ?? '')
                ->where('TEQ1COD', $data['tipo_equipo_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El tipo de equipo no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('LABTEQ')->where('TEQCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('TEQ1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del tipo de equipo ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTEQ')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('TEQ1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del tipo de equipo ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('LABEQU')
            ->where('TEQ2DEL', $delegation)->where('TEQ2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El tipo de equipo no puede ser eliminado porque está siendo referenciado en algún equipo');
        }

        $used = DB::connection('dynamic')->table('LABTEQ')
            ->where('TEQ2DEL', $delegation)->where('TEQ2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El tipo de equipo no puede ser eliminado porque está siendo referenciado en otro tipo de equipo');
        }
    }
}
