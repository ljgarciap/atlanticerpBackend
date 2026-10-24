# Review: SCRUM-994 — Inventario de Bodega: KPIs y filtros calculados en SQL
**Date**: 2026-09-29
**Reviewer**: Senior Reviewer (lente, mismo hilo)
**Commit**: f144f08
**Decision**: ✅ Approved (sin bloqueantes), con verificación obligatoria en dev

## Summary
`BodegaInventoryKpiService::kpis()` pasa a un solo SELECT agregado (COUNT FILTER + SUM del compromiso solo de productos activos) y
`BodegaInventoryController::index()` elimina la rama PHP de filtros calculados: los chips `por_ingresar`, `en_atencion` (allowlist
alta/media de Bodega) y `criticos`, y el filtro `estado`, son `WHERE` sobre expresiones SQL; una sola ruta `paginate()`; relaciones
solo para la página; `warehouse_id` como subconsulta. `InventoryKpiService` de Compras no se modificó: el compromiso de Bodega
(`committedSub()`) se une con el alias `inv_ps` que esperan `sqlDisponible/sqlSinStock/sqlBajoStock`, así que la fórmula
`disponible = stock - por servir - en excluida` sigue existiendo una sola vez. Cumple la spec aprobada
(`docs/specs/bodega-inventory-kpis-and-computed-filters-sql.md`).

## Findings
### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (no bloquean)
1. **El 500 original no se reprodujo en el stack de test** (`memory_limit=512M` allí; 256 MB en FPM): lo guarda el test de hidratación y
   memoria. Debe confirmarse en dev con `chip=criticos` y `estado=sin_stock`, que hoy dan 500.
2. **Estadísticas de Postgres**: con la tabla recién sembrada y sin `ANALYZE`, un caso combinado tardó 759 ms (Nested Loop mal
   estimado); con `ANALYZE` bajó a 79 ms. En dev/test/prod tras una carga masiva comprobar que el autovacuum ya analizó
   `catalog_products`, o el p95 puede ser peor que el medido.
3. **Cambio de comportamiento aceptado por Luis**: con un chip calculado o `estado`, el orden por nombre/referencia pasa de
   `strcasecmp` a `LOWER()` (el que ya tenía el camino común).
4. Dos definiciones de "por servir" y dos de "En atención" (Compras vs Bodega) conviven: la de Bodega vive en
   `BodegaInventoryKpiService` (`sqlEnAtencionBodega()`), junto a `isInAttentionBodega()` que se conserva; el test de paridad falla si divergen.
5. Fuera de alcance, sin medir: `AdjustmentRequestController`, `BodegaHomeService::articulosCriticos`, `CatalogPdfService`, "Buscar
   Sustituto" (SCRUM-995).

### 🟢 Well done
- 54 tests de paridad con la lógica PHP original como oráculo (todos los chips y estados, los 5 KPIs incluido `total_por_servir` solo de
  activos, `BodegaReportsService::inventorySummary()` = `kpis()`, recorrido de todas las páginas, 8 órdenes, combinaciones de filtros,
  hidratación y memoria con 5.000 productos); 9 fallan contra el código anterior; 8 mutaciones, todas detectadas tras reforzar 2.
- Sin migración, sin cambio de frontend, mismo shape de respuesta; los 96 tests previos de Bodega pasan sin ajustes.

## Verificación
- Fork: suite completa 3.991 tests / 17.378 assertions OK (1 omitido de siempre), PHPStan del proyecto limpio.
- Revisor (independiente): 695 tests / 3.350 assertions (Bodega, inventario, reportes, almacenes) sin fallos; PHPStan de Bodega y Compras limpio.
- Local (30K sintéticos): sin filtros 786→35 ms (+157→+1 MB), `chip=criticos` 1.747→51 ms (+206→0 MB), `estado=sin_stock` 1.714→56 ms,
  `criticos+search+sort` 1.145→79 ms; 15 variantes con 200 y totales idénticos.

## Pendiente
Medir en dev con el catálogo real (hoy: `criticos` y `estado=sin_stock` = 500; sin filtros 1,5–2,1 s) y Pre-QA completo.
