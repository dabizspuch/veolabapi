<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabQuality;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Rejillas de una incidencia de calidad (CALACC, CALIYE, CALIEM; desde
 * Veolab 3): clave = la incidencia (incidencia_delegacion,
 * incidencia_serie, incidencia_codigo) + la de la línea.
 *
 *  - Necesitan el módulo CAL para escribir y la incidencia abierta (en
 *    $editableStates): cerrada o anulada es de solo lectura.
 *  - Auditoría como FichaIncidencia.Grabar: suceso de la incidencia con la
 *    tabla modificada ($auditField).
 */
abstract class IncidenciaChildController extends BaseController
{
    protected ?string $sinceVersion = VeolabVersion::V3;

    /** AUDCCAM del suceso de campo sobre la incidencia. */
    protected string $auditField = '';

    /** Estados de la incidencia en los que se puede cambiar la rejilla. */
    protected array $editableStates = ['R', 'T', 'V'];

    /** Reglas de los datos de la línea (sin la clave de la incidencia). */
    abstract protected function fieldRules(): array;

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'incidencia_delegacion' => 'nullable|string|max:10',
            'incidencia_serie'      => 'nullable|string|max:10',
            'incidencia_codigo'     => ($isCreating ? 'required' : 'sometimes').'|integer|min:1',
        ] + $this->fieldRules();
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
    }

    /** Clave de la incidencia [delegación, serie, código] desde los datos o la clave de la línea. */
    protected static function incidentKey(array $values): array
    {
        return [(string) ($values['incidencia_delegacion'] ?? ''), (string) ($values['incidencia_serie'] ?? ''),
            (int) ($values['incidencia_codigo'] ?? 0)];
    }

    /** Incidencia bloqueada hasta el commit, que debe existir y admitir cambios en la rejilla. */
    protected function lockIncident(array $key): object
    {
        $incident = VeolabQuality::incident($key[0], $key[1], $key[2], true);
        if (! $incident) {
            throw new BusinessRuleException('La incidencia no existe');
        }
        VeolabQuality::assertOpen($incident);
        if (! in_array(VeolabQuality::state($incident), $this->editableStates, true)) {
            throw new BusinessRuleException($this->stateMessage());
        }

        return $incident;
    }

    protected function stateMessage(): string
    {
        return 'La incidencia no admite cambios en este estado';
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (! $keys) {
            $data['incidencia_delegacion'] = (string) ($data['incidencia_delegacion'] ?? '');
            $data['incidencia_serie'] = (string) ($data['incidencia_serie'] ?? '');
        }
        $data['_incidencia'] = $this->lockIncident(self::incidentKey($keys ?: $data));

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
        $this->lockIncident(self::incidentKey($keys));
    }

    /** Siguiente número de línea de la incidencia (la ficha numera las líneas de cada incidencia). */
    protected function nextLine(array $key, string $column): int
    {
        return (int) DB::connection('dynamic')->table($this->table)
            ->where('DEL3COD', $key[0])->where('INC3SER', $key[1])->where('INC3COD', $key[2])
            ->max($column) + 1;
    }

    /** Empleado [delegación, código] que existe y, si se asigna de nuevo, está de alta (la ficha solo ofrece esos). */
    protected static function checkEmployee(string $del, int $cod, bool $changed, string $what): void
    {
        $employee = DB::connection('dynamic')->table('GRHEMP')->where('DEL3COD', $del)->where('EMP1COD', $cod)->first(['EMPBBAJ']);
        if (! $employee) {
            throw new BusinessRuleException("El empleado {$what} no existe");
        }
        if ($changed && $employee->EMPBBAJ === 'T') {
            throw new BusinessRuleException("El empleado {$what} está de baja");
        }
    }

    // ------------------------------------------------------------------
    // Auditoría: suceso de la incidencia con la rejilla modificada
    // ------------------------------------------------------------------

    protected function auditCreated(array $data, array $keyParams): void
    {
        [$del, $ser, $cod] = self::incidentKey($keyParams);
        VeolabQuality::auditIncident($del, $ser, $cod, $this->auditField);
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        [$del, $ser, $cod] = self::incidentKey($keyParams);
        VeolabQuality::auditIncident($del, $ser, $cod, $this->auditField);
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        [$del, $ser, $cod] = self::incidentKey($keyParams);
        VeolabQuality::auditIncident($del, $ser, $cod, $this->auditField);
    }
}
