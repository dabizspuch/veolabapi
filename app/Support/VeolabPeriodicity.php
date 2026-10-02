<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Periodicidad de Veolab (Agenda.bas, AGE_ColeccionPeriodicidad), común a la
 * agenda (AGEAGE.AGEN*) y a las planificaciones (LABPLO.PLON*).
 *
 *  frecuencia 0 sin periodicidad: solo la fecha de inicio.
 *  1 diaria:   opcion 0 cada 'repetir' días; opcion 1 todos los laborables.
 *  2 semanal:  cada 'repetir' semanas, los días de 'dias' (producto de los
 *              primos L 2, M 3, X 5, J 7, V 11, S 13, D 17; 0 = todos).
 *  3 mensual:  cada 'repetir' meses; opcion 0 el día 'ordinal'; opcion 1 el
 *              'ordinal' (0 primer, 1 segundo, 2 tercer, 3 cuarto, 4 último)
 *              'dias' (0 día, 1 día laborable, 2..8 lunes..domingo).
 *  4 anual:    opcion 0 el 'ordinal' 'dias' (0 día, 1..7 lunes..domingo) del
 *              mes 'repetir' (0 enero..11 diciembre); opcion 1 el día de
 *              inicio de los meses de 'dias' (máscara: enero 1 ... diciembre 2048).
 *
 * Termina en la fecha de fin (si no hay número de repeticiones) o en el
 * horizonte de periodicidades (LABCON.CONDPER); con 'repeticiones' > 0 se
 * cuentan las vueltas (días, semanas, meses o años). 'trasladar' lleva cada
 * fecha al siguiente laborable (sábado, domingo y festivos de la delegación).
 *
 * Se replica Veolab, incluidas sus comparaciones (el límite es un día a las
 * 00:00, así que una fecha con hora de ese mismo día queda fuera). Se
 * corrigen tres fallos: no se repiten fechas al trasladar en la diaria, el
 * "último lunes..domingo" anual se calcula bien (Veolab resta 7 días de más)
 * y en la anual enero (repetir 0) no pasa a febrero.
 */
class VeolabPeriodicity
{
    private const MAX_ITERATIONS = 100000;

    /** Primos de los días de la semana (lunes..domingo). */
    public const WEEKDAYS = [2, 3, 5, 7, 11, 13, 17];

    private array $holidays = [];

    public function __construct(string $delegation)
    {
        $db = DB::connection('dynamic');
        // AGE_ConstruirListaFestivos: sin módulo de agenda no hay festivos.
        if (VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'AGE')) {
            foreach ($db->table('AGEFES')->where('DEL3COD', $delegation)->pluck('FESTFEC') as $date) {
                $this->holidays[substr((string) $date, 0, 10)] = true;
            }
        }
    }

    /**
     * Horizonte de las periodicidades: LABCON.CONDPER (hoy si no hay), y al
     * menos hoy + 180 días, el que fija Veolab al arrancar si ya se pasó.
     */
    public static function horizon(): DateTimeImmutable
    {
        $stored = DB::connection('dynamic')->table('LABCON')->where('CON1COD', 1)->value('CONDPER');
        $today = new DateTimeImmutable('today');
        $horizon = $stored ? new DateTimeImmutable(substr((string) $stored, 0, 10)) : $today;
        $minimum = $today->modify('+180 days');

        return $horizon < $minimum ? $minimum : $horizon;
    }

    /**
     * Fechas de la periodicidad, en el orden en que Veolab las genera.
     *
     * @return DateTimeImmutable[]
     */
    public function dates(DateTimeImmutable $start, int $frequency, int $option, int $repeat, int $ordinal, int $days,
        ?string $endDate, int $count, bool $shift, DateTimeImmutable $horizon): array
    {
        // Veolab sube a 1 cualquier 'repetir' menor, también en la anual, donde
        // es el mes (0 = enero) y enero pasaría a febrero: allí no se aplica.
        if ($frequency !== 4) {
            $repeat = max($repeat, 1);
        }
        if ($endDate === null || $endDate === '' || $count > 0) {
            $end = $horizon;
        } else {
            $endDay = new DateTimeImmutable(substr($endDate, 0, 10));
            $end = $endDay < $horizon->setTime(0, 0) ? $endDay : $horizon;
        }

        $out = [];
        $add = function (DateTimeImmutable $date) use (&$out) {
            $out[$date->format('Y-m-d H:i:s')] = $date;
        };
        $within = fn (DateTimeImmutable $date, int $loop) => $date <= $end && ($count === 0 || $loop < $count);
        $guard = 0;
        $tick = function () use (&$guard) {
            if (++$guard > self::MAX_ITERATIONS) {
                throw new BusinessRuleException('La periodicidad no avanza la fecha: revise el intervalo de repetición');
            }
        };

        switch ($frequency) {
            case 0:
                $add($start);
                break;

            case 1:
                $date = $start;
                $loop = 0;
                $finished = false;
                do {
                    if ($option === 0) {
                        $real = $date;
                        if ($shift) {
                            $date = $this->nextWorking($date);
                        }
                    } else {
                        $date = $this->nextWorking($date);
                        $real = $date;
                    }
                    if ($within($date, $loop)) {
                        if ($date >= $start) {
                            $add($date);
                        }
                    } else {
                        $finished = true;
                    }
                    if ($count > 0) {
                        $loop++;
                    }
                    $date = $real->modify('+'.($option === 0 ? $repeat : 1).' days');
                    $tick();
                } while (! $finished);
                break;

            case 2:
                $monday = $this->monday($start);
                $loop = 0;
                $finished = false;
                do {
                    foreach (self::WEEKDAYS as $i => $prime) {
                        $real = $monday->modify("+{$i} days");
                        $date = $shift ? $this->nextWorking($real) : $real;
                        if ($within($date, $loop)) {
                            if ($days % $prime === 0 && $date >= $start) {
                                $add($date);
                            }
                        } else {
                            $finished = true;
                        }
                    }
                    if ($count > 0) {
                        $loop++;
                    }
                    $monday = $this->monday($monday->modify('+6 days')->modify('+'.(7 * $repeat).' days'));
                    $tick();
                } while (! $finished);
                break;

            case 3:
                $date = $start;
                $loop = 0;
                $finished = false;
                $aux = (int) $date->format('n');
                do {
                    $month = $aux % 12;
                    $year = (int) $date->format('Y') + intdiv($aux, 12) - ($month === 0 ? 1 : 0);
                    if ($month === 0) {
                        $month = 12;
                    }

                    if ($option === 0) {
                        $date = $this->withTime($this->approxDate($ordinal, $month, $year), $date, true);
                        $real = $date;
                        if ($shift) {
                            $date = $this->nextWorking($date);
                        }
                        if ($date->format('Y-m-d') >= $start->format('Y-m-d')) {
                            if ($within($date, $loop)) {
                                if ($date >= $start) {
                                    $add($date);
                                }
                            } else {
                                $finished = true;
                            }
                            if ($count > 0) {
                                $loop++;
                            }
                        }
                        $date = $real;
                    } else {
                        $day = $this->monthDay($days, $ordinal, $month, $year, $date);
                        $date = $this->withTime($this->exactDate($day, $month, $year), $date, false);
                        if ($date >= $start) {
                            $real = $date;
                            if ($shift) {
                                $date = $this->nextWorking($date);
                            }
                            if ($within($date, $loop)) {
                                if ($date >= $start) {
                                    $add($date);
                                }
                            } else {
                                $finished = true;
                            }
                            $date = $real;
                            if ($count > 0) {
                                $loop++;
                            }
                        }
                    }
                    $aux = (int) $date->format('n') + $repeat;
                    $tick();
                } while (! $finished);
                break;

            case 4:
                $loop = 0;
                $finished = false;
                if ($option === 0) {
                    $date = $start;
                    $year = (int) $date->format('Y');
                    do {
                        $month = min(max($repeat, 0), 11) + 1;
                        $day = $this->yearDay($days, $ordinal, $month, $year, $date);
                        $date = $this->withTime($this->exactDate($day, $month, $year), $date, false);
                        $real = $date;
                        if ($shift) {
                            $date = $this->nextWorking($date);
                        }
                        if ($within($date, $loop)) {
                            if ($date >= $start) {
                                $add($date);
                            }
                        } else {
                            $finished = true;
                        }
                        $date = $real;
                        if ($count > 0 && $date > $start) {
                            $loop++;
                        }
                        $year = (int) $date->format('Y') + 1;
                        $tick();
                    } while (! $finished);
                } else {
                    // Cada mes marcado, el día del inicio (ajustado al fin de mes, sin traslado).
                    $date = $start;
                    do {
                        for ($month = 1; $month <= 12; $month++) {
                            if ((int) $date->format('n') !== $month) {
                                continue;
                            }
                            if (! $finished) {
                                if ($within($date, $loop)) {
                                    if (($days & (1 << ($month - 1))) && $date >= $start) {
                                        $add($date);
                                    }
                                } else {
                                    $finished = true;
                                }
                            }
                            $nextMonth = $month === 12 ? 1 : $month + 1;
                            $nextYear = (int) $date->format('Y') + ($month === 12 ? 1 : 0);
                            $date = $this->withTime($this->approxDate((int) $date->format('j'), $nextMonth, $nextYear), $date, false);
                        }
                        if ($count > 0) {
                            $loop++;
                        }
                        $tick();
                    } while (! $finished);
                }
                break;

            default:
                throw new BusinessRuleException('La frecuencia de la periodicidad no es válida');
        }

        return array_values($out);
    }

    // ------------------------------------------------------------------
    // Días del mes (mensual opción 1 y anual opción 0)
    // ------------------------------------------------------------------

    private function monthDay(int $kind, int $ordinal, int $month, int $year, DateTimeImmutable $time): int
    {
        $last = $this->daysInMonth($month, $year);
        if ($kind === 0) {
            return $ordinal < 4 ? $ordinal + 1 : $last;
        }
        if ($kind === 1) {
            if ($ordinal >= 4) {
                return (int) $this->previousWorking($this->exactDate($last, $month, $year))->format('j');
            }
            $found = 0;
            for ($day = 1; $day <= $last; $day++) {
                if ($this->isWorking($this->exactDate($day, $month, $year)) && ++$found === $ordinal + 1) {
                    return $day;
                }
            }

            return $last;
        }

        return $this->weekdayOfMonth($kind - 2, $ordinal, $month, $year);
    }

    private function yearDay(int $kind, int $ordinal, int $month, int $year, DateTimeImmutable $time): int
    {
        if ($kind === 0) {
            return $ordinal < 4 ? $ordinal + 1 : $this->daysInMonth($month, $year);
        }

        return $this->weekdayOfMonth($kind - 1, $ordinal, $month, $year);
    }

    /**
     * Día del mes del 'ordinal' (0..3, 4 último) día de la semana $target
     * (0 lunes .. 6 domingo), con el cálculo de Veolab: el lunes de la semana
     * del día 7·ordinal+1 (una semana más si el primero cae en el mes anterior).
     */
    private function weekdayOfMonth(int $target, int $ordinal, int $month, int $year): int
    {
        if ($ordinal < 4) {
            $first = $this->monday($this->exactDate(1, $month, $year))->modify("+{$target} days");
            $extra = ((int) $first->format('n') < $month || (int) $first->format('Y') < $year) ? 7 : 0;
            $date = $this->monday($this->exactDate(7 * $ordinal + 1, $month, $year)->modify("+{$extra} days"))->modify("+{$target} days");

            return (int) $date->format('j');
        }

        $last = $this->daysInMonth($month, $year);
        $weekday = (int) $this->exactDate($last, $month, $year)->format('N') - 1;

        return $last - (($weekday - $target + 7) % 7);
    }

    // ------------------------------------------------------------------
    // Utilidades de fechas (Agenda.bas)
    // ------------------------------------------------------------------

    private function isHoliday(DateTimeImmutable $date): bool
    {
        return isset($this->holidays[$date->format('Y-m-d')]);
    }

    private function isWorking(DateTimeImmutable $date): bool
    {
        return (int) $date->format('N') < 6 && ! $this->isHoliday($date);
    }

    /** AGE_SigLaborable: el mismo día o el siguiente laborable (conserva la hora). */
    private function nextWorking(DateTimeImmutable $date): DateTimeImmutable
    {
        for ($i = 0; $i < 1000; $i++) {
            if ($this->isHoliday($date)) {
                $date = $date->modify('+1 day');
                continue;
            }
            $weekday = (int) $date->format('N');
            if ($weekday === 6) {
                $date = $date->modify('+2 days');
            } elseif ($weekday === 7) {
                $date = $date->modify('+1 day');
            } else {
                return $date;
            }
        }

        return $date;
    }

    /** AGE_AntLaborable: el mismo día o el anterior laborable. */
    private function previousWorking(DateTimeImmutable $date): DateTimeImmutable
    {
        for ($i = 0; $i < 1000; $i++) {
            if ($this->isHoliday($date)) {
                $date = $date->modify('-1 day');
                continue;
            }
            $weekday = (int) $date->format('N');
            if ($weekday === 6) {
                $date = $date->modify('-1 day');
            } elseif ($weekday === 7) {
                $date = $date->modify('-2 days');
            } else {
                return $date;
            }
        }

        return $date;
    }

    /** AGE_ObtenLunes (conserva la hora). */
    private function monday(DateTimeImmutable $date): DateTimeImmutable
    {
        $back = (int) $date->format('N') - 1;

        return $back > 0 ? $date->modify("-{$back} days") : $date;
    }

    private function daysInMonth(int $month, int $year): int
    {
        return (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    }

    /** AGE_ObtenFechaAprox: el día ajustado a 1..fin de mes. */
    private function approxDate(int $day, int $month, int $year): DateTimeImmutable
    {
        $day = min(max($day, 1), $this->daysInMonth($month, $year));

        return $this->exactDate($day, $month, $year);
    }

    private function exactDate(int $day, int $month, int $year): DateTimeImmutable
    {
        if ($day < 1 || $day > $this->daysInMonth($month, $year)) {
            throw new BusinessRuleException("La periodicidad da una fecha no válida ({$day}/{$month}/{$year})");
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** La fecha con la hora de $time; Veolab conserva los segundos solo en algunos casos. */
    private function withTime(DateTimeImmutable $date, DateTimeImmutable $time, bool $seconds): DateTimeImmutable
    {
        return $date->setTime((int) $time->format('G'), (int) $time->format('i'), $seconds ? (int) $time->format('s') : 0);
    }

    // ------------------------------------------------------------------
    // Entrada de la API
    // ------------------------------------------------------------------

    /** Reglas del objeto 'periodicidad' de la API. */
    public static function rules(string $prefix): array
    {
        return [
            $prefix                           => 'nullable|array',
            "{$prefix}.frecuencia"            => 'required_with:'.$prefix.'|integer|in:0,1,2,3,4',
            "{$prefix}.opcion"                => 'nullable|integer|in:0,1',
            "{$prefix}.repetir"               => 'nullable|integer|min:0|max:1000',
            "{$prefix}.ordinal"               => 'nullable|integer|min:0|max:31',
            "{$prefix}.dias"                  => 'nullable|integer|min:0',
            "{$prefix}.dias_semana"           => 'nullable|array',
            "{$prefix}.dias_semana.*"         => 'string|in:L,M,X,J,V,S,D',
            "{$prefix}.meses"                 => 'nullable|array',
            "{$prefix}.meses.*"               => 'integer|min:1|max:12',
            "{$prefix}.fecha_fin"             => 'nullable|date',
            "{$prefix}.repeticiones"          => 'nullable|integer|min:0|max:10000',
            "{$prefix}.trasladar_laborable"   => 'nullable|string|in:T,F',
        ];
    }

    /**
     * Normaliza el objeto 'periodicidad' a [frecuencia, opcion, repetir,
     * ordinal, dias, fecha_fin, repeticiones, trasladar] y lo valida.
     */
    public static function normalize(array $input): array
    {
        $frequency = (int) $input['frecuencia'];
        $option = (int) ($input['opcion'] ?? 0);
        $repeat = (int) ($input['repetir'] ?? ($frequency === 4 ? 0 : 1));
        $ordinal = (int) ($input['ordinal'] ?? 0);
        $days = (int) ($input['dias'] ?? 0);

        if (! empty($input['dias_semana'])) {
            $days = 1;
            foreach (array_unique($input['dias_semana']) as $letter) {
                $days *= self::WEEKDAYS[strpos('LMXJVSD', $letter)];
            }
        }
        if (! empty($input['meses'])) {
            $days = 0;
            foreach (array_unique($input['meses']) as $month) {
                $days |= 1 << ((int) $month - 1);
            }
        }

        $fail = fn (string $message) => throw new BusinessRuleException("Periodicidad: {$message}");
        switch ($frequency) {
            case 1:
                if ($option === 0 && $repeat < 1) {
                    $fail('el intervalo de días debe ser al menos 1');
                }
                break;
            case 2:
                if ($repeat < 1) {
                    $fail('el intervalo de semanas debe ser al menos 1');
                }
                if ($days < 0) {
                    $fail('días de la semana no válidos');
                }
                break;
            case 3:
                if ($repeat < 1) {
                    $fail('el intervalo de meses debe ser al menos 1');
                }
                if ($option === 0 && ($ordinal < 1 || $ordinal > 31)) {
                    $fail('el día del mes (ordinal) debe estar entre 1 y 31');
                }
                if ($option === 1 && ($ordinal > 4 || $days > 8)) {
                    $fail('ordinal 0..4 y dias 0 (día), 1 (laborable) o 2..8 (lunes..domingo)');
                }
                break;
            case 4:
                if ($option === 0 && ($repeat > 11 || $ordinal > 4 || $days > 7)) {
                    $fail('mes (repetir) 0..11, ordinal 0..4 y dias 0 (día) o 1..7 (lunes..domingo)');
                }
                if ($option === 1 && ($days < 1 || $days > 4095)) {
                    $fail('indique los meses');
                }
                break;
        }

        return [
            'frecuencia'   => $frequency,
            'opcion'       => $option,
            'repetir'      => $repeat,
            'ordinal'      => $ordinal,
            'dias'         => $days,
            'fecha_fin'    => ! empty($input['fecha_fin']) ? (new DateTimeImmutable($input['fecha_fin']))->format('Y-m-d 00:00:00') : null,
            'repeticiones' => (int) ($input['repeticiones'] ?? 0),
            'trasladar'    => ($input['trasladar_laborable'] ?? 'F') === 'T',
        ];
    }

    /**
     * El objeto 'repeticion' de la API a partir de los campos guardados
     * (null sin periodicidad), con dias_semana o meses cuando aplican.
     */
    public static function describe(int $frequency, int $option, int $repeat, int $ordinal, int $days,
        $endDate, int $count, bool $shift): ?array
    {
        if ($frequency <= 0) {
            return null;
        }
        $out = [
            'frecuencia'          => $frequency,
            'opcion'              => $option,
            'repetir'             => $repeat,
            'ordinal'             => $ordinal,
            'dias'                => $days,
            'fecha_fin'           => $endDate ?: null,
            'repeticiones'        => $count,
            'trasladar_laborable' => $shift ? 'T' : 'F',
        ];
        if ($frequency === 2) {
            $out['dias_semana'] = self::weekdayLetters($days) ?: str_split('LMXJVSD');
        }
        if ($frequency === 4 && $option === 1) {
            $out['meses'] = self::monthList($days);
        }

        return $out;
    }

    /** Días de la semana (L..D) de un valor 'dias' semanal. */
    public static function weekdayLetters(int $days): array
    {
        $letters = [];
        foreach (self::WEEKDAYS as $i => $prime) {
            if ($days !== 0 && $days % $prime === 0) {
                $letters[] = 'LMXJVSD'[$i];
            }
        }

        return $letters;
    }

    /** Meses (1..12) de una máscara anual. */
    public static function monthList(int $days): array
    {
        return array_values(array_filter(range(1, 12), fn ($m) => (bool) ($days & (1 << ($m - 1)))));
    }
}
