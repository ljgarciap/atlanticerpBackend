# Revisión de seguridad — Fase 30 (Servicios), 2026-11-09

Alcance: ramas `fase30/servicios` de ambos repositorios (spec `docs/specs/fase30-servicios.md`). Revisión propia contra el diff; **0 bloqueantes**.

## Permisos nuevos y chequeo de fuga
- `servicios.reports.team` (carga por técnico y comisión del equipo) y `servicios.assets.manage` (insumos y herramientas) son permisos de **rol completo**, escritos en el `security_level` por defecto de `lider_servicios` y `management`.
- Regla del proyecto: el JSONB de `security_levels.permissions` no se filtra por visibilidad de módulo, así que cada migración **aborta si otro rol comparte ese nivel** (`RuntimeException`) en vez de escribir. `lider_servicios` tiene su propio nivel desde la Fase 1 y `management` es exclusivo.
- `servicios.technicians.commission.view_own` se concede además al Líder (migración `2026_11_25_100001`). El controlador de comisión acepta ahora Líder, Gerencia y superadmin, o al propio técnico; cualquier otro recibe 403. Un test cubre al vendedor (403) y al Líder y Gerencia (200).
- El vendedor deja de leer `distribucion-tecnico` (ruta con `servicios.reports.team`). Es un cambio de comportamiento **decidido** (decisión 4 de la spec).

## Escrituras nuevas
| Ruta | Gate | Notas |
|---|---|---|
| `PATCH claim-sheet/reabrir` | `servicios.tickets.manage` | motivo ≥ 10, auditado (`servicios.claim_sheet.reopened`), solo hojas completadas |
| `DELETE/POST inspection-report/photos/{id}[/replace]` | `servicios.field_work.edit` + `assertCanEdit` (dueño del ticket) | la foto se busca **dentro del informe del ticket de la URL** (no se puede tocar la de otro ticket); 409 con el informe firmado |
| `PATCH/DELETE insumos/{id}`, `DELETE tools/{id}`, recepción | `servicios.assets.manage` | baja = desactivar (nada se borra); herramienta asignada → 422 |
| `PATCH internal-technicians/{id}` | `servicios.tickets.manage` | solo los campos reenviados; `user_id` valida existencia y que no esté ligado a otra ficha |

Ids no numéricos devuelven 404 por el `Route::pattern` global.

## Integridad
- Conciliación del Kardex: calcula por diferencia contra los movimientos del propio informe (`referencia_entidad_tipo/id`) dentro de la transacción del guardado; guardar dos veces lo mismo no mueve nada (cubierto por test).
- El borrador **no** consume inventario ni cambia `inspection_report_status`; un informe completado no vuelve a borrador (422).
- Cierre de instalaciones y reclamos: las compuertas se evalúan en `changeStage` antes de cualquier cambio de estado.

## Observaciones (no bloqueantes)
1. El motivo de reapertura de la hoja queda en la columna y en la auditoría; no se muestra aún quién reabrió en la interfaz.
2. Editar insumos y dar de baja usa `prompt`/`confirm` del navegador en el frontend: funcional, sin modal propio.
3. `received_reference` es texto libre sin validar contra Compras (decisión 5).
4. El backfill de `is_active` deja todo activo: ningún insumo ni herramienta existente cambia de visibilidad al desplegar.
