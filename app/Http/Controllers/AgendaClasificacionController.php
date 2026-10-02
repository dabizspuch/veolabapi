<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Clasificaciones de los eventos de agenda (AGECLA), de Configurar calendario.
 * Color: entero de color de VB (BGR). Al borrar una, los eventos que la
 * usan se quedan sin clasificación (Veolab los deja apuntando a una borrada).
 */
class AgendaClasificacionController extends BaseController
{
    protected string $table = 'AGECLA';
    protected ?string $auditDescription = 'CLACDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'CLA1COD',
    ];
    protected array $searchFields = ['CLACDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'CLA1COD',
        'descripcion' => 'CLACDES',
        'color'       => 'CLANCOL',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'  => 'nullable|string|max:10',
            'codigo'      => 'nullable|integer|min:1',
            'descripcion' => 'nullable|string|max:50',
            'color'       => 'nullable|integer|min:0',
        ];
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('AGEAGE')
            ->where('CLA2DEL', $keys['delegacion'] ?? '')->where('CLA2COD', $keys['codigo'])
            ->update(['CLA2DEL' => '', 'CLA2COD' => 0]);
    }
}
