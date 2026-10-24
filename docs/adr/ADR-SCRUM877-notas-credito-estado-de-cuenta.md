# ADR — Batch D: Notas de crédito y anulaciones en el Estado de Cuenta (SCRUM-877 parte 1)

Arquitecto, 2026-09-09. Corrección reportada por Gerencia Test. Spec:
`docs/specs/scrum-875-880-correcciones-admincont-analista-spec.md`. Decisiones D-1…D-6 cerradas
con Luis.

## Problema

`AccountStatementService::movements()` construye filas solo de facturas (Débito) y cobros
confirmados (Crédito). Las notas de crédito (`AdminContCreditNote`, módulo que **ya existe** desde
Batch 10-13) no se reflejan, aunque `CreditNoteService::applyToBalance()` sí mueve
`invoice.saldo_aplicado`. El saldo del Estado de Cuenta queda sobrestimado.

## Decisión

### 1. Filas nuevas en `movements()` — notas de crédito aplicadas

Además de facturas y cobros, `movements()` incorpora cada `AdminContCreditNote` con
`estado = ESTADO_APLICADA` cuya `factura_origen` pertenece al cliente (y proyecto si hay filtro).

**Monto del crédito por caso** (decisión D-1/D-4/D-6):

| tipo / resultado | Crédito |
|---|---|
| `descuento_comercial` (cualquier resultado) | `note.monto` completo |
| `devolucion_mercancia` + `RESULTADO_DEVUELTO` | `min(note.monto, saldo_pendiente_al_aplicarse)` — solo la parte que redujo la factura |
| `devolucion_mercancia` + `RESULTADO_APLICADO_SALDO` / `RESULTADO_SALDO_FAVOR` | `note.monto` completo |
| cualquier tipo + `RESULTADO_SALDO_FAVOR` | `note.monto` completo (el saldo puede quedar a favor) |

Para el caso "solo la parte que redujo la factura", el monto que efectivamente bajó el saldo es lo
que `applyToBalance()` sumó a `saldo_aplicado`: `min(note.monto, monto - saldo_aplicado_previo)`.
Como no persistimos "saldo pendiente al momento de aplicar", se recalcula de forma equivalente:
`min(note.monto, note.facturaOrigen.monto)` acota el peor caso, pero lo correcto es
`min(note.monto, saldo_pendiente)` donde `saldo_pendiente` se deriva del estado de la factura
**excluyendo** el efecto de esta misma nota. **Decisión de implementación:** para devolución con
excedente devuelto, usar `note.subtotal + note.itbms` limitado a `note.facturaOrigen.monto` — o,
más simple y exacto, guardar el "monto aplicado a saldo" en la nota al momento de `applyToBalance()`
(columna nueva `monto_aplicado_saldo` en `credit_notes`, aditiva). **El Backend elige** entre
recálculo o columna nueva en Senior Review; preferencia: columna nueva (exacto, sin recálculo
frágil).

- **Fecha de la fila** (D-3): `note.fecha_decision ?? note.fecha`. Mismo criterio que
  `DailyCashCountService::creditNotesReturnedOn()`.
- **Columna "N° Factura"**: `note.facturaOrigen.numero`. **"Concepto"**: "Descuento" / "Devolución"
  / "Anulación" según `tipo`. **"N° Cotización"**: la de la factura de origen. **Guía**: "—".
- Solo `ESTADO_APLICADA` (D-2). `pendiente_aprobacion` y `rechazada` no aparecen.

### 2. Facturas anuladas (decisión D-5)

`movements()` deja de excluir `whereNull('anulada_at')` para las facturas. Cada factura anulada
produce:
- La fila **Factura** normal (Débito por `monto`, fecha de emisión).
- Una fila **Anulación** (Crédito por el mismo `monto`).
  - Fecha: la de la nota de crédito `anulacion_completa` asociada (`fecha_decision ?? fecha`). Si
    no hay nota asociada (datos viejos / anulación pre-Batch 12): usar `invoice.anulada_at`.
  - Se resuelve la nota asociada por `credit_notes.factura_origen_id = invoice.id AND tipo =
    ANULACION_COMPLETA` (la más reciente `aplicada`).
- Efecto neto en el saldo corrido: **cero** para esa factura.
- La factura de reemplazo de "Corrección de datos" entra como una factura más (Débito normal).

### 3. `detail()` / `contextNote()`

- `saldo` sigue siendo `totalDebito - totalCredito` de los movimientos — ahora incluye las notas.
- `contextNote()`: la rama "Cuenta completada y pagada en su totalidad" (`$saldo === 0.0`, hoy con
  comentario "nunca true todavía porque Notas de Crédito no existe") ahora puede darse — quitar el
  comentario, dejar el texto.

### 4. PDF (`account-statement-pdf.blade.php`)

Las mismas filas nuevas (Descuento / Devolución / Anulación) aparecen en la tabla del PDF con el
saldo corrido recalculado. El blade ya itera `$detail['movimientos']` — con el shape de fila
consistente (mismo `tipo`/`numero_factura`/`debito`/`credito`/`saldo`), el cambio en el blade es
solo el label de "Concepto" para los tipos nuevos (`factura`/`cobro` → agregar `descuento`/
`devolucion`/`anulacion`).

### 5. Frontend (`EstadoCuentaPage.tsx`)

- `MovementsTable`: mismo shape de fila, agregar los labels de concepto nuevos y el color de la
  celda de crédito (ya usa `text-primary-dark` para crédito > 0).
- La alineación del subtotal (`colSpan`) es SCRUM-877 parte 2 / Batch A — **no** se hace acá,
  pero D hereda A (A va primero).

## Reconciliaciones

- **No tocar `AdminContCustomerCreditBalance`** (ledger de saldo a favor acumulado). D solo suma
  las notas que afectan facturas del cliente; no rehace el modelo de saldo a favor. Si esto genera
  una discrepancia entre "saldo a favor mostrado en el Estado de Cuenta" y "saldo a favor real del
  cliente en el ledger", es aceptable para este batch (el Estado de Cuenta es por
  cliente/proyecto y cronológico; el ledger es un total). Documentar como known-gap.
- **Doble conteo con Batch C:** post-876, la `factura_origen` de una nota es la de la raíz del
  pedido — la nota se cruza contra esa. D va **después** de C por esto.
- **`movements()` hoy filtra por `master_client_id`** en las facturas; las notas se filtran igual
  vía `factura_origen.master_client_id` (o `note.master_client_id`, que la nota ya copia).

## Consecuencias

- **Positivo:** el saldo del Estado de Cuenta pasa a ser confiable; consistencia total con el
  módulo de Notas de Crédito.
- **Riesgo:** doble contar si una nota `RESULTADO_DEVUELTO` ya generó un `bank_movement` que
  aparece en otro lado — pero el Estado de Cuenta no lee `bank_movements`, así que no hay solape.
- **Riesgo:** el recálculo de "monto aplicado a saldo" para devoluciones con excedente. Mitigación:
  columna nueva `credit_notes.monto_aplicado_saldo` (aditiva, poblada en `applyToBalance()` +
  backfill de las notas existentes desde su `factura_origen`).
- **Test:** casos de cada tipo × resultado, factura anulada con y sin nota asociada, filtro de
  fechas, cliente sin notas (sin regresión). Backend + vitest + verificación del PDF.

## Estado
Implemented: **no** — pendiente de PM breakdown. Va después de Batch C (y hereda Batch A).
