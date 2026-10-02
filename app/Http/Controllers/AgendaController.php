<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAgenda;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabPeriodicity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Eventos de agenda (AGEAGE + fechas AGEFEC + asistentes AGEASI), como
 * FichaCalendario y Calendario de Veolab. Clave: usuario dueño
 * (usuario_delegacion + usuario_codigo) + codigo (contador por usuario).
 *
 *  - Fechas: 'inicio' y 'fin' (la duración de cada fecha) y opcionalmente
 *    'repeticion' (ver VeolabPeriodicity) generan las fechas hasta el
 *    horizonte de periodicidades, como FichaCalendario. Sin 'repeticion' se
 *    conserva la periodicidad que tenía el evento.
 *  - Avisos (ACCAVI tipo A), como la ficha: para el dueño y cada asistente,
 *    uno al inicio de cada fecha y otro antes según aviso_numero/aviso_unidad
 *    (M minutos, H horas, D días, S semanas). Al cambiar las fechas se
 *    rehacen todos; al cambiar asistentes o aviso (Veolab no lo hace) se
 *    rehacen los de las fechas que no han empezado.
 *  - Lectura: cada evento lleva 'fechas' y 'asistentes'. GET
 *    /agenda/fechas?usuario_*&desde=&hasta= da las fechas de los eventos del
 *    usuario o a los que asiste, en orden (calendario).
 *  - Borrado (Calendario.Borrar): fechas, asistentes y avisos; además (Veolab
 *    no lo hace) los documentos del evento van a la papelera.
 */
class AgendaController extends BaseController
{
    protected string $table = 'AGEAGE';
    protected ?string $auditDescription = 'AGECASU';
    protected array $keys = [
        'usuario_delegacion' => 'USU3DEL',
        'usuario_codigo'     => 'USU3COD',
        'codigo'             => 'AGE1COD',
    ];
    protected array $searchFields = ['AGECASU', 'AGECUBI', 'AGECNOT'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'usuario_delegacion';
    protected ?string $seriesKey = 'usuario_codigo';

    protected array $foreignKeys = [
        'estado'        => 'int',
        'clasificacion' => 'int',
        'planificacion' => 'int',
    ];

    protected array $mapping = [
        'usuario_delegacion'        => 'USU3DEL',
        'usuario_codigo'            => 'USU3COD',
        'codigo'                    => 'AGE1COD',
        'asunto'                    => 'AGECASU',
        'ubicacion'                 => 'AGECUBI',
        'duracion'                  => 'AGENDUR',   // 0 0 min, 1 30 min, 2 1 h, 3 90 min, 4 2 h, 5 todo el día, 6 personalizada
        'aviso_numero'              => 'AGENAVI',
        'aviso_unidad'              => 'AGECAVI',
        'es_privado'                => 'AGEBPRI',
        'notas'                     => 'AGECNOT',
        'estado_delegacion'         => 'EST2DEL',
        'estado_codigo'             => 'EST2COD',
        'clasificacion_delegacion'  => 'CLA2DEL',
        'clasificacion_codigo'      => 'CLA2COD',
        'planificacion_delegacion'  => 'PLO2DEL',
        'planificacion_codigo'      => 'PLO2COD',
        // Periodicidad (solo lectura): frecuencia 0 no, 1 diaria, 2 semanal, 3 mensual, 4 anual.
        'frecuencia'                => 'AGENFRE',
        'periodicidad_opcion'       => 'AGENOPC',
        'periodicidad_repetir'      => 'AGENREP',
        'periodicidad_ordinal'      => 'AGENORD',
        'periodicidad_dias'         => 'AGENSEM',
        'periodicidad_inicio'       => 'AGEDINI',
        'periodicidad_fin'          => 'AGEDFIN',
        'periodicidad_repeticiones' => 'AGENINR',
        'trasladar_laborable'       => 'AGEBLAB',
    ];

    protected function rules(): array
    {
        return [
            'usuario_delegacion'               => 'nullable|string|max:10',
            'usuario_codigo'                   => 'nullable|string|max:15',
            'codigo'                           => 'nullable|integer|min:1',
            'asunto'                           => 'nullable|string|max:255',
            'ubicacion'                        => 'nullable|string|max:255',
            'duracion'                         => 'nullable|integer|in:0,1,2,3,4,5,6',
            'aviso_numero'                     => 'nullable|integer|min:0',
            'aviso_unidad'                     => 'nullable|string|in:M,H,D,S',
            'es_privado'                       => 'nullable|string|in:T,F',
            'notas'                            => 'nullable|string',
            'estado_delegacion'                => 'nullable|string|max:10',
            'estado_codigo'                    => 'nullable|integer|min:0',
            'clasificacion_delegacion'         => 'nullable|string|max:10',
            'clasificacion_codigo'             => 'nullable|integer|min:0',
            'inicio'                           => 'nullable|date',
            'fin'                              => 'nullable|date',
            'asistentes'                       => 'nullable|array',
            'asistentes.*.usuario_delegacion'  => 'nullable|string|max:10',
            'asistentes.*.usuario_codigo'      => 'required|string|max:15',
        ] + VeolabPeriodicity::rules('repeticion');
    }

    protected function validateRelationships(array $data): void
    {
        $db = DB::connection('dynamic');
        if (! empty($data['estado_codigo'])
            && ! $db->table('AGEEST')->where('DEL3COD', $data['estado_delegacion'] ?? '')->where('EST1COD', $data['estado_codigo'])->exists()) {
            throw new BusinessRuleException('El estado no existe');
        }
        if (! empty($data['clasificacion_codigo'])
            && ! $db->table('AGECLA')->where('DEL3COD', $data['clasificacion_delegacion'] ?? '')->where('CLA1COD', $data['clasificacion_codigo'])->exists()) {
            throw new BusinessRuleException('La clasificación no existe');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = empty($keys);
        $before = $isNew ? null : $db->table('AGEAGE')
            ->where('USU3DEL', $keys['usuario_delegacion'] ?? '')->where('USU3COD', $keys['usuario_codigo'])
            ->where('AGE1COD', $keys['codigo'])->first();
        $owner = $isNew
            ? [(string) ($data['usuario_delegacion'] ?? ''), (string) ($data['usuario_codigo'] ?? '')]
            : [(string) ($keys['usuario_delegacion'] ?? ''), (string) $keys['usuario_codigo']];

        if ($isNew) {
            if ($owner[1] === '' || ! $db->table('ACCUSU')->where('DEL3COD', $owner[0])->where('USU1COD', $owner[1])->exists()) {
                throw new BusinessRuleException('El usuario de la agenda no existe');
            }
            if (empty($data['inicio'])) {
                throw new BusinessRuleException('La fecha de inicio es obligatoria');
            }
            $data['usuario_delegacion'] = $owner[0];
            // Evento sin periodicidad, como una cita nueva de la ficha.
            $data += ['duracion' => 0, 'aviso_numero' => 0, 'es_privado' => 'F', 'frecuencia' => 0,
                'periodicidad_opcion' => 0, 'periodicidad_repetir' => 0, 'periodicidad_ordinal' => 0,
                'periodicidad_dias' => 0, 'periodicidad_repeticiones' => 0, 'trasladar_laborable' => 'F'];
        }
        if (array_key_exists('aviso_numero', $data) && (int) $data['aviso_numero'] > 0
            && ($data['aviso_unidad'] ?? $before->AGECAVI ?? '') === '') {
            throw new BusinessRuleException('Falta la unidad del aviso (M, H, D o S)');
        }

        // Fechas y periodicidad (FichaCalendario.Grabar).
        $dates = null;
        if (array_key_exists('inicio', $data) || array_key_exists('fin', $data) || array_key_exists('repeticion', $data)) {
            $current = $isNew ? null : $db->table('AGEFEC')
                ->where('USU3DEL', $owner[0])->where('USU3COD', $owner[1])->where('AGE3COD', $keys['codigo'])
                ->orderBy('FECTINI')->first();

            $repeat = ! empty($data['repeticion']) ? VeolabPeriodicity::normalize($data['repeticion']) : null;
            if ($repeat === null && ! array_key_exists('repeticion', $data) && $before && (int) $before->AGENFRE > 0) {
                $repeat = [
                    'frecuencia' => (int) $before->AGENFRE, 'opcion' => (int) $before->AGENOPC, 'repetir' => (int) $before->AGENREP,
                    'ordinal' => (int) $before->AGENORD, 'dias' => (int) $before->AGENSEM,
                    'fecha_fin' => $before->AGEDFIN, 'repeticiones' => (int) $before->AGENINR, 'trasladar' => $before->AGEBLAB === 'T',
                ];
            }

            // Inicio: el indicado; si no, el de la periodicidad o el de la primera fecha.
            $start = $data['inicio'] ?? (($repeat && $before && $before->AGEDINI) ? $before->AGEDINI : ($current->FECTINI ?? null));
            if (! isset($data['inicio']) && $repeat && $before && $before->AGEDINI && $current) {
                // AGEDINI puede guardar solo el día: la hora es la de sus fechas (como la ficha).
                $start = substr((string) $before->AGEDINI, 0, 10).' '.substr((string) $current->FECTINI, 11, 8);
            }
            if ($start === null) {
                throw new BusinessRuleException('La fecha de inicio es obligatoria');
            }
            $start = new \DateTimeImmutable($start);
            if (! empty($data['fin'])) {
                $end = new \DateTimeImmutable($data['fin']);
            } elseif ($current && $current->FECTFIN === null) {
                // Sin fin (Veolab lo permite, p. ej. eventos de planificación): sigue sin fin.
                $end = null;
            } elseif ($current) {
                // Sin fin: se conserva la duración.
                $length = (new \DateTime($current->FECTFIN))->getTimestamp() - (new \DateTime($current->FECTINI))->getTimestamp();
                $end = $start->modify(($length >= 0 ? '+' : '').$length.' seconds');
            } else {
                $end = $start;
            }
            if ($end !== null && $end < $start) {
                throw new BusinessRuleException('La fecha de fin es anterior a la de inicio');
            }
            $length = $end === null ? null : $end->getTimestamp() - $start->getTimestamp();

            if ($repeat && $repeat['frecuencia'] > 0) {
                $starts = (new VeolabPeriodicity($owner[0]))->dates($start, $repeat['frecuencia'], $repeat['opcion'],
                    $repeat['repetir'], $repeat['ordinal'], $repeat['dias'], $repeat['fecha_fin'], $repeat['repeticiones'],
                    $repeat['trasladar'], VeolabPeriodicity::horizon());
                if (! $starts) {
                    throw new BusinessRuleException('La periodicidad no genera ninguna fecha');
                }
                $data['periodicidad_inicio'] = $start->format('Y-m-d H:i:s');
            } else {
                $repeat = null;
                $starts = [$start];
                $data['periodicidad_inicio'] = null;
            }
            $data['frecuencia'] = $repeat['frecuencia'] ?? 0;
            $data['periodicidad_opcion'] = $repeat['opcion'] ?? 0;
            $data['periodicidad_repetir'] = $repeat['repetir'] ?? 0;
            $data['periodicidad_ordinal'] = $repeat['ordinal'] ?? 0;
            $data['periodicidad_dias'] = $repeat['dias'] ?? 0;
            $data['periodicidad_fin'] = $repeat['fecha_fin'] ?? null;
            $data['periodicidad_repeticiones'] = $repeat['repeticiones'] ?? 0;
            $data['trasladar_laborable'] = ($repeat['trasladar'] ?? false) ? 'T' : 'F';

            $dates = array_map(fn ($d) => [$d->format('Y-m-d H:i:s'),
                $length === null ? null : $d->modify("+{$length} seconds")->format('Y-m-d H:i:s')], $starts);
        }

        // Asistentes: usuarios existentes, sin repetir ni el propio dueño.
        $attendees = null;
        if (array_key_exists('asistentes', $data)) {
            $attendees = [];
            foreach ($data['asistentes'] ?? [] as $item) {
                $user = [(string) ($item['usuario_delegacion'] ?? ''), (string) $item['usuario_codigo']];
                if (! $db->table('ACCUSU')->where('DEL3COD', $user[0])->where('USU1COD', $user[1])->exists()) {
                    throw new BusinessRuleException("El asistente {$user[1]} no existe");
                }
                if ($user === $owner || in_array($user, $attendees, true)) {
                    throw new BusinessRuleException("El asistente {$user[1]} está repetido");
                }
                $attendees[] = $user;
            }
        }

        $warningChanged = ! $isNew && (
            (array_key_exists('aviso_numero', $data) && (int) $data['aviso_numero'] !== (int) $before->AGENAVI)
            || (array_key_exists('aviso_unidad', $data) && (string) $data['aviso_unidad'] !== (string) $before->AGECAVI));

        unset($data['inicio'], $data['fin'], $data['asistentes'], $data['codigo'], $data['repeticion']);
        $data['_fechas'] = $dates;
        $data['_asistentes'] = $attendees;
        $data['_aviso'] = $warningChanged;
        $data['_nuevo'] = $isNew;

        return $data;
    }

    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        [$del, $usu, $cod] = [(string) $keys['usuario_delegacion'], (string) $keys['usuario_codigo'], (int) $keys['codigo']];
        $isNew = $data['_nuevo'];
        $row = $this->auditRow($keys);
        $fieldAudit = ! $isNew && VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO);
        $rowAudited = ! $isNew && array_intersect_key($data, $this->mapping);
        $auditField = function (string $field) use (&$rowAudited, $fieldAudit, $row) {
            if (! $rowAudited) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'AGEAGE', $row);
                $rowAudited = true;
            }
            if ($fieldAudit) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'AGEAGE', $row, $field);
            }
        };

        if ($data['_asistentes'] !== null) {
            $db->table('AGEASI')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)->delete();
            foreach ($data['_asistentes'] as [$aDel, $aCod]) {
                $db->table('AGEASI')->insert(['USU3DEL' => $del, 'USU3COD' => $usu, 'AGE3COD' => $cod, 'USA3DEL' => $aDel, 'USA3COD' => $aCod]);
            }
            if (! $isNew) {
                $auditField('AGEASI');
            }
        }

        $event = $db->table('AGEAGE')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE1COD', $cod)->first();

        if ($data['_fechas'] !== null) {
            // Como la ficha: nueva lista de fechas y todos los avisos de nuevo.
            $db->table('AGEFEC')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)->delete();
            $db->table('ACCAVI')->where('AGE2DEL', $del)->where('AGE2USU', $usu)->where('AGE2COD', $cod)->delete();
            $last = VeolabCodes::reserve('AGEFEC', $usu, $del, count($data['_fechas']));
            foreach (array_values($data['_fechas']) as $i => [$ini, $fin]) {
                $db->table('AGEFEC')->insert(['USU3DEL' => $del, 'USU3COD' => $usu, 'AGE3COD' => $cod,
                    'FEC1COD' => $last - count($data['_fechas']) + 1 + $i, 'FECTINI' => $ini, 'FECTFIN' => $fin]);
            }
            VeolabAgenda::createWarnings($event, null);
            if (! $isNew) {
                $auditField('AGEFEC');
            }
        } elseif ($data['_asistentes'] !== null || $data['_aviso']) {
            // Asistentes o aviso cambiados: avisos de las fechas que no han empezado.
            $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
            $db->delete('DELETE ACCAVI FROM ACCAVI JOIN AGEFEC ON (ACCAVI.AGE2DEL = AGEFEC.USU3DEL AND ACCAVI.AGE2USU = AGEFEC.USU3COD '
                .'AND ACCAVI.AGE2COD = AGEFEC.AGE3COD AND ACCAVI.AGE2FEC = AGEFEC.FEC1COD) '
                .'WHERE AGEFEC.USU3DEL = ? AND AGEFEC.USU3COD = ? AND AGEFEC.AGE3COD = ? AND AGEFEC.FECTINI >= ?', [$del, $usu, $cod, $now]);
            VeolabAgenda::createWarnings($event, $now);
        }

        return $data;
    }

    /** Cada evento lleva sus fechas y asistentes. */
    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        foreach ($rows as &$row) {
            [$del, $usu, $cod] = [(string) $row['usuario_delegacion'], (string) $row['usuario_codigo'], (int) $row['codigo']];
            $row['fechas'] = $db->table('AGEFEC')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)
                ->orderBy('FECTINI')->get()
                ->map(fn ($f) => ['codigo' => (int) $f->FEC1COD, 'inicio' => $f->FECTINI, 'fin' => $f->FECTFIN])->all();
            $row['repeticion'] = VeolabPeriodicity::describe((int) $row['frecuencia'], (int) $row['periodicidad_opcion'],
                (int) $row['periodicidad_repetir'], (int) $row['periodicidad_ordinal'], (int) $row['periodicidad_dias'],
                $row['periodicidad_fin'], (int) $row['periodicidad_repeticiones'], $row['trasladar_laborable'] === 'T');
            $row['asistentes'] = $db->table('AGEASI')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)
                ->get()->map(fn ($a) => ['usuario_delegacion' => $a->USA3DEL, 'usuario_codigo' => $a->USA3COD])->all();
        }

        return $rows;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        [$del, $usu, $cod] = [(string) $keys['usuario_delegacion'], (string) $keys['usuario_codigo'], (int) $keys['codigo']];

        $db->table('AGEFEC')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)->delete();
        $db->table('AGEASI')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)->delete();
        $db->table('ACCAVI')->where('AGE2DEL', $del)->where('AGE2USU', $usu)->where('AGE2COD', $cod)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('AGE2SER', $usu)->where('AGE2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);
    }

    /** Fechas de los eventos del usuario o a los que asiste, entre dos fechas. */
    public function dates(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'usuario_codigo' => 'required|string',
            'desde'          => 'required|date',
            'hasta'          => 'required|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $user = [(string) ($request->query('usuario_delegacion') ?? ''), (string) $request->query('usuario_codigo')];
        $from = (new \DateTime($request->query('desde')))->format('Y-m-d H:i:s');
        $to = (new \DateTime($request->query('hasta')))->format('Y-m-d H:i:s');

        $rows = DB::connection('dynamic')->table('AGEFEC')
            ->join('AGEAGE', function ($join) {
                $join->on('AGEAGE.USU3DEL', '=', 'AGEFEC.USU3DEL')->on('AGEAGE.USU3COD', '=', 'AGEFEC.USU3COD')
                    ->on('AGEAGE.AGE1COD', '=', 'AGEFEC.AGE3COD');
            })
            ->whereRaw('COALESCE(AGEFEC.FECTFIN, AGEFEC.FECTINI) >= ?', [$from])->where('AGEFEC.FECTINI', '<=', $to)
            ->where(function ($q) use ($user) {
                $q->where(fn ($w) => $w->where('AGEAGE.USU3DEL', $user[0])->where('AGEAGE.USU3COD', $user[1]))
                    ->orWhereExists(function ($e) use ($user) {
                        $e->select(DB::raw(1))->from('AGEASI')
                            ->whereColumn('AGEASI.USU3DEL', 'AGEAGE.USU3DEL')->whereColumn('AGEASI.USU3COD', 'AGEAGE.USU3COD')
                            ->whereColumn('AGEASI.AGE3COD', 'AGEAGE.AGE1COD')
                            ->where('AGEASI.USA3DEL', $user[0])->where('AGEASI.USA3COD', $user[1]);
                    });
            })
            ->orderBy('AGEFEC.FECTINI')->orderBy('AGEFEC.USU3COD')->orderBy('AGEFEC.AGE3COD')
            ->get(['AGEFEC.*', 'AGEAGE.AGECASU', 'AGEAGE.AGECUBI', 'AGEAGE.AGEBPRI', 'AGEAGE.EST2DEL', 'AGEAGE.EST2COD',
                'AGEAGE.CLA2DEL', 'AGEAGE.CLA2COD', 'AGEAGE.AGENDUR'])
            ->map(fn ($r) => [
                'usuario_delegacion'       => $r->USU3DEL,
                'usuario_codigo'           => $r->USU3COD,
                'codigo'                   => (int) $r->AGE3COD,
                'fecha_codigo'             => (int) $r->FEC1COD,
                'inicio'                   => $r->FECTINI,
                'fin'                      => $r->FECTFIN,
                'asunto'                   => $r->AGECASU,
                'ubicacion'                => $r->AGECUBI,
                'duracion'                 => (int) $r->AGENDUR,
                'es_privado'               => $r->AGEBPRI,
                'estado_delegacion'        => (int) $r->EST2COD === 0 ? null : $r->EST2DEL,
                'estado_codigo'            => (int) $r->EST2COD === 0 ? null : (int) $r->EST2COD,
                'clasificacion_delegacion' => (int) $r->CLA2COD === 0 ? null : $r->CLA2DEL,
                'clasificacion_codigo'     => (int) $r->CLA2COD === 0 ? null : (int) $r->CLA2COD,
                'es_propio'                => $r->USU3DEL === $user[0] && $r->USU3COD === $user[1] ? 'T' : 'F',
            ])->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }
}
