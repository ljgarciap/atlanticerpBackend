# SCRUM-990 — Re-verificación de seguridad, ronda 2 (Cybersecurity)

Fecha: 2026-10-01. Solo lectura: no se ejecutó nada contra ningún entorno ni se corrió la suite. Leído de forma independiente el código final
(`app/Console/Commands/LafayetteAislarCatalogoCommand.php` 1161 líneas, `LafayetteSyncProductosCommand`, `ProductoMapper`, `LafayetteProductoLoader`,
`LafayetteSettings`, `config/lafayette.php`, `AppServiceProvider` throttles, `routes/bodega.php`, `ProductWarehouseStock`) y, en `atlanticerp-frontend`,
`src/lib/zonaLibreCatalogo.ts` y `src/components/bodega/ZonaLibreCatalogoTable.tsx`. Commits backend 85261e5d, 6bfbaed0, 375091b3, ba88b726, c405413f, 07f655b9;
frontend 896ae4c y f3bee24. Se contrastó contra `scrum990-revision-seguridad.md` (ronda 1). No se confió en el docblock ni en los mensajes de commit: se verificó el SQL.

## Veredicto

- **Push a dev: NO bloquea.** Ningún CRÍTICO ni ALTO abierto. Los 3 ALTOS de la ronda 1 (C1, C2, C3) están CERRADOS en el código.
- **Ejecución del comando en test/prod: NO bloquea por código, SÍ queda condicionada** a 1 hallazgo MEDIO nuevo (N1, sin `lock_timeout`) y a las
  precondiciones operativas de DevOps/Luis (sección final). Dev puede ejecutarse tras el dry-run.
- `--apply` en producción sigue siendo imposible sin: frase exacta `APLICAR <entorno> <tenant>` tecleada (`--yes` solo con `config('app.env')` en
  `local|testing`), rol de migración (`DB_MIGRATE_*` definido, distinto de `atlanticerp_app` y del actual) y huella del plan recalculada bajo bloqueo.
  Fallar cualquiera aborta antes de escribir.

## Estado por hallazgo

| ID | Ronda 1 | Ronda 2 | Evidencia |
|---|---|---|---|
| C1 | ALTO | **CERRADO** | Clasificación por auditoría: `enlace` (primer `updated` que puso `external_source`, CTE líneas 317-322) => PREEXISTENTE no-ICG se RESTAURA, nunca se borra (`conjuntos()` 358-371). "Puro" = sin enlace ni vínculo ICG; además exige evento `created` con origen Lafayette (`creados`); sin él bloquea salvo `--permitir-puros-sin-evidencia` (bloqueos() 792). El DELETE (945-948) usa `NOT IN enlace` y `NOT IN legacy` (sin NULL en el `NOT IN`: `IS NOT NULL` en 378). Dry-run reporta preexistentes aparte (829-831). Test `test_un_producto_de_atlanticerp_vinculado_...`. Residual BAJO en N4. |
| C2 | ALTO | **CERRADO** (con residuales BAJO N5, N6) | Lista blanca `HISTORIAL_TABLAS`; cualquier otra tabla contra Zona Libre bloquea (783). `whereSoloBodega` (418-423) excluye filas mixtas y las lista como bloqueo (697-700); el MISMO `where` se usa en respaldo, huella, listado de ids y DELETE (702, 911, 953). `customer_returns.credit_note_id` bloquea (728-733); recepciones confirmadas, OC `recibido` o con pagos bloquean (748-754); hijas CASCADE de primer nivel se cuentan, respaldan y descuentan en la verificación (704-707, 912-915, 1124). Dry-run imprime ids, hijas y "desfases". Sigue siendo el comportamiento por defecto anular (existe `--conservar-historial-zona-libre`); decisión de Luis, ver pendientes. |
| C3 | ALTO | **CERRADO** (residual BAJO N7) | Válido solo si `old_values` trae `provider_id` (`jsonb_exists`, 357, 369); sin `COALESCE` a `{}` como evento válido. Valor original = el MÁS ANTIGUO por clave (`jsonb_object_agg ... ORDER BY eid DESC`, el último duplicado gana en jsonb = el más antiguo; solo eventos de consola `user_id IS NULL` desde el enlace). Ediciones de usuario posteriores no se pisan y se reportan (`ediciones`, 337-344, 922). Documentales sin evento válido bloquean aun con la opción (795-798). Verificación con comprobaciones que no dependen solo del `old_values` usado (proveedor Lafayette, `factory_reference` = id Lafayette, pérdida de `factory_reference`) y detalle de contraste con `legacy.productos` marcado REVISAR (654, 869). |
| C4 | MEDIO | **CERRADO** | Lista blanca `['local','testing']` sobre `config('app.env')` (65, 133-141); `--yes` fuera de ella termina con error antes de tocar BD (111-115). `--env=` no altera `config('app.env')` cuando APP_ENV viene del entorno del contenedor. Nota: `dev` también queda fuera de la lista blanca (exige frase y `DB_MIGRATE_*` en dev; es más estricto, no un fallo). |
| C5 | MEDIO | **CERRADO** | `errorRolMigracion()` (242-263): exige `DB_MIGRATE_USERNAME` y contraseña definidos, `current_user` distinto de `DB_APP_ROLE` (default `atlanticerp_app`) e igual a `DB_MIGRATE_USERNAME`. Se evalúa solo en `--apply`, antes de la confirmación. Se lee vía `config()` (`config:cache`-safe). |
| C6 | MEDIO | **CERRADO con NUEVO N1** | Plan recalculado DENTRO de la transacción, tras `LOCK TABLE ... SHARE ROW EXCLUSIVE` (885-895); huella SHA-256 distinta => RuntimeException => ROLLBACK; los bloqueos se reevalúan bajo lock. Las referencias sin FK (por nombre `catalog_product_id`) también entran en el LOCK (`refs`). Ver N1 por el riesgo operativo del LOCK. |
| C7 | MEDIO | **CERRADO** (residual BAJO N8) | `auditar()` dentro de la transacción con `AuditLog::create` directo (no el `AuditService` que traga errores): si falla, rollback (test dedicado). Lleva operador (`--operador`), usuario del SO, host, entorno, tenant, conteos, ids documentales/historial (hasta 200 c/u) y huellas. Caché de Zona Libre invalidada tras el commit (207). Sin secretos ni datos de Lafayette en `metadata`. |
| H1 | MEDIO | **CERRADO** | `zona-libre-lectura` registrado en `AppServiceProvider` (158), 60/min por `sub` de un JWT con firma verificada (HS256) o IP de respaldo; aplicado a `GET /bodega/zona-libre/productos` ANTES de `permission:bodega.read` (routes/bodega.php:183, la ruta correcta). `page` `max:10000`. Residual BAJO N9. |
| H2 (frontend) | MEDIO | **CERRADO** (pendiente solo CSP, infra) | Texto de tercero solo como texto de React (`displayText`: controles C0/C1 y bidi fuera), sin `dangerouslySetInnerHTML` ni `href` con `url_imagen`. `safeImageUrl`: solo `https`, sin credenciales, sin controles ni espacios, <= 2048, forma normalizada; `<img referrerPolicy="no-referrer" loading="lazy" alt="">`, `onError` solo cambia estado. El backend deja pasar `http(s)` con host (el filtro `https` es del frontend): aceptable porque es la capa que pinta. Spec e2e con datos hostiles. Falta CSP `img-src` (DevOps). |
| P1 | MEDIO | **CERRADO** (residual BAJO N2, N3) | Mapper con cuarentena por fila: id vacío/UTF-8 inválido/controles/>50, referencia >100 se rechazan y cuentan por motivo; texto saneado (UTF-8, `\0` y controles fuera, truncado 2000/255); `url_imagen` solo http(s) con host, <=2048, sin espacios; `disponible` [0, 2^31-1]; `precio_5` null si no finito, negativo o > 1e10 (cabe en numeric(15,4)); fecha fuera de 1970-2100 => null. Si la BD aun así rechaza un lote, reintento fila por fila en savepoints; solo se registra id/clase/SQLSTATE, no contenido. `partial` si rechazadas > 1 %. |
| P2 | MEDIO | **PARCIAL — ABIERTO, MEDIO bajo** | La guarda ahora usa solo corridas `success` como línea base (una `partial` ya no se vuelve la base) y el umbral vive en `auth.system_settings.lafayette_guarda_min_ratio` (acotado a [0,1]). Pero el valor sembrado sigue en 0,5 (hasta 50 % del catálogo oculto por corrida, reversible) y no existe CRUD/pantalla de superadmin: solo SQL/tinker. No hay alerta por % fuera de fuente. No bloquea. Recomendación: sembrar 0,9 y/o alertar si `fuera_de_fuente` > 5 %. |
| P3 | BAJO | **CERRADO** (residual BAJO) | `MensajeSeguroDeExcepcion` (clase, código, primera línea sin `(Connection:|SQL:` recortada a 200) usado en ambos comandos. Residual: un "Access denied for user 'x'@'ip'" de la fuente aún muestra usuario/IP en consola; sin contraseña. |
| P4 | BAJO | ABIERTO | No hay `SET SESSION TRANSACTION READ ONLY` en la conexión `lafayette_lighting`. Defensa en profundidad opcional. |
| P5 | BAJO | ABIERTO (operativo) | El sync de prod (12:00/22:00) puede coincidir con `--apply`: la comprobación `lafayette_productos` + el LOCK de `lafayette_productos` lo vuelven espera/rollback, no corrupción. Ver pendientes DevOps. |
| T1 | BAJO | ABIERTO | El trigger sigue cubriendo solo `product_warehouse_stock`; sin cambios. El guard de aplicación con caché de 30 s por proceso no es un riesgo nuevo (el trigger es la barrera). |
| T2, C8 | BAJO | C8 **CERRADO** (exactamente 1 bodega y `solo_consulta`, 222-239); T2 ABIERTO (500 en vez de 422 si un camino olvida la validación) | |
| C9 | BAJO | (a) sin cambio, (b) y (c) ABIERTOS | Ver N6. |
| H3 | BAJO | Informativo; precio visible a `bodega.read` por decisión de Luis registrada en el commit ba88b726. | |

## Hallazgos NUEVOS introducidos por los arreglos

### N1 — MEDIO — `LOCK TABLE ... SHARE ROW EXCLUSIVE` sin `lock_timeout` ni `statement_timeout` (Command:884-899, 983-1000)
El bloqueo cubre `catalog_products`, `product_warehouse_stock`, `lafayette_productos`, `legacy.productos`, TODAS las tablas que referencian productos (líneas de cotización,
pedidos, ajustes, kardex, etc.), el historial anulable y sus hijas. Bloquea ESCRITURAS (las lecturas siguen) durante toda la transacción, que incluye `analizar()` completo
(escaneos de `audit_logs`, un conteo por tabla de referencia) y la verificación. Dos riesgos: (1) `LOCK TABLE a,b,c` toma los bloqueos en orden y, si una transacción larga
de la aplicación o de un job (p. ej. el sync ICG sobre `catalog_products`) tiene un `ROW EXCLUSIVE`, el comando espera SIN LÍMITE y, mientras espera, la cola de bloqueo
detiene a todos los escritores nuevos de las tablas ya adquiridas: en producción la app puede quedar sin poder guardar cotizaciones/pedidos hasta que el otro termine;
(2) un deadlock con otra transacción que bloquee en otro orden (Postgres mata a una de las dos; si es la de la app, error 500 al usuario).
Mitigación (Backend Dev): `SET LOCAL lock_timeout = '10s'` y `SET LOCAL statement_timeout` (p. ej. 15 min) al abrir la transacción, antes del LOCK; mensaje claro de reintento. Mover el
costo del análisis fuera del bloqueo no es posible por TOCTOU, así que además ventana de mantenimiento (DevOps: pausar Horizon/sync ICG/sync Lafayette y poner la app en modo mantenimiento).
Bloquea: push dev NO; ejecución test/prod: SÍ hasta tener `lock_timeout` (cambio de 2 líneas) o ventana de mantenimiento confirmada.

### N2 — BAJO — Reintento fila por fila ante falla de infraestructura (Loader:99-125)
Cualquier `Throwable` del lote (conexión caída, deadlock, `statement_timeout`) se trata como "datos del tercero" y dispara hasta 1000 reintentos individuales; si la causa es
infraestructura, todas las filas quedan "rechazadas por BD" (miles de líneas `Log::warning`, corrida `partial` por > 1 %). Fail-safe (no oculta catálogo: las filas rechazadas siguen en `seenIds`),
pero engañoso y ruidoso. Mitigación: reintentar fila por fila solo si `QueryException` con SQLSTATE de datos (clase 22/23); si no, relanzar. Bloquea: no.

### N3 — BAJO — Línea base de la guarda puede congelarse y caracteres bidi del tercero en el backend
(a) Con la base solo `success`, si Lafayette reduce de verdad su catálogo por debajo del umbral, todas las corridas quedan `partial` y los productos retirados nunca se marcan fuera de fuente
hasta que superadmin baje el umbral (sin pantalla, ver P2): fail-safe pero requiere operación manual. (b) El mapper no quita caracteres bidi (U+202A-E, U+2066-9) ni de ancho cero; el frontend sí quita los bidi. Bloquea: no.

### N4 — BAJO — Clasificación "puro" sigue dependiendo de la integridad de `audit_logs` (Command:317-327)
Si faltaran eventos por retención/borrado de `audit_logs`, un preexistente sin evento `updated` y con `created` Lafayette solo podría ser puro verdadero; sin ningún `created` bloquea. El caso residual
(producto de AtlanticERP creado a mano, vinculado por el loader viejo y SIN evento de enlace) cae en "puro sin evidencia" y bloquea: seguro por diseño. Confirmar en el dry-run de prod que `puros_sin_evidencia = 0`.

### N5 — BAJO — Hijas CASCADE solo de primer nivel y FKs `SET NULL` sin respaldo (Command:704-707, 912-915)
`fks($tabla, true)` no recorre nietos; una FK `ON DELETE SET NULL` hacia `inventory_movements`/`general_count_requests` (p. ej. `general_count_request_lines.replaced_by_id`, autorreferencia) no se respalda.
RESTRICT es fail-safe (rollback). Revisar en el dry-run de cada entorno el listado de hijas; el impacto es acotado a datos de prueba contra Zona Libre.

### N6 — BAJO — Respaldos `*_bak_scrum990` sin retención ni permisos explícitos; restauraciones por SQL crudo sin evento por producto (Command:908-916, 925)
Copian filas completas (productos, stock, devoluciones, conteos) sin `REVOKE ALL ... FROM PUBLIC`, sin fecha de borrado y en la MISMA base (mismo dominio de falla). Las 17.8K+ restauraciones se hacen con `UPDATE` crudo: no generan eventos
por producto en `audit_logs`; la única evidencia es el evento resumen + estas tablas. Mitigación: `pg_dump` externo previo (DevOps) y copia al bucket inmutable (SCRUM-815), fecha de retiro de los respaldos en el ticket de aceptación, y retirar el comando del repo tras los 3 entornos (ticket con fecha).

### N7 — BAJO — Restauración puede retroceder datos ICG más nuevos (Command:328-331)
Los eventos de consola (`user_id IS NULL`) posteriores al enlace incluyen también al sync ICG; el valor "más antiguo" es el previo al enlace, que puede ser anterior a una actualización legítima de ICG. El paso 7 del runbook (`legacy:sync-incremental-productos`) lo corrige solo para los campos que ese sync pisa; `factory_reference`, `provider_id`, `photo_url`, `category` deben verificarse tras el sync. Con `--permitir-icg-sin-auditoria` los productos sin evento válido conservan nombre/foto/precio de Lafayette (solo pierden marca y proveedor): usar solo con aprobación de Luis.

### N8 — BAJO — Operador opcional (Command:98, 1017)
`--operador` es texto libre y no obligatorio: el evento siempre lleva usuario del SO y host, pero conviene exigirlo con `--apply` (ya que `user_id` en CLI es nulo).

### N9 — BAJO — Throttle no impide la copia gradual por un usuario legítimo
60 peticiones/min x 100 filas = el catálogo completo (~258 páginas) en ~5 min por un usuario con `bodega.read`. Frena la ráfaga masiva y el abuso, no la exfiltración lenta; las lecturas de este endpoint no se auditan (REQ-632 "lecturas sensibles" sigue pendiente). Aceptable dado el dato (catálogo y precio de lista de un tercero, ya decidido); considerar tope diario si H3 se reclasifica.

### Revisado y SIN hallazgo nuevo
- Inyección SQL: lo interpolado son constantes, `(int)` (`$zlId`), nombres de `pg_catalog`/modelos y el slug validado contra la tabla de tenants; `$campo` sale de una constante; operador, proveedor, ids y datos del tercero van como bindings. `LIMIT` es constante entera.
- Rollback: DDL de Postgres es transaccional; los `CREATE TABLE ..._bak` entran en la misma transacción; el caché se invalida solo tras el commit; no queda estado a medias si la verificación o la auditoría fallan.
- Información sensible: ni el evento de auditoría ni los logs llevan credenciales, filas del tercero ni SQL; los logs por fila rechazada llevan `lafayette_id` (<= 50, sin controles) como contexto JSON.
- `lafayette_id` con `\n`/`\t` interior pasa el mapper (solo se rechazan controles fuera de tab/LF/CR): inofensivo en JSON, pero evitar imprimirlo en texto plano.

## Pendiente — DevOps / AWS Architect
1. CSP `img-src` en nginx limitado al/los hosts de imágenes de Lafayette (host real aún por confirmar): sin esto, cualquier URL https de la fuente carga desde el navegador del usuario (rastreo de IP, peticiones GET a hosts internos desde el navegador).
2. Antes de `--apply` en test/prod: pausar Horizon, el sync ICG y `lafayette:sync-productos` y poner la app en modo mantenimiento (o aceptar el bloqueo de escrituras de N1); verificar que `lock_timeout` esté en el comando.
3. `pg_dump` externo (y copia al bucket) por entorno antes de cada `--apply`; fecha de retención de los `*_bak_scrum990`.
4. Confirmar que `DB_MIGRATE_USERNAME/PASSWORD` existen en test y prod, que es un rol DISTINTO del de la app y que `DB_APP_ROLE` coincide con el rol real de la app (default `atlanticerp_app`); en dev el comando también lo exige (APP_ENV=dev no está en la lista blanca). Invocación exacta en el runbook (`-e DB_USERNAME=... -e DB_PASSWORD=...`).
5. Verificar que el rol de la app no sea dueño de `product_warehouse_stock` (T1) y, si se desea, sembrar el umbral de la guarda en 0,9.

## Pendiente — Luis (decisión de negocio)
1. Aprobar, por entorno, el listado de historial a anular (movimientos, recepciones, devoluciones, conteos) y la lista de "desfases" del dry-run; o correr con `--conservar-historial-zona-libre`.
2. Revisar uno por uno los productos con documentos marcados REVISAR (los 39 de prod) y que `puros_sin_evidencia = 0`; no aplicar con diferencias sin explicar.
3. Autorizar el diagnóstico de solo lectura (dry-run) en test y prod antes de cualquier `--apply`; decidir el valor del umbral de la guarda (0,5 vs 0,9) y si hace falta pantalla de ajuste (regla de parámetros).
4. Confirmar que `precio_5` puede verlo todo `bodega.read` (H3, ya aceptado en ba88b726).

## Handoff
- Backend Dev: N1 (`lock_timeout`/`statement_timeout`) antes de test/prod; opcionales N2, N8, T2, P4.
- Senior Reviewer: cambios de seguridad de este ciclo cubiertos; sin hallazgos que impidan aprobar el push a dev.
- Pre-QA: dry-run en dev (preexistentes, puros sin evidencia, filas mixtas, hijas) y prueba del `--yes` fuera de lista blanca en un entorno `dev`.
