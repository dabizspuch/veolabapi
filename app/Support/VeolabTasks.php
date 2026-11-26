<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tareas pendientes de un usuario (opción COM_TAR de Veolab 3, entrega 1b de
 * Calidad): réplica de solo lectura de Tareas.bas del escritorio.
 *
 * No se guarda nada: cada tarea se calcula al vuelo con una consulta por tipo
 * sobre los registros de origen y desaparece sola cuando se hace. Una tarea es
 * «personal» si el registro la asigna al empleado del usuario (ACCUSU.EMP2*)
 * o al propio usuario, y «por cargo» si va a un privilegio (responsable de
 * calidad, aprobar documentos, decidir solicitudes...) y la ven todos los que
 * lo tienen. Cada tipo exige el acceso del perfil (ACCPYF) a la funcionalidad
 * que abre su ficha; todo exige COM_TAR y el módulo COM, y no es para
 * usuarios de tipo cliente. Las tareas de Calidad (incidencias, auditorías,
 * aptitud, revisión por la dirección y documentos controlados) solo salen con
 * el módulo CAL. Fase 2: fecha de compromiso de las operaciones y
 * calibración, verificación y mantenimiento de los equipos; después, stock
 * bajo el mínimo, seguimiento de presupuestos, gráficos de control en error
 * y familias del plan de intercomparaciones a las que les toca participar.
 * Objetivos de calidad (13-nov-2026): los del año actual sin definir desde
 * febrero y los de años anteriores sin cerrar. Control de documentos fase 2
 * (14-nov-2026): revisar edición (DE), aprobar solo lo revisado si la
 * carpeta lo exige, leer la edición vigente como destinatario (DL, con
 * plazo) y retirar copias controladas (DC).
 *
 * La API no tiene sesión: el usuario lo indica la petición (como en
 * notificaciones o avisos) y su delegación hace de delegación conectada (la
 * suya y las comunes). «Hoy» es la fecha del servidor de base de datos.
 * Las consultas son las del escritorio; si la de un tipo falla (tabla o
 * columna que aún no existe en la base de datos), ese tipo se omite y se
 * informa en omitted().
 */
class VeolabTasks
{
    public const FUNCTIONALITY = 'COM_TAR';

    /** Se incluyen las que vencen dentro de estos días (TAR_DIAS_ANTELACION). */
    public const DAYS_AHEAD = 30;

    /** Aviso: vence dentro de estos días (TAR_DIAS_AVISO); plazo de la fecha de compromiso. */
    public const WARN_DAYS = 7;

    /** Revisión por la dirección anual (TAR_MESES_REVISION). */
    public const REVIEW_MONTHS = 12;

    /**
     * Tipos en el orden de TAR_TIPOS, con su texto (ETI01332-01349; OC-MM,
     * ETI01394-01397; ST-FA, ETI01400-01403; OB-OA, ETI01480-01481; DE y
     * DC, control de documentos fase 2, 14-nov-2026, al final para no mover
     * las posiciones de los textos del escritorio; MP, patrones y
     * materiales de referencia caducados o a punto, 14-nov-2026, también al
     * final). VV (valorar la actualización de Veolab) es solo del escritorio.
     */
    public const TYPES = [
        'IC' => 'Clasificar incidencia',
        'IT' => 'Tratar incidencia',
        'IA' => 'Hacer acción',
        'IV' => 'Verificar incidencia',
        'AR' => 'Realizar auditoría',
        'AC' => 'Cerrar auditoría',
        'EE' => 'Enviar resultados de aptitud',
        'EV' => 'Evaluar ronda de aptitud',
        'EC' => 'Cerrar ronda de aptitud',
        'RD' => 'Completar revisión por la dirección',
        'RA' => 'Convocar la revisión por la dirección',
        'PE' => 'Evaluar proveedor',
        'AU' => 'Renovar autorización',
        'DA' => 'Aprobar edición',
        'DR' => 'Revisar documento',
        'DL' => 'Leer nueva edición',
        'FI' => 'Firmar informe',
        'SO' => 'Decidir solicitud',
        'OC' => 'Fecha de compromiso',
        'MC' => 'Calibrar equipo',
        'MV' => 'Verificar equipo',
        'MM' => 'Mantener equipo',
        'ST' => 'Reponer stock',
        'PS' => 'Seguimiento de presupuesto',
        'GC' => 'Revisar gráfico de control',
        'FA' => 'Participar en intercomparación',
        'OB' => 'Definir los objetivos de calidad',
        'OA' => 'Cerrar los objetivos de calidad',
        'DE' => 'Revisar edición',
        'DC' => 'Retirar copias',
        'MP' => 'Renovar patrón o material de referencia',
    ];

    /**
     * Grupos (TAR_GRUPOS): calidad, proveedores, personal, documentos, informes,
     * solicitudes (ETI01350-01355), operaciones, inventario (ETI01398-01399) y
     * ventas (ETI01404).
     */
    public const GROUPS = [
        'Q' => 'Calidad',
        'P' => 'Proveedores',
        'E' => 'Personal',
        'D' => 'Documentos',
        'I' => 'Informes',
        'S' => 'Solicitudes',
        'O' => 'Operaciones',
        'M' => 'Inventario',
        'V' => 'Ventas',
    ];

    /** Textos de las clases de incidencia (CAL_TextoClase, ETI00800-804). */
    private const CLASS_NAMES = [
        'Q' => 'Queja',
        'T' => 'Trabajo no conforme',
        'N' => 'Hallazgo de auditoría',
        'M' => 'Mejora',
        'R' => 'Riesgo',
    ];

    private const DECISION_TEXT = 'Decisión de revisión';         // ETI01381
    private const ACTION_TEXT = 'Acción %1';                       // ETI01382
    private const REVIEWS_TEXT = 'Revisiones por la dirección';    // MNU00157
    private const OBJECTIVES_UNDEFINED_TEXT = 'Objetivos de calidad de %1 sin definir';   // ETI01482
    private const OBJECTIVES_OPEN_TEXT = 'Objetivos de calidad de %1: %2 sin cerrar';     // ETI01483
    private const EDITION_TEXT = '%1 (edición %2)';              // ETI01561
    private const VERSION_TEXT = 'Versión %1';                   // ETI01241
    private const COPY_TEXT = '%1 copia por retirar';            // control de documentos fase 2
    private const COPIES_TEXT = '%1 copias por retirar';

    /** Serie oculta de los productos sin series ni lotes (ALM_SERIE_UNICA). */
    private const SINGLE_SERIAL = '-';

    /** Bits de PYFNACC (SES_ACCESO_*). */
    private const ACCESS = 0x1;
    private const WRITE = 0x2;
    private const SPECIAL1 = 0x4;
    private const SPECIAL2 = 0x8;

    /** Usuario de tipo cliente (ACCUSU.USUNTIP, SES_USUARIO_CLIENTE). */
    private const CUSTOMER_USER = 1;

    private string $userDel;
    private string $userCod;
    /** [delegación, código] del empleado del usuario, o null. */
    private ?array $employee;
    /** Funcionalidad => máscara PYFNACC del perfil del usuario. */
    private array $access;
    private string $database;
    private string $today = '';
    /** Hoy + DAYS_AHEAD + 1 (fin excluido), 'aaaa-mm-dd'. */
    private string $limit = '';
    private array $tasks = [];
    private array $omitted = [];

    private function __construct(string $del, string $cod, ?array $employee, array $access)
    {
        $this->userDel = $del;
        $this->userCod = $cod;
        $this->employee = $employee;
        $this->access = $access;
        $this->database = (string) DB::connection('dynamic')->getDatabaseName();
    }

    /**
     * Calculador para el usuario indicado (TAR_Activo). 422 si no existe, está
     * de baja, es de tipo cliente, falta el módulo COM o su perfil no tiene la
     * funcionalidad COM_TAR.
     */
    public static function forUser(string $del, string $cod): self
    {
        $db = DB::connection('dynamic');
        $row = $db->table('ACCUSU')->where('DEL3COD', $del)->where('USU1COD', $cod)
            ->first(['USUBBAJ', 'USUNTIP', 'EMP2DEL', 'EMP2COD', 'PER2DEL', 'PER2COD']);
        if (! $row) {
            throw new BusinessRuleException('El usuario no existe');
        }
        if ($row->USUBBAJ === 'T') {
            throw new BusinessRuleException('El usuario está dado de baja');
        }
        if ((int) $row->USUNTIP === self::CUSTOMER_USER) {
            throw new BusinessRuleException('Los usuarios de tipo cliente no tienen tareas pendientes');
        }
        $database = (string) $db->getDatabaseName();
        if (! VeolabLicense::moduleActive('dynamic', $database, 'COM')) {
            throw new BusinessRuleException('Las tareas pendientes requieren el módulo Comunicaciones');
        }
        $access = VeolabPermissions::values((string) $row->PER2DEL, (int) $row->PER2COD);
        if (((int) ($access[self::FUNCTIONALITY] ?? 0) & self::ACCESS) !== self::ACCESS) {
            throw new BusinessRuleException('El usuario no tiene acceso a las tareas pendientes (funcionalidad '.self::FUNCTIONALITY.')');
        }
        $employee = (int) ($row->EMP2COD ?? 0) !== 0 ? [(string) $row->EMP2DEL, (int) $row->EMP2COD] : null;

        return new self($del, $cod, $employee, $access);
    }

    /** ¿Tiene el usuario un empleado enlazado? (sin él solo ve las tareas de su cargo) */
    public function hasEmployee(): bool
    {
        return $this->employee !== null;
    }

    /** Tipos que no se han podido calcular (su consulta falló). */
    public function omitted(): array
    {
        return $this->omitted;
    }

    /**
     * TAR_Calcular: las tareas, vencidas primero, después por fecha (sin fecha
     * al final) y por tipo. Cada una: tipo, tipo_texto, grupo, tabla,
     * delegacion, serie, codigo, registro, descripcion, fecha, vence, dias,
     * vencida y asignada (personal o cargo).
     */
    public function tasks(): array
    {
        $db = DB::connection('dynamic');
        $this->tasks = [];
        $this->omitted = [];
        $this->today = substr((string) $db->selectOne('SELECT CURDATE() AS d')->d, 0, 10);
        $this->limit = $this->addDays($this->today, self::DAYS_AHEAD + 1);

        if (VeolabLicense::moduleActive('dynamic', $this->database, 'CAL')) {   // booCalidad
            if ($this->has(VeolabQuality::FUNCTIONALITY)) {
                $this->run(['IC', 'IT', 'IV', 'IA'], fn () => $this->readIncidents());
            }
            if ($this->has('CAL_AUI')) {
                $this->run(['AR', 'AC'], fn () => $this->readAudits());
            }
            if ($this->has('CAL_EAP')) {
                $this->run(['EE', 'EV', 'EC'], fn () => $this->readProficiency());
            }
            if ($this->has(VeolabManagementReview::FUNCTIONALITY)) {   // RDI_AccesoRevisiones
                $this->run(['RD', 'RA'], fn () => $this->readReviews());
            }
            // OBJ_ResponsableCalidad: especial 1 de CAL_OBJ
            if ($this->has(VeolabQualityIndicators::FUNCTIONALITY, self::SPECIAL1)) {
                $this->run(['OB', 'OA'], fn () => $this->readObjectives());
            }
        }
        if ($this->has('FIC_PRO') || $this->has('ALM_PRO')) {
            $this->run(['PE'], fn () => $this->readSuppliers());
        }
        $this->run(['AU'], fn () => $this->readAuthorizations());
        if (VeolabDocumentControl::active()) {
            // Leer la edición vigente (DL): cualquier empleado, como el escritorio; el resto exige la lista (DOC_CTL)
            $list = $this->has(VeolabDocumentControl::FUNCTIONALITY);
            $this->run($list ? ['DE', 'DA', 'DR', 'DL', 'DC'] : ['DL'], fn () => $this->readDocuments($list));
        }
        if ($this->has('LAB_INF')) {
            $this->run(['FI'], fn () => $this->readSignatures());
        }
        if (VeolabLicense::moduleActive('dynamic', $this->database, 'SOL') && $this->has('LAB_SOL', self::WRITE)) {
            $this->run(['SO'], fn () => $this->readRequests());
        }
        // Cualquier operación con fecha de compromiso: CONBAFC solo la activa por defecto al crear la operación
        if ($this->has('LAB_OPE') || $this->has('LAB_REC')) {
            $this->run(['OC'], fn () => $this->readCommitments());
        }
        if (VeolabLicense::moduleActive('dynamic', $this->database, 'ALM') && $this->has('ALM_INV', self::WRITE)) {
            $this->run(['MC', 'MV', 'MM'], fn () => $this->readEquipment());
            $this->run(['ST'], fn () => $this->readStock());
            // Patrones y materiales de referencia (ISO/IEC 17025 6.5), con el script de los patrones
            if (VeolabMetrology::available()) {
                $this->run(['MP'], fn () => $this->readReferenceMaterials());
            }
        }
        if (VeolabLicense::moduleActive('dynamic', $this->database, 'VEN') && $this->has('VEN_PRE')) {
            $this->run(['PS'], fn () => $this->readQuotations());
        }
        if (VeolabLicense::moduleActive('dynamic', $this->database, 'CDC') && $this->has('LAB_CDC', self::WRITE)) {
            $quality = VeolabLicense::moduleActive('dynamic', $this->database, 'CAL');
            $this->run(['GC'], fn () => $this->readControlCharts($quality));
        }
        // Plan de intercomparaciones (booPlan): con Calidad y escritura en el plan (EAP_FUNCIONALIDAD_PLAN)
        if (VeolabLicense::moduleActive('dynamic', $this->database, 'CAL') && $this->has('CAL_EAF', self::WRITE)) {
            $this->run(['FA'], fn () => $this->readProficiencyPlan());
        }

        $tasks = $this->tasks;
        $order = array_flip(array_keys(self::TYPES));
        // usort es estable (PHP 8): a igualdad, el orden de lectura, como la inserción del escritorio
        usort($tasks, function (array $a, array $b) use ($order) {
            if ($a['vencida'] !== $b['vencida']) {
                return $a['vencida'] ? -1 : 1;
            }
            if (($a['fecha'] === null) !== ($b['fecha'] === null)) {
                return $a['fecha'] !== null ? -1 : 1;
            }
            if ($a['fecha'] !== null && $a['fecha'] !== $b['fecha']) {
                return strcmp($a['fecha'], $b['fecha']);
            }

            return $order[$a['tipo']] <=> $order[$b['tipo']];
        });

        return $tasks;
    }

    /** Grupo del tipo (TAR_GrupoTipo). */
    public static function groupOf(string $type): string
    {
        return match ($type) {
            'PE'                   => 'P',
            'AU'                   => 'E',
            'DE', 'DA', 'DR', 'DL', 'DC' => 'D',
            'FI'                   => 'I',
            'SO'                   => 'S',
            'OC'                   => 'O',
            'MC', 'MV', 'MM', 'ST', 'MP' => 'M',
            'PS'                   => 'V',
            default                => 'Q',
        };
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** SES_ObtenAcceso: (PYFNACC And tipo) = tipo. */
    private function has(string $functionality, int $mask = self::ACCESS): bool
    {
        return ((int) ($this->access[$functionality] ?? 0) & $mask) === $mask;
    }

    /** CAL_ResponsableCalidad: especial 1 de CAL_INC. */
    private function qualityManager(): bool
    {
        return $this->has(VeolabQuality::FUNCTIONALITY, self::SPECIAL1);
    }

    /** Ejecuta la lectura de unos tipos; si falla, se omiten y se registra. */
    private function run(array $types, callable $reader): void
    {
        try {
            $reader();
        } catch (\Throwable $e) {
            Log::warning('Tareas pendientes: no se han podido calcular '.implode(', ', $types).': '.$e->getMessage());
            $this->omitted = array_merge($this->omitted, $types);
        }
    }

    /** Añade el valor a los parámetros y devuelve su marcador. */
    private function bind($value, array &$bindings): string
    {
        $bindings[] = $value;

        return '?';
    }

    /** CondicionDelegacion: con delegación, la suya y las comunes. */
    private function delegationSql(string $field, array &$bindings): string
    {
        if ($this->userDel === '') {
            return '';
        }

        return " AND ({$field} = '' OR {$field} = ".$this->bind($this->userDel, $bindings).')';
    }

    /** Lo mismo sobre una consulta del constructor de consultas. */
    private function whereDelegation($query, string $field): void
    {
        if ($this->userDel !== '') {
            $query->where(fn ($w) => $w->where($field, '')->orWhere($field, $this->userDel));
        }
    }

    /** MiEmpleado: condición «es el empleado del usuario» (1 = 0 si no tiene). */
    private function myEmployeeSql(string $delField, string $codField, array &$bindings): string
    {
        if ($this->employee === null) {
            return '(1 = 0)';
        }

        return "({$delField} = ".$this->bind($this->employee[0], $bindings)." AND {$codField} = ".$this->bind($this->employee[1], $bindings).')';
    }

    /** EsMiEmpleado. */
    private function isMyEmployee($del, $cod): bool
    {
        return $this->employee !== null && (string) $del === $this->employee[0] && (int) $cod === $this->employee[1];
    }

    /** EsMiUsuario. */
    private function isMyUser($del, $cod): bool
    {
        return (string) $del === $this->userDel && (string) $cod !== ''
            && mb_strtolower((string) $cod) === mb_strtolower($this->userCod);
    }

    /** Código con el formato de la tabla (si no lo tiene, el número). */
    private function code(string $table, $cod, $del, $ser = ''): string
    {
        try {
            $code = VeolabCodes::format($table, (string) $cod, (string) $del, (string) $ser);
        } catch (\Throwable) {
            $code = '';
        }

        return $code !== '' ? $code : ((string) $ser !== '' ? $ser.'/' : '').$cod;
    }

    /** «Clase: título» (o «Decisión de revisión: título» en las de origen D). */
    private function incidentText(object $row): string
    {
        $class = self::CLASS_NAMES[(string) $row->INCCCLA] ?? '';
        if ((string) $row->INCCORI === 'D') {
            $class = self::DECISION_TEXT;
        }

        return ($class !== '' ? $class.': ' : '').$row->INCCDES;
    }

    /** Día 'aaaa-mm-dd' de un valor de fecha de la base de datos, o null. */
    private static function day($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function addDays(string $day, int $days): string
    {
        return (new \DateTimeImmutable($day))->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }

    /** DateAdd("m") de VB: si el día no existe en el mes de destino, el último del mes. */
    private function addMonths(string $day, int $months): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        $index = $y * 12 + ($m - 1) + $months;
        $y = intdiv($index, 12);
        $m = $index % 12 + 1;
        $last = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');

        return sprintf('%04d-%02d-%02d', $y, $m, min($d, $last));
    }

    /** Anadir: la tarea con sus datos calculados. */
    private function add(string $type, string $table, $del, $ser, $cod, string $record, string $description, $date, bool $due, bool $byRole): void
    {
        $day = self::day($date);
        $days = null;
        if ($day !== null) {
            $diff = (new \DateTimeImmutable($this->today))->diff(new \DateTimeImmutable($day));
            $days = $diff->days * ($diff->invert ? -1 : 1);
        }
        $this->tasks[] = [
            'tipo'        => $type,
            'tipo_texto'  => self::TYPES[$type],
            'grupo'       => self::groupOf($type),
            'tabla'       => $table,
            'delegacion'  => $del,
            'serie'       => $ser,
            'codigo'      => $cod,
            'registro'    => $record,
            'descripcion' => str_replace(["\r", "\n"], ' ', $description),
            'fecha'       => $day,
            'vence'       => $due,
            'dias'        => $days,
            'vencida'     => $due && $days !== null && $days < 0,
            'asignada'    => $byRole ? 'cargo' : 'personal',
        ];
    }

    // ------------------------------------------------------------------
    // Incidencias y acciones
    // ------------------------------------------------------------------

    /**
     * Clasificar (registradas: responsables de calidad), tratar (en
     * tratamiento: su responsable), verificar (en verificación: su
     * verificador o, sin él, los responsables de calidad) y hacer las acciones
     * sin fecha real de las abiertas (su responsable).
     */
    private function readIncidents(): void
    {
        $db = DB::connection('dynamic');
        $manager = $this->qualityManager();
        $b = [];
        $sql = 'SELECT CALINC.DEL3COD, CALINC.INC1SER, CALINC.INC1COD, CALINC.INCCCLA, CALINC.INCCORI, CALINC.INCCDES, CALINC.INCTREG, CALINC.INCCEST, '
            .'CALINC.EMR2COD, CALINC.EMV2DEL, CALINC.EMV2COD, '
            .'(SELECT MIN(a.ACCDPRE) FROM CALACC a WHERE a.DEL3COD = CALINC.DEL3COD AND a.INC3SER = CALINC.INC1SER AND a.INC3COD = CALINC.INC1COD AND a.ACCDREA IS NULL) AS PREVISTA, '
            .'(SELECT MAX(a.ACCDREA) FROM CALACC a WHERE a.DEL3COD = CALINC.DEL3COD AND a.INC3SER = CALINC.INC1SER AND a.INC3COD = CALINC.INC1COD) AS ULTIMA '
            .'FROM CALINC WHERE ('.($manager ? "CALINC.INCCEST = 'R' OR " : '')
            ."(CALINC.INCCEST = 'T' AND ".$this->myEmployeeSql('CALINC.EMR2DEL', 'CALINC.EMR2COD', $b).') OR '
            ."(CALINC.INCCEST = 'V' AND (".$this->myEmployeeSql('CALINC.EMV2DEL', 'CALINC.EMV2COD', $b)
            .($manager ? ' OR CALINC.EMV2COD IS NULL OR CALINC.EMV2COD = 0' : '').')))'
            .$this->delegationSql('CALINC.DEL3COD', $b);

        foreach ($db->select($sql, $b) as $row) {
            [$del, $ser, $cod] = [(string) $row->DEL3COD, (string) $row->INC1SER, (int) $row->INC1COD];
            $code = $this->code('CALINC', $row->INC1COD, $row->DEL3COD, $row->INC1SER);
            $text = $this->incidentText($row);
            switch ((string) $row->INCCEST) {
                case 'R':
                    $this->add('IC', 'CALINC', $del, $ser, $cod, $code, $text, $row->INCTREG, false, true);
                    break;
                case 'T':
                    if (self::day($row->PREVISTA) !== null) {
                        $this->add('IT', 'CALINC', $del, $ser, $cod, $code, $text, $row->PREVISTA, true, false);
                    } else {
                        $this->add('IT', 'CALINC', $del, $ser, $cod, $code, $text, $row->INCTREG, false, false);
                    }
                    break;
                case 'V':
                    $date = self::day($row->ULTIMA) !== null ? $row->ULTIMA : $row->INCTREG;
                    $this->add('IV', 'CALINC', $del, $ser, $cod, $code, $text, $date, false, ! $this->isMyEmployee($row->EMV2DEL, $row->EMV2COD));
                    break;
            }
        }

        // Acciones asignadas a su empleado, sin fecha real, de incidencias abiertas
        if ($this->employee === null) {
            return;
        }
        $b = [];
        $sql = 'SELECT CALACC.DEL3COD, CALACC.INC3SER, CALACC.INC3COD, CALACC.ACC1COD, CALACC.ACCCDES, CALACC.ACCDPRE, CALINC.INCCCLA, '
            .'CALINC.INCCORI, CALINC.INCCDES FROM CALACC INNER JOIN CALINC ON (CALINC.DEL3COD = CALACC.DEL3COD AND CALINC.INC1SER = CALACC.INC3SER '
            ."AND CALINC.INC1COD = CALACC.INC3COD) WHERE CALACC.ACCDREA IS NULL AND CALINC.INCCEST IN ('R', 'T', 'V') AND "
            .$this->myEmployeeSql('CALACC.EMP2DEL', 'CALACC.EMP2COD', $b).$this->delegationSql('CALACC.DEL3COD', $b);
        foreach ($db->select($sql, $b) as $row) {
            $text = str_replace('%1', (string) $row->ACC1COD, self::ACTION_TEXT).': '.mb_substr((string) $row->ACCCDES, 0, 150)
                .' ('.$this->incidentText($row).')';
            $this->add('IA', 'CALINC', (string) $row->DEL3COD, (string) $row->INC3SER, (int) $row->INC3COD,
                $this->code('CALINC', $row->INC3COD, $row->DEL3COD, $row->INC3SER), $text, $row->ACCDPRE, true, false);
        }
    }

    // ------------------------------------------------------------------
    // Auditorías
    // ------------------------------------------------------------------

    /**
     * AUI_SQLConHallazgosAbiertos, resuelta en PHP (CALIYE.IYECCOD es texto):
     * claves de las auditorías con alguna incidencia enlazada sin cerrar ni
     * anular. Si la consulta falla, ninguna (como el escritorio).
     */
    private function auditsWithOpenFindings(): ?array
    {
        $keys = [];
        try {
            $rows = DB::connection('dynamic')->select('SELECT DISTINCT h.IYECDEL, h.IYECSER, h.IYECCOD FROM CALIYE h INNER JOIN CALINC i ON '
                ."(i.DEL3COD = h.DEL3COD AND i.INC1SER = h.INC3SER AND i.INC1COD = h.INC3COD) WHERE h.IYECTAB = 'CALAUI' AND "
                ."(i.INCCEST IN ('R', 'T', 'V') OR i.INCCEST IS NULL OR i.INCCEST = '')");
        } catch (\Throwable $e) {
            Log::warning('Tareas pendientes: no se han podido leer los hallazgos abiertos: '.$e->getMessage());

            return null;   // «no se sabe» no es «ninguno»: no se propone cerrar ninguna auditoría
        }
        foreach ($rows as $row) {
            $cod = (int) trim((string) $row->IYECCOD);   // Val()
            if ($cod > 0) {
                $keys[(string) $row->IYECDEL."\x1B".(string) $row->IYECSER."\x1B".$cod] = true;
            }
        }

        return $keys;
    }

    /**
     * Realizar (previstas próximas o pasadas) y cerrar (informe emitido sin
     * hallazgos abiertos): el auditor jefe o, si no tiene, los responsables de
     * calidad.
     */
    private function readAudits(): void
    {
        $open = $this->auditsWithOpenFindings();
        $manager = $this->qualityManager();
        $b = [];
        $sql = 'SELECT CALAUI.DEL3COD, CALAUI.AUI1SER, CALAUI.AUI1COD, CALAUI.AUICEST, CALAUI.AUICDES, CALAUI.AUIDPRE, CALAUI.AUIDINF, '
            ."CALAUI.EMA2DEL, CALAUI.EMA2COD FROM CALAUI WHERE ((CALAUI.AUICEST = 'P' AND CALAUI.AUIDPRE < ".$this->bind($this->limit, $b).') '
            ."OR CALAUI.AUICEST = 'I')".$this->delegationSql('CALAUI.DEL3COD', $b).' ORDER BY CALAUI.AUIDPRE';

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            $state = (string) $row->AUICEST;
            if ($state === 'I' && ($open === null
                || isset($open[(string) $row->DEL3COD."\x1B".(string) $row->AUI1SER."\x1B".(int) $row->AUI1COD]))) {
                continue;
            }
            $mine = $this->isMyEmployee($row->EMA2DEL, $row->EMA2COD);
            if (! $mine && ! ($manager && (int) ($row->EMA2COD ?? 0) === 0)) {
                continue;
            }
            $code = $this->code('CALAUI', $row->AUI1COD, $row->DEL3COD, $row->AUI1SER);
            if ($state === 'P') {
                $this->add('AR', 'CALAUI', (string) $row->DEL3COD, (string) $row->AUI1SER, (int) $row->AUI1COD, $code,
                    (string) $row->AUICDES, $row->AUIDPRE, true, ! $mine);
            } else {
                $this->add('AC', 'CALAUI', (string) $row->DEL3COD, (string) $row->AUI1SER, (int) $row->AUI1COD, $code,
                    (string) $row->AUICDES, $row->AUIDINF, false, ! $mine);
            }
        }
    }

    // ------------------------------------------------------------------
    // Ensayos de aptitud
    // ------------------------------------------------------------------

    /**
     * Enviar resultados (pendiente de enviar con la fecha límite próxima,
     * pasada o sin ella), evaluar (enviada con el informe del organizador) y
     * cerrar (evaluada): quien registró la ronda o, si ya no es un usuario
     * activo, quien puede modificar rondas; cerrar, quien revisa la
     * conclusión si lo hay.
     */
    private function readProficiency(): void
    {
        $write = $this->has('CAL_EAP', self::WRITE);
        $b = [];
        $sql = 'SELECT CALEAP.DEL3COD, CALEAP.EAP1SER, CALEAP.EAP1COD, CALEAP.EAPCEST, CALEAP.EAPCPRG, CALEAP.EAPCRON, CALEAP.EAPCDES, '
            .'CALEAP.EAPDLIM, CALEAP.EAPDINF, CALEAP.EAPTREG, CALEAP.EMR2DEL, CALEAP.EMR2COD, CALEAP.USU2DEL, CALEAP.USU2COD, '
            .'(SELECT COUNT(*) FROM ACCUSU WHERE ACCUSU.DEL3COD = CALEAP.USU2DEL AND ACCUSU.USU1COD = CALEAP.USU2COD AND '
            ."(ACCUSU.USUBBAJ IS NULL OR ACCUSU.USUBBAJ <> 'T')) AS ACTIVO FROM CALEAP WHERE ((CALEAP.EAPCEST = 'P' AND (CALEAP.EAPDLIM IS NULL OR "
            .'CALEAP.EAPDLIM < '.$this->bind($this->limit, $b).")) OR (CALEAP.EAPCEST = 'E' AND CALEAP.EAPDINF IS NOT NULL) OR CALEAP.EAPCEST = 'V')"
            .$this->delegationSql('CALEAP.DEL3COD', $b);

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            $text = trim((string) $row->EAPCPRG);
            if (trim((string) $row->EAPCRON) !== '') {
                $text = trim($text.' '.$row->EAPCRON);
            }
            if (trim((string) $row->EAPCDES) !== '') {
                $text .= ($text !== '' ? ' - ' : '').$row->EAPCDES;
            }
            [$del, $ser, $cod] = [(string) $row->DEL3COD, (string) $row->EAP1SER, (int) $row->EAP1COD];
            $code = $this->code('CALEAP', $row->EAP1COD, $row->DEL3COD, $row->EAP1SER);
            $state = (string) $row->EAPCEST;
            $evaluated = self::day($row->EAPDINF) !== null ? $row->EAPDINF : $row->EAPTREG;

            if ($state === 'V' && (int) ($row->EMR2COD ?? 0) !== 0) {
                // Cerrar con la conclusión revisada: quien la revisa
                if ($this->isMyEmployee($row->EMR2DEL, $row->EMR2COD)) {
                    $this->add('EC', 'CALEAP', $del, $ser, $cod, $code, $text, $evaluated, false, false);
                }
                continue;
            }
            $mine = $this->isMyUser($row->USU2DEL, $row->USU2COD);
            $orphan = (int) $row->ACTIVO === 0;
            if (! $mine && ! ($orphan && $write)) {
                continue;
            }
            switch ($state) {
                case 'P':
                    $limit = self::day($row->EAPDLIM) !== null;
                    $this->add('EE', 'CALEAP', $del, $ser, $cod, $code, $text, $limit ? $row->EAPDLIM : $row->EAPTREG, $limit, ! $mine);
                    break;
                case 'E':
                    $this->add('EV', 'CALEAP', $del, $ser, $cod, $code, $text, $row->EAPDINF, false, ! $mine);
                    break;
                case 'V':
                    $this->add('EC', 'CALEAP', $del, $ser, $cod, $code, $text, $evaluated, false, ! $mine);
                    break;
            }
        }
    }

    // ------------------------------------------------------------------
    // Revisión por la dirección
    // ------------------------------------------------------------------

    /**
     * Completar (borrador o celebrada sin aprobar) y convocar la anual
     * (ninguna en borrador y la última celebrada o aprobada, por la fecha de
     * la reunión, tiene casi un año, o no hay ninguna; 13-nov-2026):
     * responsables de calidad (especial 1 de CAL_RDI).
     */
    private function readReviews(): void
    {
        if (! $this->has(VeolabManagementReview::FUNCTIONALITY, self::SPECIAL1)) {
            return;
        }
        $db = DB::connection('dynamic');
        $b = [];
        $sql = 'SELECT CALRDI.DEL3COD, CALRDI.RDI1SER, CALRDI.RDI1COD, CALRDI.RDICEST, CALRDI.RDICDES, CALRDI.RDIDFEC, CALRDI.RDITREG '
            ."FROM CALRDI WHERE CALRDI.RDICEST IN ('B', 'C')".$this->delegationSql('CALRDI.DEL3COD', $b);
        $inProgress = 0;
        foreach ($db->select($sql, $b) as $row) {
            $state = (string) $row->RDICEST;
            if ($state === 'B') {
                $inProgress++;   // solo una en borrador suprime la de convocar
            }
            $meeting = self::day($row->RDIDFEC) !== null;
            $this->add('RD', 'CALRDI', (string) $row->DEL3COD, (string) $row->RDI1SER, (int) $row->RDI1COD,
                $this->code('CALRDI', $row->RDI1COD, $row->DEL3COD, $row->RDI1SER),
                (VeolabManagementReview::STATE_NAMES[$state] ?? '').': '.$row->RDICDES,
                $meeting ? $row->RDIDFEC : $row->RDITREG, $state === 'B' && $meeting, true);
        }
        if ($inProgress > 0) {
            return;
        }

        $b = [];
        $last = $db->selectOne("SELECT MAX(CALRDI.RDIDFEC) AS ULTIMA FROM CALRDI WHERE CALRDI.RDICEST IN ('C', 'P')"
            .$this->delegationSql('CALRDI.DEL3COD', $b), $b);
        $last = self::day($last->ULTIMA ?? null);
        if ($last !== null) {
            $next = $this->addMonths($last, self::REVIEW_MONTHS);
            if ($next <= $this->addDays($this->today, self::DAYS_AHEAD)) {
                $this->add('RA', 'CALRDI', null, null, null, '', self::REVIEWS_TEXT, $next, true, true);
            }
        } else {
            $this->add('RA', 'CALRDI', null, null, null, '', self::REVIEWS_TEXT, null, false, true);
        }
    }

    // ------------------------------------------------------------------
    // Objetivos de calidad
    // ------------------------------------------------------------------

    /**
     * LeerObjetivos (responsables de calidad, especial 1 de CAL_OBJ, con
     * Calidad): definir los del año actual (desde el 1 de febrero, si no hay
     * ninguno; fecha 1 de febrero, sin vencer) y cerrar los de años anteriores
     * (una tarea por año con alguno de estado distinto de C; fecha 1 de enero
     * del año siguiente, sin vencer). En el código va el año. Delegación: la
     * del usuario y las comunes. Sin la tabla (base sin actualizar), nada.
     */
    private function readObjectives(): void
    {
        if (! VeolabQualityIndicators::available()) {
            return;
        }
        $db = DB::connection('dynamic');
        $year = (int) substr($this->today, 0, 4);
        if ($this->today >= sprintf('%04d-02-01', $year)) {
            $b = [];
            $count = $db->selectOne('SELECT COUNT(*) AS N FROM CALOBJ WHERE CALOBJ.OBJNANI = '.$this->bind($year, $b)
                .$this->delegationSql('CALOBJ.DEL3COD', $b), $b);
            if ((int) ($count->N ?? 0) === 0) {
                $this->add('OB', 'CALOBJ', '', '', $year, (string) $year, str_replace('%1', (string) $year, self::OBJECTIVES_UNDEFINED_TEXT),
                    sprintf('%04d-02-01', $year), false, true);
            }
        }

        $b = [];
        $sql = "SELECT CALOBJ.OBJNANI, COUNT(*) AS NOBJ FROM CALOBJ WHERE (CALOBJ.OBJCEST IS NULL OR CALOBJ.OBJCEST <> 'C') "
            .'AND CALOBJ.OBJNANI < '.$this->bind($year, $b).$this->delegationSql('CALOBJ.DEL3COD', $b)
            .' GROUP BY CALOBJ.OBJNANI ORDER BY CALOBJ.OBJNANI';
        foreach ($db->select($sql, $b) as $row) {
            $previous = (int) $row->OBJNANI;
            if ($previous > 0) {
                $this->add('OA', 'CALOBJ', '', '', $previous, (string) $previous,
                    str_replace(['%1', '%2'], [(string) $previous, (string) (int) $row->NOBJ], self::OBJECTIVES_OPEN_TEXT),
                    sprintf('%04d-01-01', $previous + 1), false, true);
            }
        }
    }

    // ------------------------------------------------------------------
    // Proveedores y personal
    // ------------------------------------------------------------------

    /**
     * Evaluar proveedor (próxima evaluación próxima o pasada): quien hizo la
     * última evaluación (como el aviso H) o, si no hay o ya no es un usuario
     * activo, los responsables de calidad.
     */
    private function readSuppliers(): void
    {
        $manager = $this->qualityManager();
        $b = [];
        $sql = 'SELECT SINPRO.DEL3COD, SINPRO.PRO1COD, SINPRO.PROCNOM, SINPRO.PRODPEV, e.USU2DEL, e.USU2COD, '
            .'(SELECT COUNT(*) FROM ACCUSU WHERE ACCUSU.DEL3COD = e.USU2DEL AND ACCUSU.USU1COD = e.USU2COD AND (ACCUSU.USUBBAJ IS NULL OR '
            ."ACCUSU.USUBBAJ <> 'T')) AS ACTIVO FROM SINPRO LEFT JOIN SINEVA e ON (e.PRO3DEL = SINPRO.DEL3COD AND e.PRO3COD = SINPRO.PRO1COD AND "
            .'e.EVA1COD = (SELECT MAX(x.EVA1COD) FROM SINEVA x WHERE x.PRO3DEL = SINPRO.DEL3COD AND x.PRO3COD = SINPRO.PRO1COD)) '
            .'WHERE SINPRO.PRODPEV < '.$this->bind($this->limit, $b)." AND (SINPRO.PROBBAJ IS NULL OR SINPRO.PROBBAJ <> 'T')"
            .$this->delegationSql('SINPRO.DEL3COD', $b);

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            $mine = $this->isMyUser($row->USU2DEL, $row->USU2COD);
            if ($mine || ($manager && (int) $row->ACTIVO === 0)) {
                $this->add('PE', 'SINPRO', (string) $row->DEL3COD, null, (string) $row->PRO1COD, (string) $row->PRO1COD,
                    (string) $row->PROCNOM, $row->PRODPEV, true, ! $mine);
            }
        }
    }

    /** Renovar autorización (autorizado con la caducidad próxima o pasada): el empleado autorizado y quien autoriza. */
    private function readAuthorizations(): void
    {
        if ($this->employee === null) {
            return;
        }
        $b = [];
        $sql = 'SELECT GRHAUT.EMP3DEL, GRHAUT.EMP3COD, GRHAUT.TEC3COD, GRHAUT.AUTDCAD, GRHEMP.EMPCNOM, LABTEC.TECCNOM FROM (GRHAUT '
            .'INNER JOIN GRHEMP ON (GRHEMP.DEL3COD = GRHAUT.EMP3DEL AND GRHEMP.EMP1COD = GRHAUT.EMP3COD)) LEFT JOIN LABTEC ON (LABTEC.DEL3COD = '
            ."GRHAUT.TEC3DEL AND LABTEC.TEC1COD = GRHAUT.TEC3COD) WHERE GRHAUT.AUTCEST = 'A' AND GRHAUT.AUTDCAD < ".$this->bind($this->limit, $b).' AND '
            ."(GRHEMP.EMPBBAJ IS NULL OR GRHEMP.EMPBBAJ <> 'T') AND (".$this->myEmployeeSql('GRHAUT.EMP3DEL', 'GRHAUT.EMP3COD', $b).' OR '
            .$this->myEmployeeSql('GRHAUT.EMA2DEL', 'GRHAUT.EMA2COD', $b).')'.$this->delegationSql('GRHAUT.EMP3DEL', $b).' ORDER BY GRHAUT.AUTDCAD';

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            $technique = trim((string) $row->TECCNOM) !== '' ? $row->TECCNOM.' ('.$row->TEC3COD.')' : (string) $row->TEC3COD;
            $this->add('AU', 'GRHEMP', (string) $row->EMP3DEL, null, (int) $row->EMP3COD, (string) $row->EMPCNOM,
                $technique, $row->AUTDCAD, true, false);
        }
    }

    // ------------------------------------------------------------------
    // Documentos controlados
    // ------------------------------------------------------------------

    /**
     * Documentos controlados de verdad (VeolabDocumentControl::whereControlled:
     * controlado efectivo, sin accesos directos) fuera de la papelera. La API
     * no aplica el acceso a carpetas (DCT_CarpetasVisibles del escritorio).
     */
    private function controlledDocuments()
    {
        $query = DB::connection('dynamic')->table('DOCFAT')
            ->where('DOCFAT.DIR2COD', '>', 0)->where('DOCFAT.DIR2COD', '<>', 4);
        VeolabDocumentControl::whereControlled($query, true, 'DOCFAT');

        return $query;
    }

    /**
     * Documentos controlados (con la fase 2 del control, 14-nov-2026):
     *  - DE revisar edición: la edición en curso (principal sin aprobar) de
     *    un documento cuya carpeta exige revisión antes de aprobar y que no
     *    está revisada (VERCEST <> R): el revisor del documento (personal) o
     *    quien tiene el especial 2 de DOC_CTL (cargo); nunca quien lo elabora.
     *  - DA aprobar edición (borrador o vigente con la principal sin
     *    aprobar: quien puede aprobar); con revisión antes de aprobar, solo
     *    cuando la edición ya está revisada.
     *  - DR revisar (vigente con la revisión periódica próxima o pasada:
     *    quien lo elabora o, sin él, quien puede aprobar).
     *  - DL leer la edición vigente: con destinatarios, la que tiene
     *    pendiente el empleado del usuario como destinatario, con la fecha
     *    límite (aprobación + días para leer) y vencida en rojo; sin
     *    destinatarios, como en la fase 1 (leyó una edición anterior y no la
     *    vigente).
     *  - DC retirar copias: documentos con copias controladas de una edición
     *    anterior a la vigente sin retirar (especial 1 de DOC_CTL).
     * Sin acceso a la lista de documentos controlados ($list = false) solo
     * salen las lecturas (DL), como en el escritorio.
     */
    private function readDocuments(bool $list = true): void
    {
        $approver = $list && $this->has(VeolabDocumentControl::FUNCTIONALITY, self::SPECIAL1);   // DCT_PuedeAprobar
        $reviewer = $list && $this->has(VeolabDocumentControl::FUNCTIONALITY, self::SPECIAL2);   // DCT_PuedeRevisar
        $distribution = VeolabDocumentControl::distributionAvailable();
        $limit = $this->limit;
        $reviewLimit = $this->addDays($this->today, self::DAYS_AHEAD);

        $query = $this->controlledDocuments()
            ->leftJoin('DOCVER AS v', function ($join) {
                $join->on('v.DEL3COD', '=', 'DOCFAT.DEL3COD')->on('v.FAT3COD', '=', 'DOCFAT.FAT1COD')->on('v.VER1COD', '=', 'DOCFAT.VER2COD');
            })
            ->where(function ($w) use ($limit) {
                $w->where('DOCFAT.FATCEST', 'B')->orWhere(function ($v) use ($limit) {
                    $v->where('DOCFAT.FATCEST', 'V')->where(function ($x) use ($limit) {
                        $x->whereColumn('DOCFAT.VER2APR', '<>', 'DOCFAT.VER2COD')->orWhere('DOCFAT.FATDREV', '<', $limit);
                    });
                });
            });
        $this->whereDelegation($query, 'DOCFAT.DEL3COD');
        $columns = ['DOCFAT.DEL3COD', 'DOCFAT.FAT1COD', 'DOCFAT.FATCNOM', 'DOCFAT.FATCCOD', 'DOCFAT.FATCEST', 'DOCFAT.VER2COD',
            'DOCFAT.VER2APR', 'DOCFAT.FATDREV', 'DOCFAT.EME2DEL', 'DOCFAT.EME2COD', 'DOCFAT.FATCCTL', 'DOCFAT.DIR2DEL',
            'DOCFAT.DIR2COD', 'v.VERTEMI', 'v.VERDAPR', 'v.VERCEDI', 'v.USU2DEL', 'v.USU2COD'];
        if ($distribution) {
            array_push($columns, 'DOCFAT.EMR2DEL', 'DOCFAT.EMR2COD', 'v.VERCEST');
        }
        $rows = $list ? $query->get($columns) : [];   // sin la lista (DOC_CTL), solo las lecturas

        foreach ($rows as $row) {
            $code = trim((string) $row->FATCCOD);
            $inProgress = (string) $row->FATCEST === 'B' || (int) $row->VER2APR !== (int) $row->VER2COD;
            // Paso de la edición en curso: revisar (carpeta con revisión y sin revisar) o aprobar
            $step = 'aprobar';
            if ($inProgress && VeolabDocumentControl::requiresReview($row)
                && VeolabDocumentControl::editionState((object) ['VERCEST' => $row->VERCEST ?? null, 'VERDAPR' => $row->VERDAPR]) !== 'R') {
                $step = 'revisar';
            }
            // Revisar la edición: su revisor o el especial 2; nunca quien lo elabora ni quien subió la edición
            if ($inProgress && $step === 'revisar' && ! $this->isMyEmployee($row->EME2DEL, $row->EME2COD)
                && ! $this->isMyUser($row->USU2DEL, $row->USU2COD)) {
                $mine = (int) ($row->EMR2COD ?? 0) !== 0 && $this->isMyEmployee($row->EMR2DEL, $row->EMR2COD);
                if ($mine || $reviewer) {
                    $this->add('DE', 'DOCFAT', (string) $row->DEL3COD, null, (int) $row->FAT1COD, $code,
                        $this->editionText($row->FATCNOM, $row->VERCEDI, $row->VER2COD), $row->VERTEMI, false, ! $mine);
                }
            }
            // Aprobar: borrador o pendiente (la principal no es la aprobada), ya revisada si hace falta
            if ($approver && $inProgress && $step === 'aprobar') {
                $this->add('DA', 'DOCFAT', (string) $row->DEL3COD, null, (int) $row->FAT1COD, $code, (string) $row->FATCNOM,
                    $row->VERTEMI, false, true);
            }
            // Revisar: vigente con la revisión próxima o pasada
            $review = self::day($row->FATDREV);
            if ((string) $row->FATCEST === 'V' && $review !== null && $review <= $reviewLimit) {
                $mine = $this->isMyEmployee($row->EME2DEL, $row->EME2COD);
                if ($mine || ($approver && (int) ($row->EME2COD ?? 0) === 0)) {
                    $this->add('DR', 'DOCFAT', (string) $row->DEL3COD, null, (int) $row->FAT1COD, $code, (string) $row->FATCNOM,
                        $row->FATDREV, true, ! $mine);
                }
            }
        }

        // Retirar copias: las de una edición anterior a la vigente sin fecha de retirada
        if ($distribution && $approver) {
            $query = $this->controlledDocuments()
                ->join('DOCCOP AS c', function ($join) {
                    $join->on('c.DEL3COD', '=', 'DOCFAT.DEL3COD')->on('c.FAT3COD', '=', 'DOCFAT.FAT1COD');
                })
                ->leftJoin('DOCVER AS v', function ($join) {
                    $join->on('v.DEL3COD', '=', 'DOCFAT.DEL3COD')->on('v.FAT3COD', '=', 'DOCFAT.FAT1COD')->on('v.VER1COD', '=', 'DOCFAT.VER2APR');
                })
                ->whereRaw(VeolabDocumentControl::toWithdrawSql('c', 'DOCFAT'));
            $this->whereDelegation($query, 'DOCFAT.DEL3COD');
            $rows = $query->groupBy('DOCFAT.DEL3COD', 'DOCFAT.FAT1COD', 'DOCFAT.FATCNOM', 'DOCFAT.FATCCOD', 'v.VERDAPR')
                ->get(['DOCFAT.DEL3COD', 'DOCFAT.FAT1COD', 'DOCFAT.FATCNOM', 'DOCFAT.FATCCOD', 'v.VERDAPR', DB::raw('COUNT(*) AS NCOP')]);
            foreach ($rows as $row) {
                $count = (int) $row->NCOP;
                $this->add('DC', 'DOCFAT', (string) $row->DEL3COD, null, (int) $row->FAT1COD, trim((string) $row->FATCCOD),
                    (string) $row->FATCNOM.' ('.str_replace('%1', (string) $count, $count === 1 ? self::COPY_TEXT : self::COPIES_TEXT).')',
                    $row->VERDAPR, false, true);
            }
        }

        // Leer la edición vigente
        if ($this->employee === null) {
            return;
        }
        [$empDel, $empCod] = $this->employee;
        $handled = [];   // documentos con destinatarios (la regla de la fase 2)
        if ($distribution) {
            $query = VeolabDocumentControl::inForceDocuments()->where('DOCFAT.DIR2COD', '<>', 4);
            $this->whereDelegation($query, 'DOCFAT.DEL3COD');
            $documents = $query->get();
            foreach ($documents as $document) {
                if (VeolabDocumentControl::documentRecipientLines($document)['lineas']) {
                    $handled[VeolabDocumentControl::documentKey($document->DEL3COD, $document->FAT1COD)] = true;
                }
            }
            foreach (VeolabDocumentControl::readingStatus($documents, $this->employee) as $status) {
                if (! $status['pendientes']) {
                    continue;
                }
                $document = $status['documento'];
                $this->add('DL', 'DOCFAT', (string) $document->DEL3COD, null, (int) $document->FAT1COD, trim((string) $document->FATCCOD),
                    $this->editionText($document->FATCNOM, $status['edicion'], $status['version']),
                    $status['fecha_limite'] ?? $status['fecha_aprobacion'], $status['fecha_limite'] !== null, false);
            }
        }

        // Sin destinatarios (fase 1): su empleado leyó una edición anterior y no la aprobada vigente, salvo que la que
        // leyó siga valiendo (las ediciones posteriores no requirieron nueva lectura)
        $query = $this->controlledDocuments()
            ->leftJoin('DOCVER AS v', function ($join) {
                $join->on('v.DEL3COD', '=', 'DOCFAT.DEL3COD')->on('v.FAT3COD', '=', 'DOCFAT.FAT1COD')->on('v.VER1COD', '=', 'DOCFAT.VER2APR');
            })
            ->where('DOCFAT.FATCEST', 'V')->where('DOCFAT.VER2APR', '>', 0)
            ->whereRaw('EXISTS (SELECT 1 FROM DOCLEC l WHERE l.DEL3COD = DOCFAT.DEL3COD AND l.FAT3COD = DOCFAT.FAT1COD '
                .'AND l.EMP3DEL = ? AND l.EMP3COD = ?)', [$empDel, $empCod])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM DOCLEC l WHERE l.DEL3COD = DOCFAT.DEL3COD AND l.FAT3COD = DOCFAT.FAT1COD '
                .'AND l.VER3COD = DOCFAT.VER2APR AND l.EMP3DEL = ? AND l.EMP3COD = ?)', [$empDel, $empCod]);
        $this->whereDelegation($query, 'DOCFAT.DEL3COD');
        $rows = $query->get(['DOCFAT.DEL3COD', 'DOCFAT.FAT1COD', 'DOCFAT.FATCNOM', 'DOCFAT.FATCCOD', 'DOCFAT.VER2APR', 'v.VERDAPR',
            'v.VERTEMI', 'v.VERCEDI']);

        foreach ($rows as $row) {
            if (isset($handled[VeolabDocumentControl::documentKey($row->DEL3COD, $row->FAT1COD)])) {
                continue;
            }
            if ($distribution && $this->earlierReadingValid((string) $row->DEL3COD, (int) $row->FAT1COD, (int) $row->VER2APR)) {
                continue;
            }
            $this->add('DL', 'DOCFAT', (string) $row->DEL3COD, null, (int) $row->FAT1COD, trim((string) $row->FATCCOD),
                $this->editionText($row->FATCNOM, $row->VERCEDI, $row->VER2APR),
                self::day($row->VERDAPR) !== null ? $row->VERDAPR : $row->VERTEMI, false, false);
        }
    }

    /** TextoEdicion: «Nombre (edición Rev. 3)»; sin edición, con el nombre automático de la versión. */
    private function editionText($name, $edition, $version): string
    {
        $edition = trim((string) ($edition ?? ''));
        if ($edition === '') {
            $edition = str_replace('%1', (string) (int) $version, self::VERSION_TEXT);
        }

        return str_replace('%2', $edition, str_replace('%1', (string) $name, self::EDITION_TEXT));
    }

    /**
     * LecturaAnteriorValida: ¿tiene el empleado del usuario una lectura que
     * vale para la edición vigente? Vale la de cualquier edición desde la
     * última aprobada que requirió nueva lectura (DOCVER.VERBLEC distinto de
     * F; DCT_DesdeLectura), como en VeolabDocumentControl::readingStatus().
     * Se comprueba fila a fila: son pocas (las que no tienen lectura de la
     * vigente).
     */
    private function earlierReadingValid(string $del, int $cod, int $current): bool
    {
        $db = DB::connection('dynamic');
        $from = (int) ($db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', '<=', $current)
            ->whereNotNull('VERDAPR')
            ->where(fn ($q) => $q->whereNull('VERBLEC')->orWhere('VERBLEC', '<>', 'F'))
            ->max('VER1COD') ?? 0);
        if ($from >= $current) {
            return false;
        }

        return $db->table('DOCLEC')->where('DEL3COD', $del)->where('FAT3COD', $cod)
            ->where('VER3COD', '>=', $from)->where('VER3COD', '<=', $current)
            ->where('EMP3DEL', (string) $this->employee[0])->where('EMP3COD', (int) $this->employee[1])->exists();
    }

    // ------------------------------------------------------------------
    // Informes y solicitudes
    // ------------------------------------------------------------------

    /**
     * Firmar informe: los del tipo de firma del perfil del usuario (como el
     * aviso F), sin validar, enviar, rechazar ni sustituir, con las firmas
     * anteriores en el orden (TIFNORD) ya puestas y con alguna operación sin
     * archivar ni anular.
     */
    private function readSignatures(): void
    {
        $db = DB::connection('dynamic');
        $signature = $db->selectOne('SELECT ACCPER.TIF2DEL, ACCPER.TIF2COD, LABTIF.TIFNORD, LABTIF.TIFBBAJ FROM (ACCUSU INNER JOIN ACCPER ON '
            .'(ACCPER.DEL3COD = ACCUSU.PER2DEL AND ACCPER.PER1COD = ACCUSU.PER2COD)) INNER JOIN LABTIF ON (LABTIF.DEL3COD = ACCPER.TIF2DEL '
            .'AND LABTIF.TIF1COD = ACCPER.TIF2COD) WHERE ACCUSU.DEL3COD = ? AND ACCUSU.USU1COD = ?', [$this->userDel, $this->userCod]);
        if (! $signature || (string) $signature->TIFBBAJ === 'T' || (int) $signature->TIF2COD === 0) {
            return;
        }

        $b = [];
        $sql = 'SELECT LABINF.DEL3COD, LABINF.INF1SER, LABINF.INF1COD, LABINF.INFDCRE, (SELECT MIN(SINCLI.CLICNOM) FROM (LABIYO INNER JOIN '
            .'LABOPE ON (LABOPE.DEL3COD = LABIYO.OPE3DEL AND LABOPE.OPE1SER = LABIYO.OPE3SER AND LABOPE.OPE1COD = LABIYO.OPE3COD)) INNER JOIN SINCLI ON '
            .'(SINCLI.DEL3COD = LABOPE.CLI2DEL AND SINCLI.CLI1COD = LABOPE.CLI2COD) WHERE LABIYO.INF3DEL = LABINF.DEL3COD AND LABIYO.INF3SER = '
            .'LABINF.INF1SER AND LABIYO.INF3COD = LABINF.INF1COD) AS CLIENTE FROM LABINF WHERE LABINF.INFDVAL IS NULL AND LABINF.INFDENV IS NULL '
            ."AND (LABINF.INFCVAL IS NULL OR LABINF.INFCVAL <> 'R') AND (LABINF.INFBSUS IS NULL OR LABINF.INFBSUS <> 'T') "
            .'AND NOT EXISTS (SELECT 1 FROM LABFIR f WHERE f.INF3DEL = LABINF.DEL3COD AND f.INF3SER = LABINF.INF1SER AND f.INF3COD = LABINF.INF1COD '
            .'AND f.TIF3DEL = '.$this->bind((string) $signature->TIF2DEL, $b).' AND f.TIF3COD = '.$this->bind((int) $signature->TIF2COD, $b).') '
            ."AND NOT EXISTS (SELECT 1 FROM LABTIF p WHERE (p.TIFBBAJ IS NULL OR p.TIFBBAJ <> 'T') AND p.TIFNORD < ".$this->bind((int) $signature->TIFNORD, $b)
            .' AND NOT EXISTS (SELECT 1 FROM LABFIR f WHERE f.INF3DEL = LABINF.DEL3COD AND f.INF3SER = LABINF.INF1SER AND f.INF3COD = LABINF.INF1COD AND '
            .'f.TIF3DEL = p.DEL3COD AND f.TIF3COD = p.TIF1COD)) '
            .'AND EXISTS (SELECT 1 FROM LABIYO y INNER JOIN LABOPE o ON (o.DEL3COD = y.OPE3DEL AND o.OPE1SER = y.OPE3SER AND o.OPE1COD = y.OPE3COD) '
            ."WHERE y.INF3DEL = LABINF.DEL3COD AND y.INF3SER = LABINF.INF1SER AND y.INF3COD = LABINF.INF1COD AND (y.IYOBHIS IS NULL OR y.IYOBHIS <> 'T') "
            ."AND o.OPENEST <> 7 AND (o.OPEBANU IS NULL OR o.OPEBANU <> 'T'))".$this->delegationSql('LABINF.DEL3COD', $b).' ORDER BY LABINF.INFDCRE';

        foreach ($db->select($sql, $b) as $row) {
            $this->add('FI', 'LABINF', (string) $row->DEL3COD, (string) $row->INF1SER, (int) $row->INF1COD,
                $this->code('LABINF', $row->INF1COD, $row->DEL3COD, $row->INF1SER), (string) $row->CLIENTE, $row->INFDCRE, false, false);
        }
    }

    /** Decidir las solicitudes de clientes pendientes: quien puede decidirlas (escritura en solicitudes, como el aviso T). */
    private function readRequests(): void
    {
        $b = [];
        $sql = 'SELECT LABSOL.DEL3COD, LABSOL.SOL1SER, LABSOL.SOL1COD, LABSOL.SOLCDES, LABSOL.SOLTFEC, SINCLI.CLICNOM FROM LABSOL '
            ."LEFT JOIN SINCLI ON (LABSOL.CLI2DEL = SINCLI.DEL3COD AND LABSOL.CLI2COD = SINCLI.CLI1COD) WHERE LABSOL.SOLCEST = 'P'"
            .$this->delegationSql('LABSOL.DEL3COD', $b).' ORDER BY LABSOL.SOLTFEC';

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            $client = trim((string) $row->CLICNOM) !== '' ? $row->CLICNOM.': ' : '';
            $this->add('SO', 'LABSOL', (string) $row->DEL3COD, (string) $row->SOL1SER, (int) $row->SOL1COD,
                $this->code('LABSOL', $row->SOL1COD, $row->DEL3COD, $row->SOL1SER), $client.$row->SOLCDES, $row->SOLTFEC, false, true);
        }
    }

    // ------------------------------------------------------------------
    // Operaciones y equipos (fase 2)
    // ------------------------------------------------------------------

    /**
     * Fecha de compromiso de las operaciones sin enviar, archivar ni anular
     * que vence dentro de WARN_DAYS días o ya pasó: para los empleados de la
     * operación (LABOYE, como el aviso C); las que no tienen ningún empleado,
     * para quien graba resultados (escritura en LAB_RES). Quien no tiene
     * empleado (p. ej. el administrador) no las ve: eran miles de tareas que
     * repetían el listado de Operaciones (20-nov-2026, como el escritorio).
     */
    private function readCommitments(): void
    {
        $results = $this->has('LAB_RES', self::WRITE);
        if ($this->employee === null) {
            return;
        }
        $b = [];
        $sql = 'SELECT LABOPE.DEL3COD, LABOPE.OPE1SER, LABOPE.OPE1COD, LABOPE.OPEDCOM, LABOPE.OPECREF, SINCLI.CLICNOM, '
            .'(SELECT COUNT(*) FROM LABOYE y WHERE y.OPE3DEL = LABOPE.DEL3COD AND y.OPE3SER = LABOPE.OPE1SER AND y.OPE3COD = LABOPE.OPE1COD AND '
            .$this->myEmployeeSql('y.EMP3DEL', 'y.EMP3COD', $b).') AS MIA FROM LABOPE LEFT JOIN SINCLI ON (SINCLI.DEL3COD = LABOPE.CLI2DEL AND '
            .'SINCLI.CLI1COD = LABOPE.CLI2COD) WHERE LABOPE.OPEDCOM < '.$this->bind($this->addDays($this->today, self::WARN_DAYS + 1), $b)
            ." AND LABOPE.OPEDENV IS NULL AND LABOPE.OPENEST < 6 AND (LABOPE.OPEBANU IS NULL OR LABOPE.OPEBANU <> 'T') AND ("
            .'EXISTS (SELECT 1 FROM LABOYE y WHERE y.OPE3DEL = LABOPE.DEL3COD AND y.OPE3SER = LABOPE.OPE1SER AND y.OPE3COD = LABOPE.OPE1COD AND '
            .$this->myEmployeeSql('y.EMP3DEL', 'y.EMP3COD', $b).')'
            .($results ? ' OR NOT EXISTS (SELECT 1 FROM LABOYE z WHERE z.OPE3DEL = LABOPE.DEL3COD AND z.OPE3SER = LABOPE.OPE1SER AND '
                .'z.OPE3COD = LABOPE.OPE1COD)' : '')
            .')'.$this->delegationSql('LABOPE.DEL3COD', $b).' ORDER BY LABOPE.OPEDCOM';

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            $text = trim((string) $row->CLICNOM);
            if (trim((string) $row->OPECREF) !== '') {
                $text .= ($text !== '' ? ' - ' : '').trim((string) $row->OPECREF);
            }
            $this->add('OC', 'LABOPE', (string) $row->DEL3COD, (string) $row->OPE1SER, (int) $row->OPE1COD,
                $this->code('LABOPE', $row->OPE1COD, $row->DEL3COD, $row->OPE1SER), $text, $row->OPEDCOM, true, (int) $row->MIA === 0);
        }
    }

    /**
     * Calibrar, verificar y mantener las series de inventario (equipos) en
     * uso (producto sin baja y serie ni de baja ni fuera de uso) con la fecha
     * próxima o pasada: para quien puede modificar el inventario (los equipos
     * no tienen responsable). Tabla ALMSEL: serie = producto, código = serie;
     * con la serie oculta de un producto sin series, tabla ALMPRD y código =
     * producto (lo que el usuario ve, CAL_EnlaceInventario).
     */
    private function readEquipment(): void
    {
        $b = [];
        $limit = $this->limit;
        $sql = 'SELECT ALMSEL.PRD3DEL, ALMSEL.PRD3COD, ALMSEL.SEL1COD, ALMSEL.SELCDES, ALMSEL.SELDCAL, ALMSEL.SELDVER, ALMSEL.SELDMAN, '
            .'ALMPRD.PRDCDES FROM ALMSEL INNER JOIN ALMPRD ON (ALMPRD.DEL3COD = ALMSEL.PRD3DEL AND ALMPRD.PRD1COD = ALMSEL.PRD3COD) WHERE '
            ."(ALMPRD.PRDBBAJ IS NULL OR ALMPRD.PRDBBAJ <> 'T') AND (ALMSEL.SELCESA IS NULL OR ALMSEL.SELCESA NOT IN ('F', 'B')) "
            .'AND (ALMSEL.SELDCAL < '.$this->bind($limit, $b)
            .' OR ALMSEL.SELDVER < '.$this->bind($limit, $b).' OR ALMSEL.SELDMAN < '.$this->bind($limit, $b).')'
            .$this->delegationSql('ALMSEL.PRD3DEL', $b).' ORDER BY ALMSEL.PRD3COD, ALMSEL.SEL1COD';
        $last = $this->addDays($this->today, self::DAYS_AHEAD);

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            [$del, $product, $serial] = [(string) $row->PRD3DEL, (string) $row->PRD3COD, (string) $row->SEL1COD];
            // La serie oculta de los productos sin series no se muestra: el registro es el producto
            $record = $serial === self::SINGLE_SERIAL ? $product : $product.' / '.$serial;
            $text = trim((string) $row->PRDCDES);
            if (trim((string) $row->SELCDES) !== '') {
                $text .= ($text !== '' ? ' - ' : '').trim((string) $row->SELCDES);
            }
            foreach (['MC' => $row->SELDCAL, 'MV' => $row->SELDVER, 'MM' => $row->SELDMAN] as $type => $date) {
                $day = self::day($date);
                if ($day !== null && $day <= $last) {
                    $this->addInventory($type, $del, $product, $serial, $record, $text, $date);
                }
            }
        }
    }

    /** Tarea de una serie de inventario: la serie o, si es la oculta de un producto sin series, el producto. */
    private function addInventory(string $type, string $del, string $product, string $serial, string $record, string $text, $date): void
    {
        if ($serial === self::SINGLE_SERIAL || $serial === '') {
            $this->add($type, 'ALMPRD', $del, null, $product, $record, $text, $date, true, true);
        } else {
            $this->add($type, 'ALMSEL', $del, $product, $serial, $record, $text, $date, true, true);
        }
    }

    /**
     * Renovar patrón o material de referencia: series de productos con tipo
     * metrológico (ALMPRD.PRDCTPR), ni de baja ni fuera de uso, que siguen en
     * uso (equipos, o con existencias) y cuya caducidad efectiva (la menor
     * entre SELDCAD y SELDAPE + PRDNDAP días) está dentro de 30 días o
     * pasada; para quien puede modificar el inventario. La calibración y la
     * verificación vencidas ya salen en MC y MV. Tabla ALMSEL: serie =
     * producto, código = serie (con la serie oculta, ALMPRD y el producto,
     * como en los equipos); vence en la caducidad efectiva. La descripción es
     * la del escritorio: producto y descripción de la serie.
     */
    private function readReferenceMaterials(): void
    {
        $query = DB::connection('dynamic')->table('ALMSEL')
            ->join('ALMPRD', fn ($j) => $j->on('ALMPRD.DEL3COD', '=', 'ALMSEL.PRD3DEL')->on('ALMPRD.PRD1COD', '=', 'ALMSEL.PRD3COD'))
            ->where(fn ($q) => $q->whereNull('ALMSEL.SELCESA')->orWhereNotIn('ALMSEL.SELCESA', ['F', 'B']))
            ->whereNull('ALMSEL.SELDBAJ')
            ->where(fn ($q) => $q->whereNull('ALMPRD.PRDBBAJ')->orWhere('ALMPRD.PRDBBAJ', '<>', 'T'))
            ->where(fn ($q) => $q->where('ALMPRD.PRDBEQU', 'T')->orWhere('ALMSEL.SELNCAE', '>', 0));
        VeolabMetrology::whereMetrological($query);
        VeolabMetrology::whereExpiresBefore($query, $this->limit);
        $this->whereDelegation($query, 'ALMSEL.PRD3DEL');
        $rows = $query->orderBy('ALMSEL.PRD3COD')->orderBy('ALMSEL.SEL1COD')
            ->get(['ALMSEL.PRD3DEL', 'ALMSEL.PRD3COD', 'ALMSEL.SEL1COD', 'ALMSEL.SELCDES', 'ALMSEL.SELDCAD', 'ALMSEL.SELDAPE',
                'ALMPRD.PRDCDES', 'ALMPRD.PRDNDAP']);
        $last = $this->addDays($this->today, self::DAYS_AHEAD);

        foreach ($rows as $row) {
            $expiry = VeolabMetrology::effectiveExpiry($row->SELDCAD, $row->SELDAPE, $row->PRDNDAP);
            if ($expiry === null || $expiry > $last) {
                continue;
            }
            [$del, $product, $serial] = [(string) $row->PRD3DEL, (string) $row->PRD3COD, (string) $row->SEL1COD];
            $record = $serial === self::SINGLE_SERIAL ? $product : $product.' / '.$serial;
            $text = trim((string) $row->PRDCDES);
            if (trim((string) $row->SELCDES) !== '') {
                $text .= ($text !== '' ? ' - ' : '').trim((string) $row->SELCDES);
            }
            $this->addInventory('MP', $del, $product, $serial, $record, $text, $expiry);
        }
    }

    /**
     * Reponer stock: productos no de baja con las existencias (PRDNCAE) bajo
     * el stock mínimo (PRDNSMI > 0), para quien puede modificar el
     * inventario. Sin fecha. Descripción «producto (existencias / mínimo)».
     */
    private function readStock(): void
    {
        $b = [];
        $sql = 'SELECT ALMPRD.DEL3COD, ALMPRD.PRD1COD, ALMPRD.PRDCDES, ALMPRD.PRDNCAE, ALMPRD.PRDNSMI FROM ALMPRD WHERE ALMPRD.PRDNSMI > 0 '
            ."AND ALMPRD.PRDNCAE < ALMPRD.PRDNSMI AND (ALMPRD.PRDBBAJ IS NULL OR ALMPRD.PRDBBAJ <> 'T')"
            .$this->delegationSql('ALMPRD.DEL3COD', $b).' ORDER BY ALMPRD.PRD1COD';

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            // GEN_Decimal concatenado: CStr del número con el separador decimal del laboratorio
            $text = trim((string) $row->PRDCDES).' ('.VeolabFormat::cstr((float) $row->PRDNCAE).' / '
                .VeolabFormat::cstr((float) $row->PRDNSMI).')';
            $this->add('ST', 'ALMPRD', (string) $row->DEL3COD, null, (string) $row->PRD1COD, (string) $row->PRD1COD,
                $text, null, false, true);
        }
    }

    // ------------------------------------------------------------------
    // Ventas, gráficos de control y plan de intercomparaciones
    // ------------------------------------------------------------------

    /**
     * Seguimiento de presupuesto: pendientes o enviados (PRECEST P o E) con
     * la fecha de seguimiento (PREDSEG) dentro de WARN_DAYS días o pasada,
     * para su comercial (FACPRE.EMP2*, como el aviso P) o, si no tiene, para
     * quien puede modificar presupuestos (escritura en VEN_PRE).
     */
    private function readQuotations(): void
    {
        $write = $this->has('VEN_PRE', self::WRITE);
        if ($this->employee === null && ! $write) {
            return;
        }
        $b = [];
        $sql = 'SELECT FACPRE.DEL3COD, FACPRE.PRE1SER, FACPRE.PRE1COD, FACPRE.PRECDES, FACPRE.PREDSEG, FACPRE.EMP2DEL, FACPRE.EMP2COD, '
            .'SINCLI.CLICNOM FROM FACPRE LEFT JOIN SINCLI ON (SINCLI.DEL3COD = FACPRE.CLI2DEL AND SINCLI.CLI1COD = FACPRE.CLI2COD) WHERE '
            ."FACPRE.PRECEST IN ('P', 'E') AND FACPRE.PREDSEG < ".$this->bind($this->addDays($this->today, self::WARN_DAYS + 1), $b).' AND ('
            .$this->myEmployeeSql('FACPRE.EMP2DEL', 'FACPRE.EMP2COD', $b).($write ? ' OR FACPRE.EMP2COD IS NULL OR FACPRE.EMP2COD = 0' : '').')'
            .$this->delegationSql('FACPRE.DEL3COD', $b).' ORDER BY FACPRE.PREDSEG';

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            $text = trim((string) $row->CLICNOM);
            if (trim((string) $row->PRECDES) !== '') {
                $text .= ($text !== '' ? ': ' : '').trim((string) $row->PRECDES);
            }
            $this->add('PS', 'FACPRE', (string) $row->DEL3COD, (string) $row->PRE1SER, (int) $row->PRE1COD,
                $this->code('FACPRE', $row->PRE1COD, $row->DEL3COD, $row->PRE1SER), $text, $row->PREDSEG, true,
                ! $this->isMyEmployee($row->EMP2DEL, $row->EMP2COD));
        }
    }

    /**
     * Claves de los gráficos de control con una incidencia abierta enlazada
     * (CALIYE con IYECTAB = 'LABCDC' e incidencia en R/T/V), resueltas en PHP
     * porque CALIYE.IYECCOD es texto (Val del escritorio).
     */
    private function chartsWithOpenIncidents(): array
    {
        $keys = [];
        $rows = DB::connection('dynamic')->select('SELECT DISTINCT h.IYECDEL, h.IYECCOD FROM CALIYE h INNER JOIN CALINC i ON '
            .'(i.DEL3COD = h.DEL3COD AND i.INC1SER = h.INC3SER AND i.INC1COD = h.INC3COD) WHERE h.IYECTAB = \'LABCDC\' AND '
            ."i.INCCEST IN ('R', 'T', 'V')");
        foreach ($rows as $row) {
            $keys[(string) $row->IYECDEL."\x1B".(int) trim((string) $row->IYECCOD)] = true;
        }

        return $keys;
    }

    /**
     * Revisar gráfico de control: en error (CDCCEST = 'E') y sin cerrar
     * (CDCDCIE nula), para quien puede modificarlos; con Calidad, solo los que
     * no tienen una incidencia abierta enlazada. Desde su creación, sin
     * vencimiento.
     */
    private function readControlCharts(bool $quality): void
    {
        $withIncident = $quality ? $this->chartsWithOpenIncidents() : [];
        $b = [];
        $sql = "SELECT LABCDC.DEL3COD, LABCDC.CDC1COD, LABCDC.CDCCNOM, LABCDC.CDCDCRE FROM LABCDC WHERE LABCDC.CDCCEST = 'E' AND "
            .'LABCDC.CDCDCIE IS NULL'.$this->delegationSql('LABCDC.DEL3COD', $b).' ORDER BY LABCDC.CDCDCRE';

        foreach (DB::connection('dynamic')->select($sql, $b) as $row) {
            if (isset($withIncident[(string) $row->DEL3COD."\x1B".(int) $row->CDC1COD])) {
                continue;
            }
            $this->add('GC', 'LABCDC', (string) $row->DEL3COD, null, (int) $row->CDC1COD, (string) $row->CDC1COD,
                (string) $row->CDCCNOM, $row->CDCDCRE, false, true);
        }
    }

    /**
     * Participar en intercomparación: familias del plan (EAP_CalcularPlan,
     * VeolabProficiencyPlan::families con los años por defecto) no de baja a
     * las que les toca participar este año o que ya van tarde (sin
     * participación en su periodo), para quien modifica el plan. Vencen el 31
     * de diciembre del año de la próxima participación (o del actual).
     */
    private function readProficiencyPlan(): void
    {
        $current = (int) substr($this->today, 0, 4);
        [$from, $to] = VeolabProficiencyPlan::yearRange(null, null, $current);
        $families = VeolabProficiencyPlan::families($from, $to, false, $this->userDel !== '' ? $this->userDel : null, $current);

        foreach ($families as $family) {
            if ($family['baja'] === 'T' || ! in_array($family['situacion'], ['E', 'S'], true)) {
                continue;
            }
            $parameters = implode('; ', array_column($family['parametros'], 'parametro_nombre'));
            $program = (string) ($family['programa'] ?? '');
            $year = (int) ($family['proxima'] ?? 0) > 0 ? (int) $family['proxima'] : $current;
            $this->add('FA', 'CALFAE', $family['delegacion'], null, $family['codigo'], (string) $family['nombre'],
                ($program !== '' ? $program.': ' : '').$parameters, sprintf('%04d-12-31', $year), true, true);
        }
    }
}
