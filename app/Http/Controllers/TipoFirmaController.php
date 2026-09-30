<?php

namespace App\Http\Controllers;

/**
 * Tipos de firma de informes (LABTIF). Solo lectura: se mantienen en la
 * configuración de informes de Veolab. 'orden' es el orden en que se firman.
 */
class TipoFirmaController extends BaseController
{
    protected string $table = 'LABTIF';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TIF1COD',
    ];
    protected ?string $inactiveField = 'TIFBBAJ';
    protected array $searchFields = ['TIFCDES'];

    protected array $mapping = [
        'delegacion'     => 'DEL3COD',
        'codigo'         => 'TIF1COD',
        'descripcion'    => 'TIFCDES',
        'es_obligatoria' => 'TIFBOBL',
        'es_baja'        => 'TIFBBAJ',
        'orden'          => 'TIFNORD',
    ];
}
