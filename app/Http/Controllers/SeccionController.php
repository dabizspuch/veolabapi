<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class SeccionController extends BaseController
{
    protected $table = 'LABSEC';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'SEC1COD';    
    protected $inactiveField = 'SECBBAJ';
    protected $searchFields = ['SECCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'SEC1COD',
        'descripcion'                   => 'SECCDES',
        'icono'                         => 'SECNICO',
        'posicion'                      => 'SECNORD',
        'es_baja'                       => 'SECBBAJ',
        'departamento_delegacion'       => 'DEP2DEL',
        'departamento_codigo'           => 'DEP2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:100',
            'icono'                     => 'nullable|integer',
            'posicion'                  => 'nullable|integer',
            'es_baja'                   => 'nullable|string|int:T,F|max:1',
            'departamento_delegacion'   => 'nullable|string|max:10',
            'departamento_codigo'       => 'nullable|integer',
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

        // Valida la existencia del departamento 
        if (!empty($data['departamento_codigo'])) {
            $department = DB::connection('dynamic')->table('GRHDEP')
                ->where('DEL3COD', $data['departamento_delegacion'] ?? '')
                ->where('DEP1COD', $data['departamento_codigo'])
                ->first(); 
            if (!$department) {
                throw new \Exception("El departamento no existe");
            }
        }         
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripcón de la sección no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::connection('dynamic')->table('LABSEC')->where('SECCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripicón no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('SEC1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripcón de la sección ya está en uso");
            }
        }

        // Comprueba que el código para la nueva sección no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABSEC')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('SEC1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la sección ya está en uso");
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
        // Parámetros
        $usedInAnotherTable = DB::connection('dynamic')->table('LABTEC')
            ->where('SEC2DEL', $delegation)
            ->where('SEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La sección no puede ser eliminada porque está siendo referenciada en algún parámetro");
        }

        // Resultados
        $usedInAnotherTable = DB::connection('dynamic')->table('LABRES')
            ->where('SEC2DEL', $delegation)
            ->where('SEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La sección no puede ser eliminada porque está siendo referenciada en algún resultado");
        }

        // Líneas de factura
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIF')
            ->where('SEC2DEL', $delegation)
            ->where('SEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La sección no puede ser eliminada porque está siendo referenciada en alguna factura");
        }

        // Líneas de presupuesto
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIC')
            ->where('SEC2DEL', $delegation)
            ->where('SEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La sección no puede ser eliminada porque está siendo referenciada en algún contrato");
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