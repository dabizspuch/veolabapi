<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class TipoEvaluacionController extends BaseController
{
    protected string $table = 'SINTIE';
    protected ?string $auditDescription = 'TIECDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TIE1COD',
    ];
    protected ?string $inactiveField = 'TIEBBAJ';
    protected array $searchFields = ['TIECDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'TIE1COD',
        'descripcion' => 'TIECDES',
        'es_baja'     => 'TIEBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer',
            'descripcion' => 'nullable|string|max:100',
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
            $query = DB::connection('dynamic')->table('SINTIE')->where('TIECDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('TIE1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del tipo de evaluación ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('SINTIE')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('TIE1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de tipo de evaluación ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('SINPRO')
            ->where('TIE2DEL', $delegation)->where('TIE2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El tipo de evaluación no puede ser eliminado porque está siendo referenciado en proveedores');
        }
    }
}
