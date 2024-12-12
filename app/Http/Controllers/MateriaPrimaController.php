<?php

namespace App\Http\Controllers;

use App\Traits\OperationInventoryTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MateriaPrimaController extends BaseController
{
    use OperationInventoryTools;

    protected $table = 'ALMMAT';
    protected $delegationField = 'PRD3DEL';
    protected $codeField = 'SEL3COD';  
    protected $key1Field = 'PRD3COD';          
    protected $key2Field = 'SEM3COD';          
    protected $key3Field = 'PRM3DEL';          
    protected $key4Field = 'PRM3COD';          
    protected $skipNewCode = true;          
    
    protected $previousAmount = 0; // Existencias antes de grabar

    protected $mapping = [
        'delegacion_producto'           => 'PRD3DEL',
        'codigo_producto'               => 'PRD3COD',
        'numero_serie_lote'             => 'SEL3COD',
        'delegacion_producto_materia'   => 'PRM3DEL',
        'codigo_producto_materia'       => 'PRM3COD',
        'numero_serie_lote_materia'     => 'SEM3COD',
        'cantidad'                      => 'MATNCAN'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion_producto'           => 'nullable|string|max:10',
            'codigo_producto'               => 'required|string|max:15',
            'numero_serie_lote'             => 'required|string|max:30',
            'delegacion_producto_materia'   => 'nullable|string|max:10',
            'codigo_producto_materia'       => 'required|string|max:15',
            'numero_serie_lote_materia'     => 'required|string|max:30',
            'cantidad'                      => 'nullable|numeric'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {         
        // Valida la existencia del lote 
        if (!empty($data['numero_serie_lote'])) {
            $order = DB::table('ALMSEL')
                ->where('PRD3DEL', $data['delegacion_producto'] ?? '')
                ->where('PRD3COD', $data['codigo_producto'])
                ->where('SEL1COD', $data['numero_serie_lote'])
                ->first(); 
            if (!$order) {
                throw new \Exception("La serie o lote no existe");
            }
        }   

        // Valida la existencia del lote (materia prima)
        if (!empty($data['numero_serie_lote_materia'])) {
            $order = DB::table('ALMSEL')
                ->where('PRD3DEL', $data['delegacion_producto_materia'] ?? '')
                ->where('PRD3COD', $data['codigo_producto_materia'])
                ->where('SEL1COD', $data['numero_serie_lote_materia'])
                ->first(); 
            if (!$order) {
                throw new \Exception("La serie o lote no existe (materia prima)");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::table('ALMMAT')
                ->where('PRM3DEL', $data['delegacion_producto'] ?? '')
                ->where('PRM3COD', $data['codigo_producto'])
                ->where('SEM3COD', $data['numero_serie_lote'])
                ->where('PRD3DEL', $data['delegacion_producto_materia'] ?? '')
                ->where('PRD3COD', $data['codigo_producto_materia'])
                ->where('SEL3COD', $data['numero_serie_lote_materia'])
                ->exists();
            if ($exist) {
                throw new \Exception("Los lotes indicados ya están vinculados como materias primas");            
            }            
        }

        // Recuerda cantidad anterior
        if (!$isCreating) {
            $result = DB::table('ALMMAT')
                ->select('MATNCAN')
                ->where('PRD3DEL', $delegation)
                ->where('PRD3COD', $key1)
                ->where('SEL3COD', $code)
                ->where('PRM3DEL', $key3)
                ->where('PRM3COD', $key4)
                ->where('SEM3COD', $key2)
                ->first();

            $this->previousAmount = $result->MATNCAN ?? 0;        
        }
            
        return $data;
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Recuerda cantidad anterior para generar el movimiento
        $result = DB::table('ALMMAT')
            ->select('MATNCAN')
            ->where('PRD3DEL', $delegation)
            ->where('PRD3COD', $key1)
            ->where('SEL3COD', $code)
            ->where('PRM3DEL', $key3)
            ->where('PRM3COD', $key4)
            ->where('SEM3COD', $key2)
            ->first();
        
        $this->previousAmount = $result->MATNCAN ?? 0;        
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {        
        $this->recalculateLotStock($key3, $key4, $key2, $this->previousAmount, 'J');      
    }

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {        
            $productDelegation = $data['delegacion_producto_materia'] ?? '';
            $productCode = $data['codigo_producto_materia'];
            $lotNumber = $data['numero_serie_lote_materia'];                
            $movementType = "O"; // Consumo
            $amount = -$data['cantidad'] ?? 0;
        
        } else {    
            $productDelegation = $key3 ?? '';
            $productCode = $key4;
            $lotNumber = $key2;    
            $movementType = "J"; // Ajuste
            $amount = $this->previousAmount - ($data['cantidad'] ?? 0);        
        }

        $this->recalculateLotStock($productDelegation, $productCode, $lotNumber, $amount, $movementType);      

        return $data;
    }    

}