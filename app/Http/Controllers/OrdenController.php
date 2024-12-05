<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class OrdenController extends BaseController
{
    protected $table = 'LABORD';
    protected $delegationField = 'DEL3COD';
    protected $key1Field = 'ORD1SER';
    protected $codeField = 'ORD1COD';    
    protected $searchFields = ['ORDCOBS'];
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'serie'                         => 'ORD1SER',
        'codigo'                        => 'ORD1COD',
        'observaciones'                 => 'ORDCOBS',
        'fecha_creacion'                => 'ORDDCRE',
        'fecha_impresion'               => 'ORDDIMP',
        'departamento_delegacion'       => 'DEP2DEL',
        'departamento_codigo'           => 'DEP2COD',
        'tecnica_delegacion'            => 'TEC2DEL',
        'tecnica_codigo'                => 'TEC2COD'
    ];

    protected function rules()
    {
        // Determina si es una creación
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',     
            'serie'                     => 'nullable|string|max:10',     
            'codigo'                    => 'nullable|integer',           
            'observaciones'             => 'nullable|string|max:255',    
            'fecha_creacion'            => 'nullable|date',              
            'fecha_impresion'           => 'nullable|date',              
            'departamento_delegacion'   => 'nullable|string|max:10',     
            'departamento_codigo'       => 'nullable|integer',           
            'tecnica_delegacion'        => 'nullable|string|max:10',     
            'tecnica_codigo'            => 'nullable|string|max:30',
            'operaciones'               => $isCreating ? 'required|array|min:1' : 'nullable|array',
            'operaciones.*.delegacion'  => 'nullable|string|max:10',
            'operaciones.*.serie'       => 'nullable|string|max:10',
            'operaciones.*.codigo'      => 'required|integer',
            'analistas'                 => 'nullable|array',
            'analistas.*.delegacion'    => 'nullable|string|max:10',
            'analistas.*.codigo'        => 'nullable|integer'
        ];
        
        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia de la delegación 
        if (!empty($data['delegacion'])) {
            $delegation = DB::table('ACCDEL')
                ->where('DEL1COD', $data['delegacion'])
                ->first(); 
            if (!$delegation) {
                throw new \Exception("La delegación no existe");
            }
        }

        // Valida la existencia de la técnica
        if (!empty($data['tecnica_codigo'])) {
            $parameter = DB::table('LABTEC')
                ->where('DEL3COD', $data['tecnica_delegacion'] ?? '')
                ->where('TEC1COD', $data['tecnica_codigo'])
                ->first();
            if (!$parameter) {
                throw new \Exception("La técnica no existe");
            }
        }  
        
        // Valida la existencia del departamento
        if (!empty($data['departamento_codigo'])) {
            $department = DB::table('GRHDEP')
                ->where('DEL3COD', $data['departamento_delegacion'] ?? '')
                ->where('DEP1COD', $data['departamento_codigo'])
                ->first();
            if (!$department) {
                throw new \Exception("El departamento no existe");
            }
        }
        
        // Valida la existencia de las operaciones
        if (!empty($data['operaciones'])) {
            $existOperation = true;
            foreach ($data['operaciones'] as $operation) {
                $existOperation = DB::table('LABOPE')
                    ->where('DEL3COD', $operation['delegacion'])
                    ->where('OPE1SER', $operation['serie'])
                    ->where('OPE1COD', $operation['codigo'])
                    ->exists();
                if (!$existOperation) {
                    break;
                }                    
            }
            if (!$existOperation) {
                throw new \Exception("Alguna de las operaciones no existe");
            }            
        }   
        
        // Valida la existencia de los analistas
        if (!empty($data['analistas'])) {
            $existAnalista = true;
            foreach ($data['analistas'] as $analyst) {
                $existAnalista = DB::table('GRHEMP')
                    ->where('DEL3COD', $analyst['delegacion'])
                    ->where('EMP1COD', $analyst['codigo'])
                    ->where('EMPBANA', 'T')
                    ->exists();
                if (!$existAnalista) {
                    break;
                }                    
            }
            if (!$existAnalista) {
                throw new \Exception("Alguno de los analistas no existe");
            }            
        }        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {

        $isCreating = request()->isMethod('post');

        // Comprueba que el código para la nueva orden no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('LABORD')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('ORD1SER', $data['serie'] ?? '')
                    ->where('ORD1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la orden ya está en uso");
                }
            }
        }

        // Excluir campos clave de los datos a actualizar porque no serán editables        
        if (!$isCreating) { 
            unset(
                $data['delegacion'], 
                $data['serie'], 
                $data['codigo']
            ); 
        }

        return $data;               
    }

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No hay restricciones respecto a otras tablas para poder borrar órdenes
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Renumera posición de órdenes con operacione relacionadas
        $this->renumPositionsInOrder($delegation, $key1, $code);

        // Borra la relación con operaciones
        DB::table('LABOYO')
            ->where('ORD3DEL', $delegation)
            ->where('ORD3SER', $key1)
            ->where('ORD3COD', $code)
            ->delete();   
        
        // Borra la lista de empleados
        DB::table('LABORE')
            ->where('ORD3DEL', $delegation)
            ->where('ORD3SER', $key1)
            ->where('ORD3COD', $code)
            ->delete();
            
        // Documentos a la papelera
        DB::table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('ORD2SER', $key1)
            ->where('ORD2COD', $code)
            ->update([
                'DIR2DEL' => $delegation,
                'DIR2COD' => 0
            ]);              
    }

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Operaciones relacionadas con la orden
        if (isset($data['operaciones'])) {         

            $operationMaxPositions = $this->getOperationMaxPositions($data, $delegation, $key1, $code);

            if (!request()->isMethod('post')) {     
                // Reasigna posiciones de tipo 1 en las operaciones       
                $this->reassignPositionAfterDeletion($operationMaxPositions, $delegation, $key1, $code);

                // Borra la asociación previa de operaciones
                DB::table('LABOYO')
                    ->where('ORD3DEL', $delegation)
                    ->where('ORD3SER', $key1)
                    ->where('ORD3COD', $code)  
                    ->delete(); 
            }
                
            // Crea la nueva asociación de operaciones
            foreach ($data['operaciones'] as $operation) {                                 
                $position = $operationMaxPositions[$operation['delegacion']][$operation['serie']][$operation['codigo']] ?? 0;                
                DB::table('LABOYO')->insert([
                    'ORD3DEL' => $delegation,
                    'ORD3SER' => $key1,
                    'ORD3COD' => $code,
                    'OPE3DEL' => $operation['delegacion'],
                    'OPE3SER' => $operation['serie'],
                    'OPE3COD' => $operation['codigo'],
                    'OYONPOS' => $position + 1
                ]);           
            }
        }

        // Analistas
        if (isset($data['analistas'])) {
            // Borra la asociación previa de analistas
            DB::table('LABORE')
                ->where('ORD3DEL', $delegation)
                ->where('ORD3SER', $key1)
                ->where('ORD3COD', $code)  
                ->delete(); 

            // Crea la nueva asociación de analistas
            foreach ($data['analistas'] as $analyst) {
                DB::table('LABORE')->insert([
                    'ORD3DEL' => $delegation,
                    'ORD3SER' => $key1,
                    'ORD3COD' => $code,
                    'EMP3DEL' => $analyst['delegacion'],
                    'EMP3COD' => $analyst['codigo']
                ]);
            }
        }

        return $data;
    }    

    /**
     * Construye una colección con los valores de posición máximo para cada operación sin considerar la orden actual
     * @param array $data - Datos que contienen las operaciones, estructurados como un arreglo.
     * @param string $orderDelegation - La delegación de la orden que se va a procesar.
     * @param string $orderSeries - La serie de la orden que se va a procesar.
     * @param int $orderCode - El código de la orden que se va a procesar.
     * @return array - Una colección con las posiciones máximas de las operaciones, indexadas por delegación, serie y código.
     */
    private function getOperationMaxPositions(array $data, string $orderDelegation, string $orderSeries, int $orderCode) 
    {
        $result = [];
        foreach ($data['operaciones'] as $operation) {  
            $maxPos = DB::table('LABOYO')
                ->where('OPE3DEL', $operation['delegacion'])
                ->where('OPE3SER', $operation['serie'])
                ->where('OPE3COD', $operation['codigo'])
                ->where(function ($query) use ($orderDelegation, $orderSeries, $orderCode) {
                    $query->where('ORD3DEL', '<>', $orderDelegation)
                            ->orWhere('ORD3SER', '<>', $orderSeries)
                            ->orWhere('ORD3COD', '<>', intval($orderCode));
                })
                ->max('OYONPOS');
            $result[$operation['delegacion']][$operation['serie']][$operation['codigo']] = $maxPos ?? 0;
        }
        return $result;   
    }

    /**
     * Reasigna la posición de las operaciones de la operación de entrada que serán borradas si alguna es 1
     * @param array &$operationMaxPositions Las posiciones máximas de las operaciones, pasadas por referencia para ser modificadas.
     * @param string $orderDelegation La delegación de la orden que se va a procesar.
     * @param string $orderSeries La serie de la orden que se va a procesar.
     * @param int $orderCode El código de la orden que se va a procesar.
     * @return void
     */
    private function reassignPositionAfterDeletion(
        array &$operationMaxPositions, 
        string $orderDelegation, 
        string $orderSeries, 
        int $orderCode
    ) {
        // Lee las operaciones de la orden actual
        $operations = DB::table('LABOYO')
            ->where('ORD3DEL', $orderDelegation)
            ->where('ORD3SER', $orderSeries)
            ->where('ORD3COD', $orderCode)
            ->get();

        if ($operations->isNotEmpty()) {
            foreach ($operations as $operation) {

                // Si alguna operación operación que va a ser borrada tenía la posición 1 
                if ($operation->OYONPOS == 1) {
                    
                    // Busca el nuevo sustituto que tendrá el 1 como posición
                    $minPosition = DB::table('LABOYO')
                        ->where('OPE3DEL', $operation->OPE3DEL)
                        ->where('OPE3SER', $operation->OPE3SER)
                        ->where('OPE3COD', $operation->OPE3COD)
                        ->where(function ($query) use ($orderDelegation, $orderSeries, $orderCode) {
                            $query->where('ORD3DEL', '<>', $orderDelegation)
                                ->orWhere('ORD3SER', '<>', $orderSeries)
                                ->orWhere('ORD3COD', '<>', intval($orderCode));
                        })
                        ->min('OYONPOS');

                    // Si lo encuentra lo actualiza con 1 en la base de datos y en la colección
                    if ($minPosition > 1) {
                        DB::table('LABOYO')
                            ->where('OPE3DEL', $operation->OPE3DEL)
                            ->where('OPE3SER', $operation->OPE3SER)
                            ->where('OPE3COD', $operation->OPE3COD)
                            ->where('OYONPOS', $minPosition)
                            ->update(['OYONPOS' => 1]);

                        if (isset($operationMaxPositions[$operation->OPE3DEL][$operation->OPE3SER][$operation->OPE3COD])) {
                            $maxRecorded = $operationMaxPositions[$operation->OPE3DEL][$operation->OPE3SER][$operation->OPE3COD];
                            if ($maxRecorded == $minPosition) {
                                $operationMaxPositions[$operation->OPE3DEL][$operation->OPE3SER][$operation->OPE3COD] = 1; 
                            }
                        }
                    }
                }  
            }                  
        }
    }
    
    /**
     * Renumera las posiciones en las órdenes relacionadas para las operacioes de la orden de entrada
     * @param string $orderDelegation - Delegación de la orden
     * @param string $orderSeries - Serie de la orden
     * @param int $orderCode - Código de la orden
     * @return void
     */
    private function renumPositionsInOrder(string $orderDelegation, string $orderSeries, int $orderCode)
    {
        $operations = DB::table('LABOYO')
            ->where('ORD3DEL', $orderDelegation)
            ->where('ORD3SER', $orderSeries)
            ->where('ORD3COD', $orderCode)
            ->get(); 

        foreach ($operations as $operation) {
            $minPosition = DB::table('LABOYO')
                ->where('OPE3DEL', $operation->OPE3DEL)
                ->where('OPE3SER', $operation->OPE3SER)
                ->where('OPE3COD', $operation->OPE3COD)
                ->where(function ($query) use ($orderDelegation, $orderSeries, $orderCode) {
                    $query->where('ORD3DEL', '<>', $orderDelegation)
                          ->orWhere('ORD3SER', '<>', $orderSeries)
                          ->orWhere('ORD3COD', '<>', intval($orderCode));
                })                
                ->min('OYONPOS');

            if ($minPosition > 1) {
                DB::table('LABOYO')
                    ->where('OPE3DEL', $operation->OPE3DEL)
                    ->where('OPE3SER', $operation->OPE3SER)
                    ->where('OPE3COD', $operation->OPE3COD)
                    ->where('OYONPOS', $minPosition)
                    ->update(['OYONPOS' => 1]);
            }     
        }        
    }     
}