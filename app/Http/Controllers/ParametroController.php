<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroController extends BaseController
{
    protected $table = 'LABTEC';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'TEC1COD';    
    protected $inactiveField = 'TECBBAJ';
    protected $searchFields = ['TECCNOM', 'TECCNOI'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'TEC1COD',
        'nombre'                        => 'TECCNOM',
        'nombre_informes'               => 'TECCNOI',
        'id_igeo'                       => 'TECCIGC',
        'es_cursiva'                    => 'TECBCUR',
        'fecha_acreditacion'            => 'TECDACR',
        'parametro'                     => 'TECCPAR',
        'abreviatura'                   => 'TECCABR',
        'numero_cas'                    => 'TECCCAS',
        'precio'                        => 'TECNPRE',
        'descuento'                     => 'TECCDTO',
        'unidades'                      => 'TECCUNI',
        'leyenda'                       => 'TECCLEY',
        'metodologia'                   => 'TECCMET',
        'metodologia_abreviada'         => 'TECCMEA',
        'normativa'                     => 'TECCNOR',
        'tiempo_prueba'                 => 'TECNTIE',
        'tiempo_descarte'               => 'TECNTID',
        'limite_cuantificacion'         => 'TECCLIM',
        'valor_minimo_detectable'       => 'TECCMIN',
        'incertidumbre'                 => 'TECCINC',
        'instruccion'                   => 'TECCINS',
        'es_exportable'                 => 'TECBEXP',
        'codigo_metodo_sinac'           => 'TECNMET',
        'tipo_metodo_sinac'             => 'TECNTME',
        'numero_norma_sinac'            => 'TECCNUN',
        'es_acreditado_sinac'           => 'TECBACR',
        'es_validado_sinac'             => 'TECBVAL',
        'es_equivalente_sinac'          => 'TECBEQU',
        'es_sin_cualificacion_sinac'    => 'TECBSIC',
        'es_uso_rutina_sinac'           => 'TECBMER',
        'codigo_parametro_sinac'        => 'TECCCOP',
        'exactitud_sinac'               => 'TECNEXA',
        'precision_sinac'               => 'TECNPRC',
        'limite_deteccion_sinac'        => 'TECNLID',
        'limite_cuantificacion_sinac'   => 'TECNLIC',
        'codigo_laboratorio_sinac'      => 'TECNCOL',
        'decimales_sinac'               => 'TECNDEC',
        'fecha_baja'                    => 'TECDBAJ',
        'es_baja'                       => 'TECBBAJ',
        'seccion_delegacion'            => 'SEC2DEL',
        'seccion_codigo'                => 'SEC2COD',
    ];    

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                    => 'nullable|string|max:10',
            'codigo'                        => 'nullable|string|max:30',
            'nombre'                        => 'nullable|string|max:255',
            'nombre_informes'               => 'nullable|string|max:255',
            'id_igeo'                       => 'nullable|string|max:20',
            'es_cursiva'                    => 'nullable|string|in:T,F|max:1',
            'fecha_acreditacion'            => 'nullable|date',
            'parametro'                     => 'nullable|string|max:100',
            'abreviatura'                   => 'nullable|string|max:50',
            'numero_cas'                    => 'nullable|string|max:50',
            'precio'                        => 'nullable|numeric|min:0',
            'descuento'                     => 'nullable|string|max:15',
            'unidades'                      => 'nullable|string|max:50',
            'leyenda'                       => 'nullable|string|max:100',
            'metodologia'                   => 'nullable|string|max:255',
            'metodologia_abreviada'         => 'nullable|string|max:255',
            'normativa'                     => 'nullable|string|max:100',
            'tiempo_prueba'                 => 'nullable|integer|min:0',
            'tiempo_descarte'               => 'nullable|integer|min:0',
            'limite_cuantificacion'         => 'nullable|string|max:50',
            'valor_minimo_detectable'       => 'nullable|string|max:50',
            'incertidumbre'                 => 'nullable|string|max:50',
            'instruccion'                   => 'nullable|string',
            'es_exportable'                 => 'nullable|string|in:T,F|max:1',
            'codigo_metodo_sinac'           => 'nullable|integer|min:0',
            'tipo_metodo_sinac'             => 'nullable|integer|min:0',
            'numero_norma_sinac'            => 'nullable|string|max:50',
            'es_acreditado_sinac'           => 'nullable|string|in:T,F|max:1',
            'es_validado_sinac'             => 'nullable|string|in:T,F|max:1',
            'es_equivalente_sinac'          => 'nullable|string|in:T,F|max:1',
            'es_sin_cualificacion_sinac'    => 'nullable|string|in:T,F|max:1',
            'es_uso_rutina_sinac'           => 'nullable|string|in:T,F|max:1',
            'codigo_parametro_sinac'        => 'nullable|string|max:10',
            'exactitud_sinac'               => 'nullable|numeric|min:0',
            'precision_sinac'               => 'nullable|numeric|min:0',
            'limite_deteccion_sinac'        => 'nullable|numeric|min:0',
            'limite_cuantificacion_sinac'   => 'nullable|numeric|min:0',
            'codigo_laboratorio_sinac'      => 'nullable|numeric|min:0',
            'decimales_sinac'               => 'nullable|integer|min:0',
            'fecha_baja'                    => 'nullable|date',
            'es_baja'                       => 'nullable|string|in:T,F|max:1',
            'seccion_delegacion'            => 'nullable|string|max:10',
            'seccion_codigo'                => 'nullable|integer|min:0',
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

        // Valida la existencia de la sección 
        if (!empty($data['seccion_codigo'])) {
            $section = DB::connection('dynamic')->table('LABSEC')
                ->where('DEL3COD', $data['seccion_delegacion'] ?? '')
                ->where('SEC1COD', $data['seccion_codigo'])
                ->first(); 
            if (!$section) {
                throw new \Exception("La sección no existe");
            }
        }         
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre del parámetro no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::connection('dynamic')->table('LABTEC')->where('TECCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('TEC1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del parámetro ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo parámetro no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABTEC')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('TEC1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del parámetro ya está en uso");
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
        // Resultados
        $usedInAnotherTable = DB::connection('dynamic')->table('LABRES')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en algún resultado");
        }

        // Servicios 
        $usedInAnotherTable = DB::connection('dynamic')->table('LABSYT')
            ->where('DEL3TEC', $delegation)
            ->where('TEC3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en algún servicio");
        }

        // Cartas de control 
        $usedInAnotherTable = DB::connection('dynamic')->table('LABCYT')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en alguna carta de control");
        }

        // Líneas de factura 
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIF')
            ->where('TEC2DEL', $delegation)
            ->where('TEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en alguna factura");
        }     
        
        // Líneas de contrato 
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIC')
            ->where('TEC2DEL', $delegation)
            ->where('TEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en alguna contrato");
        }          

        // Líneas de presupuesto 
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIP')
            ->where('TEC2DEL', $delegation)
            ->where('TEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en algún presupuesto");
        }         
        
        // Movimientos de inventario 
        $usedInAnotherTable = DB::connection('dynamic')->table('ALMMOV')
            ->where('TEC2DEL', $delegation)
            ->where('TEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en algún movimiento de inventario");
        }     
        
        // Operaciones 
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOPE')
            ->where('TEC2DEL', $delegation)
            ->where('TEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en alguna operación");
        }          

        // Órdenes 
        $usedInAnotherTable = DB::connection('dynamic')->table('LABORD')
            ->where('TEC2DEL', $delegation)
            ->where('TEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en alguna orden");
        }          

        // Residuos
        $usedInAnotherTable = DB::connection('dynamic')->table('LABRED')
            ->where('TEC2DEL', $delegation)
            ->where('TEC2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El parámetro no puede ser eliminado porque está siendo referenciado en algún residuo");
        }          

    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra vínculos con plantillas
        DB::connection('dynamic')->table('PLAPYT')
            ->where('DEL3TEC', $delegation)
            ->where('TEC3COD', $code)
            ->delete();   
            
        // Borra vínculos con planificaciones
        DB::connection('dynamic')->table('LABPYT')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete();

        // Borra valores de normativa
        DB::connection('dynamic')->table('LABTYN')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete();  
            
        // Borra vínculos con matrices
        DB::connection('dynamic')->table('LABTYM')
            ->where('DEL3TEC', $delegation)
            ->where('TEC3COD', $code)
            ->delete();             

        // Borra precios por cliente
        DB::connection('dynamic')->table('LABTYC')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete(); 

        // Borra precios por tarifa
        DB::connection('dynamic')->table('LABTYF')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete();  
            
        // Borra personal cualificado
        DB::connection('dynamic')->table('LABTYE')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete();  
            
        // Borra equipos vinculados
        DB::connection('dynamic')->table('LABTYQ')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete();
            
        // Borra consumibles vinculados
        DB::connection('dynamic')->table('LABTYP')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete();  
            
        // Borra columnas
        DB::connection('dynamic')->table('LABCOT')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete(); 
            
        // Borra intervalos
        DB::connection('dynamic')->table('LABCYR')
            ->where('TEC3DEL', $delegation)
            ->where('TEC3COD', $code)
            ->delete();

        // Documentos a la papelera
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('TEC2COD', $code)
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