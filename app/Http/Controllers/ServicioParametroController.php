<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ServicioParametroController extends BaseController
{
    protected $table = 'LABSYT';
    protected $delegationField = 'DEL3SER';
    protected $codeField = 'SER3COD';   
    protected $key1Field = 'TEC3COD';
    protected $key2Field = 'DEL3TEC';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'servicio_delegacion'           => 'DEL3SER',
        'servicio_codigo'               => 'SER3COD',
        'parametro_delegacion'          => 'DEL3TEC',
        'parametro_codigo'              => 'TEC3COD',
        'posicion'                      => 'SYTNORD',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'servicio_delegacion'         => 'nullable|string|max:10',
            'servicio_codigo'             => $isCreating ? 'required|string|max:20' : 'nullable|string|max:20',
            'parametro_delegacion'        => 'nullable|string|max:10',
            'parametro_codigo'            => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'posicion'                    => 'nullable|integer|min:1',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del servicio 
        if (!empty($data['servicio_codigo'])) {
            $service = DB::table('LABSER')
                ->where('DEL3COD', $data['servicio_delegacion'] ?? '')
                ->where('SER1COD', $data['servicio_codigo'])
                ->first(); 
            if (!$service) {
                throw new \Exception("El servicio no existe");
            }
        }

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
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::table('LABSYT')
                ->where('DEL3SER', $data['servicio_delegacion'] ?? '')
                ->where('SER3COD', $data['servicio_codigo'])
                ->where('DEL3TEC', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El parámetro ya estaba enlazado con el servicio");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['servicio_delegacion'], 
                $data['servicio_codigo'], 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
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