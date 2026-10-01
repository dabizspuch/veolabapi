<?php

namespace App\Http\Controllers;

/** Cargos de un empleado (GRHEYC), en el orden de la ficha. */
class EmpleadoCargoController extends RelationController
{
    protected string $table = 'GRHEYC';

    protected array $keys = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'cargo_delegacion'    => 'CAR3DEL',
        'cargo_codigo'        => 'CAR3COD',
    ];

    protected array $mapping = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'cargo_delegacion'    => 'CAR3DEL',
        'cargo_codigo'        => 'CAR3COD',
        'posicion'            => 'EYCNPOS',
    ];

    protected array $entities = [
        'empleado' => ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'],
        'cargo'    => ['GRHCAR', 'CAR1COD', 'int', 'El cargo no existe'],
    ];

    protected string $auditOwner = 'empleado';
    protected string $auditField = 'GRHEYC';
    protected ?string $positionKey = 'posicion';
}
