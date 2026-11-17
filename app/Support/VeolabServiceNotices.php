<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Avisos de servicio de SPUCH (cortes del servidor, incidencias y comunicaciones a los clientes), los mismos que
 * Veolab muestra con AvisosServicio.bas. Se crean en la web de licencias y se leen de getnotice.php en spuch.com, que
 * identifica al laboratorio por su licencia (ACCPAR.PARCLBD) y registra la recepción.
 *
 * Respuesta de getnotice.php: una línea por aviso con los campos separados por tabulador (código, gravedad, inicio,
 * fin, corte, título y texto); en el título y el texto, \n es un salto de línea, \t un tabulador y \\ una barra. Las
 * fechas van en hora peninsular española (Europe/Madrid) con el formato «AAAA-MM-DD hh:mm».
 */
class VeolabServiceNotices
{
    public const GRAVEDADES = [0 => 'informativo', 1 => 'programado', 2 => 'urgente'];

    /** La API cuenta en la web como un solo «equipo» por laboratorio. */
    private const EQUIPO = 'a91e0c5d';

    /**
     * Avisos vigentes para la licencia, o null si spuch.com no responde. La respuesta se guarda un minuto por
     * licencia para que muchas peticiones seguidas no lleguen todas a spuch.com.
     */
    public static function fetch(string $license, string $database): ?array
    {
        $key = 'avisos-servicio:'.sha1($license.'|'.$database);
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Sin caché se consulta igualmente
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)->get((string) config('veolab.service_notices.url'), [
                'licencia' => $license,
                'servidor' => (string) config('veolab.service_notices.server'),
                'version'  => 'api',
                'equipo'   => self::EQUIPO,
                'r'        => (string) time(),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $notices = self::parse($response->body());
        try {
            Cache::put($key, $notices, (int) config('veolab.service_notices.cache_seconds', 60));
        } catch (\Throwable $e) {
            // La caché es solo una ayuda
        }

        return $notices;
    }

    /** Interpreta la respuesta de getnotice.php. */
    public static function parse(string $body): array
    {
        $notices = [];
        foreach (explode("\n", str_replace("\r", '', $body)) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) < 7 || ! ctype_digit($fields[0]) || ! ctype_digit($fields[1])) {
                continue;
            }
            $severity = (int) $fields[1];
            $notices[] = [
                'codigo'                => (int) $fields[0],
                'gravedad'              => $severity,
                'gravedad_descripcion'  => self::GRAVEDADES[$severity] ?? 'informativo',
                'inicio'                => self::date($fields[2]),
                'fin'                   => self::date($fields[3]),
                'corte'                 => self::date($fields[4]),
                'titulo'                => self::unescape($fields[5]),
                'texto'                 => self::unescape($fields[6]),
            ];
        }

        return $notices;
    }

    /** «AAAA-MM-DD hh:mm» a «AAAA-MM-DD hh:mm:ss» (null si viene vacía o mal formada). */
    private static function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) ? $value.':00' : null;
    }

    /** Deshace los escapes: \n salto de línea, \t tabulador, \\ barra (cualquier otro \x queda en x). */
    private static function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/s', fn ($m) => match ($m[1]) {
            'n'     => "\n",
            't'     => "\t",
            default => $m[1],
        }, $value);
    }
}
