<?php

namespace App\Http\Controllers;

class AuditoriaArchivadaController extends BaseController
{
    protected string $table = 'ACAAUD';
    protected array $keys = [
        'codigo' => 'AUD1COD',
    ];
    protected array $searchFields = ['AUDCTAB', 'AUDCFIL', 'AUDCCAM', 'AUDCVAM', 'AUDCVAA'];

    // Registro de auditoría archivada: solo lectura.
    protected bool $generatesCode = false;

    protected array $mapping = [
        'codigo'           => 'AUD1COD',
        'fecha'            => 'AUDTFEC',
        'tipo'             => 'AUDCTIP',
        'tabla'            => 'AUDCTAB',
        'fila'             => 'AUDCFIL',
        'campo'            => 'AUDCCAM',
        'valor_modificado' => 'AUDCVAM',
        'valor_anterior'   => 'AUDCVAA',
        'sesion_codigo'    => 'SES2COD',
        'delegacion'       => 'DEL2COD',
    ];
}
