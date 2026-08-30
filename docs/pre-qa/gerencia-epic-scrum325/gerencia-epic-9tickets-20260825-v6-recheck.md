# Pre-QA adversarial — Epic Gerencia (SCRUM-325), RE-VERIFICACIÓN v6

**Fecha:** 2026-08-25
**Ambiente:** `dev.atlanticerp.ai`, tras 2 commits nuevos del 2026-08-24 18:59 (mismo minuto, backend+frontend):
- `9f82409` (atlanticerp-backend) — agrega `GET /gerencia/vendors`, `GET /gerencia/clients`, y campos
  `margen_real_pct`/`margen_cotiz_pct` en `variacion_margen` (**hardcodeados a `0.0`, TODO
  explícito en el código** — "pendiente campo de costo").
- `572ddea` (atlanticerp-frontend) — puebla los `<select>` de Vendedor/Cliente con datos reales,
  agrega el subtítulo "Real X% · Cotizado Y%", cablea `item.id` en Agenda Bodega/Servicios
  (`?order=<id>` / `?ticket=<id>`), y agrega querystring a 3 de 7 métricas de Salud de módulo
  (`?stage=proposal`, `?order=days`, `?chip=critical`).

**Punto de partida:** `docs/pre-qa/gerencia-epic-12tickets-20260824-v5-recheck.md` — de los 12
tickets de esa pasada, 3 (SCRUM-157/158/165) ya están en `PM Review` y quedan fuera de este batch;
los 9 restantes son el alcance completo de esta re-verificación.

**Alcance:** rol Pre-QA — verificar en vivo contra `dev.atlanticerp.ai` (Playwright CLI + curl directo
contra la API, nunca Claude in Chrome) + lectura de código cuando el criterio no depende de dato
del día, y transicionar: cumple → `PM Review` (asignado a Daniela Amaya); no cumple → `In
Progress` con comentario explícito (asignado a Marly Rangel). **No se implementó ni pusheó ningún
fix** — por instrucción explícita para esta pasada, distinto de la práctica default de
`pre-qa.md` Paso 6; el fix lo hace el otro equipo. Acciones en Jira ejecutadas y confirmadas con
`jira_search_issues` tras cada transición.

---

## Veredicto final — 9 tickets

| Ticket | Veredicto v5 | Veredicto v6 | Cambio | Transición ejecutada |
|---|---|---|---|---|
| SCRUM-156 (Variación margen) | ❌ NO PASA | ❌ NO PASA | Sin cambio real (campos agregados pero hardcodeados) | → `In Progress`, Marly |
| SCRUM-159 (Panel Facturación) | ❌ NO PASA | ❌ NO PASA | Mejora parcial (selects poblados) + 1 hallazgo NUEVO | → `In Progress`, Marly |
| SCRUM-160 (Panel Proyectos) | ❌ NO PASA | ❌ NO PASA | Sin cambio | → `In Progress`, Marly |
| SCRUM-161 (Aprobaciones — listado) | ❌ NO PASA | ❌ NO PASA | Sin cambio (commit no tocó este código) | → `In Progress`, Marly |
| SCRUM-162 (Aprobaciones — detalle/acción) | ❌ NO PASA | ❌ NO PASA | Sin cambio — ahora confirmado con Mark real (antes bloqueado por password) | → `In Progress`, Marly |
| SCRUM-166 (Salud Ventas&Diseño) | ❌ NO PASA | ❌ NO PASA | Mejora aparente pero rota (mismatch de contrato query param) | → `In Progress`, Marly |
| SCRUM-168 (Salud Compras) | ❌ NO PASA | ❌ NO PASA | Mejora parcial + 1 hallazgo NUEVO (inconsistencia de conteo) | → `In Progress`, Marly |
| SCRUM-171 (Agenda Bodega) | ❌ NO PASA | ❌ NO PASA | RN3 corregido por código (mismo patrón que 172, no ejercitable en vivo hoy); RN2 sin cambio | → `In Progress`, Marly |
| SCRUM-172 (Agenda Servicios) | ❌ NO PASA (solo RN3) | ✅ **PASA** | RN3 corregido y confirmado en vivo con dato real | → `PM Review`, Daniela |

**1 de 9 pasa** (SCRUM-172 — primer ticket de todo el ciclo de re-verificación de este épica que
cierra limpio). **8 de 9 siguen sin pasar.**

---

## Detalle por ticket

### SCRUM-156 — Variación margen — ❌ NO PASA
- **CRÍTICO RN1 (sin cambio real):** `GerenciaHomeService::kpis()` ahora agrega
  `margen_real_pct`/`margen_cotiz_pct` al payload, pero ambos están **hardcodeados a `0.0`** con
  comentario `// TODO: requiere campo costo en AdminContInvoice` / `// TODO: requiere campo margin
  en PipelineCard` (líneas 109-110). Confirmado en vivo: con `facturado=$19,374.61` y
  `cotizado=$20,441.28` (ambos > 0), un cálculo real de margen no daría 0 — el campo es un
  placeholder, no un cálculo.
- **CRÍTICO RN3 (empeoró, ahora es engañoso):** el subtítulo pasó de mostrar solo montos ("Facturado
  $X · Cotizado $Y") a mostrar **también** "Real 0% · Cotizado 0%" — cumple la LETRA de "ambos %
  explícitos" pero muestra un dato falso/placeholder al usuario final como si fuera real, que es
  peor que no mostrarlo.
- **Sugerencia de fix concreta:** `GerenciaHomeService::kpis()` líneas 109-110 — antes de exponer
  el campo al frontend, o (a) implementar el cálculo real (requiere agregar columna de costo a
  `AdminContInvoice` y de margen a `PipelineCard`, tal como dice el propio TODO) o (b) si el cálculo
  real no es alcance de este batch, quitar el subtítulo "Real X% · Cotizado Y%" del frontend
  (`GerenciaPage.tsx` línea ~225) hasta que el dato sea real — mostrar un placeholder con apariencia
  de dato real es peor que la ausencia previa.
- Lo que sí funciona: tarjeta renderiza, colorea rojo/neutro según signo, navega sin error a
  `/admin-contab/facturacion`.

### SCRUM-159 — Panel Facturación — ❌ NO PASA
- **RESUELTO — RN1/Escenario 1 (selects):** los `<select>` Vendedor/Cliente ahora tienen opciones
  reales vía `GET /gerencia/vendors` (10 vendedores reales) y `GET /gerencia/clients` (clientes
  reales, paginado a 50 con búsqueda). Confirmado en vivo — mejora genuina, no solo cosmética.
- **CRÍTICO NUEVO — el filtro de Vendedor no tiene NINGÚN efecto sobre el panel de Facturación:**
  confirmado con curl comparando `GET /gerencia/home` vs `GET /gerencia/home?vendedor_id=39` —
  payload de `facturacion.kpis` **idéntico** en ambos casos. Causa raíz:
  `GerenciaHomeService::facturacion()` nunca recibe ni usa `$vendedorId` como parámetro —
  `AdminContInvoice` no tiene relación con vendedor (documentado en el propio docblock del método),
  así que el filtro es arquitectónicamente imposible con el modelo de datos actual. El Escenario 1
  del ticket dice literalmente "filtrar por vendedor → 4 indicadores y gráfico se recalculan" — con
  el select ahora poblado, un usuario real puede intentar este flujo y ver que no cambia nada,
  visible por primera vez ahora que el select tiene opciones para elegir.
- **CRÍTICO (sin cambio) RN2:** `facturacion.kpis.margen_anio_pct`/`margen_mes_pct` siguen
  hardcodeados en `0.0` (mismo patrón TODO que SCRUM-156, línea 160-161 de
  `GerenciaHomeService.php`).
- MEJORA no bloqueante (heredada de v5): 6 barras del chart clickeables → `/admin-contab/facturacion`,
  sin filtrar por el mes específico.
- **Sugerencia de fix concreta:** (1) para el filtro de vendedor sin efecto — si el ticket exige
  que Facturación responda a Vendedor, la relación necesita existir en el modelo (¿join vía
  proyecto/pipeline card asociado a la factura?); si no es alcance de este batch, quitar el select
  de Vendedor de la sección Facturación del frontend (dejarlo solo donde sí aplica — Proyectos) en
  vez de mostrar un control que no filtra nada. (2) `margen_anio_pct`/`margen_mes_pct` — mismo fix
  que SCRUM-156, líneas 160-161.

### SCRUM-160 — Panel Proyectos — ❌ NO PASA
- **CRÍTICO (sin cambio) RN3:** `proyectos.kpis.margen_anio_pct`/`margen_mes_pct` siguen
  hardcodeados en `0.0` (`GerenciaHomeService.php` líneas 219-220, mismo patrón TODO).
- A diferencia de Facturación, el filtro de Vendedor SÍ tiene efecto real en este panel
  (`proyectos()` usa `$vendedorId` en cada query) — no es el mismo problema que SCRUM-159.
- Lo que sí funciona: Escenario 1 (etiquetas/orden correcto — "Proyectos cerrados este año/mes"),
  barras clickeables → `/ventas-diseno/pedidos`.
- **Sugerencia de fix concreta:** mismo patrón que SCRUM-156/159 — `GerenciaHomeService.php`
  líneas 219-220, requiere campo `margin` en `PipelineCard` según el propio TODO del código.

### SCRUM-161 — Aprobaciones (listado) — ❌ NO PASA — GAP DE DISEÑO, sin cambio
El commit `9f82409` no tocó `pendingApprovalsSection()` — sigue siendo un único aprobador global
(`ComprasSettings.mark_approver_user_id`), sin consultar la tabla `ReglaAprobacion` (SCRUM-163)
que ya existe en el código. Confirmado en vivo con `whil@illuminations.com.pa`:
`is_approver=false`, `aprobaciones=[]`. Mismo hallazgo estructural que v3/v4/v5, sin variación.
- **Sugerencia de fix concreta:** `GerenciaHomeService::pendingApprovalsSection()`, línea ~380 —
  reemplazar el chequeo `$userId === $markApproverId` por una consulta a `ReglaAprobacion` que
  resuelva, por `type` de cada ítem (`purchase_order`, `relocation`, `adjustment`,
  `general_count`, `zona_libre`), qué rol/usuario está autorizado a verlo/aprobarlo.

### SCRUM-162 — Aprobaciones (detalle/acción) — ❌ NO PASA — confirmado con Mark real esta vez
A diferencia de v5 (bloqueado por password no-default de Mark), esta pasada usó la password real
confirmada `B1n4X_2026?` — login exitoso, `is_approver=true`, `pending_total=23`,
`aprobaciones.length=22` (dato real, no vacío).
- **CRÍTICO RN2 (confirmado con datos reales, no solo por código):** con 22 aprobaciones reales
  visibles para el aprobador real, **cero** botones "Aprobar"/"Rechazar" en toda la pantalla —
  `GerenciaPage.tsx` no tiene ningún componente de acción para estos ítems.
- **CRÍTICO RN3 (nuevo detalle, antes no confirmable):** cada ítem de `aprobaciones` trae
  `action_url` genérico (ej. `/compras/ordenes` para TODAS las órdenes de compra, sin importar el
  `id` de cada una) — no hay deep-link a la orden/solicitud puntual, a diferencia del patrón
  `?order=<id>` que este mismo batch sí implementó para Agenda Bodega/Servicios.
- **Sugerencia de fix concreta:** (1) agregar botones Aprobar/Rechazar en `GerenciaPage.tsx`,
  sección de aprobaciones, que llamen al endpoint de aprobación real de cada módulo de origen
  (`PurchaseOrderController`, `RelocationRequestController`, etc.) — RN4 exige que al resolver
  desaparezca del listado y se refleje en el módulo de origen. (2) en
  `GerenciaHomeService::pendingApprovalsSection()`, cambiar cada `action_url` de genérico a
  deep-link con el `id` real (ej. `/compras/ordenes?order={$po->id}` o equivalente en cada módulo,
  reusando el mismo mecanismo `?order=<id>`/`?ticket=<id>` ya implementado para Bodega/Servicios en
  este mismo commit).

### SCRUM-166 — Salud Ventas & Diseño — ❌ NO PASA
- **CRÍTICO NUEVO — mismatch de contrato en "En Final Stage":** ahora navega a
  `/ventas-diseno/pipeline?stage=proposal` (antes sin querystring), pero `PipelinePage.tsx` línea
  42 solo reconoce `params.get('stage') === 'approved'` — cualquier otro valor (incluido
  `proposal`) cae al chip `'all'`, mostrando las 6 columnas del pipeline en vez de solo "Propuesta".
  Confirmado en vivo: tras el clic, la columna "Lead" es visible junto con las demás.
- **CRÍTICO NUEVO — mismo problema en "Estancados":** navega con `?order=days`, pero en
  `PipelinePage.tsx` ese parámetro controla el **orden de sorting** (`days` vs `value`), no un
  filtro — y `days` ya es el default, así que el querystring no cambia nada visible. El chip real
  que filtra a "solo estancados" (`chip === 'stagnant'`) nunca se activa por ningún query param.
  Confirmado en vivo: mismas 6 columnas visibles tras el clic.
- **CRÍTICO (sin cambio) RN3/RN4:** "Monto cotizado en el mes"/"Monto aprobado del mes" navegan a
  `/ventas-diseno/quotes-list` y `/ventas-diseno/pedidos` respectivamente, sin ningún querystring
  de filtro por mes — el commit no tocó estas 2 métricas.
- **Sugerencia de fix concreta:** `GerenciaPage.tsx` — "En Final Stage" debe navegar con
  `?stage=approved` para activar el chip que YA existe (`final_stage`/`approved` en
  `PipelinePage.tsx`, aunque el nombre del chip es confuso: el chip `'final_stage'` filtra a
  `stage.id === 'proposal'`, revisar cuál de los 2 valores corresponde realmente al criterio del
  ticket antes de fijar el query param); o, más limpio, agregar a `PipelinePage.tsx` un
  reconocimiento explícito de `?stage=proposal` → `chip='final_stage'` y de `?filter=stagnant` →
  `chip='stagnant'` (nuevo query param, `order` ya está usado para otra cosa). "Monto
  cotizado/aprobado del mes" necesitan un filtro de fecha en `quotes-list`/`pedidos` que hoy no
  existe — confirmar si esas pantallas ya soportan algún filtro de mes antes de decidir el query
  param a usar.

### SCRUM-168 — Salud Compras — ❌ NO PASA
- **CRÍTICO (sin cambio) RN2/Escenario 1:** "Bajo stock sin ordenar" sigue sin existir como
  indicador — `GerenciaHomeService::saludModulos()` (sección Compras) no tiene ese campo, 0
  ocurrencias en frontend. Escenario 1 completo del ticket no ejecutable.
- **CRÍTICO NUEVO — inconsistencia de conteo en "Órdenes críticas":** ahora navega con
  `?chip=critical` a un filtro REAL (`PurchaseOrderController` usa `PurchaseOrder::isCritical()`,
  el mismo criterio que Logística "Retrasados") — mejora genuina de destino. Pero el número que
  muestra la tarjeta de Gerencia (**41**, de `saludModulos()` línea 259: `count()` de TODAS las
  órdenes en `STATUS_POR_APROBAR`, sin importar antigüedad) **no coincide** con el total que
  devuelve ese mismo filtro al hacer clic (**43**, confirmado vía
  `GET /api/compras/orders?chip=critical` → `meta.total=43`). Causa: `isCritical()` combina 2
  condiciones distintas (antigüedad en `POR_APROBAR` ≥ `CRITICAL_DAYS_POR_APROBAR` OR fecha de
  llegada estimada vencida) — no es lo mismo que "todas las POR_APROBAR". Un usuario que ve "41" en
  la tarjeta y hace clic esperando ver 41 filas ve 43.
- MEJORA confirmada: "Próximas a llegar" sigue yendo a `/compras/logistica` (correcto, de v5).
- **Sugerencia de fix concreta:** (1) para "Bajo stock sin ordenar" — agregar el indicador
  cruzando `InventoryKpiService` (chip `bajo_stock`/`sin_stock`, ya existe en
  `InventoryController.php` línea 222) con órdenes de compra activas por producto, tal como pide
  RN2 ("EXCLUYENDO productos con orden ya en camino"). (2) para el mismatch de conteo — en
  `GerenciaHomeService::saludModulos()` línea 259, reemplazar el `count()` de `STATUS_POR_APROBAR`
  por la misma lógica `isCritical()` que usa `PurchaseOrderController` (filtrar en PHP sobre el
  set, igual que hace el controller), para que el número de la tarjeta y el total del listado
  filtrado siempre coincidan.

### SCRUM-171 — Agenda Bodega — ❌ NO PASA
- **RESUELTO por código (RN3), no ejercitable en vivo hoy:** `GerenciaPage.tsx` ahora navega con
  `onClick={() => navigate(`/bodega/pedidos?order=${item.id}`)}` — exactamente el fix que v5 había
  identificado como pendiente. `PedidosPage.tsx` línea 85-94 confirma que consume `?order=<id>` y
  abre el modal de detalle de ESE pedido puntual (mismo mecanismo verificado en vivo para Servicios
  en este mismo batch, ver SCRUM-172). `dev.atlanticerp.ai` sigue sin entregas de Bodega sembradas para
  hoy (`agendas.bodega=[]`, confirmado por fetch), así que no se pudo ejercitar el flujo completo
  end-to-end (clic en fila real → ver el pedido correcto abrirse) — el hallazgo de v5 se da por
  corregido con alta confianza (mismo patrón exacto que SCRUM-172, que sí se confirmó en vivo), no
  con confirmación end-to-end.
- **CRÍTICO (sin cambio) RN2:** ni `/bodega/pedidos` ni ninguna otra ruta de Bodega implementan la
  pantalla "Rutas de entrega del día completo" que pide RN2 — sigue sin existir en el código (0
  resultados de grep).
- **Sugerencia de fix concreta (solo lo que sigue pendiente):** RN2 necesita una pantalla nueva de
  Bodega que muestre las rutas/entregas de TODO el día (no un pedido puntual) — no es un fix de una
  línea como RN3, requiere diseño de pantalla nuevo. Notificar a PM para que lo secuencie como
  ticket propio si no está en el alcance de este batch — no bloquear indefinidamente sin decisión
  explícita (ver "Regla dura" de `pre-qa.md`).

### SCRUM-172 — Agenda Servicios — ✅ PASA
- **RN3 corregido y confirmado en vivo:** a diferencia de v5 (sin datos ese día), hoy
  `dev.atlanticerp.ai` tiene 1 visita real sembrada (ticket `INS-2026-0002`, id=2, técnico Agustín
  Rodríguez, 19:00). Clic en la fila navegó a `/servicios/tickets?ticket=2` y el detalle del
  ticket `INS-2026-0002` específico quedó visible en pantalla — confirmado con Playwright, no solo
  por código.
- RN2 ya funcionaba desde v4/v5 (botón "Ver detalle" → `/servicios/inicio`, panel real "Rutas del
  día") — sin regresión.
- RN1 (hora, cliente, detalle/técnico por fila) — cumple, sin cambios respecto a pasadas
  anteriores, nunca fue un hallazgo bloqueante.
- **Sin hallazgos bloqueantes.** Primer ticket de todo el ciclo de re-verificación de este épica
  (v2→v6) que cierra completamente limpio.

---

## Verificación de credenciales (esta pasada)
- `whil@illuminations.com.pa` — password default (su propio email) — OK, usado en todos los tests.
- `mbekhar@illuminations.com.pa` (Mark, aprobador real) — password `B1n4X_2026?` — **login exitoso
  esta vez** (a diferencia de v5, que falló por asumir password default). Permitió confirmar
  SCRUM-162 con datos reales (`is_approver=true`, 22 aprobaciones pendientes reales) en vez de solo
  por lectura de código — evidencia más fuerte que en cualquier pasada anterior.
- `daniela@illuminations.com.pa` — no se necesitó para este batch (no hay tickets que dependan de
  su rol específico en los 9 de este batch).

---

## Entregables
1. Este reporte: `docs/pre-qa/gerencia-epic-9tickets-20260825-v6-recheck.md`.
2. Spec Playwright permanente:
   `atlanticerp-frontend/e2e/preqa-scrum-gerencia-9tickets-20260825-v6-recheck.spec.ts` — 17 tests,
   17 passed (0 fallos, 0 skips — a diferencia de v5, no hubo que saltar ningún test por falta de
   dato o password).
3. Transiciones+comentarios+reasignaciones en Jira — confirmadas con `jira_search_issues` tras
   cada transición (ver tabla de veredictos).

## Resumen conciso
**1 de 9 pasa:** SCRUM-172 (Agenda Servicios) — RN3 corregido y confirmado en vivo con dato real,
sin hallazgos bloqueantes. → `PM Review`/Daniela.

**8 de 9 no pasan** (todos a `In Progress`/Marly):
- SCRUM-156 — `margen_real_pct`/`margen_cotiz_pct` agregados al payload pero hardcodeados a 0
  (TODO server-side); el subtítulo ahora muestra ese 0% como si fuera un dato real.
- SCRUM-159 — selects Vendedor/Cliente ahora poblados (mejora real), pero el filtro de Vendedor no
  tiene NINGÚN efecto sobre Facturación (AdminContInvoice sin relación a vendedor — arquitectónico,
  no solo pendiente) + margen año/mes sigue hardcodeado en 0%.
- SCRUM-160 — margen cotizado año/mes sigue hardcodeado en 0%.
- SCRUM-161 — gap de diseño sin cambio: aprobador único global, no por tipo de solicitud.
- SCRUM-162 — confirmado con Mark real (22 aprobaciones reales visibles): sigue sin existir botón
  Aprobar/Rechazar, y cada ítem navega a la lista genérica del módulo, no al registro puntual.
- SCRUM-166 — 2 de 5 métricas ahora navegan con querystring, pero la pantalla destino
  (`PipelinePage.tsx`) no reconoce esos valores — el filtro no se aplica pese a la URL cambiar; las
  otras 2 (monto cotizado/aprobado del mes) siguen sin ningún filtro.
- SCRUM-168 — sigue faltando el indicador "Bajo stock sin ordenar"; "Órdenes críticas" ahora
  navega a un filtro real pero el conteo de la tarjeta (41) no coincide con el total del filtro
  (43) — definiciones distintas en el backend.
- SCRUM-171 — fix de `item.id` implementado por código, mismo patrón exacto que SCRUM-172 (que sí
  se confirmó en vivo), pero sin datos de agenda hoy para ejercitarlo end-to-end; la pantalla
  "Rutas de entrega del día" que pide RN2 sigue sin existir.
