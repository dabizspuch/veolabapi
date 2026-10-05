<?php

namespace App\Http\Middleware;

use App\Support\ServerError;
use Closure;
use Illuminate\Support\Facades\DB;

class SetClientDatabase
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            // Usar el nombre del usuario como nombre de la base de datos
            $databaseName = $user->name;

            // Configurar conexión dinámica a partir de la conexión 'mysql' base,
            // sobrescribiendo solo el nombre de la BD. Se usa config() (no env())
            // para que funcione también con la configuración cacheada en producción
            // (php artisan config:cache); con env() los valores volverían a los por
            // defecto y la conexión apuntaría a credenciales incorrectas.
            config([
                'database.connections.dynamic' => array_merge(
                    config('database.connections.mysql'),
                    ['database' => $databaseName]
                ),
            ]);

            // Reconectar con la nueva configuración
            DB::purge('dynamic');
            DB::reconnect('dynamic');

            // Verificar conexión
            try {
                DB::connection('dynamic')->getPdo();
            } catch (\Exception $e) {
                return ServerError::response('v2 conexión BD '.$databaseName, $e, 'No se ha podido conectar con la base de datos del laboratorio');
            }
        }

        return $next($request);
    }
}
