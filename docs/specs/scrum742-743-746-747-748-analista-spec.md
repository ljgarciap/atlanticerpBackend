# Spec Analista — SCRUM-742 / 743 / 746 / 747 / 748

Batch puntual fuera del alcance normal de este hilo (Servicios) — Luis amplió explícitamente el
alcance el 2026-08-13 a Compras (742/743/747), Bodega (748) y Auth (746). Ver
`memory/project_scope_expandido_compras_bodega_auth_20260813.md`.

**Por qué existe este archivo:** la primera versión de esta spec se armó en una sesión que se
cortó antes de persistirla — quedó solo un resumen de una línea por ticket en memoria. Rehecha el
2026-08-13 a pedido explícito de Luis ("rehacer la spec y guardarla porque entonces se perdieron
horas de trabajo"). Esta vez se persiste acá + puntero en memoria, no solo en la conversación.

Reportera de los 5 tickets: Daniela Amaya. Todos en estado `To Do`, sin comentarios, asignados a
Luis. Ninguno tiene código implementado (verificado por `git status`/`git log` en ambos repos:
sin cambios relacionados).

---

## SCRUM-742 — Reorganizar encabezado de Inventario (Compras)

**Tipo:** Task · **Parent:** SCRUM-328 · **Prioridad:** Medium

### Contexto (código actual)
`atlanticerp-frontend/src/pages/InventarioPage.tsx` — todo el header vive inline en el mismo archivo,
sin subcomponentes separados:
- Título "Inventario" simple (línea 66)
- Toggle "Compras / Ventas & Diseño" (línea 145-161, solo visible si `!restricted`)
- Botones "+ Crear nuevo producto" / "Nueva orden" (línea 162-169)
- Grid de 5 tarjetas KPI (línea 173-183, colores hardcodeados)
- Toggle "Productos / Familias" (línea 69-84)
- Buscador + chips de filtro rápido (línea 186-194)

Comparación visual (adjuntos `ENCABEZADO ATLANTICERP.png` vs `ENCABEZADO MOCKUP.png`, descargados a
`/tmp` para este spec) confirma el diagnóstico del ticket: el orden actual no coincide con el
mockup.

### Orden esperado (según ticket, top→bottom)
1. Título — línea 1: **"Inventario - [Usuario]"** (hoy es solo "Inventario", sin nombre de
   usuario — esto es un campo NUEVO, no solo reordenar)
2. Acciones arriba a la derecha, en una sola línea, alineadas verticalmente: **+ Crear nuevo
   producto** → **+ Nueva orden de compra**
3. Toggle Compras/Ventas & Diseño — debajo del header, alineado a la izquierda
4. 5 tarjetas de indicadores — una sola fila
5. Toggle Productos/Familias — alineado a la izquierda, separado visualmente de las tarjetas
6. Búsqueda ("Buscar producto o proveedor...", ancho completo) + fila de filtros rápidos

### ✅ Discrepancia resuelta con Luis (2026-08-13)
El **texto** del ticket solo listaba 2 botones ("+ Crear nuevo producto" + "+ Nueva orden de
compra"), pero la **imagen** `ENCABEZADO MOCKUP.png` adjunta muestra **3 botones** ("Proveedores"
+ los 2 anteriores) más un subtítulo **"Vista de consulta · Bodega es dueño del dato, aquí no se
edita"** que el texto tampoco mencionaba.

**Decisión de Luis: el mockup manda completo.** Alcance confirmado — implementar los 3 botones
("Proveedores" → "+ Crear nuevo producto" → "+ Nueva orden de compra", en ese orden, alineados
verticalmente) y el subtítulo bajo el título, tal cual la imagen. El texto original del ticket
queda tratado como incompleto en este punto, no como restrictivo.

### Criterios de aceptación
1. Header queda: Encabezado → Acciones → Toggle Compras/Ventas&Diseño → Indicadores → Toggle
   Productos/Familias → Búsqueda → Filtros → Filtros rápidos → Tabla, en ese orden exacto.
2. Título de línea 1 muestra "Inventario - [Usuario]" con el nombre del usuario autenticado, más
   el subtítulo "Vista de consulta · Bodega es dueño del dato, aquí no se edita" debajo.
3. Tres botones de acción, en este orden, alineados verticalmente entre sí, arriba a la derecha:
   "Proveedores" → "+ Crear nuevo producto" → "+ Nueva orden de compra".
4. Las 5 tarjetas KPI en una sola fila.
5. Posición, alineación, espaciado y jerarquía visual equivalentes al mockup — no solo presencia
   de los elementos (criterio explícito del ticket, mismo estándar que usa Visual Reviewer).

---

## SCRUM-743 — Filtro multiselección de Bodegas en Inventario (feature nueva)

**Tipo:** Story · **Parent:** SCRUM-328 · **Prioridad:** Medium

### Contexto (código actual)
`InventarioPage.tsx` hoy solo tiene búsqueda de texto + chips categóricos (todos/en
atención/inactivos/por ingresar) — **no existe ningún filtro de bodegas**, ni siquiera
single-select. Las bodegas hoy solo aparecen como **texto de solo lectura** dentro de cada fila de
la tabla (línea 278-281, `warehouse_name + quantity` por producto).

Ya existe lo reutilizable:
- Endpoint `/compras/warehouses` (`comprasApi.warehouses.list()`) — retorna el array completo de
  bodegas.
- Modelo backend `Bodega/Models/Warehouse.php`.

Nota: el mockup de SCRUM-742 (`ENCABEZADO MOCKUP.png`) ya muestra un dropdown **"Todas las
bodegas"** entre los filtros — pero como *single-select* aparente. SCRUM-743 pide explícitamente
que sea **multiselección**, así que el mockup no alcanza como referencia visual completa para este
ticket — construir el componente nuevo seguiendo el comportamiento descrito en el texto, no la
imagen.

### Criterios de aceptación (ya explícitos y completos en el ticket original, sin ambigüedad)
1. Nuevo filtro "Bodegas" en Inventario, junto a los filtros existentes (no los reemplaza).
2. Al hacer clic despliega la lista completa de bodegas del sistema.
3. Selección múltiple simultánea — 1, 2, 3... o todas.
4. Opción "Seleccionar todas" y opción "Limpiar selección".
5. Se puede quitar una bodega individual sin limpiar el resto.
6. Sin selección = mostrar todas las bodegas (nunca tabla vacía por default).
7. La selección vigente queda visible en el filtro (chip/badge, no solo el dropdown cerrado).
8. La tabla refleja los resultados combinando: bodegas seleccionadas + resto de filtros activos.
9. Nunca implementar como dropdown de selección única.

### Puntos técnicos para el Arquitecto
- Definir el componente: ¿multiselect con checkboxes propio del design system, o ya existe algo
  reusable en otro módulo (Grupo D Herramientas usa filtros similares — revisar antes de crear de
  cero)?
- Definir contrato del filtro combinado: ¿la tabla de Inventario ya soporta filtrar por
  `warehouse_id[]` en el backend, o hay que agregar el parámetro al endpoint de listado de
  productos?

---

## SCRUM-746 — Sesión expira por inactividad consecutiva (no absoluta desde login)

**Tipo:** Task · **Parent:** SCRUM-327 (Auth) · **Prioridad:** Medium

### Contexto (código actual) — cambio de arquitectura real, no ajuste cosmético
Confirmado: **hoy no existe ningún concepto de "última actividad"** en el sistema.
- Backend: `JWT_EXPIRATION_MS` (config `jwt.php`) define expiración **absoluta desde el
  login** — `JwtService::47` calcula `exp = now_del_login + ms`. `RefreshToken` (modelo) solo
  tiene `expires_at`, sin ningún campo de actividad.
- Frontend: `authStore.ts` tiene un flag `sessionExpired` pero sin timer de inactividad;
  `authApi.ts` solo reacciona a un 401 ya ocurrido (interceptor reactivo, no proactivo).

Este ticket requiere agregar, no ajustar:
1. Un campo de "última actividad válida" persistido (ej. `last_activity_at` en `refresh_tokens`
   o tabla de sesión nueva) que se actualice en cada request autenticado que cuente como
   actividad real (RN4 del ticket: navegar, abrir, consultar, buscar, filtrar, crear, editar,
   guardar, ejecutar acciones — explícitamente NO cuenta solo tener la pestaña abierta).
2. Lógica de expiración que compare `now - last_activity_at` contra el valor configurado
   (10h default), no contra el momento del login.
3. El valor de horas configurable — **candidato directo a la tabla paramétrica de
   umbrales/configuración de negocio** que exige el CLAUDE.md raíz (regla "Nunca hardcodear
   umbrales de negocio") — mismo patrón que `share_links_expiration_days` en
   `auth.system_settings`.
4. Frontend: mecanismo que detecte actividad real del usuario (no solo la pestaña abierta) y la
   reporte al backend, o al menos sincronice con la expiración real para poder avisar/cerrar
   sesión en el momento correcto.

### Criterios de aceptación (ya explícitos en el ticket, resumidos)
1. Tiempo configurable, default 10h.
2. El contador representa inactividad, no duración total de sesión.
3. Cada actividad válida reinicia el contador al valor configurado vigente (no al valor que
   estaba configurado al momento del login).
4. Cambiar el valor de config (10→5→2h) aplica de inmediato al nuevo comportamiento, sin
   depender del login original.
5. Tener la app abierta sin interacción NO reinicia el contador.
6. Al cumplirse el tiempo sin actividad, la sesión expira y el usuario va al login.
7. Comportamiento consistente en todos los módulos de AtlanticERP.

### Punto para el Arquitecto — antes de implementar
Definir explícitamente **qué endpoints/acciones cuentan como "actividad válida"** — el ticket da
ejemplos genéricos (navegar, abrir, consultar, buscar, filtrar, crear, editar, guardar, ejecutar
acciones) pero la implementación real necesita una lista concreta o un criterio programático (ej.
"cualquier request autenticado a `/api/*` excepto polling/heartbeat silencioso", si es que se
agrega alguno). Esto es una decisión técnica dentro del diseño aprobado, no de negocio — la
resuelve el Arquitecto, no requiere volver a Luis.

---

## SCRUM-747 — Reestructurar navegación de Compras en 3 submenús desplegables

**Tipo:** Task · **Parent:** SCRUM-328 · **Prioridad:** Alta (la más alta de las 5)

### Contexto (código actual) — el patrón que se necesita YA EXISTE, usado en otro módulo
`atlanticerp-frontend/src/components/Sidebar.tsx`:
- Interface `NavDropdown` (línea 73-78) ya implementa exactamente este patrón: item de nivel
  superior que despliega una lista de hijos, con estado manejado por `openDropdowns` (Set) +
  `toggleDropdown()`, y auto-expansión cuando un hijo está activo (línea 455-456).
- Ejemplo real funcionando hoy: en **Bodega**, "Inventario" (`id: bodega-inventario-menu`) es un
  dropdown con 5 opciones hijas. El ticket mismo pide "mismo principio utilizado en la navegación
  estándar del módulo Bodega" (RN8) — literalmente este componente.
- Hoy Compras es una **lista plana** sin dropdowns — falta aplicar el patrón ya existente, no
  crear uno nuevo.

### Estructura exacta esperada (7 accesos de nivel superior)
```
Inicio
Órdenes ▾        → Ver Órdenes | Nueva Orden | Logística & Envío
Catálogo y Stock ▾ → Inventario | Ver Registros de Ingreso | Comparación de Referencias
Pagos ▾          → Pagos a Proveedores | Agencias de Liquidación
Proveedores
Garantías & Reclamos
Reportes
```

### Renombres obligatorios (no solo reorganizar, también renombrar)
| Actual | Nuevo |
|---|---|
| Logística | **Logística & Envío** |
| Sustitutos | **Comparación de Referencias** |
| Agencias de Liquidación | *(sin cambio — el ticket aclara explícitamente NO usar "Pagos Operativos" pese a mencionarlo)* |

### Criterios de aceptación (7 escenarios ya definidos en el ticket, Dado/Cuando/Entonces)
1. Estructura principal: exactamente los 7 accesos, en ese orden.
2. Dropdown "Órdenes" con exactamente 3 hijos.
3. Dropdown "Catálogo y Stock" con exactamente 3 hijos.
4. Dropdown "Pagos" con exactamente 2 hijos.
5. Pantalla activa: el dropdown padre queda marcado activo + el hijo resaltado dentro (RN6).
6. No duplicación: ningún ítem que vive dentro de un dropdown aparece también como acceso
   independiente de nivel superior (RN5).
7. Consistencia: la estructura no cambia entre pantallas de Compras (RN8).

Sin ambigüedad relevante — este ticket es el más directo de los 5 para pasar a Arquitecto/PM tal
cual.

---

## SCRUM-748 — Data de prueba: 40 proyectos aprobados en TEST (Ventas & Diseño → Bodega)

**Tipo:** Task (etiquetado DATA) · **Parent:** SCRUM-329 (Bodega) · **Prioridad:** Medium

### Contexto (código actual) — el flujo de integración YA EXISTE end-to-end
Confirmado: la integración Ventas & Diseño → Bodega funciona hoy, no hay que construirla:
- `VentasDiseno/Models/SalesProject.php` + `PipelineCard.php` (stages: lead → design → quote →
  proposal → **approved**).
- Al pasar `PipelineCard` a `approved`, dispara evento `QuoteApproved` → listener
  `Bodega/Listeners/CreateOrderFromApprovedQuote.php` → `OrderService::createFromApprovedQuote()`
  → crea automáticamente un `Order` en Bodega, con FK `pipeline_card_id`. Listener con try/catch
  robusto (una falla de Bodega no bloquea la aprobación en Ventas).
- Ya existe `database/seeders/VentasDisenoDemoSeeder.php`, pero solo siembra **1** `PipelineCard`
  en stage `approved` (línea 85) — lejos de los 40 que pide el ticket.

Esto es un ticket de **datos**, no de código nuevo de producto — el trabajo real es extender/crear
un seeder (o script) que:
1. Cree 40 `SalesProject`/`PipelineCard` válidos (con todos los campos obligatorios de la etapa
   aprobado completos — no registros vacíos "de relleno", ver nota del ticket).
2. Los distribuya entre **distintos vendedores** (no exige cantidad pareja, sí variedad real de
   casos).
3. Los deje en stage `approved` para que el listener existente dispare la creación automática en
   Bodega — **no** insertar directamente en `Order`/Bodega a mano, tiene que pasar por el flujo
   real (mismo criterio que "válidos y utilizables para pruebas", RN de integridad del ticket).
4. Corra solo en **TEST** (`test.atlanticerp.ai`, `APP_ENV=qa`) — mismo gating que otros seeders demo
   del proyecto (`AuthUserSeeder`/`CrmDemoSeeder` solo corren en `local`/`dev`/`testing`; este es
   el primer seeder pensado explícitamente para `qa`, confirmar con Arquitecto si reusa el mismo
   gate de entornos o necesita uno propio).

### Criterios de aceptación (5 escenarios, ya explícitos en el ticket)
1. Existen 40 proyectos válidos en Ventas & Diseño.
2. Los 40 en etapa "Aprobados".
3. Distribuidos entre distintos vendedores.
4. Los 40 aparecen en Bodega como "proyectos asignados" (vía el flujo real, no inserción manual).
5. Integridad: sin duplicados, sin huérfanos (aprobado en Ventas sin reflejo en Bodega).

### Punto para el Arquitecto
Decidir el mecanismo: ¿Artisan seeder nuevo (`ProyectosPruebaBodegaSeeder`) corrido una sola vez
en TEST, o un comando repetible (`bodega:seed-test-data --count=40`)? Dado que es data puntual
para UAT (no un fixture que se re-siembra en cada deploy como los seeders `dev`/`local`), un
comando one-shot documentado en la memoria del proyecto probablemente alcanza — evaluar si vale
la pena el overhead de un seeder permanente en `DatabaseSeeder.php`.

---

## Resumen de handoff — próximo paso

Spec del Analista completa para los 5 tickets, **sin ambigüedades abiertas** — la discrepancia de
SCRUM-742 quedó resuelta por Luis el 2026-08-13 (el mockup manda completo). Los 5 tickets tienen
criterios de aceptación suficientemente explícitos para pasar directo a diseño técnico del
Arquitecto.

1. SCRUM-747 es el más directo/aislado (solo frontend, patrón ya existente) — candidato a
   arrancar primero si se prioriza por menor riesgo/mayor velocidad.
2. SCRUM-746 es el de mayor riesgo técnico (cambio de arquitectura de sesión, toca todos los
   módulos) — candidato a más tiempo de diseño antes de asignar a Backend/Frontend Dev.
3. Nota abierta para el Arquitecto en SCRUM-742 (no bloqueante, no de negocio): el subtítulo
   "Bodega es dueño del dato, aquí no se edita" — confirmar si es solo texto informativo o si
   implica reforzar/verificar que la vista ya es de solo lectura (evitar que el subtítulo prometa
   algo que el frontend no garantiza realmente).
