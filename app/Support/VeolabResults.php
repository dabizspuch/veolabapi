<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resultados de una operación (LABRES + LABCOR), replicando la rejilla de
 * FichaResultados de Veolab:
 *  - Celdas (LABCOR con la definición de columna LABCOT): editables si están
 *    activas (CORBACT) y son editables (CORBEDI).
 *  - Marcas por rangos (AplicarMarcaRango / EstaEnIntervalo): rangos LABCYR
 *    de la columna, con los de normativa (RANBNOR) sustituidos por el rango
 *    LABTYN.TYNCRAN de la normativa del servicio; la primera marca concluyente
 *    prevalece sobre "no evaluable" (-2) y el valor puede sustituirse por el
 *    límite superado (RANBSUV/RANBSUX) o por el texto de la marca (MARCSUS).
 *  - Dictamen (ObtenerDictamen) a partir de las marcas de la operación.
 *  - Fechas y estado de la operación (ConfigurarActualizacion): ver
 *    setStart/setEnd/setVerdict/markChanged sobre el array de estado.
 * Números: se leen con coma o punto decimal (sin separador de miles) y se
 * escriben con el separador del laboratorio (config veolab.decimal_separator).
 */
class VeolabResults
{
    /** Marca "no evaluable" de Veolab. */
    public const MARK_NOT_EVALUABLE = -2;

    private const INFINITESIMAL = 0.000001;

    private static array $yesNo = [];

    // ------------------------------------------------------------------
    // Carga de la rejilla
    // ------------------------------------------------------------------

    /**
     * Técnicas (LABRES) de la operación, indexadas por "del\x1Bcod".
     */
    public static function techniques(string $del, string $ser, int $cod): array
    {
        $rows = DB::connection('dynamic')->table('LABRES')
            ->where('OPE3DEL', $del)->where('OPE3SER', $ser)->where('OPE3COD', $cod)
            ->orderBy('RESNORD')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[self::key($row->TEC3DEL, $row->TEC3COD)] = $row;
        }

        return $out;
    }

    /**
     * Celdas de la operación: [técnica => [columna => celda]]. Cada celda
     * lleva su valor y marca actuales, la definición de la columna y sus
     * rangos ya resueltos (con los de normativa sustituidos).
     */
    public static function cells(string $del, string $ser, int $cod): array
    {
        $db = DB::connection('dynamic');

        $rows = $db->table('LABCOR')
            ->leftJoin('LABCOT', function ($join) {
                $join->on('LABCOR.TEC3DEL', '=', 'LABCOT.TEC3DEL')
                    ->on('LABCOR.TEC3COD', '=', 'LABCOT.TEC3COD')
                    ->on('LABCOR.COR1COD', '=', 'LABCOT.COT1COD');
            })
            ->where('LABCOR.OPE3DEL', $del)->where('LABCOR.OPE3SER', $ser)->where('LABCOR.OPE3COD', $cod)
            ->orderBy('LABCOR.TEC3DEL')->orderBy('LABCOR.TEC3COD')->orderBy('LABCOR.COR1COD')
            ->get(['LABCOR.*', 'LABCOT.COTCTIP', 'LABCOT.COTCFOR', 'LABCOT.COTCSEL', 'LABCOT.COTCPRE', 'LABCOT.COTCFOM']);

        $ranges = self::ranges($del, $ser, $cod);
        $regulations = self::regulationRanges($del, $ser, $cod);

        $cells = [];
        foreach ($rows as $row) {
            $tec = self::key($row->TEC3DEL, $row->TEC3COD);
            $column = (int) $row->COR1COD;

            $cellRanges = [];
            foreach ($ranges[$tec."\x1B".$column] ?? [] as $range) {
                if ($range['regulation']) {
                    // Rango de normativa: el de la normativa del servicio si lo hay.
                    $regulation = $regulations[$tec] ?? '';
                    if (trim($regulation) !== '') {
                        $range['interval'] = $regulation;
                    }
                }
                $cellRanges[] = $range;
            }

            $cells[$tec][$column] = [
                'column'     => $column,
                'letter'     => self::letter($column),
                'value'      => (string) ($row->CORCVAL ?? ''),
                'original'   => (string) ($row->CORCVAL ?? ''),
                'mark'       => [(string) ($row->MAR2DEL ?? ''), (int) $row->MAR2COD],
                'markBefore' => [(string) ($row->MAR2DEL ?? ''), (int) $row->MAR2COD],
                'type'       => (string) ($row->COTCTIP ?? ''),
                'formula'    => (string) ($row->COTCFOM ?? ''),
                'format'     => (string) ($row->COTCFOR ?? ''),
                'default'    => (string) ($row->COTCPRE ?? ''),
                'title'      => (string) ($row->CORCTIT ?? ''),
                'active'     => $row->CORBACT === 'T',
                'editable'   => $row->CORBACT === 'T' && $row->CORBEDI === 'T',
                'control'    => $row->CORBCON === 'T' || $row->CORBCOP === 'T',
                'ranges'     => $cellRanges,
                'changed'    => false,
                'warning'    => '',
            ];
        }

        return $cells;
    }

    /**
     * Rangos LABCYR de las columnas de la operación, en el orden de Veolab
     * (RAN3COD descendente): ["tec\x1Bcolumna" => [rango...]].
     */
    private static function ranges(string $del, string $ser, int $cod): array
    {
        $rows = DB::connection('dynamic')->table('LABCOR')
            ->join('LABCYR', function ($join) {
                $join->on('LABCYR.TEC3DEL', '=', 'LABCOR.TEC3DEL')
                    ->on('LABCYR.TEC3COD', '=', 'LABCOR.TEC3COD')
                    ->on('LABCYR.COT3COD', '=', 'LABCOR.COR1COD');
            })
            ->leftJoin('LABRAN', function ($join) {
                $join->on('LABCYR.RAN3DEL', '=', 'LABRAN.DEL3COD')
                    ->on('LABCYR.RAN3COD', '=', 'LABRAN.RAN1COD');
            })
            ->where('LABCOR.OPE3DEL', $del)->where('LABCOR.OPE3SER', $ser)->where('LABCOR.OPE3COD', $cod)
            ->distinct()
            ->orderBy('LABCYR.TEC3DEL')->orderBy('LABCYR.TEC3COD')->orderBy('LABCYR.COT3COD')
            ->orderBy('LABCYR.RAN3COD', 'desc')->orderBy('LABCYR.MAR2DEL')->orderBy('LABCYR.MAR2COD')
            ->orderBy('LABRAN.RANBSUV')->orderBy('LABRAN.RANBSUX')->orderBy('LABRAN.RANBNOR')
            ->get(['LABCYR.TEC3DEL', 'LABCYR.TEC3COD', 'LABCYR.COT3COD', 'LABCYR.RAN3COD', 'LABCYR.CYRCVAR',
                'LABCYR.MAR2DEL', 'LABCYR.MAR2COD', 'LABRAN.RANBSUV', 'LABRAN.RANBSUX', 'LABRAN.RANBNOR']);

        $out = [];
        foreach ($rows as $row) {
            $out[self::key($row->TEC3DEL, $row->TEC3COD)."\x1B".(int) $row->COT3COD][] = [
                'mark'       => [(string) ($row->MAR2DEL ?? ''), (int) $row->MAR2COD],
                'interval'   => (string) ($row->CYRCVAR ?? ''),
                'regulation' => $row->RANBNOR === 'T',
                'limit'      => $row->RANBSUV === 'T',
                'withValue'  => $row->RANBSUX === 'T',
            ];
        }

        return $out;
    }

    /** Rango LABTYN.TYNCRAN de la normativa del servicio de cada técnica. */
    private static function regulationRanges(string $del, string $ser, int $cod): array
    {
        $rows = DB::connection('dynamic')->table('LABRES')
            ->leftJoin('LABSER', function ($join) {
                $join->on('LABRES.SER2DEL', '=', 'LABSER.DEL3COD')
                    ->on('LABRES.SER2COD', '=', 'LABSER.SER1COD');
            })
            ->leftJoin('LABTYN', function ($join) {
                $join->on('LABRES.TEC3DEL', '=', 'LABTYN.TEC3DEL')
                    ->on('LABRES.TEC3COD', '=', 'LABTYN.TEC3COD')
                    ->on('LABSER.NOR2DEL', '=', 'LABTYN.NOR3DEL')
                    ->on('LABSER.NOR2COD', '=', 'LABTYN.NOR3COD');
            })
            ->where('LABRES.OPE3DEL', $del)->where('LABRES.OPE3SER', $ser)->where('LABRES.OPE3COD', $cod)
            ->get(['LABRES.TEC3DEL', 'LABRES.TEC3COD', 'LABTYN.TYNCRAN']);

        $out = [];
        foreach ($rows as $row) {
            $out[self::key($row->TEC3DEL, $row->TEC3COD)] = (string) ($row->TYNCRAN ?? '');
        }

        return $out;
    }

    /** Marcas vigentes (LABMAR sin baja): ["del\x1Bcod" => marca]. */
    public static function marks(): array
    {
        $out = [];
        $rows = DB::connection('dynamic')->table('LABMAR')
            ->where(fn ($q) => $q->whereNull('MARBBAJ')->orWhere('MARBBAJ', '<>', 'T'))
            ->get(['DEL3COD', 'MAR1COD', 'MARCAVI', 'MARCSUS']);
        foreach ($rows as $row) {
            $out[self::key($row->DEL3COD, $row->MAR1COD)] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Marcas
    // ------------------------------------------------------------------

    /**
     * AplicarMarcaRango: si la celda tiene rangos, calcula la marca y aplica
     * la sustitución del valor. Devuelve false si la celda no tiene rangos
     * (su marca no se toca).
     */
    public static function applyRangeMark(array &$cell, array $marks, string $delegation, bool $keepLimit): bool
    {
        if (! $cell['ranges']) {
            return false;
        }

        $selected = ['', ''];
        $notEvaluableSelected = false;
        $limitSelected = '';

        foreach ($cell['ranges'] as $range) {
            $interval = str_replace('*', '', $range['interval']);
            if (trim($interval) === '' || $cell['value'] === '') {
                continue;
            }

            $limit = '';
            $notEvaluable = false;
            if (self::inInterval($cell['value'], $interval, $notEvaluable, $range['withValue'], $limit)) {
                continue;
            }

            if ($notEvaluable) {
                // "No evaluable" solo si ningún rango ha dado una marca concluyente.
                if ($selected[1] === '') {
                    $mark = self::notEvaluableMark($marks, $delegation);
                    if ($mark !== null) {
                        $selected = [$mark[0], (string) $mark[1]];
                        $notEvaluableSelected = true;
                    }
                }
            } elseif ($selected[1] === '' || $notEvaluableSelected) {
                $selected = [$range['mark'][0], (string) $range['mark'][1]];
                $notEvaluableSelected = false;
            }

            if ($limitSelected === '') {
                $limitSelected = ($range['limit'] || $range['withValue']) ? $limit : '';
            }
        }

        self::applyMark($cell, $selected[0], (int) $selected[1], $marks, $keepLimit ? '' : $limitSelected);

        return true;
    }

    /**
     * AplicarMarca: guarda la marca y, si es una marca vigente, su aviso y la
     * sustitución del valor (texto MARCSUS, o el límite superado); las
     * casillas no se sustituyen.
     */
    public static function applyMark(array &$cell, string $del, int $cod, array $marks, string $limit = ''): void
    {
        $cell['mark'] = [$del, $cod];
        $cell['warning'] = '';

        if ($cod === 0) {
            return;
        }

        $mark = $marks[self::key($del, $cod)] ?? null;
        if (! $mark) {
            return;
        }

        $cell['warning'] = (string) ($mark->MARCAVI ?? '');
        if ($cell['type'] === 'C') {
            return;
        }
        if ((string) ($mark->MARCSUS ?? '') !== '') {
            $cell['value'] = (string) $mark->MARCSUS;
        } elseif ($limit !== '') {
            $cell['value'] = $limit;
        }
    }

    /** Marca "no evaluable" (-2) de la delegación, o la general. */
    private static function notEvaluableMark(array $marks, string $delegation): ?array
    {
        foreach (array_unique([$delegation, '']) as $del) {
            if (isset($marks[self::key($del, self::MARK_NOT_EVALUABLE)])) {
                return [$del, self::MARK_NOT_EVALUABLE];
            }
        }

        return null;
    }

    /**
     * EstaEnIntervalo: lista de intervalos "(a;b]" (separador ';', o ',' si no
     * hay ';'), o de valores entre llaves {"x" "y"}. Los valores "<n" y ">n"
     * se evalúan como intervalo abierto y pueden ser "no evaluables".
     */
    public static function inInterval(string $value, string $interval, bool &$notEvaluable, bool $withValue, string &$limit): bool
    {
        try {
            return self::evaluateInterval($value, $interval, $notEvaluable, $withValue, $limit);
        } catch (\InvalidArgumentException) {
            // Error de ejecución en VB (Mid con longitud negativa, p. ej. ';'
            // también entre intervalos): lo avisa y queda como no evaluable.
            $notEvaluable = true;

            return false;
        }
    }

    private static function evaluateInterval(string $value, string $interval, bool &$notEvaluable, bool $withValue, string &$limit): bool
    {
        $notEvaluable = false;
        $inside = false;
        $syntaxError = false;
        $separator = str_contains($interval, ';') ? ';' : ',';
        // Posición del separador en la lista completa (VB no la recalcula).
        $separatorPos = self::instr($interval, $separator);

        while ($interval !== '' && ! $inside) {
            $open = self::instr($interval, '(') ?: (self::instr($interval, '[') ?: self::instr($interval, '{'));
            $close = self::instr($interval, ')') ?: (self::instr($interval, ']') ?: self::instr($interval, '}'));

            if ($open === 0 || $close === 0) {
                $syntaxError = true;
                break;
            }

            $trimmed = trim($value);
            if (self::mid($interval, $open, 1) === '{') {
                $list = trim(self::mid($interval, $open + 1, $close - $open - 1));
                $inside = str_contains($list, '"'.$value.'"');
            } elseif (str_starts_with($trimmed, '<')) {
                $max = (self::number(self::mid($value, 2)) ?? 0.0) - self::INFINITESIMAL;
                $inMin = self::numberInInterval(0.0, $interval, $separator, $open, $close, $withValue, $limit);
                $inMax = self::numberInInterval($max, $interval, $separator, $open, $close, $withValue, $limit);
                if ($inMin && $inMax) {
                    $inside = true;
                } elseif (! $inMin && ! $inMax) {
                    $start = self::number(self::mid($interval, $open + 1, $separatorPos - $open - 1)) ?? 0.0;
                    if ($max > $start) {
                        $notEvaluable = true;
                    }
                } else {
                    $notEvaluable = true;
                }
            } elseif (str_starts_with($trimmed, '>')) {
                $min = (self::number(self::mid($value, 2)) ?? 0.0) + self::INFINITESIMAL;
                $inMin = self::numberInInterval($min, $interval, $separator, $open, $close, $withValue, $limit);
                $inMax = self::numberInInterval('&', $interval, $separator, $open, $close, $withValue, $limit);
                if ($inMin && $inMax) {
                    $inside = true;
                } elseif (! $inMin && ! $inMax) {
                    $end = self::number(self::mid($interval, $separatorPos + 1, $close - $separatorPos - 1)) ?? 0.0;
                    if ($min < $end) {
                        $notEvaluable = true;
                    }
                } else {
                    $notEvaluable = true;
                }
            } else {
                $inside = self::numberInInterval($value, $interval, $separator, $open, $close, $withValue, $limit);
            }

            $interval = self::mid($interval, $close + 1);
        }

        if ($notEvaluable) {
            $limit = '';
        }
        if ($syntaxError) {
            // Error de sintaxis: VB lo avisa y lo trata como no evaluable.
            $notEvaluable = true;

            return false;
        }

        return $inside;
    }

    /**
     * EstaNumeroEnIntervalo: $value numérico (o '&' = infinito) dentro del
     * intervalo que empieza en $open y acaba en $close. Anota el límite
     * superado ("<a" / ">b", con el valor entre paréntesis si se pide).
     */
    private static function numberInInterval($value, string $interval, string $separator, int $open, int $close, bool $withValue, string &$limit): bool
    {
        $infinite = $value === '&';
        $number = is_float($value) ? $value : (self::number((string) $value) ?? 0.0);
        if ($infinite) {
            $number = 0.0;
        }

        $separatorPos = self::instr($interval, $separator);
        if ($separatorPos === 0) {
            return false;
        }

        if ($infinite) {
            $okStart = true;
        } else {
            $start = trim(self::mid($interval, $open + 1, $separatorPos - $open - 1));
            if (self::number($start) !== null) {
                $okStart = match (self::mid($interval, $open, 1)) {
                    '(' => $number > self::number($start),
                    '[' => $number >= self::number($start),
                    default => false,
                };
                if (! $okStart) {
                    $limit = '<'.$start.($withValue ? ' ('.self::vbString($number).')' : '');
                }
            } else {
                $okStart = true; // no numérico: infinito
            }
        }

        $end = trim(self::mid($interval, $separatorPos + 1, $close - $separatorPos - 1));
        if (self::number($end) !== null) {
            if ($infinite) {
                $okEnd = false;
            } else {
                $okEnd = match (self::mid($interval, $close, 1)) {
                    ')' => $number < self::number($end),
                    ']' => $number <= self::number($end),
                    default => false,
                };
                if (! $okEnd) {
                    $limit = '>'.$end.($withValue ? ' ('.self::vbString($number).')' : '');
                }
            }
        } else {
            $okEnd = true;
        }

        return $okStart && $okEnd;
    }

    // ------------------------------------------------------------------
    // Dictamen
    // ------------------------------------------------------------------

    /**
     * ObtenerDictamen: el dictamen (sin baja) asociado a alguna marca de la
     * operación, con prioridad para el de código inferior; si no hay, el
     * primero sin marca. Devuelve [del, cod] o null.
     */
    public static function verdict(array $cells): ?array
    {
        $db = DB::connection('dynamic');
        $active = fn ($q) => $q->whereNull('DICBBAJ')->orWhere('DICBBAJ', '<>', 'T');

        $selected = null;
        foreach ($cells as $columns) {
            foreach ($columns as $cell) {
                if ($cell['mark'][1] <= 0) {
                    continue;
                }
                $verdict = $db->table('LABDIC')
                    ->where('MAR2DEL', $cell['mark'][0])->where('MAR2COD', $cell['mark'][1])
                    ->where($active)
                    ->orderBy('DEL3COD')->orderBy('DIC1COD')
                    ->first(['DEL3COD', 'DIC1COD']);
                if (! $verdict) {
                    continue;
                }
                [$del, $cod] = [(string) $verdict->DEL3COD, (int) $verdict->DIC1COD];
                if ($selected === null
                    || ($del === $selected[0] && $cod < $selected[1])
                    || ($del !== $selected[0] && $del === '')) {
                    $selected = [$del, $cod];
                }
            }
        }

        if ($selected === null) {
            $verdict = $db->table('LABDIC')
                ->where(fn ($q) => $q->whereNull('MAR2DEL')->orWhere('MAR2DEL', ''))
                ->where('MAR2COD', 0)
                ->where($active)
                ->orderBy('DEL3COD')->orderBy('DIC1COD')
                ->first(['DEL3COD', 'DIC1COD']);
            if ($verdict) {
                $selected = [(string) $verdict->DEL3COD, (int) $verdict->DIC1COD];
            }
        }

        return $selected;
    }

    // ------------------------------------------------------------------
    // Fechas y estado de la operación (ConfigurarActualizacion)
    // ------------------------------------------------------------------
    // $op = ['start' => ?string, 'end' => ?string, 'state' => int, 'verdict' => ?[del, cod]]

    /** Fecha de inicio: con fecha, al menos iniciada; sin ella, se vacían fin y dictamen. */
    public static function setStart(array &$op, ?string $date): void
    {
        $op['start'] = $date;
        if ($date !== null) {
            $op['state'] = max($op['state'], 3);
        } else {
            $op['end'] = null;
            $op['verdict'] = null;
            $op['state'] = min($op['state'], 2);
        }
    }

    /** Fecha de fin: con fecha, al menos finalizada y dictamen si no lo tenía. */
    public static function setEnd(array &$op, ?string $date, callable $verdict): void
    {
        $op['end'] = $date;
        if ($date !== null) {
            $op['start'] ??= $date;
            $op['verdict'] ??= $verdict();
            $op['state'] = max($op['state'], 4);
        } else {
            $op['verdict'] = null;
            $op['state'] = min($op['state'], 3);
        }
    }

    /** Dictamen manual: con dictamen, fechas vacías a ahora y al menos finalizada. */
    public static function setVerdict(array &$op, ?array $verdict, string $now): void
    {
        $op['verdict'] = $verdict;
        if ($verdict !== null) {
            $op['start'] ??= $now;
            $op['end'] ??= $now;
            $op['state'] = max($op['state'], 4);
        }
    }

    /** Cambio de marcas: el dictamen se recalcula si está finalizada. */
    public static function marksChanged(array &$op, callable $verdict): void
    {
        $op['verdict'] = $op['end'] === null ? null : $verdict();
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** Textos de Sí/No de las casillas (IDICAD ESPVALSI/ESPVALNO, español). */
    public static function yesNo(): array
    {
        $db = DB::connection('dynamic');
        $name = $db->getDatabaseName();
        if (! isset(self::$yesNo[$name])) {
            $texts = $db->table('IDICAD')->where('IDI3COD', 1)
                ->whereIn('CAD1COD', ['ESPVALSI', 'ESPVALNO'])->pluck('CADCDES', 'CAD1COD');
            self::$yesNo[$name] = [(string) ($texts['ESPVALSI'] ?? 'Sí') ?: 'Sí', (string) ($texts['ESPVALNO'] ?? 'No') ?: 'No'];
        }

        return self::$yesNo[$name];
    }

    /**
     * Número de un texto de Veolab. Se admiten coma y punto decimal (lo
     * guardado puede venir de equipos o clientes con otro separador), sin
     * separador de miles. Null si no es numérico.
     */
    public static function number(?string $value): ?float
    {
        $text = str_replace(',', '.', trim((string) $value));

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    /** CStr de un Double con el separador del laboratorio ("0,499999", "12", "1E-07"). */
    public static function vbString(float $number): string
    {
        $text = sprintf('%.15G', $number);
        if (preg_match('/^(-?[\d.]+)E([+-])(\d+)$/', $text, $m)) {
            $mantissa = rtrim(rtrim($m[1], '0'), '.');
            $text = $mantissa.'E'.$m[2].str_pad($m[3], 2, '0', STR_PAD_LEFT);
        }

        return str_replace('.', self::decimalSeparator(), $text);
    }

    /** Separador decimal de los equipos Veolab del servidor (config veolab.decimal_separator). */
    public static function decimalSeparator(): string
    {
        return config('veolab.decimal_separator') === '.' ? '.' : ',';
    }

    /**
     * Número (JSON, o texto con coma o punto decimal) como texto con el
     * separador del laboratorio. Null si no es un número.
     */
    public static function numberText($value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return self::vbString((float) $value);
        }
        if (! is_string($value) || ! preg_match('/^\s*([+-]?)(\d+)(?:[.,](\d+))?\s*$/', $value, $m)) {
            return null;
        }

        return $m[1].$m[2].(isset($m[3]) ? self::decimalSeparator().$m[3] : '');
    }

    /** Letra de columna (1 = A, 27 = AA). */
    public static function letter(int $column): string
    {
        $letter = '';
        while ($column > 0) {
            $column--;
            $letter = chr(65 + $column % 26).$letter;
            $column = intdiv($column, 26);
        }

        return $letter;
    }

    /** CAD_ObtenColumnaLetra. */
    public static function column(string $letter): int
    {
        $column = 0;
        foreach (str_split(strtoupper($letter)) as $char) {
            $column = $column * 26 + (ord($char) - 64);
        }

        return $column;
    }

    public static function key($del, $cod): string
    {
        return ((string) ($del ?? ''))."\x1B".$cod;
    }

    /** InStr de VB (1-based, 0 si no está). */
    private static function instr(string $text, string $search): int
    {
        $pos = mb_strpos($text, $search);

        return $pos === false ? 0 : $pos + 1;
    }

    /** Mid de VB (1-based); con inicio < 1 o longitud negativa VB da error. */
    private static function mid(string $text, int $start, ?int $length = null): string
    {
        if ($start < 1 || ($length !== null && $length < 0)) {
            throw new \InvalidArgumentException('Mid');
        }

        return (string) mb_substr($text, $start - 1, $length);
    }
}
