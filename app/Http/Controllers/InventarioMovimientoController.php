<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabStock;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de almacén (ALMMOV), como FichaMovimiento de Veolab: es el
 * historial de cada serie o lote. Los genera Veolab (y la API) al cambiar las
 * existencias del inventario, consumir en operaciones o en materias primas;
 * aquí se consultan y se pueden anotar o corregir a mano, lo que, igual que
 * en Veolab, NO cambia las existencias (para eso, /inventario). Los consumos
 * (O) y usos (U) de las operaciones son de solo lectura: los genera la
 * operación al crearse y se devuelven al borrarla.
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

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            $this->checkNotFromOperation($keys);
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

    protected function validateBeforeDelete(array $keys): void
    {
        $this->checkNotFromOperation($keys);
    }

    /**
     * Los consumos y usos de una operación se gestionan desde ella: al borrarla
     * se devuelven a las existencias sumando sus consumos, que no deben tocarse.
     */
    private function checkNotFromOperation(array $keys): void
    {
        $operation = DB::connection('dynamic')->table('ALMMOV')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('MOV1COD', $keys['codigo'])
            ->value('OPE2COD');
        if ((int) $operation > 0) {
            throw new BusinessRuleException('Los movimientos de una operación se gestionan desde la operación');
        }
    }
}
