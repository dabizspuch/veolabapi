<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAuditChecklists;
use App\Support\VeolabCodes;
use App\Support\VeolabQuality;
use App\Support\VeolabQualityAudits;
use App\Support\VeolabVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Lista de verificación de una auditoría (CALAUP, módulo CAL, desde Veolab
 * 3; ISO/IEC 17025 8.8): las preguntas copiadas de las listas (CALLVE) o
 * añadidas sueltas, con su resultado, evidencia y hallazgo. Sigue el modelo
 * común con el escritorio (App\Support\VeolabAuditChecklists).
 *
 *  - Ruta /auditorias-calidad/lista; clave = la de la auditoría
 *    (delegacion, serie, codigo) + linea. El GET exige la auditoría y
 *    devuelve en meta.resumen cuántas hay respondidas, no conformes,
 *    observaciones y no aplican; ?auv=n solo las del ensayo presenciado n
 *    (?auv=0, solo las de la auditoría).
 *  - POST añade una pregunta suelta (línea y orden siguientes); PUT cambia
 *    resultado (C conforme, N no conforme, O observación, X no aplica; vacío
 *    = sin responder), evidencia, orden, apartado, pregunta, qué revisar y
 *    hallazgo (una incidencia de la misma delegación enlazada a la
 *    auditoría); DELETE la quita. La lista de origen y el ensayo presenciado
 *    no se cambian.
 *  - POST /auditorias-calidad/lista/cargar copia las preguntas de una o
 *    varias listas (load()); POST /auditorias-calidad/lista/hallazgo crea la
 *    incidencia del hallazgo de una pregunta (createFinding()).
 *  - Necesita el módulo CAL para escribir. Cada cambio se audita como un cambio de la auditoría (campo
 *    CALAUP), como sus demás listas.
 */
class AuditoriaListaController extends BaseController
{
    protected string $table = 'CALAUP';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'AUI3SER',
        'codigo'     => 'AUI3COD',
        'linea'      => 'AUP1COD',
    ];

    protected array $mapping = [
        'delegacion'         => 'DEL3COD',
        'serie'              => 'AUI3SER',
        'codigo'             => 'AUI3COD',
        'linea'              => 'AUP1COD',
        'orden'              => 'AUPNORD',
        'ensayo_presenciado' => 'AUV2COD',   // Línea de CALAUV o NULL si es de la auditoría
        'apartado'           => 'AUPCAPA',
        'pregunta'           => 'AUPCPRE',
        'que_revisar'        => 'AUPCGUI',
        'resultado'          => 'AUPCRES',   // C, N, O, X; NULL sin responder
        'evidencia'          => 'AUPCEVI',
        'hallazgo_serie'     => 'INC2SER',   // Incidencia de la misma delegación
        'hallazgo_codigo'    => 'INC2COD',
        'lista'              => 'LVE2COD',   // Lista de origen (informativo)
    ];

    protected array $searchFields = ['AUPCPRE', 'AUPCGUI', 'AUPCEVI'];

    // Una lista completa cabe en una página.
    protected int $defaultPerPage = 500;
    protected int $maxPerPage = 1000;

    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabAuditChecklists::available()) {
            return response()->json(['message' => VeolabAuditChecklists::unavailableMessage()], 422);
        }

        return null;
    }

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'delegacion'         => 'nullable|string|max:10',
            'serie'              => 'nullable|string|max:10',
            'codigo'             => ($isCreating ? 'required' : 'sometimes').'|integer|min:1',
            'linea'              => 'nullable|integer|min:1',
            'orden'              => 'nullable|integer',
            'ensayo_presenciado' => 'nullable|integer|min:0',
            'apartado'           => 'nullable|string|max:10',
            'pregunta'           => ($isCreating ? 'required' : 'sometimes').'|string',
            'que_revisar'        => 'nullable|string',
            'resultado'          => 'nullable|string|in:'.implode(',', array_keys(VeolabAuditChecklists::RESULT_NAMES)),
            'evidencia'          => 'nullable|string',
            'hallazgo_serie'     => 'nullable|string|max:10',
            'hallazgo_codigo'    => 'nullable|integer|min:0',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = ! $keys;
        $source = $isNew ? $data : $keys;
        [$del, $ser, $cod] = [(string) ($source['delegacion'] ?? ''), (string) ($source['serie'] ?? ''), (int) ($source['codigo'] ?? 0)];
        VeolabAuditChecklists::lockAudit($del, $ser, $cod);
        $before = $isNew ? [] : (array) VeolabAuditChecklists::lines($del, $ser, $cod)->where('AUP1COD', (int) $keys['linea'])->first();
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        // Ensayo presenciado: al añadir, una línea de la auditoría (o ninguno); después no cambia
        $witnessed = (int) ($data['ensayo_presenciado'] ?? 0);
        if ($isNew) {
            if ($witnessed !== 0 && ! $db->table('CALAUV')->where('DEL3COD', $del)->where('AUI3SER', $ser)->where('AUI3COD', $cod)
                ->where('AUV1COD', $witnessed)->exists()) {
                throw new BusinessRuleException("El ensayo presenciado {$witnessed} no existe en la auditoría");
            }
            $data['ensayo_presenciado'] = $witnessed === 0 ? null : $witnessed;
        } elseif (array_key_exists('ensayo_presenciado', $data)) {
            if ($witnessed !== (int) ($before['AUV2COD'] ?? 0)) {
                throw new BusinessRuleException('El ensayo presenciado de una pregunta no se cambia: quítela y añádala en el otro');
            }
            unset($data['ensayo_presenciado']);
        }

        // Textos recortados; vacíos, NULL. La pregunta es obligatoria.
        foreach (['pregunta', 'que_revisar', 'evidencia'] as $param) {
            if (array_key_exists($param, $data)) {
                $text = trim((string) ($data[$param] ?? ''));
                $data[$param] = $text === '' ? null : $text;
            }
        }
        if (trim((string) $current('pregunta')) === '') {
            throw new BusinessRuleException('La pregunta es obligatoria');
        }

        // Apartado de la lista fija (o ninguno) y resultado (vacío = sin responder)
        if (array_key_exists('apartado', $data)) {
            $clause = trim((string) ($data['apartado'] ?? ''));
            if ($clause !== '' && ! VeolabQualityAudits::isClause($clause)) {
                throw new BusinessRuleException("El apartado '{$clause}' no es de la norma; use uno de: "
                    .implode(', ', array_map('strval', array_keys(VeolabQualityAudits::CLAUSES))));
            }
            $data['apartado'] = $clause === '' ? null : $clause;
        }
        if (array_key_exists('resultado', $data) && (string) ($data['resultado'] ?? '') === '') {
            $data['resultado'] = null;
        }

        // Hallazgo: una incidencia de la misma delegación enlazada a la auditoría
        if (array_key_exists('hallazgo_codigo', $data) || array_key_exists('hallazgo_serie', $data)) {
            $incSer = (string) ($current('hallazgo_serie') ?? '');
            $incCod = (int) $current('hallazgo_codigo');
            if ($incCod === 0) {
                $data['hallazgo_serie'] = null;
                $data['hallazgo_codigo'] = null;
            } else {
                if (! VeolabQuality::incident($del, $incSer, $incCod)) {
                    throw new BusinessRuleException('La incidencia '.VeolabCodes::format('CALINC', (string) $incCod, $del, $incSer)
                        .' no existe en la delegación de la auditoría');
                }
                $linked = $db->table('CALIYE')->where('DEL3COD', $del)->where('INC3SER', $incSer)->where('INC3COD', $incCod)
                    ->where('IYECTAB', VeolabQualityAudits::TABLE)->where('IYECDEL', $del)->where('IYECSER', $ser)
                    ->where('IYECCOD', (string) $cod)->exists();
                if (! $linked) {
                    throw new BusinessRuleException('La incidencia '.VeolabCodes::format('CALINC', (string) $incCod, $del, $incSer)
                        .' no es un hallazgo de esta auditoría: enlácela antes a la auditoría');
                }
                $data['hallazgo_serie'] = $incSer;
                $data['hallazgo_codigo'] = $incCod;
            }
        }

        if ($isNew) {
            $data['delegacion'] = $del;
            $data['serie'] = $ser;
            $data['linea'] = (int) VeolabAuditChecklists::lines($del, $ser, $cod)->max('AUP1COD') + 1;
            if (! isset($data['orden'])) {
                $group = VeolabAuditChecklists::lines($del, $ser, $cod);
                if ($data['ensayo_presenciado'] === null) {
                    $group->whereNull('AUV2COD');
                } else {
                    $group->where('AUV2COD', $data['ensayo_presenciado']);
                }
                $data['orden'] = (int) $group->max('AUPNORD') + 1;
            }
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /** Las preguntas de la auditoría y, en meta.resumen, su avance. */
    public function index(Request $request)
    {
        $response = parent::index($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }
        try {
            $payload = $response->getData(true);
            [$del, $ser, $cod] = [(string) ($request->query('delegacion') ?? ''), (string) ($request->query('serie') ?? ''), (int) $request->query('codigo')];
            $witnessed = self::witnessedFilter($request);
            $payload['meta']['resumen'] = VeolabAuditChecklists::summary($del, $ser, $cod, $witnessed);
            // Por apartado (marcador resumenapartadoslistaauditoria): sin ?auv=, solo las de la auditoría, como el escritorio
            $payload['meta']['resumen_apartados'] = VeolabAuditChecklists::summaryByClause($del, $ser, $cod, $witnessed ?? 0);

            return response()->json($payload);
        } catch (\Throwable $e) {
            return ServerError::response('v2 resumen CALAUP', $e, 'Error al calcular el resumen de la lista de verificación');
        }
    }

    /** ?auv=: null sin filtro, 0 solo las de la auditoría, n las del ensayo presenciado n. */
    private static function witnessedFilter(Request $request): ?int
    {
        $auv = $request->query('auv');
        if ($auv === null || $auv === '') {
            return null;
        }
        if (! is_string($auv) || ! ctype_digit($auv)) {
            throw new BusinessRuleException('El filtro auv no es un número');
        }

        return (int) $auv;
    }

    /**
     * Exige la auditoría (delegación y serie vacías si no se indican); ?auv=;
     * por defecto, las de la auditoría y luego las de cada ensayo, en su orden.
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $code = $request->query('codigo');
        if (! is_string($code) || ! ctype_digit($code)) {
            throw new BusinessRuleException('Indique la auditoría: delegacion, serie y codigo');
        }
        $query->where('CALAUP.DEL3COD', (string) ($request->query('delegacion') ?? ''))
            ->where('CALAUP.AUI3SER', (string) ($request->query('serie') ?? ''));
        $witnessed = self::witnessedFilter($request);
        if ($witnessed === 0) {
            $query->whereNull('CALAUP.AUV2COD');
        } elseif ($witnessed !== null) {
            $query->where('CALAUP.AUV2COD', $witnessed);
        }
        if (! $request->query('sort')) {
            $query->orderByRaw('COALESCE(CALAUP.AUV2COD, 0)')->orderBy('CALAUP.AUPNORD');
        }
    }

    /** Textos del apartado y del resultado, el parámetro del ensayo presenciado, la lista de origen y el hallazgo. */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $first = $rows[0];
        [$del, $ser, $cod] = [(string) $first['delegacion'], (string) $first['serie'], (int) $first['codigo']];

        $witnessed = $db->table('CALAUV')
            ->leftJoin('LABTEC', fn ($j) => $j->on('LABTEC.DEL3COD', '=', 'CALAUV.TEC2DEL')->on('LABTEC.TEC1COD', '=', 'CALAUV.TEC2COD'))
            ->where('CALAUV.DEL3COD', $del)->where('CALAUV.AUI3SER', $ser)->where('CALAUV.AUI3COD', $cod)
            ->get(['CALAUV.AUV1COD', 'CALAUV.TEC2COD', 'LABTEC.TECCNOM'])->keyBy(fn ($v) => (int) $v->AUV1COD);
        $listCodes = array_values(array_unique(array_filter(array_map(fn ($r) => (int) $r['lista'], $rows))));
        $lists = $listCodes ? $db->table(VeolabAuditChecklists::TABLE)->whereIn('LVE1COD', $listCodes)
            ->get(['LVE1COD', 'LVECDES'])->keyBy(fn ($l) => (int) $l->LVE1COD) : collect();
        $findings = $db->table('CALINC')
            ->where('DEL3COD', $del)
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    if ((int) $row['hallazgo_codigo'] !== 0) {
                        $q->orWhere(fn ($w) => $w->where('INC1SER', (string) $row['hallazgo_serie'])->where('INC1COD', (int) $row['hallazgo_codigo']));
                    }
                }
                $q->orWhereRaw('1 = 0');
            })
            ->get(['INC1SER', 'INC1COD', 'INCCDES', 'INCCEST', 'INCCGRV'])->keyBy(fn ($i) => $i->INC1SER."\x1B".(int) $i->INC1COD);

        foreach ($rows as &$row) {
            $auv = (int) $row['ensayo_presenciado'];
            $param = $auv !== 0 ? ($witnessed[$auv] ?? null) : null;
            $incCod = (int) $row['hallazgo_codigo'];
            $finding = $incCod !== 0 ? ($findings[$row['hallazgo_serie']."\x1B".$incCod] ?? null) : null;
            $state = $finding ? VeolabQuality::state($finding) : null;

            $row['apartado_titulo'] = VeolabQualityAudits::clauseTitle($row['apartado']) ?: null;
            $row['resultado_texto'] = VeolabAuditChecklists::resultName($row['resultado']);
            $row['ensayo_presenciado_parametro'] = $param ? (trim((string) $param->TECCNOM) !== '' ? trim((string) $param->TECCNOM) : $param->TEC2COD) : null;
            $row['lista_descripcion'] = (int) $row['lista'] !== 0 ? ($lists[(int) $row['lista']]->LVECDES ?? null) : null;
            $row['hallazgo_codigo_formateado'] = $incCod !== 0
                ? VeolabCodes::format('CALINC', (string) $incCod, (string) $row['delegacion'], (string) $row['hallazgo_serie']) : null;
            $row['hallazgo_descripcion'] = $finding->INCCDES ?? null;
            $row['hallazgo_clasificacion'] = $finding->INCCGRV ?? null;
            $row['hallazgo_estado'] = $state;
            $row['hallazgo_estado_texto'] = $state !== null ? (VeolabQuality::STATE_NAMES[$state] ?? $state) : null;
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Borrado
    // ------------------------------------------------------------------

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabAuditChecklists::lockAudit((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']);
    }

    // ------------------------------------------------------------------
    // Auditoría: suceso de la auditoría con su lista modificada
    // ------------------------------------------------------------------

    protected function auditCreated(array $data, array $keyParams): void
    {
        VeolabAuditChecklists::auditAudit((string) $keyParams['delegacion'], (string) $keyParams['serie'], (int) $keyParams['codigo']);
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        VeolabAuditChecklists::auditAudit((string) $keyParams['delegacion'], (string) $keyParams['serie'], (int) $keyParams['codigo']);
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        VeolabAuditChecklists::auditAudit((string) $keyParams['delegacion'], (string) $keyParams['serie'], (int) $keyParams['codigo']);
    }

    // ------------------------------------------------------------------
    // Cargar preguntas de las listas
    // ------------------------------------------------------------------

    /** Clave de la auditoría de la query string [delegación, serie, código], o null si falta el código. */
    private static function auditKey(Request $request): ?array
    {
        $code = $request->query('codigo');
        if (! is_string($code) || ! ctype_digit($code)) {
            return null;
        }

        return [(string) ($request->query('delegacion') ?? ''), (string) ($request->query('serie') ?? ''), (int) $code];
    }

    /**
     * POST /auditorias-calidad/lista/cargar?delegacion=&serie=&codigo=
     * Cuerpo {listas: [códigos], solo_alcance (T por defecto), auv}. Copia
     * a la auditoría (o a su ensayo presenciado auv) las preguntas de las
     * listas dadas (comunes o de la delegación de la auditoría, no de baja),
     * en su orden y detrás de las que ya tiene. Con solo_alcance, solo las de
     * los apartados del alcance (CALAUX) y las que no tienen apartado; si el
     * alcance está vacío, todas. No repite las que ya están con el mismo
     * apartado y texto. Devuelve cuántas se han añadido y omitido, y el resumen.
     */
    public function load(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        if (! $key = self::auditKey($request)) {
            return response()->json(['message' => "Falta la clave 'codigo'"], 400);
        }
        [$del, $ser, $cod] = $key;
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'listas'       => 'required|array|min:1',
            'listas.*'     => 'required|integer|min:1',
            'solo_alcance' => 'nullable',
            'auv'          => 'nullable|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }
        $witnessed = isset($body['auv']) ? (int) $body['auv'] : null;
        // En un ensayo presenciado se carga la lista técnica completa (como el escritorio, que no ofrece el filtro)
        $flag = $witnessed !== null ? false : ($body['solo_alcance'] ?? true);
        $onlyScope = is_bool($flag) ? $flag : in_array(strtoupper(trim((string) $flag)), ['T', 'TRUE', '1', 'S', 'SI'], true);

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabAuditChecklists::lockAudit($del, $ser, $cod);
            if ($witnessed !== null && ! $db->table('CALAUV')->where('DEL3COD', $del)->where('AUI3SER', $ser)->where('AUI3COD', $cod)
                ->where('AUV1COD', $witnessed)->exists()) {
                throw new BusinessRuleException("El ensayo presenciado {$witnessed} no existe en la auditoría");
            }
            $lists = [];
            foreach (array_values(array_unique(array_map('intval', $body['listas']))) as $listCode) {
                $list = $db->table(VeolabAuditChecklists::TABLE)->where('LVE1COD', $listCode)->whereIn('DEL3COD', array_values(array_unique(['', $del])))
                    ->first(['DEL3COD', 'LVE1COD', 'LVEBBAJ']);
                if (! $list) {
                    throw new BusinessRuleException("La lista {$listCode} no existe o es de otra delegación");
                }
                if ($list->LVEBBAJ === 'T') {
                    throw new BusinessRuleException("La lista {$listCode} está de baja");
                }
                $lists[] = $list;
            }

            $scope = $db->table('CALAUX')->where('DEL3COD', $del)->where('AUI3SER', $ser)->where('AUI3COD', $cod)
                ->pluck('AUXCAPA')->map(fn ($c) => trim((string) $c))->all();
            $filterScope = $onlyScope && $scope;
            $group = function () use ($del, $ser, $cod, $witnessed) {
                $q = VeolabAuditChecklists::lines($del, $ser, $cod);

                return $witnessed === null ? $q->whereNull('AUV2COD') : $q->where('AUV2COD', $witnessed);
            };
            // Sin distinguir mayúsculas, como AUI_MismaPregunta del escritorio (vbTextCompare)
            $sameKey = fn ($clause, $text) => mb_strtolower(trim((string) $clause)."\x1B".trim((string) $text));
            $loaded = [];
            foreach ($group()->get(['AUPCAPA', 'AUPCPRE']) as $existing) {
                $loaded[$sameKey($existing->AUPCAPA, $existing->AUPCPRE)] = true;
            }
            $line = (int) VeolabAuditChecklists::lines($del, $ser, $cod)->max('AUP1COD') + 1;
            $order = (int) $group()->max('AUPNORD') + 1;

            $counts = ['anadidas' => 0, 'repetidas' => 0, 'fuera_del_alcance' => 0];
            foreach ($lists as $list) {
                $questions = $db->table(VeolabAuditChecklists::QUESTIONS)->where('DEL3COD', (string) $list->DEL3COD)
                    ->where('LVE3COD', (int) $list->LVE1COD)->orderBy('LVQNORD')->orderBy('LVQ1COD')->get();
                foreach ($questions as $q) {
                    $clause = trim((string) $q->LVQCAPA);
                    if ($filterScope && $clause !== '' && ! in_array($clause, $scope, true)) {
                        $counts['fuera_del_alcance']++;
                        continue;
                    }
                    $same = $sameKey($clause, $q->LVQCPRE);
                    if (isset($loaded[$same])) {
                        $counts['repetidas']++;
                        continue;
                    }
                    $db->table(VeolabAuditChecklists::LINES)->insert([
                        'DEL3COD' => $del, 'AUI3SER' => $ser, 'AUI3COD' => $cod, 'AUP1COD' => $line++, 'AUPNORD' => $order++,
                        'AUV2COD' => $witnessed, 'AUPCAPA' => $clause === '' ? null : $clause, 'AUPCPRE' => $q->LVQCPRE,
                        'AUPCGUI' => $q->LVQCGUI, 'AUPCRES' => null, 'AUPCEVI' => null, 'INC2SER' => null, 'INC2COD' => null,
                        'LVE2COD' => (int) $list->LVE1COD,
                    ]);
                    $loaded[$same] = true;
                    $counts['anadidas']++;
                }
            }
            if ($counts['anadidas'] > 0) {
                VeolabAuditChecklists::auditAudit($del, $ser, $cod);
            }
            $summary = VeolabAuditChecklists::summary($del, $ser, $cod, $witnessed);

            $db->commit();

            return response()->json([
                'message' => $counts['anadidas'] > 0 ? 'Preguntas cargadas correctamente' : 'No hay preguntas nuevas que cargar',
                'data'    => $counts + ['solo_alcance' => $filterScope ? 'T' : 'F', 'resumen' => $summary],
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 cargar {$this->table}", $e, 'Error al cargar las preguntas de la lista de verificación');
        }
    }

    // ------------------------------------------------------------------
    // Crear el hallazgo de una pregunta
    // ------------------------------------------------------------------

    /**
     * POST /auditorias-calidad/lista/hallazgo?delegacion=&serie=&codigo=&linea=
     * Cuerpo opcional {serie (de la incidencia), detectada_por_delegacion,
     * detectada_por_codigo (por defecto, el auditor jefe), hallazgo (M, N u
     * O; en una observación, O por defecto), usuario_delegacion,
     * usuario_codigo}. En una pregunta «No conforme» u «Observación» sin
     * hallazgo, crea la incidencia como un hallazgo nuevo de la auditoría
     * (POST /incidencias con clase N, origen I o E según el tipo de
     * auditoría y el apartado de la pregunta; título = la pregunta,
     * recortada; detalle = la evidencia), la enlaza a la auditoría (CALIYE
     * tipo CALAUI) y la guarda como hallazgo de la pregunta. 201 con la clave
     * de la incidencia.
     */
    public function createFinding(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        if (! $key = self::auditKey($request)) {
            return response()->json(['message' => "Falta la clave 'codigo'"], 400);
        }
        $lineParam = $request->query('linea');
        if (! is_string($lineParam) || ! ctype_digit($lineParam)) {
            return response()->json(['message' => "Falta la clave 'linea'"], 400);
        }
        [$del, $ser, $cod] = $key;
        $lineCode = (int) $lineParam;
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'serie'                    => 'nullable|string|max:10',
            'detectada_por_delegacion' => 'nullable|string|max:10',
            'detectada_por_codigo'     => 'nullable|integer|min:0',
            'hallazgo'                 => 'nullable|string|in:'.implode(',', VeolabQuality::FINDINGS),
            'usuario_delegacion'       => 'nullable|string|max:10',
            'usuario_codigo'           => 'nullable|string|max:15',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $audit = VeolabAuditChecklists::lockAudit($del, $ser, $cod);
            $line = VeolabAuditChecklists::lines($del, $ser, $cod)->where('AUP1COD', $lineCode)->lockForUpdate()->first();
            if (! $line) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            if (! in_array((string) $line->AUPCRES, VeolabAuditChecklists::FINDING_RESULTS, true)) {
                throw new BusinessRuleException('El hallazgo se crea en una pregunta «No conforme» u «Observación»');
            }
            if ((int) $line->INC2COD !== 0) {
                throw new BusinessRuleException('La pregunta ya tiene el hallazgo '
                    .VeolabCodes::format('CALINC', (string) $line->INC2COD, $del, (string) $line->INC2SER));
            }

            // La incidencia, con las comprobaciones de POST /incidencias
            $title = trim((string) preg_replace('/\s+/u', ' ', (string) $line->AUPCPRE));
            if (mb_strlen($title) > 255) {
                $title = rtrim(mb_substr($title, 0, 254)).'…';
            }
            $clause = trim((string) $line->AUPCAPA);
            // Quién la detecta (DetectadaInicial): la indicada; si no, el empleado del usuario que la crea
            // y, si el usuario no es empleado, el auditor jefe.
            $creator = VeolabQuality::requestUser($body['usuario_delegacion'] ?? null, $body['usuario_codigo'] ?? null);
            $detector = match (true) {
                array_key_exists('detectada_por_codigo', $body)
                    => [(string) ($body['detectada_por_delegacion'] ?? ''), (int) ($body['detectada_por_codigo'] ?? 0)],
                $creator !== null && $creator['empleado'] !== null => [(string) $creator['empleado'][0], (int) $creator['empleado'][1]],
                default => [(string) ($audit->EMA2DEL ?? ''), (int) ($audit->EMA2COD ?? 0)],
            };
            $incident = [
                'delegacion'               => $del,
                'serie'                    => (string) ($body['serie'] ?? ''),
                'clase'                    => 'N',
                'origen'                   => VeolabQualityAudits::findingsOrigin((string) ($audit->AUICTIP ?? '')),
                'descripcion'              => $title,
                'detalle'                  => (string) ($line->AUPCEVI ?? ''),
                'apartado'                 => VeolabQualityAudits::isClause($clause) ? $clause : null,
                'hallazgo'                 => $body['hallazgo'] ?? ($line->AUPCRES === 'O' ? 'O' : null),
                'detectada_por_delegacion' => $detector[1] !== 0 ? $detector[0] : null,
                'detectada_por_codigo'     => $detector[1] !== 0 ? $detector[1] : null,
            ];
            if (! empty($body['usuario_codigo'])) {
                $incident['usuario_delegacion'] = (string) ($body['usuario_delegacion'] ?? '');
                $incident['usuario_codigo'] = (string) $body['usuario_codigo'];
            }
            $response = app(IncidenciaController::class)->createFromData($incident);
            if (! $response instanceof JsonResponse || $response->getStatusCode() !== 201) {
                $db->rollBack();

                return $response;
            }
            $created = $response->getData(true)['data'] ?? [];
            [$incSer, $incCod] = [(string) ($created['serie'] ?? ''), (int) ($created['codigo'] ?? 0)];

            // Enlace con la auditoría y hallazgo de la pregunta
            $db->table('CALIYE')->insert([
                'DEL3COD' => $del, 'INC3SER' => $incSer, 'INC3COD' => $incCod,
                'IYE1COD' => (int) $db->table('CALIYE')->where('DEL3COD', $del)->where('INC3SER', $incSer)->where('INC3COD', $incCod)->max('IYE1COD') + 1,
                'IYECTAB' => VeolabQualityAudits::TABLE, 'IYECDEL' => $del, 'IYECSER' => $ser, 'IYECCOD' => (string) $cod,
            ]);
            VeolabAuditChecklists::lines($del, $ser, $cod)->where('AUP1COD', $lineCode)->update(['INC2SER' => $incSer, 'INC2COD' => $incCod]);
            VeolabAuditChecklists::auditAudit($del, $ser, $cod);

            $db->commit();

            return response()->json([
                'message' => 'Hallazgo creado correctamente',
                'data'    => [
                    'delegacion'        => $del,
                    'serie'             => $incSer,
                    'codigo'            => $incCod,
                    'codigo_formateado' => VeolabCodes::format('CALINC', (string) $incCod, $del, $incSer),
                    'linea'             => $lineCode,
                ],
            ], 201);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 hallazgo {$this->table}", $e, 'Error al crear el hallazgo');
        }
    }
}
