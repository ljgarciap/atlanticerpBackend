# Senior Review — Manual técnico de Admin & Contabilidad

2026-10-12 · Documento: `docs/manuales-tecnicos/admin-contabilidad.md` · Misma plantilla y método.

## Cobertura
472 de 472 elementos nombrados: 27 controladores, 43 Form Requests, 36 modelos, 49 servicios, 2 jobs, 1 evento, 1 correo, 5 clases de soporte, 9 excepciones, 36 tablas, 140 rutas, 62 piezas de frontend y 61 archivos de prueba. La cobertura mide presencia, no calidad. La capa `FacturacionElectronica/` queda fuera del inventario (tiene su propio manual); los controladores y rutas de FE que viven aquí se mencionan y se remiten a ese manual.

## Muestra contrastada con el código
Estado de comisiones internas y externas, monto y régimen de la comisión externa, condición de aprobación de notas, valor acreditable restante, estados del gasto de caja chica y su reapertura, estados del arqueo, métodos de pago y cuáles confirman de inmediato, valores por defecto de `fiscal_settings` y de los umbrales de fraude, gate `primary_approver_only` (rutas y middleware), roles por ruta, listeners del evento `InvoiceCreated`, rutas del frontend.

## Correcciones hechas en el repaso
| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | El evento se llama `admincont.invoice.created` | Es `admin_contab.invoice.created`. |
| 2 | Una nota pasa a aprobación si supera el umbral o es corrección | Pasa si el monto supera el umbral **o** es anulación completa; la corrección, si el total de la factura nueva supera el umbral. |
| 3 | Un gasto rechazado puede reabrirse hasta el máximo de intentos | Cada rechazo suma un intento; solo uno `rechazado_permanente` se reabre, y la reapertura pone el contador en 0. |
| 4 | Mark decide notas, incobrables y % | También aprueba el arqueo y la caja chica, y es el único que edita tramos de comisión (grupos `primary_approver_only`). |
| 5 | `MarkOrderInvoiceReady` marca el pedido como facturado | Está registrado pero sin efecto: Bodega calcula "facturado" en vivo (ver manual de Bodega). |

## Hallazgos de contenido que el manual deja registrados (no se corrigieron)
- `PendingAlertsService::DIAS_UMBRAL_COMISION_PENDIENTE = 10` fijo en código.
- Rangos de antigüedad de cartera fijos (convención declarada, no editable).
- Tres servicios de más de 900 líneas.
- ITBMS al 7 % duplicado en Compras (deuda declarada en `ItbmsRateResolver`).
- Reglas de acceso por rol en rutas y `App.tsx`, no por permiso.

## Límites
No se ejecutó la aplicación ni las pruebas. No se leyeron línea a línea `InvoiceService`, `CreditNoteService`, `ArchitectCommissionService`, `CommissionOrderService`, `DailyCashCountService` ni `AdminContPettyCashService`; sus reglas finas salen de docblocks, firmas y fragmentos. No se revisaron las plantillas de PDF ni las vistas del frontend más allá de rutas, guardas y nombres. El frontend se leyó de `origin/dev` (commit `95cc675`).
