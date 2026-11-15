<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Listas de normativas de servicios (LABSYN), operaciones (LABOYN) e informes
 * (LABIYN), desde Veolab 3.
 * Mismas reglas que Laboratorio.bas (LAB_Normativas*): el orden marca la
 * prioridad, NOR2DEL y NOR2COD de LABSER y LABINF guardan la primera y, sin
 * filas en la lista, la lista es esa normativa principal. Una operación sin
 * filas usa las normativas de sus servicios. Las bases 2.4 no tienen listas:
 * en ellas solo cuenta la principal.
 *
 * Una normativa es [delegación, código].
 */
class VeolabRegulations
{
    /** Normativas del servicio, por orden de prioridad. */
    public static function ofService(string $del, string $cod): array
    {
        $db = DB::connection('dynamic');

        if (VeolabVersion::isV3()) {
            $rows = $db->table('LABSYN')->where('SER3DEL', $del)->where('SER3COD', $cod)
                ->orderBy('SYNNPOS')->get(['NOR3DEL', 'NOR3COD']);
            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($r) => [(string) $r->NOR3DEL, (string) $r->NOR3COD])->all();
            }
        }

        $row = $db->table('LABSER')->where('DEL3COD', $del)->where('SER1COD', $cod)->first(['NOR2DEL', 'NOR2COD']);

        return $row && (string) $row->NOR2COD !== '' ? [[(string) $row->NOR2DEL, (string) $row->NOR2COD]] : [];
    }

    /** Normativas de la operación; una fila sin código indica que se dejó la lista vacía. */
    public static function ofOperation(string $del, string $ser, int $cod): array
    {
        $db = DB::connection('dynamic');

        if (VeolabVersion::isV3()) {
            $rows = $db->table('LABOYN')->where('OPE3DEL', $del)->where('OPE3SER', $ser)->where('OPE3COD', $cod)
                ->orderBy('OYNNPOS')->get(['NOR3DEL', 'NOR3COD']);
            if ($rows->isNotEmpty()) {
                return $rows->filter(fn ($r) => (string) $r->NOR3COD !== '')
                    ->map(fn ($r) => [(string) $r->NOR3DEL, (string) $r->NOR3COD])->values()->all();
            }
        }

        $list = [];
        $services = $db->table('LABOYS')->where('OPE3DEL', $del)->where('OPE3SER', $ser)->where('OPE3COD', $cod)
            ->orderBy('OYSNPOS')->get(['SER3DEL', 'SER3COD']);
        foreach ($services as $service) {
            foreach (self::ofService((string) $service->SER3DEL, (string) $service->SER3COD) as $regulation) {
                if (! in_array($regulation, $list, true)) {
                    $list[] = $regulation;
                }
            }
        }

        return $list;
    }

    /** Lista de un informe nuevo: las de sus operaciones, de menor a mayor, sin repetir. */
    public static function ofOperations(array $operations): array
    {
        usort($operations, fn ($a, $b) => $a[2] <=> $b[2]);

        $list = [];
        foreach ($operations as [$del, $ser, $cod]) {
            foreach (self::ofOperation($del, $ser, $cod) as $regulation) {
                if (! in_array($regulation, $list, true)) {
                    $list[] = $regulation;
                }
            }
        }

        return $list;
    }

    /** Orden de búsqueda de una línea (LAB_OrdenNormativasLinea): las de su servicio, en el orden de la lista, y luego el resto. */
    public static function lineOrder(array $list, array $service): array
    {
        $own = array_filter($list, fn ($regulation) => in_array($regulation, $service, true));
        $rest = array_filter($list, fn ($regulation) => ! in_array($regulation, $service, true));

        return array_values(array_merge($own, $rest));
    }

    /**
     * Normativa de una línea de resultados (LAB_SQLNormativaLinea): valor y
     * normativa aplicada, que decide el rango, salen de la primera de la lista
     * con valor o rango para la técnica. Una normativa sin datos, como la que solo
     * consta como texto legal, se salta. Si ninguna tiene datos queda la primera,
     * sin valor. Devuelve [delegación, código, valor|null].
     */
    public static function forTechnique(array $list, string $tecDel, string $tecCod): array
    {
        foreach ($list as [$del, $cod]) {
            $row = DB::connection('dynamic')->table('LABTYN')
                ->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)
                ->where('NOR3DEL', $del)->where('NOR3COD', $cod)
                ->first(['TYNCVAL', 'TYNCRAN']);
            if ($row && (trim((string) $row->TYNCVAL) !== '' || trim((string) $row->TYNCRAN) !== '')) {
                return [$del, $cod, trim((string) $row->TYNCVAL) !== '' ? $row->TYNCVAL : null];
            }
        }

        return $list ? [$list[0][0], $list[0][1], null] : ['', '', null];
    }

    /** Sustituye la lista del informe. */
    public static function saveForReport(array $report, array $list): void
    {
        if (! VeolabVersion::isV3()) {
            return;
        }

        [$del, $ser, $cod] = $report;
        $db = DB::connection('dynamic');
        $key = ['INF3DEL' => $del, 'INF3SER' => $ser, 'INF3COD' => $cod];

        $db->table('LABIYN')->where($key)->delete();
        foreach (array_values($list) as $index => [$norDel, $norCod]) {
            $db->table('LABIYN')->insert($key + ['NOR3DEL' => $norDel, 'NOR3COD' => $norCod, 'IYNNPOS' => $index + 1]);
        }
    }

    /** La normativa principal del servicio ha cambiado por la API: pasa a ser la primera de su lista. */
    public static function promoteInService(string $del, string $cod, string $norDel, string $norCod): void
    {
        self::promote('LABSYN', 'SYNNPOS', ['SER3DEL' => $del, 'SER3COD' => $cod], $norDel, $norCod);
    }

    /** La normativa principal del informe ha cambiado por la API: pasa a ser la primera de su lista. */
    public static function promoteInReport(array $report, string $norDel, string $norCod): void
    {
        [$del, $ser, $cod] = $report;
        self::promote('LABIYN', 'IYNNPOS', ['INF3DEL' => $del, 'INF3SER' => $ser, 'INF3COD' => $cod], $norDel, $norCod);
    }

    /** Sin filas no hay nada que tocar: la lista ya es la principal. Sin normativa principal la lista queda vacía. */
    private static function promote(string $table, string $position, array $key, string $norDel, string $norCod): void
    {
        if (! VeolabVersion::isV3()) {
            return;
        }

        $db = DB::connection('dynamic');
        if (! $db->table($table)->where($key)->exists()) {
            return;
        }
        if ($norCod === '') {
            $db->table($table)->where($key)->delete();

            return;
        }

        $db->table($table)->where($key)->where('NOR3DEL', $norDel)->where('NOR3COD', $norCod)->delete();
        $first = (int) $db->table($table)->where($key)->min($position);
        $db->table($table)->insert($key + ['NOR3DEL' => $norDel, 'NOR3COD' => $norCod, $position => $first - 1]);
    }
}
