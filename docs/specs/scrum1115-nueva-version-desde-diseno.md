# SCRUM-1115 — Proyecto restaurado desde Perdido: partir de la cotización existente

**Ticket:** SCRUM-1115 (reporta marly.rangel, prioridad High, épica SCRUM-731). **Estado:** spec pendiente de aprobación de Luis (2026-10-07).
**Decisiones ya tomadas por Luis:** D1 alcance = solo "nueva versión" (sin atajo para volver a Cotización sin documento nuevo);
D2 comportamiento = "Crear cotización" PREGUNTA siempre cuando el proyecto ya tiene una cotización confirmada.

## 1. Defecto (reproducido 2026-10-07 con un test descartable, sin commitear)

Paso a paso del ticket: cotización confirmada (tarjeta en `quote`) → Perdido → Lead → Diseño.

| Intento | Respuesta real |
|---|---|
| `PATCH /pipeline/{id}/stage` a `quote` desde Diseño | 422 "La transición a Cotización solo ocurre generando una cotización real desde Crear cotización" |
| `POST /quotes/{id}/duplicate` con la tarjeta en Diseño | 422 "Solo se puede crear una versión nueva mientras el proyecto está en etapa Cotización" |

**Causa raíz — dos reglas que se cierran entre sí:**
1. RN5 (SCRUM-677/680, `PipelineCard::validationErrorForTransitionTo`): de Diseño a Cotización solo se pasa confirmando una cotización real
   (`QuoteService::advanceCardToQuoteStage`). Se mantiene, no se toca.
2. `QuoteService::duplicate()` ("Usar como base para nueva versión", RN2.3) exige `card.stage === 'quote'`, y la UI esconde el botón fuera de esa etapa
   (`QuoteViewerModal`). Un proyecto que vuelve de Perdido queda en Lead/Diseño: la única salida es "Crear cotización" en blanco.

Marcar como Perdido NO modifica las cotizaciones: "quedó como perdida" es solo la etapa de la tarjeta.

## 2. Diseño aprobado en alcance (Arquitecto)

**Backend**
- `QuoteService::duplicate()` se permite con la tarjeta en `lead`, `design` o `quote` (cotización origen confirmada, como hoy). Sigue bloqueado en
  `lost` (mensaje: reactivar primero), `proposal` y `approved` (congelada). Mismo criterio que ya usa `resolveLinkedCardForConfirm`.
- Al confirmar la versión nueva, la tarjeta avanza a Cotización por el flujo existente (`confirm()` → `advanceCardToQuoteStage`), sin cambios.
- La tarjeta (`GET /pipeline/{id}`) expone `latest_quote_folio` junto a `latest_quote_id` (ya es la última confirmada) para el diálogo.
- `GET /quotes/{id}/versions` ya devuelve `card_stage`; no cambia.

**Frontend**
- `PipelineCardModal`: en Lead/Diseño, si `latest_quote_id != null`, "Crear cotización" abre un diálogo con 2 opciones:
  "Partir de COT-xxxx (nueva versión)" → `POST duplicate` y navega al borrador precargado; "En blanco" → comportamiento actual
  (validaciones de superficie/archivo/cliente incluidas). Sin cotización confirmada, nada cambia.
- Las validaciones del gate de "Crear cotización" (superficie, archivo de diseño, Cliente Master/Subcliente) aplican igual a la opción "Partir de…".
- `QuoteViewerModal`: "Usar como base" visible con `card_stage` en `lead`, `design` o `quote` (hoy solo `quote`), en la tabla y en el caso de una sola versión.

**Sin cambios:** RN5, el endpoint genérico de cambio de etapa, el motor de márgenes al confirmar, el congelado de Condiciones vigentes en cada versión nueva.
No hay migración ni tabla nueva; nada de umbrales de negocio hardcodeados.

## 3. Criterios de aceptación

- **A1.** Proyecto con cotización confirmada, restaurado a Lead y avanzado a Diseño: `duplicate` responde 201 y crea un borrador con las partidas, ítems, precios, cantidades,
  descuentos, fechas y contactos de la cotización origen.
- **A2.** Confirmar ese borrador mueve la tarjeta a Cotización y la cotización queda como versión 2 (evento `QuoteConfirmed` con `es_nueva_version = true`).
- **A3.** Lo mismo con la tarjeta en Lead.
- **A4.** `duplicate` sigue respondiendo 422 en `lost`, `proposal` y `approved`, y sobre una cotización no confirmada (tests existentes intactos).
- **A5.** El 422 de `lost` dice que hay que reactivar el proyecto primero; el de `proposal`/`approved` dice que la cotización quedó congelada.
- **A6.** En Lead/Diseño con cotización confirmada, "Crear cotización" muestra el diálogo con el folio real; "En blanco" abre el formulario de siempre; "Partir de…" abre el borrador precargado.
- **A7.** En Lead/Diseño SIN cotización confirmada no aparece ningún diálogo (flujo actual).
- **A8.** El atajo directo a Cotización sigue bloqueado: el PATCH genérico a `quote` desde Diseño responde 422 como hoy (RN5 intacta).
- **A9.** Las validaciones previas (superficie, archivo de diseño, cliente) siguen exigiéndose también para "Partir de…".
- **A10.** Vendedor sin permiso `ventas_diseno.edit` no puede duplicar (403 como hoy).
- **A11.** Caso del ticket de punta a punta en dev: COT-2026-0003 → nueva versión precargada → confirmar → la tarjeta pasa a Cotización.

## 4. Desglose del PM (ruta crítica P1 → P3; el frontend sigue al contrato de P2)

| # | Tarea | Dueño | Depende |
|---|---|---|---|
| P1 | `duplicate()` permitido en lead/design/quote + mensajes de 422 + tests (A1–A5, A10) | Backend | — |
| P2 | `latest_quote_folio` en el detalle de la tarjeta + test | Backend | — |
| P3 | Diálogo "Crear cotización" en `PipelineCardModal` (A6, A7, A9) + tests | Frontend | P1, P2 |
| P4 | "Usar como base" visible en lead/design en `QuoteViewerModal` + tests | Frontend | P1 |
| P5 | Gate local en ambos repos + Senior Review independiente | Arquitecto | P1–P4 |
| P6 | Pre-QA adversarial en dev (A8, A11, bordes: dos versiones simultáneas, proyecto sin permisos, tarjeta en proposal) | Pre-QA | P5 |
| P7 | Despliegue dev → test → prod (cada paso con OK de Luis), tag, Jira, documentación | Arquitecto | P6 |

## 5. Riesgos y notas

- Ninguna regla de negocio nueva ni umbral: solo se amplía en qué etapas se puede crear una versión, con las mismas validaciones.
- La versión nueva congela las Condiciones VIGENTES y pasa por las validaciones de margen al confirmar: el precio viejo no se reutiliza sin revisión (por eso se descartó el atajo directo).
- Tras meses en Perdido, los precios del catálogo pueden haber cambiado: la vendedora ve la cotización precargada y la edita antes de confirmar.
