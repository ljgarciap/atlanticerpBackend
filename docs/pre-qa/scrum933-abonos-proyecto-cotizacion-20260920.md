# Pre-QA — SCRUM-933 "MEJORA UAT: Registrar Abonos"

Pre-QA, 2026-09-20. Sin mockup adjunto en Jira — Visual Review no aplica (confirmado). Diseño:
`docs/adr/ADR-SCRUM933-registrar-abonos-correcciones.md`. Senior Review: aprobado sin hallazgos
(`docs/reviews/scrum933-senior-review-20260920.md`). Último ticket del batch SCRUM-929/930/931/932/933
(mismo problema de fondo, filtro cruzado Proyecto↔Cotización) — los 4 anteriores ya en `QA`.

## Gate automatizado (repetido en vivo antes de la pasada adversarial, no solo leído del reporte de Senior Review)

- Backend: `infra/test.sh --filter=test_933_invoices_for_sub_client_incluye_proyecto_cotizacion_y_excluye_otros_sub_clientes`
  → **1/1 verde**, corrido de nuevo por mí (no solo tomado del reporte de Senior Review) — incluye
  el caso negativo explícito (familia ajena, otro Sub-cliente, no aparece en la lista).
- Frontend: `npx vitest run src/components/admin-contab/RegistrarAbonoModal.test.tsx` → **8/8 verdes**,
  ídem, corrido de nuevo por mí.

## Por qué no hay fixture real de "2+ Sub-clientes con proyectos propios" en la BD local

Confirmado por tinker (`MasterClient::whereHas('subClients', fn($q) => $q->has('salesProjects'), '>=', 2)`)
que no existe ningún Cliente Master local con ese shape — armar esa fixture real de punta a punta
(pedido→Bodega→factura→`pipeline_card`→cotización, como hace `familia()` en
`AdminContPartialInvoicingConsumersTest`) es demasiado costoso para esta pasada. Se usó una
estrategia mixta, no solo lectura de código:

1. **Backend real, sin mocks** — el test de integración nuevo (`test_933_invoices_for_sub_client...`)
   ya construye 2 familias completas independientes con `makePedido()`/Bodega real y llama al
   endpoint por HTTP de verdad — corrido en vivo por mí arriba, no solo confiado del reporte de
   Senior Review. Además, verificación directa por curl contra el endpoint real (ver abajo) para
   los casos negativos/inválidos que el test unitario no cubre.
2. **Frontend real, con `page.route` mockeando solo el endpoint nuevo** — Playwright contra
   `localhost:5173` con login real (`contabilidad@test.com`, Contabilidad, `lider_admin_contab`),
   ejecutando el componente `RegistrarAbonoModal.tsx` compilado de verdad; solo la respuesta de
   `GET .../facturas-por-subcliente` está controlada, para poder ejercitar 2 Sub-clientes con
   proyectos/cotizaciones cruzadas sin sembrar datos reales. Esto corre el código real de cruce
   (`useMemo`/`selectProyecto`/`selectCotizacion`), no una simulación.

## Verificación directa por API (antes de UI, inputs inválidos/borde que el test unitario no cubre)

```
GET .../facturas-por-subcliente                       (sin sub_client_id) → 422, "El campo sub client id es obligatorio"
GET .../facturas-por-subcliente?sub_client_id=abc      (no numérico)      → 422, "...debe ser un número entero"
GET .../facturas-por-subcliente?sub_client_id=999999   (no existe)        → 200, {"data":[]} — no crashea, no filtra mal
GET .../facturas-por-subcliente?sub_client_id=-1       (negativo)         → 200, {"data":[]}
```

Mismo comportamiento que el endpoint hermano `.../cascada/proyectos` con el mismo input inválido
(confirmado en paralelo) — consistente con el resto de la cascada, no una regresión.

## Pasada en vivo (Playwright CLI, `atlanticerp-frontend/e2e/preqa-scrum933-abonos-proyecto-cotizacion-20260920.spec.ts`, 11/11 verdes)

Promovido a test permanente (mismo criterio ya usado en 930/931/932 — el patrón de fondo ya se
rompió una vez en la clase) — queda en `atlanticerp-frontend/e2e/` para re-correr ante cualquier
regresión futura.

| # | Escenario de ruptura intentado | Resultado |
|---|---|---|
| 933.0 | Cero emoji en cualquier elemento del modal | OK |
| 933.1 | Proyecto y # de Cotización aparecen tras Sub-cliente, acotados a ESE sub-cliente | OK |
| 933.2 | Texto PARCIAL en minúscula ("azu") filtra correctamente (no exact-match) | OK |
| 933.3 | Elegir una opción de la lista FIJA el valor del input (no queda medio tipeado) | OK |
| 933.4 | Elegir Cotización autocompleta Proyecto y acota Factura a la combinación exacta (2 facturas del mismo proyecto, solo la de esa cotización queda visible) | OK |
| 933.5 | Elegir Proyecto filtra Cotización a las de ese proyecto — puede ser más de una (no asume cardinalidad 1) | OK |
| 933.6 | Tras elegir Cotización, tipear en Proyecto buscando OTRO proyecto | Ver "Nota no bloqueante" abajo |
| 933.7 | Doble clic / clicks rápidos Proyecto→Cotización→Proyecto | OK — sin estado corrupto, Factura conserva la opción correcta |
| 933.8 | Cambiar de Sub-cliente A a B nunca deja ver ni matchear opciones del otro sub-cliente (frontend) | OK — Proyecto/Cotización se limpian solos al cambiar Sub-cliente, y la lista mockeada de B nunca mezcla con la de A |
| 933.9 | Tras elegir Proyecto, tipear en Cotización buscando OTRA cotización (dirección inversa de 933.6) | Ver "Nota no bloqueante" abajo |
| 933.10 | Edición de abono existente: Proyecto muestra el valor real guardado (`abono.proyecto`), Cotización SIEMPRE vacía (nunca se persiste, confirmado por diseño) | OK |

### Nota no bloqueante (tests #933.6 y #933.9) — mismo patrón ya aceptado en SCRUM-932

Reproducción: elegir Cotización (fija Proyecto vía cruce) → borrar el texto de Proyecto e intentar
tipear un proyecto DISTINTO sin tocar el campo Cotización primero. El campo Proyecto se comporta
honestamente (`salesProjectId` se limpia, aparece "Al elegir un sub-cliente, el proyecto se vuelve
obligatorio", "Guardar abono" queda deshabilitado — **nunca hay riesgo de submit con datos
inconsistentes**), pero la lista de opciones de Proyecto queda vacía sin explicación visible: como
`selectedQuoteId` no se limpia al tipear en Proyecto (ídem la dirección inversa: `selectedQuoteId`
sigue activo, cruzando `proyectoOptions` contra esa cotización), el usuario no puede encontrar el
proyecto nuevo hasta que borra manualmente el campo Cotización. Captura:
`atlanticerp-frontend/e2e/.tmp/preqa-933-06-proyecto-tras-cotizacion.png`.

Es el MISMO comportamiento ya documentado como no bloqueante en el Pre-QA de SCRUM-932 (nota del
test #5 de `docs/pre-qa/scrum932-notas-credito-20260920.md`) — "elegir X auto-completa Y, pero
tipear en Y no limpia la selección de X" es un patrón consistente en las 3 implementaciones de este
batch (931/932/933), no una regresión puntual de este ticket. No corrompe ningún dato, no permite
un submit inválido, y el AC de SCRUM-933 no pide que tipear en un campo limpie la selección del
otro. Queda documentado por transparencia y consistencia con el resto del batch — **no bloquea**.

Si en el futuro se decide corregir esta clase de UX (los 3 tickets a la vez, no solo éste), el
punto de entrada en este archivo es: `selectProyecto()`/`selectCotizacion()` en
`RegistrarAbonoModal.tsx` líneas 118-139 no son el problema — son los `onChange` de los inputs de
texto (líneas ~333 y ~363) los que deberían limpiar también `selectedQuoteId`/`salesProjectId` del
campo contrario al empezar a tipear, no solo el propio.

## Lo que sí funciona (más allá de lo tabulado arriba)

- `AbonoService::invoicesForSubClient()` nunca filtra por `es_incobrable` (mismo criterio que
  `invoicesForProject()`, documentado a propósito en el código para no cambiar comportamiento).
- Las 3 rutas de cascada viejas (`sub-clientes`/`proyectos`/`facturas`) siguen intactas — no se
  tocaron, confirmado por diff.
- Validación de input inválido/borde del endpoint nuevo, idéntica a su endpoint hermano.
- Hidratación de un abono existente en edición: Proyecto correcto, Cotización siempre vacía por
  diseño (no persiste).
- Cero emoji en cualquier elemento nuevo (regla dura del cliente, Atlantic).
- Fuga de datos entre Sub-clientes: cubierta en 2 capas independientes — backend real (test de
  integración con familia ajena, corrido en vivo) y frontend (933.8, mock por sub-cliente con
  aserción de no-mezcla).

## Veredicto

**Pasada limpia.** Sin hallazgos CRÍTICOS ni MEDIOS bloqueantes — el único comportamiento
documentado (933.6/933.9) es no bloqueante, consistente con el resto del batch, y no permite un
submit con datos inconsistentes. Transiciono SCRUM-933 a `QA`.
