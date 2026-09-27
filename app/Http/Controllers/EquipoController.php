<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class EquipoController extends BaseController
{
    protected string $table = 'LABEQU';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'EQU1COD',
    ];
    protected ?string $inactiveField = 'EQUBBAJ';
    protected array $searchFields = ['EQUCDES'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'             => 'DEL3COD',
        'codigo'                 => 'EQU1COD',
        'descripcion'            => 'EQUCDES',
        'marca'                  => 'EQUCMAR',
        'modelo'                 => 'EQUCMOD',
        'serie'                  => 'EQUCSER',
        'referencia'             => 'EQUCREF',
        'fabricante'             => 'EQUCFAB',
        'ano_fabricacion'        => 'EQUCANF',
        'ubicacion'              => 'EQUCUBI',
        'manual'                 => 'EQUCMAO',
        'especificaciones'       => 'EQUCETC',
        'condiciones'            => 'EQUCCOA',
        'tipo_fluido'            => 'EQUCTIF',
        'volumen_fluido'         => 'EQUCVOF',
        'preservacion'           => 'EQUCPRE',
        'enfriamiento'           => 'EQUCENF',
        'reglas_analisis'        => 'EQUCREA',
        'rango_kv'               => 'EQUCRKV',
        'rango_mva'              => 'EQUCRMV',
        'estado'                 => 'EQUCEST',
        'fecha_servicio'         => 'EQUDFPE',
        'fecha_baja'             => 'EQUDBAS',
        'observaciones'          => 'EQUCOBS',
        'es_baja'                => 'EQUBBAJ',
        'tipo_equipo_delegacion' => 'TEQ2DEL',
        'tipo_equipo_codigo'     => 'TEQ2COD',
        'cliente_delegacion'     => 'CLI2DEL',
        'cliente_codigo'         => 'CLI2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'             => 'nullable|string|max:10',
            'codigo'                 => 'nullable|string|max:20',
            'descripcion'            => 'nullable|string|max:100',
            'marca'                  => 'nullable|string|max:50',
            'modelo'                 => 'nullable|string|max:50',
            'serie'                  => 'nullable|string|max:30',
            'referencia'             => 'nullable|string|max:30',
            'fabricante'             => 'nullable|string|max:50',
            'ano_fabricacion'        => 'nullable|string|max:10',
            'ubicacion'              => 'nullable|string|max:255',
            'manual'                 => 'nullable|string|max:255',
            'especificaciones'       => 'nullable|string|max:255',
            'condiciones'            => 'nullable|string|max:255',
            'tipo_fluido'            => 'nullable|string|max:50',
            'volumen_fluido'         => 'nullable|string|max:50',
            'preservacion'           => 'nullable|string|max:50',
            'enfriamiento'           => 'nullable|string|max:50',
            'reglas_analisis'        => 'nullable|string|max:50',
            'rango_kv'               => 'nullable|string|max:20',
            'rango_mva'              => 'nullable|string|max:20',
            'estado'                 => 'nullable|string|in:N,U,L,F,B|max:1',
            'fecha_servicio'         => 'nullable|date',
            'fecha_baja'             => 'nullable|date',
            'observaciones'          => 'nullable|string',
            'es_baja'                => 'nullable|string|in:T,F|max:1',
            'tipo_equipo_delegacion' => 'nullable|string|max:10',
            'tipo_equipo_codigo'     => 'nullable|integer',
            'cliente_delegacion'     => 'nullable|string|max:10',
            'cliente_codigo'         => 'nullable|string|max:15',
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

        if (! empty($data['tipo_equipo_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTEQ')
                ->where('DEL3COD', $data['tipo_equipo_delegacion'] ?? '')
                ->where('TEQ1COD', $data['tipo_equipo_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El tipo de equipo no existe');
            }
        }

        if (! empty($data['cliente_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', $data['cliente_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El cliente no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['descripcion'])) {
            $query = DB::connection('dynamic')->table('LABEQU')->where('EQUCDES', $data['descripcion']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('EQU1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('La descripción del equipo ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('LABEQU')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('EQU1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del equipo ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $used = DB::connection('dynamic')->table('LABPLO')
            ->where('EQU2DEL', $delegation)->where('EQU2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El equipo no puede ser eliminado porque está vinculado a alguna planificación');
        }

        $used = DB::connection('dynamic')->table('LABOPE')
            ->where('EQU2DEL', $delegation)->where('EQU2COD', $code)->exists();
        if ($used) {
            throw new BusinessRuleException('El equipo no puede ser eliminado porque está vinculado a alguna operación');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('EQU2COD', $code)
            ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);
    }
}
