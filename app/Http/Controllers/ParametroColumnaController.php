<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ParametroColumnaController extends BaseController
{
    protected $table = 'LABCOT';
    protected $delegationField = 'TEC3DEL';
    protected $codeField = 'TEC3COD';   
    protected $key1Field = 'COT1COD';
    protected $searchFields = ['COTCTIT', 'COTCTI2', 'COTCTI3'];          
    protected $skipNewCode = true;
    
    protected $mapping = [
        'parametro_delegacion'     => 'TEC3DEL',
        'parametro_codigo'         => 'TEC3COD',
        'codigo'                   => 'COT1COD',
        'titulo'                   => 'COTCTIT',
        'titulo2'                  => 'COTCTI2',
        'titulo3'                  => 'COTCTI3',
        'formato'                  => 'COTCFOR',
        'mostrar_informes'         => 'COTBINF',
        'mostrar_resultados'       => 'COTBRES',
        'seleccionables'           => 'COTCSEL',
        'tipo_dato'                => 'COTCTIP',
        'predeterminado'           => 'COTCPRE',
        'formula'                  => 'COTCFOM',
        'es_editable'              => 'COTBEDI',
        'es_exactitud'             => 'COTBCON',
        'es_precision'             => 'COTBCOP',
        'es_activada'              => 'COTBACT',
    ];

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'parametro_delegacion'  => 'nullable|string|max:10',
            'parametro_codigo'      => $isCreating ? 'required|string|max:30' : 'nullable|string|max:30',
            'codigo'                => 'nullable|integer',
            'titulo'                => 'nullable|string|max:100',
            'titulo2'               => 'nullable|string|max:100',
            'titulo3'               => 'nullable|string|max:100',
            'formato'               => 'nullable|string|max:30',
            'mostrar_informes'      => 'nullable|string|in:T,F|max:1',
            'mostrar_resultados'    => 'nullable|string|in:T,F|max:1',
            'seleccionables'        => 'nullable|string',
            'tipo_dato'             => 'nullable|string|in:N,T,F,H,C|max:1',
            'predeterminado'        => 'nullable|string|max:100',
            'formula'               => 'nullable|string',
            'es_editable'           => 'nullable|string|in:T,F|max:1',
            'es_exactitud'          => 'nullable|string|in:T,F|max:1',
            'es_precision'          => 'nullable|string|in:T,F|max:1',
            'es_activada'           => 'nullable|string|in:T,F|max:1',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia del parámetro 
        if (!empty($data['parametro_codigo'])) {
            $employee = DB::table('LABTEC')
                ->where('DEL3COD', $data['parametro_delegacion'] ?? '')
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first(); 
            if (!$employee) {
                throw new \Exception("El parámetro no existe");
            }
        }          
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post'); 

        if ($isCreating) {
            // Genera el código sin usar claves técnicas
            if (empty($data['codigo'])) {
                $data['parametro_delegacion'] = $data['parametro_delegacion'] ?? '';
                $data['codigo'] = $this->getNextColumnCode(
                    $data['parametro_delegacion'], 
                    $data['parametro_codigo']
                );
            }            
        } else {
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset(
                $data['parametro_delegacion'], 
                $data['parametro_codigo'], 
                $data['codigo']
            ); 
        }

        return $data;
    }    

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se requieren validaciones antes de borrar
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
     * Obtiene el siguiente código de columna para el parámetro de entrada.
     * 
     * @param string $parameterDelegation - Delegación del parámetro para filtrar las columnas.
     * @param string $parameterCode - Código del parámetro para filtrar las columnas.
     * @return string - El siguiente código de columna
     */
    private function getNextColumnCode($parameterDelegation, $parameterCode)
    {
        $maxPoint = DB::table('LABCOT')
            ->where('TEC3DEL', $parameterDelegation)
            ->where('TEC3COD', $parameterCode) 
            ->max('COT1COD');    

        return $maxPoint ? $maxPoint + 1 : 1;
    }    
}