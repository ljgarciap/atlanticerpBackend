# Fase 13 — integridad contable y saldos

Estado: **APROBADA por Luis (2026-10-30, decisiones en §5); en implementación, un PR por ítem.** Origen: grupo 2 de `docs/flujos/candidatos-priorizados.md` (hallazgos de la Fase 11, cada uno verificado ejecutando el flujo real).

## 1. Problema
Hay cifras de dinero que no reconcilian entre pantallas o con su detalle: el total de una orden de compra deja de sumar sus líneas tras un cambio de monto, el saldo a favor de un mismo cliente se muestra con tres cifras distintas, y varias operaciones que reabren deuda o mueven dinero no tienen el mismo control. Ninguna rompe una operación hoy, pero cada una hace que dos personas lean números distintos del mismo hecho.

## 2. Hechos verificados en el código
- **Saldo a favor (2.2).** `AbonoService::saldoClienteDisponible` (lo que muestra `open-invoices.credit_balance` y consume Cobros) suma solo los abonos **de nivel Cliente** (sin proyecto ni factura) y las notas de crédito `saldo_favor` aplicadas. `SaldoFavorService::allRows` (la pantalla «Saldo a favor») suma los abonos **de todos los niveles** más las mismas notas. La vista previa de compensación usa los orígenes que se le piden. Por eso un cliente con un abono ligado a un proyecto aparece con 0 en un sitio y con su monto en otro. El listado además no expone `source_type`/`source_id`, que la compensación exige por línea.
- **Cambio de monto (2.1).** `approve-amount-change` fija `purchase_orders.total_amount` al valor nuevo y no toca `purchase_order_lines`; el detalle deja de cuadrar con el total.
- Los demás candidatos (2.4–2.11) están descritos en el catálogo de candidatos con su fuente.

## 3. Diseño por sub-fase (un PR por ítem; backend y frontend cuando aplique)

**13a — Compras y Bodega**
- **2.1 Cambio de monto** (decisión de Luis: *línea de ajuste*). Al aprobar, se agrega a la orden una línea «Ajuste de monto aprobado» por la diferencia (positiva o negativa), marcada como ajuste y sin producto; `total_amount` = suma de líneas siempre. Las cantidades recibidas, el reclamo y la recepción ignoran esa línea. Orden con detalle ya inconsistente: el reporte de migración lista las órdenes cuyo total no suma sus líneas y crea la línea de ajuste con la diferencia (reversible).
- **2.7 Pagos a proveedor** (*avisar, no bloquear*). Si el pago acumulado supera lo recibido (valorado a precio de orden), la respuesta trae una advertencia `pago_supera_recibido` con las cifras, la pantalla la muestra y queda en el rastro de auditoría; no se impide el pago.
- **2.9 Devolución de cliente** (*tope = lo solicitado*). `confirm-reception` rechaza (422) una cantidad recibida mayor que la solicitada, con el mensaje de qué hacer (editar la solicitud con motivo). Tope anterior (lo entregado) queda como segunda guarda.

**13b — Saldo a favor y reversos**
- **2.2 Una sola fuente.** Una consulta de saldos por nivel en `AbonoService` (cliente, proyecto, factura) de la que beben las tres pantallas; el listado de saldo a favor expone `nivel`, `source_type` y `source_id`, y los indicadores separan «aplicable a cualquier factura del cliente» de «reservado a un proyecto o factura». `open-invoices.credit_balance` conserva su significado (nivel Cliente) y suma al lado `credit_balance_reservado`; ningún lugar suma niveles distintos sin decirlo.
- **2.3 Reverso de compensación con aprobación** (decisión de Luis). Reversar una compensación crea una solicitud que decide el aprobador o un suplente (mismo mecanismo y permisos que la anulación de cobro, `PaymentReversalService`), con motivo obligatorio; mientras esté pendiente la compensación sigue vigente.
- **2.4 Compensación sugerida tras anular un cobro.** Al aprobar la anulación de un cobro de una factura acreditada por notas, la respuesta propone la compensación (no la aplica sola): lo que la nota ya acreditó se muestra como saldo aplicable.

**13c — Cierres y limpieza**
- **2.5 Folios**: al asignar, tomar el máximo entre el contador y el mayor folio existente (el contador deja de poder chocar).
- **2.6 Facturar «completo»**: la vista previa advierte las cantidades no entregadas que se están cobrando.
- **2.8 Incobrable**: estado propio `incobrable` en la factura y se oculta la acción; se puede marcar una sola vez.
- **2.10 Arqueo cerrado** (decisión de Luis: *reapertura con aprobación*): el aprobador o un suplente puede reabrir un arqueo cerrado con motivo obligatorio; queda auditado y el cierre se vuelve a hacer.
- **2.11 Anulación completa**: quitar el campo `monto`, que ya se ignora.

## 4. Riesgos y no-alcance
- Toca dinero: cada ítem lleva prueba de regresión (falla sin el cambio) y la suite completa se corre **antes** de abrir el PR (lección de la 12c).
- La migración 2.1 modifica datos de órdenes existentes: respaldo previo y `down()` que borra solo las líneas de ajuste creadas por ella.
- No cambia la contabilidad de notas de crédito ni la facturación electrónica. Los umbrales de aprobación y los motivos fijos son de la Fase 14.

## 5. Decisiones de Luis (2026-10-30)
1. **2.1 Cambio de monto → línea de ajuste.** ✅
2. **2.3 Reverso de compensación → con aprobación.** ✅
3. **2.7 Pago mayor a lo recibido → avisar, no bloquear.** ✅
4. **2.9 Devolución → tope = lo solicitado.** ✅
5. **2.10 Arqueo cerrado → reapertura con aprobación y motivo.** ✅
6. **Entrega → un PR por ítem** (cada uno corre la suite completa antes de abrirse). ✅
7. Los demás ítems son técnicos y siguen la propuesta de §3 (2.2, 2.4, 2.5, 2.6, 2.8, 2.11).

## 6. Abierto
Nada de negocio pendiente. Orden de entrega: 13a (2.1, 2.9, 2.7), 13b (2.2, 2.3, 2.4), 13c (2.5, 2.6, 2.8, 2.10, 2.11).
