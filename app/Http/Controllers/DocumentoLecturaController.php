<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabCodes;
use App\Support\VeolabDocumentControl;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lecturas de documentos controlados (DOCLEC, desde Veolab 3; ISO 17025 8.3):
 * qué empleado ha confirmado que ha leído qué edición y cuándo. El
 * escritorio lo pregunta la primera vez que un empleado abre la edición
 * aprobada vigente; la API lo registra con POST.
 *
 *  - Alta: delegacion + documento; version (por defecto la edición aprobada
 *    vigente, VER2APR; debe ser una edición aprobada); el empleado
 *    (empleado_delegacion + empleado_codigo) o el usuario (usuario_delegacion
 *    + usuario_codigo), del que se toma su empleado: un usuario sin empleado
 *    no registra lecturas. fecha, por defecto ahora.
 *  - Solo de documentos controlados. Una lectura por documento, versión y
 *    empleado.
 *  - El listado añade el nombre del empleado y la edición leída.
 *  - Un acceso directo no tiene lecturas: se registran y consultan en su
 *    destino (422 que lo indica).
 */
class DocumentoLecturaController extends BaseController
{
    protected string $table = 'DOCLEC';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion'          => 'DEL3COD',
        'documento'           => 'FAT3COD',
        'version'             => 'VER3COD',
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
    ];

    protected array $mapping = [
        'delegacion'          => 'DEL3COD',
        'documento'           => 'FAT3COD',
        'version'             => 'VER3COD',
        'empleado_delegacion' => 'EMP3DEL',
        'empleado_codigo'     => 'EMP3COD',
        'fecha'               => 'LECTFEC',
    ];

    protected ?string $delegationKey = 'delegacion';

    /** Sin el script de conversión (tabla DOCLEC) tampoco hay recurso. */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabDocumentControl::available()) {
            return response()->json(['message' => 'El control de documentos requiere el script de conversión que crea DOCLEC y los campos de control'], 422);
        }
        if (! VeolabDocumentControl::active()) {
            return response()->json(['message' => VeolabDocumentControl::INACTIVE_MESSAGE], 422);
        }

        return null;
    }

    protected function rules(): array
    {
        return [
            'delegacion'          => 'nullable|string|max:10',
            'documento'           => 'required|integer|min:1',
            'version'             => 'nullable|integer|min:1',
            'empleado_delegacion' => 'nullable|string|max:10',
            'empleado_codigo'     => 'nullable|integer|min:1',
            'usuario_delegacion'  => 'nullable|string|max:10',
            'usuario_codigo'      => 'nullable|string|max:15',
            'fecha'               => 'nullable|date',
        ];
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $del = (string) ($data['delegacion'] ?? '');
        $cod = (int) $data['documento'];
        $data['delegacion'] = $del;

        $document = VeolabDocumentControl::document($del, $cod);
        if (! $document) {
            throw new BusinessRuleException('El documento no existe');
        }
        VeolabDocuments::assertNotShortcut($document, 'registrar su lectura');
        if (! VeolabDocumentControl::isControlled($document)) {
            throw new BusinessRuleException('Solo se registran lecturas de documentos controlados');
        }

        // Versión: la indicada o la edición aprobada vigente; siempre una edición aprobada.
        $version = (int) ($data['version'] ?? 0);
        if ($version <= 0) {
            $version = (int) ($document->VER2APR ?? 0);
            if ($version <= 0) {
                throw new BusinessRuleException('El documento aún no tiene ninguna edición aprobada');
            }
        }
        $versionRow = DB::connection('dynamic')->table('DOCVER')
            ->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $version)->first();
        if (! $versionRow) {
            throw new BusinessRuleException('La versión no existe');
        }
        // DCT_LecturaPendiente: solo la edición aprobada vigente de un documento no obsoleto.
        if ((string) ($document->FATCEST ?? '') === 'O') {
            throw new BusinessRuleException('El documento está obsoleto: no se registran lecturas');
        }
        if ($version !== (int) ($document->VER2APR ?? 0)) {
            throw new BusinessRuleException('Solo se registran lecturas de la edición aprobada vigente');
        }
        $data['version'] = $version;

        // Empleado: el indicado o el del usuario.
        if ((int) ($data['empleado_codigo'] ?? 0) > 0) {
            $data['empleado_delegacion'] = (string) ($data['empleado_delegacion'] ?? '');
            VeolabDocumentControl::assertEmployee($data['empleado_delegacion'], (int) $data['empleado_codigo']);
        } elseif (($data['usuario_codigo'] ?? '') !== '') {
            $user = VeolabDocumentControl::requestUser($data['usuario_delegacion'] ?? null, (string) $data['usuario_codigo']);
            if ($user['empleado'] === null) {
                throw new BusinessRuleException('El usuario no tiene empleado: las lecturas solo se registran a empleados');
            }
            [$data['empleado_delegacion'], $data['empleado_codigo']] = $user['empleado'];
        } else {
            throw new BusinessRuleException('Indique el empleado (empleado_codigo) o el usuario (usuario_codigo)');
        }
        unset($data['usuario_delegacion'], $data['usuario_codigo']);

        $exists = DB::connection('dynamic')->table('DOCLEC')
            ->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER3COD', $version)
            ->where('EMP3DEL', $data['empleado_delegacion'])->where('EMP3COD', (int) $data['empleado_codigo'])->exists();
        if ($exists) {
            throw new BusinessRuleException('La lectura de esta edición ya está registrada');
        }

        if (($data['fecha'] ?? null) === null) {
            $data['fecha'] = (string) DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
        }

        return $data;
    }

    /** Las lecturas de un acceso directo son las de su destino: 422 que lo indica. */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $code = $request->query('documento');
        if (! $request->has('delegacion') || ! is_string($code) || ! ctype_digit($code)) {
            return;
        }
        $document = VeolabDocumentControl::document((string) ($request->query('delegacion') ?? ''), (int) $code);
        if ($document) {
            VeolabDocuments::assertNotShortcut($document, 'consultar sus lecturas');
        }
    }

    /** Nombre del empleado y edición leída. */
    protected function appendRelatedData(array $rows): array
    {
        $names = VeolabDocumentControl::employeeNames(array_map(
            fn ($row) => [$row['empleado_delegacion'], $row['empleado_codigo']], $rows));
        $editions = [];
        foreach ($rows as &$row) {
            $key = $row['delegacion']."\0".$row['documento']."\0".$row['version'];
            $editions[$key] ??= DB::connection('dynamic')->table('DOCVER')
                ->where('DEL3COD', (string) $row['delegacion'])->where('FAT3COD', $row['documento'])
                ->where('VER1COD', $row['version'])->value('VERCEDI');
            $row['empleado_nombre'] = VeolabDocumentControl::employeeName($names, $row['empleado_delegacion'], $row['empleado_codigo']);
            $row['edicion'] = $editions[$key];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Fase 2: lecturas pendientes (destinatarios − lecturas)
    // ------------------------------------------------------------------

    /** 422 sin la fase 2 (o sin el control de documentos); null si se puede. */
    private function distributionUnavailable()
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabDocumentControl::distributionAvailable()) {
            return response()->json(['message' => VeolabDocumentControl::DISTRIBUTION_MESSAGE], 422);
        }

        return null;
    }

    /**
     * GET /documentos/lecturas/estado?delegacion=&codigo=
     * Lecturas de la edición vigente de un documento controlado, con sus
     * destinatarios ya resueltos en personas: cada una leida (con la fecha y
     * la edición leída), pendiente o vencida (pendiente con el plazo pasado),
     * más quien la ha leído sin ser destinatario (es_destinatario F). Vale la
     * lectura de una edición anterior si la vigente no requiere nueva lectura
     * (VERBLEC = F), desde la última que sí la requirió.
     */
    public function status(Request $request)
    {
        if ($unavailable = $this->distributionUnavailable()) {
            return $unavailable;
        }
        foreach (['delegacion', 'codigo'] as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }
        $del = (string) ($request->query('delegacion') ?? '');
        $cod = (int) $request->query('codigo');

        try {
            $document = VeolabDocumentControl::document($del, $cod);
            if (! $document) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            VeolabDocuments::assertNotShortcut($document, 'consultar sus lecturas');
            if (! VeolabDocumentControl::isControlled($document)) {
                throw new BusinessRuleException('El documento no está controlado');
            }
            $status = VeolabDocumentControl::readingStatus([$document])[VeolabDocumentControl::documentKey($del, $cod)] ?? null;
            $lines = VeolabDocumentControl::documentRecipientLines($document);

            $people = [];
            if ($status) {
                $editions = DB::connection('dynamic')->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)
                    ->pluck('VERCEDI', 'VER1COD')->all();
                $others = array_diff_key($status['lecturas'], $status['destinatarios']);
                $names = VeolabDocumentControl::employeeNames(array_map(fn ($r) => [$r['delegacion'], $r['codigo']], $others));
                $person = function (string $key, $del, $cod, ?string $name, bool $recipient) use ($status, $editions) {
                    $reading = $status['lecturas'][$key] ?? null;
                    $state = $reading ? 'leida' : ($status['vencida'] ? 'vencida' : 'pendiente');

                    return [
                        'empleado_delegacion' => (string) $del,
                        'empleado_codigo'     => (int) $cod,
                        'empleado_nombre'     => $name,
                        'es_destinatario'     => $recipient ? 'T' : 'F',
                        'estado'              => $state,
                        'fecha_lectura'       => $reading['fecha'] ?? null,
                        'version_leida'       => $reading['version'] ?? null,
                        'edicion_leida'       => $reading ? ($editions[$reading['version']] ?? null) : null,
                    ];
                };
                foreach ($status['destinatarios'] as $key => [$empDel, $empCod, $name]) {
                    $people[] = $person($key, $empDel, $empCod, $name, true);
                }
                foreach ($others as $key => $reading) {
                    $people[] = $person($key, $reading['delegacion'], $reading['codigo'],
                        VeolabDocumentControl::employeeName($names, $reading['delegacion'], $reading['codigo']), false);
                }
            }

            $recipients = $status ? count($status['destinatarios']) : 0;
            $pending = $status ? count($status['pendientes']) : 0;

            return response()->json([
                'data' => [
                    'delegacion'               => $del,
                    'codigo'                   => $cod,
                    'version_vigente'          => $status['version'] ?? null,
                    'edicion'                  => $status['edicion'] ?? null,
                    'fecha_aprobacion'         => $status['fecha_aprobacion'] ?? null,
                    'dias_lectura'             => $status['dias'] ?? VeolabDocumentControl::readingDays($document),
                    'fecha_limite'             => $status['fecha_limite'] ?? null,
                    'plazo_vencido'            => $status && $status['vencida'] ? 'T' : 'F',
                    'desde_version'            => $status['desde_version'] ?? null,
                    'destinatarios_origen'     => $lines['origen'],
                    'origen_carpeta_delegacion' => $lines['carpeta'][0] ?? null,
                    'origen_carpeta_codigo'    => $lines['carpeta'][1] ?? null,
                    'destinatarios'            => $recipients,
                    'leidas'                   => $status['leidas'] ?? 0,
                    'pendientes'               => $pending,
                    'vencidas'                 => $status && $status['vencida'] ? $pending : 0,
                    'lecturas'                 => $status ? "{$status['leidas']}/{$recipients}" : null,
                    'personas'                 => $people,
                ],
                'meta' => ['version_bd' => VeolabVersion::current()],
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return \App\Support\ServerError::response('v2 estado DOCLEC', $e, 'Error al leer las lecturas del documento');
        }
    }

    /**
     * GET /documentos/lecturas/pendientes?empleado_delegacion=&empleado_codigo=
     * (o ?usuario_delegacion=&usuario_codigo=, su empleado): los documentos
     * controlados en vigor cuya edición vigente tiene pendiente de leer como
     * destinatario, vencidas primero y después por fecha límite. Filtro
     * opcional delegacion (la del documento; lista con comas).
     */
    public function pending(Request $request)
    {
        if ($unavailable = $this->distributionUnavailable()) {
            return $unavailable;
        }

        try {
            if ($request->filled('empleado_codigo')) {
                $employee = [(string) ($request->query('empleado_delegacion') ?? ''), (int) $request->query('empleado_codigo')];
                VeolabDocumentControl::assertEmployee($employee[0], $employee[1]);
            } elseif ($request->filled('usuario_codigo')) {
                $user = VeolabDocumentControl::queryUser();
                if ($user['empleado'] === null) {
                    throw new BusinessRuleException('El usuario no tiene empleado: las lecturas solo se piden a empleados');
                }
                $employee = $user['empleado'];
            } else {
                throw new BusinessRuleException('Indique el empleado (empleado_codigo) o el usuario (usuario_codigo)');
            }

            $query = VeolabDocumentControl::inForceDocuments();
            if ($request->has('delegacion')) {
                $delegations = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('delegacion'))), fn ($v) => $v !== ''));
                $delegations ? $query->whereIn('DOCFAT.DEL3COD', $delegations) : $query->where('DOCFAT.DEL3COD', '');
            }
            $statuses = VeolabDocumentControl::readingStatus($query->get(), $employee);

            $data = [];
            foreach ($statuses as $status) {
                if (! $status['pendientes']) {
                    continue;
                }
                $document = $status['documento'];
                $data[] = [
                    'delegacion'       => (string) $document->DEL3COD,
                    'codigo'           => (int) $document->FAT1COD,
                    'codigo_documento' => $document->FATCCOD,
                    'titulo'           => $document->FATCNOM,
                    'extension'        => $document->FATCTIP,
                    'version'          => $status['version'],
                    'edicion'          => $status['edicion'],
                    'fecha_aprobacion' => $status['fecha_aprobacion'],
                    'dias_lectura'     => $status['dias'],
                    'fecha_limite'     => $status['fecha_limite'],
                    'vencida'          => $status['vencida'] ? 'T' : 'F',
                ];
            }
            usort($data, function (array $a, array $b) {
                if ($a['vencida'] !== $b['vencida']) {
                    return $a['vencida'] === 'T' ? -1 : 1;
                }

                return strcmp((string) $a['fecha_limite'], (string) $b['fecha_limite'])
                    ?: strcmp((string) $a['codigo_documento'], (string) $b['codigo_documento']);
            });

            return response()->json([
                'data' => $data,
                'meta' => [
                    'empleado_delegacion' => $employee[0],
                    'empleado_codigo'     => $employee[1],
                    'empleado_nombre'     => VeolabDocumentControl::staffName($employee[0], $employee[1]),
                    'total'               => count($data),
                    'vencidas'            => count(array_filter($data, fn ($d) => $d['vencida'] === 'T')),
                    'version_bd'          => VeolabVersion::current(),
                ],
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return \App\Support\ServerError::response('v2 pendientes DOCLEC', $e, 'Error al leer las lecturas pendientes');
        }
    }

    /** "nombre.ext - del-versión - empleado", como las versiones. */
    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        $document = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) ($keyParams['delegacion'] ?? ''))->where('FAT1COD', $keyParams['documento'] ?? 0)
            ->first(['FATCNOM', 'FATCTIP']);
        $name = $document ? VeolabDocuments::fullName((string) $document->FATCNOM, $document->FATCTIP) : '';

        return $name.' - '.VeolabCodes::format('', (string) ($keyParams['version'] ?? ''), (string) ($keyParams['delegacion'] ?? ''))
            .' - '.VeolabCodes::format('', (string) ($keyParams['empleado_codigo'] ?? ''), (string) ($keyParams['empleado_delegacion'] ?? ''));
    }
}
