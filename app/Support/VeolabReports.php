<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lógica de los informes de Veolab (FichaInforme.frm / Informes.frm) que no es
 * de un solo campo: estado de las firmas por tipo (LABTIF / LABFIR), estado de
 * validación que resulta de ellas, efecto del informe sobre sus operaciones y
 * valores por defecto de un informe nuevo.
 *
 * Trabaja sobre la conexión 'dynamic' y dentro de la transacción del cambio.
 */
class VeolabReports
{
    // Estado de un tipo de firma, de más a menos prioritario (EsFirmaPrioritaria).
    public const RECHAZADO = 'rechazado';
    public const RECHAZADO_PARCIAL = 'rechazado_parcial';
    public const FIRMADO = 'firmado';
    public const FIRMADO_PARCIAL = 'firmado_parcial';
    public const PENDIENTE = 'pendiente';

    private const PRIORITY = [
        self::RECHAZADO => 1, self::RECHAZADO_PARCIAL => 2, self::FIRMADO => 3,
        self::FIRMADO_PARCIAL => 4, self::PENDIENTE => 5,
    ];

    // ------------------------------------------------------------------
    // Firmas
    // ------------------------------------------------------------------

    /**
     * Tipos de firma del informe en su orden (LeerTiposFirmas + LeerRegistro):
     * los que están en vigor de la delegación del informe o generales, por
     * TIFNORD, más los que ya tengan firmas en el informe. Cada uno con su
     * estado: [['del', 'cod', 'obl' => bool, 'state', 'rows' => [...]]].
     */
    public static function signatureTypes(array $report): array
    {
        $db = DB::connection('dynamic');

        $rows = $db->table('LABFIR')
            ->where('INF3DEL', $report[0])->where('INF3SER', $report[1])->where('INF3COD', $report[2])
            ->orderBy('TIF3DEL')->orderBy('TIF3COD')->orderBy('DEP3DEL')->orderBy('DEP3COD')
            ->get();

        $types = [];
        $add = function ($type) use (&$types) {
            $types[$type->DEL3COD."\x1B".(int) $type->TIF1COD] ??= [
                'del' => (string) $type->DEL3COD, 'cod' => (int) $type->TIF1COD,
                'obl' => $type->TIFBOBL === 'T', 'state' => self::PENDIENTE, 'rows' => [],
            ];
        };

        $active = $db->table('LABTIF')
            ->where(fn ($q) => $q->whereNull('TIFBBAJ')->orWhere('TIFBBAJ', '<>', 'T'))
            ->whereIn('DEL3COD', array_unique([$report[0], '']))
            ->orderBy('TIFNORD')->orderBy('DEL3COD')->orderBy('TIF1COD')
            ->get(['DEL3COD', 'TIF1COD', 'TIFBOBL']);
        foreach ($active as $type) {
            $add($type);
        }

        foreach ($rows as $row) {
            $key = $row->TIF3DEL."\x1B".(int) $row->TIF3COD;
            if (! isset($types[$key])) {
                $obligatory = $db->table('LABTIF')->where('DEL3COD', $row->TIF3DEL)
                    ->where('TIF1COD', $row->TIF3COD)->value('TIFBOBL');
                $add((object) ['DEL3COD' => $row->TIF3DEL, 'TIF1COD' => $row->TIF3COD, 'TIFBOBL' => $obligatory]);
            }
            $types[$key]['rows'][] = $row;
            $state = self::rowState($row);
            if (self::PRIORITY[$state] <= self::PRIORITY[$types[$key]['state']]) {
                $types[$key]['state'] = $state;
            }
        }

        return array_values($types);
    }

    /** ObtenerIconoFirma: estado de una fila de LABFIR. */
    private static function rowState(object $row): string
    {
        if ((string) $row->USU2COD === '') {
            return self::PENDIENTE;
        }
        $total = (int) $row->DEP3COD === 0;

        if ($row->FIRBVAL === 'T') {
            return $total ? self::FIRMADO : self::FIRMADO_PARCIAL;
        }

        return $total ? self::RECHAZADO : self::RECHAZADO_PARCIAL;
    }

    /** Posición del tipo de firma en la lista, o null. */
    public static function typeIndex(array $types, string $delegation, int $code): ?int
    {
        foreach ($types as $i => $type) {
            if ($type['del'] === $delegation && $type['cod'] === $code) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Firmar/rechazar: se permite si la firma obligatoria anterior (si la hay)
     * ya no está pendiente.
     */
    public static function canSign(array $types, int $index): bool
    {
        for ($k = $index - 1; $k >= 0; $k--) {
            if ($types[$k]['obl']) {
                return $types[$k]['state'] !== self::PENDIENTE;
            }
        }

        return true;
    }

    /**
     * Eliminar firma: se permite si la firma obligatoria siguiente (si la hay)
     * sigue pendiente.
     */
    public static function canRemove(array $types, int $index): bool
    {
        for ($k = $index + 1; $k < count($types); $k++) {
            if ($types[$k]['obl']) {
                return $types[$k]['state'] === self::PENDIENTE;
            }
        }

        return true;
    }

    /**
     * ¿Informe firmado? (EstadoControlesPostLectura): alguna firma total y
     * ninguna rechazada. Bloquea los datos del informe y sus operaciones.
     */
    public static function isSigned(array $types): bool
    {
        $signed = false;
        foreach ($types as $type) {
            if ($type['state'] === self::RECHAZADO || $type['state'] === self::RECHAZADO_PARCIAL) {
                return false;
            }
            $signed = $signed || $type['state'] === self::FIRMADO;
        }

        return $signed;
    }

    /**
     * ActualizarEstadoValidacion: 'R' si hay un rechazo (total, o parcial de
     * una firma obligatoria); 'P' si queda pendiente una firma obligatoria o
     * una firma parcial no cubre todos los departamentos del informe; si no, 'V'.
     */
    public static function validationState(array $types, array $report): string
    {
        $rejected = false;
        $pending = false;
        $departments = null;

        foreach ($types as $type) {
            switch ($type['state']) {
                case self::FIRMADO_PARCIAL:
                    $departments ??= self::departments($report);
                    $signed = 0;
                    foreach ($departments as [$del, $cod]) {
                        foreach ($type['rows'] as $row) {
                            if ((string) $row->DEP3DEL === $del && (int) $row->DEP3COD === $cod && $row->FIRBVAL === 'T') {
                                $signed++;
                            }
                        }
                    }
                    $pending = $pending || $signed < count($departments);
                    break;
                case self::RECHAZADO:
                    $rejected = true;
                    break;
                case self::RECHAZADO_PARCIAL:
                    $rejected = $rejected || $type['obl'];
                    break;
                case self::PENDIENTE:
                    $pending = $pending || $type['obl'];
                    break;
            }
        }

        return $rejected ? 'R' : ($pending ? 'P' : 'V');
    }

    /**
     * Departamentos del informe (ListaDepartamentos): los de sus operaciones
     * en vigor (LABOYD) que no están de baja. Sin ninguno, el "departamento vacío" que
     * deja Veolab en la lista, con el que una firma parcial nunca se completa.
     */
    public static function departments(array $report): array
    {
        $rows = DB::connection('dynamic')->table('LABOYD')
            ->join('LABIYO', function ($join) {
                $join->on('LABOYD.OPE3DEL', '=', 'LABIYO.OPE3DEL')->on('LABOYD.OPE3SER', '=', 'LABIYO.OPE3SER')
                    ->on('LABOYD.OPE3COD', '=', 'LABIYO.OPE3COD');
            })
            ->join('GRHDEP', function ($join) {
                $join->on('GRHDEP.DEL3COD', '=', 'LABOYD.DEP3DEL')->on('GRHDEP.DEP1COD', '=', 'LABOYD.DEP3COD');
            })
            ->where('LABIYO.INF3DEL', $report[0])->where('LABIYO.INF3SER', $report[1])->where('LABIYO.INF3COD', $report[2])
            ->where(fn ($q) => $q->whereNull('LABIYO.IYOBHIS')->orWhere('LABIYO.IYOBHIS', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('GRHDEP.DEPBBAJ')->orWhere('GRHDEP.DEPBBAJ', '<>', 'T'))
            ->distinct()->orderBy('GRHDEP.DEL3COD')->orderBy('GRHDEP.DEP1COD')
            ->get(['GRHDEP.DEL3COD', 'GRHDEP.DEP1COD'])
            ->map(fn ($row) => [(string) $row->DEL3COD, (int) $row->DEP1COD])->all();

        return $rows ?: [['', 0]];
    }

    /** Último tipo de firma aplicado (LABINF.TIF2DEL / TIF2COD), o ['', 0]. */
    public static function lastSignedType(array $types): array
    {
        $last = ['', 0];
        foreach ($types as $type) {
            if ($type['state'] !== self::PENDIENTE) {
                $last = [$type['del'], $type['cod']];
            }
        }

        return $last;
    }

    // ------------------------------------------------------------------
    // Efecto sobre las operaciones
    // ------------------------------------------------------------------

    /**
     * Operaciones del informe: [[del, ser, cod]]. Por defecto las que están
     * en vigor; con $historical, las de la lista de histórico (IYOBHIS = 'T'),
     * que el informe solo muestra como evolución y no le pertenecen.
     */
    public static function operations(array $report, bool $historical = false): array
    {
        return DB::connection('dynamic')->table('LABIYO')
            ->where('INF3DEL', $report[0])->where('INF3SER', $report[1])->where('INF3COD', $report[2])
            ->where(fn ($q) => $historical
                ? $q->where('IYOBHIS', 'T')
                : $q->whereNull('IYOBHIS')->orWhere('IYOBHIS', '<>', 'T'))
            ->orderBy('OPE3DEL')->orderBy('OPE3SER')->orderBy('OPE3COD')
            ->get(['OPE3DEL', 'OPE3SER', 'OPE3COD'])
            ->map(fn ($row) => [(string) $row->OPE3DEL, (string) $row->OPE3SER, (int) $row->OPE3COD])->all();
    }

    /**
     * FichaInforme.Grabar, "Estado de las operaciones": un informe final
     * lleva sus fechas de informe y envío a las operaciones y las pasa a
     * enviadas (con fecha de envío), finalizadas (pendiente), validadas
     * (validado) o de vuelta a preparadas (rechazado, las afectadas).
     */
    public static function syncOperations(array $report): void
    {
        $db = DB::connection('dynamic');
        $row = $db->table('LABINF')
            ->where('DEL3COD', $report[0])->where('INF1SER', $report[1])->where('INF1COD', $report[2])
            ->first(['INFCVAL', 'INFDCRE', 'INFDENV', 'INFDVAL', 'INFBFIN']);
        $operations = self::operations($report);

        if (! $row || $row->INFBFIN !== 'T' || $operations === []) {
            return;
        }

        $date = fn ($value) => $value === null ? null : substr((string) $value, 0, 10);
        $ops = fn () => self::whereOperations($db->table('LABOPE'), $operations);

        $ops()->update(['OPEDINF' => $date($row->INFDCRE), 'OPEDENV' => $date($row->INFDENV)]);

        if ($row->INFDENV !== null) {
            self::fillStateDates(6, $operations);
            $ops()->where('OPENEST', '<', 6)->update(['OPENEST' => 6]);
            if ($row->INFCVAL === 'V') {
                $ops()->whereNull('OPEDVAL')->update(['OPEDVAL' => $date($row->INFDVAL)]);
            }

            return;
        }

        switch ($row->INFCVAL) {
            case 'P':
                self::fillStateDates(4, $operations);
                $ops()->where('OPENEST', '<>', 4)->update(['OPENEST' => 4]);
                break;
            case 'V':
                self::fillStateDates(5, $operations);
                $ops()->where(fn ($q) => $q->where('OPENEST', '<', 5)->orWhere('OPENEST', 6))->update(['OPENEST' => 5]);
                $ops()->whereNull('OPEDVAL')->update(['OPEDVAL' => $date($row->INFDVAL)]);
                break;
            case 'R':
                $rejected = self::rejectedOperations($report, $operations);
                if ($rejected) {
                    self::whereOperations($db->table('LABOPE'), $rejected)
                        ->update(['OPENEST' => 2, 'OPEDINI' => null, 'OPEDFIN' => null, 'OPEDVAL' => null]);
                }
                break;
        }
    }

    /**
     * LAB_ActualizarFechasEstados desde el informe: las fechas vacías de los
     * estados anteriores (hasta finalizada) toman la fecha de hoy.
     */
    private static function fillStateDates(int $state, array $operations): void
    {
        foreach ([4 => 'OPEDFIN', 3 => 'OPEDINI', 2 => 'OPEDPRE', 1 => 'OPETREP'] as $from => $column) {
            if ($state >= $from) {
                self::whereOperations(DB::connection('dynamic')->table('LABOPE'), $operations)
                    ->whereNull($column)->where('OPENEST', '<', $state)
                    ->update([$column => DB::raw('CURDATE()')]);
            }
        }
    }

    /**
     * OperacionRechazada: con una firma rechazada total, todas las
     * operaciones; con rechazos parciales, las de los departamentos que
     * rechazan (LABOYD). Un rechazo sin firmas no toca las operaciones.
     */
    private static function rejectedOperations(array $report, array $operations): array
    {
        $departments = [];
        foreach (self::signatureTypes($report) as $type) {
            if ($type['state'] === self::RECHAZADO) {
                return $operations;
            }
            if ($type['state'] === self::RECHAZADO_PARCIAL) {
                foreach ($type['rows'] as $row) {
                    if ($row->FIRBVAL !== 'T') {
                        $departments[] = [(string) $row->DEP3DEL, (int) $row->DEP3COD];
                    }
                }
            }
        }
        if ($departments === []) {
            return [];
        }

        return array_values(array_filter($operations, function ($operation) use ($departments) {
            return DB::connection('dynamic')->table('LABOYD')
                ->where('OPE3DEL', $operation[0])->where('OPE3SER', $operation[1])->where('OPE3COD', $operation[2])
                ->where(function ($q) use ($departments) {
                    foreach ($departments as [$del, $cod]) {
                        $q->orWhere(fn ($w) => $w->where('DEP3DEL', $del)->where('DEP3COD', $cod));
                    }
                })
                ->exists();
        }));
    }

    /** Filtra una consulta por una lista de operaciones [[del, ser, cod]]. */
    public static function whereOperations($query, array $operations, array $columns = ['DEL3COD', 'OPE1SER', 'OPE1COD'])
    {
        return $query->where(function ($q) use ($operations, $columns) {
            foreach ($operations as $operation) {
                $q->orWhere(fn ($w) => $w->where($columns[0], $operation[0])
                    ->where($columns[1], $operation[1])->where($columns[2], $operation[2]));
            }
        });
    }

    // ------------------------------------------------------------------
    // Valores por defecto de un informe nuevo
    // ------------------------------------------------------------------

    /** HayTecnicasAcreditadas: alguna técnica de las operaciones con fecha de acreditación. */
    public static function hasAccreditedTechniques(array $operations): bool
    {
        return self::whereOperations(DB::connection('dynamic')->table('LABRES'), $operations,
            ['OPE3DEL', 'OPE3SER', 'OPE3COD'])->whereNotNull('RESDACR')->exists();
    }

    /** Normativa del servicio de la operación de menor código: [del, cod] o null. */
    public static function defaultRegulation(array $operations): ?array
    {
        usort($operations, fn ($a, $b) => $a[2] <=> $b[2]);
        [$del, $ser, $cod] = $operations[0];

        $row = DB::connection('dynamic')->table('LABOYS')
            ->join('LABSER', function ($join) {
                $join->on('LABSER.DEL3COD', '=', 'LABOYS.SER3DEL')->on('LABSER.SER1COD', '=', 'LABOYS.SER3COD');
            })
            ->where('LABOYS.OPE3DEL', $del)->where('LABOYS.OPE3SER', $ser)->where('LABOYS.OPE3COD', $cod)
            ->first(['LABSER.NOR2DEL', 'LABSER.NOR2COD']);

        return $row && (string) $row->NOR2COD !== '' ? [(string) $row->NOR2DEL, (string) $row->NOR2COD] : null;
    }

    /** Forma de envío del cliente de la primera operación: [del, cod] o null. */
    public static function defaultDelivery(array $operations): ?array
    {
        [$del, $ser, $cod] = $operations[0];

        $row = DB::connection('dynamic')->table('LABOPE')
            ->join('SINCLI', function ($join) {
                $join->on('LABOPE.CLI2DEL', '=', 'SINCLI.DEL3COD')->on('LABOPE.CLI2COD', '=', 'SINCLI.CLI1COD');
            })
            ->join('LABFDE', function ($join) {
                $join->on('SINCLI.FDE2DEL', '=', 'LABFDE.DEL3COD')->on('SINCLI.FDE2COD', '=', 'LABFDE.FDE1COD');
            })
            ->where('LABOPE.DEL3COD', $del)->where('LABOPE.OPE1SER', $ser)->where('LABOPE.OPE1COD', $cod)
            ->first(['LABFDE.DEL3COD', 'LABFDE.FDE1COD']);

        return $row ? [(string) $row->DEL3COD, (int) $row->FDE1COD] : null;
    }

    /**
     * ObtenerOpinion: opinión automática (LABOEI.OEICAUT): por marca de los
     * resultados ('M') o sin marcas ('I'); si no hay, con normativa ('C') o
     * sin ella ('S').
     */
    public static function defaultOpinion(array $operations, bool $hasRegulation): string
    {
        $db = DB::connection('dynamic');
        $opinions = fn () => $db->table('LABOEI')->orderBy('DEL3COD')->orderBy('OEI1COD');

        $marks = self::whereOperations($db->table('LABCOR'), $operations, ['OPE3DEL', 'OPE3SER', 'OPE3COD'])
            ->where('MAR2COD', '<>', 0)->distinct()->get(['MAR2DEL', 'MAR2COD']);

        if ($marks->isEmpty()) {
            $opinion = $opinions()->where('OEICAUT', 'I')->first(['OEICVAL']);
        } else {
            $opinion = $opinions()->where('OEICAUT', 'M')
                ->where(function ($q) use ($marks) {
                    foreach ($marks as $mark) {
                        $q->orWhere(fn ($w) => $w->where('MAR2DEL', $mark->MAR2DEL)->where('MAR2COD', $mark->MAR2COD));
                    }
                })
                ->first(['OEICVAL']);
        }

        $opinion ??= $opinions()->where('OEICAUT', $hasRegulation ? 'C' : 'S')->first(['OEICVAL']);

        return (string) ($opinion->OEICVAL ?? '');
    }
}
