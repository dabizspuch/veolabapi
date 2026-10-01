<?php

namespace App\Http\Controllers;

/** Gastos (escandallo) de un servicio (LABSYE). */
class ServicioGastoController extends RelationController
{
    protected string $table = 'LABSYE';

    protected array $keys = [
        'servicio_delegacion' => 'DEL3SER',
        'servicio_codigo'     => 'SER3COD',
        'gasto_delegacion'    => 'DEL3ESC',
        'gasto_codigo'        => 'ESC3COD',
    ];

    protected array $mapping = [
        'servicio_delegacion' => 'DEL3SER',
        'servicio_codigo'     => 'SER3COD',
        'gasto_delegacion'    => 'DEL3ESC',
        'gasto_codigo'        => 'ESC3COD',
    ];

    protected array $entities = [
        'servicio' => ['LABSER', 'SER1COD', 20, 'El servicio no existe'],
        'gasto'    => ['LABESC', 'ESC1COD', 'int', 'El gasto no existe'],
    ];

    protected string $auditOwner = 'servicio';
    protected string $auditField = 'LABESC';
}
