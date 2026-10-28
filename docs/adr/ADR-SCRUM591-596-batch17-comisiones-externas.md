# ADR-SCRUM591-596 — Batch 17: Comisiones Externas (continuación)

**Status:** Implemented (2026-08-25)
**Épica:** SCRUM-331 (Admin&Cont), área funcional Comisiones Externas (12 tickets, Batch 16+17)
**Tickets de este batch:** SCRUM-591→596 / REQ-514→519

Continuación directa de Batch 16 (`ADR-SCRUM585-590-batch16-comisiones-externas.md`) — mismo
motor (`ArchitectCommissionService`), mismo modelo overlay (`ArchitectCommission`). Este batch
agrega lo que Batch 16 dejó explícitamente diferido en su §7: comprobante de retención, "Marcar
como pagado" real, % de comisión por proyecto (propuesta + aprobación de Aprobador), cuenta de pago
editable, recordatorio por correo al arquitecto, y el modal de detalle que consolida todo.

## 1. Modelo de datos — extensión de `architect_commissions` (migración `2026_08_25_100009`)

```sql
-- Comprobante de retención (REQ-514) — mismas columnas/patrón que cuenta_cobro_* (Batch 16), la
-- sube Atlantic (no el arquitecto), mecanismo de storage idéntico.
comprobante_retencion_storage_key, comprobante_retencion_nombre_archivo,
comprobante_retencion_size_bytes, comprobante_retencion_mime_type,
comprobante_retencion_uploaded_at, comprobante_retencion_uploaded_by (FK users)

-- Cuenta de pago (REQ-517) — FK real, mismo schema admin_contab, mismo criterio que
-- bank_movements.bank_account_id. NULL = usar el default resuelto en runtime ("Banco General —
-- Cuenta Corriente", mismo query que PaymentService::defaultBankAccountFor()) — nunca se siembra
-- un valor fijo acá porque esa cuenta puede no existir todavía en un tenant nuevo.
bank_account_id (FK admin_contab.bank_accounts, nullOnDelete)

-- % de comisión por proyecto (REQ-516) — el % base sigue siendo el default global de
-- fiscal_settings (Batch 16) hasta que exista una propuesta aprobada; nunca se sobreescribe el
-- default, es un override puntual por proyecto.
porcentaje_aprobado, porcentaje_pendiente, porcentaje_pendiente_motivo,
porcentaje_pendiente_propuesto_por (FK users), porcentaje_pendiente_propuesto_at
```

`porcentaje_pendiente_motivo` de un RECHAZO nunca se persiste (mismo criterio que el mockup del
ticket, que tampoco lo guarda) — el motivo es para que Aprobador lo vea en el momento de decidir, no
queda como historial. `decidirPorcentaje()` limpia los 4 campos `porcentaje_pendiente*` tanto en
aprobación como en rechazo; en rechazo `porcentaje_aprobado` nunca se toca, el % vigente vuelve
exactamente al valor anterior porque no se modificó.

## 2. Cadena de validación de "Marcar como pagado" (REQ-515 RN1/RN2)

`ArchitectCommissionService::marcarComoPagada()` — mismo orden exacto que `marcarPagado()` del
mockup del ticket (única fuente de verdad de la secuencia, no está escrito así en el texto plano
del REQ):

1. Proyecto encontrado y en estado `pendiente_factura_arquitecto` (cualquier otro estado, incluida
   `pagada` — no es un no-op, es error explícito).
2. Sin % pendiente de aprobación de Aprobador.
3. Datos fiscales del arquitecto completos.
4. Cuenta de cobro subida.
5. Si el régimen es "Retención del 50%": comprobante de retención subido.

Cada falla lanza `ArchitectCommissionStateException` con un mensaje específico en español — el
frontend repite el mismo chequeo client-side antes de habilitar el botón (para no ofrecer una
acción que va a fallar), pero la autoridad real es siempre el backend, nunca confía en que el
cliente ya validó. `fecha_pago = now()` — RN5, cae en el mes calendario actual para "Pagado este
mes" (REQ-508).

## 3. % de comisión por proyecto (REQ-516)

- **Proponer** (`proponerPorcentaje`, RN3): cualquiera del roster amplio (Contabilidad/Asistente/Aprobador/
  Gerencia) propone un % + motivo obligatorio para ESTE proyecto puntual. El % vigente
  (`porcentaje` en la respuesta de `summary()`) sigue siendo el actual — aprobado o default —
  hasta que Aprobador decida; nunca se aplica de inmediato. Bloqueado si la comisión ya está pagada, o
  si ya hay una propuesta pendiente sin resolver (RN de "una a la vez").
- **Decidir** (`decidirPorcentaje`, RN4): exclusivo de Aprobador (`mark_only`, mismo middleware que
  Configuración Fiscal). Aprobar copia `porcentaje_pendiente` → `porcentaje_aprobado` (aplica de
  inmediato al cálculo de `montoFor()`). Rechazar solo descarta la propuesta, ver §1.
- **`puede_decidir_porcentaje`** en la respuesta de `summary()` — calculado por actor (JWT sub ===
  `mark_approver_user_id`, `superadmin.all` bypassa), mismo criterio ya establecido en
  `AdminContInternalCommissionController::isMarkApprover()` y
  `AdminContInvoiceController::detail()`. El frontend nunca decide por su cuenta quién es Aprobador; el
  endpoint real (`percent/decide`) queda gateado por `mark_only` de todos modos, este flag es solo
  para no ofrecer Aprobar/Rechazar a quien igual recibiría 403.

## 4. Permisos — 2 grupos nuevos, mismo criterio de Batch 16 §5

- **Marcar pagado, proponer %, recordar** (REQ-515/516 RN3/518) — roster amplio
  `role:superadmin,lider_admin_contab,asistente_administrativa,management` (Contabilidad/Asistente/Aprobador/
  Gerencia), el REQ lo dice explícito para las 3 acciones.
- **Comprobante de retención, cuenta de pago** (REQ-514/517) — roster angosto
  `role:superadmin,lider_admin_contab,asistente_administrativa` (mismo grupo que la cuenta de
  cobro de Batch 16, Aprobador recibe 403).
- **Decidir %** (REQ-516 RN4) — `mark_only` exclusivo.
- Lectura nueva (`GET bank-accounts`, `GET .../comprobante-retencion`) — mismo
  `permission:admin_contab.read` que el resto de las lecturas del módulo.

## 5. Modal de detalle (REQ-519)

`ArchitectCommissionDetailModal.tsx` (frontend) consolida en una sola vista lo que Batch 16 dejó
disperso en la fila expandida más las acciones nuevas: cliente/monto/cobro, % de comisión con
"Editar %" (propone) y el banner de propuesta pendiente (con Aprobar/Rechazar solo si
`puede_decidir_porcentaje`), comisión/impuesto/total (o banner "faltan datos fiscales" con acceso
directo al modal de datos fiscales de Batch 16), estado, RUC, cuenta de pago (select editable
mientras no esté pagada), cuenta de cobro y comprobante de retención (este último solo visible con
régimen retención_50), y los botones Recordar/Marcar como pagado. Sin lógica de negocio propia —
consolida REQ-508→518, cada campo/acción ya vive en `summary()`/los endpoints de este batch.

## 6. Hallazgo real corregido durante implementación — caché de `allProjects()` obsoleto entre requests

La primera versión de `ArchitectCommissionService::allProjects()` cacheaba el resultado en una
propiedad de instancia (`$allProjectsCache`), pensado como optimización válida solo dentro de un
mismo request real. Un test de Feature que llamaba dos veces seguidas a `percent/propose` en el
mismo método (para verificar que la segunda propuesta se rechaza) reveló que
`Illuminate\Routing\Route::getController()` cachea la instancia del controller en el propio objeto
`Route`, que sobrevive entre llamadas `postJson()` dentro de un mismo test de Feature — dos
"requests" simuladas terminan compartiendo la misma instancia de `ArchitectCommissionService` y,
con ella, un `allProjects()` obsoleto justo para los métodos de escritura nuevos de este batch
(`proponerPorcentaje`, `marcarComoPagada`, `actualizarCuentaPago`, `enviarRecordatorio`) que
necesitan leer el estado más reciente. En producción real esto no ocurre (cada request HTTP es un
proceso PHP-FPM nuevo), pero es un riesgo latente de diseño — corregido eliminando el caché de
instancia por completo (`allProjects()` siempre recalcula); el costo es una query extra por acción
de escritura, aceptable frente al riesgo de una validación silenciosamente obsoleta.
