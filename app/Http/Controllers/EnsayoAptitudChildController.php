<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabProficiency;
use App\Support\VeolabQuality;
use App\Support\VeolabVersion;

/**
 * Rejillas de un ensayo de aptitud (CALEAO muestras, CALEAR resultados;
 * desde Veolab 3): clave = la ronda (ensayo_delegacion, ensayo_serie,
 * ensayo_codigo) + la de la línea.
 *
 *  - Necesitan el módulo CAL para escribir y la ronda sin cerrar: cerrada es
 *    de solo lectura (EstadoSegunRonda).
 *  - Auditoría como FichaEnsayoAptitud.Grabar: suceso de la ronda con la
 *    tabla modificada ($auditField).
 */
abstract class EnsayoAptitudChildController extends BaseController
{
    protected ?string $sinceVersion = VeolabVersion::V3;

    /** AUDCCAM del suceso de campo sobre la ronda. */
    protected string $auditField = '';

    /** Reglas de los datos de la línea (sin la clave de la ronda). */
    abstract protected function fieldRules(): array;

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'ensayo_delegacion' => 'nullable|string|max:10',
            'ensayo_serie'      => 'nullable|string|max:10',
            'ensayo_codigo'     => ($isCreating ? 'required' : 'sometimes').'|integer|min:1',
        ] + $this->fieldRules();
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
    }

    /** Clave de la ronda [delegación, serie, código] desde los datos o la clave de la línea. */
    protected static function roundKey(array $values): array
    {
        return [(string) ($values['ensayo_delegacion'] ?? ''), (string) ($values['ensayo_serie'] ?? ''),
            (int) ($values['ensayo_codigo'] ?? 0)];
    }

    /** Ronda bloqueada hasta el commit, que debe existir y no estar cerrada. */
    protected function lockRound(array $key): object
    {
        $round = VeolabProficiency::round($key[0], $key[1], $key[2], true);
        if (! $round) {
            throw new BusinessRuleException('El ensayo de aptitud no existe');
        }
        VeolabProficiency::assertEditable($round);

        return $round;
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (! $keys) {
            $data['ensayo_delegacion'] = (string) ($data['ensayo_delegacion'] ?? '');
            $data['ensayo_serie'] = (string) ($data['ensayo_serie'] ?? '');
        }
        $data['_ensayo'] = $this->lockRound(self::roundKey($keys ?: $data));

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
        $this->lockRound(self::roundKey($keys));
    }

    // ------------------------------------------------------------------
    // Auditoría: suceso de la ronda con la rejilla modificada
    // ------------------------------------------------------------------

    protected function auditCreated(array $data, array $keyParams): void
    {
        [$del, $ser, $cod] = self::roundKey($keyParams);
        VeolabProficiency::auditRound($del, $ser, $cod, $this->auditField);
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        [$del, $ser, $cod] = self::roundKey($keyParams);
        VeolabProficiency::auditRound($del, $ser, $cod, $this->auditField);
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        [$del, $ser, $cod] = self::roundKey($keyParams);
        VeolabProficiency::auditRound($del, $ser, $cod, $this->auditField);
    }
}
