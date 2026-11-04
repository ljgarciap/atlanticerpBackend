# Fase 21 — Seguridad en Ventas & Diseño (B1 y B2)

Estado: **B2 implementada; B1 parcial, a la espera de una decisión (ver §3)**. Nace del orden recomendado tras cerrar la Fase 20 (candidatos 6 y 4 de `docs/flujos/ventas-diseno.md`).

## 1. Decisiones de Luis (2026-11-04)
- **B1**: Contabilidad solo crea y vincula (edita las tarjetas que ella creó); las de otros vendedores quedan en lectura.
- **B2**: «Perdido» exige motivo y reabrir lo decide el aprobador primario o un suplente, con motivo, auditado.

## 2. B2 — implementado
- Migración `2026_11_14_100000` (`pipeline_cards`): `lost_reason/lost_at/lost_by` y `reopened_reason/reopened_at/reopened_by`, nullable (las ya perdidas quedan sin motivo; nunca se inventa uno).
- `PATCH pipeline/{id}/stage`: pasar a `lost` exige `motivo` (10–1000 caracteres); pasar de `lost` a `lead` exige `motivo` **y** que quien lo pide sea el titular del aprobador primario, un suplente (`PrimaryApprovers`) o superadmin (403 si no, con el texto que ya usa la 12b). Sin titular configurado, solo el superadmin. El motivo de la pérdida se conserva al reactivar.
- El detalle de la tarjeta expone `lost_reason`, `lost_at`, `reopened_reason`, `reopened_at` y `can_reactivate`.
- Frontend: formulario de motivo en el detalle (perder y reactivar), botón «Reactivar» solo con `can_reactivate`, el motivo visible en una tarjeta perdida, y el tablero no deja arrastrar hacia o desde «Perdido».

## 3. B1 — hallazgo y estado
- Implementado: `PipelineCard::isEditableBy` — con **solo** `ventas_diseno.pipeline.edit` (sin `ventas_diseno.edit` ni superadmin) se edita únicamente lo que la persona creó (`created_by`).
- **No alcanza para la Contabilidad del demo**: tiene `ventas_diseno.view/read/view_team/write/edit/approve/pass_to_order` porque su usuario trae una visibilidad de módulo propia (`UserModuleVisibility`) que le da Ventas & Diseño completo, no por `pipeline.edit`. Por eso sigue editando y moviendo tarjetas ajenas.
- Opciones para cumplir la decisión: (a) quitar esa visibilidad de módulo y dejarle solo los permisos angostos (lectura de todo, crear/vincular), (b) una regla por rol que limite la edición ajena aunque tenga el módulo, (c) dejarlo y solo auditarlo. Requiere confirmar qué más usa Contabilidad de Ventas (cotizaciones, aprobar, pasar a pedido).

## 4. Verificación
Backend: VentasDiseno + CRM + Bodega 1784 tests en verde, PHPStan 0. Frontend: 248 archivos / 2938 tests, tsc limpio. E2E de flujos: 120 pasos (el paso 5 de Ventas cubre motivo, 403 al dueño y a Gerencia, y la reactivación por el aprobador).
