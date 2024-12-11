<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class TarifaController extends BaseController
{
    protected $table = 'LABTAR';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'TAR1COD';    
    protected $inactiveField = 'TARBBAJ';
    protected $searchFields = ['TARCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'TAR1COD',
        'descripcion'                   => 'TARCDES',
        'es_baja'                       => 'TARBBAJ',
        'orden'                         => 'TARNORD'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:50',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'orden'                     => 'nullable|integer'
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
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre de la tarifa no esté en uso
        if (!empty($data['descripcion'])) {            
            $existingRecord = DB::table('LABTAR')->where('TARCDES', $data['descripcion']);
            if (!$isCreating) {
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('TAR1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                         
            }
            $existingRecord = $existingRecord->first();            
            if ($existingRecord) {
                throw new \Exception("La descripción de la tarifa ya está en uso");
            }
        }   

        // Comprueba que el código para la nueva tarifa no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('LABTAR')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('TAR1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la tarifa ya está en uso");
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

        // Comprueba que no esté referenciada en clientes
        $usedInAnotherTable = DB::table('SINCLI')
            ->where('TAR2DEL', $delegation)
            ->where('TAR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La tarifa no puede ser eliminada porque está siendo referenciada en clientes");
        }

        // Comprueba que no esté referenciada en operaciones
        $usedInAnotherTable = DB::table('LABOPE')
            ->where('TAR2DEL', $delegation)
            ->where('TAR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La tarifa no puede ser eliminada porque está siendo referenciada en operaciones");
        }        

        // Comprueba que no esté referenciada en planificaciones
        $usedInAnotherTable = DB::table('LABPLO')
            ->where('TAR2DEL', $delegation)
            ->where('TAR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La tarifa no puede ser eliminada porque está siendo referenciada en planificaciones");
        }  

        // Comprueba que no esté referenciada en presupuestos
        $usedInAnotherTable = DB::table('FACPRE')
            ->where('TAR2DEL', $delegation)
            ->where('TAR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La tarifa no puede ser eliminada porque está siendo referenciada en presupuestos");
        }

        // Comprueba que no esté referenciada en contratos
        $usedInAnotherTable = DB::table('FACCON')
            ->where('TAR2DEL', $delegation)
            ->where('TAR2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La tarifa no puede ser eliminada porque está siendo referenciada en contratos");
        }        
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra precios por tarifa en servicios
        DB::table('LABSYF')
        ->where('TAR3DEL', $delegation)
        ->where('TAR3COD', $code)
        ->delete();        

        // Borra precios por tarifa en técnicas
        DB::table('LABTYF')
        ->where('TAR3DEL', $delegation)
        ->where('TAR3COD', $code)
        ->delete();
    }
    
    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}