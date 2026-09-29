<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabCodes;
use App\Support\VeolabCustomFields;
use App\Support\VeolabLicense;
use App\Support\VeolabOperationServices;
use App\Support\VeolabStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Operaciones (LABOPE): datos generales, servicios al crear (LABOYS, LABRES...)
 * y campos autodefinibles (LABOYA, ver VeolabCustomFields).
 *
 * Réplica de FichaOperacion/Operaciones de Veolab:
 *  - Estado y fechas del flujo como la barra de estados: avanzar rellena las
 *    fechas vacías hasta el nuevo estado, retroceder borra las posteriores;
 *    respeta LABCON.CONBBAR (barra bloqueada) y CONBUNO (de uno en uno).
 *  - No se modifica si está en un informe validado o firmado (salvo los
 *    autodefinibles editables estando validada, AUTBVAL).
 *  - Para pasar a recibida (y guardar desde preparada) exige los campos
 *    obligatorios de LABCON.CONCCAO (CamposObligatoriosCubiertos).
 *  - Borrado con las comprobaciones de CON_BorradoFisicoPermitidoOperaciones
 *    y la cascada de Operaciones.frm (con devolución de stock si hay Almacén).
 *  - Generación desde una planificación (POST /planificaciones/generar).
 */
class OperacionController extends BaseController
{
    use ChecksVeolabReferences;

    /** Planificación de la que se genera la operación en curso, o null. */
    private ?array $planningSource = null;

    /** Campos de la operación que se copian de la planificación (GenerarOperacion). */
    private const PLANNING_FIELDS = [
        'informacion' => 'PLOCINF', 'referencia' => 'PLOCREF', 'tipo' => 'PLOCTIP',
        'tipo_analisis' => 'PLONTIA', 'precio' => 'PLONPRE', 'descuento' => 'PLOCDTO',
        'descripcion' => 'PLOCDES', 'observaciones' => 'PLOCOBS', 'recolector' => 'PLOCREC',
        'lugar_recogida' => 'PLOCLUR', 'temperatura' => 'PLOCTEM', 'cantidad' => 'PLOCCAN',
        'unidad' => 'PLOCUNI', 'tipo_desglose' => 'PLOCTID', 'lote_muestra' => 'PLOCLOT',
        'marca' => 'PLOCMAR', 'envase' => 'PLOCENV', 'numero_envases' => 'PLONENV',
        'latitud' => 'PLOCLAT', 'longitud' => 'PLOCLNG', 'direccion_gps' => 'PLOCDIG',
        'tipo_muestreo' => 'PLOCTIM', 'id_red_sinac' => 'PLONRED',
        'codigo_localidad_sinac' => 'PLONLOC', 'direccion_sinac' => 'PLOCDIR',
    ];

    /** Grupos de claves foráneas que se copian de la planificación (mismas columnas). */
    private const PLANNING_REFERENCES = ['tipo_operacion', 'matriz', 'equipamiento', 'cliente', 'contrato',
        'presupuesto', 'empleado_recolector', 'lote', 'tarifa', 'proveedor', 'producto'];

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
     *
     * 'autodefinibles': {"nombre": valor} (fichero: {delegacion, codigo});
     * valor vacío = se borra. Solo se tocan los indicados.
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
            'autodefinibles'                 => 'nullable|array',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $current = null;

        $delegation = (string) (empty($keys) ? ($data['delegacion'] ?? '') : $keys['delegacion']);
        $customFields = VeolabCustomFields::resolve($delegation, $data['autodefinibles'] ?? null);
        unset($data['autodefinibles']);

        if ($this->planningSource) {
            $this->assertPlanningDateAvailable();
            // Los autodefinibles de la planificación, con los indicados encima.
            $customFields += VeolabCustomFields::stored('LABPLO',
                [$this->planningSource['delegacion'], $this->planningSource['codigo']], $delegation);
        }

        if (empty($keys)) {
            // Valores por defecto de una operación nueva.
            $data['tipo'] ??= 'E';
            $data['es_urgente'] ??= 'F';
            $data['es_baja'] ??= 'F';
            $data['descuento'] ??= ''; // Veolab guarda '' sin descuento
            $data['tipo_desglose'] ??= $this->defaultBreakdown();

            // Tarifa por defecto: la del cliente (CargarTarifaCliente).
            $data = $this->applyClientTariff($data);

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

            try {
                $this->assertNotInValidatedReport($keys['delegacion'], $keys['serie'], (int) $keys['codigo']);
            } catch (BusinessRuleException $e) {
                // Solo autodefinibles editables estando validada (AUTBVAL).
                $onlyCustomFields = array_diff(array_keys($data), array_keys($this->keys)) === [];
                if (! $onlyCustomFields || ! VeolabCustomFields::allEditableWhenValidated($customFields)) {
                    throw $e;
                }
            }

            if (array_key_exists('servicios', $data)) {
                throw new BusinessRuleException('Los servicios solo se indican al crear la operación');
            }
        }

        $data = $this->applyBillable($data, $current);
        $data = $this->applyStateRules($data, $current);

        $opKey = $current ? [$keys['delegacion'], $keys['serie'], (int) $keys['codigo']] : null;
        $this->assertRequiredFields($data, $current, $delegation, $opKey, $customFields);

        $data['_autodefinibles'] = $customFields;

        return $data;
    }

    /**
     * CamposObligatoriosCubiertos: LABCON.CONCCAO lista (';') los campos que
     * deben estar cubiertos para recibir la operación: columnas de LABOPE y
     * autodefinibles ("AU_<del>_<cod>.OYACVAL"). Veolab lo exige al pasar a
     * recibida y al guardar en un estado posterior.
     */
    private function assertRequiredFields(array $data, ?object $current, string $delegation, ?array $opKey, array $customFields): void
    {
        $newState = (int) $data['estado'];
        $currentState = $current ? (int) $current->OPENEST : 0;
        if ($newState < 1 || ($newState === 1 && $current && $currentState >= 1)) {
            return;
        }

        $list = trim((string) DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONCCAO'));
        if ($list === '') {
            return;
        }

        $params = array_flip($this->mapping);
        $missing = [];
        $customEntries = [];
        foreach (array_filter(array_map('trim', explode(';', $list))) as $column) {
            if (str_starts_with($column, 'AU_')) {
                $customEntries[] = $column;
                continue;
            }
            $param = $params[$column] ?? null;
            if ($param === null) {
                continue;
            }
            $value = array_key_exists($param, $data) ? $data[$param] : ($current->{$column} ?? null);
            // Vacío: nulo o '' ; en claves foráneas y nº de envases también 0
            // (Veolab marca el nº de envases al revés, cuando es > 0: no se replica).
            $zeroIsEmpty = str_ends_with($column, '2COD') || $column === 'OPENENV';
            if ($value === null || trim((string) $value) === '' || ($zeroIsEmpty && (float) $value == 0)) {
                $missing[] = $param;
            }
        }

        $missing = array_merge($missing, VeolabCustomFields::missingRequired($customEntries, $delegation, $opKey, $customFields));
        if ($missing) {
            throw new BusinessRuleException('Faltan campos obligatorios para recibir la operación: '.implode(', ', $missing));
        }
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

    /** Servicios de una operación nueva: existen y respetan CONBSER. */
    private function validateServices(array $services): void
    {
        if (count($services) > 1
            && DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONBSER') === 'T') {
            throw new BusinessRuleException(
                'Este laboratorio está configurado con una operación por servicio: cree una operación por cada servicio'
            );
        }

        $this->checkServicesExist($services);
    }

    /**
     * POST /planificaciones/generar: operación a partir de una planificación
     * (Planificaciones.GenerarOperacion, generación sin abrir la ficha). Cuerpo:
     * {delegacion, codigo, fecha?, operacion?}; "fecha" es el código de la fecha
     * planificada (LABFEP) y "operacion" campos que sustituyen a los copiados.
     * Una fecha ya generada no se vuelve a generar (se puede marcar como
     * pendiente). Tandas (PLONNOP > 1) y "una operación por servicio" con
     * varios servicios se generan desde Veolab.
     */
    public function generateFromPlanning(Request $request)
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'delegacion' => 'nullable|string|max:10',
            'codigo'     => 'required|integer',
            'fecha'      => 'nullable|integer',
            'operacion'  => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $del = (string) ($body['delegacion'] ?? '');
        $plan = DB::connection('dynamic')->table('LABPLO')
            ->where('DEL3COD', $del)->where('PLO1COD', (int) $body['codigo'])->first();
        if (! $plan) {
            return response()->json(['message' => 'Planificación no encontrada'], 404);
        }

        try {
            $payload = $this->payloadFromPlanning($plan, $body['fecha'] ?? null, $body['operacion'] ?? []);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->planningSource = [
            'delegacion' => $del,
            'codigo'     => (int) $plan->PLO1COD,
            'fecha'      => isset($body['fecha']) ? (int) $body['fecha'] : null,
            'compromiso' => $plan->PLOBCOM === 'T',
        ];

        return $this->create($payload);
    }

    /** Datos de la operación copiados de la planificación, con las sustituciones. */
    private function payloadFromPlanning(object $plan, $date, array $overrides): array
    {
        if ((int) $plan->PLONNOP > 1) {
            throw new BusinessRuleException('La planificación genera una tanda de operaciones: genérela desde Veolab');
        }
        $services = DB::connection('dynamic')->table('LABPYS')
            ->where('PLO3DEL', $plan->DEL3COD)->where('PLO3COD', $plan->PLO1COD)->count();
        if ($services > 1 && DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONBSER') === 'T') {
            throw new BusinessRuleException(
                'Este laboratorio crea una operación por servicio y la planificación tiene varios: genérela desde Veolab'
            );
        }
        foreach (['delegacion', 'servicios', 'planificacion_delegacion', 'planificacion_codigo', 'planificacion_fecha_codigo'] as $param) {
            if (array_key_exists($param, $overrides)) {
                throw new BusinessRuleException("El campo '{$param}' no se puede indicar al generar desde una planificación");
            }
        }

        $payload = [];
        foreach (self::PLANNING_FIELDS as $param => $column) {
            if ($plan->{$column} !== null && $plan->{$column} !== '') {
                $payload[$param] = $plan->{$column};
            }
        }
        foreach (self::PLANNING_REFERENCES as $group) {
            foreach (['delegacion', 'serie', 'codigo'] as $suffix) {
                $param = "{$group}_{$suffix}";
                if (isset($this->mapping[$param]) && $plan->{$this->mapping[$param]} !== null) {
                    $payload[$param] = $plan->{$this->mapping[$param]};
                }
            }
        }
        if ((int) $plan->PUM2COD !== 0) {
            $payload['punto_muestreo_codigo'] = (int) $plan->PUM2COD;
        }
        if ((string) $plan->SEL2COD !== '') {
            $payload['producto_serie_lote_codigo'] = (string) $plan->SEL2COD;
        }
        if ((string) $plan->PLOCSER !== '') {
            $payload['serie'] = mb_substr((string) $plan->PLOCSER, 0, 10);
        }
        $payload['es_urgente'] = $plan->PLOBURG === 'T' ? 'T' : 'F';
        $payload['es_visible_sinac'] = $plan->PLOBVIS === 'F' ? 'F' : 'T';

        $payload['delegacion'] = (string) $plan->DEL3COD;
        $payload['planificacion_delegacion'] = (string) $plan->DEL3COD;
        $payload['planificacion_codigo'] = (int) $plan->PLO1COD;
        if ($date !== null) {
            $payload['planificacion_fecha_codigo'] = (int) $date;
        }

        return array_merge($payload, $overrides);
    }

    /** La fecha planificada existe, está activa y no se ha generado ya (con bloqueo). */
    private function assertPlanningDateAvailable(): void
    {
        if ($this->planningSource['fecha'] === null) {
            return;
        }

        $row = DB::connection('dynamic')->table('LABFEP')
            ->where('PLO3DEL', $this->planningSource['delegacion'])
            ->where('PLO3COD', $this->planningSource['codigo'])
            ->where('FEP1COD', $this->planningSource['fecha'])
            ->lockForUpdate()->first();

        if (! $row || $row->FEPTINI === null) {
            throw new BusinessRuleException('La fecha de planificación no existe');
        }
        if ($row->FEPBCOM === 'T') {
            throw new BusinessRuleException(
                'Esa fecha de la planificación ya está generada (márquela como pendiente para volver a generarla)'
            );
        }
    }

    /**
     * Rejilla de servicios de la planificación, fecha de compromiso (PLOBCOM,
     * desde la recepción o ahora) y fecha planificada como completada
     * (FichaOperacion.ActualizarPlanificacion).
     */
    private function completeFromPlanning(array $data, string $del, string $ser, int $cod): void
    {
        $source = $this->planningSource;
        $commitmentFrom = null;
        if ($source['compromiso'] && empty($data['fecha_compromiso'])) {
            $commitmentFrom = ! empty($data['fecha_recepcion'])
                ? (string) $data['fecha_recepcion']
                : (string) DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
        }

        VeolabOperationServices::addFromPlanning($del, $ser, $cod, $source['delegacion'], $source['codigo'], $commitmentFrom);

        if ($source['fecha'] !== null) {
            DB::connection('dynamic')->table('LABFEP')
                ->where('PLO3DEL', $source['delegacion'])->where('PLO3COD', $source['codigo'])
                ->where('FEP1COD', $source['fecha'])
                ->update(['FEPBCOM' => 'T']);
        }
    }

    /** Tras crear: estructura de los servicios (fase 2). Siempre: autodefinibles. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];

        if (! empty($data['servicios'])) {
            VeolabOperationServices::add($del, $ser, $cod, $data['servicios'], $data);
        }

        if ($this->planningSource) {
            $this->completeFromPlanning($data, $del, $ser, $cod);
        }

        VeolabCustomFields::save('LABOPE', [$del, $ser, $cod], $data['_autodefinibles'] ?? [], $this->auditRow($keys));

        return $data;
    }

    /** Cada operación del listado lleva sus autodefinibles ({nombre: valor}). */
    protected function appendRelatedData(array $rows): array
    {
        $values = VeolabCustomFields::valuesFor('LABOPE', array_map(
            fn ($row) => [(string) $row['delegacion'], (string) $row['serie'], (int) $row['codigo']], $rows
        ));

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".$row['serie']."\x1B".$row['codigo'];
            $row['autodefinibles'] = (object) ($values[$key] ?? []);
        }

        return $rows;
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

}
