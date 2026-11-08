# Revisión de seguridad — Fase 28 (Admin & Contab)

Revisión de solo lectura por un subagente de ciberseguridad con contexto aislado, sobre el diff `master...fase28/contabilidad` (2026-11-07). **Veredicto: aprobado para PR, 0 bloqueantes.**

No se creó ningún permiso nuevo (las rutas reutilizan `admin_contab.read`, `admin_contab.records.manage` y `superadmin.all`), así que no hay riesgo de fuga por nivel compartido. `/api/admin/process-approvers` vive bajo `permission:superadmin.all`: nadie puede agregarse a sí mismo. Las 4 rutas de decisión de los procesos con aprobadores propios pasan por `primary_approver_only:<proceso>`; no se halló ninguna otra ruta que decida esos procesos. En tesorería, `denegarSiNoPuede` corre antes de cualquier lectura o escritura en ambos métodos.

## Hallazgos importantes (corregidos en el commit `6504cb5`)
1. **Un PUT parcial podía invertir un tramo** (mínimo > máximo): `CommissionTierScaleGuard` ahora rechaza máximo ≤ mínimo (422). Test de regresión en `AdminContReportsControllerTest` y paso e2e.
2. **El cambio de aprobadores por proceso no se auditaba** y `created_by` quedaba nulo: ahora registra `admin_contab.process_approvers.updated` con antes/después y el actor del JWT.
3. **`ProcessApprovers::idsOf` fallaba abierto** ante cualquier error: ahora solo tolera la ausencia de la tabla (SQLSTATE 42P01) y propaga el resto.

## Sugerencias
- Aplicadas: reasignar la cuenta de un cobro desde el movimiento se rechaza (422; dejaría `payments.bank_account_id` desincronizado) y la reasignación corre en transacción con `lockForUpdate`.
- Anotadas, sin aplicar: la asignación inicial no mira el arqueo cerrado (a propósito); `anulacion_cobro` incluye devoluciones y reversos de compensación; validar que los `user_ids` de un proceso estén activos; pedir motivo al cambiar los días de crédito de un cliente (hoy queda la auditoría con actor); `FiscalDocumentBuilder` usa los días de crédito globales para la fecha que viaja al PAC.
