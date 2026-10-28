# Pre-QA RECHECK — SCRUM-400 (REQ-330, "Guía de Entrega")

**Fecha:** 2026-07-23
**Repos (HEAD verificado, working tree limpio, sin checkout):**
- `atlanticerp-backend` `b5ce631` — "fix(bodega): capture recipient name on signed delivery guide (SCRUM-329)"
- `atlanticerp-frontend` `fcb073d` — "fix(bodega): show recipient name in signed guide, pick latest guia_entrega document"

**Contexto:** mi propia pasada de hoy (2026-07-23) había rebotado el ticket con un hallazgo
CRÍTICO: el área de firma mostraba el nombre del repartidor en vez de quien recibió (ver
`docs/pre-qa/scrum329-batch-a3-pedidos-frontend-2026-07-23.md`). El fix (campo nuevo
`received_by_name` en `order_documents` + toma del documento `guia_entrega` de mayor `id`) ya pasó
Senior Review con veredicto 🟢 Aprobado (`docs/reviews/scrum-400-guia-entrega-fix-2026-07-23.md`).
Esta es la re-corrida COMPLETA del checklist en navegador real, no solo el punto que falló.

**Entorno:** Docker local (`infra-*`, nginx puerto 8090, backend real) + Vite dev server (5173,
`baseURL` de `playwright.config.ts`, mismo proxy `/api → localhost:8090`). Login real vía
`POST /api/auth/login`, navegador real (Playwright, ver nota de infraestructura abajo).

**Nota de infraestructura (nueva, para dejar registrado):** el navegador de `claude-in-chrome`
(controlado vía extensión) no pudo alcanzar `http://localhost:8090` (`ERR_CONNECTION_REFUSED`,
confirmado con `location.href === 'chrome-error://chromewebdata/'`) pese a que `curl` desde este
mismo entorno sí llegaba — indica que ambas herramientas corren en namespaces de red distintos en
esta sesión. Se resolvió corriendo Playwright real (`npx playwright test`, Chromium real, no
headless-simulado) contra el stack, que sí comparte red con el resto del tooling de esta sesión —
sigue siendo "navegador real", no lectura de código. Promovido a
`atlanticerp-frontend/e2e/preqa-scrum400-guia-entrega-recheck.spec.ts` (6/6 verde) — no se borra, es
justamente el tipo de gate que ya se rompió una vez (ver `feedback_e2e_no_desechar`).

**Nota de higiene:** a mitad de sesión el Postgres local compartido se reseteó por completo (mismo
gotcha ya documentado en `feedback_local_test_db_no_es_fresh.md` — otro proceso corriendo contra el
mismo Postgres del stack local) y se llevó los 3 pedidos sembrados en la primera pasada. Se
resembraron con IDs nuevos (125/126/127) y se re-corrió todo de punta a punta; no afectó al
resultado, solo al tiempo de la sesión.

## Paso 0 — permisos

`bodega.read`/`bodega.write` **no** requirieron ningún grant manual: el rol `lider_bodega`
(Liderbodega, `almacen@atlantic.com.pa`) ya los trae nativos vía el rol (confirmado en el JWT:
`modules.bodega = {view:true, view_team:true, edit:true}`, sin `extra_permissions`). Intenté
otorgarlos manualmente por las dudas al principio de la sesión y confirmé después que era
redundante — revertido a `extra_permissions: []` antes de terminar, entorno queda limpio, nada que
agregar a `SpecialPermissionSeeder`.

## Fixtures sembradas (vía `tinker` + HTTP real contra los endpoints reales, no fixtures directas a BD)

| Order | Flujo | Propósito |
|---|---|---|
| 125 (`PED-2026-PREQA-A2`) | Packing → `register-delivery` → **Por despachar**, guía generada sin firmar | Escenario 1 |
| 126 (`PED-2026-PREQA-B2`) | + `assign-courier` ([persona] [persona]) + `dispatch` + `register-signed-guide` con `received_by_name = "Maria Fernandez (Recepcionista)"` (deliberadamente distinto del repartidor) | Escenario 2 |
| 127 (`PED-2026-PREQA-C2`) | Mismo flujo que 126, `received_by_name = "Carlos Receptor Real"`, **+ un 3er documento `guia_entrega` insertado a mano después, sin firmar (id más alto)** | Adversarial — "herir el orden de creación" |

## Veredicto: 🟢 Limpio para SCRUM-400 — 1 hallazgo MEDIO nuevo, no bloqueante, fuera del alcance reachable hoy

Los dos criterios de aceptación del ticket (Escenario 1 y 2) y los dos bugs que motivaron el fix
(nombre de receptor, documento más reciente) verifican correctamente en navegador real, sin
regresión. El único hallazgo nuevo es un gap defensivo que **ningún flujo de negocio actual puede
alcanzar** (confirmado independientemente, ver abajo) — no bloquea este ticket, queda documentado
para hardening futuro.

---

## Escenario 1 — guía sin firmar (Order 125) ✅

Dado un pedido en "Por despachar" con guía generada y nunca firmada, el modal muestra:

> FIRMA: **"Pendiente de firma"**

Texto capturado con `getByTestId('guide-signature').innerText()` (no solo visual). Sin regresión.
Screenshot: `e2e/.tmp/scrum400-recheck-order103-unsigned.png`.

## Escenario 2 — guía firmada, nombres deliberadamente distintos (Order 126) ✅

Repartidor (`assign-courier`) = **[persona] [persona]**. `received_by_name` sembrado = **"Maria Fernandez
(Recepcionista)"** — a propósito un nombre que no se parece en nada al repartidor, para que la
prueba sea significativa (no repetir el error de sembrar el mismo nombre en ambos roles).

> REPARTIDOR: "[persona] [persona]" (campo separado, correcto)
> FIRMA: **"Firmado y recibido por: Maria Fernandez (Recepcionista)"**

El bug original (mostrar el repartidor en la firma) **no reaparece**. Screenshot:
`e2e/.tmp/scrum400-recheck-order104-signed.png`.

## RN1 — nunca precios ✅

Confirmado en las 3 tarjetas: `"Este documento no incluye precios."` visible, `body.innerText()`
sin ningún `$` en ningún order verificado (125/126). Tabla de artículos solo trae Ref./Artículo/
Cantidad, sin columna de precio en ningún caso.

## Punto 3 del pedido — documento más reciente / adversarial "herir el orden de creación" 🟡 MEDIO (no bloqueante)

**Setup:** Order 127 llegó a "Entregado" con `guia_entrega` id 13 firmado
(`received_by_name = "Carlos Receptor Real"`). Después, inserté a mano un **4to... 3er documento
`guia_entrega` nuevo (id 14), sin firmar (`received_by_name = null`)** — simulando qué pasaría si
alguna vez existiera una regeneración de guía DESPUÉS de la firma (hoy no existe ningún flujo que
lo haga, ver más abajo).

**Resultado:** el modal toma el documento de **mayor id** (14, el rogue sin firmar) en vez del
firmado (13):

> Guía # 14
> ESTADO DE LA ENTREGA: Entregado
> FIRMA: **"Firmado y recibido por: —"**

Screenshot (zoom a la sección de firma):
`e2e/.tmp/scrum400-recheck-order127-signature-zoom.png`. El pedido SÍ tiene un `received_by_name`
real guardado (id 13), pero la UI no lo muestra — no repite el bug original (no muestra el nombre
del repartidor), pero sí pierde el dato real y muestra un placeholder de "sin firmar" para un
pedido que en los hechos ya fue firmado y tiene el nombre guardado.

**Por qué NO es bloqueante para este ticket:**
- Confirmé independientemente (no solo confiando en el Senior Review) que hoy solo existen 2 puntos
  de creación de un documento `guia_entrega` en todo el código (`grep -rn "TIPO_GUIA_ENTREGA"` +
  `generateGuiaEntrega\(\)`/`registerSignedGuide\(\)`): `OrderDocumentService::generateGuiaEntrega()`
  (al entrar a Por despachar) y `DeliveryService::registerSignedGuide()` (al firmar). Ambos
  estrictamente secuenciales para el mismo pedido — no hay ningún flujo de reproceso/regeneración
  que pueda insertar un 3er documento del mismo tipo. El escenario que reproduje **no es
  alcanzable hoy por ningún camino real de la aplicación** — lo construí a mano vía `tinker`
  insertando el documento directo a la tabla.
- El bug original que motivó el rebote de hoy (mostrar el nombre del REPARTIDOR) no reaparece —
  el fallback es un placeholder neutro (`—`), no un dato incorrecto/engañoso.

**Recomendación para hardening futuro (no de este ticket, dejar para cuando exista algún flujo de
regeneración real):** en vez de `documents.filter(tipo==='guia_entrega').reduce(max id)`, preferir
el de mayor `id` que además tenga `received_by_name` no nulo cuando `order.stage === 'entregado'`
— así un documento "guia_entrega" nuevo sin firmar nunca puede tapar a uno ya firmado. Dejar
anotado en Jira, no abre ticket nuevo por decisión propia (Luis decide si vale la pena o se queda
como está dado que hoy es inalcanzable).

## Descarga del PDF — Order 126 (firmada) ✅

Capturé la llamada de red real (no solo el click): `handleDownload()` disparó
`GET /api/bodega/orders/126/documents/11/download` — **documento id 11 es el firmado** (confirmado
contra BD: id 10 = sin firmar, id 11 = `received_by_name = "Maria Fernandez (Recepcionista)"`). La
descarga trae el documento correcto tras el fix, no el sin firmar (bug de descarga silencioso que
el Senior Review ya había señalado como corregido de rebote — confirmado en vivo, no solo leído).

## Paso 3 — casos de ruptura estándar

- **Rol sin permiso de Bodega:** primero probé con [persona] (`transporte`) asumiendo que no tendría
  acceso — **resultó ser un supuesto mío incorrecto**, no un bug: el rol `transporte` SÍ trae
  `bodega.view/edit` nativos (confirmado en el JWT, sin `extra_permissions`), tiene sentido de
  negocio (un repartidor necesita ver sus pedidos). Corregí el test con un rol que de verdad no
  tiene ningún permiso de Bodega (`vendedor_disenador`, `[persona].[persona]@atlantic.com.pa` —
  `modules.bodega` todo en `false`): navegar a `/bodega/pedidos` **nunca llama a la API** (el
  router del frontend bloquea antes), termina en el Dashboard genérico ("Selecciona un módulo").
  Verificado también a nivel API directo: `GET /api/bodega/orders` con su JWT → `403`. Gate real,
  no solo un botón escondido.
- **Recargar a mitad del modal abierto:** con Order 126 (firmada) abierto, `page.reload()` — sin
  error boundary, sin excepción de página (`page.on('pageerror')` vacío), el modal simplemente se
  cierra (SPA vuelve al estado inicial de la ruta), comportamiento esperado para un modal sin
  estado persistido en la URL.
- **Doble documento sin firmar + firmado (Order 126, 2 docs):** ya cubierto arriba en Escenario 2 —
  confirma que con exactamente 2 documentos (el caso real que SÍ puede ocurrir hoy) el criterio de
  "más reciente" funciona correctamente.

## Lo que sí funciona (verificado en navegador real, no solo leído en código)

- Escenario 1 (sin firmar) → "Pendiente de firma", sin regresión.
- Escenario 2 (firmada, nombres distintos) → firma muestra el receptor, no el repartidor; el
  repartidor se sigue mostrando correctamente en su propio campo separado.
- RN1 (nunca precios) → confirmado en las 3 tarjetas, sin `$` en ningún lado.
- Selección del documento `guia_entrega` más reciente entre 2 documentos reales (el caso que
  puede ocurrir hoy) → correcto.
- Descarga → baja el documento firmado correcto (id verificado por red, no solo por click).
- Gate de permiso de Bodega → bloqueo real (backend 403 + frontend nunca llama a la API), no solo
  un botón escondido.
- Recarga a mitad de flujo → sin crash, sin error boundary.
- Contrato de campo backend↔frontend (`received_by_name`) → mismo nombre, mismo tipo, sin mismatch
  (ya verificado por Senior Review, reconfirmado en runtime con la respuesta real de
  `GET /api/bodega/orders/{id}`).

## 🟡 MEDIO — ya conocido, no de este ticket, confirmado que no empeoró

`contacto_cliente` sigue siendo un string combinado (no 3 campos separados) — Teléfono/Correo
muestran `"No disponible todavía (pendiente de backend)"` en las 3 tarjetas verificadas. Mismo
hallazgo ya reportado en la pasada anterior (2026-07-23), sin cambios para peor ni para mejor —
sigue fuera del alcance de este fix puntual.

## Resultado de herramientas

- `atlanticerp-frontend/e2e/preqa-scrum400-guia-entrega-recheck.spec.ts` — **6/6 verde** (Playwright,
  Chromium real, contra el stack Docker local + Vite dev server).
- Sin cambios de código — esta es una pasada de re-verificación pura, ningún fix aplicado en esta
  sesión.

## Siguiente paso

Pasada limpia y concluyente para SCRUM-400. Transiciono a `QA` (marker
`~/.claude-preqa-markers/SCRUM-400` creado antes de la transición). El hallazgo MEDIO adversarial
queda anotado en el comentario de Jira para que quede visible, sin bloquear el batch.
