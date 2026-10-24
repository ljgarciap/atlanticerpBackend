# Pre-QA — SCRUM-932 "MEJORA UAT: Notas Crédito y Devoluciones"

Pre-QA, 2026-09-20. Sin mockup adjunto en Jira — Visual Review no aplica (confirmado). Diseño:
`docs/adr/ADR-SCRUM932-notas-credito-correcciones.md`. Senior Review: aprobado sin hallazgos nuevos
(`docs/reviews/scrum932-senior-review-20260920.md`).

## Gate automatizado (repetido antes de la pasada en vivo)

- Backend: `infra/test.sh --filter="CreditNote"` → 44/44 verdes (incluye el test nuevo
  `test_932_invoices_for_client_y_detalle_de_nota_incluyen_proyecto_cotizacion_y_guia`, que ya cubre
  el caso de una factura **ya pagada** siguiendo apareciendo en `invoicesForClient()`).
- Frontend: `npx vitest run` sobre los 4 archivos tocados (`RegistrarNotaCreditoModal.test.tsx`,
  `DetalleNotaCreditoModal.test.tsx`, `DocumentoNotaCreditoModal.test.tsx`,
  `NotasCreditoPage.test.tsx`) → 61/61 verdes.

## Datos sembrados para la pasada adversarial

No hay seeder/factory de `admin_contab` para este escenario — sembrado vía `php artisan tinker`
directo sobre los modelos reales (`Order`, `OrderDocument`, `AdminContInvoice`,
`AdminContCreditNote`), mismo criterio que usan los propios tests de `AdminContPartialInvoicingConsumersTest`.
Master Client 46 ("PreQA932 Cliente Master"), 4 facturas:

| Factura | Proyecto/Cotización | Guía | Estado |
|---|---|---|---|
| FAC-932-NORTE (45) | Proyecto Norte / COT-2026-932N | En su propio pedido (45) | **Pagada por completo** (saldo=0) |
| FAC-932-SUR (46) | Proyecto Sur / COT-2026-932S | — | Pendiente |
| FAC-932-FAMILIA (47) | Proyecto Familia / COT-2026-932F | En OTRO pedido de la familia (48, no en el 47 de la factura) | Pendiente — pedido de 3 entregas |
| FAC-932-SUELTA (48, order 50) | Sin proyecto/cotización (pedido sin `pipeline_card`) | Sin guía | Pendiente |

3 notas de crédito reales: NC-932-FAMILIA (30), NC-932-SUELTA (31), NC-932-NORTE (32).

## Verificación directa por API (antes de UI, para aislar backend de frontend)

```
GET /notas-credito/30 → quote_id=9 (COT-2026-932F), guia_entrega_order_id=48 ≠ factura_origen_order_id=47
GET /notas-credito/31 → quote_id=null, cotizacion_folio=null, guia_entrega_numero=null, guia_entrega_order_id=null
GET /notas-credito/32 → guia_entrega_order_id=45 = factura_origen_order_id=45 (factura sin familia, mismo pedido)
GET /notas-credito/clientes/46/facturas → las 4 filas con sales_project_id/proyecto/quote_id/cotizacion_folio
  correctos; FAC-932-NORTE (saldo_pendiente=0.0) SIGUE en la lista.
```

Esto confirma el punto más sensible del ticket (dinero/datos): `GuiaEntregaResolver::resolve()`
reusado tal cual en `CreditNoteService::show()` encuentra correctamente la guía en un pedido
DISTINTO al `order_id` propio de una factura `completo` (familia de 3 entregas) — mismo patrón ya
puesto a prueba en SCRUM-930/931, sin regresión.

## Pasada en vivo (Playwright CLI, `atlanticerp-frontend/e2e/preqa-scrum932-notas-credito-20260920.spec.ts`, 10/10 verdes)

Promovido a test permanente (criterio ya roto una vez en la clase, SCRUM-931) — queda en
`atlanticerp-frontend/e2e/` para re-correr en cualquier regresión futura del mismo patrón.

| # | Escenario de ruptura intentado | Resultado |
|---|---|---|
| 1 | Modal de creación: Proyecto/# Cotización aparecen tras Cliente Master, acotados a las facturas del cliente | OK |
| 2 | Elegir Cotización (COT-2026-932F) autocompleta el Proyecto (Familia) y acota "Factura de origen" a solo esa factura | OK |
| 3 | Elegir Proyecto (Norte) filtra Cotización, y la factura **ya pagada** sigue apareciendo en "Factura de origen" | OK — confirmado que `invoicesForClient()` no excluye facturas pagadas, a diferencia de Cobros |
| 4 | Elegir factura (Norte), luego cambiar el filtro de forma que la oculte — la selección se limpia | OK — el `useEffect` agregado por Frontend Dev (sin que Senior Review se lo pidiera) funciona en vivo, mismo bug de clase que SCRUM-931 no se repitió |
| 5 | Doble clic / cambios rápidos Proyecto→Cotización→Proyecto→Cotización→Proyecto | OK — sin crash ni estado corrupto. Ver nota abajo (no bloqueante) |
| 6 | Detalle NC-932-FAMILIA: Cotización y Guía aparecen ANTES de "Factura de origen", ambas clickeables | OK |
| 7 | Detalle NC-932-FAMILIA: clic en Guía abre el pedido CORRECTO (48, distinto del 47 de la factura) | OK — sin error, mismo caso SCRUM-930/931 |
| 8 | Detalle NC-932-SUELTA (factura sin proyecto/cotización/guía): Cotización y Guía muestran "—" texto plano, sin botón roto | OK |
| 9 | Detalle NC-932-NORTE: "Factura de origen" sigue disparando descarga directa del PDF (no un modal) | OK — `download` real, `factura-FAC-932-NORTE.pdf` |
| 10 | Cero emoji en el modal de creación y el detalle | OK |

### Nota no bloqueante (test #5)

Secuencia: Proyecto=Familia → Cotización=932F → Proyecto=Todos → Cotización=932N → Proyecto=Todos.
Al final, el `<select>` de Proyecto muestra "Todos los proyectos" pero la Cotización elegida
(932N) sigue acotando qué proyectos/facturas aparecen — `selectProject(null)` nunca limpia
`selectedQuoteId` (mismo criterio ya usado en `RegistrarCobroModal.tsx`/SCRUM-931: solo "elegir
Cotización" auto-completa Proyecto, "Proyecto=Todos" nunca toca Cotización). No es un bug: no deja
estado inconsistente ni una factura inválida seleccionada, y el AC del ticket no pide que
resetear Proyecto limpie la Cotización. Queda documentado por transparencia, no bloquea.

### Devolución precargada (`esDevolucion`) — no re-testeado en vivo

El ticket es explícito en que este modo no se toca (la factura ya viene resuelta desde afuera, sin
pasar por el selector nuevo). Confirmado por lectura de código (`!esDevolucion &&` sigue envolviendo
el bloque nuevo y el bloque de "Factura de origen" existente, sin cambios) y por la suite de
`RegistrarNotaCreditoModal.test.tsx` (35/35 verdes, incluye casos de modo Devolución ya existentes).
Dado el costo de armar un flujo real de devolución confirmada por Bodega solo para este chequeo, y
sin ningún cambio de código en ese branch, se aceptó la cobertura de código+unit tests en vez de un
E2E vivo nuevo — decisión de criterio de Pre-QA, no un hallazgo.

## Lo que sí funciona (más allá de lo tabulado arriba)

- Filtrado cruzado Proyecto↔Cotización bidireccional, sin request nueva (todo local sobre
  `facturasCliente` ya cargada).
- `CreditNoteService::invoicesForClient()` sigue siendo una query independiente de
  `PaymentService::openInvoicesForClient()` — no se colapsó con Cobros (confirmado en el diff).
- `factura_origen_order_id`/comportamiento de "Factura de origen" en el detalle sin cambios.
- Sin emoji en ningún elemento nuevo (regla dura del cliente).

## Veredicto

**Pasada limpia.** Sin hallazgos CRÍTICOS ni MEDIOS bloqueantes. Transiciono SCRUM-932 a `QA`.
