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
 *
 * Veolab 3: cada técnica puede tener varias cartas de cada tipo. La carta lleva
 * su número (LABCDC.CDCNCAR, 1 = principal) y nombre (CDCCNOM), y cada columna
 * de la técnica el número de carta al que va (LABCOT.COTNCDE / COTNCDP; vacío =
 * principal). Cada punto (LABRCD) guarda la fecha del análisis (RCDTFEC) y el
 * analista (EMP2DEL/EMP2COD) de LABRES. En bases 2.4 todo es la carta principal.
 */
class VeolabControlCharts
{
    public const EXACTITUD = 'E';

    /**
     * Veolab 3: carta de blancos (LABCDC.CDCBBLA = T en una carta de exactitud).
     * Solo cuentan los valores altos: límites superiores, subidas y puntos por
     * encima del valor central; su valor central es la media de los blancos.
     * Es un tipo de evaluación, no un CDCCTIP (las columnas siguen siendo de exactitud).
     */
    public const BLANCO = 'B';
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
    public static function incidence(string $type, string $section, float $average, float $deviation, array $values, string $rules = 'V'): string
    {
        $values = array_values(array_map(fn ($v) => VeolabOperationServices::decimal($v), $values));
        if (! $values) {
            return '';
        }

        // Microbiología: carta de rangos (3,27) en precisión; en Veolab 3 la exactitud usa ±3s como las demás.
        if ($section === 'M' && ($type === self::PRECISION || ! VeolabVersion::isV3())) {
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

        if ($rules === 'N' && VeolabVersion::isV3()) {
            if ($section === 'M' && $type === self::PRECISION) {
                $lsa = $average * 2.512;   // Rangos: advertencia a 2,512 veces el rango medio
            }

            return self::nordtest($type, $limitsOnly, $average, $lsc, $lic, $lsa, $lia, $values);
        }

        $error = $limitsOnly
            ? self::errorControlLimit($type, $values, $lsc, $lic)
            : self::errorControlLimit($type, $values, $lsc, $lic)
                || self::errorWarningLimit($type, $values, $lsa, $lia)
                || self::errorDeviation($type, $values, $s1s, $s1i)
                || self::errorTrend($values, $average, $type === self::BLANCO);
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
     * Veolab 3 (LAB_CalcularPromedioDesviacionCarta): solo la delegación de la carta,
     * los más recientes por fecha de análisis y un valor por operación y técnica (el de
     * la primera columna con valor numérico, la que pone el punto en la carta).
     */
    public static function averageDeviation(string $type, string $section, int $count, array $techniques, int $number = 1, bool $blank = false, ?string $chartDel = null): array
    {
        $average = 0.0;
        $deviation = 0.0;
        if (! $techniques) {
            return [$average, $deviation];
        }

        $query = DB::connection('dynamic')->table('LABCOR')
            ->leftJoin('LABOPE', function ($join) {
                $join->on('LABCOR.OPE3DEL', '=', 'LABOPE.DEL3COD')
                    ->on('LABCOR.OPE3SER', '=', 'LABOPE.OPE1SER')
                    ->on('LABCOR.OPE3COD', '=', 'LABOPE.OPE1COD');
            });
        self::whereColumnChart($query, $type, $number);

        // Veolab 3: sin operaciones anuladas ni valores que no son números (p. ej. "<0,5"); se leen de más para no quedarse cortos.
        $v3 = VeolabVersion::isV3();
        if ($v3) {
            $query->where(fn ($q) => $q->whereNull('LABOPE.OPEBANU')->orWhere('LABOPE.OPEBANU', '<>', 'T'));
        }
        $query
            ->where('LABOPE.OPEBCON', 'T')
            ->where($type === self::EXACTITUD ? 'LABCOR.CORBCON' : 'LABCOR.CORBCOP', 'T')
            ->where('LABCOR.CORCVAL', '<>', 'N/A')->where('LABCOR.CORCVAL', '<>', '')
            ->where(fn ($q) => self::whereTechniques($q, $techniques, 'LABCOR.'));
        if ($v3) {
            if ($chartDel !== null && $chartDel !== '') {
                $query->where('LABOPE.DEL3COD', $chartDel);
            }
            $query->orderByDesc('LABOPE.OPEDINI');
        }
        $query->orderByDesc('LABCOR.OPE3DEL')->orderByDesc('LABCOR.OPE3SER')->orderByDesc('LABCOR.OPE3COD');
        if ($v3) {
            $query->orderBy('LABCOR.TEC3DEL')->orderBy('LABCOR.TEC3COD')->orderBy('LABCOR.COR1COD');
        }
        $rows = $query
            ->limit(max(abs($count), 1) * ($v3 ? 6 : 1))
            ->get(['LABCOR.CORCVAL', 'LABCOR.OPE3DEL', 'LABCOR.OPE3SER', 'LABCOR.OPE3COD', 'LABCOR.TEC3DEL', 'LABCOR.TEC3COD']);
        $values = [];
        $seen = [];
        foreach ($rows as $row) {
            if (count($values) >= max(abs($count), 1)) {
                break;
            }
            if ($v3) {
                if (! preg_match('/^\s*[-+]?[\d.,]*\d[\d.,]*\s*$/', (string) $row->CORCVAL)) {
                    continue;
                }
                $key = $row->OPE3DEL.'|'.$row->OPE3SER.'|'.$row->OPE3COD.'|'.$row->TEC3DEL.'|'.$row->TEC3COD;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $values[] = VeolabOperationServices::decimal($row->CORCVAL);
        }

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

        // Blancos: el valor central es la media de los blancos.
        if ($section === 'F' && ! $blank) {
            $average = $type === self::PRECISION ? 0.0 : 100.0;
        }

        return [$average, $deviation];
    }

    /**
     * Valor de control de una operación para la carta (LAB_ObtenerResultadoControl):
     * el de la primera columna de control del tipo en las técnicas, o 0.
     */
    public static function controlValue(string $type, array $operation, array $techniques, int $number = 1): float
    {
        if (! $techniques) {
            return 0.0;
        }

        $query = DB::connection('dynamic')->table('LABCOR');
        self::whereColumnChart($query, $type, $number);
        $value = $query
            ->where('LABCOR.CORCVAL', '<>', '')->where('LABCOR.CORCVAL', '<>', 'N/A')
            ->where($type === self::EXACTITUD ? 'LABCOR.CORBCON' : 'LABCOR.CORBCOP', 'T')
            ->where('LABCOR.OPE3DEL', $operation[0])->where('LABCOR.OPE3SER', $operation[1])->where('LABCOR.OPE3COD', $operation[2])
            ->where(fn ($q) => self::whereTechniques($q, $techniques, 'LABCOR.'))
            ->orderBy('LABCOR.TEC3DEL')->orderBy('LABCOR.TEC3COD')->orderBy('LABCOR.COR1COD')
            ->value('LABCOR.CORCVAL');

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
    public static function chartFor(string $type, string $tecDel, string $tecCod, array $operation, string $sessionDel, int $number = 1): ?object
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
            ->where(fn ($q) => self::whereChartNumber($q, $number))
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
            ->where(fn ($q) => self::whereChartNumber($q, $number))
            ->where('LABCYT.TEC3DEL', $tecDel)->where('LABCYT.TEC3COD', $tecCod)
            ->orderByDesc('LABCDC.CDC1COD')
            ->first(['LABCDC.*']);
    }

    /** ¿La técnica está bloqueada en la operación por alguna de sus cartas en error? (RevisarBloqueosCartas) */
    public static function blocked(string $tecDel, string $tecCod, array $operation, string $sessionDel): bool
    {
        foreach (self::numbers($tecDel, $tecCod) as $number) {
            foreach ([self::EXACTITUD, self::PRECISION] as $type) {
                $chart = self::chartFor($type, $tecDel, $tecCod, $operation, $sessionDel, $number);
                if ($chart && $chart->CDCCEST === 'E') {
                    return true;
                }
            }
        }

        return false;
    }

    /** Número de carta de un campo (LABCDC.CDCNCAR, LABCOT.COTNCDE/COTNCDP): vacío, 0 o 1 es la principal. */
    public static function chartNumber($value): int
    {
        return (int) $value > 1 ? (int) $value : 1;
    }

    /** Números de carta que tiene la técnica (Veolab 3); en bases 2.4 solo la principal. */
    public static function numbers(string $tecDel, string $tecCod): array
    {
        if (! VeolabVersion::isV3()) {
            return [1];
        }
        $numbers = DB::connection('dynamic')->table('LABCDC')
            ->join('LABCYT', function ($join) {
                $join->on('LABCYT.CDC3DEL', '=', 'LABCDC.DEL3COD')->on('LABCYT.CDC3COD', '=', 'LABCDC.CDC1COD');
            })
            ->where('LABCYT.TEC3DEL', $tecDel)->where('LABCYT.TEC3COD', $tecCod)
            ->distinct()->pluck('LABCDC.CDCNCAR')
            ->map(fn ($n) => self::chartNumber($n))->unique()->values()->all();

        return $numbers ?: [1];
    }

    /**
     * Copia a los puntos de carta de la operación la fecha del análisis (inicio
     * o, si falta, fin) y el analista de la técnica de la carta en LABRES
     * (LAB_SQLActualizarPuntosCarta). Solo Veolab 3; tras grabar LABRES.
     */
    public static function refreshPoints(array $operation): void
    {
        if (VeolabVersion::isV3()) {
            self::updatePoints('OPE2DEL = ? AND OPE2SER = ? AND OPE2COD = ?', [$operation[0], $operation[1], $operation[2]]);
        }
    }

    /** Lo mismo para todos los puntos de una carta (tras sustituir su lista de resultados). */
    public static function refreshChartPoints(string $del, int $cod): void
    {
        if (VeolabVersion::isV3()) {
            self::updatePoints('CDC3DEL = ? AND CDC3COD = ?', [$del, $cod]);
        }
    }

    private static function updatePoints(string $where, array $bindings): void
    {
        $from = ' FROM LABRES INNER JOIN LABCYT ON (LABRES.TEC3DEL = LABCYT.TEC3DEL AND LABRES.TEC3COD = LABCYT.TEC3COD)'
            .' WHERE LABCYT.CDC3DEL = LABRCD.CDC3DEL AND LABCYT.CDC3COD = LABRCD.CDC3COD AND LABRES.OPE3DEL = LABRCD.OPE2DEL'
            .' AND LABRES.OPE3SER = LABRCD.OPE2SER AND LABRES.OPE3COD = LABRCD.OPE2COD';
        DB::connection('dynamic')->update(
            'UPDATE LABRCD SET'
            .' RCDTFEC = COALESCE((SELECT MAX(COALESCE(LABRES.RESTINI, LABRES.RESTFIN))'.$from.'), RCDTFEC),'
            .' EMP2DEL = COALESCE((SELECT MAX(LABRES.EMP2DEL)'.$from.' AND LABRES.EMP2COD IS NOT NULL), EMP2DEL),'
            .' EMP2COD = COALESCE((SELECT MAX(LABRES.EMP2COD)'.$from.'), EMP2COD)'
            .' WHERE '.$where,
            $bindings);
    }

    /**
     * Graba un resultado de control de la operación en la carta de la técnica
     * (AcumulaGrabarControl). Sin carta no hace nada. Con la carta llena
     * (CDCNNUM resultados) abre una nueva con las mismas técnicas y matriz, el
     * promedio y la desviación recalculados y CONNNUM resultados, y cierra la
     * anterior. Acumula en $events las cartas con error, aviso y nuevas.
     * Devuelve false si la técnica no tiene carta del tipo y número (el valor no va a ninguna).
     */
    public static function record(string $type, string $tecDel, string $tecCod, array $operation, string $value,
        string $sessionDel, array &$events, int $number = 1): bool
    {
        $chart = self::chartFor($type, $tecDel, $tecCod, $operation, $sessionDel, $number);
        if (! $chart) {
            return false;
        }

        $db = DB::connection('dynamic');
        $chartDel = (string) $chart->DEL3COD;
        $chartCod = (int) $chart->CDC1COD;
        $section = self::sectionType([[$tecDel, $tecCod]]);
        $results = self::results($chartDel, $chartCod);
        [$rules, $limits, $percent] = self::criteria($chart);
        $evalType = self::evalType($chart);   // E, P o B (blancos)

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
            if ($limits === 'F') {
                // Fijos: los de la carta anterior (p. ej. de la validación del método).
                [$average, $deviation] = [(float) $chart->CDCNPRO, (float) $chart->CDCNDES];
            } elseif ($limits === 'P') {
                // Porcentaje sobre el valor central de la carta anterior.
                $average = (float) $chart->CDCNPRO;
                $deviation = self::percentDeviation($average, $percent);
            } else {
                [$average, $deviation] = self::averageDeviation($type, $section, (int) $chart->CDCNNUM, $techniques, $number, $evalType === self::BLANCO, $chartDel);
            }
            $incidence = self::incidence($evalType, $section, $average, $deviation, [$value], $rules);

            foreach ($techniques as [$del, $cod]) {
                $db->table('LABCYT')->insert(['CDC3DEL' => $sessionDel, 'CDC3COD' => $newCod, 'TEC3DEL' => $del, 'TEC3COD' => $cod]);
            }
            $db->table('LABCDC')->where('DEL3COD', $chartDel)->where('CDC1COD', $chartCod)->update(['CDCDCIE' => $now]);
            $newChart = [
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
            ];
            if (VeolabVersion::isV3()) {
                // La carta nueva sigue la serie de la anterior: mismo número, nombre, nº de resultados y criterios.
                $newChart['CDCNCAR'] = self::chartNumber($chart->CDCNCAR ?? null);
                $newChart['CDCCNOM'] = $chart->CDCCNOM ?? null;
                if ((int) $chart->CDCNNUM > 0) {
                    $newChart['CDCNNUM'] = (int) $chart->CDCNNUM;
                }
                $newChart['CDCCREG'] = $rules;
                $newChart['CDCCLIM'] = $limits;
                $newChart['CDCNPLC'] = $percent > 0 ? $percent : null;
                $newChart['CDCBBLA'] = $evalType === self::BLANCO ? 'T' : null;
            }
            $db->table('LABCDC')->insert($newChart);
            $db->table('LABRCD')->insert(self::resultRow($sessionDel, $newCod, 1, $value, $incidence, $operation));

            VeolabAudit::record(VeolabAudit::INSERCION, 'LABCDC', VeolabCodes::format('LABCDC', (string) $newCod, $sessionDel));
            $events['nuevas'][] = [$sessionDel, $newCod];
            self::incidenceEvent($incidence, $sessionDel, $newCod, $events);

            return true;
        }

        $average = (float) $chart->CDCNPRO;
        $deviation = (float) $chart->CDCNDES;
        $values = array_map(fn ($r) => (string) $r->RCDNVAL, $results);

        // Ventana de resultados: 9 en Veolab 3 (Nordtest necesita 9 seguidos; las reglas de Veolab usan como mucho 7).
        $window = VeolabVersion::isV3() ? 9 : 7;

        if ($position === null) {
            $values[] = $value;
            $incidence = self::incidence($evalType, $section, $average, $deviation, array_slice($values, -$window), $rules);
            $next = $results ? max(array_map(fn ($r) => (int) $r->RCD1COD, $results)) + 1 : 1;
            $db->table('LABRCD')->insert(self::resultRow($chartDel, $chartCod, $next, $value, $incidence, $operation));
        } else {
            $values[$position] = $value;
            $incidence = self::incidence($evalType, $section, $average, $deviation,
                array_slice($values, max(0, $position - $window + 1), min($window, $position + 1)), $rules);
            $db->table('LABRCD')->where('CDC3DEL', $chartDel)->where('CDC3COD', $chartCod)
                ->where('RCD1COD', $results[$position]->RCD1COD)
                ->update(['RCDNVAL' => VeolabOperationServices::decimal($value), 'RCDCTII' => $incidence]);

            // Veolab 3: los resultados posteriores dependen del corregido; se recalculan sus incidencias (el estado no cambia).
            if (VeolabVersion::isV3()) {
                for ($k = $position + 1; $k < count($results); $k++) {
                    $db->table('LABRCD')->where('CDC3DEL', $chartDel)->where('CDC3COD', $chartCod)
                        ->where('RCD1COD', $results[$k]->RCD1COD)
                        ->update(['RCDCTII' => self::incidence($evalType, $section, $average, $deviation,
                            array_slice($values, max(0, $k - $window + 1), min($window, $k + 1)), $rules)]);
                }
            }
        }

        if ($incidence !== '') {
            // El estado no baja de error.
            $db->table('LABCDC')->where('DEL3COD', $chartDel)->where('CDC1COD', $chartCod)->where('CDCCEST', '<>', 'E')
                ->update(['CDCCEST' => $incidence]);
            self::incidenceEvent($incidence, $chartDel, $chartCod, $events);
        }

        return true;
    }

    /**
     * Recalcula las incidencias de todos los resultados de la carta
     * (FichaCarta.ComprobarIncidenciasTodos) y devuelve el estado que deja:
     * el de las incidencias encontradas sin bajar de error, o el actual si
     * no hay ninguna.
     */
    public static function recheck(string $del, int $cod, string $state): string
    {
        $chart = DB::connection('dynamic')->table('LABCDC')->where('DEL3COD', $del)->where('CDC1COD', $cod)->first();
        $section = self::sectionType(self::techniques($del, $cod));
        $results = self::results($del, $cod);
        $values = array_map(fn ($r) => (string) $r->RCDNVAL, $results);
        [$rules] = self::criteria($chart);
        $window = VeolabVersion::isV3() ? 9 : 8;

        foreach ($results as $i => $row) {
            $incidence = self::incidence(self::evalType($chart), $section, (float) $chart->CDCNPRO, (float) $chart->CDCNDES,
                array_slice($values, max(0, $i - $window + 1), min($window, $i + 1)), $rules);
            if ($incidence !== (string) $row->RCDCTII) {
                DB::connection('dynamic')->table('LABRCD')
                    ->where('CDC3DEL', $del)->where('CDC3COD', $cod)->where('RCD1COD', $row->RCD1COD)
                    ->update(['RCDCTII' => $incidence]);
            }
            // Veolab 3 no baja de error a aviso; Veolab 2.4 deja el de la última incidencia.
            if (VeolabVersion::isV3()
                ? $incidence === 'E' || ($incidence === 'A' && $state !== 'E')
                : $incidence !== '') {
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

    /**
     * Reglas de Nordtest TR 569 (Laboratorio.IncumplimientoNordtest). Error:
     * 1 punto fuera del límite de control, 2 de los 3 últimos fuera del de
     * advertencia por el mismo lado (el último incluido) o 9 seguidos al mismo
     * lado del valor central. Aviso: 1 punto entre advertencia y control o 6
     * seguidos subiendo o bajando. Precisión: solo el lado superior.
     */
    private static function nordtest(string $type, bool $limitsOnly, float $average, float $lsc, float $lic, float $lsa, float $lia, array $v): string
    {
        $n = count($v);
        $last = $v[$n - 1];
        $both = $type === self::EXACTITUD;

        if ($last > $lsc || ($both && $last < $lic)) {
            return 'E';
        }
        if (! $limitsOnly) {
            if ($n >= 2) {
                $window = array_slice($v, -3);
                $up = count(array_filter($window, fn ($x) => $x > $lsa));
                $down = count(array_filter($window, fn ($x) => $x < $lia));
                if (($last > $lsa && $up >= 2) || ($both && $last < $lia && $down >= 2)) {
                    return 'E';
                }
            }
            if ($n >= 9) {
                $last9 = array_slice($v, -9);
                if (count(array_filter($last9, fn ($x) => $x > $average)) === 9
                    || ($both && count(array_filter($last9, fn ($x) => $x < $average)) === 9)) {
                    return 'E';
                }
            }
        }
        if ($last > $lsa || ($both && $last < $lia)) {
            return 'A';
        }

        return ! $limitsOnly && self::monotonic($v, 6, $type === self::BLANCO) ? 'A' : '';
    }

    /** Tipo de la carta para las reglas: E, P o B (exactitud marcada como carta de blancos, solo Veolab 3). */
    public static function evalType(object $chart): string
    {
        $type = (string) $chart->CDCCTIP;

        return $type === self::EXACTITUD && VeolabVersion::isV3() && ($chart->CDCBBLA ?? '') === 'T' ? self::BLANCO : $type;
    }

    /** Criterios de la carta: [reglas V|N, límites C|F|P, porcentaje] (Veolab 3; en 2.4, V y C). */
    public static function criteria(object $chart): array
    {
        if (! VeolabVersion::isV3()) {
            return ['V', 'C', 0.0];
        }
        $rules = ($chart->CDCCREG ?? '') === 'N' ? 'N' : 'V';
        $limits = in_array($chart->CDCCLIM ?? '', ['F', 'P'], true) ? (string) $chart->CDCCLIM : 'C';

        return [$rules, $limits, (float) ($chart->CDCNPLC ?? 0)];
    }

    /** Desviación que deja el límite de control en el valor central ± el porcentaje (LAB_DesviacionPorcentaje). */
    public static function percentDeviation(float $average, float $percent): float
    {
        return round(abs($average) * $percent / 300, 4);
    }

    /** Solo las cartas con ese número (Veolab 3; en bases 2.4 no hay números y no filtra). */
    private static function whereChartNumber($query, int $number): void
    {
        if (! VeolabVersion::isV3()) {
            return;
        }
        if ($number > 1) {
            $query->where('LABCDC.CDCNCAR', $number);
        } else {
            $query->whereNull('LABCDC.CDCNCAR')->orWhere('LABCDC.CDCNCAR', '<=', 1);
        }
    }

    /**
     * Une LABCOR con su columna de la técnica (LABCOT) y deja solo las columnas
     * que van a la carta $number del tipo (LAB_WhereNumeroCarta). Solo Veolab 3.
     */
    private static function whereColumnChart($query, string $type, int $number): void
    {
        if (! VeolabVersion::isV3()) {
            return;
        }
        $field = $type === self::EXACTITUD ? 'LABCOT.COTNCDE' : 'LABCOT.COTNCDP';
        $query->leftJoin('LABCOT', function ($join) {
            $join->on('LABCOR.TEC3DEL', '=', 'LABCOT.TEC3DEL')
                ->on('LABCOR.TEC3COD', '=', 'LABCOT.TEC3COD')
                ->on('LABCOR.COR1COD', '=', 'LABCOT.COT1COD');
        });
        if ($number > 1) {
            $query->where($field, $number);
        } else {
            $query->where(fn ($q) => $q->whereNull($field)->orWhere($field, '<=', 1));
        }
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
        // Tendencia con siete puntos seguidos en Veolab 3 (con cinco, los de Veolab 2.4, saltaba por azar).
        $monotonic = self::monotonic($v, VeolabVersion::isV3() ? 7 : 5, $type === self::BLANCO);
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
        // Tendencia con seis puntos seguidos en Veolab 3, como la ISO 7870-2 (con cuatro, los de
        // Veolab 2.4, saltaba en el 8 % de los puntos).
        $monotonic = self::monotonic($v, VeolabVersion::isV3() ? 6 : 4, $type === self::BLANCO);
        $up = (int) ($r1 > $s1s) + (int) ($r2 > $s1s) + (int) ($r3 > $s1s) + (int) ($r4 > $s1s) >= 4;
        if ($type !== self::EXACTITUD) {
            return $up || $monotonic;
        }
        $down = (int) ($r1 < $s1i) + (int) ($r2 < $s1i) + (int) ($r3 < $s1i) + (int) ($r4 < $s1i) >= 4;

        return $up || $down || $monotonic;
    }

    /**
     * Los últimos $n valores van todos en orden creciente o todos en orden
     * decreciente (dos iguales seguidos rompen la tendencia). Falso si no hay
     * suficientes (Laboratorio.PuntosMonotonos). $onlyUp: blancos, donde bajar no es un problema.
     */
    private static function monotonic(array $v, int $n, bool $onlyUp = false): bool
    {
        if (count($v) < $n) {
            return false;
        }
        $w = array_map('floatval', array_slice($v, -$n));
        $up = $down = true;
        for ($i = 1; $i < $n; $i++) {
            $up = $up && $w[$i] > $w[$i - 1];
            $down = $down && $w[$i] < $w[$i - 1];
        }

        return $up || ($down && ! $onlyUp);
    }

    /** 7 seguidos al mismo lado del valor central ($onlyAbove: blancos, solo por encima). */
    private static function errorTrend(array $v, float $average, bool $onlyAbove = false): bool
    {
        if (count($v) <= 6) {
            return false;
        }
        $last7 = array_slice($v, -7);

        return count(array_filter($last7, fn ($x) => $x > $average)) === 7
            || (! $onlyAbove && count(array_filter($last7, fn ($x) => $x < $average)) === 7);
    }
}
