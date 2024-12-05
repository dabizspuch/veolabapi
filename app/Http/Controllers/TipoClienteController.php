<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class TipoClienteController extends BaseController
{
    protected $table = 'SINTIC';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'TIC1COD';    
    protected $inactiveField = 'TICBBAJ';
    protected $searchFields = ['TICCDES'];
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'TIC1COD',
        'descripcion'                   => 'TICCDES',
        'es_baja'                       => 'TICBBAJ'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:50',
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

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción de tipo de cliente no esté en uso
        if (!empty($data['descripcion'])) {
            
            $existingRecord = DB::table('SINTIC')->where('TICCDES', $data['descripcion']);                            
            
            if (!$isCreating) {
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('TIC1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });
            }                          
            
            $existingRecord = $existingRecord->first();
            
            if ($existingRecord) {
                throw new \Exception("La descripción del tipo de cliente ya está en uso");
            }
        } 

        // Comprueba que el código para el nuevo tipo de cliente no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('SINTIC')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('TIC1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de tipo de cliente ya está en uso");
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

        // Comprueba que no esté referenciado en clientes
        $usedInAnotherTable = DB::table('SINCLI')
            ->where('TIC2DEL', $delegation)
            ->where('TIC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El tipo de cliente no puede ser eliminado porque está siendo referenciado en clientes");
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