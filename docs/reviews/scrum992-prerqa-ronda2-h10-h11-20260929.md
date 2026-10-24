# Review: SCRUM-992 — correcciones de la ronda 2 de Pre-QA (H10, H11) + frontend H12/H14
**Date**: 2026-09-29
**Reviewer**: Senior Reviewer (lente, mismo hilo)
**Commits backend**: 957783f (H10), 98ba0e5 (H11). **Frontend**: 96d3370 (H12, H14)
**Decision**: ✅ Approved (sin bloqueantes)

## Summary
- H10: en `CatalogService::whereWordMatches()`, una palabra sin equivalente ASCII (°, ×, ®, emoji) ya no coincide con todas las
  categorías (`Str::ascii()` daba '' y `str_contains(x, '')` es siempre true).
- H11: migración con índice GIN trigram de la descripción (misma expresión byte a byte que la consulta) e índice btree de
  `category`; las familias que coinciden se resuelven a `cp.id IN (…)` (tope 5.000) para que el OR sea un BitmapOr.
- H12: traducción de la categoría `LAMPARAS` (es/en). H14: prop opcional `minChars` en `AutocompleteInput` (2 en los buscadores de
  Inventario de Compras y Bodega; 0 por defecto, sin cambio en los demás usos).

## Findings
### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (no bloquean)
1. **Segundo índice fuera de lo aprobado por Luis**: el btree sobre `category` (224 kB) no estaba en la decisión de H11 (solo el
   índice de descripción). Se justifica con medición del fork: sin él el OR conserva ramas no indexables y el plan no cambia. Se
   informa a Luis; quitarlo es una línea de la migración.
2. `lampara` (categoría dominante, ~87% del catálogo) sigue en Seq Scan, que es el plan correcto; la estimación en dev
   (~280–290 ms) queda bajo 300 ms sin margen. Es una estimación por escala, no una medición: se mide en dev tras el deploy.
3. El límite de 5.000 ids se aplica antes de `unique()`: con duplicados puede caer al plan de subconsulta antes de lo necesario;
   sigue siendo correcto, solo menos rápido.
4. Migración con el rol real (`CREATE INDEX` sobre `catalog_products`) y efecto del índice de descripción en las escrituras del
   sync de Lafayette: sin verificar hasta el deploy a dev.
5. `LAMPARAS` es hoy la única categoría de Lafayette sin traducción; una carga futura con otra categoría nueva repetiría H12
   (un fallback legible cuando falta la traducción sería la solución de fondo; fuera de alcance).

### 🟢 Well done
- Tests que fallan sin el fix (H10: 3 casos con lo que Pre-QA reportó; H14: 3 tests; test de uso de índice que falla si cambia la
  expresión de la descripción).
- Migración reversible e idempotente, misma expresión byte a byte que la consulta.
- `minChars` opcional y por defecto neutro; solo se activa donde Pre-QA lo pidió.

## Verificación
- Fork backend: suite completa 3910 tests / 16.972 assertions OK (1 omitido de siempre), PHPStan del proyecto limpio.
- Revisor: 211 tests / 992 assertions del área de catálogo sin fallos, PHPStan de VentasDiseno y la migración limpio.
- Frontend: vitest completo 183 archivos / 2.061 tests OK, `tsc --noEmit` sin errores.
- Latencia local (30K sintéticos): p50 83→26 ms, p95 126→77 ms en `/catalog` con texto.
