<?php

namespace App\Http\Controllers;

use App\Traits\OperationInventoryTools;
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
        'producto_delegacion'           => 'PRD3DEL',
        'producto_codigo'               => 'PRD3COD',
        'numero_serie_lote'             => 'SEL3COD',
        'producto_materia_delegacion'   => 'PRM3DEL',
        'producto_materia_codigo'       => 'PRM3COD',
        'materia_numero_serie_lote'     => 'SEM3COD',
        'cantidad'                      => 'MATNCAN'
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'producto_delegacion'           => 'nullable|string|max:10',
            'producto_codigo'               => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
            'numero_serie_lote'             => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'producto_materia_delegacion'   => 'nullable|string|max:10',
            'producto_materia_codigo'       => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
            'materia_numero_serie_lote'     => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'cantidad'                      => 'nullable|numeric'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {         
        // Valida la existencia del lote 
        if (!empty($data['numero_serie_lote'])) {
            $order = DB::connection('dynamic')->table('ALMSEL')
                ->where('PRD3DEL', $data['producto_delegacion'] ?? '')
                ->where('PRD3COD', $data['producto_codigo'])
                ->where('SEL1COD', $data['numero_serie_lote'])
                ->first(); 
            if (!$order) {
                throw new \Exception("La serie o lote no existe");
            }
        }   

        // Valida la existencia del lote (materia prima)
        if (!empty($data['materia_numero_serie_lote'])) {
            $order = DB::connection('dynamic')->table('ALMSEL')
                ->where('PRD3DEL', $data['producto_materia_delegacion'] ?? '')
                ->where('PRD3COD', $data['producto_materia_codigo'])
                ->where('SEL1COD', $data['materia_numero_serie_lote'])
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
            $exist = DB::connection('dynamic')->table('ALMMAT')
                ->where('PRM3DEL', $data['producto_delegacion'] ?? '')
                ->where('PRM3COD', $data['producto_codigo'])
                ->where('SEM3COD', $data['numero_serie_lote'])
                ->where('PRD3DEL', $data['producto_materia_delegacion'] ?? '')
                ->where('PRD3COD', $data['producto_materia_codigo'])
                ->where('SEL3COD', $data['materia_numero_serie_lote'])
                ->exists();
            if ($exist) {
                throw new \Exception("Los lotes indicados ya están vinculados como materias primas");            
            }
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['producto_delegacion'], 
                $data['producto_codigo'], 
                $data['numero_serie_lote'], 
                $data['producto_materia_delegacion'], 
                $data['producto_materia_codigo'], 
                $data['materia_numero_serie_lote'], 
            );                        
        }

        // Recuerda cantidad anterior
        if (!$isCreating) {
            $result = DB::connection('dynamic')->table('ALMMAT')
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
        $result = DB::connection('dynamic')->table('ALMMAT')
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
            $productDelegation = $data['producto_materia_delegacion'] ?? '';
            $productCode = $data['producto_materia_codigo'];
            $lotNumber = $data['materia_numero_serie_lote'];                
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