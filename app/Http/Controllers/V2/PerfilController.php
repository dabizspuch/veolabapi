<?php

namespace App\Http\Controllers\V2;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class PerfilController extends BaseController
{
    protected string $table = 'ACCPER';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'PER1COD',
    ];
    protected array $searchFields = ['PERCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'            => 'DEL3COD',
        'codigo'                => 'PER1COD',
        'descripcion'           => 'PERCDES',
        'estado_desde'          => 'PERNESD',
        'estado_hasta'          => 'PERNESH',
        'precios_restringidos'  => 'PERBPRE',
        'campos_operaciones'    => 'PERCCAO',
        'campos_resultados'     => 'PERCCAR',
        'tipo_firma_delegacion' => 'TIF2DEL',
        'tipo_firma_codigo'     => 'TIF2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'            => 'nullable|string|max:10',
            'codigo'                => 'nullable|integer',
            'descripcion'           => 'nullable|string|max:50',
            'estado_desde'          => 'nullable|integer|in:0,1,2,3,4,5,6,7',
            'estado_hasta'          => 'nullable|integer|in:0,1,2,3,4,5,6,7',
            'precios_restringidos'  => 'nullable|string|in:T,F|max:1',
            'campos_operaciones'    => 'nullable|string',
            'campos_resultados'     => 'nullable|string',
            'tipo_firma_delegacion' => 'nullable|string|max:10',
            'tipo_firma_codigo'     => 'nullable|integer',
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

        if (! empty($data['tipo_firma_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTIF')
                ->where('DEL3COD', $data['tipo_firma_delegacion'] ?? '')
                ->where('TIF1COD', $data['tipo_firma_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El tipo de firma no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('ACCPER')->where('PERCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('PER1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del perfil ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('ACCPER')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('PER1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del perfil ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('ACCUSU')
            ->where('PER2DEL', $delegation)->where('PER2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El perfil no puede ser eliminado porque está siendo referenciado en algún usuario');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('ACCPYF')
            ->where('DEL3COD', $delegation)->where('PER3COD', $code)->delete();

        DB::connection('dynamic')->table('DOCDYP')
            ->where('PER3DEL', $delegation)->where('PER3COD', $code)->delete();
    }
}
