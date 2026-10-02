<?php

namespace App\Http\Controllers;

/**
 * Configuración del laboratorio (LABCON, una fila con codigo = 1). Solo
 * lectura: se mantiene en Configurar operaciones, informes, notificaciones,
 * facturación, listas y exportación de Veolab. Los estados son los de la
 * operación (0-7); los booleanos, T/F.
 */
class ConfiguracionLaboratorioController extends BaseController
{
    protected string $table = 'LABCON';
    protected array $keys = [
        'codigo' => 'CON1COD',
    ];
    protected ?string $delegationKey = null;

    protected array $mapping = [
        'codigo'                              => 'CON1COD',

        // Operaciones y resultados
        'valor_predeterminado_al_crear'       => 'CONBPRE',
        'operacion_por_servicio'              => 'CONBSER',
        'operacion_por_tecnica'               => 'CONBTEI',
        'incremento_especial_referencia'      => 'CONBIER',
        'fecha_compromiso_activa'             => 'CONBAFC',
        'campos_obligatorios_recibida'        => 'CONCCAO',
        'avance_estado_barra'                 => 'CONBBAR',
        'bloquear_avance_sin_estado_anterior' => 'CONBUNO',
        'marcar_inicio_resultados'            => 'CONBMAI',
        'marcar_fin_resultados'               => 'CONBMAF',
        'finalizado_columnas'                 => 'CONCFIN',   // T todas, P la primera
        'resultados_titulo_unico'             => 'CONBTUR',
        'resultados_con_unidades'             => 'CONBUNI',
        'resultados_salto_fila'               => 'CONBSFR',
        'resultados_autoajustar_columnas'     => 'CONBAUC',
        'formulas_dependencias_vacias'        => 'CONBEFD',
        'desactivar_sustitucion_limites'      => 'CONBDSL',
        'bloquear_tecnicas_dictamen'          => 'CONBBTD',
        'destino_importacion_resultados'      => 'CONNEVI',   // 0 no guardar, 1 orden, 2 operación
        'subseleccion_tandas'                 => 'CONBSUB',
        'tandas_abrir_al_leer'                => 'CONBTAN',
        'codigo_barras_fecha_inicio'          => 'CONBFIB',
        'expandir_grupos'                     => 'CONBEXP',
        'lotes_agrupar_operaciones'           => 'CONBAGL',
        'normativas_campo_principal'          => 'CONCCPN',   // D descripción, A abreviatura
        'notificar_stock_minimo'              => 'CONBSTM',

        // Histórico
        'historico_automatico'                => 'CONBHIS',
        'historico_operaciones'               => 'CONNNUH',
        'historico_incluir_propias'           => 'CONBPRO',

        // Informes
        'informe_secciones'                   => 'CONBDSE',
        'marcar_tecnicas'                     => 'CONCMTE',   // A acreditadas, N no acreditadas, X no marcar
        'marca_tecnicas'                      => 'CONCTEM',
        'posicion_marca'                      => 'CONCPOM',   // D derecha, I izquierda
        'informes_estado_desde'               => 'CONNEST',
        'informes_estado_hasta'               => 'CONNESH',
        'exportar_al_firmar'                  => 'CONBEXA',
        'marcar_enviado_al_exportar'          => 'CONBENE',
        'bloquear_informe_al_validar'         => 'CONBBLI',
        'bloquear_exportacion_sin_firmar'     => 'CONBBEF',
        'bloquear_validacion_manual'          => 'CONBBEV',
        'secciones_paginacion_independientes' => 'CONBPAG',
        'copiar_archivo_otro_nombre'          => 'CONBCOP',
        'nota_informes_acreditados'           => 'CONCNIA',
        'nota_recolectores_externos'          => 'CONCNRE',
        'pie_informes'                        => 'CONCPIE',
        'word_autoajustar_tablas'             => 'CONBAUT',
        'motor_word'                          => 'CONCMOT',
        'motor_excel'                         => 'CONCMOE',

        // Notificaciones (clientes: E email, U usuario, X ambos)
        'notificar_compromiso_analistas'      => 'CONBCOM',
        'notificar_compromiso_resultados'     => 'CONBCOR',
        'dias_aviso_compromiso'               => 'CONNDIC',
        'notificar_asignadas'                 => 'CONBASI',
        'notificar_firmas_pendientes'         => 'CONBFIR',
        'notificar_rechazados'                => 'CONBREC',
        'notificacion_clientes'               => 'CONCNCL',
        'notificar_clientes_recibida'         => 'CONBREB',
        'notificar_clientes_informes'         => 'CONBINF',
        'notificar_clientes_marcados'         => 'CONBMAR',
        'notificaciones_calendario'           => 'CONBCAL',
        'notificaciones_mensajeria'           => 'CONBMEN',
        'email_agrupado'                      => 'CONBAGR',
        'asunto_recibida'                     => 'CONCASR',
        'texto_recibida'                      => 'CONCRBT',
        'asunto_informes'                     => 'CONCASI',
        'texto_informes'                      => 'CONCINN',
        'asunto_marcas'                       => 'CONCASU',
        'texto_marcas'                        => 'CONCMAR',
        'linea_marcas'                        => 'CONCLIN',
        'fecha_fin_periodicidad'              => 'CONDPER',

        // Cartas de control
        'cartas_control_resultados'           => 'CONNNUM',
        'cartas_control_tandas'               => 'CONBTAC',
        'cartas_control_avisos'               => 'CONBCAA',
        'cartas_control_errores'              => 'CONBCAE',
        'cartas_control_creacion'             => 'CONBCAN',

        // Facturación, contratos y presupuestos
        'facturas_borrador'                   => 'CONBFAB',
        'serie_rectificativa'                 => 'CONCSFR',
        'notas_rectificativa'                 => 'CONCNFR',
        'contratos_estado_facturable'         => 'CONNESC',
        'contratos_vigentes_por_defecto'      => 'CONBACV',
        'tipo_desglose'                       => 'CONCTID',   // S servicio, T técnica, O vinculadas, N no
        'tipo_tarifa'                         => 'CONBTAR',   // F por cliente, T por tarifa
        'factura_desglose_cliente'            => 'CONBDPC',
        'factura_desglose_punto'              => 'CONBDPP',
        'desglosar_precio_cero'               => 'CONBDPZ',
        'bloquear_facturacion_al_firmar'      => 'CONBNBF',
        'archivar_operaciones'                => 'CONCARC',   // F facturar, E enviada, C cobrada, T contabilizada
        'facturacion_campo_fecha'             => 'CONCMOF',
        'facturacion_campo_referencia'        => 'CONCMOR',
        'facturacion_columna_adicional'       => 'CONCMOA',
        'facturacion_columna_descripcion'     => 'CONCCDS',
        'facturacion_agrupacion'              => 'CONCTAR',   // S servicio, O operación
        'aviso_facturas_pendientes'           => 'CONBAFP',
        'aviso_factura_no_cobrada'            => 'CONBBEI',
        'seleccion_desde_presupuesto'         => 'CONBSDP',
        'presupuesto_pendiente_seleccionable' => 'CONBEPP',
        'presupuesto_enviado_seleccionable'   => 'CONBEPE',
        'presupuesto_aceptado_seleccionable'  => 'CONBEPA',
        'presupuesto_rechazado_seleccionable' => 'CONBEPR',
        'presupuesto_validado_seleccionable'  => 'CONBEPV',
        'presupuesto_cancelado_seleccionable' => 'CONBEPC',
        'presupuesto_marcar_enviado'          => 'CONBENV',

        // Exportación de listados (estilo N normal, G negrita, C cursiva, S subrayado, T tachado, R negrita cursiva)
        'exportacion_totales_tamano'          => 'CONNTAF',
        'exportacion_totales_color'           => 'CONNCOF',
        'exportacion_totales_fondo'           => 'CONNCOB',
        'exportacion_totales_estilo'          => 'CONCESF',
        'exportacion_clientes_tamano'         => 'CONNTAC',
        'exportacion_clientes_color'          => 'CONNCOC',
        'exportacion_clientes_fondo'          => 'CONNCBC',
        'exportacion_clientes_estilo'         => 'CONCESC',

        // Aplicación
        'actualizaciones_automaticas'         => 'CONBACT',
    ];
}
