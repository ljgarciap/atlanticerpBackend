# Pre-QA + Visual Review (fusionado) — Admin&Cont, Facturación Batch 3 (SCRUM-519→523, REQ-442→446)

**Fecha:** 2026-08-21
**Resultado: PASADA LIMPIA — los 5 tickets pasan a QA.** Sin hallazgos bloqueantes. 2 hallazgos
MENORES (cosmético/i18n) y 1 hallazgo de entorno (no imputable a este batch) documentados abajo.

Commits evaluados: backend `d38323b`, frontend `564e2bd` (rama `dev`, sin pushear todavía).
Gate previo ya corrido y verificado — no se repite acá: `infra/test.sh` 2442/2442 + PHPStan Level 8
limpio (backend), `npm run test` 1345/1345 + `tsc --noEmit` limpio (frontend), Senior Review
aprobado sin hallazgos (`docs/reviews/scrum519-523-facturacion-batch3-20260821.md`).

---

## 0. Hallazgo de entorno (bloqueó el arranque, no es de este batch)

El ambiente local (`docker compose` en `atlanticerp-backend/infra`) estaba con **cero datos de
negocio** pese a `APP_ENV=local`: `orders`, `pipeline_cards`, `quotes`, `master_clients`,
`catalog_products`, `crm.projects` — las 6 en 0 filas. Nunca se había corrido `db:seed` en este
checkout (o se corrió antes de un `docker volume` nuevo). Sin esto, Escenario 1/2 de REQ-442 eran
imposibles de reproducir con "un cliente real con una entrega pendiente", como pedía el encargo.

**Corregido en esta sesión:** `docker compose exec laravel php artisan db:seed --force` (mismo
comando que usa `infra/deploy-manual.sh`) — trajo el roster de 40 usuarios reales + demo de
CRM/VentasDiseno/Servicios. Confirmado que no rompió nada (`CoreUserSeeder` es create-only, no tocó
usuarios existentes). Recomendado documentar esto como gotcha de entorno en la memoria del
proyecto — no se tocó `atlanticerp/CLAUDE.md` ni la memoria desde este documento, queda a criterio del
Arquitecto al consolidar.

Como aun con el seed no existe ninguna Quote/Order real facturable (`VentasDisenoDemoSeeder` solo
llega hasta `pipeline_cards`), se sembraron 4 clientes de prueba completos vía `tinker`
(MasterClient→SubClient→SalesProject→PipelineCard→Quote con ítems reales→Order en
`por_despachar`), replicando exactamente el fixture de
`tests/Feature/AdminCont/AdminContInvoiceControllerTest::makeFacturableOrder()`:

| Cliente (subclient) | Proyecto | Order / monto | Saldo a favor sembrado | Escenario |
|---|---|---|---|---|
| PREQA Subcliente A | PREQA Proyecto A | PED-PREQA-A001 / $1000 | $300 (< monto) | 1 — parcial |
| PREQA Subcliente B | PREQA Proyecto B | PED-PREQA-B001 $500 + B002 $700 | $2000 (> monto de la 1ra) | 2 + RN3 (lote) |
| PREQA Subcliente C | PREQA Proyecto C | PED-PREQA-C001 / $250 | sin fila (0) | 3 — real, default |
| PREQA Subcliente D | PREQA Proyecto D | PED-PREQA-D001 / $300 | sin fila (0) | control "todavía pendiente" para RN1/RN5 |

**Todo este fixture (clientes A-D, subclientes, proyectos, pipeline cards, quotes+ítems, orders,
facturas creadas durante las pruebas, y las 2 filas de `customer_credit_balances`) fue revertido al
cerrar la sesión** — confirmado por SQL: `orders`/`quotes`/`customer_credit_balances`/`invoices` en
0 filas de nuevo. El `db:seed` general (usuarios/CRM/VentasDiseno/Servicios demo) se dejó — es el
estado correcto y esperado de un entorno `local`, no un artefacto de esta sesión.

---

## 1. SCRUM-519 / REQ-442 — Saldo a favor

Verificado en vivo con Playwright contra `http://localhost:5173`, 3 escenarios + RN3 de lote:

- **Escenario 3 (real, sin saldo — el 100% de los clientes reales hoy):** confirmado con Cliente C
  (sin fila en `customer_credit_balances`) — la vista previa NO muestra ninguna opción de saldo a
  favor, ni "Saldo a favor aplicado" ni "Total a pagar". Captura:
  `atlanticerp-frontend/test-results/scrum519-523-escenario3-sin-saldo.png`.
- **Escenario 1 (saldo $300 < monto $1000, Cliente A):** checkbox visible y marcado por defecto
  (RN1), monto disponible correcto. Al confirmar, verificado por SQL directo — no solo por texto en
  pantalla — que `AdminContInvoice.monto=1000.00`, `saldo_aplicado=300.00`, y que
  `customer_credit_balances.saldo` bajó de 300 a 0 (RN2/RN4). Captura:
  `scrum519-523-escenario1-saldo-parcial.png`.
- **Escenario 2 + RN3 (saldo $2000 > monto de la 1ra entrega, lote de 2, Cliente B):** desde "+
  Crear Factura" se seleccionaron ambas entregas del mismo proyecto (B001 $500 + B002 $700). La
  vista previa muestra el saldo disponible real ($2000, sin capar en el checkbox informativo) Y el
  aviso EXPLÍCITO "El saldo a favor solo se aplicará a la primera factura de este lote." Confirmado
  por SQL tras guardar: `B001.saldo_aplicado=500.00` (capado al monto de esa entrega, RN2: nunca
  mayor al menor entre saldo y monto — min(2000,500)=500), `B002.saldo_aplicado=0.00` (RN3: el
  resto del lote nunca lo recibe), `customer_credit_balances` bajó de 2000 a 1500. Captura:
  `scrum519-523-escenario2-rn3-lote.png`.
- **RN6 (Bodega recibe la notificación):** verificado por SQL que las 4 entregas confirmadas en
  esta sesión terminaron con `bodega.orders.invoice_ready = true` — mecanismo reusado sin cambios
  del Batch 2 (`Bodega\Listeners\MarkOrderInvoiceReady`), sigue funcionando con el flujo de saldo
  nuevo.

**Sin hallazgos bloqueantes.** RN1-RN4 y RN6 confirmados con datos reales de punta a punta (UI +
base de datos), no solo con el test unitario.

## 2. SCRUM-520 / REQ-443 — Vista previa de factura

- RN1 (pinta de documento fiscal real): confirmado — encabezado, cliente/cotización, tabla de
  ítems con descripción/cantidad/precio unitario/subtotal, desglose subtotal/ITBMS/total. Ver
  capturas de Escenario 1/2 arriba.
- RN2 (varias entregas apiladas): confirmado en Escenario 2 — la vista previa renderiza un bloque
  por cada `order_id` del lote, cada uno con su propio desglose.
- **RN3 ("Editar" vuelve al formulario sin perder la selección) — test dedicado:** desde "+ Crear
  Factura" con Cliente D/Proyecto D, abrir la vista previa y clickear "Editar selección" vuelve al
  wizard con cliente, proyecto y el checkbox de la entrega YA seleccionados (verificado
  `<select>.value` de ambos campos + el checkbox de la entrega siguiendo marcado) — no un
  formulario vacío. Captura: `scrum519-523-req443-rn3-editar.png`.
- RN4 (folio real al confirmar, nada se guarda antes): confirmado — antes de "Guardar factura(s)"
  no existe ninguna fila en `admin_contab.invoices`; al confirmar, folio real asignado
  (`AdminContInvoiceFolioService`) y estado pasa a "Facturada".

**Sin hallazgos bloqueantes.**

## 3. SCRUM-521 / REQ-444 — Flujo guiado "+ Crear Factura"

- **RN1 (selector NO restringido por los filtros de la tabla) — el bug real del batch anterior,
  foco explícito del encargo:** se filtró la tabla principal a "PREQA Subcliente A" (`Mostrando 1
  de N`) y se abrió "+ Crear Factura" — el selector de Cliente siguió ofreciendo Cliente D
  (todavía pendiente-facturar, sin relación con el filtro activo). Confirmado que el fix
  (`useInvoiceList({tab:'cobrable', view:'plana'})` sin pasar los filtros de la tabla) funciona.
  Captura: `scrum519-523-rn1-wizard-sin-filtro.png`.
- RN2 (entregas marcadas por defecto al elegir proyecto): confirmado con Cliente B (2 entregas,
  ambas chequeadas automáticamente).
- RN3 (se puede desmarcar): implícito en el flujo de RN5 de abajo (se desmarcaron manualmente).
- **RN5 (bloqueo de avance sin ninguna entrega marcada, mensaje INLINE, nunca
  `window.alert()`/`confirm()`):** se desmarcaron todas las entregas de un proyecto y se clickeó
  "Ver vista previa" — apareció el mensaje inline "Selecciona al menos una entrega para facturar.",
  la vista previa NO se abrió, y se instaló un listener de `page.on('dialog', ...)` durante todo el
  test que nunca disparó — confirmado que no hay ningún diálogo nativo bloqueante. Captura:
  `scrum519-523-rn5-mensaje-inline.png`.
- RN6: ver sección 1 (mismo mecanismo, verificado ahí).

**Sin hallazgos bloqueantes.**

## 4. SCRUM-522 / REQ-445 — Barra de filtros y búsqueda

- RN1 (búsqueda insensible a mayúsculas, parcial): cubierto por el propio flujo de los tests
  anteriores (filtro por subcliente en varias ocasiones, siempre funcionó).
- **RN4 (contador "Mostrando X de Y" con denominador real) — el bug real del batch anterior:**
  medido el contador sin filtro (`X=Y=total`) y luego con un filtro que reduce a 1 resultado — Y
  (total de la pestaña) se mantuvo IDÉNTICO entre ambas mediciones, mientras X bajó de forma real.
  Si el bug viejo (`list.length:list.length`, X siempre igual a Y) hubiera vuelto, esta prueba
  falla explícitamente (assertion `visibleFiltered < totalFiltered`). Captura:
  `scrum519-523-rn4-counter.png`.
- RN5 (Limpiar filtros no cambia la pestaña de cartera activa): no re-verificado explícitamente en
  este pase (sin cambios de código en esta área del batch) — revisado en código
  (`clearFilters()` no toca `cartera`), consistente con lo esperado.

**Sin hallazgos bloqueantes.**

## 5. SCRUM-523 / REQ-446 — Exportar reporte (PDF/Excel)

- **RN1 (la exportación respeta EXACTAMENTE los filtros activos) — verificado por CONTENIDO, no
  solo por el request:** con la tabla filtrada a "PREQA Subcliente B" se exportó a Excel real,
  se abrió el `.xlsx` descargado (zip) y se inspeccionó el XML crudo de la hoja — contiene
  `PED-PREQA-B001`/`PED-PREQA-B002` y NO contiene `PED-PREQA-A001`/`PED-PREQA-C001`. Archivo:
  `atlanticerp-frontend/test-results/scrum519-523-export-filtrado.xlsx`.
- RN2 (mismas columnas que en pantalla): confirmado en código
  (`InvoiceExportService::rowValues()` — Entrega/Hito, N° Factura, N° Cotización, Monto, Estado —
  coincide con las columnas de `FacturacionPage.tsx`) y visualmente en el Excel abierto.

**Sin hallazgos bloqueantes.**

## 6. RBAC negativo (los 5 tickets)

`vendedor_disenador` (Neil Quiel) → `GET /api/admin-contab/invoices` responde **403** real
(confirmado por `curl` directo con JWT real, no solo mockeado) y la UI no expone ningún dato de
Facturación al navegar directo a la ruta. Sin cambios respecto al Batch 2 (mismo gate de rol),
verificado igual para este batch por si algún cambio de ruta lo hubiera aflojado sin querer.

---

## Hallazgos MENORES (no bloqueantes, no impiden pasar a QA)

1. **Símbolo de moneda duplicado/confuso en el checkbox de saldo a favor.** El string i18n
   `adminContab:facturacion.preview.aplicarSaldo` es
   `"Aplicar saldo a favor disponible (${{monto}})"` — antepone un `"$"` literal delante del
   resultado de `formatCurrency()`, que en formato `es-PA` YA renderiza con el código de moneda
   (`"USD 300.00"`, sin símbolo `$`, como se ve en el resto de la pantalla: "Total factura USD
   0.00", "Monto facturado este mes USD 250.00"). El resultado visible es
   **"Aplicar saldo a favor disponible ($USD 300.00)"** — moneda indicada dos veces de forma
   inconsistente con el resto de la UI. Archivo:
   `atlanticerp-frontend/src/i18n/locales/{es,en}/adminContab.json`, clave `preview.aplicarSaldo`. Fix
   sugerido: quitar el `$` literal del template (dejar solo `({{monto}})`), ya que
   `formatCurrency()` aporta el indicador de moneda por sí solo.
2. **Ambigüedad visual de "N° Cotización" cuando un proyecto agrupa entregas de Quotes distintas.**
   `FacturacionPage.tsx` (`groups` `useMemo`) fija `cotizacion_folio` del grupo con el de la
   PRIMERA entrega encontrada (`map.set` solo si `!map.has`) — con el fixture de Cliente B (2
   Quotes distintas, `COT-PREQA-B001`/`COT-PREQA-B002`, mismo proyecto) el encabezado del grupo
   mostró solo una de las dos. No es un dato incorrecto (cada fila interna sigue mostrando su
   propio folio) — es puramente el rótulo de resumen del grupo. No bloqueante; se menciona porque
   es un patrón real que puede repetirse (proyectos con re-cotizaciones tienen más de un folio).

## Nota de verificación (no un hallazgo — quedó descartada tras reproducirla)

Al armar el primer fixture de prueba SIN ítems de cotización (`QuoteItem`), la vista previa mostró
"Total factura USD 0.00" para una entrega de $1000 — porque `InvoiceService::fiscalBreakdown()`
deriva el total de la Quote (`netTotal()+itbms()`, que da 0 sin ítems), y el frontend usa
`r.total ?? r.monto` (0 no activa el fallback porque no es `null`/`undefined`). **Se confirmó que
esto NO afecta el dinero real**: `InvoiceService::confirm()` siempre usa
`PipelineCard.amount` (nunca el total de la Quote) para `monto` y para el cálculo de
`saldo_aplicado`/`total_a_pagar` — la factura guardada fue correcta ($250.00) pese al $0.00 mostrado
en la vista previa de ese primer intento. Se rehizo el fixture con ítems reales
(`unit_price` calculado para que `netTotal()+itbms()` coincida con `PipelineCard.amount`) y todas
las mediciones de Escenario 1/2 de este documento usan ese fixture correcto. Se documenta la
mecánica acá porque el Senior Review ya identificó el mismo riesgo teórico ("no hay divergencia
posible mientras la cotización no se edite sin volver a pasar por el sync de
`QuoteService.php:510`") — esta sesión reprodujo en vivo el caso donde SÍ diverge (una Quote sin
ítems o no sincronizada), confirmando que el riesgo es real aunque hoy no ocurra con datos de
producción. Queda como riesgo aceptado y ya documentado por Senior Review, no como hallazgo nuevo
de este batch.

---

## Veredicto

**Pasada limpia.** Los 5 tickets (SCRUM-519→523) pasan a QA (Marly). Ningún hallazgo bloqueante —
los 2 MENORES quedan a criterio de PM para agendar como ajuste cosmético menor (no ameritan volver
a pasar por Senior Review ni retrasar el push a `dev`).

Evidencia completa (capturas Playwright + export real) en `atlanticerp-frontend/test-results/` (carpeta
gitignored, local): `scrum519-523-rbac-negativo.png`, `scrum519-523-escenario3-sin-saldo.png`,
`scrum519-523-escenario1-saldo-parcial.png`, `scrum519-523-escenario2-rn3-lote.png`,
`scrum519-523-rn1-wizard-sin-filtro.png`, `scrum519-523-rn4-counter.png`,
`scrum519-523-rn5-mensaje-inline.png`, `scrum519-523-req443-rn3-editar.png`,
`scrum519-523-export-filtrado.xlsx`. Spec de Playwright:
`atlanticerp-frontend/e2e/preqa-scrum519-523-facturacion-batch3-20260821.spec.ts` (untracked, mismo
patrón que otros specs de Pre-QA de este proyecto — no autosembrado, depende de fixtures por
tinker documentados arriba; no se dejó ningún dato de prueba en la base al cerrar la sesión).
