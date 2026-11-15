<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión por la dirección, fase 1 (módulo CAL, desde Veolab 3; ISO/IEC
 * 17025:2017 8.9): revisiones (CALRDI) con sus asistentes (CALRDA), los
 * quince temas de entrada de 8.9.2 (CALRDX) y las delegaciones incluidas
 * (CALRDL; sin filas, todas). Las decisiones son incidencias de clase M con
 * origen D enlazadas con CALIYE tipo CALRDI. Sigue el modelo común con el
 * escritorio («Revision por la direccion - modelo de datos.md»).
 *
 * «Recoger datos» (collect()) escribe en cada tema con datos automáticos
 * (b-o) un resumen en texto llano con líneas «Concepto: valor» y, cuando
 * hay pocos registros abiertos (hasta 10), su código y título. La API no
 * tiene idioma de usuario: los textos van en español. Si una tabla o una
 * columna no existe en la base de datos, el dato se omite sin error.
 */
class VeolabManagementReview
{
    public const TABLE = 'CALRDI';

    /** Funcionalidad de Veolab: especial 1 = responsable de calidad (crear, modificar, cambiar de estado y eliminar). */
    public const FUNCTIONALITY = 'CAL_RDI';

    /** Letras de los temas de 8.9.2, en el orden de la norma (RDI_TEMAS). */
    public const TOPIC_LETTERS = 'abcdefghijklmno';

    /** Título corto de cada tema (el del escritorio, en español). */
    public const TOPICS = [
        'a' => 'Cambios internos y externos',
        'b' => 'Cumplimiento de los objetivos',
        'c' => 'Políticas y procedimientos',
        'd' => 'Acciones de revisiones anteriores',
        'e' => 'Auditorías internas',
        'f' => 'Acciones correctivas',
        'g' => 'Evaluaciones externas',
        'h' => 'Volumen y tipo de trabajo',
        'i' => 'Opinión de clientes y personal',
        'j' => 'Quejas',
        'k' => 'Eficacia de las mejoras',
        'l' => 'Recursos',
        'm' => 'Riesgos',
        'n' => 'Validez de los resultados',
        'o' => 'Otros (formación, plazos)',
    ];

    /**
     * Temas con datos automáticos («Recoger datos»); b, los objetivos de
     * calidad (VeolabQualityIndicators); c, el control de documentos (desde
     * la fase 2, 14-nov-2026).
     */
    public const AUTOMATIC_TOPICS = 'bcdefghijklmno';

    /** Borrador, celebrada, aprobada y anulada. */
    public const STATE_NAMES = [
        'B' => 'Borrador',
        'C' => 'Celebrada',
        'P' => 'Aprobada',
        'A' => 'Anulada',
    ];

    /** Aprobada o anulada: solo lectura (salvo Documentos). */
    public const READ_ONLY_STATES = ['P', 'A'];

    /** Registros abiertos que se listan con su código y título como mucho. */
    public const MAX_LISTED = 10;

    /** Clase y origen de las decisiones (incidencias de mejora con origen «Revisión por la dirección»). */
    public const DECISION_CLASS = 'M';
    public const DECISION_ORIGIN = 'D';

    /** Textos de las clases de incidencia (ETI00800-804). */
    private const CLASS_NAMES = [
        'Q' => 'Queja',
        'T' => 'Trabajo no conforme',
        'N' => 'Hallazgo de auditoría',
        'M' => 'Mejora',
        'R' => 'Riesgo',
    ];

    private const EOL = "\r\n";   // como los cuadros de texto de Veolab

    private static array $available = [];

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** ¿Tiene la base de datos las tablas de la revisión por la dirección? */
    public static function available(): bool
    {
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$available)) {
            try {
                self::$available[$database] = Schema::connection('dynamic')->hasTable(self::TABLE);
            } catch (\Throwable) {
                self::$available[$database] = false;
            }
        }

        return self::$available[$database];
    }

    /** ¿Existe la columna? (false si no se puede saber) */
    public static function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::connection('dynamic')->hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Revisión (CALRDI) o null; con $lock, bloqueada hasta el commit. */
    public static function review(string $del, string $ser, int $cod, bool $lock = false): ?object
    {
        $query = DB::connection('dynamic')->table(self::TABLE)
            ->where('DEL3COD', $del)->where('RDI1SER', $ser)->where('RDI1COD', $cod);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /** Estado de la revisión ('' cuenta como borrador). */
    public static function state(object $review): string
    {
        $state = (string) ($review->RDICEST ?? '');

        return $state === '' ? 'B' : $state;
    }

    /** ¿Es un tema de la lista? */
    public static function isTopic(?string $letter): bool
    {
        return $letter !== null && strlen($letter) === 1 && str_contains(self::TOPIC_LETTERS, $letter);
    }

    /** ¿Tema con datos automáticos? */
    public static function isAutomatic(string $letter): bool
    {
        return strlen($letter) === 1 && str_contains(self::AUTOMATIC_TOPICS, $letter);
    }

    /** Fecha (texto de la BD) como dd/mm/aaaa; '' si está vacía. */
    public static function date($value): string
    {
        return $value ? (new \DateTime(substr((string) $value, 0, 10)))->format('d/m/Y') : '';
    }

    // ------------------------------------------------------------------
    // Usuario de la petición (la API no tiene sesión)
    // ------------------------------------------------------------------

    /**
     * Usuario de Veolab con su acceso a CAL_RDI: ['activo', 'escritura',
     * 'calidad' (especial 1: responsable de calidad), 'delegacion',
     * 'codigo']. Null si no se indica; 422 si no existe.
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
            // (PYFNACC MOD 8) >= 4 AND (PYFNACC MOD 2) = 1, como VeolabQuality
            'calidad'    => ($access % 8) >= 4 && ($access % 2) === 1,
            'delegacion' => (string) $del,
            'codigo'     => $cod,
        ];
    }

    /**
     * Solo el responsable de calidad (especial 1 de CAL_RDI, con escritura)
     * crea, modifica, cambia de estado, recoge datos y elimina. Sin usuario,
     * la API actúa como responsable de calidad, como en incidencias.
     */
    public static function assertManager(?array $user): void
    {
        if ($user !== null && (! $user['activo'] || ! $user['escritura'] || ! $user['calidad'])) {
            throw new BusinessRuleException('Solo el responsable de calidad puede crear, modificar o eliminar revisiones por la dirección');
        }
    }

    // ------------------------------------------------------------------
    // Periodo y título por defecto
    // ------------------------------------------------------------------

    /**
     * Periodo por defecto al crearla: desde el día siguiente al fin del
     * periodo de la última revisión no anulada hasta hoy; si no hay ninguna,
     * el año natural anterior. Devuelve [inicio, fin] como 'aaaa-mm-dd 00:00:00'.
     */
    public static function defaultPeriod(): array
    {
        $db = DB::connection('dynamic');
        $today = (string) $db->selectOne('SELECT CURDATE() AS d')->d;
        $last = $db->table(self::TABLE)
            ->where(fn ($q) => $q->whereNull('RDICEST')->orWhere('RDICEST', '<>', 'A'))
            ->whereNotNull('RDIDFIN')
            ->max('RDIDFIN');
        // RDI_PeriodoPorDefecto: solo si la última acaba antes de hoy; si acaba hoy o después, el año anterior
        if ($last && substr((string) $last, 0, 10) < $today) {
            $from = (new \DateTime(substr((string) $last, 0, 10)))->modify('+1 day')->format('Y-m-d');

            return [$from.' 00:00:00', $today.' 00:00:00'];
        }
        $year = (int) substr($today, 0, 4) - 1;

        return ["{$year}-01-01 00:00:00", "{$year}-12-31 00:00:00"];
    }

    /** Título por defecto: «Revisión por la dirección <año de fin del periodo>». */
    public static function defaultTitle(?string $periodEnd): string
    {
        $year = $periodEnd ? substr($periodEnd, 0, 4) : (string) DB::connection('dynamic')->selectOne('SELECT YEAR(CURDATE()) AS y')->y;

        return 'Revisión por la dirección '.$year;
    }

    // ------------------------------------------------------------------
    // Decisiones: incidencias enlazadas (CALIYE tipo CALRDI)
    // ------------------------------------------------------------------

    /**
     * Decisiones de varias revisiones: [clave "del\x1Bser\x1Bcod" => lista]
     * con cada incidencia enlazada: código, título, responsable, fecha
     * prevista de la acción pendiente más próxima y estado. $reviews =
     * [[del, ser, cod], ...].
     */
    public static function decisions(array $reviews): array
    {
        if (! $reviews) {
            return [];
        }
        $db = DB::connection('dynamic');
        $rows = $db->table('CALIYE')
            ->join('CALINC', function ($j) {
                $j->on('CALINC.DEL3COD', '=', 'CALIYE.DEL3COD')->on('CALINC.INC1SER', '=', 'CALIYE.INC3SER')
                    ->on('CALINC.INC1COD', '=', 'CALIYE.INC3COD');
            })
            ->leftJoin('GRHEMP', fn ($j) => $j->on('GRHEMP.DEL3COD', '=', 'CALINC.EMR2DEL')->on('GRHEMP.EMP1COD', '=', 'CALINC.EMR2COD'))
            ->where('CALIYE.IYECTAB', self::TABLE)
            ->where(function ($q) use ($reviews) {
                foreach ($reviews as [$del, $ser, $cod]) {
                    $q->orWhere(fn ($w) => $w->where('CALIYE.IYECDEL', (string) $del)->where('CALIYE.IYECSER', (string) $ser)
                        ->where('CALIYE.IYECCOD', (string) $cod));
                }
            })
            ->distinct()
            ->orderBy('CALINC.INCDDET')->orderBy('CALINC.DEL3COD')->orderBy('CALINC.INC1SER')->orderBy('CALINC.INC1COD')
            ->get(['CALIYE.IYECDEL', 'CALIYE.IYECSER', 'CALIYE.IYECCOD', 'CALINC.DEL3COD', 'CALINC.INC1SER', 'CALINC.INC1COD',
                'CALINC.INCCDES', 'CALINC.INCCCLA', 'CALINC.INCCORI', 'CALINC.INCCEST', 'CALINC.INCDDET', 'CALINC.INCDCIE',
                'CALINC.EMR2DEL', 'CALINC.EMR2COD', 'GRHEMP.EMPCNOM']);
        if ($rows->isEmpty()) {
            return [];
        }

        // Acción pendiente (sin fecha real) más próxima de cada incidencia
        $next = $db->table('CALACC')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $r) {
                    $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $r->DEL3COD)->where('INC3SER', (string) $r->INC1SER)
                        ->where('INC3COD', (int) $r->INC1COD));
                }
            })
            ->whereNull('ACCDREA')->whereNotNull('ACCDPRE')
            ->groupBy('DEL3COD', 'INC3SER', 'INC3COD')
            ->get(['DEL3COD', 'INC3SER', 'INC3COD', DB::raw('MIN(ACCDPRE) AS proxima')])
            ->keyBy(fn ($a) => $a->DEL3COD."\x1B".$a->INC3SER."\x1B".(int) $a->INC3COD);

        $out = [];
        foreach ($rows as $r) {
            $state = VeolabQuality::state($r);
            $hasResponsible = (int) ($r->EMR2COD ?? 0) !== 0;
            $out[$r->IYECDEL."\x1B".$r->IYECSER."\x1B".$r->IYECCOD][] = [
                'delegacion'           => (string) $r->DEL3COD,
                'serie'                => (string) $r->INC1SER,
                'codigo'               => (int) $r->INC1COD,
                'codigo_formateado'    => VeolabCodes::format('CALINC', (string) $r->INC1COD, (string) $r->DEL3COD, (string) $r->INC1SER),
                'descripcion'          => (string) $r->INCCDES,
                'clase'                => $r->INCCCLA,
                'origen'               => $r->INCCORI,
                'responsable_delegacion' => $hasResponsible ? (string) $r->EMR2DEL : null,
                'responsable_codigo'   => $hasResponsible ? (int) $r->EMR2COD : null,
                'responsable_nombre'   => $hasResponsible ? $r->EMPCNOM : null,
                'fecha_prevista'       => $next[$r->DEL3COD."\x1B".$r->INC1SER."\x1B".(int) $r->INC1COD]->proxima ?? null,
                'estado'               => $state,
                'estado_texto'         => VeolabQuality::STATE_NAMES[$state] ?? $state,
                'abierta'              => in_array($state, VeolabQuality::OPEN_STATES, true) ? 'T' : 'F',
                'fecha_deteccion'      => $r->INCDDET,
                'fecha_cierre'         => $r->INCDCIE,
            ];
        }

        return $out;
    }

    /** Número de decisiones (incidencias enlazadas) de una revisión. */
    public static function decisionCount(string $del, string $ser, int $cod): int
    {
        return DB::connection('dynamic')->table('CALIYE')->where('IYECTAB', self::TABLE)
            ->where('IYECDEL', $del)->where('IYECSER', $ser)->where('IYECCOD', (string) $cod)->count();
    }

    /** ¿Tiene documentos fuera de la papelera? (DOCFAT.RDI2SER/RDI2COD, si existen) */
    public static function hasDocuments(string $del, string $ser, int $cod): bool
    {
        if (! self::hasColumn('DOCFAT', 'RDI2SER') || ! self::hasColumn('DOCFAT', 'RDI2COD')) {
            return false;
        }

        return DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $del)->where('RDI2SER', $ser)->where('RDI2COD', $cod)
            ->where(fn ($q) => $q->whereNull('DIR2COD')->orWhere('DIR2COD', '<>', 0))
            ->exists();
    }

    /** Sin la fila de CALRDI, que ya ha borrado quien llama: asistentes, temas y delegaciones. */
    public static function deleteReviewData(string $del, string $ser, int $cod): void
    {
        $db = DB::connection('dynamic');
        foreach (['CALRDA', 'CALRDX', 'CALRDL'] as $table) {
            $db->table($table)->where('DEL3COD', $del)->where('RDI3SER', $ser)->where('RDI3COD', $cod)->delete();
        }
    }

    /** Delegaciones incluidas (CALRDL); vacía = todas. */
    public static function delegations(string $del, string $ser, int $cod): array
    {
        return DB::connection('dynamic')->table('CALRDL')
            ->where('DEL3COD', $del)->where('RDI3SER', $ser)->where('RDI3COD', $cod)
            ->orderBy('DEL2COD')->pluck('DEL2COD')->map(fn ($d) => (string) $d)->all();
    }

    // ------------------------------------------------------------------
    // Recoger datos
    // ------------------------------------------------------------------

    /**
     * Resumen de cada tema con datos automáticos (b-o) para el periodo de la
     * revisión (RDIDINI a RDIDFIN, ambos incluidos) y sus delegaciones (las
     * de CALRDL o todas): [letra => texto o null]. Cada dato se calcula por
     * separado: el que falla (tabla o columna que no existe) se omite.
     * $userDelegation: delegación del usuario que recoge ('' si trabaja con
     * todas), para el plan de intercomparaciones del tema n.
     */
    public static function collect(object $review, string $userDelegation = ''): array
    {
        $db = DB::connection('dynamic');
        $today = (string) $db->selectOne('SELECT CURDATE() AS d')->d;
        $from = substr((string) $review->RDIDINI, 0, 10);
        $to = substr((string) $review->RDIDFIN, 0, 10);
        $days = (int) (new \DateTime($from))->diff(new \DateTime($to))->days + 1;
        $c = [
            'review'     => $review,
            'from'       => $from.' 00:00:00',
            'until'      => (new \DateTime($to))->modify('+1 day')->format('Y-m-d').' 00:00:00',   // exclusivo
            'prev_from'  => (new \DateTime($from))->modify("-{$days} days")->format('Y-m-d').' 00:00:00',
            'today'      => $today,
            'tomorrow'   => (new \DateTime($today))->modify('+1 day')->format('Y-m-d').' 00:00:00',
            'dels'       => self::delegations((string) $review->DEL3COD, (string) $review->RDI1SER, (int) $review->RDI1COD),
            'user_del'   => $userDelegation,
        ];

        $out = [];
        foreach (str_split(self::AUTOMATIC_TOPICS) as $letter) {
            $lines = [];
            $method = 'topic'.strtoupper($letter);
            try {
                self::$method($lines, $c);
            } catch (\Throwable) {
                // Lo ya calculado se conserva; el resto del tema se omite.
            }
            $out[$letter] = $lines ? implode(self::EOL, $lines) : null;
        }

        return $out;
    }

    /** Número de un COUNT o null si la consulta falla (el dato no existe en esta base), como Contar del escritorio. */
    private static function tally(callable $query): ?int
    {
        try {
            return (int) $query();
        } catch (\Throwable) {
            return null;
        }
    }

    /** ¿Se han podido contar todos? (ninguno null) */
    private static function counted(...$values): bool
    {
        foreach ($values as $v) {
            if ($v === null) {
                return false;
            }
        }

        return true;
    }

    /** Texto del escritorio (IDICAD, idioma 1) con %1, %2... sustituidos. */
    private static function text(string $template, ...$values): string
    {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            $template = str_replace('%'.($i + 1), (string) $values[$i], $template);
        }

        return $template;
    }

    /** Fecha en el periodo de la revisión (desde incluido, hasta el día siguiente al fin excluido). */
    private static function inPeriod($query, string $column, array $c)
    {
        return $query->where($column, '>=', $c['from'])->where($column, '<', $c['until']);
    }

    /** Solo las delegaciones de la revisión (si las tiene); con $common, también los registros comunes (''). */
    private static function inDelegations($query, string $column, array $c, bool $common = false)
    {
        if ($c['dels']) {
            $query->whereIn($column, $common ? array_merge($c['dels'], ['']) : $c['dels']);
        }

        return $query;
    }

    /** No anulada (estado vacío o distinto de A). */
    private static function notAnnulled($query, string $column)
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<>', 'A'));
    }

    /** Incidencias de las delegaciones de la revisión (sin filtrar el estado). */
    private static function incidents(array $c)
    {
        return self::inDelegations(DB::connection('dynamic')->table('CALINC'), 'CALINC.DEL3COD', $c);
    }

    /** Abierta: registrada, en tratamiento, en verificación o sin estado (RDI_IncidenciaAbierta). */
    private static function isOpen(?string $state): bool
    {
        return in_array(trim((string) $state), ['', 'R', 'T', 'V'], true);
    }

    /**
     * RecorrerIncidencias: las incidencias de la consulta (ya filtrada) con
     * cuántas hay, cerradas, abiertas y abiertas con acciones vencidas, y la
     * lista de las abiertas («Abiertas:» y «- código título») si son pocas.
     * Null si la consulta falla.
     */
    private static function walkIncidents($query, array $c): ?array
    {
        try {
            $rows = $query->orderBy('CALINC.INCDDET')->orderBy('CALINC.INC1COD')
                ->get(['CALINC.DEL3COD', 'CALINC.INC1SER', 'CALINC.INC1COD', 'CALINC.INCCDES', 'CALINC.INCCEST',
                    DB::raw("(SELECT COUNT(*) FROM CALACC WHERE CALACC.DEL3COD = CALINC.DEL3COD AND CALACC.INC3SER = CALINC.INC1SER "
                        ."AND CALACC.INC3COD = CALINC.INC1COD AND CALACC.ACCDREA IS NULL AND CALACC.ACCDPRE < '{$c['today']}') AS NVEN")]);
        } catch (\Throwable) {
            return null;
        }
        $out = ['total' => 0, 'cerradas' => 0, 'abiertas' => 0, 'vencidas' => 0, 'lista' => ''];
        $open = [];
        foreach ($rows as $r) {
            $out['total']++;
            if (self::isOpen($r->INCCEST)) {
                $out['abiertas']++;
                if ((int) $r->NVEN > 0) {
                    $out['vencidas']++;
                }
                $open[] = '- '.VeolabCodes::format('CALINC', (string) $r->INC1COD, (string) $r->DEL3COD, (string) $r->INC1SER)
                    .' '.trim((string) $r->INCCDES);
            } elseif ((string) $r->INCCEST === 'C') {
                $out['cerradas']++;
            }
        }
        if ($open && count($open) <= self::MAX_LISTED) {
            $out['lista'] = implode(self::EOL, array_merge(['Abiertas:'], $open));
        }

        return $out;
    }

    /** Añade una línea si no está vacía (Linea del escritorio). */
    private static function add(array &$lines, ?string $line): void
    {
        if ($line !== null && $line !== '') {
            $lines[] = $line;
        }
    }

    /** Variación en % respecto al periodo anterior (entero con signo); «-» si antes no hubo ninguno. */
    private static function change(int $current, int $previous): string
    {
        if ($previous <= 0) {
            return '-';
        }
        $value = (int) round(($current - $previous) / $previous * 100);

        return ($value > 0 ? '+' : '').$value.' %';
    }

    // --- b: cumplimiento de los objetivos ------------------------------

    /**
     * TemaObjetivos: una línea por objetivo de calidad (CALOBJ) del año del
     * fin del periodo, en el orden de la clave: los de las delegaciones de la
     * revisión y los de todo el laboratorio (''); sin delegaciones, todos.
     * «Descripción (delegación): valor (meta >= x), estado», con el valor de
     * los abiertos del 1 de enero al fin del periodo
     * (VeolabQualityIndicators::summaryLine). Sin la tabla de objetivos (base
     * sin actualizar), nada.
     */
    private static function topicB(array &$lines, array $c): void
    {
        if (! VeolabQualityIndicators::available()) {
            return;
        }
        $year = (int) substr((string) $c['review']->RDIDFIN, 0, 4);
        $query = DB::connection('dynamic')->table(VeolabQualityIndicators::OBJECTIVES)->where('OBJNANI', $year);
        if ($c['dels']) {
            $query->whereIn('DEL3COD', array_merge($c['dels'], ['']));
        }
        $objectives = $query->orderBy('DEL3COD')->orderBy('OBJ1COD')->get();
        $to = substr((string) $c['review']->RDIDFIN, 0, 10);
        if ($objectives->isEmpty()) {
            $lines[] = self::text('No hay objetivos de calidad definidos para %1', $year);

            return;
        }
        foreach ($objectives as $objective) {
            $lines[] = VeolabQualityIndicators::summaryLine($objective, $to);
        }
    }

    // --- c: políticas y procedimientos ----------------------------------

    /**
     * TemaDocumentos (con el control de documentos activo):
     *  - ediciones de documentos controlados aprobadas en el periodo, con su
     *    lista si son pocas;
     *  - con la fase 2 (destinatarios y copias): lecturas pendientes y
     *    vencidas al fin del periodo y copias controladas por retirar.
     *
     * No hay histórico de destinatarios ni de ediciones vigentes: las
     * lecturas se aproximan con la situación actual (destinatarios y edición
     * vigente de hoy), sin contar las lecturas hechas después del fin del
     * periodo, y vencidas son las de plazo pasado al fin del periodo (o hoy,
     * si aún no ha terminado). Las copias por retirar son las de hoy.
     */
    private static function topicC(array &$lines, array $c): void
    {
        if (! VeolabDocumentControl::active()) {
            return;
        }
        $db = DB::connection('dynamic');

        $query = $db->table('DOCVER')->join('DOCFAT', function ($j) {
            $j->on('DOCFAT.DEL3COD', '=', 'DOCVER.DEL3COD')->on('DOCFAT.FAT1COD', '=', 'DOCVER.FAT3COD');
        })->where('DOCFAT.DIR2COD', '>', 0);
        VeolabDocumentControl::whereControlled($query, true, 'DOCFAT');
        self::inDelegations(self::inPeriod($query, 'DOCVER.VERDAPR', $c), 'DOCFAT.DEL3COD', $c, true);
        $editions = $query->orderBy('DOCVER.VERDAPR')->orderBy('DOCFAT.FATCCOD')
            ->get(['DOCFAT.FATCCOD', 'DOCFAT.FATCNOM', 'DOCVER.VERCEDI']);
        $lines[] = self::text('Ediciones de documentos controlados aprobadas: %1', $editions->count());
        if ($editions->isNotEmpty() && $editions->count() <= self::MAX_LISTED) {
            foreach ($editions as $e) {
                $edition = trim((string) $e->VERCEDI);
                $lines[] = '- '.self::documentText($e).($edition !== '' ? ' ('.$edition.')' : '');
            }
        }

        if (! VeolabDocumentControl::distributionAvailable()) {
            return;
        }

        // Lecturas pendientes (situación actual, lecturas hasta el fin del periodo)
        $query = VeolabDocumentControl::inForceDocuments();
        self::inDelegations($query, 'DOCFAT.DEL3COD', $c, true);
        $end = substr((string) $c['review']->RDIDFIN, 0, 10);
        $reference = min($end, substr($c['today'], 0, 10));
        $pending = 0;
        $overdue = 0;
        $withPending = [];
        foreach (VeolabDocumentControl::readingStatus($query->get()) as $status) {
            $read = array_filter($status['lecturas'], fn ($r) => $r['fecha'] === null || (string) $r['fecha'] < $c['until']);
            $count = count(array_diff_key($status['destinatarios'], $read));
            if ($count === 0) {
                continue;
            }
            $late = $status['fecha_limite'] !== null && $status['fecha_limite'] < $reference ? $count : 0;
            $pending += $count;
            $overdue += $late;
            $withPending[] = '- '.self::documentText($status['documento']).': '.$count.($late ? ' ('.$late.' vencidas)' : '');
        }
        $lines[] = self::text('Lecturas pendientes de las ediciones vigentes: %1 en %2 documentos, %3 vencidas (aproximado con los destinatarios y ediciones vigentes de hoy)',
            $pending, count($withPending), $overdue);
        if ($withPending && count($withPending) <= self::MAX_LISTED) {
            array_push($lines, ...$withPending);
        }

        // Copias controladas por retirar (hoy)
        $copies = self::tally(function () use ($db, $c) {
            $query = $db->table('DOCCOP')->join('DOCFAT', function ($j) {
                $j->on('DOCFAT.DEL3COD', '=', 'DOCCOP.DEL3COD')->on('DOCFAT.FAT1COD', '=', 'DOCCOP.FAT3COD');
            })->where('DOCFAT.DIR2COD', '>', 0)->whereRaw(VeolabDocumentControl::toWithdrawSql('DOCCOP', 'DOCFAT'));
            VeolabDocumentControl::whereControlled($query, true, 'DOCFAT');

            return self::inDelegations($query, 'DOCFAT.DEL3COD', $c, true)->count();
        });
        if ($copies !== null) {
            $lines[] = self::text('Copias controladas por retirar: %1', $copies);
        }
    }

    /** «Código Título» de un documento (sin código, solo el título). */
    private static function documentText(object $document): string
    {
        return trim(trim((string) ($document->FATCCOD ?? '')).' '.trim((string) ($document->FATCNOM ?? '')));
    }

    // --- d: acciones de revisiones anteriores ---------------------------

    /**
     * Decisiones de la revisión anterior no anulada: la de reunión más
     * reciente antes de esta (de su fecha de reunión o, si no tiene, del día
     * siguiente al fin del periodo).
     */
    private static function topicD(array &$lines, array $c): void
    {
        $review = $c['review'];
        $reference = $review->RDIDFEC ? substr((string) $review->RDIDFEC, 0, 10).' 00:00:00' : $c['until'];
        $previous = self::notAnnulled(DB::connection('dynamic')->table(self::TABLE), 'RDICEST')
            ->whereNotNull('RDIDFEC')->where('RDIDFEC', '<', $reference)
            ->whereRaw('NOT (DEL3COD = ? AND RDI1SER = ? AND RDI1COD = ?)', [(string) $review->DEL3COD, (string) $review->RDI1SER, (int) $review->RDI1COD])
            ->orderByDesc('RDIDFEC')->first(['DEL3COD', 'RDI1SER', 'RDI1COD', 'RDIDFEC', 'RDICDES']);
        if (! $previous) {
            $lines[] = 'No hay ninguna revisión anterior.';

            return;
        }
        $name = VeolabCodes::format(self::TABLE, (string) $previous->RDI1COD, (string) $previous->DEL3COD, (string) $previous->RDI1SER)
            .' ('.self::date($previous->RDIDFEC).')';
        if (trim((string) $previous->RDICDES) !== '') {
            $name .= ' '.trim((string) $previous->RDICDES);
        }
        $lines[] = self::text('Revisión anterior: %1', $name);

        $query = DB::connection('dynamic')->table('CALINC')->whereExists(function ($s) use ($previous) {
            $s->select(DB::raw(1))->from('CALIYE')
                ->whereColumn('CALIYE.DEL3COD', 'CALINC.DEL3COD')->whereColumn('CALIYE.INC3SER', 'CALINC.INC1SER')
                ->whereColumn('CALIYE.INC3COD', 'CALINC.INC1COD')->where('CALIYE.IYECTAB', self::TABLE)
                ->where('CALIYE.IYECDEL', (string) $previous->DEL3COD)->where('CALIYE.IYECSER', (string) $previous->RDI1SER)
                ->where('CALIYE.IYECCOD', (string) (int) $previous->RDI1COD);
        });
        if ($r = self::walkIncidents($query, $c)) {
            $lines[] = self::text('Decisiones: %1 cerradas, %2 abiertas (%3 con acciones vencidas)', $r['cerradas'], $r['abiertas'], $r['vencidas']);
            self::add($lines, $r['lista']);
        }
    }

    // --- e y g: auditorías ---------------------------------------------

    /**
     * e) Internas (I, C o sin tipo): previstas en el periodo y realizadas
     * (estado R, I o C con inicio en el periodo). g) Externas (E y X)
     * realizadas. En ambas, los hallazgos (no anulados) de las realizadas
     * por clasificación y abiertos, y la lista de las realizadas si son pocas.
     * En las internas, además, el resumen de sus listas de verificación
     * (CALAUP), si tienen alguna pregunta.
     */
    private static function topicAudits(array &$lines, array $c, bool $internal): void
    {
        $db = DB::connection('dynamic');
        $types = function ($q) use ($internal) {
            $internal
                ? $q->where(fn ($w) => $w->whereIn('CALAUI.AUICTIP', ['I', 'C'])->orWhereNull('CALAUI.AUICTIP')->orWhere('CALAUI.AUICTIP', ''))
                : $q->whereIn('CALAUI.AUICTIP', ['E', 'X']);

            return $q;
        };
        $planned = null;
        if ($internal) {
            $planned = self::tally(fn () => self::inDelegations(self::inPeriod(self::notAnnulled($types($db->table('CALAUI')), 'CALAUI.AUICEST'),
                'CALAUI.AUIDPRE', $c), 'CALAUI.DEL3COD', $c)->count());
        }
        $done = self::inDelegations(self::inPeriod($types($db->table('CALAUI')), 'CALAUI.AUIDINI', $c), 'CALAUI.DEL3COD', $c)
            ->whereIn('CALAUI.AUICEST', ['R', 'I', 'C'])->orderBy('CALAUI.AUIDINI')
            ->get(['CALAUI.DEL3COD', 'CALAUI.AUI1SER', 'CALAUI.AUI1COD', 'CALAUI.AUICDES']);
        if ($internal) {
            if ($planned !== null) {
                $lines[] = self::text('Auditorías internas previstas: %1; realizadas: %2', $planned, $done->count());
            }
        } else {
            $lines[] = self::text('Evaluaciones externas realizadas: %1', $done->count());
        }
        if ($done->isEmpty()) {
            return;
        }

        // Hallazgos (no anulados) enlazados solo a las auditorías realizadas en el periodo
        $keys = $done->mapWithKeys(fn ($a) => [$a->DEL3COD."\x1B".$a->AUI1SER."\x1B".(int) $a->AUI1COD => true])->all();
        $findings = $db->table('CALIYE')
            ->join('CALINC', function ($j) {
                $j->on('CALINC.DEL3COD', '=', 'CALIYE.DEL3COD')->on('CALINC.INC1SER', '=', 'CALIYE.INC3SER')
                    ->on('CALINC.INC1COD', '=', 'CALIYE.INC3COD');
            })
            ->where('CALIYE.IYECTAB', 'CALAUI')
            ->where(fn ($q) => $q->whereNull('CALINC.INCCEST')->orWhere('CALINC.INCCEST', '<>', 'A'))
            ->get(['CALIYE.IYECDEL', 'CALIYE.IYECSER', 'CALIYE.IYECCOD', 'CALINC.INCCGRV', 'CALINC.INCCEST']);
        $counts = ['M' => 0, 'N' => 0, 'O' => 0];
        $open = 0;
        foreach ($findings as $f) {
            if (! isset($keys[$f->IYECDEL."\x1B".$f->IYECSER."\x1B".(int) $f->IYECCOD])) {
                continue;
            }
            if (isset($counts[(string) $f->INCCGRV])) {
                $counts[(string) $f->INCCGRV]++;
            }
            if (self::isOpen($f->INCCEST)) {
                $open++;
            }
        }
        $lines[] = self::text('Hallazgos: %1 mayores, %2 menores, %3 observaciones; %4 abiertos', $counts['M'], $counts['N'], $counts['O'], $open);
        if ($internal) {
            self::add($lines, self::checklistLine($done));
        }
        if ($done->count() <= self::MAX_LISTED) {
            $lines[] = 'Realizadas:';
            foreach ($done as $a) {
                $lines[] = '- '.VeolabCodes::format('CALAUI', (string) $a->AUI1COD, (string) $a->DEL3COD, (string) $a->AUI1SER)
                    .' '.trim((string) $a->AUICDES);
            }
        }
    }

    /**
     * Resumen de las listas de verificación de las auditorías dadas (todas
     * sus preguntas, también las de los ensayos presenciados); null si no
     * tienen ninguna o la base aún no tiene las listas.
     */
    private static function checklistLine($audits): ?string
    {
        try {
            if (! VeolabAuditChecklists::available()) {
                return null;
            }
            $query = DB::connection('dynamic')->table(VeolabAuditChecklists::LINES)
                ->where(function ($q) use ($audits) {
                    foreach ($audits as $a) {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $a->DEL3COD)->where('AUI3SER', (string) $a->AUI1SER)
                            ->where('AUI3COD', (int) $a->AUI1COD));
                    }
                });
            $s = VeolabAuditChecklists::count($query);
        } catch (\Throwable) {
            return null;
        }
        if ($s['total'] === 0) {
            return null;
        }

        return self::text('Listas de verificación: %1 de %2 preguntas respondidas, %3 no conformes y %4 observaciones',
            $s['respondidas'], $s['total'], $s['no_conformes'], $s['observaciones']);
    }

    private static function topicE(array &$lines, array $c): void
    {
        self::topicAudits($lines, $c, true);
    }

    private static function topicG(array &$lines, array $c): void
    {
        self::topicAudits($lines, $c, false);
    }

    // --- f: acciones correctivas ---------------------------------------

    /**
     * Incidencias no anuladas detectadas en el periodo con alguna acción
     * correctiva (tipo A); no conformidades (INCBNCO, todas las del periodo);
     * lista de las abiertas.
     */
    private static function topicF(array &$lines, array $c): void
    {
        $query = self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->whereExists(function ($s) {
                $s->select(DB::raw(1))->from('CALACC')
                    ->whereColumn('CALACC.DEL3COD', 'CALINC.DEL3COD')->whereColumn('CALACC.INC3SER', 'CALINC.INC1SER')
                    ->whereColumn('CALACC.INC3COD', 'CALINC.INC1COD')->where('CALACC.ACCCTIP', 'A');
            });
        $r = self::walkIncidents($query, $c);
        if ($r) {
            $lines[] = self::text('Incidencias con acción correctiva: %1 (%2 cerradas, %3 abiertas, %4 con acciones vencidas)',
                $r['total'], $r['cerradas'], $r['abiertas'], $r['vencidas']);
        }
        $nc = self::tally(fn () => self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->where('CALINC.INCBNCO', 'T')->count());
        if ($nc !== null) {
            $lines[] = self::text('No conformidades: %1', $nc);
        }
        self::add($lines, $r['lista'] ?? null);
    }

    // --- h: volumen y tipo de trabajo ----------------------------------

    /**
     * Operaciones no anuladas por fecha de registro e informes por fecha de
     * creación frente al periodo anterior de igual duración; clientes dados de
     * alta en el periodo con alguna operación en él. Las técnicas no tienen
     * fecha de alta: no se cuentan (como el escritorio).
     */
    private static function topicH(array &$lines, array $c): void
    {
        $db = DB::connection('dynamic');
        $previous = ['from' => $c['prev_from'], 'until' => $c['from']] + $c;
        $operations = fn (array $p) => self::tally(fn () => self::inDelegations(self::inPeriod($db->table('LABOPE'), 'LABOPE.OPEDREG', $p), 'LABOPE.DEL3COD', $p)
            ->where(fn ($q) => $q->whereNull('LABOPE.OPEBANU')->orWhere('LABOPE.OPEBANU', '<>', 'T'))->count());
        $reports = fn (array $p) => self::tally(fn () => self::inDelegations(self::inPeriod($db->table('LABINF'), 'LABINF.INFDCRE', $p), 'LABINF.DEL3COD', $p)->count());

        [$now, $before] = [$operations($c), $operations($previous)];
        if (self::counted($now, $before)) {
            $lines[] = self::text('Operaciones: %1 (periodo anterior: %2; variación: %3)', $now, $before, self::change($now, $before));
        }
        [$now, $before] = [$reports($c), $reports($previous)];
        if (self::counted($now, $before)) {
            $lines[] = self::text('Informes emitidos: %1 (periodo anterior: %2; variación: %3)', $now, $before, self::change($now, $before));
        }
        $new = self::tally(fn () => self::inPeriod($db->table('SINCLI'), 'SINCLI.CLIDALT', $c)
            ->whereExists(function ($s) use ($c) {
                $s->select(DB::raw(1))->from('LABOPE')
                    ->whereColumn('LABOPE.CLI2DEL', 'SINCLI.DEL3COD')->whereColumn('LABOPE.CLI2COD', 'SINCLI.CLI1COD')
                    ->where(fn ($q) => $q->whereNull('LABOPE.OPEBANU')->orWhere('LABOPE.OPEBANU', '<>', 'T'));
                self::inDelegations(self::inPeriod($s, 'LABOPE.OPEDREG', $c), 'LABOPE.DEL3COD', $c);
            })->count());
        if ($new !== null) {
            $lines[] = self::text('Clientes nuevos con operaciones: %1', $new);
        }
    }

    // --- i: opinión de clientes y personal -----------------------------

    /** Incidencias de origen cliente (C) o personal (P) que no son quejas, por clase. */
    private static function topicI(array &$lines, array $c): void
    {
        $rows = self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->whereIn('CALINC.INCCORI', ['C', 'P'])
            ->where(fn ($q) => $q->whereNull('CALINC.INCCCLA')->orWhere('CALINC.INCCCLA', '<>', 'Q'))
            ->groupBy('CALINC.INCCCLA')->orderBy('CALINC.INCCCLA')
            ->get(['CALINC.INCCCLA', DB::raw('COUNT(*) AS NINC')]);
        $total = 0;
        $classes = [];
        foreach ($rows as $r) {
            $total += (int) $r->NINC;
            $classes[] = '- '.(self::CLASS_NAMES[(string) $r->INCCCLA] ?? '?').': '.(int) $r->NINC;
        }
        $lines[] = self::text('Incidencias de clientes y del personal que no son quejas: %1', $total);
        foreach ($classes as $line) {
            $lines[] = $line;
        }
    }

    // --- j: quejas -----------------------------------------------------

    /** Quejas recibidas en el periodo: cerradas, abiertas, tiempo medio de cierre (días de la detección al cierre) y lista. */
    private static function topicJ(array &$lines, array $c): void
    {
        $query = fn () => self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->where('CALINC.INCCCLA', 'Q');
        $r = self::walkIncidents($query(), $c);
        if (! $r) {
            return;
        }
        $lines[] = self::text('Quejas: %1 recibidas, %2 cerradas, %3 abiertas', $r['total'], $r['cerradas'], $r['abiertas']);
        $days = $query()->where('CALINC.INCCEST', 'C')->whereNotNull('CALINC.INCDCIE')->whereNotNull('CALINC.INCDDET')
            ->get(['CALINC.INCDDET', 'CALINC.INCDCIE'])
            ->map(fn ($q) => (int) (new \DateTime(substr((string) $q->INCDDET, 0, 10)))->diff(new \DateTime(substr((string) $q->INCDCIE, 0, 10)))->format('%r%a'));
        if ($days->isNotEmpty()) {
            // Format(x, "0.0") de TemaQuejas: siempre un decimal («5,0 días»)
            $average = number_format($days->sum() / $days->count(), 1, VeolabResults::decimalSeparator(), '');
            $lines[] = self::text('Tiempo medio de cierre: %1 días', $average);
        }
        self::add($lines, $r['lista']);
    }

    // --- k: eficacia de las mejoras ------------------------------------

    /** Mejoras cerradas en el periodo y, de ellas, con la verificación de la eficacia escrita. */
    private static function topicK(array &$lines, array $c): void
    {
        $rows = self::inPeriod(self::incidents($c), 'CALINC.INCDCIE', $c)->where('CALINC.INCCCLA', 'M')->where('CALINC.INCCEST', 'C')
            ->get(['CALINC.INCCVEF']);
        $lines[] = self::text('Mejoras cerradas: %1; con eficacia verificada: %2', $rows->count(),
            $rows->filter(fn ($r) => trim((string) $r->INCCVEF) !== '')->count());
    }

    // --- l: recursos ---------------------------------------------------

    /**
     * Personal: altas y bajas; autorizaciones concedidas y caducadas en el
     * periodo. Equipos (productos con PRDBEQU) no de baja ni fuera de uso con
     * la calibración, verificación o mantenimiento alcanzados hoy. Patrones y
     * materiales de referencia caducados (caducidad efectiva) o con la
     * calibración vencida hoy.
     * Proveedores: evaluaciones del periodo por resultado y proveedores no de
     * baja con la reevaluación vencida.
     */
    private static function topicL(array &$lines, array $c): void
    {
        $db = DB::connection('dynamic');

        $a = self::tally(fn () => self::inDelegations(self::inPeriod($db->table('GRHEMP'), 'GRHEMP.EMPDALT', $c), 'GRHEMP.DEL3COD', $c)->count());
        $b = self::tally(fn () => self::inDelegations(self::inPeriod($db->table('GRHEMP'), 'GRHEMP.EMPDBAJ', $c), 'GRHEMP.DEL3COD', $c)->count());
        if (self::counted($a, $b)) {
            $lines[] = self::text('Personal: %1 altas y %2 bajas', $a, $b);
        }
        $a = self::tally(fn () => self::inDelegations(self::inPeriod($db->table('GRHAUT'), 'GRHAUT.AUTDAUT', $c), 'GRHAUT.EMP3DEL', $c)->count());
        $b = self::tally(fn () => self::inDelegations(self::inPeriod($db->table('GRHAUT'), 'GRHAUT.AUTDCAD', $c), 'GRHAUT.EMP3DEL', $c)->count());
        if (self::counted($a, $b)) {
            $lines[] = self::text('Autorizaciones concedidas: %1; caducadas: %2', $a, $b);
        }

        $equipment = fn (string $column) => self::tally(fn () => self::inDelegations($db->table('ALMSEL')
            ->join('ALMPRD', fn ($j) => $j->on('ALMSEL.PRD3DEL', '=', 'ALMPRD.DEL3COD')->on('ALMSEL.PRD3COD', '=', 'ALMPRD.PRD1COD')), 'ALMSEL.PRD3DEL', $c, true)
            ->where('ALMPRD.PRDBEQU', 'T')
            ->where(fn ($q) => $q->whereNull('ALMSEL.SELCESA')->orWhereNotIn('ALMSEL.SELCESA', ['B', 'F']))
            ->whereNull('ALMSEL.SELDBAJ')
            ->where($column, '<', $c['tomorrow'])->count());
        $a = $equipment('ALMSEL.SELDCAL');
        $b = $equipment('ALMSEL.SELDVER');
        $m = $equipment('ALMSEL.SELDMAN');
        if (self::counted($a, $b, $m)) {
            $lines[] = self::text('Equipos vencidos hoy: %1 de calibración, %2 de verificación y %3 de mantenimiento', $a, $b, $m);
        }

        // Patrones y materiales de referencia (ISO/IEC 17025 6.5; con el script de los patrones): series de productos con
        // tipo metrológico, ni de baja ni fuera de uso, con la caducidad efectiva o la próxima calibración alcanzadas hoy
        if (VeolabMetrology::available()) {
            $patterns = fn () => VeolabMetrology::whereMetrological(self::inDelegations($db->table('ALMSEL')
                ->join('ALMPRD', fn ($j) => $j->on('ALMSEL.PRD3DEL', '=', 'ALMPRD.DEL3COD')->on('ALMSEL.PRD3COD', '=', 'ALMPRD.PRD1COD')), 'ALMSEL.PRD3DEL', $c, true)
                ->where(fn ($q) => $q->whereNull('ALMPRD.PRDBBAJ')->orWhere('ALMPRD.PRDBBAJ', '<>', 'T'))
                ->where(fn ($q) => $q->whereNull('ALMSEL.SELCESA')->orWhereNotIn('ALMSEL.SELCESA', ['B', 'F']))
                ->whereNull('ALMSEL.SELDBAJ')
                // En uso: con existencias, o equipos (TemaRecursos)
                ->where(fn ($q) => $q->where('ALMSEL.SELNCAE', '>', 0)->orWhere('ALMPRD.PRDBEQU', 'T')));
            // Solo si el laboratorio tiene alguno en uso
            $any = self::tally(fn () => $patterns()->count());
            $a = $any > 0 ? self::tally(fn () => VeolabMetrology::whereExpiresBefore($patterns(), $c['tomorrow'])->count()) : 0;
            $b = $any > 0 ? self::tally(fn () => $patterns()->where('ALMSEL.SELDCAL', '<', $c['tomorrow'])->count()) : 0;
            if ($any > 0 && self::counted($a, $b)) {
                $lines[] = self::text('Patrones y materiales de referencia: %1 caducados y %2 con la calibración vencida', $a, $b);
            }
        }

        try {
            $rows = self::inDelegations(self::inPeriod($db->table('SINEVA'), 'SINEVA.EVADFEC', $c), 'SINEVA.PRO3DEL', $c, true)
                ->groupBy('SINEVA.EVACRES')->get(['SINEVA.EVACRES', DB::raw('COUNT(*) AS NEVA')]);
            $results = ['P' => 0, 'N' => 0, '' => 0];
            foreach ($rows as $r) {
                $results[in_array((string) $r->EVACRES, ['P', 'N'], true) ? (string) $r->EVACRES : ''] += (int) $r->NEVA;
            }
            $lines[] = self::text('Evaluaciones de proveedores: %1 positivas, %2 negativas, %3 sin resultado', $results['P'], $results['N'], $results['']);
        } catch (\Throwable) {
            // Sin evaluaciones en esta base: se omite
        }
        $a = self::tally(fn () => self::inDelegations($db->table('SINPRO'), 'SINPRO.DEL3COD', $c, true)
            ->where('SINPRO.PRODPEV', '<', $c['today'])
            ->where(fn ($q) => $q->whereNull('SINPRO.PROBBAJ')->orWhere('SINPRO.PROBBAJ', '<>', 'T'))->count());
        if ($a !== null) {
            $lines[] = self::text('Proveedores con la reevaluación vencida: %1', $a);
        }

        // Software (ISO/IEC 17025 7.11): versión de Veolab valorada (ACCPAR.PARCVVA, «Marcar como valorada» en Tareas
        // pendientes). El escritorio la compara con la del ejecutable (ValoracionVeolab); la API no conoce el ejecutable
        // de cada puesto y la compara con la versión de la base de datos: si la valorada es de una versión anterior (o
        // no hay ninguna), «sin valorar», como el escritorio. Dentro de la misma versión de la base no puede saber si
        // se instaló una revisión posterior sin valorar: ahí da la última valorada
        try {
            if (self::hasColumn('ACCPAR', 'PARCVVA')) {
                $p = $db->table('ACCPAR')
                    ->leftJoin('ACCUSU', fn ($j) => $j->on('ACCPAR.USV2DEL', '=', 'ACCUSU.DEL3COD')->on('ACCPAR.USV2COD', '=', 'ACCUSU.USU1COD'))
                    ->where('ACCPAR.PAR1COD', 1)
                    ->first(['ACCPAR.PARCVVA', 'ACCPAR.PARTVVA', 'ACCPAR.PARCNVA', 'ACCPAR.USV2COD', 'ACCUSU.USUCNOM']);
                $assessed = trim((string) ($p->PARCVVA ?? ''));
                $current = VeolabVersion::current();
                // «3.0.12» → «3.0», para comparar con la versión de la base de datos
                $assessedBase = implode('.', array_slice(explode('.', $assessed), 0, 2));
                if ($assessed !== '' && $current !== '' && version_compare($assessedBase, $current, '<')) {
                    $lines[] = self::text('Veolab %1 sin valorar (última valorada: %2)', $current, $assessed);
                } elseif ($assessed !== '') {
                    $user = trim((string) ($p->USUCNOM ?? '')) ?: trim((string) ($p->USV2COD ?? ''));
                    $line = self::text('Veolab %1 valorada el %2 por %3', $assessed, self::date($p->PARTVVA ?? null), $user);
                    $note = trim(preg_split('/\r\n|\r|\n/', (string) ($p->PARCNVA ?? ''))[0]);
                    if (mb_strlen($note) > 200) {
                        $note = mb_substr($note, 0, 200).'...';
                    }
                    $lines[] = $note !== '' ? $line.': '.$note : $line;
                } else {
                    $lines[] = self::text('Veolab %1 sin valorar (última valorada: %2)', VeolabVersion::current(), 'ninguna');
                }
            }
        } catch (\Throwable) {
            // Sin datos de valoración en esta base: se omite
        }
    }

    // --- m: riesgos ----------------------------------------------------

    /** Riesgos abiertos ahora y detectados hasta el fin del periodo; tratados (cerrados) en el periodo; lista de los abiertos. */
    private static function topicM(array &$lines, array $c): void
    {
        $query = self::incidents($c)->where('CALINC.INCCCLA', 'R')
            ->where(fn ($q) => $q->whereIn('CALINC.INCCEST', VeolabQuality::OPEN_STATES)->orWhereNull('CALINC.INCCEST')->orWhere('CALINC.INCCEST', ''))
            ->where('CALINC.INCDDET', '<', $c['until']);
        $r = self::walkIncidents($query, $c);
        $treated = self::tally(fn () => self::inPeriod(self::incidents($c), 'CALINC.INCDCIE', $c)
            ->where('CALINC.INCCCLA', 'R')->where('CALINC.INCCEST', 'C')->count());
        if ($r && $treated !== null) {
            $lines[] = self::text('Riesgos abiertos: %1; tratados en el periodo: %2', $r['abiertas'], $treated);
        }
        self::add($lines, $r['lista'] ?? null);
    }

    // --- n: validez de los resultados ----------------------------------

    /**
     * Ensayos de aptitud con informe del organizador en el periodo y sus
     * resultados por evaluación; familias del plan (no de baja) sin
     * participación en su periodo; cartas de control con algún punto del
     * periodo en error (E) y con aviso (A); trabajos no conformes.
     */
    private static function topicN(array &$lines, array $c): void
    {
        $db = DB::connection('dynamic');
        $rounds = self::tally(fn () => self::inDelegations(self::inPeriod($db->table('CALEAP'), 'CALEAP.EAPDINF', $c), 'CALEAP.DEL3COD', $c)->count());
        if ($rounds !== null) {
            $lines[] = self::text('Ensayos de aptitud con informe: %1', $rounds);
        }
        try {
            $rows = self::inDelegations(self::inPeriod($db->table('CALEAR')->join('CALEAP', function ($j) {
                $j->on('CALEAP.DEL3COD', '=', 'CALEAR.DEL3COD')->on('CALEAP.EAP1SER', '=', 'CALEAR.EAP3SER')
                    ->on('CALEAP.EAP1COD', '=', 'CALEAR.EAP3COD');
            }), 'CALEAP.EAPDINF', $c), 'CALEAP.DEL3COD', $c)
                ->groupBy('CALEAR.EARCEVA')->get(['CALEAR.EARCEVA', DB::raw('COUNT(*) AS NEAR')]);
            $results = ['S' => 0, 'Q' => 0, 'I' => 0];
            foreach ($rows as $r) {
                $group = match ((string) $r->EARCEVA) {
                    'S', 'C'      => 'S',
                    'Q'           => 'Q',
                    'I', 'P', 'N' => 'I',
                    default       => null,
                };
                if ($group !== null) {
                    $results[$group] += (int) $r->NEAR;
                }
            }
            $lines[] = self::text('Resultados: %1 satisfactorios, %2 cuestionables, %3 insatisfactorios', $results['S'], $results['Q'], $results['I']);
        } catch (\Throwable) {
            // Sin resultados en esta base: se omite
        }

        $families = self::tally(function () use ($c) {
            $current = VeolabProficiencyPlan::currentYear();
            // Como el escritorio (FamiliasPendientes → EAP_FamiliasSinParticipacion): el plan de la delegación de
            // trabajo del usuario que recoge los datos y las familias comunes; sin ella, el de todo el laboratorio
            $delegation = ($c['user_del'] ?? '') !== '' ? $c['user_del'] : null;
            $plan = VeolabProficiencyPlan::families($current - VeolabProficiencyPlan::YEARS_BACK, $current, false, $delegation, $current);

            return count(array_filter($plan, fn ($f) => ($f['baja'] ?? '') !== 'T' && $f['situacion'] !== 'A' && $f['situacion'] !== 'E'));
        });
        if ($families !== null) {
            $lines[] = self::text('Familias del plan con participación pendiente: %1', $families);
        }

        $charts = fn (string $incidence) => self::tally(fn () => self::inDelegations($db->table('LABCDC'), 'LABCDC.DEL3COD', $c, true)
            ->whereExists(function ($s) use ($c, $incidence) {
                $s->select(DB::raw(1))->from('LABRCD')
                    ->whereColumn('LABRCD.CDC3DEL', 'LABCDC.DEL3COD')->whereColumn('LABRCD.CDC3COD', 'LABCDC.CDC1COD')
                    ->where('LABRCD.RCDCTII', $incidence);
                self::inPeriod($s, 'LABRCD.RCDTFEC', $c);
            })->count());
        $errors = $charts('E');
        $warnings = $charts('A');
        if (self::counted($errors, $warnings)) {
            $lines[] = self::text('Gráficos de control fuera de control: %1; con aviso: %2', $errors, $warnings);
        }

        $r = self::walkIncidents(self::inPeriod(self::notAnnulled(self::incidents($c), 'CALINC.INCCEST'), 'CALINC.INCDDET', $c)
            ->where('CALINC.INCCCLA', 'T'), $c);
        if ($r) {
            $lines[] = self::text('Trabajos no conformes: %1 (%2 abiertos)', $r['total'], $r['abiertas']);
            self::add($lines, $r['lista']);
        }
    }

    // --- o: otros (formación, plazos) ----------------------------------

    /**
     * Formación: cursos del plan (GRHPAF) iniciados en el periodo, sin
     * cancelados (C) ni rechazados (H), y registros de formación del personal
     * (GRHFOR); informes emitidos en el periodo después de la fecha de
     * compromiso de la operación, de los que tienen compromiso.
     */
    private static function topicO(array &$lines, array $c): void
    {
        $db = DB::connection('dynamic');
        $plan = self::tally(fn () => self::inDelegations(self::inPeriod($db->table('GRHPAF'), 'GRHPAF.PAFDINI', $c), 'GRHPAF.DEL3COD', $c)
            ->where(fn ($q) => $q->whereNull('GRHPAF.PAFCEST')->orWhereNotIn('GRHPAF.PAFCEST', ['C', 'H']))->count());
        $records = self::tally(fn () => self::inDelegations(self::inPeriod($db->table('GRHFOR'), 'GRHFOR.FORDINI', $c), 'GRHFOR.EMP3DEL', $c)->count());
        if (self::counted($plan, $records)) {
            $lines[] = self::text('Formación: %1 cursos del plan y %2 registros del personal', $plan, $records);
        }

        try {
            $rows = self::inDelegations(self::inPeriod($db->table('LABOPE'), 'LABOPE.OPEDINF', $c), 'LABOPE.DEL3COD', $c)
                ->where(fn ($q) => $q->whereNull('LABOPE.OPEBANU')->orWhere('LABOPE.OPEBANU', '<>', 'T'))
                ->whereNotNull('LABOPE.OPEDCOM')
                ->get(['LABOPE.OPEDINF', 'LABOPE.OPEDCOM']);
            $late = $rows->filter(fn ($r) => substr((string) $r->OPEDINF, 0, 10) > substr((string) $r->OPEDCOM, 0, 10))->count();
            $lines[] = self::text('Informes emitidos después de la fecha de compromiso: %1 de %2', $late, $rows->count());
        } catch (\Throwable) {
            // Se omite
        }
    }
}
