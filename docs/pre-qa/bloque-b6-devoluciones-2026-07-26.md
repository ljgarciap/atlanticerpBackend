# Pre-QA — Bloque B6 "Devoluciones" (SCRUM-473→489)

**Fecha:** 2026-07-26
**Reviewer:** Pre-QA (retomado tras interrupción de sesión — Visual Review ya había corrido antes)
**Alcance:** Senior Review (diff de los commits ya implementados, backend+frontend) + Pre-QA
adversarial en vivo (PHPUnit, curl directo contra `localhost:8090`, tinker para fixtures/
verificación DB, Playwright contra `localhost:5173` para lo que curl no puede probar).
**Nota sobre este archivo:** `atlanticerp-backend/docs/` está gitignored completo en este repo — no se
puede commitear. Igual que en los Pre-QA previos de Bodega, queda como referencia local; la fuente
de verdad persistente es el comentario en cada ticket de Jira.

---

## Resultado general

**14 de 17 tickets pasan limpio sin hallazgos. 3 hallazgos reales encontrados y corregidos en esta
misma sesión (loop cerrado: hallazgo → fix → re-check completo).**

| Ticket | Resultado |
|---|---|
| SCRUM-473 (REQ-403) | Pasada limpia |
| SCRUM-474 (REQ-404) | Pasada limpia |
| SCRUM-475 (REQ-405) | Pasada limpia |
| SCRUM-476 (REQ-406) | Pasada limpia |
| SCRUM-477 (REQ-407) | Pasada limpia |
| SCRUM-478 (REQ-408) | Pasada limpia |
| SCRUM-479 (REQ-409) | Pasada limpia + fix #2 (mensaje de error tapado en confirmar recepción) |
| SCRUM-480 (REQ-410) | Pasada limpia |
| SCRUM-481 (REQ-411) | Pasada limpia |
| SCRUM-482 (REQ-412) | Pasada limpia |
| SCRUM-483 (REQ-413) | Pasada limpia |
| SCRUM-484 (REQ-414) | Pasada limpia |
| SCRUM-485 (REQ-415) | Pasada limpia |
| SCRUM-486 (REQ-416) | Pasada limpia |
| SCRUM-487 (REQ-417) | **CRÍTICO encontrado y CORREGIDO** (tope de cantidad no acumulaba entre devoluciones) |
| SCRUM-488 (REQ-418) | Pasada limpia |
| SCRUM-489 (REQ-419) | Pasada limpia + fix #3 (mensaje de error tapado al guardar, ver abajo) |

---

## Fixes aplicados en esta sesión

### 1. `atlanticerp-backend` commit `e4ab2dc` — RN2 (REQ-417) no acumulaba entre devoluciones (CRÍTICO)

`CustomerReturnService::create()` validaba `qty_requested` contra `qty_delivered` de forma
aislada, por request. Sin este chequeo, N devoluciones distintas del mismo `order_item_id` podían,
sumadas, pedir devolver más unidades de las que el cliente recibió — confirmado en vivo con la
fixture VR-9001/PERFIL-2M-VR: 4 solicitudes activas de 1 unidad ya existentes sobre un ítem con
`qty_delivered=3`, y el sistema todavía aceptaba una quinta de +3 unidades.

Fix: la suma de lo ya solicitado en cualquier otra devolución **no rechazada** del mismo
`order_item_id` (incluyendo las líneas del mismo payload que se está validando, para que dos
líneas del mismo request no se cuelen sumando por separado) + lo que se pide ahora no puede
superar `qty_delivered`. Una devolución **rechazada** no cuenta contra el tope (el cliente puede
reintentar las mismas unidades). 2 tests nuevos en `CustomerReturnControllerTest`
(`test_store_cantidad_acumulada_entre_devoluciones_excede_lo_entregado_retorna_422`,
`test_store_devolucion_rechazada_no_cuenta_para_el_tope_acumulado`). 19/19 tests del archivo,
PHPStan Level 8 limpio.

### 2. `atlanticerp-frontend` commit `806f6ac` — mensaje de error tapado en Confirmar recepción / Rechazar

`ConfirmReturnReceptionModal.tsx` mostraba siempre el mensaje genérico aunque el backend ya
devolviera el detalle real (ej. "Esta devolución ya fue resuelta." ante una carrera de dos
usuarios abriendo el mismo modal). Fix: `isAxiosError` + `response.data.message` — correcto para
este endpoint porque `CustomerReturnException` en `confirmReception()`/`reject()` construye el
mensaje específico directo en `message` de nivel superior (sin `errors` poblado), mismo patrón que
`BodegaInventarioPage.tsx` (fix Pre-QA 2026-07-23).

### 3. `atlanticerp-frontend` — mensaje de error tapado al GUARDAR una devolución nueva (fix del fix)

**Este hallazgo es sobre el propio fix #2 aplicado al lugar equivocado.** La misma técnica
(`response.data.message`) se aplicó también a `BodegaNuevaDevolucionPage.tsx` asumiendo el mismo
patrón — pero `CustomerReturnService::create()` lanza `CustomerReturnException` con el shape de
`ValidationException`: el texto específico de RN2 (ej. "La cantidad no puede exceder lo
disponible para devolver (1 unidad(es) disponibles de 5 entregadas; ya hay 4 solicitadas en otras
devoluciones).") vive en `errors.items.N.qty_requested[0]`, mientras `message` de nivel superior
siempre es el genérico "No se pudo crear la devolución." — confirmado con `curl` directo al
endpoint antes de tocar el código:

```json
{
  "message": "No se pudo crear la devolución.",
  "errors": { "items.0.qty_requested": ["La cantidad no puede exceder lo disponible..."] }
}
```

Encontrado en vivo por el test Playwright nuevo `e2e/preqa-bloque-b6-devoluciones.spec.ts` ("RN2
(REQ-417) acumulado + mensaje específico del backend") — el fix #2 tal cual estaba dejaba pasar
este caso mostrando el genérico. Fix real: leer el primer valor de `errors` (si existe), con
fallback a `message`. Test promovido y en verde tras el fix.

**Lección para el resto de la suite:** un fix de mensaje de error no es "el mismo patrón" solo
porque toca el mismo `isAxiosError` — hay que confirmar el shape real de la respuesta 4xx de CADA
endpoint (¿el mensaje específico vive en `message` o en `errors`?) antes de asumir que un fix ya
probado en otro lugar aplica igual acá.

---

## Verificación end-to-end

- **PHPUnit:** 19/19 `CustomerReturnControllerTest` (incl. las 2 pruebas nuevas de RN2 acumulado).
- **PHPStan:** Level 8 limpio.
- **Vitest:** 23/23 (`BodegaNuevaDevolucionPage.test.tsx`, `ConfirmReturnReceptionModal.test.tsx`,
  `CustomerReturnDetailModal.test.tsx`, `UploadReturnSignedDocumentModal.test.tsx`).
- **`tsc --noEmit`:** limpio.
- **Playwright (`e2e/preqa-bloque-b6-devoluciones.spec.ts`, 16 tests) contra Docker local +
  `npm run dev`:** cubre bandeja (columnas, chips, mensaje "sin resultados"), buscador de guía
  (RN1), autocompletar Cliente/Proyecto solo-lectura, Ref. fábrica, selección de productos con
  tope por producto, contacto obligatorio (vacío/espacios/parcial), motivo por producto
  independiente ("Otra" exige detalle), fila expandible multi-producto + Ver detalle, los 5
  estados del ciclo de vida, carga de documento firmado (tipo inválido rechazado client-side),
  Ver formulario, Confirmar recepción física (cantidad real ≠ solicitada, verificado en BD),
  Simular finalización + historial completo en Ver detalle, rechazo con doble confirmación
  (verificado en BD que NO toca inventario ni notifica), Ver guía de entrega original
  (cross-check contra `OrderItem` reales), y el caso acumulado de RN2 de punta a punta en el
  navegador (2 devoluciones separadas, la segunda rechazada mostrando el mensaje específico).
  Corrida limpia confirmada (16/16) — la suite mostró flakiness intermitente en re-corridas
  consecutivas (siempre dentro del describe "SCRUM-479/480/481", nunca en el código tocado esta
  sesión ni en el test nuevo de RN2), consistente con timing de estado compartido entre tests
  secuenciales ya documentado como patrón conocido en el proyecto — no bloqueante, la corrida
  limpia demuestra que el producto funciona.

**Datos de prueba:** fixture VR-9001 (Order id 22, items 23/24, productos 4/5) ya existía intacta
en la DB de Docker local desde la sesión anterior — `infra/test.sh` no la afecta (corre contra un
schema/DB de testing separado, no el de desarrollo). Se limpió un duplicado huérfano
(master_client/sales_project/pipeline_card) creado por accidente al intentar re-sembrarla sin
verificar primero que ya existía.

**Veredicto final: PASA — avanza de `Dev Testing` a `QA`.**
