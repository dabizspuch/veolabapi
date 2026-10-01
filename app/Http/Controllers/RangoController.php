<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use Illuminate\Support\Facades\DB;

/**
 * Rangos de resultados (LABRAN): intervalos con nombre que cada columna de
 * técnica concreta en LABCYR. Al borrarlos se borran sus intervalos.
 */
class RangoController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABRAN';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'RAN1COD',
    ];
    protected array $searchFields = ['RANCNOM'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'RAN1COD',
        'nombre'                        => 'RANCNOM',
        'sustituir_por_limite'          => 'RANBSUV',
        'sustituir_por_limite_y_valor'  => 'RANBSUX',
        'es_rango_normativa'            => 'RANBNOR',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'                   => 'nullable|string|max:10',
            'codigo'                       => 'nullable|integer|min:1',
            'nombre'                       => 'nullable|string|max:50',
            'sustituir_por_limite'         => 'nullable|string|in:T,F',
            'sustituir_por_limite_y_valor' => 'nullable|string|in:T,F',
            'es_rango_normativa'           => 'nullable|string|in:T,F',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (! $keys) {
            $data['sustituir_por_limite'] ??= 'F';
            $data['sustituir_por_limite_y_valor'] ??= 'F';
            $data['es_rango_normativa'] ??= 'F';
        }

        return $data;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('LABCYR')
            ->where('RAN3DEL', (string) $keys['delegacion'])->where('RAN3COD', $keys['codigo'])->delete();
    }
}
