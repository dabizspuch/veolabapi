<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Fórmulas de las columnas de resultados (LABCOT.COTCFOM), replicando
 * FichaResultados de Veolab (CalcularFormulas, EvaluarCelda, EvaluaFormula,
 * ExtraeAtomo, EstablecerPrioridadOperaciones, ObtenParametros...) con su
 * mismo analizador y sus rarezas, para que la API calcule lo mismo que Veolab:
 *  - Columnas de la técnica por letra (A, B... AA), números con el separador
 *    decimal del laboratorio, literales entre comillas, operadores
 *    ^ * / \ + - : (concatena) = <> < > <= >= & | # y funciones ln, log, sin,
 *    cos, tan, sqr, exp, abs, round(x;n), if(c;a;b), not(x), cross(x;"a#b"...),
 *    format(x;"formato"), field("campo") y result(delegación;técnica;columna).
 *  - La prioridad de operadores se resuelve añadiendo paréntesis sobre la
 *    lista de átomos y la evaluación es de izquierda a derecha.
 *  - Errores: desbordamiento, división por cero y error de función dejan en
 *    la celda el texto de Veolab (MEN00252/253/254); otros errores, vacío.
 *  - Sin CONBEFD ("evaluar fórmulas con dependencias vacías") un operando
 *    vacío deja vacía la (sub)expresión.
 *  - El resultado se formatea con el formato de la columna y recalcula su
 *    marca por rangos.
 * Recalcular una técnica (recalculate) evalúa sus celdas con fórmula, con
 * sus dependencias primero, y después las de todas las técnicas con
 * fórmulas result(). Las celdas modificadas a mano no se recalculan salvo
 * forzándolo (menú "Recalcular" de Veolab).
 *
 * field(): autodefinibles ("ªNombre") y los campos directos de la operación
 * y de la técnica (fechas, referencia, temperatura...). Con otro campo la
 * celda no se recalcula y se devuelve un aviso.
 */
class VeolabFormulas
{
    /** Límite de evaluaciones de celda por recálculo (MAXPILA). */
    private const MAX_STACK = 2000;

    private const FUNCTIONS = ['ln', 'log', 'sin', 'cos', 'tan', 'sqr', 'exp', 'round', 'if', 'cross',
        'not', 'field', 'result', 'abs', 'format'];

    private const OPERATORS = ['+', '-', '*', '/', '\\', '^', '<', '>', '=', '>=', '<=', '=>', '=<', '<>',
        '&', '|', '#', ':'];

    /** Operadores cuya posición se recuerda para añadir paréntesis (":", "#" y "<>" no). */
    private const PRIORITY_OPERATORS = ['^', '*', '\\', '/', '+', '-', '&', '|', '>', '<', '=', '>=', '<=', '=>', '=<'];

    private const PRIORITY_LEVELS = [['^'], ['*', '/', '\\'], ['+', '-'], [':'],
        ['>', '<', '=', '>=', '=>', '<=', '=<', '<>'], ['&'], ['|'], ['#']];

    /** Errores de EvaluaFormula (código => texto de Veolab). */
    private const ERROR_OVERFLOW = 2;
    private const ERROR_DIVISION = 3;
    private const ERROR_FUNCTION = 4;

    private static array $messages = [];

    private array $cells;
    private array $techniques;
    private array $order;
    private array $manual = [];
    private array $evaluated = [];
    private int $stack = 0;
    private bool $forced = false;
    private bool $marksChanged = false;
    private array $warnings = [];
    private array $fieldCache = [];
    private ?object $operationRow = null;

    /**
     * @param array $cells      Celdas de VeolabResults::cells (por referencia: se recalculan ahí).
     * @param array $techniques Técnicas de VeolabResults::techniques (orden de la rejilla).
     * @param array $operation  [delegación, serie, código].
     */
    public function __construct(array &$cells, array $techniques, private array $operation, private array $marks,
        private bool $evaluateEmpty)
    {
        $this->cells = &$cells;
        $this->techniques = $techniques;
        $this->order = array_keys($techniques);
    }

    /** Celda modificada a mano (no se recalcula salvo forzando). */
    public function markManual(string $tec, int $column): void
    {
        $this->manual[$tec][$column] = true;
    }

    public function marksChanged(): bool
    {
        return $this->marksChanged;
    }

    /** Avisos: [tecnica_delegacion, tecnica_codigo, columna, aviso]. */
    public function warnings(): array
    {
        return array_values($this->warnings);
    }

    /** ¿Tiene la operación alguna celda con fórmula? */
    public function hasFormulas(): bool
    {
        foreach ($this->cells as $columns) {
            foreach ($columns as $cell) {
                if ($cell['formula'] !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Recálculo (CalcularFormulas / EvaluarCelda)
    // ------------------------------------------------------------------

    /** CalcularFormulas sobre la fila de la técnica (forzado: también las modificadas a mano). */
    public function recalculate(string $tec, bool $forced = false): void
    {
        $this->evaluated = [];
        $this->stack = 0;
        $this->forced = $forced;
        $this->fieldCache = [];

        $columns = 0;
        foreach ($this->cells as $row) {
            $columns = max($columns, $row ? max(array_keys($row)) : 0);
        }

        for ($column = 1; $column <= $columns; $column++) {
            $this->visit($tec, $column);
        }

        // Fórmulas entre técnicas (result): todas las filas que las tienen, por columnas.
        $resultRows = [];
        foreach ($this->order as $row) {
            foreach ($this->cells[$row] ?? [] as $cell) {
                if (str_contains($cell['formula'], 'result')) {
                    $resultRows[] = $row;
                    break;
                }
            }
        }
        for ($column = 1; $resultRows && $column <= $columns; $column++) {
            foreach ($resultRows as $row) {
                $this->visit($row, $column);
            }
        }

        if ($this->stack > self::MAX_STACK) {
            $this->warn($tec, null, 'Las fórmulas tienen demasiadas dependencias (¿referencias circulares?): no se han calculado todas');
        }
    }

    private function visit(string $tec, int $column): void
    {
        $cell = $this->cells[$tec][$column] ?? null;
        if ($cell === null || $cell['formula'] === '') {
            $this->evaluated[$tec][$column] = true;

            return;
        }
        if (isset($this->manual[$tec][$column])) {
            if (! $this->forced) {
                return;
            }
            unset($this->manual[$tec][$column]);
        }
        $this->evaluateCell($tec, $column);
    }

    /** EvaluarCelda: dependencias de la misma fila primero, después la fórmula. */
    private function evaluateCell(string $tec, int $column): void
    {
        if (++$this->stack > self::MAX_STACK || isset($this->evaluated[$tec][$column])) {
            return;
        }

        $cell = $this->cells[$tec][$column] ?? null;
        if ($cell === null || $cell['formula'] === '' || (isset($this->manual[$tec][$column]) && ! $this->forced)) {
            $this->evaluated[$tec][$column] = true;

            return;
        }

        try {
            foreach ($this->dependencies($cell['formula']) as $dependency) {
                if ($dependency !== $column) {
                    $this->evaluateCell($tec, $dependency);
                }
            }

            $formula = $cell['formula'];
            if (str_starts_with($formula, '=')) {
                $formula = mb_substr($formula, 1);
            }
            $value = VeolabFormat::conditional($this->evaluate(self::priority($formula), $tec), $cell['format']);
        } catch (\DomainException $e) {
            $this->warn($tec, $column, "La fórmula usa field(\"{$e->getMessage()}\"), que la API no calcula: la celda no se ha recalculado");

            return;
        } catch (\Throwable) {
            $this->warn($tec, $column, 'Error de sintaxis en la fórmula: la celda no se ha recalculado');

            return;
        }

        $cell = &$this->cells[$tec][$column];
        [$yes, $no] = VeolabResults::yesNo();
        if ($cell['type'] === 'C') {
            $new = $value === $yes ? $yes : $no;
            $distinct = ($cell['value'] === $yes) !== ($new === $yes);
        } else {
            $new = $value;
            $distinct = $cell['value'] !== $new;
        }
        if ($distinct) {
            $cell['value'] = $new;
            $cell['changed'] = true;
            if (VeolabResults::applyRangeMark($cell, $this->marks, $this->operation[0], false)) {
                $this->marksChanged = true;
            }
        }
        unset($cell);

        $this->evaluated[$tec][$column] = true;
    }

    /** ObtenerDependencias: columnas (átomos en mayúsculas) de la fórmula. */
    private function dependencies(string $formula): array
    {
        $columns = [];
        do {
            $atom = self::extractAtom($formula);
            if ($atom !== '' && ord($atom[0]) >= 65 && ord($atom[0]) <= 90) {
                $column = self::columnOf($atom);
                if ($column !== null) {
                    $columns[$column] = $column;
                }
            }
        } while ($atom !== '');

        return array_values($columns);
    }

    private function warn(string $tec, ?int $column, string $message): void
    {
        [$tecDel, $tecCod] = explode("\x1B", $tec, 2);
        $this->warnings[$tec."\x1B".$column] = [
            'tecnica_delegacion' => $tecDel,
            'tecnica_codigo'     => $tecCod,
            'columna'            => $column === null ? null : VeolabResults::letter($column),
            'aviso'              => $message,
        ];
    }

    // ------------------------------------------------------------------
    // Evaluación (EvaluaFormula)
    // ------------------------------------------------------------------

    /** EvaluaFormula: de izquierda a derecha sobre la fórmula ya con paréntesis de prioridad. */
    private function evaluate(string $formula, string $tec): string
    {
        $value = '0';
        $obtained = '';
        $operator = '';
        $function = '';
        $isValue = false;
        $end = false;
        $roundDigits = 0;

        try {
            do {
                $atom = self::extractAtom($formula);
                $number = VeolabFormat::toDouble($atom);
                if ($number !== null) {
                    $obtained = VeolabFormat::cstr($number);
                    $isValue = true;
                } elseif (in_array($atom, self::FUNCTIONS, true)) {
                    $function = $atom;
                } elseif (in_array($atom, self::OPERATORS, true)) {
                    $operator = $atom;
                    $isValue = false;
                } elseif ($atom === '(') {
                    $parameters = $this->parameters($formula, $tec);
                    $obtained = $this->callFunction($function, $parameters, $tec, $roundDigits);
                    $function = '';
                    $isValue = true;
                } elseif ($atom === ')' || $atom === '') {
                    $end = true;
                    $isValue = false;
                } else {
                    if (str_starts_with($atom, '"')) {
                        if (mb_strlen($atom) < 2) {
                            throw new \RuntimeException('Literal');
                        }
                        $obtained = mb_substr($atom, 1, mb_strlen($atom) - 2);
                    } else {
                        $obtained = $this->columnValue($atom, $tec);
                    }
                    $isValue = true;
                }

                if ($isValue) {
                    if ($obtained === '' && ! $this->evaluateEmpty) {
                        $value = '';
                        $end = true;
                    } elseif ($operator === '') {
                        $value = $obtained;
                    } else {
                        $value = $this->operate($value, $operator, $obtained);
                    }
                }
            } while (! $end);

            return $value;
        } catch (\DomainException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return match ($e->getCode()) {
                self::ERROR_OVERFLOW => self::message('MEN00252'),
                self::ERROR_DIVISION => self::message('MEN00253'),
                self::ERROR_FUNCTION => self::message('MEN00254'),
                default              => '',
            };
        } catch (\Throwable) {
            return '';
        }
    }

    /** Operación binaria con el valor acumulado. */
    private function operate(string $left, string $operator, string $right): string
    {
        $a = fn () => VeolabFormat::value($left);
        $b = fn () => VeolabFormat::value($right);
        $number = function (float $result, int $error): string {
            if (! is_finite($result)) {
                throw new \RuntimeException('Aritmética', $error);
            }

            return VeolabFormat::cstr($result);
        };

        switch ($operator) {
            case '+':
                return $number($a() + $b(), self::ERROR_OVERFLOW);
            case '-':
                return $number($a() - $b(), self::ERROR_OVERFLOW);
            case '*':
                return $number($a() * $b(), self::ERROR_OVERFLOW);
            case '^':
                return $number($a() ** $b(), self::ERROR_OVERFLOW);
            case '/':
                if ($b() == 0) {
                    throw new \RuntimeException('División por cero', self::ERROR_DIVISION);
                }

                return $number($a() / $b(), self::ERROR_DIVISION);
            case '\\':
                try {
                    $dividend = VeolabFormat::toLong($a());
                    $divisor = VeolabFormat::toLong($b());
                } catch (\OverflowException) {
                    throw new \RuntimeException('Desbordamiento', self::ERROR_DIVISION);
                }
                if ($divisor === 0) {
                    throw new \RuntimeException('División por cero', self::ERROR_DIVISION);
                }

                return (string) intdiv($dividend, $divisor);
            case '|':
                return (string) (VeolabFormat::toLong($a()) | VeolabFormat::toLong($b()));
            case '&':
                return (string) (VeolabFormat::toLong($a()) & VeolabFormat::toLong($b()));
            case '>':
                return $a() > $b() ? '1' : '0';
            case '<':
                return $a() < $b() ? '1' : '0';
            case '=':
                return $left === $right ? '1' : '0';
            case '>=':
            case '=>':
                return $a() >= $b() ? '1' : '0';
            case '<=':
            case '=<':
                return $a() <= $b() ? '1' : '0';
            case '<>':
                return $left !== $right ? '1' : '0';
            case '#':
                return $left.'#'.$right;
            case ':':
                return $left.$right;
        }

        return $left;
    }

    /** Función con sus parámetros ya evaluados; cualquier error es "error de función". */
    private function callFunction(string $function, array $parameters, string $tec, int &$roundDigits): string
    {
        $parameter = function (int $index) use ($parameters): string {
            if (! array_key_exists($index - 1, $parameters)) {
                throw new \RuntimeException('Falta un parámetro');
            }

            return $parameters[$index - 1];
        };
        $positive = function (float $value): float {
            if ($value <= 0) {
                throw new \RuntimeException('Fuera de dominio');
            }

            return $value;
        };
        $finite = function (float $value): string {
            if (! is_finite($value)) {
                throw new \RuntimeException('Desbordamiento');
            }

            return VeolabFormat::cstr($value);
        };

        try {
            switch ($function) {
                case 'ln':
                    return $finite(log($positive(VeolabFormat::value($parameter(1)))));
                case 'log':
                    return $finite(log($positive(VeolabFormat::value($parameter(1)))) / log(10.0));
                case 'sin':
                    return $finite(sin(VeolabFormat::value($parameter(1))));
                case 'cos':
                    return $finite(cos(VeolabFormat::value($parameter(1))));
                case 'tan':
                    return $finite(tan(VeolabFormat::value($parameter(1))));
                case 'sqr':
                    $value = VeolabFormat::value($parameter(1));
                    if ($value < 0) {
                        throw new \RuntimeException('Fuera de dominio');
                    }

                    return $finite(sqrt($value));
                case 'exp':
                    return $finite(exp(VeolabFormat::value($parameter(1))));
                case 'round':
                    // CLng del segundo parámetro; si falla se queda el anterior.
                    try {
                        $digits = VeolabFormat::toDouble($parameter(2));
                        if ($digits !== null) {
                            $roundDigits = VeolabFormat::toLong($digits);
                        }
                    } catch (\Throwable) {
                    }

                    return $finite(VeolabFormat::round(VeolabFormat::value($parameter(1)), $roundDigits));
                case 'if':
                    return $parameter(1) === '1' ? $parameter(2) : $parameter(3);
                case 'cross':
                    $index = 2;
                    $result = '';
                    do {
                        [$left, $right] = VeolabFormat::splitPair($parameter($index), '#');
                        if ($parameter(1) === $left) {
                            $result = $right;
                        }
                        $index++;
                    } while ($index <= count($parameters) && $result === '');

                    return $result;
                case 'not':
                    $value = VeolabFormat::toDouble($parameter(1));
                    if ($value === null) {
                        throw new \RuntimeException('Tipos no coinciden');
                    }

                    return $value == 0 ? '1' : '0';
                case 'field':
                    return $this->fieldValue($parameter(1), $tec);
                case 'result':
                    return $this->resultValue($parameter(1), $parameter(2), $parameter(3));
                case 'abs':
                    return $finite(abs(VeolabFormat::value($parameter(1))));
                case 'format':
                    return VeolabFormat::format($parameter(1), $parameter(2));
                default:
                    return $parameter(1); // función identidad (paréntesis)
            }
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new \RuntimeException('Error de función', self::ERROR_FUNCTION);
        }
    }

    /**
     * ObtenParametros: evalúa los parámetros (separados por ';' fuera de
     * paréntesis) hasta el paréntesis de cierre y deja en $formula el resto.
     */
    private function parameters(string &$formula, string $tec): array
    {
        $copy = $formula;
        $chars = mb_str_split($copy);
        $separators = [];
        $depth = 0;
        $i = 1;
        do {
            $char = $chars[$i - 1] ?? '';
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                $separators[] = $i;
            }
            $i++;
        } while ($depth >= 0 && $char !== '');
        $final = $i - 1;

        $parameters = [];
        if (! $separators) {
            $parameters[] = $this->evaluate(self::mid($copy, 1, $final - 1), $tec);
        } else {
            foreach ($separators as $index => $separator) {
                $previous = $index === 0 ? 1 : $separators[$index - 1] + 1;
                $parameters[] = $this->evaluate(self::mid($copy, $previous, $separator - $previous), $tec);
            }
            $last = end($separators);
            $parameters[] = $this->evaluate(self::mid($copy, $last + 1, $final - $last - 1), $tec);
        }
        $formula = self::mid($copy, $final + 1);

        return $parameters;
    }

    /** ObtenValorColumna: celda de la técnica por letra (casillas: Sí/No). */
    private function columnValue(string $letters, string $tec): string
    {
        $column = self::columnOf($letters);

        return $column === null ? '' : $this->cellText($tec, $column);
    }

    private function cellText(string $tec, int $column): string
    {
        $cell = $this->cells[$tec][$column] ?? null;
        if ($cell === null) {
            return '';
        }
        if ($cell['type'] === 'C') {
            [$yes, $no] = VeolabResults::yesNo();

            return $cell['value'] === $yes ? $yes : $no;
        }

        return $cell['value'];
    }

    /** ObtenValorResultado: celda de otra técnica de la operación (columna por letra o número). */
    private function resultValue(string $delegation, string $code, string $column): string
    {
        if ($delegation === '0') {
            $delegation = '';
        }
        $tec = VeolabResults::key($delegation, $code);
        if (! isset($this->techniques[$tec])) {
            return '';
        }
        if (VeolabFormat::isNumeric($column)) {
            try {
                return $this->cellText($tec, VeolabFormat::toLong(self::val($column)));
            } catch (\OverflowException) {
                return '';
            }
        }

        return $this->columnValue($column, $tec);
    }

    // ------------------------------------------------------------------
    // field() (ObtenValorCampo)
    // ------------------------------------------------------------------

    /**
     * Campo de la técnica (nombre acabado en "tecnica"), autodefinible de la
     * operación ("ªNombre") o campo de la operación, como los marcadores de
     * exportación de Veolab. Un campo no soportado lanza DomainException.
     */
    private function fieldValue(string $name, string $tec): string
    {
        $isTechnique = mb_substr($name, -7) === 'tecnica';
        $cacheKey = $name."\x00".($isTechnique ? $tec : '');
        if (array_key_exists($cacheKey, $this->fieldCache)) {
            return $this->fieldCache[$cacheKey];
        }

        if ($isTechnique) {
            $value = $this->techniqueField($name, $this->techniques[$tec] ?? null);
        } elseif (str_starts_with($name, 'ª')) {
            [$del, $ser, $cod] = $this->operation;
            $value = (string) DB::connection('dynamic')->table('LABOYA')
                ->leftJoin('LABAUT', function ($join) {
                    $join->on('LABOYA.AUT3DEL', '=', 'LABAUT.DEL3COD')->on('LABOYA.AUT3COD', '=', 'LABAUT.AUT1COD');
                })
                ->where('LABAUT.AUTCNOM', mb_substr($name, 1))
                ->where('LABOYA.OPE3DEL', $del)->where('LABOYA.OPE3SER', $ser)->where('LABOYA.OPE3COD', $cod)
                ->value('LABOYA.OYACVAL');
        } else {
            $value = $this->operationField($name);
        }

        return $this->fieldCache[$cacheKey] = $value;
    }

    private function operationField(string $name): string
    {
        if ($this->operationRow === null) {
            [$del, $ser, $cod] = $this->operation;
            $this->operationRow = DB::connection('dynamic')->table('LABOPE')
                ->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod)->first() ?? (object) [];
        }
        $op = $this->operationRow;
        $text = fn (string $column) => (string) ($op->$column ?? '');
        [$yes, $no] = VeolabResults::yesNo();

        $texts = [
            'referenciaoperacion'    => 'OPECREF',
            'descuentooperacion'     => 'OPECDTO',
            'descripcionoperacion'   => 'OPECDES',
            'observacionesoperacion' => 'OPECOBS',
            'recolectoroperacion'    => 'OPECREC',
            'lugaroperacion'         => 'OPECLUR',
            'temperaturaoperacion'   => 'OPECTEM',
            'cantidadoperacion'      => 'OPECCAN',
            'unidadoperacion'        => 'OPECUNI',
            'loteoperacion'          => 'OPECLOT',
            'marcaoperacion'         => 'OPECMAR',
            'latitudoperacion'       => 'OPECLAT',
            'longitudoperacion'      => 'OPECLNG',
            'direcciongpsoperacion'  => 'OPECDIG',
            'envaseoperacion'        => 'OPECENV',
            'direccionoperacion'     => 'OPECDIR',
            'serieoperacion'         => 'OPE1SER',
            'serieoloteoperacion'    => 'SEL2COD',
        ];
        $dateTimes = [
            'fecharegistrooperacion'      => 'OPEDREG',
            'fechahorarecogidaoperacion'  => 'OPETREC',
            'fechahorarecepcionoperacion' => 'OPETREP',
            'fechahorainiciooperacion'    => 'OPEDINI',
            'fechahorafinoperacion'       => 'OPEDFIN',
            'fechadescarteoperacion'      => 'OPEDDES',
        ];
        $dates = [
            'fecharecogidaoperacion'   => 'OPETREC',
            'fecharecepcionoperacion'  => 'OPETREP',
            'fechapreparadaoperacion'  => 'OPEDPRE',
            'fechainiciooperacion'     => 'OPEDINI',
            'fechafinoperacion'        => 'OPEDFIN',
            'fechavalidacionoperacion' => 'OPEDVAL',
            'fechainformeoperacion'    => 'OPEDINF',
            'fechaenviooperacion'      => 'OPEDENV',
            'fechaarchivooperacion'    => 'OPEDARC',
            'fechaanulacionoperacion'  => 'OPEDANU',
            'fechacompromisooperacion' => 'OPEDCOM',
        ];

        $key = mb_strtolower($name);

        return match (true) {
            isset($texts[$key])     => $text($texts[$key]),
            isset($dateTimes[$key]) => self::vbDate($op->{$dateTimes[$key]} ?? null, false),
            isset($dates[$key])     => self::vbDate($op->{$dates[$key]} ?? null, true),
            in_array($key, ['operacion', 'codigooperacion'], true)
                => VeolabCodes::format('LABOPE', $text('OPE1COD'), $text('DEL3COD'), $text('OPE1SER'), $text('OPECINF')),
            $key === 'numenvasesoperacion' => VeolabFormat::cstr((float) ($op->OPENENV ?? 0)),
            $key === 'redoperacion'        => (string) (int) ($op->OPENRED ?? 0),
            $key === 'localidadoperacion'  => (string) (int) ($op->OPENLOC ?? 0),
            $key === 'urgenteoperacion'    => $text('OPEBURG') === 'T' ? $yes : $no,
            $key === 'controloperacion'    => $text('OPEBCON') === 'T' ? $yes : $no,
            $key === 'anuladaoperacion'    => $text('OPEBANU') === 'T' ? $yes : $no,
            $key === 'visiblesinacoperacion'   => $text('OPEBVIS') === 'F' ? $no : $yes,
            $key === 'visiblesinacsnoperacion' => $text('OPEBVIS') === 'F' ? 'N' : 'S',
            default => throw new \DomainException($name),
        };
    }

    private function techniqueField(string $name, ?object $res): string
    {
        if ($res === null) {
            return '';
        }
        $text = fn (string $column) => (string) ($res->$column ?? '');

        $texts = [
            'descuentotecnica'            => 'RESCDTO',
            'unidadestecnica'             => 'RESCUNI',
            'leyendatecnica'              => 'RESCLEY',
            'abreviaturatecnica'          => 'RESCABR',
            'numerocastecnica'            => 'RESCCAS',
            'castecnica'                  => 'RESCCAS',
            'metodologiatecnica'          => 'RESCMET',
            'metodologiaabreviadatecnica' => 'RESCMEA',
            'normativatecnica'            => 'RESCNOR',
            'limitetecnica'               => 'RESCLIM',
            'minimotecnica'               => 'RESCMIN',
            'incertidumbretecnica'        => 'RESCINC',
            'instrucciontecnica'          => 'RESCINS',
        ];

        $key = mb_strtolower($name);

        return match (true) {
            isset($texts[$key]) => $text($texts[$key]),
            $key === 'codigotecnica'            => VeolabCodes::format('LABTEC', $text('TEC3COD'), $text('TEC3DEL')),
            $key === 'tiempotecnica'            => $res->RESNTIE === null ? '' : VeolabFormat::cstr((float) $res->RESNTIE),
            $key === 'fechaacreditaciontecnica' => self::vbDate($res->RESDACR, true),
            $key === 'acreditadotecnica'        => $text('RESDACR') !== '' ? 'S' : 'N',
            $key === 'fechahorainiciotecnica'   => self::vbDate($res->RESTINI, false),
            $key === 'fechainiciotecnica'       => self::vbDate($res->RESTINI, true),
            $key === 'fechahorafintecnica'      => self::vbDate($res->RESTFIN, false),
            $key === 'fechafintecnica'          => self::vbDate($res->RESTFIN, true),
            default => throw new \DomainException($name),
        };
    }

    /** Fecha de la BD como la muestra VB (dd/mm/aaaa y, si no es medianoche y se pide, H:mm:ss). */
    private static function vbDate($value, bool $dateOnly): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $time = strtotime((string) $value);
        if ($time === false) {
            return '';
        }
        $out = date('d/m/Y', $time);
        if (! $dateOnly && date('H:i:s', $time) !== '00:00:00') {
            $out .= ' '.date('G:i:s', $time);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Analizador (ExtraeAtomo / EstablecerPrioridadOperaciones)
    // ------------------------------------------------------------------

    /** ExtraeAtomo: siguiente átomo (columna, número, función, operador, literal, paréntesis o ';'). */
    private static function extractAtom(string &$formula): string
    {
        $chars = mb_str_split($formula);
        $length = count($chars);
        if ($length === 0) {
            return '';
        }

        $type = '';
        $i = 1;
        $end = false;
        do {
            if ($i > $length) {
                $end = true;
            } else {
                $char = $chars[$i - 1];
                $code = strlen($char) === 1 ? ord($char) : 0;
                if ($code >= 65 && $code <= 90) {                                   // A-Z
                    if ($type === '') {
                        $type = 'C';
                    } elseif ($type !== 'C' && $type !== 'L') {
                        $end = true;
                    }
                } elseif (in_array($char, ['+', '-', '/', '\\', '*', '^', '<', '>', '=', '&', '|', '#', ':'], true)) {
                    if ($type === '') {
                        $type = 'O';
                    } elseif ($type === 'O') {
                        if (! in_array($char, ['>', '<', '='], true)) {
                            $end = true;
                        }
                    } elseif ($type !== 'L') {
                        $end = true;
                    }
                } elseif ($char === '(' || $char === ')' || $char === ';') {
                    if ($type !== 'L') {
                        $end = true;
                    }
                } elseif ($char === ' ') {
                    // los espacios se omiten
                } elseif (in_array($char, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', ',', '.'], true)) {
                    if ($type === '') {
                        $type = 'N';
                    } elseif ($type !== 'N' && $type !== 'L') {
                        $end = true;
                    }
                } elseif ($code >= 97 && $code <= 122) {                            // a-z
                    if ($type === '') {
                        $type = 'F';
                    } elseif ($type !== 'F' && $type !== 'L') {
                        $end = true;
                    }
                } elseif ($char === '"') {
                    if ($type === 'O') {
                        $end = true;
                    } elseif ($type === 'L') {
                        $type = '';
                        $end = true;
                    } else {
                        $type = 'L';
                    }
                }
            }
            $i++;
        } while (! $end);

        $cut = $type === '' ? $i - 1 : $i - 2;
        $atom = implode('', array_slice($chars, 0, $cut));
        $formula = implode('', array_slice($chars, $cut));

        return trim(preg_replace('/[\x00-\x1F]/u', '', $atom), ' ');
    }

    /**
     * EstablecerPrioridadOperaciones: añade paréntesis alrededor de los
     * operandos de cada operador, por niveles (^, * / \, + -, :, comparaciones,
     * &, |, #). Las posiciones de los operadores se toman al principio, como VB.
     */
    private static function priority(string $formula): string
    {
        $atoms = [];
        $positions = [];
        $rest = $formula;
        do {
            $atom = self::extractAtom($rest);
            $atoms[] = $atom;
            if (in_array($atom, self::PRIORITY_OPERATORS, true)) {
                $positions[$atom][] = count($atoms);
            }
        } while ($rest !== '');

        foreach (self::PRIORITY_LEVELS as $operators) {
            foreach ($operators as $operator) {
                foreach ($positions[$operator] ?? [] as $position) {
                    self::closeRight($atoms, $position);
                    self::openLeft($atoms, $position);
                }
            }
        }

        return implode('', $atoms);
    }

    /** ParentesisOperandoDerecha. */
    private static function closeRight(array &$atoms, int $position): void
    {
        $open = 0;
        $end = 0;
        $i = $position + 1;
        do {
            $atom = self::item($atoms, $i);
            if (self::first($atom) === '(') {
                $open += substr_count($atom, '(');
            } elseif (self::last($atom) === ')') {
                $open -= substr_count($atom, ')');
                if ($open === 0) {
                    $end = $i;
                }
            } elseif (self::isLower(self::first($atom))) {
                // función: se continúa
            } elseif ($open === 0) {
                $end = $i;
            }
            $i++;
        } while ($end === 0 && $i <= count($atoms));

        if ($end > 0) {
            $element = self::item($atoms, $end);
            self::remove($atoms, $end);
            self::insertAfter($atoms, $end - 1, $element.')');
        } else {
            $element = self::item($atoms, count($atoms));
            self::remove($atoms, count($atoms));
            self::insertAfter($atoms, count($atoms), $element.')');
        }
    }

    /** ParentesisOperandoIzquierda (con el caso del menos unario). */
    private static function openLeft(array &$atoms, int $position): void
    {
        $open = 0;
        $end = 0;
        $i = $position - 1;
        if ($i <= 0) {
            // Operación no binaria: se añade un 0.
            self::insertBefore($atoms, 1, '(0');

            return;
        }

        do {
            $atom = self::item($atoms, $i);
            if (self::last($atom) === ')') {
                $open += substr_count($atom, ')');
            } elseif (self::first($atom) === '(') {
                $open -= substr_count($atom, '(');
                if ($open <= 0) {
                    $end = $i > 1 && self::isLower(self::first(self::item($atoms, $i - 1))) ? $i - 1 : $i;
                }
            } elseif (self::isLower(self::first($atom))) {
                // función: se continúa
            } elseif ($open === 0) {
                $end = $i;
            }
            $i--;
        } while ($end === 0 && $i >= 1);

        if ($end > 0) {
            $element = self::item($atoms, $end);
            if (self::item($atoms, $end + 1) === '-'
                && (self::instr('^*/\\+-&|><=>=<=#', $element) || $element === '(' || $element === ';')) {
                self::remove($atoms, $end + 1);
                self::insertBefore($atoms, $end + 1, '(0-');
            } else {
                self::remove($atoms, $end);
                self::insertBefore($atoms, $end, '('.$element);
            }
        } else {
            $element = self::item($atoms, 1);
            self::remove($atoms, 1);
            self::insertBefore($atoms, 1, $element.'(');
        }
    }

    // Colección de VB (índices desde 1; un índice fuera de rango es un error).

    private static function item(array $atoms, int $index): string
    {
        if ($index < 1 || $index > count($atoms)) {
            throw new \OutOfRangeException('Índice');
        }

        return $atoms[$index - 1];
    }

    private static function remove(array &$atoms, int $index): void
    {
        self::item($atoms, $index);
        array_splice($atoms, $index - 1, 1);
    }

    private static function insertBefore(array &$atoms, int $index, string $value): void
    {
        self::item($atoms, $index);
        array_splice($atoms, $index - 1, 0, [$value]);
    }

    private static function insertAfter(array &$atoms, int $index, string $value): void
    {
        self::item($atoms, $index);
        array_splice($atoms, $index, 0, [$value]);
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** CAD_ObtenColumnaLetra (null si no corresponde a ninguna columna posible). */
    private static function columnOf(string $letters): ?int
    {
        $column = 0;
        $power = 1;
        $chars = mb_str_split($letters);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $column += (mb_ord($chars[$i]) - 64) * $power;
            $power *= 26;
            if ($power > PHP_INT_MAX / 1000) {
                return null;
            }
        }

        return $column >= 1 ? $column : null;
    }

    /** Val de VB: número inicial con punto decimal. */
    private static function val(string $text): float
    {
        preg_match('/^\s*([+-]?\d*(?:\.\d*)?)/', $text, $m);

        return is_numeric($m[1] ?? '') ? (float) $m[1] : 0.0;
    }

    /** Mid de VB (1-based); longitud negativa o inicio < 1 es un error. */
    private static function mid(string $text, int $start, ?int $length = null): string
    {
        if ($start < 1 || ($length !== null && $length < 0)) {
            throw new \RuntimeException('Mid');
        }

        return (string) mb_substr($text, $start - 1, $length);
    }

    /** InStr de VB (con cadena buscada vacía es verdadero). */
    private static function instr(string $haystack, string $needle): bool
    {
        return $needle === '' || str_contains($haystack, $needle);
    }

    private static function first(string $text): string
    {
        return mb_substr($text, 0, 1);
    }

    private static function last(string $text): string
    {
        return mb_substr($text, -1);
    }

    private static function isLower(string $char): bool
    {
        return strlen($char) === 1 && ord($char) >= 97 && ord($char) <= 122;
    }

    /** Textos de error de Veolab (IDICAD, español). */
    private static function message(string $code): string
    {
        $db = DB::connection('dynamic');
        $key = $db->getDatabaseName();
        if (! isset(self::$messages[$key])) {
            self::$messages[$key] = $db->table('IDICAD')->where('IDI3COD', 1)
                ->whereIn('CAD1COD', ['MEN00252', 'MEN00253', 'MEN00254'])->pluck('CADCDES', 'CAD1COD')->all();
        }

        return (string) (self::$messages[$key][$code] ?? 'Error en la fórmula');
    }
}
