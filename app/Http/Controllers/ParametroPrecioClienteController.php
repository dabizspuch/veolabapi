<?php

namespace App\Http\Controllers;

/** Precio de una técnica para un cliente (LABTYC). */
class ParametroPrecioClienteController extends PriceRelationController
{
    protected string $table = 'LABTYC';

    protected array $keys = [
        'tecnica_delegacion' => 'TEC3DEL',
        'tecnica_codigo'     => 'TEC3COD',
        'cliente_delegacion' => 'CLI3DEL',
        'cliente_codigo'     => 'CLI3COD',
    ];

    protected array $mapping = [
        'tecnica_delegacion' => 'TEC3DEL',
        'tecnica_codigo'     => 'TEC3COD',
        'cliente_delegacion' => 'CLI3DEL',
        'cliente_codigo'     => 'CLI3COD',
        'precio'             => 'TYCNPRE',
        'descuento'          => 'TYCCDTO',
        'referencia'         => 'TYCCREF',
    ];

    protected array $entities = [
        'tecnica' => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
        'cliente' => ['SINCLI', 'CLI1COD', 15, 'El cliente no existe'],
    ];

    protected string $auditOwner = 'tecnica';

    protected function fieldRules(): array
    {
        return parent::fieldRules() + [
            'referencia' => 'nullable|string|max:255',
        ];
    }
}
