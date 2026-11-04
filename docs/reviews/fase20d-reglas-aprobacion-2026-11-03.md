# Senior Review — Fase 20d: reglas de aprobación sin huecos (2026-11-03)

Revisión hecha en el mismo hilo (no un agente independiente). **Veredicto: aprobado, 0 bloqueantes.**

## Qué cambia
- `PurchaseApprovalRuleController`: `store`/`update` devuelven `warnings` si la regla activa se solapa con otra activa de igual alcance (mismo envío y mismo pagador); no bloquea. Nuevo `GET /admin/purchase-approval-rules/coverage` (bajo `superadmin.all`): por cada combinación (aéreo/empresa, aéreo/proveedor, marítimo, terrestre) lista los rangos de monto sin regla, calculados en centavos.
- `PurchaseApprovalRules::requiresApproval`: sin regla que coincida decide el ajuste global `purchase_approval_no_rule` (registro en `GlobalSettingsRegistry`, grupo `compras`, default 0 = comportamiento anterior). La vista previa lo refleja.
- Frontend: aviso de huecos y de solapes en el panel de reglas; ajuste editable en Ajustes globales.

## Verificación
- 4 tests nuevos de backend (solape, cobertura con el ejemplo 0–999 / 5 000–∞, cobertura sin huecos, permisos, ajuste global con orden real); suites Compras + Servicios + Auth: 1770 tests en verde; PHPStan 0.
- 3 tests nuevos del panel; Ajustes globales 43/43; e2e de flujos 118 pasos (paso 3b nuevo).

## Observaciones
1. El solape se detecta solo entre reglas de **igual alcance**; una regla general bajo una específica (patrón normal de «excepción + resto») no avisa a propósito.
2. `coverage` no considera órdenes sin tipo de envío (`shipping_type` nulo); una regla con envío concreto no las cubre y caen en el ajuste global.
3. El ajuste global no exige contraseña del operador (no es de seguridad de cuentas); cambia solo cuándo se pide aprobación, y queda en auditoría como el resto de ajustes.
