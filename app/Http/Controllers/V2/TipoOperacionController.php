<?php

namespace App\Http\Controllers\V2;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class TipoOperacionController extends BaseController
{
    protected string $table = 'LABTIO';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TIO1COD',
    ];
    protected ?string $inactiveField = 'TIOBBAJ';
    protected array $searchFields = ['TIOCNOM'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'                => 'DEL3COD',
        'codigo'                    => 'TIO1COD',
        'nombre'                    => 'TIOCNOM',
        'es_predeterminado'         => 'TIOBPRE',
        'es_gestionable_equipos'    => 'TIOBGDE',
        'es_gestionable_parametros' => 'TIOBGDT',
        'es_baja'                   => 'TIOBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'nombre'                    => 'nullable|string|max:50',
            'es_predeterminado'         => 'nullable|string|in:T,F|max:1',
            'es_gestionable_equipos'    => 'nullable|string|in:T,F|max:1',
            'es_gestionable_parametros' => 'nullable|string|in:T,F|max:1',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
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
            $query = DB::connection('dynamic')->table('LABTIO')->where('TIOCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('TIO1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del tipo de operación ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTIO')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('TIO1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del tipo de operación ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $record = DB::connection('dynamic')->table('LABTIO')
            ->where('DEL3COD', $delegation)->where('TIO1COD', $code)->first();
        if ($record && $record->TIOBPRE === 'T') {
            throw new BusinessRuleException('El tipo de operación no puede ser eliminado porque es predeterminado del sistema');
        }

        $references = [
            ['LABOPE', 'está siendo referenciado en alguna operación'],
            ['LABPLO', 'está siendo referenciado en alguna planificación'],
            ['LABSER', 'está siendo referenciado en algún servicio'],
        ];
        foreach ($references as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('TIO2DEL', $delegation)->where('TIO2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El tipo de operación no puede ser eliminado porque {$reason}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('LABOYM')
            ->where('DEL3TIO', $delegation)->where('TIO3COD', $code)->delete();
    }
}
