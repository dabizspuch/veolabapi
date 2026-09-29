<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Campos autodefinibles de operación (LABAUT / LABOYA), replicando
 * FichaOperacion.frm (AcumulaGrabarAutodefinibles, EjecutaGrabarAutodefinibles,
 * CamposObligatoriosCubiertos) y Autodefinibles.bas.
 *
 *  - En la API se identifican por NOMBRE (AUTCNOM): Veolab no deja repetirlo
 *    entre delegaciones. Solo valen los de la delegación de la operación y los
 *    generales (DEL3COD = ''), nunca los de otra delegación; si un nombre
 *    estuviera en ambas, gana el de la delegación de la operación.
 *  - Valor vacío (null / '') = se borra la fila de LABOYA, como Veolab.
 *  - Fichero (F): se indica {delegacion, codigo}; se guarda la clave en
 *    OYA3DEL/OYA3COD y en OYACVAL el texto que muestra Veolab.
 *  - LABAYS (vínculo con servicios) solo decide lo que muestra Veolab: no
 *    restringe los valores.
 *
 * Trabaja sobre la conexión 'dynamic' y dentro de la transacción del cambio.
 */
class VeolabCustomFields
{
    // Tipos de datos (AUTCTDD, PAR_AUTO_* de Parametros.bas).
    private const NUMBER = 'N';
    private const DATE = 'D';
    private const DATE_WARNING = 'V';
    private const FILE = 'F';

    /**
     * Ficheros de un autodefinible F: tabla => [código, nombre, baja, código
     * entero, mostrar código]. AUTCFOR guarda el nombre de la tabla en el
     * idioma de Veolab (IDI_Cadena("CAM_<tabla>")), de ahí los alias.
     */
    private const FILE_TABLES = [
        'SINCLI' => ['CLI1COD', 'CLICNOM', 'CLIBBAJ', false, true],
        'SINPRO' => ['PRO1COD', 'PROCNOM', 'PROBBAJ', false, true],
        'GRHEMP' => ['EMP1COD', 'EMPCNOM', 'EMPBBAJ', true, false],
        'LABEQU' => ['EQU1COD', 'EQUCDES', 'EQUBBAJ', false, true],
        'LABTIO' => ['TIO1COD', 'TIOCNOM', 'TIOBBAJ', true, false],
        'LABSEC' => ['SEC1COD', 'SECCDES', 'SECBBAJ', true, false],
        'LABMAT' => ['MAT1COD', 'MATCDES', 'MATBBAJ', true, false],
        'LABTAR' => ['TAR1COD', 'TARCDES', 'TARBBAJ', true, false],
        'LABTEC' => ['TEC1COD', 'TECCNOM', 'TECBBAJ', false, false],
        'LABSER' => ['SER1COD', 'SERCNOM', 'SERBBAJ', false, false],
        'LABESC' => ['ESC1COD', 'ESCCDES', 'ESCBBAJ', true, false],
        'LABNOR' => ['NOR1COD', 'NORCDES', 'NORBBAJ', false, false],
    ];

    /** Nombres de tabla de Idioma.lng (es, en, ca, gl) => tabla. */
    private const FILE_TABLE_NAMES = [
        'clientes' => 'SINCLI', 'customers' => 'SINCLI', 'clients' => 'SINCLI',
        'proveedores' => 'SINPRO', 'providers' => 'SINPRO', 'proveïdors' => 'SINPRO', 'provedores' => 'SINPRO',
        'empleados' => 'GRHEMP', 'employees' => 'GRHEMP', 'empleats' => 'GRHEMP', 'empregados' => 'GRHEMP',
        'equipo de cliente' => 'LABEQU', 'client equipment' => 'LABEQU', 'equip de client' => 'LABEQU',
        'tipos de operaciones' => 'LABTIO', 'types of operations' => 'LABTIO', "tipus d'operacions" => 'LABTIO', 'tipos de operacións' => 'LABTIO',
        'secciones' => 'LABSEC', 'sections' => 'LABSEC', 'seccions' => 'LABSEC', 'seccións' => 'LABSEC',
        'matrices' => 'LABMAT', 'arrays' => 'LABMAT', 'matrius' => 'LABMAT',
        'tarifas' => 'LABTAR', 'rates' => 'LABTAR', 'tarifes' => 'LABTAR',
        'parámetros' => 'LABTEC', 'parameters' => 'LABTEC', 'paràmetres' => 'LABTEC',
        'servicios' => 'LABSER', 'services' => 'LABSER', 'serveis' => 'LABSER', 'servizos' => 'LABSER',
        'gastos adicionales' => 'LABESC', 'additional costs' => 'LABESC', 'despeses addicionals' => 'LABESC', 'gastos adicionais' => 'LABESC',
        'normativas' => 'LABNOR', 'regulations' => 'LABNOR', 'normatives' => 'LABNOR',
    ];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /**
     * Valida y normaliza los autodefinibles recibidos ({nombre: valor}).
     * Devuelve [clave "del\x1Bcod" => ['def', 'value', 'fileDel', 'fileCod']].
     */
    public static function resolve(string $delegation, $input): array
    {
        if ($input === null || $input === []) {
            return [];
        }
        if (! is_array($input) || array_is_list($input)) {
            throw new BusinessRuleException('Los autodefinibles se indican como un objeto {"nombre": valor}');
        }

        $byName = [];
        foreach (self::definitions($delegation) as $def) {
            $byName[mb_strtolower(trim((string) $def->AUTCNOM))] = $def;
        }

        $out = [];
        foreach ($input as $name => $value) {
            $def = $byName[mb_strtolower(trim((string) $name))] ?? null;
            if (! $def) {
                throw new BusinessRuleException("El autodefinible '{$name}' no existe para la delegación de la operación");
            }
            $out[self::key($def->DEL3COD, $def->AUT1COD)] = ['def' => $def] + self::normalize($def, $value, (string) $name);
        }

        return $out;
    }

    /**
     * ¿Todos los autodefinibles indicados se pueden editar con la operación
     * validada (AUTBVAL)? Veolab los deja editables aunque la bloquee un informe.
     */
    public static function allEditableWhenValidated(array $resolved): bool
    {
        foreach ($resolved as $item) {
            if ($item['def']->AUTBVAL !== 'T') {
                return false;
            }
        }

        return $resolved !== [];
    }

    /**
     * Guarda los valores resueltos: borra e inserta solo los que cambian, sin
     * fila para los vacíos (EjecutaGrabarAutodefinibles), y audita cada valor
     * nuevo como modificación del campo "#<nombre>" de LABOPE.
     *
     * Asegura además la fila "cero" (AUT3DEL = '', AUT3COD = 0) que Veolab crea
     * en cada operación nueva y que necesitan sus listados de operaciones con
     * columnas de autodefinibles (también repara operaciones que no la tengan).
     */
    public static function save(string $del, string $ser, int $cod, array $resolved, string $auditRow): void
    {
        $db = DB::connection('dynamic');
        $opKey = ['OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod];

        $db->table('LABOYA')->insertOrIgnore($opKey + [
            'AUT3DEL' => '', 'AUT3COD' => 0, 'OYACVAL' => '', 'OYA3DEL' => '', 'OYA3COD' => '',
        ]);

        if ($resolved === []) {
            return;
        }

        $previous = $db->table('LABOYA')->where($opKey)->get()
            ->keyBy(fn ($row) => self::key($row->AUT3DEL, $row->AUT3COD));

        foreach ($resolved as $key => $item) {
            $old = $previous[$key] ?? null;
            $oldValue = (string) ($old->OYACVAL ?? '');

            $unchanged = $old
                ? $oldValue === $item['value'] && (string) $old->OYA3DEL === $item['fileDel'] && (string) $old->OYA3COD === $item['fileCod']
                : $item['value'] === '';
            if ($unchanged) {
                continue;
            }

            $where = $opKey + ['AUT3DEL' => (string) $item['def']->DEL3COD, 'AUT3COD' => (int) $item['def']->AUT1COD];
            $db->table('LABOYA')->where($where)->delete();

            if ($item['value'] !== '') {
                $db->table('LABOYA')->insert($where + [
                    'OYACVAL' => $item['value'],
                    'OYA3DEL' => $item['fileDel'],
                    'OYA3COD' => $item['fileCod'],
                ]);
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABOPE', $auditRow,
                    '#'.$item['def']->AUTCNOM, $item['value'], $oldValue);
            }
        }
    }

    /**
     * Valores para la respuesta de varias operaciones ([[del, ser, cod], ...]):
     * [clave "del\x1Bser\x1Bcod" => [nombre => valor]]. Los de fichero salen como
     * {delegacion, codigo}. Solo autodefinibles de operación en vigor de la
     * delegación de la operación o generales, como los muestra Veolab.
     */
    public static function valuesFor(array $operations): array
    {
        if ($operations === []) {
            return [];
        }

        $rows = DB::connection('dynamic')->table('LABOYA')
            ->join('LABAUT', function ($join) {
                $join->on('LABOYA.AUT3DEL', '=', 'LABAUT.DEL3COD')
                    ->on('LABOYA.AUT3COD', '=', 'LABAUT.AUT1COD');
            })
            ->where(function ($q) use ($operations) {
                foreach ($operations as [$del, $ser, $cod]) {
                    $q->orWhere(fn ($w) => $w->where('LABOYA.OPE3DEL', $del)
                        ->where('LABOYA.OPE3SER', $ser)->where('LABOYA.OPE3COD', $cod));
                }
            })
            ->where('LABAUT.AUTCTIP', 'O')
            ->where(fn ($q) => $q->whereNull('LABAUT.AUTBCAT')->orWhere('LABAUT.AUTBCAT', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('LABAUT.AUTBBAJ')->orWhere('LABAUT.AUTBBAJ', '<>', 'T'))
            ->orderBy('LABAUT.DEL3COD')->orderBy('LABAUT.AUTNORD')
            ->get(['LABOYA.OPE3DEL', 'LABOYA.OPE3SER', 'LABOYA.OPE3COD', 'LABOYA.AUT3DEL', 'LABOYA.OYACVAL',
                'LABOYA.OYA3DEL', 'LABOYA.OYA3COD', 'LABAUT.AUTCNOM', 'LABAUT.AUTCTDD', 'LABAUT.AUTCFOR']);

        $out = [];
        foreach ($rows as $row) {
            if ($row->AUT3DEL !== '' && $row->AUT3DEL !== $row->OPE3DEL) {
                continue;
            }
            $fileCode = (string) $row->OYA3COD;
            if ((string) $row->OYACVAL === '' && $fileCode === '') {
                continue;
            }

            $value = (string) $row->OYACVAL;
            if ($row->AUTCTDD === self::FILE && $fileCode !== '') {
                $table = self::fileTable((string) $row->AUTCFOR);
                $value = [
                    'delegacion' => (string) $row->OYA3DEL,
                    'codigo'     => $table && self::FILE_TABLES[$table][3] ? (int) $fileCode : $fileCode,
                ];
            }

            // Orden por delegación: la de la operación pisa a la general.
            $out[$row->OPE3DEL."\x1B".$row->OPE3SER."\x1B".$row->OPE3COD][(string) $row->AUTCNOM] = $value;
        }

        return $out;
    }

    /**
     * Autodefinibles obligatorios de CONCCAO ("AU_<del>_<cod>.OYACVAL") que
     * quedarían vacíos: sus nombres. Se ignoran los de otra delegación o que ya
     * no están en vigor (Veolab no los muestra). $opKey null = operación nueva.
     */
    public static function missingRequired(array $entries, string $delegation, ?array $opKey, array $resolved): array
    {
        if ($entries === []) {
            return [];
        }

        $definitions = [];
        foreach (self::definitions($delegation) as $def) {
            $definitions[self::key($def->DEL3COD, $def->AUT1COD)] = $def;
        }

        $stored = [];
        if ($opKey !== null) {
            $stored = DB::connection('dynamic')->table('LABOYA')
                ->where(['OPE3DEL' => $opKey[0], 'OPE3SER' => $opKey[1], 'OPE3COD' => $opKey[2]])
                ->get()->keyBy(fn ($row) => self::key($row->AUT3DEL, $row->AUT3COD))->all();
        }

        $missing = [];
        foreach ($entries as $entry) {
            // CAD_DescomponerParFinal: la delegación puede contener '_'.
            $pair = preg_replace('/^AU_|\.OYACVAL$/', '', $entry);
            $pos = strrpos($pair, '_');
            $key = $pos === false ? self::key('', (int) $pair) : self::key(substr($pair, 0, $pos), (int) substr($pair, $pos + 1));

            $def = $definitions[$key] ?? null;
            if (! $def) {
                continue;
            }

            if (isset($resolved[$key])) {
                $empty = $resolved[$key]['value'] === '' && $resolved[$key]['fileCod'] === '';
            } else {
                $row = $stored[$key] ?? null;
                $empty = ! $row || ((string) $row->OYACVAL === '' && (string) $row->OYA3COD === '');
            }
            if ($empty) {
                $missing[] = (string) $def->AUTCNOM;
            }
        }

        return $missing;
    }

    // ------------------------------------------------------------------

    /**
     * Autodefinibles de operación en vigor utilizables en la delegación
     * (AUT_CargarAutodefinibles): generales primero y luego los de la
     * delegación, para que estos prevalezcan al indexar por nombre.
     */
    private static function definitions(string $delegation): array
    {
        return DB::connection('dynamic')->table('LABAUT')
            ->where('AUTCTIP', 'O')
            ->whereIn('DEL3COD', array_unique(['', $delegation]))
            ->where(fn ($q) => $q->whereNull('AUTBCAT')->orWhere('AUTBCAT', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('AUTBBAJ')->orWhere('AUTBBAJ', '<>', 'T'))
            ->orderBy('DEL3COD')->orderBy('AUTNORD')
            ->get(['DEL3COD', 'AUT1COD', 'AUTCNOM', 'AUTCTDD', 'AUTCFOR', 'AUTBVAL'])
            ->all();
    }

    private static function key($delegation, $code): string
    {
        return $delegation."\x1B".(int) $code;
    }

    /** Valor a guardar según el tipo del autodefinible. */
    private static function normalize(object $def, $value, string $name): array
    {
        $empty = ['value' => '', 'fileDel' => '', 'fileCod' => ''];
        if ($value === null || $value === '') {
            return $empty;
        }

        if ($def->AUTCTDD === self::FILE) {
            return self::fileValue($def, $value, $name);
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new BusinessRuleException("El valor del autodefinible '{$name}' no es válido");
        }

        $text = match ($def->AUTCTDD) {
            self::NUMBER => self::numberValue($def, $value, $name),
            self::DATE, self::DATE_WARNING => self::dateValue($def, (string) $value, $name),
            // Texto, extenso, seleccionable e incremento especial: tal cual. Como
            // en Veolab, el seleccionable admite también texto libre.
            default => (string) $value,
        };

        return ['value' => $text] + $empty;
    }

    /**
     * Número: se acepta con coma o punto decimal y se guarda como en Veolab
     * (coma decimal), con el formato numérico de AUTCFOR si lo tiene.
     */
    private static function numberValue(object $def, $value, string $name): string
    {
        $text = is_float($value)
            ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.')
            : trim((string) $value);

        if (! preg_match('/^[+-]?(\d+([.,]\d*)?|[.,]\d+)$/', $text)) {
            throw new BusinessRuleException("El autodefinible '{$name}' debe ser un número");
        }

        $formatted = self::vbNumberFormat((float) str_replace(',', '.', $text), trim((string) $def->AUTCFOR));

        return $formatted ?? str_replace('.', ',', $text);
    }

    /**
     * Format() de VB para formatos numéricos sencillos (0 # , .) con la
     * configuración regional española. Otros formatos: null (sin formato).
     */
    private static function vbNumberFormat(float $number, string $format): ?string
    {
        if ($format === '' || ! preg_match('/^([#0,]*)(?:(\.)([#0]*))?$/', $format, $m)) {
            return null;
        }

        [$intPart, $hasPoint, $decPart] = [$m[1], ($m[2] ?? '') !== '', $m[3] ?? ''];
        $maxDecimals = strlen($decPart);
        $minDecimals = substr_count($decPart, '0');

        $rounded = round($number, $maxDecimals);
        [$int, $dec] = array_pad(explode('.', number_format(abs($rounded), $maxDecimals, '.', '')), 2, '');

        while (strlen($dec) > $minDecimals && str_ends_with($dec, '0')) {
            $dec = substr($dec, 0, -1);
        }
        $int = ltrim($int, '0');
        $int = str_pad($int, substr_count($intPart, '0'), '0', STR_PAD_LEFT);
        if (str_contains($intPart, ',') && $int !== '') {
            $int = strrev(implode('.', str_split(strrev($int), 3)));
        }

        // VB conserva el separador decimal aunque no queden decimales ("5,").
        $out = $int.($hasPoint ? ','.$dec : '');

        return ($rounded < 0 ? '-' : '').$out;
    }

    /** Fecha: ISO (o dd/mm/aaaa) guardada con el formato de AUTCFOR, como Veolab. */
    private static function dateValue(object $def, string $value, string $name): string
    {
        $date = self::parseDate(trim($value));
        if (! $date) {
            throw new BusinessRuleException("El autodefinible '{$name}' debe ser una fecha");
        }

        return self::vbDateFormat($date, trim((string) $def->AUTCFOR));
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $formats = ['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.vP', 'd/m/Y', 'd/m/Y H:i', 'd/m/Y H:i:s'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Format() de VB para fechas con la configuración regional española.
     * Sin formato, el de fecha general: "25/09/2026" o "25/09/2026 9:05:00".
     */
    private static function vbDateFormat(DateTimeImmutable $date, string $format): string
    {
        $lower = strtolower($format);
        if ($format === '' || $lower === 'general date') {
            return $date->format('d/m/Y').($date->format('His') !== '000000' ? ' '.$date->format('G:i:s') : '');
        }
        if ($lower === 'short date') {
            return $date->format('d/m/Y');
        }

        $twelveHours = (bool) preg_match('#am/pm|a/p#i', $format);
        $out = '';
        $afterHour = false;
        $length = strlen($format);

        for ($i = 0; $i < $length;) {
            $char = $format[$i];

            if ($char === '"') {
                $end = strpos($format, '"', $i + 1);
                $end = $end === false ? $length : $end;
                $out .= substr($format, $i + 1, $end - $i - 1);
                $i = $end + 1;
                continue;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $out .= $format[$i + 1];
                $i += 2;
                continue;
            }
            if (! preg_match('#^(yyyy|yy|dddd|ddd|dd|d|mmmm|mmm|mm|m|hh|h|nn|n|ss|s|am/pm|a/p)#i', substr($format, $i), $t)) {
                $out .= $char;
                $i++;
                continue;
            }

            $token = strtolower($t[1]);
            $i += strlen($t[1]);

            // "m"/"mm" son minutos tras una hora o antes de unos segundos.
            if (($token === 'm' || $token === 'mm')
                && ($afterHour || preg_match('/^[^a-z]*s/i', substr($format, $i)))) {
                $token = $token === 'm' ? 'n' : 'nn';
            }

            $hour = (int) $date->format($twelveHours ? 'g' : 'G');
            $out .= match ($token) {
                'yyyy'  => $date->format('Y'),
                'yy'    => $date->format('y'),
                'dddd'  => self::WEEKDAYS[(int) $date->format('w')],
                'ddd'   => mb_substr(self::WEEKDAYS[(int) $date->format('w')], 0, 3),
                'dd'    => $date->format('d'),
                'd'     => $date->format('j'),
                'mmmm'  => self::MONTHS[(int) $date->format('n') - 1],
                'mmm'   => mb_substr(self::MONTHS[(int) $date->format('n') - 1], 0, 3),
                'mm'    => $date->format('m'),
                'm'     => $date->format('n'),
                'hh'    => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
                'h'     => (string) $hour,
                'nn'    => $date->format('i'),
                'n'     => (string) (int) $date->format('i'),
                'ss'    => $date->format('s'),
                's'     => (string) (int) $date->format('s'),
                'am/pm' => $date->format('A'),
                'a/p'   => substr($date->format('A'), 0, 1),
            };
            $afterHour = $token === 'h' || $token === 'hh';
        }

        return $out;
    }

    /**
     * Fichero: {delegacion, codigo} de un registro en vigor de la tabla de
     * AUTCFOR. OYACVAL guarda el texto de la lista de Veolab: código
     * formateado + nombre en clientes, proveedores y equipos; el nombre en el resto.
     */
    private static function fileValue(object $def, $value, string $name): array
    {
        $table = self::fileTable((string) $def->AUTCFOR);
        if (! $table) {
            throw new BusinessRuleException("El autodefinible '{$name}' apunta a un fichero no admitido");
        }
        if (! is_array($value) || ! isset($value['codigo']) || $value['codigo'] === ''
            || ! is_scalar($value['codigo']) || ! is_scalar($value['delegacion'] ?? '')) {
            throw new BusinessRuleException("El autodefinible '{$name}' se indica como {\"delegacion\": ..., \"codigo\": ...}");
        }

        [$codeColumn, $nameColumn, $inactiveColumn, $intCode, $showCode] = self::FILE_TABLES[$table];
        $del = (string) ($value['delegacion'] ?? '');
        $cod = (string) $value['codigo'];

        $row = null;
        if (! $intCode || ctype_digit($cod)) {
            $row = DB::connection('dynamic')->table($table)
                ->where('DEL3COD', $del)->where($codeColumn, $cod)
                ->first([$nameColumn, $inactiveColumn]);
        }
        if (! $row || $row->{$inactiveColumn} === 'T') {
            throw new BusinessRuleException("El registro del autodefinible '{$name}' no existe o está de baja");
        }

        $text = (string) $row->{$nameColumn};
        if ($showCode) {
            $text = VeolabCodes::format($table, $cod, $del).' '.$text;
        }

        return ['value' => $text !== '' ? $text : $cod, 'fileDel' => $del, 'fileCod' => $cod];
    }

    private static function fileTable(string $format): ?string
    {
        return self::FILE_TABLE_NAMES[mb_strtolower(trim($format))] ?? null;
    }
}
