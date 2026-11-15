<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Licencia de Veolab
    |--------------------------------------------------------------------------
    |
    | Patrones de cifrado de la licencia (Encriptacion.bas), en base64. Son
    | SECRETOS: van solo en el .env del servidor, nunca en el repositorio.
    | Sin ellos la licencia no se puede descifrar y la API aplica siempre las
    | restricciones de Verifactu (el caso de una API desplegada por un cliente).
    |
    */

    'license' => [
        'busqueda'          => env('VEOLAB_ENC_BUSQUEDA'),
        'encripta3'         => env('VEOLAB_ENC_ENCRIPTA3'),
        'busqueda_numeros'  => env('VEOLAB_ENC_BUSQUEDA_NUMEROS'),
        'encriptan'         => env('VEOLAB_ENC_ENCRIPTAN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contraseñas de los usuarios de Veolab
    |--------------------------------------------------------------------------
    |
    | Desde la base de datos 3.0 ACCUSU.USUCCON es un hash PBKDF2 y no usa
    | patrones (VeolabPassword). En las anteriores se guarda con ENC_Encripta
    | (Encriptacion.bas) con los patrones ENC_PATRON_BUSQUEDA (el mismo
    | 'busqueda' de la licencia) y ENC_PATRON_ENCRIPTA1, en base64 de
    | Windows-1252. SECRETOS: solo en el .env. Sin ellos la API no puede poner
    | contraseñas en bases anteriores a 3.0 (422).
    |
    */

    'password' => [
        'busqueda'  => env('VEOLAB_ENC_BUSQUEDA'),
        'encripta1' => env('VEOLAB_ENC_ENCRIPTA1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Separador decimal
    |--------------------------------------------------------------------------
    |
    | Veolab escribe los números de los campos de texto (resultados, rangos,
    | descuentos...) con la configuración regional de cada equipo; dentro de
    | un laboratorio es la misma para todos. La API lo toma de aquí (uno por
    | servidor: los laboratorios de cada VPS comparten país) para guardar los
    | números que recibe y los textos que genera. "," o ".".
    |
    */

    'decimal_separator' => env('VEOLAB_SEPARADOR_DECIMAL', ',') === '.' ? '.' : ',',

];
