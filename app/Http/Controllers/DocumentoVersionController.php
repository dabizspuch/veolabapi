<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabDocumentControl;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Versiones de un documento (DOCVER), como la pestaña de versiones de las
 * propiedades del documento en Veolab. Las versiones nuevas se crean al subir
 * contenido (POST /documentos/contenido).
 *
 *  - es_actual=T restablece la versión como principal (DOCFAT.VER2COD); no
 *    en un documento controlado, cuya última versión es siempre la principal
 *    (para volver a la aprobada, POST /documentos/descartar).
 *  - La versión actual no se puede borrar, ni la edición aprobada vigente
 *    (VER2APR); al borrar una versión se borra también su contenido (DOCBLO)
 *    y sus lecturas (DOCLEC).
 *  - Datos de la edición aprobada (Veolab 3): edicion, motivo, fecha_edicion,
 *    aprobada_por_* y fecha_aprobacion. Se ponen al aprobar (POST
 *    /documentos/aprobar); en un PUT solo se corrigen en versiones aprobadas
 *    y con el privilegio de aprobar (?usuario_delegacion=&usuario_codigo=).
 *  - Fase 2 (con su script), solo lectura: estado_edicion (B borrador, R
 *    revisada, A aprobada), revisada_por_*, fecha_revision,
 *    comentario_revision (o motivo de la devolución) y requiere_lectura.
 *    Se ponen con POST /documentos/revisar-edicion, devolver-edicion y
 *    aprobar.
 *  - Auditoría como Veolab: fila "documento.ext - del-versión".
 *  - Un acceso directo (Veolab 3) no tiene versiones: pedir las de uno con
 *    ?delegacion=&documento= da 422 indicando su destino.
 */
class DocumentoVersionController extends BaseController
{
    protected string $table = 'DOCVER';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'documento'  => 'FAT3COD',
        'codigo'     => 'VER1COD',
    ];
    protected array $searchFields = ['VERCNOM', 'VERCDES', 'VERCEDI'];

    protected array $mapping = [
        'delegacion'              => 'DEL3COD',
        'documento'               => 'FAT3COD',
        'codigo'                  => 'VER1COD',
        'nombre'                  => 'VERCNOM',
        'descripcion'             => 'VERCDES',
        'tamano'                  => 'VERNTAM',
        'fecha'                   => 'VERTEMI',
        'es_comprimido'           => 'VERBZIP',
        'usuario_delegacion'      => 'USU2DEL',
        'usuario_codigo'          => 'USU2COD',
        // Edición aprobada (control de documentos, Veolab 3)
        'edicion'                 => 'VERCEDI',
        'motivo'                  => 'VERCMOT',
        'fecha_edicion'           => 'VERDEDI',
        'aprobada_por_delegacion' => 'EMA2DEL',
        'aprobada_por_codigo'     => 'EMA2COD',
        'fecha_aprobacion'        => 'VERDAPR',
        // Fase 2 (solo lectura: se ponen al revisar, devolver y aprobar)
        'requiere_lectura'        => 'VERBLEC',
        'estado_edicion'          => 'VERCEST',
        'revisada_por_delegacion' => 'EMR2DEL',
        'revisada_por_codigo'     => 'EMR2COD',
        'fecha_revision'          => 'VERDREV',
        'comentario_revision'     => 'VERCREV',
    ];

    /** Campos de la fase 2 del control (DOCVER.VERBLEC, VERCEST, EMR2*, VERDREV, VERCREV). */
    private const DISTRIBUTION_PARAMS = ['requiere_lectura', 'estado_edicion', 'revisada_por_delegacion',
        'revisada_por_codigo', 'fecha_revision', 'comentario_revision'];

    protected array $since = [
        'edicion'                 => VeolabVersion::V3,
        'motivo'                  => VeolabVersion::V3,
        'fecha_edicion'           => VeolabVersion::V3,
        'aprobada_por_delegacion' => VeolabVersion::V3,
        'aprobada_por_codigo'     => VeolabVersion::V3,
        'fecha_aprobacion'        => VeolabVersion::V3,
        'requiere_lectura'        => VeolabVersion::V3,
        'estado_edicion'          => VeolabVersion::V3,
        'revisada_por_delegacion' => VeolabVersion::V3,
        'revisada_por_codigo'     => VeolabVersion::V3,
        'fecha_revision'          => VeolabVersion::V3,
        'comentario_revision'     => VeolabVersion::V3,
    ];

    protected array $foreignKeys = [
        'usuario'      => 'string',
        'aprobada_por' => 'int',
        'revisada_por' => 'int',
    ];

    /** Sin el script de la fase 2, sus campos no están disponibles. */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if ($this->supports('estado_edicion') && ! VeolabDocumentControl::distributionAvailable()) {
            foreach (self::DISTRIBUTION_PARAMS as $param) {
                $this->unsupported[$param] = VeolabVersion::V3;
                unset($this->mapping[$param]);
            }
            unset($this->foreignKeys['revisada_por']);
        }

        return null;
    }

    /** Datos de la edición aprobada. */
    private const APPROVAL_PARAMS = ['edicion', 'motivo', 'fecha_edicion', 'aprobada_por_delegacion',
        'aprobada_por_codigo', 'fecha_aprobacion'];

    protected function rules(): array
    {
        return [
            'nombre'                  => 'sometimes|nullable|string|max:50',
            'descripcion'             => 'sometimes|nullable|string|max:255',
            'es_actual'               => 'sometimes|string|in:T',
            'edicion'                 => 'sometimes|nullable|string|max:50',
            'motivo'                  => 'sometimes|nullable|string|max:255',
            'fecha_edicion'           => 'sometimes|nullable|date',
            'aprobada_por_delegacion' => 'sometimes|nullable|string|max:10',
            'aprobada_por_codigo'     => 'sometimes|nullable|integer|min:0',
            'fecha_aprobacion'        => 'sometimes|nullable|date',
        ];
    }

    /** Las versiones de un acceso directo son las de su destino: 422 que lo indica. */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $code = $request->query('documento');
        if (! $request->has('delegacion') || ! is_string($code) || ! ctype_digit($code)) {
            return;
        }
        $document = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) ($request->query('delegacion') ?? ''))->where('FAT1COD', (int) $code)->first();
        if ($document) {
            VeolabDocuments::assertNotShortcut($document, 'consultar sus versiones');
        }
    }

    /** Restablecer y datos de la edición aprobada (ver la cabecera). */
    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $document = $db->table('DOCFAT')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('FAT1COD', $keys['documento'])->first();
        $control = $document && VeolabDocumentControl::available();

        if (($data['es_actual'] ?? null) === 'T' && $control && VeolabDocumentControl::isControlled($document)
            && (int) $document->VER2COD !== (int) $keys['codigo']) {
            throw new BusinessRuleException('En un documento controlado la última versión es siempre la principal; '
                .'para volver a la edición aprobada use POST /documentos/descartar');
        }

        if (array_intersect(array_keys($data), self::APPROVAL_PARAMS)) {
            VeolabDocumentControl::assertActive();   // sin Calidad se leen, no se escriben
            $version = $db->table('DOCVER')->where('DEL3COD', (string) $keys['delegacion'])
                ->where('FAT3COD', $keys['documento'])->where('VER1COD', $keys['codigo'])->first();
            $approved = ($version && ! empty($version->VERDAPR))
                || ($document && (int) ($document->VER2APR ?? 0) === (int) $keys['codigo']);
            if (! $approved) {
                throw new BusinessRuleException('Los datos de la edición solo se indican en versiones aprobadas; se ponen al aprobar (POST /documentos/aprobar)');
            }
            VeolabDocumentControl::assertApprover(VeolabDocumentControl::queryUser(), 'cambiar los datos de una edición aprobada');
            if (array_key_exists('edicion', $data) && trim((string) $data['edicion']) === '') {
                throw new BusinessRuleException('La edición aprobada debe tener edición');
            }
            if ((int) ($data['aprobada_por_codigo'] ?? 0) > 0) {
                VeolabDocumentControl::assertEmployee((string) ($data['aprobada_por_delegacion'] ?? ''),
                    (int) $data['aprobada_por_codigo'], 'El empleado que la aprueba');
            }
        }

        return $data;
    }

    /** Restablece la versión como principal del documento. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if (($data['es_actual'] ?? null) !== 'T') {
            return $data;
        }

        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('FAT1COD', $keys['documento'])
            ->update(['VER2COD' => (int) $keys['codigo']]);

        VeolabAudit::record(VeolabAudit::MODIFICACION, 'DOCVER', $this->auditRow($keys));

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $document = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('FAT1COD', $keys['documento'])
            ->first();
        if (! $document) {
            return;
        }

        if ((int) $document->VER2COD === (int) $keys['codigo']) {
            throw new BusinessRuleException('La versión actual del documento no se puede eliminar');
        }
        // PropiedadesDocumento (MEN00901): las versiones de un documento controlado son registro (ISO 17025 8.3) y no se borran.
        if (VeolabDocumentControl::isControlled($document)) {
            throw new BusinessRuleException('El documento está controlado: sus versiones se conservan y no se pueden eliminar');
        }
        if (VeolabDocumentControl::available() && (int) ($document->VER2APR ?? 0) === (int) $keys['codigo']) {
            throw new BusinessRuleException('La edición aprobada vigente del documento no se puede eliminar');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        VeolabDocuments::deleteBlocks((string) $keys['delegacion'], (int) $keys['documento'], (int) $keys['codigo']);
        VeolabDocumentControl::deleteReadings((string) $keys['delegacion'], (int) $keys['documento'], [(int) $keys['codigo']]);
    }

    /** Si es la versión actual o la dual del documento y, desde Veolab 3, la edición aprobada vigente. */
    protected function appendRelatedData(array $rows): array
    {
        $control = $this->supports('edicion');
        $documents = [];
        foreach ($rows as &$row) {
            $key = $row['delegacion']."\0".$row['documento'];
            $documents[$key] ??= DB::connection('dynamic')->table('DOCFAT')
                ->where('DEL3COD', (string) $row['delegacion'])->where('FAT1COD', $row['documento'])
                ->first();

            $document = $documents[$key];
            $row['es_actual'] = $document && (int) $document->VER2COD === (int) $row['codigo'] ? 'T' : 'F';
            $row['es_dual'] = $document && (int) $document->VER2DUA === (int) $row['codigo'] ? 'T' : 'F';
            if ($control) {
                $row['es_aprobada'] = $document && (int) ($document->VER2APR ?? 0) === (int) $row['codigo'] ? 'T' : 'F';
            }
            if ($this->supports('estado_edicion')) {
                // Vacío en las versiones anteriores a la fase 2: A si tiene fecha de aprobación, B si no.
                $row['estado_edicion'] = VeolabDocumentControl::editionState(
                    (object) ['VERCEST' => $row['estado_edicion'], 'VERDAPR' => $row['fecha_aprobacion'] ?? null]);
                $row['requiere_lectura'] = VeolabDocumentControl::requiresReading((object) ['VERBLEC' => $row['requiere_lectura']]) ? 'T' : 'F';
            }
        }

        return $rows;
    }

    /** "nombre.ext - del-versión", como PropiedadesDocumento. */
    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        $document = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) $keyParams['delegacion'])->where('FAT1COD', $keyParams['documento'])
            ->first(['FATCNOM', 'FATCTIP']);
        $name = $document ? VeolabDocuments::fullName((string) $document->FATCNOM, $document->FATCTIP) : '';

        return $name.' - '.VeolabCodes::format('', (string) $keyParams['codigo'], (string) $keyParams['delegacion']);
    }
}
