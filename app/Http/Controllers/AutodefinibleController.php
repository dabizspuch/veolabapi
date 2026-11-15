<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ChecksVeolabReferences;
use App\Support\VeolabAudit;
use App\Support\VeolabFieldFormulas;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Definición de los campos autodefinibles (LABAUT), como la configuración de
 * autodefinibles de Veolab. Los valores se graban con las operaciones y los
 * lotes (ver VeolabCustomFields), que los identifican por el nombre.
 *
 *  - ambito: O operaciones, L lotes (no se cambia).
 *  - tipo_dato: N número, D fecha, V fecha con aviso, T texto, E extenso,
 *    S seleccionable (opciones en formato), F fichero (tabla en formato),
 *    I incremento especial.
 *  - Nombre: empieza por letra, sin símbolos y no repetido (lo usan las
 *    fórmulas y los listados).
 *  - Al crear se añade a los campos accesibles de los perfiles de la
 *    delegación (ACCPER.PERCCAO) y al borrar se quita. No se borra si alguna
 *    operación o lote tiene valor; se borran sus servicios y valores vacíos.
 *  - Auditoría como Veolab: la fila es el nombre del autodefinible.
 *  - Desde Veolab 3, 'predeterminado' (AUTCPRE): valor fijo que se pone al
 *    crear la operación o el lote, o fórmula si empieza por "=" (ver
 *    VeolabFieldFormulas); los ficheros no lo admiten. En esas bases la
 *    pantalla «Campos» guarda además la configuración de los campos fijos de
 *    recolección como filas con tipo_dato X (formato = columna de LABOPE,
 *    es_baja = oculto, predeterminado), que aparecen en el listado y no se
 *    crean por la API.
 */
class AutodefinibleController extends BaseController
{
    use ChecksVeolabReferences;

    protected string $table = 'LABAUT';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'AUT1COD',
    ];
    protected ?string $inactiveField = 'AUTBBAJ';
    protected array $searchFields = ['AUTCNOM'];

    protected array $since = [
        'predeterminado' => VeolabVersion::V3,
    ];

    protected bool $generatesCode = true;

    protected array $mapping = [
        'delegacion'          => 'DEL3COD',
        'codigo'              => 'AUT1COD',
        'nombre'              => 'AUTCNOM',
        'ambito'              => 'AUTCTIP',
        'orden'               => 'AUTNORD',
        'tipo_dato'           => 'AUTCTDD',
        'formato'             => 'AUTCFOR',
        'es_categoria'        => 'AUTBCAT',
        'editable_resultados' => 'AUTBRES',
        'editable_validada'   => 'AUTBVAL',
        'es_baja'             => 'AUTBBAJ',
        'codigo_parametro'    => 'AUTCCOP',
        'codigo_metodo'       => 'AUTNMET',
        'codigo_laboratorio'  => 'AUTNCOL',
        'decimales'           => 'AUTNDEC',
        'predeterminado'      => 'AUTCPRE',
    ];

    /** Símbolos que la configuración de Veolab no admite en el nombre. */
    private const SYMBOLS = ['º', 'ª', 'çç', '_', '(', ')', '"', '/', '\\', '.', ':', ';', ',', '{', '}', '^', '`', '´',
        '|', '!', '¡', '?', '¿', '+', '*', '-', ']', '[', "'", '&', '%', '$', '#', '·', '<', '>'];

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'delegacion'          => 'nullable|string|max:10',
            'codigo'              => 'nullable|integer|min:1',
            'nombre'              => ($isCreating ? 'required' : 'sometimes').'|string|max:100',
            'ambito'              => ($isCreating ? 'required' : 'prohibited').'|string|in:O,L',
            'orden'               => 'nullable|integer|min:0',
            'tipo_dato'           => 'nullable|string|in:N,D,V,T,E,S,F,I',
            'formato'             => 'nullable|string',
            'es_categoria'        => 'nullable|string|in:T,F',
            'editable_resultados' => 'nullable|string|in:T,F',
            'editable_validada'   => 'nullable|string|in:T,F',
            'es_baja'             => 'nullable|string|in:T,F',
            'codigo_parametro'    => 'nullable|string|max:10',
            'codigo_metodo'       => 'nullable|integer',
            'codigo_laboratorio'  => 'nullable|numeric',
            'decimales'           => 'nullable|integer|min:0',
            'predeterminado'      => 'nullable|string',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        $this->checkReferences($data);
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (array_key_exists('nombre', $data)) {
            $data['nombre'] = trim((string) $data['nombre']);
            $this->checkName($data['nombre'], $keys);
        }

        if (array_key_exists('predeterminado', $data)) {
            $data['predeterminado'] = $this->checkDefault((string) ($data['predeterminado'] ?? ''), $data, $keys);
        }

        if (! $keys) {
            $data['delegacion'] = (string) ($data['delegacion'] ?? '');
            $data['orden'] ??= (int) DB::connection('dynamic')->table('LABAUT')
                ->where('AUTCTIP', $data['ambito'])->max('AUTNORD') + 1;
            $data['tipo_dato'] ??= 'T';
            $data['formato'] ??= '';
            $data['es_categoria'] ??= 'F';
            $data['editable_resultados'] ??= 'F';
            $data['editable_validada'] ??= 'F';
            $data['es_baja'] ??= 'F';
            $data['codigo_parametro'] ??= '';
            $data['codigo_metodo'] ??= 0;
            $data['codigo_laboratorio'] ??= 0;
            $data['decimales'] ??= 0;
            if ($this->supports('predeterminado')) {
                $data['predeterminado'] ??= '';
            }
        }

        return $data;
    }

    /** Valor predeterminado o fórmula (Veolab 3): sintaxis de la fórmula y tipos que lo admiten. */
    private function checkDefault(string $default, array $data, array $keys): string
    {
        $default = trim($default);
        if ($default === '') {
            return '';
        }
        $type = $data['tipo_dato'] ?? ($keys ? (string) DB::connection('dynamic')->table('LABAUT')
            ->where('DEL3COD', (string) $keys['delegacion'])->where('AUT1COD', $keys['codigo'])->value('AUTCTDD') : 'T');
        if ($type === 'F') {
            throw new BusinessRuleException('Los autodefinibles de fichero no admiten valor predeterminado');
        }
        if (VeolabFieldFormulas::isFormula($default)) {
            [$valid] = VeolabFieldFormulas::validate($default);
            if (! $valid) {
                throw new BusinessRuleException('La fórmula del valor predeterminado no es válida');
            }
        }

        return $default;
    }

    /** CamposValidos de la configuración: letra inicial, sin símbolos, único. */
    private function checkName(string $name, array $keys): void
    {
        if (! preg_match('/^[A-Za-zÁÉÍÓÚÀÈÌÒÙÂÊÎÔÛÄËÏÖÜáéíóúàèìòùâêîôûäëïöüÑñÇç]/u', $name)) {
            throw new BusinessRuleException('El nombre del autodefinible debe comenzar por una letra');
        }
        foreach (self::SYMBOLS as $symbol) {
            if (str_contains($name, $symbol)) {
                throw new BusinessRuleException("El nombre del autodefinible no puede contener '{$symbol}'");
            }
        }

        $query = DB::connection('dynamic')->table('LABAUT')->where('AUTCNOM', $name);
        if ($keys) {
            $query->where(fn ($q) => $q->where('DEL3COD', '!=', (string) $keys['delegacion'])->orWhere('AUT1COD', '!=', $keys['codigo']));
        }
        if ($query->exists()) {
            throw new BusinessRuleException('El nombre del autodefinible ya está en uso');
        }
    }

    /** Alta: se añade a los campos de operación de los perfiles de la delegación. */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        if (! request()->isMethod('post')) {
            return $data;
        }

        $field = $this->profileField($keys);
        DB::connection('dynamic')->table('ACCPER')
            ->where('DEL3COD', (string) $keys['delegacion'])
            ->update(['PERCCAO' => DB::raw('CONCAT(COALESCE(PERCCAO, \'\'), '.DB::connection('dynamic')->getPdo()->quote($field).')')]);

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        foreach (['LABOYA' => ['OYACVAL', 'alguna operación'], 'LABLYA' => ['LYACVAL', 'algún lote']] as $table => [$column, $where]) {
            $used = DB::connection('dynamic')->table($table)
                ->where('AUT3DEL', (string) $keys['delegacion'])->where('AUT3COD', $keys['codigo'])
                ->whereNotNull($column)->where($column, '!=', '')->exists();
            if ($used) {
                throw new BusinessRuleException("El autodefinible no puede ser eliminado porque tiene valor en {$where}; se puede dar de baja");
            }
        }
    }

    /**
     * Servicios, valores (vacíos) en operaciones, lotes y planificaciones, y
     * el campo en los perfiles.
     */
    protected function deleteRelatedRecords(array $keys): void
    {
        $db = DB::connection('dynamic');
        foreach (['LABAYS', 'LABOYA', 'LABLYA', 'LABPYA'] as $table) {
            $db->table($table)->where('AUT3DEL', (string) $keys['delegacion'])->where('AUT3COD', $keys['codigo'])->delete();
        }

        $field = $this->profileField($keys);
        $db->table('ACCPER')->where('PERCCAO', 'like', '%'.$field.'%')
            ->update(['PERCCAO' => DB::raw('REPLACE(PERCCAO, '.$db->getPdo()->quote($field).', \'\')')]);
    }

    /** Entrada del autodefinible en ACCPER.PERCCAO. */
    private function profileField(array $keys): string
    {
        return ';AU_'.$keys['delegacion'].'_'.$keys['codigo'].'.OYACVAL';
    }

    // Auditoría: la configuración identifica el autodefinible por su nombre.

    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        return (string) DB::connection('dynamic')->table('LABAUT')
            ->where('DEL3COD', (string) $keyParams['delegacion'])
            ->where('AUT1COD', $keyParams['codigo'])
            ->value('AUTCNOM');
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::BORRADO, $this->table, (string) $before['AUTCNOM']);
    }
}
