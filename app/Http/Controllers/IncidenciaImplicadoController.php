<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Empleados implicados en una incidencia de calidad (CALIEM, desde Veolab 3):
 * reciben los avisos de sus pasos además del responsable y del verificador.
 * Clave: la incidencia + empleado_delegacion + empleado_codigo. Solo alta y
 * baja, con la incidencia abierta; la ficha solo ofrece empleados de alta.
 * La lectura añade empleado_nombre.
 */
class IncidenciaImplicadoController extends IncidenciaChildController
{
    protected string $table = 'CALIEM';
    protected string $auditField = 'CALIEM';

    protected array $keys = [
        'incidencia_delegacion' => 'DEL3COD',
        'incidencia_serie'      => 'INC3SER',
        'incidencia_codigo'     => 'INC3COD',
        'empleado_delegacion'   => 'EMP3DEL',
        'empleado_codigo'       => 'EMP3COD',
    ];

    protected array $mapping = [
        'incidencia_delegacion' => 'DEL3COD',
        'incidencia_serie'      => 'INC3SER',
        'incidencia_codigo'     => 'INC3COD',
        'empleado_delegacion'   => 'EMP3DEL',
        'empleado_codigo'       => 'EMP3COD',
    ];

    protected function fieldRules(): array
    {
        return [
            'empleado_delegacion' => 'nullable|string|max:10',
            'empleado_codigo'     => (request()->isMethod('post') ? 'required' : 'sometimes').'|integer|min:1',
        ];
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            throw new BusinessRuleException('Un implicado no se modifica: se quita y se añade otro');
        }
        $data = parent::validateAdditionalCriteria($data, $keys);
        $data['empleado_delegacion'] = (string) ($data['empleado_delegacion'] ?? '');
        self::checkEmployee($data['empleado_delegacion'], (int) $data['empleado_codigo'], true, 'implicado');

        return $data;
    }

    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        foreach ($rows as &$row) {
            $row['empleado_nombre'] = (string) $db->table('GRHEMP')->where('DEL3COD', (string) $row['empleado_delegacion'])
                ->where('EMP1COD', (int) $row['empleado_codigo'])->value('EMPCNOM');
        }
        unset($row);

        return $rows;
    }
}
