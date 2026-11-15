<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabLicense;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Evaluaciones de un proveedor (SINEVA, desde Veolab 3; ISO/IEC 17025 6.6),
 * como FichaEvaluacionProveedor y ucEvaluaciones. Clave proveedor + codigo
 * (contador ACCCLT 'SINEVA' de la delegación del proveedor, como
 * DBS_Autoincremento).
 *
 * Al crear, cambiar o borrar una evaluación, la última (por fecha y código)
 * se lleva al proveedor (ALM_ActualizarEvaluacionProveedor): fecha de la
 * última y de la próxima evaluación, «aceptado» según el resultado (P sí,
 * N no; sin resultado queda como estaba) y su tipo de evaluación si lo
 * tiene. Y se rehace el aviso de la próxima evaluación
 * (COM_CrearNotificacionesEvaluacion): notificación H para quien hizo la
 * última, si sigue activo, con el módulo COM y LABCON.CONBNEV.
 */
class ProveedorEvaluacionController extends ChildController
{
    protected string $table = 'SINEVA';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'proveedor_delegacion' => 'PRO3DEL',
        'proveedor_codigo'     => 'PRO3COD',
        'codigo'               => 'EVA1COD',
    ];

    protected array $foreignKeys = [
        'evaluador'       => 'string',
        'tipo_evaluacion' => 'int',
    ];

    protected array $mapping = [
        'proveedor_delegacion'       => 'PRO3DEL',
        'proveedor_codigo'           => 'PRO3COD',
        'codigo'                     => 'EVA1COD',
        'fecha'                      => 'EVADFEC',
        'resultado'                  => 'EVACRES',   // P positiva, N negativa, vacío sin resultado
        'descripcion'                => 'EVACDES',
        'fecha_proxima'              => 'EVADPRO',
        'evaluador_delegacion'       => 'USU2DEL',
        'evaluador_codigo'           => 'USU2COD',
        'tipo_evaluacion_delegacion' => 'TIE2DEL',
        'tipo_evaluacion_codigo'     => 'TIE2COD',
    ];

    protected array $searchFields = ['EVACDES'];

    protected string $parentGroup = 'proveedor';
    protected array $parentEntity = ['SINPRO', 'PRO1COD', 15, 'El proveedor no existe'];
    protected string $auditField = 'SINEVA';

    protected function fieldRules(): array
    {
        return [
            'fecha'                      => 'nullable|date',
            'resultado'                  => 'nullable|string|in:P,N',
            'descripcion'                => 'nullable|string',
            'fecha_proxima'              => 'nullable|date',
            'evaluador_delegacion'       => 'nullable|string|max:10',
            'evaluador_codigo'           => 'nullable|string|max:15',
            'tipo_evaluacion_delegacion' => 'nullable|string|max:10',
            'tipo_evaluacion_codigo'     => 'nullable|integer|min:0',
        ];
    }

    protected function validateRelationships(array $data): void
    {
        parent::validateRelationships($data);

        $db = DB::connection('dynamic');
        if (isset($data['evaluador_codigo']) && $data['evaluador_codigo'] !== '') {
            $exists = $db->table('ACCUSU')->where('DEL3COD', (string) ($data['evaluador_delegacion'] ?? ''))
                ->where('USU1COD', $data['evaluador_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El usuario que evalúa no existe');
            }
        }
        if (! empty($data['tipo_evaluacion_codigo'])) {
            $exists = $db->table('SINTIE')->where('DEL3COD', (string) ($data['tipo_evaluacion_delegacion'] ?? ''))
                ->where('TIE1COD', $data['tipo_evaluacion_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El tipo de evaluación no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if (! $keys && empty($data['codigo'])) {
            // DBS_Autoincremento("SINEVA", "", delegación del proveedor), saltando los ocupados.
            $del = (string) ($data['proveedor_delegacion'] ?? '');
            do {
                $code = VeolabCodes::next('SINEVA', '', $del);
            } while (DB::connection('dynamic')->table('SINEVA')->where('PRO3DEL', $del)
                ->where('PRO3COD', (string) $data['proveedor_codigo'])->where('EVA1COD', $code)->exists());
            $data['codigo'] = $code;
        }
        $data = parent::validateAdditionalCriteria($data, $keys);

        foreach (['fecha', 'fecha_proxima'] as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = self::day($data[$param]);
            }
        }
        if (array_key_exists('resultado', $data)) {
            $data['resultado'] = (string) ($data['resultado'] ?? '');
        }
        // FichaEvaluacionProveedor.Grabar (MEN00744): el texto de la evaluación es obligatorio
        if (array_key_exists('descripcion', $data) || ! $keys) {
            $data['descripcion'] = trim((string) ($data['descripcion'] ?? ''));
            if ($data['descripcion'] === '') {
                throw new BusinessRuleException('Escriba el texto de la evaluación (descripcion)');
            }
        }
        if (! $keys) {
            $data['fecha'] ??= date('Y-m-d 00:00:00');
            $data['resultado'] ??= '';
        } elseif (array_key_exists('fecha', $data) && $data['fecha'] === null) {
            throw new BusinessRuleException('La fecha de la evaluación es obligatoria');
        }

        return $data;
    }

    protected function updateAdditionalData(array $data, array $keys): array
    {
        self::updateSupplier((string) ($keys['proveedor_delegacion'] ?? ''), (string) $keys['proveedor_codigo']);

        return $data;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        self::updateSupplier((string) ($keys['proveedor_delegacion'] ?? ''), (string) $keys['proveedor_codigo']);
    }

    /**
     * ALM_ActualizarEvaluacionProveedor: lleva al proveedor su última
     * evaluación y rehace el aviso de la próxima. Sin evaluaciones quedan
     * vacías las fechas y «aceptado» como estaba. Dentro de la transacción.
     */
    public static function updateSupplier(string $del, string $cod): void
    {
        $db = DB::connection('dynamic');
        $last = $db->table('SINEVA')->where('PRO3DEL', $del)->where('PRO3COD', $cod)
            ->orderByDesc('EVADFEC')->orderByDesc('EVA1COD')
            ->first(['EVADFEC', 'EVACRES', 'EVADPRO', 'USU2DEL', 'USU2COD', 'TIE2DEL', 'TIE2COD']);

        $next = null;
        $user = ['', ''];
        if (! $last) {
            $update = ['PRODUEV' => null, 'PRODPEV' => null];
        } else {
            $next = $last->EVADPRO;
            $user = [(string) $last->USU2DEL, (string) $last->USU2COD];
            $update = ['PRODUEV' => $last->EVADFEC, 'PRODPEV' => $last->EVADPRO];
            if ((int) $last->TIE2COD !== 0) {   // su tipo de evaluación pasa a ser el del proveedor
                $update['TIE2DEL'] = (string) $last->TIE2DEL;
                $update['TIE2COD'] = (int) $last->TIE2COD;
            }
            if ($last->EVACRES === 'P') {
                $update['PROBACE'] = 'T';
            } elseif ($last->EVACRES === 'N') {
                $update['PROBACE'] = 'F';
            }
        }
        $db->table('SINPRO')->where('DEL3COD', $del)->where('PRO1COD', $cod)->update($update);

        self::notifyNextEvaluation($del, $cod, $next, $user);
    }

    /**
     * COM_CrearNotificacionesEvaluacion: borra la notificación H del proveedor
     * (y sus avisos) y, si hay próxima evaluación, el módulo COM está activo y
     * LABCON.CONBNEV, la crea para quien hizo la última evaluación si sigue
     * activo (Veolab usa el usuario en sesión si no; la API no tiene sesión y
     * en ese caso no avisa a nadie).
     */
    private static function notifyNextEvaluation(string $del, string $cod, $next, array $user): void
    {
        $db = DB::connection('dynamic');
        $db->delete('DELETE ACCAVI FROM ACCAVI JOIN ACCNOT ON (ACCAVI.NOT2DEL = ACCNOT.DEL3COD AND ACCAVI.NOT2COD = ACCNOT.NOT1COD) '
            .'WHERE ACCAVI.AVICTIP = ? AND ACCNOT.NOTCTIP = ? AND ACCNOT.PRO2DEL = ? AND ACCNOT.PRO2COD = ?', ['N', 'H', $del, $cod]);
        $db->table('ACCNOT')->where('NOTCTIP', 'H')->where('PRO2DEL', $del)->where('PRO2COD', $cod)->delete();

        if ($next === null || $next === '' || $user[1] === '') {
            return;
        }
        if (($db->table('LABCON')->where('CON1COD', 1)->value('CONBNEV') ?? '') !== 'T'
            || ! VeolabLicense::moduleActive('dynamic', $db->getDatabaseName(), 'COM')) {
            return;
        }
        [$userDel, $userCod] = $user;
        $active = $db->table('ACCUSU')->where('DEL3COD', $userDel)->where('USU1COD', $userCod)
            ->where(fn ($q) => $q->whereNull('USUBBAJ')->orWhere('USUBBAJ', '<>', 'T'))
            ->exists();
        if (! $active) {
            return;
        }

        // Texto «proveedor: fecha» (el nombre puede llevar dos puntos: Veolab separa por el último).
        $day = substr((string) $next, 0, 10);
        $name = (string) $db->table('SINPRO')->where('DEL3COD', $del)->where('PRO1COD', $cod)->value('PROCNOM');
        $text = mb_substr($name, 0, 240).': '.(new \DateTime($day))->format('d/m/Y');
        $date = $day.' 00:00:00';

        $notification = VeolabCodes::next('ACCNOT', '', $userDel);
        $db->table('ACCNOT')->insert([
            'DEL3COD' => $userDel, 'NOT1COD' => $notification, 'NOTTFEC' => $date, 'NOTCTIP' => 'H', 'NOTCAVI' => $text,
            'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'PRO2DEL' => $del, 'PRO2COD' => $cod,
        ]);
        $db->table('ACCAVI')->insert([
            'DEL3COD' => $userDel, 'AVI1COD' => VeolabCodes::next('ACCAVI', '', $userDel), 'AVITFEC' => $date,
            'AVICTIP' => 'N', 'USU2DEL' => $userDel, 'USU2COD' => $userCod, 'NOT2DEL' => $userDel, 'NOT2COD' => $notification,
        ]);
    }

    // ------------------------------------------------------------------
    // Auditoría (FichaEvaluacionProveedor / ucEvaluaciones): fila "proveedor - nº"
    // ------------------------------------------------------------------

    protected function auditRow(array $keyParams, ?string $description = null): string
    {
        return VeolabCodes::format('SINPRO', (string) ($keyParams['proveedor_codigo'] ?? ''), (string) ($keyParams['proveedor_delegacion'] ?? ''))
            .' - '.($keyParams['codigo'] ?? '');
    }

    protected function auditCreated(array $data, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::INSERCION, $this->table, $this->auditRow($keyParams));
    }

    /** Como el escritorio: un suceso de modificación (M) del registro. */
    protected function auditUpdated(array $before, array $dbData, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::MODIFICACION, $this->table, $this->auditRow($keyParams));
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        VeolabAudit::record(VeolabAudit::BORRADO, $this->table, $this->auditRow($keyParams));
    }
}
