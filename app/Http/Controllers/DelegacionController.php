<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class DelegacionController extends BaseController
{
    protected $table = 'ACCDEL';
    protected $codeField = 'DEL1COD';    
    protected $inactiveField = 'DELBBAJ';
    protected $searchFields = ['DELCNOM', 'DELCOBS'];
    
    protected $mapping = [
        'codigo'                        => 'DEL1COD',
        'nombre'                        => 'DELCNOM',
        'direccion'                     => 'DELCDIR',
        'codigo_postal'                 => 'DELCCOP',
        'provincia'                     => 'DELCPRO',
        'poblacion'                     => 'DELCPOB',
        'pais'                          => 'DELCPAI',
        'telefono'                      => 'DELCTEL',
        'movil'                         => 'DELCMOV',
        'fax'                           => 'DELCFAX',
        'email'                         => 'DELCEMA',
        'nif'                           => 'DELCNIF',
        'razon'                         => 'DELCRAS',
        'tipo_persona'                  => 'DELCTIP',
        'residencia'                    => 'DELCRES',
        'moneda'                        => 'DELCMON',
        'lengua'                        => 'DELCLEN',
        'observaciones'                 => 'DELCOBS',
        'fecha_alta'                    => 'DELDALT',
        'fecha_baja'                    => 'DELDBAJ',
        'es_baja'                       => 'DELBBAJ'
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'codigo'                    => 'nullable|string|max:10',
            'nombre'                    => 'nullable|string|max:100',
            'direccion'                 => 'nullable|string|max:255',
            'codigo_postal'             => 'nullable|string|max:10',
            'provincia'                 => 'nullable|string|max:100',
            'poblacion'                 => 'nullable|string|max:100',
            'pais'                      => 'nullable|string|max:3',
            'telefono'                  => 'nullable|string|max:20',
            'movil'                     => 'nullable|string|max:20',
            'fax'                       => 'nullable|string|max:20',
            'email'                     => 'nullable|string|max:100',
            'nif'                       => 'nullable|string|max:15',
            'razon'                     => 'nullable|string|max:255',
            'tipo_persona'              => 'nullable|string|in:F,J|max:1',
            'residencia'                => 'nullable|string|in:E,R,U|max:1',
            'moneda'                    => 'nullable|string|max:3',
            'lengua'                    => 'nullable|string|max:2',
            'observaciones'             => 'nullable|string',
            'fecha_alta'                => 'nullable|date',
            'fecha_baja'                => 'nullable|date',
            'es_baja'                   => 'nullable|string|in:T,F|max:1'
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {    
        // No se requieren validaciones
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre de delegación no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::connection('dynamic')->table('ACCDEL')->where('DELCNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $existingRecord = $existingRecord->where('DEL1COD', '!=', $code);                                      
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre de la delegación ya está en uso");
            }
        }

        // Comprueba que el código para la nueva delegación no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('ACCDEL')
                    ->where('DEL1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la delegación ya está en uso");
                }
            }
        }

        // Excluir campos clave de los datos a actualizar porque no serán editables
        if (!$isCreating) { 
            unset( 
                $data['codigo'] 
            );
        } 
                
        return $data;        
    }
        
    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $tables = [
            'ACCPER' => 'algún perfil',
            'ACCUSU' => 'algún usuario',
            'DOCFAT' => 'algún archivo',
            'DOCDIR' => 'alguna carpeta',
            'PLAPLA' => 'alguna plantilla',
            'FACFAC' => 'alguna factura',
            'FACCON' => 'algún contrato',
            'FACPRE' => 'algún presupuesto',
            'ALMFAM' => 'alguna familia de inventario',
            'ALMPRD' => 'algún producto',
            'ALMMOV' => 'algún movimiento de inventario',
            'ALMPRE' => 'algún préstamo',
            'SINPRO' => 'algún proveedor',
            'SINCLI' => 'algún cliente',
            'SINTIC' => 'algún tipo de cliente',
            'SINTIE' => 'algún tipo de evaluación',
            'GRHEMP' => 'algún empleado',
            'GRHCAR' => 'algún cargo',
            'GRHPAF' => 'algún plan de formación',
            'GRHDEP' => 'algún departamento',
            'LABPLO' => 'alguna planificación',
            'LABOPE' => 'alguna operación',
            'LABLOT' => 'algún lote',
            'LABORD' => 'alguna orden',
            'LABINF' => 'algún informe',
            'LABDIC' => 'algún dictamen',
            'LABTIF' => 'algún tipo de firma',
            'LABFDE' => 'alguna forma de envío',
            'LABTIO' => 'algún tipo de operación',
            'LABSER' => 'algún servicio',
            'LABMAT' => 'alguna matriz',
            'LABSEC' => 'alguna sección',
            'LABTEC' => 'algún parámetro',
            'LABNOR' => 'alguna normativa',
            'LABESC' => 'algún gasto adicional',
            'LABRED' => 'algún residuo',
            'LABTDR' => 'algún tipo de residuo',
            'LABTEQ' => 'algún tipo de equipo',
            'LABEQU' => 'algún equipo',
            'LABTAR' => 'alguna tarifa',
            'LABRAN' => 'algún rango',
            'LABMAR' => 'alguna marca',
            'LABAUT' => 'algún autodefinible'
        ];
        
        foreach ($tables as $table => $reference) {
            if (DB::connection('dynamic')->table($table)->where('DEL3COD', $code)->exists()) {
                throw new \Exception("La delegación no puede ser eliminada porque está siendo referenciada en $reference");
            }
        }         
    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra las claves técnicas de la delegación
        DB::connection('dynamic')->table('ACCCLT')
            ->where('DEL3COD', $code)
            ->delete();

        // Borra los avisos de la delegación
        DB::connection('dynamic')->table('ACCAVI')
            ->where('DEL3COD', $code)
            ->delete();

        // Borra las notificaciones de la delegación
        DB::connection('dynamic')->table('ACCNOT')
            ->where('DEL3COD', $code)
            ->delete();

        // Borra los mensajes de la delegación
        DB::connection('dynamic')->table('MENMEN')
            ->where('DEL3COD', $code)
            ->delete();            

    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        return $data;
    }    

}