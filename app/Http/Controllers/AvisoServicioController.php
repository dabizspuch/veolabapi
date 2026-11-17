<?php

namespace App\Http\Controllers;

use App\Support\VeolabServiceNotices;
use Illuminate\Support\Facades\DB;

/**
 * Avisos de servicio de SPUCH vigentes para el laboratorio: cortes programados del servidor, incidencias urgentes y
 * comunicaciones (los mismos que muestra Veolab). Solo lectura; qué avisos ha visto cada usuario lo decide la app.
 *
 *  - GET /avisos-servicio: data = avisos vigentes, urgentes primero. Fechas en hora peninsular española
 *    (meta.zona_horaria). Si spuch.com no responde, data vacío y meta.disponible = false: nunca es un error.
 */
class AvisoServicioController extends Controller
{
    public function index()
    {
        $db = DB::connection('dynamic');
        try {
            $license = trim((string) $db->table('ACCPAR')->where('PAR1COD', 1)->value('PARCLBD'));
        } catch (\Throwable $e) {
            $license = '';
        }

        $notices = $license !== '' ? VeolabServiceNotices::fetch($license, $db->getDatabaseName()) : null;

        return response()->json([
            'data' => $notices ?? [],
            'meta' => [
                'total'         => count($notices ?? []),
                'disponible'    => $notices !== null,
                'zona_horaria'  => 'Europe/Madrid',
            ],
        ]);
    }
}
