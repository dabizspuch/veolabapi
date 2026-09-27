<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class ClienteController extends BaseController
{
    protected string $table = 'SINCLI';
    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'codigo'     => 'CLI1COD',
    ];
    protected ?string $inactiveField = 'CLIBBAJ';
    protected array $searchFields = ['CLICNOM', 'CLICRAS'];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = null;

    protected array $mapping = [
        'delegacion'                    => 'DEL3COD',
        'codigo'                        => 'CLI1COD',
        'nombre'                        => 'CLICNOM',
        'razon_social'                  => 'CLICRAS',
        'actividad'                     => 'CLICACT',
        'nif'                           => 'CLICNIF',
        'direccion_1'                   => 'CLICDI1',
        'poblacion_1'                   => 'CLICPO1',
        'provincia_1'                   => 'CLICPR1',
        'codigo_postal_1'               => 'CLICCO1',
        'pais_1'                        => 'CLICPA1',
        'es_facturacion_1'              => 'CLIBDF1',
        'direccion_2'                   => 'CLICDI2',
        'poblacion_2'                   => 'CLICPO2',
        'provincia_2'                   => 'CLICPR2',
        'codigo_postal_2'               => 'CLICCO2',
        'pais_2'                        => 'CLICPA2',
        'es_facturacion_2'              => 'CLIBDF2',
        'direccion_3'                   => 'CLICDI3',
        'poblacion_3'                   => 'CLICPO3',
        'provincia_3'                   => 'CLICPR3',
        'codigo_postal_3'               => 'CLICCO3',
        'pais_3'                        => 'CLICPA3',
        'es_facturacion_3'              => 'CLIBDF3',
        'telefono'                      => 'CLICTEL',
        'movil'                         => 'CLICMOV',
        'fax'                           => 'CLICFAX',
        'persona_contacto'              => 'CLICPEC',
        'email'                         => 'CLICEMA',
        'web'                           => 'CLICWEB',
        'es_contacto_laboratorio'       => 'CLIBLAB',
        'es_contacto_administracion'    => 'CLIBADM',
        'fecha_alta'                    => 'CLIDALT',
        'fecha_baja'                    => 'CLIDBAJ',
        'observaciones'                 => 'CLICOBS',
        'notas_facturacion'             => 'CLICOBF',
        'modo_facturacion'              => 'CLICMDF',
        'forma_pago'                    => 'CLICFOP',
        'numero_cuenta'                 => 'CLICNUC',
        'tipo_persona'                  => 'CLICTIP',
        'residencia'                    => 'CLICRES',
        'tipo_impuesto_1'               => 'CLICTI1',
        'valor_impuesto_1'              => 'CLICII1',
        'tipo_impuesto_2'               => 'CLICTI2',
        'valor_impuesto_2'              => 'CLICII2',
        'descuento'                     => 'CLICDTO',
        'es_baja'                       => 'CLIBBAJ',
        'dias_vencimiento_facturas'     => 'CLINDVF',
        'dias_pago_facturas'            => 'CLINDIP',
        'dias_vencimiento_presupuestos' => 'CLINDVP',
        'cliente_principal_delegacion'  => 'CLI2DEL',
        'cliente_principal_codigo'      => 'CLI2COD',
        'forma_envio_delegacion'        => 'FDE2DEL',
        'forma_envio_codigo'            => 'FDE2COD',
        'tipo_cliente_delegacion'       => 'TIC2DEL',
        'tipo_cliente_codigo'           => 'TIC2COD',
        'telefono_2'                    => 'CLICTE2',
        'movil_2'                       => 'CLICMO2',
        'fax_2'                         => 'CLICFA2',
        'persona_contacto_2'            => 'CLICPE2',
        'email_2'                       => 'CLICEM2',
        'web_2'                         => 'CLICWE2',
        'es_contacto_laboratorio_2'     => 'CLIBLA2',
        'es_contacto_administracion_2'  => 'CLIBAD2',
        'telefono_3'                    => 'CLICTE3',
        'movil_3'                       => 'CLICMO3',
        'fax_3'                         => 'CLICFA3',
        'persona_contacto_3'            => 'CLICPE3',
        'email_3'                       => 'CLICEM3',
        'web_3'                         => 'CLICWE3',
        'es_contacto_laboratorio_3'     => 'CLIBLA3',
        'es_contacto_administracion_3'  => 'CLIBAD3',
        'informacion_adicional'         => 'CLICADI',
        'otros_datos'                   => 'CLICOTD',
        'proyecto'                      => 'CLICPRO',
        'tarifa_delegacion'             => 'TAR2DEL',
        'tarifa_codigo'                 => 'TAR2COD',
        'cliente_igeo'                  => 'CLICIGC',
    ];

    protected function rules(): array
    {
        return [
            'delegacion'                    => 'nullable|string|max:10',
            'codigo'                        => 'nullable|string|max:15',
            'nombre'                        => 'nullable|string|max:255',
            'razon_social'                  => 'nullable|string|max:255',
            'actividad'                     => 'nullable|string|max:100',
            'nif'                           => 'nullable|string|max:15',
            'direccion_1'                   => 'nullable|string|max:255',
            'poblacion_1'                   => 'nullable|string|max:100',
            'provincia_1'                   => 'nullable|string|max:100',
            'codigo_postal_1'               => 'nullable|string|max:10',
            'pais_1'                        => 'nullable|string|max:3',
            'es_facturacion_1'              => 'nullable|string|in:T,F|max:1',
            'direccion_2'                   => 'nullable|string|max:255',
            'poblacion_2'                   => 'nullable|string|max:100',
            'provincia_2'                   => 'nullable|string|max:100',
            'codigo_postal_2'               => 'nullable|string|max:10',
            'pais_2'                        => 'nullable|string|max:3',
            'es_facturacion_2'              => 'nullable|string|in:T,F|max:1',
            'direccion_3'                   => 'nullable|string|max:255',
            'poblacion_3'                   => 'nullable|string|max:100',
            'provincia_3'                   => 'nullable|string|max:100',
            'codigo_postal_3'               => 'nullable|string|max:10',
            'pais_3'                        => 'nullable|string|max:3',
            'es_facturacion_3'              => 'nullable|string|in:T,F|max:1',
            'telefono'                      => 'nullable|string|max:40',
            'movil'                         => 'nullable|string|max:40',
            'fax'                           => 'nullable|string|max:40',
            'persona_contacto'              => 'nullable|string|max:255',
            'email'                         => 'nullable|email|max:255',
            'web'                           => 'nullable|string|max:100',
            'es_contacto_laboratorio'       => 'nullable|string|in:T,F|max:1',
            'es_contacto_administracion'    => 'nullable|string|in:T,F|max:1',
            'fecha_alta'                    => 'nullable|date',
            'fecha_baja'                    => 'nullable|date',
            'observaciones'                 => 'nullable|string',
            'notas_facturacion'             => 'nullable|string',
            'modo_facturacion'              => 'nullable|string|in:C,P,N|max:1',
            'forma_pago'                    => 'nullable|string|max:100',
            'numero_cuenta'                 => 'nullable|string|max:50',
            'tipo_persona'                  => 'nullable|string|in:F,J|max:1',
            'residencia'                    => 'nullable|string|in:E,R,U|max:1',
            'tipo_impuesto_1'               => 'nullable|string|max:10',
            'valor_impuesto_1'              => 'nullable|string|max:10',
            'tipo_impuesto_2'               => 'nullable|string|max:10',
            'valor_impuesto_2'              => 'nullable|string|max:10',
            'descuento'                     => 'nullable|string|max:10',
            'es_baja'                       => 'nullable|string|in:T,F|max:1',
            'dias_vencimiento_facturas'     => 'nullable|integer',
            'dias_pago_facturas'            => 'nullable|integer',
            'dias_vencimiento_presupuestos' => 'nullable|integer',
            'cliente_principal_delegacion'  => 'nullable|string|max:10',
            'cliente_principal_codigo'      => 'nullable|string|max:15',
            'forma_envio_delegacion'        => 'nullable|string|max:10',
            'forma_envio_codigo'            => 'nullable|integer',
            'tipo_cliente_delegacion'       => 'nullable|string|max:10',
            'tipo_cliente_codigo'           => 'nullable|integer',
            'telefono_2'                    => 'nullable|string|max:40',
            'movil_2'                       => 'nullable|string|max:40',
            'fax_2'                         => 'nullable|string|max:40',
            'persona_contacto_2'            => 'nullable|string|max:255',
            'email_2'                       => 'nullable|email|max:255',
            'web_2'                         => 'nullable|string|max:100',
            'es_contacto_laboratorio_2'     => 'nullable|string|in:T,F|max:1',
            'es_contacto_administracion_2'  => 'nullable|string|in:T,F|max:1',
            'telefono_3'                    => 'nullable|string|max:40',
            'movil_3'                       => 'nullable|string|max:40',
            'fax_3'                         => 'nullable|string|max:40',
            'persona_contacto_3'            => 'nullable|string|max:255',
            'email_3'                       => 'nullable|email|max:255',
            'web_3'                         => 'nullable|string|max:100',
            'es_contacto_laboratorio_3'     => 'nullable|string|in:T,F|max:1',
            'es_contacto_administracion_3'  => 'nullable|string|in:T,F|max:1',
            'informacion_adicional'         => 'nullable|string|max:255',
            'otros_datos'                   => 'nullable|string|max:255',
            'proyecto'                      => 'nullable|string|max:255',
            'tarifa_delegacion'             => 'nullable|string|max:10',
            'tarifa_codigo'                 => 'nullable|integer',
            'cliente_igeo'                  => 'nullable|string|max:20',
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

        if (! empty($data['cliente_principal_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', $data['cliente_principal_delegacion'] ?? '')
                ->where('CLI1COD', $data['cliente_principal_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El cliente principal no existe');
            }
        }

        if (! empty($data['tipo_cliente_codigo'])) {
            $exists = DB::connection('dynamic')->table('SINTIC')
                ->where('DEL3COD', $data['tipo_cliente_delegacion'] ?? '')
                ->where('TIC1COD', $data['tipo_cliente_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('El tipo de cliente no existe');
            }
        }

        if (! empty($data['forma_envio_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABFDE')
                ->where('DEL3COD', $data['forma_envio_delegacion'] ?? '')
                ->where('FDE1COD', $data['forma_envio_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La forma de envío no existe');
            }
        }

        if (! empty($data['tarifa_codigo'])) {
            $exists = DB::connection('dynamic')->table('LABTAR')
                ->where('DEL3COD', $data['tarifa_delegacion'] ?? '')
                ->where('TAR1COD', $data['tarifa_codigo'])->exists();
            if (! $exists) {
                throw new BusinessRuleException('La tarifa no existe');
            }
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;
        $delegation = $keys['delegacion'] ?? '';

        // El nombre de cliente no puede estar repetido.
        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('SINCLI')->where('CLICNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where(function ($q) use ($code, $delegation) {
                    $q->where('CLI1COD', '!=', $code)->orWhere('DEL3COD', '!=', $delegation);
                });
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre del cliente ya está en uso');
            }
        }

        // En creación, el código propuesto no puede estar en uso.
        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('SINCLI')
                ->where('DEL3COD', $data['delegacion'] ?? '')
                ->where('CLI1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código del cliente ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        $references = [
            ['SINCLI', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado como cliente principal'],
            ['ACCUSU', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en algún usuario'],
            ['FACFAC', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en alguna factura'],
            ['FACLIF', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en alguna línea de factura'],
            ['FACCON', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en algún contrato'],
            ['FACPRE', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en algún presupuesto'],
            ['LABPLO', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en alguna planificación'],
            ['LABOPE', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en alguna operación'],
            ['LABLOT', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en algún lote'],
            ['LABEQU', 'CLI2DEL', 'CLI2COD', 'está siendo referenciado en algún equipo de cliente'],
        ];

        foreach ($references as [$table, $delCol, $codCol, $reason]) {
            $used = DB::connection('dynamic')->table($table)
                ->where($delCol, $delegation)
                ->where($codCol, $code)->exists();
            if ($used) {
                throw new BusinessRuleException("El cliente no puede ser eliminado porque {$reason}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $delegation = $keys['delegacion'] ?? '';
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('LABPUM')
            ->where('DEL3COD', $delegation)->where('CLI3COD', $code)->delete();

        DB::connection('dynamic')->table('PLAPYC')
            ->where('DEL3CLI', $delegation)->where('CLI3COD', $code)->delete();

        DB::connection('dynamic')->table('GRHCLI')
            ->where('CLI3DEL', $delegation)->where('CLI3COD', $code)->delete();

        DB::connection('dynamic')->table('LABSYC')
            ->where('CLI3DEL', $delegation)->where('CLI3COD', $code)->delete();

        DB::connection('dynamic')->table('LABTYC')
            ->where('CLI3DEL', $delegation)->where('CLI3COD', $code)->delete();

        // Documentos del cliente a la papelera.
        DB::connection('dynamic')->table('DOCFAT')
            ->where('DEL3COD', $delegation)->where('CLI2COD', $code)
            ->update(['DIR2DEL' => $delegation, 'DIR2COD' => 0]);
    }
}
