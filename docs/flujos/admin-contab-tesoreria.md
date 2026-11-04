# Flujo: tesorería (cuentas bancarias y movimientos) en Admin & Contab

Continuación de [comisiones externas](admin-contab-comisiones-externas.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-04 con las cuentas demo de cada rol y un proyecto propio creado por el flujo (`npm run e2e:flujos`, archivo `admin-contab-tesoreria.spec.ts`, 4 pasos).

## Qué es
- **Cuentas bancarias** (`/admin-contab/bank-accounts`): catálogo de cuentas de la empresa. No se borran: se activan y desactivan [C].
- **Movimientos** (`/admin-contab/bank-movements`): libro de entradas y salidas de caja. Lo escriben los otros módulos (cobros, devoluciones de dinero, comisiones), nunca la pantalla: no hay alta manual de movimientos [C]. `tipo` ∈ `cobro` (entrada), `devolucion` y `comision` (salidas) [E].

## Quién decide qué
- **Ver cuentas y movimientos**: Contabilidad, Asistente y Gerencia (`admin_contab.read`); vendedor y Líder de Compras reciben 403 [E].
- **Crear, editar, desactivar y reactivar cuentas**: Contabilidad, Asistente **y Gerencia** (`admin_contab.records.manage`); vendedor y Líder de Compras, 403 [E].
- **Asignar cuenta a un movimiento**: solo Contabilidad y Asistente (`admin_contab.operations.manage`); Gerencia, el aprobador primario y los demás reciben 403 [E].

## 1. Cuentas bancarias
- Alta: `banco` obligatorio; `ultimos_4_digitos` exactamente 4 dígitos (`12`, `12345` y `abcd` → 422); `tipo_cuenta` ∈ corriente, ahorro, tarjeta de crédito (otro → 422) [E]. Nace **siempre activa**, con tipo corriente y moneda USD por defecto, aunque el cliente mande `activa: false` [E].
- Editar (`PUT /{id}`), `PUT /{id}/deactivate` y `/reactivate`; un id inexistente o no numérico → 404 [E]. `GET ?activa=true` deja fuera las inactivas: es el filtro que usan los selectores [E].

## 2. Movimientos
- Listado paginado, del más reciente al más antiguo, con `direccion` derivada del tipo (cobro = entrada; devolución y comisión = salida) y la etiqueta de la cuenta [E]. `?bank_account_id=` filtra por cuenta [E].

## 3. Comisión pagada sin cuenta de pago
Si Contabilidad marca pagada una comisión externa sin haber fijado la cuenta de pago, el pago se acepta y registra la salida **sin cuenta** (`bank_account_id` y etiqueta nulos) [E].

## 4. Asignar cuenta (`PUT bank-movements/{id}/assign-account {bank_account_id}`)
- Solo sirve para una **comisión sin cuenta**: sobre un cobro, o sobre una comisión que ya tiene cuenta, responde 422 «Solo se puede asignar cuenta a un movimiento de comisión sin cuenta asignada.» [E].
- La cuenta es obligatoria, debe existir y **estar activa** (422 si no) [E]. Movimiento inexistente o id no numérico → 404 [E].
- Hecho, el movimiento aparece en el filtro de esa cuenta [E]. No hay vuelta atrás por la API: no se reasigna [E].

## Candidatos a cambio o mejora
1. **Quién edita cuentas y quién asigna no coinciden**: Gerencia crea y edita cuentas pero no puede asignar la cuenta de un movimiento; el aprobador primario tampoco. Es el roster «angosto» de la asignación (REQ-534) frente al ancho de las cuentas; conviene confirmar que es intencional. [E]
2. **Marcar pagada una comisión sin cuenta de pago no avisa**: la salida queda «sin cuenta» y alguien debe acordarse de asignarla. Un aviso en la respuesta o un pendiente visible evitaría salidas de caja sin cuenta. (Ya anotado en comisiones externas.) [E]
3. **La asignación no se puede corregir**: una cuenta mal elegida no se cambia ni se retira; habría que anular y volver a pagar, o tocar la base. [E]
4. **Las cuentas no se pueden borrar** y no se valida que dos cuentas repitan banco y últimos 4 dígitos. Quedan desactivadas, pero el catálogo crece. [C]
5. **Una cuenta con movimientos se puede desactivar** sin aviso; solo deja de ofrecerse en los selectores. [C]
