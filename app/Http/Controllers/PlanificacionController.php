<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\ServerError;
use App\Support\VeolabAgenda;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabCustomFields;
use App\Support\VeolabOperationServices;
use App\Support\VeolabPeriodicity;
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
     * 'servicios' solo al crear. 'autodefinibles': {"nombre": valor}.
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
            'servicios'                      => 'nullable|array|min:1',
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
            $data = $this->applyClientTariff($data);
            $this->checkServicesExist($data['servicios'] ?? []);
            $data = $this->applyDate($data, $delegation, null);
        } else {
            if (array_key_exists('servicios', $data)) {
                throw new BusinessRuleException('Los servicios solo se indican al crear la planificación');
            }
            if (array_key_exists('fecha_inicio', $data) || array_key_exists('repeticion', $data)) {
                $before = DB::connection('dynamic')->table('LABPLO')
                    ->where('DEL3COD', $keys['delegacion'])->where('PLO1COD', $keys['codigo'])->first();
                $data = $this->applyDate($data, $delegation, $before);
            }
        }

        $data = $this->checkWarning($data, $keys);
        $data['_autodefinibles'] = $customFields;
        $data['_nueva'] = $isNew;

        return $data;
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

        return $data;
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

    /**
     * Cada planificación del listado lleva sus fechas activas
     * ([{codigo, fecha, completada}]) y sus autodefinibles ({nombre: valor}).
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

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".$row['codigo'];
            $row['fechas'] = $dates[$key] ?? [];
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
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);

        foreach (['LABPYA', 'LABFEP', 'LABPYS', 'LABPYT', 'LABPYG'] as $table) {
            $db->table($table)->where('PLO3DEL', $del)->where('PLO3COD', $cod)->delete();
        }

        // Eventos de agenda (AGE_BorrarEventoCalendarioPlanificaciones).
        VeolabAgenda::deletePlanning($del, $cod);
    }
}
