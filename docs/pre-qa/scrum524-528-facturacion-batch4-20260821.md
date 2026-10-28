# Pre-QA + Visual Review (fusionado) — Admin&Cont, Facturación Batch 4 (SCRUM-524→528, REQ-447→451)

**Fecha:** 2026-08-21/22
**Resultado ACTUALIZADO tras re-check completo (2026-08-21/22, segunda pasada): PASADA LIMPIA.
Los 5 tickets pasan a QA.** Ver sección "Re-check completo tras hallazgo MAYOR" al final de este
documento para el detalle de la segunda corrida — el resto de este archivo es la corrida ORIGINAL
que encontró el hallazgo (se conserva sin editar para trazabilidad, no reescribir el veredicto de
arriba).

Commits evaluados en la corrida original: backend `9d8ae74`+`52fe7fc`, frontend `3a39ebd`+`137a104`
(rama `dev`, sin pushear). Último batch de Facturación. Gate real ya corrido y verificado — no se
repite acá: `infra/test.sh` 2457/2457 + PHPStan Level 8 limpio (backend), `npm run test` 1357/1357
+ `tsc --noEmit` limpio (frontend), Senior Review aprobado tras 3 fixes propios
(`docs/reviews/scrum524-528-facturacion-batch4-20260821.md`).

---

## 0. Hallazgo de entorno (no es de este batch)

El ambiente local estaba en 0 filas en `orders`/`admin_contab.invoices`/`admin_contab.bank_accounts`
(mismo patrón ya reportado en el Pre-QA de Batch 3). Se sembraron 9 clientes de prueba completos
vía `tinker` (MasterClient→SubClient→SalesProject→PipelineCard→Quote con ítems reales→Order en
`por_despachar`) y se confirmaron las facturas vía la API real (`POST /invoices`), no por inserción
directa — mismo criterio que Batch 3. Adicionalmente se creó **1 cuenta bancaria de prueba**
(`AdminContBankAccount`, banco "Banco PreQA Test", activa) porque el ambiente tenía **0 cuentas
bancarias reales**, y sin ninguna el bloque de pago de REQ-449 no tiene nada que mostrar.

También se detectó, de paso, que `AdminContFiscalSettings::current()` de este tenant tiene
`nombre_comercial`/`ruc`/`dv`/`direccion_fiscal` completamente vacíos — el PDF real muestra "RUC -"
y "Pago a:" en blanco (ver captura `scrum524-528-req449-pdf-anulada-watermark-1.png`). Esto es un
gap de configuración de datos del tenant (Configuración Fiscal, pantalla ya existente de un batch
anterior), no un defecto de código de este batch — se documenta para que el Arquitecto decida si
amerita sembrarse en algún seeder o completarse manualmente antes de la demo a cliente.

**Todo el fixture de esta sesión (9 clientes/subclientes/proyectos/cards/quotes+ítems/orders/8
facturas, 1 cuenta bancaria de prueba, 1 fila de saldo a favor, 2 `OrderDocument` manuales) fue
revertido al cerrar** — confirmado por SQL: `orders`/`admin_contab.invoices`/
`admin_contab.bank_accounts`/`admin_contab.customer_credit_balances` en 0 filas de nuevo, igual que
antes de empezar.

Roster de prueba usado abajo (todos con Quote+ítems reales, `unit_price` calculado para que el
total fiscal coincida con `PipelineCard.amount`, mismo criterio que Batch 3):

| Cliente | Order/Invoice | Escenario |
|---|---|---|
| I | sin factura | REQ-447 Escenario 1 (trazabilidad pendiente) |
| B | F-0002 $850, con Guía de Entrega generada DESPUÉS de la factura | REQ-447 Escenario 2 (completa) |
| C | F-0003 $300, con `OrderDocument` guía_entrega creado ANTES de facturar | REQ-447 RN2 adversarial |
| D | F-0004 $500, saldo a favor $150 aplicado, total a pagar $350 | REQ-449 RN2 + bloque de pago |
| E | F-0005 $620 | REQ-448 workflow completo (Contabilidad propone, RN5, Aprobador rechaza) |
| F | F-0006 $275, `anulada_at` seteado manualmente (sin flujo real vía Notas de Crédito todavía) | REQ-449 RN3 |
| G | F-0007 $900, `created_at` backdateado 75 días, incobrable PENDIENTE | REQ-450 RN1/RN2 + REQ-451 RN2 |
| H | F-0008 $450, `created_at` backdateado 75 días, incobrable APROBADA | REQ-450 Escenario 2 + REQ-451 RN1 |

---

## 1. SCRUM-524 / REQ-447 — Modal de detalle/trazabilidad

- **Escenario 1 (Cliente I, sin factura):** trazabilidad muestra Cotización completa, Factura y
  Guía de Entrega como "Pendiente"; sin vista previa de documento fiscal ni botón de PDF (RN4).
  Verificado en vivo con Playwright. Captura: `scrum524-528-req447-escenario1-pendiente.png`.
- **Escenario 2 (Cliente B, con Guía de Entrega generada DESPUÉS de la factura):** trazabilidad
  completa (3 pasos, ningún "Pendiente" restante), botón "Ver / Imprimir PDF" disponible. Captura:
  `scrum524-528-req447-escenario2-completa.png`.
- **RN2/RN3 (nunca Guía de Entrega antes que factura) — verificado ADVERSARIALMENTE, no solo con el
  test unitario:** se insertó manualmente un `OrderDocument` tipo `guia_entrega` sobre el Order de
  Cliente C **antes** de que existiera factura, y se confirmó por API (`GET .../detail`) que
  `guia_entrega.existe` seguía en `false` mientras no había factura — la doble defensa
  (`detail()` solo busca el documento cuando `$invoice !== null`) funciona exactamente como describe
  el docblock del Senior Review. Al facturar después (dentro de la misma sesión), el documento
  preexistente pasó a mostrarse correctamente — comportamiento esperado, no un hallazgo.

**Sin hallazgos bloqueantes en REQ-447.**

## 2. SCRUM-525 / REQ-448 — Cobrabilidad + aprobación de Aprobador

Verificado tanto por API directa (adversarial) como con Playwright en el navegador real:

- **RN1 (botón solo sobre `facturada`):** confirmado — no aparece sobre pendiente ni sobre `F`
  (anulada, ver sección 3).
- **RN2 (motivo obligatorio):** cubierto por el frontend (mensaje inline) — no se re-probó por ser
  puramente de UI y ya cubierto por test unitario/E2E de Batch anterior con el mismo patrón.
- **RN4 (Aprobador-only) — probado con Contabilidad Y con Aprobador, como pidió el encargo:**
  - Por **API directa**: Contabilidad (`PUT /uncollectible-decision`) → **403** real
    (`{"message":"Esta pantalla es exclusiva de Aprobador."}`), gateado por el middleware
    `EnsureIsMarkApprover` (fail-closed contra `AdminContFiscalSettings.mark_approver_user_id`, que
    en este tenant apunta correctamente a `gerencia3@test.com`).
  - Por **navegador real**: logueado como Contabilidad, el modal de Cliente G (pendiente de aprobación)
    **no muestra los botones Aprobar/Rechazar** (`scrum524-528-req448-contabilidad-sin-botones.png`).
    Logueado como Aprobador, el mismo modal **sí** los muestra (`scrum524-528-req448-mark-con-botones.png`)
    — al hacer clic en "Rechazar" en vivo, el modal vuelve exactamente a normal: sin pastilla
    especial, "Marcar como incobrable" disponible de nuevo, "Registrar cobro" sigue visible
    (`scrum524-528-req448-mark-rechazo-vuelve-normal.png`).
- **RN5 (no proponer un segundo cambio mientras el primero sigue pendiente):** verificado por API —
  segundo intento de `POST /mark-uncollectible` sobre una factura ya con propuesta pendiente →
  **422** (`"Ya existe una propuesta de incobrable pendiente..."`).
- **Escenario 2 (Aprobador aprueba, REQ-450):** verificado con Cliente H — tras aprobar, la factura pasó
  de la pestaña "Cartera cobrable" a "Cartera incobrable" (confirmado por API en ambos tabs) y
  quedó excluida del panel de Antigüedad (ver sección 4).

**HALLAZGO MAYOR — ver sección "Hallazgos" abajo: RN3 ("visible en la fila con una pastilla de
estado distinta") no se cumple — la tabla nunca muestra la pastilla naranja de "Pendiente de
aprobación".**

## 3. SCRUM-526 / REQ-449 — Vista previa/impresión fiscal + estado Anulada

- **RN1 (bloque de pago completo, foco explícito del encargo) — verificado en pantalla Y en el PDF
  real descargado:** con Cliente D se confirmaron en el modal los 3 campos ("Cuenta número: Banco
  PreQA Test ****4321", "Responsable: Contabilidad Test") y se descargó el PDF real (evento `download`
  interceptado por Playwright, no un mock) — `pdftotext` sobre el archivo descargado confirma los 3
  campos + ambos totales. Captura: `scrum524-528-req449-pago-y-saldo.png`.
- **RN2 (saldo a favor, ambos totales):** Cliente D, monto $500 con $150 de saldo aplicado — "Total
  factura" tachado en $500.00, "Saldo a favor aplicado" -$150.00, "Total a pagar" $350.00, visibles
  tanto en el modal como en el PDF descargado.
- **RN3 (marca de agua "ANULADA") — seteado manualmente `anulada_at` sobre la factura de Cliente F
  (no existe flujo real todavía, depende de Notas de Crédito):** confirmado visualmente que el PDF
  real descargado muestra la marca de agua diagonal roja "ANULADA" superpuesta sobre el documento
  completo, y que el modal muestra el aviso de anulada + oculta "Marcar como incobrable" y
  "Registrar cobro". Captura (rasterizada del PDF real con `pdftoppm`):
  `scrum524-528-req449-pdf-anulada-watermark-1.png`.
  - Nota técnica: `pdftotext` (con o sin `-layout`) **no** extrae "ANULADA" como substring contigua
    de este PDF — el watermark está rotado -35° a 90px de font-size (`position:fixed` a página
    completa) y la extracción de texto de poppler reordena los caracteres individuales por la
    rotación extrema (se confirmaron las mismas 7 letras A/N/U/L/A/D/A presentes, pero
    desordenadas). No es un defecto del PDF — la verificación de contenido real para este punto se
    hizo visualmente (rasterizado), que es inequívoco.

**Sin hallazgos bloqueantes en REQ-449** (más allá del gap de configuración fiscal del tenant, ver
sección 0, que no es un defecto de código).

## 4. SCRUM-527 / REQ-450 — Antigüedad de cartera

Verificado con matemática real de antigüedad, no solo con el panel cargando:

- **RN2 (antigüedad desde vencimiento, no desde emisión) — medido con datos reales backdateados:**
  se backdatearon `created_at` de las facturas G y H a 75 días atrás
  (`dias_credito_factura=30` en este tenant → vencimiento hace 45 días). El panel movió
  correctamente ambas de la bucket "0–30 días" a "31–60 días" (`cantidad` 8→6 en 0-30, 0→2 en
  31-60, monto $1,350 = $900+$450) apenas se aplicó el backdate, **antes** de tocar ningún estado de
  cobrabilidad — confirma que el cálculo usa la fecha de vencimiento real, no la de emisión.
- **RN1 (excluye incobrable APROBADA, pendiente sigue contando) — medido con delta real:**
  - Con G y H ambas con propuesta de incobrable **pendiente** (no decidida): bucket 31-60 se
    mantuvo en cantidad 2 / $1,350 — confirma que una propuesta pendiente sigue contando.
  - Tras **Aprobador aprobar** la propuesta de H: bucket 31-60 bajó a cantidad 1 / $900 (solo G) —
    confirma exclusión real apenas se aprueba, no antes.
  - Verificado también en el navegador real (Playwright, captura
    `scrum524-528-req450-aging-panel.png`) con el mismo dato ($900 en 31-60, reflejando a G
    pendiente-pero-cobrable tras el rechazo de esa sesión).

**Sin hallazgos bloqueantes en REQ-450.**

## 5. SCRUM-528 / REQ-451 — Botón "Registrar cobro"

- **RN1 (3 condiciones a la vez) — probado con los 3 casos límite reales, no solo el camino feliz:**
  - Facturada + no incobrable (Cliente D): botón visible, navegación funcional.
  - **Incobrable APROBADA (Cliente H)** — RN1 3ra condición: botón **ausente**, confirmado tanto
    por API (`puede_registrar_cobro: false`) como en el navegador
    (`scrum524-528-req451-h-incobrable-sin-boton.png`).
  - **Anulada (Cliente F):** botón ausente (`puede_registrar_cobro: false` porque
    `anulada_at !== null`), confirmado en el navegador.
- **RN2 (propuesta PENDIENTE, no decidida, sigue mostrando el botón) — caso adversarial explícito
  del encargo, fácil de implementar al revés:** confirmado con Cliente G (incobrable pendiente, sin
  decidir) — el botón **sí** aparece, tanto por API (`puede_registrar_cobro: true`) como en el
  navegador (`scrum524-528-req448-contabilidad-sin-botones.png`, mismo modal usado para el hallazgo de RN4
  de arriba).
- **RN3 (navegación con cliente preseleccionado):** click en "Registrar cobro" sobre Cliente D
  navega a `/admin-contab/cobros?master_client_id=611&accion=nuevo-cobro` — `master_client_id`
  correcto (611 = master_client_id real de Cliente D), la pantalla `CobrosPlaceholderPage` carga sin
  error 404. Captura: `scrum524-528-req451-navegacion-cobros.png`. Confirmado que el placeholder no
  se cae — el formulario real "+ Registrar cobro" con el cliente preseleccionado queda para el
  batch de Cobros (fuera de alcance, ya aclarado por el encargo).

**Sin hallazgos bloqueantes en REQ-451.**

## 6. RBAC negativo (los 5 tickets)

`vendedor_disenador` ([persona] [persona]) → `GET /api/admin-contab/invoices` responde **403** real, la UI
no expone datos de Facturación al navegar directo a la ruta. Sin cambios respecto a batches
anteriores, re-verificado igual para este batch. Captura: `scrum524-528-rbac-negativo.png`.

---

## Hallazgos

### 1. MAYOR (bloqueante) — REQ-448 RN3/Escenario 1: la tabla NUNCA muestra la pastilla de
"Pendiente de aprobación", pese a que el AC de Jira lo pide explícitamente

**AC de SCRUM-525 (Escenario 1):** *"...entonces la factura queda con estado 'pendiente de
aprobación', **visible en la tabla**, pero sigue en la pestaña 'Cartera cobrable'."* RN3 del mismo
ticket: *"...visible en la fila con una nota y una pastilla de estado distinta..."*

**Causa raíz:** `InvoiceService::list()` (`atlanticerp-backend/app/Modules/AdminCont/Services/
InvoiceService.php:111-123`) arma cada fila de la tabla con `es_incobrable` pero **nunca incluye
`incobrable_pendiente`** en el array devuelto — solo `detail()` (el endpoint del modal individual)
expone ese campo. El frontend ya está preparado para esto: `pillFor()`
(`atlanticerp-frontend/src/pages/admin-contab/FacturacionPage.tsx:51-59`) chequea
`entry.incobrable_pendiente` primero y renderiza una pastilla naranja distinta ("Pendiente de
aprobación") — pero como el campo nunca llega en la respuesta de `GET /api/admin-contab/invoices`,
esa rama del código nunca se activa, y la fila siempre cae al `else` final mostrando la pastilla
normal de "Facturada" (`ESTADO_PILL_CLASSES`), indistinguible de cualquier otra factura vigente.

**Reproducción exacta:**
1. Factura cualquiera en estado `facturada`, cobrabilidad normal.
2. `POST /api/admin-contab/invoices/{order}/mark-uncollectible` con un motivo válido (o desde la UI,
   "Marcar como incobrable" → enviar motivo).
3. `GET /api/admin-contab/invoices?tab=cobrable&view=plana` — la fila de esa entrega vuelve con
   `"estado": "facturada"` y **sin** la clave `incobrable_pendiente` en absoluto.
4. En la UI (`/admin-contab/facturacion`, Vista plana), la fila muestra la pastilla verde/teal
   "Facturada" normal — no la pastilla naranja de "Pendiente de aprobación" que sí se ve
   correctamente si se abre el modal de detalle de esa misma fila (`GET .../detail` sí incluye la
   info de cobrabilidad completa).

**Evidencia visual — comparar los dos casos, mismo layout de tabla, mismo ambiente de prueba:**
- `scrum524-528-req448-contabilidad-sin-botones.png` / `scrum524-528-req448-mark-con-botones.png`: fila de
  Cliente G (incobrable **pendiente**) muestra pastilla "Facturada" lisa en la tabla de fondo, pese
  a que el modal (mismo momento) sí refleja correctamente "Propuesta de incobrable pendiente de
  aprobación de Aprobador."
- `scrum524-528-req451-h-incobrable-sin-boton.png`: fila de Cliente H (incobrable **aprobada**, un
  campo que sí viaja en el payload de `list()`) muestra correctamente la pastilla roja "Incobrable"
  — confirma que el mecanismo de pastillas funciona bien en general, el problema es específicamente
  la ausencia del campo `incobrable_pendiente` en la proyección de `list()`.

**Impacto:** Contabilidad/Aprobador/Gerencia no tienen ninguna señal visual en la tabla principal de qué
facturas tienen una propuesta de incobrable esperando decisión — tienen que abrir el modal de
detalle de cada fila una por una para descubrirlo. Rompe el propósito explícito de RN3 (dar
visibilidad de un estado sensible sin tener que entrar a cada entrega).

**Fix sugerido:** agregar `'incobrable_pendiente' => $invoice->incobrable_pendiente ?? false` al
array `$row` de `InvoiceService::list()` (línea ~122, junto a `es_incobrable`) — el frontend ya está
listo para consumirlo sin cambios adicionales.

### 2. MENOR (no bloqueante, riesgo a futuro) — el mismo gap existe para el estado "Anulada" en
la tabla principal

`InvoiceService::list()` calcula `'estado' => $invoice !== null ? 'facturada' : 'pendiente-facturar'`
— nunca revisa `$invoice->anulada_at`, así que una factura anulada seguirá apareciendo como
"Facturada" lisa en la tabla principal (confirmado con Cliente F: `GET .../invoices?tab=cobrable`
devuelve `"estado": "facturada"` para una factura con `anulada_at` seteado). El frontend también
está listo para esto (`EntregaEstado` incluye `'anulada'`, `ESTADO_PILL_CLASSES` tiene un estilo
tachado para ese caso) pero nunca se activa por el mismo motivo que el hallazgo MAYOR de arriba.

**No se clasifica como bloqueante** porque ningún AC de REQ-447/448/449/450/451 exige explícitamente
que la tabla principal muestre "Anulada" (los ACs de REQ-449 hablan de la vista previa/PDF, que sí
funciona correctamente) — y hoy no existe ningún flujo real para llegar a este estado (depende de
Notas de Crédito, todavía no implementado). Se documenta como riesgo real a futuro: el día que
exista el flujo de Notas de Crédito → Anulación completa, la tabla seguirá sin distinguir facturas
anuladas de vigentes salvo abriendo el detalle de cada una — mismo fix aplicaría (agregar el chequeo
de `anulada_at` al cálculo de `estado` en `list()`).

---

## Veredicto

**NO pasa a QA todavía.** El hallazgo MAYOR (REQ-448 RN3, pastilla de "Pendiente de aprobación"
ausente en la tabla) es un incumplimiento directo y verificable de un AC explícito de Jira
(Escenario 1 de SCRUM-525) — corresponde al loop completo: PM reasigna a Backend Dev → agregar
`incobrable_pendiente` a la proyección de `InvoiceService::list()` → Pre-QA vuelve a correr el
checklist COMPLETO de este documento (no solo el punto corregido) → recién ahí pasa a QA (regla
dura del proyecto, `atlanticerp/CLAUDE.md`).

El hallazgo MENOR (mismo gap para "Anulada" en la tabla) puede diferirse a criterio de PM/Luis dado
que ningún AC de este batch lo exige explícitamente todavía y no hay flujo real que lo dispare — pero
se recomienda corregirlo en el mismo fix ya que es la misma causa raíz y el mismo patrón de
proyección incompleta en `list()`.

Evidencia completa (capturas Playwright + PDF real descargado + rasterizado) en
`atlanticerp-frontend/test-results/` (carpeta gitignored, local): `scrum524-528-rbac-negativo.png`,
`scrum524-528-req447-escenario1-pendiente.png`, `scrum524-528-req447-escenario2-completa.png`,
`scrum524-528-req447-rn2-guia-post-factura.png`, `scrum524-528-req449-pago-y-saldo.png`,
`scrum524-528-req449-pdf-d-saldo.pdf` + `-1.png`, `scrum524-528-req449-anulada-modal.png`,
`scrum524-528-req449-pdf-anulada.pdf` + `-watermark-1.png`, `scrum524-528-req448-contabilidad-sin-botones.png`,
`scrum524-528-req448-mark-con-botones.png`, `scrum524-528-req448-mark-rechazo-vuelve-normal.png`,
`scrum524-528-req451-h-incobrable-sin-boton.png`, `scrum524-528-req451-navegacion-cobros.png`,
`scrum524-528-req450-aging-panel.png`. Spec de Playwright:
`atlanticerp-frontend/e2e/preqa-scrum524-528-facturacion-batch4-20260821.spec.ts` (untracked, mismo
patrón que los pre-qa previos de este proyecto — no autosembrado, depende de fixtures por tinker/API
documentados en la sección 0; ningún dato de prueba quedó en la base al cerrar la sesión, verificado
por SQL).

---

## Re-check completo tras hallazgo MAYOR (segunda pasada, mismo día 2026-08-21/22)

**No se repitió solo el punto que falló — se corrió el checklist COMPLETO de los 5 tickets desde
cero**, como pide la regla dura del proyecto (`atlanticerp/CLAUDE.md`, "el loop de Pre-QA no se cierra en
documentado, sigo con el próximo"). Commits evaluados en esta segunda pasada: backend `9d8ae74`+
`52fe7fc`+`625a48e`+`e91c09a`, frontend `3a39ebd`+`137a104` (mismos, sin cambios en frontend en
este fix). Ver el fix real y su docblock en `app/Modules/AdminCont/Services/InvoiceService.php`
línea ~111-133 (`estado` ahora `match(true)` sobre `anulada_at`/`invoice===null`, más
`incobrable_pendiente` agregado al `$row`).

### Gate real (repetido, no asumido del Senior Review)

- Backend: `infra/test.sh` completo → **2457/2457 tests, 9888 assertions, OK**. PHPStan Level 8 →
  `[OK] No errors`.
- Frontend: `npm run test -- --run` completo → **116 archivos, 1357/1357 tests, OK**. `tsc --noEmit`
  → sin errores.

Mismos números que reportó el Senior Reviewer tras su propio re-run — confirmado de forma
independiente, no copiado del documento de review.

### Puntos ya cubiertos a fondo en la corrida anterior (NO se repitieron, ver arriba en este mismo
documento): trazabilidad Cotización→Factura→Guía de Entrega (REQ-447), gate exclusivo de Aprobador con
Contabilidad Y Aprobador reales (REQ-448 RN4), marca de agua "ANULADA" en el PDF real vía `pdftoppm` (REQ-449
RN3), aritmética de antigüedad con factura backdateada (REQ-450), los 4 escenarios de "Registrar
cobro" (REQ-451). Ninguno de estos puntos depende del método `list()` que tocó el fix, y ya estaban
verificados con datos reales — no hay motivo para sospechar que cambiaron.

### 1. La pastilla nueva en la tabla principal (foco explícito del hallazgo MAYOR)

Fixture nuevo sembrado por tinker/API (el ambiente había vuelto a 0 filas al cerrar la corrida
anterior, confirmado por SQL antes de empezar): 3 entregas facturadas —
- **P** (order 372, F-0001): Contabilidad propuso incobrable vía `POST /mark-uncollectible` real → queda
  `incobrable_pendiente: true`, `es_incobrable: false`.
- **N** (order 373, F-0002): factura normal, sin flags — control.
- **A** (order 374, F-0003): `anulada_at` seteado manualmente por SQL (sigue sin existir flujo real,
  depende de Notas de Crédito — mismo criterio que la corrida anterior).
- 1 cuenta bancaria de prueba (`AdminContBankAccount` id 18, "Banco PreQA Recheck ****9911") porque
  el ambiente volvía a estar en 0 cuentas.

Verificado en el navegador real (Playwright, Vista plana, **no el modal**):
- Fila de **P** muestra la pastilla naranja **"Pend. aprobación"** (texto abreviado real
  renderizado, no "Facturada") — captura
  `scrum524-528-recheck2-pastilla-pendiente-tabla.png`. Confirma que el hallazgo MAYOR original
  está resuelto: la tabla ya no requiere abrir el modal de cada fila para ver esta señal.
- Fila de **N** (control) muestra "Facturada" lisa, sin ninguna pastilla especial.
- Fila de **A** muestra una pastilla distinta con el texto "Anulada" (tachada, estilo gris) — el
  hallazgo MENOR (no bloqueante) también quedó resuelto de hecho por el mismo fix, aunque ningún AC
  lo exigía explícitamente. Captura: `scrum524-528-recheck2-pastillas-control-anulada.png`.

Verificado también por API directa (`GET /invoices?tab=cobrable&view=plana`): fila de P trae
`"estado":"facturada"` + `"incobrable_pendiente":true`; fila de A trae `"estado":"anulada"`; fila de
N trae `"estado":"facturada"` + `"incobrable_pendiente":false` — coincide exactamente con lo
renderizado en pantalla.

### 2. El fix no rompió nada del tab/filtro existente

- **Tab "Cartera cobrable" vs "Cartera incobrable" (RN3 REQ-448):** con P todavía pendiente de
  decisión, la fila **sigue** en "Cartera cobrable" y **desaparece** de "Cartera incobrable" al
  cambiar de tab — confirmado en el navegador (test 3 del spec) y por API (`tab=incobrable` no
  devuelve la fila de P). Esto no depende de `estado`, depende de `es_incobrable` (columna
  independiente que el fix no tocó) — comportamiento correcto, sin regresión.
- **Filtro por `estado` (dropdown de UI):** `estado=facturada` sigue trayendo P y N (ambas
  `estado==='facturada'` a nivel de dato, aunque P muestre una pastilla distinta por
  `incobrable_pendiente`) y ahora **excluye correctamente a A** (que antes del fix se hubiera
  contado también como "facturada" — el fix cambia el resultado de este filtro para las facturas
  anuladas, cambio de comportamiento deseado, no una regresión). Verificado en el navegador
  (dropdown real) y por API. Captura: `scrum524-528-recheck2-filtro-estado.png`.
- **Hallazgo menor nuevo, NO bloqueante:** el `<select>` de filtro de estado en
  `FacturacionPage.tsx` (línea ~217-222) solo ofrece las opciones `['pendiente-facturar',
  'facturada']` — nunca se agregó `'anulada'` como `<option>` seleccionable, pese a que `list()` ya
  soporta `estado=anulada` correctamente a nivel de API/dato (confirmado por curl directo: filtrar
  por `estado=anulada` devuelve solo la fila de A). No es una regresión de este fix — es simplemente
  que nadie agregó la opción al dropdown, consistente con el mismo criterio ya aceptado para el
  hallazgo MENOR original (ningún AC de este batch exige un filtro de UI para "anulada" todavía, y
  no hay flujo real que genere facturas anuladas fuera de este fixture manual). Se documenta como
  limpieza cosmética a considerar junto con el resto de la deuda de Notas de Crédito — no bloquea el
  paso a QA.
- **"Mostrando X de Y" (`total_en_tab`):** con 3 entregas en el tab, mostró "Mostrando 1 de 3" al
  buscar por un cliente puntual — el denominador (3) no se ve afectado por ningún filtro adicional,
  igual que antes del fix.

### 3. Bloque de pago del PDF (commit `e91c09a`)

Descargado el PDF real de la factura N vía Playwright (evento `download` interceptado, no mock) y
extraído con `pdftotext -layout` — contiene "Cuenta número", "Banco PreQA Recheck ****9911",
"Responsable" y "Contabilidad" (nombre del `created_by` real). Confirma que el commit `e91c09a` (el edit
de blade que había quedado sin commitear en la corrida anterior) sí está aplicado de punta a punta:
`detail()`/`pdf()` → `pagoBlock()` → `invoice-pdf.blade.php` → PDF real.

### Fixtures — revertidos al cerrar

Todo el fixture de este re-check (3 clientes/subclientes/proyectos/cards/quotes+ítems/orders/
facturas nuevas y 1 cuenta bancaria) fue revertido por tinker al cerrar — confirmado por SQL:
`orders`/`admin_contab.invoices`/`admin_contab.bank_accounts`/`ventas_diseno.master_clients`/
`sub_clients`/`sales_projects`/`quotes`/`pipeline_cards`, todas en 0 filas de nuevo, igual que antes
de empezar este re-check. Spec de Playwright de este re-check:
`atlanticerp-frontend/e2e/preqa-scrum524-528-recheck2-20260821.spec.ts` (untracked, 5 tests, 5/5 verde).

### Veredicto final (segunda pasada)

**PASADA LIMPIA — los 5 tickets (SCRUM-524→528) pasan a QA.** El hallazgo MAYOR original
(pastilla de "Pendiente de aprobación" ausente en la tabla) está corregido y verificado de punta a
punta: PHPUnit (2 tests nuevos que fijan exactamente el campo que faltaba), vitest (pillFor ya
cubierto con los 3 casos `incobrable_pendiente`/`es_incobrable`/`anulada` en
`FacturacionPage.test.tsx`), y ahora también en el navegador real contra datos reales. El hallazgo
MENOR original (estado "Anulada" ausente en la tabla) quedó resuelto de hecho por el mismo fix. Un
hallazgo menor NUEVO y no bloqueante quedó documentado arriba (falta la opción "Anulada" en el
`<select>` de filtro de UI) — no impide el paso a QA, se recomienda incluirlo junto con el resto del
trabajo pendiente de Notas de Crédito. No se encontró ninguna regresión en el resto de los 5
tickets.
