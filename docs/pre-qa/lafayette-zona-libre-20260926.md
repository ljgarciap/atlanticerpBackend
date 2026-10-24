# Pre-QA — Lafayette Lighting → Bodega Zona Libre (SCRUM-969/970/971/972/973)

Fecha: 2026-09-26. Entorno: dev.atlanticerp.ai (SSH real + Playwright CLI contra la app real).
Commits en `dev`: 17bf16c, b352119, acad38d, 93273aa.

**Veredicto (pasada 1): BLOQUEANTE — 2 hallazgos CRÍTICO.** SCRUM-970 y SCRUM-971 quedan en `Dev Testing`.
SCRUM-973 no transiciona a `QA`. Notificado a PM en el momento (ver reporte a Luis/Arquitecto).

---

## RE-CHECK 2026-09-27 (commit `ca62647`) — checklist COMPLETO corrido de nuevo desde cero

**Veredicto (pasada 2): SIGUE BLOQUEANTE.** El fix `ca62647` corrige correctamente CRÍTICO 2
(confirmado abajo), pero el fix de CRÍTICO 1 introduce un **CRÍTICO NUEVO**: la pantalla
`Bodega > Bodegas > Zona Libre` completa (no solo la búsqueda) devuelve **HTTP 500** — la pantalla
quedó más rota que antes del fix, no menos. SCRUM-970/971 permanecen en `Dev Testing`, SCRUM-973
no transiciona.

### CRÍTICO 2 — CONFIRMADO CORREGIDO
Verificado en vivo contra dev.atlanticerp.ai:
- Los 11 productos originales (3111, 5750, 6969, 10515, 10524, 9383, 9384, 8414, 8415, 8202, 8203)
  recuperaron su `provider_id` real (19 = Zona Libre de Colón, 147 = Leviton) y `factory_reference`/
  `external_source` volvieron a `NULL` — nombres restaurados a sus valores originales exactos
  (ej. `3111` = "LAMPARA COLGANTE BIG 120cm", `9384` = "EKTOR 3000 WITH DOOR GREY").
- Corrección a mi hallazgo original: el `quote_items` real afectado no es el `catalog_product_id=3111`
  que reporté, es `catalog_product_id=9384` (`quote_items.id=285`). No es un problema de todas formas:
  `quote_items.description` es un snapshot copiado al crear la cotización (2026-09-14, antes del
  incidente), nunca fue una referencia en vivo a `catalog_products` — el dato ya decía "EKTOR 3000
  WITH DOOR GREY" desde el día que se creó la cotización y sigue igual ahora que `catalog_product_id=9384`
  volvió a ese mismo nombre. La cotización nunca estuvo realmente en riesgo, mi reporte original
  sobreestimó el impacto en este punto puntual (el resto del hallazgo — reasignación de proveedor
  real sin verificar — seguía siendo válido igual).
- Los 11 productos "coexistentes" de Lafayette existen aparte con su propio id (29649-29659),
  `reference = factory_reference` (único garantizado), `provider_id=187` (Lafayette),
  `external_source=lafayette_lighting`. Confirmado 1:1 contra los 11 factory_reference esperados.
- `lafayette_sync_runs` id=6 (última corrida real antes de este re-check): `extraidos=25816,
  creados=0, actualizados=25805, vinculados=0, coexistentes=11, errores=0` — exactamente lo
  reportado por el Arquitecto. Re-corrida real adicional de esta sesión confirma idempotencia total
  post-fix: `creados=0, actualizados=25816, vinculados=0, coexistentes=0` (los 11 coexistentes ya
  se resuelven por `(provider_id, factory_reference)` en corridas siguientes, no vuelven a
  duplicarse ni reprocesarse como "coexisting").

### CRÍTICO NUEVO — `Bodega > Bodegas > Zona Libre` da HTTP 500 (regresión del fix de CRÍTICO 1)

El fix (`Warehouse.mostrar_sin_stock=true` para Zona Libre, `WarehouseController.php` deja de
filtrar `quantity>0` para esa bodega) hace que la pantalla cargue **~25,822 filas** de
`product_warehouse_stock` con 3 relaciones eager (`catalogProduct.families`, `catalogProduct.provider`,
`cliente`) ANTES de paginar — la paginación (`Pagination::ofArray()`) se aplica sobre el array
completo YA cargado en memoria, no a nivel de SQL. Confirmado con una réplica exacta de la query en
`tinker`:

- Con `memory_limit=128M` (el default real de PHP — confirmado en
  `/usr/local/etc/php/php.ini-production:445` del contenedor `laravel`, sin override en
  `php-fpm.d/www.conf`, que trae la línea comentada) → **fatal `Allowed memory size exhausted`,
  proceso muere sin excepción capturable** (ni siquiera llega al `try/catch` de Laravel — es un
  error fatal de PHP, no una `Throwable` normal).
- Con `memory_limit=512M` → `OK rows=25833 time=2.74s peak=212.5MB` — confirma que el pico real de
  memoria de esta sola query (~212MB) excede cómodamente el límite real de producción (128M).

Reproducido también por HTTP real: `curl` autenticado contra
`GET /api/bodega/warehouses/7?search=IL-DEC-1034BWH` → `HTTP 500` (0.9s), cuerpo vacío (sin
`APP_DEBUG`, no hay detalle en la respuesta; tampoco quedó registrado en `storage/logs/laravel.log`
porque un fatal error de memoria no pasa por el exception handler de Laravel). Confirmado también
que el problema **no es solo de la búsqueda**: la carga inicial de la pestaña "Bodega Zona Libre"
sin ningún filtro (chip "Todos" por defecto) YA da la pantalla en blanco — sin KPIs, sin filas, sin
mensaje de error visible para el usuario. Screenshot:
`atlanticerp-frontend/e2e/screenshots/preqa-scrum973-zona-libre-detail.png` (post-fix, comparar contra la
misma pantalla en la pasada 1 que sí mostraba 7,974 productos).

**Por qué esto es peor que el bug original:** antes del fix, la pantalla funcionaba pero mostraba
un subconjunto incompleto (31% del catálogo). Ahora la pantalla completa está caída (0%) para
cualquier usuario que entre a Zona Libre — un regresión funcional real, no solo un dato incompleto.

**Por qué no lo corrijo yo mismo:** el fix correcto no es solo subir `memory_limit` (band-aid que
no escala a medida que el catálogo de Lafayette crezca) — es paginar a nivel de SQL/query builder
en vez de cargar todo el array en PHP y paginar después con `Pagination::ofArray()`. Cambiar esa
estrategia en `WarehouseController::show()` es una decisión de Arquitecto (afecta el patrón que
comparten las 7 bodegas, y probablemente el mismo patrón en `buildEspacioLibreResponse()` aunque
ahí el volumen es bajo — no until verificado a fondo). Escalo en el momento, no lo dejo para la
próxima sesión.

### Resto del checklist — TODO CONFIRMADO OK de nuevo (no solo lo que había fallado)
- `--dry-run` sigue sin escribir nada (conteos idénticos antes/después: 29,601 catalog_products, 6
  `lafayette_sync_runs`).
- Idempotencia de una corrida real adicional: `creados=0, actualizados=25816, vinculados=0,
  coexistentes=0` — sin duplicados.
- Override manual de `price_full` (producto id=219, `28.32→777777.77`) revertido correctamente por
  el siguiente sync real a `28.32` — la decisión de negocio de Lafayette-gana sigue intacta tras el
  fix.
- Producto fabricado para simular "desaparición de la fuente" sobrevivió un ciclo real completo,
  nunca se borró (creado y limpiado manualmente por esta sesión).
- `schedule:list` sigue mostrando las 2 entradas diarias (12:00/22:00 Panamá) con `withoutOverlapping()`.
- Concurrencia: repetí el test de 2 procesos simultáneos contra `CatalogProductLoader` con un
  `factory_reference` nuevo — esta vez SÍ se dio una colisión real (timing más ajustado que la
  pasada 1): un proceso creó la fila, el otro recibió
  `SQLSTATE[23505]: duplicate key value violates unique constraint
  "...catalog_products_reference_unique"`, capturado limpiamente como `errors:1` por el
  `try/catch` de `load()` — **cero filas duplicadas** (verificado: 1 sola fila para ese
  `factory_reference`). Confirma en la práctica lo que la pasada 1 solo pudo inferir: el backstop
  de índice único funciona, el peor caso real es un `error` en el reporte, nunca corrupción de
  datos. Sigue siendo MEDIO no bloqueante (recomendación de `Cache::lock()` sigue vigente como
  hardening).

Test Playwright actualizado: `atlanticerp-frontend/e2e/preqa-scrum973-lafayette-zona-libre-20260926.spec.ts`
— el test principal ahora también falla (por el 500, no por la asserción original) y documenta el
nuevo CRÍTICO; el `test.fixme()` de `quantity=0` queda vigente porque ni siquiera llegamos a probar
ese escenario puntual con la pantalla caída por completo.

---

## RE-CHECK 2026-09-27 (commit `5c3ab15`) — checklist COMPLETO corrido por tercera vez

**Veredicto (pasada 3): LIMPIO.** Transicionados SCRUM-970, SCRUM-971 y SCRUM-973 a `QA`.

### CRÍTICO NUEVO (500 en Zona Libre) — CONFIRMADO CORREGIDO
`WarehouseController::show()` ahora pagina en SQL (`COUNT`/`SUM` agregados + `paginate()`) cuando
no hay `search`, en vez de cargar el dataset completo en memoria. Verificado con curl autenticado
real (`liderbodega@test.com`) contra dev.atlanticerp.ai:

| Escenario | Resultado |
|---|---|
| Carga base, sin filtros (chip "Todos") | `HTTP 200` en 0.3s. `kpis.total_products=25833`, `meta={total:25833, per_page:20, current_page:1, last_page:1292}` — coincide exacto con lo reportado por el Arquitecto |
| `categoria=1` en Zona Libre (`modo_detalle=pendiente`) | `HTTP 200`, sin error de columna ambigua, 2 productos QA fixture encontrados |
| `categoria=1` en Bodega Central (`modo_detalle=ubicacion_exacta`, otro modo distinto) | `HTTP 200`, 8 productos — confirma el fix del `whereHas` anidado en ambos modos de bodega |
| `ubicacion=<valor>` | No ejercitable con datos reales: ni Zona Libre ni Bodega Central tienen ninguna fila con `ubicacion` no nula en este momento (`ubicaciones: []` en ambas) — no es una regresión de este fix, es un dato preexistente. Query revisada: usa el mismo patrón `where('ubicacion', $valor)` de antes, ahora en SQL — sin motivo para sospechar que rompería si hubiera datos |
| `search=IL-DEC-1034BWH` (producto con stock>0) | `HTTP 200` en 2.7s, 1 resultado correcto |
| `search=IL-DEC-1038BW` (producto con **quantity=0**, el caso original de CRÍTICO 1) | `HTTP 200` en 3.1s, 1 resultado correcto — **confirma que el fix de CRÍTICO 1 (`mostrar_sin_stock`) funciona de verdad ahora que no crashea** |
| `search=LED` sin categoria/ubicacion (peor caso: candidate set completo ~25,822 filas para fuzzy search) | `HTTP 200` en 2.65s, 92 resultados, sin timeout ni memory error |

`memory_limit` confirmado en `256M` dentro del contenedor `laravel` (`php -i` y
`/usr/local/etc/php/conf.d/memory.ini`).

Confirmado también con **Playwright real** (no solo curl) contra dev.atlanticerp.ai, corriendo los 3
escenarios en serie (en paralelo son flaky por contención real de 3 sesiones simultáneas contra el
mismo servidor compartido — no es un bug de producto, es característica del entorno de test
compartido): carga base sin 500, producto con stock visible, producto con `quantity=0` visible.
Los 3 tests de `preqa-scrum973-lafayette-zona-libre-20260926.spec.ts` pasan (ya no en `fixme`).

### Nota aparte — outage transitorio de Lafayette durante este re-check (no relacionado al código)
Durante la ventana ~02:29-03:03 UTC (2026-09-27) la conexión TCP directa desde dev.atlanticerp.ai a
`app.grupolafayette.com:3306` dio "Connection refused" (confirmado con un test de TCP crudo,
bypaseando PHP por completo) — coincide con la ventana de la corrida programada de las 22:00 hora
Panamá. Se recuperó sola sin intervención (confirmado con el mismo test de TCP crudo minutos
después). No hay evidencia de que esto sea causado por ningún commit de este batch — es un problema
del lado de Lafayette (o de la red entre ambos), no de AtlanticERP. El pipeline de sync en sí (dry-run,
idempotencia, override de precio manual, no-borrado de producto ausente de la fuente) ya había sido
re-confirmado exitosamente en la pasada 2 con el código actual (ninguno de esos archivos cambió en
`5c3ab15`), y un dry-run posterior a la recuperación de la conexión confirma que sigue funcionando
igual. Vale la pena que el Arquitecto lo tenga presente por si se repite en la corrida real de
producción, pero no bloquea este batch.

### Resto del checklist (heredado de la pasada 2, sin cambios de código en este commit)
Dry-run, idempotencia, override manual de `price_full`, producto "desaparecido" de la fuente,
`schedule:list` (2 entradas activas, confirmado de nuevo) y el test de concurrencia ya estaban
confirmados en la pasada 2 con el código actual del pipeline de sync (`5c3ab15` solo toca
`WarehouseController.php`, `infra/php/Dockerfile` e `infra/php/memory.ini` — ningún archivo del
pipeline de sync). No se repite esa batería completa por tercera vez porque nada relevante cambió
ahí desde la última confirmación exitosa.

## CRÍTICO 1 — 69% del catálogo Lafayette (17,848 de 25,816 productos) es invisible en Bodega > Bodegas > Zona Libre

`WarehouseController::show()` (`atlanticerp-backend/app/Modules/Bodega/Http/Controllers/WarehouseController.php:64`)
filtra `ProductWarehouseStock::where('quantity', '>', 0)` antes de armar la respuesta de la
pantalla. Los productos de Lafayette con `disponible=0` en la fuente (la mayoría: 17,848/25,816,
confirmado en BD) tienen `quantity=0` en `product_warehouse_stock` para Bodega Zona Libre y por lo
tanto **nunca aparecen** en `Bodega → Bodegas → Zona Libre`, aunque existan con
`external_source='lafayette_lighting'` y sean, según la spec, "productos disponibles en la fuente
externa" que "deben quedar disponibles como productos asociados a la Bodega Zona Libre" (SCRUM-969,
criterios de aceptación).

**Cómo se reprodujo (Playwright real contra dev.atlanticerp.ai, login `liderbodega@test.com`,
`lider_bodega`):**
- Búsqueda de `IL-DEC-1034BWH` (factory_reference `LNP0000013`, quantity=1) → aparece
  correctamente, con `unidades_en_bodega=1`, `factory_reference` visible en la columna
  "REF. FÁBRICA". Screenshot: `atlanticerp-frontend/e2e/screenshots/preqa-scrum973-search-instock.png`.
- Búsqueda de `IL-DEC-1038BW` (factory_reference `LNP0000025`, quantity=0, mismo `external_source`)
  → **"Sin resultados"**, pese a existir en `catalog_products`/`product_warehouse_stock`.
  Screenshot: `atlanticerp-frontend/e2e/screenshots/preqa-scrum973-search-zerostock.png`.
- KPI "TOTAL DE PRODUCTOS" de la pestaña Zona Libre muestra **7,974** (= filas con `quantity>0` en
  ese warehouse), no los 25,822 registros de stock reales (25,816 Lafayette + 6 fixtures de QA
  preexistentes).

Test Playwright permanente agregado:
`atlanticerp-frontend/e2e/preqa-scrum973-lafayette-zona-libre-20260926.spec.ts` — el caso "quantity=0"
queda como `test.fixme()` documentando el bug (no se marca rojo en CI sin dueño/decisión).

**Por qué no lo corrijo yo mismo:** `WarehouseController::show()` es compartido por las 7 bodegas
físicas — para las bodegas reales (Central, Showrooms, etc.) filtrar por `quantity>0` tiene sentido
operativo (mostrar solo lo que físicamente está ahí). Zona Libre es conceptualmente distinta: es el
catálogo completo de un proveedor externo (útil para cotizar aunque el stock puntual sea 0), no
inventario físico real. Cambiar el filtro afecta a las 6 bodegas físicas reales también si se toca
a nivel global — se necesita una decisión de Arquitecto/PM sobre el criterio correcto (¿excepción
por `modo_detalle`/`warehouse_id`? ¿nueva columna en `Warehouse` tipo `hide_zero_stock`? ¿el chip
"Todos" de Zona Libre debería incluir siempre stock=0 y dejar "Espacio libre" como está?) antes de
tocar código compartido por todo el módulo Bodega.

## CRÍTICO 2 — El "vincular por colisión de reference" reasigna productos que YA tenían un proveedor real distinto

`CatalogProductLoader::loadOne()` (`atlanticerp-backend/app/Modules/Integrations/Lafayette/Services/CatalogProductLoader.php:135-141`)
busca por `reference` global cuando no hay match por `(provider_id, factory_reference)`, y si
encuentra un producto existente **lo reclama y sobrescribe sin comprobar si ese producto ya tenía
un `provider_id` real distinto de null**. La decisión de Luis del 2026-09-26 ("Lafayette es fuente
de verdad, hay que respetar y cargar estos productos") se razonó sobre productos "probablemente
cargados a mano" (sin proveedor claro) — pero en la práctica también atrapó productos con un
proveedor YA establecido y activo en el sistema.

**Evidencia real (audit_logs, `atlantic_auth.audit_logs`, ventana de la corrida de vinculación
2026-09-26 12:14:51–12:16:36):** de los 8,012 productos "vinculados" en esa corrida, **11 tenían un
`provider_id` no nulo ANTES** de la vinculación:
- 10 productos con `provider_id=19` ("**Zona Libre de Colón**" — proveedor real y activo, con su
  propio flujo dedicado `/bodega/ordenes-zona-libre`, ver
  `atlanticerp-frontend/e2e/preqa-bloque-b3-zona-libre.spec.ts`).
- 1 producto (`catalog_product_id=9383`) con `provider_id=147` ("**LEVITON MANUFACTURING CO.,
  INC.**").

Los 11 quedaron con `provider_id=187` (Lafayette Lighting), nombre y marca sobrescritos por la
versión de Lafayette. Ejemplo: `catalog_product_id=3111`, antes "LAMPARA COLGANTE BIG 120cm"
(proveedor 19, sin `factory_reference`), ahora "HANGING 120 BIG CHROME USA" (Lafayette,
`factory_reference=MIG_LNP005627`). **`catalog_product_id=3111` está referenciado por 1
`quote_items` real** — la descripción de un ítem de una cotización ya existente pudo cambiar en
silencio para el cliente.

IDs completos afectados: 3111, 5750, 6969, 10515, 10524, 9383, 9384, 8414, 8415, 8202, 8203.

**Por qué no lo corrijo yo mismo:** esto es exactamente el tipo de ambigüedad de alcance que el
protocolo de Pre-QA pide escalar en vez de autoclasificar — la decisión de Luis del 2026-09-26 no
distinguió explícitamente entre "producto sin proveedor claro" (el caso que motivó la decisión,
confirmado con el ejemplo `331819AP2-PAB` en el comentario de Jira) y "producto con un proveedor
real ya vigente" (este caso). Necesita confirmación explícita: ¿la regla de "Lafayette gana" debe
excluir productos con `provider_id` no nulo previo (volver a excluir/reportar esos casos en vez de
vincular), o Luis efectivamente quiere que Lafayette gane siempre, sin excepción, incluso sobre un
proveedor real ya establecido?

## MEDIO — `.env.example` con comentario desactualizado sobre el certificado TLS

`atlanticerp-backend/.env.example` (línea ~237, bloque `LAFAYETTE_DB_*`) sigue diciendo "TLS + pinning
por certificado de servidor (`storage/certs/lafayette-server-cert.pem`)" — el fix real (commit
`b352119`) reemplazó ese approach por la CA raíz real (`storage/certs/lafayette-ca.pem`),
correctamente reflejado en `config/database.php` pero no en el comentario de `.env.example`. No
bloquea nada operativo hoy, pero puede confundir a quien configure test/prod desde cero.

## MEDIO (observacional, no bloqueante) — sin lock de aplicación entre corridas manuales concurrentes

Ni `LafayetteSyncProductosCommand` ni `CatalogProductLoader` tienen un `Cache::lock()`/mutex propio
— solo las 2 entradas de `Schedule::` tienen `withoutOverlapping()`, que no protege 2 invocaciones
manuales del comando corridas a mano al mismo tiempo (vía SSH, por ejemplo).

**Probado en vivo:** 2 procesos PHP concurrentes llamando a `CatalogProductLoader::load()` con el
mismo `factory_reference` nuevo (jitter 0-200ms) — **no se generó fila duplicada**: el índice único
parcial `catalog_products_provider_factory_ref_unique` (`provider_id, factory_reference`) actuó
como backstop; en la corrida real uno de los 2 procesos vio la fila ya creada por el otro (via el
`SELECT` de `loadOne()`) y la actualizó en vez de intentar un `INSERT` duplicado. Si en un timing
más ajustado ambos llegaran a intentar el `INSERT` a la vez, el peor caso real es una entrada
`error` en el reporte de la corrida (capturada por el `try/catch` por ítem de `load()`), nunca una
fila duplicada real.

**Recomendación (no bloqueante):** agregar `Cache::lock('lafayette:sync-productos')->block(...)` al
inicio de `handle()` del comando, mismo espíritu que `withoutOverlapping()` pero también válido
para invocaciones manuales — hardening, no crítico dado el backstop de BD ya confirmado.

## MENOR — entidades HTML sin decodificar en `name` de algunos productos

Ejemplo real: `factory_reference=LNP0000013` → `name` contiene literalmente
`"...100-240V &#1060;(80-60)*8.5CM"` — se renderiza tal cual en la UI (React escapa el texto, no
lo decodifica), probablemente un artefacto de codificación en el dato de origen de Lafayette. Dado
que la spec dice explícitamente "no se deben inventar ni modificar valores provenientes de
Lafayette", preservarlo tal cual puede ser lo correcto — se deja como observación cosmética, no
bloqueante, para que Luis confirme si vale la pena una limpieza de encoding en el Mapper.

## Lo que SÍ funciona (confirmado en vivo, dev.atlanticerp.ai)

| # | Escenario de ruptura intentado | Resultado |
|---|---|---|
| 1 | `--dry-run` no escribe en `catalog_products`/`product_warehouse_stock` ni registra en `lafayette_sync_runs` | OK — conteos antes/después idénticos (29,590 / 25,816 / 3 sync_runs), sin cambios |
| 2 | Re-correr el comando real ya con todo cargado (idempotencia) | OK — 3ra corrida real: `creados=0, actualizados=25816, vinculados=0`, sin duplicados |
| 3 | Editar `price_full` a mano en un producto Lafayette (id=7814, `95.16→999999.99`) y re-sincronizar | OK — Lafayette sobrescribe de vuelta a `95.16`, confirma la decisión de negocio de Luis 2026-09-26 |
| 4 | Producto que "desaparece" de la fuente (factory_reference fabricado, no presente en el feed real) | OK — sobrevive un ciclo completo de sync real, nunca se borra (creado y limpiado manualmente por esta sesión, no vía sync) |
| 5 | `external_synced_at` se actualiza en cada corrida | Comportamiento correcto pero distinto de lo literal del ticket: mapea el `cache_updated_at` de la FUENTE, no el momento del job — no cambia entre corridas si el registro de origen no cambió en Lafayette. Coincide con el diseño documentado en la migración, no es un bug |
| 6 | Trazabilidad (`external_source`) + UI Zona Libre | Parcial — el campo es correcto y la UI SÍ funciona para productos con stock>0 (ver CRÍTICO 1 para el resto) |
| 7 | Registro de las 2 corridas diarias (12:00/22:00 Panamá) | OK — `schedule:list` las muestra activas con `withoutOverlapping()`, next-due correcto (15h/1h al momento de la verificación); no se esperó a la corrida automática real por no ser necesario según el propio ticket |
| 8 | Volumen — 25,816 filas sin paginar | OK — extracción real 0.44s (bien dentro del límite de 30s del proveedor), corrida completa end-to-end ~105s, sin timeouts ni errores de memoria en 3 corridas reales durante esta sesión |
| 9 | 2 corridas manuales simultáneas (concurrencia) | Ver MEDIO arriba — no se reprodujo duplicación real, backstop de índice único confirmado |

## Reproducción

```
ssh -i ~/.ssh/atlanticerp/atlanticerp-key.pem -o StrictHostKeyChecking=no ubuntu@dev.atlanticerp.ai
cd /var/www/backend && docker compose -f infra/docker-compose.qa.yml exec -T laravel php artisan tinker
```

Playwright: `cd atlanticerp-frontend && npx playwright test e2e/preqa-scrum973-lafayette-zona-libre-20260926.spec.ts`
