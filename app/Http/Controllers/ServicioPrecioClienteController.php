<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ServicioPrecioClienteController extends BaseController
{
    protected $table = 'LABSYC';
    protected $delegationField = 'SER3DEL';
    protected $codeField = 'SER3COD';   
    protected $key1Field = 'CLI3COD';
    protected $key2Field = 'CLI3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'servicio_delegacion'           => 'SER3DEL',
        'servicio_codigo'               => 'SER3COD',
        'cliente_delegacion'            => 'CLI3DEL',
        'cliente_codigo'                => 'CLI3COD',
        'precio'                        => 'SYCNPRE',
        'descuento'                     => 'SYCCDTO',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'servicio_delegacion'       => 'nullable|string|max:10',
            'servicio_codigo'           => $isCreating ? 'required|string|max:20' : 'nullable|string|max:20',
            'cliente_delegacion'        => 'nullable|string|max:10',
            'cliente_codigo'            => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
            'precio'                    => 'nullable|numeric',
            'descuento'                 => 'nullable|string|max:15',
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

        // Valida la existencia del cliente 
        if (!empty($data['cliente_codigo'])) {
            $parameter = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_codigo'])
                ->first(); 
            if (!$parameter) {
                throw new \Exception("El cliente no existe");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::connection('dynamic')->table('LABSYC')
                ->where('SER3DEL', $data['servicio_delegacion'] ?? '')
                ->where('SER3COD', $data['servicio_codigo'])
                ->where('CLI3DEL', $data['cliente_delegacion'] ?? '')
                ->where('CLI3COD', $data['cliente_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El precio del servicio ya estaba definido para este cliente");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['servicio_delegacion'], 
                $data['servicio_codigo'], 
                $data['cliente_delegacion'], 
                $data['cliente_codigo'], 
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