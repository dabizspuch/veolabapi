<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class FamiliaController extends BaseController
{
    protected $table = 'ALMFAM';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'FAM1COD';    
    protected $searchFields = ['FAMCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'FAM1COD',
        'descripcion'                   => 'FAMCDES',
        'familia_padre_delegacion'      => 'FAM2DEL',
        'familia_padre_codigo'          => 'FAM2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:255',
            'familia_padre_delegacion'  => 'nullable|string|max:10',
            'familia_padre_codigo'      => 'nullable|integer',
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

        // Valida la existencia de la familia padre
        if (!empty($data['familia_padre_codigo'])) {
            $family = DB::table('ALMFAM')
                ->where('DEL3COD', $data['familia_padre_delegacion'] ?? '')
                ->where('FAM1COD', $data['familia_padre_codigo'])
                ->first(); 
            if (!$family) {
                throw new \Exception("La familia padre no existe");
            }
        }       
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción de la familia no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::table('ALMFAM')->where('FAMCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('FAM1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripción de la familia ya está en uso");
            }
        }

        // Comprueba que el código para la nueva familia no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('ALMFAM')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('FAM1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la familia ya está en uso");
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
        $delegation = $delegation ?? '';

        // Comprueba que la familia no tenga hijos
        $usedInAnotherTable = DB::table('ALMFAM')
            ->where('FAM2DEL', $delegation)
            ->where('FAM2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La familia no puede ser eliminada porque contiene subfamilias");
        }     
        
        // Comprueba que la familia no está vinculada a algún producto
        $usedInAnotherTable = DB::table('ALMPRD')
            ->where('FAM2DEL', $delegation)
            ->where('FAM2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La familia no puede ser eliminada porque está vinculada a algún producto");
        }         
    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se requiere borrar tablas relacionadas
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}