<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use Illuminate\Support\Facades\DB;

/**
 * Órdenes de trabajo (LABORD) con sus operaciones (LABOYO) y su personal
 * (LABORE). Réplica de FichaOrden/Ordenes de Veolab:
 *
 *  - Una orden lleva al menos una operación. Una operación puede estar en
 *    varias órdenes: LABOYO.OYONPOS es su índice entre ellas (1 la primera).
 *  - Al vincular operaciones, las que están registradas o recibidas pasan a
 *    preparadas (estado 2, con la fecha de preparación de hoy).
 *  - Personal: si no se indica, los analistas (LABOYE) de las operaciones
 *    que se añaden, como hace la ficha al seleccionarlas.
 *  - Con LABCON.CONBBTD no se graba si alguna técnica de las operaciones
 *    está bloqueada (operación interna abierta con dictamen no satisfactorio).
 *  - Borrado (Ordenes.Borrar): renumera las operaciones, borra operaciones y
 *    personal de la orden y envía los documentos a la papelera.
 */
class OrdenController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABORD';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'ORD1SER',
        'codigo'     => 'ORD1COD',
    ];
    protected array $searchFields = ['ORDCOBS'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    protected array $foreignKeys = [
        'departamento' => 'int',
        'tecnica'      => 'string',
    ];

    protected array $mapping = [
        'delegacion'              => 'DEL3COD',
        'serie'                   => 'ORD1SER',
        'codigo'                  => 'ORD1COD',
        'observaciones'           => 'ORDCOBS',
        'fecha_creacion'          => 'ORDDCRE',
        'fecha_impresion'         => 'ORDDIMP',
        'departamento_delegacion' => 'DEP2DEL',
        'departamento_codigo'     => 'DEP2COD',
        'tecnica_delegacion'      => 'TEC2DEL',
        'tecnica_codigo'          => 'TEC2COD',
    ];

    /**
     * 'operaciones': [{delegacion, serie, codigo}] (obligatoria al crear; en
     * PUT sustituye la lista). 'personal': [{delegacion, codigo}] (empleados).
     */
    protected function rules(): array
    {
        return [
            'delegacion'               => 'nullable|string|max:10',
            'serie'                    => 'nullable|string|max:10',
            'codigo'                   => 'nullable|integer|min:1',
            'observaciones'            => 'nullable|string|max:255',
            'fecha_creacion'           => 'nullable|date',
            'fecha_impresion'          => 'nullable|date',
            'departamento_delegacion'  => 'nullable|string|max:10',
            'departamento_codigo'      => 'nullable|integer',
            'tecnica_delegacion'       => 'nullable|string|max:10',
            'tecnica_codigo'           => 'nullable|string|max:30',
            'operaciones'              => 'sometimes|array|min:1',
            'operaciones.*.delegacion' => 'nullable|string|max:10',
            'operaciones.*.serie'      => 'nullable|string|max:10',
            'operaciones.*.codigo'     => 'required|integer|min:1',
            'personal'                 => 'sometimes|array',
            'personal.*.delegacion'    => 'nullable|string|max:10',
            'personal.*.codigo'        => 'required|integer|min:1',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);

        foreach ($data['operaciones'] ?? [] as $operation) {
            [$del, $ser, $cod] = self::operationKey($operation);
            $this->mustExist('LABOPE', ['DEL3COD' => $del, 'OPE1SER' => $ser, 'OPE1COD' => $cod],
                "La operación {$cod} no existe");
        }

        foreach ($data['personal'] ?? [] as $employee) {
            $this->mustExist('GRHEMP', [
                'DEL3COD' => (string) ($employee['delegacion'] ?? ''),
                'EMP1COD' => (int) $employee['codigo'],
            ], "El empleado {$employee['codigo']} no existe");
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isNew = empty($keys);

        if ($isNew) {
            if (empty($data['operaciones'])) {
                throw new BusinessRuleException('Es obligatorio vincular al menos una operación');
            }
            // Fecha de creación de una orden nueva: ahora.
            if (! array_key_exists('fecha_creacion', $data)) {
                $data['fecha_creacion'] = (string) DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
            }
        }

        foreach (['fecha_creacion', 'fecha_impresion'] as $param) {
            if (! empty($data[$param])) {
                $data[$param] = (new \DateTime($data[$param]))->format('Y-m-d H:i:s');
            }
        }

        $operations = array_key_exists('operaciones', $data)
            ? self::uniqueKeys(array_map([self::class, 'operationKey'], $data['operaciones']))
            : $this->storedOperations($keys);
        $this->assertNoBlockedTechniques($operations);

        if (array_key_exists('operaciones', $data)) {
            $data['_operaciones'] = $operations;
        }
        if (array_key_exists('personal', $data)) {
            $data['_personal'] = self::uniqueKeys(array_map(
                fn ($e) => [(string) ($e['delegacion'] ?? ''), (int) $e['codigo']], $data['personal']
            ));
        }
        $data['_nueva'] = $isNew;
        unset($data['operaciones'], $data['personal']);

        return $data;
    }

    /**
     * HayTecnicasBloqueadas (LABCON.CONBBTD): no se graba la orden si alguna
     * técnica de sus operaciones tiene una operación interna sin archivar ni
     * anular con dictamen distinto de satisfactorio.
     */
    private function assertNoBlockedTechniques(array $operations): void
    {
        $db = DB::connection('dynamic');
        if ($operations === [] || $db->table('LABCON')->where('CON1COD', 1)->value('CONBBTD') !== 'T') {
            return;
        }

        $techniques = $db->table('LABRES')
            ->where(function ($q) use ($operations) {
                foreach ($operations as [$del, $ser, $cod]) {
                    $q->orWhere(fn ($w) => $w->where('OPE3DEL', $del)->where('OPE3SER', $ser)->where('OPE3COD', $cod));
                }
            })
            ->distinct()->get(['TEC3DEL', 'TEC3COD']);
        if ($techniques->isEmpty()) {
            return;
        }

        $blocked = $db->table('LABOPE')
            ->where('OPENEST', '<', 7)->where('OPECTIP', 'I')
            ->where(fn ($q) => $q->whereNull('OPEBANU')->orWhere('OPEBANU', '<>', 'T'))
            ->where(fn ($q) => $q->whereNull('DIC2COD')->orWhere('DIC2COD', '<>', 1))
            ->where(function ($q) use ($techniques) {
                foreach ($techniques as $t) {
                    $q->orWhere(fn ($w) => $w->where('TEC2DEL', $t->TEC3DEL)->where('TEC2COD', $t->TEC3COD));
                }
            })
            ->exists();
        if ($blocked) {
            throw new BusinessRuleException('Hay técnicas bloqueadas por operaciones internas con dictamen no satisfactorio');
        }
    }

    /** Tras crear/modificar: operaciones y personal de la orden. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $order = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $isNew = $data['_nueva'];
        $added = [];

        if (isset($data['_operaciones'])) {
            $previous = $isNew ? [] : $this->storedOperations($keys);
            $this->saveOperations($order, $data['_operaciones'], $isNew);
            $added = array_values(array_filter($data['_operaciones'], fn ($op) => ! in_array($op, $previous, true)));
            if (! $isNew) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $this->auditRow($keys), 'LABOYO');
            }
        }

        // Personal: el indicado; si no, se suman los analistas de las
        // operaciones añadidas (la ficha los añade al seleccionarlas).
        $staff = $data['_personal'] ?? null;
        if ($staff === null && $added) {
            $staff = self::uniqueKeys(array_merge($isNew ? [] : $this->storedStaff($order), $this->analysts($added)));
        }
        if ($staff !== null) {
            $this->saveStaff($order, $staff);
            if (! $isNew) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $this->auditRow($keys), 'LABORE');
            }
        }

        return $data;
    }

    /**
     * FichaOrden.Grabar, "Operaciones": libera las posiciones de la lista
     * anterior, inserta cada operación a continuación de las órdenes en las
     * que ya está y pasa a preparadas las que no habían llegado a ese estado.
     */
    private function saveOperations(array $order, array $operations, bool $isNew): void
    {
        $db = DB::connection('dynamic');
        $orderKey = array_combine(['ORD3DEL', 'ORD3SER', 'ORD3COD'], $order);

        if (! $isNew) {
            $this->releasePositions($order);
            $db->table('LABOYO')->where($orderKey)->delete();
        }

        foreach ($operations as [$del, $ser, $cod]) {
            $operation = ['OPE3DEL' => $del, 'OPE3SER' => $ser, 'OPE3COD' => $cod];
            $position = (int) $db->table('LABOYO')->where($operation)->max('OYONPOS');
            $db->table('LABOYO')->insert($orderKey + $operation + ['OYONPOS' => $position + 1]);

            $db->table('LABOPE')
                ->where('DEL3COD', $del)->where('OPE1SER', $ser)->where('OPE1COD', $cod)
                ->where('OPENEST', '<', 2)
                ->update(['OPENEST' => 2, 'OPEDPRE' => DB::raw('CURDATE()')]);
        }
    }

    /**
     * Renumeración previa a quitar las operaciones de una orden: si la orden
     * era la primera de una operación (no queda ninguna en la posición 1),
     * la siguiente orden de esa operación pasa a ser la primera.
     */
    private function releasePositions(array $order): void
    {
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = $order;

        $rows = $db->table('LABOYO')->where('ORD3DEL', $del)->where('ORD3SER', $ser)->where('ORD3COD', $cod)
            ->get(['OPE3DEL', 'OPE3SER', 'OPE3COD']);

        foreach ($rows as $row) {
            $operation = ['OPE3DEL' => $row->OPE3DEL, 'OPE3SER' => $row->OPE3SER, 'OPE3COD' => $row->OPE3COD];
            $min = (int) $db->table('LABOYO')->where($operation)
                ->where(fn ($q) => $q->where('ORD3DEL', '<>', $del)->orWhere('ORD3SER', '<>', $ser)->orWhere('ORD3COD', '<>', $cod))
                ->min('OYONPOS');
            if ($min > 1) {
                $db->table('LABOYO')->where($operation)->where('OYONPOS', $min)->update(['OYONPOS' => 1]);
            }
        }
    }

    private function saveStaff(array $order, array $staff): void
    {
        $db = DB::connection('dynamic');
        $orderKey = array_combine(['ORD3DEL', 'ORD3SER', 'ORD3COD'], $order);

        $db->table('LABORE')->where($orderKey)->delete();
        foreach ($staff as [$del, $cod]) {
            $db->table('LABORE')->insert($orderKey + ['EMP3DEL' => $del, 'EMP3COD' => $cod]);
        }
    }

    /** Analistas (LABOYE) de las operaciones: [[delegación, código]]. */
    private function analysts(array $operations): array
    {
        return DB::connection('dynamic')->table('LABOYE')
            ->where(function ($q) use ($operations) {
                foreach ($operations as [$del, $ser, $cod]) {
                    $q->orWhere(fn ($w) => $w->where('OPE3DEL', $del)->where('OPE3SER', $ser)->where('OPE3COD', $cod));
                }
            })
            ->get(['EMP3DEL', 'EMP3COD'])
            ->map(fn ($row) => [(string) $row->EMP3DEL, (int) $row->EMP3COD])->all();
    }

    /** Operaciones actuales de la orden: [[delegación, serie, código]]. */
    private function storedOperations(array $keys): array
    {
        return DB::connection('dynamic')->table('LABOYO')
            ->where('ORD3DEL', (string) $keys['delegacion'])->where('ORD3SER', (string) $keys['serie'])
            ->where('ORD3COD', (int) $keys['codigo'])
            ->get(['OPE3DEL', 'OPE3SER', 'OPE3COD'])
            ->map(fn ($row) => [(string) $row->OPE3DEL, (string) $row->OPE3SER, (int) $row->OPE3COD])->all();
    }

    private function storedStaff(array $order): array
    {
        return DB::connection('dynamic')->table('LABORE')
            ->where(array_combine(['ORD3DEL', 'ORD3SER', 'ORD3COD'], $order))
            ->get(['EMP3DEL', 'EMP3COD'])
            ->map(fn ($row) => [(string) $row->EMP3DEL, (int) $row->EMP3COD])->all();
    }

    private static function operationKey(array $operation): array
    {
        return [(string) ($operation['delegacion'] ?? ''), (string) ($operation['serie'] ?? ''), (int) $operation['codigo']];
    }

    /** Claves sin repetir, conservando el orden. */
    private static function uniqueKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[implode("\x1B", $key)] ??= $key;
        }

        return array_values($out);
    }

    /**
     * Cada orden del listado lleva sus operaciones ([{delegacion, serie,
     * codigo, posicion}]) y su personal ([{delegacion, codigo}]).
     */
    protected function appendRelatedData(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $db = DB::connection('dynamic');
        $ofOrders = function ($q) use ($rows) {
            foreach ($rows as $row) {
                $q->orWhere(fn ($w) => $w->where('ORD3DEL', (string) $row['delegacion'])
                    ->where('ORD3SER', (string) $row['serie'])->where('ORD3COD', (int) $row['codigo']));
            }
        };
        $orderKey = fn ($r) => $r->ORD3DEL."\x1B".$r->ORD3SER."\x1B".$r->ORD3COD;

        $operations = [];
        foreach ($db->table('LABOYO')->where($ofOrders)
            ->orderBy('OPE3DEL')->orderBy('OPE3SER')->orderBy('OPE3COD')->get() as $r) {
            $operations[$orderKey($r)][] = [
                'delegacion' => (string) $r->OPE3DEL,
                'serie'      => (string) $r->OPE3SER,
                'codigo'     => (int) $r->OPE3COD,
                'posicion'   => $r->OYONPOS === null ? null : (int) $r->OYONPOS,
            ];
        }

        $staff = [];
        foreach ($db->table('LABORE')->where($ofOrders)->orderBy('EMP3DEL')->orderBy('EMP3COD')->get() as $r) {
            $staff[$orderKey($r)][] = ['delegacion' => (string) $r->EMP3DEL, 'codigo' => (int) $r->EMP3COD];
        }

        foreach ($rows as &$row) {
            $key = $row['delegacion']."\x1B".$row['serie']."\x1B".$row['codigo'];
            $row['operaciones'] = $operations[$key] ?? [];
            $row['personal'] = $staff[$key] ?? [];
        }

        return $rows;
    }

    /**
     * Antes de borrar la fila (Ordenes.Borrar): renumera las operaciones de
     * las que esta orden era la primera.
     */
    protected function validateBeforeDelete(array $keys): void
    {
        $this->releasePositions([(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']]);
    }

    /** Cascada de Ordenes.Borrar: operaciones, personal y documentos a la papelera. */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $db = DB::connection('dynamic');
        $orderKey = ['ORD3DEL' => $del, 'ORD3SER' => $ser, 'ORD3COD' => $cod];

        $db->table('LABOYO')->where($orderKey)->delete();
        $db->table('LABORE')->where($orderKey)->delete();
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('ORD2SER', $ser)->where('ORD2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);
    }
}
