<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use Illuminate\Support\Facades\DB;

/**
 * Tabla de relación N:N entre dos entidades de clave delegación + código
 * (LABSYT, LABTYE, GRHEYC...). La clave son las dos entidades; el resto de
 * columnas son datos de la relación.
 *
 *  - Las dos entidades deben existir; una relación repetida da 422.
 *  - La posición (orden en la ficha de Veolab), si la hay, se asigna al
 *    final cuando no se indica.
 *  - Auditoría como en Veolab: la relación se graba desde la ficha de una de
 *    las entidades, que anota un suceso de fila (nivel 2) o de campo con la
 *    rejilla modificada (nivel 3, AUDCCAM = $auditField, sin valores).
 */
abstract class RelationController extends BaseController
{
    /**
     * Entidades enlazadas, en el orden de la clave:
     * grupo => [tabla, columna de código, 'int' | longitud máxima del código, mensaje].
     * Los parámetros son {grupo}_delegacion y {grupo}_codigo.
     */
    protected array $entities = [];

    /** Grupo de la entidad cuya ficha mantiene la relación (auditoría). */
    protected string $auditOwner = '';

    /** AUDCCAM del suceso de campo: lo que la ficha anota como modificado. */
    protected string $auditField = '';

    /** Parámetro de posición y primer valor que asigna la ficha. */
    protected ?string $positionKey = null;
    protected int $firstPosition = 1;

    /** Reglas de los datos de la relación (sin las claves). */
    protected function fieldRules(): array
    {
        return [];
    }

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        $rules = [];
        foreach ($this->entities as $group => [, , $type]) {
            $rules["{$group}_delegacion"] = 'nullable|string|max:10';
            $rules["{$group}_codigo"] = ($isCreating ? 'required' : 'sometimes')
                .($type === 'int' ? '|integer|min:1' : "|string|max:{$type}");
        }
        if ($this->positionKey) {
            $rules[$this->positionKey] = 'nullable|integer|min:1';
        }

        return $rules + $this->fieldRules();
    }

    protected function validateRelationships(array $data): void
    {
        foreach ($this->entities as $group => [$table, $codeColumn, , $message]) {
            if (! isset($data["{$group}_codigo"])) {
                continue;
            }
            $exists = DB::connection('dynamic')->table($table)
                ->where('DEL3COD', (string) ($data["{$group}_delegacion"] ?? ''))
                ->where($codeColumn, $data["{$group}_codigo"])
                ->exists();
            if (! $exists) {
                throw new BusinessRuleException($message);
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            return $data;
        }

        // Clave sin nulos: la delegación ausente es ''.
        foreach (array_keys($this->entities) as $group) {
            $data["{$group}_delegacion"] = (string) ($data["{$group}_delegacion"] ?? '');
        }

        if ($this->positionKey && empty($data[$this->positionKey])) {
            $data[$this->positionKey] = $this->nextPosition($data);
        }

        return $data;
    }

    /** Siguiente posición dentro de la entidad que mantiene la relación. */
    private function nextPosition(array $data): int
    {
        $query = DB::connection('dynamic')->table($this->table)
            ->where($this->mapping["{$this->auditOwner}_delegacion"], $data["{$this->auditOwner}_delegacion"])
            ->where($this->mapping["{$this->auditOwner}_codigo"], $data["{$this->auditOwner}_codigo"]);

        return max((int) $query->max($this->mapping[$this->positionKey]) + 1, $this->firstPosition);
    }

    // ------------------------------------------------------------------
    // Auditoría
    // ------------------------------------------------------------------

    protected function auditCreated(array $data, array $keyParams): void
    {
        $this->auditOwnerChange($keyParams);
    }

    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        $this->auditOwnerChange($keyParams);
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        $this->auditOwnerChange($keyParams);
    }

    /** Suceso de la ficha de la entidad que mantiene la relación. */
    protected function auditOwnerChange(array $keyParams): void
    {
        $table = $this->entities[$this->auditOwner][0];
        $row = VeolabCodes::format($table,
            (string) $keyParams["{$this->auditOwner}_codigo"],
            (string) $keyParams["{$this->auditOwner}_delegacion"]);

        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $table, $row);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $table, $row, $this->auditField);
    }
}
