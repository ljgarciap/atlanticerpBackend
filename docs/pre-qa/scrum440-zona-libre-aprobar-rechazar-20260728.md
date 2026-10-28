# Pre-QA — SCRUM-440 (REQ-370): ciclo de vida completo de una orden Zona Libre

**Fecha:** 2026-07-28
**Entorno:** dev.atlanticerp.ai
**Commit verificado:** d837c0d (frontend Aprobar/Rechazar en `BodegaOrdenesZonaLibrePage.tsx`)
**Contexto:** primera pasada de Pre-QA propia de este ticket — la sesión anterior (2026-07-28,
batch de 9 tickets Bodega) encontró el CRÍTICO de que no existía ningún botón Aprobar/Rechazar en
la SPA (ver `preqa-scrum440-zona-libre-recheck-20260728.spec.ts`), Luis pidió resolverlo en la
misma sesión, y quedó pendiente de este Pre-QA propio antes de pasar a QA.

## Paso 0 — permisos angostos
`compras.zona-libre.approve`, `bodega.read`, `compras.settings.configure` ya están en
`SpecialPermissionSeeder.php` para `gerencia2@atlantic.com.pa` (Lidercompras) — no es grant manual.
No hay hallazgo.

## Criterios de aceptación

| Escenario | Camino | Resultado |
|---|---|---|
| Escenario 1 — Rechazo con motivo visible | Feliz | PASA — Bodega ve el motivo real de la orden #3 rechazada ("Presupuesto de Zona Libre excedido este mes...") |
| Escenario 2 — Aprobación sigue flujo normal | Feliz | PASA — aprobar la orden #5 generó `PurchaseOrder` #42 real, modalidad Zona Libre, visible en Compras > Ver Órdenes, mismo estado inicial que cualquier otra orden |
| RN3 — Bodega no edita | Ruptura (permiso) | PASA — Bodega no ve botones Aprobar/Rechazar; API responde 401/403 a un POST directo con el JWT de Bodega |

## Escenarios de ruptura intentados (Paso 3)

| Escenario de ruptura | Resultado |
|---|---|
| Rechazar sin motivo (input vacío) | Bloqueado client-side: "El motivo es obligatorio.", modal no cierra, no llama al backend |
| Recargar la página a mitad del modal de Rechazar | Modal no persiste, orden sigue "Por aprobar" intacta — sin limbo |
| Doble clic rápido en Aprobar | No duplicó la orden de compra — botón se deshabilita en el primer clic (loading state) + `lockForUpdate()` y chequeo de estado dentro de la transacción en el backend |
| Bypass de permiso vía API directa (Bodega) | 401/403 real, no solo botón escondido en la UI |
| Rol sin permiso (Bodega) en cada estado | Solo ve "Recordar" (pendiente) / "Ver motivo" (rechazada) / texto (aprobada) — igual que antes del fix |

## Visual Review (mockup adjunto)
`3D__Bodega_OrdenesZL.html` — la vista de Bodega coincide elemento a elemento (chips, badges,
acciones). El mockup nunca modeló la acción de Lidercompras (solo cubre la vista de lectura de Bodega),
así que Aprobar/Rechazar es funcionalidad nueva mandatada por el texto del ticket, no una
desviación. El 🔔 del mockup se tradujo correctamente a `IcoBell` (SVG), no emoji — cumple SCRUM-56.

## Lo que sí funciona (regresión)
Filtrado por chips (Todas/Por aprobar/Aprobadas/Rechazadas), montos y fechas correctos, badges de
estado con los colores esperados, "Recordar" para Bodega sin cambios.

## Veredicto
Sin hallazgos bloqueantes. Pasada limpia en la primera vuelta. Transicionado a QA
(comentario en Jira, `jira_add_comment` id 11608). Test permanente agregado:
`atlanticerp-frontend/e2e/preqa-scrum440-zona-libre-aprobar-rechazar-20260728.spec.ts`.
