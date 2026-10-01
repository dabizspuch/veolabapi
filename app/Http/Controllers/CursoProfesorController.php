<?php

namespace App\Http\Controllers;

/** Profesores de un curso del plan de formación (GRHPRO). */
class CursoProfesorController extends RelationController
{
    protected string $table = 'GRHPRO';

    protected array $keys = [
        'curso_delegacion'    => 'PAF3DEL',
        'curso_codigo'        => 'PAF3COD',
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
    ];

    protected array $mapping = [
        'curso_delegacion'    => 'PAF3DEL',
        'curso_codigo'        => 'PAF3COD',
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
    ];

    protected array $entities = [
        'curso'    => ['GRHPAF', 'PAF1COD', 15, 'El curso no existe'],
        'empleado' => ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'],
    ];

    protected string $auditOwner = 'curso';
    protected string $auditField = 'GRHPRO';
}
