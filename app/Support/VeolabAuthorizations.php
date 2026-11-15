<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones del personal por técnica (GRHAUT, desde Veolab 3; ISO/IEC
 * 17025 6.2), como Autorizaciones.bas (AUP_*). LABTYE dice quién hace cada
 * técnica y en qué orden (1 = titular); GRHAUT dice si puede hacerla: estado
 * F en formación, A autorizado, S suspendido, y caducidad opcional.
 *
 * Sin autorización registrada no cuenta como no autorizado: los laboratorios
 * que no registran autorizaciones no notan nada. En bases anteriores a
 * Veolab 3 (o sin la tabla) nadie está "no autorizado".
 */
class VeolabAuthorizations
{
    public const FORMACION = 'F';
    public const AUTORIZADO = 'A';
    public const SUSPENDIDO = 'S';

    /** LABCON.CONCAUT: N no comprobar (bases convertidas), A avisar, B bloquear. */
    public const NO_COMPROBAR = 'N';
    public const AVISAR = 'A';
    public const BLOQUEAR = 'B';

    /** ¿La base de datos tiene GRHAUT? (por base de datos) */
    private static array $available = [];

    /** Autorizaciones registradas leídas en la petición: clave => [estado, caducidad] | null. */
    private static array $registered = [];

    /** ¿Hay autorizaciones en esta base de datos? */
    public static function available(): bool
    {
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$available)) {
            try {
                self::$available[$database] = VeolabVersion::isV3()
                    && Schema::connection('dynamic')->hasTable('GRHAUT');
            } catch (\Throwable) {
                self::$available[$database] = false;
            }
        }

        return self::$available[$database];
    }

    /**
     * AUP_Vigente: autorizado y sin caducar en la fecha (hoy si no se indica;
     * la que está a punto de caducar sigue vigente).
     */
    public static function isValid(?string $state, $expiry, ?string $date = null): bool
    {
        if ((string) $state !== self::AUTORIZADO) {
            return false;
        }
        if ($expiry === null || $expiry === '') {
            return true;
        }
        $day = substr((string) ($date ?? date('Y-m-d')), 0, 10);

        return substr((string) $expiry, 0, 10) >= $day;
    }

    /** Días antes de la caducidad en los que ya se avisa (AUP_DIAS_AVISO). */
    public const NOTICE_DAYS = 30;

    /** AUP_TextoSituacion: «Autorizado - Caducada», «Autorizado - Caduca en menos de 30 días», «En formación», «Suspendido». */
    public static function situationText(?string $state, $expiry, ?string $date = null): string
    {
        $text = match ((string) $state) {
            self::AUTORIZADO => 'Autorizado',
            self::SUSPENDIDO => 'Suspendido',
            self::FORMACION  => 'En formación',
            default          => 'Sin estado',
        };
        if ((string) $state === self::AUTORIZADO && ! self::isValid($state, $expiry, $date)) {
            $text .= ' - Caducada';
        } elseif ((string) $state === self::AUTORIZADO && $expiry !== null && $expiry !== '') {
            // AUP_Situacion: caduca antes de AUP_DIAS_AVISO días (situación P, ámbar)
            $day = substr((string) ($date ?? date('Y-m-d')), 0, 10);
            $limit = (new \DateTime($day))->modify('+'.self::NOTICE_DAYS.' days')->format('Y-m-d');
            if (substr((string) $expiry, 0, 10) < $limit) {
                $text .= ' - Caduca en menos de '.self::NOTICE_DAYS.' días';
            }
        }

        return $text;
    }

    /**
     * AUP_NoAutorizado: ¿tiene el empleado una autorización registrada para la
     * técnica que no está vigente en la fecha? Sin autorización registrada: no.
     */
    public static function notAuthorized(string $empDel, int $empCod, string $tecDel, string $tecCod, ?string $date = null): bool
    {
        if ($empCod === 0 || ! self::available()) {
            return false;
        }
        $key = implode("\x1B", [(string) DB::connection('dynamic')->getDatabaseName(), $empDel, $empCod, $tecDel, $tecCod]);
        if (! array_key_exists($key, self::$registered)) {
            $row = DB::connection('dynamic')->table('GRHAUT')
                ->where('EMP3DEL', $empDel)->where('EMP3COD', $empCod)
                ->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)
                ->first(['AUTCEST', 'AUTDCAD']);
            self::$registered[$key] = $row ? [(string) $row->AUTCEST, $row->AUTDCAD] : null;
        }
        $registered = self::$registered[$key];

        return $registered !== null && ! self::isValid($registered[0], $registered[1], $date);
    }

    /**
     * AUP_ElegirAnalista: si el titular de la técnica tiene autorización
     * registrada y no vigente, el primer empleado de LABTYE (por TYENPOS) que
     * no la tenga así; si no hay ninguno, se queda el titular (el aviso o el
     * bloqueo al grabar resultados lo dirá). Devuelve [delegación, código].
     */
    public static function chooseAnalyst(string $tecDel, string $tecCod, string $empDel, int $empCod): array
    {
        if (! self::notAuthorized($empDel, $empCod, $tecDel, $tecCod)) {
            return [$empDel, $empCod];
        }

        $candidates = DB::connection('dynamic')->table('LABTYE')
            ->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)
            ->orderBy('TYENPOS')
            ->get(['EMP3DEL', 'EMP3COD']);
        foreach ($candidates as $candidate) {
            $cod = (int) $candidate->EMP3COD;
            if ($cod !== 0 && ! self::notAuthorized((string) $candidate->EMP3DEL, $cod, $tecDel, $tecCod)) {
                return [(string) $candidate->EMP3DEL, $cod];
            }
        }

        return [$empDel, $empCod];
    }

    /** Olvida las autorizaciones leídas (tras grabar alguna, como AUP_OlvidarAutorizaciones). */
    public static function forget(): void
    {
        self::$registered = [];
    }
}
