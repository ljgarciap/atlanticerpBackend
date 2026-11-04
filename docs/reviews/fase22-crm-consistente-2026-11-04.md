# Senior Review — Fase 22 (CRM consistente, B3–B5)

Revisión hecha en el hilo de trabajo (no por un agente independiente). **0 bloqueantes.**

## Revisado
- **Autorización:** la reactivación reutiliza `PrimaryApprovers` + superadmin (misma regla que la Fase 21); el cambio de etapa no se puede esquivar por `PUT` (no acepta `etapa`) ni por el alta (se rechazan etapas perdidas).
- **Contactos:** `canManage` permite creador, equipo (`canViewTeamV2('crm')`) o contacto sin dueño; no hay vía de «reclamar» un contacto al editarlo.
- **Catálogo:** `crm_stage` con categorías `system_only` para ganada/perdida y orden bloqueado en las de sistema; una etapa en uso no se borra (`usage` sobre `Project::etapa`).
- **Migraciones:** aditivas; `etapa` pasa de ENUM a `varchar(50)` quitando el CHECK por consulta a `pg_constraint`; `down()` restaura el CHECK y solo es seguro sin etapas propias en uso.

## Sugerencias abiertas
- `KanbanBoard` no lo monta ninguna página (solo tests): conviene decidir si se retira o se vuelve a usar.
- Los tipos de proyecto, actividad, recordatorio y categoría de documento del CRM siguen fijos (candidato 6).
- El modal ignoraba antes la etapa en edición; ahora cambia por `PATCH` con motivo cuando corresponde.
