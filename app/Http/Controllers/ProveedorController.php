<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ProveedorController extends BaseController
{
    protected $table = 'SINPRO';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'PRO1COD';    
    protected $inactiveField = 'PROBBAJ';
    protected $searchFields = ['PROCNOM', 'PROCRAS'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'PRO1COD',
        'nombre'                        => 'PROCNOM',
        'razon_social'                  => 'PROCRAS',
        'direccion'                     => 'PROCDIR',
        'poblacion'                     => 'PROCPOB',
        'provincia'                     => 'PROCPRO',
        'codigo_postal'                 => 'PROCCOP',
        'telefono'                      => 'PROCTEL',
        'movil'                         => 'PROCMOV',
        'fax'                           => 'PROCFAX',
        'persona_contacto'              => 'PROCPEC',
        'nif'                           => 'PROCNIF',
        'email'                         => 'PROCEMA',
        'web'                           => 'PROCWEB',
        'fecha_alta'                    => 'PRODALT',
        'fecha_baja'                    => 'PRODBAJ',
        'es_proveedor_aceptado'         => 'PROBACE',
        'productos_suministrados'       => 'PROCSUM',
        'plazo_entrega'                 => 'PROCPLE',
        'pedido_minimo'                 => 'PROCPMI',
        'observaciones'                 => 'PROCOBS',
        'es_laboratorio_subcontratado'  => 'PROBLAS',
        'es_baja'                       => 'PROBBAJ',
        'tipo_evaluacion_delegacion'    => 'TIE2DEL',
        'tipo_evaluacion_codigo'        => 'TIE2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                    => 'nullable|string|max:10',
            'codigo'                        => 'nullable|string|max:15',
            'nombre'                        => 'nullable|string|max:255',
            'razon_social'                  => 'nullable|string|max:255',
            'direccion'                     => 'nullable|string|max:255',
            'poblacion'                     => 'nullable|string|max:100',
            'provincia'                     => 'nullable|string|max:100',
            'codigo_postal'                 => 'nullable|string|max:10',
            'telefono'                      => 'nullable|string|max:40',
            'movil'                         => 'nullable|string|max:20',
            'fax'                           => 'nullable|string|max:20',
            'persona_contacto'              => 'nullable|string|max:50',
            'nif'                           => 'nullable|string|max:15',
            'email'                         => 'nullable|string|max:100',
            'web'                           => 'nullable|string|max:100',
            'fecha_alta'                    => 'nullable|date',
            'fecha_baja'                    => 'nullable|date',
            'es_proveedor_aceptado'         => 'nullable|string|in:T,F|max:1',
            'productos_suministrados'       => 'nullable|string',
            'plazo_entrega'                 => 'nullable|string|max:50',
            'pedido_minimo'                 => 'nullable|string|max:50',
            'observaciones'                 => 'nullable|string',
            'es_laboratorio_subcontratado'  => 'nullable|string|in:T,F|max:1',
            'es_baja'                       => 'nullable|string|in:T,F|max:1',
            'tipo_evaluacion_delegacion'    => 'nullable|string|max:10',
            'tipo_evaluacion_codigo'        => 'nullable|integer',
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

        // Valida la existencia del tipo de evaluación
        if (!empty($data['tipo_evaluacion_codigo'])) {
            $evaluationType = DB::table('SINTIE')
                ->where('DEL3COD', $data['tipo_evaluacion_delegacion'] ?? '')
                ->where('TIE1COD', $data['tipo_evaluacion_codigo'])
                ->first(); 
            if (!$evaluationType) {
                throw new \Exception("El tipo de evaluación no existe");
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre de proveedor no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::table('SINPRO')->where('PROCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('PRO1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del proveedor ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo proveedor no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('SINPRO')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('PRO1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del proveedor ya está en uso");
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
        // No se requieren validaciones antes de borrar aunque está pendiente integrar compras
    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra las relaciones del proveedor con productos suministrados
        DB::table('ALMPYP')
        ->where('PRO3DEL', $delegation)
        ->where('PRO3COD', $code)
        ->delete();

        // Borra las relaciones del proveedor con plantillas
        DB::table('PLAPYP')
        ->where('DEL3PRO', $delegation)
        ->where('PRO3COD', $code)
        ->delete();

        // Documentos a la papelera
        DB::table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('PRO2COD', $code)
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