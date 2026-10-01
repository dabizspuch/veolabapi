<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class CursoController extends BaseController
{
    protected string $table = 'GRHPAF';
    protected ?string $auditDescription = 'PAFCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'PAF1COD',
    ];
    protected array $searchFields = ['PAFCDES', 'PAFCOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'         => 'DEL3COD',
        'codigo'             => 'PAF1COD',
        'estado'             => 'PAFCEST',
        'fecha_prevista'     => 'PAFDPRE',
        'fecha_inicio'       => 'PAFDINI',
        'fecha_fin'          => 'PAFDFIN',
        'horas_duracion'     => 'PAFNHOR',
        'dias_plazo_evaluar' => 'PAFNDIA',
        'tipo'               => 'PAFCTIF',
        'descripcion'        => 'PAFCDES',
        'organismo'          => 'PAFCEXT',
        'observaciones'      => 'PAFCOBS',
        'objetivos'          => 'PAFCOBJ',
        'programa'           => 'PAFCPRO',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'         => 'nullable|string|max:10',
            'codigo'             => 'nullable|string|max:15',
            'estado'             => 'nullable|string|in:P,J,R,E,S,C,H|max:1',
            'fecha_prevista'     => 'nullable|date',
            'fecha_inicio'       => 'nullable|date',
            'fecha_fin'          => 'nullable|date',
            'horas_duracion'     => 'nullable|integer',
            'dias_plazo_evaluar' => 'nullable|integer',
            'tipo'               => 'nullable|string|in:I,E|max:1',
            'descripcion'        => 'nullable|string|max:100',
            'organismo'          => 'nullable|string|max:100',
            'observaciones'      => 'nullable|string|max:255',
            'objetivos'          => 'nullable|string|max:255',
            'programa'           => 'nullable|string',
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
            $query = DB::connection('dynamic')->table('GRHPAF')->where('PAFCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('PAF1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del curso ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHPAF')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('PAF1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del curso ya está en uso');
            }
        }

        return $data;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('GRHALU')
            ->where('PAF3DEL', $delegation)->where('PAF3COD', $code)->delete();

        DB::connection('dynamic')->table('GRHPRO')
            ->where('PAF3DEL', $delegation)->where('PAF3COD', $code)->delete();

        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('PAF2COD', $code)
            ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);
    }
}
