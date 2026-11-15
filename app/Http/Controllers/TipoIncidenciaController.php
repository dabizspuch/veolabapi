<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabQuality;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Tipos de incidencia de calidad (CALTIN, desde Veolab 3), como
 * TiposIncidencia: descripción única (entre los comunes y los de la
 * delegación), clase que proponen al elegirlos en una incidencia (opcional)
 * y baja. El código sale del contador ACCCLT 'CALTIN'. Un tipo usado en
 * incidencias no se borra: se da de baja.
 */
class TipoIncidenciaController extends BaseController
{
    protected string $table = 'CALTIN';
    protected ?string $sinceVersion = VeolabVersion::V3;
    protected ?string $auditDescription = 'TINCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TIN1COD',
    ];
    protected ?string $inactiveField = 'TINBBAJ';
    protected array $searchFields = ['TINCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'TIN1COD',
        'descripcion' => 'TINCDES',
        'clase'       => 'TINCCLA',   // clase que propone: Q, T, N, M, R o vacía
        'es_baja'     => 'TINBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer|min:1',
            'descripcion' => (request()->isMethod('post') ? 'required' : 'sometimes').'|string|max:100',
            'clase'       => 'nullable|string|in:'.implode(',', VeolabQuality::CLASSES),
            'es_baja'     => 'nullable|string|in:T,F',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
        if (! empty($data['delegacion'])
            && ! DB::connection('dynamic')->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
            throw new BusinessRuleException('La delegación no existe');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isNew = ! $keys;
        $delegation = (string) ($isNew ? ($data['delegacion'] ?? '') : $keys['delegacion']);

        if (array_key_exists('descripcion', $data)) {
            $data['descripcion'] = trim((string) $data['descripcion']);
            if ($data['descripcion'] === '') {
                throw new BusinessRuleException('La descripción es obligatoria');
            }
            // GuardarDescripcion: no se repite entre los comunes y los de la delegación
            $query = DB::connection('dynamic')->table('CALTIN')
                ->whereIn('DEL3COD', array_unique(['', $delegation]))->where('TINCDES', $data['descripcion']);
            if (! $isNew) {
                $query->where(fn ($q) => $q->where('TIN1COD', '<>', (int) $keys['codigo'])->orWhere('DEL3COD', '<>', $delegation));
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del tipo de incidencia ya está en uso');
            }
        }
        if (array_key_exists('clase', $data)) {
            $data['clase'] = ($data['clase'] ?? '') === '' ? null : $data['clase'];
        }
        if ($isNew) {
            $data['es_baja'] ??= 'F';
        } elseif (array_key_exists('es_baja', $data) && $data['es_baja'] === null) {
            $data['es_baja'] = 'F';
        }

        return $data;
    }

    /** BorradoFisicoPermitido: no si alguna incidencia lo usa. */
    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
        $used = DB::connection('dynamic')->table('CALINC')
            ->where('TIN2DEL', (string) $keys['delegacion'])->where('TIN2COD', (int) $keys['codigo'])->exists();
        if ($used) {
            throw new BusinessRuleException('El tipo de incidencia no puede ser eliminado porque está siendo referenciado en incidencias: se puede dar de baja');
        }
    }
}
