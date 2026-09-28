<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class UsuarioController extends BaseController
{
    protected string $table = 'ACCUSU';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'USU1COD',
    ];
    protected ?string $inactiveField = 'USUBBAJ';
    protected array $searchFields = ['USUCNOM', 'USUCOBS'];

    protected bool $generatesCode = true;

    protected array $foreignKeys = [
        'perfil'   => 'int',
        'empleado' => 'int',
        'cliente'  => 'string',
    ];

    protected array $mapping = [
        'delegacion'              => 'DEL3COD',
        'codigo'                  => 'USU1COD',
        'nombre'                  => 'USUCNOM',
        'es_conectado'            => 'USUBCON',
        'idioma'                  => 'USUNIDI',
        'certificado'             => 'USUCCER',
        'usuario_windows'         => 'USUCWIN',
        'sid_windows'             => 'USUCSID',
        'ocultar_aviso_minimizar' => 'USUBOAP',
        'observaciones'           => 'USUCOBS',
        'tipo'                    => 'USUNTIP',
        'fecha_alta'              => 'USUDALT',
        'fecha_baja'              => 'USUDBAJ',
        'fecha_ultimo_acceso'     => 'USUTULT',
        'perfil_delegacion'       => 'PER2DEL',
        'perfil_codigo'           => 'PER2COD',
        'empleado_delegacion'     => 'EMP2DEL',
        'empleado_codigo'         => 'EMP2COD',
        'cliente_delegacion'      => 'CLI2DEL',
        'cliente_codigo'          => 'CLI2COD',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'              => 'nullable|string|max:10',
            'codigo'                  => 'nullable|string|max:15',
            'nombre'                  => 'nullable|string|max:100',
            'es_conectado'            => 'nullable|string|in:T,F|max:1',
            'idioma'                  => 'nullable|integer|min:0',
            'certificado'             => 'nullable|string|max:100',
            'usuario_windows'         => 'nullable|string|max:50',
            'sid_windows'             => 'nullable|string|max:50',
            'ocultar_aviso_minimizar' => 'nullable|string|in:T,F|max:1',
            'observaciones'           => 'nullable|string',
            'tipo'                    => 'nullable|integer',
            'fecha_alta'              => 'nullable|date',
            'fecha_baja'              => 'nullable|date',
            'fecha_ultimo_acceso'     => 'nullable|date',
            'perfil_delegacion'       => 'nullable|string|max:10',
            'perfil_codigo'           => 'nullable|integer',
            'empleado_delegacion'     => 'nullable|string|max:10',
            'empleado_codigo'         => 'nullable|integer',
            'cliente_delegacion'      => 'nullable|string|max:10',
            'cliente_codigo'          => 'nullable|string|max:15',
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

        if (! empty($data['perfil_codigo'])) {
            $exists = DB::connection('dynamic')->table('ACCPER')
                ->where('DEL3COD', $data['perfil_delegacion'] ?? '')
                ->where('PER1COD', $data['perfil_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El perfil de usuario no existe');
            }
        }

        if (! empty($data['empleado_codigo'])) {
            $exists = DB::connection('dynamic')->table('GRHEMP')
                ->where('DEL3COD', $data['empleado_delegacion'] ?? '')
                ->where('EMP1COD', $data['empleado_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El empleado no existe');
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

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('ACCUSU')->where('USUCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('USU1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del usuario ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('ACCUSU')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('USU1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del usuario ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $references = [
            ['DOCVER', 'está siendo referenciado en alguna versión de documento'],
            ['ALMMOV', 'está siendo referenciado en algún movimiento de inventario'],
            ['LABINF', 'está siendo referenciado en algún informe'],
            ['LABFIR', 'está siendo referenciado en alguna firma'],
        ];
        foreach ($references as [$table, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('USU2DEL', $delegation)->where('USU2COD', $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El usuario no puede ser eliminado porque {$reason}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('ACCUYV')
            ->where('DEL3COD', $delegation)->where('USU3COD', $code)->delete();

        DB::connection('dynamic')->table('ACCSES')
            ->where('DEL3COD', $delegation)->where('USU2COD', $code)->delete();

        DB::connection('dynamic')->table('ACCAVI')
            ->where('USU2DEL', $delegation)->where('USU2COD', $code)->delete();

        DB::connection('dynamic')->table('ACCNOT')
            ->where('USU2DEL', $delegation)->where('USU2COD', $code)->delete();

        DB::connection('dynamic')->table('ACCFIR')
            ->where('DEL3COD', $delegation)->where('USU3COD', $code)->delete();

        DB::connection('dynamic')->table('AGEAGE')
            ->where('USU3DEL', $delegation)->where('USU3COD', $code)->delete();

        DB::connection('dynamic')->table('AGEFEC')
            ->where('USU3DEL', $delegation)->where('USU3COD', $code)->delete();

        DB::connection('dynamic')->table('AGEASI')
            ->where('USU3DEL', $delegation)->where('USU3COD', $code)->delete();

        DB::connection('dynamic')->table('AGEASI')
            ->where('USA3DEL', $delegation)->where('USA3COD', $code)->delete();
    }
}
