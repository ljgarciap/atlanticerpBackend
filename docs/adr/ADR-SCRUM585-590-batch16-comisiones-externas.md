# ADR-SCRUM585-590 — Batch 16: Comisiones Externas (arquitectos)

**Status:** Implemented (2026-08-25)
**Épica:** SCRUM-331 (Admin&Cont), área funcional Comisiones Externas (12 tickets, Batch 16+17)
**Tickets de este batch:** SCRUM-585→590 / REQ-508→513

## 1. Decisiones de diseño confirmadas con Luis antes de implementar

Dos vacíos reales en el modelo de datos existente, sin precedente directo — confirmados
explícitamente antes de escribir migraciones:

1. **Datos fiscales del arquitecto (empresa/RUC/régimen, REQ-510) → tabla nueva en
   `admin_contab`**, no columnas agregadas a `ventas_diseno.architects`. Mismo criterio ya usado
   para el régimen fiscal del CLIENTE (`AdminContClientBillingProfile`, Batch 8) — mantiene lo
   fiscal dentro de Admin&Cont, sin acoplar el módulo Ventas&Diseño a un concepto que no es suyo.
   A diferencia de `client_billing_profiles`, **sin default razonable** (`forMasterClient()`): el
   régimen del cliente tiene un default operativo (Con ITBMS/Neto 30); el del arquitecto es
   información real que hay que pedirle — la ausencia de fila ES la señal de "faltan datos
   fiscales" (RN4 REQ-510), nunca se asume un régimen.

2. **Vínculo proyecto↔arquitecto (REQ-508 RN1) → `ventas_diseno.quotes.architect_id`** (la
   cotización más reciente del `sales_project` con `architect_id` no nulo), no una columna nueva
   en `sales_projects`/`pipeline_cards`. Es el único lugar del sistema donde ya se captura hoy
   "quién refirió este proyecto" — reusarlo evita una migración nueva en Ventas&Diseño y una UI de
   asignación que ningún ticket de este batch pide. Limitación conocida y aceptada: si un
   `sales_project` tiene cotizaciones de más de un arquitecto, se atribuye a la más reciente con
   `architect_id` — no hay ningún REQ que contemple explícitamente ese caso.

## 2. Modelo de datos nuevo (schema `{tenant}_admin_contab`)

```sql
architect_fiscal_profiles
  id, architect_id (FK suelta cross-schema → ventas_diseno.architects, unique), empresa, ruc,
  regimen_fiscal (exento|con_itbms|retencion_50), created_at, updated_at

architect_commissions             -- overlay persistido, NUNCA creado a mano (RN1 REQ-508) — se
  id, architect_id,               -- crea/actualiza (upsert) como efecto colateral de subir la
  pipeline_card_id (unique),      -- cuenta de cobro (REQ-513). Mientras eso no pase, el proyecto
  cuenta_cobro_storage_key,       -- se calcula igual en vivo (join, ver §3) simplemente sin fila.
  cuenta_cobro_nombre_archivo,
  cuenta_cobro_size_bytes,
  cuenta_cobro_mime_type,
  cuenta_cobro_uploaded_at,
  cuenta_cobro_uploaded_by (FK users),
  fecha_pago,                     -- ya modelado aunque la ACCIÓN de marcarla (REQ-515) es Batch 17
  created_at, updated_at
```

**Diferencia real con Comisiones Internas** (`CommissionOrderService`, Batch 14/15): acá SÍ hace
falta esta tabla porque hay estado que no se puede derivar de ningún otro dato (documento adjunto,
fecha de pago manual). No hay tiered %, así que tampoco hace falta ningún snapshot de mes
cerrado — REQ-511 RN1 es 10% fijo en este batch (el override por proyecto de REQ-514 llega en
Batch 17, columna a agregar entonces, mismo criterio incremental que `compartido_con` en Internas).

## 3. Motor de cálculo — `ArchitectCommissionService`

Deliberadamente independiente de `CommissionOrderService` (mismo criterio ya establecido ahí para
no forzar reuso entre motores de comisión de distinta audiencia/módulo). "Proyecto referido
comisionable" tampoco es una tabla propia: join de solo lectura `ventas_diseno.pipeline_cards`
(stage=approved) → `bodega.orders` → `admin_contab.invoices` (+ `admin_contab.payments` para
"cobro 100%"), filtrado a proyectos con arquitecto resuelto (§1.2), left-join con
`architect_commissions` para la cuenta de cobro/fecha de pago.

- **Estado (REQ-512):** `aun_no_generada` (cobro <100%, todo o nada — RN1) →
  `pendiente_factura_arquitecto` (cobro 100%, sin `fecha_pago`) → `pagada` (con `fecha_pago`,
  RN3: siempre manual, nunca automático a diferencia de Internas).
- **Monto (REQ-511):** comisión = monto_proyecto × 10%. Exento → impuesto 0. Con ITBMS → 7%
  completo. Retención del 50% → 3.5% efectivo (mitad del ITBMS, una sola línea de impuesto neto,
  nunca 2 separadas — RN2). Sin régimen → impuesto/total `null`, nunca se asume Exento (RN6).
- **Tarjetas (REQ-508):** "Comisión generada" = pagada + pendiente_factura (Escenario 1 del
  REQ, verificado por test). "Aún no generada" NO exige datos fiscales completos (RN4) — es un
  estimado en potencia, se suma por `comisión` base aunque falte el régimen. Las otras 3 SÍ exigen
  datos fiscales completos (RN2) — un arquitecto sin régimen no suma ahí, pero sigue apareciendo
  en la tabla con el aviso "Completar datos fiscales" (RN4 REQ-510).
- **Filtros (REQ-509):** búsqueda/mes/arquitecto/estado combinados AND; un arquitecto sin ningún
  proyecto que matchee se oculta por completo de la tabla (RN3).

## 4. Cuenta de cobro (REQ-513)

Sin tabla de adjuntos propia (a diferencia de `AdminContPaymentAttachment`) — la fila vive directo
en `architect_commissions`, porque RN3 es explícita: a lo sumo 1 vigente por proyecto
("Reemplazar", no una lista histórica). Mismo disco privado (`s3-private`), mismo TTL de URL
firmada (15 min) que el resto del módulo. El archivo viejo se borra del disco recién después de
confirmar el nuevo en BD, para no dejar el proyecto sin cuenta de cobro si el upload falla a mitad
de camino.

## 5. Permisos

Lectura (`GET /`, `/architects`, `/projects/{id}/cuenta-cobro`): `permission:admin_contab.read` —
a diferencia de Comisiones Internas, la audiencia de este batch es exclusivamente
Contabilidad/Asistente/Aprobador/Gerencia (REQ-508→512, ningún vendedor ni arquitecto la consulta), así que
`role_module_visibility` ya cubre a los 4 sin permiso angosto adicional (mismo criterio que
`bank-accounts` GET).

Escritura, 2 grupos separados a propósito porque los REQ difieren:
- Datos fiscales (REQ-510, "Contabilidad y Asistente **y Aprobador/Gerencia si lo necesitan**" — texto explícito
  del REQ): `role:superadmin,lider_admin_contab,asistente_administrativa,management`.
- Cuenta de cobro (REQ-513, "Disponible para Contabilidad y Asistente" **sin** el carve-out de Aprobador):
  `role:superadmin,lider_admin_contab,asistente_administrativa` — Aprobador recibe 403 si lo intenta,
  verificado con test dedicado.

## 6. Senior Review — 2 hallazgos de reuso corregidos antes de Dev Testing

- **Tasa de ITBMS:** la primera versión hardcodeaba `ITBMS_RATE = 0.07` como constante privada del
  servicio. `ItbmsRateResolver::defaultPercentage()` (REQ-480, Batch 10 de Notas Crédito) ya existe
  para exactamente esto — su propio docblock lista "Comisiones Externas" como consumidor previsto.
  Corregido: `montoFor()` resuelve la tasa base ahí (7.0 hoy, catálogo `admin_contab.itbms_rates`).
  RN5 (retención del 50%) se calcula como la mitad de ESA misma tasa, no como una fila separada del
  catálogo — sigue el texto literal del REQ y se mantiene correcto si la tasa base cambia.
- **% de comisión por defecto:** la primera versión también hardcodeaba `PORCENTAJE_DEFAULT = 10.0`.
  Mismo criterio ya usado para `mark_approval_threshold` (migración `2026_08_24_100002`, cuyo propio
  docblock ya anticipaba explícitamente "Comisiones Externas... todavía no implementada"): se agregó
  `comision_externa_default_percent` al singleton `AdminContFiscalSettings` (migración
  `2026_08_25_100008`) en vez de una constante — regla dura del workspace contra hardcodear
  porcentajes de negocio. Sin pantalla de edición propia todavía (mismo patrón que
  `client_billing_profiles`) — el override real con aprobación de Aprobador es REQ-514 (Batch 17).

## 7. Fuera de alcance de este batch (Batch 17, SCRUM-591→596)

- REQ-514 — editar el % de comisión por proyecto (con aprobación de Aprobador) — columna
  `porcentaje_override` a agregar entonces, mismo patrón incremental que `compartido_con`.
- REQ-515 — acción real de "marcar como pagada" (`fecha_pago`, columna ya modelada acá).
- Comprobante de retención (régimen 50%), cuenta de pago editable, recordatorio al arquitecto,
  tabla expandir + modal de detalle.
