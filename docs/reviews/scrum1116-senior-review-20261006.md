# Senior Review independiente — SCRUM-1116 (valor acreditable restante)

Fecha: 2026-10-06. Alcance: backend `origin/dev..HEAD` (4 commits, P1-P5) y frontend rama `scrum1116-fe` (1 commit). Revisión de solo lectura, sin ejecutar tests.

## Veredicto: DEVUELTO (1 hallazgo ALTO que incumple RN6/A3 de la spec aprobada; el resto son observaciones)

## ALTO

### A-1. `decide()` no cumple RN6/A3: una anulación pendiente cuyo monto quedó MENOR que el VAR se puede aprobar
`app/Modules/AdminCont/Services/CreditNoteService.php:538-547`.
La spec (RN6, criterio A3, tarea P4) dice: "una anulación se aprueba solo si su monto sigue IGUAL al VAR vigente (excluyendo la propia nota)". El código solo bloquea `monto > acreditable`.
Escenario: factura 1.000; descuento de 300 en `pendiente_aprobacion`; se registra la anulación (700, pendiente). Aprobador rechaza el descuento: el VAR sin la anulación pasa a 1.000. Aprobador aprueba la anulación de 700: pasa la revalidación (700 <= 1.000), `applyToBalance` aplica 700 y queda una "Anulación completa" aprobada que deja 300 sin anular, que es justo la "anulación a medias" que RN6 dice evitar.
Ningún test cubre A3 (hay tests de "nota excedida" y de aprobación normal, ninguno de "rechazo de la nota previa y luego aprobación de la anulación desactualizada").
Fix: para `tipo === anulacion_completa` y `! correccion_pendiente_aprobacion`, exigir `abs(round($note->monto,2) - $acreditable) >= 0.005` -> 422 con mensaje "Recházala y regístrala de nuevo" (el mismo texto, ajustando "supera" por "ya no coincide con"). Añadir test A3 (descuento pendiente + anulación pendiente, rechazar el descuento, aprobar la anulación -> 422).

## MEDIO

### M-1. No hay test real de concurrencia (A4) ni de dos pendientes aprobadas en secuencia
`tests/Feature/AdminCont/CreditNoteSaldoAcreditableTest.php`. El diseño es correcto (ver "Verificado"), pero los tests solo ejercitan secuencias. Al menos añadir un test de `decide()` con dos notas pendientes sobre la misma factura (A=600 y B=500 forzadas sobre 1.000: aprobar A debe pasar si B <= restante, y B después debe fallar). Hoy el único test de decide con exceso usa una nota aplicada previa.

## BAJO

### B-1. P6/D3 (corrección de datos con notas previas) no es código nuevo: depende del guard de SCRUM-1009
`CreditNoteService.php:1250-1280` (`correccionContext`). Funciona (bloquea aplicadas y pendientes), pero: (a) el mensaje para una nota PENDIENTE distinta de corrección dice "ya tiene una corrección pendiente de aprobación", impreciso (es un descuento/devolución pendiente); (b) el test `test_p6_...` solo cubre `preview` con una nota aplicada y no asserta mensaje; no cubre `registerCorreccion` ni el caso pendiente. El commit P4 se titula "P4 y P6" aunque P6 no tocó código. Sugerido: un test adicional con nota pendiente + `registerCorreccion`, y ajustar el texto si se quiere cumplir C2 al pie de la letra.

### B-2. Dos notas heredadas pendientes que se excluyen mutuamente
`decide()` cuenta la otra pendiente al revalidar una; si ambas ya exceden juntas, ninguna es aprobable hasta rechazar una (comportamiento deseado por D1, coincide con la spec). Solo documentarlo en el runbook (P12).

### B-3. Consultas repetidas en el Form Request
`RegisterCreditNoteRequest.php` hace `AdminContInvoice::find` en `rules()` y de nuevo en `validarValorAcreditable()`, y `valorAcreditableRestante` + `notasPrevias` suman dos veces en `register()` (líneas 259-260) y en el mensaje de exceso. Son consultas por clave/índice, sin N+1 ni riesgo; optimización opcional (calcular `notasPrevias` una vez y derivar el VAR).

### B-4. Hueco preexistente (no regresión): corrección pendiente + nota nueva
Una corrección pendiente cuyo monto (desglose de la cotización) sea menor que el de la factura deja VAR > 0 y admite una nota nueva; al aprobar la corrección la factura se anula con esa nota colgando. Antes del fix era peor (sin tope alguno); `decide()` salta la revalidación para correcciones. Fuera de alcance, anotar como seguimiento.

## Verificado sin hallazgos

1. Concurrencia en `register()`: la factura se bloquea con `lockForUpdate()` antes de calcular el VAR (líneas 247-260) y la suma es una consulta nueva dentro de la transacción (READ COMMITTED), así que el segundo registro, al obtener el bloqueo, ve la nota ya comprometida del primero. `decide()` bloquea nota y luego factura; `register()` solo la factura: no hay ciclo de bloqueos. Dos `decide()` sobre la misma factura se serializan por el bloqueo de factura.
2. Redondeo: `sum('monto')` + `round(...,2)` y comparaciones siempre con `round($a - $b, 2) > 0.0`; `max(0.0, ...)` evita negativos. Consistente con el resto del módulo.
3. Cobros: no entran al VAR (A1 y el caso nota previa + cobro tienen test); el excedente sigue calculándose contra el saldo por cobrar (RN5). Hay un `resultado ?? null` defensivo que da 422 claro si aparece excedente entre la validación y el bloqueo.
4. Form Request y servicio: misma regla, mismos métodos de mensaje (`mensajeSinSaldo`/`mensajeExceso`), mismo criterio de comparación.
5. N+1: `valoresAcreditables` hace 2 consultas para N facturas; `invoicesForClient` lo usa correctamente, con fallback al total si falta la clave. `devolucionDetail` limita `monto_sugerido` con `min`.
6. Migración: aditiva (solo índice), multi-tenant vía `TenantSchemaMigration`/`table()`, reversible (`dropIndex` con el nombre explícito); mismo patrón que otras migraciones del repo.
7. Frontend: monto enviado = VAR en anulación (test lo verifica con 700); `sinValorAcreditable`/`superaAcreditable` deshabilitan el envío; fallback `monto_acreditable ?? monto` para backend anterior (con test); `esAnulacionNuncaCobrada` (saldo === monto) sigue siendo coherente con el backend (con nota previa aplicada no se pide resultado, con solo pendientes tampoco, y con cobros se pide el excedente real); i18n es/en con las 3 claves; sin emojis. El servidor sigue siendo la autoridad.
8. Reglas del proyecto: sin umbrales hardcodeados nuevos (el umbral sigue en `primaryApprovalThreshold()`), commits en español con formato `tipo(scope)`. Los 2 tests existentes modificados (`AdminContCreditNoteControllerTest`) cambian una nota de 1.000/400 sobre factura de 300 por 300: justificado por D2, no oculta nada.

## Cierre
Corregir A-1 (con su test A3), repetir los gates y volver a revisar solo `decide()`. M-1 recomendado en el mismo cambio.

---

## Re-revisión (commit f3b9640b, 2026-10-07) — solo lectura, sin ejecutar tests

Alcance: `CreditNoteService::decide()` (líneas ~537-554), `CreditableBalanceService` y los tests A3/M-1/B-1.

### Verificación punto por punto
1. **A-1 cerrado.** Con descuento pendiente 300 + anulación 700 sobre 1.000: si se rechaza el descuento, `valorAcreditableRestante(factura, id_anulación)` da 1.000 y la anulación de 700 ya no coincide, 422 y la nota queda pendiente. Si en cambio se aprueba el descuento (cuenta como aplicada, sigue dando 700 sin la propia nota), la anulación de 700 coincide y se aprueba. Si se aprueba primero la anulación (el descuento pendiente se resta, 700), pasa y anula la factura; el descuento posterior falla por "factura ya no vigente". Sin escenario que deje una "anulación completa" parcial.
2. **Aprobaciones legítimas intactas.** Anulación sin otras notas: monto = factura = acreditable, pasa. Con nota previa aplicada: el monto registrado ya era el VAR, coincide. Correcciones (`correccion_pendiente_aprobacion`) siguen fuera del bloque. Factura cobrada con excedente: la validación es solo contra el valor de la factura (no mira cobros); el excedente sigue resolviéndose en `applyToBalance`.
3. **Redondeo.** `notasPrevias` redondea a 2 decimales, `valorAcreditableRestante` también y la comparación es `abs(round(a-b,2)) > 0.0`: el ruido de coma flotante (~1e-13) redondea a 0.0, sin falsos positivos. `max(0.0, ...)` evita negativos.
4. **Tests.** A3 reproduce exactamente el escenario del informe y verifica 422, el texto "ya no coincide", "USD 1,000.00", que la nota sigue pendiente y que `saldo_aplicado` no cambió: válido. M-1 prueba de verdad la exclusión de la propia nota (a falla por 600+600, tras rechazar b pasa) y que el estado final es aplicada: válido. B-1 cubre el bloqueo de corrección con otra nota pendiente, pero solo afirma el status 422 (ver B-1 abajo).
5. **Mensaje 422.** Claro y accionable: "su monto USD 700.00 ya no coincide con el valor que aún se puede acreditar de la factura X (USD 1,000.00), porque cambiaron las notas de crédito de esa factura. Recházala y regístrala de nuevo por el valor correcto." Para descuentos/devoluciones usa "supera".

### Hallazgos
- **CRÍTICO / ALTO / MEDIO:** ninguno.
- **BAJO B-1** (`tests/Feature/AdminCont/CreditNoteSaldoAcreditableTest.php`, `test_b1_...`): solo comprueba `assertStatus(422)` en el preview; un 422 por otra validación (campo faltante) también lo haría pasar. Fix opcional: afirmar el mensaje o la clave de error del bloqueo por nota pendiente.
- **BAJO B-2** (observación, sin acción): una anulación completa registrada antes del fix que quedó por menos del valor vigente ahora solo se puede rechazar; es el comportamiento deseado, lo explica el mensaje.

### Veredicto: APROBADO CON OBSERVACIONES
A-1 y M-1 cerrados; B-1 (BAJO) opcional. Pendiente del gate habitual: suite completa + PHPStan antes del push (no ejecutados en esta revisión).
