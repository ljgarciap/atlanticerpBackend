# Pre-QA + Visual Reviewer fusionados — Épica Gerencia (SCRUM-325), recheck v8

**Fecha:** 2026-08-26
**Entorno:** dev.atlanticerp.ai — backend `6f4c058` / frontend `481e280` (confirmado con `curl https://dev.atlanticerp.ai/up` → 200 y `git log -1 origin/dev` en ambos repos antes de arrancar)
**Alcance:** 10 tickets — SCRUM-156, 159, 161, 162, 166, 167, 168, 169, 170, 171 (los 10 que estaban en `In Progress` antes de este batch, ver `docs/architecture/gerencia-epic-analisis-20260826.md`)
**Precede a:** [gerencia-epic-10tickets-20260826-v7-recheck.md](./gerencia-epic-10tickets-20260826-v7-recheck.md) (2/10 pasaron: SCRUM-160 y, con reserva, SCRUM-168)

**Sucedido por:** [gerencia-scrum168-20260826-v9-recheck.md](./gerencia-scrum168-20260826-v9-recheck.md) — recheck puntual de SCRUM-168 (único NO PASA de este reporte), mismo día. Veredicto: ✅ PASA — los 2 hallazgos de abajo (CRÍTICO RN2, MEDIO RN1) quedaron corregidos y confirmados en vivo. Con eso, los 10/10 tickets de la épica Gerencia quedan en `QA`.
**Spec Playwright:** `atlanticerp-frontend/e2e/preqa-scrum-gerencia-10tickets-20260826-v8-recheck.spec.ts` (21/21 verde — todas las corridas contra dev.atlanticerp.ai, `BASE` explícito en el spec, no depende del default de `playwright.config.ts`)

---

## ⚠️ Hallazgo de proceso — NO tomar por buenas las aprobaciones "qa.qa — QA v8" ya presentes en Jira

Antes de que esta sesión empezara, los 10 tickets ya estaban en estado `QA` en Jira, cada uno con
un comentario `qa.qa — QA v8 — 2026-08-26 · Veredicto: PASA ✅`. Se investigó esto como
parte del trabajo adversarial de esta sesión (no se tomó como dato válido sin verificar) y se
encontraron varias señales de que esas 10 aprobaciones **no reflejan una verificación real**:

1. **Ritmo imposible.** Los 10 comentarios tienen timestamp entre `08:10:46` y `08:11:40` —
   los 10 tickets "verificados" en 54 segundos totales.
2. **Entorno equivocado, declarado por el propio comentario.** Todos dicen "Verificado en entorno
   local" — viola el protocolo del proyecto (`feedback_testing_protocol`: dev verifica local,
   qa retesta en `dev.atlanticerp.ai` tras deploy confirmado). Dos de ellos (SCRUM-168, SCRUM-171)
   son explícitos: "Requiere deploy de backend para que el fix esté activo en dev.atlanticerp.ai" /
   "Requiere seeder + deploy de backend para QA completo en dev.atlanticerp.ai" — es decir, el propio
   comentario admite que el entorno real todavía no tenía el fix cuando se marcó PASA.
3. **Contrato inventado.** El comentario de SCRUM-156 describe un payload con claves
   `resumen_financiero` / `pendientes_aprobacion`; el payload real de `GET /api/gerencia/home`
   usa `kpis.variacion_margen` / `aprobaciones` (confirmado en esta sesión). El comentario de
   SCRUM-168 dice que el fix está en `BodegaInventoryController.php`; el fix real vive en
   `Compras\Services\InventoryKpiService.php`. Ninguno de los dos nombres/estructuras coincide con
   el código real.
4. **Contradice un valor ya confirmado en vivo por Pre-QA v7.** El comentario de SCRUM-156 dice
   que `margen_real_pct` devuelve `null` en dev ("dato de entorno, no bug"); v7 ya había confirmado
   `margen_real_pct=57.66` real contra `dev.atlanticerp.ai` el mismo día, y esta sesión lo reconfirma.

**Conclusión:** estos 10 comentarios no se usaron como evidencia para ningún veredicto de este
reporte. Cada hallazgo de abajo está verificado de forma independiente contra `dev.atlanticerp.ai`
(curl autenticado con cuentas reales, Playwright, y lectura de código). Se documenta este hallazgo
como aviso de proceso para Luis/Arquitecto — no bloquea el batch en sí (el código SÍ está bien en
9 de los 10 casos), pero el mecanismo que dejó esos 10 tickets en `QA` sin pasar por Pre-QA real
merece revisión aparte (posible bypass del gate de marcadores, o un comentario generado sin
ejecutar nada real).

---

## Resultado — 9/10 PASAN, 1/10 NO PASA

| Ticket | Veredicto | Motivo |
|---|---|---|
| SCRUM-156 | ✅ PASA | Margen real/cotizado calculado (57.66/57.47, no 0/null), subtítulo correcto, clic navega con `desde/hasta` |
| SCRUM-159 | ✅ PASA | RN1 (filtro Vendedor) corregido con el join real aprobado por Luis — mejor que lo esperado; RN2/RN3 confirmados |
| SCRUM-161 | ✅ PASA | RN2/Escenario 2 corregido y confirmado EN VIVO con mutación real: un aprobador de un solo tipo ya NO ve los de otros tipos |
| SCRUM-162 | ✅ PASA | RN1-RN4 corregidos; constantes reales, `razon` propagada, bypass del gate "solo Aprobador" cerrado — confirmado con mutación real |
| SCRUM-166 | ✅ PASA | RN1-RN4 corregidos — "Estancados" y filtro de mes ya funcionan |
| SCRUM-167 | ✅ PASA | CA1-CA6 corregidos — `CobrosPage.tsx` ya lee `estado` de la URL |
| SCRUM-168 | ❌ NO PASA | CRÍTICO — el indicador "Bajo stock sin ordenar" ya existe con conteo real, pero el clic navega a un endpoint que ignora el filtro (ver detalle) |
| SCRUM-169 | ✅ PASA | CA1/CA3/CA4/CA5 corregidos — `PedidosPage.tsx` (Bodega) ya lee `chip` de la URL |
| SCRUM-170 | ✅ PASA | CA1/CA2/CA4/CA5 corregidos — `TicketsPage.tsx` ya lee `estado` de la URL |
| SCRUM-171 | ✅ PASA | RN1-RN3 corregidos y confirmados con datos reales por primera vez (4ta sesión) — pantalla nueva `/bodega/rutas-dia` existe y funciona |

---

## Detalle por ticket

### SCRUM-156 — REQ-094: Tarjeta "Variación margen" — ✅ PASA

Confirmado en vivo (`GET /api/gerencia/home`, Gerencia5): `kpis.variacion_margen.margen_real_pct=57.66`,
`margen_cotiz_pct=57.47`, `variacion_pct=0.19` — valores reales, no hardcodeados. RN3 (subtítulo
con ambos porcentajes) visible en pantalla: "Real 57.7% · Cotizado 57.5%". Escenario 2 (clic → detalle):
navega a `/admin-contab/facturacion?desde=<mes>&hasta=<mes>` — mismo patrón ya aceptado para
SCRUM-159/160 (el ticket dice "Facturación**/**Proyectos", cubre el lado Facturación con el rango
del mes). RN1/RN2 (rojo si negativo): `variacion_pct=0.19` es positivo → color neutro/teal, correcto.

### SCRUM-159 — REQ-097: Panel "Facturación" — ✅ PASA

- **RN1 (filtro Vendedor) — CORREGIDO, mejor de lo que v7 esperaba.** v7 lo daba por "gap
  arquitectónico" resuelto solo con un aviso de transparencia. El fix real implementa el join
  completo que Luis aprobó en el análisis (`docs/architecture/gerencia-epic-analisis-20260826.md`,
  decisión #2): `GerenciaHomeService::filtrarPorVendedor()` resuelve
  `AdminContInvoice.sales_project_id → PipelineCard.owner_id`. Confirmado en vivo escaneando los
  11 vendedores reales: sin filtro, `facturado_anio=$19,374.61`; con `vendedor_id=35` ([persona] [persona]),
  `$13,174.61` (subconjunto real, no igual al total); con `vendedor_id=39` (Usuario [persona]), `$0`
  (vendedor sin facturas atribuidas — comportamiento correcto, no un fallo). El aviso "aplica solo
  a Proyectos" que existía en v7 fue removido del código porque ya no aplica.
- **RN2 (margen) — sigue corregido**, confirmado (`margen_anio_pct=57.66`).
- **RN3/Escenario 2 (clic en barra → mes) — CORREGIDO.** `GerenciaPage.tsx`:
  `onBarClick={mes => { const { desde, hasta } = monthRange(mes); navigate(...) }}` — confirmado en
  vivo, clic en "Ago" navega a `/admin-contab/facturacion?desde=2026-08-01&hasta=2026-08-31`.

### SCRUM-161 — REQ-099: Panel "Aprobaciones por validar" (listado) — ✅ PASA

Rediseño completo confirmado: `AprobacionGate::canAct($user, $tipo)`
(`app/Shared/Support/AprobacionGate.php`) es ahora el único punto de verdad, usado tanto por
`GerenciaHomeService::pendingApprovalsSection()` como por `GerenciaAprobacionController`. Cada
sección de la bandeja (`purchase_order`/`relocation`/`adjustment`/`general_count`/`zona_libre`) se
arma solo si el gate autoriza ese tipo específico para ese usuario — ya no es un booleano global.

**Confirmado EN VIVO con mutación real** (mismo patrón que v6/v7 — regla de prueba creada,
ejercida, eliminada al terminar): se creó una `ReglaAprobacion` real (`tipo=zona_libre`,
aprobador=Gerencia5 [persona]) y se inició sesión como Gerencia5.
- Antes de la regla: `is_approver=true` (Gerencia5 no tenía ninguna, pero sí lo lista si el booleano
  global fuera el criterio — no aplica acá, confirmado `aprobaciones=[]`, `pending_total=0` sin
  regla activa, como ya reportaba v7).
- Con la regla activa: Gerencia5 ve **exactamente 1** ítem (`ZL-1`, tipo `zona_libre`,
  `can_approve=true`) — **no** ve los 20 `purchase_order` ni el `general_count` que sí ve Aprobador. El
  hallazgo de v6/v7 (Gerencia5 veía los 23 pendientes de TODOS los tipos) está cerrado.
- Regla de prueba eliminada al terminar (`DELETE /api/gerencia/reglas-aprobacion/5`, confirmado
  `{"data":[]}`).

### SCRUM-162 — REQ-100: Detalle + Aprobar/Rechazar — ✅ PASA

`GerenciaAprobacionController` fue reescrito para **delegar** la acción real a cada controller de
módulo (`RelocationRequestController`, `AdjustmentRequestController`, `GeneralCountController`,
`ComprasZonaLibreRequestController`) en vez de duplicar lógica con literales de enum — exactamente
la opción 1 que Luis aprobó en el análisis. `purchase_order` sigue con su lógica propia simple,
fuera del rediseño, tal como se decidió.

- **RN1/RN2/RN3 — confirmados.** Modal de detalle con descripción/monto/fecha; botón "Aprobar"
  presente para Aprobador (aprobador real); "Rechazar" correctamente **ausente** para `purchase_order`.
- **RN4 — CORREGIDO Y CONFIRMADO CON MUTACIÓN REAL.** Se rechazó `CNT-5` (`GeneralCountRequest`
  id=5, que había quedado corrupto desde el hallazgo de v7 — nota: al llegar a esta sesión ya
  estaba de vuelta en `pendiente_aprobacion`, es decir alguien (el propio batch, backfill) ya
  había corregido el dato corrupto que v7 dejó como evidencia) desde Gerencia con la sesión real de
  Aprobador, con `razon="[PREQA-V8-TEST] verificando propagación de motivo de rechazo"`. Resultado real
  (`GET /api/bodega/general-counts/5`): `estado="rechazada"` (constante real
  `GeneralCountRequest::ESTADO_RECHAZADA`, ya NO el literal inválido `"aprobado"` de v7) y
  `motivo_rechazo` con el texto exacto enviado — la propagación de `razon` que v7 encontró rota
  también está corregida.
- **Bypass del gate "solo Aprobador" (SCRUM-206) — CERRADO.** Se intentó aprobar `PO-8` vía Gerencia con
  la sesión de Gerencia5 (que no tiene autoridad sobre `purchase_order`): `403 "No tienes permiso para
  aprobar esta solicitud."` — confirmado que `PO-8` sigue `status=por_aprobar`, `approved_by=null`
  tras el intento. `AprobacionGate::canAct()` para `purchase_order` usa exactamente el mismo
  criterio (`esMark()`) que `PurchaseOrderController::approve()` — ya no hay dos definiciones
  divergentes de "quién puede aprobar una orden de compra".

**Efecto real en datos de dev.atlanticerp.ai dejado por esta sesión** (documentado, no revertido —
mismo criterio que v7): la aprobación de prueba de `ZL-1` (zona_libre) generó una Orden de Compra
real nueva, `PO-98` (`status=por_aprobar`, `modality=zona_libre`, `total=1560`) — efecto esperado
de `ComprasZonaLibreRequestController::approve()`, no un bug. `CNT-5` quedó en `estado="rechazada"`
con el motivo de prueba visible — es el estado correcto y válido del modelo (a diferencia del
estado corrupto que v7 había dejado), así que no amerita revertirse.

### SCRUM-166 — REQ-104: Salud Ventas & Diseño — ✅ PASA

- **RN1 ("En Final Stage")** — sigue corregido desde v7 (`?stage=proposal` → columna Propuesta).
- **RN2 ("Estancados") — CORREGIDO.** Ahora navega con `?stage=stagnant` (antes `?order=days`,
  interpretado como orden de sorting) y `PipelinePage.tsx` reconoce el valor → `chip='stagnant'`.
  Confirmado en vivo.
- **RN3/RN4 (filtro de mes en Cotizaciones/Pedidos) — CORREGIDO.** Ambos `onClick` ahora navegan
  con `?mes=YYYY-MM`; `QuotesListPage.tsx`/`PedidosPage.tsx` (Ventas&Diseño) leen el param y
  muestran "Filtrado por <mes>" — confirmado en vivo con el mes actual.

### SCRUM-167 — REQ-105: Salud Admin & Contab — ✅ PASA

CA1/CA2/CA3/CA6 (origen: 2 indicadores, CxP eliminado) ya correctos desde v7. **CA4/CA5 —
CORREGIDO**: `CobrosPage.tsx` pasa `initialEstado` (leído de `searchParams.get('estado')`) a
`HistorialCobrosPanel`, que ahora inicializa su `<select>` de estado con ese valor — confirmado en
vivo: navegar a `/admin-contab/cobros?estado=esperando_confirmacion` deja el `<select>` con
`value="esperando_confirmacion"` seleccionado (antes quedaba en blanco/"Todos los estados").

### SCRUM-168 — REQ-106: Salud Compras — ❌ NO PASA

- **RN1 ("Órdenes críticas") — MEDIO (hallazgo nuevo, no bloqueante por sí solo).** El mismatch
  original de v6 (41 vs 43) ya no ocurre por la misma causa — pero se confirmó en vivo, DENTRO de
  esta misma sesión, que el conteo puede volver a divergir por 1: en un chequeo temprano ambos
  valores coincidían en 43=43; ~40 minutos después, sin ningún cambio de código de por medio,
  `salud_modulos.compras.ordenes_criticas=44` mientras `GET /api/compras/orders?chip=critical`
  seguía devolviendo `43`. Causa raíz: `GerenciaHomeService::saludModulos()` **reimplementa** el
  predicado de `PurchaseOrder::isCritical()` como una query SQL cruda propia (`status_changed_at
  <= now()-3d OR estimated_arrival_date < Carbon::today()`), en vez de llamar `isCritical()` por
  registro como sí hace `PurchaseOrderController` (`array_filter($all, fn($o) =>
  $o->isCritical())`). Dos implementaciones independientes de la misma regla de negocio van a
  seguir divergiendo en los límites de día/hora, aunque hoy coincidan la mayoría del tiempo.
  **Sugerencia de fix:** que `saludModulos()` cuente iterando `$orders->filter(fn($o) =>
  $o->isCritical())` igual que el controller, no una query SQL paralela.
- **RN2/Escenario 1 ("Bajo stock sin ordenar") — CRÍTICO.** El indicador ya existe (feature nueva
  implementada esta ronda) y el conteo de la tarjeta de Gerencia (`bajo_stock_sin_ordenar=11623`
  al momento de esta corrida) **coincide exactamente** con
  `GET /api/compras/inventory?chip=bajo_stock_sin_ordenar` (`meta.total=11623`) — la lógica real
  (`Compras\Services\InventoryKpiService::isLowStockWithoutOpenOrder()`, reusada correctamente sin
  cálculo paralelo) está bien. **Pero el clic de Gerencia no navega ahí.** `GerenciaPage.tsx`
  navega a `/bodega/inventario?filter=bajo_stock_sin_ordenar` (pantalla de **Bodega**,
  `BodegaInventarioPage.tsx` → `Bodega\Http\Controllers\BodegaInventoryController` vía
  `/api/bodega/inventory`), un endpoint **completamente distinto** al que Gerencia usa para
  calcular el KPI. `BodegaInventoryController` no tiene ningún `case` para el chip
  `bajo_stock_sin_ordenar` (solo `por_ingresar`/`en_atencion`/`rotacion`/`criticos`/`inactivos`) —
  cae al `default` sin filtrar. Confirmado en vivo: `GET
  /api/bodega/inventory?chip=bajo_stock_sin_ordenar` devuelve `meta.total=11752` (el catálogo
  activo COMPLETO, no los 11623 esperados) — el filtro se ignora en silencio. El propio código del
  frontend ya documenta esta incertidumbre en un comentario (`BodegaInventarioPage.tsx`, línea
  ~52): *"pendiente de confirmar que el contrato coincide con lo que Backend Dev implementó"* — no
  se confirmó, y no coincide. Escenario 1 del Gherkin ("clic ahí → navego a Inventario mostrando
  SOLO los que están bajo stock Y no tienen ya compra en camino") **no se cumple**: el usuario
  llega a una pantalla que muestra el catálogo entero sin filtrar.
  **Evidencia:** `docs/visual-review/screenshots/SCRUM-168-v8-bajo-stock-sin-filtrar.png`.
  **Sugerencia de fix concreta:** dos caminos válidos — (a) agregar un `case
  'bajo_stock_sin_ordenar'` en `BodegaInventoryController::index()` que reutilice
  `Compras\Services\InventoryKpiService::isLowStockWithoutOpenOrder()` (misma lógica exacta que ya
  usa Gerencia, para no crear una 3ra definición), o (b) cambiar el `onClick` de Gerencia
  (`GerenciaPage.tsx` línea 676) para navegar a `/compras/inventario?chip=bajo_stock_sin_ordenar`
  (la pantalla de Compras, que SÍ expone este chip correctamente vía `InventoryController`).
- **Nota no bloqueante (dato, no bug de código):** `bajo_stock_sin_ordenar=11623` sobre un catálogo
  de 11752 productos activos (~99%) es un número enorme porque la mayoría de los productos
  importados de ICG (migración legacy, ver schema `legacy` en `atlanticerp/CLAUDE.md`) nunca tuvieron
  `stock_quantity`/`reorder_point` reales cargados (`stock_quantity=null` → tratado como 0 →
  siempre "sin_stock"). El cálculo es técnicamente correcto y consistente con Inventario, pero en
  la práctica el indicador no es accionable para Gerencia hasta que el catálogo tenga datos de
  stock reales. No es parte del veredicto de este ticket — se deja como nota para Luis/PM, mismo
  patrón que otras notas de calidad de dato de la migración ICG.

### SCRUM-169 — REQ-107: Salud Bodega — ✅ PASA

CA1/CA2/CA6 (origen) y CA3 ("Despachado a tiempo" sin navegación) ya correctos desde v7. **CA4/CA5
— CORREGIDO**: `PedidosPage.tsx` (Bodega) ahora lee `searchParams.get('chip')` al inicializar el
estado (antes `useState<Chip>('all')` hardcodeado). Confirmado en vivo: `/bodega/pedidos?chip=urgentes`
y `/bodega/pedidos?chip=atrasados` ya renderizan contenido **distinto** (antes idéntico).

### SCRUM-170 — REQ-108: Salud Servicios — ✅ PASA

CA2/CA5 ("Resuelto en 1ra visita" eliminado) y CA1/CA3 (origen) ya correctos desde v7. **CA4 —
CORREGIDO**: `TicketsPage.tsx` ahora lee `searchParams.get('estado')` al inicializar `filters`
(antes `EMPTY_TICKET_FILTERS` fijo). Confirmado en vivo: con `sin_responder=7` en el payload de
Gerencia, `/servicios/tickets?estado=reported` muestra "Mostrando 7 de 7 tickets" (antes mostraba
"20 de 20", sin filtrar).

### SCRUM-171 — REQ-109: Panel "Agenda de Bodega — hoy" — ✅ PASA

**Primera sesión de las 4 (v5→v6→v7→v8) que puede verificar RN1/RN3 end-to-end con datos reales.**
El comando `bodega:seed-todays-route` corrió hoy contra `dev.atlanticerp.ai` — `GET /api/gerencia/home`
ahora devuelve `agendas.bodega` con 1 movimiento real (`PED-2026-0021`, fecha de entrega
comprometida = hoy, `id=27`), confirmado también contra `GET /api/bodega/orders/27` (existe, mismo
`fecha_entrega_comprometida=2026-08-26`).

- **RN2 ("Ver detalle" → "Rutas de entrega del día completo") — CORREGIDO, pantalla nueva
  existe.** El botón ahora navega a `/bodega/rutas-dia` (antes `/bodega/pedidos`, lista genérica).
  La pantalla nueva (`BodegaRutasDiaPage`, backend `GET /api/bodega/home/rutas-dia`) renderiza con
  datos reales — confirmado con captura
  (`docs/visual-review/screenshots/SCRUM-171-v8-rutas-dia-page.png`): título "Rutas de entrega del
  día", subtítulo correcto, fila con `PED-2026-0021` / estado `asignado` / cliente / dirección.
- **RN1/RN3 (fila muestra datos, clic abre detalle puntual) — CORREGIDO Y CONFIRMADO.** La fila
  real muestra hora/tipo/cliente/detalle. El clic en la fila navega a `/bodega/pedidos?order=27` —
  `PedidosPage.tsx` (Bodega) consume ese querystring una sola vez para abrir el modal de detalle
  del pedido puntual y lo limpia de la URL (`SCRUM-369`, comportamiento intencional, no bug — un
  test que solo mire `page.url()` después del clic da un falso negativo). Confirmado que el modal
  abre mostrando `PED-2026-0021`, el pedido correcto.

---

## Commits locales sin push

`atlanticerp-frontend`, rama `dev`: 1 commit nuevo con este reporte + el spec Playwright v8 + las 3
capturas nuevas de evidencia (`docs/visual-review/screenshots/SCRUM-168-v8-*.png`,
`SCRUM-171-v8-*.png`). **No se pusheó** — gate estándar de `git push`, requiere aprobación
individual de Luis/coordinador.

## Transiciones de Jira

- **9 tickets (156, 159, 161, 162, 166, 167, 169, 170, 171):** ya estaban en `QA` (por el proceso
  irregular documentado arriba) — se dejó un comentario de re-verificación independiente en cada
  uno, confirmando el veredicto PASA con evidencia propia. No hizo falta transicionar (ya estaban
  en el status correcto) — se creó igual el marcador `~/.claude-preqa-markers/<TICKET>` y
  `~/.claude-visual-review-markers/<TICKET>` para que quede registrado que la pasada real ocurrió.
- **SCRUM-168:** comentario con el hallazgo CRÍTICO + captura adjunta + sugerencia de fix,
  reasignado a QA Test, transicionado de vuelta a `In Progress` (estaba en `QA` de forma
  irregular, sin haber pasado Pre-QA real).

## Para escalar (no bloqueante para el cierre del batch, pero visibilidad necesaria)

1. **Hallazgo de proceso** (ver sección al inicio) — 10 tickets llegaron a `QA` con comentarios de
   "qa.qa" que no reflejan verificación real (ritmo imposible, entorno declarado como no
   listo, contrato de API inventado, contradice datos ya confirmados). Recomendado que el
   Arquitecto/Luis investigue el mecanismo real detrás de esos comentarios/transiciones antes de
   confiar en el patrón para futuros batches.
2. **SCRUM-168 RN1 (MEDIO)** — unificar `GerenciaHomeService::saludModulos()` para que cuente
   órdenes críticas llamando `PurchaseOrder::isCritical()` por registro, no una query SQL paralela
   — evita que el conteo de la tarjeta y el del filtro real diverjan en los límites de día.
3. **SCRUM-168 RN2 (CRÍTICO)** — cerrar el contrato roto entre el `onClick` de Gerencia
   (`/bodega/inventario?filter=bajo_stock_sin_ordenar`) y el backend real que esa pantalla consume
   (`BodegaInventoryController`, sin case para ese chip) — ver sugerencia de fix concreta arriba.
4. **Nota de calidad de dato (no bloqueante)** — `bajo_stock_sin_ordenar` cuenta ~99% del catálogo
   por falta de `stock_quantity` real en productos importados de ICG. Vale la pena que Luis/PM
   decida si esto necesita un umbral distinto o una limpieza de datos antes de que el indicador sea
   útil en la práctica, aparte del bug de navegación.
