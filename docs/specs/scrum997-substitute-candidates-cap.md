# SCRUM-997 — "Buscar Sustituto" sin texto: tope de candidatos con preselección en SQL

**Date**: 2026-09-29
**Ticket**: SCRUM-997 (auditoría SCRUM-995, sitio F) · `GET /api/compras/products?provider_id=X&original_catalog_product_id=Y` (sin `search`)
**Status**: decisión de Luis 2026-09-29 (tope de candidatos, cambio de comportamiento aceptado); el "cómo se eligen los candidatos" lo define esta spec con datos medidos — en implementación
**Relacionada**: `scrum995-catalog-hydration-audit.md`, SCRUM-771/818/246 (Comparación de Referencias)

## Problema
`CatalogProductSearchController::index` con un sustituto y sin `search` hidrata TODOS los productos del proveedor (Lafayette: 25.816 en dev; proveedor 125 en
test, 230 en prod) y `SubstituteSimilarityService::rank()` los puntúa a todos en PHP: 3,4 s, 146,6 MB y ~2,6 MB de respuesta (unos 8-11 mil candidatos
sobre el umbral de similitud). Con `search` (tope 500) o sin sustituto (tope 100) ya está acotado (0,17-0,23 s, 3-5 MB).

## Cómo puntúa `rank()` hoy (no cambia)
1. Descarta al propio original. 2. Pool = candidatos de la **misma categoría**; si no hay ninguno, todos con una penalización fija.
3. `% = w_texto × similar_text(nombre + descripción) + w_precio × cercanía(precio) + w_margen × cercanía(margen) − penalización`, con los pesos de
`ComprasSettings` (hoy 50 / 30 / 20, penalización 30, umbral mínimo 25 %). 4. Descarta lo que quede bajo el umbral y ordena de mayor a menor.
Dato del catálogo real: **los 25.816 productos del proveedor Lafayette están en una sola categoría** (`LAMPARAS`), así que el filtro de categoría no reduce nada
y la preselección debe apoyarse en texto, precio y margen.

## Diseño: preselección en SQL de 500 candidatos y el mismo `rank()` sobre ellos
Solo para "con sustituto y sin `search`" (con `search` no cambia, criterio 3 del ticket):
1. **Categoría** (misma regla que `rank()`): si el original tiene categoría y existe algún candidato de esa categoría, el pool se restringe a ella; si no, sin
   restricción (la penalización es constante y no altera el orden).
2. **Pool = unión de dos generadores baratos:** (a) los `POOL_BY_PRICE_MARGIN` = 2.500 con mayor cercanía de precio y margen al original (aritmética pura,
   ~15 ms; mismas fórmulas y pesos de `rank()`) y (b) los `POOL_BY_TEXT` = 1.000 más parecidos en texto por el índice GIN trigram de `search_text` (operador
   `%`, umbral de similitud 0,4 fijado solo para esa consulta; creado por SCRUM-992). El (b) es la garantía de que un producto casi idéntico en nombre nunca
   queda fuera aunque su precio o margen estén lejos.
3. **Puntaje combinado sobre el pool** `w_texto × 100 × similarity(nombre + descripción) + cercanías` (`similarity` de `pg_trgm`) y los
   `MAX_CANDIDATES` = 500 mejores.
4. `SubstituteSimilarityService::rank()` puntúa esos ≤ 500 con su fórmula exacta (`similar_text`) y devuelve la lista ordenada, como siempre.
Los pesos salen de `ComprasSettings::current()` (nunca constantes); el tope de 500, los pools y el umbral trigram son límites técnicos como los `MAX_*` del controlador.

## Medición (dev, catálogo real; ranking completo actual vs. preselección; originales al azar por corrida)
| Variante | SQL p50 / máx | recall@10 medio (mín) | recall@20 medio (mín) | recall@50 medio (mín) |
|---|---|---|---|---|
| Puntaje combinado sobre las 25.816 filas (referencia, 12 originales) | 356 / 471 ms | 100 % | 100 % (100 %) | 100 % (100 %) |
| Solo precio/margen 4.000 → combinado, 12 originales (1.ª ronda) | 123 / 201 ms | — | 100 % (100 %) | 100 % (100 %) |
| **Solo precio/margen 4.000 → combinado, 23 originales incl. el 691** | 152 / 209 ms | 99,6 % (90 %) | 99,8 % (**95 %**) | 99,7 % (98 %) |
| 2.500 precio/margen + 1.500 texto (umbral 0,3) | 254 / 808 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
| 2.500 + 1.500 texto (umbral 0,2) | 643 / 849 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
| 3.500 + 500 texto (umbral 0,3) | 267 / 732 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
| 3.500 + 500 texto (umbral 0,4) | 188 / 289 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
| 3.000 + 1.000 texto (umbral 0,4), aritmética numeric | 190 / 289 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
| 3.000 + 1.000 texto (0,4), **float8** | 162 / 224 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
| **2.500 + 1.000 texto (0,4), float8 — elegida** | **140 / 239 ms** | **100 % (100 %)** | **100 % (100 %)** | **99,9 % (98 %)** |
| 2.000 + 800 texto (0,4), float8 | 134 / 210 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
| 1.500 + 1.000 texto (0,4), float8 | 134 / 200 ms | 99,6 % (90 %) | 99,8 % (95 %) | 99,7 % (96 %) |
| 3.000 + 1.000 texto (umbral 0,5) | 173 / 254 ms | 100 % (100 %) | 100 % (100 %) | 99,9 % (98 %) |
Tres lecciones: (0) `float8` en vez de `numeric` para la cercanía de precio/margen baja esa consulta de ~74 a ~25 ms; por debajo de ~2.000 candidatos por precio/margen el recall empieza a caer. (1) ordenar la etapa 2 solo por texto (primer intento) perdía candidatos que rankean alto por precio y margen: la etapa 2 usa el puntaje
combinado. (2) **Con solo precio/margen el Pre-QA encontró un fallo real:** el original 691 (margen NEGATIVO, ver abajo) perdió el candidato puesto 4 del ranking
completo (nombre casi idéntico, "JUEGO DE MONTAJE DE EMPOTRABLE TWEETER…") porque en la etapa de precio/margen quedó en el puesto 6.279 de 25.815, fuera del pool de 4.000;
subir el pool no es una garantía, el generador por texto sí (con él ese caso entra y el recall vuelve a 100 %).

## Hallazgo aparte, fuera de alcance: puntajes de más de 100 % con márgenes negativos
`SubstituteSimilarityService::closenessScore()` calcula `100 − |Δ| / original × 100`; con un original de margen negativo (costo mayor que el precio, p. ej. el 691: precio 62,57,
costo 113,04, margen −80,7 %) el divisor es negativo y el "descuento" se suma: `similarity_percent` sale > 100 (117,3 % medido) y el término de margen premia a los candidatos
LEJANOS al margen del original. La preselección replica la fórmula tal cual (paridad exacta con `rank()`); corregirla es una decisión de regla de negocio (la fórmula la
especificó Gerencia1, SCRUM-246) y va en un ticket aparte, no en este.

## Criterios de aceptación
1. El endpoint responde 200 en < 500 ms (p95 por HTTP) con pico < 64 MB para un proveedor de ~26K productos, con y sin `search`.
2. Mismo shape (`fuzzy`, `data[]` con `similarity_percent`); con `search` y sin sustituto los resultados no cambian.
3. Los mejores sustitutos del ranking completo siguen presentes: top-20 idéntico al de `rank()` sobre TODOS los candidatos (paridad con oráculo en test y recall
   medido en dev con productos reales).
4. Ningún camino hidrata más de 500 `CatalogProduct` (+ el original) y hay un test que falla si vuelve la hidratación sin tope.
5. Se respeta la regla de categoría (misma categoría si hay; si no, todas con penalización) y los pesos/umbral de `ComprasSettings`.
