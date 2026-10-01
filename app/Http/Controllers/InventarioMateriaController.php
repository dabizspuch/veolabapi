<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabStock;
use Illuminate\Support\Facades\DB;

/**
 * Materias primas de una serie o lote (ALMMAT): otras series o lotes que se
 * han consumido para fabricarla, con la cantidad usada. Como la rejilla de
 * materias de FichaInventario (GenerarMovimientosMaterias):
 *
 *  - Alta: movimiento de consumo (cantidad en negativo) en la materia, que
 *    pierde esas existencias.
 *  - Cambio de cantidad: movimiento de ajuste con la diferencia, que se
 *    devuelve (o se descuenta) de la materia.
 *  - Baja: movimiento de ajuste que devuelve la cantidad a la materia.
 *  - Se recalculan las existencias del producto de la materia. Auditoría
 *    sobre la ficha de la serie o lote (fila + campo "ALMMAT").
 */
class InventarioMateriaController extends BaseController
{
    protected string $table = 'ALMMAT';

    protected array $keys = [
        'materia_delegacion'      => 'PRM3DEL',
        'materia_producto_codigo' => 'PRM3COD',
        'materia_codigo'          => 'SEM3COD',
        'producto_delegacion'     => 'PRD3DEL',
        'producto_codigo'         => 'PRD3COD',
        'codigo'                  => 'SEL3COD',
    ];

    protected array $mapping = [
        'producto_delegacion'     => 'PRD3DEL',
        'producto_codigo'         => 'PRD3COD',
        'codigo'                  => 'SEL3COD',
        'materia_delegacion'      => 'PRM3DEL',
        'materia_producto_codigo' => 'PRM3COD',
        'materia_codigo'          => 'SEM3COD',
        'cantidad'                => 'MATNCAN',
    ];

    /** Cantidad anterior (modificación y borrado). */
    private ?float $previous = null;

    protected function rules(): array
    {
        $required = request()->isMethod('post') ? 'required' : 'sometimes';

        return [
            'producto_delegacion'     => 'nullable|string|max:10',
            'producto_codigo'         => "{$required}|string|max:15",
            'codigo'                  => "{$required}|string|max:30",
            'materia_delegacion'      => 'nullable|string|max:10',
            'materia_producto_codigo' => "{$required}|string|max:15",
            'materia_codigo'          => "{$required}|string|max:30",
            'cantidad'                => 'nullable|numeric|min:0',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        if (! isset($data['codigo'])) {
            return;
        }

        $this->lotMustExist((string) ($data['producto_delegacion'] ?? ''), $data['producto_codigo'], $data['codigo'],
            'La serie o lote no existe');
        $this->lotMustExist((string) ($data['materia_delegacion'] ?? ''), $data['materia_producto_codigo'], $data['materia_codigo'],
            'La serie o lote de la materia prima no existe');
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            $this->previous = (float) $this->keyRow($keys)->MATNCAN;

            return $data;
        }

        $data['producto_delegacion'] = (string) ($data['producto_delegacion'] ?? '');
        $data['materia_delegacion'] = (string) ($data['materia_delegacion'] ?? '');
        $data['cantidad'] = (float) ($data['cantidad'] ?? 0);

        if ($data['producto_delegacion'] === $data['materia_delegacion']
            && $data['producto_codigo'] === $data['materia_producto_codigo']
            && $data['codigo'] === $data['materia_codigo']) {
            throw new BusinessRuleException('Una serie o lote no puede ser materia prima de sí misma');
        }

        return $data;
    }

    /** Consumo de la materia (alta) o ajuste por la diferencia (modificación). */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $new = (float) ($data['cantidad'] ?? $this->previous ?? 0);
        $delta = $this->previous === null ? -$new : $this->previous - $new;
        $type = $this->previous === null ? VeolabStock::MOV_CONSUMO : VeolabStock::MOV_AJUSTE;

        $this->moveRawMaterial($keys, $delta, $type);

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $this->previous = (float) $this->keyRow($keys)->MATNCAN;
    }

    /** La cantidad vuelve a la materia. */
    protected function deleteRelatedRecords(array $keys): void
    {
        $this->moveRawMaterial($keys, $this->previous, VeolabStock::MOV_AJUSTE);
    }

    private function moveRawMaterial(array $keys, float $delta, string $type): void
    {
        if (abs($delta) < 1e-9) {
            return;
        }

        $delegation = (string) $keys['materia_delegacion'];
        $product = (string) $keys['materia_producto_codigo'];
        $lot = (string) $keys['materia_codigo'];

        VeolabStock::movement($delegation, $product, $lot, $delta, $type);
        VeolabStock::addToLot($delegation, $product, $lot, $delta);
        VeolabStock::recalculateProducts([[$delegation, $product]]);
    }

    // ------------------------------------------------------------------
    // Auditoría sobre la ficha de la serie o lote
    // ------------------------------------------------------------------

    protected function auditCreated(array $data, array $keyParams): void
    {
        $this->auditOwner($keyParams);
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        $this->auditOwner($keyParams);
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        $this->auditOwner($keyParams);
    }

    private function auditOwner(array $keys): void
    {
        $row = VeolabCodes::format('ALMSEL', (string) $keys['codigo'], (string) $keys['producto_delegacion'],
            (string) $keys['producto_codigo']);

        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'ALMSEL', $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'ALMSEL', $row, 'ALMMAT');
    }

    // ------------------------------------------------------------------

    private function keyRow(array $keys): ?object
    {
        $query = DB::connection('dynamic')->table('ALMMAT');
        foreach ($this->keys as $param => $column) {
            $query->where($column, (string) $keys[$param]);
        }

        return $query->first();
    }

    private function lotMustExist(string $delegation, string $product, string $lot, string $message): void
    {
        $exists = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)->exists();
        if (! $exists) {
            throw new BusinessRuleException($message);
        }
    }
}
