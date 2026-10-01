<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabDocuments;
use Illuminate\Support\Facades\DB;

/**
 * Carpetas de la gestión documental (DOCDIR), como el explorador de Veolab.
 *
 *  - Las raíces (carpeta_padre vacía) las crea Veolab, una por funcionalidad
 *    (DIRCTAB: SINCLI, LABOPE... y ZZZDIR para las carpetas generales), y no
 *    se modifican ni se borran.
 *  - Una subcarpeta hereda la tabla de su padre y no se mueve. Una carpeta
 *    pública (delegación vacía) solo puede colgar de otra pública.
 *  - Solo se borran carpetas vacías (sin subcarpetas ni documentos).
 *  - compartir (DIRCCOM): U usuarios, E empleados, C clientes, O otros,
 *    P perfiles (/documentos/carpetas/perfiles). La API no aplica privilegios.
 */
class CarpetaDocumentoController extends BaseController
{
    protected string $table = 'DOCDIR';
    protected ?string $auditDescription = 'DIRCNOM';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'DIR1COD',
    ];
    protected array $searchFields = ['DIRCNOM', 'DIRCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'               => 'DEL3COD',
        'codigo'                   => 'DIR1COD',
        'nombre'                   => 'DIRCNOM',
        'descripcion'              => 'DIRCDES',
        'tabla'                    => 'DIRCTAB',
        'compartir'                => 'DIRCCOM',
        'carpeta_padre_delegacion' => 'DIR2DEL',
        'carpeta_padre_codigo'     => 'DIR2COD',
    ];

    protected array $foreignKeys = ['carpeta_padre' => 'int'];

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'delegacion'               => 'nullable|string|max:10',
            'nombre'                   => ($isCreating ? 'required' : 'sometimes|required').'|string|max:255',
            'descripcion'              => 'nullable|string|max:255',
            'compartir'                => 'nullable|string|in:U,E,C,O,P',
            'carpeta_padre_delegacion' => 'nullable|string|max:10',
            'carpeta_padre_codigo'     => ($isCreating ? 'required' : 'prohibited').'|integer|min:1',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        if (! empty($data['delegacion'])
            && ! DB::connection('dynamic')->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
            throw new BusinessRuleException('La delegación no existe');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            $folder = VeolabDocuments::folder((string) $keys['delegacion'], (int) $keys['codigo']);
            $this->checkEditable($folder);
            unset($data['delegacion']);

            return $data;
        }

        $data['delegacion'] = (string) ($data['delegacion'] ?? '');
        $data['carpeta_padre_delegacion'] = (string) ($data['carpeta_padre_delegacion'] ?? '');

        $parent = VeolabDocuments::folder($data['carpeta_padre_delegacion'], (int) $data['carpeta_padre_codigo']);
        if (! $parent) {
            throw new BusinessRuleException('La carpeta padre no existe');
        }
        if ($parent->DIRCTAB === VeolabDocuments::TEMPLATES) {
            throw new BusinessRuleException('Las plantillas de exportación se gestionan desde Veolab');
        }
        if ($data['delegacion'] === '' && $data['carpeta_padre_delegacion'] !== '') {
            throw new BusinessRuleException('Una carpeta pública solo puede colgar de otra pública');
        }

        $data['tabla'] = (string) $parent->DIRCTAB;
        $data['compartir'] ??= 'E'; // como la crea un empleado en Veolab

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = (string) $keys['delegacion'];
        $code = (int) $keys['codigo'];
        $this->checkEditable(VeolabDocuments::folder($delegation, $code));

        $db = DB::connection('dynamic');
        $used = $db->table('DOCDIR')->where('DIR2DEL', $delegation)->where('DIR2COD', $code)->exists()
            || $db->table('DOCFAT')->where('DIR2DEL', $delegation)->where('DIR2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('La carpeta no está vacía');
        }
    }

    /** Los perfiles con los que se compartía. */
    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('DOCDYP')
            ->where('DIR3DEL', (string) $keys['delegacion'])->where('DIR3COD', (int) $keys['codigo'])->delete();
    }

    protected function appendRelatedData(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['es_raiz'] = $row['carpeta_padre_codigo'] === null ? 'T' : 'F';
        }

        return $rows;
    }

    private function checkEditable(?object $folder): void
    {
        if ($folder && (int) $folder->DIR2COD === 0) {
            throw new BusinessRuleException('Las carpetas raíz las gestiona Veolab');
        }
        if ($folder && $folder->DIRCTAB === VeolabDocuments::TEMPLATES) {
            throw new BusinessRuleException('Las plantillas de exportación se gestionan desde Veolab');
        }
    }
}
