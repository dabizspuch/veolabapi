<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabVersion;

/**
 * Lugares de recogida (LABLUG, desde Veolab 3): valores predefinidos que se
 * eligen como lugar en las operaciones y planificaciones, como los
 * recolectores. En bases anteriores el recurso responde 422.
 */
class LugarController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABLUG';
    protected ?string $sinceVersion = VeolabVersion::V3;
    protected ?string $auditDescription = 'LUGCVAL';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'LUG1COD',
    ];
    protected array $searchFields = ['LUGCVAL'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'LUG1COD',
        'nombre'     => 'LUGCVAL',
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
