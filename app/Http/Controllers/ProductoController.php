<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ProductoController extends BaseController
{
    protected $table = 'ALMPRD';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'PRD1COD';    
    protected $inactiveField = 'PRDBBAJ';
    protected $searchFields = ['PRDCDES'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'PRD1COD',
        'descripcion'                   => 'PRDCDES',
        'marca'                         => 'PRDCMAR',
        'modelo'                        => 'PRDCMOD',
        'fabricante'                    => 'PRDCFAB',
        'ano_frabricacion'              => 'PRDCANF',
        'codigo_barras'                 => 'PRDCCOB',
        'es_equipo'                     => 'PRDBEQU',
        'es_consumible'                 => 'PRDBCON',
        'permite_operaciones'           => 'PRDBOPE',
        'unidades'                      => 'PRDCUNI',
        'stock_minimo'                  => 'PRDNSMI',
        'stock_maximo'                  => 'PRDNSMA',
        'existencias_unidades'          => 'PRDNEXI',
        'existencias_cantidad'          => 'PRDNCAE',
        'observaciones'                 => 'PRDCOBS',
        'es_baja'                       => 'PRDBBAJ',
        'referencia'                    => 'PRDCREF',
        'precio'                        => 'PRDNPRE',
        'familia_delegacion'            => 'FAM2DEL',
        'familia_codigo'                => 'FAM2COD',
        'proveedor_delegacion'          => 'PRO2DEL',
        'proveedor_codigo'              => 'PRO2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|string|max:15',
            'descripcion'               => 'nullable|string|max:255',
            'marca'                     => 'nullable|string|max:100',
            'modelo'                    => 'nullable|string|max:100',
            'fabricante'                => 'nullable|string|max:100',
            'ano_fabricacion'           => 'nullable|string|max:10',
            'codigo_barras'             => 'nullable|string|max:100',
            'es_equipo'                 => 'nullable|string|in:T,F|max:1',
            'es_consumible'             => 'nullable|string|in:T,F|max:1',
            'permite_operaciones'       => 'nullable|string|in:T,F|max:1',
            'unidades'                  => 'nullable|string|max:20',
            'stock_minimo'              => 'nullable|numeric|min:0',
            'stock_maximo'              => 'nullable|numeric|min:0',
            'existencias_unidades'      => 'nullable|numeric|min:0',
            'existencias_cantidad'      => 'nullable|numeric|min:0',
            'observaciones'             => 'nullable|string',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'referencia'                => 'nullable|string|max:30',
            'precio'                    => 'nullable|numeric|min:0',
            'familia_delegacion'        => 'nullable|string|max:10',
            'familia_codigo'            => 'nullable|integer',
            'proveedor_delegacion'      => 'nullable|string|max:10',
            'proveedor_codigo'          => 'nullable|string|max:15',
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

        // Valida la existencia de la familia
        if (!empty($data['familia_codigo'])) {
            $family = DB::table('ALMFAM')
                ->where('DEL3COD', $data['familia_delegacion'] ?? '')
                ->where('FAM1COD', $data['familia_codigo'])
                ->first(); 
            if (!$family) {
                throw new \Exception("La familia no existe");
            }
        }

        // Valida la existencia del proveedor
        if (!empty($data['proveedor_codigo'])) {
            $family = DB::table('SINPRO')
                ->where('DEL3COD', $data['proveedor_delegacion'] ?? '')
                ->where('PRO1COD', $data['proveedor_codigo'])
                ->first(); 
            if (!$family) {
                throw new \Exception("El proveedor no existe");
            }
        }        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción del producto no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::table('ALMPRD')->where('PRDCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('PRD1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripción del producto ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo producto no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('ALMPRD')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('PRD1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del producto ya está en uso");
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

        // Comprueba que el producto no está usado en series o lotes
        $usedInAnotherTable = DB::table('ALMSEL')
            ->where('PRD3DEL', $delegation)
            ->where('PRD3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El producto no puede ser eliminado porque contiene series o lotes");
        }     
        
        // Comprueba que el producto no está vinculado a alguna técnica
        $usedInAnotherTable = DB::table('LABTYQ')
            ->where('PRD3DEL', $delegation)
            ->where('PRD3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El producto no puede ser eliminado porque está vinculado a alguna técnica (equipo)");
        }        

        // Comprueba que el producto no está vinculado a alguna técnica
        $usedInAnotherTable = DB::table('LABTYP')
            ->where('PRD3DEL', $delegation)
            ->where('PRD3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El producto no puede ser eliminado porque está vinculado a alguna técnica (consumible)");
        }        
    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra las relaciones del producto con proveedores
        DB::table('ALMPYP')
            ->where('PRD3DEL', $delegation)
            ->where('PRD3COD', $code)
            ->delete();

        // Documentos a la papelera
        DB::table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('PRD2COD', $code)
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