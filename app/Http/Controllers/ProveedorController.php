<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\VeolabDocuments;
use App\Support\VeolabVersion;
use Illuminate\Support\Facades\DB;

class ProveedorController extends BaseController
{
    protected string $table = 'SINPRO';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'PRO1COD',
    ];
    protected ?string $inactiveField = 'PROBBAJ';
    protected array $searchFields = ['PROCNOM', 'PROCRAS'];

    protected bool $generatesCode = true;

    protected array $foreignKeys = [
        'tipo_evaluacion' => 'int',
    ];

    /** ISO 17025 6.6, desde Veolab 3: acreditación del subcontratado, requisitos de compra y evaluaciones. */
    protected array $since = [
        'numero_acreditacion'          => VeolabVersion::V3,
        'organismo_acreditacion'       => VeolabVersion::V3,
        'fecha_caducidad_acreditacion' => VeolabVersion::V3,
        'alcance_acreditacion'         => VeolabVersion::V3,
        'requisitos_compra'            => VeolabVersion::V3,
        'fecha_ultima_evaluacion'      => VeolabVersion::V3,
        'fecha_proxima_evaluacion'     => VeolabVersion::V3,
    ];

    protected array $mapping = [
        'delegacion'                   => 'DEL3COD',
        'codigo'                       => 'PRO1COD',
        'nombre'                       => 'PROCNOM',
        'razon_social'                 => 'PROCRAS',
        'direccion'                    => 'PROCDIR',
        'poblacion'                    => 'PROCPOB',
        'provincia'                    => 'PROCPRO',
        'codigo_postal'                => 'PROCCOP',
        'telefono'                     => 'PROCTEL',
        'movil'                        => 'PROCMOV',
        'fax'                          => 'PROCFAX',
        'persona_contacto'             => 'PROCPEC',
        'nif'                          => 'PROCNIF',
        'email'                        => 'PROCEMA',
        'web'                          => 'PROCWEB',
        'fecha_alta'                   => 'PRODALT',
        'fecha_baja'                   => 'PRODBAJ',
        'es_proveedor_aceptado'        => 'PROBACE',
        'productos_suministrados'      => 'PROCSUM',
        'plazo_entrega'                => 'PROCPLE',
        'pedido_minimo'                => 'PROCPMI',
        'observaciones'                => 'PROCOBS',
        'es_laboratorio_subcontratado' => 'PROBLAS',
        'es_baja'                      => 'PROBBAJ',
        'tipo_evaluacion_delegacion'   => 'TIE2DEL',
        'tipo_evaluacion_codigo'       => 'TIE2COD',
        // Veolab 3: acreditación del laboratorio subcontratado y requisitos que se proponen en los pedidos
        'numero_acreditacion'          => 'PROCNAC',
        'organismo_acreditacion'       => 'PROCOAC',
        'fecha_caducidad_acreditacion' => 'PRODCAC',
        'alcance_acreditacion'         => 'PROCALC',
        'requisitos_compra'            => 'PROCREQ',
        // Veolab 3, solo lectura: los lleva la última evaluación (/proveedores/evaluaciones)
        'fecha_ultima_evaluacion'      => 'PRODUEV',
        'fecha_proxima_evaluacion'     => 'PRODPEV',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'                   => 'nullable|string|max:10',
            'codigo'                       => 'nullable|string|max:15',
            'nombre'                       => 'nullable|string|max:255',
            'razon_social'                 => 'nullable|string|max:255',
            'direccion'                    => 'nullable|string|max:255',
            'poblacion'                    => 'nullable|string|max:100',
            'provincia'                    => 'nullable|string|max:100',
            'codigo_postal'                => 'nullable|string|max:10',
            'telefono'                     => 'nullable|string|max:40',
            'movil'                        => 'nullable|string|max:20',
            'fax'                          => 'nullable|string|max:20',
            'persona_contacto'             => 'nullable|string|max:50',
            'nif'                          => 'nullable|string|max:15',
            'email'                        => 'nullable|string|max:100',
            'web'                          => 'nullable|string|max:100',
            'fecha_alta'                   => 'nullable|date',
            'fecha_baja'                   => 'nullable|date',
            'es_proveedor_aceptado'        => 'nullable|string|in:T,F|max:1',
            'productos_suministrados'      => 'nullable|string',
            'plazo_entrega'                => 'nullable|string|max:50',
            'pedido_minimo'                => 'nullable|string|max:50',
            'observaciones'                => 'nullable|string',
            'es_laboratorio_subcontratado' => 'nullable|string|in:T,F|max:1',
            'es_baja'                      => 'nullable|string|in:T,F|max:1',
            'tipo_evaluacion_delegacion'   => 'nullable|string|max:10',
            'tipo_evaluacion_codigo'       => 'nullable|integer',
            'numero_acreditacion'          => 'nullable|string|max:50',
            'organismo_acreditacion'       => 'nullable|string|max:100',
            'fecha_caducidad_acreditacion' => 'nullable|date',
            'alcance_acreditacion'         => 'nullable|string|max:255',
            'requisitos_compra'            => 'nullable|string',
            // Sin regla (solo lectura): fecha_ultima_evaluacion y fecha_proxima_evaluacion.
        ];
    }

    protected function validateRelationships(array $data): void
    {
        if (! empty($data['delegacion'])) {
            $exists = DB::connection('dynamic')->table('ACCDEL')
                ->where('DEL1COD', $data['delegacion'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La delegación no existe');
            }
        }

        if (! empty($data['tipo_evaluacion_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINTIE')
                ->where('DEL3COD', $data['tipo_evaluacion_delegacion'] ?? '')
                ->where('TIE1COD', $data['tipo_evaluacion_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El tipo de evaluación no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('SINPRO')->where('PROCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('PRO1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del proveedor ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('SINPRO')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('PRO1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del proveedor ya está en uso');
            }
        }

        return $data;
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('ALMPYP')
            ->where('PRO3DEL', $delegation)->where('PRO3COD', $code)->delete();

        DB::connection('dynamic')->table('PLAPYP')
            ->where('DEL3PRO', $delegation)->where('PRO3COD', $code)->delete();

        // Veolab 3: evaluaciones del proveedor y el aviso de la próxima (notificación H con sus avisos).
        if (VeolabVersion::isV3()) {
            $db = DB::connection('dynamic');
            $db->table('SINEVA')->where('PRO3DEL', $delegation)->where('PRO3COD', $code)->delete();
            $notifications = $db->table('ACCNOT')->where('NOTCTIP', 'H')
                ->where('PRO2DEL', $delegation)->where('PRO2COD', $code);
            foreach ((clone $notifications)->get(['DEL3COD', 'NOT1COD']) as $notification) {
                $db->table('ACCAVI')->where('AVICTIP', 'N')
                    ->where('NOT2DEL', $notification->DEL3COD)->where('NOT2COD', $notification->NOT1COD)->delete();
            }
            $notifications->delete();
        }

        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('PRO2COD', $code)
            ->update(VeolabDocuments::trashUpdate($delegation));
    }
}
