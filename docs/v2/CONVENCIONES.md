# Veolab API REST v2 — Convenciones de diseño

> Documento de referencia para la construcción de la v2. Recoge las decisiones
> tomadas en la fase de análisis. Implementado en la rama `v2` (tablas sencillas).

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
11. [Gestión documental](#11-gestión-documental)
12. [Fixes de corrección a arrastrar de la v1](#12-fixes-de-corrección-a-arrastrar-de-la-v1)
13. [Decisiones pendientes de confirmar](#13-decisiones-pendientes-de-confirmar)

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
- Las rutas v1 (`/api/...` sin versión) se dejan como *deprecated* durante una
  ventana corta y luego se retiran. Cortesía opcional: avisar a los dueños de los
  tokens externos.

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

- `POST /api/v2/login` — cuerpo `{ "name": "<bd_laboratorio>", "password": "..." }` → `{ "token": "..." }`
- `POST /api/v2/logout` — revoca el token actual (requiere Bearer)
- `POST /api/v2/refresh` — revoca el actual y emite uno nuevo (requiere Bearer)
- El resto de endpoints requieren cabecera `Authorization: Bearer <token>`.
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
- **clave completa presente →** `data` con 1 elemento (o `data` vacío si no existe; **nunca `404`**),
- **clave parcial o ausente →** listado filtrado.

Direccionar es, por tanto, un caso particular de filtrar: mismo endpoint, misma forma
de respuesta. No hay endpoint "show" aparte ni segunda semántica.

```
GET /api/v2/operaciones?delegacion=DEL001&serie=25            → listado de la serie 25
GET /api/v2/operaciones?delegacion=DEL001&serie=25&codigo=1   → una operación (o 404)
GET /api/v2/clientes?delegacion=DEL001&codigo=5               → un cliente (tabla de 2 claves)
GET /api/v2/operaciones-resultados?ope_delegacion=DEL001&ope_serie=25&ope_codigo=1
                                   &tec_delegacion=DEL001&tec_codigo=7   → un resultado (5 claves)
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
| Nulo / no nulo | `?fecha_baja[null]=true` | `IS NULL` |

- **Booleanos:** Veolab usa `'T'`/`'F'` (no true/false). Los filtros aceptan `T`/`F`
  (`?es_urgente=T`), coherente con la validación `in:T,F`.
- **Fechas:** ISO `yyyy-mm-dd`, rangos inclusivos. Cuidado con columnas datetime
  (`OPETREC`): un `[lte]` sin hora puede excluir ese día — fijar semántica.
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

Dos modos.

### 8.1 Offset (por defecto, para UI y uso normal)

- Parámetros `page` (1‑based) y `limit`.
- **`ORDER BY` obligatorio por la clave compuesta** (corrige el bug de la v1, que
  paginaba sin orden → páginas inconsistentes).
- `limit`: **casteo a entero**, rechazo de negativos y **tope máximo** (p. ej.
  100–200). Con el envoltorio de respuesta, el recorte es seguro: `meta` revela que
  hay más páginas, así que el cliente nunca se queda a ciegas.
- Con clave múltiple no hay complicación: solo alarga el `ORDER BY`.

```
GET /api/v2/operaciones?limit=200&page=2
```

### 8.2 Keyset / cursor (para lectura masiva / exportación de tablas grandes)

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
  "meta": { "total": 342, "page": 2, "per_page": 50, "last_page": 7 }
}
```
Con keyset, `meta` lleva `next_cursor` (y `per_page`) en lugar de `total/last_page`.

- **Registro único:** no hay forma aparte (Opción X); se pide con la clave completa y
  llega como `{data, meta}` con `data` de 1 elemento. `data` vacío = no existe.
- **Creación (`POST`):** `{ "message": "...", "data": { <clave del nuevo registro> } }`
  (`201`). Si no se envía código, la API genera el siguiente y lo devuelve.
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
| `400` | Petición mal formada (JSON inválido, parámetro imposible) |
| `401` | Token ausente o inválido |
| `404` | Recurso no encontrado |
| `422` | **Validación** o reglas de negocio (relación inexistente, código duplicado…) |
| `500` | Error inesperado del servidor |

- **No se usa `403`** (no hay capa de permisos en la API).
- **Nunca** se filtran mensajes internos de excepción al cliente. Errores de
  validación en formato estándar Laravel `{ "message": ..., "errors": { campo: [...] } }`;
  el resto, `{ "message": ... }`. El detalle interno va a `Log`, no a la respuesta.
  (Corrige el `{error, detalle}` de la v1, que devolvía 500 en validación y filtraba
  `$e->getMessage()`.)

## 11. Gestión documental

Endpoints nuevos (no rompen nada). El filtrado por privilegios de usuario final lo
hace la app consumidora, no la API.

Modelo de datos:

| Tabla | Rol |
|---|---|
| `DOCDIR` | Carpetas (`DIRCTAB` = tabla asociada, `DIRCCOM` = modo de compartición, jerarquía por `DIR2*`) |
| `DOCFAT` | Documento (metadatos): nombre `FATCNOM`, extensión `FATCTIP`, comprimido `FATBZIP`, versión actual `VER2COD`, + FKs a entidades (`CLI2COD`, `OPE2*`…) |
| `DOCVER` | Versiones |
| `DOCBLO` | Bloques binarios (`BLO1COD` orden, `BLONTAM` tamaño, `BLOLCON` contenido) |

Recuperación del binario: leer `DOCFAT` → resolver versión (`VER2COD` o la pedida) →
concatenar bloques `DOCBLO` **ordenados por `BLO1COD`** → si `FATBZIP='T'`,
descomprimir (el binario es un ZIP que contiene un fichero llamado
`FATCNOC.FATCTIP`).

Endpoints previstos:
```
GET /api/v2/documentos?tabla=SINCLI&delegacion=DEL001&codigo=5   → documentos de una entidad
GET /api/v2/documentos/{delegacion}/{fat}                        → metadatos
GET /api/v2/documentos/{delegacion}/{fat}/versiones              → histórico
GET /api/v2/documentos/{delegacion}/{fat}/contenido[?version=]   → descarga (streaming)
```

Puntos técnicos: usar `StreamedResponse` bloque a bloque (no cargar en memoria);
**verificar el formato ZIP** que produce el módulo `ZIP.bas` de Veolab antes de
implementar la descompresión (mayor riesgo técnico); `Content-Type` según `FATCTIP`,
`Content-Disposition` con `FATCNOM`.

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
  sin ella, `del-ser-cod`). Tablas con otra forma de clave sobreescriben `auditRow()`.
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

**Planificaciones** (`/planificaciones`, `LABPLO`; clave `delegacion` + `codigo`, sin
serie). "Preoperaciones" con los mismos campos que la operación (mismos nombres de
parámetro), `serie_operaciones` (serie de las operaciones que genera),
`numero_operaciones` (tanda) y `calcular_compromiso`. Réplica de
`FichaPlanificacion`/`Planificaciones`:
- **Servicios al crear** (`"servicios"`): `LABPYS`, `LABPYT` y `LABPYG` con los precios
  calculados como en la operación, salvo que las técnicas salen siempre de `LABSYT`
  (no del presupuesto); sin consumos, columnas de resultado ni referencias. Precio,
  tipo de operación, matriz y envases se heredan como en la operación.
- **Autodefinibles**: mismas definiciones y reglas que en la operación; valores
  propios en `LABPYA` (se auditan solo al modificar, como Veolab).
- **Fechas** (`LABFEP`): la respuesta incluye `fechas: [{codigo, fecha, completada}]`
  (solo las activas). La **periodicidad es de solo lectura** (se configura en
  Veolab): la API admite planificaciones sin fecha (`fecha_inicio: null`,
  `PLONFRE = -1`) o de fecha única (`fecha_inicio`, `PLONFRE = 0`); cambiar la fecha
  de una periódica → `422`. Al cambiar la fecha, las no completadas se desactivan
  (`FEPTINI = NULL`, se conservan por el vínculo de códigos de barras) y se crea la
  nueva salvo que ya haya una completada en esa fecha.
- `PUT /planificaciones/fechas?delegacion=&codigo=&fecha=` con `{"completada": "T"|"F"}`
  marca una fecha como generada o pendiente (suceso `M` en la auditoría).
- Aviso en la agenda (`es_aviso`, `aviso_*`): solo lectura.
- **Borrado**: desvincula sus operaciones, documentos a la papelera, borra
  autodefinibles, fechas, servicios/técnicas/gastos (Veolab deja estos tres
  huérfanos) y los eventos de agenda.

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
  servicios, y como en Veolab solo se auditan al modificar.
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
  (máx. 255): números con **coma decimal** (`"7,2"`; un número JSON se convierte), fechas
  `dd/mm/aaaa`, horas `hh:mm`, casillas `T`/`F` (se guardan `Sí`/`No`). `null` vacía.
- **Marcas por rangos** (`LABCYR`/`LABRAN`, y los de normativa con el rango `LABTYN` de la
  normativa del servicio): al cambiar el valor de una celda con rangos se recalcula su
  marca (la primera concluyente; "no evaluable" `-2` si no la hay) y el valor puede
  sustituirse por el límite superado (`RANBSUV`/`RANBSUX`) o por el texto de la marca
  (`MARCSUS`). `marcas` aplica marcas a mano (`null` la quita).
- **Analista**: las técnicas con resultado sin analista toman el empleado del usuario
  indicado (`ACCUSU.EMP2*`), como la ficha con el usuario en sesión.
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
- **Pendiente**: el recálculo de **fórmulas** (`COTCFOM`; de momento la celda guarda lo que
  llega) y los formatos de columna (`COTCFOR`). Con el módulo de **cartas de control**, los
  resultados de control de una operación de control (`OPEBCON`) se graban en Veolab (`422`).
  Tampoco: notificaciones de marcas, importación de equipos.

**Presupuestos** (`/presupuestos`, `FACPRE`; clave `delegacion` + `serie` + `codigo`). Réplica
de `FichaPresupuesto`/`Presupuestos` (`App\Support\VeolabBillingLines`):
- Campos: `descripcion`, `informacion_adicional`, `orden_compra`, `solicitado_por`,
  `observaciones`, `lugar`, `horario`, `recogida`, `facturacion`, `notas`, `fecha`,
  `fecha_vencimiento`, `fecha_entrega`, `fecha_aceptacion` (sin hora), `estado`
  (`P` pendiente · `E` enviado · `A` aceptado · `R` rechazado · `V` validado ·
  `C` cancelado), `es_acreditado`, `es_archivado`, `tipo_desglose` (`S` servicio ·
  `T` técnica · `N` sin desglose), `descuento`, `tipo_impuesto_1/2`, `valor_impuesto_1/2`,
  `cliente_*`, `empleado_comercial_*`, `tarifa_*`.
- **Calculados** (solo lectura): `subtotal`, `base_imponible`, `importe_impuesto_1/2`,
  `suplidos`, `total` y `precios_modificados`. Base = subtotal − descuento; total =
  base + impuesto 1 − impuesto 2 (retención) + suplidos. Descuentos e impuestos son texto:
  porcentaje (`"21%"`) o importe (`"15"`). **Se guardan tal cual llegan**, como en
  Veolab, que los lee con el separador decimal de cada equipo: quien llama debe
  escribir los decimales como los equipos del laboratorio (con configuración regional
  española, `"10,5%"`; un `"10.5%"` Veolab lo leería allí como 105 %). La API entiende
  coma y punto al calcular. Sin desglose (`N`) el `subtotal` se puede indicar a mano.
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

## 13. Decisiones pendientes de confirmar

- **Set exacto de operadores de filtro** a soportar en la primera versión (mínimo
  propuesto: `=`, `in`, `gte`/`lte`, `like`; ampliar `ne`/`null` si hacen falta).
- **Valor del tope de `limit`** (100 vs 200 vs otro).
- **¿Generar controladores/rutas desde `modelo.sql`** (aprovechando que la PK es
  deducible) o mantenerlos a mano?
- **Estrategia de sincronización de la app web:** al no existir columna de última
  modificación en casi ninguna tabla, el sync incremental por fecha no es fiable;
  asumir relectura filtrada, o valorar (con cuidado, es esquema compartido con
  Veolab escritorio) añadir columna/trigger de modificación.
