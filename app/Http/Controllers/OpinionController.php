<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;

/**
 * Opiniones e interpretaciones (LABOEI): textos para los informes. La
 * automática (`automatica`) se propone al crear informes: C con normativa,
 * S sin normativa, M por la marca de los resultados, I sin marcas.
 */
class OpinionController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABOEI';
    protected ?string $auditDescription = 'OEICVAL';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'OEI1COD',
    ];
    protected array $searchFields = ['OEICVAL'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'       => 'DEL3COD',
        'codigo'           => 'OEI1COD',
        'texto'            => 'OEICVAL',
        'automatica'       => 'OEICAUT',
        'marca_delegacion' => 'MAR2DEL',
        'marca_codigo'     => 'MAR2COD',
    ];

    protected array $foreignKeys = ['marca' => 'int'];

    protected function rules(): array
    {
        return [
            'delegacion'       => 'nullable|string|max:10',
            'codigo'           => 'nullable|integer|min:1',
            'texto'            => 'nullable|string',
            'automatica'       => 'nullable|string|in:C,S,M,I',
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
        // Sin automática se guarda '' (como la ficha).
        if (array_key_exists('automatica', $data) || ! $keys) {
            $data['automatica'] = (string) ($data['automatica'] ?? '');
        }

        // La ficha pasa a "por marca" al elegir una marca.
        if (! empty($data['marca_codigo']) && ($data['automatica'] ?? null) === '') {
            $data['automatica'] = 'M';
        }
        if (($data['automatica'] ?? null) === 'M' && ! $keys && empty($data['marca_codigo'])) {
            throw new BusinessRuleException('La opinión automática por marca requiere indicar la marca');
        }

        return $data;
    }
}
