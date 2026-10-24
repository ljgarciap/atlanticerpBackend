# ADR — Batch E: Comisiones pagadas como movimiento de caja (SCRUM-879)

Arquitecto, 2026-09-09. Corrección reportada por Gerencia Test. Spec:
`docs/specs/scrum-875-880-correcciones-admincont-analista-spec.md`. Decisiones E-1…E-8 cerradas
con Luis.

## Problema

Al marcar "Pagada" una comisión **externa**, el pago no aparece en Arqueo de Caja → Vista Real ni
en Cuentas Bancarias → Movimientos. Las comisiones **internas** tienen el mismo problema y no
tienen siquiera un evento de pago.

## Reversión explícita de ADR-SCRUM597-601 punto 4

Ese ADR (Batch 18) decidió que **las comisiones internas nunca generan un movimiento de banco
real** porque "la liquidación real ocurre en planilla, fuera del sistema". **Luis revierte esa
decisión** (2026-09-09, decisión E-1): las comisiones internas sí deben reflejarse como salida de
caja, generadas por un job automático al cierre de mes. El motivo de negocio pesa más que el
argumento "está en planilla": el Flujo de Caja debe reflejar todas las salidas de dinero reales
para ser confiable (mismo criterio que motivó SCRUM-879).

## Decisión

### 1. Comisión externa — `ArchitectCommissionService::marcarComoPagada()`

Se elimina el guard `if ($commission->bank_account_id !== null)`. El `AdminContBankMovement` se
crea **siempre**:
- `tipo = TIPO_COMISION`, `source_type = SOURCE_ARCHITECT_COMMISSION`, `source_id = commission.id`
- `bank_account_id` = `$commission->bank_account_id` (puede ser null)
- `fecha = now()->toDateString()`, `monto` = `$montos['total'] ?? $montos['comision']` (sin cambio)
- Idempotente: `AdminContBankMovement::where('source_type', SOURCE_ARCHITECT_COMMISSION)->where('source_id', $commission->id)->exists()` antes de crear.

### 2. Comisión interna — job nuevo `commissions:settle-internal`

- Comando en `App\Modules\AdminCont\Console\Commands`, registrado en `Console\Kernel::schedule()`
  **diario** con guard interno de fecha (no mensual estricto — si el server estuvo caído el día 1
  no se pierde el mes). Corre para todo mes ya cerrado (`mes_cierre < startOfMonth actual`) que
  aún tenga comisiones sin movimiento.
- Por cada mes objetivo:
  1. Fuerza la creación de los `CommissionMonthSnapshot` de todos los vendedores con comisión ese
     mes — reusa `CommissionOrderService::percentForVendorMonth()` (que ya crea el snapshot si el
     mes está cerrado), iterando sobre el roster de vendedores con pedidos en ese mes. **No**
     depende de que alguien haya abierto la pantalla (mismo gotcha que SCRUM-867).
  2. Por cada snapshot con `monto_comision > 0` sin movimiento asociado, crea un
     `AdminContBankMovement`:
     - `tipo = TIPO_COMISION`, `source_type = SOURCE_INTERNAL_COMMISSION` **(constante nueva)**,
       `source_id = snapshot.id`
     - `bank_account_id = null` (decisión E-6)
     - `fecha` = **día 1 del mes siguiente** a `mes_cierre` (decisión E-5)
     - `concepto` = "Comisión interna {vendedor} ({mes de cierre, formato 'M Y' en es, ej. 'jun 2026'})"
     - `monto = snapshot.monto_comision`
  3. Idempotente por `(source_type, source_id)`.
- El job **no** cambia `CommissionOrderService::estadoFor()` ni la pantalla de Comisiones
  Internas — solo alimenta `bank_movements`.

### 3. `AdminContBankMovement` — constante nueva

```php
public const SOURCE_INTERNAL_COMMISSION = 'internal_commission';
```
`assignAccount()` (`AdminContBankMovementController`) ya acepta cualquier movimiento con
`tipo === TIPO_COMISION && bank_account_id === null` — sirve para internas sin cambio.

### 4. `CashPositionService` — dejar de filtrar por cuenta para comisiones

`real()`, `bankBalance()` y `netFlowForWindow()` cambian el criterio de:
```
bank_account_id IN (cuentas activas)
```
a:
```
bank_account_id IN (cuentas activas) OR (tipo = 'comision' AND bank_account_id IS NULL)
```
Encapsular en un scope de modelo (`AdminContBankMovement::scopeRealCashImpact($query, $activeAccountIds)`)
para no repetir el `OR` en 3 métodos.

- `real()`: la comisión pagada aparece como **salida** en su fecha. El invariante "la última fila
  cierra en `bankBalance()`" se mantiene porque `bankBalance()` usa el mismo criterio nuevo.
- `projected()`: **sin cambio** (ya proyecta comisiones pendientes).
- Reportes → "Arqueo de Caja" (`netFlowSeries()` / `netFlowForWindow()`): las comisiones sin
  cuenta entran al flujo neto real.

### 5. Backfill — comando `commissions:backfill-cash-movements`

- **Fecha de corte** = `AdminContFiscalSettings::current()->arqueo_tracking_start_date`, con
  fallback a una constante `'2026-08-25'` (fecha de go-live de Batch 18) si el setting es null.
- **Comisiones externas** con `fecha_pago >= corte` y sin movimiento: crea el movimiento con
  `fecha = fecha_pago`, `bank_account_id` = el de la comisión (puede ser null).
- **Comisiones internas**: para cada mes cerrado `>= corte`, misma lógica que el job (§2).
- Idempotente por `(source_type, source_id)`.
- **dev + test**: corre en el `db:seed` / deploy del batch (o manual post-deploy, decisión de
  DevOps). **prod**: `# ESTRATEGIA` aparte, en ventana coordinada con Luis, con captura de
  `bankBalance()` + Vista Real antes y después (decisión E-8).

### 6. Alcance — dónde aparecen (decisión E-4)

Solo Flujo de Caja (Vista Real) + Cuentas Bancarias → Movimientos. El documento "Arqueo N° X"
(`DailyCashCountService::forDate()` / SCRUM-878) **no** se toca — sigue siendo cobros − notas de
crédito, orientado a cobranza.

## Consecuencias

- **Positivo:** el Flujo de Caja Real y el saldo de bancos reflejan todas las salidas por
  comisiones. El flujo "Seleccionar cuenta" (REQ-534) por fin tiene movimientos que categorizar.
- **Riesgo:** el backfill mueve la posición de caja reportada. Mitigación: corte por fecha
  (E-7), prod en ventana con verificación antes/después (E-8).
- **Riesgo:** el job de comisiones internas depende de que `percentForVendorMonth()` calcule bien
  el roster de vendedores del mes. Mitigación: test del comando con varios vendedores, meses
  vacíos, y re-corrida (idempotencia).
- **Depende de Batch C:** el fix de `allProjects()` (join a la raíz) elimina las comisiones
  externas duplicadas antes de que el backfill genere sus movimientos — por eso E va después de C.

## Estado
Implemented: **no** — pendiente de PM breakdown e implementación. Va después de Batch C.
