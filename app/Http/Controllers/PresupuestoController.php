<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\BuildsBillingLines;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabBillingLines;
use App\Support\VeolabCodes;
use App\Support\VeolabLicense;
use App\Support\VeolabOperationServices;
use Illuminate\Support\Facades\DB;

/**
 * Presupuestos (FACPRE) con sus líneas (FACLIP). Réplica de
 * FichaPresupuesto/Presupuestos de Veolab:
 *
 *  - Líneas: 'lineas' (la rejilla completa, línea a línea) o 'servicios'
 *    (cada servicio con sus técnicas y gastos, como "añadir servicio"). En
 *    PUT sustituyen la rejilla entera. Ver App\Support\VeolabBillingLines.
 *  - Importes (base, impuestos, suplidos, total) siempre calculados. El
 *    subtotal sale de la rejilla; sin desglose (N) se puede indicar a mano.
 *  - Al elegir cliente se toman su descuento, impuestos, tarifa y días de
 *    vencimiento; al cambiar de cliente o de tarifa se regeneran los precios
 *    de la rejilla salvo que se hubieran modificado a mano.
 *  - Estado enviado / aceptado apunta la fecha de entrega / aceptación.
 *  - Verifactu: no se borra ningún presupuesto ni se modifica el que tiene
 *    factura; alta, modificación y borrado dejan un registro V encadenado.
 */
class PresupuestoController extends BaseController
{
    use BuildsBillingLines;
    use ChecksVeolabReferences;

    protected string $table = 'FACPRE';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'PRE1SER',
        'codigo'     => 'PRE1COD',
    ];
    protected array $searchFields = ['PRECDES', 'PRECORC', 'PRECSOL', 'PRECOBS'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    protected array $foreignKeys = [
        'cliente'            => 'string',
        'empleado_comercial' => 'int',
        'tarifa'             => 'int',
    ];

    protected array $mapping = [
        'delegacion'                    => 'DEL3COD',
        'serie'                         => 'PRE1SER',
        'codigo'                        => 'PRE1COD',
        'descripcion'                   => 'PRECDES',
        'informacion_adicional'         => 'PRECADI',
        'orden_compra'                  => 'PRECORC',
        'solicitado_por'                => 'PRECSOL',
        'observaciones'                 => 'PRECOBS',
        'lugar'                         => 'PRECLUG',
        'horario'                       => 'PRECHOR',
        'recogida'                      => 'PRECREC',
        'facturacion'                   => 'PRECFAC',
        'notas'                         => 'PRECNOT',
        'fecha'                         => 'PREDFEC',
        'fecha_vencimiento'             => 'PREDVEN',
        'fecha_entrega'                 => 'PREDENT',
        'fecha_aceptacion'              => 'PREDACE',
        'estado'                        => 'PRECEST',
        'es_acreditado'                 => 'PREBACR',
        'es_archivado'                  => 'PREBARC',
        'tipo_desglose'                 => 'PRECTID',
        'precios_modificados'           => 'PREBMOP',
        'subtotal'                      => 'PRENSUB',
        'descuento'                     => 'PRECDTO',
        'base_imponible'                => 'PRENBAS',
        'tipo_impuesto_1'               => 'PRECTI1',
        'valor_impuesto_1'              => 'PRECII1',
        'importe_impuesto_1'            => 'PRENVI1',
        'tipo_impuesto_2'               => 'PRECTI2',
        'valor_impuesto_2'              => 'PRECII2',
        'importe_impuesto_2'            => 'PRENVI2',
        'suplidos'                      => 'PRENSUP',
        'total'                         => 'PRENTOT',
        'cliente_delegacion'            => 'CLI2DEL',
        'cliente_codigo'                => 'CLI2COD',
        'empleado_comercial_delegacion' => 'EMP2DEL',
        'empleado_comercial_codigo'     => 'EMP2COD',
        'tarifa_delegacion'             => 'TAR2DEL',
        'tarifa_codigo'                 => 'TAR2COD',
    ];

    /**
     * Importe o porcentaje: "10", "10,5", "21%". Es texto: se acepta con coma
     * o punto y se guarda con el separador decimal del laboratorio
     * (VeolabBillingLines::localized), que es como lo lee Veolab.
     */
    private const AMOUNT = 'regex:/^\d+([.,]\d+)?\s?%?$/';

    private const DATES = ['fecha', 'fecha_vencimiento', 'fecha_entrega', 'fecha_aceptacion'];

    /** Textos que Veolab guarda como '' cuando están vacíos. */
    private const EMPTY_AS_BLANK = ['descuento', 'tipo_impuesto_1', 'valor_impuesto_1', 'tipo_impuesto_2', 'valor_impuesto_2'];

    /**
     * Solo lectura (calculados): base_imponible, importe_impuesto_1/2,
     * suplidos, total y precios_modificados; subtotal salvo sin desglose.
     */
    protected function rules(): array
    {
        return [
            'delegacion'                     => 'nullable|string|max:10',
            'serie'                          => 'nullable|string|max:10',
            'codigo'                         => 'nullable|integer|min:1',
            'descripcion'                    => 'nullable|string|max:100',
            'informacion_adicional'          => 'nullable|string|max:50',
            'orden_compra'                   => 'nullable|string|max:20',
            'solicitado_por'                 => 'nullable|string|max:50',
            'observaciones'                  => 'nullable|string|max:255',
            'lugar'                          => 'nullable|string|max:255',
            'horario'                        => 'nullable|string|max:255',
            'recogida'                       => 'nullable|string|max:255',
            'facturacion'                    => 'nullable|string|max:255',
            'notas'                          => 'nullable|string',
            'fecha'                          => 'nullable|date',
            'fecha_vencimiento'              => 'nullable|date',
            'fecha_entrega'                  => 'nullable|date',
            'fecha_aceptacion'               => 'nullable|date',
            'estado'                         => 'sometimes|string|in:P,E,A,R,V,C',
            'es_acreditado'                  => 'sometimes|string|in:T,F',
            'es_archivado'                   => 'sometimes|string|in:T,F',
            'tipo_desglose'                  => 'sometimes|string|in:S,T,N',
            'subtotal'                       => 'sometimes|numeric|min:0|max:9999999999999.99',
            'descuento'                      => ['nullable', 'string', 'max:15', self::AMOUNT],
            'tipo_impuesto_1'                => 'nullable|string|max:10',
            'valor_impuesto_1'               => ['nullable', 'string', 'max:10', self::AMOUNT],
            'tipo_impuesto_2'                => 'nullable|string|max:10',
            'valor_impuesto_2'               => ['nullable', 'string', 'max:10', self::AMOUNT],
            'cliente_delegacion'             => 'nullable|string|max:10',
            'cliente_codigo'                 => 'nullable|string|max:15',
            'empleado_comercial_delegacion'  => 'nullable|string|max:10',
            'empleado_comercial_codigo'      => 'nullable|integer',
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
            'lineas.*.punto_muestreo_codigo' => 'nullable|integer',
            'servicios'                      => 'sometimes|array|min:1',
            'servicios.*.delegacion'         => 'nullable|string|max:10',
            'servicios.*.codigo'             => 'required|string|max:20',
            'servicios.*.cantidad'           => 'nullable|numeric|min:0|max:9999999999999.99999',
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
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = empty($keys);
        $current = null;
        $budget = null;
        $archiveOnly = array_values(array_diff(array_keys($data), array_keys($this->keys))) === ['es_archivado'];

        if (! $isNew) {
            $budget = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
            $current = $db->table($this->table)
                ->where('DEL3COD', $budget[0])->where('PRE1SER', $budget[1])->where('PRE1COD', $budget[2])->first();
            // EstadoControles: con Verifactu, el presupuesto facturado es de solo
            // lectura (archivar se hace desde el listado y sigue permitido).
            if (! $archiveOnly && $this->verifactu() && $this->hasInvoice($budget)) {
                throw new BusinessRuleException('El presupuesto tiene una factura: no se puede modificar');
            }
        }

        $today = $db->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
        foreach (self::DATES as $param) {
            // La ficha guarda estas fechas sin hora.
            if (! empty($data[$param])) {
                $data[$param] = (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }
        foreach (self::EMPTY_AS_BLANK as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = (string) ($data[$param] ?? '');
            }
        }

        if ($isNew) {
            // Valores por defecto de un presupuesto nuevo.
            $data['estado'] ??= 'P';
            $data['es_acreditado'] ??= 'F';
            $data['es_archivado'] ??= 'F';
            $data['tipo_desglose'] ??= $this->documentBreakdown($this->defaultBreakdown());
            $data['precios_modificados'] = 'F';
            if (! array_key_exists('fecha', $data)) {
                $data['fecha'] = $today;
            }
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
        if ($clientChanged && $clientCode !== '') {
            $data = $this->applyClientData($data, $clientDel, $clientCode, (string) ($value('fecha') ?? $today));
        }
        if ($isNew) {
            foreach (self::EMPTY_AS_BLANK as $param) {
                $data[$param] ??= '';
            }
        }
        foreach (['descuento', 'valor_impuesto_1', 'valor_impuesto_2'] as $param) {
            if (isset($data[$param])) {
                $data[$param] = VeolabBillingLines::localized((string) $data[$param]);
            }
        }

        // ActualizarFechasEstado: enviado / aceptado apuntan su fecha.
        $state = $data['estado'] ?? null;
        if ($state !== null && ($isNew || $state !== (string) $current->PRECEST)) {
            if ($state === 'E' && empty($value('fecha_entrega'))) {
                $data['fecha_entrega'] = $today;
            }
            if ($state === 'A' && empty($value('fecha_aceptacion'))) {
                $data['fecha_aceptacion'] = $today;
            }
        }

        // Rejilla: la indicada o la guardada, con los precios que correspondan.
        $tariffCode = (int) ($value('tarifa_codigo') ?? 0);
        $tariffDel = $tariffCode === 0 ? '' : (string) ($value('tarifa_delegacion') ?? '');
        $tariffChanged = $tariffCode !== (int) ($current->TAR2COD ?? 0)
            || ($tariffCode !== 0 && $tariffDel !== (string) ($current->TAR2DEL ?? ''));
        $breakdown = $this->documentBreakdown((string) $value('tipo_desglose'));
        $ctx = VeolabBillingLines::context($clientDel, $clientCode, $tariffDel, $tariffCode, $value('es_acreditado') === 'T');

        [$lines, $gridChanged, $gridSubtotal, $gridSupplied] = $this->resolveLines(
            $data, $current, $budget, $ctx, $breakdown, $clientChanged, $tariffChanged);

        // Subtotal: el de la rejilla; sin desglose se puede indicar a mano.
        if (array_key_exists('subtotal', $data) && $breakdown !== 'N') {
            throw new BusinessRuleException("El subtotal solo se puede indicar sin desglose (tipo_desglose 'N')");
        }
        $subtotal = (float) ($data['subtotal'] ?? ($gridChanged ? $gridSubtotal : $current->PRENSUB));
        $supplied = (float) ($gridChanged ? $gridSupplied : $current->PRENSUP);

        $base = (float) ($current->PRENBAS ?? 0);
        $tax1 = (float) ($current->PRENVI1 ?? 0);
        if ($gridChanged || array_intersect(['subtotal', 'descuento', 'valor_impuesto_1', 'valor_impuesto_2'], array_keys($data))) {
            // CalcularTotalesPresupuesto.
            $base = VeolabOperationServices::withDiscount($subtotal, (string) $value('descuento'));
            $tax1 = VeolabBillingLines::tax($base, (string) $value('valor_impuesto_1'));
            $tax2 = VeolabBillingLines::tax($base, (string) $value('valor_impuesto_2'));

            $data['subtotal'] = self::amount($subtotal);
            $data['base_imponible'] = self::amount($base);
            $data['importe_impuesto_1'] = self::amount($tax1);
            $data['importe_impuesto_2'] = self::amount($tax2);
            $data['suplidos'] = self::amount($supplied);
            $data['total'] = self::amount(round($base + $tax1 - $tax2 + $supplied, 2));
        }

        $data['_lineas'] = $gridChanged ? $lines : null;
        $data['_nuevo'] = $isNew;
        // Detalle del registro V: cliente e importe (base + impuesto 1), como Veolab.
        $data['_verifactu'] = $archiveOnly && ! $isNew ? null
            : ($clientCode === '' ? '' : VeolabCodes::format('SINCLI', $clientCode, $clientDel))
                .' '.number_format(round($base + $tax1, 2), 2, ',', '.');

        return $data;
    }

    /**
     * LeerDatosCliente + CargarTarifaCliente: al elegir cliente, lo que no se
     * indique en la petición toma su descuento, sus impuestos, su tarifa y
     * el vencimiento (fecha + días de vencimiento de presupuestos).
     */
    private function applyClientData(array $data, string $clientDel, string $clientCode, string $date): array
    {
        $client = DB::connection('dynamic')->table('SINCLI')
            ->where('DEL3COD', $clientDel)->where('CLI1COD', $clientCode)
            ->first(['CLICDTO', 'CLICTI1', 'CLICII1', 'CLICTI2', 'CLICII2', 'CLINDVP', 'TAR2DEL', 'TAR2COD']);
        if (! $client) {
            return $data;
        }

        $defaults = [
            'descuento'        => (string) $client->CLICDTO,
            'tipo_impuesto_1'  => (string) $client->CLICTI1,
            'valor_impuesto_1' => (string) $client->CLICII1,
            'tipo_impuesto_2'  => (string) $client->CLICTI2,
            'valor_impuesto_2' => (string) $client->CLICII2,
        ];
        foreach ($defaults as $param => $default) {
            if (! array_key_exists($param, $data)) {
                $data[$param] = $default;
            }
        }

        if (! array_key_exists('fecha_vencimiento', $data)) {
            $days = (int) $client->CLINDVP;
            $data['fecha_vencimiento'] = $days > 0
                ? (new \DateTime($date))->modify("+{$days} days")->format('Y-m-d 00:00:00')
                : null;
        }

        if (! array_key_exists('tarifa_codigo', $data) && (int) $client->TAR2COD > 0) {
            $data['tarifa_delegacion'] = (string) $client->TAR2DEL;
            $data['tarifa_codigo'] = (int) $client->TAR2COD;
        }

        return $data;
    }

    /** Importe con los decimales de la columna (para no auditar cambios que no lo son). */
    private static function amount(float $value): string
    {
        return number_format($value, 5, '.', '');
    }

    /** Tras crear/modificar: líneas y registro de facturación. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $budget = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $row = $this->auditRow($keys);

        if ($data['_lineas'] !== null) {
            VeolabBillingLines::save($this->table, $budget, $data['_lineas']);
            if (! $data['_nuevo']) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'FACLIP');
            }
        }

        // $ESPVER003 "Nuevo presupuesto" / $ESPVER007 "Modificación de presupuesto".
        if ($data['_verifactu'] !== null) {
            VeolabAudit::verifactu($this->table, $row, $data['_nuevo'] ? '$ESPVER003' : '$ESPVER007', $data['_verifactu']);
        }

        return $data;
    }

    /** Cada presupuesto del listado lleva sus líneas. */
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

    /**
     * Presupuestos.BorradoFisicoPermitido; con Verifactu el borrado está
     * deshabilitado.
     */
    protected function validateBeforeDelete(array $keys): void
    {
        if ($this->verifactu()) {
            throw new BusinessRuleException('Con Verifactu no se pueden eliminar presupuestos');
        }

        $references = [
            'LABOPE' => 'operaciones',
            'LABPLO' => 'planificaciones',
            'FACFAC' => 'facturas',
            'FACCON' => 'contratos',
        ];
        foreach ($references as $table => $name) {
            $used = DB::connection('dynamic')->table($table)
                ->where('PRE2DEL', (string) $keys['delegacion'])->where('PRE2SER', (string) $keys['serie'])
                ->where('PRE2COD', (int) $keys['codigo'])->exists();
            if ($used) {
                throw new BusinessRuleException("El presupuesto tiene {$name} vinculados y no se puede eliminar");
            }
        }
    }

    /** Cascada de Presupuestos.Borrar: líneas y documentos a la papelera. */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];

        VeolabBillingLines::delete($this->table, [$del, $ser, $cod]);
        DB::connection('dynamic')->table('DOCFAT')->where('DEL3COD', $del)->where('PRE2SER', $ser)->where('PRE2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);

        // $ESPVER009 "Eliminación de presupuesto".
        VeolabAudit::verifactu($this->table, $this->auditRow($keys), '$ESPVER009');
    }

    private function verifactu(): bool
    {
        return VeolabLicense::isVerifactu('dynamic', DB::connection('dynamic')->getDatabaseName());
    }

    /** FAC_PresupuestoConFactura: factura o subsanación vinculada. */
    private function hasInvoice(array $budget): bool
    {
        foreach (['FACFAC', 'FACSUB'] as $table) {
            $exists = DB::connection('dynamic')->table($table)
                ->where('PRE2DEL', $budget[0])->where('PRE2SER', $budget[1])->where('PRE2COD', $budget[2])->exists();
            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
