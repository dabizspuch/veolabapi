# API REST de Veolab

API REST de **Veolab**, el sistema de gestión de laboratorios (LIMS) de Spuch. Permite que otras
aplicaciones —una aplicación web, un ERP, un portal de clientes, equipos de laboratorio— lean y
escriban los datos de un laboratorio Veolab con las **mismas reglas de negocio que la aplicación de
escritorio**: códigos, estados, precios, resultados, fórmulas, firmas, existencias, auditoría y
Verifactu.

- **Versión:** 2.0 (rutas `/api/v2`).
- **Licencia de Veolab:** exclusiva de la **Edición Empresarial**.
- **URL del servicio de Spuch:** `https://api.veolab.es`
- **Formato:** JSON sobre HTTPS, autenticación con token Bearer.
- **[Manual de referencia](https://www.spuch.com/downloads/API%20REST%20Veolab%20-%20Manual%20de%20referencia.pdf)** (PDF con todos los recursos y campos).

## Índice

- [Novedades de la versión 2](#novedades-de-la-versión-2)
- [Inicio rápido](#inicio-rápido)
- [Autenticación](#autenticación)
- [Convenciones](#convenciones)
  - [Claves y direccionamiento](#claves-y-direccionamiento)
  - [Filtros](#filtros)
  - [Orden y paginación](#orden-y-paginación)
  - [Formato de las respuestas](#formato-de-las-respuestas)
  - [Tipos de datos](#tipos-de-datos)
  - [Errores](#errores)
  - [Auditoría y sesiones](#auditoría-y-sesiones)
- [Recursos](#recursos)
- [Lo que se hace desde Veolab](#lo-que-se-hace-desde-veolab)
- [Ejemplos](#ejemplos)
- [Instalación en un servidor propio](#instalación-en-un-servidor-propio)
- [Documentación](#documentación)
- [Historial de versiones](#historial-de-versiones)
- [Licencia](#licencia)

## Novedades de la versión 2

La versión 2 es una reescritura completa que **sustituye a la 1.0** (archivada en la etiqueta
`v1.0.0`; sus rutas sin versión ya no existen):

- Más de 100 recursos: todo el laboratorio, la facturación, el almacén, el personal, la gestión
  documental, la agenda y las comunicaciones.
- Claves con nombre en la query string, válidas para las claves compuestas de Veolab (de 1 a 7 columnas).
- Respuesta uniforme `{data, meta}`, filtros con operadores, orden por cualquier campo y paginación.
- Reglas de negocio de Veolab: estados y fechas de las operaciones, precios por cliente, tarifa o
  presupuesto, resultados con formato, fórmulas y marcas, firmas de informes, existencias, etc.
- Auditoría como Veolab (cada token es una sesión) y registros encadenados de Verifactu.
- Errores de validación en español (`422`), sin detalles internos.

## Inicio rápido

```bash
# 1. Obtener un token
curl -s -X POST https://api.veolab.es/api/login \
  -H "Content-Type: application/json" \
  -d '{"name": "mi_laboratorio", "password": "********"}'
# → {"token": "12|AbC...", "expires_at": "2026-11-01T10:00:00+00:00"}

# 2. Usarlo
curl -s "https://api.veolab.es/api/v2/operaciones?estado=1,2&sort=-fecha_registro&limit=10" \
  -H "Authorization: Bearer 12|AbC..."
```

La API es exclusiva de la Edición Empresarial de Veolab. El nombre de laboratorio y la contraseña de
la API los facilita Spuch al contratar el servicio
(son distintos de los usuarios de Veolab).

## Autenticación

Las rutas de autenticación no llevan versión:

| Método | Ruta | Uso |
|---|---|---|
| `POST` | `/api/login` | `{"name": "<laboratorio>", "password": "..."}` → `{"token", "expires_at"}`. Credenciales incorrectas: `401`. |
| `POST` | `/api/refresh` | Revoca el token actual y devuelve uno nuevo (requiere Bearer). |
| `POST` | `/api/logout` | Revoca el token actual. |

- Todas las demás peticiones llevan la cabecera `Authorization: Bearer <token>`.
- Los tokens **caducan a los 30 días** (`expires_at`); renuévelos con `/api/refresh` antes de esa fecha
  o vuelva a hacer login. Un token caducado o revocado da `401`.
- El login admite **5 intentos por minuto** por laboratorio e IP (y 20 por IP); al superarlos, `429`
  con la cabecera `Retry-After`.
- **Un token da acceso total a los datos de su laboratorio.** La API no aplica los privilegios de los
  usuarios de Veolab: eso corresponde a la aplicación que la usa, que puede leerlos de
  `/perfiles/permisos`. Guarde el token en el servidor, nunca en el navegador de usuarios no autorizados.

## Convenciones

### Claves y direccionamiento

Cada recurso tiene **una sola ruta** y la clave del registro va **con nombre en la query string**:

```
GET    /api/v2/clientes                                   → listado
GET    /api/v2/clientes?delegacion=&codigo=C001           → un cliente (data con 1 elemento)
POST   /api/v2/clientes                                   → alta (datos en el cuerpo)
PUT    /api/v2/clientes?delegacion=&codigo=C001           → modificación (cambios en el cuerpo)
DELETE /api/v2/clientes?delegacion=&codigo=C001           → baja
```

- Las claves de Veolab son compuestas: `delegacion` + `codigo`, o `delegacion` + `serie` + `codigo`
  (operaciones, informes, facturas...). En las relaciones y subtablas la clave lleva el nombre de cada
  entidad: `servicio_delegacion` + `servicio_codigo` + `tecnica_delegacion` + `tecnica_codigo`.
- **La delegación vacía es un valor válido** (laboratorios sin delegaciones): se envía `delegacion=`
  vacía, o se omite al crear.
- `PUT` y `DELETE` necesitan la clave completa (`400` si falta una parte); el `GET` con la clave
  completa devuelve un elemento, o `data` vacío si no existe.
- Al crear, si no se indica el código, **la API lo genera** con los contadores y el formato de códigos
  de Veolab, y lo devuelve en la respuesta.

### Filtros

Se puede filtrar por cualquier campo del recurso:

| Filtro | Ejemplo |
|---|---|
| Igual | `?estado=2` |
| Uno de varios | `?estado=1,2,3` |
| Mayor o igual / menor o igual | `?fecha_registro[gte]=2026-01-01&fecha_registro[lte]=2026-01-31` |
| Mayor / menor | `?precio[gt]=10&precio[lt]=50` |
| Distinto | `?estado[ne]=7` |
| Contiene | `?descripcion[like]=agua` |
| Vacío / no vacío | `?fecha_baja[null]=T` · `?cliente_codigo[null]=F` |
| Búsqueda de texto | `?search=agua` (en los campos de texto principales del recurso) |
| Dados de baja | `?is_deleted=F` (solo vigentes) · `?is_deleted=T`, en los recursos con baja o anulación (también se puede filtrar por `es_baja`) |

Los campos desconocidos se ignoran. Un valor imposible para el campo (una fecha mal escrita) da `422`.
En campos con fecha y hora, `[lte]=2026-01-31` llega hasta las 00:00 de ese día: use
`[lt]=2026-02-01` para incluirlo entero.

### Orden y paginación

- `?sort=campo` o `?sort=-campo` (descendente); varios separados por comas: `?sort=estado,-codigo`.
  Siempre se desempata por la clave, así que el orden es estable.
- `?page=1&limit=25`: página y tamaño (por defecto 25, máximo 100).

### Formato de las respuestas

Las lecturas devuelven siempre:

```json
{
  "data": [ { "delegacion": "", "serie": "26", "codigo": 143, "estado": 2, "...": "..." } ],
  "meta": { "total": 342, "page": 1, "per_page": 25, "last_page": 14 }
}
```

| Petición | Respuesta |
|---|---|
| `POST` | `201` `{"message": "...", "data": { <clave del registro creado> }}` |
| `PUT`, `DELETE` | `200` `{"message": "..."}` |

Muchos recursos añaden datos relacionados a la lectura (las `lineas` de un presupuesto, las
`fechas` de una planificación, las `columnas` de un resultado, los `autodefinibles` de una
operación...) y los aceptan en la escritura con la misma forma.

### Tipos de datos

- **Booleanos:** `"T"` / `"F"`, como Veolab.
- **Fechas:** `"aaaa-mm-dd"` o `"aaaa-mm-dd hh:mm:ss"`.
- **Referencias vacías:** una referencia a otra ficha sin valor sale como `null` en todos sus campos
  (`cliente_delegacion`, `cliente_codigo`); para quitarla, envíe `null`.
- **Códigos:** numéricos o de texto según la tabla (clientes, productos, usuarios, lotes... son de
  texto y se ordenan alfabéticamente, como en Veolab).
- **Separador decimal:** los textos con números (descuentos como `"10,5%"`, resultados, rangos) se
  aceptan con coma o punto y se guardan con el separador decimal del laboratorio, el mismo que usa
  Veolab en sus equipos. Los importes numéricos son números JSON.

### Errores

| Código | Significado |
|---|---|
| `400` | Falta una parte de la clave. |
| `401` | Sin token, o token caducado o revocado. |
| `404` | Ruta o registro inexistente (en `PUT`/`DELETE`). |
| `405` | Método no admitido en esa ruta. |
| `413` | Fichero demasiado grande. |
| `422` | Datos no válidos o regla de negocio incumplida. |
| `429` | Demasiados intentos de login. |
| `500` | Error interno (el detalle queda en el registro del servidor). |

Los errores llevan siempre `message` en español; los de validación, además, `errors` por campo:

```json
{ "message": "Datos no válidos", "errors": { "estado": ["El campo estado debe ser un número entero."] } }
{ "message": "La operación está en un informe validado y no puede modificarse" }
```

### Auditoría y sesiones

- Los cambios se auditan como en Veolab, según el nivel de auditoría del laboratorio: altas, bajas,
  modificaciones de fila o de cada campo con su valor anterior.
- Cada token aparece en Veolab como una sesión `API REST v2 (token N)`. Con la cabecera opcional
  `X-Veolab-Sesion: <texto>` (por ejemplo, el usuario de su aplicación) la sesión pasa a ser
  `API REST v2 (token N) - <texto>`, para saber en Veolab quién hizo cada cambio.
- Con Verifactu o con la auditoría de facturación activada, presupuestos, contratos y borradores de
  factura dejan además registros encadenados con hash.

## Recursos

Todas las rutas cuelgan de `https://api.veolab.es/api/v2`. `G` = `GET`, `P` = `POST`, `U` = `PUT`,
`D` = `DELETE`. El [manual de referencia](#documentación) detalla los campos de cada recurso.

### Clientes y proveedores

Fichas de clientes con sus puntos de muestreo, proveedores y los catálogos que los clasifican.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/clientes` | G P U D | `SINCLI` | Clientes del laboratorio (datos fiscales, direcciones, facturación, contacto). |
| `/clientes/puntos-muestreo` | G P U D | `LABPUM` | Puntos de muestreo de un cliente, en un árbol de un nivel (categorías y puntos). |
| `/tipos-cliente` | G P U D | `SINTIC` | Tipos de cliente. |
| `/proveedores` | G P U D | `SINPRO` | Proveedores. |
| `/proveedores/productos` | G P U D | `ALMPYP` | Productos que suministra cada proveedor, con su referencia y precio. |
| `/tipos-evaluacion` | G P U D | `SINTIE` | Tipos de evaluación. |
| `/formas-envio` | G P U D | `LABFDE` | Formas de envío de informes. |

- Un punto de muestreo cuelga de la raíz o de una categoría del mismo cliente. Un punto usado en operaciones, planificaciones o facturas no se borra (se da de baja); borrar una categoría borra sus puntos.
- Los códigos de cliente y proveedor son de texto y se ordenan alfabéticamente, como en Veolab.

### Catálogo de análisis

Servicios, parámetros (técnicas) y todo lo que define cómo se analiza, se valora y se cobra.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/servicios` | G P U D | `LABSER` | Servicios: conjuntos de técnicas que se contratan juntas. |
| `/servicios/tecnicas` | G P U D | `LABSYT` | Técnicas de un servicio, en orden. |
| `/servicios/gastos` | G P U D | `LABSYE` | Gastos (escandallo) de un servicio. |
| `/servicios/precios-cliente` | G P U D | `LABSYC` | Precio y descuento de un servicio para un cliente. |
| `/servicios/precios-tarifa` | G P U D | `LABSYF` | Precio y descuento de un servicio en una tarifa. |
| `/servicios/autodefinibles` | G P U D | `LABAYS` | Campos autodefinibles que se muestran en las operaciones de un servicio. |
| `/parametros` | G P U D | `LABTEC` | Parámetros o técnicas de análisis (unidades, metodología, límites, precio...). |
| `/parametros/columnas` | G P U D | `LABCOT` | Estructura de resultados de una técnica: columnas, formato, fórmulas e intervalos por rango. |
| `/parametros/matrices` | G P U D | `LABTYM` | Matrices en las que se analiza una técnica. |
| `/parametros/normativas` | G P U D | `LABTYN` | Valor y rango de una técnica en una normativa. |
| `/parametros/precios-cliente` | G P U D | `LABTYC` | Precio de una técnica para un cliente. |
| `/parametros/precios-tarifa` | G P U D | `LABTYF` | Precio de una técnica en una tarifa. |
| `/parametros/empleados` | G P U D | `LABTYE` | Personal cualificado para una técnica, en orden. |
| `/parametros/equipos` | G P U D | `LABTYQ` | Equipos de una técnica, con el formato de importación de resultados. |
| `/parametros/consumibles` | G P U D | `LABTYP` | Consumibles que gasta una técnica y en qué cantidad. |
| `/gastos` | G P U D | `LABESC` | Gastos y suplidos que se repercuten en servicios y facturas. |
| `/tarifas` | G P U D | `LABTAR` | Tarifas de precios. |
| `/matrices` | G P U D | `LABMAT` | Matrices (tipos de muestra). |
| `/normativas` | G P U D | `LABNOR` | Normativas de referencia. |
| `/secciones` | G P U D | `LABSEC` | Secciones del laboratorio. |
| `/tipos-operacion` | G P U D | `LABTIO` | Tipos de operación. |
| `/tipos-operacion/matrices` | G P U D | `LABOYM` | Matrices de un tipo de operación. |
| `/marcas` | G P U D | `LABMAR` | Marcas de resultados (incluidas la predeterminada y la de "no evaluable"). |
| `/rangos` | G P U D | `LABRAN` | Rangos de resultados: intervalos con nombre que concreta cada columna de técnica. |
| `/dictamenes` | G P U D | `LABDIC` | Dictámenes de operación y la marca que los provoca. |
| `/opiniones` | G P U D | `LABOEI` | Opiniones e interpretaciones para los informes, con la propuesta automática. |
| `/descripciones` | G P U D | `LABDES` | Descripciones predefinidas de operaciones. |
| `/recolectores` | G P U D | `LABREC` | Recolectores de muestras. |
| `/autodefinibles` | G P U D | `LABAUT` | Definición de los campos autodefinibles de operaciones y lotes. |

- Las relaciones (técnicas de un servicio, precios, matrices...) se direccionan con las dos entidades: `{grupo}_delegacion` + `{grupo}_codigo` de cada una. Con una sola entidad, el GET lista sus relaciones.
- `posicion` es el orden en la ficha; sin indicarla, al final. Las técnicas de un servicio empiezan en la 2.
- Descuentos y precios en texto (`"10%"`, `"15"`) se aceptan con coma o punto y se guardan con el separador decimal del laboratorio.
- Columnas de resultados: se añaden al final y solo se borra la última; las demás se desactivan. `rangos` sustituye los intervalos de la columna.
- Un autodefinible se identifica por su nombre (empieza por letra, sin símbolos, no repetido). Con valores en alguna operación o lote no se borra.
- Marcas, dictámenes y tipos usados por otros registros no se borran (`422`): se dan de baja.

### Operaciones y resultados

El trabajo diario del laboratorio: operaciones (muestras), sus resultados, lotes, órdenes de trabajo, planificaciones y cartas de control.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/operaciones` | G P U D | `LABOPE` | Operaciones (muestras): datos generales, estado, servicios al crear y autodefinibles. |
| `/resultados` | G U | `LABRES` | Resultados: técnicas de cada operación con sus columnas. Lectura y grabación (PUT). Técnicas de las operaciones con sus `columnas` (valor, tipo, formato, fórmula, marca...). `?operacion_*` (cuerpo `{tecnicas: [...], usuario_*...}`) o además `&tecnica_*` (cuerpo de la técnica). |
| `/lotes` | G P U D | `LABLOT` | Lotes de operaciones, con sus autodefinibles de lote. |
| `/ordenes` | G P U D | `LABORD` | Órdenes de trabajo con sus operaciones y su personal. |
| `/planificaciones` | G P U D | `LABPLO` | Planificaciones: operaciones previstas, con fechas únicas o periódicas. |
| `/planificaciones/generar` | P | `LABOPE` | Genera una operación a partir de una planificación. Cuerpo `{delegacion, codigo, fecha, operacion: {...}}`: copia la planificación en una operación nueva. |
| `/planificaciones/fechas` | U | `LABPLO` | Marca una fecha planificada como generada o pendiente. `?delegacion=&codigo=&fecha=`; cuerpo `{completada: T|F}`. |
| `/cartas-control` | G P U D | `LABCDC` | Cartas de control de calidad (módulo CDC): técnicas, resultados, límites e incidencias. |

- Estados de una operación: 0 registrada, 1 recibida, 2 preparada, 3 iniciada, 4 finalizada, 5 validada, 6 enviada, 7 archivada. Al avanzar se rellenan las fechas vacías hasta el nuevo estado; al retroceder se borran las posteriores, como la barra de estados de Veolab.
- Al crear una operación, `servicios` genera sus técnicas, columnas de resultado, gastos, analistas y consumos de almacén con los precios del cliente, la tarifa o el presupuesto. Después, los servicios se cambian en Veolab.
- Los campos autodefinibles van como `"autodefinibles": {"Nombre": valor}`, por nombre.
- Una operación en un informe validado o firmado no se modifica.
- Resultados: `PUT /resultados` con los valores por letra de columna. La API aplica el formato de la columna, calcula las fórmulas, asigna las marcas por rangos y actualiza fechas, estado y dictamen como la ficha de resultados de Veolab.
- Los números de los resultados se aceptan como número JSON o como texto con coma o punto, y se guardan con el separador decimal del laboratorio.
- Planificaciones: sin fecha, fecha única o periódica (`repeticion`). Con aviso, la API crea el evento de agenda para los usuarios con acceso a planificaciones.

### Informes y firmas

Informes de resultados con sus operaciones, sus firmas y su estado de validación.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/informes` | G P U D | `LABINF` | Informes con sus operaciones (y operaciones históricas) y sus firmas. |
| `/informes/firmas` | U | `LABINF` | Firma, rechazo o eliminación de una firma de un informe. `?delegacion=&serie=&codigo=` del informe; cuerpo `{accion: firmar|rechazar|eliminar, tipo_firma_*, usuario_*, departamento_*, comentario}`. |
| `/tipos-firma` | G | `LABTIF` | Tipos de firma configurados en Veolab (solo lectura). |

- Un informe final traslada sus fechas y su estado a las operaciones (finalizada, validada, enviada).
- El estado de validación (P pendiente, V validado, R rechazado) lo deciden las firmas. La API no tiene usuario: quien firma se indica en la petición y sus privilegios los comprueba la aplicación cliente.
- Las firmas se aplican en orden; un informe firmado no admite cambios en sus operaciones ni en sus datos principales.
- El documento PDF del informe y su exportación se generan en Veolab.

### Facturación

Presupuestos, contratos y facturas con sus líneas e importes calculados.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/presupuestos` | G P U D | `FACPRE` | Presupuestos con sus líneas e importes. |
| `/contratos` | G P U D | `FACCON` | Contratos con sus líneas. |
| `/facturas` | G P U D | `FACFAC` | Facturas: borradores (alta, cambio y borrado) y estado de las emitidas. |

- Las líneas se indican como `lineas` (la rejilla completa) o `servicios` (cada servicio con sus técnicas y gastos, con los precios del cliente o la tarifa). En un PUT sustituyen la rejilla entera.
- Subtotal, base imponible, impuestos, suplidos y total se calculan siempre.
- **La API no emite facturas.** Las definitivas, rectificativas y el envío a la AEAT (Verifactu) se hacen en Veolab. Desde la API: borradores (si el laboratorio los tiene activados) y, en las emitidas, solo enviada, cobrada, contabilizada, pendiente, vencimiento, fecha de pago y notas.
- Un borrador puede generarse desde operaciones (como la facturación de Veolab) o convertir un presupuesto o contrato.
- Con Verifactu, presupuestos y contratos no se borran y cada cambio deja un registro encadenado con hash.

### Almacén

Productos, sus series o lotes reales, movimientos, materias primas y préstamos de material.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/productos` | G P U D | `ALMPRD` | Catálogo de productos (equipos y consumibles), con sus existencias. |
| `/familias` | G P U D | `ALMFAM` | Familias de productos (árbol). |
| `/inventario` | G P U D | `ALMSEL` | Series y lotes de cada producto: los elementos reales del almacén. |
| `/inventario/materias` | G P U D | `ALMMAT` | Materias primas consumidas para fabricar una serie o lote. |
| `/inventario/movimientos` | G P U D | `ALMMOV` | Movimientos de almacén (historial de cada serie o lote). |
| `/prestamos` | G P U D | `ALMPRE` | Préstamos de material (módulo ALM) con sus líneas. |
| `/equipos` | G P U D | `LABEQU` | Equipos de los clientes que se muestrean (p. ej. transformadores). |
| `/tipos-equipos` | G P U D | `LABTEQ` | Tipos de equipo (árbol). |

- Las existencias de un producto son la suma de sus series y lotes vigentes y se recalculan con cada cambio.
- Cambiar las existencias de una serie o lote genera un movimiento de ajuste; darla de baja, uno de baja. Los movimientos anotados a mano no cambian existencias, como en Veolab.
- Las operaciones consumen sus consumibles y usan sus equipos al crearse, y los devuelven al borrarse.
- Préstamos: estados R registrado, E entregado, P parcialmente devuelto, D devuelto, C cancelado. Sin existencias suficientes no se graba.

### Residuos

Gestión de residuos del laboratorio (módulo GDR).

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/residuos` | G P U D | `LABRED` | Residuos generados. |
| `/residuos/registro` | P | `LABRED` | Alta masiva: unos datos comunes y una línea por tipo de residuo. Datos comunes y `lineas` `[{tipo_residuo_*, unidades, valor_unitario, valor_total}]`. |
| `/tipos-residuo` | G P U D | `LABTDR` | Tipos de residuo. |

- `es_baja` y `fecha_baja` van juntas; el valor total lo indica el usuario.

### Personal y formación

Empleados, cargos, departamentos y el plan de formación.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/empleados` | G P U D | `GRHEMP` | Empleados. |
| `/empleados/ausencias` | G P U D | `GRHAUS` | Ausencias de un empleado. |
| `/empleados/curriculum` | G P U D | `GRHCUR` | Currículum de un empleado en la empresa (cargo y departamento por periodo). |
| `/empleados/formacion` | G P U D | `GRHFOR` | Formación de un empleado fuera del plan de formación. |
| `/empleados/clientes` | G P U D | `GRHCLI` | Clientes asociados a un empleado. |
| `/empleados/cargos` | G P U D | `GRHEYC` | Cargos de un empleado, en orden. |
| `/cargos` | G P U D | `GRHCAR` | Cargos (puestos de trabajo). |
| `/cargos/tareas` | G P U D | `GRHTAR` | Tareas de un cargo. |
| `/departamentos` | G P U D | `GRHDEP` | Departamentos. |
| `/cursos` | G P U D | `GRHPAF` | Cursos del plan de formación. |
| `/cursos/alumnos` | G P U D | `GRHALU` | Alumnos de un curso y su evaluación. |
| `/cursos/profesores` | G P U D | `GRHPRO` | Profesores de un curso. |

- Las subtablas (ausencias, currículum, formación, tareas) se direccionan con la entidad padre y un `codigo` de línea, que sin indicarlo es el siguiente dentro del padre.

### Gestión documental

Carpetas y documentos con versiones, vinculados a cualquier ficha de Veolab.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/documentos` | G P U D | `DOCFAT` | Documentos: propiedades, carpeta y entidad vinculada. El alta va en multipart. |
| `/documentos/contenido` | G P | `DOCFAT` | Descarga (GET) o sustitución (POST, multipart) del contenido. `?delegacion=&codigo=[&version=][&inline=T]`: descarga el fichero. `?delegacion=&codigo=`, multipart con `fichero`: contenido nuevo (o versión nueva). |
| `/documentos/versiones` | G U D | `DOCVER` | Versiones de un documento: restablecer o borrar. Versiones de un documento. `es_actual: "T"` restablece la versión. Borra una versión (no la actual). |
| `/documentos/carpetas` | G P U D | `DOCDIR` | Carpetas. |
| `/documentos/carpetas/perfiles` | G P D | `DOCDYP` | Perfiles con los que se comparte una carpeta. |

- El alta y el contenido se envían en `multipart/form-data` con el campo `fichero`; el resto, en JSON.
- Un documento se vincula a una ficha (cliente, operación, informe, factura...) de su misma delegación.
- La papelera es la carpeta 0: `DELETE` envía a la papelera y `definitivo=T` (o borrar desde la papelera) lo elimina.
- Con control de versiones, cada contenido nuevo crea una versión. La compresión ZIP sigue la configuración de Veolab.
- El tamaño máximo lo fija el servidor (en el de Spuch, 100 MB); por encima, `413`.

### Agenda y comunicaciones

Agenda de los usuarios, festivos, notificaciones, mensajería y avisos emergentes.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/agenda` | G P U D | `AGEAGE` | Eventos de agenda de un usuario (fecha única o periódicos), con asistentes y avisos. |
| `/agenda/fechas` | G | `AGEAGE` | Fechas de los eventos de un usuario entre dos fechas (vista de calendario). `?usuario_*&desde=&hasta=`: fechas propias y de los eventos a los que asiste. |
| `/agenda/estados` | G P U D | `AGEEST` | Estados de los eventos. |
| `/agenda/clasificaciones` | G P U D | `AGECLA` | Clasificaciones de los eventos. |
| `/periodicidad/fechas` | P | `—` | Vista previa de las fechas que genera una periodicidad, sin grabar. Cuerpo `{inicio, repeticion, delegacion, horizonte}`. |
| `/festivos` | G P U D | `AGEFES` | Calendario de festivos (para las fechas laborables). |
| `/notificaciones` | G D | `ACCNOT` | Notificaciones de los usuarios: lectura y borrado. |
| `/mensajes` | G P | `MENMEN` | Mensajes entre usuarios (módulo COM). |
| `/mensajes/conversacion` | G | `MENMEN` | Conversación entre dos usuarios, en orden. `?usuario_*&con_*[&desde=]`. |
| `/mensajes/leidos` | P | `MENMEN` | Marca como leídos los mensajes de un usuario. Cuerpo `{usuario_*[, con_*]}`. |
| `/avisos` | G D | `ACCAVI` | Avisos emergentes pendientes de un usuario. |
| `/avisos/vistos` | P | `ACCAVI` | Marca como vistos todos los avisos vencidos de un usuario. Cuerpo `{usuario_*[, tipo]}`. |

- La API no tiene sesión de usuario: estas rutas trabajan sobre el usuario que se indique (`usuario_delegacion` + `usuario_codigo`), que la aplicación cliente toma de su usuario conectado.
- Periodicidad (`repeticion`): diaria, semanal, mensual o anual, con fin por fecha o por número de repeticiones y traslado opcional al siguiente día laborable. Las fechas se generan hasta el horizonte de periodicidades de Veolab (al menos 180 días).

### Usuarios, configuración y auditoría

Usuarios de Veolab y sus perfiles, la configuración del laboratorio (lectura) y la auditoría.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/usuarios` | G P U D | `ACCUSU` | Usuarios de Veolab (con su contraseña de Veolab, solo de escritura). |
| `/usuarios/firma` | G P D | `—` | Firma digitalizada de un usuario (imagen BMP, JPG o GIF). `?delegacion=&codigo=[&inline=T]`: descarga la imagen. Multipart con `fichero`: sustituye la firma. Quita la firma. |
| `/perfiles` | G P U D | `ACCPER` | Perfiles de usuario. |
| `/perfiles/permisos` | G U | `—` | Permisos de un perfil sobre cada funcionalidad. `?perfil_delegacion=&perfil_codigo=`: todas las funcionalidades con su acceso. Cuerpo `{permisos: [{funcionalidad, acceso: E|L|null, especial}]}`. |
| `/funcionalidades` | G | `ACCFUN` | Funcionalidades de Veolab (solo lectura). |
| `/modulos` | G | `ACCMOD` | Módulos de Veolab, activos y licenciados (solo lectura). |
| `/delegaciones` | G P U D | `ACCDEL` | Delegaciones del laboratorio. |
| `/configuracion/general` | G | `ACCPAR` | Configuración general (solo lectura, sin contraseñas). |
| `/configuracion/laboratorio` | G | `LABCON` | Configuración del laboratorio (solo lectura). |
| `/configuracion/codigos` | G | `ACCCFC` | Formato y numeración de los códigos por tabla (solo lectura). |
| `/configuracion/imagen-acreditacion` | G | `—` | Imagen de acreditación de los informes (solo lectura). Descarga la imagen (`inline=T` para mostrarla). |
| `/series` | G | `ACCCLT` | Series y contadores (solo lectura). |
| `/auditorias` | G | `ACCAUD` | Registro de auditoría (solo lectura). |
| `/auditorias-archivadas` | G | `ACAAUD` | Auditoría archivada (solo lectura). |

- **La API no aplica privilegios de usuario:** el token da acceso total al laboratorio. Los perfiles y permisos se mantienen para que la aplicación cliente los aplique.
- La contraseña (`contrasena`) se guarda como Veolab, para que el usuario entre en Veolab con ella; nunca se devuelve.
- La configuración se mantiene en las pantallas de Veolab; la API solo la lee y nunca devuelve contraseñas ni la licencia cifrada.


## Lo que se hace desde Veolab

La API cubre la gestión diaria; estas tareas siguen en la aplicación de escritorio:

- Emitir facturas definitivas y rectificativas, y el envío a la AEAT (Verifactu).
- Generar el documento (PDF) de los informes y su exportación automática.
- Configuración: pantallas *Configurar...*, series, módulos, tipos de firma (la API la lee).
- Estadísticas y plantillas de exportación.
- Cambiar los servicios de una operación ya creada.
- Algunos avisos que crea Veolab al grabar (al analista, fecha de compromiso, marcas en resultados).

## Ejemplos

**Crear una operación con sus servicios y un campo autodefinible:**

```http
POST /api/v2/operaciones
Authorization: Bearer 12|AbC...
Content-Type: application/json

{
  "serie": "26",
  "cliente_codigo": "C001",
  "descripcion": "Agua de red - grifo cocina",
  "fecha_recogida": "2026-10-02 09:30:00",
  "estado": 1,
  "servicios": [{ "codigo": "AGUA-CONTROL" }],
  "autodefinibles": { "Temperatura de llegada": "4,5" }
}
```
```json
{ "message": "Registro creado correctamente", "data": { "delegacion": "", "serie": "26", "codigo": 144 } }
```

**Grabar resultados de una técnica:**

```http
PUT /api/v2/resultados?operacion_delegacion=&operacion_serie=26&operacion_codigo=144&tecnica_delegacion=&tecnica_codigo=PH
Content-Type: application/json

{ "valores": { "A": 7.2 }, "usuario_codigo": "ANALISTA1" }
```

**Python:**

```python
import requests

API = "https://api.veolab.es/api"
token = requests.post(f"{API}/login", json={"name": "mi_laboratorio", "password": "********"}).json()["token"]
s = requests.Session()
s.headers.update({"Authorization": f"Bearer {token}", "X-Veolab-Sesion": "Portal de clientes"})

page = 1
while True:
    r = s.get(f"{API}/v2/informes", params={"estado_validacion": "V", "page": page, "limit": 100}).json()
    for informe in r["data"]:
        print(informe["serie"], informe["codigo"], informe["fecha_creacion"])
    if page >= r["meta"]["last_page"]:
        break
    page += 1
```

**JavaScript:**

```js
const API = "https://api.veolab.es/api/v2";
const res = await fetch(`${API}/clientes?search=${encodeURIComponent("aguas")}&is_deleted=F`, {
  headers: { Authorization: `Bearer ${token}` },
});
const { data, meta } = await res.json();
```

**Subir un documento y vincularlo a un cliente:**

```bash
curl -X POST https://api.veolab.es/api/v2/documentos \
  -H "Authorization: Bearer 12|AbC..." \
  -F "fichero=@certificado.pdf" -F "cliente_codigo=C001" -F "descripcion=Certificado 2026"
```

## Instalación en un servidor propio

Spuch ofrece la API como servicio en `https://api.veolab.es`. Para alojarla en un servidor propio:

**Requisitos:** licencia de Veolab Edición Empresarial; PHP 8.2 o superior con las extensiones `pdo_mysql`, `mbstring`, `zip` y `fileinfo`;
Composer; MySQL o MariaDB con las bases de datos de Veolab; Apache o Nginx con HTTPS.

```bash
git clone https://github.com/dabizspuch/veolabapi.git api
cd api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate          # tablas propias de la API (usuarios y tokens) en la BD central
```

**Configuración (`.env`):**

| Variable | Uso |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` | Servidor MySQL de los laboratorios. El usuario necesita acceso a la BD central y a las de los laboratorios. |
| `DB_DATABASE` | BD central de la API (por defecto `veolabapi`). |
| `SANCTUM_EXPIRATION` | Caducidad de los tokens en minutos (43200 = 30 días; vacío = sin caducidad). |
| `VEOLAB_SEPARADOR_DECIMAL` | `,` o `.`: el separador decimal de los equipos Veolab de los laboratorios del servidor. |
| `VEOLAB_ENC_*` | Patrones de la licencia y de las contraseñas de Veolab. Los facilita Spuch; sin ellos se aplican siempre las restricciones de Verifactu y no se pueden poner contraseñas de usuario. |

**Laboratorios:** cada laboratorio es un usuario de la API cuyo `name` es **el nombre exacto de su
base de datos de Veolab**:

```bash
php artisan tinker
>>> \App\Models\User::create(['name' => 'mi_laboratorio', 'email' => 'lab@ejemplo.com', 'password' => \Hash::make('una-contraseña-larga')]);
```

**Servidor web:** la raíz pública es la carpeta `public/`. Para la gestión documental, ajuste el
tamaño máximo de subida (`upload_max_filesize` y `post_max_size` de PHP, `client_max_body_size` de
Nginx y el límite del cortafuegos web si lo hay). Programe `php artisan schedule:run` cada minuto
en el cron para borrar a diario los tokens caducados. Compruebe la licencia de un laboratorio con
`php artisan veolab:licencia <bd>`.

**Actualizar:** `git pull`, `composer install --no-dev` y `php artisan route:clear`.

## Documentación

- **[Manual de referencia](https://www.spuch.com/downloads/API%20REST%20Veolab%20-%20Manual%20de%20referencia.pdf)**
  (PDF): todos los recursos, sus reglas y sus campos, con ejemplos.
- **[Presentación](https://www.spuch.com/downloads/API%20REST%20Veolab.pdf)** (PDF): qué es la API y cómo empezar.
- Otros recursos de Veolab: [spuch.com/resources.php](https://www.spuch.com/resources.php).
- **Diseño técnico y reglas de negocio**, recurso por recurso: [`docs/v2/CONVENCIONES.md`](docs/v2/CONVENCIONES.md).

## Historial de versiones

### 2.0.0 — octubre de 2026
- Reescritura completa con rutas `/api/v2`, claves con nombre, respuesta `{data, meta}`, filtros,
  orden y paginación.
- Más de 100 recursos con las reglas de negocio de Veolab, auditoría y Verifactu.
- Caducidad y renovación de tokens, límite de intentos de login.

### 1.0.0 — enero de 2025
- Primera versión (archivada en la etiqueta `v1.0.0`).

## Licencia

Código bajo [licencia MIT](LICENSE); consulte también los [términos de uso](TERMS_OF_USE.md).
Veolab es un producto de [Spuch](https://www.spuch.com).
