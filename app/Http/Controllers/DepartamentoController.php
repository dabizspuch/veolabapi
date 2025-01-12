<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class DepartamentoController extends BaseController
{
    protected $table = 'GRHDEP';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'DEP1COD';    
    protected $inactiveField = 'DEPBBAJ';
    protected $searchFields = ['DEPCNOM'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'DEP1COD',
        'nombre'                        => 'DEPCNOM',
        'es_baja'                       => 'DEPBBAJ',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'nombre'                    => 'nullable|string|max:50',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
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
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre del departamento no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::connection('dynamic')->table('GRHDEP')->where('DEPCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('DEP1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del departamento ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo departamento no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('GRHDEP')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('DEP1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del departamento ya está en uso");
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
        // Comprueba que el departamento no está vinculado a ningún cargo
        $usedInAnotherTable = DB::connection('dynamic')->table('GRHCAR')
            ->where('DEP2DEL', $delegation)
            ->where('DEP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El departamento no puede ser eliminado porque está siendo referenciado en algún cargo");
        }  
        
        // Comprueba que el departamento no está vinculado a ningún currículum 
        $usedInAnotherTable = DB::connection('dynamic')->table('GRHCUR')
            ->where('DEP2DEL', $delegation)
            ->where('DEP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El departamento no puede ser eliminado porque está siendo referenciado en algún currículum");
        }      
        
        // Comprueba que el departamento no está vinculado a ninguna orden 
        $usedInAnotherTable = DB::connection('dynamic')->table('LABORD')
            ->where('DEP2DEL', $delegation)
            ->where('DEP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El departamento no puede ser eliminado porque está siendo referenciado en alguna orden");
        }         

        // Comprueba que el departamento no está vinculado a ninguna sección 
        $usedInAnotherTable = DB::connection('dynamic')->table('LABSEC')
            ->where('DEP2DEL', $delegation)
            ->where('DEP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El departamento no puede ser eliminado porque está siendo referenciado en alguna sección");
        }      
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se requiere el borrado de tablas adicionales
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}