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

    // El código es el nombre de inicio de sesión: se indica siempre (como en
    // FichaUsuario), no se genera.
    protected bool $generatesCode = false;

    protected array $foreignKeys = [
        'perfil'   => 'int',
        'empleado' => 'int',
        'cliente'  => 'string',
    ];

    // es_conectado, sid_windows y fecha_ultimo_acceso los mantiene Veolab:
    // se leen pero no se escriben (no tienen regla de validación).
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
        'es_baja'                 => 'USUBBAJ',
        'fecha_ultimo_acceso'     => 'USUTULT',
        'perfil_delegacion'       => 'PER2DEL',
        'perfil_codigo'           => 'PER2COD',
        'empleado_delegacion'     => 'EMP2DEL',
        'empleado_codigo'         => 'EMP2COD',
        'cliente_delegacion'      => 'CLI2DEL',
        'cliente_codigo'          => 'CLI2COD',
    ];

    /** Tipos de usuario (USUNTIP; 2 = otro). */
    private const TIPO_EMPLEADO = 0;
    private const TIPO_CLIENTE = 1;

    protected function rules(): array
    {
        return [
            'delegacion'              => 'nullable|string|max:10',
            // Sin puntos ni el carácter reservado ¶ (FichaUsuario.CamposValidos).
            'codigo'                  => ['nullable', 'string', 'max:15', 'not_regex:/[.¶]/u'],
            'nombre'                  => 'nullable|string|max:100',
            'idioma'                  => 'nullable|integer|min:0',
            'certificado'             => 'nullable|string|max:100',
            'usuario_windows'         => 'nullable|string|max:50',
            'ocultar_aviso_minimizar' => 'nullable|string|in:T,F|max:1',
            'observaciones'           => 'nullable|string',
            'tipo'                    => 'nullable|integer|in:0,1,2',
            'fecha_alta'              => 'nullable|date',
            'fecha_baja'              => 'nullable|date',
            'es_baja'                 => 'nullable|string|in:T,F|max:1',
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
        $before = null;

        if ($isCreating) {
            $data['delegacion'] = $data['delegacion'] ?? '';
            if (trim((string) ($data['codigo'] ?? '')) === '') {
                throw new BusinessRuleException('El código del usuario es obligatorio');
            }
            if (trim((string) ($data['nombre'] ?? '')) === '') {
                throw new BusinessRuleException('El nombre del usuario es obligatorio');
            }
            $delegation = $data['delegacion'];
            $code = $data['codigo'];

            $exists = DB::connection('dynamic')->table('ACCUSU')
                ->where('DEL3COD', $delegation)->where('USU1COD', $code)->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del usuario ya está en uso');
            }
        } else {
            $delegation = $keys['delegacion'] ?? '';
            $code = $keys['codigo'] ?? null;
            if (array_key_exists('nombre', $data) && trim((string) $data['nombre']) === '') {
                throw new BusinessRuleException('El nombre del usuario es obligatorio');
            }
            $before = DB::connection('dynamic')->table('ACCUSU')
                ->where('DEL3COD', $delegation)->where('USU1COD', $code)->first();
        }

        // Nombre único en la delegación del usuario y en la común (''), como FichaUsuario.
        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('ACCUSU')
                ->where('USUCNOM', $data['nombre'])
                ->whereIn('DEL3COD', array_unique(['', $delegation]));
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('USU1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del usuario ya está en uso');
            }
        }

        if ($isCreating) {
            $data['tipo'] = $data['tipo'] ?? self::TIPO_EMPLEADO;
            $data['fecha_alta'] = $data['fecha_alta'] ?? date('Y-m-d 00:00:00');
        }

        // Como FichaUsuario: el empleado solo se guarda en usuarios de tipo
        // empleado y el cliente solo en los de tipo cliente.
        $type = (int) ($data['tipo'] ?? $before->USUNTIP ?? self::TIPO_EMPLEADO);
        if ($type !== self::TIPO_EMPLEADO) {
            if (! empty($data['empleado_codigo'])) {
                throw new BusinessRuleException('Solo los usuarios de tipo empleado pueden tener empleado');
            }
            $data['empleado_delegacion'] = null;
            $data['empleado_codigo'] = null;
        }
        if ($type !== self::TIPO_CLIENTE) {
            if (! empty($data['cliente_codigo'])) {
                throw new BusinessRuleException('Solo los usuarios de tipo cliente pueden tener cliente');
            }
            $data['cliente_delegacion'] = null;
            $data['cliente_codigo'] = null;
        }

        // Baja: la marca y la fecha van juntas (T con fecha, F sin ella).
        if (($data['es_baja'] ?? null) === 'T') {
            $data['fecha_baja'] = $data['fecha_baja'] ?? $before->USUDBAJ ?? date('Y-m-d 00:00:00');
        } elseif (($data['es_baja'] ?? null) === 'F') {
            $data['fecha_baja'] = null;
        } else {
            unset($data['es_baja']);
            if (array_key_exists('fecha_baja', $data)) {
                $data['es_baja'] = empty($data['fecha_baja']) ? 'F' : 'T';
            } elseif ($isCreating) {
                $data['es_baja'] = 'F';
            }
        }

        // El SID es el de la cuenta de Windows anterior: si la cuenta cambia
        // se vacía (Veolab lo vuelve a tomar al iniciar sesión con ella).
        if (array_key_exists('usuario_windows', $data)
            && ($isCreating || (string) $data['usuario_windows'] !== (string) ($before->USUCWIN ?? ''))) {
            $data['sid_windows'] = '';
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
