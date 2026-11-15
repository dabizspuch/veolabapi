<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabControlCharts;
use App\Support\VeolabDocuments;
use App\Support\VeolabLicense;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Cartas de control (LABCDC + LABCYT + LABRCD, módulo CDC) como FichaCarta y
 * Cartas de Veolab 2.4. Ver VeolabControlCharts.
 *
 *  - Alta: tipo E (exactitud) por defecto, estado N, creada ahora y
 *    CONNNUM resultados. Código del contador de LABCDC (reglas de ACCCFC).
 *  - 'tecnicas' [{tecnica_delegacion, tecnica_codigo}] sustituye las de la
 *    carta; 'resultados' [{operacion_delegacion, operacion_serie,
 *    operacion_codigo}] sustituye la lista en ese orden: las operaciones que
 *    ya estaban conservan su valor y las nuevas toman el de su primera columna
 *    de control (deben ser operaciones de control).
 *  - 'calcular_promedio' = T calcula promedio y desviación con los últimos
 *    'numero_resultados' resultados de control de sus técnicas (botón de la ficha).
 *  - Al cambiar tipo, número, promedio, desviación o resultados se recalculan
 *    las incidencias de todos los resultados y el estado pasa al de la última
 *    incidencia (salvo que la petición indique 'estado', p. ej. C corregida).
 *  - Los resultados de las operaciones de control llegan solos al grabar
 *    resultados (PUT /resultados).
 *  - Borrado: documentos a la papelera, resultados, técnicas y notificaciones.
 */
class CartaControlController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABCDC';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'CDC1COD',
    ];
    protected array $searchFields = ['CDCCOBS'];

    protected bool $generatesCode = true;

    protected array $foreignKeys = [
        'matriz' => 'int',
    ];

    protected array $mapping = [
        'delegacion'        => 'DEL3COD',
        'codigo'            => 'CDC1COD',
        'tipo'              => 'CDCCTIP',
        'estado'            => 'CDCCEST',
        'fecha_creacion'    => 'CDCDCRE',
        'fecha_cierre'      => 'CDCDCIE',
        'numero_resultados' => 'CDCNNUM',
        'promedio'          => 'CDCNPRO',
        'desviacion'        => 'CDCNDES',
        'observaciones'     => 'CDCCOBS',
        'matriz_delegacion' => 'MAT2DEL',
        'matriz_codigo'     => 'MAT2COD',
        'numero_carta'      => 'CDCNCAR',
        'nombre'            => 'CDCCNOM',
        'reglas'            => 'CDCCREG',
        'limites'           => 'CDCCLIM',
        'porcentaje_limite' => 'CDCNPLC',
        'blancos'           => 'CDCBBLA',
    ];

    /**
     * Veolab 3: varias cartas de cada tipo por parámetro. 'numero_carta' (1 =
     * principal) es la carta del parámetro a la que van las columnas de control
     * con ese número (LABCOT.COTNCDE / COTNCDP); las cartas nuevas que se abren
     * al llenarse heredan número y nombre.
     */
    protected array $since = [
        'numero_carta'      => VeolabVersion::V3,
        'nombre'            => VeolabVersion::V3,
        'reglas'            => VeolabVersion::V3,
        'limites'           => VeolabVersion::V3,
        'porcentaje_limite' => VeolabVersion::V3,
        'blancos'           => VeolabVersion::V3,
    ];

    /**
     * Veolab 3: 'reglas' V (las de Veolab) o N (Nordtest); 'limites' de las
     * cartas siguientes C (calculados), F (fijos) o P (porcentaje del valor
     * central, con 'porcentaje_limite': la desviación pasa a ser
     * promedio × porcentaje / 300). Quitar el estado E pide 'motivo', que se
     * añade a las observaciones con la fecha. 'blancos' T: carta de blancos
     * (solo de exactitud; solo cuenta el lado superior y el valor central es la
     * media de los blancos). Cada resultado admite 'lote' (del material de
     * control) y 'comentario'. Cambiar promedio o desviación de una carta con
     * resultados se anota en las observaciones con los valores anteriores.
     */

    /** Cambios que recalculan las incidencias (los que lo hacen en la ficha). */
    private const RECHECK = ['tipo', 'numero_resultados', 'promedio', 'desviacion', 'reglas', 'blancos'];

    protected function rules(): array
    {
        return [
            'delegacion'                         => 'nullable|string|max:10',
            'codigo'                             => 'nullable|integer|min:1',
            'tipo'                               => 'nullable|string|in:E,P',
            'estado'                             => 'nullable|string|in:N,A,E,C',
            'fecha_creacion'                     => 'nullable|date',
            'fecha_cierre'                       => 'nullable|date',
            'numero_resultados'                  => 'nullable|integer|min:0',
            'promedio'                           => 'nullable|numeric',
            'desviacion'                         => 'nullable|numeric',
            'observaciones'                      => 'nullable|string',
            'matriz_delegacion'                  => 'nullable|string|max:10',
            'matriz_codigo'                      => 'nullable|integer',
            'numero_carta'                       => 'nullable|integer|min:1|max:99',
            'nombre'                             => 'nullable|string|max:100',
            'reglas'                             => 'nullable|string|in:V,N',
            'limites'                            => 'nullable|string|in:C,F,P',
            'porcentaje_limite'                  => 'nullable|numeric|min:0',
            'blancos'                            => 'nullable|string|in:T,F',
            'motivo'                             => 'nullable|string|max:255',
            'calcular_promedio'                  => 'nullable|string|in:T,F',
            'tecnicas'                           => 'nullable|array',
            'tecnicas.*.tecnica_delegacion'      => 'nullable|string|max:10',
            'tecnicas.*.tecnica_codigo'          => 'required|string|max:30',
            'resultados'                         => 'nullable|array',
            'resultados.*.operacion_delegacion'  => 'nullable|string|max:10',
            'resultados.*.operacion_serie'       => 'nullable|string|max:10',
            'resultados.*.operacion_codigo'      => 'required|integer|min:1',
            'resultados.*.lote'                  => 'nullable|string|max:50',
            'resultados.*.comentario'            => 'nullable|string|max:255',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $this->assertModule();
        $isNew = empty($keys);
        $db = DB::connection('dynamic');
        $before = $isNew ? null
            : $db->table('LABCDC')->where('DEL3COD', $keys['delegacion'] ?? '')->where('CDC1COD', $keys['codigo'])->first();

        $stateGiven = isset($data['estado']);

        if ($isNew) {
            $data['tipo'] ??= VeolabControlCharts::EXACTITUD;
            $data['estado'] ??= 'N';
            $data['fecha_creacion'] ??= (string) $db->selectOne('SELECT NOW() AS n')->n;
            $data['numero_resultados'] ??= (int) $db->table('LABCON')->where('CON1COD', 1)->value('CONNNUM');
            $data['promedio'] ??= 0;
            $data['desviacion'] ??= 0;
            if (VeolabVersion::isV3()) {
                $data['numero_carta'] ??= 1;
                $data['reglas'] ??= 'V';
                $data['limites'] ??= 'C';
            }
        }

        if (VeolabVersion::isV3()) {
            // Quitar el estado de error pide el motivo, que queda en las observaciones (ISO 17025 7.7.3).
            if (! $isNew && (string) $before->CDCCEST === 'E' && isset($data['estado']) && $data['estado'] !== 'E') {
                $reason = trim((string) ($data['motivo'] ?? ''));
                if ($reason === '') {
                    throw new BusinessRuleException('Para quitar el estado de error de la carta hay que indicar el motivo');
                }
                $notes = (string) ($data['observaciones'] ?? $before->CDCCOBS ?? '');
                $data['observaciones'] = ($notes !== '' ? $notes."\r\n" : '').date('d/m/Y').' - Error > '.$data['estado'].': '.$reason;
            }
            // Límites en porcentaje: la desviación sale del valor central.
            $limits = (string) ($data['limites'] ?? $before->CDCCLIM ?? 'C');
            $percent = (float) ($data['porcentaje_limite'] ?? $before->CDCNPLC ?? 0);
            if ($limits === 'P' && $percent > 0
                && (array_key_exists('limites', $data) || array_key_exists('porcentaje_limite', $data) || array_key_exists('promedio', $data))) {
                $data['desviacion'] = VeolabControlCharts::percentDeviation((float) ($data['promedio'] ?? $before->CDCNPRO ?? 0), $percent);
            }
        }
        unset($data['motivo']);
        $number = VeolabControlCharts::chartNumber($data['numero_carta'] ?? $before->CDCNCAR ?? null);

        // Carta de blancos: solo en exactitud; F o vacío la quita.
        $blank = false;
        if (VeolabVersion::isV3()) {
            if (array_key_exists('blancos', $data)) {
                $data['blancos'] = $data['blancos'] === 'T' ? 'T' : null;
            }
            if (($data['tipo'] ?? $before->CDCCTIP ?? VeolabControlCharts::EXACTITUD) !== VeolabControlCharts::EXACTITUD) {
                if (array_key_exists('blancos', $data) || ($before->CDCBBLA ?? null) === 'T') {
                    $data['blancos'] = null;
                }
            }
            $blank = (array_key_exists('blancos', $data) ? $data['blancos'] : ($before->CDCBBLA ?? null)) === 'T';
        }

        // Técnicas: existentes y sin repetir.
        $techniques = null;
        if (array_key_exists('tecnicas', $data)) {
            $techniques = [];
            foreach ($data['tecnicas'] ?? [] as $item) {
                $tec = [(string) ($item['tecnica_delegacion'] ?? ''), (string) $item['tecnica_codigo']];
                if (! $db->table('LABTEC')->where('DEL3COD', $tec[0])->where('TEC1COD', $tec[1])->exists()) {
                    throw new BusinessRuleException("La técnica {$tec[1]} no existe");
                }
                if (in_array($tec, $techniques, true)) {
                    throw new BusinessRuleException("La técnica {$tec[1]} está repetida");
                }
                $techniques[] = $tec;
            }
        }
        $currentTechniques = $techniques
            ?? ($isNew ? [] : VeolabControlCharts::techniques((string) $keys['delegacion'], (int) $keys['codigo']));
        $type = (string) ($data['tipo'] ?? $before->CDCCTIP ?? VeolabControlCharts::EXACTITUD);

        // Resultados: operaciones de control, sin repetir; las que ya estaban conservan su valor.
        $results = null;
        if (array_key_exists('resultados', $data)) {
            $existing = [];
            $existingData = [];
            if (! $isNew) {
                foreach (VeolabControlCharts::results((string) $keys['delegacion'], (int) $keys['codigo']) as $row) {
                    $existing[$row->OPE2DEL."\x1B".$row->OPE2SER."\x1B".$row->OPE2COD] = (float) $row->RCDNVAL;
                    $existingData[$row->OPE2DEL."\x1B".$row->OPE2SER."\x1B".$row->OPE2COD] = [$row->RCDCLOT ?? null, $row->RCDCOBS ?? null];
                }
            }
            $results = [];
            foreach ($data['resultados'] ?? [] as $item) {
                $op = [(string) ($item['operacion_delegacion'] ?? ''), (string) ($item['operacion_serie'] ?? ''), (int) $item['operacion_codigo']];
                $key = implode("\x1B", $op);
                if (isset($results[$key])) {
                    throw new BusinessRuleException("La operación {$op[2]} está repetida en los resultados");
                }
                $control = $db->table('LABOPE')->where('DEL3COD', $op[0])->where('OPE1SER', $op[1])->where('OPE1COD', $op[2])->value('OPEBCON');
                if ($control === null) {
                    throw new BusinessRuleException("La operación {$op[2]} no existe");
                }
                if (! array_key_exists($key, $existing) && $control !== 'T') {
                    throw new BusinessRuleException("La operación {$op[2]} no es una operación de control");
                }
                // Lote y comentario: los indicados o, si no vienen, los que ya tenía el resultado
                $batch = array_key_exists('lote', $item) ? (trim((string) $item['lote']) ?: null) : ($existingData[$key][0] ?? null);
                $comment = array_key_exists('comentario', $item) ? (trim((string) $item['comentario']) ?: null) : ($existingData[$key][1] ?? null);
                $results[$key] = [$op, $existing[$key] ?? VeolabControlCharts::controlValue($type, $op, $currentTechniques, $number), $batch, $comment];
            }
        }

        if (($data['calcular_promedio'] ?? 'F') === 'T') {
            $count = (int) ($data['numero_resultados'] ?? $before->CDCNNUM ?? 0);
            [$data['promedio'], $data['desviacion']] = VeolabControlCharts::averageDeviation(
                $type, VeolabControlCharts::sectionType($currentTechniques), $count, $currentTechniques, $number, $blank);
        }

        // Revisión de los límites de una carta con resultados: se anota con los valores anteriores (ISO 17025 7.7).
        if (VeolabVersion::isV3() && ! $isNew
            && ((array_key_exists('promedio', $data) && (float) $data['promedio'] !== (float) $before->CDCNPRO)
                || (array_key_exists('desviacion', $data) && (float) $data['desviacion'] !== (float) $before->CDCNDES))
            && $db->table('LABRCD')->where('CDC3DEL', $keys['delegacion'])->where('CDC3COD', $keys['codigo'])->exists()) {
            $notes = (string) ($data['observaciones'] ?? $before->CDCCOBS ?? '');
            $data['observaciones'] = ($notes !== '' ? $notes."\r\n" : '').date('d/m/Y').' - Límites revisados: Promedio '
                .(float) $before->CDCNPRO.' > '.(float) ($data['promedio'] ?? $before->CDCNPRO)
                .'; Desviación '.(float) $before->CDCNDES.' > '.(float) ($data['desviacion'] ?? $before->CDCNDES);
        }

        if (! empty($data['fecha_creacion'])) {
            $data['fecha_creacion'] = (new \DateTime($data['fecha_creacion']))->format('Y-m-d H:i:s');
        }
        if (! empty($data['fecha_cierre'])) {
            $data['fecha_cierre'] = (new \DateTime($data['fecha_cierre']))->format('Y-m-d H:i:s');
        }

        $changedForRecheck = $results !== null || $techniques !== null;
        foreach (self::RECHECK as $param) {
            if (array_key_exists($param, $data) && ($isNew || (string) $data[$param] !== (string) $before->{$this->mapping[$param]})) {
                $changedForRecheck = true;
            }
        }

        $data['_tecnicas'] = $techniques;
        $data['_resultados'] = $results;
        $data['_recalcular'] = $changedForRecheck;
        $data['_estado_indicado'] = $stateGiven;
        $data['_nueva'] = $isNew;
        unset($data['calcular_promedio'], $data['tecnicas'], $data['resultados']);

        return $data;
    }

    /** Técnicas y resultados (DELETE + INSERT como la ficha) e incidencias. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];
        $row = $this->auditRow($keys);
        $audit = ! $data['_nueva'] && VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO);
        $rowAudited = ! $data['_nueva'] && array_intersect_key($data, $this->mapping);

        if ($data['_tecnicas'] !== null) {
            $db->table('LABCYT')->where('CDC3DEL', $del)->where('CDC3COD', $cod)->delete();
            foreach ($data['_tecnicas'] as [$tecDel, $tecCod]) {
                $db->table('LABCYT')->insert(['CDC3DEL' => $del, 'CDC3COD' => $cod, 'TEC3DEL' => $tecDel, 'TEC3COD' => $tecCod]);
            }
            if (! $data['_nueva'] && ! $rowAudited) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'LABCDC', $row);
                $rowAudited = true;
            }
            if ($audit) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABCDC', $row, 'LABCYT');
            }
        }

        // Veolab 3: una carta nueva cierra las abiertas de su delegación con el mismo parámetro, tipo y número.
        if ($data['_nueva'] && VeolabVersion::isV3()) {
            $chart = $db->table('LABCDC')->where('DEL3COD', $del)->where('CDC1COD', $cod)->first();
            $number = VeolabControlCharts::chartNumber($chart->CDCNCAR ?? null);
            foreach (VeolabControlCharts::techniques($del, $cod) as [$tecDel, $tecCod]) {
                $db->table('LABCDC')
                    ->where('DEL3COD', $del)->where('CDC1COD', '<>', $cod)->whereNull('CDCDCIE')
                    ->where('CDCCTIP', (string) $chart->CDCCTIP)
                    ->where(fn ($q) => $number > 1 ? $q->where('CDCNCAR', $number) : $q->whereNull('CDCNCAR')->orWhere('CDCNCAR', '<=', 1))
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('LABCYT')
                        ->whereColumn('LABCYT.CDC3DEL', 'LABCDC.DEL3COD')->whereColumn('LABCYT.CDC3COD', 'LABCDC.CDC1COD')
                        ->where('LABCYT.TEC3DEL', $tecDel)->where('LABCYT.TEC3COD', $tecCod))
                    ->update(['CDCDCIE' => $db->selectOne('SELECT NOW() AS n')->n]);
            }
        }

        if ($data['_resultados'] !== null) {
            $db->table('LABRCD')->where('CDC3DEL', $del)->where('CDC3COD', $cod)->delete();
            $position = 0;
            foreach ($data['_resultados'] as [$op, $value, $batch, $comment]) {
                $point = [
                    'CDC3DEL' => $del, 'CDC3COD' => $cod, 'RCD1COD' => ++$position, 'RCDNVAL' => $value, 'RCDCTII' => '',
                    'OPE2DEL' => $op[0], 'OPE2SER' => $op[1], 'OPE2COD' => $op[2],
                ];
                if (VeolabVersion::isV3()) {
                    $point['RCDCLOT'] = $batch;     // Lote del material de control
                    $point['RCDCOBS'] = $comment;   // Comentario del resultado
                }
                $db->table('LABRCD')->insert($point);
            }
            VeolabControlCharts::refreshChartPoints($del, $cod);   // Veolab 3: fecha y analista de cada punto
            if (! $data['_nueva'] && ! $rowAudited) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'LABCDC', $row);
            }
            if ($audit) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABCDC', $row, 'LABRCD');
            }
        }

        if ($data['_recalcular']) {
            $current = (string) $db->table('LABCDC')->where('DEL3COD', $del)->where('CDC1COD', $cod)->value('CDCCEST');
            $state = VeolabControlCharts::recheck($del, $cod, $current);
            if (! $data['_estado_indicado'] && $state !== $current) {
                $db->table('LABCDC')->where('DEL3COD', $del)->where('CDC1COD', $cod)->update(['CDCCEST' => $state]);
                if ($audit) {
                    VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABCDC', $row, 'LABCDCCDCCEST', $state, $current);
                }
            }
        }

        return $data;
    }

    /** Cada carta lleva sus técnicas y resultados (Veolab 3: con fecha del análisis y analista). */
    protected function appendRelatedData(array $rows): array
    {
        $v3 = VeolabVersion::isV3();
        foreach ($rows as &$row) {
            [$del, $cod] = [(string) $row['delegacion'], (int) $row['codigo']];
            $row['tecnicas'] = array_map(fn ($t) => ['tecnica_delegacion' => $t[0], 'tecnica_codigo' => $t[1]],
                VeolabControlCharts::techniques($del, $cod));
            $row['resultados'] = array_map(fn ($r) => [
                'posicion'             => (int) $r->RCD1COD,
                'valor'                => (float) $r->RCDNVAL,
                'incidencia'           => (string) $r->RCDCTII !== '' ? (string) $r->RCDCTII : null,
                'operacion_delegacion' => (string) $r->OPE2DEL,
                'operacion_serie'      => (string) $r->OPE2SER,
                'operacion_codigo'     => (int) $r->OPE2COD,
            ] + ($v3 ? [
                'fecha'                => $r->RCDTFEC,
                'analista_delegacion'  => (int) $r->EMP2COD === 0 ? null : (string) $r->EMP2DEL,
                'analista_codigo'      => (int) $r->EMP2COD === 0 ? null : (int) $r->EMP2COD,
                'lote'                 => $r->RCDCLOT ?? null,
                'comentario'           => $r->RCDCOBS ?? null,
            ] : []), VeolabControlCharts::results($del, $cod));
        }

        return $rows;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $this->assertModule();
    }

    /** Cartas.Borrar: documentos a la papelera, resultados, técnicas y notificaciones (y sus avisos). */
    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];

        $db->table('DOCFAT')->where('DEL3COD', $del)->where('CDC2COD', $cod)->update(VeolabDocuments::trashUpdate($del));
        $db->table('LABRCD')->where('CDC3DEL', $del)->where('CDC3COD', $cod)->delete();
        $db->table('LABCYT')->where('CDC3DEL', $del)->where('CDC3COD', $cod)->delete();
        if (VeolabVersion::isV3()) {
            // Enlaces de las incidencias de calidad (IYECCOD es de texto)
            $db->table('CALIYE')->where('IYECTAB', 'LABCDC')->where('IYECDEL', $del)->where('IYECCOD', (string) $cod)->delete();
        }
        VeolabControlCharts::deleteNotifications($del, $cod);
    }

    private function assertModule(): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'CDC')) {
            throw new BusinessRuleException('El módulo de gráficos de control no está activo');
        }
    }
}
