<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * Gestión documental de Veolab (Documentos.bas): los ficheros se guardan en
 * la BD troceados en DOCBLO, por versión (DOCVER) de cada documento (DOCFAT).
 *
 *  - Trozos de DBS_MAX_BUFFER_BLOB (65534 bytes, la columna es un BLOB); el
 *    orden es el de BLO1COD, que sale del contador ACCCLT 'DOCBLO' de la
 *    delegación reservando de una vez los códigos de todo el fichero.
 *  - Comprimido (FATBZIP / VERBZIP): el contenido es un ZIP estándar (Info-ZIP)
 *    con una única entrada, FATCNOC.FATCTIP. Al leer se extrae esa única
 *    entrada sin fiarse del nombre, que Veolab no actualiza al renombrar.
 *  - Los códigos de documento y de bloque se reservan fuera de la transacción
 *    del alta, como Veolab, para no bloquear el contador mientras se graba el
 *    fichero; si el alta falla quedan huecos en la secuencia, que no importan.
 */
class VeolabDocuments
{
    /** Tamaño de trozo de Veolab (Database.bas, DBS_MAX_BUFFER_BLOB). */
    public const BLOCK_SIZE = 65534;

    /** Tabla de las carpetas generales (sin entidad) y de las plantillas. */
    public const GENERAL = 'ZZZDIR';
    public const TEMPLATES = 'PLAPLA';

    /**
     * Entidades vinculables (DOC_GuardarFicheroEnBD): tabla => [mensaje,
     * columna de delegación de la entidad, [columna de la entidad => parámetro]].
     * La delegación de la entidad es la del documento (DOCFAT.DEL3COD).
     */
    public const ENTITIES = [
        'SINPRO' => ['El proveedor no existe', 'DEL3COD', ['PRO1COD' => 'proveedor_codigo']],
        'SINCLI' => ['El cliente no existe', 'DEL3COD', ['CLI1COD' => 'cliente_codigo']],
        'LABTEC' => ['La técnica no existe', 'DEL3COD', ['TEC1COD' => 'tecnica_codigo']],
        'LABEQU' => ['El equipamiento no existe', 'DEL3COD', ['EQU1COD' => 'equipamiento_codigo']],
        'GRHEMP' => ['El empleado no existe', 'DEL3COD', ['EMP1COD' => 'empleado_codigo']],
        'GRHPAF' => ['El curso no existe', 'DEL3COD', ['PAF1COD' => 'curso_codigo']],
        'LABOPE' => ['La operación no existe', 'DEL3COD', ['OPE1SER' => 'operacion_serie', 'OPE1COD' => 'operacion_codigo']],
        'LABORD' => ['La orden no existe', 'DEL3COD', ['ORD1SER' => 'orden_serie', 'ORD1COD' => 'orden_codigo']],
        'LABINF' => ['El informe no existe', 'DEL3COD', ['INF1SER' => 'informe_serie', 'INF1COD' => 'informe_codigo']],
        'LABLOT' => ['El lote no existe', 'DEL3COD', ['LOT1SER' => 'lote_serie', 'LOT1COD' => 'lote_codigo']],
        'LABPLO' => ['La planificación no existe', 'DEL3COD', ['PLO1COD' => 'planificacion_codigo']],
        'AGEAGE' => ['El evento de agenda no existe', 'USU3DEL', ['USU3COD' => 'agenda_serie', 'AGE1COD' => 'agenda_codigo']],
        'FACCON' => ['El contrato no existe', 'DEL3COD', ['CON1SER' => 'contrato_serie', 'CON1COD' => 'contrato_codigo']],
        'FACPRE' => ['El presupuesto no existe', 'DEL3COD', ['PRE1SER' => 'presupuesto_serie', 'PRE1COD' => 'presupuesto_codigo']],
        'FACFAC' => ['La factura no existe', 'DEL3COD', ['FAC1SER' => 'factura_serie', 'FAC1COD' => 'factura_codigo']],
        'ALMSEL' => ['La serie o lote del producto no existe', 'PRD3DEL', ['PRD3COD' => 'producto_codigo', 'SEL1COD' => 'producto_serie_lote_codigo']],
        'ALMPRD' => ['El producto no existe', 'DEL3COD', ['PRD1COD' => 'producto_codigo']],
        'LABCDC' => ['La carta de control no existe', 'DEL3COD', ['CDC1COD' => 'carta_control_codigo']],
        'ALMPRE' => ['El préstamo no existe', 'DEL3COD', ['PRE1COD' => 'prestamo_codigo']],
    ];

    /** Parámetros de entidad de DOCFAT (todos los de ENTITIES). */
    public static function entityParams(): array
    {
        $params = [];
        foreach (self::ENTITIES as [, , $columns]) {
            foreach ($columns as $param) {
                $params[$param] = true;
            }
        }

        return array_keys($params);
    }

    /**
     * Tabla de la entidad a la que apuntan los datos (null = ninguna). Una
     * entidad cuenta cuando tiene todos sus códigos; de las que encajan se
     * queda la más concreta (la serie o lote antes que su producto).
     */
    public static function entityTable(array $data): ?string
    {
        $found = [];
        foreach (self::ENTITIES as $table => [, , $columns]) {
            $complete = true;
            foreach ($columns as $param) {
                if (str_ends_with($param, '_codigo') && in_array($data[$param] ?? null, [null, '', 0, '0'], true)) {
                    $complete = false;
                }
            }
            if ($complete) {
                $found[$table] = count($columns);
            }
        }

        if (isset($found['ALMSEL'])) {
            unset($found['ALMPRD']);
        }
        if (count($found) > 1) {
            throw new BusinessRuleException('Un documento solo puede vincularse a una entidad');
        }

        return array_key_first($found);
    }

    /** La entidad indicada existe en la delegación del documento. */
    public static function checkEntity(string $table, array $data, string $delegation): void
    {
        [$message, $delegationColumn, $columns] = self::ENTITIES[$table];

        $query = DB::connection('dynamic')->table($table)->where($delegationColumn, $delegation);
        foreach ($columns as $column => $param) {
            $query->where($column, (string) ($data[$param] ?? ''));
        }
        if (! $query->exists()) {
            throw new BusinessRuleException($message);
        }
    }

    /** Carpeta (DOCDIR) o null. */
    public static function folder(string $delegation, int $code): ?object
    {
        return DB::connection('dynamic')->table('DOCDIR')
            ->where('DEL3COD', $delegation)->where('DIR1COD', $code)->first();
    }

    /** Carpeta raíz de una tabla (DIRCTAB = tabla y DIR2COD = 0). */
    public static function rootFolder(string $table): ?object
    {
        return DB::connection('dynamic')->table('DOCDIR')
            ->where('DIRCTAB', $table)->where('DIR2COD', 0)
            ->orderBy('DEL3COD')->orderBy('DIR1COD')->first();
    }

    /**
     * Carpeta de destino de un documento de $table (null = sin entidad):
     * la indicada, que debe ser de la gestión documental de esa tabla, o su
     * carpeta raíz. Las plantillas de exportación no se gestionan aquí.
     */
    public static function resolveFolder(?string $table, ?string $folderDelegation, ?int $folderCode): object
    {
        $expected = $table ?? self::GENERAL;

        if ($folderCode) {
            $folder = self::folder((string) $folderDelegation, $folderCode);
            if (! $folder) {
                throw new BusinessRuleException('La carpeta no existe');
            }
        } else {
            $folder = self::rootFolder($expected);
            if (! $folder) {
                throw new BusinessRuleException("No existe la carpeta raíz de {$expected}");
            }
        }

        if ($folder->DIRCTAB === self::TEMPLATES) {
            throw new BusinessRuleException('Las plantillas de exportación se gestionan desde Veolab');
        }
        if ($folder->DIRCTAB !== $expected) {
            throw new BusinessRuleException($table
                ? "La carpeta no pertenece a la gestión documental de {$table}"
                : "Los documentos de las carpetas de {$folder->DIRCTAB} deben vincularse a su entidad");
        }

        return $folder;
    }

    /** Separa nombre y extensión por el último punto (FIC_DescomponerNombreExtension). */
    public static function splitName(string $fileName): array
    {
        $pos = strrpos($fileName, '.');
        if ($pos === false || $pos === 0) {
            return [$fileName, ''];
        }

        return [substr($fileName, 0, $pos), substr($fileName, $pos + 1)];
    }

    /** Nombre completo nombre.extensión. */
    public static function fullName(string $name, ?string $extension): string
    {
        return ($extension ?? '') === '' ? $name : "{$name}.{$extension}";
    }

    /** ¿Comprimir por defecto los documentos nuevos? (ACCPAR.PARBZIP) */
    public static function compressByDefault(): bool
    {
        return DB::connection('dynamic')->table('ACCPAR')->value('PARBZIP') === 'T';
    }

    /** ¿Versión dual activada? (ACCPAR.PARBDUA) */
    public static function dualVersion(): bool
    {
        return DB::connection('dynamic')->table('ACCPAR')->value('PARBDUA') === 'T';
    }

    // ------------------------------------------------------------------
    // Códigos (fuera de la transacción del alta)
    // ------------------------------------------------------------------

    /**
     * Código libre de documento (DOC_ReservarCodigoDocumental): si el contador
     * está retrasado se avanza y, tras 25 vueltas, se recoloca sobre el máximo.
     */
    public static function reserveDocumentCode(string $delegation): int
    {
        $db = DB::connection('dynamic');

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $code = $db->transaction(fn () => VeolabCodes::next('DOCFAT', '', $delegation));

            if (! $db->table('DOCFAT')->where('DEL3COD', $delegation)->where('FAT1COD', $code)->exists()) {
                return $code;
            }

            if ($attempt === 25) {
                $max = (int) $db->table('DOCFAT')->where('DEL3COD', $delegation)->max('FAT1COD');
                $db->transaction(fn () => VeolabCodes::realign('DOCFAT', '', $delegation, $max));
            }
        }

        throw new BusinessRuleException('No se ha podido generar un código de documento libre');
    }

    /** Primer código de un rango libre de $count bloques en DOCBLO. */
    public static function reserveBlockCodes(string $delegation, int $count): int
    {
        $db = DB::connection('dynamic');
        $count = max($count, 1);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $last = $db->transaction(fn () => VeolabCodes::reserve('DOCBLO', '', $delegation, $count));
            $first = $last - $count + 1;

            $taken = $db->table('DOCBLO')->where('DEL3COD', $delegation)
                ->whereBetween('BLO1COD', [$first, $last])->exists();
            if (! $taken) {
                return $first;
            }

            // Contador retrasado: se recoloca sobre el máximo real.
            $max = (int) $db->table('DOCBLO')->where('DEL3COD', $delegation)->max('BLO1COD');
            $db->transaction(fn () => VeolabCodes::realign('DOCBLO', '', $delegation, $max));
        }

        throw new BusinessRuleException('No se han podido reservar los bloques del documento');
    }

    /** Número de bloques de un fichero de $size bytes. */
    public static function blockCount(int $size): int
    {
        return (int) ceil($size / self::BLOCK_SIZE);
    }

    // ------------------------------------------------------------------
    // Contenido
    // ------------------------------------------------------------------

    /**
     * FATCNOC de un documento nuevo: el nombre en ASCII, sin caracteres no
     * válidos en Windows. Veolab extrae el ZIP y busca FATCNOC.FATCTIP, así
     * que un nombre sin acentos no depende de cómo el unzip32.dll de Veolab
     * interprete la codificación de la entrada.
     */
    public static function zipBaseName(string $name): string
    {
        $ascii = trim(preg_replace('/[^\x20-\x7E]|[\\\\\/:*?"<>|]/', '_', Str::ascii($name)));

        return $ascii === '' ? 'documento' : $ascii;
    }

    /**
     * Fichero a guardar: el subido o, si se comprime, un ZIP temporal con una
     * única entrada $entryName. Devuelve [ruta, tamaño, temporal a borrar].
     * La entrada va en la página de códigos OEM (CP850) y sin la marca UTF-8,
     * como la escribe Info-ZIP en Windows: el unzip32.dll de Veolab es
     * anterior a los nombres UTF-8.
     */
    public static function prepare(string $path, bool $compress, string $entryName): array
    {
        if (! $compress) {
            return [$path, (int) filesize($path), null];
        }

        $oemName = @iconv('UTF-8', 'CP850//TRANSLIT', $entryName);
        if ($oemName === false || $oemName === '') {
            $oemName = self::zipBaseName($entryName);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'veozip');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true
            || ! $zip->addFile($path, $oemName, 0, 0, \ZipArchive::FL_OVERWRITE | \ZipArchive::FL_ENC_CP437)
            || ! $zip->close()) {
            @unlink($zipPath);
            throw new \RuntimeException('No se ha podido comprimir el documento');
        }
        clearstatcache(true, $zipPath);

        return [$zipPath, (int) filesize($zipPath), $zipPath];
    }

    /** Graba el fichero en DOCBLO a partir del código $firstBlock. */
    public static function writeBlocks(string $path, string $delegation, int $document, int $version, int $firstBlock): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No se ha podido leer el fichero subido');
        }

        try {
            $code = $firstBlock;
            while (! feof($handle)) {
                $chunk = '';
                while (strlen($chunk) < self::BLOCK_SIZE && ! feof($handle)) {
                    $chunk .= fread($handle, self::BLOCK_SIZE - strlen($chunk));
                }
                if ($chunk === '') {
                    break;
                }

                // En hexadecimal: el binario no pasa por el juego de caracteres de la conexión.
                DB::connection('dynamic')->table('DOCBLO')->insert([
                    'DEL3COD' => $delegation,
                    'BLO1COD' => $code++,
                    'FAT3COD' => $document,
                    'VER3COD' => $version,
                    'BLONTAM' => strlen($chunk),
                    'BLOLCON' => DB::raw("X'".bin2hex($chunk)."'"),
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    /** Borra el contenido de un documento (o de una de sus versiones). */
    public static function deleteBlocks(string $delegation, int $document, ?int $version = null): void
    {
        $query = DB::connection('dynamic')->table('DOCBLO')
            ->where('DEL3COD', $delegation)->where('FAT3COD', $document);
        if ($version !== null) {
            $query->where('VER3COD', $version);
        }
        $query->delete();
    }

    /**
     * Descarga de una versión: los bloques se leen de uno en uno; si está
     * comprimido se reconstruye antes el ZIP en un temporal y se extrae su
     * única entrada (si no es un ZIP válido, se entrega tal cual).
     */
    public static function download(object $document, int $version, bool $compressed, bool $inline = false): StreamedResponse
    {
        $delegation = (string) $document->DEL3COD;
        $code = (int) $document->FAT1COD;

        $blocks = DB::connection('dynamic')->table('DOCBLO')
            ->where('DEL3COD', $delegation)->where('FAT3COD', $code)->where('VER3COD', $version)
            ->orderBy('BLO1COD')->pluck('BLO1COD')->all();

        $emit = function ($output) use ($delegation, $blocks) {
            foreach ($blocks as $block) {
                $row = DB::connection('dynamic')->table('DOCBLO')
                    ->where('DEL3COD', $delegation)->where('BLO1COD', $block)->first(['BLONTAM', 'BLOLCON']);
                fwrite($output, substr((string) $row->BLOLCON, 0, (int) $row->BLONTAM));
            }
        };

        $zipPath = null;
        if ($compressed) {
            $zipPath = tempnam(sys_get_temp_dir(), 'veozip');
            $out = fopen($zipPath, 'wb');
            $emit($out);
            fclose($out);
        }

        $fileName = str_replace(['/', '\\'], '_', self::fullName((string) $document->FATCNOM, $document->FATCTIP));
        $headers = [
            'Content-Type'        => self::mimeType((string) $document->FATCTIP),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $fileName,
                self::asciiName($fileName)
            ),
            'X-Content-Type-Options' => 'nosniff',
        ];

        return new StreamedResponse(function () use ($emit, $zipPath, $document) {
            $output = fopen('php://output', 'wb');
            try {
                if ($zipPath === null) {
                    $emit($output);
                } else {
                    self::extract($zipPath, $document, $output);
                }
            } finally {
                fclose($output);
                if ($zipPath !== null) {
                    @unlink($zipPath);
                }
            }
        }, 200, $headers);
    }

    /** Extrae la única entrada del ZIP (o la llamada FATCNOC.FATCTIP si hay varias). */
    private static function extract(string $zipPath, object $document, $output): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true || $zip->numFiles < 1) {
            // No es un ZIP (FATBZIP cambiado sin recomprimir): se entrega tal cual.
            $input = fopen($zipPath, 'rb');
            stream_copy_to_stream($input, $output);
            fclose($input);

            return;
        }

        $index = 0;
        if ($zip->numFiles > 1) {
            $expected = self::fullName((string) ($document->FATCNOC ?: $document->FATCNOM), $document->FATCTIP);
            $found = $zip->locateName($expected, \ZipArchive::FL_NOCASE | \ZipArchive::FL_NODIR);
            $index = $found === false ? 0 : $found;
        }

        $input = $zip->getStream($zip->getNameIndex($index));
        if ($input !== false) {
            stream_copy_to_stream($input, $output);
            fclose($input);
        }
        $zip->close();
    }

    private static function mimeType(string $extension): string
    {
        $types = $extension === '' ? [] : MimeTypes::getDefault()->getMimeTypes(strtolower($extension));

        return $types[0] ?? 'application/octet-stream';
    }

    /** Nombre alternativo ASCII para Content-Disposition (sin % ni barras). */
    public static function asciiName(string $fileName): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '_', Str::ascii($fileName));

        return $ascii === '' ? 'documento' : $ascii;
    }
}
