<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Objetivos e indicadores de calidad (módulo CAL, desde Veolab 3; ISO/IEC
 * 17025:2017 8.2.1 y 8.9.2 b): indicadores (CALKPI), automáticos de Veolab
 * (KPICCAL, códigos 1 a 18, comunes) o manuales con sus valores (CALKPV), y
 * objetivos anuales (CALOBJ) con su meta, seguimiento y cierre. Las mejoras
 * son incidencias de clase M con origen D enlazadas con CALIYE tipo CALOBJ.
 * Sigue el modelo común con el escritorio (Objetivos.bas; «Analisis
 * objetivos e indicadores.md», apartado 5).
 *
 * El valor de un indicador en un periodo (ambos días incluidos) se calcula
 * para una delegación ('' o null = todo el laboratorio; en los maestros
 * comunes, equipos, proveedores y gráficos de control, también los registros
 * con delegación ''). Null si no hay datos, si el denominador es 0 o si la
 * consulta falla (tabla o columna que no existe en esta base).
 */
class VeolabQualityIndicators
{
    public const TABLE = 'CALKPI';
    public const VALUES = 'CALKPV';
    public const OBJECTIVES = 'CALOBJ';

    /** Funcionalidad de Veolab: especial 1 = responsable de calidad (crear, modificar, cerrar y eliminar). */
    public const FUNCTIONALITY = 'CAL_OBJ';

    /** Los indicadores del laboratorio toman MAX(KPI1COD) + 1 de todas las delegaciones, y nunca menos de este. */
    public const FIRST_OWN_CODE = 19;

    /** Cálculos de los indicadores automáticos (KPICCAL) => método. */
    public const CALCULATIONS = [
        'INF_PLAZO' => 'reportsOnTime',
        'INF_DIAS'  => 'reportDays',
        'INF_SUST'  => 'replacedReports',
        'QUE_MIL'   => 'complaintsPerThousand',
        'QUE_DIAS'  => 'complaintDays',
        'INC_NC'    => 'nonConformities',
        'INC_DIAS'  => 'incidentDays',
        'INC_EFIC'  => 'effectiveActions',
        'ACC_VENC'  => 'overdueActions',
        'EAP_SAT'   => 'satisfactoryResults',
        'EAP_PLAN'  => 'planCoverage',
        'AUD_PROG'  => 'auditProgramme',
        'AUD_HALL'  => 'auditFindings',
        'FOR_PLAN'  => 'trainingPlan',
        'FOR_HORAS' => 'trainingHours',
        'EQU_VENC'  => 'overdueEquipment',
        'PRO_EVAL'  => 'supplierEvaluation',
        'GCO_FUERA' => 'outOfControlPoints',
    ];

    /** Mejor si sube o si baja (KPICSEN; ETI01460-1461). */
    public const SENSE_NAMES = ['S' => 'Mejor si sube', 'B' => 'Mejor si baja'];

    /** Periodicidad de los valores manuales (KPICPER; ETI01462-1465) y sus meses. */
    public const PERIOD_NAMES = ['M' => 'Mensual', 'T' => 'Trimestral', 'S' => 'Semestral', 'A' => 'Anual'];
    public const PERIOD_MONTHS = ['M' => 1, 'T' => 3, 'S' => 6, 'A' => 12];

    /** Estado del objetivo (OBJCEST; '' cuenta como abierto; ETI01466-1467). */
    public const STATE_NAMES = ['A' => 'Abierto', 'C' => 'Cerrado'];

    /** Resultado al cerrarlo (OBJCRES; ETI01468-1470). */
    public const RESULT_NAMES = ['S' => 'Cumplido', 'P' => 'Cumplido en parte', 'N' => 'No cumplido'];

    /** Semáforo: ámbar si el valor no llega a la meta por menos de este tanto por uno de la meta (OBJ_MARGEN_AMBAR). */
    public const AMBER_MARGIN = 0.1;

    /** Decimales al crear un indicador sin ellos; al formatear, nulos = 0 (Val("" & KPINDEC) del escritorio). */
    public const DEFAULT_DECIMALS = 2;

    /** Clase y origen de las mejoras (incidencias de mejora con origen «Revisión por la dirección»). */
    public const IMPROVEMENT_CLASS = 'M';
    public const IMPROVEMENT_ORIGIN = 'D';

    /** Auditorías internas: interna, interna contratada o sin tipo. */
    private const INTERNAL_AUDITS = ['I', 'C'];

    private static array $available = [];

    /** Indicadores leídos en la petición: "base\x1Bdel\x1Bcod" => fila o null. */
    private static array $indicators = [];

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** ¿Tiene la base de datos las tablas de objetivos e indicadores? */
    public static function available(): bool
    {
        if (! VeolabVersion::isV3()) {
            return false;
        }
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$available)) {
            try {
                $schema = Schema::connection('dynamic');
                self::$available[$database] = $schema->hasTable(self::TABLE) && $schema->hasTable(self::VALUES)
                    && $schema->hasTable(self::OBJECTIVES);
            } catch (\Throwable) {
                self::$available[$database] = false;
            }
        }

        return self::$available[$database];
    }

    public static function unavailableMessage(): string
    {
        return 'La base de datos aún no tiene los objetivos e indicadores de calidad (CALKPI, CALKPV, CALOBJ): hay que actualizarla';
    }

    /** Indicador (CALKPI) o null. */
    public static function indicator(string $del, int $cod): ?object
    {
        $key = DB::connection('dynamic')->getDatabaseName()."\x1B".$del."\x1B".$cod;
        if (! array_key_exists($key, self::$indicators)) {
            self::$indicators[$key] = DB::connection('dynamic')->table(self::TABLE)
                ->where('DEL3COD', $del)->where('KPI1COD', $cod)->first();
        }

        return self::$indicators[$key];
    }

    /** Olvida los indicadores leídos (tras cambiarlos). */
    public static function forget(): void
    {
        self::$indicators = [];
    }

    /** ¿Es uno de los indicadores automáticos que trae Veolab (KPICCAL no vacío)? */
    public static function isAutomatic(?object $kpi): bool
    {
        return $kpi !== null && trim((string) ($kpi->KPICCAL ?? '')) !== '';
    }

    /** Objetivo (CALOBJ) o null; con $lock, bloqueado hasta el commit. */
    public static function objective(string $del, int $cod, bool $lock = false): ?object
    {
        $query = DB::connection('dynamic')->table(self::OBJECTIVES)->where('DEL3COD', $del)->where('OBJ1COD', $cod);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /** Estado del objetivo ('' cuenta como abierto). */
    public static function state(object $objective): string
    {
        $state = (string) ($objective->OBJCEST ?? '');

        return $state === '' ? 'A' : $state;
    }

    /** Hoy (fecha del servidor de base de datos, aaaa-mm-dd). */
    public static function today(): string
    {
        return (string) DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d;
    }

    /** Siguiente código (MAX + 1 de todas las delegaciones); con $minimum, nunca menos. */
    public static function nextCode(string $table, string $column, int $minimum = 1): int
    {
        return max($minimum, (int) DB::connection('dynamic')->table($table)->lockForUpdate()->max($column) + 1);
    }

    // ------------------------------------------------------------------
    // Usuario de la petición (la API no tiene sesión)
    // ------------------------------------------------------------------

    /**
     * Usuario de Veolab con su acceso a CAL_OBJ: ['activo', 'escritura',
     * 'calidad' (especial 1), 'delegacion', 'codigo']. Null si no se indica;
     * 422 si no existe. Como VeolabManagementReview::requestUser.
     */
    public static function requestUser(?string $del, ?string $cod): ?array
    {
        if ($cod === null || $cod === '') {
            return null;
        }
        $row = DB::connection('dynamic')->table('ACCUSU')
            ->leftJoin('ACCPYF', function ($join) {
                $join->on('ACCPYF.DEL3COD', '=', 'ACCUSU.PER2DEL')
                    ->on('ACCPYF.PER3COD', '=', 'ACCUSU.PER2COD')
                    ->where('ACCPYF.FUN3COD', '=', self::FUNCTIONALITY);
            })
            ->where('ACCUSU.DEL3COD', (string) $del)->where('ACCUSU.USU1COD', $cod)
            ->first(['ACCUSU.USUBBAJ', 'ACCPYF.PYFNACC']);
        if (! $row) {
            throw new BusinessRuleException('El usuario no existe');
        }
        $access = (int) ($row->PYFNACC ?? 0);

        return [
            'activo'     => $row->USUBBAJ !== 'T',
            'escritura'  => ($access & 3) === 3,
            'calidad'    => ($access % 8) >= 4 && ($access % 2) === 1,
            'delegacion' => (string) $del,
            'codigo'     => $cod,
        ];
    }

    /**
     * Solo el responsable de calidad (especial 1 de CAL_OBJ, con escritura)
     * escribe indicadores, valores y objetivos. Sin usuario, la API actúa como
     * responsable de calidad.
     */
    public static function assertManager(?array $user): void
    {
        if ($user !== null && (! $user['activo'] || ! $user['escritura'] || ! $user['calidad'])) {
            throw new BusinessRuleException('Solo el responsable de calidad puede crear, modificar, cerrar o eliminar objetivos e indicadores de calidad');
        }
    }

    /** assertManager con ?usuario_delegacion=&usuario_codigo= de la petición. */
    public static function assertRequestManager(): void
    {
        self::assertManager(self::requestUser(request()->query('usuario_delegacion'), request()->query('usuario_codigo')));
    }

    // ------------------------------------------------------------------
    // Cumplimiento y formato
    // ------------------------------------------------------------------

    /** ¿Alcanza la meta? Mejor si baja: valor <= meta; si no (sube): valor >= meta (OBJ_Cumple). */
    public static function complies(float $value, float $goal, ?string $sense): bool
    {
        return $sense === 'B' ? $value <= $goal : $value >= $goal;
    }

    /**
     * Semáforo (OBJ_ColorSemaforo): verde si cumple; ámbar si no llega por
     * menos de un 10 % de la meta (|valor − meta| <= |meta| × 0,1); rojo en el
     * resto. Null sin valor o sin meta.
     */
    public static function trafficLight(?float $value, ?float $goal, ?string $sense): ?string
    {
        if ($value === null || $goal === null) {
            return null;
        }
        if (self::complies($value, $goal, $sense)) {
            return 'verde';
        }

        return abs($value - $goal) <= abs($goal) * self::AMBER_MARGIN ? 'ambar' : 'rojo';
    }

    /** Decimales del indicador. */
    public static function decimals(?object $kpi): int
    {
        return max(0, (int) ($kpi->KPINDEC ?? 0));
    }

    /** Número como el escritorio (OBJ_FormatoValor, «#,##0.0» en español) con la unidad: «96,2 %». */
    public static function formatValue(float $value, int $decimals, string $unit = ''): string
    {
        $text = number_format(round($value, $decimals), $decimals, ',', '.');
        $unit = trim($unit);

        return $unit !== '' ? $text.' '.$unit : $text;
    }

    /** Meta con el sentido (OBJ_TextoMeta): «>= 95,0 %» o «<= 2 días». */
    public static function goalText(float $goal, ?string $sense, int $decimals, string $unit = ''): string
    {
        return ($sense === 'B' ? '<= ' : '>= ').self::formatValue($goal, $decimals, $unit);
    }

    /** Número de la BD (double) o null. */
    public static function number($value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    // ------------------------------------------------------------------
    // Valor de un objetivo
    // ------------------------------------------------------------------

    /**
     * Periodo del año para el valor del objetivo: del 1 de enero a hoy, o al
     * 31 de diciembre si el año ya pasó. Null si el año aún no ha empezado.
     */
    public static function yearPeriod(int $year): ?array
    {
        $today = self::today();
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        if ($from > $today) {
            return null;
        }

        return [$from, min($to, $today)];
    }

    /** Valor calculado del año del objetivo (sin mirar si está cerrado). */
    public static function calculatedValue(object $objective, ?object $kpi = null): ?float
    {
        $kpi = $kpi ?? self::indicator((string) $objective->KPI2DEL, (int) $objective->KPI2COD);
        $period = self::yearPeriod((int) $objective->OBJNANI);
        if (! $kpi || ! $period) {
            return null;
        }

        return self::value($kpi, $period[0], $period[1], (string) $objective->DEL3COD);
    }

    /** Valor del objetivo: el congelado si está cerrado; si no, el calculado del año. */
    public static function objectiveValue(object $objective, ?object $kpi = null): ?float
    {
        if (self::state($objective) === 'C') {
            return self::number($objective->OBJNVAL);
        }

        return self::calculatedValue($objective, $kpi);
    }

    /**
     * Datos calculados del objetivo para el listado: valor, si cumple y el
     * semáforo, con los textos del indicador.
     */
    public static function objectiveStatus(object $objective): array
    {
        $kpi = self::indicator((string) $objective->KPI2DEL, (int) $objective->KPI2COD);
        $value = self::objectiveValue($objective, $kpi);
        $goal = self::number($objective->OBJNMET);
        $sense = $kpi->KPICSEN ?? 'S';
        $decimals = self::decimals($kpi);
        $unit = (string) ($kpi->KPICUNI ?? '');

        return [
            'indicador'   => $kpi,
            'valor'       => $value,
            'valor_texto' => $value === null ? null : self::formatValue($value, $decimals, $unit),
            'meta_texto'  => $goal === null ? null : self::goalText($goal, $sense, $decimals, $unit),
            'cumple'      => $value === null || $goal === null ? null : (self::complies($value, $goal, $sense) ? 'T' : 'F'),
            'semaforo'    => self::trafficLight($value, $goal, $sense),
        ];
    }

    /**
     * Seguimiento del objetivo: los cuatro trimestres (cada uno hasta hoy como
     * mucho; los que aún no han empezado, sin valor) y el año, con valor,
     * cumple y semáforo.
     */
    public static function tracking(object $objective): array
    {
        $kpi = self::indicator((string) $objective->KPI2DEL, (int) $objective->KPI2COD);
        $year = (int) $objective->OBJNANI;
        $goal = self::number($objective->OBJNMET);
        $sense = $kpi->KPICSEN ?? 'S';
        $decimals = self::decimals($kpi);
        $unit = (string) ($kpi->KPICUNI ?? '');
        $today = self::today();

        $row = function (string $period, string $from, string $to, ?float $value) use ($goal, $sense, $decimals, $unit) {
            return [
                'periodo'     => $period,
                'desde'       => $from,
                'hasta'       => $to,
                'valor'       => $value,
                'valor_texto' => $value === null ? null : self::formatValue($value, $decimals, $unit),
                'cumple'      => $value === null || $goal === null ? null : (self::complies($value, $goal, $sense) ? 'T' : 'F'),
                'semaforo'    => self::trafficLight($value, $goal, $sense),
            ];
        };

        $out = [];
        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $from = sprintf('%04d-%02d-01', $year, $quarter * 3 - 2);
            $to = (new \DateTime($from))->modify('+3 months')->modify('-1 day')->format('Y-m-d');
            $value = null;
            if ($kpi && $from <= $today) {
                $value = self::value($kpi, $from, min($to, $today), (string) $objective->DEL3COD);
            }
            $out[] = $row('T'.$quarter, $from, $to, $value);
        }
        $period = self::yearPeriod($year);
        $out[] = $row('A', sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year),
            $kpi && $period ? self::value($kpi, $period[0], $period[1], (string) $objective->DEL3COD) : null);

        return $out;
    }

    /**
     * Línea del objetivo para el tema b de la revisión por la dirección, como
     * TemaObjetivos del escritorio: «Descripción (delegación): valor unidad
     * (meta >= x unidad), estado». Descripción del objetivo o, si falta, la del
     * indicador; la delegación, por su nombre, si tiene. Cerrado: el valor
     * alcanzado y el texto del resultado en minúsculas (sin resultado,
     * «cerrado»). Abierto: el valor del 1 de enero a $hasta (por defecto, el
     * periodo del año de yearPeriod) y «se cumple» o «no se cumple» si hay
     * valor y meta. Sin valor, «sin datos»; sin meta, sin el paréntesis.
     */
    public static function summaryLine(object $objective, ?string $hasta = null): string
    {
        $kpi = self::indicator((string) $objective->KPI2DEL, (int) $objective->KPI2COD);
        $goal = self::number($objective->OBJNMET);
        $sense = $kpi->KPICSEN ?? 'S';
        $decimals = self::decimals($kpi);
        $unit = (string) ($kpi->KPICUNI ?? '');

        $name = trim((string) $objective->OBJCDES);
        if ($name === '') {
            $name = trim((string) ($kpi->KPICDES ?? ''));
        }
        if ((string) $objective->DEL3COD !== '') {
            $delegation = trim((string) DB::connection('dynamic')->table('ACCDEL')->where('DEL1COD', (string) $objective->DEL3COD)->value('DELCNOM'));
            if ($delegation !== '') {
                $name .= ' ('.$delegation.')';
            }
        }

        if (self::state($objective) === 'C') {
            $value = self::number($objective->OBJNVAL);
            $state = mb_strtolower(self::RESULT_NAMES[(string) ($objective->OBJCRES ?? '')] ?? self::STATE_NAMES['C']);
        } else {
            $year = (int) $objective->OBJNANI;
            $value = null;
            if ($kpi) {
                $value = $hasta !== null
                    ? self::value($kpi, sprintf('%04d-01-01', $year), $hasta, (string) $objective->DEL3COD)
                    : self::calculatedValue($objective, $kpi);
            }
            $state = $value !== null && $goal !== null ? (self::complies($value, $goal, $sense) ? 'se cumple' : 'no se cumple') : '';
        }

        $line = $name.': '.($value === null ? 'sin datos' : self::formatValue($value, $decimals, $unit));
        if ($goal !== null) {
            $line .= ' (meta '.self::goalText($goal, $sense, $decimals, $unit).')';
        }

        return $state !== '' ? $line.', '.$state : $line;
    }

    // ------------------------------------------------------------------
    // Valor de un indicador
    // ------------------------------------------------------------------

    /**
     * Valor del indicador de $desde a $hasta (aaaa-mm-dd, ambos incluidos)
     * para la delegación ('' o null = todo el laboratorio). Automáticos: el
     * cálculo de KPICCAL; manuales: la media de los valores de CALKPV cuyo
     * inicio (KPVDINI) cae en el periodo. Null si no hay datos, el
     * denominador es 0 o la consulta falla.
     */
    public static function value(object $kpi, string $desde, string $hasta, ?string $delegacion): ?float
    {
        $from = substr($desde, 0, 10);
        $to = substr($hasta, 0, 10);
        if ($from === '' || $to === '' || $from > $to) {
            return null;
        }
        try {
            $until = (new \DateTime($to))->modify('+1 day')->format('Y-m-d').' 00:00:00';   // exclusivo
            $today = self::today();
            $c = [
                'from'  => $from.' 00:00:00',
                'until' => $until,
                // Corte de lo que se mide «al final del periodo» (acciones, equipos y proveedores), exclusivo: el día
                // siguiente al fin o, si el fin es hoy o posterior, hoy (KpiCalcular del escritorio)
                'cut'   => $to >= $today ? $today.' 00:00:00' : $until,
                'today' => $to >= $today,
                'year'  => (int) substr($to, 0, 4),
                'del'   => trim((string) $delegacion),
            ];
            if (! self::isAutomatic($kpi)) {
                return self::manualValue($kpi, $c);
            }
            $method = self::CALCULATIONS[strtoupper(trim((string) $kpi->KPICCAL))] ?? null;
            if ($method === null) {
                return null;
            }
            $value = self::$method($c);
        } catch (\Throwable) {
            return null;
        }

        return $value === null ? null : (float) $value;
    }

    /** Media de los valores manuales con inicio en el periodo (la delegación no cuenta: son del indicador). */
    private static function manualValue(object $kpi, array $c): ?float
    {
        $average = DB::connection('dynamic')->table(self::VALUES)
            ->where('DEL3COD', (string) $kpi->DEL3COD)->where('KPI3COD', (int) $kpi->KPI1COD)
            ->where('KPVDINI', '>=', $c['from'])->where('KPVDINI', '<', $c['until'])
            ->whereNotNull('KPVNVAL')
            ->avg('KPVNVAL');

        return $average === null ? null : (float) $average;
    }

    // --- Condiciones comunes ---------------------------------------------

    private static function db()
    {
        return DB::connection('dynamic');
    }

    /** Fecha en el periodo (desde incluido, hasta el día siguiente al fin excluido). */
    private static function inPeriod($query, string $column, array $c)
    {
        return $query->where($column, '>=', $c['from'])->where($column, '<', $c['until']);
    }

    /** Solo la delegación (si se indica). */
    private static function own($query, string $column, array $c)
    {
        if ($c['del'] !== '') {
            $query->where($column, $c['del']);
        }

        return $query;
    }

    /** Maestros comunes: la delegación y los comunes ('') (si se indica). */
    private static function shared($query, string $column, array $c)
    {
        if ($c['del'] !== '') {
            $query->whereIn($column, [$c['del'], '']);
        }

        return $query;
    }

    /** No anulada (estado vacío o distinto de A). */
    private static function notAnnulled($query, string $column)
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<>', 'A'));
    }

    /** Operación no anulada (OPEBANU distinto de T). */
    private static function liveOperation($query)
    {
        return $query->where(fn ($q) => $q->whereNull('LABOPE.OPEBANU')->orWhere('LABOPE.OPEBANU', '<>', 'T'));
    }

    /** Porcentaje; null si el denominador es 0. */
    private static function percent(int $part, int $total): ?float
    {
        return $total > 0 ? $part * 100 / $total : null;
    }

    /** Media de días entre dos fechas (sin horas) de las filas de la consulta; null sin filas. */
    private static function averageDays($query, string $later, string $earlier): ?float
    {
        $row = $query->whereNotNull($later)->whereNotNull($earlier)
            ->selectRaw("AVG(DATEDIFF({$later}, {$earlier})) AS MEDIA")->first();

        return $row === null || $row->MEDIA === null ? null : (float) $row->MEDIA;
    }

    /** Incidencias de la delegación (sin filtrar el estado). */
    private static function incidents(array $c)
    {
        return self::own(self::db()->table('CALINC'), 'CALINC.DEL3COD', $c);
    }

    // --- Cálculos (uno por KPICCAL) ----------------------------------------

    /**
     * INF_PLAZO: operaciones no anuladas con fecha de informe (OPEDINF) en el
     * periodo y fecha de compromiso (OPEDCOM); en plazo, las que tienen
     * DATE(OPEDINF) <= DATE(OPEDCOM). % en plazo sobre esas.
     */
    private static function reportsOnTime(array $c): ?float
    {
        $base = fn () => self::liveOperation(self::own(self::inPeriod(self::db()->table('LABOPE'), 'LABOPE.OPEDINF', $c), 'LABOPE.DEL3COD', $c))
            ->whereNotNull('LABOPE.OPEDCOM');
        $total = $base()->count();
        $onTime = $base()->whereRaw('DATE(LABOPE.OPEDINF) <= DATE(LABOPE.OPEDCOM)')->count();

        return self::percent($onTime, $total);
    }

    /** INF_DIAS: media de DATEDIFF(OPEDINF, OPEDREG) de las operaciones no anuladas con OPEDINF en el periodo. */
    private static function reportDays(array $c): ?float
    {
        return self::averageDays(self::liveOperation(self::own(self::inPeriod(self::db()->table('LABOPE'), 'LABOPE.OPEDINF', $c), 'LABOPE.DEL3COD', $c)),
            'LABOPE.OPEDINF', 'LABOPE.OPEDREG');
    }

    /** INF_SUST: informes creados (INFDCRE) en el periodo que sustituyen a otro (INF2COD no nulo), en % sobre todos los creados. */
    private static function replacedReports(array $c): ?float
    {
        $base = fn () => self::own(self::inPeriod(self::db()->table('LABINF'), 'LABINF.INFDCRE', $c), 'LABINF.DEL3COD', $c);
        $total = $base()->count();
        $replacing = $base()->whereNotNull('LABINF.INF2COD')->count();

        return self::percent($replacing, $total);
    }

    /**
     * QUE_MIL: quejas (INCCCLA Q, no anuladas) detectadas (INCDDET) en el
     * periodo × 1.000 / operaciones no anuladas registradas (OPEDREG) en el
     * periodo.
     */
    private static function complaintsPerThousand(array $c): ?float
    {
        $complaints = self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->where('CALINC.INCCCLA', 'Q')->count();
        $operations = self::liveOperation(self::own(self::inPeriod(self::db()->table('LABOPE'), 'LABOPE.OPEDREG', $c), 'LABOPE.DEL3COD', $c))->count();

        return $operations > 0 ? $complaints * 1000 / $operations : null;
    }

    /** QUE_DIAS: media de DATEDIFF(INCDCIE, INCDDET) de las quejas cerradas (INCCEST C) con INCDCIE en el periodo. */
    private static function complaintDays(array $c): ?float
    {
        return self::averageDays(self::inPeriod(self::incidents($c), 'CALINC.INCDCIE', $c)->where('CALINC.INCCCLA', 'Q')
            ->where('CALINC.INCCEST', 'C'), 'CALINC.INCDCIE', 'CALINC.INCDDET');
    }

    /** INC_NC: incidencias no anuladas con INCBNCO = T detectadas (INCDDET) en el periodo. */
    private static function nonConformities(array $c): ?float
    {
        return (float) self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->where('CALINC.INCBNCO', 'T')->count();
    }

    /** INC_DIAS: media de DATEDIFF(INCDCIE, INCDDET) de las incidencias (todas las clases) cerradas con INCDCIE en el periodo. */
    private static function incidentDays(array $c): ?float
    {
        return self::averageDays(self::inPeriod(self::incidents($c), 'CALINC.INCDCIE', $c)->where('CALINC.INCCEST', 'C'),
            'CALINC.INCDCIE', 'CALINC.INCDDET');
    }

    /** Acciones (CALACC) con su incidencia, de la delegación. */
    private static function actions(array $c)
    {
        return self::own(self::db()->table('CALACC')->join('CALINC', function ($j) {
            $j->on('CALINC.DEL3COD', '=', 'CALACC.DEL3COD')->on('CALINC.INC1SER', '=', 'CALACC.INC3SER')
                ->on('CALINC.INC1COD', '=', 'CALACC.INC3COD');
        }), 'CALACC.DEL3COD', $c);
    }

    /**
     * INC_EFIC: acciones correctivas (ACCCTIP A) de incidencias no anuladas
     * con la eficacia verificada (ACCDEFI) en el periodo y valorada
     * (ACCBEFI T o F); % de eficaces (T).
     */
    private static function effectiveActions(array $c): ?float
    {
        $base = fn () => self::inPeriod(self::notAnnulled(self::actions($c), 'CALINC.INCCEST'), 'CALACC.ACCDEFI', $c)
            ->where('CALACC.ACCCTIP', 'A')->whereIn('CALACC.ACCBEFI', ['T', 'F']);
        $total = $base()->count();
        $effective = $base()->where('CALACC.ACCBEFI', 'T')->count();

        return self::percent($effective, $total);
    }

    /**
     * ACC_VENC: acciones con fecha prevista anterior al corte (ACCDPRE <
     * corte) sin realizar entonces, de incidencias no anuladas abiertas
     * entonces. Con el corte en hoy: ACCDREA nula e incidencia no cerrada;
     * en un periodo pasado: ACCDREA nula o >= corte, e incidencia no cerrada
     * o cerrada (INCDCIE) en el corte o después.
     */
    private static function overdueActions(array $c): ?float
    {
        $query = self::notAnnulled(self::db()->table('CALACC')->join('CALINC', function ($j) {
            $j->on('CALINC.DEL3COD', '=', 'CALACC.DEL3COD')->on('CALINC.INC1SER', '=', 'CALACC.INC3SER')
                ->on('CALINC.INC1COD', '=', 'CALACC.INC3COD');
        }), 'CALINC.INCCEST')->where('CALACC.ACCDPRE', '<', $c['cut']);
        if ($c['today']) {
            $query->whereNull('CALACC.ACCDREA')
                ->where(fn ($q) => $q->whereNull('CALINC.INCCEST')->orWhere('CALINC.INCCEST', '<>', 'C'));
        } else {
            $query->where(fn ($q) => $q->whereNull('CALACC.ACCDREA')->orWhere('CALACC.ACCDREA', '>=', $c['cut']))
                ->where(fn ($q) => $q->whereNull('CALINC.INCCEST')->orWhere('CALINC.INCCEST', '<>', 'C')->orWhere('CALINC.INCDCIE', '>=', $c['cut']));
        }

        return (float) self::own($query, 'CALINC.DEL3COD', $c)->count();
    }

    /**
     * EAP_SAT: resultados (CALEAR) de las rondas con informe del organizador
     * (CALEAP.EAPDINF) en el periodo; evaluados = EARCEVA S, C, Q, I, P o N;
     * satisfactorios = S o C. % sobre los evaluados.
     */
    private static function satisfactoryResults(array $c): ?float
    {
        $rows = self::own(self::inPeriod(self::db()->table('CALEAR')->join('CALEAP', function ($j) {
            $j->on('CALEAP.DEL3COD', '=', 'CALEAR.DEL3COD')->on('CALEAP.EAP1SER', '=', 'CALEAR.EAP3SER')
                ->on('CALEAP.EAP1COD', '=', 'CALEAR.EAP3COD');
        }), 'CALEAP.EAPDINF', $c), 'CALEAP.DEL3COD', $c)
            ->whereIn('CALEAR.EARCEVA', ['S', 'C', 'Q', 'I', 'P', 'N'])
            ->groupBy('CALEAR.EARCEVA')->get(['CALEAR.EARCEVA', DB::raw('COUNT(*) AS NEAR')]);
        $total = 0;
        $satisfactory = 0;
        foreach ($rows as $r) {
            $total += (int) $r->NEAR;
            if (in_array((string) $r->EARCEVA, ['S', 'C'], true)) {
                $satisfactory += (int) $r->NEAR;
            }
        }

        return self::percent($satisfactory, $total);
    }

    /**
     * EAP_PLAN (KpiCoberturaPlan): familias del plan (CALFAE) no de baja, de
     * la delegación y comunes; cubiertas, las que tienen alguna ronda con
     * resultados de sus parámetros (CALFAT → CALEAR → CALEAP, rondas de la
     * delegación) con fecha COALESCE(EAPDREC, EAPDLIM) anterior al día
     * siguiente al fin del periodo y año >= año del fin − FAENFRE + 1
     * (frecuencia mínima 1). % de cubiertas sobre todas.
     */
    private static function planCoverage(array $c): ?float
    {
        $families = self::shared(self::db()->table('CALFAE'), 'CALFAE.DEL3COD', $c)
            ->where(fn ($q) => $q->whereNull('CALFAE.FAEBBAJ')->orWhere('CALFAE.FAEBBAJ', '<>', 'T'))
            ->get(['CALFAE.DEL3COD', 'CALFAE.FAE1COD', 'CALFAE.FAENFRE'])
            ->keyBy(fn ($f) => $f->DEL3COD."\x1B".(int) $f->FAE1COD);
        if ($families->isEmpty()) {
            return null;
        }
        $rounds = self::own(self::db()->table('CALFAT')
            ->join('CALEAR', fn ($j) => $j->on('CALEAR.TEC2DEL', '=', 'CALFAT.TEC3DEL')->on('CALEAR.TEC2COD', '=', 'CALFAT.TEC3COD'))
            ->join('CALEAP', fn ($j) => $j->on('CALEAP.DEL3COD', '=', 'CALEAR.DEL3COD')->on('CALEAP.EAP1SER', '=', 'CALEAR.EAP3SER')
                ->on('CALEAP.EAP1COD', '=', 'CALEAR.EAP3COD')), 'CALEAP.DEL3COD', $c)
            ->whereRaw('COALESCE(CALEAP.EAPDREC, CALEAP.EAPDLIM) < ?', [$c['until']])
            ->groupBy('CALFAT.DEL3COD', 'CALFAT.FAE3COD')
            ->get(['CALFAT.DEL3COD', 'CALFAT.FAE3COD', DB::raw('MAX(YEAR(COALESCE(CALEAP.EAPDREC, CALEAP.EAPDLIM))) AS ULTIMO')]);
        $covered = 0;
        foreach ($rounds as $r) {
            $family = $families[$r->DEL3COD."\x1B".(int) $r->FAE3COD] ?? null;
            if ($family && (int) $r->ULTIMO > 0 && (int) $r->ULTIMO >= $c['year'] - max(1, (int) $family->FAENFRE) + 1) {
                $covered++;
            }
        }

        return self::percent($covered, $families->count());
    }

    /**
     * AUD_PROG: auditorías internas (AUICTIP I, C, vacío o nulo) no anuladas
     * con fecha prevista (AUIDPRE) en el periodo; realizadas = estado R, I o
     * C. % de realizadas sobre las previstas.
     */
    private static function auditProgramme(array $c): ?float
    {
        $base = fn () => self::own(self::inPeriod(self::notAnnulled(self::db()->table('CALAUI'), 'CALAUI.AUICEST'), 'CALAUI.AUIDPRE', $c), 'CALAUI.DEL3COD', $c)
            ->where(fn ($w) => $w->whereIn('CALAUI.AUICTIP', self::INTERNAL_AUDITS)->orWhereNull('CALAUI.AUICTIP')->orWhere('CALAUI.AUICTIP', ''));
        $planned = $base()->count();
        $done = $base()->whereIn('CALAUI.AUICEST', ['R', 'I', 'C'])->count();

        return self::percent($done, $planned);
    }

    /** AUD_HALL: incidencias de clase hallazgo (INCCCLA N) no anuladas detectadas (INCDDET) en el periodo. */
    private static function auditFindings(array $c): ?float
    {
        return (float) self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->where('CALINC.INCCCLA', 'N')->count();
    }

    /**
     * FOR_PLAN: cursos del plan (GRHPAF) con COALESCE(PAFDPRE, PAFDINI) en el
     * periodo, sin cancelados (C) ni rechazados (H); realizados = estado R o
     * E (evaluado). % sobre los previstos.
     */
    private static function trainingPlan(array $c): ?float
    {
        $base = fn () => self::own(self::db()->table('GRHPAF'), 'GRHPAF.DEL3COD', $c)
            ->whereRaw('COALESCE(GRHPAF.PAFDPRE, GRHPAF.PAFDINI) >= ? AND COALESCE(GRHPAF.PAFDPRE, GRHPAF.PAFDINI) < ?', [$c['from'], $c['until']])
            ->where(fn ($q) => $q->whereNull('GRHPAF.PAFCEST')->orWhereNotIn('GRHPAF.PAFCEST', ['C', 'H']));
        $planned = $base()->count();
        $done = $base()->whereIn('GRHPAF.PAFCEST', ['R', 'E'])->count();

        return self::percent($done, $planned);
    }

    /**
     * FOR_HORAS: suma de PAFNHOR por cada alumno que asistió (GRHALU con
     * ALUBNAS distinto de T) de los cursos del plan realizados o evaluados
     * (R, E) que empiezan en el periodo (COALESCE(PAFDINI, PAFDPRE)), dividida
     * por los empleados en activo en algún momento del periodo: alta
     * (EMPDALT) nula o anterior al día siguiente al fin, y baja (EMPDBAJ) en
     * el inicio o después, o sin fecha de baja y no de baja (EMPBBAJ distinto
     * de T). Null sin empleados.
     */
    private static function trainingHours(array $c): ?float
    {
        $hours = (float) self::own(self::db()->table('GRHALU')
            ->join('GRHPAF', fn ($j) => $j->on('GRHPAF.DEL3COD', '=', 'GRHALU.PAF3DEL')->on('GRHPAF.PAF1COD', '=', 'GRHALU.PAF3COD')), 'GRHPAF.DEL3COD', $c)
            ->whereRaw('COALESCE(GRHPAF.PAFDINI, GRHPAF.PAFDPRE) >= ? AND COALESCE(GRHPAF.PAFDINI, GRHPAF.PAFDPRE) < ?', [$c['from'], $c['until']])
            ->whereIn('GRHPAF.PAFCEST', ['R', 'E'])
            ->where(fn ($q) => $q->whereNull('GRHALU.ALUBNAS')->orWhere('GRHALU.ALUBNAS', '<>', 'T'))
            ->sum('GRHPAF.PAFNHOR');
        $employees = self::own(self::db()->table('GRHEMP'), 'GRHEMP.DEL3COD', $c)
            ->where(fn ($q) => $q->whereNull('GRHEMP.EMPDALT')->orWhere('GRHEMP.EMPDALT', '<', $c['until']))
            ->where(fn ($q) => $q->where('GRHEMP.EMPDBAJ', '>=', $c['from'])
                ->orWhere(fn ($w) => $w->whereNull('GRHEMP.EMPDBAJ')->where(fn ($x) => $x->whereNull('GRHEMP.EMPBBAJ')->orWhere('GRHEMP.EMPBBAJ', '<>', 'T'))))
            ->count();

        return $employees > 0 ? $hours / $employees : null;
    }

    /**
     * EQU_VENC: series (ALMSEL) de productos que son equipos (PRDBEQU T), de
     * la delegación y comunes, sin fecha de baja ni estado B o F, con la
     * calibración (SELDCAL) o la verificación (SELDVER) anterior al corte.
     */
    private static function overdueEquipment(array $c): ?float
    {
        return (float) self::shared(self::db()->table('ALMSEL')
            ->join('ALMPRD', fn ($j) => $j->on('ALMSEL.PRD3DEL', '=', 'ALMPRD.DEL3COD')->on('ALMSEL.PRD3COD', '=', 'ALMPRD.PRD1COD')), 'ALMSEL.PRD3DEL', $c)
            ->where('ALMPRD.PRDBEQU', 'T')
            ->where(fn ($q) => $q->whereNull('ALMSEL.SELCESA')->orWhereNotIn('ALMSEL.SELCESA', ['B', 'F']))
            ->whereNull('ALMSEL.SELDBAJ')
            ->where(fn ($q) => $q->where('ALMSEL.SELDCAL', '<', $c['cut'])->orWhere('ALMSEL.SELDVER', '<', $c['cut']))
            ->count();
    }

    /**
     * PRO_EVAL: proveedores (de la delegación y comunes) no de baja (PROBBAJ
     * distinto de T) con alguna evaluación (PRODUEV o PRODPEV no nula); al
     * día, los que no tienen próxima evaluación (PRODPEV nula) o la tienen en
     * el corte o después. % al día sobre todos ellos.
     */
    private static function supplierEvaluation(array $c): ?float
    {
        $base = fn () => self::shared(self::db()->table('SINPRO'), 'SINPRO.DEL3COD', $c)
            ->where(fn ($q) => $q->whereNull('SINPRO.PROBBAJ')->orWhere('SINPRO.PROBBAJ', '<>', 'T'))
            ->where(fn ($q) => $q->whereNotNull('SINPRO.PRODUEV')->orWhereNotNull('SINPRO.PRODPEV'));
        $evaluated = $base()->count();
        $upToDate = $base()->where(fn ($q) => $q->whereNull('SINPRO.PRODPEV')->orWhere('SINPRO.PRODPEV', '>=', $c['cut']))->count();

        return self::percent($upToDate, $evaluated);
    }

    /** GCO_FUERA: puntos de los gráficos de control (LABRCD, de la delegación y comunes) con fecha (RCDTFEC) en el periodo y RCDCTII = E. */
    private static function outOfControlPoints(array $c): ?float
    {
        return (float) self::shared(self::inPeriod(self::db()->table('LABRCD'), 'LABRCD.RCDTFEC', $c), 'LABRCD.CDC3DEL', $c)
            ->where('LABRCD.RCDCTII', 'E')->count();
    }
}
