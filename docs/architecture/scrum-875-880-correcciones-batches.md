# PM — Desglose de tareas · Correcciones Admin&Cont SCRUM-875 → 880

PM, 2026-09-09. Spec: `docs/specs/scrum-875-880-correcciones-admincont-analista-spec.md`.
ADRs: `ADR-SCRUM875-876-*`, `ADR-SCRUM877-*`, `ADR-SCRUM879-*`.

Rama de trabajo: `dev` (ambos repos). Commits en inglés. Gate por batch: suite completa
(`infra/test.sh` + `npx vitest run` + Playwright del módulo) + PHPStan L8 + Senior Review +
Visual Review + Pre-QA (fusionados). Promoción `dev → test` = `git push origin dev:test` completo
tras OK de Luis.

## Orden de ejecución

```
A ∥ B   (independientes, en paralelo, arrancan ya)
  └─► C   (875 → 876; camino crítico)
        └─► D   (hereda A)
        └─► E   (después de C por el join de comisiones)
```

---

## Batch A — SCRUM-880 + SCRUM-877 parte 2  ·  Tamaño XS

### A1 · Reordenar columnas del reporte "Ventas por medio de pago" (SCRUM-880) — Backend Dev
- `PaymentMethodSalesReportService.php`: agregar `public const COLUMNAS_METODO_ORDEN` con el orden
  [transferencia, cheque, efectivo, yappy, link_pago, tarjeta, deposito, retencion_impuestos,
  ajuste_cuenta]. **No** tocar `AdminContPayment::METODOS`.
- `PaymentMethodSalesExportService.php`: usar `COLUMNAS_METODO_ORDEN` en los 3 lugares
  (encabezados, filas, fila de totales) en vez de `AdminContPayment::METODOS`.
- Tests: PHPUnit — el Excel y el payload salen en el orden nuevo; `AdminContPayment::METODOS`
  intacto.

### A2 · Reordenar columnas en la pantalla (SCRUM-880) — Frontend Dev
- `VentasMedioPagoPage.tsx:19`: reordenar el array `METODOS` al orden nuevo.
- Test vitest: la tabla renderiza las columnas en el orden nuevo; las columnas antes de "Total" no
  cambian.

### A3 · Alinear subtotal del Estado de Cuenta (SCRUM-877 p2) — Frontend Dev
- `EstadoCuentaPage.tsx` (~línea 403): `colSpan={4}` → `colSpan={withProyecto ? 6 : 5}` (o el
  cálculo equivalente al número real de columnas líderes).
- Test vitest: en vista plana y agrupada, los valores Débito/Crédito/Saldo del subtotal caen bajo
  sus columnas.

**Dependencias A:** ninguna. **Entregable:** 1 push a `dev`, 1 ciclo de gate.

---

## Batch B — SCRUM-878 (rediseño PDF "Resumen del Arqueo")  ·  Tamaño M

### B1 · `DailyCashCountSummaryPdfService` pasa datos de empresa/logo/footer — Backend Dev
- Inyectar `AdminContFiscalSettings` / `AdminContCompanyProfile` (mismo patrón que
  `AccountStatementPdfService::viewData()`).
- Pasar al blade: `logoDataUri` (`PdfLogo::dataUri()`), `footerCompanyText`
  (`PdfFooterText::forFiscal()`), datos reales de la empresa, `generatedAt`.
- `setOption('isPhpEnabled', true)` para el `page_text()` del pie (igual que account-statement).

### B2 · Reescribir `daily-cash-count-summary-pdf.blade.php` — Backend Dev
- Encabezado estándar (logo + "ATLANTIC" + línea), sin la barra oscura del mockup.
- Pie estándar `page_text()` (datos reales + "Página X de Y"), sin "Documento generado por AtlanticERP".
- Bloque "RESUMEN": Fecha / Estado (realizado por X a las HH:MM) / Aprobación de Gerencia (o
  pendiente / no cerrado), líneas separadas.
- Sección "CONCILIACIÓN": "Retenciones con constancia: X de Y" (X = `retenciones` con
  `constancia !== null`, Y = `count(retenciones)`; "0 de 0" si no hay).
- Una sola tabla "Movimientos del día" con encabezado teal/blanco y columnas Concepto · Medio ·
  Observaciones · Monto. Cobros + notas de crédito + devoluciones en la misma tabla. `Medio` vía
  `AdminContPayment::METODO_LABELS`; "—" para notas. `Observaciones` = la de la fila o "—".
- Recuadro destacado con totales **después** de la tabla: Total cobrado / "Notas crédito y
  devoluciones" (rojo, negativo) / "Total neto del día" (negrita).
- Reemplazar el glyph `✓` de la fila de retenciones por texto/SVG (regla de iconografía).
- Estados: activo / pendiente_aprobacion / aprobado — cambia solo la línea de Estado.

### B3 · Tests — Backend Dev
- PHPUnit: el PDF se genera sin error para los 3 estados; snapshot / assert de que el HTML
  renderizado contiene la tabla única, el recuadro de totales, la sección Conciliación, el pie
  estándar, y no contiene "Documento generado por AtlanticERP" ni el nombre del mockup.

**Dependencias B:** ninguna. **Entregable:** 1 push a `dev`, 1 ciclo de gate. Visual Review contra
`REPORTE MOCKUP.png`.

---

## Batch C — SCRUM-875 + SCRUM-876  ·  Tamaño L  ·  CAMINO CRÍTICO

### C1 · SCRUM-875 — split de familia en creación de pedido — Backend Dev
- `OrderService::createFromApprovedQuote()`: si modo "parcial" con N≥2 `deliveryDates` → crear N
  `Order` en la misma transacción (mismo `order_number`, ordenados por fecha asc), tarjeta 1 =
  raíz, 2..N con `family_id => root` **en el `create()`**, `committed_delivery_date` por fecha,
  todos los ítems por tarjeta (`qty_requested` completo). `FamilyService::attach()`, `OrderSplit` +
  `OrderCreated` por tarjeta.
- Resolver el flag "parcial vs única" (columna en `pipeline_cards` o `deliveryDates->count() > 1`).
- Casos: modo única / parcial-1-fecha → 1 tarjeta (sin regresión); cotización sin ítems; vendedor
  sin mapping; fallo a mitad → rollback total.
- Tests PHPUnit: 2/3/4 fechas → familia correcta; badge de completitud; "Status de Pedidos".

### C2 · Helper `AdminContInvoice::forOrder()` + migración de call sites — Backend Dev
- `AdminContInvoice::forOrder(int|Order $order): Builder` → resuelve raíz.
- Migrar: `InvoiceService` (confirm/detail/proposeUncollectible/decideUncollectible),
  `CreditNoteService::invoiceForOrder()`, `OrderInvoiceController` (show/pdf), `InvoiceBookService`,
  y todo lo que aparezca en el grep. Docblock en el modelo aclarando que `order_id` = raíz.

### C3 · `InvoiceService` — agrupar por familia — Backend Dev
- `list()` / `pendingForProject()` / `summary()`: dedupe por `familyRootId()`, 1 fila por pedido,
  entra a "pendiente-facturar" si cualquier miembro está en `POR_DESPACHAR`. Payload
  `entregas_del_pedido[]`.
- `confirm()`: lock sobre la raíz, guard family-wide, `order_id = root->id`, monto = `card->amount`.
- Tests PHPUnit: pedido de 3 entregas → 1 línea, 1 factura, guard rechaza la 2ª; pedido no partido
  sin cambios.

### C4 · Bodega — factura por familia + `invoice_ready` en vivo — Backend Dev
- `OrderInvoiceController::show()/pdf()`: resolver raíz, `invoice_ready` calculado en vivo con
  `forOrder()`. Cualquier hermana devuelve el mismo `invoice_numero`.
- `MarkOrderInvoiceReady`: convertir a no-op con docblock (no eliminar el registro en
  `AppServiceProvider` en este batch).
- Tests PHPUnit: `show()` desde la tarjeta 2 de 3 devuelve la factura.

### C5 · Comisiones — join a la raíz — Backend Dev
- `ArchitectCommissionService::allProjects()`: join `orders` con `AND o.family_id IS NULL`, join
  `invoices` con `AND inv.anulada_at IS NULL`.
- Verificar `CommissionOrderService` (internas) — aplicar el mismo filtro si fan-out.
- Tests PHPUnit: pedido partido → 1 línea de comisión externa.

### C6 · Frontend Facturación — Frontend Dev
- `FacturacionPage.tsx`: la lista viene deduplicada; `FactRow` muestra "Entrega X de Y · [etapa]"
  del pedido. Preview: aviso "N entregas parciales → una sola factura por el total".
- i18n: claves nuevas.
- Tests vitest: pedido de 3 entregas → 1 fila / 1 preview / 1 confirm.

### C7 · Frontend Bodega — Frontend Dev
- `InvoiceStatusModal.tsx`: abrir desde cualquier tarjeta hermana muestra la factura del pedido.
- Test vitest.

### C8 · Spec Playwright permanente — Frontend Dev / QA
- `atlanticerp-frontend/e2e/`: pedido partido → 1 factura → todas las tarjetas de Bodega la muestran →
  1 línea de comisión externa. Autosembrado en `beforeAll`.

**Dependencias C:** C1 antes de C3-C5 (necesita familias reales). C2 antes de C3/C4/C5.
**Despacho:** C1+C2 primero; luego C3/C4/C5 en paralelo (Backend), C6/C7 en paralelo (Frontend, tras
contrato de C3/C4); C8 al final. **2 repos → 2 agentes con `isolation:worktree` si se despacha en
frío.** Entregable: 1 push a `dev` (ambos repos, mismo momento), 1 ciclo de gate.

---

## Batch D — SCRUM-877 parte 1  ·  Tamaño M-L  ·  después de C

### D1 · Columna `credit_notes.monto_aplicado_saldo` + poblarla — Backend Dev
- Migración aditiva (nullable decimal). `CreditNoteService::applyToBalance()` la setea con el
  `min(note.monto, saldoPendiente)` que ya calcula. Backfill de las notas existentes desde su
  `factura_origen`.

### D2 · `AccountStatementService::movements()` — notas de crédito + anuladas — Backend Dev
- Agregar filas por `AdminContCreditNote` `ESTADO_APLICADA` de las facturas del cliente/proyecto,
  con el monto de crédito por caso (ver ADR §1), fecha `fecha_decision ?? fecha`.
- Dejar de excluir facturas anuladas; por cada una, fila Factura (Débito) + fila Anulación
  (Crédito), fecha de la nota `anulacion_completa` asociada o `anulada_at`.
- Recalcular saldo corrido con las filas nuevas ordenadas por fecha.
- `contextNote()`: habilitar la rama "Cuenta completada y pagada" (`saldo === 0`).
- Tests PHPUnit: cada tipo × resultado; anulada con/sin nota; filtro de fechas; cliente sin notas.

### D3 · PDF y frontend — Backend Dev + Frontend Dev
- `account-statement-pdf.blade.php`: labels de Concepto para `descuento`/`devolucion`/`anulacion`.
- `EstadoCuentaPage.tsx` (`MovementsTable`): labels + color de crédito para los tipos nuevos.
- i18n. Tests vitest + verificación del PDF.

**Dependencias D:** después de C (la `factura_origen` de una nota es la raíz post-876). Hereda A3.
Entregable: 1 push a `dev`, 1 ciclo de gate. Visual Review contra `ESTADO DE CUENTA.png`.

---

## Batch E — SCRUM-879  ·  Tamaño L  ·  después de C

### E1 · Comisión externa — crear siempre el movimiento — Backend Dev
- `ArchitectCommissionService::marcarComoPagada()`: quitar el guard de `bank_account_id`; crear
  el `bank_movement` siempre (idempotente por `source_type`+`source_id`).
- Tests PHPUnit: pagar sin cuenta → movimiento con `bank_account_id` null; con cuenta → sin
  regresión; idempotencia.

### E2 · Constante `SOURCE_INTERNAL_COMMISSION` + scope `realCashImpact` — Backend Dev
- `AdminContBankMovement::SOURCE_INTERNAL_COMMISSION`.
- `AdminContBankMovement::scopeRealCashImpact($q, $activeAccountIds)` → `bank_account_id IN (activas)
  OR (tipo = comision AND bank_account_id IS NULL)`.
- `CashPositionService::real()` / `bankBalance()` / `netFlowForWindow()` usan el scope.
- Tests PHPUnit: la Vista Real incluye comisión sin cuenta; `real()` cierra en `bankBalance()`.

### E3 · Job `commissions:settle-internal` — Backend Dev
- Comando + `Console\Kernel::schedule()->daily()` con guard de fecha. Para cada mes cerrado sin
  liquidar: forzar snapshots (`percentForVendorMonth()`), crear 1 `bank_movement` por snapshot con
  `monto_comision > 0`, `fecha` = día 1 del mes siguiente, `bank_account_id` null, idempotente.
- Tests PHPUnit: varios vendedores; mes vacío; re-corrida (idempotencia); snapshot pre-existente.

### E4 · Backfill `commissions:backfill-cash-movements` — Backend Dev + DevOps
- Corte = `arqueo_tracking_start_date` (fallback `'2026-08-25'`). Externas con `fecha_pago >=
  corte` sin movimiento → crear con `fecha = fecha_pago`. Internas de meses cerrados `>= corte` →
  lógica del job. Idempotente.
- DevOps: correr en dev + test en el deploy del batch. **Prod: `# ESTRATEGIA` aparte con Luis**,
  captura de `bankBalance()` + Vista Real antes/después.
- Tests PHPUnit del comando.

### E5 · Frontend Cuentas Bancarias — Frontend Dev
- Verificar que el listado de Movimientos muestra tipo "Comisión" y el flujo "Sin cuenta asignada
  · Seleccionar cuenta" para comisiones (externas e internas). Ajustar labels si falta.
- Test vitest.

**Dependencias E:** después de C (join de comisiones ya corregido). Entregable: push a `dev` +
backfill dev/test; backfill prod como paso coordinado aparte. Gate normal.

---

## Resumen de artefactos por repo

| Repo | Batches que lo tocan |
|---|---|
| `atlanticerp-backend` | A1, B (todo), C1-C5, D1-D2-D3(blade), E (todo) |
| `atlanticerp-frontend` | A2, A3, C6, C7, C8, D3(page), E5 |

## Riesgos que el PM vigila
- **C:** call site de `where('order_id')` sin migrar. Senior Review con la lista del ADR §3.
- **C:** despacho en 2 repos — `isolation:worktree`, verificar rama `dev` antes de editar.
- **E:** backfill de prod mueve la posición de caja. Nunca en el deploy automático — siempre
  ventana con Luis.
- **D:** el recálculo de "monto aplicado a saldo" — se resuelve con la columna nueva D1, no con
  recálculo frágil.
- **D después de C, E después de C** — no arrancar D/E hasta que C esté en `dev` y verde.
