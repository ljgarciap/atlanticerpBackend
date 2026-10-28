# Flujo: compensaciones, incobrables, caja chica y arqueo (Admin & Contab)

Continuación de [notas de crédito y anulaciones](admin-contab-notas-y-anulaciones.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-10-29 con las cuentas demo de cada rol.

## Quién decide qué
Contabilidad (`admin_contab.records.manage` / `operations.manage`) registra y propone; **el aprobador primario configurado** decide incobrables, reportes de caja chica, arqueos, notas, reversos y tramos de comisión. Otra cuenta de Gerencia recibe 403 «Esta pantalla es exclusiva del aprobador configurado» [E].

## 1. Compensación de saldo a favor
Cruza lo que el cliente tiene a favor con facturas abiertas. `POST admin-contab/compensaciones/preview` y `POST …/compensaciones` con `{master_client_id, lines[{source_type, source_id, invoice_id, monto}]}`; `source_type` ∈ `cobro`, `abono`, `nota_credito` [E][C].
- Reglas: no se usa más de lo disponible de una fuente («NC-0034 solo tiene $498.38 disponibles»), la fuente debe ser del cliente, y el vendedor no compensa (403) [E].
- La vista previa informa el total aplicado, el saldo a favor antes y después y el pendiente de cada factura [E].
- Al ejecutar, la factura queda saldada (sale de las abiertas) y repetirlo responde 422: las fuentes ya no tienen saldo [E].
- **Reversar** (`POST compensaciones/{id}/reverse {motivo}`): exige motivo, **no necesita aprobación**, reabre la factura y no se reversa dos veces [E].

## 2. Incobrables
`POST invoices/{pedido}/mark-uncollectible {motivo}` propone; `PUT invoices/{pedido}/uncollectible-decision {approve}` decide solo el aprobador [E]. El parámetro `{pedido}` es el pedido raíz de la factura, no el id de la factura [E].
- Motivo obligatorio, el vendedor 403; tras aprobar, la factura sale de las abiertas del cliente; decidir de nuevo → 422 «No hay ninguna propuesta de incobrable pendiente» [E].

## 3. Abonos
Con el concepto nuevo de cobros encendido, `POST admin-contab/abonos` responde 422 «Los abonos se registran ahora como cobros (con o sin factura) desde Cobros» [E]. Las rutas de abonos (alta, edición y confirmación) siguen en el código como herencia [C].

## 4. Caja chica
1. `POST petty-cash/expenses` (multipart `lineas[i][fecha|solicitante_id|proveedor|descripcion|monto_bruto|itbms|foto]`): la **foto del comprobante es obligatoria**; solo Contabilidad (vendedor 403) [E].
2. `POST petty-cash/reports {expense_ids[], forma_pago}`: forma de pago obligatoria (transferencia, efectivo, cheque o Yappy); folio `0001-2026` [E][C]. Un gasto va a **un solo reporte** (409 «puede que otro reporte ya los haya incluido») [E].
3. `PUT petty-cash/reports/{numero}/approve|reject`: solo el aprobador; rechazar exige motivo; aprobar deja el reporte `finalizado` y no se aprueba dos veces (409) [E].

## 5. Arqueo diario de caja
`GET cash-position/daily-count` arma el día: cobros, notas de crédito, comisiones, reversos y retenciones con sus totales; **neto = cobrado − notas − reversos** (7.957,32 − 30 − 7.257,32 = 670) [E].
- `PUT …/entries` anota cada movimiento y `PUT …/general-observation` la observación general (204); el vendedor 403 [E].
- `POST …/close` cierra el arqueo (`pendiente_aprobacion`, número consecutivo): **una sola vez** (409 «ya no está abierto») y, cerrado, no se edita (422) [E].
- `POST cash-position/history/{id}/approve` solo el aprobador; pasa a `aprobado` y no se aprueba dos veces (409) [E].
- **Un arqueo por día**: la primera corrida de la prueba automática del día lo cierra; las siguientes verifican las protecciones sobre el ya cerrado [E].

## 6. Tramos de comisión (configuración)
Los tramos viven en la base (internos y de cartera), con `monto_minimo`, `monto_maximo`, `porcentaje` (0–100) y `orden`; **solo el aprobador** los crea o edita (contabilidad y otra Gerencia, 403; porcentaje > 100, 422) [E]. El resumen mensual de un vendedor aplica el tramo de su total de pedidos del mes (10.313,24 → 1,5 %) [E].

## Candidatos a cambio o mejora
1. **Reversar una compensación no necesita aprobación, pero anular un cobro sí**, y ambos reabren la deuda. Criterio distinto para efectos equivalentes. [E]
2. **El listado de saldo a favor no coincide con lo compensable**: mostró 50 para un cliente que podía compensar 568,38 (las notas de crédito no aparecen como filas). [E]
3. **Una factura incobrable conserva el estado `facturada`** y el detalle sigue ofreciendo marcarla. [E]
4. **Los abonos heredados siguen en el código** aunque ya no se aceptan: retirar las rutas y la bandera de cobros v2. [E][C]
5. **Las formas de pago son una lista fija con valores regionales (Yappy)**: debería ser un catálogo por empresa y país. [C]
6. **Los tramos sembrados dejan un hueco de una unidad** (0–20.000 y luego desde 20.001) y no coinciden con los del manual (0–19.999,99): por verificar qué tramo recibe 20.000,50. [E][C]
7. **Un solo arqueo por día sin reapertura**: un error al anotar un movimiento no se puede corregir después del cierre sin intervención en la base. [E]
8. **Comparten umbral y aprobador** la caja chica, los arqueos y las anulaciones: si el aprobador falta, todo se detiene. [E][C]
9. **Sin ejercitar**: comisiones externas (perfil fiscal del arquitecto, cuenta de cobro, comprobante de retención, propuesta y decisión del porcentaje, pago), tesorería y movimientos bancarios (asignar cuenta), cuentas bancarias (baja y reactivación), exención de ITBMS de un cliente, devolución de dinero al cliente y reportes. [C]
