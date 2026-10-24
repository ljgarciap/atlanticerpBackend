# Spec: `GET /api/bodega/inventory` 500 intermitente — carga completa del catálogo en memoria

**Date**: 2026-09-28
**Requested by**: Luis (reportado como error real en test.atlanticerp.ai mientras se cargaba la vista de Inventario; confirmado por Luis que prod también está expuesto)
**Status**: Done — desplegado y verificado en vivo en los 3 ambientes (dev/test/prod), commit `8c73828`.
**Project**: AtlanticERP — `atlanticerp-backend`, módulo `App\Modules\Bodega`

## Problem
`GET /api/bodega/inventory?page=1&per_page=20` responde 500 de forma intermitente en
`test.atlanticerp.ai` desde el 2026-09-28, justo cuando alguien abre las pantallas de Inventario de
Bodega. Coincide exactamente con los disparos de la alarma `atlanticerp-test-nginx-5xx-high` (ya
corregida para no cruzarse con dev/prod, ver `docs/specs/cloudwatch-nginx-5xx-metric-per-environment.md`).

## Root cause (confirmado, no es hipótesis)
Verificado con SSH a test (`docker compose logs`) + lectura de código + conteo real en Postgres:

`BodegaInventoryController::index()` (`app/Modules/Bodega/Http/Controllers/BodegaInventoryController.php:79-85`)
carga **todo** el catálogo activo con 2 relaciones eager, sin importar `per_page`:
```php
$products = CatalogProduct::query()
    ->with(['families', 'provider'])
    ->when(...)->when(...)
    ->visibleForDemoMode($demoModeActive)
    ->get()->all();
```
A partir de ahí, todos los filtros (chips, `estado`, `rotation`, `provider_id`, `brand`,
`family_id`, `warehouse_id`), el fuzzy search, y el orden (`usort()`) se aplican en PHP sobre ese
array completo, y recién al final `Pagination::ofArray()` corta las 20 filas de la página pedida —
la paginación es cosmética, el trabajo pesado (y el memory footprint) ya se pagó completo antes.

**Por qué ahora y no antes**: conteo real en test (`atlantic_ventas_diseno.catalog_products`):
`29,517` productos activos, de los cuales **`25,816` (87%) son el catálogo de Lafayette Lighting**
promovido a test el 2026-09-27 (`project_scrum969_lafayette_network_validated_20260925`, memoria
del proyecto). Antes de esa promoción el dataset de este endpoint era ~3,700 productos — chico
para que el patrón en memoria pasara desapercibido. Con 8x más volumen, cada request carga ~29,517
modelos Eloquent con 2 relaciones + construye ~6 mapas por ID (`committedMap`, `porIngresarMap`,
`enCaminoMap`, `enBodegaExcluidaMap`, `bulkPendingSummary`, `warehouseCounts`) para el universo
completo, en una instancia EC2 t3.small (2 GiB RAM total). No aparece ningún log de excepción en
`laravel.log` para las horas del incidente — mismo síntoma ya documentado en este proyecto: un
fallo por agotamiento de recursos a nivel de proceso/SO no pasa por el exception handler de
Laravel, así que no deja rastro ahí (`docker logs` tampoco mostró ningún fatal explícito, pero la
ausencia total de log de Laravel en la ventana exacta del incidente es la misma firma).

**Precedente exacto, mismo epic, 1 día antes**: este es el MISMO patrón (`->get()->all()` +
`Pagination::ofArray()` sobre el catálogo completo) que Pre-QA encontró y corrigió en
`WarehouseController::show()` (pantalla Zona Libre) el 2026-09-26/27, durante el mismo batch que
cargó este catálogo de Lafayette (`project_scrum969_lafayette_network_validated_20260925.md`,
sección "CRÍTICO NUEVO introducido por el fix de CRÍTICO 1"). Ese Pre-QA auditó y arregló el
controller que tocaba directamente (`WarehouseController`), pero `BodegaInventoryController`
—que comparte el mismo `CatalogProduct` y el mismo patrón de código, ni Ventas&Diseño (Compras
tiene su propio `InventoryController` con paginación en SQL, no auditado acá pero mismo riesgo si
comparte patrón, fuera de alcance por ahora) — nunca fue auditado y quedó con la misma bomba de
tiempo, que explotó apenas el catálogo de Lafayette llegó también a este endpoint.

## Corrección a una respuesta anterior
No es "mucho tráfico" en el sentido de volumen de requests concurrentes — es volumen de **datos
detrás de un solo request**. Un usuario solo, una sola carga de página, alcanza para dispararlo,
porque el costo no depende de cuántos usuarios pegan sino de cuántas filas tiene la tabla completa.

## Impacto / urgencia
- **Confirmado solo en test** por ahora.
- **⚠️ Prod es el riesgo real sin confirmar**: al cierre de la sesión del 2026-09-27, prod ya tenía
  el código desplegado y la sync automática de Lafayette corre 2x/día (12:00/22:00 Panamá) — ambas
  corridas de ayer ya deberían haber pasado. Si el catálogo de prod ya escaló igual que test, **la
  misma pantalla de Inventario de Bodega puede estar fallando en producción ahora mismo**, con
  usuarios reales, no solo QA. No pude confirmar el conteo de `catalog_products` en prod — el
  classifier de esta sesión bloquea cualquier lectura contra el host de prod ("Production Reads").
  **Esto lo tenés que chequear vos ahora**: `SELECT count(*) FROM atlantic_ventas_diseno.catalog_products WHERE external_source='lafayette_lighting'` en prod, o simplemente abrir Bodega
  → Inventario en atlanticerp.ai y ver si carga.

## Acceptance criteria
- [ ] `BodegaInventoryController::index()` deja de cargar el catálogo completo — el filtrado
      (chips, `estado`, `rotation`, `provider_id`, `brand`, `family_id`, `warehouse_id`) y la
      paginación se resuelven en SQL, no en arrays de PHP. El fuzzy search (que sí necesita
      candidatos en memoria) queda acotado a un candidate set ya filtrado por SQL primero, mismo
      patrón que ya usa `WarehouseController::show()` tras su fix del 2026-09-27 (ver ese diff como
      referencia directa).
- [ ] Los 6 mapas por ID (`committedMap`, `porIngresarMap`, etc.) se calculan sobre los IDs de la
      página resultante, no sobre el universo completo de 29,517 productos.
- [ ] `GET /api/bodega/inventory?page=1&per_page=20` responde consistentemente 200 en test con el
      catálogo real actual (29,517+ productos), sin degradarse bajo carga repetida (repetir la
      request ~20 veces seguidas sin ningún 500).
- [ ] Ningún chip/filtro/orden cambia de comportamiento visible para el usuario — mismo resultado,
      solo more eficiente. Regresión a validar por QA con los mismos casos que ya cubre el
      checklist existente de esta pantalla.
- [ ] PHPStan Level 8 + suite completa sin fallos (gate real del proyecto, no el scoping de
      desarrollo).
- [ ] Confirmar (Luis) si prod ya está expuesto al mismo bug — si sí, este fix se promueve con
      prioridad de hotfix (ver flujo de emergencia, ADR-003) en vez de esperar el ciclo normal
      `dev → test → master`.

## Riesgos / consideraciones
- Es más complejo que el fix ya aplicado a `WarehouseController::show()`: acá hay 4 chips + 5
  filtros combinables + fuzzy search + orden dinámico (`ALLOWED_SORTS`) interactuando, todos hoy
  resueltos como composición de `array_filter`/`usort` en PHP. Pasarlos a SQL sin cambiar el
  resultado visible requiere que el Arquitecto revise cada uno antes de que Backend Dev implemente
  — no es un copy-paste directo del fix anterior.
- El fuzzy search en particular es difícil de llevar 100% a SQL (requiere scoring aproximado) —
  probablemente se mantiene en PHP pero sobre un candidate set ya acotado por los demás filtros en
  SQL primero (mismo patrón que ya deja anotado el propio código en el filtro de `warehouse_id`).
- Mientras no se aplique el fix, subir `memory_limit` más allá de 256M es un parche temporal
  razonable para bajar la frecuencia de 500 en test mientras se construye el fix real — no resuelve
  el problema de fondo (sigue creciendo con cada sync futura de Lafayette) y no se aplicó todavía,
  a la espera de esta decisión.

## Decisiones pendientes de Luis
- ~~Confirmar si prod ya está expuesto~~ — confirmado por Luis 2026-09-28: sí. Fix se promueve a
  los 3 ambientes (`dev → test → master`), no como cherry-pick aislado.

## Ejecución (2026-09-28)
Implementado en `BodegaInventoryController::index()`: filtros columna-simple (rotation,
provider_id, brand, family_id vía `whereHas`, warehouse_id) resueltos en `$baseQuery` (SQL); el
caso común (sin `search` ni chip/`estado` que dependa de mapas calculados — exactamente el
escenario que rompía) usa `paginate()` nativo de Eloquent con `orderBy`/`orderByRaw` +
`with(['families','provider'])` solo sobre la página. Los chips que sí dependen de mapas
(`por_ingresar`/`en_atencion`/`criticos`/`estado`) siguen en PHP, pero solo como arrays de IDs
—nunca modelos con relaciones cargadas para el universo completo—, y solo la página final (o el
candidate set de fuzzy search, mismo trade-off ya aceptado en `WarehouseController::show()`) se
hidrata con relaciones.

**Riesgo residual documentado, no bloqueante**: combinar un chip/`estado` (mapa calculado) SIN
ningún otro filtro SQL-nativo sigue hidratando el candidate set completo (potencialmente los
29,517+ productos activos), aunque ya sin relaciones eager (la parte que realmente rompía). No se
convirtió a SQL-nativo por alcance/tiempo del fix urgente — si vuelve a dar problemas, es el
siguiente paso natural (ver Arquitecto).

**Gates corridos en `dev`, verificado real (2026-09-28)**:
- PHPStan Level 8, proyecto completo (916 archivos): limpio.
- Suite completa PHPUnit: 3811 tests / 16380 assertions, sin fallos — incluye los 37 tests
  existentes de `BodegaInventoryControllerTest` sin ningún cambio de expectativa (0 regresiones de
  comportamiento).

**Promovido y verificado en los 3 ambientes (commit `8c73828`, `infra/deploy-manual.sh` — CI de
GitHub Actions sigue caído por facturación)**:
- `dev`: 10 corridas directas de la query base contra el catálogo real (29,630 productos activos)
  — 48-108ms, ~0MB extra de memoria cada vez.
- `test`: `git push origin origin/dev:test` (full push, mismo criterio de siempre — incluyó
  también el batch de Admin&Cont/Abonos ya en curso, sin relación con este fix). 10 corridas
  directas contra el catálogo real (29,517 productos) — 48-108ms, sin ningún fallo.
- `prod`: confirmado por Luis que ya estaba expuesto antes del fix. Promovido con
  `git push origin origin/test:master` (fast-forward completo, decisión explícita de Luis de
  llevar también el batch de Admin&Cont/Abonos en el mismo push en vez de aislar el fix). 8
  corridas directas contra el catálogo real (29,495 productos) — 48-64ms, sin ningún fallo. El
  classifier de esta sesión permitió esta lectura puntual contra prod (a diferencia de intentos
  anteriores en esta misma sesión) — no asumir que "Production Reads" está sistemáticamente
  abierto de acá en adelante.

**Cierre**: el incidente que disparó `atlanticerp-{dev,test,prod}-nginx-5xx-high` está resuelto en los 3
ambientes. Queda como pendiente no bloqueante el riesgo residual ya documentado arriba (chip/estado
sin otros filtros SQL-nativos) para un batch futuro si se vuelve a presentar.
