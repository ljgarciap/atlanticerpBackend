# Pre-QA SCRUM-1136 F6 — stock de ICG en las bodegas de AtlanticERP (dev.atlanticerp.ai)

Fecha: 2026-10-16. Entorno: https://dev.atlanticerp.ai (commit backend c85f9e6c). Cuenta: Gerencia Test 3 (`gerencia3@test.com`, password = su email).
Método: Playwright (Node) secuencial y lento, solo lecturas (GET). Cero escrituras, cero artisan, cero push, sin transiciones de Jira. Sin bans de CrowdSec.
Evidencia: carpeta `scrum-1136-f6-stock-bodegas/` junto a este informe.

## Veredicto
No hay CRÍTICO. 1 hallazgo MEDIO (Disponible negativo en 84 filas, ver M1) y 2 observaciones BAJAS. La carga cuadra al 100% con las cifras del encargo una vez descontado lo que el listado filtra por diseño.

## Hallazgos

MEDIO M1 — «Disponible» negativo en 84 filas de las pantallas de bodega (21 Obarrio, 1 Cliente, 24 Merma, 37 Reclamos, 1 Reserva; 0 en Bodega Central y SM).
Causa: `disponible = stock_quantity − por_servir − SUM(bodegas excluye_disponible)`, y para esos productos `catalog_products.stock_quantity` (el «stock total» global) es MENOR que lo que ahora hay en Merma+Reclamos+Reserva. Ej.: `10074A-WH` stock total 8, Merma+Reclamos 20 → Disponible −12 (estado «Sin stock»); `ML-10156EWH` stock total −82 → −83; `63981` total 13, Reserva 10+ → −10.
No rompe nada (estado sale «Sin stock», sin 500), pero el criterio 3 del encargo («Disponible neto 0 respecto del stock total») solo se cumple cuando `stock_quantity` ya incluye las unidades cargadas; en estos 84 casos `stock_quantity` y la suma de bodegas divergen (probablemente `stock_quantity` viene de la sync de ICG por delta/AL4 y no se recalculó con F6, o ICG tenía totales distintos). Requiere decisión de Arquitecto/PM: ¿F6 debe reconciliar `stock_quantity` con la suma de bodegas, o es el comportamiento esperado a ajustar con el inventario físico? No lo autoclasifico como aceptado. Reproducir: `GET /api/bodega/warehouses/12` (o Merma id 11) y buscar `disponible < 0`.

BAJO B1 — «En atención» difiere entre pantallas hermanas: Bodega → Ver Inventario muestra 18 («Alta/media rotación + bajo o sin stock») y Compras → Inventario muestra 7.493. Probablemente definición distinta (preexistente, no atribuible a F6); lo dejo registrado porque no pude comparar contra una captura previa a F6.

BAJO B2 — Totales de Bodega Central: la pantalla muestra 3.300 productos / 80.721 u; el encargo cita 3.530 filas / 79.407 u. Explicado: el listado excluye `quantity <= 0` y productos inactivos (230 filas ocultas, neto +1.314 u por negativos ocultos). Kardex de Central tiene 3.502 líneas (la última, 2026-10-06, «Ajuste por sincronización ICG (corrida #76)»), sin líneas nuevas de F6. No hay regresión observable en Central, pero no pude leer la tabla `product_warehouse_stock` directamente para confirmar 3.530/79.407 (ver «No verificado»).

## Pruebas (escenario de ruptura / criterio → resultado)

| # | Criterio / escenario | Resultado |
|---|---|---|
| 1 | Totales por bodega en `/bodega/bodegas` (KPI = suma de filas listadas, recorrido completo) | Obarrio 2.040 / 5.562; SM 65 / 605; Cliente 93 / 660; Merma 821 / 28.499; Reclamos 989 / 12.965; Reserva 7 / 99. KPI == suma de filas en las 7 bodegas, sin duplicados de producto. |
| 1b | Cuadre contra cifras del encargo (filas/u cargadas) | Kardex por bodega (filtro `warehouse_id`): Obarrio 2.079, SM 107, Cliente 93, Merma 845, Reclamos 993, Reserva 7 líneas — coincide EXACTO con filas cargadas. Unidades Kardex: SM 683, Cliente 660, Merma 28.186, Reclamos 13.043, Reserva 99 — EXACTO. Obarrio: solo pude sumar 1.000 de 2.079 líneas (tope de `per_page=all`/20), ver «No verificado». |
| 1c | Diferencia listado vs carga explicada | SM 107→65 filas y 683→605 u = los 42 inactivos (78 u). Reclamos 993→989 y 13.043→12.965 = 4 inactivos/78 u. Merma 845→821: 17 negativas ocultas (−360 u) + 7 inactivos (47 u): 28.186+360−47 = 28.499 exacto. Obarrio 2.079→2.040 = 33 negativas + 6 inactivos; unidades consistentes. Cliente y Reserva sin diferencia. Los inactivos NO se ven (esperado). |
| 2 | 5 productos de Obarrio (`001498`, `T-21005LWD`, `SPTUBES60BCNI`, `403911A`, `IL-PT-15`) | Listado: 2/1/1/3/2 u; Kardex: 1 línea tipo Ajuste, cantidad igual, `saldo_inicial` 0, `saldo_resultante` = cantidad, referencia «Carga inicial desde ICG (SCRUM-1136 F6) — almacén AL2». OK |
| 2b | 3 productos de Merma (`60005B` 88 u, `10090B-WH` 6 u, `74025D-WW` 2 u) | Cantidad, bodega y Kardex cuadran (ref. «…almacén ALX», saldo_inicial 0). UI Kardex (`12-kardex-60005B-enter.png`) y modal «Bodegas — 60005B» en Ver Inventario (`23-modal-bodegas-60005B.png`: Merma 88). OK |
| 2c | Integridad del Kardex en bloque | En SM, Cliente, Merma, Reclamos y Reserva: 0 líneas anómalas (todas tipo Ajuste, saldo_inicial 0, saldo_resultante = cantidad, referencia F6). Listado vs Kardex por producto: 0 diferencias (Obarrio 990/990 en la ventana de 1.000, resto 100%). Negativos con Kardex consistente: Merma 17 (−360 u), Obarrio 4 de los 1.000 revisados (−5 u). |
| 3 | Disponible en producto de Merma/Reclamos | `60005B`: stock total 88, en Merma 88 → Disponible 0, «Sin stock» (UI `23-...png`). `10090B-WH`: total 12, Merma 6 → Disponible 6. Reclamos `42016B-WH`: total 3, 3 en Reclamos → 0. OK salvo M1. |
| 3b | Producto en Showroom incluye Disponible | `001498`: Obarrio 2, total 10, Disponible 10; `ML-73021C40-WW`, etc. Fórmula verificada en las 7.315 filas listadas: solo 6 no cuadran y todas por filas negativas ocultas en el listado (no son errores de cálculo). |
| 4 | Errores/500/NaN/«undefined»/null en pantallas tocadas | `/bodega/bodegas` (7 pestañas), `/bodega/kardex`, `/bodega/inventario`, `/inventario`, `/bodega/inventario-general`: 0 respuestas ≥400, 0 errores de consola/página, sin NaN/undefined/null en el texto. |
| 4b | Bodega Central sin regresión | Carga y lista sin error; Kardex de Central intacto (sin líneas F6). Ver B2. |
| 5 | Iconografía/ortografía | Sin emojis ni glifos de emoji en el texto de ninguna pantalla revisada; textos en español correcto («Carga inicial desde ICG … almacén»). |

## Lo que sí funciona
Carga completa y consistente en las 6 bodegas; Kardex inmutable con saldo_inicial 0 y trazabilidad por almacén; KPIs de bodega iguales a sus filas; productos inactivos ocultos; negativos ocultos del listado pero trazados en Kardex; Disponible excluye Merma/Reclamos/Reserva como diseñado; sin errores en red ni consola; Central sin cambios.

## No pude verificar
- Cifras directas de tabla (3.530 filas / 79.407 u de Central; 2.079 filas / 5.505 u de Obarrio por suma de `quantity`): requiere SQL en dev (ssh/artisan están fuera de este encargo). Obarrio: Kardex solo permite leer 1.000 líneas por consulta (`per_page` cap), así que la suma total 5.505 se infiere (5.562 listadas − negativas ocultas − inactivos), no se sumó línea a línea.
- No abrí en UI un producto con stock de Showroom y Disponible (lo comprobé por API sobre todas las filas); la ruta Compras → Inventario (`/inventario`) se revisó por carga/errores/capturas, no fila por fila.
- Pre-QA de reversa/idempotencia del comando (criterios 7/11) queda fuera: son artisan que escriben.
- Un clic mío en la barra superior abrió «Órdenes de Zona Libre» (navegación pura, sin crear nada; captura `22-...png` quedó regrabada con la pantalla correcta).

## Pendiente para PM/Arquitecto
M1 — **RESUELTO 2026-10-16: Luis lo aceptó tal cual** (el Disponible negativo en 84 filas viene de los negativos de ICG migrados tal cual y, en 51 de los 79 productos, de la Bodega Central ya negativa por AL4/SCRUM-1016; con F6 los productos con Disponible negativo bajaron de 113 a 79; `stock_quantity` nunca queda por debajo de la suma de bodegas). No se transiciona ni se crea marcador desde este informe.

## Cierre posterior (2026-10-16)
- Dev: carga de 4.124 filas, `legacy:verify-stock-bodegas` OK, segunda pasada idempotente (0).
- Test: igual, con 4.123 filas (1 de ALY ya existía y no se pisa); verify marca DIFERENCIA de 1 u en ALY por esa fila operativa, no por la carga.
- Prod (`v1.49.0.0`): backup `pg_dump -Fc` previo verificado, 42 inactivos de B2 creados, 4.124 filas sembradas, verify OK en los 6 almacenes, segunda pasada 0. No se repitió la revisión visual en test/prod.
