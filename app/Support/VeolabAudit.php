<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Auditoría de Veolab (ACCAUD), replicando SES_SucesoAuditoria:
 *  - Nivel en ACCPAR.PARNAUN: 0 desactivado, 1 accesos, 2 registro, 3 campo.
 *  - I (alta) y B (borrado) con nivel >= 2; F (modificación de fila) solo con
 *    nivel 2; C (modificación de campo, con valor nuevo/anterior) solo con 3.
 *  - V (facturación / Verifactu): con ACCPAR.PARBAUF o licencia Verifactu,
 *    sea cual sea el nivel. Van encadenados por hash (ACCHAS 'AUD').
 *  - Cada token de la API es una sesión de Veolab (ACCSES) con observaciones
 *    "API REST v2 (token N)", así que en Veolab se ve el origen del cambio.
 * Debe llamarse dentro de la transacción del cambio auditado.
 */
class VeolabAudit
{
    public const INSERCION = 'I';
    public const BORRADO = 'B';
    public const MODIFICACION_FILA = 'F';
    public const MODIFICACION_CAMPO = 'C';
    public const MODIFICACION = 'M'; // de fila y de campo a la vez
    public const VERIFACTU = 'V';

    private static array $levels = [];
    private static array $billing = [];
    private static array $sessions = [];

    /** Nivel de auditoría del laboratorio (ACCPAR.PARNAUN). */
    public static function level(): int
    {
        $db = DB::connection('dynamic')->getDatabaseName();

        return self::$levels[$db] ??= (int) DB::connection('dynamic')->table('ACCPAR')->value('PARNAUN');
    }

    /** ProcedeRegistroAuditoria. */
    public static function enabled(string $type): bool
    {
        return match ($type) {
            self::MODIFICACION_FILA  => self::level() === 2,
            self::MODIFICACION_CAMPO => self::level() === 3,
            self::VERIFACTU          => self::billingAudited(),
            default                  => self::level() >= 2,
        };
    }

    /** Registra un suceso si el nivel configurado lo requiere. */
    public static function record(string $type, string $table, string $row, string $field = '', ?string $new = '', ?string $old = ''): void
    {
        if (! self::enabled($type)) {
            return;
        }

        $values = [
            'AUDCTIP' => $type,
            'AUDCTAB' => mb_substr($table, 0, 6),
            'AUDCFIL' => mb_substr($row, 0, 50),
            'AUDCCAM' => mb_substr($field, 0, 13),
            'AUDCVAM' => mb_substr((string) $new, 0, 50),
            'AUDCVAA' => mb_substr((string) $old, 0, 50),
        ];

        DB::connection('dynamic')->table('ACCAUD')->insert($values + [
            'AUDTFEC' => DB::raw('NOW()'),
            'AUDCHAS' => $type === self::VERIFACTU ? self::chainHash($values) : '',
            'SES2COD' => self::session(),
            'DEL2COD' => '',
        ]);
    }

    /**
     * Suceso de facturación (SES_SUCESO_VERIFACTU): $message es la clave del
     * texto de Veolab ("$ESPVER003" = nuevo presupuesto...).
     */
    public static function verifactu(string $table, string $row, string $message, string $detail = ''): void
    {
        self::record(self::VERIFACTU, $table, $row, $message, $detail);
    }

    /**
     * ProcedeRegistroAuditoria, sucesos V: "auditar facturación" (PARBAUF) o
     * licencia Verifactu; sin licencia legible también (ver VeolabLicense).
     */
    private static function billingAudited(): bool
    {
        $db = DB::connection('dynamic')->getDatabaseName();

        return self::$billing[$db] ??= DB::connection('dynamic')->table('ACCPAR')->value('PARBAUF') === 'T'
            || VeolabLicense::isVerifactu('dynamic', $db);
    }

    /**
     * SES_GenerarHashAuditoria: SHA-256 (UTF-8, hexadecimal en minúsculas) de
     * "tipo|tabla|fila|campo|nuevo|anterior|hash anterior" con los valores ya
     * recortados; el último hash se guarda en ACCHAS 'AUD', que se bloquea
     * hasta el commit para que la cadena siga el orden de los registros.
     */
    private static function chainHash(array $values): string
    {
        $db = DB::connection('dynamic');
        $last = $db->table('ACCHAS')->where('HAS1COD', 'AUD')->lockForUpdate()->first();

        $hash = hash('sha256', implode('|', $values).'|'.mb_substr((string) ($last->HASCULT ?? ''), 0, 64));

        if ($last) {
            $db->table('ACCHAS')->where('HAS1COD', 'AUD')->update(['HASCULT' => $hash]);
        } else {
            $db->table('ACCHAS')->insert(['HAS1COD' => 'AUD', 'HASCULT' => $hash]);
        }

        return $hash;
    }

    /**
     * Valor auditado como lo escribe Veolab: las fechas de la BD (ISO) pasan a
     * formato de CStr(Date) de VB en español: "25/09/2026 9:00:00", o solo
     * "25/09/2026" si la hora es 00:00:00.
     */
    public static function value($value): string
    {
        $value = (string) ($value ?? '');

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?$/', $value, $m)) {
            $date = "{$m[3]}/{$m[2]}/{$m[1]}";
            if (isset($m[4]) && "{$m[4]}{$m[5]}{$m[6]}" !== '000000') {
                $date .= ' '.((int) $m[4]).":{$m[5]}:{$m[6]}";
            }

            return $date;
        }

        return $value;
    }

    /** Sesión de Veolab del token actual; se crea en ACCSES la primera vez. */
    private static function session(): int
    {
        $token = request()->user()?->currentAccessToken()?->id;
        $observations = 'API REST v2 (token '.($token ?? '-').')';
        $key = DB::connection('dynamic')->getDatabaseName().'.'.$observations;

        if (isset(self::$sessions[$key])) {
            return self::$sessions[$key];
        }

        $existing = DB::connection('dynamic')->table('ACCSES')
            ->where('DEL3COD', '')->where('SESCOBS', $observations)->value('SES1COD');

        if ($existing === null) {
            $existing = VeolabCodes::next('ACCSES', '', '');
            DB::connection('dynamic')->table('ACCSES')->insert([
                'DEL3COD' => '',
                'SES1COD' => $existing,
                'SESTINI' => DB::raw('NOW()'),
                'SESBERR' => 'F',
                'SESBWIN' => 'F',
                'SESBCON' => 'F',
                'SESCOBS' => $observations,
                'USU2COD' => '',
            ]);
        }

        return self::$sessions[$key] = (int) $existing;
    }
}
