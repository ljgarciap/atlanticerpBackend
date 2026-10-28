# Pre-QA + Visual Review (fusionado) — Admin&Cont, Estado de Cuenta Batch 9 (SCRUM-534→538, REQ-457→461)

**Fecha:** 2026-08-23
**Resultado: PASADA LIMPIA. Los 5 tickets pasan a QA.**

Gate real ya corrido y verificado — no se repite acá: `docs/reviews/scrum534-538-estadocuenta-batch9-20260823.md`
(2530/2530 backend + PHPStan Level 8 limpio de todo lo tocado por este batch, 1432/1432 frontend +
tsc limpio).

## 0. Ambiente de prueba

Local (`localhost:5173` vía Vite dev, proxy a `localhost:8090`). Fixture sembrado vía tinker:
MasterClient "Playwright EC Batch9" (id 670), 2 SalesProject (Norte id 604, Sur id 605), 3
`AdminContInvoice` (F-338600 $4,000 pagada en su totalidad, F-922846 $2,200 abierta en Norte,
F-190237 $1,500 abierta en Sur), 1 `AdminContPayment` confirmado de $4,000 aplicado a F-338600,
1 `AdminContBankAccount` activa (Banco General/Corriente ****8901),
`fiscal_settings.responsable_administracion_user_id` apuntando a Contabilidad. 6 escenarios corridos con
**Playwright CLI** (regla dura del proyecto) contra una cuenta real (`conta@atlantic.com.pa`,
Contabilidad, `lider_admin_contab`, password = email).

## 1. SCRUM-534 / REQ-457 — Tarjeta de saldo

- **Escenario 1:** verificado en vivo — "Saldo actual" muestra `USD 3,700.00`
  ((4,000−4,000)+2,200+1,500), calculado sobre las 3 facturas del cliente sin filtrar por proyecto.
  Captura `01-resultado-todos-proyectos.png`.
- **Escenario 2 (filtrado por proyecto):** verificado en vivo — al filtrar por "Proyecto Sur" el
  saldo recalcula a `USD 1,500.00` (solo la factura de ese proyecto), confirmando que el filtro de
  proyecto SÍ acota el cálculo del saldo, no solo la tabla de movimientos.
- **RN1 (saldo a favor, estilo distinto):** no reproducido con datos reales en este batch (ningún
  escenario del fixture deja saldo negativo) — cubierto por test backend dedicado
  (`test_saldo_a_favor_cuando_el_cliente_pago_de_mas`, aritmética verificada: dos facturas $8,660 y
  $4,825 con dos pagos $8,660 y $5,325 → saldo −$500) y por el estilo condicional ya verificado en
  `EstadoCuentaPage.test.tsx`.

**Sin hallazgos en REQ-457.**

## 2. SCRUM-535 / REQ-458 — Bloque de datos de pago

- **Escenario 1:** verificado en vivo — "Cuenta número: Banco General ****8901" y
  "Responsable: Contabilidad Test" visibles siempre, incluso sin filtrar por proyecto. Captura
  `01-resultado-todos-proyectos.png`.
- **RN3 (idéntico entre Facturación y Estado de Cuenta):** verificado por diseño, no solo por
  observación — ambas pantallas llaman al mismo `InvoiceService::paymentBlock()` estático (ver
  Senior Review). Test backend dedicado (`AdminContInvoiceControllerTest`) confirma que Facturación
  ya no depende de `created_by` de la factura individual.
- **Nota de dato local, no un hallazgo de código:** "Pago a:" apareció vacío en la corrida local
  porque `fiscal_settings.nombre_comercial` no estaba sembrado en el fixture de este Pre-QA (gap del
  fixture, no del código) — el campo lee `AdminContFiscalSettings::current()->nombre_comercial`
  igual que en Facturación (ya probado ahí). No bloquea, documentado para no confundirlo con un bug
  si se repite la corrida sin sembrar ese campo.

**Sin hallazgos en REQ-458.**

## 3. SCRUM-536 / REQ-459 — Nota automática de contexto

- **Escenario 1 (más de 1 proyecto con saldo pendiente):** verificado en vivo — "Este cliente tiene
  2 proyectos." aparece cuando se busca sin filtrar por proyecto (RN3). Desaparece al filtrar por un
  proyecto específico (verificado en el Escenario 2 de REQ-457, la nota no aparece en la captura
  `03-filtro-proyecto-sur.png`), consistente con que `proyectos_count` solo se calcula cuando
  `sales_project_id === null`.
- **Prioridad de reglas (factura vencida / saldo a favor / cuenta completada):** no ejercitado con
  los 3 escenarios reales en esta corrida (el fixture no tiene facturas >90 días vencidas ni saldo
  exactamente en 0) — cubierto por los tests backend dedicados de `contextNote()` que verifican
  la prioridad fija entre las 4 reglas (vencida > saldo a favor > Notas de Crédito [nunca true hoy]
  > completada), incluida la fórmula de vencimiento compartida con `agingBuckets()`.

**Sin hallazgos en REQ-459.**

## 4. SCRUM-537 / REQ-460 — Tabla de movimientos

- **Escenario 1 (vista Agrupado por proyecto, default):** verificado en vivo — 2 tablas separadas
  ("Proyecto Norte" / "Proyecto Sur"), cada una con las 8 columnas del mockup (Fecha, N° Factura,
  Concepto, N° Cotización, **Guía de Entrega**, Débito, Crédito, Saldo) y subtotal por proyecto.
  Captura `02-tabla-movimientos.png`.
- **Filas de crédito además de débito:** verificado en vivo — F-338600 aparece 2 veces (fila
  "Factura" de débito $4,000 + fila "Cobro" de crédito $4,000, referenciando el mismo N° Factura) —
  comportamiento correcto (una fila por transacción, no por factura), no un duplicado.
- **Hallazgo NO bloqueante, confirma diseño correcto (no un bug):** el saldo corrido de cada fila
  dentro de un grupo de proyecto es el saldo GLOBAL del cliente en ese punto cronológico, no un
  saldo recalculado por sub-grupo — ej. la fila de crédito de F-338600 (dentro de "Proyecto Norte")
  muestra saldo $3,700 (el saldo final real), mientras que la fila de F-190237 (dentro de "Proyecto
  Sur", listada después en la página pero anterior en el orden cronológico global) muestra $7,700.
  Verificado contra el diseño documentado en el Senior Review — es el comportamiento esperado
  (`movements()` calcula el saldo corrido una sola vez sobre el array completo; el agrupado visual
  no recalcula), no se reporta como hallazgo.
- **Escenario 2 (filtro por proyecto):** verificado en vivo — solo F-190237 visible, F-922846
  ausente, saldo de la tabla filtrada = $1,500. Captura `03-filtro-proyecto-sur.png`.
- **Escenario 3 (filtro por rango de fechas):** verificado en vivo — fijar "Desde" a una fecha
  futura (2030-01-01) deja la tabla sin movimientos del fixture (F-338600 ya no visible). Captura
  `04-filtro-fecha-futuro-vacio.png`.
- **Excel con tabla de movimientos:** verificado en vivo — "Descargar / Imprimir" → "Descargar como
  Excel" dispara una descarga real (`download` no nulo). Contenido de la segunda tabla verificado
  por test backend dedicado (`AccountStatementExportServiceTest`-equivalente dentro del controller
  test), no reabierto manualmente en esta corrida.

**Sin hallazgos en REQ-460.**

## 5. SCRUM-538 / REQ-461 — Apertura automática desde Facturación

- **Escenario 1 (RN1, `master_client_id` en la URL):** verificado en vivo —
  `/admin-contab/facturacion/estado-cuenta?master_client_id=670` carga con el cliente y el saldo
  ($3,700.00) ya generados sin ninguna interacción manual. Captura `05-apertura-automatica.png`.
- **RN2 (falla en silencio):** no reproducido con un id inexistente en esta corrida — cubierto por
  el diseño verificado en el Senior Review (`onError` de la mutation no muestra nada, limpia el
  query param) y por `EstadoCuentaPage.test.tsx` (caso dedicado con mock de error).

**Sin hallazgos en REQ-461.**

## Visual Review — fidelidad contra el mockup

Mockup: `4A1__Admin_Contabilidad_CXC_EstadoCuenta.html`. La tarjeta de saldo, el bloque de datos de
pago (Pago a / Cuenta número / Responsable) y la tabla de movimientos (con toggle
Agrupado/Vista plana y las 8 columnas, incluida Guía de Entrega) coinciden 1:1 con el mockup — mismo
layout, mismo orden de campos, mismos textos. Estilo condicional de saldo a favor (RN1 REQ-457) no
verificado visualmente en esta corrida (sin dato real que lo dispare) pero implementado siguiendo el
mismo patrón de color condicional ya usado en Cobros/Facturación.

**Sin hallazgos críticos de Visual Review.**
