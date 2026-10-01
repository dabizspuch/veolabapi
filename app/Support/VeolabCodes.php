<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Códigos de Veolab: contador de claves técnicas (ACCCLT) y formato de código
 * configurado por tabla (ACCCFC), replicando DBS_Autoincremento y
 * PAR_FormatoCodigo. Trabaja siempre sobre la conexión 'dynamic'.
 */
class VeolabCodes
{
    /** Configuración de ACCCFC por BD y tabla (false = la tabla no tiene). */
    private static array $config = [];

    /**
     * Siguiente valor del contador (DBS_Autoincremento, rama ACCCLT).
     * DEBE llamarse dentro de una transacción: bloquea la fila con FOR UPDATE.
     * Con múltiplo > 1 el código salta de múltiplo en múltiplo conservando el
     * resto (reparto de rangos entre instalaciones).
     */
    public static function next(string $table, string $series, string $delegation, int $multiple = 1): int
    {
        $table = substr($table, 0, 6);
        $series = substr($series, 0, 15);
        $delegation = substr($delegation, 0, 10);

        $where = ['DEL3COD' => $delegation, 'CLTCTAB' => $table, 'CLTCSER' => $series];
        $row = DB::connection('dynamic')->table('ACCCLT')->where($where)->lockForUpdate()->first();

        if (! $row) {
            $value = $multiple > 1 ? $multiple + 1 : 1;
            DB::connection('dynamic')->table('ACCCLT')->insert($where + ['CLTNVAL' => $value]);

            return $value;
        }

        $current = (int) $row->CLTNVAL;
        $value = $multiple > 1
            ? (intdiv($current, $multiple) + 1) * $multiple + ($current % $multiple)
            : $current + 1;

        DB::connection('dynamic')->table('ACCCLT')->where($where)->update(['CLTNVAL' => $value]);

        return $value;
    }

    /**
     * Reserva $count valores seguidos del contador y devuelve el ÚLTIMO, como
     * DBS_Autoincremento con incremento (sin múltiplo): los reservados son
     * [último - $count + 1, último]. Bloquea la fila hasta el commit.
     */
    public static function reserve(string $table, string $series, string $delegation, int $count): int
    {
        $table = substr($table, 0, 6);
        $series = substr($series, 0, 15);
        $delegation = substr($delegation, 0, 10);
        $count = max($count, 1);

        $where = ['DEL3COD' => $delegation, 'CLTCTAB' => $table, 'CLTCSER' => $series];
        $row = DB::connection('dynamic')->table('ACCCLT')->where($where)->lockForUpdate()->first();

        if (! $row) {
            DB::connection('dynamic')->table('ACCCLT')->insert($where + ['CLTNVAL' => $count]);

            return $count;
        }

        $value = (int) $row->CLTNVAL + $count;
        DB::connection('dynamic')->table('ACCCLT')->where($where)->update(['CLTNVAL' => $value]);

        return $value;
    }

    /**
     * Recoloca el contador sobre el máximo real (DBS_RegistrarClaveTecnica),
     * para cuando se ha quedado retrasado. Solo lo adelanta, nunca lo atrasa.
     */
    public static function realign(string $table, string $series, string $delegation, int $maximum): void
    {
        $where = ['DEL3COD' => substr($delegation, 0, 10), 'CLTCTAB' => substr($table, 0, 6), 'CLTCSER' => substr($series, 0, 15)];
        $row = DB::connection('dynamic')->table('ACCCLT')->where($where)->lockForUpdate()->first();

        if (! $row) {
            DB::connection('dynamic')->table('ACCCLT')->insert($where + ['CLTNVAL' => $maximum]);
        } elseif ((int) $row->CLTNVAL < $maximum) {
            DB::connection('dynamic')->table('ACCCLT')->where($where)->update(['CLTNVAL' => $maximum]);
        }
    }

    /** Múltiplo configurado para la tabla (CFCNMUL), 1 si no hay. */
    public static function multiple(string $table): int
    {
        $config = self::config($table);

        return $config ? max((int) $config->CFCNMUL, 1) : 1;
    }

    /** ¿El código se genera con el contador ACCCLT? (CFCBAUT; sin config, sí). */
    public static function autonumeric(string $table): bool
    {
        $config = self::config($table);

        return ! $config || $config->CFCBAUT === 'T';
    }

    /** ¿El código está bloqueado y no se puede indicar a mano? (CFCBBLO). */
    public static function locked(string $table): bool
    {
        $config = self::config($table);

        return $config && $config->CFCBBLO === 'T';
    }

    /** ¿La serie es por defecto el código del cliente? (CFCBCLI). */
    public static function seriesPerClient(string $table): bool
    {
        $config = self::config($table);

        return $config && $config->CFCBCLI === 'T';
    }

    /**
     * Código formateado para mostrar/auditar (PAR_FormatoCodigo). Sin
     * configuración para la tabla usa el formato de reserva de VB: del-ser-cod.
     * $info = ':SINFORMATO' (OPECINF de la operación) deja el código sin formato.
     * $description solo se usa en el formato de reserva (tablas sin formato
     * configurable): "del-ser-cod-descripción", como el ControlError de VB.
     */
    public static function format(string $table, string $code, string $delegation = '', string $series = '', string $info = '', string $description = ''): string
    {
        $config = $table === '' ? false : self::config($table);

        if (! $config) {
            $parts = array_filter([$delegation, $series], fn ($p) => $p !== '');
            $parts[] = $code;
            if ($description !== '') {
                $parts[] = $description;
            }

            return implode('-', $parts);
        }

        $separator = (string) $config->CFCCSEP;
        $out = $info === ':SINFORMATO' ? $code : self::vbFormat($code, (string) $config->CFCCFCO);

        if ($config->CFCBTOD === 'T' && $config->CFCBMSE === 'T' && $series !== '') {
            $out = $config->CFCCPSE === 'D' ? $out.$separator.$series : $series.$separator.$out;
        }

        if ($config->CFCBMDE === 'T' && $delegation !== '') {
            $del = self::vbFormat($delegation, (string) $config->CFCCFDE);
            $out = $config->CFCCPDE === 'D' ? $out.$separator.$del : $del.$separator.$out;
        }

        return $out;
    }

    private static function config(string $table)
    {
        $key = DB::connection('dynamic')->getDatabaseName().'.'.$table;

        if (! array_key_exists($key, self::$config)) {
            self::$config[$key] = DB::connection('dynamic')->table('ACCCFC')
                ->where('CFCCNOM', $table)->first() ?? false;
        }

        return self::$config[$key];
    }

    /**
     * Subconjunto de Format() de VB con los formatos que usa Veolab para
     * códigos: numéricos con 0/# (relleno con ceros) y de texto con @/& y
     * </> (minúsculas/mayúsculas). Cualquier otro formato devuelve el valor
     * sin tocar, igual que FormatoUsuario ante un error.
     */
    private static function vbFormat(string $value, string $format): string
    {
        if ($format === '') {
            return $value;
        }

        if (preg_match('/^[0#]+$/', $format)) {
            if (! preg_match('/^\d+$/', $value)) {
                return $value;
            }

            return str_pad(ltrim($value, '0') ?: '0', substr_count($format, '0'), '0', STR_PAD_LEFT);
        }

        if (preg_match('/^[<>]?[@&]+$/', $format)) {
            if ($format[0] === '>') {
                $value = mb_strtoupper($value);
            } elseif ($format[0] === '<') {
                $value = mb_strtolower($value);
            }

            return str_pad($value, substr_count($format, '@'), ' ', STR_PAD_LEFT);
        }

        return $value;
    }
}
