<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroMatrizController extends BaseController
{
    protected $table = 'LABTYM';
    protected $delegationField = 'DEL3TEC';
    protected $codeField = 'TEC3COD';   
    protected $key1Field = 'MAT3COD';
    protected $key2Field = 'DEL3MAT';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'parametro_delegacion'           => 'DEL3TEC',
        'parametro_codigo'               => 'TEC3COD',
        'matriz_delegacion'              => 'DEL3MAT',
        'matriz_codigo'                  => 'MAT3COD',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'parametro_delegacion'       => 'nullable|string|max:10',
            'parametro_codigo'           => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'matriz_delegacion'          => 'nullable|string|max:10',
            'matriz_codigo'              => $isCreating ? 'required|integer' : 'nullable|integer',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del parámetro 
        if (!empty($data['parametro_codigo'])) {
            $parameter = DB::connection('dynamic')->table('LABTEC')
                ->where('DEL3COD', $data['parametro_delegacion'] ?? '')
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("El parámetro no existe");
            }
        }

        // Valida la existencia de la matriz 
        if (!empty($data['matriz_codigo'])) {
            $matrix = DB::connection('dynamic')->table('LABMAT')
                ->where('DEL3COD', $data['matriz_delegacion'] ?? '')
                ->where('MAT1COD', $data['matriz_codigo'])
                ->first(); 
            if (!$matrix) {
                throw new \Exception("La matriz no existe");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::connection('dynamic')->table('LABTYM')
                ->where('DEL3TEC', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->where('DEL3MAT', $data['matriz_delegacion'] ?? '')
                ->where('MAT3COD', $data['matriz_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("La matriz ya estaba vinculada con el parámetro");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
                $data['matriz_delegacion'], 
                $data['matriz_codigo'], 
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