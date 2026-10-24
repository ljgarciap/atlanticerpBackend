# Review: SCRUM-992 — correcciones de la ronda 3 de Pre-QA (H17, H18)
**Date**: 2026-09-29
**Reviewer**: Senior Reviewer (lente, mismo hilo)
**Commits**: 6180994 (H17), 95bd49d (H18)
**Decision**: ✅ Approved (sin bloqueantes), con medición obligatoria en dev

## Summary
- H17: las categorías coincidentes de `/catalog` se resuelven en Postgres (`VALUES` + `public.f_unaccent(lower(replace(v,'_',' ')))` sobre
  los ~9 slugs del dropdown) en vez de `Str::ascii()` de PHP: una sola normalización en toda la búsqueda.
- H18: el respaldo de typos usa el operador indexable `palabra OPERATOR(public.<%) search_text` con
  `pg_trgm.word_similarity_threshold = 0.5` (mismos resultados que `word_similarity() >= 0.5`), ejecutado con
  `random_page_cost = 1.1` dentro de una transacción (`CatalogProduct::withFuzzySettings()`).

## Findings
### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (no bloquean)
1. **Desviación de lo aprobado en H18, informada**: se pidió `<%` con umbral 0.5; el fork demostró que con el `random_page_cost` por
   defecto (4) el planner elige Seq Scan aun con `<%` (estima 1115 vs 942) y añadió `random_page_cost = 1.1`, transaction-local.
   Es un ajuste del planner, no un cambio de resultados; queda documentado en la constante. Riesgo: el plan en dev/test/prod
   depende de sus estadísticas: se confirma con EXPLAIN y p95 tras el deploy.
2. `withFuzzySettings()` restaura los valores anteriores en `finally` (importa con transacción externa: el valor local sobreviviría
   hasta el fin de la externa) y tiene tests de no-fuga (dentro, después, tras búsqueda real y tras error).
3. Desempate por `id` en el tope de 200 candidatos: hace determinista el corte (antes dependía del orden físico).
4. Medición local con siembra sesgada (~5% de filas coinciden como typo, muy por encima de un catálogo real): con menos
   coincidencias el índice gana más; sin medir.
5. `/catalog` no tiene respaldo de typos (solo parcial): H18 no le aplica.

### 🟢 Well done
- Tests con mutaciones: los de H18 fallan (6, 2 y 1 fallos) si se quita el umbral, el `random_page_cost` o la restauración; los de H17
  fallan 7 casos sin el fix, con oráculo SQL independiente del servicio.
- Paridad exacta con la versión anterior (1500 = 1500 ids en 4 términos; 14 términos de borde de H3 incluidos).
- Un test captura la consulta real de `applySearch()` y confirma `Bitmap Index Scan` sobre `catalog_products_search_text_trgm_idx`.

## Verificación
- Fork: suite completa 3.937 tests / 17.072 assertions OK (1 omitido de siempre), PHPStan del proyecto limpio.
- Revisor: 363 tests / 1.729 assertions de catálogo, inventario y búsqueda sin fallos; PHPStan de VentasDiseno, Compras y Bodega limpio.
- Local (30K sintéticos, HTTP completo): p95 Inventario de Compras 349→59 ms, Nueva Orden 179→48 ms.

## Pendiente
Medir en dev con el catálogo real (baseline Pre-QA: 583 ms y 486 ms) y Pre-QA ronda 4 completo.
