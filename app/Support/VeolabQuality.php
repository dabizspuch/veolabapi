<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Módulo Calidad (CAL, desde Veolab 3; ISO/IEC 17025 7.9, 7.10, 8.5-8.7):
 * incidencias (CALINC) con sus acciones (CALACC), lo que afectan (CALIYE),
 * implicados (CALIEM) y tipos (CALTIN). Réplica de Calidad.bas y de los
 * avisos de Comunicaciones.bas (COM_CrearNotificacionesIncidencia y
 * COM_CrearNotificacionesAccionesCalidad).
 *
 * La API no tiene sesión de usuario: donde Veolab mira al usuario conectado
 * (privilegio de responsable de calidad, quién registró la incidencia, a
 * quién no se avisa) se usa el usuario que indique la petición
 * (usuario_delegacion + usuario_codigo); sin él, la API actúa como el
 * responsable de calidad, como en el resto de recursos, donde los
 * privilegios los comprueba la aplicación cliente.
 */
class VeolabQuality
{
    public const FUNCTIONALITY = 'CAL_INC';

    /** Queja, trabajo no conforme, hallazgo de auditoría, mejora y riesgo (CAL_CLASES). */
    public const CLASSES = ['Q', 'T', 'N', 'M', 'R'];

    /** Cliente, personal, auditoría interna y externa, proveedor, ensayo de aptitud, carta de control, equipo, recepción, revisión por la dirección y otro. */
    public const ORIGINS = ['C', 'P', 'I', 'E', 'V', 'A', 'K', 'Q', 'R', 'D', 'O'];

    /** Corrección, acción correctiva y mejora o tratamiento del riesgo (CAL_TIPOS_ACCION). */
    public const ACTION_TYPES = ['C', 'A', 'M'];

    /** Hallazgo de auditoría mayor, menor y observación (CAL_HALLAZGOS). */
    public const FINDINGS = ['M', 'N', 'O'];

    public const OPEN_STATES = ['R', 'T', 'V'];

    /** Textos de los estados (ETI00816-820). */
    public const STATE_NAMES = [
        'R' => 'Registrada',
        'T' => 'En tratamiento',
        'V' => 'En verificación',
        'C' => 'Cerrada',
        'A' => 'Anulada',
    ];

    /** Tablas que se pueden enlazar en «A qué afecta» (CAL_TABLAS_ENLACE, ALMPRD, CALEAP, CALAUI, CALRDI y CALOBJ), con su texto (ETI00834-842, ETI00897, ETI01069). */
    public const LINK_TABLES = [
        'LABOPE' => 'Operación',
        'LABINF' => 'Informe',
        'SINCLI' => 'Cliente',
        'SINPRO' => 'Proveedor',
        'GRHEMP' => 'Empleado',
        'LABTEC' => 'Parámetro',             // ETI00839
        'ALMSEL' => 'Serie o lote',          // ETI00840
        'ALMPRD' => 'Producto',              // ETI00897
        'LABCDC' => 'Gráfico de control',    // ETI00841
        'CALINC' => 'Incidencia',
        'CALEAP' => 'Ensayo de aptitud',     // ETI01069; Veolab lo enlaza desde la ficha de la ronda
        'CALAUI' => 'Auditoría',             // Veolab lo enlaza desde la ficha de la auditoría (hallazgos)
        'CALRDI' => 'Revisión por la dirección',   // Veolab lo enlaza desde la ficha de la revisión (decisiones)
        'CALOBJ' => 'Objetivo de calidad',         // Veolab lo enlaza desde la ficha del objetivo («Abrir mejora»; serie '')
    ];

    /** Notificaciones: paso de una incidencia (Q) y fecha prevista de una acción (K). */
    public const NOTIFICATION_STEP = 'Q';
    public const NOTIFICATION_ACTION = 'K';

    // ------------------------------------------------------------------
    // Reglas de Calidad.bas
    // ------------------------------------------------------------------

    /** CAL_AdmiteNoConformidad: mejoras y riesgos no son incumplimientos. */
    public static function admitsNonConformity(string $class): bool
    {
        return $class !== 'M' && $class !== 'R';
    }

    /**
     * CAL_TratamientoCompleto + CaminoCompleto (FichaIncidencia): pasan por
     * verificación las no conformidades, las quejas y cualquier incidencia con
     * una acción correctiva (de toda acción correctiva se revisa la eficacia).
     */
    public static function fullTreatment(string $class, bool $nonConformity, bool $hasCorrective): bool
    {
        return $nonConformity || $class === 'Q' || $hasCorrective;
    }

    /** El módulo CAL debe estar activo (y licenciado) para escribir. */
    public static function assertModule(): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'CAL')) {
            throw new BusinessRuleException('El módulo de calidad no está activo');
        }
    }

    /** Incidencia (CALINC) o null; con $lock, bloqueada hasta el commit. */
    public static function incident(string $del, string $ser, int $cod, bool $lock = false): ?object
    {
        $query = DB::connection('dynamic')->table('CALINC')
            ->where('DEL3COD', $del)->where('INC1SER', $ser)->where('INC1COD', $cod);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /** Estado de la incidencia ('' cuenta como registrada, como LeerRegistro). */
    public static function state(object $incident): string
    {
        $state = (string) ($incident->INCCEST ?? '');

        return $state === '' ? 'R' : $state;
    }

    /** Una incidencia cerrada o anulada es de solo lectura (ISO 17025, 8.4). */
    public static function assertOpen(object $incident): void
    {
        if (! in_array(self::state($incident), self::OPEN_STATES, true)) {
            throw new BusinessRuleException('La incidencia está '.mb_strtolower(self::STATE_NAMES[self::state($incident)] ?? 'cerrada')
                .' y solo se puede consultar; para cambiarla hay que reabrirla (PUT /incidencias/estado)');
        }
    }

    /**
     * Usuario de Veolab para las comprobaciones que el escritorio hace con el
     * usuario conectado: ['activo', 'escritura' en CAL_INC, 'calidad'
     * (privilegio especial 1 de CAL_INC: responsable de calidad), 'empleado'
     * => [delegación, código] o null]. Null si no existe.
     */
    public static function user(string $del, string $cod): ?array
    {
        $row = DB::connection('dynamic')->table('ACCUSU')
            ->leftJoin('ACCPYF', function ($join) {
                $join->on('ACCPYF.DEL3COD', '=', 'ACCUSU.PER2DEL')
                    ->on('ACCPYF.PER3COD', '=', 'ACCUSU.PER2COD')
                    ->where('ACCPYF.FUN3COD', '=', self::FUNCTIONALITY);
            })
            ->where('ACCUSU.DEL3COD', $del)->where('ACCUSU.USU1COD', $cod)
            ->first(['ACCUSU.USUBBAJ', 'ACCUSU.EMP2DEL', 'ACCUSU.EMP2COD', 'ACCPYF.PYFNACC']);
        if (! $row) {
            return null;
        }
        $access = (int) ($row->PYFNACC ?? 0);

        return [
            'activo'    => $row->USUBBAJ !== 'T',
            'escritura' => ($access & 3) === 3,
            // (PYFNACC MOD 8) >= 4 AND (PYFNACC MOD 2) = 1, como Comunicaciones.bas
            'calidad'   => ($access % 8) >= 4 && ($access % 2) === 1,
            'empleado'  => (int) ($row->EMP2COD ?? 0) !== 0 ? [(string) $row->EMP2DEL, (int) $row->EMP2COD] : null,
        ];
    }

    /** Usuario de la petición (usuario_delegacion + usuario_codigo) o null si no se indica. 422 si no existe. */
    public static function requestUser(?string $del, ?string $cod): ?array
    {
        if ($cod === null || $cod === '') {
            return null;
        }
        $user = self::user((string) $del, $cod);
        if (! $user) {
            throw new BusinessRuleException('El usuario no existe');
        }

        return $user + ['delegacion' => (string) $del, 'codigo' => $cod];
    }

    /** ¿El empleado del usuario es el de las columnas indicadas? (EsEmpleadoConectado) */
    public static function isUserEmployee(?array $user, $empDel, $empCod): bool
    {
        return $user !== null && $user['empleado'] !== null && (int) $empCod !== 0
            && $user['empleado'][0] === (string) $empDel && $user['empleado'][1] === (int) $empCod;
    }

    /**
     * CAL_BorrarIncidencia (sin la fila de CALINC, que ya ha borrado quien
     * llama): acciones, enlaces propios y de otras incidencias hacia esta,
     * implicados, notificaciones con sus avisos y documentos a la papelera.
     * Las preguntas de las listas de verificación que la tenían como
     * hallazgo se quedan sin él (CALAUP.INC2* a NULL).
     */
    public static function deleteIncidentData(string $del, string $ser, int $cod): void
    {
        $db = DB::connection('dynamic');
        $own = fn ($table) => $db->table($table)->where('DEL3COD', $del)->where('INC3SER', $ser)->where('INC3COD', $cod);

        $own('CALACC')->delete();
        $own('CALIYE')->delete();
        $db->table('CALIYE')->where('IYECTAB', 'CALINC')->where('IYECDEL', $del)
            ->where('IYECSER', $ser)->where('IYECCOD', (string) $cod)->delete();
        $own('CALIEM')->delete();
        VeolabAuditChecklists::clearFinding($del, $ser, $cod);
        // Los avisos de sus notificaciones, antes que ellas (como CAL_BorrarIncidencia).
        $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
            .'WHERE ACCAVI.AVICTIP = ? AND ACCNOT.INC2DEL = ? AND ACCNOT.INC2SER = ? AND ACCNOT.INC2COD = ?', ['N', $del, $ser, $cod]);
        $db->table('ACCNOT')->where('INC2DEL', $del)->where('INC2SER', $ser)->where('INC2COD', $cod)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('INC2SER', $ser)->where('INC2COD', $cod)
            ->where(fn ($q) => $q->whereNull('DIR2COD')->orWhere('DIR2COD', '<>', 0))
            ->update(VeolabDocuments::trashUpdate($del));
    }

    /**
     * Suceso de la ficha de la incidencia por un cambio en una de sus
     * rejillas (acciones, enlaces, implicados), como FichaIncidencia.Grabar:
     * fila con nivel 2, campo «tabla modificada» sin valores con nivel 3.
     */
    public static function auditIncident(string $del, string $ser, int $cod, string $field): void
    {
        $row = VeolabCodes::format('CALINC', (string) $cod, $del, $ser);
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'CALINC', $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'CALINC', $row, $field);
    }

    // ------------------------------------------------------------------
    // Enlaces (CALIYE): a qué afecta la incidencia
    // ------------------------------------------------------------------

    /**
     * Consulta del registro enlazado (o null si la tabla no se enlaza). En
     * ALMSEL la "serie" del enlace es el producto y el código la serie o lote.
     */
    /** Tablas enlazables cuyo código es un número. */
    private const NUMERIC_LINK_TABLES = ['LABOPE', 'LABINF', 'GRHEMP', 'LABCDC', 'CALINC', 'CALEAP', 'CALAUI', 'CALRDI', 'CALOBJ'];

    private static function linkQuery(string $table, string $del, string $ser, string $cod)
    {
        $db = DB::connection('dynamic');

        return match ($table) {
            'LABOPE' => ctype_digit($cod) ? $db->table('LABOPE')->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', (int) $cod) : null,
            'LABINF' => ctype_digit($cod) ? $db->table('LABINF')->where('DEL3COD', $del)->where('INF1SER', $ser)->where('INF1COD', (int) $cod) : null,
            'SINCLI' => $db->table('SINCLI')->where('DEL3COD', $del)->where('CLI1COD', $cod),
            'SINPRO' => $db->table('SINPRO')->where('DEL3COD', $del)->where('PRO1COD', $cod),
            'GRHEMP' => ctype_digit($cod) ? $db->table('GRHEMP')->where('DEL3COD', $del)->where('EMP1COD', (int) $cod) : null,
            'LABTEC' => $db->table('LABTEC')->where('DEL3COD', $del)->where('TEC1COD', $cod),
            'ALMSEL' => $db->table('ALMSEL')->where('PRD3DEL', $del)->where('PRD3COD', $ser)->where('SEL1COD', $cod),
            'ALMPRD' => $db->table('ALMPRD')->where('DEL3COD', $del)->where('PRD1COD', $cod),
            'LABCDC' => ctype_digit($cod) ? $db->table('LABCDC')->where('DEL3COD', $del)->where('CDC1COD', (int) $cod) : null,
            'CALINC' => ctype_digit($cod) ? $db->table('CALINC')->where('DEL3COD', $del)->where('INC1SER', $ser)->where('INC1COD', (int) $cod) : null,
            'CALEAP' => ctype_digit($cod) ? $db->table('CALEAP')->where('DEL3COD', $del)->where('EAP1SER', $ser)->where('EAP1COD', (int) $cod) : null,
            'CALAUI' => ctype_digit($cod) ? $db->table('CALAUI')->where('DEL3COD', $del)->where('AUI1SER', $ser)->where('AUI1COD', (int) $cod) : null,
            'CALRDI' => ctype_digit($cod) && VeolabManagementReview::available()
                ? $db->table('CALRDI')->where('DEL3COD', $del)->where('RDI1SER', $ser)->where('RDI1COD', (int) $cod) : null,
            'CALOBJ' => ctype_digit($cod) && VeolabQualityIndicators::available()
                ? $db->table('CALOBJ')->where('DEL3COD', $del)->where('OBJ1COD', (int) $cod) : null,
            default  => null,
        };
    }

    /** ¿Existe el registro enlazado? */
    public static function linkExists(string $table, string $del, string $ser, string $cod): bool
    {
        $query = self::linkQuery($table, $del, $ser, $cod);

        return $query !== null && $query->exists();
    }

    /**
     * CAL_EnlaceInventario: la serie oculta de un producto sin series (código
     * '-') se enlaza como el producto, que es lo que el usuario ve.
     */
    public static function normalizeLink(string $table, string $del, string $ser, string $cod): array
    {
        if ($table === 'ALMSEL' && $cod === VeolabStock::SERIE_UNICA) {
            return ['ALMPRD', $del, '', $ser];
        }
        // Las tablas de código numérico guardan el valor sin ceros a la izquierda, como el escritorio
        // («05» → «5»): si no, el enlace no aparecería en CAL_IncidenciasDe ni se borraría en cascada.
        if (in_array($table, self::NUMERIC_LINK_TABLES, true) && ctype_digit($cod)) {
            $cod = (string) (int) $cod;
        }

        return [$table, $del, $ser, $cod];
    }

    /** CAL_CodigoEnlace: código del registro enlazado en el formato de Veolab. */
    public static function linkCode(string $table, string $del, string $ser, string $cod): string
    {
        if ($table === 'ALMSEL') {
            return VeolabCodes::format('ALMPRD', $ser, $del).' / '.$cod;
        }

        return VeolabCodes::format($table, $cod, $del, $ser);
    }

    /** CAL_DescripcionEnlace: descripción para la lista; vacía si ya no existe. */
    public static function linkDescription(string $table, string $del, string $ser, string $cod): string
    {
        $query = self::linkQuery($table, $del, $ser, $cod);
        if ($query === null) {
            return '';
        }
        $date = fn ($v) => $v ? (new \DateTime((string) $v))->format('d/m/Y') : '';

        switch ($table) {
            case 'LABOPE':
                $client = $query->first(['CLI2DEL', 'CLI2COD']);

                return $client ? (string) DB::connection('dynamic')->table('SINCLI')
                    ->where('DEL3COD', (string) $client->CLI2DEL)->where('CLI1COD', (string) $client->CLI2COD)->value('CLICNOM') : '';
            case 'LABINF':
                return $date($query->value('INFDCRE'));
            case 'LABCDC':
                return $date($query->value('CDCDCRE'));
            case 'CALEAP':     // Programa y ronda
                $round = $query->first(['EAPCPRG', 'EAPCRON']);

                return $round ? trim($round->EAPCPRG.' '.$round->EAPCRON) : '';
            case 'CALAUI':     // Tipo y descripción
                $audit = $query->first(['AUICTIP', 'AUICDES']);
                if (! $audit) {
                    return '';
                }
                $text = VeolabQualityAudits::TYPE_NAMES[(string) $audit->AUICTIP] ?? '';

                return trim((string) $audit->AUICDES) !== '' ? $text.' - '.trim((string) $audit->AUICDES) : $text;
            case 'CALOBJ':     // Año y descripción
                $objective = $query->first(['OBJNANI', 'OBJCDES']);

                return $objective ? trim($objective->OBJNANI.' - '.$objective->OBJCDES) : '';
            case 'ALMSEL':
                $serial = $query->first(['SELCDES']);
                if (! $serial) {
                    return '';
                }
                $text = (string) DB::connection('dynamic')->table('ALMPRD')->where('DEL3COD', $del)->where('PRD1COD', $ser)->value('PRDCDES');

                return trim((string) $serial->SELCDES) !== '' ? $text.' - '.$serial->SELCDES : $text;
            default:
                $column = ['SINCLI' => 'CLICNOM', 'SINPRO' => 'PROCNOM', 'GRHEMP' => 'EMPCNOM', 'LABTEC' => 'TECCNOM',
                    'ALMPRD' => 'PRDCDES', 'CALINC' => 'INCCDES', 'CALRDI' => 'RDICDES'][$table];

                return (string) $query->value($column);
        }
    }

    // ------------------------------------------------------------------
    // Avisos (Comunicaciones.bas): se crean tras grabar, fuera de la transacción
    // ------------------------------------------------------------------

    /**
     * Ejecuta $callback tras el commit de la transacción en curso (o ya, si
     * no hay ninguna), en su propia transacción. Un fallo al avisar no deshace
     * la grabación, como en Veolab: se registra y se sigue.
     */
    public static function afterCommit(callable $callback): void
    {
        $run = function () use ($callback) {
            try {
                DB::connection('dynamic')->transaction(fn () => $callback());
            } catch (\Throwable $e) {
                Log::warning('Calidad: no se han podido crear los avisos: '.$e->getMessage());
            }
        };

        $db = DB::connection('dynamic');
        if ($db->transactionLevel() > 0) {
            try {
                $db->afterCommit($run);

                return;
            } catch (\Throwable) {
                // Sin gestor de transacciones: se avisa ya.
            }
        }
        $run();
    }

    /**
     * COM_CrearNotificacionesIncidencia: avisa del paso dado (el estado actual,
     * según el anterior) a quien le corresponde:
     *   registrada: responsables de calidad (especial 1 de CAL_INC);
     *   en tratamiento: responsable, verificador e implicados (si se reabre, solo el responsable);
     *   en verificación: verificador;
     *   cerrada o anulada: quien la detectó e implicados.
     * Con «avisar a todos», además a todos los usuarios con acceso a
     * incidencias. Nunca a quien da el paso ($actor, si se conoce). Cada
     * usuario conserva solo la última notificación de cada incidencia.
     * Necesita los módulos COM y CAL y LABCON.CONBNIN.
     */
    public static function notifyStep(string $del, string $ser, int $cod, string $previous, ?array $actor): void
    {
        $db = DB::connection('dynamic');
        $database = $db->getDatabaseName();
        if (! VeolabLicense::moduleActive('dynamic', $database, 'COM') || ! VeolabLicense::moduleActive('dynamic', $database, 'CAL')
            || ($db->table('LABCON')->where('CON1COD', 1)->value('CONBNIN') ?? '') !== 'T') {
            return;
        }
        $incident = $db->table('CALINC')->where('DEL3COD', $del)->where('INC1SER', $ser)->where('INC1COD', $cod)
            ->first(['INCCEST', 'INCCDES', 'INCBTOD', 'EMP2DEL', 'EMP2COD', 'EMR2DEL', 'EMR2COD', 'EMV2DEL', 'EMV2COD']);
        if (! $incident) {
            return;
        }

        $state = (string) $incident->INCCEST;
        $users = [];
        $access = null;
        switch ($state) {
            case 'R':
                $access = '(ACCPYF.PYFNACC % 8) >= 4 AND (ACCPYF.PYFNACC % 2) = 1';     // especial 1
                break;
            case 'T':
                if ($previous === 'R' || $previous === '') {
                    self::addEmployeeUsers($users, [[$incident->EMR2DEL, $incident->EMR2COD], [$incident->EMV2DEL, $incident->EMV2COD]], [$del, $ser, $cod]);
                } else {
                    self::addEmployeeUsers($users, [[$incident->EMR2DEL, $incident->EMR2COD]], null);
                }
                break;
            case 'V':
                self::addEmployeeUsers($users, [[$incident->EMV2DEL, $incident->EMV2COD]], null);
                break;
            case 'C':
            case 'A':
                self::addEmployeeUsers($users, [[$incident->EMP2DEL, $incident->EMP2COD]], [$del, $ser, $cod]);
                break;
        }
        if ($incident->INCBTOD === 'T') {
            $access = '(ACCPYF.PYFNACC % 2) = 1';      // acceso permitido
        }
        if ($access !== null) {
            $rows = $db->table('ACCUSU')
                ->join('ACCPYF', function ($join) {
                    $join->on('ACCUSU.PER2DEL', '=', 'ACCPYF.DEL3COD')->on('ACCUSU.PER2COD', '=', 'ACCPYF.PER3COD');
                })
                ->where('ACCPYF.FUN3COD', self::FUNCTIONALITY)->whereRaw($access)->where('ACCUSU.USUNTIP', 0)
                ->where(fn ($q) => $q->whereNull('ACCUSU.USUBBAJ')->orWhere('ACCUSU.USUBBAJ', '<>', 'T'))
                ->distinct()->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD']);
            foreach ($rows as $row) {
                $users[$row->DEL3COD."\x1B".$row->USU1COD] = [(string) $row->DEL3COD, (string) $row->USU1COD];
            }
        }
        if ($actor !== null) {
            unset($users[$actor['delegacion']."\x1B".$actor['codigo']]);
        }
        if (! $users) {
            return;
        }

        $text = mb_substr((self::STATE_NAMES[$state] ?? $state).': '.$incident->INCCDES, 0, 255);
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        foreach ($users as [$userDel, $userCod]) {
            self::deleteNotifications(self::NOTIFICATION_STEP, $del, $ser, $cod, [$userDel, $userCod]);
            self::insertNotification(self::NOTIFICATION_STEP, $del, $ser, $cod, $userDel, $userCod, $now, $text);
        }
    }

    /**
     * COM_CrearNotificacionesAccionesCalidad: rehace las notificaciones K de
     * la incidencia, una para cada usuario activo del responsable de cada
     * acción sin fecha real, con aviso en la fecha prevista, mientras la
     * incidencia está abierta. Siempre borra las anteriores; crea las nuevas
     * con el módulo COM y LABCON.CONBNAC.
     */
    public static function notifyActions(string $del, string $ser, int $cod): void
    {
        $db = DB::connection('dynamic');
        $database = $db->getDatabaseName();
        if (! VeolabLicense::moduleActive('dynamic', $database, 'CAL')) {
            return;
        }
        self::deleteNotifications(self::NOTIFICATION_ACTION, $del, $ser, $cod, null);

        if (! VeolabLicense::moduleActive('dynamic', $database, 'COM')
            || ($db->table('LABCON')->where('CON1COD', 1)->value('CONBNAC') ?? '') !== 'T') {
            return;
        }
        $rows = $db->table('CALACC')
            ->join('CALINC', function ($join) {
                $join->on('CALINC.DEL3COD', '=', 'CALACC.DEL3COD')->on('CALINC.INC1SER', '=', 'CALACC.INC3SER')
                    ->on('CALINC.INC1COD', '=', 'CALACC.INC3COD');
            })
            ->join('ACCUSU', function ($join) {
                $join->on('ACCUSU.EMP2DEL', '=', 'CALACC.EMP2DEL')->on('ACCUSU.EMP2COD', '=', 'CALACC.EMP2COD');
            })
            ->where('CALACC.DEL3COD', $del)->where('CALACC.INC3SER', $ser)->where('CALACC.INC3COD', $cod)
            ->whereNull('CALACC.ACCDREA')->whereNotNull('CALACC.ACCDPRE')
            ->whereIn('CALINC.INCCEST', self::OPEN_STATES)
            ->where(fn ($q) => $q->whereNull('ACCUSU.USUBBAJ')->orWhere('ACCUSU.USUBBAJ', '<>', 'T'))
            ->get(['CALACC.ACCCDES', 'CALACC.ACCDPRE', 'ACCUSU.DEL3COD', 'ACCUSU.USU1COD']);

        foreach ($rows as $row) {
            $day = substr((string) $row->ACCDPRE, 0, 10);
            $text = mb_substr((string) $row->ACCCDES, 0, 230).': '.(new \DateTime($day))->format('d/m/Y');
            self::insertNotification(self::NOTIFICATION_ACTION, $del, $ser, $cod, (string) $row->DEL3COD, (string) $row->USU1COD,
                $day.' 00:00:00', $text);
        }
    }

    /** AnadirUsuariosEmpleados: usuarios activos de los empleados y, con $incident, de sus implicados. */
    private static function addEmployeeUsers(array &$users, array $employees, ?array $incident): void
    {
        $db = DB::connection('dynamic');
        $pairs = array_filter($employees, fn ($e) => (int) ($e[1] ?? 0) !== 0);
        if (! $pairs && $incident === null) {
            return;
        }

        $rows = $db->table('ACCUSU')
            ->where(function ($q) use ($pairs, $incident) {
                foreach ($pairs as [$empDel, $empCod]) {
                    $q->orWhere(fn ($w) => $w->where('ACCUSU.EMP2DEL', (string) $empDel)->where('ACCUSU.EMP2COD', (int) $empCod));
                }
                if ($incident !== null) {
                    [$del, $ser, $cod] = $incident;
                    $q->orWhereExists(function ($s) use ($del, $ser, $cod) {
                        $s->select(DB::raw(1))->from('CALIEM')
                            ->whereColumn('CALIEM.EMP3DEL', 'ACCUSU.EMP2DEL')->whereColumn('CALIEM.EMP3COD', 'ACCUSU.EMP2COD')
                            ->where('CALIEM.DEL3COD', $del)->where('CALIEM.INC3SER', $ser)->where('CALIEM.INC3COD', $cod);
                    });
                }
            })
            ->where(fn ($q) => $q->whereNull('ACCUSU.USUBBAJ')->orWhere('ACCUSU.USUBBAJ', '<>', 'T'))
            ->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD']);
        foreach ($rows as $row) {
            $users[$row->DEL3COD."\x1B".$row->USU1COD] = [(string) $row->DEL3COD, (string) $row->USU1COD];
        }
    }

    /** BorrarNotificacionesIncidencia: las de un tipo de la incidencia (de un usuario, si se indica) y sus avisos. */
    public static function deleteNotifications(string $type, string $del, string $ser, int $cod, ?array $user): void
    {
        $db = DB::connection('dynamic');
        $sql = 'DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
            .'WHERE ACCAVI.AVICTIP = ? AND ACCNOT.NOTCTIP = ? AND ACCNOT.INC2DEL = ? AND ACCNOT.INC2SER = ? AND ACCNOT.INC2COD = ?';
        $bindings = ['N', $type, $del, $ser, $cod];
        $query = $db->table('ACCNOT')->where('NOTCTIP', $type)->where('INC2DEL', $del)->where('INC2SER', $ser)->where('INC2COD', $cod);
        if ($user !== null) {
            $sql .= ' AND ACCNOT.USU2DEL = ? AND ACCNOT.USU2COD = ?';
            array_push($bindings, $user[0], $user[1]);
            $query->where('USU2DEL', $user[0])->where('USU2COD', $user[1]);
        }
        $db->delete($sql, $bindings);
        $query->delete();
    }

    /** InsertarNotificacionesIncidencia: notificación con su aviso, en la delegación del usuario. */
    private static function insertNotification(string $type, string $del, string $ser, int $cod, string $userDel, string $userCod, string $date, string $text): void
    {
        $db = DB::connection('dynamic');
        $notification = VeolabCodes::next('ACCNOT', '', $userDel);
        $db->table('ACCNOT')->insert([
            'DEL3COD' => $userDel, 'NOT1COD' => $notification, 'NOTTFEC' => $date, 'NOTCTIP' => $type, 'NOTCAVI' => $text,
            'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'INC2DEL' => $del, 'INC2SER' => $ser, 'INC2COD' => $cod,
        ]);
        $db->table('ACCAVI')->insert([
            'DEL3COD' => $userDel, 'AVI1COD' => VeolabCodes::next('ACCAVI', '', $userDel), 'AVITFEC' => $date,
            'AVICTIP' => 'N', 'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'NOT2DEL' => $userDel, 'NOT2COD' => $notification,
        ]);
    }
}
