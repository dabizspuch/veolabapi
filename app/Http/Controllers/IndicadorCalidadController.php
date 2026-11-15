<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabFormat;
use App\Support\VeolabQuality;
use App\Support\VeolabQualityIndicators;
use App\Support\VeolabVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Indicadores de calidad (CALKPI, módulo CAL, desde Veolab 3; ISO/IEC
 * 17025 8.2.1 y 8.9.2 b; funcionalidad CAL_OBJ en Veolab). Sigue el modelo
 * común con el escritorio (App\Support\VeolabQualityIndicators).
 *
 *  - Clave delegación ('' = común) + código. El código no se indica al
 *    crear: es el siguiente al mayor de todas las delegaciones, y nunca menos
 *    de 19 (1 a 18 son los de Veolab).
 *  - Automáticos: los 18 que trae Veolab (calculo = INF_PLAZO...), solo
 *    admiten cambiar la baja y no se eliminan. La API crea solo manuales
 *    (calculo vacío), con su periodicidad (M, T, S, A).
 *  - No se elimina el que usa algún objetivo; al eliminarlo se borran sus
 *    valores manuales.
 *  - Valores manuales (CALKPV): GET, PUT y DELETE /indicadores-calidad/valores
 *    (values(), putValue(), deleteValue()).
 *  - GET /indicadores-calidad/valor: valor calculado en un periodo (value()).
 *  - Solo el responsable de calidad (especial 1 de CAL_OBJ) escribe: con
 *    ?usuario_delegacion=&usuario_codigo= se comprueba; sin él, la API actúa
 *    como responsable de calidad.
 */
class IndicadorCalidadController extends BaseController
{
    protected string $table = 'CALKPI';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'KPI1COD',
    ];

    protected array $mapping = [
        'delegacion'   => 'DEL3COD',
        'codigo'       => 'KPI1COD',
        'descripcion'  => 'KPICDES',
        'calculo'      => 'KPICCAL',   // INF_PLAZO... (automático de Veolab) o NULL (manual)
        'unidad'       => 'KPICUNI',
        'sentido'      => 'KPICSEN',   // S mejor si sube, B mejor si baja
        'periodicidad' => 'KPICPER',   // M, T, S, A (valores manuales)
        'decimales'    => 'KPINDEC',
        'definicion'   => 'KPICDEF',
        'baja'         => 'KPIBBAJ',
    ];

    protected array $searchFields = ['KPICDES', 'KPICDEF'];
    protected ?string $inactiveField = 'KPIBBAJ';
    protected ?string $auditDescription = 'KPICDES';

    // El catálogo cabe en una página.
    protected int $defaultPerPage = 100;
    protected int $maxPerPage = 500;

    /** Campos que se pueden cambiar en los indicadores de Veolab. */
    private const VEOLAB_EDITABLE = ['baja'];

    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabQualityIndicators::available()) {
            return response()->json(['message' => VeolabQualityIndicators::unavailableMessage()], 422);
        }

        return null;
    }

    protected function rules(): array
    {
        return [
            'delegacion'   => 'nullable|string|max:10',
            'codigo'       => 'nullable|integer|min:1',
            'descripcion'  => 'nullable|string|max:255',
            'calculo'      => 'nullable|string|max:20',
            'unidad'       => 'nullable|string|max:20',
            'sentido'      => 'nullable|string|in:'.implode(',', array_keys(VeolabQualityIndicators::SENSE_NAMES)),
            'periodicidad' => 'nullable|string|in:'.implode(',', array_keys(VeolabQualityIndicators::PERIOD_NAMES)),
            'decimales'    => 'nullable|integer|min:0|max:6',
            'definicion'   => 'nullable|string',
            'baja'         => 'nullable|string|in:T,F',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = ! $keys;
        VeolabQualityIndicators::assertRequestManager();
        $before = $isNew ? [] : (array) $db->table($this->table)->where('DEL3COD', (string) $keys['delegacion'])
            ->where('KPI1COD', (int) $keys['codigo'])->lockForUpdate()->first();
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        // Los de Veolab: solo la baja
        if (! $isNew && trim((string) ($before['KPICCAL'] ?? '')) !== '') {
            foreach ($data as $param => $value) {
                if (isset($this->keys[$param]) || in_array($param, self::VEOLAB_EDITABLE, true) || ! isset($this->mapping[$param])) {
                    continue;
                }
                if (! self::sameValue($value, $before[$this->mapping[$param]] ?? null)) {
                    throw new BusinessRuleException('Los indicadores que trae Veolab solo admiten cambiar la baja');
                }
                unset($data[$param]);
            }
            if (array_key_exists('baja', $data)) {
                $data['baja'] = ($data['baja'] ?? 'F') === 'T' ? 'T' : 'F';
            }
            VeolabQualityIndicators::forget();

            return $data;
        }

        // El cálculo automático es solo de los de Veolab
        if (array_key_exists('calculo', $data)) {
            if (trim((string) ($data['calculo'] ?? '')) !== '') {
                throw new BusinessRuleException('Los indicadores automáticos los trae Veolab; los del laboratorio son manuales (calculo vacío)');
            }
            $data['calculo'] = null;
        }

        if ($isNew) {
            if (! empty($data['codigo'])) {
                throw new BusinessRuleException('El código se asigna automáticamente y no se puede indicar');
            }
            $data['delegacion'] = trim((string) ($data['delegacion'] ?? ''));
            if ($data['delegacion'] !== '' && ! $db->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
                throw new BusinessRuleException("La delegación {$data['delegacion']} no existe");
            }
            $data['codigo'] = VeolabQualityIndicators::nextCode($this->table, 'KPI1COD', VeolabQualityIndicators::FIRST_OWN_CODE);
            $data['calculo'] = null;
        }

        // Textos recortados; vacíos, NULL. La descripción es obligatoria.
        foreach (['descripcion', 'unidad', 'definicion'] as $param) {
            if (array_key_exists($param, $data)) {
                $text = trim((string) ($data[$param] ?? ''));
                $data[$param] = $text === '' ? null : $text;
            }
        }
        if (trim((string) $current('descripcion')) === '') {
            throw new BusinessRuleException('La descripción del indicador es obligatoria');
        }

        // Sentido (por defecto, mejor si sube), periodicidad (anual), decimales y baja
        if ($isNew || array_key_exists('sentido', $data)) {
            $data['sentido'] = (string) ($data['sentido'] ?? '') === '' ? 'S' : $data['sentido'];
        }
        if ($isNew || array_key_exists('periodicidad', $data)) {
            $data['periodicidad'] = (string) ($data['periodicidad'] ?? '') === '' ? 'A' : $data['periodicidad'];
        }
        if ($isNew && ! array_key_exists('decimales', $data)) {
            $data['decimales'] = VeolabQualityIndicators::DEFAULT_DECIMALS;
        }
        if ($isNew || array_key_exists('baja', $data)) {
            $data['baja'] = ($data['baja'] ?? 'F') === 'T' ? 'T' : 'F';
        }
        VeolabQualityIndicators::forget();

        return $data;
    }

    /** Valor recibido igual al guardado (vacíos iguales; números por su valor). */
    private static function sameValue($given, $stored): bool
    {
        $given = $given === null ? '' : trim((string) $given);
        $stored = $stored === null ? '' : trim((string) $stored);
        if ($given === $stored) {
            return true;
        }

        return is_numeric($given) && is_numeric($stored) && (float) $given === (float) $stored;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /**
     *  - ?para_delegacion=: los comunes y los de esa delegación.
     *  - ?automatico=T|F: los de Veolab (con cálculo) o los manuales.
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        if ($request->has('para_delegacion')) {
            $query->whereIn('CALKPI.DEL3COD', array_values(array_unique(['', (string) ($request->query('para_delegacion') ?? '')])));
        }
        $automatic = $request->query('automatico');
        if (is_string($automatic) && $automatic !== '') {
            strtoupper($automatic) === 'T'
                ? $query->whereNotNull('CALKPI.KPICCAL')->where('CALKPI.KPICCAL', '<>', '')
                : $query->where(fn ($q) => $q->whereNull('CALKPI.KPICCAL')->orWhere('CALKPI.KPICCAL', ''));
        }
    }

    /** Código formateado, textos, si es de Veolab y cuántos objetivos y valores manuales tiene. */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $byIndicator = function ($query, string $delColumn, string $codColumn) use ($rows) {
            return $query->where(function ($q) use ($rows, $delColumn, $codColumn) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where($delColumn, (string) $row['delegacion'])->where($codColumn, (int) $row['codigo']));
                }
            });
        };
        $objectives = $byIndicator($db->table(VeolabQualityIndicators::OBJECTIVES), 'KPI2DEL', 'KPI2COD')
            ->groupBy('KPI2DEL', 'KPI2COD')->get(['KPI2DEL', 'KPI2COD', DB::raw('COUNT(*) AS NOBJ')])
            ->keyBy(fn ($r) => $r->KPI2DEL."\x1B".(int) $r->KPI2COD);
        $values = $byIndicator($db->table(VeolabQualityIndicators::VALUES), 'DEL3COD', 'KPI3COD')
            ->groupBy('DEL3COD', 'KPI3COD')->get(['DEL3COD', 'KPI3COD', DB::raw('COUNT(*) AS NKPV')])
            ->keyBy(fn ($r) => $r->DEL3COD."\x1B".(int) $r->KPI3COD);

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".(int) $row['codigo'];
            $automatic = trim((string) $row['calculo']) !== '';
            $row['codigo_formateado'] = VeolabCodes::format($this->table, (string) $row['codigo'], (string) $row['delegacion']);
            $row['automatico'] = $automatic ? 'T' : 'F';
            $row['sentido_texto'] = VeolabQualityIndicators::SENSE_NAMES[(string) $row['sentido']] ?? null;
            $row['periodicidad_texto'] = $automatic ? null : (VeolabQualityIndicators::PERIOD_NAMES[(string) $row['periodicidad']] ?? null);
            $row['objetivos'] = (int) ($objectives[$key]->NOBJ ?? 0);
            $row['valores'] = (int) ($values[$key]->NKPV ?? 0);
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
        VeolabQualityIndicators::assertRequestManager();
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];
        $kpi = DB::connection('dynamic')->table($this->table)->where('DEL3COD', $del)->where('KPI1COD', $cod)->first(['KPICCAL']);
        if ($kpi && trim((string) $kpi->KPICCAL) !== '') {
            throw new BusinessRuleException('Los indicadores que trae Veolab no se eliminan: se pueden dar de baja');
        }
        if (DB::connection('dynamic')->table(VeolabQualityIndicators::OBJECTIVES)->where('KPI2DEL', $del)->where('KPI2COD', $cod)->exists()) {
            throw new BusinessRuleException('El indicador lo usa algún objetivo y no se puede eliminar: désele de baja');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table(VeolabQualityIndicators::VALUES)->where('DEL3COD', (string) $keys['delegacion'])
            ->where('KPI3COD', (int) $keys['codigo'])->delete();
        VeolabQualityIndicators::forget();
    }

    // ------------------------------------------------------------------
    // Valores manuales (CALKPV)
    // ------------------------------------------------------------------

    /** Indicador de ?delegacion=&codigo= o la respuesta 400/404. */
    private function indicatorFromQuery(Request $request)
    {
        $code = $request->query('codigo');
        if (! is_string($code) || ! ctype_digit($code)) {
            return response()->json(['message' => "Falta la clave 'codigo'"], 400);
        }
        $kpi = VeolabQualityIndicators::indicator((string) ($request->query('delegacion') ?? ''), (int) $code);
        if (! $kpi) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        return $kpi;
    }

    /** Fecha aaaa-mm-dd de un parámetro (null si falta; BusinessRuleException si no es una fecha). */
    private static function dateParam($value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || strtotime(substr($value, 0, 10)) === false) {
            throw new BusinessRuleException("{$name} no es una fecha (aaaa-mm-dd)");
        }

        return (new \DateTime(substr($value, 0, 10)))->format('Y-m-d');
    }

    /** Valor manual como lo devuelve la API. */
    private static function valueRow(object $v, object $kpi): array
    {
        $value = VeolabQualityIndicators::number($v->KPVNVAL);

        return [
            'delegacion'    => (string) $v->DEL3COD,
            'codigo'        => (int) $v->KPI3COD,
            'fecha_inicio'  => $v->KPVDINI,
            'fecha_fin'     => $v->KPVDFIN,
            'valor'         => $value,
            'valor_texto'   => $value === null ? null : VeolabQualityIndicators::formatValue($value,
                VeolabQualityIndicators::decimals($kpi), (string) $kpi->KPICUNI),
            'observaciones' => $v->KPVCOBS,
        ];
    }

    /**
     * GET /indicadores-calidad/valores?delegacion=&codigo=[&desde=&hasta=]
     * Valores de un indicador manual por fecha de inicio (los de inicio de
     * desde a hasta, ambos incluidos, si se indican).
     */
    public function values(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $kpi = $this->indicatorFromQuery($request);
        if ($kpi instanceof JsonResponse) {
            return $kpi;
        }
        try {
            $from = self::dateParam($request->query('desde'), 'desde');
            $to = self::dateParam($request->query('hasta'), 'hasta');
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => ['filtro' => [$e->getMessage()]]], 422);
        }
        try {
            $query = DB::connection('dynamic')->table(VeolabQualityIndicators::VALUES)
                ->where('DEL3COD', (string) $kpi->DEL3COD)->where('KPI3COD', (int) $kpi->KPI1COD)->orderBy('KPVDINI');
            if ($from !== null) {
                $query->where('KPVDINI', '>=', $from.' 00:00:00');
            }
            if ($to !== null) {
                $query->where('KPVDINI', '<', (new \DateTime($to))->modify('+1 day')->format('Y-m-d').' 00:00:00');
            }
            $list = $query->get()->map(fn ($v) => self::valueRow($v, $kpi))->all();

            return response()->json([
                'data' => $list,
                'meta' => ['total' => count($list), 'version_bd' => VeolabVersion::current()],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response("v2 valores {$this->table}", $e, 'Error al leer los valores del indicador');
        }
    }

    /**
     * PUT /indicadores-calidad/valores?delegacion=&codigo=&fecha_inicio=
     * [&usuario_delegacion=&usuario_codigo=]. Cuerpo {valor (obligatorio),
     * fecha_fin, observaciones}. Crea o sustituye el valor del periodo que
     * empieza en fecha_inicio. Sin fecha_fin, el fin del periodo según la
     * periodicidad del indicador. Solo indicadores manuales.
     */
    public function putValue(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $kpi = $this->indicatorFromQuery($request);
        if ($kpi instanceof JsonResponse) {
            return $kpi;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'valor'         => 'required|numeric',
            'fecha_fin'     => 'nullable|date',
            'observaciones' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            VeolabQualityIndicators::assertRequestManager();
            $start = self::dateParam($request->query('fecha_inicio'), 'fecha_inicio');
            if ($start === null) {
                $db->rollBack();

                return response()->json(['message' => "Falta la clave 'fecha_inicio'"], 400);
            }
            if (VeolabQualityIndicators::isAutomatic($kpi)) {
                throw new BusinessRuleException('Los indicadores automáticos los calcula Veolab: no tienen valores manuales');
            }
            $months = VeolabQualityIndicators::PERIOD_MONTHS[(string) $kpi->KPICPER] ?? 12;
            $end = ! empty($body['fecha_fin']) ? (new \DateTime($body['fecha_fin']))->format('Y-m-d')
                // FichaIndicador: DateAdd("m", n, inicio) - 1
                : (new \DateTime(VeolabFormat::addMonths((new \DateTime($start))->format('Y-m-d'), $months)))->modify('-1 day')->format('Y-m-d');
            if ($end < $start) {
                throw new BusinessRuleException('El fin del periodo es anterior a su inicio');
            }
            $notes = trim((string) ($body['observaciones'] ?? ''));
            $fields = [
                'KPVDFIN' => $end.' 00:00:00',
                'KPVNVAL' => (float) $body['valor'],
                'KPVCOBS' => $notes === '' ? null : $notes,
            ];
            $own = fn () => $db->table(VeolabQualityIndicators::VALUES)->where('DEL3COD', (string) $kpi->DEL3COD)
                ->where('KPI3COD', (int) $kpi->KPI1COD)->where('KPVDINI', $start.' 00:00:00');
            $exists = $own()->lockForUpdate()->exists();
            if ($exists) {
                $own()->update($fields);
            } else {
                $db->table(VeolabQualityIndicators::VALUES)->insert(['DEL3COD' => (string) $kpi->DEL3COD,
                    'KPI3COD' => (int) $kpi->KPI1COD, 'KPVDINI' => $start.' 00:00:00'] + $fields);
            }
            $this->auditValues($kpi);

            $db->commit();

            return response()->json([
                'message' => $exists ? 'Valor actualizado correctamente' : 'Valor creado correctamente',
                'data'    => self::valueRow($own()->first(), $kpi),
            ], $exists ? 200 : 201);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 valor {$this->table}", $e, 'Error al grabar el valor del indicador');
        }
    }

    /** DELETE /indicadores-calidad/valores?delegacion=&codigo=&fecha_inicio=[&usuario_*]: quita un valor manual. */
    public function deleteValue(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $kpi = $this->indicatorFromQuery($request);
        if ($kpi instanceof JsonResponse) {
            return $kpi;
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            VeolabQualityIndicators::assertRequestManager();
            $start = self::dateParam($request->query('fecha_inicio'), 'fecha_inicio');
            if ($start === null) {
                $db->rollBack();

                return response()->json(['message' => "Falta la clave 'fecha_inicio'"], 400);
            }
            $deleted = $db->table(VeolabQualityIndicators::VALUES)->where('DEL3COD', (string) $kpi->DEL3COD)
                ->where('KPI3COD', (int) $kpi->KPI1COD)->where('KPVDINI', $start.' 00:00:00')->delete();
            if ($deleted === 0) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $this->auditValues($kpi);

            $db->commit();

            return response()->json(['message' => 'Valor eliminado correctamente']);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 borrar valor {$this->table}", $e, 'Error al eliminar el valor del indicador');
        }
    }

    /** Cambio de los valores: suceso de fila del indicador y, con nivel 3, campo CALKPV sin valores. */
    private function auditValues(object $kpi): void
    {
        $row = $this->auditRow(['delegacion' => (string) $kpi->DEL3COD, 'codigo' => (int) $kpi->KPI1COD], (string) $kpi->KPICDES);
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, VeolabQualityIndicators::VALUES);
    }

    // ------------------------------------------------------------------
    // Valor calculado
    // ------------------------------------------------------------------

    /**
     * GET /indicadores-calidad/valor?codigo=[&indicador_delegacion=]&desde=&hasta=&delegacion=
     * Valor del indicador de desde a hasta (ambos incluidos; por defecto, del
     * 1 de enero a hoy) con los datos de la delegación (vacía = todo el
     * laboratorio). El código del indicador es único entre delegaciones;
     * indicador_delegacion lo fija si hiciera falta.
     */
    public function value(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $code = $request->query('codigo');
        if (! is_string($code) || ! ctype_digit($code)) {
            return response()->json(['message' => "Falta la clave 'codigo'"], 400);
        }
        try {
            $from = self::dateParam($request->query('desde'), 'desde');
            $to = self::dateParam($request->query('hasta'), 'hasta');
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => ['filtro' => [$e->getMessage()]]], 422);
        }
        try {
            $query = DB::connection('dynamic')->table($this->table)->where('KPI1COD', (int) $code);
            if ($request->has('indicador_delegacion')) {
                $query->where('DEL3COD', (string) ($request->query('indicador_delegacion') ?? ''));
            }
            $kpi = $query->orderBy('DEL3COD')->first();
            if (! $kpi) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $today = VeolabQualityIndicators::today();
            $to = $to ?? $today;
            $from = $from ?? substr($to, 0, 4).'-01-01';
            if ($from > $to) {
                return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => ['filtro' => ['desde es posterior a hasta']]], 422);
            }
            $delegation = trim((string) ($request->query('delegacion') ?? ''));
            $value = VeolabQualityIndicators::value($kpi, $from, $to, $delegation);
            $decimals = VeolabQualityIndicators::decimals($kpi);

            return response()->json([
                'data' => [
                    'indicador_delegacion' => (string) $kpi->DEL3COD,
                    'indicador_codigo'     => (int) $kpi->KPI1COD,
                    'descripcion'          => $kpi->KPICDES,
                    'calculo'              => $kpi->KPICCAL,
                    'unidad'               => $kpi->KPICUNI,
                    'sentido'              => $kpi->KPICSEN,
                    'decimales'            => $decimals,
                    'desde'                => $from,
                    'hasta'                => $to,
                    'delegacion'           => $delegation,
                    'valor'                => $value,
                    'valor_texto'          => $value === null ? null : VeolabQualityIndicators::formatValue($value, $decimals, (string) $kpi->KPICUNI),
                ],
                'meta' => ['version_bd' => VeolabVersion::current()],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response("v2 valor calculado {$this->table}", $e, 'Error al calcular el valor del indicador');
        }
    }
}
