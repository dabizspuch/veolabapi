<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabDocuments;
use Illuminate\Support\Facades\DB;

/**
 * Versiones de un documento (DOCVER), como la pestaña de versiones de las
 * propiedades del documento en Veolab. Las versiones nuevas se crean al subir
 * contenido (POST /documentos/contenido).
 *
 *  - es_actual=T restablece la versión como principal (DOCFAT.VER2COD).
 *  - La versión actual no se puede borrar; al borrar una versión se borra
 *    también su contenido (DOCBLO).
 *  - Auditoría como Veolab: fila "documento.ext - del-versión".
 */
class DocumentoVersionController extends BaseController
{
    protected string $table = 'DOCVER';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'documento'  => 'FAT3COD',
        'codigo'     => 'VER1COD',
    ];
    protected array $searchFields = ['VERCNOM', 'VERCDES'];

    protected array $mapping = [
        'delegacion'         => 'DEL3COD',
        'documento'          => 'FAT3COD',
        'codigo'             => 'VER1COD',
        'nombre'             => 'VERCNOM',
        'descripcion'        => 'VERCDES',
        'tamano'             => 'VERNTAM',
        'fecha'              => 'VERTEMI',
        'es_comprimido'      => 'VERBZIP',
        'usuario_delegacion' => 'USU2DEL',
        'usuario_codigo'     => 'USU2COD',
    ];

    protected array $foreignKeys = ['usuario' => 'string'];

    protected function rules(): array
    {
        return [
            'nombre'      => 'sometimes|nullable|string|max:50',
            'descripcion' => 'sometimes|nullable|string|max:255',
            'es_actual'   => 'sometimes|string|in:T',
        ];
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
        $current = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('FAT1COD', $keys['documento'])
            ->value('VER2COD');

        if ((int) $current === (int) $keys['codigo']) {
            throw new BusinessRuleException('La versión actual del documento no se puede eliminar');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        VeolabDocuments::deleteBlocks((string) $keys['delegacion'], (int) $keys['documento'], (int) $keys['codigo']);
    }

    /** Si es la versión actual o la dual del documento. */
    protected function appendRelatedData(array $rows): array
    {
        $documents = [];
        foreach ($rows as &$row) {
            $key = $row['delegacion']."\0".$row['documento'];
            $documents[$key] ??= DB::connection('dynamic')->table('DOCFAT')
                ->where('DEL3COD', (string) $row['delegacion'])->where('FAT1COD', $row['documento'])
                ->first(['VER2COD', 'VER2DUA']);

            $document = $documents[$key];
            $row['es_actual'] = $document && (int) $document->VER2COD === (int) $row['codigo'] ? 'T' : 'F';
            $row['es_dual'] = $document && (int) $document->VER2DUA === (int) $row['codigo'] ? 'T' : 'F';
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
