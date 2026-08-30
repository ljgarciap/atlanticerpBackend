# Pre-QA — SCRUM-416 (REQ-346) + SCRUM-420 (REQ-350) — "En atención" no respeta rotación

Fecha: 2026-07-28
Batch: rebote de marly.rangel (QA) del 2026-07-24, mismo defecto de raíz en ambos tickets.

## Contexto y por qué el premise inicial era incorrecto

Antes de arrancar se planteó como causa raíz "no hay ningún producto con `rotation='baja'`
en dev, por eso QA nunca vio el filtro excluir nada" — y se pidió sembrar 1 producto de
rotación baja para poder observar la exclusión.

Paso 1 del protocolo (leer el criterio exacto en Jira, no lo que ya se investigó) invalida
esa premisa: **la exclusión de `rotation='baja'` ya funciona correctamente hoy** (verificado
abajo). El bug real de marly es otro y es más grave: productos con `rotation = NULL`
(que el frontend etiqueta "Compra única", ver `atlanticerp-frontend/src/types/bodega.ts:613-621`)
se están contando en "En atención" cuando no deberían.

## Causa raíz real — conflicto de reglas de negocio entre dos tickets que comparten código

`InventoryKpiService::isInAttention()` (`app/Modules/Compras/Services/InventoryKpiService.php:85-94`)
es un método único, reusado "byte a byte" (comentario de clase, línea 17) entre dos pantallas
con **reglas de negocio distintas para el mismo concepto**:

| Ticket | Pantalla | Regla textual (RN) | Tipo de regla |
|---|---|---|---|
| SCRUM-232 / REQ-169 (ya en producción) | Compras › Inventario | "Rotación baja no cuenta en atención" (Escenario 1) | **Blocklist** — excluye solo `baja`, todo lo demás (incluido `null`) cuenta |
| SCRUM-416 / REQ-346 (este batch) | Bodega › Inventario | RN1: *"'En atención' = productos de rotación **alta o media** que además están en 'Bajo stock' o 'Sin stock'"* | **Allowlist** — solo `alta`/`media` cuentan; `null` y cualquier otro valor quedan afuera |

El código implementa el blocklist de REQ-169. SCRUM-416/420 (Bodega) necesitan el allowlist de
REQ-346. Ambas pantallas leen `isInAttention()` sin distinguir cuál regla aplica.

En el catálogo real (dev y local), la enorme mayoría de productos de Bodega tiene
`rotation = NULL` (frontend lo pinta como "Compra única" — valor que **no existe** como
enum válido en backend, `CatalogProduct::ROTATIONS = ['alta','media','baja']`, ver
`app/Modules/VentasDiseno/Models/CatalogProduct.php:66-71` — es una interpretación
client-side documentada como pendiente de confirmar con Analista, REQ-419). Con el blocklist
actual, cada uno de esos productos con `rotation=NULL` y stock bajo/sin stock cuenta en "En
atención" — exactamente lo que marly reportó ("En atención" = "Bajo stock" + "Sin stock" sin
descontar nada).

## Verificación en vivo (Docker local, `atlantic_ventas_diseno.catalog_products`)

Local tiene 13 productos, los 13 con `rotation = NULL` (mismo patrón que dev, donde marly
reportó 43/43 en "Compra única"). Con ese dataset `kpis()` da `in_attention=0` porque
ninguno está en bajo/sin stock — así que se probó la lógica pura de `isInAttention()`
vía `artisan tinker` (instancias en memoria, sin persistir):

```
rotation=NULL,  sin_stock (stock=0, reorder=5) -> isInAttention: TRUE   ← BUG (debería ser FALSE)
rotation=baja,  sin_stock (stock=0, reorder=5) -> isInAttention: FALSE  ← correcto (ya funcionaba)
rotation=media, sin_stock (stock=0, reorder=5) -> isInAttention: TRUE   ← correcto
```

Confirma exactamente el reporte de marly: no es la exclusión de `baja` la que falla (esa
funciona), es que `NULL`/"Compra única" no debería contar bajo la RN1 de REQ-346 y hoy cuenta.

## Por qué esto NO se corrige acá mismo (regla dura del loop, "fix grande o toca diseño")

Cambiar `isInAttention()` a allowlist (solo `alta`/`media`) arregla Bodega pero **cambia el
comportamiento de Compras › Inventario (REQ-169, ya en producción)**: hoy un producto
`rotation=NULL` ahí SÍ cuenta en "En atención" por diseño aceptado de REQ-169, y con el
cambio dejaría de contar — una regresión de una feature en producción, no una corrección
aislada a Bodega. `isInAttention()` se usa también en `Compras\Home` y `Compras\Reportes`
(mismo cálculo compartido).

Esto requiere una decisión de Arquitecto/Analista, no una resolución unilateral de Pre-QA:
- **Opción A** — divergir la lógica: parámetro o método separado por pantalla
  (`isInAttentionBodega()` allowlist vs `isInAttentionCompras()` blocklist existente).
- **Opción B** — confirmar que REQ-169 debía ser allowlist también (cambiaría el
  comportamiento ya en producción de Compras › Inventario, necesita validación de negocio).
- Relacionado y probablemente la causa de fondo: confirmar con Analista si "Compra única"
  (REQ-419, hoy solo interpretación client-side de `rotation=NULL`) debería ser un 4º valor
  real de `rotation` en el backend, y si productos de Bodega deberían tener rotación asignada
  obligatoriamente en vez de quedar `NULL` por default.

## Escenarios de ruptura adicionales intentados (Paso 3)

- `reorder_point = NULL` → `?? 0` cubre el caso, sin excepción. OK.
- Punto de reorden exacto (`disponible == reorder_point`) → `<=` inclusivo, consistente con
  el resto del sistema (`stockState()` usa el mismo operador). OK.
- Chip "Atención" (SCRUM-420) y KPI "En atención" (SCRUM-416) confirmado que llaman al MISMO
  `isInAttention()` (`BodegaInventoryController.php:99`, `BodegaInventoryKpiService.php:57`) —
  un solo fix resuelve ambos tickets, como ya había notado marly.
- Chip "Por ingresar" e "Inactivos" de SCRUM-420: sin cambios de código, marly ya los aprobó
  explícitamente ("Lo que SI funciona") — no forman parte del hallazgo.

## Veredicto

**CRÍTICO — bloqueante.** `isInAttention()` no implementa la RN1 allowlist de REQ-346/REQ-350
para productos `rotation=NULL` ("Compra única"), porque el método está compartido con el
blocklist de REQ-169 (Compras, en producción) y arreglarlo sin coordinación regresionaría esa
pantalla. Ambos tickets quedan en `Dev Testing`. Notificado a PM para reasignar a
Arquitecto/Analista — decisión de diseño (Opción A vs B) antes de que Backend Dev implemente.

**Lo que sí funciona (verificado, no se toca):**
- Exclusión de `rotation='baja'` en "En atención" — correcta en ambas pantallas.
- Chip "Por ingresar" (Escenario 1 de SCRUM-420).
- Chip "Inactivos".
- RN1 de SCRUM-420 (chips combinables entre sí y con el resto de filtros).
- `reorder_point` NULL no rompe el cálculo.
- KPI y chip usan el mismo método (sin duplicación de lógica, un solo fix futuro resuelve ambos tickets).

No se sembró el producto `rotation='baja'` pedido originalmente porque ese escenario ya pasa
hoy — sembrarlo no habría reproducido ni cerrado el hallazgo real. No se tocó código ni se
hizo push (nada que commitear).
