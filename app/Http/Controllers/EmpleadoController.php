<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EmpleadoController extends BaseController
{
    protected $table = 'GRHEMP';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'EMP1COD';    
    protected $inactiveField = 'EMPBBAJ';
    protected $searchFields = ['EMPCNOM'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'EMP1COD',
        'nombre'                        => 'EMPCNOM',
        'tratamiento'                   => 'EMPCTRA',
        'direccion'                     => 'EMPCDIR',
        'poblacion'                     => 'EMPCPOB',
        'provincia'                     => 'EMPCPRO',
        'codigo_postal'                 => 'EMPCCOP',
        'telefono'                      => 'EMPCTEL',
        'movil'                         => 'EMPCMOV',
        'nif'                           => 'EMPCNIF',
        'nss'                           => 'EMPCNSS',
        'cedula'                        => 'EMPCCEP',
        'abreviatura'                   => 'EMPCABR',
        'tipo'                          => 'EMPCTIP',
        'email'                         => 'EMPCEMA',
        'fecha_alta'                    => 'EMPDALT',
        'fecha_baja'                    => 'EMPDBAJ',
        'observaciones'                 => 'EMPCOBS',
        'es_analista'                   => 'EMPBANA',
        'es_recolector'                 => 'EMPBREC',
        'es_comercial'                  => 'EMPBCOM',
        'es_baja'                       => 'EMPBBAJ',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'        => 'nullable|string|max:10',
            'codigo'            => 'nullable|integer',
            'nombre'            => 'nullable|string|max:100',
            'tratamiento'       => 'nullable|string|max:10',
            'direccion'         => 'nullable|string|max:255',
            'poblacion'         => 'nullable|string|max:100',
            'provincia'         => 'nullable|string|max:100',
            'codigo_postal'     => 'nullable|string|max:10',
            'telefono'          => 'nullable|string|max:40',
            'movil'             => 'nullable|string|max:20',
            'nif'               => 'nullable|string|max:15',
            'nss'               => 'nullable|string|max:20',
            'cedula'            => 'nullable|string|max:20',
            'abreviatura'       => 'nullable|string|max:20',
            'tipo'              => 'nullable|string|max:50',
            'email'             => 'nullable|string|max:100|email',
            'fecha_alta'        => 'nullable|date',
            'fecha_baja'        => 'nullable|date',
            'observaciones'     => 'nullable|string',
            'es_analista'       => 'nullable|string|in:T,F|max:1',
            'es_recolector'     => 'nullable|string|in:T,F|max:1',
            'es_comercial'      => 'nullable|string|in:T,F|max:1',
            'es_baja'           => 'nullable|string|in:T,F|max:1',
        ];
        

        return $rules;
    }

    protected function validateRelationships(array $data)
    {    
        // Valida la existencia de la delegación 
        if (!empty($data['delegacion'])) {
            $delegation = DB::connection('dynamic')->table('ACCDEL')
                ->where('DEL1COD', $data['delegacion'])
                ->first(); 
            if (!$delegation) {
                throw new \Exception("La delegación no existe");
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre de empleado no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::connection('dynamic')->table('GRHEMP')->where('EMPCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('EMP1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del empleado ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo empleado no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('GRHEMP')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('EMP1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del empleado ya está en uso");
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
        $delegation = $delegation ?? '';

        // Comprueba que el empleado no esté asociado a operaciones como analista (LABOYE)
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOYE')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado como analista en alguna operación");
        } 

        // Comprueba que el empleado no esté asociado a operaciones como analista (LABRES)
        $usedInAnotherTable = DB::connection('dynamic')->table('LABRES')
            ->where('EMP2DEL', $delegation)
            ->where('EMP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado como analista en algún parámetro de operación");
        }         

        // Comprueba que el empleado no esté asociado a órdenes 
        $usedInAnotherTable = DB::connection('dynamic')->table('LABORE')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado a alguna orden");
        } 

        // Comprueba que el empleado no esté asocido a parámetros de planificación
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPYT')
            ->where('EMP2DEL', $delegation)
            ->where('EMP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado como analista en alguna planificación");
        }           
        
        // Comprueba que el empleado no esté asocido a operaciones como recolector
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOPE')
            ->where('EMP2DEL', $delegation)
            ->where('EMP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado como recolector en alguna operación");
        }
        
        // Comprueba que el empleado no esté asocido a planificaciones como recolector
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPLO')
            ->where('EMP2DEL', $delegation)
            ->where('EMP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado como recolector en alguna planificación");
        }

        // Comprueba que el empleado no esté asocido a parámetros
        $usedInAnotherTable = DB::connection('dynamic')->table('LABTYE')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado a algún parámetro");
        }     
        
        // Comprueba que el empleado no esté asocido a usuarios
        $usedInAnotherTable = DB::connection('dynamic')->table('ACCUSU')
            ->where('EMP2DEL', $delegation)
            ->where('EMP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado con algún usuario");
        }    
        
        // Comprueba que el empleado no esté asocido a algún presupuesto
        $usedInAnotherTable = DB::connection('dynamic')->table('FACPRE')
            ->where('EMP2DEL', $delegation)
            ->where('EMP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado con algún presupuesto");
        }          

        // Comprueba que el empleado no esté definido como alumno 
        $usedInAnotherTable = DB::connection('dynamic')->table('GRHALU')
            ->where(function ($query) use ($delegation, $code) {
                $query->where('EMP2DEL', $delegation)
                    ->where('EMP2COD', $code);
            })
            ->orWhere(function ($query) use ($delegation, $code) {
                $query->where('EMP3DEL', $delegation)
                    ->where('EMP3COD', $code);
            })
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está definido como alumno en alguna formación");
        }          
        
        // Comprueba que el empleado no esté asocido a algún residuo
        $usedInAnotherTable = DB::connection('dynamic')->table('LABRED')
            ->where('EMP2DEL', $delegation)
            ->where('EMP2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está vinculado con algún residuo");
        }    
        
        // Comprueba que el empleado no esté definido como profesor en algún curso
        $usedInAnotherTable = DB::connection('dynamic')->table('GRHPRO')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El empleado no puede ser eliminado porque está definido como profesor en algún curso");
        }           
    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra las relaciones con los cargos
        DB::connection('dynamic')->table('GRHEYC')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->delete();

        // Borra las asuencias
        DB::connection('dynamic')->table('GRHAUS')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->delete();

        // Borra el currículum
        DB::connection('dynamic')->table('GRHCUR')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->delete();

        // Borra la formación 
        DB::connection('dynamic')->table('GRHFOR')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->delete();    

        // Borra el vínculo con clientes 
        DB::connection('dynamic')->table('GRHCLI')
            ->where('EMP3DEL', $delegation)
            ->where('EMP3COD', $code)
            ->delete();             

        // Documentos a la papelera
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('EMP2COD', $code)
            ->update([
                'DIR2DEL' => $delegation,
                'DIR2COD' => 0
            ]);          
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}