# Fase 32 — Cierre de abiertos de las Fases 29 a 31

Estado: **APROBADA** por Luis (2026-11-10); implementada, resultado en §5. Origen: los «Abierto» de `fase29-bodega-compras.md`, `fase30-servicios.md` y `fase31-ventas-administracion.md`. Luis eligió este alcance entre cuatro opciones. Una sola fase, un PR por repositorio, sub-entregas en el orden de §2.

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
1. **Contacto de soporte**: se muestra en la pantalla de ingreso y en el menú del usuario.
2. **Informe móvil con borrador**: se incluye; solo el informe completado desbloquea el cierre (igual que en escritorio).
3. **Reasignar departamento**: una entrada de auditoría por usuario (departamento anterior, nuevo, quién, cuándo).
4. **Historial** de ficha técnica y de archivos reemplazados: sin límite y solo consulta; la depuración se revisa en otra fase si crece.

## 4. Criterios de aceptación
Tests de permisos por rol y de regresión, catálogos tachados, e2e nuevos, suite completa verde y revisión de seguridad antes de mezclar. Sin migraciones que cambien comportamiento al desplegar.

## 5. Resultado de la Fase 32 (2026-11-10)
| Grupo | Hecho |
|---|---|
| 1 | Mapeo vendedor→asistente con pantalla (panel en Configuración de Bodega; `team-members?role=vendedor` alimenta el selector); vínculo ficha de técnico↔usuario en el modal de edición (`GET internal-technicians/linkable-users`, activos con rol de técnico y sin otra ficha); «Reemplazar contacto» del subcliente en la ficha del cliente (usa `contacts/{id}/replace` de la 31a); versiones anteriores de la ficha técnica en el detalle del producto (solo con permiso de edición); Kardex con filtro de producto exacto (se elige desde una fila). |
| 2 | Modal propio para editar el mínimo de un insumo y confirmación propia (`ConfirmModal`) para dar de baja insumos y herramientas (antes `prompt`/`confirm`; la herramienta no tenía botón de baja); la hoja de reclamo guarda y muestra quién la reabrió (`reopened_by_user_id`, migración `2026_11_27_100000`); el informe móvil guarda borrador (mismo endpoint y reglas que escritorio, sin firma). |
| 3 | `GET /auth/support-contact` (público, solo ese dato) y línea de soporte en el ingreso y en el menú del usuario; `NotificationRuleCache` (memo por petición/job, se invalida al guardar o borrar una regla); reasignar un departamento deja una entrada de auditoría por usuario (`auth.user.department_reassigned`). |
| 4 | `e2e/flujos/fase32-cierre-abiertos.spec.ts` (4 pasos: soporte, vendedores, vínculo de técnico, Kardex por producto). |

Una migración aditiva y reversible. Nada cambia al desplegar salvo lo nuevo y opcional.
**Abierto**: el panel del mapeo vive en Configuración de Bodega (superadmin): el Líder de Bodega puede editarlo por el servidor pero no tiene cómo llegar a la pantalla; el historial de la ficha y de archivos no se depura (decisión 4); el reemplazo de contacto, el historial y el borrador móvil no tienen e2e de interfaz (se cubren con tests de componente); la caché de reglas es por petición, no entre peticiones.
