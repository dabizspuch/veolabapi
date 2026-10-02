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
                ? ['SELNCAE' => $quantity, 'SELNUNE' => self::unitsFromQuantity((float) $row->SELNCAU, $quantity)]
                : ['SELNCAE' => 0, 'SELNUNE' => 0]);
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
                        ? ['SELNCAE' => $quantity, 'SELNUNE' => self::unitsFromQuantity((float) $row->SELNCAU, $quantity)]
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
     * sus series/lotes que no están de baja (SELCESA <> 'B', como en VB: los
     * NULL tampoco cuentan).
     */
    public static function recalculateProducts(array $products): void
    {
        foreach ($products as [$delegation, $code]) {
            DB::connection('dynamic')->update(
                'UPDATE ALMPRD SET '
                .'PRDNEXI = COALESCE((SELECT SUM(SELNUNE) FROM ALMSEL s WHERE s.PRD3DEL = ALMPRD.DEL3COD AND s.PRD3COD = ALMPRD.PRD1COD AND s.SELCESA <> \'B\'), 0), '
                .'PRDNCAE = COALESCE((SELECT SUM(SELNCAE) FROM ALMSEL s WHERE s.PRD3DEL = ALMPRD.DEL3COD AND s.PRD3COD = ALMPRD.PRD1COD AND s.SELCESA <> \'B\'), 0) '
                .'WHERE DEL3COD = ? AND PRD1COD = ?',
                [$delegation, $code]
            );
        }
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
}
