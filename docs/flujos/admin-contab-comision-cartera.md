# Flujo: comisión de Contabilidad por gestión de cartera +90 días

Continuación de [tesorería](admin-contab-tesoreria.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 con las cuentas demo de cada rol (`npm run e2e:flujos`, archivo `admin-contab-comision-cartera.spec.ts`) y, para el cálculo del atraso, con una verificación aparte descrita en §2.

## Qué es
Contabilidad gana un porcentaje de lo **cobrado en el mes en curso de facturas que se pagaron con más de 90 días de atraso**. El porcentaje sale de una tabla de tramos (`cartera_commission_tiers`: `monto_minimo`, `monto_maximo`, `porcentaje`, `orden`) según el monto cobrado de esa cartera. El reporte **nunca depende del selector de período**: siempre es el mes en curso [C][E].

## Quién decide qué
- **Comisión** (`GET reports/commission-tiers`): Contabilidad, Gerencia y el aprobador; la Asistente recibe 403 (SCRUM-881) [E].
- **Cartera** (`GET reports/cartera`) y **lectura de tramos** (`GET commissions/cartera/tiers`): además la Asistente; el vendedor recibe 403 en ambos [E].
- **Crear, editar y borrar tramos**: solo el aprobador primario configurado. Contabilidad, la Asistente, el vendedor y otra cuenta de Gerencia reciben 403 [E].

## 1. El reporte
`cobrado_mes`, `rango_actual`, `porcentaje`, `comision = cobrado_mes × porcentaje / 100` y la lista de tramos con `es_actual` (exactamente uno) [E]. Con el demo: tres tramos (0–14.999,99 → 1 %; 15.000–19.999,99 → 1,5 %; desde 20.000 → 2 %) [E]. El `cobrado_mes` de la cartera y el de la comisión son el mismo número [E]. Sin ningún tramo definido, la comisión degrada a 0 sin error [C].

## 2. Qué cuenta como «+90 días»
Una línea de cobro confirmado cuenta si su atraso > 90: `fecha de aplicación − (fecha de la factura + días de crédito)`, con los días de crédito de la Configuración Fiscal (30 en el demo) y excluidas las facturas incobrables. También cuentan las compensaciones del mes que salen del saldo de una nota de crédito [C].
- **Verificado el 2026-11-02** retrocediendo la fecha de una factura cobrada de prueba directamente en la base y restaurándola: con atraso de **90 días no cuenta; con 91 días sí** (`cobrado_mes` 345,59 → comisión 3,46 al 1 %), y la factura aparece en el tramo «91+ días» de la cartera [E]. Por la API no se puede reproducir, porque las facturas nacen con la fecha de hoy y la fecha de aplicación no se envía; por eso el e2e no cubre este cálculo.

## 3. Tramos (CRUD)
- Máximo ≤ mínimo, porcentaje > 100, `orden` < 1 y mínimo negativo → 422 [E].
- Borrar un tramo inexistente o ya borrado → 404 [E].
- **No se valida el solapamiento ni el `orden` repetido**: un tramo que cubre lo mismo que otro, con el mismo orden, se acepta (201). Gana el primero por `orden`; con órdenes iguales el desempate no está definido [E][C].

## Candidatos a cambio o mejora
1. **Los tramos pueden solaparse, repetir `orden` o dejar huecos** sin ningún aviso: el porcentaje aplicado depende de un desempate no definido. Convendría validar continuidad al guardar. [E]
2. **Hueco de 0,01 entre tramos**: el límite superior es 14.999,99 y el siguiente empieza en 15.000; un cobrado intermedio (p. ej. 14.999,995 por redondeo) no cae en ninguno y la comisión sería 0. [C]
3. **Una factura ya pagada sigue contándose en el tramo «91+ días» de la cartera** (cantidad 1, monto 0), así que «cantidad» mezcla facturas con y sin saldo. [E]
4. **El atraso se mide contra la fecha de la factura**, no contra una fecha de vencimiento acordada con el cliente; renegociar un plazo no lo cambia. [C]
5. **Los días de crédito son globales** (uno solo para todos los clientes). [C]
6. **Los tramos de cartera comparten el patrón y los problemas de presentación de los tramos internos** (ver cierres, candidato 6): límites mostrados distintos de los aplicados. [C]
