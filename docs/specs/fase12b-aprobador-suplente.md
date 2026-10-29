# Fase 12b — aprobador suplente (resultado)

Estado: **implementada** (backend #55, frontend #46; mensajes de denegación en el PR siguiente). Spec madre: `fase12-seguridad-y-separacion-de-funciones.md` §2.

## Qué se hizo
- `App\Shared\Support\PrimaryApprovers::isApprover(userId, titularId)`: titular **o suplente**. Sustituye la comparación contra un id suelto en 12 sitios (`AprobacionGate`, `EnsureIsPrimaryApprover`, bandera `flags.primary_approver` del token, `ApprovalsPendingService`, comisiones, caja chica, arqueo, órdenes de compra y pagos).
- El **titular** sigue en `compras_settings.primary_approver_user_id` y `admin_contab_fiscal_settings.primary_approver_user_id` (sin migración de datos). Los **suplentes** viven en `{tenant}_auth.primary_approver_substitutes` (una fila por persona, compartida por ambos módulos; máximo 10; el titular no puede ser suplente).
- Administración: `GET|PUT /api/admin/primary-approver-substitutes` (solo `superadmin.all`) y la pestaña «Aprobadores» de Configuración global.
- Mensajes de denegación (`AprobacionGate::motivoDenegacion`): una cuenta que no es aprobadora lee «Solo el aprobador configurado o un suplente puede decidir esto… pídele a un superadmin que te designe en Configuración global → Aprobadores»; Zona Libre, que se decide por permiso, dice qué permiso falta.

## Análisis: ¿cada decisión registra quién la tomó? (2026-10-30)
Sí, en todos los casos: o una columna con la persona o el rastro de auditoría (`LogsAuditTrail`, que guarda actor, antes y después). No hizo falta cambiar el esquema.

| Decisión | Dónde queda quién decidió |
|---|---|
| Nota de crédito (aprobar y rechazar) | `credit_notes.aprobado_por` (+ `motivo_rechazo`) |
| Anulación / reverso de pago | `payment_reversal_requests.decided_by`, `payments.anulado_by` |
| Devolución de dinero / movimiento | `payment_movements.approved_by` |
| Orden de compra y cambio de monto | `purchase_orders.approved_by` |
| Ajuste de inventario, reubicación, conteo general | `*.resuelto_por` por línea o solicitud |
| Sustitución de producto | `replacement_requests.approved_by` / `rejected_by` (+ `rejection_reason`) |
| Zona Libre | `bodega_zona_libre_requests.approved_by` / `rejected_by` |
| Devolución de cliente | `customer_returns.received_by` / `rejected_by` |
| Arqueo diario | `daily_cash_counts.cerrado_por` / `aprobado_por` |
| Caja chica (informe y gastos) | `petty_cash_reports.aprobado_por`, `petty_cash_expense_history.actor_id` |
| Tramos y ajustes de comisión | auditoría (`CommissionTier`, `CarteraCommissionTier`, `SalesCommissionTier`, `ArchitectCommission`) |

## Abierto
- Un aprobador **suplente** queda registrado con su propio id (no como «titular»); los informes siguen mostrando el id de quien decidió, que es lo correcto, pero no indican si era titular o suplente en ese momento.
- El titular de Compras y el de Admin & Contab se siguen fijando por separado en cada módulo (pueden ser personas distintas); la lista de suplentes es una sola.
