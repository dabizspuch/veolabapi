<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class FormaEnvioController extends BaseController
{
    protected $table = 'LABFDE';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'FDE1COD';    
    protected $inactiveField = 'FDEBBAJ';
    protected $searchFields = ['FDECDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'FDE1COD',
        'descripcion'                   => 'FDECDES',
        'especial'                      => 'FDECESP',
        'es_baja'                       => 'FDEBBAJ',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:50',
            'especial'                  => 'nullable|string|in:N,E,P|max:1',
            'es_baja'                   => 'nullable|string|in:T,F|max:1'
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

        // Comprueba que el nombre de la forma de envío no esté en uso
        if (!empty($data['descripcion'])) {
            
            $existingRecord = DB::connection('dynamic')->table('LABFDE')->where('FDECDES', $data['descripcion']);
            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('FDE1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }

            $existingRecord = $existingRecord->first();
            
            if ($existingRecord) {
                throw new \Exception("La descripción de la forma de envío ya está en uso");
            }
        }

        // Comprueba que el código para la nueva forma de envío no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABFDE')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('FDE1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la forma de envío ya está en uso");
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

        // Comprueba que no esté referenciada en clientes
        $usedInAnotherTable = DB::connection('dynamic')->table('SINCLI')
            ->where('FDE2DEL', $delegation)
            ->where('FDE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La forma de envío no puede ser eliminada porque está siendo referenciada en clientes");
        }

        // Comprueba que no esté referenciada en informes
        $usedInAnotherTable = DB::connection('dynamic')->table('LABINF')
            ->where('FDE2DEL', $delegation)
            ->where('FDE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La forma de envío no puede ser eliminada porque está siendo referenciada en informes");
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