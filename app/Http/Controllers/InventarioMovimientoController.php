<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabStock;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de almacén (ALMMOV), como FichaMovimiento de Veolab: es el
 * historial de cada serie o lote. Los genera Veolab (y la API) al cambiar las
 * existencias del inventario, consumir en operaciones o en materias primas;
 * aquí se consultan y se pueden anotar, corregir o borrar a mano. En Veolab 3,
 * igual que en FichaMovimiento, el movimiento a mano cambia las existencias
 * de su serie (entradas suman; salidas, bajas, préstamos, anulaciones y
 * consumos restan; el ajuste lleva su signo; el uso no cuenta) y al borrarlo
 * se deshace; en bases 2.4 no las cambia. Los consumos (O) y usos (U) de las
 * operaciones, y en Veolab 3 los de préstamos y entregas, son de solo
 * lectura: se gestionan desde su origen.
 *
 * Tipos: I inventario inicial, E entrada, S salida, C compra, D devolución,
 * P préstamo, B baja, O consumo, U uso, J ajuste, A anulación.
 */
class InventarioMovimientoController extends BaseController
{
    protected string $table = 'ALMMOV';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'MOV1COD',
    ];
    protected array $searchFields = ['MOVCOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'           => 'DEL3COD',
        'codigo'               => 'MOV1COD',
        'tipo'                 => 'MOVCTIP',
        'fecha'                => 'MOVDFEC',
        'cantidad'             => 'MOVNCAN',
        'observaciones'        => 'MOVCOBS',
        'producto_delegacion'  => 'PRD2DEL',
        'producto_codigo'      => 'PRD2COD',
        'serie_lote_codigo'    => 'SEL2COD',
        'operacion_delegacion' => 'OPE2DEL',
        'operacion_serie'      => 'OPE2SER',
        'operacion_codigo'     => 'OPE2COD',
        'tecnica_delegacion'   => 'TEC2DEL',
        'tecnica_codigo'       => 'TEC2COD',
        'prestamo_delegacion'  => 'PRE2DEL',
        'prestamo_codigo'      => 'PRE2COD',
        'usuario_delegacion'   => 'USU2DEL',
        'usuario_codigo'       => 'USU2COD',
    ];

    protected array $foreignKeys = [
        'producto'   => 'string',
        'serie_lote' => 'string',
        'operacion'  => 'int',
        'tecnica'    => 'string',
        'prestamo'   => 'int',
        'usuario'    => 'string',
    ];

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'delegacion'          => 'nullable|string|max:10',
            'tipo'                => ($isCreating ? 'required' : 'sometimes').'|string|in:'.implode(',', VeolabStock::MOVEMENT_TYPES),
            'fecha'               => 'nullable|date',
            'cantidad'            => 'nullable|numeric',
            'observaciones'       => 'nullable|string|max:50',
            'producto_delegacion' => 'nullable|string|max:10',
            'producto_codigo'     => ($isCreating ? 'required' : 'sometimes').'|string|max:15',
            'serie_lote_codigo'   => 'nullable|string|max:30',
            'usuario_delegacion'  => 'nullable|string|max:10',
            'usuario_codigo'      => 'nullable|string|max:15',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $db = DB::connection('dynamic');

        if (! empty($data['delegacion']) && ! $db->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
            throw new BusinessRuleException('La delegación no existe');
        }

        if (! empty($data['producto_codigo'])) {
            $productDelegation = (string) ($data['producto_delegacion'] ?? '');
            if (! $db->table('ALMPRD')->where('DEL3COD', $productDelegation)->where('PRD1COD', $data['producto_codigo'])->exists()) {
                throw new BusinessRuleException('El producto no existe');
            }
            if (! empty($data['serie_lote_codigo'])) {
                $exists = $db->table('ALMSEL')->where('PRD3DEL', $productDelegation)
                    ->where('PRD3COD', $data['producto_codigo'])->where('SEL1COD', $data['serie_lote_codigo'])->exists();
                if (! $exists) {
                    throw new BusinessRuleException('La serie o lote no existe');
                }
            }
        }

        if (! empty($data['usuario_codigo'])) {
            $exists = $db->table('ACCUSU')->where('DEL3COD', (string) ($data['usuario_delegacion'] ?? ''))
                ->where('USU1COD', $data['usuario_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El usuario no existe');
            }
        }
    }

    /** Fila anterior de un movimiento que se modifica, para deshacer su efecto en las existencias. */
    private ?object $previous = null;

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            $this->checkNotFromOperation($keys);
            $this->previous = $this->movementRow($keys);
        }

        if (! empty($data['fecha'])) {
            $data['fecha'] = (new \DateTime($data['fecha']))->format('Y-m-d H:i:s');
        } elseif (! $keys) {
            $data['fecha'] = DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
        }
        if (! $keys) {
            $data['cantidad'] ??= 0;
        }

        return $data;
    }

    /**
     * Veolab 3: el movimiento anotado a mano cambia las existencias de su
     * serie; al modificarlo se deshace lo que hacía antes y se aplica el nuevo.
     */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if (VeolabVersion::isV3()) {
            $products = [];
            if ($this->previous) {
                VeolabStock::applyManualMovement($this->previous, true, $products);
            }
            if ($row = $this->movementRow($keys)) {
                $missing = VeolabStock::applyManualMovement($row, false, $products);
                if ($missing < 0) {
                    // Como en Veolab: se quita lo disponible y el movimiento guarda lo que de verdad se quitó
                    $real = VeolabStock::movementEffect((string) $row->MOVCTIP, (float) $row->MOVNCAN) - $missing;
                    DB::connection('dynamic')->table('ALMMOV')
                        ->where('DEL3COD', (string) $keys['delegacion'])->where('MOV1COD', $keys['codigo'])
                        ->update(['MOVNCAN' => $row->MOVCTIP === VeolabStock::MOV_AJUSTE ? $real : abs($real)]);
                }
            }
            if ($products) {
                VeolabStock::recalculateProducts(array_values($products));
            }
        }

        return $data;
    }

    /** Veolab 3: lo que hizo el movimiento borrado vuelve a las existencias. */
    protected function validateBeforeDelete(array $keys): void
    {
        $this->checkNotFromOperation($keys);
        if (VeolabVersion::isV3() && ($row = $this->movementRow($keys))) {
            $products = [];
            VeolabStock::applyManualMovement($row, true, $products);
            if ($products) {
                VeolabStock::recalculateProducts(array_values($products));
            }
        }
    }

    private function movementRow(array $keys): ?object
    {
        return DB::connection('dynamic')->table('ALMMOV')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('MOV1COD', $keys['codigo'])
            ->lockForUpdate()->first(['PRD2DEL', 'PRD2COD', 'SEL2COD', 'MOVCTIP', 'MOVNCAN']);
    }

    /**
     * Los consumos y usos de una operación se gestionan desde ella: al borrarla
     * se devuelven a las existencias sumando sus consumos, que no deben tocarse.
     * En Veolab 3, igual los de un préstamo o una entrega de proveedor.
     */
    private function checkNotFromOperation(array $keys): void
    {
        $v3 = VeolabVersion::isV3();
        $row = DB::connection('dynamic')->table('ALMMOV')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('MOV1COD', $keys['codigo'])
            ->first($v3 ? ['OPE2COD', 'PRE2COD', 'ALB2COD'] : ['OPE2COD']);
        if (! $row) {
            return;
        }
        if ((int) $row->OPE2COD > 0) {
            throw new BusinessRuleException('Los movimientos de una operación se gestionan desde la operación');
        }
        if ($v3 && (int) $row->PRE2COD > 0) {
            throw new BusinessRuleException('Los movimientos de un préstamo se gestionan desde el préstamo');
        }
        if ($v3 && (int) $row->ALB2COD > 0) {
            throw new BusinessRuleException('Los movimientos de una entrega de proveedor se gestionan desde la entrega');
        }
    }
}
