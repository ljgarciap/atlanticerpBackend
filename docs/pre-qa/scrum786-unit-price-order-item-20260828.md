# Pre-QA — SCRUM-786: precio unitario en OrderItem/CustomerReturnLine, 2026-08-28

**Ticket:** Trackear precio unitario en `CustomerReturnLine` (devoluciones de Bodega). No
bloqueante — el flujo anterior funcionaba con entrada manual del monto en $0.00.

## Decisión de arquitectura (pregunta abierta del ticket)

El ticket dejaba explícitamente a definir "de dónde sale el precio: ¿la línea original del
pedido/factura? ¿precio actual del catálogo?". Investigación completa (ver commit) encontró:

- `AdminContInvoice` (la factura real, ligada 1:1 por `order_id`) **no tiene desglose por
  producto** — solo un `monto` agregado por proyecto (`PipelineCard.amount`). No sirve como fuente.
- El precio realmente cobrado vive en `QuoteItem.unit_price` + tipo de precio/descuento
  (`QuoteItem::unitPriceAfterDiscount()`), pero nunca se copiaba a `OrderItem` al crear el pedido
  — pese a que `OrderService::createItemsFromQuote()` tiene el `QuoteItem` de origen disponible en
  ese mismo momento.
- Precio de catálogo (`catalog_products.price_full`) fue descartado: es un valor único y mutable
  en el tiempo (depende de `pricing_settings`, editable, sin versionar) — no reflejaría lo
  realmente cobrado en la venta original.
- Precedente único y sin excepciones en todo el código: todo valor de dinero se snapshotea al
  momento de la transacción (`QuoteItem.unit_price`, `OrderItem.reference_snapshot`,
  `AdminContInvoice.monto`, etc.), nunca se recalcula en vivo.

**Decisión:** snapshotear el precio efectivo de la cotización (`unitPriceAfterDiscount()`) en
`OrderItem.unit_price` al crear el pedido — mismo momento, mismo patrón que
`reference_snapshot`/`description_snapshot`. `CustomerReturnLine` lo hereda del `OrderItem` (FK
real, sin ambigüedad). Backfill best-effort para pedidos ya existentes vía
`bodega:backfill-order-item-prices` (Order→PipelineCard→Quote, convención ya usada por
`OrderService::resolveApprovedQuote()` — omite cualquier emparejamiento ambiguo, deja NULL).

## Verificación

**Suite completa (antes de push):** backend 2834/2834 tests + PHPStan Level 8 (0 errores);
frontend 1633/1633 tests + `tsc --noEmit` limpio. Tests nuevos/ampliados: `unitPriceAfterDiscount()`
con tipo de precio "project" + descuento global 10% (verifica que no sea un passthrough del
`unit_price` crudo); `CustomerReturnService::create()` copia y también el caso NULL (pedidos sin
backfill); `GET /notas-credito/devoluciones/{id}` (endpoint sin ningún test previo — cubierto por
primera vez); comando de backfill (match único, match ambiguo omitido, `--dry-run` no escribe).

**Backfill corrido en vivo contra dev.atlanticerp.ai** (después de desplegar, antes de este doc):
`--dry-run` primero (7 backfilleables, 29 sin cotización resoluble, 0 ambiguos), luego real —
mismo resultado, más 1 `CustomerReturnLine` heredado. Sin errores.

**Verificación adversarial en vivo con Playwright** (login real Felix, dev.atlanticerp.ai recién
desplegado): sembrado un caso real vía tinker (pedido con `unit_price=45.50`, devolución de 2
unidades en `esperando_nota_credito`, factura real vinculada por `order_id`) — la cola de Notas de
Crédito muestra la fila, el clic abre el formulario, y el monto sugerido por producto muestra
**"USD 91.00"** (2 × $45.50) en vez del $0.00 fijo de antes del fix. Registro de evidencia dejado
en dev.atlanticerp.ai marcado `[PREQA-SCRUM786]`/`PREQA786` (mismo criterio que
`AdminContSeedRebotesQaCommand`), sin limpiar a propósito.

## Veredicto

✅ **PASA.** Causa raíz corregida en el punto correcto del flujo (creación del pedido, no la
devolución), decisión de arquitectura documentada y consistente con el único patrón existente en
el código, verificado end-to-end en vivo contra dev.atlanticerp.ai.

## Commits

`atlanticerp-backend@b365b79`, `atlanticerp-frontend@253b34c` — pusheados a `origin/dev`, desplegados a
dev.atlanticerp.ai vía `infra/deploy-manual.sh`.
