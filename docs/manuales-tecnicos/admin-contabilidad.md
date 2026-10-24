# Manual técnico — Admin & Contabilidad

<!-- nav-manuales:inicio -->
> **Navegación:** [Índice de manuales](README.md) · ← [Bodega](bodega.md) · [Factura Electrónica](factura-electronica.md) →
> **Módulos relacionados:** [Factura Electrónica](factura-electronica.md) · [Bodega](bodega.md) · [Ventas & Diseño](ventas-diseno.md) · [Gerencia](gerencia.md)
<!-- nav-manuales:fin -->

> **Para quién:** un desarrollador que nunca ha tocado este módulo y necesita entenderlo y modificarlo con seguridad.
> **Base del manual:** backend `atlanticerp-backend` rama `dev` (2026-10-12) y frontend `atlanticerp-frontend` `origin/dev` (commit `95cc675`, 2026-10-12).
> **Cómo se verifica:** `python3 scripts/manual-coverage.py admin-contabilidad --frontend <ruta>` comprueba que cada pieza del módulo esté nombrada aquí. Mide presencia, no calidad.
> **Documentos hermanos:** manual de **Factura Electrónica** (`factura-electronica.md`, la capa `AdminCont/FacturacionElectronica/` se documenta allí y aquí solo se enlaza), ERD `docs/architecture/erd/admin-contabilidad.md`, plan de batches `docs/architecture/admin-contab-batches.md`, ADR por batch en `docs/adr/` (`ADR-SCRUM575-579…`, `ADR-SCRUM585-590…`, `ADR-SCRUM597-601…`, `ADR-SCRUM602-606…`, `ADR-SCRUM929…`).

---

## 1. Propósito y alcance

Admin & Contabilidad es el **back-office financiero** de Atlantic: factura lo despachado, registra cobros y abonos, emite notas de crédito, calcula comisiones, controla el flujo y la caja, y entrega los reportes administrativos. Casi todo lo que muestra se arma **leyendo en vivo** otros módulos (Bodega, Ventas & Diseño, Compras); sus tablas propias guardan lo que ocurre en la administración (facturas, pagos, notas, gastos).

| Área | Qué hace |
|---|---|
| **Inicio** | Resumen del mes, pendientes, vencidos y por vencer, aprobaciones pendientes. |
| **Facturación** | Facturar entregas (parcial o completa), vista previa, PDF, antigüedad de cartera, incobrables. |
| **Cobros, Abonos y Saldo a favor** | Registrar pagos contra facturas, anticipos y su aplicación. |
| **Notas de crédito y Devoluciones** | Descuento, devolución, anulación y corrección de datos, con aprobación de Mark. |
| **Estado de cuenta** | Documento por cliente y proyecto. |
| **Comisiones** | Internas (vendedores) y externas (arquitectos), más la comisión de cartera de Felix. |
| **Arqueo y Flujo de caja, Cuentas bancarias, Movimientos** | Posición de caja, cierre diario y libro de movimientos. |
| **Caja chica** | Gastos, reportes y aprobación. |
| **Reportes** | Mensual por cliente, acumulado, Libro de Facturas, ventas por medio de pago, cartera. |
| **Configuración** | Datos fiscales, empresa, ITBMS, ubicaciones, contactos, umbrales de fraude. |
| **Factura electrónica** | Certificación ante la DGI vía PAC; ver su manual. |

**Reglas de oro del módulo:**
1. Nunca una fórmula paralela: Inicio, Reportes y Comisiones **reutilizan** el cómputo del servicio dueño (`InvoiceService`, `CommissionOrderService`, `CashPositionService`).
2. Un documento fiscal no se edita: se anula y se emite otro.
3. Mark (aprobador fiscal) decide lo sensible; el resto del equipo propone.

---

## 2. Mapa del código

### 2.1 Backend (`atlanticerp-backend/app/Modules/AdminCont/`)

**Controladores** (`Http/Controllers/`, 27)

| Archivo | Para qué |
|---|---|
| `AdminContHomeController` | Inicio: resumen del mes, pendientes, vencidos, aprobaciones. |
| `AdminContInvoiceController` | Facturación: lista, previa, confirmar, PDF, incobrables. |
| `AdminContInvoiceReceptorController` | Corregir el receptor desde la vista previa (guarda de RUC). |
| `AdminContPaymentController` | Cobros: registrar, historial, comprobante, recibo, confirmar. |
| `AdminContAbonoController` | Abonos y sus cascadas de selección. |
| `AdminContSaldoFavorController` | Saldo a favor (solo lectura). |
| `AdminContCreditNoteController` | Notas de crédito y devoluciones. |
| `AdminContAccountStatementController` | Estado de cuenta. |
| `AdminContInternalCommissionController` | Comisiones internas y sus tramos. |
| `AdminContArchitectCommissionController` | Comisiones externas (arquitectos). |
| `AdminContCarteraCommissionController` | Tramos de la comisión de cartera de Felix. |
| `AdminContCashPositionController` | Flujo de caja y arqueo del día. |
| `AdminContBankAccountController`, `AdminContBankMovementController` | Cuentas bancarias y movimientos. |
| `AdminContPettyCashController` | Caja chica. |
| `AdminContReportsController` | Home de Reportes. |
| `AdminContClientReportsController` | Mensual por cliente y acumulado. |
| `AdminContInvoiceBookController` | Libro de Facturas. |
| `AdminContPaymentMethodSalesController` | Ventas por medio de pago. |
| `AdminContFiscalSettingsController`, `AdminContCompanyProfileController`, `AdminContItbmsRateController`, `AdminContLocationController`, `AdminContContactController` | Configuración (solo Mark). |
| `AdminContSettingsController` | Umbrales de alertas de fraude. |
| `AdminContFeReintentoController`, `AdminContFeTransmisionController` | Acciones y evidencia de factura electrónica (ver su manual). |

**Form Requests** (`Http/Requests/`, 43): `InvoiceOrderIdsRequest`, `CorrectInvoiceReceptorRequest`, `StorePaymentRequest`, `UploadPaymentRetentionAttachmentRequest`, `StoreAbonoRequest`, `UpdateAbonoRequest`, `RegisterCreditNoteRequest`, `PreviewCreditNoteCorreccionRequest`, `RegisterCreditNoteCorreccionRequest`, `CreditNoteDecisionRequest`, `StoreCommissionTierRequest`, `UpdateCommissionTierRequest`, `StoreCarteraCommissionTierRequest`, `UpdateCarteraCommissionTierRequest`, `UpdateArchitectFiscalProfileRequest`, `UploadArchitectCuentaCobroRequest`, `UploadComprobanteRetencionRequest`, `UpdateArchitectCuentaPagoRequest`, `ProposeArchitectPercentRequest`, `DecideArchitectPercentRequest`, `UpdateDailyCashCountEntryRequest`, `UpdateDailyCashCountGeneralObservationRequest`, `StoreBankAccountRequest`, `UpdateBankAccountRequest`, `AssignBankMovementAccountRequest`, `StorePettyCashExpensesRequest`, `StorePettyCashReportRequest`, `UpdatePettyCashExpenseRequest`, `AddPettyCashExpenseAttachmentRequest`, `RejectPettyCashExpenseRequest`, `RejectPettyCashReportRequest`, `ReopenPettyCashExpenseRequest`, `PettyCashMotivoRequest`, `UpdateFiscalSettingsRequest`, `UpdateCompanyProfileRequest`, `UploadCompanyLogoRequest`, `StoreItbmsRateRequest`, `UpdateItbmsRateRequest`, `StoreLocationRequest`, `UpdateLocationRequest`, `StoreContactRequest`, `UpdateContactRequest`, `AdminContSettingsRequest`.

**Modelos** (`Models/`, 36): `AdminContInvoice`, `AdminContInvoiceLine`, `AdminContInvoiceFolioCounter`, `AdminContPayment`, `AdminContPaymentInvoice`, `AdminContPaymentAttachment`, `AdminContPaymentRetentionAttachment`, `AdminContPaymentConfirmation`, `AdminContPaymentFolioCounter`, `AdminContPaymentConfirmationFolioCounter`, `AdminContAbono`, `AdminContAbonoFolioCounter`, `AdminContCreditNote`, `AdminContCreditNoteFolioCounter`, `AdminContBankAccount`, `AdminContBankMovement`, `AdminContDailyCashCount`, `AdminContDailyCashCountEntry`, `AdminContPettyCashExpense`, `AdminContPettyCashExpenseAttachment`, `AdminContPettyCashExpenseHistory`, `AdminContPettyCashReport`, `AdminContPettyCashReportFolioCounter`, `AdminContClientBillingProfile`, `AdminContFiscalSettings`, `AdminContCompanyProfile`, `AdminContItbmsRate`, `AdminContLocation`, `AdminContContact`, `AdminContSetting`, `AdminContFeTransmision`, `ArchitectCommission`, `ArchitectFiscalProfile`, `CarteraCommissionTier`, `CommissionTier`, `CommissionMonthSnapshot`.

**Servicios** (`Services/`, 44 + 5 en `Billing/`)

| Servicio | Para qué |
|---|---|
| `InvoiceService` | Facturación completa (1 824 líneas): lista, previa, confirmar, incobrables, antigüedad, indicadores de Inicio. |
| `InvoiceBillingPlanner`, `BillingContext`, `InvoicePlan`, `InvoiceLineMath`, `InvoiceTaxBreakdown` | Plan de facturación parcial o completa, anti-duplicado, redondeo y desglose fiscal (carpeta `Billing/`). |
| `InvoicePdfService`, `InvoiceExportService` | PDF de la factura y Excel/PDF de la lista. |
| `AdminContInvoiceFolioService` | Folio `F-XXXX`. |
| `PaymentService` | Cobros (567 líneas). |
| `PaymentReceiptPdfService`, `AdminContPaymentFolioService`, `AdminContPaymentConfirmationFolioService`, `AdminContPaymentAttachmentService`, `AdminContPaymentRetentionAttachmentService` | Recibo, folios `REC-` y `CONF-`, comprobante y constancia de retención. |
| `AbonoService`, `AbonoReceiptPdfService`, `AdminContAbonoFolioService`, `AdminContAbonoAttachmentService` | Abonos, recibo, folio `ABN-` y comprobante. |
| `SaldoFavorService` | Unión de abonos y notas con saldo a favor. |
| `CreditNoteService` | Notas de crédito (1 423 líneas). |
| `CreditableBalanceService` | Valor acreditable restante de una factura. |
| `CreditNotePdfService`, `AdminContCreditNoteFolioService`, `AdminContCreditNoteAttachmentService` | PDF, folio `NC-` y comprobante. |
| `ItbmsRateResolver` | Tasa de ITBMS vigente (fuente única). |
| `AccountStatementService`, `AccountStatementPdfService`, `AccountStatementExportService` | Estado de cuenta, PDF y Excel. |
| `CommissionOrderService`, `CommissionExportService` | Comisiones internas y sus exportaciones. |
| `ArchitectCommissionService` | Comisiones externas (965 líneas). |
| `CarteraCommissionService` | Comisión de cartera de Felix. |
| `CashPositionService`, `CashPositionExportService` | Flujo de caja proyectado y real. |
| `DailyCashCountService`, `DailyCashCountSummaryPdfService` | Arqueo del día y su resumen. |
| `AdminContPettyCashService`, `AdminContPettyCashAttachmentService`, `AdminContPettyCashReportFolioService`, `AdminContPettyCashReportPdfService` | Caja chica. |
| `ClientCollectionReportService`, `ClientCollectionReportExportService` | Mensual por cliente y acumulado. |
| `InvoiceBookService`, `InvoiceBookExportService` | Libro de Facturas. |
| `PaymentMethodSalesReportService`, `PaymentMethodSalesExportService` | Ventas por medio de pago. |
| `PendingAlertsService` | Panel Pendientes de Inicio. |
| `AdminContSettingsService` | Umbrales de fraude. |

**Soporte** (`Support/`): `AgingBucketCalculator` (rangos 0-30, 31-60, 61-90, +91), `ExcelMoney` (formato de moneda en Excel), `GuiaEntregaResolver` (guía de entrega sintética), `PdfFooterText` (pie de página fiscal), `ReportPeriodResolver` (períodos `3m`, `6m`, `anio`).
**Evento:** `InvoiceCreated`. **Jobs:** `SendArchitectReminderEmailJob`, `SendCreditNoteConfirmedEmailJob`. **Correo:** `CreditNoteConfirmedMail`. **Middleware:** `EnsureIsPrimaryApprover` (alias `primary_approver_only`).
**Excepciones:** `AbonoStateException`, `AccountStatementException`, `ArchitectCommissionStateException`, `CreditNoteStateException`, `DailyCashCountStateException`, `InvoicePreviewException`, `InvoiceStateException`, `PaymentStateException`, `PettyCashStateException`.

### 2.2 Frontend (`atlanticerp-frontend/src/`)

Páginas en `pages/admin-contab/`, componentes en `components/admin-contab/`, cliente `api/adminContabApi.ts`, hook `hooks/useAdminContab.ts`, tipos `types/adminContab.ts`. Detalle en la sección 8.

---

## 3. Modelo de datos

Esquema PostgreSQL `{tenant}_admin_contab`. Diccionario de columnas en el ERD (36 tablas). Lo que el ERD no explica:

| Grupo | Tablas | Notas |
|---|---|---|
| **Facturas** | `invoices`, `invoice_lines`, `invoice_folio_counters` | `tipo` `completo` o `parcial`. Un pedido es su tarjeta raíz: la factura `completo` cuelga de la raíz y cubre todo; la `parcial` cuelga de la entrega que la origina. Las líneas son inmutables (sin `updated_at`). Folio global `F-XXXX`. |
| **Cobros** | `payments`, `payment_invoices`, `payment_attachments`, `payment_retention_attachments`, `payment_confirmations`, `payment_folio_counters`, `payment_confirmation_folio_counters` | `payment_invoices` es el pivote pago-factura con el monto aplicado (y abono usado). Folios `REC-` y `CONF-`. |
| **Abonos** | `abonos`, `abono_folio_counters` | Estado `por_confirmar` o `confirmado`; nivel de asociación `cliente`, `proyecto` o `factura`; folio `ABN-`. |
| **Notas** | `credit_notes`, `credit_note_folio_counters` | Folio `NC-XXXX`; tipo, subtipo, resultado y estado (sección 4.4). |
| **Caja** | `bank_accounts`, `bank_movements`, `daily_cash_counts`, `daily_cash_count_entries` | `bank_movements` es el libro mayor (tipos `cobro`, `devolucion`, `comision`). El arqueo guarda un `snapshot` al cerrar. |
| **Caja chica** | `petty_cash_expenses`, `petty_cash_expense_attachments`, `petty_cash_expense_history`, `petty_cash_reports`, `petty_cash_report_folio_counters` | Folio `NNNN-AAAA`, por año. |
| **Comisiones** | `commission_tiers`, `commission_month_snapshots`, `architect_commissions`, `architect_fiscal_profiles`, `cartera_commission_tiers` | Tramos paramétricos y fotografía mensual. |
| **Clientes** | `client_billing_profiles` | Régimen fiscal y términos de pago por cliente master. |
| **Configuración** | `fiscal_settings`, `company_profile`, `itbms_rates`, `locations`, `contacts`, `admin_contab_settings` | Las dos primeras son filas únicas (`id = 1`). |
| **Factura electrónica** | `fe_transmisiones` | Evidencia de cada intento ante el PAC; solo se agrega, nunca se edita. |

**Referencias cruzadas sin llave foránea:** a Ventas & Diseño (`pipeline_cards`, `sub_clients`, `master_clients`), a Bodega (`orders`) y a Compras. Postgres no permite FK entre esquemas independientes en este proyecto; la integridad la sostienen los servicios.

---

## 4. Reglas de negocio y parámetros

### 4.1 Facturación

- **Qué se factura:** una entrega de Bodega (`Order`). Estados de la lista: *pendiente de entrega* (el pedido aún no llega a `por_despachar`), *pendiente de facturar* (`por_despachar` sin factura; es el punto donde Bodega bloquea el despacho) y *facturada*.
- **Modos:** `entrega` (factura parcial de una entrega) y `completo` (todo el pedido). Si algún ítem del pedido no está enlazado a un ítem de la cotización vigente, el pedido solo admite `completo` (degradación segura).
- **Invariante** (`InvoiceBillingPlanner`): Σ facturas activas ≤ total de la cotización, y = total al centavo al cerrar la última cantidad. Una factura anulada devuelve su saldo.
- **Confirmar** (`InvoiceService::confirm`): bajo transacción y con la fila raíz bloqueada, arma el plan, asigna folio, crea factura y líneas, y dispara `InvoiceCreated`. Los campos faltantes se informan con `InvoicePreviewException` (422).
- **Incobrables:** Felix, Mark o Gerencia **proponen**; solo Mark **decide**. Solo sobre facturas emitidas vigentes y sin otra propuesta pendiente. Aprobar la pasa a cartera incobrable; rechazar la deja como estaba.
- **Antigüedad** (`agingBuckets`): rangos fijos 0-30, 31-60, 61-90 y +91 días contados desde el **vencimiento**, no la emisión. Lo configurable es `dias_credito_factura` (default 30). Excluye incobrables aprobadas.
- **Guarda de RUC:** el receptor se valida contra la DGI antes de guardarse (`AdminContInvoiceReceptorController`); el candado 409 por documentos certificados aplica igual que en Clientes. Desde SCRUM-1140 la factura **congela** su receptor (razón social de la DGI, o Consumidor final por decisión de quien factura) y una razón social distinta ya no bloquea; ver `factura-electronica.md` §4.5.

### 4.2 Cobros, abonos y saldo a favor

- **Métodos** (9): `transferencia`, `cheque`, `efectivo`, `tarjeta`, `deposito`, `yappy`, `link_pago`, `retencion_impuestos`, `ajuste_cuenta`. Los dos últimos no llevan cuenta bancaria. Se **confirman de inmediato** `efectivo`, `retencion_impuestos` y `ajuste_cuenta`; los demás quedan `esperando_confirmacion` hasta que alguien presione confirmar (sin validación bancaria automática).
- **Registrar** (`PaymentService::register`): asigna folio `REC-`, rechaza facturas ya saldadas y, solo si el cobro nace `confirmado`, baja el saldo de cada factura, concreta abonos y escribe el movimiento bancario. En `esperando_confirmacion` solo deja las líneas; `confirm` aplica lo diferido, con folio `CONF-` propio y no deshacible.
- **Abonos:** un abono `por_confirmar` no es visible ni usable en ningún lado. A nivel factura o proyecto el uso en Cobros es **obligatorio y bloqueado**; a nivel cliente es **opcional** y el monto se valida contra el disponible real. Si se asocia a proyecto, se reasocia a la factura al facturar (`reasociarAlFacturar`).
- **Saldo a favor** (`SaldoFavorService`): unión de abonos `confirmado` y notas `saldo_favor` aplicadas. Es de solo consulta.

### 4.3 Valor acreditable

`CreditableBalanceService`: **VAR = monto de la factura − Σ notas de la factura (todos los tipos) en estado `aplicada` o `pendiente_aprobacion`**. No es el saldo por cobrar. Una anulación completa debe ser por el VAR; ninguna nota puede superarlo.

### 4.4 Notas de crédito

- **Tipos:** `descuento_comercial`, `devolucion_mercancia`, `anulacion_completa` (subtipo `cancelado`) y la **corrección de datos** (subtipo `correccion`, motivos `itbms`, `fecha` o `ambos`), que anula y reemplaza la factura.
- **Estados:** `aplicada`, `pendiente_aprobacion`, `rechazada`. **Resultado:** `aplicado_saldo`, `devuelto`, `saldo_favor`.
- **Aprobación:** una nota queda `pendiente_aprobacion` si su monto supera `fiscal_settings.primary_approval_threshold` (default 5 000.00) **o** si es una anulación completa; una corrección de datos, si el total de la factura nueva supera ese umbral. Solo Mark decide (`primary_approver_only`). Aprobar ejecuta el efecto diferido; rechazar exige motivo y no ejecuta nada. `requiresComprobante` define cuándo el soporte es obligatorio.
- Al confirmar una nota con factura de origen, `SendCreditNoteConfirmedEmailJob` envía `CreditNoteConfirmedMail` con la nota y el estado de cuenta; un fallo del correo nunca revierte la nota.

### 4.5 Comisiones

- **Internas** (`CommissionOrderService`): el "pedido comisionable" no es una tabla; es un join de solo lectura de tarjetas aprobadas → pedidos → facturas. Tramos en `commission_tiers`. Estados: `pendiente_cobro` (no totalmente facturado o cobrado), `por_pagar` (ganada, mes de cierre en curso) y `pagado` (mes de cierre ya pasado, liquidada el día 1-5). El estado depende del **mes de cierre del pedido**, no de la fecha del cobro. `commission_month_snapshots` fija % y monto al cerrar el mes. El comando `commissions:settle-internal` (03:45 diario) las liquida al libro.
- **Externas** (`ArchitectCommissionService`): independiente de las internas a propósito. Estados: `aun_no_generada` (no facturado y cobrado al 100 %), `pendiente_factura` (lista, sin fecha de pago) y `pagada`. Monto = proyecto × % (`fiscal_settings.comision_externa_default_percent`, default 10) o el % aprobado del proyecto; el impuesto depende del régimen del arquitecto: `exento` (0), `con_itbms` (comisión × tasa) o retención del 50 % (la mitad de esa tasa). Un % distinto al default se **propone** y Mark lo **decide**. Se exige cuenta de cobro antes de marcar pagada; el recordatorio al arquitecto va por `SendArchitectReminderEmailJob`.
- **Cartera de Felix** (`CarteraCommissionService`): tramos en `cartera_commission_tiers`, siempre sobre el mes en curso y gestión de cartera de +90 días.
- Todos los tramos se editan solo por Mark (`primary_approver_only`) y los lee el grupo amplio de Reportes.

### 4.6 Caja

- **Flujo de caja** (`CashPositionService`): encabezado con saldo bancario (no depende de ventana), vista *proyectado* y vista *real* (ventana configurable). Exportar *Real + Hoy* se rechaza.
- **Arqueo del día** (`DailyCashCountService`): se arma solo desde cobros, notas y pagos de comisión; el usuario solo edita observaciones por fila y una general. Estados `abierto → pendiente_aprobacion → aprobado`. Al cerrar se congela `snapshot` y no se recalcula; aprobar exige estado `pendiente_aprobacion`. `arqueo_tracking_start_date` marca desde cuándo se exige.
- **Caja chica:** gastos con fecha, proveedor, monto bruto e ITBMS; se agrupan en un reporte (`transferencia`, `efectivo`, `cheque`, `yappy`) que pasa por `pendiente_aprobacion → finalizado | rechazado`. Cada rechazo de un gasto suma un intento (`rechazado_temporal`); al llegar a `fiscal_settings.petty_cash_max_intentos_rechazo` (default 2) queda `rechazado_permanente`. Solo uno permanente puede reabrirse: la reapertura pone el contador en 0 y lo deja `reabierto`. El saldo vive en `fiscal_settings.petty_cash_balance`.

### 4.7 Parámetros (nunca constantes)

| Dónde | Campo | Default |
|---|---|---|
| `fiscal_settings` | `dias_credito_factura` | 30 |
| | `primary_approval_threshold` | 5 000.00 |
| | `comision_externa_default_percent` | 10.00 |
| | `petty_cash_max_intentos_rechazo` | 2 |
| | `pac_ambiente`, `pac_doc_factura_habilitado`, `pac_doc_nota_credito_habilitado`, `fe_reintento_max_horas`, `primary_approver_user_id`, `responsable_administracion_user_id`, `retencion_proveedores_activa` | ver manual de Factura Electrónica |
| `admin_contab_settings` (pantalla `FraudSettingsPage`) | `fraude_monto_desviaciones` | 3 |
| | `fraude_monto_ventana_dias` | 90 |
| | `fraude_aprobaciones_repetidas_n` | 5 |
| | `fraude_aprobaciones_repetidas_minutos` | 10 |
| `itbms_rates` | tasas; las 3 base no se crean ni se borran por API | sembradas |

Las alertas de fraude las consume `SecurityAnalysisJob`; el umbral de monto se auto-calibra por desviación estándar en vez de una cifra fija.

---

## 5. Flujos principales

### 5.1 De la entrega al cobro
1. Bodega deja el pedido en `por_despachar`; aparece en *pendiente de facturar*.
2. `POST /admin-contab/invoices/preview` y luego `POST /admin-contab/invoices` (`InvoiceOrderIdsRequest`).
3. `InvoiceCreated` activa dos listeners (registrados en `AppServiceProvider`): `MarkOrderInvoiceReady` (Bodega) y `CertifyInvoiceOnCreated` (certificación electrónica).
4. `POST /admin-contab/payments` registra el cobro; si es inmediato, baja el saldo y escribe el movimiento.
5. Un cobro `esperando_confirmacion` espera `PUT /admin-contab/payments/{id}/confirm`.

### 5.2 Nota de crédito
`POST /notas-credito` (o `/correccion/preview` y `/correccion`) → si supera el umbral o es corrección, queda pendiente → `PUT /notas-credito/{creditNote}/decision` (Mark) → efecto real, folio, PDF y correo. La certificación electrónica de la nota sigue el flujo de FE.

### 5.3 Cierre del día
Ver `GET /cash-position/daily-count`, anotar observaciones, `POST …/close` (congela el snapshot) y `POST /cash-position/history/{id}/approve`. El historial y el detalle se consultan por `history` y `history/{id}`.

### 5.4 Caja chica
Registrar gastos (`POST /petty-cash/expenses`) → generar reporte (`POST /petty-cash/reports`) → aprobar o rechazar (`PUT …/approve` o `…/reject`). Un rechazo de línea suma un intento.

### 5.5 Reportes
`ReportsController`, `ClientCollectionReportService`, `InvoiceBookService` y `PaymentMethodSalesReportService` delegan en los servicios de dominio; los Excel usan `ExcelMoney` para celdas numéricas con formato de moneda.

---

## 6. API

Prefijo `/api`. Permisos en OR. Tabla generada con `scripts/manual-routes-table.py admin-contab` desde `route:list` el 2026-10-12 (161 rutas). "La comprueba el controlador" suele significar el grupo `primary_approver_only` (solo Mark o superadmin) o un chequeo interno. Las rutas de factura electrónica (`fe-*`, `fiscal-settings`, `invoices/receptors`) se explican en el manual de Factura Electrónica.

**`/admin-contab/abonos`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/abonos` | `AdminContAbonoController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/abonos` | `AdminContAbonoController@store` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/abonos/cascada/facturas` | `AdminContAbonoController@facturas` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/abonos/cascada/facturas-por-subcliente` | `AdminContAbonoController@facturasPorSubcliente` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/abonos/cascada/proyectos` | `AdminContAbonoController@proyectos` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/abonos/cascada/sub-clientes` | `AdminContAbonoController@subClientes` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/abonos/{id}` | `AdminContAbonoController@update` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/abonos/{id}` | `AdminContAbonoController@show` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/abonos/{id}/confirm` | `AdminContAbonoController@confirm` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/abonos/{id}/pdf` | `AdminContAbonoController@pdf` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/account-statement`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/account-statement` | `AdminContAccountStatementController@show` | `admin_contab.read` |
| GET | `/admin-contab/account-statement/clients` | `AdminContAccountStatementController@clients` | `admin_contab.read` |
| GET | `/admin-contab/account-statement/export` | `AdminContAccountStatementController@exportExcel` | `admin_contab.read` |
| GET | `/admin-contab/account-statement/pdf` | `AdminContAccountStatementController@pdf` | `admin_contab.read` |
| GET | `/admin-contab/account-statement/projects` | `AdminContAccountStatementController@projects` | `admin_contab.read` |

**`/admin-contab/bank-accounts`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/bank-accounts` | `AdminContBankAccountController@index` | `admin_contab.read` |
| POST | `/admin-contab/bank-accounts` | `AdminContBankAccountController@store` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/bank-accounts/{id}` | `AdminContBankAccountController@update` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/bank-accounts/{id}/deactivate` | `AdminContBankAccountController@deactivate` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/bank-accounts/{id}/reactivate` | `AdminContBankAccountController@reactivate` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/bank-movements`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/bank-movements` | `AdminContBankMovementController@index` | `admin_contab.read` |
| PUT | `/admin-contab/bank-movements/{id}/assign-account` | `AdminContBankMovementController@assignAccount` | `role:superadmin,lider_admin_contab,asistente_administrativa` |

**`/admin-contab/calendar`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/calendar` | `OutlookCalendarController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/cash-position`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/cash-position` | `AdminContCashPositionController@header` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/cash-position/daily-count` | `AdminContCashPositionController@dailyCount` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| POST | `/admin-contab/cash-position/daily-count/close` | `AdminContCashPositionController@closeDailyCashCount` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| PUT | `/admin-contab/cash-position/daily-count/entries` | `AdminContCashPositionController@updateDailyCashCountEntry` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| GET | `/admin-contab/cash-position/daily-count/export` | `AdminContCashPositionController@dailyCountExport` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| PUT | `/admin-contab/cash-position/daily-count/general-observation` | `AdminContCashPositionController@updateDailyCashCountGeneralObservation` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| GET | `/admin-contab/cash-position/export` | `AdminContCashPositionController@export` | `role:superadmin,lider_admin_contab,management` |
| GET | `/admin-contab/cash-position/history` | `AdminContCashPositionController@history` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/cash-position/history/{id}` | `AdminContCashPositionController@historyDetail` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/cash-position/history/{id}/approve` | `AdminContCashPositionController@approveDailyCashCount` | `(la comprueba el controlador)` |
| GET | `/admin-contab/cash-position/history/{id}/export` | `AdminContCashPositionController@historyExport` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/cash-position/projected` | `AdminContCashPositionController@projected` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/cash-position/real` | `AdminContCashPositionController@real` | `role:superadmin,lider_admin_contab,management` |

**`/admin-contab/commissions`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/commissions/cartera/tiers` | `AdminContCarteraCommissionController@tiers` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/commissions/cartera/tiers` | `AdminContCarteraCommissionController@storeTier` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/commissions/cartera/tiers/{id}` | `AdminContCarteraCommissionController@updateTier` | `(la comprueba el controlador)` |
| DELETE | `/admin-contab/commissions/cartera/tiers/{id}` | `AdminContCarteraCommissionController@destroyTier` | `(la comprueba el controlador)` |
| GET | `/admin-contab/commissions/external` | `AdminContArchitectCommissionController@summary` | `admin_contab.read` |
| GET | `/admin-contab/commissions/external/architects` | `AdminContArchitectCommissionController@architectOptions` | `admin_contab.read` |
| PUT | `/admin-contab/commissions/external/architects/{architectId}/fiscal-profile` | `AdminContArchitectCommissionController@updateFiscalProfile` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/commissions/external/bank-accounts` | `AdminContArchitectCommissionController@bankAccountOptions` | `admin_contab.read` |
| GET | `/admin-contab/commissions/external/projects/{pipelineCardId}/comprobante-retencion` | `AdminContArchitectCommissionController@showComprobanteRetencion` | `admin_contab.read` |
| POST | `/admin-contab/commissions/external/projects/{pipelineCardId}/comprobante-retencion` | `AdminContArchitectCommissionController@uploadComprobanteRetencion` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| GET | `/admin-contab/commissions/external/projects/{pipelineCardId}/cuenta-cobro` | `AdminContArchitectCommissionController@showCuentaCobro` | `admin_contab.read` |
| POST | `/admin-contab/commissions/external/projects/{pipelineCardId}/cuenta-cobro` | `AdminContArchitectCommissionController@uploadCuentaCobro` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| PUT | `/admin-contab/commissions/external/projects/{pipelineCardId}/cuenta-pago` | `AdminContArchitectCommissionController@updateCuentaPago` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| POST | `/admin-contab/commissions/external/projects/{pipelineCardId}/mark-paid` | `AdminContArchitectCommissionController@markPaid` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/commissions/external/projects/{pipelineCardId}/percent/decide` | `AdminContArchitectCommissionController@decidePercent` | `(la comprueba el controlador)` |
| POST | `/admin-contab/commissions/external/projects/{pipelineCardId}/percent/propose` | `AdminContArchitectCommissionController@proposePercent` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/commissions/external/projects/{pipelineCardId}/remind` | `AdminContArchitectCommissionController@sendReminder` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/commissions/internal` | `AdminContInternalCommissionController@summary` | `admin_contab.read,admin_contab.commissions_internal.view` |
| GET | `/admin-contab/commissions/internal/export` | `AdminContInternalCommissionController@export` | `admin_contab.read` |
| GET | `/admin-contab/commissions/internal/tiers` | `AdminContInternalCommissionController@tiers` | `admin_contab.read,admin_contab.commissions_internal.view` |
| POST | `/admin-contab/commissions/internal/tiers` | `AdminContInternalCommissionController@storeTier` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/commissions/internal/tiers/{id}` | `AdminContInternalCommissionController@updateTier` | `(la comprueba el controlador)` |
| DELETE | `/admin-contab/commissions/internal/tiers/{id}` | `AdminContInternalCommissionController@destroyTier` | `(la comprueba el controlador)` |
| GET | `/admin-contab/commissions/internal/vendors` | `AdminContInternalCommissionController@vendorOptions` | `admin_contab.read` |
| GET | `/admin-contab/commissions/internal/{vendedorId}/account-statement` | `AdminContInternalCommissionController@accountStatement` | `admin_contab.read,admin_contab.commissions_internal.view` |
| GET | `/admin-contab/commissions/internal/{vendedorId}/account-statement/pdf` | `AdminContInternalCommissionController@accountStatementExport` | `admin_contab.read,admin_contab.commissions_internal.view` |

**`/admin-contab/company-profile`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/company-profile` | `AdminContCompanyProfileController@show` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/company-profile` | `AdminContCompanyProfileController@update` | `(la comprueba el controlador)` |
| POST | `/admin-contab/company-profile/logo` | `AdminContCompanyProfileController@uploadLogo` | `(la comprueba el controlador)` |

**`/admin-contab/contacts`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/contacts` | `AdminContContactController@index` | `(la comprueba el controlador)` |
| POST | `/admin-contab/contacts` | `AdminContContactController@store` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/contacts/{id}` | `AdminContContactController@update` | `(la comprueba el controlador)` |

**`/admin-contab/fiscal-settings`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/fiscal-settings` | `AdminContFiscalSettingsController@show` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/fiscal-settings` | `AdminContFiscalSettingsController@update` | `(la comprueba el controlador)` |

**`/admin-contab/home`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/home/approvals-pending` | `AdminContHomeController@approvalsPending` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/home/pendientes` | `AdminContHomeController@pendientes` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/home/resumen-mes` | `AdminContHomeController@resumenMes` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/home/vencidos-por-vencer` | `AdminContHomeController@vencidosPorVencer` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/invoices`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/invoices` | `AdminContInvoiceController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/invoices` | `AdminContInvoiceController@store` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/invoices/aging` | `AdminContInvoiceController@aging` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/invoices/export` | `AdminContInvoiceController@export` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/invoices/pending` | `AdminContInvoiceController@pending` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/invoices/preview` | `AdminContInvoiceController@preview` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PATCH | `/admin-contab/invoices/receptors/{subClientId}` | `AdminContInvoiceReceptorController@update` | `admin_contab.edit.receptor_fiscal · throttle:tax-lookup` |
| GET | `/admin-contab/invoices/summary` | `AdminContInvoiceController@summary` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/invoices/{order}/detail` | `AdminContInvoiceController@detail` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/invoices/{order}/fe-actualizar-estado` | `AdminContFeReintentoController@actualizarEstadoFactura` | `(la comprueba el controlador) · throttle:fe-accion` |
| GET | `/admin-contab/invoices/{order}/fe-preview` | `AdminContInvoiceController@fePreview` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/invoices/{order}/fe-reconciliar` | `AdminContFeReintentoController@reconciliarFactura` | `(la comprueba el controlador) · throttle:fe-accion` |
| POST | `/admin-contab/invoices/{order}/fe-reintentar` | `AdminContFeReintentoController@reintentarFactura` | `(la comprueba el controlador) · throttle:fe-accion` |
| GET | `/admin-contab/invoices/{order}/fe-transmisiones` | `AdminContFeTransmisionController@factura` | `(la comprueba el controlador)` |
| POST | `/admin-contab/invoices/{order}/mark-uncollectible` | `AdminContInvoiceController@markUncollectible` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/invoices/{order}/pdf` | `AdminContInvoiceController@pdf` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/invoices/{order}/uncollectible-decision` | `AdminContInvoiceController@uncollectibleDecision` | `(la comprueba el controlador)` |

**`/admin-contab/itbms-rates`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/itbms-rates` | `AdminContItbmsRateController@index` | `(la comprueba el controlador)` |
| POST | `/admin-contab/itbms-rates` | `AdminContItbmsRateController@store` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/itbms-rates/{id}` | `AdminContItbmsRateController@update` | `(la comprueba el controlador)` |
| DELETE | `/admin-contab/itbms-rates/{id}` | `AdminContItbmsRateController@destroy` | `(la comprueba el controlador)` |

**`/admin-contab/locations`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/locations` | `AdminContLocationController@index` | `(la comprueba el controlador)` |
| POST | `/admin-contab/locations` | `AdminContLocationController@store` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/locations/{id}` | `AdminContLocationController@update` | `(la comprueba el controlador)` |

**`/admin-contab/notas-credito`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| POST | `/admin-contab/notas-credito` | `AdminContCreditNoteController@store` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito` | `AdminContCreditNoteController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/clientes/{masterClient}/facturas` | `AdminContCreditNoteController@facturas` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/notas-credito/correccion` | `AdminContCreditNoteController@storeCorreccion` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/notas-credito/correccion/preview` | `AdminContCreditNoteController@previewCorreccion` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/devoluciones/{customerReturn}` | `AdminContCreditNoteController@devolucionDetail` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/devoluciones/{customerReturn}/signed-document` | `AdminContCreditNoteController@devolucionSignedDocumentUrl` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/facturas/{order}/itbms-treatment` | `AdminContCreditNoteController@itbmsTreatment` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/itbms-rates` | `AdminContCreditNoteController@itbmsRates` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/summary` | `AdminContCreditNoteController@summary` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/{creditNote}` | `AdminContCreditNoteController@show` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/notas-credito/{creditNote}/comprobante` | `AdminContCreditNoteController@comprobante` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/notas-credito/{creditNote}/decision` | `AdminContCreditNoteController@decision` | `(la comprueba el controlador)` |
| POST | `/admin-contab/notas-credito/{creditNote}/fe-actualizar-estado` | `AdminContFeReintentoController@actualizarEstadoNotaCredito` | `(la comprueba el controlador) · throttle:fe-accion` |
| GET | `/admin-contab/notas-credito/{creditNote}/fe-preview` | `AdminContCreditNoteController@fePreview` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/notas-credito/{creditNote}/fe-reconciliar` | `AdminContFeReintentoController@reconciliarNotaCredito` | `(la comprueba el controlador) · throttle:fe-accion` |
| POST | `/admin-contab/notas-credito/{creditNote}/fe-reintentar` | `AdminContFeReintentoController@reintentarNotaCredito` | `(la comprueba el controlador) · throttle:fe-accion` |
| GET | `/admin-contab/notas-credito/{creditNote}/fe-transmisiones` | `AdminContFeTransmisionController@notaCredito` | `(la comprueba el controlador)` |
| GET | `/admin-contab/notas-credito/{creditNote}/pdf` | `AdminContCreditNoteController@pdf` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/payments`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/payments` | `AdminContPaymentController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/payments` | `AdminContPaymentController@store` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/payments/clients` | `AdminContPaymentController@clients` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/payments/default-bank-account` | `AdminContPaymentController@defaultBankAccount` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/payments/open-invoices` | `AdminContPaymentController@openInvoices` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/payments/summary` | `AdminContPaymentController@summary` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/payments/{id}` | `AdminContPaymentController@show` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/payments/{id}/attachment` | `AdminContPaymentController@attachment` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/payments/{id}/confirm` | `AdminContPaymentController@confirm` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/payments/{id}/pdf` | `AdminContPaymentController@pdf` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/payments/{id}/retention-attachment` | `AdminContPaymentController@retentionAttachment` | `role:superadmin,lider_admin_contab,asistente_administrativa` |

**`/admin-contab/petty-cash`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| POST | `/admin-contab/petty-cash/expenses` | `AdminContPettyCashController@storeExpenses` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| GET | `/admin-contab/petty-cash/expenses/{expenseId}/attachments/{attachmentId}/url` | `AdminContPettyCashController@attachmentUrl` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/petty-cash/expenses/{id}` | `AdminContPettyCashController@expenseDetail` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/petty-cash/expenses/{id}` | `AdminContPettyCashController@updateExpense` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| POST | `/admin-contab/petty-cash/expenses/{id}/attachments` | `AdminContPettyCashController@addExpenseAttachment` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| PUT | `/admin-contab/petty-cash/expenses/{id}/reject` | `AdminContPettyCashController@rejectExpense` | `(la comprueba el controlador)` |
| PUT | `/admin-contab/petty-cash/expenses/{id}/reopen` | `AdminContPettyCashController@reopenExpense` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| GET | `/admin-contab/petty-cash/pending` | `AdminContPettyCashController@pending` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/petty-cash/rejected` | `AdminContPettyCashController@rejected` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/petty-cash/reports` | `AdminContPettyCashController@reports` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| POST | `/admin-contab/petty-cash/reports` | `AdminContPettyCashController@storeReport` | `role:superadmin,lider_admin_contab,asistente_administrativa` |
| GET | `/admin-contab/petty-cash/reports/{numero}` | `AdminContPettyCashController@reportDetail` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/petty-cash/reports/{numero}/approve` | `AdminContPettyCashController@approveReport` | `(la comprueba el controlador)` |
| GET | `/admin-contab/petty-cash/reports/{numero}/pdf` | `AdminContPettyCashController@reportPdf` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| PUT | `/admin-contab/petty-cash/reports/{numero}/reject` | `AdminContPettyCashController@rejectReport` | `(la comprueba el controlador)` |
| GET | `/admin-contab/petty-cash/summary` | `AdminContPettyCashController@summary` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/reports`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/reports/cartera` | `AdminContReportsController@cartera` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/comisiones` | `AdminContReportsController@comisiones` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/felix-commission` | `AdminContReportsController@felixCommission` | `role:superadmin,lider_admin_contab,management` |
| GET | `/admin-contab/reports/flujo-caja` | `AdminContReportsController@flujoCaja` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/libro-facturas` | `AdminContInvoiceBookController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/libro-facturas/excel` | `AdminContInvoiceBookController@excel` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/mensual-cliente` | `AdminContClientReportsController@mensual` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/mensual-cliente-acumulado` | `AdminContClientReportsController@acumulado` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/mensual-cliente-acumulado/excel` | `AdminContClientReportsController@acumuladoExcel` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/mensual-cliente/excel` | `AdminContClientReportsController@mensualExcel` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/notas-credito` | `AdminContReportsController@notasCredito` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/ventas` | `AdminContReportsController@ventas` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/ventas-medio-pago` | `AdminContPaymentMethodSalesController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |
| GET | `/admin-contab/reports/ventas-medio-pago/excel` | `AdminContPaymentMethodSalesController@excel` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/saldo-favor`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/saldo-favor` | `AdminContSaldoFavorController@index` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` |

**`/admin-contab/settings`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/admin-contab/settings` | `AdminContSettingsController@show` | `admin_contab.read` |
| PUT | `/admin-contab/settings` | `AdminContSettingsController@update` | `admin_contab.settings.configure` |

---

## 7. Permisos, roles y acceso

| Quién | Qué puede |
|---|---|
| `superadmin`, `lider_admin_contab`, `asistente_administrativa`, `management` | Operar la mayoría de las pantallas: facturar, cobrar, abonar, notas, comisiones externas, reportes. |
| `asistente_administrativa` | Sin acceso a Flujo de caja *real* ni a la comisión de Felix; sí al arqueo y caja chica. |
| **Mark** (`primary_approver_only`, `EnsureIsPrimaryApprover`) | Configuración fiscal, ITBMS, empresa, ubicaciones y contactos; decidir notas, incobrables y % de comisión; aprobar el arqueo del día y los reportes y gastos de caja chica; crear, editar y borrar tramos de comisión; reintentar certificaciones; ver transmisiones. |
| `admin_contab.commissions_internal.view` | Ver comisiones internas propias (un vendedor ve lo suyo, no lo ajeno). |
| `admin_contab.edit.receptor_fiscal` | Corregir el receptor en la vista previa y actualizar la razón social del cliente. |
| `admin_contab.edit.receptor_consumidor_final` | Facturar a Consumidor final por decisión de quien factura (SCRUM-1140). Va a quien factura; ver `docs/runbooks/alta-de-facturador.md`. |
| `admin_contab.settings.configure` | Editar los umbrales de fraude. |
| `superadmin.all` | Pantalla de fraude. |

El gate de rol está en `routes/admin-contab.php`; la aprobación y el *ownership* que una ruta no expresa (por ejemplo, aprobar un arqueo) los comprueba el controlador. Los permisos llegan del JWT (`JwtClaimsBuilder`, `RoleModuleVisibility`).

---

## 8. Frontend

Rutas (`App.tsx`), todas con `admin_contab.view` y, salvo excepción, el rol del equipo de la sección 7: `/admin-contab/inicio` (`InicioPage`), `/fiscal` (`FiscalConfigPage`), `/empresa` (`CompanyProfilePage`), `/cuentas-bancarias` (`BankAccountsPage`), `/facturacion` (`FacturacionPage`), `/facturacion/estado-cuenta` (`EstadoCuentaPage`), `/cobros` (`CobrosPage`), `/notas-credito` (`NotasCreditoPage`), `/abonos` (`AbonosPage`), `/saldo-favor` (`SaldoFavorPage`), `/comisiones/internas` (`ComisionesInternasPage`), `/comisiones/externas` (`ComisionesExternasPage`), `/arqueo-caja` (`ArqueoCajaPage`), `/caja-chica` (`PettyCashPage`), `/reportes` (`ReportesPage`), `/reportes/mensual-cliente` (`MensualClientePage`), `/reportes/mensual-cliente-acumulado` (`MensualClienteAcumuladoPage`), `/reportes/libro-facturas` (`LibroFacturasPage`), `/reportes/ventas-medio-pago` (`VentasMedioPagoPage`), `/datos-fiscales-clientes` (`ClientesFiscalPage`) y `/configuracion-fraude` (`FraudSettingsPage`, `superadmin.all`).

**Componentes** (`components/admin-contab/`)
- Inicio: `InicioHeader`, `ResumenDelMesPanel`, `PendientesPanel`, `VencidosPorVencerPanel`, `AdminContMyCalendarPanel`.
- Facturación: `InvoiceDetailModal`, `InvoiceStatusModal`, `InvoiceFiscalSummary`, `AntiguedadCarteraPanel`, `ReceptorGuardNotice`, `FiscalDocumentPreview`.
- Cobros y abonos: `RegistrarCobroModal`, `DetalleCobroModal`, `HistorialCobrosPanel`, `VerComprobanteModal`, `RegistrarAbonoModal`.
- Notas: `RegistrarNotaCreditoModal`, `RevisionPreviaCorreccionModal`, `DetalleNotaCreditoModal`, `DocumentoNotaCreditoModal`, `HistorialNotasCreditoPanel`, `VerComprobanteNotaCreditoModal`.
- Comisiones: `CommissionTiersModal`, `CommissionAccountStatementModal`, `ArchitectCommissionDetailModal`, `ArchitectFiscalProfileModal`.
- Caja y bancos: `BankAccountDetailModal`, `RegistrarGastoCajaChicaModal`, `GenerarReportePettyCashModal`, `DetalleReportePettyCashModal`, `DetalleLineaCajaChicaModal`, `VerSoporteCajaChicaModal`.
- Reportes: `ClientCollectionReportView`.
- Factura electrónica y configuración: `FiscalElectronicStatusBadge`, `FeAccionFiscal`, `FeTransmisionesPanel`, `ItbmsRateDetailModal`; además `components/FiscalSettingsPanel.tsx` y `components/InvoiceStatusModal.tsx` fuera de la carpeta.

**Capa de datos:** `api/adminContabApi.ts` (cliente), `hooks/useAdminContab.ts` (consultas y mutaciones de TanStack Query) y `types/adminContab.ts`.

---

## 9. Eventos, jobs e integraciones

| Elemento | Detalle |
|---|---|
| `InvoiceCreated` (`admin_contab.invoice.created`) | Lo disparan `InvoiceService::confirm`, `CreditNoteService` (factura de reemplazo) y `FiscalCertificationService`. Listeners: `MarkOrderInvoiceReady` (Bodega, hoy sin efecto) y `CertifyInvoiceOnCreated`. |
| `SendCreditNoteConfirmedEmailJob` | Correo de la nota con PDF y estado de cuenta; reintentos con backoff. |
| `SendArchitectReminderEmailJob` | Recordatorio de cuenta de cobro al arquitecto. |
| `commissions:settle-internal` | Diario 03:45: liquida comisiones internas al libro. |
| Bodega | Fuente de entregas, pedidos y devoluciones. |
| Ventas & Diseño | Tarjetas, cotizaciones y clientes (lectura). |
| Compras | Tasa de ITBMS compartida (`ItbmsRateResolver`). |
| MSL / DGI | Certificación y validación de RUC (ver manual de FE). |
| S3 | Comprobantes, constancias, adjuntos de caja chica y logo, en disco privado con URL firmada. |
| Auditoría | Trait `LogsAuditTrail` en los modelos de escritura. |

---

## 10. Configuración y datos semilla

- **Seeders:** `AdminContBankAccountSeeder`, `AdminContFiscalIdentitySeeder`, `AdminContPrimaryApproverBackfillSeeder`, `AdminContRoleVisibilityBackfillSeeder`.
- **Comandos:** `commissions:settle-internal` (agendado), `commissions:backfill-cash-movements`, `admin-contab:backfill-credit-note-applied-amount`, `admin-contab:backfill-daily-cash-count-snapshots`, `admin-contab:set-pac-provider`, y los de QA `admincont:seed-rebotes-qa` y `qa:seed-invoiced-compras`.
- **Mark:** `fiscal_settings.primary_approver_user_id` identifica al aprobador; sin él `primary_approver_only` no deja pasar a nadie salvo superadmin.
- **Variables:** las de S3 y las del PAC del `.env` general.

---

## 11. Pruebas

`tests/Feature/AdminCont/` (41 archivos, incluye `FacturacionElectronica/`, `Concerns/` y `Concurrency/`) y `tests/Unit/AdminCont/`. Ejecutar con `infra/test.sh --filter=AdminCont`.

| Tema | Archivos |
|---|---|
| Inicio y facturación | `AdminContHomeControllerTest`, `AdminContHomePendingAlertsControllerTest`, `AdminContHomeVencidosPorVencerControllerTest`, `AdminContInvoiceControllerTest`, `AdminContInvoiceDeliveryProgressTest`, `AdminContInvoiceFamilyRootTest`, `AdminContPartialInvoicingTest`, `AdminContPartialInvoicingConsumersTest`, `InvoiceReceptorGuardTest`, `InvoiceConcurrencyTest` |
| Cobros, abonos, saldo | `AdminContPaymentControllerTest`, `AdminContAbonoControllerTest`, `AdminContSaldoFavorControllerTest`, `AdminContAccountStatementControllerTest` |
| Notas | `AdminContCreditNoteControllerTest`, `CreditNoteSaldoAcreditableTest`, `CreditableBalanceServiceTest` |
| Comisiones | `AdminContInternalCommissionControllerTest`, `AdminContArchitectCommissionControllerTest` |
| Caja | `AdminContCashPositionControllerTest`, `AdminContBackfillDailyCashCountSnapshotsCommandTest`, `AdminContBankAccountControllerTest`, `AdminContBankMovementControllerTest`, `AdminContPettyCashControllerTest` |
| Reportes | `AdminContReportsControllerTest`, `AdminContClientReportsControllerTest`, `AdminContInvoiceBookControllerTest`, `AdminContPaymentMethodSalesControllerTest` |
| Configuración y seeders | `AdminContFiscalSettingsControllerTest`, `AdminContCompanyProfileControllerTest`, `AdminContItbmsRateControllerTest`, `AdminContLocationControllerTest`, `AdminContContactControllerTest`, `AdminContSettingsControllerTest`, `AdminContBankAccountSeederTest`, `AdminContFiscalIdentitySeederTest`, `AdminContPrimaryApproverBackfillSeederTest`, `AdminContSetPacProviderCommandTest`, `LogsAuditTrailTest`, `PdfFooterTextTest` |
| Factura electrónica | `CertifyCreditNoteTest`, `CertifyInvoiceOnCreatedTest`, `DigifactPacAdapterTest`, `DigifactTokenServiceTest`, `FeReintentoTest`, `FeTransmisionesTest`, `FiscalCertificationServiceTest`, `FiscalDocumentBuilderNotaCreditoTest`, `FiscalDocumentBuilderNumeroFiscalTest`, `FiscalDocumentBuilderTest`, `FiscalDocumentItemsBuilderTest`, `FiscalDocumentPreviewTest`, `FiscalDocumentSerieResolverTest`, `ItbmsCodeMapperTest`, `MslJsonMapperTest`, `MslPacAdapterTest`, `MslPaymentMethodMapperTest`, `NucJsonMapperTest`, `PacProviderFactoryTest`, `PollMslDocumentEstadoJobTest`, `PreQa1019FeMslTest` |

Frontend: `npx vitest run src/components/admin-contab src/pages/admin-contab`.

---

## 12. Recetas para modificar el módulo

- **Un método de pago nuevo:** constantes y `METODOS`/`METODO_LABELS` de `AdminContPayment`, si confirma de inmediato o no, si lleva cuenta, `MslPaymentMethodMapper` (FE), `PaymentMethodSalesReportService` (columna) y `RegistrarCobroModal`.
- **Un tipo de nota nuevo:** constantes de `AdminContCreditNote`, `RegisterCreditNoteRequest`, `CreditNoteService::register`/`decide`, `CreditableBalanceService` y `RegistrarNotaCreditoModal`.
- **Cambiar el umbral de aprobación de notas:** pantalla *Fiscal* (`primary_approval_threshold`); no tocar código.
- **Un tramo o régimen de comisión:** tramos desde la pantalla de comisiones; regímenes en `ArchitectFiscalProfile::REGIMENES` y `ArchitectCommissionService::montoFor`.
- **Un reporte nuevo:** servicio de dominio + `*ExportService`, controlador fino, ruta bajo el grupo de reportes, página y entrada en `ReportesPage`; reutilizar `ReportPeriodResolver` y `ExcelMoney`.
- **Un parámetro nuevo:** columna en `fiscal_settings` (si es fiscal) o clave en `admin_contab_settings` con su `AdminContSettingsService`; nunca constante.
- **Cuidado con la facturación:** todo cambio en `InvoiceBillingPlanner` debe mantener el invariante de la sección 4.1 y correr `AdminContPartialInvoicingTest` e `InvoiceConcurrencyTest`.

---

## 13. Problemas conocidos y deuda

1. **`PendingAlertsService::DIAS_UMBRAL_COMISION_PENDIENTE = 10`** está fijo en código (alerta de comisión pendiente), contra la regla de parametrizar umbrales.
2. **Rangos de antigüedad fijos** (0-30, 31-60, 61-90, +91): es convención de reporte declarada, pero no editable.
3. **Servicios gigantes:** `CreditNoteService` (1 423), `InvoiceService` (1 824) y `ArchitectCommissionService` (965) concentran mucha lógica.
4. **ITBMS al 7 % duplicado en Compras** (`GoodsReceiptController::ITBMS_RATE_LOCAL`), declarado como deuda en `ItbmsRateResolver`.
5. **Dos `primary_approver_user_id` distintos** (Compras y fiscal), ya registrado en el manual de FE.
6. **Doc de conocimiento de FE desactualizado** sobre la retención del 3.5 %, ya registrado en el manual de FE.
7. **Reglas por rol, no por permiso:** cambiar quién puede operar exige editar `routes/admin-contab.php` y las guardas de `App.tsx`.
8. **`home/approvals-pending`** devuelve lo que otros módulos aprueban; no es una bandeja propia.
9. **`MarkOrderInvoiceReady` es un listener sin efecto:** `orders.invoice_ready` ya no se usa (Bodega calcula "facturado" en vivo); ver el manual de Bodega.

---

## 14. Glosario

| Término | Significado |
|---|---|
| **Entrega** | Un pedido de Bodega (`Order`); no existe tabla propia. |
| **Pedido raíz** | Tarjeta raíz de la familia de entregas; la factura completa cuelga de ella. |
| **F-, REC-, CONF-, ABN-, NC-** | Prefijos de folio: factura, recibo de cobro, confirmación, abono, nota de crédito. |
| **VAR** | Valor acreditable restante de una factura. |
| **Abono** | Anticipo registrado a nivel cliente, proyecto o factura. |
| **Saldo a favor** | Crédito disponible de un cliente (abonos y notas). |
| **Incobrable** | Factura que se retira de la cartera tras aprobación de Mark. |
| **Arqueo** | Cierre diario de caja con conciliación y aprobación. |
| **Cuenta de cobro** | Documento que el arquitecto entrega para cobrar su comisión. |
| **primary_approver_only** | Alias del middleware que deja pasar solo a Mark o superadmin. |
| **PAC** | Proveedor autorizado de certificación de facturas electrónicas. |

<!-- nav-manuales:inicio -->
---
**Navegación:** [Índice de manuales](README.md) · ← [Bodega](bodega.md) · [Factura Electrónica](factura-electronica.md) →
<!-- nav-manuales:fin -->
