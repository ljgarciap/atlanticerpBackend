# Flujo: tesorería y movimientos bancarios en Admin & Contab

Continuación de [comisiones externas](admin-contab-comisiones-externas.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-01 con las cuentas demo de cada rol y un proyecto propio creado por el flujo (`npm run e2e:flujos`, archivo `admin-contab-tesoreria.spec.ts`).

## Qué es
`bank_movements` es el libro de movimientos de las cuentas bancarias. Lo alimentan los cobros (entrada), las devoluciones y anulaciones de cobro (salida) y las comisiones (salida). La **posición de caja** (`GET cash-position`, `saldo_bancos`) no se guarda: se calcula en vivo desde ese libro [C].

## Quién decide qué
- **Ver movimientos y cuentas** (`admin_contab.read`): Contabilidad, Asistente y Gerencia; vendedor y Líder de Compras reciben 403 [E].
- **Crear, editar, activar y desactivar cuentas** (`records.manage`): el vendedor recibe 403 [E].
- **Asignar cuenta a un movimiento** (`operations.manage`, el permiso más angosto): solo Contabilidad y la Asistente. Gerencia **y el aprobador primario** reciben 403 [E].

## 1. Una comisión pagada sin cuenta de pago
Marcar pagada una comisión externa **no exige** cuenta de pago. Si no se fijó, se registra igual la salida de caja (`tipo = comision`, `direccion = salida`, monto = comisión + impuesto) con `bank_account_id = null`, que la interfaz muestra como «Sin cuenta asignada» [E].
- **El dinero ya salió**: el saldo de bancos baja en el monto de la salida aunque no tenga cuenta (la regla `realCashImpact` cuenta las comisiones sin cuenta) [E].
- Régimen fiscal `exento` y cuenta de cobro bastan para pagar; no hace falta comprobante de retención [E].

## 2. Asignar la cuenta (`PUT bank-movements/{id}/assign-account {bank_account_id}`)
- Sin `bank_account_id` o con una cuenta inexistente → 422; movimiento inexistente → 404 [E].
- Una cuenta **desactivada** no se acepta (422) [E].
- **Solo aplica a comisiones sin cuenta**: un cobro (que ya tiene cuenta) → 422 [E]. Una comisión que ya tiene cuenta tampoco se reasigna (422) [E].
- Asignar solo **categoriza**: el saldo de bancos no cambia [E].

## 3. Cuentas y saldo
- `GET bank-accounts?activa=true` filtra las activas y cada cuenta trae `movimientos_count` [E].
- `GET bank-movements` acepta `bank_account_id` (el filtro no trae movimientos de otras cuentas) y pagina con `meta`; **no filtra por tipo ni por «sin cuenta»** y solo ordena por fecha, de modo que encontrar un movimiento obliga a recorrer páginas [E].
- **Desactivar una cuenta saca sus movimientos del saldo de bancos**: la salida de una comisión asignada a la cuenta deja de restar (el saldo sube en ese monto); al reactivarla vuelve el valor anterior [E]. Lo mismo ocurre con los cobros de esa cuenta [C].

## Candidatos a cambio o mejora
1. **Desactivar una cuenta con movimientos hace «reaparecer» dinero** en la posición de caja (y haría desaparecer los cobros). Es coherente con «solo cuentas activas», pero un cierre de cuenta legítimo (el banco se cerró) cambia el saldo histórico sin aviso. [E]
2. **Una asignación equivocada no se corrige**: asignada la cuenta, ni Contabilidad puede cambiarla (422). [E]
3. **Una salida sin cuenta no genera aviso ni pendiente visible** más allá de la fila «Sin cuenta asignada»; nadie recibe una notificación para completarla. [C]
4. **El listado de movimientos no filtra por tipo, fecha ni «sin cuenta»**; ordena solo por fecha, así que un orden estable y un filtro «pendientes de asignar» ayudarían a la tarea que el permiso angosto existe para hacer. [E]
5. **Ni Gerencia ni el aprobador primario pueden asignar** (carve-out deliberado de REQ-534); si Contabilidad no está, la salida queda sin cuenta. [E]
