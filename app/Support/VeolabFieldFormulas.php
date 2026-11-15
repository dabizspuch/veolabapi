<?php

namespace App\Support;

/**
 * Fórmulas de los autodefinibles y de los campos fijos de recolección
 * (LABAUT.AUTCPRE que empieza por "="), desde Veolab 3. Es el lenguaje de las
 * fórmulas de resultados sin result() ni letras de columna (Formulas.bas):
 *
 *   operadores  + - * / \ ^   < > = >= <= <>   & (y)  | (o)  : (concatenar)  # (pares de cross)
 *   funciones   ln log sin cos tan sqr exp abs round(x;dec) if(cond;si;no) not(x)
 *               cross(x;"a#b";...) format(x;"0.00") field("nombre")
 *
 * field() lee otro campo de la ficha: los autodefinibles con el símbolo de
 * exportación delante (field("ªpH")) y los campos fijos por su marcador de
 * exportación (field("temperaturaoperacion")); las claves no distinguen
 * mayúsculas. Con una dependencia vacía el resultado es vacío salvo que el
 * laboratorio evalúe las fórmulas con dependencias vacías (LABCON.CONBEFD).
 * Cualquier error (sintaxis, división por cero, desbordamiento) devuelve vacío.
 *
 * Puerto paso a paso de Documentacion\Formulas autodefinibles\referencia_formulas.py
 * (la misma semántica que el escritorio y la aplicación web); se comprueba con
 * casos.csv de esa carpeta. Los números siguen el separador decimal del
 * laboratorio (VeolabFormat).
 */
class VeolabFieldFormulas
{
    private const FUNCTIONS = ['ln', 'log', 'sin', 'cos', 'tan', 'sqr', 'exp', 'round', 'if', 'cross', 'not', 'field', 'abs', 'format'];

    private const OPERATORS = ['+', '-', '*', '/', '\\', '^', '<', '>', '=', '>=', '<=', '=>', '=<', '<>', '&', '|', '#', ':'];

    private const LEVELS = [['^'], ['*', '/', '\\'], ['+', '-'], [':'], ['>', '<', '=', '>=', '=>', '<=', '=<', '<>'], ['&'], ['|'], ['#']];

    /** Profundidad máxima de anidamiento al evaluar (MAXPILA). */
    private const MAX_STACK = 60;

    private array $values;
    private int $stack = 0;

    private function __construct(array $values, private bool $evaluateEmpty)
    {
        $this->values = [];
        foreach ($values as $key => $value) {
            $this->values[mb_strtolower((string) $key)] = (string) $value;
        }
    }

    /** ¿El valor predeterminado es una fórmula? (FOR_EsFormula) */
    public static function isFormula(?string $text): bool
    {
        return str_starts_with(ltrim((string) $text), '=');
    }

    /**
     * FOR_Evaluar: resultado como texto, o "" si no se puede calcular.
     * $values: clave en minúscula (o no) => valor de cada campo disponible.
     */
    public static function evaluate(string $formula, array $values = [], bool $evaluateEmpty = false): string
    {
        $f = trim($formula);
        if (str_starts_with($f, '=')) {
            $f = trim(mb_substr($f, 1));
        }
        if ($f === '') {
            return '';
        }

        try {
            return (new self($values, $evaluateEmpty))->evaluateExpression(self::setPriority($f));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * FOR_Validar: [válida, nombres usados en field]. Paréntesis y comillas
     * cerrados, funciones conocidas seguidas de paréntesis, sin letras de
     * columna y con un literal como nombre en cada field.
     */
    public static function validate(string $formula): array
    {
        $rest = trim($formula);
        if (str_starts_with($rest, '=')) {
            $rest = trim(mb_substr($rest, 1));
        }
        if ($rest === '' || mb_substr_count($rest, '"') % 2 !== 0) {
            return [false, []];
        }

        $refs = [];
        $level = 0;
        $expectParenthesis = false;
        $previous = '';
        $previous2 = '';
        $ok = true;
        while ($ok) {
            $atom = self::extractAtom($rest);
            if ($atom === '') {
                break;
            }
            if ($expectParenthesis && $atom !== '(') {
                $ok = false;
            }
            $expectParenthesis = false;
            if (str_starts_with($atom, '"')) {
                if (mb_strlen($atom) < 2 || ! str_ends_with($atom, '"')) {
                    $ok = false;
                }
                if ($previous === '(' && $previous2 === 'field') {
                    $refs[] = mb_substr($atom, 1, mb_strlen($atom) - 2);
                }
            } elseif (VeolabFormat::isNumeric($atom)) {
                // número
            } elseif ($atom === '(') {
                $level++;
            } elseif ($atom === ')') {
                $level--;
                $ok = $ok && $level >= 0;
            } elseif ($atom === ';') {
                $ok = $ok && $level > 0;
            } elseif (in_array($atom, self::OPERATORS, true)) {
                // operador
            } elseif (in_array($atom, self::FUNCTIONS, true)) {
                $expectParenthesis = true;
            } else {
                $ok = false;
            }
            $previous2 = $previous;
            $previous = $atom;
            if ($rest === '') {
                break;
            }
        }
        if ($expectParenthesis || $level !== 0 || in_array($previous, self::OPERATORS, true) || $previous === ';' || $previous === '(') {
            $ok = false;
        }

        return [$ok, $refs];
    }

    // ------------------------------------------------------------------
    // Análisis (ExtraeAtomo, EstablecerPrioridadOperaciones, SignosNegativos)
    // ------------------------------------------------------------------

    /** Extrae el primer átomo de la fórmula y deja el resto en $formula. */
    private static function extractAtom(string &$formula): string
    {
        $chars = mb_str_split($formula);
        $n = count($chars);
        if ($n === 0) {
            return '';
        }
        [$oper, $num, $fun, $col, $lit] = ['O', 'N', 'F', 'C', 'L'];
        $type = '';
        $i = 1;
        while (true) {
            $end = false;
            if ($i > $n) {
                $end = true;
            } else {
                $c = $chars[$i - 1];
                if ($c >= 'A' && $c <= 'Z' && strlen($c) === 1) {
                    if ($type === '') {
                        $type = $col;
                    } elseif ($type !== $col && $type !== $lit) {
                        $end = true;
                    }
                } elseif (strlen($c) === 1 && str_contains('+-/\\*^<>=&|#:', $c)) {
                    if ($type === '') {
                        $type = $oper;
                    } elseif ($type === $oper) {
                        if (! str_contains('><=', $c)) {
                            $end = true;
                        }
                    } elseif ($type !== $lit) {
                        $end = true;
                    }
                } elseif ($c === '(' || $c === ')' || $c === ';') {
                    if ($type !== $lit) {
                        $end = true;
                    }
                } elseif ($c === ' ') {
                    // se ignora
                } elseif (strlen($c) === 1 && str_contains('1234567890,.', $c)) {
                    if ($type === '') {
                        $type = $num;
                    } elseif ($type !== $num && $type !== $lit) {
                        $end = true;
                    }
                } elseif ($c >= 'a' && $c <= 'z' && strlen($c) === 1) {
                    if ($type === '') {
                        $type = $fun;
                    } elseif ($type !== $fun && $type !== $lit) {
                        $end = true;
                    }
                } elseif ($c === '"') {
                    if ($type === $lit) {
                        $type = '';
                        $end = true;
                    } elseif ($type === '') {
                        $type = $lit;
                    } else {
                        $end = true;
                    }
                } else {
                    if ($type === $lit) {
                        // dentro del literal
                    } elseif ($type === '') {
                        $type = $col;
                    } else {
                        $end = true;
                    }
                }
            }
            $i++;
            if ($end) {
                break;
            }
        }
        $cut = $type === '' ? $i - 1 : $i - 2;
        $atom = implode('', array_slice($chars, 0, $cut));
        $formula = implode('', array_slice($chars, $cut));
        $atom = preg_replace('/[\x00-\x1F]/u', '', $atom);

        return trim($atom, ' ');
    }

    private static function isFunction(string $atom): bool
    {
        return $atom !== '' && $atom[0] >= 'a' && $atom[0] <= 'z';
    }

    /** Añade el paréntesis de cierre al operando derecho del operador en $pos (1-based). */
    private static function closeRight(array &$atoms, int $pos): void
    {
        $open = 0;
        $end = 0;
        $count = count($atoms);
        for ($i = $pos + 1; $i <= $count; $i++) {
            $a = $atoms[$i - 1];
            if (str_starts_with($a, '(')) {
                $open += substr_count($a, '(');
            } elseif (str_ends_with($a, ')')) {
                $open -= substr_count($a, ')');
                if ($open === 0) {
                    $end = $i;
                }
            } elseif (self::isFunction($a)) {
                // la función va con su paréntesis
            } elseif ($open === 0) {
                $end = $i;
            }
            if ($end > 0) {
                break;
            }
        }
        if ($end > 0) {
            $atoms[$end - 1] .= ')';
        } else {
            $atoms[$count - 1] .= ')';
        }
    }

    /** Añade el paréntesis de apertura al operando izquierdo del operador en $pos (1-based). */
    private static function openLeft(array &$atoms, int $pos): void
    {
        $open = 0;
        $end = 0;
        $i = $pos - 1;
        if ($i > 0) {
            while (true) {
                $a = $atoms[$i - 1];
                if (str_ends_with($a, ')')) {
                    $open += substr_count($a, ')');
                } elseif (str_starts_with($a, '(')) {
                    $open -= substr_count($a, '(');
                    if ($open <= 0) {
                        $end = ($i > 1 && self::isFunction($atoms[$i - 2])) ? $i - 1 : $i;
                    }
                } elseif (self::isFunction($a)) {
                    // la función va con su paréntesis
                } elseif ($open === 0) {
                    $end = $i;
                }
                $i--;
                if ($end > 0 || $i < 1) {
                    break;
                }
            }
            if ($end > 0) {
                $element = $atoms[$end - 1];
                if ($atoms[$end] === '-') {
                    if ($element === '(' || $element === ';' || in_array($element, self::OPERATORS, true)) {
                        $atoms[$end] = '(0-';
                    } else {
                        $atoms[$end - 1] = '('.$element;
                    }
                } else {
                    $atoms[$end - 1] = '('.$element;
                }
            } else {
                $atoms[0] .= '(';
            }
        } else {
            $atoms[0] = '(0'.$atoms[0];
        }
    }

    /** Un - al principio o tras operador, ( o ; pasa a (0-operando). */
    private static function negativeSigns(array &$atoms): void
    {
        $count = count($atoms);
        for ($i = 1; $i <= $count; $i++) {
            if ($atoms[$i - 1] !== '-') {
                continue;
            }
            $previous = $i === 1 ? '(' : $atoms[$i - 2];
            if (! ($previous === '(' || $previous === ';' || in_array($previous, self::OPERATORS, true))) {
                continue;
            }
            $end = 0;
            $k = $i + 1;
            if ($k <= $count && self::isFunction($atoms[$k - 1])) {
                $k++;
            }
            if ($k <= $count) {
                if ($atoms[$k - 1] === '(') {
                    $level = 0;
                    while ($k <= $count) {
                        if ($atoms[$k - 1] === '(') {
                            $level++;
                        }
                        if ($atoms[$k - 1] === ')') {
                            $level--;
                        }
                        if ($level === 0) {
                            $end = $k;
                            break;
                        }
                        $k++;
                    }
                } elseif ($atoms[$k - 1] !== ')' && $atoms[$k - 1] !== ';' && $atoms[$k - 1] !== '') {
                    $end = $k;
                }
            }
            if ($end > 0) {
                $atoms[$end - 1] .= ')';
                $atoms[$i - 1] = '(0-';
            }
        }
    }

    /** EstablecerPrioridadOperaciones: paréntesis por niveles de prioridad; la evaluación es de izquierda a derecha. */
    private static function setPriority(string $formula): string
    {
        $atoms = [];
        $rest = $formula;
        while (true) {
            $atoms[] = self::extractAtom($rest);
            if ($rest === '') {
                break;
            }
        }
        self::negativeSigns($atoms);

        $positions = [];
        foreach ($atoms as $index => $atom) {
            if (in_array($atom, self::OPERATORS, true)) {
                $positions[$atom][] = $index + 1;
            }
        }
        foreach (self::LEVELS as $level) {
            $pos = [];
            foreach ($level as $operator) {
                foreach ($positions[$operator] ?? [] as $p) {
                    $pos[] = $p;
                }
            }
            sort($pos);
            foreach ($pos as $p) {
                self::closeRight($atoms, $p);
                self::openLeft($atoms, $p);
            }
        }

        return implode('', $atoms);
    }

    // ------------------------------------------------------------------
    // Evaluación (EvaluaFormula, ObtenParametros)
    // ------------------------------------------------------------------

    /** Parámetros evaluados hasta el paréntesis de cierre; deja el resto en $formula. */
    private function parameters(string &$formula): array
    {
        $chars = mb_str_split($formula);
        $n = count($chars);
        $separators = [];
        $level = 0;
        $literal = false;
        $i = 1;
        while (true) {
            $c = $i <= $n ? $chars[$i - 1] : '';
            if ($c === '"') {
                $literal = ! $literal;
            } elseif (! $literal) {
                if ($c === '(') {
                    $level++;
                } elseif ($c === ')') {
                    $level--;
                } elseif ($c === ';' && $level === 0) {
                    $separators[] = $i;
                }
            }
            $i++;
            if ($level < 0 || $c === '') {
                break;
            }
        }
        if ($level >= 0) {
            throw new \RuntimeException('falta paréntesis');
        }
        $final = $i - 1;
        $slice = fn (int $from, int $to) => implode('', array_slice($chars, $from, max($to - $from, 0)));

        $params = [];
        if (! $separators) {
            $params[] = $this->evaluateExpression($slice(0, $final - 1));
        } else {
            $previous = 1;
            foreach ($separators as $s) {
                $params[] = $this->evaluateExpression($slice($previous - 1, $s - 1));
                $previous = $s + 1;
            }
            $params[] = $this->evaluateExpression($slice(end($separators), $final - 1));
        }
        $formula = $slice($final, $n);

        return $params;
    }

    private function evaluateExpression(string $formula): string
    {
        if (++$this->stack > self::MAX_STACK) {
            throw new \RuntimeException('anidamiento');
        }
        $value = '0';
        $operator = '';
        $function = '';
        while (true) {
            $atom = self::extractAtom($formula);
            $isValue = false;
            $obtained = '';
            $end = false;
            if (VeolabFormat::isNumeric($atom)) {
                $obtained = self::text(VeolabFormat::value($atom));
                $isValue = true;
            } elseif (in_array($atom, self::FUNCTIONS, true)) {
                $function = $atom;
            } elseif (in_array($atom, self::OPERATORS, true)) {
                $operator = $atom;
            } elseif ($atom === '(') {
                $params = $this->parameters($formula);
                $obtained = $this->call($function, $params);
                $function = '';
                $isValue = true;
            } elseif ($atom === ')' || $atom === '') {
                $end = true;
            } elseif (mb_strlen($atom) >= 2 && str_starts_with($atom, '"') && str_ends_with($atom, '"')) {
                $obtained = mb_substr($atom, 1, mb_strlen($atom) - 2);
                $isValue = true;
            } else {
                throw new \RuntimeException('átomo no válido: '.$atom);
            }
            if ($isValue) {
                if ($obtained === '' && ! $this->evaluateEmpty) {
                    $value = '';
                    $end = true;
                } elseif ($operator === '') {
                    $value = $obtained;
                } else {
                    $value = $this->operate($operator, $value, $obtained);
                }
            }
            if ($end) {
                break;
            }
        }
        $this->stack--;

        return $value;
    }

    private function call(string $function, array $p): string
    {
        $d = function (int $k) use ($p): float {
            if (! array_key_exists($k, $p)) {
                throw new \RuntimeException('falta parámetro');
            }

            return VeolabFormat::value($p[$k]);
        };
        try {
            switch ($function) {
                case 'ln':
                    return self::text(self::checked(log($d(0))));
                case 'log':
                    return self::text(self::checked(log($d(0)) / log(10)));
                case 'sin':
                    return self::text(sin($d(0)));
                case 'cos':
                    return self::text(cos($d(0)));
                case 'tan':
                    return self::text(tan($d(0)));
                case 'sqr':
                    return self::text(self::checked(sqrt($d(0))));
                case 'exp':
                    return self::text(self::checked(exp($d(0))));
                case 'round':
                    $decimals = count($p) > 1 ? (int) $d(1) : 0;

                    return self::text(VeolabFormat::round($d(0), $decimals));
                case 'if':
                    if (($p[0] ?? null) === null) {
                        throw new \RuntimeException('falta parámetro');
                    }
                    if ($p[0] === '1') {
                        return $p[1] ?? throw new \RuntimeException('falta parámetro');
                    }

                    return $p[2] ?? '';
                case 'cross':
                    if (! array_key_exists(0, $p)) {
                        throw new \RuntimeException('falta parámetro');
                    }
                    foreach (array_slice($p, 1) as $pair) {
                        $hash = mb_strpos($pair, '#');
                        $left = $hash === false ? $pair : mb_substr($pair, 0, $hash);
                        $right = $hash === false ? '' : mb_substr($pair, $hash + 1);
                        if ($p[0] === $left) {
                            return $right;
                        }
                    }

                    return '';
                case 'not':
                    return $d(0) == 0 ? '1' : '0';
                case 'field':
                    if (! array_key_exists(0, $p)) {
                        throw new \RuntimeException('falta parámetro');
                    }

                    return $this->values[mb_strtolower(trim($p[0]))] ?? '';
                case 'abs':
                    return self::text(abs($d(0)));
                case 'format':
                    return self::format($p[0] ?? throw new \RuntimeException('falta parámetro'), $p[1] ?? throw new \RuntimeException('falta parámetro'));
                case '':
                    return $p[0] ?? throw new \RuntimeException('falta parámetro');
            }
        } catch (\DivisionByZeroError|\ArithmeticError|\OverflowException|\ValueError $e) {
            throw new \RuntimeException('error en función');
        }
        throw new \RuntimeException('función desconocida');
    }

    private function operate(string $operator, string $a, string $b): string
    {
        try {
            switch ($operator) {
                case '+':
                    return self::text(VeolabFormat::value($a) + VeolabFormat::value($b));
                case '-':
                    return self::text(VeolabFormat::value($a) - VeolabFormat::value($b));
                case '*':
                    return self::text(VeolabFormat::value($a) * VeolabFormat::value($b));
                case '/':
                    $divisor = VeolabFormat::value($b);
                    if ($divisor == 0.0) {
                        throw new \RuntimeException('división por cero');
                    }

                    return self::text(VeolabFormat::value($a) / $divisor);
                case '\\':
                    $y = VeolabFormat::toLong(VeolabFormat::value($b));
                    if ($y === 0) {
                        throw new \RuntimeException('división por cero');
                    }

                    return self::text((float) intdiv(VeolabFormat::toLong(VeolabFormat::value($a)), $y));
                case '^':
                    return self::text(self::checked(VeolabFormat::value($a) ** VeolabFormat::value($b)));
                case '|':
                    return self::text((float) (VeolabFormat::toLong(VeolabFormat::value($a)) | VeolabFormat::toLong(VeolabFormat::value($b))));
                case '&':
                    return self::text((float) (VeolabFormat::toLong(VeolabFormat::value($a)) & VeolabFormat::toLong(VeolabFormat::value($b))));
                case '>':
                    return VeolabFormat::value($a) > VeolabFormat::value($b) ? '1' : '0';
                case '<':
                    return VeolabFormat::value($a) < VeolabFormat::value($b) ? '1' : '0';
                case '=':
                    return $a === $b ? '1' : '0';
                case '>=':
                case '=>':
                    return VeolabFormat::value($a) >= VeolabFormat::value($b) ? '1' : '0';
                case '<=':
                case '=<':
                    return VeolabFormat::value($a) <= VeolabFormat::value($b) ? '1' : '0';
                case '<>':
                    return $a !== $b ? '1' : '0';
                case '#':
                    return $a.'#'.$b;
                case ':':
                    return $a.$b;
            }
        } catch (\DivisionByZeroError|\ArithmeticError|\OverflowException $e) {
            throw new \RuntimeException('error de cálculo');
        }
        throw new \RuntimeException('operador desconocido');
    }

    // ------------------------------------------------------------------
    // Números y formatos como VB6 con la configuración regional del laboratorio
    // ------------------------------------------------------------------

    /** CStr de un Double (sin NaN ni infinito: desbordamiento). */
    private static function text(float $number): string
    {
        return VeolabFormat::cstr(self::checked($number));
    }

    private static function checked(float $number): float
    {
        if (is_nan($number) || is_infinite($number)) {
            throw new \RuntimeException('desbordamiento');
        }

        return $number;
    }

    /** format(x;"0.00"): formatos sencillos 0, 0.0, 0.00... (con el separador decimal del laboratorio); otros, tal cual. */
    private static function format(string $value, string $format): string
    {
        $format = trim($format);
        if ($format !== '' && preg_match('/^[0.,#]+$/', $format) && VeolabFormat::isNumeric($value)) {
            $decimals = str_contains($format, '.') ? strlen(explode('.', $format, 2)[1]) : 0;
            $rounded = VeolabFormat::round(VeolabFormat::value($value), $decimals);

            return str_replace('.', VeolabFormat::decimalSeparator(), sprintf('%.'.$decimals.'f', $rounded));
        }

        return $value;
    }
}
