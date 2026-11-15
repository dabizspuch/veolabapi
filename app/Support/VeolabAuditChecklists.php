<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de verificación de las auditorías (módulo CAL, desde Veolab 3;
 * ISO/IEC 17025:2017 8.8, fase 2): plantillas de preguntas (CALLVE, común
 * con delegación '' o de una delegación; uso G auditoría o T ensayo
 * presenciado) con sus preguntas (CALLVQ), y la copia de las preguntas en
 * cada auditoría (CALAUP; AUV2COD = línea del ensayo presenciado de CALAUV o
 * NULL si es de la auditoría) con su resultado, evidencia y hallazgo
 * (INC2SER/INC2COD: incidencia de la misma delegación). Sigue el modelo
 * común con el escritorio («Analisis listas de verificacion de
 * auditorias.md», apartado 4).
 *
 * Las tablas llegan con un script de conversión: en las bases que aún no lo
 * tienen, available() es false.
 */
class VeolabAuditChecklists
{
    public const TABLE = 'CALLVE';
    public const QUESTIONS = 'CALLVQ';
    public const LINES = 'CALAUP';

    /** Resultado de una pregunta (CALAUP.AUPCRES; NULL = sin responder; ETI01411-01415). */
    public const RESULT_NAMES = [
        'C' => 'Conforme',
        'N' => 'No conforme',
        'O' => 'Observación',
        'X' => 'No aplica',
    ];

    public const UNANSWERED_NAME = 'Sin responder';

    /** Uso de la lista (CALLVE.LVECTIP; ETI01416-01417). */
    public const USE_NAMES = [
        'G' => 'Auditoría',
        'T' => 'Ensayo presenciado',
    ];

    /** Listas comunes que trae Veolab (AUI_LISTA_VEOLAB y AUI_LISTA_VEOLAB_ENSAYO): no se eliminan. */
    public const VEOLAB_LISTS = [1, 2];

    /** Resultados que pueden dar lugar a un hallazgo. */
    public const FINDING_RESULTS = ['N', 'O'];

    private static array $available = [];

    /** ¿Tiene la base de datos las tablas de las listas de verificación? */
    public static function available(): bool
    {
        if (! VeolabVersion::isV3()) {
            return false;
        }
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$available)) {
            try {
                $schema = Schema::connection('dynamic');
                self::$available[$database] = $schema->hasTable(self::TABLE) && $schema->hasTable(self::QUESTIONS)
                    && $schema->hasTable(self::LINES);
            } catch (\Throwable) {
                self::$available[$database] = false;
            }
        }

        return self::$available[$database];
    }

    /** Mensaje (422) de las bases sin las tablas. */
    public static function unavailableMessage(): string
    {
        return 'La base de datos aún no tiene las listas de verificación de auditorías (CALLVE, CALLVQ, CALAUP): hay que actualizarla';
    }

    /** ¿Es una de las listas comunes que trae Veolab? */
    public static function isVeolabList(string $del, int $cod): bool
    {
        return $del === '' && in_array($cod, self::VEOLAB_LISTS, true);
    }

    /**
     * AUI_NuevoCodigoLista: el mayor de todas las delegaciones más uno, para
     * no chocar con las de Veolab, que son comunes; nunca las de Veolab
     * aunque la conversión aún no las haya creado. Dentro de la transacción.
     */
    public static function newListCode(): int
    {
        $code = (int) DB::connection('dynamic')->table('CALLVE')->lockForUpdate()->max('LVE1COD') + 1;

        return max($code, max(self::VEOLAB_LISTS) + 1);
    }

    /** Texto del resultado (null = sin responder). */
    public static function resultName(?string $result): string
    {
        return self::RESULT_NAMES[(string) $result] ?? self::UNANSWERED_NAME;
    }

    /**
     * Auditoría bloqueada hasta el commit para cambiar su lista: debe existir
     * Necesita el módulo CAL. Como en el escritorio, el estado de la auditoría
     * no limita la lista (tampoco el resto de la auditoría).
     */
    public static function lockAudit(string $del, string $ser, int $cod): object
    {
        VeolabQuality::assertModule();
        $audit = VeolabQualityAudits::audit($del, $ser, $cod, true);
        if (! $audit) {
            throw new BusinessRuleException('La auditoría no existe');
        }

        return $audit;
    }

    /** Consulta de las preguntas de una auditoría. */
    public static function lines(string $del, string $ser, int $cod)
    {
        return DB::connection('dynamic')->table(self::LINES)
            ->where('DEL3COD', $del)->where('AUI3SER', $ser)->where('AUI3COD', $cod);
    }

    /**
     * Resumen de la lista de una auditoría (o de varias): total, respondidas,
     * y cuántas de cada resultado. $witnessed: null todas, 0 solo las de la
     * auditoría (sin ensayo presenciado), n las del ensayo presenciado n.
     */
    public static function summary(string $del, string $ser, int $cod, ?int $witnessed = null): array
    {
        $query = self::lines($del, $ser, $cod);
        if ($witnessed === 0) {
            $query->whereNull('AUV2COD');
        } elseif ($witnessed !== null) {
            $query->where('AUV2COD', $witnessed);
        }

        return self::count($query);
    }

    /** Cuenta las preguntas de la consulta dada por resultado. */
    public static function count($query): array
    {
        $row = $query->first([
            DB::raw('COUNT(*) AS total'),
            DB::raw("SUM(CASE WHEN AUPCRES IN ('C', 'N', 'O', 'X') THEN 1 ELSE 0 END) AS respondidas"),
            DB::raw("SUM(CASE WHEN AUPCRES = 'C' THEN 1 ELSE 0 END) AS conformes"),
            DB::raw("SUM(CASE WHEN AUPCRES = 'N' THEN 1 ELSE 0 END) AS no_conformes"),
            DB::raw("SUM(CASE WHEN AUPCRES = 'O' THEN 1 ELSE 0 END) AS observaciones"),
            DB::raw("SUM(CASE WHEN AUPCRES = 'X' THEN 1 ELSE 0 END) AS no_aplican"),
        ]);
        $total = (int) ($row->total ?? 0);
        $answered = (int) ($row->respondidas ?? 0);

        return [
            'total'          => $total,
            'respondidas'    => $answered,
            'sin_responder'  => $total - $answered,
            'conformes'      => (int) ($row->conformes ?? 0),
            'no_conformes'   => (int) ($row->no_conformes ?? 0),
            'observaciones'  => (int) ($row->observaciones ?? 0),
            'no_aplican'     => (int) ($row->no_aplican ?? 0),
        ];
    }

    /**
     * Resumen por apartado de la norma, como el marcador resumenapartadoslistaauditoria
     * del escritorio (AUI_ResumenApartados): una entrada por apartado con
     * preguntas, en el orden de la norma; los apartados que no son de la lista
     * van detrás y las preguntas sin apartado (apartado null), al final.
     * $witnessed: 0 (por defecto, como el escritorio) solo las de la auditoría,
     * n las del ensayo presenciado n, null todas.
     */
    public static function summaryByClause(string $del, string $ser, int $cod, ?int $witnessed = 0): array
    {
        $query = self::lines($del, $ser, $cod);
        if ($witnessed === 0) {
            $query->whereNull('AUV2COD');
        } elseif ($witnessed !== null) {
            $query->where('AUV2COD', $witnessed);
        }
        $rows = $query->groupBy('AUPCAPA', 'AUPCRES')->get(['AUPCAPA', 'AUPCRES', DB::raw('COUNT(*) AS n')]);

        $columns = ['C' => 'conformes', 'N' => 'no_conformes', 'O' => 'observaciones', 'X' => 'no_aplican', '' => 'sin_responder'];
        $byClause = [];
        foreach ($rows as $row) {
            $clause = trim((string) $row->AUPCAPA);
            $byClause[$clause] ??= ['preguntas' => 0, 'conformes' => 0, 'no_conformes' => 0, 'observaciones' => 0, 'no_aplican' => 0, 'sin_responder' => 0];
            $n = (int) $row->n;
            $byClause[$clause]['preguntas'] += $n;
            $column = $columns[trim((string) $row->AUPCRES)] ?? null;    // Otro valor: solo cuenta en el total
            if ($column !== null) {
                $byClause[$clause][$column] += $n;
            }
        }

        $order = array_map('strval', array_keys(VeolabQualityAudits::CLAUSES));
        $key = function (string $clause) use ($order): string {
            if ($clause === '') {
                return '3';
            }
            $pos = array_search($clause, $order, true);

            return $pos === false ? '2' . strtoupper($clause) : '1' . sprintf('%03d', $pos);
        };
        $clauses = array_map('strval', array_keys($byClause));
        usort($clauses, fn ($a, $b) => strcmp($key($a), $key($b)));

        $result = [];
        foreach ($clauses as $clause) {
            $result[] = [
                'apartado' => $clause === '' ? null : $clause,
                'titulo'   => VeolabQualityAudits::clauseTitle($clause) ?: null,
            ] + $byClause[$clause];
        }

        return $result;
    }

    /** Preguntas sin responder de una auditoría (0 si la base no tiene las listas). */
    public static function unanswered(string $del, string $ser, int $cod): int
    {
        if (! self::available()) {
            return 0;
        }

        return self::summary($del, $ser, $cod)['sin_responder'];
    }

    /** Suceso de la ficha de la auditoría por un cambio en su lista (fila y campo «CALAUP»), como las demás listas. */
    public static function auditAudit(string $del, string $ser, int $cod): void
    {
        $row = VeolabCodes::format(VeolabQualityAudits::TABLE, (string) $cod, $del, $ser);
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, VeolabQualityAudits::TABLE, $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, VeolabQualityAudits::TABLE, $row, self::LINES);
    }

    // ------------------------------------------------------------------
    // Borrados en cascada
    // ------------------------------------------------------------------

    /** Se borra la auditoría: sus preguntas. */
    public static function deleteAuditLines(string $del, string $ser, int $cod): void
    {
        if (self::available()) {
            self::lines($del, $ser, $cod)->delete();
        }
    }

    /** Se borra una incidencia: las preguntas que la tenían como hallazgo se quedan sin él. */
    public static function clearFinding(string $del, string $ser, int $cod): void
    {
        if (self::available()) {
            DB::connection('dynamic')->table(self::LINES)->where('DEL3COD', $del)->where('INC2SER', $ser)->where('INC2COD', $cod)
                ->update(['INC2SER' => null, 'INC2COD' => null]);
        }
    }

    /**
     * Ensayos presenciados reescritos (se renumeran 1, 2... en el orden
     * dado): qué línea anterior es cada nueva. $old = filas anteriores
     * (AUV1COD, TEC2DEL, TEC2COD); $new = las nuevas, cada una con
     * '_anterior' (la línea que dice ser, o null). Las que no lo dicen se
     * emparejan con la primera anterior libre del mismo parámetro. Devuelve
     * [línea anterior => línea nueva].
     */
    public static function matchWitnessed($old, array $new): array
    {
        $free = [];
        foreach ($old as $o) {
            $free[(int) $o->AUV1COD] = (string) $o->TEC2DEL."\x1B".(string) $o->TEC2COD;
        }
        $map = [];
        foreach ($new as $i => $line) {
            $previous = $line['_anterior'] ?? null;
            if ($previous === null) {
                continue;
            }
            if (! array_key_exists($previous, $free)) {
                throw new BusinessRuleException("El ensayo presenciado {$previous} no existe en la auditoría o está repetido");
            }
            $map[$previous] = $i + 1;
            unset($free[$previous]);
        }
        foreach ($new as $i => $line) {
            if (($line['_anterior'] ?? null) !== null) {
                continue;
            }
            $found = array_search((string) $line['TEC2DEL']."\x1B".(string) $line['TEC2COD'], $free, true);
            if ($found !== false) {
                $map[$found] = $i + 1;
                unset($free[$found]);
            }
        }

        return $map;
    }

    /**
     * Tras reescribir los ensayos presenciados: cada pregunta pasa a la línea
     * nueva de su ensayo, y las de los ensayos quitados se borran.
     */
    public static function remapWitnessed(string $del, string $ser, int $cod, array $map): void
    {
        if (! self::available()) {
            return;
        }
        $query = fn () => self::lines($del, $ser, $cod);
        $removed = $query()->whereNotNull('AUV2COD');
        if ($map) {
            $removed->whereNotIn('AUV2COD', array_keys($map));
        }
        $removed->delete();
        // En dos pasos (negativo y vuelta) para que una línea no pise a otra al cambiar de número
        foreach ($map as $previous => $line) {
            if ((int) $previous !== (int) $line) {
                $query()->where('AUV2COD', (int) $previous)->update(['AUV2COD' => -(int) $line]);
            }
        }
        $query()->where('AUV2COD', '<', 0)->update(['AUV2COD' => DB::raw('-AUV2COD')]);
    }
}
