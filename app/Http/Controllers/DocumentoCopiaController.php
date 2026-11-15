<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabCodes;
use App\Support\VeolabDocumentControl;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Copias controladas en papel de un documento controlado (DOCCOP, control de
 * documentos fase 2, desde Veolab 3; ISO/IEC 17025:2017 8.3.2 f): número de
 * copia, ubicación o persona, edición entregada y fechas de entrega y de
 * retirada.
 *
 *  - Alta: delegacion + documento; codigo (número de copia) por defecto el
 *    siguiente del documento; version (edición entregada) por defecto la
 *    vigente (VER2APR), y siempre una edición aprobada; fecha_entrega por
 *    defecto ahora. Hace falta ubicacion o el empleado.
 *  - «Por retirar» (por_retirar = T): sin fecha de retirada y de una edición
 *    anterior a la vigente o de un documento obsoleto. Se cierra poniendo fecha_retirada. Filtro
 *    ?por_retirar=T|F.
 *  - Con ?usuario_delegacion=&usuario_codigo=, dar de alta, cambiar y borrar
 *    exigen el privilegio de aprobar documentos (especial 1 de DOC_CTL, el
 *    responsable de calidad); sin usuario la API actúa como privilegiada.
 *  - Solo con el módulo Calidad y el script de la fase 2 (si no, 422). Un
 *    acceso directo no tiene copias: son las de su destino.
 */
class DocumentoCopiaController extends BaseController
{
    protected string $table = 'DOCCOP';
    protected ?string $sinceVersion = VeolabVersion::V3;
    protected int $maxPerPage = 500;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'documento'  => 'FAT3COD',
        'codigo'     => 'COP1COD',
    ];

    protected array $mapping = [
        'delegacion'          => 'DEL3COD',
        'documento'           => 'FAT3COD',
        'codigo'              => 'COP1COD',
        'ubicacion'           => 'COPCUBI',
        'empleado_delegacion' => 'EMP2DEL',
        'empleado_codigo'     => 'EMP2COD',
        'version'             => 'VER2COD',
        'fecha_entrega'       => 'COPDENT',
        'fecha_retirada'      => 'COPDRET',
    ];

    protected array $searchFields = ['COPCUBI'];

    protected array $foreignKeys = ['empleado' => 'int'];

    protected ?string $delegationKey = 'delegacion';

    /** Sin el control de documentos activo o sin el script de la fase 2, 422. */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabDocumentControl::available()) {
            return response()->json(['message' => 'El control de documentos requiere el script de conversión que crea DOCLEC y los campos de control'], 422);
        }
        if (! VeolabDocumentControl::active()) {
            return response()->json(['message' => VeolabDocumentControl::INACTIVE_MESSAGE], 422);
        }
        if (! VeolabDocumentControl::distributionAvailable()) {
            return response()->json(['message' => VeolabDocumentControl::DISTRIBUTION_MESSAGE], 422);
        }

        return null;
    }

    protected function rules(): array
    {
        $isCreating = request()->isMethod('post');

        return [
            'delegacion'          => 'nullable|string|max:10',
            'documento'           => ($isCreating ? 'required' : 'sometimes').'|integer|min:1',
            'codigo'              => 'nullable|integer|min:1',
            'ubicacion'           => 'sometimes|nullable|string|max:255',
            'empleado_delegacion' => 'sometimes|nullable|string|max:10',
            'empleado_codigo'     => 'sometimes|nullable|integer|min:0',
            'version'             => 'sometimes|nullable|integer|min:1',
            'fecha_entrega'       => 'sometimes|nullable|date',
            'fecha_retirada'      => 'sometimes|nullable|date',
        ];
    }

    /** Documento controlado de la copia (bloqueado hasta el commit) o 422. */
    private function controlledDocument(string $del, int $cod): object
    {
        $document = VeolabDocumentControl::document($del, $cod, true);
        if (! $document) {
            throw new BusinessRuleException('El documento no existe');
        }
        VeolabDocuments::assertNotShortcut($document, 'gestionar sus copias controladas');
        if (! VeolabDocumentControl::isControlled($document)) {
            throw new BusinessRuleException('Solo los documentos controlados tienen copias controladas');
        }

        return $document;
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $creating = ! $keys;
        $del = $creating ? (string) ($data['delegacion'] ?? '') : (string) $keys['delegacion'];
        $cod = $creating ? (int) $data['documento'] : (int) $keys['documento'];
        $document = $this->controlledDocument($del, $cod);
        VeolabDocumentControl::assertApprover(VeolabDocumentControl::queryUser(), 'gestionar las copias controladas');

        $current = $creating ? null : $db->table('DOCCOP')->where('DEL3COD', $del)->where('FAT3COD', $cod)
            ->where('COP1COD', (int) $keys['codigo'])->first();

        if ($creating) {
            $data['delegacion'] = $del;
            if ((int) ($data['codigo'] ?? 0) <= 0) {
                $data['codigo'] = (int) $db->table('DOCCOP')->where('DEL3COD', $del)->where('FAT3COD', $cod)->max('COP1COD') + 1;
            }
            if (! array_key_exists('version', $data) || $data['version'] === null) {
                $data['version'] = (int) ($document->VER2APR ?? 0);
                if ($data['version'] <= 0) {
                    throw new BusinessRuleException('El documento aún no tiene ninguna edición aprobada que entregar');
                }
            }
            if (($data['fecha_entrega'] ?? null) === null) {
                $data['fecha_entrega'] = (string) $db->selectOne('SELECT NOW() AS n')->n;
            }
        } else {
            unset($data['delegacion'], $data['documento'], $data['codigo']);
        }

        if (($data['version'] ?? null) !== null) {
            $version = (int) $data['version'];
            $row = $db->table('DOCVER')->where('DEL3COD', $del)->where('FAT3COD', $cod)->where('VER1COD', $version)->first(['VERDAPR']);
            if (! $row) {
                throw new BusinessRuleException('La edición entregada no existe');
            }
            if ($version !== (int) ($document->VER2APR ?? 0) && empty($row->VERDAPR)) {
                throw new BusinessRuleException('Solo se entregan copias de ediciones aprobadas');
            }
        }

        if ((int) ($data['empleado_codigo'] ?? 0) > 0) {
            $data['empleado_delegacion'] = (string) ($data['empleado_delegacion'] ?? '');
            VeolabDocumentControl::assertEmployee($data['empleado_delegacion'], (int) $data['empleado_codigo']);
        }

        $location = array_key_exists('ubicacion', $data) ? trim((string) $data['ubicacion']) : trim((string) ($current->COPCUBI ?? ''));
        $employee = array_key_exists('empleado_codigo', $data) ? (int) ($data['empleado_codigo'] ?? 0) : (int) ($current->EMP2COD ?? 0);
        if ($location === '' && $employee <= 0) {
            throw new BusinessRuleException('Indique la ubicación o el empleado que tiene la copia');
        }

        $delivered = array_key_exists('fecha_entrega', $data) ? $data['fecha_entrega'] : ($current->COPDENT ?? null);
        $withdrawn = array_key_exists('fecha_retirada', $data) ? $data['fecha_retirada'] : ($current->COPDRET ?? null);
        if ($delivered && $withdrawn && substr((string) $withdrawn, 0, 10) < substr((string) $delivered, 0, 10)) {
            throw new BusinessRuleException('La fecha de retirada no puede ser anterior a la de entrega');
        }

        return $data;
    }

    /** Borrar exige el mismo privilegio que dar de alta. */
    protected function validateBeforeDelete(array $keys): void
    {
        $this->controlledDocument((string) $keys['delegacion'], (int) $keys['documento']);
        VeolabDocumentControl::assertApprover(VeolabDocumentControl::queryUser(), 'gestionar las copias controladas');
    }

    /** ?por_retirar=T|F; las copias de un acceso directo son las de su destino (422). */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $code = $request->query('documento');
        if ($request->has('delegacion') && is_string($code) && ctype_digit($code)) {
            $document = VeolabDocumentControl::document((string) ($request->query('delegacion') ?? ''), (int) $code);
            if ($document) {
                VeolabDocuments::assertNotShortcut($document, 'consultar sus copias controladas');
            }
        }

        $flag = $request->query('por_retirar');
        if (in_array($flag, ['T', 'F'], true)) {
            $condition = function ($q) {
                $q->select(DB::raw(1))->from('DOCFAT')
                    ->whereColumn('DOCFAT.DEL3COD', 'DOCCOP.DEL3COD')->whereColumn('DOCFAT.FAT1COD', 'DOCCOP.FAT3COD')
                    ->whereRaw(VeolabDocumentControl::toWithdrawSql('DOCCOP', 'DOCFAT'));
            };
            $flag === 'T' ? $query->whereExists($condition) : $query->whereNotExists($condition);
        }
    }

    /** Edición entregada, edición vigente, nombre del empleado y si está por retirar (una consulta por documento). */
    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        $documents = [];
        $editions = [];
        $names = VeolabDocumentControl::employeeNames(array_map(
            fn ($row) => [$row['empleado_delegacion'] ?? '', $row['empleado_codigo'] ?? 0], $rows));
        foreach ($rows as &$row) {
            $key = VeolabDocumentControl::documentKey($row['delegacion'], $row['documento']);
            if (! array_key_exists($key, $documents)) {
                $documents[$key] = $db->table('DOCFAT')->where('DEL3COD', (string) $row['delegacion'])
                    ->where('FAT1COD', (int) $row['documento'])->first(['VER2APR', 'FATCNOM', 'FATCCOD', 'FATCEST']);
                $editions[$key] = $db->table('DOCVER')->where('DEL3COD', (string) $row['delegacion'])
                    ->where('FAT3COD', (int) $row['documento'])->pluck('VERCEDI', 'VER1COD')->all();
            }
            $document = $documents[$key];
            $approved = (int) ($document->VER2APR ?? 0);
            $row['edicion'] = $row['version'] !== null ? ($editions[$key][(int) $row['version']] ?? null) : null;
            $row['version_vigente'] = $approved > 0 ? $approved : null;
            $row['edicion_vigente'] = $approved > 0 ? ($editions[$key][$approved] ?? null) : null;
            $row['empleado_nombre'] = VeolabDocumentControl::employeeName($names, $row['empleado_delegacion'] ?? '', $row['empleado_codigo'] ?? 0);
            $row['retirada'] = ! empty($row['fecha_retirada']) ? 'T' : 'F';
            $row['por_retirar'] = VeolabDocumentControl::isToWithdraw(
                (object) ['VER2COD' => $row['version'], 'COPDRET' => $row['fecha_retirada']], $document) ? 'T' : 'F';
        }

        return $rows;
    }

    /** "nombre.ext - del-copia", como las versiones. */
    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        $document = DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', (string) ($keyParams['delegacion'] ?? ''))->where('FAT1COD', $keyParams['documento'] ?? 0)
            ->first(['FATCNOM', 'FATCTIP']);
        $name = $document ? VeolabDocuments::fullName((string) $document->FATCNOM, $document->FATCTIP) : '';

        return $name.' - '.VeolabCodes::format('', (string) ($keyParams['codigo'] ?? ''), (string) ($keyParams['delegacion'] ?? ''));
    }
}
