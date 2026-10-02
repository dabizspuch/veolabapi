<?php

namespace App\Http\Controllers;

/**
 * Series y contadores (ACCCLT). Solo lectura: las series se configuran en
 * Veolab (ConfigurarSeries) y los contadores los avanzan las altas.
 *
 * Una fila por delegación + tabla + serie. Las tablas sin serie (perfiles,
 * planificaciones, trozos de firma...) tienen su contador con serie ''.
 * 'contador' es el último código asignado; 'es_predeterminada' marca la
 * serie que Veolab propone en las altas de esa tabla.
 */
class SerieController extends BaseController
{
    protected string $table = 'ACCCLT';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'tabla'      => 'CLTCTAB',
        'serie'      => 'CLTCSER',
    ];

    protected array $mapping = [
        'delegacion'        => 'DEL3COD',
        'tabla'             => 'CLTCTAB',
        'serie'             => 'CLTCSER',
        'descripcion'       => 'CLTCDES',
        'es_predeterminada' => 'CLTBPRE',
        'contador'          => 'CLTNVAL',
    ];
}
