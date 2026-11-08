# Fase 28 — Contabilidad: cartera, tesorería, comisiones y reportes

Estado: **APROBADA** por Luis (2026-11-07), con las 3 propuestas de §3 aplicadas. Origen: `docs/flujos/pendientes-priorizados.md`, Fase 28. Una sola fase, un PR por repositorio, con sub-entregas internas en este orden.

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

## 3. Decisiones de Luis (2026-11-07): se aplican las 3 propuestas
(Antes eran preguntas abiertas.)
1. **Base de la comisión externa**: hoy es el total con impuesto. Propuesta: pasar a la **base imponible** (sin impuesto) y hacerlo configurable; cambia importes, por eso lo decides tú. Alternativa: dejarlo y documentarlo.
2. **Días de crédito de clientes existentes**: propuesta, copiar el valor global actual a todos, de modo que nada cambie hasta que alguien lo ajuste.
3. **Reportes**: si la diferencia de 0,05 resulta ser por notas de crédito, ¿el libro debe seguir mostrándolas (sí, es un libro fiscal) y el reporte por medio de pago ganar una columna «notas de crédito» para conciliar? (propuesta: sí).

## 4. Verificación prevista
Tests de cada regla (tramos, vencimiento, conciliación de reportes, reasignación, umbrales por proceso), e2e ampliado en `e2e/flujos/` (cierres y tesorería), suite completa del backend por `infra/test.sh`, revisión de seguridad (nuevos permisos: reasignar cuenta y aprobadores por proceso, con chequeo de fuga por nivel compartido) y comprobación en navegador con Playwright CLI.

## 5. Riesgos
- Migración de umbrales: debe conservar los valores actuales; test de equivalencia.
- Cambiar la base de la comisión altera importes futuros; no toca lo ya pagado.
- Los días de crédito por cliente cambian la antigüedad de cartera de quien se ajuste; los reportes históricos se recalculan en vivo.

## 6. Resultado (2026-11-07)
Implementada en la rama `fase28/contabilidad` de ambos repositorios (backend: 11 commits; frontend: 8). Verificación: PHPStan 0 errores; suite completa del backend 5347 tests / 0 fallos (antes de los 2 últimos commits, que se verificaron con 115 tests dirigidos); frontend `tsc` limpio y vitest 258 archivos / 2989 tests; `npm run e2e:flujos` 118 pasan (incluye 6 pasos nuevos de esta fase). Revisión de seguridad: `docs/reviews/fase28-contabilidad-2026-11-07.md`.

| Grupo | Qué quedó |
|---|---|
| 1 Reportes | El 0,05 no era redondeo: el libro incluye pendientes y notas de crédito y «ventas por medio de pago» solo las cobradas. El resumen de este último trae `notas_credito`, `pendientes_total` y `total_libro` (cobradas + pendientes + notas = total del libro). `tipo` desconocido y rangos invertidos → 422. |
| 2 Tramos | `CommissionTierScaleGuard`: solape y mínimo ≥ máximo → 422; `orden` repetido → 422 (solo cartera); **los huecos avisan** (`avisos`), no bloquean (mover un límite exige editar dos tramos). Corrige el PUT parcial con solo `monto_maximo`. |
| 3 Cartera | Una factura pagada no cuenta en ningún tramo. `client_billing_profiles.dias_credito` (NULL = rige el global, en vez de copiar el valor a cada cliente: mismo comportamiento al desplegar). `CreditTerms` es la única fórmula de vencimiento (6 sitios). `PUT /admin-contab/clientes/{id}/dias-credito` (`records.manage`). |
| 4 Tesorería | `reassign-account` (motivo ≥ 10, auditado, bloqueado con arqueo cerrado, solo salidas); filtros `tipo/desde/hasta/sin_cuenta`; `pendientes_sin_cuenta`; aviso al desactivar una cuenta con movimientos; el aprobador primario o un suplente es segundo asignador. |
| 5 Comisiones externas | Ajuste `comision_externa_base` (`total` por defecto | `base_imponible`, **no activado**: cambia los importes de lo no pagado); `regimen_pendiente`/`comision_sin_regimen`; alias `pendiente_cuenta_cobro`; `avisos` al pagar sin cuenta. Los archivos ya validaban tipo y tamaño; el cruce con el monto no es viable sin leer el documento. |
| 6 Aprobadores | **Los umbrales ya estaban separados** (el de notas de crédito desde la 14d; caja chica, arqueo y anulaciones no tienen umbral monetario), así que no hubo migración de umbrales. `process_approvers`: aprobadores propios para caja chica, arqueo, anulación de cobros y notas de crédito (`primary_approver_only:<proceso>`); sin filas sigue el aprobador primario. API y pestaña solo superadmin; cambios auditados. |

**Abiertos / candidatos**: los 4 specs e2e que fallan (`compras-familias`, `ventas-diseno`, `bodega-ubicaciones-rutas-kardex`, `venta-a-cobro` paso 15) fallan igual en `master` (deriva de la BD de desarrollo, no de esta fase); `FiscalDocumentBuilder` aún toma los días de crédito globales para la fecha que viaja al PAC; la asignación inicial de cuenta no mira el arqueo cerrado (a propósito: las comisiones se asignan después del pago); `anulacion_cobro` también decide devoluciones y reversos de compensación; ninguna pantalla edita el % por defecto de la comisión externa.
