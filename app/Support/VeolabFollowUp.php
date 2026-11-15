<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento comercial de clientes de Veolab 3 (CLISEG, tipos en CLITSE).
 *
 * Replica Seguimiento.bas para que lo que se hace por la API deje el mismo
 * rastro que en Veolab: envíos, aceptaciones, rechazos y cancelaciones de
 * presupuestos y envíos de facturas. Dar de baja un tipo automático (-1 a -5)
 * desactiva su registro; «Factura enviada» viene de baja.
 *
 * Solo actúa en bases con las tablas, y un fallo aquí nunca impide la
 * operación que lo provoca. Los textos van en español porque la API no
 * tiene idioma de usuario, y el autor queda vacío: el origen del cambio se ve
 * en la auditoría.
 */
class VeolabFollowUp
{
    private const BUDGET_TYPES = ['E' => -1, 'A' => -2, 'R' => -3, 'C' => -4];
    private const BUDGET_STATES = ['E' => 'Enviado', 'A' => 'Aceptado', 'R' => 'Rechazado', 'C' => 'Cancelado'];
    private const INVOICE_TYPE = -5;

    private const ORIGIN_BUDGET = 'P';
    private const ORIGIN_INVOICE = 'F';

    /** Disponibilidad por base de datos (nombre => bool). */
    private static array $available = [];

    /** ¿La base de datos tiene el seguimiento? Una base 3.0 antigua podría no tener aún las tablas. */
    public static function available(): bool
    {
        $database = (string) DB::connection('dynamic')->getDatabaseName();

        return self::$available[$database]
            ??= VeolabVersion::isV3() && Schema::connection('dynamic')->hasTable('CLISEG');
    }

    /** SEG_RegistrarPresupuesto: cambio de estado de un presupuesto (otros estados no se registran). */
    public static function budget(string $delegation, string $series, int $code, string $state): void
    {
        if (! isset(self::BUDGET_TYPES[$state])) {
            return;
        }

        self::safely(function () use ($delegation, $series, $code, $state) {
            $budget = DB::connection('dynamic')->table('FACPRE')
                ->where('DEL3COD', $delegation)->where('PRE1SER', $series)->where('PRE1COD', $code)
                ->first(['CLI2DEL', 'CLI2COD']);

            self::register(self::BUDGET_TYPES[$state], $budget,
                'Presupuesto '.VeolabCodes::format('FACPRE', (string) $code, $delegation, $series).' - '.self::BUDGET_STATES[$state],
                self::ORIGIN_BUDGET, $delegation, $series, $code);
        });
    }

    /** SEG_RegistrarFactura: envío de una factura. */
    public static function invoiceSent(string $delegation, string $series, int $code): void
    {
        self::safely(function () use ($delegation, $series, $code) {
            $invoice = DB::connection('dynamic')->table('FACFAC')
                ->where('DEL3COD', $delegation)->where('FAC1SER', $series)->where('FAC1COD', $code)
                ->first(['CLI2DEL', 'CLI2COD']);

            self::register(self::INVOICE_TYPE, $invoice,
                'Factura '.VeolabCodes::format('FACFAC', (string) $code, $delegation, $series).' - Enviada',
                self::ORIGIN_INVOICE, $delegation, $series, $code);
        });
    }

    /** Clientes.Borrar: los sucesos se eliminan con el cliente. */
    public static function deleteClient(string $delegation, string $code): void
    {
        if (self::available()) {
            DB::connection('dynamic')->table('CLISEG')
                ->where('CLI3DEL', $delegation)->where('CLI3COD', $code)->delete();
        }
    }

    /** Crea el suceso si el documento tiene cliente y su tipo no está de baja. */
    private static function register(int $type, ?object $document, string $text, string $origin, string $delegation, string $series, int $code): void
    {
        if (! $document || (string) $document->CLI2COD === '') {
            return;
        }

        $db = DB::connection('dynamic');
        $followUpType = $db->table('CLITSE')->where('DEL3COD', '')->where('TSE1COD', $type)->first(['TSEBBAJ']);
        if (! $followUpType || $followUpType->TSEBBAJ === 'T') {
            return;
        }

        $clientDelegation = (string) $document->CLI2DEL;
        $db->table('CLISEG')->insert([
            'CLI3DEL' => $clientDelegation,
            'CLI3COD' => (string) $document->CLI2COD,
            'SEG1COD' => VeolabCodes::next('CLISEG', '', $clientDelegation),
            'SEGTFEC' => DB::raw('NOW()'),
            'TSE2DEL' => '',
            'TSE2COD' => $type,
            'SEGCDES' => $text,
            'USU2DEL' => '',
            'USU2COD' => '',
            'SEGCORI' => $origin,
            'SEG2DEL' => $delegation,
            'SEG2SER' => $series,
            'SEG2COD' => $code,
        ]);
    }

    /** Ejecuta el registro sin dejar que un fallo afecte a la operación principal. */
    private static function safely(callable $action): void
    {
        try {
            if (self::available()) {
                $action();
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar el suceso de seguimiento: '.$e->getMessage());
        }
    }
}
