# Senior Review independiente — SCRUM-1009 (2026-09-30)

Commits: backend 4d5f7273, frontend 9887cdd9. Revisión de lectura (sin correr suites).

## Veredicto: DEVUELTO (1 bloqueante, 3 sugerencias fuertes)

La mecánica pedida está bien construida: compare-and-set correcto, los 3 caminos de certificación de la NC
(inicial, `recertificar`/`actualizarEstado` de SCRUM-1007, `PollMslDocumentEstadoJob`) pasan todos por
`persistirResultado()`, FE apagada queda igual, D1 y el builder de pagos son correctos y acotados a `correccion`.
Pero hay un estado irrecuperable que este cambio crea (antes el reemplazo se certificaba).

## BLOQUEANTE

### B1. Corregir una factura cuyo original NUNCA fue certificado deja el reemplazo retenido para siempre
`CreditNoteService.php` ~L384-395 + `FiscalDocumentBuilder::paraNotaCredito` L76-83 + `FiscalCertificationService::bloqueoFiscal`.
Escenario: la factura F1 fue rechazada por la DGI (o quedó `error_configuracion`/`pendiente`, o se emitió con la FE apagada y luego se
activó): `F1.cufe = null`. Es el motivo más natural para una "Corrección de datos". Se registra la corrección:
1. `executeCorreccion` anula F1 en AtlanticERP y crea F2.
2. `certificarNotaCredito`: el builder lanza "la factura de origen no tiene CUFE" -> NC `error_configuracion`.
3. Nuevo código: estado de la NC no es `certificado`/`no_aplica` -> F2 queda `en_espera_nc`.
4. Salida imposible: "Reintentar" la NC vuelve a fallar por el mismo motivo (sigue sin CUFE); F1 no se puede re-certificar
   (`bloqueoFiscal` -> `DOCUMENTO_ANULADO` porque `anulada_at != null`); F2 no tiene acción (`accionDisponible` devuelve null para `en_espera_nc`).
Resultado: el cliente queda sin factura vigente ante la DGI y sin ninguna vía en la UI; el aviso de la NC promete "se emitirá automáticamente"
(falso). Antes de este ticket F2 sí se certificaba. La spec no contempla este caso.
Fix sugerido (elegir uno, decisión de Arquitecto/Luis): (a) en `certificarFacturaDeReemplazoSiCorresponde`, si `facturaOrigen->cufe === null`
no hay nada que anular ante la DGI: no retener y despachar `InvoiceCreated` (y no intentar la NC, o dejarla `no_aplica`); o (b) bloquear la
corrección en `correccionContext()` con 422 si la original no está `certificado` cuando la FE está activa. Falta además un test de este caso.

## Sugerencias fuertes (no bloquean por sí solas, pero deben resolverse o quedar como decisión explícita de Luis)

### S1. Ventana de carrera: reemplazo retenido para siempre aunque la NC certifique
`CreditNoteService.php` L384-388. Se lee `$note->fresh()->fe_estado` (`pendiente`) y recién después se escribe `en_espera_nc` en F2. Si el
job de polling (o un reintento manual) certifica la NC entre esa lectura y ese `save()`, el CAS de `liberarReemplazoRetenido` corrió cuando F2
todavía NO estaba en `en_espera_nc` (no-op) y F2 queda retenido sin que nadie lo libere. Ventana pequeña (el polling parte con delay de 90 s) pero
el resultado es irrecuperable. Fix barato: tras guardar `en_espera_nc`, releer la NC y, si ya está `certificado`, ejecutar la misma liberación
(extraer `liberarReemplazoRetenido` a método público del servicio y llamarlo desde ambos lados).

### S2. Sin salida manual para un reemplazo retenido
`accionDisponible()` devuelve null para `en_espera_nc`. Casos residuales sin salida: NC que MSL ya tiene (9022; `reenviar` deja el estado
sin cambiar a propósito, así la NC no llega a `certificado`), excepción inesperada en el listener tras el CAS (F2 queda `fe_estado = null`, sin
badge ni botón), y el S1. Proponer una acción auditada para Mark/superadmin "liberar reemplazo" (o "certificar reemplazo") que reponga el CAS,
o al menos registrar el riesgo residual como ticket. La spec ya reconoce el riesgo pero no cubre estos casos.

### S3. NC `no_aplica` con la factura habilitada => doble facturación
`certificarFacturaDeReemplazoSiCorresponde` trata `no_aplica` de la NC como "sigue su curso". Con `pac_doc_nota_credito_habilitado = false` y
`pac_doc_factura_habilitado = true` (combinación permitida por `debeCertificar`), la NC no se envía, la original sigue vigente ante la DGI y F2 SÍ se
certifica: justo el escenario que D2 quiere impedir. Sugerencia: pasar solo si la NC es `no_aplica` Y F2 también sería `no_aplica` (factura
deshabilitada/FE apagada); si la factura está habilitada y la NC no, retener.

## Sugerencias menores
- `FiscalDocumentBuilder.php` L167-177: la spec (B) pedía derivar TAMBIÉN el ítem (subtotal/ITBMS) de la original; solo se cambió `pagos[]`.
  El ítem sigue saliendo de `credit_notes.subtotal` (calculado del reemplazo desde la cotización). Cuadra cuando la base no cambió (caso del test), pero si
  la cotización cambió tras facturar, o la original tiene `ajuste_itbms_delta`, ítem y pagos pueden volver a diferir en centavos/monto. Sin columna `subtotal`
  en `invoices`, usar `monto / (1 + tasa)` con redondeo o sumar las `lines` de la original.
- Tests: no hay caso de original sin CUFE (B1), ni de camino por polling job, ni de carrera S1. `test_la_liberacion_..._idempotente` con `Event::fake`
  verifica el CAS de forma secuencial (suficiente para probar la semántica del `UPDATE ... WHERE`, no es concurrencia real). Los tests nuevos fallarían con el
  código viejo (esperan `en_espera_nc`, 422 y monto original). Sin flakiness evidente (`Http::sequence`, `Carbon::setTestNow` restaurado).
- D1 (`correccionContext`): correcto. NC `rechazada` por Mark no bloquea; `aplicada` sí (incluye las rechazadas por la DGI, como pide la spec);
  `pendiente_aprobacion` ya lo cubre `$yaPendiente` con otro mensaje (dice "corrección pendiente" aunque sea un descuento; y con voseo "resolvé", preexistente).
  Correcciones encadenadas OK: la NC de F1 no bloquea corregir F2.
- Frontend: el aviso se muestra también si la original nunca se certificó (B1) y usa `IcoAlertTriangle` en tono informativo sky; correcto en i18n es/en, sin
  emojis, con variantes dark. `en_espera_nc` no tiene explicación en el detalle del reemplazo más allá de la etiqueta (la spec lo pedía como badge, cumplido).
- Efectos secundarios de `InvoiceCreated`: el otro listener (`MarkOrderInvoiceReady`) es no-op; solo certifica. No se duplica nada al liberar (se despacha
  una única vez por CAS; mientras está retenido no se despacha). Certificación de F2 ocurre síncrona dentro del lock de caché de `recertificar` (TTL 90 s
  vs peor caso ~45 s: OK) y dentro del job de polling con tenant ya activo (OK).
- Consumidores de `fe_estado`: `InvoiceService` detail y controladores solo lo pasan tal cual; `accionDisponible` cae en `default => null`; no hay
  reportes/Gerencia que lo traten como certificado. Sin hallazgos.

---
# Re-review (commit 4f75c95b) — VEREDICTO FINAL: APROBADO para Pre-QA (con 2 puntos abiertos no bloqueantes)

- B1 cerrado: original con cufe null (rechazada/error_configuracion/legacy/FE apagada al emitirla) + FE activa => NC de corrección `no_aplica`
  (`FiscalCertificationService::certificarNotaCredito`), el reemplazo entra por la rama `no_aplica` y se certifica; ya no queda retenido. Original `pendiente`
  con FE activa se bloquea (422) en `correccionContext` (preview y submit), evitando duplicar si luego certifica.
- Original certificada + NC rechazada: sigue retenida y se libera por reintento/polling (probado). S1 cerrado (relectura + CAS idempotente público).
  S3 cerrado (422 si factura certificada y NC electrónicas deshabilitadas).
- Riesgo de "tapar" un caso real: solo si una factura realmente existente ante la DGI tuviera `cufe = null` en AtlanticERP. Caminos posibles: (a) 9022 en el envío
  de la original (MSL la tiene pero AtlanticERP la dejó `rechazado`/sin CUFE, CUFE no recuperable por API) => la corrección emitiría un reemplazo duplicado ante la DGI;
  (b) facturas emitidas por el sistema legado antes de la FE de AtlanticERP. Ambos son casos de borde documentables; el bloqueo `pendiente` y la
  transacción con lock cubren la carrera original-vs-corrección de este ticket. No bloqueante: verificar en Pre-QA/decisión de Luis si las facturas
  `rechazado` con transmisión 9022 deben bloquear la corrección.
- NC que no son corrección: sin cambios (el atajo exige `subtipo_anulacion = correccion`; las demás siguen con error visible si falta el CUFE). Sin regresiones vistas.
- Abiertos (no bloquean): S2 (liberación manual, ticket aparte / decisión de Luis, ya aceptado); ítem del builder derivado de la original (Pre-QA sandbox).
- Tests nuevos (4) cubren original sin certificar, pendiente, NC deshabilitadas y doble liberación; coherentes. La carrera S1 en sí no tiene test (aceptable).
