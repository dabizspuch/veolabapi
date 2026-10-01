<?php

namespace App\Http\Controllers;

/** Clientes asociados a un empleado (GRHCLI). */
class EmpleadoClienteController extends RelationController
{
    protected string $table = 'GRHCLI';

    protected array $keys = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'cliente_delegacion'  => 'CLI3DEL',
        'cliente_codigo'      => 'CLI3COD',
    ];

    protected array $mapping = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'cliente_delegacion'  => 'CLI3DEL',
        'cliente_codigo'      => 'CLI3COD',
    ];

    protected array $entities = [
        'empleado' => ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'],
        'cliente'  => ['SINCLI', 'CLI1COD', 15, 'El cliente no existe'],
    ];

    protected string $auditOwner = 'empleado';
    protected string $auditField = 'GRHCLI';
}
