<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroNormativaController extends BaseController
{
    protected $table = 'LABTYN';
    protected $delegationField = 'TEC3DEL';
    protected $codeField = 'TEC3COD';   
    protected $key1Field = 'NOR3COD';
    protected $key2Field = 'NOR3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'parametro_delegacion'          => 'TEC3DEL',
        'parametro_codigo'              => 'TEC3COD',
        'normativa_delegacion'          => 'NOR3DEL',
        'normativa_codigo'              => 'NOR3COD',
        'valor'                         => 'TYNCVAL',
        'rango'                         => 'TYNCRAN',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'parametro_delegacion'      => 'nullable|string|max:10',
            'parametro_codigo'          => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'normativa_delegacion'      => 'nullable|string|max:10',
            'normativa_codigo'          => $isCreating ? 'required|string|max:20' : 'nullable|string|max:20',
            'valor'                     => 'nullable|string|max:100',
            'rango'                     => 'nullable|string|max:100',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del parámetro 
        if (!empty($data['parametro_codigo'])) {
            $service = DB::connection('dynamic')->table('LABTEC')
                ->where('DEL3COD', $data['parametro_delegacion'] ?? '')
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first(); 
            if (!$service) {
                throw new \Exception("El parámetro no existe");
            }
        }

        // Valida la existencia de la normativa 
        if (!empty($data['normativa_codigo'])) {
            $parameter = DB::connection('dynamic')->table('LABNOR')
                ->where('DEL3COD', $data['normativa_delegacion'] ?? '')
                ->where('NOR1COD', $data['normativa_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("La normativa no existe");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::connection('dynamic')->table('LABTYN')
                ->where('TEC3DEL', $data['parametro_delegacion'] ?? '')
                ->where('TEC3COD', $data['parametro_codigo'])
                ->where('NOR3DEL', $data['normativa_delegacion'] ?? '')
                ->where('NOR3COD', $data['normativa_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El parámetro y la normativa ya estaban vinculados");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
                $data['normativa_delegacion'], 
                $data['normativa_codigo'], 
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