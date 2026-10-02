<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Estados de los eventos de agenda (AGEEST), de Configurar calendario.
 * Color: entero de color de VB (BGR). Al borrar uno, los eventos que lo
 * usan se quedan sin estado (Veolab los deja apuntando a un estado borrado).
 */
class AgendaEstadoController extends BaseController
{
    protected string $table = 'AGEEST';
    protected ?string $auditDescription = 'ESTCDES';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'EST1COD',
    ];
    protected array $searchFields = ['ESTCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'  => 'DEL3COD',
        'codigo'      => 'EST1COD',
        'descripcion' => 'ESTCDES',
        'color'       => 'ESTNCOL',
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
            ->where('EST2DEL', $keys['delegacion'] ?? '')->where('EST2COD', $keys['codigo'])
            ->update(['EST2DEL' => '', 'EST2COD' => 0]);
    }
}
