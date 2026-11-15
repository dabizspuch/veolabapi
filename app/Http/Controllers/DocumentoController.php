<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabDocumentControl;
use App\Support\VeolabFormat;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
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
 *  - Control de documentos (Veolab 3, ISO 17025 8.3; ver
 *    App\Support\VeolabDocumentControl y DocumentoControlController): datos
 *    de control en el propio documento, es_controlado y pendiente_aprobar
 *    calculados, y reglas al subir contenido y al descargar. Solo con el
 *    módulo Calidad: sin él no hay controlados y los datos de control se
 *    leen pero no se escriben (422).
 *  - Accesos directos (Veolab 3, «Analisis accesos directos a documentos.md»):
 *    acceso_directo_delegacion + acceso_directo_codigo (DOCFAT.FAT2DEL/FAT2COD)
 *    apuntan al documento de destino; es_acceso_directo calculado. Se crean
 *    con POST sin fichero, no tienen contenido propio (la descarga entrega el
 *    destino) y solo se mueven de carpeta o ficha. Nombre y extensión son una
 *    copia de los del destino, que se actualiza al renombrarlo; borrar
 *    definitivamente el destino borra sus accesos. GET /documentos/accesos
 *    lista los accesos de un documento.
 */
class DocumentoController extends BaseController
{
    protected string $table = 'DOCFAT';
    protected ?string $auditDescription = 'FATCNOM';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'FAT1COD',
    ];
    protected array $searchFields = ['FATCNOM', 'FATCTIP', 'FATCDES', 'FATCCOD'];

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
        'pedido_serie'               => 'PED2SER',
        'pedido_codigo'              => 'PED2COD',
        'entrega_codigo'             => 'ALB2COD',
        'incidencia_serie'           => 'INC2SER',
        'incidencia_codigo'          => 'INC2COD',
        'solicitud_serie'            => 'SOL2SER',
        'solicitud_codigo'           => 'SOL2COD',
        'ensayo_serie'               => 'EAP2SER', // ensayo de aptitud
        'ensayo_codigo'              => 'EAP2COD',
        'auditoria_calidad_serie'    => 'AUI2SER',
        'auditoria_calidad_codigo'   => 'AUI2COD',
        'revision_direccion_serie'   => 'RDI2SER',
        'revision_direccion_codigo'  => 'RDI2COD',
        // Control de documentos (ISO 17025 8.3), desde Veolab 3
        'controlado'                 => 'FATCCTL', // null = según la carpeta, T sí, F no
        'codigo_documento'           => 'FATCCOD',
        'tipo_documento'             => 'FATCTDC', // I interno, E externo
        'entidad_emisora'            => 'FATCEMI',
        'estado_control'             => 'FATCEST', // B borrador, V vigente, O obsoleto
        'version_aprobada'           => 'VER2APR', // edición aprobada vigente (0/null = ninguna)
        'elabora_delegacion'         => 'EME2DEL', // empleado que lo elabora
        'elabora_codigo'             => 'EME2COD',
        'periodicidad_revision'      => 'FATNPER', // meses (vacío = la de la carpeta)
        'ultima_revision'            => 'FATDULR',
        'proxima_revision'           => 'FATDREV',
        'fecha_vigencia'             => 'FATDVIG', // externos: comprobación de que sigue vigente
        'revisor_delegacion'         => 'EMR2DEL', // fase 2: revisor de las ediciones (revisión antes de aprobar)
        'revisor_codigo'             => 'EMR2COD',
        // Accesos directos, desde Veolab 3 (vacío = documento normal)
        'acceso_directo_delegacion'  => 'FAT2DEL',
        'acceso_directo_codigo'      => 'FAT2COD',
    ];

    /** Propiedades que un acceso directo toma de su destino (no se indican al crearlo ni se cambian). */
    private const SHORTCUT_TARGET_PARAMS = ['nombre', 'extension', 'descripcion', 'es_solo_lectura',
        'es_control_versiones', 'version_nombre', 'version_descripcion'];

    /** Datos de control que solo cambia quien puede aprobar documentos controlados. */
    private const APPROVER_CONTROL_PARAMS = ['codigo_documento', 'tipo_documento', 'entidad_emisora', 'periodicidad_revision',
        'elabora_delegacion', 'elabora_codigo', 'proxima_revision', 'revisor_delegacion', 'revisor_codigo'];

    /** Parámetros del control de documentos. */
    private const CONTROL_PARAMS = ['controlado', 'codigo_documento', 'tipo_documento', 'entidad_emisora',
        'estado_control', 'version_aprobada', 'elabora_delegacion', 'elabora_codigo', 'periodicidad_revision',
        'ultima_revision', 'proxima_revision', 'fecha_vigencia', 'revisor_delegacion', 'revisor_codigo'];

    /** Vínculos con entidades de Veolab 3 (sus columnas no existen en DOCFAT antes). */
    protected array $since = [
        'controlado'                => VeolabVersion::V3,
        'codigo_documento'          => VeolabVersion::V3,
        'tipo_documento'            => VeolabVersion::V3,
        'entidad_emisora'           => VeolabVersion::V3,
        'estado_control'            => VeolabVersion::V3,
        'version_aprobada'          => VeolabVersion::V3,
        'elabora_delegacion'        => VeolabVersion::V3,
        'elabora_codigo'            => VeolabVersion::V3,
        'periodicidad_revision'     => VeolabVersion::V3,
        'ultima_revision'           => VeolabVersion::V3,
        'proxima_revision'          => VeolabVersion::V3,
        'fecha_vigencia'            => VeolabVersion::V3,
        'revisor_delegacion'        => VeolabVersion::V3,
        'revisor_codigo'            => VeolabVersion::V3,
        'pedido_serie'              => VeolabVersion::V3,
        'pedido_codigo'             => VeolabVersion::V3,
        'entrega_codigo'            => VeolabVersion::V3,
        'incidencia_serie'          => VeolabVersion::V3,
        'incidencia_codigo'         => VeolabVersion::V3,
        'solicitud_serie'           => VeolabVersion::V3,
        'solicitud_codigo'          => VeolabVersion::V3,
        'ensayo_serie'              => VeolabVersion::V3,
        'ensayo_codigo'             => VeolabVersion::V3,
        'auditoria_calidad_serie'   => VeolabVersion::V3,
        'auditoria_calidad_codigo'  => VeolabVersion::V3,
        'revision_direccion_serie'  => VeolabVersion::V3,
        'revision_direccion_codigo' => VeolabVersion::V3,
        'acceso_directo_delegacion' => VeolabVersion::V3,
        'acceso_directo_codigo'     => VeolabVersion::V3,
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
        'pedido'              => 'int',
        'entrega'             => 'int',
        'incidencia'          => 'int',
        'solicitud'           => 'int',
        'ensayo'              => 'int',
        'auditoria_calidad'   => 'int',
        'revision_direccion'  => 'int',
        'elabora'             => 'int',
        'revisor'             => 'int',
        'acceso_directo'      => 'int',
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
        'pedido_serie' => 10, 'pedido_codigo' => 'int', 'entrega_codigo' => 'int',
        'incidencia_serie' => 10, 'incidencia_codigo' => 'int', 'solicitud_serie' => 10, 'solicitud_codigo' => 'int',
        'ensayo_serie' => 10, 'ensayo_codigo' => 'int', 'auditoria_calidad_serie' => 10, 'auditoria_calidad_codigo' => 'int',
        'revision_direccion_serie' => 10, 'revision_direccion_codigo' => 'int',
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
            // Control de documentos (los rechaza BaseController en bases anteriores a Veolab 3)
            'controlado'            => 'sometimes|nullable|string|in:T,F',
            'codigo_documento'      => 'sometimes|nullable|string|max:50',
            'tipo_documento'        => 'sometimes|nullable|string|in:I,E',
            'entidad_emisora'       => 'sometimes|nullable|string|max:100',
            'estado_control'        => 'sometimes|nullable|string|in:B,V,O',
            'elabora_delegacion'    => 'sometimes|nullable|string|max:10',
            'elabora_codigo'        => 'sometimes|nullable|integer|min:0',
            'periodicidad_revision' => 'sometimes|nullable|integer|min:0',
            'ultima_revision'       => 'sometimes|nullable|date',
            'proxima_revision'      => 'sometimes|nullable|date',
            'fecha_vigencia'        => 'sometimes|nullable|date',
            'revisor_delegacion'    => 'sometimes|nullable|string|max:10',
            'revisor_codigo'        => 'sometimes|nullable|integer|min:0',
            // El destino de un acceso directo no se cambia (se crea otro acceso).
            'acceso_directo_delegacion' => 'sometimes|prohibited',
            'acceso_directo_codigo'     => 'sometimes|prohibited',
        ] + $this->entityRules();
    }

    /**
     * Además de lo de BaseController: en una base de Veolab 3 sin el script
     * de los accesos directos (DOCFAT.FAT2DEL/FAT2COD), sus campos se tratan
     * como no disponibles.
     */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if ($this->supports('acceso_directo_codigo') && ! VeolabDocuments::shortcutsAvailable()) {
            foreach (['acceso_directo_delegacion', 'acceso_directo_codigo'] as $param) {
                $this->unsupported[$param] = VeolabVersion::V3;
                unset($this->mapping[$param]);
            }
            unset($this->foreignKeys['acceso_directo']);
        }
        // El revisor (DOCFAT.EMR2*) es de la fase 2 del control: sin su script, no disponible.
        if ($this->supports('revisor_codigo') && ! VeolabDocumentControl::distributionAvailable()) {
            foreach (['revisor_delegacion', 'revisor_codigo'] as $param) {
                $this->unsupported[$param] = VeolabVersion::V3;
                unset($this->mapping[$param]);
            }
            unset($this->foreignKeys['revisor']);
        }

        return null;
    }

    private function entityRules(): array
    {
        $rules = [];
        foreach (self::ENTITY_RULES as $param => $type) {
            if (! $this->supports($param)) {
                continue;
            }
            $rules[$param] = 'sometimes|nullable|'.($type === 'int' ? 'integer|min:0' : "string|max:{$type}");
        }

        return $rules;
    }

    /**
     * PUT: datos de control, vínculo con otra entidad y carpeta. Con
     * ?usuario_delegacion=&usuario_codigo= se comprueban los privilegios del
     * control (sin usuario, la API actúa como privilegiada).
     */
    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $row = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('FAT1COD', $keys['codigo'])->first();

        // Un acceso directo solo se mueve de carpeta o de ficha: lo demás es del destino.
        unset($data['acceso_directo_delegacion'], $data['acceso_directo_codigo']);
        $shortcut = VeolabDocuments::isShortcut($row);
        if ($shortcut) {
            $extra = array_diff(array_keys($data), ['carpeta_delegacion', 'carpeta_codigo', ...VeolabDocuments::entityParams()]);
            if ($extra) {
                throw new BusinessRuleException('En un acceso directo solo se cambian la carpeta y la ficha; '
                    .implode(', ', $extra).' son del documento de destino');
            }
        }

        $data = $this->validateControl($data, $row);
        $data = $this->resolveLink($data, $row);
        if ($shortcut && $data) {
            $this->checkMovedShortcut($row, $data);
        }

        // Un documento controlado lleva siempre el control de versiones.
        if (($data['es_control_versiones'] ?? null) === 'F' && VeolabDocumentControl::available()) {
            $after = (array) $row;
            foreach (['controlado' => 'FATCCTL', 'carpeta_delegacion' => 'DIR2DEL', 'carpeta_codigo' => 'DIR2COD'] as $param => $column) {
                if (array_key_exists($param, $data)) {
                    $after[$column] = $data[$param];
                }
            }
            if (VeolabDocumentControl::isControlled($after)) {
                throw new BusinessRuleException('Un documento controlado lleva siempre el control de versiones');
            }
        }

        return $data;
    }

    /**
     * Datos de control (desde Veolab 3):
     *  - controlado vacío = según la carpeta; cambiarlo exige el privilegio de aprobar;
     *  - estado_control: O (obsoleto) y volver de obsoleto exigen el privilegio
     *    de aprobar; de obsoleto a V sin edición aprobada queda en B; a V desde
     *    borrador solo con «aprobar»; nunca vuelve a B;
     *  - elabora_* debe ser un empleado;
     *  - revisor_* (fase 2) debe ser un empleado distinto de quien lo elabora.
     */
    private function validateControl(array $data, object $row): array
    {
        if (! array_intersect(array_keys($data), self::CONTROL_PARAMS)) {
            return $data;
        }
        VeolabDocumentControl::assertActive();   // sin Calidad se leen, no se escriben
        $user = VeolabDocumentControl::queryUser();

        if (array_key_exists('controlado', $data)) {
            $data['controlado'] = in_array($data['controlado'], ['T', 'F'], true) ? $data['controlado'] : null;
            if ((string) ($row->FATCCTL ?? '') !== (string) ($data['controlado'] ?? '')) {
                VeolabDocumentControl::assertApprover($user, 'cambiar si un documento está controlado');
            }
        }

        if (array_key_exists('estado_control', $data)) {
            $new = (string) ($data['estado_control'] ?? '');
            $current = (string) ($row->FATCEST ?? '');
            if ($new === '') {
                if ($current !== '') {
                    throw new BusinessRuleException('El estado de un documento controlado no se puede vaciar');
                }
                unset($data['estado_control']);
            } elseif ($new !== $current) {
                if ($new === 'B') {
                    throw new BusinessRuleException('Un documento no vuelve a borrador; para deshacer una edición sin aprobar use POST /documentos/descartar');
                }
                if ($new === 'V' && $current !== 'O') {
                    throw new BusinessRuleException('Un documento pasa a vigente al aprobar una edición (POST /documentos/aprobar)');
                }
                VeolabDocumentControl::assertApprover($user, $new === 'O' ? 'marcar documentos como obsoletos' : 'volver a poner en vigor un documento obsoleto');
                if ($new === 'V' && (int) ($row->VER2APR ?? 0) <= 0) {
                    $data['estado_control'] = 'B';   // sin edición aprobada: borrador
                }
            }
        }

        // PropiedadesDocumento (booEditar = DCT_PuedeAprobar): el resto de datos de control también los cambia solo
        // quien puede aprobar; y en un documento que no está controlado no se aplican (AplicarControlDocumento).
        $same = function ($a, $b): bool {
            $norm = function ($v): string {
                $v = trim((string) ($v ?? ''));
                if ($v === '0') {
                    return '';
                }

                return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : $v;
            };

            return $norm($a) === $norm($b);
        };
        $changed = array_values(array_filter(self::APPROVER_CONTROL_PARAMS, fn ($p) => array_key_exists($p, $data)
            && ! $same($data[$p], $row->{$this->mapping[$p]} ?? null)));
        if ($changed) {
            $controlled = VeolabDocumentControl::isControlled(['FATCCTL' => array_key_exists('controlado', $data) ? $data['controlado'] : ($row->FATCCTL ?? null)]
                + (array) $row);
            if (! $controlled) {
                throw new BusinessRuleException('El documento no está controlado: no se pueden cambiar '.implode(', ', $changed));
            }
            VeolabDocumentControl::assertApprover($user, 'cambiar los datos de control de un documento');
        }

        // Nueva periodicidad sin próxima revisión a mano: se recalcula desde la última revisión.
        if (array_key_exists('periodicidad_revision', $data) && ! array_key_exists('proxima_revision', $data)
            && (string) ($data['periodicidad_revision'] ?? '') !== (string) ($row->FATNPER ?? '') && ! empty($row->FATDULR)) {
            $months = VeolabDocumentControl::periodicity(['FATNPER' => $data['periodicidad_revision']] + (array) $row);
            $data['proxima_revision'] = $months === null ? null
                : VeolabFormat::addMonths(substr((string) $row->FATDULR, 0, 10), $months).' 00:00:00';
        }

        if ((int) ($data['elabora_codigo'] ?? 0) > 0) {
            VeolabDocumentControl::assertEmployee((string) ($data['elabora_delegacion'] ?? ''), (int) $data['elabora_codigo'],
                'El empleado que lo elabora');
        }

        // Revisor (fase 2): un empleado distinto de quien lo elabora.
        if ((int) ($data['revisor_codigo'] ?? 0) > 0) {
            $reviewer = [(string) ($data['revisor_delegacion'] ?? ''), (int) $data['revisor_codigo']];
            VeolabDocumentControl::assertEmployee($reviewer[0], $reviewer[1], 'El revisor');
            $elaborator = array_key_exists('elabora_codigo', $data)
                ? [(string) ($data['elabora_delegacion'] ?? ''), (int) ($data['elabora_codigo'] ?? 0)]
                : [(string) ($row->EME2DEL ?? ''), (int) ($row->EME2COD ?? 0)];
            if ($elaborator[1] !== 0 && $elaborator === $reviewer) {
                throw new BusinessRuleException('El revisor no puede ser quien elabora el documento');
            }
        }

        return $data;
    }

    /**
     * Vincular a otra entidad (los parámetros de entidad sustituyen al vínculo
     * entero) o mover de carpeta. Si cambia la entidad y no se indica carpeta,
     * va a la raíz de la nueva tabla.
     */
    private function resolveLink(array $data, object $row): array
    {
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

    /** Al mover un acceso directo: no puede quedar junto a su destino ni duplicado. */
    private function checkMovedShortcut(object $row, array $data): void
    {
        $after = $this->rowParams($row);
        foreach ($data as $param => $value) {
            $after[$param] = $value;
        }
        $folderCode = (int) ($after['carpeta_codigo'] ?? 0);
        $target = VeolabDocuments::shortcutTarget($row);
        if ($folderCode === 0 || ! $target) {
            return;   // en la papelera, o con el destino ya borrado
        }
        $this->checkShortcutLocation($target, (string) $row->DEL3COD, (int) $row->FAT1COD,
            (string) ($after['carpeta_delegacion'] ?? ''), $folderCode, VeolabDocuments::entityTable($after), $after);
    }

    /**
     * Sitio de un acceso directo (carpeta y ficha de la delegación $delegation):
     * ni donde ya está el destino ni donde ya hay otro acceso al mismo destino.
     * $self = código del propio acceso (al moverlo), para no contarlo.
     */
    private function checkShortcutLocation(object $target, string $delegation, ?int $self, string $folderDelegation,
        int $folderCode, ?string $table, array $params): void
    {
        // Columnas de la ficha (las de su tabla; sin ficha, solo cuenta la carpeta).
        $columns = [];
        foreach ($table ? VeolabDocuments::ENTITIES[$table][2] : [] as $param) {
            $columns[$this->mapping[$param]] = (string) ($params[$param] ?? '');
        }

        $sameAsTarget = (string) $target->DIR2DEL === $folderDelegation && (int) $target->DIR2COD === $folderCode
            && (! $columns || (string) $target->DEL3COD === $delegation);
        foreach ($columns as $column => $value) {
            $sameAsTarget = $sameAsTarget && (string) ($target->{$column} ?? '') === $value;
        }
        if ($sameAsTarget) {
            throw new BusinessRuleException('El documento de destino ya está en esa carpeta');
        }

        $query = DB::connection('dynamic')->table('DOCFAT')
            ->where('DIR2DEL', $folderDelegation)->where('DIR2COD', $folderCode);
        VeolabDocuments::whereShortcutsOf($query, (string) $target->DEL3COD, (int) $target->FAT1COD);
        if ($columns) {
            $query->where('DEL3COD', $delegation);
            foreach ($columns as $column => $value) {
                $query->where($column, $value);
            }
        }
        if ($self !== null) {
            $query->whereNot(fn ($q) => $q->where('DEL3COD', $delegation)->where('FAT1COD', $self));
        }
        if ($query->exists()) {
            throw new BusinessRuleException('Ya hay un acceso directo a ese documento en esa carpeta');
        }
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

    /**
     * Tras el PUT: el nombre nuevo pasa a sus accesos directos; si el
     * documento ha quedado controlado (por él o por su carpeta), control de
     * versiones, copia dual a versión visible, borrador y tipo de la carpeta;
     * si solo se activa el control de versiones, la copia dual pasa a versión
     * visible.
     */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $delegation = (string) $keys['delegacion'];
        $code = (int) $keys['codigo'];

        // Veolab 3: el aviso W de la próxima revisión se rehace al grabar los datos de control.
        if (array_intersect(array_keys($data), self::CONTROL_PARAMS)) {
            VeolabDocumentControl::notifyReview($delegation, $code);
        }

        // Renombrar el documento renombra sus accesos directos.
        if (array_intersect(array_keys($data), ['nombre', 'extension'])) {
            VeolabDocuments::renameShortcuts($delegation, $code);
        }

        if (! VeolabDocumentControl::available()) {
            return $data;
        }
        $document = VeolabDocumentControl::document($delegation, $code);
        if (! $document) {
            return $data;
        }

        if (VeolabDocumentControl::isControlled($document)) {
            VeolabDocumentControl::activate($delegation, $code);
        } elseif ($document->FATBVER === 'T' && ($update = VeolabDocumentControl::dualToVersion($document))) {
            DB::connection('dynamic')->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->update($update);
        }

        return $data;
    }

    /**
     * Filtros del control (Veolab 3): ?es_controlado=T|F (efectivo, con la
     * herencia de carpetas; nunca los accesos directos), ?pendiente_aprobar=T|F
     * y ?es_acceso_directo=T|F.
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $shortcut = $request->query('es_acceso_directo');
        if (in_array($shortcut, ['T', 'F'], true) && $this->supports('acceso_directo_codigo')) {
            $shortcut === 'T' ? $query->whereRaw('COALESCE(FAT2COD, 0) > 0') : VeolabDocuments::whereNotShortcut($query);
        }

        if (! $this->supports('controlado')) {
            return;
        }
        $controlled = $request->query('es_controlado');
        if (in_array($controlled, ['T', 'F'], true)) {
            VeolabDocumentControl::whereControlled($query, $controlled === 'T');
        }
        $pending = $request->query('pendiente_aprobar');
        if (in_array($pending, ['T', 'F'], true)) {
            $condition = function ($q) {
                VeolabDocumentControl::whereControlled($q);
                $q->whereRaw('COALESCE(VER2APR, 0) > 0')->whereRaw('VER2APR <> COALESCE(VER2COD, 0)');
            };
            $pending === 'T' ? $query->where($condition) : $query->whereNot($condition);
        }
    }

    /**
     * Tabla de la entidad vinculada, si está en la papelera y, desde Veolab 3,
     * es_controlado y pendiente_aprobar (siempre F en un acceso directo) y
     * es_acceso_directo; en los accesos, destino con el estado del documento
     * de destino (si existe, si está en la papelera, su control y su estado).
     */
    protected function appendRelatedData(array $rows): array
    {
        $control = $this->supports('controlado');
        $shortcuts = $this->supports('acceso_directo_codigo');
        $targets = [];
        foreach ($rows as &$row) {
            try {
                $row['tabla'] = VeolabDocuments::entityTable($row);
            } catch (BusinessRuleException) {
                $row['tabla'] = null; // vinculado a varias entidades (dato de Veolab incoherente)
            }
            $row['en_papelera'] = $row['carpeta_codigo'] === null ? 'T' : 'F';

            if ($control) {
                $document = [
                    'FATCCTL' => $row['controlado'] ?? null,
                    'FATCEST' => $row['estado_control'] ?? null,
                    'VER2APR' => $row['version_aprobada'] ?? 0,
                    'VER2COD' => $row['version_actual'] ?? 0,
                    'DIR2DEL' => $row['carpeta_delegacion'] ?? '',
                    'DIR2COD' => $row['carpeta_codigo'] ?? 0,
                    'FAT2COD' => $row['acceso_directo_codigo'] ?? 0,
                ];
                $row['es_controlado'] = VeolabDocumentControl::isControlled($document) ? 'T' : 'F';
                $row['pendiente_aprobar'] = VeolabDocumentControl::isPendingApproval($document) ? 'T' : 'F';
            }

            if ($shortcuts) {
                $isShortcut = (int) ($row['acceso_directo_codigo'] ?? 0) > 0;
                $row['es_acceso_directo'] = $isShortcut ? 'T' : 'F';
                $row['destino'] = null;
                if ($isShortcut) {
                    $targetDelegation = (string) ($row['acceso_directo_delegacion'] ?? '');
                    $targetCode = (int) $row['acceso_directo_codigo'];
                    $key = $targetDelegation."|".$targetCode;
                    $targets[$key] ??= DB::connection('dynamic')->table('DOCFAT')
                        ->where('DEL3COD', $targetDelegation)->where('FAT1COD', $targetCode)->first() ?? false;
                    $row['destino'] = $this->targetSummary($targetDelegation, $targetCode, $targets[$key] ?: null);
                }
            }
        }

        return $rows;
    }

    /** Resumen del destino de un acceso directo (null si no existe). */
    private function targetSummary(string $delegation, int $code, ?object $target): array
    {
        $out = [
            'delegacion'         => $delegation,
            'codigo'             => $code,
            'existe'             => $target ? 'T' : 'F',
            'en_papelera'        => $target && (int) $target->DIR2COD === 0 ? 'T' : 'F',
            'carpeta_delegacion' => $target && (int) $target->DIR2COD !== 0 ? (string) $target->DIR2DEL : null,
            'carpeta_codigo'     => $target && (int) $target->DIR2COD !== 0 ? (int) $target->DIR2COD : null,
        ];
        if (VeolabDocumentControl::available()) {
            $controlled = $target && VeolabDocumentControl::isControlled($target);
            $out['es_controlado'] = $controlled ? 'T' : 'F';
            $out['estado'] = $controlled ? VeolabDocumentControl::state($target) : null;   // B, V, P u O
            $out['pendiente_aprobar'] = $controlled && VeolabDocumentControl::isPendingApproval($target) ? 'T' : 'F';
        }

        return $out;
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
     * Con acceso_directo_codigo y sin fichero crea un acceso directo (ver storeShortcut).
     */
    public function store(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        if ($request->filled('acceso_directo_codigo')) {
            return $this->storeShortcut($request);
        }
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
            // En una carpeta controlada nace controlado: con versiones, en borrador y con el tipo de la carpeta.
            if (VeolabDocumentControl::active()) {   // sin Calidad, una carpeta controlada no controla
                $folderControl = VeolabDocumentControl::folder($folder->DEL3COD, $folder->DIR1COD);
                if ($folderControl['controlada'] ?? false) {
                    $document['FATBVER'] = 'T';
                    $document['FATCEST'] = 'B';
                    if ($folderControl['tipo'] !== null) {
                        $document['FATCTDC'] = $folderControl['tipo'];
                    }
                }
            }
            if (VeolabDocumentControl::available() && $document['FATBVER'] === 'T'
                && trim((string) ($data['version_nombre'] ?? '')) === '') {
                $data['version_nombre'] = 'Versión 1';
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

    /**
     * Alta de un acceso directo (Veolab 3): POST /documentos sin fichero, en
     * JSON o en formulario, con acceso_directo_delegacion + acceso_directo_codigo
     * (el documento de destino), delegacion, carpeta_delegacion + carpeta_codigo
     * y la entidad como cualquier documento.
     *  - Nombre y extensión, copia de los del destino; tamaño 0, sin versiones
     *    (VER2COD 0, FATBVER F), sin contenido ni datos de control, aunque la
     *    carpeta sea controlada: el control es el del destino.
     *  - Un acceso a otro acceso apunta al documento real. El destino debe
     *    existir y no estar en la papelera ni ser una plantilla de exportación;
     *    puede ser de otra delegación.
     *  - No puede estar en la carpeta y ficha del destino ni repetir el
     *    destino en la misma carpeta y ficha.
     */
    private function storeShortcut(Request $request)
    {
        if (! VeolabDocuments::shortcutsAvailable()) {
            return response()->json(['message' => VeolabDocuments::shortcutsUnavailableMessage()], 422);
        }
        $db = DB::connection('dynamic');

        try {
            $data = Validator::make($request->all(), [
                'fichero'                   => 'prohibited',
                'delegacion'                => 'nullable|string|max:10',
                'carpeta_delegacion'        => 'nullable|string|max:10',
                'carpeta_codigo'            => 'nullable|integer|min:1',
                'acceso_directo_delegacion' => 'nullable|string|max:10',
                'acceso_directo_codigo'     => 'required|integer|min:1',
            ] + $this->entityRules())->validate();

            foreach (self::SHORTCUT_TARGET_PARAMS as $param) {
                if ($request->filled($param)) {
                    throw new BusinessRuleException("Un acceso directo toma {$param} del documento de destino: no se indica");
                }
            }

            $delegation = (string) ($data['delegacion'] ?? '');
            if ($delegation !== '' && ! $db->table('ACCDEL')->where('DEL1COD', $delegation)->exists()) {
                throw new BusinessRuleException('La delegación no existe');
            }

            $pointed = $db->table('DOCFAT')->where('DEL3COD', (string) ($data['acceso_directo_delegacion'] ?? ''))
                ->where('FAT1COD', (int) $data['acceso_directo_codigo'])->first();
            $target = $pointed ? VeolabDocuments::shortcutTarget($pointed) : null;   // siempre al documento real
            if (! $target) {
                throw new BusinessRuleException('El documento de destino no existe');
            }
            if ((int) $target->DIR2COD === 0) {
                throw new BusinessRuleException('El documento de destino está en la papelera');
            }
            $targetFolder = VeolabDocuments::folder((string) $target->DIR2DEL, (int) $target->DIR2COD);
            if ($targetFolder && $targetFolder->DIRCTAB === VeolabDocuments::TEMPLATES) {
                throw new BusinessRuleException('Las plantillas de exportación no admiten accesos directos');
            }

            $table = VeolabDocuments::entityTable($data);
            if ($table) {
                VeolabDocuments::checkEntity($table, $data, $delegation);
            }
            $folder = VeolabDocuments::resolveFolder($table, $data['carpeta_delegacion'] ?? '',
                isset($data['carpeta_codigo']) ? (int) $data['carpeta_codigo'] : null);
            $this->checkShortcutLocation($target, $delegation, null, (string) $folder->DEL3COD, (int) $folder->DIR1COD, $table, $data);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $code = VeolabDocuments::reserveDocumentCode($delegation);

            $db->beginTransaction();

            $document = [
                'DEL3COD' => $delegation,
                'FAT1COD' => $code,
                'FATCNOM' => $target->FATCNOM,
                'FATCTIP' => $target->FATCTIP,
                'FATCDES' => null,
                'FATNTAM' => 0,
                'FATBZIP' => 'F',
                'FATBLEC' => 'F',
                'FATTCRE' => DB::raw('NOW()'),
                'FATTMOD' => DB::raw('NOW()'),
                'FATBVER' => 'F',
                'VER2COD' => 0,
                'DIR2DEL' => (string) $folder->DEL3COD,
                'DIR2COD' => (int) $folder->DIR1COD,
                'FAT2DEL' => (string) $target->DEL3COD,
                'FAT2COD' => (int) $target->FAT1COD,
            ];
            foreach ($table ? VeolabDocuments::ENTITIES[$table][2] : [] as $param) {
                $document[$this->mapping[$param]] = $data[$param] ?? '';
            }
            $db->table('DOCFAT')->insert($document);

            VeolabAudit::record(VeolabAudit::INSERCION, 'DOCFAT',
                $this->auditRow(['delegacion' => $delegation, 'codigo' => $code], (string) $target->FATCNOM));

            $db->commit();

            return response()->json([
                'message' => 'Acceso directo creado correctamente',
                'data'    => [
                    'delegacion'                => $delegation,
                    'codigo'                    => $code,
                    'acceso_directo_delegacion' => (string) $target->DEL3COD,
                    'acceso_directo_codigo'     => (int) $target->FAT1COD,
                ],
            ], 201);
        } catch (BusinessRuleException $e) {
            $this->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->rollBack();

            return ServerError::response('v2 store acceso DOCFAT', $e, 'Error al crear el acceso directo');
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
        $removedShortcuts = 0;

        try {
            $db->beginTransaction();

            if ($final) {
                VeolabDocuments::deleteBlocks($delegation, $code);
                VeolabDocumentControl::deleteDocumentData($delegation, $code);   // lecturas, destinatarios y copias
                $db->table('DOCVER')->where('DEL3COD', $delegation)->where('FAT3COD', $code)->delete();
                $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->delete();

                // Sus accesos directos se borran con él (al enviarlo a la papelera no se tocan).
                if (VeolabDocuments::shortcutsAvailable()) {
                    $query = $db->table('DOCFAT');
                    VeolabDocuments::whereShortcutsOf($query, $delegation, $code);
                    foreach ($query->get(['DEL3COD', 'FAT1COD', 'FATCNOM']) as $shortcut) {
                        $db->table('DOCFAT')->where('DEL3COD', (string) $shortcut->DEL3COD)
                            ->where('FAT1COD', (int) $shortcut->FAT1COD)->delete();
                        VeolabAudit::record(VeolabAudit::BORRADO, 'DOCFAT', $this->auditRow(
                            ['delegacion' => (string) $shortcut->DEL3COD, 'codigo' => (int) $shortcut->FAT1COD], (string) $shortcut->FATCNOM));
                        $removedShortcuts++;
                    }
                }
            } else {
                $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)
                    ->update(VeolabDocuments::trashUpdate($delegation));
            }

            VeolabAudit::record(VeolabAudit::BORRADO, 'DOCFAT',
                $this->auditRow(['delegacion' => $delegation, 'codigo' => $code], (string) $row->FATCNOM));

            $db->commit();

            $response = ['message' => $final
                ? 'Documento eliminado correctamente'
                : 'Documento enviado a la papelera'];
            if ($removedShortcuts > 0) {
                $response['data'] = ['accesos_eliminados' => $removedShortcuts];
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            $this->rollBack();
            return ServerError::response('v2 destroy DOCFAT', $e, 'Error al eliminar el documento');
        }
    }

    // ------------------------------------------------------------------
    // Contenido
    // ------------------------------------------------------------------

    /**
     * GET /documentos/contenido?delegacion=&codigo=[&version=][&inline=T]
     * [&usuario_delegacion=&usuario_codigo=]. La cabecera X-Documento-Version
     * dice qué versión se ha entregado. Un acceso directo entrega su destino,
     * con sus reglas (version es una versión del destino), y lo indica en
     * X-Documento-Destino-Delegacion y X-Documento-Destino-Codigo; si el
     * destino no existe, 404, y si está en la papelera, 422.
     */
    public function download(Request $request)
    {
        [$delegation, $code] = $this->documentKeys($request);
        $db = DB::connection('dynamic');

        $row = $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->first();
        if (! $row) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        $targetHeaders = [];
        if (VeolabDocuments::isShortcut($row)) {
            $row = VeolabDocuments::shortcutTarget($row);
            if (! $row) {
                return response()->json(['message' => 'El documento de destino del acceso directo no existe'], 404);
            }
            if ((int) $row->DIR2COD === 0) {
                return response()->json(['message' => 'El documento de destino del acceso directo está en la papelera'], 422);
            }
            $delegation = (string) $row->DEL3COD;
            $code = (int) $row->FAT1COD;
            $targetHeaders = ['X-Documento-Destino-Delegacion' => $delegation, 'X-Documento-Destino-Codigo' => (string) $code];
        }

        // Sin versión, la que abriría Veolab: en un controlado pendiente de
        // aprobar, quien solo consulta (?usuario_delegacion=&usuario_codigo=)
        // recibe la edición aprobada; sin usuario, la principal.
        if ($request->filled('version')) {
            $version = (int) $request->query('version');
            // DOC_AbrirDocumento: con el especial 2 de DOC_EXP solo se abre la versión principal
            if ($version !== (int) $row->VER2COD && VeolabVersion::isV3()) {
                try {
                    $user = VeolabDocumentControl::queryUser();
                } catch (BusinessRuleException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                if ($user && $user['solo_principal']) {
                    return response()->json(['message' => 'El usuario solo puede abrir la versión principal del documento'], 403);
                }
            }
        } else {
            $version = (int) $row->VER2COD;
            if (VeolabDocumentControl::available()) {
                try {
                    $version = VeolabDocumentControl::versionToOpen($row, VeolabDocumentControl::queryUser());
                } catch (BusinessRuleException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }
        }

        // Como DOC_AbrirDocumento: la versión pedida dice si está comprimida
        // (VERBZIP); la actual, el documento (FATBZIP).
        if ($request->filled('version') || $version !== (int) $row->VER2COD) {
            $versionRow = $db->table('DOCVER')->where('DEL3COD', $delegation)
                ->where('FAT3COD', $code)->where('VER1COD', $version)->first();
            if (! $versionRow) {
                return response()->json(['message' => 'La versión no existe'], 404);
            }
            $compressed = $versionRow->VERBZIP === 'T';
        } else {
            $compressed = $row->FATBZIP === 'T';
        }

        try {
            $response = VeolabDocuments::download($row, $version, $compressed, $request->query('inline') === 'T');
            $response->headers->set('X-Documento-Version', (string) $version);
            foreach ($targetHeaders as $header => $value) {
                $response->headers->set($header, $value);
            }

            return $response;
        } catch (\Throwable $e) {
            return ServerError::response('v2 download DOCFAT', $e, 'Error al leer el documento');
        }
    }

    /**
     * POST /documentos/contenido?delegacion=&codigo= (multipart): contenido
     * nuevo, como al guardar un documento abierto en Veolab:
     *  - controlado (Veolab 3): siempre versión nueva, que pasa a principal;
     *    si ya tenía edición aprobada queda pendiente de aprobar. La versión
     *    dual no actúa y no se admite en los obsoletos;
     *  - con control de versiones, versión nueva (nueva_version=F sobrescribe);
     *    en Veolab 3, sin version_nombre se llama «Versión N»;
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
        if (VeolabDocuments::isShortcut($row)) {
            return response()->json(['message' => VeolabDocuments::shortcutMessage($row, 'subir contenido')], 422);
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

            $control = VeolabDocumentControl::available();
            $controlled = $control && VeolabDocumentControl::isControlled($row);
            if ($controlled) {
                if ((string) ($row->FATCEST ?? '') === 'O') {
                    throw new BusinessRuleException('El documento está obsoleto');
                }
                if (($data['nueva_version'] ?? 'T') === 'F') {
                    throw new BusinessRuleException('Un documento controlado crea siempre una versión nueva');
                }
                // Por si se marcó controlado (o su carpeta) sin pasar por la API.
                if (VeolabDocumentControl::activate($delegation, $code)) {
                    $row = $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->lockForUpdate()->first();
                }
            }
            $current = (int) $row->VER2COD;

            if ($row->FATBVER === 'T') {
                $newVersion = $controlled || ($data['nueva_version'] ?? 'T') === 'T';
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
                if ($control && $row->FATBVER === 'T' && trim((string) ($data['version_nombre'] ?? '')) === '') {
                    $data['version_nombre'] = "Versión {$version}";
                }
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

            $result = ['delegacion' => $delegation, 'codigo' => $code, 'version' => $version];
            if ($controlled) {
                $approved = (int) ($row->VER2APR ?? 0);
                $result['pendiente_aprobar'] = $approved > 0 && $approved !== $version ? 'T' : 'F';
            }

            return response()->json([
                'message' => $newVersion ? 'Nueva versión guardada correctamente' : 'Contenido actualizado correctamente',
                'data'    => $result,
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
    // Accesos directos de un documento
    // ------------------------------------------------------------------

    /**
     * GET /documentos/accesos?delegacion=&codigo=: los accesos directos que
     * apuntan al documento, con su carpeta (nombre incluido; null en la
     * papelera) y su ficha (tabla y parámetros de la entidad). Sin paginar.
     */
    public function shortcuts(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabDocuments::shortcutsAvailable()) {
            return response()->json(['message' => VeolabDocuments::shortcutsUnavailableMessage()], 422);
        }
        [$delegation, $code] = $this->documentKeys($request);
        $db = DB::connection('dynamic');

        if (! $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->exists()) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        try {
            $query = $db->table('DOCFAT AS F')
                ->leftJoin('DOCDIR AS D', function ($join) {
                    $join->on('D.DEL3COD', '=', 'F.DIR2DEL')->on('D.DIR1COD', '=', 'F.DIR2COD');
                });
            VeolabDocuments::whereShortcutsOf($query, $delegation, $code, 'F');
            $rows = $query->orderBy('F.DEL3COD')->orderBy('F.FAT1COD')->get(['F.*', 'D.DIRCNOM']);

            $data = [];
            foreach ($rows as $row) {
                $params = $this->rowParams($row);
                try {
                    $table = VeolabDocuments::entityTable($params);
                } catch (BusinessRuleException) {
                    $table = null;   // vinculado a varias entidades (dato de Veolab incoherente)
                }
                $entity = [];
                foreach ($table ? VeolabDocuments::ENTITIES[$table][2] : [] as $param) {
                    $entity[$param] = $params[$param];
                }
                $inTrash = (int) $row->DIR2COD === 0;
                $data[] = [
                    'delegacion'         => (string) $row->DEL3COD,
                    'codigo'             => (int) $row->FAT1COD,
                    'nombre'             => $row->FATCNOM,
                    'extension'          => $row->FATCTIP,
                    'carpeta_delegacion' => $inTrash ? null : (string) $row->DIR2DEL,
                    'carpeta_codigo'     => $inTrash ? null : (int) $row->DIR2COD,
                    'carpeta_nombre'     => $inTrash ? null : $row->DIRCNOM,
                    'en_papelera'        => $inTrash ? 'T' : 'F',
                    'tabla'              => $table,
                    'entidad'            => $entity ?: null,
                    'fecha_creacion'     => $row->FATTCRE,
                ];
            }

            return response()->json([
                'data' => $data,
                'meta' => ['total' => count($data), 'version_bd' => VeolabVersion::current()],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response('v2 accesos DOCFAT', $e, 'Error al leer los accesos directos');
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
