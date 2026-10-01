<?php

namespace App\Http\Controllers\Concerns;

use App\Support\VeolabAudit;
use App\Support\VeolabCodes;

/**
 * Auditoría de las rejillas que Veolab graba desde la ficha de otra entidad
 * (relaciones y subtablas): suceso de fila de la ficha (nivel 2) o de campo
 * con la rejilla modificada (nivel 3, AUDCCAM = $field, sin valores).
 */
trait AuditsOwnerRecord
{
    protected function recordOwnerChange(string $table, string $code, string $delegation, string $field): void
    {
        $row = VeolabCodes::format($table, $code, $delegation);

        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $table, $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $table, $row, $field);
    }
}
