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
