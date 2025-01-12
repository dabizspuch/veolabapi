<?php

namespace App\Http\Controllers;

use App\Traits\OperationInventoryTools;
use Illuminate\Support\Facades\DB;

class OperacionParametroController extends BaseController
{
    use OperationInventoryTools;

    protected $table = 'LABRES';
    protected $delegationField = 'OPE3DEL';
    protected $key1Field = 'OPE3SER';     
    protected $codeField = 'OPE3COD'; 
    protected $key2Field = 'TEC3COD';    
    protected $key3Field = 'TEC3DEL';    
    protected $inactiveField = 'RESBEXP';
    protected $searchFields = ['RESCNOM', 'RESCNOI'];
    protected $skipInsert = true; // Se genera el parámetro desde la estructura de ficheros
    
    protected $mapping = [
        'operacion_delegacion'          => 'OPE3DEL',
        'operacion_serie'               => 'OPE3SER',
        'operacion_codigo'              => 'OPE3COD',
        'parametro_delegacion'          => 'TEC3DEL',
        'parametro_codigo'              => 'TEC3COD',
        'nombre'                        => 'RESCNOM',
        'nombre_informes'               => 'RESCNOI',
        'es_cursiva'                    => 'RESBCUR',
        'fecha_acreditacion'            => 'RESDACR',
        'parametro'                     => 'RESCPAR',
        'abreviatura'                   => 'RESCABR',
        'numero_cas'                    => 'RESCCAS',
        'precio'                        => 'RESNPRE',
        'descuento'                     => 'RESCDTO',
        'unidades'                      => 'RESCUNI',
        'leyenda'                       => 'RESCLEY',
        'metodologia'                   => 'RESCMET',
        'metodologia_abreviada'         => 'RESCMEA',
        'normativa'                     => 'RESCNOR',
        'tiempo_prueba'                 => 'RESNTIE',
        'limite_cuantificacion'         => 'RESCLIM',
        'valor_minimo_detectable'       => 'RESCMIN',
        'incertidumbre'                 => 'RESCINC',
        'instruccion'                   => 'RESCINS',
        'es_exportable'                 => 'RESBEXP',
        'posicion'                      => 'RESNORD',
        'es_agrupada'                   => 'RESBAGR',
        'fecha_inicio'                  => 'RESTINI',
        'fecha_fin'                     => 'RESTFIN',
        'seccion_delegacion'            => 'SEC2DEL',
        'seccion_codigo'                => 'SEC2COD',
        'analista_delegacion'           => 'EMP2DEL',
        'analista_codigo'               => 'EMP2COD',
        'servicio_delegacion'           => 'SER2DEL',
        'servicio_codigo'               => 'SER2COD'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'operacion_delegacion'      => 'nullable|string|max:10',
            'operacion_serie'           => 'nullable|string|max:10',
            'operacion_codigo'          => 'nullable|integer',
            'parametro_delegacion'      => 'nullable|string|max:10',
            'parametro_codigo'          => 'nullable|string|max:30',
            'nombre'                    => 'nullable|string|max:255',
            'nombre_informes'           => 'nullable|string|max:255',
            'es_cursiva'                => 'nullable|in:T,F|max:1',
            'fecha_acreditacion'        => 'nullable|date',
            'parametro'                 => 'nullable|string|max:100',
            'abreviatura'               => 'nullable|string|max:50',
            'numero_cas'                => 'nullable|string|max:50',
            'precio'                    => 'nullable|numeric',
            'descuento'                 => 'nullable|string|max:15',
            'unidades'                  => 'nullable|string|max:50',
            'leyenda'                   => 'nullable|string|max:100',
            'metodologia'               => 'nullable|string|max:255',
            'metodologia_abreviada'     => 'nullable|string|max:255',
            'normativa'                 => 'nullable|string|max:100',
            'tiempo_prueba'             => 'nullable|integer',
            'limite_cuantificacion'     => 'nullable|string|max:50',
            'valor_minimo_detectable'   => 'nullable|string|max:50',
            'incertidumbre'             => 'nullable|string|max:50',
            'instruccion'               => 'nullable|string',
            'es_exportable'             => 'nullable|in:T,F|max:1',
            'posicion'                  => 'nullable|integer',
            'es_agrupada'               => 'nullable|in:T,F|max:1',
            'fecha_inicio'              => 'nullable|date',
            'fecha_fin'                 => 'nullable|date',
            'seccion_delegacion'        => 'nullable|string|max:10',
            'seccion_codigo'            => 'nullable|integer',
            'analista_delegacion'       => 'nullable|string|max:10',
            'analista_codigo'           => 'nullable|integer',
            'servicio_delegacion'       => 'nullable|string|max:10',
            'servicio_codigo'           => 'nullable|string|max:20',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia de la operación 
        if (!empty($data['operacion_codigo'])) {
            $exist = DB::connection('dynamic')->table('LABOPE')
                ->where('DEL3COD', $data['operacion_delegacion'])
                ->where('OPE1SER', $data['operacion_serie'])
                ->where('OPE1COD', $data['operacion_codigo'])
                ->first(); 
            if (!$exist) {
                throw new \Exception("La operación no existe");
            }
        }

        // Valida la existencia del parámetro 
        if (!empty($data['parametro_codigo'])) {
            $exist = DB::connection('dynamic')->table('LABTEC')
                ->where('DEL3COD', $data['parametro_delegacion'])
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first(); 
            if (!$exist) {
                throw new \Exception("El parámetro no existe");
            }
        }

        // Valida la existencia de la sección 
        if (!empty($data['seccion_codigo'])) {
            $exist = DB::connection('dynamic')->table('LABSEC')
                ->where('DEL3COD', $data['seccion_delegacion'])
                ->where('SEC1COD', $data['seccion_codigo'])
                ->first(); 
            if (!$exist) {
                throw new \Exception("La sección no existe");
            }
        } 
        
        // Valida la existencia del analista 
        if (!empty($data['analista_codigo'])) {
            $exist = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', $data['analista_delegacion'])
                ->where('EMP1COD', $data['analista_codigo'])
                ->where('EMPBANA', 'T')
                ->first(); 
            if (!$exist) {
                throw new \Exception("El analista no existe");
            }
        }          

        // Valida la existencia del servicio 
        if (!empty($data['servicio_codigo'])) {
            $exist = DB::connection('dynamic')->table('LABSER')
                ->where('DEL3COD', $data['servicio_delegacion'])
                ->where('SER1COD', $data['servicio_codigo'])
                ->first(); 
            if (!$exist) {
                throw new \Exception("El servicio no existe");
            }
        }         
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Si se está modificando una operación se debe comprobar que no se hayan firmado o validado informes relacionados
        if (!is_null($code)) {
            $this->validateOperation($delegation ?? '', $key1 ?? '', $code);  
        }
        if (!empty($data['operacion_codigo'])) {
            $this->validateOperation($data['operacion_delegacion'] ?? '', $data['operacion_serie'] ?? '', $data['operacion_codigo']);  
        }

        $isCreating = request()->isMethod('post');
        if ($isCreating) {
            // se deben excluir todos los campos salvo la operación, parámetro y servicio
            $allowedKeys = [
                'operacion_delegacion', 
                'operacion_serie',
                'operacion_codigo',
                'parametro_codigo', 
                'parametro_delegacion', 
                'servicio_delegacion', 
                'servicio_codigo'
            ];
            $data = array_intersect_key($data, array_flip($allowedKeys));

            // Verificar que todos los campos obligatorios están presentes 
            foreach ($allowedKeys as $key) { 
                if (!array_key_exists($key, $data)) {
                    throw new \InvalidArgumentException("El campo $key es obligatorio.");
                }
            }

            // Se comprueba que el servicio indicado ya esté asociado a la operación
            if ($data['servicio_codigo']) {
                $serviceExists = DB::connection('dynamic')->table('LABOYS')
                    ->where('OPE3DEL', $data['operacion_delegacion'])
                    ->where('OPE3SER', $data['operacion_serie'])
                    ->where('OPE3COD', $data['operacion_codigo'])
                    ->where('SER3DEL', $data['servicio_delegacion'])
                    ->where('SER3COD', $data['servicio_codigo'])
                    ->exists();

                if (!$serviceExists) {
                    throw new \Exception("El servicio no está asociado a la operación");
                }
            }

            // Se comprueba que el parámetro no exista en la operación
            $paramExist = DB::connection('dynamic')->table('LABRES')
                ->where('OPE3DEL', $data['operacion_delegacion'])
                ->where('OPE3SER', $data['operacion_serie'])
                ->where('OPE3COD', $data['operacion_codigo'])
                ->where('TEC3DEL', $data['parametro_delegacion'])
                ->where('TEC3COD', $data['parametro_codigo'])
                ->exists();
            if ($paramExist) {
                throw new \Exception("El parámetro ya estaba asociado a la operación");
            }
        } else {                        
            // Las claves y el servicio no pueden ser modificados            
            unset(
                $data['operacion_delegacion'], 
                $data['operacion_serie'], 
                $data['operacion_codigo'], 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'],
                $data['servicio_delegacion'],
                $data['servicio_codigo']             
            );
        }

        return $data;               
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Una operación con informes firmados o validados no puede ser borrada
        $this->validateOperation($delegation, $key1, $code);  
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Reconstruye LABOYD
        DB::connection('dynamic')->table('LABOYD')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();

        $departments = DB::connection('dynamic')->table('LABRES')
            ->join('LABSEC', function($join) {
                $join->on('LABRES.SEC2DEL', '=', 'LABSEC.DEL3COD')
                    ->on('LABRES.SEC2COD', '=', 'LABSEC.SEC1COD');
            })
            ->select('LABSEC.DEP2DEL', 'LABSEC.DEP2COD')
            ->where('LABRES.OPE3DEL', $delegation)
            ->where('LABRES.OPE3SER', $key1)
            ->where('LABRES.OPE3COD', $code)
            ->where(function($query) use ($key3, $key2) {
                $query->where('LABRES.TEC3DEL', '<>', $key3)
                      ->orWhere('LABRES.TEC3COD', '<>', $key2);
            })
            ->distinct()
            ->get();

        $rowsToInsert = $departments->map(function($department) use ($delegation, $key1, $code) {
            return [
                'OPE3DEL' => $delegation,
                'OPE3SER' => $key1,
                'OPE3COD' => $code,
                'DEP3DEL' => $department->DEP2DEL,
                'DEP3COD' => $department->DEP2COD,
            ];
        })->toArray();
        
        if (!empty($rowsToInsert)) {
            DB::connection('dynamic')->table('LABOYD')->insert($rowsToInsert);
        }

        // Borrado de LABCOR
        DB::connection('dynamic')->table('LABCOR')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->where('TEC3DEL', $key3)
            ->where('TEC3COD', $key2)
            ->delete();            

        // Actualizar lista de técnicas en OPECTEC
        $parameterList = DB::connection('dynamic')->table('LABRES')
            ->leftJoin('LABTEC', function($join) {
                $join->on('LABRES.TEC3DEL', '=', 'LABTEC.DEL3COD')
                    ->on('LABRES.TEC3COD', '=', 'LABTEC.TEC1COD');
            })
            ->pluck('LABTEC.TECCNOM')
            ->where('LABRES.OPE3DEL', $delegation)
            ->where('LABRES.OPE3SER', $key1)
            ->where('LABRES.OPE3COD', $code)
            ->where(function($query) use ($key3, $key2) {
                $query->where('LABRES.TEC3DEL', '<>', $key3)
                    ->orWhere('LABRES.TEC3COD', '<>', $key2);
            })
            ->implode(';');
            
        DB::connection('dynamic')->table('LABOPE')
            ->where('DEL3COD', $delegation)
            ->where('OPE1SER', $key1)
            ->where('OPE1COD', $code)
            ->update([
                'OPECTEC' => $parameterList
            ]);

        // Borra consumos y actualiza cantidades en inventario
        $this->deleteConsumptions($delegation, $key1, $code, $key3, $key2);
    }

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');
        if ($isCreating) {
            // --- Generacion de nuevo parámetro a partir de estructuras de ficheros ---
            
            $delegation = $data['operacion_delegacion'];
            $key1 = $data['operacion_serie'];
            $code = $data['operacion_codigo'];

            // Inicializa variables
            $rateCode = 0; 
            $rateDelegation = ''; 
            $clientCode = ''; 
            $clientDelegation = '';
            $employees = [];
            $departments = [];
            $products = [];

            // Lee configuraciones             
            [$isRateBased, $isDefaultValue] = $this->getConfiguration();
            [$markDelegation, $markCode] = $this->getDefaultMark($delegation);
            
            if ($isRateBased) {
                [$rateCode, $rateDelegation] = $this->getRateData($data, $delegation, $key1, $code);
            } else {
                [$clientCode, $clientDelegation] = $this->getClientData($data, $delegation, $key1, $code);
            }  

            // Obtiene información en la base de datos del parámetro
            $parameter = DB::connection('dynamic')->table('LABTEC')
                ->where('DEL3COD', $data['parametro_delegacion'])
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first();

                // Obtiene información en la base de datos del servicio
            $service = DB::connection('dynamic')->table('LABSER')
                ->where('DEL3COD', $data['servicio_delegacion'])
                ->where('SER1COD', $data['servicio_codigo'])
                ->first();

            // Obtiene la posición del servicio
            $servicePosition = DB::connection('dynamic')->table('LABOYS')
                ->where('OPE3DEL', $delegation)
                ->where('OPE3SER', $key1)
                ->where('OPE3COD', $code)
                ->where('SER3DEL', $data['servicio_delegacion'])
                ->where('SER3COD', $data['servicio_codigo'])
                ->pluck('OYSNPOS')
                ->first();
                
            // Obtiene la última posición en la que irá el nuevo parámetro
            $parameterPosition = DB::connection('dynamic')->table('LABRES')
                ->where('OPE3DEL', $delegation)
                ->where('OPE3SER', $key1)
                ->where('OPE3COD', $code)
                ->max('RESNORD')+1;

            // Crea la estructura del parámetro en la base de datos
            $this->insertParam(
                $delegation, 
                $key1, 
                $code, 
                $parameter, 
                $service, 
                $servicePosition, 
                $parameterPosition,
                $isRateBased, 
                $isDefaultValue, 
                $markDelegation, 
                $markCode, 
                $rateDelegation, 
                $rateCode, 
                $clientDelegation, 
                $clientCode,
                $employees,
                $departments,
                $products
            );

            // Actualizar la lista de parámetros en el campo de operaciones
            DB::connection('dynamic')->table('LABOPE')
                ->where('DEL3COD', $delegation)
                ->where('OPE1SER', $key1)
                ->where('OPE1COD', $code)
                ->update([
                    'OPECTEC' => DB::connection('dynamic')->raw("IF(OPECTEC = '', '$parameter->TECCNOM', CONCAT(OPECTEC, ';', '$parameter->TECCNOM'))")
                ]);

        } else {                
            // --- Modificación de parámetro en LABRES ---                         

            if (!empty($data['seccion_codigo'])) {  // Sólo se permite modificar la sección

                // Borra los departamentos de la operación para ser regenerados      
                DB::connection('dynamic')->table('LABOYD')
                    ->where('OPE3DEL', $delegation)
                    ->where('OPE3SER', $key1)
                    ->where('OPE3COD', $code)
                    ->delete();

                // Lee la seccion de los parámetros
                $parameters = DB::connection('dynamic')->table('LABRES')
                    ->join('LABSEC', function($join) {
                        $join->on('LABRES.SEC2DEL', '=', 'LABSEC.DEL3COD')
                            ->on('LABRES.SEC2COD', '=', 'LABSEC.SEC1COD');
                    })       
                    ->select('LABRES.TEC3DEL', 'LABRES.TEC3COD', 'LABSEC.DEP2DEL', 'LABSEC.DEP2COD')     
                    ->where('OPE3DEL', $delegation)
                    ->where('OPE3SER', $key1)
                    ->where('OPE3COD', $code)
                    ->get();

                // Obtiene el departamento de la sección modificada
                $section = DB::connection('dynamic')->table('LABSEC')
                    ->where('DEL3COD', $data['seccion_delegacion'])
                    ->where('SEC1COD', $data['seccion_codigo'])
                    ->first();
                
                // Construye una lista con las secciones de los parámetros, salvo el de entrada que entra el modificado
                $rowsToInsert = collect();

                foreach ($parameters as $parameter) {

                    // Para la técnica modificada no se considera la sección de la tabla
                    if ($parameter->TEC3DEL === $key3 && $parameter->TEC3COD === $key2) {
                        $departmentDelegation =$section->DEP2DEL ?? '';
                        $departmentCode = $section->DEP2COD ?? 0;
                    } else {
                        $departmentDelegation =$parameter->DEP2DEL ?? '';
                        $departmentCode = $parameter->DEP2COD ?? 0;
                    }

                    if ($departmentCode !== 0) {
                        $item = [
                            'OPE3DEL' => $delegation,
                            'OPE3SER' => $key1,
                            'OPE3COD' => $code,
                            'DEP3DEL' => $departmentDelegation,
                            'DEP3COD' => $departmentCode,
                        ];

                        $rowsToInsert->push($item);
                    }
                }

                $rowsToInsert = $rowsToInsert->unique(function ($item) {
                    return $item['OPE3DEL'] . $item['OPE3SER'] . $item['OPE3COD'] . $item['DEP3DEL'] . $item['DEP3COD'];
                });

                // Inserta en LABOYD
                DB::connection('dynamic')->table('LABOYD')->insert($rowsToInsert->toArray());            
            }
        }

        return $data;
    }    

}