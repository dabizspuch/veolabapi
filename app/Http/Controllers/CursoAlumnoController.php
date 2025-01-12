<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class CursoAlumnoController extends BaseController
{
    protected $table = 'GRHALU';
    protected $delegationField = 'PAF3DEL';
    protected $codeField = 'PAF3COD';   
    protected $key1Field = 'EMP3COD';
    protected $key2Field = 'EMP3DEL';
    protected $searchFields = ['ALUCCOM'];          
    protected $skipNewCode = true;
    
    protected $mapping = [
        'curso_delegacion'              => 'PAF3DEL',
        'curso_codigo'                  => 'PAF3COD',
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
        'evaluacion'                    => 'ALUNEVA',
        'adjunta_evidencia'             => 'ALUBADE',
        'no_finalizo'                   => 'ALUBNAS',
        'comentarios'                   => 'ALUCCOM',
        'empleado_evaluador_delegacion' => 'EMP2DEL',
        'empleado_evaluador_codigo'     => 'EMP2COD'
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'curso_delegacion'              => 'nullable|string|max:10',
            'curso_codigo'                  => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
            'empleado_delegacion'           => 'nullable|string|max:10',
            'empleado_codigo'               => $isCreating ? 'required|integer' : 'nullable|integer',
            'evaluacion'                    => 'nullable|integer',
            'adjunta_evidencia'             => 'nullable|string|in:T,F|max:1',
            'no_finalizo'                   => 'nullable|string|in:T,F|max:1',
            'comentarios'                   => 'nullable|string|max:255',
            'empleado_evaluador_delegacion' => 'nullable|string|max:10',
            'empleado_evaluador_codigo'     => 'nullable|integer'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del curso 
        if (!empty($data['curso_codigo'])) {
            $course = DB::connection('dynamic')->table('GRHPAF')
                ->where('DEL3COD', $data['curso_delegacion'] ?? '')
                ->where('PAF1COD', $data['curso_codigo'])
                ->first(); 
            if (!$course) {
                throw new \Exception("El curso no existe");
            }
        }          

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
        
        // Valida la existencia del empleado que evalúa 
        if (!empty($data['empleado_evaluador_codigo'])) {
            $employee = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', $data['empleado_evaluador_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_evaluador_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El empleado evaluador no existe");
            }
        }        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Comprueba que no estaban ya enlazados
            $exist = DB::connection('dynamic')->table('GRHALU')
                ->where('PAF3DEL', $data['curso_delegacion'] ?? '')
                ->where('PAF3COD', $data['curso_codigo'])
                ->where('EMP3DEL', $data['empleado_delegacion'] ?? '')
                ->where('EMP3COD', $data['empleado_codigo'])
                ->exists();
            if ($exist) {
                throw new \Exception("El curso y el empleado ya estaban enlazados");            
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['curso_delegacion'], 
                $data['curso_codigo'], 
                $data['empleado_delegacion'], 
                $data['empleado_codigo'], 
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