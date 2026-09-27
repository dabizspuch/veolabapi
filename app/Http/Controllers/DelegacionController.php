<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class DelegacionController extends BaseController
{
    protected string $table = 'ACCDEL';
    protected array $keys = [
        'codigo' => 'DEL1COD',
    ];
    protected ?string $inactiveField = 'DELBBAJ';
    protected array $searchFields = ['DELCNOM', 'DELCOBS'];

    // La delegación no cuelga de otra delegación: no hay delegación en su clave.
    protected bool $generatesCode = true;
    protected ?string $delegationKey = null;

    protected array $mapping = [
        'codigo'        => 'DEL1COD',
        'nombre'        => 'DELCNOM',
        'direccion'     => 'DELCDIR',
        'codigo_postal' => 'DELCCOP',
        'provincia'     => 'DELCPRO',
        'poblacion'     => 'DELCPOB',
        'pais'          => 'DELCPAI',
        'telefono'      => 'DELCTEL',
        'movil'         => 'DELCMOV',
        'fax'           => 'DELCFAX',
        'email'         => 'DELCEMA',
        'nif'           => 'DELCNIF',
        'razon'         => 'DELCRAS',
        'tipo_persona'  => 'DELCTIP',
        'residencia'    => 'DELCRES',
        'moneda'        => 'DELCMON',
        'lengua'        => 'DELCLEN',
        'observaciones' => 'DELCOBS',
        'fecha_alta'    => 'DELDALT',
        'fecha_baja'    => 'DELDBAJ',
        'es_baja'       => 'DELBBAJ',
    ];

    protected function rules(): array
    {
        return [
            'codigo'        => 'nullable|string|max:10',
            'nombre'        => 'nullable|string|max:100',
            'direccion'     => 'nullable|string|max:255',
            'codigo_postal' => 'nullable|string|max:10',
            'provincia'     => 'nullable|string|max:100',
            'poblacion'     => 'nullable|string|max:100',
            'pais'          => 'nullable|string|max:3',
            'telefono'      => 'nullable|string|max:20',
            'movil'         => 'nullable|string|max:20',
            'fax'           => 'nullable|string|max:20',
            'email'         => 'nullable|email|max:100',
            'nif'           => 'nullable|string|max:15',
            'razon'         => 'nullable|string|max:255',
            'tipo_persona'  => 'nullable|string|in:F,J|max:1',
            'residencia'    => 'nullable|string|in:E,R,U|max:1',
            'moneda'        => 'nullable|string|max:3',
            'lengua'        => 'nullable|string|max:2',
            'observaciones' => 'nullable|string',
            'fecha_alta'    => 'nullable|date',
            'fecha_baja'    => 'nullable|date',
            'es_baja'       => 'nullable|string|in:T,F|max:1',
        ];
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $isCreating = empty($keys);
        $code = $keys['codigo'] ?? null;

        if (! empty($data['nombre'])) {
            $query = DB::connection('dynamic')->table('ACCDEL')->where('DELCNOM', $data['nombre']);
            if (! $isCreating) {
                $query->where('DEL1COD', '!=', $code);
            }
            if ($query->exists()) {
                throw new BusinessRuleException('El nombre de la delegación ya está en uso');
            }
        }

        if ($isCreating && ! empty($data['codigo'])) {
            $exists = DB::connection('dynamic')->table('ACCDEL')
                ->where('DEL1COD', $data['codigo'])->exists();
            if ($exists) {
                throw new BusinessRuleException('El código de la delegación ya está en uso');
            }
        }

        return $data;
    }

    protected function validateBeforeDelete(array $keys): void
    {
        $code = $keys['codigo'] ?? null;

        $tables = [
            'ACCPER' => 'algún perfil',
            'ACCUSU' => 'algún usuario',
            'DOCFAT' => 'algún archivo',
            'DOCDIR' => 'alguna carpeta',
            'PLAPLA' => 'alguna plantilla',
            'FACFAC' => 'alguna factura',
            'FACCON' => 'algún contrato',
            'FACPRE' => 'algún presupuesto',
            'ALMFAM' => 'alguna familia de inventario',
            'ALMPRD' => 'algún producto',
            'ALMMOV' => 'algún movimiento de inventario',
            'ALMPRE' => 'algún préstamo',
            'SINPRO' => 'algún proveedor',
            'SINCLI' => 'algún cliente',
            'SINTIC' => 'algún tipo de cliente',
            'SINTIE' => 'algún tipo de evaluación',
            'GRHEMP' => 'algún empleado',
            'GRHCAR' => 'algún cargo',
            'GRHPAF' => 'algún plan de formación',
            'GRHDEP' => 'algún departamento',
            'LABPLO' => 'alguna planificación',
            'LABOPE' => 'alguna operación',
            'LABLOT' => 'algún lote',
            'LABORD' => 'alguna orden',
            'LABINF' => 'algún informe',
            'LABDIC' => 'algún dictamen',
            'LABTIF' => 'algún tipo de firma',
            'LABFDE' => 'alguna forma de envío',
            'LABTIO' => 'algún tipo de operación',
            'LABSER' => 'algún servicio',
            'LABMAT' => 'alguna matriz',
            'LABSEC' => 'alguna sección',
            'LABTEC' => 'algún parámetro',
            'LABNOR' => 'alguna normativa',
            'LABESC' => 'algún gasto adicional',
            'LABRED' => 'algún residuo',
            'LABTDR' => 'algún tipo de residuo',
            'LABTEQ' => 'algún tipo de equipo',
            'LABEQU' => 'algún equipo',
            'LABTAR' => 'alguna tarifa',
            'LABRAN' => 'algún rango',
            'LABMAR' => 'alguna marca',
            'LABAUT' => 'algún autodefinible',
        ];

        foreach ($tables as $table => $reference) {
            if (DB::connection('dynamic')->table($table)->where('DEL3COD', $code)->exists()) {
                throw new BusinessRuleException("La delegación no puede ser eliminada porque está siendo referenciada en {$reference}");
            }
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        $code = $keys['codigo'] ?? null;

        DB::connection('dynamic')->table('ACCCLT')->where('DEL3COD', $code)->delete();
        DB::connection('dynamic')->table('ACCAVI')->where('DEL3COD', $code)->delete();
        DB::connection('dynamic')->table('ACCNOT')->where('DEL3COD', $code)->delete();
        DB::connection('dynamic')->table('MENMEN')->where('DEL3COD', $code)->delete();
    }
}
