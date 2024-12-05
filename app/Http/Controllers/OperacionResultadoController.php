<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class OperacionResultadoController extends BaseController
{
    protected $table = 'LABCOR';
    protected $delegationField = 'OPE3DEL';
    protected $key1Field = 'OPE3SER';    
    protected $codeField = 'OPE3COD';    
    protected $key2Field = 'TEC3COD';    
    protected $key3Field = 'TEC3DEL';    
    protected $key4Field = 'COR1COD';    
    protected $inactiveField = 'CORBACT';
    protected $searchFields = ['CORCVAL'];
    protected $mapping = [
        'operacion_delegacion'          => 'OPE3DEL',
        'operacion_serie'               => 'OPE3SER',
        'operacion_codigo'              => 'OPE3COD',
        'parametro_delegacion'          => 'TEC3DEL',
        'parametro_codigo'              => 'TEC3COD',
        'numero_columna'                => 'COR1COD',
        'valor'                         => 'CORCVAL',
        'titulo'                        => 'CORCTIT',
        'titulo2'                       => 'CORCTI2',
        'titulo3'                       => 'CORCTI3',
        'es_visible_informes'           => 'CORBINF',
        'es_visible_resultados'         => 'CORBRES',
        'es_editable'                   => 'CORBINF',
        'es_control_exactitud'          => 'CORBCON',
        'es_control_precision'          => 'CORBCOP', 
        'es_activa'                     => 'CORBACT', 
        'marca_delegacion'              => 'MAR2DEL',
        'marca_codigo'                  => 'MAR2COD'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'operacion_delegacion'      => 'nullable|string|max:10',
            'operacion_serie'           => 'nullable|string|max:10',
            'operacion_codigo'          => 'nullable|integer',
            'parametro_delegacion'      => 'nullable|string|max:10',
            'parametro_codigo'          => 'nullable|string|max:30',
            'numero_columna'            => 'nullable|integer',
            'valor'                     => 'nullable|string|max:255',
            'titulo'                    => 'nullable|string|max:100',
            'titulo2'                   => 'nullable|string|max:100',
            'titulo3'                   => 'nullable|string|max:100',
            'es_visible_informes'       => 'nullable|string|in:T,F|max:1',
            'es_visible_resultados'     => 'nullable|string|in:T,F|max:1',
            'es_editable'               => 'nullable|string|in:T,F|max:1',
            'es_control_exactitud'      => 'nullable|string|in:T,F|max:1',
            'es_control_precision'      => 'nullable|string|in:T,F|max:1', 
            'es_activa'                 => 'nullable|string|in:T,F|max:1', 
            'marca_delegacion'          => 'nullable|string|max:10',
            'marca_codigo'              => 'nullable|integer'            
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {  
        // Valida la existencia de la operación 
        if (!empty($data['operacion_codigo'])) {
            $exist = DB::table('LABTEC')
                ->where('DEL3COD', $data['operacion_delegacion'])
                ->where('OPE1SER', $data['operacion_serie'])
                ->where('OPE1COD', $data['operacion_codigo'])
                ->first(); 
            if (!$exist) {
                throw new \Exception("La operación no existe");
            }
        }

        // Valida la existencia del parámetro 
        if (!empty($data['parametro_codigo'])) {
            $exist = DB::table('LABMAR')
                ->where('DEL3COD', $data['parametro_delegacion'])
                ->where('TEC1COD', $data['parametro_codigo'])
                ->first(); 
            if (!$exist) {
                throw new \Exception("El parámetro no existe");
            }
        }
        
        // Valida la existencia de la marca 
        if (!empty($data['marca_codigo'])) {
            $exist = DB::table('LABMAR')
                ->where('DEL3COD', $data['marca_delegacion'])
                ->where('MAR1COD', $data['marca_codigo'])
                ->first(); 
            if (!$exist) {
                throw new \Exception("La marca no existe");
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        // Si se está modificando una operación se debe comprobar que no se hayan firmado o validado informes relacionados
        if (!is_null($code)) {
            $hasValidSignatureOrValidatedReport = DB::table('LABIYO')
            ->leftJoin('LABINF', function($join) {
                $join->on('LABIYO.INF3DEL', '=', 'LABINF.DEL3COD')
                     ->on('LABIYO.INF3SER', '=', 'LABINF.INF1SER')
                     ->on('LABIYO.INF3COD', '=', 'LABINF.INF1COD');
            })
            ->leftJoin('LABFIR', function($join) {
                $join->on('LABIYO.INF3DEL', '=', 'LABFIR.INF3DEL')
                     ->on('LABIYO.INF3SER', '=', 'LABFIR.INF3SER')
                     ->on('LABIYO.INF3COD', '=', 'LABFIR.INF3COD');
            })
            ->where('LABIYO.OPE3DEL', $delegation)
            ->where('LABIYO.OPE3SER', $key1)
            ->where('LABIYO.OPE3COD', $code)
            ->where('LABIYO.IYOBHIS', '<>', 'T')
            ->where(function($query) {
                $query->where('LABFIR.FIRBVAL', 'T')
                      ->orWhere('LABINF.INFCVAL', 'V');
            })
            ->exists();
            if ($hasValidSignatureOrValidatedReport) {
                throw new \Exception("La operación ya ha sido validada y no puede ser modificada.");
            }    
        }

        // Excluir campos clave de los datos a actualizar porque no serán editables
        unset(
            $data['operacion_delegacion'], 
            $data['operacion_serie'], 
            $data['operacion_codigo'], 
            $data['parametro_delegacion'], 
            $data['parametro_codigo'], 
            $data['numero_columna']
        );         

        return $data;        
    }

    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No es posible borrar columnas de resultados
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No es posible borrar columnas de resultados
    }
    
    /**
     * @param string $code - Código del operación
     * @param string|null $delegation - Delegación de operación
     * @param string|null $key1 - Serie de operación
     * @param string|null $key2 - Código de técnica
     * @param string|null $key3 - Delegación de técnica
     * @param string|null $key4 - Número de columna      
     */     
    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Aplicar la marca según rango si no se está asociando una marca manualmente         
        if (!isset($data['marca_codigo']) && isset($data['valor'])) {

            $markCode = 0;
            $markDelegacion = '';
            $replacedValue = '';

            if ($data['valor'] != '') {

                // Para la celda indicada obtiene la lista de rangos a considerar 
                $ranges = DB::table('LABCYR')
                    ->leftJoin('LABRAN', function($join) {
                        $join->on('LABCYR.RAN3DEL', '=', 'LABRAN.DEL3COD')
                            ->on('LABCYR.RAN3COD', '=', 'LABRAN.RAN1COD');
                    })
                    ->where('LABCYR.TEC3DEL', $key3)
                    ->where('LABCYR.TEC3COD', $key2)
                    ->where('LABCYR.COT3COD', $key4)
                    ->orderBy('LABCYR.RAN3COD')
                    ->orderBy('LABCYR.MAR2DEL')
                    ->orderBy('LABCYR.MAR2COD')
                    ->orderBy('LABRAN.RANBSUV')
                    ->orderBy('LABRAN.RANBSUX')
                    ->orderBy('LABRAN.RANBNOR')                
                    ->get();

                foreach ($ranges as $range) { 
                    
                    if ($range->CYRCVAR !== '' && $range->CYRCVAR !== null) {
                        
                        [$isInInterval, $isEvaluable, $limitExceeded] = $this->validateRange($range, $data['valor']);

                        if (!$isInInterval) {
                            // Marca
                            if (!$isEvaluable) {
                                if ($this->existMarkNotEvaluable($delegation)) {
                                    $markDelegacion = $delegation;
                                    $markCode = -2; // Marca no evaluable    
                                }
                            } else {
                                $markDelegacion = $range->MAR2DEL;
                                $markCode = $range->MAR2COD;    
                            }

                            // Valor reemplazado por límite
                            if ($replacedValue === '') {
                                if ($range->RANBSUX === 'T' || $range->RANBSUV === 'T') {
                                    $replacedValue = $limitExceeded;
                                } else {
                                    $replacedValue = '';
                                }
                            }
                        }
                    }
                }
            }

            // Aplica la marca obtenida en la evaluación de rangos
            DB::table('LABCOR')
                ->where('OPE3DEL', $delegation)
                ->where('OPE3SER', $key1)
                ->where('OPE3COD', $code)
                ->where('TEC3DEL', $key3)
                ->where('TEC3COD', $key2)
                ->where('COR1COD', $key4)
                ->update([
                    'MAR2DEL' => $markDelegacion,
                    'MAR2COD' => $markCode
                ]);

            // Aplica el reemplazo del valor si procede
            if ($replacedValue !== '') {
                DB::table('LABCOR')
                ->where('OPE3DEL', $delegation)
                ->where('OPE3SER', $key1)
                ->where('OPE3COD', $code)
                ->where('TEC3DEL', $key3)
                ->where('TEC3COD', $key2)
                ->where('COR1COD', $key4)
                ->update([
                    'CORCVAL' => $replacedValue,
                ]);

                // Elimina el campo de $data para que perdure el reemplazo y no el nuevo valor
                unset($data['valor']); 
            }
        }

        return $data;
    } 

    private function existMarkNotEvaluable($delegation) 
    {
        return DB::table('LABMAR')
                ->where('DEL3COD', $delegation)
                ->where('MAR1COD', -2)
                ->exists();      
    }


    private function validateRange($range, $value)
    {
        $INFINITESIMAL = 0.000001;
        $isEvaluable = true;
        $isInInterval = false;
        $limitExceeded = '';
    
        $intervals = explode(',', $range->CYRCVAR); 

        foreach ($intervals as $interval) {
            $interval = trim($interval);
    
            if (empty($interval)) {
                continue;
            }
    
            $firstChar = substr($value, 0, 1);
            if ($firstChar === '<') {

                $minValue = 0;
                $maxValue = floatval(substr($value, 1)) - $INFINITESIMAL;
                
                $isMinValid = $this->isNumberInInterval($minValue, $interval);
                $isMaxValid = $this->isNumberInInterval($maxValue, $interval);

                if ($isMinValid && $isMaxValid) {
                    $isInInterval = true;
                } elseif (!$isMaxValid && !$isMaxValid) {
                    $isInInterval = false;
                    $cleanedInterval = str_replace(['[', ']', '(', ')'], '', $interval);
                    $isEvaluable = $maxValue <= floatval(explode(';', $cleanedInterval)[0]);  
                } else {
                    $isInInterval = false;
                    $isEvaluable = false;
                }

            } elseif ($firstChar === '>') {
                
                $minValue = floatval(substr($value, 1)) + $INFINITESIMAL;
                $maxValue = INF;
                
                $isMinValid = $this->isNumberInInterval($minValue, $interval);
                $isMaxValid = $this->isNumberInInterval($maxValue, $interval);

                $isInInterval = $isMinValid && $isMaxValid;
                $isEvaluable = !$isMinValid && !$isMaxValid ? $minValue >= floatval(explode(';', $interval)[1]) : ($isMinValid || $isMaxValid);

                if ($isMinValid && $isMaxValid) {
                    $isInInterval = true;
                } elseif (!$isMaxValid && !$isMaxValid) {
                    $isInInterval = false;
                    $cleanedInterval = str_replace(['[', ']', '(', ')'], '', $interval);
                    $isEvaluable = $minValue >= floatval(explode(';', $cleanedInterval)[1]);  
                } else {
                    $isInInterval = false;
                    $isEvaluable = false;
                }

            } else {
                $isInInterval = $this->isNumberInInterval(floatval($value), $interval, $limitExceeded);
            }
        }
    
        return [$isInInterval, $isEvaluable, $limitExceeded];
    }
    
    private function isNumberInInterval($value, $interval, &$limitExceeded = null)
    {        
        $cleanedInterval = str_replace(['[', ']', '(', ')'], '', $interval);
        $intervalParts = explode(';', $cleanedInterval); 

        if (count($intervalParts) !== 2) {
            return false;
        }
    
        $start = floatval(trim($intervalParts[0]));
        $end = floatval(trim($intervalParts[1]));

        $startInclusive = strpos($interval, '[') !== false;
        $endInclusive = strpos($interval, ']') !== false;
    
        $isWithinStart = $startInclusive ? $value >= $start : $value > $start;
        $isWithinEnd = $endInclusive ? $value <= $end : $value < $end;
    
        if (!$isWithinStart) {
            $limitExceeded = "<$start ($value)";
        } elseif (!$isWithinEnd) {
            $limitExceeded = ">$end ($value)";
        }
    
        return $isWithinStart && $isWithinEnd;
    }    

}
