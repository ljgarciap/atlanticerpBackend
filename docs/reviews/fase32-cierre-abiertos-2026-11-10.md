# Revisión de seguridad — Fase 32 (cierre de abiertos), 2026-11-10

Alcance: ramas `fase32/spec` (backend) y `fase32/cierre-abiertos` (frontend); spec `docs/specs/fase32-cierre-abiertos.md`. Revisión propia contra el diff; **0 bloqueantes**.

## Superficie nueva
| Ruta | Gate | Notas |
|---|---|---|
| `GET /api/auth/support-contact` | público | Devuelve solo `support_contact` (texto de Gerencia, ≤ 200). Sin sesión no sale nada más de la empresa; un tenant no resuelto devuelve vacío. Un test fija la respuesta exacta. |
| `GET /api/servicios/internal-technicians/linkable-users` | `servicios.tickets.manage` | Solo nombre e id de usuarios activos con rol de técnico sin otra ficha (la de `ficha_id` conserva el suyo). El vendedor recibe 403 (test). Va antes de `/{id}` en el archivo de rutas. |
| `GET /api/bodega/team-members?role=vendedor` | `bodega.read` (ya existente) | Nuevo valor del mapa de roles; mismo shape `{id, name}`. |

## Permisos y fugas
- Ningún permiso nuevo ni nivel de seguridad tocado: no hay riesgo de fuga por nivel compartido.
- La edición del mapeo sigue exigiendo Líder de Bodega o superadmin en el servidor; el panel nuevo no abre nada que antes no estuviera. El historial de la ficha técnica sigue exigiendo `ventas_diseno.edit` (el frontend ni lo pide sin ese permiso).

## Integridad y auditoría
- `reopened_by_user_id` es aditivo y nullable; la reapertura queda además en la auditoría con `reabierta_por`.
- Reasignar un departamento se hace en una transacción: se mueven los usuarios y se registra una entrada por persona con el departamento anterior y el nuevo; el autor lo pone `AuditService` (incluso bajo «Ver como usuario»).
- `NotificationRuleCache` es `scoped` (una petición o un job de la cola) y se invalida al guardar o borrar una regla: ningún worker ve reglas viejas.

## Observaciones (no bloqueantes)
1. El panel del mapeo está en Configuración de Bodega (superadmin); el Líder de Bodega no tiene ruta propia.
2. El contacto de soporte es texto libre: se muestra como texto (React lo escapa), no como enlace.
3. El borrador del informe móvil reutiliza el endpoint JSON de escritorio: mismas reglas (el borrador no consume inventario ni desbloquea el cierre).
