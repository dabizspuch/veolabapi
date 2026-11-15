<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabBillingLines;
use App\Support\VeolabCodes;
use App\Support\VeolabDocuments;
use App\Support\VeolabPurchases;
use App\Support\VeolabStock;
use App\Support\VeolabVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Entregas de proveedor: la recepción de lo comprado (ALMALB + líneas ALMLAL,
 * desde Veolab 3, módulo ALM), como FichaEntrega y Entregas de Veolab.
 *
 *  - Alta (FichaEntrega.Grabar → GrabarLineas): por cada línea de producto,
 *    una serie o lote nuevo (en equipos, uno por unidad) o, en productos sin
 *    series, la suma a su serie oculta; un movimiento de compra (C) con
 *    proveedor, precio y entrega; la línea de la entrega y lo recibido en la
 *    línea de pedido de la que viene. Después, el estado de esos pedidos
 *    (ALM_RecalcularEstadoPedido), los precios del proveedor y las
 *    existencias de los productos.
 *  - Las líneas y el proveedor quedan fijos tras el alta. Se modifican los
 *    datos generales y la verificación (ISO 17025 6.6.2): al cambiar su
 *    estado se apuntan fecha y quién verifica; al pasar a no conforme, con
 *    'poner_fuera_de_uso', sus series en uso quedan fuera de uso.
 *  - Borrado (ALM_EntregaBorrable + ALM_BorrarEntrega): solo si sus series no
 *    se han usado; deshace series, existencias, movimientos y lo recibido en
 *    los pedidos, y manda sus documentos a la papelera.
 */
class EntregaController extends BaseController
{
    protected string $table = 'ALMALB';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'ALB1COD',
    ];
    protected array $searchFields = ['ALBCNUM', 'ALBCOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'               => 'DEL3COD',
        'codigo'                   => 'ALB1COD',
        'fecha'                    => 'ALBDFEC',
        'numero_albaran'           => 'ALBCNUM',
        'observaciones'            => 'ALBCOBS',
        'es_archivado'             => 'ALBBARC',
        'proveedor_delegacion'     => 'PRO2DEL',
        'proveedor_codigo'         => 'PRO2COD',
        'usuario_delegacion'       => 'USU2DEL',   // quien recibe (solo al crear)
        'usuario_codigo'           => 'USU2COD',
        'verificacion'             => 'ALBCEST',   // vacío sin verificar, A aceptada, N no conforme
        'resultado_verificacion'   => 'ALBCVER',
        'fecha_verificacion'       => 'ALBDVER',   // solo lectura: la pone el cambio de verificación
        'verificador_delegacion'   => 'USV2DEL',
        'verificador_codigo'       => 'USV2COD',
    ];

    protected array $foreignKeys = [
        'proveedor'   => 'string',
        'usuario'     => 'string',
        'verificador' => 'string',
    ];

    private ?object $before = null;
    private array $lines = [];
    private array $priceLines = [];
    private array $reservedSeries = [];
    private bool $outOfService = false;
    private array $warnings = [];

    protected function rules(): array
    {
        return [
            'delegacion'                   => 'nullable|string|max:10',
            'codigo'                       => 'nullable|integer|min:1',
            'fecha'                        => 'nullable|date',
            'numero_albaran'               => 'nullable|string|max:50',
            'observaciones'                => 'nullable|string',
            'es_archivado'                 => 'nullable|string|in:T,F',
            'proveedor_delegacion'         => 'nullable|string|max:10',
            'proveedor_codigo'             => 'nullable|string|max:15',
            'usuario_delegacion'           => 'nullable|string|max:10',
            'usuario_codigo'               => 'nullable|string|max:15',
            'verificacion'                 => 'nullable|string|in:,A,N',
            'resultado_verificacion'       => 'nullable|string',
            'verificador_delegacion'       => 'nullable|string|max:10',
            'verificador_codigo'           => 'nullable|string|max:15',
            'poner_fuera_de_uso'           => 'nullable|string|in:T,F',
            'actualizar_precios'           => 'nullable|string|in:T,F',
            'lineas'                       => 'sometimes|nullable|array',
            'lineas.*.producto_delegacion' => 'nullable|string|max:10',
            'lineas.*.producto_codigo'     => 'nullable|string|max:15',
            'lineas.*.pedido_delegacion'   => 'nullable|string|max:10',
            'lineas.*.pedido_serie'        => 'nullable|string|max:10',
            'lineas.*.pedido_codigo'       => 'nullable|integer|min:1',
            'lineas.*.pedido_linea'        => 'nullable|integer|min:1',
            'lineas.*.descripcion'         => 'nullable|string|max:255',
            'lineas.*.cantidad'            => 'nullable|numeric',
            'lineas.*.precio'              => 'nullable|numeric',
            'lineas.*.serie_lote_codigo'   => 'nullable|string|max:30',
            'lineas.*.lote_fabricante'     => 'nullable|string|max:50',
            'lineas.*.fecha_caducidad'     => 'nullable|date',
            'lineas.*.formato_codigo'      => 'nullable|integer|min:0',
            'lineas.*.contenido'           => 'nullable|numeric|min:0',
        ];
    }

    public function store(Request $request)
    {
        $this->warnings = [];

        return $this->withWarnings(parent::store($request));
    }

    public function update(Request $request)
    {
        $this->warnings = [];

        return $this->withWarnings(parent::update($request));
    }

    /** Añade los avisos a una respuesta correcta. */
    private function withWarnings($response)
    {
        if ($this->warnings && $response instanceof JsonResponse && $response->getStatusCode() < 300) {
            $payload = $response->getData(true);
            $payload['avisos'] = array_map(fn ($text) => ['aviso' => $text], $this->warnings);

            return response()->json($payload, $response->getStatusCode());
        }

        return $response;
    }

    protected function validateRelationships(array $data): void
    {
        VeolabPurchases::assertModule();
        $db = DB::connection('dynamic');

        if (! empty($data['delegacion']) && ! $db->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
            throw new BusinessRuleException('La delegación no existe');
        }
        if (! empty($data['proveedor_codigo'])) {
            $exists = $db->table('SINPRO')->where('DEL3COD', (string) ($data['proveedor_delegacion'] ?? ''))
                ->where('PRO1COD', $data['proveedor_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El proveedor no existe');
            }
        }
        foreach (['usuario' => 'El usuario no existe', 'verificador' => 'El usuario que verifica no existe'] as $group => $message) {
            if (! empty($data["{$group}_codigo"])) {
                $exists = $db->table('ACCUSU')->where('DEL3COD', (string) ($data["{$group}_delegacion"] ?? ''))
                    ->where('USU1COD', $data["{$group}_codigo"])->exists();
                if (! $exists) {
                    throw new BusinessRuleException($message);
                }
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        $this->lines = [];
        $this->priceLines = [];

        $this->before = $keys
            ? $db->table('ALMALB')->where('DEL3COD', (string) $keys['delegacion'])->where('ALB1COD', $keys['codigo'])->first()
            : null;
        $before = $this->before;

        if (! empty($data['fecha'])) {
            $data['fecha'] = (new \DateTime($data['fecha']))->format('Y-m-d H:i:s');
        }
        foreach (['numero_albaran', 'observaciones'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (string) ($data[$field] ?? '');
            }
        }
        if (array_key_exists('resultado_verificacion', $data)) {
            $data['resultado_verificacion'] = trim((string) ($data['resultado_verificacion'] ?? ''));
        }

        if ($before) {
            // Grabada: líneas, proveedor y quien recibe quedan fijos (EstadoLineas)
            if (array_key_exists('lineas', $data)) {
                throw new BusinessRuleException('Las líneas de una entrega no se pueden cambiar una vez grabada');
            }
            if (array_key_exists('usuario_codigo', $data) || array_key_exists('usuario_delegacion', $data)) {
                throw new BusinessRuleException('El usuario que recibió la entrega no se puede cambiar');
            }
            if (array_key_exists('proveedor_codigo', $data) || array_key_exists('proveedor_delegacion', $data)) {
                $proDel = (string) ($data['proveedor_delegacion'] ?? '');
                $proCod = (string) ($data['proveedor_codigo'] ?? '');
                if ($proDel !== (string) $before->PRO2DEL || $proCod !== (string) $before->PRO2COD) {
                    throw new BusinessRuleException('El proveedor de una entrega no se puede cambiar una vez grabada');
                }
                unset($data['proveedor_delegacion'], $data['proveedor_codigo']);
            }
        } else {
            $data['delegacion'] = (string) ($data['delegacion'] ?? '');
            if (! array_key_exists('fecha', $data)) {
                $data['fecha'] = $now;
            }
            $data['es_archivado'] ??= 'F';
            $data['numero_albaran'] ??= '';
            $data['observaciones'] ??= '';
            $data['resultado_verificacion'] ??= '';
        }

        // Verificación: al cambiar el estado se apuntan quién y cuándo (Grabar)
        $previous = $before ? (string) $before->ALBCEST : '';
        $verification = array_key_exists('verificacion', $data) ? (string) ($data['verificacion'] ?? '') : $previous;
        $changed = $verification !== $previous;
        $verifierGiven = array_key_exists('verificador_codigo', $data) || array_key_exists('verificador_delegacion', $data);
        if ($verifierGiven && ! $changed) {
            throw new BusinessRuleException('El usuario que verifica solo se indica al cambiar la verificación');
        }
        if ($changed) {
            $data['verificacion'] = $verification;
            if ($verification === '') {
                $data['fecha_verificacion'] = null;
                $data['verificador_delegacion'] = null;
                $data['verificador_codigo'] = null;
            } else {
                $data['fecha_verificacion'] = $now;
                $data['verificador_delegacion'] = (string) ($data['verificador_delegacion'] ?? '');
                $data['verificador_codigo'] = (string) ($data['verificador_codigo'] ?? '');
            }
        } else {
            unset($data['verificacion']);
        }

        // Entrega no conforme: poner fuera de uso lo recibido (EntregaNoConforme), solo al marcarla
        $this->outOfService = ($data['poner_fuera_de_uso'] ?? 'F') === 'T';
        if ($this->outOfService && ! ($changed && $verification === 'N')) {
            throw new BusinessRuleException("'poner_fuera_de_uso' solo se aplica al marcar la entrega como no conforme (verificacion N)");
        }
        $updatePrices = ($data['actualizar_precios'] ?? 'F') === 'T';
        unset($data['poner_fuera_de_uso'], $data['actualizar_precios']);

        if (! $before) {
            $proDel = (string) ($data['proveedor_delegacion'] ?? '');
            $proCod = (string) ($data['proveedor_codigo'] ?? '');
            $this->lines = $this->prepareLines($data['lineas'] ?? [], $proDel, $proCod);
            $data['proveedor_delegacion'] = $proDel;
            $data['proveedor_codigo'] = $proCod;
            $this->priceLines = $this->pricesToUpdate($proDel, $proCod, $updatePrices);
            if (($warning = VeolabPurchases::supplierWarning($proDel, $proCod)) !== '') {
                $this->warnings[] = $warning;
            }
        } elseif ($updatePrices) {
            throw new BusinessRuleException("'actualizar_precios' solo se aplica al crear la entrega");
        }
        unset($data['lineas']);

        return $data;
    }

    protected function updateAdditionalData(array $data, array $keys): array
    {
        $del = (string) $keys['delegacion'];
        $cod = (int) $keys['codigo'];

        if (! $this->before) {
            $proDel = (string) $data['proveedor_delegacion'];
            $proCod = (string) $data['proveedor_codigo'];
            $user = [(string) ($data['usuario_delegacion'] ?? ''), (string) ($data['usuario_codigo'] ?? '')];
            $date = $data['fecha'] ?? null;

            [$products, $orders] = $this->saveLines($del, $cod, $proDel, $proCod, $user, $date);
            foreach ($orders as [$orderDel, $orderSer, $orderCod]) {
                VeolabPurchases::recalculateOrderState($orderDel, $orderSer, $orderCod);
            }
            $this->updateSupplierPrices($proDel, $proCod);
            VeolabStock::recalculateProducts(array_values($products));
        }

        if ($this->outOfService) {
            $count = $this->putOutOfService($del, $cod);
            $this->warnings[] = $count > 0
                ? "Se han puesto fuera de uso {$count} series o lotes recibidos en esta entrega"
                : 'No había series o lotes en uso que poner fuera de uso';
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabPurchases::assertModule();
        if (! $this->deletable((string) $keys['delegacion'], (int) $keys['codigo'])) {
            throw new BusinessRuleException('La entrega no puede ser eliminada porque sus series o lotes ya se han usado (consumos, préstamos, operaciones o planificaciones).');
        }
    }

    /**
     * ALM_BorrarEntrega: quita lo que sumó a series que no creó (la serie oculta
     * de los productos sin series), lo recibido en los pedidos (que recuperan su
     * estado), sus movimientos, las series que creó, sus líneas y documentos.
     * La propia entrega ya la ha borrado destroy().
     */
    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        $del = (string) $keys['delegacion'];
        $cod = (int) $keys['codigo'];

        $added = $db->select('SELECT M.PRD2DEL, M.PRD2COD, M.SEL2COD, M.MOVNCAN FROM ALMMOV M WHERE M.ALB2DEL = ? AND M.ALB2COD = ? '
            .'AND M.MOVCTIP = ? AND NOT EXISTS (SELECT 1 FROM ALMSEL S WHERE S.PRD3DEL = M.PRD2DEL AND S.PRD3COD = M.PRD2COD '
            .'AND S.SEL1COD = M.SEL2COD AND S.ALB2DEL = M.ALB2DEL AND S.ALB2COD = M.ALB2COD)', [$del, $cod, VeolabPurchases::MOV_COMPRA]);
        foreach ($added as $row) {
            VeolabStock::addToLot((string) $row->PRD2DEL, (string) $row->PRD2COD, (string) $row->SEL2COD, -(float) $row->MOVNCAN);
        }

        $products = [];
        $rows = $db->table('ALMLAL')->where('ALB3DEL', $del)->where('ALB3COD', $cod)->whereNotNull('PRD2COD')
            ->distinct()->get(['PRD2DEL', 'PRD2COD']);
        foreach ($rows as $row) {
            $products[$row->PRD2DEL."\x1B".$row->PRD2COD] = [(string) $row->PRD2DEL, (string) $row->PRD2COD];
        }

        $orders = [];
        $received = $db->table('ALMLAL')->where('ALB3DEL', $del)->where('ALB3COD', $cod)->whereNotNull('LPE2COD')
            ->groupBy('LPE2DEL', 'LPE2SER', 'LPE2COD', 'LPE2LIN')
            ->get(['LPE2DEL', 'LPE2SER', 'LPE2COD', 'LPE2LIN', DB::raw('SUM(LALNCAN) AS CANTIDAD')]);
        foreach ($received as $row) {
            $db->table('ALMLPE')->where('PED3DEL', (string) $row->LPE2DEL)->where('PED3SER', (string) $row->LPE2SER)
                ->where('PED3COD', (int) $row->LPE2COD)->where('LPE1COD', (int) $row->LPE2LIN)
                ->update(['LPENREC' => DB::raw('LPENREC - '.sprintf('%.5F', (float) $row->CANTIDAD))]);
            $orders[$row->LPE2DEL."\x1B".$row->LPE2SER."\x1B".$row->LPE2COD] = [(string) $row->LPE2DEL, (string) $row->LPE2SER, (int) $row->LPE2COD];
        }

        $db->table('ALMMOV')->where('ALB2DEL', $del)->where('ALB2COD', $cod)->delete();
        $db->table('ALMSEL')->where('ALB2DEL', $del)->where('ALB2COD', $cod)->delete();
        $db->table('ALMLAL')->where('ALB3DEL', $del)->where('ALB3COD', $cod)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('ALB2COD', $cod)->update(VeolabDocuments::trashUpdate($del));

        foreach ($orders as [$orderDel, $orderSer, $orderCod]) {
            VeolabPurchases::recalculateOrderState($orderDel, $orderSer, $orderCod);
        }
        VeolabStock::recalculateProducts(array_values($products));
    }

    /** Como la ficha: la modificación es un suceso de fila (sin detalle de campos). */
    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $this->auditRow($keyParams));
    }

    /** ?pedido_delegacion=&pedido_serie=&pedido_codigo=: entregas con líneas de ese pedido. */
    protected function applyCustomFilters(Request $request, $query): void
    {
        if (! $request->has('pedido_codigo')) {
            return;
        }
        $order = [(string) ($request->query('pedido_delegacion') ?? ''), (string) ($request->query('pedido_serie') ?? ''),
            (int) $request->query('pedido_codigo')];
        $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ALMLAL')
            ->whereColumn('ALMLAL.ALB3DEL', 'ALMALB.DEL3COD')->whereColumn('ALMLAL.ALB3COD', 'ALMALB.ALB1COD')
            ->where('ALMLAL.LPE2DEL', $order[0])->where('ALMLAL.LPE2SER', $order[1])->where('ALMLAL.LPE2COD', $order[2]));
    }

    /** Líneas de cada entrega, en orden, con la serie o lote creado. */
    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        foreach ($rows as &$row) {
            $row['lineas'] = $db->table('ALMLAL')->where('ALB3DEL', (string) $row['delegacion'])->where('ALB3COD', $row['codigo'])
                ->orderBy('LALNORD')->orderBy('LAL1COD')->get()
                ->map(function ($l) {
                    $hasProduct = $l->PRD2COD !== null && $l->PRD2COD !== '';
                    $hasOrder = (int) $l->LPE2COD > 0;

                    return [
                        'linea'               => (int) $l->LAL1COD,
                        'pedido_delegacion'   => $hasOrder ? (string) $l->LPE2DEL : null,
                        'pedido_serie'        => $hasOrder ? (string) $l->LPE2SER : null,
                        'pedido_codigo'       => $hasOrder ? (int) $l->LPE2COD : null,
                        'pedido_linea'        => $hasOrder ? (int) $l->LPE2LIN : null,
                        'producto_delegacion' => $hasProduct ? (string) $l->PRD2DEL : null,
                        'producto_codigo'     => $hasProduct ? (string) $l->PRD2COD : null,
                        'serie_lote_codigo'   => $l->SEL2COD !== null && $l->SEL2COD !== '' ? (string) $l->SEL2COD : null,
                        'descripcion'         => $l->LALCDES,
                        'formato_codigo'      => (int) $l->FOR2COD > 0 ? (int) $l->FOR2COD : null,
                        'formato'             => $l->LALCFOR,
                        'contenido'           => $l->LALNFAC,
                        'cantidad'            => $l->LALNCAN,
                        'precio'              => $l->LALNPRE,
                        'lote_fabricante'     => $l->LALCLOP,
                        'fecha_caducidad'     => $l->LALDCAD,
                        'verificacion'        => $l->LALCVER,
                        'observaciones'       => $l->LALCOBS,
                        'orden'               => (int) $l->LALNORD,
                    ];
                })->all();
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Alta: líneas (CamposValidos, LineasPedido, AñadirProducto, GrabarLineas)
    // ------------------------------------------------------------------

    /**
     * Valida y completa las líneas como la ficha nueva: las de un pedido toman
     * su producto, descripción, lo pendiente, el precio neto pedido y su
     * formato; las de producto, el precio y el formato del proveedor. Fija el
     * proveedor (el de los pedidos si no se indica).
     */
    private function prepareLines(array $input, string &$proDel, string &$proCod): array
    {
        $db = DB::connection('dynamic');

        // Sin proveedor, el del primer pedido (LineasPedido)
        if ($proCod === '') {
            foreach ($input as $line) {
                if ((int) ($line['pedido_codigo'] ?? 0) > 0) {
                    $order = $db->table('ALMPED')->where('DEL3COD', (string) ($line['pedido_delegacion'] ?? ''))
                        ->where('PED1SER', (string) ($line['pedido_serie'] ?? ''))->where('PED1COD', (int) $line['pedido_codigo'])
                        ->first(['PRO2DEL', 'PRO2COD']);
                    if ($order) {
                        [$proDel, $proCod] = [(string) $order->PRO2DEL, (string) $order->PRO2COD];
                    }
                    break;
                }
            }
        }
        if ($proCod === '') {
            throw new BusinessRuleException('Falta el proveedor de la entrega.');
        }

        $out = [];
        $series = [];
        foreach ($input as $i => $line) {
            $position = $i + 1;
            $prdDel = (string) ($line['producto_delegacion'] ?? '');
            $prdCod = trim((string) ($line['producto_codigo'] ?? ''));
            $orderKey = null;
            $orderPrice = -1.0;
            $defaults = ['descripcion' => '', 'cantidad' => 1.0, 'precio' => 0.0, 'formato' => null];

            if ((int) ($line['pedido_codigo'] ?? 0) > 0) {
                $orderKey = [(string) ($line['pedido_delegacion'] ?? ''), (string) ($line['pedido_serie'] ?? ''), (int) $line['pedido_codigo'],
                    (int) ($line['pedido_linea'] ?? 0)];
                $orderText = VeolabCodes::format('ALMPED', (string) $orderKey[2], $orderKey[0], $orderKey[1]);
                if ($orderKey[3] <= 0) {
                    throw new BusinessRuleException("Falta 'pedido_linea' en la línea {$position}");
                }
                $order = $db->table('ALMPED')->where('DEL3COD', $orderKey[0])->where('PED1SER', $orderKey[1])->where('PED1COD', $orderKey[2])
                    ->first(['PEDCEST', 'PRO2DEL', 'PRO2COD']);
                if (! $order) {
                    throw new BusinessRuleException("El pedido {$orderText} de la línea {$position} no existe");
                }
                if (in_array((string) $order->PEDCEST, ['R', 'C'], true)) {
                    throw new BusinessRuleException("El pedido {$orderText} está recibido o cancelado: no admite más entregas");
                }
                if ((string) $order->PRO2DEL !== $proDel || (string) $order->PRO2COD !== $proCod) {
                    throw new BusinessRuleException("El pedido {$orderText} es de otro proveedor");
                }
                $lpe = $db->table('ALMLPE')->where('PED3DEL', $orderKey[0])->where('PED3SER', $orderKey[1])->where('PED3COD', $orderKey[2])
                    ->where('LPE1COD', $orderKey[3])->first();
                if (! $lpe) {
                    throw new BusinessRuleException("La línea {$orderKey[3]} del pedido {$orderText} no existe");
                }
                $lpeDel = $lpe->PRD2COD !== null && $lpe->PRD2COD !== '' ? (string) $lpe->PRD2DEL : '';
                $lpeCod = (string) ($lpe->PRD2COD ?? '');
                $otherDelegation = array_key_exists('producto_delegacion', $line) && $prdDel !== $lpeDel;
                if ($prdCod !== '' && ($prdCod !== $lpeCod || $otherDelegation)) {
                    throw new BusinessRuleException("El producto de la línea {$position} no es el de la línea {$orderKey[3]} del pedido {$orderText}");
                }
                [$prdDel, $prdCod] = [$lpeDel, $lpeCod];

                // Lo que falta por recibir, al precio neto pedido (con su descuento)
                $orderPrice = (float) $lpe->LPENCAN != 0.0 ? round((float) $lpe->LPENTOT / (float) $lpe->LPENCAN, 5) : (float) $lpe->LPENPRE;
                $defaults = ['descripcion' => (string) $lpe->LPECDES, 'cantidad' => (float) $lpe->LPENCAN - (float) $lpe->LPENREC,
                    'precio' => $orderPrice, 'formato' => [(int) $lpe->FOR2COD, (string) $lpe->LPECFOR, (float) $lpe->LPENFAC]];
                if ($defaults['formato'][2] <= 0 && $prdCod !== '') {
                    $defaults['formato'] = VeolabPurchases::purchaseFormat($proDel, $proCod, $prdDel, $prdCod);
                }
            } elseif ($prdCod !== '') {
                $description = $db->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)->value('PRDCDES');
                if ($description === null) {
                    throw new BusinessRuleException("El producto {$prdCod} de la línea {$position} no existe");
                }
                [, $price] = VeolabPurchases::supplierPrice($proDel, $proCod, $prdDel, $prdCod);
                $defaults = ['descripcion' => (string) $description, 'cantidad' => 1.0, 'precio' => $price,
                    'formato' => VeolabPurchases::purchaseFormat($proDel, $proCod, $prdDel, $prdCod)];
            }
            if ($prdCod === '') {
                $prdDel = '';
            }

            // Formato: el de la línea de pedido o el propuesto, salvo que se indique otro
            [$format, $formatText, $factor] = $defaults['formato'] ?? [0, '', 0.0];
            if (array_key_exists('formato_codigo', $line)) {
                $format = (int) ($line['formato_codigo'] ?? 0);
                [$formatText, $factor] = $format > 0 ? VeolabPurchases::chosenFormat($prdDel, $prdCod, $format) : ['', 0.0];
            }
            if (isset($line['contenido'])) {
                $factor = (float) $line['contenido'];
            }

            $type = $prdCod !== '' ? VeolabPurchases::productType($prdDel, $prdCod) : '';
            $quantity = array_key_exists('cantidad', $line) ? (float) ($line['cantidad'] ?? 0) : (float) $defaults['cantidad'];
            $price = array_key_exists('precio', $line) ? (float) ($line['precio'] ?? 0) : (float) $defaults['precio'];
            $description = array_key_exists('descripcion', $line) ? (string) ($line['descripcion'] ?? '') : $defaults['descripcion'];
            $lot = (string) ($line['lote_fabricante'] ?? '');
            $expiry = ! empty($line['fecha_caducidad']) ? (new \DateTime($line['fecha_caducidad']))->format('Y-m-d 00:00:00') : null;
            $serie = trim((string) ($line['serie_lote_codigo'] ?? ''));

            if ($prdCod !== '' && $quantity <= 0) {
                throw new BusinessRuleException("La cantidad de la línea {$position} debe ser mayor que cero.");
            }
            $units = 1;
            if ($type === 'E') {
                if (abs($quantity - floor($quantity)) > 1e-9) {
                    throw new BusinessRuleException("La línea {$position} es de un equipo: la cantidad son unidades enteras, una serie por unidad.");
                }
                $units = (int) floor($quantity);
            }
            if ($serie !== '' && ! in_array($type, ['E', 'L'], true)) {
                throw new BusinessRuleException("La línea {$position} no crea series ni lotes (producto sin series o línea sin producto): no admite 'serie_lote_codigo'");
            }

            // SerieIndicada: la escrita o, en lotes, el del fabricante; vacía, el siguiente número
            $base = $serie !== '' ? $serie : ($type === 'L' ? trim($lot) : '');
            if ($base !== '') {
                for ($k = 1; $k <= $units; $k++) {
                    $code = mb_substr(self::unitSerie($base, $k), 0, 30);
                    $key = $prdDel."\x1B".$prdCod."\x1B".$code;
                    $exists = $code === VeolabStock::SERIE_UNICA || isset($series[$key])
                        || $db->table('ALMSEL')->where('PRD3DEL', $prdDel)->where('PRD3COD', $prdCod)->where('SEL1COD', $code)->exists();
                    if ($exists) {
                        throw new BusinessRuleException("La serie o lote «{$code}» de {$prdCod} ya existe, está reservada o está repetida en la entrega.");
                    }
                    $series[$key] = true;
                }
            }

            $out[] = [
                'producto_delegacion' => $prdDel,
                'producto_codigo'     => $prdCod,
                'tipo'                => $type,
                'pedido'              => $orderKey,
                'precio_pedido'       => $orderPrice,
                'descripcion'         => $description,
                'cantidad'            => $quantity,
                'unidades'            => $units,
                'precio'              => round($price, 5),
                'lote'                => $lot,
                'caducidad'           => $expiry,
                'base'                => $base,
                'formato_codigo'      => $format,
                'formato'             => $formatText,
                'contenido'           => $factor,
            ];
        }

        $this->reservedSeries = $series;

        return $out;
    }

    /**
     * PreciosDistintos: líneas de producto cuyo precio no coincide con el del
     * proveedor (salvo las de un pedido al precio pedido), que se guardan con
     * 'actualizar_precios' (en Veolab, la pregunta), y las que el proveedor
     * aún no tiene con precio, que lo toman siempre.
     */
    private function pricesToUpdate(string $proDel, string $proCod, bool $update): array
    {
        $out = [];
        $different = [];
        foreach ($this->lines as $line) {
            if ($line['producto_codigo'] === '' || $line['precio'] <= 0) {
                continue;
            }
            [, $supplier] = VeolabPurchases::supplierPrice($proDel, $proCod, $line['producto_delegacion'], $line['producto_codigo']);
            if ($supplier == 0.0) {
                $out[] = $line;
            } elseif (abs($supplier - $line['precio']) > 0.000001 && abs($line['precio_pedido'] - $line['precio']) > 0.000001) {
                $different[] = VeolabCodes::format('ALMPRD', $line['producto_codigo'], $line['producto_delegacion'])
                    .' ('.self::money($supplier).' > '.self::money($line['precio']).')';
                if ($update) {
                    $out[] = $line;
                }
            }
        }
        if ($different && ! $update) {
            $this->warnings[] = 'El precio de compra no coincide con el del proveedor en: '.implode(', ', $different)
                .". No se han cambiado los precios del proveedor (envíe 'actualizar_precios': 'T' para guardarlos).";
        }

        return $out;
    }

    /** GrabarLineas: series, existencias, movimientos de compra, líneas y lo recibido en los pedidos. */
    private function saveLines(string $del, int $cod, string $proDel, string $proCod, array $user, ?string $date): array
    {
        $db = DB::connection('dynamic');
        $created = (string) $db->selectOne('SELECT NOW() AS n')->n;
        $received = $date ?: $created;
        $products = [];
        $orders = [];
        $number = 0;

        foreach ($this->lines as $line) {
            [$prdDel, $prdCod, $type] = [$line['producto_delegacion'], $line['producto_codigo'], $line['tipo']];
            $times = $type === 'E' ? max($line['unidades'], 1) : 1;
            $units = $type === 'E' ? 1.0 : (float) $line['cantidad'];

            if ($prdCod !== '') {
                // El producto bloqueado: el número de serie siguiente sin carreras
                $db->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)->lockForUpdate()->first(['PRD1COD']);
            }

            for ($k = 1; $k <= $times; $k++) {
                $lot = '';
                if ($prdCod !== '') {
                    if ($type === 'S') {
                        // Sin series: suma a la serie oculta con su cantidad por unidad
                        $lot = VeolabStock::singleLot($prdDel, $prdCod);
                        $perUnit = (float) ($db->table('ALMSEL')->where('PRD3DEL', $prdDel)->where('PRD3COD', $prdCod)
                            ->where('SEL1COD', $lot)->value('SELNCAU') ?? 0);
                        if ($perUnit <= 0) {
                            $perUnit = 1.0;
                        }
                        if ($line['contenido'] > 0) {
                            $perUnit = (float) $line['contenido'];
                        }
                        VeolabStock::addToLot($prdDel, $prdCod, $lot, round($units * $perUnit, 5));
                    } else {
                        // Serie o lote nuevo
                        if ($line['base'] !== '') {
                            $lot = self::unitSerie($line['base'], $k);
                        } else {
                            // ALM_ObtenerSerieSiguiente, saltando las que otras líneas de esta entrega indican
                            $next = (int) $db->table('ALMSEL')->where('PRD3DEL', $prdDel)->where('PRD3COD', $prdCod)
                                ->max(DB::raw('CAST(SEL1COD AS DECIMAL(30,0))')) + 1;
                            while (isset($this->reservedSeries[$prdDel."\x1B".$prdCod."\x1B".$next])) {
                                $next++;
                            }
                            $lot = (string) $next;
                        }
                        $lot = mb_substr($lot, 0, 30);
                        $perUnit = (float) $line['contenido'];
                        if ($perUnit <= 0 || $type === 'E') {
                            $perUnit = VeolabPurchases::quantityPerUnit($prdDel, $prdCod);
                        }
                        $db->table('ALMSEL')->insert([
                            'PRD3DEL' => $prdDel, 'PRD3COD' => $prdCod, 'SEL1COD' => $lot, 'SELCDES' => '', 'SELCESA' => 'N',
                            'SELDALT' => $created, 'SELDREC' => $received, 'SELDCAD' => $line['caducidad'],
                            'SELNCAU' => $perUnit, 'SELNUNL' => $units, 'SELNUNE' => $units, 'SELNCAE' => round($units * $perUnit, 5),
                            'SELNPRE' => $line['precio'], 'SELCESR' => 'N', 'PRO2DEL' => $proDel, 'PRO2COD' => $proCod,
                            'ALB2DEL' => $del, 'ALB2COD' => $cod,
                        ]);
                    }

                    // Movimiento de compra con proveedor, precio y entrega
                    do {
                        $movement = VeolabCodes::next('ALMMOV', '', $del);
                    } while ($db->table('ALMMOV')->where('DEL3COD', $del)->where('MOV1COD', $movement)->exists());
                    $db->table('ALMMOV')->insert([
                        'DEL3COD' => $del, 'MOV1COD' => $movement, 'MOVCTIP' => VeolabPurchases::MOV_COMPRA, 'MOVDFEC' => $received,
                        'MOVNCAN' => round($units * $perUnit, 5), 'PRD2DEL' => $prdDel, 'PRD2COD' => $prdCod, 'SEL2COD' => $lot,
                        'USU2DEL' => $user[0], 'USU2COD' => $user[1], 'ALB2DEL' => $del, 'ALB2COD' => $cod,
                        'PRO2DEL' => $proDel, 'PRO2COD' => $proCod, 'MOVNPRE' => $line['precio'],
                    ]);
                    $products[$prdDel."\x1B".$prdCod] = [$prdDel, $prdCod];
                }

                // Línea de la entrega (una por unidad en los equipos)
                $number++;
                $order = $line['pedido'];
                $db->table('ALMLAL')->insert([
                    'ALB3DEL' => $del, 'ALB3COD' => $cod, 'LAL1COD' => $number,
                    'LPE2DEL' => $order ? $order[0] : null, 'LPE2SER' => $order ? $order[1] : null,
                    'LPE2COD' => $order ? $order[2] : null, 'LPE2LIN' => $order ? $order[3] : null,
                    'PRD2DEL' => $prdCod !== '' ? $prdDel : null, 'PRD2COD' => $prdCod !== '' ? $prdCod : null,
                    'SEL2COD' => $lot !== '' ? $lot : null,
                    'LALCDES' => mb_substr($line['descripcion'], 0, 255), 'LALNCAN' => $units, 'LALNPRE' => $line['precio'],
                    'LALCLOP' => mb_substr($line['lote'], 0, 50), 'LALDCAD' => $line['caducidad'], 'LALNORD' => $number,
                    'FOR2COD' => $line['formato_codigo'] > 0 ? $line['formato_codigo'] : null,
                    'LALCFOR' => $line['formato'] !== '' ? mb_substr($line['formato'], 0, 50) : null,
                    'LALNFAC' => $line['contenido'] > 0 ? $line['contenido'] : null,
                ]);

                // Lo recibido en la línea de pedido
                if ($order) {
                    $db->table('ALMLPE')->where('PED3DEL', $order[0])->where('PED3SER', $order[1])->where('PED3COD', $order[2])
                        ->where('LPE1COD', $order[3])->update(['LPENREC' => DB::raw('LPENREC + '.sprintf('%.5F', $units))]);
                    $orders[$order[0]."\x1B".$order[1]."\x1B".$order[2]] = [$order[0], $order[1], $order[2]];
                }
            }
        }

        return [$products, array_values($orders)];
    }

    /**
     * ActualizarPrecios: el precio de esta compra pasa a ser el del proveedor
     * (con su fecha) y, si es el proveedor predeterminado, el del producto.
     */
    private function updateSupplierPrices(string $proDel, string $proCod): void
    {
        $db = DB::connection('dynamic');
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        foreach ($this->priceLines as $line) {
            [$prdDel, $prdCod, $price] = [$line['producto_delegacion'], $line['producto_codigo'], $line['precio']];
            $where = ['PRO3DEL' => $proDel, 'PRO3COD' => $proCod, 'PRD3DEL' => $prdDel, 'PRD3COD' => $prdCod];
            if ($db->table('ALMPYP')->where($where)->exists()) {
                $db->table('ALMPYP')->where($where)->update(['PYPNPRE' => $price, 'PYPDPRE' => $now]);
            } else {
                $db->table('ALMPYP')->insert($where + ['PYPCREF' => '', 'PYPNPRE' => $price, 'PYPDPRE' => $now]);
            }
            $db->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)
                ->where('PRO2DEL', $proDel)->where('PRO2COD', $proCod)->update(['PRDNPRE' => $price]);
        }
    }

    /**
     * EntregaNoConforme: las series y lotes recibidos que están en uso (sin
     * estado, nuevos, en uso o en límite de uso) pasan a fuera de uso, salvo
     * la serie oculta de los productos sin series, que lleva también
     * existencias de otras entregas. Devuelve cuántas.
     */
    private function putOutOfService(string $del, int $cod): int
    {
        $db = DB::connection('dynamic');
        $series = $db->table('ALMLAL AS l')
            ->join('ALMSEL AS s', fn ($j) => $j->on('l.PRD2DEL', '=', 's.PRD3DEL')->on('l.PRD2COD', '=', 's.PRD3COD')->on('l.SEL2COD', '=', 's.SEL1COD'))
            ->where('l.ALB3DEL', $del)->where('l.ALB3COD', $cod)
            ->distinct()->get(['s.PRD3DEL', 's.PRD3COD', 's.SEL1COD', 's.SELCESA']);
        $count = 0;
        foreach ($series as $s) {
            $state = (string) $s->SELCESA;
            if ((string) $s->SEL1COD === VeolabStock::SERIE_UNICA || ! in_array($state, ['', 'N', 'U', 'L'], true)) {
                continue;
            }
            $db->table('ALMSEL')->where('PRD3DEL', $s->PRD3DEL)->where('PRD3COD', $s->PRD3COD)->where('SEL1COD', $s->SEL1COD)
                ->update(['SELCESA' => 'F']);
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'ALMSEL', $s->PRD3COD.' - '.$s->SEL1COD, 'ALMSELSELCESA', 'F', $state);
            $count++;
        }

        return $count;
    }

    /**
     * ALM_EntregaBorrable: sus series o lotes no tienen más movimientos que su
     * compra ni préstamos, materias primas, operaciones o planificaciones.
     */
    private function deletable(string $del, int $cod): bool
    {
        $row = DB::connection('dynamic')->selectOne('SELECT COUNT(*) AS N FROM ALMSEL S WHERE S.ALB2DEL = ? AND S.ALB2COD = ? AND ('
            .'EXISTS (SELECT 1 FROM ALMMOV M WHERE M.PRD2DEL = S.PRD3DEL AND M.PRD2COD = S.PRD3COD AND M.SEL2COD = S.SEL1COD '
            .'AND NOT (M.MOVCTIP = ? AND M.ALB2DEL = S.ALB2DEL AND M.ALB2COD = S.ALB2COD)) '
            .'OR EXISTS (SELECT 1 FROM ALMPYS P WHERE P.PRD3DEL = S.PRD3DEL AND P.PRD3COD = S.PRD3COD AND P.SEL3COD = S.SEL1COD) '
            .'OR EXISTS (SELECT 1 FROM ALMMAT A WHERE (A.PRD3DEL = S.PRD3DEL AND A.PRD3COD = S.PRD3COD AND A.SEL3COD = S.SEL1COD) '
            .'OR (A.PRM3DEL = S.PRD3DEL AND A.PRM3COD = S.PRD3COD AND A.SEM3COD = S.SEL1COD)) '
            .'OR EXISTS (SELECT 1 FROM LABOPE O WHERE O.PRD2DEL = S.PRD3DEL AND O.PRD2COD = S.PRD3COD AND O.SEL2COD = S.SEL1COD) '
            .'OR EXISTS (SELECT 1 FROM LABPLO L WHERE L.PRD2DEL = S.PRD3DEL AND L.PRD2COD = S.PRD3COD AND L.SEL2COD = S.SEL1COD))',
            [$del, $cod, VeolabPurchases::MOV_COMPRA]);

        return (int) $row->N === 0;
    }

    /**
     * SerieDeUnidad: código de la unidad n de un equipo con varias unidades:
     * si el de la primera es un número, los siguientes números; si no, el
     * código con -2, -3...
     */
    private static function unitSerie(string $base, int $unit): string
    {
        if ($unit <= 1) {
            return $base;
        }
        if (preg_match('/^\s*[+-]?\d+\s*$/', $base)) {
            return (string) ((int) trim($base) + $unit - 1);
        }

        return $base.'-'.$unit;
    }

    private static function money(float $amount): string
    {
        return VeolabBillingLines::localized(number_format($amount, 2, '.', ''));
    }
}
