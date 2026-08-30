# Senior Review + Pre-QA — SCRUM-237 (REQ-174), cierre de gaps de alcance

**Fecha:** 2026-07-19
**Alcance:** los 2 gaps de alcance detectados al re-revisar SCRUM-237 (Detalle del producto,
modo Compras) contra el Excel de requerimientos original (`2__Requerimientos_Compras.xlsx`,
adjunto en el ticket): familia editable en el modal, y desglose de costo (importación/flete/
manejo/otros). El tercer gap (código de barras) se descopeó por decisión de Luis — cambio de
esquema sin prioridad actual, queda como ticket futuro.

## Qué se construyó

1. **Migración** `2026_07_21_100016_add_cost_breakdown_to_ventas_diseno_catalog_products_table.php`
   — agrega `import_cost`/`freight_cost`/`handling_cost`/`other_cost` (decimal 12,2, default 0) a
   `catalog_products`.
2. **`CatalogProduct::costTotal()`/`marginPercent()`** — única fuente de verdad del margen; ya
   había 5 copias de la fórmula `(price_full - cost) / price_full` dispersas en el código
   (`InventoryController::format()`, `ReplacementRequestController::computeMarginPercent()`,
   `QuoteItem::violatesMinMargin()` — esta última con dato propio, no tocada — y el cálculo en
   vivo del frontend). Las 3 que sí leen `catalog_products.cost` ahora usan el método del modelo.
3. **`CatalogProduct::families()`** (N:M inversa, antes solo existía `CatalogProductFamily::products()`)
   — no existía ningún endpoint para asignar familia a un producto; `InventoryController::update()`
   ahora acepta `family_id` (selector único en el modal, sincroniza el pivot vía `sync()`) — el
   modelo de datos sigue siendo N:M por si otro flujo asigna varias familias a futuro.
4. **`GoodsReceiptController`** (REQ-164) — el prorrateo de gastos de envío para proveedor no
   local ya no se pliega en un solo `cost`: cada uno de los 4 totales del envío se reprorratea por
   línea y se persiste en su propia columna, reemplazando el estimado manual anterior (RN de
   REQ-166). Proveedor local no cambia — ITBMS se sigue plegando en `cost`, las 4 columnas quedan
   en 0 (REQ-164: "no aplican los campos de gastos de envío" para local).
5. **Frontend** (`InventarioPage.tsx`) — sección "Precios" con los 4 campos nuevos + Costo Total
   en vivo; sección "Información general" con selector de familia (reusa
   `ventasDisenoApi.catalogProductFamilies.list()`, ya existente para la pestaña Familias).

## Hallazgo real corregido durante el review

`ReplacementRequestController::computeMarginPercent()` usaba `cost` puro para decidir si una
solicitud de reemplazo escala a Gerencia (umbral `replacement_margin_threshold`). Con un producto
no local que ya tuvo un Ingreso de Mercancía, `cost` deja de incluir el flete/manejo prorrateado
(ahora vive en las columnas nuevas) — de no migrar este cálculo a `costTotal()`, el margen
calculado habría quedado inflado (más alto de lo real) y solicitudes que debían escalar a
Gerencia habrían quedado aprobadas de más. Corregido antes de pushear, no en una vuelta posterior.

## Verificación

- Backend: 978/978 (`infra/test.sh`), PHPStan Level 8 limpio. 3 tests nuevos
  (`InventoryControllerTest`: desglose recalcula costo total/margen, asignar/quitar familia,
  family_id inexistente → 422) + 1 test existente actualizado (`GoodsReceiptControllerTest`,
  antes esperaba el prorrateo plegado en `cost`, ahora verifica el desglose por componente).
- Frontend: 367/367 (`npx vitest run`), `tsc --noEmit` limpio. 2 tests nuevos en
  `InventarioPage.test.tsx` (margen en vivo con desglose, family_id al guardar).
- Verificado en vivo contra Docker local (tinker): `costTotal()`/`marginPercent()` con y sin
  desglose, `families()->sync()` asignar y quitar con una familia real.

## Gaps de alcance NO corregidos (documentados, no bloqueantes)

- **Código de barras** — la RN lo pide editable pero no existe la columna en `catalog_products`;
  descopeado por decisión de Luis (2026-07-19), agregar en un ticket futuro si se prioriza.

Listo para pasar a QA en cuanto se pushee.
