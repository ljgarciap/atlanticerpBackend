# PM — Desglose de tareas · Correcciones UAT Admin&Cont SCRUM-928 → 935

PM, 2026-09-18. Spec: `docs/specs/scrum-928-935-correcciones-uat-admincont-analista-spec.md`.
ADR: `docs/adr/ADR-SCRUM929-facturacion-parcial-o-completa.md`. Trabajo directo sobre `dev`
(sin ramas feat/*). Todo batch cierra con: suite completa (`infra/test.sh` + `npx vitest run` +
Playwright del módulo) → Senior Review → Pre-QA → `Dev Testing` → `QA`.
Los tickets no traen mockup adjunto → Visual Reviewer no aplica.

```
A (S, paralelo) ─┐
                 ├─► D (930)
B (929, XL) ─────┤
                 └─► C (931/932/933)   (C puede arrancar en paralelo con B, integra al final)
```

## Batch A — 935 + 928 + 934 · Tamaño S · sin dependencias

**Archivos disjuntos entre sí** (seguro en paralelo, un solo hilo por repo — sin worktrees).

- **A1 · 935 — Backend Dev:** `ClientCollectionReportExportService`, `InvoiceBookExportService`,
  `PaymentMethodSalesExportService` (+ el export de "acumulado"): celdas numéricas reales con
  `setFormatCode('$#,##0.00')`; quitar `media` de headers/filas/totales en mensual-cliente y
  acumulado. **Frontend Dev:** quitar columna "Media" en `MensualClientePage`/`MensualClienteAcumuladoPage`/
  `ClientCollectionReportView`. Test: leer el xlsx generado y afirmar tipo numérico + format code.
- **A2 · 928 — Backend Dev:** `tax_id_dv` a `$fillable` de `SubClient`; `StoreClientRequest` y
  `StoreSubClientRequest` (`required`, regex `^\d{1,2}$`); exponerlo en los resources.
  `InvoiceService::paymentBlock()`: eliminar el fallback `****XXXX` → `null` ("—" en vistas).
  Seeder idempotente "llenar solo si vacío" para `fiscal_settings.dv = '81'` y, desde 2026-09-18,
  `AdminContBankAccountSeeder` con las 4 cuentas de `test` y el número completo de Banco General
  (antes era una variable de entorno; Luis pidió que viviera en un seeder).
  **Frontend Dev:** campo DV tras RUC en `CreateClientModal` y en el diálogo de subcliente de
  `QuotePage`; `ventasDisenoApi`, tipos, i18n es/en; mostrar DV en `ClientDetailModal`.
  Test: crear sin DV → 422 y botón deshabilitado; PDF muestra `RUC-81`; sin cuenta completa no hay `*`.
- **A3 · 934 — Backend Dev:** en `DailyCashCountService::present()/totalsFor()` agregar
  `total_facturado_dia` (Σ `invoices.monto` creadas ese día Panamá, no anuladas) y
  `total_pasado_a_pedido_dia` (Σ monto de cotizaciones convertidas a pedido ese día) calculados
  "al momento de crear" y persistidos en el snapshot al cerrar (mismo patrón que SCRUM-878).
  **Frontend Dev:** bloque indicador bajo tabla/totales en `ArqueoCajaPage` y en el PDF
  `daily-cash-count-summary-pdf.blade.php`. Reinicio diario = filtro por fecha, sin job.

## Batch B — 929 · Tamaño XL · CAMINO CRÍTICO

- **B0 · Spike — CERRADO (Arquitecto, 2026-09-18).** La cantidad definitiva de una entrega es
  `qty_delivered` (fijada en `DeliveryService::registerDelivery()`, tope `qty_picked`) ✔; el mapeo
  `OrderItem`↔`QuoteItem` era ambiguo ✘ → ver "Resultado del spike B0" en el ADR. El diseño se
  enmendó dos veces durante el spike; B1 absorbe el arreglo.
- **B1a · Bodega — Backend Dev** (revisa Arquitecto): migración `order_items.quote_item_id`
  (nullable, indexed). Se llena y se copia junto con `unit_price` en los **4 sitios** que crean
  `OrderItem`: `OrderService::createItemsFromQuote()` (origen),
  `PickingService::createInventoryReviewCard()`, `DeliveryService::createFaltanteCard()` e
  `InventoryReviewService::splitFoundItemsIntoNewOrder()`. Comando de backfill best-effort
  `bodega:backfill-order-item-quote-links` (`--dry-run` opt-in, patrón SCRUM-786 — **la primera corrida
  en test/prod SIEMPRE con `--dry-run` y revisión humana de la salida**; solo vincula con la
  cotización vigente al crear el pedido y con cantidad/precio consistentes, Senior Review MEDIO-1). Tests: vínculo en
  pedido único, en familia SCRUM-909, en tarjeta de revisión, en faltante y en división "mixta".
  Tocar Bodega exige suite completa.
- **B1b · Migraciones + modelo — Backend Dev** (revisa Arquitecto, regla de migraciones): columnas
  `family_root_id`/`tipo`, tabla `invoice_lines` (con `quote_item_id`), índice parcial nuevo,
  backfill `completo`. Guard: pedido con algún ítem sin `quote_item_id` → solo modo `completo`.
- **B2 · `InvoiceService` — Backend Dev:** `confirm()` por entrega (cantidad = `qty_delivered`),
  `confirmCompleto()` (cantidad cotizada − facturada por `quote_item_id`), residual solo al cerrar la
  última cantidad, guard transaccional con topes por monto e ítem, `pending/list/summary` por
  entrega + `puede_facturar_completo`. Verificar antes la interacción con `order_items.itbms_rate_id`.
- **B3 · Consumidores — Backend Dev, dividido en dos (2026-09-19):**
  - **B3a — los que CORROMPEN datos con varias facturas activas por pedido** (parcial + completo, incluso dos
    con el mismo `order_id` en la raíz): regla central `InvoiceService::pickInvoiceForDelivery()` /
    `invoiceForDelivery()` ("la factura de una entrega": parcial vigente anclada → completo vigente de la
    familia → ninguna; solo después, y solo para históricos, las anuladas); `OrderInvoiceController` y
    `OrderBoardService` (`invoice_ready` por entrega, lote y detalle unificados); incobrable por FACTURA
    (`invoice_id`); `CreditNoteService` (tratamiento ITBMS, devoluciones, cola de devoluciones, listado por
    cliente) y **Corrección de datos** (el reemplazo conserva `tipo`/`order_id`/`family_root_id` y copia las
    `invoice_lines`, con importes desde ESAS líneas); guía por entrega en `AccountStatementService`;
    verificación de `GerenciaHomeService` (sin doble conteo). Más los pendientes menores de las
    re-revisiones de B1b+B2 (N-1, N-2, N1–N7).
  - **B3b — reportes e importes derivados (pendiente):** `InvoiceBookService` (Libro de facturas) y
    `PaymentMethodSalesReportService` (reporte por medio de pago) — hoy calculan base/ITBMS por factura desde
    la cotización ENTERA (`netTotal()/itbms()`), lo que sobrestima ×N con parciales: deben leer
    `invoice_lines`; comisiones externas (`ArchitectCommissionService::allProjects`) e internas
    (`CommissionOrderService`) — join `inv.order_id = o.id` con `o.family_id IS NULL` que no ve parciales de
    entregas hijas ni el completo cuando hay dos por raíz; **D-4: comisión por factura sobre su monto
    proporcional, y la suma por proyecto == la del pedido completo**; mapper de facturación electrónica desde
    `invoice_lines`. `GerenciaHomeService` quedó verificado en B3a (sin cambios de código).
- **B4 · Frontend Dev: HECHO (2026-09-19).** `FacturacionPage` reescrita — fila por
  `invoice_id`/entrega pendiente (nunca por `order_id` a secas), botón "Generar factura de esta
  entrega" (`modo:'entrega'`) por fila pendiente de un pedido por-entrega, botón "Facturar pedido
  completo" una sola vez por pedido (muestra `restante`), wizard "+ Crear Factura" acotado a
  pedidos que NO se facturan por entrega (evita mezclar modos en un mismo submit — ver comentario
  en `CrearFacturaModal`), modal de detalle con `invoice_id` para elegir la factura exacta cuando
  la tarjeta tiene más de una activa. Tipos/hooks/cliente API actualizados al contrato completo del
  ADR §4. 1891 tests vitest + TypeScript limpio + **validado en navegador real** (Playwright, pedido
  de 3 entregas sembrado y limpiado después): 3 filas con "Entrega N de 3", botón por entrega,
  "Facturar pedido completo" mostrando el restante correcto, preview con badge "Parcial" y aviso
  "Después de esta factura quedan $642.00 por facturar del pedido", confirmar deja la fila
  "Facturada · F-0001 · Parcial" y actualiza los indicadores. Sin regresión en el flujo legado
  (entrega única/solo-completo, wizard multi-pedido) — mismo comportamiento que antes de SCRUM-929.
- **B5 · Tests — Backend Dev: HECHO (2026-09-19).** Invariante Σ = total (3 entregas + ITBMS +
  descuento) ya cubierto por `pedidoConDescuentos()` en `AdminContPartialInvoicingTest` (35 usos);
  entrega única sin cambio (`AdminContPartialInvoicingTest`, 3 tests dedicados) y backfill
  (`AdminContInvoiceFamilyRootTest`, 14 tests) y anulación libera saldo
  (`test_929_anular_una_factura_parcial_libera_el_saldo_de_esa_entrega`) ya existían del trabajo de
  B1b-B3b. **Concurrencia real (parcial vs parcial, y parcial vs completo) — nuevo**:
  `tests/Feature/AdminCont/Concurrency/InvoiceConcurrencyTest.php` (2 tests, procesos/conexiones
  Postgres separados de verdad vía `RunsConcurrentBodegaRequests`, mismo patrón ya establecido en
  Bodega) — confirma que el `lockForUpdate()` de `InvoiceService::confirm()` serializa
  correctamente bajo carrera real: exactamente 1 de 2 requests simultáneos gana, Σ activas nunca
  excede el total, sin importar la combinación de `modo`. **Se promovió a `atlanticerp-frontend/e2e/`**
  un spec permanente autosembrado
  (`preqa-scrum929-b5-facturacion-por-entrega-20260919.spec.ts`) — siembra y limpia SU PROPIO
  pedido de 3 entregas en cada corrida (nunca depende de fixtures sembradas a mano en una sesión
  pasada — hallazgo de entorno 2026-09-19: 3 specs viejos de Facturación
  (`preqa-scrum401-invoice-access`, `-scrum519-523-...batch3`, `-scrum524-528-...batch4`) fallan hoy
  porque el dev local tiene 0 `bodega.orders` — las fixtures que esos specs esperaban (tinker-seeded
  en 2026-08-21) ya no existen; no es una regresión de SCRUM-929, es staleness de datos preexistente,
  sin tocar), corrido 2 veces seguidas para confirmar que es repetible.

## Batch C — 931 + 932 + 933 · Tamaño M

- **C1 · Backend Dev:** endpoint compartido `GET …/cascada/proyecto-cotizacion?master_client_id=&sales_project_id=&quote_id=`
  (opciones acotadas al Cliente Master; filtrado cruzado en servidor) que devuelve **la lista de
  facturas abiertas** de la combinación. Reusa `AbonoService::projectsForSubClient/invoicesForProject`.
  `CobrosPage`/`NotasCredito` detalle: agregar Cotización, Guía, Abonos (todos), NC (todas).
- **C2 · Frontend Dev:** hook/componente `ProjectQuoteCascade` compartido por Cobros, Notas
  Crédito y Abonos; selector de factura cuando hay N; limpiar dependientes al cambiar el master;
  documentos clickeables que abren su modal.
- Depende de B solo para el caso N>1 facturas (se prueba con fixture de B).

## Batch D — 930 · Tamaño M · después de B

- **D1 · Backend Dev:** `guia_entrega` pasa de bool a `{numero, order_id}` derivada por D-5
  (`{folio}-G` / `-G{seq}`); columna `antiguedad_cartera` con los 4 rangos (reusa
  `agingBuckets()` por factura); sin `****` en `numero_cuenta`.
- **D2 · Frontend Dev:** columna tras "Saldo"; Factura/NC/Cotización/Guía clickeables (modal, sin
  salir); ítem "Estado de Cuenta" en `Sidebar.tsx` entre Inicio y Facturación; botón "Status de
  Pedidos" a la izquierda de "Cerrar Ventana" (ruta `OrderStatusPage` existente).

## Gates y notas

- **Pre-QA fusionado por batch** (regla 2026-08-15). B exige el checklist adversarial de la spec
  (casos 1–8 de 929). A/C/D usan los negativos de su sección.
- Todo fix de Pre-QA se pushea a `dev`; `git push` sigue pasando por su gate individual.
- Sin umbrales de negocio hardcodeados: los 4 rangos de antigüedad son los mismos ya usados por
  `agingBuckets()` (verificar que estén parametrizados; si no, es hallazgo).
- Al cierre: Jira → `Dev Testing`/`QA` con comentario aparte (`jira_add_comment`, la transición
  descarta el comentario) y marcadores Pre-QA (`~/.claude-preqa-markers/`).
