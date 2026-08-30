# Senior Review — Admin&Cont, Notas Crédito Batch 12 (SCRUM-565→570, REQ-488→493)

**Fecha:** 2026-08-24
**Resultado: APROBADO tras reconciliar el contrato Backend↔Frontend, que había quedado
inconsistente al cortarse la sesión anterior — 1 defecto real bloqueante (build de frontend roto)
encontrado y corregido en este mismo review, gate completo re-corrido después.**

Batch 12 de Notas Crédito y Devoluciones (ver `docs/architecture/admin-contab-batches.md`) —
submit real de "Corrección de datos" (revisión previa + vista previa de factura nueva + aprobación
diferida >$5,000, REQ-488→490), puente Bodega→Admin&Cont (cierre real de la cola de devoluciones
confirmadas, REQ-491), historial combinado con filtros (REQ-492) y modal de detalle (REQ-493). Ver
`docs/adr/ADR-SCRUM565-570-batch12-correccion-datos-bodega-bridge.md` para el diseño completo y el
addendum de Backend Dev (desvíos reales del diseño original, todos confirmados en código).

## Estado al retomar la sesión

Backend Dev había dejado la implementación completa y su propio gate corrido (PHPStan limpio,
`infra/test.sh` 2550/2550) — confirmado en el addendum del ADR, con aprobación de Arquitecto sobre
el cambio de invariante de schema (`admin_contab.invoices.order_id` de único a único parcial).
Frontend Dev había construido los 4 componentes nuevos (`RevisionPreviaCorreccionModal`,
`HistorialNotasCreditoPanel`, `DetalleNotaCreditoModal`, extensión de `RegistrarNotaCreditoModal`)
pero la sesión se cortó **antes de reconciliar `NotasCreditoPage.tsx` y 2 archivos de test contra
el contrato ya corregido en `types/adminContab.ts`** — `npx tsc --noEmit` fallaba con 6 errores
reales (campos inventados en fixtures de test que no existen en el tipo real: `meta`,
`devolucion_precarga`, `cuenta_bancaria_salida`, `confirmado_por`; más 2 usos de
`devolucion_precarga` en código de producción, `NotasCreditoPage.tsx`). Nada de esto estaba
commiteado en ninguno de los 2 repos.

## Defecto real encontrado y corregido en este review

**`NotasCreditoPage.tsx` asumía un contrato de precarga que el backend nunca implementó.** El
diseño original (borrador del ADR) asumía que `NotaCreditoHistorialRow` traería un campo
`devolucion_precarga` embebido con todo lo necesario para precargar el formulario al hacer clic en
una fila de la cola de Bodega. El addendum de Backend Dev ya documentaba el desvío real: existe un
endpoint aparte, `GET /notas-credito/devoluciones/{customerReturnId}`
(`NotaCreditoDevolucionDetail`), más angosto — sin `persona_devuelve`/`proyecto`/`conformidad` ni
precio unitario por producto (`CustomerReturnLine` no los trackea). El tipo ya se había corregido
para reflejar esto, pero `NotasCreditoPage.tsx` seguía con la función síncrona
`rowToDevolucionPrecargada()` leyendo un campo que no existe — en producción esto hubiera hecho que
**clic en cualquier fila de la cola de Bodega no abriera nunca el formulario** (retorno `null`
silencioso), sin ningún error visible al usuario.

Corregido: `openDesdeDevolucion()` ahora dispara `useNotaCreditoDevolucionDetail()` (mutation ya
existente, sin usar) y combina el resultado con `useNotaCreditoFacturas(masterClientId)` (mismo
endpoint que ya usa el modo manual) para completar `monto`/`saldo_pendiente`/`% ITBMS` de la
factura de origen. `persona_devuelve`/`conformidad` — datos que el backend genuinamente no tiene —
se muestran con un placeholder explícito i18n (`precargaDatoNoDisponible`), nunca inventados.
Precio unitario por producto tampoco existe en el backend — el monto sugerido arranca en $0 y el
campo sigue siendo editable (ver "Hallazgo no bloqueante" abajo). Si el detalle falla, o si la
factura resuelta no aparece en la lista del cliente (inconsistencia real Bodega↔Admin&Cont, no un
timing normal), se muestra un error en vez de dejar la cola esperando en silencio.

## Hallazgo no bloqueante — para Pre-QA/Visual Review

Al precargar desde la cola de Bodega, el campo "Monto" del formulario arranca en **$0.00** en vez
de sugerir la suma de los productos devueltos (que sí ocurría en el diseño original, porque asumía
`monto_unitario` disponible). El usuario debe completarlo a mano siempre en este flujo. Es
consistente con el dato real disponible (no se inventa un precio), pero es una regresión de UX
frente al diseño original — vale la pena que Visual Review lo compare contra el mockup y que Luis
decida si amerita que Bodega empiece a trackear el precio unitario de la línea devuelta en un
ticket aparte.

## Gate real (suite completa)

- **Backend:** PHPStan `--memory-limit=1G` → `[OK] No errors`. `infra/test.sh` completo →
  **2550/2550 tests, 10315 assertions, OK** (re-corrido en este review, sin cambios de código
  backend — ya estaba limpio).
- **Frontend:** `npx tsc --noEmit` → sin errores (0 antes de este review: 6). `npm run test --
  --run` completo → **129 archivos, 1483/1483 tests, OK** (incluye los 2 test files reconciliados
  contra el contrato real — `NotasCreditoPage.test.tsx` con un caso nuevo de error de precarga,
  `DetalleNotaCreditoModal.test.tsx` con fixtures corregidos).

## Arquitectura — verificado en código (más allá de lo ya confirmado por Backend Dev en el ADR)

- `NotaCreditoDetalle` (frontend) coincide campo a campo con lo que `DetalleNotaCreditoModal.tsx`
  consume — el componente en sí nunca tuvo el desajuste, solo su archivo de test tenía fixtures
  viejos (`cuenta_bancaria_salida`, `aprobado_rechazado_por/at`) de un borrador de contrato anterior
  a la reconciliación de Backend Dev.
- `bodega_trazabilidad` real es `{ return_number, historial: Step[] }` (payload reusado de
  `CustomerReturnService::historyPayload()`), no `{ confirmado_por, referencia }` como asumía el
  test viejo — el componente ya renderizaba el shape correcto, solo el test estaba desalineado.
- `NotaCreditoHistorialResult` sin paginación (`{ data: [...] }`, sin `meta`) — consistente con el
  resto de listados de AdminCont (`InvoiceService::list()` tampoco pagina), ya documentado en el
  docblock del tipo desde antes de este review.

## Veredicto y siguiente paso

**Pasada limpia tras reconciliar el contrato de precarga (defecto real bloqueante) + 1 hallazgo no
bloqueante documentado para Visual Review.** Los 6 tickets (SCRUM-565→570) pasan a Pre-QA + Visual
Review (fusionado, mismo criterio que batches anteriores) — **pendiente, no corrido en esta
sesión** (requiere stack local con Playwright real contra los 6 flujos, incluida la cola de
devoluciones de Bodega con datos sembrados). Commits pendientes de push a `dev` al cierre de este
review.
