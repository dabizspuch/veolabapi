<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class GastosController extends BaseController
{
    protected $table = 'LABESC';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'ESC1COD';    
    protected $inactiveField = 'ESCBBAJ';
    protected $searchFields = ['ESCCDES', 'ESCCOBS'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'ESC1COD',
        'descripcion'                   => 'ESCCDES',
        'observaciones'                 => 'ESCCOBS',
        'es_suplido'                    => 'ESCBSUP',        
        'precio'                        => 'ESCNPRE',        
        'descuento'                     => 'ESCCDTO',        
        'fecha_baja'                    => 'ESCDBAJ',        
        'es_baja'                       => 'ESCBBAJ',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:100',
            'observaciones'             => 'nullable|string',
            'es_suplido'                => 'nullable|string|in:T,F|max:1',
            'precio'                    => 'nullable|numeric',
            'descuento'                 => 'nullable|string|max:15',
            'fecha_baja'                => 'nullable|date',
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

        // Comprueba que la descripción del gasto no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::connection('dynamic')->table('LABESC')->where('ESCCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('ESC1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripción del gasto ya está en uso");
            }
        }        

        // Comprueba que el código para la nueva normativa no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABESC')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('ESC1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de gasto ya está en uso");
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
        // Planificaciones
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPYG')
            ->where('ESC3DEL', $delegation)
            ->where('ESC3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El gasto no puede ser eliminado porque está siendo referenciado en alguna planificación");
        }
       
        // Operaciones
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOYG')
            ->where('ESC3DEL', $delegation)
            ->where('ESC3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El gasto no puede ser eliminado porque está siendo referenciado en alguna operación");
        }      
        
        // Servicios
        $usedInAnotherTable = DB::connection('dynamic')->table('LABSYE')
            ->where('DEL3ESC', $delegation)
            ->where('ESC3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El gasto no puede ser eliminado porque está siendo referenciado en algún servicio");
        }    
        
        // Líneas de factura
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIF')
            ->where('ESC2DEL', $delegation)
            ->where('ESC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El gasto no puede ser eliminado porque está siendo referenciado en alguna factura");
        }
        
        // Líneas de contratos
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIC')
            ->where('ESC2DEL', $delegation)
            ->where('ESC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El gasto no puede ser eliminado porque está siendo referenciado en algún contrato");
        } 
        
        // Líneas de presupuesto
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIP')
            ->where('ESC2DEL', $delegation)
            ->where('ESC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El gasto no puede ser eliminado porque está siendo referenciado en algún presupuesto");
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