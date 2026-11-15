<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabVersion;

/**
 * Envases (LABENV, desde Veolab 3): valores predefinidos que se eligen como
 * envase en las operaciones y planificaciones, como los recolectores. La
 * conversión a 3.0 los crea a partir de los más usados en el último año. En
 * bases anteriores el recurso responde 422.
 */
class EnvaseController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABENV';
    protected ?string $sinceVersion = VeolabVersion::V3;
    protected ?string $auditDescription = 'ENVCVAL';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'ENV1COD',
    ];
    protected array $searchFields = ['ENVCVAL'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'ENV1COD',
        'nombre'     => 'ENVCVAL',
    ];

    protected function rules(): array
    {
        return [
            'delegacion' => 'nullable|string|max:10',
            'codigo'     => 'nullable|integer|min:1',
            'nombre'     => 'nullable|string|max:255',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }
}
