<?php

namespace App\Http\Controllers;

/** Precio de un servicio en una tarifa (LABSYF). */
class ServicioPrecioTarifaController extends PriceRelationController
{
    protected string $table = 'LABSYF';

    protected array $keys = [
        'servicio_delegacion' => 'SER3DEL',
        'servicio_codigo'     => 'SER3COD',
        'tarifa_delegacion'   => 'TAR3DEL',
        'tarifa_codigo'       => 'TAR3COD',
    ];

    protected array $mapping = [
        'servicio_delegacion' => 'SER3DEL',
        'servicio_codigo'     => 'SER3COD',
        'tarifa_delegacion'   => 'TAR3DEL',
        'tarifa_codigo'       => 'TAR3COD',
        'precio'              => 'SYFNPRE',
        'descuento'           => 'SYFCDTO',
    ];

    protected array $entities = [
        'servicio' => ['LABSER', 'SER1COD', 20, 'El servicio no existe'],
        'tarifa'   => ['LABTAR', 'TAR1COD', 'int', 'La tarifa no existe'],
    ];

    protected string $auditOwner = 'servicio';
}
