# Pre-QA — SCRUM-777 (Técnicos Internos) y SCRUM-781 (correcciones globales de Tickets)

Visual Review + Pre-QA fusionados, corridos contra **dev.atlanticerp.ai real** vía Playwright CLI
(`playwright.dev-remote.config.ts`) + curl directo a la API. Retoma un pase de Pre-QA anterior de
esta misma sesión que se cortó a mitad de camino por límite de sesión, justo cuando había
detectado (correctamente) que los PDFs de Servicios no mostraban el logo. Este pase repite el
checklist **completo desde cero** — no solo el punto del logo — y además audita el ticket de Jira
completo de SCRUM-781 (9 puntos), no solo el resumen de 7 puntos que traía el brief inicial.

Cuentas reales (password = email): `servicio@atlantic.com.pa` (Aaron Leis, lider_servicios —
`can_view_cost_breakdown=true`, cubre el gate de Gerencia para costo/margen de cotización),
`carlos@atlantic.com.pa` (Tecnico Test, tecnico_servicios).

Spec Playwright: `atlanticerp-frontend/e2e/preqa-scrum777-781-tecnicos-tickets-20260820.spec.ts` (14 tests,
screenshots en `atlanticerp-frontend/e2e/.tmp/preqa-scrum777-781-20260820/`).

---

## Resumen de veredicto

| Ticket | Veredicto | Nota |
|---|---|---|
| **SCRUM-777** (REQ-255/256/257) | **LIMPIO — transicionado a QA** | Los 3 REQ verificados con reproducción real, sin F5 |
| **SCRUM-781** (9 puntos) | **8/9 LIMPIO — 1 pendiente de confirmación de PM/Luis** | Ver detalle abajo. No se transiciona todavía |

---

## SCRUM-777 — Técnicos Internos (LIMPIO)

**REQ-255 (Herramientas asignadas):** se asignó una herramienta libre a Tecnico Test desde
Insumos y Herramientas (select inline "En bodega de herramientas" → "Tecnico Test"). El conteo
`herramientas_asignadas` en su tarjeta de Técnicos Internos pasó de 1 a 2 dentro de la ventana de
polling real de 30s (`refetchInterval: 30_000`, `InternalTechniciansPage.tsx:69`), sin recargar la
página. Confirmado por API y en pantalla real (test 1).

**REQ-256 (Estado automático del técnico):** se reagendó la visita del ticket REC-2026-0007 (id 28)
a 20 minutos en el futuro vía `PATCH /tickets/{id}/agendar` (mismo endpoint que usa el formulario
real de "Reagendar"). La tarjeta de Carlos en Técnicos Internos pasó a mostrar **"En ruta"** sin
F5, dentro de la ventana de polling — confirma que el estado se recalcula en cada request desde
`scheduled_at`/`scheduled_ends_at` (test 2), no que quedó pegado al valor anterior como reportaba
el hallazgo original.

**REQ-257 (Modal "Visitas hoy" clickeable):** con Carlos mostrando 2 visitas hoy (tras la prueba
anterior), se abrió el modal desde su tarjeta y se hizo clic en una visita → navegó directo a
`/servicios/tickets?ticket=28` con el detalle del ticket abierto y el modal cerrado (test 3,
screenshot `06-navego-a-ticket.png`). Comportamiento correcto, sin pasos intermedios.

Sin regresiones. Transicionado a QA (comentario + `jira_transition_issue`), marcadores creados en
`~/.claude-preqa-markers/SCRUM-777` y `~/.claude-visual-review-markers/SCRUM-777`.

---

## SCRUM-781 — Correcciones globales de Tickets (8/9 LIMPIO)

### Punto 1/5/8/9 (REQ-235/278/279) — Logo en PDFs: LIMPIO

Este era el hallazgo real del pase de Pre-QA anterior (cortado a mitad de sesión): dompdf no
renderiza `<svg>` inline en este entorno — página en blanco. Corregido en `dbefecc` (imagen
embebida base64 vía `App\Shared\Support\PdfLogo`). Se pegó directo a la API con bearer token
(mismos bytes que recibe el frontend, ya que el "Ver/Imprimir" real descarga un blob vía axios, no
es navegable en browser) y se renderizó a PNG con `pdftoppm` para inspección visual real:

- **Hoja de Reclamo diligenciada** (ticket 28, REC-2026-0007): logo real, 4 secciones en orden — OK.
- **Hoja de Reclamo en blanco** (ticket 19): `404` — correcto, RN2 exige la hoja Completada antes
  de habilitar el PDF (gate del propio backend, no un bug).
- **Informe de Inspección** (ticket 26, GAR-2026-0008): logo real — OK.
- **Cotización de Servicio** (ticket 22, quote 4, REC-2026-0006): logo real, tabla de ítems,
  totales — OK.

Los 3 documentos válidos: `Content-Type: application/pdf`, tamaños 900KB-1.3MB, dompdf 3.1.5
+ CPDF. Sin texto plano "ATLANTIC" como sustituto del logo.

### Punto 2 (REQ-227) — Motivo de cancelación obligatorio: LIMPIO

Probado en los 3 puntos de entrada por separado, cada uno con un ticket distinto:

1. **Botón "Cancelar ticket"** en el detalle (ticket 16, REC-2026-0004) — el modal
   `TicketCancelModal` trae "Confirmar cancelación" **deshabilitado de fábrica** (no solo
   validado al submit) con el texto "El motivo de cancelación es obligatorio" visible. Con motivo
   escrito, el botón se habilita y cancela — `cancellation_reason` quedó persistido.
2. **Select de estado en la tabla (Lista)** (ticket 24, GAR-2026-0006) — cambiar a "Cancelado"
   abre el mismo modal (no aplica el cambio directo), mismo gate de botón deshabilitado. Confirmado
   con motivo real persistido.
3. **Arrastrar tarjeta a "Cancelado" en el Tablero** (ticket 9, REC-2026-0001, drag real con
   `@hello-pangea/dnd` — targeting por `data-rfd-drag-handle-draggable-id`/`data-rfd-droppable-id`)
   — mismo modal, mismo gate. Confirmado con motivo real persistido.

Los 3 reutilizan el mismo componente (`TicketStatusSelect.tsx`, `TicketBoard.tsx` — ver comentarios
`SCRUM-781 (punto 2, REQ-227 RN6)` en el código), consistente con la regla de negocio de que
ningún camino puede cancelar sin motivo.

### Punto 3 (REQ-232) — Costo editable en ítem Subcontratado: LIMPIO

Ticket 25 (GAR-2026-0007, cotización draft existente). Se agregó un ítem "+ Subcontratado", se
eligió el técnico externo real "QA Luis Vargas" (tarifa_dia real = 25.00) → el campo "Costo de
referencia" se prellenó con **25.00** automáticamente. Se editó a 40 y se guardó — el ítem quedó
con `cost_reference: 40`, `subtotal: 52` (= 40 × 1.3, margen 30% default), **no** con la tarifa
original. Se confirmó por API que la ficha del técnico externo (`GET /external-technicians`) sigue
mostrando `tarifa_dia: 25.00` sin cambios — el override de cotización nunca toca el maestro.

### Punto 4 (REQ-247 RN3) — Nombre en buscador de productos: LIMPIO (sin regresión)

Ya verificado LIMPIO el 2026-08-19 (ver `scrum347-344-361-779-337-780-servicios-rebotes-20260819.md`).
Re-chequeado brevemente en este pase — sigue mostrando nombre real, sin regresión.

### Punto 6 — "Generar cotización"/"Generar informe": LIMPIO

Verificado desde **Lista** y desde **Tablero**: ambos triggers abren su modal correspondiente
(`ServiceQuoteModal`/`InspectionReportModal`) en las 3 combinaciones probadas. Confirma el fix de
`aa95f03` (antes `onOpen` nunca se pasaba desde `TicketTable.tsx`/`TicketBoard.tsx`).

### Punto 7/9 — Ancho de tarjetas del Tablero: LIMPIO tras un fix trivial propio

Sin overflow fuera del borde de ninguna tarjeta (columnas `minmax(260px, 1fr)`, `min-w-0` en los
contenedores flex — commit `aa95f03`). **Hallazgo MEDIO encontrado y corregido en el momento**: con
un badge de tipo/subtipo largo (ej. "Garantía · Garantía general"), el número de ticket y el
nombre del cliente siguen truncando con ellipsis CSS sin ningún fallback — técnicamente no se
"sale" de la tarjeta, pero sí "oculta información" en el sentido literal del criterio del ticket
("No se debe ocultar información para solucionar el problema visual"). Fix propio (commit
`3223b60`, `atlanticerp-frontend`): se agregó `title={ticket.numero}` / `title={ticket.cliente}` (tooltip
nativo) a ambos campos en `TicketBoard.tsx` — disclosure mínimo sin rediseñar el layout ya
retocado por este mismo ticket. `npx tsc --noEmit` limpio, suite completa (`infra/deploy-manual.sh
dev`: 116 archivos, 1329 tests) en verde, desplegado a dev.atlanticerp.ai y re-verificado.

### Punto 1 (REQ-225) — Edición global (productos + adjuntos): CRÍTICO encontrado → corregido en vivo durante este mismo Pre-QA → re-verificado LIMPIO

**Al arrancar este pase**, este punto (uno de los 9 del ticket, parte de "Criterios de aceptación
globales" — "Se pueden agregar/editar/quitar productos reclamados/afectados", "Se pueden agregar
nuevos archivos adjuntos") **no estaba implementado en absoluto**, pese a que el brief inicial de
esta tarea lo daba por hecho junto con el resto de los 7 puntos resumidos. Verificado por código
antes de tocar el navegador (regla de Pre-QA: Jira es la fuente de verdad, no el resumen de otro
agente):

- `UpdateTicketRequest.php` (backend) solo validaba 4 campos (`tipo`, `subtipo`,
  `tipo_instalacion`, `requerimientos_especiales`) — con un docblock que decía explícitamente
  "Productos reclamados/afectados NO está en el alcance de este endpoint".
- `TicketDetailModal.tsx` en modo `edit` no renderizaba ninguna sección de productos ni de
  adjuntos.
- No existía ningún endpoint `DELETE` de adjuntos, ni rutas `POST/PATCH/DELETE` de productos sobre
  un ticket ya creado.

Esto se documentó como hallazgo real (no se autoclasificó como "alcance diferido") y se reportó.
**Durante la misma sesión de Pre-QA**, el gap se implementó y desplegó en tiempo real:

- Backend: commit `3716c8d` — 3 endpoints nuevos bajo el mismo gate de rol que `update()`
  (`superadmin`/`lider_servicios`): `POST/PATCH/DELETE /tickets/{id}/products/...`. Una fila con
  `cantidad_recibida > 0` queda de solo lectura (decisión explícita de Luis, 2026-08-20, para no
  perder trazabilidad de recepción).
- Frontend: commit `a1a76ac` — sección "Productos reclamados/afectados" (picker + edición inline
  de cantidad + quitar) y "Fotos, videos o archivos adjuntos" (subir nuevo, sin opción de eliminar
  los existentes) en modo edición.

Se desplegó a dev.atlanticerp.ai (`infra/deploy-manual.sh dev`, junto con el fix trivial del punto 7/9 —
suite completa 1329 tests + build en verde) y se **re-verificó end-to-end contra el build real**
(ticket 25):

1. Agregar producto (buscador "bombillo" → catálogo real) → `productos` pasó de `[]` a 1 fila.
2. Editar cantidad inline (click-to-edit, 1 → 3) → confirmado por API.
3. Quitar producto → confirmado ausente por API.
4. Subir adjunto nuevo (`.png`, whitelist de extensiones del proyecto — un `.txt` de prueba se
   rechazó silenciosamente, comportamiento esperado) → confirmado en `adjuntos`.
5. Cerrar y reabrir en modo edición → el adjunto recién subido, ahora "existente", **no** ofrece
   ningún botón de eliminar (RN3) — 0 botones `title*="eliminar"` en el DOM del modal.

### Punto 4 (REQ-247, "Mejora") — Filtro de productos por proyecto: **PENDIENTE, no autoclasificado**

Único punto que sigue sin resolver. El ticket, sección 4.2, lo etiqueta explícitamente **"Mejora"**
(a diferencia de las reglas de negocio RN2/RN3 del mismo REQ-247, que sí están cumplidas) — pero
también aparece listado dentro de "Criterios de aceptación globales" sin ninguna nota de que sea
opcional. Verificado en código: `ProductLookupController::index()` (`GET
/servicios/lookup/products`) filtra solo por `is_active`, `exclude` y `search` — no existe ningún
parámetro `project_id`/`sales_project_id`. El picker del frontend tampoco pasa ninguno. El buscador
de "Productos reclamados/afectados" sigue devolviendo el catálogo completo activo,
independientemente de que el ticket ya tenga Cliente Master + Subcliente + Proyecto seleccionados.

Por la regla dura de Pre-QA ("si el hallazgo es una diferencia entre lo que el ticket pide y lo que
existe, no autoclasificar como alcance diferido — notificar a PM/Luis y esperar confirmación antes
de transicionar a QA", agregada tras SCRUM-428), **no se transiciona SCRUM-781 a QA todavía**.
Queda documentado en el comentario de Jira, a la espera de que PM/Luis decida si se implementa
ahora o se difiere explícitamente.

---

## Recomendación

- **SCRUM-777**: cerrado, en QA.
- **SCRUM-781**: 8/9 puntos limpios y re-verificados en vivo (incluyendo un CRÍTICO real que se
  cerró dentro de esta misma sesión). El único punto abierto es una mejora explícitamente
  etiquetada como tal en el propio ticket (REQ-247 filtro por proyecto) — decisión de alcance, no
  un bug de código. Recomendado: que PM/Luis confirme en el comentario de Jira si se implementa
  antes de QA o se difiere formalmente: en cuanto haya esa confirmación, este mismo checklist ya
  está listo para transicionar sin necesidad de repetir el resto de los 8 puntos.
