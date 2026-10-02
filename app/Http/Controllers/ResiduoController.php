<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabLicense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de residuos (LABRED, módulo GDR), como FichaResiduo de Veolab.
 *
 *  - es_baja y fecha_baja van juntas: con fecha de baja el residuo está de
 *    baja ('T'); sin ella no ('F', fecha NULL). Dar es_baja = 'T' sin fecha
 *    toma la fecha actual.
 *  - El valor total lo indica el usuario (Veolab no lo calcula).
 *  - POST /residuos/registro es el alta masiva de RegistroResiduos: unos
 *    datos comunes y una línea por tipo de residuo (las líneas sin unidades,
 *    valor unitario ni total se descartan, como en Veolab). A diferencia de
 *    Veolab, cada alta se audita.
 */
class ResiduoController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABRED';
    protected ?string $auditDescription = 'REDCDES';
    protected array $keys = [
        'delegacion'=> 'DEL3COD',
        'codigo'    => 'RED1COD',
    ];
    protected ?string $inactiveField = 'REDBBAJ';
    protected array $searchFields = ['RED1COD', 'REDCDES', 'REDCOBS'];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'              => 'DEL3COD',
        'codigo'                  => 'RED1COD',
        'descripcion'             => 'REDCDES',
        'fecha_registro'          => 'REDDFER',
        'fecha_baja'              => 'REDDBAJ',
        'es_baja'                 => 'REDBBAJ',
        'unidades'                => 'REDNUNI',
        'valor_unitario'          => 'REDNVAU',
        'valor_total'             => 'REDNTOT',
        'observaciones'           => 'REDCOBS',
        'tipo_residuo_delegacion' => 'TDR2DEL',
        'tipo_residuo_codigo'     => 'TDR2COD',
        'operacion_delegacion'    => 'OPE2DEL',
        'operacion_serie'         => 'OPE2SER',
        'operacion_codigo'        => 'OPE2COD',
        'tecnica_delegacion'      => 'TEC2DEL',
        'tecnica_codigo'          => 'TEC2COD',
        'empleado_delegacion'     => 'EMP2DEL',
        'empleado_codigo'         => 'EMP2COD',
    ];

    protected array $foreignKeys = [
        'tipo_residuo' => 'int',
        'operacion'    => 'int',
        'tecnica'      => 'string',
        'empleado'     => 'int',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'              => 'nullable|string|max:10',
            'codigo'                  => 'nullable|integer|min:1',
            'descripcion'             => 'nullable|string|max:100',
            'fecha_registro'          => 'nullable|date',
            'fecha_baja'              => 'nullable|date',
            'es_baja'                 => 'nullable|string|in:T,F',
            'unidades'                => 'nullable|numeric',
            'valor_unitario'          => 'nullable|numeric',
            'valor_total'             => 'nullable|numeric',
            'observaciones'           => 'nullable|string',
            'tipo_residuo_delegacion' => 'nullable|string|max:10',
            'tipo_residuo_codigo'     => 'nullable|integer',
            'operacion_delegacion'    => 'nullable|string|max:10',
            'operacion_serie'         => 'nullable|string|max:10',
            'operacion_codigo'        => 'nullable|integer',
            'tecnica_delegacion'      => 'nullable|string|max:10',
            'tecnica_codigo'          => 'nullable|string|max:30',
            'empleado_delegacion'     => 'nullable|string|max:10',
            'empleado_codigo'         => 'nullable|integer',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->assertModule();
        $this->checkReferences($data);
        $this->checkResidueReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $before = $keys
            ? DB::connection('dynamic')->table('LABRED')
                ->where('DEL3COD', (string) $keys['delegacion'])->where('RED1COD', $keys['codigo'])
                ->first(['REDDBAJ', 'REDBBAJ'])
            : null;

        $data = $this->normalizeDates($data, $before);

        if (! $keys) {
            $data['fecha_registro'] ??= $this->now();
            $data['unidades'] ??= 0;
            $data['valor_unitario'] ??= 0;
            $data['valor_total'] ??= 0;
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $this->assertModule();
    }

    /**
     * POST /residuos/registro: alta masiva (RegistroResiduos). Cuerpo: los
     * datos comunes del residuo (sin tipo, unidades ni valores) y 'lineas'
     * [{tipo_residuo_delegacion, tipo_residuo_codigo, unidades, valor_unitario,
     * valor_total}]. Devuelve las claves creadas.
     */
    public function register(Request $request)
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $db = DB::connection('dynamic');

        try {
            $db->beginTransaction();

            $rules = $this->rules();
            unset($rules['codigo'], $rules['tipo_residuo_delegacion'], $rules['tipo_residuo_codigo'],
                $rules['unidades'], $rules['valor_unitario'], $rules['valor_total']);
            $rules += [
                'lineas'                           => 'required|array|min:1',
                'lineas.*.tipo_residuo_delegacion' => 'nullable|string|max:10',
                'lineas.*.tipo_residuo_codigo'     => 'required|integer|min:1',
                'lineas.*.unidades'                => 'nullable|numeric',
                'lineas.*.valor_unitario'          => 'nullable|numeric',
                'lineas.*.valor_total'             => 'nullable|numeric',
            ];
            $validator = Validator::make($data, $rules);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $common = $validator->validated();
            $lines = $common['lineas'];
            unset($common['lineas']);

            $this->assertModule();
            $this->checkReferences($common);
            $this->checkResidueReferences($common);
            $common = $this->normalizeDates($common, null);
            $common['delegacion'] = (string) ($common['delegacion'] ?? '');
            $common['fecha_registro'] ??= $this->now();

            $created = [];
            foreach ($lines as $line) {
                // Como Veolab: solo las líneas con algún importe.
                if ((float) ($line['unidades'] ?? 0) == 0 && (float) ($line['valor_unitario'] ?? 0) == 0
                    && (float) ($line['valor_total'] ?? 0) == 0) {
                    continue;
                }
                $this->checkResidueReferences($line);

                $row = $common + [
                    'tipo_residuo_delegacion' => (string) ($line['tipo_residuo_delegacion'] ?? ''),
                    'tipo_residuo_codigo'     => (int) $line['tipo_residuo_codigo'],
                    'unidades'                => (float) ($line['unidades'] ?? 0),
                    'valor_unitario'          => (float) ($line['valor_unitario'] ?? 0),
                    'valor_total'             => (float) ($line['valor_total'] ?? 0),
                ];
                $row['codigo'] = $this->generateCode($common['delegacion'], '');

                $insert = [];
                foreach ($this->mapping as $param => $column) {
                    $insert[$column] = $row[$param] ?? null;
                }
                foreach (['operacion' => ['OPE2DEL' => '', 'OPE2SER' => '', 'OPE2COD' => 0],
                    'tecnica' => ['TEC2DEL' => '', 'TEC2COD' => ''],
                    'empleado' => ['EMP2DEL' => '', 'EMP2COD' => 0]] as $group => $empty) {
                    if (empty($row["{$group}_codigo"])) {
                        $insert = array_merge($insert, $empty);
                    }
                }
                foreach (['OPE2DEL', 'OPE2SER', 'TEC2DEL', 'EMP2DEL'] as $column) {
                    $insert[$column] ??= '';
                }
                $db->table('LABRED')->insert($insert);

                $keys = ['delegacion' => $common['delegacion'], 'codigo' => $row['codigo']];
                VeolabAudit::record(VeolabAudit::INSERCION, 'LABRED', $this->auditRow($keys));
                $created[] = $keys;
            }

            if (! $created) {
                throw new BusinessRuleException('Ninguna línea tiene unidades, valor unitario ni total');
            }

            $db->commit();

            return response()->json(['message' => 'Residuos registrados correctamente', 'data' => $created], 201);
        } catch (ValidationException $e) {
            $db->rollBack();

            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();
            Log::error('v2 register LABRED: '.$e->getMessage());

            return response()->json(['message' => 'Error al registrar los residuos'], 500);
        }
    }

    /** Tipo de residuo, operación y empleado responsable: existen. */
    private function checkResidueReferences(array $data): void
    {
        if (! empty($data['tipo_residuo_codigo'])) {
            $this->mustExist('LABTDR', [
                'DEL3COD' => (string) ($data['tipo_residuo_delegacion'] ?? ''),
                'TDR1COD' => $data['tipo_residuo_codigo'],
            ], 'El tipo de residuo no existe');
        }
        if (! empty($data['operacion_codigo'])) {
            $this->mustExist('LABOPE', [
                'DEL3COD' => (string) ($data['operacion_delegacion'] ?? ''),
                'OPE1SER' => (string) ($data['operacion_serie'] ?? ''),
                'OPE1COD' => $data['operacion_codigo'],
            ], 'La operación no existe');
        }
        if (! empty($data['empleado_codigo'])) {
            $this->mustExist('GRHEMP', [
                'DEL3COD' => (string) ($data['empleado_delegacion'] ?? ''),
                'EMP1COD' => $data['empleado_codigo'],
            ], 'El empleado responsable no existe');
        }
    }

    /**
     * Fechas en formato de BD y baja coherente con su fecha (FichaResiduo:
     * REDBBAJ = 'T' si y solo si hay REDDBAJ).
     */
    private function normalizeDates(array $data, ?object $before): array
    {
        foreach (['fecha_registro', 'fecha_baja'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = (new \DateTime($data[$field]))->format('Y-m-d H:i:s');
            }
        }

        if (array_key_exists('fecha_baja', $data)) {
            if (empty($data['fecha_baja']) && ($data['es_baja'] ?? null) === 'T') {
                throw new BusinessRuleException('Un residuo de baja requiere la fecha de baja');
            }
            $data['fecha_baja'] = $data['fecha_baja'] ?: null;
            $data['es_baja'] = $data['fecha_baja'] ? 'T' : 'F';
        } elseif (array_key_exists('es_baja', $data)) {
            if ($data['es_baja'] === 'T') {
                $data['fecha_baja'] = $before && $before->REDBBAJ === 'T' && $before->REDDBAJ ? $before->REDDBAJ : $this->now();
            } else {
                $data['es_baja'] = 'F';
                $data['fecha_baja'] = null;
            }
        } elseif (! $before) {
            $data['es_baja'] = 'F';
        }

        return $data;
    }

    private function now(): string
    {
        return DB::connection('dynamic')->selectOne('SELECT NOW() AS n')->n;
    }

    private function assertModule(): void
    {
        $db = DB::connection('dynamic');
        if (! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'GDR')) {
            throw new BusinessRuleException('El módulo de gestión de residuos no está activo');
        }
    }
}
