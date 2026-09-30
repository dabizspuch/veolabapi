<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabLicense;
use App\Support\VeolabResults;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Resultados de las operaciones: técnicas (LABRES) con sus celdas (LABCOR).
 *
 * GET es el listado estándar de LABRES (filtrable, p. ej. por analista o
 * fechas) y cada técnica lleva sus columnas. Las técnicas se añaden con los
 * servicios de la operación, así que no hay POST ni DELETE.
 *
 * PUT graba como FichaResultados.Grabar de Veolab, sobre una operación
 * (?operacion_delegacion=&operacion_serie=&operacion_codigo=, cuerpo con
 * "tecnicas") o sobre una técnica (además ?tecnica_delegacion=&tecnica_codigo=,
 * cuerpo con los campos de la técnica):
 *  - Valores de celdas editables por letra de columna; marcas por rangos y
 *    sustitución por límites (VeolabResults) y marcas manuales.
 *  - Analista de la técnica: el del usuario indicado si no tenía (EstablecerAnalista).
 *  - Fechas de técnica y de operación, estado y dictamen como la ficha
 *    (CONBMAI/CONBMAF/CONCFIN, ConfigurarActualizacion).
 *  - No se graba si algún informe de la operación está validado o tiene la
 *    firma total (InformesSinValidar); con la operación finalizada se borran
 *    las firmas de sus informes, que vuelven a pendientes.
 *  - Fórmulas (COTCFOM): todavía no se recalculan; la celda guarda el valor recibido.
 *  - Cartas de control (módulo CDC): los resultados de control de una
 *    operación de control se graban en Veolab.
 */
class ResultadoController extends BaseController
{
    protected string $table = 'LABRES';
    protected array $keys = [
        'operacion_delegacion' => 'OPE3DEL',
        'operacion_serie'      => 'OPE3SER',
        'operacion_codigo'     => 'OPE3COD',
        'tecnica_delegacion'   => 'TEC3DEL',
        'tecnica_codigo'       => 'TEC3COD',
    ];
    protected array $searchFields = ['RESCNOM', 'RESCNOI'];

    protected array $foreignKeys = [
        'seccion'  => 'int',
        'analista' => 'int',
        'servicio' => 'string',
    ];

    protected array $mapping = [
        'operacion_delegacion'    => 'OPE3DEL',
        'operacion_serie'         => 'OPE3SER',
        'operacion_codigo'        => 'OPE3COD',
        'tecnica_delegacion'      => 'TEC3DEL',
        'tecnica_codigo'          => 'TEC3COD',
        'nombre'                  => 'RESCNOM',
        'nombre_informes'         => 'RESCNOI',
        'es_cursiva'              => 'RESBCUR',
        'fecha_acreditacion'      => 'RESDACR',
        'parametro'               => 'RESCPAR',
        'abreviatura'             => 'RESCABR',
        'numero_cas'              => 'RESCCAS',
        'precio'                  => 'RESNPRE',
        'descuento'               => 'RESCDTO',
        'unidades'                => 'RESCUNI',
        'leyenda'                 => 'RESCLEY',
        'metodologia'             => 'RESCMET',
        'metodologia_abreviada'   => 'RESCMEA',
        'normativa'               => 'RESCNOR',
        'tiempo_prueba'           => 'RESNTIE',
        'limite_cuantificacion'   => 'RESCLIM',
        'valor_minimo_detectable' => 'RESCMIN',
        'incertidumbre'           => 'RESCINC',
        'instruccion'             => 'RESCINS',
        'es_exportable'           => 'RESBEXP',
        'orden'                   => 'RESNORD',
        'es_agrupada'             => 'RESBAGR',
        'fecha_inicio'            => 'RESTINI',
        'fecha_fin'               => 'RESTFIN',
        'observaciones'           => 'RESCOBS',
        'referencia_igeo'         => 'RESCREF',
        'seccion_delegacion'      => 'SEC2DEL',
        'seccion_codigo'          => 'SEC2COD',
        'analista_delegacion'     => 'EMP2DEL',
        'analista_codigo'         => 'EMP2COD',
        'servicio_delegacion'     => 'SER2DEL',
        'servicio_codigo'         => 'SER2COD',
    ];

    /** Campos de la técnica que se pueden grabar. */
    private const TECHNIQUE_RULES = [
        'tecnica_delegacion'  => 'nullable|string|max:10',
        'tecnica_codigo'      => 'required|string|max:30',
        'valores'             => 'nullable|array',
        'marcas'              => 'nullable|array',
        'fecha_inicio'        => 'nullable|date',
        'fecha_fin'           => 'nullable|date',
        'analista_delegacion' => 'nullable|string|max:10',
        'analista_codigo'     => 'nullable|integer|min:0',
        'observaciones'       => 'nullable|string|max:255',
    ];

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /** Cada técnica lleva sus columnas (LABCOR + definición LABCOT). */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }

        $cells = DB::connection('dynamic')->table('LABCOR')
            ->leftJoin('LABCOT', function ($join) {
                $join->on('LABCOR.TEC3DEL', '=', 'LABCOT.TEC3DEL')
                    ->on('LABCOR.TEC3COD', '=', 'LABCOT.TEC3COD')
                    ->on('LABCOR.COR1COD', '=', 'LABCOT.COT1COD');
            })
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('LABCOR.OPE3DEL', $row['operacion_delegacion'])
                        ->where('LABCOR.OPE3SER', $row['operacion_serie'])
                        ->where('LABCOR.OPE3COD', $row['operacion_codigo'])
                        ->where('LABCOR.TEC3DEL', $row['tecnica_delegacion'])
                        ->where('LABCOR.TEC3COD', $row['tecnica_codigo']));
                }
            })
            ->orderBy('LABCOR.COR1COD')
            ->get(['LABCOR.*', 'LABCOT.COTCTIP', 'LABCOT.COTCFOR', 'LABCOT.COTCSEL', 'LABCOT.COTCPRE', 'LABCOT.COTCFOM']);

        $grouped = [];
        foreach ($cells as $cell) {
            $grouped[$this->rowKey($cell->OPE3DEL, $cell->OPE3SER, $cell->OPE3COD, $cell->TEC3DEL, $cell->TEC3COD)][] = [
                'columna'               => (int) $cell->COR1COD,
                'letra'                 => VeolabResults::letter((int) $cell->COR1COD),
                'valor'                 => $cell->CORCVAL,
                'titulo'                => $cell->CORCTIT,
                'titulo2'               => $cell->CORCTI2,
                'titulo3'               => $cell->CORCTI3,
                'tipo'                  => $cell->COTCTIP,
                'formato'               => $cell->COTCFOR,
                'seleccionables'        => $cell->COTCSEL,
                'predeterminado'        => $cell->COTCPRE,
                'formula'               => $cell->COTCFOM,
                'es_activa'             => $cell->CORBACT,
                'es_editable'           => $cell->CORBEDI,
                'es_visible_informe'    => $cell->CORBINF,
                'es_visible_resultados' => $cell->CORBRES,
                'es_control_exactitud'  => $cell->CORBCON,
                'es_control_precision'  => $cell->CORBCOP,
                'marca_delegacion'      => (int) $cell->MAR2COD === 0 ? null : (string) $cell->MAR2DEL,
                'marca_codigo'          => (int) $cell->MAR2COD === 0 ? null : (int) $cell->MAR2COD,
            ];
        }

        foreach ($rows as &$row) {
            $row['columnas'] = $grouped[$this->rowKey($row['operacion_delegacion'], $row['operacion_serie'],
                $row['operacion_codigo'], $row['tecnica_delegacion'], $row['tecnica_codigo'])] ?? [];
        }

        return $rows;
    }

    private function rowKey($opDel, $opSer, $opCod, $tecDel, $tecCod): string
    {
        return implode("\x1B", [(string) $opDel, (string) $opSer, (int) $opCod, (string) $tecDel, (string) $tecCod]);
    }

    // ------------------------------------------------------------------
    // Grabación
    // ------------------------------------------------------------------

    public function update(Request $request)
    {
        foreach (['operacion_delegacion', 'operacion_serie', 'operacion_codigo'] as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }
        $operation = [(string) ($request->query('operacion_delegacion') ?? ''),
            (string) ($request->query('operacion_serie') ?? ''), (int) $request->query('operacion_codigo')];

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        if ($request->has('tecnica_codigo')) {
            // Una técnica: el cuerpo son sus campos (y el usuario).
            $technique = array_diff_key($body, array_flip(['usuario_delegacion', 'usuario_codigo', 'tecnicas']));
            $technique['tecnica_delegacion'] = (string) ($request->query('tecnica_delegacion') ?? '');
            $technique['tecnica_codigo'] = (string) $request->query('tecnica_codigo');
            $body = ['tecnicas' => [$technique]]
                + array_intersect_key($body, array_flip(['usuario_delegacion', 'usuario_codigo']));
        }

        $db = DB::connection('dynamic');
        try {
            $rules = [
                'tecnicas'            => 'nullable|array',
                'tecnicas.*'          => 'array',
                'fecha_inicio'        => 'nullable|date',
                'fecha_fin'           => 'nullable|date',
                'dictamen_delegacion' => 'nullable|string|max:10',
                'dictamen_codigo'     => 'nullable|integer|min:0',
                'usuario_delegacion'  => 'nullable|string|max:10',
                'usuario_codigo'      => 'nullable|string|max:15',
            ];
            foreach (self::TECHNIQUE_RULES as $field => $rule) {
                $rules["tecnicas.*.{$field}"] = $rule;
            }
            $validator = Validator::make($body, $rules);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $db->beginTransaction();

            $current = $db->table('LABOPE')
                ->where('DEL3COD', $operation[0])->where('OPE1SER', $operation[1])->where('OPE1COD', $operation[2])
                ->lockForUpdate()->first();
            if (! $current) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }

            $this->assertReportsNotValidated($operation);
            $data = $this->save($operation, $current, $body);

            $db->commit();

            return response()->json(['message' => 'Resultados actualizados correctamente', 'data' => $data]);
        } catch (ValidationException $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            Log::error('v2 update LABRES: '.$e->getMessage());

            return response()->json(['message' => 'Error al grabar los resultados'], 500);
        }
    }

    /** Aplica los cambios de la petición y los graba. Devuelve el estado de la operación y los avisos. */
    private function save(array $operation, object $current, array $body): array
    {
        [$del, $ser, $cod] = $operation;
        $db = DB::connection('dynamic');
        $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
        $config = $db->table('LABCON')->where('CON1COD', 1)->first(['CONBMAI', 'CONBMAF', 'CONCFIN', 'CONBDSL']);

        $techniques = VeolabResults::techniques($del, $ser, $cod);
        $cells = VeolabResults::cells($del, $ser, $cod);
        $marks = VeolabResults::marks();

        $resChanges = [];       // técnica => [columna LABRES => valor]
        $valueRows = [];        // técnicas con valores modificados
        $analystRows = [];      // técnicas a las que asignar el analista del usuario
        $explicitDates = [];    // técnica => [RESTINI|RESTFIN => true] indicadas en la petición
        $marksChanged = false;

        foreach ($body['tecnicas'] ?? [] as $input) {
            $tec = VeolabResults::key($input['tecnica_delegacion'] ?? '', (string) $input['tecnica_codigo']);
            $label = trim(($input['tecnica_delegacion'] ?? '').' '.$input['tecnica_codigo']);
            if (! isset($techniques[$tec])) {
                throw new BusinessRuleException("La técnica {$label} no está en la operación");
            }
            if (isset($resChanges[$tec])) {
                throw new BusinessRuleException("La técnica {$label} está repetida");
            }
            $resChanges[$tec] = [];

            foreach ($input['valores'] ?? [] as $letter => $value) {
                $cell = &$this->cell($cells, $tec, (string) $letter, $label, true);
                $value = $this->cellValue($value, $cell, $label);
                if ($value === $cell['value']) {
                    unset($cell);
                    continue;
                }

                $cell['value'] = $value;
                $cell['changed'] = true;
                if ($value !== '' && ($cell['type'] !== 'C' || $value === VeolabResults::yesNo()[0])) {
                    $analystRows[$tec] = true;
                }
                // Con CONBDSL no se sustituye por el límite una celda con fórmula modificada a mano.
                $keepLimit = ($config->CONBDSL ?? '') === 'T' && $cell['formula'] !== '';
                if (VeolabResults::applyRangeMark($cell, $marks, $del, $keepLimit)) {
                    $marksChanged = true;
                }
                $valueRows[$tec] = true;
                unset($cell);
            }

            foreach ($input['marcas'] ?? [] as $letter => $mark) {
                $cell = &$this->cell($cells, $tec, (string) $letter, $label, false);
                if ($mark === null) {
                    VeolabResults::applyMark($cell, '', 0, $marks);
                } else {
                    $markDel = (string) (is_array($mark) ? ($mark['delegacion'] ?? '') : '');
                    $markCod = is_array($mark) ? filter_var($mark['codigo'] ?? null, FILTER_VALIDATE_INT) : false;
                    if ($markCod === false || $markCod === 0 || ! isset($marks[VeolabResults::key($markDel, $markCod)])) {
                        throw new BusinessRuleException("La marca de la columna {$letter} de la técnica {$label} no existe");
                    }
                    VeolabResults::applyMark($cell, $markDel, $markCod, $marks);
                }
                $cell['changed'] = true;
                $marksChanged = true;
                $analystRows[$tec] = true;
                unset($cell);
            }

            if (array_key_exists('observaciones', $input)) {
                $resChanges[$tec]['RESCOBS'] = (string) ($input['observaciones'] ?? '');
            }
            if (array_key_exists('analista_codigo', $input) || array_key_exists('analista_delegacion', $input)) {
                $empCod = (int) ($input['analista_codigo'] ?? 0);
                $empDel = $empCod === 0 ? '' : (string) ($input['analista_delegacion'] ?? '');
                if ($empCod !== 0 && ! $db->table('GRHEMP')->where('DEL3COD', $empDel)->where('EMP1COD', $empCod)->exists()) {
                    throw new BusinessRuleException("El analista de la técnica {$label} no existe");
                }
                $resChanges[$tec]['EMP2DEL'] = $empDel;
                $resChanges[$tec]['EMP2COD'] = $empCod;
            }
            foreach (['fecha_inicio' => 'RESTINI', 'fecha_fin' => 'RESTFIN'] as $param => $column) {
                if (array_key_exists($param, $input)) {
                    $resChanges[$tec][$column] = $this->date($input[$param]);
                    $explicitDates[$tec][$column] = true;
                }
            }
        }

        $this->assertNoControlResults($current, $cells);
        $this->assignAnalyst($body, $analystRows, $techniques, $resChanges);

        // Fechas, estado y dictamen de la operación (en memoria hasta grabar).
        $op = [
            'start'   => $current->OPEDINI,
            'end'     => $current->OPEDFIN,
            'state'   => (int) $current->OPENEST,
            'verdict' => (int) $current->DIC2COD === 0 ? null : [(string) $current->DIC2DEL, (int) $current->DIC2COD],
        ];
        $verdict = fn () => VeolabResults::verdict($cells);

        if ($marksChanged) {
            VeolabResults::marksChanged($op, $verdict);
        }
        if ($valueRows) {
            $this->markDates($op, $cells, $valueRows, $techniques, $resChanges, $explicitDates, $config, $now, $verdict);
        }

        // Fecha de inicio de técnica indicada: la de la operación es la menor de las técnicas.
        if (array_filter($explicitDates, fn ($dates) => isset($dates['RESTINI']))) {
            $starts = [];
            foreach ($techniques as $tec => $technique) {
                $start = array_key_exists('RESTINI', $resChanges[$tec] ?? []) ? $resChanges[$tec]['RESTINI'] : $technique->RESTINI;
                if ($start !== null) {
                    $starts[] = (string) $start;
                }
            }
            VeolabResults::setStart($op, $starts ? min($starts) : null);
        }

        if (array_key_exists('fecha_inicio', $body)) {
            VeolabResults::setStart($op, $this->date($body['fecha_inicio']));
        }
        if (array_key_exists('fecha_fin', $body)) {
            VeolabResults::setEnd($op, $this->date($body['fecha_fin']), $verdict);
        }
        if (array_key_exists('dictamen_codigo', $body)) {
            VeolabResults::setVerdict($op, $this->verdictFromBody($body), $now);
        }

        // Grabación y auditoría.
        $opRow = VeolabCodes::format('LABOPE', (string) $cod, $del, $ser);
        $changed = $this->saveOperation($operation, $current, $op, $opRow, $now);
        $changed = $this->saveTechniques($operation, $techniques, $resChanges, $opRow) || $changed;
        $changed = $this->saveCells($operation, $cells, $opRow) || $changed;

        if ($changed && $op['end'] !== null) {
            $this->resetReportSignatures($operation);
        }

        $warnings = [];
        foreach ($cells as $tec => $columns) {
            foreach ($columns as $cell) {
                if ($cell['changed'] && $cell['warning'] !== '') {
                    [$tecDel, $tecCod] = explode("\x1B", $tec, 2);
                    $warnings[] = ['tecnica_delegacion' => $tecDel, 'tecnica_codigo' => $tecCod,
                        'columna' => $cell['letter'], 'aviso' => $cell['warning']];
                }
            }
        }

        return [
            'estado'              => $op['state'],
            'fecha_inicio'        => $op['start'],
            'fecha_fin'           => $op['end'],
            'dictamen_delegacion' => $op['verdict'][0] ?? null,
            'dictamen_codigo'     => $op['verdict'][1] ?? null,
            'avisos'              => $warnings,
        ];
    }

    /** Celda de la técnica por letra de columna (editable para cambiar el valor). */
    private function &cell(array &$cells, string $tec, string $letter, string $label, bool $mustBeEditable): array
    {
        if (! preg_match('/^[A-Z]{1,3}$/', $letter)) {
            throw new BusinessRuleException("La columna '{$letter}' no es válida (se indica con su letra: A, B...)");
        }
        $column = VeolabResults::column($letter);
        if (! isset($cells[$tec][$column])) {
            throw new BusinessRuleException("La técnica {$label} no tiene la columna {$letter}");
        }
        if ($mustBeEditable && ! $cells[$tec][$column]['editable']) {
            throw new BusinessRuleException("La columna {$letter} de la técnica {$label} no es editable");
        }

        return $cells[$tec][$column];
    }

    /**
     * Valor de celda como lo guarda Veolab: texto tal cual llega; en las
     * columnas numéricas con el separador decimal del laboratorio (coma o
     * punto, según la configuración regional de sus equipos); en las casillas
     * Sí/No (también T/F o booleano).
     */
    private function cellValue($value, array $cell, string $label): string
    {
        $where = "la columna {$cell['letter']} de la técnica {$label}";
        [$yes, $no] = VeolabResults::yesNo();

        if ($cell['type'] === 'C') {
            if ($value === true || $value === 'T' || $value === $yes) {
                return $yes;
            }
            if ($value === false || $value === null || $value === 'F' || $value === $no || $value === '') {
                return $no;
            }
            throw new BusinessRuleException("El valor de {$where} debe ser T o F");
        }

        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            // El separador decimal depende de la configuración regional de cada
            // equipo: la API no puede elegirlo, así que el número llega como texto.
            throw new BusinessRuleException("El valor de {$where} debe enviarse como texto, con el separador decimal del laboratorio");
        }
        if (! is_string($value)) {
            throw new BusinessRuleException("El valor de {$where} no es válido");
        }
        $value = mb_substr($value, 0, 255);
        if (trim($value) === '') {
            return $value;
        }

        switch ($cell['type']) {
            case 'N':
                if (! preg_match('/^\s*[+-]?\d+([.,]\d+)?\s*$/', $value)) {
                    throw new BusinessRuleException("El valor de {$where} debe ser numérico");
                }
                break;
            case 'F':
                if (! preg_match('#^\s*(\d{1,2})/(\d{1,2})/(\d{2,4})(\s+\d{1,2}:\d{2}(:\d{2})?)?\s*$#', $value, $m)
                    || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    throw new BusinessRuleException("El valor de {$where} debe ser una fecha dd/mm/aaaa");
                }
                break;
            case 'H':
                if (! preg_match('/^\s*([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?\s*$/', $value)) {
                    throw new BusinessRuleException("El valor de {$where} debe ser una hora hh:mm");
                }
                break;
        }

        return $value;
    }

    private function date($value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** Dictamen indicado en la petición (null = sin dictamen). */
    private function verdictFromBody(array $body): ?array
    {
        $cod = (int) ($body['dictamen_codigo'] ?? 0);
        if ($cod === 0) {
            return null;
        }
        $del = (string) ($body['dictamen_delegacion'] ?? '');
        $exists = DB::connection('dynamic')->table('LABDIC')
            ->where('DEL3COD', $del)->where('DIC1COD', $cod)
            ->where(fn ($q) => $q->whereNull('DICBBAJ')->orWhere('DICBBAJ', '<>', 'T'))
            ->exists();
        if (! $exists) {
            throw new BusinessRuleException('El dictamen no existe o está de baja');
        }

        return [$del, $cod];
    }

    /**
     * MarcarFechas con CONBMAI/CONBMAF: la operación se inicia al tener algún
     * valor y se finaliza con todas las celdas editables cubiertas (CONCFIN
     * 'P': solo la primera columna); cada técnica modificada, igual con sus
     * celdas. Las casillas no cuentan (siempre tienen valor).
     */
    private function markDates(array &$op, array $cells, array $valueRows, array $techniques, array &$resChanges,
        array $explicitDates, ?object $config, string $now, callable $verdict): void
    {
        $markStart = ($config->CONBMAI ?? '') === 'T';
        $markEnd = ($config->CONBMAF ?? '') === 'T';
        if (! $markStart && ! $markEnd) {
            return;
        }

        $firstOnly = ($config->CONCFIN ?? '') === 'P';
        $empty = true;
        $full = true;
        $rowEmpty = [];
        $rowFull = [];
        foreach ($cells as $tec => $columns) {
            $rowEmpty[$tec] = true;
            $rowFull[$tec] = true;
            foreach ($columns as $cell) {
                if (! $cell['editable'] || $cell['type'] === 'C') {
                    continue;
                }
                $filled = $cell['value'] !== '';
                $rowEmpty[$tec] = $rowEmpty[$tec] && ! $filled;
                $rowFull[$tec] = $rowFull[$tec] && $filled;
                if (! $firstOnly || $cell['column'] === 1) {
                    $empty = $empty && ! $filled;
                    $full = $full && $filled;
                }
            }
        }

        $techniqueDate = function (string $tec, string $column) use ($techniques, &$resChanges) {
            return array_key_exists($column, $resChanges[$tec] ?? []) ? $resChanges[$tec][$column] : $techniques[$tec]->$column;
        };

        if ($markStart) {
            if ($empty && $op['start'] !== null) {
                VeolabResults::setStart($op, null);
            }
            if (! $empty && $op['start'] === null) {
                VeolabResults::setStart($op, $now);
            }
            foreach (array_keys($valueRows) as $tec) {
                if (isset($explicitDates[$tec]['RESTINI'])) {
                    continue;
                }
                $start = $techniqueDate($tec, 'RESTINI');
                if ($rowEmpty[$tec] && $start !== null) {
                    $resChanges[$tec]['RESTINI'] = null;
                }
                if (! $rowEmpty[$tec] && $start === null) {
                    $resChanges[$tec]['RESTINI'] = $now;
                }
            }
        }

        if ($markEnd) {
            if ($full && $op['end'] === null) {
                VeolabResults::setEnd($op, $now, $verdict);
            }
            if (! $full && $op['end'] !== null) {
                VeolabResults::setEnd($op, null, $verdict);
            }
            foreach (array_keys($valueRows) as $tec) {
                if (isset($explicitDates[$tec]['RESTFIN'])) {
                    continue;
                }
                $end = $techniqueDate($tec, 'RESTFIN');
                if ($rowFull[$tec] && $end === null) {
                    $resChanges[$tec]['RESTFIN'] = $now;
                }
                if (! $rowFull[$tec] && $end !== null) {
                    $resChanges[$tec]['RESTFIN'] = null;
                }
            }
        }
    }

    /**
     * EstablecerAnalista: las técnicas con resultados sin analista toman el
     * empleado del usuario de Veolab indicado (ACCUSU.EMP2*), si lo tiene.
     */
    private function assignAnalyst(array $body, array $analystRows, array $techniques, array &$resChanges): void
    {
        $user = (string) ($body['usuario_codigo'] ?? '');
        if (! $analystRows || $user === '') {
            return;
        }

        $employee = DB::connection('dynamic')->table('ACCUSU')
            ->where('DEL3COD', (string) ($body['usuario_delegacion'] ?? ''))->where('USU1COD', $user)
            ->first(['EMP2DEL', 'EMP2COD']);
        if (! $employee) {
            throw new BusinessRuleException('El usuario no existe');
        }
        if ((int) $employee->EMP2COD <= 0) {
            return;
        }

        foreach (array_keys($analystRows) as $tec) {
            if (! array_key_exists('EMP2COD', $resChanges[$tec] ?? []) && (int) $techniques[$tec]->EMP2COD === 0) {
                $resChanges[$tec]['EMP2DEL'] = (string) $employee->EMP2DEL;
                $resChanges[$tec]['EMP2COD'] = (int) $employee->EMP2COD;
            }
        }
    }

    /**
     * Cartas de control (AcumulaGrabarControl): con el módulo CDC, los
     * resultados de columnas de control de una operación de control
     * alimentan las cartas; eso todavía se hace en Veolab.
     */
    private function assertNoControlResults(object $current, array $cells): void
    {
        if ($current->OPEBCON !== 'T') {
            return;
        }
        foreach ($cells as $columns) {
            foreach ($columns as $cell) {
                if ($cell['changed'] && $cell['control'] && $cell['value'] !== '' && $cell['value'] !== 'N/A') {
                    $db = DB::connection('dynamic');
                    if (VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'CDC')) {
                        throw new BusinessRuleException('Los resultados de control de una operación de control (cartas de control) se graban desde Veolab');
                    }

                    return;
                }
            }
        }
    }

    /**
     * InformesSinValidar (acceso a todas las operaciones): no se graba si algún
     * informe de la operación (no histórico) está validado o, estando
     * pendiente, tiene la firma total aplicada.
     */
    private function assertReportsNotValidated(array $operation): void
    {
        $db = DB::connection('dynamic');
        $reports = $this->reports($operation);

        foreach ($reports as $report) {
            if ($report->INFCVAL === 'V') {
                throw new BusinessRuleException('La operación está en un informe validado y sus resultados no pueden modificarse');
            }
            if ($report->INFCVAL !== 'R') {
                $signed = $db->table('LABFIR')
                    ->where('INF3DEL', $report->DEL3COD)->where('INF3SER', $report->INF1SER)->where('INF3COD', $report->INF1COD)
                    ->whereNotNull('FIRDFEC')->where('FIRBVAL', 'T')
                    ->where('DEP3DEL', '')->where('DEP3COD', 0)
                    ->exists();
                if ($signed) {
                    throw new BusinessRuleException('La operación está en un informe firmado y sus resultados no pueden modificarse');
                }
            }
        }
    }

    /** Informes (no históricos) de la operación. */
    private function reports(array $operation)
    {
        return DB::connection('dynamic')->table('LABIYO')
            ->join('LABINF', function ($join) {
                $join->on('LABIYO.INF3DEL', '=', 'LABINF.DEL3COD')
                    ->on('LABIYO.INF3SER', '=', 'LABINF.INF1SER')
                    ->on('LABIYO.INF3COD', '=', 'LABINF.INF1COD');
            })
            ->where('LABIYO.OPE3DEL', $operation[0])->where('LABIYO.OPE3SER', $operation[1])->where('LABIYO.OPE3COD', $operation[2])
            ->where(fn ($q) => $q->whereNull('LABIYO.IYOBHIS')->orWhere('LABIYO.IYOBHIS', '<>', 'T'))
            ->distinct()
            ->get(['LABINF.DEL3COD', 'LABINF.INF1SER', 'LABINF.INF1COD', 'LABINF.INFCVAL']);
    }

    /**
     * Grabar con la operación finalizada: se borran las firmas de sus informes
     * (p. ej. tras un rechazo) y estos vuelven a pendientes.
     */
    private function resetReportSignatures(array $operation): void
    {
        $db = DB::connection('dynamic');
        foreach ($this->reports($operation) as $report) {
            $row = VeolabCodes::format('LABINF', (string) $report->INF1COD, $report->DEL3COD, $report->INF1SER);

            $db->table('LABFIR')
                ->where('INF3DEL', $report->DEL3COD)->where('INF3SER', $report->INF1SER)->where('INF3COD', $report->INF1COD)
                ->delete();
            VeolabAudit::record(VeolabAudit::BORRADO, 'LABFIR', $row);

            $db->table('LABINF')
                ->where('DEL3COD', $report->DEL3COD)->where('INF1SER', $report->INF1SER)->where('INF1COD', $report->INF1COD)
                ->update(['INFCVAL' => 'P', 'INFDVAL' => null]);
            VeolabAudit::record(VeolabAudit::MODIFICACION, 'LABINF', $row, 'LABINFINFCVAL', 'P');
        }
    }

    /**
     * Fechas, dictamen y estado de la operación. Si el estado avanza, las
     * fechas vacías de recepción y preparación toman la fecha de hoy
     * (LAB_ActualizarFechasEstados; Veolab lo llama con estado 0 y no las rellena).
     */
    private function saveOperation(array $operation, object $current, array $op, string $opRow, string $now): bool
    {
        $db = DB::connection('dynamic');
        $update = [];
        $audit = [];

        foreach (['OPEDINI' => 'start', 'OPEDFIN' => 'end'] as $column => $key) {
            if ((string) $op[$key] !== (string) $current->$column) {
                $update[$column] = $op[$key];
                $audit[] = ['LABOPE'.$column, VeolabAudit::value($op[$key]), VeolabAudit::value($current->$column)];
            }
        }

        $verdict = $op['verdict'] ?? ['', 0];
        if ($verdict[0] !== (string) $current->DIC2DEL || $verdict[1] !== (int) $current->DIC2COD) {
            $update['DIC2DEL'] = $verdict[0];
            $update['DIC2COD'] = $verdict[1];
            $audit[] = ['LABOPEDICCDES', $this->verdictName($verdict), $this->verdictName([(string) $current->DIC2DEL, (int) $current->DIC2COD])];
        }

        if ($op['state'] !== (int) $current->OPENEST) {
            $update['OPENEST'] = $op['state'];
            $audit[] = ['LABOPEOPENEST', (string) $op['state'], (string) $current->OPENEST];
            if ($op['state'] > (int) $current->OPENEST) {
                foreach ([1 => 'OPETREP', 2 => 'OPEDPRE'] as $state => $column) {
                    if ($op['state'] >= $state && $current->$column === null) {
                        $update[$column] = substr($now, 0, 10);
                    }
                }
            }
        }

        // Veolab deja rastro de fila de cada operación grabada.
        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'LABOPE', $opRow);
        if (! $update) {
            return false;
        }

        $db->table('LABOPE')
            ->where('DEL3COD', $operation[0])->where('OPE1SER', $operation[1])->where('OPE1COD', $operation[2])
            ->update($update);
        foreach ($audit as [$field, $new, $old]) {
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABOPE', $opRow, $field, $new, $old);
        }

        return true;
    }

    private function verdictName(array $verdict): string
    {
        if ($verdict[1] === 0) {
            return '';
        }

        return (string) DB::connection('dynamic')->table('LABDIC')
            ->where('DEL3COD', $verdict[0])->where('DIC1COD', $verdict[1])->value('DICCDES');
    }

    /** Fechas, analista y observaciones de las técnicas (auditoría con el valor nuevo). */
    private function saveTechniques(array $operation, array $techniques, array $resChanges, string $opRow): bool
    {
        $db = DB::connection('dynamic');
        $changed = false;

        foreach ($resChanges as $tec => $changes) {
            $technique = $techniques[$tec];
            $changes = array_filter($changes, fn ($value, $column) => (string) $value !== (string) $technique->$column,
                ARRAY_FILTER_USE_BOTH);
            if (! $changes) {
                continue;
            }

            $db->table('LABRES')
                ->where('OPE3DEL', $operation[0])->where('OPE3SER', $operation[1])->where('OPE3COD', $operation[2])
                ->where('TEC3DEL', $technique->TEC3DEL)->where('TEC3COD', $technique->TEC3COD)
                ->update($changes);
            $changed = true;

            $row = $opRow.' '.VeolabCodes::format('LABTEC', (string) $technique->TEC3COD, (string) $technique->TEC3DEL);
            foreach (['RESTINI', 'RESTFIN', 'RESCOBS'] as $column) {
                if (array_key_exists($column, $changes)) {
                    VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABRES', $row, 'LABRES'.$column,
                        VeolabAudit::value($changes[$column]));
                }
            }
            if (array_key_exists('EMP2COD', $changes)) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABRES', $row, 'LABRESEMP2COD',
                    $changes['EMP2DEL'].' '.$changes['EMP2COD']);
            }
        }

        return $changed;
    }

    /** Valor y marca de las celdas modificadas (auditoría con el título de la columna). */
    private function saveCells(array $operation, array $cells, string $opRow): bool
    {
        $db = DB::connection('dynamic');
        $changed = false;

        foreach ($cells as $tec => $columns) {
            [$tecDel, $tecCod] = explode("\x1B", $tec, 2);
            $row = $opRow.' '.VeolabCodes::format('LABTEC', $tecCod, $tecDel);

            foreach ($columns as $cell) {
                if (! $cell['changed'] || ($cell['value'] === $cell['original'] && $cell['mark'] === $cell['markBefore'])) {
                    continue;
                }

                $db->table('LABCOR')
                    ->where('OPE3DEL', $operation[0])->where('OPE3SER', $operation[1])->where('OPE3COD', $operation[2])
                    ->where('TEC3DEL', $tecDel)->where('TEC3COD', $tecCod)->where('COR1COD', $cell['column'])
                    ->update(['CORCVAL' => mb_substr($cell['value'], 0, 255), 'MAR2DEL' => $cell['mark'][0], 'MAR2COD' => $cell['mark'][1]]);
                $changed = true;

                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABRES', $row.' '.$cell['column'],
                    trim($cell['title']) !== '' ? $cell['title'] : 'LABCORCORCVAL', $cell['value'], $cell['original']);
            }
        }

        return $changed;
    }
}
