<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\AuditsOwnerRecord;
use Illuminate\Support\Facades\DB;

/**
 * Subtabla de una entidad de clave delegación + código (GRHTAR, GRHAUS,
 * LABPUM...): clave = la entidad padre + un código propio dentro de ella.
 *
 *  - El código, si no se indica, es el siguiente dentro del padre (como las
 *    rejillas de Veolab, que numeran las líneas de cada ficha), no el
 *    contador ACCCLT.
 *  - El padre debe existir. Auditoría sobre la ficha del padre.
 */
abstract class ChildController extends BaseController
{
    use AuditsOwnerRecord;

    /** Grupo del padre: parámetros {grupo}_delegacion y {grupo}_codigo. */
    protected string $parentGroup = '';

    /** Padre: [tabla, columna de código, 'int' | longitud máxima, mensaje]. */
    protected array $parentEntity = [];

    /** AUDCCAM del suceso de campo sobre la ficha del padre. */
    protected string $auditField = '';

    /** Reglas de los datos de la línea (sin la clave). */
    protected function fieldRules(): array
    {
        return [];
    }

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');
        $type = $this->parentEntity[2];

        return [
            "{$this->parentGroup}_delegacion" => 'nullable|string|max:10',
            "{$this->parentGroup}_codigo"     => ($isCreating ? 'required' : 'sometimes')
                .($type === 'int' ? '|integer|min:1' : "|string|max:{$type}"),
            'codigo'                          => 'nullable|integer|min:1',
        ] + $this->fieldRules();
    }

    protected function validateRelationships(array $data): void
    {
        if (! isset($data["{$this->parentGroup}_codigo"])) {
            return;
        }

        [$table, $codeColumn, , $message] = $this->parentEntity;
        $exists = DB::connection('dynamic')->table($table)
            ->where('DEL3COD', (string) ($data["{$this->parentGroup}_delegacion"] ?? ''))
            ->where($codeColumn, $data["{$this->parentGroup}_codigo"])
            ->exists();
        if (! $exists) {
            throw new BusinessRuleException($message);
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            return $data;
        }

        $data["{$this->parentGroup}_delegacion"] = (string) ($data["{$this->parentGroup}_delegacion"] ?? '');

        if (empty($data['codigo'])) {
            // Siguiente línea del padre, con el padre bloqueado hasta el commit.
            [$table, $codeColumn] = $this->parentEntity;
            DB::connection('dynamic')->table($table)
                ->where('DEL3COD', $data["{$this->parentGroup}_delegacion"])
                ->where($codeColumn, $data["{$this->parentGroup}_codigo"])
                ->lockForUpdate()->first();

            $data['codigo'] = (int) $this->parentQuery($data)->max($this->keys['codigo']) + 1;
        }

        return $data;
    }

    /** Líneas del mismo padre. */
    protected function parentQuery(array $data)
    {
        return DB::connection('dynamic')->table($this->table)
            ->where($this->keys["{$this->parentGroup}_delegacion"], (string) $data["{$this->parentGroup}_delegacion"])
            ->where($this->keys["{$this->parentGroup}_codigo"], $data["{$this->parentGroup}_codigo"]);
    }

    /** Fecha de la petición como datetime de Veolab (solo día). */
    protected static function day(?string $value): ?string
    {
        return empty($value) ? null : (new \DateTime($value))->format('Y-m-d 00:00:00');
    }

    // ------------------------------------------------------------------
    // Auditoría
    // ------------------------------------------------------------------

    protected function auditCreated(array $data, array $keyParams): void
    {
        $this->auditParent($keyParams);
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        $this->auditParent($keyParams);
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        $this->auditParent($keyParams);
    }

    private function auditParent(array $keyParams): void
    {
        $this->recordOwnerChange($this->parentEntity[0],
            (string) $keyParams["{$this->parentGroup}_codigo"],
            (string) $keyParams["{$this->parentGroup}_delegacion"],
            $this->auditField);
    }
}
