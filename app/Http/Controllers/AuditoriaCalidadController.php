<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabAuditChecklists;
use App\Support\VeolabCodes;
use App\Support\VeolabProficiencyPlan;
use App\Support\VeolabQuality;
use App\Support\VeolabQualityAudits;
use App\Support\VeolabVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Auditorías internas y externas, fase 1 (CALAUI, módulo CAL, desde Veolab
 * 3; ISO/IEC 17025:2017 8.8; funcionalidad CAL_AUI en Veolab). Sigue el
 * modelo común con el escritorio (App\Support\VeolabQualityAudits).
 *
 *  - Ruta /auditorias-calidad: /auditorias ya es el registro de auditoría
 *    de cambios (ACCAUD, AuditoriaController).
 *  - Clave delegación + serie + código; el código sale del contador ACCCLT
 *    de la serie con las reglas de ACCCFC 'CALAUI', como CALEAP.
 *  - Tipo I interna, C interna contratada, E externa de acreditación, X
 *    externa de certificación o de cliente (al crear sin tipo, I). En la
 *    interna se guarda el auditor jefe y se vacían entidad y auditor
 *    externo; en las demás, al revés (como el escritorio). Estado P
 *    prevista, R realizada (exige la fecha de inicio), I informe emitido
 *    (exige la fecha del informe), C cerrada (exige que no quede ningún
 *    hallazgo abierto) y A anulada.
 *  - Embebidos que sustituyen la lista al escribir: equipo (CALAUQ papel A),
 *    auditados (CALAUQ papel D), apartados (CALAUX), delegaciones (CALAUL) y
 *    ensayos_presenciados (CALAUV, renumerados 1, 2... en el orden dado).
 *    Cada ensayo presenciado puede llevar su línea actual en 'codigo' para
 *    conservar las preguntas de su lista de verificación (CALAUP.AUV2COD);
 *    sin ella se empareja con la anterior del mismo parámetro. Las preguntas
 *    de los ensayos quitados se borran.
 *  - Avisos no bloqueantes en 'avisos' de la respuesta: auditor entre los
 *    auditados (independencia), auditoría interna sin auditor y, al pasar a
 *    informe emitido, preguntas de la lista de verificación sin responder.
 *  - Al eliminarla se borran sus listas, las preguntas de su lista de
 *    verificación y los enlaces de las incidencias hacia ella (no las
 *    incidencias); sus documentos van a la papelera.
 *  - La lista de verificación se gestiona en /auditorias-calidad/lista
 *    (AuditoriaListaController).
 *  - GET /auditorias-calidad/cobertura: la cobertura del programa (coverage()).
 */
class AuditoriaCalidadController extends BaseController
{
    protected string $table = 'CALAUI';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'AUI1SER',
        'codigo'     => 'AUI1COD',
    ];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    // Sin $foreignKeys: las referencias vacías se guardan como NULL, como en el resto de Calidad.
    protected array $mapping = [
        'delegacion'                   => 'DEL3COD',
        'serie'                        => 'AUI1SER',
        'codigo'                       => 'AUI1COD',
        'tipo'                         => 'AUICTIP',   // I interna, C interna contratada, E externa de acreditación, X externa de certificación o de cliente
        'anio'                         => 'AUINANI',   // Año del programa
        'descripcion'                  => 'AUICDES',
        'fecha_prevista'               => 'AUIDPRE',
        'fecha_inicio'                 => 'AUIDINI',
        'fecha_fin'                    => 'AUIDFIN',
        'fecha_informe'                => 'AUIDINF',
        'fecha_comunicacion_direccion' => 'AUIDDIR',
        'estado'                       => 'AUICEST',   // P prevista, R realizada, I informe emitido, C cerrada, A anulada
        'auditor_jefe_delegacion'      => 'EMA2DEL',   // Empleado
        'auditor_jefe_codigo'          => 'EMA2COD',
        'entidad_delegacion'           => 'PRO2DEL',   // Entidad auditora externa (proveedor)
        'entidad_codigo'               => 'PRO2COD',
        'auditor_externo'              => 'AUICAUD',   // Nombre del auditor externo
        'criterios'                    => 'AUICCRI',
        'conclusion'                   => 'AUICCON',
        'observaciones'                => 'AUICOBS',
        'fecha_registro'               => 'AUITREG',
        'usuario_delegacion'           => 'USU2DEL',
        'usuario_codigo'               => 'USU2COD',
    ];

    protected array $searchFields = ['AUICDES', 'AUICAUD', 'AUICCRI'];

    private const DATES = ['fecha_prevista', 'fecha_inicio', 'fecha_fin', 'fecha_informe', 'fecha_comunicacion_direccion'];

    /** Textos con su longitud (recortados; vacíos, NULL). */
    private const TEXTS = ['descripcion' => 255, 'auditor_externo' => 100, 'criterios' => null, 'conclusion' => null, 'observaciones' => null];

    /** Listas de empleados de CALAUQ: parámetro => papel. */
    private const EMPLOYEE_LISTS = ['equipo' => VeolabQualityAudits::ROLE_TEAM, 'auditados' => VeolabQualityAudits::ROLE_AUDITEE];

    /** Listas embebidas: parámetro => tabla (para la auditoría de la ficha). */
    private const LIST_TABLES = ['equipo' => 'CALAUQ', 'auditados' => 'CALAUQ', 'apartados' => 'CALAUX',
        'delegaciones' => 'CALAUL', 'ensayos_presenciados' => 'CALAUV'];

    /** Avisos de la última escritura. */
    private array $warnings = [];

    protected function rules(): array
    {
        $rules = [
            'delegacion'                                    => 'nullable|string|max:10',
            'serie'                                         => 'nullable|string|max:10',
            'codigo'                                        => 'nullable|integer|min:1',
            'tipo'                                          => 'nullable|string|in:'.implode(',', array_keys(VeolabQualityAudits::TYPE_NAMES)),
            'anio'                                          => 'nullable|integer|min:1900|max:9999',
            'estado'                                        => 'nullable|string|in:'.implode(',', array_keys(VeolabQualityAudits::STATE_NAMES)),
            'auditor_jefe_delegacion'                       => 'nullable|string|max:10',
            'auditor_jefe_codigo'                           => 'nullable|integer|min:0',
            'entidad_delegacion'                            => 'nullable|string|max:10',
            'entidad_codigo'                                => 'nullable|string|max:15',
            'usuario_delegacion'                            => 'nullable|string|max:10',
            'usuario_codigo'                                => 'nullable|string|max:15',
            'fecha_registro'                                => 'nullable',   // solo lectura: se acepta si no cambia
            'equipo'                                        => 'nullable|array',
            'equipo.*.empleado_delegacion'                  => 'nullable|string|max:10',
            'equipo.*.empleado_codigo'                      => 'required|integer|min:1',
            'auditados'                                     => 'nullable|array',
            'auditados.*.empleado_delegacion'               => 'nullable|string|max:10',
            'auditados.*.empleado_codigo'                   => 'required|integer|min:1',
            'apartados'                                     => 'nullable|array',
            'apartados.*.apartado'                          => 'required|string|max:10',
            'delegaciones'                                  => 'nullable|array',
            'delegaciones.*.delegacion'                     => 'required|string|max:10',
            'ensayos_presenciados'                          => 'nullable|array',
            'ensayos_presenciados.*.codigo'                 => 'nullable|integer|min:1',   // línea actual (para su lista de verificación)
            'ensayos_presenciados.*.parametro_delegacion'   => 'nullable|string|max:10',
            'ensayos_presenciados.*.parametro_codigo'       => 'required|string|max:30',
            'ensayos_presenciados.*.analista_delegacion'    => 'nullable|string|max:10',
            'ensayos_presenciados.*.analista_codigo'        => 'nullable|integer|min:0',
            'ensayos_presenciados.*.operacion_delegacion'   => 'nullable|string|max:10',
            'ensayos_presenciados.*.operacion_serie'        => 'nullable|string|max:10',
            'ensayos_presenciados.*.operacion_codigo'       => 'nullable|integer|min:0',
            'ensayos_presenciados.*.observaciones'          => 'nullable|string|max:255',
        ];
        foreach (self::TEXTS as $param => $max) {
            $rules[$param] = 'nullable|string'.($max ? "|max:{$max}" : '');
        }
        foreach (self::DATES as $param) {
            $rules[$param] = 'nullable|date';
        }

        return $rules;
    }

    public function store(Request $request)
    {
        $this->warnings = [];

        return $this->withWarnings(parent::store($request));
    }

    public function update(Request $request)
    {
        $this->warnings = [];

        return $this->withWarnings(parent::update($request));
    }

    /** Añade los avisos a una respuesta correcta. */
    private function withWarnings($response)
    {
        if ($this->warnings && $response instanceof JsonResponse && $response->getStatusCode() < 300) {
            $payload = $response->getData(true);
            $payload['avisos'] = array_map(fn ($text) => ['aviso' => $text], $this->warnings);

            return response()->json($payload, $response->getStatusCode());
        }

        return $response;
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
        if (str_contains((string) ($data['serie'] ?? ''), '#')) {
            throw new BusinessRuleException('La serie no puede llevar el carácter #');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = ! $keys;
        $before = $isNew ? [] : (array) VeolabQualityAudits::audit((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'], true);
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        // Solo lectura: el registro (fecha y usuario, al crear)
        if (array_key_exists('fecha_registro', $data)) {
            if ((! $isNew && ! self::sameValue($data['fecha_registro'], $before['AUITREG'] ?? null)) || ($isNew && ! empty($data['fecha_registro']))) {
                throw new BusinessRuleException("El campo 'fecha_registro' lo pone Veolab al registrar la auditoría");
            }
            unset($data['fecha_registro']);
        }
        if (! $isNew) {
            foreach (['usuario_delegacion' => 'USU2DEL', 'usuario_codigo' => 'USU2COD'] as $param => $column) {
                if (array_key_exists($param, $data)) {
                    if (! self::sameValue($data[$param], $before[$column] ?? null)) {
                        throw new BusinessRuleException('El usuario que registró la auditoría no se cambia');
                    }
                    unset($data[$param]);
                }
            }
        } elseif (! empty($data['usuario_codigo'])) {
            if (! $db->table('ACCUSU')->where('DEL3COD', (string) ($data['usuario_delegacion'] ?? ''))
                ->where('USU1COD', $data['usuario_codigo'])->exists()) {
                throw new BusinessRuleException('El usuario que registra la auditoría no existe');
            }
            $data['usuario_delegacion'] = (string) ($data['usuario_delegacion'] ?? '');
        } else {
            $data['usuario_delegacion'] = null;
            $data['usuario_codigo'] = null;
        }

        // Textos recortados; vacíos, NULL
        foreach (self::TEXTS as $param => $max) {
            if (array_key_exists($param, $data)) {
                $text = trim((string) ($data[$param] ?? ''));
                $data[$param] = $text === '' ? null : $text;
            }
        }

        // Tipo (al crear sin él, interna)
        if ($isNew || array_key_exists('tipo', $data)) {
            $data['tipo'] = (string) ($data['tipo'] ?? '') === '' ? 'I' : $data['tipo'];
        }

        // Auditor según el tipo, como el escritorio: en la interna (o sin tipo) el auditor jefe;
        // en la contratada y las externas, la entidad y el nombre del auditor externo. Lo otro se vacía
        // (y queda en la auditoría de cambios como un cambio más).
        if (VeolabQualityAudits::isOwnInternal((string) $current('tipo'))) {
            $data['entidad_delegacion'] = null;
            $data['entidad_codigo'] = null;
            $data['auditor_externo'] = null;
        } else {
            $data['auditor_jefe_delegacion'] = null;
            $data['auditor_jefe_codigo'] = null;
        }

        // Fechas (solo el día)
        foreach (self::DATES as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = empty($data[$param]) ? null : (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        // Año del programa: si falta, el de la fecha prevista, el de inicio o el actual
        if ($isNew || array_key_exists('anio', $data)) {
            if (empty($data['anio'])) {
                $date = $current('fecha_prevista') ?: $current('fecha_inicio');
                $data['anio'] = $date ? (int) substr((string) $date, 0, 4) : VeolabProficiencyPlan::currentYear();
            } else {
                $data['anio'] = (int) $data['anio'];
            }
        }

        // Auditor jefe: un empleado; si cambia, de alta
        if (array_key_exists('auditor_jefe_codigo', $data) || array_key_exists('auditor_jefe_delegacion', $data)) {
            $empDel = (string) $current('auditor_jefe_delegacion');
            $empCod = (int) $current('auditor_jefe_codigo');
            if ($empCod === 0) {
                $data['auditor_jefe_delegacion'] = null;
                $data['auditor_jefe_codigo'] = null;
            } else {
                $employee = $db->table('GRHEMP')->where('DEL3COD', $empDel)->where('EMP1COD', $empCod)->first(['EMPBBAJ']);
                if (! $employee) {
                    throw new BusinessRuleException('El auditor jefe (empleado) no existe');
                }
                $changed = $isNew || (string) ($before['EMA2DEL'] ?? '') !== $empDel || (int) ($before['EMA2COD'] ?? 0) !== $empCod;
                if ($changed && $employee->EMPBBAJ === 'T') {
                    throw new BusinessRuleException('El auditor jefe (empleado) está de baja');
                }
                $data['auditor_jefe_delegacion'] = $empDel;
                $data['auditor_jefe_codigo'] = $empCod;
            }
        }

        // Entidad auditora externa: un proveedor; si cambia, de alta
        if (array_key_exists('entidad_codigo', $data) || array_key_exists('entidad_delegacion', $data)) {
            $proDel = (string) $current('entidad_delegacion');
            $proCod = trim((string) $current('entidad_codigo'));
            if ($proCod === '') {
                $data['entidad_delegacion'] = null;
                $data['entidad_codigo'] = null;
            } else {
                $provider = $db->table('SINPRO')->where('DEL3COD', $proDel)->where('PRO1COD', $proCod)->first(['PROBBAJ']);
                if (! $provider) {
                    throw new BusinessRuleException('La entidad auditora (proveedor) no existe');
                }
                $changed = $isNew || (string) ($before['PRO2DEL'] ?? '') !== $proDel || (string) ($before['PRO2COD'] ?? '') !== $proCod;
                if ($changed && $provider->PROBBAJ === 'T') {
                    throw new BusinessRuleException('La entidad auditora (proveedor) está de baja');
                }
                $data['entidad_delegacion'] = $proDel;
                $data['entidad_codigo'] = $proCod;
            }
        }

        // Estado y sus requisitos (cerrar se comprueba al pasar a cerrada)
        $storedState = $isNew ? '' : VeolabQualityAudits::state((object) $before);
        $state = (string) ($current('estado') ?? '');
        $state = $state === '' ? 'P' : $state;
        if ($isNew || array_key_exists('estado', $data)) {
            $data['estado'] = $state;
        }
        if ($state === 'R' && empty($current('fecha_inicio'))) {
            throw new BusinessRuleException('Para marcar la auditoría como realizada indique la fecha de inicio');
        }
        if ($state === 'I' && empty($current('fecha_informe'))) {
            throw new BusinessRuleException('Para marcar el informe como emitido indique la fecha del informe');
        }
        // Al pasar a informe emitido, aviso (sin impedirlo) si la lista de verificación tiene preguntas sin responder
        $data['_pasa_a_informe'] = ! $isNew && $state === 'I' && $storedState !== 'I';
        if ($state === 'C' && ! $isNew && $storedState !== 'C'
            && ($open = VeolabQualityAudits::openFindings((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'])) > 0) {
            throw new BusinessRuleException("La auditoría no se puede cerrar: tiene {$open} hallazgo(s) abierto(s). Cierre o anule antes "
                .'sus incidencias (ISO/IEC 17025 8.8.2 d)');
        }

        // Listas embebidas
        $data['_listas'] = $this->normalizeLists($data, $isNew ? null : $keys);
        foreach (array_keys(self::LIST_TABLES) as $param) {
            unset($data[$param]);
        }

        if ($isNew) {
            $data['fecha_registro'] = (string) $db->selectOne('SELECT NOW() AS n')->n;
        }
        $data['_nueva'] = $isNew;

        return $data;
    }

    /**
     * Comprueba las listas recibidas y las devuelve normalizadas (solo las
     * presentes): empleados existentes (los que se añaden, de alta) y sin
     * repetir; apartados de la lista fija; delegaciones existentes; ensayos
     * presenciados con un parámetro existente y, si se indican, analista y
     * operación existentes.
     */
    private function normalizeLists(array $data, ?array $keys): array
    {
        $db = DB::connection('dynamic');
        $own = fn ($table) => $db->table($table)->where('DEL3COD', (string) ($keys['delegacion'] ?? ''))
            ->where('AUI3SER', (string) ($keys['serie'] ?? ''))->where('AUI3COD', (int) ($keys['codigo'] ?? 0));
        $lists = [];

        foreach (self::EMPLOYEE_LISTS as $param => $role) {
            if (! array_key_exists($param, $data)) {
                continue;
            }
            $previous = $keys === null ? [] : $own('CALAUQ')->where('AUQCROL', $role)->get(['EMP3DEL', 'EMP3COD'])
                ->map(fn ($e) => [(string) $e->EMP3DEL, (int) $e->EMP3COD])->all();
            $list = [];
            foreach ($data[$param] ?? [] as $item) {
                $emp = [(string) ($item['empleado_delegacion'] ?? ''), (int) $item['empleado_codigo']];
                $employee = $db->table('GRHEMP')->where('DEL3COD', $emp[0])->where('EMP1COD', $emp[1])->first(['EMPBBAJ']);
                if (! $employee) {
                    throw new BusinessRuleException("El empleado {$emp[1]} de '{$param}' no existe");
                }
                if (! in_array($emp, $previous, true) && $employee->EMPBBAJ === 'T') {
                    throw new BusinessRuleException("El empleado {$emp[1]} de '{$param}' está de baja");
                }
                if (in_array($emp, $list, true)) {
                    throw new BusinessRuleException("El empleado {$emp[1]} está repetido en '{$param}'");
                }
                $list[] = $emp;
            }
            $lists[$param] = $list;
        }

        if (array_key_exists('apartados', $data)) {
            $list = [];
            foreach ($data['apartados'] ?? [] as $item) {
                $clause = trim((string) $item['apartado']);
                if (! VeolabQualityAudits::isClause($clause)) {
                    throw new BusinessRuleException("El apartado '{$clause}' no es de la norma; use uno de: "
                        .implode(', ', array_map('strval', array_keys(VeolabQualityAudits::CLAUSES))));
                }
                if (in_array($clause, $list, true)) {
                    throw new BusinessRuleException("El apartado {$clause} está repetido");
                }
                $list[] = $clause;
            }
            $lists['apartados'] = $list;
        }

        if (array_key_exists('delegaciones', $data)) {
            $list = [];
            foreach ($data['delegaciones'] ?? [] as $item) {
                $del = trim((string) $item['delegacion']);
                if (! $db->table('ACCDEL')->where('DEL1COD', $del)->exists()) {
                    throw new BusinessRuleException("La delegación {$del} no existe");
                }
                if (in_array($del, $list, true)) {
                    throw new BusinessRuleException("La delegación {$del} está repetida");
                }
                $list[] = $del;
            }
            $lists['delegaciones'] = $list;
        }

        if (array_key_exists('ensayos_presenciados', $data)) {
            $list = [];
            foreach ($data['ensayos_presenciados'] ?? [] as $item) {
                $tec = [(string) ($item['parametro_delegacion'] ?? ''), trim((string) $item['parametro_codigo'])];
                if (! $db->table('LABTEC')->where('DEL3COD', $tec[0])->where('TEC1COD', $tec[1])->exists()) {
                    throw new BusinessRuleException("El parámetro {$tec[1]} del ensayo presenciado no existe");
                }
                $emp = [(string) ($item['analista_delegacion'] ?? ''), (int) ($item['analista_codigo'] ?? 0)];
                if ($emp[1] !== 0 && ! $db->table('GRHEMP')->where('DEL3COD', $emp[0])->where('EMP1COD', $emp[1])->exists()) {
                    throw new BusinessRuleException("El analista {$emp[1]} del ensayo presenciado no existe");
                }
                $ope = [(string) ($item['operacion_delegacion'] ?? ''), (string) ($item['operacion_serie'] ?? ''), (int) ($item['operacion_codigo'] ?? 0)];
                if ($ope[2] !== 0 && ! $db->table('LABOPE')->where('DEL3COD', $ope[0])->where('OPE1SER', $ope[1])->where('OPE1COD', $ope[2])->exists()) {
                    throw new BusinessRuleException('La operación '.VeolabCodes::format('LABOPE', (string) $ope[2], $ope[0], $ope[1])
                        .' del ensayo presenciado no existe');
                }
                $observations = trim((string) ($item['observaciones'] ?? ''));
                $list[] = [
                    'TEC2DEL'  => $tec[0],
                    'TEC2COD'  => $tec[1],
                    'EMP2DEL'  => $emp[1] !== 0 ? $emp[0] : null,
                    'EMP2COD'  => $emp[1] !== 0 ? $emp[1] : null,
                    'OPE2DEL'  => $ope[2] !== 0 ? $ope[0] : null,
                    'OPE2SER'  => $ope[2] !== 0 ? $ope[1] : null,
                    'OPE2COD'  => $ope[2] !== 0 ? $ope[2] : null,
                    'AUVCOBS'  => $observations === '' ? null : $observations,
                    '_anterior' => isset($item['codigo']) ? (int) $item['codigo'] : null,   // no se graba: para la lista de verificación
                ];
            }
            $lists['ensayos_presenciados'] = $list;
        }

        return $lists;
    }

    /** Valor recibido igual al guardado (vacíos iguales; fechas por su valor). */
    private static function sameValue($given, $stored): bool
    {
        $given = $given === null ? '' : trim((string) $given);
        $stored = $stored === null ? '' : trim((string) $stored);
        if ($given === $stored) {
            return true;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $given) && preg_match('/^\d{4}-\d{2}-\d{2}/', $stored)) {
            return strtotime($given) === strtotime($stored);
        }

        return false;
    }

    /**
     * Tras grabar: reescribe las listas recibidas, audita su cambio como la
     * ficha (fila y, con nivel 3, campo con la tabla modificada) y calcula
     * los avisos.
     */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $base = ['DEL3COD' => $del, 'AUI3SER' => $ser, 'AUI3COD' => $cod];
        $own = fn ($table) => $db->table($table)->where('DEL3COD', $del)->where('AUI3SER', $ser)->where('AUI3COD', $cod);
        $lists = $data['_listas'] ?? [];

        foreach (self::EMPLOYEE_LISTS as $param => $role) {
            if (! array_key_exists($param, $lists)) {
                continue;
            }
            $own('CALAUQ')->where('AUQCROL', $role)->delete();
            foreach ($lists[$param] as [$empDel, $empCod]) {
                $db->table('CALAUQ')->insert($base + ['EMP3DEL' => $empDel, 'EMP3COD' => $empCod, 'AUQCROL' => $role]);
            }
        }
        if (array_key_exists('apartados', $lists)) {
            $own('CALAUX')->delete();
            foreach ($lists['apartados'] as $clause) {
                $db->table('CALAUX')->insert($base + ['AUXCAPA' => $clause]);
            }
        }
        if (array_key_exists('delegaciones', $lists)) {
            $own('CALAUL')->delete();
            foreach ($lists['delegaciones'] as $delegation) {
                $db->table('CALAUL')->insert($base + ['DEL2COD' => $delegation]);
            }
        }
        if (array_key_exists('ensayos_presenciados', $lists)) {
            $checklists = VeolabAuditChecklists::available();
            $map = $checklists ? VeolabAuditChecklists::matchWitnessed($own('CALAUV')->get(['AUV1COD', 'TEC2DEL', 'TEC2COD']),
                $lists['ensayos_presenciados']) : [];
            $own('CALAUV')->delete();
            foreach ($lists['ensayos_presenciados'] as $i => $line) {
                unset($line['_anterior']);
                $db->table('CALAUV')->insert($base + ['AUV1COD' => $i + 1] + $line);
            }
            if ($checklists) {
                VeolabAuditChecklists::remapWitnessed($del, $ser, $cod, $map);
            }
        }

        // Auditoría de las listas (en el alta basta la inserción)
        if ($lists && empty($data['_nueva'])) {
            $row = $this->auditRow($keys);
            if (! array_intersect_key($data, array_diff_key($this->mapping, $this->keys))) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);   // solo cambian las listas
            }
            if (VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
                $tables = array_unique(array_map(fn ($param) => self::LIST_TABLES[$param], array_keys($lists)));
                foreach ($tables as $table) {
                    VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, $table);
                }
            }
        }

        $this->warnings = $this->auditWarnings($del, $ser, $cod);
        if (! empty($data['_pasa_a_informe']) && ($pending = VeolabAuditChecklists::unanswered($del, $ser, $cod)) > 0) {
            $this->warnings[] = "Quedan {$pending} preguntas de la lista de verificación sin responder.";
        }

        return $data;
    }

    /**
     * Avisos al grabar, sin bloquear: el auditor jefe o uno del equipo está
     * entre los auditados (independencia, en cualquier tipo) y la auditoría
     * interna sin auditor: la propia (I o sin tipo) sin jefe ni equipo; la
     * contratada (C) sin entidad ni nombre del auditor externo.
     */
    private function auditWarnings(string $del, string $ser, int $cod): array
    {
        $db = DB::connection('dynamic');
        $audit = VeolabQualityAudits::audit($del, $ser, $cod);
        if (! $audit) {
            return [];
        }
        $people = $db->table('CALAUQ')
            ->leftJoin('GRHEMP', fn ($j) => $j->on('GRHEMP.DEL3COD', '=', 'CALAUQ.EMP3DEL')->on('GRHEMP.EMP1COD', '=', 'CALAUQ.EMP3COD'))
            ->where('CALAUQ.DEL3COD', $del)->where('CALAUQ.AUI3SER', $ser)->where('CALAUQ.AUI3COD', $cod)
            ->get(['CALAUQ.EMP3DEL', 'CALAUQ.EMP3COD', 'CALAUQ.AUQCROL', 'GRHEMP.EMPCNOM']);
        $auditors = [];
        $auditees = [];
        foreach ($people as $p) {
            $key = $p->EMP3DEL."\x1B".(int) $p->EMP3COD;
            $name = trim((string) $p->EMPCNOM) !== '' ? trim((string) $p->EMPCNOM) : (string) $p->EMP3COD;
            if ($p->AUQCROL === VeolabQualityAudits::ROLE_TEAM) {
                $auditors[$key] = $name;
            } elseif ($p->AUQCROL === VeolabQualityAudits::ROLE_AUDITEE) {
                $auditees[$key] = $name;
            }
        }
        if ((int) ($audit->EMA2COD ?? 0) !== 0) {
            $key = $audit->EMA2DEL."\x1B".(int) $audit->EMA2COD;
            $auditors[$key] = trim((string) $db->table('GRHEMP')->where('DEL3COD', (string) $audit->EMA2DEL)
                ->where('EMP1COD', (int) $audit->EMA2COD)->value('EMPCNOM')) ?: (string) $audit->EMA2COD;
        }

        $warnings = [];
        foreach (array_intersect_key($auditors, $auditees) as $name) {
            $warnings[] = "{$name} es auditor y está entre los auditados: el auditor no debe auditar su propio trabajo (independencia)";
        }
        $type = (string) ($audit->AUICTIP ?? '');
        if (VeolabQualityAudits::isOwnInternal($type) && ! $auditors) {
            $warnings[] = 'La auditoría interna no tiene auditor: indique el auditor jefe o el equipo auditor';
        } elseif ($type === 'C' && trim((string) ($audit->PRO2COD ?? '')) === '' && trim((string) ($audit->AUICAUD ?? '')) === '') {
            $warnings[] = 'La auditoría interna contratada no tiene auditor: indique la entidad auditora o el nombre del auditor externo';
        }

        return $warnings;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /**
     * Además de los campos (anio, tipo, estado...):
     *  - ?auditor_delegacion=&auditor_codigo=: las de ese auditor (jefe o del equipo).
     *  - ?auditado_delegacion=&auditado_codigo=: las que lo tienen entre los auditados.
     *  - ?con_hallazgos_abiertos=T|F: con algún hallazgo abierto (o ninguno).
     *  - ?apartado=: las que tienen ese apartado en su alcance.
     *  - ?parametro_delegacion=&parametro_codigo=: las que lo tienen entre los ensayos presenciados.
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $own = fn ($s, string $table) => $s->select(DB::raw(1))->from($table)->whereColumn("{$table}.DEL3COD", 'CALAUI.DEL3COD')
            ->whereColumn("{$table}.AUI3SER", 'CALAUI.AUI1SER')->whereColumn("{$table}.AUI3COD", 'CALAUI.AUI1COD');

        foreach (['auditor' => VeolabQualityAudits::ROLE_TEAM, 'auditado' => VeolabQualityAudits::ROLE_AUDITEE] as $param => $role) {
            $code = $request->query("{$param}_codigo");
            if (! is_string($code) || $code === '') {
                continue;
            }
            if (! ctype_digit($code)) {
                throw new BusinessRuleException("El filtro {$param}_codigo no es un número");
            }
            $del = (string) ($request->query("{$param}_delegacion") ?? '');
            $query->where(function ($q) use ($own, $role, $del, $code, $param) {
                $q->whereExists(fn ($s) => $own($s, 'CALAUQ')->where('CALAUQ.AUQCROL', $role)
                    ->where('CALAUQ.EMP3DEL', $del)->where('CALAUQ.EMP3COD', (int) $code));
                if ($param === 'auditor') {
                    $q->orWhere(fn ($w) => $w->where('CALAUI.EMA2DEL', $del)->where('CALAUI.EMA2COD', (int) $code));
                }
            });
        }

        $open = $request->query('con_hallazgos_abiertos');
        if (is_string($open) && $open !== '') {
            $exists = function ($s) {
                $s->select(DB::raw(1))->from('CALIYE')
                    ->join('CALINC', function ($j) {
                        $j->on('CALINC.DEL3COD', '=', 'CALIYE.DEL3COD')->on('CALINC.INC1SER', '=', 'CALIYE.INC3SER')
                            ->on('CALINC.INC1COD', '=', 'CALIYE.INC3COD');
                    })
                    ->where('CALIYE.IYECTAB', VeolabQualityAudits::TABLE)
                    ->whereColumn('CALIYE.IYECDEL', 'CALAUI.DEL3COD')->whereColumn('CALIYE.IYECSER', 'CALAUI.AUI1SER')
                    ->whereRaw('CALIYE.IYECCOD = CAST(CALAUI.AUI1COD AS CHAR)')
                    ->where(fn ($q) => $q->whereIn('CALINC.INCCEST', VeolabQuality::OPEN_STATES)->orWhereNull('CALINC.INCCEST')
                        ->orWhere('CALINC.INCCEST', ''));
            };
            strtoupper($open) === 'T' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }

        $clause = $request->query('apartado');
        if (is_string($clause) && $clause !== '') {
            $query->whereExists(fn ($s) => $own($s, 'CALAUX')->where('CALAUX.AUXCAPA', $clause));
        }

        $tecCod = $request->query('parametro_codigo');
        if (is_string($tecCod) && $tecCod !== '') {
            $tecDel = (string) ($request->query('parametro_delegacion') ?? '');
            $query->whereExists(fn ($s) => $own($s, 'CALAUV')->where('CALAUV.TEC2DEL', $tecDel)->where('CALAUV.TEC2COD', $tecCod));
        }
    }

    /**
     * Datos de solo lectura: textos del tipo y del estado, nombres del
     * auditor jefe y de la entidad, las listas con sus nombres, el origen de
     * sus hallazgos (I o E) y los hallazgos (incidencias enlazadas) con
     * cuántos siguen abiertos.
     */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $k = fn ($d, $s, $c) => $d."\x1B".$s."\x1B".(int) $c;
        $byAudit = function (string $table) use ($db, $rows) {
            return $db->table($table)
                ->where(function ($q) use ($rows, $table) {
                    foreach ($rows as $row) {
                        $q->orWhere(fn ($w) => $w->where("{$table}.DEL3COD", (string) $row['delegacion'])
                            ->where("{$table}.AUI3SER", (string) $row['serie'])->where("{$table}.AUI3COD", (int) $row['codigo']));
                    }
                });
        };
        $group = fn ($r) => $r->DEL3COD."\x1B".$r->AUI3SER."\x1B".(int) $r->AUI3COD;

        $people = $byAudit('CALAUQ')
            ->leftJoin('GRHEMP', fn ($j) => $j->on('GRHEMP.DEL3COD', '=', 'CALAUQ.EMP3DEL')->on('GRHEMP.EMP1COD', '=', 'CALAUQ.EMP3COD'))
            ->orderBy('GRHEMP.EMPCNOM')->orderBy('CALAUQ.EMP3COD')
            ->get(['CALAUQ.DEL3COD', 'CALAUQ.AUI3SER', 'CALAUQ.AUI3COD', 'CALAUQ.EMP3DEL', 'CALAUQ.EMP3COD', 'CALAUQ.AUQCROL', 'GRHEMP.EMPCNOM'])
            ->groupBy($group);
        $clauses = $byAudit('CALAUX')->get(['DEL3COD', 'AUI3SER', 'AUI3COD', 'AUXCAPA'])->groupBy($group);
        $delegations = $byAudit('CALAUL')
            ->leftJoin('ACCDEL', 'ACCDEL.DEL1COD', '=', 'CALAUL.DEL2COD')
            ->orderBy('CALAUL.DEL2COD')
            ->get(['CALAUL.DEL3COD', 'CALAUL.AUI3SER', 'CALAUL.AUI3COD', 'CALAUL.DEL2COD', 'ACCDEL.DELCNOM'])->groupBy($group);
        $witnessed = $byAudit('CALAUV')
            ->leftJoin('LABTEC', fn ($j) => $j->on('LABTEC.DEL3COD', '=', 'CALAUV.TEC2DEL')->on('LABTEC.TEC1COD', '=', 'CALAUV.TEC2COD'))
            ->leftJoin('GRHEMP', fn ($j) => $j->on('GRHEMP.DEL3COD', '=', 'CALAUV.EMP2DEL')->on('GRHEMP.EMP1COD', '=', 'CALAUV.EMP2COD'))
            ->orderBy('CALAUV.AUV1COD')
            ->get(['CALAUV.*', 'LABTEC.TECCNOM', 'GRHEMP.EMPCNOM'])->groupBy($group);

        $employees = $db->table('GRHEMP')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    if ((int) $row['auditor_jefe_codigo'] !== 0) {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['auditor_jefe_delegacion'])->where('EMP1COD', (int) $row['auditor_jefe_codigo']));
                    }
                }
                $q->orWhereRaw('1 = 0');
            })
            ->get(['DEL3COD', 'EMP1COD', 'EMPCNOM'])->keyBy(fn ($e) => $e->DEL3COD."\x1B".(int) $e->EMP1COD);
        $providers = $db->table('SINPRO')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    if ((string) $row['entidad_codigo'] !== '') {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['entidad_delegacion'])->where('PRO1COD', (string) $row['entidad_codigo']));
                    }
                }
                $q->orWhereRaw('1 = 0');
            })
            ->get(['DEL3COD', 'PRO1COD', 'PROCNOM'])->keyBy(fn ($p) => $p->DEL3COD."\x1B".$p->PRO1COD);
        $findings = VeolabQualityAudits::findings(array_map(fn ($r) => [(string) $r['delegacion'], (string) $r['serie'], (int) $r['codigo']], $rows));

        $person = fn ($p) => [
            'empleado_delegacion' => (string) $p->EMP3DEL,
            'empleado_codigo'     => (int) $p->EMP3COD,
            'empleado_nombre'     => $p->EMPCNOM,
        ];

        foreach ($rows as &$row) {
            $key = $k($row['delegacion'], $row['serie'], $row['codigo']);
            $type = (string) ($row['tipo'] ?? '');
            $state = (string) ($row['estado'] ?? '') === '' ? 'P' : (string) $row['estado'];
            $list = $people[$key] ?? collect();

            $row['codigo_formateado'] = VeolabCodes::format($this->table, (string) $row['codigo'], (string) $row['delegacion'], (string) $row['serie']);
            $row['tipo_texto'] = VeolabQualityAudits::TYPE_NAMES[$type] ?? null;
            $row['estado_texto'] = VeolabQualityAudits::STATE_NAMES[$state] ?? $state;
            $row['auditor_jefe_nombre'] = (int) $row['auditor_jefe_codigo'] !== 0
                ? ($employees[$row['auditor_jefe_delegacion']."\x1B".(int) $row['auditor_jefe_codigo']]->EMPCNOM ?? null) : null;
            $row['entidad_nombre'] = (string) $row['entidad_codigo'] !== ''
                ? ($providers[$row['entidad_delegacion']."\x1B".$row['entidad_codigo']]->PROCNOM ?? null) : null;
            $row['equipo'] = $list->where('AUQCROL', VeolabQualityAudits::ROLE_TEAM)->map($person)->values()->all();
            $row['auditados'] = $list->where('AUQCROL', VeolabQualityAudits::ROLE_AUDITEE)->map($person)->values()->all();
            $own = ($clauses[$key] ?? collect())->pluck('AUXCAPA')->map(fn ($c) => (string) $c)->all();
            $row['apartados'] = [];
            foreach (array_keys(VeolabQualityAudits::CLAUSES) as $clause) {     // en el orden de la norma
                if (in_array((string) $clause, $own, true)) {
                    $row['apartados'][] = ['apartado' => (string) $clause, 'titulo' => VeolabQualityAudits::CLAUSES[$clause]];
                }
            }
            foreach (array_diff($own, array_map('strval', array_keys(VeolabQualityAudits::CLAUSES))) as $other) {
                $row['apartados'][] = ['apartado' => $other, 'titulo' => null];
            }
            $row['delegaciones'] = ($delegations[$key] ?? collect())->map(fn ($d) => [
                'delegacion' => (string) $d->DEL2COD,
                'nombre'     => $d->DELCNOM,
            ])->values()->all();
            $row['ensayos_presenciados'] = ($witnessed[$key] ?? collect())->map(fn ($v) => [
                'codigo'                       => (int) $v->AUV1COD,
                'parametro_delegacion'         => $v->TEC2DEL,
                'parametro_codigo'             => $v->TEC2COD,
                'parametro_nombre'             => trim((string) $v->TECCNOM) !== '' ? trim((string) $v->TECCNOM) : $v->TEC2COD,
                'analista_delegacion'          => (int) $v->EMP2COD !== 0 ? $v->EMP2DEL : null,
                'analista_codigo'              => (int) $v->EMP2COD !== 0 ? (int) $v->EMP2COD : null,
                'analista_nombre'              => (int) $v->EMP2COD !== 0 ? $v->EMPCNOM : null,
                'operacion_delegacion'         => (int) $v->OPE2COD !== 0 ? $v->OPE2DEL : null,
                'operacion_serie'              => (int) $v->OPE2COD !== 0 ? $v->OPE2SER : null,
                'operacion_codigo'             => (int) $v->OPE2COD !== 0 ? (int) $v->OPE2COD : null,
                'operacion_codigo_formateado'  => (int) $v->OPE2COD !== 0
                    ? VeolabCodes::format('LABOPE', (string) $v->OPE2COD, (string) $v->OPE2DEL, (string) $v->OPE2SER) : null,
                'observaciones'                => $v->AUVCOBS,
            ])->values()->all();
            $row['origen_hallazgos'] = VeolabQualityAudits::findingsOrigin($type);
            $row['hallazgos'] = $findings[$key] ?? [];
            $row['hallazgos_abiertos'] = count(array_filter($row['hallazgos'], fn ($f) => $f['abierta'] === 'T'));
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Borrado
    // ------------------------------------------------------------------

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        VeolabQualityAudits::deleteAuditData((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']);
    }

    // ------------------------------------------------------------------
    // Cobertura del programa
    // ------------------------------------------------------------------

    /**
     * GET /auditorias-calidad/cobertura?desde=&hasta=&delegacion=&ciclo_desde=
     * Apartados de la norma por años de desde a hasta (por defecto los cuatro
     * anteriores y el actual; como máximo 8), cada uno auditado, previsto o
     * sin auditar, y los parámetros acreditados sin ensayo presenciado en el
     * ciclo (desde ciclo_desde; por defecto, hace un año). Solo lectura.
     */
    public function coverage(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        try {
            $from = VeolabProficiencyPlan::yearParam($request->query('desde'), 'desde');
            $to = VeolabProficiencyPlan::yearParam($request->query('hasta'), 'hasta');
            $delegation = $request->query('delegacion');
            if ($delegation !== null && ! is_string($delegation)) {
                throw new BusinessRuleException('El parámetro delegacion no es válido');
            }
            $since = $request->query('ciclo_desde');
            if ($since !== null && (! is_string($since) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) || ! strtotime($since))) {
                throw new BusinessRuleException('El parámetro ciclo_desde debe ser una fecha AAAA-MM-DD');
            }
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => ['filtro' => [$e->getMessage()]]], 422);
        }

        try {
            $db = DB::connection('dynamic');
            $current = VeolabProficiencyPlan::currentYear();
            [$from, $to] = VeolabProficiencyPlan::yearRange($from, $to, $current);
            $since ??= (string) $db->selectOne('SELECT DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AS d')->d;
            $clauses = VeolabQualityAudits::coverage($from, $to, $delegation, $current);
            $params = VeolabQualityAudits::parametersWithoutWitnessed($delegation, substr($since, 0, 10).' 00:00:00');

            // Se resume el año hasta o, si el cuadro llega a años futuros, el actual (AUI_AnioResumen del escritorio):
            // en un año futuro todavía no hay nada «sin auditar»
            $summaryYear = ($to > $current && $from <= $current) ? $current : $to;
            $last = array_filter(array_map(fn ($c) => $c['anios'][$summaryYear - $from]['estado'] ?? null, $clauses), fn ($s) => $s !== null);
            $counts = array_count_values($last) + ['A' => 0, 'P' => 0, 'S' => 0];

            return response()->json([
                'data' => [
                    'desde'                             => $from,
                    'hasta'                             => $to,
                    'anio_actual'                       => $current,
                    'anios'                             => range($from, $to),
                    'apartados'                         => $clauses,
                    'ciclo_desde'                       => substr($since, 0, 10),
                    'parametros_sin_ensayo_presenciado' => $params,
                    'resumen'                           => [
                        'anio'                              => $summaryYear,
                        'apartados'                         => count($clauses),
                        'auditados'                         => $counts['A'],
                        'previstos'                         => $counts['P'],
                        'sin_auditar'                       => $counts['S'],
                        'parametros_sin_ensayo_presenciado' => count($params),
                    ],
                ],
                'meta' => ['version_bd' => VeolabVersion::current()],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response('v2 cobertura CALAUI', $e, 'Error al calcular la cobertura de las auditorías');
        }
    }
}
