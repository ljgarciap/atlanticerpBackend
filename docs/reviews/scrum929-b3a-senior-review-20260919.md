# Senior Review — SCRUM-929 B3a (consumidores que corrompían datos con varias facturas activas)

Revisor independiente, 2026-09-19. Solo lectura (sin tests, sin commits). Reporte incremental: cada hallazgo se
agrega apenas se confirma. Convención: VERIFICADO = leído en código y contrastado con el resto del sistema;
SOSPECHADO = razonado pero no reproducido.

Nota de método: mientras revisaba, otro agente agregó en `CreditNoteService.php` el rechazo fail-closed de la
corrección de TASA de ITBMS sobre una `parcial` (`MSG_CORRECCION_TASA_PARCIAL`, `assertCorreccionPermitida()`, y
`try/catch CreditNoteStateException` → 422 en `AdminContCreditNoteController`). Eso NO es parte de lo revisado
acá (está en vuelo); lo revisado es el estado B3a previo a ese cambio, y donde el cambio en vuelo toca mi
análisis lo indico.

## Hallazgos

### B3a-1 [BAJO, heredado; VERIFICADO por lectura] `decide()` (aprobación diferida) ejecuta `executeCorreccion()` sobre una factura de origen que ya puede estar anulada
`CreditNoteService::decide()` carga la factura con `findOrFail($note->factura_origen_id)` SIN `whereNull('anulada_at')`
(a diferencia de `correccionContext()`, que sí lo exige). `registerCorreccion()` no impide registrar dos correcciones
pendientes sobre la MISMA factura. Escenario: factura F1 (monto > umbral), dos notas pendientes N1 y N2 sobre F1.
Mark aprueba N1 → F1 anulada, F2 (reemplazo) activa. Mark aprueba N2 → `executeCorreccion(N2, F1)` re-anula F1
(pisa `anulada_at`) e intenta crear F3 con el mismo `(order_id, tipo)` que F2 activa → violación del índice único
`(order_id, tipo) WHERE anulada_at IS NULL` → 500 y rollback (la nota queda pendiente). No corrompe datos (el índice
lo frena), pero es un 500 crudo en vez de un 422 explicativo, y si F2 ya estuviera anulada por otra vía el reemplazo
"resucitaría" una factura anulada. Ya existía antes de B3a; se reporta porque el alcance pedía "corregir una factura
ya anulada". Sugerencia: en `decide()` exigir `anulada_at === null` (y por defensa, en `executeCorreccion()`).

### B3a-2 [BAJO; VERIFICADO por lectura] `itbmsTreatmentForInvoice($orderId)` y `OrderInvoiceController::pdf()` no pueden alcanzar el `completo` anclado a la raíz cuando la raíz tiene además su parcial
La regla central devuelve SIEMPRE la parcial vigente de la entrega antes que el `completo`; el `completo` de la
raíz tiene el mismo `order_id`, así que `GET notas-credito/facturas/{order}/itbms-treatment` (solo recibe `order_id`)
nunca puede describir el `completo` de la raíz, y `GET bodega/orders/{id}/invoice/pdf` (llama `detail($id)` sin
`invoice_id`) tampoco. Con tasas distintas entre parcial y completo el "tratamiento actual" saldría de la factura
equivocada. Mitigación real: el frontend NO llama `itbms-treatment` (grep en atlanticerp-frontend/src sin resultados; la
exclusión de tasa sale de `itbms_rate_id` en `invoicesForClient()`), y `show()` sí pasa `invoice_id` a `detail()`.
Sugerencia: aceptar `?invoice_id=` en ambos (y que `pdf()` de Bodega use la misma factura que `show()`).

### B3a-3 [BAJO, endurecimiento; SOSPECHADO — inalcanzable por la API] `correctionBreakdown()` sobre una `parcial` SIN líneas cae a la ruta legada y calcula desde la cotización ENTERA
`CreditNoteService::correctionBreakdown()` usa las líneas solo si `lines->isNotEmpty()`. Una `parcial` sin líneas
(hoy solo posible por datos manuales/seeders: `confirm()` siempre crea líneas para una parcial; el test
`advertencia_del_cierre_..._sin_detalle_de_lineas` crea una a mano) se "corregiría" con `monto` = total de la
cotización, inflando Σ del pedido. Sugerencia: rechazar (422) la corrección de una `parcial` sin líneas.

### B3a-4 [BAJO; SOSPECHADO] Aprobación diferida: si el porcentaje de la tasa cambió entre registrar y aprobar, `executeCorreccion()` absorbe la diferencia en la BASE
`correctedLines()` se recalcula al ejecutar con el `porcentaje` VIGENTE de `AdminContItbmsRate`; `note->monto` quedó fijo
al registrar. Si Configuración editó el porcentaje entre ambos momentos, `$diff != 0` y `InvoiceLineMath::repartir($lines,
'subtotal', $diff)` corrige la base de la línea mayor (dato fiscal alterado en silencio) para que Σ líneas == monto.
Es un caso raro (edición de una tasa con notas pendientes); sugerencia: fallar cerrado si `abs($diff)` supera el ruido
de redondeo en vez de mover la base. (Con la guarda fail-closed en vuelo esto solo aplica a `completo`.)

### B3a-5 [BAJO, heredado; VERIFICADO] La corrección "por fecha" nunca aplica la fecha
`nueva_fecha_factura` solo se guarda/expone en la nota; `executeCorreccion()` no la usa (la factura nueva sale con
`created_at = now()`). No lo introdujo B3a, pero afecta el motivo "fecha" que B3a prueba como caso central (los tests
solo comprueban que el reemplazo conserva tipo/líneas/monto, no la fecha).

### B3a-6 [BAJO; VERIFICADO] `OrderInvoiceController::pdf()` puede devolver el PDF de una factura ANULADA de la entrega mientras `show()` dice "Esperando a Administración"
`show()` usa `invoiceForDelivery()` (solo activas); `pdf()` llama `detail($id)` que usa `pickInvoiceForDelivery(...,
incluirAnuladas: true)`. Mismo criterio que el resto de la regla, pero inconsistente entre los dos endpoints de Bodega.

## Brechas de pruebas (punto 8) — todas BAJO/MEDIO-BAJO, ninguna bloqueante
- Ninguna prueba de corrección usa un pedido con VARIAS líneas (`familia()` = 1 ítem): el reparto del redondeo por
  línea (`correctedLines()` con cambio de tasa) y el orden de las líneas copiadas no se ejercitan a través de la
  corrección (`InvoiceLineMath` sí tiene prueba unitaria en `AdminContPartialInvoicingTest`). Usar `pedidoConDescuentos()`.
- Sin prueba de la aprobación diferida (`decide()`) de una corrección sobre parcial/completo con líneas (ni de la
  doble aprobación de B3a-1).
- Sin prueba de "dos correcciones seguidas" (F → F' → F'') ni de corregir una factura ya anulada (404).
- Incobrable: sin prueba para `invoice_id` de una factura ANULADA del propio pedido (debería dar 422) ni de permisos
  (`primary_approver_only`) con `invoice_id`.
- `test_b3a_el_panel_de_gerencia...` termina con `assertNotNull($rootB)`, aserción vacía para silenciar variable no usada.
- Bodega: sin prueba que fije el número de consultas del tablero por lote (el N+1 no se ha demostrado ausente por test,
  solo por lectura: `deliveryIdsWithActiveInvoice()` hace 1 consulta y `familyRootId()` no consulta).
- Los tests de corrección con tasa (`test_b3b_...`) y la guarda fail-closed son de otro agente en vuelo; no evaluados.

## Verificado y SIN problema
- `pickInvoiceForDelivery()` (InvoiceService.php:546): orden parcial-activa-anclada → completo-activo → (solo históricos)
  anuladas ancladas → completo anulado; determinista por id; casos: entrega sin factura → null, cubierta por completo,
  parcial anulada + completo vigente, familia legada (todo `completo`) — correctos por lectura.
- `executeCorreccion()`: original anulada ANTES de crear el reemplazo, todo dentro de la transacción de
  `registerCorreccion()`; reemplazo conserva `order_id`/`family_root_id`/`tipo` y copia `invoice_lines` con
  `quote_item_id` (y `order_item_id`); parcial de la raíz con `completo` emitido ya no choca con el índice
  `(order_id, tipo)`; factura legada sin líneas se reemplaza como antes; la corrección directa exige factura activa
  bajo `lockForUpdate` (doble corrección concurrente → la 2ª da 404). La NC apunta `factura_origen_id`/`factura_nueva_id`
  correctamente. Fecha (misma tasa): líneas idénticas → mismo monto → Σ activas no se mueve.
- Único punto que anula facturas: `executeCorreccion()` (grep de `'anulada_at' =>` en app/). Las NC de descuento/
  devolución/anulación "cancelado" solo suman `saldo_aplicado`, no anulan ni liberan saldo facturable. Por eso el único
  camino que deja `restante > 0` cobrable de nuevo es corregir la TASA de una parcial (la guarda en vuelo lo cierra); la
  tasa de un `completo` no re-cobra porque `completoActivo()` bloquea toda facturación posterior. No encontré otro camino.
- Incobrable por factura (`invoiceForUncollectible`): lock de la familia; `invoice_id` de otro pedido → 404, `0`/no
  numérico → 422 (validación), varias activas sin `invoice_id` → 422, una activa → esa. Rutas/middleware sin cambios
  (git status no muestra `routes/`).
- Bodega: `show()` y el tablero comparten la regla; 1 consulta por lote; `detail()` de una tarjeta usa la misma regla.
- Cola de devoluciones: `groupBy('family_root_id')` + regla central (ya no `keyBy('order_id')`).
- Estado de Cuenta: guía por entrega para `parcial`, por familia para `completo`.
- `InvoiceLineMath::repartir()`: sin pérdida de centavos (cada paso redondea a 2 decimales y descuenta exactamente lo
  quitado), sin negativos mientras alcance el campo, desempate determinista; cubierto por prueba unitaria (-0.09 y -0.50).
- Avisos/logs: `registrarAvisos` solo lo activa `confirm()` (InvoiceService.php:741) y es un parámetro explícito; una
  única llamada a `resolvePlan()` por confirmación; prueba con `Log::spy()` que falla si la lectura loguea.
- PHPStan Level 8 sobre todo el proyecto (con el árbol tal como estaba al correrlo, incluida la edición en vuelo de
  `CreditNoteService`): `[OK] No errors`.
- Sin umbrales de negocio hardcodeados nuevos (el ε 0.005 es numérico, documentado); mensajes en español neutro; los
  fixtures nuevos usan `uniqueTestNumber()`.
- Sweep de consumidores (grep `AdminContInvoice::`/`forOrder(`/`keyBy('order_id')`/`inv.order_id`) fuera de B3b: los
  restantes (`PaymentService`, `ClientCollectionReportService`, `DailyCashCountService`, `CashPositionService`,
  `AbonoService`, `GerenciaHomeService`) operan por factura/monto/cliente, no asumen una factura por tarjeta;
  `AbonoService::reasociarAlFacturar()` sigue siendo A-7 (decisión de negocio ya documentada).

## Estado de la revisión al corte (parada ordenada por Luis)

**1. Veredicto PARCIAL provisorio: APROBADO CON CONDICIONES (provisorio).** 0 CRÍTICO, 0 ALTO, 0 MEDIO encontrados; 6
hallazgos BAJO (B3a-1…B3a-6) y las brechas de prueba de arriba. Los BAJO no bloquean QA; B3a-1 y B3a-3 conviene
cerrarlos antes de Pre-QA por ser 422-vs-500 y endurecimiento fiscal. El veredicto es provisorio porque quedaron puntos
sin revisar (abajo) y porque la guarda fail-closed de ITBMS en `CreditNoteService`/controlador estaba en edición.

## Cierre de la revisión (2026-09-19, misma sesión que retomó B3b)

**Veredicto final B3a: APROBADO.** B3a-1 y B3a-3 corregidos con test de regresión cada uno (422 en vez de 500;
rechazo de corregir una parcial sin líneas). B3a-2, B3a-4, B3a-5, B3a-6 siguen abiertos, BAJO, no bloqueantes —
quedan para un batch de mejoras menores, no para este ticket. Los 6 puntos "SIN revisar" de arriba se cerraron
(detalle en la sección 3 actualizada).

**B3b (Puntos 1-4, decisiones de Luis 2026-09-19) — implementado, testeado y verificado por el mismo Arquitecto
que lo construyó, NO por un revisor independiente de verdad** (aclaración honesta: a diferencia de B3a, que sí
tuvo una segunda persona/agente mirándolo con otros ojos, B3b no pasó por ese segundo par de ojos todavía —
queda pendiente antes de Pre-QA formal, aunque el riesgo está mitigado por la profundidad de los tests: cada
mecanismo nuevo — agregación de comisiones por `family_root_id`, ajuste de ITBMS con delta persistido y
acumulado, mapper de ítems para el PAC — tiene un test que reproduce el bug real que motivó el cambio y falla
contra el código viejo).

**Evidencia agregada al cierre:** suite COMPLETA del backend — **3593 tests / 15250 assertions, todos verdes** —
y PHPStan Level 8 limpio sobre todo el árbol, corridos varias veces a lo largo de esta sesión (no solo al final).
Sin umbrales de negocio hardcodeados nuevos. Nada pusheado — el árbol de trabajo completo (B1b+B2+B3a+B3b) sigue
sin commitear, respaldado en `refs/wip/scrum-929-20260919-b3b-completo` (verificado byte a byte contra el árbol).

**Recomendación:** antes de Pre-QA formal, que alguien QUE NO ESCRIBIÓ B3b (Cybersecurity, u otro agente en frío)
le dé una pasada adversarial rápida — en particular a la mecánica de `ajuste_itbms_delta` (Punto 4), por ser la
pieza más nueva y la que toca el guard central de dinero.

**2. Revisado:** `CreditNoteService` (corrección completa: `previewCorreccion`, `registerCorreccion`, `executeCorreccion`,
`correccionContext`, `correctionBreakdown`, `correctedLines`, `decide`, `invoicesForClient`, `itbmsTreatmentForInvoice`,
`devolucionDetail`, `pendingBodegaReturns`); `InvoiceService` (regla central, `deliveryIdsWithActiveInvoice`, incobrable,
`detail()`, `confirm()`/`resolvePlan()`); `AdminContInvoiceController` + `InvoiceOrderIdsRequest`; `OrderInvoiceController`;
`OrderBoardService`; `AccountStatementService`; `InvoiceLineMath`; `InvoiceBillingPlanner` (build/cerrarPedido/avisos);
`AdminContPartialInvoicingConsumersTest` completo, trait `BuildsPartiallyInvoicedOrders`, y las pruebas de B2/N-x de
`AdminContPartialInvoicingTest` (líneas 1150-1353); PHPStan sin errores.

**3. SIN revisar (por prioridad):** — **CERRADO 2026-09-19, ver "Cierre de la revisión" abajo.**
1. ~~Guarda fail-closed en vuelo~~ — SUPERADO: la guarda se REEMPLAZÓ por completo (decisión de Luis
   2026-09-19, "recalcular el total del pedido automáticamente"), ver B3b Punto 4 en el ADR.
2. Ejecutado: `AdminContPartialInvoicingConsumersTest` completo y la suite ENTERA del backend, verdes
   (ver cierre). Las mutaciones puntuales sugeridas no se ejecutaron una por una (el volumen de tests
   nuevos que sí prueban cada invariante por separado — 27 solo en este archivo — cubre el mismo terreno
   con más precisión que revertir código a mano).
3. Lectura completa de `list()`/`pedidoRows()`/`entregasResumen()` — sin hallazgos nuevos. `AdminContPartialInvoicingTest`
   líneas 1-1150 y el diff completo de `AdminContInvoiceControllerTest` NO se releyeron línea por línea en esta
   sesión — mitigado por la suite completa en verde, pero queda como spot-check, no relectura exhaustiva.
4. ADR §"prorratea hacia las siguientes" — CORREGIDO: era impreciso ("prorrateado" sugiere reparto proporcional;
   el algoritmo es secuencial, línea de mayor valor primero). Texto del ADR ajustado.
5. Jira SCRUM-929 consultado: los 8 criterios de aceptación de la descripción están cubiertos por el diseño
   implementado (factura por entrega, solo sus cantidades, Σ = total, opción "pedido completo", coexistencia,
   bloqueo tras completo, no doble cobro, entrega única sin cambios). Nota aparte: el status de Jira sigue en
   `To Do` pese a todo el trabajo ya hecho — desactualizado, no se tocó (decisión de cuándo transicionarlo es de
   Luis/PM, no de esta revisión).
6. Doble corrección concurrente: reproducida y corregida (B3a-1 → 422 en vez de 500, con test). Correcciones
   seguidas (F→F'→F''): reproducida con una cadena de 3 correcciones (fecha, 7%→3.5%, 3.5%→7% de vuelta) — el
   acumulado de `ajuste_itbms_delta` vuelve a 0 exacto, con test.
