<?php

namespace App\Http\Controllers;

/** Matrices de una técnica (LABTYM). */
class ParametroMatrizController extends RelationController
{
    protected string $table = 'LABTYM';

    protected array $keys = [
        'tecnica_delegacion' => 'DEL3TEC',
        'tecnica_codigo'     => 'TEC3COD',
        'matriz_delegacion'  => 'DEL3MAT',
        'matriz_codigo'      => 'MAT3COD',
    ];

    protected array $mapping = [
        'tecnica_delegacion' => 'DEL3TEC',
        'tecnica_codigo'     => 'TEC3COD',
        'matriz_delegacion'  => 'DEL3MAT',
        'matriz_codigo'      => 'MAT3COD',
    ];

    protected array $entities = [
        'tecnica' => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
        'matriz'  => ['LABMAT', 'MAT1COD', 'int', 'La matriz no existe'],
    ];

    protected string $auditOwner = 'tecnica';
    protected string $auditField = 'LABTYM';
}
