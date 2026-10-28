# Senior Review — SCRUM-929 B3b (consumidores de importes derivados)

Revisor: Senior Reviewer. Fecha: 2026-09-19.
Alcance: **solo B3b** (4 puntos: Libro de Facturas/Ventas por medio de pago, Comisiones, mapper de
ítems de facturación electrónica, ajuste de ITBMS en el guard/cierre). No se re-revisó B1a/B1b/B2/B3a
en profundidad — se confía en los veredictos previos salvo lo que se señala abajo.

## Dónde vive el código revisado

El árbol de trabajo del proyecto (`.claude/worktrees/agent-a30afef05b89bb22f`) estaba limpio y en
`a54c548` — el trabajo de B1b+B2+B3a+B3b **no estaba en el working tree**, sino en un commit suelto
`refs/wip/scrum-929-20260919-b3b-completo` (`c5cebd1`, "no es una rama, no se empuja"). Para revisar
el código y correr la suite/PHPStan sin tocar el working tree que se me pidió no tocar (`git checkout`/
`reset`/`clean` prohibidos), armé un **worktree temporal separado** (`git worktree add
.claude/worktrees/review-scrum929-b3b refs/wip/scrum-929-20260919-b3b-completo`), levanté un stack
Docker aislado (`COMPOSE_PROJECT_NAME=reviewb3b`, sin publicar el puerto de Postgres al host para no
chocar con el stack compartido `infra-*` ya corriendo), migré y sembré un tenant nuevo, y ahí corrí
todo. Al terminar, bajé el stack (`docker compose down -v`) y borré el worktree temporal
(`git worktree remove --force`) — el stack `infra-*` compartido quedó intacto durante y después de
toda la revisión (verificado antes/después).

## Estado base verificado

- `infra/test.sh` (suite completa, sin filtro): **OK — 3590 tests, 15218 assertions**, coincide
  exacto con lo que reporta el ADR.
- `vendor/bin/phpstan analyse --memory-limit=1G` (Level 8): **[OK] No errors**.

Ambos corridos sobre el snapshot COMPLETO (B1b+B2+B3a+B3b), no sobre un subset.

## Punto 1 — Libro de Facturas / Ventas por medio de pago

`InvoiceTaxBreakdown::of()` (nuevo, `app/Modules/AdminCont/Services/Billing/InvoiceTaxBreakdown.php`)
centraliza la fuente base/ITBMS de una factura: `invoice_lines` si las tiene, si no `Quote` (legadas),
si no división por tasa. `InvoiceBookService::facturaRows()` y `PaymentMethodSalesReportService::report()`
lo adoptan igual, cargando `lines` en lote y sólo trayendo `Quote` para las facturas SIN líneas (evita
N+1 innecesario). Verificado con los tests `test_b3b_libro_de_facturas_*`: 3 parciales suman 900/63 (no
2700/189 por triplicar), parcial+completo no duplica, entrega única sin cambio, corrección por fecha
conserva base/ITBMS de sus líneas. Correcto, sin hallazgos.

Pendiente no bloqueante (ya declarado en el ADR, lo confirmo razonable dejarlo fuera): el export a
Excel de ambos reportes no se verificó a mano con datos de una familia parcial — los tests de export
existentes siguen sobre facturas legadas sin `invoice_lines`. No es un hallazgo de esta review (el
código de `InvoiceTaxBreakdown` es el mismo consumido por ambos exports), pero dejo la nota para QA.

## Punto 2 — Comisiones (`ArchitectCommissionService`, `CommissionOrderService`)

El `leftJoin` directo contra `invoices` se reemplaza por `leftJoinSub` agregando por `family_root_id`
(`sum(monto)`, `sum(saldo_aplicado)`) en ambos servicios — evita el fan-out de antes (parcial + completo
en la raíz duplicaba `total_facturado`/`monto_proyecto` en cualquier suma aguas abajo). `estadoFor()` de
ambos gana el parámetro `$montoProyecto`/`$totalPedidoCompleto` y exige `total_facturado + 0.005 >=
monto_total` antes de generar/estimar la comisión — cierra el bug real (proyecto de 963 con solo la
entrega 1 facturada y cobrada generaba la comisión completa). Las subqueries correlacionadas de
`fecha_cobro_completo`/`factura_numero`/`total_nota_credito` se reescribieron contra `family_root_id`
en vez de `order_id` puntual — correcto.

Sin hallazgos. El único punto a vigilar (no bloqueante): `comisionBase()` en `CommissionOrderService`
sigue usando `total_cobrado` para el estado no-pendiente — como `total_cobrado` es la suma de
`saldo_aplicado` de las facturas activas (que ya reflejan cualquier `ajuste_itbms_delta` aplicado vía el
`monto` real de cada factura), la comisión interna paga sobre el monto REAL cobrado tras un ajuste de
ITBMS, no sobre `card->amount`. Es la interacción correcta y deseada con el Punto 4 (Σ facturado ya
ajustado), lo dejo anotado porque no hay test que cruce explícitamente "comisión interna + corrección de
ITBMS de una parcial" en la misma cadena — ver recomendación al final.

## Punto 3 — Mapper de facturación electrónica (`FiscalDocumentItemsBuilder`)

Alcance acotado a los ítems, confirmado razonable: no hay ningún `new FiscalDocument(...)` fuera de
tests de esta capa en todo el código, así que ensamblar el documento fiscal completo (emisor/receptor/
pagos/PAC) sigue siendo trabajo sin ticket, fuera de SCRUM-929 — no es "dejar algo peligroso a medio
construir", es simplemente no tocar una capa que hoy no tiene ningún caller real.

`precioUnitario` recalculado sin redondear desde `subtotal/cantidad` (nunca el `precio_unitario`
persistido, que sí está redondeado) — correcto para que `cantidad × precioUnitario == subtotal` al
centavo contra el PAC. Tasa "3.5% retención" sigue sin código DGI y lanza `UnmappableItbmsRateException`
tal como se documenta — el caller decide, el builder no la traga en silencio. Sin hallazgos.

Hallazgo de higiene de docs (BAJO, ya señalado en el ADR, lo confirmo): varios docblocks de esta capa
citan `docs/architecture/facturacion-electronica-pac-diseno.md`, que no existe en el repo ni en el
historial de git — deuda de Tech Writer, no bloqueante.

## Punto 4 — Ajuste de ITBMS en el guard/cierre (`ajuste_itbms_delta`)

Este es el punto de mayor riesgo (toca el guard central de dinero) y donde encontré el único hallazgo
real de esta revisión.

### Mecanismo verificado como correcto

- **Acumulación a través de correcciones encadenadas**: `executeCorreccion()` persiste
  `ajuste_itbms_delta_nuevo = ajuste_itbms_delta_original + (note.monto − facturaOrigen.monto)`. Tracé
  esto a mano para una cadena de 3 correcciones (7% → 3.5% → 0%) sobre la misma entrega: el delta
  acumulado telescópico da exactamente `monto_final − monto_original` en cada paso, y `context()` suma
  el delta SOLO de las facturas activas de la familia (no depende de "heredar" entre facturas hermanas)
  — la agregación a nivel de familia es lo que hace innecesaria cualquier propagación cruzada entre una
  `parcial` corregida y un `completo` emitido después: cada factura activa aporta su propio delta
  acumulado y la suma da el ajuste total del pedido. No encontré ningún camino donde el delta se
  "pierda" al emitir un `completo` que cubre el resto — la pregunta que plantea el ADR sobre esto queda
  respondida por el diseño (agregación por familia, no por "factura que carga el delta de las demás").
- **Auto-consistencia con el pedido ya cerrado**: si el pedido ya está 100% facturado (Σ activas ==
  total ajustado) y se corrige una de sus facturas activas, `total` y `facturado()` se mueven por el
  MISMO delta (`Δthis_step`) — verifiqué esto algebraicamente sobre `context()`/`build()`, la igualdad
  se preserva, `restante()` sigue en 0 después de la corrección. No hay drift.
- **Test único de extremo a extremo** (`test_b3b_corregir_la_tasa_de_itbms_de_una_parcial_ajusta_el_total...`):
  familia de 963 (3×321 @ 7%), corregir la entrega 2 a 3.5% (delta −10.50, monto 310.50), luego facturar
  las otras 2 entregas — la que cierra cobra exactamente 321.00 (no 331.50, no recobra en silencio), sin
  advertencias, Σ final 952.50. Re-derivé el cálculo de `cerrarPedido()` a mano (prevBase/prevItbms,
  dBase/dItbms con el objetivo `Quote::itbms() + ajusteItbms`) y da 0/0 — coincide con el test.

### 🔴 Hallazgo — CRÍTICO/ALTO: `decide()` no revalida que la factura a corregir siga activa antes de ejecutar la corrección diferida

**Verificado con una reproducción real** (no es una sospecha teórica), agregué temporalmente un test de
integración sobre el snapshot y lo corrí contra el stack aislado (test descartado, no forma parte del
código revisado ni se dejó en ningún branch):

1. Familia de 3 entregas, se factura la entrega 2 como `parcial` (F-0001, 321.00).
2. Con `primary_approval_threshold = 0` (fuerza que TODA corrección de datos quede `pendiente_aprobacion`,
   comportamiento normal cuando el monto de la corrección supera el umbral configurado — con el default
   real de 5000.00 esto pasa igual en pedidos más grandes), se registra una Corrección de datos #1
   (motivo ITBMS, 7%→3.5%) sobre F-0001. Queda `pendiente_aprobacion`; F-0001 **sigue activa**
   (`anulada_at` null) porque `executeCorreccion()` todavía no corrió — se difiere hasta que Aprobador
   apruebe.
3. **Antes de que Aprobador decida sobre la corrección #1**, se registra una Corrección de datos #2 (motivo
   fecha) contra la MISMA `factura_origen_id` (F-0001) — el request lo permite: `correccionContext()`
   sólo exige `whereNull('anulada_at')`, y F-0001 todavía lo está. Queda también `pendiente_aprobacion`.
   No hay ningún guard (ni en `PreviewCreditNoteCorreccionRequest`, ni en `correccionContext()`, ni en
   `registerCorreccion()`) que impida registrar una segunda corrección contra una factura que ya tiene
   una corrección pendiente de aprobación.
4. Aprobador aprueba la corrección #1 (`PUT /notas-credito/{id}/decision`, `approve:true`) → **200 OK**.
   `executeCorreccion()` anula F-0001 y crea F-0002 (reemplazo, `order_id=2`, `tipo=parcial`) — ahora es
   la única activa con `(order_id=2, tipo=parcial)`.
5. Aprobador aprueba la corrección #2 (misma ruta, mismo `note.factura_origen_id` = F-0001, que ya está
   anulada) → **HTTP 500** (`Server Error`). El log real:
   ```
   SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint
   "invoices_order_id_tipo_active_unique"
   DETAIL: Key (order_id, tipo)=(2, parcial) already exists.
   ```
   `decide()` (`CreditNoteService.php:363-409`) hace `AdminContInvoice::query()->lockForUpdate()->
   findOrFail($note->factura_origen_id)` **sin filtrar `whereNull('anulada_at')`** — encuentra F-0001
   igual (anulada, pero existe), y llama `executeCorreccion()` sin volver a preguntar "¿esta factura
   sigue siendo la activa de su `(order_id, tipo)`?". `executeCorreccion()` intenta crear OTRO reemplazo
   con el mismo `order_id`/`tipo` que F-0002 (ya activa) y la BD rechaza el INSERT.

**Efecto real**: la transacción de `decide()` hace rollback (gracias al `DB::transaction()` — no hay
doble cobro ni dato corrupto, la integridad de dinero la protege el índice único), pero:
- Aprobador recibe un 500 sin mensaje útil ("Server Error" genérico, no una `CreditNoteStateException` en
  español como el resto de este mismo método).
- La nota #2 queda **permanentemente** en `pendiente_aprobacion`: cualquier reintento de aprobarla vuelve
  a chocar con el MISMO índice, siempre — no es una condición transitoria de carrera, es determinístico
  una vez que la corrección #1 ya se aprobó. La única salida es **rechazarla** (`approve:false`), perder
  esa corrección, y que alguien la vuelva a registrar contra la factura realmente activa (F-0002).

**Por qué es relevante para B3b específicamente** (no solo un gap heredado de B3a): antes de la decisión
de Luis del Punto 4, corregir la TASA de ITBMS de una `parcial` estaba bloqueada con 422
(`MSG_CORRECCION_TASA_PARCIAL`) — la única corrección permitida sobre una parcial era por FECHA. Ahora
que el Punto 4 desbloquea correcciones de ITBMS sobre parciales (el caso de uso central del punto), el
volumen de correcciones sobre facturas parciales va a subir, y con eso la probabilidad de que Contabilidad/
Asistente registren una segunda corrección (p. ej. "me equivoqué también en la fecha") antes de que Aprobador
revise la primera dentro de este mismo flujo recién habilitado.

**Severidad**: ALTO (no CRÍTICO — el índice único evita cualquier duplicado de dinero real; la app no
llega a facturar dos veces por el mismo delta). Pero es un defecto real y reproducible, no un caso de
carrera de milisegundos: cualquier segunda corrección registrada mientras la primera está pendiente
queda con una aprobación rota permanentemente, con un 500 genérico en vez de un mensaje de dominio.

**Recomendación** (para el Arquitecto, no lo corregí yo): agregar el guard más simple posible antes de
generalizar — en `correccionContext()` (o en el Form Request), rechazar con 422 si ya existe una
`AdminContCreditNote` con `factura_origen_id = esa factura` y `estado = pendiente_aprobacion`
(`correccion_pendiente_aprobacion = true`) sin resolver. Alternativamente/además, `decide()` debería
re-verificar `facturaOrigen->anulada_at === null` antes de llamar `executeCorreccion()` y lanzar una
`CreditNoteStateException` legible ("La factura de origen de esta corrección ya fue reemplazada por otra
corrección — rechace esta nota y regístrela de nuevo contra la factura vigente.") en vez de dejar
propagar el `QueryException`. Cualquiera de las dos cierra el hueco; la primera además evita que Aprobador
llegue a acumular notas condenadas a fallar.

### Otros puntos menores del Punto 4

- 🟡 **Sugerencia (BAJO)**: el docblock de `cerrarPedido()` (línea ~391-393) sigue diciendo "el total
  facturado siempre es `card->amount − Σ previas`" — desactualizado desde que `$monto = $restante` pasó
  a derivarse de `ctx.total` (que ya incluye `ajusteItbms`), no de `card->amount` a secas. El código es
  correcto (verificado arriba); solo el comentario quedó de una versión anterior. No bloqueante.
- 🟡 **Sugerencia (BAJO)**: no hay ningún test que encadene 2-3 correcciones sucesivas de ITBMS sobre la
  MISMA entrega (el único test de Punto 4 aplica una sola corrección). Mi verificación algebraica dice
  que la acumulación telescópica es correcta, pero un test real que lo confirme (p. ej. corregir
  7%→3.5%→0% y verificar Σ final) cerraría la pregunta que el propio ADR deja abierta ("¿se acumula bien
  a través de 3+ correcciones?") con evidencia ejecutada, no solo con derivación manual de un revisor.

## Verificación cruzada con el resto del sistema

No re-audité los ~22 call sites de `AdminContInvoice`/`forOrder()` (eso es B1b/B2/B3a, ya revisado).
Sí verifiqué que Punto 2 y Punto 1 no reintroducen ningún `keyBy('order_id')`/`->first()` ciego sobre
facturas — ambos usan agregación (`family_root_id`) o `lines` de la factura puntual, consistente con la
regla central de B3a.

## Veredicto

**Cambios necesarios antes de Pre-QA** — un hallazgo ALTO real y reproducido (no especulativo) en el
mecanismo de aprobación diferida de correcciones (`CreditNoteService::decide()`), expuesto con más
probabilidad de ocurrir justamente por lo que el Punto 4 habilita (corregir ITBMS sobre parciales). Los
Puntos 1, 2 y 3 están limpios — aprobados sin condiciones. El mecanismo de acumulación del delta en sí
(el corazón del Punto 4) es correcto, verificado tanto algebraicamente como con la suite existente.

No se requiere volver a tocar Puntos 1-3. El fix del hallazgo ALTO es acotado (un guard en
`correccionContext()`/`registerCorreccion()`, o una revalidación en `decide()` antes de
`executeCorreccion()`) — no exige rediseñar el mecanismo de `ajuste_itbms_delta`.

### Resumen de hallazgos

| # | Severidad | Punto | Descripción | Estado |
|---|---|---|---|---|
| 1 | 🔴 ALTO | 4 | `decide()` no revalida `anulada_at` antes de `executeCorreccion()` — segunda corrección pendiente sobre una factura ya reemplazada por otra corrección aprobada da 500 y queda permanentemente atascada | VERIFICADO (reproducido con test de integración real) |
| 2 | 🟡 BAJO | 4 | Docblock de `cerrarPedido()` desactualizado (sigue mencionando `card->amount` en vez del total ajustado) | VERIFICADO |
| 3 | 🟡 BAJO | 4 | Sin test de acumulación encadenada (3+ correcciones) — solo verificación manual/algebraica de este revisor | SOSPECHADO (matemáticamente resuelto, no ejecutado) |
| 4 | 🟢 — | 1/2/3 | Sin hallazgos | — |

**0 CRÍTICO, 1 ALTO, 0 MEDIO, 2 BAJO.**

## Cierre (2026-09-19, mismo día — Arquitecto)

Este reviewer trabajó sobre un snapshot (`refs/wip/scrum-929-20260919-b3b-completo`) tomado un poco ANTES
de que el Arquitecto cerrara la revisión de B3a en el árbol de trabajo real — el hallazgo #1 (ALTO) es la
MISMA causa raíz que "Senior Review B3a-1" (ver `scrum929-b3a-senior-review-20260919.md`, ya corregido con
el `whereNull('anulada_at')` en `decide()` antes de este despacho). Verificado que el árbol de trabajo
actual YA tenía ese fix al momento en que se dispara este review — el snapshot revisado, no.

Aun así, el punto 3 de la sugerencia de este reviewer (cortar en el REGISTRO, no solo en la aprobación)
era una mejora real y distinta que el fix de B3a-1 no cubría — implementada ahora en
`correccionContext()`: una segunda corrección no se admite mientras la primera sigue
`pendiente_aprobacion` sobre la misma factura (antes de tocar `order`/`quote`). El guard de `decide()`
(B3a-1) se mantiene como defensa en profundidad para lo que este nuevo guard no puede cubrir (condición de
carrera entre dos registros simultáneos, o datos preexistentes) — test dedicado que fuerza el escenario
saltándose el guard de registro a propósito (`test_b3b_aprobar_una_correccion_cuya_factura_de_origen_ya_fue_anulada_da_422_no_500`).

Hallazgo #2 (docblock desactualizado): corregido — `cerrarPedido()` y sus vecinos ya no dicen `card->amount`
a secas, dicen `ctx->total` (`card->amount + ajusteItbms`).

Hallazgo #3 (sin test de cadena 3+): ya existía en el árbol de trabajo al momento del despacho de este
review (`test_b3b_correcciones_encadenadas_acumulan_el_delta_de_itbms_correctamente`, familia de 963,
fecha → 7%→3.5% → 3.5%→7% de vuelta, verifica que el acumulado vuelve a 0 exacto) — el snapshot revisado
no lo tenía.

**Estado final: los 3 hallazgos de este review están cerrados.** Suite completa del backend tras los 3
fixes: 3594 tests / 15256 assertions, todos verdes; PHPStan L8 limpio.
