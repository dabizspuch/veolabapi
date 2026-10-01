<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Controlador base de la API v2 de Veolab.
 *
 * Convenciones (ver docs/v2/CONVENCIONES.md):
 *  - Direccionamiento por claves NOMBRADAS en query string (no posicional).
 *  - Un único endpoint de colección: filtrar por clave completa devuelve 1 registro,
 *    clave parcial/ausente devuelve un listado. La respuesta es SIEMPRE {data, meta}.
 *  - Filtrado por cualquier campo mapeado (whitelist = $mapping) con operadores.
 *  - Orden configurable con desempate determinista por la clave primaria.
 *  - Paginación offset con envoltorio {data, meta} y tope de 'limit'.
 *  - Errores: 422 para validación y reglas de negocio; nunca se filtra el detalle interno.
 */
abstract class BaseController extends Controller
{
    /** Nombre de la tabla gestionada. */
    protected string $table;

    /** Clave primaria como mapa ORDENADO: parámetro API => columna BD. */
    protected array $keys = [];

    /** Todos los campos: parámetro API => columna BD (incluye las claves). */
    protected array $mapping = [];

    /** Columnas usadas para la búsqueda de texto libre (?search=). */
    protected array $searchFields = [];

    /**
     * Claves foráneas: nombre del grupo => tipo del código ('int' | 'string').
     * El grupo lo forman los parámetros {grupo}_delegacion, {grupo}_serie y
     * {grupo}_codigo que existan en $mapping (delegación y serie son texto).
     *
     * Veolab guarda la FK vacía como 0 / '' (no NULL). La API la expone como
     * null en todo el grupo (el vacío lo decide el código: la delegación ''
     * es válida), y al escribir convierte null en 0 / '' para no romper VB6.
     */
    protected array $foreignKeys = [];

    /**
     * Columna cuyo valor acompaña al código en la fila auditada (AUDCFIL)
     * cuando la tabla no tiene formato configurable, como las fichas de
     * Veolab que llaman a PAR_FormatoCodigo con la descripción.
     */
    protected ?string $auditDescription = null;

    /** Columna que marca baja/anulación, para el atajo ?is_deleted= (opcional). */
    protected ?string $inactiveField = null;

    /** Generación automática de código en la creación (entidades propias). */
    protected bool $generatesCode = false;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = null;

    /** Paginación. */
    protected int $defaultPerPage = 25;
    protected int $maxPerPage = 100;

    /** Operadores de comparación admitidos como sufijo campo[op]=valor. */
    private const OPERATORS = ['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<', 'ne' => '!='];

    /** Parámetros reservados que no son filtros de campo. */
    private const RESERVED = ['page', 'limit', 'sort', 'order', 'after', 'search', 'is_deleted'];

    // ------------------------------------------------------------------
    // Hooks extensibles (no-op por defecto; los sobreescribe cada entidad)
    // ------------------------------------------------------------------

    protected function rules(): array
    {
        return [];
    }

    /** Valida existencia de referencias. Lanza BusinessRuleException si falla. */
    protected function validateRelationships(array $data): void {}

    /** Validaciones adicionales (unicidad, etc.). $keys vacío = creación. */
    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        return $data;
    }

    /** Comprueba que el registro no está referenciado antes de borrarlo. */
    protected function validateBeforeDelete(array $keys): void {}

    /** Borra registros relacionados tras eliminar el principal. */
    protected function deleteRelatedRecords(array $keys): void {}

    /** Actualizaciones adicionales tras crear/actualizar. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        return $data;
    }

    /** Completa las filas ya mapeadas de un listado (datos de otras tablas). */
    protected function appendRelatedData(array $rows): array
    {
        return $rows;
    }

    // ------------------------------------------------------------------
    // Endpoints
    // ------------------------------------------------------------------

    /**
     * Listado (o registro único si se da la clave completa).
     * Respuesta: { "data": [...], "meta": { total, page, per_page, last_page } }.
     */
    public function index(Request $request)
    {
        $query = DB::connection('dynamic')->table($this->table);

        $this->applyFilters($request, $query);
        $this->applyIsDeleted($request, $query);
        $this->applySearch($request, $query);
        $this->applyOrder($request, $query);

        $perPage = min(max((int) $request->query('limit', (string) $this->defaultPerPage), 1), $this->maxPerPage);
        $page = max((int) $request->query('page', '1'), 1);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = $this->appendRelatedData(
            collect($paginator->items())->map(fn ($row) => $this->fromDb((array) $row))->all()
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'per_page'  => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** Crea un registro. Genera el código si procede (dentro de la transacción). */
    public function store(Request $request)
    {
        return $this->create(json_decode($request->getContent(), true) ?? []);
    }

    /** Alta a partir de los datos ya decodificados (store y altas derivadas). */
    protected function create(array $data)
    {
        try {
            DB::connection('dynamic')->beginTransaction();

            $validated = $this->validateData($data);
            $this->validateRelationships($validated);
            $validated = $this->validateAdditionalCriteria($validated, []);

            if ($this->generatesCode) {
                // Evitar claves nulas: la delegación/serie ausentes se insertan como ''.
                if ($this->delegationKey) {
                    $validated[$this->delegationKey] = $validated[$this->delegationKey] ?? '';
                }
                if ($this->seriesKey) {
                    $validated[$this->seriesKey] = $validated[$this->seriesKey] ?? '';
                }

                // Reglas de ACCCFC: código bloqueado (solo automático) o no
                // autonumérico (obligatorio indicarlo).
                $given = isset($validated[$this->codeKey]) && $validated[$this->codeKey] !== '';
                if ($given && VeolabCodes::locked($this->table)) {
                    throw new BusinessRuleException('El código se asigna automáticamente y no se puede indicar');
                }
                if (! $given && ! VeolabCodes::autonumeric($this->table)) {
                    throw new BusinessRuleException('El código es obligatorio');
                }

                if (! $given) {
                    $validated[$this->codeKey] = $this->generateCode(
                        $this->delegationKey ? (string) $validated[$this->delegationKey] : '',
                        $this->seriesKey ? (string) $validated[$this->seriesKey] : ''
                    );
                } elseif ($this->keyQuery($this->keyColumnsFromData($validated))->exists()) {
                    throw new BusinessRuleException('El código ya está en uso');
                }
            }

            if (! $this->generatesCode && $this->keyQuery($this->keyColumnsFromData($validated))->exists()) {
                throw new BusinessRuleException('El registro ya existe');
            }

            DB::connection('dynamic')->table($this->table)->insert($this->toDb($validated, true));

            $this->auditCreated($validated, $this->keyParamsFromData($validated));

            $this->updateAdditionalData($validated, $this->keyParamsFromData($validated));

            DB::connection('dynamic')->commit();

            return response()->json([
                'message' => 'Registro creado correctamente',
                'data'    => $this->keyParamsFromData($validated),
            ], 201);
        } catch (ValidationException $e) {
            DB::connection('dynamic')->rollBack();

            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            DB::connection('dynamic')->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::connection('dynamic')->rollBack();
            Log::error("v2 store {$this->table}: ".$e->getMessage());

            return response()->json(['message' => 'Error al crear el registro'], 500);
        }
    }

    /** Actualiza el registro identificado por la clave completa (query string). */
    public function update(Request $request)
    {
        $keyCols = $this->fullKeyColumns($request);

        try {
            DB::connection('dynamic')->beginTransaction();

            // Fila anterior (bloqueada) para la auditoría de campos.
            $before = $this->keyQuery($keyCols)->lockForUpdate()->first();
            if (! $before) {
                DB::connection('dynamic')->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $validated = $this->validateData($data);
            $this->validateRelationships($validated);
            $validated = $this->validateAdditionalCriteria($validated, $this->keyParamsFromRequest($request));

            // Las claves no son editables.
            foreach (array_keys($this->keys) as $param) {
                unset($validated[$param]);
            }

            $dbData = $this->toDb($validated);
            if ($dbData) {
                $this->keyQuery($keyCols)->update($dbData);
                $this->auditUpdated((array) $before, $dbData, $this->keyParamsFromRequest($request));
            }

            $this->updateAdditionalData($validated, $this->keyParamsFromRequest($request));

            DB::connection('dynamic')->commit();

            return response()->json(['message' => 'Registro actualizado correctamente']);
        } catch (ValidationException $e) {
            DB::connection('dynamic')->rollBack();

            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            DB::connection('dynamic')->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::connection('dynamic')->rollBack();
            Log::error("v2 update {$this->table}: ".$e->getMessage());

            return response()->json(['message' => 'Error al actualizar el registro'], 500);
        }
    }

    /** Elimina el registro identificado por la clave completa (query string). */
    public function destroy(Request $request)
    {
        $keyCols = $this->fullKeyColumns($request);

        $before = $this->keyQuery($keyCols)->first();
        if (! $before) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        try {
            DB::connection('dynamic')->beginTransaction();

            $this->validateBeforeDelete($this->keyParamsFromRequest($request));
            $this->keyQuery($keyCols)->delete();
            $this->deleteRelatedRecords($this->keyParamsFromRequest($request));

            $this->auditDeleted((array) $before, $this->keyParamsFromRequest($request));

            DB::connection('dynamic')->commit();

            return response()->json(['message' => 'Registro eliminado correctamente']);
        } catch (BusinessRuleException $e) {
            DB::connection('dynamic')->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::connection('dynamic')->rollBack();
            Log::error("v2 destroy {$this->table}: ".$e->getMessage());

            return response()->json(['message' => 'Error al eliminar el registro'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Filtrado / orden / paginación
    // ------------------------------------------------------------------

    /** Aplica filtros por cualquier campo mapeado (incluidas las claves). */
    private function applyFilters(Request $request, $query): void
    {
        foreach ($request->query() as $param => $value) {
            if (in_array($param, self::RESERVED, true) || ! isset($this->mapping[$param])) {
                continue;
            }

            $column = $this->mapping[$param];

            if (is_array($value)) {
                foreach ($value as $op => $operand) {
                    if ($op === 'null' && ($group = $this->foreignKeyGroupOf($param))) {
                        $this->applyForeignKeyNull($query, $group, $operand);
                        continue;
                    }
                    $this->applyOperator($query, $column, (string) $op, $operand);
                }
            } elseif (is_string($value) && str_contains($value, ',')) {
                $query->whereIn($column, $this->splitList($value));
            } elseif ($value === null) {
                // ?campo= llega como null (ConvertEmptyStringsToNull): es vacío.
                // En Veolab eso es '' (p. ej. la delegación vacía), o NULL.
                $query->where(function ($q) use ($column) {
                    $q->where($column, '')->orWhereNull($column);
                });
            } else {
                $query->where($column, '=', $value);
            }
        }
    }

    private function applyOperator($query, string $column, string $op, $operand): void
    {
        switch ($op) {
            case 'like':
                $query->where($column, 'like', '%'.$operand.'%');
                break;
            case 'in':
                $query->whereIn($column, is_array($operand) ? $operand : $this->splitList((string) $operand));
                break;
            case 'null':
                filter_var($operand, FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNull($column)
                    : $query->whereNotNull($column);
                break;
            default:
                if (isset(self::OPERATORS[$op])) {
                    $query->where($column, self::OPERATORS[$op], $operand);
                }
        }
    }

    /**
     * campo[null]=T|F sobre cualquier miembro de un grupo FK: se evalúa sobre
     * el código del grupo y cuenta como vacío NULL, 0 ó '' (lo que guarda Veolab).
     */
    private function applyForeignKeyNull($query, string $group, $operand): void
    {
        $column = $this->mapping["{$group}_codigo"];
        $empty = $this->foreignKeys[$group] === 'int' ? 0 : '';

        if (filter_var($operand, FILTER_VALIDATE_BOOLEAN)) {
            $query->where(function ($q) use ($column, $empty) {
                $q->whereNull($column)->orWhere($column, $empty);
            });
        } else {
            $query->whereNotNull($column)->where($column, '!=', $empty);
        }
    }

    /** Atajo de compatibilidad ?is_deleted= sobre la columna de baja. */
    private function applyIsDeleted(Request $request, $query): void
    {
        if (! $this->inactiveField || ! $request->has('is_deleted')) {
            return;
        }

        $value = $request->query('is_deleted');
        if ($value === '' || is_null($value)) {
            return;
        }

        if ($value === 'F') {
            $query->where(function ($q) {
                $q->where($this->inactiveField, 'F')->orWhereNull($this->inactiveField);
            });
        } else {
            $query->where($this->inactiveField, $value);
        }
    }

    private function applySearch(Request $request, $query): void
    {
        if (! $request->has('search') || empty($this->searchFields)) {
            return;
        }

        $term = $request->query('search');
        $query->where(function ($q) use ($term) {
            foreach ($this->searchFields as $field) {
                $q->orWhere($field, 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * Orden configurable (?sort=-campo,campo2 ó ?sort=campo&order=desc).
     * SIEMPRE añade la clave primaria completa como desempate para que la
     * paginación sea determinista.
     */
    private function applyOrder(Request $request, $query): void
    {
        $applied = [];
        $defaultDir = strtolower((string) $request->query('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sort = $request->query('sort')) {
            foreach (explode(',', (string) $sort) as $field) {
                $field = trim($field);
                $dir = $defaultDir;
                if (str_starts_with($field, '-')) {
                    $dir = 'desc';
                    $field = substr($field, 1);
                }
                if (! isset($this->mapping[$field])) {
                    continue;
                }
                $column = $this->mapping[$field];
                $query->orderBy($column, $dir);
                $applied[] = $column;
            }
        }

        foreach ($this->keys as $column) {
            if (! in_array($column, $applied, true)) {
                $query->orderBy($column, 'asc');
                $applied[] = $column;
            }
        }
    }

    // ------------------------------------------------------------------
    // Claves / mapeo / utilidades
    // ------------------------------------------------------------------

    /** Devuelve [columna => valor] exigiendo que estén TODAS las claves. */
    private function fullKeyColumns(Request $request): array
    {
        $columns = [];
        foreach ($this->keys as $param => $column) {
            if (! $request->has($param)) {
                throw new HttpResponseException(
                    response()->json(['message' => "Falta la clave '{$param}'"], 400)
                );
            }
            $columns[$column] = $this->keyValue($request, $param);
        }

        return $columns;
    }

    private function keyQuery(array $keyColumns)
    {
        $query = DB::connection('dynamic')->table($this->table);
        foreach ($keyColumns as $column => $value) {
            $query->where($column, $value);
        }

        return $query;
    }

    private function keyParamsFromRequest(Request $request): array
    {
        $out = [];
        foreach ($this->keys as $param => $column) {
            $out[$param] = $this->keyValue($request, $param);
        }

        return $out;
    }

    /**
     * Valor de una clave en la query string. Laravel convierte ?delegacion= en
     * null (ConvertEmptyStringsToNull); en Veolab la clave vacía es '' (las
     * columnas de PK son NOT NULL), así que se restituye.
     */
    private function keyValue(Request $request, string $param): string
    {
        return (string) ($request->query($param) ?? '');
    }

    /** Clave completa [columna => valor] a partir de los datos (ausente = ''). */
    private function keyColumnsFromData(array $data): array
    {
        $out = [];
        foreach ($this->keys as $param => $column) {
            $out[$column] = $data[$param] ?? '';
        }

        return $out;
    }

    private function keyParamsFromData(array $data): array
    {
        $out = [];
        foreach (array_keys($this->keys) as $param) {
            if (array_key_exists($param, $data)) {
                $out[$param] = $data[$param];
            }
        }

        return $out;
    }

    /**
     * Parámetros API => columnas BD. Las FK nulas se escriben como 0 / '';
     * si el código de un grupo llega vacío se vacía el grupo entero. Con
     * $fillForeignKeys (creación) las FK ausentes también se rellenan así.
     */
    private function toDb(array $data, bool $fillForeignKeys = false): array
    {
        foreach ($this->foreignKeys as $group => $type) {
            $members = $this->foreignKeyMembers($group);
            $code = "{$group}_codigo";
            $clearGroup = array_key_exists($code, $data) && $this->isEmptyForeignKey($data[$code], $type);

            foreach ($members as $param => $memberType) {
                $present = array_key_exists($param, $data);
                if ($clearGroup || ($present && $data[$param] === null) || (! $present && $fillForeignKeys)) {
                    $data[$param] = $memberType === 'int' ? 0 : '';
                }
            }
        }

        $out = [];
        foreach ($this->mapping as $param => $column) {
            if (array_key_exists($param, $data)) {
                $out[$column] = $data[$param];
            }
        }

        return $out;
    }

    /** Columnas BD => parámetros API. Un grupo FK vacío sale entero como null. */
    private function fromDb(array $row): array
    {
        $out = [];
        foreach ($this->mapping as $param => $column) {
            $out[$param] = $row[$column] ?? null;
        }

        foreach ($this->foreignKeys as $group => $type) {
            if ($this->isEmptyForeignKey($out["{$group}_codigo"] ?? null, $type)) {
                foreach (array_keys($this->foreignKeyMembers($group)) as $param) {
                    $out[$param] = null;
                }
            }
        }

        return $out;
    }

    /** Miembros de un grupo FK presentes en $mapping: parámetro => tipo. */
    private function foreignKeyMembers(string $group): array
    {
        $members = [];
        foreach (['delegacion' => 'string', 'serie' => 'string', 'codigo' => $this->foreignKeys[$group]] as $suffix => $type) {
            if (isset($this->mapping["{$group}_{$suffix}"])) {
                $members["{$group}_{$suffix}"] = $type;
            }
        }

        return $members;
    }

    /** Grupo FK al que pertenece un parámetro, o null. */
    private function foreignKeyGroupOf(string $param): ?string
    {
        foreach (array_keys($this->foreignKeys) as $group) {
            if (isset($this->foreignKeyMembers($group)[$param])) {
                return $group;
            }
        }

        return null;
    }

    private function isEmptyForeignKey($value, string $type): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return $type === 'int' && (int) $value === 0;
    }

    private function splitList(string $value): array
    {
        return array_map('trim', explode(',', $value));
    }

    private function validateData(array $data): array
    {
        $validator = Validator::make($data, $this->rules());
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Genera el siguiente código para (delegación, serie, tabla) como Veolab:
     * contador ACCCLT con el múltiplo de ACCCFC, repitiendo mientras el código
     * ya exista (puede haberse introducido a mano). DEBE llamarse dentro de una
     * transacción abierta: el contador se bloquea con FOR UPDATE.
     */
    protected function generateCode(string $delegation, string $series): int
    {
        $multiple = VeolabCodes::multiple($this->table);
        $codeColumn = $this->keys[$this->codeKey] ?? null;

        for ($attempt = 0; $attempt < 10000; $attempt++) {
            $code = VeolabCodes::next($this->table, $series, $delegation, $multiple);

            if ($codeColumn === null) {
                return $code;
            }

            $query = DB::connection('dynamic')->table($this->table)->where($codeColumn, $code);
            if ($this->delegationKey && isset($this->keys[$this->delegationKey])) {
                $query->where($this->keys[$this->delegationKey], $delegation);
            }
            if ($this->seriesKey && isset($this->keys[$this->seriesKey])) {
                $query->where($this->keys[$this->seriesKey], $series);
            }
            if (! $query->exists()) {
                return $code;
            }
        }

        throw new BusinessRuleException('No se ha podido generar un código libre');
    }

    // ------------------------------------------------------------------
    // Auditoría (ACCAUD)
    // ------------------------------------------------------------------

    /**
     * Fila auditada (AUDCFIL): el código formateado como en Veolab, con la
     * descripción de $auditDescription (la vigente si no se pasa). Las tablas
     * cuya clave no sea delegación/serie/código deben sobreescribirlo.
     */
    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        if ($description === null && $this->auditDescription) {
            $columns = [];
            foreach ($this->keys as $param => $column) {
                $columns[$column] = $keyParams[$param] ?? '';
            }
            $description = (string) $this->keyQuery($columns)->value($this->auditDescription);
        }

        return VeolabCodes::format(
            $this->table,
            (string) ($keyParams[$this->codeKey] ?? ''),
            $this->delegationKey ? (string) ($keyParams[$this->delegationKey] ?? '') : '',
            $this->seriesKey ? (string) ($keyParams[$this->seriesKey] ?? '') : '',
            '',
            (string) $description
        );
    }

    /** Alta: suceso de inserción. */
    protected function auditCreated(array $data, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::INSERCION, $this->table, $this->auditRow($keyParams));
    }

    /** Borrado: suceso de borrado ($before = fila borrada). */
    protected function auditDeleted(array $before, array $keyParams): void
    {
        $description = $this->auditDescription ? (string) ($before[$this->auditDescription] ?? '') : null;
        VeolabAudit::record(VeolabAudit::BORRADO, $this->table, $this->auditRow($keyParams, $description));
    }

    /**
     * Modificación: con nivel 2 un suceso de fila; con nivel 3 uno por cada
     * campo que cambia (AUDCCAM = tabla+columna, valores nuevo y anterior).
     */
    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        $row = $this->auditRow($keyParams);

        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);

        if (! VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
            return;
        }

        foreach ($dbData as $column => $new) {
            $new = VeolabAudit::value($new);
            $old = VeolabAudit::value($before[$column] ?? null);
            if ($old === $new) {
                continue;
            }
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row,
                $this->table.$column, $new, $old);
        }
    }
}
