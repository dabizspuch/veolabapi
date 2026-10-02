<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Contraseñas de los usuarios de Veolab (ACCUSU.USUCCON).
 *
 * Veolab las guarda con ENC_Encripta (Encriptacion.bas): cada carácter que
 * está en el patrón de búsqueda se sustituye por el del patrón ENCRIPTA1 en
 * la posición (pos + longitud + índice) mod longitud del patrón; el resto
 * queda igual. Los patrones (secretos, config veolab.password) están en
 * Windows-1252 y se trabaja por caracteres, como VB6 con sus cadenas.
 */
class VeolabPassword
{
    /** ¿Están configurados los patrones? */
    public static function configured(): bool
    {
        return self::patterns() !== null;
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
