# ADR-SCRUM580-584 — Batch 15: Comisiones Internas, parte 2 (NC restando base, arrastre correcto, agrupación por mes, proyectos compartidos, estado de cuenta)

**Status:** Propuesto — pendiente de aprobación de Luis
**Épica:** SCRUM-331 (Admin&Cont), área funcional Comisiones Internas (10 tickets — Batch 14 ya cerrado)
**Tickets de este batch:** SCRUM-580→584 / REQ-503→507

Continúa directo sobre `docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md` (ya implementado,
en `QA`) — no repite las decisiones de §1-2 de ese ADR (coexistencia con REQ-073, `CommissionOrderService`
en vivo sin tabla propia, `commission_tiers`/`commission_month_snapshots`).

## 1. REQ-503 — Notas de crédito restan la base de comisión

`AdminContCreditNote` ya existe (Notas Crédito y Devoluciones, Batch 10-13). Solo las notas en
`estado='aplicada'` reducen la base — `pendiente_aprobacion`/`rechazada` no han reducido nada
todavía. `CommissionOrderService::rawOrders()` suma, por `factura_origen_id = invoice.id`, el
`monto` de las notas aplicadas (+ concatena sus `numero` para mostrar "-$200 (NC-0071)", RN3).

`summaryForVendor()` cambia la base de cálculo de `total_cobrado` a
`total_cobrado - total_nota_credito` (nunca negativa) — hoy usa `total_cobrado` sin restar nada,
`total_nota_credito` está hardcodeado en `0.0` como placeholder de Batch 14. `total_nota_credito`
ya existe en `CommissionVendorSummary`/`CommissionOrder` (`types/adminContab.ts`) sin usarse — Batch
15 lo llena de verdad.

## 2. REQ-504 — Arrastre correcto (corrige un gap real de Batch 14, no solo lo completa)

Batch 14 agrega TODOS los pedidos del vendedor a los 3 totales (Pagada/Por pagar/Pendiente),
sin importar el mes que se está viendo — funciona hoy porque no hay datos viejos suficientes para
notarlo, pero es inconsistente con REQ-499 RN2 ("arrastrados... que **aún no se cobraron**") y
directamente contradice REQ-504 RN2 ("un pedido ya pagado deja de aparecer en meses posteriores").

Filtro correcto para un `viewMonth` dado — un pedido entra en el cálculo del vendedor si:
```
pedido.mes_cierre == viewMonth                         (nuevo del mes, cualquier estado)
  OR (pedido.mes_cierre < viewMonth AND pedido.estado != 'pagado')   (arrastrado, aún pendiente)
```
Un pedido de un mes FUTURO respecto al `viewMonth` nunca entra (no se puede "ver" un pedido que
todavía no existía en ese momento — no hay RN explícita para este caso, pero es la lectura
consistente de RN1). Este filtro reemplaza el loop actual de `summaryForVendor()` que itera
`$orders` completo sin condición de mes.

## 3. REQ-505 — Agrupación por mes en el detalle expandido

El expand/collapse de la fila de vendedor YA existe (Batch 14, `expandedVendor` state en
`ComisionesInternasPage.tsx`) — lo que falta es agrupar el detalle POR mes de cierre en vez de la
lista plana de un solo mes que arma Batch 14 (`groups` hoy siempre tiene como máximo 1 entrada).
Con el filtro de §2 ya aplicado, se arma un `group` por cada `mes_cierre` presente en el resultado
filtrado (el del `viewMonth` + cualquier arrastrado), cada uno con su propio `porcentaje`/`fijo` —
mismo shape `CommissionVendorMonthGroup[]` que el frontend ya declara y ya sabe renderizar
(`arrastrado: true` para los que no son el mes filtrado). RN3 (vendedor sin pedidos → mensaje) ya
está cubierto por Batch 14 (`groups.length === 0` → "Sin pedidos registrados todavía").

## 4. REQ-506 — Proyectos compartidos

**Decisión de diseño — alcance acotado al cálculo, no a la asignación:** el propio ticket dice
"Debe originarse en Ventas & Diseño al crear el proyecto... no está conectado en este mockup, es
solo un ejemplo local" — los criterios de aceptación asumen "Dado que un proyecto... se comparte"
como precondición dada, no piden una UI de asignación. Ventas&Diseño no tiene ningún mecanismo de
multi-responsable hoy (`PipelineCard.owner_id` es un único FK) y construir esa UI es una feature de
OTRA épica, fuera de los 148 tickets de Admin&Cont — no se hace en este batch.

Se agrega `pipeline_cards.compartido_con` (JSONB nullable, array de `user_id`) vía migración
cross-schema (Admin&Cont modificando una tabla de `ventas_diseno` — sin problema, es una columna,
no una FK cross-schema). Sin UI propia para asignarlo — editable solo por superadmin (tinker o un
endpoint mínimo, a definir con PM), mismo criterio que otros valores "sin UI todavía" ya aceptados
en el proyecto cuando el ticket que los pide no incluye construir esa UI. Mientras Ventas&Diseño no
tenga su propia feature de multi-responsable, este campo queda vacío en producción — el cálculo
está listo para cuando esa UI exista.

`CommissionOrderService`: si `compartido_con` no está vacío, `total_pedido` de CADA responsable
(el `owner_id` original + cada id de `compartido_con`) es `pc.amount / (1 + count(compartido_con))`
— división en partes iguales entre TODOS los responsables (RN1). Se agrega
`total_pedido_completo` (el monto sin dividir) y `compartido_con` (nombres, no solo ids) a la forma
`CommissionOrder` para el badge "↔ Compartido con [...] · $X total del proyecto" (RN3). Un pedido
compartido genera una fila de "pedido comisionable" POR CADA responsable (mismo `pedido_id`
lógico, cada uno viendo solo su parte) — ninguno ve el total completo (RN2/RN4).

## 5. REQ-507 — Estado de cuenta de comisiones (documento)

Nuevo endpoint `GET /api/admin-contab/commissions/internal/{vendedor_id}/account-statement?mes=`
(mismo permiso que summary — Felix/Mark/Gerencia de cualquier vendedor, un vendedor solo el propio,
verificado server-side igual que el resto del módulo, nunca solo con la UI). Reusa
`CommissionOrderService::summaryForVendor()` con el filtro de §2, pero:
- **Mes cerrado (RN1):** solo pedidos con `estado='pagado'` de ese mes — lo pendiente no se repite,
  vive en el estado de cuenta del mes en curso.
- **Mes en curso (RN2):** todos los grupos relevantes (mes actual + arrastrados), 3 totales.
- **RN3:** línea de descuento total por NC, antes de los totales — `total_nota_credito * pct / 100`
  agregado (mismo cálculo que ya usa el mockup, `openEstadoCuenta()`, línea `Descuento total por
  notas crédito`).
- **RN4:** pedidos compartidos identificados con quién se comparten.

Documento PDF vía dompdf (mismo patrón que `commissions-internal-pdf.blade.php` de Batch 14,
`CommissionExportService` gana un método `toAccountStatementPdf()`) — el mockup ya trae el HTML/CSS
completo de referencia (`openEstadoCuenta()`, adjunto SCRUM-575). Frontend: botón "Ver estado de
cuenta" ya visible en el mockup dentro de la fila expandida (Batch 14 no lo conectó a propósito,
diferido a este batch) — abre un modal/overlay con el documento + botón "Ver/Imprimir PDF".

## 6. Notas para Backend/Frontend Dev

- `summaryForVendor()` cambia de forma significativa (filtro de mes, NC, split compartido) — no es
  un parche menor sobre Batch 14, es una reescritura de la función. Cubrir con tests explícitos
  cada RN (NC parcial/total, arrastre que deja de aparecer tras pagarse, split entre 2/3
  responsables, documento de mes cerrado vs en curso).
- El campo `total_nota_credito` y el shape `groups[]` YA existen en `types/adminContab.ts` desde
  Batch 14 (con placeholder) — no hace falta tocar los tipos, solo llenarlos de verdad.
- Verificar antes de implementar: `compartido_con` es una decisión de diseño mía (Arquitecto), no
  viene de ningún ticket de Ventas&Diseño existente — si Luis prefiere una vía distinta (ej. una
  tabla propia en vez de JSONB en `pipeline_cards`), ajustar antes de dispatchar a PM.

## 7. Referencias

- Mockup: mismo adjunto de Batch 14 (`4E__Admin_Contabilidad_ComisionesInt.html`, SCRUM-575) — ya
  incluye el HTML/JS completo de `openEstadoCuenta()` y la lógica de `compartidoCon` como ejemplo
  local, usado como guía de comportamiento.
- `docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md` — diseño base.
- `docs/reviews/scrum575-579-batch14-comisiones-internas-20260825.md`,
  `docs/pre-qa/scrum575-579-batch14-comisiones-internas-20260825.md` — estado real del código tras
  Senior Review/Pre-QA, punto de partida real de este batch (no el diseño original de Batch 14).
