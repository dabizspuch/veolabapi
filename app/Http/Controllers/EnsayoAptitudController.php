<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabProficiency;
use App\Support\VeolabQuality;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ensayos de aptitud e intercomparaciones (CALEAP, módulo CAL, desde Veolab 3;
 * ISO/IEC 17025:2017 7.7.2): rondas o participaciones con organizador
 * (proveedor), programa, ronda, fechas, estado, límites de evaluación y
 * conclusión del laboratorio. Réplica de FichaEnsayoAptitud, EnsayosAptitud
 * y Aptitud.bas (App\Support\VeolabProficiency).
 *
 *  - Clave delegación + serie + código; el código sale del contador ACCCLT
 *    de la serie con las reglas de ACCCFC 'CALEAP', como las incidencias.
 *  - Obligatorios: el organizador (un proveedor) y el programa o la ronda.
 *  - Estado P pendiente de enviar, E enviada, V evaluada, C cerrada, con los
 *    pasos automáticos de la ficha (EstadoAGrabar): con fecha de envío deja
 *    de estar pendiente; con fecha del informe del organizador, evaluada.
 *  - Para cerrar hace falta la conclusión del laboratorio, aunque todo sea
 *    satisfactorio (ENAC NT-03); al cerrar sin fecha de la conclusión se pone
 *    la de hoy. Cerrada, solo lectura (también sus muestras y resultados)
 *    salvo el estado, para reabrirla; no se elimina.
 *  - Al cambiar los límites se vuelven a evaluar sus resultados.
 *  - Con abrir_incidencia = T en el PUT y algún insatisfactorio sin
 *    incidencias enlazadas, se abre la incidencia como la ficha propone.
 *  - Fase 2: al grabar se rehace el aviso J a quien la registró o, si ya no
 *    es un usuario activo, al de ?usuario_delegacion=&usuario_codigo= (fecha
 *    límite de envío próxima mientras está pendiente de enviar, con el
 *    módulo COM y LABCON.CONBNAP); al eliminarla se borra.
 */
class EnsayoAptitudController extends BaseController
{
    protected string $table = 'CALEAP';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'EAP1SER',
        'codigo'     => 'EAP1COD',
    ];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    // Sin $foreignKeys: Veolab guarda NULL en las referencias vacías de estas tablas.
    protected array $mapping = [
        'delegacion'             => 'DEL3COD',
        'serie'                  => 'EAP1SER',
        'codigo'                 => 'EAP1COD',
        'organizador_delegacion' => 'PRO2DEL',
        'organizador_codigo'     => 'PRO2COD',
        'programa'               => 'EAPCPRG',
        'ronda'                  => 'EAPCRON',
        'descripcion'            => 'EAPCDES',   // Matriz o descripción
        'fecha_recepcion'        => 'EAPDREC',
        'fecha_limite'           => 'EAPDLIM',
        'fecha_envio'            => 'EAPDENV',
        'fecha_informe'          => 'EAPDINF',
        'estado'                 => 'EAPCEST',   // P pendiente de enviar, E enviada, V evaluada, C cerrada
        'limite_satisfactorio'   => 'EAPNLSA',   // |z|, z' y zeta (vacío = 2)
        'limite_insatisfactorio' => 'EAPNLIN',   // (vacío = 3)
        'limite_en'              => 'EAPNLEN',   // |En| (vacío = 1)
        'conclusion'             => 'EAPCCON',
        'revisor_delegacion'     => 'EMR2DEL',
        'revisor_codigo'         => 'EMR2COD',
        'fecha_conclusion'       => 'EAPDCON',
        'observaciones'          => 'EAPCOBS',
        'fecha_registro'         => 'EAPTREG',
        'usuario_delegacion'     => 'USU2DEL',
        'usuario_codigo'         => 'USU2COD',
    ];

    protected array $searchFields = ['EAPCPRG', 'EAPCRON', 'EAPCDES'];

    private const DATES = ['fecha_recepcion', 'fecha_limite', 'fecha_envio', 'fecha_informe', 'fecha_conclusion'];

    private const LIMITS = ['limite_satisfactorio', 'limite_insatisfactorio', 'limite_en'];

    /** Textos con su longitud (la ficha guarda '' en los vacíos). */
    private const TEXTS = ['programa' => 100, 'ronda' => 50, 'descripcion' => 255, 'conclusion' => null, 'observaciones' => null];

    protected function rules(): array
    {
        $rules = [
            'delegacion'             => 'nullable|string|max:10',
            'serie'                  => 'nullable|string|max:10',
            'codigo'                 => 'nullable|integer|min:1',
            'organizador_delegacion' => 'nullable|string|max:10',
            'organizador_codigo'     => 'nullable|string|max:15',
            'estado'                 => 'nullable|string|in:'.implode(',', VeolabProficiency::STATES),
            'revisor_delegacion'     => 'nullable|string|max:10',
            'revisor_codigo'         => 'nullable|integer|min:0',
            'usuario_delegacion'     => 'nullable|string|max:10',
            'usuario_codigo'         => 'nullable|string|max:15',
            'fecha_registro'         => 'nullable',   // solo lectura: se acepta si no cambia
            'abrir_incidencia'       => 'nullable|string|in:T,F',
        ] + VeolabProficiency::incidentRules();
        foreach (self::TEXTS as $param => $max) {
            $rules[$param] = 'nullable|string'.($max ? "|max:{$max}" : '');
        }
        foreach (self::DATES as $param) {
            $rules[$param] = 'nullable|date';
        }
        foreach (self::LIMITS as $param) {
            $rules[$param] = 'nullable|string|max:30';   // número (con coma o punto)
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
        $before = $isNew ? null : (array) VeolabProficiency::round((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'], true);
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        // Solo lectura: el registro (fecha y usuario, al crear)
        if (array_key_exists('fecha_registro', $data)) {
            if ((! $isNew && ! self::sameValue($data['fecha_registro'], $before['EAPTREG'] ?? null)) || ($isNew && ! empty($data['fecha_registro']))) {
                throw new BusinessRuleException("El campo 'fecha_registro' lo pone Veolab al registrar el ensayo de aptitud");
            }
            unset($data['fecha_registro']);
        }
        if (! $isNew) {
            foreach (['usuario_delegacion' => 'USU2DEL', 'usuario_codigo' => 'USU2COD'] as $param => $column) {
                if (array_key_exists($param, $data)) {
                    if (! self::sameValue($data[$param], $before[$column] ?? null)) {
                        throw new BusinessRuleException('El usuario que registró el ensayo de aptitud no se cambia');
                    }
                    unset($data[$param]);
                }
            }
        }

        // Cerrado: solo lectura salvo el estado (EstadoSegunRonda); si se reabre, se puede cambiar todo a la vez
        $storedState = $isNew ? 'P' : VeolabProficiency::state((object) $before);
        $state = (string) ($current('estado') ?? '');
        $state = $state === '' ? 'P' : $state;
        if (! $isNew && $storedState === 'C' && $state === 'C') {
            foreach ($data as $param => $value) {
                if (isset($this->mapping[$param]) && ! isset($this->keys[$param]) && $param !== 'estado'
                    && ! self::sameValue($value, $before[$this->mapping[$param]] ?? null)) {
                    throw new BusinessRuleException('El ensayo de aptitud está cerrado y solo se puede consultar; para cambiarlo hay que '
                        .'reabrirlo (otro estado)');
                }
            }
            if (($data['abrir_incidencia'] ?? 'F') !== 'T') {
                // Nada que grabar: se devuelve tal cual
                foreach (array_keys($data) as $param) {
                    if (! isset($this->keys[$param])) {
                        unset($data[$param]);
                    }
                }

                return $data;
            }
        }

        // Textos ('' en los vacíos, como la ficha)
        foreach (array_keys(self::TEXTS) as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = trim((string) ($data[$param] ?? ''));
            }
        }

        // Usuario que lo registra
        if ($isNew && ! empty($data['usuario_codigo'])) {
            if (! $db->table('ACCUSU')->where('DEL3COD', (string) ($data['usuario_delegacion'] ?? ''))
                ->where('USU1COD', $data['usuario_codigo'])->exists()) {
                throw new BusinessRuleException('El usuario que registra el ensayo de aptitud no existe');
            }
            $data['usuario_delegacion'] = (string) ($data['usuario_delegacion'] ?? '');
        } elseif ($isNew) {
            $data['usuario_delegacion'] = null;
            $data['usuario_codigo'] = null;
        }

        // Organizador: un proveedor; si cambia, de alta (la ficha solo ofrece esos)
        if (array_key_exists('organizador_codigo', $data) || array_key_exists('organizador_delegacion', $data)) {
            $proDel = (string) $current('organizador_delegacion');
            $proCod = trim((string) $current('organizador_codigo'));
            if ($proCod === '') {
                $data['organizador_delegacion'] = null;
                $data['organizador_codigo'] = null;
            } else {
                $provider = $db->table('SINPRO')->where('DEL3COD', $proDel)->where('PRO1COD', $proCod)->first(['PROBBAJ']);
                if (! $provider) {
                    throw new BusinessRuleException('El organizador (proveedor) no existe');
                }
                $changed = $isNew || (string) ($before['PRO2DEL'] ?? '') !== $proDel || (string) ($before['PRO2COD'] ?? '') !== $proCod;
                if ($changed && $provider->PROBBAJ === 'T') {
                    throw new BusinessRuleException('El organizador (proveedor) está de baja');
                }
                $data['organizador_delegacion'] = $proDel;
                $data['organizador_codigo'] = $proCod;
            }
        }
        if (trim((string) $current('organizador_codigo')) === ''
            || (trim((string) $current('programa')) === '' && trim((string) $current('ronda')) === '')) {
            throw new BusinessRuleException('Indique el organizador del ensayo de aptitud (uno de los proveedores) y el programa o la ronda');
        }

        // Revisor de la conclusión: existe y, si cambia, de alta
        if (array_key_exists('revisor_codigo', $data) || array_key_exists('revisor_delegacion', $data)) {
            $empDel = (string) $current('revisor_delegacion');
            $empCod = (int) $current('revisor_codigo');
            if ($empCod === 0) {
                $data['revisor_delegacion'] = null;
                $data['revisor_codigo'] = null;
            } else {
                $employee = $db->table('GRHEMP')->where('DEL3COD', $empDel)->where('EMP1COD', $empCod)->first(['EMPBBAJ']);
                if (! $employee) {
                    throw new BusinessRuleException('El empleado que revisa la conclusión no existe');
                }
                $changed = $isNew || (string) ($before['EMR2DEL'] ?? '') !== $empDel || (int) ($before['EMR2COD'] ?? 0) !== $empCod;
                if ($changed && $employee->EMPBBAJ === 'T') {
                    throw new BusinessRuleException('El empleado que revisa la conclusión está de baja');
                }
                $data['revisor_delegacion'] = $empDel;
                $data['revisor_codigo'] = $empCod;
            }
        }

        // Fechas (solo el día, como la ficha)
        foreach (self::DATES as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = empty($data[$param]) ? null : (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        // Límites: números con coma o punto (vacíos = los de ISO 13528)
        foreach (self::LIMITS as $param) {
            if (array_key_exists($param, $data)) {
                if ($data[$param] === null || trim((string) $data[$param]) === '') {
                    $data[$param] = null;
                } elseif (($number = VeolabProficiency::number($data[$param])) === null) {
                    throw new BusinessRuleException("El campo '{$param}' debe ser un número");
                } else {
                    $data[$param] = $number;
                }
            }
        }
        // Los informados, mayores que cero, y el satisfactorio por debajo del insatisfactorio (CamposValidos de la ficha)
        if (array_intersect(self::LIMITS, array_keys($data))) {
            $limSat = VeolabProficiency::number($current('limite_satisfactorio')) ?? VeolabProficiency::LIMIT_SATISFACTORY;
            $limIns = VeolabProficiency::number($current('limite_insatisfactorio')) ?? VeolabProficiency::LIMIT_UNSATISFACTORY;
            $limEn = VeolabProficiency::number($current('limite_en')) ?? VeolabProficiency::LIMIT_EN;
            if ($limSat <= 0 || $limIns <= 0 || $limEn <= 0 || $limSat >= $limIns) {
                throw new BusinessRuleException('Los límites de evaluación deben ser mayores que cero y el satisfactorio, menor que el insatisfactorio');
            }
        }

        // Pasos automáticos de estado y, al cerrar, la conclusión obligatoria y su fecha
        $state = VeolabProficiency::automaticState($state, ! empty($current('fecha_envio')), ! empty($current('fecha_informe')));
        if ($isNew || $state !== $storedState || array_key_exists('estado', $data)) {
            $data['estado'] = $state;
        }
        if ($state === 'C') {
            if (trim((string) $current('conclusion')) === '') {
                throw new BusinessRuleException('Para cerrar el ensayo de aptitud escriba la conclusión del laboratorio, aunque todos los '
                    .'resultados sean satisfactorios (ISO/IEC 17025 7.7.2)');
            }
            if (empty($current('fecha_conclusion'))) {
                $data['fecha_conclusion'] = (string) $db->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
            }
        }

        if ($isNew) {
            foreach (array_keys(self::TEXTS) as $param) {
                $data[$param] ??= '';
            }
            $data['fecha_registro'] = (string) $db->selectOne('SELECT NOW() AS n')->n;
        }
        $data['_limites'] = ! $isNew && array_intersect(self::LIMITS, array_keys($data));

        return $data;
    }

    /** Valor recibido igual al guardado (vacíos iguales; números y fechas por su valor). */
    private static function sameValue($given, $stored): bool
    {
        $given = $given === null ? '' : trim((string) $given);
        $stored = $stored === null ? '' : trim((string) $stored);
        if ($given === $stored) {
            return true;
        }
        if (is_numeric(str_replace(',', '.', $given)) && is_numeric($stored)) {
            return (float) str_replace(',', '.', $given) === (float) $stored;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $given) && preg_match('/^\d{4}-\d{2}-\d{2}/', $stored)) {
            return strtotime($given) === strtotime($stored);
        }

        return false;
    }

    /**
     * Tras grabar: con límites nuevos, se reevalúan los resultados; con
     * abrir_incidencia, la incidencia propuesta; y se rehace el aviso J de la
     * fecha límite de envío (COM_CrearNotificacionesAptitud).
     */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        // Si quien la registró ya no es un usuario activo, el aviso es para el usuario de la petición (?usuario_delegacion=&usuario_codigo=)
        $userCod = request()->query('usuario_codigo');
        VeolabProficiency::notifyDeadline($del, $ser, $cod, is_scalar($userCod) && (string) $userCod !== ''
            ? [(string) (is_scalar(request()->query('usuario_delegacion')) ? request()->query('usuario_delegacion') : ''), (string) $userCod] : null);
        if (! empty($data['_limites'])) {
            VeolabProficiency::recalculateRound($del, $ser, $cod);
        }
        if (($data['abrir_incidencia'] ?? 'F') === 'T') {
            VeolabProficiency::openIncidentIfProposed($del, $ser, $cod, $data);
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /**
     * Además de los campos, como el panel de filtros de EnsayosAptitud:
     *  - ?abiertas=T|F: sin cerrar (o cerradas).
     *  - ?anio=AAAA: por la recepción de la muestra o, si falta, la fecha límite.
     *  - ?con_insatisfactorios=T|F: con algún insatisfactorio, falso positivo o falso negativo (o sin ninguno).
     *  - ?parametro_delegacion=&parametro_codigo=: con resultados de ese parámetro.
     *  - ?operacion_delegacion=&operacion_serie=&operacion_codigo=: las que tienen esa operación como muestra.
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $open = $request->query('abiertas');
        if (is_string($open) && $open !== '') {
            strtoupper($open) === 'T'
                ? $query->where(fn ($q) => $q->where('CALEAP.EAPCEST', '<>', 'C')->orWhereNull('CALEAP.EAPCEST'))
                : $query->where('CALEAP.EAPCEST', 'C');
        }

        if (($year = VeolabProficiency::yearFilter($request->query('anio'))) !== null) {
            VeolabProficiency::applyYear($query, $year);
        }

        $bad = $request->query('con_insatisfactorios');
        if (is_string($bad) && $bad !== '') {
            $exists = function ($s) {
                $s->select(DB::raw(1))->from('CALEAR')->whereColumn('CALEAR.DEL3COD', 'CALEAP.DEL3COD')
                    ->whereColumn('CALEAR.EAP3SER', 'CALEAP.EAP1SER')->whereColumn('CALEAR.EAP3COD', 'CALEAP.EAP1COD')
                    ->whereIn('CALEAR.EARCEVA', VeolabProficiency::BAD);
            };
            strtoupper($bad) === 'T' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }

        $tecCod = $request->query('parametro_codigo');
        if (is_string($tecCod) && $tecCod !== '') {
            $tecDel = (string) ($request->query('parametro_delegacion') ?? '');
            $query->whereExists(function ($s) use ($tecDel, $tecCod) {
                $s->select(DB::raw(1))->from('CALEAR')->whereColumn('CALEAR.DEL3COD', 'CALEAP.DEL3COD')
                    ->whereColumn('CALEAR.EAP3SER', 'CALEAP.EAP1SER')->whereColumn('CALEAR.EAP3COD', 'CALEAP.EAP1COD')
                    ->where('CALEAR.TEC2DEL', $tecDel)->where('CALEAR.TEC2COD', $tecCod);
            });
        }

        $opCod = $request->query('operacion_codigo');
        if (is_string($opCod) && $opCod !== '') {
            if (! ctype_digit($opCod)) {
                throw new BusinessRuleException('El filtro operacion_codigo no es un número');
            }
            $opDel = (string) ($request->query('operacion_delegacion') ?? '');
            $opSer = (string) ($request->query('operacion_serie') ?? '');
            $query->whereExists(function ($s) use ($opDel, $opSer, $opCod) {
                $s->select(DB::raw(1))->from('CALEAO')->whereColumn('CALEAO.DEL3COD', 'CALEAP.DEL3COD')
                    ->whereColumn('CALEAO.EAP3SER', 'CALEAP.EAP1SER')->whereColumn('CALEAO.EAP3COD', 'CALEAP.EAP1COD')
                    ->where('CALEAO.OPE3DEL', $opDel)->where('CALEAO.OPE3SER', $opSer)->where('CALEAO.OPE3COD', (int) $opCod);
            });
        }
    }

    /**
     * Datos calculados de solo lectura: nombre del organizador, muestras,
     * resultados, peor evaluación (EAP_SQLPeorEvaluacion: 0 sin evaluar, 1
     * satisfactorios, 2 algún cuestionable, 3 algún insatisfactorio o falso),
     * fuera de plazo (pendiente con la fecha límite pasada), incidencias
     * enlazadas y si la ficha propondría abrir una (insatisfactorios sin
     * incidencias).
     */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $k = fn ($d, $s, $c) => $d."\x1B".$s."\x1B".$c;
        $byRound = function (string $table, string $serColumn, string $codColumn, array $columns) use ($db, $rows) {
            return $db->table($table)
                ->where(function ($q) use ($rows, $serColumn, $codColumn) {
                    foreach ($rows as $row) {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['delegacion'])->where($serColumn, (string) $row['serie'])
                            ->where($codColumn, (int) $row['codigo']));
                    }
                })
                ->groupBy('DEL3COD', $serColumn, $codColumn)->get(array_merge(['DEL3COD', $serColumn, $codColumn], $columns));
        };

        $results = $byRound('CALEAR', 'EAP3SER', 'EAP3COD', [
            DB::raw('COUNT(*) AS total'),
            DB::raw("MAX(CASE WHEN EARCEVA IN ('I', 'P', 'N') THEN 3 WHEN EARCEVA = 'Q' THEN 2 WHEN EARCEVA IN ('S', 'C') THEN 1 ELSE 0 END) AS peor"),
            DB::raw("SUM(CASE WHEN EARCEVA IN ('I', 'P', 'N') THEN 1 ELSE 0 END) AS malos"),
        ])->keyBy(fn ($r) => $k($r->DEL3COD, $r->EAP3SER, $r->EAP3COD));
        $samples = $byRound('CALEAO', 'EAP3SER', 'EAP3COD', [DB::raw('COUNT(*) AS total')])
            ->keyBy(fn ($r) => $k($r->DEL3COD, $r->EAP3SER, $r->EAP3COD));
        $incidents = $db->table('CALIYE')->where('IYECTAB', 'CALEAP')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('IYECDEL', (string) $row['delegacion'])->where('IYECSER', (string) $row['serie'])
                        ->where('IYECCOD', (string) $row['codigo']));
                }
            })
            ->groupBy('IYECDEL', 'IYECSER', 'IYECCOD')
            ->get(['IYECDEL', 'IYECSER', 'IYECCOD', DB::raw('COUNT(DISTINCT DEL3COD, INC3SER, INC3COD) AS total')])
            ->keyBy(fn ($r) => $k($r->IYECDEL, $r->IYECSER, $r->IYECCOD));
        $providers = $db->table('SINPRO')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    if ((string) $row['organizador_codigo'] !== '') {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['organizador_delegacion'])->where('PRO1COD', (string) $row['organizador_codigo']));
                    }
                }
                $q->orWhereRaw('1 = 0');
            })
            ->get(['DEL3COD', 'PRO1COD', 'PROCNOM'])->keyBy(fn ($p) => $p->DEL3COD."\x1B".$p->PRO1COD);
        $today = (string) $db->selectOne('SELECT CURDATE() AS d')->d;

        foreach ($rows as &$row) {
            $key = $k($row['delegacion'], $row['serie'], $row['codigo']);
            $result = $results[$key] ?? null;
            $state = (string) ($row['estado'] ?? '');
            $row['organizador_nombre'] = $providers[$row['organizador_delegacion']."\x1B".$row['organizador_codigo']]->PROCNOM ?? null;
            $row['muestras'] = (int) ($samples[$key]->total ?? 0);
            $row['resultados'] = (int) ($result->total ?? 0);
            $row['peor_evaluacion'] = (int) ($result->peor ?? 0);
            $row['fuera_de_plazo'] = ($state === '' || $state === 'P') && ! empty($row['fecha_limite'])
                && substr((string) $row['fecha_limite'], 0, 10) < $today ? 'T' : 'F';
            $row['incidencias'] = (int) ($incidents[$key]->total ?? 0);
            $row['proponer_incidencia'] = (int) ($result->malos ?? 0) > 0 && $row['incidencias'] === 0 ? 'T' : 'F';
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Borrado (EnsayosAptitud.Borrar + EAP_Borrable + EAP_Borrar)
    // ------------------------------------------------------------------

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
        $round = VeolabProficiency::round((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'], true);
        if ($round && (string) $round->EAPCEST === 'C') {
            throw new BusinessRuleException('Un ensayo de aptitud cerrado es el registro de la participación (ISO/IEC 17025 7.7.2): no se elimina');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        VeolabProficiency::deleteRoundData((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']);
    }

    // ------------------------------------------------------------------
    // Incidencia de la ronda (botón «Nueva incidencia» de la pestaña Incidencias)
    // ------------------------------------------------------------------

    /**
     * POST /ensayos-aptitud/incidencia?delegacion=&serie=&codigo=
     * Cuerpo {incidencia_serie, incidencia_detectada_por_delegacion,
     * incidencia_detectada_por_codigo, incidencia_usuario_delegacion,
     * incidencia_usuario_codigo}: registra una incidencia de trabajo no
     * conforme, con origen ensayo de aptitud, enlazada a la ronda y a sus
     * operaciones (NuevaIncidencia). Responde 201 con su clave.
     */
    public function openIncident(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        foreach (array_keys($this->keys) as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }
        [$del, $ser, $cod] = [(string) ($request->query('delegacion') ?? ''), (string) ($request->query('serie') ?? ''),
            (int) $request->query('codigo')];

        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, VeolabProficiency::incidentRules());
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            $round = VeolabProficiency::round($del, $ser, $cod, true);
            if (! $round) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $incident = VeolabProficiency::openIncident($round, $validator->validated());

            $db->commit();

            return response()->json(['message' => 'Incidencia registrada correctamente', 'data' => $incident], 201);
        } catch (ValidationException $e) {
            $db->rollBack();

            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 incidencia {$this->table}", $e, 'Error al registrar la incidencia');
        }
    }
}
