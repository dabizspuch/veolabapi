<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabProficiency;
use Illuminate\Support\Facades\DB;

/**
 * Muestras de un ensayo de aptitud (CALEAO, desde Veolab 3), como la pestaña
 * Muestras de FichaEnsayoAptitud: operaciones normales de Veolab que son las
 * muestras de la ronda, con su identificación para el organizador (A, B...)
 * y su orden.
 *
 *  - Clave: la ronda + la operación (operacion_delegacion, operacion_serie,
 *    operacion_codigo). Cualquier operación salvo las anuladas, como el
 *    selector de operaciones de Calidad; no se repite.
 *  - Nueva: sin muestra, la letra siguiente (A, B... por el número de
 *    muestras); sin orden, al final.
 *  - Al quitar una muestra, sus resultados se quedan sin muestra
 *    (QuitarMuestras).
 */
class EnsayoAptitudMuestraController extends EnsayoAptitudChildController
{
    protected string $table = 'CALEAO';
    protected string $auditField = 'CALEAO';

    protected array $keys = [
        'ensayo_delegacion'    => 'DEL3COD',
        'ensayo_serie'         => 'EAP3SER',
        'ensayo_codigo'        => 'EAP3COD',
        'operacion_delegacion' => 'OPE3DEL',
        'operacion_serie'      => 'OPE3SER',
        'operacion_codigo'     => 'OPE3COD',
    ];

    protected array $mapping = [
        'ensayo_delegacion'    => 'DEL3COD',
        'ensayo_serie'         => 'EAP3SER',
        'ensayo_codigo'        => 'EAP3COD',
        'operacion_delegacion' => 'OPE3DEL',
        'operacion_serie'      => 'OPE3SER',
        'operacion_codigo'     => 'OPE3COD',
        'muestra'              => 'EAOCMUE',   // Identificación para el organizador (A, B, muestra 1...)
        'orden'                => 'EAONORD',
    ];

    protected array $searchFields = ['EAOCMUE'];

    protected function fieldRules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'operacion_delegacion' => 'nullable|string|max:10',
            'operacion_serie'      => 'nullable|string|max:10',
            'operacion_codigo'     => ($isCreating ? 'required' : 'sometimes').'|integer|min:1',
            'muestra'              => 'nullable|string|max:50',
            'orden'                => 'nullable|integer|min:0',
        ];
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $data = parent::validateAdditionalCriteria($data, $keys);
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = self::roundKey($keys ?: $data);
        $own = fn () => $db->table('CALEAO')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod);

        if (! $keys) {
            $data['operacion_delegacion'] = (string) ($data['operacion_delegacion'] ?? '');
            $data['operacion_serie'] = (string) ($data['operacion_serie'] ?? '');
            [$opDel, $opSer, $opCod] = [$data['operacion_delegacion'], $data['operacion_serie'], (int) $data['operacion_codigo']];
            $operation = $db->table('LABOPE')->where('DEL3COD', $opDel)->where('OPE1SER', $opSer)->where('OPE1COD', $opCod)->first(['OPEBANU']);
            if (! $operation) {
                throw new BusinessRuleException('La operación no existe');
            }
            if ($operation->OPEBANU === 'T') {
                throw new BusinessRuleException('La operación está anulada');
            }
            if ($own()->where('OPE3DEL', $opDel)->where('OPE3SER', $opSer)->where('OPE3COD', $opCod)->exists()) {
                throw new BusinessRuleException('La operación ya es una muestra del ensayo de aptitud');
            }
            // AnadirMuestra (PrimeraLetraLibre): sin identificación, la primera letra A-Z que no usa ninguna
            // muestra; con todas usadas, la siguiente al número de muestras
            if (trim((string) ($data['muestra'] ?? '')) === '') {
                $used = $own()->pluck('EAOCMUE')->map(fn ($m) => (string) $m)->all();
                $free = array_values(array_diff(range('A', 'Z'), $used));
                $data['muestra'] = $free[0] ?? chr(ord('A') + ($own()->count() % 26));
            }
            if (! isset($data['orden'])) {
                $data['orden'] = (int) $own()->max('EAONORD') + 1;
            }
        } elseif (array_key_exists('operacion_codigo', $data) || array_key_exists('operacion_delegacion', $data)
            || array_key_exists('operacion_serie', $data)) {
            // La clave no cambia (se quita y se añade otra)
            foreach (['operacion_delegacion', 'operacion_serie', 'operacion_codigo'] as $param) {
                if (array_key_exists($param, $data) && (string) ($data[$param] ?? '') !== (string) $keys[$param]) {
                    throw new BusinessRuleException('La operación de una muestra no se cambia: se quita y se añade otra');
                }
            }
        }

        if (array_key_exists('muestra', $data)) {
            $sample = trim((string) ($data['muestra'] ?? ''));
            $data['muestra'] = $sample === '' ? null : $sample;
        }

        return $data;
    }

    /** QuitarMuestras: los resultados de esa operación en la ronda se quedan sin muestra. */
    protected function deleteRelatedRecords(array $keys): void
    {
        [$del, $ser, $cod] = self::roundKey($keys);
        $changed = DB::connection('dynamic')->table('CALEAR')->where('DEL3COD', $del)->where('EAP3SER', $ser)->where('EAP3COD', $cod)
            ->where('OPE2DEL', (string) $keys['operacion_delegacion'])->where('OPE2SER', (string) $keys['operacion_serie'])
            ->where('OPE2COD', (int) $keys['operacion_codigo'])
            ->update(['OPE2DEL' => null, 'OPE2SER' => null, 'OPE2COD' => null]);
        // Grabar audita también los resultados cambiados (campo CALEAR de la ronda); la fila ya la audita el borrado.
        if ($changed > 0) {
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, VeolabProficiency::TABLE,
                VeolabCodes::format(VeolabProficiency::TABLE, (string) $cod, $del, $ser), 'CALEAR');
        }
    }

    /** Código formateado, fecha de registro y descripción de la operación (columnas de la pestaña). */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $operations = DB::connection('dynamic')->table('LABOPE')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['operacion_delegacion'])
                        ->where('OPE1SER', (string) $row['operacion_serie'])->where('OPE1COD', (int) $row['operacion_codigo']));
                }
            })
            ->get(['DEL3COD', 'OPE1SER', 'OPE1COD', 'OPEDREG', 'OPECDES'])
            ->keyBy(fn ($o) => $o->DEL3COD."\x1B".$o->OPE1SER."\x1B".$o->OPE1COD);

        foreach ($rows as &$row) {
            $operation = $operations[$row['operacion_delegacion']."\x1B".$row['operacion_serie']."\x1B".$row['operacion_codigo']] ?? null;
            $row['operacion_codigo_formateado'] = VeolabCodes::format('LABOPE', (string) $row['operacion_codigo'],
                (string) $row['operacion_delegacion'], (string) $row['operacion_serie']);
            $row['operacion_fecha_registro'] = $operation->OPEDREG ?? null;
            $row['operacion_descripcion'] = $operation->OPECDES ?? null;
        }
        unset($row);

        return $rows;
    }
}
