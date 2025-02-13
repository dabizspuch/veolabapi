<?php

namespace App\Http\Controllers;

use App\Traits\OperationInventoryTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductoLoteController extends BaseController
{
    use OperationInventoryTools;

    protected $table = 'ALMSEL';
    protected $delegationField = 'PRD3DEL';
    protected $codeField = 'SEL1COD';    
    protected $key1Field = 'PRD3COD'; // Se utilizará como serie en la generación del código
    protected $searchFields = ['SELCDES', 'SELCCOB'];
    protected $skipNewCode = true;

    protected $previousState; // Estado antes de grabar
    protected $previousAmount = 0; // Existencias antes de grabar

    protected $mapping = [
        'producto_delegacion'         => 'PRD3DEL',
        'producto_codigo'             => 'PRD3COD',
        'numero_serie_lote'           => 'SEL1COD',
        'descripcion'                 => 'SELCDES',
        'codigo_barras'               => 'SELCCOB',
        'estado'                      => 'SELCESA',
        'fecha_alta'                  => 'SELDALT',
        'fecha_apertura'              => 'SELDAPE',
        'fecha_baja'                  => 'SELDBAJ',
        'cantidad_por_unidad'         => 'SELNCAU',
        'unidades_por_lote'           => 'SELNUNL',
        'existencias_unidades'        => 'SELNUNE',
        'existencias_cantidad'        => 'SELNCAE',
        'precio_compra'               => 'SELNPRE',
        'ubicacion_fisica'            => 'SELCUBI',
        'condiciones_ambientales'     => 'SELCCOA',
        'manual_operacion'            => 'SELCMAO',
        'especificaciones_tecnicas'   => 'SELCETC',
        'fecha_recepcion'             => 'SELDREC',
        'fecha_calibracion'           => 'SELDCAL',
        'fecha_mantenimiento'         => 'SELDMAN',
        'fecha_verificacion'          => 'SELDVER',
        'fecha_caducidad'             => 'SELDCAD',
        'fecha_aviso_caducidad'       => 'SELDACA',
        'estado_recepcion'            => 'SELCESR',
        'tipo_fluido'                 => 'SELCTIF',
        'volumen_fluido'              => 'SELCVOF',
        'reglas_analisis'             => 'SELCREA',
        'generico_1'                  => 'SELCGE1',
        'generico_2'                  => 'SELCGE2',
        'generico_3'                  => 'SELCGE3',
        'generico_4'                  => 'SELCGE4',
        'generico_5'                  => 'SELCGE5',
        'generico_6'                  => 'SELCGE6',
        'observaciones'               => 'SELCOBS',
        'proveedor_delegacion'        => 'PRO2DEL',
        'proveedor_codigo'            => 'PRO2COD',
    ];    

    protected function rules()
    {
        $isCreating = request()->isMethod('post');

        // Reglas generales
        return [
            'producto_delegacion'        => 'nullable|string|max:10',
            'producto_codigo'            => $isCreating ? 'required|string|max:15' : 'nullable|string|max:15',
            'numero_serie_lote'          => 'nullable|string|max:30',
            'descripcion'                => 'nullable|string|max:50',
            'codigo_barras'              => 'nullable|string|max:100',
            'estado'                     => 'nullable|string|in:N,U,L,F,B|max:1',
            'fecha_alta'                 => 'nullable|date',
            'fecha_apertura'             => 'nullable|date',
            'fecha_baja'                 => 'nullable|date',
            'cantidad_por_unidad'        => 'nullable|numeric|min:0',
            'unidades_por_lote'          => 'nullable|numeric|min:0',
            'existencias_unidades'       => 'nullable|numeric|min:0',
            'existencias_cantidad'       => 'nullable|numeric|min:0',
            'precio_compra'              => 'nullable|numeric|min:0',
            'ubicacion_fisica'           => 'nullable|string|max:100',
            'condiciones_ambientales'    => 'nullable|string|max:100',
            'manual_operacion'           => 'nullable|string|max:255',
            'especificaciones_tecnicas'  => 'nullable|string|max:255',
            'fecha_recepcion'            => 'nullable|date',
            'fecha_calibracion'          => 'nullable|date',
            'fecha_mantenimiento'        => 'nullable|date',
            'fecha_verificacion'         => 'nullable|date',
            'fecha_caducidad'            => 'nullable|date',
            'fecha_aviso_caducidad'      => 'nullable|date',
            'estado_recepcion'           => 'nullable|string|in:N,U|max:1',
            'tipo_fluido'                => 'nullable|string|max:50',
            'volumen_fluido'             => 'nullable|string|max:50',
            'reglas_analisis'            => 'nullable|string|max:50',
            'generico_1'                 => 'nullable|string|max:50',
            'generico_2'                 => 'nullable|string|max:50',
            'generico_3'                 => 'nullable|string|max:50',
            'generico_4'                 => 'nullable|string|max:50',
            'generico_5'                 => 'nullable|string|max:50',
            'generico_6'                 => 'nullable|string|max:50',
            'observaciones'              => 'nullable|string',
            'proveedor_delegacion'       => 'nullable|string|max:10',
            'proveedor_codigo'           => 'nullable|string|max:15',
        ];

        return $rules;
    }

    protected function validateRelationships(array $data)
    {    
        // Valida la existencia del producto
        if (!empty($data['producto_codigo'])) {
            $family = DB::connection('dynamic')->table('ALMPRD')
                ->where('DEL3COD', $data['producto_delegacion'] ?? '')
                ->where('PRD1COD', $data['producto_codigo'])
                ->first(); 
            if (!$family) {
                throw new \Exception("El producto no existe");
            }
        }

        // Valida la existencia del proveedor
        if (!empty($data['proveedor_codigo'])) {
            $family = DB::connection('dynamic')->table('SINPRO')
                ->where('DEL3COD', $data['proveedor_delegacion'] ?? '')
                ->where('PRO1COD', $data['proveedor_codigo'])
                ->first(); 
            if (!$family) {
                throw new \Exception("El proveedor no existe");
            }
        }        
    }

    protected function validateAdditionalCriteria(array $data, $code = null, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $isCreating = request()->isMethod('post');

        // Comprueba que el código para el nuevo lote no esté en uso
        if ($isCreating) { 
            if (!empty($data['numero_serie_lote'])) {
                $existingRecord = DB::connection('dynamic')->table('ALMSEL')
                    ->where('PRD3DEL', $data['producto_delegacion'] ?? '')
                    ->where('PRD3COD', $data['producto_codigo'])
                    ->where('SEL1COD', $data['numero_serie_lote'])
                    ->exists();
                if ($existingRecord) {
                    throw new \Exception("La serie o lote para el producto ya está en uso");
                }
            }
        }

        if ($isCreating) {
            // Genera el código sin usar claves técnicas
            if (empty($data['numero_serie_lote'])) {
                $data['producto_delegacion'] = $data['producto_delegacion'] ?? '';
                $data['numero_serie_lote'] = $this->getNextLotValue(
                    $data['producto_delegacion'], 
                    $data['producto_codigo']
                );
            }
        } else { 
            // Excluir campos clave de los datos a actualizar porque no serán editables
            unset( 
                $data['producto_delegacion'], 
                $data['producto_codigo'],
                $data['numero_serie_lote'] 
            );
        } 

        if (!$isCreating) {
            // Recuerda valores anteriores
            $result = DB::connection('dynamic')->table('ALMSEL')
                ->select('SELCESA', 'SELNCAE')
                ->where('PRD3DEL', $delegation)
                ->where('PRD3COD', $key1)
                ->where('SEL1COD', $code)
                ->first();

            $this->previousAmount = $result->SELNCAE ?? 0;
            $this->previousState = $result->SELCESA ?? '';
        }
            
        return $data;        
    }
        
    protected function validateBeforeDelete($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        $delegation = $delegation ?? '';

        // Comprueba que la serie o lote no está vinculado a ninguna operación
        $usedInAnotherTable = DB::connection('dynamic')->table('LABOPE')
            ->where('PRD2DEL', $delegation)
            ->where('PRD2COD', $key1)
            ->where('SEL2COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La serie o lote no puede ser eliminada porque está referenciada en alguna operación");
        }             

        // Comprueba que la serie o lote no está siendo usada como materia prima en otro producto
        $usedInAnotherTable = DB::connection('dynamic')->table('ALMMAT')
            ->where('PRM3DEL', $delegation)
            ->where('PRM3COD', $key1)
            ->where('SEM3COD', $code)
            ->exists();
        if ($usedInAnotherTable) {
            throw new \Exception("La serie o lote no puede ser eliminada porque está siendo usada como materia prima");
        }             

    }

    protected function deleteRelatedRecords($code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Borra los movimientos relacionados
        DB::connection('dynamic')->table('ALMMOV')
            ->where('PRD2DEL', $delegation)
            ->where('PRD2COD', $key1)
            ->where('SEL2COD', $code)
            ->delete();

        // Borra las materias primas
        DB::connection('dynamic')->table('ALMMAT')
            ->where('PRD3DEL', $delegation)
            ->where('PRD3COD', $key1)
            ->where('SEL3COD', $code)
            ->delete();            

        // Documentos a la papelera
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)
            ->where('PRD2COD', $key1)
            ->where('SEL2COD', $code)
            ->update([
                'DIR2DEL' => $delegation,
                'DIR2COD' => 0
            ]);     
            
        // Recalcula el stock del producto
        $this->recalculateAffectedProductStock(["{$delegation}" . self::SEPARATOR . "{$key1}"]);
    }    

    protected function updateAdditionalData (array $data, $code, $delegation = null, $key1 = null, $key2 = null, $key3 = null, $key4 = null)
    {
        // Crea el movimiento en inventario si procede
        $isCreating = request()->isMethod('post');

        if ($isCreating) {
            // Nueva
            $movementType = "I"; // Inicial
            $amount = $data['existencias_cantidad'];
        } else {
            // Modificación
            if (!empty($data['estado'])) {
                if ($data['estado'] == 'B') { 
                    // Nuevo estado es baja
                    if ($this->previousState != 'B') {
                        $movementType = 'B'; // Baja
                        $amount = -$this->previousAmount;
                    }
                } else {
                    // Nuevo estado no es baja
                    if ($this->previousState == 'B' && $data['estado'] != 'B') {
                        // Si estaba de baja y ya no lo está
                        $movementType = 'J'; // Ajuste
                        $amount = $data['existencias_cantidad'];
                    } else {
                        if (!empty($data['existencias_cantidad'])) {
                            // Ajuste por cantidad modificada
                            $movementType = 'J'; // Ajuste
                            $amount = ($data['existencias_cantidad'] ?? 0) - $this->previousAmount;
                        }
                    }
                }
            } else {
                if (!empty($data['existencias_cantidad'])) {
                    $movementType = "J"; // Ajuste
                    $amount = ($data['existencias_cantidad'] ?? 0) - $this->previousAmount;
                }
            }
        }

        // Genera el movimiento        
        DB::connection('dynamic')->table('ALMMOV')->insert([
            'DEL3COD' => $delegation,
            'MOV1COD' => $this->generateNewCode($delegation, '', 'ALMMOV'),
            'MOVCTIP' => $movementType,
            'MOVDFEC' => Carbon::now(),
            'MOVNCAN' => (float)$amount,
            'PRD2DEL' => $delegation,
            'PRD2COD' => $key1,
            'SEL2COD' => $code,
        ]);

        // Recalcula el stock del producto
        $this->recalculateAffectedProductStock(["{$delegation}" . self::SEPARATOR . "{$key1}"]);     

        return $data;
    }  
    
    /**
     * Obtiene el siguiente valor de lote para el producto de entrada.
     * 
     * @param string $productDelegation - Delegación del producto para filtrar los lotes.
     * @param string $productCode - Código del producto para filtrar los lotes.
     * @return string - El siguiente valor de lote 
     */
    protected function getNextLotValue($productDelegation, $productCode)
    {
        $maxLot = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $productDelegation)
            ->where('PRD3COD', $productCode)        
            ->selectRaw('MAX(CAST(SEL1COD AS UNSIGNED)) as max_numeric')
            ->value('max_numeric');

        if (!is_null($maxLot)) {
            return (string)($maxLot + 1);
        }
        return '1';
    }

}