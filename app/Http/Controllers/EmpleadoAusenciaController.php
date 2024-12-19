<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EmpleadoAusenciaController extends BaseController
{
    protected $table = 'GRHAUS';
    protected $delegationField = 'EMP3DEL';
    protected $codeField = 'EMP3COD';   
    protected $key1Field = 'AUS1COD';          
    protected $searchFields = ['AUSCDES'];
    protected $skipNewCode = true;
    
    protected $mapping = [
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
        'codigo'                        => 'AUS1COD',
        'fecha_inicio'                  => 'AUSDINI',
        'fecha_fin'                     => 'AUSDFIN',
        'descripcion'                   => 'AUSCDES'
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
            'descripcion'               => 'nullable|string|max:50',
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
                $data['codigo'] = $this->getNextAbsenceCode(
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
     * Obtiene el siguiente código de ausencia para el empleado de entrada.
     * 
     * @param string $employeeDelegation - Delegación del emppleado para filtrar las ausencias.
     * @param string $employeeCode - Código del empleado para filtrar las ausencias.
     * @return string - El siguiente código de ausencia
     */
    private function getNextAbsenceCode($employeeDelegation, $employeeCode)
    {
        $maxPoint = DB::table('GRHAUS')
            ->where('EMP3DEL', $employeeDelegation)
            ->where('EMP3COD', $employeeCode) 
            ->max('AUS1COD');    

        return $maxPoint ? $maxPoint + 1 : 1;
    }    
}