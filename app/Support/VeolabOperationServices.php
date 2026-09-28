<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Servicios de una operación nueva, replicando FichaOperacion.frm
 * (AñadirServicio + Grabar): LABOYS, LABRES, LABCOR, LABOYG, LABOYE, LABOYD,
 * consumos de almacén (ALMMOV + existencias) y los datos que la operación
 * toma de sus servicios (precio, técnicas, tipo, matriz, envases, compromiso).
 *
 * Se trabaja sobre la conexión 'dynamic' y dentro de la transacción del alta.
 * Referencias al código VB entre paréntesis.
 */
class VeolabOperationServices
{
    /** Separador de la lista de técnicas (PAR_strSeparadorLista en España). */
    private const LIST_SEPARATOR = ';';

    public static function add(string $del, string $ser, int $cod, array $services, array $opData): void
    {
        $db = DB::connection('dynamic');
        $op = $db->table('LABOPE')->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod)->first();
        $config = $db->table('LABCON')->where('CON1COD', 1)
            ->first(['CONBTAR', 'CONBSDP', 'CONBDPZ', 'CONBPRE', 'CONBAFC']);

        $ctx = (object) [
            'perTariff'   => $config && $config->CONBTAR === 'T',
            'zeroDetail'  => $config && $config->CONBDPZ === 'T',
            'defaultRes'  => $config && $config->CONBPRE === 'T',
            'commitment'  => $config && $config->CONBAFC === 'T',
            'clientDel'   => (string) $op->CLI2DEL,
            'clientCode'  => (string) $op->CLI2COD,
            'tariffDel'   => (string) $op->TAR2DEL,
            'tariffCode'  => (int) $op->TAR2COD > 0 ? (string) $op->TAR2COD : '',
            'budget'      => ($config && $config->CONBSDP === 'T' && (int) $op->PRE2COD > 0)
                ? [(string) $op->PRE2DEL, (string) $op->PRE2SER, (int) $op->PRE2COD] : null,
        ];

        // Servicios sin repetir, en el orden recibido.
        $serviceList = [];
        foreach ($services as $s) {
            $key = ($s['delegacion'] ?? '')."\x1B".$s['codigo'];
            $serviceList[$key] ??= ['del' => (string) ($s['delegacion'] ?? ''), 'cod' => (string) $s['codigo']];
        }

        $techniques = self::techniques($serviceList, $ctx);
        $expenses = self::expenses($serviceList);
        $grid = self::buildGrid($serviceList, $techniques, $expenses, $ctx);

        $breakdown = (string) ($op->OPECTID ?: 'S');
        $opPrice = self::computeTotals($grid, $breakdown, $ctx);

        self::saveServices($del, $ser, $cod, $grid);
        self::saveExpenses($del, $ser, $cod, $grid);
        self::saveTechniques($del, $ser, $cod, $grid, $ctx);

        if (VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'ALM')) {
            self::saveConsumptions($del, $ser, $cod, $grid);
        }

        self::updateOperation($op, $grid, $serviceList, $breakdown, $opPrice, $opData, $ctx);
    }

    // ------------------------------------------------------------------
    // Lectura: técnicas y gastos de los servicios
    // ------------------------------------------------------------------

    /**
     * Técnicas por servicio (InsertarTecnicasServicio). Un parámetro solo
     * puede estar una vez en la operación: se queda en el primer servicio
     * que lo aporta, en el orden de la consulta de Veolab (servicio y
     * SYTNORD, o líneas del presupuesto).
     *
     * @return array servicio => lista de técnicas
     */
    private static function techniques(array $serviceList, object $ctx): array
    {
        $db = DB::connection('dynamic');
        $rows = [];

        if ($ctx->budget) {
            [$preDel, $preSer, $preCod] = $ctx->budget;
            // LAB_ObtenerWhereTecnicasServicioDePresupuesto: una línea de
            // técnica pertenece al último servicio visto en el presupuesto.
            // (Se filtra por el presupuesto de la operación; Veolab filtra
            // solo por técnica y puede tomar líneas de otro presupuesto.)
            $lines = $db->table('FACLIP')
                ->where('PRE3DEL', $preDel)->where('PRE3SER', $preSer)->where('PRE3COD', $preCod)
                ->orderBy('LIP1COD')->get(['SER2DEL', 'SER2COD', 'TEC2DEL', 'TEC2COD', 'LIPNPRE', 'LIPCDTO']);
            $current = null;
            foreach ($lines as $line) {
                if ((string) $line->SER2COD !== '') {
                    $current = $line->SER2DEL."\x1B".$line->SER2COD;
                }
                if ($current !== null && isset($serviceList[$current]) && (string) $line->TEC2COD !== '') {
                    $rows[] = ['service' => $current, 'del' => (string) $line->TEC2DEL, 'cod' => (string) $line->TEC2COD,
                        'budgetPrice' => $line->LIPNPRE, 'budgetDiscount' => (string) $line->LIPCDTO];
                }
            }
        } else {
            $keys = array_keys($serviceList);
            sort($keys);
            foreach ($keys as $key) {
                $s = $serviceList[$key];
                $items = $db->table('LABSYT')
                    ->where('DEL3SER', $s['del'])->where('SER3COD', $s['cod'])
                    ->orderBy('SYTNORD')->get(['DEL3TEC', 'TEC3COD']);
                foreach ($items as $item) {
                    $rows[] = ['service' => $key, 'del' => (string) $item->DEL3TEC, 'cod' => (string) $item->TEC3COD];
                }
            }
        }

        $byService = array_fill_keys(array_keys($serviceList), []);
        $seen = [];
        foreach ($rows as $row) {
            $techKey = $row['del']."\x1B".$row['cod'];
            if (isset($seen[$techKey])) {
                continue;
            }
            $tec = $db->table('LABTEC')->where('DEL3COD', $row['del'])->where('TEC1COD', $row['cod'])->first();
            if (! $tec) {
                continue;
            }
            $seen[$techKey] = true;
            $byService[$row['service']][] = self::techniqueData($tec, $row, $ctx);
        }

        return $byService;
    }

    private static function techniqueData(object $tec, array $row, object $ctx): array
    {
        $db = DB::connection('dynamic');

        $clientPrice = $db->table('LABTYC')
            ->where('TEC3DEL', $tec->DEL3COD)->where('TEC3COD', $tec->TEC1COD)
            ->where('CLI3DEL', $ctx->clientDel)->where('CLI3COD', $ctx->clientCode)
            ->first(['TYCNPRE', 'TYCCDTO', 'TYCCREF']);

        // Precio (InsertarTecnicasServicio): presupuesto, tarifa o cliente.
        if (array_key_exists('budgetPrice', $row)) {
            $price = self::decimal($row['budgetPrice']);
            $discount = $row['budgetDiscount'];
        } elseif ($ctx->perTariff) {
            $tariff = $ctx->tariffCode === '' ? null : $db->table('LABTYF')
                ->where('TEC3DEL', $tec->DEL3COD)->where('TEC3COD', $tec->TEC1COD)
                ->where('TAR3DEL', $ctx->tariffDel)->where('TAR3COD', $ctx->tariffCode)
                ->first(['TYFNPRE', 'TYFCDTO']);
            $price = self::decimal($tariff?->TYFNPRE);
            $discount = (string) ($tariff?->TYFCDTO ?? '');
        } else {
            $price = self::decimal($clientPrice?->TYCNPRE);
            $discount = (string) ($clientPrice?->TYCCDTO ?? '');
        }
        if ($price == 0 && $discount !== '') {
            $price = self::decimal($tec->TECNPRE);
        }
        if ($price == 0 && $discount === '') {
            $price = self::decimal($tec->TECNPRE);
            $discount = (string) $tec->TECCDTO;
        }

        $analyst = $db->table('LABTYE')
            ->where('TEC3DEL', $tec->DEL3COD)->where('TEC3COD', $tec->TEC1COD)->where('TYENPOS', 1)
            ->first(['EMP3DEL', 'EMP3COD']);
        $department = $db->table('LABSEC')
            ->where('DEL3COD', (string) $tec->SEC2DEL)->where('SEC1COD', (int) $tec->SEC2COD)
            ->first(['DEP2DEL', 'DEP2COD']);

        // PrimeraReferencia: la referencia IGEO hasta la primera coma.
        $ref = trim((string) ($clientPrice?->TYCCREF ?? ''));
        if (($pos = strpos($ref, ',')) !== false) {
            $ref = trim(substr($ref, 0, $pos));
        }

        return [
            'tec'        => $tec,
            'price'      => $price,
            'discount'   => $discount,
            'analystDel' => (string) ($analyst?->EMP3DEL ?? ''),
            'analystCod' => (int) ($analyst?->EMP3COD ?? 0),
            'deptDel'    => (string) ($department?->DEP2DEL ?? ''),
            'deptCod'    => (int) ($department?->DEP2COD ?? 0),
            'ref'        => $ref,
        ];
    }

    /** Gastos de los servicios (InsertarGastosServicio), sin repetir. */
    private static function expenses(array $serviceList): array
    {
        $db = DB::connection('dynamic');
        $keys = array_keys($serviceList);
        sort($keys);

        $out = [];
        $seen = [];
        foreach ($keys as $key) {
            $s = $serviceList[$key];
            $rows = $db->table('LABSYE')
                ->leftJoin('LABESC', function ($join) {
                    $join->on('LABSYE.DEL3ESC', '=', 'LABESC.DEL3COD')->on('LABSYE.ESC3COD', '=', 'LABESC.ESC1COD');
                })
                ->where('LABSYE.DEL3SER', $s['del'])->where('LABSYE.SER3COD', $s['cod'])
                ->orderBy('LABSYE.DEL3ESC')->orderBy('LABSYE.ESC3COD')
                ->get(['LABSYE.DEL3ESC', 'LABSYE.ESC3COD', 'LABESC.ESCNPRE', 'LABESC.ESCCDTO', 'LABESC.ESCBSUP']);
            foreach ($rows as $row) {
                $expKey = $row->DEL3ESC."\x1B".$row->ESC3COD;
                if (isset($seen[$expKey])) {
                    continue;
                }
                $seen[$expKey] = true;
                $out[] = [
                    'service'  => $key,
                    'del'      => (string) $row->DEL3ESC,
                    'cod'      => (int) $row->ESC3COD,
                    'price'    => self::decimal($row->ESCNPRE),
                    'discount' => (string) ($row->ESCCDTO ?? ''),
                    'supplied' => $row->ESCBSUP === 'T',
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Rejilla y precios
    // ------------------------------------------------------------------

    /**
     * Rejilla de la ficha: cada servicio seguido de sus técnicas y sus
     * gastos; al final el grupo de suplidos. La posición en la rejilla es
     * la que Veolab guarda en OYSNPOS / OYGNPOS.
     */
    private static function buildGrid(array $serviceList, array $techniques, array $expenses, object $ctx): array
    {
        $grid = [];
        foreach ($serviceList as $key => $s) {
            [$price, $discount] = self::servicePrice($s['del'], $s['cod'], $ctx);
            $grid[] = ['type' => 'S', 'key' => $key, 'del' => $s['del'], 'cod' => $s['cod'],
                'price' => $price, 'discount' => $discount, 'children' => []];
            $parent = count($grid) - 1;

            foreach ($techniques[$key] as $t) {
                $grid[] = ['type' => 'T', 'parent' => $parent] + $t;
                $grid[$parent]['children'][] = count($grid) - 1;
            }
            foreach ($expenses as $e) {
                if ($e['service'] === $key && ! $e['supplied']) {
                    $grid[] = ['type' => 'G', 'parent' => $parent] + $e;
                    $grid[$parent]['children'][] = count($grid) - 1;
                }
            }
        }

        $supplied = array_values(array_filter($expenses, fn ($e) => $e['supplied']));
        if ($supplied) {
            $grid[] = ['type' => 'U', 'price' => 0.0, 'discount' => '', 'children' => []];
            $parent = count($grid) - 1;
            foreach ($supplied as $e) {
                $grid[] = ['type' => 'G', 'parent' => $parent] + $e;
                $grid[$parent]['children'][] = count($grid) - 1;
            }
        }

        foreach ($grid as $i => $row) {
            $grid[$i]['position'] = $i + 1;
        }

        return $grid;
    }

    /** FAC_ObtenerPrecioServicio / FAC_ObtenerPrecioServicioDePresupuesto. */
    private static function servicePrice(string $del, string $cod, object $ctx): array
    {
        $db = DB::connection('dynamic');

        if ($ctx->budget) {
            [$preDel, $preSer, $preCod] = $ctx->budget;
            $line = $db->table('FACLIP')
                ->where('SER2DEL', $del)->where('SER2COD', $cod)
                ->where('PRE3DEL', $preDel)->where('PRE3SER', $preSer)->where('PRE3COD', $preCod)
                ->first(['LIPNPRE', 'LIPCDTO']);

            return [self::decimal($line?->LIPNPRE), (string) ($line?->LIPCDTO ?? '')];
        }

        $base = $db->table('LABSER')->where('DEL3COD', $del)->where('SER1COD', $cod)->first(['SERNPRE', 'SERCDTO']);
        $price = 0.0;
        $discount = '';

        if ($ctx->perTariff) {
            if ($ctx->tariffCode !== '') {
                $row = $db->table('LABSYF')->where('SER3DEL', $del)->where('SER3COD', $cod)
                    ->where('TAR3DEL', $ctx->tariffDel)->where('TAR3COD', $ctx->tariffCode)
                    ->first(['SYFNPRE', 'SYFCDTO']);
                if ($row) {
                    $price = self::decimal($row->SYFNPRE);
                    $discount = (string) ($row->SYFCDTO ?? '');
                    if ($price == 0 && $discount !== '') {
                        $price = self::decimal($base?->SERNPRE);
                    }
                }
            }
        } elseif ($ctx->clientCode !== '') {
            $row = $db->table('LABSYC')->where('SER3DEL', $del)->where('SER3COD', $cod)
                ->where('CLI3DEL', $ctx->clientDel)->where('CLI3COD', $ctx->clientCode)
                ->first(['SYCNPRE', 'SYCCDTO']);
            if ($row) {
                $price = self::decimal($row->SYCNPRE);
                $discount = (string) ($row->SYCCDTO ?? '');
                if ($price == 0 && $discount !== '') {
                    $price = self::decimal($base?->SERNPRE);
                }
            }
        }

        // Lo que siga sin valor se toma del servicio (campo a campo).
        if ($price == 0 || $discount === '') {
            if ($price == 0) {
                $price = self::decimal($base?->SERNPRE);
            }
            if ($discount === '') {
                $discount = (string) ($base?->SERCDTO ?? '');
            }
        }

        return [$price, $discount];
    }

    /**
     * CalcularPreciosServicios + CalcularPrecioOperacion. Devuelve el precio
     * de la operación (suma de los grupos, sin suplidos).
     */
    private static function computeTotals(array &$grid, string $breakdown, object $ctx): float
    {
        foreach ($grid as $i => $row) {
            if ($row['type'] === 'T' || $row['type'] === 'G') {
                $grid[$i]['total'] = self::withDiscount($row['price'], $row['discount']);
            }
        }

        $opPrice = 0.0;
        foreach ($grid as $i => $row) {
            if ($row['type'] !== 'S' && $row['type'] !== 'U') {
                continue;
            }
            $childrenTotal = round(array_sum(array_map(fn ($c) => $grid[$c]['total'], $row['children'])), 2);

            if ($row['type'] === 'U' || $breakdown === 'T') {
                $total = $childrenTotal;
            } elseif ($row['price'] == 0 && $ctx->zeroDetail) {
                // Servicio a precio cero: se desglosa en sus técnicas.
                $total = $childrenTotal;
                $grid[$i]['price'] = 0.0;
                $grid[$i]['discount'] = '';
            } else {
                $total = self::withDiscount($row['price'], $row['discount']);
            }
            $grid[$i]['total'] = $total;

            if ($row['type'] === 'S') {
                $opPrice += $total;
            }
        }

        return round($opPrice, 2);
    }

    // ------------------------------------------------------------------
    // Grabación
    // ------------------------------------------------------------------

    /** LABOYS (AcumulaGrabarServicios): el primero es el predeterminado. */
    private static function saveServices(string $del, string $ser, int $cod, array $grid): void
    {
        $first = true;
        foreach ($grid as $row) {
            if ($row['type'] !== 'S') {
                continue;
            }
            DB::connection('dynamic')->table('LABOYS')->insert([
                'OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod,
                'SER3DEL' => $row['del'], 'SER3COD' => $row['cod'],
                'OYSNPRE' => $row['price'], 'OYSCDTO' => mb_substr($row['discount'], 0, 15),
                'OYSNPOS' => $row['position'], 'OYSBPRE' => $first ? 'T' : 'F',
            ]);
            $first = false;
        }
    }

    /** LABOYG (AcumulaGrabarGastos). */
    private static function saveExpenses(string $del, string $ser, int $cod, array $grid): void
    {
        foreach ($grid as $row) {
            if ($row['type'] !== 'G') {
                continue;
            }
            $parent = $grid[$row['parent']];
            $isService = $parent['type'] === 'S';
            DB::connection('dynamic')->table('LABOYG')->insert([
                'OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod,
                'ESC3DEL' => $row['del'], 'ESC3COD' => $row['cod'],
                'OYGBSUP' => $parent['type'] === 'U' ? 'T' : 'F',
                'OYGBAGR' => 'F',
                'OYGNPRE' => $row['price'], 'OYGCDTO' => $row['discount'],
                'OYGNPOS' => $row['position'],
                'SER2DEL' => $isService ? $parent['del'] : '', 'SER2COD' => $isService ? $parent['cod'] : '',
            ]);
        }
    }

    /**
     * LABRES, LABCOR, LABOYE y LABOYD (AcumulaGrabarTecnicas +
     * EjecutaGrabarTecnicas).
     */
    private static function saveTechniques(string $del, string $ser, int $cod, array $grid, object $ctx): void
    {
        $db = DB::connection('dynamic');

        // Marca predeterminada (-1) de la delegación, si existe.
        $defaultMark = $db->table('LABMAR')->where('DEL3COD', $del)->where('MAR1COD', -1)->exists();
        [$markDel, $markCod] = $defaultMark ? [$del, -1] : ['', 0];

        $order = 0;
        $analysts = [];
        $departments = [];

        foreach ($grid as $row) {
            if ($row['type'] !== 'T') {
                continue;
            }
            $order++;
            $tec = $row['tec'];
            $service = $grid[$row['parent']];

            // Normativa del servicio para la técnica (LABTYN), si tiene valor.
            $serviceNorm = $db->table('LABSER')->where('DEL3COD', $service['del'])->where('SER1COD', $service['cod'])
                ->first(['NOR2DEL', 'NOR2COD']);
            $norm = $serviceNorm ? $db->table('LABTYN')
                ->where('TEC3DEL', $tec->DEL3COD)->where('TEC3COD', $tec->TEC1COD)
                ->where('NOR3DEL', (string) $serviceNorm->NOR2DEL)->where('NOR3COD', (string) $serviceNorm->NOR2COD)
                ->value('TYNCVAL') : null;

            $db->table('LABRES')->insert([
                'OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod,
                'TEC3DEL' => $tec->DEL3COD, 'TEC3COD' => $tec->TEC1COD,
                'RESCNOM' => $tec->TECCNOM, 'RESCNOI' => $tec->TECCNOI, 'RESBCUR' => $tec->TECBCUR,
                'RESDACR' => $tec->TECDACR, 'RESCPAR' => $tec->TECCPAR, 'RESCABR' => $tec->TECCABR,
                'RESCCAS' => $tec->TECCCAS, 'RESCUNI' => $tec->TECCUNI, 'RESCLEY' => $tec->TECCLEY,
                'RESCMET' => $tec->TECCMET, 'RESCMEA' => $tec->TECCMEA,
                'RESCNOR' => ($norm !== null && $norm !== '') ? $norm : $tec->TECCNOR,
                'RESNTIE' => $tec->TECNTIE, 'RESCLIM' => $tec->TECCLIM, 'RESCMIN' => $tec->TECCMIN,
                'RESCINC' => $tec->TECCINC, 'RESCINS' => $tec->TECCINS, 'RESBEXP' => $tec->TECBEXP,
                'RESBAGR' => 'F',
                'SEC2DEL' => $tec->SEC2DEL, 'SEC2COD' => $tec->SEC2COD,
                'SER2DEL' => $service['del'], 'SER2COD' => $service['cod'],
                'RESNORD' => $order,
                'EMP2DEL' => $row['analystDel'], 'EMP2COD' => $row['analystCod'],
                'RESTINI' => null, 'RESTFIN' => null,
                'RESNPRE' => $row['price'], 'RESCDTO' => mb_substr($row['discount'], 0, 15),
                'RESCREF' => $row['ref'],
            ]);

            // Columnas de resultado de la técnica (LABCOT).
            $columns = $db->table('LABCOT')->where('TEC3DEL', $tec->DEL3COD)->where('TEC3COD', $tec->TEC1COD)->get();
            foreach ($columns as $c) {
                $db->table('LABCOR')->insert([
                    'OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod,
                    'TEC3DEL' => $c->TEC3DEL, 'TEC3COD' => $c->TEC3COD, 'COR1COD' => $c->COT1COD,
                    'CORCVAL' => $ctx->defaultRes ? $c->COTCPRE : '',
                    'CORCTIT' => $c->COTCTIT, 'CORCTI2' => $c->COTCTI2, 'CORCTI3' => $c->COTCTI3,
                    'CORBINF' => $c->COTBINF, 'CORBRES' => $c->COTBRES, 'CORBEDI' => $c->COTBEDI,
                    'CORBCON' => $c->COTBCON, 'CORBCOP' => $c->COTBCOP, 'CORBACT' => $c->COTBACT,
                    'MAR2DEL' => $markDel, 'MAR2COD' => $markCod,
                ]);
            }

            if ($row['analystCod'] !== 0) {
                $analysts[$row['analystDel']."\x1B".$row['analystCod']] = [$row['analystDel'], $row['analystCod']];
            }
            if ($row['deptCod'] !== 0) {
                $departments[$row['deptDel']."\x1B".$row['deptCod']] = [$row['deptDel'], $row['deptCod']];
            }
        }

        foreach ($analysts as [$empDel, $empCod]) {
            $db->table('LABOYE')->insert(['OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod,
                'EMP3DEL' => $empDel, 'EMP3COD' => $empCod]);
        }
        foreach ($departments as [$depDel, $depCod]) {
            $db->table('LABOYD')->insert(['OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod,
                'DEP3DEL' => $depDel, 'DEP3COD' => $depCod]);
        }
    }

    /**
     * Consumos y usos de almacén (AcumulaGrabarConsumos): por técnica, un
     * uso por equipo (LABTYQ) y un consumo por producto (LABTYP) sobre la
     * serie/lote predeterminada. El consumo descuenta existencias sin bajar
     * de cero (si no llega, el movimiento se reduce a lo disponible).
     */
    private static function saveConsumptions(string $del, string $ser, int $cod, array $grid): void
    {
        $db = DB::connection('dynamic');
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        $products = [];

        foreach ($grid as $row) {
            if ($row['type'] !== 'T') {
                continue;
            }
            $tec = $row['tec'];

            $uses = $db->table('LABTYQ')->where('TEC3DEL', $tec->DEL3COD)->where('TEC3COD', $tec->TEC1COD)
                ->get(['PRD3DEL', 'PRD3COD']);
            foreach ($uses as $u) {
                $lot = self::defaultLot((string) $u->PRD3DEL, (string) $u->PRD3COD, false);
                if ($lot !== null) {
                    self::insertMovement($del, $ser, $cod, $tec, VeolabStock::MOV_USO, 1, $u->PRD3DEL, $u->PRD3COD, $lot, $now);
                }
            }

            $items = $db->table('LABTYP')->where('TEC3DEL', $tec->DEL3COD)->where('TEC3COD', $tec->TEC1COD)
                ->get(['PRD3DEL', 'PRD3COD', 'TYPNCON']);
            foreach ($items as $p) {
                $lot = self::defaultLot((string) $p->PRD3DEL, (string) $p->PRD3COD, true);
                if ($lot === null) {
                    continue;
                }
                $quantity = self::decimal($p->TYPNCON);

                $stock = $db->table('ALMSEL')->where('PRD3DEL', $p->PRD3DEL)->where('PRD3COD', $p->PRD3COD)
                    ->where('SEL1COD', $lot)->lockForUpdate()->first(['SELNCAU', 'SELNCAE']);
                if ($stock) {
                    $remaining = self::decimal($stock->SELNCAE) - $quantity;
                    if ($remaining > 0) {
                        $update = ['SELNCAE' => $remaining,
                            'SELNUNE' => VeolabStock::unitsFromQuantity(self::decimal($stock->SELNCAU), $remaining)];
                    } else {
                        $update = ['SELNCAE' => 0, 'SELNUNE' => 0];
                        $quantity += $remaining; // solo lo disponible
                    }
                    $db->table('ALMSEL')->where('PRD3DEL', $p->PRD3DEL)->where('PRD3COD', $p->PRD3COD)
                        ->where('SEL1COD', $lot)->update($update);
                }

                self::insertMovement($del, $ser, $cod, $tec, VeolabStock::MOV_CONSUMO, $quantity, $p->PRD3DEL, $p->PRD3COD, $lot, $now);
                $products[$p->PRD3DEL."\x1B".$p->PRD3COD] = [(string) $p->PRD3DEL, (string) $p->PRD3COD];
            }
        }

        VeolabStock::recalculateProducts(array_values($products));
    }

    private static function insertMovement(string $del, string $ser, int $cod, object $tec, string $type,
        float $quantity, $prdDel, $prdCod, string $lot, string $now): void
    {
        $db = DB::connection('dynamic');
        do {
            $movCode = VeolabCodes::next('ALMMOV', '', $del);
        } while ($db->table('ALMMOV')->where('DEL3COD', $del)->where('MOV1COD', $movCode)->exists());

        $db->table('ALMMOV')->insert([
            'DEL3COD' => $del, 'MOV1COD' => $movCode, 'MOVCTIP' => $type, 'MOVDFEC' => $now,
            'MOVNCAN' => $quantity, 'PRD2DEL' => $prdDel, 'PRD2COD' => $prdCod, 'SEL2COD' => $lot,
            'OPE2DEL' => $del, 'OPE2SER' => $ser, 'OPE2COD' => $cod,
            'TEC2DEL' => $tec->DEL3COD, 'TEC2COD' => $tec->TEC1COD,
            'USU2DEL' => '', 'USU2COD' => '',
        ]);
    }

    /**
     * Serie/lote predeterminada (ALM_ObtenerSeriesPredeterminadasProductos):
     * vigente (caducidad, calibración, mantenimiento y verificación) y con
     * existencias, prefiriendo las "en uso"/"límite de uso"; para consumibles,
     * si no hay ninguna con existencias, la mayor con existencias cero.
     */
    private static function defaultLot(string $del, string $cod, bool $consumable): ?string
    {
        $today = (string) DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d;
        $base = fn () => DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $del)->where('PRD3COD', $cod)
            ->where(fn ($q) => $q->whereIn('SELCESA', ['U', 'L', 'N', ''])->orWhereNull('SELCESA'))
            ->where(fn ($q) => $q->where('SELDCAD', '>', $today)->orWhereNull('SELDCAD'))
            ->where(fn ($q) => $q->where('SELDCAL', '>', $today)->orWhereNull('SELDCAL'))
            ->where(fn ($q) => $q->where('SELDMAN', '>', $today)->orWhereNull('SELDMAN'))
            ->where(fn ($q) => $q->where('SELDVER', '>', $today)->orWhereNull('SELDVER'));

        $lot = $base()->whereIn('SELCESA', ['U', 'L'])->where('SELNCAE', '>', 0)->min('SEL1COD')
            ?? $base()->where(fn ($q) => $q->whereIn('SELCESA', ['N', ''])->orWhereNull('SELCESA'))
                ->where('SELNCAE', '>', 0)->min('SEL1COD');

        if ($lot === null && $consumable) {
            $lot = $base()->where('SELNCAE', 0)->max('SEL1COD');
        }

        return $lot === null ? null : (string) $lot;
    }

    /**
     * Datos de la operación que salen de sus servicios: precio (salvo
     * desglose manual), técnicas, tipo de operación y matriz del primer
     * servicio (si no se indicaron), envases/cantidad del último (si están
     * vacíos) y fecha de compromiso (CONBAFC).
     */
    private static function updateOperation(object $op, array $grid, array $serviceList, string $breakdown,
        float $opPrice, array $opData, object $ctx): void
    {
        $db = DB::connection('dynamic');
        $update = ['OPEBMOP' => 'F'];

        if ($breakdown !== 'N') {
            $update['OPENPRE'] = $opPrice;
        }

        $names = [];
        foreach ($grid as $row) {
            if ($row['type'] === 'T') {
                $names[] = (string) $row['tec']->TECCNOM;
            }
        }
        $update['OPECTEC'] = implode(self::LIST_SEPARATOR, $names);

        $first = reset($serviceList);
        $firstRow = $db->table('LABSER')->where('DEL3COD', $first['del'])->where('SER1COD', $first['cod'])
            ->first(['TIO2DEL', 'TIO2COD', 'MAT2DEL', 'MAT2COD']);
        if ($firstRow && empty($opData['tipo_operacion_codigo']) && (int) $firstRow->TIO2COD > 0) {
            $update['TIO2DEL'] = (string) $firstRow->TIO2DEL;
            $update['TIO2COD'] = (int) $firstRow->TIO2COD;
        }
        if ($firstRow && empty($opData['matriz_codigo']) && (int) $firstRow->MAT2COD > 0) {
            $update['MAT2DEL'] = (string) $firstRow->MAT2DEL;
            $update['MAT2COD'] = (int) $firstRow->MAT2COD;
        }

        $last = end($serviceList);
        $lastRow = $db->table('LABSER')->where('DEL3COD', $last['del'])->where('SER1COD', $last['cod'])
            ->first(['SERNENV', 'SERCCAN']);
        if ($lastRow && (float) $op->OPENENV == 0 && self::decimal($lastRow->SERNENV) != 0) {
            $update['OPENENV'] = self::decimal($lastRow->SERNENV);
        }
        if ($lastRow && (string) $op->OPECCAN === '' && (string) $lastRow->SERCCAN !== '') {
            $update['OPECCAN'] = (string) $lastRow->SERCCAN;
        }

        if ($ctx->commitment && $op->OPETREP !== null && empty($opData['fecha_compromiso'])) {
            $update['OPEDCOM'] = self::commitmentDate((string) $op->OPETREP, $serviceList, (string) $op->DEL3COD);
        }

        $db->table('LABOPE')
            ->where('DEL3COD', $op->DEL3COD)->where('OPE1SER', $op->OPE1SER)->where('OPE1COD', $op->OPE1COD)
            ->update($update);
    }

    /**
     * CalcularFechaCompromiso: recepción + el mayor plazo (SERNTIE) de los
     * servicios, en días laborables (sin fines de semana ni festivos de
     * AGEFES si hay módulo de agenda) salvo que ese servicio use naturales.
     */
    private static function commitmentDate(string $reception, array $serviceList, string $delegation): string
    {
        $db = DB::connection('dynamic');
        $max = 0;
        $working = true;
        foreach ($serviceList as $s) {
            $row = $db->table('LABSER')->where('DEL3COD', $s['del'])->where('SER1COD', $s['cod'])
                ->first(['SERNTIE', 'SERCTDI']);
            if ($row && (int) $row->SERNTIE > $max) {
                $max = (int) $row->SERNTIE;
                $working = $row->SERCTDI !== 'N';
            }
        }

        $date = new \DateTime($reception);
        if (! $working) {
            return $date->modify("+{$max} days")->format('Y-m-d H:i:s');
        }

        $holidays = [];
        if (VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'AGE')) {
            $holidays = $db->table('AGEFES')->where('DEL3COD', $delegation)
                ->pluck('FESTFEC')->map(fn ($d) => substr((string) $d, 0, 10))->flip()->all();
        }

        // AGE_SumarDiasLaborables: cuenta el día siguiente solo si es laborable.
        while ($max > 0) {
            $next = (clone $date)->modify('+1 day');
            if ((int) $next->format('N') < 6 && ! isset($holidays[$next->format('Y-m-d')])) {
                $max--;
            }
            $date = $next;
        }

        return $date->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Utilidades numéricas (GEN_Decimal, FAC_CalcularImporteConDescuento)
    // ------------------------------------------------------------------

    private static function decimal($value): float
    {
        // Como Val/GEN_Decimal: la parte numérica inicial ("10%" => 10).
        return (float) trim(str_replace(',', '.', (string) ($value ?? '')));
    }

    /** Importe con descuento: "10%" o importe fijo, redondeado a 2 decimales. */
    public static function withDiscount(float $amount, string $discount): float
    {
        $pos = strpos($discount, '%');
        if ($pos !== false) {
            $amount -= $amount * self::decimal(substr($discount, 0, $pos)) / 100;
        } else {
            $amount -= self::decimal($discount);
        }

        return round($amount, 2);
    }
}
