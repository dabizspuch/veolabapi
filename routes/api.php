<?php

use App\Http\Controllers\AuditoriaArchivadaController;
use App\Http\Controllers\AuditoriaController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\CargoTareaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClientePuntoController;
use App\Http\Controllers\CursoAlumnoController;
use App\Http\Controllers\CursoController;
use App\Http\Controllers\CursoProfesorController;
use App\Http\Controllers\DelegacionController;
use App\Http\Controllers\DepartamentoController;
use App\Http\Controllers\EmpleadoAusenciaController;
use App\Http\Controllers\EmpleadoCargoController;
use App\Http\Controllers\EmpleadoClienteController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\EmpleadoCurriculumController;
use App\Http\Controllers\EmpleadoFormacionController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\FamiliaController;
use App\Http\Controllers\TipoClienteController;
use App\Http\Controllers\TarifaController;
use App\Http\Controllers\FormaEnvioController;
use App\Http\Controllers\GastosController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\InformeFirmaController;
use App\Http\Controllers\LoteController;
use App\Http\Controllers\MateriaPrimaController;
use App\Http\Controllers\MatrizController;
use App\Http\Controllers\MatrizTipoOperacionController;
use App\Http\Controllers\NormativaController;
use App\Http\Controllers\OperacionController;
use App\Http\Controllers\OperacionResultadoController;
use App\Http\Controllers\OperacionParametroController;
use App\Http\Controllers\OrdenAnalistaController;
use App\Http\Controllers\OrdenController;
use App\Http\Controllers\OrdenOperacionController;
use App\Http\Controllers\ParametroColumnaController;
use App\Http\Controllers\ParametroConsumibleController;
use App\Http\Controllers\ParametroController;
use App\Http\Controllers\ParametroEmpleadoController;
use App\Http\Controllers\ParametroEquipoController;
use App\Http\Controllers\ParametroIntervaloController;
use App\Http\Controllers\ParametroMatrizController;
use App\Http\Controllers\ParametroNormativaController;
use App\Http\Controllers\ParametroPrecioClienteController;
use App\Http\Controllers\ParametroPrecioTarifaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProductoLoteController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ProveedorProductoController;
use App\Http\Controllers\SeccionController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\ServicioGastoController;
use App\Http\Controllers\ServicioParametroController;
use App\Http\Controllers\ServicioPrecioClienteController;
use App\Http\Controllers\ServicioPrecioTarifaController;
use App\Http\Controllers\TipoEquipoController;
use App\Http\Controllers\TipoEvaluacionController;
use App\Http\Controllers\TipoOperacionController;
use App\Http\Controllers\UsuarioController;

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

    // Puntos de muestreo de clientes
    Route::get('/clientes-puntos/{codigo}/{delegacion?}', [ClientePuntoController::class, 'index']);
    Route::get('/clientes-puntos/{codigo}/{delegacion?}/{clave1}', [ClientePuntoController::class, 'show']);
    Route::post('/clientes-puntos', [ClientePuntoController::class, 'store']);
    Route::put('/clientes-puntos/{codigo}/{delegacion?}/{clave1}', [ClientePuntoController::class, 'update']);
    Route::delete('/clientes-puntos/{codigo}/{delegacion?}/{clave1}', [ClientePuntoController::class, 'destroy']);

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

    // Tipos de evaluación
    Route::get('/tipos-evaluacion', [TipoEvaluacionController::class, 'index']);
    Route::get('/tipos-evaluacion/{codigo}/{delegacion?}', [TipoEvaluacionController::class, 'show']);
    Route::post('/tipos-evaluacion', [TipoEvaluacionController::class, 'store']);
    Route::put('/tipos-evaluacion/{codigo}/{delegacion?}', [TipoEvaluacionController::class, 'update']);
    Route::delete('/tipos-evaluacion/{codigo}/{delegacion?}', [TipoEvaluacionController::class, 'destroy']);

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

    // Empleados
    Route::get('/empleados', [EmpleadoController::class, 'index']);
    Route::get('/empleados/{codigo}/{delegacion?}', [EmpleadoController::class, 'show']);
    Route::post('/empleados', [EmpleadoController::class, 'store']);
    Route::put('/empleados/{codigo}/{delegacion?}', [EmpleadoController::class, 'update']);
    Route::delete('/empleados/{codigo}/{delegacion?}', [EmpleadoController::class, 'destroy']);

    // Cargos de empleados
    Route::get('/empleados-cargos/{codigo}/{delegacion?}', [EmpleadoCargoController::class, 'index']);
    Route::get('/empleados-cargos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [EmpleadoCargoController::class, 'show']);
    Route::post('/empleados-cargos', [EmpleadoCargoController::class, 'store']);
    Route::put('empleados-cargos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [EmpleadoCargoController::class, 'update']);
    Route::delete('/empleados-cargos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [EmpleadoCargoController::class, 'destroy']);      

    // Clientes vinculados a empleados
    Route::get('/empleados-clientes/{codigo}/{delegacion?}', [EmpleadoClienteController::class, 'index']);
    Route::get('/empleados-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [EmpleadoClienteController::class, 'show']);
    Route::post('/empleados-clientes', [EmpleadoClienteController::class, 'store']);
    Route::delete('/empleados-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [EmpleadoClienteController::class, 'destroy']);    
    
    // Ausencias de empleados
    Route::get('/empleados-ausencias/{codigo}/{delegacion?}', [EmpleadoAusenciaController::class, 'index']);
    Route::get('/empleados-ausencias/{codigo}/{delegacion?}/{clave1}', [EmpleadoAusenciaController::class, 'show']);
    Route::post('/empleados-ausencias', [EmpleadoAusenciaController::class, 'store']);
    Route::put('/empleados-ausencias/{codigo}/{delegacion?}/{clave1}', [EmpleadoAusenciaController::class, 'update']);
    Route::delete('/empleados-ausencias/{codigo}/{delegacion?}/{clave1}', [EmpleadoAusenciaController::class, 'destroy']);    

    // Currículum de empleados
    Route::get('/empleados-curriculum/{codigo}/{delegacion?}', [EmpleadoCurriculumController::class, 'index']);
    Route::get('/empleados-curriculum/{codigo}/{delegacion?}/{clave1}', [EmpleadoCurriculumController::class, 'show']);
    Route::post('/empleados-curriculum', [EmpleadoCurriculumController::class, 'store']);
    Route::put('/empleados-curriculum/{codigo}/{delegacion?}/{clave1}', [EmpleadoCurriculumController::class, 'update']);
    Route::delete('/empleados-curriculum/{codigo}/{delegacion?}/{clave1}', [EmpleadoCurriculumController::class, 'destroy']);
    
    // Formación de empleados
    Route::get('/empleados-formacion/{codigo}/{delegacion?}', [EmpleadoFormacionController::class, 'index']);
    Route::get('/empleados-formacion/{codigo}/{delegacion?}/{clave1}', [EmpleadoFormacionController::class, 'show']);
    Route::post('/empleados-formacion', [EmpleadoFormacionController::class, 'store']);
    Route::put('/empleados-formacion/{codigo}/{delegacion?}/{clave1}', [EmpleadoFormacionController::class, 'update']);
    Route::delete('/empleados-formacion/{codigo}/{delegacion?}/{clave1}', [EmpleadoFormacionController::class, 'destroy']); 
    
    // Cursos
    Route::get('/cursos', [CursoController::class, 'index']);
    Route::get('/cursos/{codigo}/{delegacion?}', [CursoController::class, 'show']);
    Route::post('/cursos', [CursoController::class, 'store']);
    Route::put('/cursos/{codigo}/{delegacion?}', [CursoController::class, 'update']);
    Route::delete('/cursos/{codigo}/{delegacion?}', [CursoController::class, 'destroy']);    

    // Alumnos de cursos
    Route::get('/cursos-alumnos/{codigo}/{delegacion?}', [CursoAlumnoController::class, 'index']);
    Route::get('/cursos-alumnos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [CursoAlumnoController::class, 'show']);
    Route::post('/cursos-alumnos', [CursoAlumnoController::class, 'store']);
    Route::put('cursos-alumnos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [CursoAlumnoController::class, 'update']);
    Route::delete('/cursos-alumnos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [CursoAlumnoController::class, 'destroy']);   
    
    // Profesores de cursos
    Route::get('/cursos-profesores/{codigo}/{delegacion?}', [CursoProfesorController::class, 'index']);
    Route::get('/cursos-profesores/{codigo}/{delegacion?}/{clave1}/{clave2?}', [CursoProfesorController::class, 'show']);
    Route::post('/cursos-profesores', [CursoProfesorController::class, 'store']);
    Route::delete('/cursos-profesores/{codigo}/{delegacion?}/{clave1}/{clave2?}', [CursoProfesorController::class, 'destroy']);    

    // Cargos
    Route::get('/cargos', [CargoController::class, 'index']);
    Route::get('/cargos/{codigo}/{delegacion?}', [CargoController::class, 'show']);
    Route::post('/cargos', [CargoController::class, 'store']);
    Route::put('/cargos/{codigo}/{delegacion?}', [CargoController::class, 'update']);
    Route::delete('/cargos/{codigo}/{delegacion?}', [CargoController::class, 'destroy']); 

    // Tareas de cargos
    Route::get('/cargos-tareas/{codigo}/{delegacion?}', [CargoTareaController::class, 'index']);
    Route::get('/cargos-tareas/{codigo}/{delegacion?}/{clave1}', [CargoTareaController::class, 'show']);
    Route::post('/cargos-tareas', [CargoTareaController::class, 'store']);
    Route::put('/cargos-tareas/{codigo}/{delegacion?}/{clave1}', [CargoTareaController::class, 'update']);
    Route::delete('/cargos-tareas/{codigo}/{delegacion?}/{clave1}', [CargoTareaController::class, 'destroy']);   
    
    // Departamentos
    Route::get('/departamentos', [DepartamentoController::class, 'index']);
    Route::get('/departamentos/{codigo}/{delegacion?}', [DepartamentoController::class, 'show']);
    Route::post('/departamentos', [DepartamentoController::class, 'store']);
    Route::put('/departamentos/{codigo}/{delegacion?}', [DepartamentoController::class, 'update']);
    Route::delete('/departamentos/{codigo}/{delegacion?}', [DepartamentoController::class, 'destroy']);    
    
    // Usuarios
    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::get('/usuarios/{codigo}/{delegacion?}', [UsuarioController::class, 'show']);
    Route::post('/usuarios', [UsuarioController::class, 'store']);
    Route::put('/usuarios/{codigo}/{delegacion?}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{codigo}/{delegacion?}', [UsuarioController::class, 'destroy']);
    
    // Delegaciones
    Route::get('/delegaciones', [DelegacionController::class, 'index']);
    Route::get('/delegaciones/{codigo}', [DelegacionController::class, 'show']);
    Route::post('/delegaciones', [DelegacionController::class, 'store']);
    Route::put('/delegaciones/{codigo}', [DelegacionController::class, 'update']);
    Route::delete('/delegaciones/{codigo}', [DelegacionController::class, 'destroy']);     

    // Perfiles de usuario
    Route::get('/perfiles', [PerfilController::class, 'index']);
    Route::get('/perfiles/{codigo}/{delegacion?}', [PerfilController::class, 'show']);
    Route::post('/perfiles', [PerfilController::class, 'store']);
    Route::put('/perfiles/{codigo}/{delegacion?}', [PerfilController::class, 'update']);
    Route::delete('/perfiles/{codigo}/{delegacion?}', [PerfilController::class, 'destroy']);    

    // Auditorías
    Route::get('/auditorias', [AuditoriaController::class, 'index']);
    Route::get('/auditorias/{codigo}/{delegacion?}', [AuditoriaController::class, 'show']);
    Route::delete('/auditorias/{codigo}/{delegacion?}', [AuditoriaController::class, 'destroy']);
    
    // Auditorías archivadas
    Route::get('/auditorias-archivadas', [AuditoriaArchivadaController::class, 'index']);
    Route::get('/auditorias-archivadas/{codigo}/{delegacion?}', [AuditoriaArchivadaController::class, 'show']);
    Route::delete('/auditorias-archivadas/{codigo}/{delegacion?}', [AuditoriaArchivadaController::class, 'destroy']);   
    
    // Servicios
    Route::get('/servicios', [ServicioController::class, 'index']);
    Route::get('/servicios/{codigo}/{delegacion?}', [ServicioController::class, 'show']);
    Route::post('/servicios', [ServicioController::class, 'store']);
    Route::put('/servicios/{codigo}/{delegacion?}', [ServicioController::class, 'update']);
    Route::delete('/servicios/{codigo}/{delegacion?}', [ServicioController::class, 'destroy']);  
    
    // Parámetros de servicios
    Route::get('/servicios-parametros/{codigo}/{delegacion?}', [ServicioParametroController::class, 'index']);
    Route::get('/servicios-parametros/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioParametroController::class, 'show']);
    Route::post('/servicios-parametros', [ServicioParametroController::class, 'store']);
    Route::put('servicios-parametros/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioParametroController::class, 'update']);
    Route::delete('/servicios-parametros/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioParametroController::class, 'destroy']);      

    // Gastos de servicios
    Route::get('/servicios-gastos/{codigo}/{delegacion?}', [ServicioGastoController::class, 'index']);
    Route::get('/servicios-gastos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioGastoController::class, 'show']);
    Route::post('/servicios-gastos', [ServicioGastoController::class, 'store']);
    Route::delete('/servicios-gastos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioGastoController::class, 'destroy']);      

    // Precios por cliente de servicios
    Route::get('/servicios-precios-clientes/{codigo}/{delegacion?}', [ServicioPrecioClienteController::class, 'index']);
    Route::get('/servicios-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioPrecioClienteController::class, 'show']);
    Route::post('/servicios-precios-clientes', [ServicioPrecioClienteController::class, 'store']);
    Route::put('servicios-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioPrecioClienteController::class, 'update']);
    Route::delete('/servicios-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioPrecioClienteController::class, 'destroy']);      

    // Precios por tarifa de servicios
    Route::get('/servicios-precios-tarifas/{codigo}/{delegacion?}', [ServicioPrecioTarifaController::class, 'index']);
    Route::get('/servicios-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioPrecioTarifaController::class, 'show']);
    Route::post('/servicios-precios-tarifas', [ServicioPrecioTarifaController::class, 'store']);
    Route::put('servicios-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioPrecioTarifaController::class, 'update']);
    Route::delete('/servicios-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ServicioPrecioTarifaController::class, 'destroy']);     

    // Parámetros
    Route::get('/parametros', [ParametroController::class, 'index']);
    Route::get('/parametros/{codigo}/{delegacion?}', [ParametroController::class, 'show']);
    Route::post('/parametros', [ParametroController::class, 'store']);
    Route::put('/parametros/{codigo}/{delegacion?}', [ParametroController::class, 'update']);
    Route::delete('/parametros/{codigo}/{delegacion?}', [ParametroController::class, 'destroy']);
    
    // Valores de normativas de parámetros
    Route::get('/parametros-normativas/{codigo}/{delegacion?}', [ParametroNormativaController::class, 'index']);
    Route::get('/parametros-normativas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroNormativaController::class, 'show']);
    Route::post('/parametros-normativas', [ParametroNormativaController::class, 'store']);
    Route::put('parametros-normativas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroNormativaController::class, 'update']);
    Route::delete('/parametros-normativas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroNormativaController::class, 'destroy']);

    // Matrices asociadas a parámetros
    Route::get('/parametros-matrices/{codigo}/{delegacion?}', [ParametroMatrizController::class, 'index']);
    Route::get('/parametros-matrices/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroMatrizController::class, 'show']);
    Route::post('/parametros-matrices', [ParametroMatrizController::class, 'store']);
    Route::delete('/parametros-matrices/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroMatrizController::class, 'destroy']);  
    
    // Precios por cliente de parámetros
    Route::get('/parametros-precios-clientes/{codigo}/{delegacion?}', [ParametroPrecioClienteController::class, 'index']);
    Route::get('/parametros-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroPrecioClienteController::class, 'show']);
    Route::post('/parametros-precios-clientes', [ParametroPrecioClienteController::class, 'store']);
    Route::put('parametros-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroPrecioClienteController::class, 'update']);
    Route::delete('/parametros-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroPrecioClienteController::class, 'destroy']);      

    // Precios por tarifa de parámetros
    Route::get('/parametros-precios-tarifas/{codigo}/{delegacion?}', [ParametroPrecioTarifaController::class, 'index']);
    Route::get('/parametros-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroPrecioTarifaController::class, 'show']);
    Route::post('/parametros-precios-tarifas', [ParametroPrecioTarifaController::class, 'store']);
    Route::put('parametros-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroPrecioTarifaController::class, 'update']);
    Route::delete('/parametros-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroPrecioTarifaController::class, 'destroy']);
    
    // Personal cualificado de parámetros
    Route::get('/parametros-empleados/{codigo}/{delegacion?}', [ParametroEmpleadoController::class, 'index']);
    Route::get('/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroEmpleadoController::class, 'show']);
    Route::post('/parametros-empleados', [ParametroEmpleadoController::class, 'store']);
    Route::put('parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroEmpleadoController::class, 'update']);
    Route::delete('/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroEmpleadoController::class, 'destroy']);      

    // Equipos de parámetros
    Route::get('/parametros-equipos/{codigo}/{delegacion?}', [ParametroEquipoController::class, 'index']);
    Route::get('/parametros-equipos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroEquipoController::class, 'show']);
    Route::post('/parametros-equipos', [ParametroEquipoController::class, 'store']);
    Route::put('parametros-equipos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroEquipoController::class, 'update']);
    Route::delete('/parametros-equipos/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroEquipoController::class, 'destroy']);

    // Consumibles de parámetros
    Route::get('/parametros-consumibles/{codigo}/{delegacion?}', [ParametroConsumibleController::class, 'index']);
    Route::get('/parametros-consumibles/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroConsumibleController::class, 'show']);
    Route::post('/parametros-consumibles', [ParametroConsumibleController::class, 'store']);
    Route::put('parametros-consumibles/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroConsumibleController::class, 'update']);
    Route::delete('/parametros-consumibles/{codigo}/{delegacion?}/{clave1}/{clave2?}', [ParametroConsumibleController::class, 'destroy']);         
    
    // Columnas de parámetros
    Route::get('/parametros-columnas/{codigo}/{delegacion?}', [ParametroColumnaController::class, 'index']);
    Route::get('/parametros-columnas/{codigo}/{delegacion?}/{clave1}', [ParametroColumnaController::class, 'show']);
    Route::post('/parametros-columnas', [ParametroColumnaController::class, 'store']);
    Route::put('/parametros-columnas/{codigo}/{delegacion?}/{clave1}', [ParametroColumnaController::class, 'update']);
    Route::delete('/parametros-columnas/{codigo}/{delegacion?}/{clave1}', [ParametroColumnaController::class, 'destroy']); 

    // Intervalos definidos de parámetros
    Route::get('/parametros-intervalos/{codigo}/{delegacion?}/{clave1}', [ParametroIntervaloController::class, 'index']);
    Route::get('/parametros-intervalos/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}', [ParametroIntervaloController::class, 'show']);
    Route::post('/parametros-intervalos', [ParametroIntervaloController::class, 'store']);
    Route::put('parametros-intervalos/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}', [ParametroIntervaloController::class, 'update']);
    Route::delete('/parametros-intervalos/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}', [ParametroIntervaloController::class, 'destroy']);         

    // Secciones
    Route::get('/secciones', [SeccionController::class, 'index']);
    Route::get('/secciones/{codigo}/{delegacion?}', [SeccionController::class, 'show']);
    Route::post('/secciones', [SeccionController::class, 'store']);
    Route::put('/secciones/{codigo}/{delegacion?}', [SeccionController::class, 'update']);
    Route::delete('/secciones/{codigo}/{delegacion?}', [SeccionController::class, 'destroy']);  
    
    // Matrices
    Route::get('/matrices', [MatrizController::class, 'index']);
    Route::get('/matrices/{codigo}/{delegacion?}', [MatrizController::class, 'show']);
    Route::post('/matrices', [MatrizController::class, 'store']);
    Route::put('/matrices/{codigo}/{delegacion?}', [MatrizController::class, 'update']);
    Route::delete('/matrices/{codigo}/{delegacion?}', [MatrizController::class, 'destroy']);    

    // Tipos de operación asociados a matrices
    Route::get('/matrices-tipos-operacion/{codigo}/{delegacion?}', [MatrizTipoOperacionController::class, 'index']);
    Route::get('/matrices-tipos-operacion/{codigo}/{delegacion?}/{clave1}/{clave2?}', [MatrizTipoOperacionController::class, 'show']);
    Route::post('/matrices-tipos-operacion', [MatrizTipoOperacionController::class, 'store']);
    Route::delete('/matrices-tipos-operacion/{codigo}/{delegacion?}/{clave1}/{clave2?}', [MatrizTipoOperacionController::class, 'destroy']);
    
    // Tipos de operación
    Route::get('/tipos-operacion', [TipoOperacionController::class, 'index']);
    Route::get('/tipos-operacion/{codigo}/{delegacion?}', [TipoOperacionController::class, 'show']);
    Route::post('/tipos-operacion', [TipoOperacionController::class, 'store']);
    Route::put('/tipos-operacion/{codigo}/{delegacion?}', [TipoOperacionController::class, 'update']);
    Route::delete('/tipos-operacion/{codigo}/{delegacion?}', [TipoOperacionController::class, 'destroy']);
    
    // Normativas
    Route::get('/normativas', [NormativaController::class, 'index']);
    Route::get('/normativas/{codigo}/{delegacion?}', [NormativaController::class, 'show']);
    Route::post('/normativas', [NormativaController::class, 'store']);
    Route::put('/normativas/{codigo}/{delegacion?}', [NormativaController::class, 'update']);
    Route::delete('/normativas/{codigo}/{delegacion?}', [NormativaController::class, 'destroy']); 
    
    // Gastos adicionales
    Route::get('/gastos', [GastosController::class, 'index']);
    Route::get('/gastos/{codigo}/{delegacion?}', [GastosController::class, 'show']);
    Route::post('/gastos', [GastosController::class, 'store']);
    Route::put('/gastos/{codigo}/{delegacion?}', [GastosController::class, 'update']);
    Route::delete('/gastos/{codigo}/{delegacion?}', [GastosController::class, 'destroy']);    
    
    // Equipos de clientes
    Route::get('/equipos', [EquipoController::class, 'index']);
    Route::get('/equipos/{codigo}/{delegacion?}', [EquipoController::class, 'show']);
    Route::post('/equipos', [EquipoController::class, 'store']);
    Route::put('/equipos/{codigo}/{delegacion?}', [EquipoController::class, 'update']);
    Route::delete('/equipos/{codigo}/{delegacion?}', [EquipoController::class, 'destroy']);
    
    // Tipos de equipos
    Route::get('/tipos-equipos', [TipoEquipoController::class, 'index']);
    Route::get('/tipos-equipos/{codigo}/{delegacion?}', [TipoEquipoController::class, 'show']);
    Route::post('/tipos-equipos', [TipoEquipoController::class, 'store']);
    Route::put('/tipos-equipos/{codigo}/{delegacion?}', [TipoEquipoController::class, 'update']);
    Route::delete('/tipos-equipos/{codigo}/{delegacion?}', [TipoEquipoController::class, 'destroy']);     
});

