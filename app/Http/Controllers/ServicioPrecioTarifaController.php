<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ServicioPrecioTarifaController extends BaseController
{
    protected $table = 'LABSYF';
    protected $delegationField = 'SER3DEL';
    protected $codeField = 'SER3COD';   
    protected $key1Field = 'TAR3COD';
    protected $key2Field = 'TAR3DEL';
    protected $skipNewCode = true;
    
    protected $mapping = [
        'servicio_delegacion'           => 'SER3DEL',
        'servicio_codigo'               => 'SER3COD',
        'tarifa_delegacion'             => 'TAR3DEL',
        'tarifa_codigo'                 => 'TAR3COD',
        'precio'                        => 'SYFNPRE',
        'descuento'                     => 'SYFCDTO',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'servicio_delegacion'       => 'nullable|string|max:10',
            'servicio_codigo'           => $isCreating ? 'required|string|max:20' : 'nullable|string|max:20',
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
        if (!empty($data['servicio_codigo'])) {
            $service = DB::table('LABSER')
                ->where('DEL3COD', $data['servicio_delegacion'] ?? '')
                ->where('SER1COD', $data['servicio_codigo'])
                ->first(); 
            if (!$service) {
                throw new \Exception("El servicio no existe");
            }
        }

        // Valida la existencia de la tarifa 
        if (!empty($data['tarifa_codigo'])) {
            $parameter = DB::table('LABTAR')
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
            $exist = DB::table('LABSYF')
                ->where('SER3DEL', $data['servicio_delegacion'] ?? '')
                ->where('SER3COD', $data['servicio_codigo'])
                ->where('TAR3DEL', $data['tarifa_delegacion'] ?? '')
                ->where('TAR3COD', $data['tarifa_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El precio del servicio ya estaba definido para esta tarifa");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['servicio_delegacion'], 
                $data['servicio_codigo'], 
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