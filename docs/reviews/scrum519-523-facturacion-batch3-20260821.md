# Senior Review — Admin&Cont, Facturación Batch 3 (SCRUM-519→523, REQ-442→446)

**Fecha:** 2026-08-21
**Resultado: APROBADO. Sin hallazgos bloqueantes.**

Batch 3 del cuerpo principal de la épica Admin&Cont (ver `docs/architecture/admin-contab-batches.md`).
Backend Dev y Frontend Dev implementaron en paralelo (forks) contra un contrato de API fijado por
el Arquitecto antes del despacho — verificado que ambos lados lo respetaron sin desvíos relevantes.

## Gate real (suite completa)

- Backend: PHPStan Level 8 (`--memory-limit=1G`) → `[OK] No errors`. `infra/test.sh` completo →
  **2442/2442 tests, 9807 assertions, OK** (incluye 67 tests nuevos/ajustados de `AdminCont`).
- Frontend: `npm run test -- --run` completo → **116 archivos, 1345 tests, OK**. `tsc --noEmit` →
  sin errores.

## Arquitectura — verificado en código

- **Saldo a favor (REQ-442):** `admin_contab.customer_credit_balances` — infraestructura
  fundacional a propósito, sin escritor real todavía (Notas de Crédito, el único origen legítimo,
  es un batch muy posterior). `CreditBalanceService::forMasterClient()` devuelve 0.0 sin fila —
  confirmado que Escenario 3 (sin saldo, ninguna opción visible) funciona hoy para el 100% de los
  clientes reales, tal como se esperaba. RN3 (saldo solo sobre `order_ids[0]`) implementado en
  `previewBatch()`/`confirmBatch()` — verificado que el resto del lote nunca lo ofrece aunque el
  cliente tenga saldo. `CreditBalanceService::apply()` usa `lockForUpdate()` dentro de la misma
  transacción de `confirm()` — correcto contra condiciones de carrera.
- **Fidelidad de la vista previa (REQ-443) — punto verificado a fondo antes de aprobar:** el nuevo
  `fiscalBreakdown()` calcula `total = netTotal() + itbms()` sobre la `Quote` de origen. Existía
  riesgo real de que este total divergiera de `monto` (`PipelineCard.amount`, la cifra que
  efectivamente se factura) si ambos no se derivaran de la misma fórmula. Confirmado en
  `QuoteService.php:510` que `card.amount` se sincroniza como `$quote->grandTotal()` —
  **exactamente la misma fórmula** (`Quote::grandTotal() = netTotal() + itbms()`, `Quote.php:271`).
  No hay divergencia posible mientras la cotización no se edite sin volver a pasar por ese sync
  (riesgo preexistente del módulo VentasDiseno, fuera de alcance de este batch).
- **REQ-444 RN1 (bug real de Batch 2 corregido en este batch):** el selector de cliente/proyecto de
  `CrearFacturaModal` quedaba restringido a los filtros activos de la tabla principal (búsqueda,
  estado, fechas) en vez de mostrar TODA la cartera cobrable pendiente. Frontend Dev lo corrigió
  con un fetch independiente (`{ tab: 'cobrable', view: 'plana' }`, sin los demás filtros) —
  verificado en el código que es un query separado del de la tabla.
- **REQ-444 RN2/RN5:** entregas marcadas por defecto al elegir proyecto (antes arrancaba vacío);
  `window.alert()` reemplazado por mensaje inline — correcto, un `alert()` real hubiera bloqueado
  cualquier corrida de Playwright de Pre-QA/Visual Review.
- **REQ-445 RN4 (bug real de Batch 2 corregido):** el contador "Mostrando X de Y" usaba
  `list.length` para ambos lados (siempre 1:1, no informaba nada). Ahora usa `total_en_tab`
  (`InvoiceService::countInTab()`, cuenta la pestaña activa sin los demás filtros) — contrato
  verificado consistente en ambos lados (`{ rows, total_en_tab }` / `{ groups, total_en_tab }`).
- **REQ-446:** Excel reutiliza el patrón de `Bodega\Services\OrderExportService` (PhpSpreadsheet),
  PDF reutiliza el patrón de los `*PdfService` de Servicios/VentasDiseno (dompdf) — sin inventar un
  mecanismo nuevo. Export respeta los filtros activos (RN1) — el controller arma las filas con
  `view: 'plana'` forzado vía `+` (los filtros del caller no pueden pisarlo), correcto.
- Gates de rol sin cambios respecto a Batch 2 (`role:superadmin,lider_admin_contab,
  asistente_administrativa,management`), incluido el nuevo endpoint `/export`.

## Fidelidad visual contra el mockup (`4B__Admin_Contabilidad_Facturacion.html`)

No evaluada en este documento — corre fusionada con Pre-QA en el mismo despacho adversarial (ver
regla de fusión Visual Reviewer + Pre-QA, `atlanticerp/CLAUDE.md`).

## Veredicto

**Pasada limpia, sin fixes necesarios.** Los 5 tickets (SCRUM-519→523) pasan a Pre-QA + Visual
Review (fusionado). Commits locales en `dev`, sin pushear todavía (backend `d38323b`, frontend
`564e2bd`) — el push ocurre después de que Pre-QA/Visual Review termine limpio.
