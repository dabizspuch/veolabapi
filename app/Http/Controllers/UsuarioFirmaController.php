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
 *
 * LoadPicture descomprime la imagen entera en memoria y FichaUsuario.Grabar la
 * vuelve a guardar con SavePicture, que la convierte en BMP sin comprimir: una
 * foto grande hace fallar la ficha del usuario en Veolab aunque el fichero
 * pese poco. Por eso se limitan también las dimensiones y se rechazan los JPG
 * progresivos y CMYK, que LoadPicture no abre ("Imagen no válida").
 */
class UsuarioFirmaController extends Controller
{
    private const CHUNK = 65534;            // DBS_MAX_BUFFER_BLOB
    private const MAX_KB = 2048;
    private const MAX_PX = 2000;            // por lado; 2000x2000 en BMP de 24 bits = 12 MB

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
        $type = $this->type($content);
        if ($type === null) {
            return response()->json(['message' => 'La firma debe ser una imagen BMP, JPG o GIF (Veolab no admite otros formatos)'], 422);
        }
        $error = $this->loadable($content, $type);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
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

    /** Motivo por el que LoadPicture no podría cargar la imagen; null si puede. */
    private function loadable(string $content, string $type): ?string
    {
        $info = @getimagesizefromstring($content);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return 'La imagen de la firma está dañada o no se puede leer';
        }
        if ($info[0] > self::MAX_PX || $info[1] > self::MAX_PX) {
            return 'La imagen de la firma mide '.$info[0].'x'.$info[1].' píxeles; el máximo es '
                .self::MAX_PX.' por lado (Veolab no puede cargar imágenes tan grandes). Redúzcala y vuelva a subirla';
        }
        if ($type === 'jpg') {
            if (($info['channels'] ?? 3) === 4) {
                return 'La imagen JPG de la firma está en CMYK; Veolab solo admite JPG en RGB o escala de grises';
            }
            $sof = $this->jpegFrame($content);
            if ($sof !== null && $sof !== 0xC0 && $sof !== 0xC1) {
                return 'La imagen JPG de la firma es progresiva; Veolab solo admite JPG estándar (baseline). Guárdela de nuevo sin la opción progresiva';
            }
        }

        return null;
    }

    /** Marcador SOFn (0xC0-0xCF) del JPG: 0xC0/0xC1 estándar, 0xC2 progresivo...; null si no aparece. */
    private function jpegFrame(string $content): ?int
    {
        $pos = 2;
        $len = strlen($content);
        while ($pos + 4 <= $len) {
            if (ord($content[$pos]) !== 0xFF) {
                return null;
            }
            $marker = ord($content[$pos + 1]);
            if ($marker === 0xFF) {             // relleno entre marcadores
                $pos++;

                continue;
            }
            if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                return $marker;
            }
            if ($marker === 0xD9 || $marker === 0xDA) { // fin de imagen o datos sin SOF antes
                return null;
            }
            $pos += 2 + ((ord($content[$pos + 2]) << 8) | ord($content[$pos + 3]));
        }

        return null;
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
