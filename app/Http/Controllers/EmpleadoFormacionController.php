<?php

namespace App\Http\Controllers;

/**
 * Formación de un empleado (GRHFOR) que no viene de los cursos del plan de
 * formación: en la empresa (es_plan_empresa T) o fuera de ella (F).
 */
class EmpleadoFormacionController extends ChildController
{
    protected string $table = 'GRHFOR';

    protected array $keys = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'codigo'              => 'FOR1COD',
    ];

    protected array $mapping = [
        'empleado_delegacion'  => 'EMP3DEL',
        'empleado_codigo'      => 'EMP3COD',
        'codigo'               => 'FOR1COD',
        'descripcion'          => 'FORCDES',
        'observaciones'        => 'FORCOBS',
        'fecha_inicio'         => 'FORDINI',
        'fecha_fin'            => 'FORDFIN',
        'es_evidencia_adjunta' => 'FORBADJ',
        'es_plan_empresa'      => 'FORBEMP',
    ];

    protected array $searchFields = ['FORCDES'];

    protected string $parentGroup = 'empleado';
    protected array $parentEntity = ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'];
    protected string $auditField = 'GRHFOR';

    protected function fieldRules(): array
    {
        return [
            'descripcion'          => 'nullable|string|max:50',
            'observaciones'        => 'nullable|string|max:255',
            'fecha_inicio'         => 'nullable|date',
            'fecha_fin'            => 'nullable|date',
            'es_evidencia_adjunta' => 'nullable|string|in:T,F',
            'es_plan_empresa'      => 'nullable|string|in:T,F',
        ];
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);

        foreach (['fecha_inicio', 'fecha_fin'] as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = self::day($data[$param]);
            }
        }
        if (! $keys) {
            $data['es_evidencia_adjunta'] ??= 'F';
            $data['es_plan_empresa'] ??= 'F';
        }

        return $data;
    }
}
