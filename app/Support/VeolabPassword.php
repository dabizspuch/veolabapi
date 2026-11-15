<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Contraseñas de los usuarios de Veolab (ACCUSU.USUCCON).
 *
 * Desde la base de datos 3.0 se guardan con PBKDF2-HMAC-SHA256 y sal
 * aleatoria (Contrasenas.bas): $pbkdf2-sha256$<iteraciones>$<sal>$<hash>,
 * en base64 y sobre la contraseña en UTF-8. Las bases anteriores siguen con
 * ENC_Encripta (Encriptacion.bas), porque Veolab 2.4 solo entiende ese
 * cifrado: cada carácter que está en el patrón de búsqueda se sustituye por
 * el del patrón ENCRIPTA1 en la posición (pos + longitud + índice) mod
 * longitud del patrón; el resto queda igual. Los patrones (secretos, config
 * veolab.password) están en Windows-1252 y se trabaja por caracteres, como
 * VB6 con sus cadenas.
 */
class VeolabPassword
{
    public const HASH_PREFIX = '$pbkdf2-sha256$';

    /** Iteraciones de los hashes nuevos: las mismas que CTR_ITERACIONES (limitadas por lo que tarda un XP). */
    public const ITERATIONS = 10000;

    /** ¿Se pueden poner contraseñas? (con el cifrado anterior hacen falta los patrones) */
    public static function configured(): bool
    {
        return self::hashed() || self::patterns() !== null;
    }

    /** Valor para USUCCON: hash en bases 3.0 o posteriores, ENC_Encripta en las anteriores ('' sin contraseña). */
    public static function make(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        return self::hashed() ? self::hash($plain) : self::encrypt($plain);
    }

    /** ¿La base de datos guarda las contraseñas con hash? (ACCPAR.PARCVBD 3.0 o posterior) */
    public static function hashed(): bool
    {
        return VeolabVersion::isV3();
    }

    /** CTR_Calcular: PBKDF2-HMAC-SHA256 con sal aleatoria de 16 bytes. */
    public static function hash(string $plain): string
    {
        $salt = random_bytes(16);
        $hash = hash_pbkdf2('sha256', $plain, $salt, self::ITERATIONS, 32, true);

        return self::HASH_PREFIX.self::ITERATIONS.'$'.base64_encode($salt).'$'.base64_encode($hash);
    }

    /** CTR_Verificar: admite el hash y el cifrado anterior. */
    public static function verify(string $plain, ?string $stored): bool
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return $plain === '';
        }

        if (str_starts_with($stored, self::HASH_PREFIX)) {
            $parts = explode('$', substr($stored, strlen(self::HASH_PREFIX)));
            if (count($parts) !== 3 || $plain === '' || ! ctype_digit($parts[0]) || (int) $parts[0] < 1) {
                return false;
            }
            $salt = base64_decode($parts[1], true);
            if ($salt === false) {
                return false;
            }

            return hash_equals($parts[2], base64_encode(hash_pbkdf2('sha256', $plain, $salt, (int) $parts[0], 32, true)));
        }

        return self::patterns() !== null && hash_equals($stored, self::encrypt($plain));
    }

    /** ENC_Encripta con los patrones de contraseña. */
    public static function encrypt(string $plain): string
    {
        [$search, $encrypt] = self::patterns();
        $chars = mb_str_split($plain);
        $len = count($chars);
        $size = count($search);
        $positions = array_flip(array_reverse($search, true)); // primera aparición, como InStr

        $out = '';
        foreach ($chars as $i => $char) {
            if (! isset($positions[$char])) {
                $out .= $char;
                continue;
            }
            $out .= $encrypt[($positions[$char] + $len + $i) % $size] ?? '';
        }

        return $out;
    }

    /**
     * SES_ContraSegura: con ACCPAR.PARBSEG = T exige 8 caracteres o más con
     * mayúscula, minúscula, número y otro carácter (A-Z, a-z y 0-9 ASCII).
     */
    public static function secure(string $plain): bool
    {
        $required = DB::connection('dynamic')->table('ACCPAR')->where('PAR1COD', 1)->value('PARBSEG') === 'T';
        if (! $required) {
            return true;
        }

        $chars = mb_str_split($plain);
        if (count($chars) < 8) {
            return false;
        }

        $upper = $lower = $digit = $other = false;
        foreach ($chars as $char) {
            if ($char >= '0' && $char <= '9' && strlen($char) === 1) {
                $digit = true;
            } elseif ($char >= 'A' && $char <= 'Z' && strlen($char) === 1) {
                $upper = true;
            } elseif ($char >= 'a' && $char <= 'z' && strlen($char) === 1) {
                $lower = true;
            } else {
                $other = true;
            }
        }

        return $upper && $lower && $digit && $other;
    }

    /** [búsqueda, encripta1] como listas de caracteres UTF-8, o null. */
    private static function patterns(): ?array
    {
        $out = [];
        foreach (['busqueda', 'encripta1'] as $key) {
            $value = base64_decode((string) config("veolab.password.{$key}"), true);
            if ($value === false || $value === '') {
                return null;
            }
            $out[] = mb_str_split(mb_convert_encoding($value, 'UTF-8', 'Windows-1252'));
        }

        return $out;
    }
}
