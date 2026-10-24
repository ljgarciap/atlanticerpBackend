# Review: SCRUM-992 (backend Fase 1) — búsqueda de catálogo con pg_trgm + unaccent
**Date**: 2026-09-29
**Reviewer**: Senior Reviewer (lente, mismo hilo)
**Commits**: ca4f9f6 (migración + scope), 207b316 (adopción en 4 buscadores)
**Decision**: ✅ Approved (sin bloqueantes), con verificación obligatoria de la migración en dev

## Summary
Columna `search_text` GENERATED STORED con índice GIN trigram, `CatalogProduct::applySearch()`/`orderByRelevance()`/
`scopeSearch()`, adoptado en Nueva Orden de Compras, Inventario de Compras, Inventario de Bodega y el buscador de
Ventas&Diseño (este sin respaldo de typos, SCRUM-796). Fase 2 (resto de call sites) sin tocar.

## Findings
### 🔴 Blockers
Ninguno.

### 🟡 Suggestions
1. **Migración con el rol real**: nunca probada con `DB_MIGRATE_USERNAME` de dev/test/prod. Necesita `CREATE EXTENSION`
   (trusted desde PG13) y `CREATE FUNCTION` en `public`. Se verifica en el deploy a dev ANTES de promover; el plan de
   contingencia (superusuario crea las extensiones una vez) está en el docblock.
2. `ALTER TABLE ... ADD COLUMN ... GENERATED STORED` reescribe la tabla y toma ACCESS EXCLUSIVE; medido 112 ms con 30K
   filas sintéticas. Confirmar en dev con el catálogo real y que el sync de Lafayette no se degrada.
3. **Cambios respecto al diseño (aceptados)**: respaldo con `word_similarity() >= 0.5` por palabra (el operador `<%` a 0.6
   dejaba fuera typos comunes), flag `fuzzy` solo true si el respaldo encontró algo, topes de 100 resultados en Nueva Orden y
   en el listado vacío del catálogo de Ventas&Diseño (antes sin tope). Los topes son cambio visible de comportamiento: Pre-QA
   debe confirmar que ninguna pantalla depende de recibir todo el catálogo del proveedor.
4. El índice no se usa con el planner por defecto a 30K filas (prefiere Seq Scan por costo); se demostró con
   `enable_seqscan=off`. La ganancia real viene de quitar el Levenshtein/la carga completa en PHP. Aceptable a este volumen.
5. `CREATE INDEX` sin `CONCURRENTLY`: bloqueo de escritura de fracciones de segundo a 30K filas, documentado.

### 🟢 Well done
- Normalización del término con la misma función SQL que la columna (nunca una transformación equivalente en PHP).
- Escape de `%`, `_` y `\` en los LIKE; SQL parametrizado.
- Migración idempotente y `down()` que no elimina lo compartido entre tenants.
- Cobertura: acentos, mayúsculas, exacta primero, respaldo solo con 0 resultados, término corto, índice, búsqueda tras editar.

## Verificación
- Fork: suite completa 3845 tests / 16556 assertions OK, PHPStan de todo el proyecto limpio.
- Latencia p95 (30K sintéticos, HTTP completo): Ventas&Diseño 15 ms, Nueva Orden 16 ms, Inventario Compras 28 ms (246 ms con
  respaldo de typos), Bodega ~600 ms (dominado por `BodegaInventoryKpiService::kpis()`, no por la búsqueda).

## Hallazgo fuera de alcance
`BodegaInventoryKpiService::kpis()` hidrata el catálogo activo completo en cada `GET /api/bodega/inventory`
(600 ms / 264 MB de pico medidos en el stack de test). Mismo patrón que SCRUM-993. Ticket aparte.
