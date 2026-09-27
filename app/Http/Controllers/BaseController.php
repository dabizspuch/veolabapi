<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
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

        $data = collect($paginator->items())->map(fn ($row) => $this->fromDb((array) $row))->all();

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
        try {
            DB::connection('dynamic')->beginTransaction();

            $data = json_decode($request->getContent(), true) ?? [];
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

                if (empty($validated[$this->codeKey])) {
                    $validated[$this->codeKey] = $this->generateCode(
                        $this->delegationKey ? (string) $validated[$this->delegationKey] : '',
                        $this->seriesKey ? (string) $validated[$this->seriesKey] : ''
                    );
                }
            }

            DB::connection('dynamic')->table($this->table)->insert($this->toDb($validated));

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

        if (! $this->keyQuery($keyCols)->exists()) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        try {
            DB::connection('dynamic')->beginTransaction();

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

        if (! $this->keyQuery($keyCols)->exists()) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        try {
            DB::connection('dynamic')->beginTransaction();

            $this->validateBeforeDelete($this->keyParamsFromRequest($request));
            $this->keyQuery($keyCols)->delete();
            $this->deleteRelatedRecords($this->keyParamsFromRequest($request));

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
                    $this->applyOperator($query, $column, (string) $op, $operand);
                }
            } elseif (is_string($value) && str_contains($value, ',')) {
                $query->whereIn($column, $this->splitList($value));
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
            $columns[$column] = $request->query($param);
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
            $out[$param] = $request->query($param);
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

    private function toDb(array $data): array
    {
        $out = [];
        foreach ($this->mapping as $param => $column) {
            if (array_key_exists($param, $data)) {
                $out[$column] = $data[$param];
            }
        }

        return $out;
    }

    private function fromDb(array $row): array
    {
        $out = [];
        foreach ($this->mapping as $param => $column) {
            $out[$param] = $row[$column] ?? null;
        }

        return $out;
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
     * Genera el siguiente código para (delegación, serie, tabla).
     * DEBE llamarse dentro de una transacción abierta: usa lockForUpdate
     * sobre ACCCLT para evitar colisiones (corrige la race condition de la v1,
     * donde el bloqueo se liberaba antes del INSERT).
     */
    protected function generateCode(string $delegation, string $series): int
    {
        $row = DB::connection('dynamic')->table('ACCCLT')
            ->where('DEL3COD', $delegation)
            ->where('CLTCTAB', $this->table)
            ->where('CLTCSER', $series)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            DB::connection('dynamic')->table('ACCCLT')->insert([
                'DEL3COD' => $delegation,
                'CLTCTAB' => $this->table,
                'CLTCSER' => $series,
                'CLTNVAL' => 1,
            ]);

            return 1;
        }

        $next = (int) $row->CLTNVAL + 1;

        DB::connection('dynamic')->table('ACCCLT')
            ->where('DEL3COD', $delegation)
            ->where('CLTCTAB', $this->table)
            ->where('CLTCSER', $series)
            ->update(['CLTNVAL' => $next]);

        return $next;
    }
}
