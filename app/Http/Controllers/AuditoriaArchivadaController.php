<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class AuditoriaArchivadaController extends BaseController
{
    protected $table = 'ACAAUD';
    protected $codeField = 'AUD1COD';    
    protected $searchFields = ['AUDCTAB', 'AUDCFIL', 'AUDCCAM', 'AUDCVAM', 'AUDCVAA'];
    
    protected $mapping = [
        'codigo'                        => 'AUD1COD',
        'fecha'                         => 'AUDTFEC',
        'tipo'                          => 'AUDCTIP',
        'tabla'                         => 'AUDCTAB',
        'fila'                          => 'AUDCFIL',
        'campo'                         => 'AUDCCAM',
        'valor_modificado'              => 'AUDCVAM',
        'valor_anterior'                => 'AUDCVAA',
        'sesion_codigo'                 => 'SES2COD',
        'delegacion'                    => 'DEL2COD',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [];    // No se requiere por no soportar altas ni modificaciones

        return $rules;
    }

    protected function validateRelationships(array $data)
    {    
        // No se permiten modificaciones
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {                
        return $data;        
    }
        
    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se requieren comprobaciones antes de borrar
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // No se requiere borrado en cascada
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}