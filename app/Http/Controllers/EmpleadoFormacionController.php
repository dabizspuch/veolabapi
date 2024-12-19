<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EmpleadoFormacionController extends BaseController
{
    protected $table = 'GRHFOR';
    protected $delegationField = 'EMP3DEL';
    protected $codeField = 'EMP3COD';   
    protected $key1Field = 'FOR1COD';
    protected $searchFields = ['FORCDES'];          
    protected $skipNewCode = true;
    
    protected $mapping = [
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
        'codigo'                        => 'FOR1COD',
        'descripcion'                   => 'FORCDES',
        'observaciones'                 => 'FORCOBS',
        'fecha_inicio'                  => 'FORDINI',
        'fecha_fin'                     => 'FORDFIN',
        'adjunta_evidencia'             => 'FORBADJ',
        'es_plan_empresa'               => 'FORBEMP'
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'empleado_delegacion'       => 'nullable|string|max:10',
            'empleado_codigo'           => $isCreating ? 'required|integer' : 'nullable|integer',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:50',
            'observaciones'             => 'nullable|string|max:255',
            'fecha_inicio'              => 'nullable|date',
            'fecha_fin'                 => 'nullable|date',
            'adjunta_evidencia'         => 'nullable|string|in:T,F|max:1',
            'es_plan_empresa'           => 'nullable|string|in:T,F|max:1'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del empleado 
        if (!empty($data['empleado_codigo'])) {
            $employee = DB::table('GRHEMP')
                ->where('DEL3COD', $data['empleado_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El empleado no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post'); 

        if ($isCreating) {
            // Genera el código sin usar claves técnicas
            if (empty($data['codigo'])) {
                $data['empleado_delegacion'] = $data['empleado_delegacion'] ?? '';
                $data['codigo'] = $this->getNextTrainingCode(
                    $data['empleado_delegacion'], 
                    $data['empleado_codigo']
                );
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset(
                $data['empleado_delegacion'], 
                $data['empleado_codigo'], 
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
     * Obtiene el siguiente código de formación para el empleado de entrada.
     * 
     * @param string $employeeDelegation - Delegación del emppleado para filtrar las formaciones.
     * @param string $employeeCode - Código del empleado para filtrar las formaciones.
     * @return string - El siguiente código de formación
     */
    private function getNextTrainingCode($employeeDelegation, $employeeCode)
    {
        $maxPoint = DB::table('GRHFOR')
            ->where('EMP3DEL', $employeeDelegation)
            ->where('EMP3COD', $employeeCode) 
            ->max('FOR1COD');    

        return $maxPoint ? $maxPoint + 1 : 1;
    }    
}