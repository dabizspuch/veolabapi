<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroEquipoController extends BaseController
{
    protected $table = 'LABTYQ';
    protected $delegationField = 'TEC3DEL';
    protected $codeField = 'TEC3COD';   
    protected $key1Field = 'PRD3COD';
    protected $key2Field = 'PRD3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'parametro_delegacion'          => 'TEC3DEL',
        'parametro_codigo'              => 'TEC3COD',
        'producto_delegacion'           => 'PRD3DEL',
        'producto_codigo'               => 'PRD3COD',
        'formato_importacion'           => 'TYQNFOR',
        'nombre_importacion'            => 'TYQCNOM',
        'columnas'                      => 'TYQCCOL',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'parametro_delegacion'        => 'nullable|string|max:10',
            'parametro_codigo'            => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'producto_delegacion'         => 'nullable|string|max:10',
            'producto_codigo'             => $isCreating ? 'required|string|max:20' : 'nullable|string|max:20',
            'formato_importacion'         => 'nullable|integer',
            'nombre_importacion'          => 'nullable|string|max:150',
            'columnas'                    => 'nullable|string|max:30',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del parámetro 
        if (!empty($data['parametro_codigo'])) {
            $parameter = DB::table('LABTEC')
                ->where('DEL3COD', $data['parametro_delegacion'] ?? '')
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("El parámetro no existe");
            }
        }    
        
        // Valida la existencia del producto 
        if (!empty($data['producto_codigo'])) {
            $employee = DB::table('ALMPRD')
                ->where('DEL3COD', $data['producto_delegacion'] ?? '')
                ->where('PRD1COD', $data['producto_codigo'])
                ->where('PRDBEQU', 'T')
                ->first(); 
            if (!$employee) {
                throw new \Exception("El producto/equipo no existe");
            }
        }

        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::table('LABTYQ')
                ->where('TEC3DEL', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->where('PRD3DEL', $data['producto_delegacion'] ?? '')
                ->where('PRD3COD', $data['producto_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El producto ya estaba enlazado con el parámetro");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
                $data['producto_delegacion'], 
                $data['producto_codigo'], 
            );            
        }

        return $data;
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se requieren validaciones antes de borrar
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