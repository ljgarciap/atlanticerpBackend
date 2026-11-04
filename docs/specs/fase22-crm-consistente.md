# Fase 22 — CRM consistente (B3, B4, B5)

Estado: **aprobada, en implementación**. Nace del orden recomendado tras la Fase 21 (candidatos 3, 4 y 5 de `docs/flujos/crm.md`).

## 1. Decisiones de Luis (2026-11-04)
- **B3**: perder exige motivo; reactivar un perdido lo decide el aprobador primario, un suplente o el superadmin, con motivo. El resto de saltos de etapa sigue libre.
- **B4**: un contacto guarda quién lo creó y quién lo editó por última vez; editar y borrar solo el creador o quien ve al equipo (Gerencia/superadmin). Los contactos existentes quedan sin dueño y los edita cualquiera del CRM hasta que alguien con alcance de equipo los reasigne (editarlos no los reclama).
- **B5**: las etapas del CRM pasan a un dominio de catálogo propio (`crm_stage`), editable por empresa. No se mezcla con el pipeline de Ventas & Diseño.

## 2. B3 — motivo y reactivación
- Migración (`crm.projects`): `lost_reason`, `lost_at`, `lost_by`, `reopened_reason`, `reopened_at`, `reopened_by` (nullable; los ya perdidos quedan sin motivo, nunca se inventa).
- `PATCH projects/{id}/stage`: pasar a una etapa de categoría `perdida` exige `motivo` (10–1000); salir de una `perdida` exige `motivo` y que quien lo pide sea titular/suplente del aprobador primario o superadmin (403 con el texto de la 12b). Misma regla y mismo servicio que la Fase 21 de Ventas (`canReactivateLost` se comparte, no se duplica).
- El detalle expone `lost_reason`, `lost_at`, `reopened_reason`, `reopened_at`, `can_reactivate`.

## 3. B4 — contactos con dueño
- Migración (`crm.contacts`): `created_by`, `updated_by` (nullable, FK lógica a usuarios).
- `store` guarda `created_by`; `update` guarda `updated_by`. `update`/`destroy`: permitido si el contacto no tiene dueño, si lo creó quien pide o si ve al equipo (`canViewTeamV2('crm')`); si no, 403.
- La lista y el detalle devuelven `created_by` y `updated_by` (nombre).

## 4. B5 — etapas como catálogo `crm_stage`
- Dominio `crm_stage` (`config/catalogs.php`, usa `Project::etapa`), props `categoria` (`abierta|ganada|perdida`, las dos últimas `system_only`) y `color`; `locked_when_system` orden y categoría; reglas `CrmStageRules` (una etapa propia es abierta y va entre Lead y Cerrado).
- Sembrado con las 6 de hoy y sus claves (`lead`=100, `diseno`=200, `cotizacion`=300, `propuesta`=400, `cerrado`=500 ganada, `perdido`=900 perdida): ningún dato cambia.
- `crm.projects.etapa`: de ENUM a `varchar(50)` (la `down` solo es segura sin etapas propias en uso).
- Fachada `CrmStages` (como `PipelineStages`): validación (`Rule::in(CrmStages::keys())`), abiertas/ganadas/perdidas en tablero, reportes, directorio y contactos; adiós a las listas fijas.
- Frontend: hook `useCrmStages`, el tablero y los modales leen el catálogo (respaldo a las 6 de sistema); pestaña de catálogo ya existente en Ajustes.
- Fuera de alcance: sincronizar con el pipeline de Ventas, tipos de proyecto/actividad/documento fijos (candidato 6).

## 5. Pruebas
Backend: motivo y 403 de reactivación, contactos (dueño/equipo/sin dueño), catálogo (etapa propia en el tablero y en reportes, no se borra una en uso). Frontend: modal de motivo, tablero con etapa propia. E2E: `crm.spec.ts` ampliado.
