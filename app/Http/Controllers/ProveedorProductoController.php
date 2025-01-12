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
        'proveedor_delegacion'          => 'PRO3DEL',
        'proveedor_codigo'              => 'PRO3COD',
        'producto_delegacion'           => 'PRD3DEL',
        'producto_codigo'               => 'PRD3COD',
        'referencia'                    => 'PYPCREF',
        'precio'                        => 'PYPNPRE',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'proveedor_delegacion'      => 'nullable|string|max:10',
            'proveedor_codigo'          => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
            'producto_delegacion'       => 'nullable|string|max:10',
            'producto_codigo'           => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
            'referencia'                => 'nullable|string|max:30',
            'precio'                    => 'nullable|numeric|min:0',
        ];
        

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del proveedor 
        if (!empty($data['proveedor_codigo'])) {
            $analyst = DB::connection('dynamic')->table('SINPRO')
                ->where('DEL3COD', $data['proveedor_delegacion'] ?? '')
                ->where('PRO1COD', $data['proveedor_codigo'])
                ->first(); 
            if (!$analyst) {
                throw new \Exception("El proveedor no existe");
            }
        }        

        // Valida la existencia del producto 
        if (!empty($data['producto_codigo'])) {
            $order = DB::connection('dynamic')->table('ALMPRD')
                ->where('DEL3COD', $data['producto_delegacion'] ?? '')
                ->where('PRD1COD', $data['producto_codigo'])
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
            $exist = DB::connection('dynamic')->table('ALMPYP')
                ->where('PRO3DEL', $data['proveedor_delegacion'] ?? '')
                ->where('PRO3COD', $data['proveedor_codigo'])
                ->where('PRD3DEL', $data['producto_delegacion'] ?? '')
                ->where('PRD3COD', $data['producto_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El proveedor y el producto ya estaban asociados");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['proveedor_delegacion'], 
                $data['proveedor_codigo'], 
                $data['producto_delegacion'], 
                $data['producto_codigo'], 
            );            
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