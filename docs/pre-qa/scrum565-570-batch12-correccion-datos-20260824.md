# Visual Review + Pre-QA (fusionado) — Admin&Cont, Notas Crédito Batch 12 (SCRUM-565→570, REQ-488→493)

**Fecha:** 2026-08-24
**Entorno:** contra `dev.atlanticerp.ai` real (backend `8bf111c`, frontend `a3bdc66`, el commit ya
desplegado al arrancar esta sesión), con datos sembrados en vivo vía API "front door" (POST reales
a `/ventas-diseno/quotes`, `/bodega/orders/*`, `/admin-contab/invoices`, `/bodega/returns`) —
ninguna escritura directa a BD/SSH.
**Resultado: 2 hallazgos CRÍTICOS reales encontrados y corregidos (commit local `c1aa4e0` en
`atlanticerp-backend`, sin pushear) — pendiente de push/deploy y de una última re-verificación en vivo
antes de pasar a QA. Todo lo demás (los 6 tickets, RN por RN) verificado limpio.**

Spec de referencia: 6 tickets de Jira (SCRUM-565→570) + mockup adjunto
(`4D__Admin_Contabilidad_Notas_Credito.html`) + Senior Review previo
(`docs/reviews/scrum565-570-batch12-correccion-datos-20260824.md`, que ya dejó documentado 1
hallazgo no bloqueante conocido — ver más abajo, no se re-reporta).

Suite Playwright: `atlanticerp-frontend/e2e/preqa-scrum565-570-batch12-20260824.spec.ts` (commit local
`2a2e43f`, sin pushear) — 12 tests, corridos contra `dev.atlanticerp.ai` real con datos sembrados en vivo.

---

## Paso 0 — estado de los datos en dev.atlanticerp.ai al arrancar

Ningún dato real en `dev.atlanticerp.ai` permitía ejercitar "Corrección de datos" (SCRUM-566/567) de
punta a punta al empezar esta sesión:

- Las 11 entregas "pendiente-facturar" visibles en Facturación eran huérfanas de cotización real
  (`preview` devolvía `missing: ["N° de Cotización", "Monto", "Cliente"]` para las 11).
- Las 2 únicas facturas ya emitidas (`F-PREQA-COBROS-1`/`-2`, de un batch de Cobros anterior) tenían
  `order_id` sintético (900001) sin `Order`/`Quote` real detrás — `previewCorreccion()` fallaba con
  `404 Order 900001 not found` (comportamiento CORRECTO del backend, no un bug — confirma que la
  guarda contra datos huérfanos funciona).

Sembrado en vivo, vía API real (mismos endpoints que usa la UI, ningún atajo de BD):

1. Cliente `[PREQA-B12] Subcliente Correccion` (master_client_id 7040, sub-cliente 7561).
2. Cotización COT-2026-0089 → Pedido PED-2026-0019 → Factura **F-0001** ($6,587.31, **>$5,000**).
3. Cotización COT-2026-0090 → Pedido PED-2026-0020 → Factura **F-0002** ($3,293.65, **≤$5,000**).
4. Devolución **DEV-2026-0003** confirmada por Bodega sobre el Pedido PED-2026-0019 (2 unidades),
   llevada a `esperando_nota_credito` (para SCRUM-568, que tampoco tenía ninguna devolución
   pendiente real al arrancar — `devoluciones_por_generar: 0`).

Con esto se pudo ejercitar en vivo TODO lo que el batch necesitaba: >$5,000 y ≤$5,000 para
Corrección de datos, y la cola de devoluciones de Bodega.

---

## Hallazgo CRÍTICO #1 — "Tratamiento correcto" nunca excluye la tasa ya aplicada (RN1 REQ-488)

`CreditNoteService::invoicesForClient()` (usado por el selector de "Factura de origen" del
formulario manual) nunca devolvía `itbms_rate_id` — pese a que el tipo del frontend
(`NotaCreditoFacturaOrigen.itbms_rate_id`, `types/adminContab.ts`) documentaba EXACTAMENTE este
requisito con una nota explícita: *"Verificar que el endpoint `GET .../clientes/{masterClient}/facturas`
lo devuelva"*. Nunca se verificó — no lo devolvía.

**Efecto real, confirmado en pantalla:** al elegir "Corrección de datos" sobre una factura con
ITBMS 7% (la factura de origen no venía de la cola de Bodega, sino elegida a mano):
- "Tratamiento de ITBMS actual" siempre mostraba `—` en vez del 7% real.
- El selector "Tratamiento correcto" ofrecía las 3 tasas SIN excluir la que ya estaba aplicada — un
  usuario podía "corregir" el ITBMS eligiendo la MISMA tasa que ya tenía la factura, violando RN1
  REQ-488 ("nunca la misma que ya está aplicada").

![Antes del fix — "Tratamiento actual" en blanco, "Tratamiento correcto" ofrece 7% (la misma tasa
ya aplicada) como primera opción.](../../../atlanticerp-frontend/e2e/.tmp/preqa-b12-566-debug-antes-revisar.png)

**Fix aplicado** (mismo día, `CreditNoteService.php`): agregar `'itbms_rate_id' => $i->itbms_rate_id`
al mapeo de `invoicesForClient()`. Una línea, campo ya cargado en el modelo (`$i->itbms_rate_id`,
columna real), sin tocar frontend (el tipo y el código que lo consume ya estaban listos, solo
faltaba el dato del backend).

## Hallazgo CRÍTICO #2 — la revisión previa muestra "true" y "[object Object]" en pantalla (RN1 REQ-489)

`previewCorreccion()` mandaba dos campos de las tarjetas sin formatear:
- `guia_entrega.existe` — boolean crudo (`true`/`false`).
- `correccion_aplicada.nuevo_tratamiento_itbms` — objeto crudo `{id, nombre, porcentaje}`.

El frontend (`CardBlock` en `RevisionPreviaCorreccionModal.tsx`) renderiza cada valor con
`String(v)` — literalmente **"true"** y **"[object Object]"** en la tarjeta "Corrección aplicada"
de la Revisión previa, en vez de un texto legible. RN1 REQ-489 exige que la revisión muestre
claramente qué se está corrigiendo ANTES de confirmar — un valor roto en pantalla incumple esa
intención, aunque ningún criterio Given/When/Then lo mencione palabra por palabra (regla del
mandato: no autoclasificar como "no es un hallazgo" solo porque no está en el texto literal del
criterio).

**Fix aplicado** (mismo `CreditNoteService.php`): formatear ambos campos como strings legibles
antes de mandarlos (`'Sí'`/`'No'` para `existe`, `"{$rate->nombre} ({$rate->porcentaje}%)"` para el
tratamiento nuevo) — mismo criterio ya usado en `AccountStatementExportService::rows()`
(`'Sí'`/`'No'` hardcodeado, sin locale, precedente real ya en el módulo). También se tradujeron
`motivo_correccion` (vía un mapa de labels nuevo, `MOTIVO_CORRECCION_LABELS`) y `tipo`/`subtipo` de
`nota_a_generar` (siempre los mismos 2 valores fijos en este flujo, hardcodeados directo). Se
evaluó hacer el fix en el frontend (reformatear en `RevisionPreviaCorreccionModal.tsx`), pero el
propio docblock del tipo (`PreviewCorreccionTarjetas`) documenta que estas tarjetas son "texto
libre server-side... el frontend no arma ninguno de estos bloques, solo los muestra" — se respetó
esa decisión de arquitectura ya tomada, el fix quedó en el backend.

**Confirmado visualmente después del fix** (revisión previa de F-0001, corrección de ITBMS 7%→0%):

![Después del fix — tarjetas con texto legible, sin booleans ni objetos crudos.](../../../atlanticerp-frontend/e2e/.tmp/preqa-b12-566-revision-tarjetas.png)

### Gate de los 2 fixes

- PHPStan (`app/`, scope completo del módulo AdminCont y del resto de `app/`) → `[OK] No errors`.
- `infra/test.sh --filter=AdminContCreditNoteControllerTest` → **22/22 OK** (2 tests nuevos:
  `test_facturas_del_cliente_incluye_itbms_rate_id_para_excluirlo_en_correccion_de_datos`,
  `test_preview_correccion_tarjetas_no_manda_booleans_ni_objetos_crudos`).
  `infra/test.sh --filter=AdminCont` (suite completa del módulo) → **166/166 OK**, sin regresiones.
- Frontend: sin cambios de código (el fix quedó 100% en backend) — no aplica gate de frontend.

### Estado de despliegue — IMPORTANTE

Ambos fixes están **commiteados localmente, sin pushear** (`atlanticerp-backend` commit `c1aa4e0`,
`atlanticerp-frontend` commit `2a2e43f` — este último solo agrega el spec de Playwright, sin cambios de
código de producción). Por mandato de esta sesión, el push/deploy los hace el Arquitecto después de
revisar el diff — **no se transicionan los 6 tickets a QA todavía**: `dev.atlanticerp.ai` sigue corriendo
el código con ambos bugs hasta que se despliegue. Una vez desplegado, falta una última
re-verificación en vivo (repetir el flujo de Corrección de datos con ITBMS y confirmar que ya no
aparece "—"/"[object Object]") antes de dar el batch por cerrado.

---

## Checklist de verificación por ticket (RN por RN)

### SCRUM-565 (REQ-488) — Validaciones de registro

| RN / Escenario | Resultado |
|---|---|
| RN1 — cliente/monto/motivo siempre obligatorios | OK (código + UI: sin factura de origen el bloque de Monto/Motivo ni se renderiza) |
| RN2 — factura de origen obligatoria en Descuento comercial/Anulación completa (salvo Corrección) | OK — `factura_origen_id` siempre `required` en `RegisterCreditNoteRequest`; Escenario 1 (Descuento comercial sin factura) confirmado: botón "Registrar nota" queda deshabilitado, campo Monto no llega a mostrarse |
| RN3 — cuenta bancaria obligatoria si excedente se resuelve "devolver dinero" | OK — Escenario 2 confirmado en vivo: F-0003 ($3,293.65 saldo real), monto $3,400 → excedente $106.35, "devolver dinero" sin cuenta → botón deshabilitado |
| RN4 — si motivo de corrección incluye fecha, la nueva fecha debe existir | OK — `nueva_fecha` `requiredIf` en `PreviewCreditNoteCorreccionRequest`; en UI la fecha siempre es la de hoy, no editable (mismo criterio que Facturación) |
| RN5 — comprobante obligatorio según REQ-487 | OK, ya implementado en Batch 11, sin regresión — confirmado `comprobanteObligatorio` intacto |
| RN6 — si falta algo, bloquea sin guardar nada parcial | OK — en ningún test se observó un registro parcial; el botón se mantiene deshabilitado hasta `canSubmit`/`correccionListaParaRevisar` completos |

### SCRUM-566 (REQ-489) — Revisión previa + vista previa de factura nueva

| RN / Escenario | Resultado |
|---|---|
| RN1 — revisión obligatoria, nada se guarda hasta "Confirmar y generar factura nueva" | OK — "Revisar y continuar" abre `RevisionPreviaCorreccionModal` (`previewCorreccion`, GET/POST de solo lectura), cerrar sin confirmar no crea nada |
| RN2 — vista previa con el MISMO formato visual que cualquier factura | OK — reusa `FiscalItemsTable`/`FiscalTotalsBlock` (extraídos de `FacturacionPage.tsx`), confirmado el mismo componente, no uno propio |
| RN3 — mismos productos/cantidades, solo cambia ITBMS o fecha | OK, confirmado en vivo vía API: factura original y preview de F-0001 muestran EXACTAMENTE "6 × ENDLESS 30 30 LED WW", solo el ITBMS cambia (7%→0%, total $6,587.31→$6,156.36) |
| RN4 — "Volver y corregir" regresa sin perder lo ya llenado | OK, confirmado: el formulario queda montado-oculto (no desmontado) mientras la revisión está abierta; el motivo de texto libre se conserva tal cual tras "Volver y corregir" |
| **CRÍTICO — "Tratamiento actual"/"Tratamiento correcto"** | **Corregido, ver Hallazgo #1** |
| **CRÍTICO — tarjetas con "true"/"[object Object]"** | **Corregido, ver Hallazgo #2** |

### SCRUM-567 (REQ-490) — Aprobación diferida >$5,000

| RN / Escenario | Resultado |
|---|---|
| RN1 — corrección >$5,000 → `pendiente_aprobacion`, sin factura nueva | OK, confirmado en vivo: NC-0001 sobre F-0001 ($6,156.36 tras corrección) → `estado: pendiente_aprobacion`, `factura_nueva_numero: null` |
| RN2 — mensaje explícito: sin documento fiscal, original sigue activa | OK — banner ámbar visible en la Revisión previa ("Esta corrección supera el umbral... NO se genera ningún documento fiscal todavía"); confirmado por API que F-0001 sigue en la lista de facturas activas del cliente con su saldo completo |
| RN3/RN4 — aprobar/rechazar real (Mark) | Fuera de alcance (Batch 13, REQ-494) — botones presentes, deshabilitados, con hint "próximo batch" — comportamiento ESPERADO, no es un hallazgo |
| RN5 — ≤$5,000 → todo de inmediato | OK, confirmado en vivo: NC-0002 sobre F-0002 ($3,293.65) → `estado: aplicada` de inmediato, generó F-0003, F-0002 quedó anulada (fuera de la lista de facturas activas del cliente) |

### SCRUM-568 (REQ-491) — Cola de devoluciones confirmadas por Bodega

| RN / Escenario | Resultado |
|---|---|
| RN1 — fila ámbar, estado "Pendiente de generar nota" | OK, confirmado visualmente: `background-color: rgba(255, 251, 235, 0.7)` (bg-amber-50/70) en la fila real de DEV-2026-0003 |
| RN2 — clic abre el formulario precargado | OK, confirmado: cliente bloqueado, tipo "Devolución de mercancía" solo lectura, referencia DEV-2026-0003, producto "2 × ENDLESS 30 30 LED WW", `persona_devuelve`/`conformidad` con el placeholder i18n correcto ("No disponible — Bodega no registra este dato", nunca inventado) |
| RN3 — tras generar la nota, la fila deja de aparecer | OK, confirmado end-to-end: se completó el monto (gap conocido, ver abajo), se registró NC-0003, la fila ámbar desapareció del historial en la siguiente carga |
| GAP YA CONOCIDO — monto sugerido arranca en $0.00 | Reconfirmado presente (Senior Review 2026-08-24, ticket SCRUM-786 ya abierto) — **no se re-reporta como hallazgo nuevo**, tal como indica el mandato |

### SCRUM-569 (REQ-492) — Historial, filtros y búsqueda

| RN / Escenario | Resultado |
|---|---|
| RN1 — búsqueda insensible a mayúsculas, parcial en cliente/factura/número de nota/tipo/registrado por | OK — el número de nota (NC-000x) no es una columna visible (mismo criterio que el mockup, que tampoco la muestra), pero SÍ es buscable: `search=NC-0001` filtra correctamente aunque la columna no exista |
| RN2 — filtro de estado con los 4 estados | OK, los 4 presentes: Aplicada / Pendiente de aprobación / Pendiente de generar nota / Rechazada |
| RN3 — todos los filtros combinan (AND) | OK, confirmado: tipo "Anulación completa" + estado "Pendiente de aprobación" → solo F-0001 (NC-0001), F-0002 excluida |
| RN4 — "Limpiar filtros" resetea todo | OK, confirmado |

### SCRUM-570 (REQ-493) — Modal de detalle

| RN / Escenario | Resultado |
|---|---|
| RN1 — "Motivo del rechazo"/"Aprobado o rechazado por" solo cuando aplican | OK — en NC-0001 (`pendiente_aprobacion`) ninguno de los 2 aparece; el backend ya los devuelve `null` según el estado real (`estado !== ESTADO_PENDIENTE_APROBACION`/`estado === ESTADO_RECHAZADA`), nunca vacíos por default |
| RN2 — trazabilidad de Bodega SOLO en Devolución de mercancía | OK en ambos sentidos: ausente en NC-0001/NC-0002 (Corrección de datos), presente y completa en NC-0003 (Devolución) — historial completo con 5 pasos (creada → firma → recepción → notificación → finalizado) |
| RN3 — "Ver comprobante"/"Ver documento" (Batch 13) coherentes si aparecen | El componente actual NO los muestra en absoluto (ni habilitados ni deshabilitados) — "ausentes" es una de las 2 formas aceptadas explícitamente por la RN, no rotos |
| RN4 — Aprobar/Rechazar visibles y deshabilitados en `pendiente_aprobacion` | OK, confirmado con Felix — ambos botones presentes, `disabled`, con `title` "próximo batch". No se pudo repetir la misma verificación logueado como Mark (`mbekhar@illuminations.com.pa` ya no tiene el password default en `dev.atlanticerp.ai`, sin acceso para resetearlo en esta sesión) — el componente es el mismo para cualquier rol con acceso (`puede_aprobar_rechazar` 100% server-side), gap de credenciales documentado, no un hallazgo funcional |

---

## Visual Review — comparación contra el mockup

- **Entrada al módulo:** el mockup usa subtabs superiores + dropdown ("Movimientos ▾ → Notas
  crédito y devoluciones"); `dev.atlanticerp.ai` real usa un sidebar izquierdo con el ítem "Notas crédito
  y devoluciones" directo, sin dropdown intermedio. **Variante de layout aceptable** — misma
  funcionalidad, mismo conjunto de opciones de navegación, confirmado alcanzable con 1 clic desde
  Admin&Cont.
- **Tabla de historial:** mismas columnas conceptuales que el mockup (Fecha/Cliente/Tipo/
  Factura/Monto/Estado + Registrado por, que el mockup no tiene pero es información real y útil).
  Sin columna de número de nota — igual que el mockup.
- **Filas ámbar de la cola de Bodega:** implementadas con el mismo criterio visual que el mockup
  (fondo ámbar, sin acción de "ver detalle", van directo a precarga).
- **Revisión previa + vista previa de factura:** estructura de tarjetas equivalente al mockup
  (Factura a anular / Proyecto / Cotización / Guía de Entrega / Nota a generar / Corrección
  aplicada / Motivo), con el defecto de formato ya corregido (Hallazgo #2).
- **Modal de detalle:** grid de 2-3 columnas con los mismos campos que el mockup, trazabilidad de
  Bodega con el mismo formato de línea de tiempo.

Nada de lo pedido por el mockup está ausente — solo el defecto de formato ya corregido.

---

## Hallazgo NO bloqueante nuevo — fechas "date-only" se muestran un día atrás en timezone negativo

`formatDate()` (duplicado en `HistorialNotasCreditoPanel.tsx` y `DetalleNotaCreditoModal.tsx`) hace
`new Date(iso).toLocaleDateString(...)` directo sobre un string `fecha` que el backend manda
"date-only" (`"2026-08-24"`, sin hora). `new Date("2026-08-24")` se interpreta como medianoche UTC
— en cualquier timezone con offset negativo (ej. **America/Panama, UTC-5**, el target real del
cliente) esto renderiza como el día ANTERIOR. Confirmado en vivo: una nota registrada "hoy 24 ago"
se mostró como "23 ago 2026" tanto en la fila del historial como en el header del modal de detalle
(la trazabilidad de Bodega, que sí trae timestamps completos con hora+offset, mostró la fecha
correcta).

**No es un bug nuevo de este batch** — el mismo patrón (`toLocaleDateString` sobre un string
date-only) ya existe en `HistorialCobrosPanel.tsx`/`DetalleCobroModal.tsx` (Batch 5/6, ya en
producción) — Batch 12 solo copió un patrón ya presente en el módulo. Por eso no se bloquea este
batch por esto, pero es un hallazgo real, reproducible, con impacto en el mercado real (Panamá es
UTC-5, sin horario de verano — el offset nunca cambia). Recomiendo abrir un ticket aparte para
adoptar un `parseDateOnly()` compartido (parsear como fecha local, no UTC) en todo Admin&Cont, no
arreglarlo pieza por pieza en cada batch nuevo.

---

## Archivos tocados en este Pre-QA

- `atlanticerp-backend/app/Modules/AdminCont/Services/CreditNoteService.php` (fix, commit local `c1aa4e0`)
- `atlanticerp-backend/tests/Feature/AdminCont/AdminContCreditNoteControllerTest.php` (2 tests nuevos, mismo commit)
- `atlanticerp-frontend/e2e/preqa-scrum565-570-batch12-20260824.spec.ts` (spec nuevo, commit local `2a2e43f`)

## Veredicto

**Los 6 tickets NO se transicionan a QA todavía.** Los 2 hallazgos críticos ya están corregidos y
verificados por gate (PHPStan + 166/166 AdminCont), pero el fix vive solo en commits locales sin
pushear — `dev.atlanticerp.ai` sigue con el código viejo. Falta: (1) que el Arquitecto revise el diff y
pushee/despliegue, (2) una última re-verificación en vivo del flujo de Corrección de datos con
ITBMS (confirmar que ya no aparece "—"/"[object Object]" y que "Tratamiento correcto" excluye la
tasa actual), y recién ahí crear los marcadores de gate y transicionar a `QA`. El resto del batch
(SCRUM-565, 568, 569, 570 completos, y el resto de SCRUM-566/567) está limpio y no necesita
volver a tocarse.
