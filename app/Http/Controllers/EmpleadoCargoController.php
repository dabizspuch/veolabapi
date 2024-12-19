<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EmpleadoCargoController extends BaseController
{
    protected $table = 'GRHEYC';
    protected $delegationField = 'EMP3DEL';
    protected $codeField = 'EMP3COD';   
    protected $key1Field = 'CAR3COD';
    protected $key2Field = 'CAR3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
        'cargo_delegacion'              => 'CAR3DEL',
        'cargo_codigo'                  => 'CAR3COD',
        'posicion'                      => 'EYCNPOS',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'empleado_delegacion'         => 'nullable|string|max:10',
            'empleado_codigo'             => $isCreating ? 'required|integer' : 'nullable|integer',
            'cargo_delegacion'            => 'nullable|string|max:10',
            'cargo_codigo'                => $isCreating ? 'required|integer' : 'nullable|integer',
            'posicion'                    => 'nullable|integer|min:1',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
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

        // Valida la existencia del cargo 
        if (!empty($data['cargo_codigo'])) {
            $chargue = DB::table('GRHCAR')
                ->where('DEL3COD', $data['cargo_delegacion'] ?? '')
                ->where('CAR1COD', $data['cargo_codigo'])
                ->first(); 
            if (!$chargue) {
                throw new \Exception("El cargo no existe");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::table('GRHEYC')
                ->where('EMP3DEL', $data['empleado_delegacion'] ?? '')
                ->where('EMP3COD', $data['empleado_codigo'])
                ->where('CAR3DEL', $data['cargo_delegacion'] ?? '')
                ->where('CAR3COD', $data['cargo_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El cargo ya estaba enlazado con el empleado");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['empleado_delegacion'], 
                $data['empleado_codigo'], 
                $data['cargo_delegacion'], 
                $data['cargo_codigo'], 
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