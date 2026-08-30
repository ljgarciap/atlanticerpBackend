# Bodega (SCRUM-329) — bloques de abordaje del resto del épico

**Fecha:** 2026-07-23
**Por qué existe este documento:** en una sesión anterior se habían definido bloques de trabajo
para el resto del épico, pero la definición no quedó guardada en ningún lado (ni memoria, ni
repo, ni Jira) y se perdió. Este documento fija esa definición para que no vuelva a pasar.

**Alcance:** los 100 tickets de SCRUM-329 en estado `In Progress` al 2026-07-23 (después de cerrar
Batch A2 — backend de Pedidos, 14 tickets ahora en `QA`). De esos 100, 2 (SCRUM-377/378) ya están
funcionalmente completos — Luis decidió dejarlos en `In Progress` a propósito (ver
`docs/pre-qa/scrum329-batch-a1-fundacion-bodega-2026-07-22.md`) en vez de transicionarlos, así que
no cuentan como trabajo pendiente real. Quedan **98 tickets sin construir**.

Agrupación hecha por el campo `UBICACION:` real de cada ticket en Jira (no por el título ni por
cercanía de número) — verificado ticket por ticket, no inferido.

---

## Batch A3 — Pedidos: tablero frontend (16 tickets)

Continuación directa de Batch A2 (backend, cerrado hoy) — el motor ya existe, esto es la UI que lo
consume. Máxima prioridad: ROI inmediato, mismo contexto fresco del equipo.

| Ticket | REQ | Título | Status real (2026-07-24) |
|---|---|---|---|
| SCRUM-375 | REQ-305 | Encabezado — título, contador de pedidos y botones de acción | ✅ QA |
| SCRUM-376 | REQ-306 | Panel de carga por asistente (61/39) con detección de desbalance | ✅ QA |
| SCRUM-379 | REQ-309 | Filtros — buscador, asistente, cliente, vendedor | ✅ QA |
| SCRUM-380 | REQ-310 | Chips de filtro rápido — Urgentes, Atrasados, Sin stock | ✅ QA |
| SCRUM-381 | REQ-311 | Tablero Kanban — estructura de 7 etapas | ✅ QA |
| SCRUM-382 | REQ-312 | Tarjeta de pedido — información visible en el tablero | ✅ QA |
| SCRUM-397 | REQ-327 | Etapa Por despachar — factura de Administración (pasiva) | ✅ QA |
| SCRUM-400 | REQ-330 | Documento — Guía de Entrega | ✅ QA |
| SCRUM-402 | REQ-332 | Modal "Ver detalle" del pedido | ✅ QA |
| SCRUM-404 | REQ-334 | Enlace a Status de pedidos | ✅ QA |
| SCRUM-405 | HU-335 | Números de pedido y trazabilidad entre tarjetas | ✅ QA |
| SCRUM-390 | REQ-320 | Descarga a Excel de la Hoja de Picking y del detalle de artículos | ⚠️ Backend 🟢 aprobado, frontend sin wirear — ver "Bloque A3-cierre" abajo |
| SCRUM-391 | REQ-321 | Consolidado del día por picker ("Imprimir picking del día") | ⚠️ ídem |
| SCRUM-401 | REQ-331 | Documento — Factura | ⚠️ ídem |
| SCRUM-403 | REQ-333 | Modal "Ver bodegas" — desglose de stock por ubicación | ⚠️ ídem |
| SCRUM-406 | REQ-336 | Tarjeta sin stock — fecha estimada de llegada visible en el tablero | ⚠️ ídem |

*(SCRUM-377/378 ya completos — Batch A1, dejados en `In Progress` a propósito — no se listan como
pendientes.)*

### Bloque "A3-cierre" (2026-07-24) — ⚠️ PENDIENTE DE VISUAL REVIEWER + PRE-QA, no pushear todavía

**Status para la próxima sesión, leer esto ANTES de tocar nada de Batch A3:**
- Implementación de los 5 tickets: **hecha**, Senior Review 🟢 propio (independiente, no solo el
  reporte del subagente — diffs leídos completos, `npx vitest run` 519/519 y `npm run build`
  corridos a mano en el worktree antes de mergear).
- Mergeado a **`dev` LOCAL** en `atlanticerp-frontend` (fast-forward, commits `b833e55..9f78957`),
  **sin push a origin** — sigue el protocolo de este proyecto (push solo con OK explícito de
  Luis, ver `feedback_push_workflow` en memoria).
- **Falta correr, en ese orden, antes de pushear:**
  1. **Visual Reviewer** (`atlanticerp/.claude/agents/visual-reviewer.md`, gate creado 2026-07-24) —
     comparar la app real (Playwright CLI, no Claude in Chrome) contra el mockup
     `3A__Bodega_Pedidos.html` (adjunto de SCRUM-391) para los 5 elementos: modal "Ver bodegas"
     (chip por artículo), modal de consolidado con selector de chip de picker, modal de estado de
     factura, botón de descarga Excel, nota de ETA en la tarjeta.
  2. **Pre-QA** (despachado como subagente real, ver `feedback_preqa_dispatch_real_subagent`) —
     adversarial, corriendo la app de verdad, con foco en el gap de permisos ya documentado abajo.
  3. Recién ahí: push a `dev` (origin) + transición de los 5 tickets a `QA` en Jira.
- **Decisión de producto sin resolver** (marcada para Luis, no la tomé yo): SCRUM-391 — un actor
  sin `modules.bodega.view_team` (ej. Picker/Repartidor) ve la lista de chips de picker vacía en
  el modal de consolidado, aunque el botón siga visible para todos los perfiles (así lo pide la
  spec). ¿Es aceptable o hace falta ajustar el permiso/contrato? Ver docblock de
  `ConsolidatedPickingModal.tsx` para el detalle técnico completo.

Detalle de qué se hizo, ticket por ticket, spec confirmada contra mockup/Excel de requerimientos:

Descubierto en Pre-QA/daily del 2026-07-24: Senior Review aprobó 🟢 el backend de estos 5 el
2026-07-23 (`docs/reviews/oleada-a-batch-a3-pedidos-backend-2026-07-23.md`), pero el frontend
nunca se conectó — quedaron botones `disabled` con comentarios que ya mienten ("backend todavía
no la construye"). Spec confirmada contra `3__Requerimientos_Bodega.xlsx` + mockup
`3A__Bodega_Pedidos.html` (adjuntos de SCRUM-391), no inventada:

| Ticket | Qué falta en frontend (confirmado en mockup) |
|---|---|
| SCRUM-403 | Link "📦 Ver bodegas" en la fila de ítem expandido → abre modal con desglose por bodega (`abrirModalBodegas`, mockup línea ~2093). Contrato backend ya listo (`warehouse-breakdown`, resuelve todos los ítems del pedido en una sola llamada). |
| SCRUM-391 | Botón abre un **modal** "Consolidado del día — por picker" con selector tipo **chip, uno a la vez** (no multiselect) — mockup línea ~1323/2749. El endpoint pide `picker_id` — por eso hace falta el selector, no auto-usar el usuario logueado. |
| SCRUM-401 | El link "✓ ver" (ya existe en la tarjeta) debe abrir un preview tipo `modalFactura` del mockup — pero con alcance reducido a propósito (decisión ya tomada por Luis, ver `OrderInvoiceController` docblock): solo muestra el `status_message` del backend, nunca genera/calcula el documento real. |
| SCRUM-390 | Dos botones "⬇️ Descargar Excel" separados en el mockup: uno en la tabla de detalle de ítems (mismo modal que SCRUM-403, wireable ya) y otro dentro del modal de Hoja de Picking (`#modalPicking`, mockup línea ~1265) — **ese segundo no se puede wirear todavía porque el modal de Hoja de Picking no existe en el frontend**, ver bloque nuevo abajo. |
| SCRUM-406 | Atributo `data-eta` / clase `.kcard-eta` en la tarjeta del mockup ("📦 Llegada estimada del proveedor: ...") — el backend ya expone el campo en el payload del board, falta leerlo y pintarlo en `OrderCardTile`. |

## Bloque nuevo — Hoja de Picking (pantalla faltante, separado a pedido de Luis 2026-07-24)

**No confundir con el bloque A3-cierre de arriba** — Luis pidió explícitamente no mezclarlos.
Hallazgo del daily 2026-07-24: SCRUM-383/384/385/386 están en `QA` en Jira, pero esa pasada de
Pre-QA fue **backend-only** (`docs/pre-qa/scrum329-batch-a2-pedidos-backend-2026-07-22.md`) — no
existe ningún componente, hook ni llamada a `assign-picker`/`start-picking`/`complete-picking`/
`picking-sheet` en todo `atlanticerp-frontend`. Un operario de Bodega hoy no puede hacer picking desde
la app. Confirmado con el propio comentario del código: `OrderCardTile.tsx` admite que "no existe
todavía el modal de 'Asignar Picker'".

| Ticket | REQ | Título | Status Jira (engañoso) |
|---|---|---|---|
| SCRUM-383 | REQ-313 | Etapa Asignado → asignar quién hace el picking | QA (sin frontend) |
| SCRUM-384 | REQ-314 | Etapa Picking pendiente → iniciar el trabajo de picking | QA (sin frontend) |
| SCRUM-385 | REQ-315 | Hoja de Picking — estructura y contenido | QA (sin frontend) |
| SCRUM-386 | REQ-316 | Hoja de Picking — completar el picking | QA (sin frontend) |

Mockup de referencia: `#modalPicking` en `3A__Bodega_Pedidos.html` (mismo archivo adjunto a
SCRUM-391). Pendiente: (1) que PM/Luis corrija el status Jira de estos 4 a algo que refleje la
realidad (no `QA` con feature no navegable), (2) spec formal de Analista/Arquitecto antes de
asignar — no se scopeó en detalle en esta sesión, solo se identificó el gap.

## Batch A4 — Status de Pedidos (7 tickets) — ✅ COMPLETO, en QA (2026-07-23)

Pantalla de consulta separada, compartida con Ventas & Diseño. Depende de la misma fuente de datos
que Batch A3 (REQ-343: "misma fuente de datos, incluye divisiones parciales") — natural hacerla
inmediatamente después.

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-407 | REQ-337 | Acceso a la pantalla — botón de entrada (Pedidos) y de regreso (Status) |
| SCRUM-408 | REQ-338 | Buscador y tabla principal de pedidos |
| SCRUM-409 | REQ-339 | Modal Detalle del pedido — datos generales |
| SCRUM-410 | REQ-340 | Modal Detalle del pedido — tabla de productos |
| SCRUM-411 | REQ-341 | Botón "Ver/Descargar" — documento Estatus de Pedido |
| SCRUM-412 | REQ-342 | Acceso compartido con Ventas & Diseño (vendedores y diseñadores) |
| SCRUM-413 | REQ-343 | Relación con Pedidos (3A) — misma fuente de datos, incluye divisiones parciales |

## Bloque B1 — Home de Bodega (12 tickets) — ✅ COMPLETO, en QA (2026-07-23)

Dashboard de inicio. Varios paneles leen datos de Pedidos (Despachos urgentes, Por recibir, Estado
de pedidos) — conviene después de A3/A4 para mostrar data real, no mockeada.

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-363 | REQ-293 | Navegación general — sidebar, topbar y subtabs (aplica a *todas* las pantallas del módulo) |
| SCRUM-364 | REQ-294 | Saludo personalizado con nombre del usuario en sesión |
| SCRUM-365 | REQ-295 | Toggle "Inicio / Equipo" — alcance de la vista |
| SCRUM-366 | REQ-296 | Selector de persona dentro de "Equipo" |
| SCRUM-367 | REQ-297 | Panel "Mi día" / "Día del equipo" |
| SCRUM-368 | REQ-298 | Panel "Mi calendario" / "Calendario del equipo" |
| SCRUM-369 | REQ-299 | Panel "Pendientes" |
| SCRUM-370 | REQ-300 | Panel "Despachos urgentes" |
| SCRUM-371 | REQ-301 | Panel "Por recibir" |
| SCRUM-372 | REQ-302 | Panel "Estado de pedidos" — resumen por etapa |
| SCRUM-373 | REQ-303 | Panel "Mayor rotación" |
| SCRUM-374 | REQ-304 | Panel "Artículos críticos" |

## Bloque B2 — Catálogo / Ver inventario (16 tickets)

Vista de Bodega sobre el catálogo de productos (Compras es dueño del dato). Bajo riesgo relativo —
el módulo Inventario/Compras ya tiene buena parte construida (Sprint 2, ver `CLAUDE.md`).

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-414 | REQ-344 | Encabezado — vista de consulta, Compras es dueño del dato |
| SCRUM-415 | REQ-345 | Botones de acción — Ir a ver Órdenes / Nueva orden (redirige a Zona Libre) |
| SCRUM-416 | REQ-346 | KPIs — Total de productos, Bajo stock, Sin stock, En atención, Total por servir |
| SCRUM-417 | REQ-347 | Toggle de vista — Productos / Familias |
| SCRUM-418 | REQ-348 | Vista Familias — agrupación por proveedor, sin acción de compra para Bodega |
| SCRUM-419 | REQ-349 | Filtros — buscador, categoría, estado, rotación, bodega, proveedor, marca |
| SCRUM-420 | REQ-350 | Chips de filtro rápido — Atención, Inactivos, Por ingresar |
| SCRUM-421 | REQ-351 | Tabla de catálogo — las 15 columnas |
| SCRUM-422 | REQ-352 | Columna Bodega(s) — desglose de stock por ubicación |
| SCRUM-423 | REQ-353 | Columna Por servir — detalle de compromisos con clientes |
| SCRUM-424 | REQ-354 | Columna En camino — detalle de la orden de compra |
| SCRUM-425 | REQ-355 | Ver ficha técnica del producto |
| SCRUM-426 | REQ-356 | Ficha técnica obligatoria al crear un producto (vive en Compras — Crear producto) |
| SCRUM-427 | REQ-357 | Confirmar llegada física (Bodega) — distinto del ingreso de Compras |
| SCRUM-431 | REQ-361 | Modal Ver detalle de producto — campos de solo lectura para Bodega |
| SCRUM-432 | REQ-362 | Entrada a la pantalla con filtro pre-aplicado (deep link) |

## Bloque B3 — Zona Libre de Colón (13 tickets)

Nueva orden de compra + bandeja de órdenes. Funcionalmente es más de Compras que de Bodega puro,
pero vive bajo este épico en Jira — sin dependencia dura de Pedidos, se puede coordinar en paralelo
con cualquiera de los bloques anteriores si hay squad de Compras disponible.

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-433 | REQ-363 | Restricción de proveedor único — Zona Libre de Colón |
| SCRUM-434 | REQ-364 | Buscador y tabla de productos del proveedor |
| SCRUM-435 | REQ-365 | Un producto agregado desaparece de la lista disponible |
| SCRUM-436 | REQ-366 | Carrito de líneas de la orden — editar, quitar, totales |
| SCRUM-437 | REQ-367 | Ver bodegas — desglose de stock por ubicación |
| SCRUM-438 | REQ-368 | Detalles del envío — modalidad fija, tipo de envío y llegada estimada |
| SCRUM-439 | REQ-369 | Guardar orden — numeración automática y estado inicial |
| SCRUM-440 | REQ-370 | Regla de negocio — ciclo de vida completo de una orden Zona Libre |
| SCRUM-441 | REQ-371 | Panel de orden guardada — navegación y crear otra |
| SCRUM-442 | REQ-372 | Bandeja de órdenes Zona Libre — tabla y encabezado |
| SCRUM-443 | REQ-373 | Chips de filtro por estado — Todas, Por aprobar, Aprobadas, Rechazadas |
| SCRUM-444 | REQ-374 | Los 3 estados de una orden y su acción correspondiente |
| SCRUM-445 | REQ-375 | Botón Recordar — aviso a Yirena sobre una orden pendiente |

## Bloque B4 — Bodegas: reubicación entre bodegas (4 tickets)

Construye sobre B2 (necesita la vista de catálogo/inventario ya lista).

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-454 | REQ-384 | Chips Todos / Espacio libre |
| SCRUM-457 | REQ-387 | Solicitar reubicación de un producto entre bodegas |
| SCRUM-458 | REQ-388 | Regla de negocio — aprobación y efecto de una reubicación |
| SCRUM-459 | REQ-389 | Bandeja de Solicitudes de reubicación — historial, filtros y acciones |

## Bloque B5 — Inventario general: conteos (7 tickets)

Conteo cíclico/general de inventario. Misma dependencia que B4 (necesita B2).

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-460 | REQ-390 | Iniciar un conteo general — selector de bodega y carga de productos |
| SCRUM-461 | REQ-391 | Aviso de cruce con solicitud cíclica pendiente |
| SCRUM-462 | REQ-392 | Evaluar el conteo — cálculo de diferencias |
| SCRUM-463 | REQ-393 | Enviar conteo a aprobación — validaciones y manejo de cruce |
| SCRUM-464 | REQ-394 | Regla de negocio — el conteo general reemplaza la solicitud cíclica pendiente |
| SCRUM-465 | REQ-395 | Bandeja de conteos generales — historial, filtros y aprobación ⚠️ *descripción vacía en Jira, falta completar antes de asignar* |
| SCRUM-466 | REQ-396 | Realizar ajuste — aplicar un conteo ya aprobado al inventario |

## Bloque B6 — Devoluciones (17 tickets)

Ciclo completo de devolución de cliente. Depende de la Guía de Entrega (documento de Batch A3,
REQ-414: "Buscar la guía de entrega original — punto de partida obligatorio") — necesita A3
completo antes de arrancar.

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-473 | REQ-403 | Bandeja de devoluciones — tabla, filtros y encabezado |
| SCRUM-474 | REQ-404 | Producto(s) expandible cuando una devolución tiene varios |
| SCRUM-475 | REQ-405 | Ver detalle — modal compacto con historial cronológico |
| SCRUM-476 | REQ-406 | Ver formulario — documento imprimible con firma simulada |
| SCRUM-477 | REQ-407 | Ver guía de entrega original — trazabilidad contra lo realmente entregado |
| SCRUM-478 | REQ-408 | Cargar documento firmado por el cliente |
| SCRUM-479 | REQ-409 | Confirmar recepción física — multi-producto, bodega destino, ingreso a inventario |
| SCRUM-480 | REQ-410 | Regla de negocio — efecto de confirmar la recepción física |
| SCRUM-481 | REQ-411 | Regla de negocio — rechazo de una devolución es potestad exclusiva de Bodega |
| SCRUM-482 | REQ-412 | Simular finalización — nota de crédito completada por Administración |
| SCRUM-483 | REQ-413 | Regla de negocio — los 5 estados del ciclo de vida de una devolución |
| SCRUM-484 | REQ-414 | Buscar la guía de entrega original — punto de partida obligatorio |
| SCRUM-485 | REQ-415 | Autocompletar datos al seleccionar la guía |
| SCRUM-486 | REQ-416 | Datos de contacto de quien devuelve el producto |
| SCRUM-487 | REQ-417 | Selección de productos a devolver con tope de cantidad |
| SCRUM-488 | REQ-418 | Motivo de devolución por producto |
| SCRUM-489 | REQ-419 | Guardar la devolución — validaciones y estado inicial |

## Bloque B7 — Reportes de Bodega (6 tickets)

Depende de que exista data real de todos los módulos anteriores — al final de la secuencia.

| Ticket | REQ | Título |
|---|---|---|
| SCRUM-490 | REQ-420 | Encabezado y selector de período |
| SCRUM-491 | REQ-421 | Reporte — Productividad por operativo |
| SCRUM-492 | REQ-422 | Reporte — Precisión de inventario |
| SCRUM-493 | REQ-423 | Reporte — Capacidad por bodega |
| SCRUM-494 | REQ-424 | Reporte — Inventario: rotación y atención |
| SCRUM-495 | REQ-425 | Cada reporte lleva a su pantalla de detalle correspondiente |

---

## Resumen y secuencia sugerida

| Orden | Bloque | Tickets | Dependencia |
|---|---|---|---|
| 1 | Batch A3 — Pedidos frontend | 16 | Backend ya listo (hoy) |
| 2 | Batch A4 — Status de Pedidos | 7 | Misma fuente de datos que A3 |
| 3 | Bloque B1 — Home de Bodega | 12 | Paneles leen datos de A3/A4 |
| 4 | Bloque B2 — Catálogo/Ver inventario | 16 | Reutiliza módulo Compras/Inventario existente |
| 5 | Bloque B4 — Reubicación entre bodegas | 4 | Necesita B2 |
| 6 | Bloque B5 — Conteos de inventario | 7 | Necesita B2 |
| 7 | Bloque B6 — Devoluciones | 17 | Necesita Guía de Entrega (A3) |
| 8 | Bloque B7 — Reportes | 6 | Necesita data real de todo lo anterior |
| — | Bloque B3 — Zona Libre de Colón | 13 | Sin dependencia dura — paralelizable con squad de Compras en cualquier momento |

**Total:** 98 tickets nuevos + 2 ya completos (SCRUM-377/378) = 100.

Esta secuencia es una recomendación basada en dependencias de datos, no una decisión tomada —
queda a criterio de Luis/PM confirmarla o reordenarla antes de asignar.
