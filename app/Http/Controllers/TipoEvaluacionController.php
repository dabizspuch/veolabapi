<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class TipoEvaluacionController extends BaseController
{
    protected $table = 'SINTIE';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'TIE1COD';    
    protected $inactiveField = 'TIEBBAJ';
    protected $searchFields = ['TIECDES'];

    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'TIE1COD',
        'descripcion'                   => 'TIECDES',
        'es_baja'                       => 'TIEBBAJ'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:100',
            'es_baja'                   => 'nullable|string|in:T,F|max:1'
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
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción de tipo de cliente no esté en uso
        if (!empty($data['descripcion'])) {
            
            $existingRecord = DB::table('SINTIE')->where('TIECDES', $data['descripcion']);                            
            
            if (!$isCreating) {
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('TIE1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });
            }                          
            
            $existingRecord = $existingRecord->first();
            
            if ($existingRecord) {
                throw new \Exception("La descripción del tipo de evaluación ya está en uso");
            }
        } 

        // Comprueba que el código para el nuevo tipo de cliente no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('SINTIE')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('TIE1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de tipo de evaluación ya está en uso");
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

        // Comprueba que no esté referenciado en proveedores
        $usedInAnotherTable = DB::table('SINPRO')
            ->where('TIE2DEL', $delegation)
            ->where('TIE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El tipo de evaluación no puede ser eliminado porque está siendo referenciado en proveedores");
        }
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se requiere borrar ningún registro de tablas relacionadas
    }

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    
    
}