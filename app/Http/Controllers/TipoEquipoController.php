<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class TipoEquipoController extends BaseController
{
    protected $table = 'LABTEQ';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'TEQ1COD';    
    protected $searchFields = ['TEQCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'TEQ1COD',
        'descripcion'                   => 'TEQCDES',
        'tipo_equipo_delegacion'        => 'TEQ2DEL',        
        'tipo_equipo_codigo'            => 'TEQ2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:100',
            'tipo_equipo_delegacion'    => 'nullable|string|max:10',
            'tipo_equipo_codigo'        => 'nullable|integer',
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

        // Valida la existencia del tipo de equipo padre 
        if (!empty($data['tipo_equipo_codigo'])) {
            $type = DB::connection('dynamic')->table('LABTEQ')
                ->where('DEL3COD', $data['tipo_equipo_delegacion'] ?? '')
                ->where('TEQ1COD', $data['tipo_equipo_codigo'])
                ->first(); 
            if (!$type) {
                throw new \Exception("El tipo de equipo no existe");
            }
        }         
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción del gasto no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::connection('dynamic')->table('LABTEQ')->where('TEQCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('TEQ1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripción del tipo de equipo ya está en uso");
            }
        }        

        // Comprueba que el código para la nueva normativa no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABTEQ')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('TEQ1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del tipo de equipo ya está en uso");
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
        // Equipos
        $usedInAnotherTable = DB::connection('dynamic')->table('LABEQU')
            ->where('TEQ2DEL', $delegation)
            ->where('TEQ2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El tipo de equipo no puede ser eliminado porque está siendo referenciado en algún equipo");
        }
       
        // Tipos de equipo
        $usedInAnotherTable = DB::connection('dynamic')->table('LABTEQ')
            ->where('TEQ2DEL', $delegation)
            ->where('TEQ2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El tipo de equipo no puede ser eliminado porque está siendo referenciado en otro tipo de equipo");
        }              
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {            
        // No se requiere borrado en cascada
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}