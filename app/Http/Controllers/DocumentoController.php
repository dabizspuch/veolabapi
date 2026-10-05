<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Documentos de la gestión documental (DOCFAT), con su contenido en
 * DOCVER/DOCBLO (ver App\Support\VeolabDocuments).
 *
 *  - Un documento está en una carpeta (carpeta_*) y puede vincularse a una
 *    entidad (cliente_codigo, operacion_serie + operacion_codigo...), que es
 *    de la delegación del documento. La carpeta debe ser de la gestión
 *    documental de la tabla de la entidad (DIRCTAB), o de las carpetas
 *    generales (ZZZDIR) si no hay entidad; sin carpeta, la raíz de la tabla.
 *  - Papelera = carpeta 0 (carpeta_codigo[null]=T). DELETE manda a la
 *    papelera; con definitivo=T, o si ya estaba en ella, lo borra con sus
 *    versiones y bloques (Veolab se deja los bloques, la API no).
 *  - El alta y el contenido van en multipart/form-data (campo "fichero");
 *    las propiedades, en JSON como el resto de la API.
 */
class DocumentoController extends BaseController
{
    protected string $table = 'DOCFAT';
    protected ?string $auditDescription = 'FATCNOM';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'FAT1COD',
    ];
    protected array $searchFields = ['FATCNOM', 'FATCTIP', 'FATCDES'];

    protected array $mapping = [
        'delegacion'                 => 'DEL3COD',
        'codigo'                     => 'FAT1COD',
        'nombre'                     => 'FATCNOM',
        'extension'                  => 'FATCTIP',
        'descripcion'                => 'FATCDES',
        'tamano'                     => 'FATNTAM',
        'es_comprimido'              => 'FATBZIP',
        'es_solo_lectura'            => 'FATBLEC',
        'es_control_versiones'       => 'FATBVER',
        'fecha_creacion'             => 'FATTCRE',
        'fecha_modificacion'         => 'FATTMOD',
        'version_actual'             => 'VER2COD',
        'version_dual'               => 'VER2DUA',
        'carpeta_delegacion'         => 'DIR2DEL',
        'carpeta_codigo'             => 'DIR2COD',
        'proveedor_codigo'           => 'PRO2COD',
        'cliente_codigo'             => 'CLI2COD',
        'tecnica_codigo'             => 'TEC2COD',
        'equipamiento_codigo'        => 'EQU2COD',
        'empleado_codigo'            => 'EMP2COD',
        'curso_codigo'               => 'PAF2COD',
        'operacion_serie'            => 'OPE2SER',
        'operacion_codigo'           => 'OPE2COD',
        'orden_serie'                => 'ORD2SER',
        'orden_codigo'               => 'ORD2COD',
        'informe_serie'              => 'INF2SER',
        'informe_codigo'             => 'INF2COD',
        'lote_serie'                 => 'LOT2SER',
        'lote_codigo'                => 'LOT2COD',
        'planificacion_codigo'       => 'PLO2COD',
        'agenda_serie'               => 'AGE2SER', // usuario del calendario
        'agenda_codigo'              => 'AGE2COD',
        'contrato_serie'             => 'CON2SER',
        'contrato_codigo'            => 'CON2COD',
        'presupuesto_serie'          => 'PRE2SER',
        'presupuesto_codigo'         => 'PRE2COD',
        'factura_serie'              => 'FAC2SER',
        'factura_codigo'             => 'FAC2COD',
        'producto_codigo'            => 'PRD2COD',
        'producto_serie_lote_codigo' => 'SEL2COD',
        'carta_control_codigo'       => 'CDC2COD',
        'prestamo_codigo'            => 'PRT2COD',
    ];

    protected array $foreignKeys = [
        'carpeta'             => 'int',
        'proveedor'           => 'string',
        'cliente'             => 'string',
        'tecnica'             => 'string',
        'equipamiento'        => 'string',
        'empleado'            => 'int',
        'curso'               => 'string',
        'operacion'           => 'int',
        'orden'               => 'int',
        'informe'             => 'int',
        'lote'                => 'string',
        'planificacion'       => 'int',
        'agenda'              => 'int',
        'contrato'            => 'int',
        'presupuesto'         => 'int',
        'factura'             => 'int',
        'producto'            => 'string',
        'producto_serie_lote' => 'string',
        'carta_control'       => 'int',
        'prestamo'            => 'int',
    ];

    /** Longitud máxima (o 'int') de cada parámetro de entidad. */
    private const ENTITY_RULES = [
        'proveedor_codigo' => 15, 'cliente_codigo' => 15, 'tecnica_codigo' => 30,
        'equipamiento_codigo' => 20, 'empleado_codigo' => 'int', 'curso_codigo' => 15,
        'operacion_serie' => 10, 'operacion_codigo' => 'int', 'orden_serie' => 10, 'orden_codigo' => 'int',
        'informe_serie' => 10, 'informe_codigo' => 'int', 'lote_serie' => 10, 'lote_codigo' => 50,
        'planificacion_codigo' => 'int', 'agenda_serie' => 15, 'agenda_codigo' => 'int',
        'contrato_serie' => 10, 'contrato_codigo' => 'int', 'presupuesto_serie' => 10, 'presupuesto_codigo' => 'int',
        'factura_serie' => 10, 'factura_codigo' => 'int', 'producto_codigo' => 15,
        'producto_serie_lote_codigo' => 30, 'carta_control_codigo' => 'int', 'prestamo_codigo' => 'int',
    ];

    /** Reglas de las propiedades editables (PUT). */
    protected function rules(): array
    {
        return [
            'nombre'               => 'sometimes|required|string|max:255',
            'extension'            => 'sometimes|nullable|string|max:50',
            'descripcion'          => 'sometimes|nullable|string|max:255',
            'es_solo_lectura'      => 'sometimes|string|in:T,F',
            'es_control_versiones' => 'sometimes|string|in:T,F',
            'carpeta_delegacion'   => 'sometimes|nullable|string|max:10',
            'carpeta_codigo'       => 'sometimes|nullable|integer|min:1',
        ] + $this->entityRules();
    }

    private function entityRules(): array
    {
        $rules = [];
        foreach (self::ENTITY_RULES as $param => $type) {
            $rules[$param] = 'sometimes|nullable|'.($type === 'int' ? 'integer|min:0' : "string|max:{$type}");
        }

        return $rules;
    }

    /**
     * PUT: vincular a otra entidad (los parámetros de entidad sustituyen al
     * vínculo entero) o mover de carpeta. Si cambia la entidad y no se indica
     * carpeta, va a la raíz de la nueva tabla.
     */
    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $row = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('FAT1COD', $keys['codigo'])->first();

        if (array_key_exists('carpeta_codigo', $data) && empty($data['carpeta_codigo'])) {
            throw new BusinessRuleException('Para enviar el documento a la papelera se usa DELETE');
        }

        $entityGiven = (bool) array_intersect(VeolabDocuments::entityParams(), array_keys($data));
        $folderGiven = ! empty($data['carpeta_codigo']);
        if (! $entityGiven && ! $folderGiven) {
            return $data;
        }

        $current = $this->rowParams($row);
        $currentFolder = VeolabDocuments::folder((string) $row->DIR2DEL, (int) $row->DIR2COD);
        if ($currentFolder && $currentFolder->DIRCTAB === VeolabDocuments::TEMPLATES) {
            throw new BusinessRuleException('Las plantillas de exportación se gestionan desde Veolab');
        }

        if ($entityGiven) {
            // El vínculo se sustituye: lo no indicado queda vacío.
            foreach (VeolabDocuments::entityParams() as $param) {
                $data[$param] ??= null;
            }
            $table = VeolabDocuments::entityTable($data);
            if ($table) {
                VeolabDocuments::checkEntity($table, $data, (string) $row->DEL3COD);
            }
        } else {
            $table = VeolabDocuments::entityTable($current);
        }

        if ($folderGiven) {
            $folder = VeolabDocuments::resolveFolder($table, $data['carpeta_delegacion'] ?? '', (int) $data['carpeta_codigo']);
        } elseif ((int) $row->DIR2COD === 0) {
            return $data; // En la papelera sigue en la papelera.
        } elseif ($currentFolder && $currentFolder->DIRCTAB === ($table ?? VeolabDocuments::GENERAL)) {
            return $data;
        } else {
            $folder = VeolabDocuments::resolveFolder($table, null, null);
        }

        $data['carpeta_delegacion'] = (string) $folder->DEL3COD;
        $data['carpeta_codigo'] = (int) $folder->DIR1COD;

        return $data;
    }

    /** Fila de DOCFAT como parámetros (para saber su entidad). */
    private function rowParams(object $row): array
    {
        $out = [];
        foreach ($this->mapping as $param => $column) {
            $out[$param] = $row->{$column} ?? null;
        }

        return $out;
    }

    /** Tabla de la entidad vinculada y si está en la papelera. */
    protected function appendRelatedData(array $rows): array
    {
        foreach ($rows as &$row) {
            try {
                $row['tabla'] = VeolabDocuments::entityTable($row);
            } catch (BusinessRuleException) {
                $row['tabla'] = null; // vinculado a varias entidades (dato de Veolab incoherente)
            }
            $row['en_papelera'] = $row['carpeta_codigo'] === null ? 'T' : 'F';
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Alta (multipart)
    // ------------------------------------------------------------------

    /**
     * Alta de un documento con su primera versión. Campos: fichero (obligatorio),
     * delegacion, carpeta_delegacion + carpeta_codigo, la entidad, nombre y
     * extension (por defecto los del fichero), descripcion, es_solo_lectura,
     * es_control_versiones, version_nombre, version_descripcion y el autor
     * (usuario_delegacion + usuario_codigo). Se comprime según ACCPAR.PARBZIP.
     */
    public function store(Request $request)
    {
        try {
            $data = Validator::make($request->all(), [
                'fichero'              => 'required|file',
                'delegacion'           => 'nullable|string|max:10',
                'carpeta_delegacion'   => 'nullable|string|max:10',
                'carpeta_codigo'       => 'nullable|integer|min:1',
                'nombre'               => 'nullable|string|max:255',
                'extension'            => 'nullable|string|max:50',
                'descripcion'          => 'nullable|string|max:255',
                'es_solo_lectura'      => 'nullable|string|in:T,F',
                'es_control_versiones' => 'nullable|string|in:T,F',
            ] + $this->versionRules() + $this->entityRules())->validate();

            $delegation = (string) ($data['delegacion'] ?? '');
            if ($delegation !== '' && ! DB::connection('dynamic')->table('ACCDEL')->where('DEL1COD', $delegation)->exists()) {
                throw new BusinessRuleException('La delegación no existe');
            }

            [$name, $extension] = VeolabDocuments::splitName($request->file('fichero')->getClientOriginalName());
            $name = ($data['nombre'] ?? '') !== '' ? $data['nombre'] : $name;
            $extension = array_key_exists('extension', $data) ? (string) $data['extension'] : $extension;
            if ($name === '') {
                throw new BusinessRuleException('El documento debe tener nombre');
            }

            $table = VeolabDocuments::entityTable($data);
            if ($table) {
                VeolabDocuments::checkEntity($table, $data, $delegation);
            }
            $folder = VeolabDocuments::resolveFolder($table, $data['carpeta_delegacion'] ?? '',
                isset($data['carpeta_codigo']) ? (int) $data['carpeta_codigo'] : null);
            $this->checkAuthor($data);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $compress = VeolabDocuments::compressByDefault();
        $zipName = VeolabDocuments::zipBaseName($name);
        $temporary = null;
        $db = DB::connection('dynamic');

        try {
            [$path, $size, $temporary] = VeolabDocuments::prepare(
                $request->file('fichero')->getRealPath(), $compress, VeolabDocuments::fullName($zipName, $extension));

            $code = VeolabDocuments::reserveDocumentCode($delegation);
            $firstBlock = VeolabDocuments::reserveBlockCodes($delegation, VeolabDocuments::blockCount($size));

            $db->beginTransaction();

            $document = [
                'DEL3COD' => $delegation,
                'FAT1COD' => $code,
                'FATCNOM' => $name,
                'FATCTIP' => $extension,
                'FATCDES' => $data['descripcion'] ?? null,
                'FATNTAM' => $size,
                'FATBZIP' => $compress ? 'T' : 'F',
                'FATBLEC' => $data['es_solo_lectura'] ?? 'F',
                'FATTCRE' => DB::raw('NOW()'),
                'FATTMOD' => DB::raw('NOW()'),
                'FATBVER' => $data['es_control_versiones'] ?? 'F',
                'VER2COD' => 1,
                'DIR2DEL' => (string) $folder->DEL3COD,
                'DIR2COD' => (int) $folder->DIR1COD,
            ];
            if ($compress) {
                $document['FATCNOC'] = $zipName; // nombre (ASCII) de la entrada del ZIP
            }
            foreach ($table ? VeolabDocuments::ENTITIES[$table][2] : [] as $param) {
                $document[$this->mapping[$param]] = $data[$param] ?? '';
            }
            $db->table('DOCFAT')->insert($document);

            $this->insertVersion($delegation, $code, 1, $compress, $size, $data);
            VeolabDocuments::writeBlocks($path, $delegation, $code, 1, $firstBlock);

            VeolabAudit::record(VeolabAudit::INSERCION, 'DOCFAT',
                $this->auditRow(['delegacion' => $delegation, 'codigo' => $code], $name));

            $db->commit();

            return response()->json([
                'message' => 'Documento creado correctamente',
                'data'    => ['delegacion' => $delegation, 'codigo' => $code],
            ], 201);
        } catch (BusinessRuleException $e) {
            $this->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->rollBack();
            return ServerError::response('v2 store DOCFAT', $e, 'Error al crear el documento');
        } finally {
            if ($temporary) {
                @unlink($temporary);
            }
        }
    }

    // ------------------------------------------------------------------
    // Borrado (papelera)
    // ------------------------------------------------------------------

    public function destroy(Request $request)
    {
        [$delegation, $code] = $this->documentKeys($request);
        $db = DB::connection('dynamic');

        $row = $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->first();
        if (! $row) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        if ($db->table('PLAPLA')->where('DEL3COD', $delegation)->where('FAT2COD', $code)->exists()) {
            return response()->json(['message' => 'El documento es la plantilla de alguna exportación'], 422);
        }

        $final = $request->query('definitivo') === 'T' || (int) $row->DIR2COD === 0;

        try {
            $db->beginTransaction();

            if ($final) {
                VeolabDocuments::deleteBlocks($delegation, $code);
                $db->table('DOCVER')->where('DEL3COD', $delegation)->where('FAT3COD', $code)->delete();
                $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->delete();
            } else {
                $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)
                    ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);
            }

            VeolabAudit::record(VeolabAudit::BORRADO, 'DOCFAT',
                $this->auditRow(['delegacion' => $delegation, 'codigo' => $code], (string) $row->FATCNOM));

            $db->commit();

            return response()->json(['message' => $final
                ? 'Documento eliminado correctamente'
                : 'Documento enviado a la papelera']);
        } catch (\Throwable $e) {
            $this->rollBack();
            return ServerError::response('v2 destroy DOCFAT', $e, 'Error al eliminar el documento');
        }
    }

    // ------------------------------------------------------------------
    // Contenido
    // ------------------------------------------------------------------

    /** GET /documentos/contenido?delegacion=&codigo=[&version=][&inline=T] */
    public function download(Request $request)
    {
        [$delegation, $code] = $this->documentKeys($request);
        $db = DB::connection('dynamic');

        $row = $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->first();
        if (! $row) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        // Como DOC_AbrirDocumento: la versión pedida dice si está comprimida
        // (VERBZIP); la actual, el documento (FATBZIP).
        if ($request->filled('version')) {
            $version = (int) $request->query('version');
            $versionRow = $db->table('DOCVER')->where('DEL3COD', $delegation)
                ->where('FAT3COD', $code)->where('VER1COD', $version)->first();
            if (! $versionRow) {
                return response()->json(['message' => 'La versión no existe'], 404);
            }
            $compressed = $versionRow->VERBZIP === 'T';
        } else {
            $version = (int) $row->VER2COD;
            $compressed = $row->FATBZIP === 'T';
        }

        try {
            return VeolabDocuments::download($row, $version, $compressed, $request->query('inline') === 'T');
        } catch (\Throwable $e) {
            return ServerError::response('v2 download DOCFAT', $e, 'Error al leer el documento');
        }
    }

    /**
     * POST /documentos/contenido?delegacion=&codigo= (multipart): contenido
     * nuevo, como al guardar un documento abierto en Veolab:
     *  - con control de versiones, versión nueva (nueva_version=F sobrescribe);
     *  - si no, con versión dual (PARBDUA), versión nueva y se borra la dual
     *    anterior, que pasa a ser la actual de antes;
     *  - si no, se sobrescribe la versión actual.
     * Se comprime como el documento (FATBZIP). No se admite en documentos de
     * solo lectura ni en la papelera.
     */
    public function upload(Request $request)
    {
        [$delegation, $code] = $this->documentKeys($request);
        $db = DB::connection('dynamic');

        try {
            $data = Validator::make($request->all(), [
                'fichero'       => 'required|file',
                'nueva_version' => 'nullable|string|in:T,F',
            ] + $this->versionRules())->validate();
            $this->checkAuthor($data);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $row = $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->first();
        if (! $row) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }
        if ($row->FATBLEC === 'T') {
            return response()->json(['message' => 'El documento es de solo lectura'], 422);
        }
        if ((int) $row->DIR2COD === 0) {
            return response()->json(['message' => 'El documento está en la papelera'], 422);
        }

        $compress = $row->FATBZIP === 'T';
        $temporary = null;

        try {
            [$path, $size, $temporary] = VeolabDocuments::prepare($request->file('fichero')->getRealPath(), $compress,
                VeolabDocuments::fullName((string) ($row->FATCNOC ?: $row->FATCNOM), $row->FATCTIP));
            $firstBlock = VeolabDocuments::reserveBlockCodes($delegation, VeolabDocuments::blockCount($size));

            $db->beginTransaction();

            $row = $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->lockForUpdate()->first();
            $current = (int) $row->VER2COD;

            if ($row->FATBVER === 'T') {
                $newVersion = ($data['nueva_version'] ?? 'T') === 'T';
                $dual = false;
            } else {
                $dual = VeolabDocuments::dualVersion();
                $newVersion = $dual;
            }

            $update = ['FATTMOD' => DB::raw('NOW()'), 'FATNTAM' => $size];

            if ($dual && (int) $row->VER2DUA > 0) {
                VeolabDocuments::deleteBlocks($delegation, $code, (int) $row->VER2DUA);
                $db->table('DOCVER')->where('DEL3COD', $delegation)->where('FAT3COD', $code)
                    ->where('VER1COD', (int) $row->VER2DUA)->delete();
            }
            if ($dual) {
                $update['VER2DUA'] = $current;
            }

            if ($newVersion) {
                $version = (int) $db->table('DOCVER')->where('DEL3COD', $delegation)->where('FAT3COD', $code)->max('VER1COD') + 1;
                $this->insertVersion($delegation, $code, $version, $compress, $size, $data);
            } else {
                $version = $current;
                VeolabDocuments::deleteBlocks($delegation, $code, $version);
                $db->table('DOCVER')->where('DEL3COD', $delegation)->where('FAT3COD', $code)
                    ->where('VER1COD', $version)->update(['VERNTAM' => $size]);
            }

            $update['VER2COD'] = $version;
            $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->update($update);

            VeolabDocuments::writeBlocks($path, $delegation, $code, $version, $firstBlock);

            VeolabAudit::record(VeolabAudit::MODIFICACION, 'DOCBLO',
                $this->auditRow(['delegacion' => $delegation, 'codigo' => $code], (string) $row->FATCNOM));

            $db->commit();

            return response()->json([
                'message' => $newVersion ? 'Nueva versión guardada correctamente' : 'Contenido actualizado correctamente',
                'data'    => ['delegacion' => $delegation, 'codigo' => $code, 'version' => $version],
            ]);
        } catch (BusinessRuleException $e) {
            $this->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->rollBack();
            return ServerError::response('v2 upload DOCFAT', $e, 'Error al guardar el contenido');
        } finally {
            if ($temporary) {
                @unlink($temporary);
            }
        }
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    private function versionRules(): array
    {
        return [
            'version_nombre'      => 'nullable|string|max:50',
            'version_descripcion' => 'nullable|string|max:255',
            'usuario_delegacion'  => 'nullable|string|max:10',
            'usuario_codigo'      => 'nullable|string|max:15',
        ];
    }

    /** El autor de la versión, si se indica, es un usuario de Veolab. */
    private function checkAuthor(array $data): void
    {
        if (($data['usuario_codigo'] ?? '') === '') {
            return;
        }
        $exists = DB::connection('dynamic')->table('ACCUSU')
            ->where('DEL3COD', (string) ($data['usuario_delegacion'] ?? ''))
            ->where('USU1COD', $data['usuario_codigo'])->exists();
        if (! $exists) {
            throw new BusinessRuleException('El usuario no existe');
        }
    }

    private function insertVersion(string $delegation, int $code, int $version, bool $compressed, int $size, array $data): void
    {
        DB::connection('dynamic')->table('DOCVER')->insert([
            'DEL3COD' => $delegation,
            'FAT3COD' => $code,
            'VER1COD' => $version,
            'VERCNOM' => (string) ($data['version_nombre'] ?? ''),
            'VERCDES' => (string) ($data['version_descripcion'] ?? ''),
            'VERNTAM' => $size,
            'VERTEMI' => DB::raw('NOW()'),
            'VERBZIP' => $compressed ? 'T' : 'F',
            'USU2DEL' => ($data['usuario_codigo'] ?? '') === '' ? '' : (string) ($data['usuario_delegacion'] ?? ''),
            'USU2COD' => (string) ($data['usuario_codigo'] ?? ''),
        ]);
    }

    /** Clave completa del documento en la query string. */
    private function documentKeys(Request $request): array
    {
        foreach (['delegacion', 'codigo'] as $param) {
            if (! $request->has($param)) {
                abort(response()->json(['message' => "Falta la clave '{$param}'"], 400));
            }
        }

        return [(string) ($request->query('delegacion') ?? ''), (int) $request->query('codigo')];
    }

    private function rollBack(): void
    {
        if (DB::connection('dynamic')->transactionLevel() > 0) {
            DB::connection('dynamic')->rollBack();
        }
    }
}
