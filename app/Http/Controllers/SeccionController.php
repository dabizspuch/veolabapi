<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class SeccionController extends BaseController
{
    protected string $table = 'LABSEC';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'SEC1COD',
    ];
    protected ?string $inactiveField = 'SECBBAJ';
    protected array $searchFields = ['SECCDES'];

    protected bool $generatesCode = true;

    protected array $foreignKeys = [
        'departamento' => 'int',
    ];

    protected array $mapping = [
        'delegacion'              => 'DEL3COD',
        'codigo'                  => 'SEC1COD',
        'descripcion'             => 'SECCDES',
        'icono'                   => 'SECNICO',
        'posicion'                => 'SECNORD',
        'tipo'                    => 'SECCTIP',
        'es_baja'                 => 'SECBBAJ',
        'departamento_delegacion' => 'DEP2DEL',
        'departamento_codigo'     => 'DEP2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'              => 'nullable|string|max:10',
            'codigo'                  => 'nullable|integer',
            'descripcion'             => 'nullable|string|max:100',
            'icono'                   => 'nullable|integer',
            'posicion'                => 'nullable|integer',
            'tipo'                    => 'nullable|string|in:M,F|max:1',
            'es_baja'                 => 'nullable|string|in:T,F|max:1',
            'departamento_delegacion' => 'nullable|string|max:10',
            'departamento_codigo'     => 'nullable|integer',
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

        if (! empty($data['departamento_codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHDEP')
                ->where('DEL3COD', $data['departamento_delegacion'] ?? '')
                ->where('DEP1COD', $data['departamento_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El departamento no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('LABSEC')->where('SECCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('SEC1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción de la sección ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABSEC')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('SEC1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de la sección ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $references = [
            ['LABTEC', 'está siendo referenciada en algún parámetro'],
            ['LABRES', 'está siendo referenciada en algún resultado'],
            ['FACLIF', 'está siendo referenciada en alguna factura'],
            ['FACLIC', 'está siendo referenciada en algún contrato'],
        ];
        foreach ($references as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('SEC2DEL', $delegation)->where('SEC2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("La sección no puede ser eliminada porque {$reason}");
            }
        }
    }
}
