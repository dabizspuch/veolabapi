<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Imagen de acreditación de los informes (LABIMG), la de Configurar
 * informes de Veolab. Solo lectura. Como la firma de usuario, Veolab la
 * guarda en trozos (IMGNTAM bytes de IMGLIMA) que se concatenan por IMG1COD.
 */
class ConfiguracionImagenController extends Controller
{
    private const TYPES = [
        'BM'           => ['bmp', 'image/bmp'],
        "\xFF\xD8\xFF" => ['jpg', 'image/jpeg'],
        'GIF8'         => ['gif', 'image/gif'],
    ];

    public function show(Request $request)
    {
        $chunks = DB::connection('dynamic')->table('LABIMG')->where('IMG1COD', '>', 0)
            ->orderBy('IMG1COD')->get(['IMGNTAM', 'IMGLIMA']);
        if ($chunks->isEmpty()) {
            return response()->json(['message' => 'No hay imagen de acreditación'], 404);
        }

        $content = '';
        foreach ($chunks as $chunk) {
            $content .= substr((string) $chunk->IMGLIMA, 0, (int) $chunk->IMGNTAM ?: null);
        }

        [$extension, $mime] = ['', 'application/octet-stream'];
        foreach (self::TYPES as $magic => $type) {
            if (str_starts_with($content, $magic)) {
                [$extension, $mime] = $type;
                break;
            }
        }
        $name = 'acreditacion'.($extension ? '.'.$extension : '');

        return response($content, 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => strlen($content),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $request->query('inline') === 'T' ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
