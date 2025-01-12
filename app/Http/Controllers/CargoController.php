<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class CargoController extends BaseController
{
    protected $table = 'GRHCAR';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'CAR1COD';    
    protected $inactiveField = 'CARBBAJ';
    protected $searchFields = ['CARCNOM', 'CARCOBS'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'CAR1COD',
        'nombre'                        => 'CARCNOM',
        'certificaciones'               => 'CARCCEA',
        'requerimientos'                => 'CARCRFA',
        'experiencia'                   => 'CARCEXR',
        'caracteristicas'               => 'CARCCAP',
        'observaciones'                 => 'CARCOBS',
        'es_baja'                       => 'CARBBAJ',
        'departamento_delegacion'       => 'DEP2DEL',
        'departamento_codigo'           => 'DEP2COD',
        'cargo_superior_delegacion'     => 'CAR2DEL',
        'cargo_superior_codigo'         => 'CAR2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'nombre'                    => 'nullable|string|max:100',
            'certificaciones'           => 'nullable|string',
            'requerimientos'            => 'nullable|string',
            'experiencia'               => 'nullable|string',
            'caracteristicas'           => 'nullable|string',
            'observaciones'             => 'nullable|string',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'departamento_delegacion'   => 'nullable|string|max:10',
            'departamento_codigo'       => 'nullable|integer',
            'cargo_superior_delegacion' => 'nullable|string|max:10',
            'cargo_superior_codigo'     => 'nullable|integer',
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

        // Valida la existencia del departamento 
        if (!empty($data['departamento_codigo'])) {
            $employee = DB::connection('dynamic')->table('GRHDEP')
                ->where('DEL3COD', $data['departamento_delegacion'] ?? '')
                ->where('DEP1COD', $data['departamento_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El departamento no existe");
            }
        } 
        
        // Valida la existencia del cargo superior 
        if (!empty($data['cargo_superior_codigo'])) {
            $employee = DB::connection('dynamic')->table('GRHCAR')
                ->where('DEL3COD', $data['cargo_superior_delegacion'] ?? '')
                ->where('CAR1COD', $data['cargo_superior_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El cargo no existe");
            }
        }                
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre del cargo no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::connection('dynamic')->table('GRHCAR')->where('CARCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('CAR1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del cargo ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo cargo no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('GRHCAR')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('CAR1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del cargo ya está en uso");
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
        // Comprueba que el cargo no está vinculado a ningún empleado
        $usedInAnotherTable = DB::connection('dynamic')->table('GRHEYC')
            ->where('CAR3DEL', $delegation)
            ->where('CAR3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cargo no puede ser eliminado porque está siendo referenciado en algún empleado");
        }  
        
        // Comprueba que el cargo no está vinculado a otro cargo 
        $usedInAnotherTable = DB::connection('dynamic')->table('GRHCAR')
            ->where('CAR2DEL', $delegation)
            ->where('CAR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cargo no puede ser eliminado porque está siendo referenciado por otro cargo");
        }      
        
        // Comprueba que el cargo no está vinculado a ningún currículum 
        $usedInAnotherTable = DB::connection('dynamic')->table('GRHCUR')
            ->where('CAR2DEL', $delegation)
            ->where('CAR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cargo no puede ser eliminado porque está siendo referenciado en algún currículum");
        }         
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra las taras del cargo
        DB::connection('dynamic')->table('GRHTAR')
        ->where('CAR3DEL', $delegation)
        ->where('CAR3COD', $code)
        ->delete();
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}