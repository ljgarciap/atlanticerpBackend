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

## 5. Resultado de la 29a (Bodega), 2026-11-08
| Grupo | Hecho |
|---|---|
| 1 | Gerencia (rol) decide ajustes, reubicaciones y conteos además del aprobador, suplentes y reglas (`AprobacionGate`); conteo con `estado_visible: aplicada` y filtro `estado=aplicada`; rechazar un cambio de monto pide motivo (≥ 10, auditado). **No se usó `process_approvers`**: Bodega ya decidía por `AprobacionGate` (aprobador de Compras + reglas de aprobación por tipo), así que los «aprobadores por proceso» ya existían; solo faltaba Gerencia. |
| 2 | Permiso `bodega.locations.manage` (Líder y Asistente, este con nivel propio); ruta del día con atrasados marcados; Kardex con `producto_id`, `desde`, `hasta`; ubicaciones desactivadas ocultas por defecto (`incluir_inactivas=1`). Sin orden por hora: el pedido no guarda hora. |
| 3 | Mapeo validado por rol, `DELETE` y `reassign-pending`. El detalle del pedido ya mostraba al asistente (candidato desactualizado). |

**Abierto:** el mapeo no tiene pantalla (solo API); el filtro de producto exacto del Kardex existe en la API pero la pantalla solo ofrece fechas (el buscador ya filtra por texto). Verificación: ver el informe `docs/reviews/fase29a-bodega-2026-11-08.md`.

## 6. Resultado de la 29b (Compras), 2026-11-08
| Grupo | Hecho |
|---|---|
| 4 | `status_visible` en órdenes (`aprobada` mientras el estado real sigue en `por_aprobar`; `status` no cambia). Sustituciones: rutas `accept-substitute` / `keep-original` (alias de approve/reject), campo `decision` y textos «Aceptar sustituto» / «Mantener original». |
| 5 | Agencias: nombre único, contacto principal editable, desactivar. Proveedores: `PATCH` parcial, control de versión (409), `incluir_inactivas`. Mínimo y múltiplo por proveedor y producto (tres columnas aditivas y opcionales), `sugerida` en reposición y respeto en «Generar compra» de familia. |
| 6 | `PATCH families/{id}`: renombrar (único), moneda, descripción, desactivar; `stock_por_almacen` en el detalle. **La modalidad no se ofrece**: hoy solo existe la directa (Fase 14f). |
| 7 | `POST documents/{id}/replace` atómico con motivo; historial de envío con llegada, tipo de envío, quién paga, costo y modalidad. |

Tres migraciones aditivas y reversibles (`2026_11_24_10000x`). Nada cambia al desplegar: lo nuevo es opcional o por defecto igual al comportamiento anterior. Revisión de seguridad: `docs/reviews/fase29b-compras-2026-11-08.md`.
**Abierto**: el reemplazo de documento no tiene prueba e2e (la cubre un test de feature); la validación de que los aprobadores por proceso sean usuarios activos sigue de la Fase 28.
