<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class OrdenOperacionController extends BaseController
{
    protected $table = 'LABOYO';
    protected $delegationField = 'ORD3DEL';
    protected $key1Field = 'ORD3SER';          
    protected $codeField = 'ORD3COD';  
    protected $key2Field = 'OPE3COD';          
    protected $key3Field = 'OPE3SER';          
    protected $key4Field = 'OPE3DEL';          
    protected $mapping = [
        'orden_delegacion'              => 'ORD3DEL',
        'orden_serie'                   => 'ORD3SER',
        'orden_codigo'                  => 'ORD3COD',
        'operacion_delegacion'          => 'OPE3DEL',
        'operacion_serie'               => 'OPE3SER',
        'operacion_codigo'              => 'OPE3COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'orden_delegacion'          => 'nullable|string|max:10',
            'orden_serie'               => 'nullable|string|max:10',
            'orden_codigo'              => 'required|integer',
            'operacion_delegacion'      => 'nullable|string|max:10',
            'operacion_serie'           => 'nullable|string|max:10',
            'operacion_codigo'          => 'required|integer'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia de la operación 
        if (!empty($data['operacion_codigo'])) {
            $operation = DB::table('LABOPE')
                ->where('DEL3COD', $data['operacion_delegacion'] ?? '')
                ->where('OPE1SER', $data['operacion_serie'] ?? '')
                ->where('OPE1COD', $data['operacion_codigo'])
                ->first(); 
            if (!$operation) {
                throw new \Exception("La operación no existe");
            }
        }        

        // Valida la existencia de la órden 
        if (!empty($data['orden_codigo'])) {
            $order = DB::table('LABORD')
                ->where('DEL3COD', $data['orden_delegacion'] ?? '')
                ->where('ORD1SER', $data['orden_serie'] ?? '')
                ->where('ORD1COD', $data['orden_codigo'])
                ->first(); 
            if (!$order) {
                throw new \Exception("La orden no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        // Comprueba que la orden y la operación no estaban ya enlazadas
        $exist = DB::table('LABOYO')
            ->where('ORD3DEL', $data['orden_delegacion'] ?? '')
            ->where('ORD3SER', $data['orden_serie'] ?? '')
            ->where('ORD3COD', $data['orden_codigo'])
            ->where('OPE3DEL', $data['operacion_delegacion'] ?? '')
            ->where('OPE3SER', $data['operacion_serie'] ?? '')
            ->where('OPE3COD', $data['operacion_codigo'])
            ->exists();
        if ($exist) {
            throw new \Exception("La orden ya tiene asociada esta operación");            
        }
        return $data;
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se permite dejar órdenes sin ninguna operación
        $count = DB::table('LABOYO')
            ->where('ORD3DEL', $delegation)
            ->where('ORD3SER', $key1)
            ->where('ORD3COD', $code)
            ->whereNot(function ($query) use ($key2, $key3, $key4) {
                $query->where('OPE3DEL', $key4)
                      ->where('OPE3SER', $key3)
                      ->where('OPE3COD', $key2);
            })         
            ->count();
        if ($count == 0) {
            throw new \Exception("La orden debe contener al menos una operación");
        }
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