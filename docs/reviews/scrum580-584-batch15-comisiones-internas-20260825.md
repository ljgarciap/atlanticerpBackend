# Senior Review — Batch 15 Comisiones Internas, parte 2 (SCRUM-580→584, REQ-503→507)

**Fecha:** 2026-08-25. **Reviewer:** Arquitecto (mismo hilo, no subagente — reconciliación de 2
forks paralelos, Backend Dev + Frontend Dev, sobre `docs/adr/ADR-SCRUM580-584-batch15-comisiones-internas.md`).

## Resumen

Ambos devs entregaron implementaciones completas sobre el mismo ADR, con el contrato de API mucho
más alineado que en Batch 14 (ambos forks partieron del mismo código real ya reconciliado, no de un
diseño todavía no verificado) — solo 1 gap de contrato real, más los hallazgos técnicos habituales
de PHPStan Level 8 y el gotcha de int/float en JSON ya conocido de Batch 14.

## Hallazgos

### 1. Gap de contrato — ruta del PDF de estado de cuenta

Backend registró `GET /commissions/internal/account-statement/export?vendedor_id=&mes=&format=pdf`
(ruta estática, `vendedor_id` en query); Frontend ya llamaba
`GET /commissions/internal/{vendedorId}/account-statement/pdf?mes=` (mismo patrón que la ruta de
datos JSON, `{vendedorId}` en el path). Reconciliado moviendo el backend a la forma que el frontend
ya esperaba — `accountStatementExport()` ahora recibe `$vendedorId` como parámetro de ruta, no de
query. 1 test actualizado a la nueva URL.

### 2. PHPStan Level 8 — 7 hallazgos, todos por la reescritura de `CommissionOrderService`

- `$allOrdersCache` sin generics especificados.
- `rawOrders()`/`buildGroups()` con mismatch de tipo de retorno por el problema conocido de
  covarianza de `Collection` (ver Batch 14) — el alias `@phpstan-type CommissionOrderRow` necesitó
  relajar `nota_credito_numeros` a `mixed` (viene de una subquery cruda, no transformada) y
  `compartido_con` de `list<string>` a `array<int, string>` (la construcción vía `->all()` no
  garantiza "list" para PHPStan).
- 3 sitios con `$order['mes_cierre']` (nullable en el tipo) pasado a métodos que exigen
  `CarbonImmutable` no-nulo, dentro de loops sobre colecciones ya filtradas por `relevantOrders()`
  — PHPStan no cruza esa garantía entre métodos, se agregaron `continue` defensivos.

### 3. Gotcha ya conocido — floats enteros en JSON

5 tests nuevos (`assertSame(200.0, ...)`, `assertSame(10000.0, ...)`, etc.) fallaban porque PHP
serializa un float sin parte decimal sin `.0` (sin `JSON_PRESERVE_ZERO_FRACTION`, que este proyecto
no usa en ningún endpoint) — `json_decode` los trae de vuelta como `int`. Mismo gotcha exacto que
Batch 14, mismo fix (ajustar la aserción, el dato interno real ya era correcto).

## Verificación de las reglas de negocio nuevas (lectura de código, ya cubierta por los 21 tests
nuevos de Backend Dev — no re-derivada acá)

- **REQ-503:** solo notas `estado='aplicada'` restan la base; `pendiente_aprobacion`/`rechazada`
  no. Verificado con tests explícitos de ambos casos.
- **REQ-504:** corrige el gap real de Batch 14 (ver ADR §2) — un pedido de un mes ya cerrado y ya
  `pagado` deja de aparecer al ver meses posteriores. Verificado.
- **REQ-505:** `groups[]` ahora puede tener más de 1 entrada (una por `mes_cierre` presente en el
  resultado filtrado), cada una con su propio %/fijo y `arrastrado` correcto.
- **REQ-506:** división en partes iguales entre 2 y 3 responsables, cada uno viendo solo su parte
  (`total_pedido`) y el total completo del proyecto por separado (`total_pedido_completo`). Sin UI
  de asignación (decisión de diseño ya aprobada por Luis, ver ADR §4) — el campo
  `compartido_con` en `pipeline_cards` queda vacío en producción hasta que exista esa UI en
  Ventas&Diseño.
- **REQ-507:** documento de estado de cuenta — mes cerrado (solo `pagado` de ese mes) vs mes en
  curso (3 totales + arrastre) verificado con tests separados para cada rama.

## Gate final

Backend: PHPStan Level 8 limpio (682 archivos), suite completa `infra/test.sh` sin fallos (2589
tests, corrida sola sin nada más en paralelo contra el mismo Postgres compartido — lección de
Batch 14, evita el ruido de tests no relacionados fallando por `migrate:fresh` concurrente).
Frontend: `tsc --noEmit` limpio, `npm run build` limpio, 1512/1512 tests vitest.

**Siguiente paso:** push a `dev` con aprobación explícita, deploy manual, Pre-QA/Visual Review
fusionado, comentario+transición de los 5 tickets a QA. Con esto, Comisiones Internas (10/10
tickets, Batch 14+15) queda completo.
