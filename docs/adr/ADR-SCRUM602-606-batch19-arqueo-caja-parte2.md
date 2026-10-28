# ADR — Batch 19: Arqueo / Flujo de Caja, parte 2 (SCRUM-602→606, REQ-525→529)

Arquitecto, 2026-08-26. Segundo y último batch del grupo "Arqueo / Flujo de Caja" (ver
`docs/architecture/admin-contab-batches.md`). Mismo mockup que Batch 18:
`4G__Admin_Contabilidad_ArqueoCaja.html` (adjunto en los 5 tickets) — este batch implementa lo que
Batch 18 dejó fuera a propósito: cerrar el arqueo, cola secuencial de atrasados, historial +
aprobación de Gerencia (Aprobador), constancia de retención, y ver/descargar resumen.

## Alcance de los 5 tickets
- REQ-525: sección "Ajustes / Retención" dentro del arqueo del día — cobros con método "Retención
  de impuestos", estado de constancia (Pendiente/✓), botón subir/reemplazar.
- REQ-526: botón "Cerrar arqueo del día" — confirmación con número+fecha+total neto, aviso si hay
  retención sin constancia (no bloquea), pasa a `pendiente_aprobacion`, registra quién y cuándo.
- REQ-527: bloqueo secuencial — el "arqueo activo" es siempre el más antiguo sin cerrar; aviso
  explícito si no es el de hoy; avanza solo al cerrar el atrasado.
- REQ-528: historial de arqueos cerrados (solo lectura, inmutable salvo constancia) + aprobación
  exclusiva de Aprobador, contador de pendientes.
- REQ-529: ver detalle de cualquier arqueo (activo o del historial) + descargar resumen formal
  (PDF).

## Reconciliaciones spec vs. código existente

**1. `daily_cash_counts` se extiende, no se reemplaza** — ya anticipado en el docblock de la
migración de Batch 18: agrega `numero` (int, único cuando no null), `estado`
(`abierto|pendiente_aprobacion|aprobado`, default `abierto`), `cerrado_por`/`cerrado_at`,
`aprobado_por`/`aprobado_at`. Migración aditiva.

**2. `DailyCashCountService::today()` deja de ser "literalmente hoy"** — con el bloqueo secuencial
(REQ-527), la pantalla de "Arqueo del día" ya no siempre corresponde a la fecha real. Se
refactoriza: `forDate(string $fecha)` (lógica actual de Batch 18, sin cambios) +
`activeDate(): string` (ver punto 4) + `active()` = `forDate(activeDate())` con metadata de cierre
agregada (`numero`, `estado`, `retenciones[]`, `es_atrasado`, `fecha_real_hoy`). La ruta
`GET /cash-position/daily-count` pasa a devolver el arqueo **activo**, no el de la fecha del
servidor — cambio de contrato respecto a Batch 18, documentado acá porque no hay ruta nueva que lo
señale.

**3. Guard de inmutabilidad retroactivo sobre las 2 rutas de Batch 18.** REQ-528 RN1 exige que un
arqueo cerrado no pueda editarse (ni observación de fila ni general). `updateEntryObservation()` y
`updateGeneralObservation()` (ya existentes) ahora operan sobre `activeDate()` en vez de
`now()->toDateString()`, y lanzan `DailyCashCountStateException` (mismo patrón que
`CreditNoteStateException` de `CreditNoteService`) si el registro de esa fecha no está `abierto`.
En la práctica esto no debería poder ocurrir desde la UI normal (el frontend solo deja editar el
arqueo activo, que por definición está `abierto`), pero es la única forma de que la regla sea real
y no solo convención de frontend.

**4. Piso del bloqueo secuencial — de dónde arranca a contar "atrasados".** REQ-527 no dice desde
qué fecha empieza a exigirse el cierre secuencial, y los datos reales de `bank_movements`/
`AdminContPayment` existen desde antes de Batch 18 (2026-08-25) — tomar la fecha del primer cobro
real como piso generaría un backlog de meses de "atrasados" el día que este batch se despliegue,
que no es la intención. Se agrega `fiscal_settings.arqueo_tracking_start_date` (date, nullable,
editable por Aprobador vía el `PUT /fiscal-settings` ya existente — mismo patrón que
`petty_cash_balance`/Batch 18). `activeDate()` calcula el piso como
`arqueo_tracking_start_date ?? MIN(daily_cash_counts.fecha) ?? hoy` y escanea día por día (rango
acotado a semanas, sin problema de performance) hasta encontrar la primera fecha sin registro o con
`estado=abierto`. Default `null` en fiscal_settings → si no hay filas previas, el piso es hoy mismo
(cero backlog al día del deploy). **Antes de desplegar a QA/prod, verificar si ya existen filas de
`daily_cash_counts` con fechas de prueba (dev/test) que distorsionen el piso real** — si las hay,
Aprobador debe fijar `arqueo_tracking_start_date` explícitamente al promover.

**5. Totales del historial/detalle se recalculan en vivo, no se snapshot-ean.** RN1 de REQ-528
("ningún dato... puede editarse") se refiere a que el usuario no pueda escribir sobre el arqueo
cerrado, no a que el backend deba congelar una copia de los totales — los cobros/notas de crédito
que alimentan `forDate()` ya son inmutables una vez confirmados/aplicados en sus propios módulos
(no hay ningún flujo que los edite retroactivamente). Persistir una copia duplicaría la fuente de
verdad sin necesidad. `detail($id)` = `forDate($record->fecha)` + metadata de cierre/aprobación del
registro (`numero`, `estado`, `cerrado_por`, `cerrado_at`, `aprobado_por`, `aprobado_at`).

**6. Constancia de retención — tabla nueva, no reutiliza `payment_attachments`.**
`payment_attachments` (Batch existente, REQ-468/472) ya guarda **el comprobante del cobro**
(captura al registrar el pago, cualquier método, 1 por pago, `payment_id UNIQUE`) — es un concepto
distinto de la **constancia fiscal de retención** de REQ-525 (documento que el cliente entrega
después, solo aplica a método "Retención de impuestos", puede subirse/reemplazarse en cualquier
momento incluso tras cerrar el arqueo). Reusar la misma tabla arriesgaría pisar el comprobante de
un pago que también tuviera uno cargado al registrarse. Se agrega
`admin_contab.payment_retention_attachments` (mismo shape que `payment_attachments`: `payment_id
UNIQUE`, `nombre_archivo`, `storage_key`, `size_bytes`, `mime_type`, `uploaded_by`, timestamps) +
modelo `AdminContPaymentRetentionAttachment` + servicio
`AdminContPaymentRetentionAttachmentService` (clon de `AdminContPaymentAttachmentService`, mismo
disco `s3-private`, key `admin-contab/payments/{id}/retencion/{uuid}.{ext}`, con **reemplazo real**
— a diferencia del original, este servicio borra el objeto S3 anterior antes de insertar el nuevo
cuando ya existe una fila para ese `payment_id`, porque acá "reemplazar" es un caso de uso
explícito del REQ, no solo "subir una vez".

**7. "Motivo" de la retención en el mockup = `numero_documento_retencion` ya existente.** El campo
ya capturado al registrar el cobro (`AdminContPayment::numero_documento_retencion`, texto libre) es
la fuente para la columna "Motivo" de la sección Ajustes/Retención — no se agrega columna nueva.

**8. Numeración del arqueo — entero secuencial simple, sin prefijo.** El mockup usa
`SIGUIENTE_NUM_ARQUEO` como entero plano (1, 2, 3...), sin prefijo tipo `ARQ-`. Se asigna solo al
cerrar (`close()`), dentro de la misma transacción que el cambio de estado, vía
`lockForUpdate()` + `MAX(numero)+1` sobre `daily_cash_counts` — sin tabla de folio dedicada (a
diferencia de `ticket_folio_counters` en Servicios): volumen de 1 cierre/día, acción humana
explícita, sin escenario de concurrencia real que justifique el mecanismo más pesado.

**9. Ambigüedad de permisos — "Descargar resumen" del arqueo ACTIVO (REQ-529 Escenario 1).**
REQ-529 PERMISOS dice "Contabilidad, Asistente, Aprobador y Gerencia" sin distinguir activo vs. historial, pero
REQ-524 (pantalla "Arqueo del día") ya excluye a Aprobador/Gerencia por completo — no hay forma de que
lleguen al botón "Descargar resumen" del arqueo activo si nunca ven esa pantalla. Resuelto
igual que la ambigüedad ya reconciliada en Batch 18 (punto 8 de ese ADR): el gate real sigue la
pantalla donde vive el botón, no la lista de PERMISOS tomada literalmente sin cruzar con REQ-524.
`GET /cash-position/daily-count/export` (activo) → mismo gate que `/daily-count` (Contabilidad+Asistente).
`GET /cash-position/history/{id}/export` (cerrado) → gate amplio (Contabilidad, Asistente, Aprobador, Gerencia),
que es donde Aprobador/Gerencia sí llegan de forma natural vía el historial. **Marcado para que Luis lo
confirme** — si prefiere lo contrario, es un cambio de una línea en el middleware de la ruta, sin
impacto en el resto del diseño.

**10. Sin evento de dominio nuevo.** Mismo criterio que `CreditNoteService::decide()` (única
aprobación tipo-Gerencia ya existente en el módulo): ni `close()` ni `approve()` disparan
`DomainEvent` — el único evento real de AdminCont sigue siendo `InvoiceCreated`. No se inventa un
patrón nuevo para este batch.

## Esquema nuevo (`admin_contab`)

```sql
-- Migración aditiva sobre daily_cash_counts
ALTER TABLE daily_cash_counts ADD COLUMN numero INT UNSIGNED NULL UNIQUE;
ALTER TABLE daily_cash_counts ADD COLUMN estado VARCHAR(255) NOT NULL DEFAULT 'abierto';
  -- abierto | pendiente_aprobacion | aprobado
ALTER TABLE daily_cash_counts ADD COLUMN cerrado_por BIGINT UNSIGNED NULL REFERENCES users;
ALTER TABLE daily_cash_counts ADD COLUMN cerrado_at TIMESTAMPTZ NULL;
ALTER TABLE daily_cash_counts ADD COLUMN aprobado_por BIGINT UNSIGNED NULL REFERENCES users;
ALTER TABLE daily_cash_counts ADD COLUMN aprobado_at TIMESTAMPTZ NULL;

-- Migración aditiva sobre fiscal_settings
ALTER TABLE fiscal_settings ADD COLUMN arqueo_tracking_start_date DATE NULL;

-- Tabla nueva
payment_retention_attachments(
  id, payment_id BIGINT UNSIGNED UNIQUE REFERENCES payments,
  nombre_archivo, storage_key, size_bytes, mime_type,
  uploaded_by BIGINT UNSIGNED REFERENCES users,
  created_at, updated_at   -- updated_at real: se pisa en cada "reemplazar"
)
```

## Contrato de API (`routes/admin-contab.php`)

| Método/ruta | Gate | REQ |
|---|---|---|
| `GET /cash-position/daily-count` | `role:superadmin,lider_admin_contab,asistente_administrativa` (sin cambio de gate — cambia el significado: ahora es el arqueo **activo**, no literal-hoy) | REQ-524/527, extendido |
| `PUT /cash-position/daily-count/entries` | ídem, ahora 409 si `estado != abierto` | REQ-524, guard nuevo |
| `PUT /cash-position/daily-count/general-observation` | ídem, ahora 409 si `estado != abierto` | REQ-524, guard nuevo |
| `POST /cash-position/daily-count/close` | ídem (Contabilidad+Asistente) | REQ-526 |
| `GET /cash-position/daily-count/export` | ídem (Contabilidad+Asistente) — PDF | REQ-529 (activo) |
| `GET /cash-position/history?page=` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` | REQ-528 |
| `GET /cash-position/history/{id}` | ídem | REQ-528/529 |
| `GET /cash-position/history/{id}/export` | ídem — PDF | REQ-529 (historial) |
| `POST /cash-position/history/{id}/approve` | `mark_only` | REQ-528 RN2/RN4 |
| `POST /admin-contab/payments/{id}/retention-attachment` | `role:superadmin,lider_admin_contab,asistente_administrativa` — 422 si `payment.metodo_pago != retencion_impuestos` | REQ-525 |

`retention-attachment` es multipart (`file`), upsert (crea o reemplaza), sin guard de estado del
arqueo — funciona sobre un pago de cualquier arqueo, cerrado o no (REQ-525 RN4).

## Contrato JSON exacto (Backend Dev y Frontend Dev implementan contra esto)

```jsonc
// GET /cash-position/daily-count (arqueo ACTIVO, no necesariamente hoy)
{
  "id": 7, "numero": null, "estado": "abierto",
  "fecha": "2026-08-24", "es_atrasado": true, "fecha_real_hoy": "2026-08-26",
  "cobros": [ /* igual a Batch 18 */ ],
  "notas_credito": [ /* igual a Batch 18 */ ],
  "retenciones": [
    { "payment_id": 88, "cliente": "Constructora del Istmo", "referencia": "F-4392",
      "motivo": "Retención 2% ISR sobre servicios", "monto": 3450.00,
      "constancia": null /* o { "nombre_archivo": "...", "url": "...", "uploaded_at": "..." } */ }
  ],
  "total_cobrado": 27350.00, "total_notas_credito": 500.00, "total_neto": 26850.00,
  "observacion_general": null,
  "cerrado_por": null, "cerrado_at": null, "aprobado_por": null, "aprobado_at": null
}

// POST /cash-position/daily-count/close  -> 200, mismo shape de arriba con estado=pendiente_aprobacion
// 409 {"message": "..."} si ya no está abierto (doble click / carrera)

// GET /cash-position/history?page=1
{
  "data": [
    { "id": 5, "numero": 2, "fecha": "2026-07-02", "total_neto": 18420.00,
      "estado": "aprobado", "aprobado_por": "Aprobador", "aprobado_at": "2026-07-03T09:10:00-05:00",
      "realizado_por": "Asistente Ríos" }
  ],
  "meta": { "current_page": 1, "last_page": 3 },
  "pendientes_aprobacion": 2
}

// GET /cash-position/history/{id}  -> mismo shape que GET .../daily-count (punto de arriba)
// POST /cash-position/history/{id}/approve  -> 200, estado=aprobado + aprobado_por/aprobado_at
// 409 si estado != pendiente_aprobacion

// GET /cash-position/{daily-count/export | history/{id}/export}  -> PDF binario, mismo patrón
// que AdminContPaymentController::pdf() (dompdf + Blade), nunca <svg> inline (usar <img
// data:...;base64> para el logo — mismo gotcha ya documentado en memoria del proyecto)

// POST /admin-contab/payments/{id}/retention-attachment  (multipart, campo "file")
// -> 201 { "nombre_archivo": "...", "url": "...", "uploaded_at": "..." }
// -> 422 si metodo_pago != retencion_impuestos
```

## Servicios nuevos/extendidos

- `DailyCashCountService` — agrega `activeDate()`, `active()`, `close(int $actorId)`,
  `history(int $page)`, `detail(int $id)`, `approve(int $id, int $approverUserId)`. `forDate()` es el
  refactor de la lógica ya existente de `today()`, sin cambios de comportamiento.
- `AdminContPaymentRetentionAttachmentService` — nuevo, clon con reemplazo real de
  `AdminContPaymentAttachmentService`.
- `DailyCashCountSummaryPdfService` (o método nuevo reusando el servicio de export ya usado por
  `CashPositionService`) — genera el PDF de REQ-529 vía Blade+dompdf, plantilla nueva
  `resources/views/admin-cont/daily-cash-count-summary-pdf.blade.php`.
- `DailyCashCountStateException` — nueva, mismo patrón que `CreditNoteStateException`.

## Frontend

Misma página `ArqueoCajaPage.tsx` (ya existe desde Batch 18) — agrega: sección "Ajustes /
Retención" (condicional, RN1 REQ-525), banner de atrasado (`es_atrasado`), botón "Cerrar arqueo del
día" con modal de confirmación (número+fecha+total neto+aviso de retención pendiente), panel
"Historial de arqueos cerrados" con contador de pendientes y botón "Ver" → detalle de solo lectura,
botón "Aprobar" visible solo si el actor es Aprobador (`flags`/gate del JWT, mismo criterio que otros
botones `mark_only` del frontend, ej. decisión de Notas de Crédito), botón "Descargar resumen" en
ambos (activo e historial).

## No arrancar sin aprobación de Luis

Mismo criterio que Batch 18 y el resto de Admin&Cont — este documento es el diseño, Luis lo aprueba
o ajusta antes de que el PM desglose tareas y despache a Backend/Frontend Dev. Punto 9 (gate de
`/daily-count/export` vs `/history/{id}/export`) es la única decisión con más de una lectura
razonable — el resto son reconciliaciones sin ambigüedad real.
