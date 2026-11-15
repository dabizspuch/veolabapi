<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Existencias de almacén (Almacen.bas). Trabaja sobre la conexión 'dynamic'
 * y debe llamarse dentro de la transacción del cambio.
 */
class VeolabStock
{
    public const MOV_INICIAL = 'I';
    public const MOV_AJUSTE = 'J';
    public const MOV_BAJA = 'B';
    public const MOV_CONSUMO = 'O';
    public const MOV_USO = 'U';
    public const MOV_PRESTAMO = 'P';
    public const MOV_DEVOLUCION = 'D';

    /**
     * Veolab 3: código de la serie oculta de los productos que llevan las
     * existencias sin series ni lotes (ALM_SERIE_UNICA). Reservado: no se
     * puede dar a una serie nueva.
     */
    public const SERIE_UNICA = '-';

    /** Tipos de movimiento (ALMMOV.MOVCTIP). */
    public const MOVEMENT_TYPES = ['I', 'E', 'S', 'C', 'D', 'P', 'B', 'O', 'U', 'J', 'A'];

    /**
     * ALM_GenerarMovimiento: movimiento de la serie/lote en la delegación del
     * producto, sin operación, técnica ni usuario. Devuelve su código.
     */
    public static function movement(string $delegation, string $product, string $lot, float $quantity, string $type, ?string $date = null): int
    {
        $db = DB::connection('dynamic');
        do {
            $code = VeolabCodes::next('ALMMOV', '', $delegation);
        } while ($db->table('ALMMOV')->where('DEL3COD', $delegation)->where('MOV1COD', $code)->exists());

        $db->table('ALMMOV')->insert([
            'DEL3COD' => $delegation,
            'MOV1COD' => $code,
            'MOVCTIP' => $type,
            'MOVDFEC' => $date ?? DB::raw('NOW()'),
            'MOVNCAN' => $quantity,
            'PRD2DEL' => $delegation,
            'PRD2COD' => $product,
            'SEL2COD' => $lot,
        ]);

        return $code;
    }

    /**
     * ALM_ActualizarExistenciasSerieLote con incremento: suma $delta a la
     * cantidad en existencias y recalcula las unidades; nunca queda negativa.
     */
    public static function addToLot(string $delegation, string $product, string $lot, float $delta): void
    {
        $row = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)
            ->lockForUpdate()->first(['SELNCAU', 'SELNCAE']);
        if (! $row) {
            return;
        }

        $quantity = (float) $row->SELNCAE + $delta;
        DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)
            ->update($quantity > 0
                ? ['SELNCAE' => $quantity, 'SELNUNE' => self::lotUnits((float) $row->SELNCAU, $quantity)]
                : ['SELNCAE' => 0, 'SELNUNE' => 0]);
    }

    /** UnidadesSerie (Veolab 3): sin cantidad por unidad, las unidades son la cantidad; en 2.4.5, como siempre. */
    public static function lotUnits(float $perUnit, float $quantity): float
    {
        return $perUnit == 0.0 && VeolabVersion::isV3() ? $quantity : self::unitsFromQuantity($perUnit, $quantity);
    }

    /** ALM_CalcularCantidadDeUnidades: cantidad por unidad × unidades (4 decimales). */
    public static function quantityFromUnits(float $perUnit, float $units): float
    {
        return round($perUnit * $units, 4);
    }

    /**
     * ALM_CancelarExistenciasOperaciones: devuelve a cada serie/lote la
     * cantidad consumida por la operación (movimientos de consumo) y
     * recalcula las existencias de los productos afectados. No borra los
     * movimientos: eso lo hace quien borra la operación.
     */
    public static function cancelOperationConsumptions(string $delegation, string $series, int $code): void
    {
        $consumptions = DB::connection('dynamic')->table('ALMMOV')
            ->select('PRD2DEL', 'PRD2COD', 'SEL2COD', DB::raw('SUM(MOVNCAN) AS CANTIDAD'))
            ->where('OPE2DEL', $delegation)
            ->where('OPE2SER', $series)
            ->where('OPE2COD', $code)
            ->where('MOVCTIP', self::MOV_CONSUMO)
            ->where('SEL2COD', '<>', '')
            ->whereNotNull('SEL2COD')
            ->groupBy('PRD2DEL', 'PRD2COD', 'SEL2COD')
            ->get();

        $products = [];
        foreach ($consumptions as $row) {
            $lot = DB::connection('dynamic')->table('ALMSEL')
                ->where('PRD3DEL', $row->PRD2DEL)
                ->where('PRD3COD', $row->PRD2COD)
                ->where('SEL1COD', $row->SEL2COD)
                ->lockForUpdate()
                ->first(['SELNCAU', 'SELNCAE']);

            if ($lot) {
                // ALM_ActualizarExistenciasSerieLotePospuesto: suma y nunca negativo.
                $quantity = (float) $row->CANTIDAD + (float) $lot->SELNCAE;
                $units = $quantity > 0 ? self::unitsFromQuantity((float) $lot->SELNCAU, $quantity) : 0;

                DB::connection('dynamic')->table('ALMSEL')
                    ->where('PRD3DEL', $row->PRD2DEL)
                    ->where('PRD3COD', $row->PRD2COD)
                    ->where('SEL1COD', $row->SEL2COD)
                    ->update(['SELNCAE' => max($quantity, 0), 'SELNUNE' => $units]);
            }

            $products[$row->PRD2DEL."\x1B".$row->PRD2COD] = [$row->PRD2DEL, $row->PRD2COD];
        }

        self::recalculateProducts(array_values($products));
    }

    /**
     * Movimientos y existencias de un préstamo (FichaPrestamo.Grabar): devuelve
     * a cada serie/lote lo que el préstamo tenía fuera (ALM_ObtenPrestamosSerieLote:
     * movimientos P y D), borra sus movimientos y, si está entregado o devuelto
     * en todo o en parte ($active), genera uno de préstamo (+prestada) y otro de
     * devolución (−devuelta) por línea y descuenta lo no devuelto. Si alguna
     * serie/lote se queda sin existencias suficientes lanza la excepción con
     * la lista (como Veolab, que no graba). Con $active = false es la anulación
     * del borrado (ALM_CancelarExistenciasPrestamos).
     *
     * $lines: [[producto_delegacion, producto_codigo, serie_lote_codigo, prestada, devuelta]].
     */
    public static function syncLoan(string $delegation, int $code, array $lines, bool $active): void
    {
        $db = DB::connection('dynamic');

        // Lo que estaba fuera vuelve a las existencias.
        $deltas = [];
        $previous = $db->table('ALMMOV')
            ->select('PRD2DEL', 'PRD2COD', 'SEL2COD', DB::raw('SUM(MOVNCAN) AS CANTIDAD'))
            ->where('PRE2DEL', $delegation)->where('PRE2COD', $code)
            ->whereIn('MOVCTIP', [self::MOV_PRESTAMO, self::MOV_DEVOLUCION])
            ->where('SEL2COD', '<>', '')->whereNotNull('SEL2COD')
            ->groupBy('PRD2DEL', 'PRD2COD', 'SEL2COD')
            ->get();
        foreach ($previous as $row) {
            $deltas[$row->PRD2DEL."\x1B".$row->PRD2COD."\x1B".$row->SEL2COD] = [$row->PRD2DEL, $row->PRD2COD, $row->SEL2COD, (float) $row->CANTIDAD, false];
        }

        $db->table('ALMMOV')->where('PRE2DEL', $delegation)->where('PRE2COD', $code)->delete();

        if ($active) {
            $now = $db->selectOne('SELECT NOW() AS n')->n;
            foreach ($lines as [$productDelegation, $product, $lot, $lent, $returned]) {
                foreach ([[self::MOV_PRESTAMO, $lent], [self::MOV_DEVOLUCION, -$returned]] as [$type, $quantity]) {
                    if ($quantity == 0) {
                        continue;
                    }
                    do {
                        $movement = VeolabCodes::next('ALMMOV', '', $delegation);
                    } while ($db->table('ALMMOV')->where('DEL3COD', $delegation)->where('MOV1COD', $movement)->exists());
                    $db->table('ALMMOV')->insert([
                        'DEL3COD' => $delegation,
                        'MOV1COD' => $movement,
                        'MOVCTIP' => $type,
                        'MOVDFEC' => $now,
                        'MOVNCAN' => $quantity,
                        'PRD2DEL' => $productDelegation,
                        'PRD2COD' => $product,
                        'SEL2COD' => $lot,
                        'PRE2DEL' => $delegation,
                        'PRE2COD' => $code,
                        'USU2DEL' => '',
                        'USU2COD' => '',
                    ]);
                }

                $key = $productDelegation."\x1B".$product."\x1B".$lot;
                $deltas[$key] ??= [$productDelegation, $product, $lot, 0.0, false];
                $deltas[$key][3] -= $lent - $returned;
                $deltas[$key][4] = true;
            }
        }

        $exceeded = [];
        $products = [];
        foreach ($deltas as [$productDelegation, $product, $lot, $delta, $checked]) {
            $row = $db->table('ALMSEL')
                ->where('PRD3DEL', $productDelegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)
                ->lockForUpdate()->first(['SELNCAU', 'SELNCAE']);
            $quantity = ($row ? (float) $row->SELNCAE : 0.0) + $delta;
            if ($quantity < -1e-9 && $checked) {
                $exceeded[] = "{$product} - {$lot}";
            }
            if ($row) {
                $db->table('ALMSEL')
                    ->where('PRD3DEL', $productDelegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)
                    ->update($quantity > 0
                        ? ['SELNCAE' => $quantity, 'SELNUNE' => self::lotUnits((float) $row->SELNCAU, $quantity)]
                        : ['SELNCAE' => 0, 'SELNUNE' => 0]);
            }
            $products[$productDelegation."\x1B".$product] = [$productDelegation, $product];
        }

        if ($exceeded) {
            throw new BusinessRuleException('La cantidad prestada supera las existencias de: '.implode(', ', $exceeded));
        }

        self::recalculateProducts(array_values($products));
    }

    /**
     * ALM_RecalcularExistenciasProductos: existencias del producto = suma de
     * sus series/lotes que no están de baja. En Veolab 3 una serie sin estado
     * cuenta como activa y un producto sin ninguna serie conserva las
     * existencias que tenga grabadas; en Veolab 2.4.5 solo SELCESA <> 'B'
     * (sin estado no cuenta) y sin series queda a 0.
     */
    public static function recalculateProducts(array $products): void
    {
        $v3 = VeolabVersion::isV3();
        $active = $v3 ? "(s.SELCESA <> 'B' OR s.SELCESA IS NULL)" : "s.SELCESA <> 'B'";
        $withLots = $v3 ? 'AND EXISTS (SELECT 1 FROM ALMSEL e WHERE e.PRD3DEL = ALMPRD.DEL3COD AND e.PRD3COD = ALMPRD.PRD1COD)' : '';
        foreach ($products as [$delegation, $code]) {
            DB::connection('dynamic')->update(
                'UPDATE ALMPRD SET '
                ."PRDNEXI = COALESCE((SELECT SUM(SELNUNE) FROM ALMSEL s WHERE s.PRD3DEL = ALMPRD.DEL3COD AND s.PRD3COD = ALMPRD.PRD1COD AND {$active}), 0), "
                ."PRDNCAE = COALESCE((SELECT SUM(SELNCAE) FROM ALMSEL s WHERE s.PRD3DEL = ALMPRD.DEL3COD AND s.PRD3COD = ALMPRD.PRD1COD AND {$active}), 0) "
                ."WHERE DEL3COD = ? AND PRD1COD = ? {$withLots}",
                [$delegation, $code]
            );
        }

        self::notifyMinimumStock($products);
    }

    /** Tipo de notificación de stock mínimo (COM_NOTIF_STOCK). */
    public const STOCK_NOTIFICATION = 'S';

    /**
     * Tras recalcular (ALM_RecalcularExistenciasProductos, también en 2.4.5):
     * con el módulo COM y LABCON.CONBSTM, los productos por debajo del stock
     * mínimo (PRDNCAE < PRDNSMI) rehacen su notificación S para los usuarios
     * activos de tipo empleado con escritura en ALM_INV
     * (COM_CrearNotificacionesStockMinimo).
     */
    private static function notifyMinimumStock(array $products): void
    {
        if (! $products) {
            return;
        }
        $db = DB::connection('dynamic');
        if (($db->table('LABCON')->where('CON1COD', 1)->value('CONBSTM') ?? '') !== 'T'
            || ! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'COM')) {
            return;
        }
        $below = [];
        foreach ($products as [$delegation, $code]) {
            $row = $db->table('ALMPRD')->where('DEL3COD', $delegation)->where('PRD1COD', $code)->first(['PRDNCAE', 'PRDNSMI']);
            if ($row && (float) $row->PRDNCAE < (float) $row->PRDNSMI) {
                $below[] = [(string) $delegation, (string) $code];
            }
        }
        if (! $below) {
            return;
        }

        $users = $db->table('ACCUSU')
            ->leftJoin('ACCPYF', fn ($j) => $j->on('ACCUSU.PER2DEL', '=', 'ACCPYF.DEL3COD')->on('ACCUSU.PER2COD', '=', 'ACCPYF.PER3COD'))
            ->where('ACCPYF.FUN3COD', 'ALM_INV')->whereRaw('MOD(ACCPYF.PYFNACC, 4) = 3')->where('ACCUSU.USUNTIP', 0)
            ->where(fn ($q) => $q->whereNull('ACCUSU.USUBBAJ')->orWhere('ACCUSU.USUBBAJ', '<>', 'T'))
            ->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD']);
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;

        foreach ($below as [$delegation, $code]) {
            // Las anteriores del producto, con sus avisos
            $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
                .'WHERE ACCAVI.AVICTIP = ? AND ACCNOT.NOTCTIP = ? AND ACCNOT.PRD2DEL = ? AND ACCNOT.PRD2COD = ?',
                ['N', self::STOCK_NOTIFICATION, $delegation, $code]);
            $db->table('ACCNOT')->where('NOTCTIP', self::STOCK_NOTIFICATION)->where('PRD2DEL', $delegation)->where('PRD2COD', $code)->delete();

            foreach ($users as $user) {
                [$userDel, $userCod] = [(string) $user->DEL3COD, (string) $user->USU1COD];
                $notification = VeolabCodes::next('ACCNOT', '', $userDel);
                $db->table('ACCNOT')->insert([
                    'DEL3COD' => $userDel, 'NOT1COD' => $notification, 'NOTTFEC' => $now, 'NOTCTIP' => self::STOCK_NOTIFICATION,
                    'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'PRD2DEL' => $delegation, 'PRD2COD' => $code,
                ]);
                $db->table('ACCAVI')->insert([
                    'DEL3COD' => $userDel, 'AVI1COD' => VeolabCodes::next('ACCAVI', '', $userDel), 'AVITFEC' => $now,
                    'AVICTIP' => 'N', 'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'NOT2DEL' => $userDel, 'NOT2COD' => $notification,
                ]);
            }
        }
    }

    /** Veolab 3: inventario básico (LABCON.CONCINV = 'B'): todos los productos sin series. */
    public static function basicMode(): bool
    {
        return VeolabVersion::isV3()
            && DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONCINV') === 'B';
    }

    /**
     * Valor de PRDBSEL para un producto nuevo: sin series ('F') en el modo
     * básico o si la configuración lo indica (LABCON.CONBCSN = 'F').
     */
    public static function newProductSeriesFlag(): string
    {
        if (self::basicMode()) {
            return 'F';
        }

        return DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONBCSN') === 'F' ? 'F' : 'T';
    }

    /**
     * ALM_ProductoSinSeries: ¿lleva el producto las existencias sin series o
     * lotes? Todos en el modo básico; en el completo, los marcados con
     * PRDBSEL = 'F'. Siempre false en bases anteriores a Veolab 3.
     */
    public static function productWithoutSeries(string $delegation, string $product): bool
    {
        if (! VeolabVersion::isV3()) {
            return false;
        }
        if (self::basicMode()) {
            return true;
        }

        return DB::connection('dynamic')->table('ALMPRD')
            ->where('DEL3COD', $delegation)->where('PRD1COD', $product)->value('PRDBSEL') === 'F';
    }

    /** ALM_ContarSeriesActivas: series/lotes del producto que no están de baja. */
    public static function activeLots(string $delegation, string $product): int
    {
        return DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $product)
            ->where(fn ($q) => $q->where('SELCESA', '<>', 'B')->orWhereNull('SELCESA'))
            ->count();
    }

    /**
     * ALM_ObtenerSerieUnica: serie en la que lleva las existencias un producto
     * sin series (la activa más reciente) o, si no tiene ninguna, la oculta
     * nueva con las existencias que tuviera el producto.
     */
    public static function singleLot(string $delegation, string $product): string
    {
        $lot = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $product)
            ->where(fn ($q) => $q->where('SELCESA', '<>', 'B')->orWhereNull('SELCESA'))
            ->orderByDesc('SELDALT')->value('SEL1COD');
        if ($lot !== null) {
            return (string) $lot;
        }

        $lot = self::createSingleLot($delegation, $product);
        self::recalculateProducts([[$delegation, $product]]);

        return $lot;
    }

    /**
     * Existencias de un producto sin series cambiadas en su ficha
     * (FichaProducto.ActualizarSerieUnica): crea la serie oculta si no tiene
     * ninguna o ajusta la suya con un movimiento de ajuste.
     */
    public static function updateSingleLot(string $delegation, string $product, ?float $quantity, ?float $units): void
    {
        if (self::activeLots($delegation, $product) === 0) {
            self::singleLot($delegation, $product);   // se crea con las existencias del producto

            return;
        }
        if ($quantity === null && $units === null) {
            return;
        }

        $lot = self::singleLot($delegation, $product);
        $row = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)
            ->lockForUpdate()->first(['SELNCAU', 'SELNCAE']);
        $previous = $row ? (float) $row->SELNCAE : 0.0;
        $perUnit = $row ? (float) $row->SELNCAU : 0.0;

        // La cantidad manda; con solo unidades, la cantidad sale de la cantidad por unidad de la serie.
        $new = ($quantity !== null || $perUnit <= 0) ? (float) ($quantity ?? $units) : $units * $perUnit;
        if (abs($new - $previous) > 1e-9) {
            DB::connection('dynamic')->table('ALMSEL')
                ->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)
                ->update($new > 0
                    ? ['SELNCAE' => $new, 'SELNUNE' => self::lotUnits($perUnit, $new)]
                    : ['SELNCAE' => 0, 'SELNUNE' => 0]);
            self::movement($delegation, $product, $lot, $new - $previous, self::MOV_AJUSTE);
        }
        self::recalculateProducts([[$delegation, $product]]);
    }

    /**
     * CrearSerieUnica: crea (o reactiva si estaba de baja) la serie oculta del
     * producto con un movimiento inicial (o de ajuste) por las existencias
     * que tuviera el producto. Devuelve su código.
     */
    private static function createSingleLot(string $delegation, string $product): string
    {
        $db = DB::connection('dynamic');
        $row = $db->table('ALMPRD')->where('DEL3COD', $delegation)->where('PRD1COD', $product)->first(['PRDNEXI', 'PRDNCAE']);
        $units = $row ? (float) $row->PRDNEXI : 0.0;
        $quantity = $row ? max((float) $row->PRDNCAE, 0.0) : 0.0;
        if ($units > 0 && $quantity == 0.0) {
            // CrearSerieUnica: sin cantidad, una por unidad, para que las unidades no se pierdan al recalcular
            $perUnit = 1.0;
            $quantity = $units;
        } elseif ($units > 0) {
            $perUnit = $quantity / $units;
        } else {
            $perUnit = 1.0;
            $units = $quantity;
        }

        $exists = $db->table('ALMSEL')->where('PRD3DEL', $delegation)->where('PRD3COD', $product)
            ->where('SEL1COD', self::SERIE_UNICA)->exists();
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        if ($exists) {
            $db->table('ALMSEL')->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL1COD', self::SERIE_UNICA)
                ->update(['SELCESA' => 'U', 'SELDBAJ' => null, 'SELNCAU' => $perUnit, 'SELNUNE' => $units, 'SELNCAE' => $quantity]);
        } else {
            $db->table('ALMSEL')->insert([
                'PRD3DEL' => $delegation, 'PRD3COD' => $product, 'SEL1COD' => self::SERIE_UNICA, 'SELCDES' => '',
                'SELCESA' => 'U', 'SELDALT' => $now, 'SELNCAU' => $perUnit, 'SELNUNL' => 0, 'SELNUNE' => $units, 'SELNCAE' => $quantity,
            ]);
        }
        self::movement($delegation, $product, self::SERIE_UNICA, $quantity, $exists ? self::MOV_AJUSTE : self::MOV_INICIAL, $now);

        return self::SERIE_UNICA;
    }

    /**
     * ALM_CalcularUnidadesDeCantidad: unidades enteras de la cantidad, más
     * una si no es divisible (unidad empezada). 0 si falta algún dato.
     */
    public static function unitsFromQuantity(float $perUnit, float $quantity): float
    {
        if ($perUnit == 0.0 || $quantity == 0.0) {
            return 0;
        }

        $whole = floor($quantity / $perUnit);
        $remainder = abs($quantity - $whole * $perUnit) > 1e-9 ? 1 : 0;

        return round($whole, 4) + $remainder;
    }

    /**
     * Veolab 3: fecha de la serie que mueve cada tipo de operación reservado
     * (delegación común) calibración, verificación y mantenimiento
     * (ALM_CampoFechaTipo). Aunque se renombren, no cambian de significado.
     */
    public const METROLOGY_DATES = [2 => 'SELDCAL', 3 => 'SELDVER', 4 => 'SELDMAN'];

    /**
     * Veolab 3 (ALM_SincronizarFechasSerie): próxima calibración, verificación
     * y mantenimiento de la serie según su planificación de cada tipo: la
     * primera fecha pendiente o la de una operación ya generada que aún no se
     * ha finalizado (ni anulado). Sin planificación del tipo, la fecha sigue
     * siendo la que se ponga a mano. A diferencia del escritorio no calcula la
     * fecha teórica cuando ya no queda ninguna generada: la completa Veolab al
     * abrir Inventario.
     */
    public static function syncMetrologyDates(string $delegation, string $product, string $lot): void
    {
        if ($product === '' || $lot === '' || ! VeolabVersion::isV3()) {
            return;
        }

        $db = DB::connection('dynamic');
        foreach (self::METROLOGY_DATES as $type => $column) {
            $plans = fn ($q) => $q->where('LABPLO.PRD2DEL', $delegation)->where('LABPLO.PRD2COD', $product)
                ->where('LABPLO.SEL2COD', $lot)->where('LABPLO.TIO2DEL', '')->where('LABPLO.TIO2COD', $type);
            if (! $db->table('LABPLO')->where($plans)->exists()) {
                continue;
            }

            $dates = fn () => $db->table('LABFEP')
                ->join('LABPLO', fn ($j) => $j->on('LABFEP.PLO3DEL', '=', 'LABPLO.DEL3COD')->on('LABFEP.PLO3COD', '=', 'LABPLO.PLO1COD'))
                ->where($plans)->whereNotNull('LABFEP.FEPTINI');

            // Pendientes (las que no tienen FEPTINI son restos de un cambio de periodicidad)
            $pending = $dates()->where(fn ($q) => $q->whereNull('LABFEP.FEPBCOM')->orWhere('LABFEP.FEPBCOM', '<>', 'T'))
                ->min('LABFEP.FEPTINI');

            // Generadas cuya operación no se ha finalizado: la calibración no está hecha hasta que se finaliza
            $generated = $dates()
                ->join('LABOPE', fn ($j) => $j->on('LABOPE.PLO2DEL', '=', 'LABFEP.PLO3DEL')->on('LABOPE.PLO2COD', '=', 'LABFEP.PLO3COD')
                    ->on('LABOPE.FEP2COD', '=', 'LABFEP.FEP1COD'))
                ->whereNull('LABOPE.OPEDFIN')
                ->where(fn ($q) => $q->whereNull('LABOPE.OPEBANU')->orWhere('LABOPE.OPEBANU', '<>', 'T'))
                ->min('LABFEP.FEPTINI');

            $next = array_filter([$pending, $generated], fn ($d) => $d !== null);
            if (! $next) {
                continue;
            }
            $date = substr((string) min($next), 0, 10);

            $db->table('ALMSEL')->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)
                ->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<>', $date))
                ->update([$column => $date]);
        }
    }

    /** Fechas de la serie de una operación de calibración, verificación o mantenimiento. */
    public static function syncOperationMetrology(?object $operation): void
    {
        if ($operation && (string) $operation->TIO2DEL === '' && isset(self::METROLOGY_DATES[(int) $operation->TIO2COD])) {
            self::syncMetrologyDates((string) $operation->PRD2DEL, (string) $operation->PRD2COD, (string) $operation->SEL2COD);
        }
    }

    /** Fila de la operación con lo que necesita syncOperationMetrology. */
    public static function operationForMetrology(string $delegation, string $series, int $code): ?object
    {
        return DB::connection('dynamic')->table('LABOPE')
            ->where('DEL3COD', $delegation)->where('OPE1SER', $series)->where('OPE1COD', $code)
            ->first(['TIO2DEL', 'TIO2COD', 'PRD2DEL', 'PRD2COD', 'SEL2COD']);
    }

    /**
     * Veolab 3, LABCON.CONCVEN (Configurar inventario): qué hacer con los
     * equipos vencidos o fuera de uso. Vacío (o nulo) bloquear, el
     * predeterminado de las bases nuevas; A avisar; N no comprobar, el de las
     * bases convertidas. Como el escritorio, cualquier otro valor avisa.
     */
    public const VENCIDOS_BLOQUEAR = '';
    public const VENCIDOS_AVISAR = 'A';
    public const VENCIDOS_NO_COMPROBAR = 'N';

    /**
     * Modo de LABCON.CONCVEN, o null si no se comprueba: bases anteriores a
     * Veolab 3, sin el módulo de almacén (ALM) o con «No comprobar».
     */
    public static function expiredEquipmentMode(): ?string
    {
        if (! VeolabVersion::isV3()) {
            return null;
        }
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'ALM')) {
            return null;
        }
        $mode = (string) $db->table('LABCON')->where('CON1COD', 1)->value('CONCVEN');

        return $mode === self::VENCIDOS_NO_COMPROBAR ? null : $mode;
    }

    /**
     * ALM_MotivoEquipoVencido: por qué no se puede usar una serie de equipo
     * en una fecha (aaaa-mm-dd): fuera de uso (F), de baja (B) o con la
     * próxima calibración, verificación, mantenimiento o la caducidad ya
     * alcanzadas (el mismo día cuenta). Vacío si se puede usar.
     */
    public static function expiredReason($state, $calibration, $verification, $maintenance, $expiry, string $date): string
    {
        $reasons = [];
        $state = (string) $state;
        if ($state === 'F') {
            $reasons[] = 'Fuera de uso';
        } elseif ($state === 'B') {
            $reasons[] = 'Baja definitiva';
        }
        $day = substr($date, 0, 10);
        $dates = ['próxima calibración' => $calibration, 'próxima verificación' => $verification,
            'próximo mantenimiento' => $maintenance, 'fecha de caducidad' => $expiry];
        foreach ($dates as $label => $value) {
            $value = substr((string) $value, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && $value !== '0000-00-00' && $value <= $day) {
                $reasons[] = $label.' '.substr($value, 8, 2).'/'.substr($value, 5, 2).'/'.substr($value, 0, 4);
            }
        }

        return implode(', ', $reasons);
    }

    /**
     * LeerEquiposVencidos (FichaResultados): equipos registrados como usos
     * (ALMMOV tipo U, productos con PRDBEQU = T) en las técnicas de la
     * operación que estaban vencidos o fuera de uso en la fecha indicada.
     * Devuelve técnica ("delegación\x1Bcódigo") => textos «Equipo (serie):
     * motivo», en el orden del escritorio (por descripción del producto).
     */
    public static function expiredEquipment(string $delegation, string $series, int $code, string $date): array
    {
        $uses = DB::connection('dynamic')->table('ALMMOV')
            ->join('ALMSEL', fn ($j) => $j->on('ALMSEL.PRD3DEL', '=', 'ALMMOV.PRD2DEL')->on('ALMSEL.PRD3COD', '=', 'ALMMOV.PRD2COD')
                ->on('ALMSEL.SEL1COD', '=', 'ALMMOV.SEL2COD'))
            ->join('ALMPRD', fn ($j) => $j->on('ALMPRD.DEL3COD', '=', 'ALMMOV.PRD2DEL')->on('ALMPRD.PRD1COD', '=', 'ALMMOV.PRD2COD'))
            ->where('ALMMOV.MOVCTIP', self::MOV_USO)->where('ALMPRD.PRDBEQU', 'T')
            ->where('ALMMOV.OPE2DEL', $delegation)->where('ALMMOV.OPE2SER', $series)->where('ALMMOV.OPE2COD', $code)
            ->orderBy('ALMMOV.TEC2DEL')->orderBy('ALMMOV.TEC2COD')->orderBy('ALMPRD.PRDCDES')
            ->get(['ALMMOV.TEC2DEL', 'ALMMOV.TEC2COD', 'ALMMOV.SEL2COD', 'ALMPRD.PRDCDES',
                'ALMSEL.SELCESA', 'ALMSEL.SELDCAL', 'ALMSEL.SELDVER', 'ALMSEL.SELDMAN', 'ALMSEL.SELDCAD']);

        $expired = [];
        foreach ($uses as $use) {
            $reason = self::expiredReason($use->SELCESA, $use->SELDCAL, $use->SELDVER, $use->SELDMAN, $use->SELDCAD, $date);
            if ($reason === '') {
                continue;
            }
            // ALM_TextoEquipo: la serie oculta de los productos sin series no se muestra
            $lot = (string) $use->SEL2COD;
            $text = (string) $use->PRDCDES.($lot !== '' && $lot !== self::SERIE_UNICA ? " ({$lot})" : '');
            $expired[((string) $use->TEC2DEL)."\x1B".$use->TEC2COD][] = $text.': '.$reason;
        }

        return $expired;
    }
}
