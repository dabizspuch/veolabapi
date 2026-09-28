<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabCodes;
use App\Support\VeolabLicense;
use App\Support\VeolabOperationServices;
use App\Support\VeolabStock;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones (LABOPE): datos generales. Los servicios y resultados de la
 * operación (LABOYS, LABRES...) se gestionarán aparte (fase 2).
 *
 * Réplica de FichaOperacion/Operaciones de Veolab:
 *  - Estado y fechas del flujo como la barra de estados: avanzar rellena las
 *    fechas vacías hasta el nuevo estado, retroceder borra las posteriores;
 *    respeta LABCON.CONBBAR (barra bloqueada) y CONBUNO (de uno en uno).
 *  - No se modifica si está en un informe validado o firmado.
 *  - Borrado con las comprobaciones de CON_BorradoFisicoPermitidoOperaciones
 *    y la cascada de Operaciones.frm (con devolución de stock si hay Almacén).
 */
class OperacionController extends BaseController
{
    protected string $table = 'LABOPE';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'OPE1SER',
        'codigo'     => 'OPE1COD',
    ];
    protected ?string $inactiveField = 'OPEBANU';
    protected array $searchFields = ['OPECDES', 'OPECREF'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    /** Estado => fecha que lo marca (barra de estados de la ficha). */
    private const STATE_DATES = [
        0 => 'fecha_registro',
        1 => 'fecha_recepcion',
        2 => 'fecha_preparada',
        3 => 'fecha_inicio',
        4 => 'fecha_fin',
        5 => 'fecha_validacion',
        6 => 'fecha_envio',
        7 => 'fecha_archivo',
    ];

    protected array $foreignKeys = [
        'tipo_operacion'      => 'int',
        'matriz'              => 'int',
        'equipamiento'        => 'string',
        'cliente'             => 'string',
        'punto_muestreo'      => 'int',
        'contrato'            => 'int',
        'presupuesto'         => 'int',
        'empleado_recolector' => 'int',
        'lote'                => 'string',
        'lote_relacionado'    => 'string',
        'factura'             => 'int',
        'planificacion'       => 'int',
        'planificacion_fecha' => 'int',
        'dictamen'            => 'int',
        'tarifa'              => 'int',
        'proveedor'           => 'string',
        'producto'            => 'string',
        'producto_serie_lote' => 'string',
        'tecnica'             => 'string',
        'operacion_control'   => 'int',
    ];

    protected array $mapping = [
        'delegacion'                     => 'DEL3COD',
        'serie'                          => 'OPE1SER',
        'codigo'                         => 'OPE1COD',
        'informacion'                    => 'OPECINF',
        'tanda'                          => 'OPENTAN',
        'estado'                         => 'OPENEST',
        'fecha_registro'                 => 'OPEDREG',
        'fecha_recogida'                 => 'OPETREC',
        'fecha_recepcion'                => 'OPETREP',
        'fecha_preparada'                => 'OPEDPRE',
        'fecha_inicio'                   => 'OPEDINI',
        'fecha_fin'                      => 'OPEDFIN',
        'fecha_validacion'               => 'OPEDVAL',
        'fecha_informe'                  => 'OPEDINF',
        'fecha_envio'                    => 'OPEDENV',
        'fecha_archivo'                  => 'OPEDARC',
        'fecha_anulacion'                => 'OPEDANU',
        'fecha_compromiso'               => 'OPEDCOM',
        'fecha_descarte'                 => 'OPEDDES',
        'referencia'                     => 'OPECREF',
        'tipo'                           => 'OPECTIP',
        'tipo_analisis'                  => 'OPENTIA',
        'precio'                         => 'OPENPRE',
        'descuento'                      => 'OPECDTO',
        'descripcion'                    => 'OPECDES',
        'observaciones'                  => 'OPECOBS',
        'tecnicas'                       => 'OPECTEC',
        'recolector'                     => 'OPECREC',
        'lugar_recogida'                 => 'OPECLUR',
        'temperatura'                    => 'OPECTEM',
        'es_urgente'                     => 'OPEBURG',
        'es_baja'                        => 'OPEBANU',
        'es_prefacturada'                => 'OPEBPRE',
        'es_facturada'                   => 'OPEBFAC',
        'es_facturable'                  => 'OPEBFAB',
        'precios_modificados'            => 'OPEBMOP',
        'estado_igeo'                    => 'OPECIGE',
        'identificador_igeo'             => 'OPECIDG',
        'error_mapeo_igeo'               => 'OPEBMAP',
        'cantidad'                       => 'OPECCAN',
        'unidad'                         => 'OPECUNI',
        'tipo_desglose'                  => 'OPECTID',
        'lote_muestra'                   => 'OPECLOT',
        'marca'                          => 'OPECMAR',
        'envase'                         => 'OPECENV',
        'numero_envases'                 => 'OPENENV',
        'latitud'                        => 'OPECLAT',
        'longitud'                       => 'OPECLNG',
        'direccion_gps'                  => 'OPECDIG',
        'tipo_muestreo'                  => 'OPECTIM',
        'es_control'                     => 'OPEBCON',
        'es_visible_sinac'               => 'OPEBVIS',
        'id_red_sinac'                   => 'OPENRED',
        'codigo_localidad_sinac'         => 'OPENLOC',
        'direccion_sinac'                => 'OPECDIR',
        'tipo_operacion_delegacion'      => 'TIO2DEL',
        'tipo_operacion_codigo'          => 'TIO2COD',
        'matriz_delegacion'              => 'MAT2DEL',
        'matriz_codigo'                  => 'MAT2COD',
        'equipamiento_delegacion'        => 'EQU2DEL',
        'equipamiento_codigo'            => 'EQU2COD',
        'cliente_delegacion'             => 'CLI2DEL',
        'cliente_codigo'                 => 'CLI2COD',
        'punto_muestreo_codigo'          => 'PUM2COD',
        'contrato_delegacion'            => 'CON2DEL',
        'contrato_serie'                 => 'CON2SER',
        'contrato_codigo'                => 'CON2COD',
        'presupuesto_delegacion'         => 'PRE2DEL',
        'presupuesto_serie'              => 'PRE2SER',
        'presupuesto_codigo'             => 'PRE2COD',
        'empleado_recolector_delegacion' => 'EMP2DEL',
        'empleado_recolector_codigo'     => 'EMP2COD',
        'lote_delegacion'                => 'LOT2DEL',
        'lote_serie'                     => 'LOT2SER',
        'lote_codigo'                    => 'LOT2COD',
        'lote_relacionado_delegacion'    => 'LOT4DEL',
        'lote_relacionado_serie'         => 'LOT4SER',
        'lote_relacionado_codigo'        => 'LOT4COD',
        'factura_delegacion'             => 'FAC2DEL',
        'factura_serie'                  => 'FAC2SER',
        'factura_codigo'                 => 'FAC2COD',
        'planificacion_delegacion'       => 'PLO2DEL',
        'planificacion_codigo'           => 'PLO2COD',
        'planificacion_fecha_codigo'     => 'FEP2COD',
        'dictamen_delegacion'            => 'DIC2DEL',
        'dictamen_codigo'                => 'DIC2COD',
        'tarifa_delegacion'              => 'TAR2DEL',
        'tarifa_codigo'                  => 'TAR2COD',
        'proveedor_delegacion'           => 'PRO2DEL',
        'proveedor_codigo'               => 'PRO2COD',
        'producto_delegacion'            => 'PRD2DEL',
        'producto_codigo'                => 'PRD2COD',
        'producto_serie_lote_codigo'     => 'SEL2COD',
        'tecnica_delegacion'             => 'TEC2DEL',
        'tecnica_codigo'                 => 'TEC2COD',
        'operacion_control_delegacion'   => 'OPE2DEL',
        'operacion_control_serie'        => 'OPE2SER',
        'operacion_control_codigo'       => 'OPE2COD',
    ];

    /**
     * Sin regla (solo lectura, los mantiene Veolab): tanda, tecnicas,
     * es_facturable (se deriva del tipo y del cliente), es_prefacturada,
     * es_facturada, factura_*, precios_modificados y los campos de IGEO.
     *
     * 'servicios' solo al crear: genera servicios, parámetros, columnas de
     * resultado, gastos, analistas, departamentos y consumos como Veolab.
     */
    protected function rules(): array
    {
        return [
            'delegacion'                     => 'nullable|string|max:10',
            'serie'                          => 'nullable|string|max:10',
            'codigo'                         => 'nullable|integer|min:1',
            'informacion'                    => 'nullable|string|max:20',
            'estado'                         => 'nullable|integer|between:0,7',
            'fecha_registro'                 => 'nullable|date',
            'fecha_recogida'                 => 'nullable|date',
            'fecha_recepcion'                => 'nullable|date',
            'fecha_preparada'                => 'nullable|date',
            'fecha_inicio'                   => 'nullable|date',
            'fecha_fin'                      => 'nullable|date',
            'fecha_validacion'               => 'nullable|date',
            'fecha_informe'                  => 'nullable|date',
            'fecha_envio'                    => 'nullable|date',
            'fecha_archivo'                  => 'nullable|date',
            'fecha_anulacion'                => 'nullable|date',
            'fecha_compromiso'               => 'nullable|date',
            'fecha_descarte'                 => 'nullable|date',
            'referencia'                     => 'nullable|string|max:255',
            'tipo'                           => 'nullable|string|in:I,E',
            'tipo_analisis'                  => 'nullable|integer',
            'precio'                         => 'nullable|numeric|min:0|max:9999999999999.99999',
            'descuento'                      => 'nullable|string|max:15',
            'descripcion'                    => 'nullable|string',
            'observaciones'                  => 'nullable|string',
            'recolector'                     => 'nullable|string|max:100',
            'lugar_recogida'                 => 'nullable|string|max:100',
            'temperatura'                    => 'nullable|string|max:50',
            'es_urgente'                     => 'nullable|string|in:T,F',
            'es_baja'                        => 'nullable|string|in:T,F',
            'cantidad'                       => 'nullable|string|max:50',
            'unidad'                         => 'nullable|string|max:15',
            'tipo_desglose'                  => 'nullable|string|in:S,T,N,O',
            'lote_muestra'                   => 'nullable|string|max:70',
            'marca'                          => 'nullable|string|max:50',
            'envase'                         => 'nullable|string|max:255',
            'numero_envases'                 => 'nullable|numeric|min:0|max:9999999999999.99999',
            'latitud'                        => 'nullable|string|max:20',
            'longitud'                       => 'nullable|string|max:20',
            'direccion_gps'                  => 'nullable|string|max:255',
            'tipo_muestreo'                  => 'nullable|string|in:P,C,I',
            'es_control'                     => 'nullable|string|in:T,F',
            'es_visible_sinac'               => 'nullable|string|in:T,F',
            'id_red_sinac'                   => 'nullable|integer',
            'codigo_localidad_sinac'         => 'nullable|integer',
            'direccion_sinac'                => 'nullable|string|max:200',
            'tipo_operacion_delegacion'      => 'nullable|string|max:10',
            'tipo_operacion_codigo'          => 'nullable|integer',
            'matriz_delegacion'              => 'nullable|string|max:10',
            'matriz_codigo'                  => 'nullable|integer',
            'equipamiento_delegacion'        => 'nullable|string|max:10',
            'equipamiento_codigo'            => 'nullable|string|max:20',
            'cliente_delegacion'             => 'nullable|string|max:10',
            'cliente_codigo'                 => 'nullable|string|max:15',
            'punto_muestreo_codigo'          => 'nullable|integer',
            'contrato_delegacion'            => 'nullable|string|max:10',
            'contrato_serie'                 => 'nullable|string|max:10',
            'contrato_codigo'                => 'nullable|integer',
            'presupuesto_delegacion'         => 'nullable|string|max:10',
            'presupuesto_serie'              => 'nullable|string|max:10',
            'presupuesto_codigo'             => 'nullable|integer',
            'empleado_recolector_delegacion' => 'nullable|string|max:10',
            'empleado_recolector_codigo'     => 'nullable|integer',
            'lote_delegacion'                => 'nullable|string|max:10',
            'lote_serie'                     => 'nullable|string|max:10',
            'lote_codigo'                    => 'nullable|string|max:50',
            'lote_relacionado_delegacion'    => 'nullable|string|max:10',
            'lote_relacionado_serie'         => 'nullable|string|max:10',
            'lote_relacionado_codigo'        => 'nullable|string|max:50',
            'planificacion_delegacion'       => 'nullable|string|max:10',
            'planificacion_codigo'           => 'nullable|integer',
            'planificacion_fecha_codigo'     => 'nullable|integer',
            'dictamen_delegacion'            => 'nullable|string|max:10',
            'dictamen_codigo'                => 'nullable|integer',
            'tarifa_delegacion'              => 'nullable|string|max:10',
            'tarifa_codigo'                  => 'nullable|integer',
            'proveedor_delegacion'           => 'nullable|string|max:10',
            'proveedor_codigo'               => 'nullable|string|max:15',
            'producto_delegacion'            => 'nullable|string|max:10',
            'producto_codigo'                => 'nullable|string|max:15',
            'producto_serie_lote_codigo'     => 'nullable|string|max:30',
            'tecnica_delegacion'             => 'nullable|string|max:10',
            'tecnica_codigo'                 => 'nullable|string|max:30',
            'operacion_control_delegacion'   => 'nullable|string|max:10',
            'operacion_control_serie'        => 'nullable|string|max:10',
            'operacion_control_codigo'       => 'nullable|integer',
            'servicios'                      => 'nullable|array|min:1',
            'servicios.*.delegacion'         => 'nullable|string|max:10',
            'servicios.*.codigo'             => 'required|string|max:20',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $del = fn (string $group) => (string) ($data["{$group}_delegacion"] ?? '');
        $ser = fn (string $group) => (string) ($data["{$group}_serie"] ?? '');

        if (! empty($data['delegacion'])) {
            $this->mustExist('ACCDEL', ['DEL1COD' => $data['delegacion']], 'La delegación no existe');
        }

        $simple = [
            'tipo_operacion'      => ['LABTIO', 'TIO1COD', 'El tipo de operación no existe'],
            'matriz'              => ['LABMAT', 'MAT1COD', 'La matriz no existe'],
            'equipamiento'        => ['LABEQU', 'EQU1COD', 'El equipamiento no existe'],
            'cliente'             => ['SINCLI', 'CLI1COD', 'El cliente no existe'],
            'empleado_recolector' => ['GRHEMP', 'EMP1COD', 'El empleado recolector no existe'],
            'planificacion'       => ['LABPLO', 'PLO1COD', 'La planificación no existe'],
            'dictamen'            => ['LABDIC', 'DIC1COD', 'El dictamen no existe'],
            'tarifa'              => ['LABTAR', 'TAR1COD', 'La tarifa no existe'],
            'proveedor'           => ['SINPRO', 'PRO1COD', 'El proveedor no existe'],
            'producto'            => ['ALMPRD', 'PRD1COD', 'El producto no existe'],
            'tecnica'             => ['LABTEC', 'TEC1COD', 'La técnica no existe'],
        ];
        foreach ($simple as $group => [$table, $codeColumn, $message]) {
            if (! empty($data["{$group}_codigo"])) {
                $this->mustExist($table, ['DEL3COD' => $del($group), $codeColumn => $data["{$group}_codigo"]], $message);
            }
        }

        $withSeries = [
            'contrato'          => ['FACCON', 'CON1SER', 'CON1COD', 'El contrato no existe'],
            'presupuesto'       => ['FACPRE', 'PRE1SER', 'PRE1COD', 'El presupuesto no existe'],
            'lote'              => ['LABLOT', 'LOT1SER', 'LOT1COD', 'El lote no existe'],
            'lote_relacionado'  => ['LABLOT', 'LOT1SER', 'LOT1COD', 'El lote relacionado no existe'],
            'operacion_control' => ['LABOPE', 'OPE1SER', 'OPE1COD', 'La operación de control no existe'],
        ];
        foreach ($withSeries as $group => [$table, $seriesColumn, $codeColumn, $message]) {
            if (! empty($data["{$group}_codigo"])) {
                $this->mustExist($table, [
                    'DEL3COD'     => $del($group),
                    $seriesColumn => $ser($group),
                    $codeColumn   => $data["{$group}_codigo"],
                ], $message);
            }
        }

        // Punto de muestreo: cuelga del cliente.
        if (! empty($data['punto_muestreo_codigo'])) {
            if (empty($data['cliente_codigo'])) {
                throw new BusinessRuleException('El punto de muestreo requiere indicar el cliente');
            }
            $this->mustExist('LABPUM', [
                'DEL3COD' => $del('cliente'),
                'CLI3COD' => $data['cliente_codigo'],
                'PUM1COD' => $data['punto_muestreo_codigo'],
            ], 'El punto de muestreo no existe');
        }

        // Fecha de planificación: cuelga de la planificación.
        if (! empty($data['planificacion_fecha_codigo'])) {
            if (empty($data['planificacion_codigo'])) {
                throw new BusinessRuleException('La fecha de planificación requiere indicar la planificación');
            }
            $this->mustExist('LABFEP', [
                'PLO3DEL' => $del('planificacion'),
                'PLO3COD' => $data['planificacion_codigo'],
                'FEP1COD' => $data['planificacion_fecha_codigo'],
            ], 'La fecha de planificación no existe');
        }

        // Serie o lote del producto: cuelga del producto.
        if (! empty($data['producto_serie_lote_codigo'])) {
            if (empty($data['producto_codigo'])) {
                throw new BusinessRuleException('La serie o lote requiere indicar el producto');
            }
            $this->mustExist('ALMSEL', [
                'PRD3DEL' => $del('producto'),
                'PRD3COD' => $data['producto_codigo'],
                'SEL1COD' => $data['producto_serie_lote_codigo'],
            ], 'La serie o lote del producto no existe');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $current = null;

        if (empty($keys)) {
            // Valores por defecto de una operación nueva.
            $data['tipo'] ??= 'E';
            $data['es_urgente'] ??= 'F';
            $data['es_baja'] ??= 'F';
            $data['descuento'] ??= ''; // Veolab guarda '' sin descuento
            $data['tipo_desglose'] ??= $this->defaultBreakdown();

            // Tarifa por defecto: la del cliente (CargarTarifaCliente).
            if (empty($data['tarifa_codigo']) && ! empty($data['cliente_codigo'])) {
                $clientRate = DB::connection('dynamic')->table('SINCLI')
                    ->where('DEL3COD', (string) ($data['cliente_delegacion'] ?? ''))
                    ->where('CLI1COD', $data['cliente_codigo'])
                    ->first(['TAR2DEL', 'TAR2COD']);
                if ($clientRate && (int) $clientRate->TAR2COD > 0) {
                    $data['tarifa_delegacion'] = (string) $clientRate->TAR2DEL;
                    $data['tarifa_codigo'] = (int) $clientRate->TAR2COD;
                }
            }

            $this->validateServices($data['servicios'] ?? []);

            // Serie por cliente (ACCCFC.CFCBCLI): sin serie, la del cliente.
            if (($data['serie'] ?? null) === null && VeolabCodes::seriesPerClient($this->table)) {
                $data['serie'] = mb_substr((string) ($data['cliente_codigo'] ?? ''), 0, 10);
            }
        } else {
            $current = DB::connection('dynamic')->table($this->table)
                ->where('DEL3COD', $keys['delegacion'])
                ->where('OPE1SER', $keys['serie'])
                ->where('OPE1COD', $keys['codigo'])
                ->first();

            $this->assertNotInValidatedReport($keys['delegacion'], $keys['serie'], (int) $keys['codigo']);

            if (array_key_exists('servicios', $data)) {
                throw new BusinessRuleException('Los servicios solo se indican al crear la operación');
            }
        }

        $data = $this->applyBillable($data, $current);

        return $this->applyStateRules($data, $current);
    }

    /**
     * Facturable (GrabarCamposOperacion): interna => 'F'; externa => 'T' si el
     * cliente existe y su modo de facturación (CLICMDF) no es 'N'. Se
     * recalcula al crear y cuando cambia el tipo o el cliente.
     */
    private function applyBillable(array $data, ?object $current): array
    {
        $clientChanged = array_key_exists('cliente_codigo', $data) || array_key_exists('cliente_delegacion', $data);
        if ($current !== null && ! array_key_exists('tipo', $data) && ! $clientChanged) {
            return $data;
        }

        $type = $data['tipo'] ?? $current?->OPECTIP;
        $clientDel = array_key_exists('cliente_delegacion', $data) ? (string) $data['cliente_delegacion'] : (string) ($current?->CLI2DEL ?? '');
        $clientCode = array_key_exists('cliente_codigo', $data) ? (string) $data['cliente_codigo'] : (string) ($current?->CLI2COD ?? '');

        if ($type === 'I' || $clientCode === '') {
            $data['es_facturable'] = 'F';
        } else {
            $mode = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', $clientDel)->where('CLI1COD', $clientCode)
                ->first(['CLICMDF']);
            $data['es_facturable'] = $mode && $mode->CLICMDF !== 'N' ? 'T' : 'F';
        }

        return $data;
    }

    /** Desglose predeterminado (LABCON.CONCTID; si no es válido, por servicio). */
    private function defaultBreakdown(): string
    {
        $value = DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONCTID');

        return in_array($value, ['S', 'T', 'N', 'O'], true) ? $value : 'S';
    }

    /** Servicios de una operación nueva: existen y respetan CONBSER. */
    private function validateServices(array $services): void
    {
        if (count($services) > 1
            && DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONBSER') === 'T') {
            throw new BusinessRuleException(
                'Este laboratorio está configurado con una operación por servicio: cree una operación por cada servicio'
            );
        }

        foreach ($services as $service) {
            $this->mustExist('LABSER', [
                'DEL3COD' => (string) ($service['delegacion'] ?? ''),
                'SER1COD' => $service['codigo'],
            ], "El servicio {$service['codigo']} no existe");
        }
    }

    /** Tras crear: genera la estructura de los servicios (fase 2). */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if (! empty($data['servicios'])) {
            VeolabOperationServices::add(
                (string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'],
                $data['servicios'], $data
            );
        }

        return $data;
    }

    /**
     * Barra de estados (FichaOperacion.CambiarEstado) y anulación.
     */
    private function applyStateRules(array $data, ?object $current): array
    {
        $currentState = $current ? (int) $current->OPENEST : 0;
        $newState = isset($data['estado']) ? (int) $data['estado'] : $currentState;
        $now = (string) DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
        $today = substr($now, 0, 10);

        if ($newState !== $currentState) {
            $config = DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->first(['CONBBAR', 'CONBUNO']);
            if ($config && $config->CONBBAR === 'T') {
                throw new BusinessRuleException('El cambio de estado está bloqueado en la configuración del laboratorio');
            }
            if ($config && $config->CONBUNO === 'T' && abs($newState - $currentState) > 1) {
                throw new BusinessRuleException('Solo se permite avanzar o retroceder un estado cada vez');
            }
        }

        if ($current === null || $newState > $currentState) {
            // Avance: las fechas vacías hasta el nuevo estado toman la actual
            // (la de preparación solo con fecha, como en Veolab).
            for ($i = $current === null ? 0 : $currentState; $i <= $newState; $i++) {
                $param = self::STATE_DATES[$i];
                $given = isset($data[$param]) && $data[$param] !== '';
                $stored = $current && $current->{$this->mapping[$param]} !== null;
                if (! $given && ! $stored) {
                    $data[$param] = $i === 2 ? $today : $now;
                }
            }
        } elseif ($newState < $currentState) {
            // Retroceso: se borran las fechas de los estados posteriores.
            for ($i = $currentState; $i > $newState; $i--) {
                $data[self::STATE_DATES[$i]] = null;
            }
        }

        // Cambiar de estado "desanula" (salvo que se anule a la vez).
        $annulled = $current && $current->OPEBANU === 'T';
        if ($current && $newState !== $currentState && $annulled && ($data['es_baja'] ?? null) !== 'T') {
            $data['es_baja'] = 'F';
        }

        if (array_key_exists('es_baja', $data)) {
            if ($data['es_baja'] === 'T') {
                if (empty($data['fecha_anulacion']) && ! ($annulled && $current->OPEDANU !== null)) {
                    $data['fecha_anulacion'] = $today;
                }
                // Si está archivada y se anula, deja de estar archivada.
                if ($newState === 7) {
                    $newState = 6;
                    $data['fecha_archivo'] = null;
                }
            } else {
                $data['fecha_anulacion'] = null;
            }
        }

        $data['estado'] = $newState;

        return $data;
    }

    /**
     * FichaOperacion.InformesSinValidar: no se modifica si alguno de sus
     * informes (no históricos) está validado, o pendiente con firma válida.
     */
    private function assertNotInValidatedReport(string $delegation, string $series, int $code): void
    {
        $reports = DB::connection('dynamic')->table('LABIYO')
            ->join('LABINF', function ($join) {
                $join->on('LABIYO.INF3DEL', '=', 'LABINF.DEL3COD')
                    ->on('LABIYO.INF3SER', '=', 'LABINF.INF1SER')
                    ->on('LABIYO.INF3COD', '=', 'LABINF.INF1COD');
            })
            ->where('LABIYO.OPE3DEL', $delegation)
            ->where('LABIYO.OPE3SER', $series)
            ->where('LABIYO.OPE3COD', $code)
            ->where(fn ($q) => $q->whereNull('LABIYO.IYOBHIS')->orWhere('LABIYO.IYOBHIS', '<>', 'T'))
            ->get(['LABINF.DEL3COD', 'LABINF.INF1SER', 'LABINF.INF1COD', 'LABINF.INFCVAL']);

        foreach ($reports as $report) {
            if ($report->INFCVAL === 'V') {
                throw new BusinessRuleException('La operación está en un informe validado y no puede modificarse');
            }
            if ($report->INFCVAL !== 'R') {
                $signed = DB::connection('dynamic')->table('LABFIR')
                    ->where('INF3DEL', $report->DEL3COD)
                    ->where('INF3SER', $report->INF1SER)
                    ->where('INF3COD', $report->INF1COD)
                    ->whereNotNull('FIRDFEC')
                    ->where('FIRBVAL', 'T')
                    ->exists();
                if ($signed) {
                    throw new BusinessRuleException('La operación está en un informe firmado y no puede modificarse');
                }
            }
        }
    }

    /** CON_BorradoFisicoPermitidoOperaciones. */
    protected function validateBeforeDelete(array $keys): void
    {
        [$del, $ser, $cod] = [$keys['delegacion'], $keys['serie'], (int) $keys['codigo']];

        $references = [
            ['LABOYO', 'OPE3', 'está incluida en órdenes'],
            ['LABIYO', 'OPE3', 'está incluida en informes'],
            ['FACLIF', 'OPE2', 'está en líneas de factura'],
            ['LABRED', 'OPE2', 'está referenciada en residuos'],
            ['LABRCD', 'OPE2', 'está referenciada en cartas de control'],
            ['LABOPE', 'OPE2', 'es operación de control de otra operación'],
            ['ALMPRE', 'OPE2', 'está referenciada en préstamos'],
        ];
        foreach ($references as [$table, $prefix, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where("{$prefix}DEL", $del)->where("{$prefix}SER", $ser)->where("{$prefix}COD", $cod)
                ->exists();
            if ($used) {
                throw new BusinessRuleException("La operación no puede eliminarse porque {$reason}");
            }
        }

        $invoice = DB::connection('dynamic')->table('LABOPE')
            ->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod)
            ->value('FAC2COD');
        if ((int) $invoice > 0) {
            throw new BusinessRuleException('La operación no puede eliminarse porque está facturada');
        }
    }

    /** Cascada de Operaciones.frm (borrado múltiple). */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $ser, $cod] = [$keys['delegacion'], $keys['serie'], (int) $keys['codigo']];
        $db = DB::connection('dynamic');

        // Devuelve al almacén lo consumido (antes de borrar los movimientos).
        if (VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'ALM')) {
            VeolabStock::cancelOperationConsumptions($del, $ser, $cod);
        }

        foreach (['LABRES', 'LABCOR', 'LABOYS', 'LABOYE', 'LABOYD', 'LABOYG', 'LABOYA'] as $table) {
            $db->table($table)->where('OPE3DEL', $del)->where('OPE3SER', $ser)->where('OPE3COD', $cod)->delete();
        }
        foreach (['ALMMOV', 'ACCNOT'] as $table) {
            $db->table($table)->where('OPE2DEL', $del)->where('OPE2SER', $ser)->where('OPE2COD', $cod)->delete();
        }

        // Documentos a la papelera.
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('OPE2SER', $ser)->where('OPE2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);
    }

    private function mustExist(string $table, array $where, string $message): void
    {
        if (! DB::connection('dynamic')->table($table)->where($where)->exists()) {
            throw new BusinessRuleException($message);
        }
    }
}
