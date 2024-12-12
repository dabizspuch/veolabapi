<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ProveedorProductoController extends BaseController
{
    protected $table = 'ALMPYP';
    protected $delegationField = 'PRO3DEL';
    protected $codeField = 'PRO3COD';  
    protected $key1Field = 'PRD3COD';          
    protected $key2Field = 'PRD3DEL';          
    protected $skipNewCode = true;          
    
    protected $mapping = [
        'delegacion_proveedor'          => 'PRO3DEL',
        'codigo_proveedor'              => 'PRO3COD',
        'delegacion_producto'           => 'PRD3DEL',
        'codigo_producto'               => 'PRD3COD',
        'referencia'                    => 'PYPCREF',
        'precio'                        => 'PYPNPRE',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion_proveedor'      => 'nullable|string|max:10',
            'codigo_proveedor'          => 'required|string|max:15',
            'delegacion_producto'       => 'nullable|string|max:10',
            'codigo_producto'           => 'required|string|max:15',
            'referencia'                => 'nullable|string|max:30',
            'precio'                    => 'nullable|numeric|min:0',
        ];
        

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del proveedor 
        if (!empty($data['codigo_proveedor'])) {
            $analyst = DB::table('SINPRO')
                ->where('DEL3COD', $data['delegacion_proveedor'] ?? '')
                ->where('PRO1COD', $data['codigo_proveedor'])
                ->first(); 
            if (!$analyst) {
                throw new \Exception("El proveedor no existe");
            }
        }        

        // Valida la existencia del producto 
        if (!empty($data['codigo_producto'])) {
            $order = DB::table('ALMPRD')
                ->where('DEL3COD', $data['delegacion_producto'] ?? '')
                ->where('PRD1COD', $data['codigo_producto'])
                ->first(); 
            if (!$order) {
                throw new \Exception("El producto no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::table('ALMPYP')
                ->where('PRO3DEL', $data['delegacion_proveedor'] ?? '')
                ->where('PRO3COD', $data['codigo_proveedor'])
                ->where('PRD3DEL', $data['delegacion_producto'] ?? '')
                ->where('PRD3COD', $data['codigo_producto'])
                ->exists();
            if ($exist) {
                throw new \Exception("El proveedor y el producto ya estaban asociados");            
            }            
        }
        
        return $data;
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No hay restricciones previas al borrado
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