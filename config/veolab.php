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
