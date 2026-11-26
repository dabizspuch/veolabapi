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

### Versiones de Veolab

La API funciona con bases de datos de Veolab 2.4 y de Veolab 3. Los campos y recursos añadidos
en Veolab 3 (marcados «desde Veolab 3») no se devuelven en una base anterior, y enviarlos da `422`
con un mensaje que lo indica. `meta.version_bd` en los listados y `version_bd` en
`/configuracion/general` dicen la versión de la base de datos de cada laboratorio.

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
| `500` | Error interno. Lleva una `referencia` que identifica el error en el registro del servidor: indíquela al comunicar la incidencia. |

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
| `/proveedores/evaluaciones` | G P U D | `SINEVA` | Evaluaciones de un proveedor; la última se lleva al proveedor (desde Veolab 3). |
| `/tipos-evaluacion` | G P U D | `SINTIE` | Tipos de evaluación. |
| `/formas-envio` | G P U D | `LABFDE` | Formas de envío de informes. |

- Un punto de muestreo cuelga de la raíz o de una categoría del mismo cliente. Un punto usado en operaciones, planificaciones o facturas no se borra (se da de baja); borrar una categoría borra sus puntos.
- Los códigos de cliente y proveedor son de texto y se ordenan alfabéticamente, como en Veolab.

### Catálogo de análisis

Servicios, parámetros (técnicas) y todo lo que define cómo se analiza, se valora y se cobra.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/servicios` | G P U D | `LABSER` | Servicios: conjuntos de técnicas que se contratan juntas. Desde Veolab 3, opcionalmente exclusivos de un cliente (`cliente_delegacion`, `cliente_codigo`); `?para_cliente_codigo=` (y `para_cliente_delegacion`) lista los que se pueden elegir para ese cliente: los suyos y los que no tienen cliente. |
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
| `/envases` | G P U D | `LABENV` | Envases predefinidos de las operaciones (desde Veolab 3). |
| `/lugares` | G P U D | `LABLUG` | Lugares de recogida predefinidos (desde Veolab 3). |
| `/temperaturas` | G P U D | `LABTEM` | Temperaturas predefinidas (desde Veolab 3). |
| `/autodefinibles` | G P U D | `LABAUT` | Definición de los campos autodefinibles de operaciones y lotes. |

- Las relaciones (técnicas de un servicio, precios, matrices...) se direccionan con las dos entidades: `{grupo}_delegacion` + `{grupo}_codigo` de cada una. Con una sola entidad, el GET lista sus relaciones.
- `posicion` es el orden en la ficha; sin indicarla, al final. Las técnicas de un servicio empiezan en la 2.
- Descuentos y precios en texto (`"10%"`, `"15"`) se aceptan con coma o punto y se guardan con el separador decimal del laboratorio.
- Columnas de resultados: se añaden al final y solo se borra la última; las demás se desactivan. `rangos` sustituye los intervalos de la columna.
- Un autodefinible se identifica por su nombre (empieza por letra, sin símbolos, no repetido). Con valores en alguna operación o lote no se borra.
- Desde Veolab 3, un autodefinible puede tener valor predeterminado o fórmula (`predeterminado`), que la API aplica al crear operaciones, planificaciones y lotes y recalcula al modificarlos.
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
- Desde Veolab 3: campos de recolección (`fecha_elaboracion`, `fecha_caducidad`, `cloro_libre`, `ph`, `conductividad`, `condiciones_recepcion`, `informacion_cliente`, `pedido`, `transporte`, `conservacion`) en operaciones y planificaciones, comercial de la planificación (heredado del presupuesto) y valores predeterminados y fórmulas de autodefinibles y campos, como en las fichas de Veolab.
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
| `/facturas` | G P U D | `FACFAC` | Facturas: borradores (alta, cambio y borrado) y estado de las emitidas; sin Verifactu, también facturas definitivas. |
| `/facturas/emitir` | P | `FACFAC` | Sin Verifactu, emite un borrador: `?delegacion=&serie=BOR&codigo=N`. Pasa a su `serie_final` con un código nuevo, que devuelve en `data`. Sin `serie_final`, se emite en la serie vacía, como en Veolab: conviene indicarla siempre al crear el borrador. |

- Las líneas se indican como `lineas` (la rejilla completa) o `servicios` (cada servicio con sus técnicas y gastos, con los precios del cliente o la tarifa). En un PUT sustituyen la rejilla entera.
- Subtotal, base imponible, impuestos, suplidos y total se calculan siempre.
- Desde Veolab 3, `fecha_seguimiento` en presupuestos: al pasarlos a enviado se rellena con los días configurados en Veolab.
- **Con Verifactu la API no emite facturas.** Las definitivas, rectificativas y el envío a la AEAT se hacen en Veolab. Desde la API: borradores (si el laboratorio los tiene activados) y, en las emitidas, solo enviada, cobrada, contabilizada, pendiente, vencimiento, fecha de pago y notas. Si la API no puede leer la licencia, se comporta como con Verifactu.
- **Sin Verifactu la API factura como Veolab**, sin huella ni envío:
  - Con borradores activados, el alta crea un borrador y `/facturas/emitir` lo emite. La fecha de la factura no cambia.
  - Sin borradores, el alta crea la factura definitiva en la `serie` indicada. El `codigo` es opcional: sin él se usa el contador con su múltiplo.
  - Las operaciones de una factura definitiva quedan facturadas y se archivan según el modo de archivo de Veolab (al facturar, al enviar, al cobrar o al contabilizar).
  - Las emitidas sin Verifactu se modifican y eliminan como un borrador. Las rectificativas, solo en Veolab.
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
| `/inventario/trazabilidad` | G | `ALMMOV` | Cadena de trazabilidad de una serie (Veolab 3, ISO 17025 6.5): con qué patrones y materiales de referencia se calibra o verifica, si eran válidos ese día, y qué series se calibran o verifican con ella. `?producto_delegacion=&producto_codigo=&codigo=`. |
| `/inventario/operaciones-afectadas` | G P | `LABOPE` | Equipo no conforme (Veolab 3, ISO 17025 6.4.9): `GET` lista las operaciones en las que se usó la serie desde la última calibración o verificación conforme (o `desde`/`hasta`); `POST` crea la incidencia de trabajo no conforme (clase `T`, origen `Q`) enlazada con la serie y las operaciones elegidas. |
| `/prestamos` | G P U D | `ALMPRE` | Préstamos de material (módulo ALM) con sus líneas. |
| `/pedidos` | G P U D | `ALMPED` | Pedidos a proveedor con sus líneas, estado según lo recibido y requisitos de compra (módulo ALM, desde Veolab 3). |
| `/entregas` | G P U D | `ALMALB` | Entregas de proveedor: el alta crea series o lotes, movimientos de compra y existencias y lleva lo recibido a los pedidos; verificación de lo recibido (módulo ALM, desde Veolab 3). `?pedido_codigo=` (con `pedido_delegacion` y `pedido_serie`) lista las de un pedido. |
| `/equipos` | G P U D | `LABEQU` | Equipos de los clientes que se muestrean (p. ej. transformadores). |
| `/tipos-equipos` | G P U D | `LABTEQ` | Tipos de equipo (árbol). |

- Las existencias de un producto son la suma de sus series y lotes vigentes y se recalculan con cada cambio.
- Cambiar las existencias de una serie o lote genera un movimiento de ajuste; darla de baja, uno de baja. En Veolab 3, como en su ficha de movimiento, un movimiento anotado a mano cambia las existencias de su serie (entradas suman; salidas, bajas, préstamos, anulaciones y consumos restan; el ajuste lleva su signo; el uso no cuenta) y borrarlo lo deshace; los de operaciones, préstamos y entregas se gestionan desde su origen. En bases 2.4 los movimientos a mano no cambian existencias.
- Las operaciones consumen sus consumibles y usan sus equipos al crearse, y los devuelven al borrarse.
- Préstamos: estados R registrado, E entregado, P parcialmente devuelto, D devuelto, C cancelado. Sin existencias suficientes no se graba.
- Pedidos: estados B borrador, E enviado, P recibido en parte, R recibido, C cancelado; P y R los ponen las entregas. Sin referencia, precio o formato, las líneas toman los del proveedor. Un pedido con algo recibido no se borra (se cancela o archiva).
- Patrones y materiales de referencia (Veolab 3, con su script): `tipo_metrologico` (`R` patrón de referencia, `T` patrón de trabajo, `C` material de referencia certificado, `M` material de referencia o control; vacío, producto normal) y `dias_validez_apertura` en `/productos`; datos del certificado (`valor_certificado`, `incertidumbre`, `trazabilidad`, `numero_certificado`, `fecha_certificado`, `acreditado`) y, de solo lectura, `tipo_metrologico` y `caducidad_efectiva` (la menor entre la caducidad y la apertura más los días de validez) en `/inventario`. Filtros `?tipo_metrologico=R,T` y `?patrones=T` en los dos.
- Entregas: las líneas (de pedido, de producto o sueltas) solo se indican al crear. `actualizar_precios: "T"` guarda en el proveedor los precios de compra distintos de los suyos; `verificacion` `A`/`N` apunta fecha y `verificador_*`, y con `poner_fuera_de_uso: "T"` una entrega no conforme deja fuera de uso sus series. Solo se borra si sus series no se han usado, y entonces se deshace todo lo que hizo.

### Residuos

Gestión de residuos del laboratorio (módulo GDR).

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/residuos` | G P U D | `LABRED` | Residuos generados. |
| `/residuos/registro` | P | `LABRED` | Alta masiva: unos datos comunes y una línea por tipo de residuo. Datos comunes y `lineas` `[{tipo_residuo_*, unidades, valor_unitario, valor_total}]`. |
| `/tipos-residuo` | G P U D | `LABTDR` | Tipos de residuo. |

- `es_baja` y `fecha_baja` van juntas; el valor total lo indica el usuario.

### Calidad

Incidencias de calidad (módulo CAL, desde Veolab 3; ISO/IEC 17025 7.9, 7.10 y 8.5-8.7): quejas, trabajo no conforme, hallazgos de auditoría, mejoras y riesgos. Ensayos de aptitud e intercomparaciones (7.7.2): rondas, muestras y resultados con su puntuación. Auditorías internas y externas (8.8): programa, alcance, hallazgos y cobertura.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/incidencias` | G P U D | `CALINC` | Incidencias: clase, origen, tipo, datos, tratamiento, verificación y cierre. Además de los campos, `?enlace_tabla=&enlace_delegacion=&enlace_serie=&enlace_codigo=` (las de un registro: operación, informe, cliente...), `?propias_usuario_codigo=` (las que ve un usuario sin privilegio de ver todas) y `?abiertas=T`. |
| `/incidencias/estado` | U | `CALINC` | Cambio de estado. `?delegacion=&serie=&codigo=`; cuerpo `{estado, motivo_anulacion, usuario_*}`. |
| `/incidencias/historial` | G | `ACCAUD` | Historial de la incidencia (registro de cambios y su archivo): quién cambió qué y cuándo, de la incidencia y de sus acciones, enlaces e implicados. `?delegacion=&serie=&codigo=`; `meta.aviso` si el registro está desactivado, no hay sucesos o no hay detalle por campo. |
| `/incidencias/acciones` | G P U D | `CALACC` | Acciones (corrección, correctiva, mejora) con responsable, fechas y eficacia. |
| `/incidencias/enlaces` | G P D | `CALIYE` | A qué afecta: operaciones, informes, clientes, proveedores, empleados, técnicas, equipos o productos, cartas de control, otras incidencias, ensayos de aptitud y auditorías. |
| `/incidencias/enlaces/informes` | P | `CALIYE` | Enlaza los informes de las operaciones enlazadas. Cuerpo `{incidencia_delegacion, incidencia_serie, incidencia_codigo}`. |
| `/incidencias/implicados` | G P D | `CALIEM` | Empleados implicados, que reciben los avisos. |
| `/tipos-incidencia` | G P U D | `CALTIN` | Tipos de incidencia, con la clase que proponen. |
| `/ensayos-aptitud` | G P U D | `CALEAP` | Ensayos de aptitud e intercomparaciones (ISO/IEC 17025 7.7.2): organizador (proveedor), programa, ronda, fechas, estado, límites de evaluación y conclusión. Además de los campos, `?abiertas=T`, `?anio=`, `?con_insatisfactorios=T`, `?parametro_codigo=` y `?operacion_codigo=` (las rondas de una operación). |
| `/ensayos-aptitud/incidencia` | P | `CALINC` | Abre una incidencia de trabajo no conforme enlazada a la ronda y a sus muestras. `?delegacion=&serie=&codigo=`; cuerpo `{incidencia_detectada_por_*, incidencia_usuario_*, incidencia_serie}`. |
| `/ensayos-aptitud/muestras` | G P U D | `CALEAO` | Operaciones que son las muestras de la ronda, con su identificación (A, B...). |
| `/ensayos-aptitud/resultados` | G P U D | `CALEAR` | Resultados por parámetro: lo enviado, lo que da el organizador y la puntuación y la evaluación que calcula la API, con el aviso de tendencia. Además de los campos, `?no_satisfactorias=T`, `?difiere=T`, `?aviso_tendencia=T`, `?organizador_codigo=`, `?programa=`, `?ronda=`, `?anio=` y `?estado_ensayo=`. |
| `/ensayos-aptitud/resultados/traer` | P | `CALEAR` | Trae los parámetros con resultado de las muestras. Cuerpo `{ensayo_delegacion, ensayo_serie, ensayo_codigo}`. |
| `/ensayos-aptitud/familias` | G P U D | `CALFAE` | Familias de ensayos del plan de intercomparaciones: nombre, frecuencia mínima en años, programa y organizador previstos, justificación, baja y `parametros` (`CALFAT`, se sustituyen). Además de los campos, `?parametro_codigo=`. |
| `/ensayos-aptitud/plan` | G | `CALFAE` | Plan de intercomparaciones (solo lectura): por familia, cada año de `?desde=` a `?hasta=` (realizado con la peor evaluación, en curso, no realizado o previsto), la próxima participación y la situación; los parámetros acreditados sin familia y el resumen para la revisión por la dirección. `?bajas=T`, `?delegacion=`. |
| `/ensayos-aptitud/tendencia` | G | `CALEAR` | Tendencia de un parámetro (solo lectura): sus resultados en el orden del tiempo con la puntuación y las rachas de aviso (cuestionables seguidos o posible sesgo). `?parametro_delegacion=&parametro_codigo=`. |
| `/auditorias-calidad` | G P U D | `CALAUI` | Auditorías internas y externas (ISO/IEC 17025 8.8): tipo, año del programa, fechas, estado, auditor jefe o entidad externa, criterios y conclusión, con `equipo` y `auditados` (`CALAUQ`), `apartados` (`CALAUX`), `delegaciones` (`CALAUL`) y `ensayos_presenciados` (`CALAUV`), que se sustituyen; la lectura añade los `hallazgos` (incidencias enlazadas) y `hallazgos_abiertos`. Además de los campos, `?auditor_codigo=`, `?auditado_codigo=`, `?con_hallazgos_abiertos=T`, `?apartado=` y `?parametro_codigo=`. |
| `/revisiones-direccion` | G P U D | `CALRDI` | Revisión por la dirección (ISO/IEC 17025 8.9): periodo revisado, fecha y lugar de la reunión, estado, quién la aprueba, asistentes externos, las cuatro salidas de 8.9.3 y observaciones, con `asistentes` (`CALRDA`) y `delegaciones` (`CALRDL`), que se sustituyen, y los quince `temas` de 8.9.2 (`CALRDX`: datos del sistema y análisis), que se cambian por tema; la lectura añade `decisiones` y `decisiones_abiertas`. Además de los campos, `?anio=`, `?asistente_codigo=` y `?con_decisiones_abiertas=T`. Modificar, recoger datos y eliminar admiten `?usuario_delegacion=&usuario_codigo=` (debe ser responsable de calidad). |
| `/revisiones-direccion/recoger-datos` | P | `CALRDX` | Escribe los datos del sistema de los temas b (objetivos de calidad del año), c (control de documentos) y d-o (resumen del periodo y las delegaciones de la revisión). Solo en borrador. `?delegacion=&serie=&codigo=`; cuerpo `{sobrescribir: "T"}` si ya los tenían. |
| `/revisiones-direccion/decisiones` | G | `CALIYE` | Decisiones de la revisión (incidencias enlazadas): código, título, responsable, fecha prevista de la acción más próxima y estado. `?delegacion=&serie=&codigo=`. |
| `/indicadores-calidad` | G P U D | `CALKPI` | Indicadores de calidad: descripción, `calculo` (los 18 automáticos de Veolab, `INF_PLAZO`…; vacío = manual), unidad, `sentido` (`S` mejor si sube, `B` si baja), `periodicidad` de los valores manuales (`M`, `T`, `S`, `A`), decimales, definición y baja. La API crea solo manuales (código = el siguiente al mayor, nunca menos de 19); los de Veolab solo admiten cambiar la baja y no se eliminan; no se elimina el que usa algún objetivo. `?para_delegacion=`, `?automatico=T`. |
| `/indicadores-calidad/valores` | G U D | `CALKPV` | Valores de un indicador manual (`?delegacion=&codigo=`, más `&fecha_inicio=` en PUT y DELETE): `PUT` crea o sustituye `{valor, fecha_fin, observaciones}` (sin `fecha_fin`, el fin según la periodicidad). `GET` admite `?desde=&hasta=`. |
| `/indicadores-calidad/valor` | G | `CALKPI` | Valor calculado del indicador `?codigo=` de `?desde=` a `?hasta=` (por defecto, del 1 de enero a hoy) con los datos de `?delegacion=` (vacía = todo el laboratorio). Manuales: la media de sus valores con inicio en el periodo. |
| `/objetivos-calidad` | G P U D | `CALOBJ` | Objetivos de calidad anuales: año, descripción, indicador, meta, responsable, plan y, al cerrarlos, valor alcanzado, resultado (`S`, `P`, `N`), conclusión y fecha de cierre. La lectura añade `valor` (el congelado si está cerrado; si no, el calculado del año hasta hoy), `cumple`, `semaforo` (`verde`, `ambar`, `rojo`) y `mejoras`. Solo los abiertos se modifican o eliminan. Además de los campos (`?anio=`…), `?para_delegacion=`. |
| `/objetivos-calidad/cerrar`, `/reabrir` | P | `CALOBJ` | Cierra (`{valor, resultado, conclusion}`; sin `valor`, el calculado del año) o reabre el objetivo. `?delegacion=&codigo=`. |
| `/objetivos-calidad/duplicar` | P | `CALOBJ` | Copia los objetivos de `?anio=` (y `?delegacion=`, si se indica) al año siguiente, abiertos. |
| `/objetivos-calidad/seguimiento` | G | `CALOBJ` | Valor, cumplimiento y semáforo por trimestres y del año. `?delegacion=&codigo=`. |
| `/objetivos-calidad/mejora` | P | `CALINC` | Crea la incidencia de mejora (clase `M`, origen `D`) y la enlaza al objetivo (`CALIYE` tipo `CALOBJ`). `?delegacion=&codigo=`; cuerpo opcional `{delegacion, serie, descripcion, detalle, detectada_por_*, usuario_*}`. |
| `/auditorias-calidad/cobertura` | G | `CALAUX` | Cobertura del programa (solo lectura): cada apartado de la norma por años de `?desde=` a `?hasta=` (auditado, previsto o, hasta el año actual, sin auditar) y los parámetros acreditados sin ensayo presenciado desde `?ciclo_desde=` (por defecto, hace un año). `?delegacion=`. |
| `/auditorias-calidad/listas` | G P U D | `CALLVE` | Listas de verificación de auditorías (plantillas): descripción, `uso` (`G` auditoría, `T` ensayo presenciado), `predeterminada` (una por uso y delegación), `baja` y `preguntas` (`CALLVQ`: orden, apartado, pregunta, qué revisar y referencia), que se sustituyen. El código es el siguiente al mayor. Solo se eliminan si ninguna auditoría las ha usado, y nunca las comunes 1 y 2 de Veolab. `?para_delegacion=`. |
| `/auditorias-calidad/listas/duplicar` | P | `CALLVE` | Copia una lista con sus preguntas. `?delegacion=&codigo=`; cuerpo opcional `{delegacion, descripcion}`. |
| `/auditorias-calidad/lista` | G P U D | `CALAUP` | Lista de verificación de una auditoría (`?delegacion=&serie=&codigo=`, más `&linea=` en PUT y DELETE): pregunta, resultado (`C`, `N`, `O`, `X`; vacío sin responder), evidencia, orden y hallazgo (incidencia enlazada a la auditoría). El GET añade `meta.resumen` y `meta.resumen_apartados` (por apartado en el orden de la norma: `apartado`, `titulo`, `preguntas`, `conformes`, `no_conformes`, `observaciones`, `no_aplican`, `sin_responder`; sin `?auv=`, solo las de la auditoría, como el marcador `resumenapartadoslistaauditoria`); `?auv=` filtra por ensayo presenciado (`0`, las de la auditoría). Anulada, solo lectura. |
| `/auditorias-calidad/lista/cargar` | P | `CALAUP` | Copia las preguntas de `listas[]` a la auditoría (o a su ensayo presenciado `auv`), sin repetir apartado y texto; con `solo_alcance` (por defecto), solo las de los apartados del alcance (si está vacío, todas). |
| `/auditorias-calidad/lista/hallazgo` | P | `CALINC` | Crea la incidencia del hallazgo de una pregunta «No conforme» u «Observación» (clase N, origen según el tipo, apartado, título = la pregunta, detalle = la evidencia), la enlaza a la auditoría y la guarda en la pregunta. `?delegacion=&serie=&codigo=&linea=`. |

- Estados: R registrada → T en tratamiento → V en verificación → C cerrada, y A anulada. Las no conformidades, las quejas y las incidencias con una acción correctiva pasan por verificación; el resto se cierra al terminar el tratamiento. Cada paso exige lo mismo que la ficha de Veolab (gravedad y responsable, causa y acción correctiva, decisión, eficacia, verificador, comunicación al cliente, motivo de anulación).
- Cerrada o anulada, la incidencia y sus acciones, enlaces e implicados son de solo lectura hasta que se reabre. Las acciones solo se cambian en tratamiento o en verificación.
- Las quejas no se eliminan nunca: se anulan. La API no tiene sesión: `usuario_*` es quien registra la incidencia y, en el cambio de estado y el borrado, quien da el paso (opcional: con él se comprueban sus privilegios como en Veolab; sin él, la API actúa como responsable de calidad).
- Al registrar, cambiar de estado o tocar las acciones se crean los avisos de calidad (notificaciones `Q` y `K`), según la configuración de notificaciones.
- Ensayos de aptitud: estados P pendiente de enviar, E enviada (sola al poner la fecha de envío), V evaluada (sola al poner la fecha del informe del organizador) y C cerrada, que exige la conclusión del laboratorio y deja la ronda, sus muestras y sus resultados en solo lectura hasta que se reabre; una cerrada no se elimina. La puntuación de comprobación (z, z′, zeta, En, D %, sobre log10 si se indica) y la evaluación (la del organizador o la propuesta según los límites de la ronda, ±2/±3 por defecto) las calcula la API al grabar, como Veolab. Con `abrir_incidencia=T`, si hay insatisfactorios y ninguna incidencia, se abre la que Veolab propone.
- Auditorías: estados P prevista, R realizada (exige la fecha de inicio), I informe emitido (exige la fecha del informe), C cerrada (exige que ningún hallazgo siga abierto) y A anulada. Los hallazgos son incidencias de clase `N` con su `apartado` de la norma, enlazadas a la auditoría (`/incidencias/enlaces` con la tabla `CALAUI`). En la interna se guarda el auditor jefe y en la contratada y las externas la entidad y el nombre del auditor externo (lo otro se vacía). Al grabar se devuelven en `avisos`, sin bloquear, el auditor que está entre los auditados (independencia) y la auditoría interna sin auditor. `/auditorias` sigue siendo el registro de auditoría de cambios (`ACCAUD`).
- Revisión por la dirección: estados B borrador → C celebrada (exige la fecha de la reunión, algún asistente y el análisis de los quince temas) → P aprobada (exige quién la aprueba y pone la fecha de aprobación), y A anulada desde borrador o celebrada; reabrir es volver a borrador. Aprobada o anulada es de solo lectura. Al crearla, el periodo por defecto va desde el día siguiente al fin de la última revisión no anulada hasta hoy (sin ninguna, el año anterior). Solo se elimina en borrador y sin decisiones ni documentos. Las decisiones son incidencias de clase `M` con origen `D` enlazadas con la tabla `CALRDI`.
- Objetivos e indicadores de calidad: solo el responsable de calidad (especial 1 de `CAL_OBJ`) escribe; con `?usuario_delegacion=&usuario_codigo=` se comprueba y sin él la API actúa como tal. Cumple si el valor alcanza la meta (mejor si sube: `>=`; si baja: `<=`); semáforo ámbar si se queda a menos de un 10 % de la meta. El tema b de la revisión por la dirección («Recoger datos») trae una línea por objetivo del año del fin del periodo.

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
| `/empleados/autorizaciones` | G P U D | `GRHAUT` | Autorizaciones de un empleado por técnica (desde Veolab 3). |
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
| `/documentos/accesos` | G | `DOCFAT` | Veolab 3. Accesos directos que apuntan a un documento (`?delegacion=&codigo=`), con su carpeta y ficha. |
| `/documentos/versiones` | G U D | `DOCVER` | Versiones de un documento: restablecer o borrar. Versiones de un documento. `es_actual: "T"` restablece la versión. Borra una versión (no la actual). |
| `/documentos/carpetas` | G P U D | `DOCDIR` | Carpetas. |
| `/documentos/carpetas/perfiles` | G P D | `DOCDYP` | Perfiles con los que se comparte una carpeta. |
| `/documentos/controlados` | G | `DOCFAT` | Veolab 3. Lista de documentos controlados (ISO 17025 8.3) con su edición vigente. Filtros `estado` (B, V, P pendiente de aprobar, O), `tipo`, `carpeta_*` (con subcarpetas), `revision_vencida`, `revision_proxima` y `texto`. |
| `/documentos/aprobar` | P | `DOCFAT` | Veolab 3. Aprueba la versión principal como edición vigente: `{edicion, motivo, fecha_edicion, aprobada_por_*, requiere_lectura}`. Con revisión antes de aprobar, solo una edición revisada. |
| `/documentos/revisar` | P | `DOCFAT` | Veolab 3. Revisión sin cambios: última revisión hoy y próxima recalculada (sin periodicidad, la próxima revisión no se toca; tampoco al aprobar). |
| `/documentos/descartar` | P | `DOCFAT` | Veolab 3. Descarta las versiones sin aprobar y vuelve a la edición aprobada. |
| `/documentos/lecturas` | G P | `DOCLEC` | Veolab 3. Lecturas de ediciones aprobadas por empleado. |
| `/documentos/lecturas/estado` | G | `DOCLEC` | Veolab 3, fase 2. Lecturas de la edición vigente de un documento (`?delegacion=&codigo=`) con sus destinatarios resueltos en personas: leída (con fecha), pendiente o vencida. |
| `/documentos/lecturas/pendientes` | G | `DOCLEC` | Veolab 3, fase 2. Documentos que un empleado tiene pendientes de leer (`?empleado_delegacion=&empleado_codigo=` o `usuario_*`), vencidos primero. |
| `/documentos/destinatarios` | G U | `DOCDIS` | Veolab 3, fase 2. Destinatarios de un documento (`?delegacion=&codigo=`) o de una carpeta (`?carpeta_delegacion=&carpeta_codigo=`): todo el personal, empleado, cargo, departamento o autorizados del parámetro. PUT con la lista entera. |
| `/documentos/revisar-edicion` | P | `DOCVER` | Veolab 3, fase 2. Revisa la edición en curso antes de aprobarla (carpetas con revisión antes de aprobar): `{comentario}`. |
| `/documentos/devolver-edicion` | P | `DOCVER` | Veolab 3, fase 2. Devuelve la edición en curso a borrador: `{motivo}` obligatorio. |
| `/documentos/copias` | G P U D | `DOCCOP` | Veolab 3, fase 2. Copias controladas en papel de un documento, con `por_retirar` (de una edición anterior a la vigente y sin retirar). |

- El alta y el contenido se envían en `multipart/form-data` con el campo `fichero`; el resto, en JSON.
- Un documento se vincula a una ficha (cliente, operación, informe, factura...) de su misma delegación.
- La papelera es la carpeta 0: `DELETE` envía a la papelera y `definitivo=T` (o borrar desde la papelera) lo elimina.
- Con control de versiones, cada contenido nuevo crea una versión. La compresión ZIP sigue la configuración de Veolab.
- Control de documentos (Veolab 3): por carpeta (heredado por las subcarpetas) con excepción por documento (`controlado`). Un controlado lleva siempre versiones: subir contenido crea una versión nueva que queda pendiente de aprobar hasta `POST /documentos/aprobar`, y quien solo consulta descarga la edición aprobada si se indica el usuario. Aprobar, revisar, marcar obsoleto y cambiar el control exigen el privilegio de aprobar documentos (`DOC_CTL` especial 1) si se indica el usuario. Solo con el módulo Calidad activo: sin él no hay documentos controlados, los datos de control se leen pero no se escriben y las rutas del control responden `422`.
- Accesos directos (Veolab 3): `POST /documentos` sin fichero con `acceso_directo_delegacion` y `acceso_directo_codigo` (más carpeta y ficha) crea un acceso a ese documento, que puede estar en cualquier carpeta o ficha. No tiene contenido propio: la descarga entrega el destino con sus reglas de control; versiones, lecturas, aprobar, revisar y descartar se hacen sobre el destino (`422` en el acceso). Renombrar el destino renombra sus accesos y borrarlo definitivamente los borra. Los accesos no cuentan como documentos controlados.
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
| `/mensajes` | G P | `MENMEN` | Mensajes entre usuarios (módulo MEN desde Veolab 3, COM antes); con Veolab 3, conversación de los usuarios cliente con el laboratorio y fecha de lectura. |
| `/mensajes/conversacion` | G | `MENMEN` | Conversación entre dos usuarios, en orden. `?usuario_*&con_*[&desde=]`. |
| `/mensajes/leidos` | P | `MENMEN` | Marca como leídos los mensajes de un usuario. Cuerpo `{usuario_*[, con_*]}`. |
| `/avisos` | G D | `ACCAVI` | Avisos emergentes pendientes de un usuario. |
| `/avisos/vistos` | P | `ACCAVI` | Marca como vistos todos los avisos vencidos de un usuario. Cuerpo `{usuario_*[, tipo]}`. |
| `/avisos-servicio` | G | — | Avisos de servicio de SPUCH vigentes para el laboratorio (cortes programados, incidencias urgentes y comunicaciones), los mismos que muestra Veolab; urgentes primero. Solo lectura. Si spuch.com no responde, `data` vacío y `meta.disponible = false`, nunca un error. Fechas en hora peninsular española (`meta.zona_horaria`). |
| `/tareas-pendientes` | G | `—` | Tareas pendientes de un usuario (Veolab 3, módulo COM, funcionalidad `COM_TAR`), calculadas al vuelo: incidencias y acciones, auditorías, ensayos de aptitud, revisión por la dirección y documentos controlados (revisar y aprobar ediciones, revisión periódica, lecturas pendientes y copias por retirar, con el módulo CAL), proveedores, autorizaciones, firmas, solicitudes, fecha de compromiso de las operaciones, calibración, verificación y mantenimiento de equipos, patrones y materiales de referencia caducados o a punto de caducar, stock bajo el mínimo, seguimiento de presupuestos, gráficos de control en error, familias del plan de intercomparaciones y objetivos de calidad sin definir o sin cerrar. `?usuario_*[&tipo=&grupo=&solo_vencidas=T&solo_mias=T]`. Solo lectura. |
| `/tareas-pendientes/recuento` | G | `—` | Recuento de las tareas pendientes de un usuario: `{total, vencidas, por_tipo}`. Mismos parámetros. |

- La API no tiene sesión de usuario: estas rutas trabajan sobre el usuario que se indique (`usuario_delegacion` + `usuario_codigo`), que la aplicación cliente toma de su usuario conectado.
- Periodicidad (`repeticion`): diaria, semanal, mensual o anual, con fin por fecha o por número de repeticiones y traslado opcional al siguiente día laborable. Las fechas se generan hasta el horizonte de periodicidades de Veolab (al menos 180 días).

### Usuarios, configuración y auditoría

Usuarios de Veolab y sus perfiles, la configuración del laboratorio (lectura) y la auditoría.

| Ruta | Métodos | Tabla | Descripción |
|---|---|---|---|
| `/usuarios` | G P U D | `ACCUSU` | Usuarios de Veolab (con su contraseña de Veolab, solo de escritura). Con Veolab 3, `abrir_tareas_al_iniciar` (`T`/`F`). |
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

- Con Verifactu, emitir facturas definitivas y el envío a la AEAT; siempre, las rectificativas.
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

### 2.1.0 — octubre de 2026
- Sin Verifactu, `/facturas` crea facturas definitivas cuando no hay borradores y modifica o elimina
  las emitidas. `/facturas/emitir` emite un borrador, como Veolab y sin huella ni envío.
- Veolab 3: los movimientos de inventario anotados a mano (`/inventario/movimientos`) cambian las
  existencias de su serie al crearlos, modificarlos o borrarlos, como la ficha de movimiento; los de
  préstamos y entregas de proveedor, como los de operaciones, solo se cambian desde su origen (`422`).
- Compatible con bases de datos de Veolab 3 y anteriores: lo nuevo de Veolab 3 se omite o se
  rechaza con `422` en las bases que no lo tienen; `meta.version_bd` en los listados.
- Veolab 3: patrones y materiales de referencia (ISO 17025 6.5 y 6.4.9; script de los patrones).
  `tipo_metrologico` y `dias_validez_apertura` en `/productos`; datos del certificado,
  `tipo_metrologico` y `caducidad_efectiva` en `/inventario`; filtros `tipo_metrologico` y
  `patrones`. Rutas nuevas `/inventario/trazabilidad` y `/inventario/operaciones-afectadas` (`GET`
  y `POST`, que abre la incidencia de trabajo no conforme). Tarea pendiente nueva `MP` (renovar
  patrón o material de referencia, grupo `M`) y una línea más en el tema l de la revisión por la
  dirección. Sin el script, los campos no aparecen y escribirlos o filtrar por ellos responde `422`.
- Veolab 3: al eliminar una operación generada desde una planificación, su fecha vuelve a quedar
  pendiente si no le queda otra operación, como en el escritorio.
- `GET /planificaciones` devuelve los servicios de cada planificación
  (`servicios: [{delegacion, codigo, nombre}]`, en el orden de la rejilla). En un `PUT` se aceptan
  si no cambian.
- Veolab 3: campos de recolección y predeterminados o fórmulas de autodefinibles y campos en
  operaciones, planificaciones y lotes; envases, lugares y temperaturas; comercial en
  planificaciones; fecha de seguimiento de presupuestos; mensajería con conversaciones de
  clientes y lectura; contraseñas con hash; opciones nuevas de configuración; reglas de
  subclientes; notificaciones de seguimiento y de iGEO.
- Veolab 3: los cambios de estado de presupuestos y el envío de facturas registran sucesos en el
  seguimiento comercial del cliente, y al borrar un cliente se borran sus sucesos.
- Veolab 3: `/documentos` se vincula también a pedidos, entregas, incidencias, solicitudes,
  ensayos de aptitud, auditorías de calidad y revisiones por la dirección.
- Veolab 3: al enviar documentos a la papelera se guarda su carpeta de origen (`FATCDPA`,
  `FATNCPA`), para que el escritorio pueda restaurarlos.
- Veolab 3: control de documentos (ISO 17025 8.3, fase 1). Datos de control en `/documentos`
  (código, tipo, entidad emisora, estado, quien lo elabora, revisión periódica; calculados
  `es_controlado` y `pendiente_aprobar`), `/documentos/versiones` (edición, motivo, aprobador y
  fechas) y `/documentos/carpetas` (`controlada`, tipo y periodicidad por defecto). Rutas nuevas
  `/documentos/controlados`, `/documentos/aprobar`, `/documentos/revisar`, `/documentos/descartar`
  y `/documentos/lecturas`. Con control de versiones, una versión sin nombre se llama «Versión N».
  El control solo actúa con el módulo Calidad (`CAL`) activo, como en el escritorio. Al aprobar sin
  indicar aprobador se toma el empleado del usuario o, si no tiene, el de la edición anterior.
- Veolab 3: control de documentos, fase 2 (distribución, lectura y revisión; script que crea
  `DOCDIS` y `DOCCOP`). Destinatarios de documentos y carpetas (`/documentos/destinatarios`, con
  herencia de la carpeta y resolución en personas), lecturas pendientes y vencidas
  (`/documentos/lecturas/estado` y `/pendientes`; plazo `dias_lectura` de la carpeta, 15 por
  defecto), revisión antes de aprobar (`revision_antes_aprobar` en la carpeta, `revisor_*` en el
  documento, `/documentos/revisar-edicion` y `/devolver-edicion`; especial 2 de `DOC_CTL`),
  `requiere_lectura` al aprobar y copias controladas (`/documentos/copias`). La lista de controlados
  añade lecturas («leídas/destinatarios», pendientes y vencidas), el estado de la edición en curso y
  el filtro `pendiente=revisar|aprobar`; las tareas pendientes, `DE` revisar edición y `DC` retirar
  copias, y `DL` usa los destinatarios; «Recoger datos» de la revisión por la dirección rellena el
  tema c. Sin el script, esas rutas y campos responden `422` o no aparecen.
- Veolab 3: accesos directos a documentos (`DOCFAT.FAT2DEL`/`FAT2COD`). Campos
  `acceso_directo_delegacion`, `acceso_directo_codigo` y `es_acceso_directo` en `/documentos`; alta
  con `POST /documentos` sin fichero; la descarga de un acceso entrega el destino; ruta nueva
  `/documentos/accesos`. Los accesos no aparecen en `/documentos/controlados`.
- Veolab 3: servicios exclusivos de un cliente. Al indicar `servicios` en operaciones,
  planificaciones, presupuestos, contratos y facturas, un documento sin cliente toma el de esos
  servicios; con otro cliente, o con servicios de clientes distintos, se responde `422`. No se puede
  borrar un cliente con servicios exclusivos.
- Veolab 3: listas de normativas en servicios, operaciones e informes. La API sigue exponiendo la
  normativa principal, que es la primera de la lista; cambiarla la sube a ese puesto sin perder las
  demás. Cada línea nueva toma el valor y el rango de la primera normativa con datos, y guarda
  cuál se aplicó. Un informe nuevo reúne las listas de sus operaciones.
- Veolab 3: productos sin series ni lotes (`existencias_por_series` = `F`, y todos en el inventario
  básico, `inventario_basico` en la configuración). Llevan las existencias en una serie oculta con
  código `-`, que se crea sola y se ajusta con un movimiento al cambiar las existencias del producto;
  los consumos y usos de las operaciones la toman siempre. No admiten series nuevas, el código `-`
  está reservado y un producto sin historial se puede borrar con su serie oculta. Las series con
  consumos, usos o préstamos solo se pueden dar de baja. Al recalcular, una serie sin estado cuenta
  como activa y un producto sin ninguna serie conserva sus existencias.
- Veolab 3: las fechas `SELDCAL`, `SELDVER` y `SELDMAN` de la serie son la próxima calibración,
  verificación y mantenimiento. Al grabar operaciones o resultados, borrar operaciones y grabar
  planificaciones o marcar sus fechas, si el tipo es uno de los reservados (2, 3, 4) se ponen a la
  primera fecha pendiente de su planificación o a la de una operación generada sin finalizar
  (`VeolabStock::syncMetrologyDates`). Sin planificación del tipo, la fecha es la que se ponga.
- Veolab 3: informes sustitutos. `GET /informes` devuelve, de solo lectura, `sustituye_*`,
  `motivo_sustitucion` y `es_sustituido`. Un informe sustituido no se modifica, ni se firma ni se
  borra (`422`), y deja de bloquear sus operaciones y resultados. Al borrar un sustituto, el
  original deja de estar sustituido. La sustitución se hace en el escritorio.
- Veolab 3: rangos con cálculos (`U`, `LI`, `LS`, letras de columna, `result(...)` y funciones) y
  banda de guarda (`RANBGUA`, valor ± incertidumbre) al marcar resultados, como FichaResultados.
  Un extremo que no se puede calcular deja el rango sin aplicar. Tras grabar, se vuelven a evaluar
  los resultados cuyos rangos dependen de otras celdas, salvo los de marca indicada a mano.
- Veolab 3: al borrar una operación de una solicitud de cliente, su muestra vuelve a quedar
  pendiente; al borrar un proveedor se borran sus evaluaciones y su aviso de la próxima.
- Veolab 3: notificaciones Q, K (calidad), L, T, D (solicitudes) y H (evaluación de proveedores),
  con `incidencia_*`, `solicitud_*` y `proveedor_*`.
- Veolab 3 (ISO 17025): conformidad en recepción (`conformidad_recepcion`: C, N, D o vacía) en
  operaciones y planificaciones, copiada al generar la operación; validación de métodos en
  parámetros (`estado_metodo`, `fecha_validacion_metodo`, `fecha_revision_metodo`,
  `fecha_proxima_revision`); fechas de los compromisos de confidencialidad e imparcialidad en
  empleados (`fecha_compromiso_confidencialidad`, `fecha_compromiso_imparcialidad`).
- Veolab 3: autorizaciones del personal por técnica (`/empleados/autorizaciones`), con
  `es_vigente` y `situacion` en la lectura; se borran con el empleado o la técnica. Al asignar
  automáticamente el analista (titular de la técnica), si tiene una autorización registrada no
  vigente se pasa al siguiente sustituto que no la tenga. Al grabar resultados de técnicas
  acreditadas con un analista sin autorización vigente, aviso en `avisos` o `422` según
  `autorizaciones_analistas` (`CONCAUT`) en la configuración.
- Veolab 3: equipos vencidos o fuera de uso al grabar resultados (módulo de almacén). Si una técnica
  con valores o marcas en la petición tiene registrado el uso de un equipo fuera de uso, de baja o
  con la próxima calibración, verificación, mantenimiento o caducidad alcanzada en la fecha de
  inicio de la operación, no se graba (`422`) o se añade un aviso en `avisos`, según
  `equipos_vencidos` (`CONCVEN`: vacío bloquear, `A` avisar, `N` no comprobar) en la configuración.
- Veolab 3: acreditación del laboratorio subcontratado (`numero_acreditacion`,
  `organismo_acreditacion`, `fecha_caducidad_acreditacion`, `alcance_acreditacion`) y
  `requisitos_compra` en proveedores, con `fecha_ultima_evaluacion` y `fecha_proxima_evaluacion`
  de solo lectura. Evaluaciones de proveedores (`/proveedores/evaluaciones`): la última se lleva
  al proveedor (fechas, aceptado y tipo de evaluación) y rehace el aviso de la próxima
  (notificación H, con `notificar_evaluaciones_proveedores`). Un tipo de evaluación usado en
  evaluaciones no se borra.
- Veolab 3: módulo Calidad (`/incidencias` con su estado, acciones, enlaces e implicados, y
  `/tipos-incidencia`), con el flujo de estados, el camino corto o completo, la solo lectura de las
  cerradas, el borrado limitado (las quejas nunca), la auditoría y los avisos `Q` y `K` de Veolab.
  Solo con el módulo `CAL` activo para escribir. La licencia reconoce ahora los módulos `MEN`, `CAL`
  y `SOL` de Veolab 3.
- Veolab 3: compras. Pedidos a proveedor (`/pedidos`) con sus líneas, formatos de compra, estado
  según lo recibido y requisitos de compra propuestos desde el proveedor; entregas (`/entregas`)
  que, como la ficha de entrega, crean las series o lotes (o suman a la serie oculta de los
  productos sin series), los movimientos de compra y las existencias, llevan lo recibido a los
  pedidos y actualizan los precios del proveedor; verificación de lo recibido y puesta fuera de
  uso de una entrega no conforme. Solo con el módulo `ALM` activo para escribir. Avisos del
  proveedor (evaluación vencida o no aceptado) en `avisos`.
- Veolab 3: ensayos de aptitud (`/ensayos-aptitud` con sus muestras y resultados), con los pasos
  automáticos de estado por las fechas de envío e informe, la conclusión obligatoria para cerrar,
  la solo lectura de las cerradas, el cálculo de la puntuación y la evaluación propuesta (ISO
  13528, como Veolab), «traer resultados de las operaciones» y la incidencia de la ronda
  (`POST /ensayos-aptitud/incidencia` o `abrir_incidencia`). `/incidencias/enlaces` admite la
  tabla `CALEAP`.
- Veolab 3: plan de intercomparaciones de los ensayos de aptitud. Familias de ensayos
  (`/ensayos-aptitud/familias`) con su frecuencia mínima y sus parámetros; el cuadro del plan
  (`GET /ensayos-aptitud/plan`) con lo realizado y lo previsto por año, la situación de cada
  familia, los parámetros acreditados sin familia y el resumen; la tendencia de un parámetro
  (`GET /ensayos-aptitud/tendencia`) y `aviso_tendencia` en los resultados (2 cuestionables
  seguidos o 4 puntuaciones seguidas del mismo signo). Al grabar una ronda se rehace el aviso de
  la fecha límite de envío (notificación J con `ensayo_*`, con `notificar_ensayos_aptitud`) y al
  eliminarla se borra.
- Veolab 3: auditorías internas y externas (`/auditorias-calidad`, ISO/IEC 17025 8.8) con su equipo
  auditor y auditados (en la interna el auditor jefe; en la contratada y las externas, la entidad y
  el auditor externo), apartados de la norma, delegaciones y ensayos presenciados; los hallazgos
  (incidencias enlazadas) con los abiertos; las reglas de estado (realizada con fecha de inicio,
  informe emitido con su fecha, cerrada sin hallazgos abiertos) y los avisos de independencia y de
  auditoría interna sin auditor; la cobertura del programa (`GET /auditorias-calidad/cobertura`) con
  los apartados por años y los parámetros acreditados sin ensayo presenciado. Las incidencias tienen
  `apartado` (`INCCAPA`, en hallazgos de auditoría) y `/incidencias/enlaces` admite la tabla `CALAUI`.
- Veolab 3: revisión por la dirección (`/revisiones-direccion`, ISO/IEC 17025 8.9) con sus asistentes,
  delegaciones y los quince temas de entrada; las reglas de estado (celebrada, aprobada, anulada,
  reabrir) y de borrado, solo para el responsable de calidad si se indica el usuario; «Recoger datos»
  (`POST /revisiones-direccion/recoger-datos`), que resume el periodo en los temas d-o (decisiones
  de la revisión anterior, auditorías, acciones correctivas, volumen de trabajo, quejas, mejoras,
  recursos, riesgos, validez de los resultados, formación y plazos), y las decisiones
  (`GET /revisiones-direccion/decisiones`). `/incidencias/enlaces` admite la tabla `CALRDI`.
- Veolab 3: objetivos e indicadores de calidad (`/indicadores-calidad` y `/objetivos-calidad`, ISO/IEC
  17025 8.2.1 y 8.9.2 b): los 18 indicadores automáticos de Veolab y los manuales con sus valores
  (`/indicadores-calidad/valores`), el valor calculado en un periodo (`GET /indicadores-calidad/valor`),
  objetivos anuales con valor, cumplimiento y semáforo, cerrar, reabrir, duplicar al año siguiente,
  seguimiento por trimestres y «Abrir mejora». «Recoger datos» de la revisión por la dirección
  rellena el tema b con los objetivos del año. `/incidencias/enlaces` admite la tabla `CALOBJ`. Tareas
  pendientes `OB` (objetivos del año sin definir desde febrero) y `OA` (de años anteriores sin cerrar);
  `RA` toma como referencia la última revisión celebrada o aprobada y solo la suprime una en borrador.
- Al crear operaciones, la serie de un producto sin series se usa solo si no está fuera de uso
  ni vencida, como las demás. Veolab 3: de los equipos alternativos de una técnica
  (`TYQNGRU`) solo se registra el uso de uno: el predeterminado si tiene serie utilizable, si
  no el primero del grupo que la tenga.
- Veolab 3: varias cartas de control de cada tipo por parámetro. `/cartas-control` tiene
  `numero_carta` (1 = principal) y `nombre`, y cada resultado su `fecha` (del análisis) y
  `analista_*`; `/parametros/columnas` tiene `carta_exactitud` y `carta_precision` (vacío o 1 =
  principal). Al grabar resultados, cada columna de control va a la carta de su número (la primera
  con valor de cada número) y se copian a los puntos la fecha de inicio (o fin) del análisis y el
  analista. Las cartas que se abren al llenarse heredan número y nombre. Si una columna de control
  tiene valor y el parámetro no tiene carta de ese tipo y número, se graba igualmente y se
  devuelve un aviso en `avisos` (el valor no va a ninguna carta).
- Veolab 3: criterios de cada carta de control. `reglas` (`V` las de siempre, `N` Nordtest),
  `limites` de las cartas siguientes (`C` calculados, `F` fijos, `P` porcentaje del valor central
  con `porcentaje_limite`; la desviación pasa a ser promedio × porcentaje / 300). Las cartas que se
  abren al llenarse heredan criterios y número de resultados. Quitar el estado `E` pide `motivo`
  (se añade a las observaciones). Una carta nueva cierra las abiertas del mismo parámetro, tipo y
  número. Al corregir un resultado se recalculan las incidencias de los posteriores. Los límites
  calculados no usan operaciones anuladas ni valores que no son números. En microbiología, la
  exactitud usa ±3s (la fórmula de rangos queda para la precisión).
- Veolab 3: cartas de control, fase 3. `blancos` = `T` en una carta de exactitud la convierte en
  carta de blancos: solo cuentan los valores altos (límites superiores, subidas y puntos por encima
  del valor central) y el valor central calculado es la media de los blancos (no 100); las cartas
  que se abren al llenarse lo heredan. Cada resultado lleva `lote` (del material de control) y
  `comentario`, en lectura y escritura (si no se envían, se conservan los que tenía). Cambiar
  `promedio` o `desviacion` de una carta con resultados se anota en las observaciones con los
  valores anteriores.
- Veolab 3: tareas pendientes (`GET /tareas-pendientes` y `/tareas-pendientes/recuento`), las del
  usuario indicado calculadas con las mismas consultas y reglas que el escritorio; en `/usuarios`,
  `abrir_tareas_al_iniciar` (`ACCUSU.USUBTAP`).
- Veolab 3: tareas pendientes, fase 2. Basta el módulo `COM` (las de Calidad siguen exigiendo `CAL`);
  tipos nuevos `OC` (fecha de compromiso, grupo `O` operaciones) y `MC`, `MV` y `MM` (calibrar,
  verificar y mantener equipo, grupo `M` equipos).
- Veolab 3: tareas pendientes, cuatro tipos más (26): `ST` reponer stock (grupo `M`, que pasa a
  llamarse inventario), `PS` seguimiento de presupuesto (grupo nuevo `V` ventas), `GC` revisar gráfico
  de control y `FA` participar en intercomparación (grupo `Q`).
- En bases 2.4 se mantiene lo de Veolab 2.4.5: contratos solo con importe de facturación `C` u `O`
  (y sin marcar `facturar_operaciones` por defecto) y cartas de control con tendencia de 5/4 puntos,
  el estado de la última incidencia y todas las columnas de control.
- Se quita `id_igeo` de `/parametros` y `/servicios`: sus columnas (`TECCIGC`, `SERCIGC`) se
  eliminaron en Veolab 2.1, así que siempre salía `null` y escribirlo o filtrar por él daba error 500.
- Veolab 3: con `motivo_rechazo_firmas` (`CONBCRF`, nuevo en `/configuracion/laboratorio`), rechazar
  una firma sin `comentario` responde `422`. Notificaciones W (próxima revisión de un documento
  controlado) con `documento_*`.
- En bases 2.4 vuelve lo de Veolab 2.4.5, que se había cambiado para todas: redondeo con Double (el
  de 15 cifras, que da 1,01 al redondear 1,005, es de Veolab 3), el signo tras un paréntesis en las
  fórmulas, la normativa de cada línea nueva (solo la de su servicio), la normativa de un informe
  nuevo (la del primer servicio de la operación de menor código), la serie predeterminada de
  equipos y consumibles (orden de texto) y la fecha de planificación al borrar una operación (no
  vuelve a pendiente).
- Calidad, como el escritorio: al cerrar una incidencia, las acciones con la eficacia evaluada sin
  fecha de verificación o sin quien verifica toman la fecha de hoy y el verificador de la
  incidencia (8.7.1 d). Las listas de verificación de Veolab no admiten cambios de `descripcion` ni
  de `preguntas` (`422`), y una lista nueva nunca toma los códigos 1 y 2. Los enlaces a tablas de
  código numérico se guardan sin ceros a la izquierda. El hallazgo creado desde una pregunta lo
  detecta por defecto el empleado de `usuario_*` y, si no es empleado, el auditor jefe.
- Objetivos e indicadores, como el escritorio: reabrir un objetivo conserva el valor y el resultado
  del cierre anterior; cerrarlo sin `valor` toma el calculado redondeado a los decimales del
  indicador y, si no lo hay, responde `422`; un objetivo con mejoras se puede eliminar (se quitan
  sus enlaces). El fin por defecto de un valor manual suma los meses como `DateAdd` (31-ene + 1 mes
  − 1 día = 27-feb).
- Revisión por la dirección: el periodo de una nueva solo empieza tras la última si esta acabó antes
  de hoy (si no, el año natural anterior); el filtro `anio` usa la fecha de la reunión o, sin ella,
  el fin del periodo; los patrones solo cuentan los de productos activos en uso, y la línea solo
  sale si hay alguno; el tiempo medio de cierre de quejas lleva siempre un decimal.
- Control de documentos, como el escritorio:
  - aprobar, revisar sin cambios y grabar los datos de control rehacen el aviso W de la próxima
    revisión (y obsoleto lo quita);
  - las versiones de un documento controlado no se eliminan (`422`);
  - aprobar sin cuerpo propone la edición, el motivo y la fecha que ya tenía la versión principal;
  - los datos de control solo los cambia quien puede aprobar, y no en un documento no controlado;
    una periodicidad nueva recalcula la próxima revisión desde la última;
  - con `version` distinta de la principal, un usuario con el especial 2 de `DOC_EXP` recibe `403`;
  - las carpetas nuevas heredan el acceso de la superior (y sus perfiles);
  - «Todo el personal» incluye a los empleados comunes y no cuentan los cargos de baja;
  - las lecturas solo se registran de la edición aprobada vigente de un documento no obsoleto;
  - quien elabora puede devolver una edición si puede aprobar; revisar no lo puede tampoco el autor
    de la versión, una edición aprobada no se revisa y la fecha de revisión va sin hora;
  - con edición aprobada, el estado efectivo es vigente aunque `FATCEST` diga borrador.
- Patrones y ensayos de aptitud, como el escritorio: la trazabilidad no cuenta la propia serie y
  da una fila por operación y serie usada; un patrón que ya no existe no sale como válido; sin fecha
  del uso se evalúa con hoy. Una muestra nueva sin identificación toma la primera letra libre, y
  quitarla audita también los resultados (`CALEAR`). El periodo por defecto de las operaciones
  afectadas busca la última calibración conforme entre todas, y la incidencia de equipo no conforme
  con una sola operación propone su cliente.
- En bases 2.4 vuelve el borrado de inventario de Veolab 2.4.5: un producto con cualquier serie no
  se borra, no se miran consumos, materias primas ni operaciones, no se borran movimientos y el
  código de serie «-» no está reservado.
- Inventario y proveedores, como el escritorio:
  - recalcular existencias crea el aviso S de stock mínimo (módulo COM y `CONBSTM`) para los
    productos por debajo del mínimo, también en bases 2.4; en 2.4 el recálculo vuelve al de 2.4.5
    (las series sin estado no suman y un producto sin series queda a 0);
  - la serie oculta de un producto con unidades y sin cantidad se crea con una cantidad por unidad
    de 1, y en Veolab 3 una serie sin cantidad por unidad toma como unidades la cantidad;
  - en Veolab 3, eliminar un producto borra sus formatos de compra y sus avisos, y solo los
    movimientos de su serie oculta;
  - el texto de una evaluación de proveedor es obligatorio (`422`); modificar una evaluación o una
    autorización se audita como modificación (`M`), y la situación de una autorización avisa
    «Caduca en menos de 30 días».

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
