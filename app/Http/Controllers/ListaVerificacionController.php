<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabAuditChecklists;
use App\Support\VeolabCodes;
use App\Support\VeolabQuality;
use App\Support\VeolabQualityAudits;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Listas de verificación de auditorías (plantillas; CALLVE con sus
 * preguntas CALLVQ; módulo CAL, desde Veolab 3; ISO/IEC 17025 8.8). Sigue
 * el modelo común con el escritorio (App\Support\VeolabAuditChecklists).
 *
 *  - Ruta /auditorias-calidad/listas; clave delegación ('' = común) +
 *    código. El código es el siguiente al mayor de todas las delegaciones
 *    (CALAUP.LVE2COD guarda solo el código), y no se indica al crear.
 *  - Uso G auditoría (por defecto) o T ensayo presenciado. Solo una
 *    predeterminada por uso y delegación: al marcar una, se desmarcan las
 *    demás. Una lista de baja no queda como predeterminada.
 *  - 'preguntas' (embebida) sustituye todas las preguntas al escribir:
 *    líneas 1, 2... en el orden dado, con orden, apartado de la norma (de la
 *    lista fija o vacío), pregunta (obligatoria), qué revisar y referencia.
 *  - Solo se elimina si ninguna auditoría la ha usado (si no, se da de baja),
 *    y nunca las comunes 1 y 2 que trae Veolab.
 *  - POST /auditorias-calidad/listas/duplicar?delegacion=&codigo=: copia la
 *    lista con sus preguntas (duplicate()).
 *  - Filtro ?para_delegacion=: las comunes y las de esa delegación.
 */
class ListaVerificacionController extends BaseController
{
    protected string $table = 'CALLVE';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'LVE1COD',
    ];

    protected array $mapping = [
        'delegacion'     => 'DEL3COD',
        'codigo'         => 'LVE1COD',
        'descripcion'    => 'LVECDES',
        'uso'            => 'LVECTIP',   // G auditoría, T ensayo presenciado
        'predeterminada' => 'LVEBPRE',
        'baja'           => 'LVEBBAJ',
        'observaciones'  => 'LVECOBS',
    ];

    protected array $searchFields = ['LVECDES', 'LVECOBS'];
    protected ?string $inactiveField = 'LVEBBAJ';
    protected ?string $auditDescription = 'LVECDES';

    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabAuditChecklists::available()) {
            return response()->json(['message' => VeolabAuditChecklists::unavailableMessage()], 422);
        }

        return null;
    }

    protected function rules(): array
    {
        return [
            'delegacion'              => 'nullable|string|max:10',
            'codigo'                  => 'nullable|integer|min:1',
            'descripcion'             => 'nullable|string|max:255',
            'uso'                     => 'nullable|string|in:'.implode(',', array_keys(VeolabAuditChecklists::USE_NAMES)),
            'predeterminada'          => 'nullable|string|in:T,F',
            'baja'                    => 'nullable|string|in:T,F',
            'observaciones'           => 'nullable|string',
            'preguntas'               => 'nullable|array',
            'preguntas.*.orden'       => 'nullable|integer',
            'preguntas.*.apartado'    => 'nullable|string|max:10',
            'preguntas.*.pregunta'    => 'required|string',
            'preguntas.*.que_revisar' => 'nullable|string',
            'preguntas.*.referencia'  => 'nullable|string|max:100',
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
        $before = $isNew ? [] : (array) $db->table($this->table)->where('DEL3COD', (string) $keys['delegacion'])
            ->where('LVE1COD', (int) $keys['codigo'])->lockForUpdate()->first();
        // Valor resultante de un campo: el recibido (tal como va quedando en $data) o el guardado.
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        if ($isNew) {
            if (! empty($data['codigo'])) {
                throw new BusinessRuleException('El código se asigna automáticamente y no se puede indicar');
            }
            $data['delegacion'] = trim((string) ($data['delegacion'] ?? ''));
            if ($data['delegacion'] !== '' && ! $db->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
                throw new BusinessRuleException("La delegación {$data['delegacion']} no existe");
            }
            $data['codigo'] = VeolabAuditChecklists::newListCode();
        }

        // Las listas que trae Veolab tienen la descripción y las preguntas fijas (FichaListaVerificacion, booVeolab).
        if (! $isNew && VeolabAuditChecklists::isVeolabList((string) $keys['delegacion'], (int) $keys['codigo'])) {
            foreach (['descripcion', 'preguntas'] as $param) {
                if (array_key_exists($param, $data)) {
                    throw new BusinessRuleException("La lista es de Veolab: no se puede cambiar '{$param}'");
                }
            }
            // El uso tampoco (PuedeEditar): se admite si llega el mismo que está guardado.
            if (array_key_exists('uso', $data)) {
                $use = (string) ($data['uso'] ?? '') === '' ? 'G' : (string) $data['uso'];
                if ($use !== trim((string) ($before['LVECTIP'] ?? ''))) {
                    throw new BusinessRuleException("La lista es de Veolab: no se puede cambiar 'uso'");
                }
            }
        }

        // Textos recortados; vacíos, NULL. La descripción es obligatoria.
        foreach (['descripcion', 'observaciones'] as $param) {
            if (array_key_exists($param, $data)) {
                $text = trim((string) ($data[$param] ?? ''));
                $data[$param] = $text === '' ? null : $text;
            }
        }
        if (trim((string) $current('descripcion')) === '') {
            throw new BusinessRuleException('La descripción de la lista es obligatoria');
        }

        if ($isNew || array_key_exists('uso', $data)) {
            $data['uso'] = (string) ($data['uso'] ?? '') === '' ? 'G' : $data['uso'];
        }
        foreach (['predeterminada', 'baja'] as $param) {
            if ($isNew || array_key_exists($param, $data)) {
                $data[$param] = ($data[$param] ?? 'F') === 'T' ? 'T' : 'F';
            }
        }
        if ($current('baja') === 'T' && $current('predeterminada') === 'T') {
            $data['predeterminada'] = 'F';
        }

        // Preguntas
        if (array_key_exists('preguntas', $data)) {
            $questions = [];
            foreach ($data['preguntas'] ?? [] as $i => $item) {
                $questions[] = self::question($item, $i + 1);
            }
            $data['_preguntas'] = $questions;
            unset($data['preguntas']);
        }
        $data['_nueva'] = $isNew;

        return $data;
    }

    /** Pregunta normalizada (columnas de CALLVQ sin la clave); $position = orden por defecto. */
    private static function question(array $item, int $position): array
    {
        $text = trim((string) ($item['pregunta'] ?? ''));
        if ($text === '') {
            throw new BusinessRuleException("La pregunta {$position} está vacía");
        }
        $clause = trim((string) ($item['apartado'] ?? ''));
        if ($clause !== '' && ! VeolabQualityAudits::isClause($clause)) {
            throw new BusinessRuleException("El apartado '{$clause}' no es de la norma; use uno de: "
                .implode(', ', array_map('strval', array_keys(VeolabQualityAudits::CLAUSES))));
        }
        $guide = trim((string) ($item['que_revisar'] ?? ''));
        $reference = trim((string) ($item['referencia'] ?? ''));

        return [
            'LVQNORD' => isset($item['orden']) ? (int) $item['orden'] : $position,
            'LVQCAPA' => $clause === '' ? null : $clause,
            'LVQCPRE' => $text,
            'LVQCGUI' => $guide === '' ? null : $guide,
            'LVQCREF' => $reference === '' ? null : $reference,
        ];
    }

    /** Tras grabar: preguntas recibidas, una sola predeterminada por uso y delegación, y auditoría de las preguntas. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];

        if (array_key_exists('_preguntas', $data)) {
            self::writeQuestions($del, $cod, $data['_preguntas']);
        }

        $list = $db->table($this->table)->where('DEL3COD', $del)->where('LVE1COD', $cod)->first(['LVECTIP', 'LVEBPRE']);
        if ($list && $list->LVEBPRE === 'T') {
            $db->table($this->table)->where('DEL3COD', $del)->where('LVE1COD', '<>', $cod)
                ->where('LVECTIP', (string) $list->LVECTIP)->where('LVEBPRE', 'T')
                ->update(['LVEBPRE' => 'F']);
        }

        // Auditoría de las preguntas (en el alta basta la inserción)
        if (array_key_exists('_preguntas', $data) && empty($data['_nueva'])) {
            $row = $this->auditRow($keys);
            if (! array_intersect_key($data, array_diff_key($this->mapping, $this->keys))) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);   // solo cambian las preguntas
            }
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, VeolabAuditChecklists::QUESTIONS);
        }

        return $data;
    }

    /** Sustituye las preguntas de la lista (líneas 1, 2... en el orden dado). */
    private static function writeQuestions(string $del, int $cod, array $questions): void
    {
        $db = DB::connection('dynamic');
        $db->table(VeolabAuditChecklists::QUESTIONS)->where('DEL3COD', $del)->where('LVE3COD', $cod)->delete();
        foreach ($questions as $i => $question) {
            $db->table(VeolabAuditChecklists::QUESTIONS)->insert(['DEL3COD' => $del, 'LVE3COD' => $cod, 'LVQ1COD' => $i + 1] + $question);
        }
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /** ?para_delegacion=: las comunes y las de esa delegación (las que se pueden cargar en sus auditorías). */
    protected function applyCustomFilters(Request $request, $query): void
    {
        if ($request->has('para_delegacion')) {
            $query->whereIn('CALLVE.DEL3COD', array_values(array_unique(['', (string) ($request->query('para_delegacion') ?? '')])));
        }
    }

    /** Texto del uso, si es de Veolab, si alguna auditoría la ha usado y sus preguntas (en orden). */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $questions = $db->table(VeolabAuditChecklists::QUESTIONS)
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['delegacion'])->where('LVE3COD', (int) $row['codigo']));
                }
            })
            ->orderBy('LVQNORD')->orderBy('LVQ1COD')
            ->get()->groupBy(fn ($r) => $r->DEL3COD."\x1B".(int) $r->LVE3COD);
        $used = $db->table(VeolabAuditChecklists::LINES)
            ->whereIn('LVE2COD', array_map(fn ($r) => (int) $r['codigo'], $rows))
            ->distinct()->pluck('LVE2COD')->map(fn ($c) => (int) $c)->all();

        foreach ($rows as &$row) {
            $list = $questions[$row['delegacion']."\x1B".(int) $row['codigo']] ?? collect();
            $row['codigo_formateado'] = VeolabCodes::format($this->table, (string) $row['codigo'], (string) $row['delegacion']);
            $row['uso_texto'] = VeolabAuditChecklists::USE_NAMES[(string) $row['uso']] ?? null;
            $row['de_veolab'] = VeolabAuditChecklists::isVeolabList((string) $row['delegacion'], (int) $row['codigo']) ? 'T' : 'F';
            $row['usada'] = in_array((int) $row['codigo'], $used, true) ? 'T' : 'F';
            $row['num_preguntas'] = $list->count();
            $row['preguntas'] = $list->map(fn ($q) => [
                'linea'           => (int) $q->LVQ1COD,
                'orden'           => $q->LVQNORD === null ? null : (int) $q->LVQNORD,
                'apartado'        => $q->LVQCAPA,
                'apartado_titulo' => VeolabQualityAudits::clauseTitle($q->LVQCAPA) ?: null,
                'pregunta'        => $q->LVQCPRE,
                'que_revisar'     => $q->LVQCGUI,
                'referencia'      => $q->LVQCREF,
            ])->values()->all();
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
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];
        if (VeolabAuditChecklists::isVeolabList($del, $cod)) {
            throw new BusinessRuleException('Las listas que trae Veolab no se eliminan: se pueden dar de baja o duplicar para hacer la propia');
        }
        if (DB::connection('dynamic')->table(VeolabAuditChecklists::LINES)->where('LVE2COD', $cod)->exists()) {
            throw new BusinessRuleException('La lista se ha usado en alguna auditoría y no se puede eliminar: désela de baja');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table(VeolabAuditChecklists::QUESTIONS)->where('DEL3COD', (string) $keys['delegacion'])
            ->where('LVE3COD', (int) $keys['codigo'])->delete();
    }

    // ------------------------------------------------------------------
    // Duplicar
    // ------------------------------------------------------------------

    /**
     * POST /auditorias-calidad/listas/duplicar?delegacion=&codigo=
     * Cuerpo opcional {delegacion (de la copia; por defecto la del
     * original), descripcion (por defecto la del original con «(copia)»)}.
     * Copia la lista y sus preguntas con un código nuevo; la copia no es
     * predeterminada ni está de baja.
     */
    public function duplicate(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        if (! $request->has('codigo')) {
            return response()->json(['message' => "Falta la clave 'codigo'"], 400);
        }
        [$del, $cod] = [(string) ($request->query('delegacion') ?? ''), (int) $request->query('codigo')];
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'delegacion'  => 'nullable|string|max:10',
            'descripcion' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            $source = $db->table($this->table)->where('DEL3COD', $del)->where('LVE1COD', $cod)->first();
            if (! $source) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $target = array_key_exists('delegacion', $body) ? trim((string) ($body['delegacion'] ?? '')) : $del;
            if ($target !== '' && ! $db->table('ACCDEL')->where('DEL1COD', $target)->exists()) {
                throw new BusinessRuleException("La delegación {$target} no existe");
            }
            $description = trim((string) ($body['descripcion'] ?? ''));
            if ($description === '') {
                $description = mb_substr(trim((string) $source->LVECDES).' (copia)', 0, 255);
            }
            $new = VeolabAuditChecklists::newListCode();

            $db->table($this->table)->insert([
                'DEL3COD' => $target,
                'LVE1COD' => $new,
                'LVECDES' => $description,
                'LVECTIP' => $source->LVECTIP,
                'LVEBPRE' => 'F',
                'LVEBBAJ' => 'F',
                'LVECOBS' => $source->LVECOBS,
            ]);
            $questions = $db->table(VeolabAuditChecklists::QUESTIONS)->where('DEL3COD', $del)->where('LVE3COD', $cod)
                ->orderBy('LVQ1COD')->get();
            foreach ($questions as $q) {
                $db->table(VeolabAuditChecklists::QUESTIONS)->insert([
                    'DEL3COD' => $target, 'LVE3COD' => $new, 'LVQ1COD' => (int) $q->LVQ1COD, 'LVQNORD' => $q->LVQNORD,
                    'LVQCAPA' => $q->LVQCAPA, 'LVQCPRE' => $q->LVQCPRE, 'LVQCGUI' => $q->LVQCGUI, 'LVQCREF' => $q->LVQCREF,
                ]);
            }
            $keys = ['delegacion' => $target, 'codigo' => $new];
            VeolabAudit::record(VeolabAudit::INSERCION, $this->table, $this->auditRow($keys, $description));

            $db->commit();

            return response()->json(['message' => 'Lista duplicada correctamente', 'data' => $keys + ['preguntas' => $questions->count()]], 201);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 duplicar {$this->table}", $e, 'Error al duplicar la lista');
        }
    }
}
