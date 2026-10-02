<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Agenda de Veolab compartida por la agenda y las planificaciones: avisos
 * ACCAVI de los eventos y eventos de planificación (Agenda.bas,
 * AGE_CrearEventoCalendarioPlanificacion / AGE_BorrarEventoCalendarioPlanificacion).
 * Debe llamarse dentro de una transacción (reserva contadores).
 */
class VeolabAgenda
{
    /** Minutos de cada unidad de aviso. */
    public const UNITS = ['M' => 1, 'H' => 60, 'D' => 1440, 'S' => 10080];

    /** Duración de los eventos de planificación (AGENDUR 2 = 1 h, como Veolab). */
    private const PLANNING_DURATION = 2;

    /**
     * Avisos (tipo A) de las fechas del evento, desde $from si se indica, para
     * el dueño y los asistentes: uno al inicio y otro antes según
     * AGENAVI/AGECAVI. Los avisos anteriores a $from no se crean.
     */
    public static function createWarnings(object $event, ?string $from): void
    {
        $db = DB::connection('dynamic');
        [$del, $usu, $cod] = [(string) $event->USU3DEL, (string) $event->USU3COD, (int) $event->AGE1COD];

        $users = [[$del, $usu]];
        foreach ($db->table('AGEASI')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)->get() as $a) {
            $users[] = [(string) $a->USA3DEL, (string) $a->USA3COD];
        }
        $dates = $db->table('AGEFEC')->where('USU3DEL', $del)->where('USU3COD', $usu)->where('AGE3COD', $cod)
            ->whereNotNull('FECTINI')
            ->when($from !== null, fn ($q) => $q->where('FECTINI', '>=', $from))
            ->orderBy('FEC1COD')->get(['FEC1COD', 'FECTINI']);

        $before = (int) $event->AGENAVI > 0 ? (int) $event->AGENAVI * (self::UNITS[(string) $event->AGECAVI] ?? 0) : 0;
        $rows = [];
        foreach ($dates as $date) {
            $times = [(string) $date->FECTINI];
            if ($before > 0) {
                $time = (new \DateTime($date->FECTINI))->modify("-{$before} minutes")->format('Y-m-d H:i:s');
                if ($from === null || $time >= $from) {
                    $times[] = $time;
                }
            }
            foreach ($users as [$uDel, $uCod]) {
                foreach ($times as $time) {
                    $rows[] = ['AVITFEC' => $time, 'USU2DEL' => $uDel, 'USU2COD' => $uCod, 'AGE2FEC' => (int) $date->FEC1COD];
                }
            }
        }
        if (! $rows) {
            return;
        }

        $last = VeolabCodes::reserve('ACCAVI', '', $del, count($rows));
        $first = $last - count($rows) + 1;
        $insert = [];
        foreach ($rows as $i => $row) {
            $insert[] = ['DEL3COD' => $del, 'AVI1COD' => $first + $i, 'AVICTIP' => 'A',
                'AGE2DEL' => $del, 'AGE2USU' => $usu, 'AGE2COD' => $cod] + $row;
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            $db->table('ACCAVI')->insert($chunk);
        }
    }

    /**
     * Evento de agenda de una planificación con aviso (PLOBAVI = T), como
     * AGE_CrearEventoCalendarioPlanificacion: se borra el anterior y se crea
     * uno para los usuarios con acceso a planificaciones (función LAB_PLA)
     * y los analistas de sus técnicas (LABPYT), si no tienen cliente o es el
     * de la planificación. El primero (por delegación y código) es el dueño
     * y el resto asistentes. Fechas: las de la planificación desde hoy, sin
     * fecha de fin. Sin usuarios no se crea nada.
     *
     * Diferencias con Veolab: avisos también para los asistentes (como los
     * eventos de agenda) y contador de fechas por usuario (como FichaCalendario).
     */
    public static function syncPlanning(string $del, int $cod): void
    {
        $db = DB::connection('dynamic');
        self::deletePlanning($del, $cod);

        $plan = $db->table('LABPLO')
            ->leftJoin('SINCLI', function ($join) {
                $join->on('SINCLI.DEL3COD', '=', 'LABPLO.CLI2DEL')->on('SINCLI.CLI1COD', '=', 'LABPLO.CLI2COD');
            })
            ->where('LABPLO.DEL3COD', $del)->where('LABPLO.PLO1COD', $cod)
            ->first(['LABPLO.*', 'SINCLI.CLICNOM']);
        if (! $plan || $plan->PLOBAVI !== 'T') {
            return;
        }

        $users = self::planningUsers($plan);
        if (! $users) {
            return;
        }
        [$oDel, $oUsu] = array_shift($users);

        $subject = trim('Planificación '.VeolabCodes::format('LABPLO', (string) $cod, $del).' '.($plan->CLICNOM ?? ''));
        do {
            $code = VeolabCodes::next('AGEAGE', $oUsu, $oDel);
        } while ($db->table('AGEAGE')->where('USU3DEL', $oDel)->where('USU3COD', $oUsu)->where('AGE1COD', $code)->exists());

        $db->table('AGEAGE')->insert([
            'USU3DEL' => $oDel, 'USU3COD' => $oUsu, 'AGE1COD' => $code, 'AGECASU' => $subject,
            'AGENAVI' => (int) $plan->PLONAVI, 'AGECAVI' => (string) $plan->PLOCAVI, 'AGENDUR' => self::PLANNING_DURATION,
            'CLA2DEL' => '', 'CLA2COD' => -1,
            'AGENFRE' => (int) $plan->PLONFRE, 'AGENOPC' => (int) $plan->PLONOPC, 'AGENREP' => (int) $plan->PLONREP,
            'AGENORD' => (int) $plan->PLONORD, 'AGENSEM' => (int) $plan->PLONSEM,
            'AGEDINI' => $plan->PLODINI, 'AGEDFIN' => $plan->PLODFIN, 'AGENINR' => (int) $plan->PLONINR,
            'AGEBLAB' => (string) $plan->PLOBLAB, 'PLO2DEL' => $del, 'PLO2COD' => $cod,
        ]);
        VeolabAudit::record(VeolabAudit::INSERCION, 'AGEAGE', VeolabCodes::format('AGEAGE', (string) $code, $oDel, $oUsu, '', $subject));

        foreach ($users as [$aDel, $aUsu]) {
            $db->table('AGEASI')->insert(['USU3DEL' => $oDel, 'USU3COD' => $oUsu, 'AGE3COD' => $code, 'USA3DEL' => $aDel, 'USA3COD' => $aUsu]);
        }

        $today = substr((string) $db->selectOne('SELECT NOW() AS n')->n, 0, 10);
        $dates = $db->table('LABFEP')->where('PLO3DEL', $del)->where('PLO3COD', $cod)
            ->where('FEPTINI', '>=', $today)->orderBy('FEPTINI')->orderBy('FEP1COD')->get(['FEP1COD', 'FEPTINI']);
        if ($dates->isEmpty()) {
            return;
        }
        $first = VeolabCodes::reserve('AGEFEC', $oUsu, $oDel, $dates->count()) - $dates->count() + 1;
        $insert = [];
        foreach ($dates->values() as $i => $date) {
            $insert[] = ['USU3DEL' => $oDel, 'USU3COD' => $oUsu, 'AGE3COD' => $code, 'FEC1COD' => $first + $i,
                'FECTINI' => $date->FEPTINI, 'FEP2COD' => (int) $date->FEP1COD];
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            $db->table('AGEFEC')->insert($chunk);
        }

        // Avisos que no han vencido (Veolab los recrea al ampliar las periodicidades).
        $event = $db->table('AGEAGE')->where('USU3DEL', $oDel)->where('USU3COD', $oUsu)->where('AGE1COD', $code)->first();
        self::createWarnings($event, (string) $db->selectOne('SELECT NOW() AS n')->n);
    }

    /** Usuarios del evento de la planificación, sin repetir, el dueño primero. */
    private static function planningUsers(object $plan): array
    {
        $db = DB::connection('dynamic');
        $byProfile = $db->table('ACCUSU')
            ->join('ACCPYF', function ($join) {
                $join->on('ACCPYF.DEL3COD', '=', 'ACCUSU.PER2DEL')->on('ACCPYF.PER3COD', '=', 'ACCUSU.PER2COD');
            })
            ->where('ACCPYF.FUN3COD', 'LAB_PLA')
            ->orderBy('ACCUSU.DEL3COD')->orderBy('ACCUSU.USU1COD')
            ->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD', 'ACCUSU.CLI2DEL', 'ACCUSU.CLI2COD']);
        $analysts = $db->table('LABPYT')
            ->join('ACCUSU', function ($join) {
                $join->on('ACCUSU.EMP2DEL', '=', 'LABPYT.EMP2DEL')->on('ACCUSU.EMP2COD', '=', 'LABPYT.EMP2COD');
            })
            ->where('LABPYT.PLO3DEL', $plan->DEL3COD)->where('LABPYT.PLO3COD', $plan->PLO1COD)
            ->where('LABPYT.EMP2COD', '<>', 0)
            ->orderBy('ACCUSU.DEL3COD')->orderBy('ACCUSU.USU1COD')
            ->get(['ACCUSU.DEL3COD', 'ACCUSU.USU1COD', 'ACCUSU.CLI2DEL', 'ACCUSU.CLI2COD']);

        $users = [];
        foreach ($byProfile->concat($analysts) as $u) {
            $client = (string) $u->CLI2COD;
            if ($client !== '' && ((string) $u->CLI2DEL !== (string) $plan->CLI2DEL || $client !== (string) $plan->CLI2COD)) {
                continue;
            }
            $users[$u->DEL3COD."\x1B".$u->USU1COD] ??= [(string) $u->DEL3COD, (string) $u->USU1COD];
        }

        return array_values($users);
    }

    /** Borra los eventos de agenda de la planificación con sus fechas, asistentes y avisos. */
    public static function deletePlanning(string $del, int $cod): void
    {
        $db = DB::connection('dynamic');
        $agenda = 'AGEAGE.PLO2DEL = ? AND AGEAGE.PLO2COD = ?';
        $db->delete('DELETE AGEFEC FROM AGEFEC JOIN AGEAGE ON (AGEFEC.USU3DEL = AGEAGE.USU3DEL '
            ."AND AGEFEC.USU3COD = AGEAGE.USU3COD AND AGEFEC.AGE3COD = AGEAGE.AGE1COD) WHERE {$agenda}", [$del, $cod]);
        $db->delete('DELETE AGEASI FROM AGEASI JOIN AGEAGE ON (AGEASI.USU3DEL = AGEAGE.USU3DEL '
            ."AND AGEASI.USU3COD = AGEAGE.USU3COD AND AGEASI.AGE3COD = AGEAGE.AGE1COD) WHERE {$agenda}", [$del, $cod]);
        $db->delete('DELETE ACCAVI FROM ACCAVI JOIN AGEAGE ON (ACCAVI.AGE2DEL = AGEAGE.USU3DEL '
            ."AND ACCAVI.AGE2USU = AGEAGE.USU3COD AND ACCAVI.AGE2COD = AGEAGE.AGE1COD) WHERE {$agenda}", [$del, $cod]);
        $db->table('AGEAGE')->where('PLO2DEL', $del)->where('PLO2COD', $cod)->delete();
    }
}
