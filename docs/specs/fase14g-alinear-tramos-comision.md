# Fase 14g — Alinear los tramos de comisión de Admin & Contab y Ventas

**Estado**: decisiones de §5 tomadas por Luis (2026-10-30); pendiente de su «adelante con 14g». Origen: el «Abierto» de §9 de `fase14-parametrizacion.md` (14d). No se implementa sin su «adelante».

## 1. Objetivo
Hoy hay DOS tablas de tramos de comisión con la misma escala de porcentajes (1,5 / 2 / 3 / 4 / 4,5 / 5 %) pero con los puntos de corte en lugares distintos, así que el mismo total mensual puede dar un porcentaje diferente según la pantalla. Que haya una sola regla, o que la diferencia sea una decisión consciente y visible.

## 2. Hallazgos (medidos en el seed de `master`)
| Tramo | Admin & Contab (`commission_tiers`) | Ventas & Diseño (`sales_commission_tiers`) |
|---|---|---|
| 1,5 % | 0 – 20.000 | 0 – 19.999,99 |
| 2 % | 20.001 – 35.000 | 20.000 – 34.999,99 |
| 3 % | 35.001 – 60.000 | 35.000 – 59.999,99 |
| 4 % | 60.001 – 81.000 | 60.000 – 79.999,99 |
| 4,5 % | 81.001 – 120.000 | **80.000** – 119.999,99 |
| 5 % | desde 120.001 | desde 120.000 |

- **Diferencia pequeña** (una unidad): en los cortes de 20 mil, 35 mil, 60 mil y 120 mil, un total de 20.000 a 20.000,99 da 1,5 % en Admin & Contab (límite inferior inclusivo desde 14d) y 2 % en Ventas.
- **Diferencia real**: el corte del 4,5 % es **81.001 en Admin & Contab y 80.000 en Ventas**. Un total de 80.500 da 4,5 % en Ventas y 4 % en Admin & Contab. El código de Ventas lo documenta como una ambigüedad del Excel original («zona ambigua entre $80.000 y $81.000»): quedó un corte único en 80.000.
- Cada módulo lee SU tabla con su propia pantalla de edición (`CommissionTiersModal` en Admin & Contab; `SalesCommissionTierController` en Ventas) y SU lógica de búsqueda (Admin: mayor `monto_minimo` que no supera el total; Ventas: rango inclusivo con `monto_maximo` en «.99»).
- Las bases de cálculo son distintas: Ventas suma las tarjetas ganadas del mes; Admin & Contab suma los pedidos facturables. Una diferencia de base es legítima; una diferencia de ESCALA para el mismo concepto no lo parece.
- Los meses ya cerrados no se recalculan: Ventas persiste su cohorte (`CommissionCohort`) y Admin & Contab su `commission_month_snapshots`. Cambiar la escala solo afecta meses abiertos.

## 3. Diseño propuesto (depende de la decisión 1 de §5)
- **A — Una sola tabla**: Admin & Contab y Ventas leen la misma escala (el servicio de búsqueda y la pantalla de edición son únicos). Migración: toma los valores de Ventas (o los que Luis elija), copia lo que haya personalizado una empresa y borra la tabla sobrante. Más trabajo y cambia dos pantallas.
- **B — Dos tablas, valores alineados**: se conservan las dos tablas, pero una migración de DATOS iguala los cortes de Admin & Contab a los de Ventas (mínimos 20.000 / 35.000 / 60.000 / 80.000 / 120.000), solo si la empresa no los ha personalizado (si difieren del seed, se respeta y se avisa). Sin cambios de código salvo un aviso en pantalla si las dos escalas difieren.
- **C — No se toca**: se documenta la diferencia como intencional y el modal de Admin & Contab dice «Esta escala es independiente de la de Ventas».

## 4. Criterios de aceptación (A o B)
1. Con los valores sembrados, el mismo total mensual produce el mismo porcentaje en Admin & Contab y en Ventas, incluidos los bordes (19.999,99, 20.000, 20.000,50, 79.999,99, 80.000, 80.500, 119.999,99, 120.000).
2. Una empresa que personalizó sus tramos no pierde sus valores; si difieren entre módulos, la pantalla lo advierte.
3. Los meses cerrados (snapshots y cohortes) no cambian.
4. Test de equivalencia sobre esos bordes en ambos módulos; PHPStan, Pint de archivos propios y suites en verde.

## 5. Decisiones de Luis (2026-10-30)
1. **Escala**: **B — dos tablas con valores iguales**. Una migración de datos iguala los cortes de Admin & Contab a los de Ventas, solo donde siguen en el valor sembrado; lo personalizado se respeta y se avisa. ✅
2. **Corte del 4,5 %**: **80.000** (el de Ventas). Los mínimos de Admin & Contab quedan en 0 / 20.000 / 35.000 / 60.000 / 80.000 / 120.000. ✅
3. **Límite**: **inclusivo hacia arriba** (un total igual al corte pasa al tramo siguiente), como hoy en Ventas y, desde 14d, en Admin & Contab. ✅
4. **Entrega**: 1 PR de backend y 1 de frontend (el aviso de escalas distintas en el modal) — propuesta, no se preguntó.

## 6. Riesgos
- Cambiar la escala de Admin & Contab sube o baja comisiones de los meses abiertos para totales entre 80.000 y 81.000 (y en el primer peso de cada corte). Hay que avisar a Contabilidad antes de aplicar.
- Una empresa que haya editado sus tramos queda con valores propios distintos: por eso B solo corrige lo que sigue en el valor sembrado.

## 7. No-alcance
Cambiar las bases de cálculo, las comisiones de cartera (`cartera_commission_tiers`), las comisiones de arquitectos o prescriptores, y el recálculo de meses cerrados.
