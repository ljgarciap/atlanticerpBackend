# Spec — Familias de productos (SCRUM-129/REQ-037) y Alerta de stock insuficiente (SCRUM-131/REQ-039)

**Módulo:** VentasDiseno (Cotización)
**Status:** Implementada 2026-07-13
**Fecha:** 2026-07-13
**Autor:** Analista + Arquitecto + Backend Dev + Frontend Dev (roles del equipo, ver `atlanticerp/CLAUDE.md`)

---

## SCRUM-131 (REQ-039) — Alerta de stock insuficiente

### Problema
El vendedor/diseñador pide más unidades de un producto de catálogo de las que hay en
`stock_quantity` — hoy no hay ninguna señal visual, y no debe bloquear la cotización.

### Diseño
No requiere cambios de modelo de datos — `catalog_products.stock_quantity` ya existía (sembrado
2026-07-13 con REQ-036), nunca se había expuesto en el flujo de ítems de cotización.

- **Backend:** `stock_quantity` se agrega a la respuesta de `QuoteItemController::format()`
  (store/update) y `QuoteController::formatDetail()` (detalle de cotización), leído vía la
  relación `QuoteItem::catalogProduct()` — precargada en `QuoteService::DETAIL_RELATIONS`
  (`parts.items.catalogProduct`) para evitar N+1. `null` = sin control de stock, no dispara
  alerta. Ítems personalizados (`is_custom=true`) siempre `null` — no tienen producto de catálogo
  asociado. Ninguna validación de request cambia — la cantidad sigue sin tope server-side,
  consistente con "no debe bloquear la cotización".
- **Frontend:** `QuotePartCard.tsx` — el draft del ítem (`ItemDraft`) ahora carga `stock_quantity`
  al seleccionar un producto de catálogo. Helper `stockShortage(draft)` compara `quantity` vs
  `stock_quantity` y retorna el faltante (o `null` si no aplica). Mensaje
  `catalog.insufficientStock` (i18n, interpolado con `{{count}}`) se muestra debajo del campo
  cantidad, tanto en el formulario de alta de ítem nuevo como en la fila de edición de un ítem ya
  guardado — estilo ámbar, no bloqueante (el botón Agregar/Guardar sigue habilitado).

### Criterios de aceptación
- [x] Cantidad > stock disponible → mensaje debajo del producto con las unidades que faltan.
- [x] La cotización se puede generar con normalidad (nada bloquea el submit).

---

## SCRUM-129 (REQ-037) — Familias de productos

### Problema
El concepto de "familia de productos" (agrupación curada para agregar de una vez, ej. "Kit Baño")
**no existía en el modelo de datos** — quedó fuera de alcance cuando se construyó el Catálogo real
(REQ-036/038, ver `memory/project_backlog_ventas_diseno_req.md`).

### Decisiones (confirmadas por Luis, 2026-07-13)
1. **Origen de datos:** sin pantalla de administración en esta iteración (mismo precedente que
   `catalog_products`) — se siembra por seeder con datos demo curados a mano
   (`CatalogProductFamilySeeder`, 3 familias sobre los 10 productos ya sembrados por
   `CatalogProductSeeder`, ejecuta después en `DatabaseSeeder`). Si el cliente pide administración
   real más adelante, es una pantalla nueva (mismo patrón que `RolesPage`), no un cambio de este
   modelo.
2. **Alta en lote:** endpoint nuevo `POST /quotes/{id}/parts/{id}/items/bulk` (en vez de N llamadas
   secuenciales al `POST .../items` existente) — una sola operación, atómica por request, más
   prolija desde el frontend.

### Modelo de datos (nuevo)
- `catalog_product_families` (id, name, description nullable, is_active, timestamps).
- `catalog_product_family_items` (pivot N:M — un producto puede pertenecer a más de una familia,
  ej. un spot compartido entre "Kit Baño" y "Promoción Verano"; FK explícitamente calificadas por
  schema, `unique(family_id, catalog_product_id)` nombrado explícito por la convención de
  truncamiento de Postgres — ver `memory/feedback_tenant_migration_constraint_name_truncation.md`).
- Migración: `2026_07_19_100004_create_ventas_diseno_catalog_product_families_table.php`.

### Backend
- `CatalogProductFamily` (modelo) — `products(): BelongsToMany`, mismo patrón que
  `Quote::contacts()` (pivot schema-calificado explícito).
- `CatalogProductFamilyController` — `GET /catalog-product-families` (lista con
  `products_count`), `GET /catalog-product-families/{id}` (detalle, ficha de cada producto sin
  `cost`, mismo criterio que `CatalogProductController`). Permiso `ventas_diseno.read`.
- `QuoteItemController::bulkStore()` — recibe `catalog_product_ids: int[]`, reusa
  `resolveItemData()`/`catalogProductExistsInPart()` por cada uno; los ya existentes en la
  partida se **saltean sin fallar el resto** (AC3: "sin duplicar los que ya estaban"), a
  diferencia de `store()` que sí retorna 422 para un ítem individual duplicado. `quantity` fija en
  1 por producto — el usuario ajusta después en la partida. Permiso `ventas_diseno.edit`.

### Frontend
- `CatalogProductPicker` (dentro de `QuotePartCard.tsx`) — tabs "Buscar"/"Familias" (nuevo estado
  `mode`). Tab Familias: lista → detalle (ficha de productos, sin cantidad/precio editable) →
  botón "Seleccionar productos" dispara `bulkCreateItemMutation` directo (no pasa por el draft de
  "nuevo ítem", a diferencia de la selección individual — no hay cantidad/precio que confirmar por
  producto).

### Criterios de aceptación
- [x] Tab "Familias" lista cada familia con su cantidad de productos.
- [x] Seleccionar una familia muestra el detalle con todos sus productos antes de confirmar.
- [x] Confirmar "Seleccionar productos" agrega todos como líneas separadas, sin duplicar los ya
      existentes en la partida.

---

## Verificación

725→741 tests backend (13 nuevos: 6 `CatalogProductFamilyControllerTest` + 7 en
`QuoteItemControllerTest`), PHPStan Level 8 limpio, 227→233 tests frontend (6 nuevos en
`QuotePartCard.test.tsx`), `tsc --noEmit` y `npm run build` limpios. Migración corrida y
verificada localmente (`tenants:artisan migrate`), seeder verificado vía `db:seed --force` real
(no solo tinker manual). Smoke test en navegador real (Playwright, login real, cotización nueva
con partida): alerta de stock visible ("7 units short of stock", ámbar, no bloqueante) y alta en
lote de familia completa (3 productos de "Riel Direccionable" agregados como líneas separadas,
subtotal correcto) — sin errores de consola.

**Nada commiteado ni pusheado** — Senior Review + OK explícito de Luis primero
([[feedback_push_workflow]]).

## Fix del Senior Review (2026-07-13)

Primera pasada: **CAMBIOS REQUERIDOS** — 1 blocker: `QuoteItemController::bulkStore()` no estaba
envuelto en `DB::transaction()`, contradiciendo el propio diseño ("atómica por request") y el
patrón establecido en `QuoteService` (todo método de escritura múltiple del módulo ya usa
transacción). Corregido envolviendo el loop en `DB::transaction()`.

También resueltas 2 de las 4 sugerencias 🟡 (las que el propio reviewer recomendó cerrar en la
misma iteración):
- **FK sin nombre explícito** en la migración — el nombre autogenerado por Laravel (con schema
  calificado) superaba los 63 bytes de Postgres y truncaba en silencio. Nombradas explícitas
  (`catalog_product_family_items_family_fk`/`..._product_fk`), migración re-corrida localmente y
  verificado el nombre real de constraint contra `pg_constraint`.
- **Test de ownership faltante** — agregado `test_bulk_en_cotizacion_ajena_retorna_403`.

Sugerencia adicional resuelta (no pedida explícitamente pero contenida): botón "Seleccionar
productos" ahora se deshabilita si la familia no tiene productos activos (antes mandaba un array
vacío que el backend rechazaba con 422 sin prevenirlo en el cliente), con test frontend nuevo.

**Sugerencia no resuelta a propósito:** extraer `bulkStore()` a un `FormRequest` dedicado —
calificada por el reviewer como "cuestión de convención, no incorrecto tal como está", no pedida
para cerrar esta iteración.

**Verificado de nuevo:** 742/742 tests backend (+1 test de ownership), PHPStan Level 8 limpio,
234/234 tests frontend (+1 test de botón deshabilitado), `tsc`/`build` limpios.

**Hallazgo colateral, no relacionado a esta feature:** `CatalogProductControllerTest::
test_ficha_tecnica_nunca_incluye_el_costo` asume que el producto creado por el test queda en
`data.0` — es frágil si `catalog_products` tiene datos reales de otro origen (ej. demo data ya
sembrada vía `db:seed`), porque el listado ordena por `description` y no filtra por lo que el test
creó. Se disparó 2 veces en esta sesión al correr `db:seed --force` real contra la BD local
persistente para el smoke test (ver [[feedback_local_test_db_no_es_fresh]]) — no es una regresión
de este cambio, es deuda preexistente. No se tocó (fuera de alcance de este ticket), queda anotado
para quien lo encuentre de nuevo.
