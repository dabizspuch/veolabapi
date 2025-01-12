<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class CursoProfesorController extends BaseController
{
    protected $table = 'GRHPRO';
    protected $delegationField = 'PAF3DEL';
    protected $codeField = 'PAF3COD';  
    protected $key1Field = 'EMP3COD';          
    protected $key2Field = 'EMP3DEL';          
    protected $skipNewCode = true;          

    protected $mapping = [
        'curso_delegacion'              => 'PAF3DEL',
        'curso_codigo'                  => 'PAF3COD',
        'empleado_delegacion'           => 'EMP3DEL',
        'empleado_codigo'               => 'EMP3COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'curso_delegacion'          => 'nullable|string|max:10',
            'curso_codigo'              => 'required|string|max:15',
            'empleado_delegacion'       => 'nullable|string|max:10',
            'empleado_codigo'           => 'required|integer',
        ];        

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del curso 
        if (!empty($data['curso_codigo'])) {
            $order = DB::connection('dynamic')->table('GRHPAF')
                ->where('DEL3COD', $data['curso_delegacion'] ?? '')
                ->where('PAF1COD', $data['curso_codigo'])
                ->first(); 
            if (!$order) {
                throw new \Exception("El curso no existe");
            }
        } 

        // Valida la existencia del empleado 
        if (!empty($data['empleado_codigo'])) {
            $analyst = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', $data['empleado_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_codigo'])
                ->first(); 
            if (!$analyst) {
                throw new \Exception("El empleado no existe");
            }
        }               
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Comprueba que no estaban ya enlazados
        $exist = DB::connection('dynamic')->table('GRHPRO')
            ->where('PAF3DEL', $data['curso_delegacion'] ?? '')
            ->where('PAF3COD', $data['curso_codigo'])
            ->where('EMP3DEL', $data['empleado_delegacion'] ?? '')
            ->where('EMP3COD', $data['empleado_codigo'])
            ->exists();
        if ($exist) {
            throw new \Exception("El profesor ya estaba asociado al curso");            
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