<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Ensayos de aptitud e intercomparaciones (módulo CAL, desde Veolab 3;
 * ISO/IEC 17025:2017 7.7.2), fase 1: rondas (CALEAP), sus operaciones o
 * muestras (CALEAO) y los resultados por parámetro (CALEAR) con la puntuación
 * y la evaluación del organizador y las que calcula Veolab (ISO 13528).
 * Réplica de Aptitud.bas (EAP_*) y de FichaEnsayoAptitud. De la fase 2, el
 * aviso J de la fecha límite de envío; el plan por familias y la tendencia
 * están en VeolabProficiencyPlan.
 */
class VeolabProficiency
{
    public const TABLE = 'CALEAP';

    /** Pendiente de enviar, enviada, evaluada y cerrada (EAP_ESTADOS). */
    public const STATES = ['P', 'E', 'V', 'C'];

    /** z, z', zeta, En, D % y cualitativo (EAP_TIPOS). */
    public const TYPES = ['Z', 'P', 'T', 'E', 'D', 'C'];

    /** Satisfactorio, cuestionable, insatisfactorio, correcto, falso positivo y falso negativo (EAP_EVALUACIONES). */
    public const EVALUATIONS = ['S', 'Q', 'I', 'C', 'P', 'N'];

    /** Evaluaciones que piden investigación y acción (EAP_EvaluacionMala). */
    public const BAD = ['I', 'P', 'N'];

    /** No satisfactorias (primer valor del filtro de evaluación del listado de resultados). */
    public const NOT_SATISFACTORY = ['Q', 'I', 'P', 'N'];

    public const STATE_NAMES = [
        'P' => 'Pendiente de enviar',
        'E' => 'Enviada',
        'V' => 'Evaluada',
        'C' => 'Cerrada',
    ];

    /** Límites de ISO 13528 (nulos en la ronda = estos). */
    public const LIMIT_SATISFACTORY = 2.0;
    public const LIMIT_UNSATISFACTORY = 3.0;
    public const LIMIT_EN = 1.0;

    /** Diferencia admitida entre la puntuación del organizador y la calculada (en D %, puntos porcentuales). */
    public const TOLERANCE = 0.1;
    public const TOLERANCE_D = 1.0;

    // ------------------------------------------------------------------
    // Puntuaciones (ISO 13528)
    // ------------------------------------------------------------------

    /**
     * EAP_Numero: número escrito con coma o punto decimal y sin separador de
     * miles. Null si está vacío o no es un número (p. ej. «<10»).
     */
    public static function number($value): ?float
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = str_replace(',', '.', trim((string) $value));
        if ($text === '' || substr_count($text, '.') > 1 || ! is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }

    /**
     * EAP_CalcularPuntuacion, con x el resultado enviado y X el valor asignado
     * (sus log10 si se indica, salvo en D %):
     *   z = (x - X) / s;  z' = (x - X) / raíz(s² + u(X)²);  zeta = (x - X) / raíz(u(x)² + u(X)²);
     *   En = (x - X) / raíz(U(x)² + U(X)²);  D % = 100 (x - X) / X.
     * Null si faltan datos o no son números (resultados con «<», cualitativos...).
     */
    public static function calculateScore(string $type, bool $log, $result, $assigned, $deviation, $uncAssigned, $uncLab): ?float
    {
        if ($type === '' || $type === 'C') {
            return null;
        }
        $x = self::number($result);
        $a = self::number($assigned);
        if ($x === null || $a === null) {
            return null;
        }
        if ($log && $type !== 'D') {
            if ($x <= 0 || $a <= 0) {
                return null;
            }
            $x = log10($x);
            $a = log10($a);
        }
        $s = self::number($deviation) ?? 0.0;
        $ua = self::number($uncAssigned) ?? 0.0;
        $ul = self::number($uncLab) ?? 0.0;

        switch ($type) {
            case 'Z':
                $den = $s;
                break;
            case 'P':
                $den = sqrt($s * $s + $ua * $ua);
                break;
            case 'T':
            case 'E':
                $den = sqrt($ul * $ul + $ua * $ua);
                break;
            case 'D':
                return $a == 0.0 ? null : 100.0 * ($x - $a) / $a;
            default:
                return null;
        }

        return $den > 0 ? ($x - $a) / $den : null;
    }

    /**
     * EAP_EvaluacionPuntuacion: z, z' y zeta satisfactorio hasta el primer
     * límite e insatisfactorio desde el segundo (cuestionable entre ambos); En
     * satisfactorio hasta su límite; D % satisfactorio si no pasa de la
     * desviación admitida (sin ella, sin evaluación). Se compara |puntuación|
     * redondeada a 2 decimales (Round de VB6: al par).
     */
    public static function scoreEvaluation(string $type, float $score, $deviation, float $limSat, float $limIns, float $limEn): string
    {
        $abs = abs(round($score, 2, PHP_ROUND_HALF_EVEN));
        switch ($type) {
            case 'Z':
            case 'P':
            case 'T':
                return $abs <= $limSat ? 'S' : ($abs < $limIns ? 'Q' : 'I');
            case 'E':
                return $abs <= $limEn ? 'S' : 'I';
            case 'D':
                $admitted = self::number($deviation) ?? 0.0;

                return $admitted > 0 ? ($abs <= $admitted ? 'S' : 'I') : '';
        }

        return '';
    }

    private static function qualitativeText($text): string
    {
        return str_replace(' ', '', mb_strtolower(trim((string) $text, ' ')));
    }

    /** EsNegativo: ¿resultado cualitativo negativo? (ausencia, no detectado, negativo, menor que...) */
    private static function isNegative($text): bool
    {
        $t = self::qualitativeText($text);
        if (str_starts_with($t, '<') || $t === 'nd' || $t === 'n.d.') {
            return true;
        }
        foreach (['ausen', 'absen', 'nodetect', 'notdetect', 'nosedetect', 'negativ'] as $word) {
            if (str_contains($t, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * EAP_EvaluacionCualitativa: correcto si coincide con el valor asignado (o
     * los dos son negativos); falso positivo si el asignado es negativo y el
     * resultado no; falso negativo al revés. Si no se puede decidir, ''.
     */
    public static function qualitativeEvaluation($result, $assigned): string
    {
        $r = self::qualitativeText($result);
        $a = self::qualitativeText($assigned);
        if ($r === '' || $a === '') {
            return '';
        }
        $negR = self::isNegative($result);
        $negA = self::isNegative($assigned);
        if ($r === $a || ($negR && $negA)) {
            return 'C';
        }
        if ($negA) {
            return 'P';
        }

        return $negR ? 'N' : '';
    }

    /**
     * EAP_EvaluarFila: puntuación de comprobación (redondeada a 2 decimales,
     * como la guarda la ficha), evaluación final (la del organizador o, si no
     * la da, la propuesta con su puntuación o, si tampoco la da, con la
     * calculada), si es propuesta y si la calculada no coincide con la del
     * organizador. $row lleva tipo_puntuacion, sobre_log10, resultado,
     * valor_asignado, desviacion, incertidumbre_asignado,
     * incertidumbre_laboratorio, puntuacion_organizador y evaluacion_organizador.
     * Devuelve [puntuacion_calculada ?float, evaluacion ?string, propuesta bool, difiere bool].
     */
    public static function evaluateRow(array $row, array $limits): array
    {
        $type = (string) ($row['tipo_puntuacion'] ?? '');
        $calc = self::calculateScore($type, ($row['sobre_log10'] ?? 'F') === 'T', $row['resultado'] ?? null, $row['valor_asignado'] ?? null,
            $row['desviacion'] ?? null, $row['incertidumbre_asignado'] ?? null, $row['incertidumbre_laboratorio'] ?? null);
        $org = self::number($row['puntuacion_organizador'] ?? null);
        [$limSat, $limIns, $limEn] = $limits;

        // Con la calculada a dos decimales, como se graba (EARNPCA) y como la filtra el listado de resultados
        $differs = $calc !== null && $org !== null
            && abs(round($calc, 2) - $org) > ($type === 'D' ? self::TOLERANCE_D : self::TOLERANCE) + 0.000001;

        $given = (string) ($row['evaluacion_organizador'] ?? '');
        $proposed = false;
        if ($given !== '') {
            $evaluation = $given;
        } else {
            $evaluation = '';
            if ($type === 'C') {
                $evaluation = self::qualitativeEvaluation($row['resultado'] ?? null, $row['valor_asignado'] ?? null);
            } elseif ($org !== null) {
                $evaluation = self::scoreEvaluation($type, $org, $row['desviacion'] ?? null, $limSat, $limIns, $limEn);
            } elseif ($calc !== null) {
                $evaluation = self::scoreEvaluation($type, $calc, $row['desviacion'] ?? null, $limSat, $limIns, $limEn);
            }
            $proposed = $evaluation !== '';
        }

        return [$calc === null ? null : round($calc, 2), $evaluation === '' ? null : $evaluation, $proposed, $differs];
    }

    /** LimitesRonda: [satisfactorio, insatisfactorio, En] de la ronda; vacíos, los de ISO 13528. */
    public static function limits(object $round): array
    {
        return [
            self::number($round->EAPNLSA ?? null) ?? self::LIMIT_SATISFACTORY,
            self::number($round->EAPNLIN ?? null) ?? self::LIMIT_UNSATISFACTORY,
            self::number($round->EAPNLEN ?? null) ?? self::LIMIT_EN,
        ];
    }

    /** Fila de CALEAR (columnas) con los nombres de evaluateRow. */
    public static function rowValues(array $columns): array
    {
        return [
            'tipo_puntuacion'           => $columns['EARCTPU'] ?? null,
            'sobre_log10'               => $columns['EARBLOG'] ?? null,
            'resultado'                 => $columns['EARCRES'] ?? null,
            'valor_asignado'            => $columns['EARCVAS'] ?? null,
            'desviacion'                => $columns['EARNDES'] ?? null,
            'incertidumbre_asignado'    => $columns['EARNINV'] ?? null,
            'incertidumbre_laboratorio' => $columns['EARNINL'] ?? null,
            'puntuacion_organizador'    => $columns['EARNPOR'] ?? null,
            'evaluacion_organizador'    => $columns['EARCEVO'] ?? null,
        ];
    }

    /** Columnas calculadas de una fila (EARNPCA, EARCEVA, EARBPRO). */
    public static function evaluatedColumns(array $values, array $limits): array
    {
        [$calc, $evaluation, $proposed] = self::evaluateRow($values, $limits);

        return ['EARNPCA' => $calc, 'EARCEVA' => $evaluation, 'EARBPRO' => $proposed ? 'T' : 'F'];
    }

    /**
     * RecalcularRejilla: vuelve a evaluar todas las filas de la ronda (al
     * cambiar los límites) y graba las que cambian. Devuelve cuántas cambian.
     */
    public static function recalculateRound(string $del, string $ser, int $cod): int
    {
        $db = DB::connection('dynamic');
        $round = self::round($del, $ser, $cod);
        if (! $round) {
            return 0;
        }
        $limits = self::limits($round);
        $changed = 0;
        $rows = $db->table('CALEAR')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)->get();
        foreach ($rows as $row) {
            $row = (array) $row;
            $new = self::evaluatedColumns(self::rowValues($row), $limits);
            $old = ['EARNPCA' => self::number($row['EARNPCA']), 'EARCEVA' => $row['EARCEVA'] === '' ? null : $row['EARCEVA'],
                'EARBPRO' => $row['EARBPRO'] === 'T' ? 'T' : 'F'];
            if ($new == $old) {
                continue;
            }
            $db->table('CALEAR')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)
                ->where('EAR1COD', (int) $row['EAR1COD'])->update($new);
            $changed++;
        }

        return $changed;
    }

    // ------------------------------------------------------------------
    // Rondas
    // ------------------------------------------------------------------

    /** Ronda (CALEAP) o null; con $lock, bloqueada hasta el commit. */
    public static function round(string $del, string $ser, int $cod, bool $lock = false): ?object
    {
        $query = DB::connection('dynamic')->table(self::TABLE)
            ->where('DEL3COD', $del)->where('EAP1SER', $ser)->where('EAP1COD', $cod);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /** Estado de la ronda ('' cuenta como pendiente de enviar). */
    public static function state(object $round): string
    {
        $state = (string) ($round->EAPCEST ?? '');

        return $state === '' ? 'P' : $state;
    }

    /** Una ronda cerrada se ve en solo lectura (EstadoSegunRonda): sus muestras y resultados tampoco cambian. */
    public static function assertEditable(object $round): void
    {
        if (self::state($round) === 'C') {
            throw new BusinessRuleException('El ensayo de aptitud está cerrado y solo se puede consultar; para cambiarlo hay que '
                .'reabrirlo (PUT /ensayos-aptitud con otro estado)');
        }
    }

    /**
     * EstadoAGrabar: con fecha de envío deja de estar pendiente; con el
     * informe del organizador, evaluada.
     */
    public static function automaticState(string $state, bool $hasSubmission, bool $hasReport): string
    {
        if ($state === 'P' && $hasSubmission) {
            $state = 'E';
        }
        if (($state === 'P' || $state === 'E') && $hasReport) {
            $state = 'V';
        }

        return $state;
    }

    /**
     * Suceso de la ficha de la ronda por un cambio en sus muestras o
     * resultados, como FichaEnsayoAptitud.Grabar: fila con nivel 2, campo
     * «tabla modificada» sin valores con nivel 3.
     */
    public static function auditRound(string $del, string $ser, int $cod, string $field): void
    {
        $row = VeolabCodes::format(self::TABLE, (string) $cod, $del, $ser);
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, self::TABLE, $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, self::TABLE, $row, $field);
    }

    /**
     * EAP_Borrar (sin la fila de CALEAP, que ya ha borrado quien llama):
     * resultados, operaciones, enlaces de las incidencias hacia la ronda, su
     * aviso J y documentos a la papelera.
     */
    public static function deleteRoundData(string $del, string $ser, int $cod): void
    {
        $db = DB::connection('dynamic');
        $db->table('CALEAR')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)->delete();
        $db->table('CALEAO')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)->delete();
        $db->table('CALIYE')->where('IYECTAB', self::TABLE)->where('IYECDEL', $del)->where('IYECSER', $ser)
            ->where('IYECCOD', (string) $cod)->delete();
        self::deleteDeadlineNotification($del, $ser, $cod);
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('EAP2SER', $ser)->where('EAP2COD', $cod)
            ->where(fn ($q) => $q->whereNull('DIR2COD')->orWhere('DIR2COD', '<>', 0))
            ->update(VeolabDocuments::trashUpdate($del));
    }

    /** ¿Tiene la ronda algún insatisfactorio, falso positivo o falso negativo? */
    public static function hasBadResults(string $del, string $ser, int $cod): bool
    {
        return DB::connection('dynamic')->table('CALEAR')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)
            ->whereIn('EARCEVA', self::BAD)->exists();
    }

    /** ¿Hay alguna incidencia que tenga la ronda en «A qué afecta»? */
    public static function hasIncidents(string $del, string $ser, int $cod): bool
    {
        return DB::connection('dynamic')->table('CALIYE')->where('IYECTAB', self::TABLE)->where('IYECDEL', $del)
            ->where('IYECSER', $ser)->where('IYECCOD', (string) $cod)->exists();
    }

    /** EAP_SQLAnio (sobre CALEAP): rondas de un año por la recepción de la muestra o, si falta, por la fecha límite. */
    public static function applyYear($query, int $year, string $table = 'CALEAP'): void
    {
        $query->whereRaw("COALESCE({$table}.EAPDREC, {$table}.EAPDLIM) >= ?", [sprintf('%04d-01-01 00:00:00', $year)])
            ->whereRaw("COALESCE({$table}.EAPDREC, {$table}.EAPDLIM) < ?", [sprintf('%04d-01-01 00:00:00', $year + 1)]);
    }

    /** Año de un filtro ?anio= (422 si no es un año). */
    public static function yearFilter($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}$/', $value)) {
            throw new BusinessRuleException('El filtro anio debe ser un año de cuatro cifras');
        }

        return (int) $value;
    }

    // ------------------------------------------------------------------
    // Aviso J de la fecha límite de envío (fase 2, Comunicaciones.bas)
    // ------------------------------------------------------------------

    /** Tipo de notificación (COM_NOTIF_APTITUD). */
    public const NOTIFICATION = 'J';

    /** Días antes de la fecha límite de envío en que sale el aviso (EAP_DIAS_AVISO_ENVIO). */
    public const NOTICE_DAYS = 7;

    /** COM_BorrarNotificacionesAptitud: la notificación J de la ronda y sus avisos emergentes. */
    public static function deleteDeadlineNotification(string $del, string $ser, int $cod): void
    {
        $db = DB::connection('dynamic');
        $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
            .'WHERE ACCAVI.AVICTIP = ? AND ACCNOT.NOTCTIP = ? AND ACCNOT.EAP2DEL = ? AND ACCNOT.EAP2SER = ? AND ACCNOT.EAP2COD = ?',
            ['N', self::NOTIFICATION, $del, $ser, $cod]);
        $db->table('ACCNOT')->where('NOTCTIP', self::NOTIFICATION)->where('EAP2DEL', $del)->where('EAP2SER', $ser)
            ->where('EAP2COD', $cod)->delete();
    }

    /**
     * COM_CrearNotificacionesAptitud, tras grabar la ronda (dentro de la
     * transacción): borra la notificación J anterior y, si la ronda está
     * pendiente de enviar con fecha límite, el módulo COM está activo y
     * LABCON.CONBNAP = T, la crea para quien registró la ronda si sigue
     * activo; si no, como Veolab con el usuario en sesión, para el usuario de
     * la petición ($fallback = [delegación, código]) si está activo, y sin él
     * no avisa a nadie. Sale NOTICE_DAYS días antes de la fecha
     * límite o hoy si ese día ya pasó; texto «programa - ronda: fecha límite»
     * (o el código de la ronda si no tiene programa ni ronda).
     */
    public static function notifyDeadline(string $del, string $ser, int $cod, ?array $fallback = null): void
    {
        self::deleteDeadlineNotification($del, $ser, $cod);

        $db = DB::connection('dynamic');
        $round = self::round($del, $ser, $cod);
        if (! $round || self::state($round) !== 'P' || empty($round->EAPDLIM)) {
            return;
        }
        if (($db->table('LABCON')->where('CON1COD', 1)->value('CONBNAP') ?? '') !== 'T'
            || ! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'COM')) {
            return;
        }
        $isActive = fn (string $uDel, string $uCod) => $uCod !== ''
            && $db->table('ACCUSU')->where('DEL3COD', $uDel)->where('USU1COD', $uCod)
                ->where(fn ($q) => $q->whereNull('USUBBAJ')->orWhere('USUBBAJ', '<>', 'T'))
                ->exists();
        [$userDel, $userCod] = [(string) ($round->USU2DEL ?? ''), (string) ($round->USU2COD ?? '')];
        if (! $isActive($userDel, $userCod)) {
            // Quien la registró ya no es un usuario activo: el que graba
            [$userDel, $userCod] = [(string) ($fallback[0] ?? ''), (string) ($fallback[1] ?? '')];
            if (! $isActive($userDel, $userCod)) {
                return;
            }
        }

        $limit = substr((string) $round->EAPDLIM, 0, 10);
        $today = (string) $db->selectOne('SELECT CURDATE() AS d')->d;
        $notice = (new \DateTime($limit))->modify('-'.self::NOTICE_DAYS.' days')->format('Y-m-d');
        if ($notice < $today) {
            $notice = $today;
        }
        $date = $notice.' 00:00:00';

        $program = trim((string) $round->EAPCPRG);
        $roundName = trim((string) $round->EAPCRON);
        $text = trim($program.($program !== '' && $roundName !== '' ? ' - ' : '').$roundName);
        if ($text === '') {
            $text = VeolabCodes::format(self::TABLE, (string) $cod, $del, $ser);
        }
        $text = mb_substr($text, 0, 240).': '.(new \DateTime($limit))->format('d/m/Y');

        $notification = VeolabCodes::next('ACCNOT', '', $userDel);
        $db->table('ACCNOT')->insert([
            'DEL3COD' => $userDel, 'NOT1COD' => $notification, 'NOTTFEC' => $date, 'NOTCTIP' => self::NOTIFICATION, 'NOTCAVI' => $text,
            'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'EAP2DEL' => $del, 'EAP2SER' => $ser, 'EAP2COD' => $cod,
        ]);
        $db->table('ACCAVI')->insert([
            'DEL3COD' => $userDel, 'AVI1COD' => VeolabCodes::next('ACCAVI', '', $userDel), 'AVITFEC' => $date,
            'AVICTIP' => 'N', 'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'NOT2DEL' => $userDel, 'NOT2COD' => $notification,
        ]);
    }

    // ------------------------------------------------------------------
    // Traer resultados de las operaciones (TraerResultados)
    // ------------------------------------------------------------------

    /**
     * EAP_ResultadoTecnica: resultado de una técnica de la operación tal como
     * se informa: la primera columna con valor que va al informe o, si no hay
     * ninguna marcada, la primera con valor.
     */
    public static function techniqueResult(string $opDel, string $opSer, int $opCod, string $tecDel, string $tecCod): string
    {
        $rows = DB::connection('dynamic')->table('LABCOR')
            ->where('OPE3DEL', $opDel)->where('OPE3SER', $opSer)->where('OPE3COD', $opCod)
            ->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)
            ->whereNotNull('CORCVAL')->where('CORCVAL', '<>', '')->where('CORCVAL', '<>', 'N/A')
            ->orderBy('COR1COD')->get(['CORCVAL', 'CORBINF']);
        $first = '';
        foreach ($rows as $row) {
            if ($first === '') {
                $first = (string) $row->CORCVAL;
            }
            if ($row->CORBINF === 'T') {
                return (string) $row->CORCVAL;
            }
        }

        return $first;
    }

    /**
     * TraerResultados: añade los parámetros de las operaciones de la ronda que
     * tienen resultado (nombre, unidad, método y resultado tal como se
     * informa), sin repetir los que ya están (misma operación y parámetro). El
     * tipo de puntuación y el log10 son los de la última fila (o z). Devuelve
     * las filas añadidas.
     */
    public static function bringResults(object $round): array
    {
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = [(string) $round->DEL3COD, (string) $round->EAP1SER, (int) $round->EAP1COD];
        $samples = $db->table('CALEAO')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)
            ->orderBy('EAONORD')->orderBy('OPE3COD')->get(['OPE3DEL', 'OPE3SER', 'OPE3COD']);
        if ($samples->isEmpty()) {
            throw new BusinessRuleException('Añada primero las operaciones de la ronda (/ensayos-aptitud/muestras)');
        }
        $own = fn () => $db->table('CALEAR')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod);
        $last = $own()->orderByDesc('EAR1COD')->first(['EARCTPU', 'EARBLOG']);
        $type = $last && (string) $last->EARCTPU !== '' ? (string) $last->EARCTPU : 'Z';
        $log = $last && $last->EARBLOG === 'T' ? 'T' : 'F';
        $existing = [];
        foreach ($own()->get(['OPE2DEL', 'OPE2SER', 'OPE2COD', 'TEC2DEL', 'TEC2COD']) as $r) {
            $existing[$r->OPE2DEL."\x1B".$r->OPE2SER."\x1B".$r->OPE2COD."\x1B".$r->TEC2DEL."\x1B".$r->TEC2COD] = true;
        }
        $line = (int) $own()->max('EAR1COD');
        $limits = self::limits($round);

        $added = [];
        foreach ($samples as $sample) {
            [$opDel, $opSer, $opCod] = [(string) $sample->OPE3DEL, (string) $sample->OPE3SER, (int) $sample->OPE3COD];
            $results = $db->table('LABRES')->where('OPE3DEL', $opDel)->where('OPE3SER', $opSer)->where('OPE3COD', $opCod)
                ->orderBy('RESNORD')->get(['TEC3DEL', 'TEC3COD', 'RESCNOM', 'RESCUNI', 'RESCMET']);
            foreach ($results as $res) {
                $key = $opDel."\x1B".$opSer."\x1B".$opCod."\x1B".$res->TEC3DEL."\x1B".$res->TEC3COD;
                if (isset($existing[$key])) {
                    continue;
                }
                $value = self::techniqueResult($opDel, $opSer, $opCod, (string) $res->TEC3DEL, (string) $res->TEC3COD);
                if ($value === '') {
                    continue;
                }
                $existing[$key] = true;
                $line++;
                $row = [
                    'DEL3COD' => $del, 'EAP3SER' => $ser, 'EAP3COD' => $cod, 'EAR1COD' => $line,
                    'OPE2DEL' => $opDel, 'OPE2SER' => $opSer, 'OPE2COD' => $opCod,
                    'TEC2DEL' => (string) $res->TEC3DEL, 'TEC2COD' => (string) $res->TEC3COD,
                    'EARCPAR' => self::textOrNull($res->RESCNOM, 255), 'EARCRES' => self::textOrNull($value, 255),
                    'EARCUNI' => self::textOrNull($res->RESCUNI, 50), 'EARCMET' => self::textOrNull($res->RESCMET, 255),
                    'EARCVAS' => null, 'EARNDES' => null, 'EARNINV' => null, 'EARNINL' => null,
                    'EARCTPU' => $type, 'EARBLOG' => $log, 'EARNPOR' => null, 'EARCEVO' => null, 'EARCOBS' => null,
                ];
                $row += self::evaluatedColumns(self::rowValues($row), $limits);
                $db->table('CALEAR')->insert($row);
                $added[] = [
                    'codigo' => $line, 'operacion_delegacion' => $opDel, 'operacion_serie' => $opSer, 'operacion_codigo' => $opCod,
                    'parametro_delegacion' => $row['TEC2DEL'], 'parametro_codigo' => $row['TEC2COD'], 'parametro_nombre' => $row['EARCPAR'],
                    'resultado' => $row['EARCRES'], 'unidad' => $row['EARCUNI'], 'metodo' => $row['EARCMET'],
                    'tipo_puntuacion' => $type, 'sobre_log10' => $log,
                ];
            }
        }

        return $added;
    }

    /** TextoSQLONulo: texto recortado o null si está vacío. */
    public static function textOrNull($value, int $max = 255): ?string
    {
        $text = (string) ($value ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    // ------------------------------------------------------------------
    // Incidencia de la ronda (NuevaIncidencia / ProponerIncidencia)
    // ------------------------------------------------------------------

    /**
     * NuevaIncidencia: incidencia de calidad registrada, enlazada a la ronda y
     * a sus operaciones: trabajo no conforme con origen «ensayo de aptitud»,
     * título «Ensayo de aptitud …: resultados insatisfactorios» y el cliente
     * de la primera operación que lo tenga (CAL_ClienteEnlaces). En Veolab se
     * abre la ficha para completarla; la API la registra con quien la detecta
     * (obligatorio: $detectedBy o el empleado del usuario) y la fecha de hoy.
     * Avisos Q y K tras el commit. Devuelve la clave de la incidencia.
     *
     * $options: incidencia_serie, incidencia_detectada_por_delegacion,
     * incidencia_detectada_por_codigo, incidencia_usuario_delegacion,
     * incidencia_usuario_codigo.
     */
    public static function openIncident(object $round, array $options): array
    {
        VeolabQuality::assertModule();
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = [(string) $round->DEL3COD, (string) $round->EAP1SER, (int) $round->EAP1COD];

        $incSeries = (string) ($options['incidencia_serie'] ?? '');
        if (str_contains($incSeries, '#')) {
            throw new BusinessRuleException('La serie de la incidencia no puede llevar el carácter #');
        }
        if (! VeolabCodes::autonumeric('CALINC')) {
            throw new BusinessRuleException('El código de las incidencias no es autonumérico: créela con POST /incidencias y enlácela con POST /incidencias/enlaces');
        }

        // Usuario que la registra y quién la detecta (por defecto, el empleado del usuario)
        $user = VeolabQuality::requestUser($options['incidencia_usuario_delegacion'] ?? null, isset($options['incidencia_usuario_codigo'])
            ? (string) $options['incidencia_usuario_codigo'] : null);
        if ($user !== null && ! $user['escritura']) {
            throw new BusinessRuleException('El usuario no tiene permiso para modificar incidencias');
        }
        $empCod = (int) ($options['incidencia_detectada_por_codigo'] ?? 0);
        $empDel = (string) ($options['incidencia_detectada_por_delegacion'] ?? '');
        if ($empCod === 0 && $user !== null && $user['empleado'] !== null) {
            [$empDel, $empCod] = $user['empleado'];
        }
        if ($empCod === 0) {
            throw new BusinessRuleException('Para abrir la incidencia hace falta quién la detecta (incidencia_detectada_por_codigo) '
                .'o un usuario con empleado (incidencia_usuario_codigo)');
        }
        $employee = $db->table('GRHEMP')->where('DEL3COD', $empDel)->where('EMP1COD', $empCod)->first(['EMPBBAJ']);
        if (! $employee) {
            throw new BusinessRuleException('El empleado que detecta la incidencia no existe');
        }
        if ($employee->EMPBBAJ === 'T') {
            throw new BusinessRuleException('El empleado que detecta la incidencia está de baja');
        }

        // Enlaces: la ronda y sus operaciones; cliente de la primera operación que lo tenga
        $links = [[self::TABLE, $del, $ser, (string) $cod]];
        $client = null;
        $samples = $db->table('CALEAO')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)
            ->orderBy('EAONORD')->orderBy('OPE3COD')->get(['OPE3DEL', 'OPE3SER', 'OPE3COD']);
        foreach ($samples as $s) {
            $links[] = ['LABOPE', (string) $s->OPE3DEL, (string) $s->OPE3SER, (string) $s->OPE3COD];
            if ($client === null) {
                $op = $db->table('LABOPE')->where('DEL3COD', (string) $s->OPE3DEL)->where('OPE1SER', (string) $s->OPE3SER)
                    ->where('OPE1COD', (int) $s->OPE3COD)->first(['CLI2DEL', 'CLI2COD']);
                if ($op && (string) $op->CLI2COD !== '') {
                    $client = [(string) $op->CLI2DEL, (string) $op->CLI2COD];
                }
            }
        }

        // Título (ETI01068) con el programa y la ronda o, si no hay, el código
        $title = trim(trim((string) $round->EAPCPRG).' '.trim((string) $round->EAPCRON));
        if ($title === '') {
            $title = VeolabCodes::format(self::TABLE, (string) $cod, $del, $ser);
        }
        $title = mb_substr("Ensayo de aptitud {$title}: resultados insatisfactorios", 0, 255);

        // Código del contador ACCCLT de la serie (reglas de ACCCFC 'CALINC')
        $multiple = VeolabCodes::multiple('CALINC');
        $incCod = 0;
        for ($attempt = 0; $attempt < 10000; $attempt++) {
            $candidate = VeolabCodes::next('CALINC', $incSeries, $del, $multiple);
            if (! VeolabQuality::incident($del, $incSeries, $candidate)) {
                $incCod = $candidate;
                break;
            }
        }
        if ($incCod === 0) {
            throw new BusinessRuleException('No se ha podido generar un código libre para la incidencia');
        }

        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        $db->table('CALINC')->insert([
            'DEL3COD' => $del, 'INC1SER' => $incSeries, 'INC1COD' => $incCod,
            'INCCCLA' => 'T', 'INCCORI' => 'A', 'INCCEST' => 'R',
            'INCDDET' => substr($now, 0, 10).' 00:00:00', 'INCTREG' => $now, 'INCCDES' => $title,
            'INCBNCO' => 'F', 'INCBSIM' => 'F', 'INCBAFI' => 'F', 'INCBRET' => 'F', 'INCBCCL' => 'F', 'INCBTOD' => 'F',
            'CLI2DEL' => $client[0] ?? null, 'CLI2COD' => $client[1] ?? null,
            'EMP2DEL' => $empDel, 'EMP2COD' => $empCod,
            'USU2DEL' => $user['delegacion'] ?? null, 'USU2COD' => $user['codigo'] ?? null,
        ]);
        VeolabAudit::record(VeolabAudit::INSERCION, 'CALINC', VeolabCodes::format('CALINC', (string) $incCod, $del, $incSeries));

        $line = 0;
        foreach ($links as [$table, $lDel, $lSer, $lCod]) {
            $db->table('CALIYE')->insert([
                'DEL3COD' => $del, 'INC3SER' => $incSeries, 'INC3COD' => $incCod, 'IYE1COD' => ++$line,
                'IYECTAB' => $table, 'IYECDEL' => $lDel, 'IYECSER' => $lSer, 'IYECCOD' => $lCod,
            ]);
        }

        $actor = $user !== null ? ['delegacion' => $user['delegacion'], 'codigo' => $user['codigo']] : null;
        VeolabQuality::afterCommit(function () use ($del, $incSeries, $incCod, $actor) {
            VeolabQuality::notifyStep($del, $incSeries, $incCod, '', $actor);
            VeolabQuality::notifyActions($del, $incSeries, $incCod);
        });

        return ['delegacion' => $del, 'serie' => $incSeries, 'codigo' => $incCod];
    }

    /**
     * ProponerIncidencia: con algún insatisfactorio, falso positivo o falso
     * negativo y sin incidencias enlazadas aún, abre la incidencia. Devuelve
     * su clave o null si no procede.
     */
    public static function openIncidentIfProposed(string $del, string $ser, int $cod, array $options): ?array
    {
        if (! self::hasBadResults($del, $ser, $cod) || self::hasIncidents($del, $ser, $cod)) {
            return null;
        }
        $round = self::round($del, $ser, $cod);

        return $round ? self::openIncident($round, $options) : null;
    }

    /** Reglas de validación de los parámetros de la incidencia (abrir_incidencia y POST /ensayos-aptitud/incidencia). */
    public static function incidentRules(): array
    {
        return [
            'incidencia_serie'                    => 'nullable|string|max:10',
            'incidencia_detectada_por_delegacion' => 'nullable|string|max:10',
            'incidencia_detectada_por_codigo'     => 'nullable|integer|min:0',
            'incidencia_usuario_delegacion'       => 'nullable|string|max:10',
            'incidencia_usuario_codigo'           => 'nullable|string|max:15',
        ];
    }
}
