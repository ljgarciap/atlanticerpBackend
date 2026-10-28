# Pre-QA — SCRUM-994 (Inventario de Bodega: KPIs, chips calculados y filtro estado en SQL)

Fecha: 2026-09-29 (UTC) · Entorno: dev.atlanticerp.ai · Backend origin/dev **58be810** (verificado por SSH: `git -C /var/www/backend rev-parse --short HEAD` = 58be810) · Frontend 96d3370 (sin cambios en este ticket).
Catálogo real de dev: 29.630 activos + 6 inactivos (incluido PREQA-992-A id 29697). Pedidos de Bodega: 58 (asignado 21, picking_pendiente 2, en_picking 2, packing 0, por_despachar 25, despachado 3, entregado 5).

Método: API real con JWT temporales (SSH+tinker, borrados al terminar), oráculos INDEPENDIENTES por SQL de solo lectura (stock, punto de reorden, rotación, compromiso de pedidos de Bodega en etapas activas, stock en bodegas excluidas, líneas de ingreso sin confirmar, familias, stock por bodega, orden por `LOWER(col), id`) escritos con subconsultas correlacionadas a partir de las reglas de la spec (no del código bajo prueba); baterías HTTPS a través de nginx desde el EC2 de dev (IP en allowlist de CrowdSec); Playwright CLI headless (NO Claude in Chrome); `npx vitest run`; `infra/test.sh`; medición de memoria en proceso por el kernel HTTP con `memory_limit=256M`.
Usuarios: `almacen@` (Líder de Bodega, `bodega.read`), `[persona]@` (Vendedor/Diseñador, sin `bodega.read`), superadmin. Sin logins fallidos (no se dispara CrowdSec).

## Paso 0 (permisos angostos, valores paramétricos, dependencias de otros tickets, datos sembrados)

- Permisos angostos: el ticket no menciona ninguna capacidad "solo X puede...": el gate es `bodega.read` (un string simple, sin `extra_permissions` por persona). No aplica.
- Valores paramétricos: el ticket no introduce umbrales/márgenes nuevos. `reorder_point` (columna del producto) es el único umbral y ya es dato, no constante. La allowlist de rotación de "En atención" (alta/media) es regla de negocio de REQ-346 RN1 ya existente (constante de dominio `CatalogProduct::ROTATION_*`), sin cambio.
- Dependencias de UI en otros tickets: ninguna (sin cambios de frontend; el Home de Bodega enlaza a `?filter=criticos` / `?filter=rotacion`, ya desplegado).
- Datos sembrados en dev (verificado por SQL): stock NULL 18.561, `reorder_point` NULL en 29.596 activos, `reorder_point` = 0 en 11, disponible exacto 0 con pedidos (p.ej. id 11814: stock 10, en bodega excluida 10, `reorder_point` 10), disponible negativo en 244 productos, pedidos en etapas activas (50) y NO activas (despachado/entregado, 8 pedidos; 10 líneas), 10 líneas con `catalog_product_id` NULL, bodegas excluidas con stock (Reserva Servicios: productos 150 y 11814), rotación alta 9 / media 29 / baja 9 / `unica` 6 / NULL 29.577, stock por bodega en Zona Libre 7.976, Central 3.354, Showroom Obarrio 3, Reserva Servicios 2, bodegas sin stock (Showroom SM id 9, Showroom Cliente id 10), 5 productos "por ingresar". **Bordes que NO existen en dev** (no se pueden sembrar: solo se puede escribir el producto PREQA-992-A y no crear pedidos): `qty_delivered` > `qty_requested` (0 líneas) y producto inactivo con pedidos (0). Ambos los cubre `BodegaInventorySqlParityTest` (ver sección L).

## (a) Paridad exacta de conjuntos — todas las páginas, `per_page=all` (1.000 filas por página), contra el oráculo SQL

Cada fila compara el conjunto completo de IDs devuelto por la API con el del oráculo: sin repetidos, sin faltantes, sin sobrantes, `meta.total` = tamaño esperado.

| Escenario | Esperado (SQL) | API | Resultado |
|---|---|---|---|
| `chip=todos` | 29.630 | 29.630 | OK |
| `chip=inactivos` | 6 | 6 | OK |
| `chip=rotacion` | 9 | 9 | OK |
| `chip=por_ingresar` | 5 | 5 | OK |
| `chip=en_atencion` | 21 | 21 | OK |
| `chip=criticos` (antes 500) | 26.285 | 26.285 | OK |
| `estado=disponible` | 3.345 | 3.345 | OK |
| `estado=bajo_stock` | 2 | 2 | OK |
| `estado=sin_stock` (antes 500) | 26.283 | 26.283 | OK |
| `estado=todos` | 29.630 | 29.630 | OK |
| chip × estado, 12 combinaciones (criticos/en_atencion/por_ingresar/rotacion × disponible/bajo/sin) | intersección SQL (0, 2, 26.283, 0, 2, 19, 0, 0, 5, 6, 0, 3) | idéntico | OK |
| `chip=inactivos&estado=sin_stock` | 2 (SQL: 2 de los 6 inactivos tienen disponible <= 0) | 2 | OK (ver nota de método 1) |

Filtros combinados: `warehouse_id` 7/6/8/13 (7.976 / 3.350 / 3 / 2 activos con stock > 0), cada uno solo y con `chip=criticos` (6.320 / 6 / 1 / 1), `warehouse_id=8&estado=sin_stock` (1), `warehouse_id=999999` (0); `provider_id` 187/147/82 (25.816 / 1 / 1), `+chip=criticos` (23.093), `+estado=disponible` (2.723); `brand=770 lights` en minúsculas y MAYÚSCULAS (5.168), `+chip=criticos` (4.554); `family_id` 1 y 2 (8 / 6), con `estado=sin_stock` (0); `rotation=alta` (9) y `+chip=en_atencion` (3). **Todos idénticos al oráculo** (26 escenarios).

## (b) KPIs contra SQL independiente y coherencia con Reportes/Home

`GET /api/bodega/inventory` (kpis) vs cálculo independiente por SQL (`total_products` = activos, `low_stock` = 0 < disp <= reorder_point, `out_of_stock` = disp <= 0, `in_attention` = rotación alta/media y disp <= reorder_point, `total_por_servir` = suma del compromiso SOLO de activos):

| KPI | SQL independiente | API |
|---|---|---|
| total_products | 29.630 | 29.630 |
| low_stock | 2 | 2 |
| out_of_stock | 26.283 | 26.283 |
| in_attention | 21 | 21 |
| total_por_servir | 213 | 213 |

Los mismos 5 números en 15 combinaciones de filtro distintas (sin filtro, `chip=criticos`/`inactivos`/`rotacion`/`por_ingresar`/`en_atencion`, `estado=sin_stock`/`bajo_stock`, `warehouse_id=8`, `search`, `provider_id`, `brand`, `family_id`, orden desc + chip): los KPIs son del universo activo, no del filtro. Idénticos a los que dio el código anterior según el Senior/dev fork.
`GET /api/bodega/reports/inventory-summary` (REQ-424 RN1): `total_products` 29.630, `in_attention` 21, `committed_units` 213 = exactamente lo de la pantalla en el mismo momento.
Home de Bodega: ver **O1** (diferencia de 3 productos preexistente, no causada por este ticket).

## (c) Orden exacto (criterio 7 — paginación estable, desempate por `id`)

`chip=criticos` (26.285 filas), los 4 campos × asc/desc, recorriendo todas las páginas: el orden completo de la API es **idéntico** al orden SQL `ORDER BY <campo>, id` (8 de 8): `name` (LOWER), `reference` (LOWER), `stock_quantity` (con NULL), `created_at`. Además `estado=disponible&sort_by=name&sort_dir=desc` (3.345) idéntico. Con `per_page=100` (263 páginas) y `sort_by=stock_quantity desc`: sin repetidos ni faltantes, orden = oráculo. Última página parcial (26.285 / 100 → página 263 con 85 filas) correcta; una página más allá → `data: []`.
Punto de la spec "Pre-QA debe probar orden por `name` con acentos y mayúsculas" (cambio `strcasecmp` → `LOWER()`): el oráculo usa `LOWER(name)` de Postgres con la misma colación, y la comparación de orden completo (26.285 filas, asc y desc) coincide, de modo que las filas con acentos/mayúsculas del catálogo real quedan en el mismo orden que la pantalla sin chip calculado.

## (d) Autorización

Sin token → 401. `[persona]@` (sin `bodega.read`) → **403** en `/api/bodega/inventory` y también con `chip=criticos` (sin `data` en el cuerpo; no se oculta solo el botón). Superadmin → 200. En UI, `[persona]@` entrando a `/bodega/inventario?filter=criticos` no recibe ninguna respuesta 200 del listado y la tabla queda vacía.

## (e) Forma de la respuesta (criterio 6)

Claves raíz `fuzzy, kpis, providers, brands, data, meta` intactas en todas las combinaciones; claves de fila (26): `arrival_confirmation, barcode, brand, category, description, disponible, en_camino, estado, factory_reference, family_id, family_name, id, is_active, name, photo_url, por_ingresar, por_servir, provider_id, provider_name, reference, reorder_point, rotation, stock_quantity, stock_total, technical_spec, warehouse_count`. Sin cambio de contrato.

## (f) Entradas inválidas / hostiles — 56 peticiones, cero 5xx, shape intacto

`estado`/`chip` vacíos, desconocidos o en mayúsculas; `per_page` 0/-1/abc/1000000/`all`/`[]`; `page` 0/-5/abc/99999999999999999999/1000000/100000; `sort_by` con `;drop table`, comilla y `[]`; `sort_dir` inválido/mayúsculas; `search` con `'`, `%`, `_`, `";--`, NUL, solo espacios y 3.000 caracteres; `provider_id`/`warehouse_id`/`family_id` no numéricos, negativos, 0, desbordados y `[]`; `brand` con `' OR 1=1--`, `%`, `[]`; `rotation` inválida/`[]`; `chip[]`, `estado[]`; combinaciones (`chip=criticos&estado=xyz`, `chip=inactivos&warehouse_id=7`, etc.). Todas devuelven 200 con las claves raíz. Comportamiento verificado: `estado` desconocido → 0 resultados (igual que antes), `chip` desconocido → equivale a `todos` (29.630), `per_page` fuera de la lista blanca → 20, `page` desbordado → `data: []`.

## (g) Búsqueda (SCRUM-992, sin cambios) combinada con chips

`search=led` (9.238), `lampara`/`LAMPARA`/`lámpara` (1.397 los tres), `zzzqqq` (0), `PREQA` (59): 200, `fuzzy=false`, 146–246 ms. `search=led&chip=criticos` (7.965) = exactamente la intersección de `search=led` con `criticos`.

## (h) Latencia y memoria (criterio 1: p95 < 500 ms por HTTP y pico < 64 MB)

Por HTTP a través de nginx (5 mediciones calientes por combinación, `per_page=20`): sin filtros p50 183 / máx 184 ms; `criticos` 203 / 234; `estado=sin_stock` 201 / 318; `bajo_stock` 167 / 212; `en_atencion` 158 / 163; `por_ingresar` 185 / 245; `inactivos` 149 / 233; `rotacion` 156 / 180; `search=led` 178 / 178; `search=led&criticos` 197 / 199; orden `name desc + criticos` 215 / 244; orden `stock desc` 196 / 249; `warehouse_id=7` 227 / 261; `warehouse_id=7&criticos` 214 / 301; `provider 187 + sin_stock` 197 / 202; `brand + criticos` 184 / 298; `per_page=all + criticos` (1.000 filas) 353 / 361 ms. **Todas < 500 ms** (antes: 500 en 4,8 s).

Pico de memoria extra en proceso (kernel HTTP, `memory_limit=256M`, 3 repeticiones): 0,2–0,4 MB con todos los chips/estado/búsqueda/filtros, 3,7 MB sin filtros, 1 MB con `per_page=100`, 8,5 MB con `per_page=all` (1.000 filas). **Máximo 8,5 MB** vs criterio < 64 MB (antes ~164 MB, y agotamiento a 256 MB en `criticos`).

## (i) Concurrencia

8 peticiones simultáneas (`criticos` y `estado=sin_stock`, las dos que antes agotaban memoria): todas 200, máx 1.467 ms. 40 peticiones con 20 hilos mezclando `criticos`, `sin_stock`, sin filtro, `search=led` y `en_atencion`: **todas 200**, sin 5xx, máx 5.156 ms (cola en los workers de PHP-FPM; ver O2).

## (j) UI real (Playwright CLI headless contra dev.atlanticerp.ai, `almacen@`) — 5 de 5

Spec permanente `atlanticerp-frontend/e2e/preqa-scrum994-bodega-inventario-sql-20260929.spec.ts` (regresión de un fallo real de producción-like: el 500 de `criticos`/`sin_stock`; por eso NO se descarta):
1. Home → clic real en "Ver los 26282 artículos" de la tarjeta "Artículos críticos" → `/bodega/inventario?filter=criticos` responde 200 con `total` 26.285 y muestra filas (REQ-362 RN1; antes 500).
2. KPIs visibles 29630 / 2 / 26283 / 21 / 213 y no cambian al aplicar `estado=sin_stock` (total del listado 26.283).
3. Chips En atención (21) / Inactivos (6) / Por ingresar (5) + chip En atención con Estado Sin stock (19) + Todos con Estado Disponible (3.345): 200 y conteos exactos; sin 5xx durante toda la sesión.
4. Deep-link `?filter=criticos`, recarga a mitad de flujo (mismo primer producto), orden por columna Nombre asc/desc, página 2 (distinto conjunto de filas, `total` 26.285) y "Todas" (`per_page=all`, 1.000 filas renderizadas): todo 200.
5. `[persona]@` sin `bodega.read`: ningún 200 del listado, tabla vacía.
Sin errores de consola ni 5xx en ninguno. `npx vitest run src/pages/BodegaHomePage.test.tsx src/pages/BodegaInventarioPage`: 49/49 (frontend sin cambios en este ticket).

## (k) Backend local

`infra/test.sh --filter=BodegaInventorySqlParityTest`: **54/54 OK** (304 aserciones). `infra/test.sh --filter=Bodega`: **528/528 OK** (2.493 aserciones). PHPStan (Level 8) sobre `app/Modules/Bodega`: sin errores. (La suite completa de 3.991 tests la corrió el dev y el Senior reejecutó 695 del área — no se repite completa acá.)

## (L) Bordes que dev no permite sembrar

`qty_delivered` > `qty_requested` y producto inactivo con pedidos activos/ingreso sin confirmar: no existen en dev y no se pueden crear con los permisos de esta pasada. Los cubre `BodegaInventorySqlParityTest::seedEdgeCases()` (pedido con 3 pedidas / 5 entregadas en `packing` → pendiente `max(0, …)` = 0; producto inactivo con pedido de 50 y línea por ingresar), comparando la API contra la lógica PHP original de la pantalla copiada tal cual (`oracleIds()`/`oracleKpis()`). Corrido localmente: 54/54.

## Observaciones (ninguna bloquea SCRUM-994)

**O1 — MEDIO, preexistente, fuera del alcance de este ticket — "Artículos críticos" del Home no coincide con el Inventario al que enlaza.** El Home muestra "Ver los **26282** artículos" (`articulos_criticos.total`) y el clic lleva a `chip=criticos`, que lista **26.285**: 3 productos de diferencia, visible para el usuario en el mismo clic (comprobado en UI). Causa medida: `BodegaHomeService::articulosCriticos()` calcula el "por servir" con `InventoryKpiService::porServirMap()` (líneas de órdenes de compra con `sales_project_id`, la definición de Compras), mientras Inventario de Bodega usa el compromiso a pedidos de Bodega en etapas activas (`OrderCommitmentService`, REQ-351 RN1) — las dos definiciones de "por servir" que la spec ya advertía. `BodegaHomeService` no está en el commit f144f08 (3 archivos: controller, servicio KPI y test), así que no lo introdujo este ticket; además esa función sigue hidratando el catálogo activo completo (tema de SCRUM-995). **No se auto-clasifica**: queda para decisión de Luis/PM (ticket propio, o incluirlo en SCRUM-995).

**O2 — informativa — bajo carga concurrente alta las latencias suben por cola de PHP-FPM.** 40 peticiones con 20 hilos: máx 5,2 s, sin errores. El criterio de la spec (p95 < 500 ms) es por petición y se cumple con margen; no es una regresión de este ticket (el costo por petición bajó de 1,5–4,9 s a 0,1–0,3 s), pero indica el techo real de concurrencia del EC2 t3.small.

**O3 — sin verificar todavía (criterio 9 de la spec, posterior al deploy):** test y prod (mismo código y volumen; el 500 no se comprobó allí, se verifica al desplegar), 30 min sin 5xx en `/api/bodega/inventory` y la alarma `*-nginx-5xx-high` sin dispararse por esta ruta. Estadísticas de Postgres: el dev midió 759 ms sin `ANALYZE` reciente frente a 79 ms con `ANALYZE` en un caso combinado del stack de test; en dev las mediciones de arriba ya son con las estadísticas actuales — conviene un `ANALYZE` tras el deploy en test/prod.

## Notas de método (errores míos, no defectos del producto)

1. Mi primera expectativa para `chip=inactivos&estado=sin_stock` fue 0 (asumí que `estado` excluye inactivos); la verificación por SQL mostró que 2 de los 6 inactivos tienen disponible <= 0 y que el comportamiento original (filtro `estado` sobre el conjunto candidato) es exactamente ese. La API estaba bien; corregí el oráculo.
2. La primera corrida de paridad pidió `per_page=1000`, que no está en la lista blanca (10/20/50/100/`all`) y cae a 20: solo trajo 4.000 filas. Corregido a `per_page=all` (techo 1.000). No es un defecto (comportamiento verificado en la sección f).
3. En UI, la respuesta de `estado=disponible` llegó antes que la de `estado=sin_stock` (más pesada) y una aserción sobre "la última respuesta" falló; la pantalla usa la consulta vigente. Se corrigió la aserción a búsqueda por URL. React Query sirve desde caché al volver a un filtro ya visto, sin petición nueva.

## Veredicto

**Pasada limpia y concluyente para SCRUM-994**: sin hallazgos CRÍTICOS ni MEDIOS propios del ticket; criterios 1–8 verificados en vivo en dev con el catálogo real (más 54 tests de paridad y 528 de Bodega en verde localmente); el criterio 9 queda para el deploy a test/prod. **Transición a `QA` y comentario en Jira pendientes** de que Luis/PM decidan sobre **O1** (regla dura de Pre-QA: no auto-clasificar una diferencia entre lo que la pantalla enlaza y lo que muestra).
