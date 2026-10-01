<?php

namespace App\Http\Controllers;

/** Productos que suministra un proveedor (ALMPYP), con su referencia y precio. */
class ProveedorProductoController extends RelationController
{
    protected string $table = 'ALMPYP';

    protected array $keys = [
        'proveedor_delegacion' => 'PRO3DEL',
        'proveedor_codigo'     => 'PRO3COD',
        'producto_delegacion'  => 'PRD3DEL',
        'producto_codigo'      => 'PRD3COD',
    ];

    protected array $mapping = [
        'proveedor_delegacion' => 'PRO3DEL',
        'proveedor_codigo'     => 'PRO3COD',
        'producto_delegacion'  => 'PRD3DEL',
        'producto_codigo'      => 'PRD3COD',
        'referencia'           => 'PYPCREF',
        'precio'               => 'PYPNPRE',
    ];

    protected array $entities = [
        'proveedor' => ['SINPRO', 'PRO1COD', 15, 'El proveedor no existe'],
        'producto'  => ['ALMPRD', 'PRD1COD', 15, 'El producto no existe'],
    ];

    protected string $auditOwner = 'proveedor';
    protected string $auditField = 'ALMPYP';

    protected function fieldRules(): array
    {
        return [
            'referencia' => 'nullable|string|max:30',
            'precio'     => 'nullable|numeric',
        ];
    }
}
