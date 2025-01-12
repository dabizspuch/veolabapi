<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class CargoTareaController extends BaseController
{
    protected $table = 'GRHTAR';
    protected $delegationField = 'CAR3DEL';
    protected $codeField = 'CAR3COD';   
    protected $key1Field = 'TAR1COD';          
    protected $searchFields = ['TARCDES'];
    protected $skipNewCode = true;
    
    protected $mapping = [
        'cargo_delegacion'           => 'CAR3DEL',
        'cargo_codigo'               => 'CAR3COD',
        'codigo'                     => 'TAR1COD',
        'descripcion'                => 'TARCDES'
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'cargo_delegacion'       => 'nullable|string|max:10',
            'cargo_codigo'           => $isCreating ? 'required|integer' : 'nullable|integer',
            'codigo'                 => 'nullable|integer',
            'descripcion'            => 'nullable|string|max:255',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del cargo 
        if (!empty($data['cargo_codigo'])) {
            $employee = DB::connection('dynamic')->table('GRHCAR')
                ->where('DEL3COD', $data['cargo_delegacion'] ?? '')
                ->where('CAR1COD', $data['cargo_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El cargo no existe");
            }
        }        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post'); 

        if ($isCreating) {
            // Genera el código sin usar claves técnicas
            if (empty($data['codigo'])) {
                $data['cargo_delegacion'] = $data['cargo_delegacion'] ?? '';
                $data['codigo'] = $this->getNextTaskCode(
                    $data['cargo_delegacion'], 
                    $data['cargo_codigo']
                );
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset(
                $data['cargo_delegacion'], 
                $data['cargo_codigo'], 
                $data['codigo']
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
    
    /**
     * Obtiene el siguiente código de tarea para el cargo de entrada.
     * 
     * @param string $chargeDelegation - Delegación del cargo para filtrar las tareas.
     * @param string $chargeCode - Código del cargo para filtrar las tareas.
     * @return string - El siguiente código de tarea
     */
    private function getNextTaskCode($chargeDelegation, $chargeCode)
    {
        $maxPoint = DB::connection('dynamic')->table('GRHTAR')
            ->where('CAR3DEL', $chargeDelegation)
            ->where('CAR3COD', $chargeCode) 
            ->max('TAR1COD');    

        return $maxPoint ? $maxPoint + 1 : 1;
    }    
}