<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroEmpleadoController extends BaseController
{
    protected $table = 'LABTYE';
    protected $delegationField = 'TEC3DEL';
    protected $codeField = 'TEC3COD';   
    protected $key1Field = 'EMP3COD';
    protected $key2Field = 'EMP3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'parametro_delegacion'          => 'TEC3DEL',
        'parametro_codigo'              => 'TEC3COD',
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
        'posicion'                      => 'TYENPOS',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'parametro_delegacion'        => 'nullable|string|max:10',
            'parametro_codigo'            => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'empleado_delegacion'         => 'nullable|string|max:10',
            'empleado_codigo'             => $isCreating ? 'required|integer' : 'nullable|integer',
            'posicion'                    => 'nullable|integer|min:1',
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
        
        // Valida la existencia del empleado 
        if (!empty($data['empleado_codigo'])) {
            $employee = DB::table('GRHEMP')
                ->where('DEL3COD', $data['empleado_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El empleado no existe");
            }
        }

        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::table('LABTYE')
                ->where('TEC3DEL', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->where('EMP3DEL', $data['empleado_delegacion'] ?? '')
                ->where('EMP3COD', $data['empleado_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El empleado ya estaba enlazado con el parámetro");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
                $data['empleado_delegacion'], 
                $data['empleado_codigo'], 
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