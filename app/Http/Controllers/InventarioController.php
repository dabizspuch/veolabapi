<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabStock;
use Illuminate\Support\Facades\DB;

/**
 * Inventario: series y lotes de los productos (ALMSEL), los elementos reales
 * del almacén, como FichaInventario de Veolab. El producto (ALMPRD) es el
 * catálogo; sus existencias son la suma de sus series y lotes que no están
 * de baja y se recalculan con cada cambio.
 *
 *  - Código: si no se indica, el siguiente numérico del producto
 *    (ALM_ObtenerSerieSiguiente).
 *  - Alta: sin existencias indicadas, las del lote (unidades por lote y
 *    cantidad por unidad × unidades); proveedor y precio, los del producto
 *    (ALMPRD / ALMPYP). Genera el movimiento de inventario inicial.
 *  - Modificación: cambiar las existencias genera un movimiento de ajuste;
 *    dar de baja (estado B o fecha de baja) uno de baja con la cantidad en
 *    negativo; reactivar, uno de ajuste con la cantidad.
 *  - Borrado: no si la usa una operación o es materia prima de otra serie
 *    (se puede dar de baja); borra sus movimientos y materias, y sus
 *    documentos van a la papelera.
 */
class InventarioController extends BaseController
{
    protected string $table = 'ALMSEL';
    protected array $keys = [
        'producto_delegacion' => 'PRD3DEL',
        'producto_codigo'     => 'PRD3COD',
        'codigo'              => 'SEL1COD',
    ];
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'producto_delegacion';
    protected ?string $seriesKey = 'producto_codigo';
    protected array $searchFields = ['SEL1COD', 'SELCDES', 'SELCCOB'];

    protected array $mapping = [
        'producto_delegacion'      => 'PRD3DEL',
        'producto_codigo'          => 'PRD3COD',
        'codigo'                   => 'SEL1COD',
        'descripcion'              => 'SELCDES',
        'codigo_barras'            => 'SELCCOB',
        'estado'                   => 'SELCESA',
        'fecha_alta'               => 'SELDALT',
        'fecha_apertura'           => 'SELDAPE',
        'fecha_baja'               => 'SELDBAJ',
        'cantidad_unidad'          => 'SELNCAU',
        'unidades_lote'            => 'SELNUNL',
        'existencias_unidades'     => 'SELNUNE',
        'existencias_cantidad'     => 'SELNCAE',
        'precio'                   => 'SELNPRE',
        'ubicacion'                => 'SELCUBI',
        'condiciones_ambientales'  => 'SELCCOA',
        'manual_operacion'         => 'SELCMAO',
        'especificaciones_tecnicas' => 'SELCETC',
        'fecha_recepcion'          => 'SELDREC',
        'fecha_calibracion'        => 'SELDCAL',
        'fecha_mantenimiento'      => 'SELDMAN',
        'fecha_verificacion'       => 'SELDVER',
        'fecha_caducidad'          => 'SELDCAD',
        'fecha_aviso_caducidad'    => 'SELDACA',
        'estado_recepcion'         => 'SELCESR',
        'tipo_fluido'              => 'SELCTIF',
        'volumen_fluido'           => 'SELCVOF',
        'reglas_analisis'          => 'SELCREA',
        'generico_1'               => 'SELCGE1',
        'generico_2'               => 'SELCGE2',
        'generico_3'               => 'SELCGE3',
        'generico_4'               => 'SELCGE4',
        'generico_5'               => 'SELCGE5',
        'generico_6'               => 'SELCGE6',
        'observaciones'            => 'SELCOBS',
        'proveedor_delegacion'     => 'PRO2DEL',
        'proveedor_codigo'         => 'PRO2COD',
    ];

    protected array $foreignKeys = ['proveedor' => 'string'];

    private const DATES = ['fecha_alta', 'fecha_apertura', 'fecha_baja', 'fecha_recepcion', 'fecha_calibracion',
        'fecha_mantenimiento', 'fecha_verificacion', 'fecha_caducidad', 'fecha_aviso_caducidad'];

    /** Fila anterior (modificación) y si es un alta, para después de grabar. */
    private ?object $before = null;
    private bool $creating = false;

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        $rules = [
            'producto_delegacion'       => 'nullable|string|max:10',
            'producto_codigo'           => ($isCreating ? 'required' : 'sometimes').'|string|max:15',
            'codigo'                    => 'nullable|string|max:30',
            'descripcion'               => 'nullable|string|max:50',
            'codigo_barras'             => 'nullable|string|max:100',
            // N nuevo, U en uso, L límite de uso, F fuera de uso, B baja ('' sin estado).
            'estado'                    => 'nullable|string|in:N,U,L,F,B',
            'cantidad_unidad'           => 'nullable|numeric|min:0',
            'unidades_lote'             => 'nullable|numeric|min:0',
            'existencias_unidades'      => 'nullable|numeric|min:0',
            'existencias_cantidad'      => 'nullable|numeric|min:0',
            'precio'                    => 'nullable|numeric|min:0',
            'ubicacion'                 => 'nullable|string|max:100',
            'condiciones_ambientales'   => 'nullable|string|max:100',
            'manual_operacion'          => 'nullable|string|max:255',
            'especificaciones_tecnicas' => 'nullable|string|max:255',
            // N nuevo, U usado.
            'estado_recepcion'          => 'nullable|string|in:N,U',
            'tipo_fluido'               => 'nullable|string|max:50',
            'volumen_fluido'            => 'nullable|string|max:50',
            'reglas_analisis'           => 'nullable|string|max:50',
            'observaciones'             => 'nullable|string',
            'proveedor_delegacion'      => 'nullable|string|max:10',
            'proveedor_codigo'          => 'nullable|string|max:15',
        ];
        foreach (self::DATES as $date) {
            $rules[$date] = 'nullable|date';
        }
        for ($i = 1; $i <= 6; $i++) {
            $rules["generico_{$i}"] = 'nullable|string|max:50';
        }

        return $rules;
    }

    protected function validateRelationships(array $data): void
    {
        if (isset($data['producto_codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMPRD')
                ->where('DEL3COD', (string) ($data['producto_delegacion'] ?? ''))
                ->where('PRD1COD', $data['producto_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El producto no existe');
            }
        }

        if (! empty($data['proveedor_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINPRO')
                ->where('DEL3COD', (string) ($data['proveedor_delegacion'] ?? ''))
                ->where('PRO1COD', $data['proveedor_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El proveedor no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        foreach (self::DATES as $date) {
            if (! empty($data[$date])) {
                $data[$date] = (new \DateTime($data[$date]))->format('Y-m-d H:i:s');
            }
        }
        foreach (['estado', 'estado_recepcion'] as $state) {
            if (array_key_exists($state, $data)) {
                $data[$state] = (string) ($data[$state] ?? ''); // Veolab graba '' sin estado
            }
        }

        $this->creating = ! $keys;

        return $keys ? $this->prepareUpdate($data, $keys) : $this->prepareCreate($data);
    }

    private function prepareCreate(array $data): array
    {
        $db = DB::connection('dynamic');
        $data['producto_delegacion'] = (string) ($data['producto_delegacion'] ?? '');

        // El producto bloqueado hasta el commit: código siguiente sin carreras.
        $product = $db->table('ALMPRD')
            ->where('DEL3COD', $data['producto_delegacion'])->where('PRD1COD', $data['producto_codigo'])
            ->lockForUpdate()->first(['PRO2DEL', 'PRO2COD']);

        if (($data['codigo'] ?? '') === '') {
            $max = $db->table('ALMSEL')
                ->where('PRD3DEL', $data['producto_delegacion'])->where('PRD3COD', $data['producto_codigo'])
                ->max(DB::raw('CAST(SEL1COD AS DECIMAL(30,0))'));
            $data['codigo'] = (string) ((int) $max + 1);
        }

        // Proveedor y precio de compra del producto (ObtenerDatosProducto).
        if (empty($data['proveedor_codigo']) && $product && (string) $product->PRO2COD !== '') {
            $data['proveedor_delegacion'] = (string) $product->PRO2DEL;
            $data['proveedor_codigo'] = (string) $product->PRO2COD;
        }
        if (! isset($data['precio']) && ! empty($data['proveedor_codigo'])) {
            $price = $db->table('ALMPYP')
                ->where('PRO3DEL', (string) ($data['proveedor_delegacion'] ?? ''))->where('PRO3COD', $data['proveedor_codigo'])
                ->where('PRD3DEL', $data['producto_delegacion'])->where('PRD3COD', $data['producto_codigo'])
                ->value('PYPNPRE');
            if ($price !== null) {
                $data['precio'] = $price;
            }
        }

        $data['estado'] ??= '';
        $data['estado_recepcion'] ??= '';
        $data['fecha_alta'] ??= self::today();
        $data = $this->linkWithdrawal($data, '');

        // Existencias iniciales: las indicadas o las del lote completo.
        $perUnit = (float) ($data['cantidad_unidad'] ?? 0);
        if (isset($data['existencias_cantidad'])) {
            $data['existencias_unidades'] ??= VeolabStock::unitsFromQuantity($perUnit, (float) $data['existencias_cantidad']);
        } elseif (isset($data['existencias_unidades'])) {
            $data['existencias_cantidad'] = VeolabStock::quantityFromUnits($perUnit, (float) $data['existencias_unidades']);
        } else {
            $data['existencias_unidades'] = (float) ($data['unidades_lote'] ?? 0);
            $data['existencias_cantidad'] = VeolabStock::quantityFromUnits($perUnit, (float) ($data['unidades_lote'] ?? 0));
        }

        return $data;
    }

    private function prepareUpdate(array $data, array $keys): array
    {
        $this->before = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', (string) $keys['producto_delegacion'])->where('PRD3COD', $keys['producto_codigo'])
            ->where('SEL1COD', $keys['codigo'])->first();

        $data = $this->linkWithdrawal($data, (string) $this->before->SELCESA);

        // Como la ficha: cantidad y unidades en existencias se calculan una de otra.
        $perUnit = (float) ($data['cantidad_unidad'] ?? $this->before->SELNCAU);
        if (isset($data['existencias_cantidad']) && ! isset($data['existencias_unidades']) && $perUnit > 0) {
            $data['existencias_unidades'] = VeolabStock::unitsFromQuantity($perUnit, (float) $data['existencias_cantidad']);
        } elseif (isset($data['existencias_unidades']) && ! isset($data['existencias_cantidad']) && $perUnit > 0) {
            $data['existencias_cantidad'] = VeolabStock::quantityFromUnits($perUnit, (float) $data['existencias_unidades']);
        }

        return $data;
    }

    /**
     * Estado de baja y fecha de baja van juntos (ucdBaja_Change y la baja del
     * listado de inventario): fecha de baja → estado B; estado B → fecha de
     * hoy si no hay; quitar la baja → estado U y sin fecha.
     */
    private function linkWithdrawal(array $data, string $previousState): array
    {
        if (! empty($data['fecha_baja']) && ! array_key_exists('estado', $data)) {
            $data['estado'] = 'B';
        }
        if (array_key_exists('fecha_baja', $data) && empty($data['fecha_baja']) && ! array_key_exists('estado', $data)
            && $previousState === 'B') {
            $data['estado'] = 'U';
        }
        if (($data['estado'] ?? null) === 'B' && empty($data['fecha_baja'])
            && ! ($previousState === 'B' && $this->before && $this->before->SELDBAJ)) {
            $data['fecha_baja'] = self::today();
        }
        if (isset($data['estado']) && $data['estado'] !== 'B' && $previousState === 'B' && ! array_key_exists('fecha_baja', $data)) {
            $data['fecha_baja'] = null;
        }

        return $data;
    }

    /** Fecha de hoy del servidor de BD (como DBS_ObtenFechaServidor). */
    private static function today(): string
    {
        return DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
    }

    /** Movimiento que corresponde al cambio (Grabar) y existencias del producto. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $delegation = (string) $keys['producto_delegacion'];
        $product = (string) $keys['producto_codigo'];
        $lot = (string) $keys['codigo'];

        if ($this->creating) {
            VeolabStock::movement($delegation, $product, $lot, (float) $data['existencias_cantidad'], VeolabStock::MOV_INICIAL);
        } else {
            $oldState = (string) $this->before->SELCESA;
            $oldQuantity = (float) $this->before->SELNCAE;
            $newState = array_key_exists('estado', $data) ? (string) $data['estado'] : $oldState;
            $newQuantity = isset($data['existencias_cantidad']) ? (float) $data['existencias_cantidad'] : $oldQuantity;

            $movement = null;
            if ($newState !== $oldState && $newState === 'B') {
                $movement = [VeolabStock::MOV_BAJA, -$oldQuantity];
            } elseif ($newState !== $oldState && $oldState === 'B') {
                $movement = [VeolabStock::MOV_AJUSTE, $newQuantity];
            } elseif (abs($newQuantity - $oldQuantity) > 1e-9) {
                $movement = [VeolabStock::MOV_AJUSTE, $newQuantity - $oldQuantity];
            }
            if ($movement) {
                VeolabStock::movement($delegation, $product, $lot, $movement[1], $movement[0]);
            }
        }

        VeolabStock::recalculateProducts([[$delegation, $product]]);

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $db = DB::connection('dynamic');
        $delegation = (string) $keys['producto_delegacion'];

        $checks = [
            ['LABOPE', ['PRD2DEL' => $delegation, 'PRD2COD' => $keys['producto_codigo'], 'SEL2COD' => $keys['codigo']], 'alguna operación'],
            ['ALMMAT', ['PRM3DEL' => $delegation, 'PRM3COD' => $keys['producto_codigo'], 'SEM3COD' => $keys['codigo']], 'otra serie o lote como materia prima'],
            ['ALMPYS', ['PRD3DEL' => $delegation, 'PRD3COD' => $keys['producto_codigo'], 'SEL3COD' => $keys['codigo']], 'algún préstamo'],
        ];
        foreach ($checks as [$table, $where, $what]) {
            if ($db->table($table)->where($where)->exists()) {
                throw new BusinessRuleException("La serie o lote no puede ser eliminada porque la usa {$what}; se puede dar de baja");
            }
        }
    }

    /** Como el borrado del inventario de Veolab. */
    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        $delegation = (string) $keys['producto_delegacion'];
        $product = (string) $keys['producto_codigo'];
        $lot = (string) $keys['codigo'];

        $db->table('ALMMOV')->where('PRD2DEL', $delegation)->where('PRD2COD', $product)->where('SEL2COD', $lot)->delete();
        $db->table('ALMMAT')->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL3COD', $lot)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('PRD2COD', $product)->where('SEL2COD', $lot)
            ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);

        VeolabStock::recalculateProducts([[$delegation, $product]]);
    }
}
