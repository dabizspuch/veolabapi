<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class MatrizController extends BaseController
{
    protected string $table = 'LABMAT';
    protected ?string $auditDescription = 'MATCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'MAT1COD',
    ];
    protected ?string $inactiveField = 'MATBBAJ';
    protected array $searchFields = ['MATCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'MAT1COD',
        'descripcion' => 'MATCDES',
        'es_baja'     => 'MATBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer',
            'descripcion' => 'nullable|string|max:255',
            'es_baja'     => 'nullable|string|in:T,F|max:1',
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
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('LABMAT')->where('MATCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('MAT1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción de la matriz ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABMAT')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('MAT1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de la matriz ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $references = [
            ['LABOPE', 'está siendo referenciada en alguna operación'],
            ['LABPLO', 'está siendo referenciada en alguna planificación'],
            ['LABSER', 'está siendo referenciada en algún servicio'],
            ['LABCDC', 'está siendo referenciada en alguna carta de control'],
        ];
        foreach ($references as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('MAT2DEL', $delegation)->where('MAT2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("La matriz no puede ser eliminada porque {$reason}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('LABTYM')
            ->where('DEL3MAT', $delegation)->where('MAT3COD', $code)->delete();

        DB::connection('dynamic')->table('LABOYM')
            ->where('DEL3MAT', $delegation)->where('MAT3COD', $code)->delete();
    }
}
