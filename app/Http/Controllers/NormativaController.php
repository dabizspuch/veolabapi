<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class NormativaController extends BaseController
{
    protected string $table = 'LABNOR';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'NOR1COD',
    ];
    protected ?string $inactiveField = 'NORBBAJ';
    protected array $searchFields = ['NORCDES', 'NORCABR', 'NORCOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'    => 'DEL3COD',
        'codigo'        => 'NOR1COD',
        'descripcion'   => 'NORCDES',
        'abreviatura'   => 'NORCABR',
        'observaciones' => 'NORCOBS',
        'es_desglose'   => 'NORBPDT',
        'fecha_baja'    => 'NORDBAJ',
        'es_baja'       => 'NORBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'    => 'nullable|string|max:10',
            'codigo'        => 'nullable|string|max:20',
            'descripcion'   => 'nullable|string',
            'abreviatura'   => 'nullable|string|max:50',
            'observaciones' => 'nullable|string',
            'es_desglose'   => 'nullable|string|in:T,F|max:1',
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

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABNOR')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('NOR1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de normativa ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('LABINF')
            ->where('NOR2DEL', $delegation)->where('NOR2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La normativa no puede ser eliminada porque está siendo referenciada en algún informe');
        }

        $used = DB::connection('dynamic')->table('LABSER')
            ->where('NOR2DEL', $delegation)->where('NOR2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La normativa no puede ser eliminada porque está siendo referenciada en algún servicio');
        }

        $used = DB::connection('dynamic')->table('LABTYN')
            ->where('NOR3DEL', $delegation)->where('NOR3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La normativa no puede ser eliminada porque está siendo referenciada en algún parámetro');
        }
    }
}
