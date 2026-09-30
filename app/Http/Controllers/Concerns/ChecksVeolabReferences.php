<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Comprobaciones compartidas por operaciones, planificaciones, lotes, órdenes
 * e informes: existencia de las referencias, tarifa por defecto del cliente
 * y desglose por defecto. Solo se comprueban los grupos que existan
 * en el $mapping del controlador.
 */
trait ChecksVeolabReferences
{
    /** Grupo => [tabla, columna de código, mensaje] (clave delegación + código). */
    private static function simpleReferences(): array
    {
        return [
            'tipo_operacion'      => ['LABTIO', 'TIO1COD', 'El tipo de operación no existe'],
            'matriz'              => ['LABMAT', 'MAT1COD', 'La matriz no existe'],
            'equipamiento'        => ['LABEQU', 'EQU1COD', 'El equipamiento no existe'],
            'cliente'             => ['SINCLI', 'CLI1COD', 'El cliente no existe'],
            'empleado_recolector' => ['GRHEMP', 'EMP1COD', 'El empleado recolector no existe'],
            'empleado_comercial'  => ['GRHEMP', 'EMP1COD', 'El empleado comercial no existe'],
            'planificacion'       => ['LABPLO', 'PLO1COD', 'La planificación no existe'],
            'dictamen'            => ['LABDIC', 'DIC1COD', 'El dictamen no existe'],
            'tarifa'              => ['LABTAR', 'TAR1COD', 'La tarifa no existe'],
            'proveedor'           => ['SINPRO', 'PRO1COD', 'El proveedor no existe'],
            'producto'            => ['ALMPRD', 'PRD1COD', 'El producto no existe'],
            'tecnica'             => ['LABTEC', 'TEC1COD', 'La técnica no existe'],
            'departamento'        => ['GRHDEP', 'DEP1COD', 'El departamento no existe'],
            'forma_envio'         => ['LABFDE', 'FDE1COD', 'La forma de envío no existe'],
            'normativa'           => ['LABNOR', 'NOR1COD', 'La normativa no existe'],
            'usuario_validacion'  => ['ACCUSU', 'USU1COD', 'El usuario que valida no existe'],
        ];
    }

    /** Grupo => [tabla, columna de serie, columna de código, mensaje]. */
    private static function seriesReferences(): array
    {
        return [
            'contrato'          => ['FACCON', 'CON1SER', 'CON1COD', 'El contrato no existe'],
            'presupuesto'       => ['FACPRE', 'PRE1SER', 'PRE1COD', 'El presupuesto no existe'],
            'lote'              => ['LABLOT', 'LOT1SER', 'LOT1COD', 'El lote no existe'],
            'lote_relacionado'  => ['LABLOT', 'LOT1SER', 'LOT1COD', 'El lote relacionado no existe'],
            'operacion_control' => ['LABOPE', 'OPE1SER', 'OPE1COD', 'La operación de control no existe'],
        ];
    }

    protected function checkReferences(array $data): void
    {
        $del = fn (string $group) => (string) ($data["{$group}_delegacion"] ?? '');
        $ser = fn (string $group) => (string) ($data["{$group}_serie"] ?? '');
        $has = fn (string $param) => isset($this->mapping[$param]) && ! empty($data[$param]);

        if (! empty($data['delegacion'])) {
            $this->mustExist('ACCDEL', ['DEL1COD' => $data['delegacion']], 'La delegación no existe');
        }

        foreach (self::simpleReferences() as $group => [$table, $codeColumn, $message]) {
            if ($has("{$group}_codigo")) {
                $this->mustExist($table, ['DEL3COD' => $del($group), $codeColumn => $data["{$group}_codigo"]], $message);
            }
        }

        foreach (self::seriesReferences() as $group => [$table, $seriesColumn, $codeColumn, $message]) {
            if ($has("{$group}_codigo")) {
                $this->mustExist($table, [
                    'DEL3COD'     => $del($group),
                    $seriesColumn => $ser($group),
                    $codeColumn   => $data["{$group}_codigo"],
                ], $message);
            }
        }

        // Punto de muestreo: cuelga del cliente.
        if ($has('punto_muestreo_codigo')) {
            if (empty($data['cliente_codigo'])) {
                throw new BusinessRuleException('El punto de muestreo requiere indicar el cliente');
            }
            $this->mustExist('LABPUM', [
                'DEL3COD' => $del('cliente'),
                'CLI3COD' => $data['cliente_codigo'],
                'PUM1COD' => $data['punto_muestreo_codigo'],
            ], 'El punto de muestreo no existe');
        }

        // Fecha de planificación: cuelga de la planificación.
        if ($has('planificacion_fecha_codigo')) {
            if (empty($data['planificacion_codigo'])) {
                throw new BusinessRuleException('La fecha de planificación requiere indicar la planificación');
            }
            $this->mustExist('LABFEP', [
                'PLO3DEL' => $del('planificacion'),
                'PLO3COD' => $data['planificacion_codigo'],
                'FEP1COD' => $data['planificacion_fecha_codigo'],
            ], 'La fecha de planificación no existe');
        }

        // Serie o lote del producto: cuelga del producto.
        if ($has('producto_serie_lote_codigo')) {
            if (empty($data['producto_codigo'])) {
                throw new BusinessRuleException('La serie o lote requiere indicar el producto');
            }
            $this->mustExist('ALMSEL', [
                'PRD3DEL' => $del('producto'),
                'PRD3COD' => $data['producto_codigo'],
                'SEL1COD' => $data['producto_serie_lote_codigo'],
            ], 'La serie o lote del producto no existe');
        }
    }

    /** Sin tarifa indicada, la del cliente (CargarTarifaCliente). */
    protected function applyClientTariff(array $data): array
    {
        if (empty($data['tarifa_codigo']) && ! empty($data['cliente_codigo'])) {
            $clientRate = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', (string) ($data['cliente_delegacion'] ?? ''))
                ->where('CLI1COD', $data['cliente_codigo'])
                ->first(['TAR2DEL', 'TAR2COD']);
            if ($clientRate && (int) $clientRate->TAR2COD > 0) {
                $data['tarifa_delegacion'] = (string) $clientRate->TAR2DEL;
                $data['tarifa_codigo'] = (int) $clientRate->TAR2COD;
            }
        }

        return $data;
    }

    /** Desglose predeterminado (LABCON.CONCTID; si no es válido, por servicio). */
    protected function defaultBreakdown(): string
    {
        $value = DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONCTID');

        return in_array($value, ['S', 'T', 'N', 'O'], true) ? $value : 'S';
    }

    /** Servicios indicados: existen. */
    protected function checkServicesExist(array $services): void
    {
        foreach ($services as $service) {
            $this->mustExist('LABSER', [
                'DEL3COD' => (string) ($service['delegacion'] ?? ''),
                'SER1COD' => $service['codigo'],
            ], "El servicio {$service['codigo']} no existe");
        }
    }

    protected function mustExist(string $table, array $where, string $message): void
    {
        if (! DB::connection('dynamic')->table($table)->where($where)->exists()) {
            throw new BusinessRuleException($message);
        }
    }
}
