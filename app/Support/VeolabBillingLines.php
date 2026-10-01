<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Rejilla de líneas de los documentos de venta (presupuestos: FACLIP,
 * contratos: FACLIC, facturas: FACLIF), réplica de la rejilla de servicios
 * de FichaPresupuesto / FichaContrato / FichaFactura.
 *
 * Tipos de línea (Facturacion.bas):
 *  - Grupos: S servicio, O operación (solo facturas), L línea de grupo
 *    libre; especiales (su total es
 *    siempre la suma de sus líneas): E técnicas sueltas, A gastos, U suplidos.
 *  - Detalle: T técnica, G gasto, D línea libre. Una línea de detalle cuelga
 *    del grupo anterior más próximo (o de ninguno si no lo hay).
 *
 * Totales (CalcularPreciosDetalles / CalcularSubtotalPresupuesto): con
 * desglose por técnica el grupo suma sus líneas; por servicio o sin desglose
 * vale su propio precio (o la suma si es cero y LABCON.CONBDPZ). El subtotal
 * suma las líneas sin padre; el grupo de suplidos va aparte.
 */
class VeolabBillingLines
{
    public const TYPES = ['S', 'T', 'G', 'D', 'L', 'E', 'A', 'U', 'O'];

    private const GROUPS = ['S', 'O', 'L', 'E', 'A', 'U'];
    private const SPECIAL_GROUPS = ['E', 'A', 'U'];

    /** Texto por defecto de los grupos especiales: clave de idioma y respaldo. */
    private const GROUP_LABELS = [
        'U' => ['ETI00451', 'Suplidos'],
        'A' => ['ETI00489', 'Gastos adicionales'],
        'E' => ['ETI00490', 'Técnicas'],
    ];

    /**
     * Cabecera => [tabla de líneas, prefijo, columnas de la clave, ¿punto de
     * muestreo?, grupos que por servicio o sin desglose suman sus líneas,
     * ¿columnas de factura? (fecha, adicional, cliente y operación)].
     * (CalcularPreciosDetalles: las fichas suman los tres grupos especiales;
     * la de contrato solo sumaba el de suplidos, corregido en Veolab 2.4.4.)
     */
    private const TABLES = [
        'FACPRE' => ['FACLIP', 'LIP', ['PRE3DEL', 'PRE3SER', 'PRE3COD'], true, ['E', 'A', 'U'], false],
        'FACCON' => ['FACLIC', 'LIC', ['CON3DEL', 'CON3SER', 'CON3COD'], false, ['E', 'A', 'U'], false],
        'FACFAC' => ['FACLIF', 'LIF', ['FAC3DEL', 'FAC3SER', 'FAC3COD'], true, ['E', 'A', 'U'], true],
    ];

    /**
     * Contexto de precios: cliente, tarifa y configuración (precios por
     * tarifa, desglose a precio cero, grupos expandidos y marca de
     * acreditación de las técnicas si el documento es acreditado).
     */
    public static function context(string $clientDel, string $clientCode, string $tariffDel, int $tariffCode, bool $accredited): object
    {
        $config = DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)
            ->first(['CONBTAR', 'CONBDPZ', 'CONBEXP', 'CONCMTE', 'CONCTEM', 'CONCPOM']);

        return (object) [
            'perTariff'    => $config && $config->CONBTAR === 'T',
            'zeroDetail'   => $config && $config->CONBDPZ === 'T',
            'expand'       => $config && $config->CONBEXP === 'T',
            'clientDel'    => $clientDel,
            'clientCode'   => $clientCode,
            'tariffDel'    => $tariffDel,
            'tariffCode'   => $tariffCode > 0 ? (string) $tariffCode : '',
            'budget'       => null,
            'markMode'     => $accredited ? (string) ($config->CONCMTE ?? '') : '',
            'mark'         => (string) ($config->CONCTEM ?? ''),
            'markPosition' => (string) ($config->CONCPOM ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // Construcción de la rejilla
    // ------------------------------------------------------------------

    /**
     * AñadirServicio: cada servicio con sus técnicas (LABSYT) y sus gastos
     * (LABSYE); los gastos suplidos van juntos en un grupo al final. La
     * cantidad del servicio se traslada a sus líneas, como en la ficha.
     *
     * @param  array  $services  [{delegacion, codigo, cantidad?, punto_muestreo_codigo?}]
     * @return array líneas con el formato de entrada de fromInput()
     */
    public static function inputFromServices(array $services): array
    {
        $db = DB::connection('dynamic');
        $input = [];
        $supplied = [];

        foreach ($services as $service) {
            $del = (string) ($service['delegacion'] ?? '');
            $cod = (string) $service['codigo'];
            $quantity = $service['cantidad'] ?? 1;

            $input[] = [
                'tipo'                  => 'S',
                'servicio_delegacion'   => $del,
                'servicio_codigo'       => $cod,
                'cantidad'              => $quantity,
                'punto_muestreo_codigo' => $service['punto_muestreo_codigo'] ?? null,
            ];

            $techniques = $db->table('LABSYT')->where('DEL3SER', $del)->where('SER3COD', $cod)
                ->orderBy('SYTNORD')->get(['DEL3TEC', 'TEC3COD']);
            foreach ($techniques as $t) {
                $input[] = ['tipo' => 'T', 'tecnica_delegacion' => (string) $t->DEL3TEC,
                    'tecnica_codigo' => (string) $t->TEC3COD, 'cantidad' => $quantity];
            }

            $expenses = $db->table('LABSYE')
                ->leftJoin('LABESC', function ($join) {
                    $join->on('LABSYE.DEL3ESC', '=', 'LABESC.DEL3COD')->on('LABSYE.ESC3COD', '=', 'LABESC.ESC1COD');
                })
                ->where('LABSYE.DEL3SER', $del)->where('LABSYE.SER3COD', $cod)
                ->orderBy('LABSYE.DEL3ESC')->orderBy('LABSYE.ESC3COD')
                ->get(['LABSYE.DEL3ESC', 'LABSYE.ESC3COD', 'LABESC.ESCBSUP']);
            foreach ($expenses as $e) {
                $line = ['tipo' => 'G', 'gasto_delegacion' => (string) $e->DEL3ESC, 'gasto_codigo' => (int) $e->ESC3COD];
                if ($e->ESCBSUP === 'T') {
                    $supplied[] = $line;
                } else {
                    $input[] = $line + ['cantidad' => $quantity];
                }
            }
        }

        if ($supplied) {
            $input[] = ['tipo' => 'U'];
            array_push($input, ...$supplied);
        }

        return $input;
    }

    /**
     * Líneas indicadas en la petición, en su orden. Lo que no se indica (o va
     * a null) toma el valor que pondría Veolab al añadir la línea: nombre en
     * informes, referencia y precio/descuento del servicio, técnica o gasto.
     *
     * @return array [líneas, ¿se han indicado precios o descuentos?]
     */
    public static function fromInput(array $input, object $ctx): array
    {
        $db = DB::connection('dynamic');
        $lines = [];
        $modified = false;

        foreach (array_values($input) as $i => $in) {
            $n = $i + 1;
            $type = (string) ($in['tipo'] ?? 'D');
            $given = fn (string $key) => isset($in[$key]);
            $line = self::emptyLine($type);

            switch ($type) {
                case 'S':
                    [$del, $cod] = self::entityKey($in, 'servicio', "Línea {$n}: falta el servicio");
                    $service = $db->table('LABSER')->where('DEL3COD', $del)->where('SER1COD', $cod)->first(['SERCNOM', 'SERCNOI']);
                    if (! $service) {
                        throw new BusinessRuleException("Línea {$n}: el servicio {$cod} no existe");
                    }
                    $line['del'] = $del;
                    $line['cod'] = $cod;
                    $line['ref'] = VeolabCodes::format('LABSER', $cod, $del);
                    $line['desc'] = (string) ($service->SERCNOI ?: $service->SERCNOM);
                    [$line['price'], $line['discount']] = VeolabOperationServices::servicePrice($del, $cod, $ctx);
                    [$line['secDel'], $line['secCod']] = self::serviceSection($del, $cod);
                    $line['point'] = self::samplingPoint($in, $ctx, $n);
                    break;

                case 'T':
                    [$del, $cod] = self::entityKey($in, 'tecnica', "Línea {$n}: falta la técnica");
                    $tec = $db->table('LABTEC')->where('DEL3COD', $del)->where('TEC1COD', $cod)->first();
                    if (! $tec) {
                        throw new BusinessRuleException("Línea {$n}: la técnica {$cod} no existe");
                    }
                    $line['del'] = $del;
                    $line['cod'] = $cod;
                    $line['desc'] = self::techniqueName($tec, $ctx);
                    [$line['price'], $line['discount']] = VeolabOperationServices::techniquePrice($tec, $ctx);
                    $line['secDel'] = (string) $tec->SEC2DEL;
                    $line['secCod'] = (int) $tec->SEC2COD;
                    break;

                case 'G':
                    [$del, $cod] = self::entityKey($in, 'gasto', "Línea {$n}: falta el gasto");
                    $expense = $db->table('LABESC')->where('DEL3COD', $del)->where('ESC1COD', (int) $cod)
                        ->first(['ESCCDES', 'ESCNPRE', 'ESCCDTO']);
                    if (! $expense) {
                        throw new BusinessRuleException("Línea {$n}: el gasto {$cod} no existe");
                    }
                    $line['del'] = $del;
                    $line['cod'] = $cod;
                    $line['desc'] = (string) $expense->ESCCDES;
                    $line['price'] = VeolabOperationServices::decimal($expense->ESCNPRE);
                    $line['discount'] = (string) ($expense->ESCCDTO ?? '');
                    break;

                case 'O':
                    [$del, $cod] = self::entityKey($in, 'operacion', "Línea {$n}: falta la operación");
                    $ser = (string) ($in['operacion_serie'] ?? '');
                    $operation = $db->table('LABOPE')->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', (int) $cod)
                        ->first(['OPECDES', 'OPENPRE', 'OPECDTO', 'OPECINF']);
                    if (! $operation) {
                        throw new BusinessRuleException("Línea {$n}: la operación {$cod} no existe");
                    }
                    $line['del'] = $del;
                    $line['ser'] = $ser;
                    $line['cod'] = (string) (int) $cod;
                    $line['ref'] = VeolabCodes::format('LABOPE', (string) (int) $cod, $del, $ser, (string) $operation->OPECINF);
                    $line['desc'] = (string) $operation->OPECDES;
                    $line['price'] = VeolabOperationServices::decimal($operation->OPENPRE);
                    $line['discount'] = (string) ($operation->OPECDTO ?? '');
                    break;

                case 'U':
                case 'A':
                case 'E':
                    $line['desc'] = self::groupLabel($type);
                    break;
            }

            // Columnas de las líneas de factura.
            if ($given('fecha')) {
                $line['date'] = (new \DateTime((string) $in['fecha']))->format('Y-m-d 00:00:00');
            }
            if ($given('adicional')) {
                $line['extra'] = (string) $in['adicional'];
            }
            if ($given('cliente_codigo')) {
                $line['lineClientDel'] = (string) ($in['cliente_delegacion'] ?? '');
                $line['lineClientCod'] = (string) $in['cliente_codigo'];
            } elseif ($line['point'] > 0) {
                $line['lineClientDel'] = $ctx->clientDel;
                $line['lineClientCod'] = $ctx->clientCode;
            }

            if ($given('referencia')) {
                $line['ref'] = (string) $in['referencia'];
            }
            if ($given('descripcion')) {
                $line['desc'] = (string) $in['descripcion'];
            }

            if (in_array($type, self::SPECIAL_GROUPS, true)) {
                // El grupo especial no tiene cantidad ni precio: suma sus líneas.
                $line['qty'] = 0.0;
            } else {
                if ($given('cantidad')) {
                    $line['qty'] = (float) $in['cantidad'];
                }
                if ($given('precio')) {
                    $line['price'] = (float) $in['precio'];
                    $modified = true;
                }
                if ($given('descuento')) {
                    $line['discount'] = trim((string) $in['descuento']);
                    $modified = true;
                }
            }

            $isGroup = in_array($type, self::GROUPS, true);
            $line['highlighted'] = ($in['es_destacada'] ?? 'F') === 'T';
            $line['collapsed'] = $isGroup && ($given('es_agrupada') ? $in['es_agrupada'] === 'T'
                : (($type === 'S' || $type === 'O') && ! $ctx->expand));

            $lines[] = $line;
        }

        return [$lines, $modified];
    }

    /** Línea vacía con todos los campos de la representación interna. */
    public static function emptyLine(string $type): array
    {
        return ['type' => $type, 'ref' => '', 'desc' => '', 'qty' => 1.0, 'price' => 0.0, 'discount' => '',
            'del' => '', 'ser' => '', 'cod' => '', 'point' => 0, 'secDel' => '', 'secCod' => 0,
            'date' => null, 'extra' => '', 'lineClientDel' => '', 'lineClientCod' => '',
            'highlighted' => false, 'collapsed' => false];
    }

    /** Líneas guardadas de un documento, con el mismo formato que fromInput(). */
    public static function stored(string $header, array $key): array
    {
        [$table, $p, $keyColumns, $hasPoint, , $invoice] = self::TABLES[$header];

        return DB::connection('dynamic')->table($table)->where(array_combine($keyColumns, $key))
            ->orderBy("{$p}1COD")->get()
            ->map(function ($row) use ($p, $hasPoint, $invoice) {
                $type = (string) ($row->{"{$p}CTIP"} ?: 'D');
                [$del, $cod] = match ($type) {
                    'S'     => [(string) $row->SER2DEL, (string) $row->SER2COD],
                    'T'     => [(string) $row->TEC2DEL, (string) $row->TEC2COD],
                    'G'     => [(string) $row->ESC2DEL, (string) $row->ESC2COD],
                    'O'     => $invoice ? [(string) $row->OPE2DEL, (string) $row->OPE2COD] : ['', ''],
                    default => ['', ''],
                };

                return [
                    'ser'           => $invoice && $type === 'O' ? (string) $row->OPE2SER : '',
                    'date'          => $invoice ? $row->LIFDFEC : null,
                    'extra'         => $invoice ? (string) $row->LIFCADI : '',
                    'lineClientDel' => $invoice ? (string) $row->CLI2DEL : '',
                    'lineClientCod' => $invoice ? (string) $row->CLI2COD : '',
                    'type'        => $type,
                    'ref'         => (string) $row->{"{$p}CREF"},
                    'desc'        => (string) $row->{"{$p}CDES"},
                    'qty'         => (float) $row->{"{$p}NCAN"},
                    'price'       => (float) $row->{"{$p}NPRE"},
                    'discount'    => (string) $row->{"{$p}CDTO"},
                    'del'         => $del,
                    'cod'         => $cod,
                    'point'       => $hasPoint ? (int) $row->PUM2COD : 0,
                    'secDel'      => (string) $row->SEC2DEL,
                    'secCod'      => (int) $row->SEC2COD,
                    'highlighted' => $row->{"{$p}BDES"} === 'T',
                    'collapsed'   => $row->{"{$p}BAGR"} === 'T',
                ];
            })->all();
    }

    /**
     * RegenerarPrecios (al cambiar de cliente o de tarifa): servicios y
     * técnicas toman el precio y el descuento que les corresponden ahora; un
     * precio a cero no sustituye al que tenía la línea.
     */
    public static function reprice(array $lines, object $ctx): array
    {
        $db = DB::connection('dynamic');

        foreach ($lines as $i => $line) {
            if ($line['type'] === 'S') {
                [$price, $discount] = self::listedServicePrice($line['del'], $line['cod'], $ctx);
            } elseif ($line['type'] === 'T') {
                $tec = $db->table('LABTEC')->where('DEL3COD', $line['del'])->where('TEC1COD', $line['cod'])->first();
                [$price, $discount] = $tec ? VeolabOperationServices::techniquePrice($tec, $ctx) : [0.0, ''];
            } else {
                continue;
            }

            if ($price != 0) {
                $lines[$i]['price'] = $price;
            }
            if ($discount !== '' || $price != 0) {
                $lines[$i]['discount'] = $discount;
            }
        }

        return $lines;
    }

    // ------------------------------------------------------------------
    // Totales
    // ------------------------------------------------------------------

    /**
     * Calcula padre, total y marcas de cada línea (mostrar precio: LIPBVER,
     * como EstadoColumnas; importe computable: LIPBIMC, como Grabar).
     *
     * @return array [subtotal, suplidos]
     */
    public static function compute(string $header, array &$lines, string $breakdown, object $ctx): array
    {
        [, , , , $summed, $invoice] = self::TABLES[$header];
        $parent = null;
        $sums = [];
        foreach ($lines as $i => $line) {
            $isGroup = in_array($line['type'], self::GROUPS, true);
            $lines[$i]['parent'] = $isGroup ? null : $parent;
            $lines[$i]['total'] = self::lineTotal($line['price'], $line['discount'], $line['qty']);
            if ($isGroup) {
                $parent = $i;
            } elseif ($parent !== null) {
                $sums[$parent] = ($sums[$parent] ?? 0) + $lines[$i]['total'];
            }
        }

        foreach ($lines as $i => $line) {
            if (! in_array($line['type'], self::GROUPS, true)) {
                continue;
            }
            $children = round($sums[$i] ?? 0, 2);

            if ($breakdown === 'T' || in_array($line['type'], $summed, true)) {
                $lines[$i]['total'] = $children;
            } elseif ($line['price'] == 0 && $ctx->zeroDetail) {
                // Grupo a precio cero: se desglosa en sus líneas.
                $lines[$i]['total'] = $children;
                $lines[$i]['discount'] = '';
            }
        }

        $subtotal = 0.0;
        $supplied = 0.0;
        foreach ($lines as $i => $line) {
            $isGroup = in_array($line['type'], self::GROUPS, true);
            $isSpecial = in_array($line['type'], self::SPECIAL_GROUPS, true);

            if ($line['parent'] === null) {
                $lines[$i]['visible'] = $breakdown === 'T' ? ! $isGroup : ! $isSpecial;
                if ($line['type'] === 'U') {
                    $supplied += $line['total'];
                } else {
                    $subtotal += $line['total'];
                }
            } else {
                $group = $lines[$line['parent']];
                $lines[$i]['visible'] = $breakdown === 'T'
                    || in_array($group['type'], self::SPECIAL_GROUPS, true)
                    || ($group['price'] == 0 && $ctx->zeroDetail);
            }

            // Grabar: en la factura también el gasto y la línea libre sueltos cuentan.
            $lines[$i]['computable'] = match ($line['type']) {
                'T'     => $line['parent'] === null,
                'G'     => $invoice && $line['parent'] === null,
                'D'     => ! $invoice || $line['parent'] === null,
                default => true,
            };

            // En la factura el servicio toma la sección de su primera técnica.
            $next = $lines[$i + 1] ?? null;
            if ($invoice && $line['type'] === 'S' && $next && $next['type'] === 'T') {
                $lines[$i]['secDel'] = $next['secDel'];
                $lines[$i]['secCod'] = $next['secCod'];
            }
        }

        return [round($subtotal, 2), round($supplied, 2)];
    }

    /** FAC_CalcularTotalLinea: precio × cantidad menos el descuento ("10%" o importe). */
    public static function lineTotal(float $price, string $discount, float $quantity): float
    {
        return VeolabOperationServices::withDiscount($price * $quantity, $discount);
    }

    /** FAC_CalcularImporteImpuesto: porcentaje sobre la base o importe fijo. */
    public static function tax(float $base, string $value): float
    {
        $pos = strpos($value, '%');
        $amount = $pos !== false
            ? $base * VeolabOperationServices::decimal(substr($value, 0, $pos)) / 100
            : VeolabOperationServices::decimal($value);

        return round($amount, 2);
    }

    /**
     * Descuento o impuesto en texto ("10,5%", "3.20") con el separador
     * decimal del laboratorio, para que Veolab lo lea igual que la API.
     */
    public static function localized(string $value): string
    {
        $separator = VeolabResults::decimalSeparator();

        return strtr($value, ['.' => $separator, ',' => $separator]);
    }

    // ------------------------------------------------------------------
    // Grabación y lectura
    // ------------------------------------------------------------------

    /** Sustituye las líneas del documento (Grabar borra e inserta la rejilla). */
    public static function save(string $header, array $key, array $lines): void
    {
        [$table, $p, $keyColumns, $hasPoint, , $invoice] = self::TABLES[$header];
        $db = DB::connection('dynamic');
        $keyValues = array_combine($keyColumns, $key);

        $db->table($table)->where($keyValues)->delete();

        $rows = [];
        foreach (array_values($lines) as $i => $line) {
            $type = $line['type'];
            $rows[] = $keyValues + [
                "{$p}1COD" => $i + 1,
                "{$p}CREF" => mb_substr($line['ref'], 0, 50),
                "{$p}CDES" => mb_substr($line['desc'], 0, 255),
                "{$p}NCAN" => $line['qty'],
                "{$p}NPRE" => $line['price'],
                "{$p}CDTO" => mb_substr(self::localized($line['discount']), 0, 15),
                "{$p}NTOT" => $line['total'],
                "{$p}BAGR" => $line['collapsed'] ? 'T' : 'F',
                "{$p}BDES" => $line['highlighted'] ? 'T' : '',
                "{$p}CTIP" => $type,
                "{$p}BIMC" => $line['computable'] ? 'T' : 'F',
                "{$p}BVER" => $line['visible'] ? 'T' : 'F',
                'SER2DEL'  => $type === 'S' ? $line['del'] : '',
                'SER2COD'  => $type === 'S' ? $line['cod'] : '',
                'TEC2DEL'  => $type === 'T' ? $line['del'] : '',
                'TEC2COD'  => $type === 'T' ? $line['cod'] : '',
                'ESC2DEL'  => $type === 'G' ? $line['del'] : '',
                'ESC2COD'  => $type === 'G' ? (int) $line['cod'] : 0,
                'SEC2DEL'  => $line['secDel'],
                'SEC2COD'  => $line['secCod'],
            ] + ($hasPoint ? ['PUM2COD' => ($invoice || $type === 'S') ? $line['point'] : 0] : [])
              + ($invoice ? [
                'LIFDFEC' => $line['date'],
                'LIFCADI' => mb_substr($line['extra'], 0, 50),
                'CLI2DEL' => $line['lineClientDel'],
                'CLI2COD' => $line['lineClientCod'],
                'OPE2DEL' => $type === 'O' ? $line['del'] : '',
                'OPE2SER' => $type === 'O' ? $line['ser'] : '',
                'OPE2COD' => $type === 'O' ? (int) $line['cod'] : 0,
            ] : []);
        }

        if ($rows) {
            $db->table($table)->insert($rows);
        }
    }

    public static function delete(string $header, array $key): void
    {
        [$table, , $keyColumns] = self::TABLES[$header];

        DB::connection('dynamic')->table($table)->where(array_combine($keyColumns, $key))->delete();
    }

    /**
     * Líneas de varios documentos para la lectura, agrupadas por la clave
     * del documento unida con "\x1B".
     *
     * @param  array  $keys  lista de claves [delegación, serie, código]
     */
    public static function read(string $header, array $keys): array
    {
        [$table, $p, $keyColumns, $hasPoint, , $invoice] = self::TABLES[$header];
        if ($keys === []) {
            return [];
        }

        $rows = DB::connection('dynamic')->table($table)
            ->where(function ($q) use ($keys, $keyColumns) {
                foreach ($keys as $key) {
                    $q->orWhere(fn ($w) => $w->where(array_combine($keyColumns, $key)));
                }
            })
            ->orderBy($keyColumns[0])->orderBy($keyColumns[1])->orderBy($keyColumns[2])->orderBy("{$p}1COD")
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $pair = function (string $del, string $cod, bool $numeric = false) use ($row) {
                $empty = $numeric ? (int) $row->{$cod} === 0 : (string) $row->{$cod} === '';

                return $empty ? [null, null] : [(string) $row->{$del}, $numeric ? (int) $row->{$cod} : (string) $row->{$cod}];
            };
            [$serDel, $serCod] = $pair('SER2DEL', 'SER2COD');
            [$tecDel, $tecCod] = $pair('TEC2DEL', 'TEC2COD');
            [$escDel, $escCod] = $pair('ESC2DEL', 'ESC2COD', true);
            [$secDel, $secCod] = $pair('SEC2DEL', 'SEC2COD', true);

            $line = [
                'codigo'              => (int) $row->{"{$p}1COD"},
                'tipo'                => (string) $row->{"{$p}CTIP"},
                'referencia'          => $row->{"{$p}CREF"},
                'descripcion'         => $row->{"{$p}CDES"},
                'cantidad'            => $row->{"{$p}NCAN"},
                'precio'              => $row->{"{$p}NPRE"},
                'descuento'           => $row->{"{$p}CDTO"},
                'total'               => $row->{"{$p}NTOT"},
                'es_agrupada'         => $row->{"{$p}BAGR"} === 'T' ? 'T' : 'F',
                'es_destacada'        => $row->{"{$p}BDES"} === 'T' ? 'T' : 'F',
                'mostrar_precio'      => $row->{"{$p}BVER"} === 'T' ? 'T' : 'F',
                'es_computable'       => $row->{"{$p}BIMC"} === 'T' ? 'T' : 'F',
                'servicio_delegacion' => $serDel,
                'servicio_codigo'     => $serCod,
                'tecnica_delegacion'  => $tecDel,
                'tecnica_codigo'      => $tecCod,
                'gasto_delegacion'    => $escDel,
                'gasto_codigo'        => $escCod,
                'seccion_delegacion'  => $secDel,
                'seccion_codigo'      => $secCod,
            ];
            if ($hasPoint) {
                $line['punto_muestreo_codigo'] = (int) $row->PUM2COD ?: null;
            }
            if ($invoice) {
                [$cliDel, $cliCod] = $pair('CLI2DEL', 'CLI2COD');
                [$opeDel, $opeCod] = $pair('OPE2DEL', 'OPE2COD', true);
                $line += [
                    'fecha'                => $row->LIFDFEC,
                    'adicional'            => $row->LIFCADI,
                    'cliente_delegacion'   => $cliDel,
                    'cliente_codigo'       => $cliCod,
                    'operacion_delegacion' => $opeDel,
                    'operacion_serie'      => $opeCod === null ? null : (string) $row->OPE2SER,
                    'operacion_codigo'     => $opeCod,
                ];
            }

            $out[implode("\x1B", [$row->{$keyColumns[0]}, $row->{$keyColumns[1]}, $row->{$keyColumns[2]}])][] = $line;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Datos de servicios, técnicas y gastos
    // ------------------------------------------------------------------

    private static function entityKey(array $in, string $group, string $message): array
    {
        $code = (string) ($in["{$group}_codigo"] ?? '');
        if ($code === '') {
            throw new BusinessRuleException($message);
        }

        return [(string) ($in["{$group}_delegacion"] ?? ''), $code];
    }

    /** Punto de muestreo de la línea de servicio: uno del cliente del documento. */
    private static function samplingPoint(array $in, object $ctx, int $n): int
    {
        $point = (int) ($in['punto_muestreo_codigo'] ?? 0);
        if ($point === 0) {
            return 0;
        }

        $exists = $ctx->clientCode !== '' && DB::connection('dynamic')->table('LABPUM')
            ->where('DEL3COD', $ctx->clientDel)->where('CLI3COD', $ctx->clientCode)->where('PUM1COD', $point)->exists();
        if (! $exists) {
            throw new BusinessRuleException("Línea {$n}: el punto de muestreo {$point} no existe para el cliente");
        }

        return $point;
    }

    /** CON_ObtenSeccionServicio: la sección de la primera técnica del servicio. */
    private static function serviceSection(string $del, string $cod): array
    {
        $row = DB::connection('dynamic')->table('LABSYT')
            ->leftJoin('LABTEC', function ($join) {
                $join->on('LABSYT.DEL3TEC', '=', 'LABTEC.DEL3COD')->on('LABSYT.TEC3COD', '=', 'LABTEC.TEC1COD');
            })
            ->where('LABSYT.DEL3SER', $del)->where('LABSYT.SER3COD', $cod)
            ->orderBy('LABSYT.SYTNORD')
            ->first(['LABTEC.SEC2DEL', 'LABTEC.SEC2COD']);

        return [(string) ($row?->SEC2DEL ?? ''), (int) ($row?->SEC2COD ?? 0)];
    }

    /**
     * EXP_ObtenNombreTecnicaMarcaAcreditacion: nombre en informes, con la
     * marca de LABCON en las técnicas acreditadas (CONCMTE 'A') o en las no
     * acreditadas ('N') cuando el documento es acreditado.
     */
    private static function techniqueName(object $tec, object $ctx): string
    {
        return self::markedName((string) ($tec->TECCNOI ?: $tec->TECCNOM), (string) ($tec->TECDACR ?? ''), $ctx);
    }

    /** Nombre con la marca de acreditación según $ctx->markMode ('' = sin marca). */
    public static function markedName(string $name, string $accreditationDate, object $ctx): string
    {
        $accredited = $accreditationDate !== '';

        $marked = match ($ctx->markMode) {
            'A'     => $accredited,
            'N'     => ! $accredited,
            default => false,
        };
        if (! $marked) {
            return $name;
        }

        return $ctx->markPosition === 'D' ? $name.$ctx->mark : $ctx->mark.$name;
    }

    /**
     * FAC_ObtenerPreciosServicios (el que usa RegenerarPrecios): precio de la
     * tarifa o del cliente; a cero, el del servicio, y su descuento solo si
     * tampoco había descuento.
     */
    private static function listedServicePrice(string $del, string $cod, object $ctx): array
    {
        $db = DB::connection('dynamic');
        $service = $db->table('LABSER')->where('DEL3COD', $del)->where('SER1COD', $cod)->first(['SERNPRE', 'SERCDTO']);
        if (! $service) {
            return [0.0, ''];
        }

        if ($ctx->perTariff) {
            $row = $db->table('LABSYF')->where('SER3DEL', $del)->where('SER3COD', $cod)
                ->where('TAR3DEL', $ctx->tariffDel)->where('TAR3COD', $ctx->tariffCode)->first(['SYFNPRE', 'SYFCDTO']);
            $price = VeolabOperationServices::decimal($row?->SYFNPRE);
            $discount = (string) ($row?->SYFCDTO ?? '');
        } else {
            $row = $db->table('LABSYC')->where('SER3DEL', $del)->where('SER3COD', $cod)
                ->where('CLI3DEL', $ctx->clientDel)->where('CLI3COD', $ctx->clientCode)->first(['SYCNPRE', 'SYCCDTO']);
            $price = VeolabOperationServices::decimal($row?->SYCNPRE);
            $discount = (string) ($row?->SYCCDTO ?? '');
        }

        if ($price == 0) {
            $price = VeolabOperationServices::decimal($service->SERNPRE);
            if ($discount === '') {
                $discount = (string) ($service->SERCDTO ?? '');
            }
        }

        return [$price, $discount];
    }

    /** Texto del grupo especial en el idioma principal de Veolab (IDICAD). */
    public static function groupLabel(string $type): string
    {
        [$key, $fallback] = self::GROUP_LABELS[$type];

        try {
            $text = DB::connection('dynamic')->table('IDICAD')->where('CAD1COD', $key)->where('IDI3COD', 1)->value('CADCDES');
        } catch (\Throwable $e) {
            $text = null;
        }

        return trim((string) $text) !== '' ? (string) $text : $fallback;
    }
}
