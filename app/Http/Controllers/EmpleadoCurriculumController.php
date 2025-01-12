<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EmpleadoCurriculumController extends BaseController
{
    protected $table = 'GRHCUR';
    protected $delegationField = 'EMP3DEL';
    protected $codeField = 'EMP3COD';   
    protected $key1Field = 'CUR1COD';          
    protected $skipNewCode = true;
    
    protected $mapping = [
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
        'codigo'                        => 'CUR1COD',
        'fecha_inicio'                  => 'CURDINI',
        'fecha_fin'                     => 'CURDFIN',
        'cargo_delegacion'              => 'CAR2DEL',
        'cargo_codigo'                  => 'CAR2COD',
        'departamento_delegacion'       => 'DEP2DEL',
        'departamento_codigo'           => 'DEP2COD'
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'empleado_delegacion'       => 'nullable|string|max:10',
            'empleado_codigo'           => $isCreating ? 'required|integer' : 'nullable|integer',
            'codigo'                    => 'nullable|integer',
            'fecha_inicio'              => 'nullable|date',
            'fecha_fin'                 => 'nullable|date',
            'cargo_delegacion'          => 'nullable|string|max:10',
            'cargo_codigo'              => 'nullable|integer',
            'departamento_delegacion'   => 'nullable|string|max:10',
            'departamento_codigo'       => 'nullable|integer',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del empleado 
        if (!empty($data['empleado_codigo'])) {
            $employee = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', $data['empleado_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El empleado no existe");
            }
        }  
        
        // Valida la existencia del cargo 
        if (!empty($data['cargo_codigo'])) {
            $chargue = DB::connection('dynamic')->table('GRHCAR')
                ->where('DEL3COD', $data['cargo_delegacion'] ?? '')
                ->where('CAR1COD', $data['cargo_codigo'])
                ->first(); 
            if (!$chargue) {
                throw new \Exception("El cargo no existe");
            }
        }  
        
        // Valida la existencia del departamento 
        if (!empty($data['departamento_codigo'])) {
            $department = DB::connection('dynamic')->table('GRHDEP')
                ->where('DEL3COD', $data['departamento_delegacion'] ?? '')
                ->where('DEP1COD', $data['departamento_codigo'])
                ->first(); 
            if (!$department) {
                throw new \Exception("El departamento no existe");
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
                $data['codigo'] = $this->getNextResumeCode(
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
     * Obtiene el siguiente código de currículum para el empleado de entrada.
     * 
     * @param string $employeeDelegation - Delegación del emppleado para filtrar los currículums.
     * @param string $employeeCode - Código del empleado para filtrar los currículums.
     * @return string - El siguiente código de currículum
     */
    private function getNextResumeCode($employeeDelegation, $employeeCode)
    {
        $maxPoint = DB::connection('dynamic')->table('GRHCUR')
            ->where('EMP3DEL', $employeeDelegation)
            ->where('EMP3COD', $employeeCode) 
            ->max('CUR1COD');    

        return $maxPoint ? $maxPoint + 1 : 1;
    }    
}