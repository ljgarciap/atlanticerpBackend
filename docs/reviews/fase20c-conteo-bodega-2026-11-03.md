# Senior Review — Fase 20c: el conteo de Bodega manda sobre lo que entra al inventario

**Veredicto: APROBADO, 0 bloqueantes, 3 observaciones.** Alcance revisado: `GoodsReceiptConfirmationService` (`confirmPendingLine`, `splitUnreportedRemainder`, `confirmLine` con nota de Kardex), `GoodsReceiptController` (`confirm`, `confirmPendingForProduct`) y `confirmPendingForOrder`. Spec: `docs/specs/fase20-integridad-de-flujos.md` §3.C, aprobada por Luis el 2026-11-03.

## Qué se verificó
- **Corrección del reparto.** Con Bodega contando 6 de 10 entran 6 al stock (Entrada de Kardex por 6, con la diferencia escrita en la referencia) y queda una línea hija de 4, sin reporte de Bodega, en el mismo ingreso y almacén. El total registrado (`sum(quantity)`) es el original, así que `isFullyReceived`, `receivedQuantitiesByProduct` y `enCaminoMap` no cambian.
- **Dinero.** ITBMS y los cuatro prorrateos de costo se reparten proporcionalmente a lo contado; el resto toma la diferencia (`round(total − parte, 2)`), así la suma de ambas líneas es exactamente la original. Probado con 7,00 → 2,10 + 4,90.
- **Concurrencia.** Los tres caminos bloquean la línea (`lockForUpdate`) y revisan que siga pendiente **dentro** de la transacción. Antes, `confirm()` y `confirmPendingForProduct()` leían fuera de ella: dos confirmaciones simultáneas podían entrar dos veces al stock. El e2e dispara dos confirmaciones en paralelo y obtiene exactamente un 200 y un 422, con el stock subiendo una sola vez.
- **Sin reporte de Bodega = comportamiento de siempre** (ni partición ni cambio de cantidades); un conteo completo tampoco parte la línea. Las 4 pruebas de regresión de estos caminos pasan.
- **Pruebas que de verdad detectan el cambio**: con el código de la app revertido, 5 de los 7 tests nuevos fallan.
- Verificación completa: PHPStan nivel 8 sin errores en Compras; suites de Compras (601), Bodega (580) y Servicios (410, el listener de insumos lee estas líneas) en verde; los 117 pasos de e2e de flujos pasan.

## Observaciones (no bloquean)
1. **Avanzar la orden a «Recibido» con una línea contada de menos**: `confirmPendingForOrder` parte la línea igual, y la parte no contada queda «Por ingresar» aunque la orden pase a Recibido. Es coherente con la regla (inventario = lo contado) y visible en Inventario, pero conviene que el equipo lo sepa. Documentado en el catálogo.
2. **La línea hija no hereda el reporte de Bodega a propósito**: hay que reportarla de nuevo cuando llegue. Es lo que pide la spec.
3. **Alta de líneas con confirmación inmediata** (`GoodsReceiptController` al crear un ingreso ya confirmado) no pasa por `confirmPendingLine`: no hay reporte de Bodega posible en ese momento, así que no aplica.

## Riesgo residual
Bajo. El cambio solo altera el resultado cuando Bodega reportó una cantidad menor a la registrada, un caso que hasta hoy era justamente el que dejaba el inventario sobrevalorado.
