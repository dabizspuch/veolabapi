<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Traits\CodeGenerator;

abstract class BaseController extends Controller
{
    use CodeGenerator;
    
    protected $table;               // Nombre de la tabla que se está gestionando
    protected $mapping = [];        // Mapeo de los campos del API a los campos de la base de datos
    protected $delegationField;     // Campo de delegación en la tabla de base de datos
    protected $codeField;           // Campo de código en la tabla de base de datos
    protected $key1Field;           // Campo de clave1 (serie o delegacion de segundo item relacionado)
    protected $key2Field;           // Campo de clave2 (codigo auxiliar)
    protected $key3Field;           // Campo de clave3 (codigo auxiliar)
    protected $key4Field;           // Campo de clave4 (codigo auxiliar)
    protected $inactiveField;       // Campo que indica si el registro está dado de baja
    protected $searchFields;        // Campos que se usarán para realizar búsquedas de texto
    protected $skipInsert = false;  // Indica si debe saltarse la inserción 
    protected $skipNewCode = false; // Indica si debe saltarse la generación del nuevo código

    // Definir las reglas de validación de los datos (abstracto)
    abstract protected function rules();

    // Definir las validaciones de relaciones (abstracto)
    abstract protected function validateRelationships(array $data);
    
    // Definir las validaciones adicionales como nombre único, estado, etc. (abstracto)
    abstract protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null);

    // Definir las validaciones de relaciones (abstracto)
    abstract protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null);

    // Borrar registros de tablas relacionadas (abstracto)
    abstract protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null);

    // Realizar actualizaciones adicionales si procede (abstracto)
    abstract protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null);

    /**
     * Convierte los campos del API a los campos correspondientes de la base de datos.
     * 
     * @param array $data - Datos recibidos del API
     * @return array - Datos convertidos a formato de base de datos
     */
    private function mapToDatabaseFields(array $data)
    {
        $dbData = [];
        foreach ($this->mapping as $jsonField => $dbField) {
            if (array_key_exists($jsonField, $data)) {
                $dbData[$dbField] = $data[$jsonField];
            }
        }
        return $dbData;
    }

    /**
     * Convierte los campos de la base de datos a los campos naturales del API.
     * 
     * @param array $data - Datos obtenidos de la base de datos
     * @return array - Datos convertidos a formato del API
     */
    private function mapFromDatabaseFields(array $data)
    {
        return array_reduce(array_keys($this->mapping), function ($carry, $key) use ($data) {
            $carry[$key] = $data[$this->mapping[$key]] ?? null;
            return $carry;
        }, []);
    }

    /**
     * Valida los datos de la solicitud según las reglas definidas en el controlador.
     * 
     * @param array $data - Datos de la solicitud decodificados manualmente
     * @return array - Datos validados
     */
    private function validateData(array $data)
    {
        // Valida el array $data usando las reglas definidas en el método rules()
        $validator = Validator::make($data, $this->rules());

        // Si la validación falla, lanzará una excepción automáticamente
        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        // Retorna los datos validados
        return $validator->validated();
    }

    /**
     * Obtiene una lista paginada de registros.
     * Aplica filtros de búsqueda y de baja (si corresponde).
     * 
     * @param Request $request - Solicitud HTTP con los parámetros de búsqueda y paginación
     * @return \Illuminate\Http\JsonResponse - Respuesta JSON con los registros
     */    
    public function index(Request $request, $code = null, $delegation = null)
    {
        $query = DB::table($this->table);     

        // Filtro por codigo o supercodigo (si aplica)
        if (!empty($code)) { 
            $query->where($this->codeField, '=', $code)
                  ->where($this->delegationField, '=', $delegation ?? '');
        }

        // Filtro por baja (si aplica)
        if ($request->has('es_baja')) {
            $inactive = $request->input('es_baja');

            if (!is_null($inactive) && $inactive !== '') {
                if ($inactive === 'F') {
                    $query->where(function($subQuery) {
                        $subQuery->where($this->inactiveField, '=', 'F')
                                ->orWhereNull($this->inactiveField);
                    });
                } else {
                    $query->where($this->inactiveField, '=', $inactive);
                }
            }
        }

        // Búsqueda de texto
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($subQuery) use ($searchTerm) {
                foreach ($this->searchFields as $field) {
                    $subQuery->orWhere($field, 'like', "%{$searchTerm}%");
                }
            });
        }

        // Paginación
        $perPage = $request->input('limit', 10); // Default to 10
        $currentPage = $request->input('page', 1); // Default to 1
        $offset = ($currentPage - 1) * $perPage;

        // Obtener los registros con paginación
        $data = $query->offset($offset)->limit($perPage)->get();

        // Mapeo de los campos de base de datos a campos del API
        $mappedData = $data->map(function ($data) {
            return $this->mapFromDatabaseFields((array)$data);
        });

        return response()->json($mappedData);
    }

    /**
     * Muestra un registro específico basado en su código, delegación y serie u otras claves.
     * 
     * @param string $code - Código del registro
     * @param string|null $delegation - Delegación (si aplica)
     * @param string|null $key1 - Serie o delegación de segundo item (si aplica)
     * @param string|null $key2 - Código de segundo item (si aplica)
     * @param string|null $key3 - Código auxiliar (si aplica)
     * @param string|null $key4 - Código auxiliar (si aplica)     * 
     * @return \Illuminate\Http\JsonResponse - Respuesta JSON con el registro o un error 404
     */    
    public function show($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $delegation = $delegation ?? '';
        if ($this->key1Field) $key1 = $key1 ?? '';
        if ($this->key2Field) $key2 = $key2 ?? '';
        if ($this->key3Field) $key3 = $key3 ?? '';
        if ($this->key4Field) $key4 = $key4 ?? '';
 
        $record = DB::table($this->table)
            ->where($this->delegationField, $delegation)
            ->where($this->codeField, $code)
            ->where($this->key1Field, $key1)
            ->where($this->key2Field, $key2)
            ->where($this->key3Field, $key3)
            ->where($this->key4Field, $key4)
            ->first();

        if (!$record) {
            return response()->json(['error' => 'Registro no encontrado en ' . $this->table], 404);
        }
        return response()->json($this->mapFromDatabaseFields((array)$record), 200);
    } 

    /**
     * Crea un nuevo registro en la base de datos.
     * Valida los datos y genera un código si es necesario.
     * 
     * @param Request $request - Solicitud HTTP con los datos del nuevo registro
     * @return \Illuminate\Http\JsonResponse - Respuesta JSON con el mensaje de éxito o error
     */
    public function store(Request $request)
    {
        try {
            // Convierte request de forma no predeterminada para evitar conversiones de cadenas vacías a null
            $data = json_decode($request->getContent(), true);

            // Validar los datos del request
            $validatedData = $this->validateData($data);

            // Validar las relaciones y referencias
            $this->validateRelationships($validatedData);

            // Validar el nombre o descripción
            $validatedData = $this->validateAdditionalCriteria($validatedData);

            $fieldCodeName = array_search($this->codeField, $this->mapping);
            $fieldDelegationName = array_search($this->delegationField, $this->mapping);
            $fieldSeriesName = array_search($this->key1Field, $this->mapping);

            if (!$this->skipNewCode) { 
                // Evitar campos nulos
                $validatedData[$fieldDelegationName] = $validatedData[$fieldDelegationName] ?? '';
                $validatedData[$fieldSeriesName] = $validatedData[$fieldSeriesName] ?? '';

                // Si no hay código, generar uno nuevo
                if (empty($validatedData[$fieldCodeName])) {
                    $validatedData[$fieldCodeName] = $this->generateNewCode(
                        $validatedData[$fieldDelegationName],   // Delegación
                        $validatedData[$fieldSeriesName],       // Serie (si aplica)                    
                        $this->table,                           // Nombre de la tabla
                        true                                    // Bloqueo pesismista
                    ); 
                }
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ocurrió un error en la validación', 'detalle' => $e->getMessage()], 500);
        }
        try {
            // Iniciar la transacción
            DB::beginTransaction();

            if (!$this->skipInsert) { 
                // Convertir los datos a formato de base de datos
                $dbData = $this->mapToDatabaseFields($validatedData);
                
                // Insertar el registro en la base de datos
                DB::table($this->table)->insert($dbData);            
            }
            
            // Realizar actualizaciones adicionales    
            $validatedData = $this->updateAdditionalData(
                $validatedData, 
                $validatedData[$fieldCodeName] ?? 0, 
                $validatedData[$fieldDelegationName] ?? '', 
                $validatedData[$fieldSeriesName] ?? ''); 

            // Confirmar la transacción
            DB::commit();

            $response = [
                'message' => 'Registro creado correctamente'
            ];
            
            if (!$this->skipNewCode) {
                $response['data'] = [];
                
                if (!empty($validatedData[$fieldCodeName])) {
                    $response['data'][$fieldCodeName] = $validatedData[$fieldCodeName];
                }
                if (!empty($validatedData[$fieldDelegationName])) {
                    $response['data'][$fieldDelegationName] = $validatedData[$fieldDelegationName];
                }
                if (!empty($validatedData[$fieldSeriesName])) {
                    $response['data'][$fieldSeriesName] = $validatedData[$fieldSeriesName];
                }
            }
            
            return response()->json($response, 201);            

        } catch (\Exception $e) {
            // Si ocurre un error, deshacer la transacción
            DB::rollBack();

            return response()->json(['error' => 'Ocurrió un error al crear el registro', 'detalle' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualiza un registro existente en la base de datos.
     * 
     * @param Request $request - Solicitud HTTP con los datos actualizados
     * @param string $code - Código del registro a actualizar
     * @param string|null $delegation - Delegación (si aplica)
     * @param string|null $key1 - Serie o delegacion de segundo item (si aplica)
     * @param string|null $key2 - Código de segundo item (si aplica)     * 
     * @param string|null $key3 - Código auxiliar (si aplica)     * 
     * @param string|null $key4 - Código auxiliar (si aplica)     * 
     * @return \Illuminate\Http\JsonResponse - Respuesta JSON con el mensaje de éxito o error
     */    
    public function update(Request $request, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {     
        $delegation = $delegation ?? '';
        if ($this->key1Field) $key1 = $key1 ?? '';
        if ($this->key2Field) $key2 = $key2 ?? '';
        if ($this->key3Field) $key3 = $key3 ?? '';
        if ($this->key4Field) $key4 = $key4 ?? '';        

        $record = DB::table($this->table)
            ->where($this->delegationField, $delegation)
            ->where($this->codeField, $code)
            ->where($this->key1Field, $key1)
            ->where($this->key2Field, $key2)
            ->where($this->key3Field, $key3)
            ->where($this->key4Field, $key4)
            ->first();
        
        if (!$record) {
            return response()->json(['error' => 'Registro no encontrado en ' . $this->table], 404);
        }        
        
        try {
            // Iniciar la transacción
            DB::beginTransaction();

            // Convierte request de forma no predeterminada para evitar conversiones de cadenas vacías a null
            $data = json_decode($request->getContent(), true);

            // Validar los datos del request
            $validatedData = $this->validateData($data);

            // Validar las relaciones y referencias
            $this->validateRelationships($validatedData);

            // Validar el nombre o descripción
            $validatedData = $this->validateAdditionalCriteria($validatedData, $code, $delegation, $key1, $key2, $key3, $key4);

            // Convertir los datos a formato de base de datos
            $datosBD = $this->mapToDatabaseFields($validatedData);

            // Actualizar el registro en la base de datos
            if ($datosBD) {
                DB::table($this->table)
                    ->where($this->delegationField, $delegation)
                    ->where($this->codeField, $code)
                    ->where($this->key1Field, $key1)
                    ->where($this->key2Field, $key2)
                    ->where($this->key3Field, $key3)
                    ->where($this->key4Field, $key4)
                    ->update($datosBD);   
            }

            // Realizar actualizaciones adicionales
            $validatedData = $this->updateAdditionalData($validatedData, $code, $delegation, $key1, $key2, $key3, $key4);            
            
            // Confirmar la transacción
            DB::commit();

            return response()->json(['message' => 'Registro actualizado correctamente',], 200);
        } catch (\Exception $e) {
            // Si ocurre un error, deshacer la transacción
            DB::rollBack();

            return response()->json(['error' => 'Ocurrió un error al actualizar el registro', 'detalle' => $e->getMessage()], 500);
        }
    } 

    /**
     * Elimina un registro de la base de datos.
     * 
     * @param string $code - Código del registro a eliminar
     * @param string|null $delegation - Delegación (si aplica)
     * @param string|null $key1 - Serie o delegacion de segundo item (si aplica)
     * @param string|null $key2 - Código de segundo item (si aplica)
     * @param string|null $key3 - Código auxiliar (si aplica)
     * @param string|null $key4 - Código auxiliar (si aplica)
     * @return \Illuminate\Http\JsonResponse - Respuesta JSON con el mensaje de éxito o error
     */    
    public function destroy($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $delegation = $delegation ?? '';
        if ($this->key1Field) $key1 = $key1 ?? '';
        if ($this->key2Field) $key2 = $key2 ?? '';
        if ($this->key3Field) $key3 = $key3 ?? '';
        if ($this->key4Field) $key4 = $key4 ?? '';     
                
        $record = DB::table($this->table)
            ->where($this->delegationField, $delegation)
            ->where($this->codeField, $code)
            ->where($this->key1Field, $key1)
            ->where($this->key2Field, $key2)
            ->where($this->key3Field, $key3)
            ->where($this->key4Field, $key4)
            ->first();
        
        if (!$record) {
            return response()->json(['error' => 'Registro no encontrado en ' . $this->table], 404);
        }
        try {
            // Iniciar la transacción
            DB::beginTransaction();

            // Comprueba que no está referenciado
            $this->validateBeforeDelete($code, $delegation, $key1, $key2, $key3, $key4);

            // Eliminar el registro de la base de datos
            DB::table($this->table)
                ->where($this->delegationField, $delegation)
                ->where($this->codeField, $code)
                ->where($this->key1Field, $key1)
                ->where($this->key2Field, $key2)
                ->where($this->key3Field, $key3)
                ->where($this->key4Field, $key4)
                ->delete();

            // Eliminar registros relacionados antes de eliminar el principal
            $this->deleteRelatedRecords($code, $delegation, $key1, $key2, $key3, $key4);

            // Confirmar la transacción
            DB::commit();

            return response()->json(['message' => 'Registro eliminado correctamente'], 200);
        } catch (\Exception $e) {
            // Si ocurre un error, deshacer la transacción
            DB::rollBack();

            return response()->json(['error' => 'Ocurrió un error al eliminar el registro', 'detalle' => $e->getMessage()], 500);
        }
    }

}
