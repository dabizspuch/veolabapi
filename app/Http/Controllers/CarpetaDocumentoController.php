<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabDocumentControl;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
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
 *  - compartir (DIRCCOM): U todos, E solo empleados, C empleados y clientes,
 *    O empleados y otros, P solo los perfiles de /documentos/carpetas/perfiles
 *    (también empleados). La API no aplica privilegios.
 *  - Control de documentos (Veolab 3, ISO 17025 8.3): controlada (DIRBCON,
 *    la heredan las subcarpetas), tipo_documentos (I interno, E externo) y
 *    periodicidad_revision (meses), que también se cambian en las raíces.
 *    Cambiar controlada exige el privilegio de aprobar documentos (especial 1
 *    de DOC_CTL) si se indica ?usuario_delegacion=&usuario_codigo=. Al
 *    marcarla, sus documentos que siguen a la carpeta pasan a controlados
 *    (control de versiones, copia dual a versión visible, borrador). El
 *    listado añade es_controlada (efectiva, con la herencia). Sin el módulo
 *    Calidad ninguna es controlada y esos campos no se escriben (422).
 *  - Fase 2 (con su script): dias_lectura (DIRNDLE, vacío = el de la
 *    carpeta superior o 15) y revision_antes_aprobar (DIRBREV, T; la
 *    heredan las subcarpetas; cambiarla exige el privilegio de aprobar). El
 *    listado añade es_revision_antes_aprobar y dias_lectura_efectivos. Los
 *    destinatarios de la carpeta están en /documentos/destinatarios.
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
        'controlada'               => 'DIRBCON',
        'tipo_documentos'          => 'DIRCTIC',
        'periodicidad_revision'    => 'DIRNPER',
        // Fase 2 del control (script de distribución de documentos)
        'dias_lectura'             => 'DIRNDLE', // días para leer una edición nueva (vacío = el de la superior o 15)
        'revision_antes_aprobar'   => 'DIRBREV', // T: las ediciones se revisan antes de aprobarlas
    ];

    protected array $since = [
        'controlada'            => VeolabVersion::V3,
        'tipo_documentos'       => VeolabVersion::V3,
        'periodicidad_revision' => VeolabVersion::V3,
        'dias_lectura'          => VeolabVersion::V3,
        'revision_antes_aprobar' => VeolabVersion::V3,
    ];

    /** Campos del control de documentos (los únicos que se cambian en las raíces). */
    private const CONTROL_PARAMS = ['controlada', 'tipo_documentos', 'periodicidad_revision', 'dias_lectura', 'revision_antes_aprobar'];

    /** Sin el script de la fase 2 (DOCDIR.DIRNDLE/DIRBREV), sus campos no están disponibles. */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if ($this->supports('dias_lectura') && ! VeolabDocumentControl::distributionAvailable()) {
            foreach (['dias_lectura', 'revision_antes_aprobar'] as $param) {
                $this->unsupported[$param] = VeolabVersion::V3;
                unset($this->mapping[$param]);
            }
        }

        return null;
    }

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
            'controlada'               => 'sometimes|nullable|string|in:T,F',
            'tipo_documentos'          => 'sometimes|nullable|string|in:I,E',
            'periodicidad_revision'    => 'sometimes|nullable|integer|min:0',
            'dias_lectura'             => 'sometimes|nullable|integer|min:0|max:3650',
            'revision_antes_aprobar'   => 'sometimes|nullable|string|in:T,F',
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
            // En las raíces solo se cambia el control de documentos.
            if (! $folder || (int) $folder->DIR2COD !== 0 || array_diff(array_keys($data), [...self::CONTROL_PARAMS, 'delegacion'])) {
                $this->checkEditable($folder);
            }
            unset($data['delegacion']);

            return $this->validateControl($data, $folder);
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
        // Veolab 3 (DOC_ModoCompartirNuevaCarpeta): hereda el acceso de la superior salvo bajo una raíz; si es por
        // perfiles, también sus perfiles. Si no, como la crea un empleado en Veolab: solo empleados.
        if (! isset($data['compartir']) && VeolabVersion::isV3() && (int) ($parent->DIR2COD ?? 0) !== 0
            && (string) ($parent->DIRCCOM ?? '') !== '') {
            $data['compartir'] = (string) $parent->DIRCCOM;
            if ($data['compartir'] === 'P') {
                $data['_perfiles_de'] = [(string) $parent->DEL3COD, (int) $parent->DIR1COD];
            }
        }
        $data['compartir'] ??= 'E';

        return $this->validateControl($data, null);
    }

    /** Cambiar si la carpeta es controlada exige el privilegio de aprobar documentos. */
    private function validateControl(array $data, ?object $folder): array
    {
        // Sin el módulo Calidad los campos del control se leen pero no se escriben.
        if (array_intersect(array_keys($data), self::CONTROL_PARAMS)) {
            VeolabDocumentControl::assertActive();
        }
        if (array_key_exists('dias_lectura', $data) && (int) ($data['dias_lectura'] ?? 0) <= 0) {
            $data['dias_lectura'] = null;   // vacío: el de la carpeta superior o 15
        }
        if (array_key_exists('revision_antes_aprobar', $data)) {
            $new = $data['revision_antes_aprobar'] === 'T' ? 'T' : 'F';
            $old = ($folder->DIRBREV ?? null) === 'T' ? 'T' : 'F';
            $data['revision_antes_aprobar'] = $new === 'T' ? 'T' : ($folder ? 'F' : null);
            if ($new !== $old) {
                VeolabDocumentControl::assertApprover(VeolabDocumentControl::queryUser(), 'cambiar si una carpeta exige revisión antes de aprobar');
            }
        }
        if (! array_key_exists('controlada', $data)) {
            return $data;
        }
        $new = $data['controlada'] === 'T' ? 'T' : 'F';
        $old = ($folder->DIRBCON ?? null) === 'T' ? 'T' : 'F';
        $data['controlada'] = $new === 'T' ? 'T' : ($folder ? 'F' : null);
        if ($new !== $old) {
            VeolabDocumentControl::assertApprover(VeolabDocumentControl::queryUser(), 'cambiar si una carpeta es de documentos controlados');
        }

        return $data;
    }

    /** Al marcarla como controlada, sus documentos pasan a controlados. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        // DOC_HeredarPerfilesCarpeta
        if (isset($data['_perfiles_de'])) {
            [$parentDel, $parentCod] = $data['_perfiles_de'];
            $profiles = DB::connection('dynamic')->table('DOCDYP')->where('DIR3DEL', $parentDel)->where('DIR3COD', $parentCod)
                ->get(['PER3DEL', 'PER3COD']);
            foreach ($profiles as $profile) {
                DB::connection('dynamic')->table('DOCDYP')->insert([
                    'DIR3DEL' => (string) $keys['delegacion'], 'DIR3COD' => (int) $keys['codigo'],
                    'PER3DEL' => $profile->PER3DEL, 'PER3COD' => $profile->PER3COD,
                ]);
            }
        }

        if (! array_intersect(array_keys($data), self::CONTROL_PARAMS)) {
            return $data;
        }
        VeolabDocumentControl::forget();
        if (($data['controlada'] ?? null) === 'T' && $keys && VeolabDocumentControl::active()) {
            VeolabDocumentControl::activateFolder((string) $keys['delegacion'], (int) $keys['codigo']);
        }

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

    /** Los perfiles con los que se compartía y sus destinatarios (control de documentos). */
    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('DOCDYP')
            ->where('DIR3DEL', (string) $keys['delegacion'])->where('DIR3COD', (int) $keys['codigo'])->delete();
        VeolabDocumentControl::deleteFolderData((string) $keys['delegacion'], (int) $keys['codigo']);
    }

    protected function appendRelatedData(array $rows): array
    {
        $control = $this->supports('controlada');
        foreach ($rows as &$row) {
            $row['es_raiz'] = $row['carpeta_padre_codigo'] === null ? 'T' : 'F';
            if ($control) {
                $folder = VeolabDocumentControl::folder($row['delegacion'] ?? '', $row['codigo'] ?? 0);
                $row['es_controlada'] = VeolabDocumentControl::active() && ($folder['controlada'] ?? false) ? 'T' : 'F';
            }
            if ($control && $this->supports('dias_lectura')) {
                // Con la herencia: revisión si ella o una superior la exige; días, los de la más cercana o 15.
                $row['es_revision_antes_aprobar'] = ($folder['revision'] ?? false) ? 'T' : 'F';
                $row['dias_lectura_efectivos'] = $folder['dias_lectura'] ?? VeolabDocumentControl::DEFAULT_READING_DAYS;
            }
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
