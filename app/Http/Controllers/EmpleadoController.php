<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class EmpleadoController extends BaseController
{
    protected string $table = 'GRHEMP';
    protected ?string $auditDescription = 'EMPCNOM';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'EMP1COD',
    ];
    protected ?string $inactiveField = 'EMPBBAJ';
    protected array $searchFields = ['EMPCNOM'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'    => 'DEL3COD',
        'codigo'        => 'EMP1COD',
        'nombre'        => 'EMPCNOM',
        'tratamiento'   => 'EMPCTRA',
        'direccion'     => 'EMPCDIR',
        'poblacion'     => 'EMPCPOB',
        'provincia'     => 'EMPCPRO',
        'codigo_postal' => 'EMPCCOP',
        'telefono'      => 'EMPCTEL',
        'movil'         => 'EMPCMOV',
        'nif'           => 'EMPCNIF',
        'nss'           => 'EMPCNSS',
        'cedula'        => 'EMPCCEP',
        'abreviatura'   => 'EMPCABR',
        'tipo'          => 'EMPCTIP',
        'email'         => 'EMPCEMA',
        'fecha_alta'    => 'EMPDALT',
        'fecha_baja'    => 'EMPDBAJ',
        'observaciones' => 'EMPCOBS',
        'es_analista'   => 'EMPBANA',
        'es_recolector' => 'EMPBREC',
        'es_comercial'  => 'EMPBCOM',
        'es_baja'       => 'EMPBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'    => 'nullable|string|max:10',
            'codigo'        => 'nullable|integer',
            'nombre'        => 'nullable|string|max:100',
            'tratamiento'   => 'nullable|string|max:10',
            'direccion'     => 'nullable|string|max:255',
            'poblacion'     => 'nullable|string|max:100',
            'provincia'     => 'nullable|string|max:100',
            'codigo_postal' => 'nullable|string|max:10',
            'telefono'      => 'nullable|string|max:40',
            'movil'         => 'nullable|string|max:20',
            'nif'           => 'nullable|string|max:15',
            'nss'           => 'nullable|string|max:20',
            'cedula'        => 'nullable|string|max:20',
            'abreviatura'   => 'nullable|string|max:20',
            'tipo'          => 'nullable|string|max:50',
            'email'         => 'nullable|string|max:100|email',
            'fecha_alta'    => 'nullable|date',
            'fecha_baja'    => 'nullable|date',
            'observaciones' => 'nullable|string',
            'es_analista'   => 'nullable|string|in:T,F|max:1',
            'es_recolector' => 'nullable|string|in:T,F|max:1',
            'es_comercial'  => 'nullable|string|in:T,F|max:1',
            'es_baja'       => 'nullable|string|in:T,F|max:1',
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

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('GRHEMP')->where('EMPCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('EMP1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del empleado ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('EMP1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del empleado ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        // Referencias como parte de PK (EMP3*).
        $refs3 = [
            ['LABOYE', 'está vinculado como analista en alguna operación'],
            ['LABORE', 'está vinculado a alguna orden'],
            ['LABTYE', 'está vinculado a algún parámetro'],
            ['GRHPRO', 'está definido como profesor en algún curso'],
        ];
        foreach ($refs3 as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('EMP3DEL', $delegation)->where('EMP3COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El empleado no puede ser eliminado porque {$reason}");
            }
        }

        // Referencias simples (EMP2*).
        $refs2 = [
            ['LABRES', 'está vinculado como analista en algún parámetro de operación'],
            ['LABPYT', 'está vinculado como analista en alguna planificación'],
            ['LABOPE', 'está vinculado como recolector en alguna operación'],
            ['LABPLO', 'está vinculado como recolector en alguna planificación'],
            ['ACCUSU', 'está vinculado con algún usuario'],
            ['FACPRE', 'está vinculado con algún presupuesto'],
            ['LABRED', 'está vinculado con algún residuo'],
        ];
        foreach ($refs2 as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('EMP2DEL', $delegation)->where('EMP2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El empleado no puede ser eliminado porque {$reason}");
            }
        }

        // Alumno de formación (referenciado como EMP2 o EMP3).
        $used = DB::connection('dynamic')->table('GRHALU')
            ->where(function ($q) use ($delegation, $code) {
                $q->where('EMP2DEL', $delegation)->where('EMP2COD', $code);
            })
            ->orWhere(function ($q) use ($delegation, $code) {
                $q->where('EMP3DEL', $delegation)->where('EMP3COD', $code);
            })
            ->exists();
        if ($used) {
            throw new BusinessRuleException('El empleado no puede ser eliminado porque está definido como alumno en alguna formación');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $relatedEmp3 = ['GRHEYC', 'GRHAUS', 'GRHCUR', 'GRHFOR', 'GRHCLI'];
        foreach ($relatedEmp3 as $table) {
            DB::connection('dynamic')->table($table)
                ->where('EMP3DEL', $delegation)->where('EMP3COD', $code)->delete();
        }

        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('EMP2COD', $code)
            ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);
    }
}
