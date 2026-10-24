# Review: SCRUM-1008 — fechaEmision de la NC con hora real (9026 FE_ValidacionFechaReferenciada)
**Date**: 2026-09-30
**Reviewer**: Senior Reviewer
**Decision**: 🔄 Changes Required (un bloqueante menor, de tests; el código de producción se aprueba tal cual)

## Summary
El fix va a la causa raíz que documentó Pre-QA (`docs/pre-qa/scrum958-nc-sandbox-20260930.md`,
Hallazgo 1). La NC mandaba `credit_notes.fecha` (tipo `date`, sale a las 00:00) y la referencia
mandaba `invoices.created_at` con hora. Ahora `fechaEmision` = `credit_notes.created_at`, con piso
`referencia + 1 s`. Es chico, está bien ubicado (el builder es el único lugar que arma la fecha
fiscal, y el preview `fe-preview` usa el mismo builder, así que no se desalinea) y cumple el
criterio de aceptación 2 (`fechaEmision > referencia.fecha` siempre). El fix de producción se
aprueba. Se devuelve por un solo motivo: el alcance del ticket pide explícitamente una prueba de
regresión para **"corrección de datos el mismo día"**, y ese flujo (`registerCorreccion` →
certificación real) no tiene prueba. Además hay que registrar una decisión (queda abajo como
sugerencia): con `created_at`, una NC que Mark aprueba días después sale con una fecha de emisión
atrasada.

No volví a correr la suite: el que me despachó tiene una corrida completa de `infra/test.sh` en
curso, y 2 corridas en paralelo dan fallos falsos (`migrate:fresh` concurrente, ver memoria del
proyecto). Tomo como evidencia lo que reportó: PHPStan L8 del proyecto completo sin errores, 150
tests filtrados en verde, y mutación de 3/3 tests nuevos que fallan con el código viejo.

## Análisis adversarial: ¿`created_at`, `fecha` o `now()`?

**Recomendación: quedarse con `created_at` para este ticket, no cambiar a `now()`. Dejar
registrada la decisión sobre el camino de aprobación diferida (sugerencia 1).**

Argumento:
- **Simetría con la referencia.** `referencia.fecha` es `invoice.created_at`, que es exactamente
  la `fechaEmision` que se certificó para la factura (`paraFactura`, línea 45; el Pre-QA lo
  confirma: `referencia.fecha = 2026-09-29T21:08:51-05:00` = la emisión enviada). Si los dos lados
  salen del mismo tipo de columna (`timestamp(0)` sin tz, leída en `app.timezone`), la comparación
  que hace la DGI es coherente por construcción.
- **Determinismo.** Con `created_at`, el `fe-preview` (`FiscalDocumentPreviewService::paraNotaCredito`)
  muestra exactamente lo que se transmitió o lo que se transmitiría. Con `now()`, el preview cambia
  en cada request y deja de reflejar lo que ya se certificó.
- **Coherencia con la fecha contable** (el ticket pide decidir esto). `fecha` se fija con
  `now()->toDateString()` en la misma request que `created_at` (`CreditNoteService.php:290` y
  `:1005`), así que en el flujo inmediato la fecha fiscal y la contable caen el mismo día. La única
  excepción es el piso de +1 s cuando una NC se registra a las 23:59:59, en el mismo segundo que
  su factura. Eso es irrelevante en la práctica.
- **Por qué no `now()`.** Arreglaría el caso (a) de abajo, pero rompe el determinismo del preview
  y, para un reintento manual futuro (SCRUM-1007), cambia la fecha en cada intento sin que quede
  persistida. Si el caso (a) se confirma como problema real, la solución correcta es una columna
  `fe_emision_at` que se fija una sola vez en el primer intento de certificación (`now()`, con el
  mismo piso) y que leen tanto el builder como el preview. Eso es un cambio de modelo de datos:
  pasa por el Arquitecto y no entra en este ticket.

Casos:
- **(a) NC `pendiente_aprobacion` que se aprueba días después** (`decide()`, `CreditNoteService.php:440-491`).
  Es el único caso con riesgo real. La NC se certifica recién en la aprobación (`:490`), pero sale
  con `fechaEmision` = día del registro, días atrás. La factura de reemplazo de una corrección
  diferida sale con su propio `created_at` = momento de la aprobación (`executeCorreccion`,
  `:1051-1067`). Queda así: NC del día D anulando F, y F' del día D+n. No viola el orden que
  valida la 9026 (NC > factura referenciada), pero **no sabemos si la DGI/MSL tolera una
  `fechaEmision` atrasada varios días** respecto a la recepción. No hay evidencia en ningún
  sentido, y el sandbox no lo probó (Pre-QA, paso 16 segunda parte, "NO CUBIERTO POR EL TOPE").
  Ver sugerencia 1.
- **(b) Reintento manual futuro (SCRUM-1007).** Con `created_at`, el reintento reenvía la fecha
  original. Tiene el mismo riesgo de "fecha atrasada" que (a), y SCRUM-1007 tiene que decidirlo
  (es el mismo punto de diseño que la columna `fe_emision_at`). Ninguno de los dos casos bloquea
  este ticket.
- **(c) `fecha` distinta de la fecha de `created_at`.** Por código no pasa: ambas salen de `now()`
  en la misma request, y `fecha` no se acepta del cliente (REQ-485 RN3, `:290`). Solo una edición
  manual en BD o un backfill las separaría, y en ese caso `created_at` es igual de válida para lo
  fiscal.
- **(d) Zona horaria.** No encontré forma de que se invierta el orden. `invoices.created_at` y
  `credit_notes.created_at` son la misma clase de columna, se escriben y se leen con el mismo
  `app.timezone`, y la sesión de Postgres está sincronizada (`config/database.php:109`).
  `DateTimeImmutable` compara instantes absolutos, no strings, así que el offset no afecta el `<`.
  Panamá no tiene horario de verano, y la medianoche no importa porque ya no se trunca a fecha. El
  único riesgo sería que `APP_TIMEZONE` fuera distinto entre la request que creó la factura y la
  que creó la NC (un cambio de config entre las dos), y ese riesgo ya existía antes de este fix.
- **(e) Factura de reemplazo y NC de anulación.** La NC de corrección referencia a la factura
  ORIGINAL (`factura_origen_id`), no a la de reemplazo, así que el orden relevante es NC > F
  original, y se cumple. La factura de reemplazo no tiene `referencia` (`paraFactura`), así que
  este fix no la toca. El Hallazgo 2 del Pre-QA (saldo B606) es otro problema, de otro ticket.

## Salvaguarda `max(created_at, referencia + 1 s)`
- **¿Puede dar una fecha en el futuro?** Solo en 2 escenarios. (i) La NC se crea en el mismo
  segundo que la factura y se certifica en ese mismo segundo: la emisión queda como máximo 1 s en
  el futuro. Pasa en tests automatizados, pero no con una persona registrando la NC en la UI.
  (ii) `invoice.created_at` ya está en el futuro por un reloj desfasado, y eso ya estaba mal antes.
  No hace falta acotarla contra `now()`: acotarla volvería a producir `emision <= referencia`, que
  es justamente el rechazo 9026 garantizado. Prefiero un posible "1 s en el futuro" a un rechazo
  seguro.
- **¿El +1 s es un umbral de negocio?** No. Es técnico: es la resolución de la columna
  (`timestamp(0)`) y del formato `DATE_ATOM` que se manda a MSL (`MslJsonMapper.php:32`, `:62`).
  El mínimo incremento que hace estricta la desigualdad es 1 s. Nadie de negocio lo ajustaría, así
  que parametrizarlo en tabla sería ruido. No aplica la regla global de "no hardcodear umbrales de
  negocio".

## Otros usos de `credit_notes.fecha` (grep)
- `MslJsonMapper` y `NucJsonMapper` consumen `FiscalDocument::fechaEmision` y no leen el modelo:
  quedan cubiertos por el fix, incluido Digifact (`NucJsonMapper.php:72`).
- `PollMslDocumentEstadoJob` no reconstruye el documento: consulta por `numeroFiscal`, así que no
  hay inconsistencia.
- `fe-preview` de la NC (`AdminContCreditNoteController.php:264` → `FiscalDocumentPreviewService.php:47`)
  pasa por el mismo builder, así que queda consistente.
- PDF de la NC (`credit-note-pdf.blade.php:77`), listados, detalle y `AccountStatementService`
  siguen mostrando `fecha` o `fecha_decision` (fecha contable, sin hora). Eso es correcto: son
  vistas contables, no el documento fiscal. En el flujo inmediato coinciden en el día. En el flujo
  diferido el PDF muestra el día de registro y el estado de cuenta muestra `fecha_decision`, lo
  mismo que antes del fix.

## Findings

### 🔴 Blockers (must fix)
1. **`tests/Feature/AdminCont/FacturacionElectronica/CertifyCreditNoteTest.php` (ya existe
   `test_correccion_de_datos_certifica_la_nota_y_la_factura_de_reemplazo`, ~línea 220)**: falta la
   prueba de regresión de "corrección de datos el mismo día", que el alcance de SCRUM-1008 lista
   explícitamente. Los 3 tests nuevos prueban el builder aislado con NCs creadas a mano (`create()`
   directo). Ninguno pasa por `registerCorreccion()` → `certificarSiCorresponde()` →
   `FiscalCertificationService`, que es el flujo real que rechazó la DGI y el más común ("corregir
   apenas se factura").
   Sugerencia: un test con `Carbon::setTestNow` (factura a las 10:00, corrección a las 10:05 del
   mismo día), con el `Http::fake` que ya existe en ese archivo, y `Http::assertSent` sobre el
   request a `/notas-credito` que verifique que `$r['fechaEmision'] > $r['referencia']['fecha']` y
   que la hora de `fechaEmision` no es `00:00:00`. Cuesta unos minutos, y es la única prueba que
   rompería si alguien vuelve a leer `fecha` en otra capa (por ejemplo en un mapper).

### 🟡 Suggestions (non-blocking)
1. **`FiscalDocumentBuilder.php:106-109` (docblock) + comentario en Jira**: registrar la decisión
   del caso (a), aprobación diferida. El ticket pide "definir la regla". Propuesta: se acepta
   `created_at` en SCRUM-1008, y se consulta a MSL qué tolerancia de atraso admite la DGI en
   `fechaEmision`. Si hay un límite, se abre un ticket para `fe_emision_at` (columna fijada en el
   primer intento de certificación), que se resuelve junto con el reintento de SCRUM-1007. Agregar
   un test en `CertifyCreditNoteTest` para `decide()` 3 días después que documente el
   comportamiento elegido: hoy la NC sale con la fecha del registro.
2. **`FiscalDocumentBuilder.php:116`**: cuando actúa el piso (`$emision < $minimo`), loguear un
   `Log::warning` con ids y las dos fechas. En producción solo debería dispararse por un reloj
   desfasado o datos editados a mano, y hoy lo corrige en silencio.
3. **`FiscalDocumentBuilderNotaCreditoTest.php`, líneas ~98-144**: los `Carbon::setTestNow()` del
   final de cada test sobran pero no hacen daño. Si un assert falla antes, no hay fuga: el
   `tearDown` de Laravel ya resetea el reloj
   (`InteractsWithTestCaseLifecycle::tearDownTheTestEnvironment`, línea 156). Se pueden quitar o
   dejar. No hace falta moverlos a `tearDown`.
4. **Mismo archivo**: los `assertSame('...-05:00', ...)` dependen de que `app.timezone` sea
   `America/Panama` en el entorno de test. Hoy es el default de `config/app.php:69` y
   `phpunit.xml` no lo pisa, así que funciona. Si algún día se corre la suite con otro
   `APP_TIMEZONE`, esos 4 asserts se rompen aunque el fix esté bien. Opcional: construir el
   esperado con `config('app.timezone')`, o apoyarse solo en `assertGreaterThan` más la hora local.
5. **`FiscalDocumentBuilder.php:113`**: `new DateTimeImmutable($x->toIso8601String())` es el
   patrón ya usado en `:45` y `:79`. `->toDateTimeImmutable()` de Carbon evitaría el round-trip por
   string (y conservaría microsegundos, aunque la columna no los tiene). Es cosmético, no tocarlo
   en este ticket.

### 🟢 Well done
- Diagnóstico y fix en la raíz, sin parchear el mapper: todos los PAC (MSL y Digifact) y el
  preview heredan el arreglo desde un solo punto.
- `$fechaReferencia` se calcula una vez y se reusa para la referencia y para el piso, así que no
  hay 2 lecturas que puedan divergir.
- El docblock explica el porqué con la evidencia del Pre-QA (NC-0009 vs NC-0011).
- Los tests cubren mismo día, día siguiente (cruzando medianoche), mismo segundo y la NC registrada
  "antes" de la factura. Esos son los 3 casos del criterio 2 del ticket, y la mutación contra el
  código viejo confirma que prueban comportamiento.
- PHPStan L8 limpio y firma del método privado tipada completa.

## Next steps
Backend Dev agrega el test de corrección de datos del mismo día (blocker 1) y vuelve a pasar por
review (re-check acotado a ese test). Después: comentar en SCRUM-1008 la decisión de la
sugerencia 1, y Pre-QA verifica el criterio 1 en sandbox con un solo documento (NC del mismo día
que su factura). Commit sugerido:
`fix(admin-contab): fechaEmision de la NC con hora real de registro, posterior a la factura referenciada (SCRUM-1008)`.

---

## Re-review (2026-09-30) — solo el diff de correcciones
**Decision**: ✅ Approved — listo para Pre-QA (criterio 1: una NC del mismo día en sandbox, un documento).

- 🔴 1 **resuelto**: `CertifyCreditNoteTest::test_correccion_de_datos_el_mismo_dia_manda_la_nc_con_emision_posterior_a_la_factura`
  recorre el flujo real (`POST /notas-credito/correccion` → `registerCorreccion()` → certificación →
  request a MSL) con reloj congelado (10:00 factura / 10:05 corrección) y `Http::assertSent` sobre
  `/v1/notas-credito` exige la emisión exacta, `> referencia.fecha` y no a las 00:00. El predicado no
  puede pasar vacío: si no se envía la NC, `assertSent` falla. La mutación contra el código viejo la
  reportó el dev.
- 🟡 1 **aplicada**: el docblock de `FiscalDocumentBuilder::fechaEmisionNotaCredito` registra la decisión
  (aprobación diferida = fecha de registro, `fe_emision_at` + SCRUM-1007 si MSL confirma un límite,
  por qué no `now()`). La consulta a MSL y el test de `decide()` a 3 días quedan como seguimiento
  fuera del ticket. Es aceptable, pero tiene que quedar anotado en el comentario de Jira de
  SCRUM-1008/1007 para que no se pierda.
- 🟡 2 **aplicada**: el `Log::warning` del piso solo lleva el id de la nota y las 2 fechas (sin PII) y
  se verifica con `Log::spy`. Además la rama se reescribió a early-return (`>=`), que es equivalente
  a la anterior.
- 🟡 3-5 no aplicadas: de acuerdo, eran cosméticas.

Sin hallazgos nuevos. Evidencia del dev: suite completa 4076/0 antes del último cambio; después,
16/16 en las 2 clases tocadas y PHPStan L8 limpio. El delta posterior es 1 test, el docblock y 1 log,
sin cambio de lógica, así que el riesgo de regresión cruzada es despreciable. De todas formas la
regla del proyecto pide la suite completa antes del push.
