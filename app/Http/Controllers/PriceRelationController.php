<?php

namespace App\Http\Controllers;

use App\Support\VeolabAudit;
use App\Support\VeolabBillingLines;
use App\Support\VeolabCodes;
use App\Support\VeolabResults;

/**
 * Precio especial de un servicio o técnica por cliente o por tarifa
 * (LABSYC, LABSYF, LABTYC, LABTYF): precio y descuento ("10%" o importe).
 *
 * Auditoría como la ventana de precios de Veolab: un suceso de modificación
 * en la propia tabla, con el servicio o técnica como fila, el cliente o la
 * tarifa como campo, y "precio descuento" como valores nuevo y anterior.
 */
abstract class PriceRelationController extends RelationController
{
    /** Importe o porcentaje en texto: coma o punto, se guarda con el separador del laboratorio. */
    private const AMOUNT = 'regex:/^\d+([.,]\d+)?\s?%?$/';

    protected function fieldRules(): array
    {
        return [
            'precio'    => 'nullable|numeric',
            'descuento' => ['nullable', 'string', 'max:15', self::AMOUNT],
        ];
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);

        if (array_key_exists('precio', $data)) {
            $data['precio'] = (float) ($data['precio'] ?? 0);
        }
        if (array_key_exists('descuento', $data)) {
            $data['descuento'] = VeolabBillingLines::localized((string) ($data['descuento'] ?? ''));
        }
        if (! $keys) {
            $data['precio'] ??= 0;
            $data['descuento'] ??= '';
        }

        return $data;
    }

    protected function auditCreated(array $data, array $keyParams): void
    {
        $this->auditPrice($keyParams, $this->priceText($data['precio'], $data['descuento']), '');
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        $price = $this->mapping['precio'];
        $discount = $this->mapping['descuento'];

        $this->auditPrice($keyParams,
            $this->priceText($dbData[$price] ?? $before[$price], $dbData[$discount] ?? $before[$discount]),
            $this->priceText($before[$price], $before[$discount]));
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        $this->auditPrice($keyParams, '',
            $this->priceText($before[$this->mapping['precio']], $before[$this->mapping['descuento']]));
    }

    private function auditPrice(array $keyParams, string $new, string $old): void
    {
        [$item, $target] = array_keys($this->entities);

        VeolabAudit::record(VeolabAudit::MODIFICACION, $this->table,
            VeolabCodes::format($this->entities[$item][0], (string) $keyParams["{$item}_codigo"], (string) $keyParams["{$item}_delegacion"]),
            VeolabCodes::format($this->entities[$target][0], (string) $keyParams["{$target}_codigo"], (string) $keyParams["{$target}_delegacion"]),
            $new, $old);
    }

    /** "precio descuento", con el separador decimal del laboratorio. */
    private function priceText($price, $discount): string
    {
        $price = str_replace('.', VeolabResults::decimalSeparator(), (string) (float) $price);

        return trim($price.' '.(string) $discount);
    }
}
