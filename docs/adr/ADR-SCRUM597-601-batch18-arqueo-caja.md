# ADR — Batch 18: Arqueo / Flujo de Caja, parte 1 (SCRUM-597→601, REQ-520→524)

Arquitecto, 2026-08-25. Primer batch del grupo "Arqueo / Flujo de Caja" (ver
`docs/architecture/admin-contab-batches.md`). Mockup: `4G__Admin_Contabilidad_ArqueoCaja.html`
(adjunto en los 5 tickets) — cubre este batch **y** Batch 19 (cerrar arqueo, cola de atrasados,
historial+aprobación Gerencia, constancia de retención) en un solo archivo; este batch implementa
solo lo que piden REQ-520→524, no lo que aparece en el mockup pero pertenece a REQ-525→529.

## Alcance de los 5 tickets
- REQ-520: encabezado, 4 tarjetas (saldo disponible hoy / entradas proyectadas / salidas
  proyectadas / saldo proyectado), exportar PDF/Excel (oculto en Vista real + Hoy).
- REQ-521: vista proyectada — 2 columnas (entradas desde Facturación, salidas desde Comisiones +
  Notas Crédito), flujo neto.
- REQ-522: toggle Proyectado/Real + chips Hoy/30/90 días. Default: Proyectado + 30 días.
- REQ-523: vista real (histórico) — tabla cronológica 30/90 días con saldo acumulado.
- REQ-524: arqueo del día (Real + Hoy) — cobros confirmados + notas crédito devueltas de hoy,
  observación editable por fila + observación general, totales.

Fuera de alcance (Batch 19, REQ-525→529): cerrar arqueo, cola secuencial de atrasados, historial
de arqueos cerrados, aprobación de Gerencia, constancia de retención del cliente.

## Reconciliaciones spec vs. código existente

**1. Caja Chica no existe todavía (Batch 20-21, posterior en el plan).** RN1 de REQ-520 exige
sumar el saldo de caja chica al "Saldo disponible hoy". Resuelto agregando `petty_cash_balance`
(decimal, default 0) a `admin_contab.fiscal_settings` — mismo patrón ya usado para
`comision_externa_default_percent` (Batch 16): un valor de configuración editable por Mark
(`mark_only`, mismo endpoint `PUT /fiscal-settings`), nunca hardcodeado. Cuando Batch 20 construya
el ledger real de Caja Chica, este campo se reemplaza por el saldo calculado — documentado acá para
que esa sesión no lo redescubra desde cero.

**2. Saldo bancario no tiene columna persistida.** `AdminContBankAccount` nunca tuvo `saldo` — el
ledger fundacional `admin_contab.bank_movements` (creado en Batch 1, "vacío a propósito hasta que
Cobros/Notas Crédito/Comisiones lo alimentaran") ya es la fuente de verdad real desde Batch 5
(Cobros) y Batch 10 (Notas Crédito), que sí insertan en él. Saldo de una cuenta activa = suma de
`monto` donde `tipo=cobro` menos suma donde `tipo IN (devolucion, comision)`, calculado en vivo,
sin columna nueva.

**3. `bank_movements` no tiene trazabilidad hacia el registro de origen.** Necesaria para que el
arqueo del día (REQ-524) pueda mostrar el monto exacto de una "devolución" que ya se aplicó
(`CreditNoteService::applyToBalance()` solo registra el *excedente*, no el monto completo de la
nota — ver más abajo). Se agregan `source_type` (`payment|credit_note|architect_commission`,
nullable) y `source_id` (nullable) a `bank_movements` vía migración aditiva. `PaymentService`
(2 sitios) y `CreditNoteService::applyToBalance()` pasan a setearlos al crear el movimiento — sin
cambiar ningún comportamiento existente, solo trazabilidad nueva.

**4. Comisiones internas nunca generan un movimiento de banco real.** `CommissionOrderService`
liquida el "pagado" de forma automática por cierre de mes (día 1-5, RN5 de REQ-501) sin capturar
ninguna cuenta bancaria — es un aviso, la liquidación real ocurre en planilla, fuera del sistema.
Por eso las comisiones internas **nunca aparecen en la Vista real (histórico)** de este batch —
solo en la Vista proyectada, como salida esperada mientras están `por_pagar`. Esto es intencional,
no un gap: no hay ningún dato real de "comisión interna pagada por banco X" que mostrar.

**5. Comisiones externas (arquitectos) sí tienen cuenta de pago (`bank_account_id` en
`architect_commissions`, Batch 17) y una acción explícita de pago
(`ArchitectCommissionService::marcarComoPagada()`).** Ese método nunca insertó en
`bank_movements` porque hasta este batch nada lo necesitaba. Se agrega el insert (tipo=`comision`,
`source_type`=`architect_commission`) dentro de esa misma transacción, solo si el proyecto tiene
`bank_account_id` asignado (siempre lo tiene para llegar a "Pagada", ver validación en cadena de
REQ-515) — esto es lo que alimenta tanto la Vista real (histórico) como el `TIPO_COMISION` que la
migración de `bank_movements` ya anticipaba desde Batch 1.

**6. "Notas de crédito con resultado 'devolver dinero' aún sin procesar" (RN2 REQ-521).** El
campo `resultado=devuelto` solo puede ocurrir cuando `monto > saldo_pendiente_factura` (ver
`RegisterCreditNoteRequest`/`CreditNoteService::register()` — si no hay excedente, `resultado` se
fuerza a `aplicado_saldo`). "Aún no procesada" = `estado=pendiente_aprobacion` (esperando decisión
de Mark); una vez `estado=aplicada`, ya generó su `bank_movements` real y pasa a la Vista real, no
a la proyectada. El monto proyectado se calcula igual que `applyToBalance()`:
`excedente = max(0, monto_nota - (factura.monto - factura.saldo_aplicado))` sobre el estado
ACTUAL de la factura — solo para la proyección, nunca persistido.

**7. "Fecha estimada" de comisiones/notas para atrasado vs. próximo (RN3 REQ-521) — ajustado tras
revisar el mockup real.** El texto del REQ pide el indicador en "cada elemento de ambas columnas",
pero el propio mockup (`salidasEsperadas`) nunca le pone `dias`/tag a las filas de comisión
(interna agregada NI externa individual) — solo a las notas de crédito. Las comisiones no tienen
una fecha de vencimiento natural (la interna ya es un agregado por cierre de mes; la externa
depende de una acción — "Pendiente de factura del arquitecto" — no de una fecha). Implementado
según el mockup, no según la lectura literal del RN: comisiones (ambos tipos) siempre
`dias: null, vencimiento: null`, y por eso mismo SIEMPRE aparecen sin importar la ventana elegida
(mismo criterio que el propio filtro del mockup: `dias === null` pasa cualquier ventana). Notas de
crédito pendientes sí llevan fecha real: `fecha` (fecha de registro — no hay una "fecha esperada"
distinta capturada en ningún REQ). "Atrasado" = esa fecha ya pasó al momento de evaluar.
`ArchitectCommissionService::allProjects()` igual expone `fecha_cobro_completo` ahora (se agregó
al mapear la fila, antes se seleccionaba en SQL pero se descartaba) por si un batch futuro
necesita esa fecha — no lo usa esta implementación.

**8. REQ-521/REQ-523 (Vista proyectada / Vista real 30-90d) NO están disponibles para Yaneth** —
el REQ lo dice explícito ("Visible para Felix, Mark y Gerencia", sin Yaneth) — a diferencia de
REQ-520/522 (encabezado + toggle, sí incluyen a Yaneth) y REQ-524 (arqueo del día, exclusivo
Felix+Yaneth, sin Mark/Gerencia — mismo patrón ya usado en Batch 17 para `cuenta-cobro`/
`cuenta-pago`, "Disponible para Felix y Yaneth" sin el carve-out estándar). Contradicción real del
default de RN1 (Proyectado+30d) para una usuaria que no puede ver Proyectado: resuelto en el
frontend — Yaneth nunca aterriza en una combinación a la que no tiene acceso, su default es
Real+Hoy (Arqueo del día, lo único a lo que tiene acceso completo); las opciones Proyectado/Real
30-90d simplemente no se ofrecen para su rol (evita un 403 en vez de ocultar tras el hecho).

## Esquema nuevo (`admin_contab`)

```sql
-- Migración aditiva sobre fiscal_settings (mismo patrón que comision_externa_default_percent)
ALTER TABLE fiscal_settings ADD COLUMN petty_cash_balance DECIMAL(12,2) NOT NULL DEFAULT 0;

-- Migración aditiva sobre bank_movements
ALTER TABLE bank_movements ADD COLUMN source_type VARCHAR(255) NULL; -- payment|credit_note|architect_commission
ALTER TABLE bank_movements ADD COLUMN source_id BIGINT UNSIGNED NULL;

daily_cash_counts(
  id, fecha DATE UNIQUE, observacion_general TEXT NULL, created_at, updated_at
)
-- fila creada de forma perezosa (primera vez que se escribe algo para esa fecha). Batch 19 la
-- extiende con numero/estado/cerrado_por/cerrado_at/aprobado_por/aprobado_at.

daily_cash_count_entries(
  id, daily_cash_count_id FK->daily_cash_counts (cascadeOnDelete),
  movement_type VARCHAR(255), -- payment|credit_note
  movement_id BIGINT UNSIGNED,
  observacion TEXT NULL,
  created_at, updated_at,
  UNIQUE(daily_cash_count_id, movement_type, movement_id)
)
```

## Contrato de API (`routes/admin-contab.php`, prefix `cash-position`)

| Método/ruta | Gate | REQ |
|---|---|---|
| `GET /cash-position` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` | REQ-520 |
| `GET /cash-position/export?view=proyectado\|real&window=0\|30\|90` | `role:superadmin,lider_admin_contab,management` (sin Yaneth — ver nota abajo) — 422 si `view=real&window=0` (RN3) | REQ-520 |
| `GET /cash-position/projected?window=0\|30\|90` | `role:superadmin,lider_admin_contab,asistente_administrativa,management` (ver nota abajo) | REQ-521/522 |
| `GET /cash-position/real?window=30\|90` | `role:superadmin,lider_admin_contab,management` (sin Yaneth) | REQ-522/523 |
| `GET /cash-position/daily-count` | `role:superadmin,lider_admin_contab,asistente_administrativa` (sin management) | REQ-524 |
| `PUT /cash-position/daily-count/entries` | ídem | REQ-524 RN2 |
| `PUT /cash-position/daily-count/general-observation` | ídem | REQ-524 RN4 |

`petty_cash_balance` se edita vía el `PUT /fiscal-settings` ya existente (`mark_only`) — solo se le
agrega el campo al request/response, sin ruta nueva.

**Aclarado por Luis 2026-08-25 (Pre-QA Batch 18) — `/projected` y Yaneth.** La implementación
original bloqueaba `/projected` para Yaneth por completo, razonando que REQ-521 ("Visible para
Felix, Mark y Gerencia") cubría todo el endpoint. Pre-QA encontró la contradicción real: REQ-520
("Visible para Felix, Yaneth, Mark y Gerencia") incluye las 3 tarjetas "Entradas/Salidas/Saldo
proyectados" del encabezado, y esas tarjetas se alimentan del mismo endpoint. Luis confirmó la
lectura correcta: REQ-521 veda el **panel de detalle** (facturas/comisiones línea por línea), no
los 3 totales agregados. Resuelto agregando `asistente_administrativa` al gate de `/projected`,
con el controller devolviendo `entradas`/`salidas` vacíos para ese rol (totales siempre calculados
sobre el dato completo) — `/real` y `/export` quedan sin cambios, sin excepción para Yaneth (ningún
REQ le da una versión "solo totales" de esos dos).

## Contrato JSON exacto (Backend Dev y Frontend Dev implementan contra esto, sin improvisar shape)

```jsonc
// GET /cash-position
{
  "saldo_disponible_hoy": 28740.00,
  "saldo_bancos": 28400.00,
  "saldo_caja_menuda": 340.00
}

// GET /cash-position/projected?window=0|30|90
{
  "window_days": 30,
  "entradas": [
    { "nombre": "Hotel Riu — Lobby y piscina", "referencia": "F-4421", "monto": 9800.00,
      "dias": 3, "vencimiento": "proximo" /* atrasado|proximo|null */ }
  ],
  "salidas": [
    { "nombre": "Comisiones internas pendientes", "referencia": "Ver Comisiones Internas",
      "monto": 2209.50, "dias": null, "vencimiento": null, "tipo": "comision_interna" },
    { "nombre": "Comisión externa — Arq. Elena Duarte", "referencia": "Casa Herrera",
      "monto": 1500.00, "dias": null, "vencimiento": null, "tipo": "comision_externa" },
    { "nombre": "Devolución de dinero — Fam. Herrera", "referencia": "NC-0087", "monto": 500.00,
      "dias": 2, "vencimiento": "proximo", "tipo": "nota_credito" }
  ],
  "total_entradas": 27500.00,
  "total_salidas": 15499.50,
  "neto": 12000.50
}

// GET /cash-position/real?window=30|90
{
  "window_days": 30,
  "saldo_actual": 28740.00,
  "movimientos": [
    { "fecha": "2026-06-10", "concepto": "Cobro REC-2098", "origen": "cobro" /* cobro|devolucion|comision */,
      "entrada": 7500.00, "salida": 0, "saldo_acumulado": 21240.00 }
  ]
}

// GET /cash-position/daily-count
{
  "fecha": "2026-08-25",
  "cobros": [
    { "movement_type": "payment", "movement_id": 55, "concepto": "Cobro — Grupo Sensei (REC-2214)",
      "metodo_pago": "transferencia", "monto": 6600.00, "observacion": null }
  ],
  "notas_credito": [
    { "movement_type": "credit_note", "movement_id": 12, "concepto": "Devolución de dinero — Fam. Herrera",
      "monto": 500.00, "observacion": "" }
  ],
  "total_cobrado": 27350.00,
  "total_notas_credito": 500.00,
  "total_neto": 26850.00,
  "observacion_general": null
}

// PUT /cash-position/daily-count/entries   body: {movement_type, movement_id, observacion}
// -> 204, sin body

// PUT /cash-position/daily-count/general-observation   body: {observacion}
// -> 204, sin body

// GET /cash-position/export?view=proyectado|real&window=0|30|90
// -> descarga binaria (PDF o Excel según Accept/format, mismo patrón que InvoiceExportService)
// -> 422 {"message": "..."} si view=real&window=0
```

Montos siempre en `float` con 2 decimales server-side (`round(...,2)`), nunca strings. `dias`
negativo = atrasado (ya vencido), positivo = próximo a vencer, `null` = sin fecha aplicable
(comisiones sin `fecha_cobro_completo` todavía). `vencimiento` es el campo que el frontend usa
para pintar el tag ("Vencido Nd" / "En Nd") — se deriva de `dias`, no lo recalcula el frontend.

## Servicios nuevos

- `CashPositionService` — `header()`, `bankBalance()`, `projected(int $windowDays)`,
  `real(int $windowDays)`, `export(string $view, int $windowDays)`.
- `DailyCashCountService` — `today()`, `updateEntryObservation()`, `updateGeneralObservation()`.

## Frontend

Página nueva bajo Tesorería (mockup: sidebar "Admin. & Contab." → subtab "Tesorería" → "Arqueo de
Caja", antes de "Cuentas bancarias"/"Caja chica" — ambas ya construidas/pendientes respectivamente).
Mismo layout del mockup: 4 tarjetas, toggle+chips, panel de 2 columnas (proyectado) o tabla
(real 30/90 o arqueo del día), sin los elementos de Batch 19 (botón "Cerrar arqueo del día",
"Historial de arqueos cerrados", sección "Ajustes/Retención").
