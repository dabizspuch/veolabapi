<?php

namespace App\Http\Controllers;

use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Firma digitalizada de un usuario (ACCFIR), la imagen que Veolab pone en
 * los informes al firmar. Clave ?delegacion=&codigo= del usuario.
 *
 * Veolab guarda el fichero de imagen en trozos de 65534 bytes (FIRLIMA) con
 * FIR1COD del contador de ACCFIR, y para usarla los concatena por FIR1COD y
 * la carga con LoadPicture: solo admite BMP, JPG y GIF (PNG no).
 *
 * GET descarga la imagen, POST (multipart, campo "fichero") la sustituye y
 * DELETE la quita. Se audita como FichaUsuario: campo ACCUSUUSUCBFI.
 */
class UsuarioFirmaController extends Controller
{
    private const CHUNK = 65534;            // DBS_MAX_BUFFER_BLOB
    private const MAX_KB = 2048;

    private const TYPES = [
        'bmp' => 'image/bmp',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
    ];

    public function show(Request $request)
    {
        $key = $this->userKey($request);
        if (! is_array($key)) {
            return $key;
        }

        $chunks = DB::connection('dynamic')->table('ACCFIR')
            ->where('DEL3COD', $key[0])->where('USU3COD', $key[1])
            ->orderBy('FIR1COD')->get(['FIRNTAM', 'FIRLIMA']);
        if ($chunks->isEmpty()) {
            return response()->json(['message' => 'El usuario no tiene firma'], 404);
        }

        $content = '';
        foreach ($chunks as $chunk) {
            $content .= substr((string) $chunk->FIRLIMA, 0, (int) $chunk->FIRNTAM ?: null);
        }

        $type = $this->type($content);
        $mime = self::TYPES[$type] ?? 'application/octet-stream';
        $name = 'firma_'.$key[1].($type ? '.'.$type : '');

        return response($content, 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => strlen($content),
            'Content-Disposition' => ($request->query('inline') === 'T' ? 'inline' : 'attachment').'; filename="'.addslashes($name).'"',
        ]);
    }

    public function store(Request $request)
    {
        $key = $this->userKey($request);
        if (! is_array($key)) {
            return $key;
        }

        $validator = Validator::make($request->all(), ['fichero' => 'required|file|max:'.self::MAX_KB]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $content = (string) file_get_contents($request->file('fichero')->getRealPath());
        if ($this->type($content) === null) {
            return response()->json(['message' => 'La firma debe ser una imagen BMP, JPG o GIF (Veolab no admite otros formatos)'], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $user = $db->table('ACCUSU')->where('DEL3COD', $key[0])->where('USU1COD', $key[1])->lockForUpdate()->exists();
            if (! $user) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            $db->table('ACCFIR')->where('DEL3COD', $key[0])->where('USU3COD', $key[1])->delete();

            // Claves reservadas de una vez en el contador de ACCFIR, en orden.
            $pieces = str_split($content, self::CHUNK);
            $last = VeolabCodes::reserve('ACCFIR', '', '', count($pieces));
            foreach ($pieces as $i => $piece) {
                $db->table('ACCFIR')->insert([
                    'DEL3COD' => $key[0],
                    'USU3COD' => $key[1],
                    'FIR1COD' => $last - count($pieces) + 1 + $i,
                    'FIRNTAM' => strlen($piece),
                    'FIRLIMA' => $piece,
                ]);
            }

            $this->audit($key);
            $db->commit();

            return response()->json(['message' => 'Firma guardada correctamente', 'data' => ['tamano' => strlen($content)]]);
        } catch (\Throwable $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            Log::error('v2 store ACCFIR: '.$e->getMessage());

            return response()->json(['message' => 'Error al guardar la firma'], 500);
        }
    }

    public function destroy(Request $request)
    {
        $key = $this->userKey($request);
        if (! is_array($key)) {
            return $key;
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $deleted = $db->table('ACCFIR')->where('DEL3COD', $key[0])->where('USU3COD', $key[1])->delete();
            if (! $deleted) {
                $db->rollBack();

                return response()->json(['message' => 'El usuario no tiene firma'], 404);
            }

            $this->audit($key);
            $db->commit();

            return response()->json(['message' => 'Firma eliminada correctamente']);
        } catch (\Throwable $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            Log::error('v2 destroy ACCFIR: '.$e->getMessage());

            return response()->json(['message' => 'Error al eliminar la firma'], 500);
        }
    }

    /** [delegación, código] del usuario o respuesta 400 si falta la clave. */
    private function userKey(Request $request)
    {
        if ((string) $request->query('codigo', '') === '') {
            return response()->json(['message' => "Falta la clave 'codigo'"], 400);
        }

        return [(string) ($request->query('delegacion') ?? ''), (string) $request->query('codigo')];
    }

    /** bmp, jpg o gif según la cabecera del fichero; null si es otro formato. */
    private function type(string $content): ?string
    {
        return match (true) {
            str_starts_with($content, 'BM')           => 'bmp',
            str_starts_with($content, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($content, 'GIF8')         => 'gif',
            default                                   => null,
        };
    }

    /** Suceso de fila del usuario y de campo ACCUSUUSUCBFI (FichaUsuario.Grabar). */
    private function audit(array $key): void
    {
        $row = VeolabCodes::format('ACCUSU', $key[1], $key[0]);
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'ACCUSU', $row);
        if (VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'ACCUSU', $row, 'ACCUSUUSUCBFI');
        }
    }
}
