<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksVeolabReferences;

/** Currículum de un empleado en la empresa (GRHCUR): cargo y departamento por periodo. */
class EmpleadoCurriculumController extends ChildController
{
    use ChecksVeolabReferences;

    protected string $table = 'GRHCUR';

    protected array $keys = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'codigo'              => 'CUR1COD',
    ];

    protected array $mapping = [
        'empleado_delegacion'     => 'EMP3DEL',
        'empleado_codigo'         => 'EMP3COD',
        'codigo'                  => 'CUR1COD',
        'fecha_inicio'            => 'CURDINI',
        'fecha_fin'               => 'CURDFIN',
        'cargo_delegacion'        => 'CAR2DEL',
        'cargo_codigo'            => 'CAR2COD',
        'departamento_delegacion' => 'DEP2DEL',
        'departamento_codigo'     => 'DEP2COD',
    ];

    protected array $foreignKeys = ['cargo' => 'int', 'departamento' => 'int'];

    protected string $parentGroup = 'empleado';
    protected array $parentEntity = ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'];
    protected string $auditField = 'GRHCUR';

    protected function fieldRules(): array
    {
        return [
            'fecha_inicio'            => 'nullable|date',
            'fecha_fin'               => 'nullable|date',
            'cargo_delegacion'        => 'nullable|string|max:10',
            'cargo_codigo'            => 'nullable|integer',
            'departamento_delegacion' => 'nullable|string|max:10',
            'departamento_codigo'     => 'nullable|integer',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        parent::validateRelationships($data);
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);

        foreach (['fecha_inicio', 'fecha_fin'] as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = self::day($data[$param]);
            }
        }

        return $data;
    }
}
