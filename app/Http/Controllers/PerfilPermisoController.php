<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\AuditsOwnerRecord;
use App\Support\ServerError;
use App\Support\VeolabPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Permisos de un perfil de usuario (ACCPYF), como la pestaña de
 * funcionalidades de FichaPerfil (ver VeolabPermissions).
 *
 * GET ?perfil_delegacion=&perfil_codigo= devuelve todas las funcionalidades
 * visibles con el acceso del perfil. PUT con la misma clave y cuerpo
 * {"permisos": [{"funcionalidad", "acceso", "especial"}]} cambia solo las
 * indicadas; los grupos se recalculan y las funcionalidades de módulos no
 * visibles se conservan. Se audita sobre el perfil (campo ACCPYF).
 */
class PerfilPermisoController extends Controller
{
    use AuditsOwnerRecord;

    public function index(Request $request)
    {
        $profile = $this->profileKey($request);
        if ($profile instanceof \Illuminate\Http\JsonResponse) {
            return $profile;
        }

        $exists = DB::connection('dynamic')->table('ACCPER')
            ->where('DEL3COD', $profile[0])->where('PER1COD', $profile[1])->exists();
        if (! $exists) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        $tree = VeolabPermissions::tree();
        $values = VeolabPermissions::values(...$profile);
        $data = $this->permissions($tree, $values);

        return response()->json(['data' => $data, 'meta' => ['total' => count($data), 'grupos' => $this->groups($tree, $values)]]);
    }

    public function update(Request $request)
    {
        $profile = $this->profileKey($request);
        if ($profile instanceof \Illuminate\Http\JsonResponse) {
            return $profile;
        }

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        $db = DB::connection('dynamic');
        try {
            $validator = Validator::make($body, [
                'permisos'                 => 'required|array|min:1',
                'permisos.*'               => 'array',
                'permisos.*.funcionalidad' => 'required|string|max:12|distinct',
                'permisos.*.acceso'        => 'nullable|string|in:E,L',
                'permisos.*.especial'      => 'nullable|integer|min:0|max:7',
            ]);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $db->beginTransaction();

            $exists = $db->table('ACCPER')
                ->where('DEL3COD', $profile[0])->where('PER1COD', $profile[1])->lockForUpdate()->exists();
            if (! $exists) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            $tree = VeolabPermissions::tree();
            $before = VeolabPermissions::values(...$profile);
            ksort($before);
            $after = $this->apply($tree, $before, $body['permisos']);

            if ($after !== $before) {
                $this->save($tree, $profile, $after);
                $this->recordOwnerChange('ACCPER', 'PER1COD', (string) $profile[1], $profile[0], 'ACCPYF');
            }

            $db->commit();

            return response()->json([
                'message' => 'Permisos actualizados correctamente',
                'data'    => $this->permissions($tree, $after),
                'meta'    => ['grupos' => $this->groups($tree, $after)],
            ]);
        } catch (ValidationException $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            return ServerError::response('v2 update ACCPYF', $e, 'Error al grabar los permisos');
        }
    }

    /** [delegación, código] del perfil o respuesta 400 si falta la clave. */
    private function profileKey(Request $request)
    {
        if (! $request->has('perfil_codigo')) {
            return response()->json(['message' => "Falta la clave 'perfil_codigo'"], 400);
        }

        return [(string) ($request->query('perfil_delegacion') ?? ''), (int) $request->query('perfil_codigo')];
    }

    /** Aplica los cambios pedidos a las máscaras y recalcula los grupos visibles. */
    private function apply(array $tree, array $values, array $changes): array
    {
        foreach ($changes as $change) {
            $code = (string) $change['funcionalidad'];
            if (isset($tree['groups'][$code])) {
                throw new BusinessRuleException("'{$code}' es un grupo: su acceso se calcula con el de sus funcionalidades");
            }
            if (! isset($tree['functions'][$code])) {
                throw new BusinessRuleException("La funcionalidad '{$code}' no existe o su módulo no está disponible");
            }

            $current = $values[$code] ?? 0;
            $access = array_key_exists('acceso', $change) ? $change['acceso'] : VeolabPermissions::access($current);
            if ($access === null) {
                unset($values[$code]);

                continue;
            }

            // Sin especial se conserva el que tenía (o el 1, como un perfil nuevo).
            $special = array_key_exists('especial', $change) && $change['especial'] !== null
                ? (int) $change['especial']
                : (VeolabPermissions::special($current) ?? 1);

            $options = count($tree['functions'][$code]['especiales']);
            $max = $options > 0 ? $options - 1 : 1;
            if ($special > $max) {
                throw new BusinessRuleException("El privilegio especial {$special} no existe en '{$code}'");
            }

            $values[$code] = VeolabPermissions::encode($access, $special);
        }

        foreach (array_keys($tree['groups']) as $group) {
            $used = false;
            foreach ($tree['functions'] as $code => $item) {
                if ($item['grupo'] === $group && ($values[$code] ?? 0) > 0) {
                    $used = true;
                    break;
                }
            }
            if ($used) {
                $values[$group] = VeolabPermissions::GRUPO;
            } else {
                unset($values[$group]);
            }
        }

        ksort($values);

        return $values;
    }

    /** Graba las filas de las funcionalidades y grupos visibles (DELETE + INSERT como Veolab). */
    private function save(array $tree, array $profile, array $values): void
    {
        $visible = array_merge(array_keys($tree['groups']), array_keys($tree['functions']));

        DB::connection('dynamic')->table('ACCPYF')
            ->where('DEL3COD', $profile[0])->where('PER3COD', $profile[1])
            ->whereIn('FUN3COD', $visible)->delete();

        $rows = [];
        foreach ($visible as $code) {
            if (($values[$code] ?? 0) > 0) {
                $rows[] = ['DEL3COD' => $profile[0], 'PER3COD' => $profile[1], 'FUN3COD' => $code, 'PYFNACC' => $values[$code]];
            }
        }
        if ($rows) {
            DB::connection('dynamic')->table('ACCPYF')->insert($rows);
        }
    }

    /** Grupos visibles con su acceso grabado (E si alguna funcionalidad tiene acceso). */
    private function groups(array $tree, array $values): array
    {
        $out = [];
        foreach ($tree['groups'] as $code => $item) {
            $out[] = [
                'grupo'       => $code,
                'descripcion' => $item['descripcion'],
                'modulo'      => $item['modulo'],
                'acceso'      => VeolabPermissions::access($values[$code] ?? 0),
            ];
        }

        return $out;
    }

    /** Filas de respuesta: una por funcionalidad visible, en el orden del árbol. */
    private function permissions(array $tree, array $values): array
    {
        $out = [];
        foreach ($tree['functions'] as $code => $item) {
            $value = $values[$code] ?? 0;
            $group = $tree['groups'][$item['grupo']];
            $out[] = [
                'funcionalidad'     => $code,
                'descripcion'       => $item['descripcion'],
                'grupo'             => $item['grupo'],
                'grupo_descripcion' => $group['descripcion'],
                'modulo'            => $item['modulo'],
                'ambito'            => $item['ambito'],
                'acceso'            => VeolabPermissions::access($value),
                'especial'          => VeolabPermissions::special($value),
                'valor'             => $value,
                'especiales'        => $item['especiales'],
            ];
        }

        return $out;
    }
}
