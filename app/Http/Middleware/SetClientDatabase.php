<?php

namespace App\Http\Middleware;

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

            // Configurar conexión dinámica
            config([
                'database.connections.dynamic' => [
                    'driver' => env('DB_CONNECTION', 'mysql'),
                    'host' => env('DB_HOST', '127.0.0.1'),
                    'database' => $databaseName,
                    'username' => env('DB_USERNAME', 'root'),
                    'password' => env('DB_PASSWORD', ''),
                ],
            ]);

            // Reconectar con la nueva configuración
            DB::purge('dynamic');
            DB::reconnect('dynamic');

            // Verificar conexión
            try {
                DB::connection('dynamic')->getPdo();
            } catch (\Exception $e) {
                return response()->json(['error' => 'Could not connect to the database.'], 500);
            }            
        }

        return $next($request);
    }
}
