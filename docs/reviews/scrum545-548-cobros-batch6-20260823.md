# Senior Review — Admin&Cont, Cobros Batch 6 (SCRUM-545→548, REQ-468→471)

**Fecha:** 2026-08-23
**Resultado: APROBADO tras 3 fixes propios (1 de diseño real, 2 de bugs reales), gate completo
re-corrido después de cada uno.**

Batch 6 de Cobros (ver `docs/architecture/admin-contab-batches.md`) — comprobante de pago
(REQ-468), folio REC-XXX + estado real por método (REQ-469), historial con filtros/búsqueda
(REQ-470), modal de detalle (REQ-471). Implementado en un solo hilo (sin despachar subagentes
reales esta sesión — `feedback_risk_classifier_agent_dispatch_telegram_gap_20260823` seguía sin
aplicarse), Backend y Frontend secuencial, no forkeado.

## Gate real (suite completa)

- Backend: PHPStan Level 8 → `[OK] No errors`. `infra/test.sh` completo → **2494/2494 tests, 10090
  assertions, OK** (incluye 24 tests nuevos/actualizados de `AdminContPaymentControllerTest`).
- Frontend: `npx vitest run` completo → **122 archivos, 1401/1401 tests, OK** (incluye
  `HistorialCobrosPanel.test.tsx`, `DetalleCobroModal.test.tsx`, `adminContabApi.test.ts` nuevos +
  `RegistrarCobroModal.test.tsx`/`CobrosPage.test.tsx` actualizados). `tsc --noEmit` → sin errores.
  `npm run lint` no corre en este proyecto (ESLint no está instalado como dependencia — gap
  preexistente, no de este batch).

## Hallazgo de diseño real (corregido antes de escribir código nuevo)

**Batch 5 dejó `estado` inicial como placeholder deliberado** (ver docblock de
`2026_08_23_100000_create_admin_contab_payments_table.php`): solo `link_pago` arrancaba
`esperando_confirmacion`, el resto `confirmado` — una simplificación explícita "a falta de REQ-469,
resuelto en Batch 6". La regla real (RN2/RN3 de REQ-469) es: 6 métodos bancarios (Transferencia,
Cheque, Depósito, Tarjeta, Yappy, Link de pago) arrancan `esperando_confirmacion`; Efectivo,
Retención de impuestos y Ajustes de cuenta confirman de inmediato. Corregido en
`AdminContPayment::METODOS_CONFIRMACION_INMEDIATA` + `PaymentService::register()`. Como
consecuencia directa de RN5 ("no reduce el saldo pendiente de la factura hasta que se confirme
manualmente"), también se diferió — solo para cobros que arrancan `esperando_confirmacion` — la
reducción de `saldo_aplicado` de las facturas, la aplicación de saldo a favor, y el movimiento en
`bank_movements`; esos 3 efectos quedan pendientes hasta el botón "Marcar como confirmado" de Batch
7 (REQ-475). 3 tests de Batch 5 que asumían la regla vieja (`transferencia` confirmando de
inmediato) se migraron a `efectivo`/`retencion_impuestos` para conservar su intención original
(verificar el efecto inmediato), y se agregaron tests nuevos que fijan la regla real de los 9
métodos + RN5 (invoice/ledger sin tocar mientras espera confirmación).

## Bugs reales encontrados y corregidos en este mismo review

1. **S3 upload dentro de una transacción que tenía `invoices` bloqueadas
   (`lockForUpdate()`)** — `PaymentService::register()` llamaba
   `AdminContPaymentAttachmentService::upload()` (red, S3) desde dentro del `DB::transaction()` que
   ya tenía las facturas del cobro lockeadas desde el principio del método. Movido fuera de la
   transacción (el comprobante no participa de ningún cálculo de saldo/estado) — evita extender la
   ventana de lock por la latencia de red.
2. **`FormData` a mano serializaba booleanos como `"true"/"false"`, que la regla `boolean` de
   Laravel rechaza (solo acepta `1/0/"1"/"0"`)** — `adminContabApi.ts::payments.register()` arma un
   `FormData` cuando hay `comprobante` (archivo real, no puede ir en JSON), y `String(true)` da
   `"true"`. Un cobro con comprobante adjunto Y saldo a favor aplicado hubiera fallado 422 en
   silencio. Corregido: booleanos se serializan explícitamente como `'1'`/`'0'`. Cubierto por
   `adminContabApi.test.ts` (4 tests nuevos, incluido el caso exacto que hubiera fallado).
3. **Filtro de cliente en el historial era texto libre, pero el backend filtra por igualdad
   exacta** — `HistorialCobrosPanel` tenía un `<input>` de texto libre para "cliente"; escribir un
   nombre parcial (ej. "Hotel R" en vez de "Hotel Riu") hubiera devuelto 0 resultados en silencio,
   sin ningún error visible. Cambiado a `<select>` de valores reales derivados de las filas ya
   cargadas — mismo patrón que `clientesOptions` en `FacturacionPage.tsx` (Batch 2), consistente
   con el resto del módulo.

## Arquitectura — verificado en código

- **REQ-468:** mismo patrón que `Servicios\Services\TicketAttachmentService` (disco `s3-private`,
  sin versionado), simplificado a 1 adjunto por cobro (`hasOne`, columna `payment_id` con `unique`
  en la migración en vez de índice simple).
- **REQ-469:** folio `REC-%03d` vía `AdminContPaymentFolioService`, mismo mecanismo atómico
  (`INSERT ... ON CONFLICT DO NOTHING` + `lockForUpdate()`) que `AdminContInvoiceFolioService`
  (F-XXXX). Migración de columnas nuevas (`numero_recibo`, `referencia`) hace backfill de filas
  preexistentes en orden de `created_at` e inicializa el contador acorde, para no colisionar con el
  próximo `next()` en un entorno que ya tenía cobros de Batch 5.
- **`referencia`** — campo "Referencia / N° de transacción" del mockup
  (`4C__Admin_Contabilidad_Cobros.html`, input `#ncpRef`) que Batch 5 no había capturado pese a
  estar en el mismo formulario del mismo mockup — se agrega recién en este batch porque Historial
  (búsqueda, REQ-470) y Detalle (REQ-471) son quienes lo necesitan por primera vez.
- **REQ-470:** `total` (denominador de "Mostrando X de Y") se calcula ANTES de aplicar cualquier
  filtro — un `count()` sin condiciones, no el resultado de la query filtrada — cumple RN3
  literalmente. Búsqueda de texto libre cubre cliente+factura+recibo+referencia simultáneamente
  (RN1), case-insensitive vía `mb_strtolower`+`str_contains`.
- **REQ-471:** el modal de detalle NO incluye los botones "Ver comprobante"/"Ver recibo" del
  mockup (footer solo tiene "Cerrar") — decisión de diseño deliberada: esos 2 botones son
  REQ-472/473 (Batch 7, tickets propios SCRUM-549/550), y el mockup se comparte entre los 8 tickets
  de Cobros. Precedente: Batch 5 ya omitió partes del mismo mockup (historial, modal de detalle)
  sin que eso bloqueara su propio Visual Review. RN1 (lista completa de facturas con monto
  aplicado) y RN2 (campos condicionales) sí están cubiertos íntegramente.

## Veredicto y despliegue

**Pasada limpia tras 1 corrección de diseño (regla real de estado) + 2 bugs reales encontrados y
corregidos en este mismo review.** Los 4 tickets (SCRUM-545→548) pasan a Pre-QA + Visual Review
(fusionado, mismo criterio que batches anteriores). Commits en `dev`: backend `90ef13e`, frontend
`b48dd0a` — ya pusheados y desplegados a dev.atlanticerp.ai (backend vía SSH manual, GitHub Actions
sigue caído por el outage de billing desde 2026-08-20 — `project_github_actions_billing_outage_20260820`
en memoria; frontend build local + rsync a `/var/www/static/`).
