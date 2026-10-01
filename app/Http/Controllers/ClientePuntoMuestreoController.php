<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Puntos de muestreo de un cliente (LABPUM), como el árbol de la ficha del
 * cliente: un punto cuelga de la raíz o de una categoría (es_categoria T) del
 * mismo cliente. Los campos SINAC se usan en la exportación de aguas.
 *
 * No se borra un punto usado en operaciones, planificaciones o líneas de
 * factura (se da de baja); borrar una categoría borra sus puntos, si ninguno
 * está usado.
 */
class ClientePuntoMuestreoController extends ChildController
{
    protected string $table = 'LABPUM';

    protected array $keys = [
        'cliente_delegacion' => 'DEL3COD',
        'cliente_codigo'     => 'CLI3COD',
        'codigo'             => 'PUM1COD',
    ];

    protected array $mapping = [
        'cliente_delegacion'          => 'DEL3COD',
        'cliente_codigo'              => 'CLI3COD',
        'codigo'                      => 'PUM1COD',
        'descripcion'                 => 'PUMCDES',
        'referencia'                  => 'PUMCREF',
        'es_baja'                     => 'PUMBBAJ',
        'es_categoria'                => 'PUMBCAT',
        'categoria_codigo'            => 'PUM2COD',
        'sinac_codigo_punto'          => 'PUMNCPM',
        'sinac_tipo_punto'            => 'PUMNTPM',
        'sinac_codigo_identificativo' => 'PUMNCOI',
        'sinac_codigo_msc'            => 'PUMNMSC',
        'sinac_codigo_localidad'      => 'PUMNLOC',
        'ubicacion'                   => 'PUMCUBI',
        'municipio'                   => 'PUMCMUN',
        'latitud'                     => 'PUMCLAT',
        'longitud'                    => 'PUMCLON',
        'altitud'                     => 'PUMCALT',
        'error_gps'                   => 'PUMCERG',
        'proyecto'                    => 'PUMCPRO',
        'actividad'                   => 'PUMCACT',
        'instrumento_ambiental'       => 'PUMCINS',
        'minimo_analisis_control'     => 'PUMNCON',
        'minimo_analisis_completos'   => 'PUMNCOM',
        'minimo_muestras_anuales'     => 'PUMNANO',
    ];

    protected ?string $inactiveField = 'PUMBBAJ';
    protected array $searchFields = ['PUMCDES', 'PUMCREF'];

    protected string $parentGroup = 'cliente';
    protected array $parentEntity = ['SINCLI', 'CLI1COD', 15, 'El cliente no existe'];
    protected string $auditField = 'LABPUM';

    /** Textos y números que la ficha graba siempre ('' / 0). */
    private const TEXTS = ['referencia', 'ubicacion', 'municipio', 'latitud', 'longitud', 'altitud', 'error_gps',
        'proyecto', 'actividad', 'instrumento_ambiental'];
    private const NUMBERS = ['sinac_codigo_punto', 'sinac_tipo_punto', 'sinac_codigo_identificativo', 'sinac_codigo_msc',
        'sinac_codigo_localidad', 'minimo_analisis_control', 'minimo_analisis_completos', 'minimo_muestras_anuales'];

    protected function fieldRules(): array
    {
        $rules = [
            'descripcion'      => 'nullable|string|max:255',
            'es_baja'          => 'nullable|string|in:T,F',
            'es_categoria'     => 'nullable|string|in:T,F',
            'categoria_codigo' => 'nullable|integer|min:0',
            'ubicacion'        => 'nullable|string',
        ];
        foreach (self::TEXTS as $param) {
            $rules[$param] ??= 'nullable|string|max:100';
        }
        foreach (self::NUMBERS as $param) {
            $rules[$param] = 'nullable|integer|min:0';
        }

        return $rules;
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);
        $point = $keys ?: $data;

        $current = $keys ? $this->point($point, (int) $keys['codigo']) : null;
        $isCategory = ($data['es_categoria'] ?? $current->PUMBCAT ?? 'F') === 'T';

        // Una categoría ni se da de baja ni cuelga de otra (el árbol de la ficha).
        if ($isCategory) {
            if (($data['es_baja'] ?? 'F') === 'T') {
                throw new BusinessRuleException('Una categoría no se puede dar de baja');
            }
            if (! empty($data['categoria_codigo'])) {
                throw new BusinessRuleException('Una categoría no puede pertenecer a otra categoría');
            }
            if ($current && $current->PUMBCAT !== 'T') {
                $this->assertNoChildren($point);
            }
        } elseif ($current && $current->PUMBCAT === 'T') {
            $this->assertNoChildren($point);
        }

        if (! empty($data['categoria_codigo'])) {
            $category = $this->point($point, (int) $data['categoria_codigo']);
            if (! $category || $category->PUMBCAT !== 'T') {
                throw new BusinessRuleException('La categoría no existe en los puntos de muestreo del cliente');
            }
        }

        if (! $keys) {
            $data['es_baja'] ??= 'F';
            $data['es_categoria'] ??= 'F';
            $data['categoria_codigo'] ??= 0;
            foreach (self::TEXTS as $param) {
                $data[$param] ??= '';
            }
            foreach (self::NUMBERS as $param) {
                $data[$param] ??= 0;
            }
        } elseif (array_key_exists('categoria_codigo', $data)) {
            $data['categoria_codigo'] = (int) ($data['categoria_codigo'] ?? 0);
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $codes = $this->parentQuery($keys)->where('PUM2COD', $keys['codigo'])->pluck('PUM1COD')->all();
        $codes[] = (int) $keys['codigo'];

        $db = DB::connection('dynamic');
        foreach (['LABOPE' => 'alguna operación', 'LABPLO' => 'alguna planificación', 'FACLIF' => 'alguna línea de factura'] as $table => $where) {
            $used = $db->table($table)
                ->where('CLI2DEL', (string) $keys['cliente_delegacion'])->where('CLI2COD', $keys['cliente_codigo'])
                ->whereIn('PUM2COD', $codes)->exists();
            if ($used) {
                throw new BusinessRuleException("El punto de muestreo no puede ser eliminado porque está siendo referenciado en {$where}; se puede dar de baja");
            }
        }
    }

    /** Los puntos de una categoría borrada. */
    protected function deleteRelatedRecords(array $keys): void
    {
        $this->parentQuery($keys)->where('PUM2COD', $keys['codigo'])->delete();
    }

    private function point(array $point, int $code): ?object
    {
        return $this->parentQuery($point)->where('PUM1COD', $code)->first();
    }

    private function assertNoChildren(array $point): void
    {
        if ($this->parentQuery($point)->where('PUM2COD', $point['codigo'])->exists()) {
            throw new BusinessRuleException('La categoría tiene puntos de muestreo');
        }
    }
}
