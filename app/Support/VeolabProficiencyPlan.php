<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ensayos de aptitud, fase 2 (módulo CAL, desde Veolab 3; ISO/IEC 17025:2017
 * 7.7.2, ENAC NT-03): plan de intercomparaciones por familias de ensayos
 * (CALFAE con sus parámetros en CALFAT) y tendencia de las puntuaciones por
 * parámetro con los avisos de racha. Réplica de Aptitud.bas
 * (EAP_CalcularPlan, EAP_SQLParametrosSinFamilia, EAP_ResumenPlan,
 * EAP_ResumenResultadosAnio, EAP_LeerPuntos, EAP_MarcarAvisos y
 * EAP_ResultadosConAviso).
 *
 * Veolab limita las consultas a la delegación del usuario y a las públicas
 * (EAP_SQLDelegacion); la API no tiene sesión: lo hace con $delegation
 * (null = todas).
 */
class VeolabProficiencyPlan
{
    /** Cuestionables seguidos de un parámetro que dan aviso (EAP_RACHA_CUESTIONABLES). */
    public const STREAK_QUESTIONABLE = 2;

    /** Puntuaciones seguidas del mismo signo que dan aviso de posible sesgo (EAP_RACHA_SESGO). */
    public const STREAK_BIAS = 4;

    /** Frecuencia máxima que ofrece la ficha de la familia, en años (EAP_FRECUENCIA_MAXIMA). */
    public const MAX_FREQUENCY = 10;

    /** Años anteriores al actual que muestra el plan por defecto (EAP_ANIOS_ATRAS_PLAN). */
    public const YEARS_BACK = 4;

    /** Años como máximo en el cuadro (EAP_MAX_ANIOS_PLAN). */
    public const MAX_YEARS = 8;

    /** Situación de la familia: al día, le toca este año, sin participación en su periodo (EAP_SITUACION_*). */
    public const SITUATION_NAMES = [
        'A' => 'Al día',
        'E' => 'Prevista este año',
        'S' => 'Sin participación en su periodo',
    ];

    /**
     * Contenido de un año del cuadro: realizado con la peor evaluación (S, Q,
     * I), en curso (R: resultados aún sin evaluar), previsto (V) y no
     * realizado (N). Sin nada, null (EAP_ANIO_*, EAP_TextoAnio).
     */
    public const YEAR_NAMES = [
        'S' => 'Satisfactorio',
        'Q' => 'Cuestionable',
        'I' => 'Insatisfactorio',
        'R' => 'En curso',
        'V' => 'Previsto',
        'N' => 'No realizado',
    ];

    public const EVALUATION_NAMES = [
        'S' => 'Satisfactorio',
        'Q' => 'Cuestionable',
        'I' => 'Insatisfactorio',
        'C' => 'Correcto',
        'P' => 'Falso positivo',
        'N' => 'Falso negativo',
    ];

    /** Gravedad de lo realizado en un año (NivelAnio): nada, en curso, satisfactorio, cuestionable, insatisfactorio. */
    private const LEVELS = [1 => '-', 2 => 'R', 3 => 'S', 4 => 'Q', 5 => 'I'];

    // ------------------------------------------------------------------
    // Plan de intercomparaciones
    // ------------------------------------------------------------------

    /** Año actual (el del servidor de base de datos, como el resto de fechas de la API). */
    public static function currentYear(): int
    {
        return (int) substr((string) DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d, 0, 4);
    }

    /** EAP_TextoFrecuencia: «Cada año», «Cada 3 años». */
    public static function frequencyText(int $years): string
    {
        return $years <= 1 ? 'Cada año' : "Cada {$years} años";
    }

    /** Condición de las filas de una delegación y las públicas (EAP_SQLDelegacion); null, sin condición. */
    private static function whereDelegation($query, string $column, ?string $delegation): void
    {
        if ($delegation !== null && $delegation !== '') {
            $query->where(fn ($q) => $q->where($column, '')->orWhere($column, $delegation));
        }
    }

    /**
     * Años del cuadro como PlanAptitud: por defecto los YEARS_BACK anteriores
     * y el actual; hasta < desde, hasta = desde; como máximo MAX_YEARS (se
     * mueve el principio). Devuelve [desde, hasta].
     */
    public static function yearRange(?int $from, ?int $to, int $current): array
    {
        $to ??= $from !== null ? min($from + self::YEARS_BACK, $current) : $current;
        $from ??= $to - self::YEARS_BACK;
        if ($to < $from) {
            $to = $from;
        }
        if ($to - $from + 1 > self::MAX_YEARS) {
            $from = $to - self::MAX_YEARS + 1;
        }

        return [$from, $to];
    }

    /**
     * EAP_CalcularPlan: familias (sin las de baja salvo $withInactive) con
     * sus parámetros y, por cada año de $from a $to, lo realizado (rondas con
     * resultados de alguno de sus parámetros, por el año de la recepción de
     * la muestra o, si falta, de la fecha límite, con la peor evaluación de
     * esos resultados; sin evaluar, «en curso») y lo previsto: la próxima
     * participación toca en el año de la última (hasta el actual) más la
     * frecuencia y luego cada tantos años; sin ninguna, en el actual. Si ese
     * año ya pasó, la familia queda sin participación en su periodo y los
     * años en que le tocaba salen como no realizados.
     */
    public static function families(int $from, int $to, bool $withInactive, ?string $delegation, int $current): array
    {
        $db = DB::connection('dynamic');
        $count = $to - $from + 1;

        $query = $db->table('CALFAE')
            ->leftJoin('SINPRO', fn ($j) => $j->on('CALFAE.PRO2DEL', '=', 'SINPRO.DEL3COD')->on('CALFAE.PRO2COD', '=', 'SINPRO.PRO1COD'))
            ->orderBy('CALFAE.FAECNOM')->orderBy('CALFAE.FAE1COD');
        self::whereDelegation($query, 'CALFAE.DEL3COD', $delegation);
        if (! $withInactive) {
            $query->where(fn ($q) => $q->whereNull('CALFAE.FAEBBAJ')->orWhere('CALFAE.FAEBBAJ', '<>', 'T'));
        }

        $families = [];
        $levels = [];
        $last = [];
        $rounds = [];
        foreach ($query->get(['CALFAE.DEL3COD', 'CALFAE.FAE1COD', 'CALFAE.FAECNOM', 'CALFAE.FAENFRE', 'CALFAE.FAECPRG',
            'CALFAE.PRO2DEL', 'CALFAE.PRO2COD', 'CALFAE.FAEBBAJ', 'SINPRO.PROCNOM']) as $f) {
            $key = $f->DEL3COD."\x1B".(int) $f->FAE1COD;
            $frequency = max(1, (int) $f->FAENFRE);
            $families[$key] = [
                'delegacion'             => (string) $f->DEL3COD,
                'codigo'                 => (int) $f->FAE1COD,
                'nombre'                 => $f->FAECNOM,
                'frecuencia'             => $frequency,
                'frecuencia_texto'       => self::frequencyText($frequency),
                'programa'               => $f->FAECPRG,
                'organizador_delegacion' => (string) $f->PRO2COD === '' ? null : $f->PRO2DEL,
                'organizador_codigo'     => (string) $f->PRO2COD === '' ? null : $f->PRO2COD,
                'organizador_nombre'     => $f->PROCNOM,
                'baja'                   => $f->FAEBBAJ === 'T' ? 'T' : 'F',
                'parametros'             => [],
                'numero_parametros'      => 0,
            ];
            $levels[$key] = array_fill(0, $count, 1);
            $last[$key] = 0;
            $rounds[$key] = [];
        }
        if (! $families) {
            return [];
        }

        // Parámetros de cada familia
        $params = $db->table('CALFAT')
            ->leftJoin('LABTEC', fn ($j) => $j->on('CALFAT.TEC3DEL', '=', 'LABTEC.DEL3COD')->on('CALFAT.TEC3COD', '=', 'LABTEC.TEC1COD'))
            ->orderBy('LABTEC.TECCNOM')->orderBy('CALFAT.TEC3COD')
            ->get(['CALFAT.DEL3COD', 'CALFAT.FAE3COD', 'CALFAT.TEC3DEL', 'CALFAT.TEC3COD', 'LABTEC.TECCNOM']);
        foreach ($params as $p) {
            $key = $p->DEL3COD."\x1B".(int) $p->FAE3COD;
            if (isset($families[$key])) {
                $families[$key]['parametros'][] = [
                    'parametro_delegacion' => (string) $p->TEC3DEL,
                    'parametro_codigo'     => (string) $p->TEC3COD,
                    'parametro_nombre'     => trim((string) $p->TECCNOM) === '' ? (string) $p->TEC3COD : trim((string) $p->TECCNOM),
                ];
                $families[$key]['numero_parametros']++;
            }
        }

        // Rondas con resultados de sus parámetros, con la peor evaluación de esos resultados
        // (CodigoEvaluacion: correcto cuenta como satisfactorio y los falsos como insatisfactorio)
        $query = $db->table('CALFAT')
            ->join('CALEAR', fn ($j) => $j->on('CALEAR.TEC2DEL', '=', 'CALFAT.TEC3DEL')->on('CALEAR.TEC2COD', '=', 'CALFAT.TEC3COD'))
            ->join('CALEAP', fn ($j) => $j->on('CALEAP.DEL3COD', '=', 'CALEAR.DEL3COD')->on('CALEAP.EAP1SER', '=', 'CALEAR.EAP3SER')
                ->on('CALEAP.EAP1COD', '=', 'CALEAR.EAP3COD'))
            ->whereRaw('COALESCE(CALEAP.EAPDREC, CALEAP.EAPDLIM) IS NOT NULL')
            ->groupBy('CALFAT.DEL3COD', 'CALFAT.FAE3COD', 'CALEAP.DEL3COD', 'CALEAP.EAP1SER', 'CALEAP.EAP1COD', 'CALEAP.EAPDREC', 'CALEAP.EAPDLIM');
        self::whereDelegation($query, 'CALEAP.DEL3COD', $delegation);
        $rows = $query->get([
            'CALFAT.DEL3COD AS FAMDEL', 'CALFAT.FAE3COD', 'CALEAP.DEL3COD', 'CALEAP.EAP1SER', 'CALEAP.EAP1COD',
            DB::raw('COALESCE(CALEAP.EAPDREC, CALEAP.EAPDLIM) AS FECHA'),
            DB::raw("MAX(CASE WHEN CALEAR.EARCEVA IN ('I', 'P', 'N') THEN 5 WHEN CALEAR.EARCEVA = 'Q' THEN 4 "
                ."WHEN CALEAR.EARCEVA IN ('S', 'C') THEN 3 ELSE 2 END) AS NIVEL"),
        ]);
        foreach ($rows as $r) {
            $key = $r->FAMDEL."\x1B".(int) $r->FAE3COD;
            $year = (int) substr((string) $r->FECHA, 0, 4);
            if (! isset($families[$key]) || $year <= 0) {
                continue;
            }
            if ($year <= $current && $year > $last[$key]) {
                $last[$key] = $year;
            }
            if ($year >= $from && $year <= $to) {
                $i = $year - $from;
                $levels[$key][$i] = max($levels[$key][$i], (int) $r->NIVEL);
                $rounds[$key][$r->DEL3COD."\x1B".$r->EAP1SER."\x1B".$r->EAP1COD] = true;
            }
        }

        // Próxima participación, situación y años previstos o no realizados
        $out = [];
        foreach ($families as $key => $family) {
            $frequency = $family['frecuencia'];
            if ($last[$key] === 0) {
                $next = $current;
                $situation = 'S';
            } else {
                $next = $last[$key] + $frequency;
                $situation = $next < $current ? 'S' : ($next === $current ? 'E' : 'A');
            }
            $years = [];
            for ($year = $from; $year <= $to; $year++) {
                $code = self::LEVELS[$levels[$key][$year - $from]];
                if ($code === '-' && $year >= $next && ($year - $next) % $frequency === 0) {
                    $code = $year < $current ? 'N' : 'V';
                }
                $years[] = ['anio' => $year, 'estado' => $code === '-' ? null : $code, 'texto' => self::YEAR_NAMES[$code] ?? null];
            }
            $out[] = $family + [
                'ultima'          => $last[$key] ?: null,
                'proxima'         => $next,
                'situacion'       => $situation,
                'situacion_texto' => self::SITUATION_NAMES[$situation],
                'rondas'          => count($rounds[$key]),
                'anios'           => $years,
            ];
        }

        return $out;
    }

    /**
     * EAP_SQLParametrosSinFamilia: parámetros acreditados (LABTEC.TECDACR) y
     * no de baja que no están en ninguna familia activa (la cobertura del
     * alcance).
     */
    public static function parametersWithoutFamily(?string $delegation): array
    {
        $query = DB::connection('dynamic')->table('LABTEC')
            ->whereNotNull('LABTEC.TECDACR')
            ->where(fn ($q) => $q->whereNull('LABTEC.TECBBAJ')->orWhere('LABTEC.TECBBAJ', '<>', 'T'))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('CALFAT')
                ->join('CALFAE', fn ($j) => $j->on('CALFAE.DEL3COD', '=', 'CALFAT.DEL3COD')->on('CALFAE.FAE1COD', '=', 'CALFAT.FAE3COD'))
                ->whereColumn('CALFAT.TEC3DEL', 'LABTEC.DEL3COD')->whereColumn('CALFAT.TEC3COD', 'LABTEC.TEC1COD')
                ->where(fn ($q) => $q->whereNull('CALFAE.FAEBBAJ')->orWhere('CALFAE.FAEBBAJ', '<>', 'T')))
            ->orderBy('LABTEC.TECCNOM')->orderBy('LABTEC.TEC1COD');
        self::whereDelegation($query, 'LABTEC.DEL3COD', $delegation);

        return $query->get(['LABTEC.DEL3COD', 'LABTEC.TEC1COD', 'LABTEC.TECCNOM', 'LABTEC.TECDACR'])
            ->map(fn ($t) => [
                'parametro_delegacion' => (string) $t->DEL3COD,
                'parametro_codigo'     => (string) $t->TEC1COD,
                'parametro_nombre'     => trim((string) $t->TECCNOM) === '' ? (string) $t->TEC1COD : trim((string) $t->TECCNOM),
                'fecha_acreditacion'   => $t->TECDACR,
            ])->all();
    }

    /**
     * EAP_ResumenPlan: familias que no están de baja al día, con
     * participación prevista este año y sin participación en su periodo, y
     * parámetros acreditados sin familia (MEN00852).
     */
    public static function summary(array $families, int $withoutFamily): array
    {
        $counts = ['A' => 0, 'E' => 0, 'S' => 0];
        foreach ($families as $family) {
            if ($family['baja'] !== 'T') {
                $counts[$family['situacion'] === 'A' || $family['situacion'] === 'E' ? $family['situacion'] : 'S']++;
            }
        }
        $total = array_sum($counts);

        return [
            'familias'          => $total,
            'al_dia'            => $counts['A'],
            'este_anio'         => $counts['E'],
            'sin_participacion' => $counts['S'],
            'sin_familia'       => $withoutFamily,
            'texto'             => "{$total} familias: {$counts['A']} al día, {$counts['E']} con participación prevista este año y "
                ."{$counts['S']} sin participación en su periodo. Parámetros acreditados sin familia: {$withoutFamily}.",
        ];
    }

    /**
     * EAP_ResumenResultadosAnio: resultados de las rondas de un año (por la
     * recepción o, si falta, la fecha límite) con cuántos hay de cada
     * evaluación (MEN00853: «2026: 12 resultados en 4 rondas (10
     * Satisfactorio, 1 Cuestionable, 1 Insatisfactorio).»).
     */
    public static function yearResults(int $year, ?string $delegation): array
    {
        $query = DB::connection('dynamic')->table('CALEAR')
            ->join('CALEAP', fn ($j) => $j->on('CALEAP.DEL3COD', '=', 'CALEAR.DEL3COD')->on('CALEAP.EAP1SER', '=', 'CALEAR.EAP3SER')
                ->on('CALEAP.EAP1COD', '=', 'CALEAR.EAP3COD'));
        VeolabProficiency::applyYear($query, $year);
        self::whereDelegation($query, 'CALEAP.DEL3COD', $delegation);
        $rows = $query->get(['CALEAR.DEL3COD', 'CALEAR.EAP3SER', 'CALEAR.EAP3COD', 'CALEAR.EARCEVA']);

        $rounds = [];
        $byEvaluation = [];
        foreach ($rows as $r) {
            $rounds[$r->DEL3COD."\x1B".$r->EAP3SER."\x1B".$r->EAP3COD] = true;
            $letter = (string) $r->EARCEVA;
            if ($letter !== '') {
                $byEvaluation[$letter] = ($byEvaluation[$letter] ?? 0) + 1;
            }
        }
        $detail = [];
        $ordered = [];
        foreach (VeolabProficiency::EVALUATIONS as $letter) {
            if (isset($byEvaluation[$letter])) {
                $ordered[$letter] = $byEvaluation[$letter];
                $detail[] = $byEvaluation[$letter].' '.self::EVALUATION_NAMES[$letter];
            }
        }
        $total = $rows->count();

        return [
            'anio'            => $year,
            'resultados'      => $total,
            'rondas'          => count($rounds),
            'por_evaluacion'  => (object) $ordered,
            'texto'           => "{$year}: {$total} resultados en ".count($rounds).' rondas ('.($detail ? implode(', ', $detail) : '-').').',
        ];
    }

    // ------------------------------------------------------------------
    // Tendencia por parámetro
    // ------------------------------------------------------------------

    /**
     * EAP_LeerPuntos: resultados de las rondas en el orden del tiempo, de un
     * parámetro o, sin él, de todos (ordenados por parámetro, fecha —sin
     * fecha, al final—, ronda y línea). La puntuación es la del organizador
     * o, si no la da, la calculada por Veolab; los cualitativos no tienen.
     */
    public static function points(?string $tecDel, ?string $tecCod, ?string $delegation): array
    {
        $query = DB::connection('dynamic')->table('CALEAR')
            ->join('CALEAP', fn ($j) => $j->on('CALEAP.DEL3COD', '=', 'CALEAR.DEL3COD')->on('CALEAP.EAP1SER', '=', 'CALEAR.EAP3SER')
                ->on('CALEAP.EAP1COD', '=', 'CALEAR.EAP3COD'))
            ->whereNotNull('CALEAR.TEC2COD')->where('CALEAR.TEC2COD', '<>', '');
        self::whereDelegation($query, 'CALEAR.DEL3COD', $delegation);
        if ($tecCod !== null && $tecCod !== '') {
            $query->where('CALEAR.TEC2DEL', (string) $tecDel)->where('CALEAR.TEC2COD', $tecCod);
        }
        $rows = $query->get(['CALEAR.DEL3COD', 'CALEAR.EAP3SER', 'CALEAR.EAP3COD', 'CALEAR.EAR1COD', 'CALEAR.TEC2DEL', 'CALEAR.TEC2COD',
            'CALEAR.EARCPAR', 'CALEAR.EARCTPU', 'CALEAR.EARBLOG', 'CALEAR.EARNPOR', 'CALEAR.EARNPCA', 'CALEAR.EARCEVA', 'CALEAP.EAPDREC',
            'CALEAP.EAPDLIM', 'CALEAP.EAPCPRG', 'CALEAP.EAPCRON', 'CALEAP.EAPNLSA', 'CALEAP.EAPNLIN', 'CALEAP.EAPNLEN']);

        $limit = fn ($value, float $default) => $value !== null && (float) $value > 0 ? (float) $value : $default;
        $points = [];
        foreach ($rows as $r) {
            $date = $r->EAPDREC ?: $r->EAPDLIM;
            $date = $date ? substr((string) $date, 0, 10) : null;
            $roundName = trim((string) $r->EAPCPRG);
            if (trim((string) $r->EAPCRON) !== '') {
                $roundName .= ($roundName !== '' ? ' - ' : '').trim((string) $r->EAPCRON);
            }
            $score = null;
            $calculated = false;
            if ((string) $r->EARCTPU !== 'C') {
                if ($r->EARNPOR !== null) {
                    $score = (float) $r->EARNPOR;
                } elseif ($r->EARNPCA !== null) {
                    $score = (float) $r->EARNPCA;
                    $calculated = true;
                }
            }
            $points[] = [
                'ensayo_delegacion'      => (string) $r->DEL3COD,
                'ensayo_serie'           => (string) $r->EAP3SER,
                'ensayo_codigo'          => (int) $r->EAP3COD,
                'codigo'                 => (int) $r->EAR1COD,
                'parametro_delegacion'   => (string) $r->TEC2DEL,
                'parametro_codigo'       => (string) $r->TEC2COD,
                'parametro_nombre'       => $r->EARCPAR,
                'fecha'                  => $date,
                'ronda'                  => $roundName,
                'tipo_puntuacion'        => $r->EARCTPU,
                'sobre_log10'            => $r->EARBLOG === 'T' ? 'T' : 'F',
                'puntuacion'             => $score,
                'puntuacion_calculada'   => $calculated ? 'T' : 'F',
                'evaluacion'             => (string) $r->EARCEVA === '' ? null : (string) $r->EARCEVA,
                'limite_satisfactorio'   => $limit($r->EAPNLSA, VeolabProficiency::LIMIT_SATISFACTORY),
                'limite_insatisfactorio' => $limit($r->EAPNLIN, VeolabProficiency::LIMIT_UNSATISFACTORY),
                'limite_en'              => $limit($r->EAPNLEN, VeolabProficiency::LIMIT_EN),
                'aviso'                  => null,
                '_orden'                 => implode("\0", [(string) $r->TEC2DEL, (string) $r->TEC2COD,
                    $date ? str_replace('-', '', $date) : '99999999', (string) $r->DEL3COD, (string) $r->EAP3SER,
                    sprintf('%010d', (int) $r->EAP3COD).sprintf('%05d', (int) $r->EAR1COD)]),
            ];
        }
        usort($points, fn ($a, $b) => strcmp($a['_orden'], $b['_orden']));
        foreach ($points as &$point) {
            unset($point['_orden']);
        }
        unset($point);

        return $points;
    }

    /** SignoPuntuacion: signo de la puntuación redondeada a 2 decimales (Round de VB6: al par). */
    private static function sign(float $score): int
    {
        return round($score, 2, PHP_ROUND_HALF_EVEN) <=> 0.0;
    }

    /** TextoFechasRacha: «12/03/2025 - 14/10/2026», la primera y la última fecha de la racha. */
    private static function streakDates(array $points, int $first, int $last): string
    {
        $fmt = fn (?string $d) => $d ? (new \DateTime($d))->format('d/m/Y') : '';
        $text = $fmt($points[$first]['fecha']);
        if ($last !== $first && $points[$last]['fecha']) {
            $text .= ' - '.$fmt($points[$last]['fecha']);
        }

        return $text;
    }

    /**
     * EAP_MarcarAvisos, política por defecto de tendencia de un parámetro
     * (Eurachem; ENAC NT-03 deja la política al laboratorio), sobre sus puntos
     * de $first a $last, ya ordenados en el tiempo:
     *  - cuestionables repetidos: STREAK_QUESTIONABLE o más evaluaciones
     *    cuestionables seguidas (un resultado sin evaluar no cuenta ni corta);
     *  - sesgo: STREAK_BIAS o más puntuaciones seguidas del mismo signo (los
     *    puntos sin puntuación no cuentan ni cortan; una puntuación cero corta).
     * Marca 'aviso' (Q, S o QS) de cada punto de una racha y devuelve las
     * rachas {tipo, desde, hasta (posiciones en $points), numero, signo, texto}.
     */
    public static function markWarnings(array &$points, int $first, int $last): array
    {
        $streaks = [];
        for ($i = $first; $i <= $last; $i++) {
            $points[$i]['aviso'] = '';
        }

        // Cuestionables seguidos (el paso tras el último cierra la racha que quede abierta)
        $count = 0;
        $start = $end = $first;
        for ($i = $first; $i <= $last + 1; $i++) {
            $inStreak = false;
            if ($i <= $last) {
                $evaluation = (string) ($points[$i]['evaluacion'] ?? '');
                if ($evaluation === '') {
                    continue;   // Sin evaluar: no cuenta ni corta
                }
                $inStreak = $evaluation === 'Q';
            }
            if ($inStreak) {
                if ($count === 0) {
                    $start = $i;
                }
                $count++;
                $end = $i;
                continue;
            }
            if ($count >= self::STREAK_QUESTIONABLE) {
                for ($j = $start; $j <= $end; $j++) {
                    if (($points[$j]['evaluacion'] ?? '') === 'Q') {
                        $points[$j]['aviso'] .= 'Q';
                    }
                }
                $streaks[] = ['tipo' => 'Q', 'desde' => $start, 'hasta' => $end, 'numero' => $count, 'signo' => null,
                    'texto' => "{$count} cuestionables seguidos (".self::streakDates($points, $start, $end)
                        .'): revise la causa aunque cada uno por separado no requiera acción.'];
            }
            $count = 0;
        }

        // Puntuaciones seguidas del mismo signo
        $count = 0;
        $sign = 0;
        for ($i = $first; $i <= $last + 1; $i++) {
            $current = 0;
            if ($i <= $last) {
                if ($points[$i]['puntuacion'] === null) {
                    continue;   // Cualitativos y sin puntuación: no cuentan ni cortan
                }
                $current = self::sign((float) $points[$i]['puntuacion']);
            }
            if ($current !== 0 && $current === $sign) {
                $count++;
                $end = $i;
                continue;
            }
            if ($count >= self::STREAK_BIAS) {
                for ($j = $start; $j <= $end; $j++) {
                    if ($points[$j]['puntuacion'] !== null) {
                        $points[$j]['aviso'] .= 'S';
                    }
                }
                $streaks[] = ['tipo' => 'S', 'desde' => $start, 'hasta' => $end, 'numero' => $count, 'signo' => $sign,
                    'texto' => "{$count} puntuaciones seguidas ".($sign > 0 ? 'por encima' : 'por debajo').' del valor asignado ('
                        .self::streakDates($points, $start, $end).'): posible sesgo; investigue la causa y valore abrir una incidencia.'];
            }
            $sign = $current;
            if ($current !== 0) {
                $count = 1;
                $start = $end = $i;
            } else {
                $count = 0;
            }
        }

        for ($i = $first; $i <= $last; $i++) {
            if ($points[$i]['aviso'] === '') {
                $points[$i]['aviso'] = null;
            }
        }

        return $streaks;
    }

    /**
     * EAP_ResultadosConAviso: resultados de todos los parámetros que están en
     * una racha de aviso. Clave "delegación\x1Bserie\x1Bcódigo\x1Blínea" =>
     * aviso (Q, S o QS).
     */
    public static function resultsWithWarnings(?string $delegation = null): array
    {
        $points = self::points(null, null, $delegation);
        $count = count($points);
        $first = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($i === $count - 1 || $points[$i + 1]['parametro_delegacion'] !== $points[$i]['parametro_delegacion']
                || $points[$i + 1]['parametro_codigo'] !== $points[$i]['parametro_codigo']) {
                self::markWarnings($points, $first, $i);
                $first = $i + 1;
            }
        }
        $out = [];
        foreach ($points as $p) {
            if ($p['aviso'] !== null) {
                $out[$p['ensayo_delegacion']."\x1B".$p['ensayo_serie']."\x1B".$p['ensayo_codigo']."\x1B".$p['codigo']] = $p['aviso'];
            }
        }

        return $out;
    }

    /** Año de un parámetro de consulta (null si falta; BusinessRuleException si no es un año). */
    public static function yearParam($value, string $name): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}$/', $value)) {
            throw new \App\Exceptions\BusinessRuleException("El parámetro {$name} debe ser un año de cuatro cifras");
        }

        return (int) $value;
    }
}
