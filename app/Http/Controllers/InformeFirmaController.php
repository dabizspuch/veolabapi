<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class InformeFirmaController extends BaseController
{
    protected $table = 'LABFIR';
    protected $delegationField = 'INF3DEL';
    protected $key1Field = 'INF3SER';          
    protected $codeField = 'INF3COD';  
    protected $key2Field = 'TIF3COD';          
    protected $key3Field = 'TIF3DEL';
    protected $key4Field = 'DEP3COD';          
    protected $key5Field = 'DEP3DEL';
    protected $skipNewCode = true;          
    
    protected $mapping = [
        'informe_delegacion'            => 'INF3DEL',
        'informe_serie'                 => 'INF3SER',
        'informe_codigo'                => 'INF3COD',
        'firma_delegacion'              => 'TIF3DEL',
        'firma_codigo'                  => 'TIF3COD',
        'departamento_delegacion'       => 'DEP3DEL',
        'departamento_codigo'           => 'DEP3COD',
        'fecha_firma'                   => 'FIRDFEC',
        'estado_firma'                  => 'FIRBVAL',
        'comentarios'                   => 'FIRCCOM',
        'usuario_delegacion'            => 'USU2DEL',
        'usuario_codigo'                => 'USU2COD'      
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'informe_delegacion'        => 'nullable|string|max:10',
            'informe_serie'             => 'nullable|string|max:10',
            'informe_codigo'            => 'required|integer',
            'firma_delegacion'          => 'nullable|string|max:10',
            'firma_codigo'              => 'required|integer',
            'departamento_delegacion'   => 'nullable|string|max:10',
            'departamento_codigo'       => 'required|integer',
            'fecha_firma'               => 'nullable|date',
            'estado_firma'              => 'nullable|string|in:T,F|max:1',
            'comentarios'               => 'nullable|string|max:255',
            'usuario_delegacion'        => 'nullable|string|max:10',
            'usuario_codigo'            => 'required|string|max:15',                       
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del informe 
        if (!empty($data['informe_codigo'])) {
            $report = DB::table('LABINF')
                ->where('DEL3COD', $data['informe_delegacion'] ?? '')
                ->where('INF1SER', $data['informe_serie'] ?? '')
                ->where('INF1COD', $data['informe_codigo'])
                ->first(); 
            if (!$report) {
                throw new \Exception("El informe no existe");
            }
        }        

        // Valida la existencia del tipo de firma 
        if (!empty($data['firma_codigo'])) {
            $sign = DB::table('LABTIF')
                ->where('DEL3COD', $data['firma_delegacion'] ?? '')
                ->where('TIF1COD', $data['firma_codigo'])
                ->first(); 
            if (!$sign) {
                throw new \Exception("El tipo de firma no existe");
            }
        }
        
        // Valida la existencia del departamento
        if (isset($data['departamento_codigo']) && $data['departamento_codigo'] !== 0) {
            $sign = DB::table('GRHDEP')
                ->where('DEL3COD', $data['departamento_delegacion'] ?? '')
                ->where('DEP1COD', $data['departamento_codigo'])
                ->first(); 
            if (!$sign) {
                throw new \Exception("El departamento no existe");
            }
        }  
        
        // Valida la existencia del usuario 
        if (!empty($data['usuario_codigo'])) {
            $sign = DB::table('ACCUSU')
                ->where('DEL3COD', $data['usuario_delegacion'] ?? '')
                ->where('USU1COD', $data['usuario_codigo'])
                ->first(); 
            if (!$sign) {
                throw new \Exception("El usuario no existe");
            }
        }        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la firma no estaba ya aplicada
        if ($isCreating) { 
            $exist = DB::table('LABFIR')
                ->where('INF3DEL', $data['informe_delegacion'] ?? '')
                ->where('INF3SER', $data['informe_serie'] ?? '')
                ->where('INF3COD', $data['informe_codigo'])
                ->where('TIF3DEL', $data['firma_delegacion'] ?? '')
                ->where('TIF3COD', $data['firma_codigo'])
                ->where('DEP3DEL', $data['departamento_delegacion'] ?? '')
                ->where('DEP3COD', $data['departamento_codigo'] ?? 0)
                ->exists();
            if ($exist) {
                throw new \Exception("La firma ya existe");            
            }
        }
        
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