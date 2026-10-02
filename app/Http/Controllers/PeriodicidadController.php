<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabPeriodicity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /periodicidad/fechas: las fechas que genera una periodicidad, sin
 * grabar nada (vista previa para la agenda y las planificaciones).
 * Cuerpo: {inicio, repeticion: {...}, delegacion (festivos), horizonte}.
 * Sin horizonte se usa el de las periodicidades (VeolabPeriodicity::horizon).
 */
class PeriodicidadController extends Controller
{
    public function dates(Request $request)
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'inicio'     => 'required|date',
            'delegacion' => 'nullable|string|max:10',
            'horizonte'  => 'nullable|date',
            'repeticion' => 'required|array',
        ] + VeolabPeriodicity::rules('repeticion'));
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        try {
            $repeat = VeolabPeriodicity::normalize($body['repeticion']);
            $horizon = ! empty($body['horizonte'])
                ? new \DateTimeImmutable(substr((string) $body['horizonte'], 0, 10))
                : VeolabPeriodicity::horizon();
            $dates = (new VeolabPeriodicity((string) ($body['delegacion'] ?? '')))->dates(
                new \DateTimeImmutable($body['inicio']), $repeat['frecuencia'], $repeat['opcion'], $repeat['repetir'],
                $repeat['ordinal'], $repeat['dias'], $repeat['fecha_fin'], $repeat['repeticiones'], $repeat['trasladar'], $horizon);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $out = array_map(fn ($d) => $d->format('Y-m-d H:i:s'), $dates);

        return response()->json(['data' => $out, 'meta' => ['total' => count($out), 'horizonte' => $horizon->format('Y-m-d')]]);
    }
}
