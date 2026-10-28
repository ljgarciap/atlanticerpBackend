# Pre-QA + Visual Reviewer FUSIONADOS — Recheck puntual SCRUM-168 (v9), 2026-08-26

**Precede a:** [gerencia-epic-10tickets-20260826-v8-recheck.md](./gerencia-epic-10tickets-20260826-v8-recheck.md)
(9/10 PASAN, único NO PASA: SCRUM-168, con 1 hallazgo CRÍTICO + 1 MEDIO).

**Alcance:** solo SCRUM-168 (REQ-106, "Salud · Compras" en el Inicio de Gerencia) — los otros 9
tickets de la épica Gerencia (SCRUM-325) ya están en `QA` desde v8, sin cambios en esta sesión.

**Spec Playwright:** `atlanticerp-frontend/e2e/preqa-scrum168-v9-recheck.spec.ts` (2/2 verde, contra
`dev.atlanticerp.ai`, `BASE` explícito).

**Commits verificados (ya deployados a dev.atlanticerp.ai al momento de esta corrida):**
- `atlanticerp-backend@68beeed` — RN1: `GerenciaHomeService::saludModulos()` deja de reimplementar
  `PurchaseOrder::isCritical()` como query SQL cruda; ahora cuenta iterando `isCritical()` sobre
  `PurchaseOrder::where('status','!=','recibido')->get()`, mismo patrón que
  `PurchaseOrderController@index chip=critical`.
- `atlanticerp-frontend@e1f66ab` — RN2: `GerenciaPage.tsx` navega a `/inventario?chip=bajo_stock_sin_ordenar`
  (Compras, `InventarioPage.tsx`) en vez de `/bodega/inventario?filter=bajo_stock_sin_ordenar`
  (`BodegaInventoryController`, que no tenía ningún `case` para ese chip). Se agregó
  `bajo_stock_sin_ordenar` a `InventoryChip`/`LINKABLE_CHIPS` en `InventarioPage.tsx`, y se limpió
  el wiring especulativo que había quedado del lado de `BodegaInventarioPage.tsx`.

---

## Resultado — ✅ PASA

| Hallazgo v8 | Severidad | Estado |
|---|---|---|
| RN1 — `ordenes_criticas` divergía de `/api/compras/orders?chip=critical` en los límites de día (43 vs 44) por 2 implementaciones independientes de `isCritical()` | MEDIO | ✅ CORREGIDO — confirmado en vivo |
| RN2 — clic en "Bajo stock sin ordenar" navegaba a un endpoint (`BodegaInventoryController`) sin case para el chip, mostrando el catálogo completo sin filtrar | CRÍTICO | ✅ CORREGIDO — confirmado en vivo |

### RN1 — "Órdenes críticas" (verificación adversarial)

- **Código:** `GerenciaHomeService::saludModulos()` (línea ~460 de
  `app/Modules/Gerencia/Services/GerenciaHomeService.php`) ahora usa exactamente el mismo patrón
  que `PurchaseOrderController@index chip=critical` (línea ~153-159 de
  `app/Modules/Compras/Http/Controllers/PurchaseOrderController.php`): filtra en PHP con
  `array_filter($orders, fn ($o) => $o->isCritical())` sobre el mismo universo
  (`status != recibido`). Ya no hay una segunda definición divergente de "orden crítica" — el
  riesgo de desalinearse en los límites de día/hora que causó el hallazgo de v8 queda cerrado de
  raíz (misma llamada a método, no dos queries independientes que puedan divergir).
- **Confirmado en vivo (curl autenticado, Gerencia5 [persona]):**
  `GET /api/gerencia/home` → `salud_modulos.compras.ordenes_criticas = 43`
  `GET /api/compras/orders?chip=critical` → `meta.total = 43` — **coinciden**.
- **Confirmado con Playwright real** (`preqa-scrum168-v9-recheck.spec.ts`, test "RN1"): clic real
  en "Órdenes críticas" desde `/gerencia` navega a `/compras/ordenes?chip=critical`, la respuesta
  real del endpoint (interceptada, no simulada) devuelve `meta.total=43`, y la pantalla renderiza
  "43 órdenes" con el chip "Críticas" activo. Captura:
  `atlanticerp-frontend/docs/visual-review/screenshots/SCRUM-168-v9-ordenes-criticas.png`.
- No se repitió la lectura minutos después (como sugería el hallazgo original) porque la causa raíz
  ahora está cerrada por identidad de código, no por coincidencia numérica — las dos superficies
  llaman al mismo método `isCritical()` sobre el mismo query base, así que no hay mecanismo posible
  para que vuelvan a divergir por los límites de día/hora que causaron el 43 vs 44 original.

### RN2 / Escenario 1 — "Bajo stock sin ordenar" (verificación adversarial)

- **Confirmado en vivo (curl autenticado):**
  `GET /api/gerencia/home` → `salud_modulos.compras.bajo_stock_sin_ordenar = 11623`
  `GET /api/compras/inventory?chip=bajo_stock_sin_ordenar` → `meta.total = 11623` — **coinciden**.
  `GET /api/compras/inventory?chip=todos` → `meta.total = 11752` (catálogo completo) — el filtro
  real reduce el universo (11623 < 11752), no lo ignora.
- **Confirmado con Playwright real** (test "RN2"): clic real en "Bajo stock sin ordenar" desde
  `/gerencia` navega a `/inventario?chip=bajo_stock_sin_ordenar` (Compras, **no** a
  `/bodega/inventario`), la respuesta real interceptada del endpoint devuelve
  `meta.total < 11752` (confirmando que el filtro se aplica, no cae al catálogo completo como en
  v8), y la tabla renderiza filas reales. Captura:
  `atlanticerp-frontend/docs/visual-review/screenshots/SCRUM-168-v9-inventario-filtrado.png`.
- Se leyó también el código de `InventarioPage.tsx`: `LINKABLE_CHIPS` ya incluye
  `'bajo_stock_sin_ordenar'`, `initialChip` lo toma de `searchParams.get('chip')` al montar, y el
  estado `chip` alimenta el parámetro real de la llamada a la API (línea 212) — no es solo que el
  deep-link se acepta, es que efectivamente dispara el fetch filtrado.
- Backend: `InventoryController::index()` (línea ~231) tiene el `case 'bajo_stock_sin_ordenar'`
  que reusa `InventoryKpiService::isLowStockWithoutOpenOrder()` — la misma lógica que ya usaba
  `GerenciaHomeService` para calcular el KPI, sin una tercera definición paralela.

### Regresión — resto del ticket (RN3 "Próximas a llegar", RN4 "Cantidad de pedidos", Escenario 2)

Ninguno de los dos commits de fix tocó estas líneas — confirmado por diff:
- `GerenciaHomeService::saludModulos()`: el bloque de `en_transito`/`proximas_a_llegar`/
  `cantidad_pedidos` (líneas 469-475) es idéntico al de antes del fix — el commit `68beeed` solo
  reemplazó el cálculo de `ordenes_criticas`, arriba de ese bloque.
- `GerenciaPage.tsx`: el commit `e1f66ab` solo tocó la línea de `onClick` de "Bajo stock sin
  ordenar" — las líneas de "En tránsito" (`navigate('/compras/logistica')`), "Próximas a llegar"
  (`navigate('/compras/logistica')`) y "Cantidad de pedidos" (`navigate('/compras/ordenes')`)
  quedan sin cambios.
- Verificación adicional en vivo: `GET /api/gerencia/home` devuelve
  `en_transito=3`, `proximas_a_llegar=1`, `cantidad_pedidos=69` — `cantidad_pedidos` no coincide
  con el total crudo de `/api/compras/orders` (98, sin filtrar) porque por diseño excluye
  `status=recibido` (`PurchaseOrder::whereNotIn('status', ['recibido'])->count()`, línea 475) —
  comportamiento preexistente, no tocado por esta sesión, y consistente con RN4 ("sin filtro
  adicional" se refiere a la navegación — llega a Ver Órdenes sin querystring — no a que el número
  mostrado deba igualar el total sin filtrar de la pantalla destino).

Sin regresión en ninguno de los dos RNs no tocados por el fix.

---

## Veredicto final

**SCRUM-168 — ✅ PASA.** Los 2 hallazgos de v8 (1 CRÍTICO, 1 MEDIO) están corregidos y confirmados
end-to-end contra `dev.atlanticerp.ai` — no solo por lectura de código, sino con clic real en Playwright,
respuesta real interceptada del backend, y captura de pantalla mostrando datos reales renderizados
(no tablas vacías). Sin regresión en el resto del ticket.

**Con este resultado, los 10/10 tickets de la épica Gerencia (SCRUM-325) están en `QA`.**

---

## Transición de Jira

- `SCRUM-168`: comentario con el veredicto y evidencia (curl + Playwright + capturas), transición
  `In Progress` → `QA`, reasignado de vuelta a `qa.qa` (estaba temporalmente en el
  implementador tras el hallazgo de v8). Marcadores creados:
  `~/.claude-preqa-markers/SCRUM-168`, `~/.claude-visual-review-markers/SCRUM-168`.

## Commits locales sin push

`atlanticerp-frontend`, rama `dev`: este reporte + `e2e/preqa-scrum168-v9-recheck.spec.ts` +
`docs/visual-review/screenshots/SCRUM-168-v9-*.png` (2 capturas nuevas). **No se pusheó** — gate
estándar de `git push`, requiere aprobación individual de Luis/coordinador.
