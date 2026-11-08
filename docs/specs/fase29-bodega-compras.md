# Fase 29 — Bodega y Compras

Estado: **APROBADA** por Luis (2026-11-08); decisiones en §3. Origen: `docs/flujos/pendientes-priorizados.md`, Fase 29. Una sola fase, un PR por repositorio, con sub-entregas internas en el orden de §2.

## 1. Verificación previa (2026-11-08)
Contra el código de `master`: siguen abiertos el Kardex sin filtro por fecha ni producto exacto, el mapeo de asistentes que acepta cualquier usuario (solo `exists:users,id`) y la ruta del día sin atrasados. La Fase 20b ya resolvió los candidatos 1 a 3 de familias. El resto se confirma al implementar cada grupo; lo que ya esté resuelto se tacha en su catálogo, no se reimplementa.

## 2. Alcance por grupo
| # | Grupo | Qué cambia |
|---|---|---|
| 1 | Aprobadores de Bodega | Ajustes, reubicaciones y conteos usan aprobadores propios por proceso, reutilizando `process_approvers` de la Fase 28 (sin filas = aprobador primario). El 403 dice quién puede aprobar. «Aplicado» pasa a ser un estado visible del conteo. Rechazar pide motivo también en Compras (cambio de monto y sustituciones). |
| 2 | Ubicaciones, rutas y Kardex | Permiso angosto para escribir ubicaciones; la ruta del día incluye lo atrasado, marcado; orden por repartidor y hora; ubicaciones desactivadas ocultas por defecto (filtro para verlas); Kardex con filtro por fecha, producto exacto y `tipo` por código. Decisión documentada: el Líder de Compras ve Bodega. |
| 3 | Asistentes de llegadas | El mapeo solo acepta vendedor con rol de ventas y asistente con rol de Bodega; se puede quitar; reasignar los pedidos pendientes es una acción explícita; el detalle del pedido muestra al asistente. |
| 4 | Estados de Compras | La orden aprobada deja de mostrar `por_aprobar`; las acciones de sustitución se renombran para que «rechazada» no parezca la que genera la orden (alias en la API). |
| 5 | Reposición y proveedores | Nombre de agencia único; contacto principal editable; edición parcial de proveedor sin pisarse (control de versión); desactivar proveedores y agencias; cantidad mínima y múltiplo de presentación por proveedor. |
| 6 | Familias | Editar, renombrar y desactivar; moneda y modalidad elegibles; existencias por almacén. |
| 7 | Fase 24, abiertos | Reemplazar un documento de la orden en una sola operación; historial de envío con más campos que contenedor y transportista. |

## 3. Decisiones de Luis (2026-11-08)
1. **Partida en dos**: 29a Bodega (grupos 1 a 3) y 29b Compras (grupos 4 a 7); un PR por repositorio en cada una, Bodega primero.
2. **Gerencia también aprueba** ajustes, reubicaciones y conteos, además del aprobador designado por proceso (con suplentes). El 403 para el resto nombra quién puede aprobar.
3. **Ruta del día**: los pedidos atrasados aparecen arriba, marcados como atrasados.
4. **Cantidad mínima y múltiplo** de compra por proveedor y producto: opcionales, vacíos por defecto. Proveedores, agencias y familias solo se **desactivan**; nunca se borran si tienen historial.

## 4. Criterios de aceptación
Por grupo: tests de permisos por rol y de regresión, catálogo en `docs/flujos/` actualizado (candidatos tachados con la fase), pasos nuevos en `npm run e2e:flujos`, suite completa verde y revisión de seguridad antes de mezclar. Las migraciones son aditivas y no cambian el comportamiento al desplegar.
