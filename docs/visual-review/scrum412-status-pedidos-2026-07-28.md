# Visual Review — SCRUM-412 (REQ-342, "Acceso compartido con Ventas & Diseño") — 2026-07-28

**Reviewer:** Visual Reviewer (AtlanticERP)
**Ticket:** SCRUM-412 — status real al momento de este review: **Dev Testing**. Ya pasó Pre-QA
limpio el 2026-07-23 (`docs/pre-qa/scrum407-413-batch-a4-status-pedidos-2026-07-23.md`, batch A4
completo, sección "SCRUM-412 (REQ-342)" sin hallazgos) y hoy además se confirmó funcionalmente vía
API contra `dev.atlanticerp.ai` con usuarios reales (Idmar ve su 1 pedido propio, Neil ve 0, Bodega ve
los 16). Lo que faltaba era el gate de fidelidad visual/funcional contra el mockup adjunto — nunca
corrido para este ticket pese a tener mockup — este documento cierra ese gap.

**Mockup:** `3L__Bodega_StatusPedidos.html`, attachment id 10883 (único mockup adjunto, sin
ambigüedad de versión). Spec complementaria: `3__Requerimientos_Bodega.xlsx`, attachment id 10884,
fila REQ-342 (hoja "Requerimientos Ventas&Diseño") + fila "Ver y descargar Status de pedidos" (hoja
"Matriz de Permisos por Rol").

**Entorno:** `https://dev.atlanticerp.ai`, tres usuarios reales:
- `almacen@atlantic.com.pa` (Esteban Cárdenas, Líder de Bodega) — ve todo.
- `idmar@atlantic.com.pa` (Idmar Hernandez, Vendedor/Diseñador) — 1 pedido propio real
  (`PED-2026-0013`).
- `neil.quiel@atlantic.com.pa` (Neil Quiel, Vendedor/Diseñador) — 0 pedidos propios hoy.

**Herramienta:** Playwright CLI, `atlanticerp-frontend/e2e/visual-review-scrum412-status-pedidos.spec.ts`
(script descartable, no se promueve — el smoke test funcional permanente de este batch ya existe
en `e2e/preqa-scrum407-413-status-pedidos.spec.ts`). 4/4 tests verdes contra `dev.atlanticerp.ai`.

## Checklist funcional (mockup `3L__Bodega_StatusPedidos.html`, líneas 683-753 + datos JS 779-880)

| # | Mockup / Spec | Desarrollo real (dev.atlanticerp.ai) | Estado |
|---|---|---|---|
| 1 | Título "Status de pedidos" + subtítulo "Solicitado vs. entregado vs. pendiente, por pedido" | Título "Status de Pedidos" presente; subtítulo del mockup no se replica literal, en su lugar muestra el conteo real ("16 pedidos encontrados" / "1 pedido encontrado") | ✅ ACEPTABLE — info equivalente, más útil (dato real vs. texto estático) |
| 2 | Botón "← Volver a Pedidos" | Presente, funcional, confirmado en las 3 sesiones (Bodega/Idmar/Neil) | ✅ presente y correcto |
| 3 | Buscador libre por N° pedido/cotización/cliente/vendedor | Presente, placeholder equivalente ("Buscar por # de pedido, cotización, cliente o vendedor..") | ✅ presente y correcto |
| 4 | Tabla, columnas en orden: N° pedido, N° cotización, Subcliente, Fecha de pedido, Vendedor (RN1 de REQ-338) | Orden confirmado real en el DOM, idéntico | ✅ presente y correcto |
| 5 | Fila clickeable → abre modal de detalle | Confirmado clic en fila abre modal en las 3 sesiones | ✅ presente y correcto |
| 6 | Modal detalle: Proyecto, Diseñador, Cliente, Contacto, Teléfono, Fecha de Estatus | Los 6 campos presentes y con datos reales de la cotización original (no mock), Fecha de Estatus = fecha real del día | ✅ presente y correcto |
| 7 | Tabla de productos en el modal: Imagen, Ref. pública, Descripción, Solicitada, Entregado, Pendiente | Las 6 columnas presentes en el mismo orden; "Imagen" usa un icono placeholder outline en vez del emoji 💡 del mockup | ✅ presente — la sustitución del emoji por icono es requerida por la regla SCRUM-56, no una omisión |
| 8 | Botones del modal: "Cerrar", "Ver/Descargar" | Ambos presentes y funcionales — "Ver/Descargar" confirmado con respuesta real 200 de `.../orders/status/{id}/document` para Bodega e Idmar | ✅ presente y correcto |
| 9 | Modal de documento imprimible "Estatus de Pedido" | No re-verificado en este review — ya inspeccionado a fondo en Pre-QA 2026-07-23 (SCRUM-411, mismo documento, sin cambios de SCRUM-412 que lo afecten) — un hallazgo MEDIO no bloqueante ya conocido (falta columna imagen y título en mayúsculas en el PDF) sigue vigente y sin relación con el alcance de este ticket | ➖ N/A para este gate, ya cubierto |
| 10 | **RN1 — pantalla única compartida, entrada también desde Ventas & Diseño** | Confirmado: botón "Status de pedidos" presente en el header de `Pipeline` (Ventas & Diseño), navega al mismo `/bodega/pedidos/status` que el botón de `Pedidos` (Bodega) — mismo componente, no duplicado | ✅ presente y correcto |
| 11 | **RN2 — Vendedor/Diseñador ve solo lo propio, Bodega ve todo** | Idmar: exactamente 1 fila (`PED-2026-0013`, su pedido). Neil: 0 filas, estado vacío correcto. Bodega: 16 filas, sin filtrar | ✅ presente y correcto |
| 12 | **RN3 — solo consulta, sin edición, para ningún perfil** | Confirmado en Bodega e Idmar: sin texto "guardar"/"editar"/"eliminar" visible, sin inputs editables fuera del buscador | ✅ presente y correcto |
| 13 | Estado vacío (implícito — el mockup no lo define explícito, solo deja de renderizar filas) | Fila única con mensaje "No hay pedidos con los filtros actuales." — no roto, no en blanco | ✅ ACEPTABLE — mejora sobre el mockup (que no maneja este caso) |
| 14 | REQ-337 — no aparece en el menú de navegación principal (sidebar/subtabs) | Confirmado por inspección visual: ni en el sidebar de Bodega ni en el de Ventas & Diseño aparece "Status de pedidos" como ítem de navegación — solo accesible vía los botones de encabezado de Pedidos/Pipeline | ✅ presente y correcto |

## Evidencia (capturas, `atlanticerp-frontend/e2e/.tmp/`)
- `vr412-bodega-tabla.png` — Bodega, 16 pedidos, columnas en orden correcto
- `vr412-bodega-modal-detalle.png` — modal de detalle completo, Bodega
- `vr412-vd-pipeline-entry.png` — botón "Status de pedidos" en el header de Pipeline (Ventas & Diseño)
- `vr412-idmar-tabla.png` — Idmar, exactamente 1 fila (su propio pedido)
- `vr412-idmar-modal-detalle.png` — modal de detalle con datos reales del pedido de Idmar
- `vr412-neil-empty-state.png` — Neil, estado vacío correcto ("No hay pedidos con los filtros actuales.")

## CRÍTICO
Ninguno.

## ACEPTABLE (nota, no bloquea)
- Subtítulo estático del mockup reemplazado por el conteo real de pedidos — mejora, no regresión.
- Icono de producto reemplaza el emoji 💡 del mockup — requerido por la regla de cliente SCRUM-56
  (no-emoji), no es una omisión de funcionalidad.
- Estado vacío explícito con mensaje — el mockup no lo define, el desarrollo lo maneja mejor.

## Lo que sí cumple
Título y navegación de ida/vuelta, buscador, orden de columnas (RN1 de REQ-338), fila clickeable →
modal, los 6 campos generales del modal con datos reales (no mock), tabla de productos con las 6
columnas en orden, botones Cerrar/Ver-Descargar funcionales, punto de entrada compartido desde
Ventas & Diseño (RN1 de REQ-342) navegando al mismo lugar, filtrado real por ownership (RN2:
Idmar ve 1, Neil ve 0, Bodega ve 16), ausencia total de controles de edición para Ventas & Diseño
y Bodega (RN3), pantalla sin entrada en el menú principal (REQ-337).

## Resultado
**PASADA LIMPIA — sin hallazgos CRÍTICOS.** El ticket puede avanzar (ya viene con Pre-QA limpio
desde 2026-07-23). Marcador creado: `~/.claude-visual-review-markers/SCRUM-412`.
