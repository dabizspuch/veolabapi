<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\ServerError;
use App\Support\VeolabAgenda;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabCustomFields;
use App\Support\VeolabDocuments;
use App\Support\VeolabOperationServices;
use App\Support\VeolabPeriodicity;
use App\Support\VeolabStock;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Planificaciones (LABPLO): "preoperaciones" con los mismos datos que una
 * operación, sus servicios (LABPYS, LABPYT, LABPYG), autodefinibles (LABPYA)
 * y fechas planificadas (LABFEP). Réplica de FichaPlanificacion/Planificaciones.
 *
 *  - Fechas: sin fecha (PLONFRE = -1), fecha única (fecha_inicio,
 *    PLONFRE = 0) o periódica (fecha_inicio + 'repeticion', ver
 *    VeolabPeriodicity), como FichaPlanificacion: las fechas se generan
 *    hasta el horizonte de periodicidades y se omiten las ya completadas.
 *  - Aviso en la agenda (es_aviso, aviso_numero, aviso_unidad): con aviso,
 *    al crear o al cambiar el aviso o las fechas se rehace el evento de
 *    agenda de la planificación (ver VeolabAgenda::syncPlanning); sin
 *    aviso se borra.
 *  - Al cambiar la fecha, las fechas no completadas se desactivan
 *    (FEPTINI = NULL, se conservan por el vínculo de códigos de barras) y se
 *    crea la nueva, salvo que ya haya una completada en esa fecha.
 *  - Las operaciones se generan con POST /planificaciones/generar
 *    (OperacionController::generateFromPlanning).
 */
class PlanificacionController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABPLO';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'PLO1COD',
    ];
    protected array $searchFields = ['PLOCDES', 'PLOCREF'];

    /** Campos de Veolab 3: los fijos de la pestaña Recolección y el comercial (heredado del presupuesto). */
    protected array $since = [
        'fecha_elaboracion'     => VeolabVersion::V3,
        'fecha_caducidad'       => VeolabVersion::V3,
        'cloro_libre'           => VeolabVersion::V3,
        'ph'                    => VeolabVersion::V3,
        'conductividad'         => VeolabVersion::V3,
        'condiciones_recepcion' => VeolabVersion::V3,
        'conformidad_recepcion' => VeolabVersion::V3,
        'informacion_cliente'   => VeolabVersion::V3,
        'pedido'                => VeolabVersion::V3,
        'transporte'            => VeolabVersion::V3,
        'conservacion'          => VeolabVersion::V3,
        'empleado_comercial_delegacion' => VeolabVersion::V3,
        'empleado_comercial_codigo'     => VeolabVersion::V3,
    ];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = null;

    /** Periodicidad (PLONFRE): sin fecha y fecha única. */
    private const NO_DATE = -1;
    private const SINGLE_DATE = 0;

    protected array $foreignKeys = [
        'tipo_operacion'      => 'int',
        'matriz'              => 'int',
        'equipamiento'        => 'string',
        'cliente'             => 'string',
        'punto_muestreo'      => 'int',
        'contrato'            => 'int',
        'presupuesto'         => 'int',
        'empleado_recolector' => 'int',
        'empleado_comercial'  => 'int',
        'lote'                => 'string',
        'tarifa'              => 'int',
        'proveedor'           => 'string',
        'producto'            => 'string',
        'producto_serie_lote' => 'string',
    ];

    protected array $mapping = [
        'delegacion'                     => 'DEL3COD',
        'codigo'                         => 'PLO1COD',
        'serie_operaciones'              => 'PLOCSER',
        'informacion'                    => 'PLOCINF',
        'numero_operaciones'             => 'PLONNOP',
        'fecha_inicio'                   => 'PLODINI',
        'periodicidad'                   => 'PLONFRE',
        'periodicidad_opcion'            => 'PLONOPC',
        'periodicidad_repetir'           => 'PLONREP',
        'periodicidad_ordinal'           => 'PLONORD',
        'periodicidad_dia_semana'        => 'PLONSEM',
        'periodicidad_fecha_fin'         => 'PLODFIN',
        'periodicidad_repeticiones'      => 'PLONINR',
        'periodicidad_laborable'         => 'PLOBLAB',
        'es_aviso'                       => 'PLOBAVI',
        'aviso_numero'                   => 'PLONAVI',
        'aviso_unidad'                   => 'PLOCAVI',
        'referencia'                     => 'PLOCREF',
        'tipo'                           => 'PLOCTIP',
        'tipo_analisis'                  => 'PLONTIA',
        'precio'                         => 'PLONPRE',
        'descuento'                      => 'PLOCDTO',
        'descripcion'                    => 'PLOCDES',
        'observaciones'                  => 'PLOCOBS',
        'recolector'                     => 'PLOCREC',
        'lugar_recogida'                 => 'PLOCLUR',
        'temperatura'                    => 'PLOCTEM',
        'fecha_elaboracion'              => 'PLODELA',
        'fecha_caducidad'                => 'PLODCAD',
        'cloro_libre'                    => 'PLOCCLO',
        'ph'                             => 'PLOCPHS',
        'conductividad'                  => 'PLOCCDT',
        'condiciones_recepcion'          => 'PLOCCRE',
        'conformidad_recepcion'          => 'PLOCCFR',   // ISO 17025 7.4: vacía sin evaluar, C conforme, N no conforme, D con desviación
        'informacion_cliente'            => 'PLOCIAC',
        'pedido'                         => 'PLOCPED',
        'transporte'                     => 'PLOCTRA',
        'conservacion'                   => 'PLOCCNS',
        'cantidad'                       => 'PLOCCAN',
        'unidad'                         => 'PLOCUNI',
        'tipo_desglose'                  => 'PLOCTID',
        'lote_muestra'                   => 'PLOCLOT',
        'marca'                          => 'PLOCMAR',
        'envase'                         => 'PLOCENV',
        'numero_envases'                 => 'PLONENV',
        'latitud'                        => 'PLOCLAT',
        'longitud'                       => 'PLOCLNG',
        'direccion_gps'                  => 'PLOCDIG',
        'tipo_muestreo'                  => 'PLOCTIM',
        'id_red_sinac'                   => 'PLONRED',
        'codigo_localidad_sinac'         => 'PLONLOC',
        'direccion_sinac'                => 'PLOCDIR',
        'precios_modificados'            => 'PLOBMOP',
        'es_urgente'                     => 'PLOBURG',
        'calcular_compromiso'            => 'PLOBCOM',
        'es_visible_sinac'               => 'PLOBVIS',
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
        'empleado_comercial_delegacion'  => 'EMC2DEL',
        'empleado_comercial_codigo'      => 'EMC2COD',
        'lote_delegacion'                => 'LOT2DEL',
        'lote_serie'                     => 'LOT2SER',
        'lote_codigo'                    => 'LOT2COD',
        'tarifa_delegacion'              => 'TAR2DEL',
        'tarifa_codigo'                  => 'TAR2COD',
        'proveedor_delegacion'           => 'PRO2DEL',
        'proveedor_codigo'               => 'PRO2COD',
        'producto_delegacion'            => 'PRD2DEL',
        'producto_codigo'                => 'PRD2COD',
        'producto_serie_lote_codigo'     => 'SEL2COD',
    ];

    /**
     * Sin regla (solo lectura): periodicidad_* (se escriben con 'repeticion')
     * y precios_modificados.
     *
     * 'fecha_inicio': fecha (o inicio de la periodicidad; null = sin fecha).
     * 'repeticion': {frecuencia, opcion, repetir, ordinal, dias | dias_semana |
     * meses, fecha_fin, repeticiones, trasladar_laborable}.
     * 'servicios' solo al crear (en un PUT, solo los mismos que ya tiene, que se
     * ignoran; se devuelven en GET). 'autodefinibles': {"nombre": valor}.
     */
    protected function rules(): array
    {
        return [
            'delegacion'                     => 'nullable|string|max:10',
            'codigo'                         => 'nullable|integer|min:1',
            'serie_operaciones'              => 'nullable|string|max:10',
            'informacion'                    => 'nullable|string|max:20',
            'numero_operaciones'             => 'nullable|integer|min:1',
            'fecha_inicio'                   => 'nullable|date',
            'referencia'                     => 'nullable|string|max:100',
            'tipo'                           => 'nullable|string|in:I,E',
            'tipo_analisis'                  => 'nullable|integer',
            'precio'                         => 'nullable|numeric|min:0|max:9999999999999.99999',
            'descuento'                      => 'nullable|string|max:15',
            'descripcion'                    => 'nullable|string',
            'observaciones'                  => 'nullable|string',
            'recolector'                     => 'nullable|string|max:100',
            'lugar_recogida'                 => 'nullable|string|max:100',
            'temperatura'                    => 'nullable|string|max:50',
            'fecha_elaboracion'              => 'nullable|date',
            'fecha_caducidad'                => 'nullable|date',
            'cloro_libre'                    => 'nullable|string|max:20',
            'ph'                             => 'nullable|string|max:20',
            'conductividad'                  => 'nullable|string|max:20',
            'condiciones_recepcion'          => 'nullable|string|max:255',
            'conformidad_recepcion'          => 'nullable|string|in:C,N,D',
            'informacion_cliente'            => 'nullable|string|max:255',
            'pedido'                         => 'nullable|string|max:50',
            'transporte'                     => 'nullable|string|max:100',
            'conservacion'                   => 'nullable|string|max:100',
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
            'id_red_sinac'                   => 'nullable|integer',
            'codigo_localidad_sinac'         => 'nullable|integer',
            'direccion_sinac'                => 'nullable|string|max:200',
            'es_aviso'                       => 'nullable|string|in:T,F',
            'aviso_numero'                   => 'nullable|integer|min:0',
            'aviso_unidad'                   => 'nullable|string|in:M,H,D,S',
            'es_urgente'                     => 'nullable|string|in:T,F',
            'calcular_compromiso'            => 'nullable|string|in:T,F',
            'es_visible_sinac'               => 'nullable|string|in:T,F',
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
            'empleado_comercial_delegacion'  => 'nullable|string|max:10',
            'empleado_comercial_codigo'      => 'nullable|integer',
            'lote_delegacion'                => 'nullable|string|max:10',
            'lote_serie'                     => 'nullable|string|max:10',
            'lote_codigo'                    => 'nullable|string|max:50',
            'tarifa_delegacion'              => 'nullable|string|max:10',
            'tarifa_codigo'                  => 'nullable|integer',
            'proveedor_delegacion'           => 'nullable|string|max:10',
            'proveedor_codigo'               => 'nullable|string|max:15',
            'producto_delegacion'            => 'nullable|string|max:10',
            'producto_codigo'                => 'nullable|string|max:15',
            'producto_serie_lote_codigo'     => 'nullable|string|max:30',
            'servicios'                      => 'nullable|array',
            'servicios.*.delegacion'         => 'nullable|string|max:10',
            'servicios.*.codigo'             => 'required|string|max:20',
            'autodefinibles'                 => 'nullable|array',
        ] + VeolabPeriodicity::rules('repeticion');
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isNew = empty($keys);
        $delegation = (string) ($isNew ? ($data['delegacion'] ?? '') : $keys['delegacion']);
        $customFields = VeolabCustomFields::resolve($delegation, $data['autodefinibles'] ?? null, 'LABPLO');
        unset($data['autodefinibles']);
        $current = $isNew ? null : DB::connection('dynamic')->table('LABPLO')
            ->where('DEL3COD', $keys['delegacion'])->where('PLO1COD', $keys['codigo'])->first();

        // Las fechas de recolección se guardan sin hora (se escriben como texto en la ficha).
        foreach (['fecha_elaboracion', 'fecha_caducidad'] as $param) {
            if (! empty($data[$param])) {
                $data[$param] = (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        if ($isNew) {
            // Valores por defecto de una planificación nueva.
            $data['tipo'] ??= 'E';
            $data['es_urgente'] ??= 'F';
            $data['es_visible_sinac'] ??= 'T';
            $data['calcular_compromiso'] ??= 'F';
            $data['descuento'] ??= '';
            $data['tipo_desglose'] ??= $this->defaultBreakdown();
            $data['numero_operaciones'] ??= 1;
            $data['precios_modificados'] = 'F';
            $data['es_aviso'] ??= 'F';
            $data['aviso_numero'] ??= 0;
            $data = $this->applyServiceClient($data);
            $data = $this->applyClientTariff($data);
            $this->checkServicesExist($data['servicios'] ?? []);
            $data = $this->applyDate($data, $delegation, null);
        } else {
            // Los servicios se leen en GET: devolverlos sin cambios en un PUT no es un error.
            if (array_key_exists('servicios', $data)) {
                if (! $this->sameServices($keys, $data['servicios'] ?? [])) {
                    throw new BusinessRuleException('Los servicios solo se indican al crear la planificación');
                }
                unset($data['servicios']);
            }
            if (array_key_exists('fecha_inicio', $data) || array_key_exists('repeticion', $data)) {
                $before = DB::connection('dynamic')->table('LABPLO')
                    ->where('DEL3COD', $keys['delegacion'])->where('PLO1COD', $keys['codigo'])->first();
                $data = $this->applyDate($data, $delegation, $before);
            }
        }

        $data = $this->checkWarning($data, $keys);
        $data = $this->inheritCommercial($data, $current);
        [$data, $customFields] = $this->applyFieldDefaults($data, $current, $delegation, $customFields, $keys);
        $data['_autodefinibles'] = $customFields;
        $data['_nueva'] = $isNew;

        return $data;
    }

    /**
     * Veolab 3 (HeredarComercialPresupuesto): al indicar el presupuesto, la
     * planificación sin comercial toma el del presupuesto, salvo que la
     * petición indique el comercial (aunque sea vacío).
     */
    private function inheritCommercial(array $data, ?object $current): array
    {
        if (! $this->supports('empleado_comercial_codigo') || empty($data['presupuesto_codigo'])
            || array_key_exists('empleado_comercial_codigo', $data) || (int) ($current->EMC2COD ?? 0) !== 0) {
            return $data;
        }
        $budget = DB::connection('dynamic')->table('FACPRE')
            ->where('DEL3COD', (string) ($data['presupuesto_delegacion'] ?? ''))
            ->where('PRE1SER', (string) ($data['presupuesto_serie'] ?? ''))
            ->where('PRE1COD', (int) $data['presupuesto_codigo'])
            ->first(['EMP2DEL', 'EMP2COD']);
        if ($budget && (int) $budget->EMP2COD !== 0) {
            $data['empleado_comercial_delegacion'] = (string) $budget->EMP2DEL;
            $data['empleado_comercial_codigo'] = (int) $budget->EMP2COD;
        }

        return $data;
    }

    /**
     * Veolab 3: valores predeterminados y fórmulas (LABAUT.AUTCPRE) de los
     * autodefinibles y de los campos fijos de recolección, como la ficha
     * (ver VeolabCustomFields::applyDefaults).
     */
    private function applyFieldDefaults(array $data, ?object $current, string $delegation, array $customFields, array $keys): array
    {
        $isNew = $current === null;
        if ($isNew) {
            $stored = [];
            $services = array_map(fn ($s) => [(string) ($s['delegacion'] ?? ''), (string) $s['codigo']], $data['servicios'] ?? []);
        } else {
            $stored = VeolabCustomFields::stored('LABPLO', [$keys['delegacion'], (int) $keys['codigo']], $delegation);
            $services = DB::connection('dynamic')->table('LABPYS')
                ->where('PLO3DEL', $keys['delegacion'])->where('PLO3COD', (int) $keys['codigo'])
                ->get(['SER3DEL', 'SER3COD'])->map(fn ($s) => [(string) $s->SER3DEL, (string) $s->SER3COD])->all();
        }

        [$changedCustom, $changedFixed] = VeolabCustomFields::applyDefaults('LABPLO', $delegation, $customFields, $stored,
            VeolabCustomFields::fixedValues('LABPLO', $data, $current, $this->mapping),
            VeolabCustomFields::fixedGiven('LABPLO', $data, $this->mapping),
            $services, $isNew, $isNew);

        return [
            array_replace($data, VeolabCustomFields::fixedToData('LABPLO', $changedFixed, $this->mapping)),
            $changedCustom + $customFields,
        ];
    }

    /**
     * Aviso en la agenda: necesita fecha y, con número, la unidad. Deja en
     * '_agenda' si hay que rehacer el evento (FichaPlanificacion.Grabar: al
     * crear con aviso o al cambiar el aviso, la fecha o la periodicidad).
     */
    private function checkWarning(array $data, array $keys): array
    {
        $before = $keys ? DB::connection('dynamic')->table('LABPLO')
            ->where('DEL3COD', $keys['delegacion'])->where('PLO1COD', $keys['codigo'])->first() : null;
        $warning = $data['es_aviso'] ?? $before->PLOBAVI ?? 'F';
        $number = (int) ($data['aviso_numero'] ?? $before->PLONAVI ?? 0);
        $unit = (string) ($data['aviso_unidad'] ?? $before->PLOCAVI ?? '');
        $frequency = (int) ($data['periodicidad'] ?? $before->PLONFRE ?? self::NO_DATE);
        $warningGiven = array_key_exists('es_aviso', $data) || array_key_exists('aviso_numero', $data)
            || array_key_exists('aviso_unidad', $data);

        if ($warning === 'T' && $frequency === self::NO_DATE) {
            throw new BusinessRuleException('El aviso en la agenda necesita la fecha de inicio');
        }
        if ($warningGiven && $number > 0 && $unit === '') {
            throw new BusinessRuleException('Falta la unidad del aviso (M, H, D o S)');
        }

        $data['_agenda'] = $keys ? ($warningGiven || isset($data['_fechas'])) : $warning === 'T';

        return $data;
    }

    /**
     * Fecha y periodicidad (FichaPlanificacion.Grabar): sin fecha, fecha única
     * o periódica. Sin 'repeticion' se conserva la periodicidad que tenía (al
     * cambiar solo la fecha de una periódica, se regenera desde la nueva).
     * Deja en '_fechas' la lista de fechas a generar.
     */
    private function applyDate(array $data, string $delegation, ?object $before): array
    {
        $repeat = ! empty($data['repeticion']) ? VeolabPeriodicity::normalize($data['repeticion']) : null;
        if ($repeat === null && ! array_key_exists('repeticion', $data) && $before && (int) $before->PLONFRE > self::SINGLE_DATE) {
            $repeat = [
                'frecuencia' => (int) $before->PLONFRE, 'opcion' => (int) $before->PLONOPC, 'repetir' => (int) $before->PLONREP,
                'ordinal' => (int) $before->PLONORD, 'dias' => (int) $before->PLONSEM,
                'fecha_fin' => $before->PLODFIN, 'repeticiones' => (int) $before->PLONINR, 'trasladar' => $before->PLOBLAB === 'T',
            ];
        }
        $start = array_key_exists('fecha_inicio', $data) ? $data['fecha_inicio'] : ($before->PLODINI ?? null);
        if (! array_key_exists('fecha_inicio', $data) && $start && in_array(substr((string) $start, 11, 8), ['', '00:00:00'], true)) {
            // Veolab guarda en PLODINI solo el día: la hora es la de sus fechas
            // (la ficha la carga de la fecha planificada).
            $first = DB::connection('dynamic')->table('LABFEP')
                ->where('PLO3DEL', $before->DEL3COD)->where('PLO3COD', $before->PLO1COD)
                ->whereNotNull('FEPTINI')->orderBy('FEPTINI')->value('FEPTINI');
            if ($first) {
                $start = substr((string) $start, 0, 10).' '.substr((string) $first, 11, 8);
            }
        }
        unset($data['repeticion']);

        if (empty($start)) {
            if ($repeat && $repeat['frecuencia'] > 0) {
                throw new BusinessRuleException('La periodicidad necesita la fecha de inicio');
            }
            $repeat = null;
            $data['periodicidad'] = self::NO_DATE;
            $data['fecha_inicio'] = null;
            $dates = [];
        } else {
            $startDate = new \DateTimeImmutable($start);
            $data['fecha_inicio'] = $startDate->format('Y-m-d H:i:s');
            if ($repeat && $repeat['frecuencia'] > 0) {
                $dates = (new VeolabPeriodicity($delegation))->dates($startDate, $repeat['frecuencia'], $repeat['opcion'],
                    $repeat['repetir'], $repeat['ordinal'], $repeat['dias'], $repeat['fecha_fin'], $repeat['repeticiones'],
                    $repeat['trasladar'], VeolabPeriodicity::horizon());
                if (! $dates) {
                    throw new BusinessRuleException('La periodicidad no genera ninguna fecha');
                }
                $dates = array_map(fn ($d) => $d->format('Y-m-d H:i:s'), $dates);
                $data['periodicidad'] = $repeat['frecuencia'];
            } else {
                $repeat = null;
                $data['periodicidad'] = self::SINGLE_DATE;
                $dates = [$data['fecha_inicio']];
            }
        }

        $data['periodicidad_opcion'] = $repeat['opcion'] ?? 0;
        $data['periodicidad_repetir'] = $repeat['repetir'] ?? 0;
        $data['periodicidad_ordinal'] = $repeat['ordinal'] ?? 0;
        $data['periodicidad_dia_semana'] = $repeat['dias'] ?? 0;
        $data['periodicidad_fecha_fin'] = $repeat['fecha_fin'] ?? null;
        $data['periodicidad_repeticiones'] = $repeat['repeticiones'] ?? 0;
        $data['periodicidad_laborable'] = ($repeat['trasladar'] ?? false) ? 'T' : 'F';
        $data['_fechas'] = $dates;

        return $data;
    }

    /** Tras crear/modificar: servicios (alta), fechas, autodefinibles y evento de agenda. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];

        if ($data['_nueva'] && ! empty($data['servicios'])) {
            VeolabOperationServices::addToPlanning($del, $cod, $data['servicios'], $data);
        }

        if (isset($data['_fechas'])) {
            $this->regenerateDates($del, $cod, $data['_fechas'], $data['_nueva']);
        }

        VeolabCustomFields::save('LABPLO', [$del, $cod], $data['_autodefinibles'] ?? [], $this->auditRow($keys), $data['_nueva']);

        if ($data['_agenda']) {
            VeolabAgenda::syncPlanning($del, $cod);
        }

        $this->syncMetrology($del, $cod);

        return $data;
    }

    /** Veolab 3: próxima calibración, verificación o mantenimiento de la serie de la planificación. */
    private function syncMetrology(string $del, int $cod): void
    {
        VeolabStock::syncOperationMetrology(DB::connection('dynamic')->table('LABPLO')
            ->where('DEL3COD', $del)->where('PLO1COD', $cod)
            ->first(['TIO2DEL', 'TIO2COD', 'PRD2DEL', 'PRD2COD', 'SEL2COD']));
    }

    /**
     * Fechas planificadas (FichaPlanificacion.Grabar, "Periodicidad"): se
     * desactivan las no completadas (FEPTINI = NULL, se conservan por el
     * vínculo de códigos de barras) y se crean las nuevas, salvo las que ya
     * están completadas en esa misma fecha.
     */
    private function regenerateDates(string $del, int $cod, array $dates, bool $isNew): void
    {
        $db = DB::connection('dynamic');
        $plan = ['PLO3DEL' => $del, 'PLO3COD' => $cod];

        if (! $isNew) {
            $db->table('LABFEP')->where($plan)
                ->where(fn ($q) => $q->whereNull('FEPBCOM')->orWhere('FEPBCOM', 'F'))
                ->update(['FEPTINI' => null]);
        }

        $completed = $db->table('LABFEP')->where($plan)->where('FEPBCOM', 'T')->whereNotNull('FEPTINI')
            ->pluck('FEPTINI')->map(fn ($d) => substr((string) $d, 0, 19))->flip()->all();

        foreach ($dates as $date) {
            if (isset($completed[$date])) {
                continue;
            }
            do {
                $code = VeolabCodes::next('LABFEP', '', $del);
            } while ($db->table('LABFEP')->where($plan)->where('FEP1COD', $code)->exists());

            $db->table('LABFEP')->insert($plan + ['FEP1COD' => $code, 'FEPTINI' => $date]);
        }
    }

    /** ¿Los servicios dados son los de la planificación (misma delegación, código y orden)? */
    private function sameServices(array $keys, array $given): bool
    {
        $current = DB::connection('dynamic')->table('LABPYS')
            ->where('PLO3DEL', (string) ($keys['delegacion'] ?? ''))->where('PLO3COD', (int) $keys['codigo'])
            ->orderBy('PYSNPOS')->get(['SER3DEL', 'SER3COD'])
            ->map(fn ($s) => (string) $s->SER3DEL."\x1B".(string) $s->SER3COD)->all();
        $given = array_map(fn ($s) => (string) ($s['delegacion'] ?? '')."\x1B".(string) ($s['codigo'] ?? ''), $given);

        return $current === $given;
    }

    /**
     * Cada planificación del listado lleva sus fechas activas
     * ([{codigo, fecha, completada}]), sus servicios en el orden de la
     * rejilla ([{delegacion, codigo, nombre}]) y sus autodefinibles ({nombre: valor}).
     */
    protected function appendRelatedData(array $rows): array
    {
        $keys = array_map(fn ($row) => [(string) $row['delegacion'], (int) $row['codigo']], $rows);
        $values = VeolabCustomFields::valuesFor('LABPLO', $keys);

        $dates = [];
        if ($keys) {
            $found = DB::connection('dynamic')->table('LABFEP')
                ->where(function ($q) use ($keys) {
                    foreach ($keys as [$del, $cod]) {
                        $q->orWhere(fn ($w) => $w->where('PLO3DEL', $del)->where('PLO3COD', $cod));
                    }
                })
                ->whereNotNull('FEPTINI')
                ->orderBy('FEPTINI')->orderBy('FEP1COD')
                ->get(['PLO3DEL', 'PLO3COD', 'FEP1COD', 'FEPTINI', 'FEPBCOM']);
            foreach ($found as $d) {
                $dates[$d->PLO3DEL."\x1B".$d->PLO3COD][] = [
                    'codigo'     => (int) $d->FEP1COD,
                    'fecha'      => $d->FEPTINI,
                    'completada' => $d->FEPBCOM === 'T' ? 'T' : 'F',
                ];
            }
        }

        // Servicios de la rejilla (LABPYS) en su orden, con el nombre del servicio
        $services = [];
        if ($keys) {
            $found = DB::connection('dynamic')->table('LABPYS as p')
                ->leftJoin('LABSER as s', function ($j) {
                    $j->on('s.DEL3COD', '=', 'p.SER3DEL')->on('s.SER1COD', '=', 'p.SER3COD');
                })
                ->where(function ($q) use ($keys) {
                    foreach ($keys as [$del, $cod]) {
                        $q->orWhere(fn ($w) => $w->where('p.PLO3DEL', $del)->where('p.PLO3COD', $cod));
                    }
                })
                ->orderBy('p.PYSNPOS')
                ->get(['p.PLO3DEL', 'p.PLO3COD', 'p.SER3DEL', 'p.SER3COD', 's.SERCNOM']);
            foreach ($found as $s) {
                $services[$s->PLO3DEL."\x1B".$s->PLO3COD][] = [
                    'delegacion' => (string) $s->SER3DEL,
                    'codigo'     => (string) $s->SER3COD,
                    'nombre'     => $s->SERCNOM,
                ];
            }
        }

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".$row['codigo'];
            $row['fechas'] = $dates[$key] ?? [];
            $row['servicios'] = $services[$key] ?? [];
            $row['repeticion'] = VeolabPeriodicity::describe((int) $row['periodicidad'], (int) $row['periodicidad_opcion'],
                (int) $row['periodicidad_repetir'], (int) $row['periodicidad_ordinal'], (int) $row['periodicidad_dia_semana'],
                $row['periodicidad_fecha_fin'], (int) $row['periodicidad_repeticiones'], $row['periodicidad_laborable'] === 'T');
            $row['autodefinibles'] = (object) ($values[$key] ?? []);
        }

        return $rows;
    }

    /**
     * PUT /planificaciones/fechas?delegacion=&codigo=&fecha= con
     * {"completada": "T"|"F"}: marca una fecha como generada o pendiente
     * (Planificaciones.MarcarGenerada), con su suceso de auditoría.
     */
    public function markDate(Request $request)
    {
        foreach (['codigo', 'fecha'] as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }
        $del = (string) ($request->query('delegacion') ?? '');
        $cod = (int) $request->query('codigo');
        $date = (int) $request->query('fecha');

        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, ['completada' => 'required|string|in:T,F']);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $where = ['PLO3DEL' => $del, 'PLO3COD' => $cod, 'FEP1COD' => $date];
            if (! $db->table('LABFEP')->where($where)->whereNotNull('FEPTINI')->lockForUpdate()->exists()) {
                $db->rollBack();

                return response()->json(['message' => 'Fecha de planificación no encontrada'], 404);
            }

            $db->table('LABFEP')->where($where)->update(['FEPBCOM' => $body['completada']]);
            VeolabAudit::record(VeolabAudit::MODIFICACION, $this->table,
                $this->auditRow(['delegacion' => $del, 'codigo' => $cod]),
                'LABFEPFEPBCOM', $body['completada'] === 'T' ? 'Sí' : 'No');
            $this->syncMetrology($del, $cod);

            $db->commit();

            return response()->json(['message' => 'Fecha actualizada correctamente']);
        } catch (\Throwable $e) {
            $db->rollBack();
            return ServerError::response('v2 planificaciones/fechas', $e, 'Error al actualizar la fecha');
        }
    }

    /**
     * Cascada de Planificaciones.Borrar: desvincula sus operaciones, envía los
     * documentos a la papelera, borra autodefinibles, fechas, servicios,
     * técnicas y gastos (Veolab no borra estos tres) y los eventos de agenda.
     */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];
        $db = DB::connection('dynamic');

        $db->table('LABOPE')->where('PLO2DEL', $del)->where('PLO2COD', $cod)
            ->update(['PLO2DEL' => '', 'PLO2COD' => 0]);
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('PLO2COD', $cod)
            ->update(VeolabDocuments::trashUpdate($del));

        foreach (['LABPYA', 'LABFEP', 'LABPYS', 'LABPYT', 'LABPYG'] as $table) {
            $db->table($table)->where('PLO3DEL', $del)->where('PLO3COD', $cod)->delete();
        }

        // Eventos de agenda (AGE_BorrarEventoCalendarioPlanificaciones).
        VeolabAgenda::deletePlanning($del, $cod);
    }
}
