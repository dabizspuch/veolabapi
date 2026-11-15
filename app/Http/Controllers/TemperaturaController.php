<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabVersion;

/**
 * Temperaturas (LABTEM, desde Veolab 3): valores predefinidos que se eligen
 * como temperatura en las operaciones y planificaciones, como los
 * recolectores. En bases anteriores el recurso responde 422.
 */
class TemperaturaController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABTEM';
    protected ?string $sinceVersion = VeolabVersion::V3;
    protected ?string $auditDescription = 'TEMCVAL';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TEM1COD',
    ];
    protected array $searchFields = ['TEMCVAL'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TEM1COD',
        'nombre'     => 'TEMCVAL',
    ];

    protected function rules(): array
    {
        return [
            'delegacion' => 'nullable|string|max:10',
            'codigo'     => 'nullable|integer|min:1',
            'nombre'     => 'nullable|string|max:50',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }
}
