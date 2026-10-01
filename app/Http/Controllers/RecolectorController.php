<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;

/** Recolectores (LABREC): nombres que se eligen como recolector de las operaciones. */
class RecolectorController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABREC';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'REC1COD',
    ];
    protected array $searchFields = ['RECCVAL'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'REC1COD',
        'nombre'     => 'RECCVAL',
    ];

    protected function rules(): array
    {
        return [
            'delegacion' => 'nullable|string|max:10',
            'codigo'     => 'nullable|integer|min:1',
            'nombre'     => 'nullable|string|max:100',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }
}
