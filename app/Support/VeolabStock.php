<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Existencias de almacén (Almacen.bas). Trabaja sobre la conexión 'dynamic'
 * y debe llamarse dentro de la transacción del cambio.
 */
class VeolabStock
{
    public const MOV_CONSUMO = 'O';
    public const MOV_USO = 'U';

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
