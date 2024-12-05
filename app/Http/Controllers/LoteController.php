<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class LoteController extends BaseController
{
    protected $table = 'LABLOT';
    protected $delegationField = 'DEL3COD';
    protected $key1Field = 'LOT1SER';
    protected $codeField = 'LOT1COD';    
    protected $searchFields = ['LOTCREF', 'LOTCDES'];
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'serie'                         => 'LOT1SER',
        'codigo'                        => 'LOT1COD',
        'referencia'                    => 'LOTCREF',
        'descripcion'                   => 'LOTCDES',
        'observaciones'                 => 'LOTCOBS',
        'comentarios'                   => 'LOTCCOM',
        'fecha_registro'                => 'LOTDREG',
        'fecha_recepcion'               => 'LOTTREC',
        'estado'                        => 'LOTNEST',
        'cliente_delegacion'            => 'CLI2DEL',
        'cliente_codigo'                => 'CLI2COD'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',     
            'serie'                     => 'nullable|string|max:10',     
            'codigo'                    => 'nullable|string|max:50',           
            'referencia'                => 'nullable|string|max:30',    
            'descripcion'               => 'nullable|string|max:255',              
            'observaciones'             => 'nullable|string|max:255',              
            'comentarios'               => 'nullable|string|max:255',              
            'fecha_registro'            => 'nullable|date',              
            'fecha_recepcion'           => 'nullable|date',              
            'estado'                    => 'nullable|integer|in:0,6,7',     
            'cliente_delegacion'        => 'nullable|string|max:10',     
            'cliente_codigo'            => 'nullable|string|max:15',
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
        if (!empty($data['cliente_codigo'])) {
            $client = DB::table('SINCLI')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_codigo'])
                ->first();
            if (!$client) {
                throw new \Exception("El cliente no existe");
            }
        }  
                        
        // Valida la existencia de los analistas
        if (!empty($data['analistas'])) {
            $existAnalista = true;
            foreach ($data['analistas'] as $analyst) {
                $existAnalista = DB::table('GRHEMP')
                    ->where('DEL3COD', $analyst['delegacion'])
                    ->where('EMP1COD', $analyst['codigo'])
                    ->where('EMPBANA', 'T')
                    ->exists();
                if (!$existAnalista) {
                    break;
                }                    
            }
            if (!$existAnalista) {
                throw new \Exception("Alguno de los analistas no existe");
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
                $data['serie'], 
                $data['codigo']
            ); 
        }

        return $data;               
    }

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No hay restricciones respecto a otras tablas para poder borrar lotes
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra los datos de autodefinibles
        DB::table('LABLYA')
            ->where('LOT3DEL', $delegation)
            ->where('LOT3SER', $key1)
            ->where('LOT3COD', $code)
            ->delete();   
        
        // Documentos a la papelera
        DB::table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('LOT2SER', $key1)
            ->where('LOT2COD', $code)
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