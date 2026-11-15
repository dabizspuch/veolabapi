<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabQuality;
use App\Support\VeolabFormat;
use App\Support\VeolabQualityIndicators;
use App\Support\VeolabVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Objetivos de calidad (CALOBJ, módulo CAL, desde Veolab 3; ISO/IEC 17025
 * 8.2.1 y 8.9.2 b; funcionalidad CAL_OBJ en Veolab). Sigue el modelo común
 * con el escritorio (App\Support\VeolabQualityIndicators).
 *
 *  - Clave delegación ('' = todo el laboratorio) + código; el código no se
 *    indica al crear: es el siguiente al mayor de todas las delegaciones.
 *  - Objetivo anual con su indicador, meta, responsable y plan. Al crear,
 *    abierto (A). Solo los abiertos se modifican y se eliminan.
 *  - Lectura: valor (el congelado si está cerrado; si no, el calculado del 1
 *    de enero a hoy, o al 31 de diciembre si el año ya pasó), cumple y
 *    semáforo.
 *  - POST /objetivos-calidad/cerrar, /reabrir, /duplicar y /mejora, y GET
 *    /objetivos-calidad/seguimiento.
 *  - Solo el responsable de calidad (especial 1 de CAL_OBJ) escribe: con
 *    ?usuario_delegacion=&usuario_codigo= se comprueba; sin él, la API actúa
 *    como responsable de calidad.
 */
class ObjetivoCalidadController extends BaseController
{
    protected string $table = 'CALOBJ';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'OBJ1COD',
    ];

    protected array $mapping = [
        'delegacion'             => 'DEL3COD',
        'codigo'                 => 'OBJ1COD',
        'anio'                   => 'OBJNANI',
        'descripcion'            => 'OBJCDES',
        'indicador_delegacion'   => 'KPI2DEL',
        'indicador_codigo'       => 'KPI2COD',
        'meta'                   => 'OBJNMET',
        'responsable_delegacion' => 'EMR2DEL',   // Empleado
        'responsable_codigo'     => 'EMR2COD',
        'plan'                   => 'OBJCPLA',
        'estado'                 => 'OBJCEST',   // A abierto, C cerrado
        'valor_alcanzado'        => 'OBJNVAL',   // Al cerrar
        'resultado'              => 'OBJCRES',   // S cumplido, P en parte, N no cumplido
        'conclusion'             => 'OBJCCON',
        'fecha_cierre'           => 'OBJDCIE',
    ];

    protected array $searchFields = ['OBJCDES', 'OBJCPLA', 'OBJCCON'];
    protected ?string $auditDescription = 'OBJCDES';

    // Los objetivos de un año caben en una página.
    protected int $defaultPerPage = 100;
    protected int $maxPerPage = 500;

    /** Los pone /cerrar y /reabrir (se aceptan en PUT si no cambian). */
    private const CLOSING_FIELDS = ['estado', 'valor_alcanzado', 'resultado', 'fecha_cierre'];

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
            'delegacion'             => 'nullable|string|max:10',
            'codigo'                 => 'nullable|integer|min:1',
            'anio'                   => 'nullable|integer|min:1900|max:9999',
            'descripcion'            => 'nullable|string|max:255',
            'indicador_delegacion'   => 'nullable|string|max:10',
            'indicador_codigo'       => 'nullable|integer|min:0',
            'meta'                   => 'nullable|numeric',
            'responsable_delegacion' => 'nullable|string|max:10',
            'responsable_codigo'     => 'nullable|integer|min:0',
            'plan'                   => 'nullable|string',
            'estado'                 => 'nullable|string|in:'.implode(',', array_keys(VeolabQualityIndicators::STATE_NAMES)),
            'valor_alcanzado'        => 'nullable|numeric',
            'resultado'              => 'nullable|string|in:'.implode(',', array_keys(VeolabQualityIndicators::RESULT_NAMES)),
            'conclusion'             => 'nullable|string',
            'fecha_cierre'           => 'nullable',
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
        $before = $isNew ? [] : (array) VeolabQualityIndicators::objective((string) $keys['delegacion'], (int) $keys['codigo'], true);
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        // Cerrado: solo lectura (para cambiarlo hay que reabrirlo)
        if (! $isNew && VeolabQualityIndicators::state((object) $before) === 'C') {
            foreach ($data as $param => $value) {
                if (isset($this->keys[$param]) || ! isset($this->mapping[$param])) {
                    continue;
                }
                if (! self::sameValue($value, $before[$this->mapping[$param]] ?? null)) {
                    throw new BusinessRuleException('El objetivo está cerrado y solo se puede consultar; para cambiarlo hay que reabrirlo');
                }
                unset($data[$param]);
            }

            return $data;
        }

        // Estado y cierre: los ponen /cerrar y /reabrir
        foreach (self::CLOSING_FIELDS as $param) {
            if (array_key_exists($param, $data)) {
                $stored = $isNew ? ($param === 'estado' ? 'A' : null) : ($before[$this->mapping[$param]] ?? null);
                if ($param === 'estado' && ! $isNew && (string) $stored === '') {
                    $stored = 'A';
                }
                if (! self::sameValue($data[$param], $stored)) {
                    throw new BusinessRuleException("El campo '{$param}' lo ponen /objetivos-calidad/cerrar y /objetivos-calidad/reabrir");
                }
                unset($data[$param]);
            }
        }

        if ($isNew) {
            if (! empty($data['codigo'])) {
                throw new BusinessRuleException('El código se asigna automáticamente y no se puede indicar');
            }
            $data['delegacion'] = trim((string) ($data['delegacion'] ?? ''));
            if ($data['delegacion'] !== '' && ! $db->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
                throw new BusinessRuleException("La delegación {$data['delegacion']} no existe");
            }
            $data['codigo'] = VeolabQualityIndicators::nextCode($this->table, 'OBJ1COD');
            $data['estado'] = 'A';
            if (empty($data['anio'])) {
                $data['anio'] = (int) substr(VeolabQualityIndicators::today(), 0, 4);
            }
        } elseif (array_key_exists('anio', $data) && empty($data['anio'])) {
            throw new BusinessRuleException('El año del objetivo es obligatorio');
        }

        // Indicador: obligatorio; existe y, si cambia, no de baja
        $kpiDel = trim((string) $current('indicador_delegacion'));
        $kpiCod = (int) $current('indicador_codigo');
        if ($kpiCod === 0) {
            throw new BusinessRuleException('El indicador del objetivo es obligatorio');
        }
        $kpi = VeolabQualityIndicators::indicator($kpiDel, $kpiCod);
        if (! $kpi) {
            throw new BusinessRuleException('El indicador no existe');
        }
        $kpiChanged = $isNew || (string) ($before['KPI2DEL'] ?? '') !== $kpiDel || (int) ($before['KPI2COD'] ?? 0) !== $kpiCod;
        if ($kpiChanged && $kpi->KPIBBAJ === 'T') {
            throw new BusinessRuleException('El indicador está de baja');
        }
        // Como el desplegable de FichaObjetivo: indicadores comunes o de la delegación del objetivo
        $objectiveDel = $isNew ? (string) $data['delegacion'] : (string) $keys['delegacion'];
        if ($kpiChanged && $kpiDel !== '' && $kpiDel !== $objectiveDel) {
            throw new BusinessRuleException('El indicador es de otra delegación: solo valen los comunes y los de la delegación del objetivo');
        }
        if (array_key_exists('indicador_codigo', $data) || array_key_exists('indicador_delegacion', $data)) {
            $data['indicador_delegacion'] = $kpiDel;
            $data['indicador_codigo'] = $kpiCod;
        }

        // Meta obligatoria
        if ($current('meta') === null || $current('meta') === '') {
            throw new BusinessRuleException('La meta del objetivo es obligatoria');
        }
        if (array_key_exists('meta', $data)) {
            $data['meta'] = (float) $data['meta'];
        }

        // Textos recortados; vacíos, NULL. Sin descripción, la del indicador.
        foreach (['descripcion', 'plan', 'conclusion'] as $param) {
            if (array_key_exists($param, $data)) {
                $text = trim((string) ($data[$param] ?? ''));
                $data[$param] = $text === '' ? null : $text;
            }
        }
        if (trim((string) $current('descripcion')) === '') {
            $data['descripcion'] = mb_substr(trim((string) $kpi->KPICDES), 0, 255);
        }

        // Responsable: un empleado; si cambia, de alta
        if (array_key_exists('responsable_codigo', $data) || array_key_exists('responsable_delegacion', $data)) {
            $empDel = (string) $current('responsable_delegacion');
            $empCod = (int) $current('responsable_codigo');
            if ($empCod === 0) {
                $data['responsable_delegacion'] = null;
                $data['responsable_codigo'] = null;
            } else {
                $employee = $db->table('GRHEMP')->where('DEL3COD', $empDel)->where('EMP1COD', $empCod)->first(['EMPBBAJ']);
                if (! $employee) {
                    throw new BusinessRuleException('El responsable (empleado) no existe');
                }
                $changed = $isNew || (string) ($before['EMR2DEL'] ?? '') !== $empDel || (int) ($before['EMR2COD'] ?? 0) !== $empCod;
                if ($changed && $employee->EMPBBAJ === 'T') {
                    throw new BusinessRuleException('El responsable (empleado) está de baja');
                }
                $data['responsable_delegacion'] = $empDel;
                $data['responsable_codigo'] = $empCod;
            }
        }

        return $data;
    }

    /** Valor recibido igual al guardado (vacíos iguales; fechas y números por su valor). */
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

        return is_numeric($given) && is_numeric($stored) && (float) $given === (float) $stored;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /**
     * Además de los campos (anio, delegacion, estado...):
     *  - ?para_delegacion=: los de todo el laboratorio ('') y los de esa delegación.
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        if ($request->has('para_delegacion')) {
            $query->whereIn('CALOBJ.DEL3COD', array_values(array_unique(['', (string) ($request->query('para_delegacion') ?? '')])));
        }
    }

    /**
     * Código formateado, textos de estado y resultado, indicador (descripción,
     * unidad, sentido, automático), responsable, valor, cumple, semáforo y
     * cuántas mejoras tiene enlazadas.
     */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $employees = $db->table('GRHEMP')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    if ((int) $row['responsable_codigo'] !== 0) {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['responsable_delegacion'])->where('EMP1COD', (int) $row['responsable_codigo']));
                    }
                }
                $q->orWhereRaw('1 = 0');
            })
            ->get(['DEL3COD', 'EMP1COD', 'EMPCNOM'])->keyBy(fn ($e) => $e->DEL3COD."\x1B".(int) $e->EMP1COD);
        $improvements = $db->table('CALIYE')->where('IYECTAB', $this->table)
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('IYECDEL', (string) $row['delegacion'])->where('IYECCOD', (string) (int) $row['codigo']));
                }
            })
            ->groupBy('IYECDEL', 'IYECCOD')->get(['IYECDEL', 'IYECCOD', DB::raw('COUNT(*) AS NINC')])
            ->keyBy(fn ($r) => $r->IYECDEL."\x1B".(int) $r->IYECCOD);

        foreach ($rows as &$row) {
            $objective = (object) array_combine(array_values($this->mapping), array_map(fn ($p) => $row[$p] ?? null, array_keys($this->mapping)));
            $status = VeolabQualityIndicators::objectiveStatus($objective);
            $kpi = $status['indicador'];
            $state = VeolabQualityIndicators::state($objective);
            $key = $row['delegacion']."\x1B".(int) $row['codigo'];

            $row['codigo_formateado'] = VeolabCodes::format($this->table, (string) $row['codigo'], (string) $row['delegacion']);
            $row['estado_texto'] = VeolabQualityIndicators::STATE_NAMES[$state] ?? $state;
            $row['resultado_texto'] = VeolabQualityIndicators::RESULT_NAMES[(string) $row['resultado']] ?? null;
            $row['indicador_descripcion'] = $kpi->KPICDES ?? null;
            $row['indicador_unidad'] = $kpi->KPICUNI ?? null;
            $row['indicador_sentido'] = $kpi->KPICSEN ?? null;
            $row['indicador_automatico'] = VeolabQualityIndicators::isAutomatic($kpi) ? 'T' : 'F';
            $row['indicador_decimales'] = VeolabQualityIndicators::decimals($kpi);
            $row['responsable_nombre'] = (int) $row['responsable_codigo'] !== 0
                ? ($employees[$row['responsable_delegacion']."\x1B".(int) $row['responsable_codigo']]->EMPCNOM ?? null) : null;
            $row['meta_texto'] = $status['meta_texto'];
            $row['valor'] = $status['valor'];
            $row['valor_texto'] = $status['valor_texto'];
            $row['cumple'] = $status['cumple'];
            $row['semaforo'] = $status['semaforo'];
            $row['mejoras'] = (int) ($improvements[$key]->NINC ?? 0);
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Borrado
    // ------------------------------------------------------------------

    /** Solo abiertos y sin mejoras enlazadas. */
    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
        VeolabQualityIndicators::assertRequestManager();
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];
        $objective = VeolabQualityIndicators::objective($del, $cod, true);
        if (! $objective) {
            return;
        }
        if (VeolabQualityIndicators::state($objective) === 'C') {
            throw new BusinessRuleException('El objetivo está cerrado y no se puede eliminar; para cambiarlo hay que reabrirlo');
        }
        // Como las revisiones por la dirección con decisiones (ObjetivosCalidad.Borrar, MEN01097)
        $improvements = DB::connection('dynamic')->table('CALIYE')->where('IYECTAB', VeolabQualityIndicators::OBJECTIVES)
            ->where('IYECDEL', $del)->where('IYECSER', '')->where('IYECCOD', (string) $cod)->count();
        if ($improvements > 0) {
            throw new BusinessRuleException('El objetivo tiene mejoras enlazadas y no se puede eliminar');
        }
    }

    /** ObjetivosCalidad.Borrar: por si quedara algún enlace (con mejoras enlazadas no se llega aquí). */
    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('CALIYE')->where('IYECTAB', VeolabQualityIndicators::OBJECTIVES)
            ->where('IYECDEL', (string) $keys['delegacion'])->where('IYECSER', '')->where('IYECCOD', (string) (int) $keys['codigo'])
            ->delete();
    }

    // ------------------------------------------------------------------
    // Acciones
    // ------------------------------------------------------------------

    /** Clave de la query string (delegacion, codigo) o la respuesta 400 si falta. */
    private function queryKeys(Request $request)
    {
        $code = $request->query('codigo');
        if (! is_string($code) || ! ctype_digit($code)) {
            return response()->json(['message' => "Falta la clave 'codigo'"], 400);
        }

        return ['delegacion' => (string) ($request->query('delegacion') ?? ''), 'codigo' => (int) $code];
    }

    /** Cambio de campos por una acción: suceso de fila y, con nivel 3, uno por campo que cambia. */
    private function auditFields(array $keys, object $before, array $fields): void
    {
        $row = $this->auditRow($keys);
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);
        if (! VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
            return;
        }
        foreach ($fields as $column => $new) {
            $new = VeolabAudit::value($new);
            $old = VeolabAudit::value($before->{$column} ?? null);
            if ($old !== $new) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, $this->table.$column, $new, $old);
            }
        }
    }

    /**
     * POST /objetivos-calidad/cerrar?delegacion=&codigo=[&usuario_*]
     * Cuerpo {valor (opcional: si no viene, el calculado del año), resultado
     * (S, P o N; obligatorio), conclusion}. Congela el valor y pone el estado
     * C y la fecha de cierre (hoy).
     */
    public function close(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $keys = $this->queryKeys($request);
        if ($keys instanceof JsonResponse) {
            return $keys;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'valor'      => 'nullable|numeric',
            'resultado'  => 'required|string|in:'.implode(',', array_keys(VeolabQualityIndicators::RESULT_NAMES)),
            'conclusion' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            VeolabQualityIndicators::assertRequestManager();
            $objective = VeolabQualityIndicators::objective($keys['delegacion'], $keys['codigo'], true);
            if (! $objective) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            if (VeolabQualityIndicators::state($objective) === 'C') {
                throw new BusinessRuleException('El objetivo ya está cerrado');
            }
            // CerrarObjetivo: valor numérico obligatorio; sin él, el calculado redondeado a los decimales del indicador.
            if (isset($body['valor']) && $body['valor'] !== '') {
                $value = (float) $body['valor'];
            } else {
                $kpi = VeolabQualityIndicators::indicator((string) $objective->KPI2DEL, (int) $objective->KPI2COD);
                $value = VeolabQualityIndicators::calculatedValue($objective, $kpi);
                if ($value === null) {
                    throw new BusinessRuleException('Indique el valor alcanzado (valor): el indicador no da un valor calculado para el año');
                }
                $value = VeolabFormat::round($value, max(0, (int) ($kpi->KPINDEC ?? 0)));
            }
            $conclusion = trim((string) ($body['conclusion'] ?? ''));
            $fields = [
                'OBJCEST' => 'C',
                'OBJNVAL' => $value,
                'OBJCRES' => $body['resultado'],
                'OBJCCON' => $conclusion === '' ? $objective->OBJCCON : $conclusion,
                'OBJDCIE' => VeolabQualityIndicators::today().' 00:00:00',
            ];
            $db->table($this->table)->where('DEL3COD', $keys['delegacion'])->where('OBJ1COD', $keys['codigo'])->update($fields);
            $this->auditFields($keys, $objective, $fields);

            $db->commit();

            return response()->json([
                'message' => 'Objetivo cerrado correctamente',
                'data'    => $keys + ['valor_alcanzado' => $value, 'resultado' => $body['resultado'], 'fecha_cierre' => $fields['OBJDCIE']],
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 cerrar {$this->table}", $e, 'Error al cerrar el objetivo');
        }
    }

    /**
     * POST /objetivos-calidad/reabrir?delegacion=&codigo=[&usuario_*]
     * Vuelve a abierto y quita la fecha de cierre; el valor alcanzado, el
     * resultado y la conclusión del cierre anterior se conservan.
     */
    public function reopen(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $keys = $this->queryKeys($request);
        if ($keys instanceof JsonResponse) {
            return $keys;
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            VeolabQualityIndicators::assertRequestManager();
            $objective = VeolabQualityIndicators::objective($keys['delegacion'], $keys['codigo'], true);
            if (! $objective) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            if (VeolabQualityIndicators::state($objective) !== 'C') {
                throw new BusinessRuleException('El objetivo no está cerrado');
            }
            // ReabrirObjetivo: el valor, el resultado y la conclusión del cierre anterior se conservan para proponerlos al volver a cerrar.
            $fields = ['OBJCEST' => 'A', 'OBJDCIE' => null];
            $db->table($this->table)->where('DEL3COD', $keys['delegacion'])->where('OBJ1COD', $keys['codigo'])->update($fields);
            $this->auditFields($keys, $objective, $fields);

            $db->commit();

            return response()->json(['message' => 'Objetivo reabierto correctamente', 'data' => $keys]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 reabrir {$this->table}", $e, 'Error al reabrir el objetivo');
        }
    }

    /**
     * POST /objetivos-calidad/duplicar?anio=[&delegacion=][&usuario_*]
     * Copia los objetivos del año (de la delegación, si se indica) al
     * siguiente, abiertos y con las mismas metas, indicador, responsable y
     * plan. No copia los de un indicador de baja ni los que ya existen en el
     * año siguiente (misma delegación, indicador y descripción).
     */
    public function duplicate(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $year = $request->query('anio');
        if (! is_string($year) || ! ctype_digit($year)) {
            return response()->json(['message' => "Falta el parámetro 'anio'"], 400);
        }
        $year = (int) $year;

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            VeolabQualityIndicators::assertRequestManager();
            $query = $db->table($this->table)->where('OBJNANI', $year)->orderBy('DEL3COD')->orderBy('OBJ1COD');
            if ($request->has('delegacion')) {
                $query->where('DEL3COD', (string) ($request->query('delegacion') ?? ''));
            }
            $sources = $query->get();
            $existing = $db->table($this->table)->where('OBJNANI', $year + 1)->get(['DEL3COD', 'KPI2DEL', 'KPI2COD', 'OBJCDES'])
                ->map(fn ($o) => $o->DEL3COD."\x1B".$o->KPI2DEL."\x1B".(int) $o->KPI2COD."\x1B".trim((string) $o->OBJCDES))->all();

            $created = [];
            $skipped = [];
            foreach ($sources as $o) {
                $kpi = VeolabQualityIndicators::indicator((string) $o->KPI2DEL, (int) $o->KPI2COD);
                $signature = $o->DEL3COD."\x1B".$o->KPI2DEL."\x1B".(int) $o->KPI2COD."\x1B".trim((string) $o->OBJCDES);
                if (! $kpi || $kpi->KPIBBAJ === 'T' || in_array($signature, $existing, true)) {
                    $skipped[] = ['delegacion' => (string) $o->DEL3COD, 'codigo' => (int) $o->OBJ1COD,
                        'motivo' => ! $kpi || $kpi->KPIBBAJ === 'T' ? 'El indicador no existe o está de baja' : 'Ya existe en '.($year + 1)];
                    continue;
                }
                $code = VeolabQualityIndicators::nextCode($this->table, 'OBJ1COD');
                $db->table($this->table)->insert([
                    'DEL3COD' => (string) $o->DEL3COD,
                    'OBJ1COD' => $code,
                    'OBJNANI' => $year + 1,
                    'OBJCDES' => $o->OBJCDES,
                    'KPI2DEL' => $o->KPI2DEL,
                    'KPI2COD' => $o->KPI2COD,
                    'OBJNMET' => $o->OBJNMET,
                    'EMR2DEL' => $o->EMR2DEL,
                    'EMR2COD' => $o->EMR2COD,
                    'OBJCPLA' => $o->OBJCPLA,
                    'OBJCEST' => 'A',
                    'OBJNVAL' => null,
                    'OBJCRES' => null,
                    'OBJCCON' => null,
                    'OBJDCIE' => null,
                ]);
                $keys = ['delegacion' => (string) $o->DEL3COD, 'codigo' => $code];
                VeolabAudit::record(VeolabAudit::INSERCION, $this->table, $this->auditRow($keys, (string) $o->OBJCDES));
                $existing[] = $signature;
                $created[] = $keys;
            }

            $db->commit();

            return response()->json([
                'message' => count($created).' objetivos copiados a '.($year + 1),
                'data'    => ['anio' => $year + 1, 'creados' => $created, 'omitidos' => $skipped],
            ], $created ? 201 : 200);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 duplicar {$this->table}", $e, 'Error al duplicar los objetivos');
        }
    }

    /**
     * GET /objetivos-calidad/seguimiento?delegacion=&codigo=
     * Valor por trimestre (T1-T4) y del año (A), con cumple y semáforo, más
     * el valor congelado si está cerrado. Solo lectura.
     */
    public function tracking(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $keys = $this->queryKeys($request);
        if ($keys instanceof JsonResponse) {
            return $keys;
        }
        try {
            $objective = VeolabQualityIndicators::objective($keys['delegacion'], $keys['codigo']);
            if (! $objective) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $status = VeolabQualityIndicators::objectiveStatus($objective);
            $closed = VeolabQualityIndicators::state($objective) === 'C';

            return response()->json([
                'data' => $keys + [
                    'anio'            => (int) $objective->OBJNANI,
                    'meta'            => VeolabQualityIndicators::number($objective->OBJNMET),
                    'meta_texto'      => $status['meta_texto'],
                    'estado'          => VeolabQualityIndicators::state($objective),
                    'valor_alcanzado' => $closed ? VeolabQualityIndicators::number($objective->OBJNVAL) : null,
                    'periodos'        => VeolabQualityIndicators::tracking($objective),
                ],
                'meta' => ['version_bd' => VeolabVersion::current()],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response("v2 seguimiento {$this->table}", $e, 'Error al calcular el seguimiento del objetivo');
        }
    }

    /**
     * POST /objetivos-calidad/mejora?delegacion=&codigo=[&usuario_*]
     * Cuerpo opcional {delegacion (de la incidencia; por defecto la del
     * objetivo), serie, descripcion (por defecto «Mejora del objetivo: …», ETI01510),
     * detalle, detectada_por_* (por defecto el responsable del objetivo),
     * usuario_*}. Crea la incidencia de mejora (clase M, origen D) con las
     * reglas de POST /incidencias y la enlaza al objetivo (CALIYE tipo CALOBJ,
     * serie '').
     */
    public function createImprovement(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $keys = $this->queryKeys($request);
        if ($keys instanceof JsonResponse) {
            return $keys;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'delegacion'               => 'nullable|string|max:10',
            'serie'                    => 'nullable|string|max:10',
            'descripcion'              => 'nullable|string|max:255',
            'detalle'                  => 'nullable|string',
            'detectada_por_delegacion' => 'nullable|string|max:10',
            'detectada_por_codigo'     => 'nullable|integer|min:0',
            'usuario_delegacion'       => 'nullable|string|max:10',
            'usuario_codigo'           => 'nullable|string|max:15',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            VeolabQualityIndicators::assertRequestManager();
            $objective = VeolabQualityIndicators::objective($keys['delegacion'], $keys['codigo'], true);
            if (! $objective) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            $status = VeolabQualityIndicators::objectiveStatus($objective);
            $title = trim((string) ($body['descripcion'] ?? ''));
            if ($title === '') {
                $title = mb_substr('Mejora del objetivo: '.trim((string) $objective->OBJCDES), 0, 255);      // ETI01510
            }
            $detail = trim((string) ($body['detalle'] ?? ''));
            if ($detail === '') {
                // FichaObjetivo.NuevaIncidencia (ETI01513): el valor, solo el congelado de un objetivo cerrado
                $kpi = $status['indicador'];
                $closedValue = VeolabQualityIndicators::state($objective) === 'C' ? VeolabQualityIndicators::number($objective->OBJNVAL) : null;
                $detail = 'Indicador: '.trim((string) ($kpi->KPICDES ?? '')).'. Meta: '.($status['meta_texto'] ?? '-').'. Valor alcanzado: '
                    .($closedValue === null ? '-' : VeolabQualityIndicators::formatValue($closedValue, VeolabQualityIndicators::decimals($kpi), (string) ($kpi->KPICUNI ?? ''))).'.';
            }
            $detector = array_key_exists('detectada_por_codigo', $body)
                ? [(string) ($body['detectada_por_delegacion'] ?? ''), (int) ($body['detectada_por_codigo'] ?? 0)]
                : [(string) ($objective->EMR2DEL ?? ''), (int) ($objective->EMR2COD ?? 0)];
            $incDel = array_key_exists('delegacion', $body) ? trim((string) ($body['delegacion'] ?? '')) : $keys['delegacion'];
            $incident = [
                'delegacion'               => $incDel,
                'serie'                    => (string) ($body['serie'] ?? ''),
                'clase'                    => VeolabQualityIndicators::IMPROVEMENT_CLASS,
                'origen'                   => VeolabQualityIndicators::IMPROVEMENT_ORIGIN,
                'descripcion'              => $title,
                'detalle'                  => $detail,
                'detectada_por_delegacion' => $detector[1] !== 0 ? $detector[0] : null,
                'detectada_por_codigo'     => $detector[1] !== 0 ? $detector[1] : null,
            ];
            if (! empty($body['usuario_codigo'])) {
                $incident['usuario_delegacion'] = (string) ($body['usuario_delegacion'] ?? '');
                $incident['usuario_codigo'] = (string) $body['usuario_codigo'];
            }
            $response = app(IncidenciaController::class)->createFromData($incident);
            if (! $response instanceof JsonResponse || $response->getStatusCode() !== 201) {
                $db->rollBack();

                return $response;
            }
            $created = $response->getData(true)['data'] ?? [];
            [$incSer, $incCod] = [(string) ($created['serie'] ?? ''), (int) ($created['codigo'] ?? 0)];

            // Enlace con el objetivo
            $db->table('CALIYE')->insert([
                'DEL3COD' => $incDel, 'INC3SER' => $incSer, 'INC3COD' => $incCod,
                'IYE1COD' => (int) $db->table('CALIYE')->where('DEL3COD', $incDel)->where('INC3SER', $incSer)->where('INC3COD', $incCod)->max('IYE1COD') + 1,
                'IYECTAB' => $this->table, 'IYECDEL' => $keys['delegacion'], 'IYECSER' => '', 'IYECCOD' => (string) $keys['codigo'],
            ]);

            $db->commit();

            return response()->json([
                'message' => 'Mejora creada correctamente',
                'data'    => [
                    'delegacion'        => $incDel,
                    'serie'             => $incSer,
                    'codigo'            => $incCod,
                    'codigo_formateado' => VeolabCodes::format('CALINC', (string) $incCod, $incDel, $incSer),
                    'objetivo'          => $keys,
                    'cumple'            => $status['cumple'],
                ],
            ], 201);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 mejora {$this->table}", $e, 'Error al crear la mejora');
        }
    }
}
