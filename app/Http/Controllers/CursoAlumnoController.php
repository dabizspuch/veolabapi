<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/** Alumnos de un curso del plan de formación (GRHALU) y su evaluación. */
class CursoAlumnoController extends RelationController
{
    protected string $table = 'GRHALU';

    protected array $keys = [
        'curso_delegacion'     => 'PAF3DEL',
        'curso_codigo'         => 'PAF3COD',
        'empleado_delegacion'  => 'EMP3DEL',
        'empleado_codigo'      => 'EMP3COD',
    ];

    protected array $mapping = [
        'curso_delegacion'     => 'PAF3DEL',
        'curso_codigo'         => 'PAF3COD',
        'empleado_delegacion'  => 'EMP3DEL',
        'empleado_codigo'      => 'EMP3COD',
        'evaluacion'           => 'ALUNEVA',
        'fecha_evaluacion'     => 'ALUDEVA',
        'es_evidencia_adjunta' => 'ALUBADE',
        'es_no_finalizado'     => 'ALUBNAS',
        'comentarios'          => 'ALUCCOM',
        'evaluador_delegacion' => 'EMP2DEL',
        'evaluador_codigo'     => 'EMP2COD',
    ];

    protected array $foreignKeys = ['evaluador' => 'int'];

    protected array $entities = [
        'curso'    => ['GRHPAF', 'PAF1COD', 15, 'El curso no existe'],
        'empleado' => ['GRHEMP', 'EMP1COD', 'int', 'El empleado no existe'],
    ];

    protected string $auditOwner = 'curso';
    protected string $auditField = 'GRHALU';

    protected function fieldRules(): array
    {
        return [
            'evaluacion'           => 'nullable|integer',
            'fecha_evaluacion'     => 'nullable|date',
            'es_evidencia_adjunta' => 'nullable|string|in:T,F',
            'es_no_finalizado'     => 'nullable|string|in:T,F',
            'comentarios'          => 'nullable|string|max:255',
            'evaluador_delegacion' => 'nullable|string|max:10',
            'evaluador_codigo'     => 'nullable|integer',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        parent::validateRelationships($data);

        if (! empty($data['evaluador_codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', (string) ($data['evaluador_delegacion'] ?? ''))
                ->where('EMP1COD', $data['evaluador_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El evaluador no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);

        if (! empty($data['fecha_evaluacion'])) {
            $data['fecha_evaluacion'] = (new \DateTime($data['fecha_evaluacion']))->format('Y-m-d 00:00:00');
        }
        if (! $keys) {
            // Como la ficha del curso: las casillas se graban siempre T/F.
            $data['es_evidencia_adjunta'] ??= 'F';
            $data['es_no_finalizado'] ??= 'F';
        }

        return $data;
    }
}
