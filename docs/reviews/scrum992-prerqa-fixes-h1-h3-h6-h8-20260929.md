# Review: SCRUM-992 — correcciones del Pre-QA (H1, H3, H6, H8)
**Date**: 2026-09-29
**Reviewer**: Senior Reviewer (lente, mismo hilo)
**Commits**: 5a25178 (H8/H6), 9166098 (H3), baad790 (H1)
**Decision**: ✅ Approved (sin bloqueantes)

## Summary
Cierra los 4 hallazgos que Pre-QA devolvió a PM (`docs/pre-qa/scrum993-992-catalog-inventory-20260929.md`), con el alcance
que aprobó Luis. H1 es el cambio grande: `CatalogService::list()` (pantalla Catálogo de Ventas&Diseño,
`GET /api/ventas-diseno/catalog`) deja de hidratar ~30K productos.

## Findings
### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (no bloquean)
1. **Cambios de comportamiento de la búsqueda de `/catalog`** (documentados en el código): varias palabras se buscan en
   cualquier orden (antes: frase contigua sobre el texto concatenado, que cruzaba campos por accidente), sin distinguir
   acentos, y orden por nombre con desempate por `id`. Pre-QA debe confirmarlos con usuarios reales de Ventas.
2. La descripción se compara con `f_unaccent(lower(description)) LIKE`, fila por fila (sin índice): ~decenas de ms con 30K
   filas, aceptable; si el catálogo crece un orden de magnitud, valdría incluirla en `search_text`.
3. Las familias y categorías se resuelven una vez por palabra al construir la consulta (optimización del fork). Verificar con
   familias de nombre con acentos en dev (el fork no lo probó).
4. `per_page=all` sigue acotado a 1000 (`Pagination::MAX_ALL`), igual que antes.

### 🟢 Well done
- Un test de paridad con 23 combinaciones de filtros, modo demo y dropdowns, más un test de hidratación que falla si
  se cargan más modelos que la página.
- H8: convención única (`QueryParams::string`) y test que falla sin el fix (verificado restaurando los controladores).
- H6: `page` acotada a 1.000.000 sin cambiar ningún valor válido.
- H3: regla explícita (palabras de 4+ caracteres alfanuméricos, máx. 5 palabras y 200 candidatos, `word_similarity` una vez).

## Verificación
- Fork: suite completa 3903 tests / 16.927 assertions OK (1 omitido deliberado), PHPStan de 917 archivos limpio.
- Revisor (independiente): filtro de catálogo/paginación/búsqueda/paridad, 213 tests / 1035 assertions, 0 fallos;
  PHPStan de VentasDiseno, Shared y Bodega/Services limpio.
- `/catalog` con 30K sintéticos (kernel, local): 13–93 ms y ≤ 7,6 MB extra (antes 2,6–4,3 s y ~176 MB en dev).

## Pendiente
Medir `/catalog` en dev con el catálogo real tras el deploy; Pre-QA completo otra vez (no solo lo corregido).
