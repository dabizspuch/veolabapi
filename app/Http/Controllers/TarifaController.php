<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class TarifaController extends BaseController
{
    protected string $table = 'LABTAR';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'TAR1COD',
    ];
    protected ?string $inactiveField = 'TARBBAJ';
    protected array $searchFields = ['TARCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'TAR1COD',
        'descripcion' => 'TARCDES',
        'es_baja'     => 'TARBBAJ',
        'orden'       => 'TARNORD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer',
            'descripcion' => 'nullable|string|max:50',
            'es_baja'     => 'nullable|string|in:T,F|max:1',
            'orden'       => 'nullable|integer',
        ];
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
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('LABTAR')->where('TARCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('TAR1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción de la tarifa ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTAR')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('TAR1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de la tarifa ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $references = [
            ['SINCLI', 'está siendo referenciada en clientes'],
            ['LABOPE', 'está siendo referenciada en operaciones'],
            ['LABPLO', 'está siendo referenciada en planificaciones'],
            ['FACPRE', 'está siendo referenciada en presupuestos'],
            ['FACCON', 'está siendo referenciada en contratos'],
        ];
        foreach ($references as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('TAR2DEL', $delegation)->where('TAR2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("La tarifa no puede ser eliminada porque {$reason}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('LABSYF')
            ->where('TAR3DEL', $delegation)->where('TAR3COD', $code)->delete();

        DB::connection('dynamic')->table('LABTYF')
            ->where('TAR3DEL', $delegation)->where('TAR3COD', $code)->delete();
    }
}
