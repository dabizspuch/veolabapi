<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabPurchases;
use App\Support\VeolabVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos a proveedor (ALMPED + líneas ALMLPE, desde Veolab 3, módulo ALM),
 * como FichaPedido y Pedidos de Veolab:
 *
 *  - Clave delegación + serie + código (contador ACCCLT 'ALMPED' por serie y
 *    formato de ACCCFC). Sin serie, la predeterminada de la delegación.
 *  - Estados (PEDCEST): B borrador (al crear), E enviado, P recibido en parte,
 *    R recibido, C cancelado. A mano solo borrador o enviado mientras no haya
 *    llegado nada, cancelado siempre, y P o R solo si es el que corresponde a
 *    lo recibido (al reabrir un cancelado): los ponen las entregas. Pasar a
 *    enviado pone la fecha de envío si no la tiene, y dar fecha de envío a un
 *    borrador lo pasa a enviado.
 *  - 'lineas' sustituye la rejilla. Cada línea conserva su número ('linea'),
 *    que citan las entregas, y lo recibido; las nuevas toman el siguiente. Sin
 *    referencia, precio o formato se proponen los del proveedor (ALMPYP,
 *    formatos ALMFOR). Lo pedido no baja de lo recibido, las líneas con algo
 *    recibido no se quitan y en un pedido recibido o cancelado no cambian.
 *  - 'requisitos' (PEDCREQ): al crear, vacíos, los del proveedor (PROCREQ).
 *  - El proveedor no cambia con algo recibido; al cambiarlo, las líneas sin
 *    referencia ni precio o sin formato toman las del nuevo.
 *  - Borrado: solo sin nada recibido (ninguna entrega lo cita).
 *  - Las escrituras devuelven en 'avisos' el aviso del proveedor
 *    (ALM_AvisoProveedor) al crear o al cambiar de proveedor.
 */
class PedidoController extends BaseController
{
    protected string $table = 'ALMPED';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'PED1SER',
        'codigo'     => 'PED1COD',
    ];
    protected array $searchFields = ['PEDCREF', 'PEDCOBS', 'PEDCREQ'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    protected array $mapping = [
        'delegacion'           => 'DEL3COD',
        'serie'                => 'PED1SER',
        'codigo'               => 'PED1COD',
        'fecha'                => 'PEDDFEC',
        'estado'               => 'PEDCEST',
        'referencia'           => 'PEDCREF',
        'fecha_prevista'       => 'PEDDPRE',
        'fecha_envio'          => 'PEDDENV',
        'total'                => 'PEDNTOT',   // solo lectura: suma de las líneas sin impuestos
        'observaciones'        => 'PEDCOBS',
        'requisitos'           => 'PEDCREQ',
        'es_archivado'         => 'PEDBARC',
        'proveedor_delegacion' => 'PRO2DEL',
        'proveedor_codigo'     => 'PRO2COD',
        'empleado_delegacion'  => 'EMP2DEL',
        'empleado_codigo'      => 'EMP2COD',
        'usuario_delegacion'   => 'USU2DEL',   // quien lo crea (solo al crear)
        'usuario_codigo'       => 'USU2COD',
    ];

    protected array $foreignKeys = [
        'proveedor' => 'string',
        'empleado'  => 'int',
        'usuario'   => 'string',
    ];

    /** Pedido antes del cambio (modificación). */
    private ?object $before = null;

    /** Avisos de la escritura en curso. */
    private array $warnings = [];

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'delegacion'                => 'nullable|string|max:10',
            'serie'                     => 'nullable|string|max:10',
            'codigo'                    => 'nullable|integer|min:1',
            'fecha'                     => 'nullable|date',
            'estado'                    => 'nullable|string|in:'.implode(',', VeolabPurchases::ORDER_STATES),
            'referencia'                => 'nullable|string|max:50',
            'fecha_prevista'            => 'nullable|date',
            'fecha_envio'               => 'nullable|date',
            'observaciones'             => 'nullable|string',
            'requisitos'                => 'nullable|string',
            'es_archivado'              => 'nullable|string|in:T,F',
            'proveedor_delegacion'      => 'nullable|string|max:10',
            'proveedor_codigo'          => ($isCreating ? 'required' : 'sometimes').'|string|max:15',
            'empleado_delegacion'       => 'nullable|string|max:10',
            'empleado_codigo'           => 'nullable|integer|min:0',
            'usuario_delegacion'        => 'nullable|string|max:10',
            'usuario_codigo'            => 'nullable|string|max:15',
            'lineas'                    => 'sometimes|nullable|array',
            'lineas.*.linea'            => 'nullable|integer|min:1',
            'lineas.*.producto_delegacion' => 'nullable|string|max:10',
            'lineas.*.producto_codigo'  => 'nullable|string|max:15',
            'lineas.*.referencia'       => 'nullable|string|max:30',
            'lineas.*.descripcion'      => 'nullable|string|max:255',
            'lineas.*.cantidad'         => 'nullable|numeric|min:0',
            'lineas.*.precio'           => 'nullable|numeric',
            'lineas.*.descuento'        => 'nullable',
            'lineas.*.formato_codigo'   => 'nullable|integer|min:0',
            'lineas.*.contenido'        => 'nullable|numeric|min:0',
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
        if (isset($data['serie']) && str_contains((string) $data['serie'], '#')) {
            throw new BusinessRuleException('La serie no puede contener el carácter #');
        }
        if (! empty($data['proveedor_codigo'])) {
            $exists = $db->table('SINPRO')->where('DEL3COD', (string) ($data['proveedor_delegacion'] ?? ''))
                ->where('PRO1COD', $data['proveedor_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El proveedor no existe');
            }
        }
        if (! empty($data['empleado_codigo'])) {
            $exists = $db->table('GRHEMP')->where('DEL3COD', (string) ($data['empleado_delegacion'] ?? ''))
                ->where('EMP1COD', $data['empleado_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El empleado no existe');
            }
        }
        if (! empty($data['usuario_codigo'])) {
            $exists = $db->table('ACCUSU')->where('DEL3COD', (string) ($data['usuario_delegacion'] ?? ''))
                ->where('USU1COD', $data['usuario_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El usuario no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;

        $this->before = $keys
            ? $db->table('ALMPED')->where('DEL3COD', (string) $keys['delegacion'])->where('PED1SER', (string) $keys['serie'])
                ->where('PED1COD', $keys['codigo'])->first()
            : null;
        $before = $this->before;

        foreach (['fecha', 'fecha_prevista', 'fecha_envio'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = (new \DateTime($data[$field]))->format('Y-m-d H:i:s');
            }
        }
        foreach (['referencia', 'observaciones', 'requisitos'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (string) ($data[$field] ?? '');
            }
        }

        if ($before) {
            if (array_key_exists('usuario_codigo', $data) || array_key_exists('usuario_delegacion', $data)) {
                throw new BusinessRuleException('El usuario que creó el pedido no se puede cambiar');
            }
        } else {
            // Nuevo: serie predeterminada, fecha de hoy, borrador y el empleado del usuario (EmpleadoUsuario)
            $data['delegacion'] = (string) ($data['delegacion'] ?? '');
            if (! array_key_exists('serie', $data) || $data['serie'] === null) {
                $data['serie'] = (string) ($db->table('ACCCLT')->where('CLTCTAB', 'ALMPED')->where('DEL3COD', $data['delegacion'])
                    ->where('CLTBPRE', 'T')->value('CLTCSER') ?? '');
            }
            if (! array_key_exists('fecha', $data)) {
                $data['fecha'] = $now;
            }
            $data['es_archivado'] ??= 'F';
            $data['referencia'] ??= '';
            $data['observaciones'] ??= '';
            if (empty($data['empleado_codigo']) && ! empty($data['usuario_codigo'])) {
                $employee = $db->table('ACCUSU')->where('DEL3COD', (string) ($data['usuario_delegacion'] ?? ''))
                    ->where('USU1COD', $data['usuario_codigo'])->first(['EMP2DEL', 'EMP2COD']);
                if ($employee && (int) $employee->EMP2COD !== 0) {
                    $data['empleado_delegacion'] = (string) $employee->EMP2DEL;
                    $data['empleado_codigo'] = (int) $employee->EMP2COD;
                }
            }
        }

        // Proveedor (fijo en cuanto ha llegado algo o con las líneas fijas)
        $proDel = (string) (array_key_exists('proveedor_codigo', $data) ? ($data['proveedor_delegacion'] ?? '') : ($before->PRO2DEL ?? ''));
        $proCod = (string) ($data['proveedor_codigo'] ?? ($before->PRO2COD ?? ''));
        $data['proveedor_delegacion'] = $proDel;
        $data['proveedor_codigo'] = $proCod;
        if ($proCod === '') {
            throw new BusinessRuleException('Falta el proveedor del pedido.');
        }
        $supplierChanged = ! $before || $proDel !== (string) $before->PRO2DEL || $proCod !== (string) $before->PRO2COD;

        $stored = $before ? $this->storedLines($keys) : [];
        $received = array_sum(array_map(fn ($l) => abs($l['recibido']), $stored)) > 0;

        // Líneas: las indicadas o, al cambiar de proveedor, las grabadas completadas con el nuevo
        $lines = null;
        if (array_key_exists('lineas', $data)) {
            $lines = $this->prepareLines($data['lineas'] ?? [], $stored, $proDel, $proCod);
        } elseif ($before && $supplierChanged) {
            $lines = $this->completeLines($stored, $proDel, $proCod);
        }
        unset($data['lineas']);
        $finalLines = $lines ?? $stored;

        // Estado (cmbEstadoPedido_Click, ucdFechaEnvio_Change)
        $previous = $before ? ((string) $before->PEDCEST !== '' ? (string) $before->PEDCEST : 'B') : null;
        $explicit = array_key_exists('estado', $data) && $data['estado'] !== null;
        $state = $explicit ? (string) $data['estado'] : ($previous ?? 'B');
        $newSendDate = ! empty($data['fecha_envio'])
            && (! $before || substr((string) $before->PEDDENV, 0, 19) !== $data['fecha_envio']);
        if (! $explicit && $state === 'B' && $newSendDate) {
            $state = 'E';
        }
        if ($state !== $previous) {
            if (! $this->statePermitted($state, $finalLines)) {
                throw new BusinessRuleException('«Recibido» y «Recibido en parte» los ponen las entregas. Con algo ya recibido, el pedido solo puede cancelarse.');
            }
            $sendDate = array_key_exists('fecha_envio', $data) ? $data['fecha_envio'] : ($before->PEDDENV ?? null);
            if ($state === 'E' && empty($sendDate)) {
                $data['fecha_envio'] = $now;
            }
        }
        $data['estado'] = $state;

        if ($before && $supplierChanged && ($received || in_array($state, ['R', 'C'], true))) {
            throw new BusinessRuleException('El proveedor no se puede cambiar: el pedido ya tiene algo recibido o está recibido o cancelado');
        }
        if ($lines !== null && $before && in_array($state, ['R', 'C'], true)) {
            throw new BusinessRuleException('Las líneas de un pedido recibido o cancelado no se pueden cambiar. Si se canceló por error, cambie antes su estado.');
        }

        // Requisitos de compra del proveedor (RequisitosProveedor), al crear si vienen vacíos
        if (! $before && trim((string) ($data['requisitos'] ?? '')) === '') {
            $data['requisitos'] = (string) ($db->table('SINPRO')->where('DEL3COD', $proDel)->where('PRO1COD', $proCod)->value('PROCREQ') ?? '');
        }

        if ($supplierChanged && ($warning = VeolabPurchases::supplierWarning($proDel, $proCod)) !== '') {
            $this->warnings[] = $warning;
        }

        if ($lines !== null) {
            $data['total'] = round(array_sum(array_column($lines, 'importe')), 5);
        } elseif (! $before) {
            $data['total'] = 0;
        }
        $data['_lineas'] = $lines;

        return $data;
    }

    /** Líneas, como Grabar: se reescriben conservando su número. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if ($data['_lineas'] === null) {
            return $data;
        }
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];

        $db->table('ALMLPE')->where('PED3DEL', $del)->where('PED3SER', $ser)->where('PED3COD', $cod)->delete();
        foreach ($data['_lineas'] as $i => $line) {
            $db->table('ALMLPE')->insert([
                'PED3DEL' => $del, 'PED3SER' => $ser, 'PED3COD' => $cod, 'LPE1COD' => $line['linea'],
                'PRD2DEL' => $line['producto_codigo'] !== '' ? $line['producto_delegacion'] : null,
                'PRD2COD' => $line['producto_codigo'] !== '' ? $line['producto_codigo'] : null,
                'LPECREF' => mb_substr($line['referencia'], 0, 30),
                'LPECDES' => mb_substr($line['descripcion'], 0, 255),
                'LPENCAN' => $line['cantidad'],
                'LPENREC' => $line['recibido'],
                'LPENPRE' => $line['precio'],
                'LPECDTO' => mb_substr($line['descuento'], 0, 15),
                'LPENTOT' => $line['importe'],
                'LPENORD' => $i + 1,
                'FOR2COD' => $line['formato_codigo'] > 0 ? $line['formato_codigo'] : null,
                'LPECFOR' => $line['formato'] !== '' ? mb_substr($line['formato'], 0, 50) : null,
                'LPENFAC' => $line['contenido'] > 0 ? $line['contenido'] : null,
            ]);
        }

        // Como la ficha: un suceso de campo "ALMLPE" al cambiar las líneas de un pedido grabado
        if ($this->before) {
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'ALMPED', $this->auditRow($keys), 'ALMLPE');
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabPurchases::assertModule();
        // BorradoFisicoPermitido: solo si ninguna línea de entrega lo cita
        $received = DB::connection('dynamic')->table('ALMLAL')->where('LPE2DEL', (string) $keys['delegacion'])
            ->where('LPE2SER', (string) $keys['serie'])->where('LPE2COD', $keys['codigo'])->exists();
        if ($received) {
            throw new BusinessRuleException('El pedido no puede ser eliminado porque ya se ha recibido parte de él. Puede cancelarlo o archivarlo.');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('ALMLPE')->where('PED3DEL', (string) $keys['delegacion'])
            ->where('PED3SER', (string) $keys['serie'])->where('PED3COD', $keys['codigo'])->delete();
    }

    /** Como la ficha, sin el total (que se calcula) entre los campos auditados. */
    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        unset($dbData['PEDNTOT']);
        parent::auditUpdated($before, $dbData, $keyParams);
    }

    /** Líneas de cada pedido, en el orden de la rejilla, con lo pendiente de recibir. */
    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        foreach ($rows as &$row) {
            $row['lineas'] = $db->table('ALMLPE')
                ->where('PED3DEL', (string) $row['delegacion'])->where('PED3SER', (string) $row['serie'])->where('PED3COD', $row['codigo'])
                ->orderBy('LPENORD')->orderBy('LPE1COD')->get()
                ->map(fn ($l) => [
                    'linea'               => (int) $l->LPE1COD,
                    'producto_delegacion' => $l->PRD2COD !== null && $l->PRD2COD !== '' ? (string) $l->PRD2DEL : null,
                    'producto_codigo'     => $l->PRD2COD !== null && $l->PRD2COD !== '' ? (string) $l->PRD2COD : null,
                    'referencia'          => $l->LPECREF,
                    'descripcion'         => $l->LPECDES,
                    'formato_codigo'      => (int) $l->FOR2COD > 0 ? (int) $l->FOR2COD : null,
                    'formato'             => $l->LPECFOR,
                    'contenido'           => $l->LPENFAC,
                    'cantidad'            => $l->LPENCAN,
                    'precio'              => $l->LPENPRE,
                    'descuento'           => $l->LPECDTO,
                    'importe'             => $l->LPENTOT,
                    'cantidad_recibida'   => $l->LPENREC,
                    'cantidad_pendiente'  => round(max((float) $l->LPENCAN - (float) $l->LPENREC, 0), 5),
                    'orden'               => (int) $l->LPENORD,
                ])->all();
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Líneas
    // ------------------------------------------------------------------

    /** Líneas grabadas por número: lo que hace falta para conservarlas. */
    private function storedLines(array $keys): array
    {
        $out = [];
        $rows = DB::connection('dynamic')->table('ALMLPE')->where('PED3DEL', (string) $keys['delegacion'])
            ->where('PED3SER', (string) $keys['serie'])->where('PED3COD', $keys['codigo'])
            ->orderBy('LPENORD')->orderBy('LPE1COD')->get();
        foreach ($rows as $l) {
            $out[(int) $l->LPE1COD] = [
                'linea'               => (int) $l->LPE1COD,
                'producto_delegacion' => (string) $l->PRD2DEL,
                'producto_codigo'     => (string) $l->PRD2COD,
                'referencia'          => (string) $l->LPECREF,
                'descripcion'         => (string) $l->LPECDES,
                'cantidad'            => (float) $l->LPENCAN,
                'recibido'            => (float) $l->LPENREC,
                'precio'              => (float) $l->LPENPRE,
                'descuento'           => (string) $l->LPECDTO,
                'importe'             => (float) $l->LPENTOT,
                'formato_codigo'      => (int) $l->FOR2COD,
                'formato'             => (string) $l->LPECFOR,
                'contenido'           => (float) $l->LPENFAC,
            ];
        }

        return $out;
    }

    /**
     * Rejilla nueva a partir de la petición: valida productos y formatos,
     * propone referencia, precio, formato y descripción como AñadirProducto y
     * conserva el número y lo recibido de las líneas grabadas.
     */
    private function prepareLines(array $input, array $stored, string $proDel, string $proCod): array
    {
        $db = DB::connection('dynamic');
        $out = [];
        $used = [];
        $next = max(array_merge([0], array_keys($stored)));
        foreach ($input as $line) {
            $number = (int) ($line['linea'] ?? 0);
            if ($number > 0) {
                if (isset($used[$number])) {
                    throw new BusinessRuleException("La línea {$number} está repetida");
                }
                $used[$number] = true;
                $next = max($next, $number);
            }
        }

        foreach ($input as $i => $line) {
            $position = $i + 1;
            $number = (int) ($line['linea'] ?? 0);
            if ($number <= 0) {
                $number = ++$next;
            }
            $previous = $stored[$number] ?? null;
            $received = $previous ? $previous['recibido'] : 0.0;

            $prdDel = (string) ($line['producto_delegacion'] ?? '');
            $prdCod = trim((string) ($line['producto_codigo'] ?? ''));
            if ($prdCod === '') {
                $prdDel = '';
            } elseif (! $db->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)->exists()) {
                throw new BusinessRuleException("El producto {$prdCod} de la línea {$position} no existe");
            }
            if ($previous && $received != 0 && ($previous['producto_delegacion'] !== $prdDel || $previous['producto_codigo'] !== $prdCod)) {
                throw new BusinessRuleException("La línea {$number} ya tiene cantidad recibida: no se puede cambiar su producto");
            }

            // Referencia y precio del proveedor (PrecioProveedor), formato (ALM_FormatoCompra) y descripción del producto
            [$reference, $price] = $prdCod !== '' && (! array_key_exists('referencia', $line) || ! array_key_exists('precio', $line))
                ? VeolabPurchases::supplierPrice($proDel, $proCod, $prdDel, $prdCod) : ['', 0.0];
            $reference = array_key_exists('referencia', $line) ? (string) ($line['referencia'] ?? '') : $reference;
            $price = array_key_exists('precio', $line) ? (float) ($line['precio'] ?? 0) : $price;
            [$format, $formatText, $factor] = $this->lineFormat($line, $proDel, $proCod, $prdDel, $prdCod);

            $description = array_key_exists('descripcion', $line) ? (string) ($line['descripcion'] ?? '')
                : ($prdCod !== '' ? (string) $db->table('ALMPRD')->where('DEL3COD', $prdDel)->where('PRD1COD', $prdCod)->value('PRDCDES') : '');
            $quantity = array_key_exists('cantidad', $line) ? (float) ($line['cantidad'] ?? 0) : 1.0;
            if ($quantity < $received) {
                throw new BusinessRuleException("La cantidad de la línea {$position} no puede ser menor que la ya recibida ({$received}).");
            }
            $discount = VeolabPurchases::discount($line['descuento'] ?? '');
            if (mb_strlen($discount) > 15) {
                throw new BusinessRuleException("El descuento de la línea {$position} no puede superar los 15 caracteres");
            }

            $out[] = [
                'linea'               => $number,
                'producto_delegacion' => $prdDel,
                'producto_codigo'     => $prdCod,
                'referencia'          => $reference,
                'descripcion'         => $description,
                'cantidad'            => round($quantity, 5),
                'recibido'            => $received,
                'precio'              => round($price, 5),
                'descuento'           => $discount,
                'importe'             => VeolabPurchases::lineAmount($quantity, $price, $discount),
                'formato_codigo'      => $format,
                'formato'             => $formatText,
                'contenido'           => $factor,
            ];
        }

        // QuitarLineas: las líneas con cantidad recibida no se pueden quitar
        $kept = array_column($out, 'linea');
        foreach ($stored as $number => $line) {
            if ($line['recibido'] != 0 && ! in_array($number, $kept, true)) {
                throw new BusinessRuleException('Las líneas con cantidad recibida no se pueden quitar.');
            }
        }

        return $out;
    }

    /**
     * Formato de la línea: el indicado (con su contenido o el del formato), ninguno
     * si se envía vacío, o el que propone ALM_FormatoCompra si no se envía.
     */
    private function lineFormat(array $line, string $proDel, string $proCod, string $prdDel, string $prdCod): array
    {
        $factor = isset($line['contenido']) ? (float) $line['contenido'] : null;
        if (! array_key_exists('formato_codigo', $line)) {
            if ($prdCod === '') {
                return [0, '', $factor ?? 0.0];
            }
            [$format, $text, $proposed] = VeolabPurchases::purchaseFormat($proDel, $proCod, $prdDel, $prdCod);

            return [$format, $text, $factor ?? $proposed];
        }
        $format = (int) ($line['formato_codigo'] ?? 0);
        if ($format <= 0) {
            return [0, '', $factor ?? 0.0];
        }
        [$text, $content] = VeolabPurchases::chosenFormat($prdDel, $prdCod, $format);

        return [$format, $text, $factor ?? $content];
    }

    /** CompletarPreciosProveedor: al cambiar de proveedor, las líneas sin formato o sin referencia ni precio. */
    private function completeLines(array $stored, string $proDel, string $proCod): array
    {
        $out = [];
        foreach ($stored as $line) {
            if ($line['producto_codigo'] !== '') {
                if ($line['formato_codigo'] === 0) {
                    [$line['formato_codigo'], $line['formato'], $line['contenido']] =
                        VeolabPurchases::purchaseFormat($proDel, $proCod, $line['producto_delegacion'], $line['producto_codigo']);
                }
                if ($line['referencia'] === '' && $line['precio'] == 0) {
                    [$reference, $price] = VeolabPurchases::supplierPrice($proDel, $proCod, $line['producto_delegacion'], $line['producto_codigo']);
                    if ($reference !== '' || $price != 0) {
                        $line['referencia'] = $reference;
                        $line['precio'] = $price;
                        $line['importe'] = VeolabPurchases::lineAmount($line['cantidad'], $price, $line['descuento']);
                    }
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * EstadoPermitido: borrador o enviado mientras no haya llegado nada;
     * cancelado siempre; recibido y recibido en parte solo si es el que
     * corresponde a lo recibido (EstadoSegunRecibido).
     */
    private function statePermitted(string $state, array $lines): bool
    {
        $byReceived = '';
        $any = false;
        $missing = false;
        foreach ($lines as $line) {
            if ($line['recibido'] != 0) {
                $any = true;
            }
            if ($line['recibido'] < $line['cantidad']) {
                $missing = true;
            }
        }
        if ($any) {
            $byReceived = $missing ? 'P' : 'R';
        }

        return match ($state) {
            'C'      => true,
            'B', 'E' => $byReceived === '',
            default  => $state === $byReceived,
        };
    }
}
