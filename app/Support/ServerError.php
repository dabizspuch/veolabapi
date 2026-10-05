<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Errores 500 de la API: la respuesta lleva una referencia que también queda
 * en el registro junto con la excepción completa, para localizar el error a
 * partir de lo que comunica el cliente. Si el registro de Laravel no se puede
 * escribir (p. ej. permisos de storage/logs), se usa el error_log de PHP: un
 * fallo al registrar nunca debe ocultar el error ni cambiar la respuesta.
 */
class ServerError
{
    public static function reference(): string
    {
        return bin2hex(random_bytes(4));
    }

    /** Registra la excepción con su referencia (nueva si no se indica) y la devuelve. */
    public static function log(string $context, \Throwable $e, ?string $reference = null): string
    {
        $reference ??= self::reference();
        $message = "{$context} [ref {$reference}]: ".$e->getMessage();

        try {
            Log::error($message, ['exception' => $e]);
        } catch (\Throwable $logError) {
            error_log($message.' en '.$e->getFile().':'.$e->getLine()
                .' (registro de Laravel no disponible: '.$logError->getMessage().')');
        }

        return $reference;
    }

    /** Respuesta 500 con el mensaje para el cliente y la referencia del registro. */
    public static function response(string $context, \Throwable $e, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'referencia' => self::log($context, $e)], 500);
    }
}
