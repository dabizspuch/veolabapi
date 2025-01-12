<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroPrecioTarifaController extends BaseController
{
    protected $table = 'LABTYF';
    protected $delegationField = 'TEC3DEL';
    protected $codeField = 'TEC3COD';   
    protected $key1Field = 'TAR3COD';
    protected $key2Field = 'TAR3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'parametro_delegacion'          => 'TEC3DEL',
        'parametro_codigo'              => 'TEC3COD',
        'tarifa_delegacion'             => 'TAR3DEL',
        'tarifa_codigo'                 => 'TAR3COD',
        'precio'                        => 'TYFNPRE',
        'descuento'                     => 'TYFCDTO',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'parametro_delegacion'      => 'nullable|string|max:10',
            'parametro_codigo'          => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'tarifa_delegacion'         => 'nullable|string|max:10',
            'tarifa_codigo'             => $isCreating ? 'required|integer' : 'nullable|integer',
            'precio'                    => 'nullable|numeric',
            'descuento'                 => 'nullable|string|max:15',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del servicio 
        if (!empty($data['parametro_codigo'])) {
            $parameter = DB::connection('dynamic')->table('LABTEC')
                ->where('DEL3COD', $data['parametro_delegacion'] ?? '')
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("El parámetro no existe");
            }
        }

        // Valida la existencia de la tarifa 
        if (!empty($data['tarifa_codigo'])) {
            $parameter = DB::connection('dynamic')->table('LABTAR')
                ->where('DEL3COD', $data['tarifa_delegacion'] ?? '')
                ->where('TAR1COD', $data['tarifa_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("La tarifa no existe");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::connection('dynamic')->table('LABTYF')
                ->where('TEC3DEL', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->where('TAR3DEL', $data['tarifa_delegacion'] ?? '')
                ->where('TAR3COD', $data['tarifa_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El precio del parámetro ya estaba definido para esta tarifa");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
                $data['tarifa_delegacion'], 
                $data['tarifa_codigo'], 
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