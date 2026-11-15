<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabDocumentControl;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Destinatarios de un documento controlado o de una carpeta (DOCDIS, control
 * de documentos fase 2, desde Veolab 3; ISO/IEC 17025:2017 8.3.2 d): a quién
 * va cada documento y, por tanto, quién debe leer su edición vigente.
 *
 *  - Ámbito: el documento (?delegacion=&codigo=) o la carpeta
 *    (?carpeta_delegacion=&carpeta_codigo=). Un documento sin destinatarios
 *    propios hereda los de su carpeta o los de la primera carpeta superior
 *    que los tenga; sin ninguno, no se distribuye (acuse voluntario).
 *  - Tipos (DISCTIP): T todo el personal (de la delegación del documento o,
 *    si es común, de todas), E empleado, C cargo (GRHEYC), D departamento
 *    (quien tiene un cargo del departamento) y A autorizados vigentes
 *    (GRHAUT) en la técnica del documento (DOCFAT.TEC2COD).
 *  - PUT sustituye la lista entera; una lista vacía quita los propios (el
 *    documento vuelve a heredar). Las líneas repetidas se quedan en una.
 *    Con ?usuario_delegacion=&usuario_codigo= exige el privilegio de aprobar
 *    documentos (especial 1 de DOC_CTL); sin usuario la API actúa como
 *    privilegiada.
 *  - Solo con el módulo Calidad y el script de la fase 2 (si no, 422). Los
 *    accesos directos no tienen destinatarios: son los de su destino.
 */
class DocumentoDestinatarioController extends Controller
{
    /** 422 si la base de datos no tiene la fase 2 del control de documentos; null si la tiene. */
    private function unavailable()
    {
        if (! VeolabVersion::isV3()) {
            return response()->json(['message' => VeolabVersion::message(VeolabVersion::V3, 'Los destinatarios de documentos')], 422);
        }
        if (! VeolabDocumentControl::active()) {
            return response()->json(['message' => VeolabDocumentControl::available()
                ? VeolabDocumentControl::INACTIVE_MESSAGE
                : 'El control de documentos requiere el script de conversión que crea DOCLEC y los campos de control'], 422);
        }
        if (! VeolabDocumentControl::distributionAvailable()) {
            return response()->json(['message' => VeolabDocumentControl::DISTRIBUTION_MESSAGE], 422);
        }

        return null;
    }

    /**
     * Ámbito de la petición: ['tipo' => documento|carpeta, 'delegacion',
     * 'codigo', 'fila'] o la respuesta de error (400, 404, 422).
     */
    private function scope(Request $request)
    {
        if ($request->filled('carpeta_codigo')) {
            $del = (string) ($request->query('carpeta_delegacion') ?? '');
            $cod = (int) $request->query('carpeta_codigo');
            $folder = VeolabDocuments::folder($del, $cod);
            if (! $folder) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            if ($folder->DIRCTAB === VeolabDocuments::TEMPLATES) {
                return response()->json(['message' => 'Las plantillas de exportación no se distribuyen'], 422);
            }

            return ['tipo' => 'carpeta', 'delegacion' => $del, 'codigo' => $cod, 'fila' => $folder];
        }
        foreach (['delegacion', 'codigo'] as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}' (o carpeta_delegacion y carpeta_codigo)"], 400);
            }
        }
        $del = (string) ($request->query('delegacion') ?? '');
        $cod = (int) $request->query('codigo');
        $document = VeolabDocumentControl::document($del, $cod);
        if (! $document) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }
        if (VeolabDocuments::isShortcut($document)) {
            return response()->json(['message' => 'Un acceso directo no tiene destinatarios: son los del documento de destino'], 422);
        }

        return ['tipo' => 'documento', 'delegacion' => $del, 'codigo' => $cod, 'fila' => $document];
    }

    /** Líneas propias del ámbito, en su orden. */
    private function ownLines(array $scope): array
    {
        $lines = VeolabDocumentControl::recipientLines();
        $key = VeolabDocumentControl::documentKey($scope['delegacion'], $scope['codigo']);

        return $scope['tipo'] === 'documento' ? ($lines['documentos'][$key] ?? []) : ($lines['carpetas'][$key] ?? []);
    }

    /** Líneas de DOCDIS para la respuesta, con los nombres (una consulta por tabla). */
    private function present(array $lines): array
    {
        $db = DB::connection('dynamic');
        $employees = [];
        $positions = [];
        $departments = [];
        foreach ($lines as $line) {
            if ((int) ($line->EMP2COD ?? 0) !== 0) {
                $employees[] = [$line->EMP2DEL ?? '', $line->EMP2COD];
            }
            if ((int) ($line->CAR2COD ?? 0) !== 0) {
                $positions[(string) ($line->CAR2DEL ?? '')][(int) $line->CAR2COD] = true;
            }
            if ((int) ($line->DEP2COD ?? 0) !== 0) {
                $departments[(string) ($line->DEP2DEL ?? '')][(int) $line->DEP2COD] = true;
            }
        }
        $names = VeolabDocumentControl::employeeNames($employees);
        $positionNames = [];
        foreach ($positions as $del => $codes) {
            foreach ($db->table('GRHCAR')->where('DEL3COD', $del)->whereIn('CAR1COD', array_keys($codes))->get(['CAR1COD', 'CARCNOM']) as $row) {
                $positionNames[VeolabDocumentControl::documentKey($del, $row->CAR1COD)] = (string) $row->CARCNOM;
            }
        }
        $departmentNames = [];
        foreach ($departments as $del => $codes) {
            foreach ($db->table('GRHDEP')->where('DEL3COD', $del)->whereIn('DEP1COD', array_keys($codes))->get(['DEP1COD', 'DEPCNOM']) as $row) {
                $departmentNames[VeolabDocumentControl::documentKey($del, $row->DEP1COD)] = (string) $row->DEPCNOM;
            }
        }

        return array_map(function ($line) use ($names, $positionNames, $departmentNames) {
            $type = strtoupper(trim((string) ($line->DISCTIP ?? '')));
            $employee = $type === 'E' && (int) ($line->EMP2COD ?? 0) !== 0;
            $position = $type === 'C' && (int) ($line->CAR2COD ?? 0) !== 0;
            $department = $type === 'D' && (int) ($line->DEP2COD ?? 0) !== 0;

            return [
                'linea'                   => (int) $line->DIS1COD,
                'tipo'                    => $type !== '' ? $type : null,
                'tipo_nombre'             => VeolabDocumentControl::RECIPIENT_TYPES[$type] ?? null,
                'empleado_delegacion'     => $employee ? (string) $line->EMP2DEL : null,
                'empleado_codigo'         => $employee ? (int) $line->EMP2COD : null,
                'empleado_nombre'         => $employee ? VeolabDocumentControl::employeeName($names, $line->EMP2DEL, $line->EMP2COD) : null,
                'cargo_delegacion'        => $position ? (string) $line->CAR2DEL : null,
                'cargo_codigo'            => $position ? (int) $line->CAR2COD : null,
                'cargo_nombre'            => $position ? ($positionNames[VeolabDocumentControl::documentKey($line->CAR2DEL ?? '', $line->CAR2COD)] ?? null) : null,
                'departamento_delegacion' => $department ? (string) $line->DEP2DEL : null,
                'departamento_codigo'     => $department ? (int) $line->DEP2COD : null,
                'departamento_nombre'     => $department ? ($departmentNames[VeolabDocumentControl::documentKey($line->DEP2DEL ?? '', $line->DEP2COD)] ?? null) : null,
            ];
        }, $lines);
    }

    /** Respuesta del ámbito: líneas propias, las que valen (con la herencia) y, en un documento, las personas. */
    private function payload(array $scope): array
    {
        $own = $this->ownLines($scope);
        $effective = $scope['tipo'] === 'documento'
            ? VeolabDocumentControl::documentRecipientLines($scope['fila'])
            : VeolabDocumentControl::folderRecipientLines($scope['delegacion'], $scope['codigo']);
        $inherited = ! $own && $effective['lineas'];

        $out = [
            'ambito'                     => $scope['tipo'],
            'delegacion'                 => $scope['delegacion'],
            'codigo'                     => $scope['codigo'],
            'destinatarios'              => $this->present($own),
            'heredados'                  => $inherited ? 'T' : 'F',
            'origen_carpeta_delegacion'  => $inherited ? $effective['carpeta'][0] : null,
            'origen_carpeta_codigo'      => $inherited ? $effective['carpeta'][1] : null,
            'destinatarios_efectivos'    => $this->present($effective['lineas']),
        ];
        if ($scope['tipo'] === 'documento') {
            $people = VeolabDocumentControl::resolveRecipients($scope['fila'], $effective['lineas']);
            $out['controlado'] = VeolabDocumentControl::isControlled($scope['fila']) ? 'T' : 'F';
            $out['personas'] = array_values(array_map(fn ($p) => [
                'empleado_delegacion' => $p[0],
                'empleado_codigo'     => $p[1],
                'empleado_nombre'     => $p[2],
            ], $people));
        }

        return $out;
    }

    /**
     * GET /documentos/destinatarios?delegacion=&codigo= (documento) o
     * ?carpeta_delegacion=&carpeta_codigo= (carpeta): destinatarios propios
     * (DOCDIS), los que valen con la herencia (destinatarios_efectivos, con
     * la carpeta de la que vienen) y, en un documento, las personas ya
     * resueltas (en activo, sin repetir, por nombre).
     */
    public function index(Request $request)
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }
        try {
            $scope = $this->scope($request);
            if (! is_array($scope)) {
                return $scope;
            }

            return response()->json(['data' => $this->payload($scope), 'meta' => ['version_bd' => VeolabVersion::current()]]);
        } catch (\Throwable $e) {
            return ServerError::response('v2 index DOCDIS', $e, 'Error al leer los destinatarios');
        }
    }

    /**
     * PUT /documentos/destinatarios?delegacion=&codigo= o
     * ?carpeta_delegacion=&carpeta_codigo= [&usuario_delegacion=&usuario_codigo=]
     * Cuerpo: {destinatarios: [{tipo T|E|C|D|A, empleado_delegacion +
     * empleado_codigo (E), cargo_delegacion + cargo_codigo (C),
     * departamento_delegacion + departamento_codigo (D)}]}: la lista entera.
     * Las líneas se numeran de nuevo (DIS1COD, por delegación).
     */
    public function update(Request $request)
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        try {
            $data = Validator::make($body, [
                'destinatarios'                           => 'present|array|max:200',
                'destinatarios.*.tipo'                    => 'required|string|in:T,E,C,D,A',
                'destinatarios.*.empleado_delegacion'     => 'nullable|string|max:10',
                'destinatarios.*.empleado_codigo'         => 'nullable|integer|min:1',
                'destinatarios.*.cargo_delegacion'        => 'nullable|string|max:10',
                'destinatarios.*.cargo_codigo'            => 'nullable|integer|min:1',
                'destinatarios.*.departamento_delegacion' => 'nullable|string|max:10',
                'destinatarios.*.departamento_codigo'     => 'nullable|integer|min:1',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $e->errors()], 422);
        }

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            $scope = $this->scope($request);
            if (! is_array($scope)) {
                $db->rollBack();

                return $scope;
            }
            VeolabDocumentControl::assertApprover(VeolabDocumentControl::queryUser(), 'cambiar los destinatarios de los documentos');
            if ($scope['tipo'] === 'documento' && (int) $scope['fila']->DIR2COD === 0) {
                throw new BusinessRuleException('El documento está en la papelera');
            }

            // Líneas validadas y sin repetir.
            $lines = [];
            $warning = null;
            foreach ($data['destinatarios'] as $i => $item) {
                $type = strtoupper((string) $item['tipo']);
                $line = ['DISCTIP' => $type, 'EMP2DEL' => '', 'EMP2COD' => 0, 'CAR2DEL' => '', 'CAR2COD' => 0, 'DEP2DEL' => '', 'DEP2COD' => 0];
                $n = $i + 1;
                if ($type === 'E') {
                    if ((int) ($item['empleado_codigo'] ?? 0) <= 0) {
                        throw new BusinessRuleException("Destinatario {$n}: indique el empleado");
                    }
                    $line['EMP2DEL'] = (string) ($item['empleado_delegacion'] ?? '');
                    $line['EMP2COD'] = (int) $item['empleado_codigo'];
                    VeolabDocumentControl::assertEmployee($line['EMP2DEL'], $line['EMP2COD'], "Destinatario {$n}: el empleado");
                } elseif ($type === 'C') {
                    if ((int) ($item['cargo_codigo'] ?? 0) <= 0) {
                        throw new BusinessRuleException("Destinatario {$n}: indique el cargo");
                    }
                    $line['CAR2DEL'] = (string) ($item['cargo_delegacion'] ?? '');
                    $line['CAR2COD'] = (int) $item['cargo_codigo'];
                    if (! $db->table('GRHCAR')->where('DEL3COD', $line['CAR2DEL'])->where('CAR1COD', $line['CAR2COD'])->exists()) {
                        throw new BusinessRuleException("Destinatario {$n}: el cargo no existe");
                    }
                } elseif ($type === 'D') {
                    if ((int) ($item['departamento_codigo'] ?? 0) <= 0) {
                        throw new BusinessRuleException("Destinatario {$n}: indique el departamento");
                    }
                    $line['DEP2DEL'] = (string) ($item['departamento_delegacion'] ?? '');
                    $line['DEP2COD'] = (int) $item['departamento_codigo'];
                    if (! $db->table('GRHDEP')->where('DEL3COD', $line['DEP2DEL'])->where('DEP1COD', $line['DEP2COD'])->exists()) {
                        throw new BusinessRuleException("Destinatario {$n}: el departamento no existe");
                    }
                } elseif ($type === 'A' && $scope['tipo'] === 'documento' && trim((string) ($scope['fila']->TEC2COD ?? '')) === '') {
                    $warning = 'El documento no está vinculado a ningún parámetro: «Autorizados del parámetro» no llega a nadie';
                }
                $lines[implode("\x1B", $line)] = $line;
            }
            $lines = array_values($lines);

            $del = $scope['delegacion'];
            $column = $scope['tipo'] === 'documento' ? 'FAT2COD' : 'DIR2COD';
            $before = $db->table('DOCDIS')->where('DEL3COD', $del)->where($column, $scope['codigo'])->count();
            $db->table('DOCDIS')->where('DEL3COD', $del)->where($column, $scope['codigo'])->delete();
            if ($lines) {
                // Numeración por delegación (la clave es DEL3COD + DIS1COD), con la delegación bloqueada.
                $next = (int) $db->table('DOCDIS')->where('DEL3COD', $del)->lockForUpdate()->max('DIS1COD') + 1;
                $rows = [];
                foreach ($lines as $line) {
                    $rows[] = $line + [
                        'DEL3COD' => $del,
                        'DIS1COD' => $next++,
                        'FAT2COD' => $scope['tipo'] === 'documento' ? $scope['codigo'] : null,
                        'DIR2COD' => $scope['tipo'] === 'carpeta' ? $scope['codigo'] : null,
                    ];
                }
                $db->table('DOCDIS')->insert($rows);
            }

            // Auditoría como un campo de la ficha (DOCFAT o DOCDIR) que cambia.
            $table = $scope['tipo'] === 'documento' ? 'DOCFAT' : 'DOCDIR';
            $name = $scope['tipo'] === 'documento' ? (string) $scope['fila']->FATCNOM : (string) $scope['fila']->DIRCNOM;
            $row = VeolabCodes::format($table, (string) $scope['codigo'], $del, '', '', $name);
            VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $table, $row);
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $table, $row, 'DOCDIS', (string) count($lines), (string) $before);

            $db->commit();
            VeolabDocumentControl::forgetRecipients();

            return response()->json([
                'message' => 'Destinatarios guardados correctamente',
                'aviso'   => $warning,
                'data'    => $this->payload($scope),
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response('v2 update DOCDIS', $e, 'Error al guardar los destinatarios');
        }
    }
}
