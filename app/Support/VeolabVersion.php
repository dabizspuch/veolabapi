<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Versión de la base de datos del laboratorio (ACCPAR.PARCVBD).
 *
 * La API sirve a todos los laboratorios del servidor y cada uno actualiza
 * Veolab cuando quiere, así que conviven bases 2.4 y 3.0. Todo lo que lea o
 * escriba columnas, tablas o módulos que solo existen desde una versión
 * pasa por aquí: los controladores declaran en $since los campos nuevos y
 * en $sinceVersion los recursos enteros (BaseController los omite o rechaza
 * en bases anteriores), y el resto del código pregunta atLeast() / isV3().
 *
 * Se consulta una vez por petición y base de datos.
 */
class VeolabVersion
{
    /** Primera versión de Veolab 3 (la conversión Script24a30 deja '3.0'). */
    public const V3 = '3.0';

    /** Versión por base de datos (nombre => PARCVBD), para no repetir la consulta. */
    private static array $versions = [];

    /** Versión de la base de datos actual ('' si no se puede leer). */
    public static function current(string $connection = 'dynamic'): string
    {
        try {
            $database = (string) DB::connection($connection)->getDatabaseName();
        } catch (\Throwable) {
            $database = '';     // sin conexión (p. ej. un cálculo suelto): versión desconocida
        }
        if (! array_key_exists($database, self::$versions)) {
            try {
                self::$versions[$database] = trim((string) DB::connection($connection)->table('ACCPAR')
                    ->where('PAR1COD', 1)->value('PARCVBD'));
            } catch (\Throwable) {
                self::$versions[$database] = '';
            }
        }

        return self::$versions[$database];
    }

    /** ¿La base de datos está en la versión indicada o en una posterior? */
    public static function atLeast(string $version, string $connection = 'dynamic'): bool
    {
        $current = self::current($connection);

        return $current !== '' && version_compare($current, $version, '>=');
    }

    /** ¿Base de datos de Veolab 3 o posterior? */
    public static function isV3(string $connection = 'dynamic'): bool
    {
        return self::atLeast(self::V3, $connection);
    }

    /**
     * Exige la versión indicada para lo que se describe ($what: "El campo
     * fecha_elaboracion", "Los envases"...); si no, error de negocio (422).
     */
    public static function require(string $version, string $what, string $connection = 'dynamic'): void
    {
        if (! self::atLeast($version, $connection)) {
            throw new BusinessRuleException(self::message($version, $what, $connection));
        }
    }

    /** Texto del error de versión, con la versión que tiene la base de datos. */
    public static function message(string $version, string $what, string $connection = 'dynamic'): string
    {
        $current = self::current($connection);
        $label = version_compare($version, self::V3, '>=') && version_compare($version, '3.1', '<') ? 'Veolab 3' : "Veolab {$version}";

        return "{$what} requiere la base de datos de {$label}"
            .($current !== '' ? " (esta base de datos está en la versión {$current})" : '');
    }

    /** Olvida las versiones leídas (pruebas, o tras convertir una base de datos). */
    public static function forget(): void
    {
        self::$versions = [];
    }
}
