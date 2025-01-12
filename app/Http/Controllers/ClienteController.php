<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ClienteController extends BaseController
{
    protected $table = 'SINCLI';
    protected $delegationField = 'DEL3COD';
    protected $codeField = 'CLI1COD';    
    protected $inactiveField = 'CLIBBAJ';
    protected $searchFields = ['CLICNOM', 'CLICRAS'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'CLI1COD',
        'nombre'                        => 'CLICNOM',
        'razon_social'                  => 'CLICRAS',
        'actividad'                     => 'CLICACT',
        'nif'                           => 'CLICNIF',
        'direccion_1'                   => 'CLICDI1',
        'poblacion_1'                   => 'CLICPO1',
        'provincia_1'                   => 'CLICPR1',
        'codigo_postal_1'               => 'CLICCO1',
        'pais_1'                        => 'CLICPA1',
        'es_facturacion_1'              => 'CLIBDF1',
        'direccion_2'                   => 'CLICDI2',
        'poblacion_2'                   => 'CLICPO2',
        'provincia_2'                   => 'CLICPR2',
        'codigo_postal_2'               => 'CLICCO2',
        'pais_2'                        => 'CLICPA2',
        'es_facturacion_2'              => 'CLIBDF2',
        'direccion_3'                   => 'CLICDI3',
        'poblacion_3'                   => 'CLICPO3',
        'provincia_3'                   => 'CLICPR3',
        'codigo_postal_3'               => 'CLICCO3',
        'pais_3'                        => 'CLICPA3',
        'es_facturacion_3'              => 'CLIBDF3',
        'telefono'                      => 'CLICTEL',
        'movil'                         => 'CLICMOV',
        'fax'                           => 'CLICFAX',
        'persona_contacto'              => 'CLICPEC',
        'email'                         => 'CLICEMA',
        'web'                           => 'CLICWEB',
        'es_contacto_laboratorio'       => 'CLIBLAB',
        'es_contacto_administracion'    => 'CLIBADM',
        'fecha_alta'                    => 'CLIDALT',
        'fecha_baja'                    => 'CLIDBAJ',
        'observaciones'                 => 'CLICOBS',
        'notas_facturacion'             => 'CLICOBF',
        'modo_facturacion'              => 'CLICMDF',
        'forma_pago'                    => 'CLICFOP',
        'numero_cuenta'                 => 'CLICNUC',
        'tipo_persona'                  => 'CLICTIP',
        'residencia'                    => 'CLICRES',
        'tipo_impuesto_1'               => 'CLICTI1',
        'valor_impuesto_1'              => 'CLICII1',
        'tipo_impuesto_2'               => 'CLICTI2',
        'valor_impuesto_2'              => 'CLICII2',
        'descuento'                     => 'CLICDTO',
        'es_baja'                       => 'CLIBBAJ',
        'dias_vencimiento_facturas'     => 'CLINDVF',
        'dias_pago_facturas'            => 'CLINDIP',
        'dias_vencimiento_presupuestos' => 'CLINDVP',
        'cliente_principal_delegacion'  => 'CLI2DEL',
        'cliente_principal_codigo'      => 'CLI2COD',
        'forma_envio_delegacion'        => 'FDE2DEL',
        'forma_envio_codigo'            => 'FDE2COD',
        'tipo_cliente_delegacion'       => 'TIC2DEL',
        'tipo_cliente_codigo'           => 'TIC2COD',
        'telefono_2'                    => 'CLICTE2',
        'movil_2'                       => 'CLICMO2',
        'fax_2'                         => 'CLICFA2',
        'persona_contacto_2'            => 'CLICPE2',
        'email_2'                       => 'CLICEM2',
        'web_2'                         => 'CLICWE2',
        'es_contacto_laboratorio_2'     => 'CLIBLA2',
        'es_contacto_administracion_2'  => 'CLIBAD2',
        'telefono_3'                    => 'CLICTE3',
        'movil_3'                       => 'CLICMO3',
        'fax_3'                         => 'CLICFA3',
        'persona_contacto_3'            => 'CLICPE3',
        'email_3'                       => 'CLICEM3',
        'web_3'                         => 'CLICWE3',
        'es_contacto_laboratorio_3'     => 'CLIBLA3',
        'es_contacto_administracion_3'  => 'CLIBAD3',
        'informacion_adicional'         => 'CLICADI',
        'otros_datos'                   => 'CLICOTD',
        'proyecto'                      => 'CLICPRO',
        'tarifa_delegacion'             => 'TAR2DEL',
        'tarifa_codigo'                 => 'TAR2COD',
        'cliente_igeo'                  => 'CLICIGC',
    ];

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                    => 'nullable|string|max:10',
            'codigo'                        => 'nullable|string|max:15',
            'nombre'                        => 'nullable|string|max:255',
            'razon_social'                  => 'nullable|string|max:255',
            'actividad'                     => 'nullable|string|max:100',
            'nif'                           => 'nullable|string|max:15',
            'direccion_1'                   => 'nullable|string|max:255',
            'poblacion_1'                   => 'nullable|string|max:100',
            'provincia_1'                   => 'nullable|string|max:100',
            'codigo_postal_1'               => 'nullable|string|max:10',
            'pais_1'                        => 'nullable|string|max:3',
            'es_facturacion_1'              => 'nullable|string|in:T,F|max:1',
            'direccion_2'                   => 'nullable|string|max:255',
            'poblacion_2'                   => 'nullable|string|max:100',
            'provincia_2'                   => 'nullable|string|max:100',
            'codigo_postal_2'               => 'nullable|string|max:10',
            'pais_2'                        => 'nullable|string|max:3',
            'es_facturacion_2'              => 'nullable|string|in:T,F|max:1',
            'direccion_3'                   => 'nullable|string|max:255',
            'poblacion_3'                   => 'nullable|string|max:100',
            'provincia_3'                   => 'nullable|string|max:100',
            'codigo_postal_3'               => 'nullable|string|max:10',
            'pais_3'                        => 'nullable|string|max:3',
            'es_facturacion_3'              => 'nullable|string|in:T,F|max:1',
            'telefono'                      => 'nullable|string|max:40',
            'movil'                         => 'nullable|string|max:40',
            'fax'                           => 'nullable|string|max:40',
            'persona_contacto'              => 'nullable|string|max:255',
            'email'                         => 'nullable|string',
            'web'                           => 'nullable|string|max:100',
            'es_contacto_laboratorio'       => 'nullable|string|in:T,F|max:1',
            'es_contacto_administracion'    => 'nullable|string|in:T,F|max:1',
            'fecha_alta'                    => 'nullable|date',
            'fecha_baja'                    => 'nullable|date',
            'observaciones'                 => 'nullable|string',
            'notas_facturacion'             => 'nullable|string',
            'modo_facturacion'              => 'nullable|string|in:C,P,N|max:1',
            'forma_pago'                    => 'nullable|string|max:100',
            'numero_cuenta'                 => 'nullable|string|max:50',
            'tipo_persona'                  => 'nullable|string|in:F,J|max:1',
            'residencia'                    => 'nullable|string|in:E,R,U|max:1',
            'tipo_impuesto_1'               => 'nullable|string|max:10',
            'valor_impuesto_1'              => 'nullable|string|max:10',
            'tipo_impuesto_2'               => 'nullable|string|max:10',
            'valor_impuesto_2'              => 'nullable|string|max:10',
            'descuento'                     => 'nullable|string|max:10',
            'es_baja'                       => 'nullable|string|in:T,F|max:1',
            'dias_vencimiento_facturas'     => 'nullable|integer',
            'dias_pago_facturas'            => 'nullable|integer',
            'dias_vencimiento_presupuestos' => 'nullable|integer',
            'cliente_principal_delegacion'  => 'nullable|string|max:10',
            'cliente_principal_codigo'      => 'nullable|string|max:15',
            'forma_envio_delegacion'        => 'nullable|string|max:10',
            'forma_envio_codigo'            => 'nullable|integer',
            'tipo_cliente_delegacion'       => 'nullable|string|max:10',
            'tipo_cliente_codigo'           => 'nullable|integer',
            'telefono_2'                    => 'nullable|string|max:40',
            'movil_2'                       => 'nullable|string|max:40',
            'fax_2'                         => 'nullable|string|max:40',
            'persona_contacto_2'            => 'nullable|string|max:255',
            'email_2'                       => 'nullable|string',
            'web_2'                         => 'nullable|string|max:100',
            'es_contacto_laboratorio_2'     => 'nullable|string|in:T,F|max:1',
            'es_contacto_administracion_2'  => 'nullable|string|in:T,F|max:1',
            'telefono_3'                    => 'nullable|string|max:40',
            'movil_3'                       => 'nullable|string|max:40',
            'fax_3'                         => 'nullable|string|max:40',
            'persona_contacto_3'            => 'nullable|string|max:255',
            'email_3'                       => 'nullable|string',
            'web_3'                         => 'nullable|string|max:100',
            'es_contacto_laboratorio_3'     => 'nullable|string|in:T,F|max:1',
            'es_contacto_administracion_3'  => 'nullable|string|in:T,F|max:1',
            'informacion_adicional'         => 'nullable|string|max:255',
            'otros_datos'                   => 'nullable|string|max:255',
            'proyecto'                      => 'nullable|string|max:255',
            'tarifa_delegacion'             => 'nullable|string|max:10',
            'tarifa_codigo'                 => 'nullable|integer',
            'cliente_igeo'                  => 'nullable|string|max:20',
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

        // Valida la existencia del código de cliente principal
        if (!empty($data['cliente_principal_codigo'])) {
            $mainClient = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', $data['cliente_principal_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_principal_codigo'])
                ->first(); 
            if (!$mainClient) {
                throw new \Exception("El cliente principal no existe");
            }
        }

        // Valida la existencia del tipo de cliente
        if (!empty($data['tipo_cliente_codigo'])) {
            $clientType = DB::connection('dynamic')->table('SINTIC')
                ->where('DEL3COD', $data['tipo_cliente_delegacion'] ?? '')
                ->where('TIC1COD', $data['tipo_cliente_codigo'])
                ->first(); 
            if (!$clientType) {
                throw new \Exception("El tipo de cliente no existe");
            }
        }    

        // Valida la existencia de forma de envío
        if (!empty($data['forma_envio_codigo'])) {
            $method = DB::connection('dynamic')->table('LABFDE')
                ->where('DEL3COD', $data['forma_envio_delegacion'] ?? '')
                ->where('FDE1COD', $data['forma_envio_codigo'])
                ->first(); 
            if (!$method) {
                throw new \Exception("La forma de envío no existe");
            }
        }    

        // Valida la existencia de tarifa
        if (!empty($data['tarifa_codigo'])) {
            $rate = DB::connection('dynamic')->table('LABTAR')
                ->where('DEL3COD', $data['tarifa_delegacion'] ?? '')
                ->where('TAR1COD', $data['tarifa_codigo'])
                ->first(); 
            if (!$rate) {
                throw new \Exception("La tarifa no existe");
            }
        }         
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el nombre de cliente no esté en uso
        if (!empty($data['nombre'])) {
            $existingRecord = DB::connection('dynamic')->table('SINCLI')->where('CLICNOM', $data['nombre']);            
            if (!$isCreating) { 
                // Si se trata de una actualización el nombre no debe estar repetido pero excluyendo el registro actual
                $delegation = $delegation ?? '';
                $existingRecord = $existingRecord->where(function ($query) use ($code, $delegation) {
                    $query->where('CLI1COD', '!=', $code)
                        ->orWhere('DEL3COD', '!=', $delegation);
                });                          
            }
            $existingRecord = $existingRecord->first();
            if ($existingRecord) {
                throw new \Exception("El nombre del cliente ya está en uso");
            }
        }

        // Comprueba que el código para el nuevo cliente no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::connection('dynamic')->table('SINCLI')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('CLI1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código del cliente ya está en uso");
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

        // Comprueba que el cliente no está como cliente principal en otros clientes
        $usedInAnotherTable = DB::connection('dynamic')->table('SINCLI')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado como cliente principal");
        }

        // Comprueba que el cliente no está vinculado a ningún usuario
        $usedInAnotherTable = DB::connection('dynamic')->table('ACCUSU')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en algún usuario");
        }

        // Comprueba que el cliente no está vinculado a ninguna factura
        $usedInAnotherTable = DB::connection('dynamic')->table('FACFAC')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en alguna factura");
        }        

        // Comprueba que el cliente no está vinculado a ninguna línea de factura
        $usedInAnotherTable = DB::connection('dynamic')->table('FACLIF')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en alguna línea de factura");
        }

        // Comprueba que el cliente no está vinculado a ningún contrato
        $usedInAnotherTable = DB::connection('dynamic')->table('FACCON')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en algún contrato");
        }
        
        // Comprueba que el cliente no está vinculado a ningún presupuesto
        $usedInAnotherTable = DB::connection('dynamic')->table('FACPRE')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en algún presupuesto");
        }
        
        // Comprueba que el cliente no está vinculado a ninguna planificación
        $usedInAnotherTable = DB::connection('dynamic')->table('LABPLO')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en alguna planificación");
        }

        // Comprueba que el cliente no está vinculado a ninguna operación
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOPE')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en alguna operación");
        }        

        // Comprueba que el cliente no está vinculado a ningún lote
        $usedInAnotherTable = DB::connection('dynamic')->table('LABLOT')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en algún lote");
        }        

        // Comprueba que el cliente no está vinculado a ningún equipo de cliente
        $usedInAnotherTable = DB::connection('dynamic')->table('LABEQU')
            ->where('CLI2DEL', $delegation)
            ->where('CLI2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("El cliente no puede ser eliminado porque está siendo referenciado en algún equipo de cliente");
        }        

    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra puntos de muestreo relacionados
        DB::connection('dynamic')->table('LABPUM')
        ->where('DEL3COD', $delegation)
        ->where('CLI3COD', $code)
        ->delete();

        // Borra las relaciones del cliente con plantillas
        DB::connection('dynamic')->table('PLAPYC')
        ->where('DEL3CLI', $delegation)
        ->where('CLI3COD', $code)
        ->delete();

        // Borra asociaciones del cliente con empleados
        DB::connection('dynamic')->table('GRHCLI')
        ->where('CLI3DEL', $delegation)
        ->where('CLI3COD', $code)
        ->delete();

        // Borra precios por cliente de servicios
        DB::connection('dynamic')->table('LABSYC')
        ->where('CLI3DEL', $delegation)
        ->where('CLI3COD', $code)
        ->delete();

        // Borra precios por cliente de técnicas
        DB::connection('dynamic')->table('LABTYC')
        ->where('CLI3DEL', $delegation)
        ->where('CLI3COD', $code)
        ->delete(); 

        // Documentos a la papelera
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('CLI2COD', $code)
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