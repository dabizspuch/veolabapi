<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\BuildsBillingLines;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabBillingLines;
use App\Support\VeolabCodes;
use App\Support\VeolabInvoiceLines;
use App\Support\VeolabLicense;
use App\Support\VeolabOperationServices;
use Illuminate\Support\Facades\DB;

/**
 * Facturas (FACFAC) con sus líneas (FACLIF), sus operaciones (LABOPE.FAC2*) y
 * sus subsanaciones (FACSUB, solo lectura). Réplica de FichaFactura/Facturas.
 *
 * La API no emite facturas: las definitivas, las rectificativas, las
 * subsanaciones y el envío a la AEAT se hacen en Veolab.
 *
 *  - Borradores (serie BOR, si LABCON.CONBFAB): alta, modificación y borrado.
 *    Las líneas se indican ('lineas' o 'servicios'), se generan a partir de
 *    las 'operaciones' como en la facturación de Veolab o se copian del
 *    presupuesto o contrato que se convierte.
 *  - Facturas emitidas: solo se cambian enviada, cobrada, contabilizada,
 *    pendiente, vencimiento, fecha de pago y notas.
 *  - Registros V: alta de borrador ($ESPVER004, o conversión de presupuesto
 *    011 / de contrato 019) y modificación de borrador (008).
 */
class FacturaController extends BaseController
{
    use BuildsBillingLines;
    use ChecksVeolabReferences;

    protected string $table = 'FACFAC';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'FAC1SER',
        'codigo'     => 'FAC1COD',
    ];
    protected array $searchFields = ['FACCRAS', 'FACCNIF', 'FACCCON', 'FACCNOT'];

    // El código del borrador se genera aquí (serie BOR, sin múltiplo).
    protected bool $generatesCode = false;

    protected array $foreignKeys = [
        'cliente'             => 'string',
        'contrato'            => 'int',
        'presupuesto'         => 'int',
        'factura_rectificada' => 'int',
        'factura_anterior'    => 'int',
    ];

    protected array $mapping = [
        'delegacion'                    => 'DEL3COD',
        'serie'                         => 'FAC1SER',
        'codigo'                        => 'FAC1COD',
        'es_borrador'                   => 'FACBBOR',
        'serie_final'                   => 'FACCSER',
        'estado'                        => 'FACCEST',
        'fecha'                         => 'FACDFAC',
        'concepto'                      => 'FACCCON',
        'categoria'                     => 'FACCCAT',
        'notas'                         => 'FACCNOT',
        'es_enviada'                    => 'FACBENV',
        'es_cobrada'                    => 'FACBCOB',
        'es_contabilizada'              => 'FACBCON',
        'pendiente'                     => 'FACNPEN',
        'fecha_vencimiento'             => 'FACDPRV',
        'fecha_pago'                    => 'FACDPAG',
        'forma_pago'                    => 'FACCFOP',
        'numero_cuenta'                 => 'FACCNUC',
        'tipo_desglose'                 => 'FACCTID',
        'tipo_agrupacion'               => 'FACCTIA',
        'precios_modificados'           => 'FACBMOP',
        'lineas_modificadas'            => 'FACBRSM',
        'subtotal'                      => 'FACNSUB',
        'descuento'                     => 'FACCDTO',
        'base_imponible'                => 'FACNBAS',
        'tipo_impuesto_1'               => 'FACCTI1',
        'valor_impuesto_1'              => 'FACCII1',
        'importe_impuesto_1'            => 'FACNVI1',
        'tipo_impuesto_2'               => 'FACCTI2',
        'valor_impuesto_2'              => 'FACCII2',
        'importe_impuesto_2'            => 'FACNVI2',
        'suplidos'                      => 'FACNSUP',
        'total'                         => 'FACNTOT',
        'tipo_persona'                  => 'FACCTIP',
        'residencia'                    => 'FACCRED',
        'razon_social'                  => 'FACCRAS',
        'nombre'                        => 'FACCNOP',
        'apellido_1'                    => 'FACCAP1',
        'apellido_2'                    => 'FACCAP2',
        'direccion'                     => 'FACCDIR',
        'poblacion'                     => 'FACCPOB',
        'provincia'                     => 'FACCPRO',
        'codigo_postal'                 => 'FACCCOP',
        'pais'                          => 'FACCPAI',
        'nif'                           => 'FACCNIF',
        'oficina_contable'              => 'FACCOFI',
        'organo_gestor'                 => 'FACCORG',
        'unidad_tramitadora'            => 'FACCUNI',
        'emisor_tipo_persona'           => 'FACCETI',
        'emisor_residencia'             => 'FACCERE',
        'emisor_razon_social'           => 'FACCERA',
        'emisor_nombre'                 => 'FACCENO',
        'emisor_apellido_1'             => 'FACCEA1',
        'emisor_apellido_2'             => 'FACCEA2',
        'emisor_direccion'              => 'FACCEDI',
        'emisor_poblacion'              => 'FACCEPO',
        'emisor_provincia'              => 'FACCEPR',
        'emisor_codigo_postal'          => 'FACCECP',
        'emisor_pais'                   => 'FACCEPA',
        'emisor_nif'                    => 'FACCENI',
        'es_rectificativa'              => 'FACBREC',
        'metodo_correccion'             => 'FACNMEC',
        'motivo_rectificacion'          => 'FACNMOR',
        'fecha_inicio_periodo'          => 'FACDPRI',
        'fecha_fin_periodo'             => 'FACDPRF',
        'factura_rectificada_delegacion' => 'FAC2DEL',
        'factura_rectificada_serie'     => 'FAC2SER',
        'factura_rectificada_codigo'    => 'FAC2COD',
        'es_verifactu'                  => 'FACBVER',
        'huella'                        => 'FACCHAS',
        'huella_anterior'               => 'FACCHAA',
        'nif_emisor_anterior'           => 'FACCENA',
        'fecha_factura_anterior'        => 'FACDFAA',
        'factura_anterior_delegacion'   => 'FAC2DEA',
        'factura_anterior_serie'        => 'FAC2SEA',
        'factura_anterior_codigo'       => 'FAC2COA',
        'subsanacion_anterior'          => 'SUB2COA',
        'identificador_verifactu'       => 'FACCIDV',
        'fecha_registro'                => 'FACTREG',
        'fecha_envio_verifactu'         => 'FACDENV',
        'intentos_envio'                => 'FACNINT',
        'es_subsanable'                 => 'FACBSUB',
        'es_subsanada'                  => 'FACBSUA',
        'cliente_delegacion'            => 'CLI2DEL',
        'cliente_codigo'                => 'CLI2COD',
        'contrato_delegacion'           => 'CON2DEL',
        'contrato_serie'                => 'CON2SER',
        'contrato_codigo'               => 'CON2COD',
        'presupuesto_delegacion'        => 'PRE2DEL',
        'presupuesto_serie'             => 'PRE2SER',
        'presupuesto_codigo'            => 'PRE2COD',
    ];

    private const DRAFT_SERIES = 'BOR';

    /** Campos que se pueden cambiar en una factura emitida. */
    private const STATE_FIELDS = ['es_enviada', 'es_cobrada', 'es_contabilizada', 'pendiente', 'fecha_vencimiento', 'fecha_pago', 'notas'];

    private const DATES = ['fecha', 'fecha_vencimiento', 'fecha_pago'];

    /** Textos que Veolab guarda como '' cuando están vacíos. */
    private const EMPTY_AS_BLANK = ['descuento', 'tipo_impuesto_1', 'valor_impuesto_1', 'tipo_impuesto_2', 'valor_impuesto_2'];

    /** Importe o porcentaje en texto: se guarda tal cual (ver PresupuestoController). */
    private const AMOUNT = 'regex:/^\d+([.,]\d+)?\s?%?$/';

    protected function rules(): array
    {
        return [
            'delegacion'                       => 'nullable|string|max:10',
            'serie'                            => 'nullable|string|max:10',
            'codigo'                           => 'nullable|integer',
            'serie_final'                      => 'nullable|string|max:10',
            'fecha'                            => 'nullable|date',
            'concepto'                         => 'nullable|string|max:100',
            'categoria'                        => 'nullable|string|max:30',
            'notas'                            => 'nullable|string',
            'es_enviada'                       => 'sometimes|string|in:T,F',
            'es_cobrada'                       => 'sometimes|string|in:T,F',
            'es_contabilizada'                 => 'sometimes|string|in:T,F',
            'pendiente'                        => 'nullable|numeric|min:0|max:9999999999999.99',
            'fecha_vencimiento'                => 'nullable|date',
            'fecha_pago'                       => 'nullable|date',
            'forma_pago'                       => 'nullable|string|max:100',
            'numero_cuenta'                    => 'nullable|string|max:50',
            'tipo_desglose'                    => 'sometimes|string|in:S,T,N,O',
            'subtotal'                         => 'sometimes|numeric|min:0|max:9999999999999.99',
            'descuento'                        => ['nullable', 'string', 'max:15', self::AMOUNT],
            'tipo_impuesto_1'                  => 'nullable|string|max:50',
            'valor_impuesto_1'                 => ['nullable', 'string', 'max:15', self::AMOUNT],
            'tipo_impuesto_2'                  => 'nullable|string|max:50',
            'valor_impuesto_2'                 => ['nullable', 'string', 'max:15', self::AMOUNT],
            'tipo_persona'                     => 'nullable|string|in:F,J',
            'residencia'                       => 'nullable|string|in:E,R,U',
            'razon_social'                     => 'nullable|string|max:255',
            'nombre'                           => 'nullable|string|max:50',
            'apellido_1'                       => 'nullable|string|max:50',
            'apellido_2'                       => 'nullable|string|max:50',
            'direccion'                        => 'nullable|string|max:255',
            'poblacion'                        => 'nullable|string|max:100',
            'provincia'                        => 'nullable|string|max:100',
            'codigo_postal'                    => 'nullable|string|max:10',
            'pais'                             => 'nullable|string|max:3',
            'nif'                              => 'nullable|string|max:15',
            'oficina_contable'                 => 'nullable|string|max:10',
            'organo_gestor'                    => 'nullable|string|max:10',
            'unidad_tramitadora'               => 'nullable|string|max:10',
            'cliente_delegacion'               => 'nullable|string|max:10',
            'cliente_codigo'                   => 'nullable|string|max:15',
            'contrato_delegacion'              => 'nullable|string|max:10',
            'contrato_serie'                   => 'nullable|string|max:10',
            'contrato_codigo'                  => 'nullable|integer',
            'presupuesto_delegacion'           => 'nullable|string|max:10',
            'presupuesto_serie'                => 'nullable|string|max:10',
            'presupuesto_codigo'               => 'nullable|integer',
            'operaciones'                      => 'sometimes|array',
            'operaciones.*.delegacion'         => 'nullable|string|max:10',
            'operaciones.*.serie'              => 'nullable|string|max:10',
            'operaciones.*.codigo'             => 'required|integer|min:1',
            'lineas'                           => 'sometimes|array',
            'lineas.*.tipo'                    => 'sometimes|string|in:'.implode(',', VeolabBillingLines::TYPES),
            'lineas.*.fecha'                   => 'nullable|date',
            'lineas.*.referencia'              => 'nullable|string|max:50',
            'lineas.*.adicional'               => 'nullable|string|max:50',
            'lineas.*.descripcion'             => 'nullable|string|max:255',
            'lineas.*.cantidad'                => 'nullable|numeric|min:0|max:9999999999999.99999',
            'lineas.*.precio'                  => 'nullable|numeric|min:0|max:9999999999999.99999',
            'lineas.*.descuento'               => ['nullable', 'string', 'max:15', self::AMOUNT],
            'lineas.*.es_destacada'            => 'sometimes|string|in:T,F',
            'lineas.*.es_agrupada'             => 'sometimes|string|in:T,F',
            'lineas.*.servicio_delegacion'     => 'nullable|string|max:10',
            'lineas.*.servicio_codigo'         => 'nullable|string|max:20',
            'lineas.*.tecnica_delegacion'      => 'nullable|string|max:10',
            'lineas.*.tecnica_codigo'          => 'nullable|string|max:30',
            'lineas.*.gasto_delegacion'        => 'nullable|string|max:10',
            'lineas.*.gasto_codigo'            => 'nullable|integer',
            'lineas.*.operacion_delegacion'    => 'nullable|string|max:10',
            'lineas.*.operacion_serie'         => 'nullable|string|max:10',
            'lineas.*.operacion_codigo'        => 'nullable|integer',
            'lineas.*.cliente_delegacion'      => 'nullable|string|max:10',
            'lineas.*.cliente_codigo'          => 'nullable|string|max:15',
            'lineas.*.punto_muestreo_codigo'   => 'nullable|integer',
            'servicios'                        => 'sometimes|array|min:1',
            'servicios.*.delegacion'           => 'nullable|string|max:10',
            'servicios.*.codigo'               => 'required|string|max:20',
            'servicios.*.cantidad'             => 'nullable|numeric|min:0|max:9999999999999.99999',
            'servicios.*.punto_muestreo_codigo' => 'nullable|integer',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
        $this->checkServicesExist($data['servicios'] ?? []);

        if (array_key_exists('lineas', $data) && array_key_exists('servicios', $data)) {
            throw new BusinessRuleException("Indique 'lineas' o 'servicios', no ambos");
        }
        foreach ($data['operaciones'] ?? [] as $operation) {
            [$del, $ser, $cod] = self::operationKey($operation);
            $this->mustExist('LABOPE', ['DEL3COD' => $del, 'OPE1SER' => $ser, 'OPE1COD' => $cod], "La operación {$cod} no existe");
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = empty($keys);
        $current = null;
        $invoice = null;

        if (! $isNew) {
            $invoice = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
            $current = $db->table($this->table)
                ->where('DEL3COD', $invoice[0])->where('FAC1SER', $invoice[1])->where('FAC1COD', $invoice[2])->first();
            if ($current->FACBBOR !== 'T') {
                return $this->issuedInvoiceChanges($data, $current);
            }
        } else {
            if ($db->table('LABCON')->where('CON1COD', 1)->value('CONBFAB') !== 'T') {
                throw new BusinessRuleException('Los borradores de factura no están habilitados en Veolab (LABCON.CONBFAB)');
            }
            if (isset($data['codigo'])) {
                throw new BusinessRuleException('El código de un borrador se asigna automáticamente');
            }
            if (! in_array($data['serie'] ?? '', ['', self::DRAFT_SERIES], true)) {
                throw new BusinessRuleException("La API solo crea borradores (serie BOR); la serie de emisión va en 'serie_final'");
            }
        }
        if (($data['serie_final'] ?? null) === self::DRAFT_SERIES) {
            throw new BusinessRuleException('La serie BOR está reservada para los borradores');
        }

        $today = $db->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
        foreach (self::DATES as $param) {
            if (! empty($data[$param])) {
                $data[$param] = (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }
        foreach (self::EMPTY_AS_BLANK as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = (string) ($data[$param] ?? '');
            }
        }

        // Origen de las líneas y de las operaciones.
        $breakdownGiven = array_key_exists('tipo_desglose', $data);
        $operationsGiven = array_key_exists('operaciones', $data);
        $linesGiven = array_key_exists('lineas', $data) || array_key_exists('servicios', $data);
        $conversion = null;
        if ($isNew && ! $operationsGiven && ! $linesGiven) {
            if (! empty($data['contrato_codigo'])) {
                $conversion = 'contrato';
            } elseif (! empty($data['presupuesto_codigo'])) {
                $conversion = 'presupuesto';
            }
        }
        $source = $conversion ? $this->conversionSource($conversion, $data) : null;

        $operations = null;   // null: no cambian
        if ($operationsGiven) {
            $operations = self::uniqueKeys(array_map([self::class, 'operationKey'], $data['operaciones']));
        } elseif ($source) {
            $operations = $source['operations'];
        }
        if ($operations !== null) {
            $this->assertOperationsNotInvoiced($operations, $invoice);
        }
        unset($data['operaciones']);

        if ($isNew) {
            // Lo indicado en la petición prevalece sobre el documento convertido.
            if ($source) {
                $data += $source['data'];
            }
            // Valores por defecto de un borrador nuevo (FichaFactura).
            $data['delegacion'] = (string) ($data['delegacion'] ?? '');
            $data['serie'] = self::DRAFT_SERIES;
            $data['codigo'] = $this->draftCode($data['delegacion']);
            $data['es_borrador'] = 'T';
            $data['es_verifactu'] = 'F';
            $data['estado'] = 'B';
            $data['es_rectificativa'] = 'F';
            $data['es_enviada'] ??= 'F';
            $data['es_cobrada'] ??= 'F';
            $data['es_contabilizada'] ??= 'F';
            $data['metodo_correccion'] = 0;
            $data['motivo_rectificacion'] = 0;
            $data['precios_modificados'] ??= 'F';
            $data['lineas_modificadas'] = 'F';
            if (! array_key_exists('fecha', $data)) {
                $data['fecha'] = $today;
            }
            $data['tipo_desglose'] ??= $this->documentBreakdown($this->defaultBreakdown());
        }

        // Operaciones añadidas: cliente de facturación, presupuesto y contrato
        // de la primera (EstablecerClienteDeOperacion / ...PresupuestoContrato...).
        if ($operationsGiven && $operations) {
            $data = $this->defaultsFromOperation($data, $operations[0], $current);
        }

        $value = function (string $param) use (&$data, $current) {
            return array_key_exists($param, $data)
                ? $data[$param]
                : ($current ? $current->{$this->mapping[$param]} : null);
        };

        $clientCode = (string) ($value('cliente_codigo') ?? '');
        $clientDel = $clientCode === '' ? '' : (string) ($value('cliente_delegacion') ?? '');
        $clientChanged = $clientCode !== (string) ($current->CLI2COD ?? '')
            || ($clientCode !== '' && $clientDel !== (string) ($current->CLI2DEL ?? ''));
        if ($clientChanged && $clientCode !== '') {
            $data = $this->applyClientData($data, $clientDel, $clientCode, (string) ($value('fecha') ?? $today));
        }
        if ($isNew) {
            foreach (self::EMPTY_AS_BLANK as $param) {
                $data[$param] ??= '';
            }
        }
        $data = $this->regenerateName($data, $value);
        $data = $this->issuerData($data, (string) $value('delegacion'));

        // Rejilla.
        $ctx = VeolabBillingLines::context($clientDel, $clientCode, '', 0, false);
        $rebuild = $operations !== null && ! $linesGiven && ($source === null || $source['rebuild'])
            && ($isNew || $current->FACBRSM !== 'T');
        if ($source && ! $source['rebuild']) {
            $lines = $source['lines'];
            $gridChanged = true;
            $data['lineas_modificadas'] = 'T';
        } elseif ($rebuild) {
            [$lines, $opBreakdown] = VeolabInvoiceLines::fromOperations($operations, $ctx);
            // ReconstruirDetalles: el desglose de la primera operación (salvo que se indique).
            if ($opBreakdown !== '' && ! $breakdownGiven) {
                $data['tipo_desglose'] = $this->documentBreakdown($opBreakdown);
            }
            $gridChanged = true;
            $data['lineas_modificadas'] = 'F';
        } else {
            $lines = null;
        }
        $breakdown = $this->documentBreakdown((string) $value('tipo_desglose'));

        if ($lines !== null) {
            [$gridSubtotal, $gridSupplied] = VeolabBillingLines::compute($this->table, $lines, $breakdown, $ctx);
        } else {
            // FichaFactura regenera precios al cambiar de cliente salvo con precios por tarifa.
            [$lines, $gridChanged, $gridSubtotal, $gridSupplied] = $this->resolveLines(
                $data, $current, $invoice, $ctx, $breakdown, $clientChanged && ! $ctx->perTariff, false);
            if ($linesGiven) {
                $data['lineas_modificadas'] = 'T';
            }
        }
        unset($data['lineas'], $data['servicios']);

        // Subtotal: el de la rejilla; por operaciones, la suma de sus precios;
        // sin desglose, a mano; en una conversión, el del documento de origen.
        if (array_key_exists('subtotal', $data) && $breakdown !== 'N') {
            throw new BusinessRuleException("El subtotal solo se puede indicar sin desglose (tipo_desglose 'N')");
        }
        $currentOperations = $operations ?? ($isNew ? [] : $this->storedOperations($invoice));
        if (array_key_exists('subtotal', $data)) {
            $subtotal = (float) $data['subtotal'];
        } elseif ($source && isset($source['subtotal'])) {
            $subtotal = $source['subtotal'];
        } elseif ($breakdown === 'O') {
            $subtotal = VeolabInvoiceLines::operationsTotal($currentOperations);
        } else {
            $subtotal = $gridChanged ? $gridSubtotal : (float) $current->FACNSUB;
        }
        $supplied = (float) ($gridChanged ? $gridSupplied : $current->FACNSUP);

        $base = (float) ($current->FACNBAS ?? 0);
        $tax1 = (float) ($current->FACNVI1 ?? 0);
        $recalculate = $gridChanged || $operations !== null || $breakdown !== $this->documentBreakdown((string) ($current->FACCTID ?? ''))
            || array_intersect(['subtotal', 'descuento', 'valor_impuesto_1', 'valor_impuesto_2'], array_keys($data));
        if ($recalculate) {
            // CalcularTotalesFactura.
            $base = VeolabOperationServices::withDiscount($subtotal, (string) $value('descuento'));
            $tax1 = VeolabBillingLines::tax($base, (string) $value('valor_impuesto_1'));
            $tax2 = VeolabBillingLines::tax($base, (string) $value('valor_impuesto_2'));
            $total = round(round($base + $tax1, 2) - $tax2 + $supplied, 2);

            $data['subtotal'] = self::amount($subtotal);
            $data['base_imponible'] = self::amount($base);
            $data['importe_impuesto_1'] = self::amount($tax1);
            $data['importe_impuesto_2'] = self::amount($tax2);
            $data['suplidos'] = self::amount($supplied);
            $data['total'] = self::amount($total);

            // El pendiente sigue al total mientras no se haya tocado ni esté cobrada.
            $previousTotal = (float) ($current->FACNTOT ?? 0);
            $pending = (float) ($current->FACNPEN ?? 0);
            if (! array_key_exists('pendiente', $data) && $value('es_cobrada') !== 'T'
                && ($isNew || $pending == 0 || $pending == $previousTotal)) {
                $data['pendiente'] = self::amount($total);
            }
        }
        $data = $this->paidRules($data, $current);

        $contract = $value('contrato_codigo') ? [(string) $value('contrato_delegacion'), (string) $value('contrato_serie'), (int) $value('contrato_codigo')] : null;
        if ($isNew && $contract) {
            $this->assertContractBillable($contract);
        }

        $data['_lineas'] = $gridChanged ? $lines : null;
        $data['_operaciones'] = $operations;
        $data['_nuevo'] = $isNew;
        $data['_contrato'] = $isNew ? $contract : null;
        // Registro V: conversión de presupuesto o contrato, borrador nuevo o modificado.
        [$message, $previous] = match (true) {
            ! $isNew                   => ['$ESPVER008', ''],
            $conversion === 'presupuesto' => ['$ESPVER011', (string) $data['presupuesto_codigo']],
            $conversion === 'contrato' => ['$ESPVER019', (string) $data['contrato_codigo']],
            default                    => ['$ESPVER004', ''],
        };
        $data['_verifactu'] = [$message,
            ($clientCode === '' ? '' : VeolabCodes::format('SINCLI', $clientCode, $clientDel)).' '.number_format(round($base + $tax1, 2), 2, ',', '.'),
            $previous];

        return $data;
    }

    // ------------------------------------------------------------------
    // Reglas de la ficha
    // ------------------------------------------------------------------

    /** Factura emitida: solo los campos de estado; sin registro V (Veolab no lo genera). */
    private function issuedInvoiceChanges(array $data, object $current): array
    {
        $other = array_diff(array_keys($data), self::STATE_FIELDS, array_keys($this->keys));
        if ($other) {
            throw new BusinessRuleException('La factura está emitida: solo se puede cambiar '.implode(', ', self::STATE_FIELDS));
        }
        foreach (['fecha_vencimiento', 'fecha_pago'] as $param) {
            if (! empty($data[$param])) {
                $data[$param] = (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        return $this->paidRules($data, $current) + [
            '_lineas' => null, '_operaciones' => null, '_nuevo' => false, '_contrato' => null, '_verifactu' => null,
        ];
    }

    /**
     * chkCobrada_Click: al marcarla cobrada el pendiente pasa a cero y, si no
     * tenía, la fecha de pago es hoy.
     */
    private function paidRules(array $data, ?object $current): array
    {
        // GEN_Decimal: un pendiente vacío se guarda como 0.
        if (array_key_exists('pendiente', $data)) {
            $data['pendiente'] = self::amount((float) ($data['pendiente'] ?? 0));
        }
        if (($data['es_cobrada'] ?? null) !== 'T' || ($current && $current->FACBCOB === 'T')) {
            return $data;
        }
        if (! array_key_exists('pendiente', $data)) {
            $data['pendiente'] = self::amount(0);
        }
        if (! array_key_exists('fecha_pago', $data) && empty($current->FACDPAG ?? null)) {
            $data['fecha_pago'] = DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
        }

        return $data;
    }

    /**
     * Conversión de presupuesto o contrato en borrador (LeerRegistro con
     * strCodigoPresupuesto / strCodigoContrato): cliente, desglose, subtotal,
     * líneas y operaciones facturables del documento.
     */
    private function conversionSource(string $type, array $data): array
    {
        $db = DB::connection('dynamic');
        $key = [(string) ($data["{$type}_delegacion"] ?? ''), (string) ($data["{$type}_serie"] ?? ''), (int) $data["{$type}_codigo"]];

        if ($type === 'presupuesto') {
            $doc = $db->table('FACPRE')->where('DEL3COD', $key[0])->where('PRE1SER', $key[1])->where('PRE1COD', $key[2])->first();
            $header = [
                'tipo_desglose'    => $this->documentBreakdown((string) $doc->PRECTID),
                'descuento'        => (string) $doc->PRECDTO,
                'tipo_impuesto_1'  => (string) $doc->PRECTI1,
                'valor_impuesto_1' => (string) $doc->PRECII1,
                'tipo_impuesto_2'  => (string) $doc->PRECTI2,
                'valor_impuesto_2' => (string) $doc->PRECII2,
            ];
            $subtotal = (float) $doc->PRENSUB;
            $rebuild = false;
            $fromState = 5;
            $fk = 'PRE2';
        } else {
            $doc = $db->table('FACCON')->where('DEL3COD', $key[0])->where('CON1SER', $key[1])->where('CON1COD', $key[2])->first();
            $header = [
                'tipo_desglose' => $this->documentBreakdown((string) $doc->CONCTID),
                'concepto'      => (string) $doc->CONCCON,
            ];
            $subtotal = (float) $doc->CONNPRE;
            // Contrato por importe de las operaciones: la rejilla sale de ellas.
            $rebuild = $doc->CONCFIM === 'O';
            $fromState = (int) $db->table('LABCON')->where('CON1COD', 1)->value('CONNESC');
            $fk = 'CON2';
            if (! empty($doc->PRE2COD)) {
                $header += ['presupuesto_delegacion' => (string) $doc->PRE2DEL, 'presupuesto_serie' => (string) $doc->PRE2SER,
                    'presupuesto_codigo' => (int) $doc->PRE2COD];
            }
        }
        if ((string) $doc->CLI2COD !== '') {
            $header += ['cliente_delegacion' => (string) $doc->CLI2DEL, 'cliente_codigo' => (string) $doc->CLI2COD];
        }
        $header['precios_modificados'] = ($type === 'presupuesto' ? $doc->PREBMOP : $doc->CONBMOP) === 'T' ? 'T' : 'F';

        // Operaciones facturables del documento (ConsultaBDOperaciones).
        $operations = $db->table('LABOPE')
            ->where("{$fk}DEL", $key[0])->where("{$fk}SER", $key[1])->where("{$fk}COD", $key[2])
            ->where(fn ($q) => $q->whereNull('OPEBFAC')->orWhere('OPEBFAC', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('OPEBPRE')->orWhere('OPEBPRE', '<>', 'T'))
            ->where('OPEBFAB', 'T')->where('OPENEST', '>=', $fromState)->where('OPENEST', '<=', 6)
            ->orderBy('DEL3COD')->orderBy('OPE1SER')->orderBy('OPE1COD')
            ->get(['DEL3COD', 'OPE1SER', 'OPE1COD'])
            ->map(fn ($r) => [(string) $r->DEL3COD, (string) $r->OPE1SER, (int) $r->OPE1COD])->all();

        $lines = [];
        if (! $rebuild) {
            $lines = VeolabBillingLines::stored($type === 'presupuesto' ? 'FACPRE' : 'FACCON', $key);
            foreach ($lines as &$line) {
                // Las líneas del presupuesto con punto de muestreo llevan su cliente.
                if ($line['point'] > 0) {
                    $line['lineClientDel'] = (string) $doc->CLI2DEL;
                    $line['lineClientCod'] = (string) $doc->CLI2COD;
                }
            }
            unset($line);
        }

        return [
            'data'       => $header,
            'lines'      => $lines,
            'operations' => $operations,
            'rebuild'    => $rebuild,
            'subtotal'   => $rebuild ? null : $subtotal,
        ];
    }

    /** Operaciones añadidas: cliente, presupuesto y contrato de la primera si no se indican. */
    private function defaultsFromOperation(array $data, array $operation, ?object $current): array
    {
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = $operation;
        $op = $db->table('LABOPE')->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod)->first();

        // FAC_ObtenClienteFacturacion: el principal si el cliente factura a su principal.
        $hasClient = array_key_exists('cliente_codigo', $data) ? ! empty($data['cliente_codigo']) : (string) ($current->CLI2COD ?? '') !== '';
        if (! $hasClient && (string) $op->CLI2COD !== '') {
            $client = $db->table('SINCLI')->where('DEL3COD', $op->CLI2DEL)->where('CLI1COD', $op->CLI2COD)
                ->first(['CLICMDF', 'CLI2DEL', 'CLI2COD']);
            [$data['cliente_delegacion'], $data['cliente_codigo']] = $client && $client->CLICMDF === 'P'
                ? [(string) $client->CLI2DEL, (string) $client->CLI2COD]
                : [(string) $op->CLI2DEL, (string) $op->CLI2COD];
        }
        if (! array_key_exists('presupuesto_codigo', $data) && (int) $op->PRE2COD > 0) {
            [$data['presupuesto_delegacion'], $data['presupuesto_serie'], $data['presupuesto_codigo']] =
                [(string) $op->PRE2DEL, (string) $op->PRE2SER, (int) $op->PRE2COD];
        }
        if (! array_key_exists('contrato_codigo', $data) && (int) $op->CON2COD > 0) {
            [$data['contrato_delegacion'], $data['contrato_serie'], $data['contrato_codigo']] =
                [(string) $op->CON2DEL, (string) $op->CON2SER, (int) $op->CON2COD];
        }

        return $data;
    }

    /**
     * LeerInformacionCliente: al elegir cliente, lo que no se indique toma
     * sus datos de facturación (con Verifactu, persona jurídica, residente y
     * España por defecto).
     */
    private function applyClientData(array $data, string $clientDel, string $clientCode, string $date): array
    {
        $client = DB::connection('dynamic')->table('SINCLI')->where('DEL3COD', $clientDel)->where('CLI1COD', $clientCode)->first();
        if (! $client) {
            return $data;
        }
        $verifactu = VeolabLicense::isVerifactu('dynamic', DB::connection('dynamic')->getDatabaseName());
        $address = $client->CLIBDF3 === 'T' ? '3' : ($client->CLIBDF2 === 'T' ? '2' : '1');

        $defaults = [
            'tipo_persona'       => (string) $client->CLICTIP ?: ($verifactu ? 'J' : ''),
            'residencia'         => (string) $client->CLICRES ?: ($verifactu ? 'R' : ''),
            'oficina_contable'   => (string) $client->CLICOFI,
            'organo_gestor'      => (string) $client->CLICORG,
            'unidad_tramitadora' => (string) $client->CLICUNI,
            'nif'                => (string) $client->CLICNIF,
            'razon_social'       => (string) $client->CLICRAS,
            'nombre'             => (string) $client->CLICNOP,
            'apellido_1'         => (string) $client->CLICAP1,
            'apellido_2'         => (string) $client->CLICAP2,
            'numero_cuenta'      => (string) $client->CLICNUC,
            'forma_pago'         => (string) $client->CLICFOP,
            'descuento'          => (string) $client->CLICDTO,
            'tipo_impuesto_1'    => (string) $client->CLICTI1,
            'valor_impuesto_1'   => (string) $client->CLICII1,
            'tipo_impuesto_2'    => (string) $client->CLICTI2,
            'valor_impuesto_2'   => (string) $client->CLICII2,
            'direccion'          => (string) $client->{"CLICDI{$address}"},
            'provincia'          => (string) $client->{"CLICPR{$address}"},
            'poblacion'          => (string) $client->{"CLICPO{$address}"},
            'codigo_postal'      => (string) $client->{"CLICCO{$address}"},
            'pais'               => trim((string) $client->{"CLICPA{$address}"}) ?: ($verifactu ? 'ESP' : ''),
        ];
        if ((string) $client->CLICOBF !== '') {
            $defaults['notas'] = (string) $client->CLICOBF;
        }
        foreach ($defaults as $param => $default) {
            if (! array_key_exists($param, $data)) {
                $data[$param] = $default;
            }
        }

        if (! array_key_exists('fecha_vencimiento', $data)) {
            $days = (int) $client->CLINDVF;
            $data['fecha_vencimiento'] = $days > 0 ? self::dueDate($date, $days, (int) $client->CLINDIP) : null;
        }

        return $data;
    }

    /**
     * FAC_CalcularFechaVencimento: fecha + días; con día de pago, ese día del
     * mes (del siguiente si el día de la factura ya lo ha pasado).
     */
    private static function dueDate(string $date, int $days, int $payDay): string
    {
        $invoiceDate = new \DateTime($date);
        $due = (clone $invoiceDate)->modify("+{$days} days");
        if ($payDay > 0) {
            $year = (int) $due->format('Y');
            $month = (int) $due->format('n');
            if ((int) $invoiceDate->format('j') > $payDay) {
                $month++;
            }
            // DateSerial: los desbordamientos pasan al mes siguiente, como mktime.
            $due = (new \DateTime())->setTimestamp(mktime(0, 0, 0, $month, $payDay, $year));
        }

        return $due->format('Y-m-d 00:00:00');
    }

    /** RegenerarRazon: en persona física la razón social son nombre y apellidos. */
    private function regenerateName(array $data, \Closure $value): array
    {
        $touched = array_intersect(['nombre', 'apellido_1', 'apellido_2', 'tipo_persona'], array_keys($data));
        if ($touched && $value('tipo_persona') === 'F' && ! array_key_exists('razon_social', $data)) {
            $data['razon_social'] = $value('nombre').' '.$value('apellido_1').' '.$value('apellido_2');
        }

        return $data;
    }

    /** Datos del emisor: los de la delegación de la factura o, sin ella, los de la central. */
    private function issuerData(array $data, string $delegation): array
    {
        $db = DB::connection('dynamic');
        $issuer = $delegation !== '' ? $delegation : (string) $db->table('ACCPAR')->value('PARCCDC');
        $row = $db->table('ACCDEL')->where('DEL1COD', $issuer)->first();
        if ($row) {
            $data += [
                'emisor_tipo_persona'  => (string) $row->DELCTIP,
                'emisor_residencia'    => (string) $row->DELCRES,
                'emisor_razon_social'  => (string) $row->DELCRAS,
                'emisor_nombre'        => (string) $row->DELCNOP,
                'emisor_apellido_1'    => (string) $row->DELCAP1,
                'emisor_apellido_2'    => (string) $row->DELCAP2,
                'emisor_direccion'     => (string) $row->DELCDIR,
                'emisor_poblacion'     => (string) $row->DELCPOB,
                'emisor_provincia'     => (string) $row->DELCPRO,
                'emisor_codigo_postal' => (string) $row->DELCCOP,
                'emisor_pais'          => (string) $row->DELCPAI,
                'emisor_nif'           => (string) $row->DELCNIF,
            ];
        }

        return $data;
    }

    /** AlgunaOperacionFacturada: ninguna operación puede estar en otra factura. */
    private function assertOperationsNotInvoiced(array $operations, ?array $invoice): void
    {
        foreach ($operations as [$del, $ser, $cod]) {
            $row = DB::connection('dynamic')->table('LABOPE')->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod)
                ->first(['FAC2DEL', 'FAC2SER', 'FAC2COD']);
            if ($row && (int) $row->FAC2COD > 0
                && [(string) $row->FAC2DEL, (string) $row->FAC2SER, (int) $row->FAC2COD] !== $invoice) {
                throw new BusinessRuleException("La operación {$cod} ya está en otra factura");
            }
        }
    }

    /**
     * FAC_ActualizarFacturacionContrato calcula la próxima facturación con la
     * periodicidad del contrato, que la API no replica: un contrato periódico
     * se factura desde Veolab.
     */
    private function assertContractBillable(array $contract): void
    {
        $frequency = (int) DB::connection('dynamic')->table('FACCON')
            ->where('DEL3COD', $contract[0])->where('CON1SER', $contract[1])->where('CON1COD', $contract[2])->value('CONNFRE');
        if ($frequency > 0) {
            throw new BusinessRuleException('El contrato tiene periodicidad de facturación: facture desde Veolab');
        }
    }

    /** Código del borrador: contador de la serie BOR, sin múltiplo, hasta uno libre. */
    private function draftCode(string $delegation): int
    {
        for ($attempt = 0; $attempt < 10000; $attempt++) {
            $code = VeolabCodes::next($this->table, self::DRAFT_SERIES, $delegation);
            $exists = DB::connection('dynamic')->table($this->table)
                ->where('DEL3COD', $delegation)->where('FAC1SER', self::DRAFT_SERIES)->where('FAC1COD', $code)->exists();
            if (! $exists) {
                return $code;
            }
        }

        throw new BusinessRuleException('No se ha podido generar un código libre');
    }

    // ------------------------------------------------------------------
    // Grabación
    // ------------------------------------------------------------------

    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        $invoice = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $row = $this->auditRow($keys);

        if ($data['_lineas'] !== null) {
            VeolabBillingLines::save($this->table, $invoice, $data['_lineas']);
            if (! $data['_nuevo']) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'FACLIF');
            }
        }

        if ($data['_operaciones'] !== null) {
            $this->linkOperations($invoice, $data['_operaciones']);
            if (! $data['_nuevo']) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'LABOPE');
            }
        }

        // FAC_ActualizarFacturacionContrato (contrato sin periodicidad).
        if ($data['_contrato']) {
            [$del, $ser, $cod] = $data['_contrato'];
            $db->table('FACCON')->where('DEL3COD', $del)->where('CON1SER', $ser)->where('CON1COD', $cod)
                ->update(['CONDULF' => $data['fecha'] ?? null, 'CONNFAC' => DB::raw('COALESCE(CONNFAC, 0) + 1')]);
        }

        if ($data['_verifactu'] !== null) {
            [$message, $detail, $previous] = $data['_verifactu'];
            VeolabAudit::verifactu($this->table, $row, $message, $detail, $previous);
        }

        return $data;
    }

    /**
     * Grabar, "Operaciones": las anteriores vuelven a estar disponibles (y se
     * desarchivan si hay modo de archivo) y las nuevas quedan prefacturadas en
     * el borrador. Los borradores no archivan operaciones.
     */
    private function linkOperations(array $invoice, array $operations): void
    {
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = $invoice;

        if ((string) $db->table('LABCON')->where('CON1COD', 1)->value('CONCARC') !== '') {
            $this->unarchiveOperations($invoice);
        }
        $db->table('LABOPE')->where('FAC2DEL', $del)->where('FAC2SER', $ser)->where('FAC2COD', $cod)
            ->update(['FAC2DEL' => '', 'FAC2SER' => '', 'FAC2COD' => 0, 'OPEBFAC' => 'F', 'OPEBPRE' => 'F']);

        foreach ($operations as [$opDel, $opSer, $opCod]) {
            $db->table('LABOPE')->where('DEL3COD', $opDel)->where('OPE1SER', $opSer)->where('OPE1COD', $opCod)
                ->update(['FAC2DEL' => $del, 'FAC2SER' => $ser, 'FAC2COD' => $cod, 'OPEBPRE' => 'T', 'OPEBFAC' => 'F', 'OPEBFAB' => 'T']);
        }
    }

    /** Operaciones archivadas por la factura: vuelven a validadas (5) o enviadas (6). */
    private function unarchiveOperations(array $invoice): void
    {
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = $invoice;
        $ofInvoice = fn () => $db->table('LABOPE')->where('FAC2DEL', $del)->where('FAC2SER', $ser)->where('FAC2COD', $cod)->where('OPENEST', 7);

        $ofInvoice()->whereNull('OPEDENV')->update(['OPENEST' => 5]);
        $ofInvoice()->whereNotNull('OPEDENV')->update(['OPENEST' => 6]);
    }

    private function storedOperations(array $invoice): array
    {
        return DB::connection('dynamic')->table('LABOPE')
            ->where('FAC2DEL', $invoice[0])->where('FAC2SER', $invoice[1])->where('FAC2COD', $invoice[2])
            ->orderBy('DEL3COD')->orderBy('OPE1SER')->orderBy('OPE1COD')
            ->get(['DEL3COD', 'OPE1SER', 'OPE1COD'])
            ->map(fn ($r) => [(string) $r->DEL3COD, (string) $r->OPE1SER, (int) $r->OPE1COD])->all();
    }

    /** Cada factura del listado lleva sus líneas, operaciones y subsanaciones. */
    protected function appendRelatedData(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $keys = array_map(fn ($row) => [(string) $row['delegacion'], (string) $row['serie'], (int) $row['codigo']], $rows);
        $lines = VeolabBillingLines::read($this->table, $keys);
        $ofInvoices = function ($q, array $columns) use ($keys) {
            foreach ($keys as $key) {
                $q->orWhere(fn ($w) => $w->where(array_combine($columns, $key)));
            }
        };

        $operations = [];
        foreach ($db->table('LABOPE')->where(fn ($q) => $ofInvoices($q, ['FAC2DEL', 'FAC2SER', 'FAC2COD']))
            ->orderBy('DEL3COD')->orderBy('OPE1SER')->orderBy('OPE1COD')
            ->get(['DEL3COD', 'OPE1SER', 'OPE1COD', 'FAC2DEL', 'FAC2SER', 'FAC2COD']) as $r) {
            $operations[$r->FAC2DEL."\x1B".$r->FAC2SER."\x1B".$r->FAC2COD][] =
                ['delegacion' => (string) $r->DEL3COD, 'serie' => (string) $r->OPE1SER, 'codigo' => (int) $r->OPE1COD];
        }

        $corrections = [];
        foreach ($db->table('FACSUB')->where(fn ($q) => $ofInvoices($q, ['FAC3DEL', 'FAC3SER', 'FAC3COD']))
            ->orderBy('SUB1COD')
            ->get(['FAC3DEL', 'FAC3SER', 'FAC3COD', 'SUB1COD', 'SUBTREG', 'SUBCTIP', 'SUBCEST', 'SUBCIDV', 'SUBCHAS', 'SUBCNIF', 'SUBCRAS']) as $r) {
            $corrections[$r->FAC3DEL."\x1B".$r->FAC3SER."\x1B".$r->FAC3COD][] = [
                'codigo'                  => (int) $r->SUB1COD,
                'fecha_registro'          => $r->SUBTREG,
                'tipo'                    => $r->SUBCTIP,
                'estado'                  => $r->SUBCEST,
                'identificador_verifactu' => $r->SUBCIDV,
                'huella'                  => $r->SUBCHAS,
                'nif'                     => $r->SUBCNIF,
                'razon_social'            => $r->SUBCRAS,
            ];
        }

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".$row['serie']."\x1B".$row['codigo'];
            $row['lineas'] = $lines[$key] ?? [];
            $row['operaciones'] = $operations[$key] ?? [];
            $row['subsanaciones'] = $corrections[$key] ?? [];
        }

        return $rows;
    }

    /** Facturas.Eliminar: desde la API solo se borran borradores. */
    protected function validateBeforeDelete(array $keys): void
    {
        $draft = DB::connection('dynamic')->table($this->table)
            ->where('DEL3COD', (string) $keys['delegacion'])->where('FAC1SER', (string) $keys['serie'])
            ->where('FAC1COD', (int) $keys['codigo'])->value('FACBBOR');
        if ($draft !== 'T') {
            throw new BusinessRuleException('Desde la API solo se pueden eliminar borradores');
        }
    }

    /**
     * Cascada de Facturas.Eliminar: líneas; las operaciones se desarchivan y
     * vuelven a ser facturables; documentos a la papelera. (Veolab no registra
     * aquí el suceso V "Eliminación de factura borrador".)
     */
    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        $invoice = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        [$del, $ser, $cod] = $invoice;

        VeolabBillingLines::delete($this->table, $invoice);
        $this->unarchiveOperations($invoice);
        $db->table('LABOPE')->where('FAC2DEL', $del)->where('FAC2SER', $ser)->where('FAC2COD', $cod)
            ->update(['FAC2DEL' => '', 'FAC2SER' => '', 'FAC2COD' => 0, 'OPEBFAC' => 'F', 'OPEBPRE' => 'F']);
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('FAC2SER', $ser)->where('FAC2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);
    }

    /** Desglose de la factura: por servicio, técnica, operaciones o sin desglose. */
    protected function documentBreakdown(string $value): string
    {
        return in_array($value, ['S', 'T', 'O'], true) ? $value : 'N';
    }

    private static function amount(float $value): string
    {
        return number_format($value, 5, '.', '');
    }

    private static function operationKey(array $operation): array
    {
        return [(string) ($operation['delegacion'] ?? ''), (string) ($operation['serie'] ?? ''), (int) $operation['codigo']];
    }

    /** Claves sin repetir, conservando el orden. */
    private static function uniqueKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[implode("\x1B", $key)] ??= $key;
        }

        return array_values($out);
    }
}
