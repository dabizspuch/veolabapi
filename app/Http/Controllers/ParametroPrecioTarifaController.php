<?php

namespace App\Http\Controllers;

/** Precio de una técnica en una tarifa (LABTYF). */
class ParametroPrecioTarifaController extends PriceRelationController
{
    protected string $table = 'LABTYF';

    protected array $keys = [
        'tecnica_delegacion' => 'TEC3DEL',
        'tecnica_codigo'     => 'TEC3COD',
        'tarifa_delegacion'  => 'TAR3DEL',
        'tarifa_codigo'      => 'TAR3COD',
    ];

    protected array $mapping = [
        'tecnica_delegacion' => 'TEC3DEL',
        'tecnica_codigo'     => 'TEC3COD',
        'tarifa_delegacion'  => 'TAR3DEL',
        'tarifa_codigo'      => 'TAR3COD',
        'precio'             => 'TYFNPRE',
        'descuento'          => 'TYFCDTO',
    ];

    protected array $entities = [
        'tecnica' => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
        'tarifa'  => ['LABTAR', 'TAR1COD', 'int', 'La tarifa no existe'],
    ];

    protected string $auditOwner = 'tecnica';
}
