<?php

namespace App\Http\Controllers;

/** Matrices de un tipo de operación (LABOYM). */
class TipoOperacionMatrizController extends RelationController
{
    protected string $table = 'LABOYM';

    protected array $keys = [
        'tipo_operacion_delegacion' => 'DEL3TIO',
        'tipo_operacion_codigo'     => 'TIO3COD',
        'matriz_delegacion'         => 'DEL3MAT',
        'matriz_codigo'             => 'MAT3COD',
    ];

    protected array $mapping = [
        'tipo_operacion_delegacion' => 'DEL3TIO',
        'tipo_operacion_codigo'     => 'TIO3COD',
        'matriz_delegacion'         => 'DEL3MAT',
        'matriz_codigo'             => 'MAT3COD',
    ];

    protected array $entities = [
        'tipo_operacion' => ['LABTIO', 'TIO1COD', 'int', 'El tipo de operación no existe'],
        'matriz'         => ['LABMAT', 'MAT1COD', 'int', 'La matriz no existe'],
    ];

    protected string $auditOwner = 'matriz';
    protected string $auditField = 'LABTIO';
}
