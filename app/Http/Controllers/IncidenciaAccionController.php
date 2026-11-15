<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabQuality;
use Illuminate\Support\Facades\DB;

/**
 * Acciones de una incidencia de calidad (CALACC, desde Veolab 3), como la
 * pestaña Acciones de FichaIncidencia: tipo (C corrección, A acción
 * correctiva, M mejora o tratamiento del riesgo), qué se hace, responsable,
 * fechas prevista y real, resultado y verificación de la eficacia.
 *
 *  - Solo con la incidencia en tratamiento o en verificación (la ficha no
 *    deja tocarlas en otro estado).
 *  - Nueva: número siguiente de la incidencia; tipo correctiva (en riesgos y
 *    mejoras, de mejora) y, como responsable, el de la incidencia.
 *  - Tras grabar se rehacen los avisos K de la incidencia (fecha prevista de
 *    las acciones pendientes), fuera de la transacción.
 */
class IncidenciaAccionController extends IncidenciaChildController
{
    protected string $table = 'CALACC';
    protected string $auditField = 'CALACC';
    protected array $editableStates = ['T', 'V'];

    protected array $keys = [
        'incidencia_delegacion' => 'DEL3COD',
        'incidencia_serie'      => 'INC3SER',
        'incidencia_codigo'     => 'INC3COD',
        'codigo'                => 'ACC1COD',
    ];

    // Sin $foreignKeys: Veolab guarda NULL en las referencias vacías.
    protected array $mapping = [
        'incidencia_delegacion'  => 'DEL3COD',
        'incidencia_serie'       => 'INC3SER',
        'incidencia_codigo'      => 'INC3COD',
        'codigo'                 => 'ACC1COD',
        'tipo'                   => 'ACCCTIP',   // C corrección, A acción correctiva, M mejora o tratamiento del riesgo
        'descripcion'            => 'ACCCDES',
        'responsable_delegacion' => 'EMP2DEL',
        'responsable_codigo'     => 'EMP2COD',
        'fecha_prevista'         => 'ACCDPRE',
        'fecha_real'             => 'ACCDREA',
        'resultado'              => 'ACCCRES',
        'eficaz'                 => 'ACCBEFI',   // T, F o vacío (sin verificar)
        'fecha_eficacia'         => 'ACCDEFI',
        'verificador_delegacion' => 'EMV2DEL',
        'verificador_codigo'     => 'EMV2COD',
    ];

    protected array $searchFields = ['ACCCDES', 'ACCCRES'];

    private const EMPLOYEES = [
        'responsable' => ['EMP2DEL', 'EMP2COD', 'responsable de la acción'],
        'verificador' => ['EMV2DEL', 'EMV2COD', 'que verifica la acción'],
    ];

    protected function fieldRules(): array
    {
        return [
            'codigo'                 => 'nullable|integer|min:1',
            'tipo'                   => 'nullable|string|in:'.implode(',', VeolabQuality::ACTION_TYPES),
            'descripcion'            => 'nullable|string',
            'responsable_delegacion' => 'nullable|string|max:10',
            'responsable_codigo'     => 'nullable|integer|min:0',
            'fecha_prevista'         => 'nullable|date',
            'fecha_real'             => 'nullable|date',
            'resultado'              => 'nullable|string',
            'eficaz'                 => 'nullable|string|in:T,F',
            'fecha_eficacia'         => 'nullable|date',
            'verificador_delegacion' => 'nullable|string|max:10',
            'verificador_codigo'     => 'nullable|integer|min:0',
        ];
    }

    protected function stateMessage(): string
    {
        return 'Las acciones se registran con la incidencia en tratamiento o en verificación';
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);
        $incident = $data['_incidencia'];
        $isNew = ! $keys;
        $before = $isNew ? null : (array) DB::connection('dynamic')->table('CALACC')
            ->where('DEL3COD', (string) $keys['incidencia_delegacion'])->where('INC3SER', (string) $keys['incidencia_serie'])
            ->where('INC3COD', (int) $keys['incidencia_codigo'])->where('ACC1COD', (int) $keys['codigo'])->first();

        if ($isNew) {
            $key = self::incidentKey($data);
            if (empty($data['codigo'])) {
                $data['codigo'] = $this->nextLine($key, 'ACC1COD');
            }
            // AnadirAccion: correctiva (en riesgos y mejoras, de mejora) a cargo del responsable de la incidencia
            if (empty($data['tipo'])) {
                $data['tipo'] = in_array((string) $incident->INCCCLA, ['R', 'M'], true) ? 'M' : 'A';
            }
            if (! array_key_exists('responsable_codigo', $data) && (int) $incident->EMR2COD !== 0) {
                $data['responsable_delegacion'] = (string) $incident->EMR2DEL;
                $data['responsable_codigo'] = (int) $incident->EMR2COD;
            }
        } elseif (array_key_exists('tipo', $data) && empty($data['tipo'])) {
            throw new BusinessRuleException('El tipo de la acción es obligatorio');
        }

        foreach (self::EMPLOYEES as $group => [$delColumn, $codColumn, $what]) {
            if (! array_key_exists("{$group}_codigo", $data) && ! array_key_exists("{$group}_delegacion", $data)) {
                continue;
            }
            $empDel = (string) ($data["{$group}_delegacion"] ?? ($before[$delColumn] ?? ''));
            $empCod = (int) (array_key_exists("{$group}_codigo", $data) ? $data["{$group}_codigo"] : ($before[$codColumn] ?? 0));
            if ($empCod === 0) {
                $data["{$group}_delegacion"] = null;
                $data["{$group}_codigo"] = null;
                continue;
            }
            $changed = $isNew || (string) ($before[$delColumn] ?? '') !== $empDel || (int) ($before[$codColumn] ?? 0) !== $empCod;
            self::checkEmployee($empDel, $empCod, $changed, $what);
            $data["{$group}_delegacion"] = $empDel;
            $data["{$group}_codigo"] = $empCod;
        }

        // Textos y «eficaz» vacíos como NULL (TextoSQLONulo); fechas, solo el día
        foreach (['descripcion', 'resultado', 'eficaz'] as $param) {
            if (array_key_exists($param, $data)) {
                $value = trim((string) ($data[$param] ?? ''));
                $data[$param] = $value === '' ? null : $value;
            }
        }
        foreach (['fecha_prevista', 'fecha_real', 'fecha_eficacia'] as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = self::day($data[$param]);
            }
        }

        return $data;
    }

    private static function day($value): ?string
    {
        return empty($value) ? null : (new \DateTime((string) $value))->format('Y-m-d 00:00:00');
    }

    /** Tras crear o cambiar una acción: avisos K de la incidencia. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $this->notify($keys);

        return $data;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $this->notify($keys);
    }

    private function notify(array $keys): void
    {
        [$del, $ser, $cod] = self::incidentKey($keys);
        VeolabQuality::afterCommit(fn () => VeolabQuality::notifyActions($del, $ser, $cod));
    }
}
