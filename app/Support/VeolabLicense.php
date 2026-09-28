<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lectura del tipo de licencia de un laboratorio, replicando Veolab
 * (Licencias.bas / Encriptacion.bas). Se trabaja byte a byte: los patrones
 * están en Windows-1252 (incluyen ñ/Ñ), como en VB6.
 *
 * La licencia (ACCPAR.PARCLBD) se descifra con el "número único" de la BD,
 * que se calcula del nombre de la BD y la identidad del servidor MySQL.
 * Sin los patrones (config veolab.license) no se puede leer: type() = null.
 */
class VeolabLicense
{
    public const GRATUITA = 1;
    public const PROFESIONAL = 2;
    public const EMPRESARIAL = 3;
    public const EMPRESARIAL_VERIFACTU = 4;

    /** Caché por BD durante la petición. */
    private static array $types = [];

    /** ¿Están configurados los patrones de cifrado? */
    public static function configured(): bool
    {
        return self::patterns() !== null;
    }

    /**
     * ¿Aplican las restricciones de Verifactu? Solo NO aplican si la licencia
     * se ha leído y es de un tipo distinto a Empresarial*. Cualquier fallo
     * (sin patrones, sin licencia, licencia no válida) => sí aplican.
     */
    public static function isVerifactu(string $connection, string $database): bool
    {
        $type = self::type($connection, $database);

        return $type === null || $type === self::EMPRESARIAL_VERIFACTU;
    }

    /** Tipo de licencia (1-4) o null si no se puede determinar. */
    public static function type(string $connection, string $database): ?int
    {
        if (array_key_exists($database, self::$types)) {
            return self::$types[$database];
        }

        $type = null;
        $patterns = self::patterns();

        if ($patterns !== null) {
            try {
                $license = (string) (DB::connection($connection)->table('ACCPAR')->value('PARCLBD') ?? '');
                if (trim($license) !== '') {
                    $dbNumber = self::databaseNumber($connection, $database, $patterns);
                    $plain = self::decrypt($license, $patterns['busqueda_numeros'], $patterns['encriptan']);
                    $plain = self::subtractNumbers($plain, $dbNumber);

                    if (substr($plain, 0, 11) === str_repeat('0', 11)) {
                        $type = (int) substr($plain, 11, 2);
                    }
                }
            } catch (\Throwable $e) {
                $type = null;
            }
        }

        return self::$types[$database] = $type;
    }

    private static function patterns(): ?array
    {
        $out = [];
        foreach (['busqueda', 'encripta3', 'busqueda_numeros', 'encriptan'] as $key) {
            $value = base64_decode((string) config("veolab.license.{$key}"), true);
            if ($value === false || $value === '') {
                return null;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** LIC_ObtenNumeroUnicoBD (rama MySQL). */
    private static function databaseNumber(string $connection, string $database, array $patterns): string
    {
        $db = DB::connection($connection);
        $sum = strtolower('MYSQL'.$database);

        $version = (string) $db->selectOne('SELECT VERSION() AS v')->v;
        $uuid = null;
        if (self::supportsServerUuid($version)) {
            try {
                $uuid = $db->selectOne('SELECT @@server_uuid AS u')->u;
            } catch (\Throwable $e) {
                $uuid = null;
            }
        }

        if ($uuid !== null) {
            $sum .= $uuid;
        } else {
            $r = (array) $db->selectOne('SELECT @@hostname AS a, @@basedir AS b, @@license AS c, '
                .'@@version_comment AS d, @@version_compile_machine AS e, @@version_compile_os AS f');
            $sum .= $r['a'].$r['b'].$r['c'].$r['d'].$r['e'].$r['f'];
        }

        // Trozos de 16 caracteres acumulados. Como en VB, avanza 15 (Mid(s, 16)).
        $chunk = '';
        do {
            $chunk = $chunk === '' ? substr($sum, 0, 16) : self::sumStrings($chunk, substr($sum, 0, 16));
            $sum = (string) substr($sum, 15);
        } while ($sum !== '');

        return self::encrypt($chunk, $patterns['busqueda'], $patterns['encripta3']);
    }

    /** DBS_EsVersionMySQLCompatibleUuid: MySQL >= 5.7, nunca MariaDB. */
    private static function supportsServerUuid(string $version): bool
    {
        if (str_contains($version, 'MariaDB')) {
            return false;
        }
        $parts = explode('.', $version);
        $major = (int) ($parts[0] ?? 0);
        $minor = (int) ($parts[1] ?? 0);

        return $major > 5 || ($major === 5 && $minor >= 7);
    }

    /** SumaCadenas (Licencias.bas), incluido su "i < Len" original. */
    private static function sumStrings(string $a, string $b): string
    {
        $out = '';
        $max = max(strlen($a), strlen($b));
        for ($i = 1; $i <= $max; $i++) {
            $op1 = $i < strlen($a) ? ord($a[$i - 1]) : 0;
            $op2 = $i < strlen($b) ? ord($b[$i - 1]) : 0;
            $out .= chr(($op1 + $op2) % 94 + 32);
        }

        return $out;
    }

    /** ENC_Encripta. */
    private static function encrypt(string $text, string $search, string $encrypt): string
    {
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($search, $text[$i]);
            if ($pos === false) {
                $out .= $text[$i];
                continue;
            }
            $posEnc = ($pos + $len + $i) % strlen($search);
            $out .= $encrypt[$posEnc] ?? '';
        }

        return $out;
    }

    /** ENC_Desencripta (el % de PHP tiene el mismo signo que el Mod de VB). */
    private static function decrypt(string $text, string $search, string $encrypt): string
    {
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $posEnc = strpos($encrypt, $text[$i]);
            if ($posEnc === false) {
                $out .= $text[$i];
                continue;
            }
            $t = $posEnc - $len - $i;
            $posBus = $t > 0 ? $t % strlen($encrypt) : strlen($search) + $t % strlen($encrypt);
            $posBus = $posBus % strlen($encrypt);
            $out .= $search[$posBus] ?? '';
        }

        return $out;
    }

    /** ENC_RestaCadenasDeNumeros (Val de un no-dígito = 0). */
    private static function subtractNumbers(string $a, string $b): string
    {
        $width = max(strlen($a), strlen($b));
        $a = str_pad($a, $width, '0', STR_PAD_LEFT);
        $b = str_pad($b, $width, '0', STR_PAD_LEFT);

        $out = '';
        $carry = 0;
        for ($i = $width - 1; $i >= 0; $i--) {
            $d1 = ctype_digit($a[$i]) ? (int) $a[$i] : 0;
            $d2 = (ctype_digit($b[$i]) ? (int) $b[$i] : 0) + $carry;
            if ($d1 < $d2) {
                $d1 += 10;
                $carry = 1;
            } else {
                $carry = 0;
            }
            $out = ($d1 - $d2).$out;
        }

        return $out;
    }
}
