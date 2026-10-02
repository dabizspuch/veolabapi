<?php

namespace App\Http\Controllers\Concerns;

use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de las rejillas que Veolab graba desde la ficha de otra entidad
 * (relaciones y subtablas): suceso de fila de la ficha (nivel 2) o de campo
 * con la rejilla modificada (nivel 3, AUDCCAM = $field, sin valores).
 * Las fichas que Veolab audita con su descripción la llevan en la fila.
 */
trait AuditsOwnerRecord
{
    /** Ficha => columna de la descripción con la que Veolab la audita. */
    private static array $ownerDescriptions = [
        'GRHEMP' => 'EMPCNOM',
        'GRHPAF' => 'PAFCDES',
        'GRHCAR' => 'CARCNOM',
        'LABMAT' => 'MATCDES',
        'LABNOR' => 'NORCDES',
        'DOCDIR' => 'DIRCNOM',
        'ACCPER' => 'PERCDES',
    ];

    protected function recordOwnerChange(string $table, string $codeColumn, string $code, string $delegation, string $field): void
    {
        $description = '';
        if (isset(self::$ownerDescriptions[$table])) {
            $description = (string) DB::connection('dynamic')->table($table)
                ->where('DEL3COD', $delegation)->where($codeColumn, $code)
                ->value(self::$ownerDescriptions[$table]);
        }
        $row = VeolabCodes::format($table, $code, $delegation, '', '', $description);

        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $table, $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $table, $row, $field);
    }
}
