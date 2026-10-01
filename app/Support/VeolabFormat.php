<?php

namespace App\Support;

/**
 * Conversiones y formatos de VB6 con la configuración regional del
 * laboratorio (separador decimal de config veolab.decimal_separator; el de
 * miles es el otro), como los aplica Veolab a los resultados:
 *  - CDbl / IsNumeric / GEN_Decimal / CLng y CStr (VeolabResults::vbString).
 *  - CAD_Formato (con cifras significativas "SSS"), CAD_FormatoCondicional
 *    ("F(<condición>|<formato1>|<formato2>)") y CAD_Redondear.
 *  - Format: formatos numéricos (0 # . , % E+ E-, literales y secciones con
 *    ';', y Fixed, Standard, Percent, Scientific, General Number), de fecha y
 *    hora (d dd ddd dddd m mm mmm mmmm yy yyyy h hh n nn s ss, Short Date,
 *    Short Time, Long Time, General Date) y < > en textos. Un formato que no
 *    se reconoce deja el valor como está.
 */
class VeolabFormat
{
    private const INFINITESIMAL = 0.000001;

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    // ------------------------------------------------------------------
    // Conversiones
    // ------------------------------------------------------------------

    public static function decimalSeparator(): string
    {
        return VeolabResults::decimalSeparator();
    }

    public static function groupSeparator(): string
    {
        return self::decimalSeparator() === ',' ? '.' : ',';
    }

    /**
     * CDbl de VB con la configuración regional: separador decimal del
     * laboratorio y el otro como separador de miles (que VB ignora: con coma
     * decimal "1.5" es 15). Null si no es numérico (IsNumeric falso).
     */
    public static function toDouble($value): ?float
    {
        $text = trim((string) $value);
        $decimal = preg_quote(self::decimalSeparator(), '/');
        $group = preg_quote(self::groupSeparator(), '/');
        if ($text === '' || ! preg_match("/^([+-]?)\\s*(\\d[\\d{$group}]*)?(?:{$decimal}(\\d*))?(?:[eEdD]([+-]?\\d+))?$/", $text, $m)) {
            return null;
        }

        $integer = str_replace(self::groupSeparator(), '', $m[2] ?? '');
        $fraction = $m[3] ?? '';
        if ($integer === '' && $fraction === '') {
            return null;
        }
        $exponent = ($m[4] ?? '') !== '' ? 'e'.$m[4] : '';
        $number = (float) (($integer === '' ? '0' : $integer).'.'.($fraction === '' ? '0' : $fraction).$exponent);
        if (! is_finite($number)) {
            return null;
        }

        return $m[1] === '-' ? -$number : $number;
    }

    public static function isNumeric($value): bool
    {
        return self::toDouble($value) !== null;
    }

    /** GEN_Decimal: CDbl, o 0 si no es numérico. */
    public static function value($value): float
    {
        return self::toDouble($value) ?? 0.0;
    }

    /** CStr de un Double. */
    public static function cstr(float $number): string
    {
        return VeolabResults::vbString($number);
    }

    /** CLng: redondeo bancario; fuera de rango es un error de desbordamiento. */
    public static function toLong(float $number): int
    {
        $rounded = round($number, 0, PHP_ROUND_HALF_EVEN);
        if (! is_finite($rounded) || $rounded > 2147483647 || $rounded < -2147483648) {
            throw new \OverflowException('Desbordamiento');
        }

        return (int) $rounded;
    }

    /** CAD_Redondear: redondeo comercial (mitad hacia arriba, simétrico). */
    public static function round(float $value, int $decimals = 0): float
    {
        $factor = 10.0 ** $decimals;
        $result = $value >= 0
            ? floor($value * $factor + 0.5) / $factor
            : -floor(-$value * $factor + 0.5) / $factor;
        if (! is_finite($result)) {
            throw new \OverflowException('Desbordamiento');
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Formatos de Veolab
    // ------------------------------------------------------------------

    /**
     * CAD_FormatoCondicional: "F(<condición>|<formato1>|<formato2>)" aplica
     * el primer formato si el valor cumple la condición (>=, <=, <>, >, <, =
     * y un número) y el segundo si no; los valores "<n"/">n" cuentan como n
     * menos/más un infinitesimal. Cualquier otro formato se aplica tal cual.
     */
    public static function conditional(string $text, string $format): string
    {
        if (! str_starts_with($format, 'F(')) {
            return self::format($text, $format);
        }

        $length = mb_strlen($format);
        if ($length < 3) {
            throw new \InvalidArgumentException('Formato condicional no válido');
        }
        [$condition, $rest] = self::splitPair(mb_substr($format, 2, $length - 3), '|');
        [$format1, $format2] = self::splitPair($rest, '|');

        $number = self::value($text);
        if (str_starts_with(trim($text), '<')) {
            $number = self::value(mb_substr($text, 1)) - self::INFINITESIMAL;
        }
        if (str_starts_with(trim($text), '>')) {
            $number = self::value(mb_substr($text, 1)) + self::INFINITESIMAL;
        }

        foreach (['>=', '<=', '<>', '>', '<', '='] as $operator) {
            if (! str_starts_with($condition, $operator)) {
                continue;
            }
            $limit = self::value(mb_substr($condition, strlen($operator)));
            $holds = match ($operator) {
                '>=' => $number >= $limit,
                '<=' => $number <= $limit,
                '<>' => $number != $limit,
                '>'  => $number > $limit,
                '<'  => $number < $limit,
                '='  => $number == $limit,
            };

            return self::format($text, $holds ? $format1 : $format2);
        }

        return ''; // condición desconocida
    }

    /**
     * CAD_Formato: Format conservando un prefijo "<" o ">" del valor; con un
     * formato de solo "S" redondea a ese número de cifras significativas.
     */
    public static function format(string $text, string $format): string
    {
        $prefix = '';
        $text = trim($text, ' ');
        if (mb_strlen($text) > 1) {
            $first = mb_substr($text, 0, 1);
            if ($first === '<' || $first === '>') {
                $prefix = mb_substr($text, 1, 1) === ' ' ? $first.' ' : $first;
                $text = trim(mb_substr($text, 1), ' ');
            }
        }

        $length = mb_strlen($format);
        if ($length > 0 && $format === str_repeat('S', $length)) {
            return self::significant($text, $length, $prefix);
        }

        return $prefix.self::vbFormat($text, $format);
    }

    /** Rama de cifras significativas de CAD_Formato (incluido su cálculo con CStr). */
    private static function significant(string $text, int $digits, string $prefix): string
    {
        try {
            $number = self::round(self::value($text), 15);
            $string = self::cstr($number);
            $position = mb_strpos($string, self::decimalSeparator());
            $power = $position === false ? 1.0 : 10.0 ** (mb_strlen($string) - ($position + 1));

            $withoutDecimals = $number * $power;
            $string = self::cstr($withoutDecimals);
            if ($digits < mb_strlen($string)) {
                $scale = 10.0 ** (mb_strlen($string) - $digits);
                $rounded = self::round($withoutDecimals / $scale, 0) * $scale;
            } else {
                $rounded = $withoutDecimals;
            }
            $number = $rounded / $power;
            if (! is_finite($number)) {
                return '';
            }

            return $prefix.self::cstr($number);
        } catch (\OverflowException) {
            return ''; // desbordamiento: CAD_Formato devuelve vacío
        }
    }

    // ------------------------------------------------------------------
    // Format de VB
    // ------------------------------------------------------------------

    /**
     * Format(texto, formato): un texto numérico se formatea como número, una
     * fecha u hora como fecha; un texto, con < (minúsculas) o > (mayúsculas).
     */
    public static function vbFormat(string $text, string $format): string
    {
        if ($format === '') {
            return $text;
        }

        $number = self::toDouble($text);
        if ($number !== null) {
            return self::formatNumber($number, $format) ?? $text;
        }

        $date = self::parseDate($text);
        if ($date !== null) {
            return self::formatDate($date, $format) ?? $text;
        }

        if (preg_match('/^[@&<>!]+$/', $format)) {
            if (str_contains($format, '>')) {
                return mb_strtoupper($text);
            }
            if (str_contains($format, '<')) {
                return mb_strtolower($text);
            }
        }

        return $text;
    }

    /** Formato numérico; null si el formato no es numérico. */
    private static function formatNumber(float $number, string $format): ?string
    {
        $named = [
            'general number' => null,
            'fixed'          => '0.00',
            'standard'       => '#,##0.00',
            'percent'        => '0.00%',
            'scientific'     => '0.00E+00',
        ];
        $key = strtolower(trim($format));
        if (array_key_exists($key, $named)) {
            return $named[$key] === null ? self::cstr($number) : self::formatNumber($number, $named[$key]);
        }

        $sections = self::splitSections($format);
        if ($number == 0 && count($sections) >= 3 && $sections[2] !== '') {
            [$section, $value, $sign] = [$sections[2], 0.0, ''];
        } elseif ($number < 0 && count($sections) >= 2 && $sections[1] !== '') {
            [$section, $value, $sign] = [$sections[1], -$number, ''];
        } else {
            [$section, $value, $sign] = [$sections[0], abs($number), $number < 0 ? '-' : ''];
        }

        return self::formatSection($value, $section, $sign);
    }

    /** Secciones de un formato separadas por ';' (fuera de comillas y escapes). */
    private static function splitSections(string $format): array
    {
        $sections = [''];
        $chars = mb_str_split($format);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $char = $chars[$i];
            if ($char === '\\' && $i + 1 < $n) {
                $sections[count($sections) - 1] .= $char.$chars[++$i];
            } elseif ($char === '"') {
                $end = $i + 1;
                while ($end < $n && $chars[$end] !== '"') {
                    $end++;
                }
                $sections[count($sections) - 1] .= implode('', array_slice($chars, $i, $end - $i + 1));
                $i = $end;
            } elseif ($char === ';') {
                $sections[] = '';
            } else {
                $sections[count($sections) - 1] .= $char;
            }
        }

        return $sections;
    }

    /** Una sección de formato numérico aplicada a un valor no negativo. */
    private static function formatSection(float $value, string $section, string $sign): ?string
    {
        // Elementos: [tipo, texto]; tipos: zero, opt (dígitos), dec, comma, pct, exp, lit.
        $tokens = [];
        $chars = mb_str_split($section);
        $hasDecimal = false;
        $hasExponent = false;
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $char = $chars[$i];
            if ($char === '\\') {
                $tokens[] = ['lit', $chars[++$i] ?? ''];
            } elseif ($char === '"') {
                $literal = '';
                while (++$i < $n && $chars[$i] !== '"') {
                    $literal .= $chars[$i];
                }
                $tokens[] = ['lit', $literal];
            } elseif ($char === '0' || $char === '#') {
                $tokens[] = [$char === '0' ? 'zero' : 'opt', $char];
            } elseif ($char === '.' && ! $hasDecimal && ! $hasExponent) {
                $tokens[] = ['dec', $char];
                $hasDecimal = true;
            } elseif ($char === ',' && ! $hasExponent) {
                $tokens[] = ['comma', $char];
            } elseif ($char === '%') {
                $tokens[] = ['pct', $char];
            } elseif (($char === 'E' || $char === 'e') && in_array($chars[$i + 1] ?? '', ['+', '-'], true) && ! $hasExponent) {
                $tokens[] = ['exp', $char.$chars[++$i]];
                $hasExponent = true;
            } else {
                $tokens[] = ['lit', $char];
            }
        }

        // Zonas: entera (antes de dec/exp), decimal (tras dec) y exponente (tras exp).
        $zone = 'int';
        $intPlaces = [];
        $fracPlaces = [];
        $expPlaces = [];
        foreach ($tokens as $index => [$type]) {
            if ($type === 'dec') {
                $zone = 'frac';
            } elseif ($type === 'exp') {
                $zone = 'exp';
            } elseif (($type === 'zero' || $type === 'opt') && $zone === 'int') {
                $intPlaces[] = $index;
            } elseif ($type === 'zero' || $type === 'opt') {
                if ($zone === 'frac') {
                    $fracPlaces[] = $index;
                } else {
                    $expPlaces[] = $index;
                }
            }
        }
        if (! $intPlaces && ! $fracPlaces && preg_match('/[dmyhnsDMYHNS]/', $section)) {
            return null; // formato de fecha sobre un número
        }

        // Comas: separador de miles entre dígitos enteros; tras el último, escala (/1000).
        $grouping = false;
        $scale = 0;
        foreach ($tokens as $index => [$type]) {
            if ($type !== 'comma') {
                continue;
            }
            if ($intPlaces && $index > $intPlaces[0] && $index < end($intPlaces)) {
                $grouping = true;
                $tokens[$index][0] = 'skip';
            } elseif ($intPlaces && $index > end($intPlaces) && ! self::digitBetween($tokens, end($intPlaces), $index)
                && ($fracPlaces === [] || $index < $fracPlaces[0]) && ($expPlaces === [] || $index < $expPlaces[0])) {
                $scale++;
                $tokens[$index][0] = 'skip';
            } else {
                $tokens[$index] = ['lit', self::groupSeparator()];
            }
        }

        foreach ($tokens as [$type]) {
            if ($type === 'pct') {
                $value *= 100;
            }
        }
        $value /= 1000 ** $scale;
        if (! is_finite($value)) {
            return null;
        }

        $fracCount = count($fracPlaces);
        $fracRequired = 0;
        foreach ($fracPlaces as $position => $index) {
            if ($tokens[$index][0] === 'zero') {
                $fracRequired = $position + 1;
            }
        }
        $intMinimum = 0;
        foreach ($intPlaces as $position => $index) {
            if ($tokens[$index][0] === 'zero') {
                $intMinimum = count($intPlaces) - $position;
                break;
            }
        }

        $exponentText = '';
        if ($hasExponent) {
            $intCount = max(1, count($intPlaces));
            $exponent = 0;
            if ($value != 0) {
                $exponent = (int) floor(log10($value)) - ($intCount - 1);
            }
            [$intDigits, $fracDigits] = self::roundDigits($value / 10 ** $exponent, $fracCount);
            if (strlen(ltrim($intDigits, '0')) > $intCount) {
                $exponent++;
                [$intDigits, $fracDigits] = self::roundDigits($value / 10 ** $exponent, $fracCount);
            }
            $expZeros = 0;
            foreach ($expPlaces as $index) {
                $expZeros += $tokens[$index][0] === 'zero' ? 1 : 0;
            }
            $expSign = '';
            foreach ($tokens as [$type, $text]) {
                if ($type === 'exp') {
                    $expSign = $exponent < 0 ? '-' : ($text[1] === '+' ? '+' : '');
                    $exponentText = $text[0].$expSign.str_pad((string) abs($exponent), $expZeros, '0', STR_PAD_LEFT);
                }
            }
        } else {
            [$intDigits, $fracDigits] = self::roundDigits($value, $fracCount);
        }

        // Parte entera.
        if ($intDigits === '0' && $intMinimum === 0) {
            $intDigits = '';
        }
        $intDigits = str_pad($intDigits, $intMinimum, '0', STR_PAD_LEFT);
        if ($grouping) {
            $intDigits = self::group($intDigits);
        }

        // Parte decimal: los '#' finales no muestran ceros.
        $shown = $fracDigits;
        while (strlen($shown) > $fracRequired && str_ends_with($shown, '0')) {
            $shown = substr($shown, 0, -1);
        }

        $out = $sign;
        $intWritten = false;
        $fracPosition = 0;
        foreach ($tokens as $index => [$type, $text]) {
            switch ($type) {
                case 'zero':
                case 'opt':
                    if (in_array($index, $intPlaces, true)) {
                        if (! $intWritten) {
                            $out .= $intDigits;
                            $intWritten = true;
                        }
                    } elseif (in_array($index, $fracPlaces, true)) {
                        $out .= $shown[$fracPosition] ?? '';
                        $fracPosition++;
                    }
                    break;
                case 'dec':
                    if (! $intWritten) {
                        $out .= $intDigits;
                        $intWritten = true;
                    }
                    $out .= self::decimalSeparator();
                    break;
                case 'exp':
                    $out .= $exponentText;
                    break;
                case 'pct':
                    $out .= '%';
                    break;
                case 'lit':
                    $out .= $text;
                    break;
            }
        }

        return $out;
    }

    private static function digitBetween(array $tokens, int $from, int $to): bool
    {
        for ($i = $from + 1; $i < $to; $i++) {
            if (in_array($tokens[$i][0], ['zero', 'opt', 'dec'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redondea un valor no negativo a $places decimales como Format: sobre su
     * representación decimal de 15 cifras, mitad hacia arriba. Devuelve
     * [parte entera, parte decimal de $places dígitos].
     */
    private static function roundDigits(float $value, int $places): array
    {
        [$mantissa, $exponent] = explode('e', sprintf('%.14e', abs($value)));
        $digits = str_replace('.', '', $mantissa);
        $point = (int) $exponent + 1;
        if ($point <= 0) {
            $digits = str_repeat('0', 1 - $point).$digits;
            $point = 1;
        }
        $keep = $point + $places;
        $digits = str_pad($digits, max($keep, $point), '0');

        $roundUp = strlen($digits) > $keep && $digits[$keep] >= '5';
        $digits = substr($digits, 0, $keep);
        if ($roundUp) {
            $i = $keep - 1;
            while ($i >= 0 && $digits[$i] === '9') {
                $digits[$i] = '0';
                $i--;
            }
            if ($i < 0) {
                $digits = '1'.$digits;
                $point++;
            } else {
                $digits[$i] = (string) ((int) $digits[$i] + 1);
            }
        }

        $integer = ltrim(substr($digits, 0, $point), '0');

        return [$integer === '' ? '0' : $integer, (string) substr($digits, $point)];
    }

    private static function group(string $digits): string
    {
        $out = '';
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && ($length - $i) % 3 === 0) {
                $out .= self::groupSeparator();
            }
            $out .= $digits[$i];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Fechas
    // ------------------------------------------------------------------

    /**
     * Fecha u hora de un texto (dd/mm/aaaa [hh:mm[:ss]] o hh:mm[:ss]):
     * [año, mes, día, hora, minuto, segundo, tiene fecha] o null.
     */
    private static function parseDate(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $text, $m)) {
            $year = (int) $m[3];
            if (strlen($m[3]) <= 2) {
                $year += $year < 30 ? 2000 : 1900;
            }
            if (! checkdate((int) $m[2], (int) $m[1], $year)) {
                return null;
            }

            return [$year, (int) $m[2], (int) $m[1], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), true];
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $text, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return [1899, 12, 30, (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0), false];
        }

        return null;
    }

    /** Formato de fecha/hora; null si el formato no es de fecha. */
    private static function formatDate(array $date, string $format): ?string
    {
        [$year, $month, $day, $hour, $minute, $second, $hasDate] = $date;

        $named = [
            'short date'  => 'dd/mm/yyyy',
            'short time'  => 'hh:nn',
            'long time'   => 'h:nn:ss',
            'medium time' => 'hh:nn',
        ];
        $key = strtolower(trim($format));
        if ($key === 'general date') {
            $time = $hour || $minute || $second ? sprintf('%d:%02d:%02d', $hour, $minute, $second) : '';
            if (! $hasDate) {
                return $time === '' ? '0:00:00' : $time;
            }

            return trim(sprintf('%02d/%02d/%04d', $day, $month, $year).' '.$time);
        }
        if (isset($named[$key])) {
            $format = $named[$key];
        } elseif (preg_match('/[0#]/', $format)) {
            return null;
        }

        $chars = mb_str_split($format);
        $out = '';
        $lastWasHour = false;
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $char = $chars[$i];
            if ($char === '\\') {
                $out .= $chars[++$i] ?? '';
                continue;
            }
            if ($char === '"') {
                while (++$i < $n && $chars[$i] !== '"') {
                    $out .= $chars[$i];
                }
                continue;
            }

            $lower = mb_strtolower($char);
            $run = 1;
            while ($i + $run < $n && mb_strtolower($chars[$i + $run]) === $lower) {
                $run++;
            }

            switch ($lower) {
                case 'd':
                    $weekday = (int) date('w', mktime(0, 0, 0, $month, $day, $year));
                    $out .= match (true) {
                        $run === 1 => (string) $day,
                        $run === 2 => sprintf('%02d', $day),
                        $run === 3 => mb_substr(self::WEEKDAYS[$weekday], 0, 3),
                        default    => self::WEEKDAYS[$weekday],
                    };
                    break;
                case 'm':
                    $isMinute = $run <= 2 && ($lastWasHour || self::nextIsSecond($chars, $i + $run));
                    if ($isMinute) {
                        $out .= $run === 1 ? (string) $minute : sprintf('%02d', $minute);
                    } else {
                        $out .= match (true) {
                            $run === 1 => (string) $month,
                            $run === 2 => sprintf('%02d', $month),
                            $run === 3 => mb_substr(self::MONTHS[$month - 1], 0, 3),
                            default    => self::MONTHS[$month - 1],
                        };
                    }
                    break;
                case 'y':
                    $dayOfYear = (int) date('z', mktime(0, 0, 0, $month, $day, $year)) + 1;
                    $out .= match (true) {
                        $run >= 3  => sprintf('%04d', $year),
                        $run === 2 => sprintf('%02d', $year % 100),
                        default    => (string) $dayOfYear,
                    };
                    break;
                case 'h':
                    $out .= $run === 1 ? (string) $hour : sprintf('%02d', $hour);
                    break;
                case 'n':
                    $out .= $run === 1 ? (string) $minute : sprintf('%02d', $minute);
                    break;
                case 's':
                    $out .= $run === 1 ? (string) $second : sprintf('%02d', $second);
                    break;
                default:
                    $out .= str_repeat($char, $run);
            }
            if ($lower !== ' ' && $lower !== ':' && $lower !== '/') {
                $lastWasHour = $lower === 'h';
            }
            $i += $run - 1;
        }

        return $out;
    }

    /** ¿Lo siguiente (saltando separadores) son segundos? Entonces "m" son minutos. */
    private static function nextIsSecond(array $chars, int $from): bool
    {
        for ($i = $from, $n = count($chars); $i < $n; $i++) {
            $lower = mb_strtolower($chars[$i]);
            if ($lower === 's') {
                return true;
            }
            if (! in_array($lower, [':', ' ', '.'], true)) {
                return false;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** CAD_DescomponerPar: sin separador, el primero vacío y el segundo todo. */
    public static function splitPair(string $text, string $separator): array
    {
        $position = mb_strpos($text, $separator);
        if ($position === false) {
            return ['', $text];
        }

        return [mb_substr($text, 0, $position), mb_substr($text, $position + mb_strlen($separator))];
    }
}
