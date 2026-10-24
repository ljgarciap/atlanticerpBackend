# Pre-QA — SCRUM-996 (buscadores y Home de Bodega en SQL; auditoría SCRUM-995, lote A+B+C+G)

Fecha: 2026-09-29 (UTC) · Entorno: dev.atlanticerp.ai · Backend origin/dev **6804ca8** (verificado por SSH) · Frontend 33f2111 (sin cambios en este ticket).
Catálogo real de dev: 29.630 activos. Bodega Zona Libre 7.976 filas (8.437 stock rows con `mostrar_sin_stock`), Central 3.354.
Commits bajo prueba: `4ebcceb` (lote A+B+C+G) y `6804ca8` (un solo agregado de conteo+unidades en G, salido de este mismo Pre-QA).

Método: API real con JWT temporales (SSH + tinker, borrados al terminar); oráculos SQL de solo lectura por `psql` escritos aparte del
código bajo prueba (búsqueda por `search_text`/descripción/ubicación, relevancia exacto > prefijo > resto, regla de stock de Bodega con
compromiso a pedidos, top-N de críticos); medición por el kernel HTTP (`memory_limit=256M`) y por HTTPS a través de nginx desde el EC2
(30 muestras por caso crítico, se descarta la primera en frío); Playwright CLI headless (NO Claude in Chrome); ejecución de los mismos
tests contra el código anterior (mutación); suite completa en una base aislada.

## Paso 0

- Permisos angostos: ninguno. Gates: `bodega.read` (A, C, G) y rol `lider_servicios`/`management`/`vendedor_disenador`/`superadmin` (B). Sin `extra_permissions` por persona.
- Valores paramétricos: el ticket no introduce umbrales nuevos. Los topes (10 en A, 100 en B) son de la decisión de Luis del 2026-09-29, igual que los de otros buscadores de catálogo (constantes de presentación, no de negocio). El top-N del Home ya es configurable (`articulosCriticosTopN()`).
- Dependencias de UI en otros tickets: ninguna; sin cambios de frontend.
- Datos en dev: cubren los bordes de cada sitio (términos muy comunes `led`, término sin resultados, typos, ubicación `A-1`, referencia exacta, 7 referencias con `Ñ`). No existe en dev un proyecto con pedidos entregados, así que el filtro `sales_project_id` de B se cubre con `TicketProductProjectFilterTest` (parte de la suite verde).

## (a) Antes → después (dev, catálogo real, kernel con `memory_limit=256M`; pico = memoria extra durante la petición)

| Sitio | Caso | Antes | Después |
|---|---|---|---|
| A buscador de ajuste | `search=led` / `zzzqqq` | 1,0-2,2 s, 156,5 MB | 47-225 ms, 2,5-2,6 MB |
| B lookup de Servicios | sin params | 2,0 s, 170,7 MB, 4 MB de respuesta | 85 ms, 3 MB, 12 KB |
| B | `search=led` / `zzzqqq` | 1,3-1,6 s, 156,5 MB | 60-230 ms, 2,5-3 MB |
| C Home de Bodega | `home/summary` | 2,2 s, 93,3 MB | 318 ms p50 (515 ms la primera en frío), 7,1 MB |
| G búsqueda en bodega | `warehouses/7?search=led` (Zona Libre) | 2,9 s, 200,6 MB | 391 ms p50 / 558 ms p95 por HTTP, 3,5 MB |
| G | `search=zzzqqq` | 3,4 s, 200,6 MB | 109-138 ms, 2,6 MB |
| G | `warehouses/6?search=led` (Central) | 0,35 s, 28,2 MB | 152 ms, 3,5 MB |

## (b) Paridad contra el oráculo SQL — 30 de 30

- **G** (`warehouses/{id}?search=`, todas las páginas, `per_page=100`): `led` 8.437 · `lampara` 1.346 · `led lampara` 201 · `zzzqqq` 0 · ubicación `A-1` 208 · referencia exacta 1 · `led A` (palabra del producto + palabra de la ubicación) 7.435 · Central `led` 1.273 y `lampara` 125. En cada uno: mismo conjunto de filas, sin repetidos, `meta.total` y `kpis.total_units` iguales al oráculo (p. ej. 1.359.952 unidades). La referencia exacta va primera; el typo `lamprara` tolera (200 filas); `search` + `ubicacion` y `chip=espacio_libre` siguen en 200.
- **C** (Home): `articulos_criticos.total` = **26.285** = oráculo SQL = `meta.total` de `GET /inventory?chip=criticos` (**O1 cerrada**: antes 26.282 vs 26.285); los 5 productos, su `disponible` y su `estado` coinciden con el oráculo y en el mismo orden (sin_stock primero, luego menor disponible, desempate por id); shape de los ítems sin cambio.
- **A** (`adjustment-requests/products/search`): `led`, `lampara`, `led lampara`, referencia exacta y `LÁMPARA` devuelven los mismos 10 productos en el mismo orden que el oráculo (relevancia, nombre, id). Sin término → `[]`, sin coincidencias → `[]`, typo `lamprara` → 10 resultados.
- **B** (`servicios/lookup/products`): sin parámetros = primeros 100 por nombre; `led` y `lampara` = oráculo (relevancia, tope 100); `exclude` se respeta y completa 100.

## (c) Latencia por HTTP a través de nginx (30 muestras, sin la primera en frío)

| Caso | p50 | p95 | Criterio p95 < 500 ms |
|---|---|---|---|
| A `search=led` | 192 ms | 227 ms | OK |
| B `search=led` | 203 ms | 258 ms | OK |
| C `home/summary` | 302 ms | 377 ms | OK (la primera en frío llegó a 515 ms; un valor aislado de 1.041 ms) |
| G `wh7?search=lampara` | 278 ms | 367 ms | OK |
| G `wh7?search=led&per_page=100` | 405 ms | 523 ms | **fuera por 4,6 %** |
| **G `wh7?search=led` (Zona Libre)** | **391 ms** | **558 ms** | **fuera por 11,6 %** (máx. 1.355 ms, un valor aislado) |

Pico de memoria: máximo 7,1 MB (criterio < 64 MB), sin 5xx en ninguna combinación.

## (d) Entradas hostiles, forma de respuesta y concurrencia

- 40 combinaciones hostiles (4 endpoints × `search[]=x`, `'`, `%`, `_`, `";--`, NUL, solo espacios, 3.000 caracteres, `' OR 1=1--`, `\`): **cero 5xx** (antes `search[]=x` daba 500 en A por un cast a `string`, corregido con `QueryParams`).
- Sin cambios de contrato: mismas claves en A (`id, reference, description`), B (`id, reference, name, description, brand, price_full`), G (`stock_id, catalog_product_id, reference, …`) y C.
- 40 peticiones con 20 hilos mezclando los 4 endpoints: **todas 200**, p50 4,3 s y máximo 6,2 s por cola de PHP-FPM (mismo techo de concurrencia del `t3.small` que en SCRUM-994; sin errores).

## (e) UI real (Playwright CLI headless contra dev, `almacen@`) — 3 de 3

Spec permanente `atlanticerp-frontend/e2e/preqa-scrum996-catalogo-sql-20260929.spec.ts`:
1. Home → clic real en "Ver los N artículos" → Inventario `chip=criticos`: el `meta.total` es exactamente N (O1) y hay filas.
2. Bodegas → Zona Libre → buscar `led` (200, total > 0) y `zzzqqqxxx` (200, total 0), autocompletado visible, KPIs 8.437 productos / 1.359.952 unidades como el oráculo.
3. Solicitud de ajuste → "Nueva solicitud" → buscar producto `led` (200, entre 1 y 10 resultados) y `zzzqqqxxx` (0).
Sin errores 5xx en ninguno. El picker de Servicios (sitio B) no se ejercitó por UI (exige elegir Cliente/Subcliente/Proyecto); queda cubierto por la paridad de API y los tests.

## (f) Backend

- 22 tests nuevos (`Scrum996CatalogSqlTest`, `ProductLookupSqlTest`, 147 aserciones): **20 fallan contra el código anterior**, los 22 pasan con el nuevo.
- Suite completa en una base aislada: **4.021 tests OK** (1 omitido, ya existente). PHPStan (Level 8) sobre todo el proyecto: sin errores.
- Nota de proceso: una primera corrida completa sobre la base compartida dio 200 errores y 53 fallos, todos por colisión con otra sesión (tablas que desaparecían; `infra/test.sh` borra los schemas de la base `atlanticerp`); repetida en una base propia quedó limpia.

## Hallazgos

**MEDIO — G `warehouses/{id}?search=` con un término muy común en la bodega grande no cumple p95 < 500 ms.** `search=led` en Zona Libre (8.437 filas, el término coincide con casi todas) da p50 391 ms y p95 558 ms por HTTP (`per_page=100`: 523 ms). Es 5-8 veces más rápido que antes (2,9-3,4 s y 200 MB) y con 3,5 MB de pico, pero el criterio 1 del ticket pide p95 < 500 ms en todas las combinaciones.
Causa medida (EXPLAIN ANALYZE, dev): el orden por relevancia se evalúa sobre todas las filas coincidentes (5 llamadas a `f_unaccent(lower())` por fila): la consulta principal cuesta 202-409 ms con la relevancia y 9-14 ms sin ella; el filtro en sí es barato. Ya se recortaron ~110 ms con un solo agregado (`6804ca8`: de p50 523 / p95 652 a p50 391 / p95 558).
Opciones evaluadas: (1) quitar `f_unaccent` de las referencias baja un tercio (137-268 ms), pero **no es equivalente** —7 referencias reales llevan `Ñ` (`DISEÑO01`…) en dev, test y prod— así que se descartó; (2) columnas normalizadas generadas (`reference_norm`, `name_norm`) con índice: relevancia casi gratis (~10 ms), pero exige una migración y su revisión del Arquitecto; (3) aceptar la desviación (un solo término, la bodega más grande).

**Observación (no bloquea).** El primer request en frío del Home tarda ~515 ms y con carga concurrente alta (20 hilos) las latencias suben por cola de PHP-FPM, sin errores.

## Lo que sí funciona

Paridad exacta con el oráculo en los 4 sitios (30 de 30), O1 cerrada (Home = Inventario = 26.285), sin 5xx con entradas hostiles, KPIs y totales del filtro de G coherentes, referencia exacta y typos con el comportamiento esperado, UI de las tres pantallas (3 de 3), memoria de 200 MB a 3,5 MB, suite completa verde.

## Veredicto

**Pasada limpia con una desviación aceptada por Luis (2026-09-29).** El hallazgo MEDIO de G (p95 558 ms vs 500 ms para el término `led` en Zona Libre) se resolvió por decisión explícita de Luis: **aceptar la desviación** documentada arriba (5-8 veces más rápido que antes, 3,5 MB de pico, solo un término genérico en la bodega más grande) **y abrir un ticket de seguimiento** para las columnas normalizadas (`reference_norm`/`name_norm` generadas, con índice), que dejaría la relevancia casi gratis y beneficiaría a los otros buscadores de catálogo. El resto del lote pasó sin hallazgos: paridad 30 de 30, O1 cerrada, UI 3 de 3, suite completa verde. Listo para pasar a `QA`.
