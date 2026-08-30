# Pre-QA — SCRUM-781, punto 4.2 (REQ-247, "Mejora") — Filtro de productos por proyecto

Cierra el único punto que quedó pendiente del pase de Visual Review + Pre-QA fusionado del mismo
día (ver `docs/pre-qa/scrum777-781-tecnicos-tickets-20260820.md`, sección "Punto 4 (REQ-247,
'Mejora')"): los otros 8 puntos del ticket ya estaban LIMPIOS y re-verificados. Recién implementado
y desplegado a dev.atlanticerp.ai (script manual, GitHub Actions caído por facturación — no relevante
para este pase).

Commits verificados en el entorno: backend `92edd50`, frontend `0c411aa` (`git log -1 HEAD` en
ambos repos, `origin/dev` == local antes de arrancar). Decisión de diseño confirmada con Luis
2026-08-20: cadena vía Bodega (`sales_projects → pipeline_cards → orders(entregado) → order_items
→ catalog_products`), no vía Cotización — el texto del ticket dice explícitamente "evita
seleccionar productos... que no hayan sido entregados al cliente".

## Por qué se armó un fixture nuevo

No existía en dev.atlanticerp.ai ningún dato real con la cadena completa `sales_project → pipeline_card
→ order(stage=entregado) → order_item → catalog_product` — verificado antes de tocar el navegador:
solo 3 `orders` en estado `entregado` en todo el tenant, ninguno con `pipeline_card_id` asignado.
Se sembró vía `tinker` (mismo patrón que `BodegaOrderTestHelpers::makeOrderWithPipelineCard()`,
reusado 1:1 sobre datos reales):

- Cliente Master **"PreQA SCRUM781 Filtro"** → Subcliente **"PreQA SCRUM781 Subcliente"** →
  Proyecto **"PreQA SCRUM781 Proyecto"** (id 166).
- 1 Pedido (`order` id 24) en `stage=entregado`, con 2 ítems: producto real **ICG-62272**
  (LAMPARA CANASTA/TROZOS DE SAL DE HIMALA, id 5201) e **ICG-62270** (LAMPARA DE SAL DEL HIMALAYA,
  id 5199).
- Controles negativos (mismo texto de búsqueda cada uno, catálogo real, NO entregados a este
  proyecto): **ICG-62271** (id 5200) e **ICG-62269** (id 5198).

**Todo el fixture se limpió al terminar** (tickets de prueba 30-33, order 24 + items, pipeline
card 212 + contacto, sales_project 166, sub_client 39, master_client 38) — confirmado por API que
`GET /servicios/lookup/master-clients?search=PreQA` devuelve `[]` y que los tickets de prueba dan
404. No queda ningún residuo visible para usuarios reales (Aaron u otros) en buscadores, tablas o
los contadores de "Tickets abiertos" del dashboard.

## Qué se verificó

### 1. Backend — API real, con y sin filtro

```
GET /servicios/lookup/products?search=lampara+canasta          → 2 resultados (ICG-62272, ICG-62271)
GET /servicios/lookup/products?sales_project_id=166             → 1 resultado (ICG-62272)
GET /servicios/lookup/products?sales_project_id=99999 (inexistente) → 0 resultados
```

Confirmado contra datos reales de dev.atlanticerp.ai (11752 productos en catálogo sin filtro), no contra
fixtures aisladas.

### 2. PHPUnit — `TicketProductProjectFilterTest` (backend `92edd50`)

`infra/test.sh --filter=TicketProductProjectFilterTest` → **4/4 verde**. Cubre: sin
`sales_project_id` (catálogo completo), con filtro (solo entregado a ese proyecto), pedido no
entregado (excluido), producto entregado a OTRO proyecto (excluido).

### 3. Playwright real contra `https://dev.atlanticerp.ai` — `e2e/preqa-scrum781-filtro-producto-proyecto-20260820.spec.ts` (4/4 verde)

1. **Crear ticket**: elegir Cliente Master → Subcliente → Proyecto (fixture) y abrir el buscador
   de "Productos reclamados/afectados" → devuelve únicamente ICG-62272 (el entregado), no
   ICG-62271. Se seleccionó el producto y se guardó el ticket real (confirma que el flujo completo
   de creación funciona con el filtro activo, no solo la búsqueda).
2. **Editar ticket recién creado** (ya con `sales_project_id=166` persistido): el picker en modo
   edición, que lee `ticket.sales_project_id` (expuesto por `TicketService::detail()` en
   `92edd50`), aplica el mismo filtro — búsqueda distinta ("sal del himalaya") para no confundir
   "excluido por ya agregado" (RN3, ICG-62272 ya estaba en el ticket) con "excluido por filtro de
   proyecto roto": devuelve solo ICG-62270 (el segundo producto entregado a este proyecto), no
   ICG-62269 (control negativo).
3. **Ticket viejo real sin proyecto** (id 1, INS-2026-0001, `sales_project_id=null` — de antes de
   Batch 3 parte 2, cuando el campo todavía no era obligatorio): el picker en modo edición sigue
   funcionando sin romperse ni quedar vacío — devuelve el catálogo completo (2 resultados para
   "lampara canasta"), exactamente como antes de este cambio.
4. **Regresión — ítem Producto de Cotización de Servicio**: ticket real 25 (GAR-2026-0007,
   `sales_project_id=44`, un proyecto totalmente distinto del fixture) — se abrió su cotización
   borrador, se agregó un ítem tipo Producto y se buscó "lampara canasta". Si el filtro se hubiera
   colado por accidente a este picker, el resultado sería 0 (el proyecto 44 nunca tuvo ese producto
   entregado). Devolvió los 2 resultados esperados — catálogo completo, sin scope de proyecto.
   Confirma por código además que `ServiceQuoteModal.tsx`/`InsumoCreateModal.tsx` llaman
   `serviciosApi.lookup.products()` sin el 3er argumento `salesProjectId`.

### 4. Frontend — vitest

`TicketCreateModal.test.tsx` (9 tests) + `TicketDetailModal.test.tsx` (22 tests) → **31/31 verde**
en una corrida limpia. Una corrida previa tuvo 1 falla por timeout
("agrega un producto vía el buscador y llama a addProduct()") que **no reprodujo aislada ni en la
siguiente corrida completa** — mismo patrón de flaky ya documentado en memoria del proyecto
(`feedback_ci_flaky_quotepage_tests.md`), no una regresión real de este cambio.

## Intentos de ruptura (Paso 3 del protocolo)

- Proyecto sin ningún pedido entregado → 0 resultados, no error ni catálogo completo por accidente
  (verificado por API con `sales_project_id=99999`).
- Producto ya agregado al ticket + filtro de proyecto activos a la vez → el `exclude` (RN3,
  no-duplicados) y el filtro de proyecto se combinan correctamente, no se pisan entre sí (visto en
  el test 2: ICG-62272 seguía sin aparecer por ya estar agregado, pero ICG-62270 sí, por proyecto).
- Ticket sin proyecto en absoluto → sin crash, catálogo completo (test 3).
- Mismo endpoint desde un flujo completamente distinto (Cotización) con un ticket de OTRO
  proyecto real → sin contaminación cruzada (test 4).

## Veredicto

**PASA A QA.** Punto único pendiente de SCRUM-781 (4.2, REQ-247) verificado limpio en vivo,
end-to-end, contra dev.atlanticerp.ai real, sin regresión en el flujo de Cotización que comparte el
mismo endpoint. Con esto, **los 9 puntos de SCRUM-781 están limpios** (los otros 8 ya se habían
verificado el mismo día, ver `docs/pre-qa/scrum777-781-tecnicos-tickets-20260820.md`).

Marcadores de gate creados: `~/.claude-preqa-markers/SCRUM-781`,
`~/.claude-visual-review-markers/SCRUM-781`. Transicionado a `QA` en Jira con comentario resumen
de los 9 puntos.

Spec Playwright permanente: `atlanticerp-frontend/e2e/preqa-scrum781-filtro-producto-proyecto-20260820.spec.ts`
(commit `e965039`, pusheado a `dev`).
