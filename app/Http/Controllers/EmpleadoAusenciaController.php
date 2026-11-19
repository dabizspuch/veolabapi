<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/** Ausencias de un empleado (GRHAUS). */
class EmpleadoAusenciaController extends ChildController
{
    protected string $table = 'GRHAUS';

    protected array $keys = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'codigo'              => 'AUS1COD',
    ];

    /** Sustituto de la ausencia (Veolab 3): ve los clientes asociados del ausente mientras dura. */
    protected array $since = [
        'empleado_sustituto_delegacion' => VeolabVersion::V3,
        'empleado_sustituto_codigo'     => VeolabVersion::V3,
    ];

    protected array $foreignKeys = [
        'empleado_sustituto' => 'int',
    ];

    protected array $mapping = [
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
        'codigo'                        => 'AUS1COD',
        'fecha_inicio'                  => 'AUSDINI',
        'fecha_fin'                     => 'AUSDFIN',
        'descripcion'                   => 'AUSCDES',
        'empleado_sustituto_delegacion' => 'EMP2DEL',
        'empleado_sustituto_codigo'     => 'EMP2COD',
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
            'empleado_sustituto_delegacion' => 'nullable|string|max:10',
            'empleado_sustituto_codigo'     => 'nullable|integer',
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
        if (! empty($data['empleado_sustituto_codigo']) && isset($this->mapping['empleado_sustituto_codigo'])) {
            $delegation = (string) ($data['empleado_sustituto_delegacion'] ?? '');
            $code = (int) $data['empleado_sustituto_codigo'];
            if (! DB::connection('dynamic')->table('GRHEMP')->where('DEL3COD', $delegation)->where('EMP1COD', $code)->exists()) {
                throw new BusinessRuleException('El empleado sustituto no existe');
            }
            if (array_key_exists('empleado_codigo', $data) && $delegation === (string) ($data['empleado_delegacion'] ?? '')
                && $code === (int) $data['empleado_codigo']) {
                throw new BusinessRuleException('El empleado no puede ser su propio sustituto');
            }
        }

        return $data;
    }
}
