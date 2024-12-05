<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Exception;
use stdClass;

trait ToolsOperation
{
    const SEPARATOR = "\x1B";   // Escape

    /**
     * Comprueba que la operación no esté en informes validados o firmados
     * 
     * @param string $delegation - Delegación de la operación
     * @param string $serie - Serie de la operación
     * @param int $code - Código de la operación
     * @return void
     * @throws Exception - Si la operación ya ha sido validada
     */
    public function validateOperation(string $delegation, string $serie, int $code)
    {
        if (!is_null($code)) {
            $isValidated = DB::table('LABIYO')
                ->leftJoin('LABINF', function ($join) {
                    $join->on('LABIYO.INF3DEL', '=', 'LABINF.DEL3COD')
                         ->on('LABIYO.INF3SER', '=', 'LABINF.INF1SER')
                         ->on('LABIYO.INF3COD', '=', 'LABINF.INF1COD');
                })
                ->leftJoin('LABFIR', function ($join) {
                    $join->on('LABIYO.INF3DEL', '=', 'LABFIR.INF3DEL')
                         ->on('LABIYO.INF3SER', '=', 'LABFIR.INF3SER')
                         ->on('LABIYO.INF3COD', '=', 'LABFIR.INF3COD');
                })
                ->where('LABIYO.OPE3DEL', $delegation)
                ->where('LABIYO.OPE3SER', $serie)
                ->where('LABIYO.OPE3COD', $code)
                ->where('LABIYO.IYOBHIS', '<>', 'T')
                ->where(function ($query) {
                    $query->where('LABFIR.FIRBVAL', 'T')
                          ->orWhere('LABINF.INFCVAL', 'V');
                })
                ->exists();

            if ($isValidated) {
                throw new Exception("La operación ya ha sido validada y no puede ser modificada.");
            }
        }
    }

    /**
     * Inserta el parámetro en la operación a partir de las estructuras de ficheros
     * 
    * @param string $operationDelegation - Delegación de la operación.
    * @param string $operationSeries - Serie de la operación.
    * @param int $operationCode - Código de la operación.
    * @param stdClass $parameter - Objeto que contiene los detalles del parámetro a insertar.
    * @param stdClass $service - Objeto que contiene la información del servicio.
    * @param int $servicePosition - Posición del servicio.
    * @param bool $isRateBased - Indica si la tarifa se basa en la tarifa o en el cliente.
    * @param bool $isDefaultValue - Indica si tiene configurado un valor predeterminado.
    * @param string $markDelegation - Delegación de la marca.
    * @param int $markCode - Código de la marca.
    * @param string $rateDelegation - Delegación para la tarifa.
    * @param int $rateCode - Código de la tarifa.
    * @param string $clientDelegation - Delegación del cliente.
    * @param string $clientCode - Código del cliente.
    * @param array &$employees - Lista de empleados a actualizar.
    * @param array &$departments - Lista de departamentos a actualizar.
    * @return void
    */
    public function insertParam (
        string $operationDelegation,
        string $operationSeries,
        int $operationCode,
        stdClass $parameter,
        stdClass $service,
        int $servicePosition,
        int $parameterPosition,
        bool $isRateBased,
        bool $isDefaultValue,
        string $markDelegation,
        int $markCode,
        string $rateDelegation,
        int $rateCode,
        string $clientDelegation,
        string $clientCode,
        array &$employees,
        array &$departments,
        array &$products

    ) {
        // Obtiene el precio y descuento en función del tipo (por tarifa o cliente)
        if ($isRateBased) {
            [$parameterPrice, $parameterDiscount] = $this->getParameterPriceAndDiscountByRate(
                $parameter, 
                $rateDelegation, 
                $rateCode
            );
        } else {
            [$parameterPrice, $parameterDiscount] = $this->getParameterPriceAndDiscountByClient(
                $parameter, 
                $clientDelegation, 
                $clientCode
            );
        }
        // Obtiene la normativa del parámetro              
        $regulation = $this->getParameterRegulation($parameter, $service);

        // Obtiene el analista predeterminado del parámetro
        [$employeeDelegation, $employeeCode] = $this->getParameterAnalyst($parameter);
        // Acumula los empleados en una lista sin repetición
        if ($employeeCode) {
            $employeeKey = $employeeDelegation . self::SEPARATOR . $employeeCode;
            if (!in_array($employeeKey, $employees)) {
                $employees[] = $employeeKey;
            }     
        }
        
        // Obtiene el departamento de la sección del parámetro
        [$departmentDelegation, $departmentCode] = $this->getDepartment($parameter);
        // Acumula los departamentos en una lista sin repetición
        if ($departmentCode) {
            $departmentKey = $departmentDelegation . self::SEPARATOR . $departmentCode;
            if (!in_array($departmentKey, $departments)) {
                $departments[] = $departmentKey;
            }     
        }
        
        // Inserta el parámetro en la tabla LABRES
        DB::table('LABRES')->insertOrIgnore([
            'OPE3DEL' => $operationDelegation,
            'OPE3SER' => $operationSeries,
            'OPE3COD' => $operationCode,
            'TEC3DEL' => $parameter->DEL3COD,
            'TEC3COD' => $parameter->TEC1COD,
            'RESCNOM' => $parameter->TECCNOM,
            'RESCNOI' => $parameter->TECCNOI,
            'RESBCUR' => $parameter->TECBCUR,
            'RESDACR' => $parameter->TECDACR,
            'RESCPAR' => $parameter->TECCPAR,
            'RESCABR' => $parameter->TECCABR,
            'RESCCAS' => $parameter->TECCCAS,
            'RESNPRE' => $parameterPrice,
            'RESCDTO' => $parameterDiscount,
            'RESCUNI' => $parameter->TECCUNI,
            'RESCLEY' => $parameter->TECCLEY,
            'RESCMET' => $parameter->TECCMET,
            'RESCMEA' => $parameter->TECCMEA,
            'RESCNOR' => $regulation,
            'RESNTIE' => $parameter->TECNTIE,
            'RESCLIM' => $parameter->TECCLIM,
            'RESCMIN' => $parameter->TECCMIN,
            'RESCINC' => $parameter->TECCINC,
            'RESCINS' => $parameter->TECCINS,
            'RESBEXP' => $parameter->TECBEXP,
            'RESNORD' => $servicePosition + $parameterPosition,
            'RESBAGR' => 'F',
            'SEC2DEL' => $parameter->SEC2DEL,
            'SEC2COD' => $parameter->SEC2COD,
            'EMP2DEL' => $employeeDelegation,
            'EMP2COD' => $employeeCode,
            'SER2DEL' => $service->DEL3COD,
            'SER2COD' => $service->SER1COD
        ]);

        // Recorre las columnas predefinidas para la técnica
        $columns = DB::table('LABCOT')
            ->where('TEC3DEL', $parameter->DEL3COD)
            ->where('TEC3COD', $parameter->TEC1COD)
            ->get();

        $columnNumber = 0;
        foreach ($columns as $column) {
            $columnNumber++;  

            $defaultValue = $isDefaultValue ? $column->COTCPRE : '';
            
            // Inserta la columna en LABCOR
            DB::table('LABCOR')->insertOrIgnore([
                'OPE3DEL' => $operationDelegation,
                'OPE3SER' => $operationSeries,
                'OPE3COD' => $operationCode,
                'TEC3DEL' => $parameter->DEL3COD,
                'TEC3COD' => $parameter->TEC1COD,
                'COR1COD' => $columnNumber,
                'CORCVAL' => $defaultValue,
                'CORCTIT' => $column->COTCTIT,
                'CORCTI2' => $column->COTCTI2,
                'CORCTI3' => $column->COTCTI3,
                'CORBINF' => $column->COTBINF,
                'CORBRES' => $column->COTBRES,
                'CORBEDI' => $column->COTBEDI,
                'CORBCON' => $column->COTBCON,
                'CORBCOP' => $column->COTBCOP,
                'CORBACT' => $column->COTBACT,
                'MAR2DEL' => $markDelegation,
                'MAR2COD' => $markCode,
            ]);
        }

        // Crea los consumos a considerar por parámetro
        $this->insertConsumptions
        (
            $operationDelegation, 
            $operationSeries, 
            $operationCode, 
            $parameter->DEL3COD, 
            $parameter->TEC1COD, 
            $products
        );                        

        // Crea los usos de equipos a considerar por parámetro
        $this->insertEquipmentUses
        (
            $operationDelegation, 
            $operationSeries, 
            $operationCode, 
            $parameter->DEL3COD, 
            $parameter->TEC1COD
        );                      

    }

    /**
     * Cancela las existencias y actualiza el inventario.
     *     
     * @param string $operationDelegation - Delegación de la operación a cancelar.
     * @param string $operationSerie - Serie de la operación a cancelar.
     * @param int $operationCode - Código de la operación a cancelar.
     * @param string|null $parameterDelegation - Delegación de parámetro para filtrar (opcional).
     * @param string|null $parameterCode - Código de parámetro para filtrar (opcional).
     * @return void
     */
    public function deleteConsumptions (
        string $operationDelegation,
        string $operationSerie,
        int $operationCode,
        string $parameterDelegation = null, 
        string $parameterCode = null        
    ) {
        // Cancela existencias consumos
        $consumptions = DB::table('ALMMOV')
        ->where('OPE2DEL', $operationDelegation)
        ->where('OPE2SER', $operationSerie)
        ->where('OPE2COD', $operationCode)
        ->when($parameterCode, function ($query) use ($parameterDelegation, $parameterCode) {
            return $query->where('TEC2DEL', $parameterDelegation ?? '')
                         ->where('TEC2COD', $parameterCode);
        })
        ->get();

        foreach ($consumptions as $consumption) {                   
            // Actualiza existencias en la serie/lote sumando la cantidad anulada                 
            DB::table('ALMSEL')
                ->where('PRD3DEL', $consumption->PRD2DEL)
                ->where('PRD3COD', $consumption->PRD2COD)
                ->where('SEL1COD', $consumption->SEL2COD)
                ->update([
                    'SELNCAE' => DB::raw('SELNCAE + ' . $consumption->MOVNCAN),
                    'SELNUNE' => DB::raw('CASE WHEN SELNCAU != 0 THEN FLOOR((SELNCAE + ' . $consumption->MOVNCAN . ') / SELNCAU) ELSE 0 END')
                ]);
            
            // Recalcula las existencias del producto
            $result = DB::table('ALMSEL')
                ->select(DB::raw('SUM(SELNUNE) as suma_unidades, SUM(SELNCAE) as suma_cantidad'))
                ->where('PRD3DEL', $consumption->PRD2DEL)
                ->where('PRD3COD', $consumption->PRD2COD)
                ->where('SELCESA', '<>', 'B')  
                ->first();
            
            if ($result) {
                DB::table('ALMPRD')
                    ->where('DEL3COD', $consumption->PRD2DEL)
                    ->where('PRD1COD', $consumption->PRD2COD)
                    ->update([
                        'PRDNEXI' => $result->suma_unidades,
                        'PRDNCAE' => $result->suma_cantidad
                    ]);
            }
        }        

        // Borra consumos
        DB::table('ALMMOV')
            ->where('OPE2DEL', $operationDelegation)
            ->where('OPE2SER', $operationSerie)
            ->where('OPE2COD', $operationCode)
            ->when($parameterCode, function ($query) use ($parameterDelegation, $parameterCode) {
                return $query->where('TEC2DEL', $parameterDelegation ?? '')
                             ->where('TEC2COD', $parameterCode);
            })                
            ->delete();                    
    }

    /**
     * Crea los movimientos y actualiza los consumos de materiales relacionados con una operación específica.
     * 
     * @param string $operationDelegation - Código de delegación de la operación.
     * @param string $operationSerie - Serie de la operación.
     * @param int $operationCode - Código de la operación.
     * @param string $parameterDelegation - Código de delegación del parámetro. 
     * @param string $parameterCode - Código del parámetro.
     * @param array &$products - Arreglo pasado por referencia donde se acumulan los códigos de productos afectados.
     * @return void
     */
    public function insertConsumptions (
        string $operationDelegation, 
        string $operationSerie, 
        int $operationCode, 
        string $parameterDelegation, 
        string $parameterCode,
        array &$products
    ) {
        $consumptions = DB::table('LABTYP')
            ->where('TEC3DEL', $parameterDelegation)
            ->where('TEC3COD', $parameterCode)
            ->get();
        
        foreach ($consumptions as $consumption) {
            $lotNumber = $this->getDefaultLot ($consumption->PRD3COD, $consumption->PRD3DEL);
            DB::table('ALMMOV')->insert([
                'DEL3COD' => $operationDelegation,
                'MOV1COD' => $this->generateNewCode($operationDelegation, '', 'ALMMOV', false),
                'MOVCTIP' => 'O', // Consumo
                'MOVDFEC' => now(),
                'MOVNCAN' => $consumption->TYPNCON,
                'PRD2DEL' => $consumption->PRD3DEL,
                'PRD2COD' => $consumption->PRD3COD,
                'SEL2COD' => $lotNumber,
                'OPE2DEL' => $operationDelegation,
                'OPE2SER' => $operationSerie,
                'OPE2COD' => $operationCode,
                'TEC2DEL' => $consumption->TEC3DEL,
                'TEC2COD' => $consumption->TEC3COD
            ]);
            
            $this->updateStockLot($consumption->PRD3COD, $consumption->PRD3DEL, $lotNumber, $consumption->TYPNCON);

            // Acumula productos afectados para recalcular stock
            $productKey = $consumption->PRD3DEL . self::SEPARATOR . $consumption->PRD3COD;
            if (!in_array($productKey, $products)) {
                $products[] = $productKey;
            }              
        }
    }

    /**
     * Crea los movimientos de usos de equipamiento relacionados con una operación específica.
     * 
     * @param string $operationDelegation - Código de delegación de la operación.
     * @param string $operationSerie - Serie de la operación.
     * @param int $operationCode - Código de la operación.
     * @param string $parameterDelegation - Código de delegación del parámetro. 
     * @param string $parameterCode - Código del parámetro. 
     * @return void
     */    
    public function insertEquipmentUses (
        string $operationDelegation, 
        string $operationSerie, 
        int $operationCode, 
        string $parameterDelegation, 
        string $parameterCode,                
    ) {
        $equipmentUses = DB::table('LABTYQ')
            ->where('TEC3DEL', $parameterDelegation)
            ->where('TEC3COD', $parameterCode)
            ->get();                                                   
        foreach ($equipmentUses as $equipmentUse) {
            DB::table('ALMMOV')->insert([
                'DEL3COD' => $operationDelegation,
                'MOV1COD' => $this->generateNewCode($operationDelegation, '', 'ALMMOV', false),
                'MOVCTIP' => 'U', // Uso
                'MOVDFEC' => now(),
                'MOVNCAN' => 1,
                'PRD2DEL' => $equipmentUse->PRD3DEL,
                'PRD2COD' => $equipmentUse->PRD3COD,
                'SEL2COD' => $this->getDefaultLot ($equipmentUse->PRD3COD, $equipmentUse->PRD3DEL),
                'OPE2DEL' => $operationDelegation,
                'OPE2SER' => $operationSerie,
                'OPE2COD' => $operationCode,
                'TEC2DEL' => $equipmentUse->TEC3DEL,
                'TEC2COD' => $equipmentUse->TEC3COD
            ]);
        }         
    }

    /**
     * Obtiene el número de serie o lote por defecto para el producto de entrada
     * 
     * @param string $productCode - Código del producto.
     * @param string $productDelegation - Delegación del producto.
     * @return string|null - Serie o lote predeterminado.
     */
    public function getDefaultLot(string $productCode, string $productDelegation)
    {
        $lot = $this->getLot($productCode, $productDelegation, ['U', 'L'], true);
        $lot ??= $this->getLot($productCode, $productDelegation, ['N', null, ''], true);
        $lot ??= $this->getLot($productCode, $productDelegation, ['U', 'L'], false);
        return $lot ? $lot->SEL1COD : null;
    }   
    
    private function getLot(string $productCode, string $productDelegation, array $stateValues, bool $checkCantidad)
    {
        return DB::table('ALMSEL')
            ->select('SEL1COD')
            ->where('PRD3DEL', $productDelegation)
            ->where('PRD3COD', $productCode)
            ->where(function ($query) use ($stateValues) {
                foreach ($stateValues as $value) {
                    if (is_null($value)) {
                        $query->orWhereNull('SELCESA');
                    } else {
                        $query->orWhere('SELCESA', $value);
                    }
                }
            })
            ->where(function ($query) {
                $query->where('SELDCAD', '>', DB::raw('CURRENT_DATE'))->orWhereNull('SELDCAD');
            })
            ->where(function ($query) {
                $query->where('SELDCAL', '>', DB::raw('CURRENT_DATE'))->orWhereNull('SELDCAL');
            })
            ->where(function ($query) {
                $query->where('SELDMAN', '>', DB::raw('CURRENT_DATE'))->orWhereNull('SELDMAN');
            })
            ->where(function ($query) {
                $query->where('SELDVER', '>', DB::raw('CURRENT_DATE'))->orWhereNull('SELDVER');
            })
            ->when($checkCantidad, function ($query) {
                $query->where('SELNCAE', '>', 0);
            })
            ->orderBy('PRD3DEL')
            ->orderBy('PRD3COD')
            ->orderBy('SEL1COD')
            ->first();
    } 
    
    /**
     * Actualiza las existencias para la serie o lote
     * 
     * @param string $productCode - Código del producto.
     * @param string $productDelegation - Delegación del producto. 
     * @param string $lot - Serie o lote. 
     * @param float $quantity - Cantidad a restar en las existencias.
     * @return void
     */
    public function updateStockLot(string $productCode, string $productDelegation, string $lot, float $quantity)
    {
        // Incrementa las existencias acumulando
        DB::table('ALMSEL')
            ->where('PRD3DEL', $productDelegation)
            ->where('PRD3COD', $productCode)
            ->where('SEL1COD', $lot)
            ->decrement('SELNCAE', $quantity);

        // Obtiene la nueva cantidad de existencias acumulada
        $newValues = DB::table('ALMSEL')
            ->where('PRD3DEL', $productDelegation)
            ->where('PRD3COD', $productCode)
            ->where('SEL1COD', $lot) 
            ->select('SELNCAE', 'SELNCAU') 
            ->first();
            
        // Calcula las existencias por unidad        
        DB::table('ALMSEL')
            ->where('PRD3DEL', $productDelegation)
            ->where('PRD3COD', $productCode)
            ->where('SEL1COD', $lot)
            ->update(['SELNUNE' => $this->calculateUnitsFromQuantity($newValues->SELNCAU, $newValues->SELNCAE)]);
    }    

    /**
     * Calcula el número de unidades basado en la cantidad total y las unidades por cantidad.
     *
     * @param float $unitsPerQuantity - La cantidad de unidades por una cantidad específica.
     * @param float $totalQuantity - La cantidad total de la que se calcularán las unidades.
     * @return float - El número de unidades calculadas, redondeado a 4 decimales.
     * @throws \Exception - Si ocurre un error durante el cálculo.
     */
    public function calculateUnitsFromQuantity(float $unitsPerQuantity, float $totalQuantity)
    {
        try {    
            if ($unitsPerQuantity > 0 && $totalQuantity > 0) {
                $remainder = ($totalQuantity % $unitsPerQuantity) ? 1 : 0;
                return round(floor($totalQuantity / $unitsPerQuantity), 4) + $remainder;
            }
        } catch (\Exception $e) {
            throw new \Exception("Error in calculateUnitsFromQuantity: " . $e->getMessage());
        }
    
        return 0.0;
    }    

    /**
     * Recalcula el stock de cada producto afectado en base a los consumos acumulados.
     * 
     * @param array $products - Arreglo de productos afectados.
     * @return void
     */
    public function recalculateAffectedProductStock(array $products)
    {
        foreach ($products as $product) {
            [$productDelegation, $productCode] = explode(self::SEPARATOR, $product);

            $total = DB::table('ALMSEL')
                ->selectRaw('SUM(SELNUNE) as units, SUM(SELNCAE) as quantity')
                ->where('PRD3DEL', $productDelegation)
                ->where('PRD3COD', $productCode)
                ->where('SELCESA', '<>', 'B')
                ->first();

            DB::table('ALMPRD')
                ->where('DEL3COD', $productDelegation)
                ->where('PRD1COD', $productCode)
                ->update([
                    'PRDNEXI' => $total->units,
                    'PRDNCAE' => $total->quantity
                ]);
        }
    }

    /**
     * Obtiene el primer analista asignado al parámetro
     * 
     * @param stdClass $parameter - Objeto que contiene la información del parámetro.
     * @return array - Delegación y código del analista o `['', 0]` si no se encuentra.
     */
    private function getParameterAnalyst(stdClass $parameter)
    {
        $data = DB::table('LABTYE')
            ->where('TEC3DEL' ,$parameter->DEL3COD)
            ->where('TEC3COD', $parameter->TEC1COD)
            ->orderBy('TYENPOS', 'asc')
            ->first();
        if ($data) {
            return [$data->EMP3DEL, $data->EMP3COD];
        } else {
            return ['', 0];
        }
    }

    /**
     * Obtiene el departamento correspondiente a la sección del parámetro.
     * 
     * @param stdClass $parameter - Objeto con los datos del parámetro.
     * @return array - Delegación y código del departamento o `['', 0]` si no se encuentra.
     */
    private function getDepartment(stdClass $parameter)
    {
        $data = DB::table('LABSEC')
            ->where('DEL3COD', $parameter->SEC2DEL)
            ->where('SEC1COD', $parameter->SEC2COD)
            ->first();
        if ($data) {
            return [$data->DEP2DEL, $data->DEP2COD];
        } else {
            return ['', 0];
        }
    }    

    /**
     * Lee de la configuración si los precios están basados en tarifas 
     * y si se debe asignar resultado predeterminado.
     * 
     * @return array - Un array con dos valores:
     *                1. Booleano que indica si los precios están basados en tarifas.
     *                2. Booleano que indica si se debe asignar un valor predeterminado.
     */
    public function getConfiguration()
    {
        $configuration = DB::table('LABCON')
            ->where('CON1COD', 1)
            ->first();

        if ($configuration) {
            return [
                $configuration->CONBTAR === 'T', 
                $configuration->CONBPRE === 'T'
            ];
        } else {
            return [false, false];            
        }
    }    

    /**
     * Lee la marca por defecto si existe.
     * 
     * @param string $delegation - La delegación para la que se busca la marca por defecto.
     * @return array - Delegación y código de marca.
     */
    public function getDefaultMark(string $delegation)
    {
        $marks = DB::table('LABMAR')
            ->where('DEL3COD', $delegation)
            ->where('MAR1COD', -1)
            ->first();

        if ($marks) {
            return [$delegation, $marks->MAR1COD];
        } else {
            return ['', 0];
        }
    }

    /**
     * Recupera la tarifa de la entrada o de la base de datos, según lo que esté disponible.
     * 
     * @param array $data - Datos de entrada que pueden contener información sobre la tarifa.
     * @param string $delegation - Delegación de la operación.
     * @param string $series - Serie de la operación.
     * @param int $code - Código de la operación.
     * @return array - Delegación y código de la tarifa
     */
    public function getRateData(array $data, string $delegation, string $series, int $code)
    {
        if (array_key_exists('tarifa_codigo', $data)) {
            return [$data['tarifa_codigo'], $data['tarifa_delegacion']];
        }
    
        $operationData = DB::table('LABOPE')
            ->where('DEL3COD', $delegation)
            ->where('OPE1SER', $series)
            ->where('OPE1COD', $code)
            ->first();

        if ($operationData) {
            return [$operationData->TAR2COD, $operationData->TAR2DEL];
        } else {
            return [0, ''];
        }        
    }    

    /**
     * Recupera el cliente de la entrada o de la base de datos, según lo que esté disponible.
     * 
     * @param array $data - Datos de entrada que pueden contener información sobre el cliente.
     * @param string $delegation - Delegación de la operación.
     * @param string $key1 - Clave 1 de la operación.
     * @param int $code - Código de la operación.
     * @return array - Delegación y código del cliente
     */
    public function getClientData(array $data, string $delegation, string $series, int $code)
    {
        if (array_key_exists('cliente_codigo', $data)) {
            return [$data['cliente_codigo'], $data['cliente_delegacion']];
        }
    
        $operationData = DB::table('LABOPE')
            ->where('DEL3COD', $delegation)
            ->where('OPE1SER', $series)
            ->where('OPE1COD', $code)
            ->first();
        if ($operationData) {        
            return [$operationData->CLI2COD, $operationData->CLI2DEL];
        } else {
            return ['', ''];
        }
    }

    /**
     * Obtiene la normativa para el parámetro de entrada.
     * 
     * @param object $parameter - Objeto que contiene los parámetros a consultar.
     * @param object $service - Objeto que contiene los detalles del servicio.
     * @return string - Valor de la normativa encontrada o el valor por defecto del parámetro.
     */     
    private function getParameterRegulation(stdClass $parameter, stdClass $service) 
    {
        $data = DB::table('LABTYN')
            ->where('TEC3DEL', $parameter->DEL3COD)
            ->where('TEC3COD', $parameter->TEC1COD)
            ->where('NOR3DEL', $service->NOR2DEL)
            ->where('NOR3COD', $service->NOR2COD)
            ->first();
        if ($data) {
            return ($data->TYNCVAL !== '') ? $data->TYNCVAL : $parameter->TECCNOR;
        } else {
            return ($parameter->TECCNOR);
        }
    }    

    /**
     * Recupera el precio y descuento por tarifa del parámetro de entrada.
     * 
     * @param stdClass $parameter - Información del parámetro.
     * @param string $rateDelegation - Delegación de la tarifa.
     * @param int $rateCode - Código de la tarifa.
     * @return array - Precio y descuento.
     */
    public function getParameterPriceAndDiscountByRate(stdClass $parameter, string $rateDelegation, int $rateCode)
    {
        $priceAndDiscount = DB::table('LABTYF')
            ->where('TEC3DEL', $parameter->DEL3COD)
            ->where('TEC3COD', $parameter->TEC1COD)          
            ->where('TAR3DEL', $rateDelegation)  
            ->where('TAR3COD', $rateCode)  
            ->first();
        if ($priceAndDiscount) {
            return [$priceAndDiscount->TYFNPRE ?? 0, $priceAndDiscount->TYFCDTO ?? ''];
        } else {
            return [$parameter->TECNPRE ?? 0, $parameter->TECCDTO ?? ''];
        }
    }    

    /**
     * Recupera el precio y descuento por cliente del parámetro de entrada.
     * 
     * @param stdClass $parameter - Información del parámetro.
     * @param string $clientDelegation - Delegación del cliente.
     * @param string $clientCode - Código del cliente.
     * @return array - Precio y descuento.
     */
    public function getParameterPriceAndDiscountByClient(stdClass $parameter, string $clientDelegation, string $clientCode)
    {
        $priceAndDiscount = DB::table('LABTYC')
            ->where('TEC3DEL', $parameter->DEL3COD)
            ->where('TEC3COD', $parameter->TEC1COD)          
            ->where('CLI3DEL', $clientDelegation)  
            ->where('CLI3COD', $clientCode)  
            ->first();
        if ($priceAndDiscount) {
            return [$priceAndDiscount->TYCNPRE ?? 0, $priceAndDiscount->TYCCDTO ?? ''];
        } else {
            return [$parameter->TECNPRE ?? 0, $parameter->TECCDTO ?? ''];
        }
    }     

    /**
     * Obtiene el número de orden del parámetro de entrada dentro del servicio
     * 
     * @param stdClass $parameter - Información del parámetro.
     * @param stdClass $service - Información del servicio.
     * @return int - Número de orden.
     */
    public function getParameterOrderNumberInService(stdClass $parameter, stdClass $service) 
    {
        $orderNumber = DB::table('LABSYT')
            ->where('DEL3SER', $service->DEL3COD)
            ->where('SER3COD', $service->SER1COD)
            ->where('DEL3TEC', $parameter->DEL3COD)
            ->where('TEC3COD', $parameter->TEC1COD)
            ->pluck('SYTNORD')
            ->first();
        return $orderNumber ?? 0;
    }  
}
