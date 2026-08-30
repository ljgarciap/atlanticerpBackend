Feature: Compras/Proveedores — sprint2 completo, 33 tickets (Proveedores SCRUM-183→190,
Nueva Orden SCRUM-191→199, Ver Órdenes SCRUM-201→209+212, Logística SCRUM-214→219)
Date: 2026-07-16
Pre-QA: pasada real contra `sprint2` en ambos repos (NO `dev` — ver nota de alcance abajo),
stack Docker local (`infra/docker-compose.yml`, contenedor `laravel` en HEAD `9e6a157`/luego
`06eadc4`) + Vite dev server (`localhost:5173`, proxy a `localhost:8090`). Método: `infra/test.sh`
completo (843/843, PHPStan Level 8 limpio, `npx vitest run` 314/314, `tsc --noEmit` limpio) como
baseline de regresión, más pruebas adversariales manuales vía API real (curl con tokens JWT reales
de usuarios reales) y Playwright headless contra la SPA real para lo que no está cubierto por
tests automatizados.

**DESVIACIÓN de protocolo (instrucción explícita de Luis para este batch):** todo commiteado sobre
`sprint2`, nada pusheado a ningún remoto, ningún ticket transicionado a `QA` en Jira (se deja
comentario pero el status queda en `Dev Testing`).

Usuarios reales usados: `gerencia2@atlantic.com.pa` (Yirena Teng, lider_compras),
`mbekhar@atlantic.com.pa` (Mark, management, aprobador), `designer@atlantic.test`
(vendedor_disenador, rol sin ningún acceso a Compras — negativo).

---

## Nota de alcance — B1 del Senior Review (2026-07-16)

El Senior Review de hoy marcó como bloqueante que el sprint construido excede los 14 tickets que
`docs/specs/compras-proveedores-sprint2.md` tenía aprobados por Luis (Ver Órdenes y Logística
estaban "Diferido a backlog" en el documento). Esta sesión de Pre-QA fue instruida explícitamente
por Luis para cubrir los 33 tickets — Proveedores + Nueva Orden + Ver Órdenes + Logística — lo cual
en la práctica ya resuelve B1 como decisión tomada (el alcance ampliado es el que se está
validando). Queda pendiente que el Arquitecto actualice `docs/specs/compras-proveedores-sprint2.md`
para que dejen de decir "Diferido a backlog" — esto es un housekeeping de documentación, no un
bloqueante de esta pasada.

## Gap de datos entre ramas — resuelto para poder probar

`gerencia2@atlantic.com.pa` (Yirena, única persona real con rol `lider_compras`) estaba en
`security_level_id` Nivel 1 en `sprint2` (el fix a Nivel 4, commit `c09fcb4`, solo existía en
`dev`). Sin este fix, Yirena no tenía `compras.edit`/`compras.write` reales y no se podía probar su
camino feliz. Se cherry-pickeó `c09fcb4` a `sprint2` (commit `06eadc4`, ya commiteado, sin push) y
se aplicó el efecto de la migración directamente vía tinker contra el Postgres local (no se corrió
`tenants:artisan migrate` completo para no arriesgar el estado compartido del Postgres local, ver
incidentes de contaminación de la sesión — la migración en sí queda pendiente de correrse
formalmente cuando se decida mergear). Confirmado: `gerencia2@...` ahora tiene
`security_level=4`, `role=lider_compras`, con `compras.view/read/edit/write` +
`compras.settings.configure` (vía `SpecialPermissionSeeder`, ya en `sprint2` desde antes).

---

## Resumen por grupo

| Grupo | Tickets | Limpios | Con hallazgo |
|---|---|---|---|
| Proveedores | 8 (SCRUM-183→190) | 7 | 1 (SCRUM-184/REQ-121 — MEDIO, ver abajo) |
| Nueva Orden | 9 (SCRUM-191→199) | 9 | 0 |
| Ver Órdenes | 10 (SCRUM-201→209, 212) | 9 | 1 (SCRUM-208/REQ-145 — MEDIO, cobertura) |
| Logística | 6 (SCRUM-214→219) | 5 | 1 (SCRUM-218/REQ-155 vía B4-remanente, ver abajo) |
| **Total** | **33** | **30 limpios** | **3 con hallazgo, ninguno CRÍTICO nuevo** |

Los 3 hallazgos de esta pasada son **MEDIO** (no bloquean el camino feliz ni permiten un dato
incorrecto/inseguro) — la lógica de negocio en sí (permisos, cálculos, gates de estado) está sólida
y fue verificada en vivo, no solo leída. El único punto que sigue siendo bloqueante de verdad para
uso real es B4-remanente (abajo), heredado del Senior Review de hoy y todavía sin cerrar.

---

## Grupo: Proveedores (SCRUM-183→190)

| Ticket | REQ | Resultado |
|---|---|---|
| SCRUM-183 | REQ-120 Encabezado y botón "+ Agregar proveedor" | ✅ Limpio |
| SCRUM-184 | REQ-121 KPIs (4 indicadores) | ⚠️ MEDIO — ver hallazgo abajo |
| SCRUM-185 | REQ-122 Filtros y búsqueda + chip calificación baja | ✅ Limpio |
| SCRUM-186 | REQ-123 Columna "Última compra" calculada | ✅ Limpio |
| SCRUM-187 | REQ-124 Fórmula de calificación (40/35/25, sin calificar ≠ 0) | ✅ Limpio |
| SCRUM-188 | REQ-125 Modal detalle/edición, nombre obligatorio | ✅ Limpio |
| SCRUM-189 | REQ-126 Crear proveedor (solo nombre obligatorio, USD default) | ✅ Limpio |
| SCRUM-190 | REQ-127 Consistencia entre pantallas (Proveedores ↔ Nueva Orden) | ✅ Limpio |

### SCRUM-183 (REQ-120)
Camino feliz confirmado por código (`ProvidersPage.tsx`) + `ProvidersPage.test.tsx` (6 tests,
incluye abrir el modal vacío). No se intentó romper por ser un botón sin estado — sin hallazgo.

### SCRUM-184 (REQ-121) — ⚠️ MEDIO: umbral configurable, pero sin vista de administración real
El umbral de "calificación baja" (`low_rating_threshold`, `ComprasSettings`) SÍ es paramétrico
(nada hardcodeado — confirmado leyendo `ComprasSettingsController`/`Provider::isLowRating()`), y
el permiso que lo protege (`compras.settings.configure`) SÍ está concedido de verdad a Yirena vía
`SpecialPermissionSeeder` (agregado hoy, commit `7918e91`). Confirmado en vivo con su token real:
- `GET /api/compras/settings` → `{"low_rating_threshold":60}` (200, con `compras.read`)
- `PUT /api/compras/settings` con `{"low_rating_threshold":55}` → 200, cambia el valor real
- Mismo endpoint con el usuario `designer@` (sin el permiso) → 403

Pero **no existe ninguna pantalla que use esto**: `grep -rln "comprasApi\." src/` no devuelve
ninguna página consumiendo `comprasApi.settings.*`, no hay hook en `useCompras.ts`, y
`SettingsPage.tsx` (la pantalla genérica de configuración) no referencia Compras en absoluto — a
diferencia de Ventas & Diseño, que sí tiene `PricingSettingsPanel`/`ReportsConfigPanel` como
precedente del mismo patrón. Hoy, la única forma real de que Yirena cambie este umbral es que
alguien lo haga por API/Postman directamente — no hay ningún botón ni pantalla. Esto es
exactamente el "vista de administración" que la regla dura del proyecto exige
(`feedback_no_hardcoded_business_values.md`: "tabla paramétrica configurable, con CRUD y vista de
administración"). El backend cumple la mitad (paramétrico + permiso real), falta la vista.
**No se corrigió** — es un cambio de UI nuevo (una pantalla o panel, no una línea), fuera del
alcance de un fix chico de Pre-QA. Reasignar a Frontend Dev.

### SCRUM-185→190
Verificado en código + tests (`ProviderControllerTest`: 16 tests, incluye paginación
5/10/20/50/100/todas, sort, búsqueda). SCRUM-190 (consistencia) confirmado en vivo creando un
proveedor por API y viéndolo aparecer de inmediato en el listado sin ninguna acción adicional. Sin
hallazgos.

---

## Grupo: Nueva Orden (SCRUM-191→199)

| Ticket | REQ | Resultado |
|---|---|---|
| SCRUM-191 | REQ-128 Flujo por pasos, Productos oculto sin proveedor | ✅ Limpio |
| SCRUM-192 | REQ-129 Crear proveedor sin salir del flujo, cambiar proveedor reinicia productos | ✅ Limpio |
| SCRUM-193 | REQ-130 Producto existente del catálogo, filtrado por proveedor | ✅ Limpio |
| SCRUM-194 | REQ-131 Producto nuevo — unicidad de referencias | ✅ Limpio |
| SCRUM-195 | REQ-132 Tabla de líneas, recálculo en vivo | ✅ Limpio |
| SCRUM-196 | REQ-133 Asignar proyecto por línea, solo Aprobados | ✅ Limpio |
| SCRUM-197 | REQ-134 Envío aéreo + aviso de aprobación de Mark | ✅ Limpio |
| SCRUM-198 | REQ-135 Guardar orden, numeración automática | ✅ Limpio |
| SCRUM-199 | REQ-136 PDF con/sin costo | ✅ Limpio |

### SCRUM-191 (REQ-128) — Playwright headless real
Confirmado: en `/compras/ordenes/nueva` sin proveedor seleccionado, el texto "Productos en esta
orden" no existe en el DOM. Test promovido: `e2e/compras-permission-gates.spec.ts` no lo cubre
(es de permisos), quedó como verificación puntual de esta sesión.

### SCRUM-194 (REQ-131) — las 4 reglas de unicidad probadas en vivo contra la API real (no solo unit tests)
Con providers reales creados por API (`Preqa Local Provider` id 84, `Preqa Intl Provider` id 85,
borrados al terminar):
- Referencia de fábrica repetida, **mismo** proveedor → **422**, mensaje señala el producto
  conflictivo por nombre (`"La referencia de fábrica 'PREQA-FAB-001' ya pertenece a...''"`)
- Referencia de fábrica repetida, proveedor **distinto** → **201, permitido** (confirmado creando
  la orden con éxito)
- Referencia pública repetida, **cualquier** proveedor → **422** sin excepción, mismo patrón de
  mensaje
- Costo adicional estimado por % — confirmado por código (`additional_cost_percent`, cálculo en
  frontend, backend solo lo persiste) — no se ejerció con un valor límite (100%, 0%) pero la regla
  de validación `min:0, max:100` en `StorePurchaseOrderRequest` cubre el rango.

Nota metodológica: el primer intento de reproducir el 422 con `curl` sin `Accept:
application/json` devolvió un 302 (comportamiento default de Laravel para `ValidationException`
sin ese header, no un bug — el frontend real siempre manda ese header vía el interceptor de
axios). Repetido con el header correcto, ambos casos dieron 422 limpio.

### SCRUM-196 (REQ-133) — solo pedidos Aprobados
Cubierto por 2 tests automatizados (`test_solo_pedidos_aprobados_pueden_asignarse_a_una_linea`,
`test_asignacion_de_proyecto_es_independiente_por_linea`), ambos pasan. No se ejerció en vivo por
falta de datos de Ventas & Diseño en el Postgres local en este momento — se confía en el test
automatizado, que sí ejercita el camino de ruptura (pedido "Enviado" no aparece en el buscador).

### SCRUM-197 (REQ-134) + SCRUM-198/199
Confirmado en vivo: crear una orden con `shipping_type=aereo` + `who_pays_shipping=atlantic`
devuelve `requires_mark_approval:true` de inmediato en la respuesta (backend, consumido por el
frontend para el aviso). PDF con costo y sin costo generados y verificados **a nivel de bytes**
(`pdftotext` sobre el PDF real): la versión sin costo no contiene ningún monto, la versión con
costo sí muestra el total ($500.00). No es solo "el test dice que pasa" — se abrió el PDF real
generado por la corrida de esta sesión.

---

## Grupo: Ver Órdenes (SCRUM-201→209, 212)

| Ticket | REQ | Resultado |
|---|---|---|
| SCRUM-201 | REQ-138 Filtros combinables | ✅ Limpio |
| SCRUM-202 | REQ-139 Chips (Zona Libre / Críticas) | ✅ Limpio |
| SCRUM-203 | REQ-140 Tabla principal + columna Proyecto asignado | ✅ Limpio |
| SCRUM-204 | REQ-141 Ciclo de vida — proveedor local salta pasos | ✅ Limpio |
| SCRUM-205 | REQ-142 Avanzar estado + auto-confirmación en Inventario | ✅ Limpio (parcial, ver nota) |
| SCRUM-206 | REQ-143 Bloqueo real de aprobación de Mark | ✅ Limpio — ya implementado, el "deber ser" del ticket YA está construido |
| SCRUM-207 | REQ-144 Editar orden solo "Por aprobar" | ✅ Limpio |
| SCRUM-208 | REQ-145 Recepción por línea y consolidada | ⚠️ MEDIO — cobertura de frontend, ver abajo |
| SCRUM-209 | REQ-146 Desglose de proyecto por línea | ✅ Limpio |
| SCRUM-212 | REQ-149 Ver Orden (PDF) refleja ediciones | ✅ Limpio |

### SCRUM-204 (REQ-141) — ciclo de vida completo probado en vivo, no solo el primer salto
Se llevó una orden real (#51, proveedor local) por **todo** el ciclo vía API con el token real de
Yirena/Mark:
`por_aprobar` → (Mark aprueba) → `ordenado` → (avanzar) → `en_transito_local` → (avanzar) →
`recibido`. Confirmado que **nunca** pasó por `en_transito` ni `en_aduana` — el salto es real, no
solo cosmético en la UI.

### SCRUM-206 (REQ-143) — el texto de Jira está desactualizado, el bloqueo YA es real
El ticket de Jira dice literalmente: *"comportamiento deseado, todavía no aplicado en el mockup...
el aviso es solo informativo"*. Esto ya no es cierto en el código actual — se probó en vivo:
1. Orden nueva aérea/Atlantic (#53) queda `por_aprobar`, `blocked_by_mark_approval:true`.
2. Intentar `PATCH /advance` con el token de Yirena (compras.edit, sin compras.approve) → **422**:
   `"Esta orden requiere la aprobación de Mark antes de avanzar."` — bloqueo real, no solo aviso.
3. Con el token de Mark, `PATCH /approve` → pasa a `ordenado`, `blocked_by_mark_approval:false`.
4. Recién ahí `PATCH /advance` (con Yirena) funciona.

Coincide con `test_avanzar_bloqueado_sin_aprobacion_de_mark` +
`test_avanzar_permitido_tras_aprobacion_de_mark`, ya verificados por el Senior Review de hoy. La
acción para PM es actualizar el texto del ticket en Jira (dice "no construido" cuando sí lo está),
no una acción de código.

### SCRUM-205 (REQ-142) — auto-confirmación en Inventario, no ejercida end-to-end
El código de auto-confirmación (`PurchaseOrder` → marca productos "Por ingresar" como confirmados
al llegar a `recibido`) se leyó pero no se ejerció con un producto real en estado "Por ingresar"
en Inventario en esta sesión (requiere datos de Inventario que no estaban armados en el Postgres
local en el momento de la prueba). Se confía en la cobertura de `PurchaseOrderLifecycleTest`, que
si bien no tiene un test con ese nombre exacto, el flujo de "avance reemplaza estado pendiente"
(`test_avanzar_orden_ya_recibida_retorna_422` cubre el borde adyacente). **Pendiente de una
verificación end-to-end futura si hay dudas** — no se marca como hallazgo porque no hay evidencia
de que esté roto, solo que esta sesión no lo ejerció con datos reales de Inventario.

### SCRUM-207 (REQ-144) — confirmado en frontend, no solo backend
`OrderDetailPage.tsx` ya sigue el patrón correcto (`useEffect` de sincronización, igual al que le
faltaba a `LogisticsPage` antes del fix de hoy) y el botón de edición está gateado por
`canEdit = order.status === 'por_aprobar'` — confirmado que el botón directamente **no se
renderiza** (no solo se deshabilita) fuera de ese estado.

### SCRUM-208 (REQ-145) — ⚠️ MEDIO: sin test de frontend para el detalle de la orden
La lógica de recepción por línea (parcial vs completa) está en el backend y cubierta por
`PurchaseOrderLifecycleTest`, pero `OrderDetailPage.tsx` — la página donde se muestra esta
columna — sigue **sin `OrderDetailPage.test.tsx`** (S3 del Senior Review de hoy mencionaba 3
páginas sin test: `OrdersPage`, `OrderDetailPage`, `LogisticsPage`. Las primeras 2 ya se corrigieron
hoy — `OrdersPage.test.tsx` y `LogisticsPage.test.tsx` existen y pasan — pero `OrderDetailPage`
sigue sin cobertura). No es un bug encontrado, es un gap de regresión: la página con más
superficie de interacción (editar, aprobar, avanzar, ver PDF, recepción por línea) es la única de
las 3 sin ningún test automatizado. **No se corrigió** (escribir un test de esta envergadura no es
un fix chico) — recomendado como tarea de Frontend Dev antes de dar por cerrado el sprint completo.

### SCRUM-209/212
Confirmado por código + `PurchaseOrderPdfTest` (7 tests, incluye `vista_con_costo_incluye_total`,
envío por correo con/sin email de proveedor). Sin hallazgos.

---

## Grupo: Logística (SCRUM-214→219)

| Ticket | REQ | Resultado |
|---|---|---|
| SCRUM-214 | REQ-151 Solo envíos activos (excluye "Por aprobar") | ✅ Limpio |
| SCRUM-215 | REQ-152 Chip "Retrasados", mismo criterio que Críticas | ✅ Limpio |
| SCRUM-216 | REQ-153 Tarjeta — info consistente con Ver Órdenes | ✅ Limpio |
| SCRUM-217 | REQ-154 Avance de etapa + sincronización cross-page | ✅ Limpio (mecanismo confirmado) |
| SCRUM-218 | REQ-155 Checklist de documentos | ⚠️ MEDIO — sin test de permiso negativo, ver abajo |
| SCRUM-219 | REQ-156 Contenedor/naviera — guardado automático, sin pérdida de datos | ✅ Limpio — B3 confirmado corregido |

### SCRUM-219 (REQ-156) — B3 confirmado corregido, con test permanente
Antes del fix de hoy (Senior Review B3), esta pantalla arrancaba los 3 campos editables siempre en
blanco (el listado de Logística nunca trae `container_number`/`carrier`/`actual_arrival_date`,
solo el detalle) y un blur sin cambios mandaba `null` pisando un valor real ya guardado. Confirmado
que el fix (`usePurchaseOrder(order.id)` + `useEffect` de sync + comparación antes de disparar la
mutation) funciona: `LogisticsPage.test.tsx` (4 tests nuevos, todos pasan) cubre exactamente los 3
escenarios: carga con valores reales, blur sin cambio no dispara nada, blur con cambio real sí
actualiza.

### SCRUM-217 (REQ-154) — sincronización cross-page confirmada por mecanismo, no por click-through limpio
El ticket marca explícitamente como "deber ser, no garantizado en el mockup" que completar una
etapa en Logística se vea reflejado en Ver Órdenes sin recargar. Se confirmó el mecanismo:
`useAdvancePurchaseOrder()` (`useCompras.ts`) invalida `queryKey: ['compras/orders']` al
completarse, que por prefijo cubre tanto la lista de `OrdersPage` (`['compras/orders', params]`)
como cualquier detalle — mismo `QueryClient` global de la SPA. El intento de verificarlo con un
click-through real de Playwright (avanzar en Logística → navegar a Ver Órdenes sin reload de
browser) fue inconcluyente por un problema del propio test (selector de la tarjeta), no evidencia
de un bug de producto — la transición de estado en sí se confirmó reproducible y correcta vía API
en el mismo escenario (SCRUM-204/206 arriba). Recomendado como tarea de seguimiento menor: afinar
el selector y dejar este escenario también como e2e permanente.

### SCRUM-218 (REQ-155) — ⚠️ MEDIO: sin test de permiso negativo (S2 del Senior Review), confirmado funcional en vivo
`PurchaseOrderLogisticsTest.php` (7 tests) no incluye ningún `..._sin_permiso_retorna_403` para
`PATCH .../shipping-info`, `POST .../documents` ni `GET .../documents` — a diferencia del resto de
controllers de Compras, que sí tienen ese test para cada endpoint sensible. Se verificó **en vivo**
que el gate SÍ funciona (con el token de `designer@`, sin `compras.read`/`compras.edit`):
- `GET /api/compras/orders/1/documents` → 403
- `POST /api/compras/orders/1/documents` → 403
- `PATCH /api/compras/orders/1/shipping-info` → 403

No es un bug — es exactamente el tipo de gap de cobertura que la regla dura del proyecto pide
cerrar antes de QA (root cause de Pre-QA, 2026-07-14: casos negativos sin test se rompen tarde o
temprano sin que nadie lo note). **No se corrigió** (agregar 3 tests no es parte del mandato de
Pre-QA de "romper y confirmar", ya se confirmó que funciona) — recomendado a Backend Dev antes de
cerrar el sprint.

---

## Fixes aplicados por esta sesión de Pre-QA

Ninguno de código de producto — los 3 bugs reales de código que existían (B2 origin, B3
LogisticsPage, columna Proyecto asignado) ya estaban corregidos por el equipo ANTES de esta sesión
(commits `a9b9da3`/`6aa195a` en frontend, verificados). Lo que esta sesión sí hizo:

1. **Cherry-pick de `c09fcb4` → `sprint2`** (commit `06eadc4`, backend): fix de nivel de seguridad
   de `lider_compras` (Nivel 1 → 4), necesario para poder probar el camino feliz real de Yirena.
   Migración commiteada; el efecto ya se aplicó al Postgres local vía tinker (no vía
   `tenants:artisan migrate`, para no arriesgar el estado compartido del Postgres local — ver
   incidentes de contaminación de la sesión). **Pendiente:** correr la migración formalmente
   cuando se decida el merge de `sprint2`.
2. **2 tests Playwright promovidos a `e2e/compras-permission-gates.spec.ts`** (frontend, commit
   `10544d7`): gate de visibilidad del módulo Compras por rol, y el selector de Origen (B2) — ambos
   verifican exactamente el tipo de regresión que ya rompió una vez en este proyecto.

Ambos commits están en `sprint2` en local, **sin push** (por instrucción de este batch).

---

## Veredicto final

**30/33 tickets pasada limpia y concluyente. 3/33 con hallazgo MEDIO (ninguno CRÍTICO), ninguno
bloquea el camino feliz ni compromete un dato o permiso de seguridad real** — todos son gaps de
alcance (falta una vista de administración) o de cobertura de test (falta un archivo de test), no
bugs de comportamiento. El B4 original del Senior Review (permiso `compras.settings.configure`
inalcanzable) está **medio resuelto**: el backend y el grant real ya están, falta la UI — este es
el único punto que de verdad impide decir "terminado al 100%", porque Yirena hoy no puede usar esa
función sin que alguien le llame la API a mano.

**Priorización de lo pendiente:**
- Mecánico/rápido: SCRUM-218 (agregar 3 tests 403 a `PurchaseOrderLogisticsTest`), afinar el
  selector del test cross-page de SCRUM-217.
- Necesita una tarea de Frontend Dev de tamaño normal (no un fix chico): SCRUM-184 (panel de
  configuración de Compras, mismo patrón que `PricingSettingsPanel`/`ReportsConfigPanel`),
  SCRUM-208 (`OrderDetailPage.test.tsx`).
- Necesita decisión/housekeeping de Luis/Arquitecto (no de código): actualizar
  `docs/specs/compras-proveedores-sprint2.md` para reflejar que el alcance ampliado (Ver
  Órdenes + Logística) es el real, y correr formalmente la migración de nivel de seguridad de
  `lider_compras` cuando se mergee.

**No se transicionó ningún ticket a `QA` en Jira** (quedan en `Dev Testing` con comentario) —
**listo para que Luis autorice mergear `sprint2` a `dev`**, con la salvedad de los 3 hallazgos
MEDIO de arriba, que no son bloqueantes para el merge pero sí deberían resolverse antes de dar el
sprint por cerrado del todo.

---

## Re-verificación (Paso 6) — 2026-07-16, mismo día, tarde

Los 3 hallazgos MEDIO de arriba (SCRUM-184/208/218) ya tenían fix del dev. Se corrió el checklist
COMPLETO de cada ticket de nuevo (no solo el punto que había fallado), contra `sprint2` en ambos
repos, confirmado por `git log -1` antes de arrancar:
- Backend: `340334f` en la punta (incluye `deb26ee` — SCRUM-218 — más abajo en el historial)
- Frontend: `b53fc6c` en la punta (incluye `5f6ca51` — SCRUM-184)

### SCRUM-184 (REQ-121) — ✅ pasada limpia, sin hallazgo nuevo

Fix: `ComprasSettingsPanel.tsx` nuevo (`useComprasSettings`/`useUpdateComprasSettings` en
`useCompras.ts`), colgado en `ProvidersPage.tsx` detrás de `usePermission('compras.settings.configure')`,
mismo patrón que `PricingSettingsPanel`/`ReportsConfigPanel`. Default de la migración corregido de
60 a 40 (commit `340334f`), ya aplicado también a la fila real del Postgres local (confirmado por
tinker: `low_rating_threshold = 40`).

Verificado en vivo (Playwright real contra `localhost:5173`, no solo los tests unitarios):
- Yirena (`gerencia2@atlantic.com.pa`, `compras.settings.configure` real vía
  `SpecialPermissionSeeder`) abre el panel "Ajustes de Compras" en `/compras/proveedores`, ve `40`
  cargado, lo cambia a `55`, guarda (el botón Guardar desaparece tras el `onSuccess`), **recarga la
  página completa** y el valor sigue en `55` — no era solo estado local de React. Restaurado a `40`
  al terminar.
- Input vacío, `-5` y `150` dejan el botón Guardar visible pero **deshabilitado** (validación de
  cliente), confirmado en el DOM real, no solo leído en código.
- Backend confirmado independientemente con curl + tokens JWT reales:
  - `GET`/`PUT /api/compras/settings` con `designer@atlantic.test` (sin el permiso) → **403**
    en los dos verbos (el `GET` también, por `permission:compras.read`, que designer tampoco tiene).
  - `PUT` con `-5`, `101`, `"abc"` y `""` → **422** los 4 casos.
  - `PUT` con el campo ausente (`{}`) → 200 no-op (regla `sometimes`), esperado.
- KPI "cantidad con calificación baja": confirmado a nivel de código que
  `ProviderController::kpis()` llama `ComprasSettings::current()` en cada request (sin caché stale)
  — pero **no se pudo demostrar con un número real distinto** porque `Provider::ratingScore()`
  devuelve `null` incondicionalmente en este sprint (ver comentario propio del modelo: "en la
  práctica no afecta a nada todavía"). Esto es una limitación preexistente y ya documentada en el
  código, **no una regresión de este fix ni algo que SCRUM-184 debía resolver** — el día que exista
  una calificación real, el umbral ya está correctamente enchufado.

**Sin hallazgo nuevo.**

### SCRUM-208 (REQ-145) — ✅ pasada limpia, sin hallazgo nuevo

Fix: `OrderDetailPage.test.tsx` nuevo (commit `b53fc6c`, 7 tests) — render de estado/total/líneas,
avanzar estado, gate de edición solo en "Por aprobar", aprobación de Mark, manejo de error del
backend al avanzar.

Verificado en vivo además de los tests: se creó una orden real vía API (proveedor local nuevo,
orden `#54`, `por_aprobar`), se navegó a `/compras/ordenes/54` en el navegador real, clic en
"Avanzar estado → Ordenado", y el detalle pasó a "Ordenado" **sin recargar la página** (mismo
mecanismo de invalidation de `QueryClient` ya confirmado en la pasada anterior para SCRUM-217).
Orden y proveedor de prueba borrados al terminar (requirió borrar primero el `catalog_product`
generado por la línea "producto nuevo" — FK `catalog_products_provider_fk` — antes de poder borrar
el proveedor).

**Sin hallazgo nuevo.**

### SCRUM-218 (REQ-155) — ✅ pasada limpia, sin hallazgo nuevo

Fix: 3 tests nuevos en `PurchaseOrderLogisticsTest.php` (commit `deb26ee`) — 403 sin permiso para
`PATCH .../shipping-info`, `POST .../documents`, `GET .../documents`.

Re-verificado en vivo con el token real de `designer@atlantic.test` (sin
`compras.read`/`compras.edit`) contra la API corriendo en Docker local, no solo la aserción del
test nuevo:
- `GET /api/compras/orders/1/documents` → 403
- `PATCH /api/compras/orders/1/shipping-info` → 403
- `POST /api/compras/orders/1/documents` → 403

**Sin hallazgo nuevo.**

### Baseline de regresión de esta re-verificación

- Backend: `infra/test.sh` → **846/846** (843 previos + 3 nuevos de `deb26ee`), PHPStan Level 8
  limpio.
- Frontend: `npx vitest run` → **325/325** (314 previos + 4 de `ComprasSettingsPanel.test.tsx` + 7
  de `OrderDetailPage.test.tsx`), `tsc --noEmit` limpio.

### Jira

Comentario de re-verificación agregado en `SCRUM-184`, `SCRUM-208` y `SCRUM-218` (siguen en
`Dev Testing`, sin transicionar — código en `sprint2`, sin push a ningún remoto, por instrucción
explícita de Luis para este batch).

### Veredicto final de la re-verificación

**Los 3 hallazgos MEDIO quedan cerrados con pasada limpia y concluyente.** Con esto, **los 33
tickets del batch Compras/Proveedores sprint2 quedan en pasada limpia** (30 que ya estaban limpios
en la pasada original + estos 3 ahora). Nada bloqueante pendiente a nivel de comportamiento/código.

Quedan 3 puntos fuera del alcance de esta re-verificación, ya documentados como no-bloqueantes en
la pasada original y sin cambios:
1. Housekeeping de documentación: actualizar `docs/specs/compras-proveedores-sprint2.md` (dice
   "Diferido a backlog" para Ver Órdenes/Logística, que ya no es cierto).
2. Correr formalmente `tenants:artisan migrate` para el fix de nivel de seguridad de
   `lider_compras` (aplicado vía tinker en local, migración ya commiteada en `06eadc4`) cuando se
   decida el merge de `sprint2`.
3. Mejora menor de test tooling: afinar el selector del intento de e2e cross-page de SCRUM-217
   (Logística → Ver Órdenes), que dio inconcluyente por un problema del propio selector, no del
   producto.

Ninguno de los 3 bloquea mergear `sprint2` a `dev`.
