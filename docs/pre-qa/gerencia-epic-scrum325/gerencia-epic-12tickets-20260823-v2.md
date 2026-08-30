# Pre-QA adversarial — Epic Gerencia (SCRUM-325), SEGUNDA PASADA — 12 tickets en `QA`

**Fecha:** 2026-08-23 (misma fecha que la primera pasada)
**Ambiente:** `dev.atlanticerp.ai` — backend `9676a3a0f578...` (verificado idéntico al HEAD local de
`dev`, ver sesión), frontend en sync con `origin/dev` (`a878725...`), **cero commits relacionados
a Gerencia entre la primera pasada y esta**.
**Cuenta usada:** `whil@atlantic.com.pa` (Gerencia Restringida, Nivel 8). `daniela@
atlantic.com.pa` ya no acepta password por defecto (401) — no se pudo repetir la comparación
cruzada de usuarios que sí funcionó el 2026-08-22; no fue necesaria para el hallazgo principal (ver
abajo, confirmado por código).
**Spec (histórico, superado por la v3 post-merge):** `preqa-scrum-gerencia-12tickets-20260823-v2.spec.ts`
— 16/16 tests verdes en su momento, corridos secuencial (`--workers=1`) contra dev real. El archivo
del spec se retiró tras el merge de los PRs `gerencia→dev` del 2026-08-24 (sus locators quedaron
apuntando a copy que ya no existe); este `.md` queda como registro del estado de ese momento.

**Nota de alcance:** epic de otro equipo (ver `feedback_gerencia_epic_otro_equipo_scope`) — pedido
puntual de Luis. No se transicionó ningún ticket ni se tocó código en esta pasada. Reporte puro,
igual que la primera pasada.

---

## ⚠️ Hallazgo de proceso — el motivo por el que hizo falta esta segunda pasada

Los 12 tickets que estaban en `QA` al arrancar esta sesión **son, con 3 excepciones, los mismos 9
que la primera pasada mandó de vuelta a `In Progress` ayer con 6 CRÍTICO + 3 MEDIO documentados**
(SCRUM-156/157/158/159/160/166/168/171/172) — **más 3 tickets nuevos nunca revisados**
(SCRUM-161/162/165). Confirmado por `git log`: **no hay un solo commit tocando código de Gerencia
entre la primera pasada y esta**. Alguien movió los 9 tickets con hallazgos de vuelta a `QA` sin
que el código haya cambiado — los 6 CRÍTICO siguen reproduciendo tal cual, verificado en vivo
abajo. Esto es exactamente el loop que `pre-qa.md` existe para cerrar (hallazgo → PM reasigna →
mitigación → re-check → recién ahí `QA`) y que acá no se cerró.

---

## Parte A — Los 9 tickets de la primera pasada: TODOS los hallazgos siguen reproduciendo

| Ticket | Hallazgo (2026-08-22/23) | Resultado hoy |
|---|---|---|
| SCRUM-159 | CRÍTICO — faltan filtros Vendedor/Cliente (RN1) | **Sigue roto** — 0 `<select>` en la página |
| SCRUM-159/156/160 | CRÍTICO — indicadores/tarjetas no navegan | **Sigue roto** — clic no cambia la URL |
| SCRUM-160 | CRÍTICO — set de indicadores no es el que pide el ticket | No re-testeado en código nuevo (sin commits, se asume igual — mismo componente que 159/156) |
| SCRUM-166 | CRÍTICO — clic en "Estancados"/"Aprobados este mes" navega al mismo lugar sin filtrar | **Sigue roto** — ambos navegan a `/ventas-diseno/home` |
| SCRUM-168 | CRÍTICO — falta el indicador "Bajo stock sin ordenar" | **Sigue roto** — 0 coincidencias en el DOM |
| SCRUM-171 | CRÍTICO — "Ver detalle" de Bodega navega a Ventas & Diseño en vez de Bodega | **Sigue roto** — navega a `/ventas-diseno`, nunca a `/bodega` |
| SCRUM-157 | MEDIO — clic en la tarjeta sin ningún feedback | **Sigue igual** — clic no reacciona |
| SCRUM-157 | MEDIO — subtítulo en $0 sin el formato de RN2 | No re-testeable hoy — el dato real ya no es $0 (hoy CxC total = $6200), es un hallazgo atado a un estado de datos, no a código; queda pendiente de re-verificar el día que el dato vuelva a $0 |
| Las 12 (transversal) | MEDIO — `/gerencia` no redirige a un rol sin acceso, se apoya solo en el 403 del backend | **Sigue igual** — Vendedor/Diseñador sigue "accediendo" a la ruta con pantalla de error genérico |
| SCRUM-158 (control) | Sin hallazgo — funciona | **Sigue funcionando** — 38, "en todos los módulos" |
| SCRUM-172 (control) | Sin hallazgo — funciona | **Sigue funcionando** — navega a `/servicios/inicio` |

**Conclusión Parte A: ninguno de los 6 CRÍTICO está corregido.** Estos 9 tickets no deberían estar
en `QA` — es la misma pasada de ayer, sin cambios.

---

## Parte B — 3 tickets nunca revisados antes (SCRUM-161/162/165)

### SCRUM-161/162 (REQ-099/REQ-100, "Aprobaciones por validar") — CRÍTICO, confirmado por código

**No es un bug de comportamiento — es la ausencia total de la feature**, confirmado tanto en
backend como en frontend, no solo por comportamiento en pantalla:

- `atlanticerp-backend/app/Modules/Gerencia/Services/GerenciaHomeService.php:54` →
  `'aprobaciones' => []` hardcodeado, con el propio comentario del código:
  *"módulo de aprobaciones no construido aún"*.
- `atlanticerp-frontend/src/types/gerencia.ts:96` → `export type AprobacionItem = Record<string,
  unknown>` con docblock: *"Pendiente sistema de aprobaciones (listado de solicitudes reales —
  REQ-006/007, bloqueado por la matriz de REGLA_APROBACION)"*. REQ-006/007 son los IDs internos de
  REQ-099/REQ-100 (SCRUM-161/162) en el Excel de especificación.
- `atlanticerp-frontend/src/pages/GerenciaPage.tsx:439` → el render itera `aprobaciones.map((_, i) =>
  ...)` **ignorando el ítem** (`_`) y mostrando un placeholder `—` fijo por fila — ninguna
  descripción/módulo/motivo (RN3), sin `onClick` en la fila pese al `cursor-pointer` visual.

**Consecuencia para cada criterio:**
- SCRUM-161 RN1/RN2 (listado personalizado por usuario, según el tipo de solicitud que le
  corresponde a cada uno) — **imposible de ejercitar**: el backend devuelve `[]` incondicional
  para cualquier usuario, confirmado en vivo con Whil (`GET /api/gerencia/home` → `aprobaciones:
  []`, `status: 200`).
- SCRUM-161 RN3 (cada fila muestra descripción/módulo/motivo) — **no implementado**, solo hay un
  placeholder `—`.
- SCRUM-162 (detalle + Aprobar/Rechazar/"Ver en [módulo]") — **no existe ningún componente de
  detalle, ni botones Aprobar/Rechazar, ni navegación a módulo de origen** en todo el código de
  Gerencia. No hay nada que probar en el camino de ruptura porque no hay camino feliz que probar
  primero.

Confirmado visualmente en vivo: el panel siempre muestra el estado vacío "Todo al día — No hay
aprobaciones pendientes en este momento."

### SCRUM-165 (REQ-103, vistas Día/Semana/Mes) — LIMPIO

A diferencia de la nota del Excel de especificación ("GAP EN EL MOCKUP: hoy el selector es
puramente visual... no cambia nada" — esa nota describe el *mockup*, no la implementación real),
el desarrollo real sí está completo y funcional:

- RN1 (vista por defecto Día) ✅
- Escenario 1 (clic en "Semana" cambia el panel + marca el pill activo) ✅
- RN3 (solo un pill activo a la vez, alternando Día→Semana→Mes→Día) ✅
- Ruptura — recargar a mitad del cambio de vista: no rompe, vuelve a un estado válido (Día) ✅
- Ruptura — doble clic rápido en el mismo pill: no rompe el estado ✅

Coherente con que el mecanismo de cambio de vista ya había sido tocado indirectamente por el spec
de SCRUM-163/164 del 2026-08-22 (`pill Día/Semana/Mes cambia el rango sin romper la pantalla`),
que ya había pasado limpio.

**Nota de scope — PERMISOS:** las 3 fichas repiten "Exclusivo de Líder/Gerencia" — mismo hallazgo
MEDIO transversal ya documentado arriba (no redirige prolijo, se apoya solo en el 403 del
backend), no es un hallazgo nuevo por ticket.

---

## Resumen ejecutivo

| Ticket | Veredicto |
|---|---|
| SCRUM-156, 159, 160, 166, 168, 171 | **CRÍTICO sin corregir** (idéntico a ayer) |
| SCRUM-157 | MEDIO sin corregir (falta de feedback al clic; el hallazgo de formato de subtítulo no es re-testeable con el dato de hoy) |
| SCRUM-158, 172 | Limpios (control, sin cambios) |
| SCRUM-161, 162 | **CRÍTICO nuevo — feature no implementada, confirmado por código+deploy, no solo por comportamiento** |
| SCRUM-165 | **Limpio — pasada adversarial completa nueva, sin hallazgos** |
| Las 12 (transversal) | MEDIO sin corregir — `/gerencia` no redirige a rol sin acceso |

**11 de 12 tickets no deberían estar en `QA` todavía.** Solo SCRUM-165 pasa una pasada adversarial
limpia hoy.

## Siguiente paso — requiere decisión de Luis (epic de otro equipo, no se tocó Jira)

1. ¿Se comunica esto al otro equipo? Mismo dilema que ayer: comentario por ticket vs. resumen
   centralizado.
2. Concretamente: **9 tickets volvieron a `QA` sin ningún fix de código** — vale la pena que Luis
   confirme con el otro equipo si esto fue intencional (¿decidieron que el bounce de ayer no
   aplica y lo dejan para más adelante?) o un error de manejo del tablero.
3. SCRUM-161/162 son deuda de alcance real, no un bug puntual — la feature completa de
   Aprobaciones (REQ-006/007) sigue sin construir; puede valer la pena que quede marcado así en
   Jira en vez de "en QA" para no seguir generando bounces sobre algo que no tiene código detrás
   todavía.

## Actualización 2026-08-24 — resuelto por merge

Luis pidió mergear los PRs `gerencia→dev` de ambos repos, alinear ramas, desplegar a dev.atlanticerp.ai
y volver a correr Pre-QA. Ver `gerencia-epic-12tickets-20260824-v3-postmerge.md` para el estado
posterior al merge — varios de los hallazgos de este documento ya no aplican tal cual (SCRUM-171 y
el MEDIO transversal quedaron arreglados; SCRUM-156/157/158/159/160/161/162 tienen avance real pero
no pasan limpio todavía; SCRUM-166/168 siguen exactamente igual).
