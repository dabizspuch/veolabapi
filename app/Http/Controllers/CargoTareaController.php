<?php

namespace App\Http\Controllers;

/** Tareas de un cargo (GRHTAR). */
class CargoTareaController extends ChildController
{
    protected string $table = 'GRHTAR';

    protected array $keys = [
        'cargo_delegacion' => 'CAR3DEL',
        'cargo_codigo'     => 'CAR3COD',
        'codigo'           => 'TAR1COD',
    ];

    protected array $mapping = [
        'cargo_delegacion' => 'CAR3DEL',
        'cargo_codigo'     => 'CAR3COD',
        'codigo'           => 'TAR1COD',
        'descripcion'      => 'TARCDES',
    ];

    protected array $searchFields = ['TARCDES'];

    protected string $parentGroup = 'cargo';
    protected array $parentEntity = ['GRHCAR', 'CAR1COD', 'int', 'El cargo no existe'];
    protected string $auditField = 'GRHTAR';

    protected function fieldRules(): array
    {
        return [
            'descripcion' => 'nullable|string|max:255',
        ];
    }
}
