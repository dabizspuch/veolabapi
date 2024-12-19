<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class UsuarioController extends BaseController
{
    protected $table = 'ACCUSU';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'USU1COD';    
    protected $inactiveField = 'USUBBAJ';
    protected $searchFields = ['USUCNOM', 'USUCOBS'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'USU1COD',
        'nombre'                        => 'USUCNOM',
        'es_conectado'                  => 'USUBCON',
        'idioma'                        => 'USUNIDI',
        'certificado'                   => 'USUCCER',
        'usuario_windows'               => 'USUCWIN',
        'sid_windows'                   => 'USUCSID',
        'ocultar_aviso_minimizar'       => 'USUBOAP',
        'observaciones'                 => 'USUCOBS',
        'tipo'                          => 'USUNTIP',
        'fecha_alta'                    => 'USUDALT',
        'fecha_baja'                    => 'USUDBAJ',
        'fecha_ultimo_acceso'           => 'USUTULT',
        'perfil_delegacion'             => 'PER2DEL',
        'perfil_codigo'                 => 'PER2COD',
        'empleado_delegacion'           => 'EMP2DEL',
        'empleado_codigo'               => 'EMP2COD',
        'cliente_delegacion'            => 'CLI2DEL',
        'cliente_codigo'                => 'CLI2COD'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|string|max:15',
            'nombre'                    => 'nullable|string|max:100',
            'es_conectado'              => 'nullable|string|in:T,F|max:1',
            'idioma'                    => 'nullable|integer|min:0',
            'certificado'               => 'nullable|string|max:100',
            'usuario_windows'           => 'nullable|string|max:50',
            'sid_windows'               => 'nullable|string|max:50',
            'ocultar_aviso_minimizar'   => 'nullable|string|in:T,F|max:1',
            'observaciones'             => 'nullable|string',
            'tipo'                      => 'nullable|integer',
            'fecha_alta'                => 'nullable|date',
            'fecha_baja'                => 'nullable|date',
            'fecha_ultimo_acceso'       => 'nullable|date',
            'perfil_delegacion'         => 'nullable|string|max:10',
            'perfil_codigo'             => 'nullable|integer',
            'empleado_delegacion'       => 'nullable|string|max:10',
            'empleado_codigo'           => 'nullable|integer',
            'cliente_delegacion'        => 'nullable|string|max:10',
            'cliente_codigo'            => 'nullable|string|max:15'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {    
        // Valida la existencia de la delegación 
        if (!empty($data['delegacion'])) {
            $delegation = DB::table('ACCDEL')
                ->where('DEL1COD', $data['delegacion'])
                ->first(); 
            if (!$delegation) {
                throw new \Exception("La delegación no existe");
            }
        }

        // Valida la existencia del perfil 
        if (!empty($data['perfil_codigo'])) {
            $profile = DB::table('ACCPER')
                ->where('DEL3COD', $data['perfil_delegacion'] ?? '')
                ->where('PER1COD', $data['perfil_codigo'])
                ->first(); 
            if (!$profile) {
                throw new \Exception("El perfil de usuario no existe");
            }
        } 
        
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
        
        // Valida la existencia del cliente 
        if (!empty($data['cliente_codigo'])) {
            $client = DB::table('SINCLI')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_codigo'])
                ->first(); 
            if (!$client) {
                throw new \Exception("El cliente no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre del usuario no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::table('ACCUSU')->where('USUCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('USU1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del usuario ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo usuario no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('ACCUSU')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('USU1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del usuario ya está en uso");
                }
            }
        }

        // Excluir campos clave de los datos a actualizar porque no serán editables
        if (!$isCreating) { 
            unset( 
                $data['delegacion'], 
                $data['codigo'] 
            );
        } 
                
        return $data;        
    }
        
    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Comprueba que el usuario no está vinculado a ninguna versión de documento
        $usedInAnotherTable = DB::table('DOCVER')
            ->where('USU2DEL', $delegation)
            ->where('USU2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El usuario no puede ser eliminado porque está siendo referenciado en alguna versión de documento");
        }  
        
        // Comprueba que el usuario no está vinculado a ningún movimiento de almacén
        $usedInAnotherTable = DB::table('ALMMOV')
            ->where('USU2DEL', $delegation)
            ->where('USU2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El usuario no puede ser eliminado porque está siendo referenciado en algún movimiento de inventario");
        }  
        
        // Comprueba que el usuario no está vinculado a algún informe
        $usedInAnotherTable = DB::table('LABINF')
            ->where('USU2DEL', $delegation)
            ->where('USU2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El usuario no puede ser eliminado porque está siendo referenciado en algún informe");
        }    
        
        // Comprueba que el usuario no está vinculado a alguna firma
        $usedInAnotherTable = DB::table('LABFIR')
            ->where('USU2DEL', $delegation)
            ->where('USU2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El usuario no puede ser eliminado porque está siendo referenciado en alguna firma");
        }        
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra la configuración de ventanas
        DB::table('ACCUYV')
            ->where('DEL3COD', $delegation)
            ->where('USU3COD', $code)
            ->delete();

        // Borra las sesiones
        DB::table('ACCSES')
            ->where('DEL3COD', $delegation)
            ->where('USU2COD', $code)
            ->delete();

        // Borra los avisos
        DB::table('ACCAVI')
            ->where('USU2DEL', $delegation)
            ->where('USU2COD', $code)
            ->delete();     
        
        // Borra las notificaciones
        DB::table('ACCNOT')
            ->where('USU2DEL', $delegation)
            ->where('USU2COD', $code)
            ->delete();   
        
        // Borra la firma digitalizada
        DB::table('ACCFIR')
            ->where('DEL3COD', $delegation)
            ->where('USU3COD', $code)
            ->delete();      
        
        // Borra la agenda del usuario
        DB::table('AGEAGE')
            ->where('USU3DEL', $delegation)
            ->where('USU3COD', $code)
            ->delete();  
            
        // Borra los eventos de agenda
        DB::table('AGEFEC')
            ->where('USU3DEL', $delegation)
            ->where('USU3COD', $code)
            ->delete();
            
        // Borra la lista de asistentes de los eventos de agenda borrados
        DB::table('AGEASI')
            ->where('USU3DEL', $delegation)
            ->where('USU3COD', $code)
            ->delete();

        // Borra el usuario como asistente de otros eventos
        DB::table('AGEASI')
            ->where('USA3DEL', $delegation)
            ->where('USA3COD', $code)
            ->delete();                        
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}