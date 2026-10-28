# Pre-QA + Visual Review (fusionado) — Admin&Cont, Cobros Batch 7 (SCRUM-549→552, REQ-472→475)

**Fecha:** 2026-08-23
**Resultado: PASADA LIMPIA. Los 4 tickets pasan a QA.**

Gate real ya corrido y verificado — no se repite acá: `docs/reviews/scrum549-552-cobros-batch7-20260823.md`
(2503/2503 backend + PHPStan Level 8 limpio, 1411/1411 frontend + tsc limpio).

## 0. Ambiente de prueba

Local (`localhost:5173` vía Vite dev, proxy a `localhost:8090`). Fixture sembrado vía tinker:
MasterClient "PreQA Batch7 Cobros" (id 645), factura F-PREQA7 $1,500, cobro REC-001 por
transferencia registrado arrancando `esperando_confirmacion` (RN2 REQ-469, ya validado en Batch 6).
4 escenarios corridos con **Playwright CLI** (regla dura del proyecto).

## 1. SCRUM-549 / REQ-472 — Ver comprobante de pago adjunto

- **Escenario 1 (con comprobante):** cubierto por 2 tests backend (`test_attachment_devuelve_url_firmada_y_metadata_cuando_si_tiene_comprobante`,
  con `Storage::fake`) + 2 tests frontend (`VerComprobanteModal.test.tsx`, PDF vía `<iframe>` e
  imagen vía `<img>`, cada uno mostrando "Adjuntado por X · fecha").
- **Escenario 2 (sin comprobante) — verificado en vivo:** el cobro sembrado no tiene comprobante;
  al presionar "Ver comprobante" en el navegador real se ve el mensaje "Este cobro no tiene
  comprobante adjunto." (RN2), nunca una pantalla vacía ni un error — captura `03-comprobante-vacio.png`
  del recorrido (spec ephemeral, no promovida a permanente, mismo criterio que Batch 6).

**Sin hallazgos en REQ-472.**

## 2. SCRUM-550 / REQ-473 — Ver / Imprimir recibo formal

- **RN1 (datos completos del recibo):** verificado leyendo el PDF real generado por el endpoint
  contra el fixture (empresa, estado "CONFIRMADO"/fecha, cliente, método, cuenta destino,
  referencia, registrado por, N° de recibo, tabla de facturas con monto aplicado, total recibido) —
  todos presentes. RUC/nombre comercial en blanco en el PDF de este entorno es dato de
  `fiscal_settings` sin poblar en la BD local (mismo campo que ya usa el PDF de Facturación,
  Batch 4) — no es un bug de este batch.
- **RN2 (estado "Esperando confirmación" visible, nunca "Confirmado" antes de tiempo):** cubierto
  por `PaymentReceiptPdfService`/`payment-receipt-pdf.blade.php` (usa `$detail['estado']` real, sin
  asumir confirmado) — verificado en vivo generando el recibo ANTES de confirmar (pill amarilla
  "Esperando confirmación") y DESPUÉS de confirmar (pill teal "Confirmado", más el bloque de
  confirmación con CONF-XXX).
- **Escenario 1 (imprimir/guardar):** el botón descarga un PDF real (`recibo-REC-001.pdf`,
  verificado con `page.waitForEvent('download')`) — reusa el mismo patrón de blob-download que "Ver
  PDF" de Facturación (Batch 4, ya Senior-revisado), en vez del `window.print()` inline del mockup
  estático. Decisión de diseño documentada en el Senior Review — no es una desviación accidental.

**Sin hallazgos en REQ-473.**

## 3. SCRUM-551 / REQ-474 — Apertura automática con cliente preseleccionado

- **Ya implementada de punta a punta desde Batch 5** (`CobrosPage.tsx` lee `master_client_id`+
  `accion=nuevo-cobro`, `FacturacionPage.tsx` los envía desde "Registrar cobro") — verificado
  leyendo el código antes de re-implementar nada.
- **Escenario 1 (cliente válido):** cubierto por `RegistrarCobroModal.test.tsx` nuevo — el nombre
  se completa y las facturas pendientes se ven, sin que el usuario tenga que buscarlo.
- **Escenario 2 (cliente inválido) — hallazgo real encontrado por mis propios tests:** el
  formulario SÍ se abría (correcto, sigue el criterio de "no romper nada"), pero `masterClientId`
  quedaba con el id inválido pegado en el estado en vez de comportarse como recién abierto
  manualmente. Corregido — ver Senior Review. Verificado con test dedicado: sin nombre resuelto, la
  sección de facturas desaparece, el campo de cliente queda vacío, sin ningún mensaje de error.

**1 hallazgo, corregido en el mismo ciclo — no queda pendiente.**

## 4. SCRUM-552 / REQ-475 — "Marcar como confirmado"

- **RN1 (botón solo visible en `esperando_confirmacion`):** verificado en vivo — el cobro sembrado
  (esperando_confirmacion) mostraba el botón; tras confirmarlo, el botón desapareció del modal sin
  recargar la página (invalidación de query). Test backend dedicado
  (`test_confirm_sobre_cobro_ya_confirmado_falla_422`) + frontend
  (`REQ-475 RN1 — "Marcar como confirmado" solo aparece en esperando_confirmacion`).
- **RN2 (registro CONF-XXX propio, con quién y cuándo):** verificado en vivo — tras confirmar, el
  detalle mostró "CONF-001 — confirmado por Contabilidad Test el 23 ago 2026", captura
  `04-confirmado.png`. Test backend fija el formato `/^CONF-\d{3}$/` y que es independiente del
  `numero_recibo` (columna separada, contador separado).
- **RN3 (pasa a Confirmado, cuenta en indicadores, reduce saldo pendiente):** verificado — tras
  confirmar, la tarjeta "Total cobrado" del mes pasó de $0.00 a $1,500.00 en vivo (sin refrescar la
  página), y el test backend confirma `invoice->saldo_aplicado` actualizado + movimiento en
  `bank_movements` + saldo a favor aplicado si correspondía
  (`test_confirm_aplica_saldo_de_factura_saldo_favor_y_ledger_bancario`).
- **RN4 (no deshacible):** no existe ningún endpoint de "desconfirmar" — intentar confirmar 2 veces
  el mismo cobro devuelve 422 (`test_confirm_no_puede_correrse_dos_veces`), consistente con "no se
  puede deshacer desde la pantalla".
- **RN5 (sin validación bancaria automática):** confirmado por diseño — el endpoint no llama a
  ningún servicio externo, depende 100% del clic.
- **Escenario 3 (botón no visible en cobro ya confirmado):** cubierto por el mismo test de RN1.

**Sin hallazgos en REQ-475.**

## Visual Review — fidelidad contra el mockup

Mockup: `4C__Admin_Contabilidad_Cobros.html` (mismo archivo ya analizado en Batch 5/6). El modal de
detalle mantiene layout/estructura ("Ver comprobante"/"Ver recibo" en el footer, exactamente como
el mockup) — único cambio de comportamiento real es que "Ver recibo" descarga en vez de abrir un
overlay con `window.print()` (decisión de diseño, ver arriba, no elimina funcionalidad: el usuario
igual puede imprimir/guardar el PDF descargado). El botón "Marcar como confirmado" no existe en el
mockup estático (es una regla de negocio del Excel de requerimientos, no capturada en el HTML
demo) — su ausencia en el mockup no es un criterio de bloqueo (Visual Review solo bloquea por
elementos DEL MOCKUP ausentes en el desarrollo, nunca al revés). Título del modal corregido para
mostrar el cliente (`REC-XXX — {cliente}`, como el mockup) en vez de `registrado_por` — gap real de
Batch 6 corregido de paso en este batch, sin ticket propio (dato ahora disponible por primera vez
gracias a REQ-473).

**Sin hallazgos críticos de Visual Review.**
