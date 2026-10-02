<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cartas de control (módulo CDC) como Veolab 2.4: LABCDC (carta), LABCYT
 * (técnicas de la carta) y LABRCD (resultados, uno por operación de control).
 *
 * Tipos: E exactitud (columnas LABCOR.CORBCON) y P precisión (CORBCOP).
 * Estados: N normal, A aviso, E error, C corregida. Tipo de sección de la
 * técnica (LABSEC.SECCTIP): M microbiología, F físico-químico.
 *
 * - Límites e incumplimientos: LAB_CalcularIncumplimientosCartas (Laboratorio.bas).
 * - Promedio y desviación: LAB_CalcularPromedioDesviacionCarta.
 * - Alimentación al grabar resultados de una operación de control:
 *   FichaResultados.AcumulaGrabarControl.
 * - Bloqueo de técnicas con la carta en error: RevisarBloqueosCartas.
 * - Notificaciones: COM_CrearNotificacionesCarta{Error,Aviso,Nueva}.
 */
class VeolabControlCharts
{
    public const EXACTITUD = 'E';
    public const PRECISION = 'P';

    /** Tipos de notificación de ACCNOT (Comunicaciones.bas). */
    private const NOTIF_ERROR = 'O';
    private const NOTIF_AVISO = 'V';
    private const NOTIF_NUEVA = 'N';

    // ------------------------------------------------------------------
    // Cálculos
    // ------------------------------------------------------------------

    /**
     * Incidencia del ÚLTIMO de los resultados ('E' error, 'A' aviso, '' ninguna)
     * con los límites de la carta. $values en orden, hasta 7 (los anteriores no cuentan).
     */
    public static function incidence(string $type, string $section, float $average, float $deviation, array $values): string
    {
        $values = array_values(array_map(fn ($v) => VeolabOperationServices::decimal($v), $values));
        if (! $values) {
            return '';
        }

        if ($section === 'M') {
            $lsc = $average * 3.27;
            $lic = 2 * $average - $average * 3.27;
        } else {
            $lsc = $average + 3 * $deviation;
            $lic = $average - 3 * $deviation;
        }
        $lsa = $average + 2 * $deviation;
        $lia = $average - 2 * $deviation;
        $s1s = $average + $deviation;
        $s1i = $average - $deviation;

        // En microbiología y en físico-químico de precisión no se evalúan tendencias.
        $limitsOnly = $section === 'M' || ($section === 'F' && $type === self::PRECISION);

        $error = $limitsOnly
            ? self::errorControlLimit($type, $values, $lsc, $lic)
            : self::errorControlLimit($type, $values, $lsc, $lic)
                || self::errorWarningLimit($type, $values, $lsa, $lia)
                || self::errorDeviation($type, $values, $s1s, $s1i)
                || self::errorTrend($values, $average);
        if ($error) {
            return 'E';
        }

        $warning = $limitsOnly
            ? self::warningControlLimit($type, $values, $lsc, $lic)
            : self::warningControlLimit($type, $values, $lsc, $lic)
                || self::warningWarningLimit($type, $values, $lsa, $lia)
                || self::warningDeviation($type, $values, $s1s, $s1i);

        return $warning ? 'A' : '';
    }

    /**
     * [promedio, desviación] de los últimos $count resultados de control (de
     * cualquier operación de control) de las técnicas, redondeados a 4
     * decimales. En físico-químico se fija el promedio: 0 precisión, 100 exactitud.
     */
    public static function averageDeviation(string $type, string $section, int $count, array $techniques): array
    {
        $average = 0.0;
        $deviation = 0.0;
        if (! $techniques) {
            return [$average, $deviation];
        }

        $values = DB::connection('dynamic')->table('LABCOR')
            ->leftJoin('LABOPE', function ($join) {
                $join->on('LABCOR.OPE3DEL', '=', 'LABOPE.DEL3COD')
                    ->on('LABCOR.OPE3SER', '=', 'LABOPE.OPE1SER')
                    ->on('LABCOR.OPE3COD', '=', 'LABOPE.OPE1COD');
            })
            ->where('LABOPE.OPEBCON', 'T')
            ->where($type === self::EXACTITUD ? 'LABCOR.CORBCON' : 'LABCOR.CORBCOP', 'T')
            ->where('LABCOR.CORCVAL', '<>', 'N/A')->where('LABCOR.CORCVAL', '<>', '')
            ->where(fn ($q) => self::whereTechniques($q, $techniques, 'LABCOR.'))
            ->orderByDesc('LABCOR.OPE3DEL')->orderByDesc('LABCOR.OPE3SER')->orderByDesc('LABCOR.OPE3COD')
            ->limit(max(abs($count), 1))
            ->pluck('LABCOR.CORCVAL')
            ->map(fn ($v) => VeolabOperationServices::decimal($v))->all();

        if ($values) {
            $average = round(array_sum($values) / count($values), 4);
            if (count($values) > 1) {
                $sum = 0.0;
                foreach ($values as $value) {
                    $sum += ($value - $average) ** 2;
                }
                $deviation = round(sqrt($sum / (count($values) - 1)), 4);
            }
        }

        if ($section === 'F') {
            $average = $type === self::PRECISION ? 0.0 : 100.0;
        }

        return [$average, $deviation];
    }

    /**
     * Valor de control de una operación para la carta (LAB_ObtenerResultadoControl):
     * el de la primera columna de control del tipo en las técnicas, o 0.
     */
    public static function controlValue(string $type, array $operation, array $techniques): float
    {
        if (! $techniques) {
            return 0.0;
        }

        $value = DB::connection('dynamic')->table('LABCOR')
            ->where('CORCVAL', '<>', '')->where('CORCVAL', '<>', 'N/A')
            ->where($type === self::EXACTITUD ? 'CORBCON' : 'CORBCOP', 'T')
            ->where('OPE3DEL', $operation[0])->where('OPE3SER', $operation[1])->where('OPE3COD', $operation[2])
            ->where(fn ($q) => self::whereTechniques($q, $techniques))
            ->orderBy('TEC3DEL')->orderBy('TEC3COD')->orderBy('COR1COD')
            ->value('CORCVAL');

        return $value === null ? 0.0 : VeolabOperationServices::decimal($value);
    }

    /** Tipo de sección de las técnicas de una carta: el último no vacío (FichaCarta). */
    public static function sectionType(array $techniques): string
    {
        $type = '';
        foreach ($techniques as [$del, $cod]) {
            $value = (string) DB::connection('dynamic')->table('LABTEC')
                ->leftJoin('LABSEC', function ($join) {
                    $join->on('LABTEC.SEC2DEL', '=', 'LABSEC.DEL3COD')->on('LABTEC.SEC2COD', '=', 'LABSEC.SEC1COD');
                })
                ->where('LABTEC.DEL3COD', $del)->where('LABTEC.TEC1COD', $cod)->value('LABSEC.SECCTIP');
            if ($value !== '') {
                $type = $value;
            }
        }

        return $type;
    }

    /** Técnicas de la carta [[del, cod], ...] en el orden de LABCYT. */
    public static function techniques(string $del, int $cod): array
    {
        return DB::connection('dynamic')->table('LABCYT')
            ->where('CDC3DEL', $del)->where('CDC3COD', $cod)
            ->orderBy('TEC3DEL')->orderBy('TEC3COD')
            ->get(['TEC3DEL', 'TEC3COD'])->map(fn ($r) => [(string) $r->TEC3DEL, (string) $r->TEC3COD])->all();
    }

    /** Resultados de la carta en orden (RCD1COD). */
    public static function results(string $del, int $cod): array
    {
        return DB::connection('dynamic')->table('LABRCD')
            ->where('CDC3DEL', $del)->where('CDC3COD', $cod)->orderBy('RCD1COD')
            ->get()->all();
    }

    // ------------------------------------------------------------------
    // Resultados de operaciones de control
    // ------------------------------------------------------------------

    /**
     * Carta que corresponde a la técnica en una operación (LeerCartasControl):
     * la que ya tiene un resultado de la operación (de cualquier delegación) o,
     * si no, la última carta del tipo de la delegación de la sesión con esa técnica.
     */
    public static function chartFor(string $type, string $tecDel, string $tecCod, array $operation, string $sessionDel): ?object
    {
        $db = DB::connection('dynamic');

        $used = $db->table('LABCDC')
            ->join('LABCYT', function ($join) {
                $join->on('LABCYT.CDC3DEL', '=', 'LABCDC.DEL3COD')->on('LABCYT.CDC3COD', '=', 'LABCDC.CDC1COD');
            })
            ->join('LABRCD', function ($join) {
                $join->on('LABRCD.CDC3DEL', '=', 'LABCDC.DEL3COD')->on('LABRCD.CDC3COD', '=', 'LABCDC.CDC1COD');
            })
            ->where('LABCDC.CDCCTIP', $type)
            ->where('LABCYT.TEC3DEL', $tecDel)->where('LABCYT.TEC3COD', $tecCod)
            ->where('LABRCD.OPE2DEL', $operation[0])->where('LABRCD.OPE2SER', $operation[1])->where('LABRCD.OPE2COD', $operation[2])
            ->orderByDesc('LABCDC.CDC1COD')
            ->first(['LABCDC.*']);
        if ($used) {
            return $used;
        }

        return $db->table('LABCDC')
            ->join('LABCYT', function ($join) {
                $join->on('LABCYT.CDC3DEL', '=', 'LABCDC.DEL3COD')->on('LABCYT.CDC3COD', '=', 'LABCDC.CDC1COD');
            })
            ->where('LABCDC.CDCCTIP', $type)->where('LABCDC.DEL3COD', $sessionDel)
            ->where('LABCYT.TEC3DEL', $tecDel)->where('LABCYT.TEC3COD', $tecCod)
            ->orderByDesc('LABCDC.CDC1COD')
            ->first(['LABCDC.*']);
    }

    /** ¿La técnica está bloqueada en la operación por una carta en error? (RevisarBloqueosCartas) */
    public static function blocked(string $tecDel, string $tecCod, array $operation, string $sessionDel): bool
    {
        foreach ([self::EXACTITUD, self::PRECISION] as $type) {
            $chart = self::chartFor($type, $tecDel, $tecCod, $operation, $sessionDel);
            if ($chart && $chart->CDCCEST === 'E') {
                return true;
            }
        }

        return false;
    }

    /**
     * Graba un resultado de control de la operación en la carta de la técnica
     * (AcumulaGrabarControl). Sin carta no hace nada. Con la carta llena
     * (CDCNNUM resultados) abre una nueva con las mismas técnicas y matriz, el
     * promedio y la desviación recalculados y CONNNUM resultados, y cierra la
     * anterior. Acumula en $events las cartas con error, aviso y nuevas.
     */
    public static function record(string $type, string $tecDel, string $tecCod, array $operation, string $value,
        string $sessionDel, array &$events): void
    {
        $chart = self::chartFor($type, $tecDel, $tecCod, $operation, $sessionDel);
        if (! $chart) {
            return;
        }

        $db = DB::connection('dynamic');
        $chartDel = (string) $chart->DEL3COD;
        $chartCod = (int) $chart->CDC1COD;
        $section = self::sectionType([[$tecDel, $tecCod]]);
        $results = self::results($chartDel, $chartCod);

        $position = null;
        foreach ($results as $i => $row) {
            if ((string) $row->OPE2DEL === $operation[0] && (string) $row->OPE2SER === $operation[1] && (int) $row->OPE2COD === $operation[2]) {
                $position = $i;
                break;
            }
        }

        if ($position === null && count($results) + 1 > (int) $chart->CDCNNUM) {
            // Carta llena: nueva carta en la delegación de la sesión.
            $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
            $newCod = self::nextCode($sessionDel);
            $techniques = self::techniques($chartDel, $chartCod);
            [$average, $deviation] = self::averageDeviation($type, $section, (int) $chart->CDCNNUM, $techniques);
            $incidence = self::incidence($type, $section, $average, $deviation, [$value]);

            foreach ($techniques as [$del, $cod]) {
                $db->table('LABCYT')->insert(['CDC3DEL' => $sessionDel, 'CDC3COD' => $newCod, 'TEC3DEL' => $del, 'TEC3COD' => $cod]);
            }
            $db->table('LABCDC')->where('DEL3COD', $chartDel)->where('CDC1COD', $chartCod)->update(['CDCDCIE' => $now]);
            $db->table('LABCDC')->insert([
                'DEL3COD' => $sessionDel,
                'CDC1COD' => $newCod,
                'CDCCTIP' => $type,
                'CDCCEST' => $incidence !== '' ? $incidence : 'N',
                'CDCDCRE' => $now,
                'CDCNNUM' => (int) $db->table('LABCON')->where('CON1COD', 1)->value('CONNNUM'),
                'CDCNPRO' => $average,
                'CDCNDES' => $deviation,
                'MAT2DEL' => (string) ($chart->MAT2DEL ?? ''),
                'MAT2COD' => (int) ($chart->MAT2COD ?? 0),
            ]);
            $db->table('LABRCD')->insert(self::resultRow($sessionDel, $newCod, 1, $value, $incidence, $operation));

            VeolabAudit::record(VeolabAudit::INSERCION, 'LABCDC', VeolabCodes::format('LABCDC', (string) $newCod, $sessionDel));
            $events['nuevas'][] = [$sessionDel, $newCod];
            self::incidenceEvent($incidence, $sessionDel, $newCod, $events);

            return;
        }

        $average = (float) $chart->CDCNPRO;
        $deviation = (float) $chart->CDCNDES;
        $values = array_map(fn ($r) => (string) $r->RCDNVAL, $results);

        if ($position === null) {
            $values[] = $value;
            $incidence = self::incidence($type, $section, $average, $deviation, array_slice($values, -7));
            $next = $results ? max(array_map(fn ($r) => (int) $r->RCD1COD, $results)) + 1 : 1;
            $db->table('LABRCD')->insert(self::resultRow($chartDel, $chartCod, $next, $value, $incidence, $operation));
        } else {
            $values[$position] = $value;
            $incidence = self::incidence($type, $section, $average, $deviation, array_slice($values, max(0, $position - 6), min(7, $position + 1)));
            $db->table('LABRCD')->where('CDC3DEL', $chartDel)->where('CDC3COD', $chartCod)
                ->where('RCD1COD', $results[$position]->RCD1COD)
                ->update(['RCDNVAL' => VeolabOperationServices::decimal($value), 'RCDCTII' => $incidence]);
        }

        if ($incidence !== '') {
            // El estado no baja de error.
            $db->table('LABCDC')->where('DEL3COD', $chartDel)->where('CDC1COD', $chartCod)->where('CDCCEST', '<>', 'E')
                ->update(['CDCCEST' => $incidence]);
            self::incidenceEvent($incidence, $chartDel, $chartCod, $events);
        }
    }

    /**
     * Recalcula las incidencias de todos los resultados de la carta
     * (FichaCarta.ComprobarIncidenciasTodos) y devuelve el estado que deja:
     * el de la última incidencia (A o E), o el actual si no hay ninguna.
     */
    public static function recheck(string $del, int $cod, string $state): string
    {
        $chart = DB::connection('dynamic')->table('LABCDC')->where('DEL3COD', $del)->where('CDC1COD', $cod)->first();
        $section = self::sectionType(self::techniques($del, $cod));
        $results = self::results($del, $cod);
        $values = array_map(fn ($r) => (string) $r->RCDNVAL, $results);

        foreach ($results as $i => $row) {
            $incidence = self::incidence((string) $chart->CDCCTIP, $section, (float) $chart->CDCNPRO, (float) $chart->CDCNDES,
                array_slice($values, max(0, $i - 7), min(8, $i + 1)));
            if ($incidence !== (string) $row->RCDCTII) {
                DB::connection('dynamic')->table('LABRCD')
                    ->where('CDC3DEL', $del)->where('CDC3COD', $cod)->where('RCD1COD', $row->RCD1COD)
                    ->update(['RCDCTII' => $incidence]);
            }
            if ($incidence !== '') {
                $state = $incidence;
            }
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Notificaciones (módulo COM)
    // ------------------------------------------------------------------

    /**
     * Notificaciones de cartas como Veolab tras grabar resultados: errores y
     * cartas nuevas a los usuarios empleados con escritura en LAB_CDC (o al
     * usuario de la petición si no hay ninguno), avisos al usuario de la
     * petición. Según CONBCAE / CONBCAA / CONBCAN. Sustituye las anteriores
     * del mismo tipo de esas cartas.
     */
    public static function notify(array $events, ?array $user): void
    {
        $db = DB::connection('dynamic');
        if (! $events || ! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'COM')) {
            return;
        }
        $config = $db->table('LABCON')->where('CON1COD', 1)->first(['CONBCAE', 'CONBCAA', 'CONBCAN']);

        $managers = $db->table('ACCUSU')
            ->join('ACCPYF', function ($join) {
                $join->on('ACCUSU.PER2DEL', '=', 'ACCPYF.DEL3COD')->on('ACCUSU.PER2COD', '=', 'ACCPYF.PER3COD');
            })
            ->where('ACCPYF.FUN3COD', 'LAB_CDC')->whereIn('ACCPYF.PYFNACC', [3, 7])->where('ACCUSU.USUNTIP', 0)
            ->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD'])
            ->map(fn ($u) => [(string) $u->DEL3COD, (string) $u->USU1COD])->all();
        if (! $managers && $user) {
            $managers = [$user];
        }

        if (($config->CONBCAE ?? '') === 'T') {
            self::createNotifications(self::NOTIF_ERROR, $events['errores'] ?? [], $managers);
        }
        if (($config->CONBCAA ?? '') === 'T') {
            self::createNotifications(self::NOTIF_AVISO, $events['avisos'] ?? [], $user ? [$user] : []);
        }
        if (($config->CONBCAN ?? '') === 'T') {
            self::createNotifications(self::NOTIF_NUEVA, $events['nuevas'] ?? [], $managers);
        }
    }

    /** Borra los avisos y notificaciones de las cartas (al borrar la carta). */
    public static function deleteNotifications(string $del, int $cod): void
    {
        $db = DB::connection('dynamic');
        $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
            .'WHERE ACCNOT.CDC2DEL = ? AND ACCNOT.CDC2COD = ?', [$del, $cod]);
        $db->table('ACCNOT')->where('CDC2DEL', $del)->where('CDC2COD', $cod)->delete();
    }

    private static function createNotifications(string $kind, array $charts, array $users): void
    {
        $charts = array_values(array_unique(array_map(fn ($c) => $c[0]."\x1B".$c[1], $charts)));
        if (! $charts) {
            return;
        }

        $db = DB::connection('dynamic');
        foreach ($charts as $key) {
            [$del, $cod] = explode("\x1B", $key, 2);
            $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
                .'WHERE ACCNOT.CDC2DEL = ? AND ACCNOT.CDC2COD = ? AND ACCAVI.AVICTIP = ? AND ACCNOT.NOTCTIP = ?', [$del, (int) $cod, 'N', $kind]);
            $db->table('ACCNOT')->where('CDC2DEL', $del)->where('CDC2COD', (int) $cod)->where('NOTCTIP', $kind)->delete();
        }

        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        foreach ($users as [$userDel, $userCod]) {
            foreach ($charts as $key) {
                [$del, $cod] = explode("\x1B", $key, 2);
                $notification = VeolabCodes::next('ACCNOT', '', $userDel);
                $db->table('ACCNOT')->insert([
                    'DEL3COD' => $userDel, 'NOT1COD' => $notification, 'NOTTFEC' => $now, 'NOTCTIP' => $kind,
                    'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'CDC2DEL' => $del, 'CDC2COD' => (int) $cod,
                ]);
                $db->table('ACCAVI')->insert([
                    'DEL3COD' => $userDel, 'AVI1COD' => VeolabCodes::next('ACCAVI', '', $userDel), 'AVITFEC' => $now,
                    'AVICTIP' => 'N', 'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'NOT2DEL' => $userDel, 'NOT2COD' => $notification,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    private static function incidenceEvent(string $incidence, string $del, int $cod, array &$events): void
    {
        if ($incidence === 'E') {
            $events['errores'][] = [$del, $cod];
        } elseif ($incidence === 'A') {
            $events['avisos'][] = [$del, $cod];
        }
    }

    private static function resultRow(string $del, int $cod, int $position, string $value, string $incidence, array $operation): array
    {
        return [
            'CDC3DEL' => $del, 'CDC3COD' => $cod, 'RCD1COD' => $position,
            'RCDNVAL' => VeolabOperationServices::decimal($value), 'RCDCTII' => $incidence,
            'OPE2DEL' => $operation[0], 'OPE2SER' => $operation[1], 'OPE2COD' => $operation[2],
        ];
    }

    /** Siguiente código libre de carta de la delegación (contador de LABCDC). */
    public static function nextCode(string $del): int
    {
        $multiple = VeolabCodes::multiple('LABCDC');
        do {
            $code = VeolabCodes::next('LABCDC', '', $del, $multiple);
        } while (DB::connection('dynamic')->table('LABCDC')->where('DEL3COD', $del)->where('CDC1COD', $code)->exists());

        return $code;
    }

    private static function whereTechniques($query, array $techniques, string $prefix = ''): void
    {
        foreach ($techniques as [$del, $cod]) {
            $query->orWhere(fn ($q) => $q->where($prefix.'TEC3DEL', $del)->where($prefix.'TEC3COD', $cod));
        }
    }

    // --- Reglas (Laboratorio.bas). $v: valores en orden, el último es el nuevo. ---

    private static function errorControlLimit(string $type, array $v, float $lsc, float $lic): bool
    {
        $n = count($v);
        if ($n < 2) {
            return false;
        }
        [$last, $prev] = [$v[$n - 1], $v[$n - 2]];
        $above = $last > $lsc && $prev > $lsc;

        return $type === self::EXACTITUD ? $above || ($last < $lic && $prev < $lic) : $above;
    }

    private static function warningControlLimit(string $type, array $v, float $lsc, float $lic): bool
    {
        $last = $v[count($v) - 1];

        return $type === self::EXACTITUD ? $last > $lsc || $last < $lic : $last > $lsc;
    }

    private static function errorWarningLimit(string $type, array $v, float $lsa, float $lia): bool
    {
        $n = count($v);
        if ($n <= 3) {
            return false;
        }
        [$r1, $r2, $r3, $r4] = array_slice($v, -4);
        $up = $r4 > $lsa && (int) ($r1 > $lsa) + (int) ($r2 > $lsa) + (int) ($r3 > $lsa) >= 2;
        if ($type !== self::EXACTITUD) {
            return $up;
        }
        $down = $r4 < $lia && (int) ($r1 < $lia) + (int) ($r2 < $lia) + (int) ($r3 < $lia) >= 2;

        return $up || $down;
    }

    private static function warningWarningLimit(string $type, array $v, float $lsa, float $lia): bool
    {
        if (count($v) <= 2) {
            return false;
        }
        [$r1, $r2, $r3] = array_slice($v, -3);
        $up = (int) ($r1 > $lsa) + (int) ($r2 > $lsa) + (int) ($r3 > $lsa) >= 2;
        if ($type !== self::EXACTITUD) {
            return $up;
        }

        return $up || (int) ($r1 < $lia) + (int) ($r2 < $lia) + (int) ($r3 < $lia) >= 2;
    }

    private static function errorDeviation(string $type, array $v, float $s1s, float $s1i): bool
    {
        if (count($v) <= 4) {
            return false;
        }
        [$r1, $r2, $r3, $r4, $r5] = array_slice($v, -5);
        $monotonic = ($r1 > $r2 && $r2 > $r3 && $r3 > $r4 && $r4 > $r5) || ($r1 < $r2 && $r2 < $r3 && $r3 < $r4 && $r4 < $r5);
        $up = (int) ($r1 > $s1s) + (int) ($r2 > $s1s) + (int) ($r3 > $s1s) + (int) ($r4 > $s1s) >= 4 && $r5 > $s1s;
        if ($type !== self::EXACTITUD) {
            return $up || $monotonic;
        }
        $down = (int) ($r1 < $s1i) + (int) ($r2 < $s1i) + (int) ($r3 < $s1i) + (int) ($r4 < $s1i) >= 4 && $r5 < $s1i;

        return $up || $down || $monotonic;
    }

    private static function warningDeviation(string $type, array $v, float $s1s, float $s1i): bool
    {
        if (count($v) <= 3) {
            return false;
        }
        [$r1, $r2, $r3, $r4] = array_slice($v, -4);
        $monotonic = ($r1 > $r2 && $r2 > $r3 && $r3 > $r4) || ($r1 < $r2 && $r2 < $r3 && $r3 < $r4);
        $up = (int) ($r1 > $s1s) + (int) ($r2 > $s1s) + (int) ($r3 > $s1s) + (int) ($r4 > $s1s) >= 4;
        if ($type !== self::EXACTITUD) {
            return $up || $monotonic;
        }
        $down = (int) ($r1 < $s1i) + (int) ($r2 < $s1i) + (int) ($r3 < $s1i) + (int) ($r4 < $s1i) >= 4;

        return $up || $down || $monotonic;
    }

    private static function errorTrend(array $v, float $average): bool
    {
        if (count($v) <= 6) {
            return false;
        }
        $last7 = array_slice($v, -7);

        return count(array_filter($last7, fn ($x) => $x > $average)) === 7
            || count(array_filter($last7, fn ($x) => $x < $average)) === 7;
    }
}
