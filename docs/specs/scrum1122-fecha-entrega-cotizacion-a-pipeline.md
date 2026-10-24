# SCRUM-1122 — Cotización → Pipeline: transferir la Fecha estimada de entrega

**Ticket:** SCRUM-1122 (reporta Gerencia Test, prioridad Highest, épica SCRUM-327). **Estado:** spec pendiente de aprobación de Luis (2026-10-08).
**Decisiones de Luis:** (D1) la cotización manda: al confirmarla, la tarjeta se sincroniza con sus fechas aunque ya tuviera otras (también en una versión nueva); (D2) «Por definir» NO toca la tarjeta: ni traslada ni borra fechas.

## 1. Situación actual

Al confirmar una cotización (`QuoteService::confirm()` → `advanceCardToQuoteStage()`) solo se traslada el monto a la tarjeta (`amount`). El tipo de entrega y las fechas de la cotización (`quotes.delivery_type` + `quote_delivery_dates`) nunca llegan a `pipeline_cards.delivery_type` + `pipeline_card_delivery_dates`: hay que volver a teclearlas en el detalle del proyecto. Además, para pasar de Propuesta a Aprobado la tarjeta exige al menos una fecha de entrega.

## 2. Diseño

**Punto de enganche:** `QuoteService::advanceCardToQuoteStage()` (se ejecuta dentro de la transacción de `confirm()`, tanto para la primera confirmación como para una versión nueva). Se reutiliza `PipelineService::update()` con `delivery_type` y `delivery_dates`: ya sincroniza por diferencia (SCRUM-1027), de modo que una fecha que se conserva mantiene su id y sus asignaciones de producto, y solo se crean las nuevas y se borran las quitadas. Sin lógica de fechas nueva.

| Cotización confirmada | Efecto en la tarjeta |
|---|---|
| Única + fecha | `delivery_type = single`, una sola fecha = la de la cotización |
| Parcial + fechas | `delivery_type = partial`, TODAS las fechas (el mismo conjunto; las pantallas de Pipeline las muestran ordenadas por fecha) |
| Por definir (`tbd`) o sin fechas | no se toca nada (ni se traslada ni se borra) — D2 |

- La tarjeta nunca recibe una fecha inventada ni la primera de varias. Una fecha repetida en la cotización se traslada una sola vez.
- Orden: las fechas se sincronizan por diferencia, así que una tarjeta que ya tenía alguna conserva su id y las nuevas reciben ids mayores; el conjunto es siempre el de la cotización, y Pipeline las ordena por fecha al mostrarlas (Senior Review, observación 1).
- «La cotización manda» (D1): si la tarjeta ya tenía fechas y la cotización trae fechas, la tarjeta queda con las de la cotización; una edición manual posterior en Pipeline se mantiene hasta la siguiente confirmación.
- Aplica también cuando la confirmación CREA la tarjeta (RN0.3 de SCRUM-734): nace ya con las fechas.
- Sin migración, sin tabla nueva, sin umbrales de negocio.

**Frontend:** sin cambios de pantalla — el detalle del proyecto ya muestra `delivery_type` y `delivery_dates`; solo hay que invalidar/refrescar la tarjeta tras confirmar (la cotización ya navega a Pipeline o la recarga; se verifica).

**Sin cambios:** la selección y el registro de fechas dentro de la cotización, el gate Propuesta→Aprobado, las asignaciones de producto a fechas.

## 3. Criterios de aceptación

- **A1.** Cotización única con fecha 15/11/2026 → al confirmar, la tarjeta queda `single` con 15/11/2026, sin teclearla.
- **A2.** Cotización parcial con 3 fechas (15/11, 30/11, 15/12) → la tarjeta queda `partial` con las 3, en el mismo orden.
- **A3.** Cotización «Por definir» → la tarjeta no recibe ninguna fecha; si ya tenía, las conserva (D2).
- **A4.** Tarjeta que ya tenía otras fechas + cotización con fechas → queda con las de la cotización (D1); las fechas coincidentes conservan sus asignaciones de producto.
- **A5.** Versión nueva («Usar como base») con fechas distintas, al confirmarse, actualiza la tarjeta igual (D1).
- **A6.** La confirmación que crea la tarjeta (proyecto sin tarjeta) la deja con las fechas de la cotización.
- **A7.** Una cotización sin fechas definidas no genera fechas en Pipeline.
- **A8.** No se altera la lógica de fechas de la cotización ni el resto del flujo de confirmación (monto, etapa, evento `QuoteConfirmed`).
- **A9.** Las cotizaciones de CotizaYA no participan (no tienen tarjeta ni confirman).

## 4. Desglose

| # | Tarea | Dueño |
|---|---|---|
| P1 | Traslado de tipo y fechas en `advanceCardToQuoteStage()` + tests (A1–A8) | Backend |
| P2 | Verificar el refresco del detalle del proyecto tras confirmar + test si hace falta | Frontend |
| P3 | Gate local, Senior Review independiente, Pre-QA en dev (con pausas, ver CrowdSec) | Arquitecto/Pre-QA |
| P4 | dev → test → prod (cada paso con tu OK), Jira y documentación | Arquitecto |

## 5. Riesgos

- Una versión nueva con fechas distintas borra las asignaciones de producto de las fechas que desaparecen (cascade de la FK): es el comportamiento ya vigente al editar fechas a mano; solo ocurre mientras la tarjeta está en Lead/Diseño/Cotización, antes de Propuesta/Aprobado (donde la cotización queda congelada).
- Datos existentes: no se retrocede nada; las tarjetas confirmadas antes de este cambio no se tocan.
