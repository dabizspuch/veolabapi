<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autenticación
|--------------------------------------------------------------------------
*/
// Máximo 5 intentos por minuto y nombre de laboratorio + IP (fuerza bruta).
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
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
    $resource('/marcas', 'MarcaController');
    $resource('/rangos', 'RangoController');
    $resource('/dictamenes', 'DictamenController');
    $resource('/opiniones', 'OpinionController');
    $resource('/descripciones', 'DescripcionController');
    $resource('/recolectores', 'RecolectorController');
    $resource('/tipos-residuo', 'TipoResiduoController');
    $resource('/festivos', 'FestivoController');
    $resource('/autodefinibles', 'AutodefinibleController');

    // --- Firma digitalizada del usuario (ACCFIR). POST en multipart/form-data. ---
    Route::get('/usuarios/firma', [$ns.'UsuarioFirmaController', 'show']);
    Route::post('/usuarios/firma', [$ns.'UsuarioFirmaController', 'store']);
    Route::delete('/usuarios/firma', [$ns.'UsuarioFirmaController', 'destroy']);

    // --- Permisos de perfil (ACCPYF) y catálogos de solo lectura (ACCFUN, ACCMOD) ---
    Route::get('/perfiles/permisos', [$ns.'PerfilPermisoController', 'index']);
    Route::put('/perfiles/permisos', [$ns.'PerfilPermisoController', 'update']);
    Route::get('/funcionalidades', [$ns.'FuncionalidadController', 'index']);
    Route::get('/modulos', [$ns.'ModuloController', 'index']);

    // --- Estructura de resultados de una técnica (LABCOT + LABCYR; clave tecnica_* + columna) ---
    $resource('/parametros/columnas', 'ParametroColumnaController');

    // --- Subtablas (clave = la entidad padre + codigo de línea) ---
    $resource('/cargos/tareas', 'CargoTareaController');
    $resource('/empleados/ausencias', 'EmpleadoAusenciaController');
    $resource('/empleados/curriculum', 'EmpleadoCurriculumController');
    $resource('/empleados/formacion', 'EmpleadoFormacionController');
    $resource('/clientes/puntos-muestreo', 'ClientePuntoMuestreoController');

    // --- Tablas con serie (3 claves: delegacion + serie + codigo) ---
    $resource('/operaciones', 'OperacionController');
    // Resultados (LABRES + LABCOR): las técnicas vienen de los servicios de la operación.
    Route::get('/resultados', [$ns.'ResultadoController', 'index']);
    Route::put('/resultados', [$ns.'ResultadoController', 'update']);
    $resource('/lotes', 'LoteController');
    $resource('/ordenes', 'OrdenController');
    $resource('/informes', 'InformeController');
    Route::put('/informes/firmas', [$ns.'InformeController', 'sign']);
    // Tipos de firma: se configuran en Veolab (solo lectura).
    Route::get('/tipos-firma', [$ns.'TipoFirmaController', 'index']);
    $resource('/presupuestos', 'PresupuestoController');
    $resource('/contratos', 'ContratoController');
    // Facturas: la API gestiona borradores y el estado de las emitidas (se emiten en Veolab).
    $resource('/facturas', 'FacturaController');

    // --- Cartas de control (módulo CDC): LABCDC + técnicas (LABCYT) + resultados (LABRCD) ---
    $resource('/cartas-control', 'CartaControlController');

    // --- Planificaciones (delegacion + codigo) y su generación de operaciones ---
    $resource('/planificaciones', 'PlanificacionController');
    Route::post('/planificaciones/generar', [$ns.'OperacionController', 'generateFromPlanning']);
    Route::put('/planificaciones/fechas', [$ns.'PlanificacionController', 'markDate']);

    // --- Relaciones N:N (clave = las dos entidades: {grupo}_delegacion + {grupo}_codigo) ---
    $resource('/servicios/tecnicas', 'ServicioTecnicaController');
    $resource('/servicios/gastos', 'ServicioGastoController');
    $resource('/servicios/precios-cliente', 'ServicioPrecioClienteController');
    $resource('/servicios/precios-tarifa', 'ServicioPrecioTarifaController');
    $resource('/servicios/autodefinibles', 'ServicioAutodefinibleController');
    $resource('/parametros/matrices', 'ParametroMatrizController');
    $resource('/parametros/normativas', 'ParametroNormativaController');
    $resource('/parametros/precios-cliente', 'ParametroPrecioClienteController');
    $resource('/parametros/precios-tarifa', 'ParametroPrecioTarifaController');
    $resource('/parametros/empleados', 'ParametroEmpleadoController');
    $resource('/parametros/equipos', 'ParametroEquipoController');
    $resource('/parametros/consumibles', 'ParametroConsumibleController');
    $resource('/empleados/clientes', 'EmpleadoClienteController');
    $resource('/empleados/cargos', 'EmpleadoCargoController');
    $resource('/cursos/alumnos', 'CursoAlumnoController');
    $resource('/cursos/profesores', 'CursoProfesorController');
    $resource('/tipos-operacion/matrices', 'TipoOperacionMatrizController');
    $resource('/proveedores/productos', 'ProveedorProductoController');

    // --- Inventario: series y lotes (ALMSEL), materias primas (ALMMAT) y movimientos (ALMMOV) ---
    $resource('/inventario', 'InventarioController');
    $resource('/inventario/materias', 'InventarioMateriaController');
    $resource('/inventario/movimientos', 'InventarioMovimientoController');

    // --- Gestión documental (DOCDIR, DOCFAT, DOCVER, DOCBLO, DOCDYP) ---
    // POST /documentos y POST /documentos/contenido van en multipart/form-data.
    $resource('/documentos', 'DocumentoController');
    Route::get('/documentos/contenido', [$ns.'DocumentoController', 'download']);
    Route::post('/documentos/contenido', [$ns.'DocumentoController', 'upload']);
    Route::get('/documentos/versiones', [$ns.'DocumentoVersionController', 'index']);
    Route::put('/documentos/versiones', [$ns.'DocumentoVersionController', 'update']);
    Route::delete('/documentos/versiones', [$ns.'DocumentoVersionController', 'destroy']);
    $resource('/documentos/carpetas', 'CarpetaDocumentoController');
    Route::get('/documentos/carpetas/perfiles', [$ns.'CarpetaPerfilController', 'index']);
    Route::post('/documentos/carpetas/perfiles', [$ns.'CarpetaPerfilController', 'store']);
    Route::delete('/documentos/carpetas/perfiles', [$ns.'CarpetaPerfilController', 'destroy']);

    // --- Configuración de Veolab: solo lectura (se mantiene en las pantallas Configurar...) ---
    Route::get('/configuracion/general', [$ns.'ConfiguracionGeneralController', 'index']);
    Route::get('/configuracion/laboratorio', [$ns.'ConfiguracionLaboratorioController', 'index']);
    Route::get('/configuracion/codigos', [$ns.'ConfiguracionCodigoController', 'index']);
    Route::get('/configuracion/imagen-acreditacion', [$ns.'ConfiguracionImagenController', 'show']);
    Route::get('/series', [$ns.'SerieController', 'index']);

    // Auditoría: solo lectura.
    Route::get('/auditorias', [$ns.'AuditoriaController', 'index']);
    Route::get('/auditorias-archivadas', [$ns.'AuditoriaArchivadaController', 'index']);
});
