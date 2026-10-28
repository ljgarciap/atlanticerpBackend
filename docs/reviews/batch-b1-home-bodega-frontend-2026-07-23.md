# Senior Review — Batch B1, "Home de Bodega" (SCRUM-363→374), frontend

**Fecha:** 2026-07-23
**Alcance:** `atlanticerp-frontend`, rama `dev` (local, sin push), 5 commits en el rango
`2627102..bfb1c1e`:
- `f8e870f` — "feat(bodega): add Home entry and Inventory dropdown to sidebar"
- `cd69f08` — "feat(bodega): add Home API contract, types and hooks"
- `fb81c69` — "feat(bodega): build Home screen and Inventory dropdown placeholders"
- `bfb1c1e` — "fix(compras): let bodega.read into the order detail route guard"
- `5234ced` — "test(bodega): promote Pre-QA smoke suite for Order Status screen (SCRUM-407-413
  Batch A4)" — **fuera de alcance de este review**: pertenece a Batch A4 (Status de Pedidos, ya
  revisado en `batch-a4-status-pedidos-frontend-2026-07-23.md`), quedó incluido en el rango de
  commits solo por orden cronológico. No toca ningún archivo de Home de Bodega. No se revisa acá.

Contrato de backend confirmado leyendo el código real, no asumido: `atlanticerp-backend/app/Modules/
Bodega/Http/Controllers/BodegaHomeController.php`, `Services/BodegaHomeService.php`,
`routes/bodega.php`, `app/Shared/Http/Middleware/CheckPermission.php`. Archivos frontend nuevos/
modificados leídos completos, no solo diff: `src/pages/BodegaHomePage.tsx`,
`src/pages/BodegaHomePage.test.tsx`, `src/pages/BodegaPlaceholderPages.tsx`,
`src/components/Sidebar.tsx`, `src/components/Sidebar.test.tsx`, `src/types/bodega.ts`,
`src/api/bodegaApi.ts`, `src/hooks/useBodega.ts`, `src/i18n/locales/{es,en}/{common,bodega}.json`,
`src/App.tsx`.

## Veredicto: 🟢 Aprobado

Sin blockers. 1 hallazgo cosmético (comentario inconsistente, no funcional) + 2 suggestions no
bloqueantes.

---

## Resultado real de verificación (corrido yo mismo)

**`npx tsc --noEmit`:** sin salida — limpio.

**`npm run build`:** `tsc && vite build` completa sin errores (solo el warning preexistente de
chunk >500kB, no relacionado con este batch).

**`npm run test -- --run` (suite completa):**
```
Test Files  57 passed (57)
     Tests  488 passed (488)
```
Incluye `BodegaHomePage.test.tsx` (13 tests) y las 4 pruebas nuevas de `Sidebar.test.tsx` para el
dropdown "Inventario".

---

## Foco de revisión — resultado punto por punto

### 1. Contrato de API real — confirmado campo por campo, ambos lados

Comparé `types/bodega.ts` (interfaces `BodegaSaludo`, `BodegaMiDia`, `BodegaPendientes`/
`BodegaPendienteItem`, `BodegaDespachoUrgente`, `BodegaEstadoPedidos`, `BodegaPorRecibirRow`,
`BodegaRotacionItem`, `BodegaCriticoItem`, `BodegaHomeSummary`) contra `BodegaHomeService::summary()`
y cada método privado que arma cada panel (`saludo()`, `miDia()`, `pendientes()`,
`despachosUrgentes()`, `estadoPedidos()`, `porRecibir()`, `mayorRotacion()`, `articulosCriticos()`):
todos los nombres de campo coinciden exactamente (`saludo.nombre/fecha/resumen`,
`mi_dia.asignados_hoy/despachados_hoy/percent_despachado_hoy/percent_a_tiempo`,
`pendientes.items[].type/order_number/cliente/horas/dias/producto/bodega/solicitante`,
`despachos_urgentes[].order_id/order_number/cliente/stage/fecha_entrega_comprometida`,
`estado_pedidos.stages[].stage/count` + `urgentes_count`, `por_recibir[].purchase_order_id/
factory_reference/reference/description/quantity/status/estimated_arrival_date/provider_name/
is_zona_libre`, `mayor_rotacion[]`/`articulos_criticos[]` con `catalog_product_id/factory_reference/
reference/description/...`). También confirmé la forma de la respuesta completa: `summary()`
devuelve el array plano tal cual (`response()->json($this->service->summary(...))`, sin envolver en
`data`), y `bodegaApi.home.summary()` lee `r.data` esperando ese mismo shape sin wrapper — coincide.
`GET /bodega/home/team` sí envuelve en `{ data: [...] }` (`BodegaHomeController::team()`), y
`bodegaApi.home.team()` lo lee igual.

Rutas verificadas en `routes/bodega.php`: `GET /home/team` y `GET /home/summary`, ambas
`permission:bodega.read` — coincide con `RequirePermission permission="bodega.read"` en
`/bodega/home` (`App.tsx:257`).

No encontré ningún mismatch tipo `contacto_cliente`/`customer_contact` esta vez — el batch
efectivamente corrigió los nombres contra el backend real antes de terminar (los comentarios en
`types/bodega.ts:282-285` y `BodegaHomePage.tsx:38-40` documentan explícitamente esa verificación).

### 2. `NavDropdown` nuevo en `Sidebar.tsx` — patrón sólido, 1 quirk menor no bloqueante

`isDropdown()`/`NavDropdown` (líneas 25-36) y su render (líneas 255-306) son el primer submenú
anidado del proyecto. Revisé:

- **Auto-expand:** `anyChildActive = entry.children.some(c => isActive(c.href))` y
  `open = openDropdowns.has(entry.id) || anyChildActive` — cubre las 5 rutas hijas por igual
  (las 2 reales `/bodega/inventario`, `/bodega/solicitud-ajuste` y las 3 placeholders
  `/bodega/ordenes-zona-libre`, `/bodega/inventario-general`, `/bodega/devoluciones`), porque la
  condición itera `entry.children` genéricamente, sin lista hardcodeada de rutas "reales". No
  encontré ningún caso donde quede abierto sin razón al cargar: `openDropdowns` arranca vacío, así
  que en la carga inicial `open` depende solo de `anyChildActive`.
- **Resaltado:** el estilo del botón padre (`bg-[#d1ede9]...`) depende de `anyChildActive`, no de
  `open` — así que el resaltado del padre y el de cada hijo (`childActive = isActive(child.href)`)
  son correctos independientemente de si el usuario abrió/cerró el dropdown a mano.
- **Quirk no bloqueante:** una vez que `anyChildActive` es `true` (usuario parado en una de las 5
  pantallas hijas), hacer clic en el header del dropdown para colapsarlo no lo cierra visualmente
  — `open` sigue siendo `true` vía el OR con `anyChildActive`, aunque `toggleDropdown` sí quita el
  id de `openDropdowns` internamente. Es consistente con la intención documentada en el comentario
  de línea 100-102 ("que el resaltado sea visible sin que el usuario tenga que abrirlo a mano"),
  pero como efecto secundario el usuario pierde la posibilidad de colapsar manualmente el submenú
  mientras navega dentro de él. Es un matiz de UX, no un bug de datos ni de permisos — no bloqueo
  por esto, pero lo dejo anotado (ver Suggestions).
- Modo colapsado (`isCollapsed`): clic en el ícono del dropdown navega siempre al primer hijo
  (`entry.children[0].href`, o sea `/bodega/inventario`) en vez de abrir un submenú — razonable
  dado que no hay espacio para un flyout en este layout, y consistente con no tener otro precedente
  de submenú colapsado en el proyecto.

`Sidebar.test.tsx` (líneas 105-133) cubre: arranca cerrado + un clic revela las 5 opciones, y que
Kardex sigue siendo ítem propio del módulo. **Gap de cobertura:** no hay ningún test que verifique
el auto-expand por ruta activa (cargar el Sidebar con `pathname` en una de las 5 rutas hijas y
afirmar que el dropdown ya aparece abierto) — lo verifiqué leyendo el código, pero recomendaría un
test dedicado antes de que se vuelva a tocar esta lógica (ver Suggestions).

### 3. Regla SCRUM-56 — sin emoji, confirmado con grep de rangos Unicode

Grep con el patrón de rangos de emoji (`\U0001F300-\U0001FAFF`, `☀-➿`) sobre los 13 archivos
nuevos/modificados de este batch (incluyendo los 4 archivos i18n). Únicos 2 matches, ambos el
carácter ⚠ dentro de **comentarios de código**, nunca en JSX ni en strings de i18n:
- `BodegaHomePage.tsx:84` — comentario describiendo el chip `"⚠️ Urgentes" (sin emoji,
  IcoAlertTriangle)`, citando entre comillas cómo se llamaba el chip antes del cambio.
- `types/bodega.ts:346` — mismo patrón, comentario citando el nombre original del chip.

Ninguno de los dos llega a un string renderizado ni a un archivo de traducción — el propio texto
del comentario confirma que el ícono real usado es `IcoAlertTriangle`. Verificado también que
`IcoAlertTriangle`/`IcoClock`/`IcoGlobe` (los 3 íconos nuevos usados en `BodegaHomePage.tsx`) ya
existen en `src/components/icons/` y no se agregó ninguna librería de iconos nueva. Los 4 archivos
i18n (`es`/`en` × `common`/`bodega`) no tienen ningún glyph embebido en ningún string — confirmé
además paridad de claves 1:1 entre `es/bodega.json` y `en/bodega.json` (`home.*`), sin claves
faltantes de un lado ni del otro.

### 4. Prioridad de "Pendientes" inventada por el frontend — decisión razonable y bien documentada, pendiente de confirmación de Luis/Analista (ya señalizado por el propio batch)

`PENDIENTE_PRIORITY` (`BodegaHomePage.tsx:25-29`) mapea `picking_no_iniciado`/
`ajuste_inventario_pendiente` → alta, `guia_sin_subir` → media. Confirmé contra
`BodegaHomeService::pendientes()` (backend) que efectivamente no hay ningún campo `prioridad` en el
payload real — es 100% una decisión de presentación tomada en este batch. Encuentro razonable el
criterio (ambos casos "alta" bloquean un flujo — despacho o una aprobación de Aprobador — mientras
"guía sin subir" es papeleo de cierre sobre un pedido ya entregado), y está documentado en 3
lugares distintos con la misma advertencia explícita ("a confirmar con Luis/Analista si no era la
intención"): `types/bodega.ts:310-313`, `BodegaHomePage.tsx:19-24` y
`BodegaHomeService.php:339-346` (docblock del backend, que ya venía señalizando lo mismo desde el
lado del contrato). No lo trato como una inconsistencia de
arquitectura oculta — está señalizado en el código para que el flujo normal (PM → Luis) lo
confirme o ajuste, no es una decisión de negocio escondida. Distinto de un umbral/margen numérico
de negocio (la regla dura de "nunca hardcodear" apunta a valores como "margen mínimo 30%" en una
tabla paramétrica) — esto es una categorización cualitativa de presentación (alta/media), no un
número que Luis vaya a necesitar tunear vía UI de superadmin. No lo marco como hallazgo bloqueante,
pero coincido en que debería ir a confirmación explícita de Luis/Analista antes de que se dé por
definitivo (ya está en el radar del batch, solo falta que alguien lo cierre).

### 5. Fix de permiso `/compras/ordenes/:id` — OR confirmado en ambos lados, sin romper otros consumidores

`RequirePermission` (`App.tsx:88-95`) normaliza `permission: string | string[]` a array y evalúa
`required.some(p => user.permissions.includes(p))` — confirmado que es OR, no AND, leyendo el
código (no asumido). El único cambio del commit `bfb1c1e` es
`permission="compras.read"` → `permission={["compras.read", "bodega.read"]}` en la ruta
`/compras/ordenes/:id` (línea 201) — ningún otro de los ~30 usos de `RequirePermission` en el
archivo se tocó, y todos siguen pasando un string simple sin cambio de comportamiento (el
`Array.isArray` los envuelve en un array de 1 elemento, equivalente al `.includes()` anterior).
Confirmé en el backend que el mismo gate ya se relajó en paralelo:
`routes/compras.php:50` — `Route::get('/{id}', ...)->middleware('permission:compras.read,
bodega.read')`, y `CheckPermission::handle()` confirma que Laravel separa los parámetros de
middleware por coma y los evalúa con `foreach` + `in_array` (OR real). Mismo patrón ya usado y
aprobado en Batch A4 para `/bodega/pedidos/status` (`['bodega.read', 'ventas_diseno.read']`), no
es una improvisación nueva. No hay ningún otro consumidor de esa ruta en el código (un solo
`<Route>` para `/compras/ordenes/:id`) — nada se rompe por el cambio, es puramente aditivo.

### 6. Gap de permiso para "Mayor rotación"/"Artículos críticos" — no rompe, hay placeholder real

Ambos links navegan a `/bodega/inventario?filter=rotacion` / `?filter=criticos`. Confirmé en
`App.tsx:288-292` que la ruta `/bodega/inventario` existe y está montada
(`RequirePermission permission="bodega.read"` → `<BodegaVerInventarioPage />`), así que el clic
no produce ningún 404 ni pantalla en blanco: renderiza el componente `ComingSoonBodegaPage`
("Ver inventario") de `BodegaPlaceholderPages.tsx`, que ignora el query param `filter` (esperado —
esa pantalla todavía no existe, Bloque B2 futuro). Confirmado en
`BodegaHomePage.test.tsx:206-228` con test real (`fireEvent.click` + `navigateMock` con la URL
exacta con query string).

**Hallazgo cosmético, no bloqueante — comentario inconsistente entre 2 archivos del mismo batch:**
`Sidebar.tsx:170-173` dice textualmente: *"Solo 'Ver inventario'/'Solicitud de ajuste' tienen
pantalla real hoy; el resto son placeholders"* — pero `BodegaPlaceholderPages.tsx:5-7` lista
explícitamente **"Ver inventario"** como una de las 4 opciones **sin** pantalla real (el que sí
tiene pantalla real es solo "Solicitud de ajuste", vía `SolicitudAjustePage.tsx`, distinto
componente). Confirmé en `App.tsx:288-292` que la ruta `/bodega/inventario` efectivamente renderiza
`BodegaVerInventarioPage` (el placeholder "próximamente"), no una pantalla real — el comentario de
`Sidebar.tsx` está desactualizado/es un error de copy-paste, probablemente arrastrado de una
versión anterior del batch. No tiene impacto funcional (el código y el comportamiento real son
correctos, solo el comentario en `Sidebar.tsx` describe mal cuál de las 2 tiene pantalla real) —
lo dejo señalado para que se corrija el comentario en la próxima pasada por este archivo, no amerita
reabrir el batch solo por esto.

---

## 🟡 Suggestions (no bloqueantes)

**S1 — Sin test dedicado para el auto-expand del `NavDropdown` por ruta activa.** Ver punto 2:
verifiqué la lógica leyendo el código, pero no hay ningún test que monte `Sidebar` con
`initialEntries` apuntando a una de las 5 rutas hijas y afirme que el dropdown ya aparece expandido
sin necesidad de clic. Dado que es el primer patrón de este tipo en el proyecto, agregaría un test
antes de que se toque de nuevo.

**S2 — Comentario desactualizado en `Sidebar.tsx:170-173`** (ver punto 6) — corregir a que solo
"Solicitud de ajuste" tiene pantalla real hoy, "Ver inventario" también es placeholder por ahora.

**S3 — Quirk de UX del `NavDropdown`** (ver punto 2): el usuario no puede colapsar manualmente el
submenú "Inventario" mientras está parado en una de sus 5 pantallas — parece intencional
(resaltado siempre visible) pero vale confirmarlo con UX/UI si alguna vez se reporta como raro.

---

## Notas adicionales verificadas (sin hallazgos)

- Paridad de claves i18n `es`/`en` confirmada programáticamente para `bodega.json:home.*` y
  `common.json:nav.*` — cero claves faltantes de un lado.
- `useBodegaHomeTeam(canViewTeam)` solo se dispara si `canViewTeam` es `true` (gateado por
  `modules.bodega.view_team` del JWT) — coincide con el guard del controller
  (`user->canViewTeamV2(ModuleCatalog::BODEGA)`) que devuelve `{ data: [] }` para quien no puede
  ver Equipo.
- El commit `5234ced` dentro del rango de commits no toca ningún archivo de Home de Bodega — es la
  promoción de un e2e de Batch A4, ya fuera del alcance de esta revisión (ver encabezado).

## Siguiente paso

Sin blockers — puede pasar a Pre-QA. Recomiendo que Pre-QA confirme con Luis/Analista el criterio
de prioridad de "Pendientes" (punto 4) como parte del checklist, ya que el propio batch lo dejó
marcado como pendiente de validación de negocio, no como algo ya cerrado.
