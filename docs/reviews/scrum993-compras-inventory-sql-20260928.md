# Review: SCRUM-993 — inventario de Compras paginado en SQL
**Date**: 2026-09-28
**Reviewer**: Senior Reviewer (lente, mismo hilo)
**Commit**: 41fbff2
**Decision**: ✅ Approved (sin bloqueantes)

## Summary
`InventoryController::index()` y `InventoryKpiService::kpis()`/`lowStockWithoutOpenOrderCount()` dejan de
hidratar el catálogo completo (~30K productos). Agregados por producto como subconsultas SQL, chips/orden/
paginación en SQL, relaciones solo para la página. Cumple el diseño de la spec
(`docs/specs/compras-inventory-full-catalog-load-500.md`).

## Findings
### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (no bloquean)
1. **Fuzzy sigue siendo O(N) por request**: recorre las ~30K filas (proyección `id, reference, name`, sin
   modelos) en cada búsqueda con texto. Ya no revienta memoria, pero la latencia de la búsqueda no mejora
   hasta SCRUM-992 (`scopeSearch()` con trigram). No medida.
2. **`whereIntegerInRaw('cp.id', $matched)`** con una búsqueda muy amplia puede mandar decenas de miles de IDs
   en el SQL. Aceptable hoy; desaparece con SCRUM-992.
3. **Orden de texto**: `LOWER() COLLATE "C"` replica `strcasecmp` en ASCII. Con acentos/Unicode, PHP y Postgres
   pueden ordenar distinto (el test cubre solo ASCII). Riesgo bajo: referencias son mayormente ASCII; el nombre
   puede tener acentos. Pre-QA debe revisar un orden por `name` con acentos.
4. `BodegaInventoryController` y `WarehouseController` siguen usando los `*Map()` con listas de IDs (ya
   paginados en SQL por 8c73828/5c3ab15). Fuera de alcance; anotado.

### 🟢 Well done
- Una sola fuente de la regla de stock (`sql*()` en `InventoryKpiService`) usada por chips, `kpis()` y el conteo
  de Gerencia, con test de **paridad SQL vs PHP** sobre todos los bordes (disponible = 0, = reorder_point,
  rotación baja/null, bodega excluida, orden recibida vs abierta, registrado > esperado).
- Test de regresión que falla si `index()` vuelve a hidratar más modelos que la página.
- `kpis()` y `lowStockWithoutOpenOrderCount()` también corregidos (beneficia Gerencia, Home de Compras, Reportes).
- Shape de la respuesta sin cambios.

## Verificación
- Fork: suite backend completa 3826 tests OK, PHPStan Level 8 en 916 archivos sin errores.
- Revisor (independiente): `infra/test.sh --filter="InventoryKpiServiceSqlParity|Inventory|Gerencia|ComprasHome|Reportes"`
  198 tests / 931 assertions OK; PHPStan `app/Modules/Compras` limpio.
- Medición del fork (30.000 productos sintéticos, stack de test): 20 modelos hidratados, +1,5 MB de pico, 83 ms.

## Pendiente antes de cerrar el ticket
- Verificar en dev con el catálogo real (Lafayette) tras el deploy: sin 5xx en `/api/compras/inventory`, y la
  alarma `atlanticerp-dev-nginx-5xx-high` sin dispararse por esta ruta.
- Pre-QA: 7 chips × 5 órdenes sobre 30K productos, incluido orden por `name` con acentos.
