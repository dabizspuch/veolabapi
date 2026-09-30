<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autenticación
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| API v2
|--------------------------------------------------------------------------
| Rediseño con claves nombradas en query string. Un único endpoint de
| colección por entidad (GET lista o registro único según la clave),
| POST crea, PUT/DELETE actúan sobre la clave completa en query string.
| Respuestas siempre {data, meta}. Ver docs/v2/CONVENCIONES.md.
*/
Route::prefix('v2')->middleware(['auth:sanctum', \App\Http\Middleware\SetClientDatabase::class])->group(function () {

    $ns = 'App\\Http\\Controllers\\';

    // Recurso CRUD estándar: claves en query string (GET lista o registro único,
    // POST crea, PUT/DELETE actúan sobre la clave completa).
    $resource = function (string $slug, string $controller) use ($ns) {
        Route::get($slug, [$ns.$controller, 'index']);
        Route::post($slug, [$ns.$controller, 'store']);
        Route::put($slug, [$ns.$controller, 'update']);
        Route::delete($slug, [$ns.$controller, 'destroy']);
    };

    // --- Tablas sencillas (2 claves, salvo delegaciones/auditorías que son de 1) ---
    $resource('/clientes', 'ClienteController');
    $resource('/tipos-cliente', 'TipoClienteController');
    $resource('/tipos-evaluacion', 'TipoEvaluacionController');
    $resource('/tipos-operacion', 'TipoOperacionController');
    $resource('/tipos-equipos', 'TipoEquipoController');
    $resource('/formas-envio', 'FormaEnvioController');
    $resource('/secciones', 'SeccionController');
    $resource('/familias', 'FamiliaController');
    $resource('/normativas', 'NormativaController');
    $resource('/matrices', 'MatrizController');
    $resource('/cargos', 'CargoController');
    $resource('/departamentos', 'DepartamentoController');
    $resource('/delegaciones', 'DelegacionController');
    $resource('/perfiles', 'PerfilController');
    $resource('/gastos', 'GastosController');
    $resource('/cursos', 'CursoController');
    $resource('/equipos', 'EquipoController');
    $resource('/usuarios', 'UsuarioController');
    $resource('/tarifas', 'TarifaController');
    $resource('/parametros', 'ParametroController');
    $resource('/empleados', 'EmpleadoController');
    $resource('/productos', 'ProductoController');
    $resource('/proveedores', 'ProveedorController');
    $resource('/servicios', 'ServicioController');

    // --- Tablas con serie (3 claves: delegacion + serie + codigo) ---
    $resource('/operaciones', 'OperacionController');
    $resource('/lotes', 'LoteController');
    $resource('/ordenes', 'OrdenController');
    $resource('/informes', 'InformeController');
    Route::put('/informes/firmas', [$ns.'InformeController', 'sign']);
    // Tipos de firma: se configuran en Veolab (solo lectura).
    Route::get('/tipos-firma', [$ns.'TipoFirmaController', 'index']);

    // --- Planificaciones (delegacion + codigo) y su generación de operaciones ---
    $resource('/planificaciones', 'PlanificacionController');
    Route::post('/planificaciones/generar', [$ns.'OperacionController', 'generateFromPlanning']);
    Route::put('/planificaciones/fechas', [$ns.'PlanificacionController', 'markDate']);

    // Auditoría: solo lectura.
    Route::get('/auditorias', [$ns.'AuditoriaController', 'index']);
    Route::get('/auditorias-archivadas', [$ns.'AuditoriaArchivadaController', 'index']);
});
