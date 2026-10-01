<?php

namespace App\Http\Controllers;

/** Consumibles de una técnica (LABTYP) y cantidad consumida. */
class ParametroConsumibleController extends RelationController
{
    protected string $table = 'LABTYP';

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
        'cantidad'            => 'TYPNCON',
    ];

    protected array $entities = [
        'tecnica'  => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
        'producto' => ['ALMPRD', 'PRD1COD', 20, 'El producto no existe'],
    ];

    protected string $auditOwner = 'tecnica';
    protected string $auditField = 'LABTYP';

    protected function fieldRules(): array
    {
        return [
            'cantidad' => 'nullable|numeric',
        ];
    }
}
