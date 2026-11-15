<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabQuality;
use App\Support\VeolabQualityAudits;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Incidencias de calidad (CALINC, módulo CAL, desde Veolab 3; ISO/IEC 17025
 * 7.9, 7.10, 8.5-8.7): quejas, trabajo no conforme, hallazgos de auditoría,
 * mejoras y riesgos. Réplica de FichaIncidencia, Incidencias y Calidad.bas.
 *
 *  - Clave delegación + serie + código; el código sale del contador ACCCLT
 *    de la serie con las reglas de ACCCFC 'CALINC', como las fichas con serie.
 *  - El estado no se cambia con PUT sino con PUT /incidencias/estado
 *    (changeState), con las comprobaciones de FichaIncidencia.CambiarEstado.
 *    Al crear queda registrada (R), con la fecha y hora de registro.
 *  - Cerrada o anulada: solo lectura (también sus acciones, enlaces e
 *    implicados) hasta que se reabre.
 *  - Los campos que dependen de la clase se guardan como la ficha: gravedad
 *    1-3 o, en riesgos, probabilidad × impacto; clasificación del hallazgo
 *    y apartado de la norma (INCCAPA) solo en hallazgos de auditoría; no conformidad nunca en mejoras ni
 *    riesgos, y se marca sola al poner la gravedad grave (se puede cambiar).
 *  - Las quejas no se eliminan nunca; el resto, según CAL_IncidenciaBorrable.
 *  - Avisos Q y K tras grabar (VeolabQuality), fuera de la transacción.
 *
 * Sin sesión de usuario: «usuario_*» es quien la registra (al crear); en el
 * cambio de estado y el borrado el usuario es opcional y, si se indica, se
 * comprueban sus privilegios como en Veolab; sin él, la API actúa como el
 * responsable de calidad.
 */
class IncidenciaController extends BaseController
{
    protected string $table = 'CALINC';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'INC1SER',
        'codigo'     => 'INC1COD',
    ];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    // Sin $foreignKeys: Veolab guarda NULL (no 0 / '') en las referencias vacías de estas tablas.
    protected array $mapping = [
        'delegacion'                            => 'DEL3COD',
        'serie'                                 => 'INC1SER',
        'codigo'                                => 'INC1COD',
        'clase'                                 => 'INCCCLA',   // Q queja, T trabajo no conforme, N hallazgo de auditoría, M mejora, R riesgo
        'origen'                                => 'INCCORI',
        'tipo_delegacion'                       => 'TIN2DEL',
        'tipo_codigo'                           => 'TIN2COD',
        'estado'                                => 'INCCEST',   // R registrada, T en tratamiento, V en verificación, C cerrada, A anulada
        'fecha_deteccion'                       => 'INCDDET',
        'fecha_registro'                        => 'INCTREG',
        'descripcion'                           => 'INCCDES',
        'detalle'                               => 'INCCDET',
        'correccion_inmediata'                  => 'INCCCOI',
        'gravedad'                              => 'INCNGRA',   // 1-3; en riesgos, probabilidad × impacto
        'probabilidad'                          => 'INCNPRO',
        'impacto'                               => 'INCNIMP',
        'hallazgo'                              => 'INCCGRV',   // M mayor, N menor, O observación
        'apartado'                              => 'INCCAPA',   // Apartado de la norma del hallazgo (4.1 ... 8.9)
        'no_conformidad'                        => 'INCBNCO',
        'causa'                                 => 'INCCCAU',
        'puede_darse_en_otros_casos'            => 'INCBSIM',
        'otros_casos'                           => 'INCCSIM',
        'afecta_resultados_emitidos'            => 'INCBAFI',
        'decision'                              => 'INCCDEC',
        'informes_retenidos'                    => 'INCBRET',
        'comunicado_cliente'                    => 'INCBCCL',
        'fecha_comunicacion_cliente'            => 'INCDCCL',
        'fecha_acuse_recibo'                    => 'INCDACU',
        'reanudacion_autorizada_por_delegacion' => 'EMA2DEL',
        'reanudacion_autorizada_por_codigo'     => 'EMA2COD',
        'fecha_reanudacion'                     => 'INCDREA',
        'cambios_sistema'                       => 'INCCSIS',
        'verificacion_eficacia'                 => 'INCCVEF',
        'fecha_cierre'                          => 'INCDCIE',
        'motivo_anulacion'                      => 'INCCANU',
        'coste'                                 => 'INCNCOS',
        'avisar_a_todos'                        => 'INCBTOD',
        'cliente_delegacion'                    => 'CLI2DEL',
        'cliente_codigo'                        => 'CLI2COD',
        'detectada_por_delegacion'              => 'EMP2DEL',
        'detectada_por_codigo'                  => 'EMP2COD',
        'responsable_delegacion'                => 'EMR2DEL',
        'responsable_codigo'                    => 'EMR2COD',
        'verificador_delegacion'                => 'EMV2DEL',
        'verificador_codigo'                    => 'EMV2COD',
        'usuario_delegacion'                    => 'USU2DEL',
        'usuario_codigo'                        => 'USU2COD',
    ];

    protected array $searchFields = ['INCCDES', 'INCCDET'];

    /** Empleados de la incidencia: grupo => [columna de delegación, columna de código]. */
    private const EMPLOYEES = [
        'detectada_por'              => ['EMP2DEL', 'EMP2COD'],
        'responsable'                => ['EMR2DEL', 'EMR2COD'],
        'verificador'                => ['EMV2DEL', 'EMV2COD'],
        'reanudacion_autorizada_por' => ['EMA2DEL', 'EMA2COD'],
    ];

    private const BOOLEANS = ['no_conformidad', 'puede_darse_en_otros_casos', 'afecta_resultados_emitidos',
        'informes_retenidos', 'comunicado_cliente', 'avisar_a_todos'];

    private const TEXTS = ['descripcion', 'detalle', 'correccion_inmediata', 'causa', 'otros_casos', 'decision',
        'cambios_sistema', 'verificacion_eficacia'];

    private const DATES = ['fecha_deteccion', 'fecha_comunicacion_cliente', 'fecha_acuse_recibo', 'fecha_reanudacion'];

    /** Campos que no se escriben con POST/PUT: parámetro => valor al crear. */
    private const READ_ONLY = ['estado' => 'R', 'fecha_registro' => null, 'fecha_cierre' => null, 'motivo_anulacion' => null];

    protected function rules(): array
    {
        $rules = [
            'delegacion'           => 'nullable|string|max:10',
            'serie'                => 'nullable|string|max:10',
            'codigo'               => 'nullable|integer|min:1',
            'clase'                => 'nullable|string|in:'.implode(',', VeolabQuality::CLASSES),
            'origen'               => 'nullable|string|in:'.implode(',', VeolabQuality::ORIGINS),
            'tipo_delegacion'      => 'nullable|string|max:10',
            'tipo_codigo'          => 'nullable|integer|min:0',
            'descripcion'          => 'nullable|string|max:255',
            'gravedad'             => 'nullable|integer|min:1|max:9',
            'probabilidad'         => 'nullable|integer|min:1|max:3',
            'impacto'              => 'nullable|integer|min:1|max:3',
            'hallazgo'             => 'nullable|string|in:'.implode(',', VeolabQuality::FINDINGS),
            'apartado'             => 'nullable|string|max:10',
            'coste'                => 'nullable',
            'cliente_delegacion'   => 'nullable|string|max:10',
            'cliente_codigo'       => 'nullable|string|max:15',
            'usuario_delegacion'   => 'nullable|string|max:10',
            'usuario_codigo'       => 'nullable|string|max:15',
            // Solo lectura: se aceptan si no cambian (para devolver lo leído).
            'estado'               => 'nullable',
            'fecha_registro'       => 'nullable',
            'fecha_cierre'         => 'nullable',
            'motivo_anulacion'     => 'nullable',
        ];
        foreach (self::TEXTS as $param) {
            $rules[$param] ??= 'nullable|string';
        }
        foreach (self::BOOLEANS as $param) {
            $rules[$param] = 'nullable|string|in:T,F';
        }
        foreach (self::DATES as $param) {
            $rules[$param] = 'nullable|date';
        }
        foreach (array_keys(self::EMPLOYEES) as $group) {
            $rules["{$group}_delegacion"] = 'nullable|string|max:10';
            $rules["{$group}_codigo"] = 'nullable|integer|min:0';
        }

        return $rules;
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
        $before = $isNew ? null : (array) VeolabQuality::incident((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']);
        if (! $isNew) {
            VeolabQuality::assertOpen((object) $before);
        }
        // Valor resultante de un campo: el recibido (tal como va quedando en $data) o el guardado.
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        // Solo lectura: el estado y el cierre, con PUT /incidencias/estado; el registro, al crear.
        foreach (self::READ_ONLY as $param => $initial) {
            if (! array_key_exists($param, $data)) {
                continue;
            }
            $stored = $isNew ? $initial : ($before[$this->mapping[$param]] ?? null);
            if (! self::sameValue($data[$param], $stored)) {
                throw new BusinessRuleException($param === 'estado' || $param === 'fecha_cierre' || $param === 'motivo_anulacion'
                    ? "El campo '{$param}' cambia con el estado de la incidencia: use PUT /incidencias/estado"
                    : "El campo '{$param}' lo pone Veolab al registrar la incidencia");
            }
            unset($data[$param]);
        }
        if (! $isNew) {
            foreach (['usuario_delegacion' => 'USU2DEL', 'usuario_codigo' => 'USU2COD'] as $param => $column) {
                if (array_key_exists($param, $data)) {
                    if (! self::sameValue($data[$param], $before[$column] ?? null)) {
                        throw new BusinessRuleException('El usuario que registró la incidencia no se cambia');
                    }
                    unset($data[$param]);
                }
            }
        }

        // Textos (la ficha guarda '' en los vacíos)
        foreach (self::TEXTS as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = trim((string) ($data[$param] ?? ''));
            }
        }

        // Usuario que la registra
        if ($isNew && ! empty($data['usuario_codigo'])) {
            if (! $db->table('ACCUSU')->where('DEL3COD', (string) ($data['usuario_delegacion'] ?? ''))
                ->where('USU1COD', $data['usuario_codigo'])->exists()) {
                throw new BusinessRuleException('El usuario que registra la incidencia no existe');
            }
            $data['usuario_delegacion'] = (string) ($data['usuario_delegacion'] ?? '');
        } elseif ($isNew) {
            $data['usuario_delegacion'] = null;
            $data['usuario_codigo'] = null;
        }

        // Tipo de incidencia
        $typeClass = '';
        if (array_key_exists('tipo_codigo', $data) || array_key_exists('tipo_delegacion', $data)) {
            [$typeDel, $typeCod] = [(string) $current('tipo_delegacion'), (int) $current('tipo_codigo')];
            if ($typeCod === 0) {
                $data['tipo_delegacion'] = null;
                $data['tipo_codigo'] = null;
            } else {
                $type = $db->table('CALTIN')->where('DEL3COD', $typeDel)->where('TIN1COD', $typeCod)->first(['TINCCLA']);
                if (! $type) {
                    throw new BusinessRuleException('El tipo de incidencia no existe');
                }
                $data['tipo_delegacion'] = $typeDel;
                $data['tipo_codigo'] = $typeCod;
                $typeClass = (string) $type->TINCCLA;
            }
        }

        // Clase: si no hay ninguna, la que propone el tipo (cmbTipo_Click)
        $class = (string) $current('clase');
        if ($class === '' && in_array($typeClass, VeolabQuality::CLASSES, true)) {
            $class = $data['clase'] = $typeClass;
        }

        // Las quejas no se eliminan nunca: tampoco cambiándoles la clase (la ficha bloquea la clase de una queja grabada)
        if (! $isNew && (string) ($before['INCCCLA'] ?? '') === 'Q' && $class !== 'Q') {
            throw new BusinessRuleException('La clase de una queja no se cambia: las quejas se conservan como registro (ISO 17025, 7.9); si no procede, se anula');
        }

        // Obligatorios al grabar: clase, descripción, fecha de detección y quién la detectó (MEN00715)
        if ($isNew && empty($data['fecha_deteccion'])) {
            $data['fecha_deteccion'] = (string) $db->selectOne('SELECT CURDATE() AS d')->d;
        }
        if ($class === '' || trim((string) $current('descripcion')) === '' || empty($current('fecha_deteccion'))
            || (int) $current('detectada_por_codigo') === 0) {
            throw new BusinessRuleException('La clase, el título (descripcion), la fecha de detección y quién la detectó son obligatorios');
        }

        // Empleados: existen y, si cambian, en activo (la ficha solo ofrece los de alta)
        foreach (self::EMPLOYEES as $group => [$delColumn, $codColumn]) {
            if (! array_key_exists("{$group}_codigo", $data) && ! array_key_exists("{$group}_delegacion", $data)) {
                continue;
            }
            $empDel = (string) $current("{$group}_delegacion");
            $empCod = (int) $current("{$group}_codigo");
            if ($empCod === 0) {
                $data["{$group}_delegacion"] = null;
                $data["{$group}_codigo"] = null;
                continue;
            }
            $employee = $db->table('GRHEMP')->where('DEL3COD', $empDel)->where('EMP1COD', $empCod)->first(['EMPBBAJ']);
            if (! $employee) {
                throw new BusinessRuleException("El empleado de '{$group}' no existe");
            }
            $changed = $isNew || (string) ($before[$delColumn] ?? '') !== $empDel || (int) ($before[$codColumn] ?? 0) !== $empCod;
            if ($changed && $employee->EMPBBAJ === 'T') {
                throw new BusinessRuleException("El empleado de '{$group}' está de baja");
            }
            $data["{$group}_delegacion"] = $empDel;
            $data["{$group}_codigo"] = $empCod;
        }

        // Cliente
        if (array_key_exists('cliente_codigo', $data) || array_key_exists('cliente_delegacion', $data)) {
            $cliDel = (string) $current('cliente_delegacion');
            $cliCod = (string) $current('cliente_codigo');
            if ($cliCod === '') {
                $data['cliente_delegacion'] = null;
                $data['cliente_codigo'] = null;
            } elseif (! $db->table('SINCLI')->where('DEL3COD', $cliDel)->where('CLI1COD', $cliCod)->exists()) {
                throw new BusinessRuleException('El cliente no existe');
            } else {
                $data['cliente_delegacion'] = $cliDel;
                $data['cliente_codigo'] = $cliCod;
            }
        }

        // Coste: número, con coma o punto
        if (array_key_exists('coste', $data)) {
            $cost = is_string($data['coste']) ? str_replace(',', '.', trim($data['coste'])) : $data['coste'];
            if ($cost === null || $cost === '') {
                $data['coste'] = null;
            } elseif (! is_numeric($cost)) {
                throw new BusinessRuleException('El coste debe ser un número');
            } else {
                $data['coste'] = (float) $cost;
            }
        }

        // Fechas (solo el día, como la ficha)
        foreach (self::DATES as $param) {
            if (array_key_exists($param, $data)) {
                if ($param === 'fecha_deteccion' && empty($data[$param])) {
                    throw new BusinessRuleException('La fecha de detección es obligatoria');
                }
                $data[$param] = empty($data[$param]) ? null : (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        // Acuse de recibo, solo en quejas; comunicación al cliente, en quejas y trabajo no conforme (7.10.1 e)
        if (! empty($data['fecha_acuse_recibo']) && $class !== 'Q') {
            throw new BusinessRuleException('La fecha de acuse de recibo solo se indica en las quejas');
        }
        if (((array_key_exists('comunicado_cliente', $data) && $data['comunicado_cliente'] === 'T') || ! empty($data['fecha_comunicacion_cliente']))
            && $class !== 'Q' && $class !== 'T') {
            throw new BusinessRuleException('La comunicación al cliente solo se indica en quejas y trabajo no conforme');
        }

        $data = $this->classFields($data, $class, $before, $current);

        if ($isNew) {
            foreach (self::BOOLEANS as $param) {
                $data[$param] ??= 'F';
            }
            $data['estado'] = 'R';
            $data['fecha_registro'] = (string) $db->selectOne('SELECT NOW() AS n')->n;
        } else {
            foreach (self::BOOLEANS as $param) {
                if (array_key_exists($param, $data) && $data[$param] === null) {
                    $data[$param] = 'F';
                }
            }
        }
        $data['_nuevo'] = $isNew;

        return $data;
    }

    /**
     * Campos que dependen de la clase, como FichaIncidencia.Grabar: gravedad
     * (en riesgos, probabilidad × impacto), probabilidad e impacto solo en
     * riesgos, hallazgo y apartado solo en hallazgos de auditoría y no conformidad solo
     * donde se admite; al cambiar la gravedad, la grave la marca y las demás
     * la quitan (cmbGravedad_Click), salvo que se indique.
     */
    private function classFields(array $data, string $class, ?array $before, callable $current): array
    {
        $beforeClass = (string) ($before['INCCCLA'] ?? '');
        $severityChanged = false;

        if ($class === 'R') {
            $probability = (int) $current('probabilidad');
            $impact = (int) $current('impacto');
            $data['probabilidad'] = $probability ?: null;
            $data['impacto'] = $impact ?: null;
            $data['gravedad'] = $probability && $impact ? $probability * $impact : null;
        } else {
            // Al dejar de ser riesgo, su nivel no es una gravedad (LeerRegistro solo carga 1-3 fuera de riesgos)
            $severity = array_key_exists('gravedad', $data) ? $data['gravedad']
                : ($beforeClass === 'R' ? null : ($before['INCNGRA'] ?? null));
            if ($severity !== null && ((int) $severity < 1 || (int) $severity > 3)) {
                throw new BusinessRuleException('La gravedad es 1 (leve), 2 (moderada) o 3 (grave)');
            }
            $severityChanged = array_key_exists('gravedad', $data) && (int) $severity !== (int) ($before['INCNGRA'] ?? 0);
            $data['gravedad'] = $severity === null ? null : (int) $severity;
            $data['probabilidad'] = null;
            $data['impacto'] = null;
        }

        $finding = (string) $current('hallazgo');
        $data['hallazgo'] = $class === 'N' && $finding !== '' ? $finding : null;

        // Apartado de la norma: solo en hallazgos de auditoría y de la lista fija
        $clause = trim((string) $current('apartado'));
        if ($class === 'N' && $clause !== '' && ! VeolabQualityAudits::isClause($clause)) {
            throw new BusinessRuleException("El apartado '{$clause}' no es de la norma; use uno de: "
                .implode(', ', array_map('strval', array_keys(VeolabQualityAudits::CLAUSES))));
        }
        $data['apartado'] = $class === 'N' && $clause !== '' ? $clause : null;

        if (array_key_exists('no_conformidad', $data) && $data['no_conformidad'] !== null) {
            $nonConformity = $data['no_conformidad'] === 'T';
        } elseif ($severityChanged) {
            $nonConformity = $data['gravedad'] === 3;
        } else {
            $nonConformity = ($before['INCBNCO'] ?? 'F') === 'T';
        }
        $data['no_conformidad'] = $nonConformity && VeolabQuality::admitsNonConformity($class) ? 'T' : 'F';

        return $data;
    }

    /**
     * Alta desde otro recurso con las mismas comprobaciones que POST
     * /incidencias (el hallazgo de una pregunta de la lista de verificación
     * de una auditoría). Respuesta 201 con la clave, o la del error; dentro
     * de la transacción de quien llama, el alta va en un punto de guardado.
     */
    public function createFromData(array $data)
    {
        return $this->create($data);
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

    /** Tras crear: avisos del alta (Q a los responsables de calidad) y de las acciones (K). */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if (! empty($data['_nuevo'])) {
            [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
            $actor = ! empty($data['usuario_codigo'])
                ? ['delegacion' => (string) $data['usuario_delegacion'], 'codigo' => (string) $data['usuario_codigo']] : null;
            VeolabQuality::afterCommit(function () use ($del, $ser, $cod, $actor) {
                VeolabQuality::notifyStep($del, $ser, $cod, '', $actor);
                VeolabQuality::notifyActions($del, $ser, $cod);
            });
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /**
     * Además de los campos:
     *  - ?enlace_tabla=&enlace_delegacion=&enlace_serie=&enlace_codigo=: las
     *    incidencias que afectan a ese registro (CAL_IncidenciasDe); las de un
     *    cliente son también las que lo tienen como cliente, y las de un
     *    producto, también las de sus series o lotes.
     *  - ?propias_usuario_delegacion=&propias_usuario_codigo=: las que ve un
     *    usuario sin privilegio de ver todas (CAL_CondicionPropias).
     *  - ?abiertas=T|F: registradas, en tratamiento o en verificación (o no).
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $table = $request->query('enlace_tabla');
        if (is_string($table) && $table !== '') {
            if (! isset(VeolabQuality::LINK_TABLES[$table])) {
                throw new BusinessRuleException('enlace_tabla debe ser una de: '.implode(', ', array_keys(VeolabQuality::LINK_TABLES)));
            }
            $code = (string) ($request->query('enlace_codigo') ?? '');
            if ($code === '') {
                throw new BusinessRuleException('Falta enlace_codigo');
            }
            [$table, $del, $ser, $code] = VeolabQuality::normalizeLink($table, (string) ($request->query('enlace_delegacion') ?? ''),
                (string) ($request->query('enlace_serie') ?? ''), $code);

            $query->where(function ($q) use ($table, $del, $ser, $code) {
                $q->whereExists(function ($s) use ($table, $del, $ser, $code) {
                    $s->select(DB::raw(1))->from('CALIYE')
                        ->whereColumn('CALIYE.DEL3COD', 'CALINC.DEL3COD')->whereColumn('CALIYE.INC3SER', 'CALINC.INC1SER')
                        ->whereColumn('CALIYE.INC3COD', 'CALINC.INC1COD')
                        ->where(function ($w) use ($table, $del, $ser, $code) {
                            $w->where(fn ($x) => $x->where('CALIYE.IYECTAB', $table)->where('CALIYE.IYECDEL', $del)
                                ->where('CALIYE.IYECSER', $ser)->where('CALIYE.IYECCOD', $code));
                            if ($table === 'ALMPRD') {
                                $w->orWhere(fn ($x) => $x->where('CALIYE.IYECTAB', 'ALMSEL')->where('CALIYE.IYECDEL', $del)
                                    ->where('CALIYE.IYECSER', $code));
                            }
                        });
                });
                if ($table === 'SINCLI') {
                    $q->orWhere(fn ($x) => $x->where('CALINC.CLI2DEL', $del)->where('CALINC.CLI2COD', $code));
                }
            });
        }

        $userCode = $request->query('propias_usuario_codigo');
        if (is_string($userCode) && $userCode !== '') {
            $userDel = (string) ($request->query('propias_usuario_delegacion') ?? '');
            $user = VeolabQuality::user($userDel, $userCode);
            if (! $user) {
                throw new BusinessRuleException('El usuario de propias_usuario_codigo no existe');
            }
            $query->where(function ($q) use ($userDel, $userCode, $user) {
                // Delegación de quien la registró: la de los usuarios centrales es vacía y puede estar a NULL (CondicionDelegacionRegistro)
                $q->where(fn ($x) => $x->where('CALINC.USU2COD', $userCode)->where(fn ($d) => $userDel === ''
                        ? $d->where('CALINC.USU2DEL', '')->orWhereNull('CALINC.USU2DEL')
                        : $d->where('CALINC.USU2DEL', $userDel)))
                    ->orWhere('CALINC.INCBTOD', 'T');
                if ($user['empleado'] !== null) {
                    [$empDel, $empCod] = $user['empleado'];
                    foreach (['EMP2', 'EMR2', 'EMV2'] as $prefix) {
                        $q->orWhere(fn ($x) => $x->where("CALINC.{$prefix}DEL", $empDel)->where("CALINC.{$prefix}COD", $empCod));
                    }
                    // Implicados, y responsable o verificador de alguna acción
                    foreach ([['CALIEM', 'EMP3'], ['CALACC', 'EMP2'], ['CALACC', 'EMV2']] as [$child, $prefix]) {
                        $q->orWhereExists(function ($s) use ($child, $prefix, $empDel, $empCod) {
                            $s->select(DB::raw(1))->from($child)
                                ->whereColumn("{$child}.DEL3COD", 'CALINC.DEL3COD')->whereColumn("{$child}.INC3SER", 'CALINC.INC1SER')
                                ->whereColumn("{$child}.INC3COD", 'CALINC.INC1COD')
                                ->where("{$child}.{$prefix}DEL", $empDel)->where("{$child}.{$prefix}COD", $empCod);
                        });
                    }
                }
            });
        }

        $open = $request->query('abiertas');
        if (is_string($open) && $open !== '') {
            strtoupper($open) === 'T'
                ? $query->whereIn('CALINC.INCCEST', VeolabQuality::OPEN_STATES)
                : $query->whereNotIn('CALINC.INCCEST', VeolabQuality::OPEN_STATES);
        }
    }

    /**
     * Datos calculados de solo lectura: acciones y acciones vencidas (fecha
     * prevista pasada sin fecha real, CAL_SQLAccionesVencidas) y si sigue el
     * tratamiento completo (pasa por verificación).
     */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $stats = $db->table('CALACC')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['delegacion'])->where('INC3SER', (string) $row['serie'])
                        ->where('INC3COD', (int) $row['codigo']));
                }
            })
            ->groupBy('DEL3COD', 'INC3SER', 'INC3COD')
            ->get([
                'DEL3COD', 'INC3SER', 'INC3COD',
                DB::raw('COUNT(*) AS total'),
                DB::raw('SUM(CASE WHEN ACCDREA IS NULL AND ACCDPRE < CURDATE() THEN 1 ELSE 0 END) AS vencidas'),
                DB::raw("SUM(CASE WHEN ACCCTIP = 'A' THEN 1 ELSE 0 END) AS correctivas"),
            ])
            ->keyBy(fn ($s) => $s->DEL3COD."\x1B".$s->INC3SER."\x1B".$s->INC3COD);

        foreach ($rows as &$row) {
            $stat = $stats[$row['delegacion']."\x1B".$row['serie']."\x1B".$row['codigo']] ?? null;
            $row['acciones'] = (int) ($stat->total ?? 0);
            $row['acciones_vencidas'] = in_array((string) $row['estado'], VeolabQuality::OPEN_STATES, true) ? (int) ($stat->vencidas ?? 0) : 0;
            $row['tratamiento_completo'] = VeolabQuality::fullTreatment((string) $row['clase'], $row['no_conformidad'] === 'T',
                (int) ($stat->correctivas ?? 0) > 0) ? 'T' : 'F';
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Borrado (Incidencias.Borrar + CAL_IncidenciaBorrable + CAL_BorrarIncidencia)
    // ------------------------------------------------------------------

    /**
     * Las quejas no se eliminan nunca (se anulan con su motivo, 7.9). El
     * responsable de calidad elimina las registradas y las anuladas; los
     * demás, solo las registradas que registraron ellos. Con
     * ?usuario_delegacion=&usuario_codigo= se aplica a ese usuario; sin él,
     * la API actúa como responsable de calidad.
     */
    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
        $incident = VeolabQuality::incident((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'], true);
        if (! $incident) {
            return;
        }
        if ($incident->INCCCLA === 'Q') {
            throw new BusinessRuleException('Las quejas no se eliminan: si no proceden, se anulan con su motivo (ISO 17025, 7.9)');
        }

        $user = VeolabQuality::requestUser(request()->query('usuario_delegacion'), request()->query('usuario_codigo'));
        if ($user !== null && ! $user['escritura']) {
            throw new BusinessRuleException('El usuario no tiene permiso para modificar incidencias');
        }
        $state = (string) $incident->INCCEST;
        $deletable = $user === null || $user['calidad']
            ? in_array($state, ['R', 'A', ''], true)
            : in_array($state, ['R', ''], true) && (string) $incident->USU2DEL === $user['delegacion'] && (string) $incident->USU2COD === $user['codigo'];
        if (! $deletable) {
            throw new BusinessRuleException('La incidencia no se puede eliminar porque '
                .($user !== null && ! $user['calidad']
                    ? 'ya no está registrada o la registró otro usuario (el responsable de calidad puede eliminar también las de otros usuarios y las anuladas)'
                    : 'ya no está registrada ni anulada')
                .'. Las incidencias tratadas se conservan como registro; se pueden anular');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        VeolabQuality::deleteIncidentData((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']);
    }

    // ------------------------------------------------------------------
    // Cambio de estado (FichaIncidencia.CambiarEstado)
    // ------------------------------------------------------------------

    /**
     * PUT /incidencias/estado?delegacion=&serie=&codigo=
     * Cuerpo {estado, motivo_anulacion, usuario_delegacion, usuario_codigo}:
     *   R → T iniciar el tratamiento; T → V (tratamiento completo) o T → C
     *   (corto); V → C cerrar; R/T/V → A anular con motivo; C/A → R o T
     *   reabrir (queda en tratamiento si tiene responsable, si no registrada).
     * Graba, audita el cambio de estado y avisa (Q y K) tras el commit.
     */
    public function changeState(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        foreach (array_keys($this->keys) as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }
        $keys = [
            'delegacion' => (string) ($request->query('delegacion') ?? ''),
            'serie'      => (string) ($request->query('serie') ?? ''),
            'codigo'     => (int) $request->query('codigo'),
        ];

        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'estado'             => 'required|string|in:R,T,V,C,A',
            'motivo_anulacion'   => 'nullable|string|max:255',
            'usuario_delegacion' => 'nullable|string|max:10',
            'usuario_codigo'     => 'nullable|string|max:15',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            $incident = VeolabQuality::incident($keys['delegacion'], $keys['serie'], $keys['codigo'], true);
            if (! $incident) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $actor = VeolabQuality::requestUser($body['usuario_delegacion'] ?? null, $body['usuario_codigo'] ?? null);

            $from = VeolabQuality::state($incident);
            [$to, $update] = $this->transition($incident, $from, (string) $body['estado'], $body, $actor);
            $db->table($this->table)->where('DEL3COD', $keys['delegacion'])->where('INC1SER', $keys['serie'])
                ->where('INC1COD', $keys['codigo'])->update($update);

            $row = $this->auditRow($keys);
            VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'CALINCINCCEST',
                VeolabQuality::STATE_NAMES[$to], VeolabQuality::STATE_NAMES[$from] ?? '');
            if ($to === 'C') {
                $this->completeActionVerification($keys, $incident);
            }

            $previous = (string) $incident->INCCEST;
            VeolabQuality::afterCommit(function () use ($keys, $previous, $actor) {
                VeolabQuality::notifyStep($keys['delegacion'], $keys['serie'], $keys['codigo'], $previous, $actor);
                VeolabQuality::notifyActions($keys['delegacion'], $keys['serie'], $keys['codigo']);
            });

            $db->commit();

            return response()->json(['message' => 'Estado cambiado correctamente', 'data' => $keys + ['estado' => $to]]);
        } catch (ValidationException $e) {
            $db->rollBack();

            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 estado {$this->table}", $e, 'Error al cambiar el estado de la incidencia');
        }
    }

    /**
     * GET /incidencias/historial?delegacion=&serie=&codigo=
     * Historial de la incidencia desde el registro de cambios (ACCAUD y su
     * archivo ACAAUD), como la pestaña Historial de FichaIncidencia: altas,
     * modificaciones y borrados de la incidencia y de sus acciones, enlaces e
     * implicados (AUDCTAB CALINC, CALACC, CALIYE o CALIEM con el código
     * formateado de la incidencia en AUDCFIL), lo más reciente primero. Sin
     * lecturas, errores ni registros de facturación. «meta.aviso» explica un
     * historial vacío o sin detalle por campo según ACCPAR.PARNAUN.
     */
    public function history(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        foreach (array_keys($this->keys) as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }
        $keys = [
            'delegacion' => (string) ($request->query('delegacion') ?? ''),
            'serie'      => (string) ($request->query('serie') ?? ''),
            'codigo'     => (int) $request->query('codigo'),
        ];

        try {
            $db = DB::connection('dynamic');
            if (! VeolabQuality::incident($keys['delegacion'], $keys['serie'], $keys['codigo'])) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            $row = mb_substr($this->auditRow($keys), 0, 50);
            $source = fn (string $table, int $archived) => $db->table($table)
                ->whereIn('AUDCTAB', ['CALINC', 'CALACC', 'CALIYE', 'CALIEM'])
                ->where('AUDCFIL', $row)
                ->whereIn('AUDCTIP', [VeolabAudit::INSERCION, VeolabAudit::MODIFICACION_FILA, VeolabAudit::MODIFICACION_CAMPO,
                    VeolabAudit::MODIFICACION, VeolabAudit::BORRADO])
                ->select(['AUD1COD', 'AUDTFEC', 'AUDCTIP', 'AUDCTAB', 'AUDCCAM', 'AUDCVAM', 'AUDCVAA', 'SES2COD', 'DEL2COD'])
                ->selectRaw("{$archived} AS ARCHIVADO");

            $union = $source('ACCAUD', 0);
            $archive = (int) ($db->selectOne("SELECT COUNT(*) AS N FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() "
                ."AND UPPER(TABLE_NAME) = 'ACAAUD'")->N ?? 0);
            if ($archive > 0) {
                $union->unionAll($source('ACAAUD', 1));
            }

            $events = $db->query()->fromSub($union, 'H')
                ->leftJoin('ACCSES', fn ($j) => $j->on('H.DEL2COD', '=', 'ACCSES.DEL3COD')->on('H.SES2COD', '=', 'ACCSES.SES1COD'))
                ->leftJoin('ACCUSU', fn ($j) => $j->on('ACCSES.DEL3COD', '=', 'ACCUSU.DEL3COD')->on('ACCSES.USU2COD', '=', 'ACCUSU.USU1COD'))
                ->orderByDesc('H.AUDTFEC')->orderByDesc('H.AUD1COD')
                ->get(['H.*', 'ACCUSU.USUCNOM', 'ACCSES.USU2COD', 'ACCSES.SESCOBS']);

            // Textos de Veolab (IDICAD, español), como los pinta el escritorio (TextoCampoHistorial)
            $codes = ['MEN01015', 'MEN01016', 'MEN01017', 'ETI01624', 'ETI01625'];
            foreach ($events as $e) {
                $codes[] = 'ESPAUD0'.$e->AUDCTIP;
                $codes[] = self::historyFieldCode((string) $e->AUDCCAM, (string) $e->AUDCTAB);
            }
            $texts = $db->table('IDICAD')->where('IDI3COD', 1)->whereIn('CAD1COD', array_values(array_unique(array_filter($codes))))
                ->pluck('CADCDES', 'CAD1COD');

            $data = $events->map(function ($e) use ($texts) {
                $field = (string) $e->AUDCCAM;
                $code = self::historyFieldCode($field, (string) $e->AUDCTAB);
                $fieldText = '';
                if ($field !== '' && $field[0] === '#') {
                    $fieldText = mb_substr($field, 1);
                } elseif ($code !== '') {
                    $fieldText = (string) ($texts[$code] ?? '');
                    if ($fieldText === '' && $field !== '' && $field[0] !== '$') {
                        $fieldText = $field;   // Campo sin texto de idioma: su nombre
                    }
                }
                $new = (string) $e->AUDCVAM;
                // Las acciones, enlaces e implicados se reescriben enteros: el suceso solo dice que la lista cambió
                if ($e->AUDCTIP === VeolabAudit::MODIFICACION_CAMPO && in_array($field, ['CALACC', 'CALIYE', 'CALIEM'], true)
                    && $new === '' && (string) $e->AUDCVAA === '') {
                    $new = (string) ($texts['ETI01624'] ?? '(lista modificada)');
                }
                $user = trim((string) $e->USUCNOM) ?: trim((string) $e->SESCOBS)
                    ?: str_replace('%1', (string) $e->SES2COD, (string) ($texts['ETI01625'] ?? 'Sesión %1'));

                return [
                    'codigo'         => (int) $e->AUD1COD,
                    'fecha'          => $e->AUDTFEC,
                    'tipo'           => (string) $e->AUDCTIP,   // I alta, F modificación de registro, C de campo, M de los dos, B borrado
                    'tipo_texto'     => (string) ($texts['ESPAUD0'.$e->AUDCTIP] ?? ''),
                    'tabla'          => (string) $e->AUDCTAB,
                    'campo'          => $field,
                    'campo_texto'    => $fieldText,
                    'valor_anterior' => (string) $e->AUDCVAA,
                    'valor_nuevo'    => $new,
                    'usuario'        => $user,
                    'usuario_delegacion' => (string) ($e->USU2COD !== null ? $e->DEL2COD : ''),
                    'usuario_codigo' => (string) ($e->USU2COD ?? ''),
                    'sesion'         => $e->SES2COD !== null ? (int) $e->SES2COD : null,
                    'archivado'      => (int) $e->ARCHIVADO === 1,
                ];
            })->values()->all();

            // Aviso: registro desactivado (aunque queden sucesos antiguos), sin sucesos o sin detalle por campo
            $level = VeolabAudit::level();
            $notice = null;
            if ($level < 2) {
                $notice = 'MEN01015';
            } elseif (! $data) {
                $notice = 'MEN01016';
            } elseif ($level === 2) {
                $notice = 'MEN01017';
            }

            return response()->json([
                'data' => $data,
                'meta' => [
                    'total'          => count($data),
                    'nivel_registro' => $level,     // 0 desactivado, 1 accesos, 2 registro, 3 campo
                    'aviso'          => $notice ? (string) ($texts[$notice] ?? '') : null,
                    'version_bd'     => VeolabVersion::current(),
                ],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response("v2 historial {$this->table}", $e, 'Error al leer el historial de la incidencia');
        }
    }

    /**
     * Texto de idioma de un campo del registro de cambios (AuditoriaSistema):
     * '#...' literal (sin código), '$CODIGO' cadena, el resto CAM_ del campo;
     * sin campo, la tabla si no es la propia incidencia.
     */
    private static function historyFieldCode(string $field, string $table): string
    {
        if ($field === '') {
            return $table !== '' && $table !== 'CALINC' ? 'CAM_'.$table : '';
        }
        if ($field[0] === '#') {
            return '';
        }

        return $field[0] === '$' ? mb_substr($field, 1) : 'CAM_'.$field;
    }

    /**
     * Comprobaciones de cada paso (CambiarEstado) y privilegios del usuario si
     * se indica (PuedeAvanzar y botAnular). Devuelve [estado nuevo, columnas].
     */
    private function transition(object $incident, string $from, string $target, array $body, ?array $actor): array
    {
        $db = DB::connection('dynamic');
        $class = (string) $incident->INCCCLA;
        $actions = $db->table('CALACC')->where('DEL3COD', $incident->DEL3COD)->where('INC3SER', $incident->INC1SER)
            ->where('INC3COD', $incident->INC1COD)->get(['ACCCTIP', 'ACCDREA', 'ACCBEFI']);
        $hasCorrective = $actions->contains(fn ($a) => $a->ACCCTIP === 'A');
        $allDone = ! $actions->contains(fn ($a) => $a->ACCDREA === null);
        $complete = VeolabQuality::fullTreatment($class, $incident->INCBNCO === 'T', $hasCorrective);

        if ($actor !== null && ! $actor['escritura']) {
            throw new BusinessRuleException('El usuario no tiene permiso para modificar incidencias');
        }
        $manager = $actor === null || $actor['calidad'];
        $denied = fn () => new BusinessRuleException('El usuario no puede dar este paso: lo da el responsable de calidad'
            .($from === 'T' ? ' o el responsable del tratamiento' : ($from === 'V' ? ' o quien verifica' : '')));
        $name = fn (string $s) => '«'.(VeolabQuality::STATE_NAMES[$s] ?? $s).'»';

        $update = ['INCCEST' => null];
        if (in_array($from, ['C', 'A'], true)) {
            // Reabrir: en tratamiento si tiene responsable; si no, registrada
            if ($target !== 'R' && $target !== 'T') {
                throw new BusinessRuleException('La incidencia está '.$name($from).': solo se puede reabrir (estado R o T)');
            }
            if (! $manager) {
                throw $denied();
            }
            $to = (int) $incident->EMR2COD !== 0 ? 'T' : 'R';
        } elseif ($target === 'A') {
            if (! $manager) {
                throw $denied();
            }
            $reason = trim((string) ($body['motivo_anulacion'] ?? ''));
            if ($reason === '') {
                throw new BusinessRuleException('Para anular la incidencia hace falta el motivo de la anulación (motivo_anulacion)');
            }
            $to = 'A';
            $update['INCCANU'] = mb_substr($reason, 0, 255);
        } else {
            $next = match ($from) {
                'R'     => 'T',
                'T'     => $complete ? 'V' : 'C',
                default => 'C',
            };
            if ($target !== $next) {
                throw new BusinessRuleException($target === $from
                    ? 'La incidencia ya está '.$name($from)
                    : 'La incidencia está '.$name($from).': el siguiente paso es '.$name($next)
                        .($from === 'T' ? ($complete ? ' (tratamiento completo: no conformidad, queja o acción correctiva)' : ' (tratamiento corto)') : '')
                        .' o anularla');
            }
            $allowed = $manager
                || ($from === 'T' && $next === 'V' && VeolabQuality::isUserEmployee($actor, $incident->EMR2DEL, $incident->EMR2COD))
                || ($from === 'V' && VeolabQuality::isUserEmployee($actor, $incident->EMV2DEL, $incident->EMV2COD));
            if (! $allowed) {
                throw $denied();
            }
            $this->checkStep($incident, $from, $next, $actions, $hasCorrective, $allDone);
            $to = $next;
        }

        $update['INCCEST'] = $to;
        if ($to === 'C') {
            $update['INCDCIE'] = (string) $db->selectOne('SELECT NOW() AS n')->n;
        } elseif ($to !== 'A') {
            $update['INCDCIE'] = null;
        }
        if ($to !== 'A' && $from === 'A') {
            $update['INCCANU'] = null;
        }

        return [$to, $update];
    }

    /**
     * CompletarVerificacionAcciones (8.7.1 d): al cerrar, las acciones con la
     * eficacia evaluada que no tienen fecha de verificación o quien verifica
     * toman la fecha de hoy y quien verifica la incidencia; lo ya escrito no
     * se cambia.
     */
    private function completeActionVerification(array $keys, object $incident): void
    {
        $db = DB::connection('dynamic');
        $evaluated = fn () => $db->table('CALACC')->where('DEL3COD', $keys['delegacion'])
            ->where('INC3SER', $keys['serie'])->where('INC3COD', $keys['codigo'])->whereIn('ACCBEFI', ['T', 'F']);

        $changed = $evaluated()->whereNull('ACCDEFI')
            ->update(['ACCDEFI' => $db->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00']);
        if ((int) $incident->EMV2COD !== 0) {
            $changed += $evaluated()->where(fn ($q) => $q->whereNull('EMV2COD')->orWhere('EMV2COD', 0))
                ->update(['EMV2DEL' => (string) $incident->EMV2DEL, 'EMV2COD' => (int) $incident->EMV2COD]);
        }
        if ($changed > 0) {
            VeolabQuality::auditIncident((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'], 'CALACC');
        }
    }

    /** Lo mínimo de cada paso (MEN00716-726). */
    private function checkStep(object $incident, string $from, string $to, $actions, bool $hasCorrective, bool $allDone): void
    {
        $actionCount = $actions->count();
        $class = (string) $incident->INCCCLA;
        $empty = fn ($v) => trim((string) $v) === '';
        $pendingActions = 'Todas las acciones deben tener su fecha real';

        if ($to === 'T') {
            $severity = $class === 'R' ? (int) $incident->INCNPRO > 0 && (int) $incident->INCNIMP > 0 : (int) $incident->INCNGRA > 0;
            if ($class === '' || (int) $incident->EMR2COD === 0 || ! $severity) {
                throw new BusinessRuleException($class === 'R'
                    ? 'Para iniciar el tratamiento hacen falta la probabilidad, el impacto y el responsable del tratamiento'
                    : 'Para iniciar el tratamiento hacen falta la clase, la gravedad y el responsable del tratamiento');
            }

            return;
        }
        if ($to === 'V') {
            if ($empty($incident->INCCCAU) || ! $hasCorrective) {
                throw new BusinessRuleException('Para pasar a verificación hacen falta el análisis de la causa y al menos una acción correctiva: '
                    .'las no conformidades y las quejas las necesitan siempre');
            }
            if (! $allDone) {
                throw new BusinessRuleException($pendingActions);
            }
            if ((int) $incident->EMV2COD === 0) {
                throw new BusinessRuleException('Para pasar a verificación hace falta indicar quién la verifica');
            }

            return;
        }

        // Cerrar
        if ($class === 'T' && $empty($incident->INCCDEC)) {
            throw new BusinessRuleException('Para cerrar un trabajo no conforme hace falta la decisión sobre si el trabajo es aceptable (ISO 17025, 7.10.1 d)');
        }
        if ($from === 'T') {
            if ($empty($incident->INCCCOI) && $actionCount === 0) {
                throw new BusinessRuleException('Para cerrarla hace falta la corrección inmediata o alguna acción');
            }
            if (! $allDone) {
                throw new BusinessRuleException($pendingActions);
            }
        } else {
            if ($empty($incident->INCCVEF)) {
                throw new BusinessRuleException('Para cerrarla hace falta la verificación de su eficacia');
            }
            // Las acciones se pueden cambiar mientras se verifica: se comprueban otra vez
            if (! $hasCorrective) {
                throw new BusinessRuleException('Para cerrarla hace falta al menos una acción correctiva');
            }
            if (! $allDone) {
                throw new BusinessRuleException($pendingActions);
            }
            if ((int) $incident->EMV2COD === 0) {
                throw new BusinessRuleException('Para cerrarla hace falta indicar quién la verifica');
            }
            // Eficacia de cada acción correctiva (8.7.1 d): ninguna sin evaluar y al menos una eficaz; la que no
            // funcionó se queda en F junto a la que la sustituye
            $corrective = $actions->filter(fn ($a) => $a->ACCCTIP === 'A');
            if ($corrective->contains(fn ($a) => ! in_array($a->ACCBEFI, ['T', 'F'], true))
                || ! $corrective->contains(fn ($a) => $a->ACCBEFI === 'T')) {
                throw new BusinessRuleException('Para cerrarla, todas las acciones correctivas deben tener la eficacia evaluada y al menos una debe ser eficaz');
            }
        }
        if ($class === 'Q' && $incident->INCDCCL === null) {
            throw new BusinessRuleException('En las quejas hace falta la fecha en que se comunicó el resultado al cliente');
        }
    }
}
