# Visual Review — SCRUM-368 (REQ-298, "Mi calendario" / "Calendario del equipo") — 2026-07-28

**Reviewer:** Visual Reviewer (AtlanticERP)
**Ticket:** SCRUM-368 — status real al momento de este review: **Dev Testing** (el intento previo
de transición a QA fue bloqueado por el gate — faltaban los marcadores de Pre-QA y de Visual
Review; este review es retroactivo, corrido a pedido de Luis para cerrar el gap).
**Mockup:** `3__Bodega_Home.html` — adjunto en el ticket, attachment id 10791 (único adjunto,
sin ambigüedad de versión).
**Entorno:** `https://dev.atlanticerp.ai`, usuario `almacen@atlantic.com.pa` (Esteban Cárdenas,
Jefe de Bodega — perfil correcto para poder alternar entre scope Inicio/Equipo).
**Herramienta:** Playwright CLI (`atlanticerp-frontend/e2e/visual-review-scrum368-calendario.spec.ts`,
script descartable, no se promueve a smoke test permanente).

## Checklist funcional (mockup `3__Bodega_Home.html`, líneas 484-531 + datos JS 746-861, y
descripción del propio ticket que detalla el contenido esperado del panel)

| # | Mockup / Ticket | Desarrollo real (dev.atlanticerp.ai) | Estado |
|---|---|---|---|
| 1 | Título alterna "Mi calendario" (individual) / "Calendario del equipo" (scope Equipo) | Alterna correctamente vía `bodega:home.calendar.title` / `titleTeam`, confirmado en vivo con el toggle Inicio/Equipo | ✅ presente y correcto |
| 2 | Subtítulo fijo "Sincronizado con Outlook" bajo el título | No existe ningún tag persistente — solo aparece un banner ámbar de error ("No se pudo conectar con Outlook...") cuando la sincronización real falla | ⚠️ ver ACEPTABLE — sustancia de RN1 sí implementada (Microsoft Graph real, SCRUM-66/177), falta el rótulo visual |
| 3 | Selector de vista Día/Semana/Mes (pill toggle) | Presente y funcional (`calendarPill` state, cambia la vista de la mini-tarjeta) — de hecho más funcional que el mockup, donde el toggle es decorativo/no wireado | ✅ presente y mejora el mockup |
| 4 | Cada evento (vista Día): hora + tipo de evento con color distintivo (Recepción/Entrega/Conteo/Almuerzo) + descripción + ubicación/detalle | Solo muestra hora + título. Sin color/tag de tipo de evento en ningún punto de la feature (ni Home ni modal completo). Ubicación/detalle solo visible abriendo el modal "Calendario completo", no en el panel de Inicio | 🔴 **CRÍTICO** |
| 5 | Vista individual: solo eventos propios, sin nombre (implícito) | Correcto — scope "Inicio" solo trae eventos propios | ✅ presente y correcto |
| 6 | Vista de equipo: lista combinada, cada evento indica a quién pertenece (nombre de la persona) — RN2 explícito + Escenario 2 de aceptación | Confirmado en vivo: con scope "Equipo" activo apareció un evento real ("14:30 GLF Systems Standups") sin ninguna atribución de nombre. Revisando código (`MiniCalendarCard.tsx`, `CalendarModal.tsx`), el campo `owner_email` de `OutlookCalendarEvent` se trae del backend pero **nunca se renderiza** en ningún lado de la UI | 🔴 **CRÍTICO** |
| 7 | Estado vacío "Sin eventos" | Presente y correcto, confirmado en vivo (Esteban sin eventos hoy en scope Inicio) | ✅ presente y correcto |

## Evidencia (capturas, `atlanticerp-frontend/e2e/.tmp/`)
- `visual-review-scrum368-home-full.png` — scope Inicio, "Mi calendario", "Sin eventos", pill Día/Semana/Mes, botón "Ver calendario completo"
- `visual-review-scrum368-equipo-scope.png` — scope Equipo, título "Calendario del equipo", evento real "14:30 GLF Systems Standups" **sin nombre de a quién pertenece**
- `visual-review-scrum368-modal.png` — modal "Calendario completo", vista Mes, día seleccionado (28 jul) muestra "Sin eventos" pese a que la tarjeta de Inicio en scope Equipo mostraba un evento hoy — inconsistencia de datos entre tarjeta y modal que excede el alcance de este gate (no es de fidelidad visual, es de datos/backend) y se deja anotada para que Pre-QA/Backend Dev la investigen, no es un hallazgo de Visual Review en sí.

## CRÍTICO
1. **Tipo de evento (color/tag) y ubicación/detalle ausentes del panel "Mi calendario" en Inicio.**
   El propio ticket describe el contenido del panel explícitamente: "hora, tipo de evento
   (Recepción, Entrega, Conteo, Almuerzo, etc. — cada uno con su color distintivo), descripción y
   ubicación/detalle." El mockup lo muestra inline en cada fila. El desarrollo solo muestra hora +
   título en la vista Día de la mini-tarjeta de Inicio; para ver ubicación hay que abrir el modal
   completo (y ahí tampoco hay indicador de tipo/color en ningún punto de la feature).
2. **Vista "Calendario del equipo" no indica a quién pertenece cada evento (RN2).** Confirmado en
   vivo con un evento real del día ("14:30 GLF Systems Standups") sin ninguna atribución de
   nombre, y confirmado en código: `owner_email` se pide al backend pero no se renderiza en
   `MiniCalendarCard.tsx` ni en `CalendarModal.tsx`. Esto es explícitamente el Escenario 2 de
   aceptación del ticket ("la lista combina ambos eventos, cada uno marcado con el nombre de a
   quién pertenece") — no se cumple.

## ACEPTABLE (nota, no bloquea)
- Ausencia del rótulo estático "Sincronizado con Outlook" bajo el título del panel. La sustancia
  de RN1 (sincronización real con una fuente externa, no eventos manuales) sí está implementada —
  el feature reusa el mismo `OutlookCalendarController`/Microsoft Graph de SCRUM-66/177, y de
  hecho el evento real visto en scope Equipo confirma que los datos vienen de Outlook de verdad.
  Solo falta el badge informativo — no una funcionalidad en sí. Nota para Frontend Dev: agregar el
  tag sería una mejora de claridad, no bloqueante.
- Botón/modal "Ver calendario completo" (navegación mes/semana/día con flechas) no existe en el
  mockup — es una adición del desarrollo, no elimina nada, mejora la experiencia. Variante
  aceptable (de hecho superior al mockup).
- Pill Día/Semana/Mes: en el mockup es decorativo (sin `onclick`); en el desarrollo es funcional.
  Mejora, no regresión.

## Lo que sí cumple
- Título del panel alterna correctamente "Mi calendario" / "Calendario del equipo" según scope.
- Vista individual (Inicio) trae solo eventos propios, sin necesidad de nombre — correcto (RN3).
- Estado vacío "Sin eventos" presente y correcto.
- Selector Día/Semana/Mes presente y, a diferencia del mockup, funcional de verdad.
- Botón "Ver calendario completo" navega a un modal funcional con navegación de mes/semana/día.

## Resultado
**CRÍTICO — no se crea el marcador de Visual Review.** Dos hallazgos bloqueantes: (1) falta
tipo/color de evento y ubicación/detalle en el panel de Inicio, (2) la vista de equipo no atribuye
cada evento a su dueño (RN2 incumplido, confirmado en vivo con datos reales). El ticket ya está
correctamente detenido en Dev Testing (el gate automático ya lo bloqueó antes de este review) —
este resultado no cambia ese estado, solo lo confirma con causa raíz documentada. Queda a criterio
de Luis/PM si se reasigna a Frontend Dev antes de continuar.

Marcador NO creado (`~/.claude-visual-review-markers/SCRUM-368` no existe tras este review).
