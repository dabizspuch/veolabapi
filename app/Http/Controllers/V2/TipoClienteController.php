<?php

namespace App\Http\Controllers\V2;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class TipoClienteController extends BaseController
{
    protected string $table = 'SINTIC';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TIC1COD',
    ];
    protected ?string $inactiveField = 'TICBBAJ';
    protected array $searchFields = ['TICCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'TIC1COD',
        'descripcion' => 'TICCDES',
        'es_baja'     => 'TICBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer',
            'descripcion' => 'nullable|string|max:50',
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
            $query = DB::connection('dynamic')->table('SINTIC')->where('TICCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('TIC1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del tipo de cliente ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('SINTIC')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('TIC1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de tipo de cliente ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('SINCLI')
            ->where('TIC2DEL', $delegation)->where('TIC2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El tipo de cliente no puede ser eliminado porque está siendo referenciado en clientes');
        }
    }
}
