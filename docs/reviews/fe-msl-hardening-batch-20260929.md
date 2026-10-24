# Senior Review — Batch endurecimiento FE/MSL (2026-09-29)

**Spec:** `docs/specs/fe-msl-hardening-batch-detalle-rechazo-circuito-preview.md` (+ decisión aprobada: en C, `apagado` → "sin envío").
**Alcance revisado (sin commitear):**
- Backend: `MslPacAdapter`, `FiscalCertificationService`, `FiscalDocumentPreviewService`, `AdminContInvoiceController`, `AdminContCreditNoteController`, modelos `AdminContInvoice`/`AdminContCreditNote`, migración `database/migrations/tenant/2026_09_29_120000_add_fe_detalle_to_admin_contab_invoices_and_credit_notes.php`, y los tests correspondientes.
- Frontend: `FiscalElectronicStatusBadge(.test).tsx`, `InvoiceDetailModal.tsx`, `DetalleNotaCreditoModal.tsx`, `types/adminContab.ts`, i18n es/en.

**Fuera de alcance, pero presentes en el mismo working tree del backend:** cambios en `Bodega/*` (AdjustmentRequestController, WarehouseController, BodegaHomeService, BodegaInventoryKpiService), `Servicios/ProductLookupController`, `VentasDiseno/Models/CatalogProduct` y los tests sin trackear `Scrum996CatalogSqlTest`/`ProductLookupSqlTest`. Pertenecen a otro trabajo (SCRUM-99x, catálogo). **No se revisaron acá.** El commit de este batch tiene que agregar las rutas una por una (nunca `git add -A`) para no arrastrarlos sin su propio Senior Review.

**Evidencia aportada por el Arquitecto (no repetida):** PHPStan L8 proyecto completo limpio; vitest 2066/2066; filtro de backend del batch en verde. La suite completa de backend terminó con 1 falla de folio (F-0003 vs F-0001) en un test no tocado por el batch, con BD de tests compartida con otra sesión; pasa en aislamiento.

---

## Decision

**CHANGES NEEDED**: 1 blocker. El resto del batch está bien hecho. El blocker se arregla con 1 línea por modelo más un test.

---

## Blockers 🔴

### 1. `fe_detalle` se filtra a roles sin acceso por `POST /invoices/{order}/mark-uncollectible`

`app/Modules/AdminCont/Http/Controllers/AdminContInvoiceController.php:252`: `markUncollectible()` hace `return response()->json($invoice)` con el **modelo Eloquent completo**, que viene de `InvoiceService::proposeUncollectible()` → `$invoice->fresh()`. Ni `AdminContInvoice` (`app/Modules/AdminCont/Models/AdminContInvoice.php:55`) ni `AdminContCreditNote` (`app/Modules/AdminCont/Models/AdminContCreditNote.php:57`) declaran `$hidden`, así que la serialización incluye `fe_detalle`.

Esa ruta (`routes/admin-contab.php:102`) está bajo `role:superadmin,lider_admin_contab,asistente_administrativa,management`. Felix, Yaneth y Gerencia reciben el motivo del rechazo del PAC en la respuesta al proponer incobrable una factura rechazada. Eso rompe el criterio A.1 de la spec ("oculto para los demás"). También contradice lo que dicen los comentarios en ambos controllers: el "deny-by-default" hoy solo cubre las rutas de detalle, no el modelo.

(`uncollectibleDecision()`, línea 267, hace lo mismo, pero está bajo `primary_approver_only`, así que no filtra nada. Igual queda cubierto por el fix.)

**Fix:** deny-by-default real a nivel de modelo, en ambos modelos:
```php
/** fe_detalle: solo Mark/superadmin — se expone explícitamente en los controllers de detalle. */
protected $hidden = ['fe_detalle'];
```
Las lecturas actuales usan `->value('fe_detalle')`, que no se ven afectadas por `$hidden`. También cubre cualquier `response()->json($modelo)`, `toArray()` o evento serializado que se agregue en el futuro.

**Test requerido:** en `AdminContInvoiceControllerTest`, Felix propone incobrable sobre una factura con `fe_detalle` seteado y la respuesta no trae la clave `fe_detalle` (`assertJsonMissingPath('fe_detalle')`).

Otros lugares revisados que **no** filtran: `InvoiceService::list/detail`, export, PDF, `CreditNoteService::index/show/present`, el job de correo `SendCreditNoteConfirmedEmailJob`, `Bodega\OrderInvoiceController`, `GerenciaHomeService` y `ApprovalsPendingService`. Todos arman arrays explícitos. `audit_logs` sí guarda `fe_detalle` en `new_values` (vía `LogsAuditTrail`), pero el visor `/api/admin/audit/logs` es de superadmin y el texto ya llega redactado, así que es aceptable.

---

## Suggestions 🟡

1. **La redacción de la API key se puede eludir por el pre-truncado.** En `FiscalCertificationService.php:149-150`, `$detalleMsl` se corta a 2000 caracteres (`mb_substr`) **antes** de pasar por `sanitizarDetalle()` (`:154`, que redacta en `:197` y recién después trunca en `:201`). Si MSL citara la key justo en el borde del carácter 2000, quedaría un prefijo parcial de la key que `str_replace` ya no reconoce y se persistiría así. El orden correcto dentro de `sanitizarDetalle()` (redactar y después truncar) sí es correcto. Arreglo: pasar `rawResponse['error']` completo a `sanitizarDetalle()`, sin el `mb_substr` previo. Seguir usando `$detalleMsl` truncado solo para el `Log::warning`, o mejor, también redactado. La probabilidad es baja y solo lo ve Mark/superadmin, por eso no es blocker, pero el arreglo es trivial y el criterio A.4 lo pide sin excepciones.
   - El test `test_fe_detalle_se_trunca_a_2000_caracteres_y_no_incluye_la_api_key` (`FiscalCertificationServiceTest.php:182`) no prueba la redacción sobre `error`: la key está en la posición 5001, así que la elimina el pre-truncado, no la redacción. El `[redactado]` que el test encuentra viene de `detalles`. Agregar un caso con la key atravesando la posición 2000 y otro con la key al principio de `error`.
   - Defensa en profundidad, opcional: `str_replace` distingue mayúsculas y minúsculas y no cubre la key url-encoded ni las credenciales de Digifact (`services.digifact.password/username`). Hoy ningún mensaje de Digifact las cita (`DigifactTokenService` solo arma mensajes con el status), así que es opcional.
   - Multibyte: `mb_substr` corta por caracteres, así que no hay corte de UTF-8 inválido. OK.

2. **Falta el test de gating para la Nota de Crédito.** `AdminContCreditNoteController::show()` (`:202-207`) no tiene equivalente a `test_detail_expone_fe_detalle_solo_a_mark`. **No lo considero bloqueante por sí solo**: la lógica es una copia exacta del gate de factura, que sí está probado, y la ruta vive bajo el mismo middleware. Pero es un gate de seguridad en un endpoint distinto y una regresión ahí no la detectaría nada. Como el blocker 1 obliga a tocar tests de todas formas, pedir que se agregue en la misma ronda (Felix → `null`, Mark → texto).

3. **`decision()` de NC reemplaza el cache del modal con una respuesta sin `fe_detalle`.** `AdminContCreditNoteController.php:237` devuelve `creditNotes->show()` sin `fe_detalle`, y `useDecideNotaCredito` (`atlanticerp-frontend/src/hooks/useAdminContab.ts:708`) hace `setQueryData` con esa respuesta, que es más acotada que el detalle. Es el mismo patrón que ya está registrado en memoria (`feedback_tanstack_query_setquerydata_narrow_mutation_response`). Mark aprueba, la NC queda `aplicada`, y si el PAC la rechaza el motivo no aparece hasta el próximo refetch. Arreglo: en `decision()` agregar `fe_detalle`, porque la ruta es `primary_approver_only` y el actor ya está autorizado. Alternativa: `invalidateQueries` en vez de `setQueryData`.

4. **La clave Redis huérfana no "expira sola".** El criterio B.3 dice que el estado bajo `msl_fe` "expira solo". `cb:msl_fe:failures` sí tiene TTL, pero `cb:msl_fe:opened_at` se setea sin TTL (`app/Shared/Support/CircuitBreaker.php:92`, lo dice el propio docblock: "no TTL (cleared on close)") y ya nadie la va a leer ni limpiar. Es inofensiva, porque ningún código consulta ese nombre, pero no expira. Opciones: un `Redis::del('cb:msl_fe:opened_at', 'cb:msl_fe:failures')` en el deploy o en un comando puntual, o corregir el texto del criterio. Callers de `breaker()` revisados: solo `certificar()` y `consultarEstado()` del propio adapter. `PollMslDocumentEstadoJob` pasa por `PacProviderFactory::make()`, así que hereda el circuito del ambiente vigente. Los tests del job se actualizaron a `msl_fe_pruebas`. OK.

5. **Hay tests que pasan de forma trivial.**
   - `test_pendiente_202_deja_fe_detalle_en_null` (`FiscalCertificationServiceTest.php:214`) arranca con `fe_detalle` ya en null, así que no prueba que un `pendiente` pise un detalle previo. Sembrar `fe_detalle = 'viejo'` antes, que es el caso real: re-certificación que queda pendiente después de un rechazo.
   - `test_preview_muestra_el_ambiente_segun_pac_ambiente` no afirma `Http::assertNothingSent()`, y el criterio C.2 lo exige. El test hermano sí lo hace, así que conviene repetirlo en este.

6. **Duplicación del cálculo de `$isMark`.** Está copiado de forma idéntica en `AdminContInvoiceController::detail()` (`:178-186`) y `AdminContCreditNoteController::show()` (`:187-196`). Ahora gobierna también la exposición de datos, no solo la visibilidad de botones. Conviene extraerlo a un helper compartido (p. ej. junto a `EnsureIsPrimaryApprover`) para que el criterio de Mark/superadmin no pueda divergir entre los dos endpoints. Es deuda previa, pero con este batch el costo de que diverjan sube.

7. **Gate de suite completa antes del push.** La falla de folio F-0003/F-0001 es consistente con la BD compartida que se describió, y el test pasa en aislamiento. Aun así, la regla del proyecto exige una corrida completa limpia antes de pushear. Repetirla con la BD de tests sin otra sesión activa. Si reaparece, verificar si es un `->first()` sin `orderBy` (mismo patrón que `OrderPickingFlowTest` en memoria) antes de descartarla.

---

## Well done 🟢

- **Diseño deny-by-default en los controllers.** Se agrega `fe_detalle` en `detail()`/`show()`, no en `InvoiceService::detail()`/`CreditNoteService::show()`. Es la decisión correcta: esos servicios los reutilizan Bodega y el job de correo. Está bien documentado en el código. (Solo falta llevarlo también al modelo, ver blocker 1.)
- **Cuándo se escribe `fe_detalle`.** Un único punto de escritura (`persistirResultado`). En cada llamada se resetea a `null` antes de decidir, así que una recertificación exitosa no arrastra el rechazo viejo. `error_configuracion` desde excepción (`certificar()` catch) también pasa por `sanitizarDetalle()`. `pendiente` y `certificado` quedan en NULL.
- **`sanitizarDetalle()`.** Redacta antes de truncar, trabaja por caracteres (multibyte-safe) y convierte texto vacío en NULL.
- **Migración.** Es `TenantSchemaMigration` con `$module = 'admin_contab'`, así que se califica con el schema por tenant y corre para todos los tenants vía `tenants:artisan migrate`. Es aditiva, nullable y cubre ambas tablas en `up()`/`down()`, con `dropColumn` simétrico: reversible y no destructiva. Por regla del proyecto, igual requiere visto bueno del Arquitecto (la spec lo pide).
- **Circuito por ambiente.** El cambio es mínimo, sin tocar umbrales, y el test prueba el aislamiento de verdad: con pruebas abierto, producción certifica, y pruebas sigue degradado.
- **Preview (C).** Solo lee `pac_ambiente` y no resuelve credenciales ni instancia el adapter. `AdminContFiscalSettings::current()` (`findOrFail(1)`) ya lo invocaba `FiscalDocumentBuilder` (`:156`) dentro del mismo flujo, así que el preview no tiene un modo de falla nuevo: si el settings no existe, el builder ya falla antes y se devuelve `ok:false` como antes. El `match` usa `default → 'pruebas'`, que es conservador ante un valor inesperado.
- **Frontend.** El detalle se renderiza como texto React (escapado). El test lo verifica con un payload `<img onerror>`. Usa `whitespace-pre-wrap break-words` para el `\n` entre errores y regla. Tiene i18n `motivo` en es y en. No hay emojis, se reutilizan los íconos propios. Se muestra solo en `rechazado`/`error_configuracion` y nunca en `certificado`, aunque llegue texto. La prop es opcional, así que no rompe otros callers.
- **Sin umbrales de negocio hardcodeados.** El 2000 es un límite técnico de sanitización, no un umbral de negocio. Los parámetros del circuito no cambian.

---

## Re-review (2026-09-29)

**Decisión final: APPROVED.** Pasa a Pre-QA.

- **Blocker 1: cerrado.** `protected $hidden = ['fe_detalle']` está en ambos modelos. Verifiqué que `$hidden` no rompe ninguna serialización legítima:
  - `LogsAuditTrail` usa `getAttributes()`/`getChanges()`, que ignoran `$hidden`, así que `audit_logs` sigue registrando el campo.
  - Los 3 controllers leen con `->value('fe_detalle')`, que tampoco pasa por `$hidden`.
  - No existe ningún `->toArray()`/`toJson()`/`only()` sobre estos modelos que deba llevar el campo.
  - `InvoiceCreated::payload()` arma un array explícito y los jobs con `SerializesModels` solo guardan el id.
  - El test nuevo `test_mark_uncollectible_no_filtra_fe_detalle_al_actor` verifica que la clave no está y que el texto no aparece en el body.
- **Sug. 1 (redactar y después truncar): arreglado en el código.** `rawResponse['error']` llega entero a `sanitizarDetalle()` y el log se acota por separado.
  - 🟡 Nuevo, no bloqueante: el test `test_fe_detalle_se_trunca_a_2000_caracteres_y_no_incluye_la_api_key` (`FiscalCertificationServiceTest.php:182`) no distingue el código viejo del nuevo, así que pasaría igual con el bug.
    - Por qué: el `detalles` que se antepone ("clave usada: [redactado]\n", unos 25 caracteres) corre la key del `error` a la posición ~2020 del texto final. El truncado final la elimina sin necesidad de redactarla.
    - Arreglo: quitar `detalles`, o dejarlo vacío, para que la key en la posición 1995 del `error` quede a caballo del corte del texto final.
- **Sug. 2 y 3: cerradas.** `test_show_y_decision_exponen_fe_detalle_solo_a_mark` cubre Felix y Mark en `show` y Mark en `decision`. `decision()` agrega el campo; la ruta es `primary_approver_only`.
- **Sug. 4: cerrada** (spec corregida). **Sug. 5: cerrada.**
- **Sug. 6: cerrada.** El helper `AdminContFiscalSettings::actorEsMarkOSuperadmin()` reproduce exactamente el criterio anterior. Ahora corta antes de `current()` cuando el actor es superadmin, lo que es estrictamente más seguro.
- **Sug. 7: cerrada** según lo que reportó el Arquitecto: backend 4023/0 fallos, PHPStan L8 limpio, vitest 2066/2066.
- Se mantiene la advertencia de alcance: el commit tiene que excluir los cambios de Bodega/Servicios/CatalogProduct y los tests `Scrum996*`/`ProductLookupSql*`, que no tienen Senior Review.
