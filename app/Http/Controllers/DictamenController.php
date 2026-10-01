<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use Illuminate\Support\Facades\DB;

/**
 * Dictámenes de operación (LABDIC), con la marca de resultados que los
 * provoca. No se borran si alguna operación los usa (se dan de baja).
 */
class DictamenController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABDIC';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'DIC1COD',
    ];
    protected ?string $inactiveField = 'DICBBAJ';
    protected array $searchFields = ['DICCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'       => 'DEL3COD',
        'codigo'           => 'DIC1COD',
        'descripcion'      => 'DICCDES',
        'es_baja'          => 'DICBBAJ',
        'marca_delegacion' => 'MAR2DEL',
        'marca_codigo'     => 'MAR2COD',
    ];

    protected array $foreignKeys = ['marca' => 'int'];

    protected function rules(): array
    {
        return [
            'delegacion'       => 'nullable|string|max:10',
            'codigo'           => 'nullable|integer|min:1',
            'descripcion'      => 'nullable|string|max:50',
            'es_baja'          => 'nullable|string|in:T,F',
            'marca_delegacion' => 'nullable|string|max:10',
            'marca_codigo'     => 'nullable|integer',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (! $keys) {
            $data['es_baja'] ??= 'F';
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $used = DB::connection('dynamic')->table('LABOPE')
            ->where('DIC2DEL', (string) $keys['delegacion'])->where('DIC2COD', $keys['codigo'])->exists();
        if ($used) {
            throw new BusinessRuleException('El dictamen no puede ser eliminado porque está siendo referenciado en alguna operación');
        }
    }
}
