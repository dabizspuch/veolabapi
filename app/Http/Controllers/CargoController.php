<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class CargoController extends BaseController
{
    protected string $table = 'GRHCAR';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'CAR1COD',
    ];
    protected ?string $inactiveField = 'CARBBAJ';
    protected array $searchFields = ['CARCNOM', 'CARCOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'                => 'DEL3COD',
        'codigo'                    => 'CAR1COD',
        'nombre'                    => 'CARCNOM',
        'certificaciones'           => 'CARCCEA',
        'requerimientos'            => 'CARCRFA',
        'experiencia'               => 'CARCEXR',
        'caracteristicas'           => 'CARCCAP',
        'observaciones'             => 'CARCOBS',
        'es_baja'                   => 'CARBBAJ',
        'departamento_delegacion'   => 'DEP2DEL',
        'departamento_codigo'       => 'DEP2COD',
        'cargo_superior_delegacion' => 'CAR2DEL',
        'cargo_superior_codigo'     => 'CAR2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'nombre'                    => 'nullable|string|max:100',
            'certificaciones'           => 'nullable|string',
            'requerimientos'            => 'nullable|string',
            'experiencia'               => 'nullable|string',
            'caracteristicas'           => 'nullable|string',
            'observaciones'             => 'nullable|string',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'departamento_delegacion'   => 'nullable|string|max:10',
            'departamento_codigo'       => 'nullable|integer',
            'cargo_superior_delegacion' => 'nullable|string|max:10',
            'cargo_superior_codigo'     => 'nullable|integer',
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

        if (! empty($data['cargo_superior_codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHCAR')
                ->where('DEL3COD', $data['cargo_superior_delegacion'] ?? '')
                ->where('CAR1COD', $data['cargo_superior_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El cargo no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('GRHCAR')->where('CARCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('CAR1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del cargo ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHCAR')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('CAR1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del cargo ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('GRHEYC')
            ->where('CAR3DEL', $delegation)->where('CAR3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El cargo no puede ser eliminado porque está siendo referenciado en algún empleado');
        }

        $used = DB::connection('dynamic')->table('GRHCAR')
            ->where('CAR2DEL', $delegation)->where('CAR2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El cargo no puede ser eliminado porque está siendo referenciado por otro cargo');
        }

        $used = DB::connection('dynamic')->table('GRHCUR')
            ->where('CAR2DEL', $delegation)->where('CAR2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El cargo no puede ser eliminado porque está siendo referenciado en algún currículum');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('GRHTAR')
            ->where('CAR3DEL', $delegation)->where('CAR3COD', $code)->delete();
    }
}
