<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class MatrizController extends BaseController
{
    protected $table = 'LABMAT';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'MAT1COD';    
    protected $inactiveField = 'MATBBAJ';
    protected $searchFields = ['MATCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'MAT1COD',
        'descripcion'                   => 'MATCDES',
        'es_baja'                       => 'MATBBAJ',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:255',
            'es_baja'                   => 'nullable|string|int:T,F|max:1',
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

        // Comprueba que la descripcón de la sección no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::connection('dynamic')->table('LABMAT')->where('MATCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripicón no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('MAT1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripcón de la matriz ya está en uso");
            }
        }

        // Comprueba que el código para la nueva sección no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABMAT')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('MAT1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la matriz ya está en uso");
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
        // Operaciones
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOPE')
            ->where('MAT2DEL', $delegation)
            ->where('MAT2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La matriz no puede ser eliminada porque está siendo referenciada en alguna operación");
        }

        // Planificaciones
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPLO')
            ->where('MAT2DEL', $delegation)
            ->where('MAT2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La matriz no puede ser eliminada porque está siendo referenciada en alguna planificación");
        }

        // Servicios
        $usedInAnotherTable = DB::connection('dynamic')->table('LABSER')
            ->where('MAT2DEL', $delegation)
            ->where('MAT2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La matriz no puede ser eliminada porque está siendo referenciada en alguna servicio");
        }

        // Cartas de control
        $usedInAnotherTable = DB::connection('dynamic')->table('LABCDC')
            ->where('MAT2DEL', $delegation)
            ->where('MAT2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La matriz no puede ser eliminada porque está siendo referenciada en alguna carta de control");
        }
        
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra vínculos con técnicas
        DB::connection('dynamic')->table('LABTYM')
            ->where('DEL3MAT', $delegation)
            ->where('MAT3COD', $code)
            ->delete();    
            
        // Borra vínculos con tipos de operaciones
        DB::connection('dynamic')->table('LABOYM')
            ->where('DEL3MAT', $delegation)
            ->where('MAT3COD', $code)
            ->delete();             
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}