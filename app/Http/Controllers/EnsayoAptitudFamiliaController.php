<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabProficiencyPlan;
use App\Support\VeolabQuality;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Familias de ensayos del plan de intercomparaciones (CALFAE con sus
 * parámetros en CALFAT; módulo CAL, desde Veolab 3; ISO/IEC 17025:2017
 * 7.7.2, ENAC NT-03): ensayos con la misma técnica que se representan entre
 * sí, con la frecuencia mínima de participación, el programa y el organizador
 * previstos y la justificación. Réplica de FichaFamiliaAptitud y PlanAptitud
 * (App\Support\VeolabProficiencyPlan).
 *
 *  - Clave delegación + código (contador ACCCLT de la tabla, como
 *    DBS_Autoincremento).
 *  - Obligatorio el nombre. Frecuencia de 1 a 10 años (vacía = 1). El
 *    organizador previsto, un proveedor; al asignarlo, de alta.
 *  - 'parametros' [{parametro_delegacion, parametro_codigo}] sustituye los de
 *    la familia (la ficha los reescribe al grabar); un parámetro puede estar
 *    en varias familias.
 *  - Escribir exige el módulo CAL. Al eliminarla se borran sus parámetros;
 *    las rondas y sus resultados no se tocan.
 *  - GET /ensayos-aptitud/plan: el cuadro del plan (plan()).
 */
class EnsayoAptitudFamiliaController extends BaseController
{
    protected string $table = 'CALFAE';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'FAE1COD',
    ];

    protected bool $generatesCode = true;
    protected ?string $auditDescription = 'FAECNOM';
    protected ?string $inactiveField = 'FAEBBAJ';

    // Sin $foreignKeys: Veolab guarda NULL en las referencias vacías de esta tabla.
    protected array $mapping = [
        'delegacion'             => 'DEL3COD',
        'codigo'                 => 'FAE1COD',
        'nombre'                 => 'FAECNOM',
        'descripcion'            => 'FAECDES',   // Descripción o justificación (técnica común, programas disponibles...)
        'frecuencia'             => 'FAENFRE',   // Frecuencia mínima de participación en años (vacía = 1)
        'programa'               => 'FAECPRG',   // Programa o esquema previsto
        'organizador_delegacion' => 'PRO2DEL',   // Organizador previsto (proveedor)
        'organizador_codigo'     => 'PRO2COD',
        'baja'                   => 'FAEBBAJ',
    ];

    protected array $searchFields = ['FAECNOM', 'FAECPRG', 'FAECDES'];

    /** Textos con su longitud (TextoSQL de la ficha: recortados y vacíos como NULL). */
    private const TEXTS = ['nombre' => 100, 'descripcion' => null, 'programa' => 100];

    protected function rules(): array
    {
        return [
            'delegacion'                        => 'nullable|string|max:10',
            'codigo'                            => 'nullable|integer|min:1',
            'nombre'                            => 'nullable|string|max:100',
            'descripcion'                       => 'nullable|string',
            'frecuencia'                        => 'nullable|integer|min:1|max:'.VeolabProficiencyPlan::MAX_FREQUENCY,
            'programa'                          => 'nullable|string|max:100',
            'organizador_delegacion'            => 'nullable|string|max:10',
            'organizador_codigo'                => 'nullable|string|max:15',
            'baja'                              => 'nullable|string|in:T,F',
            'parametros'                        => 'nullable|array',
            'parametros.*.parametro_delegacion' => 'nullable|string|max:10',
            'parametros.*.parametro_codigo'     => 'required|string|max:30',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = ! $keys;
        $before = $isNew ? [] : (array) $db->table('CALFAE')->where('DEL3COD', (string) $keys['delegacion'])
            ->where('FAE1COD', (int) $keys['codigo'])->lockForUpdate()->first();

        // Textos recortados; vacíos, NULL
        foreach (self::TEXTS as $param => $max) {
            if (array_key_exists($param, $data)) {
                $text = trim((string) ($data[$param] ?? ''));
                $data[$param] = $text === '' ? null : ($max ? mb_substr($text, 0, $max) : $text);
            }
        }
        $name = array_key_exists('nombre', $data) ? $data['nombre'] : ($before['FAECNOM'] ?? null);
        if (trim((string) $name) === '') {
            throw new BusinessRuleException('Escriba el nombre de la familia');
        }

        // Frecuencia (la ficha siempre graba una, de 1 a 10) y estado
        if ($isNew || array_key_exists('frecuencia', $data)) {
            $data['frecuencia'] = (int) ($data['frecuencia'] ?? 0) >= 1 ? (int) $data['frecuencia'] : 1;
        }
        if ($isNew || array_key_exists('baja', $data)) {
            $data['baja'] = ($data['baja'] ?? 'F') === 'T' ? 'T' : 'F';
        }

        // Organizador previsto: un proveedor; si cambia, de alta (la ficha solo ofrece esos)
        if (array_key_exists('organizador_codigo', $data) || array_key_exists('organizador_delegacion', $data)) {
            $proDel = (string) (array_key_exists('organizador_delegacion', $data) ? $data['organizador_delegacion'] : ($before['PRO2DEL'] ?? ''));
            $proCod = trim((string) (array_key_exists('organizador_codigo', $data) ? $data['organizador_codigo'] : ($before['PRO2COD'] ?? '')));
            if ($proCod === '') {
                $data['organizador_delegacion'] = null;
                $data['organizador_codigo'] = null;
            } else {
                $provider = $db->table('SINPRO')->where('DEL3COD', $proDel)->where('PRO1COD', $proCod)->first(['PROBBAJ']);
                if (! $provider) {
                    throw new BusinessRuleException('El organizador previsto (proveedor) no existe');
                }
                $changed = $isNew || (string) ($before['PRO2DEL'] ?? '') !== $proDel || (string) ($before['PRO2COD'] ?? '') !== $proCod;
                if ($changed && $provider->PROBBAJ === 'T') {
                    throw new BusinessRuleException('El organizador previsto (proveedor) está de baja');
                }
                $data['organizador_delegacion'] = $proDel;
                $data['organizador_codigo'] = $proCod;
            }
        }

        // Parámetros: técnicas existentes y sin repetir
        $params = null;
        if (array_key_exists('parametros', $data)) {
            $params = [];
            foreach ($data['parametros'] ?? [] as $item) {
                $tec = [(string) ($item['parametro_delegacion'] ?? ''), trim((string) $item['parametro_codigo'])];
                if (! $db->table('LABTEC')->where('DEL3COD', $tec[0])->where('TEC1COD', $tec[1])->exists()) {
                    throw new BusinessRuleException("El parámetro {$tec[1]} no existe");
                }
                if (in_array($tec, $params, true)) {
                    throw new BusinessRuleException("El parámetro {$tec[1]} está repetido en la familia");
                }
                $params[] = $tec;
            }
        }
        unset($data['parametros']);
        $data['_parametros'] = $params;
        $data['_nueva'] = $isNew;

        return $data;
    }

    /** Parámetros: se reescriben, como FichaFamiliaAptitud.Grabar. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if (($data['_parametros'] ?? null) === null) {
            return $data;
        }
        $db = DB::connection('dynamic');
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];
        $db->table('CALFAT')->where('DEL3COD', $del)->where('FAE3COD', $cod)->delete();
        foreach ($data['_parametros'] as [$tecDel, $tecCod]) {
            $db->table('CALFAT')->insert(['DEL3COD' => $del, 'FAE3COD' => $cod, 'TEC3DEL' => $tecDel, 'TEC3COD' => $tecCod]);
        }
        if (! $data['_nueva']) {
            $row = $this->auditRow($keys);
            if (! array_intersect_key($data, array_diff_key($this->mapping, $this->keys))) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);   // solo cambian los parámetros
            }
            if (VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'CALFAT');
            }
        }

        return $data;
    }

    /** Fila auditada como la ficha y el plan: «código - nombre». */
    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        if ($description === null) {
            $description = (string) DB::connection('dynamic')->table('CALFAE')->where('DEL3COD', (string) ($keyParams['delegacion'] ?? ''))
                ->where('FAE1COD', (int) ($keyParams['codigo'] ?? 0))->value('FAECNOM');
        }

        return ($keyParams['codigo'] ?? '').' - '.trim($description);
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /** Además de los campos: ?parametro_delegacion=&parametro_codigo= (familias que tienen ese parámetro). */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $tecCod = $request->query('parametro_codigo');
        if (is_string($tecCod) && $tecCod !== '') {
            $tecDel = (string) ($request->query('parametro_delegacion') ?? '');
            $query->whereExists(fn ($s) => $s->select(DB::raw(1))->from('CALFAT')
                ->whereColumn('CALFAT.DEL3COD', 'CALFAE.DEL3COD')->whereColumn('CALFAT.FAE3COD', 'CALFAE.FAE1COD')
                ->where('CALFAT.TEC3DEL', $tecDel)->where('CALFAT.TEC3COD', $tecCod));
        }
    }

    /** Nombre del organizador y parámetros de cada familia (con su nombre, en el orden de la ficha). */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $params = $db->table('CALFAT')
            ->leftJoin('LABTEC', fn ($j) => $j->on('CALFAT.TEC3DEL', '=', 'LABTEC.DEL3COD')->on('CALFAT.TEC3COD', '=', 'LABTEC.TEC1COD'))
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('CALFAT.DEL3COD', (string) $row['delegacion'])->where('CALFAT.FAE3COD', (int) $row['codigo']));
                }
            })
            ->orderBy('LABTEC.TECCNOM')->orderBy('CALFAT.TEC3COD')
            ->get(['CALFAT.DEL3COD', 'CALFAT.FAE3COD', 'CALFAT.TEC3DEL', 'CALFAT.TEC3COD', 'LABTEC.TECCNOM'])
            ->groupBy(fn ($p) => $p->DEL3COD."\x1B".(int) $p->FAE3COD);
        $providers = $db->table('SINPRO')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    if ((string) $row['organizador_codigo'] !== '') {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['organizador_delegacion'])->where('PRO1COD', (string) $row['organizador_codigo']));
                    }
                }
                $q->orWhereRaw('1 = 0');
            })
            ->get(['DEL3COD', 'PRO1COD', 'PROCNOM'])->keyBy(fn ($p) => $p->DEL3COD."\x1B".$p->PRO1COD);

        foreach ($rows as &$row) {
            $list = $params[$row['delegacion']."\x1B".(int) $row['codigo']] ?? collect();
            $row['frecuencia_texto'] = VeolabProficiencyPlan::frequencyText(max(1, (int) $row['frecuencia']));
            $row['organizador_nombre'] = $providers[$row['organizador_delegacion']."\x1B".$row['organizador_codigo']]->PROCNOM ?? null;
            $row['parametros'] = $list->map(fn ($p) => [
                'parametro_delegacion' => (string) $p->TEC3DEL,
                'parametro_codigo'     => (string) $p->TEC3COD,
                'parametro_nombre'     => trim((string) $p->TECCNOM) === '' ? (string) $p->TEC3COD : trim((string) $p->TECCNOM),
            ])->values()->all();
            $row['numero_parametros'] = count($row['parametros']);
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Borrado (PlanAptitud.Eliminar)
    // ------------------------------------------------------------------

    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        DB::connection('dynamic')->table('CALFAT')->where('DEL3COD', (string) $keys['delegacion'])
            ->where('FAE3COD', (int) $keys['codigo'])->delete();
    }

    // ------------------------------------------------------------------
    // Plan de intercomparaciones (PlanAptitud)
    // ------------------------------------------------------------------

    /**
     * GET /ensayos-aptitud/plan?desde=&hasta=&bajas=T&delegacion=
     * El cuadro del plan (EAP_CalcularPlan) de los años de desde a hasta (por
     * defecto los cuatro anteriores y el actual; como máximo 8), los
     * parámetros acreditados sin familia (la otra pestaña), el resumen para la
     * revisión por la dirección y los resultados del año hasta (marcadores
     * resumenplan y resultadosanioplan). Solo lectura.
     */
    public function plan(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        try {
            $from = VeolabProficiencyPlan::yearParam($request->query('desde'), 'desde');
            $to = VeolabProficiencyPlan::yearParam($request->query('hasta'), 'hasta');
            $inactive = $request->query('bajas');
            $delegation = $request->query('delegacion');
            if ($delegation !== null && ! is_string($delegation)) {
                throw new BusinessRuleException('El parámetro delegacion no es válido');
            }
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => 'Parámetros de consulta no válidos', 'errors' => ['filtro' => [$e->getMessage()]]], 422);
        }

        try {
            $current = VeolabProficiencyPlan::currentYear();
            [$from, $to] = VeolabProficiencyPlan::yearRange($from, $to, $current);
            $families = VeolabProficiencyPlan::families($from, $to, is_string($inactive) && strtoupper($inactive) === 'T', $delegation, $current);
            $withoutFamily = VeolabProficiencyPlan::parametersWithoutFamily($delegation);

            return response()->json([
                'data' => [
                    'desde'           => $from,
                    'hasta'           => $to,
                    'anio_actual'     => $current,
                    'anios'           => range($from, $to),
                    'familias'        => $families,
                    'sin_familia'     => $withoutFamily,
                    'resumen'         => VeolabProficiencyPlan::summary($families, count($withoutFamily)),
                    'resultados_anio' => VeolabProficiencyPlan::yearResults($to, $delegation),
                ],
                'meta' => ['version_bd' => VeolabVersion::current()],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response('v2 plan CALFAE', $e, 'Error al calcular el plan de intercomparaciones');
        }
    }
}
