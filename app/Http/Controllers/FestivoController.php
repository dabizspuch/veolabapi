<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;

/**
 * Calendario de festivos (AGEFES): los días que se saltan al calcular
 * fechas laborables (p. ej. la fecha prevista de las operaciones).
 */
class FestivoController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'AGEFES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'FES1COD',
    ];
    protected array $searchFields = ['FESCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'FES1COD',
        'fecha'       => 'FESTFEC',
        'descripcion' => 'FESCDES',
    ];

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer|min:1',
            'fecha'       => ($isCreating ? 'required' : 'sometimes').'|date',
            'descripcion' => 'nullable|string|max:50',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (! empty($data['fecha'])) {
            $data['fecha'] = (new \DateTime($data['fecha']))->format('Y-m-d 00:00:00');
        }

        return $data;
    }
}
