<?php

namespace App\Http\Controllers;

class AuditoriaController extends BaseController
{
    protected string $table = 'ACCAUD';
    protected array $keys = [
        'codigo' => 'AUD1COD',
    ];
    protected array $searchFields = ['AUDCTAB', 'AUDCFIL', 'AUDCCAM', 'AUDCVAM', 'AUDCVAA'];

    // Registro de auditoría: solo lectura (no se generan códigos ni se altera).
    protected bool $generatesCode = false;

    protected array $foreignKeys = [
        'sesion' => 'int',
    ];

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
