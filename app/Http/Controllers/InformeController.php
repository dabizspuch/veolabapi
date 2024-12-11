<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class InformeController extends BaseController
{
    protected $table = 'LABINF';
    protected $delegationField = 'DEL3COD';
    protected $key1Field = 'INF1SER';
    protected $codeField = 'INF1COD';    
    
    protected $mapping = [
        'delegacion'                => 'DEL3COD',
        'serie'                     => 'INF1SER',
        'codigo'                    => 'INF1COD',
        'estado_validacion'         => 'INFCVAL',
        'fecha_creacion'            => 'INFDCRE',
        'fecha_envio'               => 'INFDENV',
        'fecha_validacion'          => 'INFDVAL',
        'acreditado'                => 'INFBACR',
        'final'                     => 'INFBFIN',
        'visible'                   => 'INFBVIS',
        'opiniones'                 => 'INFCOEI',
        'observaciones'             => 'INFCOBS',
        'usuario_delegacion'        => 'USU2DEL',
        'usuario_codigo'            => 'USU2COD',
        'forma_envio_delegacion'    => 'FDE2DEL',
        'forma_envio_codigo'        => 'FDE2COD',
        'normativa_delegacion'      => 'NOR2DEL',
        'normativa_codigo'          => 'NOR2COD',
        'firma_delegacion'          => 'TIF2DEL',
        'firma_codigo'              => 'TIF2COD',
    ];

    protected function rules()
    {
        // Determina si es una creación
        $isCreating = request()->isMethod('post');

        // Reglas generales
        $rules = [
            'delegacion'                        => 'nullable|string|max:10',
            'serie'                             => 'nullable|string|max:10',
            'codigo'                            => 'nullable|integer',
            'estado_validacion'                 => 'required|string|in:P,V,R|max:1', // Pendiente, Validado, Rechazado
            'fecha_creacion'                    => 'nullable|date',
            'fecha_envio'                       => 'nullable|date',
            'fecha_validacion'                  => 'nullable|date',
            'acreditado'                        => 'nullable|string|in:T,F|max:1',
            'final'                             => 'nullable|string|in:T,F|max:1',
            'visible'                           => 'nullable|string|in:T,F|max:1',
            'opiniones'                         => 'nullable|string', 
            'observaciones'                     => 'nullable|string', 
            'usuario_delegacion'                => 'nullable|string|max:10',
            'usuario_codigo'                    => 'nullable|string|max:15',
            'forma_envio_delegacion'            => 'nullable|string|max:10',
            'forma_envio_codigo'                => 'nullable|integer',
            'normativa_delegacion'              => 'nullable|string|max:10',
            'normativa_codigo'                  => 'nullable|string|max:20',
            'firma_delegacion'                  => 'nullable|string|max:10',
            'firma_codigo'                      => 'nullable|integer',
            'operaciones'                       => $isCreating ? 'required|array|min:1' : 'nullable|array',
            'operaciones.*.delegacion'          => 'nullable|string|max:10',
            'operaciones.*.serie'               => 'nullable|string|max:10',
            'operaciones.*.codigo'              => 'required|integer',
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

        // Valida la existencia del usuario
        if (!empty($data['usuario_codigo'])) {
            $user = DB::table('ACCUSU')
                ->where('DEL3COD', $data['usuario_delegacion'] ?? '')
                ->where('USU1COD', $data['usuario_codigo'])
                ->first();
            if (!$user) {
                throw new \Exception("El usuario no existe");
            }
        } 

        // Valida la existencia de la forma de envío
        if (!empty($data['forma_envio_codigo'])) {
            $form = DB::table('LABFDE')
                ->where('DEL3COD', $data['forma_envio_delegacion'] ?? '')
                ->where('FDE1COD', $data['forma_envio_codigo'])
                ->first();
            if (!$form) {
                throw new \Exception("La forma de envio no existe");
            }
        }   

        // Valida la existencia de la normativa
        if (!empty($data['normativa_codigo'])) {
            $regulation = DB::table('LABNOR')
                ->where('DEL3COD', $data['normativa_delegacion'] ?? '')
                ->where('NOR1COD', $data['normativa_codigo'])
                ->first();
            if (!$regulation) {
                throw new \Exception("La normativa no existe");
            }
        }        
        
        // Valida la existencia del tipo de firma
        if (!empty($data['firma_codigo'])) {
            $sign = DB::table('LABTIF')
                ->where('DEL3COD', $data['firma_delegacion'] ?? '')
                ->where('TIF1COD', $data['firma_codigo'])
                ->first();
            if (!$sign) {
                throw new \Exception("El tipo de firma no existe");
            }
        } 
        
        // Valida la existencia de las operaciones
        if (!empty($data['operaciones'])) {
            $existOperation = true;
            foreach ($data['operaciones'] as $operation) {
                $existOperation = DB::table('LABOPE')
                    ->where('DEL3COD', $operation['delegacion'])
                    ->where('OPE1SER', $operation['serie'])
                    ->where('OPE1COD', $operation['codigo'])
                    ->exists();
                if (!$existOperation) {
                    break;
                }                    
            }
            if (!$existOperation) {
                throw new \Exception("Alguna de las operaciones no existe");
            }            
        }       
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {

        $isCreating = request()->isMethod('post');

        // Comprueba que el código para el nuevo informe no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('LABINF')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('INF1SER', $data['serie'] ?? '')
                    ->where('INF1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del informe ya está en uso");
                }
            }
        }

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
        // No se permite borrar informes validados
        $status = DB::table('LABINF')
            ->select('INFCVAL')
            ->where('DEL3COD', $delegation)
            ->where('INF1SER', $key1)
            ->where('INF1COD', $code)
            ->first();

        if ($status && $status->INFCVAL == 'V') {
            throw new \Exception("No se permite borrar informes validados");
        }
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {      
        // Actualiza fecha de informe en operaciones relacionadas
        DB::table('LABOPE')
        ->join('LABIYO', function ($join) {
            $join->on('LABOPE.DEL3COD', '=', 'LABIYO.OPE3DEL')
                 ->on('LABOPE.OPE1SER', '=', 'LABIYO.OPE3SER')
                 ->on('LABOPE.OPE1COD', '=', 'LABIYO.OPE3COD');
        })
        ->where('LABIYO.INF3DEL', $delegation)
        ->where('LABIYO.INF3SER', $key1)
        ->where('LABIYO.INF3COD', $code)
        ->where(function ($query) {
            $query->whereNull('LABIYO.IYOBHIS')
                  ->orWhere('LABIYO.IYOBHIS', '<>', 'T');
        })
        ->update(['LABOPE.OPEDINF' => null]);    

        // Borra el vínculo con operaciones
        DB::table('LABIYO')
            ->where('INF3DEL', $delegation)
            ->where('INF3SER', $key1)
            ->where('INF3COD', $code)
            ->delete();
        
        // Borra las firmas
        DB::table('LABFIR')
            ->where('INF3DEL', $delegation)
            ->where('INF3SER', $key1)
            ->where('INF3COD', $code)
            ->delete();

        // Documentos a la papelera
        DB::table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('INF2SER', $key1)
            ->where('INF2COD', $code)
            ->update([
                'DIR2DEL' => $delegation,
                'DIR2COD' => 0
            ]);      
    }

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Operaciones relacionadas con el informe
        if (isset($data['operaciones'])) {
            
            if (!request()->isMethod('post')) {     
                // Borra la asociación previa de operaciones
                DB::table('LABIYO')
                    ->where('INF3DEL', $delegation)
                    ->where('INF3SER', $key1)
                    ->where('INF3COD', $code)  
                    ->delete(); 
            } 
            
            // Crea la nueva asociación de operaciones
            foreach ($data['operaciones'] as $operation) {                                 
                DB::table('LABIYO')->insert([
                    'INF3DEL' => $delegation,
                    'INF3SER' => $key1,
                    'INF3COD' => $code,
                    'OPE3DEL' => $operation['delegacion'] ?? '',
                    'OPE3SER' => $operation['serie'] ?? '',
                    'OPE3COD' => $operation['codigo'],
                    'IYOBHIS' => 'F'
                 ]);           
            }   
        }

        // Obtiene datos del informe de la base de datos
        $report = DB::table('LABINF')
            ->where('DEL3COD', $delegation)
            ->where('INF1SER', $key1)
            ->where('INF1COD', $code)
            ->first();
        
        $final          = $report ? $report->INFBFIN : null;
        $status         = $report ? $report->INFCVAL : null;
        $creationDate   = $report ? $report->INFDCRE : null;
        $sendingDate    = $report ? $report->INFDENV : null;
        $validationDate = $report ? $report->INFDVAL : now();

        if ($final) {
            // Se actualizan los estados de las operaciones
            $operations = DB::table('LABIYO')
                ->where('INF3DEL', $delegation)
                ->where('INF3SER', $key1)
                ->where('INF3COD', $code)
                ->get();
                
            foreach ($operations as $operation) {                
                $query = DB::table('LABOPE')
                    ->where('DEL3COD', $operation->OPE3DEL)
                    ->where('OPE1SER', $operation->OPE3SER)
                    ->where('OPE1COD', $operation->OPE3COD);

                $updateData = [
                    'OPEDINF' => $creationDate,
                    'OPEDENV' => $sendingDate
                ];

                if ($sendingDate) {
                    $updateData['OPENEST'] = DB::raw('CASE WHEN OPENEST < 6 THEN 6 ELSE OPENEST END');
                } else {
                    switch ($status) {
                        case 'P': // Pendiente
                            $updateData['OPENEST'] = 4;
                            break;
                        
                        case 'V': // Validado
                            $updateData = array_merge($updateData, [
                                'OPEDVAL' => $validationDate,
                                'OPENEST' => DB::raw('CASE WHEN (OPENEST < 5 OR OPENEST = 6) THEN 5 ELSE OPENEST END'),
                            ]);  
                            break;

                        case 'R': // Rechazado
                            $updateData = array_merge($updateData, [
                                'OPEDINI' => null,
                                'OPEDFIN' => null,
                                'OPEDVAL' => null,
                                'OPENEST' => 2,
                            ]);                       
                            break;
                    }    
                }

                $query->update($updateData);
            }
        }

        return $data;
    }         
  
}
