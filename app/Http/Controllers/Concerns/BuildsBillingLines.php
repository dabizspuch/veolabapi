<?php

namespace App\Http\Controllers\Concerns;

use App\Support\VeolabBillingLines;

/**
 * Rejilla de líneas de los documentos de venta (presupuestos y contratos):
 * la indicada en la petición ('lineas' o 'servicios') o la guardada, con
 * los precios regenerados al cambiar de cliente o de tarifa (RegenerarPrecios)
 * salvo que se hubieran modificado a mano. Requiere en $mapping los
 * parámetros 'tipo_desglose' y 'precios_modificados'.
 */
trait BuildsBillingLines
{
    /**
     * @param  ?object  $current  fila guardada (null al crear)
     * @param  ?array  $key  [delegación, serie, código] (null al crear)
     * @return array [líneas, ¿cambia la rejilla?, subtotal, suplidos]
     */
    protected function resolveLines(array &$data, ?object $current, ?array $key, object $ctx, string $breakdown,
        bool $clientChanged, bool $tariffChanged): array
    {
        $isNew = $current === null;

        $input = $data['lineas'] ?? null;
        if (array_key_exists('servicios', $data)) {
            $input = VeolabBillingLines::inputFromServices($data['servicios']);
        }
        unset($data['lineas'], $data['servicios']);

        if ($input !== null) {
            [$lines, $priced] = VeolabBillingLines::fromInput($input, $ctx);
            $data['precios_modificados'] = $priced ? 'T' : 'F';
            $changed = true;
        } else {
            $lines = $isNew ? [] : VeolabBillingLines::stored($this->table, $key);
            $changed = $isNew
                || $breakdown !== $this->documentBreakdown((string) $current->{$this->mapping['tipo_desglose']});

            if (! $isNew && ($clientChanged || ($tariffChanged && $ctx->perTariff))) {
                // Los precios modificados a mano se conservan (Veolab pregunta).
                if ((string) $current->{$this->mapping['precios_modificados']} !== 'T') {
                    $lines = VeolabBillingLines::reprice($lines, $ctx);
                }
                if ($clientChanged) {
                    // Los puntos de muestreo eran del cliente anterior.
                    $lines = array_map(fn ($line) => ['point' => 0] + $line, $lines);
                }
                $changed = true;
            }
        }

        [$subtotal, $supplied] = VeolabBillingLines::compute($this->table, $lines, $breakdown, $ctx);

        return [$lines, $changed, $subtotal, $supplied];
    }

    /** Desglose del documento: por servicio, por técnica o sin desglose (Grabar). */
    protected function documentBreakdown(string $value): string
    {
        return in_array($value, ['S', 'T'], true) ? $value : 'N';
    }
}
