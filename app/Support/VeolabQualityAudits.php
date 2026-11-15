<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Auditorías internas y externas, fase 1 (módulo CAL, desde Veolab 3;
 * ISO/IEC 17025:2017 8.8): auditorías (CALAUI) con su equipo auditor y
 * auditados (CALAUQ), apartados de la norma del alcance (CALAUX), ensayos
 * presenciados (CALAUV) y delegaciones auditadas (CALAUL); los hallazgos son
 * incidencias (CALINC, clase N) enlazadas con CALIYE tipo CALAUI. Sigue el
 * modelo común con el escritorio («Auditorias - modelo de datos.md»).
 *
 * No confundir con VeolabAudit, que es el registro de auditoría de cambios
 * (ACCAUD, /auditorias).
 */
class VeolabQualityAudits
{
    public const TABLE = 'CALAUI';

    /** Funcionalidad de Veolab (la API no aplica privilegios; para la aplicación cliente). */
    public const FUNCTIONALITY = 'CAL_AUI';

    /** Interna, interna contratada, externa de acreditación, externa de certificación o de cliente. */
    public const TYPE_NAMES = [
        'I' => 'Interna',
        'C' => 'Interna contratada',
        'E' => 'Externa de acreditación',
        'X' => 'Externa de certificación o de cliente',
    ];

    /** Tipos internos: cuentan para la cobertura del programa (también el tipo vacío); sus hallazgos, origen I. */
    public const INTERNAL_TYPES = ['I', 'C'];

    public const STATE_NAMES = [
        'P' => 'Prevista',
        'R' => 'Realizada',
        'I' => 'Informe emitido',
        'C' => 'Cerrada',
        'A' => 'Anulada',
    ];

    /** Estados que cuentan como auditado en la cobertura. */
    public const DONE_STATES = ['R', 'I', 'C'];

    /** Papel en CALAUQ: auditor del equipo y auditado. */
    public const ROLE_TEAM = 'A';
    public const ROLE_AUDITEE = 'D';

    /** Apartados de la norma (lista fija del modelo) con su título corto (los del escritorio: ETI01120-ETI01148). */
    public const CLAUSES = [
        '4.1'  => 'Imparcialidad',
        '4.2'  => 'Confidencialidad',
        '5'    => 'Requisitos relativos a la estructura',
        '6.1'  => 'Recursos: generalidades',
        '6.2'  => 'Personal',
        '6.3'  => 'Instalaciones y condiciones ambientales',
        '6.4'  => 'Equipamiento',
        '6.5'  => 'Trazabilidad metrológica',
        '6.6'  => 'Productos y servicios suministrados externamente',
        '7.1'  => 'Revisión de solicitudes, ofertas y contratos',
        '7.2'  => 'Selección, verificación y validación de métodos',
        '7.3'  => 'Muestreo',
        '7.4'  => 'Manipulación de los ítems de ensayo',
        '7.5'  => 'Registros técnicos',
        '7.6'  => 'Evaluación de la incertidumbre de medición',
        '7.7'  => 'Aseguramiento de la validez de los resultados',
        '7.8'  => 'Informe de resultados',
        '7.9'  => 'Quejas',
        '7.10' => 'Trabajo no conforme',
        '7.11' => 'Control de los datos y gestión de la información',
        '8.1'  => 'Opciones del sistema de gestión',
        '8.2'  => 'Documentación del sistema de gestión',
        '8.3'  => 'Control de los documentos',
        '8.4'  => 'Control de los registros',
        '8.5'  => 'Acciones para abordar riesgos y oportunidades',
        '8.6'  => 'Mejora',
        '8.7'  => 'Acciones correctivas',
        '8.8'  => 'Auditorías internas',
        '8.9'  => 'Revisiones por la dirección',
    ];

    /** Situación de un apartado en un año de la cobertura (los años futuros sin nada, null). */
    public const COVERAGE_NAMES = [
        'A' => 'Auditado',
        'P' => 'Previsto',
        'S' => 'Sin auditar',
    ];

    /** Título de un apartado ('' si no es de la lista). */
    public static function clauseTitle(?string $clause): string
    {
        return self::CLAUSES[(string) $clause] ?? '';
    }

    /** ¿Es un apartado de la lista fija? */
    public static function isClause(?string $clause): bool
    {
        return $clause !== null && array_key_exists($clause, self::CLAUSES);
    }

    /** Auditoría (CALAUI) o null; con $lock, bloqueada hasta el commit. */
    public static function audit(string $del, string $ser, int $cod, bool $lock = false): ?object
    {
        $query = DB::connection('dynamic')->table(self::TABLE)
            ->where('DEL3COD', $del)->where('AUI1SER', $ser)->where('AUI1COD', $cod);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /** Estado de la auditoría ('' cuenta como prevista). */
    public static function state(object $audit): string
    {
        $state = (string) ($audit->AUICEST ?? '');

        return $state === '' ? 'P' : $state;
    }

    /** ¿Interna propia (I o sin tipo)? Lleva auditor jefe y equipo; las demás, entidad y auditor externo. */
    public static function isOwnInternal(?string $type): bool
    {
        return (string) $type === '' || $type === 'I';
    }

    /** Origen de los hallazgos según el tipo: I en las internas (I, C), E en las externas (E, X). */
    public static function findingsOrigin(?string $type): string
    {
        return in_array((string) $type, self::INTERNAL_TYPES, true) || (string) $type === '' ? 'I' : 'E';
    }

    /**
     * Año de la auditoría en SQL: el del programa o, si falta, el de la
     * realización o el de la fecha prevista.
     */
    public static function yearSql(string $table = 'CALAUI'): string
    {
        return "COALESCE({$table}.AUINANI, YEAR({$table}.AUIDINI), YEAR({$table}.AUIDPRE))";
    }

    // ------------------------------------------------------------------
    // Hallazgos: incidencias enlazadas (CALIYE tipo CALAUI)
    // ------------------------------------------------------------------

    /**
     * Hallazgos de varias auditorías: [clave "del\x1Bser\x1Bcod" => lista]
     * con cada incidencia enlazada (código, clase, clasificación, apartado,
     * estado). $audits = [[del, ser, cod], ...].
     */
    public static function findings(array $audits): array
    {
        if (! $audits) {
            return [];
        }
        $rows = DB::connection('dynamic')->table('CALIYE')
            ->join('CALINC', function ($j) {
                $j->on('CALINC.DEL3COD', '=', 'CALIYE.DEL3COD')->on('CALINC.INC1SER', '=', 'CALIYE.INC3SER')
                    ->on('CALINC.INC1COD', '=', 'CALIYE.INC3COD');
            })
            ->where('CALIYE.IYECTAB', self::TABLE)
            ->where(function ($q) use ($audits) {
                foreach ($audits as [$del, $ser, $cod]) {
                    $q->orWhere(fn ($w) => $w->where('CALIYE.IYECDEL', (string) $del)->where('CALIYE.IYECSER', (string) $ser)
                        ->where('CALIYE.IYECCOD', (string) $cod));
                }
            })
            ->distinct()
            ->orderBy('CALINC.INCDDET')->orderBy('CALINC.DEL3COD')->orderBy('CALINC.INC1SER')->orderBy('CALINC.INC1COD')
            ->get(['CALIYE.IYECDEL', 'CALIYE.IYECSER', 'CALIYE.IYECCOD', 'CALINC.DEL3COD', 'CALINC.INC1SER', 'CALINC.INC1COD',
                'CALINC.INCCDES', 'CALINC.INCCCLA', 'CALINC.INCCGRV', 'CALINC.INCCAPA', 'CALINC.INCCEST', 'CALINC.INCDDET',
                'CALINC.INCDCIE']);

        $out = [];
        foreach ($rows as $r) {
            $state = VeolabQuality::state($r);
            $out[$r->IYECDEL."\x1B".$r->IYECSER."\x1B".$r->IYECCOD][] = [
                'delegacion'         => (string) $r->DEL3COD,
                'serie'              => (string) $r->INC1SER,
                'codigo'             => (int) $r->INC1COD,
                'codigo_formateado'  => VeolabCodes::format('CALINC', (string) $r->INC1COD, (string) $r->DEL3COD, (string) $r->INC1SER),
                'descripcion'        => (string) $r->INCCDES,
                'clase'              => $r->INCCCLA,
                'hallazgo'           => $r->INCCGRV,
                'apartado'           => $r->INCCAPA,
                'apartado_titulo'    => self::clauseTitle($r->INCCAPA) ?: null,
                'estado'             => $state,
                'estado_texto'       => VeolabQuality::STATE_NAMES[$state] ?? $state,
                'abierta'            => in_array($state, VeolabQuality::OPEN_STATES, true) ? 'T' : 'F',
                'fecha_deteccion'    => $r->INCDDET,
                'fecha_cierre'       => $r->INCDCIE,
            ];
        }

        return $out;
    }

    /** Número de hallazgos abiertos (registrados, en tratamiento o en verificación) de una auditoría. */
    public static function openFindings(string $del, string $ser, int $cod): int
    {
        $list = self::findings([[$del, $ser, $cod]])[$del."\x1B".$ser."\x1B".$cod] ?? [];

        return count(array_filter($list, fn ($f) => $f['abierta'] === 'T'));
    }

    // ------------------------------------------------------------------
    // Borrado
    // ------------------------------------------------------------------

    /**
     * Sin la fila de CALAUI, que ya ha borrado quien llama: equipo y
     * auditados, apartados, ensayos presenciados, delegaciones, los enlaces
     * de las incidencias hacia la auditoría (las incidencias quedan), las
     * preguntas de su lista de verificación (CALAUP) y sus documentos a la
     * papelera, como los ensayos de aptitud.
     */
    public static function deleteAuditData(string $del, string $ser, int $cod): void
    {
        $db = DB::connection('dynamic');
        foreach (['CALAUQ', 'CALAUX', 'CALAUV', 'CALAUL'] as $table) {
            $db->table($table)->where('DEL3COD', $del)->where('AUI3SER', $ser)->where('AUI3COD', $cod)->delete();
        }
        VeolabAuditChecklists::deleteAuditLines($del, $ser, $cod);
        $db->table('CALIYE')->where('IYECTAB', self::TABLE)->where('IYECDEL', $del)->where('IYECSER', $ser)
            ->where('IYECCOD', (string) $cod)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('AUI2SER', $ser)->where('AUI2COD', $cod)
            ->where(fn ($q) => $q->whereNull('DIR2COD')->orWhere('DIR2COD', '<>', 0))
            ->update(VeolabDocuments::trashUpdate($del));
    }

    // ------------------------------------------------------------------
    // Cobertura del programa
    // ------------------------------------------------------------------

    /**
     * Auditorías internas (I, C o sin tipo) no anuladas que cubren la delegación: las
     * que la tienen entre sus delegaciones auditadas o no tienen ninguna
     * (todo el laboratorio). Sin delegación, todas.
     */
    private static function whereCoversDelegation($query, ?string $delegation): void
    {
        $query->where(fn ($q) => $q->whereIn('CALAUI.AUICTIP', self::INTERNAL_TYPES)->orWhereNull('CALAUI.AUICTIP')
                ->orWhere('CALAUI.AUICTIP', ''))
            ->where(fn ($q) => $q->whereNull('CALAUI.AUICEST')->orWhere('CALAUI.AUICEST', '<>', 'A'));
        if ($delegation === null || $delegation === '') {
            return;
        }
        $lines = fn ($s) => $s->select(DB::raw(1))->from('CALAUL')->whereColumn('CALAUL.DEL3COD', 'CALAUI.DEL3COD')
            ->whereColumn('CALAUL.AUI3SER', 'CALAUI.AUI1SER')->whereColumn('CALAUL.AUI3COD', 'CALAUI.AUI1COD');
        $query->where(function ($q) use ($lines, $delegation) {
            $q->whereExists(fn ($s) => $lines($s)->where('CALAUL.DEL2COD', $delegation))
                ->orWhereNotExists(fn ($s) => $lines($s));
        });
    }

    /**
     * Apartados de la norma por años, de $from a $to: auditado (alguna
     * auditoría interna no anulada en estado R, I o C con el apartado en su
     * alcance), previsto (en estado P) o sin auditar (solo hasta el año
     * actual; los años futuros sin nada, null). El año es el del programa (o
     * el de la realización o la fecha prevista). ultimo_anio_auditado mira
     * cualquier año, no solo los del cuadro.
     */
    public static function coverage(int $from, int $to, ?string $delegation, int $current): array
    {
        $year = self::yearSql();
        $lastQuery = DB::connection('dynamic')->table('CALAUX')
            ->join('CALAUI', function ($j) {
                $j->on('CALAUI.DEL3COD', '=', 'CALAUX.DEL3COD')->on('CALAUI.AUI1SER', '=', 'CALAUX.AUI3SER')
                    ->on('CALAUI.AUI1COD', '=', 'CALAUX.AUI3COD');
            })
            ->whereIn('CALAUI.AUICEST', self::DONE_STATES);
        self::whereCoversDelegation($lastQuery, $delegation);
        $lastAudited = $lastQuery->groupBy('CALAUX.AUXCAPA')
            ->get(['CALAUX.AUXCAPA', DB::raw("MAX({$year}) AS ultimo")])
            ->mapWithKeys(fn ($r) => [(string) $r->AUXCAPA => $r->ultimo === null ? null : (int) $r->ultimo])->all();
        $query = DB::connection('dynamic')->table('CALAUX')
            ->join('CALAUI', function ($j) {
                $j->on('CALAUI.DEL3COD', '=', 'CALAUX.DEL3COD')->on('CALAUI.AUI1SER', '=', 'CALAUX.AUI3SER')
                    ->on('CALAUI.AUI1COD', '=', 'CALAUX.AUI3COD');
            })
            ->whereRaw("{$year} BETWEEN ? AND ?", [$from, $to]);
        self::whereCoversDelegation($query, $delegation);
        $rows = $query->orderBy('CALAUI.DEL3COD')->orderBy('CALAUI.AUI1SER')->orderBy('CALAUI.AUI1COD')
            ->get(['CALAUX.AUXCAPA', 'CALAUI.DEL3COD', 'CALAUI.AUI1SER', 'CALAUI.AUI1COD', 'CALAUI.AUICEST', DB::raw("{$year} AS anio")]);

        // [apartado][año] => ['A' => [...auditorías], 'P' => [...]]
        $found = [];
        foreach ($rows as $r) {
            $state = (string) $r->AUICEST === '' ? 'P' : (string) $r->AUICEST;
            $kind = in_array($state, self::DONE_STATES, true) ? 'A' : ($state === 'P' ? 'P' : null);
            if ($kind === null) {
                continue;
            }
            $found[(string) $r->AUXCAPA][(int) $r->anio][$kind][] = [
                'delegacion'        => (string) $r->DEL3COD,
                'serie'             => (string) $r->AUI1SER,
                'codigo'            => (int) $r->AUI1COD,
                'codigo_formateado' => VeolabCodes::format(self::TABLE, (string) $r->AUI1COD, (string) $r->DEL3COD, (string) $r->AUI1SER),
                'estado'            => $state,
            ];
        }

        $out = [];
        foreach (self::CLAUSES as $clause => $title) {
            $clause = (string) $clause;
            $years = [];
            for ($y = $from; $y <= $to; $y++) {
                $cell = $found[$clause][$y] ?? [];
                $state = isset($cell['A']) ? 'A' : (isset($cell['P']) ? 'P' : ($y <= $current ? 'S' : null));
                $years[] = [
                    'anio'        => $y,
                    'estado'      => $state,
                    'texto'       => $state === null ? null : self::COVERAGE_NAMES[$state],
                    'auditorias'  => array_merge($cell['A'] ?? [], $cell['P'] ?? []),
                ];
            }
            $out[] = [
                'apartado'             => $clause,
                'titulo'               => $title,
                'ultimo_anio_auditado' => $lastAudited[$clause] ?? null,
                'anios'                => $years,
            ];
        }

        return $out;
    }

    /**
     * Parámetros acreditados (LABTEC.TECDACR) y no de baja sin ninguna línea
     * de ensayo presenciado (CALAUV) en una auditoría interna no anulada del
     * ciclo (fecha de inicio o, si falta, prevista, desde $since). De la
     * delegación y las comunes, si se indica. Con la fecha del último ensayo
     * presenciado, si lo hubo antes.
     */
    public static function parametersWithoutWitnessed(?string $delegation, string $since): array
    {
        $db = DB::connection('dynamic');
        $date = 'COALESCE(CALAUI.AUIDINI, CALAUI.AUIDPRE)';
        $witnessed = function ($s) use ($delegation) {
            $s->from('CALAUV')
                ->join('CALAUI', function ($j) {
                    $j->on('CALAUI.DEL3COD', '=', 'CALAUV.DEL3COD')->on('CALAUI.AUI1SER', '=', 'CALAUV.AUI3SER')
                        ->on('CALAUI.AUI1COD', '=', 'CALAUV.AUI3COD');
                });
            self::whereCoversDelegation($s, $delegation);
        };

        $query = $db->table('LABTEC')
            ->whereNotNull('LABTEC.TECDACR')
            ->where(fn ($q) => $q->whereNull('LABTEC.TECBBAJ')->orWhere('LABTEC.TECBBAJ', '<>', 'T'))
            ->whereNotExists(function ($s) use ($witnessed, $date, $since) {
                $witnessed($s);
                $s->select(DB::raw(1))->whereColumn('CALAUV.TEC2DEL', 'LABTEC.DEL3COD')->whereColumn('CALAUV.TEC2COD', 'LABTEC.TEC1COD')
                    ->whereRaw("{$date} >= ?", [$since]);
            })
            ->orderBy('LABTEC.TECCNOM')->orderBy('LABTEC.TEC1COD');
        if ($delegation !== null && $delegation !== '') {
            $query->where(fn ($q) => $q->where('LABTEC.DEL3COD', '')->orWhere('LABTEC.DEL3COD', $delegation));
        }
        $params = $query->get(['LABTEC.DEL3COD', 'LABTEC.TEC1COD', 'LABTEC.TECCNOM', 'LABTEC.TECDACR']);
        if ($params->isEmpty()) {
            return [];
        }

        $lastQuery = $db->query();
        $witnessed($lastQuery);
        $last = $lastQuery->whereNotNull('CALAUV.TEC2COD')
            ->groupBy('CALAUV.TEC2DEL', 'CALAUV.TEC2COD')
            ->get(['CALAUV.TEC2DEL', 'CALAUV.TEC2COD', DB::raw("MAX({$date}) AS ultima")])
            ->keyBy(fn ($r) => $r->TEC2DEL."\x1B".$r->TEC2COD);

        return $params->map(fn ($t) => [
            'parametro_delegacion'      => (string) $t->DEL3COD,
            'parametro_codigo'          => (string) $t->TEC1COD,
            'parametro_nombre'          => trim((string) $t->TECCNOM) === '' ? (string) $t->TEC1COD : trim((string) $t->TECCNOM),
            'fecha_acreditacion'        => $t->TECDACR,
            'ultimo_ensayo_presenciado' => $last[$t->DEL3COD."\x1B".$t->TEC1COD]->ultima ?? null,
        ])->values()->all();
    }
}
