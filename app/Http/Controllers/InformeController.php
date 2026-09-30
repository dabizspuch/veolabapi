<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabLicense;
use App\Support\VeolabReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Informes (LABINF) con sus operaciones (LABIYO) y sus firmas (LABFIR).
 * Réplica de FichaInforme/Informes de Veolab (ver VeolabReports):
 *
 *  - Un informe lleva al menos una operación. Un informe final traslada sus
 *    fechas y su estado a las operaciones (finalizada, validada, enviada).
 *  - Operaciones históricas (LABIYO.IYOBHIS = 'T'): segunda lista de la
 *    ficha, con operaciones anteriores que el informe muestra como histórico
 *    al exportarlo (p. ej. gráficas de evolución). No son del informe: ni
 *    reciben sus fechas o su estado ni cuentan para las firmas.
 *  - El estado de validación (P pendiente, V validado, R rechazado) lo
 *    deciden las firmas: PUT /informes/firmas. La API no tiene usuario, así
 *    que quien firma se indica en la petición (usuario de Veolab) y no se
 *    comprueban sus privilegios. A mano solo se cambia si LABCON.CONBBEV no
 *    lo bloquea.
 *  - Informe firmado (alguna firma total y ningún rechazo): no se cambian la
 *    fecha de creación, acreditado/final/visible ni las operaciones y, con
 *    LABCON.CONBBLI, tampoco observaciones, opiniones ni normativa.
 *  - Un informe validado no se borra.
 *  - De Veolab: el documento del informe, las marcas de resultados y las
 *    técnicas exportables/acreditadas de la rejilla, y las notificaciones.
 */
class InformeController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABINF';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'INF1SER',
        'codigo'     => 'INF1COD',
    ];
    protected array $searchFields = ['INFCOBS', 'INFCOEI'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    protected array $foreignKeys = [
        'usuario_validacion' => 'string',
        'forma_envio'        => 'int',
        'normativa'          => 'string',
        'ultima_firma'       => 'int',
    ];

    protected array $mapping = [
        'delegacion'                    => 'DEL3COD',
        'serie'                         => 'INF1SER',
        'codigo'                        => 'INF1COD',
        'estado_validacion'             => 'INFCVAL',
        'fecha_creacion'                => 'INFDCRE',
        'fecha_envio'                   => 'INFDENV',
        'fecha_validacion'              => 'INFDVAL',
        'es_acreditado'                 => 'INFBACR',
        'es_final'                      => 'INFBFIN',
        'es_visible'                    => 'INFBVIS',
        'visto_cliente'                 => 'INFBVIC',
        'opiniones'                     => 'INFCOEI',
        'observaciones'                 => 'INFCOBS',
        'usuario_validacion_delegacion' => 'USU2DEL',
        'usuario_validacion_codigo'     => 'USU2COD',
        'forma_envio_delegacion'        => 'FDE2DEL',
        'forma_envio_codigo'            => 'FDE2COD',
        'normativa_delegacion'          => 'NOR2DEL',
        'normativa_codigo'              => 'NOR2COD',
        'ultima_firma_delegacion'       => 'TIF2DEL',
        'ultima_firma_codigo'           => 'TIF2COD',
    ];

    /** Campos bloqueados con el informe firmado, y los que añade CONBBLI. */
    private const LOCKED_WHEN_SIGNED = ['fecha_creacion', 'es_acreditado', 'es_final', 'es_visible'];
    private const LOCKED_BY_CONFIG = ['observaciones', 'opiniones', 'normativa_delegacion', 'normativa_codigo'];

    /** Estados de validación como los audita Veolab. */
    private const STATE_NAMES = ['P' => 'Pendiente', 'V' => 'Validado', 'R' => 'Rechazado'];

    /**
     * Sin regla (solo lectura): visto_cliente y ultima_firma_*.
     * 'operaciones': [{delegacion, serie, codigo}] (obligatoria al crear; en
     * PUT sustituye la lista). 'operaciones_historicas': igual, opcional y
     * puede ir vacía. estado_validacion, fecha_validacion y
     * usuario_validacion_* solo para la validación manual.
     */
    protected function rules(): array
    {
        return [
            'delegacion'                    => 'nullable|string|max:10',
            'serie'                         => 'nullable|string|max:10',
            'codigo'                        => 'nullable|integer|min:1',
            'estado_validacion'             => 'sometimes|string|in:P,V,R',
            'fecha_creacion'                => 'nullable|date',
            'fecha_envio'                   => 'nullable|date',
            'fecha_validacion'              => 'nullable|date',
            'es_acreditado'                 => 'sometimes|string|in:T,F',
            'es_final'                      => 'sometimes|string|in:T,F',
            'es_visible'                    => 'sometimes|string|in:T,F',
            'opiniones'                     => 'nullable|string',
            'observaciones'                 => 'nullable|string',
            'usuario_validacion_delegacion' => 'nullable|string|max:10',
            'usuario_validacion_codigo'     => 'nullable|string|max:15',
            'forma_envio_delegacion'        => 'nullable|string|max:10',
            'forma_envio_codigo'            => 'nullable|integer',
            'normativa_delegacion'          => 'nullable|string|max:10',
            'normativa_codigo'              => 'nullable|string|max:20',
            'operaciones'                   => 'sometimes|array|min:1',
            'operaciones.*.delegacion'      => 'nullable|string|max:10',
            'operaciones.*.serie'           => 'nullable|string|max:10',
            'operaciones.*.codigo'          => 'required|integer|min:1',
            'operaciones_historicas'              => 'sometimes|array',
            'operaciones_historicas.*.delegacion' => 'nullable|string|max:10',
            'operaciones_historicas.*.serie'      => 'nullable|string|max:10',
            'operaciones_historicas.*.codigo'     => 'required|integer|min:1',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);

        foreach (array_merge($data['operaciones'] ?? [], $data['operaciones_historicas'] ?? []) as $operation) {
            [$del, $ser, $cod] = self::operationKey($operation);
            $this->mustExist('LABOPE', ['DEL3COD' => $del, 'OPE1SER' => $ser, 'OPE1COD' => $cod],
                "La operación {$cod} no existe");
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isNew = empty($keys);
        $db = DB::connection('dynamic');
        $current = null;

        $operations = self::operationList($data, 'operaciones');
        $historical = self::operationList($data, 'operaciones_historicas');
        unset($data['operaciones'], $data['operaciones_historicas']);

        foreach (['fecha_creacion', 'fecha_envio', 'fecha_validacion'] as $param) {
            // La ficha guarda estas fechas sin hora.
            if (! empty($data[$param])) {
                $data[$param] = (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        if ($isNew) {
            if (! $operations) {
                throw new BusinessRuleException('Es obligatorio vincular al menos una operación');
            }
            $data = $this->applyDefaults($data, $operations);
        } else {
            $report = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
            $current = $db->table($this->table)
                ->where('DEL3COD', $report[0])->where('INF1SER', $report[1])->where('INF1COD', $report[2])->first();
            if ($current) {
                $this->assertEditable($data, $operations, $historical, $current, $report);
            }
        }

        $data = $this->applyManualValidation($data, $current);

        if ($operations !== null) {
            $data['_operaciones'] = $operations;
        }
        if ($historical !== null) {
            $data['_historicas'] = $historical;
        }
        $data['_nuevo'] = $isNew;
        // El estado de las operaciones se revisa al crear y al cambiar las
        // operaciones, el envío, la validación o el carácter de final.
        $data['_sincronizar'] = $isNew || $operations !== null || $historical !== null
            || array_intersect(['fecha_envio', 'estado_validacion', 'es_final'], array_keys($data)) !== [];

        return $data;
    }

    /** Valores por defecto de un informe nuevo (FichaInforme / CrearInformeAuto). */
    private function applyDefaults(array $data, array $operations): array
    {
        $data['estado_validacion'] ??= 'P';
        $data['es_final'] ??= 'T';
        $data['es_visible'] ??= 'T';
        $data['es_acreditado'] ??= VeolabReports::hasAccreditedTechniques($operations) ? 'T' : 'F';
        if (! array_key_exists('fecha_creacion', $data)) {
            $data['fecha_creacion'] = DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
        }

        if (! array_key_exists('normativa_codigo', $data) && ($regulation = VeolabReports::defaultRegulation($operations))) {
            [$data['normativa_delegacion'], $data['normativa_codigo']] = $regulation;
        }
        if (! array_key_exists('forma_envio_codigo', $data) && ($delivery = VeolabReports::defaultDelivery($operations))) {
            [$data['forma_envio_delegacion'], $data['forma_envio_codigo']] = $delivery;
        }
        if (! array_key_exists('opiniones', $data)) {
            $data['opiniones'] = VeolabReports::defaultOpinion($operations, ! empty($data['normativa_codigo']));
        }

        return $data;
    }

    /**
     * EstadoControlesPostLectura: con el informe firmado no cambian sus datos
     * (salvo envío y forma de envío) ni sus operaciones, históricas incluidas.
     */
    private function assertEditable(array $data, ?array $operations, ?array $historical, object $current, array $report): void
    {
        if (! VeolabReports::isSigned(VeolabReports::signatureTypes($report))) {
            return;
        }

        $locked = self::LOCKED_WHEN_SIGNED;
        if (DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONBBLI') === 'T') {
            $locked = array_merge($locked, self::LOCKED_BY_CONFIG);
        }

        foreach ($locked as $param) {
            if (array_key_exists($param, $data)
                && VeolabAudit::value($data[$param]) !== VeolabAudit::value($current->{$this->mapping[$param]})) {
                throw new BusinessRuleException("El informe está firmado: no se puede modificar '{$param}'");
            }
        }

        foreach ([[$operations, false], [$historical, true]] as [$given, $isHistorical]) {
            if ($given === null) {
                continue;
            }
            $stored = VeolabReports::operations($report, $isHistorical);
            sort($given);
            sort($stored);
            if ($given !== $stored) {
                throw new BusinessRuleException('El informe está firmado: no se pueden cambiar sus operaciones');
            }
        }
    }

    /**
     * Validación manual (cmbEstadoValidacion): validar o rechazar apunta la
     * fecha y el usuario que valida; volver a pendiente los borra. Sin
     * cambio de estado, fecha y usuario de validación no se tocan.
     */
    private function applyManualValidation(array $data, ?object $current): array
    {
        $state = $data['estado_validacion'] ?? null;
        $changes = $state !== null && $state !== (string) ($current->INFCVAL ?? '');
        $userGiven = array_key_exists('usuario_validacion_codigo', $data) || array_key_exists('usuario_validacion_delegacion', $data);

        if (! $changes || ($current === null && $state === 'P')) {
            if (array_key_exists('fecha_validacion', $data) || $userGiven) {
                throw new BusinessRuleException('La fecha y el usuario de validación solo se indican al validar o rechazar el informe');
            }

            return $data;
        }

        if (DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONBBEV') === 'T') {
            throw new BusinessRuleException('El estado de validación solo cambia con las firmas (configuración del laboratorio)');
        }
        if ($current && VeolabReports::isSigned(VeolabReports::signatureTypes(
            [(string) $current->DEL3COD, (string) $current->INF1SER, (int) $current->INF1COD]))) {
            throw new BusinessRuleException('El informe está firmado: su estado de validación solo cambia con las firmas');
        }

        if ($state === 'P') {
            $data['fecha_validacion'] = null;
            $data['usuario_validacion_delegacion'] = null;
            $data['usuario_validacion_codigo'] = null;

            return $data;
        }

        if (empty($data['usuario_validacion_codigo'])) {
            throw new BusinessRuleException('Para validar o rechazar hay que indicar el usuario que valida');
        }
        if (empty($data['fecha_validacion'])) {
            $data['fecha_validacion'] = DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
        }

        return $data;
    }

    /** Tras crear/modificar: operaciones del informe y su estado. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $report = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $db = DB::connection('dynamic');
        $reportKey = array_combine(['INF3DEL', 'INF3SER', 'INF3COD'], $report);

        // Cada lista (en vigor / históricas) se sustituye solo si se indica.
        foreach (['_operaciones' => 'F', '_historicas' => 'T'] as $list => $flag) {
            if (! isset($data[$list])) {
                continue;
            }
            $db->table('LABIYO')->where($reportKey)
                ->where(fn ($q) => $flag === 'T'
                    ? $q->where('IYOBHIS', 'T')
                    : $q->whereNull('IYOBHIS')->orWhere('IYOBHIS', '<>', 'T'))
                ->delete();
            foreach ($data[$list] as [$del, $ser, $cod]) {
                $db->table('LABIYO')->insert($reportKey
                    + ['OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod, 'IYOBHIS' => $flag]);
            }
        }
        if (! $data['_nuevo'] && (isset($data['_operaciones']) || isset($data['_historicas']))) {
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $this->auditRow($keys), 'LABIYO');
        }

        if ($data['_sincronizar']) {
            VeolabReports::syncOperations($report);
        }
        $this->resetIgeoIfNotSent($report);

        return $data;
    }

    /**
     * RestablecerEstadoOperacionRecibidaIgeo: sin fecha de envío, las
     * operaciones ya enviadas a IGEO vuelven a "recibida" para reenviarse.
     */
    private function resetIgeoIfNotSent(array $report): void
    {
        $db = DB::connection('dynamic');
        $sent = $db->table($this->table)
            ->where('DEL3COD', $report[0])->where('INF1SER', $report[1])->where('INF1COD', $report[2])->value('INFDENV');
        $operations = VeolabReports::operations($report);

        if ($sent === null && $operations && VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'IGE')) {
            VeolabReports::whereOperations($db->table('LABOPE'), $operations)
                ->whereIn('OPECIGE', ['E', 'I'])->update(['OPECIGE' => 'R']);
        }
    }

    /**
     * Cada informe del listado lleva sus operaciones y sus operaciones
     * históricas ([{delegacion, serie, codigo}]) y sus firmas (filas de LABFIR).
     */
    protected function appendRelatedData(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $db = DB::connection('dynamic');
        $ofReports = function ($q) use ($rows) {
            foreach ($rows as $row) {
                $q->orWhere(fn ($w) => $w->where('INF3DEL', (string) $row['delegacion'])
                    ->where('INF3SER', (string) $row['serie'])->where('INF3COD', (int) $row['codigo']));
            }
        };
        $reportKey = fn ($r) => $r->INF3DEL."\x1B".$r->INF3SER."\x1B".$r->INF3COD;

        $operations = [];
        foreach ($db->table('LABIYO')->where($ofReports)
            ->orderBy('OPE3DEL')->orderBy('OPE3SER')->orderBy('OPE3COD')->get() as $r) {
            $operations[$reportKey($r)][$r->IYOBHIS === 'T' ? 'T' : 'F'][] = [
                'delegacion' => (string) $r->OPE3DEL,
                'serie'      => (string) $r->OPE3SER,
                'codigo'     => (int) $r->OPE3COD,
            ];
        }

        $signatures = [];
        foreach ($db->table('LABFIR')->where($ofReports)
            ->orderBy('TIF3DEL')->orderBy('TIF3COD')->orderBy('DEP3DEL')->orderBy('DEP3COD')->get() as $r) {
            $partial = (int) $r->DEP3COD !== 0;
            $signatures[$reportKey($r)][] = [
                'tipo_firma_delegacion'   => (string) $r->TIF3DEL,
                'tipo_firma_codigo'       => (int) $r->TIF3COD,
                'departamento_delegacion' => $partial ? (string) $r->DEP3DEL : null,
                'departamento_codigo'     => $partial ? (int) $r->DEP3COD : null,
                'fecha'                   => $r->FIRDFEC,
                'firmada'                 => $r->FIRBVAL === 'T' ? 'T' : 'F',
                'comentario'              => $r->FIRCCOM,
                'usuario_delegacion'      => (string) $r->USU2DEL,
                'usuario_codigo'          => (string) $r->USU2COD,
            ];
        }

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".$row['serie']."\x1B".$row['codigo'];
            $row['operaciones'] = $operations[$key]['F'] ?? [];
            $row['operaciones_historicas'] = $operations[$key]['T'] ?? [];
            $row['firmas'] = $signatures[$key] ?? [];
        }

        return $rows;
    }

    /**
     * PUT /informes/firmas?delegacion=&serie=&codigo= con
     * {"accion": "firmar"|"rechazar"|"eliminar", "tipo_firma_delegacion": "",
     *  "tipo_firma_codigo": 1, "usuario_delegacion": "", "usuario_codigo": "...",
     *  "departamento_delegacion": "", "departamento_codigo": 2, "comentario": "..."}
     *
     * Firma, rechaza o quita la firma de un tipo de firma (FichaInforme:
     * Firmar, Rechazar, EliminarFirma), total o de un departamento del
     * informe, respetando el orden de las firmas obligatorias. Recalcula el
     * estado de validación y lo traslada a las operaciones.
     */
    public function sign(Request $request)
    {
        foreach (array_keys($this->keys) as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }
        $report = [(string) ($request->query('delegacion') ?? ''), (string) ($request->query('serie') ?? ''),
            (int) $request->query('codigo')];
        $keys = array_combine(array_keys($this->keys), $report);

        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'accion'                  => 'required|string|in:firmar,rechazar,eliminar',
            'tipo_firma_delegacion'   => 'nullable|string|max:10',
            'tipo_firma_codigo'       => 'required|integer|min:1',
            'usuario_delegacion'      => 'nullable|string|max:10',
            'usuario_codigo'          => 'required_unless:accion,eliminar|nullable|string|max:15',
            'departamento_delegacion' => 'nullable|string|max:10',
            'departamento_codigo'     => 'nullable|integer|min:1',
            'comentario'              => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $where = ['DEL3COD' => $report[0], 'INF1SER' => $report[1], 'INF1COD' => $report[2]];
            $current = $db->table($this->table)->where($where)->lockForUpdate()->first();
            if (! $current) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            $state = $this->applySignature($report, $body);
            $update = ['TIF2DEL' => $state['last'][0], 'TIF2COD' => $state['last'][1]];
            $row = $this->auditRow($keys);

            VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'LABFIR');

            $changed = $state['validation'] !== (string) $current->INFCVAL;
            if ($changed) {
                $validated = $state['validation'] !== 'P';
                $update += [
                    'INFCVAL' => $state['validation'],
                    'INFDVAL' => $validated ? $db->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00' : null,
                    'USU2DEL' => $validated ? (string) ($body['usuario_delegacion'] ?? '') : '',
                    'USU2COD' => $validated ? (string) ($body['usuario_codigo'] ?? '') : '',
                ];
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'LABINFINFCVAL',
                    self::STATE_NAMES[$state['validation']], self::STATE_NAMES[(string) $current->INFCVAL] ?? '');
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'LABINFINFDVAL',
                    VeolabAudit::value($update['INFDVAL']), VeolabAudit::value($current->INFDVAL));
            }
            $db->table($this->table)->where($where)->update($update);

            if ($changed) {
                VeolabReports::syncOperations($report);
            }

            $db->commit();

            return response()->json([
                'message' => 'Firmas actualizadas correctamente',
                'data'    => ['estado_validacion' => $state['validation']],
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();
            Log::error('v2 informes/firmas: '.$e->getMessage());

            return response()->json(['message' => 'Error al actualizar las firmas'], 500);
        }
    }

    /**
     * Aplica la acción sobre LABFIR y devuelve el estado de validación
     * resultante y el último tipo de firma aplicado.
     */
    private function applySignature(array $report, array $body): array
    {
        $db = DB::connection('dynamic');
        $action = $body['accion'];
        $typeDel = (string) ($body['tipo_firma_delegacion'] ?? '');
        $typeCod = (int) $body['tipo_firma_codigo'];
        $depDel = (string) ($body['departamento_delegacion'] ?? '');
        $depCod = (int) ($body['departamento_codigo'] ?? 0);
        $partial = $depCod !== 0;

        $types = VeolabReports::signatureTypes($report);
        $index = VeolabReports::typeIndex($types, $typeDel, $typeCod);
        if ($index === null) {
            throw new BusinessRuleException('El tipo de firma no existe o no está disponible para este informe');
        }

        if ($partial) {
            if (! in_array([$depDel, $depCod], VeolabReports::departments($report), true)) {
                throw new BusinessRuleException('El departamento no interviene en las operaciones del informe');
            }
            if (in_array($types[$index]['state'], [VeolabReports::FIRMADO, VeolabReports::RECHAZADO], true)) {
                throw new BusinessRuleException('El tipo de firma ya tiene una firma total');
            }
        }

        $signature = ['INF3DEL' => $report[0], 'INF3SER' => $report[1], 'INF3COD' => $report[2],
            'TIF3DEL' => $typeDel, 'TIF3COD' => $typeCod];

        if ($action === 'eliminar') {
            if (! VeolabReports::canRemove($types, $index)) {
                throw new BusinessRuleException('No se puede eliminar la firma: hay firmas obligatorias posteriores aplicadas');
            }
            $query = $db->table('LABFIR')->where($signature);
            if ($partial) {
                $query->where('DEP3DEL', $depDel)->where('DEP3COD', $depCod);
            }
            $query->delete();
            $newState = VeolabReports::PENDIENTE;
        } else {
            if (! VeolabReports::canSign($types, $index)) {
                throw new BusinessRuleException('No se puede firmar: hay firmas obligatorias anteriores pendientes');
            }
            $userDel = (string) ($body['usuario_delegacion'] ?? '');
            $user = $db->table('ACCUSU')->where('DEL3COD', $userDel)->where('USU1COD', $body['usuario_codigo'])->first(['USUBBAJ']);
            if (! $user || $user->USUBBAJ === 'T') {
                throw new BusinessRuleException('El usuario que firma no existe o está de baja');
            }

            $signature += ['DEP3DEL' => $partial ? $depDel : '', 'DEP3COD' => $depCod];
            $db->table('LABFIR')->where($signature)->delete();
            $db->table('LABFIR')->insert($signature + [
                'FIRDFEC' => DB::raw('CURDATE()'),
                'FIRBVAL' => $action === 'firmar' ? 'T' : 'R',
                'FIRCCOM' => (string) ($body['comentario'] ?? ''),
                'USU2DEL' => $userDel,
                'USU2COD' => (string) $body['usuario_codigo'],
            ]);
            $newState = $action === 'firmar'
                ? ($partial ? VeolabReports::FIRMADO_PARCIAL : VeolabReports::FIRMADO)
                : ($partial ? VeolabReports::RECHAZADO_PARCIAL : VeolabReports::RECHAZADO);
        }

        // Como en la ficha, el tipo de firma queda en el estado de la acción
        // aunque conserve firmas de otros departamentos.
        $types = VeolabReports::signatureTypes($report);
        $index = VeolabReports::typeIndex($types, $typeDel, $typeCod);
        if ($index !== null) {
            $types[$index]['state'] = $newState;
        }

        return [
            'validation' => VeolabReports::validationState($types, $report),
            'last'       => VeolabReports::lastSignedType($types),
        ];
    }

    /** BorradoFisicoPermitido: un informe validado no se borra. */
    protected function validateBeforeDelete(array $keys): void
    {
        $state = DB::connection('dynamic')->table($this->table)
            ->where('DEL3COD', (string) $keys['delegacion'])->where('INF1SER', (string) $keys['serie'])
            ->where('INF1COD', (int) $keys['codigo'])->value('INFCVAL');
        if ($state === 'V') {
            throw new BusinessRuleException('El informe está validado y no puede ser eliminado');
        }
    }

    /**
     * Cascada de BorrarInforme: quita la fecha de informe de sus operaciones
     * (no de las históricas, que son de otros informes; Veolab sí lo hace),
     * borra operaciones, firmas y notificaciones del informe y envía sus
     * documentos a la papelera.
     */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $db = DB::connection('dynamic');
        $reportKey = ['INF3DEL' => $del, 'INF3SER' => $ser, 'INF3COD' => $cod];

        $operations = VeolabReports::operations([$del, $ser, $cod]);
        if ($operations) {
            VeolabReports::whereOperations($db->table('LABOPE'), $operations)->update(['OPEDINF' => null]);
        }

        $db->table('LABIYO')->where($reportKey)->delete();
        $db->table('LABFIR')->where($reportKey)->delete();
        $db->table('ACCNOT')->where('INF2DEL', $del)->where('INF2SER', $ser)->where('INF2COD', $cod)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('INF2SER', $ser)->where('INF2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);
    }

    /** Lista de operaciones recibida sin repetidos, o null si no se indica. */
    private static function operationList(array $data, string $param): ?array
    {
        if (! array_key_exists($param, $data)) {
            return null;
        }

        $out = [];
        foreach ($data[$param] as $operation) {
            $key = self::operationKey($operation);
            $out[implode("\x1B", $key)] ??= $key;
        }

        return array_values($out);
    }

    private static function operationKey(array $operation): array
    {
        return [(string) ($operation['delegacion'] ?? ''), (string) ($operation['serie'] ?? ''), (int) $operation['codigo']];
    }
}
