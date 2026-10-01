<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;

/** Ausencias de un empleado (GRHAUS). */
class EmpleadoAusenciaController extends ChildController
{
    protected string $table = 'GRHAUS';

    protected array $keys = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'codigo'              => 'AUS1COD',
    ];

    protected array $mapping = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'codigo'              => 'AUS1COD',
        'fecha_inicio'        => 'AUSDINI',
        'fecha_fin'           => 'AUSDFIN',
        'descripcion'         => 'AUSCDES',
    ];

    protected array $searchFields = ['AUSCDES'];

    protected string $parentGroup = 'empleado';
    protected array $parentEntity = ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'];
    protected string $auditField = 'GRHAUS';

    protected function fieldRules(): array
    {
        return [
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date',
            'descripcion'  => 'nullable|string|max:50',
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
        if (! empty($data['fecha_inicio']) && ! empty($data['fecha_fin']) && $data['fecha_fin'] < $data['fecha_inicio']) {
            throw new BusinessRuleException('La fecha de fin no puede ser anterior a la de inicio');
        }

        return $data;
    }
}
