<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class CursoController extends BaseController
{
    protected $table = 'GRHPAF';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'PAF1COD';    
    protected $searchFields = ['PAFCDES', 'PAFCOBS'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'PAF1COD',
        'estado'                        => 'PAFCEST',
        'fecha_prevista'                => 'PAFDPRE',
        'fecha_inicio'                  => 'PAFDINI',
        'fecha_fin'                     => 'PAFDFIN',
        'horas_duracion'                => 'PAFNHOR',
        'dias_plazo_evaluar'            => 'PAFNDIA',
        'tipo'                          => 'PAFCTIF',
        'descripcion'                   => 'PAFCDES',
        'organismo'                     => 'PAFCEXT',
        'observaciones'                 => 'PAFCOBS',
        'objetivos'                     => 'PAFCOBJ',
        'programa'                      => 'PAFCPRO',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                 => 'nullable|string|max:10',
            'codigo'                     => 'nullable|string|max:15',
            'estado'                     => 'nullable|string|in:P,J,R,E,S,C,H|max:1',
            'fecha_prevista'             => 'nullable|date',
            'fecha_inicio'               => 'nullable|date',
            'fecha_fin'                  => 'nullable|date',
            'horas_duracion'             => 'nullable|integer',
            'dias_plazo_evaluar'         => 'nullable|integer',
            'tipo'                       => 'nullable|string|in:I,E|max:1',
            'descripcion'                => 'nullable|string|max:100',
            'organismo'                  => 'nullable|string|max:100',
            'observaciones'              => 'nullable|string|max:255',
            'objetivos'                  => 'nullable|string|max:255',
            'programa'                   => 'nullable|string',
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
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que la descripción del curso no esté en uso
        if (!empty($data['descripcion'])) {
            $existingRecord = DB::connection('dynamic')->table('GRHPAF')->where('PAFCDES', $data['descripcion']);            
            if (!$isCreating) { 
                // Si se trata de una actualización la descripción no debe estar repetida pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('PAF1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("La descripción del curso ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo curso no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('GRHPAF')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('PAF1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del curso ya está en uso");
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
        // No se requieren validaciones antes de borrar
    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra los alumnos del curso
        DB::connection('dynamic')->table('GRHALU')
            ->where('PAF3DEL', $delegation)
            ->where('PAF3COD', $code)
            ->delete();

        // Borra los profesores del curso
        DB::connection('dynamic')->table('GRHPRO')
            ->where('PAF3DEL', $delegation)
            ->where('PAF3COD', $code)
            ->delete();

        // Documentos a la papelera
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('PAF2COD', $code)
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