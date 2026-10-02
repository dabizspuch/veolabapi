<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Funcionalidades de Veolab (ACCFUN) con su módulo (ACCMYF). Solo lectura:
 * son fijas de la aplicación. Nivel 1 = grupo, nivel 2 = funcionalidad a
 * la que se da acceso en los perfiles (/perfiles/permisos). Ámbito: E =
 * escritorio, W = web. Principal: funcionalidad de la que es duplicado.
 */
class FuncionalidadController extends BaseController
{
    protected string $table = 'ACCFUN';
    protected array $keys = [
        'codigo' => 'FUN1COD',
    ];
    protected ?string $delegationKey = null;

    protected array $mapping = [
        'codigo'        => 'FUN1COD',
        'cadena_idioma' => 'FUNCDES',
        'nivel'         => 'FUNNNIV',
        'exportacion'   => 'FUNNEXP',
        'ambito'        => 'FUNCWOE',
        'principal'     => 'FUN2COD',
        'orden'         => 'FUNNORD',
    ];

    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        $texts = $db->table('IDICAD')->where('IDI3COD', 1)
            ->whereIn('CAD1COD', array_filter(array_column($rows, 'cadena_idioma')))
            ->pluck('CADCDES', 'CAD1COD');
        $modules = $db->table('ACCMYF')->whereIn('FUN3COD', array_column($rows, 'codigo'))
            ->pluck('MOD3COD', 'FUN3COD');

        foreach ($rows as &$row) {
            $row['descripcion'] = (string) ($texts[$row['cadena_idioma']] ?? $row['codigo']);
            $row['grupo'] = substr((string) $row['codigo'], 0, 3);
            $row['modulo'] = $modules[$row['codigo']] ?? null;
            if ((string) $row['principal'] === '') {
                $row['principal'] = null;
            }
        }

        return $rows;
    }
}
