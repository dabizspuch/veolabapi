<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Compras de Veolab 3 (Almacen.bas): pedidos a proveedor (ALMPED + ALMLPE) y
 * entregas (ALMALB + ALMLAL). Precios y formatos de compra del proveedor,
 * estado del pedido según lo recibido y aviso del proveedor (ISO 17025 6.6).
 * Trabaja sobre la conexión 'dynamic' y dentro de la transacción del cambio.
 */
class VeolabPurchases
{
    /** Estados del pedido (PEDCEST): borrador, enviado, recibido en parte, recibido y cancelado. */
    public const ORDER_STATES = ['B', 'E', 'P', 'R', 'C'];

    /** Movimiento de compra (ALM_MOV_COMPRA). */
    public const MOV_COMPRA = 'C';

    /** ALM_TextoEstadoPedido. */
    public static function orderStateText(string $state): string
    {
        return match ($state) {
            'E'     => 'Enviado',
            'P'     => 'Recibido en parte',
            'R'     => 'Recibido',
            'C'     => 'Cancelado',
            default => 'Borrador',
        };
    }

    /** Módulo de almacén (ALM) activo y licenciado: lo exigen las escrituras. */
    public static function assertModule(): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'ALM')) {
            throw new BusinessRuleException('El módulo de almacén no está activo');
        }
    }

    /**
     * ALM_PrecioProveedor: referencia y precio del producto en el proveedor,
     * los de sus productos suministrados (ALMPYP) o, si es el proveedor
     * predeterminado del producto y no los tiene ahí, los de la ficha del
     * producto. Devuelve [referencia, precio].
     */
    public static function supplierPrice(string $proDel, string $proCod, string $prdDel, string $prdCod): array
    {
        if ($proCod === '' || $prdCod === '') {
            return ['', 0.0];
        }
        $db = DB::connection('dynamic');
        $reference = '';
        $price = 0.0;
        $row = $db->table('ALMPYP')->where('PRO3DEL', $proDel)->where('PRO3COD', $proCod)
            ->where('PRD3DEL', $prdDel)->where('PRD3COD', $prdCod)->first(['PYPCREF', 'PYPNPRE']);
        if ($row) {
            $reference = (string) $row->PYPCREF;
            $price = (float) $row->PYPNPRE;
        }
        if ($reference === '' || $price == 0.0) {
            $product = $db->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)
                ->where('PRO2DEL', $proDel)->where('PRO2COD', $proCod)->first(['PRDCREF', 'PRDNPRE']);
            if ($product) {
                if ($reference === '') {
                    $reference = (string) $product->PRDCREF;
                }
                if ($price == 0.0) {
                    $price = (float) $product->PRDNPRE;
                }
            }
        }

        return [$reference, $price];
    }

    /** ¿El producto es un equipo? (ALMPRD.PRDBEQU) */
    public static function isEquipment(string $prdDel, string $prdCod): bool
    {
        return DB::connection('dynamic')->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)
            ->value('PRDBEQU') === 'T';
    }

    /**
     * ALM_FormatoCompra: formato en que se compra el producto: el que vende el
     * proveedor con su referencia (ALMPYP.FOR2COD), si no el predeterminado
     * del producto (o el primero) y, sin formatos, el contenido de su último
     * lote sin nombre de formato. Los equipos se compran por unidades, sin
     * formato. Devuelve [formato (0 sin formato), descripción, contenido].
     */
    public static function purchaseFormat(string $proDel, string $proCod, string $prdDel, string $prdCod): array
    {
        if ($prdCod === '') {
            return [0, '', 0.0];
        }
        if (self::isEquipment($prdDel, $prdCod)) {
            return [0, '', 1.0];
        }
        $db = DB::connection('dynamic');
        $format = 0;
        $description = '';
        $factor = 0.0;
        if ($proCod !== '') {
            $row = $db->table('ALMPYP')
                ->join('ALMFOR', fn ($j) => $j->on('ALMPYP.PRD3DEL', '=', 'ALMFOR.PRD3DEL')->on('ALMPYP.PRD3COD', '=', 'ALMFOR.PRD3COD')
                    ->on('ALMPYP.FOR2COD', '=', 'ALMFOR.FOR1COD'))
                ->where('ALMPYP.PRO3DEL', $proDel)->where('ALMPYP.PRO3COD', $proCod)
                ->where('ALMPYP.PRD3DEL', $prdDel)->where('ALMPYP.PRD3COD', $prdCod)
                ->first(['ALMFOR.FOR1COD', 'ALMFOR.FORCDES', 'ALMFOR.FORNFAC']);
            if ($row) {
                [$format, $description, $factor] = [(int) $row->FOR1COD, (string) $row->FORCDES, (float) $row->FORNFAC];
            }
        }
        if ($format === 0) {
            $formats = $db->table('ALMFOR')->where('PRD3DEL', $prdDel)->where('PRD3COD', $prdCod)
                ->orderBy('FOR1COD')->get(['FOR1COD', 'FORCDES', 'FORNFAC', 'FORBPRE']);
            foreach ($formats as $row) {
                if ($format === 0 || $row->FORBPRE === 'T') {
                    [$format, $description, $factor] = [(int) $row->FOR1COD, (string) $row->FORCDES, (float) $row->FORNFAC];
                    if ($row->FORBPRE === 'T') {
                        break;
                    }
                }
            }
        }
        if ($factor <= 0) {
            $factor = self::quantityPerUnit($prdDel, $prdCod);
        }

        return [$format, $description, $factor];
    }

    /**
     * Formato indicado a mano en una línea (cmbFormatoL_Click): debe ser de
     * ese producto, que no puede ser un equipo. Devuelve [descripción, contenido].
     */
    public static function chosenFormat(string $prdDel, string $prdCod, int $format): array
    {
        if ($prdCod === '' || self::isEquipment($prdDel, $prdCod)) {
            throw new BusinessRuleException('Solo los productos que no son equipos tienen formatos de compra');
        }
        $row = DB::connection('dynamic')->table('ALMFOR')->where('PRD3DEL', $prdDel)->where('PRD3COD', $prdCod)
            ->where('FOR1COD', $format)->first(['FORCDES', 'FORNFAC']);
        if (! $row) {
            throw new BusinessRuleException("El formato de compra {$format} no existe en el producto {$prdCod}");
        }

        return [(string) $row->FORCDES, (float) $row->FORNFAC];
    }

    /**
     * ALM_CantidadPorUnidad: cantidad por unidad con la que se dan de alta las
     * series nuevas del producto: la de su serie más reciente, o 1.
     */
    public static function quantityPerUnit(string $prdDel, string $prdCod): float
    {
        $value = DB::connection('dynamic')->table('ALMSEL')->where('PRD3DEL', $prdDel)->where('PRD3COD', $prdCod)
            ->where('SELNCAU', '>', 0)->orderByDesc('SELDALT')->value('SELNCAU');

        return $value !== null ? (float) $value : 1.0;
    }

    /**
     * TipoProducto (FichaEntrega): S sin series (suma a la serie oculta),
     * E equipo (una serie por unidad), L con series o lotes.
     */
    public static function productType(string $prdDel, string $prdCod): string
    {
        $row = DB::connection('dynamic')->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)
            ->first(['PRDBEQU', 'PRDBSEL']);
        if (! $row) {
            return 'L';
        }
        if (VeolabStock::basicMode() || $row->PRDBSEL === 'F') {
            return 'S';
        }

        return $row->PRDBEQU === 'T' ? 'E' : 'L';
    }

    /**
     * ALM_RecalcularEstadoPedido: estado del pedido según lo recibido en sus
     * líneas: recibido si no falta nada, recibido en parte si falta algo y ya
     * llegó algo, y enviado si no ha llegado nada. Los cancelados no cambian.
     */
    public static function recalculateOrderState(string $del, string $ser, int $cod): void
    {
        $db = DB::connection('dynamic');
        $lines = fn () => $db->table('ALMLPE')->where('PED3DEL', $del)->where('PED3SER', $ser)->where('PED3COD', $cod);
        $missing = $lines()->whereColumn('LPENREC', '<', 'LPENCAN')->count();
        $received = (float) $lines()->sum('LPENREC');

        $state = $received <= 0 ? 'E' : ($missing > 0 ? 'P' : 'R');
        $db->table('ALMPED')->where('DEL3COD', $del)->where('PED1SER', $ser)->where('PED1COD', $cod)
            ->where(fn ($q) => $q->whereNull('PEDCEST')->orWhere('PEDCEST', '<>', 'C'))
            ->update(['PEDCEST' => $state]);
    }

    /**
     * ALM_AvisoProveedor: aviso al pedir o recibir de un proveedor (ISO 17025
     * 6.6.2): evaluación vencida o, si el laboratorio ya registra
     * evaluaciones, proveedor no aceptado. Vacío si no hay nada que avisar.
     */
    public static function supplierWarning(string $proDel, string $proCod): string
    {
        if ($proCod === '') {
            return '';
        }
        $db = DB::connection('dynamic');
        $row = $db->table('SINPRO')->where('DEL3COD', $proDel)->where('PRO1COD', $proCod)->first(['PROBACE', 'PRODPEV']);
        if (! $row) {
            return '';
        }
        $today = (string) $db->selectOne('SELECT CURDATE() AS d')->d;
        $next = $row->PRODPEV !== null ? substr((string) $row->PRODPEV, 0, 10) : '';
        if ($next !== '' && $next < $today) {
            return 'La evaluación de este proveedor venció el '.substr($next, 8, 2).'/'.substr($next, 5, 2).'/'.substr($next, 0, 4)
                .': conviene reevaluarlo.';
        }
        if ($row->PROBACE !== 'T' && $db->table('SINEVA')->exists()) {
            return 'Este proveedor no está aceptado: su última evaluación fue negativa o aún no se ha evaluado.';
        }

        return '';
    }

    /** Texto de un descuento ("10%", "3.5") con el separador decimal del laboratorio. */
    public static function discount($value): string
    {
        return VeolabBillingLines::localized(trim((string) ($value ?? '')));
    }

    /** FAC_CalcularImporteConDescuento: cantidad × precio con el descuento de la línea. */
    public static function lineAmount(float $quantity, float $price, string $discount): float
    {
        return VeolabOperationServices::withDiscount($quantity * $price, $discount);
    }
}
