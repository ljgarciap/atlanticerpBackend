# Fase 32 — Cierre de abiertos de las Fases 29 a 31

Estado: **BORRADOR** (2026-11-10). Origen: los «Abierto» de `fase29-bodega-compras.md`, `fase30-servicios.md` y `fase31-ventas-administracion.md`. Luis eligió este alcance entre cuatro opciones. Una sola fase, un PR por repositorio, sub-entregas en el orden de §2.

## 1. Verificación previa
Se confirma contra el código de `master` al implementar cada grupo; lo que ya esté resuelto se tacha, no se reimplementa.

## 2. Alcance por grupo
| # | Grupo | Qué cambia |
|---|---|---|
| 1 | Pantallas que solo existían por API | Mapeo de asistentes de llegadas (alta, quitar, reasignar pendientes); vincular ficha de técnico con usuario; reemplazar contacto de subcliente; historial de la ficha técnica; control de producto exacto en el Kardex. |
| 2 | Servicios | Modal propio para editar y dar de baja insumos y herramientas (sin `prompt`/`confirm`); mostrar quién reabrió una hoja de reclamo y el motivo; borrador en el informe móvil. |
| 3 | Administración | Mostrar `support_contact` en una pantalla; caché de reglas de notificación activas; auditoría por usuario al reasignar departamento. |
| 4 | Pruebas | Pasos nuevos en `npm run e2e:flujos` para lo anterior. |

## 3. Decisiones de Luis
(pendientes)

## 4. Criterios de aceptación
Tests de permisos por rol y de regresión, catálogos tachados, e2e nuevos, suite completa verde y revisión de seguridad antes de mezclar. Sin migraciones que cambien comportamiento al desplegar.
