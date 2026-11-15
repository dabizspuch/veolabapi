<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabTasks;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Tareas pendientes (Veolab 3, opción COM_TAR del menú Notificaciones):
 * lo que un usuario tiene por hacer, calculado al vuelo con las mismas
 * consultas y reglas que el escritorio (App\Support\VeolabTasks). Solo
 * lectura: una tarea se quita haciéndola en su registro.
 *
 *  - GET /tareas-pendientes?usuario_delegacion=&usuario_codigo=
 *    [&tipo=IC,IT...&grupo=Q,P...&solo_vencidas=T&solo_mias=T]
 *  - GET /tareas-pendientes/recuento (mismos parámetros): total, vencidas y
 *    número por tipo, para el icono de Veolab Web.
 *
 * Recurso desde Veolab 3 (422 en bases anteriores). Exige el módulo COM y
 * la funcionalidad COM_TAR en el perfil del usuario (422 si no, como el
 * resto de reglas de la API, que no usa 403); las tareas de Calidad solo
 * salen con el módulo CAL.
 */
class TareaPendienteController extends Controller
{
    /** GET /tareas-pendientes: las tareas, vencidas primero y después por fecha (sin fecha al final) y por tipo. */
    public function index(Request $request)
    {
        $result = $this->calculate($request);
        if (! is_array($result)) {
            return $result;
        }
        [$tasks, $calculator] = $result;

        return response()->json([
            'data' => $tasks,
            'meta' => $this->meta($tasks, $calculator),
        ]);
    }

    /** GET /tareas-pendientes/recuento: {total, vencidas, por_tipo} (todos los tipos, en su orden). */
    public function count(Request $request)
    {
        $result = $this->calculate($request);
        if (! is_array($result)) {
            return $result;
        }
        [$tasks, $calculator] = $result;

        $byType = array_fill_keys(array_keys(VeolabTasks::TYPES), 0);
        foreach ($tasks as $task) {
            $byType[$task['tipo']]++;
        }

        return response()->json([
            'data' => [
                'total'    => count($tasks),
                'vencidas' => count(array_filter($tasks, fn ($t) => $t['vencida'])),
                'por_tipo' => $byType,
            ],
            'meta' => [
                'con_empleado' => $calculator->hasEmployee() ? 'T' : 'F',
                'omitidos'     => $calculator->omitted(),
                'version_bd'   => VeolabVersion::current(),
            ],
        ]);
    }

    /** Tareas filtradas y el calculador, o la respuesta de error. */
    private function calculate(Request $request)
    {
        if (! VeolabVersion::isV3()) {
            return response()->json(['message' => VeolabVersion::message(VeolabVersion::V3, 'Las tareas pendientes')], 422);
        }

        $validator = Validator::make($request->query(), [
            'usuario_delegacion' => 'nullable|string|max:10',
            'usuario_codigo'     => 'required|string|max:15',
            'tipo'               => 'nullable|string',
            'grupo'              => 'nullable|string',
            'solo_vencidas'      => 'nullable|string|in:T,F',
            'solo_mias'          => 'nullable|string|in:T,F',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $types = $this->list($request->query('tipo'));
        $groups = $this->list($request->query('grupo'));
        $errors = [];
        if ($bad = array_diff($types, array_keys(VeolabTasks::TYPES))) {
            $errors['tipo'] = ['Tipo de tarea desconocido: '.implode(', ', $bad).' (admite '.implode(', ', array_keys(VeolabTasks::TYPES)).')'];
        }
        if ($bad = array_diff($groups, array_keys(VeolabTasks::GROUPS))) {
            $errors['grupo'] = ['Grupo desconocido: '.implode(', ', $bad).' (admite '.implode(', ', array_keys(VeolabTasks::GROUPS)).')'];
        }
        if ($errors) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => $errors], 422);
        }

        try {
            $calculator = VeolabTasks::forUser((string) ($request->query('usuario_delegacion') ?? ''), (string) $request->query('usuario_codigo'));
            $tasks = $calculator->tasks();
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return ServerError::response('v2 tareas pendientes', $e, 'Error al calcular las tareas pendientes');
        }

        $overdueOnly = $request->query('solo_vencidas') === 'T';
        $mineOnly = $request->query('solo_mias') === 'T';
        $tasks = array_values(array_filter($tasks, function (array $task) use ($types, $groups, $overdueOnly, $mineOnly) {
            return (! $types || in_array($task['tipo'], $types, true))
                && (! $groups || in_array($task['grupo'], $groups, true))
                && (! $overdueOnly || $task['vencida'])
                && (! $mineOnly || $task['asignada'] === 'personal');
        }));

        return [$tasks, $calculator];
    }

    /** Lista separada por comas, en mayúsculas y sin vacíos. */
    private function list($value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v) => strtoupper(trim($v)), explode(',', $value)), fn ($v) => $v !== ''));
    }

    private function meta(array $tasks, VeolabTasks $calculator): array
    {
        return [
            'total'        => count($tasks),
            'vencidas'     => count(array_filter($tasks, fn ($t) => $t['vencida'])),
            'con_empleado' => $calculator->hasEmployee() ? 'T' : 'F',
            'omitidos'     => $calculator->omitted(),
            'version_bd'   => VeolabVersion::current(),
        ];
    }
}
