# Spec (Analista) — F6 de SCRUM-1136: stock de ICG → `bodega.*` por bodega

- **Estado:** BORRADOR 2026-10-15, 5 decisiones cerradas (sección 6); lista para pasar al Arquitecto.
- **Contexto:** F1/F2/F5 dejaron en dev/test/prod el staging completo de ICG (`legacy.stock_almacen`, `legacy.movimientos`,
  `legacy.almacenes`, descatalogados con stock). Los usuarios de AtlanticERP aún no ven nada de eso: hoy solo Bodega Central (AL4) tiene
  filas en `bodega.product_warehouse_stock` (SCRUM-1016/1114). ICG está **congelado** (último movimiento 2026-10-09, sync apagado),
  así que F6 es una **carga única**, no un sync. Diseño base: `docs/architecture/icg-staging-almacenes-diseno.md` (secciones 10–11).
- **Criterio de negocio ya decidido (Luis, 2026-10-15):** el stock de ICG se asume real y se ajusta después con el inventario físico.

## 1. Objetivo
Reflejar en AtlanticERP el stock que ICG tiene en las bodegas distintas de AL4 (≈1,1 M de valor a costo, medido 2026-10-12), de modo que
Bodega/Compras vean existencias por bodega y el inventario físico posterior ajuste sobre una base ya cargada.

## 2. Estado de partida (medido, ver `memory/project_icg_analisis_bodegas_retomar_20261009.md`)
| ICG | AtlanticERP (dev, `bodega.warehouses`) | Confianza | Stock ICG (filas / unidades) |
|---|---|---|---|
| AL4 Bodega Central | Bodega Central | alta | **ya cargado** (SCRUM-1016/1114) — F6 no lo toca |
| AL2 Showroom | Showroom Obarrio | **confirmada** (Excel 13-oct) | 2 073 / 5 562 |
| B1 Showroom Externo Clientes | Showroom Cliente | **confirmada** | 93 / 660 |
| B2 Showroom Santa María | Showroom SM | **confirmada** | 65 / 605 |
| ALX Mermas | Merma | alta, sin confirmar | 837 / 28 430 |
| ALY Reclamos | Reclamos y Devoluciones | alta, sin confirmar | 988 / 12 961 |
| ALR Almacén de Reservas | Reserva Servicios | baja | 7 / 99 |
| A1 (huérfano: está en `STOCKS`, no en `ALMACEN`) | sin equivalente | — | 565 / 15 648 |
| ALM Ajustes | sin equivalente | — | 456 / 4 677 (+) y −7 206 (neg) |
| AL1 Bodega Iluminations (bloqueada 2022) | sin equivalente | — | 254 / 460 (+) y −1 756 (neg) |
| AL3 Colón | Bodega Zona Libre es `solo_consulta` | — | 33 filas; 1 sku con 10 u, 496 u negativas |

## 3. Requisitos
- **RF1 — Equivalencias confirmadas.** El mapeo vive en `legacy.almacen_equivalencias` (ya existe, vacía). Se siembra con las filas
  propuestas y solo las de `estado = confirmado` se cargan. Se resuelve el `warehouse_id` por **nombre de bodega**, nunca por id
  (los ids difieren entre dev/test/prod). Si el nombre no existe o es ambiguo en un entorno, el comando aborta antes de escribir.
- **RF2 — Carga única, dry-run por defecto.** Comando nuevo `legacy:cargar-stock-bodegas {--tenant=} {--apply} {--operador=}
  {--almacen=*}` (mismo patrón que `legacy:backfill-stock-bodega-central`): `--apply` exige `--operador`, una transacción por
  almacén, verificación antes/después con ROLLBACK ante discrepancia, evento de auditoría resumen con los ids sembrados.
- **RF3 — Idempotente y no pisa.** Solo crea filas (producto, bodega) que **no existen**; nunca suma ni sobreescribe lo que Bodega ya
  gestione. Una 2ª corrida no encuentra nada. Nunca escribe en AL4/Bodega Central ni en bodegas `solo_consulta` (el trigger
  `product_warehouse_stock_solo_consulta` ya lo impide; el comando lo valida antes y lo reporta).
- **RF4 — Resolución de producto.** `catalog_product_id` = `COALESCE(legacy.productos.catalog_product_id, equivalente_activo.catalog_product_id)`
  (vínculo de F5 vía `DescatalogadoEquivalenciaService::consultaConCatalogoResuelto`). Incluye los descatalogados con stock
  (los 83 creados inactivos y los 11 equivalentes). Artículos sin `catalog_product_id` resoluble → no se cargan, se listan.
- **RF5 — Cantidad.** Se toma `legacy.stock_almacen.stock` (valor de ICG, no `stock_corregido`) salvo la decisión 3 sobre negativos.
  `product_warehouse_stock.quantity` es **entero** y ICG tiene decimales (ej. AL2 = 5 474,8 u en el Excel): ver riesgo R1.
- **RF6 — Kardex.** Cada fila sembrada deja una línea inmutable en `inventory_movements` (tipo `Ajuste`, referencia
  «Carga inicial desde ICG (SCRUM-1136 F6) — almacén XXX», `saldo_inicial = 0`), vía `KardexService::registrar()`, para que el
  inventario físico posterior tenga trazabilidad. El stock sembrado y el Kardex no pueden quedar desalineados.
- **RF7 — Exclusiones.** Línea de servicio `68602` (−1, no es un bien físico): excluida del stock (propuesta, ver decisión 4/riesgos).
  Filas con `CODALMACEN` vacío (6, stock 0) y filas con stock 0: no se cargan.
- **RF8 — Verificación.** `legacy:verify-stock-bodegas` (solo lectura): por almacén mapeado compara suma de `stock_almacen` esperada
  (tras exclusiones) vs `SUM(product_warehouse_stock.quantity)` de la bodega, lista productos sin resolver, filas omitidas y
  diferencias por redondeo. Debe dar diferencia 0 salvo las exclusiones y el redondeo declarados.
- **RF9 — Visibilidad.** Los listados de bodega filtran `catalog_products.is_active = true`: el stock de los 83 inactivos de F5 no se
  vería (decisión 5). No se cambia ningún listado en F6 salvo que Luis lo decida.
- **RF10 — Reversa.** Comando `legacy:revertir-stock-bodegas {--apply}` que borra solo las filas y movimientos creados por la carga
  (identificados por los ids guardados en el evento de auditoría) y solo si no tuvieron movimientos posteriores. Runbook corto en
  `docs/runbooks/`. Cambio destructivo: confirmación explícita de Luis y, en prod, backup verificado antes.

## 4. Criterios de aceptación
1. **Dado** que ninguna equivalencia está `confirmado`, **cuando** corre el comando con `--apply`, **entonces** no escribe nada y lo informa.
2. **Dado** un almacén confirmado, **cuando** corre sin `--apply`, **entonces** imprime por almacén filas a crear, unidades, valor a costo,
   omitidas por causa, y no modifica ninguna tabla.
3. **Dado** `--apply` sin `--operador`, **entonces** falla sin escribir.
4. **Dado** un (producto, bodega) que ya tiene fila, **entonces** no se modifica y se cuenta como «ya existía».
5. **Dado** una bodega `solo_consulta` o Bodega Central como destino, **entonces** el comando aborta antes de escribir.
6. **Dado** una discrepancia en la verificación posterior al lote, **entonces** ROLLBACK de ese almacén y código de salida ≠ 0.
7. **Dado** que se corre dos veces, **entonces** la 2ª no crea nada (idempotente).
8. **Dado** un descatalogado equivalente a un activo, **entonces** el stock cae sobre el `catalog_product_id` del activo, sin duplicar producto.
9. Cada fila creada tiene su línea de Kardex con `saldo_resultante` igual a `quantity`.
10. Tras la carga en dev, `legacy:verify-stock-bodegas` = OK y las pantallas de Bodega muestran las existencias del showroom contra el Excel del 13-oct
    (AL2 / B1 / B2) con diferencia explicada.
11. La reversa deja `product_warehouse_stock` e `inventory_movements` como estaban (verificado por conteo y suma).

## 5. Riesgos
- **R1 — Decimales.** `quantity` es entero; AL2 tiene cantidades como 5 474,8 u en total (probablemente metros/rollos). Redondear por fila
  pierde o inventa unidades. Necesita decisión del Arquitecto: redondeo (mitad hacia arriba), o excluir fraccionarios a lista de revisión, o
  cambio de tipo (migración mayor, fuera de alcance). **Medir antes de decidir** cuántas filas son fraccionarias y de qué artículos.
- **R2 — Negativos.** `product_warehouse_stock` admite negativos (SCRUM-1016 los reflejó tal cual) pero `KardexService::registrar()` y las
  reubicaciones (SCRUM-837) pueden rechazar o comportarse mal con base negativa. Probar antes de cargar.
- **R3 — Efecto en disponibilidad.** Bodegas como Merma/Reclamos/Reserva pueden tener `excluye_disponible`; cargar 28 k u en Merma no debe
  inflar el «disponible» de cotización/pedidos. Verificar la bandera por bodega antes del `--apply`.
- **R4 — Costo y valor.** Los ≈1,1 M de valor fuera de AL4 aparecerán en reportes de inventario/Gerencia de golpe. Avisar a Gerencia1/Aprobador antes de prod.
- **R5 — Doble conteo.** Si algún artículo ya tiene stock en `catalog_products.stock_quantity` (AL4) y se agrega en otra bodega, los totales
  por producto cambian; confirmar qué suma usa Compras («Ver Inventario») para no duplicar.
- **R6 — Performance.** ~4 300 filas fuera de AL4: carga pequeña, sin riesgo de memoria; por lotes de 500 como el precedente.
- **R7 — Prod es destructivo en datos.** Orden: dev → test → prod, cada uno con dry-run revisado por Luis antes del `--apply`.

## 6. Decisiones de Luis (2026-10-15)
1. **Mapeo:** confirmados AL2, B1, B2, ALX→Merma, ALY→Reclamos y Devoluciones, ALR→Reserva Servicios.
2. **Sin equivalente (A1, ALM, AL1, AL3):** se omiten en esta pasada; segunda pasada cuando el negocio explique qué es A1.
3. **Negativos:** se migran tal cual (como SCRUM-1016). Como AL1/AL3/ALM quedan omitidos, aplica a los negativos de los almacenes mapeados; riesgo R2
   (Kardex/reubicaciones con base negativa) se prueba en F6a antes de cargar.
4. **Artículo 2999 y servicio 68602:** se excluyen del stock y van a la lista de revisión manual.
5. **Inactivos de F5:** F6 solo carga su stock; hacerlos visibles (filtro «con stock» en Bodega) es un ticket aparte.
6. **Los 42 artículos activos de B2 sin catálogo:** se crean en `catalog_products` como **inactivos** (mismo patrón que F5, dry-run por defecto) para poder cargar su stock.

## 7. Fuera de alcance
Cambios de tipo de columna (decimales), pantallas nuevas, sincronización continua con ICG, ajuste con inventario físico, depuración de `legacy.movimientos`
(sección 10 del diseño), y cualquier cambio a Bodega Central/AL4.

## 8. Plan de fases propuesto (para el Arquitecto/PM)
1. **F6a** — medir (solo lectura): filas fraccionarias, flags `excluye_disponible`/`solo_consulta` de cada bodega en los 3 entornos, comportamiento de Kardex con negativos. Cierra R1–R3.
2. **F6b** — sembrar `almacen_equivalencias` (seeder idempotente solo-si-no-existe) + comando de carga + verificación + tests con fixtures sin red.
3. **F6c** — dev: dry-run → revisión de Luis → `--apply` → verify → cotejo contra Excel.
4. **F6d** — test, luego prod (dry-run, backup verificado, `--apply` por Luis, verify). Reversa lista y probada en dev antes de prod.
Senior Review en F6b; Pre-QA en F6c (criterio 10). Sin migraciones nuevas salvo que R1 lo exija.

## 9. Resultados de F6a (medición en dev, solo lectura, 2026-10-15)
- **R1 decimales: casi inexistente.** De 8 almacenes/filas mapeadas solo **1 fila** es fraccionaria (AL2, artículo 66152 = 5,8 u). Redondear toda la carga cambia AL2 de
  5 503,8 a 5 504 (+0,2). Propuesta: redondeo normal, sin migración de tipo; el Excel (5 474,8) ya difiere por otras causas (descatalogados, servicio 68602).
- **Banderas de bodega (dev):** Merma, Reclamos y Reserva Servicios tienen `excluye_disponible = true` (no inflan el disponible, R3 resuelto); Showrooms y Bodega Central
  `false`; Zona Libre `solo_consulta` (sin equivalente, correcto).
- **Volumen a cargar (stock ≠ 0):** AL2 2 081 filas (35 negativas, −87 u), ALX 847 (17 neg, −360), ALY 994, B1 93, B2 107, ALR 7 — ≈4 130 filas. Unidades: AL2 5 504,
  ALX 28 186, ALY 13 043, B1 660, B2 683, ALR 99.
- **Negativos:** `product_warehouse_stock` en dev ya tiene 226 filas negativas, así que el modelo los admite. **Sigue pendiente** probar `KardexService::registrar()` con
  saldo inicial 0 y cantidad negativa y una reubicación posterior (R2) en F6b con tests.
- **Sin `catalog_product_id` resoluble (dev):** AL2 6 filas/28 u, ALX 7/47, ALY 4/78, **B2 42 filas/78 u**. AL2 coincide con los 8 descatalogados de F5 (en dev el `--apply` de F5
  no se corrió; sí en test/prod). B2 con 42 filas es llamativo: revisar en F6b si son artículos fuera del catálogo o servicios antes de decidir crear/omitir.
- **R5/estado actual:** AtlanticERP solo tiene stock en Bodega Central (6): 3 530 filas / 79 407 u. Ninguna otra bodega tiene filas, no hay riesgo de pisar.
- **Faltante de F6a:** costo/valor por almacén sobre los códigos resueltos (para el aviso a Gerencia1/Aprobador) — se calcula con el dry-run del comando.

### Hallazgo B2 (2026-10-15): 42 artículos activos de ICG que nunca llegaron al catálogo
Las 42 filas de B2 sin `catalog_product_id` (78 u) son artículos **activos** (`descatalogado = false`) con `icg_codarticulo` 73258→73333, ya en `legacy.productos` pero
sin fila en `catalog_products`: ambientes de showroom (lámparas Ambrosia/Gambosa/Lauro y, sobre todo, **mobiliario**: sofá Epic, sillas Puck/Wing/Qing, mesas Spin/Valy, cojines,
carretilla). Se dieron de alta en ICG después de que el sync de productos dejó de crear catálogo. Por tanto F6 necesita resolver una **sexta decisión (RF11)**: crearlos en
`catalog_products` o dejarlos fuera. Decidido por Luis: crearlos como inactivos (sección 6, punto 6).
