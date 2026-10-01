<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use Illuminate\Support\Facades\DB;

/**
 * Marcas de resultados (LABMAR), como la configuración de rangos y marcas.
 *
 * Además de las normales (código automático) hay dos especiales por
 * delegación: la predeterminada (código -1) y la de no evaluable (-2), que se
 * crean con "tipo". Una marca usada en intervalos o resultados no se borra
 * (Veolab lo permite tras preguntar; en la API, darla de baja).
 */
class MarcaController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABMAR';
    protected ?string $auditDescription = 'MARCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'MAR1COD',
    ];
    protected ?string $inactiveField = 'MARBBAJ';
    protected array $searchFields = ['MARCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'    => 'DEL3COD',
        'codigo'        => 'MAR1COD',
        'descripcion'   => 'MARCDES',
        'aviso'         => 'MARCAVI',
        'sustituir_por' => 'MARCSUS',
        'tamano_fuente' => 'MARNTAF',
        'color_fuente'  => 'MARNCOF',
        'color_fondo'   => 'MARNCOB',
        'estilo_fuente' => 'MARCESF',
        'es_baja'       => 'MARBBAJ',
    ];

    /** Tipos de marca especiales y su código. */
    private const SPECIAL = ['predeterminada' => -1, 'no_evaluable' => -2];

    protected function rules(): array
    {
        return [
            'delegacion'    => 'nullable|string|max:10',
            'codigo'        => 'nullable|integer|min:1',
            'tipo'          => 'nullable|string|in:normal,predeterminada,no_evaluable',
            'descripcion'   => 'nullable|string|max:20',
            'aviso'         => 'nullable|string|max:255',
            'sustituir_por' => 'nullable|string|max:50',
            'tamano_fuente' => 'nullable|integer|min:0',
            'color_fuente'  => 'nullable|integer|min:0',
            'color_fondo'   => 'nullable|integer|min:0',
            // N normal, G negrita, C cursiva, S subrayado, T tachado, R negrita cursiva.
            'estilo_fuente' => 'nullable|string|in:N,G,C,S,T,R',
            'es_baja'       => 'nullable|string|in:T,F',
        ];
    }

    /** Las especiales llevan su código fijo en lugar del automático. */
    protected function create(array $data)
    {
        $special = self::SPECIAL[$data['tipo'] ?? ''] ?? null;
        if ($special !== null) {
            if (isset($data['codigo'])) {
                return response()->json(['message' => 'El código de una marca especial no se indica'], 422);
            }
            $this->generatesCode = false;
        }

        return parent::create($data);
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $special = self::SPECIAL[$data['tipo'] ?? ''] ?? null;
        $typeGiven = array_key_exists('tipo', $data);
        unset($data['tipo']);

        if (! $keys) {
            $data['delegacion'] = (string) ($data['delegacion'] ?? '');
            if ($special !== null) {
                $data['codigo'] = $special;
                $exists = DB::connection('dynamic')->table('LABMAR')
                    ->where('DEL3COD', $data['delegacion'])->where('MAR1COD', $special)->exists();
                if ($exists) {
                    throw new BusinessRuleException('La delegación ya tiene la marca '.str_replace('_', ' ', array_search($special, self::SPECIAL, true)));
                }
            }
            // Valores de una marca nueva en la configuración de Veolab.
            $data['tamano_fuente'] ??= 0;
            $data['color_fuente'] ??= 0;
            $data['color_fondo'] ??= 0xFFFFFF;
            $data['estilo_fuente'] ??= 'N';
            $data['es_baja'] ??= 'F';
        } elseif ($typeGiven) {
            throw new BusinessRuleException('El tipo de la marca no se puede cambiar');
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        foreach (['LABCYR' => 'algún intervalo de parámetro', 'LABCOR' => 'algún resultado'] as $table => $where) {
            $used = DB::connection('dynamic')->table($table)
                ->where('MAR2DEL', (string) $keys['delegacion'])->where('MAR2COD', $keys['codigo'])->exists();
            if ($used) {
                throw new BusinessRuleException("La marca no puede ser eliminada porque está vinculada a {$where}; se puede dar de baja");
            }
        }
    }

    /** Como Veolab: los dictámenes que la usaban quedan sin marca. */
    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('LABDIC')
            ->where('MAR2DEL', (string) $keys['delegacion'])->where('MAR2COD', $keys['codigo'])
            ->update(['MAR2DEL' => '', 'MAR2COD' => 0]);
    }

    /** Tipo de la marca según su código. */
    protected function appendRelatedData(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['tipo'] = array_search((int) $row['codigo'], self::SPECIAL, true) ?: 'normal';
        }

        return $rows;
    }
}
