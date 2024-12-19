<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class NormativaController extends BaseController
{
    protected $table = 'LABNOR';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'NOR1COD';    
    protected $inactiveField = 'NORBBAJ';
    protected $searchFields = ['NORCDES', 'NORCABR', 'NORCOBS'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'NOR1COD',
        'descripcion'                   => 'NORCDES',
        'abreviatura'                   => 'NORCABR',
        'observaciones'                 => 'NORCOBS',
        'es_desglose'                   => 'NORBPDT',        
        'fecha_baja'                    => 'NORDBAJ',        
        'es_baja'                       => 'NORBBAJ',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|string|max:20',
            'descripcion'               => 'nullable|string',
            'abreviatura'               => 'nullable|string|max:50',
            'observaciones'             => 'nullable|string',
            'es_desglose'               => 'nullable|string|in:T,F|max:1',
            'fecha_baja'                => 'nullable|date',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
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

        // Comprueba que el código para la nueva normativa no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('LABNOR')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('NOR1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de normativa ya está en uso");
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
        // Informes
        $usedInAnotherTable = DB::table('LABINF')
            ->where('NOR2DEL', $delegation)
            ->where('NOR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La normativa no puede ser eliminada porque está siendo referenciado en algún informe");
        }

        // Servicios
        $usedInAnotherTable = DB::table('LABSER')
            ->where('NOR2DEL', $delegation)
            ->where('NOR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La normativa no puede ser eliminada porque está siendo referenciada en algún servicio");
        }

        // Valores de normativa por parámetro
        $usedInAnotherTable = DB::table('LABTYN')
            ->where('NOR3DEL', $delegation)
            ->where('NOR3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La normativa no puede ser eliminada porque está siendo referenciada en algún parámetro");
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