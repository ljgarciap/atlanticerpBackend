# Senior Review — Admin&Cont, Facturación (SCRUM-513→518, REQ-436→441)

**Fecha:** 2026-08-19
**Resultado: APROBADO. 1 hallazgo encontrado y corregido en el mismo batch (ver Pre-QA).**

Batch 2 del cuerpo principal de la épica Admin&Cont (ver `docs/architecture/admin-contab-batches.md`).
Primer batch que introduce el concepto de "entrega facturable" — mapeado a `Bodega\Models\Order`,
no a una tabla nueva, siguiendo la misma convención de reverse-lookup ya usada por
`Quote::linkedPipelineCard()` (`Order::linkedQuote()`, sin FK única, por `sales_project_id`).

## Gate real (suite completa)

- Backend: PHPStan Level 8 → `[OK] No errors`. `infra/test.sh` → **2383/2383 tests, 9584
  assertions, OK**.
- Frontend: `npm run test -- --run` → **116 archivos, 1314/1314 tests, OK**. `tsc --noEmit` → sin
  errores.
- Flujo completo (crear factura → vista previa → confirmar) validado con Playwright contra
  `dev.atlanticerp.ai` con datos reales, sin errores de consola. Verificado por SQL directo que
  `bodega.orders.invoice_ready` y el número de factura quedan correctos tras confirmar.

## Arquitectura — verificado en código

- `admin_contab.invoices` + `admin_contab.invoice_folio_counters` (folio `F-XXXX` por año, mismo
  patrón que `ticket_folio_counters` de Servicios).
- Evento `admin_contab.invoice.created` → listener `Bodega\Listeners\MarkOrderInvoiceReady`, mismo
  patrón que `compras.purchase-order.received` → `ReleaseOrdersAwaitingStock`. El gate de despacho
  de Bodega (`DeliveryService::blockingReasonsForDispatch()`) no se tocó — ya bloqueaba
  correctamente; solo cambió quién alimenta `invoice_ready` (de stub manual a evento real).
- `OrderInvoiceController::show()` (Bodega) ahora lee `AdminContInvoice` real por `order_id` en vez
  de devolver el mensaje estático — sin duplicar Subtotal/ITBMS/Total, Bodega sigue sin calcular
  nada de eso.
- Gate de rol: `role:superadmin,lider_admin_contab,asistente_administrativa,management`, igual que
  Cuentas Bancarias (no exclusiva de Mark).
- `es_incobrable` existe en el modelo de datos pero siempre `false` en este batch — el flujo de
  aprobación (HU-448) es un batch posterior, mismo patrón de "vacío a propósito" ya usado en otras
  áreas del proyecto.

## Reconciliación de specs (decisión del Arquitecto, documentada en código)

1. REQ-437 describe las tarjetas en términos de "hoja de salida antes de facturar" — contradice la
   nota RESUELTO explícita de REQ-441 ("Cotización → Factura → Guía de Entrega → Despacho"). Se
   siguió REQ-441 (más específico, con nota RESUELTO), no el texto desactualizado de REQ-437.
2. El mockup real (`4B__Admin_Contabilidad_Facturacion.html`) modela "hitos" múltiples por
   proyecto (anticipos previos a cualquier despacho) — más rico que lo que describen los REQ de
   este batch. Se implementó el modelo Order-based que sí describen los REQ/RESUELTO; el modelo de
   "hitos" queda fuera de alcance, no está pedido en ningún REQ 436→441.
3. REQ-440 (cliente → Estado de Cuenta) apunta a una pantalla que no existe todavía (batch
   posterior) — se implementó el link real con un placeholder mínimo de destino, para no dejar un
   enlace roto en QA.

## Veredicto

Listo para QA. Ver `docs/pre-qa/scrum513-518-facturacion-20260819.md` para el hallazgo encontrado,
la corrección aplicada y el detalle de Visual Review contra el mockup.
