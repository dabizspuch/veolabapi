<?php

namespace App\Http\Controllers;

/** Equipos de una técnica (LABTYQ), con el formato de importación de sus resultados. */
class ParametroEquipoController extends RelationController
{
    protected string $table = 'LABTYQ';

    protected array $keys = [
        'tecnica_delegacion'  => 'TEC3DEL',
        'tecnica_codigo'      => 'TEC3COD',
        'producto_delegacion' => 'PRD3DEL',
        'producto_codigo'     => 'PRD3COD',
    ];

    protected array $mapping = [
        'tecnica_delegacion'  => 'TEC3DEL',
        'tecnica_codigo'      => 'TEC3COD',
        'producto_delegacion' => 'PRD3DEL',
        'producto_codigo'     => 'PRD3COD',
        'formato_importacion' => 'TYQNFOR',
        'fichero'             => 'TYQCNOM',
        'columna'             => 'TYQCCOL',
    ];

    protected array $entities = [
        'tecnica'  => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
        'producto' => ['ALMPRD', 'PRD1COD', 20, 'El producto no existe'],
    ];

    protected string $auditOwner = 'tecnica';
    protected string $auditField = 'LABTYQ';

    protected function fieldRules(): array
    {
        return [
            'formato_importacion' => 'nullable|integer|min:0',
            'fichero'             => 'nullable|string|max:150',
            'columna'             => 'nullable|string|max:30',
        ];
    }
}
