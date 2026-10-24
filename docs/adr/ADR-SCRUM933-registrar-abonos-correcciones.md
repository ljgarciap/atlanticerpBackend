# ADR — SCRUM-933: Registrar Abonos — búsqueda por Proyecto y # de Cotización

Arquitecto, 2026-09-20. Ticket: `SCRUM-933` (1 solo punto, alcance explícito: solo el modal
"Registrar Abono"). Último del grupo SCRUM-929/930/931/932/933. Mismo problema de fondo que los
3 anteriores (filtro cruzado Proyecto↔Cotización), pero con un punto de partida real distinto —
Explore confirmó que acá **no hay nada ya resuelto para reusar tal cual**, es más trabajo nuevo que
en 930/931/932.

## Por qué este ticket no es "lo mismo otra vez"

Los otros 3 partían de una lista plana YA cargada de todas las facturas del cliente
(`openInvoicesForClient()`/`invoicesForClient()`), con `sales_project_id`/`quote_id`/
`cotizacion_folio` embebidos por fila — el cruce se resolvía con un `useMemo` sobre esa lista, sin
tocar el backend más que agregar 4 campos.

`RegistrarAbonoModal.tsx` hoy tiene una cascada real pero **escalonada y unidireccional**: Sub-
cliente → Proyecto (`AbonoService::projectsForSubClient()`, solo `{id, name}`, sin ninguna noción
de cotización) → Factura (`AbonoService::invoicesForProject()`, solo `{id, numero}`) — 3 endpoints
separados, cada uno exige el id del padre, ninguno acepta otro filtro. No hay un solo punto donde
"todas las facturas del sub-cliente con su proyecto y cotización" ya estén juntas.

## Decisión 1 — Fuente de datos: endpoint nuevo, no una cascada de cotizaciones en paralelo

Se evaluaron 2 opciones (ver hallazgo de Explore):
- **(a) Endpoint nuevo** `AbonoService::invoicesForSubClient(subClientId)` que replique el shape ya
  validado 3 veces esta sesión (`CreditNoteService::invoicesForClient()`): TODAS las facturas del
  sub-cliente (vía sus proyectos — `AdminContInvoice` no tiene `sub_client_id` propio, se resuelve
  `whereIn('sales_project_id', SalesProject::where('sub_client_id', ...)->pluck('id'))`), con
  `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio` ya resueltos vía `Order::linkedQuote()`
  (mismo criterio que 930/931/932 — nunca `AdminContInvoice.quote_id` directo).
- **(b) Mantener las 3 cascadas** y agregar una cascada paralela de cotizaciones por sub-cliente,
  reconciliando dos fuentes en el cliente.

**Se elige (a).** Una sola fuente de verdad, mismo patrón ya probado 3 veces, sin reconciliar dos
listas que podrían desincronizarse. Ruta nueva: `GET /api/admin-contab/abonos/cascada/facturas-por-subcliente?sub_client_id=`
(mismo prefijo `cascada` que sus hermanas, aunque conceptualmente reemplaza 2 de los 3 pasos, no es
un cuarto escalón). **Las 3 rutas/endpoints de cascada existentes (`sub-clientes`/`proyectos`/
`facturas`) NO se tocan ni se eliminan** — pueden tener otros consumidores fuera de este modal, y
`RegistrarAbonoModal.tsx` deja de llamarlas él mismo, pero siguen existiendo.

## Decisión 2 — UI: autocomplete de texto, no `<select>` (divergencia deliberada de 930/931/932)

El ticket usa un verbo distinto a los otros 3: *"pudiendo el usuario **buscar** indistintamente por
cualquiera de los dos"* (930/931/932 dicen "seleccionar"). Se interpreta como intencional, no como
variación de redacción: un `<select>` con potencialmente decenas/cientos de cotizaciones de un
cliente con historial largo es mala UX comparado con poder tipear y filtrar. El proyecto ya tiene
el patrón exacto para esto, **en el mismo archivo**: el buscador de Cliente Master
(`RegistrarAbonoModal.tsx`, input + lista flotante con autocomplete, sin componente extraído —
JSX inline repetido en varios modales del módulo). Proyecto y # de Cotización se implementan igual:
2 inputs de texto con autocomplete, opciones derivadas (`useMemo`) de la lista del endpoint nuevo,
cross-filtradas entre sí (elegir uno filtra/acota las opciones del otro, mismo criterio de
`selectProject()`/`selectQuote()` ya escrito en `RegistrarCobroModal.tsx`, adaptado de `<select>` a
autocomplete).

## Decisión 3 — Factura sigue siendo un paso explícito, no se auto-resuelve en silencio

El ticket dice "el sistema debe traer la factura que corresponde específicamente a esa
combinación" — no dice que el selector de Factura desaparece. Igual que en 931/932: una cotización
puede tener más de una entrega facturada por separado (SCRUM-929), así que la combinación
Proyecto+Cotización puede seguir matcheando más de una factura. El `<select>` de Factura existente
se mantiene, pero sus opciones quedan acotadas a las que matchean la combinación vigente (mismo
patrón `visibleFacturas` de 931/932) — nunca se auto-selecciona a ciegas cuando hay más de un match.

## Flujo resultante (reemplaza el paso Proyecto→Factura actual, Sub-cliente no cambia)

1. Cliente Master (autocomplete, sin cambios) → Sub-cliente (`<select>`, sin cambios).
2. Al elegir Sub-cliente: se llama UNA vez a `invoicesForSubClient()` (reemplaza las llamadas a
   `useAbonoProyectos`/`useAbonoFacturas` dentro de este modal — esos hooks no se borran, otros
   consumidores pueden seguir usándolos si existen, confirmar durante implementación).
3. Proyecto / # de Cotización: 2 autocompletes cruzados sobre esa lista, acotados al sub-cliente.
4. Factura: `<select>` existente, con sus `<option>` acotadas a la combinación vigente.
5. Mismo saneamiento que 931/932: si la factura elegida deja de estar entre las visibles por el
   filtro, se limpia (efecto ya validado 2 veces esta sesión).

## Resumen de cambios

| Capa | Cambio |
|---|---|
| Backend | `AbonoService::invoicesForSubClient(int $subClientId): array` (nuevo) — mismo shape que `CreditNoteService::invoicesForClient()` |
| Backend | `AdminContAbonoController`: nueva ruta `GET .../abonos/cascada/facturas-por-subcliente` |
| Backend | Sin cambios a `subClientes()`/`proyectos()`/`facturas()` existentes — quedan intactos |
| Frontend | `RegistrarAbonoModal.tsx`: reemplaza el uso de `useAbonoProyectos`/`useAbonoFacturas` por el hook nuevo; Proyecto/Cotización pasan de `<select>` (Proyecto) e inexistente (Cotización) a 2 autocompletes cruzados; Factura sigue siendo `<select>`, acotado |
| — | Sin migraciones, sin componentes de detalle nuevos (este ticket no toca ningún modal de detalle) |
| — | Sin cambios a `AdminContAbono::store()`/modelo — la cotización nunca se persiste, es solo mecanismo de búsqueda que resuelve `sales_project_id`/`invoice_id` |
