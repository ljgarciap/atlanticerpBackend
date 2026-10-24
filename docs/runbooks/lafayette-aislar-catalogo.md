# Runbook — `lafayette:aislar-catalogo` (SCRUM-990)

Limpieza de una sola vez que separa los productos de Lafayette del catálogo de AtlanticERP. Diseño: `docs/architecture/scrum990-aislar-lafayette-diseno.md`.
**Nunca se ejecuta en test/prod sin aprobación explícita de Luis y un dry-run previo en ese mismo entorno.**

## Ventana de mantenimiento (N1)
`--apply` toma `LOCK TABLE ... IN SHARE ROW EXCLUSIVE MODE` sobre el catálogo, el stock por bodega, `lafayette_productos` y las tablas de historial/referencias:
**bloquea las escrituras** a esas tablas mientras dura la transacción. Antes de aplicar:
1. Pausar Horizon, el sync ICG y `lafayette:sync-productos` (el scheduler de Lafayette), y preferir modo mantenimiento de la app.
2. Respaldo verificado de la BD del entorno (el comando crea además tablas `*_bak_scrum990`, con retención/DROP a decidir después).
3. Correr el dry-run (sin `--apply`) y revisar bloqueos, desfases y la lista de historial que se anulará.

Tiempos de espera (valores en segundos, aplican con `SET LOCAL` solo dentro de la transacción y se revierten con ella):
- `--lock-timeout=5` (defecto): si otra transacción retiene un bloqueo conflictivo más de ese tiempo, el comando aborta con
  `ROLLBACK: la limpieza NO se aplicó. Se agotó el tiempo de espera...` y no queda nada aplicado. Reintentar tras pausar lo concurrente.
- `--statement-timeout=600` (defecto): tope por sentencia (10 min).

## Invocación
Con el rol de migración (`DB_MIGRATE_*`), y `--operador` OBLIGATORIO con `--apply` (queda en el evento de auditoría junto con usuario del SO y host):

    php artisan lafayette:aislar-catalogo --tenant=atlantic --apply --operador="<nombre>"

En test/prod la confirmación es interactiva (`APLICAR <app.env> <tenant>`); `--yes` solo se acepta en `local`/`testing`.

## Guarda del sincronizador y recorte real del catálogo de Lafayette (B2)
`lafayette:sync-productos` solo marca como "fuera de la fuente" los productos que no vinieron si la extracción trae al menos `lafayette_guarda_min_ratio`
(defecto **0,9**) de lo que trajo la última corrida **success**. Si esa fracción no se alcanza, la corrida queda `partial` con `guarda_activada` y NO se oculta nada.

**Riesgo:** si Lafayette recorta de verdad y de forma permanente su catálogo (p. ej. -60 %), la guarda quedará activa para siempre: corridas `partial`
consecutivas con `guarda_activada`, y los productos retirados seguirán visibles en Zona Libre. Es el modo seguro (no destruye ni oculta datos), pero hay que ajustarlo.

Cómo resolverlo (síntoma: 2 o más corridas `partial` seguidas con `guarda_activada` y `extraidos` estable muy por debajo de la última `success`):
1. Confirmar con Lafayette que el recorte es real (no un fallo de la vista/extracción).
2. Si el recorte es menor al 50 %: como superadmin, `PATCH /api/settings/system` con `{"lafayette_guarda_min_ratio": <0.5..1.0>}` por debajo de `extraidos / última_success`.
   Si el recorte supera el 50 % el umbral mínimo permitido (0,5) no alcanza: hay que obtener una corrida `success` de referencia (decisión de Luis; hoy requiere intervención de
   desarrollo sobre la línea base) — no se baja el umbral por debajo de 0,5 porque dejaría de proteger el catálogo.
3. Correr `lafayette:sync-productos`; al cerrar en `success` se fija la nueva línea base. Volver a subir el umbral a 0,9 después.
Cada cambio queda auditado (`settings.updated`, entidad `lafayette_guarda_min_ratio`).

## Puros referenciados y historial contra Zona Libre (ajuste por el dry-run real en TEST, 2026-10-02)
Decisiones de Luis tras el dry-run en test (que reportó 2 productos puros usados por documentos y 2 líneas de `order_items` contra Zona Libre):

- **Puros referenciados se PROMUEVEN, no se borran.** Un producto "puro" (lo creó el sincronizador, sin enlace ICG) con referencias en CUALQUIER tabla con
  `catalog_product_id` (cotizaciones, pedidos, familias, stock en otras bodegas...) deja de bloquear: pasa a la categoría **PROMOVER**. `--apply` solo le quita la marca de
  Lafayette (`external_source` y `external_synced_at` a NULL) dentro de la misma transacción; conserva nombre, precio, proveedor y referencia, y los documentos quedan intactos.
  Los puros SIN referencias se siguen borrando. El dry-run los lista aparte (id, referencia, nombre y qué tablas/cuántas líneas los usan).
  Verificación posterior: siguen existiendo sin marca, valores iguales al respaldo, mismo conteo de referencias antes/después, el respaldo (`*_bak_scrum990`) incluye sus filas,
  y `catalog_products` queda en `total - puros_borrados`. La regla de evidencia (`--permitir-puros-sin-evidencia`) sigue aplicando a los puros referenciados.
  Efecto en la auditoría: `puros_promovidos` e `ids_puros_promovidos` en el evento resumen.
- **Historial conservable contra Zona Libre.** Con `--conservar-historial-zona-libre`, las tablas `order_items`, `adjustment_request_lines`, `relocation_requests` y
  `warehouse_locations` con filas contra la bodega se aceptan y NO se tocan (la bodega se conserva sin stock); el dry-run las lista aparte ("se CONSERVA tal cual").
  Sin la bandera siguen rechazando, y cualquier tabla desconocida con filas contra Zona Libre rechaza siempre (también con la bandera).
- **Estado por entorno:** en **dev** ya se aplicó la versión anterior del comando (no hay nada que migrar ni repetir). En **prod** las referencias a puros eran 0 en el
  diagnóstico, así que el cambio no altera su plan. **Test** es el entorno que motivó el ajuste: repetir el dry-run con la versión nueva (con `--conservar-historial-zona-libre`).

## `fuente_actualizado_at` y el sincronizador (ajuste 2026-10-01)
Lafayette reconstruye su caché completa poco antes de cada corrida (en prod, todas las filas de la vista traen un `cache_updated_at` nuevo), así que comparar esa marca en el upsert hacía que
`lafayette:sync-productos` contara las ~25.8K filas como actualizadas en cada corrida sin cambio de datos. Ahora **`fuente_actualizado_at` ya no entra en la comparación** de `LafayetteProductoLoader`:
si solo cambió esa marca, la fila cuenta como "sin cambios" y no se escribe (`updated_at` intacto); si cambia un dato de negocio o reaparece una fila fuera de fuente, se actualiza todo, incluida `fuente_actualizado_at`.

Consecuencia: `fuente_actualizado_at` (que `GET /bodega/zona-libre/productos` expone como `synced_at` por fila) significa ahora "última vez que cambió el dato de esa fila en la fuente", NO "última reconstrucción de
caché de Lafayette". La fecha de última sincronización que ve el usuario (`meta.ultima_sincronizacion`) sale de la última corrida (`LafayetteSyncRun.finished_at`) y no cambia. Nada más usa esta columna como indicador
de frescura (se revisó `ZonaLibreCatalogoService`, el resumen cacheado, la guarda del sincronizador y los tests del contrato).
