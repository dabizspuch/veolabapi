<?php

namespace App\Http\Controllers;

/** Personal cualificado de una técnica (LABTYE), en el orden de la ficha. */
class ParametroEmpleadoController extends RelationController
{
    protected string $table = 'LABTYE';

    protected array $keys = [
        'tecnica_delegacion'  => 'TEC3DEL',
        'tecnica_codigo'      => 'TEC3COD',
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
    ];

    protected array $mapping = [
        'tecnica_delegacion'  => 'TEC3DEL',
        'tecnica_codigo'      => 'TEC3COD',
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'posicion'            => 'TYENPOS',
    ];

    protected array $entities = [
        'tecnica'  => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
        'empleado' => ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'],
    ];

    protected string $auditOwner = 'tecnica';
    protected string $auditField = 'LABTYE';
    protected ?string $positionKey = 'posicion';
}
