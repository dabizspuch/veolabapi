<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ServicioController extends BaseController
{
    protected $table = 'LABSER';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'SER1COD';    
    protected $inactiveField = 'SERBBAJ';
    protected $searchFields = ['SERCNOM', 'SERCNOI', 'SERCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'SER1COD',
        'nombre'                        => 'SERCNOM',
        'nombre_informes'               => 'SERCNOI',
        'id_igeo'                       => 'SERCIGC',
        'descripcion'                   => 'SERCDES',
        'observaciones'                 => 'SERCOBS',
        'objetivo'                      => 'SERCOBJ',
        'numero_envases'                => 'SERNENV',
        'cantidad'                      => 'SERCCAN',
        'precio'                        => 'SERNPRE',
        'descuento'                     => 'SERCDTO',
        'tiempo_prueba'                 => 'SERNTIE',
        'tipo_dia'                      => 'SERCTDI',
        'es_titulo_unico'               => 'SERBTUC',
        'fecha_baja'                    => 'SERDBAJ',
        'es_baja'                       => 'SERBBAJ',
        'tipo_operacion_delegacion'     => 'TIO2DEL',
        'tipo_operacion_codigo'         => 'TIO2COD',
        'matriz_delegacion'             => 'MAT2DEL',
        'matriz_codigo'                 => 'MAT2COD',
        'normativa_delegacion'          => 'NOR2DEL',
        'normativa_codigo'              => 'NOR2COD'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|string|max:20',
            'nombre'                    => 'nullable|string|max:100',
            'nombre_informes'           => 'nullable|string|max:100',
            'id_igeo'                   => 'nullable|string|max:20',
            'descripcion'               => 'nullable|string',
            'observaciones'             => 'nullable|string',
            'objetivo'                  => 'nullable|string',
            'numero_envases'            => 'nullable|numeric',
            'cantidad'                  => 'nullable|string|max:50',
            'precio'                    => 'nullable|numeric',
            'descuento'                 => 'nullable|string|max:15',
            'tiempo_prueba'             => 'nullable|integer',
            'tipo_dia'                  => 'nullable|string|in:L,N|max:1',
            'es_titulo_unico'           => 'nullable|string|in:T,F|max:1',
            'fecha_baja'                => 'nullable|date',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'tipo_operacion_delegacion' => 'nullable|string|max:10',
            'tipo_operacion_codigo'     => 'nullable|integer',
            'matriz_delegacion'         => 'nullable|string|max:10',
            'matriz_codigo'             => 'nullable|integer',
            'normativa_delegacion'      => 'nullable|string|max:10',
            'normativa_codigo'          => 'nullable|string|max:20'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {    
        // Valida la existencia de la delegación 
        if (!empty($data['delegacion'])) {
            $delegation = DB::connection('dynamic')->table('ACCDEL')
                ->where('DEL1COD', $data['delegacion'])
                ->first(); 
            if (!$delegation) {
                throw new \Exception("La delegación no existe");
            }
        }

        // Valida la existencia de la matriz 
        if (!empty($data['matriz_codigo'])) {
            $matrix = DB::connection('dynamic')->table('LABMAT')
                ->where('DEL3COD', $data['matriz_delegacion'] ?? '')
                ->where('MAT1COD', $data['matriz_codigo'])
                ->first(); 
            if (!$matrix) {
                throw new \Exception("La matriz no existe");
            }
        } 
        
        // Valida la existencia del tipo de operación 
        if (!empty($data['tipo_operacion_codigo'])) {
            $type = DB::connection('dynamic')->table('LABTIO')
                ->where('DEL3COD', $data['tipo_operacion_delegacion'] ?? '')
                ->where('TIO1COD', $data['tipo_operacion_codigo'])
                ->first(); 
            if (!$type) {
                throw new \Exception("El tipo de operación no existe");
            }
        }  
        
        // Valida la existencia de la normativa
        if (!empty($data['normativa_codigo'])) {
            $regulation = DB::connection('dynamic')->table('LABNOR')
                ->where('DEL3COD', $data['normativa_delegacion'] ?? '')
                ->where('NOR1COD', $data['normativa_codigo'])
                ->first(); 
            if (!$regulation) {
                throw new \Exception("La normativa no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre del servicio no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::connection('dynamic')->table('LABSER')->where('SERCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('SER1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del servicio ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo servicio no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABSER')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('SER1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del servicio ya está en uso");
                }
            }
        }

        // Excluir campos clave de los datos a actualizar porque no serán editables
        if (!$isCreating) { 
            unset( 
                $data['delegacion'], 
                $data['codigo'] 
            );
        } 
                
        return $data;        
    }
        
    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Servicios vinculados a plantilla
        $usedInAnotherTable = DB::connection('dynamic')->table('PLAPYS')
            ->where('DEL3SER', $delegation)
            ->where('SER3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna plantilla");
        }

        // Servicios vinculados a planificaciones
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPYS')
            ->where('SER3DEL', $delegation)
            ->where('SER3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna planificación");
        } 
        
        // Servicios vinculados a planificaciones (técnicas)
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPYT')
            ->where('SER2DEL', $delegation)
            ->where('SER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna planificación");
        }            

        // Servicios vinculados a planificaciones (gastos)
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPYG')
            ->where('SER2DEL', $delegation)
            ->where('SER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna planificación");
        }            
        
        // Servicios vinculados a operaciones
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOYS')
            ->where('SER3DEL', $delegation)
            ->where('SER3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna operación");
        }  
        
        // Servicios vinculados a resultados
        $usedInAnotherTable = DB::connection('dynamic')->table('LABRES')
            ->where('SER2DEL', $delegation)
            ->where('SER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna operación");
        }            
        
        // Servicios vinculados a resultados
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOYG')
            ->where('SER2DEL', $delegation)
            ->where('SER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna operación");
        }          
        
        // Servicios vinculados a líneas de factura
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIF')
            ->where('SER2DEL', $delegation)
            ->where('SER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna línea de factura");
        }     
        
        // Servicios vinculados a líneas de contrato
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIC')
            ->where('SER2DEL', $delegation)
            ->where('SER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna línea de contrato");
        }         

        // Servicios vinculados a líneas de presupuesto
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIP')
            ->where('SER2DEL', $delegation)
            ->where('SER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El servicio no puede ser eliminado porque está siendo referenciado en alguna línea de presupuesto");
        }         
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra los precios por cliente
        DB::connection('dynamic')->table('LABSYC')
            ->where('SER3DEL', $delegation)
            ->where('SER3COD', $code)
            ->delete();

        // Borra los precios por tarifa
        DB::connection('dynamic')->table('LABSYF')
            ->where('SER3DEL', $delegation)
            ->where('SER3COD', $code)
            ->delete();            

        // Borra los gastos asociados al servicio
        DB::connection('dynamic')->table('LABSYE')
            ->where('DEL3SER', $delegation)
            ->where('SER3COD', $code)
            ->delete();

        // Borra las técnicas asociadas al servicio
        DB::connection('dynamic')->table('LABSYT')
            ->where('DEL3SER', $delegation)
            ->where('SER3COD', $code)
            ->delete();      
            
        // Borra las asociación con autodefinibles
        DB::connection('dynamic')->table('LABAYS')
            ->where('SER3DEL', $delegation)
            ->where('SER3COD', $code)
            ->delete();             
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}