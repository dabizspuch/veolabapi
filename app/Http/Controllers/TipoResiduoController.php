<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use Illuminate\Support\Facades\DB;

/**
 * Tipos de residuos (LABTDR). La descripción no se repite en la delegación
 * ni en las generales; no se borran si algún residuo los usa.
 */
class TipoResiduoController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABTDR';
    protected ?string $auditDescription = 'TDRCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TDR1COD',
    ];
    protected ?string $inactiveField = 'TDRBBAJ';
    protected array $searchFields = ['TDRCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'TDR1COD',
        'descripcion' => 'TDRCDES',
        'es_baja'     => 'TDRBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer|min:1',
            'descripcion' => 'nullable|string|max:100',
            'es_baja'     => 'nullable|string|in:T,F',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (! empty($data['descripcion'])) {
            $delegation = (string) ($keys ? $keys['delegacion'] : ($data['delegacion'] ?? ''));
            $query = DB::connection('dynamic')->table('LABTDR')
                ->whereIn('DEL3COD', array_unique(['', $delegation]))
                ->where('TDRCDES', $data['descripcion']);
            if ($keys) {
                $query->where(fn ($q) => $q->where('DEL3COD', '!=', $delegation)->orWhere('TDR1COD', '!=', $keys['codigo']));
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del tipo de residuo ya está en uso');
            }
        }

        if (! $keys) {
            $data['es_baja'] ??= 'F';
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $used = DB::connection('dynamic')->table('LABRED')
            ->where('TDR2DEL', (string) $keys['delegacion'])->where('TDR2COD', $keys['codigo'])->exists();
        if ($used) {
            throw new BusinessRuleException('El tipo de residuo no puede ser eliminado porque está siendo referenciado en algún residuo');
        }
    }
}
