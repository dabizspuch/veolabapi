<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EmpleadoClienteController extends BaseController
{
    protected $table = 'GRHCLI';
    protected $delegationField = 'EMP3DEL';
    protected $codeField = 'EMP3COD';  
    protected $key1Field = 'CLI3COD';          
    protected $key2Field = 'CLI3DEL';          
    protected $skipNewCode = true;          
    
    protected $mapping = [
        'empleado_delegacion'          => 'EMP3DEL',
        'empleado_codigo'              => 'EMP3COD',
        'cliente_delegacion'           => 'CLI3DEL',
        'cliente_codigo'               => 'CLI3COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'empleado_delegacion'      => 'nullable|string|max:10',
            'empleado_codigo'          => 'required|integer',
            'cliente_delegacion'       => 'nullable|string|max:10',
            'cliente_codigo'           => 'required|string|max:15',
        ];        

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del empleado 
        if (!empty($data['empleado_codigo'])) {
            $analyst = DB::table('GRHEMP')
                ->where('DEL3COD', $data['empleado_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_codigo'])
                ->first(); 
            if (!$analyst) {
                throw new \Exception("El empleado no existe");
            }
        }        

        // Valida la existencia del cliente 
        if (!empty($data['cliente_codigo'])) {
            $order = DB::table('SINCLI')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_codigo'])
                ->first(); 
            if (!$order) {
                throw new \Exception("El cliente no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Comprueba que no estaban ya enlazados
        $exist = DB::table('GRHCLI')
            ->where('EMP3DEL', $data['empleado_delegacion'] ?? '')
            ->where('EMP3COD', $data['empleado_codigo'])
            ->where('CLI3DEL', $data['cliente_delegacion'] ?? '')
            ->where('CLI3COD', $data['cliente_codigo'])
            ->exists();
        if ($exist) {
            throw new \Exception("El empleado y el cliente ya estaban asociados");            
        } 
                  
        // Modificaciones no soportadas (put)
        
        return $data;
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No hay restricciones previas al borrado
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