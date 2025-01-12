<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroIntervaloController extends BaseController
{
    protected $table = 'LABCYR';
    protected $delegationField = 'TEC3DEL';
    protected $codeField = 'TEC3COD';   
    protected $key1Field = 'COT3COD';
    protected $key2Field = 'RAN3COD';
    protected $key3Field = 'RAN3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'parametro_delegacion'          => 'TEC3DEL',
        'parametro_codigo'              => 'TEC3COD',
        'columna_codigo'                => 'COT3COD',
        'rango_delegacion'              => 'RAN3DEL',
        'rango_codigo'                  => 'RAN3COD',
        'valor'                         => 'CYRCVAR',
        'marca_delegacion'              => 'MAR2DEL',
        'marca_codigo'                  => 'MAR2COD',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'parametro_delegacion'      => 'nullable|string|max:10',
            'parametro_codigo'          => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'columna_codigo'            => $isCreating ? 'required|integer' : 'nullable|integer',
            'rango_delegacion'          => 'nullable|string|max:10',
            'rango_codigo'              => $isCreating ? 'required|integer' : 'nullable|integer',
            'valor'                     => 'nullable|string|max:100',
            'marca_delegacion'          => 'nullable|string|max:10',
            'marca_codigo'              => 'nullable|integer',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia de la columna 
        if (!empty($data['columna_codigo'])) {
            $column = DB::connection('dynamic')->table('LABCOT')
                ->where('TEC3DEL', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->where('COT1COD', $data['columna_codigo'])
                ->first(); 
            if (!$column) {
                throw new \Exception("La columna no existe");
            }
        }    
        
        // Valida la existencia del rango 
        if (!empty($data['rango_codigo'])) {
            $range = DB::connection('dynamic')->table('LABRAN')
                ->where('DEL3COD', $data['rango_delegacion'] ?? '')
                ->where('RAN1COD', $data['rango_codigo'])
                ->first(); 
            if (!$range) {
                throw new \Exception("El rango no existe");
            }
        }

        // Valida la existencia de la marca 
        if (!empty($data['marca_codigo'])) {
            $mark = DB::connection('dynamic')->table('LABMAR')
                ->where('DEL3COD', $data['marca_delegacion'] ?? '')
                ->where('MAR1COD', $data['marca_codigo'])
                ->first(); 
            if (!$mark) {
                throw new \Exception("La marca no existe");
            }
        }        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::connection('dynamic')->table('LABCYR')
                ->where('TEC3DEL', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->where('COT3COD', $data['columna_codigo'])
                ->where('RAN3DEL', $data['rango_delegacion'] ?? '')
                ->where('RAN3COD', $data['rango_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El intervalo ya estaba definido");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
                $data['columna_codigo'], 
                $data['rango_delegacion'], 
                $data['rango_codigo'], 
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