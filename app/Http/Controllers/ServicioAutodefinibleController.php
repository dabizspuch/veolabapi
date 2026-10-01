<?php

namespace App\Http\Controllers;

use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use Illuminate\Support\Facades\DB;

/**
 * Servicios a los que se vincula un autodefinible (LABAYS). Un autodefinible
 * sin servicios se muestra en todas las operaciones; solo filtra lo que se
 * muestra (la API no lo restringe al grabar valores).
 */
class ServicioAutodefinibleController extends RelationController
{
    protected string $table = 'LABAYS';

    protected array $keys = [
        'autodefinible_delegacion' => 'AUT3DEL',
        'autodefinible_codigo'     => 'AUT3COD',
        'servicio_delegacion'      => 'SER3DEL',
        'servicio_codigo'          => 'SER3COD',
    ];

    protected array $mapping = [
        'autodefinible_delegacion' => 'AUT3DEL',
        'autodefinible_codigo'     => 'AUT3COD',
        'servicio_delegacion'      => 'SER3DEL',
        'servicio_codigo'          => 'SER3COD',
    ];

    protected array $entities = [
        'autodefinible' => ['LABAUT', 'AUT1COD', 'int', 'El autodefinible no existe'],
        'servicio'      => ['LABSER', 'SER1COD', 20, 'El servicio no existe'],
    ];

    protected string $auditOwner = 'autodefinible';

    /**
     * Como la configuración de autodefinibles: fila de LABAUT y, por servicio,
     * un suceso de campo LABAUTSER2COD con el autodefinible por su nombre.
     */
    protected function auditCreated(array $data, array $keyParams): void
    {
        $this->auditService($keyParams, $this->serviceCode($keyParams), '');
    }

    protected function auditDeleted(array $before, array $keyParams): void
    {
        $this->auditService($keyParams, '', $this->serviceCode($keyParams));
    }

    private function auditService(array $keyParams, string $new, string $old): void
    {
        $name = (string) DB::connection('dynamic')->table('LABAUT')
            ->where('DEL3COD', (string) $keyParams['autodefinible_delegacion'])
            ->where('AUT1COD', $keyParams['autodefinible_codigo'])
            ->value('AUTCNOM');

        VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, 'LABAUT', $name);
        VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, 'LABAUT', $name, 'LABAUTSER2COD', $new, $old);
    }

    private function serviceCode(array $keyParams): string
    {
        return VeolabCodes::format('LABSER', (string) $keyParams['servicio_codigo'], (string) $keyParams['servicio_delegacion']);
    }
}
