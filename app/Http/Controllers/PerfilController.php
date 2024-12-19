<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class PerfilController extends BaseController
{
    protected $table = 'ACCPER';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'PER1COD';    
    protected $searchFields = ['PERCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'PER1COD',
        'descripcion'                   => 'PERCDES',
        'estado_desde'                  => 'PERNESD',
        'estado_hasta'                  => 'PERNESH',
        'precios_restringidos'          => 'PERBPRE',
        'campos_operaciones'            => 'PERCCAO',
        'campos_resultados'             => 'PERCCAR',
        'tipo_firma_delegacion'         => 'TIF2DEL',
        'tipo_firma_codigo'             => 'TIF2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:50',
            'estado_desde'              => 'nullable|integer|in:0,1,2,3,4,5,6,7|',
            'estado_hasta'              => 'nullable|integer|in:0,1,2,3,4,5,6,7|',
            'precios_restringidos'      => 'nullable|string|in:T,F|max:1',
            'campos_operaciones'        => 'nullable|string',
            'campos_resultados'         => 'nullable|string',
            'tipo_firma_delegacion'     => 'nullable|string|max:10',
            'tipo_firma_codigo'         => 'nullable|integer',
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

        // Valida la existencia del tipo de firma 
        if (!empty($data['tipo_firma_codigo'])) {
            $sign = DB::table('LABTIF')
                ->where('DEL3COD', $data['tipo_firma_delegacion'] ?? '')
                ->where('TIF1COD', $data['tipo_firma_codigo'])
                ->first(); 
            if (!$sign) {
                throw new \Exception("El perfil de usuario no existe");
            }
        }         
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción del perfil no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::table('ACCPER')->where('PERCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('PER1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripción del perfil ya está en uso");
            }
        }

        // Comprueba que el código para el perfil no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('ACCPER')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('PER1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del perfil ya está en uso");
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
        // Comprueba que el usuario no está vinculado a ningún usuario
        $usedInAnotherTable = DB::table('ACCUSU')
            ->where('PER2DEL', $delegation)
            ->where('PER2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El perfil no puede ser eliminado porque está siendo referenciado en algún usuario");
        }          
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra funcionalidades del perfil
        DB::table('ACCPYF')
            ->where('DEL3COD', $delegation)
            ->where('PER3COD', $code)
            ->delete();

        // Borra la configuración de carpetas compartidas
        DB::table('DOCDYP')
            ->where('PER3DEL', $delegation)
            ->where('PER3COD', $code)
            ->delete();
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}