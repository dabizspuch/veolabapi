<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class FormaEnvioController extends BaseController
{
    protected string $table = 'LABFDE';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'FDE1COD',
    ];
    protected ?string $inactiveField = 'FDEBBAJ';
    protected array $searchFields = ['FDECDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'FDE1COD',
        'descripcion' => 'FDECDES',
        'especial'    => 'FDECESP',
        'es_baja'     => 'FDEBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer',
            'descripcion' => 'nullable|string|max:50',
            'especial'    => 'nullable|string|in:N,E,P|max:1',
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
            $query = DB::connection('dynamic')->table('LABFDE')->where('FDECDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('FDE1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción de la forma de envío ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABFDE')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('FDE1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de la forma de envío ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('SINCLI')
            ->where('FDE2DEL', $delegation)->where('FDE2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La forma de envío no puede ser eliminada porque está siendo referenciada en clientes');
        }

        $used = DB::connection('dynamic')->table('LABINF')
            ->where('FDE2DEL', $delegation)->where('FDE2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La forma de envío no puede ser eliminada porque está siendo referenciada en informes');
        }
    }
}
