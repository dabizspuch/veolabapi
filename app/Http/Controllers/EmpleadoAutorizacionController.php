<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabAuthorizations;
use App\Support\VeolabCodes;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Autorizaciones del personal por técnica (GRHAUT, desde Veolab 3; ISO/IEC
 * 17025 6.2), como FichaAutorizacion y ucAutorizaciones: una por empleado y
 * técnica con su estado (F en formación, A autorizado, S suspendido), fecha
 * de autorización, caducidad opcional, quién autoriza y la última
 * supervisión de la competencia. LABTYE (/parametros/empleados) sigue
 * diciendo quién hace cada técnica; esto dice si puede hacerla.
 *
 * Lectura: además, `es_vigente` (T/F hoy) y `situacion` (texto). Auditoría
 * como Veolab: sucesos de GRHAUT con la fila "empleado - técnica".
 */
class EmpleadoAutorizacionController extends RelationController
{
    protected string $table = 'GRHAUT';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'tecnica_delegacion'  => 'TEC3DEL',
        'tecnica_codigo'      => 'TEC3COD',
    ];

    protected array $foreignKeys = [
        'autorizado_por' => 'int',
    ];

    protected array $mapping = [
        'empleado_delegacion'       => 'EMP3DEL',
        'empleado_codigo'           => 'EMP3COD',
        'tecnica_delegacion'        => 'TEC3DEL',
        'tecnica_codigo'            => 'TEC3COD',
        'estado'                    => 'AUTCEST',   // F en formación, A autorizado, S suspendido
        'fecha_autorizacion'        => 'AUTDAUT',
        'fecha_caducidad'           => 'AUTDCAD',   // vacía: no caduca
        'autorizado_por_delegacion' => 'EMA2DEL',
        'autorizado_por_codigo'     => 'EMA2COD',
        'fecha_supervision'         => 'AUTDSUP',
        'resultado_supervision'     => 'AUTCSUP',
    ];

    protected array $searchFields = ['AUTCSUP'];

    protected array $entities = [
        'empleado' => ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'],
        'tecnica'  => ['LABTEC', 'TEC1COD', 30, 'La técnica no existe'],
    ];

    protected string $auditOwner = 'empleado';
    protected string $auditField = 'GRHAUT';

    protected function fieldRules(): array
    {
        return [
            'estado'                    => 'nullable|string|in:F,A,S',
            'fecha_autorizacion'        => 'nullable|date',
            'fecha_caducidad'           => 'nullable|date',
            'autorizado_por_delegacion' => 'nullable|string|max:10',
            'autorizado_por_codigo'     => 'nullable|integer|min:0',
            'fecha_supervision'         => 'nullable|date',
            'resultado_supervision'     => 'nullable|string',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        parent::validateRelationships($data);

        if (! empty($data['autorizado_por_codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', (string) ($data['autorizado_por_delegacion'] ?? ''))
                ->where('EMP1COD', $data['autorizado_por_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El empleado que autoriza no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);

        foreach (['fecha_autorizacion', 'fecha_caducidad', 'fecha_supervision'] as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = empty($data[$param]) ? null : (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }
        if (array_key_exists('resultado_supervision', $data) && trim((string) $data['resultado_supervision']) === '') {
            $data['resultado_supervision'] = null;
        }

        if (! $keys) {
            // Como la ficha nueva: autorizado desde hoy.
            $data['estado'] ??= VeolabAuthorizations::AUTORIZADO;
            if (! array_key_exists('fecha_autorizacion', $data)) {
                $data['fecha_autorizacion'] = date('Y-m-d 00:00:00');
            }
        }

        // MEN00823: la caducidad no puede ser anterior a la autorización.
        $authorized = array_key_exists('fecha_autorizacion', $data) ? $data['fecha_autorizacion'] : ($keys ? $this->current($keys, 'AUTDAUT') : null);
        $expiry = array_key_exists('fecha_caducidad', $data) ? $data['fecha_caducidad'] : ($keys ? $this->current($keys, 'AUTDCAD') : null);
        if ($authorized && $expiry && substr((string) $expiry, 0, 10) < substr((string) $authorized, 0, 10)) {
            throw new BusinessRuleException('La fecha de caducidad no puede ser anterior a la de autorización');
        }

        VeolabAuthorizations::forget();

        return $data;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        VeolabAuthorizations::forget();
    }

    /** Situación hoy: es_vigente (T/F) y situacion («Autorizado - Caducada»...). */
    protected function appendRelatedData(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['es_vigente'] = VeolabAuthorizations::isValid($row['estado'], $row['fecha_caducidad']) ? 'T' : 'F';
            $row['situacion'] = VeolabAuthorizations::situationText($row['estado'], $row['fecha_caducidad']);
        }
        unset($row);

        return $rows;
    }

    private function current(array $keys, string $column)
    {
        return DB::connection('dynamic')->table($this->table)
            ->where('EMP3DEL', (string) ($keys['empleado_delegacion'] ?? ''))->where('EMP3COD', (int) ($keys['empleado_codigo'] ?? 0))
            ->where('TEC3DEL', (string) ($keys['tecnica_delegacion'] ?? ''))->where('TEC3COD', (string) ($keys['tecnica_codigo'] ?? ''))
            ->value($column);
    }

    // ------------------------------------------------------------------
    // Auditoría (FichaAutorizacion / ucAutorizaciones)
    // ------------------------------------------------------------------

    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        return VeolabCodes::format('GRHEMP', (string) ($keyParams['empleado_codigo'] ?? ''), (string) ($keyParams['empleado_delegacion'] ?? ''))
            .' - '.VeolabCodes::format('LABTEC', (string) ($keyParams['tecnica_codigo'] ?? ''), (string) ($keyParams['tecnica_delegacion'] ?? ''));
    }

    protected function auditCreated(array $data, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::INSERCION, $this->table, $this->auditRow($keyParams));
    }

    /** Como el escritorio: un suceso de modificación (M) del registro. */
    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::MODIFICACION, $this->table, $this->auditRow($keyParams));
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::BORRADO, $this->table, $this->auditRow($keyParams));
    }
}
