<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Control de documentos, fase 1 (desde Veolab 3; ISO/IEC 17025:2017 8.3).
 * Capa sobre la gestión documental: no hay almacén aparte. Sigue el modelo
 * común con el escritorio («Control de documentos - modelo de datos.md»).
 *
 *  - Controlado: DOCFAT.FATCCTL = T, o vacío y su carpeta o alguna superior
 *    con DOCDIR.DIRBCON = T; FATCCTL = F lo excluye siempre. No hay CTE
 *    recursivas portables: el árbol de carpetas se sube en PHP (folders()).
 *  - La última versión es la principal (VER2COD) y VER2APR la edición
 *    aprobada vigente; si son distintas, el documento está pendiente de
 *    aprobar. Estado FATCEST: B borrador, V vigente, O obsoleto.
 *  - Aprobar, revisar sin cambios y obsoleto: privilegio especial 1 de
 *    DOC_CTL si se indica el usuario; sin usuario la API actúa como
 *    privilegiada, como en calidad.
 *  - Va con el módulo Calidad (DCT_Activo = LIC_ModuloActivo("CAL")): sin
 *    él (active() falso) ninguna carpeta ni ningún documento está
 *    controlado, se comportan como documentos normales con versiones, los
 *    campos de control se leen pero no se escriben y las rutas del control
 *    responden 422.
 *
 * Fase 2 (14-nov-2026, «Analisis distribucion de documentos.md»), solo con
 * el script que crea DOCDIS y DOCCOP (distributionAvailable()):
 *  - Destinatarios (DOCDIS) del documento o, si no tiene, de su carpeta o
 *    de la primera superior que tenga; se resuelven en empleados en activo
 *    sin repetir (resolveRecipients()).
 *  - Lecturas pendientes = destinatarios − lecturas (DOCLEC) de la edición
 *    vigente; con VERBLEC = F vale la de una edición anterior posterior a la
 *    última que requirió lectura. Vencida: VERDAPR + DIRNDLE (15) < hoy.
 *  - Revisión antes de aprobar (DIRBREV): la edición en curso pasa por R
 *    (VERCEST) antes de aprobarse; revisa el especial 2 de DOC_CTL o el
 *    revisor del documento (DOCFAT.EMR2*), nunca quien lo elabora.
 *  - Copias controladas (DOCCOP): por retirar si son de una edición
 *    anterior a la vigente y no tienen fecha de retirada.
 */
class VeolabDocumentControl
{
    /**
     * Edición siguiente a la indicada, como DCT_ProponerEdicion del escritorio: incrementa el último número
     * conservando el texto y los ceros (Rev 00 → Rev 01, Ed. 9 → Ed. 10, 2024 → 2025); sin número, la misma;
     * vacía, «Rev. 0». Como mucho 50 caracteres.
     */
    public static function proposeEdition(?string $previous): string
    {
        $previous = trim((string) $previous);
        if ($previous === '') {
            return 'Rev. 0';
        }
        if (! preg_match('/^(.*?)(\d+)(\D*)$/u', $previous, $m)) {
            return mb_substr($previous, 0, 50);
        }
        $next = (string) ((int) $m[2] + 1);
        $next = str_pad($next, strlen($m[2]), '0', STR_PAD_LEFT);

        return mb_substr($m[1].$next.$m[3], 0, 50);
    }

    /** Funcionalidad de la lista de documentos controlados: especial 1 = aprobar documentos. */
    public const FUNCTIONALITY = 'DOC_CTL';

    /** Especial 2 de DOC_CTL: revisar ediciones antes de su aprobación (ESP_DOC_CTL02). */
    public const SPECIAL2 = VeolabPermissions::ESPECIAL1 << 1;

    /** Explorador de documentos: especial 3 = ver todas las versiones (según el modelo de datos). */
    public const EXPLORER = 'DOC_EXP';

    /** Estados efectivos (P = pendiente de aprobar, calculado). */
    public const STATE_NAMES = [
        'B' => 'Borrador',
        'V' => 'Vigente',
        'P' => 'Pendiente de aprobar',
        'O' => 'Obsoleto',
    ];

    public const TYPE_NAMES = [
        'I' => 'Interno',
        'E' => 'Externo',
    ];

    /** Días antes de la próxima revisión en que se marca como próxima (ámbar). */
    public const UPCOMING_DAYS = 30;

    /** Nombre de la copia dual que pasa a versión visible. */
    public const PREVIOUS_VERSION_NAME = 'Versión anterior';

    private static array $folders = [];

    private static array $readings = [];

    private static array $active = [];

    /** Fase 2 por base de datos: ¿tiene DOCDIS, DOCCOP y las columnas nuevas? */
    private static array $distribution = [];

    /** Líneas de DOCDIS por base de datos (ver recipientLines()). */
    private static array $recipientLines = [];

    /** Personal para resolver destinatarios, por base de datos (ver staff()). */
    private static array $staff = [];

    /** Plazo de lectura por defecto (DIRNDLE vacío), en días. */
    public const DEFAULT_READING_DAYS = 15;

    /** Tipos de destinatario (DOCDIS.DISCTIP; ETI01540-01544). */
    public const RECIPIENT_TYPES = [
        'T' => 'Todo el personal',
        'E' => 'Empleado',
        'C' => 'Cargo',
        'D' => 'Departamento',
        'A' => 'Autorizados del parámetro',
    ];

    /** Estados de una edición (DOCVER.VERCEST; ETI01545-01547). */
    public const EDITION_STATES = [
        'B' => 'Borrador',
        'R' => 'Revisada',
        'A' => 'Aprobada',
    ];

    /** Mensaje sin el script de la fase 2 (DOCDIS, DOCCOP y sus columnas). */
    public const DISTRIBUTION_MESSAGE = 'La distribución, la revisión antes de aprobar y las copias controladas requieren el script de conversión que crea DOCDIS y DOCCOP';

    /** Mensaje de las rutas y campos del control sin el módulo Calidad. */
    public const INACTIVE_MESSAGE = 'El control de documentos requiere el módulo Calidad';

    /** Olvida el árbol de carpetas leído (tras cambiar una carpeta). */
    public static function forget(): void
    {
        self::$folders = [];
        self::$recipientLines = [];
    }

    /** ¿Tiene la base de datos el control de documentos? (Veolab 3 con la tabla de lecturas DOCLEC) */
    public static function available(): bool
    {
        if (! VeolabVersion::isV3()) {
            return false;
        }
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$readings)) {
            try {
                self::$readings[$database] = Schema::connection('dynamic')->hasTable('DOCLEC');
            } catch (\Throwable) {
                self::$readings[$database] = false;
            }
        }

        return self::$readings[$database];
    }

    /**
     * ¿Está activo el control de documentos? Como DCT_Activo del escritorio:
     * la base de datos lo tiene (available()) y el módulo Calidad (CAL) está
     * activo (ACCMOD.MODBACT) y licenciado. Se lee una vez por base de datos
     * y petición.
     */
    public static function active(): bool
    {
        if (! self::available()) {
            return false;
        }
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$active)) {
            try {
                self::$active[$database] = VeolabLicense::moduleActive('dynamic', $database, 'CAL');
            } catch (\Throwable) {
                self::$active[$database] = false;
            }
        }

        return self::$active[$database];
    }

    /** BusinessRuleException (422) si el control de documentos no está activo. */
    public static function assertActive(): void
    {
        if (! self::active()) {
            throw new BusinessRuleException(self::INACTIVE_MESSAGE);
        }
    }

    /** Borra las lecturas de un documento (o de algunas de sus versiones). */
    public static function deleteReadings(string $del, int $cod, ?array $versions = null): void
    {
        if (! self::available()) {
            return;
        }
        $query = DB::connection('dynamic')->table('DOCLEC')->where('DEL3COD', $del)->where('FAT3COD', $cod);
        if ($versions !== null) {
            if (! $versions) {
                return;
            }
            $query->whereIn('VER3COD', $versions);
        }
        $query->delete();
    }

    /**
     * Con el borrado definitivo de un documento: sus lecturas y, con la
     * fase 2, sus destinatarios (DOCDIS) y sus copias controladas (DOCCOP).
     * Como DCT_BorrarDatosControl del escritorio.
     */
    public static function deleteDocumentData(string $del, int $cod): void
    {
        self::deleteReadings($del, $cod);
        if (! self::distributionAvailable()) {
            return;
        }
        $db = DB::connection('dynamic');
        $db->table('DOCDIS')->where('DEL3COD', $del)->where('FAT2COD', $cod)->delete();
        $db->table('DOCCOP')->where('DEL3COD', $del)->where('FAT3COD', $cod)->delete();
        self::forgetRecipients();
    }

    /** Al borrar una carpeta: sus destinatarios (DOCDIS). Como DCT_BorrarDatosCarpetas del escritorio. */
    public static function deleteFolderData(string $del, int $cod): void
    {
        if (! self::distributionAvailable()) {
            return;
        }
        DB::connection('dynamic')->table('DOCDIS')->where('DEL3COD', $del)->where('DIR2COD', $cod)->delete();
        self::forgetRecipients();
    }

    private static function key($del, $cod): string
    {
        return (string) $del."\x1B".(int) $cod;
    }

    /** Clave "del\x1Bcod" de un documento o un empleado, la de readingStatus() y employeeNames(). */
    public static function documentKey($del, $cod): string
    {
        return self::key($del, $cod);
    }

    // ------------------------------------------------------------------
    // Carpetas
    // ------------------------------------------------------------------

    /**
     * Todas las carpetas con su control efectivo, subiendo por DIR2DEL/DIR2COD:
     * [clave "del\x1Bcod" => ['delegacion', 'codigo', 'padre' (clave o null),
     * 'controlada' (ella o alguna superior), 'tipo' (DIRCTIC de la más
     * cercana que lo tenga), 'periodicidad' (DIRNPER, ídem) y, de la fase 2,
     * 'revision' (DIRBREV = T en ella o en alguna superior) y 'dias_lectura'
     * (DIRNDLE de la más cercana que lo tenga, o null)]].
     */
    public static function folders(): array
    {
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (isset(self::$folders[$database])) {
            return self::$folders[$database];
        }

        $rows = [];
        foreach (DB::connection('dynamic')->table('DOCDIR')->get() as $row) {
            $rows[self::key($row->DEL3COD, $row->DIR1COD)] = $row;
        }

        $out = [];
        foreach ($rows as $key => $row) {
            $controlled = false;
            $type = null;
            $period = null;
            $review = false;
            $days = null;
            $seen = [];
            $current = $row;
            while ($current !== null) {
                $currentKey = self::key($current->DEL3COD, $current->DIR1COD);
                if (isset($seen[$currentKey])) {
                    break;   // ciclo (dato incoherente)
                }
                $seen[$currentKey] = true;

                if (($current->DIRBCON ?? null) === 'T') {
                    $controlled = true;
                }
                if ($type === null && in_array($current->DIRCTIC ?? null, ['I', 'E'], true)) {
                    $type = (string) $current->DIRCTIC;
                }
                if ($period === null && (int) ($current->DIRNPER ?? 0) > 0) {
                    $period = (int) $current->DIRNPER;
                }
                // Fase 2 (columnas del script de distribución; sin ellas, null)
                if (($current->DIRBREV ?? null) === 'T') {
                    $review = true;
                }
                if ($days === null && (int) ($current->DIRNDLE ?? 0) > 0) {
                    $days = (int) $current->DIRNDLE;
                }
                if ((int) $current->DIR2COD === 0) {
                    break;
                }
                $current = $rows[self::key($current->DIR2DEL, $current->DIR2COD)] ?? null;
            }

            $out[$key] = [
                'delegacion'   => (string) $row->DEL3COD,
                'codigo'       => (int) $row->DIR1COD,
                'nombre'       => (string) ($row->DIRCNOM ?? ''),
                'padre'        => (int) $row->DIR2COD === 0 ? null : self::key($row->DIR2DEL, $row->DIR2COD),
                'controlada'   => $controlled,
                'tipo'         => $type,
                'periodicidad' => $period,
                'revision'     => $review,
                'dias_lectura' => $days,
            ];
        }

        return self::$folders[$database] = $out;
    }

    /** Datos de control efectivos de una carpeta, o null si no existe. */
    public static function folder($del, $cod): ?array
    {
        return self::folders()[self::key($del, $cod)] ?? null;
    }

    /** Claves de la carpeta y de todas sus subcarpetas: [[delegación, código], ...]. */
    public static function subtree($del, $cod): array
    {
        $folders = self::folders();
        $root = self::key($del, $cod);
        if (! isset($folders[$root])) {
            return [];
        }

        $children = [];
        foreach ($folders as $key => $folder) {
            if ($folder['padre'] !== null) {
                $children[$folder['padre']][] = $key;
            }
        }

        $out = [];
        $pending = [$root];
        $seen = [];
        while ($pending) {
            $key = array_pop($pending);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [$folders[$key]['delegacion'], $folders[$key]['codigo']];
            foreach ($children[$key] ?? [] as $child) {
                $pending[] = $child;
            }
        }

        return $out;
    }

    /** Agrupa una lista de carpetas por delegación: [delegación => [códigos]]. */
    private static function byDelegation(array $folders): array
    {
        $groups = [];
        foreach ($folders as [$del, $cod]) {
            $groups[(string) $del][] = (int) $cod;
        }

        return $groups;
    }

    /** Carpetas controladas (marcadas y sus descendientes) agrupadas por delegación; sin Calidad, ninguna. */
    public static function controlledFolders(): array
    {
        if (! self::active()) {
            return [];
        }
        $list = [];
        foreach (self::folders() as $folder) {
            if ($folder['controlada']) {
                $list[] = [$folder['delegacion'], $folder['codigo']];
            }
        }

        return self::byDelegation($list);
    }

    /**
     * Restringe una consulta de DOCFAT a sus documentos en alguna de las
     * carpetas indicadas ([delegación => [códigos]]); sin carpetas, a ninguno.
     */
    public static function whereInFolders($query, array $groups, string $table = 'DOCFAT'): void
    {
        $query->where(function ($q) use ($groups, $table) {
            if (! $groups) {
                $q->whereRaw('1 = 0');

                return;
            }
            // Con COALESCE para que la negación (whereNot) no se pierda con NULL.
            foreach ($groups as $del => $codes) {
                $q->orWhere(function ($w) use ($del, $codes, $table) {
                    $w->whereRaw("COALESCE({$table}.DIR2DEL, '') = ?", [(string) $del])
                        ->whereIn(DB::raw("COALESCE({$table}.DIR2COD, 0)"), $codes);
                });
            }
        });
    }

    /**
     * Restringe una consulta de DOCFAT a los documentos controlados ($controlled) o a los que no lo están.
     * Los accesos directos nunca cuentan como controlados (su destino sí).
     */
    public static function whereControlled($query, bool $controlled = true, string $table = 'DOCFAT'): void
    {
        if (! self::active()) {
            // Sin Calidad no hay controlados.
            if ($controlled) {
                $query->whereRaw('1 = 0');
            }

            return;
        }
        $flag = "COALESCE({$table}.FATCCTL, '')";
        $groups = self::controlledFolders();
        $condition = function ($q) use ($flag, $groups, $table) {
            $q->where(function ($c) use ($flag, $groups, $table) {
                $c->whereRaw("{$flag} = 'T'")->orWhere(function ($w) use ($flag, $groups, $table) {
                    $w->whereRaw("{$flag} = ''");
                    self::whereInFolders($w, $groups, $table);
                });
            });
            VeolabDocuments::whereNotShortcut($q, $table);
        };

        $controlled ? $query->where($condition) : $query->whereNot($condition);
    }

    // ------------------------------------------------------------------
    // Documentos
    // ------------------------------------------------------------------

    /** Documento (DOCFAT) o null; con $lock, bloqueado hasta el commit. */
    public static function document(string $del, int $cod, bool $lock = false): ?object
    {
        $query = DB::connection('dynamic')->table('DOCFAT')->where('DEL3COD', $del)->where('FAT1COD', $cod);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /**
     * ¿Está controlado? (FATCCTL o, vacío, la carpeta). Acepta la fila de BD (objeto o array).
     * Un acceso directo nunca lo está: el control es el de su destino. Sin
     * el módulo Calidad, ninguno.
     */
    public static function isControlled($document): bool
    {
        $document = (object) $document;
        if (! self::active() || VeolabDocuments::isShortcut($document)) {
            return false;
        }
        $flag = (string) ($document->FATCCTL ?? '');
        if ($flag === 'T') {
            return true;
        }
        if ($flag === 'F') {
            return false;
        }

        return (bool) (self::folder($document->DIR2DEL ?? '', $document->DIR2COD ?? 0)['controlada'] ?? false);
    }

    /** ¿Controlado con una versión principal distinta de la aprobada? */
    public static function isPendingApproval($document): bool
    {
        $document = (object) $document;
        $approved = (int) ($document->VER2APR ?? 0);

        return self::isControlled($document) && $approved > 0 && $approved !== (int) $document->VER2COD;
    }

    /** Estado efectivo (B, V, P, O) de un controlado; null si no lo está. */
    public static function state($document): ?string
    {
        $document = (object) $document;
        if (! self::isControlled($document)) {
            return null;
        }
        $state = (string) ($document->FATCEST ?? '');
        $approved = (int) ($document->VER2APR ?? 0);
        if ($state === 'O') {
            return 'O';
        }
        if ($approved > 0 && $approved !== (int) $document->VER2COD) {
            return 'P';
        }

        // DCT_EstadoEfectivo: con edición aprobada es vigente, aunque FATCEST diga borrador
        return $approved > 0 ? 'V' : 'B';
    }

    /** Tipo de notificación de la próxima revisión (COM_NOTIF_REVISION_DOCUMENTO). */
    public const REVIEW_NOTIFICATION = 'W';

    /**
     * COM_CrearNotificacionesRevisionDocumento (dentro de la transacción de
     * quien llama): borra el aviso W anterior y, con el módulo COM y
     * LABCON.CONNRDC > 0, lo crea si el documento está controlado, no está
     * obsoleto y tiene próxima revisión. Sale CONNRDC días antes (u hoy si ya
     * pasó) para los usuarios activos de quien lo elabora o, si no hay, para
     * los que pueden aprobar documentos controlados (DOC_CTL especial 1).
     */
    public static function notifyReview(string $del, int $cod): void
    {
        if (! VeolabVersion::isV3()) {
            return;
        }
        $db = DB::connection('dynamic');
        $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
            .'WHERE ACCAVI.AVICTIP = ? AND ACCNOT.NOTCTIP = ? AND ACCNOT.FAT2DEL = ? AND ACCNOT.FAT2COD = ?',
            ['N', self::REVIEW_NOTIFICATION, $del, $cod]);
        $db->table('ACCNOT')->where('NOTCTIP', self::REVIEW_NOTIFICATION)->where('FAT2DEL', $del)->where('FAT2COD', $cod)->delete();

        $days = (int) $db->table('LABCON')->where('CON1COD', 1)->value('CONNRDC');
        if ($days <= 0 || ! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'COM')) {
            return;
        }
        $document = $db->table('DOCFAT')->where('DEL3COD', $del)->where('FAT1COD', $cod)->first();
        if (! $document || empty($document->FATDREV) || (string) $document->FATCEST === 'O' || ! self::isControlled($document)) {
            return;
        }

        $next = substr((string) $document->FATDREV, 0, 10);
        $text = (string) $document->FATCNOM;
        if (trim((string) $document->FATCCOD) !== '') {
            $text = trim((string) $document->FATCCOD).' - '.$text;
        }
        $text = mb_substr($text, 0, 240).': '.(new \DateTime($next))->format('d/m/Y');
        $today = self::today();
        $notice = (new \DateTime($next))->modify("-{$days} days")->format('Y-m-d');
        $date = ($notice < $today ? $today : $notice).' 00:00:00';

        $active = fn ($q) => $q->whereNull('ACCUSU.USUBBAJ')->orWhere('ACCUSU.USUBBAJ', '<>', 'T');
        $users = (int) ($document->EME2COD ?? 0) === 0 ? collect() : $db->table('ACCUSU')
            ->where('ACCUSU.EMP2DEL', (string) $document->EME2DEL)->where('ACCUSU.EMP2COD', (int) $document->EME2COD)
            ->where($active)->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD']);
        if ($users->isEmpty()) {
            $users = $db->table('ACCUSU')
                ->join('ACCPYF', fn ($j) => $j->on('ACCUSU.PER2DEL', '=', 'ACCPYF.DEL3COD')->on('ACCUSU.PER2COD', '=', 'ACCPYF.PER3COD'))
                ->where('ACCPYF.FUN3COD', self::FUNCTIONALITY)
                ->whereRaw('MOD(ACCPYF.PYFNACC, 8) >= 4 AND MOD(ACCPYF.PYFNACC, 2) = 1')
                ->where('ACCUSU.USUNTIP', 0)->where($active)
                ->distinct()->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD']);
        }

        foreach ($users as $user) {
            [$userDel, $userCod] = [(string) $user->DEL3COD, (string) $user->USU1COD];
            $notification = VeolabCodes::next('ACCNOT', '', $userDel);
            $db->table('ACCNOT')->insert([
                'DEL3COD' => $userDel, 'NOT1COD' => $notification, 'NOTTFEC' => $date, 'NOTCTIP' => self::REVIEW_NOTIFICATION,
                'NOTCAVI' => $text, 'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'FAT2DEL' => $del, 'FAT2COD' => $cod,
            ]);
            $db->table('ACCAVI')->insert([
                'DEL3COD' => $userDel, 'AVI1COD' => VeolabCodes::next('ACCAVI', '', $userDel), 'AVITFEC' => $date,
                'AVICTIP' => 'N', 'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'NOT2DEL' => $userDel, 'NOT2COD' => $notification,
            ]);
        }
    }

    /** El mismo estado efectivo en SQL (para filtrar los listados). */
    public static function stateSql(string $table = 'DOCFAT'): string
    {
        return "(CASE WHEN {$table}.FATCEST = 'O' THEN 'O'"
            ." WHEN COALESCE({$table}.VER2APR, 0) > 0 AND {$table}.VER2APR <> {$table}.VER2COD THEN 'P'"
            ." WHEN COALESCE({$table}.VER2APR, 0) <= 0 THEN 'B'"
            ." ELSE 'V' END)";
    }

    /** Periodicidad de revisión en meses: la del documento o, si no tiene, la de su carpeta; null si ninguna. */
    public static function periodicity($document): ?int
    {
        $document = (object) $document;
        if ((int) ($document->FATNPER ?? 0) > 0) {
            return (int) $document->FATNPER;
        }

        return self::folder($document->DIR2DEL ?? '', $document->DIR2COD ?? 0)['periodicidad'] ?? null;
    }

    /** Hoy, de la base de datos ('aaaa-mm-dd'). */
    public static function today(): string
    {
        return (string) DB::connection('dynamic')->selectOne('SELECT CURDATE() AS d')->d;
    }

    /**
     * Próxima revisión: hoy + periodicidad (como DateAdd de VB6: fin de mes); null sin periodicidad
     * (quien la graba deja entonces la que tuviera el documento, puesta a mano).
     */
    public static function nextReview($document): ?string
    {
        $months = self::periodicity($document);
        if ($months === null) {
            return null;
        }

        return (string) DB::connection('dynamic')
            ->selectOne('SELECT DATE_ADD(CURDATE(), INTERVAL ? MONTH) AS d', [$months])->d;
    }

    /**
     * Copia dual a versión visible: si el documento tenía VER2DUA, esa
     * versión se queda (con el nombre «Versión anterior» si no tenía) y
     * VER2DUA pasa a 0. Devuelve las columnas de DOCFAT que hay que cambiar.
     */
    public static function dualToVersion(object $document): array
    {
        $dual = (int) ($document->VER2DUA ?? 0);
        if ($dual <= 0) {
            return [];
        }
        DB::connection('dynamic')->table('DOCVER')
            ->where('DEL3COD', (string) $document->DEL3COD)->where('FAT3COD', (int) $document->FAT1COD)
            ->where('VER1COD', $dual)
            ->where(fn ($q) => $q->whereNull('VERCNOM')->orWhere('VERCNOM', ''))
            ->update(['VERCNOM' => self::PREVIOUS_VERSION_NAME]);

        return ['VER2DUA' => 0];
    }

    /**
     * Al pasar a controlado: control de versiones (FATBVER = T), la copia
     * dual a versión visible, estado inicial borrador si no tiene y el tipo
     * de la carpeta si no tiene. Solo cambia lo que hace falta; devuelve si
     * ha cambiado algo. Lo llama quien sabe que el documento está controlado.
     */
    public static function activate(string $del, int $cod): bool
    {
        $document = self::document($del, $cod);
        if (! $document || VeolabDocuments::isShortcut($document)) {
            return false;
        }

        $update = self::dualToVersion($document);
        if ($document->FATBVER !== 'T') {
            $update['FATBVER'] = 'T';
        }
        if ((string) ($document->FATCEST ?? '') === '') {
            $update['FATCEST'] = 'B';
        }
        if ((string) ($document->FATCTDC ?? '') === '') {
            $type = self::folder($document->DIR2DEL, $document->DIR2COD)['tipo'] ?? null;
            if ($type !== null) {
                $update['FATCTDC'] = $type;
            }
        }
        if (! $update) {
            return false;
        }
        DB::connection('dynamic')->table('DOCFAT')->where('DEL3COD', $del)->where('FAT1COD', $cod)->update($update);

        return true;
    }

    /**
     * Al marcar una carpeta como controlada: activa los documentos de la
     * carpeta y sus subcarpetas que siguen a la carpeta (FATCCTL vacío).
     * Devuelve cuántos han cambiado.
     */
    public static function activateFolder(string $del, int $cod): int
    {
        self::forget();
        if (! self::active()) {
            return 0;
        }
        $groups = self::byDelegation(self::subtree($del, $cod));
        if (! $groups) {
            return 0;
        }

        $query = DB::connection('dynamic')->table('DOCFAT')
            ->where(fn ($q) => $q->whereNull('FATCCTL')->orWhere('FATCCTL', ''));
        self::whereInFolders($query, $groups);
        VeolabDocuments::whereNotShortcut($query);   // los accesos directos no se activan

        $changed = 0;
        foreach ($query->get(['DEL3COD', 'FAT1COD']) as $row) {
            if (self::activate((string) $row->DEL3COD, (int) $row->FAT1COD)) {
                $changed++;
            }
        }

        return $changed;
    }

    // ------------------------------------------------------------------
    // Usuario de la petición (la API no tiene sesión)
    // ------------------------------------------------------------------

    /**
     * Usuario de Veolab con sus privilegios de documentos: ['activo',
     * 'aprobar' (especial 1 de DOC_CTL), 'revisar' (especial 2 de DOC_CTL,
     * revisar ediciones antes de aprobarlas), 'todas_versiones' (especial 3 de
     * DOC_EXP), 'empleado' => [delegación, código] o null, 'delegacion',
     * 'codigo']. Null si no se indica; 422 si no existe.
     */
    public static function requestUser(?string $del, ?string $cod): ?array
    {
        if ($cod === null || $cod === '') {
            return null;
        }
        $row = DB::connection('dynamic')->table('ACCUSU')
            ->leftJoin('ACCPYF AS CTL', function ($join) {
                $join->on('CTL.DEL3COD', '=', 'ACCUSU.PER2DEL')
                    ->on('CTL.PER3COD', '=', 'ACCUSU.PER2COD')
                    ->where('CTL.FUN3COD', '=', self::FUNCTIONALITY);
            })
            ->leftJoin('ACCPYF AS EXP', function ($join) {
                $join->on('EXP.DEL3COD', '=', 'ACCUSU.PER2DEL')
                    ->on('EXP.PER3COD', '=', 'ACCUSU.PER2COD')
                    ->where('EXP.FUN3COD', '=', self::EXPLORER);
            })
            ->where('ACCUSU.DEL3COD', (string) $del)->where('ACCUSU.USU1COD', $cod)
            ->first(['ACCUSU.USUBBAJ', 'ACCUSU.EMP2DEL', 'ACCUSU.EMP2COD', 'CTL.PYFNACC AS CTLNACC', 'EXP.PYFNACC AS EXPNACC']);
        if (! $row) {
            throw new BusinessRuleException('El usuario no existe');
        }
        $control = (int) ($row->CTLNACC ?? 0);
        $explorer = (int) ($row->EXPNACC ?? 0);

        // SES_ObtenAcceso: (acceso And especial) = especial
        return [
            'activo'          => $row->USUBBAJ !== 'T',
            'aprobar'         => ($control & VeolabPermissions::ACCESO) === VeolabPermissions::ACCESO
                && ($control & VeolabPermissions::ESPECIAL1) === VeolabPermissions::ESPECIAL1,
            'revisar'         => ($control & VeolabPermissions::ACCESO) === VeolabPermissions::ACCESO
                && ($control & self::SPECIAL2) === self::SPECIAL2,
            // Especial 2 de DOC_EXP (Veolab 3): solo la versión principal, sin abrir ni ver las demás
            'solo_principal'  => VeolabVersion::isV3() && ($explorer & VeolabPermissions::ACCESO) === VeolabPermissions::ACCESO
                && ($explorer & self::SPECIAL2) === self::SPECIAL2,
            'todas_versiones' => ($explorer & VeolabPermissions::ACCESO) === VeolabPermissions::ACCESO
                && ($explorer & (VeolabPermissions::ESPECIAL1 << 2)) === (VeolabPermissions::ESPECIAL1 << 2),
            'empleado'        => (int) ($row->EMP2COD ?? 0) !== 0 ? [(string) $row->EMP2DEL, (int) $row->EMP2COD] : null,
            'delegacion'      => (string) $del,
            'codigo'          => $cod,
        ];
    }

    /** Usuario de ?usuario_delegacion=&usuario_codigo= de la petición actual. */
    public static function queryUser(): ?array
    {
        $cod = request()->query('usuario_codigo');

        return self::requestUser(request()->query('usuario_delegacion'), is_scalar($cod) ? (string) $cod : null);
    }

    /** ¿Puede aprobar? Sin usuario, sí (la API actúa como privilegiada). */
    public static function canApprove(?array $user): bool
    {
        return $user === null || ($user['activo'] && $user['aprobar']);
    }

    /** Solo quien puede aprobar documentos (especial 1 de DOC_CTL). */
    public static function assertApprover(?array $user, string $action = 'aprobar documentos'): void
    {
        if (! self::canApprove($user)) {
            throw new BusinessRuleException("Solo quien tiene el privilegio de aprobar documentos puede {$action}");
        }
    }

    /** ¿El empleado del usuario es quien elabora el documento? */
    public static function isElaborator(?array $user, object $document): bool
    {
        return $user !== null && $user['empleado'] !== null && (int) ($document->EME2COD ?? 0) !== 0
            && $user['empleado'][0] === (string) ($document->EME2DEL ?? '')
            && $user['empleado'][1] === (int) $document->EME2COD;
    }

    /**
     * Versión que se entrega sin indicar versión: la aprobada (VER2APR) a quien
     * solo consulta un controlado pendiente de aprobar; la principal a quien
     * lo elabora, puede aprobar o ve todas las versiones, a un controlado
     * nunca aprobado y sin usuario.
     */
    public static function versionToOpen(object $document, ?array $user): int
    {
        $principal = (int) $document->VER2COD;
        if ($user === null || ! self::isPendingApproval($document) || (string) ($document->FATCEST ?? '') === 'B') {
            return $principal;
        }
        if (self::isElaborator($user, $document) || $user['aprobar'] || $user['todas_versiones']) {
            return $principal;
        }

        return (int) $document->VER2APR;
    }

    // ------------------------------------------------------------------
    // Empleados
    // ------------------------------------------------------------------

    /** El empleado existe. */
    public static function assertEmployee(string $del, int $cod, string $what = 'El empleado'): void
    {
        if (! DB::connection('dynamic')->table('GRHEMP')->where('DEL3COD', $del)->where('EMP1COD', $cod)->exists()) {
            throw new BusinessRuleException("{$what} no existe");
        }
    }

    /** Nombres de empleados: [clave "del\x1Bcod" => nombre]. */
    public static function employeeNames(array $pairs): array
    {
        $groups = [];
        foreach ($pairs as [$del, $cod]) {
            if ((int) $cod !== 0) {
                $groups[(string) $del][(int) $cod] = true;
            }
        }
        $out = [];
        foreach ($groups as $del => $codes) {
            $rows = DB::connection('dynamic')->table('GRHEMP')->where('DEL3COD', $del)
                ->whereIn('EMP1COD', array_keys($codes))->get(['EMP1COD', 'EMPCNOM']);
            foreach ($rows as $row) {
                $out[self::key($del, $row->EMP1COD)] = (string) $row->EMPCNOM;
            }
        }

        return $out;
    }

    /** Nombre de un empleado de employeeNames(), o null. */
    public static function employeeName(array $names, $del, $cod): ?string
    {
        return (int) $cod === 0 ? null : ($names[self::key($del, $cod)] ?? null);
    }

    // ------------------------------------------------------------------
    // Fase 2: distribución, lecturas pendientes, revisión y copias
    // («Analisis distribucion de documentos.md», apartado 5)
    // ------------------------------------------------------------------

    /**
     * ¿Tiene la base de datos la fase 2? (DOCDIS, DOCCOP y las columnas
     * DOCDIR.DIRNDLE/DIRBREV, DOCFAT.EMR2COD y DOCVER.VERBLEC/VERCEST, del
     * script de distribución de documentos). Una vez por base de datos.
     */
    public static function distributionAvailable(): bool
    {
        if (! self::available()) {
            return false;
        }
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (! array_key_exists($database, self::$distribution)) {
            try {
                $schema = Schema::connection('dynamic');
                self::$distribution[$database] = $schema->hasTable('DOCDIS') && $schema->hasTable('DOCCOP')
                    && $schema->hasColumn('DOCDIR', 'DIRNDLE') && $schema->hasColumn('DOCDIR', 'DIRBREV')
                    && $schema->hasColumn('DOCFAT', 'EMR2COD') && $schema->hasColumn('DOCVER', 'VERBLEC')
                    && $schema->hasColumn('DOCVER', 'VERCEST') && $schema->hasColumn('DOCVER', 'VERCREV');
            } catch (\Throwable) {
                self::$distribution[$database] = false;
            }
        }

        return self::$distribution[$database];
    }

    /** Fase 2 disponible y control de documentos activo (módulo Calidad). */
    public static function distributionActive(): bool
    {
        return self::active() && self::distributionAvailable();
    }

    /** 422 sin Calidad o sin el script de la fase 2. */
    public static function assertDistribution(): void
    {
        self::assertActive();
        if (! self::distributionAvailable()) {
            throw new BusinessRuleException(self::DISTRIBUTION_MESSAGE);
        }
    }

    /** ¿Exige su carpeta (o una superior) revisión antes de aprobar? Sin la fase 2, no. */
    public static function requiresReview($document): bool
    {
        $document = (object) $document;
        if (! self::distributionAvailable()) {
            return false;
        }

        return (bool) (self::folder($document->DIR2DEL ?? '', $document->DIR2COD ?? 0)['revision'] ?? false);
    }

    /** Días para leer una edición nueva: los de su carpeta (o la más cercana que los tenga) o 15. */
    public static function readingDays($document): int
    {
        $document = (object) $document;

        return self::folder($document->DIR2DEL ?? '', $document->DIR2COD ?? 0)['dias_lectura'] ?? self::DEFAULT_READING_DAYS;
    }

    /** Carpetas controladas con revisión antes de aprobar, agrupadas por delegación. */
    public static function reviewFolders(): array
    {
        if (! self::distributionActive()) {
            return [];
        }
        $list = [];
        foreach (self::folders() as $folder) {
            if ($folder['controlada'] && $folder['revision']) {
                $list[] = [$folder['delegacion'], $folder['codigo']];
            }
        }

        return self::byDelegation($list);
    }

    /** Estado de una edición (fila de DOCVER): VERCEST o, vacío, A si tiene fecha de aprobación y B si no. */
    public static function editionState($version): string
    {
        $version = (object) $version;
        $state = strtoupper(trim((string) ($version->VERCEST ?? '')));
        if (isset(self::EDITION_STATES[$state])) {
            return $state;
        }

        return ! empty($version->VERDAPR) ? 'A' : 'B';
    }

    /** ¿Requiere nueva lectura la edición? (VERBLEC vacío o T: sí; F: no) */
    public static function requiresReading($version): bool
    {
        return strtoupper(trim((string) (((object) $version)->VERBLEC ?? ''))) !== 'F';
    }

    /**
     * Paso pendiente de la edición en curso (la principal, sin aprobar) de
     * un controlado no obsoleto: 'revisar' si su carpeta exige revisión y la
     * edición no está revisada (R), 'aprobar' si no; null si no hay ninguna
     * edición en curso. $principalState es el estado de la principal.
     */
    public static function pendingStep($document, ?string $principalState): ?string
    {
        $document = (object) $document;
        if (! self::isControlled($document) || (string) ($document->FATCEST ?? '') === 'O' || (int) ($document->VER2COD ?? 0) <= 0
            || (int) ($document->VER2APR ?? 0) === (int) $document->VER2COD) {
            return null;
        }

        return self::requiresReview($document) && $principalState !== 'R' ? 'revisar' : 'aprobar';
    }

    /** ¿Es el empleado del usuario el revisor del documento (DOCFAT.EMR2*)? */
    public static function isReviewer(?array $user, object $document): bool
    {
        return $user !== null && $user['empleado'] !== null && (int) ($document->EMR2COD ?? 0) !== 0
            && $user['empleado'][0] === (string) ($document->EMR2DEL ?? '')
            && $user['empleado'][1] === (int) $document->EMR2COD;
    }

    /**
     * Revisar o devolver una edición (DCT_RevisarEdicion): el especial 2 de
     * DOC_CTL o el revisor del documento. Revisar, nunca quien elabora la
     * edición (DOCFAT.EME2* o el usuario de la versión, $edition); devolver
     * ($orApprover) también lo puede quien aprueba, aunque la elabore. Sin
     * usuario, la API actúa como privilegiada.
     */
    public static function assertReviewer(?array $user, object $document, bool $orApprover = false, ?object $edition = null): void
    {
        if ($user === null) {
            return;
        }
        $author = $edition !== null && (string) ($edition->USU2COD ?? '') !== ''
            && (string) ($edition->USU2DEL ?? '') === (string) $user['delegacion'] && (string) $edition->USU2COD === (string) $user['codigo'];
        if (! $orApprover && (self::isElaborator($user, $document) || $author)) {
            throw new BusinessRuleException('Quien elabora el documento no puede revisar su propia edición');
        }
        $allowed = $user['activo'] && ($user['revisar'] || self::isReviewer($user, $document) || ($orApprover && $user['aprobar']));
        if (! $allowed) {
            throw new BusinessRuleException($orApprover
                ? 'Solo el revisor del documento, quien puede revisar documentos o quien puede aprobarlos puede devolver una edición'
                : 'Solo el revisor del documento o quien tiene el privilegio de revisar documentos puede revisar una edición');
        }
    }

    // --- Destinatarios (DOCDIS) ---------------------------------------

    /**
     * Todas las líneas de DOCDIS, leídas una vez por petición y base de
     * datos: ['documentos' => [clave => [filas]], 'carpetas' => [clave =>
     * [filas]]], en el orden de la línea (DIS1COD). Sin la fase 2, vacías.
     */
    public static function recipientLines(): array
    {
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (isset(self::$recipientLines[$database])) {
            return self::$recipientLines[$database];
        }
        $out = ['documentos' => [], 'carpetas' => []];
        if (self::distributionAvailable()) {
            $rows = DB::connection('dynamic')->table('DOCDIS')->orderBy('DEL3COD')->orderBy('DIS1COD')->get();
            foreach ($rows as $row) {
                if ((int) ($row->FAT2COD ?? 0) > 0) {
                    $out['documentos'][self::key($row->DEL3COD, $row->FAT2COD)][] = $row;
                } elseif ((int) ($row->DIR2COD ?? 0) > 0) {
                    $out['carpetas'][self::key($row->DEL3COD, $row->DIR2COD)][] = $row;
                }
            }
        }

        return self::$recipientLines[$database] = $out;
    }

    /** Olvida las líneas de destinatarios leídas (tras cambiarlas). */
    public static function forgetRecipients(): void
    {
        self::$recipientLines = [];
    }

    /**
     * Destinatarios que valen para una carpeta: los suyos o los de la primera
     * carpeta superior que tenga. ['origen' => 'carpeta' o null, 'carpeta' =>
     * [delegación, código] de la que los da, o null, 'lineas' => [filas]].
     */
    public static function folderRecipientLines($del, $cod): array
    {
        $lines = self::recipientLines();
        $folders = self::folders();
        $key = (int) $cod > 0 ? self::key($del, $cod) : null;
        $seen = [];
        while ($key !== null && isset($folders[$key]) && ! isset($seen[$key])) {
            $seen[$key] = true;
            if (! empty($lines['carpetas'][$key])) {
                return [
                    'origen'  => 'carpeta',
                    'carpeta' => [$folders[$key]['delegacion'], $folders[$key]['codigo']],
                    'lineas'  => $lines['carpetas'][$key],
                ];
            }
            $key = $folders[$key]['padre'];
        }

        return ['origen' => null, 'carpeta' => null, 'lineas' => []];
    }

    /**
     * Destinatarios que valen para un documento: los suyos o, si no tiene,
     * los de su carpeta o de la primera carpeta superior que tenga. Como
     * folderRecipientLines(), con origen 'documento' si son los suyos.
     */
    public static function documentRecipientLines($document): array
    {
        $document = (object) $document;
        $own = self::recipientLines()['documentos'][self::key($document->DEL3COD, $document->FAT1COD)] ?? [];
        if ($own) {
            return ['origen' => 'documento', 'carpeta' => null, 'lineas' => $own];
        }

        return self::folderRecipientLines($document->DIR2DEL ?? '', $document->DIR2COD ?? 0);
    }

    /**
     * Personal para resolver destinatarios, leído una vez por petición:
     * 'empleados' en activo [clave => [delegación, código, nombre]] por
     * nombre, 'cargos' y 'departamentos' [clave => [clave de empleado =>
     * true]] (GRHEYC, y GRHCAR.DEP2* del cargo) y 'autorizados' vigentes hoy
     * por técnica ["delegación\x1Btécnica" => [clave de empleado => true]].
     */
    private static function staff(): array
    {
        $database = (string) DB::connection('dynamic')->getDatabaseName();
        if (isset(self::$staff[$database])) {
            return self::$staff[$database];
        }
        $db = DB::connection('dynamic');

        $employees = [];
        $rows = $db->table('GRHEMP')->where(fn ($q) => $q->whereNull('EMPBBAJ')->orWhere('EMPBBAJ', '<>', 'T'))
            ->orderBy('EMPCNOM')->orderBy('DEL3COD')->orderBy('EMP1COD')->get(['DEL3COD', 'EMP1COD', 'EMPCNOM']);
        foreach ($rows as $row) {
            $employees[self::key($row->DEL3COD, $row->EMP1COD)] = [(string) $row->DEL3COD, (int) $row->EMP1COD, (string) $row->EMPCNOM];
        }

        $positions = [];
        $departments = [];
        // CargarDistribucion: solo los cargos que no están de baja
        $rows = $db->table('GRHEYC')
            ->join('GRHCAR', function ($join) {
                $join->on('GRHCAR.DEL3COD', '=', 'GRHEYC.CAR3DEL')->on('GRHCAR.CAR1COD', '=', 'GRHEYC.CAR3COD');
            })
            ->where(fn ($q) => $q->whereNull('GRHCAR.CARBBAJ')->orWhere('GRHCAR.CARBBAJ', '<>', 'T'))
            ->get(['GRHEYC.EMP3DEL', 'GRHEYC.EMP3COD', 'GRHEYC.CAR3DEL', 'GRHEYC.CAR3COD', 'GRHCAR.DEP2DEL', 'GRHCAR.DEP2COD']);
        foreach ($rows as $row) {
            $employee = self::key($row->EMP3DEL, $row->EMP3COD);
            if (! isset($employees[$employee])) {
                continue;   // de baja
            }
            $positions[self::key($row->CAR3DEL, $row->CAR3COD)][$employee] = true;
            if ((int) ($row->DEP2COD ?? 0) !== 0) {
                $departments[self::key($row->DEP2DEL ?? '', $row->DEP2COD)][$employee] = true;
            }
        }

        $authorized = [];
        if (VeolabAuthorizations::available()) {
            $today = self::today();
            $rows = $db->table('GRHAUT')->where('AUTCEST', VeolabAuthorizations::AUTORIZADO)
                ->get(['EMP3DEL', 'EMP3COD', 'TEC3DEL', 'TEC3COD', 'AUTCEST', 'AUTDCAD']);
            foreach ($rows as $row) {
                $employee = self::key($row->EMP3DEL, $row->EMP3COD);
                if (isset($employees[$employee]) && VeolabAuthorizations::isValid($row->AUTCEST, $row->AUTDCAD, $today)) {
                    $authorized[(string) $row->TEC3DEL."\x1B".(string) $row->TEC3COD][$employee] = true;
                }
            }
        }

        return self::$staff[$database] = [
            'empleados'     => $employees,
            'cargos'        => $positions,
            'departamentos' => $departments,
            'autorizados'   => $authorized,
        ];
    }

    /**
     * Personas de unas líneas de destinatarios para un documento, en activo y
     * sin repetir, por nombre: [clave => [delegación, código, nombre]].
     *  - T: los empleados de la delegación del documento y los comunes, o todos si es común;
     *  - E: ese empleado; C: quien tiene el cargo; D: quien tiene un cargo del
     *    departamento;
     *  - A: los autorizados vigentes (GRHAUT) en la técnica del documento
     *    (DOCFAT.TEC2COD, de su delegación); sin técnica, nadie.
     */
    public static function resolveRecipients(object $document, array $lines): array
    {
        $staff = self::staff();
        $chosen = [];
        foreach ($lines as $line) {
            $type = strtoupper(trim((string) ($line->DISCTIP ?? '')));
            if ($type === 'T') {
                $del = (string) $document->DEL3COD;
                foreach ($staff['empleados'] as $employee => $data) {
                    // ResolverLineas: los de su delegación y los comunes
                    if ($del === '' || $data[0] === '' || $data[0] === $del) {
                        $chosen[$employee] = true;
                    }
                }
            } elseif ($type === 'E') {
                $employee = self::key($line->EMP2DEL ?? '', $line->EMP2COD ?? 0);
                if (isset($staff['empleados'][$employee])) {
                    $chosen[$employee] = true;
                }
            } elseif ($type === 'C') {
                $chosen += $staff['cargos'][self::key($line->CAR2DEL ?? '', $line->CAR2COD ?? 0)] ?? [];
            } elseif ($type === 'D') {
                $chosen += $staff['departamentos'][self::key($line->DEP2DEL ?? '', $line->DEP2COD ?? 0)] ?? [];
            } elseif ($type === 'A') {
                $technique = trim((string) ($document->TEC2COD ?? ''));
                if ($technique !== '') {
                    $chosen += $staff['autorizados'][(string) $document->DEL3COD."\x1B".$technique] ?? [];
                }
            }
        }

        $out = [];
        foreach ($staff['empleados'] as $employee => $data) {
            if (isset($chosen[$employee])) {
                $out[$employee] = $data;
            }
        }

        return $out;
    }

    /** Nombre de un empleado en activo leído para los destinatarios, o null. */
    public static function staffName($del, $cod): ?string
    {
        return self::staff()['empleados'][self::key($del, $cod)][2] ?? null;
    }

    // --- Lecturas pendientes ------------------------------------------

    /**
     * Lecturas de la edición vigente (VER2APR) de unos documentos, con dos
     * consultas por delegación para todos (versiones y lecturas). Solo los
     * controlados fuera de la papelera, no obsoletos y con edición aprobada.
     *
     * Vale la lectura de la vigente o, si la vigente no requiere nueva
     * lectura (VERBLEC = F), la de cualquier edición aprobada anterior
     * posterior a la última que sí la requirió (incluida). Pendientes =
     * destinatarios − quien la ha leído. Vencida: fecha de aprobación + días
     * para leer (15 por defecto) anterior a hoy.
     *
     * Con $employee [delegación, código] solo cuenta ese empleado (y se
     * omiten los documentos de los que no es destinatario).
     *
     * Devuelve [clave "del\x1Bcod" => ['documento', 'version', 'edicion',
     * 'fecha_aprobacion', 'dias', 'fecha_limite', 'vencida', 'desde_version',
     * 'origen' (documento, carpeta o null), 'carpeta_origen', 'destinatarios'
     * [clave => [del, cod, nombre]], 'lecturas' [clave => ['delegacion',
     * 'codigo', 'version', 'fecha']] (también de quien no es destinatario),
     * 'pendientes' [clave => [del, cod, nombre]] y 'leidas' (destinatarios
     * que la han leído)]]. Sin la fase 2, vacío.
     */
    public static function readingStatus(iterable $documents, ?array $employee = null): array
    {
        if (! self::distributionActive()) {
            return [];
        }
        $db = DB::connection('dynamic');
        $employeeKey = $employee !== null ? self::key($employee[0], $employee[1]) : null;

        $targets = [];
        foreach ($documents as $document) {
            $document = (object) $document;
            if ((int) ($document->DIR2COD ?? 0) === 0 || (string) ($document->FATCEST ?? '') === 'O'
                || (int) ($document->VER2APR ?? 0) <= 0 || ! self::isControlled($document)) {
                continue;
            }
            $lines = self::documentRecipientLines($document);
            $recipients = self::resolveRecipients($document, $lines['lineas']);
            if ($employeeKey !== null) {
                if (! isset($recipients[$employeeKey])) {
                    continue;
                }
                $recipients = [$employeeKey => $recipients[$employeeKey]];
            }
            $targets[self::key($document->DEL3COD, $document->FAT1COD)] = [$document, $recipients, $lines];
        }
        if (! $targets) {
            return [];
        }

        $groups = [];
        foreach ($targets as [$document]) {
            $groups[(string) $document->DEL3COD][] = (int) $document->FAT1COD;
        }
        $versions = [];
        $readings = [];
        foreach ($groups as $del => $codes) {
            foreach (array_chunk(array_values(array_unique($codes)), 500) as $chunk) {
                $rows = $db->table('DOCVER')->where('DEL3COD', $del)->whereIn('FAT3COD', $chunk)
                    ->get(['FAT3COD', 'VER1COD', 'VERBLEC', 'VERDAPR', 'VERCEDI']);
                foreach ($rows as $row) {
                    $versions[self::key($del, $row->FAT3COD)][(int) $row->VER1COD] = $row;
                }
                $query = $db->table('DOCLEC')->where('DEL3COD', $del)->whereIn('FAT3COD', $chunk);
                if ($employee !== null) {
                    $query->where('EMP3DEL', (string) $employee[0])->where('EMP3COD', (int) $employee[1]);
                }
                foreach ($query->get(['FAT3COD', 'VER3COD', 'EMP3DEL', 'EMP3COD', 'LECTFEC']) as $row) {
                    $readings[self::key($del, $row->FAT3COD)][] = $row;
                }
            }
        }

        $today = self::today();
        $out = [];
        foreach ($targets as $key => [$document, $recipients, $lines]) {
            $current = (int) $document->VER2APR;
            $own = $versions[$key] ?? [];

            // Primera edición cuya lectura vale: la última aprobada hasta la vigente que requirió lectura.
            $from = 0;
            if (! isset($own[$current])) {
                $from = $current;
            } else {
                $codes = array_keys($own);
                rsort($codes);
                foreach ($codes as $code) {
                    if ($code > $current || ($code !== $current && empty($own[$code]->VERDAPR))) {
                        continue;
                    }
                    if (self::requiresReading($own[$code])) {
                        $from = $code;
                        break;
                    }
                }
            }

            $read = [];
            foreach ($readings[$key] ?? [] as $row) {
                $version = (int) $row->VER3COD;
                if ($version < $from || $version > $current) {
                    continue;
                }
                $reader = self::key($row->EMP3DEL, $row->EMP3COD);
                if (! isset($read[$reader]) || $version > $read[$reader]['version']) {
                    $read[$reader] = [
                        'delegacion' => (string) $row->EMP3DEL,
                        'codigo'     => (int) $row->EMP3COD,
                        'version'    => $version,
                        'fecha'      => $row->LECTFEC,
                    ];
                }
            }

            $approvedAt = $own[$current]->VERDAPR ?? null;
            $days = self::readingDays($document);
            $deadline = self::addDays($approvedAt, $days);
            $out[$key] = [
                'documento'        => $document,
                'version'          => $current,
                'edicion'          => $own[$current]->VERCEDI ?? null,
                'fecha_aprobacion' => $approvedAt,
                'dias'             => $days,
                'fecha_limite'     => $deadline,
                'vencida'          => $deadline !== null && $deadline < $today,
                'desde_version'    => $from,
                'origen'           => $lines['origen'],
                'carpeta_origen'   => $lines['carpeta'],
                'destinatarios'    => $recipients,
                'lecturas'         => $read,
                'pendientes'       => array_diff_key($recipients, $read),
                'leidas'           => count(array_intersect_key($read, $recipients)),
            ];
        }

        return $out;
    }

    /** Día 'aaaa-mm-dd' de una fecha de la base de datos más unos días, o null. */
    public static function addDays($date, int $days): ?string
    {
        $day = substr(trim((string) ($date ?? '')), 0, 10);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day === '0000-00-00') {
            return null;
        }

        return (new \DateTimeImmutable($day))->modify("+{$days} days")->format('Y-m-d');
    }

    /**
     * Consulta de DOCFAT de los documentos controlados en vigor (fuera de la
     * papelera, no obsoletos y con edición aprobada) con las columnas que
     * necesitan readingStatus() y las tareas.
     */
    public static function inForceDocuments()
    {
        $query = DB::connection('dynamic')->table('DOCFAT')
            ->where('DOCFAT.DIR2COD', '>', 0)->where('DOCFAT.VER2APR', '>', 0)
            ->where(fn ($q) => $q->whereNull('DOCFAT.FATCEST')->orWhere('DOCFAT.FATCEST', '<>', 'O'));
        self::whereControlled($query, true, 'DOCFAT');

        return $query->select(['DOCFAT.DEL3COD', 'DOCFAT.FAT1COD', 'DOCFAT.FATCNOM', 'DOCFAT.FATCTIP', 'DOCFAT.FATCCOD',
            'DOCFAT.FATCEST', 'DOCFAT.FATCCTL', 'DOCFAT.VER2COD', 'DOCFAT.VER2APR', 'DOCFAT.DIR2DEL', 'DOCFAT.DIR2COD',
            'DOCFAT.TEC2COD']);   // sin accesos directos: whereControlled ya los excluye
    }

    // --- Copias controladas (DOCCOP) ------------------------------------

    /**
     * Condición SQL «copia por retirar» sobre DOCCOP ($copy) y DOCFAT
     * ($document): sin fecha de retirada y de una edición anterior a la
     * vigente o de un documento obsoleto (FATCEST = 'O'), como el escritorio.
     */
    public static function toWithdrawSql(string $copy = 'DOCCOP', string $document = 'DOCFAT'): string
    {
        return "({$copy}.COPDRET IS NULL AND ({$document}.FATCEST = 'O' OR (COALESCE({$copy}.VER2COD, 0) > 0"
            ." AND COALESCE({$document}.VER2APR, 0) > 0 AND {$copy}.VER2COD < {$document}.VER2APR)))";
    }

    /** ¿Está la copia por retirar? (fila de DOCCOP y su documento; el documento debe traer VER2APR y FATCEST) */
    public static function isToWithdraw(object $copy, ?object $document): bool
    {
        if (! empty($copy->COPDRET)) {
            return false;
        }
        if (($document->FATCEST ?? '') === 'O') {
            return true;
        }
        $approved = (int) ($document->VER2APR ?? 0);
        $version = (int) ($copy->VER2COD ?? 0);

        return $version > 0 && $approved > 0 && $version < $approved;
    }
}
