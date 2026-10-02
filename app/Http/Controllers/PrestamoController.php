<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabLicense;
use App\Support\VeolabStock;
use Illuminate\Support\Facades\DB;

/**
 * Préstamos de material (ALMPRE + líneas ALMPYS, módulo ALM), como
 * FichaPrestamo de Veolab.
 *
 *  - Estados: R registrado, E entregado, P parcialmente devuelto, D devuelto,
 *    C cancelado. Al cambiar el estado se completan las fechas como la ficha
 *    (R: solo registro; E: registro y entrega; P y D: las tres; C no las
 *    toca); dar la fecha de entrega a un préstamo registrado lo pasa a
 *    entregado, y la de devolución a uno entregado, a devuelto.
 *  - 'lineas' [{producto_delegacion, producto_codigo, serie_lote_codigo,
 *    cantidad_prestada, cantidad_devuelta}] sustituye las del préstamo. La API
 *    exige además que la devuelta no supere la prestada (Veolab no lo mira).
 *  - Al crear, cambiar las líneas o el estado se rehacen los movimientos de
 *    préstamo (P) y devolución (D) y las existencias, que solo cuentan en E, P
 *    y D. Si una serie o lote no tiene existencias suficientes no se graba.
 *  - Al borrar, las existencias se devuelven, se borran líneas y movimientos
 *    y los documentos van a la papelera.
 *  - Cada préstamo devuelve sus líneas (con la cantidad pendiente) y el
 *    cliente de su operación.
 */
class PrestamoController extends BaseController
{
    protected string $table = 'ALMPRE';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'PRE1COD',
    ];
    protected array $searchFields = ['PRE1COD', 'PRECREF', 'PRECOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'           => 'DEL3COD',
        'codigo'               => 'PRE1COD',
        'fecha_registro'       => 'PREDREG',
        'fecha_entrega'        => 'PREDENT',
        'fecha_devolucion'     => 'PREDDEV',
        'referencia'           => 'PRECREF',
        'estado'               => 'PRECEST',
        'es_archivado'         => 'PREBARC',
        'observaciones'        => 'PRECOBS',
        'operacion_delegacion' => 'OPE2DEL',
        'operacion_serie'      => 'OPE2SER',
        'operacion_codigo'     => 'OPE2COD',
    ];

    protected array $foreignKeys = [
        'operacion' => 'int',
    ];

    private const STATES = ['R', 'E', 'P', 'D', 'C'];

    /** Estados en los que el material está fuera (generan movimientos). */
    private const ACTIVE_STATES = ['E', 'P', 'D'];

    protected function rules(): array
    {
        return [
            'delegacion'                   => 'nullable|string|max:10',
            'codigo'                       => 'nullable|integer|min:1',
            'fecha_registro'               => 'nullable|date',
            'fecha_entrega'                => 'nullable|date',
            'fecha_devolucion'             => 'nullable|date',
            'referencia'                   => 'nullable|string|max:50',
            'estado'                       => 'nullable|string|in:'.implode(',', self::STATES),
            'es_archivado'                 => 'nullable|string|in:T,F',
            'observaciones'                => 'nullable|string',
            'operacion_delegacion'         => 'nullable|string|max:10',
            'operacion_serie'              => 'nullable|string|max:10',
            'operacion_codigo'             => 'nullable|integer',
            'lineas'                       => 'sometimes|nullable|array',
            'lineas.*.producto_delegacion' => 'nullable|string|max:10',
            'lineas.*.producto_codigo'     => 'required|string|max:15',
            'lineas.*.serie_lote_codigo'   => 'required|string|max:30',
            'lineas.*.cantidad_prestada'   => 'nullable|numeric|min:0',
            'lineas.*.cantidad_devuelta'   => 'nullable|numeric|min:0',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->assertModule();
        $db = DB::connection('dynamic');

        if (! empty($data['delegacion']) && ! $db->table('ACCDEL')->where('DEL1COD', $data['delegacion'])->exists()) {
            throw new BusinessRuleException('La delegación no existe');
        }

        if (! empty($data['operacion_codigo'])) {
            $exists = $db->table('LABOPE')
                ->where('DEL3COD', (string) ($data['operacion_delegacion'] ?? ''))
                ->where('OPE1SER', (string) ($data['operacion_serie'] ?? ''))
                ->where('OPE1COD', $data['operacion_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La operación no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $before = $keys
            ? DB::connection('dynamic')->table('ALMPRE')
                ->where('DEL3COD', (string) $keys['delegacion'])->where('PRE1COD', $keys['codigo'])
                ->first(['PREDREG', 'PREDENT', 'PREDDEV', 'PRECEST'])
            : null;

        foreach (['fecha_registro', 'fecha_entrega', 'fecha_devolucion'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = (new \DateTime($data[$field]))->format('Y-m-d H:i:s');
            }
        }

        $previousState = $before ? (string) $before->PRECEST : null;
        $data = $this->applyStateDates($data, $before);

        if (! $keys) {
            $data['es_archivado'] ??= 'F';
        }

        $data['_nuevo'] = ! $keys;
        $data['_lineas'] = array_key_exists('lineas', $data) ? $this->validateLines($data['lineas'] ?? []) : null;
        $data['_regenerar'] = ! $keys || $data['_lineas'] !== null
            || (array_key_exists('estado', $data) && $data['estado'] !== $previousState);
        unset($data['lineas']);

        return $data;
    }

    /** Líneas, movimientos y existencias. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];

        if ($data['_lineas'] !== null) {
            $db->table('ALMPYS')->where('PRE3DEL', $del)->where('PRE3COD', $cod)->delete();
            foreach ($data['_lineas'] as [$productDelegation, $product, $lot, $lent, $returned]) {
                $db->table('ALMPYS')->insert([
                    'PRE3DEL' => $del, 'PRE3COD' => $cod,
                    'PRD3DEL' => $productDelegation, 'PRD3COD' => $product, 'SEL3COD' => $lot,
                    'PYSNPRE' => $lent, 'PYSNDEV' => $returned,
                ]);
            }

            if (! $data['_nuevo']) {
                $row = $this->auditRow($keys);
                if (! array_intersect_key($data, $this->mapping)) {
                    VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'ALMPRE', $row);
                }
                if (VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
                    VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'ALMPRE', $row, 'ALMPYS');
                }
            }
        }

        if ($data['_regenerar']) {
            $state = (string) $db->table('ALMPRE')->where('DEL3COD', $del)->where('PRE1COD', $cod)->value('PRECEST');
            $lines = $data['_lineas'] ?? $this->storedLines($del, $cod);
            VeolabStock::syncLoan($del, $cod, $lines, in_array($state, self::ACTIVE_STATES, true));
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $this->assertModule();
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        [$del, $cod] = [(string) $keys['delegacion'], (int) $keys['codigo']];

        // Devuelve a las existencias lo prestado y borra los movimientos.
        VeolabStock::syncLoan($del, $cod, [], false);
        $db->table('ALMPYS')->where('PRE3DEL', $del)->where('PRE3COD', $cod)->delete();

        // Documentos a la papelera.
        $db->table('DOCFAT')->where('DEL3COD', $del)->where('PRT2COD', $cod)
            ->update(['DIR2DEL' => $del, 'DIR2COD' => 0]);
    }

    /** Líneas del préstamo y cliente de su operación. */
    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');

        foreach ($rows as &$row) {
            $row['lineas'] = $db->table('ALMPYS')
                ->where('PRE3DEL', (string) $row['delegacion'])->where('PRE3COD', $row['codigo'])
                ->orderBy('PRD3DEL')->orderBy('PRD3COD')->orderBy('SEL3COD')
                ->get()
                ->map(fn ($line) => [
                    'producto_delegacion' => $line->PRD3DEL,
                    'producto_codigo'     => $line->PRD3COD,
                    'serie_lote_codigo'   => $line->SEL3COD,
                    'cantidad_prestada'   => $line->PYSNPRE,
                    'cantidad_devuelta'   => $line->PYSNDEV,
                    'cantidad_pendiente'  => round((float) $line->PYSNPRE - (float) $line->PYSNDEV, 5),
                ])->all();

            $client = $row['operacion_codigo']
                ? $db->table('LABOPE')
                    ->where('DEL3COD', (string) $row['operacion_delegacion'])
                    ->where('OPE1SER', (string) $row['operacion_serie'])
                    ->where('OPE1COD', $row['operacion_codigo'])
                    ->first(['CLI2DEL', 'CLI2COD'])
                : null;
            $hasClient = $client && (int) $client->CLI2COD > 0;
            $row['cliente_delegacion'] = $hasClient ? $client->CLI2DEL : null;
            $row['cliente_codigo'] = $hasClient ? $client->CLI2COD : null;
        }

        return $rows;
    }

    /**
     * Estado y fechas (FichaPrestamo: ActualizarFechasSegunEstado y los
     * cambios de fecha de entrega y devolución).
     */
    private function applyStateDates(array $data, ?object $before): array
    {
        $current = fn (string $field, string $column) => array_key_exists($field, $data)
            ? $data[$field]
            : ($before ? $before->{$column} : null);

        if (! array_key_exists('estado', $data) || $data['estado'] === null) {
            $state = $before ? (string) $before->PRECEST : 'R';
            if ($state === 'R' && ! empty($data['fecha_entrega'])) {
                $state = 'E';
            }
            if ($state === 'E' && ! empty($data['fecha_devolucion'])) {
                $state = 'D';
            }
            if (! $before || $state !== (string) $before->PRECEST) {
                $data['estado'] = $state;
            } else {
                unset($data['estado']);

                return $data;
            }
        }

        $now = fn () => DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
        $fields = [
            'fecha_registro'   => 'PREDREG',
            'fecha_entrega'    => 'PREDENT',
            'fecha_devolucion' => 'PREDDEV',
        ];
        $required = match ($data['estado']) {
            'R'     => ['fecha_registro'],
            'E'     => ['fecha_registro', 'fecha_entrega'],
            'P', 'D' => ['fecha_registro', 'fecha_entrega', 'fecha_devolucion'],
            default => null,
        };
        if ($required === null) {
            return $data;
        }

        foreach ($fields as $field => $column) {
            if (in_array($field, $required, true)) {
                if (empty($current($field, $column))) {
                    $data[$field] = $now();
                }
            } elseif (! empty($current($field, $column))) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /** Líneas: series o lotes existentes, sin repetir, devuelta <= prestada. */
    private function validateLines(array $lines): array
    {
        $db = DB::connection('dynamic');
        $out = [];
        $seen = [];

        foreach ($lines as $line) {
            $productDelegation = (string) ($line['producto_delegacion'] ?? '');
            $product = (string) $line['producto_codigo'];
            $lot = (string) $line['serie_lote_codigo'];
            $lent = round((float) ($line['cantidad_prestada'] ?? 0), 5);
            $returned = round((float) ($line['cantidad_devuelta'] ?? 0), 5);

            $key = $productDelegation."\x1B".$product."\x1B".$lot;
            if (isset($seen[$key])) {
                throw new BusinessRuleException("La serie o lote {$product} - {$lot} está repetida");
            }
            $seen[$key] = true;

            $exists = $db->table('ALMSEL')
                ->where('PRD3DEL', $productDelegation)->where('PRD3COD', $product)->where('SEL1COD', $lot)->exists();
            if (! $exists) {
                throw new BusinessRuleException("La serie o lote {$product} - {$lot} no existe");
            }
            if ($returned > $lent) {
                throw new BusinessRuleException("La cantidad devuelta de {$product} - {$lot} supera la prestada");
            }

            $out[] = [$productDelegation, $product, $lot, $lent, $returned];
        }

        return $out;
    }

    private function storedLines(string $delegation, int $code): array
    {
        return DB::connection('dynamic')->table('ALMPYS')
            ->where('PRE3DEL', $delegation)->where('PRE3COD', $code)
            ->get()
            ->map(fn ($line) => [$line->PRD3DEL, $line->PRD3COD, $line->SEL3COD, (float) $line->PYSNPRE, (float) $line->PYSNDEV])
            ->all();
    }

    private function assertModule(): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'ALM')) {
            throw new BusinessRuleException('El módulo de almacén no está activo');
        }
    }
}
