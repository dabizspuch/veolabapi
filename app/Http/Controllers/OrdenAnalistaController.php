<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class OrdenAnalistaController extends BaseController
{
    protected $table = 'LABORE';
    protected $delegationField = 'ORD3DEL';
    protected $key1Field = 'ORD3SER';          
    protected $codeField = 'ORD3COD';  
    protected $key2Field = 'EMP3COD';          
    protected $key3Field = 'EMP3DEL';          
    protected $skipNewCode = true;          
    
    protected $mapping = [
        'orden_delegacion'              => 'ORD3DEL',
        'orden_serie'                   => 'ORD3SER',
        'orden_codigo'                  => 'ORD3COD',
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'orden_delegacion'          => 'nullable|string|max:10',
            'orden_serie'               => 'nullable|string|max:10',
            'orden_codigo'              => 'required|integer',
            'empleado_delegacion'       => 'nullable|string|max:10',
            'empleado_codigo'           => 'required|integer'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del analista 
        if (!empty($data['empleado_codigo'])) {
            $analyst = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', $data['empleado_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_codigo'])
                ->where('EMPBANA', 'T')
                ->first(); 
            if (!$analyst) {
                throw new \Exception("El analista no existe");
            }
        }        

        // Valida la existencia de la órden 
        if (!empty($data['orden_codigo'])) {
            $order = DB::connection('dynamic')->table('LABORD')
                ->where('DEL3COD', $data['orden_delegacion'] ?? '')
                ->where('ORD1SER', $data['orden_serie'] ?? '')
                ->where('ORD1COD', $data['orden_codigo'])
                ->first(); 
            if (!$order) {
                throw new \Exception("La orden no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Comprueba que la orden y la operación no estaban ya enlazadas
        $exist = DB::connection('dynamic')->table('LABORE')
            ->where('ORD3DEL', $data['orden_delegacion'] ?? '')
            ->where('ORD3SER', $data['orden_serie'] ?? '')
            ->where('ORD3COD', $data['orden_codigo'])
            ->where('EMP3DEL', $data['empleado_delegacion'] ?? '')
            ->where('EMP3COD', $data['empleado_codigo'])
            ->exists();
        if ($exist) {
            throw new \Exception("La orden ya tiene asociado este analista");            
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