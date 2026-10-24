# Pre-QA — SCRUM-929: Facturar por entrega parcial o por pedido completo

2026-09-20. Ticket en Jira: `To Do` (desactualizado — código completo, revisado dos veces por
Senior Reviewers independientes en `docs/reviews/scrum929-*.md`, pusheado a `dev` en ambos repos,
desplegado a mano en `dev.atlanticerp.ai` el mismo día). Fuente de verdad de criterios: los 8 AC de Jira
(pegados abajo). Diseño técnico: `docs/adr/ADR-SCRUM929-facturacion-parcial-o-completa.md`.

Stack local Docker (no `dev.atlanticerp.ai`), Playwright CLI contra `http://localhost:5173` (frontend)
y `http://localhost:8090` (API vía nginx). Suite completa de backend relevante corrida localmente
antes de empezar: `infra/test.sh --filter='AdminContPartialInvoicingTest|AdminContInvoiceFamilyRootTest|AdminContInvoiceControllerTest|AdminContPartialInvoicingConsumersTest|InvoiceConcurrencyTest'`
→ 145 tests / 1086 assertions, verde. PHPStan Level 8 → `[OK] No errors`.

## Criterios de aceptación (Jira, texto literal)

1. Un proyecto con entregas parciales permite generar una factura independiente por cada entrega
   que sale de Bodega.
2. Cada factura parcial generada incluye únicamente los artículos y cantidades correspondientes a
   esa entrega específica.
3. La suma de los montos de todas las facturas parciales de un proyecto es igual al valor total de
   la cotización de ese proyecto.
4. Existe la opción "Facturar pedido completo" para un proyecto con entregas pendientes, que genera
   una única factura por el total del pedido.
5. El usuario puede elegir libremente entre facturar cada entrega o usar "Facturar pedido completo".
6. Al generar la factura de "Pedido completo", las opciones de facturación parcial dejan de estar
   disponibles.
7. No es posible generar ninguna factura adicional sobre un proyecto ya facturado en su totalidad.
8. El comportamiento de Fecha de entrega Única permanece sin cambios.

## Camino feliz (ya cubierto por el spec permanente de B5, re-corrido hoy)

`e2e/preqa-scrum929-b5-facturacion-por-entrega-20260919.spec.ts` (autosembrado, permanente,
construido por el propio batch B5) — re-corrido hoy, **verde**: entrega 1 facturada por su cuenta
(321.00, solo sus 3 unidades, no las 9 del pedido — AC1/AC2), después "Facturar pedido completo"
cierra el resto (642.00) sin duplicar ni perder saldo (AC3/AC4), y al cerrar desaparecen ambos
botones de facturación para el pedido (AC6/AC7). AC5 (elegir libremente) y AC8 (entrega única sin
cambios) cubiertos por la suite de backend re-corrida hoy (`AdminContInvoiceControllerTest`, 37
tests/189 assertions, es la suite ORIGINAL pre-SCRUM-929 reescrita como `test_929_*` — sigue verde
sin cambios de comportamiento para pedidos de una sola entrega).

## Foco especial (dinero real) — pasada adversarial de hoy

### 1. Doble clic humano en "Guardar factura" (parcial)

Dos eventos `click()` sincrónicos sobre el mismo botón "Guardar factura", en el mismo tick de JS
(bypaseando las esperas de actionability de Playwright para simular el peor caso: un clic humano
más rápido que el re-render de React desactivando el botón). Verificado contra la BD real, no solo
contra la UI: **como mucho 1 factura parcial activa** para la entrega. Limpio.

### 2. Dos pestañas del navegador facturando la MISMA entrega en simultáneo

Mismo `BrowserContext` (misma sesión, como 2 pestañas reales), ambas apuntando deterministamente a
la fila "Entrega 1 de 3", disparadas con `Promise.all` sin esperar a que la primera termine. Una
pestaña recibe `201` con la factura; la otra recibe `422` limpio (`"Esta entrega ya tiene una
factura generada."`) — nunca las dos éxito. Verificado contra BD: exactamente 1 factura activa
para toda la familia. Esto complementa (no reemplaza) el test de concurrencia real de dos procesos
PHP separados que ya corrió Backend Dev (`tests/Feature/AdminCont/Concurrency/InvoiceConcurrencyTest.php`)
— acá el mismo guard se ejerce desde el navegador real, camino de UI.

### 3. Orden inverso: "Facturar pedido completo" PRIMERO, antes de cualquier parcial

El batch B5 solo probó el orden "entrega primero, completo después". Se probó el inverso: pedido
de 3 entregas, se hace clic en "Facturar pedido completo" de entrada (sin facturar ninguna entrega
antes). Tras confirmar: **0 botones de facturación** quedan para el pedido (ni "por entrega" ni
"pedido completo" de nuevo) — AC6/AC7 sostienen en ambos órdenes, no solo el que B5 ejercitó.
Verificación de backend directa: un intento de `InvoiceService::confirm(..., 'entrega')` sobre una
de las entregas restantes se rechaza con excepción de dominio (el rechazo es real, no solo la UI
ocultando el botón — ver ADR §3).

### 4. Recargar la página a mitad del flujo (preview abierto, sin confirmar)

Preview abierto (modal visible, `POST /invoices/preview` ya resuelto), recarga del navegador ANTES
de tocar "Guardar factura". El modal no sobrevive la recarga (estado en memoria, no persistido) y
las 3 entregas siguen `pendiente-facturar` — ninguna factura fantasma, ningún estado roto.

### 5. Usuario sin permiso de facturación (rol `designer`)

Login como `designer@atlantic.test` (rol sin `superadmin`/`lider_admin_contab`/
`asistente_administrativa`/`management`): la pantalla de Facturación no renderiza su título (gate
de ruta/menú por rol). Verificación de backend directa (no solo UI): `GET
/api/admin-contab/invoices` con el token real de ese usuario → **403**. El bloqueo es real en el
backend, no solo un botón escondido.

### 6. Corrección de ITBMS de una parcial + cierre del pedido (decisión de negocio #2 de Luis)

Escenario exacto pedido: 3 entregas de 100×3 c/u al 7% (321.00 cada una, total 963.00). Se ejecutó
contra la app real corriendo (servicios reales del contenedor `laravel`, no lectura de código):
factura entrega 1 (321.00) y entrega 2 (321.00) como parciales; se corrige el ITBMS de la entrega 2
a 3.5% vía `CreditNoteService::registerCorreccion()` (mismo código que ejecuta el endpoint
`POST /notas-credito/correccion`, el monto de la corrección — 310.50 — cae bajo el umbral de
aprobación de Mark así que se ejecuta de inmediato, igual que lo haría un usuario real desde la UI
de Notas de Crédito sin necesitar aprobación); se factura la entrega 3, que **cierra el pedido**.

Resultado: la entrega 3 cobra exactamente **321.00**, no 331.50 — la corrección de ITBMS ya NO se
recobra en silencio (el bug que el ADR describe como "Premisa que no se sostuvo" en B3a, resuelto
por la decisión de Luis #2 del 2026-09-19, mecanismo `ajuste_itbms_delta`). Suma total del pedido:
952.50 (963.00 − 10.50 del ajuste), exacta. **Confirmado, no un hallazgo — funciona como se pidió.**

Nota de alcance: la UI completa de "Corrección de datos" vive en la pantalla de Notas de Crédito
(SCRUM-932, ticket separado en `To Do`, fuera de mi alcance evaluar como pantalla) — se verificó el
mecanismo de negocio de SCRUM-929 (`ajuste_itbms_delta`) a través del mismo Service que expone esa
pantalla, no se evaluó la UI de Notas de Crédito en sí.

## CRÍTICO
Ninguno.

## MEDIO
Ninguno.

## Lo que sí funciona
- Factura independiente por entrega, con SOLO los artículos/cantidades de esa entrega (AC1/AC2).
- Suma de parciales == total de la cotización, con redondeo correcto incluso con 3+ entregas (AC3).
- "Facturar pedido completo" disponible y funcional en cualquier momento con saldo pendiente (AC4).
- Coexistencia libre de ambos modos, en cualquier orden — entrega-primero (B5) y completo-primero
  (hoy) (AC5).
- Bloqueo real y permanente tras "pedido completo", verificado en backend, no solo en UI (AC6/AC7).
- Entrega única sin cambios de comportamiento (AC8, suite original re-corrida verde).
- Guard anti-duplicado real contra doble clic humano y contra 2 pestañas en carrera sobre la misma
  entrega — un intento se acepta, el otro se rechaza limpio, nunca los dos.
- Orden inverso (completo antes que cualquier parcial) también bloquea correctamente todo lo
  posterior.
- Recarga a mitad de flujo no deja estado roto ni factura fantasma.
- Rol sin permiso bloqueado en backend (403), no solo oculto en la UI.
- Corrección de ITBMS de una parcial ya no recobra en silencio al cerrar el pedido — cobra
  exactamente lo esperado (321.00, verificado con el escenario numérico exacto del ticket).
- Retención 3.5% ya paramétrica (`admin_contab_itbms_rates`, CRUD real) — nada hardcodeado
  encontrado en este batch.

## Artefactos de esta pasada
- `atlanticerp-frontend/e2e/preqa-scrum929-adversarial-20260920.spec.ts` — spec permanente y
  autosembrado (mismo patrón que B5), promovido porque ejercita un gate de dinero/duplicado real
  (regla del agente: un gate de este tipo no se descarta tras usarlo una vez). 5 casos, todos
  verdes.
- `e2e/preqa-scrum929-b5-facturacion-por-entrega-20260919.spec.ts` re-corrido, verde (no tocado).
- Sin fixes de código — ningún hallazgo bloqueante que corregir.

## Veredicto
**Pasada limpia y concluyente.** Sin hallazgos CRÍTICOS ni MEDIOS. SCRUM-929 pasa a `QA`.
