<?php

namespace App\Http\Controllers;

/** Precio de un servicio para un cliente (LABSYC). */
class ServicioPrecioClienteController extends PriceRelationController
{
    protected string $table = 'LABSYC';

    protected array $keys = [
        'servicio_delegacion' => 'SER3DEL',
        'servicio_codigo'     => 'SER3COD',
        'cliente_delegacion'  => 'CLI3DEL',
        'cliente_codigo'      => 'CLI3COD',
    ];

    protected array $mapping = [
        'servicio_delegacion' => 'SER3DEL',
        'servicio_codigo'     => 'SER3COD',
        'cliente_delegacion'  => 'CLI3DEL',
        'cliente_codigo'      => 'CLI3COD',
        'precio'              => 'SYCNPRE',
        'descuento'           => 'SYCCDTO',
        'referencia'          => 'SYCCREF',
    ];

    protected array $entities = [
        'servicio' => ['LABSER', 'SER1COD', 20, 'El servicio no existe'],
        'cliente'  => ['SINCLI', 'CLI1COD', 15, 'El cliente no existe'],
    ];

    protected string $auditOwner = 'servicio';

    protected function fieldRules(): array
    {
        return parent::fieldRules() + [
            'referencia' => 'nullable|string|max:50',
        ];
    }
}
