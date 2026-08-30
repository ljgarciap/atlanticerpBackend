# Pre-QA + Visual Reviewer fusionados — Épica Gerencia (SCRUM-325), recheck v7

**Fecha:** 2026-08-26
**Entorno:** dev.atlanticerp.ai — backend `ead5669` / frontend `910e328` (HEAD de `origin/dev`, confirmado antes de arrancar)
**Alcance:** 10 tickets en columna `QA` de Jira — SCRUM-159, 160, 161, 162, 166, 167, 168, 169, 170, 171
**Precede a:** [gerencia-epic-9tickets-20260825-v6-recheck.md](./gerencia-epic-9tickets-20260825-v6-recheck.md) (1/9 pasó, 8/9 no)
**Spec Playwright:** `atlanticerp-frontend/e2e/preqa-scrum-gerencia-10tickets-20260826-v7-recheck.spec.ts` (23/24 verde; 1 test flaky por CrowdSec ante logins múltiples seguidos, ver nota en el spec — hallazgo confirmado manualmente igual, ver evidencia abajo)

> Nota de alcance: este épica es de OTRO EQUIPO (ver `feedback_gerencia_epic_otro_equipo_scope`). Ningún fix de código de negocio fue implementado en esta sesión — solo el spec Playwright de regresión y este reporte.

---

## Resultado — 2/10 PASAN, 8/10 NO PASAN

| Ticket | Veredicto | Motivo |
|---|---|---|
| SCRUM-159 | ❌ NO PASA | RN3/Escenario 2 (bar click → mes) sigue roto. RN1 (filtro vendedor) sigue sin efecto real en Facturación — ahora con aviso transparente, pero el gap de modelo de datos persiste |
| SCRUM-160 | ✅ PASA | RN1 (filtro vendedor) y RN3 (margen cotizado) corregidos y confirmados en vivo con datos reales |
| SCRUM-161 | ❌ NO PASA | CRÍTICO — el listado de aprobaciones NO filtra por tipo de solicitud pese al fix (ver detalle) |
| SCRUM-162 | ❌ NO PASA | RN1-RN3 corregidos (modal, botones, Ver en con ID), pero RN4 falla: aprobar/rechazar escribe valores de enum inválidos en el modelo real |
| SCRUM-166 | ❌ NO PASA | Solo RN1 corregido; RN2 (Estancados) y RN3/RN4 (filtro de mes) sin cambios desde v5 |
| SCRUM-167 | ❌ NO PASA | Indicadores/navegación de origen correctos, pero la pantalla destino (Cobros) no aplica el filtro |
| SCRUM-168 | ⚠️ PASA CON RESERVA | Mismatch de conteo corregido (43=43); "Bajo stock sin ordenar" sigue sin existir (alcance nuevo declarado por el propio ticket como dependencia, no un fix menor) |
| SCRUM-169 | ❌ NO PASA | Indicadores/navegación de origen correctos, pero la pantalla destino (Pedidos Bodega) no aplica el chip |
| SCRUM-170 | ❌ NO PASA | Indicadores correctos, pero la pantalla destino (Tickets) no aplica el filtro de estado |
| SCRUM-171 | ❌ NO PASA | Sin cambios desde v5/v6 — pantalla "Rutas de entrega" sigue sin existir; sin datos de agenda hoy (3ra sesión seguida) |

---

## Hallazgo transversal #1 (nuevo) — "querystring que el destino no consume", ahora en 3 pantallas más

Ya documentado para SCRUM-166 desde v5 (Pipeline). Esta pasada confirma el MISMO patrón en las 3
redefiniciones que hizo Daniela el 2026-08-25 para "Salud por módulo": el fix agregó correctamente
el indicador y la navegación de ORIGEN (Gerencia → URL con querystring correcto), pero ninguna de
las 3 pantallas DESTINO lee ese parámetro:

| Ticket | Querystring enviado | Pantalla destino | ¿Lee el param? |
|---|---|---|---|
| SCRUM-167 | `?estado=esperando_confirmacion` | `CobrosPage.tsx` | **NO** — solo lee `master_client_id`/`accion` |
| SCRUM-169 | `?chip=urgentes` / `?chip=atrasados` | `PedidosPage.tsx` (Bodega) | **NO** — `useState<Chip>('all')` hardcodeado, nunca lee `searchParams.get('chip')` |
| SCRUM-170 | `?estado=reportado` / `?estado=resuelto` | `TicketsPage.tsx` (Servicios) | **NO** — `useState(EMPTY_TICKET_FILTERS)`, nunca lee `searchParams.get('estado')` |

Confirmado empíricamente, no solo por código: `/bodega/pedidos?chip=urgentes` y
`/bodega/pedidos?chip=atrasados` renderizan contenido IDÉNTICO (mismo "25 pedidos en proceso").
`/servicios/tickets?estado=reportado` muestra "Mostrando 20 de 20 tickets" pese a que
`salud_modulos.servicios.sin_responder=7` — si el filtro aplicara, el total mostrado sería 7, no 20.

**Sugerencia de fix (mismo patrón para los 3):** cada página ya tiene el mecanismo de filtro
correcto (los valores 'urgentes'/'atrasados' SÍ son chips válidos en `PedidosPage.tsx`, 'reportado'/
'resuelto' SÍ son estados válidos en `TicketsPage.tsx`) — falta únicamente leer `searchParams` al
inicializar el estado, mismo mecanismo ya usado en `PipelinePage.tsx` (`stageParam`) o
`ventas-diseno/PedidosPage.tsx` (`searchParams.get('order')`). Es plausible que valga la pena una
revisión conjunta de las 3 en vez de 3 fixes aislados, dado que es exactamente el mismo gap
replicado 3 veces.

## Hallazgo transversal #2 (nuevo, más grave) — Aprobar/Rechazar desde Gerencia no respeta la autoridad real de cada módulo

**SCRUM-161** — Confirmado EN VIVO (no solo lectura de código): se creó una `ReglaAprobacion` real
de prueba (`tipo=adjustment`, aprobador=Whileyner Contreras, id=1, ELIMINADA al terminar la
prueba) y se inició sesión como Whil. Resultado: Whil vio los 23 pendientes de **TODOS** los tipos
(`purchase_order`, `zona_libre`, `general_count`), no solo `adjustment` — con `can_approve: true`
en cada uno. `GerenciaHomeService::pendingApprovalsSection()` solo verifica "¿es aprobador de
ALGÚN tipo?" (booleano), nunca filtra la lista ni el flag `can_approve` por el `tipo` real de la
regla. Esto incumple RN2 del propio ticket ("cada tipo tiene un rol autorizado — el listado se
filtra según quién inició sesión") y el Escenario 2 ("David... ve un listado DISTINTO, según sus
propios tipos autorizados").

**SCRUM-162 — mismo gap, con impacto de autorización real:** `GerenciaAprobacionController::isApprover()`
tiene el mismo defecto — no valida que el `$type` de la solicitud coincida con el `tipo` de la
`ReglaAprobacion` del usuario. Peor aún, comparado con el endpoint real de Compras
(`PurchaseOrderController::approve()`, línea ~443), que exige explícitamente
`$user->id === $markApproverId` (con bypass solo para `superadmin.all` — gate agregado en SCRUM-206
tras un hallazgo de QA), el endpoint de Gerencia **no replica ese gate**: cualquier usuario en
cualquier `ReglaAprobacion` activa (de cualquier tipo) podría aprobar una Orden de Compra vía
Gerencia aunque `PurchaseOrderController` se lo hubiera rechazado con 403 "Solo Mark puede aprobar
esta orden" si lo intentara por la vía normal. Es un bypass real de una regla de autorización ya
endurecida una vez (SCRUM-206).

**SCRUM-162 RN4 — CONFIRMADO EN VIVO con mutación real:** se aprobó el conteo general `CNT-5`
(`GeneralCountRequest` id=5) desde Gerencia con la sesión real de Mark. Resultado:
- Bandeja de Gerencia: `pending_total` 23→22, CNT-5 desaparece — mitad de RN4 cumple.
- Origen real (`GET /api/bodega/general-counts/5`): `estado` quedó en `"aprobado"`.
- Pero la constante real del modelo es `GeneralCountRequest::ESTADO_APROBADA = 'aprobada'` (con
  "a" final, no "o"). El valor escrito por Gerencia **no coincide con ningún estado válido** que
  el módulo Bodega reconozca — `isAprobada()` devuelve `false` para este registro. El registro
  queda en un limbo: ya no es `pendiente_aprobacion`, pero tampoco es `aprobada` de verdad.

Confirmado por lectura de código que el MISMO patrón (string literal en vez de la constante real
del modelo) se repite en los 4 helpers de `GerenciaAprobacionController` que no son
`purchase_order`:

| Tipo | Literal escrito por Gerencia (aprobar / rechazar) | Constante real del modelo |
|---|---|---|
| `relocation` | `'aprobado'` / `'rechazado'` | `RelocationRequest::ESTADO_APROBADA='aprobada'` / `ESTADO_RECHAZADA='rechazada'` |
| `adjustment` | `'Aprobado'` / `'Rechazado'` | `AdjustmentRequestLine::ESTADO_APROBADA='Aprobada'` / `ESTADO_RECHAZADA='Rechazada'` |
| `general_count` | `'aprobado'` / `'rechazado'` | `GeneralCountRequest::ESTADO_APROBADA='aprobada'` / `ESTADO_RECHAZADA='rechazada'` (**confirmado en vivo**) |
| `zona_libre` | `'aprobado'` / `'rechazado'` | `BodegaZonaLibreRequest::STATUS_APROBADA='aprobada'` / `STATUS_RECHAZADA='rechazada'` |

Además, el motivo (`razon`) que el endpoint `rechazar()` sí valida (`nullable|string|max:500`)
**nunca se pasa** a ninguno de los métodos privados `rechazar*()` — se descarta siempre, incluso
para tipos cuyo propio módulo exige motivo obligatorio (ver comentario SCRUM-465 en
`GeneralCountController::reject()`, "Mark rechaza, motivo obligatorio").

**Impacto de esta sesión en datos reales de dev.atlanticerp.ai:** el registro `general_counts` id=5
("CNT-5") quedó con `estado='aprobado'` (inválido) como evidencia directa de este hallazgo — no se
revirtió manualmente (no hay endpoint para forzar el valor correcto sin pasar por el mismo código
con el bug, y la regla del proyecto es no hacer cambios destructivos de BD sin confirmación
explícita de Luis). Recomendado: la corrección de este ticket debería incluir, además del fix de
código, una corrección de datos (backfill) para este registro puntual.

**Escalación recomendada:** este par de hallazgos (SCRUM-161 + SCRUM-162) no es un fix de una
línea — requiere decidir a nivel de Arquitecto si `GerenciaAprobacionController` debe (a) delegar
la acción real a cada controller/servicio de módulo existente (reutilizando sus propias
validaciones y constantes, recomendado) o (b) seguir siendo un mini-controlador propio pero
corregido para usar las constantes reales de cada modelo y validar `tipo` contra `ReglaAprobacion`
antes de autorizar. Cualquiera de las dos requiere tocar los 4 tipos no-PO más el filtrado de la
propia bandeja (SCRUM-161).

---

## Detalle por ticket

### SCRUM-159 — REQ-097: Panel "Facturación" — ❌ NO PASA

- **RN2 (margen año/mes) — CORREGIDO.** Ya no hardcodeado en 0% — valor real calculado:
  `margen_anio_pct=57.66`, `margen_mes_pct=57.66` (confirmado por fetch autenticado).
- **RN1 (filtro Vendedor/Cliente combinables) — gap arquitectónico persiste, ahora con
  transparencia.** El filtro de vendedor sigue sin efecto real en Facturación
  (`AdminContInvoice` no tiene FK a vendedor — no es un bug de código, es el modelo de datos). La
  UI ahora muestra el aviso "El filtro por vendedor aplica solo a Proyectos, no a Facturación" en
  vez de fallar en silencio — mejora real de transparencia, pero **RN1 tal como está escrito en el
  ticket ("Filtros Desde/Hasta/Vendedor/Cliente combinables") sigue sin cumplirse para este panel.**
  Este es el mismo hallazgo "arquitectónicamente imposible" de v6 — sigue sin solución real,
  probablemente necesita escalar a Arquitecto para decidir si se agrega la relación de vendedor a
  `AdminContInvoice` o si se redefine RN1 para excluir explícitamente a Facturación (como ya hace
  la UI de facto).
- **RN3/Escenario 2 (clic en barra → detalle del MES) — SIGUE ROTO.** Confirmado por código
  (`BarChart` pasa `item.mes` al callback, pero `onBarClick={() => navigate('/admin-contab/facturacion')}`
  en `GerenciaPage.tsx` descarta el argumento) y en vivo (clic en la barra "Ago" navega a
  `/admin-contab/facturacion` sin querystring). Mismo hallazgo, sin cambios desde v3/v5.

### SCRUM-160 — REQ-098: Panel "Proyectos" — ✅ PASA

- **RN1 (mismos filtros que Facturación) — CORREGIDO Y CONFIRMADO EN VIVO.** A diferencia de
  Facturación, Proyectos SÍ tiene relación real con vendedor (`PipelineCard.owner_id`). Se
  seleccionó un vendedor real (Anelhys Nuez) y "Proyectos cerrados este año" cambió de $769,441 a
  $55,000 — filtro real, confirmado por el network request (`vendedor_id=39`) y por el cambio del
  valor renderizado.
- **RN3 (margen cotizado año/mes) — CORREGIDO.** Valor real: `margen_anio_pct=57.47`,
  `margen_mes_pct=57.47`, ya no hardcodeado en 0%.
- **Escenario 1 (4 indicadores en el orden correcto)** — ya confirmado correcto desde v5, sin
  regresión.
- **Lo que sí funciona además:** el ticket no exige explícitamente clic-por-mes en su propio
  Gherkin (a diferencia de SCRUM-159 RN3) — el bug de bar-click sin mes existe también acá
  (mismo componente `BarChart` compartido) pero no bloquea los criterios propios de este ticket.

### SCRUM-161 — REQ-099: Panel "Aprobaciones por validar" (listado) — ❌ NO PASA

Ver "Hallazgo transversal #2" arriba para el detalle completo y la evidencia. Resumen: el fix
conectó `ReglaAprobacion` como fuente de autorización (mejora real sobre v6, donde solo Mark podía
ser aprobador), pero el filtrado es binario ("¿sos aprobador de algo?"), no por tipo — RN2 y el
Escenario 2 del propio ticket siguen sin cumplirse. Sin ninguna `ReglaAprobacion` activa hoy en
dev.atlanticerp.ai, David y Whil siguen sin forma de aparecer como `is_approver=true` en el estado
actual del entorno (confirmado: `is_approver=false`, `aprobaciones=[]` para Whil sin reglas).

### SCRUM-162 — REQ-100: Detalle + Aprobar/Rechazar — ❌ NO PASA

- **RN1/RN2/RN3 — CORREGIDOS Y CONFIRMADOS EN VIVO.** El modal de detalle existe, muestra
  descripción/monto/fecha; botones "Aprobar"/"Rechazar" renderizan para un aprobador real (Mark,
  con PO-8 y CNT-5); "Rechazar" correctamente ausente para `purchase_order` (regla de negocio ya
  documentada en el código: el rechazo de PO se gestiona en Compras). "Ver en [módulo]" navega con
  el ID puntual preseleccionado: `/bodega/inventario-general?count=5`, `/compras/ordenes?order=8`
  — esto es una mejora real y completa sobre v5/v6, donde 0 de esto existía.
- **RN4 — NO CUMPLE.** Ver "Hallazgo transversal #2": resuelve y desaparece de la bandeja de
  Gerencia, pero el valor que escribe en el módulo de origen es inválido — no es "reflejarse en el
  módulo de origen", es corromper el estado del registro ahí.

### SCRUM-166 — REQ-104: Salud Ventas & Diseño — ❌ NO PASA

- **RN1 ("En Final Stage") — CORREGIDO Y CONFIRMADO.** `?stage=proposal` → `PipelinePage.tsx`
  reconoce el valor (`chip='final_stage'`), filtra correctamente a solo la columna Propuesta.
- **RN2 ("Estancados") — SIGUE ROTO, sin cambios desde v5/v6.** Navega con `?order=days`, que
  `PipelinePage.tsx` interpreta como orden de sorting (días/valor), nunca como filtro de
  "estancados" — el chip `'stagnant'` existe en el tipo `Chip` pero nunca se activa desde este
  querystring.
- **RN3/RN4 (filtro de mes en Cotizaciones/Pedidos) — SIGUE ROTO, sin cambios.** Ambos
  `onClick` siguen navegando sin ningún querystring de mes.

### SCRUM-167 — REQ-105: Salud Admin & Contab (redefinido por Daniela 2026-08-25) — ❌ NO PASA

Contexto: el ticket de Jira original decía "PENDIENTE DE DEFINIR" — Daniela definió el
comportamiento real en un comentario del 2026-08-25 (2 indicadores: CxC Total → Cobros filtrado
"Esperando confirmación"; Cuentas al día → Cobros sin filtro; CxP Total eliminado).

- **CA1/CA2/CA3/CA6 (origen) — CORRECTOS.** Los 2 indicadores existen con las etiquetas correctas,
  navegan a `/admin-contab/cobros?estado=esperando_confirmacion` y `/admin-contab/cobros`
  respectivamente, sin un tercer indicador "CxP".
- **CA4/CA5 (el filtro debe quedar aplicado/visible en destino) — NO CUMPLE.** Ver "Hallazgo
  transversal #1": `CobrosPage.tsx` no lee `estado` de la URL — el select de estado sigue en
  "Todos los estados" tras llegar desde el indicador.

### SCRUM-168 — REQ-106: Salud Compras — ⚠️ PASA CON RESERVA

- **RN1 ("Órdenes críticas") — CORREGIDO Y CONFIRMADO.** El mismatch de v6 (tarjeta=41 vs
  filtro real=43) está resuelto: ambos ahora devuelven 43 (`GerenciaHomeService::saludModulos()`
  fue reescrito para usar la misma lógica de `PurchaseOrder::isCritical()`).
- **RN2/Escenario 1 ("Bajo stock sin ordenar") — SIGUE SIN EXISTIR.** El propio ticket declara
  esto como dependencia nueva ("requiere agregar el filtro cruzado nuevo en Inventario... no existe
  hoy") — no es un ajuste de navegación, es una feature nueva de cruce Inventario↔Compras nunca
  implementada. El Escenario 1 completo del Gherkin no se puede ejecutar sin este indicador.
- Se marca "PASA CON RESERVA" en vez de NO PASA porque el hallazgo pendiente ya estaba señalado
  por Luis en v5 como "confirmar con Arquitecto si este alcance estaba dimensionado en el batch
  actual" — sigue sin resolverse esa pregunta, así que este ticket queda igual que en v5/v6 en
  cuanto a alcance diferido, mientras el hallazgo de conteo (el único bug real de código) sí se
  corrigió. Transicioné a `In Progress` de todas formas dado que Escenario 1 del Gherkin no puede
  ejecutarse — ver sección de transiciones.

### SCRUM-169 — REQ-107: Salud Bodega (redefinido por Daniela 2026-08-25) — ❌ NO PASA

- **CA3 ("Despachado a tiempo" sin navegación) — CORRECTO.** Confirmado en vivo: clic no navega
  a ningún lado (permanece en `/gerencia`), tal como exige RN3 de la redefinición.
- **CA1/CA2/CA6 (origen) — CORRECTOS.** Los 4 indicadores existen con las etiquetas correctas;
  "Despachos urgentes"/"Despachos atrasados" navegan a URLs distintas y correctas
  (`?chip=urgentes` / `?chip=atrasados`); "Completados hoy" navega sin filtro.
- **CA4/CA5 — NO CUMPLE.** Ver "Hallazgo transversal #1": `PedidosPage.tsx` nunca lee `chip` de
  la URL (`useState<Chip>('all')` hardcodeado) — confirmado empíricamente, ambas URLs renderizan
  contenido idéntico.

### SCRUM-170 — REQ-108: Salud Servicios (redefinido por Daniela 2026-08-25) — ❌ NO PASA

- **CA2/CA5 ("Resuelto en 1ra visita" eliminado) — CORRECTO.** Confirmado: 0 ocurrencias en
  pantalla, quedan solo 2 indicadores.
- **CA1/CA3 (origen) — CORRECTOS.** Navegan a `/servicios/tickets?estado=reportado` y
  `?estado=resuelto` respectivamente.
- **CA4 — NO CUMPLE.** `TicketsPage.tsx` inicializa `filters` en `EMPTY_TICKET_FILTERS`, nunca lee
  `estado` de la URL. Confirmado: con `sin_responder=7` en el payload de Gerencia, la pantalla de
  destino muestra "Mostrando 20 de 20 tickets" (sin filtrar).

### SCRUM-171 — REQ-109: Panel "Agenda de Bodega — hoy" — ❌ NO PASA

Sin cambios desde v6:
- **RN2 ("Ver detalle" → "Rutas de entrega del día completo") — SIGUE ROTO.** Sigue navegando a
  `/bodega/pedidos` (lista genérica); no existe ninguna pantalla de "rutas de entrega" en el
  código (grep sin resultados en todo `src/`).
- **RN1/RN3 — sin datos para ejercitar, 3ra sesión consecutiva (v5→v6→v7).** `agendas.bodega=[]`
  en dev.atlanticerp.ai hoy también. El fix de código de RN3 (`?order=<id>` con `item.id` real) sigue
  presente sin cambios, pero nadie ha podido confirmarlo end-to-end con datos reales en 3 pasadas
  seguidas. Recomendación explícita para quien tome este ticket: sembrar al menos 1 movimiento de
  Bodega programado para "hoy" (fecha dinámica, no fija) antes de la próxima verificación, o
  aceptar que este RN se valide solo por código hasta que exista un mecanismo de seed relativo a
  la fecha actual.

---

## Commits locales sin push

`atlanticerp-frontend`, rama `dev`: 1 commit nuevo con este reporte + el spec Playwright v7 (ver mensaje
de commit). **No se pusheó** — gate estándar de `git push`, requiere aprobación individual de
Luis.

## Para escalar a Arquitecto (no son fixes menores)

1. **SCRUM-159 RN1** — filtro de Vendedor en Facturación es arquitectónicamente imposible con el
   modelo actual (`AdminContInvoice` sin FK a vendedor). Decidir: ¿agregar la relación, o redefinir
   RN1 para excluir Facturación explícitamente (ya es el comportamiento de facto)?
2. **SCRUM-161 + SCRUM-162 (hallazgo transversal #2)** — el mecanismo de aprobación desde Gerencia
   necesita rediseño: no filtra por tipo de solicitud (SCRUM-161 RN2), no reutiliza las
   validaciones/autorización de cada módulo de origen (bypass del gate "solo Mark" de
   `PurchaseOrderController`, SCRUM-206), y escribe valores de enum inválidos en 4 de 5 tipos
   (confirmado en vivo con datos reales en dev.atlanticerp.ai). Recomendado: decidir si Gerencia debe
   delegar a los controllers/servicios reales de cada módulo en vez de duplicar lógica.
3. **Patrón "querystring que el destino no consume"** (SCRUM-166 RN2/RN3-4, SCRUM-167 CA4,
   SCRUM-169 CA4-5, SCRUM-170 CA4) — 5 ocurrencias del mismo gap en 4 tickets distintos. Vale la
   pena evaluar un mecanismo compartido (hook/utilidad común para leer filtros iniciales desde
   `searchParams`) en vez de seguir corrigiendo cada pantalla por separado.
