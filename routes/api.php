<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\FamiliaController;
use App\Http\Controllers\PuntoController;
use App\Http\Controllers\TipoClienteController;
use App\Http\Controllers\TarifaController;
use App\Http\Controllers\FormaEnvioController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\InformeFirmaController;
use App\Http\Controllers\LoteController;
use App\Http\Controllers\MateriaPrimaController;
use App\Http\Controllers\OperacionController;
use App\Http\Controllers\OperacionResultadoController;
use App\Http\Controllers\OperacionParametroController;
use App\Http\Controllers\OrdenAnalistaController;
use App\Http\Controllers\OrdenController;
use App\Http\Controllers\OrdenOperacionController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProductoLoteController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ProveedorProductoController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // Operaciones
    Route::get('/operaciones', [OperacionController::class, 'index']);
    Route::get('/operaciones/{codigo}/{delegacion?}/{clave1?}', [OperacionController::class, 'show']);
    Route::post('/operaciones', [OperacionController::class, 'store']);
    Route::put('/operaciones/{codigo}/{delegacion?}/{clave1?}', [OperacionController::class, 'update']);
    Route::delete('/operaciones/{codigo}/{delegacion?}/{clave1?}', [OperacionController::class, 'destroy']);
    
    // Resultados de operaciones (columnas)
    Route::get('/operaciones-resultados/{codigo}/{delegacion?}/{clave1?}', [OperacionResultadoController::class, 'index']);
    Route::get('/operaciones-resultados/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}', [OperacionResultadoController::class, 'show']);
    Route::put('/operaciones-resultados/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}', [OperacionResultadoController::class, 'update']);

    // Parámetros de operaciones (técnicas)
    Route::get('/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}', [OperacionParametroController::class, 'index']);
    Route::get('/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}', [OperacionParametroController::class, 'show']);
    Route::post('/operaciones-parametros', [OperacionParametroController::class, 'store']);
    Route::put('/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}', [OperacionParametroController::class, 'update']);
    Route::delete('/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}', [OperacionParametroController::class, 'destroy']);

    // Órdenes
    Route::get('/ordenes', [OrdenController::class, 'index']);
    Route::get('/ordenes/{codigo}/{delegacion?}/{clave1?}', [OrdenController::class, 'show']);
    Route::post('/ordenes', [OrdenController::class, 'store']);
    Route::put('/ordenes/{codigo}/{delegacion?}/{clave1?}', [OrdenController::class, 'update']);
    Route::delete('/ordenes/{codigo}/{delegacion?}/{clave1?}', [OrdenController::class, 'destroy']);

    // Operaciones de órdenes
    Route::get('/ordenes-operaciones/{codigo}/{delegacion?}/{clave1?}', [OrdenOperacionController::class, 'index']);
    Route::get('/ordenes-operaciones/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4?}', [OrdenOperacionController::class, 'show']);
    Route::post('/ordenes-operaciones', [OrdenOperacionController::class, 'store']);
    Route::delete('/ordenes-operaciones/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4?}', [OrdenOperacionController::class, 'destroy']);

    // Analistas de órdenes
    Route::get('/ordenes-analistas/{codigo}/{delegacion?}/{clave1?}', [OrdenAnalistaController::class, 'index']);
    Route::get('/ordenes-analistas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}', [OrdenAnalistaController::class, 'show']);
    Route::post('/ordenes-analistas', [OrdenAnalistaController::class, 'store']);
    Route::delete('/ordenes-analistas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}', [OrdenAnalistaController::class, 'destroy']);

    // Lotes
    Route::get('/lotes', [LoteController::class, 'index']);
    Route::get('/lotes/{codigo}/{delegacion?}/{clave1?}', [LoteController::class, 'show']);
    Route::post('/lotes', [LoteController::class, 'store']);
    Route::put('/lotes/{codigo}/{delegacion?}/{clave1?}', [LoteController::class, 'update']);
    Route::delete('/lotes/{codigo}/{delegacion?}/{clave1?}', [LoteController::class, 'destroy']);

    // Informes
    Route::get('/informes', [InformeController::class, 'index']);
    Route::get('/informes/{codigo}/{delegacion?}/{clave1?}', [InformeController::class, 'show']);
    Route::post('/informes', [InformeController::class, 'store']);
    Route::put('/informes/{codigo}/{delegacion?}/{clave1?}', [InformeController::class, 'update']);
    Route::delete('/informes/{codigo}/{delegacion?}/{clave1?}', [InformeController::class, 'destroy']);

    // Firmas de informes
    Route::get('/informes-firmas/{codigo}/{delegacion?}/{clave1?}', [InformeFirmaController::class, 'index']);
    Route::get('/informes-firmas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}/{clave5?}', [InformeFirmaController::class, 'show']);
    Route::post('/informes-firmas', [InformeFirmaController::class, 'store']);
    Route::put('/informes-firmas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}/{clave5?}', [InformeFirmaController::class, 'update']);
    Route::delete('/informes-firmas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}/{clave5?}', [InformeFirmaController::class, 'destroy']);

    // Clientes
    Route::get('/clientes', [ClienteController::class, 'index']);
    Route::get('/clientes/{codigo}/{delegacion?}', [ClienteController::class, 'show']);
    Route::post('/clientes', [ClienteController::class, 'store']);
    Route::put('/clientes/{codigo}/{delegacion?}', [ClienteController::class, 'update']);
    Route::delete('/clientes/{codigo}/{delegacion?}', [ClienteController::class, 'destroy']);

    // Puntos de muestreo
    Route::get('/puntos/{codigo}/{delegacion?}', [PuntoController::class, 'index']);
    Route::get('/puntos/{codigo}/{delegacion?}/{clave1}', [PuntoController::class, 'show']);
    Route::post('/puntos', [PuntoController::class, 'store']);
    Route::put('/puntos/{codigo}/{delegacion?}/{clave1}', [PuntoController::class, 'update']);
    Route::delete('/puntos/{codigo}/{delegacion?}/{clave1}', [PuntoController::class, 'destroy']);

    // Tipos de cliente
    Route::get('/tipos-cliente', [TipoClienteController::class, 'index']);
    Route::get('/tipos-cliente/{codigo}/{delegacion?}', [TipoClienteController::class, 'show']);
    Route::post('/tipos-cliente', [TipoClienteController::class, 'store']);
    Route::put('/tipos-cliente/{codigo}/{delegacion?}', [TipoClienteController::class, 'update']);
    Route::delete('/tipos-cliente/{codigo}/{delegacion?}', [TipoClienteController::class, 'destroy']);

    // Tarifas
    Route::get('/tarifas', [TarifaController::class, 'index']);
    Route::get('/tarifas/{codigo}/{delegacion?}', [TarifaController::class, 'show']);
    Route::post('/tarifas', [TarifaController::class, 'store']);
    Route::put('/tarifas/{codigo}/{delegacion?}', [TarifaController::class, 'update']);
    Route::delete('/tarifas/{codigo}/{delegacion?}', [TarifaController::class, 'destroy']);    

    // Formas de envío
    Route::get('/formas-envio', [FormaEnvioController::class, 'index']);
    Route::get('/formas-envio/{codigo}/{delegacion?}', [FormaEnvioController::class, 'show']);
    Route::post('/formas-envio', [FormaEnvioController::class, 'store']);
    Route::put('/formas-envio/{codigo}/{delegacion?}', [FormaEnvioController::class, 'update']);
    Route::delete('/formas-envio/{codigo}/{delegacion?}', [FormaEnvioController::class, 'destroy']);  

    // Proveedores
    Route::get('/proveedores', [ProveedorController::class, 'index']);
    Route::get('/proveedores/{codigo}/{delegacion?}', [ProveedorController::class, 'show']);
    Route::post('/proveedores', [ProveedorController::class, 'store']);
    Route::put('/proveedores/{codigo}/{delegacion?}', [ProveedorController::class, 'update']);
    Route::delete('/proveedores/{codigo}/{delegacion?}', [ProveedorController::class, 'destroy']);

    // Proveedores y productos (productos suministrados)
    Route::get('/proveedores-productos/{codigo}/{delegacion?}', [ProveedorProductoController::class, 'index']);
    Route::get('/proveedores-productos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ProveedorProductoController::class, 'show']);
    Route::post('/proveedores-productos', [ProveedorProductoController::class, 'store']);
    Route::put('/proveedores-productos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ProveedorProductoController::class, 'update']);
    Route::delete('/proveedores-productos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ProveedorProductoController::class, 'destroy']);   

    // Productos
    Route::get('/productos', [ProductoController::class, 'index']);
    Route::get('/productos/{codigo}/{delegacion?}', [ProductoController::class, 'show']);
    Route::post('/productos', [ProductoController::class, 'store']);
    Route::put('/productos/{codigo}/{delegacion?}', [ProductoController::class, 'update']);
    Route::delete('/productos/{codigo}/{delegacion?}', [ProductoController::class, 'destroy']);

    // Familias
    Route::get('/familias', [FamiliaController::class, 'index']);
    Route::get('/familias/{codigo}/{delegacion?}', [FamiliaController::class, 'show']);
    Route::post('/familias', [FamiliaController::class, 'store']);
    Route::put('/familias/{codigo}/{delegacion?}', [FamiliaController::class, 'update']);
    Route::delete('/familias/{codigo}/{delegacion?}', [FamiliaController::class, 'destroy']);

    // Series o lotes de productos
    Route::get('/productos-lotes', [ProductoLoteController::class, 'index']);
    Route::get('/productos-lotes/{codigo}/{delegacion?}/{clave1}', [ProductoLoteController::class, 'show']);
    Route::post('/productos-lotes', [ProductoLoteController::class, 'store']);
    Route::put('/productos-lotes/{codigo}/{delegacion?}/{clave1}', [ProductoLoteController::class, 'update']);
    Route::delete('/productos-lotes/{codigo}/{delegacion?}/{clave1}', [ProductoLoteController::class, 'destroy']);

    // Materias primas
    Route::get('/materias-primas/{codigo}/{delegacion?}/{clave1}', [MateriaPrimaController::class, 'index']);
    Route::get('/materias-primas/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}/{clave4}', [MateriaPrimaController::class, 'show']);
    Route::post('/materias-primas', [MateriaPrimaController::class, 'store']);
    Route::put('/materias-primas/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}/{clave4}', [MateriaPrimaController::class, 'update']);
    Route::delete('/materias-primas/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}/{clave4}', [MateriaPrimaController::class, 'destroy']);   

});

