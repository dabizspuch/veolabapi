<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabProficiency;
use App\Support\VeolabProficiencyPlan;
use App\Support\VeolabQuality;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Resultados por parámetro de un ensayo de aptitud (CALEAR, desde Veolab 3),
 * como la rejilla Resultados de FichaEnsayoAptitud y el listado
 * ResultadosAptitud: lo enviado (parámetro, resultado, unidad, método), lo
 * que da el informe del organizador (valor asignado, desviación,
 * incertidumbres, tipo de puntuación, puntuación y evaluación) y lo que
 * calcula Veolab (ISO 13528).
 *
 *  - Clave: la ronda + codigo (número de línea, el siguiente al crear).
 *  - puntuacion_calculada, evaluacion y evaluacion_propuesta los calcula la
 *    API al grabar, con la fórmula y los límites de la ronda
 *    (EAP_EvaluarFila); si se envían, se ignoran.
 *  - La operación, si se indica, debe ser una muestra de la ronda; el
 *    parámetro, una técnica (al elegirla se toman su nombre y, si están
 *    vacíos, su unidad y su método, como ElegirParametro).
 *  - Nueva: la muestra y el tipo de puntuación de la última fila (o la
 *    primera muestra y z), como AnadirResultado.
 *  - POST /ensayos-aptitud/resultados/traer: «Traer resultados de las
 *    operaciones» (TraerResultados).
 *  - Con abrir_incidencia = T y algún insatisfactorio sin incidencias
 *    enlazadas, se abre la incidencia que la ficha propone.
 *  - Fase 2: aviso_tendencia de solo lectura (Q en una racha de cuestionables
 *    seguidos, S en una racha de puntuaciones del mismo signo, QS las dos;
 *    EAP_MarcarAvisos sobre todos los resultados de su parámetro) y filtro
 *    ?aviso_tendencia=T|Q|S. GET /ensayos-aptitud/tendencia: los puntos y las
 *    rachas de un parámetro para el gráfico (GraficoAptitud).
 */
class EnsayoAptitudResultadoController extends EnsayoAptitudChildController
{
    protected string $table = 'CALEAR';
    protected string $auditField = 'CALEAR';

    protected array $keys = [
        'ensayo_delegacion' => 'DEL3COD',
        'ensayo_serie'      => 'EAP3SER',
        'ensayo_codigo'     => 'EAP3COD',
        'codigo'            => 'EAR1COD',
    ];

    // Sin $foreignKeys: Veolab guarda NULL en las referencias vacías.
    protected array $mapping = [
        'ensayo_delegacion'         => 'DEL3COD',
        'ensayo_serie'              => 'EAP3SER',
        'ensayo_codigo'             => 'EAP3COD',
        'codigo'                    => 'EAR1COD',
        'operacion_delegacion'      => 'OPE2DEL',
        'operacion_serie'           => 'OPE2SER',
        'operacion_codigo'          => 'OPE2COD',
        'parametro_delegacion'      => 'TEC2DEL',
        'parametro_codigo'          => 'TEC2COD',
        'parametro_nombre'          => 'EARCPAR',
        'resultado'                 => 'EARCRES',   // Resultado enviado (texto, como los resultados)
        'unidad'                    => 'EARCUNI',
        'metodo'                    => 'EARCMET',
        'valor_asignado'            => 'EARCVAS',   // Texto: admite valores cualitativos
        'desviacion'                => 'EARNDES',   // σ; en D %, desviación admitida en %
        'incertidumbre_asignado'    => 'EARNINV',
        'incertidumbre_laboratorio' => 'EARNINL',
        'tipo_puntuacion'           => 'EARCTPU',   // Z z, P z', T zeta, E En, D D %, C cualitativo
        'sobre_log10'               => 'EARBLOG',
        'puntuacion_organizador'    => 'EARNPOR',
        'evaluacion_organizador'    => 'EARCEVO',   // S, Q, I, C, P (falso positivo), N (falso negativo)
        'puntuacion_calculada'      => 'EARNPCA',   // Solo lectura
        'evaluacion'                => 'EARCEVA',   // Solo lectura: la del organizador o la propuesta
        'evaluacion_propuesta'      => 'EARBPRO',   // Solo lectura
        'observaciones'             => 'EARCOBS',
    ];

    protected array $searchFields = ['EARCPAR', 'EARCRES', 'EARCOBS'];

    private const NUMBERS = ['desviacion', 'incertidumbre_asignado', 'incertidumbre_laboratorio', 'puntuacion_organizador'];

    /** Textos con su longitud (vacíos = NULL, TextoSQLONulo). */
    private const TEXTS = ['parametro_nombre' => 255, 'resultado' => 255, 'unidad' => 50, 'metodo' => 255, 'valor_asignado' => 50,
        'observaciones' => 255];

    private const CALCULATED = ['puntuacion_calculada', 'evaluacion', 'evaluacion_propuesta'];

    /** Resultados con aviso de tendencia (VeolabProficiencyPlan::resultsWithWarnings), una vez por petición. */
    private ?array $warnings = null;

    private function warnings(): array
    {
        return $this->warnings ??= VeolabProficiencyPlan::resultsWithWarnings();
    }

    protected function fieldRules(): array
    {
        $rules = [
            'codigo'                 => 'nullable|integer|min:1',
            'operacion_delegacion'   => 'nullable|string|max:10',
            'operacion_serie'        => 'nullable|string|max:10',
            'operacion_codigo'       => 'nullable|integer|min:0',
            'parametro_delegacion'   => 'nullable|string|max:10',
            'parametro_codigo'       => 'nullable|string|max:30',
            'tipo_puntuacion'        => 'nullable|string|in:'.implode(',', VeolabProficiency::TYPES),
            'sobre_log10'            => 'nullable|string|in:T,F',
            'evaluacion_organizador' => 'nullable|string|in:'.implode(',', VeolabProficiency::EVALUATIONS),
            'abrir_incidencia'       => 'nullable|string|in:T,F',
        ] + VeolabProficiency::incidentRules();
        foreach (self::TEXTS as $param => $max) {
            $rules[$param] = "nullable|string|max:{$max}";
        }
        foreach (self::NUMBERS as $param) {
            $rules[$param] = 'nullable|string|max:30';   // número (con coma o punto)
        }
        foreach (self::CALCULATED as $param) {
            $rules[$param] = 'nullable';
        }

        return $rules;
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);
        $round = $data['_ensayo'];
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = self::roundKey($keys ?: $data);
        $own = fn () => $db->table('CALEAR')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod);
        $isNew = ! $keys;
        $before = $isNew ? [] : (array) $own()->where('EAR1COD', (int) $keys['codigo'])->first();

        foreach (self::CALCULATED as $param) {
            unset($data[$param]);
        }

        if ($isNew) {
            if (empty($data['codigo'])) {
                $data['codigo'] = (int) $own()->max('EAR1COD') + 1;
            } elseif ($own()->where('EAR1COD', (int) $data['codigo'])->exists()) {
                throw new BusinessRuleException('Ya hay un resultado con ese número de línea en el ensayo de aptitud');
            }
            // AnadirResultado: la muestra y el tipo de la fila anterior (o la primera muestra y z)
            $last = $own()->orderByDesc('EAR1COD')->first(['OPE2DEL', 'OPE2SER', 'OPE2COD', 'EARCTPU', 'EARBLOG']);
            if (! array_key_exists('operacion_codigo', $data)) {
                if ($last) {
                    [$data['operacion_delegacion'], $data['operacion_serie'], $data['operacion_codigo']] = [$last->OPE2DEL, $last->OPE2SER, $last->OPE2COD];
                } elseif ($first = $db->table('CALEAO')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)
                    ->orderBy('EAONORD')->orderBy('OPE3COD')->first(['OPE3DEL', 'OPE3SER', 'OPE3COD'])) {
                    [$data['operacion_delegacion'], $data['operacion_serie'], $data['operacion_codigo']] = [$first->OPE3DEL, $first->OPE3SER, $first->OPE3COD];
                }
            }
            if (empty($data['tipo_puntuacion'])) {
                $data['tipo_puntuacion'] = $last && (string) $last->EARCTPU !== '' ? (string) $last->EARCTPU : 'Z';
                if (! array_key_exists('sobre_log10', $data)) {
                    $data['sobre_log10'] = $last && $last->EARBLOG === 'T' ? 'T' : 'F';
                }
            }
            if (! array_key_exists('sobre_log10', $data)) {
                $data['sobre_log10'] = 'F';
            }
        }
        $current = function (string $param) use (&$data, $before) {
            return array_key_exists($param, $data) ? $data[$param] : ($before[$this->mapping[$param]] ?? null);
        };

        // Muestra: una operación de la ronda (o ninguna)
        if (array_key_exists('operacion_codigo', $data) || array_key_exists('operacion_delegacion', $data) || array_key_exists('operacion_serie', $data)) {
            $opCod = (int) $current('operacion_codigo');
            if ($opCod === 0) {
                $data['operacion_delegacion'] = $data['operacion_serie'] = $data['operacion_codigo'] = null;
            } else {
                $opDel = (string) $current('operacion_delegacion');
                $opSer = (string) $current('operacion_serie');
                $isSample = $db->table('CALEAO')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)
                    ->where('OPE3DEL', $opDel)->where('OPE3SER', $opSer)->where('OPE3COD', $opCod)->exists();
                if (! $isSample) {
                    throw new BusinessRuleException('La operación no es una muestra del ensayo de aptitud (añádala en /ensayos-aptitud/muestras)');
                }
                [$data['operacion_delegacion'], $data['operacion_serie'], $data['operacion_codigo']] = [$opDel, $opSer, $opCod];
            }
        }

        // Textos: vacíos como NULL
        foreach (array_keys(self::TEXTS) as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = VeolabProficiency::textOrNull($data[$param], self::TEXTS[$param]);
            }
        }

        // Parámetro (técnica): al elegirlo, su nombre y, si están vacíos, su unidad y su método (ElegirParametro)
        if (array_key_exists('parametro_codigo', $data) || array_key_exists('parametro_delegacion', $data)) {
            $tecDel = (string) $current('parametro_delegacion');
            $tecCod = trim((string) $current('parametro_codigo'));
            if ($tecCod === '') {
                $data['parametro_delegacion'] = $data['parametro_codigo'] = null;
            } else {
                $technique = $db->table('LABTEC')->where('DEL3COD', $tecDel)->where('TEC1COD', $tecCod)->first(['TECCNOM', 'TECCUNI', 'TECCMET']);
                if (! $technique) {
                    throw new BusinessRuleException('El parámetro no existe');
                }
                $changed = $isNew || (string) ($before['TEC2DEL'] ?? '') !== $tecDel || (string) ($before['TEC2COD'] ?? '') !== $tecCod;
                if ($changed) {
                    if (! array_key_exists('parametro_nombre', $data) && trim((string) $technique->TECCNOM) !== '') {
                        $data['parametro_nombre'] = mb_substr((string) $technique->TECCNOM, 0, 255);
                    }
                    if ((string) $current('unidad') === '') {
                        $data['unidad'] = VeolabProficiency::textOrNull($technique->TECCUNI, 50);
                    }
                    if ((string) $current('metodo') === '') {
                        $data['metodo'] = VeolabProficiency::textOrNull($technique->TECCMET, 255);
                    }
                }
                $data['parametro_delegacion'] = $tecDel;
                $data['parametro_codigo'] = $tecCod;
            }
        }

        // Números con coma o punto (MEN00838)
        foreach (self::NUMBERS as $param) {
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

        // Tipo (siempre tiene uno: z por defecto), log10 y evaluación del organizador
        if (array_key_exists('tipo_puntuacion', $data) && empty($data['tipo_puntuacion'])) {
            $data['tipo_puntuacion'] = 'Z';
        }
        if (array_key_exists('sobre_log10', $data) && $data['sobre_log10'] !== 'T') {
            $data['sobre_log10'] = 'F';
        }
        if (array_key_exists('evaluacion_organizador', $data) && empty($data['evaluacion_organizador'])) {
            $data['evaluacion_organizador'] = null;
        }

        // Puntuación calculada y evaluación (EAP_EvaluarFila con los límites de la ronda)
        $values = [];
        foreach (array_keys(VeolabProficiency::rowValues([])) as $param) {
            $values[$param] = $current($param);
        }
        [$calc, $evaluation, $proposed] = VeolabProficiency::evaluateRow($values, VeolabProficiency::limits($round));
        $data['puntuacion_calculada'] = $calc;
        $data['evaluacion'] = $evaluation;
        $data['evaluacion_propuesta'] = $proposed ? 'T' : 'F';

        return $data;
    }

    /** Con abrir_incidencia, la incidencia que propone la ficha (insatisfactorios sin incidencias). */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if (($data['abrir_incidencia'] ?? 'F') === 'T') {
            [$del, $ser, $cod] = self::roundKey($keys);
            VeolabProficiency::openIncidentIfProposed($del, $ser, $cod, $data);
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Listado (ResultadosAptitud)
    // ------------------------------------------------------------------

    /**
     * Además de los campos (parametro_*, evaluacion, tipo_puntuacion...):
     *  - ?no_satisfactorias=T: cuestionables, insatisfactorias y falsos.
     *  - ?difiere=T: la puntuación calculada se aparta de la del organizador más
     *    de la tolerancia (0,1; en D %, 1 punto).
     *  - De la ronda: ?organizador_delegacion=&organizador_codigo=,
     *    ?programa= (contiene), ?ronda= (contiene, en la ronda o la
     *    descripción), ?anio=AAAA y ?estado_ensayo=P,E,V,C.
     *  - ?aviso_tendencia=T (con aviso de tendencia, la casilla del listado),
     *    Q (racha de cuestionables) o S (posible sesgo).
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $value = fn (string $param) => is_string($request->query($param)) && $request->query($param) !== '' ? $request->query($param) : null;

        if (($v = $value('aviso_tendencia')) !== null) {
            $v = strtoupper($v);
            if (! in_array($v, ['T', 'Q', 'S'], true)) {
                throw new BusinessRuleException('El filtro aviso_tendencia debe ser T, Q o S');
            }
            $keys = [];
            foreach ($this->warnings() as $key => $warning) {
                if ($v === 'T' || str_contains($warning, $v)) {
                    $keys[] = explode("\x1B", $key);
                }
            }
            // Las rachas se calculan en PHP: el filtro es la lista de claves, que crece con el historial. En vez de un OR
            // de cuatro condiciones por resultado se usa IN de filas, en bloques de 500 claves: los códigos (enteros) van
            // en el texto y solo la delegación y la serie como parámetros, para no acercarse al límite de 65.535
            // parámetros de MySQL ni alargar la consulta sin necesidad
            $query->where(function ($q) use ($keys) {
                foreach (array_chunk($keys, 500) as $block) {
                    $rows = [];
                    $bindings = [];
                    foreach ($block as [$del, $ser, $cod, $line]) {
                        $rows[] = '(?, ?, '.(int) $cod.', '.(int) $line.')';
                        $bindings[] = $del;
                        $bindings[] = $ser;
                    }
                    $q->orWhereRaw('(CALEAR.DEL3COD, CALEAR.EAP3SER, CALEAR.EAP3COD, CALEAR.EAR1COD) IN ('.implode(', ', $rows).')', $bindings);
                }
                $q->orWhereRaw('1 = 0');
            });
        }

        if (($v = $value('no_satisfactorias')) !== null && strtoupper($v) === 'T') {
            $query->whereIn('CALEAR.EARCEVA', VeolabProficiency::NOT_SATISFACTORY);
        }
        if (($v = $value('difiere')) !== null && strtoupper($v) === 'T') {
            $query->whereNotNull('CALEAR.EARNPOR')->whereNotNull('CALEAR.EARNPCA')
                ->whereRaw("ABS(CALEAR.EARNPOR - CALEAR.EARNPCA) > (CASE WHEN CALEAR.EARCTPU = 'D' THEN ? ELSE ? END)",
                    [VeolabProficiency::TOLERANCE_D, VeolabProficiency::TOLERANCE]);
        }

        $proCod = $value('organizador_codigo');
        $program = $value('programa');
        $round = $value('ronda');
        $year = VeolabProficiency::yearFilter($request->query('anio'));
        $state = $value('estado_ensayo');
        if ($proCod === null && $program === null && $round === null && $year === null && $state === null) {
            return;
        }
        $proDel = (string) ($request->query('organizador_delegacion') ?? '');
        $query->whereExists(function ($s) use ($proDel, $proCod, $program, $round, $year, $state) {
            $s->select(DB::raw(1))->from('CALEAP')->whereColumn('CALEAP.DEL3COD', 'CALEAR.DEL3COD')
                ->whereColumn('CALEAP.EAP1SER', 'CALEAR.EAP3SER')->whereColumn('CALEAP.EAP1COD', 'CALEAR.EAP3COD');
            if ($proCod !== null) {
                $s->where('CALEAP.PRO2DEL', $proDel)->where('CALEAP.PRO2COD', $proCod);
            }
            if ($program !== null) {
                $s->where('CALEAP.EAPCPRG', 'like', '%'.$program.'%');
            }
            if ($round !== null) {
                $s->where(fn ($q) => $q->where('CALEAP.EAPCRON', 'like', '%'.$round.'%')->orWhere('CALEAP.EAPCDES', 'like', '%'.$round.'%'));
            }
            if ($year !== null) {
                VeolabProficiency::applyYear($s, $year);
            }
            if ($state !== null) {
                $states = array_map('trim', explode(',', $state));
                in_array('P', $states, true)
                    ? $s->where(fn ($q) => $q->whereIn('CALEAP.EAPCEST', $states)->orWhereNull('CALEAP.EAPCEST')->orWhere('CALEAP.EAPCEST', ''))
                    : $s->whereIn('CALEAP.EAPCEST', $states);
            }
        });
    }

    /**
     * Datos de solo lectura: identificación de la muestra, si la puntuación
     * calculada no coincide con la del organizador (difiere) y los datos de
     * la ronda (organizador, programa, ronda, fecha de recepción y estado) y
     * el aviso de tendencia (Q, S o QS).
     */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $rounds = $db->table('CALEAP')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['ensayo_delegacion'])->where('EAP1SER', (string) $row['ensayo_serie'])
                        ->where('EAP1COD', (int) $row['ensayo_codigo']));
                }
            })
            ->get(['DEL3COD', 'EAP1SER', 'EAP1COD', 'PRO2DEL', 'PRO2COD', 'EAPCPRG', 'EAPCRON', 'EAPDREC', 'EAPCEST'])
            ->keyBy(fn ($r) => $r->DEL3COD."\x1B".$r->EAP1SER."\x1B".$r->EAP1COD);
        $samples = $db->table('CALEAO')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['ensayo_delegacion'])->where('EAP3SER', (string) $row['ensayo_serie'])
                        ->where('EAP3COD', (int) $row['ensayo_codigo']));
                }
            })
            ->get(['DEL3COD', 'EAP3SER', 'EAP3COD', 'OPE3DEL', 'OPE3SER', 'OPE3COD', 'EAOCMUE'])
            ->keyBy(fn ($m) => implode("\x1B", [$m->DEL3COD, $m->EAP3SER, $m->EAP3COD, $m->OPE3DEL, $m->OPE3SER, $m->OPE3COD]));

        foreach ($rows as &$row) {
            $roundKey = $row['ensayo_delegacion']."\x1B".$row['ensayo_serie']."\x1B".$row['ensayo_codigo'];
            $round = $rounds[$roundKey] ?? null;
            $sample = $row['operacion_codigo'] === null ? null
                : ($samples[$roundKey."\x1B".$row['operacion_delegacion']."\x1B".$row['operacion_serie']."\x1B".$row['operacion_codigo']] ?? null);
            $calc = VeolabProficiency::number($row['puntuacion_calculada']);
            $org = VeolabProficiency::number($row['puntuacion_organizador']);
            $tolerance = $row['tipo_puntuacion'] === 'D' ? VeolabProficiency::TOLERANCE_D : VeolabProficiency::TOLERANCE;

            $row['muestra'] = $sample->EAOCMUE ?? null;
            $row['difiere'] = $calc !== null && $org !== null && abs($calc - $org) > $tolerance + 0.000001 ? 'T' : 'F';
            $row['organizador_delegacion'] = $round->PRO2DEL ?? null;
            $row['organizador_codigo'] = $round->PRO2COD ?? null;
            $row['programa'] = $round->EAPCPRG ?? null;
            $row['ronda'] = $round->EAPCRON ?? null;
            $row['fecha_recepcion'] = $round->EAPDREC ?? null;
            $row['estado_ensayo'] = $round->EAPCEST ?? null;
            $row['aviso_tendencia'] = $this->warnings()[$roundKey."\x1B".$row['codigo']] ?? null;
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Tendencia de un parámetro (GraficoAptitud)
    // ------------------------------------------------------------------

    /**
     * GET /ensayos-aptitud/tendencia?parametro_delegacion=&parametro_codigo=
     * Los resultados del parámetro en todas las rondas, en el orden del
     * tiempo (EAP_LeerPuntos: puntuación del organizador o, si no la da, la
     * calculada; límites de cada ronda), con el aviso de cada uno y las
     * rachas de aviso (EAP_MarcarAvisos: desde y hasta son posiciones en
     * puntos). Solo lectura.
     */
    public function trend(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $tecCod = $request->query('parametro_codigo');
        $tecDel = $request->query('parametro_delegacion') ?? '';
        if (! is_string($tecCod) || trim($tecCod) === '' || ! is_string($tecDel)) {
            return response()->json(['message' => 'Parámetros de consulta no válidos',
                'errors' => ['filtro' => ['Indique el parámetro (parametro_delegacion y parametro_codigo)']]], 422);
        }

        try {
            $points = VeolabProficiencyPlan::points($tecDel, $tecCod, null);
            $streaks = $points ? VeolabProficiencyPlan::markWarnings($points, 0, count($points) - 1) : [];
            $name = DB::connection('dynamic')->table('LABTEC')->where('DEL3COD', $tecDel)->where('TEC1COD', $tecCod)->value('TECCNOM');

            return response()->json([
                'data' => [
                    'parametro_delegacion' => $tecDel,
                    'parametro_codigo'     => $tecCod,
                    'parametro_nombre'     => $name,
                    'politica'             => [
                        'cuestionables_seguidos' => VeolabProficiencyPlan::STREAK_QUESTIONABLE,
                        'mismo_signo_seguidos'   => VeolabProficiencyPlan::STREAK_BIAS,
                    ],
                    'puntos'               => $points,
                    'rachas'               => $streaks,
                    'avisos'               => array_column($streaks, 'texto'),
                ],
                'meta' => ['version_bd' => \App\Support\VeolabVersion::current()],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response("v2 tendencia {$this->table}", $e, 'Error al leer la tendencia del parámetro');
        }
    }

    // ------------------------------------------------------------------
    // Traer resultados de las operaciones
    // ------------------------------------------------------------------

    /**
     * POST /ensayos-aptitud/resultados/traer. Cuerpo {ensayo_delegacion,
     * ensayo_serie, ensayo_codigo}: añade los parámetros de las muestras con
     * resultado (TraerResultados). Devuelve las filas añadidas.
     */
    public function bring(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'ensayo_delegacion' => 'nullable|string|max:10',
            'ensayo_serie'      => 'nullable|string|max:10',
            'ensayo_codigo'     => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }
        $key = self::roundKey($body);

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            $round = $this->lockRound($key);
            $added = VeolabProficiency::bringResults($round);
            if ($added) {
                VeolabProficiency::auditRound($key[0], $key[1], $key[2], $this->auditField);
            }

            $db->commit();

            return response()->json([
                'message' => $added ? 'Se han añadido '.count($added).' resultados de las operaciones. Revise el resultado enviado y escriba los datos del informe del organizador'
                    : 'Las operaciones de la ronda no tienen resultados que no estén ya en el ensayo de aptitud',
                'data'    => $added,
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 traer {$this->table}", $e, 'Error al traer los resultados de las operaciones');
        }
    }
}
