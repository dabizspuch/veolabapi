<?php

namespace App\Http\Controllers;

/** Técnicas de un servicio (LABSYT), en el orden de la ficha del servicio. */
class ServicioTecnicaController extends RelationController
{
    protected string $table = 'LABSYT';

    protected array $keys = [
        'servicio_delegacion' => 'DEL3SER',
        'servicio_codigo'     => 'SER3COD',
        'tecnica_delegacion'  => 'DEL3TEC',
        'tecnica_codigo'      => 'TEC3COD',
    ];

    protected array $mapping = [
        'servicio_delegacion' => 'DEL3SER',
        'servicio_codigo'     => 'SER3COD',
        'tecnica_delegacion'  => 'DEL3TEC',
        'tecnica_codigo'      => 'TEC3COD',
        'posicion'            => 'SYTNORD',
    ];

    protected array $entities = [
        'servicio' => ['LABSER', 'SER1COD', 20, 'El servicio no existe'],
        'tecnica'  => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
    ];

    protected string $auditOwner = 'servicio';
    protected string $auditField = 'LABTEC';
    protected ?string $positionKey = 'posicion';
    protected int $firstPosition = 2;
}
