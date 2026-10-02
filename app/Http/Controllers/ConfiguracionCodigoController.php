<?php

namespace App\Http\Controllers;

/**
 * Formato y numeración de los códigos por tabla (ACCCFC). Solo lectura: se
 * configura en Veolab (ConfigurarCodigos). La API ya lo aplica al generar y
 * mostrar códigos (VeolabCodes). Orden A/D; posiciones I izquierda, D derecha;
 * informacion_adicional: L letra del día de la semana, C código de cliente.
 */
class ConfiguracionCodigoController extends BaseController
{
    protected string $table = 'ACCCFC';
    protected array $keys = [
        'tabla' => 'CFCCNOM',
    ];
    protected ?string $delegationKey = null;

    protected array $mapping = [
        'tabla'                  => 'CFCCNOM',
        'orden'                  => 'CFCCORD',
        'mostrar_delegacion'     => 'CFCBMDE',
        'formato_delegacion'     => 'CFCCFDE',
        'posicion_delegacion'    => 'CFCCPDE',
        'mostrar_serie'          => 'CFCBMSE',
        'posicion_serie'         => 'CFCCPSE',
        'formato_codigo'         => 'CFCCFCO',
        'separador'              => 'CFCCSEP',
        'informacion_adicional'  => 'CFCCINF',
        'usa_todos_campos'       => 'CFCBTOD',
        'autonumerico'           => 'CFCBAUT',
        'multiplo'               => 'CFCNMUL',
        'aviso_rotura_secuencia' => 'CFCBAVI',
        'bloquear_edicion'       => 'CFCBBLO',
        'serie_por_cliente'      => 'CFCBCLI',
        'posicion'               => 'CFCNORD',
    ];
}
