<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EquipoController extends BaseController
{
    protected $table = 'LABEQU';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'EQU1COD';    
    protected $inactiveField = 'EQUBBAJ';
    protected $searchFields = ['EQUCDES'];
    
    protected $mapping = [
        'delegacion'                => 'DEL3COD',
        'codigo'                    => 'EQU1COD',
        'descripcion'               => 'EQUCDES',
        'marca'                     => 'EQUCMAR',
        'modelo'                    => 'EQUCMOD',
        'serie'                     => 'EQUCSER',
        'referencia'                => 'EQUCREF',
        'fabricante'                => 'EQUCFAB',
        'ano_fabricacion'           => 'EQUCANF',
        'ubicacion'                 => 'EQUCUBI',
        'manual'                    => 'EQUCMAO',
        'especificaciones'          => 'EQUCETC',
        'condiciones'               => 'EQUCCOA',
        'tipo_fluido'               => 'EQUCTIF',
        'volumen_fluido'            => 'EQUCVOF',
        'preservacion'              => 'EQUCPRE',
        'enfriamiento'              => 'EQUCENF',
        'reglas_analisis'           => 'EQUCREA',
        'rango_kv'                  => 'EQUCRKV',
        'rango_mva'                 => 'EQUCRMV',
        'estado'                    => 'EQUCEST',
        'fecha_servicio'            => 'EQUDFPE',
        'fecha_baja'                => 'EQUDBAS',
        'observaciones'             => 'EQUCOBS',
        'es_baja'                   => 'EQUBBAJ',
        'tipo_equipo_delegacion'    => 'TEQ2DEL',
        'tipo_equipo_codigo'        => 'TEQ2COD',
        'cliente_delegacion'        => 'CLI2DEL',
        'cliente_codigo'            => 'CLI2COD',
    ];
    

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                => 'nullable|string|max:10',
            'codigo'                    => 'nullable|string|max:20',
            'descripcion'               => 'nullable|string|max:100',
            'marca'                     => 'nullable|string|max:50',
            'modelo'                    => 'nullable|string|max:50',
            'serie'                     => 'nullable|string|max:30',
            'referencia'                => 'nullable|string|max:30',
            'fabricante'                => 'nullable|string|max:50',
            'ano_fabricacion'           => 'nullable|string|max:10',
            'ubicacion'                 => 'nullable|string|max:255',
            'manual'                    => 'nullable|string|max:255',
            'especificaciones'          => 'nullable|string|max:255',
            'condiciones'               => 'nullable|string|max:255',
            'tipo_fluido'               => 'nullable|string|max:50',
            'volumen_fluido'            => 'nullable|string|max:50',
            'preservacion'              => 'nullable|string|max:50',
            'enfriamiento'              => 'nullable|string|max:50',
            'reglas_analisis'           => 'nullable|string|max:50',
            'rango_kv'                  => 'nullable|string|max:20',
            'rango_mva'                 => 'nullable|string|max:20',
            'estado'                    => 'nullable|string|in:N,U,L,F,B|max:1',
            'fecha_servicio'            => 'nullable|date',
            'fecha_baja'                => 'nullable|date',
            'observaciones'             => 'nullable|string',
            'es_baja'                   => 'nullable|string|in:T,F|max:1',
            'tipo_equipo_delegacion'    => 'nullable|string|max:10',
            'tipo_equipo_codigo'        => 'nullable|integer',
            'cliente_delegacion'        => 'nullable|string|max:10',
            'cliente_codigo'            => 'nullable|string|max:15',
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

        // Valida la existencia del tipo de equipo
        if (!empty($data['tipo_equipo_codigo'])) {
            $type = DB::connection('dynamic')->table('LABTEQ')
                ->where('DEL3COD', $data['tipo_equipo_delegacion'] ?? '')
                ->where('TEQ1COD', $data['tipo_equipo_codigo'])
                ->first(); 
            if (!$type) {
                throw new \Exception("El tipo de equipo no existe");
            }
        }

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
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción del equipo no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::connection('dynamic')->table('LABEQU')->where('EQUCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('EQU1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripción del equipo ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo equipo no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('LABEQU')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('EQU1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del equipo ya está en uso");
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

        // Comprueba que el equipo no está siendo usado en alguna planificación
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPLO')
            ->where('EQU2DEL', $delegation)
            ->where('EQU2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El equipo no puede ser eliminado porque está vinculado a alguna planificación");
        }     
        
        // Comprueba que el equipo no está siendo usado en alguna operación
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOPE')
            ->where('EQU2DEL', $delegation)
            ->where('EQU2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El equipo no puede ser eliminado porque está vinculado a alguna operación");
        }        
    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Documentos a la papelera
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('EQU2COD', $code)
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