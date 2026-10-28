# SCRUM-208 — Rediseño de recepción parcial (REQ-145)

> **ACTUALIZADO 2026-08-15 (misma sesión) — Backend implementado con alcance REVISADO.** La
> investigación real (leer la sección nueva de abajo) encontró que la causa raíz no era la
> planteada originalmente — no hizo falta la migración de desacople de crédito de stock (Tareas 1
> y 3 del plan original de Backend Dev, más abajo) porque esa capacidad YA EXISTÍA. Ver
> "Hallazgo real 2026-08-15" para el detalle. Backend + Frontend implementados, tests verdes, SIN
> pushear todavía — ver memoria del equipo
> (`project_scrum208_recepcion_parcial_rediseno_pendiente_20260815.md`) para el estado exacto y la
> decisión de Luis sobre cuándo pushear.

## Hallazgo real 2026-08-15 (revisa el "Root cause confirmado" original de abajo)

La investigación original (más abajo, "Root cause confirmado") asumía que el crédito de stock de
una recepción parcial estaba SIEMPRE atado a que la orden completa llegara a `STATUS_RECIBIDO` —
eso es cierto solo para el camino AUTOMÁTICO (`advance()` → `confirmPendingForOrder()`). Existe un
segundo camino, ya implementado y funcional, independiente del `status` de la orden:
`GoodsReceiptController::confirm()`/`confirmPendingForProduct()` ("Ingresar a Inventario") ya
permite confirmar manualmente una línea "Por ingresar" y acreditar stock en cualquier momento.

**El problema real no era de backend, era de UX/alcance de endpoint:** ese botón manual solo vive
en la pantalla genérica `/inventario` (`InventarioPage.tsx`), acotado por PRODUCTO a través de
TODAS las órdenes — desconectado del contexto de Ver Órdenes/Logística donde Gerencia1 realmente
trabaja, y sin forma de acotarlo a UNA orden puntual (riesgo real: confirmar de más si el mismo
producto tiene líneas pendientes en otra orden). Eso explica su reporte — no que la plataforma no
pudiera acreditar el stock, sino que no había ninguna forma descubrible de hacerlo desde donde ella
estaba parada.

**Fix real implementado (mucho más chico que el diseño original):**
1. `PurchaseOrderController::confirmPendingReceipts()` — endpoint nuevo,
   `PATCH /compras/orders/{id}/confirm-pending-receipts`, reusa
   `GoodsReceiptConfirmationService::confirmPendingForOrder()` (¡ya existía, con su propio
   lock/transacción!) pero acotado a la orden, sin esperar a `STATUS_RECIBIDO`.
2. `PurchaseOrderController::advanceRemainder()` — el endpoint que SÍ hacía falta desde el
   diseño original (nada existía para esto), sin cambios respecto al plan original.
3. **NO hizo falta** ninguna migración/cambio para desacoplar `confirmPendingForOrder()` de
   `advance()` — se sigue llamando igual que antes desde ahí, y AHORA TAMBIÉN desde el endpoint
   nuevo. Cero riesgo de reintroducir el bug histórico de cuadriplicación de stock (mismo método,
   mismo lock interno, solo un segundo punto de entrada).

**✅ CERRADO end-to-end 2026-08-15, misma sesión.** El botón "Ingresar a Inventario" ya está
conectado en `OrderDetailPage.tsx`/`LogisticsPage.tsx`, junto al de "Completar etapa del
remanente", dentro del mismo aviso ámbar. Verificado en vivo (no solo tests mockeados) contra el
stack local real: orden de prueba con remanente pendiente + línea de recepción sin confirmar,
clic en "Ingresar a Inventario" desde el detalle de la orden →
`stock_quantity` 0→1, exactamente 1 movimiento de Kardex (sin duplicar), `status` de la orden
nunca tocó "Recibido" — el escenario exacto que Gerencia1 reportó. Datos de prueba limpiados al
terminar. Ver memoria del equipo para el detalle completo de la sesión.

## Problema (rebote real de Gerencia1 Test, 2026-08-12)

Orden de 10 unidades, llegan 1. Esperado:
- La unidad recibida queda `Recibido` de forma INDEPENDIENTE — disponible como stock real en
  Inventario ya mismo, sin esperar al resto.
- Las 9 pendientes siguen su propio flujo de etapas (Ordenado→En tránsito→En aduana→En tránsito
  local→Recibido), de forma independiente de que 1 unidad ya haya llegado.
- La orden solo se da por completamente recibida cuando el 100% de todas sus líneas llegó a
  `Recibido` — esto YA es correcto en el código actual (`PurchaseOrderController::advance()`,
  gate contra `pending_remainder_status`), no tocar.

## Root cause confirmado (código actual)

1. `PurchaseOrder.status` es un único campo por ORDEN completa — no hay status por línea ni por
   remesa.
2. `pending_remainder_status` (REQ-165, valores `REMAINDER_ORDENADO`/`REMAINDER_SALIO_DE_ORIGEN`/
   `REMAINDER_EN_TRANSITO`/`REMAINDER_EN_ADUANA`/`REMAINDER_EN_TRANSITO_LOCAL`) existe y se setea
   al registrar una recepción parcial, pero es **puramente informativo** — ningún endpoint lo
   avanza. Por eso el botón "Completar etapa" en Logística no hace nada: no hay ninguna acción
   detrás para el remanente.
3. `GoodsReceiptConfirmationService::confirmPendingForOrder()` — el único lugar que aplica el
   efecto de stock real (`InventoryMovement::TIPO_ENTRADA`) de una línea "Por ingresar" — **solo se
   dispara desde `PurchaseOrderController::advance()` cuando la orden completa llega a
   `STATUS_RECIBIDO`**. Como el gate del punto 2 impide que eso pase mientras haya remanente
   pendiente, el stock de la parte YA recibida queda diferido indefinidamente. Esta es la causa
   exacta de "la unidad recibida no aparece en Inventario".

## Decisión de diseño (Arquitecto, a confirmar por Luis antes de ejecutar)

**El remanente se modela como un campo suelto en `PurchaseOrder` (evolución de
`pending_remainder_status`), NO como una tabla/entidad nueva.** Razón: en este negocio el
remanente de una orden es conceptualmente UNO solo — sucesivas recepciones parciales sobre la
misma orden reducen la cantidad pendiente del MISMO remanente, no crean remanentes paralelos con
ciclos de vida independientes. Una tabla `purchase_order_remainders` sería más "correcta" en
abstracto pero es sobre-ingeniería para el caso real reportado; se puede revisar si en producción
aparece un caso de remanentes verdaderamente paralelos (ej. 2 sub-envíos con transportistas
distintos) — no hay evidencia de eso hoy.

**Riesgo conocido a evitar (ya documentado en el código):** el docblock de
`GoodsReceiptConfirmationService` menciona un bug histórico real de cuadriplicación de stock por
llamar el crédito de stock más de una vez sobre la misma línea. Cualquier cambio acá DEBE incluir
un test de regresión que reproduzca ese escenario exacto antes de tocar el código.

## Cambios de modelo de datos

Migración nueva sobre `purchase_orders` — agregar (mirror de `STAGE_DATE_COLUMNS`, mismo patrón ya
usado para las etapas de la orden):

```
remainder_status_changed_at   timestamptz nullable
remainder_ordenado_at         timestamptz nullable
remainder_en_transito_at      timestamptz nullable
remainder_en_aduana_at        timestamptz nullable
remainder_en_transito_local_at timestamptz nullable
```

Sin cambios en `purchase_order_lines`, `purchase_order_receipts` ni `purchase_order_receipt_lines`
— esos modelos ya alcanzan.

## Cambios de backend

### 1. Desacoplar el crédito de stock del `status` de la orden

Mover (o duplicar con guardas) el crédito de stock de una `PurchaseOrderReceiptLine` al momento en
que ESA línea queda confirmada (`confirmed_at` se setea), no al momento en que la ORDEN completa
llega a `STATUS_RECIBIDO`.

**Antes de tocar código:** confirmar en qué endpoint/acción se setea hoy `confirmed_at` de una
`PurchaseOrderReceiptLine` — no está 100% localizado en esta investigación (candidato:
`GoodsReceiptController::saveReceipt()`, pero puede ser un paso de confirmación aparte hecho por
Inventario/Bodega). Este es el primer paso real de implementación, no asumir.

Una vez localizado: disparar ahí el mismo efecto que hoy aplica
`GoodsReceiptConfirmationService` para líneas "Por ingresar". `confirmPendingForOrder()` (todavía
llamado desde `advance()` al llegar a `STATUS_RECIBIDO`) pasa a ser una red de seguridad para
cualquier línea que por lo que sea siga sin crédito de stock al cerrar la orden — sigue
funcionando igual porque solo procesa líneas con `confirmed_at === null`, así que no debería
volver a acreditar una línea ya acreditada, pero **esto se verifica con un test explícito**, no se
asume.

### 2. Endpoint nuevo — avanzar el remanente de etapa

`PATCH /compras/orders/{id}/advance-remainder` → `PurchaseOrderController::advanceRemainder()`.
Mismo patrón de `lockForUpdate()` + transacción que `advance()` (ver comentario sobre condición de
carrera ya resuelta ahí, reusar el patrón, no reinventar).

- 422 si `pending_remainder_status === null` (no hay remanente que avanzar).
- Avanza por la secuencia ya existente `PurchaseOrder::REMAINDER_STATUSES`.
- Setea la columna de fecha correspondiente (ver migración arriba) y
  `remainder_status_changed_at`.
- Cuando el remanente llega a `REMAINDER_EN_TRANSITO_LOCAL` y se registra el ingreso que lo cubre
  por completo, `pending_remainder_status` se limpia (esto YA pasa hoy en el flujo de
  `GoodsReceiptController` — confirmar que sigue funcionando igual, no debería requerir cambios).
- Permiso: mismo `permission:compras.edit` que `advance()`.

### 3. Caso de 2+ recepciones parciales sucesivas sobre la misma orden

No requiere cambio de modelo (el remanente sigue siendo el mismo campo, solo se reduce la cantidad
pendiente real vía `receptionSummary()`, que ya calcula por diferencia contra lo recibido
acumulado). **Sí requiere decisión de producto a confirmar con Gerencia1**: si el remanente ya
avanzó manualmente a, por ejemplo, "En tránsito", y llega una NUEVA recepción parcial que cubre
solo una parte de lo que quedaba — ¿la etapa del remanente se mantiene donde estaba, o el usuario
puede/debe re-declararla? Asunción de este diseño: se mantiene donde estaba (el remanente es
sobre CANTIDAD, la etapa es independiente de cuánta cantidad queda) — confirmar antes de cerrar el
batch, no asumir en silencio.

## Cambios de frontend

> **YA IMPLEMENTADO en el frontend (2026-08-15, misma sesión que este diseño)** — ver
> `project_scrum208_recepcion_parcial_rediseno_pendiente_20260815.md` en la memoria del equipo
> para el detalle completo. Resumen: `comprasApi.orders.advanceRemainder(id)` +
> `useAdvanceRemainderPurchaseOrder`, botón accionable en el aviso ámbar de `OrderDetailPage.tsx` y
> `LogisticsPage.tsx`, 10 tests de componente nuevos (55/55 verdes junto con el resto de la suite:
> 1230/1230, tsc limpio). **No pusheado a dev** — el endpoint no existe en el backend todavía, así
> que el botón nuevo hoy dispara 404/422 real hasta que el Backend Dev complete su parte. El
> Backend Dev DEBE leer la nota de contrato de abajo (`next_remainder_status`) antes de dar por
> cerrado el endpoint.

- `comprasApi.orders.advanceRemainder(id)` + hook `useAdvanceRemainderPurchaseOrder` (mismo
  patrón que `useAdvancePurchaseOrder`).
- `OrderDetailPage.tsx` y `LogisticsPage.tsx`: el banner ámbar informativo actual de remanente
  pendiente pasa a ser accionable — botón que llama al endpoint nuevo, con su propio manejo de
  error inline (mismo patrón que el botón principal de avance).
- **Contrato nuevo que el backend debe cumplir, no solo el endpoint de avance:** el frontend espera
  un campo `next_remainder_status: RemainderStatus | null` en la respuesta de orden (mismo lugar
  que ya expone `next_status`), calculado por el backend respetando `provider_origin` (igual que
  `statusSequence()` ya hace para `next_status` — NO se duplicó esa lógica de secuencia en el
  frontend a propósito). Mientras este campo no venga poblado, el botón cae a una etiqueta genérica
  ("Completar etapa del remanente", sin nombrar la etapa destino) — funcional pero menos claro; el
  backend debería exponerlo desde el primer commit de este trabajo, no como follow-up.
- Bug real encontrado y corregido de paso en `LogisticsPage.tsx` (no dependía del endpoint nuevo):
  el botón principal "Completar etapa" nunca chequeaba `blockedFromReceivingByRemainder` (a
  diferencia de `OrderDetailPage.tsx`, que sí lo hacía desde el fix del 2026-08-12) NI renderizaba
  `advance.isError` en ningún lado — por eso el video de Gerencia1 mostraba el botón "clickeable pero
  sin generar ninguna acción": el 422 llegaba y no había forma de verlo. Ya corregido (mismo
  patrón que OrderDetailPage: disabled + banner rojo condicional).
- Inventario: sin cambios de frontend — el dato ya viene de `stock_quantity`/
  `product_warehouse_stock`, que el backend actualiza; nada que verificar del lado de UI una vez
  que el backend haga su parte.

## Tareas para la próxima sesión (PM: desglose y asignación)

Secuencia sugerida — Backend primero (Frontend depende de la forma del endpoint nuevo), Senior
Review real antes de Pre-QA/QA, igual que el resto del proyecto.

> **Actualización 2026-08-15 (mismo día del diseño):** Frontend Dev ya avanzó su parte (lista abajo
> marcada ✅) por decisión explícita de Luis, antes de que exista el endpoint backend — invierte el
> orden sugerido de la secuencia. Funciona en tsc/tests (mocks), pero no end-to-end todavía. El
> Backend Dev que retome esto DEBE leer la nota de contrato `next_remainder_status` en la sección
> "Cambios de frontend" arriba antes de dar el endpoint por terminado — no es opcional, el frontend
> ya asume ese campo.

### Backend Dev (atlanticerp-backend, worktree propio)

1. **Investigación previa (bloqueante, hacer primero):** localizar el punto exacto donde
   `PurchaseOrderReceiptLine.confirmed_at` se setea hoy. Documentar el hallazgo antes de tocar
   nada — puede cambiar el diseño de la sección 1.
2. Migración: 5 columnas nuevas de fecha del remanente en `purchase_orders` (ver arriba).
3. Mover/disparar el crédito de stock al momento de confirmación de línea (sección 1), con test de
   regresión explícito contra el bug histórico de cuadriplicación.
4. Endpoint `advance-remainder` (`PurchaseOrderController::advanceRemainder()`), con el mismo
   patrón de lock/transacción de `advance()`.
5. Tests: recepción parcial 1-de-10 → stock disponible inmediato (sin esperar `STATUS_RECIBIDO`);
   avance del remanente por sus propias etapas; no duplicación de stock al cerrar la orden
   completa; 2+ recepciones parciales sucesivas sobre la misma orden.

### Frontend Dev (atlanticerp-frontend, worktree propio)

1. ✅ `comprasApi.orders.advanceRemainder` + `useAdvanceRemainderPurchaseOrder` — hecho 2026-08-15.
2. ✅ Banner ámbar accionable (botón, no timeline completo — se simplificó el alcance visual, ver
   nota abajo) en `OrderDetailPage.tsx` y `LogisticsPage.tsx` — hecho 2026-08-15. Bonus: se
   encontró y corrigió un bug real preexistente en `LogisticsPage.tsx` (botón principal sin
   disabled/error para este caso, ver sección de arriba).
3. ✅ Tests de componente — 10 tests nuevos (5 por página), suite completa 1230/1230 + tsc limpio —
   hecho 2026-08-15.
4. ⬜ Verificación de Inventario — sigue pendiente, no aplica hasta que el backend exista (nada que
   verificar con la UI actual sin datos reales del endpoint nuevo).

**Nota de alcance:** se implementó un botón simple dentro del aviso ámbar existente, NO el
"mini-timeline de las etapas del remanente" que sugería el diseño original — alcance más chico y
suficiente para desbloquear la funcionalidad; una mini-timeline visual queda como mejora opcional
de UI, no bloqueante.

**Pendiente real antes de que esto funcione de punta a punta:** el endpoint
`PATCH /compras/orders/{id}/advance-remainder` no existe — clickear el botón nuevo hoy contra un
backend real da 404. Los tests pasan porque mockean `comprasApi.orders.advanceRemainder`, no
porque el endpoint funcione.

### Antes de pasar a Pre-QA/QA

- Confirmar con Gerencia1/Luis la asunción de la sección "2+ recepciones parciales sucesivas"
  (mantener etapa del remanente vs. permitir re-declararla).
- Senior Review real (cambio de modelo de datos — regla dura del CLAUDE.md de AtlanticERP, no saltar
  esta vez).
- Reproducir en vivo el escenario exacto de las capturas de Gerencia1 (orden 10 unidades, 1
  recibida) contra dev.atlanticerp.ai antes de dar el ticket por cerrado.
