<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class DepartamentoController extends BaseController
{
    protected string $table = 'GRHDEP';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'DEP1COD',
    ];
    protected ?string $inactiveField = 'DEPBBAJ';
    protected array $searchFields = ['DEPCNOM'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'DEP1COD',
        'nombre'     => 'DEPCNOM',
        'es_baja'    => 'DEPBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion' => 'nullable|string|max:10',
            'codigo'     => 'nullable|integer',
            'nombre'     => 'nullable|string|max:50',
            'es_baja'    => 'nullable|string|in:T,F|max:1',
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

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('GRHDEP')->where('DEPCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('DEP1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del departamento ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHDEP')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('DEP1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del departamento ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $references = [
            ['GRHCAR', 'está siendo referenciado en algún cargo'],
            ['GRHCUR', 'está siendo referenciado en algún currículum'],
            ['LABORD', 'está siendo referenciado en alguna orden'],
            ['LABSEC', 'está siendo referenciado en alguna sección'],
        ];
        foreach ($references as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('DEP2DEL', $delegation)->where('DEP2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El departamento no puede ser eliminado porque {$reason}");
            }
        }
    }
}
