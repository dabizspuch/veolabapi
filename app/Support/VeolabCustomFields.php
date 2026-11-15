<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Campos autodefinibles (LABAUT): de operación (tipo O; valores en LABOYA
 * para las operaciones y en LABPYA para las planificaciones) y de lote
 * (tipo L; valores en LABLYA), replicando FichaOperacion.frm
 * (AcumulaGrabarAutodefinibles, EjecutaGrabarAutodefinibles,
 * CamposObligatoriosCubiertos), FichaPlanificacion.frm, FichaLote.frm y
 * Autodefinibles.bas.
 *
 *  - En la API se identifican por NOMBRE (AUTCNOM): Veolab no deja repetirlo
 *    entre delegaciones. Solo valen los de la delegación del registro y los
 *    generales (DEL3COD = ''), nunca los de otra delegación; si un nombre
 *    estuviera en ambas, gana el de la delegación del registro.
 *  - Valor vacío (null / '') = se borra la fila de valores, como Veolab.
 *  - Fichero (F): se indica {delegacion, codigo}; se guarda la clave en
 *    OYA3DEL/OYA3COD y en OYACVAL el texto que muestra Veolab.
 *  - LABAYS (vínculo con servicios) solo decide lo que muestra Veolab: no
 *    restringe los valores (sí decide qué autodefinibles reciben valor
 *    predeterminado o fórmula, ver applyDefaults).
 *  - Desde Veolab 3, valor predeterminado y fórmulas (AUTCPRE) de los
 *    autodefinibles y de los campos fijos de recolección: applyDefaults.
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
     * Valores por tabla propietaria: operación (LABOYA, con fila "cero" y
     * auditoría también en el alta), planificación (LABPYA, mismas definiciones
     * de tipo O) y lote (LABLYA, definiciones de tipo L). Veolab solo audita
     * las dos últimas al modificar.
     */
    private const OWNERS = [
        'LABOPE' => ['table' => 'LABOYA', 'prefix' => 'OYA', 'keys' => ['OPE3DEL', 'OPE3SER', 'OPE3COD'],
            'type' => 'O', 'noun' => 'la operación', 'zeroRow' => true, 'auditNew' => true],
        'LABPLO' => ['table' => 'LABPYA', 'prefix' => 'PYA', 'keys' => ['PLO3DEL', 'PLO3COD'],
            'type' => 'O', 'noun' => 'la planificación', 'zeroRow' => false, 'auditNew' => false],
        'LABLOT' => ['table' => 'LABLYA', 'prefix' => 'LYA', 'keys' => ['LOT3DEL', 'LOT3SER', 'LOT3COD'],
            'type' => 'L', 'noun' => 'el lote', 'zeroRow' => false, 'auditNew' => false],
    ];

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
     * Valida y normaliza los autodefinibles recibidos ({nombre: valor}) para
     * un registro de la tabla $owner.
     * Devuelve [clave "del\x1Bcod" => ['def', 'value', 'fileDel', 'fileCod']].
     */
    public static function resolve(string $delegation, $input, string $owner = 'LABOPE'): array
    {
        if ($input === null || $input === []) {
            return [];
        }
        if (! is_array($input) || array_is_list($input)) {
            throw new BusinessRuleException('Los autodefinibles se indican como un objeto {"nombre": valor}');
        }

        $byName = [];
        foreach (self::definitions($delegation, $owner) as $def) {
            $byName[mb_strtolower(trim((string) $def->AUTCNOM))] = $def;
        }

        $out = [];
        foreach ($input as $name => $value) {
            $def = $byName[mb_strtolower(trim((string) $name))] ?? null;
            if (! $def) {
                $noun = self::OWNERS[$owner]['noun'];
                throw new BusinessRuleException("El autodefinible '{$name}' no existe para la delegación de {$noun}");
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
     * Guarda los valores resueltos de una operación, planificación o lote ($key =
     * valores de su clave en orden): borra e inserta solo los que cambian, sin
     * fila para los vacíos (EjecutaGrabarAutodefinibles), y audita cada valor
     * nuevo como modificación del campo "#<nombre>" de la tabla propietaria
     * (planificación y lote, como Veolab, solo al modificar).
     *
     * En operaciones asegura además la fila "cero" (AUT3DEL = '', AUT3COD = 0)
     * que Veolab crea en cada operación nueva y que necesitan sus listados con
     * columnas de autodefinibles (también repara operaciones que no la tengan).
     */
    public static function save(string $owner, array $key, array $resolved, string $auditRow, bool $isNew = false): void
    {
        $o = self::OWNERS[$owner];
        $db = DB::connection('dynamic');
        $ownerKey = array_combine($o['keys'], $key);
        [$valueCol, $fileDelCol, $fileCodCol] = self::valueColumns($owner);

        if ($o['zeroRow']) {
            $db->table($o['table'])->insertOrIgnore($ownerKey + [
                'AUT3DEL' => '', 'AUT3COD' => 0, $valueCol => '', $fileDelCol => '', $fileCodCol => '',
            ]);
        }

        if ($resolved === []) {
            return;
        }

        $previous = $db->table($o['table'])->where($ownerKey)->get()
            ->keyBy(fn ($row) => self::key($row->AUT3DEL, $row->AUT3COD));

        foreach ($resolved as $itemKey => $item) {
            $old = $previous[$itemKey] ?? null;
            $oldValue = (string) ($old->{$valueCol} ?? '');

            $unchanged = $old
                ? $oldValue === $item['value'] && (string) $old->{$fileDelCol} === $item['fileDel']
                    && (string) $old->{$fileCodCol} === $item['fileCod']
                : $item['value'] === '';
            if ($unchanged) {
                continue;
            }

            $where = $ownerKey + ['AUT3DEL' => (string) $item['def']->DEL3COD, 'AUT3COD' => (int) $item['def']->AUT1COD];
            $db->table($o['table'])->where($where)->delete();

            if ($item['value'] !== '') {
                $db->table($o['table'])->insert($where + [
                    $valueCol   => $item['value'],
                    $fileDelCol => $item['fileDel'],
                    $fileCodCol => $item['fileCod'],
                ]);
                if (! $isNew || $o['auditNew']) {
                    VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $owner, $auditRow,
                        '#'.$item['def']->AUTCNOM, $item['value'], $oldValue);
                }
            }
        }
    }

    /**
     * Valores guardados de un registro (operación, planificación o lote) en el formato de
     * resolve() (solo autodefinibles en vigor de la delegación o generales).
     * Sirve para copiar los de una planificación a la operación que genera.
     */
    public static function stored(string $owner, array $key, string $delegation): array
    {
        $o = self::OWNERS[$owner];
        [$valueCol, $fileDelCol, $fileCodCol] = self::valueColumns($owner);

        $definitions = [];
        foreach (self::definitions($delegation, $owner) as $def) {
            $definitions[self::key($def->DEL3COD, $def->AUT1COD)] = $def;
        }

        $out = [];
        $rows = DB::connection('dynamic')->table($o['table'])->where(array_combine($o['keys'], $key))->get();
        foreach ($rows as $row) {
            $itemKey = self::key($row->AUT3DEL, $row->AUT3COD);
            $value = (string) $row->{$valueCol};
            $fileCode = (string) $row->{$fileCodCol};
            if (! isset($definitions[$itemKey]) || ($value === '' && $fileCode === '')) {
                continue;
            }
            $out[$itemKey] = ['def' => $definitions[$itemKey], 'value' => $value,
                'fileDel' => (string) $row->{$fileDelCol}, 'fileCod' => $fileCode];
        }

        return $out;
    }

    /**
     * Valores para la respuesta de varios registros de la tabla $owner (lista
     * de claves en orden): [clave unida por "\x1B" => [nombre => valor]]. Los de
     * fichero salen como {delegacion, codigo}. Solo autodefinibles del tipo de
     * la tabla en vigor de su delegación o generales, como los muestra Veolab.
     */
    public static function valuesFor(string $owner, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $o = self::OWNERS[$owner];
        $t = $o['table'];
        [$valueCol, $fileDelCol, $fileCodCol] = self::valueColumns($owner);
        $delegationCol = $o['keys'][0];

        $rows = DB::connection('dynamic')->table($t)
            ->join('LABAUT', function ($join) use ($t) {
                $join->on("{$t}.AUT3DEL", '=', 'LABAUT.DEL3COD')->on("{$t}.AUT3COD", '=', 'LABAUT.AUT1COD');
            })
            ->where(function ($q) use ($keys, $o, $t) {
                foreach ($keys as $key) {
                    $q->orWhere(function ($w) use ($key, $o, $t) {
                        foreach ($o['keys'] as $i => $column) {
                            $w->where("{$t}.{$column}", $key[$i]);
                        }
                    });
                }
            })
            ->where('LABAUT.AUTCTIP', $o['type'])
            ->where(fn ($q) => $q->whereNull('LABAUT.AUTBCAT')->orWhere('LABAUT.AUTBCAT', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('LABAUT.AUTBBAJ')->orWhere('LABAUT.AUTBBAJ', '<>', 'T'))
            ->orderBy('LABAUT.DEL3COD')->orderBy('LABAUT.AUTNORD')
            ->get(array_merge(
                array_map(fn ($c) => "{$t}.{$c}", $o['keys']),
                ["{$t}.AUT3DEL", "{$t}.{$valueCol}", "{$t}.{$fileDelCol}", "{$t}.{$fileCodCol}",
                    'LABAUT.AUTCNOM', 'LABAUT.AUTCTDD', 'LABAUT.AUTCFOR']
            ));

        $out = [];
        foreach ($rows as $row) {
            if ($row->AUT3DEL !== '' && $row->AUT3DEL !== $row->{$delegationCol}) {
                continue;
            }
            $fileCode = (string) $row->{$fileCodCol};
            $value = (string) $row->{$valueCol};
            if ($value === '' && $fileCode === '') {
                continue;
            }

            if ($row->AUTCTDD === self::FILE && $fileCode !== '') {
                $table = self::fileTable((string) $row->AUTCFOR);
                $value = [
                    'delegacion' => (string) $row->{$fileDelCol},
                    'codigo'     => $table && self::FILE_TABLES[$table][3] ? (int) $fileCode : $fileCode,
                ];
            }

            // Orden por delegación: la propia pisa a la general.
            $ownerKey = implode("\x1B", array_map(fn ($c) => (string) $row->{$c}, $o['keys']));
            $out[$ownerKey][(string) $row->AUTCNOM] = $value;
        }

        return $out;
    }

    /**
     * Autodefinibles obligatorios de CONCCAO ("AU_<del>_<cod>.OYACVAL") que
     * quedarían vacíos en una operación: sus nombres. Se ignoran los de otra
     * delegación o que ya no están en vigor (Veolab no los muestra).
     * $opKey null = operación nueva.
     */
    public static function missingRequired(array $entries, string $delegation, ?array $opKey, array $resolved): array
    {
        if ($entries === []) {
            return [];
        }

        $definitions = [];
        foreach (self::definitions($delegation, 'LABOPE') as $def) {
            $definitions[self::key($def->DEL3COD, $def->AUT1COD)] = $def;
        }
        $stored = $opKey === null ? [] : self::stored('LABOPE', $opKey, $delegation);
        $values = $resolved + $stored;

        $missing = [];
        foreach ($entries as $entry) {
            // CAD_DescomponerParFinal: la delegación puede contener '_'.
            $pair = preg_replace('/^AU_|\.OYACVAL$/', '', $entry);
            $pos = strrpos($pair, '_');
            $itemKey = $pos === false ? self::key('', (int) $pair) : self::key(substr($pair, 0, $pos), (int) substr($pair, $pos + 1));

            if (! isset($definitions[$itemKey])) {
                continue;
            }
            $item = $values[$itemKey] ?? null;
            if (! $item || ($item['value'] === '' && $item['fileCod'] === '')) {
                $missing[] = (string) $definitions[$itemKey]->AUTCNOM;
            }
        }

        return $missing;
    }

    /** Columnas de valor, delegación y código de fichero (OYACVAL / PYACVAL...). */
    private static function valueColumns(string $owner): array
    {
        $prefix = self::OWNERS[$owner]['prefix'];

        return ["{$prefix}CVAL", "{$prefix}3DEL", "{$prefix}3COD"];
    }

    // ------------------------------------------------------------------

    /**
     * Autodefinibles en vigor del tipo de la tabla $owner utilizables en la
     * delegación (AUT_CargarAutodefinibles): generales primero y luego los de
     * la delegación, para que estos prevalezcan al indexar por nombre.
     */
    private static function definitions(string $delegation, string $owner): array
    {
        return DB::connection('dynamic')->table('LABAUT')
            ->where('AUTCTIP', self::OWNERS[$owner]['type'])
            ->whereIn('DEL3COD', array_unique(['', $delegation]))
            ->where(fn ($q) => $q->whereNull('AUTBCAT')->orWhere('AUTBCAT', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('AUTBBAJ')->orWhere('AUTBBAJ', '<>', 'T'))
            ->orderBy('DEL3COD')->orderBy('AUTNORD')
            ->get(array_merge(['DEL3COD', 'AUT1COD', 'AUTCNOM', 'AUTCTDD', 'AUTCFOR', 'AUTBVAL'],
                VeolabVersion::isV3() ? ['AUTCPRE'] : []))
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

    // ------------------------------------------------------------------
    // Valor predeterminado y fórmulas (LABAUT.AUTCPRE, desde Veolab 3)
    // ------------------------------------------------------------------

    /**
     * Campos fijos de recolección de las fichas de operación y planificación
     * (AUT_CAMPOS_FIJOS): columna de LABOPE (en LABPLO empieza por PLO). Su
     * configuración son filas de LABAUT con AUTCTDD = 'X' y la columna en
     * AUTCFOR: AUTBBAJ = T oculto, AUTCPRE valor predeterminado o fórmula.
     * EMPCNOM y PUMCDES (nombre del empleado recolector y del punto de muestreo)
     * no se leen aquí: no admiten predeterminado y no están en la tabla.
     */
    public const FIXED_FIELDS = ['EMPCNOM', 'OPECREC', 'OPECTIM', 'OPECLUR', 'PUMCDES', 'OPECLOT', 'OPECMAR', 'OPEDELA', 'OPEDCAD',
        'OPENENV', 'OPECTEM', 'OPECCLO', 'OPECPHS', 'OPECCDT', 'OPECCAN', 'OPECUNI', 'OPECOBS', 'OPECENV', 'OPECCNS', 'OPECTRA',
        'OPECCRE', 'OPECPED', 'OPECIAC', 'OPECDIR', 'OPENRED', 'OPENLOC'];

    /** Campos de recolección nuevos en Veolab 3 (AUT_CAMPOS_RECOLECCION): de baja mientras la delegación no los configure. */
    public const RECOLLECTION_FIELDS = ['OPEDELA', 'OPEDCAD', 'OPECCLO', 'OPECPHS', 'OPECCDT', 'OPECCRE', 'OPECIAC', 'OPECPED', 'OPECTRA', 'OPECCNS'];

    /** Campos fijos sin predeterminado ni fórmula: se eligen de una lista con código o se rellenan desde otro dato. */
    private const FIXED_WITHOUT_DEFAULT = ['EMPCNOM', 'OPECREC', 'OPECTIM', 'PUMCDES', 'OPECDIR', 'OPENRED', 'OPENLOC'];

    /** Nombre con el que field() lee cada campo fijo: su marcador de exportación (AUT_MarcadorCampoFijo). */
    private const FIXED_MARKERS = [
        'EMPCNOM' => 'empleadorecolectoroperacion', 'OPECREC' => 'recolectoroperacion', 'OPECTIM' => 'tipomuestreooperacion',
        'OPECLUR' => 'lugaroperacion', 'OPECLOT' => 'loteoperacion', 'OPECMAR' => 'marcaoperacion',
        'OPEDELA' => 'fechaelaboracionoperacion', 'OPEDCAD' => 'fechacaducidadoperacion', 'OPENENV' => 'numenvasesoperacion',
        'OPECTEM' => 'temperaturaoperacion', 'OPECCLO' => 'clorolibreoperacion', 'OPECPHS' => 'phoperacion',
        'OPECCDT' => 'conductividadoperacion', 'OPECCAN' => 'cantidadoperacion', 'OPECUNI' => 'unidadoperacion',
        'OPECOBS' => 'observacionesoperacion', 'OPECENV' => 'envaseoperacion', 'OPECCNS' => 'conservacionoperacion',
        'OPECTRA' => 'transporteoperacion', 'OPECCRE' => 'condicionesrecepcionoperacion', 'OPECPED' => 'pedidooperacion',
        'OPECIAC' => 'informacionclienteoperacion', 'OPECDIR' => 'direccionoperacion', 'OPENRED' => 'redoperacion',
        'OPENLOC' => 'localidadoperacion',
    ];

    /** Símbolo que precede al nombre de un autodefinible en field() (EXP_SIMBOLO_AUTODEFINIBLE). */
    private const CUSTOM_FIELD_SYMBOL = 'ª';

    private const FIXED_FIELD_TYPE = 'X';
    private const MAX_PASSES = 10;

    /**
     * Pone los valores predeterminados y calcula las fórmulas (AUTCPRE) de
     * los autodefinibles y de los campos fijos de recolección, como las
     * fichas de Veolab 3 (AUT_AplicarPredeterminados, AUT_RecalcularFormulas)
     * y su importación. En bases anteriores a 3.0 no hace nada.
     *
     *  - Registro nuevo: los valores fijos van a los campos vacíos (salvo en
     *    la copia de una planificación, como "generar"); las fórmulas se
     *    calculan solo para los campos vacíos, porque un valor recibido se
     *    respeta como escrito a mano (como al importar).
     *  - Modificación: las fórmulas se recalculan cuando la petición toca
     *    autodefinibles o campos de recolección (lo que la ficha hace al
     *    editar); los campos indicados en la petición no se recalculan.
     *  - Los autodefinibles vinculados a servicios solo reciben valor si el
     *    registro tiene alguno de ellos.
     *  - Varias pasadas para encadenar fórmulas, con tope para las circulares.
     *
     * @param  string      $owner       LABOPE, LABPLO o LABLOT.
     * @param  array       $given       Autodefinibles de la petición (resolve), que cuentan como manuales.
     * @param  array       $stored      Autodefinibles ya guardados o copiados (stored), con menor prioridad.
     * @param  array|null  $fixed       Campos fijos (columna OPE* => texto) tras aplicar la petición; null en lotes.
     * @param  array       $manualFixed Columnas OPE* indicadas en la petición (no se recalculan).
     * @param  array|null  $services    Servicios del registro [[delegación, código], ...]; null = sin servicios.
     * @param  bool        $isNew       Alta.
     * @param  bool        $applyValues Poner los valores predeterminados fijos (alta que no copia otro registro).
     * @return array{0: array, 1: array} Autodefinibles que cambian (formato de resolve) y campos fijos (columna OPE* => texto).
     */
    public static function applyDefaults(string $owner, string $delegation, array $given, array $stored, ?array $fixed,
        array $manualFixed, ?array $services, bool $isNew, bool $applyValues): array
    {
        if (! VeolabVersion::isV3()) {
            return [[], []];
        }

        $definitions = self::definitions($delegation, $owner);
        $withDefault = array_filter($definitions, fn ($def) => trim((string) ($def->AUTCPRE ?? '')) !== '');
        $fixedConfig = $fixed !== null ? self::fixedFieldConfig($delegation) : [];
        if ($withDefault === [] && $fixedConfig === []) {
            return [[], []];
        }
        $fixed ??= [];

        $values = $given + $stored;
        $text = fn (string $key): string => isset($values[$key]) ? (string) $values[$key]['value'] : '';
        $isEmpty = fn (string $key): bool => $text($key) === '' && (string) ($values[$key]['fileCod'] ?? '') === '';
        $links = self::serviceLinks(array_values($withDefault));
        $belongs = function (string $key) use ($links, $services): bool {
            if (empty($links[$key])) {
                return true;
            }
            if ($services === null) {
                return false;
            }
            foreach ($services as [$del, $cod]) {
                if (in_array($del."\x1B".$cod, $links[$key], true)) {
                    return true;
                }
            }

            return false;
        };
        $visible = fn (string $column): bool => array_key_exists($column, $fixedConfig)
            ? $fixedConfig[$column]['hidden'] === false
            : ! in_array($column, self::RECOLLECTION_FIELDS, true);

        $changedCustom = [];
        $changedFixed = [];

        // Valores predeterminados fijos en los campos vacíos de un registro nuevo
        if ($isNew && $applyValues) {
            foreach ($withDefault as $def) {
                $key = self::key($def->DEL3COD, $def->AUT1COD);
                if (VeolabFieldFormulas::isFormula($def->AUTCPRE) || ! $isEmpty($key) || ! $belongs($key)) {
                    continue;
                }
                $value = self::defaultValue($def);
                if ($value !== '') {
                    $values[$key] = ['def' => $def, 'value' => $value, 'fileDel' => '', 'fileCod' => ''];
                    $changedCustom[$key] = true;
                }
            }
            foreach ($fixedConfig as $column => $config) {
                if (VeolabFieldFormulas::isFormula($config['default']) || ! self::fixedAdmitsDefault($column)
                    || ! $visible($column) || trim((string) ($fixed[$column] ?? '')) !== '') {
                    continue;
                }
                $value = self::fixedDefaultValue($column, $config['default']);
                if ($value !== '') {
                    $fixed[$column] = $value;
                    $changedFixed[$column] = $value;
                }
            }
        }

        // Fórmulas
        $hasFormulas = false;
        foreach ($withDefault as $def) {
            $hasFormulas = $hasFormulas || VeolabFieldFormulas::isFormula($def->AUTCPRE);
        }
        foreach ($fixedConfig as $config) {
            $hasFormulas = $hasFormulas || VeolabFieldFormulas::isFormula($config['default']);
        }
        $trigger = $isNew || $given !== [] || $manualFixed !== [];
        if ($hasFormulas && $trigger) {
            $onlyEmpty = $isNew;
            $evaluateEmpty = DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONBEFD') === 'T';

            $dictionary = [];
            foreach ($definitions as $def) {
                $dictionary[self::formulaKey((string) $def->AUTCNOM)] = $text(self::key($def->DEL3COD, $def->AUT1COD));
            }
            foreach (self::FIXED_MARKERS as $column => $marker) {
                if (array_key_exists($column, $fixed) && $visible($column)) {
                    $dictionary[$marker] = (string) $fixed[$column];
                }
            }

            for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
                $changed = false;
                foreach ($withDefault as $def) {
                    $key = self::key($def->DEL3COD, $def->AUT1COD);
                    if (! VeolabFieldFormulas::isFormula($def->AUTCPRE) || isset($given[$key]) || $def->AUTCTDD === self::FILE || ! $belongs($key)) {
                        continue;
                    }
                    if ($onlyEmpty && $text($key) !== '' && ! isset($changedCustom[$key])) {
                        continue;
                    }
                    $value = self::formatResult($def, VeolabFieldFormulas::evaluate((string) $def->AUTCPRE, $dictionary, $evaluateEmpty));
                    if ($value !== $text($key)) {
                        $values[$key] = ['def' => $def, 'value' => $value, 'fileDel' => '', 'fileCod' => ''];
                        $dictionary[self::formulaKey((string) $def->AUTCNOM)] = $value;
                        $changedCustom[$key] = true;
                        $changed = true;
                    }
                }
                foreach ($fixedConfig as $column => $config) {
                    $marker = self::FIXED_MARKERS[$column] ?? '';
                    if (! VeolabFieldFormulas::isFormula($config['default']) || $marker === '' || ! self::fixedAdmitsDefault($column)
                        || in_array($column, $manualFixed, true) || ! array_key_exists($marker, $dictionary)) {
                        continue;
                    }
                    if ($onlyEmpty && $dictionary[$marker] !== '' && ! array_key_exists($column, $changedFixed)) {
                        continue;
                    }
                    $value = self::formatFixedResult($column, VeolabFieldFormulas::evaluate((string) $config['default'], $dictionary, $evaluateEmpty));
                    if ($value !== $dictionary[$marker]) {
                        $dictionary[$marker] = $value;
                        $fixed[$column] = $value;
                        $changedFixed[$column] = $value;
                        $changed = true;
                    }
                }
                if (! $changed) {
                    break;
                }
            }
        }

        $customOut = [];
        foreach (array_keys($changedCustom) as $key) {
            $customOut[$key] = $values[$key];
        }

        return [$customOut, $changedFixed];
    }

    /**
     * Textos de los campos fijos de un registro para las fórmulas (columna
     * OPE* => texto como lo muestra la ficha): los de la petición ($data, en
     * parámetros de la API) sobre los guardados ($current). $mapping es el
     * del controlador (parámetro => columna); en LABPLO las columnas
     * empiezan por PLO.
     */
    public static function fixedValues(string $owner, array $data, ?object $current, array $mapping): array
    {
        $params = array_flip($mapping);
        $out = [];
        foreach (self::FIXED_FIELDS as $field) {
            if (! str_starts_with($field, 'OPE')) {
                continue;
            }
            $column = self::fixedColumn($field, $owner);
            $param = $params[$column] ?? null;
            if ($param === null) {
                continue;
            }
            $value = array_key_exists($param, $data) ? $data[$param] : ($current->{$column} ?? null);
            $out[$field] = self::fixedText($field, $value);
        }

        return $out;
    }

    /**
     * Columnas OPE* de los campos fijos que trae la petición (parámetros de
     * la API presentes en $data), para no recalcular sus fórmulas.
     */
    public static function fixedGiven(string $owner, array $data, array $mapping): array
    {
        $out = [];
        foreach (self::FIXED_FIELDS as $field) {
            $param = array_search(self::fixedColumn($field, $owner), $mapping, true);
            if ($param !== false && array_key_exists($param, $data)) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * Cambios de campos fijos (columna OPE* => texto, de applyDefaults) como
     * parámetros de la API con el valor que se graba: las fechas de
     * recolección en fecha y hora, los números como números.
     */
    public static function fixedToData(string $owner, array $changes, array $mapping): array
    {
        $params = array_flip($mapping);
        $out = [];
        foreach ($changes as $field => $text) {
            $param = $params[self::fixedColumn($field, $owner)] ?? null;
            if ($param === null) {
                continue;
            }
            if (self::isRecollectionDate($field)) {
                $date = self::interpretDate($text);
                $out[$param] = $date ? $date->format('Y-m-d 00:00:00') : null;
            } elseif ($field[3] === 'N') {
                $out[$param] = trim($text) === '' ? null : VeolabFormat::toDouble($text);
            } else {
                $out[$param] = $text;
            }
        }

        return $out;
    }

    /** Columna del campo fijo en la tabla: LABPLO cambia el prefijo OPE por PLO. */
    private static function fixedColumn(string $field, string $owner): string
    {
        return $owner === 'LABPLO' ? 'PLO'.substr($field, 3) : $field;
    }

    /** Texto con el que la ficha muestra un valor guardado de un campo fijo. */
    private static function fixedText(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (self::isRecollectionDate($field)) {
            $date = self::parseDate(trim((string) $value)) ?? self::interpretDate((string) $value);

            return $date ? $date->format('d/m/Y') : '';
        }
        if ($field[3] === 'N') {
            return is_numeric($value) ? VeolabFormat::cstr((float) $value) : (string) $value;
        }

        return (string) $value;
    }

    private static function isRecollectionDate(string $field): bool
    {
        return in_array($field, self::RECOLLECTION_FIELDS, true) && $field[3] === 'D';
    }

    private static function fixedAdmitsDefault(string $field): bool
    {
        return ! in_array($field, self::FIXED_WITHOUT_DEFAULT, true);
    }

    /** Clave de un autodefinible en field(): el símbolo de exportación y el nombre, en minúscula. */
    private static function formulaKey(string $name): string
    {
        return mb_strtolower(self::CUSTOM_FIELD_SYMBOL.trim($name));
    }

    /**
     * Configuración de los campos fijos (filas X de LABAUT) utilizable en la
     * delegación: columna OPE* => ['default', 'hidden']. La de la delegación
     * prevalece sobre la común.
     */
    private static function fixedFieldConfig(string $delegation): array
    {
        $rows = DB::connection('dynamic')->table('LABAUT')
            ->where('AUTCTDD', self::FIXED_FIELD_TYPE)
            ->whereIn('DEL3COD', array_unique(['', $delegation]))
            ->orderBy('DEL3COD')
            ->get(['DEL3COD', 'AUTCFOR', 'AUTBBAJ', 'AUTCPRE']);

        $out = [];
        foreach ($rows as $row) {
            $column = trim((string) $row->AUTCFOR);
            if (! isset(self::FIXED_MARKERS[$column])) {
                continue;
            }
            // Orden por delegación: la propia ('' va antes) pisa a la común
            $out[$column] = ['default' => (string) ($row->AUTCPRE ?? ''), 'hidden' => $row->AUTBBAJ === 'T'];
        }

        return $out;
    }

    /** Servicios vinculados (LABAYS) de los autodefinibles: clave => ["del\x1Bcod", ...]. */
    private static function serviceLinks(array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }
        $rows = DB::connection('dynamic')->table('LABAYS')
            ->where(function ($q) use ($definitions) {
                foreach ($definitions as $def) {
                    $q->orWhere(fn ($w) => $w->where('AUT3DEL', (string) $def->DEL3COD)->where('AUT3COD', (int) $def->AUT1COD));
                }
            })
            ->get(['AUT3DEL', 'AUT3COD', 'SER3DEL', 'SER3COD']);

        $out = [];
        foreach ($rows as $row) {
            $out[self::key($row->AUT3DEL, $row->AUT3COD)][] = (string) $row->SER3DEL."\x1B".(string) $row->SER3COD;
        }

        return $out;
    }

    /** AUT_ValorPredeterminado: valor fijo que se pone al crear (vacío en las fórmulas y en los ficheros). */
    private static function defaultValue(object $def): string
    {
        $default = (string) $def->AUTCPRE;
        if (trim($default) === '' || VeolabFieldFormulas::isFormula($default)) {
            return '';
        }

        return match ($def->AUTCTDD) {
            self::DATE, self::DATE_WARNING => ($date = self::interpretDate($default))
                ? self::vbDateFormat($date, trim((string) $def->AUTCFOR)) : '',
            self::FILE => '',
            default => $default,
        };
    }

    /** Valor fijo de un campo fijo: las fechas de recolección se interpretan como escritas a mano. */
    private static function fixedDefaultValue(string $field, string $default): string
    {
        if (self::isRecollectionDate($field)) {
            $date = self::interpretDate($default);

            return $date ? $date->format('d/m/Y') : '';
        }

        return $default;
    }

    /** AUT_FormatearResultado: el resultado de una fórmula con el formato del autodefinible. */
    private static function formatResult(object $def, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $format = trim((string) $def->AUTCFOR);
        try {
            switch ($def->AUTCTDD) {
                case self::NUMBER:
                    return $format !== '' && VeolabFormat::isNumeric($value) ? VeolabFormat::conditional($value, $format) : $value;
                case self::DATE:
                case self::DATE_WARNING:
                    $date = self::interpretDate($value);

                    return $date ? self::vbDateFormat($date, $format) : $value;
            }
        } catch (\Throwable) {
            // Un formato que no se puede aplicar deja el valor (On Error Resume Next)
        }

        return $value;
    }

    /** Resultado de una fórmula en un campo fijo: las fechas de recolección en dd/mm/aaaa, o vacío si no es fecha. */
    private static function formatFixedResult(string $field, string $value): string
    {
        if ($value === '' || ! self::isRecollectionDate($field)) {
            return $value;
        }
        $date = self::interpretDate($value);

        return $date ? $date->format('d/m/Y') : '';
    }

    /**
     * AUT_InterpretarFecha: fecha escrita a mano (día, mes y año): "hoy", 15/3
     * (año actual), 15/3/26, 15-03-2026, 15.3.26, 1503, 150326 o 15032026.
     * Los años de dos cifras: menores de 50 en 2000, el resto en 1900.
     */
    public static function interpretDate(string $text): ?DateTimeImmutable
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }
        $today = new DateTimeImmutable('today');
        if (in_array($text, ['hoy', 'today', 'avui', 'hoxe'], true)) {
            return $today;
        }

        $text = str_replace(['-', '.', ' '], '/', $text);
        if (str_contains($text, '/')) {
            $parts = explode('/', $text);
            if (count($parts) < 2 || count($parts) > 3) {
                return null;
            }
            foreach ($parts as $part) {
                if ($part === '' || ! ctype_digit($part)) {
                    return null;
                }
            }
            $day = (int) $parts[0];
            $month = (int) $parts[1];
            $year = count($parts) === 3 ? (int) $parts[2] : (int) $today->format('Y');
        } elseif (ctype_digit($text)) {
            $day = (int) substr($text, 0, 2);
            $month = (int) substr($text, 2, 2);
            $year = match (strlen($text)) {
                4 => (int) $today->format('Y'),
                6, 8 => (int) substr($text, 4),
                default => null,
            };
            if ($year === null) {
                return null;
            }
        } else {
            return null;
        }

        if ($year < 100) {
            $year += $year < 50 ? 2000 : 1900;
        }
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 1900 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return $today->setDate($year, $month, $day);
    }
}
