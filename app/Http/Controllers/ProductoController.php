<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabDocuments;
use App\Support\VeolabMetrology;
use App\Support\VeolabStock;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoController extends BaseController
{
    protected string $table = 'ALMPRD';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'PRD1COD',
    ];
    protected ?string $inactiveField = 'PRDBBAJ';
    protected array $searchFields = ['PRDCDES'];

    protected bool $generatesCode = true;

    /**
     * Veolab 3: existencias por series o lotes (PRDBSEL). Con 'F' el producto
     * lleva las existencias en una serie oculta (VeolabStock::SERIE_UNICA) y
     * se cambian en el propio producto.
     */
    protected array $since = [
        'existencias_por_series' => VeolabVersion::V3,
        // Patrones y materiales de referencia (ISO/IEC 17025 6.5): también exigen su script (prepareForDatabase)
        'tipo_metrologico'       => VeolabVersion::V3,
        'dias_validez_apertura'  => VeolabVersion::V3,
    ];

    /** Campos de los patrones (Veolab 3 con el script de los patrones). */
    private const METROLOGY_FIELDS = ['tipo_metrologico', 'dias_validez_apertura'];

    private bool $creating = false;

    protected array $foreignKeys = [
        'familia'   => 'int',
        'proveedor' => 'string',
    ];

    protected array $mapping = [
        'delegacion'            => 'DEL3COD',
        'codigo'                => 'PRD1COD',
        'descripcion'           => 'PRDCDES',
        'marca'                 => 'PRDCMAR',
        'modelo'                => 'PRDCMOD',
        'fabricante'            => 'PRDCFAB',
        'ano_fabricacion'       => 'PRDCANF',
        'codigo_barras'         => 'PRDCCOB',
        'es_equipo'             => 'PRDBEQU',
        'es_consumible'         => 'PRDBCON',
        'permite_operaciones'   => 'PRDBOPE',
        'unidades'              => 'PRDCUNI',
        'stock_minimo'          => 'PRDNSMI',
        'stock_maximo'          => 'PRDNSMA',
        'existencias_unidades'  => 'PRDNEXI',
        'existencias_cantidad'  => 'PRDNCAE',
        'observaciones'         => 'PRDCOBS',
        'es_baja'               => 'PRDBBAJ',
        'referencia'            => 'PRDCREF',
        'precio'                => 'PRDNPRE',
        'familia_delegacion'    => 'FAM2DEL',
        'familia_codigo'        => 'FAM2COD',
        'proveedor_delegacion'  => 'PRO2DEL',
        'proveedor_codigo'      => 'PRO2COD',
        'existencias_por_series' => 'PRDBSEL',
        // R patrón de referencia, T patrón de trabajo, C material de referencia certificado,
        // M material de referencia o control; null producto normal
        'tipo_metrologico'      => 'PRDCTPR',
        'dias_validez_apertura' => 'PRDNDAP',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'           => 'nullable|string|max:10',
            'codigo'               => 'nullable|string|max:15',
            'descripcion'          => 'nullable|string|max:255',
            'marca'                => 'nullable|string|max:100',
            'modelo'               => 'nullable|string|max:100',
            'fabricante'           => 'nullable|string|max:100',
            'ano_fabricacion'      => 'nullable|string|max:10',
            'codigo_barras'        => 'nullable|string|max:100',
            'es_equipo'            => 'nullable|string|in:T,F|max:1',
            'es_consumible'        => 'nullable|string|in:T,F|max:1',
            'permite_operaciones'  => 'nullable|string|in:T,F|max:1',
            'unidades'             => 'nullable|string|max:20',
            'stock_minimo'         => 'nullable|numeric|min:0',
            'stock_maximo'         => 'nullable|numeric|min:0',
            'existencias_unidades' => 'nullable|numeric|min:0',
            'existencias_cantidad' => 'nullable|numeric|min:0',
            'observaciones'        => 'nullable|string',
            'es_baja'              => 'nullable|string|in:T,F|max:1',
            'referencia'           => 'nullable|string|max:30',
            'precio'               => 'nullable|numeric|min:0',
            'familia_delegacion'   => 'nullable|string|max:10',
            'familia_codigo'       => 'nullable|integer',
            'proveedor_delegacion' => 'nullable|string|max:10',
            'proveedor_codigo'     => 'nullable|string|max:15',
            'existencias_por_series' => 'nullable|string|in:T,F|max:1',
            'tipo_metrologico'     => 'nullable|string|in:'.implode(',', array_keys(VeolabMetrology::TYPES)),
            'dias_validez_apertura' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Además de lo de BaseController: en una base de Veolab 3 sin el script
     * de los patrones, el tipo metrológico y la validez tras abrir se tratan
     * como no disponibles (no se devuelven y escribirlos da 422).
     */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if ($this->supports('tipo_metrologico') && ! VeolabMetrology::available()) {
            foreach (self::METROLOGY_FIELDS as $param) {
                $this->unsupported[$param] = VeolabVersion::V3;
                unset($this->mapping[$param]);
            }
        }

        return null;
    }

    /** ?patrones=T|F: productos con o sin tipo metrológico (el tipo se filtra con ?tipo_metrologico=). */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $patterns = $request->query('patrones');
        if ($patterns === null) {
            return;
        }
        if (! $this->supports('tipo_metrologico')) {
            throw new BusinessRuleException(VeolabMetrology::unavailableMessage('El filtro de patrones'));
        }
        if ($patterns === 'T') {
            VeolabMetrology::whereMetrological($query, $this->table);
        } elseif ($patterns === 'F') {
            $query->where(fn ($q) => $q->whereNull('PRDCTPR')->orWhere('PRDCTPR', ''));
        } else {
            throw new BusinessRuleException("'patrones' admite T o F");
        }
    }

    protected function validateRelationships(array $data): void
    {
        if (! empty($data['delegacion'])) {
            $exists = DB::connection('dynamic')->table('ACCDEL')
                ->where('DEL1COD', $data['delegacion'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La delegación no existe');
            }
        }

        if (! empty($data['familia_codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMFAM')
                ->where('DEL3COD', $data['familia_delegacion'] ?? '')
                ->where('FAM1COD', $data['familia_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La familia no existe');
            }
        }

        if (! empty($data['proveedor_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINPRO')
                ->where('DEL3COD', $data['proveedor_delegacion'] ?? '')
                ->where('PRO1COD', $data['proveedor_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El proveedor no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('ALMPRD')->where('PRDCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('PRD1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del producto ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('ALMPRD')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('PRD1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del producto ya está en uso');
            }
        }

        // Tipo metrológico: el vacío es NULL (producto normal)
        if (array_key_exists('tipo_metrologico', $data) && trim((string) ($data['tipo_metrologico'] ?? '')) === '') {
            $data['tipo_metrologico'] = null;
        }
        if (array_key_exists('dias_validez_apertura', $data) && (int) ($data['dias_validez_apertura'] ?? 0) === 0) {
            $data['dias_validez_apertura'] = null;
        }

        $this->creating = $isCreating;
        if (VeolabVersion::isV3()) {
            if (VeolabStock::basicMode()) {
                // Inventario básico: todos los productos sin series.
                if ($isCreating || array_key_exists('existencias_por_series', $data)) {
                    $data['existencias_por_series'] = 'F';
                }
            } elseif ($isCreating) {
                $data['existencias_por_series'] ??= VeolabStock::newProductSeriesFlag();
            } elseif (($data['existencias_por_series'] ?? null) === 'F'
                && VeolabStock::activeLots($delegation, (string) $code) > 1) {
                // Pasar a sin series: la serie activa que tenga pasa a ser la del producto, si solo hay una.
                throw new BusinessRuleException('No se pueden quitar las series o lotes: el producto tiene más de una serie o lote activo. Dé de baja los que sobren y vuelva a intentarlo.');
            }
        }

        return $data;
    }

    /**
     * Producto sin series (Veolab 3): su serie oculta se crea con las
     * existencias grabadas o se ajusta con un movimiento si cambian.
     */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $delegation = (string) ($keys['delegacion'] ?? '');
        $code = (string) ($keys['codigo'] ?? '');
        if ($code === '' || ! VeolabStock::productWithoutSeries($delegation, $code)) {
            return $data;
        }

        if ($this->creating) {
            VeolabStock::singleLot($delegation, $code);
        } else {
            VeolabStock::updateSingleLot($delegation, $code,
                isset($data['existencias_cantidad']) ? (float) $data['existencias_cantidad'] : null,
                isset($data['existencias_unidades']) ? (float) $data['existencias_unidades'] : null);
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $v3 = VeolabVersion::isV3();

        // La serie oculta de un producto sin series (Veolab 3) no impide borrarlo si no tiene historial.
        $used = DB::connection('dynamic')->table('ALMSEL')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)
            ->when($v3, fn ($q) => $q->where('SEL1COD', '<>', VeolabStock::SERIE_UNICA))->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque contiene series o lotes');
        }

        // Veolab 3 (Productos.BorradoFisicoPermitido): historial, materias primas y operaciones; 2.4.5 no los mira.
        $used = $v3 && DB::connection('dynamic')->table('ALMMOV')
            ->where('PRD2DEL', $delegation)->where('PRD2COD', $code)
            ->where(fn ($q) => $q->whereNotNull('OPE2COD')->orWhereNotNull('PRE2COD')
                ->orWhereIn('MOVCTIP', [VeolabStock::MOV_CONSUMO, VeolabStock::MOV_USO, VeolabStock::MOV_PRESTAMO, VeolabStock::MOV_DEVOLUCION]))
            ->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque tiene consumos, usos o préstamos; se puede dar de baja');
        }

        $used = $v3 && DB::connection('dynamic')->table('ALMMAT')
            ->where('PRM3DEL', $delegation)->where('PRM3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque es materia prima de alguna serie o lote');
        }

        $used = $v3 && DB::connection('dynamic')->table('LABOPE')
            ->where('PRD2DEL', $delegation)->where('PRD2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque lo usa alguna operación');
        }

        $used = DB::connection('dynamic')->table('LABTYQ')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque está vinculado a algún parámetro (equipo)');
        }

        $used = DB::connection('dynamic')->table('LABTYP')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El producto no puede ser eliminado porque está vinculado a algún parámetro (consumible)');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('ALMPYP')
            ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->delete();

        // Veolab 3 (Inventario.BorrarProductos): formatos de compra, avisos del producto (stock mínimo) y la serie
        // oculta (sin historial, comprobado antes) con sus movimientos de existencias.
        if (VeolabVersion::isV3()) {
            $db = DB::connection('dynamic');
            $db->table('ALMFOR')->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->delete();
            $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
                .'WHERE ACCAVI.AVICTIP = ? AND ACCNOT.PRD2DEL = ? AND ACCNOT.PRD2COD = ?', ['N', $delegation, $code]);
            $db->table('ACCNOT')->where('PRD2DEL', $delegation)->where('PRD2COD', $code)->delete();
            DB::connection('dynamic')->table('ALMMOV')
                ->where('PRD2DEL', $delegation)->where('PRD2COD', $code)->where('SEL2COD', VeolabStock::SERIE_UNICA)->delete();
            DB::connection('dynamic')->table('ALMSEL')
                ->where('PRD3DEL', $delegation)->where('PRD3COD', $code)->where('SEL1COD', VeolabStock::SERIE_UNICA)->delete();
        }

        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('PRD2COD', $code)
            ->update(VeolabDocuments::trashUpdate($delegation));
    }
}
