<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class PuntoController extends BaseController
{
    protected $table = 'LABPUM';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'CLI3COD';    
    protected $key1Field = 'PUM1COD';          
    protected $inactiveField = 'PUMBBAJ';
    protected $searchFields = ['PUMCDES'];
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'cliente_codigo'                => 'CLI3COD',
        'codigo'                        => 'PUM1COD',
        'descripcion'                   => 'PUMCDES',
        'referencia'                    => 'PUMCREF',
        'es_baja'                       => 'PUMBBAJ',
        'es_categoria'                  => 'PUMBCAT',
        'codigo_punto'                  => 'PUMNCPM',
        'tipo_punto'                    => 'PUMNTPM',
        'codigo_identificativo'         => 'PUMNCOI',
        'ubicacion'                     => 'PUMCUBI',
        'codigo_msc'                    => 'PUMNMSC',
        'municipio'                     => 'PUMCMUN',
        'latitud'                       => 'PUMCLAT',
        'longitud'                      => 'PUMCLON',
        'altitud'                       => 'PUMCALT',
        'error_gps'                     => 'PUMCERG',
        'proyecto'                      => 'PUMCPRO',
        'actividad'                     => 'PUMCACT',
        'instrumento_ambiental'         => 'PUMCINS',
        'minimo_control'                => 'PUMNCON',
        'minimo_completos'              => 'PUMNCOM',
        'minimo_muestras'               => 'PUMNANO',
        'categoria'                     => 'PUM2COD'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'cliente_codigo'            => 'nullable|string|max:15',
            'codigo'                    => 'nullable|integer',
            'descripcion'               => 'nullable|string|max:255',
            'referencia'                => 'nullable|string|max:100',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'es_categoria'              => 'nullable|string|in:T,F|max:1',
            'codigo_punto'              => 'nullable|integer',
            'tipo_punto'                => 'nullable|integer',
            'codigo_identificativo'     => 'nullable|integer',
            'ubicacion'                 => 'nullable|string',
            'codigo_msc'                => 'nullable|integer',
            'municipio'                 => 'nullable|string|max:100',
            'latitud'                   => 'nullable|string|max:100',
            'longitud'                  => 'nullable|string|max:100',
            'altitud'                   => 'nullable|string|max:100',
            'error_gps'                 => 'nullable|string|max:100',
            'proyecto'                  => 'nullable|string|max:100',
            'actividad'                 => 'nullable|string|max:100',
            'instrumento_ambiental'     => 'nullable|string|max:100',
            'minimo_control'            => 'nullable|integer',
            'minimo_completos'          => 'nullable|integer',
            'minimo_muestras'           => 'nullable|integer',
            'categoria'                 => 'nullable|integer'
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

        // Valida la existencia del cliente 
        if (!empty($data['codigo'])) {
            $client = DB::table('SINCLI')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_codigo'])
                ->first(); 
            if (!$client) {
                throw new \Exception("El cliente no existe");
            }
        }        

        // Valida la existencia de la categoría
        if (!empty($data['categoria'])) {
            $category = DB::table('LABPUM')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('PUM2COD', $data['categoria'])
                ->where('es_categoria', 'T')
                ->first(); 
            if (!$category) {
                throw new \Exception("La categoría asignada no existe");
            }
        }           
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        $isCreating = request()->isMethod('post'); 

        // Excluir campos clave de los datos a actualizar porque no serán editables
        if (!$isCreating) {
            unset(
                $data['delegacion'], 
                $data['cliente_codigo'], 
                $data['codigo']
            ); 
        }

        return $data;
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $delegation = $delegation ?? '';
        $key1 = $key1 ?? '';

        // Comprueba que no esté referenciado en operaciones
        $usedInAnotherTable = DB::table('LABOPE')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->where('PUM2COD', $key1)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El punto no puede ser eliminado porque está siendo referenciado en operaciones");
        }

        // Comprueba que no esté referenciado en planificaciones
        $usedInAnotherTable = DB::table('LABPLO')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->where('PUM2COD', $key1)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El punto no puede ser eliminado porque está siendo referenciado en planificaciones");
        }

        // Comprueba que no esté referenciado en líneas de factura
        $usedInAnotherTable = DB::table('FACLIF')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->where('PUM2COD', $key1)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El punto no puede ser eliminado porque está siendo referenciado en líneas de factura");
        }

        // Comprueba que no esté referenciado en puntos como categoría
        $usedInAnotherTable = DB::table('LABPUM')
            ->where('DEL3COD', $delegation)
            ->where('CLI3COD', $code)
            ->where('PUM2COD', $key1)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El punto no puede ser eliminado porque está siendo referenciado desde otro punto (categoría)");
        }
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