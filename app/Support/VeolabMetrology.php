<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patrones y materiales de referencia (Veolab 3, ISO/IEC 17025 6.5, 6.4.4,
 * 6.4.13 y 6.4.9; «Analisis patrones y materiales de referencia.md» del
 * proyecto de Veolab, apartado 5).
 *
 *  - Producto (ALMPRD): tipo metrológico PRDCTPR (R patrón de referencia, T
 *    patrón de trabajo, C material de referencia certificado, M material de
 *    referencia o control; vacío o NULL, producto normal) y días de validez
 *    tras abrir PRDNDAP.
 *  - Serie o lote (ALMSEL): datos del certificado SELCVCE (valor), SELCINU
 *    (incertidumbre), SELCTRZ (trazabilidad), SELCNCE (número), SELDCER
 *    (fecha) y SELBACR (productor o laboratorio acreditado, T/F).
 *  - Caducidad efectiva: la menor entre la caducidad (SELDCAD) y la fecha de
 *    apertura (SELDAPE) más los días de validez tras abrir; null si no hay
 *    ninguna.
 *  - Cadena de trazabilidad y operaciones afectadas: sin tablas nuevas, de
 *    los usos y consumos (ALMMOV U/O) y de las operaciones de calibración o
 *    verificación de la serie (LABOPE.PRD2* y SEL2COD con el tipo reservado
 *    TIO2DEL = '' y TIO2COD 2 o 3, ALM_CampoFechaTipo).
 *
 * Las columnas las crea el script de conversión de los patrones: en una base
 * de Veolab 3 sin él, available() es falso y los campos no se ofrecen.
 */
class VeolabMetrology
{
    /** Tipos metrológicos (ALMPRD.PRDCTPR). */
    public const TYPES = [
        'R' => 'Patrón de referencia',
        'T' => 'Patrón de trabajo',
        'C' => 'Material de referencia certificado',
        'M' => 'Material de referencia o control',
    ];

    /** Tipos de operación reservados (delegación común) de calibración y verificación (ALM_TIPO_*). */
    public const CALIBRATION = 2;
    public const VERIFICATION = 3;
    public const CALIBRATION_TYPES = [self::CALIBRATION, self::VERIFICATION];

    /** Antelación de «caduca pronto» (TAR_DIAS_ANTELACION). */
    public const DAYS_AHEAD = 30;

    /** Columnas nuevas: tabla => columnas. */
    private const COLUMNS = [
        'ALMPRD' => ['PRDCTPR', 'PRDNDAP'],
        'ALMSEL' => ['SELCVCE', 'SELCINU', 'SELCTRZ', 'SELCNCE', 'SELDCER', 'SELBACR'],
    ];

    /** Por base de datos: ¿tiene las columnas? */
    private static array $available = [];

    /** ¿Tiene la base de datos los patrones? (Veolab 3 con las columnas del script). Una vez por base. */
    public static function available(): bool
    {
        if (! VeolabVersion::isV3()) {
            return false;
        }
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$available)) {
            try {
                $ok = true;
                foreach (self::COLUMNS as $table => $columns) {
                    foreach ($columns as $column) {
                        $ok = $ok && Schema::connection('dynamic')->hasColumn($table, $column);
                    }
                }
                self::$available[$database] = $ok;
            } catch (\Throwable) {
                self::$available[$database] = false;
            }
        }

        return self::$available[$database];
    }

    /** Mensaje de una base de datos sin los patrones ($what en singular: «El tipo metrológico»...). */
    public static function unavailableMessage(string $what = 'El tipo metrológico'): string
    {
        return VeolabVersion::isV3()
            ? "{$what} requiere el script de conversión de los patrones y materiales de referencia (ALMPRD.PRDCTPR y PRDNDAP y los datos del certificado de ALMSEL)"
            : VeolabVersion::message(VeolabVersion::V3, $what);
    }

    /** 422 sin los patrones. */
    public static function assertAvailable(string $what = 'El tipo metrológico'): void
    {
        if (! self::available()) {
            throw new BusinessRuleException(self::unavailableMessage($what));
        }
    }

    /** Olvida lo leído (pruebas, o tras convertir una base de datos). */
    public static function forget(): void
    {
        self::$available = [];
    }

    // ------------------------------------------------------------------
    // Caducidad y validez
    // ------------------------------------------------------------------

    /** Día 'aaaa-mm-dd' de un valor de fecha de la base de datos, o null. */
    public static function day($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return substr($value, 0, 10);
    }

    /**
     * Caducidad efectiva ('aaaa-mm-dd'): la menor entre la caducidad y la
     * apertura más los días de validez tras abrir (si son más de 0). Null si
     * no hay ninguna.
     */
    public static function effectiveExpiry($expiry, $opened, $days): ?string
    {
        $dates = [];
        if (($d = self::day($expiry)) !== null) {
            $dates[] = $d;
        }
        $days = (int) ($days ?? 0);
        if ($days > 0 && ($o = self::day($opened)) !== null) {
            $dates[] = (new \DateTimeImmutable($o))->modify("+{$days} days")->format('Y-m-d');
        }

        return $dates ? min($dates) : null;
    }

    /**
     * Condición «la caducidad efectiva es anterior a $limit» (fin excluido)
     * sobre una consulta que une ALMSEL y ALMPRD.
     */
    public static function whereExpiresBefore($query, string $limit)
    {
        return $query->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('ALMSEL.SELDCAD', '>', '1900-01-01')->where('ALMSEL.SELDCAD', '<', $limit))
            ->orWhere(fn ($w) => $w->where('ALMSEL.SELDAPE', '>', '1900-01-01')->where('ALMPRD.PRDNDAP', '>', 0)
                ->whereRaw('DATE_ADD(ALMSEL.SELDAPE, INTERVAL ALMPRD.PRDNDAP DAY) < ?', [$limit])));
    }

    /** Solo los productos con tipo metrológico (sobre una consulta con ALMPRD). */
    public static function whereMetrological($query, string $productTable = 'ALMPRD')
    {
        return $query->whereNotNull("{$productTable}.PRDCTPR")->where("{$productTable}.PRDCTPR", '<>', '');
    }

    /** dd/mm/aaaa de un día 'aaaa-mm-dd'. */
    public static function shortDate(?string $day): string
    {
        return $day === null ? '' : substr($day, 8, 2).'/'.substr($day, 5, 2).'/'.substr($day, 0, 4);
    }

    /**
     * Por qué un patrón o material de referencia no era válido en un día
     * ('aaaa-mm-dd'): caducidad efectiva, próxima calibración o próxima
     * verificación ya alcanzadas ese día (el mismo día cuenta, como
     * ALM_MotivoEquipoVencido). Vacío si era válido. Las fechas de
     * calibración y verificación son las actuales de la serie (Veolab no
     * guarda su historia): una calibración hecha después del uso las mueve.
     */
    public static function invalidReason(object $row, string $day): string
    {
        $reasons = [];
        $expiry = self::effectiveExpiry($row->SELDCAD ?? null, $row->SELDAPE ?? null, $row->PRDNDAP ?? null);
        if ($expiry !== null && $expiry <= $day) {
            $reasons[] = 'caducidad efectiva '.self::shortDate($expiry);
        }
        foreach (['próxima calibración' => $row->SELDCAL ?? null, 'próxima verificación' => $row->SELDVER ?? null] as $label => $value) {
            $value = self::day($value);
            if ($value !== null && $value <= $day) {
                $reasons[] = $label.' '.self::shortDate($value);
            }
        }

        return implode(', ', $reasons);
    }

    // ------------------------------------------------------------------
    // Datos de una serie
    // ------------------------------------------------------------------

    /** Columnas de la serie y su producto para seriesData() (con ALMSEL y ALMPRD unidas). */
    public static function seriesColumns(): array
    {
        $columns = ['ALMSEL.SELCDES', 'ALMSEL.SELCESA', 'ALMSEL.SELDCAL', 'ALMSEL.SELDVER', 'ALMSEL.SELDMAN', 'ALMSEL.SELDCAD',
            'ALMSEL.SELDAPE', 'ALMSEL.SELDBAJ', 'ALMPRD.PRDCDES', 'ALMPRD.PRDBEQU'];
        if (self::available()) {
            $columns = array_merge($columns, ['ALMPRD.PRDCTPR', 'ALMPRD.PRDNDAP', 'ALMSEL.SELCVCE', 'ALMSEL.SELCINU',
                'ALMSEL.SELCTRZ', 'ALMSEL.SELCNCE', 'ALMSEL.SELDCER', 'ALMSEL.SELBACR']);
        }

        return $columns;
    }

    /** Serie con su producto (seriesColumns), o null si no existe. */
    public static function series(string $del, string $product, string $lot): ?object
    {
        return DB::connection('dynamic')->table('ALMSEL')
            ->join('ALMPRD', fn ($j) => $j->on('ALMPRD.DEL3COD', '=', 'ALMSEL.PRD3DEL')->on('ALMPRD.PRD1COD', '=', 'ALMSEL.PRD3COD'))
            ->where('ALMSEL.PRD3DEL', $del)->where('ALMSEL.PRD3COD', $product)->where('ALMSEL.SEL1COD', $lot)
            ->first(self::seriesColumns());
    }

    /** Datos de una serie para las respuestas (los del certificado, solo si la base los tiene). */
    public static function seriesData(?object $row, string $del, string $product, string $lot): array
    {
        $out = [
            'producto_delegacion'  => $del,
            'producto_codigo'      => $product,
            'producto_descripcion' => $row ? (string) ($row->PRDCDES ?? '') : null,
            'codigo'               => $lot,
            'descripcion'          => $row ? (string) ($row->SELCDES ?? '') : null,
            'es_equipo'            => $row ? (($row->PRDBEQU ?? '') === 'T' ? 'T' : 'F') : null,
            'estado'               => $row ? (string) ($row->SELCESA ?? '') : null,
            'fecha_calibracion'    => $row ? self::day($row->SELDCAL ?? null) : null,
            'fecha_verificacion'   => $row ? self::day($row->SELDVER ?? null) : null,
            'fecha_caducidad'      => $row ? self::day($row->SELDCAD ?? null) : null,
            'fecha_apertura'       => $row ? self::day($row->SELDAPE ?? null) : null,
        ];
        if (self::available()) {
            $type = $row ? trim((string) ($row->PRDCTPR ?? '')) : '';
            $out += [
                'tipo_metrologico'       => $type !== '' ? $type : null,
                'tipo_metrologico_texto' => self::TYPES[$type] ?? null,
                'dias_validez_apertura'  => $row && ($row->PRDNDAP ?? null) !== null ? (int) $row->PRDNDAP : null,
                'valor_certificado'      => $row ? ($row->SELCVCE ?? null) : null,
                'incertidumbre'          => $row ? ($row->SELCINU ?? null) : null,
                'trazabilidad'           => $row ? ($row->SELCTRZ ?? null) : null,
                'numero_certificado'     => $row ? ($row->SELCNCE ?? null) : null,
                'fecha_certificado'      => $row ? self::day($row->SELDCER ?? null) : null,
                'acreditado'             => $row ? ($row->SELBACR ?? null) : null,
            ];
        }
        $out['caducidad_efectiva'] = $row ? self::effectiveExpiry($row->SELDCAD ?? null, $row->SELDAPE ?? null, $row->PRDNDAP ?? null) : null;

        return $out;
    }

    // ------------------------------------------------------------------
    // Cadena de trazabilidad
    // ------------------------------------------------------------------

    /** Operación no anulada (sobre una consulta con LABOPE). */
    private static function notCancelled($query)
    {
        return $query->where(fn ($q) => $q->whereNull('LABOPE.OPEBANU')->orWhere('LABOPE.OPEBANU', '<>', 'T'));
    }

    /** Calibraciones y verificaciones de la serie (LABOPE sobre la serie con el tipo reservado). */
    private static function calibrationsOf($query, string $del, string $product, string $lot)
    {
        return self::notCancelled($query->where('LABOPE.PRD2DEL', $del)->where('LABOPE.PRD2COD', $product)->where('LABOPE.SEL2COD', $lot)
            ->where('LABOPE.TIO2DEL', '')->whereIn('LABOPE.TIO2COD', self::CALIBRATION_TYPES));
    }

    /** ALMMOV unido a su operación (LABOPE). */
    private static function movementsWithOperation()
    {
        return DB::connection('dynamic')->table('ALMMOV')
            ->join('LABOPE', fn ($j) => $j->on('LABOPE.DEL3COD', '=', 'ALMMOV.OPE2DEL')->on('LABOPE.OPE1SER', '=', 'ALMMOV.OPE2SER')
                ->on('LABOPE.OPE1COD', '=', 'ALMMOV.OPE2COD'))
            ->whereIn('ALMMOV.MOVCTIP', [VeolabStock::MOV_USO, VeolabStock::MOV_CONSUMO]);
    }

    /** Fecha de un uso: la de inicio de la operación o, sin ella, la del movimiento (como LeerEquiposVencidos). */
    private const USE_DATE = 'COALESCE(LABOPE.OPEDINI, ALMMOV.MOVDFEC)';

    /** Texto del tipo de operación reservado. */
    public static function calibrationText(int $type): string
    {
        return $type === self::CALIBRATION ? 'Calibración' : ($type === self::VERIFICATION ? 'Verificación' : '');
    }

    /** Clave y datos de una operación para las respuestas. */
    private static function operationData(object $row): array
    {
        return [
            'delegacion'        => (string) $row->DEL3COD,
            'serie'             => (string) $row->OPE1SER,
            'codigo'            => (int) $row->OPE1COD,
            'codigo_formateado' => self::operationCode((string) $row->DEL3COD, (string) $row->OPE1SER, (int) $row->OPE1COD),
        ];
    }

    private static function operationCode(string $del, string $ser, int $cod): string
    {
        try {
            $code = VeolabCodes::format('LABOPE', (string) $cod, $del, $ser);
        } catch (\Throwable) {
            $code = '';
        }

        return $code !== '' ? $code : ($ser !== '' ? $ser.'/' : '').$cod;
    }

    /**
     * Cadena de trazabilidad de una serie:
     *  - se_calibra_con: los usos y consumos (U/O) de sus calibraciones y
     *    verificaciones no anuladas, del más reciente al más antiguo, con el
     *    patrón y si era válido ese día;
     *  - se_usa_para: las series calibradas o verificadas con ella y el
     *    recuento de operaciones (no anuladas) en las que se ha usado.
     */
    public static function traceability(string $del, string $product, string $lot): array
    {
        $patternColumns = array_merge(self::seriesColumns(), ['LABOPE.DEL3COD', 'LABOPE.OPE1SER', 'LABOPE.OPE1COD', 'LABOPE.TIO2COD',
            'LABOPE.OPEDINI', 'LABOPE.OPEDFIN', 'LABDIC.DICCDES', 'ALMMOV.MOVCTIP', 'ALMMOV.MOVDFEC', 'ALMMOV.MOVNCAN',
            'ALMMOV.TEC2DEL', 'ALMMOV.TEC2COD', 'ALMMOV.PRD2DEL AS PAT_DEL', 'ALMMOV.PRD2COD AS PAT_PRD', 'ALMMOV.SEL2COD AS PAT_SEL',
            'ALMSEL.SEL1COD AS PAT_EXISTE']);

        $query = self::movementsWithOperation()
            ->leftJoin('ALMSEL', fn ($j) => $j->on('ALMSEL.PRD3DEL', '=', 'ALMMOV.PRD2DEL')->on('ALMSEL.PRD3COD', '=', 'ALMMOV.PRD2COD')
                ->on('ALMSEL.SEL1COD', '=', 'ALMMOV.SEL2COD'))
            ->leftJoin('ALMPRD', fn ($j) => $j->on('ALMPRD.DEL3COD', '=', 'ALMMOV.PRD2DEL')->on('ALMPRD.PRD1COD', '=', 'ALMMOV.PRD2COD'))
            ->leftJoin('LABDIC', fn ($j) => $j->on('LABDIC.DEL3COD', '=', 'LABOPE.DIC2DEL')->on('LABDIC.DIC1COD', '=', 'LABOPE.DIC2COD'));
        $rows = self::calibrationsOf($query, $del, $product, $lot)
            ->orderByRaw(self::USE_DATE.' DESC')->orderBy('LABOPE.DEL3COD')->orderBy('LABOPE.OPE1SER')->orderBy('LABOPE.OPE1COD')
            ->orderBy('ALMPRD.PRDCDES')
            ->get($patternColumns);

        // LeerTrazabilidad: una fila por operación y serie usada; la propia serie no cuenta
        $calibratedWith = [];
        $seen = [];
        $today = (string) DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d;
        foreach ($rows as $row) {
            if ((string) $row->PAT_DEL === $del && (string) $row->PAT_PRD === $product && (string) $row->PAT_SEL === $lot) {
                continue;
            }
            $key = $row->DEL3COD."".$row->OPE1SER."".$row->OPE1COD."".$row->PAT_DEL."".$row->PAT_PRD."".$row->PAT_SEL;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $day = self::day($row->OPEDINI) ?? self::day($row->MOVDFEC);
            // La serie usada ya no existe: no se sabe si era válida. Sin fecha del uso, se evalúa con la de hoy.
            $exists = $row->PAT_EXISTE !== null;
            $reason = $exists ? self::invalidReason($row, $day ?? $today) : '';
            $calibratedWith[] = [
                'fecha'      => $day,
                'operacion'  => self::operationData($row) + [
                    'tipo_operacion'       => (int) $row->TIO2COD,
                    'tipo_operacion_texto' => self::calibrationText((int) $row->TIO2COD),
                    'fecha_fin'            => self::day($row->OPEDFIN),
                    'dictamen'             => $row->DICCDES !== null ? (string) $row->DICCDES : null,
                ],
                'movimiento' => [
                    'tipo'              => (string) $row->MOVCTIP,
                    'cantidad'          => $row->MOVNCAN !== null ? (float) $row->MOVNCAN : null,
                    'tecnica_delegacion' => $row->TEC2COD !== null && (string) $row->TEC2COD !== '' ? (string) $row->TEC2DEL : null,
                    'tecnica_codigo'    => $row->TEC2COD !== null && (string) $row->TEC2COD !== '' ? (string) $row->TEC2COD : null,
                ],
                'patron'         => self::seriesData($row->PRDCDES !== null || $row->SELCDES !== null ? $row : null,
                    (string) $row->PAT_DEL, (string) $row->PAT_PRD, (string) $row->PAT_SEL),
                'valido_ese_dia' => $exists ? $reason === '' : null,
                'motivo'         => $reason !== '' ? $reason : null,
            ];
        }

        // Series calibradas o verificadas con esta
        $query = self::movementsWithOperation()
            ->leftJoin('ALMPRD', fn ($j) => $j->on('ALMPRD.DEL3COD', '=', 'LABOPE.PRD2DEL')->on('ALMPRD.PRD1COD', '=', 'LABOPE.PRD2COD'))
            ->leftJoin('ALMSEL', fn ($j) => $j->on('ALMSEL.PRD3DEL', '=', 'LABOPE.PRD2DEL')->on('ALMSEL.PRD3COD', '=', 'LABOPE.PRD2COD')
                ->on('ALMSEL.SEL1COD', '=', 'LABOPE.SEL2COD'))
            ->where('ALMMOV.PRD2DEL', $del)->where('ALMMOV.PRD2COD', $product)->where('ALMMOV.SEL2COD', $lot)
            ->where('LABOPE.TIO2DEL', '')->whereIn('LABOPE.TIO2COD', self::CALIBRATION_TYPES)
            ->whereNotNull('LABOPE.PRD2COD')->where('LABOPE.PRD2COD', '<>', '')
            // La propia serie no cuenta (una calibración que registra el uso del equipo calibrado)
            ->whereNot(fn ($q) => $q->where('LABOPE.PRD2DEL', $del)->where('LABOPE.PRD2COD', $product)->where('LABOPE.SEL2COD', $lot));
        $groups = self::notCancelled($query)
            ->groupBy('LABOPE.PRD2DEL', 'LABOPE.PRD2COD', 'LABOPE.SEL2COD', 'LABOPE.TIO2COD')
            ->orderBy('LABOPE.PRD2DEL')->orderBy('LABOPE.PRD2COD')->orderBy('LABOPE.SEL2COD')
            ->get(['LABOPE.PRD2DEL AS EQ_DEL', 'LABOPE.PRD2COD AS EQ_PRD', 'LABOPE.SEL2COD AS EQ_SEL', 'LABOPE.TIO2COD',
                DB::raw('COUNT(DISTINCT LABOPE.DEL3COD, LABOPE.OPE1SER, LABOPE.OPE1COD) AS NOPE'),
                DB::raw('MIN('.self::USE_DATE.') AS PRIMERA'), DB::raw('MAX('.self::USE_DATE.') AS ULTIMA'),
                DB::raw('MAX(ALMPRD.PRDCDES) AS PRDCDES'), DB::raw('MAX(ALMSEL.SELCDES) AS SELCDES')]);

        $series = [];
        foreach ($groups as $g) {
            $key = $g->EQ_DEL."\x1B".$g->EQ_PRD."\x1B".$g->EQ_SEL;
            if (! isset($series[$key])) {
                $series[$key] = [
                    'producto_delegacion'  => (string) $g->EQ_DEL,
                    'producto_codigo'      => (string) $g->EQ_PRD,
                    'producto_descripcion' => $g->PRDCDES !== null ? (string) $g->PRDCDES : null,
                    'codigo'               => (string) $g->EQ_SEL,
                    'descripcion'          => $g->SELCDES !== null ? (string) $g->SELCDES : null,
                    'calibraciones'        => 0,
                    'verificaciones'       => 0,
                    'primera_fecha'        => null,
                    'ultima_fecha'         => null,
                ];
            }
            $series[$key][(int) $g->TIO2COD === self::CALIBRATION ? 'calibraciones' : 'verificaciones'] += (int) $g->NOPE;
            $first = self::day($g->PRIMERA);
            $last = self::day($g->ULTIMA);
            if ($first !== null && ($series[$key]['primera_fecha'] === null || $first < $series[$key]['primera_fecha'])) {
                $series[$key]['primera_fecha'] = $first;
            }
            if ($last !== null && ($series[$key]['ultima_fecha'] === null || $last > $series[$key]['ultima_fecha'])) {
                $series[$key]['ultima_fecha'] = $last;
            }
        }

        // Todas las operaciones (no anuladas) en las que se ha usado o consumido
        $usage = self::notCancelled(self::movementsWithOperation()
            ->where('ALMMOV.PRD2DEL', $del)->where('ALMMOV.PRD2COD', $product)->where('ALMMOV.SEL2COD', $lot))
            ->first([DB::raw('COUNT(DISTINCT LABOPE.DEL3COD, LABOPE.OPE1SER, LABOPE.OPE1COD) AS NOPE'),
                DB::raw('MIN('.self::USE_DATE.') AS PRIMERA'), DB::raw('MAX('.self::USE_DATE.') AS ULTIMA')]);

        return [
            'serie'          => self::seriesData(self::series($del, $product, $lot), $del, $product, $lot),
            'se_calibra_con' => $calibratedWith,
            'se_usa_para'    => [
                'series'        => array_values($series),
                'operaciones'   => (int) ($usage->NOPE ?? 0),
                'primera_fecha' => self::day($usage->PRIMERA ?? null),
                'ultima_fecha'  => self::day($usage->ULTIMA ?? null),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Operaciones afectadas (6.4.9)
    // ------------------------------------------------------------------

    /**
     * ¿Es la operación de calibración o verificación no conforme? Lo es si
     * está enlazada (CALIYE, LABOPE) a una incidencia de trabajo no conforme
     * (clase T) no anulada. Veolab no guarda si un dictamen es conforme.
     */
    private static function nonConforming(string $del, string $ser, int $cod): bool
    {
        try {
            return DB::connection('dynamic')->table('CALIYE')
                ->join('CALINC', fn ($j) => $j->on('CALINC.DEL3COD', '=', 'CALIYE.DEL3COD')->on('CALINC.INC1SER', '=', 'CALIYE.INC3SER')
                    ->on('CALINC.INC1COD', '=', 'CALIYE.INC3COD'))
                ->where('CALIYE.IYECTAB', 'LABOPE')->where('CALIYE.IYECDEL', $del)->where('CALIYE.IYECSER', $ser)
                ->where('CALIYE.IYECCOD', (string) $cod)
                ->where('CALINC.INCCCLA', 'T')
                ->where(fn ($q) => $q->whereNull('CALINC.INCCEST')->orWhere('CALINC.INCCEST', '<>', 'A'))
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Las 10 últimas calibraciones y verificaciones finalizadas (OPEDFIN) y no
     * anuladas de la serie, de la más reciente a la más antigua, con
     * 'conforme' (false si están enlazadas a un trabajo no conforme).
     */
    public static function lastCalibrations(string $del, string $product, string $lot, int $limit = 10): array
    {
        $query = DB::connection('dynamic')->table('LABOPE')
            ->leftJoin('LABDIC', fn ($j) => $j->on('LABDIC.DEL3COD', '=', 'LABOPE.DIC2DEL')->on('LABDIC.DIC1COD', '=', 'LABOPE.DIC2COD'));
        $rows = self::calibrationsOf($query, $del, $product, $lot)->whereNotNull('LABOPE.OPEDFIN')
            ->orderByDesc('LABOPE.OPEDFIN')->orderByDesc('LABOPE.OPE1COD')->limit($limit)
            ->get(['LABOPE.DEL3COD', 'LABOPE.OPE1SER', 'LABOPE.OPE1COD', 'LABOPE.TIO2COD', 'LABOPE.OPEDINI', 'LABOPE.OPEDFIN', 'LABDIC.DICCDES']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::operationData($row) + [
                'tipo_operacion'       => (int) $row->TIO2COD,
                'tipo_operacion_texto' => self::calibrationText((int) $row->TIO2COD),
                'fecha_inicio'         => self::day($row->OPEDINI),
                'fecha_fin'            => self::day($row->OPEDFIN),
                'dictamen'             => $row->DICCDES !== null ? (string) $row->DICCDES : null,
                'conforme'             => ! self::nonConforming((string) $row->DEL3COD, (string) $row->OPE1SER, (int) $row->OPE1COD),
            ];
        }

        return $out;
    }

    /** ¿Es la operación una calibración o verificación (no anulada) de la serie? */
    public static function isCalibrationOf(string $opDel, string $opSer, int $opCod, string $del, string $product, string $lot): bool
    {
        $query = DB::connection('dynamic')->table('LABOPE')
            ->where('LABOPE.DEL3COD', $opDel)->where('LABOPE.OPE1SER', $opSer)->where('LABOPE.OPE1COD', $opCod);

        return self::calibrationsOf($query, $del, $product, $lot)->exists();
    }

    /** ¿Usa o consume la operación la serie? (ALMMOV U/O) */
    public static function operationUses(string $opDel, string $opSer, int $opCod, string $del, string $product, string $lot): bool
    {
        return DB::connection('dynamic')->table('ALMMOV')
            ->where('OPE2DEL', $opDel)->where('OPE2SER', $opSer)->where('OPE2COD', $opCod)
            ->where('PRD2DEL', $del)->where('PRD2COD', $product)->where('SEL2COD', $lot)
            ->whereIn('MOVCTIP', [VeolabStock::MOV_USO, VeolabStock::MOV_CONSUMO])->exists();
    }

    /**
     * Operaciones no anuladas en las que se usó o consumió la serie (ALMMOV
     * U/O) con la fecha de uso entre $from y $to ('aaaa-mm-dd', incluidos;
     * $from null = desde el principio), por fecha, con sus informes (LABIYO).
     */
    public static function affectedOperations(string $del, string $product, string $lot, ?string $from, string $to): array
    {
        $db = DB::connection('dynamic');
        $until = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');

        $query = self::movementsWithOperation()
            ->leftJoin('SINCLI', fn ($j) => $j->on('SINCLI.DEL3COD', '=', 'LABOPE.CLI2DEL')->on('SINCLI.CLI1COD', '=', 'LABOPE.CLI2COD'))
            ->where('ALMMOV.PRD2DEL', $del)->where('ALMMOV.PRD2COD', $product)->where('ALMMOV.SEL2COD', $lot)
            ->whereRaw(self::USE_DATE.' < ?', [$until]);
        if ($from !== null) {
            $query->whereRaw(self::USE_DATE.' >= ?', [$from]);
        }
        $rows = self::notCancelled($query)
            ->orderByRaw(self::USE_DATE)->orderBy('LABOPE.DEL3COD')->orderBy('LABOPE.OPE1SER')->orderBy('LABOPE.OPE1COD')
            ->get(['LABOPE.DEL3COD', 'LABOPE.OPE1SER', 'LABOPE.OPE1COD', 'LABOPE.OPENEST', 'LABOPE.OPECREF', 'LABOPE.OPECDES',
                'LABOPE.OPEDVAL', 'LABOPE.OPEDENV', 'LABOPE.CLI2DEL', 'LABOPE.CLI2COD', 'SINCLI.CLICNOM', 'ALMMOV.MOVCTIP',
                'ALMMOV.TEC2DEL', 'ALMMOV.TEC2COD', DB::raw(self::USE_DATE.' AS FUSO')]);

        $operations = [];
        foreach ($rows as $row) {
            $key = $row->DEL3COD."\x1B".$row->OPE1SER."\x1B".$row->OPE1COD;
            if (! isset($operations[$key])) {
                $client = (string) ($row->CLI2COD ?? '');
                $operations[$key] = self::operationData($row) + [
                    'fecha_uso'           => self::day($row->FUSO),
                    'estado'              => $row->OPENEST !== null ? (int) $row->OPENEST : null,
                    'referencia'          => $row->OPECREF,
                    'descripcion'         => $row->OPECDES,
                    'cliente_delegacion'  => $client !== '' ? (string) $row->CLI2DEL : null,
                    'cliente_codigo'      => $client !== '' ? $client : null,
                    'cliente_nombre'      => $row->CLICNOM,
                    'fecha_validacion'    => self::day($row->OPEDVAL),
                    'fecha_envio'         => self::day($row->OPEDENV),
                    'movimientos'         => [],
                    'tecnicas'            => [],
                    'informes'            => [],
                ];
            }
            if (! in_array((string) $row->MOVCTIP, $operations[$key]['movimientos'], true)) {
                $operations[$key]['movimientos'][] = (string) $row->MOVCTIP;
            }
            if ($row->TEC2COD !== null && (string) $row->TEC2COD !== '') {
                $technique = ['delegacion' => (string) $row->TEC2DEL, 'codigo' => (string) $row->TEC2COD];
                if (! in_array($technique, $operations[$key]['tecnicas'], true)) {
                    $operations[$key]['tecnicas'][] = $technique;
                }
            }
        }

        // Informes que contienen las operaciones
        foreach (array_chunk(array_keys($operations), 200) as $chunk) {
            $reports = $db->table('LABIYO')
                ->where(function ($q) use ($chunk, $operations) {
                    foreach ($chunk as $key) {
                        $o = $operations[$key];
                        $q->orWhere(fn ($w) => $w->where('OPE3DEL', $o['delegacion'])->where('OPE3SER', $o['serie'])->where('OPE3COD', $o['codigo']));
                    }
                })
                ->distinct()->orderBy('INF3DEL')->orderBy('INF3SER')->orderBy('INF3COD')
                ->get(['OPE3DEL', 'OPE3SER', 'OPE3COD', 'INF3DEL', 'INF3SER', 'INF3COD']);
            foreach ($reports as $r) {
                $key = $r->OPE3DEL."\x1B".$r->OPE3SER."\x1B".$r->OPE3COD;
                if (! isset($operations[$key])) {
                    continue;
                }
                try {
                    $code = VeolabCodes::format('LABINF', (string) $r->INF3COD, (string) $r->INF3DEL, (string) $r->INF3SER);
                } catch (\Throwable) {
                    $code = '';
                }
                $operations[$key]['informes'][] = [
                    'delegacion'        => (string) $r->INF3DEL,
                    'serie'             => (string) $r->INF3SER,
                    'codigo'            => (int) $r->INF3COD,
                    'codigo_formateado' => $code !== '' ? $code : (string) $r->INF3COD,
                ];
            }
        }

        return array_values($operations);
    }
}
