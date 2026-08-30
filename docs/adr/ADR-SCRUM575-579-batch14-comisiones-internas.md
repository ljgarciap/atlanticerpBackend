# ADR-SCRUM575-579 — Batch 14: Comisiones Internas (encabezado, indicadores, filtros, estado desde Cobros, % escalonado)

**Status:** Approved by Luis (2026-08-25)
**Épica:** SCRUM-331 (Admin&Cont), área funcional Comisiones Internas (10 tickets, Batch 14+15)
**Tickets de este batch:** SCRUM-575→579 / REQ-498→502

## 1. Hallazgo previo — duplicación con REQ-073 (Ventas&Diseño)

Ya existe en producción `App\Modules\VentasDiseno\Services\CommissionService` (+ `CommissionCohort`,
`CommissionsController`, panel `CommissionsPanel.tsx` en Reportes de Ventas&Diseño) — mismo concepto
(comisión escalonada por vendedor/mes), pero:
- tabla de tramos **hardcodeada** en `SalesTotalsService::percentFor()` (corte en $80,000, no
  $81,000 — ambigüedad documentada del Excel original, REQ-073)
- base de cálculo = total del pipeline en stage `approved`, no facturación/cobro real
- "pagada" es un marcado manual (`markPaid()`), no derivado de Cobros

**Decisión de Luis:** los dos coexisten. El panel de Ventas&Diseño (REQ-073) no se toca — sigue
siendo la vista rápida por pipeline. Comisiones Internas de Admin&Cont es la versión fiscalmente
correcta (dirigida por Facturación/Cobros/Notas Crédito reales) y vive como módulo aparte, sin
compartir código con `CommissionService`. La tabla de tramos queda duplicada a propósito (una
hardcodeada en Ventas&Diseño, una paramétrica nueva en Admin&Cont) — deuda conocida y documentada,
no se unifica en este batch.

**Decisión de Luis:** el CRUD de tramos (fuera del alcance de los 5 tickets/mockup, pero exigido
por la regla dura del CLAUDE.md — nunca hardcodear umbrales de negocio) **se incluye en el alcance
de este batch**, no se difiere.

## 2. Modelo de datos nuevo (schema `{tenant}_admin_contab`)

```sql
commission_tiers                      -- TRAMO_COMISION_INTERNA, paramétrica, CRUD superadmin
  id, monto_minimo, monto_maximo (nullable, último tramo), porcentaje, orden, created_at, updated_at
-- Seed inicial = los 6 tramos del mockup: <20000→1.5%, 20001-35000→2%, 35001-60000→3%,
-- 60001-81000→4%, 81001-120000→4.5%, >120000→5%. Deliberadamente DISTINTOS del corte $80,000
-- hardcodeado en VentasDiseno — son dos fuentes de verdad separadas por decisión de Luis (§1).

commission_month_snapshots            -- % y total fijados al cierre — mismo patrón que
  id, vendedor_id, mes_cierre, total_pedidos, porcentaje, monto_comision,   -- CommissionCohort::cohortFor()
  created_at
  unique(vendedor_id, mes_cierre)
-- Se crea una sola vez, la primera vez que se consulta un mes YA cerrado (mes_cierre <
-- mes calendario actual) y no existe snapshot todavía — igual que cohortFor(). El mes en
-- curso NUNCA se persiste, se recalcula en vivo en cada request (RN2 REQ-502, "provisional").
```

**Deliberadamente NO se crea una tabla `commissionable_orders`/PEDIDO_COMISIONABLE.** El Excel la
modela como tabla propia, pero persistirla duplicaría datos que ya viven en 3 schemas distintos
(mismo antipatrón que motivó `AdminContInvoice.saldo_aplicado` a ser la única fuente de
`total_cobrado`, nunca un campo capturado aparte — RN de REQ-501 es explícita sobre esto). En vez
de eso, `CommissionOrderService` arma el "pedido comisionable" en vivo con un join de solo lectura:

```
ventas_diseno.pipeline_cards (stage='approved', owner_id, sales_project_id, amount, stage_changed_at)
  → bodega.orders (pipeline_card_id)
    → admin_contab.invoices (order_id, monto, saldo_aplicado, itbms_rate_id)
      → admin_contab.payment_invoices (para el detalle de qué cobro aportó cuánto, si hace falta)
      → admin_contab.credit_notes (factura_origen_id) — SOLO lectura, NO se resta de la base
        todavía en este batch, ver §3

mes_cierre = mes calendario de pipeline_cards.stage_changed_at cuando stage pasó a 'approved'
total_pedido = pipeline_cards.amount
total_facturado = invoices.monto (si existe factura; null si aún no se facturó)
total_cobrado = invoices.saldo_aplicado (si existe factura; 0 si no)
```

Sin FK cross-schema (Postgres no las permite entre módulos independientes en este proyecto, mismo
criterio que `tickets.internal_technician_id`/`invoices.order_id`) — el join es por query
explícita con nombre de schema calificado, no por relación Eloquent con constraint.

**Estado calculado (REQ-501, RN1-RN5)** — método puro, sin persistencia:
```
si no hay factura                         → pendiente_cobro (sin total_cobrado)
si total_cobrado == 0                     → pendiente_cobro
si 0 < total_cobrado < total_facturado    → pendiente_cobro ("abono parcial", mostrar total_cobrado)
si total_cobrado >= total_facturado:
  si mes_cierre_del_pedido == mes actual (calendario) → por_pagar   (RN3 — ganada, no liquidada)
  si mes_cierre_del_pedido < mes actual                → pagado     (RN4 — ya liquidada, día 1-5)
```
Nota: el estado usa el mes de CIERRE DEL PEDIDO (mes_cierre), no la fecha en que se completó el
cobro — RN5 es explícito ("un pedido cerrado en junio puede seguir Pendiente de cobro en julio").
Interpretación: una vez que un pedido de un mes cerrado alcanza el 100% de cobro, pasa a "pagado"
inmediatamente si ese mes de cierre ya pasó (nunca queda "por_pagar" esperando un cierre que ya
ocurrió) — la liquidación día 1-5 es un proceso administrativo fuera de este sistema, no un job que
haya que modelar en Batch 14.

## 3. Alcance explícito de Batch 14 (vs. diferido a Batch 15)

| En este batch | Diferido a Batch 15 |
|---|---|
| Tabla de tramos (consulta + CRUD) — REQ-498 | Notas de crédito restan de la base de comisión — REQ-503 (`monto_comision` de este batch usa `total_cobrado` sin restar NC todavía) |
| 4 tarjetas + banner de pago automático — REQ-499 | Tabla de vendedores expandir/colapsar por grupos de mes — REQ-505 (este batch renderiza una tabla plana del mes filtrado, sin agrupar visualmente los arrastrados) |
| Filtro de mes (navegación) + vendedor — REQ-500 | Proyectos compartidos entre vendedores — REQ-506 (`compartido_con` no existe todavía en `pipeline_cards`; este batch asume 1 vendedor por pedido) |
| Estado derivado de Cobros — REQ-501 | Estado de cuenta de comisiones (documento imprimible) — REQ-507 |
| Cálculo de % escalonado por vendedor/mes — REQ-502 | — |

Los indicadores "Ya pagada"/"Por pagar"/"Pendiente de cobro" (REQ-499 RN2) SÍ agregan pedidos
arrastrados de meses cerrados sin cobrar — eso es dato, no UI, y REQ-499 lo pide explícito para
este batch. Lo que se difiere a Batch 15 es solo la presentación agrupada por mes dentro de la
fila expandida de cada vendedor.

## 4. Permisos

`permission:admin_contab.read` para Felix/Mark/Gerencia — ven todos los vendedores, exportan,
editan tramos (CRUD requiere además `admin_contab.edit`, mismo patrón que el resto del módulo).

Los vendedores (`vendedor_disenador`, ya existente en `SalesProject`/`PipelineCard.owner_id`) NO
tienen `admin_contab.read` hoy — REQ-575/577 piden que puedan consultar la tabla de tramos
(solo lectura) y su propio historial, sin exportar. Se resuelve con el mismo patrón ya
establecido en el proyecto (`modules.admin_contab.view` vs `view_team` en el JWT, igual que
Reportes en Servicios/VentasDiseno): `view` = propio historial + tabla de tramos read-only, sin
export; `view_team` = Felix/Mark/Gerencia, todo. Requiere agregar `admin_contab` al mapa de 7
módulos del JWT si no está ya — Backend Dev confirma el estado actual de
`JwtClaimsBuilder::modulesPayload()` antes de implementar.

## 5. Notas abiertas para Backend Dev (se resuelven en implementación, no bloquean este ADR)

- Confirmar que `pipeline_cards.stage='approved'` es efectivamente el evento "pedido cerrado" para
  Comisiones Internas (mismo criterio que `SalesTotalsService::monthlyApprovedTotal()`) — no hay
  otro candidato más fiel en el modelo actual.
- `AdminContInvoice.order_id` es único (1 factura por Order) — un pedido sin factura aún es
  `pendiente_cobro` con `total_facturado=null`, ya contemplado en el modelo de datos del Excel.
- Exportar PDF/Excel (REQ-498 RN2) reusa el patrón ya establecido en Facturación/Cobros
  (dompdf + PhpSpreadsheet), respetando filtro de mes+vendedor activo al momento de exportar.

## 6. Referencias

- Mockup: `4E__Admin_Contabilidad_ComisionesInt.html` (adjunto a SCRUM-575→579) — incluye lógica de
  referencia en JS (tiers, cálculo de estado, agrupación por mes) usada como guía de comportamiento,
  no como fuente de tramos reales (los tramos reales viven en `commission_tiers`, seedeados con los
  mismos valores que muestra el mockup).
- Excel de requerimientos, hoja "Modelo de Datos": `TRAMO_COMISION_INTERNA`, `PEDIDO_COMISIONABLE`.
- `docs/architecture/admin-contab-batches.md` — plan de batches, orden de dependencia.
