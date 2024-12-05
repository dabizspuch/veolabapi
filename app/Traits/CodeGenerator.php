<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Exception;

trait CodeGenerator
{
    /**
     * Genera un nuevo código basado en el sistema de claves.
     * Utiliza bloqueo pesimista para evitar colisiones de claves.
     * 
     * @param string $delegation - Código de delegación
     * @param string $series - Serie del registro
     * @param string $table - Nombre de la tabla
     * @return int - Nuevo código generado
     * @throws \Exception - Si ocurre un error durante la transacción
     */
    protected function generateNewCode($delegation, $series,  $table, $lock = false) 
    {
        try {
            // Iniciar la transacción
            if ($lock) DB::beginTransaction();

            // Realizar un bloqueo pesimista para evitar colisiones
            $key = DB::table('ACCCLT')
                ->where('DEL3COD', $delegation)
                ->where('CLTCTAB', $table)
                ->where('CLTCSER', $series);
            if ($lock) $key->lockForUpdate(); // Bloqueo pesimista
            $key = $key->first();

            if (!$key) {
                // Si no existe el registro de clave, debe crearlo con un contador inicial de 1
                DB::table('ACCCLT')->insert([
                    'DEL3COD' => $delegation,
                    'CLTCTAB' => $table,
                    'CLTCSER' => $series,
                    'CLTNVAL' => 1, // Inicializamos el contador en 1
                ]); 
                $newValue = 1;           
            } else {
                // Si el registro existe, incrementar el valor del contador
                $newValue = $key->CLTNVAL + 1;

                // Actualizar el contador en la tabla ACCCLT
                DB::table('ACCCLT')
                    ->where('DEL3COD', $delegation)
                    ->where('CLTCTAB', $table)
                    ->where('CLTCSER', $series)
                    ->update(['CLTNVAL' => $newValue]);
            }
            // Confirmar la transacción
            if ($lock) DB::commit();

            // Devolver el nuevo código
            return $newValue;

        } catch (\Exception $e) {        
            // Si ocurre un error, deshacer la transacción
            if ($lock) DB::rollBack();

            throw new \Exception("Error generando nuevo código: " . $e->getMessage());
        }
    }

}