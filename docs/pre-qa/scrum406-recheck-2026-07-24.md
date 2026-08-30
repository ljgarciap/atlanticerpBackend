# Pre-QA (re-check) — SCRUM-406 RN2/Escenario 2, puente Compras → Bodega

**Fecha:** 2026-07-24 (segunda pasada, sobre el hallazgo CRÍTICO del Pre-QA original del mismo día
— ver `docs/pre-qa/a3-cierre-2026-07-24.md`, sección "🔴 CRÍTICO — SCRUM-406 RN2/Escenario 2").
**Commit revisado:** `ea82918` (`atlanticerp-backend`, rama `dev`, local — "feat(bodega): auto-release
orders awaiting stock on PO receipt (SCRUM-406)"), diseñado en
`docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md`, ya con Senior Review propio
(`docs/reviews/scrum-406-stock-bridge-2026-07-24.md`, 🟢 Aprobado).
**Entorno:** Docker local (puerto 8090), login real `gerencia2@atlantic.com.pa`
(`lider_compras`) y `almacen@atlantic.com.pa` (`lider_bodega`).

## Veredicto global: 🟢 Pasada limpia — sin hallazgos CRÍTICOS ni MEDIOS bloqueantes

---

## Qué se verificó

### 1. Código real vs. ADR (lectura de los 6 archivos del commit)
- `Compras\Events\PurchaseOrderReceived` — evento nuevo, payload `purchase_order_id`/`product_ids`/
  `tenant`, disparado en `PurchaseOrderController::advance()` inmediatamente después de que
  `GoodsReceiptConfirmationService::confirmPendingForOrder()` retorna sin excepción, dentro del
  bloque `if ($next === STATUS_RECIBIDO)`. Coincide con el ADR.
- `Bodega\Listeners\ReleaseOrdersAwaitingStock` — registrado en `AppServiceProvider::boot()`
  (`Event::listen(PurchaseOrderReceived::class, ReleaseOrdersAwaitingStock::class)`). `handle()`
  delega TODO a un método privado `release()` envuelto en un único `try/catch(Throwable)` +
  `Log::error()` con el mismo shape de contexto que `CreateOrderFromApprovedQuote` — ningún camino
  interno puede escapar sin loguearse, y una excepción acá nunca puede romper la respuesta HTTP de
  `advance()` porque el evento se dispara síncrono pero el catch está dentro del propio listener,
  no alrededor del `event()` de Compras.
- **Desviación documentada del ADR, no un bug:** el ADR pide FIFO por `status_changed_at`; el
  código usa `updated_at` porque `Order` no tiene esa columna (confirmado — no existe en el modelo
  ni en la migración). El propio Senior Review ya lo marcó como razonable (nada más toca la fila
  mientras el pedido permanece `faltante`+`asignado`). Contra este mismo re-check: correcto, no es
  un hallazgo nuevo.
- `PurchaseOrderController::advance()` — `product_ids` se arma desde `order->lines()` (líneas de
  ESTA orden de compra), no desde los ingresos confirmados — decisión correcta (un producto puede
  tener stock suficiente por otra vía sin que esta PO puntual lo haya traído).
- Sin liberación parcial: la lógica agrupa TODOS los ítems faltantes de un pedido (no solo los del
  `product_ids` del evento) y solo libera si el pedido completo queda cubierto — confirmado por
  lectura de código y por los 3 escenarios de test dedicados (ver más abajo).
- `stage` nunca se toca — solo `order_type` vuelve a `pedido`.

### 2. Suite de backend completa + estático
```
infra/test.sh          → PHPUnit 11.5.55, 1249 tests, 5124 assertions — OK (incluye los 4 tests
                          de StockReleaseOnPurchaseOrderReceivedTest, HTTP real, sin Event::fake())
vendor/bin/phpstan analyse → Level 8, [OK] No errors
```
Sin regresiones en el resto del backend tras este commit.

### 3. Tests Playwright permanentes (criterio principal de "implementado correctamente")

**Gotcha de sesión (documentado en la memoria del proyecto,
`feedback_local_test_db_no_es_fresh.md`):** correr `infra/test.sh` en este mismo entorno local
dropea y recrea los schemas de tenant compartidos con el Docker de desarrollo — se llevó puestas
las fixtures `VR-9001` (id 149) y `PO#18` que este mismo test permanente necesita en su `beforeAll`.
Se resembraron manualmente con los mismos IDs exactos (149/18) que el test tiene hardcodeados
(ambos estaban libres — huecos de secuencia en la BD local), más `VR-9007` (producto sin OC activa,
para el segundo test) — ver detalle de la reconstrucción en el historial de esta sesión, no
repetido acá por espacio. Ambos tests corrieron limpio DOS veces seguidas tras la reconstrucción
(confirmando que la fixture es re-corrible, no solo un fluke):

```
✓ preqa-scrum406-sin-oc.spec.ts             (2/2 corridas)
✓ preqa-scrum406-rn2-cross-module.spec.ts   (2/2 corridas)
```

`preqa-scrum406-rn2-cross-module.spec.ts` — **este es el test que estaba rojo a propósito** desde
el Pre-QA original. Avanza la `PurchaseOrder` real #18 hasta `Recibido` con el botón real de
Compras (usuario real `gerencia2@atlantic.com.pa`), vuelve al tablero de Bodega con el usuario
real `almacen@atlantic.com.pa`, y confirma que la tarjeta `VR-9001` **ya no** contiene "Sin
stock". Log de la corrida: `¿la tarjeta sigue en "Sin stock"? false | ¿sigue mostrando ETA? false`
— el escenario 2 del criterio de aceptación ahora se cumple en vivo, no solo por código.

`preqa-scrum406-sin-oc.spec.ts` — sin regresión: el camino de ruptura "sin OC asociada" (`VR-9007`,
producto sin ninguna `PurchaseOrder` activa) sigue mostrando "Sin stock" sin ETA inventada, sin
error de consola.

### 4. Verificación adversarial adicional (más allá del test ya escrito)

- **Dos pedidos compitiendo por el mismo stock recién llegado (FIFO), en vivo contra el servidor
  real (no solo PHPUnit):** se sembraron dos pedidos "faltante" nuevos para el mismo producto
  (`VR-9101` más antiguo por 30 min, `VR-9102` más nuevo, ambos pidiendo 5 unidades), una PO
  internacional con solo 5 unidades (alcanza para uno solo), y se avanzó esa PO real hasta
  `Recibido` vía `curl` autenticado contra `PATCH /api/compras/orders/{id}/advance` (mismo endpoint
  HTTP real que usa el botón "Avanzar", fuera del entorno de PHPUnit). Resultado:
  - `VR-9101` (más antiguo): `order_type=pedido`, item `found_at_location=true` — **liberado**.
  - `VR-9102` (más nuevo): `order_type=faltante`, item `found_at_location=false` — **se quedó
    intacto**, sin liberación parcial ni de más.
  - `stage` de ambos permaneció `asignado` — sin salto de etapa inesperado.
  Esto corrobora en vivo, contra el servidor real (no solo el proceso de test), el mismo escenario
  que ya cubre `test_stock_insuficiente_para_2_pedidos_libera_solo_el_mas_antiguo_fifo` en la suite
  PHPUnit (que también corre HTTP real, sin `Event::fake()`, confirmado por Senior Review).

- **Pedido con ítems faltantes de 2 productos distintos, solo uno llega:** no se repitió en vivo
  por tiempo — ya está cubierto end-to-end (HTTP real, sin `Event::fake()`) por
  `test_pedido_con_2_productos_faltantes_no_se_libera_si_solo_llega_uno`, parte de la suite verde
  de arriba, y explícitamente confirmado por Senior Review como ejercitado correctamente (agrupa
  TODOS los ítems faltantes del pedido antes de decidir si libera). Criterio de aceptación
  cubierto con el mismo rigor que exigiría repetirlo a mano.

- **Aislamiento de fallos del listener (try/catch + `Log::error()`):** confirmado por lectura de
  código, no forzado en vivo — forzar un fallo interno real (ej. tirar la conexión a Redis
  a mitad del listener) es artificioso para este alcance y el patrón es idéntico, byte a byte, al
  de `CreateOrderFromApprovedQuote`, que ya está en producción con este mismo criterio.

- **Doble clic / re-avance de una PO ya `Recibido` (idempotencia):** se repitió el `PATCH .../
  advance` contra la PO ya en `Recibido` — responde `422 {"message":"Esta orden no tiene un
  siguiente estado disponible."}`, sin volver a disparar el evento ni re-procesar el listener (el
  disparo está gateado por `$next === STATUS_RECIBIDO`, y `nextStatus()` devuelve `null` una vez en
  `Recibido`). Sin riesgo de notificaciones duplicadas ni de reprocesar pedidos ya liberados.

- **`stage` no cambia:** confirmado tanto en el test Playwright (implícito — la tarjeta pasa de
  "Sin stock" a normal sin saltar de columna en el Kanban) como en la verificación FIFO en vivo de
  arriba (`asignado` en ambos pedidos, antes y después).

---

## Lo que sí funciona

- Escenario 2 completo del criterio de aceptación (RN2): la tarjeta sale de "Sin stock"
  automáticamente al marcar la PO como recibida, sin acción manual de Bodega — confirmado en
  navegador real.
- Sin liberación parcial (ni entre pedidos que compiten por el mismo stock, ni dentro de un mismo
  pedido con 2+ productos faltantes).
- FIFO por tiempo de espera cuando el stock no alcanza para todos los competidores — confirmado en
  vivo contra el servidor real, no solo en test.
- `stage` nunca se toca — solo `order_type`.
- Aislamiento de fallos (try/catch + log) del lado de Bodega no puede romper la respuesta HTTP de
  Compras.
- Sin riesgo de doble-disparo por reintento/doble clic en "Avanzar" sobre una orden ya recibida.
- Camino de ruptura "sin OC asociada" (Escenario ya validado antes) sigue sin regresión.
- Suite completa de backend (1249 tests) + PHPStan Level 8 verdes tras este commit.

## Hallazgos

Ninguno bloqueante. Nota de higiene, no un hallazgo de producto: correr `infra/test.sh` en el
mismo entorno local donde luego se corren los Playwright permanentes de Bodega/Compras sigue
requiriendo re-sembrar las fixtures manuales después (gotcha ya documentado en la memoria del
proyecto, no nuevo de esta sesión) — las fixtures quedaron restauradas a su estado inicial
(`PO#18` en `en_transito`, `VR-9001` en `faltante`/`asignado`) al cierre de este re-check.

## Conclusión

SCRUM-406 queda limpio para pasar a `QA`. El puente cross-módulo Compras→Bodega funciona según lo
diseñado en el ADR, con la desviación documentada (FIFO por `updated_at` en vez de
`status_changed_at`, ya justificada y aprobada por Senior Review) siendo la única diferencia real
frente al texto del ADR, y no afecta el comportamiento observable.
