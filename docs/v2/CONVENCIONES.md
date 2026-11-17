# Veolab API REST v2 — Convenciones de diseño

> Documento técnico de diseño de la v2: decisiones, convenciones y reglas de negocio
> replicadas de Veolab, recurso por recurso. La guía de uso está en el
> [README](../../README.md). Estado: v2 completa y en producción (octubre de 2026).

## Índice

1. [Contexto y decisión](#1-contexto-y-decisión)
2. [Arquitectura y modelo de acceso](#2-arquitectura-y-modelo-de-acceso)
3. [Autenticación](#3-autenticación)
4. [El modelo de claves de Veolab](#4-el-modelo-de-claves-de-veolab)
5. [Direccionamiento de recursos](#5-direccionamiento-de-recursos)
6. [Filtrado](#6-filtrado)
7. [Ordenación](#7-ordenación)
8. [Paginación](#8-paginación)
9. [Formato de respuestas](#9-formato-de-respuestas)
10. [Códigos de estado HTTP](#10-códigos-de-estado-http)
    - [10 bis. Bases de datos anteriores a Veolab 3](#10-bis-bases-de-datos-anteriores-a-veolab-3)
11. [Gestión documental](#11-gestión-documental)
    - [11 bis. Inventario](#11-bis-inventario)
    - [11 ter. Permisos de perfil](#11-ter-permisos-de-perfil)
    - [11 quater. Contraseña y firma de usuario](#11-quater-contraseña-y-firma-de-usuario)
    - [11 quinquies. Configuración](#11-quinquies-configuración-solo-lectura)
    - [11 sexies. Cartas de control](#11-sexies-cartas-de-control)
    - [11 septies. Notificaciones, mensajes y avisos](#11-septies-notificaciones-mensajes-y-avisos)
    - [11 octies. Agenda](#11-octies-agenda)
    - [11 nonies. Periodicidad](#11-nonies-periodicidad)
    - [11 decies. Préstamos](#11-decies-préstamos)
    - [11 undecies. Residuos](#11-undecies-residuos)
    - [11 duodecies. ISO 17025: personal, métodos, recepción y proveedores](#11-duodecies-iso-17025-personal-métodos-recepción-y-proveedores)
    - [11 terdecies. Calidad: incidencias](#11-terdecies-calidad-incidencias)
    - [11 quaterdecies. Compras: pedidos y entregas](#11-quaterdecies-compras-pedidos-y-entregas)
    - [11 quindecies. Calidad: ensayos de aptitud](#11-quindecies-calidad-ensayos-de-aptitud)
    - [11 sexdecies. Calidad: auditorías](#11-sexdecies-calidad-auditorías)
    - [11 septendecies. Calidad: revisión por la dirección](#11-septendecies-calidad-revisión-por-la-dirección)
    - [11 octodecies. Tareas pendientes](#11-octodecies-tareas-pendientes)
    - [11 undevicies. Calidad: objetivos e indicadores](#11-undevicies-calidad-objetivos-e-indicadores)
12. [Fixes de corrección a arrastrar de la v1](#12-fixes-de-corrección-a-arrastrar-de-la-v1)
    - [12 bis. Auditoría, códigos y Verifactu](#12-bis-auditoría-códigos-y-verifactu) (incluye operaciones, planificaciones, lotes, órdenes, informes, resultados, facturación, relaciones, subtablas y maestros)
13. [Decisiones tomadas y pendientes](#13-decisiones-tomadas-y-pendientes)

---

## 1. Contexto y decisión

- La v1 (documentada como `1.0.0`) está desplegada en `https://api.veolab.es` pero
  **sin dependencias activas**: último uso de cualquier token hace ~8 meses
  (25-ene-2026), y los accesos previos fueron pruebas del desarrollador (id 1) y
  3 evaluaciones externas esporádicas (ids 2, 4, 5). Ninguna integración viva.
- **Decisión: ruptura limpia.** La v2 es la nueva base; no se mantienen dos
  versiones evolucionando en paralelo.
- **La v2 vive bajo prefijo de versión:** `https://api.veolab.es/api/v2/...`
  (`Route::prefix('v2')`). Motivo: la v1 no tenía versión y eso obligó a este
  rediseño; versionar desde ahora evita repetir la encrucijada en el próximo
  cambio rompedor.
- Las rutas v1 (`/api/...` sin versión) ya no existen en el servidor: la v1 queda
  archivada en la etiqueta git `v1.0.0`. Solo `login`, `logout` y `refresh` siguen
  sin versión.

## 2. Arquitectura y modelo de acceso

Multi-tenant con **dos niveles de base de datos**:

| BD | Contenido | Conexión Laravel |
|---|---|---|
| **Central** (`DB_DATABASE`, p. ej. `veolabapi`) | Tablas propias de Laravel: `users`, `personal_access_tokens`, migrations, cache, jobs, sessions | Por defecto (`mysql`) |
| **Una por laboratorio** (nombre = `users.name`) | Tablas reales de Veolab (`SINCLI`, `LABOPE`, `DOCFAT`, `ACCUSU`…) | `dynamic`, conmutada por petición en el middleware |

**Modelo de acceso:** un token = un laboratorio (tenant) con **acceso total** a su
BD. La API **no implementa privilegios de usuario final**; esa capa es
responsabilidad de la aplicación consumidora (p. ej. la app web). No hay `403`
por permisos.

## 3. Autenticación

Las rutas de autenticación van sin versión (`/api/login`, no `/api/v2/login`).

- `POST /api/login` — cuerpo `{ "name": "<bd_laboratorio>", "password": "..." }` →
  `{ "token": "...", "expires_at": "2026-11-01T10:00:00+00:00" }`. Credenciales
  incorrectas: `401`. Límite de **5 intentos por minuto** por laboratorio e IP (y 20
  por IP): al superarlo, `429` con cabecera `Retry-After`.
- `POST /api/logout` — revoca el token actual (requiere Bearer)
- `POST /api/refresh` — revoca el actual y emite uno nuevo con la misma respuesta que
  el login (requiere Bearer)
- **Caducidad:** los tokens caducan a los 30 días (`SANCTUM_EXPIRATION` en minutos en
  el `.env`; vacío = sin caducidad). Un token caducado da `401`; el cliente debe
  renovarlo con `/refresh` antes de `expires_at` o volver a hacer login. Los tokens
  caducados se borran a diario (`sanctum:prune-expired`, requiere el cron de
  `schedule:run`).
- El resto de endpoints requieren cabecera `Authorization: Bearer <token>`.
- La API responde **siempre en JSON** aunque el cliente no envíe
  `Accept: application/json` (sin token: `401 {"message": "No autenticado"}`).
- El middleware conmuta la conexión `dynamic` a la BD cuyo nombre es `users.name`.

## 4. El modelo de claves de Veolab

Los nombres de columna son autodescriptivos: **el 4º carácter indica el rol** de la
columna. Para claves:

| Sufijo | Significado | Ejemplo |
|---|---|---|
| `xxx1yyy` | Campo propio identificador (parte de la PK propia) | `OPE1COD`, `OPE1SER`, `USU1COD` |
| `xxx2yyy` | Referencia externa **simple** (FK que NO es parte de la PK) | `CLI2COD`, `TAR2COD` |
| `xxx3yyy` | Referencia externa que **forma parte de la PK** | `DEL3COD`, `OPE3SER`, `TEC3COD` |

(En campos no clave el 4º carácter es el tipo: `C`har, `N`umérico, `B`oolean,
`D`ate, `T`datetime.)

Las 143 tablas siguen **5 patrones de PK**:

| Patrón | Nº claves | Composición | Ejemplos | Tablas |
|---|---|---|---|---|
| Simple | 2 | `DEL3COD + XXX1COD` | ACCUSU, DOCFAT, SINCLI | 53 |
| Con serie | 3 | `DEL3COD + XXX1SER + XXX1COD` | LABOPE, LABORD, LABINF, FACFAC | 24 |
| Relación N:N (2 entidades simples) | 4 | `AAA3DEL,AAA3COD + BBB3DEL,BBB3COD` | LABTYC, PLAPYC | 35 |
| Relación con entidad-serie | 5 | `(del+ser+cod) + (del+cod)` | LABRES, LABOYS, LABORE | 12 |
| Relación entre dos entidades-serie | 6–7 | `(del+ser+cod) + (del+ser+cod)[+aux]` | LABOYO, LABIYO, LABFIR | 5 |

> La forma de la PK (y por tanto el direccionamiento, orden y cursor de cada tabla)
> es **deducible del esquema**. Se puede generar por tabla en lugar de picarlo a mano.

## 5. Direccionamiento de recursos

**Claves con nombre en query string** (no posicional en la ruta). Cada parte de la
clave es un parámetro nombrado, con los nombres "humanos" del mapping del
controlador (`delegacion`, `serie`, `codigo`, y para relaciones el prefijo de la
segunda entidad, p. ej. `tecnica_delegacion`, `tecnica_codigo`).

**Regla única (decidida — Opción X):** el endpoint de colección **siempre devuelve
`{data, meta}`**.
- **clave completa presente →** `data` con 1 elemento (o `data` vacío si no existe; **nunca `404`** en la lectura),
- **clave parcial o ausente →** listado filtrado.

Direccionar es, por tanto, un caso particular de filtrar: mismo endpoint, misma forma
de respuesta. No hay endpoint "show" aparte ni segunda semántica.

```
GET /api/v2/operaciones?delegacion=DEL001&serie=25            → listado de la serie 25
GET /api/v2/operaciones?delegacion=DEL001&serie=25&codigo=1   → una operación (o data vacío)
GET /api/v2/clientes?delegacion=DEL001&codigo=5               → un cliente (tabla de 2 claves)
GET /api/v2/resultados?operacion_delegacion=DEL001&operacion_serie=25&operacion_codigo=1
                      &tecnica_delegacion=DEL001&tecnica_codigo=PH   → una técnica de la operación (5 claves)
```

Ventajas para Veolab: la **delegación vacía** (labs sin delegaciones) se resuelve
sola (se omite o va vacía); escala a claves de 2 a 7 columnas sin ambigüedad; es
autoexplicativo. Escrituras (decidido): `POST` lleva los datos en el cuerpo (incluida
la clave si se aporta); **`PUT`/`DELETE` llevan la clave en query string**, igual que
las lecturas.

## 6. Filtrado

- **Whitelist automática = el `$mapping`** del controlador. Solo se filtra por
  campos mapeados; lo no mapeado se ignora. Evita inyección y filtrado por
  columnas internas.
- **Operadores** (sintaxis de corchetes, que PHP parsea como array anidado):

| Intención | Query string | SQL |
|---|---|---|
| Igualdad | `?estado=2` | `estado = 2` |
| Conjunto (IN) | `?estado=5,6,7` | `estado IN (5,6,7)` |
| Rango | `?fecha_registro[gte]=2025-01-01&fecha_registro[lte]=2025-01-31` | `>= AND <=` |
| Texto parcial | `?descripcion[like]=agua` | `LIKE '%agua%'` |
| Distinto | `?estado[ne]=7` | `<> 7` |
| Nulo / no nulo | `?fecha_baja[null]=T` | `IS NULL` (`F`: `IS NOT NULL`) |
| Mayor / menor estricto | `?precio[gt]=10&precio[lt]=50` | `> AND <` |

- **Booleanos:** Veolab usa `'T'`/`'F'` (no true/false). Los filtros aceptan `T`/`F`
  (`?es_urgente=T`), coherente con la validación `in:T,F`.
- **Fechas:** ISO `yyyy-mm-dd` (o `yyyy-mm-dd hh:mm:ss`), rangos inclusivos. En
  columnas con hora (`OPETREC`), `[lte]=2026-01-31` es hasta las 00:00 de ese día:
  usar `[lt]=2026-02-01` para incluirlo entero.
- Un valor imposible para la columna (p. ej. una fecha mal escrita) → `422`
  "Parámetros de consulta no válidos". Un operador desconocido o con lista se ignora.
- **Coma = IN** solo para campos sin comas (códigos, estados). Para texto, usar
  `[in]` explícito o no permitir IN.
- `is_deleted` y `search` de la v1 pasan a ser casos particulares de este mecanismo
  (filtro sobre el campo de baja / LIKE multicampo).

## 7. Ordenación

- Parámetro `sort`, con dirección: `?sort=fecha_registro&order=desc` o compacto
  `?sort=-fecha_registro`. Multi-campo: `?sort=estado,-codigo`.
- **Whitelist de ordenables = el mapping.**
- **Orden por defecto = la PK completa** (p. ej. `delegacion, serie, codigo`).
- La **PK se añade siempre como desempate final**, aunque se ordene por otro campo,
  para que el orden sea 100% determinista (requisito de la paginación).

## 8. Paginación

Implementada la paginación por páginas (8.1). La de cursor (8.2) está **diseñada pero
pospuesta**: hoy cada página cuenta el total y recorre las anteriores, suficiente con
filtros; si una tabla crece mucho, se añadirá `after` (ya reservado) y `total=F`.

### 8.1 Offset (por defecto, para UI y uso normal)

- Parámetros `page` (1‑based) y `limit`.
- **`ORDER BY` obligatorio por la clave compuesta** (corrige el bug de la v1, que
  paginaba sin orden → páginas inconsistentes).
- `limit`: **casteo a entero**, mínimo 1, por defecto **25** y **tope de 100**. Con el envoltorio de respuesta, el recorte es seguro: `meta` revela que
  hay más páginas, así que el cliente nunca se queda a ciegas.
- Con clave múltiple no hay complicación: solo alarga el `ORDER BY`.

```
GET /api/v2/operaciones?limit=200&page=2
```

### 8.2 Keyset / cursor (pospuesto: diseño para lectura masiva de tablas grandes)

- El cursor es la **tupla completa de la clave** de la última fila, entregada al
  cliente como **token opaco** (`after=...`); él solo lo reenvía. Oculta la
  complejidad de claves de Veolab.
- Comparación de fila (row-value), que usa el índice de la PK y no se degrada en
  páginas profundas:

```sql
-- 3 claves
WHERE (delegacion, serie, codigo) > ('DEL001','25',1200)
ORDER BY delegacion, serie, codigo LIMIT 200
```
```sql
-- 5 claves: misma idea, tupla más larga
WHERE (ope_delegacion, ope_serie, ope_codigo, tec_delegacion, tec_codigo) > (...)
ORDER BY ope_delegacion, ope_serie, ope_codigo, tec_delegacion, tec_codigo LIMIT 200
```

- **Tipos:** la (de)serialización del cursor debe respetar el tipo de cada columna
  (código numérico vs texto; `'100' < '99'` si se compara como texto).
- **Delegación vacía:** `''` es un valor válido en la comparación; no es caso
  especial.
- **Orden por campo no clave** (p. ej. `-fecha_registro`): el cursor incluye ese
  campo + la PK como desempate, y la comparación pasa a la forma expandida
  (`fecha < ? OR (fecha = ? AND (pk) > (...))`). Rinde bien solo si hay índice que
  cubra ese orden; para exportación, ordenar por PK (siempre indexada).

> Leer una tabla entera = paginar hasta `last_page` (offset) o hasta agotar el
> cursor (keyset). El tope de `limit` acota cada página, no el total accesible.

## 9. Formato de respuestas

- **Listado (colección): envoltorio `{data, meta}`.**

```json
{
  "data": [ { "delegacion": "DEL001", "serie": "25", "codigo": 1, "estado": 5 } ],
  "meta": { "total": 342, "page": 2, "per_page": 50, "last_page": 7, "version_bd": "3.0" }
}
```
(Con keyset, cuando se implemente, `meta` llevará `next_cursor` y `per_page`.)
`version_bd` es la versión de la base de datos del laboratorio (§10 bis).

- **Registro único:** no hay forma aparte (Opción X); se pide con la clave completa y
  llega como `{data, meta}` con `data` de 1 elemento. `data` vacío = no existe.
- **Creación (`POST`):** `{ "message": "...", "data": { <clave del nuevo registro> } }`
  (`201`). Si no se envía código, la API genera el siguiente y lo devuelve.
  Excepción: en **usuarios** el código es el nombre de inicio de sesión y es
  obligatorio (sin puntos ni `¶`), como en Veolab.
- **Actualización/borrado:** `{ "message": "..." }` (`200`).
- Los nombres de campo son siempre los "humanos" del mapping, nunca los internos
  (`OPE1COD` → `codigo`).
- **JSON compacto** (sin sangrado): el formateo es cosa del cliente (`| jq`, Postman).
- **Códigos de texto:** las 19 tablas con `XXX1COD varchar` (clientes, productos,
  equipos, normativas, usuarios, lotes…) exponen el código como texto (`"4"`) y se
  ordenan alfabéticamente, igual que Veolab. Las otras 77 tienen código `int`.
- **Los valores se devuelven tal cual** están en la BD (no se recortan espacios).

### 9.1 Claves foráneas vacías

Veolab guarda una FK vacía como `0` (código `int`) o `''` (código texto), no como
`NULL`, aunque el esquema declare `DEFAULT NULL`. La API lo normaliza:

- **Lectura:** si el código de la FK está vacío (`NULL`, `0` ó `''`), **todo el grupo**
  (`{fk}_delegacion`, `{fk}_serie`, `{fk}_codigo`) sale como `null`. El vacío lo decide
  el código, porque la delegación `''` es un valor válido.
- **Escritura:** `null` se guarda como `0` / `''` según el tipo (lo que espera VB6).
  Si el código llega vacío se vacía el grupo entero. En la creación, las FK no
  enviadas también se rellenan así.
- **Filtro:** `?{fk}_codigo[null]=T` (o sobre cualquier miembro del grupo) encuentra
  `NULL`, `0` y `''`; `[null]=F`, lo contrario.
- Cada controlador declara sus grupos en `$foreignKeys` (`grupo => 'int'|'string'`).

## 10. Códigos de estado HTTP

| Código | Uso |
|---|---|
| `200` | OK (lectura, actualización, borrado) |
| `201` | Creado |
| `400` | Falta una parte de la clave en la query string (`PUT`/`DELETE` y rutas con clave obligatoria) |
| `401` | Token ausente o inválido |
| `404` | Recurso no encontrado |
| `405` | Método no permitido en esa ruta |
| `413` | Fichero demasiado grande (límite del servidor web/PHP) |
| `422` | **Validación**, reglas de negocio (relación inexistente, código duplicado…) o filtro imposible |
| `429` | Demasiados intentos de login |
| `500` | Error inesperado del servidor, o sin conexión con la BD del laboratorio |

- **No se usa `403`** (no hay capa de permisos en la API).
- **Nunca** se filtran mensajes internos de excepción al cliente. Errores de
  validación en formato estándar Laravel `{ "message": ..., "errors": { campo: [...] } }`;
  el resto, `{ "message": ... }`. El detalle interno va a `Log`, no a la respuesta.
- Los `500` (capturados con `ServerError::response()` o no controlados, vía
  `bootstrap/app.php`) añaden `referencia`, que aparece en el registro como
  `[ref xxxxxxxx]` junto con la excepción completa. Si `storage/logs` no se puede
  escribir, se usa el `error_log` de PHP: un fallo del registro no tapa el error.
  (Corrige el `{error, detalle}` de la v1, que devolvía 500 en validación y filtraba
  `$e->getMessage()`.)

## 10 bis. Bases de datos anteriores a Veolab 3

La API sirve a todos los laboratorios del servidor y cada uno actualiza Veolab cuando
quiere, así que conviven bases de datos 2.4 y 3.0 (`ACCPAR.PARCVBD`). El contrato de la
v2 no cambia: lo de Veolab 3 es aditivo y la API se adapta a cada base de datos
(`App\Support\VeolabVersion`, una consulta por petición):

- **Campos nuevos** (marcados «desde Veolab 3» en este documento y declarados en `$since`
  de cada controlador): en una base anterior no se devuelven, no filtran ni ordenan, y
  enviarlos en `POST`/`PUT` da `422` («El campo 'x' requiere la base de datos de Veolab 3
  (esta base de datos está en la versión 2.4)»).
- **Recursos nuevos** (tablas que no existen antes: `/envases`, `/lugares`,
  `/temperaturas`; `$sinceVersion`): `422` con el mismo texto para cualquier método.
- **Comportamientos**: mensajería (módulo `COM` sin tipo ni lectura antes de 3.0, `MEN`
  después), contraseñas (cifrado anterior antes de 3.0, hash PBKDF2 después), reglas de
  subclientes y predeterminados o fórmulas de autodefinibles solo en 3.0.
- `meta.version_bd` lleva la versión en todos los listados y `GET /configuracion/general`
  la da en `version_bd`, para que la aplicación cliente sepa de antemano qué admite cada
  laboratorio.

**Seguimiento comercial (`CLISEG`, `CLITSE`).** `App\Support\VeolabFollowUp` replica `Seguimiento.bas`:
registra los sucesos automáticos de presupuestos y facturas y borra los del cliente eliminado. Comprueba
versión y tablas, y un fallo no impide la operación. Aún no hay recursos para consultarlos o crearlos.

## 11. Gestión documental

Replica `Documentos.bas` y el explorador de Veolab. El filtrado por privilegios
de usuario final (`DIRCCOM`, `DOCDYP`) lo hace la app consumidora, no la API.

| Tabla | Rol | Recurso |
|---|---|---|
| `DOCDIR` | Carpetas (`DIRCTAB` = tabla de la funcionalidad, `DIRCCOM` = compartición, jerarquía `DIR2*`) | `/documentos/carpetas` |
| `DOCDYP` | Perfiles con los que se comparte una carpeta (modo `P`) | `/documentos/carpetas/perfiles` (GET/POST/DELETE) |
| `DOCFAT` | Documento: nombre, extensión, carpeta y entidad vinculada; también los accesos directos (Veolab 3, `FAT2DEL`/`FAT2COD`) | `/documentos`, `/documentos/accesos` (GET) |
| `DOCVER` | Versiones | `/documentos/versiones` (GET/PUT/DELETE) |
| `DOCBLO` | Contenido en trozos binarios | `/documentos/contenido` (GET descarga, POST sube) |
| `DOCLEC` | Lecturas de documentos controlados (Veolab 3) | `/documentos/lecturas` (GET/POST), `/documentos/lecturas/estado` y `/pendientes` (GET) |
| `DOCDIS` | Destinatarios de un documento o carpeta controlada (Veolab 3, fase 2) | `/documentos/destinatarios` (GET/PUT) |
| `DOCCOP` | Copias controladas en papel (Veolab 3, fase 2) | `/documentos/copias` |

**Carpetas.** Las raíces (`carpeta_padre` vacía) las crea Veolab, una por
funcionalidad (`SINCLI`, `LABOPE`… y `ZZZDIR` para las carpetas generales); no
se modifican ni se borran. Una subcarpeta hereda la `tabla` de su padre, no se
mueve, y una carpeta pública (delegación vacía) solo cuelga de otra pública.
Solo se borran carpetas vacías. Las plantillas de exportación (`PLAPLA`) se
gestionan desde Veolab.

**Vínculo.** Un documento está en una carpeta (`carpeta_delegacion` +
`carpeta_codigo`) y puede vincularse a **una** entidad de su misma delegación:
`cliente_codigo`, `proveedor_codigo`, `tecnica_codigo`, `equipamiento_codigo`,
`empleado_codigo`, `curso_codigo`, `operacion_serie`+`operacion_codigo`, orden,
informe, lote, `planificacion_codigo`, `agenda_serie` (usuario)+`agenda_codigo`,
contrato, presupuesto, factura, `producto_codigo` (+`producto_serie_lote_codigo`
para una serie o lote), `carta_control_codigo`, `prestamo_codigo`, pedido (`pedido_serie`+`pedido_codigo`),
`entrega_codigo`, incidencia, solicitud, `ensayo_serie`+`ensayo_codigo` (ensayo de
aptitud), `auditoria_calidad_serie`+`auditoria_calidad_codigo` y
`revision_direccion_serie`+`revision_direccion_codigo`; estas siete solo con base
de datos 3.0 (antes no existen en `DOCFAT`). La carpeta
debe ser de la tabla de la entidad (o `ZZZDIR` si no hay entidad); sin carpeta
va a la raíz de esa tabla. En un PUT los parámetros de entidad sustituyen el
vínculo entero; si cambia la tabla y no se indica carpeta, pasa a su raíz. El
listado añade `tabla` (la de la entidad) y `en_papelera`.

**Papelera** = carpeta 0 (`?carpeta_codigo[null]=T`). `DELETE /documentos` la
manda a la papelera; con `definitivo=T`, o si ya estaba en ella, se borra con
sus versiones y bloques (Veolab no borra los bloques; la API sí). Para
recuperarla, PUT con otra carpeta.

**Alta y contenido (multipart/form-data, campo `fichero`):**
```
POST /api/v2/documentos                         → documento nuevo (versión 1)
POST /api/v2/documentos/contenido?delegacion=&codigo=   → contenido nuevo
GET  /api/v2/documentos/contenido?delegacion=&codigo=[&version=][&inline=T]
```
- El alta acepta `delegacion`, la carpeta, la entidad, `nombre`/`extension`
  (por defecto los del fichero), `descripcion`, `es_solo_lectura`,
  `es_control_versiones`, `version_nombre`, `version_descripcion` y el autor
  (`usuario_delegacion` + `usuario_codigo`). Se comprime si `ACCPAR.PARBZIP`.
- Contenido nuevo, como al guardar en Veolab: con control de versiones, versión
  nueva (`nueva_version=F` sobrescribe la actual); si no, con versión dual
  (`PARBDUA`), versión nueva borrando la dual anterior; si no, se sobrescribe.
  No se admite en documentos de solo lectura ni en la papelera.
- `PUT /documentos/versiones` con `es_actual=T` restablece una versión; la
  actual no se puede borrar.

**Almacenamiento.** Trozos de 65534 bytes (`DBS_MAX_BUFFER_BLOB`) ordenados por
`BLO1COD`, del contador `ACCCLT 'DOCBLO'` de la delegación, reservando de una
vez los códigos del fichero fuera de la transacción del alta (como Veolab). Si
está comprimido (`FATBZIP`, o `VERBZIP` de la versión pedida) es un ZIP estándar
(Info-ZIP) con una única entrada `FATCNOC.FATCTIP`: la API extrae esa única
entrada sin fiarse del nombre (Veolab no lo actualiza al renombrar). Al
escribir, el `unzip32.dll` de Veolab no entiende los nombres UTF-8: en las
altas `FATCNOC` es el nombre en ASCII y la entrada va en CP850 sin la marca
UTF-8, como Info-ZIP en Windows. La
descarga se sirve en streaming bloque a bloque (`Content-Type` por la
extensión, `Content-Disposition` con el nombre).

**Auditoría** como Veolab: `I`/`B` de `DOCFAT` y `DOCDIR` con la fila
"del-cod-nombre", `M` de `DOCBLO` al cambiar el contenido, `M` de `DOCVER` al
restablecer una versión (fila "nombre.ext - del-versión"), `F`/`C` de las
propiedades.

**Límite de tamaño:** lo marcan `upload_max_filesize` y `post_max_size` de
PHP-FPM y `client_max_body_size` de Nginx; un cuerpo mayor da 413.

### Control de documentos (Veolab 3, ISO 17025 8.3)

Fase 1, según el modelo común con el escritorio (`Documentacion/ISO 17025/Control de
documentos - modelo de datos.md` del proyecto de Veolab; `App\Support\VeolabDocumentControl`,
`DocumentoControlController`, `DocumentoLecturaController`). Es una capa sobre la gestión
documental. Todos los campos llevan `$since` 3.0 y las rutas nuevas responden `422` si la base de
datos no es de Veolab 3 o aún no tiene `DOCLEC` (script sin aplicar).

**Módulo Calidad.** Como en el escritorio (`DCT_Activo`), el control solo actúa con el módulo
Calidad activo y licenciado (`ACCMOD.MODBACT = 'T'` para `CAL`; `VeolabDocumentControl::active()`,
leído una vez por petición; la funcionalidad `DOC_CTL` pertenece a `CAL` en `ACCMYF`). Sin él:
ninguna carpeta ni ningún documento está controlado (`es_controlado`, `es_controlada` y
`pendiente_aprobar` son `F`, `?es_controlado=T` no devuelve nada) y se comportan como documentos
normales con versiones; el alta y la subida en una carpeta controlada no activan el control;
los campos de control de `/documentos`, `/documentos/versiones` y `/documentos/carpetas` se leen
pero escribirlos da `422`; y `/documentos/controlados`, aprobar, revisar, descartar y
`/documentos/lecturas` responden `422` «El control de documentos requiere el módulo Calidad». La
edición aprobada vigente sigue sin poder borrarse, para no perder datos si se vuelve a activar.

**Campos.**

- `/documentos`: `controlado` (`FATCCTL`: null = según la carpeta, `T`, `F`), `codigo_documento`,
  `tipo_documento` (`I`/`E`), `entidad_emisora`, `estado_control` (`B` borrador, `V` vigente,
  `O` obsoleto), `version_aprobada` (`VER2APR`, solo lectura), `elabora_delegacion` +
  `elabora_codigo` (empleado), `periodicidad_revision` (meses), `ultima_revision`,
  `proxima_revision` y `fecha_vigencia`. Calculados: `es_controlado` (efectivo) y
  `pendiente_aprobar`; también como filtros `?es_controlado=T|F` y `?pendiente_aprobar=T|F`.
  `?search=` busca también en el código.
- `/documentos/versiones`: `edicion`, `motivo`, `fecha_edicion`, `aprobada_por_delegacion` +
  `aprobada_por_codigo` y `fecha_aprobacion`; calculado `es_aprobada`.
- `/documentos/carpetas`: `controlada` (`DIRBCON`), `tipo_documentos` y `periodicidad_revision`
  (se pueden cambiar también en las raíces, solo ellos); calculado `es_controlada` (con herencia).

**Controlado.** `FATCCTL = T`, o vacío y su carpeta o alguna superior con `DIRBCON = T`;
`F` lo excluye siempre. Sin CTE recursivas: el árbol de `DOCDIR` se lee una vez por petición y se
sube en PHP por `DIR2DEL`/`DIR2COD`; los listados filtran con la lista de carpetas controladas
(las marcadas y sus descendientes). El tipo y la periodicidad por defecto son los de la carpeta
más cercana que los tenga.

**Usuario y privilegios.** La API no tiene sesión: `?usuario_delegacion=&usuario_codigo=` es
opcional. Con él, cambiar `controlado` (documento o carpeta), marcar obsoleto o volver a vigente,
aprobar, revisar y corregir los datos de una edición aprobada exigen el privilegio especial 1 de
`DOC_CTL` (aprobar documentos); descartar, ese privilegio o ser quien lo elabora. Sin usuario la
API actúa como privilegiada, como en calidad.

**Reglas.**

- Al pasar a controlado (por el documento, al moverlo a una carpeta controlada o al marcar la
  carpeta, que alcanza a sus subcarpetas): `FATBVER = T`, la copia dual (`VER2DUA`) pasa a versión
  visible con el nombre «Versión anterior» y `VER2DUA = 0`, estado `B` si no tenía y el tipo de la
  carpeta si no tenía. Un documento nuevo en una carpeta controlada nace así. Quitar el control
  de versiones a un controlado da `422`.
- Activar el control de versiones a un documento no controlado también convierte la copia dual en
  versión visible.
- `POST /documentos/contenido` en un controlado: siempre versión nueva, que pasa a principal
  (`nueva_version=F` da `422`; la dual no actúa); si ya tenía edición aprobada queda pendiente de
  aprobar (`data.pendiente_aprobar`). En un obsoleto, `422`. Con control de versiones, sin
  `version_nombre`, la versión se llama «Versión N» (también la primera, «Versión 1»).
- `GET /documentos/contenido` sin `version`: si se indica el usuario y el documento es un
  controlado pendiente de aprobar (no borrador), quien no lo elabora, no puede aprobar y no tiene
  el especial 3 de `DOC_EXP` recibe la edición aprobada (`VER2APR`); en otro caso, la principal.
  La cabecera `X-Documento-Version` dice cuál se ha entregado.
  Con `version` distinta de la principal y un usuario con el especial 2 de `DOC_EXP` (solo la
  versión principal), `403`. `GET /documentos/versiones` no lo comprueba: allí `usuario_*` filtra
  por el autor de la versión.
- Veolab 3, como PropiedadesDocumento: las versiones de un documento controlado no se eliminan
  (`422`); en un controlado, los datos de control (código, tipo, emisor, periodicidad, elabora,
  próxima revisión y revisor) solo los cambia quien puede aprobar, y en uno no controlado no se
  cambian (`422`); una periodicidad nueva sin `proxima_revision` recalcula la próxima revisión desde
  la última. Al aprobar, revisar sin cambios o grabar los datos de control se rehace el aviso W de
  la próxima revisión (`CONNRDC` días antes, módulo COM).
- `estado_control` en `PUT /documentos`: `O` (obsoleto) desde cualquier estado; `V` solo desde
  obsoleto (sin edición aprobada queda en `B`); nunca vuelve a `B` (para eso, descartar). A `V`
  desde borrador solo se llega aprobando.
- `PUT /documentos/versiones`: en un controlado no se restablece una versión anterior
  (`es_actual`; la última es siempre la principal; se usa descartar). Los datos de edición solo
  se corrigen en versiones aprobadas. La edición aprobada vigente no se puede borrar.

**Rutas.**

```
GET  /api/v2/documentos/controlados[?delegacion=&estado=B,V,P,O&tipo=I|E&carpeta_delegacion=&carpeta_codigo=
                                   [&subcarpetas=F]&revision_vencida=T|F&revision_proxima=T
                                   &elabora_delegacion=&elabora_codigo=&texto=&sort=&page=&limit=]
POST /api/v2/documentos/aprobar?delegacion=&codigo=[&usuario_*]    {edicion, motivo, fecha_edicion,
                                                                    aprobada_por_delegacion, aprobada_por_codigo}
POST /api/v2/documentos/revisar?delegacion=&codigo=[&usuario_*]
POST /api/v2/documentos/descartar?delegacion=&codigo=[&usuario_*]
GET  /api/v2/documentos/lecturas[?delegacion=&documento=&version=&empleado_delegacion=&empleado_codigo=]
POST /api/v2/documentos/lecturas   {delegacion, documento, [version], empleado_* | usuario_*, [fecha]}
```

- **Lista** (`/documentos/controlados`): los controlados fuera de la papelera con código, título,
  tipo, edición vigente y su fecha, motivo, estado efectivo (`B`, `V`, `P` pendiente de aprobar,
  `O`) y su nombre, aprobado por (con nombre) y fecha de aprobación, quien lo elabora, periodicidad
  efectiva, última y próxima revisión, `revision_vencida` y `revision_proxima` (30 días; nunca en
  obsoletos), vigencia comprobada, carpeta y delegación. `estado` admite lista; `carpeta_*` incluye
  las subcarpetas; `texto` busca en código, título, descripción, entidad emisora y edición. Orden
  por `codigo_documento` (por defecto), `titulo`, `tipo_documento`, `fecha_edicion`,
  `fecha_aprobacion`, `proxima_revision`, `ultima_revision`, `carpeta_nombre`, `delegacion` o
  `estado`, con `-` para descendente. `limit` hasta 500. La API no aplica el acceso a carpetas
  (`DOC_CarpetaCompartida`): lo filtra la aplicación cliente, como en el resto de la gestión
  documental.
- **Aprobar**: la versión principal pasa a ser la edición aprobada vigente. En ella `VERCEDI`
  (obligatoria), `VERCMOT`, `VERDEDI` (por defecto hoy), `EMA2*` (por defecto el empleado del
  usuario; si no tiene, quien aprobó la edición vigente anterior; si tampoco, ninguno;
  `aprobada_por_codigo` 0 o null, ninguno) y `VERDAPR` = hoy; en el documento
  `VER2APR = VER2COD`, `FATCEST = V`, `FATDULR` = hoy y `FATDREV` = hoy + periodicidad (la del
  documento o la de la carpeta; vacía si no hay). `422` si está obsoleto o la principal ya es la
  aprobada.
- **Revisar sin cambios**: `FATDULR` = hoy y se recalcula `FATDREV`; en los externos, también
  `FATDVIG` = hoy. Solo con edición aprobada y no obsoleto.
- **Descartar cambios**: borra las versiones posteriores a `VER2APR` (`DOCVER`, `DOCBLO` y sus
  lecturas) y `VER2COD = VER2APR`. `422` sin edición aprobada o sin cambios. El escritorio pide
  confirmación; la API no.
- **Lecturas**: solo de controlados no obsoletos y de la edición aprobada vigente (`VER2APR`), como `DCT_LecturaPendiente`, una por
  documento, versión y empleado (`422` si ya está). Con `usuario_*` se toma su empleado; un usuario
  sin empleado no registra. `fecha` por defecto ahora. El listado añade `empleado_nombre` y
  `edicion`. Al borrar un documento o una versión se borran sus lecturas.

**Sin hacer:** «Rellenar desde el nombre» (código, título y edición del nombre del fichero; solo en
el escritorio), el aviso de revisión (notificación `W`, la genera Veolab), ocultar los obsoletos en
`/documentos` (la app filtra con `estado_control`) y el acceso a carpetas en la lista.

### Control de documentos, fase 2: distribución, lectura, revisión y copias (Veolab 3)

Según `Documentacion/ISO 17025/Analisis distribucion de documentos.md` del proyecto de Veolab
(modelo en su apartado 5), a la vez que el escritorio. Necesita el script que crea `DOCDIS` y
`DOCCOP` y las columnas `DOCDIR.DIRNDLE`/`DIRBREV`, `DOCFAT.EMR2*` y `DOCVER.VERBLEC`/`EMR2*`/
`VERDREV`/`VERCREV`/`VERCEST` (`VeolabDocumentControl::distributionAvailable()`, comprobado con
`hasTable`/`hasColumn` una vez por petición). Sin él, las rutas nuevas responden `422` «…requieren
el script de conversión que crea DOCDIS y DOCCOP», los campos nuevos no aparecen (como los de
`$since` en bases anteriores) y todo sigue como en la fase 1. Como la fase 1, exige el módulo
Calidad.

**Campos nuevos.**

- `/documentos`: `revisor_delegacion` + `revisor_codigo` (`EMR2*`, grupo FK): un empleado distinto
  de quien lo elabora.
- `/documentos/carpetas`: `dias_lectura` (`DIRNDLE`; vacío o 0 = el de la carpeta superior más
  cercana que lo tenga o 15) y `revision_antes_aprobar` (`DIRBREV`, `T`; vale también para las
  subcarpetas; cambiarla exige el especial 1 de `DOC_CTL`); calculados `es_revision_antes_aprobar`
  y `dias_lectura_efectivos`.
- `/documentos/versiones` (solo lectura): `estado_edicion` (`VERCEST`: `B` borrador, `R` revisada,
  `A` aprobada; vacío = `A` con fecha de aprobación, si no `B`), `revisada_por_*`,
  `fecha_revision`, `comentario_revision` (o motivo de la devolución) y `requiere_lectura`
  (`VERBLEC`; vacío = `T`).

**Destinatarios.** `DOCDIS` con `FAT2COD` (de un documento) o `DIR2COD` (de una carpeta), en la
delegación del documento o de la carpeta. Un documento sin destinatarios propios usa los de su
carpeta o los de la primera carpeta superior que los tenga; sin ninguno no se distribuye (acuse
voluntario, como en la fase 1). Se resuelven en empleados en activo (`EMPBBAJ <> T`), sin repetir y
por nombre:

| `DISCTIP` | Quién |
|---|---|
| `T` | Los empleados de la delegación del documento, o todos si el documento es común |
| `E` | Ese empleado (`EMP2*`) |
| `C` | Quien tiene el cargo (`CAR2*`, `GRHEYC`) |
| `D` | Quien tiene un cargo del departamento (`DEP2*`: `GRHEYC` → `GRHCAR.DEP2*`) |
| `A` | Los autorizados vigentes (`GRHAUT`, estado `A` sin caducar) en la técnica del documento (`DOCFAT.TEC2COD`, de su delegación); sin técnica, nadie |

**Lecturas pendientes.** Destinatarios − lecturas (`DOCLEC`) de la edición vigente (`VER2APR`) de
los controlados en vigor (fuera de la papelera, no obsoletos, con edición aprobada). Si la vigente
tiene `VERBLEC = F` (cambio menor), vale la lectura de cualquier edición aprobada anterior
posterior a la última que sí requirió lectura (incluida). **Vencida:** `VERDAPR` + días para leer
(los de la carpeta o 15) anterior a hoy. Se calculan para muchos documentos a la vez con dos
consultas por delegación (versiones y lecturas) y una lectura del personal por petición
(`VeolabDocumentControl::readingStatus()`), sin consultas por fila.

**Revisión antes de aprobar.** En una carpeta (o una superior) con `DIRBREV = T`, la edición en
curso (la principal sin aprobar) pasa por:

1. **Revisar** (`POST /documentos/revisar-edicion`): el especial 2 de `DOC_CTL` (`ESP_DOC_CTL02`) o
   el revisor del documento; nunca quien lo elabora (tampoco como `revisada_por_*`). Pone
   `VERCEST = R`, `EMR2*` (por defecto el empleado del usuario), `VERDREV` = ahora y `VERCREV`
   (`comentario`). `422` si ya está revisada, si la carpeta no exige revisión o si no hay edición en
   curso.
2. **Devolver** (`POST /documentos/devolver-edicion`): `motivo` obligatorio; vuelve a `B` con el
   motivo en `VERCREV` y quién y cuándo. Lo pueden quien revisa y quien aprueba, nunca quien lo
   elabora.
3. **Aprobar** (`POST /documentos/aprobar`): solo una edición en `R` (si no, `422`); si quien la
   revisó es quien la aprueba, la respuesta trae `aviso`. Con la fase 2, aprobar pone siempre
   `VERCEST = A` y `VERBLEC` = `requiere_lectura` (`T` por defecto).

Sin usuario en la petición la API actúa como privilegiada, como en la fase 1.

**Copias controladas** (`/documentos/copias`, claves `delegacion`, `documento`, `codigo`): `ubicacion`
(`COPCUBI`), `empleado_*`, `version` (edición entregada; por defecto la vigente, siempre una
aprobada), `fecha_entrega` (por defecto ahora) y `fecha_retirada`. Hace falta la ubicación o el
empleado. El número de copia, si no se indica, es el siguiente del documento. Añadidos: `edicion`,
`version_vigente`, `edicion_vigente`, `empleado_nombre`, `retirada` y `por_retirar` (de una edición
anterior a la vigente y sin fecha de retirada; también filtro `?por_retirar=T|F`). Escribir exige el
especial 1 de `DOC_CTL` si se indica el usuario.

**Rutas.**

```
GET  /api/v2/documentos/destinatarios?delegacion=&codigo=  |  ?carpeta_delegacion=&carpeta_codigo=
PUT  /api/v2/documentos/destinatarios?…[&usuario_*]   {destinatarios: [{tipo, empleado_*, cargo_*, departamento_*}]}
GET  /api/v2/documentos/lecturas/estado?delegacion=&codigo=
GET  /api/v2/documentos/lecturas/pendientes?empleado_delegacion=&empleado_codigo=  |  ?usuario_*[&delegacion=]
POST /api/v2/documentos/revisar-edicion?delegacion=&codigo=[&usuario_*]   {comentario, revisada_por_*}
POST /api/v2/documentos/devolver-edicion?delegacion=&codigo=[&usuario_*]  {motivo, revisada_por_*}
POST /api/v2/documentos/aprobar?…   {…, requiere_lectura: T|F}
GET|POST|PUT|DELETE /api/v2/documentos/copias[?delegacion=&documento=&codigo=&por_retirar=T|F]
```

- **Destinatarios:** GET devuelve `destinatarios` (los propios), `heredados` (T si no tiene propios y
  valen los de una carpeta, con `origen_carpeta_*`), `destinatarios_efectivos` y, en un documento,
  `controlado` y `personas` resueltas. PUT sustituye la lista entera (vacía = quitar los propios),
  quita las repetidas, numera `DIS1COD` de nuevo por delegación y exige el especial 1 de `DOC_CTL`
  si se indica el usuario; un tipo `A` en un documento sin parámetro devuelve `aviso`.
- **Estado de lecturas:** `version_vigente`, `edicion`, `fecha_aprobacion`, `dias_lectura`,
  `fecha_limite`, `plazo_vencido`, `desde_version`, `destinatarios_origen` (`documento`, `carpeta`
  o null), recuentos (`destinatarios`, `leidas`, `pendientes`, `vencidas`, `lecturas` «12/15») y
  `personas`: cada destinatario con `estado` `leida` (con `fecha_lectura`, `version_leida` y
  `edicion_leida`), `pendiente` o `vencida`, y quien la leyó sin ser destinatario
  (`es_destinatario` F).
- **Pendientes de un empleado:** documento, edición, `fecha_aprobacion`, `dias_lectura`,
  `fecha_limite` y `vencida`; vencidas primero y después por fecha límite.
- **Lista de controlados:** con la fase 2 añade `revisor_*`, `revision_antes_aprobar`,
  `edicion_estado` (de la edición en curso, `B`/`R`) con quién, cuándo y comentario de la
  revisión, `pendiente_revisar` y `pendiente_aprobar_edicion` (el paso que le toca), y
  `lecturas` («leídas/destinatarios»), `lecturas_destinatarios`, `lecturas_leidas`,
  `lecturas_pendientes`, `lecturas_vencidas`, `lecturas_fecha_limite` y `destinatarios_origen`.
  Filtro nuevo `pendiente=revisar|aprobar`. `pendiente_aprobar` sigue siendo el de la fase 1
  (estado `P`).
- **Tareas pendientes** (con el módulo CAL): `DE` revisar edición (el revisor, personal, o el
  especial 2, por cargo; nunca quien lo elabora), `DA` solo cuando la edición está revisada si la
  carpeta lo exige, `DL` leer la edición vigente como destinatario (fecha límite y vencida; sin
  destinatarios, la regla de la fase 1) y `DC` retirar copias (especial 1 de `DOC_CTL`, una por
  documento con el número de copias).
- **Revisión por la dirección, tema c:** «Recoger datos» escribe las ediciones de controlados
  aprobadas en el periodo (con lista si son pocas) y, con la fase 2, las lecturas pendientes y
  vencidas al fin del periodo y las copias por retirar. No hay histórico de destinatarios ni de
  ediciones vigentes: las lecturas se aproximan con la situación actual (sin contar las hechas
  después del fin del periodo) y así lo dice el texto; las copias por retirar son las de hoy.

**Sin hacer:** el filtro «con lecturas pendientes» en `/documentos/controlados` (se calcula en PHP
y rompería la paginación; la app filtra por `lecturas_pendientes`) y las plantillas «Registro de
distribución y lectura» y «Hoja de entrega de copias controladas» (exportación del escritorio).

### Accesos directos a documentos (Veolab 3)

Según `Documentacion/ISO 17025/Analisis accesos directos a documentos.md` del proyecto de Veolab.
Un acceso directo es una fila de `DOCFAT` sin contenido (sin `DOCVER` ni `DOCBLO`) en cualquier
carpeta o ficha, con `FAT2DEL`/`FAT2COD` apuntando al documento de destino (`FAT2COD` NULL o 0 =
documento normal). Los campos llevan `$since` 3.0 y, si la base de Veolab 3 aún no tiene las
columnas (script sin aplicar), se tratan como no disponibles y las rutas de accesos dan `422`
(`VeolabDocuments::shortcutsAvailable()`).

**Campos en `/documentos`:** `acceso_directo_delegacion` + `acceso_directo_codigo` (grupo FK:
`?acceso_directo_codigo[null]=F` lista solo accesos) y los calculados `es_acceso_directo` (también
filtro `?es_acceso_directo=T|F`) y `destino` (solo en los accesos: `delegacion`, `codigo`,
`existe`, `en_papelera`, `carpeta_*` y, con control de documentos, `es_controlado`, `estado` B/V/P/O
y `pendiente_aprobar` del destino). Un acceso tiene siempre `es_controlado` y `pendiente_aprobar`
`F`: el control es el de su destino.

```
POST /api/v2/documentos   {acceso_directo_delegacion, acceso_directo_codigo, [delegacion],
                           [carpeta_delegacion, carpeta_codigo], [entidad]}     (JSON o formulario, sin fichero)
GET  /api/v2/documentos/accesos?delegacion=&codigo=      → accesos que apuntan al documento
```

- **Alta:** copia `FATCNOM`/`FATCTIP` del destino; `FATNTAM` 0, `FATBZIP`/`FATBLEC`/`FATBVER` `F`,
  `VER2COD` 0 y sin datos de control aunque la carpeta sea controlada. `nombre`, `extension`,
  `descripcion`, `es_solo_lectura`, `es_control_versiones` y `version_*` dan `422` (son del destino),
  igual que enviar `fichero`. Un acceso a otro acceso se resuelve al documento real. `422` si el
  destino no existe, está en la papelera o es una plantilla de exportación, si el acceso quedaría
  en la misma carpeta y ficha que el destino o si ya hay otro acceso al mismo destino en esa
  carpeta y ficha. El destino puede ser de otra delegación.
- **PUT** de un acceso: solo carpeta y ficha (con la misma comprobación de duplicados); el resto,
  `422`. `acceso_directo_*` no se cambia nunca (se crea otro acceso).
- **Renombrar** el destino (`nombre` o `extension` en su PUT) actualiza `FATCNOM`/`FATCTIP` de sus
  accesos.
- **Borrar:** un acceso se borra como cualquier documento, sin tocar el destino. Enviar el destino a
  la papelera no toca sus accesos; borrarlo definitivamente los borra (con auditoría `B` de cada
  uno y `data.accesos_eliminados` en la respuesta).
- **Contenido:** `GET /documentos/contenido` de un acceso entrega el destino con sus reglas
  (`version` es del destino; con usuario, quien solo consulta recibe la edición aprobada) y añade
  las cabeceras `X-Documento-Destino-Delegacion` y `X-Documento-Destino-Codigo`; destino
  inexistente `404`, en la papelera `422`. `POST /documentos/contenido` en un acceso, `422`.
- **Versiones, lecturas, aprobar, revisar y descartar** sobre un acceso: `422` con la clave del
  destino («use el documento de destino»), en vez de resolverlo en silencio: son acciones que
  cambian o consultan el documento controlado y la aplicación debe saber cuál es. En los listados
  de versiones y lecturas basta `?delegacion=&documento=` del acceso para recibir ese `422`.
- **Controlados:** `/documentos/controlados`, `?es_controlado`, `?pendiente_aprobar` y el marcado
  de una carpeta como controlada (que activa sus documentos) excluyen siempre los accesos.
- `GET /documentos/accesos`: delegación, código, nombre y extensión, carpeta (`carpeta_*`,
  `carpeta_nombre`; null en la papelera), `en_papelera`, `tabla` y `entidad` (parámetros de la
  ficha) y `fecha_creacion`. Sin paginar.

**Sin hacer:** las restricciones de carpeta del destino (la API no aplica el acceso a carpetas; lo
filtra la aplicación cliente) y «Añadir también a…» (parámetros con el mismo nombre o método; la
aplicación crea un acceso por cada uno con el POST).

## 11 bis. Inventario

En Veolab el **producto** (`ALMPRD`, `/productos`) es el catálogo y los
elementos reales del almacén son sus **series o lotes** (`ALMSEL`,
`/inventario`, clave `producto_delegacion` + `producto_codigo` + `codigo`). Las
existencias del producto son la suma de las de sus series y lotes que no están
de baja, y se recalculan con cada cambio.

| Recurso | Tabla | Notas |
|---|---|---|
| `/inventario` | `ALMSEL` | Como FichaInventario. Sin `codigo`, el siguiente numérico del producto. Alta: existencias del lote completo si no se indican, proveedor y precio del producto, movimiento `I`. Cambiar `existencias_cantidad` (o `existencias_unidades`, se calculan una de otra con `cantidad_unidad`) genera un ajuste `J`; dar de baja (`estado` B o `fecha_baja`) un movimiento `B` con la cantidad en negativo; reactivar, un `J`. No se borra si la usa una operación, un préstamo o es materia prima de otra (se da de baja). |
| `/inventario/materias` | `ALMMAT` | Materias primas de una serie o lote (`materia_delegacion` + `materia_producto_codigo` + `materia_codigo`, `cantidad`). Alta: consumo `O` que descuenta existencias de la materia; cambio de cantidad: ajuste `J` con la diferencia; baja: ajuste que la devuelve. |
| `/inventario/movimientos` | `ALMMOV` | Historial de movimientos (I inicial, E entrada, S salida, C compra, D devolución, P préstamo, B baja, O consumo, U uso, J ajuste, A anulación). Anotarlos a mano, como en Veolab, **no** cambia existencias. Los consumos y usos de las operaciones son de solo lectura. |

**Consumos de operaciones.** Al crear una operación (también desde una
planificación), por cada técnica: un uso `U` de 1 por equipo (`LABTYQ`) y un
consumo `O` de `TYPNCON` por consumible (`LABTYP`) sobre la serie o lote
predeterminado (vigente, con existencias; "en uso", después "límite de uso",
después nuevos; para consumibles, si no hay ninguno con existencias, el mayor
sin existencias). El consumo descuenta existencias sin bajar de cero (se reduce
a lo disponible). Al borrar la operación se devuelven.
- Como `ALM_ObtenerSeriesPredeterminadasProductos`, nunca se propone una serie fuera
  de uso o de baja ni con la caducidad, calibración, verificación o mantenimiento
  alcanzados hoy; tampoco la serie oculta de un producto sin series (que se toma con o
  sin existencias), que antes se usaba siempre.
- Desde Veolab 3, de los equipos alternativos de una técnica (`LABTYQ.TYQNGRU` ≠ 0)
  solo se registra uno (`EquipoElegidoEnGrupo`): el predeterminado del grupo
  (`TYQBPRE`) si tiene serie utilizable; si no, el primero del grupo que la tenga; si
  ninguno, ninguno.
- La API no registra a mano usos ni consumos en una operación (`/inventario/movimientos`
  no los admite), así que no hay equivalente de la comprobación de `Consumos`
  (`CONCVEN`, MEN00656-658) al elegir equipos: los automáticos ya excluyen los vencidos,
  y al grabar resultados se comprueba `CONCVEN` (§12 bis, resultados).

### Patrones y materiales de referencia (Veolab 3, ISO/IEC 17025 6.5 y 6.4.9)

Análisis en `Documentacion/ISO 17025/Analisis patrones y materiales de referencia.md` del proyecto
de Veolab. `App\Support\VeolabMetrology`. Las columnas las crea el script de los patrones: en una base
de Veolab 3 sin él (`VeolabMetrology::available()`, que mira las columnas una vez por base) los campos
se tratan como los de `$since` en una base anterior: no se devuelven, no filtran y escribirlos da
`422`; las rutas de trazabilidad y operaciones afectadas funcionan sin los datos del certificado.

| Campo | Recurso | Columna | Notas |
|---|---|---|---|
| `tipo_metrologico` | `/productos` | `ALMPRD.PRDCTPR` | `R` patrón de referencia, `T` patrón de trabajo, `C` material de referencia certificado, `M` material de referencia o control; `null` (o vacío al escribir) producto normal. |
| `dias_validez_apertura` | `/productos` | `ALMPRD.PRDNDAP` | Días de validez tras abrir; 0 se guarda como `NULL`. |
| `valor_certificado` | `/inventario` | `ALMSEL.SELCVCE` | Texto (100), p. ej. «1413 µS/cm a 25 °C». |
| `incertidumbre` | `/inventario` | `ALMSEL.SELCINU` | Texto (100). |
| `trazabilidad` | `/inventario` | `ALMSEL.SELCTRZ` | Texto (255): «NIST SRM 3191», «ENAC 123/LC456». |
| `numero_certificado` | `/inventario` | `ALMSEL.SELCNCE` | Texto (50). |
| `fecha_certificado` | `/inventario` | `ALMSEL.SELDCER` | Fecha. |
| `acreditado` | `/inventario` | `ALMSEL.SELBACR` | `T`/`F`: productor (ISO 17034) o laboratorio (ISO 17025) acreditado. |
| `tipo_metrologico` | `/inventario` | `ALMPRD.PRDCTPR` | Solo lectura: el del producto. |
| `caducidad_efectiva` | `/inventario` | — | Solo lectura (`aaaa-mm-dd`): la menor entre `SELDCAD` y `SELDAPE` + `PRDNDAP` días; `null` si no hay ninguna. |

- Filtros: `?tipo_metrologico=R,T` (vacío: productos normales) y `?patrones=T|F` (con o sin tipo), en
  `/productos` y en `/inventario` (por el producto de la serie).
- Como en el escritorio, las calibraciones y verificaciones son las operaciones sobre la serie
  (`LABOPE.PRD2DEL`/`PRD2COD`/`SEL2COD`) con el tipo reservado de la delegación común: `TIO2DEL = ''`
  y `TIO2COD` 2 (calibración) o 3 (verificación) (`ALM_CampoFechaTipo`). Las anuladas no cuentan.
  La fecha de un uso es la de inicio de la operación (`OPEDINI`) o, sin ella, la del movimiento
  (`MOVDFEC`), como `LeerEquiposVencidos`.

```
GET  /api/v2/inventario/trazabilidad?producto_delegacion=&producto_codigo=&codigo=
GET  /api/v2/inventario/operaciones-afectadas?producto_delegacion=&producto_codigo=&codigo=[&desde=&hasta=]
POST /api/v2/inventario/operaciones-afectadas?producto_delegacion=&producto_codigo=&codigo=
```

(`/series` es la ruta de los contadores `ACCCLT`; las series de inventario van en `/inventario`.)

- **Trazabilidad** (`data`): `serie` (sus datos, los del certificado y la caducidad efectiva);
  `se_calibra_con`: un elemento por operación y serie usada o consumida (`ALMMOV` `U`/`O`) en sus
  calibraciones y verificaciones, sin la propia serie, del más reciente al más antiguo, con `fecha`, `operacion` (clave, código,
  `tipo_operacion` 2/3, fin y dictamen), `movimiento` (tipo, cantidad, técnica), `patron` (producto,
  serie, tipo metrológico, datos del certificado y caducidad efectiva), `valido_ese_dia` y `motivo`
  («caducidad efectiva dd/mm/aaaa», «próxima calibración …», «próxima verificación …»: alcanzadas
  ese día, el mismo día cuenta; sin fecha del uso, con la de hoy; si la serie usada ya no existe,
  `valido_ese_dia` es `null`, como el escritorio); `se_usa_para`: `series` calibradas o verificadas
  con ella, sin ella misma (producto,
  serie, `calibraciones`, `verificaciones`, primera y última fecha) y el recuento de `operaciones` no
  anuladas con uso o consumo de la serie, con `primera_fecha` y `ultima_fecha`. Las fechas de
  calibración y verificación del patrón son las actuales (Veolab no guarda su historia), igual que
  en la comprobación de equipos vencidos del escritorio.
- **Operaciones afectadas** (6.4.9). Por defecto, `desde` es el fin (`OPEDFIN`) de la última
  calibración o verificación finalizada y **conforme** de la serie y `hasta`, hoy (los dos
  incluidos). Veolab no guarda si un dictamen es conforme: se tiene por no conforme la que está
  enlazada (`CALIYE`, `LABOPE`) a una incidencia de trabajo no conforme (clase `T`) no anulada. Sin
  ninguna, desde el principio. `data`: `serie`, `desde`, `hasta`, `desde_origen` (`calibracion`,
  `indicada` o `sin_calibracion`), `calibracion_referencia`, `calibraciones` (las 10 últimas, con
  `conforme` y `dictamen`) y `operaciones`: las no anuladas con uso o consumo de la serie en el
  periodo, por fecha de uso, con estado, referencia, cliente, validación y envío, `movimientos`,
  `tecnicas` e `informes` (`LABIYO`). `meta.total`.
- **`POST`**: cuerpo `{operaciones: [{delegacion, serie, codigo}], calibracion: {delegacion, serie,
  codigo}, desde, hasta, delegacion, serie, descripcion, detalle, detectada_por_*, usuario_*}`, todo
  opcional. Crea con las reglas de `POST /incidencias` (módulo `CAL`) una incidencia de clase `T`,
  origen `Q` (equipo), título «Equipo no conforme: producto (serie)» y un detalle con el número de
  operaciones y el periodo si no se indican, detectada por el empleado del usuario si no se indica
  otro. La enlaza (`CALIYE`) con la serie (`ALMSEL`; la serie oculta, como el producto), la
  calibración (debe ser una calibración o verificación de la serie; así deja de contar como
  conforme) y las operaciones, que deben usar o consumir la serie (`422` si no). Para enlazar los
  informes de esas operaciones, `POST /incidencias/enlaces/informes`.

## 11 ter. Permisos de perfil

La API no aplica privilegios (el token tiene acceso total), pero mantiene los
permisos de los perfiles de usuario de Veolab (`ACCPYF`) para la futura app web,
como la pestaña de funcionalidades de FichaPerfil.

| Recurso | Tabla | Notas |
|---|---|---|
| `/perfiles/permisos` (GET/PUT) | `ACCPYF` | Clave `perfil_delegacion` + `perfil_codigo`. GET devuelve **todas** las funcionalidades visibles (sin paginar) con el acceso del perfil. PUT cambia solo las indicadas. |
| `/funcionalidades` (GET) | `ACCFUN` + `ACCMYF` | Catálogo fijo: nivel 1 = grupo (código de 3 letras), nivel 2 = funcionalidad. `ambito` E escritorio / W web. |
| `/modulos` (GET) | `ACCMOD` | `es_activo` (se activa en Veolab) y `es_licenciado` (la licencia del laboratorio lo incluye). |

Cada fila del GET de permisos: `funcionalidad`, `descripcion`, `grupo`,
`grupo_descripcion`, `modulo`, `ambito`, `acceso` (`E` escritura, `L` lectura,
`null` sin acceso), `especial` (privilegio especial elegido, 0-7; `null` sin
acceso), `valor` (la máscara `PYFNACC` tal cual) y `especiales` (opciones de
privilegio especial de esa funcionalidad: `[{especial, descripcion}]`, de los
textos `ESP_<funcionalidad>0n`). Las descripciones son las de Veolab en español
(`IDICAD`). `meta.grupos` lista los grupos visibles con su acceso grabado
(`E` si alguna de sus funcionalidades tiene acceso); el PUT también lo devuelve.

PUT: `{ "permisos": [ { "funcionalidad": "LAB_OPE", "acceso": "E", "especial": 2 } ] }`.

- Funcionalidades **visibles**: sin módulo, o de un módulo activo y licenciado, y
  cuyo grupo también lo sea. Una funcionalidad no visible, inexistente o un grupo
  dan `422`. Las filas de funcionalidades no visibles se conservan.
- `acceso: null` quita el acceso. Sin `acceso` se conserva el que tenía (para
  cambiar solo el especial). Sin `especial` se conserva el que tenía, o el 1.
- `especial` debe ser una de las opciones de la funcionalidad (0 o 1 si no tiene).
  Como en Veolab, el 0 se graba igual que el 1 (siempre hay un especial con acceso),
  así que se lee como 1.
- Máscara `PYFNACC` (Sesiones.bas): 1 acceso, 2 escritura, 4·2^(n-1) especial n.
  Lectura = 1, escritura = 3. El **grupo** se graba con 3 si alguna de sus
  funcionalidades tiene acceso, y se quita si no.
- Se graba con DELETE+INSERT de las filas visibles, como Veolab, y se audita sobre
  el perfil (suceso de fila y de campo `ACCPYF`) solo si algo cambia. La respuesta
  trae la lista completa resultante.
- Un perfil nuevo no tiene permisos; al borrarlo se borran sus `ACCPYF`.

## 11 quater. Contraseña y firma de usuario

**Contraseña** (`ACCUSU.USUCCON`), en `POST`/`PUT /usuarios`:

- Campo `contrasena` (solo escritura, máx. 40). **Nunca** se devuelve: la respuesta
  lleva `tiene_contrasena` (T/F) y `fecha_contrasena` (`USUDCON`, la del último
  cambio, que Veolab usa para obligar al cambio anual).
- Se guarda como Veolab (`ENC_Encripta`, Encriptacion.bas) para que el usuario entre
  en Veolab con ella. Necesita en el `.env` los patrones `VEOLAB_ENC_BUSQUEDA` (el
  mismo de la licencia) y `VEOLAB_ENC_ENCRIPTA1` (base64 de Windows-1252); sin ellos,
  `422`.
- Con la seguridad de contraseñas activada en Veolab (`ACCPAR.PARBSEG`), al menos 8
  caracteres con mayúscula, minúscula, número y algún otro carácter (`422` si no).
- `""` o `null` quita la contraseña (como el botón de la ficha). Al cambiarla o
  quitarla `fecha_contrasena` pasa a hoy; también en un alta sin contraseña.
- Se audita el campo `ACCUSUUSUCCON` **sin valores**.

**Firma digitalizada** (`ACCFIR`), la imagen que Veolab pone en los informes:

| Método | Ruta | Uso |
|---|---|---|
| `GET` | `/usuarios/firma?delegacion=&codigo=` | Descarga la imagen (`inline=T` para mostrarla). `404` si no tiene. |
| `POST` | `/usuarios/firma?delegacion=&codigo=` | `multipart/form-data`, campo `fichero`: sustituye la firma. |
| `DELETE` | `/usuarios/firma?delegacion=&codigo=` | Quita la firma. |

- Solo **BMP, JPG o GIF** (Veolab la carga con `LoadPicture`, que no admite PNG), hasta
  2 MB; se comprueba por la cabecera del fichero.
- Como mucho **2000 píxeles por lado**, y los JPG deben ser estándar (baseline) y en
  RGB/grises: `LoadPicture` no abre JPG progresivos ni CMYK, y descomprime la imagen
  entera; además la ficha del usuario la vuelve a grabar con `SavePicture` como BMP
  sin comprimir. Una foto grande (aunque el JPG pese poco) hace fallar Veolab.
- **Error de Veolab, no replicado:** `FichaUsuario.Grabar` borra `ACCFIR` solo si la
  firma cambió, pero la vuelve a insertar (ya como BMP) en **cada** grabación de la
  ficha, así que se va duplicando; al leerla se concatenan todos los trozos.
- Se guarda como Veolab: trozos de 65534 bytes con `FIR1COD` del contador de
  `ACCFIR` (`ACCCLT`), en orden. Se audita el campo `ACCUSUUSUCBFI`.

## 11 quinquies. Configuración (solo lectura)

La configuración de Veolab se mantiene en sus pantallas Configurar...; la API la
expone **solo para lectura** (la escritura irá por partes, con las reglas de cada
pantalla). Son listados estándar (`{data, meta}`, filtros y orden).

| Recurso | Tabla | Notas |
|---|---|---|
| `GET /configuracion/general` | `ACCPAR` | Una fila (`codigo` 1): empresa, acceso, auditoría, copias, documentos, email, IGEO. |
| `GET /configuracion/laboratorio` | `LABCON` | Una fila (`codigo` 1): operaciones, resultados, informes, notificaciones, cartas de control, facturación, exportación. |
| `GET /configuracion/codigos` | `ACCCFC` | Formato y numeración de códigos por `tabla` (la API ya lo aplica al generar códigos). |
| `GET /series` | `ACCCLT` | Series y contadores por `delegacion` + `tabla` + `serie`. Las tablas sin serie tienen su contador con serie `''`. `contador` = último código asignado. |
| `GET /configuracion/imagen-acreditacion` | `LABIMG` | La imagen de acreditación de los informes (binaria, `inline=T` para mostrarla). `404` si no hay. |

- **Nunca se devuelven** las contraseñas de `ACCPAR` (SMTP `PARCPAE`, RabbitMQ de
  IGEO `PARCIGC`): solo `smtp_tiene_contrasena` / `igeo_tiene_contrasena` (T/F).
- Tampoco la licencia cifrada (`PARCLBD`, `PARCCLV`): en su lugar `licencia`
  (Gratuita, Profesional, Empresarial, Empresarial Verifactu o `null` si no se puede
  leer) y `restricciones_verifactu` (T/F). La cadena de hash de facturas (`ACCHAS`)
  no se expone.
- Los nombres de campo agrupan por pantalla; los códigos de letra se documentan en
  los controladores (`ConfiguracionGeneralController`, `ConfiguracionLaboratorioController`).
- Desde Veolab 3: en general `email_activo`, `seleccion_moderna_listados`,
  `horas_con_segundos` e `informes_error_automaticos` (el token OAuth del correo,
  `PARCTOK`, no se expone); en laboratorio las opciones de exportación (superíndices,
  subíndices, notación científica, formato de líneas y de marcas),
  `importacion_no_sobrescribir`, `orden_series_lotes`, `presupuesto_solo_incluido`,
  `notificar_seguimiento_presupuestos`, `dias_seguimiento_presupuestos` y
  `notificar_errores_igeo`, `autorizaciones_analistas` (`CONCAUT`), `equipos_vencidos`
  (`CONCVEN`: vacío o nulo bloquear · `A` avisar · `N` no comprobar) y
  `notificar_evaluaciones_proveedores` (`CONBNEV`) y `notificar_ensayos_aptitud` (`CONBNAP`, aviso
  `J`, §11 quindecies). En los colores, -1 es automático (texto) o transparente (fondo).

## 11 sexies. Cartas de control

Módulo **CDC**, como Veolab 2.4 (en 2.5 se rehacen). Tablas `LABCDC` (carta), `LABCYT`
(técnicas) y `LABRCD` (resultados: uno por operación de control). Sin el módulo activo y
licenciado, las escrituras dan `422`.

`/cartas-control` (clave `delegacion` + `codigo`; CRUD estándar):

| Campo | Notas |
|---|---|
| `tipo` | `E` exactitud (columnas de control `CORBCON`), `P` precisión (`CORBCOP`). Por defecto `E`. |
| `estado` | `N` normal, `A` aviso, `E` error, `C` corregida. Por defecto `N`. |
| `fecha_creacion`, `fecha_cierre` | Por defecto, creada ahora; se cierra al abrirse la siguiente. |
| `numero_resultados` | Resultados que admite; por defecto `LABCON.CONNNUM`. |
| `promedio`, `desviacion` | Base de los límites. `calcular_promedio: "T"` los calcula (botón de la ficha). |
| `matriz_*`, `observaciones` | |
| `tecnicas` | `[{tecnica_delegacion, tecnica_codigo}]`; en escritura sustituye la lista. |
| `resultados` | Lectura: `[{posicion, valor, incidencia, operacion_*}]`. Escritura: `[{operacion_delegacion, operacion_serie, operacion_codigo}]` en orden, sustituye la lista; las ya presentes conservan su valor y las nuevas (operaciones de control) toman el de su primera columna de control. |

- **Promedio y desviación** (`calcular_promedio`): de los últimos `numero_resultados` valores de
  control del tipo en cualquier operación de control de las técnicas de la carta, redondeados a
  4 decimales. Si la sección de las técnicas es físico-químico (`F`), el promedio es 0
  (precisión) o 100 (exactitud).
- **Límites** (sección `M` microbiología: LSC = 3,27·x̄; resto: x̄ ± 3s), advertencia x̄ ± 2s y
  1s. **Error**: dos últimos fuera del límite de control; 2 de 3 fuera del de advertencia y el
  último también; 4 de 5 fuera de 1s y el siguiente también, o 5 seguidos crecientes o
  decrecientes; 7 seguidos al mismo lado del promedio. **Aviso**: último fuera del límite de
  control; 2 de 3 fuera del de advertencia; 4 seguidos fuera de 1s o crecientes/decrecientes.
  En precisión solo cuenta el lado superior; en microbiología y en físico-químico de precisión
  solo el límite de control.
- Al cambiar `tipo`, `numero_resultados`, `promedio`, `desviacion`, `tecnicas` o `resultados` se
  recalcula la incidencia de cada resultado (con los 7 anteriores) y el estado pasa al de la
  última incidencia, salvo que la petición indique `estado` (p. ej. `C` al corregirla).
- **Desde resultados** (`PUT /resultados`, operación de control): cada valor de control
  modificado (no vacío ni `N/A`) va a la carta del tipo de su técnica: la que ya tiene un
  resultado de la operación o, si no, la última de la delegación de la sesión. Con la carta
  llena se abre una nueva (mismas técnicas y matriz, promedio y desviación recalculados,
  `CONNNUM` resultados) y se cierra la anterior. La incidencia sube el estado de la carta
  (nunca baja de error). Una técnica con la carta en error **no se puede grabar** en ninguna
  operación hasta corregirla.
- **Notificaciones** (módulo COM, `CONBCAE`/`CONBCAA`/`CONBCAN`): errores y cartas nuevas a los
  usuarios empleados con escritura en `LAB_CDC` (si no hay, al usuario indicado); avisos al
  usuario indicado (`usuario_*` del `PUT /resultados`).
- Borrado: documentos a la papelera y se borran resultados, técnicas y notificaciones.

## 11 septies. Notificaciones, mensajes y avisos

La API no tiene sesión de usuario: estas rutas trabajan sobre el usuario que se indique
(`usuario_delegacion` + `usuario_codigo`), que la app web debe tomar del usuario conectado.

| Recurso | Tabla | Notas |
|---|---|---|
| `GET /notificaciones` | `ACCNOT` | Lista de notificaciones (filtrar por `usuario_*`, `tipo`, `fecha[gte]`...). Cada una lleva `tipo_descripcion` y `pendiente` (T si su aviso emergente no se ha visto). Referencias: `operacion_*`, `informe_*`, `producto_*`, `carta_control_*`. |
| `DELETE /notificaciones?delegacion=&codigo=` | `ACCNOT` | Borra la notificación y sus avisos (Veolab deja los avisos huérfanos). Se audita. |
| `GET /mensajes` | `MENMEN` | Listado (`origen_*`, `destino_*`, `tipo`, `fecha`...); `tipo` U entre empleados, C conversación de un cliente con el laboratorio; `leido` = T cuando tiene `fecha_lectura` (lector en `lector_*`). |
| `POST /mensajes` | `MENMEN` | `{origen_delegacion, origen_codigo, destino_delegacion, destino_codigo, texto}`. Como Mensajeria: se guarda en la delegación del remitente con la hora del servidor y crea el aviso `M` del destinatario (sin baja y de la misma delegación o común). Un cliente escribe sin destino (buzón del laboratorio) y el aviso va a los empleados con el privilegio de mensajes de clientes. Necesita el módulo MEN. No se auditan ni se borran. |
| `GET /mensajes/conversacion?usuario_*[&con_*][&desde=]` | `MENMEN` | Mensajes entre los dos usuarios, en orden de envío (sin paginar), con `origen_nombre`. Si uno es cliente, toda su conversación con el laboratorio (`con` opcional si el usuario es el cliente). |
| `POST /mensajes/leidos` | `MENMEN`, `ACCAVI` | `{usuario_*[, con_*]}`: pone `fecha_lectura` y lector a los mensajes recibidos (de todos o solo de `con`; si `con` es cliente, también los que envió al laboratorio) y borra los avisos de mensajes del usuario. |
| `GET /avisos` | `ACCAVI` | Avisos pendientes: `tipo` N notificación, M mensaje, A agenda (la fecha de una cita puede ser futura: filtrar `fecha[lte]`). Cada uno lleva un `resumen` de lo avisado. |
| `DELETE /avisos?delegacion=&codigo=` | `ACCAVI` | Aviso visto (como al mostrarlo en Veolab). |
| `POST /avisos/vistos` | `ACCAVI` | `{usuario_*[, tipo]}`: todos los avisos vencidos del usuario. |
| `GET /avisos-servicio` | — | Avisos de servicio de SPUCH vigentes para el laboratorio (los mismos que muestra Veolab): ver abajo. |

**Avisos de servicio de SPUCH** (cortes del servidor, incidencias y comunicaciones; desde el 17-nov-2026).
No están en la base de datos del laboratorio: se crean en la web de licencias de SPUCH y la API los lee
de `getnotice.php` en spuch.com con la licencia del laboratorio (`ACCPAR.PARCLBD`), con una caché de un
minuto por licencia. Solo lectura: qué avisos ha visto cada usuario lo guarda la app.

- Cada aviso: `codigo`, `gravedad` (0, 1 o 2) y `gravedad_descripcion` (`informativo`, `programado`,
  `urgente`), `inicio` y `fin` (cuándo se muestra), `corte` (hora del corte de un programado, o `null`),
  `titulo` y `texto` (texto sin formato con saltos de línea). Los urgentes van primero.
- Las fechas van en **hora peninsular española** (`meta.zona_horaria` = `Europe/Madrid`), no en la del
  servidor de la API: los laboratorios de Colombia deben convertirlas.
- Cómo los muestra Veolab: el informativo, una vez por usuario; el programado, al publicarse y 15 minutos
  antes del corte; el urgente, al llegar y en cada entrada mientras esté vigente.
- Si spuch.com no responde o la base no tiene licencia: `data` vacío y `meta.disponible` = `false` (`200`,
  nunca un error).
- `VEOLAB_AVISOS_SERVIDOR` en el `.env` (p. ej. `veolab.es`) identifica el VPS para los avisos dirigidos a
  un VPS; sin él se usa el servidor de la ficha del cliente en la web de licencias.

- Antes de Veolab 3 (bases 2.4) la mensajería es el módulo `COM`: sin `tipo`,
  `fecha_lectura` ni `lector_*`; `leido` = F mientras el destinatario tenga el aviso, los
  clientes no escriben sin destinatario y `/mensajes/leidos` solo borra avisos.
- Tipos de notificación añadidos en Veolab 3: `P` fecha de seguimiento de presupuesto
  (referencia `presupuesto_*`) y `G` error al recibir una muestra de iGEO; `J` fecha límite de
  envío de un ensayo de aptitud (referencia `ensayo_delegacion`, `ensayo_serie`, `ensayo_codigo`;
  §11 quindecies).

Tipos de notificación: B muestra recibida, C fecha de compromiso, A analista asignado, F firma
pendiente, R resultado rechazado, I nuevo informe, M marca en resultados, S stock mínimo,
O/V/N error, aviso y nueva carta de control.

## 11 octies. Agenda

Como FichaCalendario y Calendario de Veolab, con eventos de una fecha o periódicos
(`repeticion`, ver §11 nonies).

| Recurso | Tabla | Notas |
|---|---|---|
| `/agenda` | `AGEAGE` (+ `AGEFEC`, `AGEASI`) | Clave `usuario_delegacion` + `usuario_codigo` (dueño) + `codigo` (contador por usuario). CRUD. |
| `GET /agenda/fechas?usuario_*&desde=&hasta=` | `AGEFEC` | Fechas de los eventos del usuario o a los que asiste, en orden (para un calendario); `es_propio`. Una fecha puede no tener `fin` (`null`: Veolab lo permite y así son los eventos de planificación). |
| `/agenda/estados` | `AGEEST` | `descripcion` (50), `color` (entero de color de VB). |
| `/agenda/clasificaciones` | `AGECLA` | Igual. |

- **Evento**: `asunto`, `ubicacion`, `duracion` (0 0 min, 1 30 min, 2 1 h, 3 90 min, 4 2 h,
  5 todo el día, 6 personalizada), `aviso_numero` + `aviso_unidad` (M, H, D, S), `es_privado`,
  `notas`, `estado_*`, `clasificacion_*`, `planificacion_*` (solo lectura) y los campos guardados
  de la periodicidad (`frecuencia`, `periodicidad_*`, solo lectura). La lectura trae `fechas`
  [{codigo, inicio, fin}], `asistentes` y `repeticion` (el objeto de §11 nonies, o null).
- **Escritura**: `inicio` (obligatorio al crear; con periodicidad es su comienzo) y `fin` (la
  duración de cada fecha; por defecto la que tenía, o ninguna); `repeticion` genera las fechas
  (`null` o frecuencia 0 = fecha única; sin indicarla se conserva la del evento). Cualquier
  cambio de fechas rehace la lista entera. `asistentes` [{usuario_delegacion, usuario_codigo}]
  sustituye la lista (usuarios existentes, sin repetir ni el dueño).
- **Avisos** (`ACCAVI` tipo A, ver §11 septies): para el dueño y cada asistente, uno al inicio de
  cada fecha y otro antes según el aviso. Al cambiar las fechas se rehacen todos (como Veolab);
  al cambiar asistentes o aviso se rehacen los de las fechas que no han empezado (Veolab no lo hace).
- **Borrado**: fechas, asistentes y avisos; los documentos del evento van a la papelera (Veolab los
  deja huérfanos). Borrar un estado o clasificación deja sin él a los eventos que lo usan.

## 11 nonies. Periodicidad

`AGE_ColeccionPeriodicidad` de Veolab (`VeolabPeriodicity`), para la agenda y las
planificaciones. Se escribe con el objeto `repeticion`:

| Campo | Significado |
|---|---|
| `frecuencia` | 0 sin periodicidad, 1 diaria, 2 semanal, 3 mensual, 4 anual. |
| `opcion` | Diaria: 0 cada `repetir` días, 1 todos los laborables. Mensual: 0 el día `ordinal`, 1 el `ordinal` `dias`. Anual: 0 el `ordinal` `dias` del mes `repetir`, 1 los meses de `meses`. |
| `repetir` | Intervalo (días, semanas, meses). En la anual opción 0, el mes (0 enero .. 11 diciembre). |
| `ordinal` | Mensual opción 0: día del mes (1..31). Si no: 0 primer, 1 segundo, 2 tercer, 3 cuarto, 4 último. |
| `dias` | Semanal: producto de L 2, M 3, X 5, J 7, V 11, S 13, D 17 (0 = todos), o `dias_semana: ["L","X"]`. Mensual opción 1: 0 día, 1 día laborable, 2..8 lunes..domingo. Anual opción 0: 0 día, 1..7 lunes..domingo. Anual opción 1: máscara de meses (enero 1 .. diciembre 2048), o `meses: [1, 6]`. |
| `fecha_fin` | Fin de la periodicidad (no cuenta si hay `repeticiones`). |
| `repeticiones` | Número de vueltas (días, semanas, meses o años), 0 = sin límite. |
| `trasladar_laborable` | T: cada fecha pasa al siguiente laborable (sábados, domingos y festivos de la delegación, si el módulo de agenda está activo). |

- Las fechas se generan hasta el **horizonte** de periodicidades: `LABCON.CONDPER`, y al menos
  hoy + 180 días (lo que fija Veolab al arrancar). Veolab amplía el horizonte y regenera las
  periódicas por su cuenta; la API no cambia `CONDPER`.
- Como Veolab, el límite es un día a las 00:00: una fecha con hora ese mismo día queda fuera.
- Correcciones respecto a Veolab: no se repiten fechas al trasladar en la diaria, el "último
  lunes..domingo" anual se calcula bien (Veolab resta 7 días de más) y en la anual enero
  (`repetir` 0) no pasa a febrero.
- `POST /periodicidad/fechas` `{inicio, repeticion, delegacion, horizonte}`: las fechas que
  saldrían, sin grabar nada (vista previa).

## 11 decies. Préstamos

Módulo **ALM** (sin él activo y licenciado, las escrituras dan `422`). `/prestamos` (clave
`delegacion` + `codigo`, código de `ACCCFC`; CRUD estándar), como FichaPrestamo:

| Campo | Notas |
|---|---|
| `estado` | `R` registrado (por defecto), `E` entregado, `P` parcialmente devuelto, `D` devuelto, `C` cancelado. |
| `fecha_registro`, `fecha_entrega`, `fecha_devolucion` | Al cambiar el estado se completan con la hora actual: `R` solo registro (borra entrega y devolución), `E` registro y entrega (borra devolución), `P`/`D` las tres; `C` no las toca. Sin estado en la petición, dar la entrega a uno registrado lo pasa a `E` y la devolución a uno entregado, a `D`. |
| `referencia` (50), `observaciones`, `es_archivado` (T/F), `operacion_*` | |
| `lineas` | `[{producto_delegacion, producto_codigo, serie_lote_codigo, cantidad_prestada, cantidad_devuelta}]`; en escritura sustituye la lista (series o lotes existentes, sin repetir; la API exige devuelta ≤ prestada). La lectura añade `cantidad_pendiente`. |
| `cliente_*` | Solo lectura: el cliente de la operación. |

- **Movimientos y existencias**: al crear, cambiar `lineas` o cambiar el estado se borran los
  movimientos del préstamo y se devuelve a cada serie/lote lo que tenía fuera; en `E`, `P` y `D`
  se genera por línea un movimiento `P` (+prestada) y otro `D` (−devuelta) y se descuenta lo no
  devuelto. Si una serie o lote no tiene existencias suficientes no se graba (`422` con la lista).
  Los movimientos no llevan usuario (la API no tiene sesión).
- **Borrado**: devuelve las existencias, borra líneas y movimientos y manda los documentos a la
  papelera.

## 11 undecies. Residuos

Módulo **GDR**. `/residuos` (clave `delegacion` + `codigo`; CRUD estándar), como FichaResiduo:
`descripcion` (100), `fecha_registro` (por defecto ahora), `unidades`, `valor_unitario`,
`valor_total` (lo indica el usuario, no se calcula), `observaciones`, `tipo_residuo_*`
(`/tipos-residuo`), `operacion_*`, `tecnica_*` y `empleado_*` (responsable).

- `fecha_baja` y `es_baja` van juntas: con fecha está de baja; `es_baja: "T"` sin fecha toma la
  actual; `F` borra la fecha.
- `POST /residuos/registro` (RegistroResiduos): los datos comunes más `lineas`
  `[{tipo_residuo_delegacion, tipo_residuo_codigo, unidades, valor_unitario, valor_total}]`; crea
  un residuo por línea con algún importe (las demás se descartan) y devuelve sus claves. A
  diferencia de Veolab, cada alta se audita.

## 11 duodecies. ISO 17025: personal, métodos, recepción y proveedores

Todo desde Veolab 3 (§10 bis: en bases anteriores los campos no salen y los recursos dan
`422`). Réplica de `Autorizaciones.bas`, `FichaAutorizacion`, `FichaEvaluacionProveedor`,
`ALM_ActualizarEvaluacionProveedor` y `COM_CrearNotificacionesEvaluacion`.

**Conformidad en recepción (7.4).** `conformidad_recepcion` en `/operaciones` y
`/planificaciones` (`OPECCFR`, `PLOCCFR`): vacía sin evaluar, `C` conforme, `N` no conforme,
`D` conforme con desviación. Se copia al generar la operación desde la planificación y se
filtra como cualquier campo (`?conformidad_recepcion=N`, `?conformidad_recepcion=` sin
evaluar). La incidencia de calidad que propone el escritorio al marcar `N` no la crea la API.

**Validación de métodos (7.2).** En `/parametros` (`LABTEC`): `estado_metodo` (`TECCVME`:
`V` validado, `F` verificado, vacío sin indicar), `fecha_validacion_metodo` (`TECDVME`),
`fecha_revision_metodo` (`TECDRME`) y `fecha_proxima_revision` (`TECDPRM`).

**Compromisos del personal (4.1, 4.2).** En `/empleados` (`GRHEMP`):
`fecha_compromiso_confidencialidad` (`EMPDCON`) y `fecha_compromiso_imparcialidad`
(`EMPDIMP`).

**Autorizaciones del personal (6.2)** (`/empleados/autorizaciones`, `GRHAUT`; relación con
clave `empleado_delegacion` + `empleado_codigo` + `tecnica_delegacion` + `tecnica_codigo`):
`estado` (`F` en formación · `A` autorizado · `S` suspendido; al crear, `A`),
`fecha_autorizacion` (al crear, hoy), `fecha_caducidad` (vacía: no caduca; no anterior a la
autorización → `422`), `autorizado_por_*` (empleado), `fecha_supervision` y
`resultado_supervision`. La lectura añade `es_vigente` (`T`/`F`, hoy) y `situacion`
(«Autorizado», «Autorizado - Caducada», «En formación», «Suspendido»). Vigente = `A` y sin
caducar. Auditoría como la ficha: sucesos de `GRHAUT` con la fila "empleado - técnica". Al
borrar un empleado o una técnica se borran sus autorizaciones.
- **Sin autorización registrada no cuenta** como no autorizado: quien no las registra no
  nota nada (`VeolabAuthorizations::notAuthorized`).
- **Asignación automática del analista** (`AUP_ElegirAnalista`): donde la API pone el
  titular de la técnica (`LABTYE`, `TYENPOS` 1) al crear operaciones, planificaciones o
  generar operaciones (`VeolabOperationServices::techniqueExtras`), si el titular tiene
  autorización registrada y no vigente se toma el primer empleado de `LABTYE`, por
  posición, que no la tenga así; si no hay ninguno, se queda el titular. El analista que ya
  lleva una línea de la planificación se respeta.
- **Al grabar resultados** (`PUT /resultados`, `RevisarAutorizaciones`), según
  `LABCON.CONCAUT` (`autorizaciones_analistas` en `/configuracion/laboratorio`: `N` no
  comprobar, el de las bases convertidas · `A` avisar · `B` bloquear): en las técnicas con
  valores o marcas en la petición que estén acreditadas (`LABTEC.TECDACR`) y cuyo analista
  (el que queda tras la grabación) tenga autorización registrada y no vigente en la fecha de
  inicio de la operación (hoy si no la tiene), con `A` se graba y se añade un aviso por
  técnica en `avisos` (`columna` nula); con `B` no se graba nada (`422`).

**Proveedores (6.6).** En `/proveedores` (`SINPRO`): acreditación del laboratorio
subcontratado `numero_acreditacion` (`PROCNAC`), `organismo_acreditacion` (`PROCOAC`),
`fecha_caducidad_acreditacion` (`PRODCAC`) y `alcance_acreditacion` (`PROCALC`);
`requisitos_compra` (`PROCREQ`, los que se proponen en los pedidos); y, de solo lectura,
`fecha_ultima_evaluacion` (`PRODUEV`) y `fecha_proxima_evaluacion` (`PRODPEV`).

**Evaluaciones de proveedores** (`/proveedores/evaluaciones`, `SINEVA`; subtabla con clave
`proveedor_delegacion` + `proveedor_codigo` + `codigo`): `fecha` (al crear, hoy; no se vacía),
`resultado` (`P` positiva · `N` negativa · vacío sin resultado), `descripcion`,
`fecha_proxima`, `evaluador_*` (usuario) y `tipo_evaluacion_*` (`/tipos-evaluacion`). A
diferencia de las demás subtablas, el código sale del contador `ACCCLT 'SINEVA'` de la
delegación del proveedor, como `DBS_Autoincremento`.
- Al crear, cambiar o borrar una evaluación, la **última** (por fecha y código) se lleva al
  proveedor: `PRODUEV`, `PRODPEV`, `es_proveedor_aceptado` (`P` → `T`, `N` → `F`; sin
  resultado queda como estaba) y su tipo de evaluación si lo tiene. Sin evaluaciones, las dos
  fechas quedan vacías.
- Y se rehace el **aviso de la próxima evaluación**: se borra la notificación `H` del
  proveedor (`ACCNOT.PRO2*`) con sus avisos y, si hay fecha próxima, el módulo `COM` está
  activo y `LABCON.CONBNEV` (`notificar_evaluaciones_proveedores`), se crea para el
  evaluador de la última si sigue activo, con fecha la próxima y texto "proveedor: fecha".
  Veolab usa el usuario en sesión si el evaluador no vale; la API no tiene sesión y en ese
  caso no crea el aviso.
- Auditoría como la ficha: sucesos de `SINEVA` con la fila "proveedor - código". Un tipo de
  evaluación usado en evaluaciones no se borra (`422`).
- Pedidos (`ALMPED`, con `PEDCREQ`) y entregas (`ALMALB`, con su verificación
  `ALBCEST`/`ALBCVER`/`ALBDVER`/`USV2*`), en §11 quaterdecies. El módulo Calidad, en §11 terdecies.

## 11 terdecies. Calidad: incidencias

Módulo `CAL` de Veolab 3 (ISO/IEC 17025 7.9 quejas, 7.10 trabajo no conforme, 8.5-8.7 riesgos,
mejora y acciones correctivas). Réplica de `Calidad.bas`, `FichaIncidencia`, `Incidencias`,
`TiposIncidencia` y de `COM_CrearNotificacionesIncidencia` / `COM_CrearNotificacionesAccionesCalidad`
(`App\Support\VeolabQuality`). Todos los recursos tienen `$sinceVersion` 3.0 (§10 bis) y escribir
exige el módulo `CAL` activo en `ACCMOD` y licenciado (`422` si no), como cartas de control y
residuos; leer, no. `VeolabLicense` incluye ahora `MEN`, `CAL` y `SOL` en las licencias, como
`Recursos.frm` de Veolab 3 (antes `MEN` no contaba como licenciado con licencia leída y la
mensajería de Veolab 3 respondía `422`).

**Incidencias** (`/incidencias`, `CALINC`; clave `delegacion` + `serie` + `codigo`, código del
contador `ACCCLT` de la serie con las reglas de `ACCCFC 'CALINC'`; serie sin `#`):

| Parámetro | Columna | Notas |
|---|---|---|
| `clase` | `INCCCLA` | `Q` queja · `T` trabajo no conforme · `N` hallazgo de auditoría · `M` mejora · `R` riesgo u oportunidad. Obligatoria; si falta, la que propone el tipo. |
| `origen` | `INCCORI` | `C` cliente · `P` personal · `I`/`E` auditoría interna/externa · `V` proveedor · `A` ensayo de aptitud · `K` carta de control · `Q` equipo · `R` recepción · `D` revisión por la dirección · `O` otro. |
| `tipo_*` | `TIN2DEL`, `TIN2COD` | `/tipos-incidencia`. |
| `estado` | `INCCEST` | `R` registrada · `T` en tratamiento · `V` en verificación · `C` cerrada · `A` anulada. Solo lectura (ver abajo). |
| `fecha_deteccion` | `INCDDET` | Solo el día; al crear, hoy. Obligatoria. |
| `fecha_registro` | `INCTREG` | Fecha y hora de alta; la pone la API. |
| `descripcion` | `INCCDES` | Título, hasta 255; obligatorio. |
| `detalle`, `correccion_inmediata`, `causa`, `otros_casos`, `decision`, `cambios_sistema`, `verificacion_eficacia` | `INCCDET`, `INCCCOI`, `INCCCAU`, `INCCSIM`, `INCCDEC`, `INCCSIS`, `INCCVEF` | Textos (vacío = `''`, como la ficha). |
| `gravedad` | `INCNGRA` | 1 leve · 2 moderada · 3 grave. En riesgos la calcula la API: `probabilidad` × `impacto`. |
| `probabilidad`, `impacto` | `INCNPRO`, `INCNIMP` | 1-3, solo en riesgos (en otras clases se guardan vacíos). |
| `hallazgo` | `INCCGRV` | `M` mayor · `N` menor · `O` observación; solo en hallazgos de auditoría. |
| `apartado` | `INCCAPA` | Apartado de la norma del hallazgo (`4.1` … `8.9`, la lista fija de §11 sexdecies; otro, `422`); solo en hallazgos de auditoría (en otras clases se guarda vacío). |
| `no_conformidad` | `INCBNCO` | Nunca en mejoras ni riesgos. Al cambiar la gravedad se marca con la grave y se quita con las demás (`cmbGravedad_Click`), salvo que se envíe. |
| `puede_darse_en_otros_casos`, `afecta_resultados_emitidos`, `informes_retenidos`, `comunicado_cliente`, `avisar_a_todos` | `INCBSIM`, `INCBAFI`, `INCBRET`, `INCBCCL`, `INCBTOD` | `T`/`F` (al crear, `F`). |
| `fecha_comunicacion_cliente` | `INCDCCL` | Con `comunicado_cliente`, solo en quejas y trabajo no conforme (7.10.1 e). |
| `fecha_acuse_recibo` | `INCDACU` | Solo en quejas. |
| `reanudacion_autorizada_por_*`, `fecha_reanudacion` | `EMA2DEL`, `EMA2COD`, `INCDREA` | Empleado y fecha. |
| `fecha_cierre`, `motivo_anulacion` | `INCDCIE`, `INCCANU` | Solo lectura: los pone el cambio de estado. |
| `coste` | `INCNCOS` | Número (se acepta texto con coma). |
| `cliente_*` | `CLI2DEL`, `CLI2COD` | En quejas, el que reclama. |
| `detectada_por_*`, `responsable_*`, `verificador_*` | `EMP2*`, `EMR2*`, `EMV2*` | Empleados; `detectada_por` es obligatorio. Al asignar uno distinto, debe estar de alta. |
| `usuario_*` | `USU2DEL`, `USU2COD` | Quien la registra; solo al crear. |

- Las referencias vacías se guardan como `NULL` (como la ficha, no `0`/`''`): estos recursos no
  declaran `$foreignKeys`. Un filtro de vacío es `?responsable_codigo[null]=T`.
- La lectura añade `acciones`, `acciones_vencidas` (abiertas con fecha prevista pasada y sin fecha
  real, `CAL_SQLAccionesVencidas`) y `tratamiento_completo` (`T` si pasa por verificación).
- Filtros: los campos (`clase`, `origen`, `estado=R,T,V`, `tipo_*`, `responsable_*`, `cliente_*`,
  `fecha_deteccion[gte]`/`[lte]`, `no_conformidad=T`, `search` en título y detalle) y además
  `abiertas=T|F`; `enlace_tabla` + `enlace_codigo` (+ `enlace_delegacion`, `enlace_serie`): las que
  afectan a ese registro, como `CAL_IncidenciasDe` (las de un cliente incluyen las que lo tienen como
  cliente; las de un producto, las de sus series); `propias_usuario_codigo` (+ `_delegacion`): las
  que ve un usuario sin privilegio de ver todas (`CAL_CondicionPropias`: registradas por él, «avisar
  a todos», o su empleado la detectó, es responsable, verificador, implicado o responsable de una
  acción). Veolab compara solo el código del usuario que registró; la API, delegación y código.
- `estado`, `fecha_registro`, `fecha_cierre` y `motivo_anulacion` se aceptan en `POST`/`PUT` solo si
  no cambian (para devolver lo leído); si cambian, `422`. Igual `usuario_*` en `PUT`.
- **Cerrada o anulada: solo lectura** (`PUT` y las rejillas dan `422`) hasta que se reabre. La
  gestión documental de la incidencia (que Veolab permite tras el cierre) aún no está en la API.

- `apartado` (apartado de la norma de los hallazgos, 4.1-8.9) se devuelve y filtra con `?apartado=8.8`.
- **Historial** (`GET /incidencias/historial?delegacion=&serie=&codigo=`), como la pestaña Historial de la
  ficha: sucesos I, F, C, M y B de `ACCAUD` y `ACAAUD` con `AUDCTAB` CALINC, CALACC, CALIYE o CALIEM y el
  código formateado de la incidencia en `AUDCFIL`, lo más reciente primero. Cada fila: `fecha`, `usuario`
  (o las observaciones de la sesión de la API), `tipo`/`tipo_texto`, `campo`/`campo_texto`, `valor_anterior`,
  `valor_nuevo` y `archivado`. `meta.nivel_registro` (PARNAUN) y `meta.aviso` (MEN01015-01017).

**Estados** (`PUT /incidencias/estado?delegacion=&serie=&codigo=`, cuerpo `{estado,
motivo_anulacion, usuario_delegacion, usuario_codigo}`), como `CambiarEstado` y el botón principal
de la ficha. Camino completo = no conformidad, queja o alguna acción correctiva
(`CAL_TratamientoCompleto` + `CaminoCompleto`); el resto, corto. Desde cada estado solo se admite
el paso de la ficha (si no, `422` diciendo cuál es):

| Paso | Exige |
|---|---|
| R → T iniciar el tratamiento | Clase, responsable y gravedad (en riesgos, probabilidad e impacto). |
| T → V (completo) | Causa y al menos una acción correctiva; todas las acciones con fecha real. |
| T → C (corto) | Corrección inmediata o alguna acción; todas con fecha real. |
| V → C | Verificación de eficacia, al menos una acción correctiva y todas hechas (se vuelve a comprobar), y verificador. |
| → C (los dos) | Trabajo no conforme: la decisión (7.10.1 d). Queja: la fecha de comunicación al cliente. |
| R/T/V → A | `motivo_anulacion` (hasta 255). |
| C/A → R o T reabrir | Queda en tratamiento si tiene responsable; si no, registrada. |

Al cerrar se pone `fecha_cierre` (ahora); al pasar a R, T o V se vacía; al anular se guarda el
motivo y al reabrir una anulada se borra. Se audita como la ficha: fila y campo `CALINCINCCEST` con
los nombres de los estados. Responde `{message, data: {delegacion, serie, codigo, estado}}`.

**Privilegios sin sesión.** El escritorio decide por el usuario conectado (`CAL_INC`: especial 1
responsable de calidad, 0 registrar y tratar las suyas, 2 consultar todas). La API no tiene sesión:
- `usuario_*` en `POST /incidencias` es quien la registra (`USU2*`), a quien no se avisa.
- En el cambio de estado, `usuario_*` es opcional. Con él se comprueba como `PuedeAvanzar`: que
  tenga escritura en `CAL_INC`; iniciar, anular, reabrir y cerrar por el camino corto, solo el
  responsable de calidad; pasar a verificación, también el responsable del tratamiento (su
  empleado); cerrar desde verificación, también el verificador. Y no se le avisa de su propio paso. Sin él, la
  API actúa como responsable de calidad.
- En `PUT` y en las rejillas no se comprueba quién edita cada campo (la ficha bloquea por usuario:
  lo registrado, quien la registró mientras está registrada; la eficacia de las acciones, el
  verificador...): eso lo hace la aplicación cliente; la API aplica lo que depende del estado y de la
  clase.

**Borrado** (`CAL_IncidenciaBorrable` + `CAL_BorrarIncidencia`): las quejas nunca (se anulan,
7.9). Con `?usuario_delegacion=&usuario_codigo=` en el `DELETE`: el responsable de calidad borra
las registradas y las anuladas; los demás, solo las registradas que registraron ellos (Veolab
compara solo el código; la API, delegación y código); además, escritura en `CAL_INC`. Sin usuario,
como responsable de calidad. Se borran sus acciones, enlaces (también los de otras incidencias hacia
ella), implicados, notificaciones con sus avisos y sus documentos van a la papelera. Una queja
grabada tampoco cambia de clase (`PUT` con otra `clase` → `422`).

**Acciones** (`/incidencias/acciones`, `CALACC`; clave `incidencia_delegacion` + `incidencia_serie`
+ `incidencia_codigo` + `codigo`): `tipo` (`C` corrección · `A` correctiva · `M` mejora o
tratamiento del riesgo), `descripcion`, `responsable_*`, `fecha_prevista`, `fecha_real`,
`resultado`, `eficaz` (`T`/`F`/vacío sin verificar), `fecha_eficacia`, `verificador_*`. Solo con la
incidencia en tratamiento o en verificación. Nueva: número siguiente de la incidencia; tipo `A` (en
riesgos y mejoras, `M`) y el responsable de la incidencia, como `AnadirAccion`.

**A qué afecta** (`/incidencias/enlaces`, `CALIYE`; solo `GET`, `POST`, `DELETE`): `tabla`
(`LABOPE`, `LABINF`, `SINCLI`, `SINPRO`, `GRHEMP`, `LABTEC`, `ALMSEL`, `ALMPRD`, `LABCDC`, `CALINC` y,
desde la ficha del ensayo de aptitud, `CALEAP`, §11 quindecies, de la auditoría, `CALAUI`, §11 sexdecies, de la revisión por la dirección, `CALRDI`, §11 septendecies, o del objetivo de calidad, `CALOBJ` con serie `''`, §11 undevicies) y la clave en texto `enlace_delegacion`, `enlace_serie`, `enlace_codigo` (en `ALMSEL`, el producto
va en `enlace_serie`; la serie oculta `-` se enlaza como el producto). El registro debe existir, no
se repite ni se enlaza la incidencia consigo misma. La lectura añade `tabla_descripcion`,
`enlace_codigo_formateado`, `enlace_descripcion` y `existe`. `POST /incidencias/enlaces/informes`
(`InformesAfectados`) enlaza los informes (`LABIYO`) de las operaciones enlazadas. Los enlaces
`CALAUI`, `CALRDI`, `CALOBJ` y `CALEAP` solo los quita el responsable de calidad (`QuitarEnlaces`):
con `?usuario_delegacion=&usuario_codigo=` en el `DELETE` se comprueba (`422` si no lo es); sin
usuario, como responsable de calidad. Al quitar un `CALAUI`, las preguntas de la lista de
verificación de esa auditoría que tenían la incidencia como hallazgo se quedan sin él
(`CALAUP.INC2SER`/`INC2COD` a `NULL`).

**Implicados** (`/incidencias/implicados`, `CALIEM`; solo `GET`, `POST`, `DELETE`): empleados de
alta que reciben los avisos; la lectura añade `empleado_nombre`.

Las tres rejillas: con la incidencia abierta y el módulo activo; auditoría de la incidencia con la
tabla modificada (`CALACC`, `CALIYE`, `CALIEM`), como `FichaIncidencia.Grabar`.

**Tipos** (`/tipos-incidencia`, `CALTIN`; contador `ACCCLT 'CALTIN'`): `descripcion` única entre
los comunes y los de la delegación, `clase` que proponen (opcional) y `es_baja`. Uno usado en
incidencias no se borra (`422`).

**Avisos** (tras el commit, en su propia transacción; un fallo no deshace la grabación):
- `Q` (`COM_CrearNotificacionesIncidencia`, con los módulos `COM` y `CAL` y `LABCON.CONBNIN`) al
  registrar y en cada cambio de estado: registrada → responsables de calidad; en tratamiento →
  responsable, verificador e implicados (al reabrir, solo el responsable); en verificación →
  verificador; cerrada o anulada → quien la detectó e implicados; con «avisar a todos», además
  todos los usuarios con acceso a incidencias. Texto «estado: título»; cada usuario conserva solo la
  última de cada incidencia.
- `K` (`COM_CrearNotificacionesAccionesCalidad`, con `LABCON.CONBNAC`) al registrar, al cambiar de
  estado y al tocar las acciones: se borran y se crea una por usuario del responsable de cada acción
  pendiente, con aviso en su fecha prevista, mientras la incidencia esté abierta.
- Un `PUT` de la incidencia sin cambio de estado no avisa, como la ficha.

**Registros enlazados que se borran.** Al borrar operaciones, informes, clientes, proveedores,
empleados, técnicas o series, Veolab no toca `CALIYE` ni las referencias de `CALINC`: el enlace
queda y la ficha lo muestra sin descripción. La API hace lo mismo (`existe` = `F`).

**Sin hacer:** documentos de la incidencia en `/documentos` (`DOCFAT.INC2SER`/`INC2COD`), la
exportación con plantilla, la advertencia de la ficha cuando en una queja el verificador coincide
con el responsable o con quien la detectó (7.9.6; es una pregunta, no un bloqueo) y abrir una
incidencia desde la recepción no conforme.

## 11 quaterdecies. Compras: pedidos y entregas

Desde Veolab 3 (§10 bis: en bases anteriores los dos recursos dan `422`) y con el módulo
**ALM** activo y licenciado para escribir (`422` si no; la lectura no lo exige, como
`/prestamos`). Réplica de `FichaPedido`, `Pedidos`, `FichaEntrega`, `Entregas` y
`Almacen.bas` (`ALM_PrecioProveedor`, `ALM_FormatoCompra`, `ALM_CantidadPorUnidad`,
`ALM_RecalcularEstadoPedido`, `ALM_EntregaBorrable`, `ALM_BorrarEntrega`,
`ALM_AvisoProveedor`), en `App\Support\VeolabPurchases`. Las líneas van **embebidas** en
`lineas`, como en presupuestos, contratos, facturas y préstamos.

**Pedidos** (`/pedidos`, `ALMPED` + `ALMLPE`; clave `delegacion` + `serie` + `codigo`,
contador `ACCCLT 'ALMPED'` por serie con el múltiplo y las reglas de `ACCCFC`; sin serie, la
predeterminada de la delegación (`CLTBPRE`), como `ConstruirListaSeries`; `#` no se admite en
la serie).

| Campo | Columna | Notas |
|---|---|---|
| `fecha` | `PEDDFEC` | Al crear, ahora. |
| `estado` | `PEDCEST` | `B` borrador (al crear) · `E` enviado · `P` recibido en parte · `R` recibido · `C` cancelado. |
| `referencia` (50), `fecha_prevista`, `fecha_envio`, `observaciones`, `es_archivado` | `PEDCREF`, `PEDDPRE`, `PEDDENV`, `PEDCOBS`, `PEDBARC` | |
| `requisitos` | `PEDCREQ` | Al crear, si vienen vacíos, los del proveedor (`SINPRO.PROCREQ`, `requisitos_compra`), como `RequisitosProveedor`. |
| `proveedor_*` | `PRO2*` | Obligatorio. Fijo en cuanto se ha recibido algo o con el pedido recibido o cancelado (`422`). |
| `empleado_*` | `EMP2*` | Quien pide. Al crear sin él, el empleado del `usuario_*` (`ACCUSU.EMP2*`, `EmpleadoUsuario`). |
| `usuario_*` | `USU2*` | Quien lo crea: solo al crear (la API no tiene sesión); después, `422`. |
| `total` | `PEDNTOT` | Solo lectura: suma de los importes de las líneas, sin impuestos. |

`lineas` `[{linea, producto_delegacion, producto_codigo, referencia, descripcion, cantidad,
precio, descuento, formato_codigo, contenido}]` sustituye la rejilla (`ALMLPE`); la lectura
añade `formato`, `importe`, `cantidad_recibida`, `cantidad_pendiente` y `orden`:
- Una línea sin producto es un artículo fuera del catálogo (`PRD2*` nulos). El número de línea
  (`LPE1COD`) lo citan las entregas: se conserva, y una línea sin `linea` toma el siguiente.
  Lo recibido (`LPENREC`) lo ponen las entregas y se conserva por número.
- Lo que no se envía se propone como al añadir el producto: `referencia` y `precio` del
  proveedor (`ALMPYP` o, si es el predeterminado del producto, `ALMPRD`); `descripcion` la del
  producto; `cantidad` 1; formato de compra (`ALM_FormatoCompra`: el de `ALMPYP.FOR2COD`, si no
  el predeterminado o el primero de `ALMFOR`, y sin formatos el contenido del último lote; los
  equipos, por unidades sin formato). `formato_codigo` indicado: un formato del producto (no
  en equipos), del que se copian descripción (`LPECFOR`) y contenido (`LPENFAC`, salvo que se
  dé `contenido`); `formato_codigo: null` deja la línea sin formato.
- `descuento` (`"10%"` o importe) con el separador decimal del laboratorio; `importe`
  (`LPENTOT`) = cantidad × precio con el descuento.
- `422` (mensajes de la ficha): cantidad por debajo de lo recibido, quitar una línea con algo
  recibido, cambiar el producto de una línea con algo recibido, y cambiar las líneas de un
  pedido recibido o cancelado (el estado que queda tras la petición).
- Al cambiar de proveedor sin enviar `lineas`, las líneas sin formato o sin referencia ni
  precio toman los del nuevo (`CompletarPreciosProveedor`).

**Estado** (`cmbEstadoPedido_Click`, `EstadoPermitido`): a mano, `B` o `E` solo mientras no ha
llegado nada; `C` siempre; `P` o `R` solo si es el que corresponde a lo recibido (al reabrir
un cancelado): los ponen las entregas (`422` con el texto de la ficha). Pasar a `E` pone
`fecha_envio` si no la tiene; dar una `fecha_envio` nueva a un borrador sin indicar estado lo
pasa a `E`. Cambiar líneas no recalcula el estado (como la ficha).

**Borrado** (`Pedidos.Borrar`): solo si ninguna línea de entrega lo cita (`422`: «ya se ha
recibido parte de él. Puede cancelarlo o archivarlo.»); borra sus líneas. Como Veolab, no toca
sus documentos.

**Auditoría** como la ficha: `I`/`B` con la fila del pedido; en una modificación `F` (nivel 2)
o un `C` por campo cambiado (nivel 3; sin el total) y un `C` `ALMLPE` si cambian las líneas.

**Entregas** (`/entregas`, `ALMALB` + `ALMLAL`; clave `delegacion` + `codigo`, contador
`ACCCLT 'ALMALB'` sin serie).

| Campo | Columna | Notas |
|---|---|---|
| `fecha` | `ALBDFEC` | Fecha de recepción; al crear, ahora. |
| `numero_albaran` (50), `observaciones`, `es_archivado` | `ALBCNUM`, `ALBCOBS`, `ALBBARC` | |
| `proveedor_*` | `PRO2*` | Al crear; sin él, el del pedido de la primera línea que lo cite. Fijo tras el alta. |
| `usuario_*` | `USU2*` | Quien recibe: solo al crear; también va en los movimientos de compra. |
| `verificacion` | `ALBCEST` | Vacía sin verificar · `A` aceptada · `N` no conforme (ISO 17025 6.6.2). |
| `resultado_verificacion` | `ALBCVER` | |
| `fecha_verificacion`, `verificador_*` | `ALBDVER`, `USV2*` | Al cambiar `verificacion`: a `A`/`N`, ahora y el `verificador_*` de la petición (vacío si no se envía: la API no tiene sesión); a vacía, se borran. Enviar `verificador_*` sin cambiar la verificación da `422`. |
| `poner_fuera_de_uso` | — | `T` al pasar a `N` (en el alta o después): como `EntregaNoConforme`, sus series y lotes sin estado, nuevos, en uso o en límite de uso pasan a fuera de uso (`SELCESA = 'F'`), nunca la serie oculta `-`; un suceso `C` `ALMSELSELCESA` por serie. En otro caso, `422`. |
| `actualizar_precios` | — | Solo al crear. Ver precios. |

`lineas` (solo al crear; después `422`) `[{pedido_delegacion, pedido_serie, pedido_codigo,
pedido_linea, producto_delegacion, producto_codigo, descripcion, cantidad, precio,
serie_lote_codigo, lote_fabricante, fecha_caducidad, formato_codigo, contenido}]`. La lectura
devuelve las grabadas (una por unidad en los equipos) con `linea`, `serie_lote_codigo` (la
creada, o `-`), `formato`, `verificacion`, `observaciones` y `orden`. `?pedido_codigo=` (con
`pedido_delegacion` y `pedido_serie`) lista las entregas con líneas de ese pedido.

**Alta, paso a paso** (`FichaEntrega.Grabar` → `GrabarLineas`):
1. Validación (`CamposValidos`): proveedor (`422` si falta); fecha de caducidad válida; en las
   líneas de producto, cantidad > 0; en las de equipo, unidades enteras; las series indicadas
   no pueden existir ni repetirse en la entrega (ni ser `-`). Una línea de pedido exige
   `pedido_linea`, que exista, que el pedido sea del proveedor de la entrega y que no esté
   recibido ni cancelado (en Veolab no se puede recibir de ellos); toma de esa línea el
   producto (si se indica otro, `422`), la descripción, lo pendiente como cantidad, el precio
   neto pedido (importe ÷ cantidad, con su descuento) y su formato (sin contenido, el que
   propone `ALM_FormatoCompra`). Una línea de producto sin pedido toma la descripción, el
   precio del proveedor y su formato; una sin producto es un artículo suelto.
2. Precios (`PreciosDistintos`, antes de grabar): líneas de producto con precio cuyo precio
   del proveedor no coincide (salvo las de pedido al precio pedido). Veolab pregunta; la API
   los guarda solo con `actualizar_precios: "T"` (por defecto no, para no cambiar el catálogo
   del proveedor sin pedirlo) y, si no, lo dice en `avisos`. Las líneas cuyo proveedor aún no
   tiene precio lo toman siempre.
3. Cabecera `ALMALB` y suceso `I`.
4. Por línea (en equipos, por unidad), según el producto (`TipoProducto`):
   - **Sin series** (`PRDBSEL = F` o inventario básico): la serie oculta (`ALM_ObtenerSerieUnica`,
     que la crea si falta) suma cantidad × contenido del formato (o su cantidad por unidad).
   - **Con series o lotes y equipos**: serie nueva con el código indicado, el lote del fabricante
     (en lotes) o el siguiente número; en equipos con varias unidades, los números siguientes o
     `código-2`, `código-3`... (`SerieDeUnidad`). `SELCESA` y `SELCESR` `N`, alta ahora, recepción
     la fecha de la entrega, caducidad, cantidad por unidad = contenido del formato (en equipos o
     sin él, `ALM_CantidadPorUnidad`), unidades del lote y en existencias = cantidad, precio,
     proveedor y la entrega (`ALB2*`).
   - Movimiento de compra `C` en la delegación de la entrega: fecha de recepción, cantidad,
     usuario que recibe, entrega, proveedor y precio (`MOVNPRE`).
   - Línea `ALMLAL` (sin producto, sin pedido o sin serie, con esos campos nulos) y, si viene de
     un pedido, `LPENREC` += cantidad.
5. Estado de cada pedido tocado (`ALM_RecalcularEstadoPedido`: `R` si no falta nada, `P` si
   falta algo y llegó algo, `E` si no llegó nada; los cancelados no cambian), precios del
   proveedor (`ALMPYP` con `PYPDPRE`, o alta con referencia vacía; y `ALMPRD.PRDNPRE` si es su
   proveedor predeterminado) y existencias de los productos (`ALM_RecalcularExistenciasProductos`).
6. Con `verificacion: "N"` y `poner_fuera_de_uso: "T"`, fuera de uso lo recibido.

La respuesta añade `avisos` (`[{aviso}]`) con el aviso del proveedor (`ALM_AvisoProveedor`:
evaluación vencida o proveedor no aceptado), los precios distintos no guardados y las series
puestas fuera de uso. Los pedidos dan el aviso del proveedor al crear o cambiarlo.

**Modificación**: fecha, número de albarán, observaciones, archivado y verificación. La
auditoría es un suceso de fila (`F`), como la ficha. La incidencia de calidad que propone
Veolab al marcar no conforme no la crea la API.

**Borrado** (`ALM_EntregaBorrable`): solo si ninguna de las series que creó tiene más
movimientos que su compra, préstamos, materias primas, operaciones o planificaciones (`422`).
Deshace como `ALM_BorrarEntrega`: resta de las series que no creó (la oculta) lo que les sumó,
resta lo recibido de las líneas de pedido y recalcula su estado, borra sus movimientos, las
series que creó y sus líneas, manda sus documentos (`DOCFAT.ALB2COD`) a la papelera y
recalcula las existencias. Suceso `B`.

**Sin exponer:** los formatos de compra de los productos (`ALMFOR`) y `PYPDPRE`/`FOR2COD` en
`/proveedores/productos` (se usan, pero se mantienen en Veolab); los documentos de pedidos y
entregas en `/documentos`; la exportación del pedido con plantilla y su envío al proveedor.

## 11 quindecies. Calidad: ensayos de aptitud

Ensayos de aptitud e intercomparaciones, fase 1 (módulo `CAL` de Veolab 3; ISO/IEC 17025:2017
7.7.2): rondas o participaciones, sus muestras y los resultados por parámetro con la puntuación y
la evaluación del organizador y las que calcula Veolab (ISO 13528). Réplica de `Aptitud.bas`,
`FichaEnsayoAptitud`, `EnsayosAptitud` y `ResultadosAptitud` (`App\Support\VeolabProficiency`).
Como las incidencias (§11 terdecies): `$sinceVersion` 3.0 (§10 bis) y escribir exige el módulo
`CAL` activo y licenciado (`422` si no); leer, no. Las referencias vacías se guardan como `NULL`
(sin `$foreignKeys`). La fase 2 (familias de ensayos, plan de intercomparaciones, tendencia por
parámetro y aviso `J` de la fecha límite) está al final de esta sección
(`App\Support\VeolabProficiencyPlan`).

**Rondas** (`/ensayos-aptitud`, `CALEAP`; clave `delegacion` + `serie` + `codigo`, código del
contador `ACCCLT` de la serie con las reglas de `ACCCFC 'CALEAP'`; serie sin `#`):

| Parámetro | Columna | Notas |
|---|---|---|
| `organizador_delegacion`, `organizador_codigo` | `PRO2DEL`, `PRO2COD` | Un proveedor (`/proveedores`); al asignarlo, de alta. Obligatorio. |
| `programa`, `ronda` | `EAPCPRG`, `EAPCRON` | Hasta 100 y 50. Obligatorio uno de los dos. |
| `descripcion` | `EAPCDES` | Matriz o descripción, hasta 255. |
| `fecha_recepcion`, `fecha_limite`, `fecha_envio`, `fecha_informe` | `EAPDREC`, `EAPDLIM`, `EAPDENV`, `EAPDINF` | Recepción de la muestra, límite de envío, envío de resultados e informe del organizador. Solo el día. |
| `estado` | `EAPCEST` | `P` pendiente de enviar · `E` enviada · `V` evaluada · `C` cerrada. Al crear, `P`. |
| `limite_satisfactorio`, `limite_insatisfactorio`, `limite_en` | `EAPNLSA`, `EAPNLIN`, `EAPNLEN` | Límites del programa para \|z\|, z′ y zeta (vacíos = 2 y 3) y para \|En\| (vacío = 1). Número con coma o punto; si no es un número, `422` (la ficha lo vacía). Los informados deben ser mayores que cero y el satisfactorio, menor que el insatisfactorio (`422`, como la ficha). |
| `conclusion` | `EAPCCON` | Conclusión del laboratorio; obligatoria para cerrar. |
| `revisor_delegacion`, `revisor_codigo`, `fecha_conclusion` | `EMR2DEL`, `EMR2COD`, `EAPDCON` | Quién revisa la conclusión (empleado; al asignarlo, de alta) y cuándo. |
| `observaciones` | `EAPCOBS` | Texto. |
| `fecha_registro`, `usuario_*` | `EAPTREG`, `USU2DEL`, `USU2COD` | Alta y quién la registra: la fecha la pone la API; el usuario, solo al crear. Se aceptan en `PUT` si no cambian. |

- **Estado** (`EstadoAGrabar`): se indica como en la ficha (cualquiera de los cuatro), con dos
  pasos automáticos al grabar: pendiente con `fecha_envio` → `E`; pendiente o enviada con
  `fecha_informe` → `V`.
- **Cerrar** (`C`) exige `conclusion`, aunque todo sea satisfactorio (ENAC NT-03; `422`). Sin
  `fecha_conclusion`, la de hoy. Veolab pone además como revisor al empleado del usuario
  conectado si no hay ninguno; la API no tiene sesión: envíe `revisor_*`.
- **Cerrada: solo lectura** (`EstadoSegunRonda`): un `PUT` que la deja cerrada solo admite los
  valores que ya tiene (`422` si cambia algo); cambiando el `estado` se reabre y se puede cambiar
  todo en la misma petición. Sus muestras y resultados tampoco cambian (`422`). Abrir la
  incidencia sí se puede.
- Al cambiar los límites se vuelven a evaluar todos sus resultados (`RecalcularRejilla`).
- Lectura: añade `organizador_nombre`, `muestras`, `resultados`, `peor_evaluacion`
  (`EAP_SQLPeorEvaluacion`: 0 sin evaluar, 1 satisfactorios, 2 algún cuestionable, 3 algún
  insatisfactorio o falso), `fuera_de_plazo` (`T` pendiente con la fecha límite pasada; el color
  rosado del listado), `incidencias` (enlazadas a la ronda) y `proponer_incidencia` (`T` con algún
  insatisfactorio, falso positivo o falso negativo y sin incidencias: lo que la ficha pregunta).
- Filtros: los campos (`estado=P,E`, `organizador_*`, `programa[like]=`, `fecha_recepcion[gte]=`,
  `search` en programa, ronda y descripción…) y además `abiertas=T|F` (sin cerrar o cerradas),
  `anio=AAAA` (por la recepción o, si falta, la fecha límite, `EAP_SQLAnio`),
  `con_insatisfactorios=T|F`, `parametro_delegacion` + `parametro_codigo` (con resultados de ese
  parámetro) y `operacion_delegacion` + `operacion_serie` + `operacion_codigo` (las que tienen esa
  operación como muestra, `EAP_SQLRondasOperaciones`).
- **Borrado** (`EAP_Borrable` + `EAP_Borrar`): una cerrada no se elimina (es el registro de la
  participación; `422`). Se borran sus resultados, sus muestras y los enlaces de las incidencias
  hacia ella (`CALIYE`; la incidencia queda), y sus documentos (`DOCFAT.EAP2SER`/`EAP2COD`) van a
  la papelera.

**Muestras** (`/ensayos-aptitud/muestras`, `CALEAO`; clave `ensayo_delegacion` + `ensayo_serie` +
`ensayo_codigo` + `operacion_delegacion` + `operacion_serie` + `operacion_codigo`): operaciones
normales de Veolab que son las muestras de la ronda (cualquiera salvo las anuladas, `OPEBANU`; no
se repiten), con `muestra` (`EAOCMUE`, identificación para el organizador, hasta 50; al crear sin
ella, la letra siguiente A, B… por el número de muestras) y `orden` (`EAONORD`; al crear sin él,
al final). En `PUT` solo cambian `muestra` y `orden`. Al quitar una, sus resultados se quedan sin
muestra (`QuitarMuestras`). La lectura añade `operacion_codigo_formateado`,
`operacion_fecha_registro` y `operacion_descripcion`.

**Resultados** (`/ensayos-aptitud/resultados`, `CALEAR`; clave `ensayo_*` + `codigo`, número de
línea, el siguiente al crear):

| Parámetro | Columna | Notas |
|---|---|---|
| `operacion_*` | `OPE2DEL`, `OPE2SER`, `OPE2COD` | La muestra: una operación de la ronda (`422` si no lo es) o ninguna. |
| `parametro_delegacion`, `parametro_codigo` | `TEC2DEL`, `TEC2COD` | Técnica (`/parametros`). Al asignarla se toman su nombre (si no se envía `parametro_nombre`) y, si están vacíos, su unidad y su método (`ElegirParametro`). |
| `parametro_nombre`, `resultado`, `unidad`, `metodo` | `EARCPAR`, `EARCRES`, `EARCUNI`, `EARCMET` | Lo enviado al organizador (texto; hasta 255, la unidad 50). |
| `valor_asignado` | `EARCVAS` | Texto (admite cualitativos), hasta 50. |
| `desviacion` | `EARNDES` | σ para la evaluación; en D %, la desviación admitida en %. |
| `incertidumbre_asignado`, `incertidumbre_laboratorio` | `EARNINV`, `EARNINL` | u(X) (en En, expandida) y la del laboratorio (zeta típica, En expandida). |
| `tipo_puntuacion` | `EARCTPU` | `Z` z · `P` z′ · `T` zeta · `E` En · `D` D % · `C` cualitativo. Vacío = `Z`. |
| `sobre_log10` | `EARBLOG` | `T` puntuación sobre log10 (recuentos), salvo en D %. |
| `puntuacion_organizador`, `evaluacion_organizador` | `EARNPOR`, `EARCEVO` | Tal como da el informe. Evaluación: `S` satisfactorio · `Q` cuestionable · `I` insatisfactorio · `C` correcto · `P` falso positivo · `N` falso negativo. |
| `puntuacion_calculada`, `evaluacion`, `evaluacion_propuesta` | `EARNPCA`, `EARCEVA`, `EARBPRO` | **Calculados por la API** al grabar (si se envían, se ignoran). |
| `observaciones` | `EARCOBS` | Hasta 255. |

- Números (`desviacion`, incertidumbres, `puntuacion_organizador`) con coma o punto y sin
  separador de miles; si no es un número, `422` (`MEN00838`). Textos vacíos, `NULL`.
- **Cálculo** (`EAP_CalcularPuntuacion`), con x el resultado y X el valor asignado (sus log10 con
  `sobre_log10`, salvo en D %): z = (x − X) / σ; z′ = (x − X) / √(σ² + u(X)²); zeta y En =
  (x − X) / √(u(x)² + u(X)²); D % = 100 (x − X) / X. Sin datos numéricos (resultado «<10»,
  cualitativo, denominador 0, log de un valor ≤ 0) no hay puntuación. Se guarda redondeada a 2
  decimales.
- **Evaluación** (`EAP_EvaluarFila`): la del organizador si la da (`evaluacion_propuesta` = `F`);
  si no, la propone Veolab (`T`): en cualitativos, correcto si coincide con el valor asignado o los
  dos son negativos («<», «nd», «ausencia», «no detectado», «negativo»…), falso positivo si el
  asignado es negativo y el resultado no, falso negativo al revés; en el resto, con la puntuación
  del organizador o, si no la da, con la calculada (\|·\| redondeada a 2 decimales): z, z′ y zeta
  satisfactorio hasta `limite_satisfactorio`, insatisfactorio desde `limite_insatisfactorio`,
  cuestionable entre ambos; En satisfactorio hasta `limite_en`, si no insatisfactorio; D %
  satisfactorio si no pasa de `desviacion` (sin ella, sin evaluación).
- Nueva fila sin muestra ni tipo: los de la última fila (o la primera muestra y z), como
  `AnadirResultado`.
- Lectura: añade `muestra` (su identificación en la ronda), `difiere` (`T` si la calculada se
  aparta de la del organizador más de 0,1, o 1 punto en D %: el rojo de la ficha) y, de la ronda,
  `organizador_*`, `programa`, `ronda`, `fecha_recepcion` y `estado_ensayo`.
- Filtros (listado `ResultadosAptitud`): los campos (`parametro_*`, `evaluacion=I,P,N`,
  `tipo_puntuacion`, `ensayo_*`…) y además `no_satisfactorias=T` (`Q`, `I`, `P`, `N`),
  `difiere=T`, y de la ronda `organizador_delegacion` + `organizador_codigo`, `programa` y `ronda`
  (contienen; la ronda también en la descripción), `anio=AAAA` y `estado_ensayo=P,E,V,C`.

**Traer resultados de las operaciones** (`POST /ensayos-aptitud/resultados/traer`, cuerpo
`{ensayo_delegacion, ensayo_serie, ensayo_codigo}`, `TraerResultados`): por cada muestra (en su
orden) y cada parámetro de la operación (`LABRES`, en su orden) con resultado, añade una fila con
nombre, unidad, método y el resultado tal como se informa (`EAP_ResultadoTecnica`: la primera
columna de `LABCOR` con valor marcada para el informe o, si no hay, la primera con valor; ni
vacíos ni `N/A`). No repite los que ya están (misma operación y parámetro). Tipo y log10 de la
última fila (o z). Sin muestras, `422`. Responde `{message, data: [filas añadidas]}`.

**Incidencia de la ronda** (ISO 17025 7.7.2 y 7.10). La ficha, al grabar con algún
insatisfactorio, falso positivo o falso negativo y sin incidencias enlazadas, pregunta si se abre
una, y su pestaña Incidencias tiene el botón de nueva. En la API:
- `POST /ensayos-aptitud/incidencia?delegacion=&serie=&codigo=` (el botón; siempre crea una).
  Responde `201` con la clave de la incidencia.
- `abrir_incidencia=T` en el `PUT` de la ronda o en el `POST`/`PUT` de un resultado (la
  pregunta): solo si, tras grabar, hay algún insatisfactorio o falso y ninguna incidencia
  enlazada; si no, no hace nada.
- En los dos, `incidencia_serie` (vacía por defecto), `incidencia_detectada_por_delegacion` +
  `incidencia_detectada_por_codigo` e `incidencia_usuario_delegacion` + `incidencia_usuario_codigo`
  (quién la registra; con escritura en `CAL_INC`). Quien la detecta es obligatorio (`422`): el
  indicado o, si no, el empleado del usuario; debe estar de alta.
- Se registra como `NuevaIncidencia`: trabajo no conforme (`T`), origen ensayo de aptitud (`A`),
  título «Ensayo de aptitud *programa ronda* (o el código): resultados insatisfactorios»,
  detectada hoy, el cliente de la primera muestra que lo tenga, en la delegación de la ronda, y
  enlazada (`CALIYE`) a la ronda y a sus muestras. Veolab abre la ficha para completarla; la API
  la deja registrada (se completa con `PUT /incidencias`). Código como `POST /incidencias`; si
  `ACCCFC 'CALINC'` no es autonumérico, `422` (créela con `/incidencias` y
  `/incidencias/enlaces`). Avisos `Q` y `K` tras el commit.
- `/incidencias/enlaces` admite la tabla `CALEAP` (en `enlace_serie`, la serie de la ronda) y
  `GET /incidencias?enlace_tabla=CALEAP&enlace_delegacion=&enlace_serie=&enlace_codigo=` da las
  incidencias de una ronda.

**Auditoría**: la ronda como las demás fichas (inserción, fila y campos, borrado); los cambios en
muestras y resultados, y traer resultados, como suceso de la ronda con la tabla modificada
(`CALEAO`, `CALEAR`), como `FichaEnsayoAptitud.Grabar`.

### Fase 2: familias, plan, tendencia y aviso J

Réplica de `PlanAptitud`, `FichaFamiliaAptitud`, `GraficoAptitud`, el filtro de tendencia de
`ResultadosAptitud` y `EAP_CalcularPlan`, `EAP_MarcarAvisos` y `COM_CrearNotificacionesAptitud`
(funcionalidad `CAL_EAF` en Veolab). Veolab limita el plan y la tendencia a la delegación del
usuario y las comunes (`EAP_SQLDelegacion`); la API no tiene sesión: el plan admite
`?delegacion=` (esa y la común, `''`) y la tendencia y los avisos usan todas.

**Familias de ensayos** (`/ensayos-aptitud/familias`, `CALFAE`; clave `delegacion` + `codigo`,
código del contador `ACCCLT` de `CALFAE` como `DBS_Autoincremento`): ensayos con la misma técnica
que se representan entre sí (ENAC NT-03).

| Parámetro | Columna | Notas |
|---|---|---|
| `nombre` | `FAECNOM` | Hasta 100. Obligatorio (`422`, `MEN00850`). |
| `descripcion` | `FAECDES` | Descripción o justificación (técnica común, programas disponibles…). |
| `frecuencia` | `FAENFRE` | Frecuencia mínima de participación en años, de 1 a 10 (la ficha); vacía = 1 (se graba 1). |
| `programa` | `FAECPRG` | Programa o esquema previsto, hasta 100. |
| `organizador_delegacion`, `organizador_codigo` | `PRO2DEL`, `PRO2COD` | Organizador previsto: un proveedor; al asignarlo, de alta. |
| `baja` | `FAEBBAJ` | `T` de baja (fuera del plan por defecto y de la cobertura). Al crear, `F`. Atajo `?is_deleted=`. |
| `parametros` | `CALFAT` | `[{parametro_delegacion, parametro_codigo}]`: **sustituye** los de la familia (la ficha los reescribe al grabar). Técnicas existentes y sin repetir (`422`); un parámetro puede estar en varias familias. |

- Textos recortados y vacíos como `NULL` (`TextoSQL` de la ficha).
- Lectura: añade `frecuencia_texto` («Cada año», «Cada 3 años»), `organizador_nombre`,
  `parametros` (con `parametro_nombre`, el de la técnica o su código, en el orden de la ficha) y
  `numero_parametros`. Filtro `?parametro_delegacion=&parametro_codigo=` (familias con ese
  parámetro) además de los campos y `search` (nombre, programa y descripción).
- Escribir exige el módulo `CAL`. Al eliminar se borran sus parámetros; las rondas y sus
  resultados no se tocan (`PlanAptitud.Eliminar`).
- Auditoría como la ficha: fila «código - nombre» (inserción, modificación, borrado); al cambiar
  solo los parámetros, suceso de fila y, con nivel 3, campo `CALFAT` sin valores.

**Plan de intercomparaciones** (`GET /ensayos-aptitud/plan?desde=&hasta=&bajas=T&delegacion=`,
solo lectura; `EAP_CalcularPlan`): el cuadro por años de `desde` a `hasta` (años de cuatro
cifras; por defecto los cuatro anteriores al actual y el actual; `hasta` menor que `desde` pasa a
`desde`; como máximo 8 años, moviendo `desde`). Sin `bajas=T`, sin las familias de baja. Responde
`{data: {desde, hasta, anio_actual, anios, familias, sin_familia, resumen, resultados_anio}}`:

- `familias[]`: los campos de la familia (`delegacion`, `codigo`, `nombre`, `frecuencia`,
  `frecuencia_texto`, `programa`, `organizador_*`, `organizador_nombre`, `baja`, `parametros`,
  `numero_parametros`) y lo calculado: `ultima` (último año con participación hasta el actual, o
  null), `proxima`, `situacion` (`A` al día · `E` prevista este año · `S` sin participación en su
  periodo, en rojo en Veolab) con `situacion_texto`, `rondas` (en los años del cuadro) y
  `anios[] = {anio, estado, texto}`, con `estado` `S` · `Q` · `I` (realizado, con la peor
  evaluación) · `R` en curso · `V` previsto · `N` no realizado · null nada.
- **Realizado**: las rondas con resultados (`CALEAR`) de alguno de sus parámetros, en el año de la
  recepción de la muestra o, si falta, de la fecha límite (`EAP_SQLAnio`), sea cual sea su estado.
  El año toma la peor evaluación de esos resultados: correcto cuenta como satisfactorio y los
  falsos como insatisfactorio; sin evaluar, `R` en curso (gravedad nada < en curso < `S` < `Q` <
  `I`).
- **Previsto**: la próxima participación es el año de la última (hasta el actual) más la
  frecuencia, y luego cada tantos años; sin ninguna, el actual. `situacion` `S` si ese año ya pasó
  (o nunca hubo), `E` si es el actual y `A` si es posterior. Los años sin nada desde la próxima y
  cada `frecuencia` años salen `N` (pasados) o `V` (el actual y futuros).
- `sin_familia[]`: parámetros acreditados (`LABTEC.TECDACR`) y no de baja que no están en
  ninguna familia activa (la cobertura del alcance), con `parametro_*`, `parametro_nombre` y
  `fecha_acreditacion`.
- `resumen` (`EAP_ResumenPlan`, marcador `resumenplan`): `{familias, al_dia, este_anio,
  sin_participacion, sin_familia, texto}`, de las familias que no están de baja (`MEN00852`).
- `resultados_anio` (`EAP_ResumenResultadosAnio` del año `hasta`, marcador
  `resultadosanioplan`): `{anio, resultados, rondas, por_evaluacion: {S: n, …}, texto}`
  (`MEN00853`).

**Tendencia y avisos** (`EAP_MarcarAvisos`, política por defecto de Eurachem; ENAC NT-03 deja la
política al laboratorio). Los resultados de cada parámetro, en el orden del tiempo (por la
recepción o, si falta, la fecha límite; sin fecha al final; luego ronda y línea), con la
puntuación del organizador o, si no la da, la calculada (los cualitativos no tienen):
- **Cuestionables seguidos**: 2 o más evaluaciones `Q` seguidas (`EAP_RACHA_CUESTIONABLES`); un
  resultado sin evaluar no cuenta ni corta; cualquier otra evaluación corta.
- **Sesgo**: 4 o más puntuaciones seguidas del mismo signo (`EAP_RACHA_SESGO`; signo de la
  puntuación redondeada a 2 decimales); los puntos sin puntuación no cuentan ni cortan; una
  puntuación cero corta.

`GET /ensayos-aptitud/tendencia?parametro_delegacion=&parametro_codigo=` (el gráfico, solo
lectura; sin parámetro, `422`) responde `{data: {parametro_*, parametro_nombre, politica,
puntos, rachas, avisos}}`: cada punto con la ronda y la línea (`ensayo_*`, `codigo`), `fecha`,
`ronda` («programa - ronda»), `tipo_puntuacion`, `sobre_log10`, `puntuacion`,
`puntuacion_calculada` (`T` si es la de Veolab), `evaluacion`, los límites de su ronda (si no son
mayores que 0, 2, 3 y 1) y `aviso` (`Q`, `S`, `QS` o null); cada racha `{tipo (Q|S), desde, hasta
(posiciones en puntos), numero, signo (1 por encima, -1 por debajo; null en Q), texto}` y
`avisos` los textos (`MEN00844`, `MEN00845`).

En `/ensayos-aptitud/resultados` la lectura añade `aviso_tendencia` (`Q`, `S`, `QS` o null, como
el granate del listado; `EAP_ResultadosConAviso` sobre todos los resultados de cada parámetro) y
el filtro `?aviso_tendencia=T` (con aviso; la casilla «Con aviso de tendencia»), `Q` o `S`. Se
calcula en cada petición leyendo todos los resultados con parámetro (cientos en un laboratorio).

**Aviso J** (`COM_CrearNotificacionesAptitud`; ISO 17025 7.7.2): al crear o modificar una ronda
(`POST`/`PUT /ensayos-aptitud`) se borra su notificación `J` (y sus avisos) y, si queda pendiente
de enviar (`P` o vacío) con `fecha_limite`, el módulo `COM` está activo y `LABCON.CONBNAP` = `T`
(`notificar_ensayos_aptitud` en `/configuracion/laboratorio`), se crea otra para quien la registró
(`usuario_*` de la ronda) si sigue activo: fecha 7 días antes de la fecha límite
(`EAP_DIAS_AVISO_ENVIO`) o hoy si ya pasó, texto «programa - ronda: dd/mm/aaaa» (sin programa ni
ronda, el código formateado; hasta 240 más la fecha) y referencia `EAP2DEL`/`EAP2SER`/`EAP2COD`
(`ensayo_*` en `/notificaciones`), con su aviso emergente. Enviada, evaluada o cerrada, o sin
fecha límite, solo se borra. Al eliminar la ronda se borra (`EAP_Borrar`). Veolab, si quien la
registró ya no es un usuario activo, avisa al usuario en sesión; la API avisa entonces al usuario
de la petición (`?usuario_delegacion=&usuario_codigo=` en el `POST`/`PUT`) si está activo, y sin él
no avisa a nadie. Los cambios en muestras y resultados no la tocan (como la ficha).

**Sin hacer:** los documentos de la ronda en `/documentos` (`DOCFAT.EAP2SER`/`EAP2COD`) y la
exportación con plantilla (informe de ronda y plan con sus marcadores).

## 11 sexdecies. Calidad: auditorías

Auditorías internas y externas, fase 1 (módulo `CAL` de Veolab 3; ISO/IEC 17025:2017 8.8;
funcionalidad `CAL_AUI` en Veolab, que la API no comprueba: §11 ter). Sigue el modelo común con el
escritorio (`Documentacion/ISO 17025/Auditorias - modelo de datos.md` del proyecto de Veolab;
`App\Support\VeolabQualityAudits`). Como las incidencias y los ensayos de aptitud: `$sinceVersion`
3.0 (§10 bis), escribir exige el módulo `CAL` activo y licenciado (`422` si no) y las referencias
vacías se guardan como `NULL` (sin `$foreignKeys`).

**Ruta.** `/auditorias` ya es el registro de auditoría de cambios (`ACCAUD`, §12 bis) y no se
cambia; las auditorías de calidad van en `/auditorias-calidad`.

**Auditorías** (`/auditorias-calidad`, `CALAUI`; clave `delegacion` + `serie` + `codigo`, código
del contador `ACCCLT` de la serie con las reglas de `ACCCFC 'CALAUI'`, como `CALEAP`; serie sin
`#`):

| Parámetro | Columna | Notas |
|---|---|---|
| `tipo` | `AUICTIP` | `I` interna · `C` interna contratada (auditor externo) · `E` externa de acreditación · `X` externa de certificación o de cliente. Al crear sin tipo, `I`. |
| `anio` | `AUINANI` | Año del programa. Vacío: el de la fecha prevista, el de inicio o el actual. |
| `descripcion` | `AUICDES` | Hasta 255. |
| `fecha_prevista`, `fecha_inicio`, `fecha_fin`, `fecha_informe`, `fecha_comunicacion_direccion` | `AUIDPRE`, `AUIDINI`, `AUIDFIN`, `AUIDINF`, `AUIDDIR` | Solo el día. |
| `estado` | `AUICEST` | `P` prevista · `R` realizada · `I` informe emitido · `C` cerrada · `A` anulada. Al crear, `P`. |
| `auditor_jefe_delegacion`, `auditor_jefe_codigo` | `EMA2DEL`, `EMA2COD` | Empleado; al asignarlo, de alta. Solo en la interna (`I` o sin tipo). |
| `entidad_delegacion`, `entidad_codigo` | `PRO2DEL`, `PRO2COD` | Entidad auditora externa: un proveedor; al asignarlo, de alta. Solo en `C`, `E` y `X`. |
| `auditor_externo` | `AUICAUD` | Nombre de la persona, hasta 100. Solo en `C`, `E` y `X`. |
| `criterios`, `conclusion`, `observaciones` | `AUICCRI`, `AUICCON`, `AUICOBS` | Textos (norma, procedimientos, requisitos del cliente…). |
| `fecha_registro`, `usuario_*` | `AUITREG`, `USU2DEL`, `USU2COD` | Alta y quién la registra: la fecha la pone la API; el usuario, solo al crear. Se aceptan en `PUT` si no cambian. |

- Textos recortados; vacíos, `NULL` (el escritorio hace lo mismo).
- **Auditor según el tipo**, como el escritorio: en la interna (`I` o sin tipo) se guarda el auditor
  jefe y se vacían `entidad_*` y `auditor_externo`; en `C`, `E` y `X` se guardan la entidad y el
  auditor externo y se vacía `auditor_jefe_*`. Se aplica en cada escritura y queda en la auditoría
  de cambios como un cambio más.
- **Estado** (`422` si no se cumple): `R` exige `fecha_inicio`; `I`, `fecha_informe`; pasar a `C`
  exige que ningún hallazgo enlazado siga abierto (registrado, en tratamiento, en verificación o
  sin estado; 8.8.2 d). Una cerrada no es de solo lectura (el modelo no lo pide).
- **Listas embebidas**, que **sustituyen** la lista guardada si vienen en el `POST`/`PUT` (ausentes,
  no se tocan; `[]` o `null`, la vacían):
  - `equipo` y `auditados`: `[{empleado_delegacion, empleado_codigo}]` (`CALAUQ`, papel `A` y `D`).
    Empleados existentes y sin repetir en cada lista; los que se añaden, de alta. Un empleado puede
    estar en las dos (da el aviso de independencia).
  - `apartados`: `[{apartado}]` (`CALAUX`), de la lista fija de abajo y sin repetir.
  - `delegaciones`: `[{delegacion}]` (`CALAUL`), existentes y sin repetir. Sin ninguna, la auditoría
    cubre todo el laboratorio.
  - `ensayos_presenciados`: `[{parametro_*, analista_*, operacion_*, observaciones}]` (`CALAUV`):
    parámetro obligatorio y existente (código hasta 30); analista (empleado) y operación opcionales y existentes;
    observaciones hasta 255. Se renumeran 1, 2… (`codigo`) en el orden dado.
- **Avisos** (no bloquean; en `avisos: [{aviso}]` de la respuesta, como pedidos y entregas): el
  auditor jefe o uno del equipo está entre los auditados (independencia, en cualquier tipo); una
  interna (`I` o sin tipo) sin auditor jefe ni equipo, y una contratada (`C`) sin entidad ni nombre
  del auditor externo.
- Lectura: añade `codigo_formateado`, `tipo_texto`, `estado_texto`, `auditor_jefe_nombre`,
  `entidad_nombre`, las listas con sus nombres (`empleado_nombre`; `titulo` del apartado, en el
  orden de la norma; `nombre` de la delegación; `parametro_nombre`, `analista_nombre` y
  `operacion_codigo_formateado`), `origen_hallazgos` (`I` en las internas, `E` en las externas: el
  origen que deben llevar sus hallazgos), `hallazgos` y `hallazgos_abiertos`.
- `hallazgos[]`: las incidencias enlazadas (`CALIYE` con `tabla` = `CALAUI`) con `delegacion`,
  `serie`, `codigo`, `codigo_formateado`, `descripcion`, `clase`, `hallazgo` (`INCCGRV`),
  `apartado` (`INCCAPA`) y `apartado_titulo`, `estado`, `estado_texto`, `abierta` (`T`/`F`),
  `fecha_deteccion` y `fecha_cierre`.
- Filtros: los campos (`anio`, `tipo=I,C`, `estado`, `auditor_jefe_*`, `entidad_*`,
  `fecha_prevista[gte]`…, `search` en descripción, auditor externo y criterios) y además
  `auditor_delegacion` + `auditor_codigo` (auditor jefe o del equipo), `auditado_delegacion` +
  `auditado_codigo`, `con_hallazgos_abiertos=T|F`, `apartado` y `parametro_delegacion` +
  `parametro_codigo` (con ese ensayo presenciado).
- **Borrado**: sin restricciones de estado (el modelo no las pone). Se borran su equipo y auditados,
  apartados, ensayos presenciados, delegaciones y los enlaces de las incidencias hacia ella
  (`CALIYE`; las incidencias quedan) y sus documentos (`DOCFAT.AUI2SER`/`AUI2COD`) van a la
  papelera, como los de los ensayos de aptitud.
- **Auditoría** (`ACCAUD`): la auditoría como las demás fichas (inserción, fila y campos, borrado);
  al cambiar las listas, suceso de fila y, con nivel 3, campo con la tabla modificada (`CALAUQ`,
  `CALAUX`, `CALAUV`, `CALAUL`) sin valores, como las familias de ensayos de aptitud.

**Hallazgos.** Son incidencias (`/incidencias`) de clase `N` (hallazgo de auditoría) con origen
`I` (tipos `I` y `C`) o `E` (tipos `E` y `X`), su clasificación (`hallazgo`, `INCCGRV`), el
`apartado` de la norma (`INCCAPA`) y enlazadas a la auditoría con `POST /incidencias/enlaces`
(`tabla` `CALAUI`, `enlace_serie` la serie de la auditoría).
`GET /incidencias?enlace_tabla=CALAUI&enlace_delegacion=&enlace_serie=&enlace_codigo=` da los de una
auditoría. La API no tiene aún un atajo como el botón «Nuevo hallazgo» de la ficha: se registra la
incidencia y luego el enlace.

**Apartados de la norma** (lista fija; el título corto en español, en `titulo`, el del escritorio,
`ETI01120`-`ETI01148`): `4.1`
Imparcialidad · `4.2` Confidencialidad · `5` Requisitos relativos a la estructura · `6.1` Recursos:
generalidades · `6.2` Personal · `6.3` Instalaciones y condiciones ambientales · `6.4`
Equipamiento · `6.5` Trazabilidad metrológica · `6.6` Productos y servicios suministrados
externamente · `7.1` Revisión de solicitudes, ofertas y contratos · `7.2` Selección, verificación y
validación de métodos · `7.3` Muestreo · `7.4` Manipulación de los ítems de ensayo ·
`7.5` Registros técnicos · `7.6` Evaluación de la incertidumbre de medición · `7.7` Aseguramiento
de la validez de los resultados · `7.8` Informe de resultados · `7.9` Quejas · `7.10` Trabajo no
conforme · `7.11` Control de los datos y gestión de la información · `8.1` Opciones del sistema de
gestión · `8.2` Documentación del sistema de gestión · `8.3` Control de los documentos · `8.4`
Control de los registros · `8.5` Acciones para abordar riesgos y oportunidades · `8.6` Mejora ·
`8.7` Acciones correctivas · `8.8` Auditorías internas · `8.9` Revisiones por la dirección.

**Cobertura del programa** (`GET /auditorias-calidad/cobertura?desde=&hasta=&delegacion=&ciclo_desde=`,
solo lectura). Los años como el plan de intercomparaciones (§11 quindecies: por defecto los cuatro
anteriores y el actual; como máximo 8). Responde `{data: {desde, hasta, anio_actual, anios,
apartados, ciclo_desde, parametros_sin_ensayo_presenciado, resumen}}`:

- `apartados[]`: cada apartado de la lista con `apartado`, `titulo`, `ultimo_anio_auditado` y
  `anios[] = {anio, estado, texto, auditorias}`, con `estado` `A` auditado (alguna auditoría
  interna, `I`, `C` o sin tipo, no anulada, en estado `R`, `I` o `C`, con el apartado en su
  alcance) · `P` previsto (solo en auditorías previstas) · `S` sin auditar (solo hasta el año
  actual) · `null` (año futuro sin nada; `texto` null); `auditorias` las que lo cubren
  (`delegacion`, `serie`, `codigo`, `codigo_formateado`, `estado`). `ultimo_anio_auditado` mira
  todos los años, no solo los del cuadro. El año de una auditoría es el del programa (`AUINANI`)
  o, si falta, el de inicio o el de la fecha prevista.
- `parametros_sin_ensayo_presenciado[]`: técnicas acreditadas (`LABTEC.TECDACR`) y no de baja sin
  ninguna línea de `CALAUV` en una auditoría interna no anulada del ciclo (fecha de inicio o, si
  falta, prevista, desde `ciclo_desde`, `AAAA-MM-DD`; por defecto, hoy menos un año; sin
  ninguna de las dos fechas no cuenta, como el escritorio), con `parametro_*`, `parametro_nombre`, `fecha_acreditacion` y
  `ultimo_ensayo_presenciado` (la fecha del último, si lo hubo antes).
- `resumen`: del año `hasta` o, si `hasta` es un año futuro, del año actual (`anio` dice cuál),
  `{anio, apartados, auditados, previstos, sin_auditar, parametros_sin_ensayo_presenciado}`.
- Con `delegacion`, solo las auditorías que tienen esa delegación entre las auditadas o no tienen
  ninguna (todo el laboratorio), y los parámetros de esa delegación y los comunes (`''`).

**Listas de verificación** (fase 2; tablas `CALLVE`, `CALLVQ` y `CALAUP` del script de conversión:
sin ellas, 422). Modelo común con el escritorio («Analisis listas de verificacion de auditorias.md»).

- `/auditorias-calidad/listas` (`CALLVE`; clave `delegacion` + `codigo`, `''` = común): `descripcion`
  (obligatoria), `uso` `G` auditoría (por defecto) o `T` ensayo presenciado, `predeterminada`
  (al marcar una se desmarcan las demás del mismo uso y delegación; una de baja no lo es), `baja`,
  `observaciones` y `preguntas[] = {orden, apartado, pregunta, que_revisar, referencia}`, que se
  sustituyen (líneas 1, 2...). El código no se indica: es el siguiente al mayor de todas las
  delegaciones (`CALAUP.LVE2COD` guarda solo el código). La lectura añade `uso_texto`, `de_veolab`,
  `usada`, `num_preguntas` y las preguntas. No se eliminan si alguna auditoría las ha usado ni las
  comunes 1 y 2 de Veolab (422). `POST /auditorias-calidad/listas/duplicar?delegacion=&codigo=`.
- `/auditorias-calidad/lista` (`CALAUP`; clave de la auditoría + `linea`): `orden`,
  `ensayo_presenciado` (línea de `CALAUV` o null; solo al añadir), `apartado`, `pregunta`,
  `que_revisar`, `resultado` (`C` conforme, `N` no conforme, `O` observación, `X` no aplica; null sin
  responder), `evidencia`, `hallazgo_serie` + `hallazgo_codigo` (incidencia de la misma delegación
  enlazada a la auditoría) y `lista` (origen, solo lectura). El GET exige la auditoría, admite
  `?auv=` (0 = solo las de la auditoría) y devuelve `meta.resumen = {total, respondidas,
  sin_responder, conformes, no_conformes, observaciones, no_aplican}` y `meta.resumen_apartados`
  (una entrada por apartado con preguntas, en el orden de la norma, los ajenos detrás y sin apartado
  —`apartado` null— al final: `{apartado, titulo, preguntas, conformes, no_conformes, observaciones,
  no_aplican, sin_responder}`; sin `?auv=`, solo las de la auditoría, como el marcador
  `resumenapartadoslistaauditoria` del escritorio). Como en el escritorio, la lista se puede editar
  en cualquier estado de la auditoría; los cambios se auditan en la auditoría (campo `CALAUP`).
- `POST /auditorias-calidad/lista/cargar?delegacion=&serie=&codigo=` `{listas[], solo_alcance,
  auv}`: copia las preguntas (listas comunes o de la delegación, no de baja) detrás de las que hay,
  sin repetir las del mismo apartado y texto; con `solo_alcance` (por defecto) solo las de los
  apartados de `CALAUX` y las que no tienen apartado (si el alcance está vacío, todas).
- `POST /auditorias-calidad/lista/hallazgo?delegacion=&serie=&codigo=&linea=` `{serie,
  detectada_por_*, hallazgo, usuario_*}`: en una pregunta `N` u `O` sin hallazgo, crea la
  incidencia con las reglas de `POST /incidencias` (clase N, origen I o E según el tipo, apartado
  de la pregunta, título = la pregunta recortada, detalle = la evidencia; quien la detecta, por
  defecto el auditor jefe), la enlaza a la auditoría (`CALIYE` tipo `CALAUI`) y la guarda en la pregunta.
- Al borrar la auditoría se borran sus preguntas; al quitar un ensayo presenciado, las suyas (en
  `ensayos_presenciados[].codigo` va la línea actual para conservarlas; sin ella se empareja con la
  anterior del mismo parámetro); al borrar una incidencia, las preguntas pierden el hallazgo. Al
  pasar a informe emitido con preguntas sin responder, aviso en `avisos`. La revisión por la
  dirección (tema e) añade «Listas de verificación: … preguntas respondidas de …».

**Sin hacer:** el atajo «Nuevo hallazgo», los documentos de la auditoría en `/documentos`
(`DOCFAT.AUI2*`), las comprobaciones de referencias al borrar empleados, técnicas, proveedores u
operaciones usados en auditorías, y la fase 2 (lista de comprobación y avisos).

## 11 septendecies. Calidad: revisión por la dirección

Revisión por la dirección, fase 1 (módulo `CAL` de Veolab 3; ISO/IEC 17025:2017 8.9; funcionalidad
`CAL_RDI` en Veolab, especial 1 = responsable de calidad). Sigue el modelo común con el escritorio
(`Documentacion/ISO 17025/Revision por la direccion - modelo de datos.md` del proyecto de Veolab;
`App\Support\VeolabManagementReview`). Como las auditorías: `$sinceVersion` 3.0, escribir exige el
módulo `CAL` activo y licenciado (`422` si no) y las referencias vacías se guardan como `NULL`. Si la
base de datos aún no tiene `CALRDI` (sin actualizar), todo el recurso responde `422`.

**Revisiones** (`/revisiones-direccion`, `CALRDI`; clave `delegacion` + `serie` + `codigo`, código
del contador `ACCCLT` de la serie con las reglas de `ACCCFC 'CALRDI'`; serie sin `#`). Las rutas del
modelo (`/revisiones-direccion/{del}/{ser}/{cod}`) siguen aquí la convención de la API: la clave va
en la query string.

| Parámetro | Columna | Notas |
|---|---|---|
| `titulo` | `RDICDES` | Hasta 255. Al crear sin él, «Revisión por la dirección <año de `fecha_fin`>». |
| `fecha_inicio`, `fecha_fin` | `RDIDINI`, `RDIDFIN` | Periodo revisado (solo el día; inicio ≤ fin; no se vacían). Al crear sin ellas: desde el día siguiente al fin de la última revisión no anulada hasta hoy; sin ninguna, el año natural anterior. |
| `fecha_reunion`, `lugar` | `RDIDFEC`, `RDICLUG` | Lugar hasta 100. |
| `estado` | `RDICEST` | `B` borrador · `C` celebrada · `P` aprobada · `A` anulada. Al crear, `B` (otro, `422`). |
| `aprobada_por_delegacion`, `aprobada_por_codigo` | `EMA2DEL`, `EMA2COD` | Empleado; al asignarlo, de alta. |
| `fecha_aprobacion` | `RDIDAPR` | Al aprobarla sin ella, hoy. Al reabrir una aprobada (`P` → `B`) se vacía. |
| `asistentes_externos` | `RDICEXT` | Texto libre. |
| `eficacia_sistema`, `mejoras`, `recursos`, `cambios` | `RDICEFI`, `RDICMEJ`, `RDICREC`, `RDICCAM` | Salidas de 8.9.3 a-d. |
| `observaciones` | `RDICOBS` | |
| `fecha_recogida_datos` | `RDITDAT` | Última «Recoger datos» (solo lectura; se acepta en `PUT` si no cambia). |
| `fecha_registro`, `usuario_*` | `RDITREG`, `USU2DEL`, `USU2COD` | Alta y quién la registra, como en auditorías. |

- Textos recortados; vacíos, `NULL`.
- **Permisos.** Solo el responsable de calidad crea, modifica, cambia de estado, recoge datos y
  elimina. La API no tiene sesión: al crear, `usuario_*` del cuerpo (quien la registra); al
  modificar, recoger datos o eliminar, `?usuario_delegacion=&usuario_codigo=`. Con usuario se
  comprueba que existe, está activo y tiene `CAL_RDI` con escritura y el especial 1 (`422` si no);
  sin él, la API actúa como responsable de calidad (§11 ter, §11 terdecies).
- **Estados** (`422` si no se cumple): `B → C → P`; `A` desde `B` o `C` (una aprobada no se anula);
  reabrir = pasar de `C` o `P` a `B`. De `B` a `P` hay que pasar antes por `C`; una anulada no cambia.
  - `C` (y `P`) exige `fecha_reunion`, algún asistente (de la plantilla o `asistentes_externos`) y
    el `analisis` de los quince temas (vale «Sin cambios»); se comprueba al cambiar de estado o al
    tocar esos datos.
  - `P` exige `aprobada_por_*` y pone `fecha_aprobacion` si está vacía.
  - Aprobada o anulada: solo lectura (un `PUT` que cambie algo, salvo reabrir, da `422`; lo que
    llega igual a lo guardado se acepta). Los documentos se gestionan en Veolab.
- **Listas embebidas:**
  - `asistentes`: `[{empleado_delegacion, empleado_codigo}]` (`CALRDA`): sustituye la lista;
    empleados existentes y sin repetir; los que se añaden, de alta.
  - `delegaciones`: `[{delegacion}]` (`CALRDL`): sustituye la lista; existentes y sin repetir. Sin
    ninguna, la revisión cubre todas las delegaciones.
  - `temas`: `[{tema, datos, analisis}]` (`CALRDX`): **no** sustituye; cambia solo los temas y los
    campos indicados (`tema` de la `a` a la `o`, sin repetir). Al crear se graban los quince.
- Lectura: añade `codigo_formateado`, `estado_texto`, `aprobada_por_nombre`, `asistentes` (con
  `empleado_nombre`), `temas` (siempre los quince en el orden de la norma: `tema`, `titulo`,
  `automatico` `T`/`F`, `datos`, `analisis`, `tratado` `T`/`F`), `temas_sin_analisis`,
  `delegaciones` (con `nombre`), `decisiones` (número) y `decisiones_abiertas`.
- Filtros: los campos (`estado=B,C`, `fecha_reunion[gte]=`, `fecha_fin[lte]=`…, `search` en título,
  lugar y observaciones) y además `anio` (el de la fecha de la reunión o, si falta, el de `fecha_fin`, como el listado del escritorio), `asistente_delegacion` +
  `asistente_codigo` y `con_decisiones_abiertas=T|F`.
- **Borrado**: solo en borrador y sin decisiones (una revisión con decisiones se anula) ni documentos
  (`DOCFAT.RDI2SER`/`RDI2COD` fuera de la papelera, si la columna existe). Se borran sus asistentes,
  temas y delegaciones.
- **Auditoría** (`ACCAUD`): como las auditorías de calidad; al cambiar las listas, suceso de fila y,
  con nivel 3, campo con la tabla modificada (`CALRDA`, `CALRDX`, `CALRDL`) sin valores.

**Temas de entrada** (8.9.2; títulos del escritorio en español): `a` Cambios internos y externos ·
`b` Cumplimiento de los objetivos · `c` Políticas y procedimientos · `d` Acciones de revisiones
anteriores · `e` Auditorías internas · `f` Acciones correctivas · `g` Evaluaciones externas · `h`
Volumen y tipo de trabajo · `i` Opinión de clientes y personal · `j` Quejas · `k` Eficacia de las
mejoras · `l` Recursos · `m` Riesgos · `n` Validez de los resultados · `o` Otros (formación, plazos).
Con datos automáticos, la `b` (objetivos de calidad, §11 undevicies), la `c` (control de documentos,
desde la fase 2) y de la `d` a la `o`.

**Recoger datos** (`POST /revisiones-direccion/recoger-datos?delegacion=&serie=&codigo=`, cuerpo
opcional `{"sobrescribir": "T"}`). Solo en borrador y con el periodo indicado. Sustituye los `datos`
de los temas `b` a `o` y pone `fecha_recogida_datos`. Si alguno ya tiene datos y no se pide
`sobrescribir`, `422` con `errors.temas_con_datos` (el escritorio lo pregunta). Responde los temas
con su texto. Los textos son los del escritorio (`RevisionDireccion.bas`, `RDI_RecogerDatos`; cadenas
`ETI01211`-`ETI01240` en español, porque la API no tiene idioma de usuario), una línea por dato
separadas por CR+LF y, si hay pocos registros abiertos (hasta 10), «Abiertas:» y «- código título».
Periodo de `fecha_inicio` a `fecha_fin`, ambos incluidos; delegaciones, las de la revisión o todas
(en los maestros, también los comunes `''`). Un dato cuya consulta falla (tabla o columna que no
existe) se omite.

| | Datos (en el periodo) |
|---|---|
| b | Una línea por objetivo de calidad (`CALOBJ`) del año de `fecha_fin`, por delegación y código: los de las delegaciones de la revisión y los de todo el laboratorio (`''`); sin delegaciones, todos. «Descripción (delegación): valor unidad (meta >= x unidad), estado» (descripción del objetivo o, si falta, del indicador; la delegación por su nombre, si tiene; sin meta, sin el paréntesis): cerrado, el valor alcanzado y el texto del resultado en minúsculas (cumplido, cumplido en parte, no cumplido; sin resultado, cerrado); abierto, el valor del 1 de enero a `fecha_fin` y «se cumple» o «no se cumple» si hay valor y meta; sin valor, «sin datos». Sin objetivos, «No hay objetivos de calidad definidos para <año>». Sin las tablas, nada. |
| c | Con el control de documentos activo: «Ediciones de documentos controlados aprobadas: N» (`DOCVER.VERDAPR` en el periodo, documentos de las delegaciones y comunes) y su lista «- código título (edición)» si son pocas. Con la fase 2: «Lecturas pendientes de las ediciones vigentes: N en M documentos, V vencidas (aproximado con los destinatarios y ediciones vigentes de hoy)», sin contar las lecturas posteriores al fin del periodo y con el plazo comparado con el fin del periodo (o hoy si no ha terminado), con la lista por documento si son pocos; y «Copias controladas por retirar: N» (las de hoy). Sin control de documentos, nada. |
| d | Revisión anterior no anulada (la de `fecha_reunion` más reciente anterior a la reunión de esta o, si no tiene, al día siguiente al fin del periodo): sus decisiones cerradas, abiertas (R/T/V o sin estado) y abiertas con acciones vencidas; lista de las abiertas. |
| e | Auditorías internas (`I`, `C` o sin tipo) de las delegaciones: previstas (`fecha_prevista`, no anuladas) y realizadas (estado R/I/C con `fecha_inicio` en el periodo); hallazgos no anulados de las realizadas por gravedad (`M`/`N`/`O`) y abiertos; lista de las realizadas si son pocas. |
| f | Incidencias no anuladas detectadas en el periodo con alguna acción correctiva (`CALACC` tipo `A`): total, cerradas, abiertas y con acciones vencidas; no conformidades (`INCBNCO`, todas las detectadas en el periodo); lista de las abiertas. |
| g | Auditorías `E` y `X` realizadas en el periodo y sus hallazgos, como en la `e`. |
| h | Operaciones no anuladas por `fecha_registro` e informes por `fecha_creacion` (`LABINF.INFDCRE`) frente al periodo anterior de igual duración, con la variación en % (entera; «-» si antes no hubo ninguno); clientes dados de alta en el periodo con operaciones en él. Las técnicas no tienen fecha de alta: no se cuentan. |
| i | Incidencias de origen `C` o `P` que no son quejas, total y por clase. |
| j | Quejas detectadas en el periodo: recibidas, cerradas, abiertas, tiempo medio de cierre (días de la detección al cierre); lista de las abiertas. |
| k | Mejoras cerradas en el periodo y, de ellas, con la verificación de la eficacia (`INCCVEF`) escrita. |
| l | Altas y bajas de personal (`GRHEMP`); autorizaciones concedidas (`AUTDAUT`) y caducadas (`AUTDCAD`) en el periodo; equipos (productos con `PRDBEQU`) no de baja ni fuera de uso con calibración, verificación o mantenimiento alcanzados hoy; con el script de los patrones, «Patrones y materiales de referencia: N caducados y M con la calibración vencida» (series de productos con tipo metrológico no de baja ni fuera de uso con la caducidad efectiva o `SELDCAL` alcanzadas hoy); evaluaciones de proveedores (`SINEVA`) por resultado; proveedores no de baja con reevaluación vencida (`PRODPEV`). |
| m | Riesgos abiertos ahora y detectados hasta el fin del periodo; tratados (cerrados) en el periodo; lista de los abiertos. |
| n | Rondas de ensayos de aptitud con informe (`EAPDINF`) en el periodo; sus resultados satisfactorios (`S`, `C`), cuestionables (`Q`) e insatisfactorios (`I`, `P`, `N`); familias del plan no de baja sin participación en su periodo (el plan de §11 quindecies, de la delegación del usuario que recoge los datos —`usuario_delegacion`— y las comunes, como el escritorio; sin ella, de todo el laboratorio); cartas de control con algún punto del periodo en error (`RCDCTII` = `E`) y, aparte, con aviso (`A`); trabajos no conformes y abiertos, con lista. |
| o | Formación: cursos del plan (`GRHPAF` por `PAFDINI`, sin cancelados `C` ni rechazados `H`) y registros de formación del personal (`GRHFOR` por `FORDINI`); informes emitidos en el periodo después de la fecha de compromiso, «x de y» (y = operaciones con informe en el periodo y fecha de compromiso). |

**Decisiones** (`GET /revisiones-direccion/decisiones?delegacion=&serie=&codigo=`, solo lectura):
las incidencias enlazadas (`CALIYE` con `tabla` = `CALRDI`) con `delegacion`, `serie`, `codigo`,
`codigo_formateado`, `descripcion`, `clase`, `origen`, `responsable_*` y `responsable_nombre`,
`fecha_prevista` (la de la acción pendiente más próxima), `estado`, `estado_texto`, `abierta`,
`fecha_deteccion` y `fecha_cierre`; `meta.total` y `meta.abiertas`. Una decisión es una incidencia
de clase `M` con origen `D` enlazada a la revisión con `POST /incidencias/enlaces` (`tabla`
`CALRDI`, `enlace_serie` la serie de la revisión); la API no tiene aún el atajo «Nueva decisión».

**Sin hacer:** el atajo «Nueva decisión», los documentos de la revisión en `/documentos`
(`DOCFAT.RDI2*`, carpeta especial 28), y la exportación del acta con plantilla.

## 11 octodecies. Tareas pendientes

Lo que un usuario tiene por hacer, como la opción «Tareas pendientes» de Veolab 3 (funcionalidad `COM_TAR`
del menú Notificaciones, módulo `COM`; `Tareas.bas` del escritorio; análisis en `Documentacion/ISO
17025/Analisis tareas pendientes.md` del proyecto de Veolab). `App\Support\VeolabTasks` y
`TareaPendienteController`. **Solo lectura y sin tabla**: cada tarea se calcula al vuelo con una
consulta por tipo sobre los registros de origen y desaparece sola cuando se hace en su registro.

```
GET /api/v2/tareas-pendientes?usuario_delegacion=&usuario_codigo=[&tipo=IC,IT&grupo=Q,D&solo_vencidas=T&solo_mias=T]
GET /api/v2/tareas-pendientes/recuento   (mismos parámetros)
```

- **Usuario obligatorio** (la API no tiene sesión; `422` sin `usuario_codigo`). Su delegación hace de
  delegación conectada: si no es vacía, solo los registros de esa delegación y de las comunes (`''`).
- **Acceso** (`422`, como el resto de reglas de la API, que no usa `403`): base de Veolab 3; usuario que
  existe, no está de baja ni es de tipo cliente; módulo `COM` activo y licenciado; su perfil con acceso
  a `COM_TAR`. Las tareas de Calidad (incidencias, auditorías, aptitud, revisión por la dirección y
  documentos controlados) solo se calculan si además está activo el módulo `CAL`. Cada tipo exige además
  el acceso del perfil (`ACCPYF`) a la funcionalidad de su ficha: incidencias `CAL_INC`, auditorías
  `CAL_AUI`, aptitud `CAL_EAP`, revisión `CAL_RDI` (y su especial 1), proveedores `FIC_PRO` o `ALM_PRO`,
  documentos `DOC_CTL` con el control de documentos activo, firmas `LAB_INF`, solicitudes `LAB_SOL` con
  escritura y el módulo `SOL`, fecha de compromiso `LAB_OPE` o `LAB_REC` (cualquier operación con fecha; `CONBAFC` solo la activa por defecto al crearla)
  (y, para las de cargo, escritura en `LAB_RES`), equipos y stock escritura en `ALM_INV` con el módulo `ALM`,
  presupuestos `VEN_PRE` con el módulo `VEN`, gráficos de control escritura en `LAB_CDC` con el módulo
  `CDC`, plan de intercomparaciones escritura en `CAL_EAF` con el módulo `CAL`, objetivos de calidad el especial 1
  de `CAL_OBJ` con el módulo `CAL`;
  las autorizaciones, solo tener empleado. «Responsable de calidad» es el especial 1 de `CAL_INC` (también en auditorías y
  proveedores); «puede aprobar documentos», el especial 1 de `DOC_CTL`. Los bits se comprueban como
  `SES_ObtenAcceso`: `(PYFNACC & bit) = bit`.
- **Fechas**: hoy es `CURDATE()` del servidor de base de datos. «Próxima» = vence dentro de 30 días
  (fecha < hoy + 31); la fecha de compromiso, dentro de 7 días (`TAR_DIAS_AVISO`: fecha < hoy + 8). Revisión por la dirección anual: 12 meses después de la última celebrada o aprobada.
- Si la consulta de un tipo falla (tabla o columna que aún no existe), ese tipo se omite, se registra en
  el log y se indica en `meta.omitidos`.

| Tipo | Texto | Grupo | Condición | Para quién | Fecha (¿vence?) |
|---|---|---|---|---|---|
| `IC` | Clasificar incidencia | Q | `CALINC.INCCEST = 'R'` | Responsables de calidad (cargo) | `INCTREG` (no) |
| `IT` | Tratar incidencia | Q | `T` y responsable `EMR2*` = su empleado | Personal | Menor `ACCDPRE` de las acciones sin fecha real (sí) o, sin ella, `INCTREG` (no) |
| `IA` | Hacer acción | Q | `CALACC.ACCDREA` nula, incidencia en R/T/V, `CALACC.EMP2*` = su empleado | Personal | `ACCDPRE` (sí) |
| `IV` | Verificar incidencia | Q | `V` y verificador `EMV2*` = su empleado o, sin verificador, responsables de calidad | Personal o cargo | Última `ACCDREA` o `INCTREG` (no) |
| `AR` | Realizar auditoría | Q | `CALAUI.AUICEST = 'P'` con `AUIDPRE` próxima o pasada | Auditor jefe `EMA2*` o, sin él, responsables de calidad | `AUIDPRE` (sí) |
| `AC` | Cerrar auditoría | Q | `I` sin hallazgos abiertos (incidencias enlazadas en R/T/V o sin estado) | Ídem | `AUIDINF` (no) |
| `EE` | Enviar resultados de aptitud | Q | `CALEAP.EAPCEST = 'P'` con `EAPDLIM` próxima, pasada o vacía | Quien la registró (`USU2*`) o, si ya no es un usuario activo, quien tiene escritura en `CAL_EAP` | `EAPDLIM` (sí) o `EAPTREG` (no) |
| `EV` | Evaluar ronda de aptitud | Q | `E` con `EAPDINF` | Ídem | `EAPDINF` (no) |
| `EC` | Cerrar ronda de aptitud | Q | `V` | Quien revisa la conclusión (`EMR2*`) o, sin él, como `EV` | `EAPDINF` o `EAPTREG` (no) |
| `RD` | Completar revisión por la dirección | Q | `CALRDI.RDICEST` en B o C | Especial 1 de `CAL_RDI` (cargo) | `RDIDFEC` (sí, en borrador) o `RDITREG` (no) |
| `RA` | Convocar la revisión por la dirección | Q | Ninguna en borrador (B) y la última celebrada o aprobada (C/P, por `RDIDFEC`) cumple los 12 meses dentro de 30 días, o no hay ninguna | Ídem | Última `RDIDFEC` + 12 meses (sí) o sin fecha |
| `PE` | Evaluar proveedor | P | `SINPRO.PRODPEV` próxima o pasada, no de baja | Quien hizo la última evaluación (`SINEVA.USU2*`) o, si no hay o no es un usuario activo, responsables de calidad | `PRODPEV` (sí) |
| `AU` | Renovar autorización | E | `GRHAUT.AUTCEST = 'A'` con `AUTDCAD` próxima o pasada, empleado no de baja | El autorizado o quien autoriza (`EMA2*`) | `AUTDCAD` (sí) |
| `DE` | Revisar edición | D | Fase 2: edición en curso (borrador o `VER2APR` distinto de `VER2COD`) en una carpeta con revisión antes de aprobar (`DIRBREV`) y `VERCEST` distinto de `R` | El revisor (`DOCFAT.EMR2*`, personal) o el especial 2 de `DOC_CTL` (cargo); nunca quien lo elabora | `VERTEMI` de la principal (no) |
| `DA` | Aprobar edición | D | Controlado en borrador o con `VER2APR` distinto de `VER2COD`; con revisión antes de aprobar, solo con la edición en `R` | Quien puede aprobar (cargo) | `VERTEMI` de la principal (no) |
| `DR` | Revisar documento | D | Vigente con `FATDREV` próxima o pasada | Quien lo elabora (`EME2*`) o, sin él, quien puede aprobar | `FATDREV` (sí) |
| `DL` | Leer nueva edición | D | Fase 2: su empleado es destinatario de la edición vigente y la tiene pendiente (ver el control de documentos, fase 2). Sin destinatarios (o sin la fase 2): vigente cuya edición aprobada no ha leído su empleado, que sí leyó una anterior (`DOCLEC`) | Personal | Fase 2: `VERDAPR` + días para leer (sí); si no, `VERDAPR` o `VERTEMI` (no) |
| `DC` | Retirar copias | D | Fase 2: copias controladas (`DOCCOP`) de una edición anterior a la vigente sin `COPDRET`; una por documento, «título (N copias por retirar)» | Especial 1 de `DOC_CTL` (cargo) | `VERDAPR` de la vigente (no) |
| `FI` | Firmar informe | I | Sin validar ni enviar, ni rechazado ni sustituido; sin la firma del tipo del perfil del usuario (`ACCPER.TIF2*`, no de baja) y con las de orden (`TIFNORD`) anterior puestas; con alguna operación sin archivar (`OPENEST` 7) ni anular | Personal | `INFDCRE` (no) |
| `SO` | Decidir solicitud | S | `LABSOL.SOLCEST = 'P'` | Escritura en `LAB_SOL` (cargo) | `SOLTFEC` (no) |
| `OC` | Fecha de compromiso | O | `LABOPE.OPEDCOM` dentro de 7 días o pasada, sin enviar (`OPEDENV` nula), `OPENEST < 6` y sin anular | Las que tienen a su empleado en `LABOYE` (personal); las que no tienen ningún empleado, escritura en `LAB_RES` (cargo); sin empleado y con escritura en `LAB_RES`, todas | `OPEDCOM` (sí) |
| `MC` | Calibrar equipo | M | `ALMSEL.SELDCAL` próxima o pasada; serie no de baja ni fuera de uso (`SELCESA` no `F` ni `B`) | Escritura en `ALM_INV` (cargo) | `SELDCAL` (sí) |
| `MV` | Verificar equipo | M | Ídem con `SELDVER` | Ídem | `SELDVER` (sí) |
| `MM` | Mantener equipo | M | Ídem con `SELDMAN` | Ídem | `SELDMAN` (sí) |
| `MP` | Renovar patrón o material de referencia | M | Con el script de los patrones: serie de un producto con tipo metrológico (`PRDCTPR`), no de baja ni fuera de uso, que sigue en uso (equipo o con existencias) y con la caducidad efectiva (la menor entre `SELDCAD` y `SELDAPE` + `PRDNDAP` días) próxima o pasada. La calibración y la verificación vencidas salen en `MC` y `MV` | Escritura en `ALM_INV` (cargo) | Caducidad efectiva (sí) |
| `ST` | Reponer stock | M | `ALMPRD.PRDNSMI > 0` y `PRDNCAE < PRDNSMI`, producto no de baja | Escritura en `ALM_INV` (cargo) | Sin fecha |
| `PS` | Seguimiento de presupuesto | V | `FACPRE.PRECEST` en P o E con `PREDSEG` dentro de 7 días o pasada | Su comercial `EMP2*` (personal) o, sin comercial, escritura en `VEN_PRE` (cargo) | `PREDSEG` (sí) |
| `GC` | Revisar gráfico de control | Q | `LABCDC.CDCCEST = 'E'` y `CDCDCIE` nula; con `CAL`, sin incidencia abierta (R/T/V) enlazada (`CALIYE`, `IYECTAB = 'LABCDC'`) | Escritura en `LAB_CDC` (cargo) | `CDCDCRE` (no) |
| `FA` | Participar en intercomparación | Q | Familia del plan (`CALFAE`, no de baja) que le toca este año o sin participación en su periodo | Escritura en `CAL_EAF` (cargo) | 31 de diciembre del año de la próxima participación o del actual (sí) |
| `OB` | Definir los objetivos de calidad | Q | Desde el 1 de febrero, ningún `CALOBJ` del año actual. «Objetivos de calidad de <año> sin definir»; `codigo` = el año | Especial 1 de `CAL_OBJ` (cargo) | 1 de febrero (no) |
| `OA` | Cerrar los objetivos de calidad | Q | Una por cada año anterior con objetivos de `OBJCEST` distinto de `C`. «Objetivos de calidad de <año>: <n> sin cerrar»; `codigo` = el año | Ídem | 1 de enero del año siguiente (no) |

- Grupos: `Q` calidad, `P` proveedores, `E` personal, `D` documentos, `I` informes, `S` solicitudes,
  `O` operaciones, `M` inventario y `V` ventas.
- Plan de intercomparaciones: el de `VeolabProficiencyPlan::families` (`EAP_CalcularPlan`) con los años
  por defecto (los 4 anteriores y el actual) y la delegación del usuario.
- Documentos: los controlados de verdad (`VeolabDocumentControl::whereControlled`, sin accesos directos)
  con `DIR2COD > 0` y distinto de 4. La API no aplica el acceso a carpetas del usuario
  (`DCT_CarpetasVisibles` del escritorio), como en la lista de controlados (§11).
- **Cada tarea**: `tipo`, `tipo_texto`, `grupo`, `tabla` (la del registro que se abre: `CALINC`,
  `CALAUI`, `CALEAP`, `CALRDI`, `SINPRO`, `GRHEMP`, `DOCFAT`, `LABINF`, `LABSOL`, `LABOPE`, `ALMSEL`,
  `ALMPRD`, `FACPRE`, `LABCDC`, `CALFAE`),
  `delegacion`, `serie` y `codigo` (clave del registro; `null` lo que no tiene, y todo en `RA`; en `ALMSEL`,
  `serie` es el producto `PRD3COD` y `codigo` la serie `SEL1COD`), `registro` (código con el formato de
  la tabla; el código del proveedor, el nombre del empleado, el código del documento o, en equipos, el
  producto o «producto / serie» si la serie no es la oculta `-`; el código del producto en stock y del
  gráfico; el nombre de la familia en el plan),
  `descripcion` («Clase: título» en incidencias, «Decisión de revisión: título» en las de origen D,
  «Acción N: texto (Clase: título)» en acciones, programa, ronda y descripción en aptitud, «Estado:
  título» en revisiones, la técnica en autorizaciones, el cliente en firmas, «Cliente: descripción» en
  solicitudes, «cliente - referencia» en la fecha de compromiso, «producto - serie» (`PRDCDES` y
  `SELCDES`) en equipos, «producto (existencias / mínimo)» en stock, «Cliente: descripción» en
  presupuestos, el nombre del gráfico y «programa: parámetros» en el plan), `fecha` (`aaaa-mm-dd` o `null`), `vence` (la fecha es un vencimiento; si no, es desde
  cuándo espera), `dias` (desde hoy; negativo = pasada; `null` sin fecha), `vencida` (vence y la fecha ya
  pasó) y `asignada` (`personal` o `cargo`).
- **Orden** (el del escritorio): vencidas primero; después por fecha, sin fecha al final; a igualdad, por
  el tipo en el orden de la tabla.
- **Filtros**: `tipo` y `grupo` (listas con comas; un valor desconocido da `422`), `solo_vencidas=T` y
  `solo_mias=T` (sin las de cargo). Sin paginación.
- **Respuesta**: `{data: [...], meta: {total, vencidas, con_empleado, omitidos, version_bd}}`.
  `con_empleado` = `F` si el usuario no está enlazado con un empleado (solo ve las de su cargo). El
  recuento: `{data: {total, vencidas, por_tipo: {IC: n, ...}}, meta: {con_empleado, omitidos, version_bd}}`
  (`por_tipo` con los veintiocho tipos).
- `/usuarios` expone `abrir_tareas_al_iniciar` (`ACCUSU.USUBTAP`, `T`/`F`: abrir la lista al iniciar
  sesión en el escritorio), desde Veolab 3 y solo si la base ya tiene la columna.

**Sin hacer:** los colores (ámbar a 7 días) y el texto del plazo, que son de presentación; posponer La tarea «Valorar la actualización de Veolab» (`VV`, validación del software, `ACCPAR.PARCVVA`) es solo del escritorio: la API no conoce la versión del ejecutable instalado.
tareas y lo que el escritorio tampoco tiene (resultados).

## 11 undevicies. Calidad: objetivos e indicadores

Objetivos e indicadores de calidad, fase 2 de la revisión por la dirección (módulo `CAL` de Veolab 3;
ISO/IEC 17025:2017 8.2.1 y 8.9.2 b; funcionalidad `CAL_OBJ`, especial 1 = responsable de calidad).
Sigue el modelo común con el escritorio (`Documentacion/ISO 17025/Analisis objetivos e
indicadores.md`, apartado 5, y `Objetivos.bas`; `App\Support\VeolabQualityIndicators`). Como la
revisión por la dirección: `$sinceVersion` 3.0, escribir exige el módulo `CAL` activo y licenciado, y
si la base aún no tiene `CALKPI`, `CALKPV` y `CALOBJ`, todo responde `422`.

**Permisos.** Solo el responsable de calidad escribe (indicadores, valores, objetivos, cerrar, reabrir,
duplicar y mejora). La API no tiene sesión: `?usuario_delegacion=&usuario_codigo=` en la query string
de cualquier escritura; con él se comprueba que existe, está activo y tiene `CAL_OBJ` con escritura y
el especial 1 (`422` si no); sin él, la API actúa como responsable de calidad.

**Indicadores** (`/indicadores-calidad`, `CALKPI`; clave `delegacion` (`''` = común) + `codigo`).

| Parámetro | Columna | Notas |
|---|---|---|
| `descripcion` | `KPICDES` | Obligatoria; hasta 255. |
| `calculo` | `KPICCAL` | `INF_PLAZO`… en los 18 de Veolab (códigos 1 a 18, comunes, los crea la conversión); vacío = manual. La API solo crea manuales. |
| `unidad` | `KPICUNI` | `%`, `días`, `nº`, `horas`…; hasta 20. |
| `sentido` | `KPICSEN` | `S` mejor si sube (por defecto) · `B` mejor si baja. |
| `periodicidad` | `KPICPER` | Valores manuales: `M`, `T`, `S`, `A` (por defecto). |
| `decimales` | `KPINDEC` | 0-6; al crear sin ellos, 2 (al formatear, nulos = 0, como el escritorio). |
| `definicion` | `KPICDEF` | Definición o fórmula en texto, para el auditor. |
| `baja` | `KPIBBAJ` | `T`/`F`. |

- El código no se indica: `MAX(KPI1COD) + 1` de todas las delegaciones y nunca menos de 19.
- Los de Veolab solo admiten cambiar `baja` (lo demás, si llega igual, se acepta) y no se eliminan.
- No se elimina un indicador que use algún objetivo (`422`); al eliminarlo se borran sus valores.
- Lectura: añade `codigo_formateado`, `automatico` `T`/`F`, `sentido_texto`, `periodicidad_texto`,
  `objetivos` (cuántos lo usan) y `valores` (cuántos valores manuales). Filtros: los campos,
  `para_delegacion` (comunes y de esa delegación) y `automatico=T|F`.

**Valores manuales** (`/indicadores-calidad/valores`, `CALKPV`; `?delegacion=&codigo=` del indicador):

- `GET` (con `?desde=&hasta=` opcionales, por `fecha_inicio`): `fecha_inicio`, `fecha_fin`, `valor`,
  `valor_texto`, `observaciones`.
- `PUT ...&fecha_inicio=` `{valor (obligatorio), fecha_fin, observaciones}`: crea (`201`) o sustituye
  (`200`) el valor del periodo que empieza ese día. Sin `fecha_fin`, el último día del periodo según la
  periodicidad. Solo en indicadores manuales (`422` en los automáticos).
- `DELETE ...&fecha_inicio=`. Cada cambio se audita como un cambio del indicador (campo `CALKPV`).

**Valor calculado** (`GET /indicadores-calidad/valor?codigo=&desde=&hasta=&delegacion=`). El código del
indicador es único entre delegaciones (`?indicador_delegacion=` lo fija si hiciera falta); `delegacion`
es la de los datos (vacía = todo el laboratorio). Por defecto, del 1 de enero del año de `hasta` a hoy.
Devuelve `valor` (número o `null`) y `valor_texto` («96,2 %»).

**Cálculo** (`VeolabQualityIndicators::value`). Periodo de `desde` a `hasta`, ambos incluidos
(`>= desde` y `< hasta + 1 día`). Lo que se mide «al final del periodo» (acciones, equipos y proveedores) se mide
en el **corte**: el día siguiente al fin (excluido) o, si `hasta` es hoy o posterior, hoy (excluido). Con delegación, los registros de
esa delegación; en los maestros comunes (equipos, proveedores y gráficos de control), también los de
`''`. `null` si no hay datos, si el denominador es 0 o si la consulta falla (tabla o columna que no
existe). Los recuentos sin registros valen 0. Manuales: la media de `KPVNVAL` (no nulos) de los
valores con `KPVDINI` en el periodo, sin mirar la delegación (son del indicador).

| Cálculo | Condición |
|---|---|
| `INF_PLAZO` | `LABOPE` no anuladas (`OPEBANU` ≠ `T`) con `OPEDINF` en el periodo y `OPEDCOM` no nula; % con `DATE(OPEDINF) <= DATE(OPEDCOM)`. |
| `INF_DIAS` | Media de `DATEDIFF(OPEDINF, OPEDREG)` de las `LABOPE` no anuladas con `OPEDINF` en el periodo. |
| `INF_SUST` | `LABINF` con `INFDCRE` en el periodo; % de las que sustituyen a otro (`INF2COD` no nulo). |
| `QUE_MIL` | Quejas (`CALINC` clase `Q`, no anuladas) con `INCDDET` en el periodo × 1.000 / `LABOPE` no anuladas con `OPEDREG` en el periodo. |
| `QUE_DIAS` | Media de `DATEDIFF(INCDCIE, INCDDET)` de las quejas cerradas (`C`) con `INCDCIE` en el periodo. |
| `INC_NC` | Incidencias no anuladas con `INCBNCO` = `T` e `INCDDET` en el periodo. |
| `INC_DIAS` | Media de `DATEDIFF(INCDCIE, INCDDET)` de las incidencias (todas las clases) cerradas con `INCDCIE` en el periodo. |
| `INC_EFIC` | Acciones `CALACC` tipo `A` de incidencias no anuladas con `ACCDEFI` en el periodo y `ACCBEFI` `T` o `F`; % de `T`. |
| `ACC_VENC` | Acciones con `ACCDPRE <` corte, de incidencias no anuladas (delegación de `CALINC`). Corte en hoy: `ACCDREA` nula e incidencia no cerrada; periodo pasado: `ACCDREA` nula o `>=` corte, e incidencia no cerrada o con `INCDCIE >=` corte. |
| `EAP_SAT` | Resultados `CALEAR` de rondas con `EAPDINF` en el periodo y `EARCEVA` en `S`, `C`, `Q`, `I`, `P`, `N`; % de `S` y `C`. |
| `EAP_PLAN` | Familias `CALFAE` no de baja (de la delegación y comunes); cubiertas, las que tienen alguna ronda con resultados de sus parámetros (`CALFAT` → `CALEAR` → `CALEAP`, rondas de la delegación) con `COALESCE(EAPDREC, EAPDLIM) < hasta + 1` y año `>=` año de `hasta` − `FAENFRE` + 1 (frecuencia mínima 1); % de cubiertas. |
| `AUD_PROG` | Auditorías internas (`AUICTIP` `I`, `C`, vacío o nulo) no anuladas con `AUIDPRE` en el periodo; % en estado `R`, `I` o `C`. |
| `AUD_HALL` | Incidencias de clase `N` no anuladas con `INCDDET` en el periodo. |
| `FOR_PLAN` | Cursos `GRHPAF` con `COALESCE(PAFDPRE, PAFDINI)` en el periodo, sin `C` ni `H`; % en estado `R` o `E`. |
| `FOR_HORAS` | Suma de `PAFNHOR` por alumno asistente (`GRHALU` con `ALUBNAS` ≠ `T`) de los cursos `R` o `E` con `COALESCE(PAFDINI, PAFDPRE)` en el periodo / empleados en activo en algún momento del periodo (`EMPDALT` nula o `< hasta + 1`, y `EMPDBAJ >= desde` o, sin `EMPDBAJ`, `EMPBBAJ` ≠ `T`). |
| `EQU_VENC` | Series `ALMSEL` de productos con `PRDBEQU` = `T`, sin `SELDBAJ` ni estado `B` o `F`, con `SELDCAL` o `SELDVER <` corte. |
| `PRO_EVAL` | Proveedores no de baja con `PRODUEV` o `PRODPEV` no nula; % con `PRODPEV` nula o `>=` corte. |
| `GCO_FUERA` | Puntos `LABRCD` con `RCDTFEC` en el periodo y `RCDCTII` = `E`. |

**Objetivos** (`/objetivos-calidad`, `CALOBJ`; clave `delegacion` (`''` = todo el laboratorio) +
`codigo`, `MAX(OBJ1COD) + 1` de todas las delegaciones, no se indica).

| Parámetro | Columna | Notas |
|---|---|---|
| `anio` | `OBJNANI` | Al crear sin él, el actual. |
| `descripcion` | `OBJCDES` | Hasta 255; sin ella, la del indicador. |
| `indicador_delegacion`, `indicador_codigo` | `KPI2DEL`, `KPI2COD` | Obligatorio; existe y, si cambia, no de baja y común o de la delegación del objetivo. |
| `meta` | `OBJNMET` | Obligatoria. |
| `responsable_delegacion`, `responsable_codigo` | `EMR2DEL`, `EMR2COD` | Empleado; si cambia, de alta. |
| `plan` | `OBJCPLA` | Plan para conseguirlo. |
| `estado` | `OBJCEST` | `A` abierto · `C` cerrado. Lo ponen `/cerrar` y `/reabrir`. |
| `valor_alcanzado`, `resultado`, `fecha_cierre` | `OBJNVAL`, `OBJCRES`, `OBJDCIE` | Al cerrar; `resultado` `S` cumplido · `P` en parte · `N` no cumplido. |
| `conclusion` | `OBJCCON` | |

- Solo los abiertos se modifican y se eliminan (cerrado, `422` salvo lo que llega igual). Como el
  escritorio, uno con mejoras enlazadas no se elimina (`422`).
- Lectura: añade `codigo_formateado`, `estado_texto`, `resultado_texto`, `indicador_descripcion`,
  `indicador_unidad`, `indicador_sentido`, `indicador_automatico`, `indicador_decimales`,
  `responsable_nombre`, `meta_texto` («>= 95,00 %»), `valor` (el congelado si está cerrado; si no, el
  calculado del 1 de enero a hoy, o al 31 de diciembre si el año ya pasó; `null` si el año aún no ha
  empezado), `valor_texto`, `cumple` (`T`/`F`/`null`), `semaforo` y `mejoras`. Filtros: los campos
  (`anio`, `delegacion`, `estado`…) y `para_delegacion` (los de `''` y los de esa delegación).
- **Cumplimiento:** mejor si sube, `valor >= meta`; mejor si baja, `valor <= meta`. **Semáforo:**
  `verde` si cumple; `ambar` si no, pero `|valor − meta| <= |meta| × 0,1`; `rojo` en el resto; `null`
  sin valor o sin meta.
- `POST /objetivos-calidad/cerrar?delegacion=&codigo=` `{valor, resultado (obligatorio), conclusion}`:
  solo abiertos; sin `valor`, el calculado del año redondeado a los decimales del indicador y, si no
  hay valor calculado, `422` (como CerrarObjetivo, el valor es obligatorio). Pone `estado` `C` y
  `fecha_cierre` = hoy.
- `POST /objetivos-calidad/reabrir?delegacion=&codigo=`: vuelve a `A` y vacía `fecha_cierre`. Como el
  escritorio, conserva `valor_alcanzado`, `resultado` y la conclusión del cierre anterior.
- `POST /objetivos-calidad/duplicar?anio=[&delegacion=]`: copia los del año al siguiente, abiertos,
  con la misma delegación, descripción, indicador, meta, responsable y plan. Omite (en
  `data.omitidos`) los de un indicador de baja y los que ya existen en el año siguiente (misma
  delegación, indicador y descripción), así que se puede repetir.
- `GET /objetivos-calidad/seguimiento?delegacion=&codigo=`: `periodos` `T1`-`T4` (cada uno hasta hoy
  como mucho; los que no han empezado, sin valor) y `A` (el año), con `valor`, `valor_texto`, `cumple`
  y `semaforo`; además `valor_alcanzado` si está cerrado.
- `POST /objetivos-calidad/mejora?delegacion=&codigo=` `{delegacion, serie, descripcion, detalle,
  detectada_por_*, usuario_*}`: crea con las reglas de `POST /incidencias` la incidencia de clase `M`
  y origen `D` (delegación, la del objetivo; título «Mejora del objetivo: …»; detalle, «Indicador: …. Meta: ….
  Valor alcanzado: ….», con el valor solo si está cerrado, como el escritorio; quien la detecta, el responsable del objetivo) y la enlaza (`CALIYE` tipo `CALOBJ`, serie
  `''`, código del objetivo). Al borrar la incidencia se borran sus enlaces, también este.
- **Auditoría** (`ACCAUD`): alta, borrado y modificación como el resto; cerrar y reabrir, suceso de
  fila y, con nivel 3, uno por campo que cambia; duplicar, una inserción por objetivo.

Tareas pendientes `OB` y `OA` (objetivos sin definir o sin cerrar): §11 octodecies.

**Sin hacer:** el gráfico de evolución y los marcadores de exportación.

## 12. Fixes de corrección a arrastrar de la v1

Se aplican dentro del rediseño (no son parte del diseño nuevo, son fallos):

1. **`env()` en el middleware `SetClientDatabase`** → con `config:cache` devuelve
   valores por defecto, no los del `.env`. **Verificado (25-sep-2026): en producción
   la config NO está cacheada** (`bootstrap/cache/config.php` no existe; `.env` con
   `DB_USERNAME=veolabapi`), así que hoy funciona — pero es una **trampa latente**: el
   día que se cachee config (optimización recomendada en prod), se rompe. Fix:
   construir la conexión `dynamic` con
   `array_merge(config('database.connections.mysql'), ['database' => $name])`, que
   funciona con o sin caché. **No ejecutar `config:cache` en el servidor hasta aplicarlo.**
2. **Conexión `dynamic` sin `charset`/`collation`/`port`/`strict`** → el `array_merge`
   anterior también lo resuelve.
3. **Paginación sin `ORDER BY`** → §8.1.
4. **7ª clave ignorada** (LABFIR: `InformeFirmaController` declara `key5Field` pero
   `BaseController` solo maneja hasta `key4`) → el rediseño debe soportar la aridad
   real de cada tabla.
5. **Validación devolvía `500`** y filtraba `$e->getMessage()` → §10.
6. **`limit` sin tope ni casteo** → §8.1.

## 12 bis. Auditoría, códigos y Verifactu

**Auditoría (`ACCAUD`)**, replicando `SES_SucesoAuditoria` según `ACCPAR.PARNAUN`:

| Operación | Suceso | Nivel |
|---|---|---|
| `POST` | `I` | ≥ 2 |
| `PUT` | `F` (fila) con nivel 2; con nivel 3 un `C` por campo cambiado (`AUDCCAM` = tabla+columna, valor nuevo/anterior) | 2 / 3 |
| `DELETE` | `B` | ≥ 2 |

- `AUDCFIL` = código formateado como `PAR_FormatoCodigo` (config. de `ACCCFC`;
  sin ella, el formato de reserva `del-ser-cod[-descripción]`). Las tablas que Veolab
  audita con su descripción (empleados, cargos, cursos, matrices, normativas, marcas,
  rangos, dictámenes, secciones, tipos…) la declaran en `$auditDescription`; solo
  aparece si la tabla no tiene formato configurable, como en VB. Tablas con otra forma
  de clave sobreescriben `auditRow()`.
- Cada token es una sesión de Veolab en `ACCSES` (`SESCOBS = 'API REST v2 (token N)'`,
  delegación y usuario vacíos): en Veolab se ve que el cambio vino de la API.
- Cabecera opcional `X-Veolab-Sesion: <texto>` → `SESCOBS = 'API REST v2 (token N) - <texto>'`
  (recortado a 100, sin caracteres de control). Cada texto distinto es una sesión
  distinta, para distinguir usuarios de la aplicación cliente; una sesión ya creada
  nunca se reescribe, así que lo ya auditado sigue atribuido a quien era.
- Se escribe dentro de la transacción del cambio. Las lecturas no se auditan.

**Códigos:** como `DBS_Autoincremento`: contador `ACCCLT` con el múltiplo de
`ACCCFC.CFCNMUL`, repitiendo mientras el código ya exista. Reglas de `ACCCFC`
(para todas las tablas): no autonumérico (`CFCBAUT`) → el código es obligatorio;
bloqueado (`CFCBBLO`) → no se puede indicar a mano; un código indicado que ya
existe → `422`. "Serie por cliente" (`CFCBCLI`) → sin serie, la del cliente.

**Operaciones (`LABOPE`, fase 1: datos generales):**
- Estado y fechas del flujo editables, con la lógica de la barra de estados de la
  ficha: avanzar rellena con la fecha actual las fechas vacías hasta el nuevo estado
  (preparada solo fecha); retroceder borra las de los estados posteriores; cambiar de
  estado desanula. `LABCON.CONBBAR` bloquea el cambio de estado y `CONBUNO` lo
  limita a un paso. Estados: 0 registrada · 1 recibida · 2 preparada · 3 iniciada ·
  4 finalizada · 5 validada · 6 enviada · 7 archivada.
- Anulación (`es_baja=T`): fecha de anulación = hoy si no se indica; si estaba
  archivada pasa a enviada.
- Solo lectura (los mantiene Veolab): tanda, técnicas, prefacturada/facturada/factura,
  precios modificados, datos IGEO.
- No se modifica si está en un informe validado, o pendiente con firma válida.
- Borrado: mismas comprobaciones que Veolab (órdenes, informes, facturas, residuos,
  cartas de control, operación de control, préstamos) y cascada de resultados,
  servicios, analistas, departamentos, gastos, autodefinibles, movimientos y avisos;
  documentos a la papelera; stock devuelto si el módulo Almacén está activo.
- Facturable (solo lectura): interna → `F`; externa → `T` si el cliente existe y su
  modo de facturación (`CLICMDF`) no es `N`. Se recalcula al cambiar tipo o cliente.
- Al crear: desglose por defecto `LABCON.CONCTID` (si no, `S`); tarifa por defecto la
  del cliente.

**Operaciones, fase 2: servicios al crear** (`POST /operaciones` con
`"servicios": [{"delegacion": "", "codigo": "..."}]`). Solo en la creación; cambiar o
quitar servicios después se hace en Veolab. Con `LABCON.CONBSER` (una operación por
servicio) solo se admite uno. Réplica de `FichaOperacion.frm` (`AñadirServicio` + `Grabar`):
- **Precio** de servicio/parámetro: del presupuesto (si `CONBSDP` y la operación tiene
  presupuesto; se filtra por ese presupuesto, a diferencia de Veolab), de la tarifa
  (`CONBTAR`) o del cliente; con respaldo en el precio/descuento base. Total por
  desglose: `S` precio del servicio (si es 0 y `CONBDPZ`, suma de parámetros); `T` suma
  de parámetros y gastos; `N` precio manual. Precio de la operación = suma de servicios,
  sin suplidos. `precios_modificados` = `F`.
- **Genera** `LABOYS`, `LABRES` (un parámetro una sola vez por operación; normativa
  `LABTYN` del servicio; primer analista; referencia IGEO), `LABCOR` (valor por defecto
  si `CONBPRE`; marca -1 si existe), `LABOYG` (suplidos aparte), `LABOYE`, `LABOYD` y,
  con Almacén, consumos/usos (`ALMMOV`) sobre el lote predeterminado descontando stock.
- **Operación**: tipo de operación y matriz del primer servicio (si no se indican),
  envases/cantidad del último (si vacíos), lista de técnicas (`;`), fecha de compromiso
  si `CONBAFC` y hay recepción (días laborables con festivos `AGEFES`, o naturales).
- Pendiente: avisos (`ACCNOT`) al analista/compromiso, que Veolab crea tras grabar.

**Operaciones: campos autodefinibles** (`LABAUT` definición, `LABOYA` valores;
`App\Support\VeolabCustomFields`). En `POST`/`PUT` y en la lectura van como
`"autodefinibles": {"Nombre": valor}`:
- Se identifican por **nombre** (`AUTCNOM`, sin distinguir mayúsculas): solo
  autodefinibles de operación (`AUTCTIP = 'O'`), no categorías ni de baja, de la
  **delegación de la operación o generales** (`DEL3COD = ''`); nunca de otra
  delegación. Veolab no deja repetir el nombre; si aun así estuviera en ambas, gana
  el de la delegación de la operación. Nombre desconocido → `422`.
- En `PUT` solo se tocan los indicados; `null`/`''` borra el valor (sin fila en
  `LABOYA`, como Veolab). Cada valor nuevo se audita como campo `#<nombre>` de `LABOPE`.
- Por tipo (`AUTCTDD`): número (`N`) con coma o punto, guardado con coma decimal y el
  formato numérico de `AUTCFOR`; fecha (`D`, `V`) en ISO (o `dd/mm/aaaa`), guardada
  con el formato de fecha de `AUTCFOR` (sin formato, `dd/mm/aaaa [h:mm:ss]`); fichero
  (`F`) como `{"delegacion": "", "codigo": "..."}` de un registro en vigor de la tabla
  de `AUTCFOR` (clave en `OYA3DEL`/`OYA3COD`, texto de Veolab en `OYACVAL`); texto,
  extenso, seleccionable e incremento especial, tal cual (como Veolab, el
  seleccionable admite texto libre). La lectura devuelve lo guardado (el fichero como
  `{delegacion, codigo}`).
- El vínculo con servicios (`LABAYS`) solo decide lo que muestra Veolab: no restringe.
- Toda operación tiene la fila "cero" de `LABOYA` (`AUT3DEL = ''`, `AUT3COD = 0`),
  que usan los listados de Veolab; la API la crea al dar de alta (y la repone al
  modificar operaciones que no la tengan).
- Con la operación en un informe validado/firmado solo se admite un `PUT` con
  autodefinibles editables estando validada (`AUTBVAL = 'T'`).
- Incremento especial: solo actúa cuando Veolab crea varias operaciones a la vez;
  la API crea una por petición, así que el valor se guarda tal cual.
- **Desde Veolab 3, valor predeterminado y fórmulas** (`LABAUT.AUTCPRE`;
  `VeolabCustomFields::applyDefaults`, `App\Support\VeolabFieldFormulas`): al crear, los
  autodefinibles vacíos reciben su valor fijo y se calculan las fórmulas (`=...`) de los
  campos vacíos (un valor recibido se respeta, como al importar); al generar desde una
  planificación solo las fórmulas. En `PUT` se recalculan las fórmulas de los campos no
  indicados cuando la petición toca autodefinibles o campos de recolección. Los
  autodefinibles vinculados a servicios solo lo reciben si la operación tiene alguno.
  Las fórmulas leen `field("ªNombre")` y los campos fijos por su marcador
  (`field("temperaturaoperacion")`); mismo evaluador que el escritorio y la web
  (`Documentacion\Formulas autodefinibles\casos.csv`).
- **Campos de recolección de Veolab 3** (pestaña Recolección; desde 3.0):
  `fecha_elaboracion`, `fecha_caducidad` (sin hora), `cloro_libre`, `ph`, `conductividad`
  (texto), `condiciones_recepcion`, `informacion_cliente`, `pedido`, `transporte` y
  `conservacion`; y `conformidad_recepcion` (`C`, `N`, `D` o vacía, §11 duodecies). La pantalla «Campos» de Veolab configura cuáles se ven y su
  predeterminado o fórmula (filas `X` de `LABAUT`); la API aplica esos predeterminados y
  fórmulas a los campos visibles. `/envases`, `/lugares` y `/temperaturas` son los valores
  predefinidos de `envase`, `lugar_recogida` y `temperatura`.

**Planificaciones** (`/planificaciones`, `LABPLO`; clave `delegacion` + `codigo`, sin
serie). "Preoperaciones" con los mismos campos que la operación (mismos nombres de
parámetro), `serie_operaciones` (serie de las operaciones que genera),
`numero_operaciones` (tanda) y `calcular_compromiso`. Réplica de
`FichaPlanificacion`/`Planificaciones`:
- **Servicios al crear** (`"servicios"`): `LABPYS`, `LABPYT` y `LABPYG` con los precios
  calculados como en la operación, salvo que las técnicas salen siempre de `LABSYT`
  (no del presupuesto); sin consumos, columnas de resultado ni referencias. Precio,
  tipo de operación, matriz y envases se heredan como en la operación.
- **Servicios al leer**: la respuesta incluye `servicios: [{delegacion, codigo, nombre}]` en el
  orden de la rejilla (`LABPYS.PYSNPOS`, nombre de `LABSER.SERCNOM`). En un `PUT` se aceptan si son
  los mismos que ya tiene (se ignoran); si cambian → `422`.
- **Autodefinibles**: mismas definiciones y reglas que en la operación; valores
  propios en `LABPYA` (se auditan solo al modificar, como Veolab).
- **Fechas** (`LABFEP`): la respuesta incluye `fechas: [{codigo, fecha, completada}]`
  (solo las activas) y `repeticion` (§11 nonies, o null). Sin fecha (`fecha_inicio: null`,
  `PLONFRE = -1`), fecha única (`fecha_inicio`, `PLONFRE = 0`) o periódica (`fecha_inicio`
  + `repeticion`; sin indicarla se conserva la que tenía). Al cambiar fecha o periodicidad,
  las no completadas se desactivan (`FEPTINI = NULL`, se conservan por el vínculo de
  códigos de barras) y se crean las nuevas, salvo las que ya están completadas en esa
  fecha; si la periodicidad no genera ninguna fecha → `422`.
- `PUT /planificaciones/fechas?delegacion=&codigo=&fecha=` con `{"completada": "T"|"F"}`
  marca una fecha como generada o pendiente (suceso `M` en la auditoría).
- **Aviso en la agenda** (`es_aviso` T/F, `aviso_numero`, `aviso_unidad` M/H/D/S),
  como `AGE_CrearEventoCalendarioPlanificacion`: al crear con aviso, o al cambiar el aviso,
  la fecha o la periodicidad, se rehace el evento de agenda (`AGEAGE` con
  `planificacion_*`, asunto "Planificación <código> <cliente>", 1 h). Dueño: el primero
  (por delegación y código) de los usuarios con la función `LAB_PLA` en su perfil y de
  los analistas de sus técnicas, si no tienen cliente o es el de la planificación; el
  resto son asistentes. Fechas: las de la planificación desde hoy, sin fecha de fin
  (como Veolab), y avisos de las que no han vencido, para el dueño y los asistentes
  (Veolab, solo para el dueño). Sin aviso se borra el evento. Aviso sin fecha → `422`.
  Veolab rehace estos eventos al ampliar el horizonte de periodicidades.
- **Borrado**: desvincula sus operaciones, documentos a la papelera, borra
  autodefinibles, fechas, servicios/técnicas/gastos (Veolab deja estos tres
  huérfanos) y los eventos de agenda.
- **Desde Veolab 3**: los mismos campos de recolección que la operación (columnas
  `PLO*`), con sus predeterminados y fórmulas, y `empleado_comercial_*`: al indicar el
  presupuesto, la planificación sin comercial toma el del presupuesto
  (`HeredarComercialPresupuesto`), salvo que la petición indique el comercial.

**Generar operaciones** (`POST /planificaciones/generar`, cuerpo
`{"delegacion": "", "codigo": 1, "fecha": 12, "operacion": {...}}`): como
`GenerarOperacion` al generar sin abrir la ficha. Copia los campos de la planificación
(serie = `serie_operaciones`), su rejilla de servicios/técnicas/gastos **con sus
precios** (no se recalculan), el analista de la planificación (si no tiene, el primero
de la técnica), consumos por defecto, referencias IGEO del cliente y sus
autodefinibles; fecha de compromiso si `calcular_compromiso` (desde la recepción o
ahora). Enlaza la operación (`planificacion_*`) y marca la fecha como completada.
`fecha` (código de `LABFEP`) es opcional; una fecha ya generada → `422` (marcarla como
pendiente para repetir). `operacion` sustituye campos copiados (p. ej. `estado`,
`fecha_recepcion`, `autodefinibles`), salvo delegación, servicios y enlace a la
planificación. Desde Veolab (→ `422`): tandas (`numero_operaciones > 1`) y
planificaciones con varios servicios si `CONBSER`.

**Lotes** (`/lotes`, `LABLOT`; clave `delegacion` + `serie` + `codigo`, código de
texto de hasta 50). Réplica de `FichaLote`/`Lotes`:
- Código generado con el contador si no se indica (reglas de `ACCCFC` como el resto).
- `estado`: 0 activo · 6 completado · 7 archivado (activo al crear). `fecha_registro`
  = ahora si no se indica (`null` = sin fecha); `fecha_recepcion` se guarda con
  hora y minutos.
- **Autodefinibles de lote** (`LABAUT` con `AUTCTIP = 'L'`, valores en `LABLYA`):
  mismas reglas que los de operación (nombre, delegación del lote o generales, tipos,
  fichero como `{delegacion, codigo}`), salvo que no hay fila "cero" ni vínculo con
  servicios, y como en Veolab solo se auditan al modificar. Desde Veolab 3 reciben
  también su valor predeterminado o fórmula (`AUTCPRE`), como los de operación.
- Las operaciones se vinculan desde la operación (`lote_*`; `lote_relacionado_*` para
  las relacionadas). Para listarlas: `GET /operaciones?lote_delegacion=&lote_serie=&lote_codigo=`.
- **Borrado** (sin comprobaciones, como Veolab): desvincula sus operaciones, documentos
  a la papelera y borra sus autodefinibles. Además desvincula las operaciones
  relacionadas y las planificaciones (Veolab las deja apuntando al lote borrado).

**Órdenes de trabajo** (`/ordenes`, `LABORD`; clave `delegacion` + `serie` + `codigo`).
Réplica de `FichaOrden`/`Ordenes`:
- Campos: `observaciones`, `fecha_creacion` (ahora si no se indica), `fecha_impresion`,
  `departamento_*` (vacío = todos) y `tecnica_*`.
- **Operaciones** (`LABOYO`): `"operaciones": [{"delegacion": "", "serie": "26", "codigo": 143}]`,
  obligatoria (al menos una) al crear; en `PUT` sustituye la lista entera. Una operación
  puede estar en varias órdenes: `posicion` (`OYONPOS`, solo lectura) es su índice entre
  ellas; al quitarla de la primera, la siguiente orden pasa a ser la primera. Las
  operaciones registradas o recibidas pasan a **preparadas** (estado 2, fecha de
  preparación de hoy), como en Veolab.
- **Personal** (`LABORE`): `"personal": [{"delegacion": "", "codigo": 3}]` (empleados).
  Si no se indica, se añaden los analistas (`LABOYE`) de las operaciones nuevas, como
  hace la ficha al seleccionarlas; si se indica, sustituye la lista.
- Con `LABCON.CONBBTD`, `422` si alguna técnica de las operaciones está bloqueada
  (operación interna sin archivar ni anular con dictamen no satisfactorio).
- La lectura incluye `operaciones` (con `posicion`) y `personal`. Los cambios de estas
  listas se auditan como campos `LABOYO` / `LABORE` de la orden.
- **Borrado**: renumera las operaciones, borra operaciones y personal de la orden y
  envía los documentos a la papelera.
- Pendiente: notificaciones al analista (`ACCNOT`), que Veolab crea al grabar.

**Informes** (`/informes`, `LABINF`; clave `delegacion` + `serie` + `codigo`). Réplica de
`FichaInforme`/`Informes` (`App\Support\VeolabReports`):
- Campos: `fecha_creacion`, `fecha_envio` y `fecha_validacion` (sin hora, como la ficha),
  `es_acreditado`, `es_final`, `es_visible`, `opiniones`, `observaciones`,
  `forma_envio_*`, `normativa_*`. Solo lectura: `visto_cliente`, `ultima_firma_*`.
- **Operaciones** (`LABIYO`): `"operaciones": [{"delegacion": "", "serie": "26", "codigo": 143}]`,
  obligatoria al crear; en `PUT` sustituye la lista. Una operación puede estar en
  varios informes.
- **Operaciones históricas** (`"operaciones_historicas"`, mismo formato, opcional y
  puede ir vacía; `LABIYO.IYOBHIS = 'T'`): la segunda lista de la ficha, con operaciones
  anteriores que el informe muestra como histórico al exportarlo (p. ej. gráficas de
  evolución). No pertenecen al informe: no reciben sus fechas ni su estado, no cuentan
  para los departamentos de las firmas y al borrar el informe no pierden su fecha de
  informe (Veolab no las distingue en estos dos últimos casos). Cada lista se sustituye
  solo si se indica.
- **Al crear**, lo que no se indique: pendiente, final, visible, fecha de hoy; acreditado
  si alguna técnica de las operaciones tiene fecha de acreditación; normativa del
  servicio de la operación de menor código; forma de envío del cliente de la primera
  operación; opinión automática de `LABOEI` (por marca, sin marcas, con/sin normativa).
- **Efecto sobre las operaciones** (informe final; al crear y al cambiar operaciones,
  envío, validación o `es_final`): fecha de informe y de envío; con fecha de envío pasan
  a enviadas (6); si no, pendiente → finalizadas (4, también si estaban más avanzadas),
  validado → validadas (5) con su fecha, rechazado → de vuelta a preparadas (2) las
  operaciones afectadas por el rechazo (todas si es total; las de los departamentos que
  rechazan si es parcial; Veolab solo mira la última operación de la lista). Las fechas
  vacías de los estados intermedios toman la de hoy. Sin fecha de envío y con el módulo
  IGEO, las operaciones ya enviadas a IGEO vuelven a "recibida".
- **Firmas** (`LABFIR`, tipos en `GET /tipos-firma`): `PUT /informes/firmas?delegacion=&serie=&codigo=`
  con `{"accion": "firmar"|"rechazar"|"eliminar", "tipo_firma_delegacion": "",
  "tipo_firma_codigo": 1, "usuario_delegacion": "", "usuario_codigo": "ADMIN",
  "departamento_delegacion": "", "departamento_codigo": 2, "comentario": "..."}`.
  La API no tiene usuario: **quien firma se indica en la petición** (usuario de Veolab
  en vigor) y no se comprueban sus privilegios ni el tipo de firma de su perfil; eso es
  cosa de la aplicación que llama. Sin departamento la firma es total; con él, parcial
  (debe ser un departamento de las operaciones del informe). Se firma en orden: no se
  puede firmar si la firma obligatoria anterior está pendiente, ni eliminar si la
  obligatoria siguiente ya está aplicada (`422`).
- **Estado de validación** (`estado_validacion`: `P`/`V`/`R`) resultante de las firmas:
  rechazado si hay un rechazo total o un rechazo parcial de una firma obligatoria;
  pendiente si falta una firma obligatoria o una firma parcial no cubre todos los
  departamentos del informe; si no, validado (con fecha y usuario de validación).
  A mano (`estado_validacion` en `POST`/`PUT`, con `usuario_validacion_*` para validar o
  rechazar) solo si `LABCON.CONBBEV` no lo bloquea y el informe no está firmado.
- **Informe firmado** (alguna firma total y ningún rechazo): `422` al cambiar fecha de
  creación, acreditado/final/visible u operaciones; con `LABCON.CONBBLI` también
  observaciones, opiniones y normativa. Envío y forma de envío siguen editables.
- La lectura incluye `operaciones`, `operaciones_historicas` y `firmas` (una fila por
  tipo de firma y departamento).
- **Borrado**: `422` si está validado; quita la fecha de informe de sus operaciones, borra
  operaciones, firmas y notificaciones del informe y envía sus documentos a la papelera.
- Desde Veolab: el documento (PDF) del informe y su exportación automática, las marcas
  de resultados y técnicas exportables/acreditadas de la rejilla, el JSON de IGEO y las
  notificaciones (firmantes, rechazo, informe nuevo al cliente). El aviso de cliente con
  facturas vencidas (`CONBAFP`) es solo un aviso en Veolab: la API no lo aplica.

**Resultados** (`/resultados`, `LABRES` + `LABCOR`; clave `operacion_delegacion` +
`operacion_serie` + `operacion_codigo` + `tecnica_delegacion` + `tecnica_codigo`). Réplica
de `FichaResultados` (`App\Support\VeolabResults`):
- `GET`: listado estándar de técnicas de operaciones (filtrable, p. ej.
  `?analista_codigo=3&fecha_fin[null]=T`), cada una con `columnas`: `columna`, `letra`,
  `valor`, títulos, `tipo` (`N` número · `T` texto · `F` fecha · `H` hora · `C` casilla),
  `formato`, `seleccionables`, `predeterminado`, `formula`, `es_activa`, `es_editable`,
  visibilidad, control de exactitud/precisión y `marca_*`. Sin `POST` ni `DELETE`: las
  técnicas llegan con los servicios de la operación.
- `PUT ?operacion_delegacion=&operacion_serie=&operacion_codigo=` con
  `{"tecnicas": [{"tecnica_delegacion": "", "tecnica_codigo": "PH", "valores": {"A": "7,2"},
  "marcas": {"B": {"delegacion": "", "codigo": 3}}, "fecha_inicio": ..., "fecha_fin": ...,
  "analista_delegacion": "", "analista_codigo": 4, "observaciones": "..."}],
  "fecha_inicio": ..., "fecha_fin": ..., "dictamen_delegacion": "", "dictamen_codigo": 2,
  "usuario_delegacion": "", "usuario_codigo": "ADMIN"}` (todo opcional). Añadiendo
  `&tecnica_delegacion=&tecnica_codigo=` el cuerpo son los campos de esa técnica (y el
  usuario). Respuesta: `estado`, fechas y dictamen de la operación y `avisos` de las marcas.
- **Valores** por letra de columna, solo en celdas activas y editables, como texto
  (máx. 255). **Números**: como número JSON (`7.2`) o como texto con coma o punto
  (`"7,2"`, `"7.2"`), sin separador de miles; se guardan con el **separador decimal del
  laboratorio** (`VEOLAB_SEPARADOR_DECIMAL` del `.env`, `,` por defecto: Veolab usa la
  configuración regional de los equipos, la misma en todo el laboratorio y, por ahora,
  en todos los de cada servidor). En columnas de texto, un número JSON se escribe igual
  y un texto se guarda tal cual. Los rangos se comparan entendiendo coma y punto, y los
  límites que escribe la API usan el separador del laboratorio. Fechas `dd/mm/aaaa`,
  horas `hh:mm`, casillas `T`/`F` (se guardan `Sí`/`No`). `null` vacía.
- **Marcas por rangos** (`LABCYR`/`LABRAN`, y los de normativa con el rango `LABTYN` de la
  normativa del servicio): al cambiar el valor de una celda con rangos se recalcula su
  marca (la primera concluyente; "no evaluable" `-2` si no la hay) y el valor puede
  sustituirse por el límite superado (`RANBSUV`/`RANBSUX`) o por el texto de la marca
  (`MARCSUS`). `marcas` aplica marcas a mano (`null` la quita).
- **Analista**: las técnicas con resultado sin analista toman el empleado del usuario
  indicado (`ACCUSU.EMP2*`), como la ficha con el usuario en sesión.
- **Autorizaciones** (Veolab 3, `LABCON.CONCAUT`): analista sin autorización vigente en una
  técnica acreditada → aviso (`A`) o `422` (`B`), ver §11 duodecies.
- **Equipos vencidos o fuera de uso** (Veolab 3, módulo `ALM`, `LABCON.CONCVEN`,
  `equipos_vencidos` en `/configuracion/laboratorio`; `RevisarEquiposVencidos`): en las
  técnicas con valores o marcas en la petición que tienen registrado el uso (`ALMMOV` tipo
  `U`) de un equipo (`ALMPRD.PRDBEQU = 'T'`) cuya serie, en la fecha de inicio de la
  operación (hoy si no la tiene), está fuera de uso (`F`) o de baja (`B`) o tiene la próxima
  calibración, verificación, mantenimiento o la caducidad (`SELDCAL`, `SELDVER`, `SELDMAN`,
  `SELDCAD`) en esa fecha o antes: con `CONCVEN` vacío o nulo (bloquear, el predeterminado de
  las bases nuevas) no se graba nada (`422`, con los equipos y el motivo); con `A` se graba y
  se añade un aviso por técnica en `avisos` (`columna` nula); con `N` (el de las bases
  convertidas) no se comprueba. Para desbloquear hay que cambiar el equipo en los consumos de
  la operación o poner la serie al día. En bases 2.4 no se comprueba.
- **Fechas y estado** (con `LABCON.CONBMAI`/`CONBMAF`): con algún valor la operación y la
  técnica toman fecha de inicio (iniciada, 3); con todas las celdas editables cubiertas
  (`CONCFIN = 'P'`: solo la primera columna), fecha de fin (finalizada, 4) y dictamen
  (el asociado a las marcas, o el primero sin marca); al vaciarlas se deshacen. Las
  casillas no cuentan. Las fechas de técnica indicadas mandan, y la de inicio de la
  operación pasa a ser la menor de sus técnicas. `fecha_inicio`/`fecha_fin`/`dictamen_*`
  de la operación actúan como en la ficha (sin inicio no hay fin ni dictamen; un
  dictamen finaliza). Si el estado avanza, las fechas vacías de recepción y preparación
  toman la de hoy (Veolab no las rellena aquí).
- `422` si algún informe (no histórico) de la operación está validado o tiene la firma
  total. Al grabar una operación finalizada se **borran las firmas** de sus informes, que
  vuelven a pendientes (p. ej. corrección tras un rechazo).
- **Formato de columna** (`COTCFOR`, `App\Support\VeolabFormat`): se aplica al valor recibido
  como al confirmar la edición en la ficha (`CAD_FormatoCondicional`/`CAD_Formato`/`Format`
  de VB): numéricos `0 # . , % E+`, secciones con `;`, cifras significativas `SSS`,
  condicionales `F(<0,5|0,000|0,00)`, fechas/horas `dd/mm/yyyy hh:nn` y `<`/`>` en textos.
  Los números se leen como VB con el separador del laboratorio (con coma decimal, `"1.5"` en
  una columna de texto con formato numérico es 15). En columnas numéricas el resultado debe
  seguir siendo numérico (`422`). Un formato no reconocido deja el valor como está.
- **Fórmulas** (`COTCFOM`, `App\Support\VeolabFormulas`): réplica del analizador de Veolab
  (mismas prioridades por paréntesis añadidos, evaluación de izquierda a derecha y rarezas):
  columnas por letra, números con el separador del laboratorio, literales entre comillas,
  `^ * / \ + - : = <> < > <= >= & | #` y `ln log sin cos tan sqr exp abs round(x;n)
  if(c;a;b) not(x) cross(x;"a#b";...) format(x;"fmt") field("campo")
  result(delegación;técnica;columna)`. Al cambiar algún valor de una técnica se recalculan
  sus fórmulas (dependencias primero) y las de `result()` de toda la operación; el resultado
  toma el formato de la columna y recalcula su marca (y puede sustituirse por el límite).
  Una celda con fórmula cuyo valor llega en la petición no se recalcula (modificada a mano,
  como en la ficha); `"recalcular": "T"` en la técnica las recalcula todas (menú "Recalcular").
  Veolab no guarda esa marca de "modificada a mano" (solo vive mientras la rejilla está
  cargada): igual que en Veolab tras recargar, una petición posterior que cambie otra celda
  de la técnica vuelve a calcularla.
  Las letras leen la rejilla de la ficha, no la base de datos: las celdas desactivadas
  (`CORBACT`) cuentan como vacías (su fórmula sí se calcula y se graba si da algo, como en
  Veolab). La API ve todas las técnicas de la operación, como la ficha en la vista por
  operación con acceso total (en Veolab, con acceso restringido o en la vista por técnica,
  `result()` de una técnica que no está en pantalla da vacío).
  Errores: desbordamiento, división por cero y error de función dejan el texto de Veolab
  (`MEN00252/253/254`); un error de sintaxis deja la celda como estaba y añade un aviso.
  Sin `LABCON.CONBEFD`, un operando vacío deja la celda vacía. `field()` calcula los
  autodefinibles (`"ªNombre"`) y los campos directos de la operación y de la técnica
  (`referenciaoperacion`, `temperaturaoperacion`, `fechainiciooperacion`, `codigotecnica`,
  `unidadestecnica`, `fechafintecnica`...); con otro campo la celda no se recalcula y se
  devuelve un aviso. En `result()` una columna numérica es la posición en esa técnica.
- **Cartas de control** (módulo CDC, ver §11 sexies): una técnica cuya carta está en error no
  se puede grabar (`422`), y los resultados de control de una operación de control alimentan
  las cartas. La "delegación de la sesión" de Veolab es la de `usuario_delegacion` si se
  indica `usuario_codigo`, o la de la operación.
- **Pendiente**: notificaciones de marcas, importación de equipos, "establecer predeterminados".

**Presupuestos** (`/presupuestos`, `FACPRE`; clave `delegacion` + `serie` + `codigo`). Réplica
de `FichaPresupuesto`/`Presupuestos` (`App\Support\VeolabBillingLines`):
- Campos: `descripcion`, `informacion_adicional`, `orden_compra`, `solicitado_por`,
  `observaciones`, `lugar`, `horario`, `recogida`, `facturacion`, `notas`, `fecha`,
  `fecha_vencimiento`, `fecha_entrega`, `fecha_aceptacion` (sin hora), `estado`
  (`P` pendiente · `E` enviado · `A` aceptado · `R` rechazado · `V` validado ·
  `C` cancelado), `es_acreditado`, `es_archivado`, `tipo_desglose` (`S` servicio ·
  `T` técnica · `N` sin desglose), `descuento`, `tipo_impuesto_1/2`, `valor_impuesto_1/2`,
  `cliente_*`, `empleado_comercial_*`, `tarifa_*`. Desde Veolab 3, `fecha_seguimiento`
  (sin hora): al pasar a enviado se pone a hoy más los días de Configurar ventas
  (`dias_seguimiento_presupuestos`) si estaba vacía; la notificación al comercial al
  alcanzarla la crea Veolab.
- **Calculados** (solo lectura): `subtotal`, `base_imponible`, `importe_impuesto_1/2`,
  `suplidos`, `total` y `precios_modificados`. Base = subtotal − descuento; total =
  base + impuesto 1 − impuesto 2 (retención) + suplidos. Descuentos e impuestos son texto:
  porcentaje (`"21%"`) o importe (`"15"`). Se aceptan con coma o punto decimal y
  **se guardan con el separador del laboratorio** (`VEOLAB_SEPARADOR_DECIMAL`, ver
  resultados), porque Veolab los lee con la configuración regional de sus equipos: con
  coma, un `"10.5%"` guardado tal cual lo leería como 105 %. Lo mismo para los
  descuentos de las líneas y para los que se copian del cliente, la tarifa o el
  presupuesto. Sin desglose (`N`) el `subtotal` se puede indicar a mano.
- **Al crear**, lo que no se indique: pendiente, fecha de hoy, desglose de
  `LABCON.CONCTID`; del cliente, su descuento, impuestos, tarifa y el vencimiento
  (fecha + días de vencimiento de presupuestos). Lo mismo al **cambiar de cliente**.
- Pasar a enviado / aceptado apunta la fecha de entrega / aceptación si está vacía.
- **Líneas** (`FACLIP`), en `POST` y en `PUT` (sustituyen la rejilla entera). Dos formas,
  excluyentes:
  - `"servicios": [{"delegacion": "", "codigo": "AGUA01", "cantidad": 1, "punto_muestreo_codigo": 3}]`:
    como "añadir servicio": cada servicio seguido de sus técnicas y sus gastos, y los
    gastos suplidos en un grupo al final, con los precios del cliente o la tarifa.
  - `"lineas": [...]`: la rejilla tal cual, en orden. Cada línea: `tipo`, `referencia`,
    `descripcion`, `cantidad`, `precio`, `descuento`, `es_destacada`, `es_agrupada` y,
    según el tipo, `servicio_*`, `tecnica_*` o `gasto_*` (y `punto_muestreo_codigo` en
    las de servicio). Lo que no se indique (o vaya a `null`) toma lo que pone Veolab al
    añadir la línea: referencia, nombre en informes (con la marca de acreditación si el
    presupuesto es acreditado), cantidad 1 y precio/descuento del cliente o la tarifa.
    Indicar algún precio o descuento marca `precios_modificados`.
  - Tipos: grupos `S` servicio, `L` línea de grupo, y grupos especiales (sin cantidad ni
    precio; suman sus líneas) `E` técnicas, `A` gastos, `U` suplidos; detalle `T` técnica,
    `G` gasto, `D` línea libre. Una línea de detalle cuelga del grupo anterior.
  - Totales: con desglose `T` cada grupo suma sus líneas; con `S`/`N` el grupo vale su
    precio × cantidad − descuento (o la suma de sus líneas si el precio es 0 y
    `LABCON.CONBDPZ`). El subtotal suma las líneas sin grupo y los grupos; los suplidos
    van aparte.
  - La lectura devuelve `lineas` con, además, `codigo` (nº de línea), `total`,
    `mostrar_precio`, `es_computable` y `seccion_*`.
- Al cambiar de cliente o (con precios por tarifa) de tarifa sin enviar líneas, los
  precios de servicios y técnicas se regeneran, salvo que `precios_modificados` sea `T`
  (Veolab pregunta; la API los conserva). Los puntos de muestreo de las líneas se quitan
  al cambiar de cliente.
- **Borrado**: `422` si tiene operaciones, planificaciones, facturas o contratos; borra
  las líneas y envía los documentos a la papelera.
- **Verifactu**: no se borra ningún presupuesto ni se modifica el que tiene factura o
  subsanación (solo archivarlo). Alta, modificación y borrado dejan un registro `V`
  encadenado (`$ESPVER003` / `007` / `009`) con el cliente y el importe (base + impuesto 1).
- Desde Veolab: generar operaciones o planificaciones del presupuesto (desde la API,
  `POST /operaciones` con `presupuesto_*`), facturarlo y exportarlo.

**Contratos** (`/contratos`, `FACCON`; clave `delegacion` + `serie` + `codigo`). Réplica de
`FichaContrato`/`Contratos`:
- Campos: `descripcion` (obligatoria), `observaciones`, `concepto_facturacion`,
  `fecha_inicio`, `fecha_fin`, `fecha_ultima_facturacion`, `fecha_proxima_facturacion`
  (sin hora), `tipo_desglose`, `es_cancelado`, `es_archivado`, `es_predeterminado`,
  `facturar_operaciones` (permitir facturar aparte las operaciones del contrato),
  `importe_facturacion` (`C` importe del contrato · `O` importe de las operaciones),
  `renovacion` + `renovacion_unidad` (`D`/`S`/`M`/`A`), `cliente_*`, `presupuesto_*`, `tarifa_*`.
- **Al crear**: no cancelado ni archivado ni predeterminado, importe del contrato,
  renovación 0 años, desglose de `LABCON.CONCTID`; con cliente, su tarifa.
- **Líneas** (`FACLIC`): como en los presupuestos (`lineas` o `servicios`, mismos tipos y
  reglas), sin punto de muestreo. Las técnicas llevan siempre la marca de acreditación.
  Los grupos de técnicas, gastos y suplidos suman sus líneas (hasta Veolab 2.4.3 la ficha
  de contrato solo sumaba el de suplidos; corregido en 2.4.4).
- `precio` (sin suplidos) sale de la rejilla; sin desglose se puede indicar a mano.
  `precios_modificados` y el regenerado de precios al cambiar de cliente o tarifa, como
  en los presupuestos.
- **Periodicidad de facturación** (`periodicidad*`, `numero_facturacion`): solo lectura,
  se configura en Veolab (como en las planificaciones).
- Un contrato **predeterminado**, al grabarse, quita la marca a los demás del cliente.
- **Borrado**: `422` si tiene operaciones, planificaciones o facturas; borra las líneas y
  envía los documentos a la papelera. Con Verifactu no se borra.
- **Registros `V`**: `$ESPVER017` alta / `018` modificación (Veolab identifica el contrato
  sin la serie) / `016` borrado, con el cliente y el precio.
- Desde Veolab: generar operaciones o planificaciones, facturar y exportar.

**Facturas** (`/facturas`, `FACFAC`; clave `delegacion` + `serie` + `codigo`). Réplica de
`FichaFactura`/`Facturas` (`App\Support\VeolabInvoiceLines` para la rejilla):
- **La API no emite facturas**: definitivas, rectificativas, subsanaciones y envío a la AEAT
  (Verifactu) se hacen en Veolab. La lectura devuelve todas, con `lineas`, `operaciones`
  (`LABOPE.FAC2*`) y `subsanaciones` (`FACSUB`, sin el XML ni la respuesta de la AEAT), y los
  datos de Verifactu como solo lectura (`estado`, `huella`, `identificador_verifactu`...).
- **Borradores** (serie `BOR`, solo si `LABCON.CONBFAB`): alta, modificación y borrado. El
  código lo da el contador de la serie `BOR`; la serie con la que se emitirá va en
  `serie_final`. Al crear: fecha de hoy, estado `B`, sin rectificar, desglose de `CONCTID`.
  Datos del emisor copiados de la delegación (o de la central, `ACCPAR.PARCCDC`) en cada
  grabación. Al elegir cliente se copian sus datos de facturación (NIF, razón social,
  nombre, dirección de facturación, forma de pago, cuenta, descuento, impuestos, notas,
  centros FACe y vencimiento con sus días y día de pago); con Verifactu, persona jurídica,
  residente y `ESP` por defecto. En persona física, la razón social es nombre y apellidos.
- **Líneas** (`FACLIF`), tres formas:
  - `"operaciones": [{"delegacion": "", "serie": "26", "codigo": 143}]` sin líneas: la rejilla
    se genera como en la facturación de Veolab (agrupación `CONCTAR` por servicio u
    operación, técnicas y gastos sueltos, servicios con sus técnicas, gastos agrupados y
    suplidos al final, líneas iguales acumuladas, columnas de fecha/referencia/adicional
    según `CONCMOF`/`CONCMOR`/`CONCMOA`, desglose por cliente y punto según
    `CONBDPC`/`CONBDPP`) y el desglose es el de la primera operación. Sin cliente, se toma
    el de facturación de la primera operación (su principal si factura al principal), y
    también su presupuesto y contrato. Al cambiar las operaciones de un borrador se
    regenera, salvo que sus líneas se hubieran editado (`lineas_modificadas`).
  - `lineas` o `servicios`, como en presupuestos; además, por línea, `fecha`, `adicional`,
    `cliente_*` y el tipo `O` (operación, con `operacion_*`).
  - **Conversión**: `presupuesto_*` o `contrato_*` sin operaciones ni líneas copia las líneas,
    el desglose y el subtotal del documento (del presupuesto también descuento e impuestos;
    del contrato, el concepto) y vincula sus operaciones facturables. Un contrato por importe
    de las operaciones (`CONCFIM = 'O'`) genera la rejilla a partir de ellas.
- **Operaciones**: `422` si alguna ya está en otra factura. Las del borrador quedan
  prefacturadas (`FAC2*`, `OPEBPRE`, `OPEBFAB`); las que se quitan vuelven a estar
  disponibles (y se desarchivan si hay modo de archivo `CONCARC`). Los borradores no
  archivan operaciones.
- **Importes** como en presupuestos (con desglose `O`, el subtotal es la suma de los precios de
  las operaciones). `pendiente` sigue al total mientras no se cambie ni esté cobrada;
  marcarla cobrada deja el pendiente a 0 y la fecha de pago de hoy.
- **Contrato**: un borrador nuevo con contrato apunta su última facturación y suma una al
  número de facturación; si el contrato es periódico → `422` (la próxima facturación depende
  de la periodicidad, que la API no calcula).
- **Facturas emitidas**: solo `es_enviada`, `es_cobrada`, `es_contabilizada`, `pendiente`,
  `fecha_vencimiento`, `fecha_pago` y `notas`; cualquier otro campo → `422`. No se borran.
- **Borrado** (solo borradores): líneas, operaciones desarchivadas y libres, documentos a la
  papelera.
- **Registros `V`**: `$ESPVER004` borrador nuevo, `011` conversión de presupuesto y `019` de
  contrato (con su código como valor anterior), `008` modificación de borrador, con el
  cliente y el importe (base + impuesto 1). Como en Veolab, borrar un borrador no deja
  registro `V`.
- Diferencias con la facturación automática de Veolab (fallos suyos): total = base +
  impuesto 1 − impuesto 2 + suplidos (Veolab suma el impuesto 2), las líneas se guardan
  aunque no haya técnicas y los gastos agrupados con servicio no se repiten.

**Relaciones N:N** (`RelationController`): la clave son las dos entidades, cada una con
`{grupo}_delegacion` + `{grupo}_codigo`. `GET` con una entidad lista sus relaciones
(p. ej. `GET /servicios/tecnicas?servicio_delegacion=&servicio_codigo=S01`), `POST` crea
(las dos entidades deben existir; repetida → `422`), `PUT` cambia los datos de la
relación y `DELETE` la quita (clave completa en query string).

| Ruta | Tabla | Grupos | Datos |
|---|---|---|---|
| `/servicios/tecnicas` | `LABSYT` | `servicio`, `tecnica` | `posicion` |
| `/servicios/gastos` | `LABSYE` | `servicio`, `gasto` | |
| `/servicios/precios-cliente` | `LABSYC` | `servicio`, `cliente` | `precio`, `descuento`, `referencia` |
| `/servicios/precios-tarifa` | `LABSYF` | `servicio`, `tarifa` | `precio`, `descuento` |
| `/servicios/autodefinibles` | `LABAYS` | `autodefinible`, `servicio` | |
| `/parametros/matrices` | `LABTYM` | `tecnica`, `matriz` | |
| `/parametros/normativas` | `LABTYN` | `tecnica`, `normativa` | `valor`, `rango` |
| `/parametros/precios-cliente` | `LABTYC` | `tecnica`, `cliente` | `precio`, `descuento`, `referencia` |
| `/parametros/precios-tarifa` | `LABTYF` | `tecnica`, `tarifa` | `precio`, `descuento` |
| `/parametros/empleados` | `LABTYE` | `tecnica`, `empleado` | `posicion` |
| `/parametros/equipos` | `LABTYQ` | `tecnica`, `producto` | `formato_importacion`, `fichero`, `columna` |
| `/parametros/consumibles` | `LABTYP` | `tecnica`, `producto` | `cantidad` |
| `/empleados/clientes` | `GRHCLI` | `empleado`, `cliente` | |
| `/empleados/cargos` | `GRHEYC` | `empleado`, `cargo` | `posicion` |
| `/cursos/alumnos` | `GRHALU` | `curso`, `empleado` | `evaluacion`, `fecha_evaluacion`, `es_evidencia_adjunta`, `es_no_finalizado`, `comentarios`, `evaluador_*` |
| `/cursos/profesores` | `GRHPRO` | `curso`, `empleado` | |
| `/tipos-operacion/matrices` | `LABOYM` | `tipo_operacion`, `matriz` | |
| `/proveedores/productos` | `ALMPYP` | `proveedor`, `producto` | `referencia`, `precio` |
| `/empleados/autorizaciones` | `GRHAUT` | `empleado`, `tecnica` | Desde Veolab 3: `estado`, fechas, `autorizado_por_*`, `resultado_supervision`; auditoría propia (§11 duodecies) |

- `posicion` (orden en la ficha): sin indicarla, al final. Las técnicas de un servicio
  empiezan en 2 (la ficha reserva la 1 para la fila del servicio).
- Descuentos de precios: como en facturación, con el separador del laboratorio.
- Auditoría como Veolab, que graba estas rejillas desde la ficha de una de las entidades:
  suceso de fila (nivel 2) o de campo con la rejilla (nivel 3) sobre esa ficha (servicio,
  técnica, normativa, empleado, curso, matriz o proveedor). Los precios, como la ventana
  de precios: suceso `M` en la propia tabla (fila = servicio/técnica, campo =
  cliente/tarifa, valores "precio descuento"). Autodefinibles: campo `LABAUTSER2COD` de
  `LABAUT` con el servicio.

**Estructura de resultados de una técnica** (`/parametros/columnas`, `LABCOT` + `LABCYR`;
clave `tecnica_delegacion` + `tecnica_codigo` + `columna`): la plantilla con la que se crean
las columnas de resultado (`LABCOR`) de cada operación, como la rejilla de formato de
`FichaTecnica`.
- Campos: `titulo`, `titulo2`, `titulo3`, `tipo` (`N` · `T` · `F` · `H` · `C`), `formato`,
  `seleccionables`, `predeterminado`, `formula`, `es_activa`, `es_editable`,
  `es_visible_informe`, `es_visible_resultados`, `es_control_exactitud`,
  `es_control_precision`; en la lectura también `letra`.
- `columna` es la posición (A = 1). `POST` añade al final (por defecto: texto, activa,
  editable y visible, sin control); solo se borra la última columna (`422`; las demás se
  desactivan). La API no inserta ni mueve columnas, así que no reescribe las letras de las
  fórmulas (eso lo hace la ficha de Veolab al insertar).
- `rangos`: `[{"rango_delegacion": "", "rango_codigo": 2, "intervalo": "[0;7,5]",
  "marca_delegacion": "", "marca_codigo": 3}]` (rangos de la delegación de la técnica o
  generales; marca de esa delegación o general). En `POST`/`PUT` sustituye los intervalos de
  la columna; la lectura devuelve los rangos con intervalo o marca. Veolab carga los
  intervalos por posición, así que se guarda una fila de `LABCYR` por cada rango y columna
  (vacía si no se usa), como al grabar la ficha.
- Los cambios no tocan las operaciones ya creadas, salvo fórmula, formato, tipo,
  seleccionables y predeterminado, que los resultados leen de aquí.
- Auditoría como la ficha: suceso de fila de la técnica (nivel 2) o de campo `LABCOT`
  (nivel 3).

**Subtablas** (`ChildController`): clave = la entidad padre (`{grupo}_delegacion` +
`{grupo}_codigo`) + `codigo` de línea. Sin `codigo`, el siguiente dentro del padre (como
las rejillas de Veolab, no el contador `ACCCLT`). Auditoría sobre la ficha del padre, como
las relaciones.

| Ruta | Tabla | Padre | Datos |
|---|---|---|---|
| `/cargos/tareas` | `GRHTAR` | `cargo` | `descripcion` |
| `/empleados/ausencias` | `GRHAUS` | `empleado` | `fecha_inicio`, `fecha_fin`, `descripcion` |
| `/empleados/curriculum` | `GRHCUR` | `empleado` | `fecha_inicio`, `fecha_fin`, `cargo_*`, `departamento_*` |
| `/empleados/formacion` | `GRHFOR` | `empleado` | `descripcion`, `observaciones`, fechas, `es_evidencia_adjunta`, `es_plan_empresa` |
| `/proveedores/evaluaciones` | `SINEVA` | `proveedor` | Desde Veolab 3: `fecha`, `resultado`, `descripcion`, `fecha_proxima`, `evaluador_*`, `tipo_evaluacion_*`; código del contador `ACCCLT` (§11 duodecies) |
| `/clientes/puntos-muestreo` | `LABPUM` | `cliente` | `descripcion`, `referencia`, `es_baja`, `es_categoria`, `categoria_codigo`, ubicación, campos `sinac_*`… |

- Puntos de muestreo: árbol de un nivel; un punto cuelga de la raíz (`categoria_codigo`
  vacío) o de una categoría del mismo cliente. Una categoría no se da de baja ni cuelga de
  otra. No se borra un punto usado en operaciones, planificaciones o líneas de factura
  (`422`, se da de baja); borrar una categoría borra sus puntos.

**Maestros de configuración** (delegación + código, código automático):

| Ruta | Tabla | Notas |
|---|---|---|
| `/marcas` | `LABMAR` | `tipo` al crear: `normal`, `predeterminada` (código -1) o `no_evaluable` (-2), una de cada por delegación. Usada en intervalos o resultados → no se borra (`422`, se da de baja; Veolab lo permite preguntando); al borrar, los dictámenes que la usaban quedan sin marca. |
| `/rangos` | `LABRAN` | Al borrar se borran sus intervalos (`LABCYR`). |
| `/dictamenes` | `LABDIC` | Con `marca_*`. Usado en operaciones → `422`. |
| `/opiniones` | `LABOEI` | `automatica`: C con normativa, S sin normativa, M por marca (con `marca_*`), I sin marcas. |
| `/descripciones` | `LABDES` | `texto`. |
| `/recolectores` | `LABREC` | `nombre`. |
| `/tipos-residuo` | `LABTDR` | Descripción única en la delegación y las generales; usado en residuos → `422`. |
| `/festivos` | `AGEFES` | `fecha` obligatoria. |
| `/autodefinibles` | `LABAUT` | Ver abajo. |
| `/envases`, `/lugares`, `/temperaturas` | `LABENV`, `LABLUG`, `LABTEM` | Desde Veolab 3 (antes `422`): valores predefinidos de envase, lugar y temperatura de las operaciones y planificaciones, como `/recolectores`. |

- **Autodefinibles** (definición): `ambito` O operaciones / L lotes (obligatorio, no se
  cambia), `tipo_dato` (N, D, V, T, E, S, F, I), `formato` (opciones de S, tabla de F),
  `orden` (al final si no se indica), categoría, editable desde resultados / con la
  operación validada, baja y códigos de exportación. Nombre: empieza por letra, sin los
  símbolos que rechaza Veolab y no repetido (las operaciones los identifican por nombre).
  Al crear se añade a los campos de operación (`PERCCAO`) de los perfiles de su
  delegación; al borrar se quita, y se borran sus servicios y valores. Con valor en alguna
  operación o lote no se borra (`422`). Auditoría con el nombre como fila.
  Desde Veolab 3, `predeterminado` (`AUTCPRE`): valor fijo o fórmula (`=...`, se valida la
  sintaxis → `422`; los ficheros no lo admiten). La pantalla «Campos» guarda además la
  configuración de los campos fijos de recolección como filas con `tipo_dato` `X`
  (`formato` = columna de `LABOPE`, `es_baja` = oculto, `predeterminado`), que salen en el
  listado y no se crean por la API.
- Fuera de la API por ahora: estadísticas, plantillas de exportación y tablas de
  sistema (idiomas, licencias, semáforos, registros internos).

**Campos obligatorios para recibir** (`LABCON.CONCCAO`, `CamposObligatoriosCubiertos`):
al pasar a recibida (estado 1) o guardar en un estado posterior, los campos de la
lista (columnas de `LABOPE` y autodefinibles `AU_<del>_<cod>.OYACVAL`) deben tener
valor; si no → `422` con la lista. Claves foráneas y nº de envases a 0 cuentan como
vacíos (Veolab comprueba el nº de envases al revés; no se replica).

**Licencia / Verifactu:** la API lee el tipo de licencia de `ACCPAR.PARCLBD`
(`App\Support\VeolabLicense`). Los patrones de cifrado son secretos: solo en el `.env`
del servidor de Spuch (`VEOLAB_ENC_*`), nunca en el repositorio (es público y los
clientes pueden autoalojar la API). **Sin patrones o sin licencia legible se aplican
siempre las restricciones de Verifactu.** Prueba: `php artisan veolab:licencia <bd>`.

Restricciones (hechas en presupuestos, contratos y facturas):

| | Sin edición * | Edición Empresarial * |
|---|---|---|
| Borradores de factura (crear/modificar/borrar; si `LABCON.CONBFAB`) | ✓ | ✓ (registro `V` con hash) |
| Emitir facturas definitivas | No (se emiten desde Veolab) | Nunca desde la API |
| Borrar contratos / presupuestos | ✓ | ✗ |
| Modificar presupuesto con factura (`FACFAC`/`FACSUB`) | ✓ | ✗ |

- NIF de cliente inválido: Veolab solo avisa, no bloquea; la API tampoco.
- Registros `V` (`VeolabAudit::verifactu`): se escriben con "auditar facturación"
  (`ACCPAR.PARBAUF`) o licencia Verifactu (o no legible), sea cual sea el nivel de
  auditoría. Cadena hash en `ACCHAS 'AUD'` (bloqueada hasta el commit) = SHA-256 de
  `tipo|tabla|fila|campo|mod|ant|hashAnterior` con los valores ya recortados a su
  columna, en UTF-8 y hexadecimal en minúsculas (`HashLibrary.HashFunctions`).

## 13. Decisiones tomadas y pendientes

Tomadas:

- **Operadores de filtro:** `=`, lista con coma (`in`), `gte`, `lte`, `gt`, `lt`, `ne`,
  `like` y `null` (§6).
- **Tope de `limit`:** 100 (por defecto 25).
- **Controladores a mano**, uno por tabla, con el motor común (`BaseController`,
  `RelationController`, `ChildController`): cada tabla de Veolab tiene reglas propias.
- **Paginación por cursor:** pospuesta (§8).
- **Limpieza de avisos antiguos (`ACCAVI`):** la API no tiene tareas programadas; si hace
  falta, será una tarea del servidor o un filtro de la aplicación cliente.
- **Escritura de la configuración:** aplazada hasta que la necesite la app web.

Pendientes:

- **Sincronización de la app web:** al no existir columna de última modificación en casi
  ninguna tabla, el sync incremental por fecha no es fiable; asumir relectura filtrada, o
  valorar (con cuidado, es esquema compartido con Veolab escritorio) añadir
  columna/trigger de modificación.
- **Avisos que Veolab crea al grabar** (`ACCNOT` al analista, de fecha de compromiso, de
  marcas en resultados, de firmas): la API aún no los genera. Tampoco la notificación de
  seguimiento de presupuestos (`P`, la crea Veolab) ni las de iGEO (`G`, el servicio de iGEO).
- **Veolab 3 pendiente de la sesión de usuario de Veolab Web:** licencias por usuario
  concurrente (`ACCCNX`: conexiones W/M al iniciar sesión, latido y recuento del número
  de licencias de `PARCLBD`), latido `ACCUSU.USUTACT`, privilegio `DOC_EXP` especial 2
  (solo versión principal) en `/documentos/versiones` y `/documentos/contenido?version=`
  (sin `version`, el control de documentos ya entrega la aprobada a quien solo consulta si se
  indica el usuario),
  y tomar el remitente de los mensajes de la sesión en lugar de la petición.
