<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabControlCharts;
use App\Support\VeolabLicense;
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
    ];

    /** Cambios que recalculan las incidencias (los que lo hacen en la ficha). */
    private const RECHECK = ['tipo', 'numero_resultados', 'promedio', 'desviacion'];

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
            'calcular_promedio'                  => 'nullable|string|in:T,F',
            'tecnicas'                           => 'nullable|array',
            'tecnicas.*.tecnica_delegacion'      => 'nullable|string|max:10',
            'tecnicas.*.tecnica_codigo'          => 'required|string|max:30',
            'resultados'                         => 'nullable|array',
            'resultados.*.operacion_delegacion'  => 'nullable|string|max:10',
            'resultados.*.operacion_serie'       => 'nullable|string|max:10',
            'resultados.*.operacion_codigo'      => 'required|integer|min:1',
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
            if (! $isNew) {
                foreach (VeolabControlCharts::results((string) $keys['delegacion'], (int) $keys['codigo']) as $row) {
                    $existing[$row->OPE2DEL."\x1B".$row->OPE2SER."\x1B".$row->OPE2COD] = (float) $row->RCDNVAL;
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
                $results[$key] = [$op, $existing[$key] ?? VeolabControlCharts::controlValue($type, $op, $currentTechniques)];
            }
        }

        if (($data['calcular_promedio'] ?? 'F') === 'T') {
            $count = (int) ($data['numero_resultados'] ?? $before->CDCNNUM ?? 0);
            [$data['promedio'], $data['desviacion']] = VeolabControlCharts::averageDeviation(
                $type, VeolabControlCharts::sectionType($currentTechniques), $count, $currentTechniques);
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

        if ($data['_resultados'] !== null) {
            $db->table('LABRCD')->where('CDC3DEL', $del)->where('CDC3COD', $cod)->delete();
            $position = 0;
            foreach ($data['_resultados'] as [$op, $value]) {
                $db->table('LABRCD')->insert([
                    'CDC3DEL' => $del, 'CDC3COD' => $cod, 'RCD1COD' => ++$position, 'RCDNVAL' => $value, 'RCDCTII' => '',
                    'OPE2DEL' => $op[0], 'OPE2SER' => $op[1], 'OPE2COD' => $op[2],
                ]);
            }
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

    /** Cada carta lleva sus técnicas y resultados. */
    protected function appendRelatedData(array $rows): array
    {
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
            ], VeolabControlCharts::results($del, $cod));
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

        $db->table('DOCFAT')->where('DEL3COD', $del)->where('CDC2COD', $cod)->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);
        $db->table('LABRCD')->where('CDC3DEL', $del)->where('CDC3COD', $cod)->delete();
        $db->table('LABCYT')->where('CDC3DEL', $del)->where('CDC3COD', $cod)->delete();
        VeolabControlCharts::deleteNotifications($del, $cod);
    }

    private function assertModule(): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'CDC')) {
            throw new BusinessRuleException('El módulo de cartas de control no está activo');
        }
    }
}
