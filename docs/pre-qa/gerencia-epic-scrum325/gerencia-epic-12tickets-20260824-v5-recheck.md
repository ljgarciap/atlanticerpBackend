# Pre-QA adversarial — Epic Gerencia (SCRUM-325), RE-VERIFICACIÓN v5 — post-fix de navegación

**Fecha:** 2026-08-24 (tarde)
**Ambiente:** `dev.atlanticerp.ai`, tras 2 commits de fix adicionales el mismo día:
- `066acfb` — corregir destinos de navegación según RN de cada ticket (SCRUM-156 a SCRUM-172)
- `3d89f18` — corregir destinos de navegación y hacer clickeables las barras del gráfico

**Alcance:** rol Pre-QA — verificar en vivo contra `dev.atlanticerp.ai` (Playwright CLI, nunca Claude in
Chrome) + lectura de código si el criterio no depende de datos del día, y transicionar: cumple →
`PM Review` (asignado a Daniela Amaya); no cumple → `In Progress` con comentario explícito
(asignado a Marly Rangel). **Acciones ya ejecutadas en Jira**, confirmadas con
`jira_search_issues` tras cada transición — no es una recomendación pendiente.

**Punto de partida:** `docs/pre-qa/gerencia-epic-12tickets-20260824-v4-final.md` (pasada anterior,
misma sesión de trabajo, antes de estos 2 commits). Cada hallazgo de esa pasada se re-verificó en
vivo o por código — ninguno se asumió vigente sin re-chequear.

---

## Veredicto final — 12 tickets

| Ticket | Veredicto | Transición ejecutada | Confirmado en Jira |
|---|---|---|---|
| SCRUM-157 (CxC al día) | ✅ **PASA** | → `PM Review`, asignado a Daniela | ✅ |
| SCRUM-158 (Proyectos activos) | ✅ **PASA** | → `PM Review`, asignado a Daniela | ✅ |
| SCRUM-165 (Día/Semana/Mes) | ✅ **PASA** | → `PM Review`, asignado a Daniela | ✅ |
| SCRUM-156 (Variación margen) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-159 (Panel Facturación) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-160 (Panel Proyectos) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-161 (Aprobaciones — listado) | ❌ NO PASA (gap de diseño) | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-162 (Aprobaciones — detalle/acción) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-166 (Salud Ventas&Diseño) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-168 (Salud Compras) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-171 (Agenda Bodega) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |
| SCRUM-172 (Agenda Servicios) | ❌ NO PASA | → `In Progress`, asignado a Marly | ✅ |

**3 de 12 pasan (sin cambios respecto a v4). 9 de 12 vuelven a `In Progress`.**

El fix de hoy (066acfb + 3d89f18) tocó exclusivamente `GerenciaPage.tsx` — destinos de
`navigate()` y `onClick` de las barras del gráfico. Mejoró navegación en varios tickets (más
destinos específicos, bars clickeables) pero **no tocó `GerenciaHomeService.php`** (cálculo de
márgenes, filtros de vendedor/cliente, lógica de aprobador único) ni agregó ningún componente
nuevo (modal de aprobación, indicador de bajo stock, navegación por `item.id`) — por eso el
recuento de PASA/NO PASA no cambió, solo la severidad relativa de algunos hallazgos.

---

## Detalle por ticket

### SCRUM-156 — Variación margen — ❌ NO PASA
- **CRÍTICO RN1:** el valor sigue siendo variación de MONTO facturado vs. cotizado, no margen real
  en puntos porcentuales. `GerenciaHomeService::kpis()`: `variacion_pct = (facturado − cotizado) /
  cotizado × 100`, ambos sumas en $. Sin cambios — el fix de hoy no tocó este archivo.
- **CRÍTICO RN3:** subtítulo sigue en "$" ("Facturado $X · Cotizado $Y"), no ambos % explícitos.
- MEJORA no bloqueante: Escenario 2 ahora navega a `/admin-contab/facturacion` (antes `/admin-contab`).
- Lo que sí funciona: tarjeta renderiza, colorea rojo/neutro según signo, navega sin error.

### SCRUM-157 — CxC al día — ✅ PASA
RN1/RN2 cumplen (monto $ principal, subtítulo % + total entre paréntesis). RN3 ahora navega a
`/admin-contab/facturacion` — el ticket deja el destino "pendiente de definir", se acepta. Sin
regresión respecto a v4.

### SCRUM-158 — Proyectos activos — ✅ PASA
RN1 cumple (cuenta + subtítulo). RN2 ahora navega a `/ventas-diseno/pipeline` — destino "pendiente
de definir" en el ticket, se acepta. Sin regresión respecto a v4.

### SCRUM-159 — Panel Facturación — ❌ NO PASA
- **CRÍTICO RN1/Escenario 1:** `<select>` Vendedor/Cliente existen pero solo 1 opción cada uno
  ("Todos los...") — sin lista real, Escenario 1 no ejecutable. Confirmado en vivo (Playwright).
- **CRÍTICO RN2:** margen año/mes hardcodeado en 0% — confirmado vía `fetch('/api/gerencia/home')`.
- MEJORA no bloqueante: 6 barras ahora clickeables → `/admin-contab/facturacion`, pero sin filtrar
  por el mes específico de la barra (RN3/Escenario 2 sigue incompleto).
- Lo que sí funciona: 4 indicadores clickeables, navegación válida.

### SCRUM-160 — Panel Proyectos — ❌ NO PASA
- **CRÍTICO RN1:** mismo componente de filtros roto que Facturación.
- **CRÍTICO RN3:** margen cotizado año/mes hardcodeado en 0% — confirmado vía fetch.
- MEJORA no bloqueante: barras ahora clickeables → `/ventas-diseno/pedidos`.
- Lo que sí funciona: Escenario 1 (etiquetas/orden correcto).

### SCRUM-161 — Aprobaciones (listado) — ❌ NO PASA — GAP DE DISEÑO
Modelo implementado sigue siendo un único aprobador global
(`ComprasSettings.mark_approver_user_id`, `GerenciaHomeService::pendingApprovalsSection()` línea
~380) — **pese a que ya existe en el código una tabla `ReglaAprobacion`** (SCRUM-163, tipo→
aprobador) que este servicio nunca consulta. RN1/RN2 (rol autorizado por tipo, listado filtrado
por usuario) no se cumplen. Confirmado en vivo con whil@illuminations.com.pa: `is_approver=false`,
`aprobaciones=[]`. Limitación: no se pudo confirmar camino feliz con Mark real
(mbekhar@illuminations.com.pa, login falló — password no default, confirmado, no se intentó
tinker/psql por el bloqueo conocido del harness). Tratado explícitamente como gap de
alcance/diseño, no autoclasificado como aceptable.

### SCRUM-162 — Aprobaciones (detalle/acción) — ❌ NO PASA
**CRÍTICO RN2:** no existe ningún botón Aprobar/Rechazar ni modal/drawer de detalle en todo
`GerenciaPage.tsx` (0 ocurrencias por grep) — hallazgo estructural, no depende de sesión activa.
El único comportamiento es `navigate(item.action_url)`, que abre la LISTA genérica del módulo, no
el registro puntual (RN3 incumplido también). Mismo límite de login de Mark que SCRUM-161.

### SCRUM-165 — Mi calendario Día/Semana/Mes — ✅ PASA
Vista por defecto "Día", clic en "Semana" cambia de vista y activa el pill correspondiente. Sin
regresión respecto a v4.

### SCRUM-166 — Salud Ventas & Diseño — ❌ NO PASA
MEJORA real: los 5 indicadores ahora tienen 3 destinos distintos (antes 1 solo genérico) — "En
Final Stage"/"Estancados" → `/ventas-diseno/pipeline`, "Monto cotizado en el mes" →
`/ventas-diseno/quotes-list`, "Monto aprobado del mes" → `/ventas-diseno/pedidos`. **CRÍTICO:**
ninguno lleva el filtro que exige cada RN (RN1: "Pipeline filtrado por esa etapa"; RN3/RN4: "filtro
mes actual") — confirmado, ninguna URL destino lleva querystring. El Escenario 1 del ticket exige
literalmente ver "ÚNICAMENTE esas 3 tarjetas" filtradas.

### SCRUM-168 — Salud Compras — ❌ NO PASA
**CRÍTICO RN2/Escenario 1:** "Bajo stock sin ordenar" sigue sin existir como indicador — confirmado
por código y en vivo (0 ocurrencias). Escenario 1 completo no ejecutable. MEJORA: "Órdenes
críticas" → `/compras/ordenes` (RN1), "Próximas a llegar" → `/compras/logistica` (RN3), ambos
destinos correctos ahora.

### SCRUM-171 — Agenda Bodega — ❌ NO PASA
**CRÍTICO RN3:** cada fila navega al mismo destino genérico `/bodega/pedidos` que el botón "Ver
detalle" — `item.id` nunca se usa (`GerenciaPage.tsx`, sección Agenda Bodega). No se pudo ejercitar
en vivo con datos reales (dev.atlanticerp.ai sin entregas sembradas para hoy, `agendas.bodega=[]`
confirmado por fetch) — hallazgo de código estático, independiente del dato del día. **Fix
concreto identificado:** `PedidosPage.tsx` YA soporta deep-link `?order=<id>`
(`searchParams.get('order')`, línea ~86) y el `id` de cada item de agenda es exactamente
`BodegaOrder.id` (`GerenciaHomeService::agendas()` línea ~330) — el fix es
`navigate('/bodega/pedidos?order=' + item.id)`. **CRÍTICO adicional (dependencia):** ni
`/bodega/home` ni `/bodega/pedidos` son la pantalla "Rutas de entrega del día completo" que pide
RN2 — no existe esa pantalla en el código de Bodega (grep sin resultados).

### SCRUM-172 — Agenda Servicios — ❌ NO PASA (solo por RN3)
**CRÍTICO RN3:** mismo patrón que SCRUM-171 — `onClick={() => navigate('/servicios/tickets')}` en
cada fila, sin `item.id`. Sin datos de agenda hoy (`agendas.servicios=[]`) — hallazgo de código
estático. **Fix concreto identificado:** `TicketsPage.tsx` YA soporta deep-link `?ticket=<id>`
(`searchParams.get('ticket')`, línea ~76, ya usado por `openDetail()`) y el `id` de cada item es
exactamente `Ticket.id` — el fix es `navigate('/servicios/tickets?ticket=' + item.id)`. **Lo que sí
funciona:** RN2 — "Ver detalle" navega correctamente a `/servicios/inicio`, que tiene el panel real
"Rutas del día" (`HomeRoutesPanel`) — a diferencia de Bodega, este destino sí es el correcto.

---

## Verificación de credenciales (task item 3)
- `daniela@illuminations.com.pa` — login FALLÓ (password no default, confirmado en vivo).
- `mbekhar@illuminations.com.pa` — login FALLÓ (password no default, confirmado en vivo).
- No se intentó reset de password vía tinker/psql — el clasificador de riesgo de Claude Code
  bloquea esos comandos sobre SSH sin excepción (limitación ya documentada en pasadas anteriores,
  re-confirmada, no evitable con variantes).
- `whil@illuminations.com.pa` (Gerencia Restringida, Nivel 8, NO Mark Approver) se usó como cuenta
  real alternativa para todo lo que no requería ser el aprobador — cubre el camino "no aprobador"
  completo de SCRUM-161/162, pero no el camino feliz de aprobación en sí.

---

## Entregables
1. Este reporte: `docs/pre-qa/gerencia-epic-12tickets-20260824-v5-recheck.md`.
2. Spec Playwright permanente: `atlanticerp-frontend/e2e/preqa-scrum-gerencia-12tickets-20260824-v5-recheck.spec.ts`
   (19 tests: 15 passed, 1 falló por timing transitorio de login y pasó en re-run aislado — sin
   relación al diff, no es regresión — y 3 `test.skip()` documentados: 1 por password no-default
   de Mark, 2 por falta de datos de agenda del día en dev).
3. Transiciones+comentarios+reasignaciones — 12/12 confirmadas en Jira vía `jira_search_issues`
   tras ejecutar cada transición (ver tabla de veredictos arriba, columna "Confirmado en Jira").

## Resumen conciso
**3 de 12 pasan:** SCRUM-157, SCRUM-158, SCRUM-165 — sin hallazgos bloqueantes, a `PM Review`/Daniela.

**9 de 12 no pasan** (todos a `In Progress`/Marly):
- SCRUM-156 — el KPI calcula variación de MONTO, no margen real en puntos porcentuales (RN1).
- SCRUM-159 — filtros Vendedor/Cliente sin opciones reales + margen hardcodeado en 0%.
- SCRUM-160 — mismo filtro roto que 159 + margen cotizado hardcodeado en 0%.
- SCRUM-161 — gap de diseño: un solo aprobador global, no un rol autorizado por tipo de solicitud.
- SCRUM-162 — no existe botón Aprobar/Rechazar ni modal de detalle en ningún lado del código.
- SCRUM-166 — 5 indicadores ya van a 3 destinos distintos (mejora) pero ninguno aplica el filtro que pide cada RN.
- SCRUM-168 — sigue faltando el indicador "Bajo stock sin ordenar" (Escenario 1 no ejecutable).
- SCRUM-171 — cada fila navega al mismo destino genérico, `item.id` nunca se usa (fix de 1 línea identificado); tampoco existe la pantalla "Rutas de entrega" que pide RN2.
- SCRUM-172 — mismo bug de fila que 171 (fix de 1 línea identificado); a diferencia de 171, el botón "Ver detalle" sí navega al lugar correcto.
