<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class MatrizTipoOperacionController extends BaseController
{
    protected $table = 'LABOYM';
    protected $delegationField = 'DEL3MAT';
    protected $codeField = 'MAT3COD';   
    protected $key1Field = 'TIO3COD';
    protected $key2Field = 'DEL3TIO';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'matriz_delegacion'              => 'DEL3MAT',
        'matriz_codigo'                  => 'MAT3COD',
        'tipo_operacion_delegacion'      => 'DEL3TIO',
        'tipo_operacion_codigo'          => 'TIO3COD',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'matriz_delegacion'          => 'nullable|string|max:10',
            'matriz_codigo'              => $isCreating ? 'required|integer' : 'nullable|integer',
            'tipo_operacion_delegacion'  => 'nullable|string|max:10',
            'tipo_operacion_codigo'      => $isCreating ? 'required|integer' : 'nullable|integer',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del parámetro 
        if (!empty($data['tipo_operacion_codigo'])) {
            $parameter = DB::connection('dynamic')->table('LABTIO')
                ->where('DEL3COD', $data['tipo_operacion_delegacion'] ?? '')
                ->where('TIO1COD', $data['tipo_operacion_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("El tipo de operación no existe");
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
            $exist = DB::connection('dynamic')->table('LABOYM')
                ->where('DEL3MAT', $data['matriz_delegacion'] ?? '')
                ->where('MAT3COD', $data['matriz_codigo'])
                ->where('DEL3TIO', $data['tipo_operacion_delegacion'] ?? '')
                ->where('TIO3COD', $data['tipo_operacion_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("La matriz ya estaba vinculada con el tipo de operación");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['matriz_delegacion'], 
                $data['matriz_codigo'], 
                $data['tipo_operacion_delegacion'], 
                $data['tipo_operacion_codigo'], 
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