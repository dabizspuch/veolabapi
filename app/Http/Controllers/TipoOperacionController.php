<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class TipoOperacionController extends BaseController
{
    protected $table = 'LABTIO';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'TIO1COD';    
    protected $inactiveField = 'TIOBBAJ';
    protected $searchFields = ['TIOCNOM'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'TIO1COD',
        'nombre'                        => 'TIOCNOM',
        'es_predeterminado'             => 'TIOBPRE',
        'es_gestionable_equipos'        => 'TIOBGDE',
        'es_gestionable_parametros'     => 'TIOBGDT',        
        'es_baja'                       => 'TIOBBAJ',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'nombre'                    => 'nullable|string|max:50',
            'es_predeterminado'         => 'nullable|string|in:T,F|max:1',
            'es_gestionable_equipos'    => 'nullable|string|in:T,F|max:1',
            'es_gestionable_parametros' => 'nullable|string|in:T,F|max:1',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
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

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre del tipo de operación no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::table('LABTIO')->where('TIOCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('TIO1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del tipo de operación ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo tipo de operación no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('LABTIO')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('TIO1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del tipo de operación ya está en uso");
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
        // Comprueba que no se trata de un tipo de operación predeterminado
        $result = DB::table('LABTIO')
            ->where('DEL3COD', $delegation)
            ->where('TIO1COD', $code)
            ->first();
        if ($result->TIOBPRE == 'T') {
            throw new \Exception("El tipo de operación no puede ser eliminado porque es predeterminado del sistema");
        }

        // Operaciones
        $usedInAnotherTable = DB::table('LABOPE')
            ->where('TIO2DEL', $delegation)
            ->where('TIO2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El tipo de operación no puede ser eliminado porque está siendo referenciado en alguna operación");
        }

        // Planificaciones
        $usedInAnotherTable = DB::table('LABPLO')
            ->where('TIO2DEL', $delegation)
            ->where('TIO2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El tipo de operación no puede ser eliminado porque está siendo referenciado en alguna planificación");
        }

        // Servicios
        $usedInAnotherTable = DB::table('LABSER')
            ->where('TIO2DEL', $delegation)
            ->where('TIO2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El tipo de operación no puede ser eliminado porque está siendo referenciado en algún servicio");
        }
        
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {            
        // Borra vínculos con tipos de operaciones
        DB::table('LABOYM')
            ->where('DEL3TIO', $delegation)
            ->where('TIO3COD', $code)
            ->delete();             
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}