# Revisión de seguridad — Fase 31b (Administración), 2026-11-10

Alcance: backend `fase31b/spec` y frontend `fase31b/administracion` (spec `docs/specs/fase31-ventas-administracion.md`). Revisión propia contra el diff; **0 bloqueantes**.

## Permisos y fuga
- **Sin permisos ni niveles nuevos**: no se escribió en ningún `security_levels.permissions`, así que no hay riesgo de fuga por nivel compartido.
- **Perfil de empresa**: el middleware `primary_approver_only:perfil_empresa` deja pasar a quien tenga `platform.management` (Gerencia), además del aprobador primario y sus suplentes; el resto recibe 403 (cubierto por test con un vendedor). La **configuración fiscal** conserva el gate anterior (Gerencia que no es aprobador sigue en 403, cubierto por test). El permiso `platform.management` ya lo escribe `ApplyGerenciaScope`/nivel de Gerencia; no se amplía.
- **Departamentos**: las rutas siguen bajo `platform.management` + `security.users`; `reassign` valida que el destino exista y esté activo.

## Escrituras nuevas
| Ruta | Gate | Notas |
|---|---|---|
| `POST departments/{id}/reassign` | Gerencia + `security.users` | solo cambia `users.department_id`; id numérico por `Route::pattern` |
| `PUT admin/global-settings/support_contact` | `superadmin.all` | texto recortado, ≤ 200, auditado (`SETTINGS_UPDATED`); no es secreto ni gobierna seguridad |
| reglas de notificación (`POST/PUT`) | `superadmin.all` | modelo y campo en lista blanca; evento validado por modelo |

## Integridad
- Las reglas de notificación solo observan los 9 modelos de la lista blanca (antes 3). Un campo condicional fuera de la lista se rechaza; el destinatario «asignados» se rechaza fuera de CRM (no habría proyecto de dónde sacarlos).
- El contenido del aviso no incluye datos del modelo más allá de su clase y número (`Ticket #12`), sin montos ni datos de clientes.
- Solapes de reglas de aprobación: el 422 evita que una regla de menor orden deje sin efecto a otra; no cambia a las ya existentes.

## Observaciones (no bloqueantes)
1. Cada actualización de `PurchaseOrder`, `Order` y `Ticket` consulta las reglas activas (una consulta por evento); sin reglas no hace nada más. Si pesa, cachear por petición como `CatalogCache`.
2. El envío usa la fuente `crm_rule` para las reglas nuevas (la columna admite 30 caracteres, sin restricción por valor).
3. `support_contact` no tiene lector todavía.
4. Reasignar departamentos mueve usuarios en una sola sentencia sin auditoría por usuario; queda la del actor en el registro HTTP.
