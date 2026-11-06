# Fase 28 — Contabilidad: cartera, tesorería, comisiones y reportes

Estado: **BORRADOR** (espera aprobación de Luis). Origen: `docs/flujos/pendientes-priorizados.md`, Fase 28. Una sola fase, un PR por repositorio, con sub-entregas internas en este orden.

## 1. Decisiones ya tomadas (2026-11-06)
1. **Una sola fase** con los siete grupos de trabajo (la pasada de «verificar» ya está hecha en los catálogos: cierres 1, 2, 3, 4, 7 y notas 1 a 4 y 6 quedaron resueltos por las Fases 12 a 15).
2. **Días de crédito por cliente**, con un valor por defecto global; el atraso cuenta desde el **vencimiento** (fecha de la factura + días de crédito), no desde la fecha de la factura.
3. **Umbrales y aprobadores separados** para caja chica, arqueos, anulaciones y notas de crédito; cada proceso con su propio aprobador (y suplentes, como en la Fase 12b). El umbral de compras deja de compartirse con el de notas de crédito.

## 2. Alcance por grupo
| # | Grupo | Qué cambia |
|---|---|---|
| 1 | Reportes (primero) | Investigar la diferencia de 0,05 entre libro de facturas y ventas por medio de pago (misma fuente `InvoiceTaxBreakdown`; sospechas: el libro incluye notas de crédito y el otro solo facturas cobradas, y redondeo por línea). Reproducir con un test, corregir la causa y fijar una prueba de conciliación entre ambos. Tipo desconocido → 422. Series con rango propio (`desde`/`hasta`). |
| 2 | Tramos | Validar al guardar: sin solapes, sin `orden` repetido, sin huecos (continuidad, incluido el 0,01). La tabla muestra los límites que realmente se aplican. Aplica a tramos de comisión y de cartera. |
| 3 | Cartera | Una factura pagada no cuenta en «91+ días»; «cantidad» solo cuenta facturas con saldo. Días de crédito por cliente + vencimiento. |
| 4 | Tesorería | Corregir una asignación de cuenta (con motivo, auditada, solo Contabilidad/aprobador); aviso y pendiente visible para salidas sin cuenta; filtros por tipo, fecha y «sin cuenta»; aviso al desactivar una cuenta con movimientos; segundo asignador (aprobador primario o suplente) si Contabilidad no está. |
| 5 | Comisiones externas | Totales del arquitecto coherentes sin régimen (mostrar «pendiente de régimen» en lugar de 0); renombrar el estado `pendiente_factura_arquitecto` en la interfaz (y alias en la API); aviso al pagar sin cuenta de pago; validar archivos (tipo y tamaño; el cruce con el monto queda como aviso). Base de cálculo: ver §3. |
| 6 | Umbrales y aprobadores | Ajustes separados por proceso (migración que copia el valor actual a cada uno, sin cambiar el comportamiento al desplegar) y aprobador/suplentes por proceso en la pestaña «Aprobadores». |

## 3. Preguntas abiertas para Luis
1. **Base de la comisión externa**: hoy es el total con impuesto. Propuesta: pasar a la **base imponible** (sin impuesto) y hacerlo configurable; cambia importes, por eso lo decides tú. Alternativa: dejarlo y documentarlo.
2. **Días de crédito de clientes existentes**: propuesta, copiar el valor global actual a todos, de modo que nada cambie hasta que alguien lo ajuste.
3. **Reportes**: si la diferencia de 0,05 resulta ser por notas de crédito, ¿el libro debe seguir mostrándolas (sí, es un libro fiscal) y el reporte por medio de pago ganar una columna «notas de crédito» para conciliar? (propuesta: sí).

## 4. Verificación prevista
Tests de cada regla (tramos, vencimiento, conciliación de reportes, reasignación, umbrales por proceso), e2e ampliado en `e2e/flujos/` (cierres y tesorería), suite completa del backend por `infra/test.sh`, revisión de seguridad (nuevos permisos: reasignar cuenta y aprobadores por proceso, con chequeo de fuga por nivel compartido) y comprobación en navegador con Playwright CLI.

## 5. Riesgos
- Migración de umbrales: debe conservar los valores actuales; test de equivalencia.
- Cambiar la base de la comisión altera importes futuros; no toca lo ya pagado.
- Los días de crédito por cliente cambian la antigüedad de cartera de quien se ajuste; los reportes históricos se recalculan en vivo.
