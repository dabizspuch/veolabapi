<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ClientePuntoController extends BaseController
{
    protected $table = 'LABPUM';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'CLI3COD';  
    protected $key1Field = 'PUM1COD';         
    protected $inactiveField = 'PUMBBAJ';
    protected $searchFields = ['PUMCDES'];
    protected $skipNewCode = true;
    
    protected $mapping = [
        'cliente_delegacion'            => 'DEL3COD',
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
        $isCreating = request()->isMethod('post');
        
        // Reglas generales
        $rules = [
            'cliente_delegacion'        => 'nullable|string|max:10',
            'cliente_codigo'            => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
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
        // Valida la existencia del cliente 
        if (!empty($data['cliente_codigo'])) {
            $client = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_codigo'])
                ->first(); 
            if (!$client) {
                throw new \Exception("El cliente no existe");
            }
        }        

        // Valida la existencia de la categoría
        if (!empty($data['categoria'])) {
            $category = DB::connection('dynamic')->table('LABPUM')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('PUM2COD', $data['categoria'])
                ->where('es_categoria', 'T')
                ->first(); 
            if (!$category) {
                throw new \Exception("La categoría asignada no existe");
            }
        }           
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post'); 

        if ($isCreating) {
            // Genera el código sin usar claves técnicas
            if (empty($data['codigo'])) {
                $data['cliente_delegacion'] = $data['cliente_delegacion'] ?? '';
                $data['codigo'] = $this->getNextPointCode(
                    $data['cliente_delegacion'], 
                    $data['cliente_codigo']
                );
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset(
                $data['cliente_delegacion'], 
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
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOPE')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->where('PUM2COD', $key1)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El punto no puede ser eliminado porque está siendo referenciado en operaciones");
        }

        // Comprueba que no esté referenciado en planificaciones
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPLO')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->where('PUM2COD', $key1)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El punto no puede ser eliminado porque está siendo referenciado en planificaciones");
        }

        // Comprueba que no esté referenciado en líneas de factura
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIF')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->where('PUM2COD', $key1)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El punto no puede ser eliminado porque está siendo referenciado en líneas de factura");
        }

        // Comprueba que no esté referenciado en puntos como categoría
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPUM')
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
    
    /**
     * Obtiene el siguiente código de punto para el cliente de entrada.
     * 
     * @param string $clientDelegation - Delegación del cliente para filtrar los lotes.
     * @param string $clientCode - Código del cliente para filtrar los lotes.
     * @return string - El siguiente código de punto
     */
    private function getNextPointCode($clientDelegation, $clientCode)
    {
        $maxPoint = DB::connection('dynamic')->table('LABPUM')
            ->where('DEL3COD', $clientDelegation)
            ->where('CLI3COD', $clientCode) 
            ->max('PUM1COD');    

        return $maxPoint ? $maxPoint + 1 : 1;
    }    
}