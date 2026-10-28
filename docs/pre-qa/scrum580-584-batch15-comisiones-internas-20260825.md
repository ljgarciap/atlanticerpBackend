# Pre-QA + Visual Review fusionado — Batch 15 Comisiones Internas, parte 2 (SCRUM-580→584, REQ-503→507)

**Fecha:** 2026-08-25. Contra `dev.atlanticerp.ai` (backend `8301262` → luego el fix de este documento;
frontend `a1cc708`, sin cambios). Ver `docs/adr/ADR-SCRUM580-584-batch15-comisiones-internas.md` y
`docs/reviews/scrum580-584-batch15-comisiones-internas-20260825.md`. Con este batch, Comisiones
Internas queda completo (10/10 tickets, Batch 14+15).

## Metodología

Playwright CLI (config descartable apuntando `baseURL: https://dev.atlanticerp.ai` — el
`playwright.config.ts` del repo apunta a `localhost:5173` por defecto, así que una corrida sin
ese override real prueba el stack local, no dev; se detectó esto en el camino cuando los ids de
usuario del DOM no coincidían con los reales de `dev.atlanticerp.ai`) + curl directo contra la API real
con tokens de Contabilidad/Aprobador/[persona] (vendedora real) + SSH/tinker de solo lectura para inspeccionar
datos reales y sembrar temporalmente un `compartido_con` de prueba (despoblado al terminar).
Usuarios reales, nunca demo — Contabilidad (`conta@atlantic.com.pa`), Aprobador
(`gerencia3@test.com`), [persona] (`usuario@example.test`, vendedora real).

## SCRUM-580 (REQ-503) — Notas de crédito restan la base de comisión — LIMPIO

Datos reales en dev (sin fabricar nada): vendedor [persona] [persona] (id 35) tiene 3 pedidos comisionables
en agosto 2026 con NC reales aplicadas:
- Pedido 215 / factura F-0002: NC-0002 por USD 3,293.65 sobre una base de USD 3,293.65 → comisión
  USD 0.00 (NC cubre el 100%).
- Pedido 214 / factura F-0001: NC-0003 por USD 850.00 (abono parcial) → base neta reducida,
  comisión USD 86.06.
- Pedido 215 / factura F-0003 (sin NC): comisión normal USD 49.40.

Verificado en UI (Contabilidad, filtrado a [persona] [persona], fila expandida): la columna "Nota crédito" muestra
en rojo `-USD 3,293.65 (NC-0002)` y `-USD 850.00 (NC-0003)`, tal como pide RN3. Solo notas
`estado='aplicada'` restan — confirmado por lectura de código (`CommissionOrderService::rawOrders()`
filtra `cn.estado = 'aplicada'` en la subquery) y por los tests explícitos del batch
(`test_nota_credito_aplicada_reduce_la_base_de_comision` /
`test_nota_credito_pendiente_de_aprobacion_no_reduce_la_base`). No hay en dev hoy un pedido con NC
`pendiente_aprobacion`/`rechazada` para ejercitarlo en vivo end-to-end — no bloquea, cubierto por
test explícito.

**Lo que sí funciona:** base = cobrado/facturado − NC (nunca negativa), referencia visible con
número de nota, mismo criterio en la tabla principal y en el estado de cuenta (REQ-507).

## SCRUM-581 (REQ-504) — Arrastre de pedidos de meses cerrados aún sin cobrar — LIMPIO (con 1 limitación de datos documentada, no bloqueante)

Escenario 1 (arrastre visible) verificado con datos reales: viendo agosto 2026 (mes en curso),
[persona] [persona] muestra 3 grupos — agosto (actual), julio arrastrado (2% fijo), abril arrastrado (2%
fijo) — los pedidos de julio/abril siguen `pendiente_cobro`, nunca se cobraron, y siguen
apareciendo correctamente marcados como arrastrados.

Escenario 2 (deja de arrastrarse una vez pagado) — **no hay en dev ningún pedido en estado
`pagado` todavía** (ninguno de los 22 pedidos comisionables reales llegó a cobro completo). No se
pudo ejercitar en vivo end-to-end. Cubierto por test explícito
(`test_arrastre_pedido_ya_pagado_deja_de_aparecer`) y confirmado por lectura de código
(`relevantOrders()` excluye `estado === 'pagado'` de meses anteriores al `viewMonth`) — no bloquea
según el criterio acordado con Luis para esta pasada.

## SCRUM-582 (REQ-505) — Tabla de vendedores: expandir/colapsar y detalle por pedido — **CRÍTICO encontrado y corregido**

### Hallazgo (antes del fix)
Escenario 1 (expandir, agrupar por mes) — limpio, verificado con [persona] [persona] (3 grupos) e [persona]
[persona].

Escenario 2 ("un vendedor sin ningún pedido registrado, al expandir muestra 'Sin pedidos
registrados todavía.'") — **CRÍTICO**. `vendedor_disenador` real "Designer Demo" (id 48, sin
ningún pedido en TODA su historia) es seleccionable en el filtro de vendedor
(`GET .../vendors` lista el rol completo), pero al filtrar la tabla a ese vendedor específico la
API devolvía `vendedores: []` — la UI mostraba el estado genérico "Sin vendedores para este
filtro." y **no existía ninguna fila para expandir**, en vez de una fila con "Sin pedidos
registrados todavía." como pide el criterio literal. Reproducido tanto por API directa
(`curl .../commissions/internal?vendedor_id=48` → `vendedores: []`) como visualmente en Playwright
contra `dev.atlanticerp.ai` (ver `e2e/preqa-visual-batch15-scrum580-584-comisiones-internas-20260825.spec.ts`).

**Causa raíz:** `AdminContInternalCommissionController::buildSummary()` construía `vendorIds`
únicamente a partir de `CommissionOrderService::ordersForAllVendors()->pluck('vendedor_id')` — un
vendedor sin ningún pedido en ningún mes nunca aparece en ese universo, así que nunca tenía fila,
sin importar el filtro. Comportamiento heredado de Batch 14 (la función no cambió en Batch 15),
pero el criterio explícito de "vendedor sin pedidos" es de REQ-505 (Batch 15) — se corrige acá.

### Fix aplicado (chico, contenido — corregido en el momento, no diferido)
`app/Modules/AdminCont/Http/Controllers/AdminContInternalCommissionController.php`: `vendorIds`
ahora es la unión de `allOrders.pluck('vendedor_id')` (preserva cualquier `owner_id` histórico que
no sea `vendedor_disenador`, sin quitar nada que ya se veía) con el roster completo del rol
`vendedor_disenador` (mismo query ya usado por `vendorOptions()`, extraído a
`vendedorDisenadorIds()` para no duplicar). 2 tests nuevos:
`test_summary_vendedor_sin_ningun_pedido_en_su_historia_aparece_como_fila_vacia` y
`test_summary_sin_filtro_incluye_vendedores_sin_ningun_pedido`.

### Re-verificación tras el fix
- `infra/test.sh --filter=AdminContInternalCommissionControllerTest` → 30/30 OK.
- `infra/test.sh --filter=AdminCont` (suite completa del módulo) → 205/205 OK.
- `infra/test.sh` (suite completa backend) → 2591/2591 tests, 10502 assertions, OK.
- PHPStan Level 8 (`--memory-limit=1G`, archivo tocado y suite completa) → limpio, 682 archivos.
- Frontend (sin cambios de código, solo el spec de e2e): `tsc --noEmit` limpio, `npm run build`
  limpio, `npm run test -- --run` → 1512/1512.
- Deploy manual a `dev.atlanticerp.ai` (backend `a124f24`) vía SSH — CI/CD sigue caído por el corte de
  billing de la org (`gh run list` confirma jobs sin logs, fallan en ~4s, mismo patrón que
  `project_github_actions_billing_outage_20260820`).
- Re-verificado en vivo contra `dev.atlanticerp.ai` post-deploy con Playwright (spec completo, 6/6):
  filtrar a "Designer Demo" ahora muestra su fila (antes: tabla vacía, "Sin vendedores para este
  filtro.") y al expandirla se ve "Sin pedidos registrados todavía." — confirmado.

## SCRUM-583 (REQ-506) — Proyectos compartidos entre 2+ vendedores — LIMPIO

Sin datos reales de `compartido_con` en dev (campo sin UI de asignación, por diseño — ver ADR §4).
Sembrado temporalmente vía tinker (`pipeline_cards.id=184`, owner [persona] [persona], `amount=549.27`,
`compartido_con=[42]` → [persona] Gomez), verificado end-to-end en UI real y despoblado al terminar
(`compartido_con=null` de vuelta).

Verificado con Contabilidad, ambos responsables por separado:
- [persona] [persona] ve `total_pedido = USD 274.64`, badge "↔ Compartido con [persona] Gomez · USD
  549.27 total del proyecto".
- [persona] Gomez ve `total_pedido = USD 274.64`, badge "↔ Compartido con [persona] [persona] · USD
  549.27 total del proyecto".
- Comisión de cada uno calculada sobre su parte ($274.64 × 1.5% = $4.12), nunca sobre el total del
  proyecto — confirmado por API y por UI.

División en partes iguales entre 2 y 3 responsables ya cubierta por tests explícitos del batch
(`test_proyecto_compartido_divide_el_monto_en_partes_iguales_entre_2/3`).

## SCRUM-584 (REQ-507) — Estado de cuenta de comisiones (documento) — LIMPIO

Verificado en UI real (modal "Estado de cuenta de comisiones", botón "Ver estado de cuenta" desde
la fila expandida de [persona] [persona]):
- **Mes en curso (agosto):** 3 grupos (agosto/julio arrastrado/abril arrastrado), 3 totales
  (Pagada/Por pagar/Pendiente de cobro), línea "Descuento total por notas crédito -USD 62.15"
  (= NC total USD 4,143.65 × 1.5%). Coincide con la API (`descuento_nota_credito: 62.15`).
- **Mes cerrado (julio):** nota "Este estado de cuenta muestra solo lo efectivamente pagado en
  este mes...", sin pedidos (no hay ninguno `pagado` en julio todavía — RN1 correcto, mismo dato
  real que el hallazgo documentado en REQ-504), sin filas de "Por pagar"/"Pendiente de cobro" en
  el bloque de totales (correcto, esos solo aplican a mes en curso).
- **Botón "Ver/Imprimir PDF":** dispara descarga real sin error (confirmado con Playwright
  `waitForEvent('download')` y por curl directo — `Content-Type: application/pdf`, 1 página válida,
  `pdfinfo` la confirma bien formada).
- **Permisos (server-side, no solo UI):** [persona] (vendedora, id 34) — `GET
  .../35/account-statement` (otro vendedor) → 403. `GET .../34/account-statement` (propio) → 200.
  `GET .../35/account-statement/pdf` (PDF de otro) → 403. Contabilidad/Aprobador de cualquier vendedor → 200.

## Iconografía (regla SCRUM-56)

Confirmado por barrido de texto (regex de rangos Unicode de emoji sobre `body.innerText()`, login
Aprobador) — sin ningún emoji en toda la pantalla de Comisiones Internas, incluidos el modal de estado
de cuenta y el modal de tramos. Solo iconos SVG del set propio (`IcoBarChart`, `IcoDownload`,
`IcoChevronDown/Left/Right`, `IcoPrinter`, `IcoClose`).

## Veredicto por ticket

| Ticket | REQ | Veredicto |
|---|---|---|
| SCRUM-580 | 503 — NC restan base | **LIMPIO** |
| SCRUM-581 | 504 — Arrastre correcto | **LIMPIO** (1 escenario sin dato real en dev, cubierto por test, no bloqueante) |
| SCRUM-582 | 505 — Expandir/agrupar/vendedor sin pedidos | **CRÍTICO encontrado, corregido en el momento, re-verificado en vivo contra dev.atlanticerp.ai — LIMPIO** |
| SCRUM-583 | 506 — Proyectos compartidos | **LIMPIO** |
| SCRUM-584 | 507 — Estado de cuenta (documento) | **LIMPIO** |

**Los 5 tickets del batch están limpios y listos para pasar a QA.** Backend `a124f24` y frontend
`4fb3050` desplegados/pusheados a `dev` (deploy manual del backend por SSH — CI/CD sigue caído por
la misma outage de billing ya documentada). Pasada limpia y concluyente — sin hallazgos
bloqueantes pendientes de este batch.

## Test permanente

`atlanticerp-frontend/e2e/preqa-visual-batch15-scrum580-584-comisiones-internas-20260825.spec.ts` — se
promueve a permanente (no se borra) porque cubre un gate real que ya se rompió una vez (REQ-505
"vendedor sin pedidos") además de permisos server-side — mismo criterio que
`feedback_e2e_no_desechar`.
