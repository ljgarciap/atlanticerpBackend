# Pre-QA — SCRUM-205/227/235/236 (rebotes de QA del 2026-07-20)

Contexto: los 4 tickets ya habían pasado por QA (qa) y fueron rechazados hoy con hallazgos
CRÍTICOS concretos. Root cause de fondo (ver `feedback_protocolo_senior_review_antes_dev_testing.md`):
las pasadas de "Pre-QA" anteriores para estos 4 tickets estaban rotas de 3 formas distintas —
saltadas del todo (235/236), probando un criterio distinto al literal del ticket (227), o
ejecutadas pero admitiendo explícitamente no haber ejercitado el camino central y pasándolo a QA
igual (205). Esta pasada corrige el código real y vuelve a verificar en vivo contra `dev.atlanticerp.ai`
(Playwright, no solo lectura de código ni curl a la API) — cada Given/When/Then literal del
ticket, más intentos de ruptura (Paso 3).

## SCRUM-235/236 (REQ-172/173) — chip "Por ingresar" + confirmar

**Causa raíz:** `InventoryController::index()` forzaba `$products = []` cuando `chip=por_ingresar`,
sin importar los datos reales — un resabio de una lectura incorrecta del alcance de REQ-170
(confundía "producto nuevo por alta automática, sin implementar" con "producto existente con
unidades pendientes", que SÍ debía listarse).

**Fix:** filtrar por `porIngresarMap[$p->id] > 0` (servicio ya existente, `InventoryKpiService`).

**Verificado en vivo (dev.atlanticerp.ai, Playwright):**
| Escenario | Resultado |
|---|---|
| Producto con unidades "Por ingresar" reales → aparece en el chip | ✅ |
| Botón "Ingresar a Inventario" visible y funcional desde el detalle | ✅ |
| Tras confirmar, el chip ya no lo lista (round-trip completo) | ✅ |
| Paso 3 — doble confirmación del mismo producto (envío duplicado) → 422 | ✅ bloqueado correctamente |

## SCRUM-227 (REQ-164) — costeo diferenciado local/no-local, observabilidad del reparto

**Causa raíz:** el backend SÍ calculaba el prorrateo proporcional (import/flete/manejo/otros) pero
solo lo pisaba como snapshot per-unit en `catalog_products` — nunca se persistía ni se exponía por
línea de recibo. El criterio literal ("la primera línea recibe $200 de flete") no era observable
en ningún lado: ni en el wizard, ni en el detalle guardado.

**Fix:** columnas nuevas `import_cost_share/freight_cost_share/handling_cost_share/other_cost_share`
en `purchase_order_receipt_lines` (persisten el TOTAL asignado a la línea, no el per-unit), método
`totalCost()`, expuestos en la respuesta (`cost_total`). Columna "Costo Total" agregada al wizard
(preview client-side en vivo, misma fórmula que el backend) y a la vista de detalle guardado.

**Verificado en vivo (dev.atlanticerp.ai):**
| Escenario | Resultado |
|---|---|
| Esc.1 (no local, 2 líneas 2:1, flete $300) → $200/$100 exactos, vía API | ✅ |
| Esc.1 — mismo resultado visible en el detalle guardado (UI, Costo Total $400.00/$200.00) | ✅ |
| Esc.2 (local, ITBMS 7%) → wizard real muestra $53.50 en un producto de $50 (5×$10×1.07) | ✅ |
| Esc.2 — sin campos de gastos totales visibles para proveedor local | ✅ |
| Guardado exitoso sin error | ✅ |

## SCRUM-205 (REQ-142) — auto-confirmación al llegar a "Recibido"

**Causa raíz #1 (la documentada):** `PurchaseOrderController::advance()` nunca invocaba la lógica
de confirmación — el docblock decía explícitamente que quedaba diferido porque Ingreso de
Mercancía no existía. Ya existe; se conectó vía `GoodsReceiptConfirmationService::confirmPendingForOrder()`.

**Causa raíz #2 (encontrada en ESTA pasada, no en el hallazgo original de qa):** un producto
creado por "Nueva Orden → + Producto nuevo" nunca inicializa `stock_quantity` (queda `NULL` por
diseño — "disponibilidad no controlada"). `increment()`/`decrement()` sobre una columna `NULL`
evalúan `NULL` en SQL, no el número esperado — el stock de CUALQUIER producto pedido por Nueva
Orden nunca se actualizaba, sin importar cuántas veces se confirmara. Esto coincide con el patrón
exacto que qa reportó 3 veces ("stock nulo") — muy probablemente la causa real detrás de sus
rechazos repetidos, más allá de la falta de wiring. Corregido con `COALESCE(stock_quantity, 0)` en
ambos puntos (`confirmLine`, `reverseConfirmedEffects`).

**Verificado en vivo (dev.atlanticerp.ai, Playwright + API):**
| Escenario | Resultado |
|---|---|
| Antes de "Recibido": producto sin stock, `por_ingresar=6` | ✅ |
| Click real en "Avanzar estado" (Ordenado → En tránsito local) | ✅ |
| Click real en "Avanzar estado" (→ Recibido) | ✅ |
| Tras Recibido: `stock_quantity=6`, `por_ingresar=0` (auto-confirmado, sin acción manual) | ✅ |
| Botón "Avanzar estado" ya no existe (Recibido es terminal) | ✅ |
| Paso 3 — reintentar avanzar una orden ya Recibida → 422 | ✅ bloqueado correctamente |

## Lo que sí funciona (sin cambios, re-confirmado)

- Ciclo de vida de estados de la orden (Ordenado → En tránsito local / En tránsito+Aduana → Recibido).
- Ingreso de Mercancía: registro, validación de cantidad máxima, entrega parcial/modal.
- Permisos: `compras.write` para crear orden, `compras.edit` para registrar ingreso/avanzar/confirmar.

## Pendiente fuera de alcance (no bloqueante para estos 4 tickets)

- `ITBMS_RATE_LOCAL = 0.07` sigue hardcodeado (`GoodsReceiptController`), no en tabla paramétrica —
  preexistente, no introducido por este batch. Deja constancia para no repetir el hallazgo la
  próxima vez que se toque este archivo (regla de "no hardcodear valores de negocio").

## Regresión agregada

- `InventoryControllerTest::test_chip_por_ingresar_lista_producto_existente_con_unidades_pendientes`
- `GoodsReceiptControllerTest::test_proveedor_no_local_prorratea_gastos_proporcionalmente` (extendido
  con aserciones de `freight_cost_share`/`cost_total` por línea)
- `PurchaseOrderLifecycleTest::test_avanzar_orden_a_recibido_confirma_pendientes_de_ingreso_automaticamente`
- `PurchaseOrderLifecycleTest::test_avanzar_orden_a_recibido_confirma_producto_creado_sin_stock_inicial_null`

Suite completa: 1004/1004 backend (PHPStan Level 8 limpio), 381/381 frontend (build + tsc limpios).

## Pasada: LIMPIA — los 4 tickets pasan a QA
