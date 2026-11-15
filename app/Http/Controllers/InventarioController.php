<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabCodes;
use App\Support\VeolabDocuments;
use App\Support\VeolabMetrology;
use App\Support\VeolabQuality;
use App\Support\VeolabStock;
use App\Support\VeolabVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Inventario: series y lotes de los productos (ALMSEL), los elementos reales
 * del almacén, como FichaInventario de Veolab. El producto (ALMPRD) es el
 * catálogo; sus existencias son la suma de sus series y lotes que no están
 * de baja y se recalculan con cada cambio.
 *
 *  - Código: si no se indica, el siguiente numérico del producto
 *    (ALM_ObtenerSerieSiguiente).
 *  - Alta: sin existencias indicadas, las del lote (unidades por lote y
 *    cantidad por unidad × unidades); proveedor y precio, los del producto
 *    (ALMPRD / ALMPYP). Genera el movimiento de inventario inicial.
 *  - Modificación: cambiar las existencias genera un movimiento de ajuste;
 *    dar de baja (estado B o fecha de baja) uno de baja con la cantidad en
 *    negativo; reactivar, uno de ajuste con la cantidad.
 *  - Borrado: no si la usa una operación o es materia prima de otra serie
 *    (se puede dar de baja); borra sus movimientos y materias, y sus
 *    documentos van a la papelera.
 */
class InventarioController extends BaseController
{
    protected string $table = 'ALMSEL';
    protected array $keys = [
        'producto_delegacion' => 'PRD3DEL',
        'producto_codigo'     => 'PRD3COD',
        'codigo'              => 'SEL1COD',
    ];
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'producto_delegacion';
    protected ?string $seriesKey = 'producto_codigo';
    protected array $searchFields = ['SEL1COD', 'SELCDES', 'SELCCOB'];

    protected array $mapping = [
        'producto_delegacion'      => 'PRD3DEL',
        'producto_codigo'          => 'PRD3COD',
        'codigo'                   => 'SEL1COD',
        'descripcion'              => 'SELCDES',
        'codigo_barras'            => 'SELCCOB',
        'estado'                   => 'SELCESA',
        'fecha_alta'               => 'SELDALT',
        'fecha_apertura'           => 'SELDAPE',
        'fecha_baja'               => 'SELDBAJ',
        'cantidad_unidad'          => 'SELNCAU',
        'unidades_lote'            => 'SELNUNL',
        'existencias_unidades'     => 'SELNUNE',
        'existencias_cantidad'     => 'SELNCAE',
        'precio'                   => 'SELNPRE',
        'ubicacion'                => 'SELCUBI',
        'condiciones_ambientales'  => 'SELCCOA',
        'manual_operacion'         => 'SELCMAO',
        'especificaciones_tecnicas' => 'SELCETC',
        'fecha_recepcion'          => 'SELDREC',
        'fecha_calibracion'        => 'SELDCAL',
        'fecha_mantenimiento'      => 'SELDMAN',
        'fecha_verificacion'       => 'SELDVER',
        'fecha_caducidad'          => 'SELDCAD',
        'fecha_aviso_caducidad'    => 'SELDACA',
        'estado_recepcion'         => 'SELCESR',
        'tipo_fluido'              => 'SELCTIF',
        'volumen_fluido'           => 'SELCVOF',
        'reglas_analisis'          => 'SELCREA',
        'generico_1'               => 'SELCGE1',
        'generico_2'               => 'SELCGE2',
        'generico_3'               => 'SELCGE3',
        'generico_4'               => 'SELCGE4',
        'generico_5'               => 'SELCGE5',
        'generico_6'               => 'SELCGE6',
        'observaciones'            => 'SELCOBS',
        'proveedor_delegacion'     => 'PRO2DEL',
        'proveedor_codigo'         => 'PRO2COD',
        // Veolab 3, patrones y materiales de referencia (ISO/IEC 17025 6.5): datos del certificado
        'valor_certificado'        => 'SELCVCE',
        'incertidumbre'            => 'SELCINU',
        'trazabilidad'             => 'SELCTRZ',
        'numero_certificado'       => 'SELCNCE',
        'fecha_certificado'        => 'SELDCER',
        'acreditado'               => 'SELBACR',
    ];

    /**
     * Veolab 3: datos del certificado de los patrones y materiales de
     * referencia (también exigen el script que crea las columnas, ver
     * prepareForDatabase). Al leer, además, 'tipo_metrologico' (el del
     * producto) y 'caducidad_efectiva' (calculada), de solo lectura.
     */
    protected array $since = [
        'valor_certificado'  => VeolabVersion::V3,
        'incertidumbre'      => VeolabVersion::V3,
        'trazabilidad'       => VeolabVersion::V3,
        'numero_certificado' => VeolabVersion::V3,
        'fecha_certificado'  => VeolabVersion::V3,
        'acreditado'         => VeolabVersion::V3,
    ];

    protected array $foreignKeys = ['proveedor' => 'string'];

    private const DATES = ['fecha_alta', 'fecha_apertura', 'fecha_baja', 'fecha_recepcion', 'fecha_calibracion',
        'fecha_mantenimiento', 'fecha_verificacion', 'fecha_caducidad', 'fecha_aviso_caducidad', 'fecha_certificado'];

    /** Campos de los patrones (Veolab 3 con el script de los patrones). */
    private const METROLOGY_FIELDS = ['valor_certificado', 'incertidumbre', 'trazabilidad', 'numero_certificado',
        'fecha_certificado', 'acreditado'];

    /** Fila anterior (modificación) y si es un alta, para después de grabar. */
    private ?object $before = null;
    private bool $creating = false;

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        $rules = [
            'producto_delegacion'       => 'nullable|string|max:10',
            'producto_codigo'           => ($isCreating ? 'required' : 'sometimes').'|string|max:15',
            'codigo'                    => 'nullable|string|max:30',
            'descripcion'               => 'nullable|string|max:50',
            'codigo_barras'             => 'nullable|string|max:100',
            // N nuevo, U en uso, L límite de uso, F fuera de uso, B baja ('' sin estado).
            'estado'                    => 'nullable|string|in:N,U,L,F,B',
            'cantidad_unidad'           => 'nullable|numeric|min:0',
            'unidades_lote'             => 'nullable|numeric|min:0',
            'existencias_unidades'      => 'nullable|numeric|min:0',
            'existencias_cantidad'      => 'nullable|numeric|min:0',
            'precio'                    => 'nullable|numeric|min:0',
            'ubicacion'                 => 'nullable|string|max:100',
            'condiciones_ambientales'   => 'nullable|string|max:100',
            'manual_operacion'          => 'nullable|string|max:255',
            'especificaciones_tecnicas' => 'nullable|string|max:255',
            // N nuevo, U usado.
            'estado_recepcion'          => 'nullable|string|in:N,U',
            'tipo_fluido'               => 'nullable|string|max:50',
            'volumen_fluido'            => 'nullable|string|max:50',
            'reglas_analisis'           => 'nullable|string|max:50',
            'observaciones'             => 'nullable|string',
            'proveedor_delegacion'      => 'nullable|string|max:10',
            'proveedor_codigo'          => 'nullable|string|max:15',
            'valor_certificado'         => 'nullable|string|max:100',
            'incertidumbre'             => 'nullable|string|max:100',
            'trazabilidad'              => 'nullable|string|max:255',
            'numero_certificado'        => 'nullable|string|max:50',
            'acreditado'                => 'nullable|string|in:T,F',
        ];
        foreach (self::DATES as $date) {
            $rules[$date] = 'nullable|date';
        }
        for ($i = 1; $i <= 6; $i++) {
            $rules["generico_{$i}"] = 'nullable|string|max:50';
        }

        return $rules;
    }

    protected function validateRelationships(array $data): void
    {
        if (isset($data['producto_codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMPRD')
                ->where('DEL3COD', (string) ($data['producto_delegacion'] ?? ''))
                ->where('PRD1COD', $data['producto_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El producto no existe');
            }
        }

        if (! empty($data['proveedor_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINPRO')
                ->where('DEL3COD', (string) ($data['proveedor_delegacion'] ?? ''))
                ->where('PRO1COD', $data['proveedor_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El proveedor no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        foreach (self::DATES as $date) {
            if (! empty($data[$date])) {
                $data[$date] = (new \DateTime($data[$date]))->format('Y-m-d H:i:s');
            }
        }
        foreach (['estado', 'estado_recepcion'] as $state) {
            if (array_key_exists($state, $data)) {
                $data[$state] = (string) ($data[$state] ?? ''); // Veolab graba '' sin estado
            }
        }

        $this->creating = ! $keys;

        return $keys ? $this->prepareUpdate($data, $keys) : $this->prepareCreate($data);
    }

    private function prepareCreate(array $data): array
    {
        $db = DB::connection('dynamic');
        $data['producto_delegacion'] = (string) ($data['producto_delegacion'] ?? '');

        // Veolab 3: el código de la serie oculta está reservado y un producto sin series no admite series nuevas.
        if (VeolabVersion::isV3() && ($data['codigo'] ?? '') === VeolabStock::SERIE_UNICA) {
            throw new BusinessRuleException('El código «'.VeolabStock::SERIE_UNICA.'» está reservado; indique otro o déjelo vacío');
        }
        if (VeolabStock::productWithoutSeries($data['producto_delegacion'], (string) $data['producto_codigo'])) {
            throw new BusinessRuleException('El producto lleva las existencias sin series ni lotes: se cambian en el propio producto');
        }

        // El producto bloqueado hasta el commit: código siguiente sin carreras.
        $product = $db->table('ALMPRD')
            ->where('DEL3COD', $data['producto_delegacion'])->where('PRD1COD', $data['producto_codigo'])
            ->lockForUpdate()->first(['PRO2DEL', 'PRO2COD']);

        if (($data['codigo'] ?? '') === '') {
            $max = $db->table('ALMSEL')
                ->where('PRD3DEL', $data['producto_delegacion'])->where('PRD3COD', $data['producto_codigo'])
                ->max(DB::raw('CAST(SEL1COD AS DECIMAL(30,0))'));
            $data['codigo'] = (string) ((int) $max + 1);
        }

        // Proveedor y precio de compra del producto (ObtenerDatosProducto).
        if (empty($data['proveedor_codigo']) && $product && (string) $product->PRO2COD !== '') {
            $data['proveedor_delegacion'] = (string) $product->PRO2DEL;
            $data['proveedor_codigo'] = (string) $product->PRO2COD;
        }
        if (! isset($data['precio']) && ! empty($data['proveedor_codigo'])) {
            $price = $db->table('ALMPYP')
                ->where('PRO3DEL', (string) ($data['proveedor_delegacion'] ?? ''))->where('PRO3COD', $data['proveedor_codigo'])
                ->where('PRD3DEL', $data['producto_delegacion'])->where('PRD3COD', $data['producto_codigo'])
                ->value('PYPNPRE');
            if ($price !== null) {
                $data['precio'] = $price;
            }
        }

        $data['estado'] ??= '';
        $data['estado_recepcion'] ??= '';
        $data['fecha_alta'] ??= self::today();
        $data = $this->linkWithdrawal($data, '');

        // Existencias iniciales: las indicadas o las del lote completo.
        $perUnit = (float) ($data['cantidad_unidad'] ?? 0);
        if (isset($data['existencias_cantidad'])) {
            $data['existencias_unidades'] ??= VeolabStock::unitsFromQuantity($perUnit, (float) $data['existencias_cantidad']);
        } elseif (isset($data['existencias_unidades'])) {
            $data['existencias_cantidad'] = VeolabStock::quantityFromUnits($perUnit, (float) $data['existencias_unidades']);
        } else {
            $data['existencias_unidades'] = (float) ($data['unidades_lote'] ?? 0);
            $data['existencias_cantidad'] = VeolabStock::quantityFromUnits($perUnit, (float) ($data['unidades_lote'] ?? 0));
        }

        return $data;
    }

    private function prepareUpdate(array $data, array $keys): array
    {
        $this->before = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', (string) $keys['producto_delegacion'])->where('PRD3COD', $keys['producto_codigo'])
            ->where('SEL1COD', $keys['codigo'])->first();

        $data = $this->linkWithdrawal($data, (string) $this->before->SELCESA);

        // Como la ficha: cantidad y unidades en existencias se calculan una de otra.
        $perUnit = (float) ($data['cantidad_unidad'] ?? $this->before->SELNCAU);
        if (isset($data['existencias_cantidad']) && ! isset($data['existencias_unidades']) && $perUnit > 0) {
            $data['existencias_unidades'] = VeolabStock::unitsFromQuantity($perUnit, (float) $data['existencias_cantidad']);
        } elseif (isset($data['existencias_unidades']) && ! isset($data['existencias_cantidad']) && $perUnit > 0) {
            $data['existencias_cantidad'] = VeolabStock::quantityFromUnits($perUnit, (float) $data['existencias_unidades']);
        }

        return $data;
    }

    /**
     * Estado de baja y fecha de baja van juntos (ucdBaja_Change y la baja del
     * listado de inventario): fecha de baja → estado B; estado B → fecha de
     * hoy si no hay; quitar la baja → estado U y sin fecha.
     */
    private function linkWithdrawal(array $data, string $previousState): array
    {
        if (! empty($data['fecha_baja']) && ! array_key_exists('estado', $data)) {
            $data['estado'] = 'B';
        }
        if (array_key_exists('fecha_baja', $data) && empty($data['fecha_baja']) && ! array_key_exists('estado', $data)
            && $previousState === 'B') {
            $data['estado'] = 'U';
        }
        if (($data['estado'] ?? null) === 'B' && empty($data['fecha_baja'])
            && ! ($previousState === 'B' && $this->before && $this->before->SELDBAJ)) {
            $data['fecha_baja'] = self::today();
        }
        if (isset($data['estado']) && $data['estado'] !== 'B' && $previousState === 'B' && ! array_key_exists('fecha_baja', $data)) {
            $data['fecha_baja'] = null;
        }

        return $data;
    }

    /** Fecha de hoy del servidor de BD (como DBS_ObtenFechaServidor). */
    private static function today(): string
    {
        return DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
    }

    /** Movimiento que corresponde al cambio (Grabar) y existencias del producto. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $delegation = (string) $keys['producto_delegacion'];
        $product = (string) $keys['producto_codigo'];
        $lot = (string) $keys['codigo'];

        if ($this->creating) {
            VeolabStock::movement($delegation, $product, $lot, (float) $data['existencias_cantidad'], VeolabStock::MOV_INICIAL);
        } else {
            $oldState = (string) $this->before->SELCESA;
            $oldQuantity = (float) $this->before->SELNCAE;
            $newState = array_key_exists('estado', $data) ? (string) $data['estado'] : $oldState;
            $newQuantity = isset($data['existencias_cantidad']) ? (float) $data['existencias_cantidad'] : $oldQuantity;

            $movement = null;
            if ($newState !== $oldState && $newState === 'B') {
                $movement = [VeolabStock::MOV_BAJA, -$oldQuantity];
            } elseif ($newState !== $oldState && $oldState === 'B') {
                $movement = [VeolabStock::MOV_AJUSTE, $newQuantity];
            } elseif (abs($newQuantity - $oldQuantity) > 1e-9) {
                $movement = [VeolabStock::MOV_AJUSTE, $newQuantity - $oldQuantity];
            }
            if ($movement) {
                VeolabStock::movement($delegation, $product, $lot, $movement[1], $movement[0]);
            }
        }

        VeolabStock::recalculateProducts([[$delegation, $product]]);

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $db = DB::connection('dynamic');
        $delegation = (string) $keys['producto_delegacion'];

        $checks = [
            ['LABOPE', ['PRD2DEL' => $delegation, 'PRD2COD' => $keys['producto_codigo'], 'SEL2COD' => $keys['codigo']], 'alguna operación'],
            ['ALMMAT', ['PRM3DEL' => $delegation, 'PRM3COD' => $keys['producto_codigo'], 'SEM3COD' => $keys['codigo']], 'otra serie o lote como materia prima'],
            ['ALMPYS', ['PRD3DEL' => $delegation, 'PRD3COD' => $keys['producto_codigo'], 'SEL3COD' => $keys['codigo']], 'algún préstamo'],
        ];
        foreach ($checks as [$table, $where, $what]) {
            if ($db->table($table)->where($where)->exists()) {
                throw new BusinessRuleException("La serie o lote no puede ser eliminada porque la usa {$what}; se puede dar de baja");
            }
        }

        // Como BorradoFisicoPermitido de Veolab 3 (2.4.5 no lo mira): con consumos, usos o préstamos solo se puede dar de baja.
        $history = VeolabVersion::isV3() && $db->table('ALMMOV')
            ->where('PRD2DEL', $delegation)->where('PRD2COD', $keys['producto_codigo'])->where('SEL2COD', $keys['codigo'])
            ->where(fn ($q) => $q->whereNotNull('OPE2COD')->orWhereNotNull('PRE2COD')
                ->orWhereIn('MOVCTIP', [VeolabStock::MOV_CONSUMO, VeolabStock::MOV_USO, VeolabStock::MOV_PRESTAMO, VeolabStock::MOV_DEVOLUCION]))
            ->exists();
        if ($history) {
            throw new BusinessRuleException('La serie o lote no puede ser eliminada porque tiene consumos, usos o préstamos; se puede dar de baja');
        }
    }

    /** Como el borrado del inventario de Veolab. */
    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        $delegation = (string) $keys['producto_delegacion'];
        $product = (string) $keys['producto_codigo'];
        $lot = (string) $keys['codigo'];

        $db->table('ALMMOV')->where('PRD2DEL', $delegation)->where('PRD2COD', $product)->where('SEL2COD', $lot)->delete();
        $db->table('ALMMAT')->where('PRD3DEL', $delegation)->where('PRD3COD', $product)->where('SEL3COD', $lot)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('PRD2COD', $product)->where('SEL2COD', $lot)
            ->update(VeolabDocuments::trashUpdate($delegation));

        VeolabStock::recalculateProducts([[$delegation, $product]]);
    }

    // ------------------------------------------------------------------
    // Patrones y materiales de referencia (Veolab 3, ISO/IEC 17025 6.5 y 6.4.9)
    // ------------------------------------------------------------------

    /**
     * Además de lo de BaseController: en una base de Veolab 3 sin el script
     * de los patrones, los datos del certificado se tratan como no
     * disponibles (no se devuelven y escribirlos da 422).
     */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if ($this->supports('valor_certificado') && ! VeolabMetrology::available()) {
            foreach (self::METROLOGY_FIELDS as $param) {
                $this->unsupported[$param] = VeolabVersion::V3;
                unset($this->mapping[$param]);
            }
        }

        return null;
    }

    /**
     * Filtros por el producto: ?tipo_metrologico=R,T (vacío: productos
     * normales) y ?patrones=T|F (con o sin tipo metrológico).
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $hasType = array_key_exists('tipo_metrologico', $request->query());
        $patterns = $request->query('patrones');
        if (! $hasType && $patterns === null) {
            return;
        }
        if (! VeolabMetrology::available()) {
            throw new BusinessRuleException(VeolabMetrology::unavailableMessage('El filtro por tipo metrológico'));
        }
        $types = self::metrologyTypes($request->query('tipo_metrologico'));
        if ($patterns !== null && ! in_array($patterns, ['T', 'F'], true)) {
            throw new BusinessRuleException("'patrones' admite T o F");
        }

        $query->whereExists(function ($s) use ($hasType, $types, $patterns) {
            $s->select(DB::raw(1))->from('ALMPRD')
                ->whereColumn('ALMPRD.DEL3COD', 'ALMSEL.PRD3DEL')->whereColumn('ALMPRD.PRD1COD', 'ALMSEL.PRD3COD');
            if ($hasType && $types) {
                $s->whereIn('ALMPRD.PRDCTPR', $types);
            } elseif ($hasType) {
                $s->where(fn ($w) => $w->whereNull('ALMPRD.PRDCTPR')->orWhere('ALMPRD.PRDCTPR', ''));
            }
            if ($patterns === 'T') {
                VeolabMetrology::whereMetrological($s);
            } elseif ($patterns === 'F') {
                $s->where(fn ($w) => $w->whereNull('ALMPRD.PRDCTPR')->orWhere('ALMPRD.PRDCTPR', ''));
            }
        });
    }

    /** Tipos metrológicos de un filtro ('R' o 'R,T'; vacío = ninguno). 422 si alguno no existe. */
    private static function metrologyTypes($value): array
    {
        if (is_array($value)) {
            throw new BusinessRuleException("'tipo_metrologico' no admite operadores");
        }
        $types = array_values(array_filter(array_map('trim', explode(',', (string) ($value ?? ''))), fn ($t) => $t !== ''));
        if ($bad = array_diff($types, array_keys(VeolabMetrology::TYPES))) {
            throw new BusinessRuleException("'tipo_metrologico' desconocido: ".implode(', ', $bad)
                .' (admite '.implode(', ', array_keys(VeolabMetrology::TYPES)).')');
        }

        return $types;
    }

    /** Al leer: el tipo metrológico del producto y la caducidad efectiva de cada serie (solo lectura). */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows || ! VeolabMetrology::available()) {
            return $rows;
        }

        $products = [];
        foreach ($rows as $row) {
            $products[(string) $row['producto_delegacion']."\x1B".(string) $row['producto_codigo']] =
                [(string) $row['producto_delegacion'], (string) $row['producto_codigo']];
        }
        $info = [];
        foreach (array_chunk(array_values($products), 200) as $chunk) {
            $found = DB::connection('dynamic')->table('ALMPRD')
                ->where(function ($q) use ($chunk) {
                    foreach ($chunk as [$del, $cod]) {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', $del)->where('PRD1COD', $cod));
                    }
                })
                ->get(['DEL3COD', 'PRD1COD', 'PRDCTPR', 'PRDNDAP']);
            foreach ($found as $p) {
                $info[(string) $p->DEL3COD."\x1B".(string) $p->PRD1COD] = $p;
            }
        }

        foreach ($rows as &$row) {
            $p = $info[(string) $row['producto_delegacion']."\x1B".(string) $row['producto_codigo']] ?? null;
            $type = $p ? trim((string) ($p->PRDCTPR ?? '')) : '';
            $row['tipo_metrologico'] = $type !== '' ? $type : null;
            $row['caducidad_efectiva'] = VeolabMetrology::effectiveExpiry($row['fecha_caducidad'] ?? null,
                $row['fecha_apertura'] ?? null, $p->PRDNDAP ?? null);
        }
        unset($row);

        return $rows;
    }

    /** Clave de la serie en la query string: [producto_delegacion, producto_codigo, codigo] o la respuesta 422. */
    private function seriesFromQuery(Request $request)
    {
        $params = [
            'producto_delegacion' => $request->query('producto_delegacion'),
            'producto_codigo'     => $request->query('producto_codigo'),
            'codigo'              => $request->query('codigo'),
        ];
        $validator = Validator::make($params, [
            'producto_delegacion' => 'nullable|string|max:10',
            'producto_codigo'     => 'required|string|max:15',
            'codigo'              => 'required|string|max:30',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => $validator->errors()], 422);
        }

        return [(string) ($params['producto_delegacion'] ?? ''), (string) $params['producto_codigo'], (string) $params['codigo']];
    }

    /** ¿Existe la serie? */
    private static function seriesExists(array $key): bool
    {
        return DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $key[0])->where('PRD3COD', $key[1])->where('SEL1COD', $key[2])->exists();
    }

    /**
     * GET /inventario/trazabilidad?producto_delegacion=&producto_codigo=&codigo=
     * Cadena de trazabilidad de la serie (VeolabMetrology::traceability):
     * con qué se calibra o verifica y para qué se usa. Veolab 3.
     */
    public function traceability(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $key = $this->seriesFromQuery($request);
        if ($key instanceof JsonResponse) {
            return $key;
        }
        try {
            VeolabVersion::require(VeolabVersion::V3, 'La cadena de trazabilidad');
            if (! self::seriesExists($key)) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            return response()->json([
                'data' => VeolabMetrology::traceability($key[0], $key[1], $key[2]),
                'meta' => ['version_bd' => VeolabVersion::current(), 'certificados' => VeolabMetrology::available()],
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return ServerError::response("v2 trazabilidad {$this->table}", $e, 'Error al leer la cadena de trazabilidad');
        }
    }

    /**
     * Desde y hasta del periodo de las operaciones afectadas: los indicados
     * o, por defecto, desde el fin de la última calibración o verificación
     * conforme (sin ninguna, desde el principio) hasta hoy. Devuelve
     * [desde, hasta, origen, calibración de referencia, últimas calibraciones].
     */
    private static function affectedPeriod(array $key, ?string $from, ?string $to): array
    {
        $calibrations = VeolabMetrology::lastCalibrations($key[0], $key[1], $key[2]);
        $reference = null;
        if ($from !== null) {
            $origin = 'indicada';
            $from = (new \DateTime($from))->format('Y-m-d');
        } else {
            // OperacionesAfectadas.FechasPorDefecto recorre todas las calibraciones finalizadas, no solo las últimas
            foreach (VeolabMetrology::lastCalibrations($key[0], $key[1], $key[2], 1000000) as $calibration) {
                if ($calibration['conforme']) {
                    $reference = $calibration;
                    break;
                }
            }
            $from = $reference['fecha_fin'] ?? null;
            $origin = $reference ? 'calibracion' : 'sin_calibracion';
        }
        $to = $to !== null ? (new \DateTime($to))->format('Y-m-d') : substr(self::today(), 0, 10);
        if ($from !== null && $from > $to) {
            throw new BusinessRuleException("'desde' no puede ser posterior a 'hasta'");
        }

        return [$from, $to, $origin, $reference, $calibrations];
    }

    /**
     * GET /inventario/operaciones-afectadas?producto_delegacion=&producto_codigo=&codigo=[&desde=&hasta=]
     * Equipo no conforme (ISO/IEC 17025 6.4.9): operaciones no anuladas en
     * las que se usó o consumió la serie en el periodo (por defecto, desde la
     * última calibración o verificación conforme hasta hoy), con sus informes.
     */
    public function affectedOperations(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $key = $this->seriesFromQuery($request);
        if ($key instanceof JsonResponse) {
            return $key;
        }
        $validator = Validator::make(['desde' => $request->query('desde'), 'hasta' => $request->query('hasta')], [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => $validator->errors()], 422);
        }
        try {
            VeolabVersion::require(VeolabVersion::V3, 'La revisión de las operaciones afectadas');
            if (! self::seriesExists($key)) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            [$from, $to, $origin, $reference, $calibrations] = self::affectedPeriod($key,
                $request->query('desde'), $request->query('hasta'));
            $operations = VeolabMetrology::affectedOperations($key[0], $key[1], $key[2], $from, $to);

            return response()->json([
                'data' => [
                    'serie'                  => VeolabMetrology::seriesData(VeolabMetrology::series($key[0], $key[1], $key[2]), $key[0], $key[1], $key[2]),
                    'desde'                  => $from,
                    'hasta'                  => $to,
                    'desde_origen'           => $origin,
                    'calibracion_referencia' => $reference,
                    'calibraciones'          => $calibrations,
                    'operaciones'            => $operations,
                ],
                'meta' => ['total' => count($operations), 'version_bd' => VeolabVersion::current()],
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return ServerError::response("v2 operaciones afectadas {$this->table}", $e, 'Error al leer las operaciones afectadas');
        }
    }

    /**
     * POST /inventario/operaciones-afectadas?producto_delegacion=&producto_codigo=&codigo=
     * Cuerpo {operaciones: [{delegacion, serie, codigo}], calibracion
     * ({delegacion, serie, codigo}, opcional: la calibración o verificación
     * no conforme de la serie), desde, hasta (para el detalle), delegacion y
     * serie de la incidencia, descripcion, detalle, detectada_por_* (por
     * defecto el empleado del usuario), usuario_*}. Crea la incidencia de
     * trabajo no conforme (clase T, origen Q equipo) con las reglas de POST
     * /incidencias y la enlaza (CALIYE) con la serie (ALMSEL; la serie oculta
     * de un producto sin series, como el producto), la calibración y las
     * operaciones elegidas, que deben usar o consumir la serie.
     */
    public function createNonconformity(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $key = $this->seriesFromQuery($request);
        if ($key instanceof JsonResponse) {
            return $key;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'operaciones'              => 'nullable|array',
            'operaciones.*.delegacion' => 'nullable|string|max:10',
            'operaciones.*.serie'      => 'nullable|string|max:10',
            'operaciones.*.codigo'     => 'required|integer|min:1',
            'calibracion'              => 'nullable|array',
            'calibracion.delegacion'   => 'nullable|string|max:10',
            'calibracion.serie'        => 'nullable|string|max:10',
            'calibracion.codigo'       => 'required_with:calibracion|integer|min:1',
            'desde'                    => 'nullable|date',
            'hasta'                    => 'nullable|date',
            'delegacion'               => 'nullable|string|max:10',
            'serie'                    => 'nullable|string|max:10',
            'descripcion'              => 'nullable|string|max:255',
            'detalle'                  => 'nullable|string',
            'detectada_por_delegacion' => 'nullable|string|max:10',
            'detectada_por_codigo'     => 'nullable|integer|min:0',
            'usuario_delegacion'       => 'nullable|string|max:10',
            'usuario_codigo'           => 'nullable|string|max:15',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabVersion::require(VeolabVersion::V3, 'La incidencia de equipo no conforme');
            VeolabQuality::assertModule();
            $series = VeolabMetrology::series($key[0], $key[1], $key[2]);
            if (! $series) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            // Operaciones elegidas: sin repetir y que usen o consuman la serie
            $operations = [];
            foreach ((array) ($body['operaciones'] ?? []) as $o) {
                $op = [trim((string) ($o['delegacion'] ?? '')), trim((string) ($o['serie'] ?? '')), (int) $o['codigo']];
                if (! VeolabMetrology::operationUses($op[0], $op[1], $op[2], $key[0], $key[1], $key[2])) {
                    throw new BusinessRuleException('La operación '.VeolabCodes::format('LABOPE', (string) $op[2], $op[0], $op[1])
                        .' no usa ni consume la serie');
                }
                $operations[implode("\x1B", $op)] = $op;
            }
            $calibration = null;
            if (! empty($body['calibracion'])) {
                $c = $body['calibracion'];
                $calibration = [trim((string) ($c['delegacion'] ?? '')), trim((string) ($c['serie'] ?? '')), (int) $c['codigo']];
                if (! VeolabMetrology::isCalibrationOf($calibration[0], $calibration[1], $calibration[2], $key[0], $key[1], $key[2])) {
                    throw new BusinessRuleException('La operación '.VeolabCodes::format('LABOPE', (string) $calibration[2], $calibration[0], $calibration[1])
                        .' no es una calibración ni una verificación de la serie');
                }
            }

            // Quién la detecta: el indicado o el empleado del usuario
            $detector = [(string) ($body['detectada_por_delegacion'] ?? ''), (int) ($body['detectada_por_codigo'] ?? 0)];
            if (! array_key_exists('detectada_por_codigo', $body) && ! empty($body['usuario_codigo'])) {
                $user = $db->table('ACCUSU')->where('DEL3COD', (string) ($body['usuario_delegacion'] ?? ''))
                    ->where('USU1COD', (string) $body['usuario_codigo'])->first(['EMP2DEL', 'EMP2COD']);
                if ($user && (int) ($user->EMP2COD ?? 0) !== 0) {
                    $detector = [(string) $user->EMP2DEL, (int) $user->EMP2COD];
                }
            }

            $lotText = $key[2] !== VeolabStock::SERIE_UNICA ? ' ('.$key[2].')' : '';
            $title = trim((string) ($body['descripcion'] ?? ''));
            if ($title === '') {
                $title = mb_substr('Equipo no conforme: '.trim((string) $series->PRDCDES).$lotText, 0, 255);
            }
            $detail = trim((string) ($body['detalle'] ?? ''));
            if ($detail === '') {
                $from = ! empty($body['desde']) ? (new \DateTime($body['desde']))->format('d/m/Y') : '';
                $to = ! empty($body['hasta']) ? (new \DateTime($body['hasta']))->format('d/m/Y') : '';
                $detail = 'Revisión del efecto en los resultados (ISO/IEC 17025 6.4.9): '.count($operations)
                    .(count($operations) === 1 ? ' operación' : ' operaciones').' en las que se usó '.trim((string) $series->PRDCDES).$lotText
                    .($from !== '' ? ' desde el '.$from : '').($to !== '' ? ' hasta el '.$to : '').'.';
            }
            $incDel = array_key_exists('delegacion', $body) ? trim((string) ($body['delegacion'] ?? '')) : $key[0];
            $incident = [
                'delegacion'               => $incDel,
                'serie'                    => (string) ($body['serie'] ?? ''),
                'clase'                    => 'T',
                'origen'                   => 'Q',
                'descripcion'              => $title,
                'detalle'                  => $detail,
                'detectada_por_delegacion' => $detector[1] !== 0 ? $detector[0] : null,
                'detectada_por_codigo'     => $detector[1] !== 0 ? $detector[1] : null,
            ];
            // OperacionesAfectadas.AbrirIncidencia: con una sola operación elegida, su cliente (CAL_ClienteEnlaces)
            if (count($operations) === 1) {
                $op = reset($operations);
                $client = $db->table('LABOPE')->where('DEL3COD', $op[0])->where('OPE1SER', $op[1])->where('OPE1COD', $op[2])
                    ->first(['CLI2DEL', 'CLI2COD']);
                if ($client && trim((string) $client->CLI2COD) !== '') {
                    $incident['cliente_delegacion'] = (string) $client->CLI2DEL;
                    $incident['cliente_codigo'] = (string) $client->CLI2COD;
                }
            }
            if (! empty($body['usuario_codigo'])) {
                $incident['usuario_delegacion'] = (string) ($body['usuario_delegacion'] ?? '');
                $incident['usuario_codigo'] = (string) $body['usuario_codigo'];
            }
            $response = app(IncidenciaController::class)->createFromData($incident);
            if (! $response instanceof JsonResponse || $response->getStatusCode() !== 201) {
                $db->rollBack();

                return $response;
            }
            $created = $response->getData(true)['data'] ?? [];
            [$incSer, $incCod] = [(string) ($created['serie'] ?? ''), (int) ($created['codigo'] ?? 0)];

            // Enlaces: la serie, la calibración y las operaciones
            $links = [VeolabQuality::normalizeLink('ALMSEL', $key[0], $key[1], $key[2])];
            if ($calibration) {
                $links[] = ['LABOPE', $calibration[0], $calibration[1], (string) $calibration[2]];
            }
            foreach ($operations as $op) {
                $links[] = ['LABOPE', $op[0], $op[1], (string) $op[2]];
            }
            $line = (int) $db->table('CALIYE')->where('DEL3COD', $incDel)->where('INC3SER', $incSer)->where('INC3COD', $incCod)->max('IYE1COD') + 1;
            $done = [];
            foreach ($links as $link) {
                $id = implode("\x1B", $link);
                if (isset($done[$id])) {
                    continue;
                }
                $done[$id] = true;
                $db->table('CALIYE')->insert([
                    'DEL3COD' => $incDel, 'INC3SER' => $incSer, 'INC3COD' => $incCod, 'IYE1COD' => $line,
                    'IYECTAB' => $link[0], 'IYECDEL' => $link[1], 'IYECSER' => $link[2], 'IYECCOD' => $link[3],
                ]);
                $line++;
            }

            $db->commit();

            return response()->json([
                'message' => 'Incidencia de trabajo no conforme creada correctamente',
                'data'    => [
                    'delegacion'        => $incDel,
                    'serie'             => $incSer,
                    'codigo'            => $incCod,
                    'codigo_formateado' => VeolabCodes::format('CALINC', (string) $incCod, $incDel, $incSer),
                    'enlaces'           => count($done),
                    'operaciones'       => count($operations),
                ],
            ], 201);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 equipo no conforme {$this->table}", $e, 'Error al crear la incidencia de trabajo no conforme');
        }
    }
}
