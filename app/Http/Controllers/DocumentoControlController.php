<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabDocumentControl;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Control de documentos (Veolab 3, ISO/IEC 17025:2017 8.3): la lista de
 * documentos controlados (opción DOC_CTL del menú Documentos) y sus
 * acciones. Los datos de control están en /documentos, /documentos/versiones
 * y /documentos/carpetas; las lecturas, en /documentos/lecturas.
 *
 * Usuario opcional en la query string (?usuario_delegacion=&usuario_codigo=):
 * si se indica, aprobar y revisar exigen el privilegio especial 1 de DOC_CTL
 * (aprobar documentos) y descartar, ese privilegio o ser quien lo elabora;
 * sin usuario, la API actúa como privilegiada.
 *
 * Solo con el módulo Calidad activo (si no, 422). Los accesos directos no aparecen en la lista ni admiten estas acciones
 * (422): se aprueba, revisa o descarta el documento de destino.
 */
class DocumentoControlController extends Controller
{
    /** Ordenaciones admitidas en la lista: parámetro => columna. */
    private const SORTS = [
        'codigo_documento' => 'F.FATCCOD',
        'titulo'           => 'F.FATCNOM',
        'tipo_documento'   => 'F.FATCTDC',
        'fecha_edicion'    => 'V.VERDEDI',
        'fecha_aprobacion' => 'V.VERDAPR',
        'proxima_revision' => 'F.FATDREV',
        'ultima_revision'  => 'F.FATDULR',
        'carpeta_nombre'   => 'D.DIRCNOM',
        'delegacion'       => 'F.DEL3COD',
    ];

    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 500;   // la lista maestra se exporta entera

    /** 422 si la base de datos no tiene el control de documentos; null si lo tiene. */
    private function unavailable()
    {
        if (! VeolabVersion::isV3()) {
            return response()->json(['message' => VeolabVersion::message(VeolabVersion::V3, 'El control de documentos')], 422);
        }
        if (! VeolabDocumentControl::available()) {
            return response()->json(['message' => 'El control de documentos requiere el script de conversión que crea DOCLEC y los campos de control'], 422);
        }
        if (! VeolabDocumentControl::active()) {
            return response()->json(['message' => VeolabDocumentControl::INACTIVE_MESSAGE], 422);
        }

        return null;
    }

    /** Clave del documento en la query string (delegacion, codigo) o la respuesta 400. */
    private function documentKeys(Request $request)
    {
        foreach (['delegacion', 'codigo'] as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }

        return [(string) ($request->query('delegacion') ?? ''), (int) $request->query('codigo')];
    }

    // ------------------------------------------------------------------
    // Lista de documentos controlados
    // ------------------------------------------------------------------

    /**
     * GET /documentos/controlados: los documentos controlados (fuera de la
     * papelera) con su edición vigente. Filtros: delegacion (lista con
     * comas), estado (B, V, P pendiente de aprobar, O; lista con comas),
     * tipo (I, E), carpeta_delegacion + carpeta_codigo (con sus subcarpetas;
     * subcarpetas=F solo la carpeta), revision_vencida (T|F), revision_proxima
     * (T: en los próximos 30 días), elabora_delegacion + elabora_codigo,
     * pendiente (revisar | aprobar: edición en curso en ese paso, fase 2) y
     * texto (código, título, descripción, entidad emisora y edición).
     *
     * Con el script de la fase 2 cada fila añade el revisor, el estado de la
     * edición en curso (edicion_estado B/R), pendiente_revisar y
     * pendiente_aprobar_edicion (T/F) y las lecturas de la edición vigente:
     * lecturas («leídas/destinatarios»), lecturas_pendientes y
     * lecturas_vencidas (pendientes con el plazo pasado), calculadas para
     * toda la página con dos consultas por delegación.
     * Orden: sort=campo|-campo (codigo_documento por defecto); page, limit.
     */
    public function index(Request $request)
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }
        $db = DB::connection('dynamic');
        $stateSql = VeolabDocumentControl::stateSql('F');

        try {
            $query = $db->table('DOCFAT AS F')
                ->leftJoin('DOCVER AS V', function ($join) {
                    $join->on('V.DEL3COD', '=', 'F.DEL3COD')->on('V.FAT3COD', '=', 'F.FAT1COD')->on('V.VER1COD', '=', 'F.VER2APR');
                })
                ->leftJoin('DOCDIR AS D', function ($join) {
                    $join->on('D.DEL3COD', '=', 'F.DIR2DEL')->on('D.DIR1COD', '=', 'F.DIR2COD');
                })
                ->leftJoin('DOCVER AS P', function ($join) {   // versión principal (edición en curso)
                    $join->on('P.DEL3COD', '=', 'F.DEL3COD')->on('P.FAT3COD', '=', 'F.FAT1COD')->on('P.VER1COD', '=', 'F.VER2COD');
                })
                ->where('F.DIR2COD', '<>', 0);   // ni la papelera
            VeolabDocumentControl::whereControlled($query, true, 'F');
            $this->applyFilters($request, $query, $stateSql);

            $applied = [];
            foreach (explode(',', (string) ($request->query('sort') ?: 'codigo_documento')) as $field) {
                $field = trim($field);
                $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
                $field = ltrim($field, '-');
                if ($field === 'estado') {
                    $query->orderByRaw("{$stateSql} {$direction}");
                } elseif (isset(self::SORTS[$field])) {
                    $query->orderBy(self::SORTS[$field], $direction);
                    $applied[] = self::SORTS[$field];
                } elseif ($field !== '') {
                    throw new BusinessRuleException("No se puede ordenar por {$field}");
                }
            }
            if (! in_array('F.FATCNOM', $applied, true)) {
                $query->orderBy('F.FATCNOM');
            }
            $query->orderBy('F.DEL3COD')->orderBy('F.FAT1COD');
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => ['filtro' => [$e->getMessage()]]], 422);
        }

        $perPage = min(max((int) $request->query('limit', (string) self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $page = max((int) $request->query('page', '1'), 1);

        $distribution = VeolabDocumentControl::distributionAvailable();
        $columns = [
            'F.DEL3COD', 'F.FAT1COD', 'F.FATCCOD', 'F.FATCNOM', 'F.FATCTIP', 'F.FATCDES', 'F.FATCTDC', 'F.FATCEMI',
            'F.FATCEST', 'F.FATCCTL', 'F.VER2COD', 'F.VER2APR', 'F.EME2DEL', 'F.EME2COD', 'F.FATNPER', 'F.FATDULR',
            'F.FATDREV', 'F.FATDVIG', 'F.DIR2DEL', 'F.DIR2COD', 'F.TEC2COD', 'D.DIRCNOM',
            'V.VERCEDI', 'V.VERDEDI', 'V.VERCMOT', 'V.EMA2DEL', 'V.EMA2COD', 'V.VERDAPR',
        ];
        if ($distribution) {
            // Fase 2: revisor del documento y estado y revisión de la edición en curso
            array_push($columns, 'F.EMR2DEL', 'F.EMR2COD', 'P.VERCEST AS PVERCEST', 'P.VERDAPR AS PVERDAPR',
                'P.EMR2DEL AS PEMR2DEL', 'P.EMR2COD AS PEMR2COD', 'P.VERDREV AS PVERDREV', 'P.VERCREV AS PVERCREV');
        }

        try {
            $paginator = $query->select($columns)->paginate($perPage, ['*'], 'page', $page);

            $rows = $paginator->items();
            $pairs = [];
            foreach ($rows as $row) {
                $pairs[] = [$row->EME2DEL, $row->EME2COD];
                $pairs[] = [$row->EMA2DEL, $row->EMA2COD];
                if ($distribution) {
                    $pairs[] = [$row->EMR2DEL, $row->EMR2COD];
                    $pairs[] = [$row->PEMR2DEL, $row->PEMR2COD];
                }
            }
            $names = VeolabDocumentControl::employeeNames($pairs);
            $today = VeolabDocumentControl::today();
            $limit = (new \DateTime($today))->modify('+'.VeolabDocumentControl::UPCOMING_DAYS.' days')->format('Y-m-d');
            // Lecturas de la edición vigente de toda la página a la vez (sin consultas por fila)
            $readings = $distribution ? VeolabDocumentControl::readingStatus($rows) : [];

            $data = array_map(fn ($row) => $this->listRow($row, $names, $today, $limit, $distribution, $readings), $rows);

            return response()->json([
                'data' => $data,
                'meta' => [
                    'total'      => $paginator->total(),
                    'page'       => $paginator->currentPage(),
                    'per_page'   => $paginator->perPage(),
                    'last_page'  => $paginator->lastPage(),
                    'version_bd' => VeolabVersion::current(),
                ],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response('v2 controlados DOCFAT', $e, 'Error al leer los documentos controlados');
        }
    }

    /** Filtros de la lista (ver index). */
    private function applyFilters(Request $request, $query, string $stateSql): void
    {
        $list = fn ($value) => array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));

        if ($request->has('delegacion')) {
            $delegations = $list($request->query('delegacion'));
            $delegations ? $query->whereIn('F.DEL3COD', $delegations) : $query->where('F.DEL3COD', '');
        }

        if ($request->filled('estado')) {
            $states = $list(strtoupper((string) $request->query('estado')));
            if (array_diff($states, array_keys(VeolabDocumentControl::STATE_NAMES))) {
                throw new BusinessRuleException('El estado debe ser B, V, P u O');
            }
            $query->whereIn(DB::raw($stateSql), $states);
        }

        if ($request->filled('tipo')) {
            $type = strtoupper((string) $request->query('tipo'));
            if (! isset(VeolabDocumentControl::TYPE_NAMES[$type])) {
                throw new BusinessRuleException('El tipo debe ser I (interno) o E (externo)');
            }
            $query->where('F.FATCTDC', $type);
        }

        if ($request->filled('carpeta_codigo')) {
            $del = (string) ($request->query('carpeta_delegacion') ?? '');
            $cod = (int) $request->query('carpeta_codigo');
            if (! VeolabDocumentControl::folder($del, $cod)) {
                throw new BusinessRuleException('La carpeta no existe');
            }
            $folders = $request->query('subcarpetas') === 'F' ? [[$del, $cod]] : VeolabDocumentControl::subtree($del, $cod);
            $groups = [];
            foreach ($folders as [$folderDel, $folderCod]) {
                $groups[$folderDel][] = $folderCod;
            }
            VeolabDocumentControl::whereInFolders($query, $groups, 'F');
        }

        $overdue = function ($q) use ($stateSql) {
            $q->whereNotNull('F.FATDREV')->whereRaw('F.FATDREV < CURDATE()')->whereRaw("{$stateSql} <> 'O'");
        };
        $flag = $request->query('revision_vencida');
        if (in_array($flag, ['T', 'F'], true)) {
            $flag === 'T' ? $query->where($overdue) : $query->whereNot($overdue);
        }
        if ($request->query('revision_proxima') === 'T') {
            $query->whereNotNull('F.FATDREV')->whereRaw("{$stateSql} <> 'O'")
                ->whereRaw('F.FATDREV >= CURDATE()')
                ->whereRaw('F.FATDREV <= DATE_ADD(CURDATE(), INTERVAL ? DAY)', [VeolabDocumentControl::UPCOMING_DAYS]);
        }

        // Fase 2: edición en curso pendiente de revisar o de aprobar (con la regla de la revisión antes de aprobar)
        if ($request->filled('pendiente')) {
            $step = strtolower((string) $request->query('pendiente'));
            if (! in_array($step, ['revisar', 'aprobar'], true)) {
                throw new BusinessRuleException('pendiente debe ser revisar o aprobar');
            }
            $query->whereRaw("COALESCE(F.FATCEST, '') <> 'O'")->whereRaw('COALESCE(F.VER2APR, 0) <> COALESCE(F.VER2COD, 0)')
                ->where('F.VER2COD', '>', 0);
            $reviewFolders = VeolabDocumentControl::reviewFolders();
            $reviewed = VeolabDocumentControl::distributionAvailable() ? "COALESCE(P.VERCEST, '') = 'R'" : '1 = 0';
            if ($step === 'revisar') {
                VeolabDocumentControl::whereInFolders($query, $reviewFolders, 'F');
                $query->whereRaw("NOT ({$reviewed})");
            } else {
                $query->where(function ($q) use ($reviewFolders, $reviewed) {
                    $q->whereNot(fn ($w) => VeolabDocumentControl::whereInFolders($w, $reviewFolders, 'F'))
                        ->orWhereRaw($reviewed);
                });
            }
        }

        if ($request->filled('elabora_codigo')) {
            $query->where('F.EME2DEL', (string) ($request->query('elabora_delegacion') ?? ''))
                ->where('F.EME2COD', (int) $request->query('elabora_codigo'));
        }

        $text = $request->query('texto');
        if (is_string($text) && trim($text) !== '') {
            $like = '%'.trim($text).'%';
            $query->where(function ($q) use ($like) {
                foreach (['F.FATCCOD', 'F.FATCNOM', 'F.FATCDES', 'F.FATCEMI', 'V.VERCEDI'] as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }
    }

    /** Fila de la lista. */
    private function listRow(object $row, array $names, string $today, string $limit, bool $distribution = false, array $readings = []): array
    {
        $state = VeolabDocumentControl::state($row) ?? 'B';
        $review = $row->FATDREV ? substr((string) $row->FATDREV, 0, 10) : null;
        $active = $state !== 'O';
        $type = (string) ($row->FATCTDC ?? '');
        $approvedBy = (int) ($row->EMA2COD ?? 0) !== 0;
        $elaborator = (int) ($row->EME2COD ?? 0) !== 0;

        return [
            'delegacion'              => (string) $row->DEL3COD,
            'codigo'                  => (int) $row->FAT1COD,
            'codigo_documento'        => $row->FATCCOD,
            'titulo'                  => $row->FATCNOM,
            'extension'               => $row->FATCTIP,
            'descripcion'             => $row->FATCDES,
            'tipo_documento'          => $type !== '' ? $type : null,
            'tipo_nombre'             => VeolabDocumentControl::TYPE_NAMES[$type] ?? null,
            'entidad_emisora'         => $row->FATCEMI,
            'estado'                  => $state,
            'estado_nombre'           => VeolabDocumentControl::STATE_NAMES[$state],
            'estado_control'          => $row->FATCEST,
            'pendiente_aprobar'       => $state === 'P' ? 'T' : 'F',
            'version_actual'          => (int) $row->VER2COD,
            'version_aprobada'        => (int) ($row->VER2APR ?? 0) > 0 ? (int) $row->VER2APR : null,
            'edicion'                 => $row->VERCEDI,
            'fecha_edicion'           => $row->VERDEDI,
            'motivo'                  => $row->VERCMOT,
            'aprobada_por_delegacion' => $approvedBy ? (string) $row->EMA2DEL : null,
            'aprobada_por_codigo'     => $approvedBy ? (int) $row->EMA2COD : null,
            'aprobada_por_nombre'     => VeolabDocumentControl::employeeName($names, $row->EMA2DEL, $row->EMA2COD ?? 0),
            'fecha_aprobacion'        => $row->VERDAPR,
            'elabora_delegacion'      => $elaborator ? (string) $row->EME2DEL : null,
            'elabora_codigo'          => $elaborator ? (int) $row->EME2COD : null,
            'elabora_nombre'          => VeolabDocumentControl::employeeName($names, $row->EME2DEL, $row->EME2COD ?? 0),
            'periodicidad_revision'   => VeolabDocumentControl::periodicity($row),
            'ultima_revision'         => $row->FATDULR,
            'proxima_revision'        => $row->FATDREV,
            'revision_vencida'        => $active && $review !== null && $review < $today ? 'T' : 'F',
            'revision_proxima'        => $active && $review !== null && $review >= $today && $review <= $limit ? 'T' : 'F',
            'fecha_vigencia'          => $row->FATDVIG,
            'carpeta_delegacion'      => (string) $row->DIR2DEL,
            'carpeta_codigo'          => (int) $row->DIR2COD,
            'carpeta_nombre'          => $row->DIRCNOM,
        ] + ($distribution ? $this->distributionColumns($row, $names, $readings) : []);
    }

    /**
     * Columnas de la fase 2 (con el script de distribución): revisor, estado
     * de la edición en curso (la principal sin aprobar), paso pendiente
     * (revisar o aprobar) y lecturas de la edición vigente («leídas/
     * destinatarios», pendientes y vencidas).
     */
    private function distributionColumns(object $row, array $names, array $readings): array
    {
        $inProgress = (int) ($row->VER2APR ?? 0) !== (int) $row->VER2COD && (string) ($row->FATCEST ?? '') !== 'O';
        $editionState = $inProgress
            ? VeolabDocumentControl::editionState((object) ['VERCEST' => $row->PVERCEST ?? null, 'VERDAPR' => $row->PVERDAPR ?? null])
            : null;
        $step = VeolabDocumentControl::pendingStep($row, $editionState);
        $reviewer = (int) ($row->EMR2COD ?? 0) !== 0;
        $reviewedBy = $inProgress && (int) ($row->PEMR2COD ?? 0) !== 0;

        $status = $readings[VeolabDocumentControl::documentKey($row->DEL3COD, $row->FAT1COD)] ?? null;
        $recipients = $status ? count($status['destinatarios']) : 0;
        $read = $status ? $status['leidas'] : 0;
        $pending = $status ? count($status['pendientes']) : 0;
        $overdue = $status && $status['vencida'] ? $pending : 0;

        return [
            'revisor_delegacion'      => $reviewer ? (string) $row->EMR2DEL : null,
            'revisor_codigo'          => $reviewer ? (int) $row->EMR2COD : null,
            'revisor_nombre'          => VeolabDocumentControl::employeeName($names, $row->EMR2DEL, $row->EMR2COD ?? 0),
            'revision_antes_aprobar'  => VeolabDocumentControl::requiresReview($row) ? 'T' : 'F',
            'edicion_estado'          => $editionState,
            'edicion_estado_nombre'   => $editionState !== null ? VeolabDocumentControl::EDITION_STATES[$editionState] : null,
            'edicion_revisada_por_delegacion' => $reviewedBy ? (string) $row->PEMR2DEL : null,
            'edicion_revisada_por_codigo'     => $reviewedBy ? (int) $row->PEMR2COD : null,
            'edicion_revisada_por_nombre'     => $reviewedBy ? VeolabDocumentControl::employeeName($names, $row->PEMR2DEL, $row->PEMR2COD) : null,
            'edicion_fecha_revision'  => $inProgress ? ($row->PVERDREV ?? null) : null,
            'edicion_comentario_revision' => $inProgress ? ($row->PVERCREV ?? null) : null,
            'pendiente_revisar'       => $step === 'revisar' ? 'T' : 'F',
            'pendiente_aprobar_edicion' => $step === 'aprobar' ? 'T' : 'F',
            'lecturas'                => $status ? "{$read}/{$recipients}" : null,
            'lecturas_destinatarios'  => $recipients,
            'lecturas_leidas'         => $read,
            'lecturas_pendientes'     => $pending,
            'lecturas_vencidas'       => $overdue,
            'lecturas_fecha_limite'   => $status['fecha_limite'] ?? null,
            'destinatarios_origen'    => $status['origen'] ?? null,
        ];
    }

    // ------------------------------------------------------------------
    // Acciones
    // ------------------------------------------------------------------

    /**
     * Documento controlado, fuera de la papelera y no obsoleto, bloqueado
     * hasta el commit, ya activado (control de versiones, sin copia dual).
     */
    private function controlledDocument(string $del, int $cod): ?object
    {
        $document = VeolabDocumentControl::document($del, $cod, true);
        if (! $document) {
            return null;
        }
        VeolabDocuments::assertNotShortcut($document, 'aprobarlo o revisarlo');
        if ((int) $document->DIR2COD === 0) {
            throw new BusinessRuleException('El documento está en la papelera');
        }
        if (! VeolabDocumentControl::isControlled($document)) {
            throw new BusinessRuleException('El documento no está controlado');
        }
        if (VeolabDocumentControl::activate($del, $cod)) {
            $document = VeolabDocumentControl::document($del, $cod, true);
        }

        return $document;
    }

    /** "del-cod-nombre" de DOCFAT, como DocumentoController. */
    private function auditRow(object $document): string
    {
        return VeolabCodes::format('DOCFAT', (string) $document->FAT1COD, (string) $document->DEL3COD, '', '', (string) $document->FATCNOM);
    }

    /** Suceso de modificación de DOCFAT con los campos que cambian. */
    private function auditUpdate(object $before, array $update): void
    {
        $row = $this->auditRow($before);
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'DOCFAT', $row);
        if (! VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
            return;
        }
        foreach ($update as $column => $new) {
            $new = VeolabAudit::value($new);
            $old = VeolabAudit::value($before->{$column} ?? null);
            if ($new !== $old) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'DOCFAT', $row, 'DOCFAT'.$column, $new, $old);
            }
        }
    }

    /**
     * POST /documentos/aprobar?delegacion=&codigo=[&usuario_delegacion=&usuario_codigo=]
     * Cuerpo: {edicion (por defecto, la siguiente a la vigente: Rev. 3 → Rev. 4;
     * sin edición vigente, «Rev. 0»), motivo, fecha_edicion (por defecto hoy),
     * aprobada_por_delegacion + aprobada_por_codigo (por defecto el empleado
     * del usuario; si no tiene, quien aprobó la edición vigente anterior; si
     * tampoco, ninguno; 0 o null, ninguno)}. La versión principal pasa a ser la
     * edición aprobada vigente: sus datos de edición, VER2APR = VER2COD,
     * estado V, última revisión hoy y próxima = hoy + periodicidad (sin
     * periodicidad, la próxima revisión no se toca).
     *
     * Con el script de la fase 2: requiere_lectura (T por defecto; F, un
     * cambio menor: quien leyó la edición anterior no la tiene pendiente)
     * va a VERBLEC y la edición pasa a A. Si la carpeta exige revisión antes
     * de aprobar (DIRBREV), solo se aprueba una edición revisada (R); si la
     * revisó quien la aprueba, la respuesta lleva un aviso.
     */
    public function approve(Request $request)
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }
        $keys = $this->documentKeys($request);
        if (! is_array($keys)) {
            return $keys;
        }
        [$del, $cod] = $keys;
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        try {
            $data = Validator::make($body, [
                'edicion'                 => 'nullable|string|max:50',
                'motivo'                  => 'nullable|string|max:255',
                'fecha_edicion'           => 'nullable|date',
                'aprobada_por_delegacion' => 'nullable|string|max:10',
                'aprobada_por_codigo'     => 'nullable|integer|min:0',
                'requiere_lectura'        => 'nullable|string|in:T,F',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $user = VeolabDocumentControl::queryUser();
            VeolabDocumentControl::assertApprover($user, 'aprobar ediciones de documentos');
            $document = $this->controlledDocument($del, $cod);
            if (! $document) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            if ((string) ($document->FATCEST ?? '') === 'O') {
                throw new BusinessRuleException('El documento está obsoleto; póngalo antes en vigor (PUT /documentos con estado_control V)');
            }
            $version = (int) $document->VER2COD;
            if ((int) ($document->VER2APR ?? 0) === $version) {
                throw new BusinessRuleException('La versión principal ya es la edición aprobada vigente; sus datos se corrigen en PUT /documentos/versiones');
            }

            // Aprobador: el indicado o el empleado del usuario.
            if (array_key_exists('aprobada_por_codigo', $data)) {
                $approver = (int) ($data['aprobada_por_codigo'] ?? 0) > 0
                    ? [(string) ($data['aprobada_por_delegacion'] ?? ''), (int) $data['aprobada_por_codigo']] : null;
                if ($approver) {
                    VeolabDocumentControl::assertEmployee($approver[0], $approver[1], 'El empleado que la aprueba');
                }
            } else {
                // El empleado del usuario; si no, quien aprobó la edición anterior; si tampoco, ninguno.
                $approver = $user['empleado'] ?? null;
                if ($approver === null && (int) ($document->VER2APR ?? 0) > 0) {
                    $previous = $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)
                        ->where('VER1COD', (int) $document->VER2APR)->first(['EMA2DEL', 'EMA2COD']);
                    if ($previous && (int) ($previous->EMA2COD ?? 0) !== 0) {
                        $approver = [(string) $previous->EMA2DEL, (int) $previous->EMA2COD];
                    }
                }
            }

            // Fase 2: revisión antes de aprobar y «Requiere nueva lectura»
            $distribution = VeolabDocumentControl::distributionAvailable();
            if (($data['requiere_lectura'] ?? null) !== null && ! $distribution) {
                throw new BusinessRuleException(VeolabDocumentControl::DISTRIBUTION_MESSAGE);
            }
            $warning = null;
            if ($distribution && VeolabDocumentControl::requiresReview($document)) {
                $principal = $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $version)
                    ->first(['VERCEST', 'VERDAPR', 'EMR2DEL', 'EMR2COD']);
                if (! $principal || VeolabDocumentControl::editionState($principal) !== 'R') {
                    throw new BusinessRuleException('La carpeta del documento exige revisión antes de aprobar: la edición debe '
                        .'revisarse antes (POST /documentos/revisar-edicion)');
                }
                if ($approver && (int) ($principal->EMR2COD ?? 0) === $approver[1] && (string) ($principal->EMR2DEL ?? '') === $approver[0]) {
                    $warning = 'La misma persona ha revisado y aprueba la edición';
                }
            }

            $today = VeolabDocumentControl::today();
            // Valores propuestos como DCT_AprobarEdicion: los que ya tenía la versión principal (sin fecha, hoy)
            $own = $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $version)
                ->first(['VERCEDI', 'VERCMOT', 'VERDEDI']);
            $editionText = trim((string) ($data['edicion'] ?? ''));
            if ($editionText === '') {
                $approved = (int) ($document->VER2APR ?? 0);
                $current = $approved > 0
                    ? trim((string) $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)
                        ->where('VER1COD', $approved)->value('VERCEDI'))
                    : '';
                $editionText = trim((string) ($own->VERCEDI ?? ''));
                if ($approved !== $version) {
                    // La siguiente a la aprobada vigente, salvo que la principal ya tenga una edición propia
                    if ($editionText === '' || $editionText === $current) {
                        $editionText = VeolabDocumentControl::proposeEdition($current);
                    }
                } elseif ($editionText === '') {
                    $editionText = VeolabDocumentControl::proposeEdition('');
                }
            }
            $reason = array_key_exists('motivo', $data) ? (string) ($data['motivo'] ?? '') : (string) ($own->VERCMOT ?? '');
            $edition = [
                'VERCEDI' => $editionText,
                'VERCMOT' => $reason !== '' ? $reason : null,
                'VERDEDI' => $data['fecha_edicion'] ?? (! empty($own->VERDEDI) ? $own->VERDEDI : $today),
                'EMA2DEL' => $approver ? $approver[0] : null,   // sin aprobador, NULL como el escritorio
                'EMA2COD' => $approver ? $approver[1] : null,
                'VERDAPR' => $today,
            ];
            if ($distribution) {
                $edition['VERCEST'] = 'A';
                $edition['VERBLEC'] = ($data['requiere_lectura'] ?? null) === 'F' ? 'F' : 'T';
            }
            $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $version)->update($edition);

            $update = [
                'VER2APR' => $version,
                'FATCEST' => 'V',
                'FATDULR' => $today,
            ];
            // Sin periodicidad no se toca la próxima revisión (puede estar puesta a mano)
            $next = VeolabDocumentControl::nextReview($document);
            if ($next !== null) {
                $update['FATDREV'] = $next;
            }
            $db->table('DOCFAT')->where('DEL3COD', $del)->where('FAT1COD', $cod)->update($update);

            $this->auditUpdate($document, $update);
            VeolabAudit::record(VeolabAudit::MODIFICACION, 'DOCVER',
                VeolabDocuments::fullName((string) $document->FATCNOM, $document->FATCTIP).' - '.VeolabCodes::format('', (string) $version, $del));
            VeolabDocumentControl::notifyReview($del, $cod);   // aviso de la próxima revisión (DCT_AprobarEdicion)

            $db->commit();

            return response()->json([
                'message' => 'Edición aprobada correctamente',
                'aviso'   => $warning,
                'data'    => [
                    'delegacion'              => $del,
                    'codigo'                  => $cod,
                    'version_aprobada'        => $version,
                    'edicion'                 => $edition['VERCEDI'],
                    'fecha_edicion'           => $edition['VERDEDI'],
                    'aprobada_por_delegacion' => $approver[0] ?? null,
                    'aprobada_por_codigo'     => $approver[1] ?? null,
                    'fecha_aprobacion'        => $today,
                    'ultima_revision'         => $today,
                    'proxima_revision'        => $update['FATDREV'] ?? $document->FATDREV,
                ] + ($distribution ? [
                    'estado_edicion'   => 'A',
                    'requiere_lectura' => $edition['VERBLEC'],
                ] : []),
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response('v2 aprobar DOCFAT', $e, 'Error al aprobar la edición');
        }
    }

    /**
     * POST /documentos/revisar?delegacion=&codigo=[&usuario_delegacion=&usuario_codigo=]
     * Revisar sin cambios: última revisión hoy y próxima = hoy + periodicidad
     * (sin periodicidad, la próxima revisión no se toca);
     * en los externos, también la comprobación de vigencia (FATDVIG) hoy.
     * Solo con una edición aprobada (vigente o pendiente de aprobar otra).
     */
    public function review(Request $request)
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }
        $keys = $this->documentKeys($request);
        if (! is_array($keys)) {
            return $keys;
        }
        [$del, $cod] = $keys;

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabDocumentControl::assertApprover(VeolabDocumentControl::queryUser(), 'registrar la revisión de documentos');
            $document = $this->controlledDocument($del, $cod);
            if (! $document) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            if ((string) ($document->FATCEST ?? '') === 'O') {
                throw new BusinessRuleException('El documento está obsoleto');
            }
            if ((int) ($document->VER2APR ?? 0) <= 0) {
                throw new BusinessRuleException('El documento aún no tiene ninguna edición aprobada');
            }

            $today = VeolabDocumentControl::today();
            $update = ['FATDULR' => $today];
            // Sin periodicidad no se toca la próxima revisión (puede estar puesta a mano)
            $next = VeolabDocumentControl::nextReview($document);
            if ($next !== null) {
                $update['FATDREV'] = $next;
            }
            if ((string) ($document->FATCTDC ?? '') === 'E') {
                $update['FATDVIG'] = $today;
            }
            $db->table('DOCFAT')->where('DEL3COD', $del)->where('FAT1COD', $cod)->update($update);
            $this->auditUpdate($document, $update);
            VeolabDocumentControl::notifyReview($del, $cod);   // aviso de la próxima revisión (DCT_RevisarSinCambios)

            $db->commit();

            return response()->json([
                'message' => 'Revisión registrada correctamente',
                'data'    => [
                    'delegacion'       => $del,
                    'codigo'           => $cod,
                    'ultima_revision'  => $today,
                    'proxima_revision' => $update['FATDREV'] ?? $document->FATDREV,
                    'fecha_vigencia'   => $update['FATDVIG'] ?? $document->FATDVIG,
                ],
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response('v2 revisar DOCFAT', $e, 'Error al registrar la revisión');
        }
    }

    /**
     * POST /documentos/descartar?delegacion=&codigo=[&usuario_delegacion=&usuario_codigo=]
     * Descartar cambios: borra las versiones posteriores a la edición
     * aprobada (DOCVER, DOCBLO y sus lecturas) y la aprobada vuelve a ser la
     * principal. Solo con edición aprobada; con privilegio de aprobar o
     * siendo quien lo elabora. El escritorio pide confirmación antes.
     */
    public function discard(Request $request)
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }
        $keys = $this->documentKeys($request);
        if (! is_array($keys)) {
            return $keys;
        }
        [$del, $cod] = $keys;

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $user = VeolabDocumentControl::queryUser();
            $document = VeolabDocumentControl::document($del, $cod, true);
            if (! $document) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            VeolabDocuments::assertNotShortcut($document, 'descartar sus cambios');
            if (! VeolabDocumentControl::canApprove($user) && ! VeolabDocumentControl::isElaborator($user, $document)) {
                throw new BusinessRuleException('Solo quien puede aprobar documentos o quien lo elabora puede descartar sus cambios');
            }
            $approved = (int) ($document->VER2APR ?? 0);
            if ($approved <= 0) {
                throw new BusinessRuleException('El documento no tiene ninguna edición aprobada a la que volver');
            }
            $approvedVersion = $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $approved)->first();
            if (! $approvedVersion) {
                throw new BusinessRuleException('La edición aprobada ya no existe');
            }

            $later = $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)
                ->where('VER1COD', '>', $approved)->orderBy('VER1COD')->pluck('VER1COD')
                ->map(fn ($v) => (int) $v)->all();
            if (! $later && (int) $document->VER2COD === $approved) {
                throw new BusinessRuleException('No hay cambios sin aprobar');
            }

            foreach ($later as $version) {
                VeolabDocuments::deleteBlocks($del, $cod, $version);
            }
            VeolabDocumentControl::deleteReadings($del, $cod, $later);
            if ($later) {
                $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->whereIn('VER1COD', $later)->delete();
            }

            $update = ['VER2COD' => $approved, 'FATNTAM' => (int) $approvedVersion->VERNTAM, 'FATTMOD' => DB::raw('NOW()')];
            if (in_array((int) ($document->VER2DUA ?? 0), $later, true)) {
                $update['VER2DUA'] = 0;
            }
            $db->table('DOCFAT')->where('DEL3COD', $del)->where('FAT1COD', $cod)->update($update);

            unset($update['FATTMOD']);
            $this->auditUpdate($document, $update);
            VeolabAudit::record(VeolabAudit::MODIFICACION, 'DOCBLO', $this->auditRow($document));

            $db->commit();

            return response()->json([
                'message' => 'Cambios descartados correctamente',
                'data'    => [
                    'delegacion'           => $del,
                    'codigo'               => $cod,
                    'version_actual'       => $approved,
                    'versiones_eliminadas' => $later,
                ],
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response('v2 descartar DOCFAT', $e, 'Error al descartar los cambios');
        }
    }

    // ------------------------------------------------------------------
    // Revisión antes de aprobar (fase 2)
    // ------------------------------------------------------------------

    /**
     * POST /documentos/revisar-edicion?delegacion=&codigo=[&usuario_delegacion=&usuario_codigo=]
     * Cuerpo opcional: {comentario, revisada_por_delegacion +
     * revisada_por_codigo (por defecto el empleado del usuario; 0 o null,
     * ninguno)}. Solo si la carpeta del documento exige revisión antes de
     * aprobar (DIRBREV) y hay una edición en curso (la principal sin aprobar)
     * no revisada. La edición pasa a R con quién (EMR2*), cuándo (VERDREV) y
     * el comentario (VERCREV). Lo hace quien tiene el especial 2 de DOC_CTL o
     * el revisor del documento (DOCFAT.EMR2*), nunca quien lo elabora.
     */
    public function reviewEdition(Request $request)
    {
        return $this->editionStep($request, false);
    }

    /**
     * POST /documentos/devolver-edicion?delegacion=&codigo=[&usuario_delegacion=&usuario_codigo=]
     * Cuerpo: {motivo (obligatorio), revisada_por_delegacion +
     * revisada_por_codigo (como al revisar)}. La edición en curso, revisada o
     * no, vuelve a borrador (B) con el motivo en VERCREV. Lo puede quien
     * revisa (como revisar-edicion) o quien aprueba; nunca quien lo elabora.
     */
    public function returnEdition(Request $request)
    {
        return $this->editionStep($request, true);
    }

    /** Revisar ($return falso) o devolver una edición en curso. */
    private function editionStep(Request $request, bool $return)
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }
        if (! VeolabDocumentControl::distributionAvailable()) {
            return response()->json(['message' => VeolabDocumentControl::DISTRIBUTION_MESSAGE], 422);
        }
        $keys = $this->documentKeys($request);
        if (! is_array($keys)) {
            return $keys;
        }
        [$del, $cod] = $keys;
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        try {
            $data = Validator::make($body, [
                'comentario'              => 'nullable|string|max:255',
                'motivo'                  => ($return ? 'required' : 'nullable').'|string|max:255',
                'revisada_por_delegacion' => 'nullable|string|max:10',
                'revisada_por_codigo'     => 'nullable|integer|min:0',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        }
        $text = trim((string) ($return ? ($data['motivo'] ?? '') : ($data['comentario'] ?? '')));
        if ($return && $text === '') {
            return response()->json(['message' => 'Indique el motivo de la devolución'], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $user = VeolabDocumentControl::queryUser();
            $document = $this->controlledDocument($del, $cod);
            if (! $document) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            if ((string) ($document->FATCEST ?? '') === 'O') {
                throw new BusinessRuleException('El documento está obsoleto');
            }
            $version = (int) $document->VER2COD;
            if ($version <= 0 || (int) ($document->VER2APR ?? 0) === $version) {
                throw new BusinessRuleException('El documento no tiene ninguna edición en curso: la versión principal ya es la edición aprobada vigente');
            }
            if (! VeolabDocumentControl::requiresReview($document)) {
                throw new BusinessRuleException('La carpeta del documento no exige revisión antes de aprobar');
            }
            $edition = $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $version)
                ->lockForUpdate()->first();
            if (! $edition) {
                throw new BusinessRuleException('La versión principal ya no existe');
            }
            $state = VeolabDocumentControl::editionState($edition);
            if (! $return && $state === 'R') {
                throw new BusinessRuleException('La edición ya está revisada; para rehacer la revisión, devuélvala antes (POST /documentos/devolver-edicion)');
            }
            if ($state === 'A') {
                throw new BusinessRuleException('La edición ya está aprobada');
            }
            VeolabDocumentControl::assertReviewer($user, $document, $return, $edition);

            // Quién revisa o devuelve: el indicado o el empleado del usuario.
            if (array_key_exists('revisada_por_codigo', $data)) {
                $reviewer = (int) ($data['revisada_por_codigo'] ?? 0) > 0
                    ? [(string) ($data['revisada_por_delegacion'] ?? ''), (int) $data['revisada_por_codigo']] : null;
                if ($reviewer) {
                    VeolabDocumentControl::assertEmployee($reviewer[0], $reviewer[1], 'El empleado que la revisa');
                }
            } else {
                $reviewer = $user['empleado'] ?? null;
            }
            if (! $return && $reviewer && (int) ($document->EME2COD ?? 0) === $reviewer[1] && (string) ($document->EME2DEL ?? '') === $reviewer[0]) {
                throw new BusinessRuleException('Quien elabora el documento no puede revisar su propia edición');
            }

            $now = VeolabDocumentControl::today().' 00:00:00';   // el escritorio guarda solo la fecha
            $update = [
                'VERCEST' => $return ? 'B' : 'R',
                'EMR2DEL' => $reviewer ? $reviewer[0] : '',
                'EMR2COD' => $reviewer ? $reviewer[1] : 0,
                'VERDREV' => $now,
                'VERCREV' => $text !== '' ? $text : null,
            ];
            $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $version)->update($update);

            $row = VeolabDocuments::fullName((string) $document->FATCNOM, $document->FATCTIP).' - '.VeolabCodes::format('', (string) $version, $del);
            VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'DOCVER', $row);
            if (VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
                foreach ($update as $column => $new) {
                    $new = VeolabAudit::value($new);
                    $old = VeolabAudit::value($edition->{$column} ?? null);
                    if ($new !== $old) {
                        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'DOCVER', $row, 'DOCVER'.$column, $new, $old);
                    }
                }
            }

            $db->commit();

            return response()->json([
                'message' => $return ? 'Edición devuelta a borrador' : 'Edición revisada correctamente',
                'data'    => [
                    'delegacion'              => $del,
                    'codigo'                  => $cod,
                    'version'                 => $version,
                    'estado_edicion'          => $update['VERCEST'],
                    'revisada_por_delegacion' => $reviewer[0] ?? null,
                    'revisada_por_codigo'     => $reviewer[1] ?? null,
                    'fecha_revision'          => $now,
                    'comentario_revision'     => $update['VERCREV'],
                ],
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response($return ? 'v2 devolver DOCVER' : 'v2 revisar DOCVER', $e,
                $return ? 'Error al devolver la edición' : 'Error al revisar la edición');
        }
    }
}
