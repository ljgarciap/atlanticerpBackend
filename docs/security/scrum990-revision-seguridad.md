# SCRUM-990 — Revisión de seguridad del backend (Cybersecurity)

Fecha: 2026-09-30. Alcance: commits 51fc22c4, 9b63f5ed, d6cbd546, 15534f8b, 113de3fe, 4cd8c785 (solo lectura; no se ejecutó nada contra ningún entorno ni se corrió la suite).
Leído: código de los 6 commits, `CatalogProduct::applySearch`, loader viejo (`git show 9b63f5ed^`), migraciones de FK de las tablas de historial, `config/database.php`, `routes/console.php`,
`AppServiceProvider` (throttles), `docs/architecture/scrum990-aislar-lafayette-diseno.md`, `docs/specs/scrum990-aislar-lafayette.md`, `THREAT-MODEL-lafayette-lighting.md`.
Frontend: solo existe el tipo `url_imagen` en `atlanticerp-frontend/src/types/bodega.ts`; la tabla de Zona Libre aún no renderiza nada (los requisitos van como condición del push del frontend).

Convención de bloqueo: "Push dev" = bloquea publicar a dev. "Ejecución prod" = bloquea correr el comando/despliegue en test/prod.

## Veredicto

- Sin hallazgos CRÍTICOS. Ninguno bloquea el push a dev de los commits B1-B3 (ya publicados).
- 3 hallazgos ALTOS, todos del comando destructivo `lafayette:aislar-catalogo`: bloquean su ejecución en test/prod (en dev, con datos de prueba y respaldo, es tolerable tras el dry-run).
- Endpoint, trigger/guards y pipeline: sólidos en inyección SQL y autorización; huecos de resiliencia, throttle y datos hostiles (MEDIO).

## Parte 1 — Endpoint GET /bodega/zona-libre/productos

Verificado OK:
- Autorización: `permission:bodega.read` (routes/bodega.php:183), mismo patrón que las rutas hermanas; test 401/403 (ZonaLibreCatalogoContractTest.php:99). Sin IDs de objeto (el `id` expuesto es `lafayette_id`), tabla por tenant vía `Tenant::current()` => sin IDOR.
- Lista blanca: `sort` por `Rule::in(array_keys(SORTS))`, `dir` por `Rule::in(['asc','desc'])`, y el servicio los revalida (ZonaLibreCatalogoService.php:79-82). El único SQL crudo de orden concatena valores de esa lista blanca: sin inyección.
- Búsqueda: `search` min 2 / max 100 (Controller:33); términos siempre como bindings; comodines escapados con `addcslashes($word,'%_\\')` (CatalogProduct::applySearch:325) y `relevanceRank` (Service:~185); respaldo fuzzy acotado (5 palabras, 200 candidatos, palabras 4..30). `categoria` = igualdad con binding.
- Escritura: único método GET en `/zona-libre/productos`; servicio sin métodos de escritura; test de rutas (Contract:291). `Warehouse::$fillable` incluye `solo_consulta` pero no hay ninguna ruta que haga `Warehouse::update/create` con input.

### H1 — MEDIO — Sin rate limit y `page` sin tope (Controller:40, routes/bodega.php:183)
Escenario: un usuario con `bodega.read` (o un token robado) pagina 258 peticiones x 100 filas y copia el catálogo y los precios de un tercero, o dispara búsquedas con 50 palabras de 1-2 caracteres (LIKE sin trigram, seq scan sobre ~26K filas) en ráfaga. Es justo el patrón "exfiltración gradual / reconocimiento automatizado" del threat model de Epic 11. Además `page=9223372036854775807` desborda `(page-1)*per_page` y produce 500.
Mitigación: `RateLimiter::for('zona-libre-lectura')` por usuario (mismo patrón que `biblioteca-lectura`, AppServiceProvider:158; ~60/min) y `throttle:zona-libre-lectura` en la ruta; `page` => `max:100000`; opcional: limitar a 5 palabras en `search`.
Bloquea: Push dev no. Ejecución prod: recomendado antes de prod.

### H2 — MEDIO — `url_imagen`, `descripcion`, `marca` de un tercero salen sin saneo (Service:110, Mapper:36-40)
El backend devuelve `url_imagen` tal cual (cualquier esquema: `javascript:`, `data:`, `http://`, tracking) y los textos sin saneo; el almacenamiento guarda "lo que viene" a propósito. En JSON no hay XSS; el riesgo nace en el frontend (aún no existe esa vista): un `<img src>` a un origen no controlado filtra IP/Referer de cada usuario interno al host de la imagen (tracking), rompe por mixed content si es `http://`, y un `javascript:`/`data:` en un `<a href>` o similar sería XSS. No se halló CSP en `infra/` (verificar en nginx).
Mitigación: (backend) exponer `url_imagen` solo si `parse_url` da esquema `https` y longitud <= 2048, si no `null` (en `formatRow`, sin alterar lo almacenado); (frontend, condición de su push) render como texto React (nunca `dangerouslySetInnerHTML`), `<img referrerPolicy="no-referrer" loading="lazy" crossOrigin="anonymous">`, nunca enlazar `url_imagen` en `href`; (infra) CSP `img-src 'self' https://<host-de-imagenes-de-Lafayette>`. Test con `descripcion = '<img src=x onerror=alert(1)>'` y `url_imagen = 'javascript:alert(1)'`.
Bloquea: backend no; sí el push del frontend de Zona Libre.

### H3 — BAJO — `precio_5` (precio de proveedor) visible a todo `bodega.read` (Service:112)
Decisión de negocio ya tomada por Luis (spec: "precio le sirven"), pero `bodega.read` incluye roles operativos (bodega/asistentes). Confirmar con Luis que el precio de lista de Lafayette no es dato restringido; si lo es, gatear el campo con un permiso fino. Informativo.

## Parte 2 — Pipeline hacia la MariaDB de Lafayette

Verificado OK:
- Credenciales solo por env (`config/database.php:195-196`, `.env.example` vacío); sin secretos en código.
- TLS: `PDO::MYSQL_ATTR_SSL_CA => storage/certs/lafayette-ca.pem` + `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true` (database.php:201-204). Ojo: el archivo fijado es la CA raíz `lafayette-ca.pem` (versionada, es pública), NO `lafayette-server-cert.pem` como dice el `.env.example:245` (documentación obsoleta, BAJO). La verificación no está desactivada.
- Lafayette no puede forzar escrituras fuera de `lafayette_productos`: el extractor emite un único SELECT constante (ProductoExtractor::query), y toda fila viaja como binding en el upsert (Loader:114-148); `markOutOfSource` pasa los ids como UN parámetro `?::varchar[]` con escape de `\` y `"` (Loader:151). Sin inyección SQL ni cambio de tabla posibles desde datos de la fuente.
- Logs: el comando no loguea filas ni secretos en el camino feliz; el evento de auditoría lleva solo contadores.

### P1 — MEDIO — Una sola fila hostil o malformada aborta TODA la sincronización, indefinidamente (Mapper:36-40; Loader:52; Command:99)
Sin validación por fila: `lafayette_id` varchar(50), `referencia` varchar(100), `marca/categoria` varchar(255) => un valor más largo hace fallar el `INSERT` de un lote de 1000 y, como todo va en UNA transacción, revierte la corrida entera; un `\0` en un texto (invalid byte sequence), un `disponible` > 2^31, un `precio_5` fuera de numeric(15,4)/INF, o un `cache_updated_at` que `Carbon::parse` no entienda lanzan lo mismo. Resultado: el espejo queda congelado en el último estado bueno (stock "disponible" desactualizado que usuarios usan para decidir), corrida `failed` cada 12 h en prod, sin cuarentena. También: `descripcion` y `url_imagen` son `text` sin tope (una fila de varios MB infla `search_text` y el índice GIN trigram). Números negativos: solo `disponible` se acota (`max(0,...)`); un `precio_5` negativo se almacena.
Escenario: proveedor comprometido o simplemente un error de carga en su sistema => denegación persistente del sincronizador, o precios/stock manipulados con visibilidad en pantalla.
Mitigación: en el mapper, por fila: truncar/rechazar según los largos de columna, quitar caracteres de control (`/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/`), `precio5` null si no es finito o < 0 o > 1e10, `disponible` acotado a [0, 2^31-1], fecha inválida => null; las filas rechazadas se cuentan en `errores` (sin abortar) y la corrida queda `partial`; opcional: alarma si `errores/extraidos` > 1%. Test con una fila de cada tipo.
Bloquea: Push dev no. Prod: recomendado (el sync de prod corre 2x/día).

### P2 — MEDIO — La guarda de "fuera de fuente" permite ocultar hasta el 50% del catálogo por corrida (config/lafayette.php, Loader:66)
`guarda_extraccion_min_ratio = 0.5`: una vista recortada al 51% marca como `fuera_de_fuente_at` casi la mitad de los productos y desaparecen de Zona Libre (se restauran solos en la siguiente corrida completa). Impacto bajo (reversible, no destruye), pero 0.5 es un umbral duro de negocio/seguridad: la regla del proyecto pide que sea parametrizable sin deploy; hoy es config de código. Mitigación: subir a 0.9 y/o alertar al superadmin cuando `fuera_de_fuente` > 5% en una corrida. Bloquea: no.

### P3 — BAJO — Mensajes de excepción con SQL y bindings (Command:86,91,100)
`$e->getMessage()` de un `QueryException` del upsert incluye el SQL con los 11.000 bindings (filas completas de un tercero) y se imprime a consola/`docker logs`; el de conexión puede incluir host/usuario. No hay secretos (la contraseña nunca va en el mensaje) pero es ruido y datos ajenos en logs. Mitigación: loguear `get_class($e)` + `$e->getCode()` y un `Str::limit($e->getMessage(), 300)`. Bloquea: no.

### P4 — BAJO — Defensa en profundidad de la cuenta externa
El control "solo lectura" depende de la palabra del proveedor (ya registrado como E1 en THREAT-MODEL-lafayette-lighting.md). Añadir a la conexión `lafayette_lighting` `PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY'` hace que el cliente rechace cualquier escritura aunque la cuenta tuviera privilegios de más. Bloquea: no.

### P5 — BAJO — Scheduler (routes/console.php:105-121, sin cambios en estos commits)
Sin dependencias nuevas (composer.json/lock/package.json sin diff en el rango). Agendado correcto por entorno con `withoutOverlapping()`. El comando destructivo NO está agendado (bien). Nota: nada impide que el sync (prod 12:00/22:00) corra durante `--apply`; la verificación `lafayette_productos cambió durante la limpieza` lo convertiría en un ROLLBACK falso (fail-safe, solo molesto). Mitigación: el comando toma el mismo lock de caché o el runbook pausa el agendado en esa ventana.

## Parte 3 — Trigger y guards de bodega solo_consulta

Verificado OK: el trigger `BEFORE INSERT OR UPDATE OF warehouse_id, quantity` (migración 2026_10_01_100100) cubre INSERT/UPSERT (`ON CONFLICT` dispara el BEFORE INSERT), `Model::query()->update()`, query builder, SQL crudo, `COPY`, seeders e importaciones, porque vive en la BD. Los DELETE quedan permitidos a propósito (la limpieza los necesita). Las validaciones `Warehouse::operativaRule()` (closure; evita el problema de `Rule::exists` con schema calificado) cierran los 422 en recepción, devolución, conteo, ajuste, reubicación y alta con stock inicial. `solo_consulta` no se puede cambiar por ninguna ruta de la API (no hay `Warehouse::update/create` con input). Uso para denegar servicio legítimo: solo si alguien con acceso a BD pone `solo_consulta=true` en una bodega operativa; no hay camino desde la API. Los seeders de CD (`db:seed --force`) escriben solo a Bodega Central/Showroom por nombre; no tocan Zona Libre.

### T1 — BAJO — Cobertura del trigger acotada a `product_warehouse_stock`
No cubre `TRUNCATE`, ni `ALTER TABLE ... DISABLE TRIGGER` (cualquier rol dueño: en test/prod `deploy-manual.sh:65-71` cae a `DB_USERNAME` cuando `DB_MIGRATE_*` no existe, o sea el rol de la app puede ser dueño y deshabilitarlo), ni las tablas de movimientos (`inventory_movements`, `purchase_order_receipt_lines`, `general_count_request_lines`): un movimiento de kardex contra Zona Libre solo lo frena la validación del endpoint. Aceptable como defensa en profundidad; confirmar que prod tiene un `DB_MIGRATE_*` distinto del rol de la app (hoy no verificable por lectura). Mitigación opcional: mismo trigger en `inventory_movements`. Bloquea: no.

### T2 — BAJO — Costo extra y excepción no mapeada
`ProductWarehouseStock::saving` agrega 1 consulta por guardado (ProductWarehouseStock.php:47) y lanza `WarehouseSoloConsultaException` (DomainException): si un camino interno olvida la validación responde 500 en vez de 422. Es red de seguridad, no ruta normal. Registrar un handler 422 en el `Handler` evitaría 500 con mensaje interno. Bloquea: no.

## Parte 4 — Comando destructivo `lafayette:aislar-catalogo`

Verificado OK:
- Dry-run por defecto y sin escritura: `ejecutar()` solo llama `analizar/reportar/bloqueos` (solo SELECT) antes de `$apply` (Command:116-151); el `--apply` pide frase exacta `APLICAR <entorno> <tenant>` (Command:188-192). Por pipe/`--no-interaction` `ask()` devuelve vacío => no confirma (Symfony desactiva interactividad sin TTY).
- Todo (respaldo, restauración, borrados, verificación) en UNA `DB::transaction`; cualquier discrepancia lanza `RuntimeException` => ROLLBACK, incluidas las `CREATE TABLE ..._bak_scrum990`. Idempotente: sin nada que limpiar responde "ya aislado" antes de los bloqueos (Command:129).
- SQL: el único valor variable interpolado es el slug del tenant, validado contra la tabla de tenants antes de usarse; nombres de tabla/columna provienen de modelos o de `pg_catalog`; `$zl` es `(int)`; `old_values` solo se castea dentro del SQL (`::bigint/::numeric`) y un valor inválido produce error => rollback. Los datos de Lafayette jamás se interpolan.
- Borra solo: stock con `warehouse_id = zl`, productos con `external_source='lafayette_lighting'` y sin enlace a `legacy.productos`, y las 4 tablas de `HISTORIAL_TABLAS` filtradas por columnas FK a esa bodega; cualquier otra tabla con filas contra la bodega, o cualquier referencia a un "puro" (FK declarada o columna `catalog_product_id` por nombre), bloquea (`bloqueos()`).
- Verificación posterior sólida: conteos, stock de otras bodegas idéntico, cero ICG con proveedor Lafayette, respaldo = filas esperadas.

### C1 — ALTO — "Puros" se define por ausencia de enlace a ICG, no por haber sido creados por el sincronizador (Command:226-228; loader viejo `loadOne`)
El loader viejo también "vinculaba" (`updateExisting`) productos YA existentes en AtlanticERP con `provider_id IS NULL` (cargados a mano, no ICG) y los marcaba `external_source='lafayette_lighting'`. Esos productos no están en `legacy.productos.catalog_product_id`, así que el comando los clasifica como PUROS y, si no tienen referencias, los BORRA en vez de restaurarlos: se pierde un producto real de AtlanticERP (solo recuperable desde `*_bak_scrum990`). La verificación no lo detecta (cuenta contra su propio plan). En dev la cifra cuadró (25.816 = 17.815 + 8.001) pero prod puede diferir; el doc 6.6 lo marca como riesgo "a verificar primero".
Mitigación: clasificar por evidencia de auditoría: todo producto con evento `primer_enlace` (UPDATE que puso `external_source`) fue PREEXISTENTE => restaurar, nunca borrar; "puro" = sin ese evento y con evento `created`. El dry-run debe listar cuántos preexistentes no-ICG hay y abortar si `puros` incluye alguno con evento de enlace.
Bloquea: Push dev no; ejecución test/prod SÍ.

### C2 — ALTO — Anulación de historial sin precondiciones sobre documentos reales ni efectos agregados (Command:61, 283-296, 584)
`customer_returns`, `purchase_order_receipt_lines`, `inventory_movements` y `general_count_requests` se borran por columnas FK a Zona Libre sin mirar estado ni documentos derivados: (a) `customer_returns` tiene `credit_note_id` (migración 2026_09_09_100001): se puede borrar una devolución que originó una Nota de Crédito emitida, dejando la NC huérfana sin error (FK del lado de la devolución); `customer_return_lines` y `general_count_request_lines` caen por CASCADE sin contarse; (b) borrar `purchase_order_receipt_lines` no revierte `qty_received` de la orden de compra, costos promedio ni cuentas por pagar; (c) `inventory_movements` es trazabilidad tipo kardex: destruirla va contra el principio de inmutabilidad ya adoptado para `audit_logs` (REQ-634); (d) el `WHERE` une con OR todas las columnas FK a bodega: un movimiento/recepción que tenga Zona Libre en una columna y otra bodega operativa en otra desaparece entero y descuadra el kardex de la bodega operativa; la verificación solo compara `product_warehouse_stock`, no el kardex. Los números del diseño (1 devolución, 1 conteo, 8 movimientos, 6 líneas) son de dev; en prod son desconocidos.
Mitigación: precondiciones que ABORTAN (no avisan): `customer_returns.credit_note_id IS NULL`, `purchase_order_receipt_lines` solo no confirmadas o de OC sin pagos/contabilizar, filas cuya OTRA columna de bodega apunte a una bodega operativa; el dry-run debe imprimir ids y estados de cada fila a borrar y Luis aprueba ese listado por entorno; por defecto `--conservar-historial-zona-libre` en test/prod y borrarlo solo con un flag explícito adicional; mantener los respaldos hasta la aceptación final.
Bloquea: ejecución test/prod SÍ (irreversible fuera de la BD, afecta datos fiscales/contables).

### C3 — ALTO — La restauración desde `audit_logs` puede "restaurar" sin restaurar y la verificación es tautológica (Command:335-343, 553, 658)
(1) `COALESCE(old_values,'{}')` convierte un evento sin `old_values` (o con diff vacío) en un "evento válido": el producto cuenta como restaurable (`tiene_evento=true`), se le quita la marca `external_source` y queda con nombre/foto/proveedor/categoría/precio de Lafayette sin ninguna advertencia. Aplica también a los 39 productos ICG con documentos reales de prod. (2) Solo se usa el PRIMER evento: `old_values` es un diff; si el primer enlace no cambió un campo (valor igual al de Lafayette) y una corrida posterior sí lo cambió, el valor ICG original está en el `old_values` del evento posterior y se ignora: el producto conserva el valor de Lafayette. (3) La verificación compara el resultado contra el mismo `old_values` del que salió, no contra una fuente independiente (`legacy.productos`), así que no detecta (1) ni (2). El paso 7 del runbook (sync incremental ICG) corrige nombre/marca/precio, pero no `factory_reference`, `provider_id`, `photo_url` ni `category` si el sync no los pisa (verificar).
Mitigación: exigir `jsonb_exists(ov,'provider_id')` (el enlace siempre cambia `provider_id`) para contar como evento válido; construir el estado original agregando el `old_values` MÁS ANTIGUO por clave (`jsonb_object_agg` ordenado por id, primera aparición) en vez de solo el primer evento; para los productos usados por documentos, comparar además contra `legacy.productos` y listar diferencias en el dry-run para revisión humana (los 39 de prod, uno por uno). Si falta cualquiera: bloquear, nunca desmarcar en silencio.
Bloquea: ejecución test/prod SÍ.

### C4 — MEDIO — Guarda de entorno por lista negra y evitable con `--env` (Command:54, 95)
`ENTORNOS_SIN_YES = ['production','qa']`: el docblock dice "solo local/dev/testing" pero el código rechaza únicamente esos dos nombres; un `APP_ENV` distinto (`prod`, `staging`, `Production`) acepta `--yes` y salta la confirmación. Además `$this->laravel->environment()` obedece `--env=local` en la línea de comandos (`php artisan ... --apply --yes --env=local` reporta "local" aunque `.env` sea producción). Intencional, no accidental, pero la defensa existe justamente contra descuidos.
Mitigación: lista blanca `['local','dev','testing']` y leer `config('app.env')` (APP_ENV real, no afectado por `--env`); rechazar también `--yes` si el host de `DB_HOST`/nombre de BD coincide con prod. Bloquea: ejecución prod: recomendado antes.

### C5 — MEDIO — Credenciales de migración no se exigen (Command: docblock vs. código)
El docblock y el diseño dicen que corre con `DB_MIGRATE_*`, pero nada en el comando lo verifica ni conecta con ese rol; depende de que quien opera pase `-e DB_USERNAME=... -e DB_PASSWORD=...` (como `deploy-manual.sh:106-122`). Si se ejecuta con el rol de la app, o falla por falta de `CREATE` (fail-safe) o, si el rol de la app es dueño (ver T1), corre con privilegios de más. Los secretos viven en `.env` y se pasan por env al contenedor (no se imprimen), correcto. Mitigación: el comando valida `SELECT current_user` contra `env('DB_MIGRATE_USERNAME')` y aborta si no coincide; runbook con la invocación exacta. Bloquea: no.

### C6 — MEDIO — TOCTOU: el análisis y los bloqueos se calculan FUERA de la transacción y no hay bloqueo de tablas (Command:126-160, 527)
Entre `analizar()`, la confirmación escrita (puede tardar minutos) y `aplicar()`, en prod hay usuarios y el sync ICG: un documento nuevo sobre un "puro", un enlace nuevo a `legacy.productos` o un movimiento nuevo cambian el resultado. Lo cubre en parte el conteo de verificación (`purosBorrados !== plan['puros']` => rollback) y las FK RESTRICT, pero las referencias sin FK (columnas `catalog_product_id` de `legacy`, JSON de exportaciones, snapshots por `reference`) pueden quedar huérfanas sin que ningún conteo cambie, y los borrados con CASCADE (receipt_lines, stock) arrastran filas.
Mitigación: dentro de la transacción, `LOCK TABLE catalog_products, product_warehouse_stock, legacy.productos IN SHARE ROW EXCLUSIVE MODE`, volver a correr `analizar()` y `bloqueos()` y comparar con el plan confirmado (hash del plan impreso en el dry-run); ventana de mantenimiento / modo mantenimiento de la app en prod.
Bloquea: ejecución prod: recomendado.

### C7 — MEDIO — Auditoría del resumen débil (Command:167-172; AuditService::log)
El evento resumen se escribe DESPUÉS del commit y `AuditService::log` traga cualquier fallo (`catch Throwable` => solo `Log::warning`): puede quedar una operación destructiva commiteada sin ningún registro. En CLI `user_id` es null: no queda quién la ejecutó (ni host/usuario del SO). `metadata` solo lleva contadores: sin los ids de productos restaurados/borrados ni un hash, y las tablas `*_bak_scrum990` (mutables, ya planeadas para borrarse tras la aceptación) serían la única evidencia. Mitigación: escribir el evento DENTRO de la transacción con `AuditLog::create` directo (si falla, rollback), incluir `executed_by` (`get_current_user()`, `gethostname()`, operador pedido por `--operador=`), hash SHA-256 de la lista ordenada de ids restaurados/borrados, y retener los respaldos hasta cerrar la auditoría (y archivarlos en el bucket inmutable, ver SCRUM-815). Bloquea: ejecución prod: recomendado.

### C8 — BAJO — Identificación de la bodega por nombre sin exigir `solo_consulta` (Command:50, 119-124)
`Warehouse::where('name','Bodega Zona Libre')->first()`: si el nombre cambió o existen dos con el mismo nombre, borra el stock de una bodega que podría ser operativa. Mitigación: exigir `solo_consulta = true` (abortar si no) y que haya exactamente una. Además el `DELETE` de stock incluye filas de productos NO Lafayette (17 en dev): comprobar que `catalog_products.stock_quantity` de esos productos no queda inconsistente. Bloquea: no.

### C9 — BAJO — Higiene operativa
(a) No exige un `pg_dump` externo previo; solo crea respaldos en la misma BD (mismo dominio de falla): imprimir y exigir `--dump-confirmado=<ruta>`; (b) las tablas `*_bak_scrum990` copian datos de devoluciones/conteos sin política de retención ni permisos explícitos: restringir `REVOKE ALL ... FROM PUBLIC`, y dar fecha de borrado; (c) el comando destructivo queda en la imagen de producción hasta el ticket de retiro: registrar ese ticket con fecha; (d) referencias sin FK ni columna `catalog_product_id` no se detectan (ver C6).

## Parte 5 — Integridad de datos (documentos reales en prod: 48 líneas de cotización, 8 de pedido, 9 ajustes, 2 movimientos sobre 39 ICG)

- El comando protege bien el caso FK: los 39 ICG con documentos se verifican uno por uno (`icg_documentales`) y se aborta si alguno no tiene evento (`bloqueos()`, Command:463-466). Las líneas de documentos no se tocan (la verificación compara los conteos de todas las tablas que referencian productos).
- Riesgo residual (ver C3): un ICG con evento vacío o con campos cambiados solo en eventos posteriores queda con `external_source=NULL` pero valores de Lafayette; las líneas de cotización/pedido que lean el producto en vivo (no snapshot) muestran nombre/precio/foto equivocados hasta que el sync ICG los pise, y `provider_id`/`factory_reference` podrían no corregirse nunca. Para esos 39: exigir revisión humana del dry-run (valor actual vs. `old_values` vs. `legacy.productos`) y NO aplicar si hay diferencias sin explicar.
- Riesgo residual (ver C1): producto preexistente de AtlanticERP no-ICG, sin referencias, clasificado "puro" y borrado.
- Riesgo residual (ver C2): historial contra Zona Libre con Nota de Crédito o recepción contabilizada. Los 2 movimientos de prod sobre ICG solo se tocan si apuntan a la bodega Zona Libre; el dry-run de prod debe decirlo explícitamente.
- Orden de operaciones: no hay riesgo de FK en el orden stock -> puros -> historial dentro de una transacción; pero si un "puro" tiene filas de historial (`inventory_movements`, `receipt_lines`), `analizar()` lo cuenta como bloqueo (esas tablas están en `refs`) y el comando se niega a correr aunque el historial sea anulable: fail-safe, pero posiblemente impide la limpieza en dev; no es un riesgo de seguridad. Confirmar en el dry-run de dev.

## Parte 6 — Cadena de suministro y agendado

- Sin cambios en `composer.json`, `composer.lock` ni `package.json` dentro del rango de los 6 commits: sin dependencias nuevas.
- `routes/console.php`: sin cambios en estos commits; cadencia por entorno vigente (prod 12:00/22:00, dev sábado 22:00, qa domingo 22:00) con `withoutOverlapping()`. El comando `lafayette:aislar-catalogo` no está agendado.
- Certificado: `storage/certs/lafayette-ca.pem` versionado (público); si Lafayette rota la CA el sync fallará cerrado (comportamiento deseado). Corregir el comentario obsoleto de `.env.example:245`.

## Handoff

- Backend Dev (antes de test/prod): C1, C2, C3 (ALTOS), luego C4-C7 y P1; H1/H2-backend/P2 recomendados antes de prod.
- Frontend Dev (condición del push de Zona Libre): H2 (render seguro de `url_imagen`, texto escapado, `referrerPolicy`, nunca `href`).
- AWS Architect / DevOps: CSP `img-src` en nginx; confirmar que `DB_MIGRATE_*` es un rol distinto del de la app en test y prod; pausar el sync de prod durante `--apply`; `pg_dump` externo + copia al bucket antes de cada entorno.
- Senior Reviewer: este informe complementa `docs/reviews/scrum990-senior-review.md` (sin leer ese archivo; no se sabe si C1-C3 ya fueron vistos).
- Luis: decisión de negocio pendiente: (a) aprobar, por entorno, el listado de historial a anular (C2); (b) si `precio_5` puede verlo todo `bodega.read` (H3); (c) autorizar el diagnóstico de solo lectura en test/prod antes de cualquier `--apply`.

## Resumen de severidades

| ID | Sev | Tema | Bloquea push dev | Bloquea ejecución prod |
|---|---|---|---|---|
| C1 | ALTO | Clasificación de "puros" borra productos preexistentes no-ICG | No | Sí |
| C2 | ALTO | Anulación de historial sin precondiciones (NC, OC, kardex) | No | Sí |
| C3 | ALTO | Restauración con evento vacío/solo primer evento; verificación tautológica | No | Sí |
| C4 | MEDIO | Guarda de entorno por lista negra y evitable con --env | No | Recomendado |
| C5 | MEDIO | DB_MIGRATE_* no exigido | No | No |
| C6 | MEDIO | TOCTOU, sin bloqueo de tablas | No | Recomendado |
| C7 | MEDIO | Auditoría post-commit, tragada, sin operador ni ids | No | Recomendado |
| H1 | MEDIO | Sin rate limit; `page` sin tope | No | Recomendado |
| H2 | MEDIO | `url_imagen`/textos de tercero sin saneo en el consumo | No (sí el frontend) | Recomendado |
| P1 | MEDIO | Fila hostil aborta todo el sync; sin topes de largo/rango | No | Recomendado |
| P2 | MEDIO | Guarda 0.5 oculta hasta 50% del catálogo | No | No |
| H3, P3-P5, T1, T2, C8, C9 | BAJO | ver arriba | No | No |
