<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Traits\ToolsOperation;

class OperacionController extends BaseController
{
    use ToolsOperation;
    
    protected $table = 'LABOPE';
    protected $delegationField = 'DEL3COD';
    protected $key1Field = 'OPE1SER';    
    protected $codeField = 'OPE1COD';    
    protected $inactiveField = 'OPEBANU';
    protected $searchFields = ['OPECDES', 'OPECREF'];
    
    protected $mapping = [
        'delegacion'                    => 'DEL3COD',
        'serie'                         => 'OPE1SER',
        'codigo'                        => 'OPE1COD',
        'informacion'                   => 'OPECINF',
        'fecha_registro'                => 'OPEDREG',
        'fecha_recogida'                => 'OPETREC',
        'fecha_recepcion'               => 'OPETREP',
        'fecha_preparada'               => 'OPEDPRE',
        'fecha_inicio'                  => 'OPEDINI',
        'fecha_fin'                     => 'OPEDFIN',
        'descripcion'                   => 'OPECDES',
        'fecha_validacion'              => 'OPEDVAL',
        'fecha_informe'                 => 'OPEDINF',
        'fecha_envio'                   => 'OPEDENV',
        'fecha_archivo'                 => 'OPEDARC',
        'fecha_anulacion'               => 'OPEDANU',
        'fecha_compromiso'              => 'OPEDCOM',
        'fecha_descarte'                => 'OPEDDES',
        'referencia'                    => 'OPECREF',
        'tipo'                          => 'OPECTIP',
        'tipo_analisis'                 => 'OPENTIA',
        'precio'                        => 'OPENPRE',
        'descuento'                     => 'OPECDTO',
        'observaciones'                 => 'OPECOBS',
        'tecnicas'                      => 'OPECTEC',
        'recolector'                    => 'OPECREC',
        'lugar_recogida'                => 'OPECLUR',
        'temperatura'                   => 'OPECTEM',
        'es_urgente'                    => 'OPEBURG',
        'estado'                        => 'OPENEST',
        'es_baja'                       => 'OPEBANU',
        'es_facturada'                  => 'OPEBFAC',
        'es_facturable'                 => 'OPEBFAB',
        'estado_igeo'                   => 'OPECIGE',
        'identificador_igeo'            => 'OPECIDG',
        'cantidad'                      => 'OPECCAN',
        'unidad'                        => 'OPECUNI',
        'tipo_desglose'                 => 'OPECTID',
        'lote'                          => 'OPECLOT',
        'marca'                         => 'OPECMAR',
        'envase'                        => 'OPECENV',
        'numero_envases'                => 'OPENENV',
        'latitud'                       => 'OPECLAT',
        'longitud'                      => 'OPECLNG',
        'direccion_gps'                 => 'OPECDIG',
        'tipo_muestreo'                 => 'OPECTIM',
        'es_control'                    => 'OPEBCON',
        'id_red_sinac'                  => 'OPENRED',
        'codigo_localidad_sinac'        => 'OPENLOC',
        'direccion_sinac'               => 'OPECDIR',
        'tipo_operacion_delegacion'     => 'TIO2DEL',
        'tipo_operacion_codigo'         => 'TIO2COD',
        'matriz_delegacion'             => 'MAT2DEL',
        'matriz_codigo'                 => 'MAT2COD',
        'equipamiento_delegacion'       => 'EQU2DEL',
        'equipamiento_codigo'           => 'EQU2COD',
        'cliente_delegacion'            => 'CLI2DEL',
        'cliente_codigo'                => 'CLI2COD',
        'punto_muestreo_codigo'         => 'PUM2COD',
        'contrato_delegacion'           => 'CON2DEL',
        'contrato_serie'                => 'CON2SER',
        'contrato_codigo'               => 'CON2COD',
        'presupuesto_delegacion'        => 'PRE2DEL',
        'presupuesto_serie'             => 'PRE2SER',
        'presupuesto_codigo'            => 'PRE2COD',
        'empleado_recolector_delegacion'=> 'EMP2DEL',
        'empleado_recolector_codigo'    => 'EMP2COD',
        'lote_delegacion'               => 'LOT2DEL',
        'lote_serie'                    => 'LOT2SER',
        'lote_codigo'                   => 'LOT2COD',
        'lote_relacionado_delegacion'   => 'LOT4DEL',
        'lote_relacionado_serie'        => 'LOT4SER',
        'lote_relacionado_codigo'       => 'LOT4COD',
        'factura_delegacion'            => 'FAC2DEL',
        'factura_serie'                 => 'FAC2SER',
        'factura_codigo'                => 'FAC2COD',
        'planificacion_delegacion'      => 'PLO2DEL',
        'planificacion_codigo'          => 'PLO2COD',
        'fecha_codigo'                  => 'FEP2COD',
        'dictamen_delegacion'           => 'DIC2DEL',
        'dictamen_codigo'               => 'DIC2COD',
        'tarifa_delegacion'             => 'TAR2DEL',
        'tarifa_codigo'                 => 'TAR2COD',
        'proveedor_delegacion'          => 'PRO2DEL',
        'proveedor_codigo'              => 'PRO2COD',
        'producto_delegacion'           => 'PRD2DEL',
        'producto_codigo'               => 'PRD2COD',
        'serie_lote_numero'             => 'SEL2COD',
        'tecnica_delegacion'            => 'TEC2DEL',
        'tecnica_codigo'                => 'TEC2COD',
        'operacion_control_delegacion'  => 'OPE2DEL',
        'operacion_control_serie'       => 'OPE2SER',
        'operacion_control_codigo'      => 'OPE2COD'
    ];    

    protected function rules()
    {
        // Reglas generales
        $rules = [
            'delegacion'                    => 'nullable|string|max:10',
            'serie'                         => 'nullable|string|max:10',
            'codigo'                        => 'nullable|integer',
            'informacion'                   => 'nullable|string|max:20',
            'fecha_registro'                => 'nullable|date',
            'fecha_recogida'                => 'nullable|date',
            'fecha_recepcion'               => 'nullable|date',
            'fecha_preparada'               => 'nullable|date',
            'fecha_inicio'                  => 'nullable|date',
            'fecha_fin'                     => 'nullable|date',
            'fecha_validacion'              => 'nullable|date',
            'fecha_informe'                 => 'nullable|date',
            'fecha_envio'                   => 'nullable|date',
            'fecha_archivo'                 => 'nullable|date',
            'fecha_anulacion'               => 'nullable|date',
            'fecha_compromiso'              => 'nullable|date',
            'fecha_descarte'                => 'nullable|date',
            'referencia'                    => 'nullable|string|max:255',
            'tipo'                          => 'nullable|string|in:I,E|max:1',
            'tipo_analisis'                 => 'nullable|integer',
            'precio'                        => 'nullable|numeric|min:0|max:99999999.99999',
            'descuento'                     => 'nullable|string|max:15',
            'descripcion'                   => 'nullable|string',
            'observaciones'                 => 'nullable|string',
            'tecnicas'                      => 'nullable|string',
            'recolector'                    => 'nullable|string|max:100',
            'lugar_recogida'                => 'nullable|string|max:100',
            'temperatura'                   => 'nullable|string|max:50',
            'es_urgente'                    => 'nullable|string|in:T,F|max:1',
            'estado'                        => 'nullable|integer|in:0,1,2,3,4,5,6,7',
            'es_baja'                       => 'nullable|string|in:T,F|max:1',  
            'es_facturada'                  => 'nullable|string|in:T,F|max:1',
            'es_facturable'                 => 'nullable|string|in:T,F|max:1',
            'estado_igeo'                   => 'nullable|string|max:1',
            'identificador_igeo'            => 'nullable|integer',
            'cantidad'                      => 'nullable|string|max:20',
            'unidad'                        => 'nullable|string|max:15',
            'tipo_desglose'                 => 'nullable|string|in:S,T,N,O|max:1',
            'lote'                          => 'nullable|string|max:70',
            'marca'                         => 'nullable|string|max:50',
            'envase'                        => 'nullable|string|max:255',
            'numero_envases'                => 'nullable|numeric|min:0|max:99999999.99999',
            'latitud'                       => 'nullable|string|max:20',
            'longitud'                      => 'nullable|string|max:20',
            'direccion_gps'                 => 'nullable|string|max:255',
            'tipo_muestreo'                 => 'nullable|string|in:P,C,I|max:1',
            'es_control'                    => 'nullable|string|in:T,F|max:1',
            'id_red_sinac'                  => 'nullable|integer',
            'codigo_localidad_sinac'        => 'nullable|integer',
            'direccion_sinac'               => 'nullable|string|max:200',
            'tipo_operacion_delegacion'     => 'nullable|string|max:10',
            'tipo_operacion_codigo'         => 'nullable|integer',
            'matriz_delegacion'             => 'nullable|string|max:10',
            'matriz_codigo'                 => 'nullable|integer',
            'equipamiento_delegacion'       => 'nullable|string|max:10',
            'equipamiento_codigo'           => 'nullable|string|max:20',
            'cliente_delegacion'            => 'nullable|string|max:10',
            'cliente_codigo'                => 'nullable|string|max:15',
            'punto_muestreo_codigo'         => 'nullable|integer',
            'contrato_delegacion'           => 'nullable|string|max:10',
            'contrato_serie'                => 'nullable|string|max:10',
            'contrato_codigo'               => 'nullable|integer',
            'presupuesto_delegacion'        => 'nullable|string|max:10',
            'presupuesto_serie'             => 'nullable|string|max:10',
            'presupuesto_codigo'            => 'nullable|integer',
            'empleado_recolector_delegacion'=> 'nullable|string|max:10',
            'empleado_recolector_codigo'    => 'nullable|integer',
            'lote_delegacion'               => 'nullable|string|max:10',
            'lote_serie'                    => 'nullable|string|max:10',
            'lote_codigo'                   => 'nullable|string|max:50',
            'lote_relacionado_delegacion'   => 'nullable|string|max:10',
            'lote_relacionado_serie'        => 'nullable|string|max:10',
            'lote_relacionado_codigo'       => 'nullable|string|max:50',
            'factura_delegacion'            => 'nullable|string|max:10',
            'factura_serie'                 => 'nullable|string|max:10',
            'factura_codigo'                => 'nullable|integer',
            'planificacion_delegacion'      => 'nullable|string|max:10',
            'planificacion_codigo'          => 'nullable|integer',
            'fecha_codigo'                  => 'nullable|integer',
            'dictamen_delegacion'           => 'nullable|string|max:10',
            'dictamen_codigo'               => 'nullable|integer',
            'tarifa_delegacion'             => 'nullable|string|max:10',
            'tarifa_codigo'                 => 'nullable|integer',
            'proveedor_delegacion'          => 'nullable|string|max:10',
            'proveedor_codigo'              => 'nullable|string|max:15',
            'producto_delegacion'           => 'nullable|string|max:10',
            'producto_codigo'               => 'nullable|string|max:15',
            'serie_lote_numero'             => 'nullable|string|max:30',
            'tecnica_delegacion'            => 'nullable|string|max:10',
            'tecnica_codigo'                => 'nullable|string|max:30',
            'operacion_control_delegacion'  => 'nullable|string|max:10',
            'operacion_control_serie'       => 'nullable|string|max:10',
            'operacion_control_codigo'      => 'nullable|integer',
            'servicios'                     => 'nullable|array',
            'servicios.*.delegacion'        => 'nullable|string|max:10',
            'servicios.*.codigo'            => 'nullable|string|max:20',
            'autodefinibles'                => 'nullable|array',
            'autodefinibles.*'              => 'nullable|string', // regla para los elementos del array        
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

        // Valida la existencia del tipo de operación 
        if (!empty($data['tipo_operacion_codigo'])) {        
            $type = DB::table('LABTIO')
                ->where('DEL3COD', $data['tipo_operacion_delegacion'] ?? '')
                ->where('TIO1COD', $data['tipo_operacion_codigo'])
                ->first(); 
            if (!$type) {
                throw new \Exception("El tipo de operación no existe");
            }
        }      
        
        // Valida la existencia de la matriz
        if (!empty($data['matriz_codigo'])) {
            $matrix = DB::table('LABMAT')
                ->where('DEL3COD', $data['matriz_delegacion'] ?? '')
                ->where('MAT1COD', $data['matriz_codigo'])
                ->first(); 
            if (!$matrix) {
                throw new \Exception("La matriz no existe");
            }
        } 

        // Valida la existencia del equipo de cliente
        if (!empty($data['equipamiento_codigo'])) {
            $equipment = DB::table('LABEQU')
                ->where('DEL3COD', $data['equipamiento_delegacion'] ?? '')
                ->where('EQU1COD', $data['equipamiento_codigo'])
                ->first(); 
            if (!$equipment) {
                throw new \Exception("El equipamiento no existe");
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

        // Valida que si se establece el punto debe haber también cliente
        if (!empty($data['punto_muestreo_codigo']) && empty($data['cliente_codigo'])) {
            throw new \Exception("El código del cliente es requerido cuando se proporciona un punto de muestreo.");
        }        

        // Valida la existencia del punto de muestreo
        if (!empty($data['cliente_codigo']) && !empty($data['punto_muestreo_codigo'])) {
            $samplingPoint = DB::table('LABPUM')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('CLI3COD', $data['cliente_codigo'])
                ->where('PUM1COD', $data['punto_muestreo_codigo'])
                ->first();
            if (!$samplingPoint) {
                throw new \Exception("El punto de muestreo no existe");
            }
        }            

        // Si existe contrato debe haber un cliente
        if (!empty($data['contrato_codigo']) && empty($data['cliente_codigo'])) {
            throw new \Exception("El código del cliente es requerido cuando se proporciona un contrato.");
        }        

        // Valida la existencia del contrato
        if (!empty($data['contrato_codigo'])) {
            $contract = DB::table('FACCON')
                ->where('DEL3COD', $data['contrato_delegacion'] ?? '')
                ->where('CON1COD', $data['contrato_codigo'])
                ->where(function ($query) use ($data) {
                    // Si la serie está vacía, se filtra por cadena vacía
                    if (empty($data['contrato_serie'])) {
                        $query->where('CON1SER', '');
                    } else {
                        $query->where('CON1SER', $data['contrato_serie']);
                    }
                })
                ->first();
            if (!$contract) {
                throw new \Exception("El contrato no existe");
            }
        }

        // Si existe presupuesto debe haber un cliente
        if (!empty($data['presupuesto_codigo']) && empty($data['cliente_codigo'])) {
            throw new \Exception("El código del cliente es requerido cuando se proporciona un presupuesto`.");
        }        

        // Valida la existencia del presupuesto
        if (!empty($data['presupuesto_codigo'])) {
            $budget = DB::table('FACPRE')
                ->where('DEL3COD', $data['presupuesto_delegacion'] ?? '')
                ->where('PRE1COD', $data['presupuesto_codigo'])
                ->where(function ($query) use ($data) {
                    // Si la serie está vacía, se filtra por cadena vacía
                    if (empty($data['presupuesto_serie'])) {
                        $query->where('PRE1SER', '');
                    } else {
                        $query->where('PRE1SER', $data['presupuesto_serie']);
                    }
                })                
                ->first();
            if (!$budget) {
                throw new \Exception("El presupuesto no existe");
            }
        }

        // Valida la existencia del empleado recolector
        if (!empty($data['empleado_recolector_codigo'])) {
            $employee = DB::table('GRHEMP')
                ->where('DEL3COD', $data['empleado_recolector_delegacion'] ?? '')
                ->where('PRE1COD', $data['empleado_recolector_codigo'])
                ->first();
            if (!$employee) {
                throw new \Exception("El empleado recolector no existe");
            }
        }        

        // Valida la existencia del lote
        if (!empty($data['lote_codigo'])) {
            $batch = DB::table('LABLOT')
                ->where('DEL3COD', $data['lote_delegacion'] ?? '')
                ->where('LOT1COD', $data['lote_codigo'])
                ->where(function ($query) use ($data) {
                    // Si la serie está vacía, se filtra por cadena vacía
                    if (empty($data['lote_serie'])) {
                        $query->where('LOT1SER', '');
                    } else {
                        $query->where('LOT1SER', $data['lote_serie']);
                    }
                })                
                ->first();
            if (!$batch) {
                throw new \Exception("El lote no existe");
            }
        }

        // Valida la existencia del lote relacionado
        if (!empty($data['lote_relacionado_codigo'])) {
            $batch = DB::table('LABLOT')
                ->where('DEL3COD', $data['lote_relacionado_delegacion'] ?? '')
                ->where('LOT1COD', $data['lote_relacionado_codigo'])
                ->where(function ($query) use ($data) {
                    // Si la serie está vacía, se filtra por cadena vacía
                    if (empty($data['lote_relacionado_serie'])) {
                        $query->where('LOT1SER', '');
                    } else {
                        $query->where('LOT1SER', $data['lote_relacionado_serie']);
                    }
                })                 
                ->first();
            if (!$batch) {
                throw new \Exception("El lote relacionado no existe");
            }
        }

        // Valida la existencia de la factura
        if (!empty($data['factura_codigo'])) {
            $invoice = DB::table('FACFAC')
                ->where('DEL3COD', $data['factura_delegacion'] ?? '')
                ->where('FAC1COD', $data['factura_codigo'])
                ->where(function ($query) use ($data) {
                    // Si la serie está vacía, se filtra por cadena vacía
                    if (empty($data['factura_serie'])) {
                        $query->where('FAC1SER', '');
                    } else {
                        $query->where('FAC1SER', $data['factura_serie']);
                    }
                })                 
                ->first();
            if (!$invoice) {
                throw new \Exception("La factura no existe");
            }
        }

        // Valida la existencia de la planificación
        if (!empty($data['planificacion_codigo'])) {
            $planning = DB::table('LABPLO')
                ->where('DEL3COD', $data['planificacion_delegacion'] ?? '')
                ->where('PLO1COD', $data['planificacion_codigo'])
                ->first();
            if (!$planning) {
                throw new \Exception("La planificación no existe");
            }
        }

        // Valida la existencia del dictamen
        if (!empty($data['dictamen_codigo'])) {
            $opinion = DB::table('LABDIC')
                ->where('DEL3COD', $data['dictamen_delegacion'] ?? '')
                ->where('DIC1COD', $data['dictamen_codigo'])
                ->first();
            if (!$opinion) {
                throw new \Exception("El dictamen no existe");
            }
        }

        // Valida la existencia de la tarifa
        if (!empty($data['tarifa_codigo'])) {
            $rate = DB::table('LABTAR')
                ->where('DEL3COD', $data['tarifa_delegacion'] ?? '')
                ->where('TAR1COD', $data['tarifa_codigo'])
                ->first();
            if (!$rate) {
                throw new \Exception("La tarifa no existe");
            }
        }

        // Valida la existencia del proveedor
        if (!empty($data['proveedor_codigo'])) {
            $supplier = DB::table('SINPRO')
                ->where('DEL3COD', $data['proveedor_delegacion'] ?? '')
                ->where('PRO1COD', $data['proveedor_codigo'])
                ->first();
            if (!$supplier) {
                throw new \Exception("El proveedor no existe");
            }
        }

        // Valida la existencia del producto
        if (!empty($data['producto_codigo'])) {
            $product = DB::table('ALMPRD')
                ->where('DEL3COD', $data['producto_delegacion'] ?? '')
                ->where('PRD1COD', $data['producto_codigo'])
                ->first();
            if (!$product) {
                throw new \Exception("El producto no existe");
            }
        }

        // Valida que si se establece la serie debe haber también producto
        if (!empty($data['numero_serie_lote']) && empty($data['producto_codigo'])) {
            throw new \Exception("El código del producto es requerido cuando se proporciona una serie.");
        }         

        // Valida la existencia de la serie o lote
        if (!empty($data['numero_serie_lote']) && !empty($data['producto_codigo'])) {
            $product = DB::table('ALMSEL')
                ->where('PRD3DEL', $data['producto_delegacion'] ?? '')
                ->where('PRD3COD', $data['producto_codigo'])
                ->where('SEL1COD', $data['numero_serie_lote'])
                ->first();
            if (!$product) {
                throw new \Exception("La serie o lote no existe");
            }
        }        

        // Valida la existencia de la técnica
        if (!empty($data['tecnica_codigo'])) {
            $parameter = DB::table('LABTEC')
                ->where('DEL3COD', $data['tecnica_delegacion'] ?? '')
                ->where('TEC1COD', $data['tecnica_codigo'])
                ->first();
            if (!$parameter) {
                throw new \Exception("La técnica no existe");
            }
        }

        // Valida la existencia de la operación de control
        if (!empty($data['operacion_control_codigo'])) {
            $controlOperation = DB::table('LABOPE')
                ->where('DEL3COD', $data['operacion_control_delegacion'] ?? '')
                ->where('OPE1COD', $data['operacion_control_codigo'])
                ->where(function ($query) use ($data) {
                    // Si la serie está vacía, se filtra por cadena vacía
                    if (empty($data['operacion_control_serie'])) {
                        $query->where('OPE1SER', '');
                    } else {
                        $query->where('OPE1SER', $data['operacion_control_serie']);
                    }
                }) 
                ->first();
            if (!$controlOperation) {
                throw new \Exception("La operación de control no existe");
            }
        }
        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null)
    {
        $isCreating = request()->isMethod('post');

        // Establece campos con valor predeterminado
        if (!array_key_exists('tipo', $data)) {    
            $data['tipo'] = 'E';    // Por defeto operación externa
        }        
        if (!array_key_exists('es_facturable', $data)) {            
            if ($data['tipo'] === 'E') {
                $data['es_facturable'] = 'T';
            } else {
                $data['es_facturable'] = 'F';
            }
        }        

        // Si se está modificando una operación se debe comprobar que no se hayan firmado o validado informes relacionados
        if (!is_null($code)) {
            $this->validateOperation($delegation, $key1, $code);
        }

        // Comprueba que el código para la nueva operación no esté en uso
        if ($isCreating) { 
            if (!empty($data['codigo'])) {
                $existingRecord = DB::table('LABOPE')
                    ->where('DEL3COD', $data['delegacion'] ?? '')
                    ->where('OPE1SER', $data['serie'])
                    ->where('OPE1COD', $data['codigo'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("El código de la operación ya está en uso");
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
        $delegation = $delegation ?? '';

        // Comprueba que no esté referenciada en órdenes
        $usedInAnotherTable = DB::table('LABOYO')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada en órdenes");
        }

        // Comprueba que no esté referenciada en informes
        $usedInAnotherTable = DB::table('LABIYO')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada en informes");
        }     

        // Comprueba que no esté referenciada en líneas de factura
        $usedInAnotherTable = DB::table('FACLIF')
            ->where('OPE2DEL', $delegation)
            ->where('OPE2SER', $key1)
            ->where('OPE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada en líneas de factura");
        }   

        // Comprueba que no esté referenciada en facturas
        $operation = DB::table('LABOPE')
            ->where('DEL3COD', $delegation)
            ->where('OPE1SER', $key1)
            ->where('OPE1COD', $code)
            ->first();
        if ($operation) {
            if (!is_null($operation->FAC2COD) && $operation->FAC2COD > 0) {
                throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada en facturas");
            }
        }     

        // Comprueba que no esté referenciada en residuos
        $usedInAnotherTable = DB::table('LABRED')
            ->where('OPE2DEL', $delegation)
            ->where('OPE2SER', $key1)
            ->where('OPE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada en residuos");
        }

        // Comprueba que el cliente no está vinculado a ningún préstamo
        $usedInAnotherTable = DB::table('ALMPRE')
            ->where('OPE2DEL', $delegation)
            ->where('OPE2SER', $key1)
            ->where('OPE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada en algún préstamo");
        }        
        
        // Comprueba que no esté referenciada en cartas de control
        $usedInAnotherTable = DB::table('LABRCD')
            ->where('OPE2DEL', $delegation)
            ->where('OPE2SER', $key1)
            ->where('OPE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada en cartas de control");
        }  
        
        // Comprueba que no esté referenciada como operación de control
        $usedInAnotherTable = DB::table('LABOPE')
            ->where('OPE2DEL', $delegation)
            ->where('OPE2SER', $key1)
            ->where('OPE2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La operación no puede ser eliminada porque está siendo referenciada como operación de control");
        }         

    }    

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra tablas relacionadas con los servicios
        $this->deleteRelatedRecordsService($code, $delegation, $key1);

        // Borra valores de autodefinibles
        DB::table('LABOYA')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();       
            
        // Borra notificaciones
        DB::table('ACCNOT')
            ->where('OPE2DEL', $delegation)
            ->where('OPE2SER', $key1)
            ->where('OPE2COD', $code)
            ->delete(); 

        // Documentos a la papelera
        DB::table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('OPE2SER', $key1)
            ->where('OPE2COD', $code)
            ->update([
                'DIR2DEL' => $delegation,
                'DIR2COD' => 0
            ]);            
    } 

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Crear estructura para cada servicio
        if (isset($data['servicios'])) {

            // Borra las tablas relacionadas con servicios
            $this->deleteRelatedRecordsService($code, $delegation, $key1);
            
            $servicePosition = 0;       // Posición del servicio en la rejilla
            $defaultService = 'T';      // Controla el estado del servicio por defecto (primero)
            $totalPrice = 0;            // Acumulado de precios
            $employees = [];            // Lista de analistas
            $departments = [];          // Lista de departamentos 
            $products = [];             // Lista de productos para actualizar stock  
            $rateDelegation = '';       // Delegación de tarifa
            $rateCode = 0;              // Código de tarifa
            $clientDelegation = '';     // Delegación de cliente
            $clientCode = '';           // Código de cliente

            // Lee configuraciones
            [$isRateBased, $isDefaultValue] = $this->getConfiguration();
            [$markDelegation, $markCode] = $this->getDefaultMark($delegation);

            if ($isRateBased) {
                [$rateCode, $rateDelegation] = $this->getRateData($data, $delegation, $key1, $code);
            } else {
                [$clientCode, $clientDelegation] = $this->getClientData($data, $delegation, $key1, $code);
            }                                       

            // Recorre la lista de servicios introducida            
            foreach ($data['servicios'] as $service) {
                $servicePosition++; // El servicio ocupa una posición

                // Obtiene información en la base de datos del servicio
                $serviceData = DB::table('LABSER')
                    ->where('DEL3COD', $service['delegacion'])
                    ->where('SER1COD', $service['codigo'])
                    ->first();

                if ($serviceData) {

                    if ($isRateBased) {
                        [$finalPrice, $finalDiscount] = $this->getPriceAndDiscountByRate(
                            $service, 
                            $rateDelegation, 
                            $rateCode, 
                            $serviceData);
                    } else {
                        [$finalPrice, $finalDiscount] = $this->getPriceAndDiscountByClient(
                            $service, 
                            $clientDelegation, 
                            $clientCode, 
                            $serviceData
                        );
                    } 

                    $totalPrice += $this->calculateAmountWithDiscount($finalPrice, $finalDiscount);

                    // Inserta el servicio asociado a la operación
                    DB::table('LABOYS')->insert([
                        'OPE3DEL' => $delegation,
                        'OPE3SER' => $key1,
                        'OPE3COD' => $code,
                        'SER3DEL' => $serviceData->DEL3COD,
                        'SER3COD' => $serviceData->SER1COD,
                        'OYSNPRE' => $finalPrice,
                        'OYSCDTO' => $finalDiscount,
                        'OYSNPOS' => $servicePosition,
                        'OYSBPRE' => $defaultService
                    ]);

                    // Lee parámetros del servicio 
                    $maxParameterPosition = 0;   
                    $parameters = $this->getServiceParameters($service['delegacion'], $service['codigo']);
                    
                    // Sobrescribe la lista de parámetros para guardar en operación
                    $data['tecnicas'] = $parameters->pluck('TECCNOM')->implode(';');

                    // Procesa los parámetros
                    foreach ($parameters as $parameter) {

                        $servicePosition++; // Cada parámetro incrementa la posición

                        $this->insertParam(
                            $delegation, 
                            $key1, 
                            $code, 
                            $parameter, 
                            $serviceData, 
                            $servicePosition, 
                            $parameter->SYTNORD,
                            $isRateBased, 
                            $isDefaultValue, 
                            $markDelegation, 
                            $markCode, 
                            $rateDelegation, 
                            $rateCode, 
                            $clientDelegation, 
                            $clientCode,
                            $employees,
                            $departments,
                            $products
                        );

                        // Se recordará la posición último parámetro del servicio
                        $maxParameterPosition = max($maxParameterPosition, $parameter->SYTNORD);
                    }                

                    // Inserta gastos en LABOYG por cada servicio
                    $expenses = DB::table('LABSYE')
                        ->leftJoin('LABESC', function($join) {
                            $join->on('LABSYE.DEL3ESC', '=', 'LABESC.DEL3COD')
                                ->on('LABSYE.ESC3COD', '=', 'LABESC.ESC1COD');
                        })
                        ->where('LABSYE.DEL3SER', $service['delegacion'])
                        ->where('LABSYE.SER3COD', $service['codigo'])
                        ->get();
                    foreach ($expenses as $expense) {
                        
                        $servicePosition++; // Cada gasto también incrementa la posición

                        DB::table('LABOYG')->insert([
                            'OPE3DEL' => $delegation,
                            'OPE3SER' => $key1,
                            'OPE3COD' => $code,
                            'ESC3DEL' => $expense->DEL3ESC,
                            'ESC3COD' => $expense->ESC3COD,
                            'OYGBSUP' => $expense->ESCBSUP,
                            'OYGBAGR' => 'F',
                            'OYGNPRE' => $expense->ESCNPRE,
                            'OYGCDTO' => $expense->ESCCDTO,
                            'OYGNPOS' => $maxParameterPosition + 2, // Se suma posición del servicio
                            'SER2DEL' => $expense->DEL3SER,
                            'SER2COD' => $expense->SER3COD,
                        ]);
                    }

                    if ($defaultService === 'T') {
                        $data = $this->setDefaultServiceValues($serviceData, $data);
                        $defaultService = 'F';  // El primer servicio será el predeterminado
                    }
                }
            }

            // Precio recalculado a partir de los servicios
            if (!array_key_exists('precio', $data)) {
                $data['precio'] = $totalPrice;
            }

            // Inserta empleados en LABOYE
            foreach ($employees as $employee) {
                // Divide la clave en delegación y código
                [$employeeDelegation, $employeeCode] = explode(self::SEPARATOR, $employee);
                DB::table('LABOYE')->insert([
                    'OPE3DEL' => $delegation,
                    'OPE3SER' => $key1,
                    'OPE3COD' => $code,
                    'EMP3DEL' => $employeeDelegation,
                    'EMP3COD' => $employeeCode
                ]);
            }

            // Inserta departamentos en LABOYD
            foreach ($departments as $department) {
                // Divide la clave en delegación y código
                [$departmentDelegation, $departmentCode] = explode(self::SEPARATOR, $department);
                DB::table('LABOYD')->insert([
                    'OPE3DEL' => $delegation,
                    'OPE3SER' => $key1,
                    'OPE3COD' => $code,
                    'DEP3DEL' => $departmentDelegation,
                    'DEP3COD' => $departmentCode
                ]);
            }
        
            // Recalcula el stock de cada producto afectado por consumos
            $this->recalculateAffectedProductStock($products);
        }    
        
        // Campos autodefinibles
        if (isset($data['autodefinibles'])) {
            foreach ($data['autodefinibles'] as $fieldName => $fieldValue) {
                $fields = DB::table('LABAUT')->where('AUTCNOM', $fieldName)->first();
                if ($fields) {
                    DB::table('LABOYA')->updateOrInsert(
                        [
                            'OPE3DEL' => $delegation,
                            'OPE3SER' => $key1,
                            'OPE3COD' => $code,
                            'AUT3DEL' => $fields->DEL3COD,
                            'AUT3COD' => $fields->AUT1COD,
                        ],
                        [
                            'OYACVAL' => $fieldValue,
                        ]
                    );           
                }
            }
        }

        return $data;
    }    

    /**
     * Borra las tablas relacionadas con servicios para la operación de entrada
     */
    private function deleteRelatedRecordsService ($code, $delegation = null, $key1 = null) {
        // Borra resultados
        DB::table('LABRES')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();   
        
        // Borra columnas de resultados
        DB::table('LABCOR')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();        

        // Borra servicios de operación
        DB::table('LABOYS')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();        

        // Borra empleados de operación
        DB::table('LABOYE')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();   

        // Borra departamentos de operación
        DB::table('LABOYD')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();        

        // Borra gastos de operación
        DB::table('LABOYG')
            ->where('OPE3DEL', $delegation)
            ->where('OPE3SER', $key1)
            ->where('OPE3COD', $code)
            ->delete();   
            
        // Cancela existencias consumos
        $this->deleteConsumptions($delegation, $key1, $code);
    }

    /**
     * Recupera el precio y descuento por tarifa, o devuelve el predeterminado del servicio.
     */
    private function getPriceAndDiscountByRate($service, $rateDelegation, $rateCode, $serviceData)
    {
        $priceAndDiscount = DB::table('LABSYF')
            ->where('SER3DEL', $service['delegacion'])
            ->where('SER3COD', $service['codigo'])          
            ->where('TAR3DEL', $rateDelegation)  
            ->where('TAR3COD', $rateCode)  
            ->first();
        if ($priceAndDiscount) {
            return [$priceAndDiscount->SYFNPRE ?? 0, $priceAndDiscount->SYFCDTO ?? ''];
        } else {
            return [$serviceData->SERNPRE ?? 0, $serviceData->SERCDTO ?? ''];
        }    
    }

    /**
     * Recupera el precio y descuento por cliente, o devuelve el predeterminado del servicio.
     */
    private function getPriceAndDiscountByClient($service, $clientDelegation, $clientCode, $serviceData)
    {
        $priceAndDiscount = DB::table('LABSYC')
            ->where('SER3DEL', $service['delegacion'])
            ->where('SER3COD', $service['codigo'])          
            ->where('CLI3DEL', $clientDelegation)  
            ->where('CLI3COD', $clientCode)  
            ->first();
        if ($priceAndDiscount) {
            return [$priceAndDiscount->SYCNPRE ?? 0, $priceAndDiscount->SYCCDTO ?? ''];
        } else {
            return [$serviceData->SERNPRE ?? 0, $serviceData->SERCDTO ?? ''];
        }
    }     

    /**
     * Recupera la lista de parámetros para el servicio de entrada 
     */
    private function getServiceParameters($delegation, $code)
    {
        return DB::table('LABSYT')
            ->leftJoin('LABTEC', function($join) {
                $join->on('LABSYT.DEL3TEC', '=', 'LABTEC.DEL3COD')
                    ->on('LABSYT.TEC3COD', '=', 'LABTEC.TEC1COD');
            })
            ->where('LABSYT.DEL3SER', $delegation)
            ->where('LABSYT.SER3COD', $code)
            ->orderBy('LABSYT.SYTNORD', 'asc')
            ->get();
    }

    /**
     * Asigna valores predeterminados al array de datos.
     */    
    private function setDefaultServiceValues ($serviceData, $data) 
    {
        // Asigna tipo de operación del servicio predeterminado
        if (!array_key_exists('tipo_operacion_codigo', $data)) {
            $data['tipo_operacion_delegacion'] = $serviceData->TIO2DEL;
            $data['tipo_operacion_codigo'] = $serviceData->TIO2COD;                        
        }
        // Asigna matriz del servicio predeterminado
        if (!array_key_exists('matriz_codigo', $data)) {
            $data['matriz_delegacion'] = $serviceData->MAT2DEL;
            $data['matriz_codigo'] = $serviceData->MAT2COD;                        
        }
        // Asigna número de envases del servicio predeterminado
        if (!array_key_exists('numero_envases', $data)) {
            $data['numero_envases'] = $serviceData->SERNENV;                        
        }
        // Asigna cantidad del servicio predeterminado
        if (!array_key_exists('cantidad', $data)) {
            $data['cantidad'] = $serviceData->SERCCAN;                        
        }                                        

        return $data;        
    }

    /**
     * Aplica un descuento o impuesto al importe total.
     */
    private function calculateAmountWithDiscount($dblAmount, $strDiscount) 
    {    
        $lonPos = strpos($strDiscount, "%");
        
        if ($lonPos !== false) {
            $percentage = (float)substr($strDiscount, 0, $lonPos);
            $dblAmount -= $dblAmount * $percentage / 100;
        } else {
            $dblAmount -= (float)$strDiscount;
        }
        
        return $dblAmount;
    }    

}