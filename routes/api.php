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
    // Valores predefinidos de recolección (LABENV, LABLUG, LABTEM): solo en bases de datos de Veolab 3
    $resource('/envases', 'EnvaseController');
    $resource('/lugares', 'LugarController');
    $resource('/temperaturas', 'TemperaturaController');
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
    // Evaluaciones de proveedores (SINEVA, desde Veolab 3): llevan la última al proveedor y avisan de la próxima
    $resource('/proveedores/evaluaciones', 'ProveedorEvaluacionController');

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

    // --- Calidad (módulo CAL, desde Veolab 3): incidencias (CALINC), su estado, acciones (CALACC),
    //     a qué afectan (CALIYE), implicados (CALIEM) y tipos (CALTIN) ---
    $resource('/incidencias', 'IncidenciaController');
    Route::put('/incidencias/estado', [$ns.'IncidenciaController', 'changeState']);
    Route::get('/incidencias/historial', [$ns.'IncidenciaController', 'history']);
    $resource('/incidencias/acciones', 'IncidenciaAccionController');
    Route::get('/incidencias/enlaces', [$ns.'IncidenciaEnlaceController', 'index']);
    Route::post('/incidencias/enlaces', [$ns.'IncidenciaEnlaceController', 'store']);
    Route::delete('/incidencias/enlaces', [$ns.'IncidenciaEnlaceController', 'destroy']);
    Route::post('/incidencias/enlaces/informes', [$ns.'IncidenciaEnlaceController', 'linkReports']);
    Route::get('/incidencias/implicados', [$ns.'IncidenciaImplicadoController', 'index']);
    Route::post('/incidencias/implicados', [$ns.'IncidenciaImplicadoController', 'store']);
    Route::delete('/incidencias/implicados', [$ns.'IncidenciaImplicadoController', 'destroy']);
    $resource('/tipos-incidencia', 'TipoIncidenciaController');

    // --- Ensayos de aptitud (módulo CAL, desde Veolab 3): rondas (CALEAP), sus muestras (CALEAO)
    //     y resultados (CALEAR) con la puntuación y la evaluación (ISO 13528) ---
    $resource('/ensayos-aptitud', 'EnsayoAptitudController');
    Route::post('/ensayos-aptitud/incidencia', [$ns.'EnsayoAptitudController', 'openIncident']);
    $resource('/ensayos-aptitud/muestras', 'EnsayoAptitudMuestraController');
    $resource('/ensayos-aptitud/resultados', 'EnsayoAptitudResultadoController');
    Route::post('/ensayos-aptitud/resultados/traer', [$ns.'EnsayoAptitudResultadoController', 'bring']);
    // Fase 2: familias de ensayos (CALFAE + CALFAT), plan de intercomparaciones y tendencia por parámetro
    $resource('/ensayos-aptitud/familias', 'EnsayoAptitudFamiliaController');
    Route::get('/ensayos-aptitud/plan', [$ns.'EnsayoAptitudFamiliaController', 'plan']);
    Route::get('/ensayos-aptitud/tendencia', [$ns.'EnsayoAptitudResultadoController', 'trend']);

    // --- Auditorías internas y externas (módulo CAL, desde Veolab 3; ISO/IEC 17025 8.8): CALAUI con su equipo
    //     y auditados (CALAUQ), apartados (CALAUX), ensayos presenciados (CALAUV) y delegaciones (CALAUL).
    //     /auditorias es el registro de auditoría de cambios (ACCAUD). ---
    Route::get('/auditorias-calidad/cobertura', [$ns.'AuditoriaCalidadController', 'coverage']);
    // Fase 2: listas de verificación (plantillas CALLVE + CALLVQ) y la lista de cada auditoría (CALAUP)
    Route::post('/auditorias-calidad/listas/duplicar', [$ns.'ListaVerificacionController', 'duplicate']);
    $resource('/auditorias-calidad/listas', 'ListaVerificacionController');
    Route::post('/auditorias-calidad/lista/cargar', [$ns.'AuditoriaListaController', 'load']);
    Route::post('/auditorias-calidad/lista/hallazgo', [$ns.'AuditoriaListaController', 'createFinding']);
    $resource('/auditorias-calidad/lista', 'AuditoriaListaController');
    $resource('/auditorias-calidad', 'AuditoriaCalidadController');

    // --- Revisión por la dirección (módulo CAL, desde Veolab 3; ISO/IEC 17025 8.9): CALRDI con sus asistentes
    //     (CALRDA), temas de entrada a-o (CALRDX) y delegaciones (CALRDL); decisiones = incidencias enlazadas ---
    Route::post('/revisiones-direccion/recoger-datos', [$ns.'RevisionDireccionController', 'collectData']);
    Route::get('/revisiones-direccion/decisiones', [$ns.'RevisionDireccionController', 'decisions']);
    $resource('/revisiones-direccion', 'RevisionDireccionController');

    // --- Objetivos e indicadores de calidad (módulo CAL, desde Veolab 3; ISO/IEC 17025 8.2.1 y 8.9.2 b): indicadores
    //     (CALKPI, automáticos de Veolab o manuales con sus valores CALKPV) y objetivos anuales (CALOBJ) ---
    Route::get('/indicadores-calidad/valor', [$ns.'IndicadorCalidadController', 'value']);
    Route::get('/indicadores-calidad/valores', [$ns.'IndicadorCalidadController', 'values']);
    Route::put('/indicadores-calidad/valores', [$ns.'IndicadorCalidadController', 'putValue']);
    Route::delete('/indicadores-calidad/valores', [$ns.'IndicadorCalidadController', 'deleteValue']);
    $resource('/indicadores-calidad', 'IndicadorCalidadController');
    Route::post('/objetivos-calidad/cerrar', [$ns.'ObjetivoCalidadController', 'close']);
    Route::post('/objetivos-calidad/reabrir', [$ns.'ObjetivoCalidadController', 'reopen']);
    Route::post('/objetivos-calidad/duplicar', [$ns.'ObjetivoCalidadController', 'duplicate']);
    Route::post('/objetivos-calidad/mejora', [$ns.'ObjetivoCalidadController', 'createImprovement']);
    Route::get('/objetivos-calidad/seguimiento', [$ns.'ObjetivoCalidadController', 'tracking']);
    $resource('/objetivos-calidad', 'ObjetivoCalidadController');

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
    // Autorizaciones del personal por técnica (GRHAUT, desde Veolab 3; clave empleado + técnica)
    $resource('/empleados/autorizaciones', 'EmpleadoAutorizacionController');
    $resource('/cursos/alumnos', 'CursoAlumnoController');
    $resource('/cursos/profesores', 'CursoProfesorController');
    $resource('/tipos-operacion/matrices', 'TipoOperacionMatrizController');
    $resource('/proveedores/productos', 'ProveedorProductoController');

    // --- Inventario: series y lotes (ALMSEL), materias primas (ALMMAT) y movimientos (ALMMOV) ---
    // Patrones y materiales de referencia (Veolab 3, ISO/IEC 17025 6.5 y 6.4.9): cadena de trazabilidad de una
    // serie y operaciones afectadas por un equipo no conforme (GET lista, POST crea la incidencia de trabajo no conforme).
    Route::get('/inventario/trazabilidad', [$ns.'InventarioController', 'traceability']);
    Route::get('/inventario/operaciones-afectadas', [$ns.'InventarioController', 'affectedOperations']);
    Route::post('/inventario/operaciones-afectadas', [$ns.'InventarioController', 'createNonconformity']);
    $resource('/inventario', 'InventarioController');
    $resource('/inventario/materias', 'InventarioMateriaController');
    $resource('/inventario/movimientos', 'InventarioMovimientoController');
    // Préstamos (ALMPRE + líneas ALMPYS): movimientos P/D y existencias.
    $resource('/prestamos', 'PrestamoController');
    // Compras (desde Veolab 3, módulo ALM): pedidos a proveedor (ALMPED + ALMLPE) y entregas (ALMALB + ALMLAL),
    // que crean series, movimientos de compra y existencias y llevan lo recibido a los pedidos.
    $resource('/pedidos', 'PedidoController');
    $resource('/entregas', 'EntregaController');

    // --- Residuos (LABRED, módulo GDR); /residuos/registro es el alta masiva por tipo ---
    Route::post('/residuos/registro', [$ns.'ResiduoController', 'register']);
    $resource('/residuos', 'ResiduoController');

    // --- Gestión documental (DOCDIR, DOCFAT, DOCVER, DOCBLO, DOCDYP) ---
    // POST /documentos y POST /documentos/contenido van en multipart/form-data.
    $resource('/documentos', 'DocumentoController');
    Route::get('/documentos/contenido', [$ns.'DocumentoController', 'download']);
    Route::post('/documentos/contenido', [$ns.'DocumentoController', 'upload']);
    // Accesos directos (Veolab 3): los que apuntan a un documento.
    Route::get('/documentos/accesos', [$ns.'DocumentoController', 'shortcuts']);
    Route::get('/documentos/versiones', [$ns.'DocumentoVersionController', 'index']);
    Route::put('/documentos/versiones', [$ns.'DocumentoVersionController', 'update']);
    Route::delete('/documentos/versiones', [$ns.'DocumentoVersionController', 'destroy']);
    // Control de documentos (Veolab 3, ISO 17025 8.3): lista de controlados, aprobar, revisar, descartar y lecturas (DOCLEC).
    Route::get('/documentos/controlados', [$ns.'DocumentoControlController', 'index']);
    Route::post('/documentos/aprobar', [$ns.'DocumentoControlController', 'approve']);
    Route::post('/documentos/revisar', [$ns.'DocumentoControlController', 'review']);
    Route::post('/documentos/descartar', [$ns.'DocumentoControlController', 'discard']);
    Route::get('/documentos/lecturas', [$ns.'DocumentoLecturaController', 'index']);
    Route::post('/documentos/lecturas', [$ns.'DocumentoLecturaController', 'store']);
    // Control de documentos, fase 2: destinatarios (DOCDIS), lecturas pendientes, revisión antes de aprobar y copias (DOCCOP).
    Route::get('/documentos/destinatarios', [$ns.'DocumentoDestinatarioController', 'index']);
    Route::put('/documentos/destinatarios', [$ns.'DocumentoDestinatarioController', 'update']);
    Route::get('/documentos/lecturas/estado', [$ns.'DocumentoLecturaController', 'status']);
    Route::get('/documentos/lecturas/pendientes', [$ns.'DocumentoLecturaController', 'pending']);
    Route::post('/documentos/revisar-edicion', [$ns.'DocumentoControlController', 'reviewEdition']);
    Route::post('/documentos/devolver-edicion', [$ns.'DocumentoControlController', 'returnEdition']);
    $resource('/documentos/copias', 'DocumentoCopiaController');
    $resource('/documentos/carpetas', 'CarpetaDocumentoController');
    Route::get('/documentos/carpetas/perfiles', [$ns.'CarpetaPerfilController', 'index']);
    Route::post('/documentos/carpetas/perfiles', [$ns.'CarpetaPerfilController', 'store']);
    Route::delete('/documentos/carpetas/perfiles', [$ns.'CarpetaPerfilController', 'destroy']);

    // --- Agenda: eventos (AGEAGE + AGEFEC + AGEASI), sus fechas por usuario y catálogos ---
    Route::get('/agenda/fechas', [$ns.'AgendaController', 'dates']);
    Route::post('/periodicidad/fechas', [$ns.'PeriodicidadController', 'dates']);
    $resource('/agenda/estados', 'AgendaEstadoController');
    $resource('/agenda/clasificaciones', 'AgendaClasificacionController');
    $resource('/agenda', 'AgendaController');

    // --- Comunicaciones: notificaciones (ACCNOT), mensajes (MENMEN) y avisos pendientes (ACCAVI) ---
    Route::get('/notificaciones', [$ns.'NotificacionController', 'index']);
    Route::delete('/notificaciones', [$ns.'NotificacionController', 'destroy']);
    Route::get('/mensajes', [$ns.'MensajeController', 'index']);
    Route::post('/mensajes', [$ns.'MensajeController', 'store']);
    Route::get('/mensajes/conversacion', [$ns.'MensajeController', 'conversation']);
    Route::post('/mensajes/leidos', [$ns.'MensajeController', 'markRead']);
    Route::get('/avisos', [$ns.'AvisoController', 'index']);
    Route::delete('/avisos', [$ns.'AvisoController', 'destroy']);
    Route::post('/avisos/vistos', [$ns.'AvisoController', 'markSeen']);
    // Avisos de servicio de SPUCH (cortes del servidor, incidencias): los lee de spuch.com, solo lectura
    Route::get('/avisos-servicio', [$ns.'AvisoServicioController', 'index']);
    // Tareas pendientes (COM_TAR, desde Veolab 3, módulos CAL y COM): calculadas al vuelo, solo lectura
    Route::get('/tareas-pendientes/recuento', [$ns.'TareaPendienteController', 'count']);
    Route::get('/tareas-pendientes', [$ns.'TareaPendienteController', 'index']);

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
