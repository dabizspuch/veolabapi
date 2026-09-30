<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\BuildsBillingLines;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabBillingLines;
use App\Support\VeolabCodes;
use App\Support\VeolabLicense;
use Illuminate\Support\Facades\DB;

/**
 * Contratos (FACCON) con sus líneas (FACLIC). Réplica de
 * FichaContrato/Contratos de Veolab:
 *
 *  - Líneas como en los presupuestos ('lineas' o 'servicios'; ver
 *    App\Support\VeolabBillingLines). Las técnicas llevan siempre la marca
 *    de acreditación (la ficha las añade como en un informe acreditado).
 *  - Precio del contrato: el de la rejilla (sin suplidos); sin desglose se
 *    puede indicar a mano.
 *  - Periodicidad de facturación: de solo lectura (se configura en Veolab);
 *    las fechas de última y próxima facturación sí se pueden cambiar.
 *  - Contrato predeterminado: al marcarlo, los demás del cliente dejan de serlo.
 *  - Verifactu: no se borran contratos; alta, modificación y borrado dejan un
 *    registro V encadenado.
 */
class ContratoController extends BaseController
{
    use BuildsBillingLines;
    use ChecksVeolabReferences;

    protected string $table = 'FACCON';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'CON1SER',
        'codigo'     => 'CON1COD',
    ];
    protected array $searchFields = ['CONCDES', 'CONCCON', 'CONCOBS'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    protected array $foreignKeys = [
        'cliente'     => 'string',
        'presupuesto' => 'int',
        'tarifa'      => 'int',
    ];

    protected array $mapping = [
        'delegacion'                   => 'DEL3COD',
        'serie'                        => 'CON1SER',
        'codigo'                       => 'CON1COD',
        'descripcion'                  => 'CONCDES',
        'observaciones'                => 'CONCOBS',
        'concepto_facturacion'         => 'CONCCON',
        'fecha_inicio'                 => 'CONDINI',
        'fecha_fin'                    => 'CONDFIN',
        'fecha_ultima_facturacion'     => 'CONDULF',
        'fecha_proxima_facturacion'    => 'CONDPRF',
        'tipo_desglose'                => 'CONCTID',
        'es_cancelado'                 => 'CONBCAN',
        'es_archivado'                 => 'CONBARC',
        'es_predeterminado'            => 'CONBPRE',
        'facturar_operaciones'         => 'CONBFOP',
        'importe_facturacion'          => 'CONCFIM',
        'precios_modificados'          => 'CONBMOP',
        'precio'                       => 'CONNPRE',
        'renovacion'                   => 'CONNREN',
        'renovacion_unidad'            => 'CONCREN',
        'numero_facturacion'           => 'CONNFAC',
        'periodicidad'                 => 'CONNFRE',
        'periodicidad_opcion'          => 'CONNOPC',
        'periodicidad_repetir'         => 'CONNREP',
        'periodicidad_ordinal'         => 'CONNORD',
        'periodicidad_dia_semana'      => 'CONNSEM',
        'periodicidad_fecha_inicio'    => 'CONDINP',
        'periodicidad_fecha_fin'       => 'CONDFIP',
        'periodicidad_repeticiones'    => 'CONNINR',
        'periodicidad_laborable'       => 'CONBLAB',
        'cliente_delegacion'           => 'CLI2DEL',
        'cliente_codigo'               => 'CLI2COD',
        'presupuesto_delegacion'       => 'PRE2DEL',
        'presupuesto_serie'            => 'PRE2SER',
        'presupuesto_codigo'           => 'PRE2COD',
        'tarifa_delegacion'            => 'TAR2DEL',
        'tarifa_codigo'                => 'TAR2COD',
    ];

    private const DATES = ['fecha_inicio', 'fecha_fin', 'fecha_ultima_facturacion', 'fecha_proxima_facturacion'];

    /** Importe o porcentaje en texto: se guarda tal cual (ver PresupuestoController). */
    private const AMOUNT = 'regex:/^\d+([.,]\d+)?\s?%?$/';

    /**
     * Solo lectura: precios_modificados, numero_facturacion y periodicidad_*;
     * precio salvo sin desglose.
     */
    protected function rules(): array
    {
        return [
            'delegacion'                     => 'nullable|string|max:10',
            'serie'                          => 'nullable|string|max:10',
            'codigo'                         => 'nullable|integer|min:1',
            'descripcion'                    => 'sometimes|string|max:100',
            'observaciones'                  => 'nullable|string',
            'concepto_facturacion'           => 'nullable|string|max:100',
            'fecha_inicio'                   => 'nullable|date',
            'fecha_fin'                      => 'nullable|date',
            'fecha_ultima_facturacion'       => 'nullable|date',
            'fecha_proxima_facturacion'      => 'nullable|date',
            'tipo_desglose'                  => 'sometimes|string|in:S,T,N',
            'es_cancelado'                   => 'sometimes|string|in:T,F',
            'es_archivado'                   => 'sometimes|string|in:T,F',
            'es_predeterminado'              => 'sometimes|string|in:T,F',
            'facturar_operaciones'           => 'sometimes|string|in:T,F',
            'importe_facturacion'            => 'sometimes|string|in:C,O',
            'precio'                         => 'sometimes|numeric|min:0|max:9999999999999.99',
            'renovacion'                     => 'sometimes|integer|min:0',
            'renovacion_unidad'              => 'sometimes|string|in:D,S,M,A',
            'cliente_delegacion'             => 'nullable|string|max:10',
            'cliente_codigo'                 => 'nullable|string|max:15',
            'presupuesto_delegacion'         => 'nullable|string|max:10',
            'presupuesto_serie'              => 'nullable|string|max:10',
            'presupuesto_codigo'             => 'nullable|integer',
            'tarifa_delegacion'              => 'nullable|string|max:10',
            'tarifa_codigo'                  => 'nullable|integer',
            'lineas'                         => 'sometimes|array',
            'lineas.*.tipo'                  => 'sometimes|string|in:'.implode(',', VeolabBillingLines::TYPES),
            'lineas.*.referencia'            => 'nullable|string|max:50',
            'lineas.*.descripcion'           => 'nullable|string|max:255',
            'lineas.*.cantidad'              => 'nullable|numeric|min:0|max:9999999999999.99999',
            'lineas.*.precio'                => 'nullable|numeric|min:0|max:9999999999999.99999',
            'lineas.*.descuento'             => ['nullable', 'string', 'max:15', self::AMOUNT],
            'lineas.*.es_destacada'          => 'sometimes|string|in:T,F',
            'lineas.*.es_agrupada'           => 'sometimes|string|in:T,F',
            'lineas.*.servicio_delegacion'   => 'nullable|string|max:10',
            'lineas.*.servicio_codigo'       => 'nullable|string|max:20',
            'lineas.*.tecnica_delegacion'    => 'nullable|string|max:10',
            'lineas.*.tecnica_codigo'        => 'nullable|string|max:30',
            'lineas.*.gasto_delegacion'      => 'nullable|string|max:10',
            'lineas.*.gasto_codigo'          => 'nullable|integer',
            'servicios'                      => 'sometimes|array|min:1',
            'servicios.*.delegacion'         => 'nullable|string|max:10',
            'servicios.*.codigo'             => 'required|string|max:20',
            'servicios.*.cantidad'           => 'nullable|numeric|min:0|max:9999999999999.99999',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
        $this->checkServicesExist($data['servicios'] ?? []);

        if (array_key_exists('lineas', $data) && array_key_exists('servicios', $data)) {
            throw new BusinessRuleException("Indique 'lineas' o 'servicios', no ambos");
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = empty($keys);
        $current = null;
        $contract = null;

        if (! $isNew) {
            $contract = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
            $current = $db->table($this->table)
                ->where('DEL3COD', $contract[0])->where('CON1SER', $contract[1])->where('CON1COD', $contract[2])->first();
        }

        // CamposValidos: la descripción es obligatoria.
        if (($isNew || array_key_exists('descripcion', $data)) && trim((string) ($data['descripcion'] ?? '')) === '') {
            throw new BusinessRuleException('La descripción es obligatoria');
        }

        foreach (self::DATES as $param) {
            // La ficha guarda estas fechas sin hora.
            if (! empty($data[$param])) {
                $data[$param] = (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        if ($isNew) {
            // Valores por defecto de la ficha de un contrato nuevo.
            $data['es_cancelado'] ??= 'F';
            $data['es_archivado'] ??= 'F';
            $data['es_predeterminado'] ??= 'F';
            $data['facturar_operaciones'] ??= 'F';
            $data['importe_facturacion'] ??= 'C';
            $data['renovacion'] ??= 0;
            $data['renovacion_unidad'] ??= 'A';
            $data['tipo_desglose'] ??= $this->documentBreakdown($this->defaultBreakdown());
            $data['precios_modificados'] = 'F';
        }

        // Valor vigente de un campo: el de la petición o el guardado.
        $value = function (string $param) use (&$data, $current) {
            return array_key_exists($param, $data)
                ? $data[$param]
                : ($current ? $current->{$this->mapping[$param]} : null);
        };

        $clientCode = (string) ($value('cliente_codigo') ?? '');
        $clientDel = $clientCode === '' ? '' : (string) ($value('cliente_delegacion') ?? '');
        $clientChanged = $clientCode !== (string) ($current->CLI2COD ?? '')
            || ($clientCode !== '' && $clientDel !== (string) ($current->CLI2DEL ?? ''));

        // CargarTarifaCliente: al elegir cliente, su tarifa (si no se indica otra).
        if ($clientChanged && $clientCode !== '' && ! array_key_exists('tarifa_codigo', $data)) {
            $rate = $db->table('SINCLI')->where('DEL3COD', $clientDel)->where('CLI1COD', $clientCode)->first(['TAR2DEL', 'TAR2COD']);
            if ($rate && (int) $rate->TAR2COD > 0) {
                $data['tarifa_delegacion'] = (string) $rate->TAR2DEL;
                $data['tarifa_codigo'] = (int) $rate->TAR2COD;
            }
        }

        $tariffCode = (int) ($value('tarifa_codigo') ?? 0);
        $tariffDel = $tariffCode === 0 ? '' : (string) ($value('tarifa_delegacion') ?? '');
        $tariffChanged = $tariffCode !== (int) ($current->TAR2COD ?? 0)
            || ($tariffCode !== 0 && $tariffDel !== (string) ($current->TAR2DEL ?? ''));
        $breakdown = $this->documentBreakdown((string) $value('tipo_desglose'));
        $ctx = VeolabBillingLines::context($clientDel, $clientCode, $tariffDel, $tariffCode, true);

        [$lines, $gridChanged, $gridSubtotal] = $this->resolveLines(
            $data, $current, $contract, $ctx, $breakdown, $clientChanged, $tariffChanged);

        // Precio (CalcularSubtotalContrato): el de la rejilla; sin desglose, a mano.
        if (array_key_exists('precio', $data) && $breakdown !== 'N') {
            throw new BusinessRuleException("El precio solo se puede indicar sin desglose (tipo_desglose 'N')");
        }
        if (array_key_exists('precio', $data) || $gridChanged) {
            $data['precio'] = number_format((float) ($data['precio'] ?? $gridSubtotal), 5, '.', '');
        }
        $price = (float) ($data['precio'] ?? $current->CONNPRE ?? 0);

        $data['_lineas'] = $gridChanged ? $lines : null;
        $data['_nuevo'] = $isNew;
        $data['_cliente'] = [$clientDel, $clientCode];
        $data['_predeterminado'] = $value('es_predeterminado') === 'T';
        // Detalle del registro V: cliente y precio, como Veolab.
        $data['_verifactu'] = ($clientCode === '' ? '' : VeolabCodes::format('SINCLI', $clientCode, $clientDel))
            .' '.number_format($price, 2, ',', '.');

        return $data;
    }

    /** Tras crear/modificar: líneas, contrato predeterminado y registro de facturación. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        $contract = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];

        if ($data['_lineas'] !== null) {
            VeolabBillingLines::save($this->table, $contract, $data['_lineas']);
            if (! $data['_nuevo']) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $this->auditRow($keys), 'FACLIC');
            }
        }

        // Grabar: si es el predeterminado, los demás del cliente dejan de serlo.
        if ($data['_predeterminado']) {
            [$clientDel, $clientCode] = $data['_cliente'];
            $db->table($this->table)
                ->where('CLI2DEL', $clientDel)->where('CLI2COD', $clientCode)
                ->where(fn ($q) => $q->where('DEL3COD', '<>', $contract[0])
                    ->orWhere('CON1SER', '<>', $contract[1])->orWhere('CON1COD', '<>', $contract[2]))
                ->update(['CONBPRE' => 'F']);
        }

        // $ESPVER017 "Nuevo contrato" / $ESPVER018 "Modificación de contrato".
        // Veolab identifica aquí el contrato sin la serie.
        VeolabAudit::verifactu($this->table, VeolabCodes::format($this->table, (string) $contract[2], $contract[0]),
            $data['_nuevo'] ? '$ESPVER017' : '$ESPVER018', $data['_verifactu']);

        return $data;
    }

    /** Cada contrato del listado lleva sus líneas. */
    protected function appendRelatedData(array $rows): array
    {
        $lines = VeolabBillingLines::read($this->table, array_map(
            fn ($row) => [(string) $row['delegacion'], (string) $row['serie'], (int) $row['codigo']], $rows
        ));

        foreach ($rows as &$row) {
            $row['lineas'] = $lines[$row['delegacion']."\x1B".$row['serie']."\x1B".$row['codigo']] ?? [];
        }

        return $rows;
    }

    /** Contratos.BorradoFisicoPermitido; con Verifactu el borrado está deshabilitado. */
    protected function validateBeforeDelete(array $keys): void
    {
        if (VeolabLicense::isVerifactu('dynamic', DB::connection('dynamic')->getDatabaseName())) {
            throw new BusinessRuleException('Con Verifactu no se pueden eliminar contratos');
        }

        $references = [
            'LABOPE' => 'operaciones',
            'LABPLO' => 'planificaciones',
            'FACFAC' => 'facturas',
        ];
        foreach ($references as $table => $name) {
            $used = DB::connection('dynamic')->table($table)
                ->where('CON2DEL', (string) $keys['delegacion'])->where('CON2SER', (string) $keys['serie'])
                ->where('CON2COD', (int) $keys['codigo'])->exists();
            if ($used) {
                throw new BusinessRuleException("El contrato tiene {$name} vinculadas y no se puede eliminar");
            }
        }
    }

    /** Cascada de Contratos.Borrar: líneas y documentos a la papelera. */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];

        VeolabBillingLines::delete($this->table, [$del, $ser, $cod]);
        DB::connection('dynamic')->table('DOCFAT')->where('DEL3COD', $del)->where('CON2SER', $ser)->where('CON2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);

        // $ESPVER016 "Eliminación de contrato".
        VeolabAudit::verifactu($this->table, $this->auditRow($keys), '$ESPVER016');
    }
}
