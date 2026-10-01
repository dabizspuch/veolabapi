<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;

/** Descripciones predefinidas (LABDES): textos que se eligen al describir operaciones. */
class DescripcionController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'DES1COD',
    ];
    protected array $searchFields = ['DESCVAL'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'DES1COD',
        'texto'      => 'DESCVAL',
    ];

    protected function rules(): array
    {
        return [
            'delegacion' => 'nullable|string|max:10',
            'codigo'     => 'nullable|integer|min:1',
            'texto'      => 'nullable|string',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }
}
