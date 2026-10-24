# Senior Review — fallback de costo por proveedor (commit d00ef95b)

**Veredicto: APROBADO con 2 hallazgos menores, corregidos en el mismo cambio.**
Spec aprobada por Luis 2026-10-14 (caso A691-451471527K; alcance medido en prod: 1.335 un proveedor + hasta 27 varios).

Revisado: `ProductoCostoResolver`, `ProductoMapper`, `legacy:backfill-costo-proveedor`, pruebas y runbook. Suite Legacy 847/847, PHPStan OK sobre lo tocado.

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| 1 | Menor | `Legacy/CLAUDE.md` y un comentario de prueba aún citaban `ProductoMapper::resolveCost()`/`DEFAULT_CODPROVEEDOR`, ya movidos al resolver | Corregido |
| 2 | Menor | El runbook prometía «nunca pisa un costo existente» sin aclarar que el sync incremental sí reescribe `cost` de los productos que creó (heredado, no nuevo) | Documentado como limitación; decisión de negocio aparte |

Verificado sin hallazgos: SQL solo interpola nombres de tabla (ningún dato del usuario); `--esperado` con `hash_equals`; UPDATE con `cost IS NULL` y `affected = 1` por fila, todo en una transacción con recálculo del plan; reversa condicionada a `cost = valor aplicado`; desempate por fecha estricto (empate o sin compras → vacío, nunca adivina); consulta de compras acotada a artículos con varios proveedores y por lotes de 1.000; los 23 con costo 1001 y los sin costo en ICG quedan fuera por diseño; dry-run sin escrituras; idempotente.

Pendiente para Pre-QA: dry-run en dev con el catálogo real (contar contra lo medido en prod) y verificar A691-451471527K → $480.06.
