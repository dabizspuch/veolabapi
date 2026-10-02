<?php

namespace App\Http\Controllers;

use App\Support\VeolabLicense;
use Illuminate\Support\Facades\DB;

/**
 * Módulos de Veolab (ACCMOD). Solo lectura: se activan en la configuración
 * de Veolab. 'es_licenciado' indica si la licencia del laboratorio lo
 * incluye; un módulo funciona si está activo y licenciado (LIC_ModuloActivo).
 */
class ModuloController extends BaseController
{
    protected string $table = 'ACCMOD';
    protected array $keys = [
        'codigo' => 'MOD1COD',
    ];
    protected ?string $delegationKey = null;

    protected array $mapping = [
        'codigo'          => 'MOD1COD',
        'cadena_idioma'   => 'MODCDES',
        'es_activo'       => 'MODBACT',
        'orden'           => 'MODNORD',
    ];

    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        $texts = $db->table('IDICAD')->where('IDI3COD', 1)
            ->whereIn('CAD1COD', array_filter(array_column($rows, 'cadena_idioma')))
            ->pluck('CADCDES', 'CAD1COD');

        foreach ($rows as &$row) {
            $row['descripcion'] = (string) ($texts[$row['cadena_idioma']] ?? $row['codigo']);
            $row['es_licenciado'] = VeolabLicense::moduleLicensed('dynamic', $db->getDatabaseName(), (string) $row['codigo']) ? 'T' : 'F';
        }

        return $rows;
    }
}
