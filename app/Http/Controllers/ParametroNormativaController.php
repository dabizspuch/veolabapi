<?php

namespace App\Http\Controllers;

/** Valor y rango de una técnica en una normativa (LABTYN). */
class ParametroNormativaController extends RelationController
{
    protected string $table = 'LABTYN';

    protected array $keys = [
        'tecnica_delegacion'   => 'TEC3DEL',
        'tecnica_codigo'       => 'TEC3COD',
        'normativa_delegacion' => 'NOR3DEL',
        'normativa_codigo'     => 'NOR3COD',
    ];

    protected array $mapping = [
        'tecnica_delegacion'   => 'TEC3DEL',
        'tecnica_codigo'       => 'TEC3COD',
        'normativa_delegacion' => 'NOR3DEL',
        'normativa_codigo'     => 'NOR3COD',
        'valor'                => 'TYNCVAL',
        'rango'                => 'TYNCRAN',
    ];

    protected array $entities = [
        'tecnica'   => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
        'normativa' => ['LABNOR', 'NOR1COD', 20, 'La normativa no existe'],
    ];

    protected string $auditOwner = 'normativa';
    protected string $auditField = 'LABTYN';

    protected function fieldRules(): array
    {
        return [
            'valor' => 'nullable|string|max:100',
            'rango' => 'nullable|string|max:100',
        ];
    }
}
