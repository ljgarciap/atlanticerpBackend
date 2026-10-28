# Senior Review — SCRUM-935 / SCRUM-928 / SCRUM-934 (Batch A) + B1a de SCRUM-929

**Fecha:** 2026-09-18
**Revisor:** Senior Reviewer (revisión independiente y adversarial, solo lectura)
**Alcance:** árbol de trabajo sin commitear en `atlanticerp-backend` y `atlanticerp-frontend` (rama `dev`, 0
commits sobre `origin/dev`). Dos cosas mezcladas: (1) Batch A — SCRUM-935 (Excel de 4 reportes),
SCRUM-928 (DV del RUC, DV obligatorio en clientes/subclientes, cuenta sin máscara), SCRUM-934
(indicadores del día en Arqueo de Caja); (2) B1a de SCRUM-929 (`order_items.quote_item_id`,
propagación en los 4 creadores de `OrderItem`, comando de backfill).
Vara de medir: criterios de aceptación literales de los 3 tickets en Jira, decisiones D-6/D-7 de
`docs/specs/scrum-928-935-correcciones-uat-admincont-analista-spec.md`, Batch A de
`docs/architecture/scrum-928-935-batches.md`, y ADR-SCRUM929 (§1 "Enmienda" + "Resultado del spike B0").

## Veredicto: APROBADO (0 CRÍTICO, 0 ALTO, 2 MEDIO no bloqueantes, 6 BAJO)

Los dos MEDIO no bloquean el paso a Pre-QA de Batch A ni de B1a, pero el MEDIO-1 (backfill) debe
resolverse o aceptarse explícitamente ANTES de ejecutar el comando fuera de `--dry-run` y antes de
que B2 (facturación parcial) dependa de `quote_item_id` en pedidos legados; el MEDIO-2 es un
prerrequisito de despliegue para que el criterio de aceptación de SCRUM-928.3 se cumpla en cada
entorno.

## Verificación ejecutada por este revisor

- PHPStan Level 8, proyecto completo: `[OK] No errors`.
- `npx tsc --noEmit` (frontend, proyecto completo incluyendo tests): sin errores.
- Tests dirigidos con `infra/test.sh --filter` (backfill de quote links, todos los `test_934`,
  `test_928`, seeder de número de cuenta): 30 tests, 113 assertions, OK, en aislamiento.
- Verificación puntual de una duda de calidad de test (ver BAJO-1): `assertEquals(0.0, null)` en
  PHPUnit 11 PASA (se ejecutó `IsEqual(0.0)->evaluate(null)` en el contenedor).
- La suite completa backend (3464) y vitest completo (1884) no se repitieron (ya en verde según el
  encargo).

## Hallazgos

### MEDIO-1 — El backfill de `quote_item_id` puede vincular al `QuoteItem` de OTRA versión de cotización (verificado en código; impacto en datos reales NO verificado)

`app/Console/Commands/BodegaBackfillOrderItemQuoteLinksCommand.php:82` resuelve la cotización con
`OrderService::resolveApprovedQuote()` (`OrderService.php:255`), que devuelve la cotización con
folio MÁS RECIENTE (`generated_at desc`) del `sales_project_id`, sin ninguna condición contra el
momento de creación del pedido. `QuoteService` mantiene varias cotizaciones/versiones por proyecto
(`versions` en `QuoteService.php` ~línea 500) y además reescribe `pipeline_cards.amount` en cada
generación. Si el proyecto tiene una versión posterior a la del pedido, el comando:
- vincula `quote_item_id` al ítem de la versión NUEVA cuando el producto aparece una sola vez
  (`matchingQuoteItems`, líneas 133-143: solo compara `catalog_product_id`/`reference`, no cantidad
  ni precio), y
- rellena `unit_price` con el precio de esa versión (mismo riesgo que ya tenía SCRUM-786, pero ahora
  amplificado porque `quote_item_id` será la llave de facturación de B2).

Consecuencia: un vínculo "único pero equivocado" pasa el filtro de "solo match único" y la
degradación segura del ADR ("ítem sin vínculo => solo facturación `completo`") NO se activa, porque
el ítem SÍ tiene vínculo. B2 calcularía `lineTotal(quoteItem) x qty_entrega / qty_cotizada` contra
el ítem equivocado. El test `test_omite_el_item_cuando_hay_2_quote_items_candidatos...` cubre
ambigüedad dentro de UNA cotización, pero ningún test cubre el caso de 2 versiones.
Cómo verificarlo: crear 2 `Quote` con folio en el mismo `sales_project_id` (el segundo con
`generated_at` posterior al `created_at` del `Order`, con distinta cantidad/precio del mismo
producto), un `OrderItem` sin vínculo, correr el comando: se vincula al ítem de la versión 2.
Sugerencia (barata y conservadora): restringir a cotizaciones con `generated_at <= order.created_at`
y/o exigir `quoteItem.quantity >= orderItem.qty_requested` (y, si el ítem no es derivado, igualdad
de cantidad y de `unit_price` cuando este no es nulo) antes de aceptar el match; dejar el resto sin
vínculo. Alternativa para pedidos con entrega parcial SCRUM-909: usar `PipelineCardProductDelivery`
(fuente autoritativa `quote_item_id` por fecha) en vez de adivinar.
Nota menor relacionada: el plan (`scrum-928-935-batches.md`, B1a) dice "dry-run por defecto",
pero el comando escribe por defecto y `--dry-run` es opt-in (igual que SCRUM-786). No es un bug,
pero conviene alinear el documento o el comando; en cualquier caso, la primera corrida en test/prod
debe ser con `--dry-run` y revisión humana de la salida.

### MEDIO-2 — Prerrequisito de despliegue: sin el número de cuenta cargado, la Factura y el Estado de Cuenta REGRESAN de "Banco General ****4554" a "—" (verificado en código)

`InvoiceService.php:1010-1031` (`paymentBlock()`) ya no cae al enmascarado (decisión D-6): con
`numero_cuenta_completo` vacío devuelve `cuenta_pago = null` y `numero_cuenta = null`, y las vistas
(`invoice-pdf.blade.php:123`, `account-statement-pdf.blade.php:81`, `AccountStatementExportService.php:61`,
`EstadoCuentaPage.tsx:282`) muestran "—". El único mecanismo automático para cargarlo es
`AdminContBankAccountNumberSeeder` vía `ADMIN_CONTAB_BANK_ACCOUNT_NUMBER`, que es no-op si la
variable no existe (comportamiento deliberado y correcto: no se inventa el dato). Por tanto, en
cualquier entorno donde el `.env` no tenga la variable y la cuenta activa no tenga el número cargado
a mano en Cuentas Bancarias, el criterio "el PDF muestra el número completo" de SCRUM-928.3 NO se
cumple y, peor, el PDF pierde información que hoy sí muestra (banco + últimos 4). Además el seeder
escribe en la cuenta activa más antigua (`oldest('id')`, coherente con `paymentBlock()`), que puede
no ser la cuenta a la que corresponde el número entregado si hay varias activas.
Acción: pedir el número a Luis/Gerencia1, ponerlo en el `.env` de dev/test/prod (o cargarlo desde
Cuentas Bancarias) ANTES de promover, y confirmar que la cuenta activa más antigua es la correcta.
Esto no es un defecto de código.

### BAJO-1 — `test_934_indicadores_parten_en_cero_si_solo_hubo_movimiento_otro_dia` usa `assertEquals(0.0, null)`, que pasa aunque el campo no exista (verificado)

`tests/Feature/AdminCont/AdminContCashPositionControllerTest.php` (test añadido, sección SCRUM-934):
`assertEquals(0.0, $response->json('total_facturado_dia'))`. Si el backend omitiera la clave,
`json()` devuelve `null` y PHPUnit considera `0.0 == null` igual (comprobado). El test sí detecta
una implementación sin filtro de fecha (devolvería 400/600), pero no una sin el campo; el primer
test de 934 (350.50 / 1500.00) cubre la existencia, así que el conjunto es suficiente. Usar
`assertSame(0.0, (float) ...)` o `assertArrayHasKey` haría el test autosuficiente.

### BAJO-2 — `test_934_close_congela_los_indicadores...` no distingue "snapshot con indicadores" de "reconstrucción legada" (verificado por razonamiento)

La factura/pedido "posterior" se crea con `created_at = now()+1 min`, es decir DESPUÉS de
`cerrado_at`. Si el snapshot no trajera los indicadores, `totalsFor()` los reconstruiría con
`$asOf = cerrado_at` (`DailyCashCountService.php:451-460`) y excluiría igualmente esa factura, con
el mismo resultado (100/300). El comportamiento de producción es correcto (`close()` guarda
`forDate()` completo, que ya incluye los indicadores), pero el test no lo prueba. Para probar el
congelamiento haría falta una factura con `created_at <= cerrado_at` insertada después del cierre.

### BAJO-3 — `.env.example`: la variable nueva quedó dentro de la sección "AI", separando el comentario de Anthropic de su clave

`.env.example:163-171`: el bloque `ADMIN_CONTAB_BANK_ACCOUNT_NUMBER` se insertó entre el comentario
"API key de Anthropic..." y `ANTHROPIC_API_KEY=`. Cosmético pero engañoso; moverlo a su propia
sección.

### BAJO-4 — Docblock huérfano en `ArqueoCajaPage.tsx`

`src/pages/admin-contab/ArqueoCajaPage.tsx` (~líneas 528-548): `DayIndicators` (con su JSDoc) se
insertó entre el JSDoc de `RetencionesSection` ("REQ-525 — cobros pagados con Retención...") y la
función `RetencionesSection`, dejando ese comentario colgando encima del docblock de SCRUM-934.
Mover `DayIndicators` antes del JSDoc de REQ-525.

### BAJO-5 — Documentación de diseño desactualizada

`docs/adr/ADR-SCRUM929-facturacion-parcial-o-completa.md` termina con "Estado: Implemented: no —
pendiente de spike y PM breakdown", pero el spike B0 está cerrado (sección del propio ADR) y B1a ya
está implementado. Actualizar a algo como "B1a implementado; B1b/B2 pendientes". El contenido del
ADR sobre B1a fue contrastado con el código y es correcto (ver "Verificado sin hallazgos").

### BAJO-6 — Specs Playwright de Pre-QA fechados se rompen con el DV obligatorio (no hay spec permanente afectado)

`atlanticerp-frontend/e2e/preqa-batch4-scrum112-113-122-146-20260730.spec.ts:250-251` llena solo el RUC del
mini-form de subcliente y espera `Confirmar` habilitado; ahora también exige DV, así que esa
aserción fallaría. Es un script de Pre-QA de un batch pasado (no un smoke permanente), pero avisar
si se re-ejecuta. Nota menor adicional: el regex `^\d{1,2}$` en PHP admite un `\n` final
(`"81\n"`); `TrimStrings` lo neutraliza en la práctica, sin impacto real (usar `\z` si se quiere
estricto).

## Verificado sin hallazgos

**SCRUM-935 (Excel)**
- Los 4 exports (mensual-cliente, acumulado — mismo servicio con agrupación `mes` —, libro de
  facturas, ventas por medio de pago) escriben números reales (`ExcelMoney::value()` = float
  redondeado a 2) con formato `$#,##0.00`; rangos de columnas revisados a mano (`dia`: C..I,
  `mes`: D..J, libro: G/I/J, ventas por medio de pago: D..última). "Núm." queda numérico sin formato
  de moneda; "%" del libro sigue texto. Hoja "Pendientes" de los 2 reportes que la tienen también
  formateada. Negativos (notas de crédito) se muestran como `-$x`.
- `strictNullComparison: true` en cada `fromArray` es correcto y necesario (sin él las celdas en 0.0
  saldrían vacías); las celdas de rótulo vacías pasaron de `''` a `null` de forma consistente.
- `media` retirada de servicio, `ClientCollectionRowResource`, totales, tipos y vista; no queda
  ningún otro consumidor en backend ni frontend (grep en `app/`, `resources/`, `routes/`,
  `src/`, `e2e/`). La suite comprueba encabezados exactos, tipo numérico, format code y ausencia de
  `media` en el JSON de ambos reportes. Los tests de frontend fallarían sin el cambio (la etiqueta
  `columnas.media` se renderizaba siempre).

**SCRUM-928**
- DV: `SubClient::$fillable`, los 3 FormRequest (`StoreClientRequest`, `StoreClientSubClientRequest`,
  `StoreSubClientRequest`) exigen `required|string|regex:/^\d{1,2}$/`; SOLO en alta (no en update,
  no retroactivo, acorde a D-7). Los 3 puntos de creación reales (`ClientsService::createClient`,
  `addSubClient`, `ClientController::storeSubClient`) persisten `tax_id_dv`; el resto de creadores de
  `SubClient` son comandos/seeders internos que no pasan por validación (sin impacto). Contrato
  backend-frontend: `tax_id_dv` en request y respuestas (`formatSubClient`, `subClients()`,
  `storeSubClient`), `SubClientRef`/`SubClientDetail`/payloads tipados; la columna ya existía
  (`2026_09_17_190000`, `string(4)`).
- Frontend: TODOS los llamadores cubiertos — `CreateClientModal` (Clientes y flujo de Cotización,
  ambos modos), mini-form de `ClientPicker` (`NewProjectModal`, `PipelineCardModal`) con DV
  obligatorio, filtrado a dígitos y `maxLength=2`, `ClientDetailModal` lo muestra y lo omite si es
  null. El DV va inmediatamente después del RUC. i18n es/en completos para las claves nuevas
  (`taxIdDv` x3, `subClientTaxIdDv`, `totalFacturadoDia`, `totalPasadoAPedidoDia`); sin emoji.
- `paymentBlock()`: todos los consumidores (`AccountStatementService`, `CreditNoteService` preview,
  PDF de factura, PDF/Excel de estado de cuenta, `InvoiceStatusModal`, `RevisionPreviaCorreccionModal`,
  `FacturacionPage`, `EstadoCuentaPage`) manejan `null`; `trim()` cubre valores en blanco.
- Seeders: `AdminContFiscalIdentitySeeder` (DV '81') rellena por campo solo si está vacío
  (idempotente, nunca pisa; el resto del PDF no cambia); `AdminContBankAccountNumberSeeder` es no-op
  sin variable, solo llena si `numero_cuenta_completo` está vacío, nunca toca cuentas inactivas ni ya
  cargadas, usa `config()` (compatible con config cacheada), `DatabaseSeeder` usa
  `WithoutModelEvents` (no genera fila de auditoría con el número), no loguea ni imprime el número.
  Ambos con tests de idempotencia y de "no pisa".

**SCRUM-934**
- Zona horaria/día: `whereDate('created_at', $date)` con sesión Postgres y app en America/Panama,
  mismo criterio que el resto de `forDate()`. Facturado = `invoices.monto` no anuladas creadas ese
  día; pasado a pedido = suma de `pipeline_cards.amount` de pedidos RAÍZ (`order_type=pedido`,
  `family_id IS NULL`), correcto porque `orders_pipeline_card_root_uq` garantiza 1 raíz por tarjeta
  (entregas parciales, Faltante y Revisión de Inventario no se cuentan dos veces). Informativos:
  no entran a `total_neto` (comprobado en test).
- Snapshot: `close()` congela `forDate()` completo (incluye los indicadores); para snapshots
  legados sin las claves, `totalsFor()` reconstruye con `$asOf = cerrado_at` (nunca en vivo); el
  `total_neto` de un arqueo cerrado no cambia. El PDF (`viewData()` hace spread de `active()`/
  `detail()`) muestra la sección debajo de los totales con datos del snapshot. Frontend oculta el
  bloque si el backend no envía los valores (no inventa $0.00) y lo reutiliza en el detalle del
  historial.

**B1a**
- Migración `2026_09_18_100000`: `TenantSchemaMigration` módulo `bodega`, columna nullable + índice
  con nombre explícito, `down()` simétrico (índice y columna); mismo patrón que la migración
  `2026_07_22_100009`. Referencia suelta sin FK (justificado en el docblock, `quote_items` vive en
  otro schema).
- Los 4 creadores de `OrderItem` son los únicos en `app/` (verificado con grep de `->items()->create`,
  `OrderItem::create/insert`, `replicate`, `DB::table('order_items')`): `OrderService::createItemsFromQuote`
  (`quote_item_id = $item->id`), `PickingService::createInventoryReviewCard`,
  `DeliveryService::createFaltanteCard` e `InventoryReviewService::splitFoundItemsIntoNewOrder`
  copian `quote_item_id` y `unit_price` del ítem padre; en el split el ítem original se borra y el
  vínculo/precio sobreviven (test dedicado). `quote_item_id` en `$fillable` no es alcanzable por
  ninguna ruta con input de usuario (todos los `update()` sobre `OrderItem` usan campos fijos).
  Nadie más consume `order_items.unit_price` salvo devoluciones/notas de crédito (mejora: los
  derivados dejan de valer $0.00).
- ADR contrastado con el código: `qty_delivered` se escribe solo en `DeliveryService.php:140`;
  `card->amount` se fija con `quote->grandTotal()` al generar la cotización; los 3 derivados no
  copiaban `unit_price` (confirmado en el diff).
- Backfill: solo toca filas con `quote_item_id IS NULL` (idempotente), omite con 0 o 2+ candidatos
  (incluido el caso de 2 ítems personalizados con la misma referencia/`null`), no pisa un
  `unit_price` existente, respeta `--dry-run`, falla limpio si el tenant no existe. (Ver MEDIO-1
  para la limitación de fondo.) Tests nuevos sin dependencia de orden ni de datos externos
  (`uniqueTestNumber()`, sin `random_int`).

**Reglas del proyecto:** sin umbrales de negocio hardcodeados nuevos; sin emoji ni iconografía
nueva; sin `random_int()` en fixtures nuevos; nombres y capas consistentes (controladores finos,
lógica en servicios); PHPStan Level 8 limpio.

## Nota fuera de alcance (preexistente, no atribuible a este cambio)

`src/i18n/locales/en/adminContab.json` no tiene `arqueoCaja.arqueoDelDia.totalComisiones` (sí existe
en `es`). Ya estaba así antes de este diff.

---

## Re-verificación de fixes

**Fecha:** 2026-09-18 (posterior al Senior Review original)
**Alcance:** delta empujado a `dev` — backend `66d079f..846d1cb`, frontend `c036355..657f6c0`. B1b
(facturación, en stash) fuera de alcance. Solo lectura; se abrió el código de cada fix.
**Verificación ejecutada:** PHPStan Level 8 proyecto completo `[OK] No errors`; `npx tsc --noEmit`
limpio; `infra/test.sh --filter` (backfill quote links + `test_934` + `ClientControllerTest`/
`ClientsControllerTest`): 25 tests, 104 assertions OK; `npx vitest run` de `CreateClientModal` y
`PipelineCardModal`: 71 tests OK.

### Veredicto del delta: APROBADO

Todos los hallazgos originales quedaron corregidos o aceptados; los dos fixes posteriores de
Pre-QA son correctos; 0 hallazgos nuevos de severidad CRÍTICO/ALTO/MEDIO (2 BAJO nuevos, abajo).

### Estado por hallazgo original

| # | Hallazgo | Estado | Evidencia |
|---|---|---|---|
| MEDIO-1 | Backfill vinculaba a la versión equivocada de la cotización | **CORREGIDO** | `BodegaBackfillOrderItemQuoteLinksCommand.php:135-146` (`quoteAsOfOrder`): cotización con folio más reciente cuyo `generated_at <= created_at` de la raíz de la familia (`:86-88`, `Order::familyRoot()`); `generated_at` nulo queda excluido (comparación falsa) => se omite. `:160` exige `qty_requested <= round(cantidad cotizada)`; `:162-168` exige precio efectivo dentro de ±0.01 si el ítem ya trae `unit_price`; `:100` solo vincula con exactamente 1 candidato. |
| MEDIO-2 | Prerrequisito de despliegue: número de cuenta | **ACEPTADO** (sin cambio de código, como se esperaba) | Sigue siendo acción de Luis/Gerencia1 (`ADMIN_CONTAB_BANK_ACCOUNT_NUMBER` o carga manual en Cuentas Bancarias) antes de promover; el Pre-QA lo registra como INFO-2 pendiente. `.env.example` ahora documenta el comportamiento ("Sin valor = no-op, los documentos muestran —"). Falta solo ejecutar la acción; recomiendo dejarla como ítem explícito del checklist de promoción a test/prod. |
| BAJO-1 | `assertEquals(0.0, null)` vacuo | **CORREGIDO** | `AdminContCashPositionControllerTest.php` (test `..._parten_en_cero_...`): `assertIsNumeric` sobre ambas claves + `assertEqualsWithDelta`. |
| BAJO-2 | Test de congelamiento no distinguía snapshot de reconstrucción | **CORREGIDO** | Mismo archivo, `test_934_close_congela_...`: lee `AdminContDailyCashCount::findOrFail($id)->snapshot` y afirma `assertArrayHasKey` de ambos indicadores y sus valores (100.00 / 300.00) sobre lo PERSISTIDO; sin las claves en el snapshot ese test falla. |
| BAJO-3 | `.env.example` mal ubicado | **CORREGIDO** | `.env.example:160-176`: `ANTHROPIC_API_KEY` conserva su comentario; la variable nueva quedó en su propia sección "Admin & Contabilidad" con la descripción completa. |
| BAJO-4 | JSDoc huérfano en `ArqueoCajaPage.tsx` | **CORREGIDO** | `ArqueoCajaPage.tsx:528-546` (`DayIndicators` con su propio JSDoc) y `:548` el JSDoc de REQ-525 directamente sobre `RetencionesSection`. |
| BAJO-5 | Estado del ADR desactualizado | **CORREGIDO** | `ADR-SCRUM929-...md:135-140`: "Parcial (2026-09-18). B0 cerrado; B1a implementado...; B1b–B5 pendientes", con la advertencia de que B2 debe reescribir los `test_876_*`. |
| BAJO-6 | Spec Pre-QA fechado y `\n` final del DV | **NO TOCADO, DE ACUERDO** | El spec `preqa-batch4-scrum112-113-122-146-20260730.spec.ts` es un script de Pre-QA de un batch pasado (no smoke permanente) contra dev.atlanticerp.ai; el `\n` final lo neutraliza `TrimStrings` (además `\d` sin `/u` no acepta dígitos no ASCII, y el nuevo spec e2e lo prueba con `'٣'`). Sin impacto real. |

### Sobre el backfill: ¿queda algún caso donde vincule al ítem equivocado?

Los 5 tests nuevos (precio distinto, cantidad excedida, versión vigente al crear el pedido — el
escenario de las 2 versiones —, ninguna cotización generada al crear el pedido, tarjeta derivada
usando la fecha de la raíz) me convencen. El de 2 versiones falla con la lógica anterior
(`resolveApprovedQuote` habría devuelto v2, cantidad 9 >= 4) y el de la tarjeta derivada demuestra
por qué se usa la raíz y no la tarjeta del ítem.

Razonamiento de solidez: un ítem original nace con `qty_requested` igual a la cantidad cotizada y
uno derivado con un valor menor o igual, así que el ítem de origen siempre pasa el filtro de
cantidad; por tanto, si tras los filtros queda un único candidato, es el de origen. La regla de
fecha replica la que usaba la creación real del pedido (cotización con folio más reciente vigente en
ese momento).

Residuales que NO considero hallazgo (todos degradan a "omitido", no a "vinculado mal"):
(1) una cotización regenerada EN LA MISMA FILA después del pedido cambia `generated_at` y queda
excluida => se omite; (2) si `PricingSettings` cambió desde el pedido, el precio efectivo de hoy no
coincide con el snapshot => se omite; (3) una edición de ítems de la cotización sin regenerar solo
queda cubierta por los filtros de cantidad/precio. El único camino teórico a un vínculo erróneo es
que el precio de origen "derive" hasta coincidir por casualidad con otro ítem del mismo producto que
además cumpla la cantidad; combinación improbable y acotada a datos legados, aceptable para un
comando de una sola corrida con `--dry-run` previo. La primera corrida en test/prod sigue debiendo
hacerse con `--dry-run` y revisión humana de la salida (ya documentado en el docblock y en el
comando).

### Fixes posteriores de Pre-QA (no revisados antes)

- **(a) `88e658e` — quitar `maxLength={2}` del DV: CORRECTO.** `CreateClientModal.tsx` y
  `ClientPicker.tsx` conservan `onChange={... .replace(/\D/g, '').slice(0, 2)}`, así que el límite de
  2 dígitos sigue aplicándose y un pegado con prefijo (`" 81"`, `"-81"`, `"DV 81"`) ya no se trunca
  antes de limpiar. Los tests de `CreateClientModal.test.tsx` usan `user-event` (que sí respeta
  `maxlength` al pegar, a diferencia de `fireEvent.change`) más una aserción explícita de ausencia del
  atributo; pasan en verde y fallarían al reintroducir el atributo. El backend igualmente valida
  `^\d{1,2}$`, así que no se debilitó ninguna garantía.
- **(b) `8690d90` — etiqueta `tax_id_dv` => "DV": CORRECTO.** `lang/es/validation.php` (dentro de
  `attributes`) y `lang/en/validation.php`; `ClientControllerTest` afirma que el mensaje contiene
  "DV" y no "tax id dv". Sin efecto colateral (solo cambia el texto de los mensajes de ese atributo).
- **`e2e/preqa-scrum928-935-batchA-20260918.spec.ts` (`657f6c0`): aceptable.** Se niega a correr
  contra un host `*.atlanticerp.ai` sin `PREQA_ALLOW_REMOTE`, se autosiembra por API con nombres únicos por
  corrida (sin IDs fijos), y cubre los 3 endpoints y los 6 puntos de UI de creación con el conjunto de
  DV inválidos relevante. Ver BAJO-N1.

### Hallazgos NUEVOS introducidos por los fixes

- **BAJO-N1 — el spec e2e guarda capturas en `e2e/__screenshots__/`, que NO está en `.gitignore`.**
  El árbol del repo frontend ya muestra `?? e2e/__screenshots__/` sin trackear; la convención de los
  demás specs es `e2e/.tmp/` (sí ignorada, `.gitignore:11`). Riesgo: commitear PNG por accidente.
  Cambiar la ruta de las capturas a `e2e/.tmp/...` o agregar la carpeta a `.gitignore`.
- **BAJO-N2 — el fix del `maxLength` en el mini-form de `ClientPicker` no tiene test propio.** El
  mismo código está cubierto en `CreateClientModal.test.tsx`, y el nuevo spec e2e lo ejercita en los
  flujos Nuevo Proyecto y tarjeta de Pipeline (`assertMiniFormRequiresDv`), pero ningún test unitario
  de `ClientPicker`/`PipelineCardModal` fallaría si alguien reintroduce `maxLength` allí. No bloquea.

### Corrección a mi revisión original (hallazgo que se me pasó, ya registrado por Pre-QA)

Al buscar consumidores del número de cuenta excluí `AdminContBankAccount.php` de mi `grep`, por lo
que no vi que `label()` (`AdminContBankAccount.php:69`) sigue devolviendo `... ****{ultimos_4}` y lo
consume el Recibo de pago (`AdminContPaymentController.php:170`, "Cuenta destino"). Es el PQ-928-2 de
Pre-QA. Severidad BAJO / decisión de negocio: el ticket SCRUM-928 acota el alcance a la Factura ("no
modifica ningún otro documento") pero D-6 dice "nunca `****XXXX` en documentos al cliente". No se
cambia `label()` porque también alimenta selectores internos (comisiones, movimientos bancarios,
notas de crédito) donde la máscara es deseable; si Luis quiere el número completo en el Recibo, es
un cambio acotado a ese documento y va en ticket aparte. Idem PQ-928-3 (Factura sin número muestra
"Cuenta número: —" sin nombre de banco): es lo que pide D-6, no es defecto.

### Confirmación

Esta sección fue agregada al final del mismo reporte. No se editó código de producción ni tests, y
no se hizo commit, push ni stash.
