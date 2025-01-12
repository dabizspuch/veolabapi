<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ServicioGastoController extends BaseController
{
    protected $table = 'LABSYE';
    protected $delegationField = 'DEL3SER';
    protected $codeField = 'SER3COD';   
    protected $key1Field = 'ESC3COD';
    protected $key2Field = 'DEL3ESC';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'servicio_delegacion'           => 'DEL3SER',
        'servicio_codigo'               => 'SER3COD',
        'gasto_delegacion'              => 'DEL3ESC',
        'gasto_codigo'                  => 'ESC3COD',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'servicio_delegacion'       => 'nullable|string|max:10',
            'servicio_codigo'           => $isCreating ? 'required|string|max:20' : 'nullable|string|max:20',
            'gasto_delegacion'          => 'nullable|string|max:10',
            'gasto_codigo'              => $isCreating ? 'required|integer' : 'nullable|integer',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del servicio 
        if (!empty($data['servicio_codigo'])) {
            $service = DB::connection('dynamic')->table('LABSER')
                ->where('DEL3COD', $data['servicio_delegacion'] ?? '')
                ->where('SER1COD', $data['servicio_codigo'])
                ->first(); 
            if (!$service) {
                throw new \Exception("El servicio no existe");
            }
        }

        // Valida la existencia del gasto 
        if (!empty($data['gasto_codigo'])) {
            $parameter = DB::connection('dynamic')->table('LABESC')
                ->where('DEL3COD', $data['gasto_delegacion'] ?? '')
                ->where('ESC1COD', $data['gasto_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("El gasto no existe");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::connection('dynamic')->table('LABSYE')
                ->where('DEL3SER', $data['servicio_delegacion'] ?? '')
                ->where('SER3COD', $data['servicio_codigo'])
                ->where('DEL3ESC', $data['gasto_delegacion'] ?? '')
                ->where('ESC3COD', $data['gasto_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El gasto ya estaba enlazado con el servicio");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['servicio_delegacion'], 
                $data['servicio_codigo'], 
                $data['gasto_delegacion'], 
                $data['gasto_codigo'], 
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