<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Rejilla de una factura construida a partir de sus operaciones, réplica de
 * la facturación de Veolab (FAC_FacturarOperacionesCliente, que es también lo
 * que hace FichaFactura.ReconstruirDetalles al añadir operaciones):
 *
 *  - Agrupación por servicio (LABCON.CONCTAR 'S', por defecto): primero las
 *    técnicas y gastos sueltos (sin servicio); luego, por operación, cada
 *    servicio de sus técnicas seguido de ellas, los servicios sin técnicas y
 *    los gastos con servicio (colgando del último servicio de la operación).
 *  - Agrupación por operación ('O'): una línea por operación (su descripción
 *    o sus servicios, según CONCCDS) con sus técnicas y gastos.
 *  - Al final, el grupo de gastos adicionales (gastos agrupados) y el de
 *    suplidos.
 *  - Las líneas iguales (misma clave: tipo, código, precio, descuento,
 *    columnas configuradas y, si se desglosa, cliente y punto de muestreo)
 *    se acumulan en cantidad.
 *
 * Diferencias con Veolab: los gastos agrupados con servicio no se repiten
 * (Veolab los añade con su servicio y otra vez en el grupo de agrupados) y
 * las técnicas no llevan marca de acreditación (en Veolab depende de la
 * última pantalla que la usó).
 */
class VeolabInvoiceLines
{
    /**
     * @param  array  $operations  [[delegación, serie, código]] en el orden de la factura
     * @return array [líneas (formato de VeolabBillingLines), desglose, contrato|null, presupuesto|null]
     */
    public static function fromOperations(array $operations, object $ctx): array
    {
        $db = DB::connection('dynamic');
        $config = $db->table('LABCON')->where('CON1COD', 1)
            ->first(['CONBDPC', 'CONBDPP', 'CONCTAR', 'CONCCDS', 'CONCMOF', 'CONCMOR', 'CONCMOA', 'CONBEXP']);
        $cfg = (object) [
            'byService'    => (string) ($config->CONCTAR ?? '') !== 'O',
            'descByOp'     => (string) ($config->CONCCDS ?? '') === 'D',
            'dateField'    => (string) ($config->CONCMOF ?? ''),
            'refField'     => (string) ($config->CONCMOR ?? ''),
            'extraField'   => (string) ($config->CONCMOA ?? ''),
            'splitPoint'   => ($config->CONBDPP ?? '') === 'T',
            'expand'       => ($config->CONBEXP ?? '') === 'T',
        ];

        $ops = self::operations($operations);
        $cfg->manyClients = ($config->CONBDPC ?? '') === 'T'
            && count(array_unique(array_map(fn ($o) => $o->CLI2DEL."\x1B".$o->CLI2COD, $ops))) > 1;
        $cfg->manyPoints = $cfg->splitPoint
            && count(array_unique(array_map(fn ($o) => $o->CLI2DEL."\x1B".$o->CLI2COD."\x1B".$o->PUM2COD,
                array_filter($ops, fn ($o) => $o->PUM2COD !== null)))) > 1;

        $grid = [];
        $breakdown = '';
        $contract = null;
        $budget = null;
        $first = true;

        if ($cfg->byService) {
            self::looseLines($grid, $operations, $ops, $cfg, $ctx);
        }

        // Variables que Veolab arrastra entre operaciones (columnas y posición).
        $state = (object) ['date' => '', 'ref' => '', 'extra' => '', 'position' => 0];

        foreach ($operations as $i => [$del, $ser, $cod]) {
            $op = $ops[self::opKey($del, $ser, $cod)] ?? null;
            if (! $op) {
                continue;
            }
            if ($first) {
                // Desglose, contrato y presupuesto de la primera operación.
                $breakdown = (string) $op->OPECTID ?: 'S';
                $contract = (int) $op->CON2COD > 0 ? [(string) $op->CON2DEL, (string) $op->CON2SER, (int) $op->CON2COD] : null;
                $budget = (int) $op->PRE2COD > 0 ? [(string) $op->PRE2DEL, (string) $op->PRE2SER, (int) $op->PRE2COD] : null;
                $first = false;
            }
            self::operationLines($grid, $op, $i === 0, $cfg, $ctx, $state);
        }

        self::groupedExpenses($grid, $operations, $ops, $cfg, 'A');
        self::groupedExpenses($grid, $operations, $ops, $cfg, 'U');

        $lines = [];
        foreach ($grid as $row) {
            if (! $cfg->expand && ($row['type'] === 'S' || $row['type'] === 'O')) {
                $row['collapsed'] = true;
            }
            unset($row['key']);
            $lines[] = $row;
        }

        return [$lines, $breakdown, $contract, $budget];
    }

    /** FAC_SumarPrecioOperaciones: subtotal con desglose por operaciones. */
    public static function operationsTotal(array $operations): float
    {
        $total = 0.0;
        foreach ($operations as [$del, $ser, $cod]) {
            $op = DB::connection('dynamic')->table('LABOPE')
                ->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod)->first(['OPENPRE', 'OPECDTO']);
            if ($op) {
                $total += VeolabOperationServices::withDiscount(VeolabOperationServices::decimal($op->OPENPRE), (string) $op->OPECDTO);
            }
        }

        return round($total, 2);
    }

    // ------------------------------------------------------------------
    // Fases de la construcción
    // ------------------------------------------------------------------

    /** Técnicas y gastos (no agrupados ni suplidos) sin servicio. */
    private static function looseLines(array &$grid, array $operations, array $ops, object $cfg, object $ctx): void
    {
        $db = DB::connection('dynamic');

        foreach ($operations as [$del, $ser, $cod]) {
            $op = $ops[self::opKey($del, $ser, $cod)] ?? null;
            if (! $op) {
                continue;
            }
            $techniques = $db->table('LABRES')->where(self::whereOp($op, 'OPE3'))
                ->where(fn ($q) => $q->whereNull('SER2COD')->orWhere('SER2COD', ''))
                ->orderBy('RESNORD')->get(['TEC3DEL', 'TEC3COD', 'RESCNOI', 'RESDACR', 'RESNPRE', 'RESCDTO']);
            foreach ($techniques as $t) {
                $key = self::key(['T', $t->TEC3DEL, '', $t->TEC3COD, $t->RESNPRE, $t->RESCDTO, '', '', ''], $op, $cfg);
                if (! self::increment($grid, $key)) {
                    self::insert($grid, self::techniqueLine($key, $t, $op, $cfg, $ctx), 0);
                }
            }

            $expenses = self::expenses($op)
                ->where(fn ($q) => $q->whereNull('LABOYG.OYGBAGR')->orWhere('LABOYG.OYGBAGR', '<>', 'T'))
                ->where(fn ($q) => $q->whereNull('LABOYG.OYGBSUP')->orWhere('LABOYG.OYGBSUP', '<>', 'T'))
                ->where(fn ($q) => $q->whereNull('LABOYG.SER2COD')->orWhere('LABOYG.SER2COD', ''))
                ->get();
            foreach ($expenses as $e) {
                $key = self::key(['GO', $e->ESC3DEL, '', $e->ESC3COD, $e->OYGNPRE, $e->OYGCDTO, '', '', ''], $op, $cfg);
                if (! self::increment($grid, $key)) {
                    // Veolab lo inserta en la posición del gasto en la operación.
                    self::insert($grid, self::expenseLine($key, $e, $op, $cfg), (int) $e->OYGNPOS);
                }
            }
        }
    }

    /** Líneas de una operación: la de operación o sus servicios, técnicas y gastos. */
    private static function operationLines(array &$grid, object $op, bool $isFirst, object $cfg, object $ctx, object $state): void
    {
        $db = DB::connection('dynamic');
        $report = $db->table('LABIYO')
            ->join('LABINF', function ($join) {
                $join->on('LABIYO.INF3DEL', '=', 'LABINF.DEL3COD')->on('LABIYO.INF3SER', '=', 'LABINF.INF1SER')
                    ->on('LABIYO.INF3COD', '=', 'LABINF.INF1COD');
            })
            ->where('LABINF.INFBFIN', 'T')
            ->where('LABIYO.OPE3DEL', $op->DEL3COD)->where('LABIYO.OPE3SER', $op->OPE1SER)->where('LABIYO.OPE3COD', $op->OPE1COD)
            ->first(['LABIYO.INF3DEL', 'LABIYO.INF3SER', 'LABIYO.INF3COD']);
        $services = $db->table('LABOYS')
            ->leftJoin('LABSER', function ($join) {
                $join->on('LABOYS.SER3DEL', '=', 'LABSER.DEL3COD')->on('LABOYS.SER3COD', '=', 'LABSER.SER1COD');
            })
            ->where(self::whereOp($op, 'LABOYS.OPE3'))
            ->orderBy('LABOYS.OYSNPOS')
            ->get(['LABOYS.SER3DEL', 'LABOYS.SER3COD', 'LABOYS.OYSNPRE', 'LABOYS.OYSCDTO', 'LABOYS.OYSNPOS', 'LABSER.SERCNOI']);

        // Línea de grupo de la operación.
        if (! $cfg->byService) {
            $name = $cfg->descByOp ? (string) $op->OPECDES : implode(', ', $services->pluck('SERCNOI')->map(fn ($v) => (string) $v)->all());
            $firstService = $cfg->descByOp ? null : $services->first();
            [$state->date, $state->ref, $state->extra] = self::columns(true, (string) ($firstService->SER3DEL ?? ''),
                (string) ($firstService->SER3COD ?? ''), $op, $report, $cfg);
            $line = self::baseLine('O', '', $op, $cfg);
            $line['desc'] = $name;
            $line['price'] = VeolabOperationServices::decimal($op->OPENPRE);
            $line['discount'] = (string) ($op->OPECDTO ?? '');
            $line['del'] = (string) $op->DEL3COD;
            $line['ser'] = (string) $op->OPE1SER;
            $line['cod'] = (string) $op->OPE1COD;
            [$line['date'], $line['ref'], $line['extra']] = [self::dateValue($state->date), $state->ref, $state->extra];
            self::insert($grid, $line, 0);
        }

        $techniques = $db->table('LABRES')
            ->leftJoin('LABSER', function ($join) {
                $join->on('LABRES.SER2DEL', '=', 'LABSER.DEL3COD')->on('LABRES.SER2COD', '=', 'LABSER.SER1COD');
            })
            ->leftJoin('LABOYS', function ($join) {
                $join->on('LABRES.OPE3DEL', '=', 'LABOYS.OPE3DEL')->on('LABRES.OPE3SER', '=', 'LABOYS.OPE3SER')
                    ->on('LABRES.OPE3COD', '=', 'LABOYS.OPE3COD')->on('LABRES.SER2DEL', '=', 'LABOYS.SER3DEL')
                    ->on('LABRES.SER2COD', '=', 'LABOYS.SER3COD');
            })
            ->where(self::whereOp($op, 'LABRES.OPE3'))
            ->orderBy('LABRES.RESNORD')
            ->get(['LABRES.TEC3DEL', 'LABRES.TEC3COD', 'LABRES.RESCNOI', 'LABRES.RESDACR', 'LABRES.RESNPRE', 'LABRES.RESCDTO',
                'LABRES.SER2DEL', 'LABRES.SER2COD', 'LABSER.SERCNOI', 'LABOYS.OYSNPRE', 'LABOYS.OYSCDTO']);

        $added = [];
        $currentService = null;
        $currentKey = null;
        foreach ($techniques as $t) {
            [$state->date, $state->ref, $state->extra] = self::columns(false, (string) $t->SER2DEL, (string) $t->SER2COD, $op, $report, $cfg);
            $hasService = (string) $t->SER2COD !== '';

            // Cambio de servicio: línea del servicio.
            if ($cfg->byService && $hasService && $currentService !== $t->SER2DEL."\x1B".$t->SER2COD) {
                $added[$t->SER2DEL."\x1B".$t->SER2COD] = true;
                $key = self::key(['S', $t->SER2DEL, '', $t->SER2COD, $t->OYSNPRE, $t->OYSCDTO, $state->date, $state->ref, $state->extra], $op, $cfg);
                if (! self::increment($grid, $key)) {
                    self::insert($grid, self::serviceLine($key, $t->SER2DEL, $t->SER2COD, $t->SERCNOI, $t->OYSNPRE, $t->OYSCDTO, $op, $cfg, $state), 0);
                }
                $currentService = $t->SER2DEL."\x1B".$t->SER2COD;
                $currentKey = $key;
            }

            if (! $cfg->byService || $hasService) {
                $parts = ['T', $t->TEC3DEL, '', $t->TEC3COD, $t->RESNPRE, $t->RESCDTO, '', '', '',
                    $t->SER2DEL, $t->SER2COD, $t->OYSNPRE, $t->OYSCDTO, $state->date, $state->ref, $state->extra];
                $key = self::key(array_merge($parts, $cfg->byService ? ['', '', ''] : [$op->DEL3COD, $op->OPE1SER, $op->OPE1COD]), $op, $cfg, true);
                if (! self::increment($grid, $key)) {
                    $position = $cfg->byService ? self::endOfGroup($grid, $currentKey) : 0;
                    self::insert($grid, self::techniqueLine($key, $t, $op, $cfg, $ctx), $position);
                }
            }
        }

        // Servicios de la operación que no han salido por sus técnicas.
        if ($cfg->byService) {
            foreach ($services as $s) {
                if (isset($added[$s->SER3DEL."\x1B".$s->SER3COD])) {
                    continue;
                }
                // La clave usa las columnas de la técnica anterior, como Veolab.
                $key = self::key(['S', $s->SER3DEL, '', $s->SER3COD, $s->OYSNPRE, $s->OYSCDTO, $state->date, $state->ref, $state->extra], $op, $cfg);
                if (! self::increment($grid, $key)) {
                    if ($isFirst) {
                        $state->position = (int) $s->OYSNPOS;   // solo en la primera operación
                    }
                    [$state->date, $state->ref, $state->extra] = self::columns(false, (string) $s->SER3DEL, (string) $s->SER3COD, $op, $report, $cfg);
                    self::insert($grid, self::serviceLine($key, $s->SER3DEL, $s->SER3COD, $s->SERCNOI, $s->OYSNPRE, $s->OYSCDTO, $op, $cfg, $state), $state->position);
                }
            }
        }

        // Gastos con servicio (no suplidos ni agrupados), tras el último servicio.
        $expenses = self::expenses($op)
            ->where(fn ($q) => $q->whereNull('LABOYG.OYGBSUP')->orWhere('LABOYG.OYGBSUP', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('LABOYG.OYGBAGR')->orWhere('LABOYG.OYGBAGR', '<>', 'T'))
            ->where('LABOYG.SER2COD', '<>', '')
            ->get();
        foreach ($expenses as $e) {
            $key = self::key(['G', $e->ESC3DEL, '', $e->ESC3COD, $e->OYGNPRE, $e->OYGCDTO, '', '', '', $e->SER2DEL, $e->SER2COD,
                '', '', '', '', '', $op->DEL3COD, $op->OPE1SER, $op->OPE1COD], $op, $cfg, true);
            if (! self::increment($grid, $key)) {
                $state->position = $cfg->byService ? self::endOfGroup($grid, $currentKey) : 0;
                self::insert($grid, self::expenseLine($key, $e, $op, $cfg), $state->position);
            }
        }
    }

    /** Gastos agrupados ('A', grupo de gastos adicionales) o suplidos ('U') al final. */
    private static function groupedExpenses(array &$grid, array $operations, array $ops, object $cfg, string $group): void
    {
        $column = $group === 'A' ? 'OYGBAGR' : 'OYGBSUP';
        $rowsByOp = [];
        foreach ($operations as [$del, $ser, $cod]) {
            $op = $ops[self::opKey($del, $ser, $cod)] ?? null;
            if ($op) {
                $rowsByOp[] = [$op, self::expenses($op)->where("LABOYG.{$column}", 'T')->get()];
            }
        }
        if (! array_filter($rowsByOp, fn ($r) => $r[1]->isNotEmpty())) {
            return;
        }

        $header = VeolabBillingLines::emptyLine($group);
        $header['key'] = '';
        $header['qty'] = 0.0;
        $header['desc'] = VeolabBillingLines::groupLabel($group);
        self::insert($grid, $header, 0);

        foreach ($rowsByOp as [$op, $expenses]) {
            foreach ($expenses as $e) {
                $key = self::key([$group === 'A' ? 'GG' : 'GS', $e->ESC3DEL, '', $e->ESC3COD, $e->OYGNPRE, $e->OYGCDTO, '', '', ''], $op, $cfg);
                if (! self::increment($grid, $key)) {
                    self::insert($grid, self::expenseLine($key, $e, $op, $cfg, false), 0);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Líneas
    // ------------------------------------------------------------------

    private static function baseLine(string $type, string $key, object $op, object $cfg, bool $point = true): array
    {
        $line = VeolabBillingLines::emptyLine($type);
        $line['key'] = $key;
        // FAC_InsertarLinea: cliente de la operación y, si se desglosa, su punto.
        if ((string) $op->CLI2COD !== '') {
            $line['lineClientDel'] = (string) $op->CLI2DEL;
            $line['lineClientCod'] = (string) $op->CLI2COD;
        }
        if ($point && $cfg->splitPoint && $op->PUM2COD !== null) {
            $line['point'] = (int) $op->PUM2COD;
        }

        return $line;
    }

    private static function serviceLine(string $key, $del, $cod, $name, $price, $discount, object $op, object $cfg, object $state): array
    {
        $line = self::baseLine('S', $key, $op, $cfg);
        $line['del'] = (string) $del;
        $line['cod'] = (string) $cod;
        $line['desc'] = (string) $name;
        $line['price'] = VeolabOperationServices::decimal($price);
        $line['discount'] = (string) ($discount ?? '');
        $line['date'] = self::dateValue($state->date);
        $line['ref'] = $state->ref;
        $line['extra'] = $state->extra;

        $section = DB::connection('dynamic')->table('LABSYT')
            ->leftJoin('LABTEC', function ($join) {
                $join->on('LABSYT.DEL3TEC', '=', 'LABTEC.DEL3COD')->on('LABSYT.TEC3COD', '=', 'LABTEC.TEC1COD');
            })
            ->where('LABSYT.DEL3SER', (string) $del)->where('LABSYT.SER3COD', (string) $cod)
            ->orderBy('LABSYT.SYTNORD')->first(['LABTEC.SEC2DEL', 'LABTEC.SEC2COD']);
        $line['secDel'] = (string) ($section->SEC2DEL ?? '');
        $line['secCod'] = (int) ($section->SEC2COD ?? 0);

        return $line;
    }

    private static function techniqueLine(string $key, object $t, object $op, object $cfg, object $ctx): array
    {
        $line = self::baseLine('T', $key, $op, $cfg);
        $line['del'] = (string) $t->TEC3DEL;
        $line['cod'] = (string) $t->TEC3COD;
        $line['desc'] = VeolabBillingLines::markedName((string) $t->RESCNOI, (string) ($t->RESDACR ?? ''), $ctx);
        $line['price'] = VeolabOperationServices::decimal($t->RESNPRE);
        $line['discount'] = (string) ($t->RESCDTO ?? '');

        $section = DB::connection('dynamic')->table('LABTEC')
            ->where('DEL3COD', (string) $t->TEC3DEL)->where('TEC1COD', (string) $t->TEC3COD)->first(['SEC2DEL', 'SEC2COD']);
        $line['secDel'] = (string) ($section->SEC2DEL ?? '');
        $line['secCod'] = (int) ($section->SEC2COD ?? 0);

        return $line;
    }

    private static function expenseLine(string $key, object $e, object $op, object $cfg, bool $point = true): array
    {
        $line = self::baseLine('G', $key, $op, $cfg, $point);
        $line['del'] = (string) $e->ESC3DEL;
        $line['cod'] = (string) $e->ESC3COD;
        $line['desc'] = (string) $e->ESCCDES;
        $line['price'] = VeolabOperationServices::decimal($e->OYGNPRE);
        $line['discount'] = (string) ($e->OYGCDTO ?? '');

        return $line;
    }

    // ------------------------------------------------------------------
    // Rejilla (posiciones como las de la rejilla de Veolab)
    // ------------------------------------------------------------------

    /** Suma uno a la cantidad de la línea con esa clave; false si no existe. */
    private static function increment(array &$grid, string $key): bool
    {
        foreach ($grid as $i => $row) {
            if ($row['key'] !== '' && $row['key'] === $key) {
                $grid[$i]['qty'] += 1;

                return true;
            }
        }

        return false;
    }

    /** AddRow: en la posición indicada (1 = primera) o al final (0 o fuera de rango). */
    private static function insert(array &$grid, array $line, int $position): void
    {
        if ($position <= 0 || $position > count($grid)) {
            $grid[] = $line;
        } else {
            array_splice($grid, $position - 1, 0, [$line]);
        }
    }

    /** RowIndex(clave) + RowChildCount + 1: la posición tras las líneas del grupo. */
    private static function endOfGroup(array $grid, ?string $key): int
    {
        if ($key === null) {
            return 0;
        }
        foreach ($grid as $i => $row) {
            if ($row['key'] === $key) {
                $end = $i + 1;
                while (isset($grid[$end]) && ! in_array($grid[$end]['type'], ['S', 'O', 'L', 'E', 'A', 'U'], true)) {
                    $end++;
                }

                return $end + 1;
            }
        }

        return 0;
    }

    /**
     * FAC_ObtenClaveLinea: los datos de la línea más, si se desglosan, el
     * cliente y el punto de muestreo. $full: ya lleva los 19 componentes.
     */
    private static function key(array $parts, object $op, object $cfg, bool $full = false): string
    {
        if (! $full) {
            $parts = array_merge($parts, array_fill(0, 10, ''));
        }
        $parts[] = $cfg->manyClients ? $op->CLI2DEL : '';
        $parts[] = $cfg->manyClients ? $op->CLI2COD : '';
        $parts[] = $cfg->manyPoints ? (string) $op->PUM2COD : '';

        return implode("\x1B", array_map(fn ($p) => (string) $p, $parts));
    }

    // ------------------------------------------------------------------
    // Datos
    // ------------------------------------------------------------------

    /** Operaciones con su cliente, punto y datos para las columnas, por clave. */
    private static function operations(array $operations): array
    {
        $rows = DB::connection('dynamic')->table('LABOPE')
            ->where(function ($q) use ($operations) {
                foreach ($operations as [$del, $ser, $cod]) {
                    $q->orWhere(fn ($w) => $w->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod));
                }
            })
            ->get(['DEL3COD', 'OPE1SER', 'OPE1COD', 'OPECINF', 'OPENPRE', 'OPECDTO', 'OPECREF', 'OPECTID', 'OPECDES',
                'OPEDREG', 'OPETREC', 'OPETREP', 'OPEDPRE', 'OPEDINI', 'OPEDFIN', 'OPEDVAL', 'OPEDINF', 'OPEDENV',
                'CLI2DEL', 'CLI2COD', 'PUM2COD', 'CON2DEL', 'CON2SER', 'CON2COD', 'PRE2DEL', 'PRE2SER', 'PRE2COD',
                'MAT2DEL', 'MAT2COD']);

        $out = [];
        foreach ($rows as $row) {
            $row->CLI2DEL = (string) $row->CLI2DEL;
            $row->CLI2COD = (string) $row->CLI2COD;
            $out[self::opKey($row->DEL3COD, $row->OPE1SER, $row->OPE1COD)] = $row;
        }

        return $out;
    }

    private static function expenses(object $op)
    {
        return DB::connection('dynamic')->table('LABOYG')
            ->leftJoin('LABESC', function ($join) {
                $join->on('LABOYG.ESC3DEL', '=', 'LABESC.DEL3COD')->on('LABOYG.ESC3COD', '=', 'LABESC.ESC1COD');
            })
            ->where(self::whereOp($op, 'LABOYG.OPE3'))
            ->orderBy('LABOYG.OYGNPOS')
            ->select(['LABOYG.ESC3DEL', 'LABOYG.ESC3COD', 'LABOYG.OYGNPRE', 'LABOYG.OYGCDTO', 'LABOYG.OYGNPOS',
                'LABOYG.SER2DEL', 'LABOYG.SER2COD', 'LABESC.ESCCDES']);
    }

    private static function whereOp(object $op, string $prefix): array
    {
        return ["{$prefix}DEL" => $op->DEL3COD, "{$prefix}SER" => $op->OPE1SER, "{$prefix}COD" => $op->OPE1COD];
    }

    private static function opKey($del, $ser, $cod): string
    {
        return $del."\x1B".$ser."\x1B".(int) $cod;
    }

    /**
     * FAC_ObtenCamposColumnasConfigurados: fecha, referencia y adicional de
     * la línea según LABCON (CONCMOF, CONCMOR, CONCMOA).
     */
    private static function columns(bool $isOperation, string $serDel, string $serCod, object $op, ?object $report, object $cfg): array
    {
        $dates = ['OPEDREG', 'OPETREC', 'OPEDPRE', 'OPEDINI', 'OPEDFIN', 'OPEDVAL', 'OPEDINF'];
        $date = match (true) {
            $cfg->dateField === ''          => '',
            in_array($cfg->dateField, $dates, true) => (string) $op->{$cfg->dateField},
            $cfg->dateField === 'OPEDENV'   => (string) $op->OPEDINI,   // Veolab toma aquí la de inicio
            default                         => (string) $op->OPETREP,
        };
        $date = $date === '' ? '' : substr($date, 0, 10);

        $opCode = VeolabCodes::format('LABOPE', (string) $op->OPE1COD, (string) $op->DEL3COD, (string) $op->OPE1SER, (string) $op->OPECINF);
        $reportCode = $report ? VeolabCodes::format('LABINF', (string) $report->INF3COD, (string) $report->INF3DEL, (string) $report->INF3SER) : '';

        $ref = match ($cfg->refField) {
            ''        => '',
            'OPECREF' => (string) $op->OPECREF,
            'INF1COD' => $reportCode,
            'OPE1COD' => $opCode,
            default   => $isOperation ? $opCode : ($serCod !== '' ? VeolabCodes::format('LABSER', $serCod, $serDel) : ''),
        };

        $extra = match ($cfg->extraField) {
            ''        => '',
            'CON2COD' => (int) $op->CON2COD > 0 ? VeolabCodes::format('FACCON', (string) $op->CON2COD, (string) $op->CON2DEL, (string) $op->CON2SER) : '',
            'PRE2COD' => (int) $op->PRE2COD > 0 ? VeolabCodes::format('FACPRE', (string) $op->PRE2COD, (string) $op->PRE2DEL, (string) $op->PRE2SER) : '',
            'OPECREF' => (string) $op->OPECREF,
            'MAT2COD' => (string) DB::connection('dynamic')->table('LABMAT')
                ->where('DEL3COD', (string) $op->MAT2DEL)->where('MAT1COD', (int) $op->MAT2COD)->value('MATCDES'),
            'INF1COD' => $reportCode,
            'OPE1COD' => $opCode,
            default   => self::customFieldValue($op, $cfg->extraField),
        };

        return [$date, mb_substr($ref, 0, 50), mb_substr($extra, 0, 50)];
    }

    /** CON_ObtenValorAutodefinibleOperacion: CONCMOA = "delegación\x1Bcódigo" del autodefinible. */
    private static function customFieldValue(object $op, string $field): string
    {
        [$del, $cod] = array_pad(explode("\x1B", $field, 2), 2, '');

        return mb_substr((string) DB::connection('dynamic')->table('LABOYA')
            ->where('OPE3DEL', $op->DEL3COD)->where('OPE3SER', $op->OPE1SER)->where('OPE3COD', $op->OPE1COD)
            ->where('AUT3DEL', $del)->where('AUT3COD', (int) $cod)
            ->value('OYACVAL'), 0, 50);
    }

    private static function dateValue(string $date): ?string
    {
        return $date === '' ? null : $date.' 00:00:00';
    }
}
