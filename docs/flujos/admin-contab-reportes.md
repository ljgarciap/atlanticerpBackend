# Flujo: reportes e Inicio de Admin & Contab

Continuación de [comisión de cartera](admin-contab-comision-cartera.md) (que ya cubre `reports/cartera` y `reports/commission-tiers`). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 con las cuentas demo de cada rol y una venta propia cobrada por transferencia (`npm run e2e:flujos`, archivo `admin-contab-reportes.spec.ts`; la cadena de la venta vive en `e2e/flujos/apoyo/venta-cobrada.ts`).

## Qué es
Reportes de solo lectura calculados en vivo sobre facturas, cobros, notas de crédito y comisiones. Tres familias:
- **Por cliente** (`master_client_id`, o `todos`): ventas por medio de pago, mensual (agrupado por día) y acumulado (por año-mes).
- **Globales**: libro de facturas (con filtro de fechas y de tipo) y series por período (`hoy`, `3m`, `6m`, `anio`): ventas, flujo de caja y comisiones; notas de crédito (siempre mes en curso).
- **Inicio** (`home/*`): resumen del mes, pendientes, vencidos y por vencer, aprobaciones pendientes.

## Quién ve qué
Todos exigen `admin_contab.records.manage`: Contabilidad, Asistente y Gerencia reciben 200; el vendedor y el Líder de Compras, 403, sin excepción en ninguna ruta de reportes ni de Inicio [E]. La única ruta más angosta es la comisión de cartera (sin Asistente, ver el flujo anterior).

## 1. Reportes por cliente
- **Ventas por medio de pago**: una fila por factura cobrada con una columna por método (`transferencia`, `cheque`, `efectivo`, `yappy`, `link_pago`, `tarjeta`, `deposito`, retención y ajuste). Una venta cobrada por transferencia aparece solo en esa columna; base + impuesto = total [E]. Un cliente sin cobros devuelve 0 facturas y ninguna fila [E].
- **Mensual / acumulado**: sin `master_client_id` responde `{estado: sin_cliente}`; con él, `resumen` (registros, facturado, cobrado, pendiente), `filas` por día o por mes, `totales` y `pendientes` (facturas sin cobrar). La venta propia da 1 registro, facturado = cobrado y 0 pendiente [E]. `desde`/`hasta` acotan; fuera de rango no hay filas [E]. `master_client_id=todos` suma todos los clientes [E].
- **Excel** de los tres: descarga un `.xlsx` si hay datos; **sin datos responde 422** («Selecciona un cliente con datos…») [E].

## 2. Libro de facturas
Una fila por factura y por nota de crédito, con RUC, cliente, base, porcentaje, ITBMS, total y vendedor. Filtros `desde`, `hasta` y `tipo` (`factura` | `nota_credito`; otro valor se ignora y no filtra) [E][C]. El resumen cuenta `facturas` y `notas_credito` y coincide con las filas de cada tipo [E]. Excel con 422 sin documentos [E].

## 3. Series, notas de crédito e Inicio
- `ventas`, `flujo-caja` y `comisiones` aceptan `periodo` ∈ `hoy`, `3m`, `6m`, `anio`; otro valor → 422 [E]. El mes actual de **ventas** incluye la venta, el de **flujo de caja** la cuenta como entrada y `neto = entradas − salidas` [E]. `flujo-caja` trae además el saldo disponible hoy y el proyectado a 30 días [E].
- `notas-credito` agrupa por motivo y nunca toma período [E][C].
- `home/resumen-mes`: `total_cobrado_mes` suma el cobro; incluye cuentas al día, cartera por cobrar (con los incobrables excluidos aparte) y ventas de ayer [E].

## Candidatos a cambio o mejora
1. **Dos reportes con el mismo total difieren en base e impuesto**: con 5.356,64 de total, el libro de facturas da base 5.006,14 e ITBMS 350,50 y el de ventas por medio de pago 5.006,19 y 350,45 (0,05 de diferencia; la causa no se investigó, las notas de crédito son la sospecha). Quien concilie con ambos verá descuadres. [E]
2. **Un `tipo` desconocido en el libro de facturas se ignora en silencio** y devuelve todo, en vez de 422 como hacen las series con `periodo`. [E][C]
3. **El reporte por cliente exige elegir un cliente a mano** (si no, `sin_cliente`); el `todos` existe en la API pero la combinación con Excel solo descarga si hay filas. [C]
4. **Las series no permiten un rango propio**: solo cuatro períodos fijos, sin fechas; el libro y los por cliente sí aceptan fechas. [C]
5. **`notas-credito` y la comisión de cartera ignoran el período del selector** por diseño (RN de los requerimientos), lo que puede confundir en una pantalla que sí lo muestra. [C]
