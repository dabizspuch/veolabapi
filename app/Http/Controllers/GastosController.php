<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class GastosController extends BaseController
{
    protected string $table = 'LABESC';
    protected ?string $auditDescription = 'ESCCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'ESC1COD',
    ];
    protected ?string $inactiveField = 'ESCBBAJ';
    protected array $searchFields = ['ESCCDES', 'ESCCOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'    => 'DEL3COD',
        'codigo'        => 'ESC1COD',
        'descripcion'   => 'ESCCDES',
        'observaciones' => 'ESCCOBS',
        'es_suplido'    => 'ESCBSUP',
        'precio'        => 'ESCNPRE',
        'descuento'     => 'ESCCDTO',
        'fecha_baja'    => 'ESCDBAJ',
        'es_baja'       => 'ESCBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'    => 'nullable|string|max:10',
            'codigo'        => 'nullable|integer',
            'descripcion'   => 'nullable|string|max:100',
            'observaciones' => 'nullable|string',
            'es_suplido'    => 'nullable|string|in:T,F|max:1',
            'precio'        => 'nullable|numeric',
            'descuento'     => 'nullable|string|max:15',
            'fecha_baja'    => 'nullable|date',
            'es_baja'       => 'nullable|string|in:T,F|max:1',
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
            $query = DB::connection('dynamic')->table('LABESC')->where('ESCCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('ESC1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del gasto ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABESC')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('ESC1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de gasto ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('LABPYG')
            ->where('ESC3DEL', $delegation)->where('ESC3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El gasto no puede ser eliminado porque está siendo referenciado en alguna planificación');
        }

        $used = DB::connection('dynamic')->table('LABOYG')
            ->where('ESC3DEL', $delegation)->where('ESC3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El gasto no puede ser eliminado porque está siendo referenciado en alguna operación');
        }

        $used = DB::connection('dynamic')->table('LABSYE')
            ->where('DEL3ESC', $delegation)->where('ESC3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El gasto no puede ser eliminado porque está siendo referenciado en algún servicio');
        }

        $references = [
            ['FACLIF', 'está siendo referenciado en alguna factura'],
            ['FACLIC', 'está siendo referenciado en algún contrato'],
            ['FACLIP', 'está siendo referenciado en algún presupuesto'],
        ];
        foreach ($references as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('ESC2DEL', $delegation)->where('ESC2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El gasto no puede ser eliminado porque {$reason}");
            }
        }
    }
}
