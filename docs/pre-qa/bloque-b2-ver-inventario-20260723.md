# Pre-QA — Bloque B2, "Ver Inventario de Bodega" (SCRUM-329, SCRUM-414→432)

**Fecha:** 2026-07-23
**Alcance:** `atlanticerp-backend` rama `dev` (commits locales `72f7656` feat, `5869849` fix, NO
pusheados al momento de esta pasada), `atlanticerp-frontend` rama `dev` (commits locales `fcd379b` feat,
`ade5dc0` fix, NO pusheados). Senior Review (self-review) ya hecho antes de esta pasada.

**Entorno de verificación:** stack Docker local (`infra-laravel-1`, `infra-postgres-1`,
`infra-nginx-1` puerto 8090) + Vite dev server real (`localhost:5173`). Verificación de API con
`curl` directo contra usuarios reales del roster (`almacen@illuminations.com.pa` = lider_bodega,
`neil.quiel@illuminations.com.pa` = vendedor_disenador sin `bodega.read`) y verificación de UI con
**Playwright real** (`e2e/preqa-scrum329-batchb2-ver-inventario.spec.ts`, 17 tests, headless
Chromium) — el tooling de navegador interactivo (claude-in-chrome) no estaba disponible en esta
sesión (permiso denegado por el harness), así que la corrida en navegador real se hizo con
Playwright en lugar de control manual, con capturas de pantalla revisadas una por una para los
criterios visuales (banner "Inactivo", KPIs, ausencia de iconografía de emoji, estado del modal
antes/después de confirmar) — no solo aserciones de DOM.

Datos de fixture sembrados a mano vía `tinker` (catálogo/stock/pedidos reales vacíos en el entorno
local antes de esta sesión): proveedores "Zona Libre de Colón"/"LuxTech Import"/"Signature
Lighting Co", stock repartido en 2 bodegas para `SPOT-EMP-010`, una orden de compra en tránsito
para `SPOT-EMP-011` (120u, LuxTech, llegada 2026-08-02), líneas de recepción pendientes/confirmadas
para `LAMP-COL-001`/`RIEL-TRK-050`/`APLQ-PAR-020`, 2 pedidos de Bodega activos + 1 entregado para
`CINT-LED-100`, y `MESA-LAMP-015` marcado `is_active=false`.

## Veredicto: NO es pasada limpia — 13 de 14 tickets a `QA`, **SCRUM-432 queda bloqueado en `Dev Testing`**

3 hallazgos CRÍTICOS encontrados. 2 corregidos en el momento (fix chico, re-verificado con la
suite completa de Playwright tras cada fix). El tercero (SCRUM-432, ver detalle) es un hallazgo de
diseño no trivial — requiere que alguien defina qué significa exactamente "rotación"/"artículos
críticos" como filtro de esta pantalla (dos servicios del propio código ya definen ese concepto de
2 formas distintas para otros fines) — **queda como bloqueante explícito, no se resuelve acá.**
3 hallazgos MEDIOS, los 3 corregidos.

---

## Paso 0 — permisos angostos y valores paramétricos

No aplica: ningún criterio de este batch menciona una persona puntual con capacidad especial.

## Hallazgos CRÍTICOS

### 1. [CORREGIDO] SCRUM-417/418/419 — Familias y filtro "Categoría" rotos para cualquier Bodega real (403 silencioso)

**Qué se rompió:** `ProductsTab` (filtro "Categoría") y `FamiliesTab` (pestaña "Familias") pedían
la lista de familias vía `ventasDisenoApi.catalogProductFamilies.list()` →
`GET /ventas-diseno/catalog-product-families`, gateado con `permission:ventas_diseno.read`.
**Ningún rol de Bodega tiene ese permiso** (confirmado con el JWT real de
`almacen@illuminations.com.pa`: `ventas_diseno.view=false`). El backend ya construyó el endpoint
correcto para esto (`GET /bodega/inventory/families`, `bodega.read`,
`BodegaInventoryController::families()`) pero el frontend nunca lo usó.

**Cómo se reprodujo:** `curl` directo con el JWT de `almacen@illuminations.com.pa` contra
`/api/ventas-diseno/catalog-product-families` → `403 {"message":"No tienes permiso..."}`. En
Playwright real, clic en pestaña "Familias" → lista completamente vacía (`- list` sin items en el
snapshot de accesibilidad), timeout esperando cualquier nombre de familia.

**Impacto:** SCRUM-417 (toggle Familias) y SCRUM-418 (vista Familias) completamente no-funcionales
para cualquier usuario real de Bodega — no solo un caso raro, el 100% de los usuarios de Bodega
hoy. SCRUM-419 (filtro "Categoría", parte de "filtros combinables") también roto: el dropdown
"Categoría" queda sin opciones.

**Fix aplicado (chico, re-verificado):**
- `atlanticerp-frontend/src/types/bodega.ts` — nuevo tipo `BodegaInventoryFamilyListItem`.
- `atlanticerp-frontend/src/api/bodegaApi.ts` — nuevo método `inventory.families.list()` apuntando al
  endpoint real ya gateado con `bodega.read`.
- `atlanticerp-frontend/src/hooks/useBodega.ts` — nuevo hook `useBodegaInventoryFamiliesList()`.
- `atlanticerp-frontend/src/pages/BodegaInventarioPage.tsx` — `ProductsTab`/`FamiliesTab` usan el hook
  nuevo en vez de `ventasDisenoApi`; se corrigió también `f.products_count` → `f.product_count`
  (el shape real del endpoint de Bodega no coincide con el de Ventas & Diseño).
- `atlanticerp-frontend/src/pages/BodegaInventarioPage.test.tsx` — mocks actualizados al endpoint real.
- Re-verificado: 17/17 Playwright + 15/15 Vitest de esta página + 511/511 suite completa frontend.

### 2. [CORREGIDO] SCRUM-427 RN2 — el botón "Confirmar llegada física" no desaparecía tras confirmar (snapshot congelado)

**Qué se rompió:** `ProductsTab` guardaba el `row` completo en `detailRow` (estado de React) al
abrir el modal. `useConfirmPhysicalArrival` invalida correctamente la query de la lista tras un
`POST /confirm-arrival` exitoso, pero el modal seguía leyendo el **objeto viejo**, así que
`arrival_confirmation.pending_bodega_action` seguía en `true` — el botón "Confirmar llegada física"
**seguía visible** (y clickeable) inmediatamente después de confirmar, en vez de desaparecer y
mostrar la nota de espera, tal como exige RN2 ("una vez que Bodega confirma, el botón desaparece y
aparece nota de espera").

**Cómo se reprodujo:** Playwright real — login `almacen@illuminations.com.pa`, abrir detalle de
`LAMP-COL-001` (10u "por ingresar" reales, nunca reportadas), confirmar con cantidad exacta (10),
esperar 1.5s, tomar captura (`e2e/.tmp/scrum427-04b-1500ms-after-click.png`, revisada visualmente):
el modal seguía mostrando "Confirmar llegada física" — sin nota de espera, sin mensaje de éxito.
Solo al cerrar y reabrir el modal aparecía el estado correcto ("Ya confirmaste la llegada física —
esperando que Compras finalice el ingreso al inventario.").

**Impacto:** un usuario de Bodega podía, sin saberlo, intentar confirmar la llegada DOS VECES en la
misma sesión de modal — el backend rechaza correctamente la segunda vez (422, "no tiene unidades
pendientes de reportar"), pero la UI no comunica que ya se procesó, generando confusión real.

**Fix aplicado (chico, re-verificado):**
- `atlanticerp-frontend/src/pages/BodegaInventarioPage.tsx` — `detailRow` (objeto) reemplazado por
  `detailRowId` (solo el id); el row mostrado se deriva en cada render desde el array `rows` ya
  refrescado (`rows.find(r => r.id === detailRowId)`), en vez de un snapshot congelado.
- Re-verificado con captura de pantalla: inmediatamente después de confirmar, el botón desaparece y
  la nota de espera aparece SIN cerrar el modal (`e2e/.tmp/scrum427-04b-*.png` tras el fix).

### 3. [BLOQUEANTE, NO corregido] SCRUM-432 RN1 — los deep-links "Mayor rotación"/"Artículos críticos" navegan bien pero el backend ignora el filtro

**Qué se rompió:** El docblock del propio código (`ProductsTab`, comentario REQ-432/362) afirma
que esta funcionalidad "conecta de verdad los enlaces de Bodega Home ('Mayor rotación', 'Artículos
críticos')... deben navegar acá con el filtro ya aplicado" — y en efecto `BodegaHomePage.tsx` ya
navega a `/bodega/inventario?filter=rotacion` y `?filter=criticos` (ya no es una alerta simulada).
Pero **el backend nunca implementó esos 2 valores de chip**: `BodegaInventoryController::index()`
solo reconoce `chip=inactivos`/`chip=por_ingresar`/`chip=en_atencion` — `chip=rotacion` y
`chip=criticos` caen al fallback silencioso (solo `is_active=true`, sin ningún filtro adicional).

**Cómo se reprodujo:** `curl` directo con JWT real — `GET /api/bodega/inventory?chip=rotacion` y
`?chip=criticos` devuelven el catálogo activo COMPLETO (9/9 productos), idéntico a
`GET /api/bodega/inventory` sin ningún chip. El usuario llega a la pantalla, la URL es correcta,
pero la tabla muestra TODO, no un subconjunto filtrado — el filtro "ya aplicado" que promete RN1 no
existe para estos 2 casos.

**Por qué no lo arreglé yo:** no es un typo de código — requiere decidir qué significa "mayor
rotación"/"artículos críticos" como filtro de ESTA pantalla. El propio backend ya tiene 2
definiciones DISTINTAS de conceptos parecidos para otros fines dentro del mismo batch/dominio:
`BodegaHomeService::mayorRotacion()` (top-N por `InventoryMovement` de salidas en una ventana de
días configurable) y `articulosCriticos()` (estado de stock vía `InventoryKpiService::porServirMap()`,
que es el concepto de "por servir" de Compras/Ventas & Diseño, NO el `OrderCommitmentService` que
esta misma pantalla usa para su propia columna "Por servir" — ver docblock de
`BodegaInventoryController`). Mapear cualquiera de los dos a un filtro nuevo en
`BodegaInventoryController::index()` es una decisión de diseño, no una corrección de una línea.

**Nota importante:** el ÚNICO escenario que Jira describe literalmente para SCRUM-432
("Escenario 1 — filtro 'Atención'") SÍ pasa limpio (verificado con Playwright: chip `en_atencion`
vía `?filter=en_atencion` filtra correctamente). El hallazgo de arriba es sobre la RN1 más amplia
del ticket (que sí menciona explícitamente "Mayor rotación"/"Artículos críticos" como parte del
alcance), no sobre el escenario puntual. Por eso el ticket **queda en `Dev Testing`**, no se
transiciona a `QA` — dejarlo pasar solo por el escenario literal hubiera sido repetir "ver el
camino feliz, ignorar el resto de la RN" — exactamente lo que Pre-QA existe para evitar.

## Hallazgos MEDIOS (los 3 corregidos)

### 4. [CORREGIDO] SCRUM-427 — cantidad 0/vacía era un no-op silencioso (sin feedback)

Escribir "0" y apretar "Confirmar y notificar a Compras" no hacía NADA visible — ni error, ni
loading, ni cambio de estado (guard `if (!cantidad || ... || n < 1) return` sin ningún mensaje).
Confirmado visualmente (`e2e/.tmp/scrum427-02-cantidad-cero.png`, antes del fix): el formulario
queda exactamente igual, cero pistas de qué salió mal. Corregido: ahora muestra un mensaje de
validación inline explícito ("Ingresa una cantidad válida (mínimo 1) antes de confirmar.").

### 5. [CORREGIDO] SCRUM-427 — mensaje de error genérico tapaba el detalle real del backend

Al exceder lo pendiente, el backend YA devuelve el mensaje útil ("La cantidad entregada no puede
superar lo pedido pendiente (X unidades)."), pero el frontend siempre mostraba un string genérico
fijo ("No se pudo confirmar la llegada. Intenta de nuevo.") sin importar la causa real. Confirmado
visualmente antes del fix (`e2e/.tmp/scrum427-03-cantidad-excede.png`). Corregido: se muestra el
`message` real de la respuesta 422 cuando existe, con el genérico como fallback.

### 6. [CORREGIDO] SCRUM-431 RN1/RN2 — sin aviso "Inactivo" en el modal, campo "Familia" sin marca de opcional

El Escenario 1 de SCRUM-431 pide literalmente: *"Bodega abre detalle de un producto marcado
'Inactivo' por Compras → aviso 'Inactivo' visible, pero SIN ningún botón para reactivarlo."* El
modal de detalle **no mostraba ningún aviso** — solo la fila de la tabla tenía opacidad reducida;
al abrir el detalle de `MESA-LAMP-015` (`is_active=false`), el modal se veía IDÉNTICO al de
cualquier producto activo (confirmado visualmente, `e2e/.tmp/scrum431-01-inactive-detail.png`
antes del fix). RN2 (campo "Familia" con etiqueta de opcional + texto de ayuda) tampoco existía —
el campo reusaba la etiqueta "Categoría" de la tabla, sin marca de opcional ni ayuda. Corregido:
banner informativo "Este producto está marcado como Inactivo por Compras. Bodega no puede
reactivarlo." (sin ningún botón), + campo "Familia (opcional)" con texto de ayuda
("Un producto puede no tener familia asignada."). Confirmado visualmente después del fix.

## Observaciones (no bloqueantes, documentadas para awareness)

- **SCRUM-424** — la columna "En camino" NO es clickeable cuando vale 0 (muestra "—" sin botón),
  mientras que "Por servir" SÍ es siempre clickeable (incluso en 0, mostrando correctamente "no hay
  clientes o pedidos comprometidos"). Asimetría de UX entre 2 columnas con el mismo patrón de
  drill-down. No viola el criterio escrito literal (un modal inalcanzable no es un "modal vacío sin
  explicación" — simplemente no hay forma de abrirlo), pero vale la pena unificar el criterio en
  algún momento.
- Ningún rol de Bodega sembrado hoy (`lider_bodega`, `asistente_bodega`, `ayudante_general_bodega`)
  tiene `bodega.read` SIN `bodega.write` — no se pudo ejercitar con un usuario real el caso "rol de
  solo lectura no puede confirmar llegada". El middleware del backend sí separa correctamente
  `permission:bodega.read` (rutas GET) de `permission:bodega.write` (POST `/confirm-arrival`), así
  que la separación existe a nivel de ruta aunque ningún rol actual la ejercite.

## Resultado por ticket

| Ticket | Resultado | Escenario(s) de ruptura intentado(s) |
|---|---|---|
| SCRUM-414 | ✅ Pasa | Input vacío N/A (solo lectura); confirmado sin "Crear producto"/"Marcar inactivo", sin precio/costo, sin ningún `<input>` editable en el modal de un producto normal |
| SCRUM-415 | ✅ Pasa | Clic real en "+ Nueva orden de compra", confirmado que NO cae en un form general |
| SCRUM-416 | ✅ Pasa | Producto rotación alta+sin stock (SÍ en atención) vs. rotación baja+sin stock (NO en atención) — ambos casos verificados en la misma pasada |
| SCRUM-417 | ✅ Pasa (tras fix #1) | Antes del fix: 403 silencioso, pestaña vacía, timeout. Tras el fix: toggle sin recarga, ida y vuelta Productos↔Familias |
| SCRUM-418 | ✅ Pasa (tras fix #1) | Vista Familias con datos reales tras el fix; confirmado sin botón "Generar compra" en ningún lado |
| SCRUM-419 | ✅ Pasa (tras fix #1) | Combinación estado=Sin stock + rotación=Alta simultáneos — filtra a 1 producto exacto, excluye el que tiene rotación distinta |
| SCRUM-420 | ✅ Pasa | Chip "Por ingresar" combinado con búsqueda; producto sin nada pendiente correctamente excluido |
| SCRUM-421 | ✅ Pasa | 14 columnas confirmadas por DOM; Stock Total = Disponible+Por servir verificado con producto con compromiso real (147+3=150) |
| SCRUM-422 | ✅ Pasa | Producto con stock en 2 bodegas reales — desglose exacto, sin inventar bodegas sin stock |
| SCRUM-423 | ✅ Pasa | 2 pedidos activos reales + 1 pedido ENTREGADO (que NO debe contar, confirmado ausente del detalle); caso vacío con mensaje explícito, no modal en blanco |
| SCRUM-424 | ✅ Pasa (ver observación) | Orden real en tránsito con proveedor+fecha correctos; caso vacío con "—" no-clickeable (ver observación de asimetría, no bloqueante) |
| SCRUM-427 | ✅ Pasa (tras fix #2, #4, #5) | Cantidad 0 (antes: no-op mudo), cantidad > pendiente (antes: mensaje genérico), doble confirmación en la misma sesión de modal (antes: botón no desaparecía), reload a mitad de flujo (producto ya awaiting_compras se muestra correcto tras recargar) |
| SCRUM-431 | ✅ Pasa (tras fix #6) | Producto Inactivo real: antes sin ningún aviso en el modal, ahora banner visible sin botón de reactivar; campo Familia ahora marcado opcional con ayuda |
| SCRUM-432 | ❌ **Bloqueado — queda en Dev Testing** | Escenario literal (`filter=en_atencion`) pasa; RN1 más amplia (rotación/críticos) falla — backend ignora esos 2 valores de chip silenciosamente |

## Lo que sí funciona (sin ambigüedad, confirmado con datos reales + captura visual donde aplica)

- Gate de solo-lectura real para Bodega: sin botones de creación/inactivación/edición de
  precio-costo en ningún punto de la pantalla, para ningún producto probado.
- Los 5 KPIs (Total, Bajo stock, Sin stock, En atención, Total por servir) se recalculan
  correctamente con datos reales y se ven legibles (revisado visualmente).
- Ningún emoji en ningún punto de la pantalla (KPIs, chips, tabla, modales) — revisado visualmente
  contra la regla SCRUM-56, todos los íconos son el set propio (`IcoAlertTriangle`/`IcoBan`/`IcoBox`).
- Filtros combinables entre sí de verdad (estado+rotación probado, chip+búsqueda probado).
- Columna "Bodega(s)" nunca inventa bodegas sin stock — solo muestra las que tienen `quantity > 0`.
- "Por servir" excluye correctamente pedidos Despachado/Entregado (verificado con un pedido
  Entregado real que NO aparece en el detalle).
- "En camino" trae proveedor/fecha reales de la orden de compra, no datos inventados.
- Confirmar llegada física: reparto FIFO correcto, rechazo 422 estructural si excede lo pendiente,
  idempotencia correcta (confirmar 2 veces la MISMA línea falla con mensaje claro), persiste
  correctamente tras recargar la página.
- Rol sin `bodega.read` (`neil.quiel@illuminations.com.pa`, vendedor/diseñador): la ruta
  `/bodega/inventario` no muestra la tabla real — bloqueado de verdad, no solo un botón escondido.

## Cómo correr esta suite de nuevo

```
atlanticerp-frontend/e2e/preqa-scrum329-batchb2-ver-inventario.spec.ts
```

Requiere: stack Docker local arriba (`infra/docker-compose.yml`), Vite dev server en :5173, y las
fixtures de arriba re-sembradas (los datos de "por ingresar"/pedidos activos se consumen al
confirmar o no persisten entre corridas limpias de base de datos).
