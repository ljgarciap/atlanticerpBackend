# Módulo Legacy — schema `legacy` (Migración ICG→AtlanticERP)

> Movido desde `atlanticerp/CLAUDE.md` (`/doctor` 2026-09-07, migración a carga perezosa — este archivo
> solo carga cuando se trabaja bajo `app/Modules/Legacy/`). Ver también `atlanticerp/CLAUDE.md` para
> convenciones globales del proyecto y `Illumination/migracion-icg-diseno.md` para el diseño
> completo de la épica.

> Agregado 2026-08-12 (SCRUM-750, épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de solo
> referencia de datos importados de ICG Software (SQL Server), separado a propósito de los schemas
> nativos de AtlanticERP — nunca se fusiona automáticamente con `ventas_diseno`/`compras`
> (`Illumination/migracion-icg-diseno.md` §4/§5).
>
> **Estado (resumen — historial fecha-por-fecha movido a `docs/changelog.md`, sesión 2026-08-30,
> poda de higiene de contexto):** Productos+fotos (SCRUM-750/751), Clientes/Proveedores/
> Cotizaciones/Pedidos (SCRUM-755→758) y Albaranes/Facturas/Pagos (SCRUM-759→761) — **Tarea 6
> completa en los 3 ambientes (dev/test/prod)**, corregido 2026-09-04: Albaranes/Facturas/Pagos
> corrieron en prod ese día (verificado por Postgres directo, 0 errores en los 3 — ver comentarios
> de SCRUM-759/760/761), cerrando el pendiente que este archivo daba por abierto.
> ⚠️ Ningún registro encontrado de Senior Review/Pre-QA formal para la CORRIDA REAL de datos de
> 755→761 (sí existe para el código) — no bloqueante dado que las corridas en los 3 ambientes
> quedaron limpias (0 errores), pero sigue siendo un hueco de proceso a tener presente.
> **Gap nuevo encontrado 2026-09-04:** la "extracción incremental semanal" que el diseño de la
> épica da por resuelta (`migracion-icg-diseno.md` §8.1/§11) nunca se implementó en código — no
> hay ningún `legacy:sync-*` agendado en `routes/console.php`, todas las corridas hasta ahora
> fueron manuales. Tickets de seguimiento: SCRUM-838 (cron semanal) y SCRUM-839 (alerta si pasan
> 10+ días sin corrida exitosa, reemplazando el mecanismo original de Telegram — ya eliminado del
> workspace — por el tema SNS `atlanticerp-alerts` ya existente).
>
> **SCRUM-921/922 (2026-09-20):** `catalog_products.stock_quantity` ahora se siembra desde
> `STOCKS WHERE CODALMACEN='AL4'` de ICG (Bodega Central) — solo al CREAR el producto, nunca en
> re-sincronización (Bodega manda después); comando `legacy:backfill-stock-al4` (una sola corrida,
> pendiente de ejecutar en dev/test/prod) cubre los productos ya migrados antes de este ticket.
> Negativos de ICG se preservan tal cual (decisión de Luis). `stock_al4` nunca participa del hash
> de cambio del incremental — evita que el movimiento normal de un almacén en vivo dispare el
> circuit breaker. De paso, SCRUM-922: `legacy:sync-incremental-proveedores` reportaba
> `cargados=extraidos` (100% falso) en vez del delta real — corregido. Detalle en
> `docs/changelog.md`.
>
> **SCRUM-1114 (2026-10-06):** el stock de AL4 ya no es solo un seed — mientras ICG y AtlanticERP operan en paralelo,
> `legacy:sync-incremental-stock` (décimo paso de `legacy:sync-incremental-all`, tras `productos`) suma a AtlanticERP el
> DELTA de AL4 desde la última lectura (`round(AL4) - stock_al4_aplicado`, con signo) como movimiento de kardex
> `Ajuste` en Bodega Central. AtlanticERP es la fuente de verdad. Línea base: `legacy:init-stock-baseline` (una vez por
> entorno); ajustes/umbrales/interruptor en `legacy.stock_sync_settings` (pantalla de superadmin); reversa por id de
> corrida: `legacy:revert-stock-sync-run`. Runbook: `docs/runbooks/legacy-sync-stock-delta.md`.
>
> **SCRUM-1136 F1 (2026-10-13):** staging en crudo de ALMACEN, STOCKS (todos los almacenes), COSTESPORALMACEN,
> TRASPASOSCAB e INVENTARIOS en `legacy.{almacenes,stock_almacen,costo_almacen,traspasos_cab,inventarios_cab}` +
> `legacy.almacen_equivalencias` (manual). Comandos `legacy:sync-almacenes {--entidad=} {--dry-run}` (upsert paginado
> de 5.000 filas, una transacción por página, `--dry-run` revierte) y `legacy:verify-almacenes` (solo lectura). Sin FK a
> `bodega.*`; sin scheduler (F3); `movimientos` es F2. El staging es upsert-only: una fila borrada en ICG no se borra en staging y `legacy:verify-almacenes` la reportará como diferencia (reconciliación opcional, F3). Diseño: `docs/architecture/icg-staging-almacenes-diseno.md`.
>
> **SCRUM-1136 F2 (2026-10-15):** `legacy.movimientos` (MOVIMENTS de ICG: 1.384.677 filas, las 32 columnas reales, llave
> `icg_id` = `MOVIMENTS.ID`, `tipo` REG/TMP/ENV/MOD tal cual, TMP incluido). Carga ÚNICA y reanudable con
> `legacy:sync-movimientos {--tenant} {--dry-run} {--max-paginas=}`: paginación por llave (`TOP n WHERE ID > ? ORDER BY ID`,
> no OFFSET), páginas de 5.000, una transacción por página, reanuda sola desde `MAX(icg_id)` (tras `partial` basta volver
> a correrlo); `--max-paginas` carga por tandas; `--dry-run` revierte y se limita a 3 páginas por defecto. Registra la
> entidad `movimientos` en `legacy.sync_runs`. `legacy:verify-movimientos` (solo lectura) compara total, `MIN/MAX(ID)` y,
> por `tipo`, filas y suma de UNIDADES contra ICG. **NO hay scheduler:** la F3 se abandonó porque ICG está congelado
> (sin lectura incremental futura). Los `datetime` de ICG no traen zona: se interpretan en America/Panama y se guardan
> como `timestamptz`. El staging es upsert-only. **No correr dos `legacy:sync-movimientos` a la vez:** la reanudación usa
> `MAX(icg_id)` y podría dejar huecos; `legacy:verify-movimientos` detecta una carga incompleta. Diseño (secciones 2, 3, 11): `docs/architecture/icg-staging-almacenes-diseno.md`.
>
> **SCRUM-1137 F1 (2026-10-13):** copia en `legacy.clientes_raw` (110 columnas de CLIENTES, `CODCLIENTE`) y `legacy.contactos_cliente_raw` con `legacy:sync-clientes-raw {--tenant} {--dry-run}` y `legacy:verify-clientes-raw` (solo lectura). Lista fija de columnas excluidas en `Support/ClienteRawColumnas` (tarjeta, credenciales, foto); ningún endpoint expone estas tablas. La copia recorta espacios y convierte vacío en NULL (decisión aceptada, igual que almacenes). Upsert-only: una fila borrada en ICG queda en staging. Diseño: `docs/architecture/icg-clientes-staging-completo-diseno.md`.
>
> **SCRUM-1137 F3 (2026-10-14):** `legacy:fix-clientes-ruc {--tenant} {--apply --operador= --esperado=<huella> --confirmar=} {--revert=<run_id>} {--csv=}` corrige `sub_clients.tax_id`/`tax_id_dv` truncados de clientes ya enlazados (dry-run por defecto con huella del plan, precondiciones estrictas incl. sin documentos fiscales, `save()` con auditoría, reversa en `legacy.cliente_ruc_correcciones`, CSV privado). Prod solo lo corre Luis. Runbook: `docs/runbooks/legacy-fix-clientes-ruc.md`. Con `--cotizaciones` el mismo comando ajusta `quotes.ruc` (la copia del RUC de cada cotización) al RUC ya corregido del sub_client (`ClienteRucCotizacionSync`, reversa en `legacy.cotizacion_ruc_correcciones`); se corre después del apply de sub_clients.
>
> **Vinculación productos↔proveedores (2026-10-14):** `legacy:vincular-productos-proveedores {--tenant} {--apply --operador= --esperado=<huella>} {--revert=<id evento>}` llena `catalog_products.provider_id` (principal) y `catalog_product_providers` (todos) desde `producto_costos` + `legacy.proveedores.provider_id`, con dry-run/huella/reversa. Regla en `ProductoProveedorResolver`; runbook `docs/runbooks/legacy-vincular-productos-proveedores.md`. La sincronización automática de ICG está detenida desde 2026-10-14.
>
> **SCRUM-1136 F5 (2026-10-15) — descatalogados de ICG con stock:** el stock de ICG se asume real, así que los artículos `DESCATALOGADO='T'` con `STOCKS.STOCK <> 0` (95 medidos) se
> llevan a AtlanticERP sin tocar el flujo de activos. (1) `legacy:sync-productos-descatalogados {--tenant} {--dry-run}` (`ProductoDescatalogadoExtractor`, contrato propio) los stagea en
> `legacy.productos` (`descatalogado=true`)/`producto_precios`/`producto_costos` y fija la equivalencia por referencia (`ref_proveedor` recortada, sin distinguir mayúsculas; una referencia solo
> cuenta si tiene algún carácter alfanumérico y no es un token genérico tipo 'n/a') con los activos: guarda SOLO `legacy.productos.equivalente_icg_codarticulo`; el `catalog_product_id` del
> equivalente queda NULL y el catálogo se RESUELVE siguiendo el vínculo (`DescatalogadoEquivalenciaService::catalogoResuelto()` / `consultaConCatalogoResuelto()` = `COALESCE(p.catalog_product_id,
> e.catalog_product_id)`; el mapeo de stock debe usarlo) para no romper `legacy:backfill-stock-bodega-central` ni `StockDeltaService::verificarCuadre()`. Más de un activo con la misma referencia =
> ambigua (no se vincula); un descatalogado con catálogo propio = conflicto; un código que ya es ACTIVO en staging se omite (nunca se convierte). Imprime los pares descatalogado→activo. Aplica por
> defecto con opt-out `--dry-run` (como F1): correr SIEMPRE `--dry-run` primero. El dry-run revierte una transacción (no escribe). (2)
> `legacy:crear-descatalogados-inactivos {--tenant} {--apply --operador=}` (dry-run por defecto) crea `catalog_products` con `is_active=false` para los que tienen referencia, sin equivalente y sin
> colisión de `reference` (vía explícita `ProductoMapper::buildPayloads($codigos, true)`; la exclusión normal de descatalogados NO cambia); sin referencia se lista, no se crea. Idempotente, una
> transacción por producto, evento de auditoría `legacy.crear_descatalogados_inactivos`. NO escribe stock (ni bodega, ni kardex, ni `stock_quantity`): eso es la fase de mapeo de stock. (3)
> `legacy:verify-descatalogados {--esperados=} {--sin-icg}` (solo lectura) resume esperados/staged/equivalentes/creados inactivos/sin referencia/no resueltos y COMPARA la suma de `legacy.stock_almacen` por
> almacén contra ICG (`DescatalogadoStockIcgExtractor`, solo SELECT); sale con 1 si hay diferencias o si algún artículo con referencia no resuelve a catálogo (los sin referencia no cuentan). Ojo:
> `ARTICULOS.DESCATALOGADO` real es `'T'`/`'F'` (no `'V'`); `mapProductoRow` acepta ambos. Orden: `sync-almacenes` (F1) → `sync-productos-descatalogados` → `crear-descatalogados-inactivos` (el alcance
> de la creación es "descatalogado con stock <> 0 en `legacy.stock_almacen`"). Spec: `docs/specs/icg-descatalogados-con-stock.md`.
>
> ⚠️ **Pendiente de Tech Writer:** tablas de Clientes/Proveedores/Cotizaciones/Pedidos/Albaranes/
> Facturas/Pagos (migraciones `2026_08_14_*`/`2026_08_19_*`) no están en el bloque SQL de abajo —
> `app/Modules/Legacy/` es la fuente de verdad hasta que se documenten acá.

```sql
legacy.productos                       -- staging de ARTICULOS: activos (DESCATALOGADO='F') + (SCRUM-1136 F5) descatalogados ('T') con stock <> 0
  id, icg_codarticulo (unique), descripcion, familia_nombre, marca_nombre, ref_proveedor,
  descatalogado, catalog_product_id  FK nullable → ventas_diseno.catalog_products
  equivalente_icg_codarticulo        -- SCRUM-1136 F5: activo con la misma referencia (solo descatalogados con stock); sin FK;
                                     -- el equivalente NO guarda catalog_product_id (se resuelve por este vínculo)
  stock_al4                          -- STOCKS WHERE CODALMACEN='AL4', SCRUM-921; null = sin fila
  stock_al4_aplicado                 -- SCRUM-1114: último AL4 (entero) ya reflejado en AtlanticERP; base del delta;
                                     -- null = nunca cargado (inactivo/descatalogado), distinto de 0 conocido
  created_at, updated_at             -- en AL4 (nunca tuvo movimiento), distinto de 0 real
-- catalog_product_id lo setea el Loader-B (CatalogProductLoader) solo cuando el producto se carga
-- de verdad en catalog_products — nunca antes. Da trazabilidad bidireccional y es la base del
-- criterio "nunca pisar un producto no cargado por este pipeline" (ver más abajo).
-- familia_nombre/marca_nombre guardan el CÓDIGO crudo de ICG, no el nombre resuelto contra
-- FAMILIAS/MARCA — el join (PK compuesta NUMDPTO+NUMSECCION+NUMFAMILIA) nunca se confirmó contra
-- ARTICULOS.FAMILIA/SUBFAMILIA con data real. Deuda a resolver cuando se confirme la relación real
-- (ver docblock de App\Modules\Legacy\Extractors\ProductoExtractor).

legacy.producto_precios                -- staging de PRECIOSVENTA, solo las 4 tarifas vigentes
  id, icg_codarticulo FK, idtarifav, precio_neto, precio_bruto, descuento, descatalogado_fila,
  unique(icg_codarticulo, idtarifav)
-- Mapeo IDTARIFAV: 1=public, 2=project, 3=special, 5=partner (App\Modules\Legacy\Support\
-- PriceTypeMap). Tarifa 4 (D.BAHIA, vencida) excluida en PHP (defensa en profundidad, no confía
-- ciegamente en que ICG la auto-excluye vía su propio DESCATALOGADO).

legacy.producto_costos                 -- staging de PRECIOSCOMPRA, sin filtrar (1 fila por
  id, icg_codarticulo FK, codproveedor, costo_neto,                     -- artículo+proveedor)
  unique(icg_codarticulo, codproveedor)
-- Selección del costo (ProductoCostoResolver): proveedor 1001 si existe; si no, el único proveedor;
-- si hay varios, el de la compra más reciente (albaranes_compra.fecha); empate o sin compras → vacío.
-- Se aplica solo al mapear hacia catalog_products.cost, nunca se pierde el resto de proveedores en
-- staging. Backfill de los ya migrados: legacy:backfill-costo-proveedor (ver su runbook).

legacy.sync_runs                       -- una fila por corrida REAL (--dry-run nunca registra acá)
  id, entidad, extraidos, staged, mapeados, cargados, excluidos, errores, duracion_ms,
  status (success|partial|failed|held_for_review), resumen JSONB (SCRUM-1114, solo entidad `stock`),
  started_at, finished_at, created_at

legacy.stock_sync_settings             -- SCRUM-1114, fila única id=1 (patrón compras.settings)
  activo, baseline_inicializada_at, max_productos_con_delta_pct, max_unidades_abs_por_producto,
  max_unidades_abs_total, correos_alerta JSONB  -- umbrales del circuit breaker: nada hardcodeado
```

**Módulo `App\Modules\Legacy`** — Extractor→Loader(staging)→Mapper→Loader(destino), sin
microservicio (mismo patrón que el resto del proyecto, `Illumination/migracion-icg-diseno.md` §10).
`ProductoExtractor` (conexión Postgres/sqlsrv nueva `legacy_icg`, `config/database.php`) separa
construcción de query / mapeo de filas / acto de conectar — testeable con fixtures, sin SQL Server
real. Comando: `legacy:sync-productos {--tenant=atlantic} {--dry-run}`. `CatalogProductLoader`
("Loader-B") nunca pisa un `catalog_products` existente que no haya sido cargado por este mismo
pipeline — criterio conservador, ver docblock de la clase.

**Stock por bodega (SCRUM-1016):** el stock inicial de ICG (AL4 = Bodega Central) vive en `catalog_products.stock_quantity` (SCRUM-921) y TAMBIÉN en
`product_warehouse_stock` (Bodega Central): `CatalogProductLoader` crea la fila al CREAR el producto (misma transacción, negativos tal cual; nunca en una
re-sincronización) y `legacy:backfill-stock-bodega-central {--tenant} {--apply --operador=}` (dry-run por defecto) siembra los ya migrados que no tengan ninguna fila por bodega.
Runbook y orden por entorno: `docs/runbooks/legacy-backfill-stock-bodega-central.md`.

**Stock de los demás almacenes de ICG (SCRUM-1136 F6, cargado en dev/test/prod 2026-10-16, `v1.49.0.0`):** `legacy:cargar-stock-bodegas` (+ `crear-articulos-sin-catalogo`, `verify-stock-bodegas`,
`revertir-stock-bodegas`, dry-run por defecto, `--apply --operador=`) siembra stock + Kardex + agregado en las 6 bodegas equivalentes (`legacy.almacen_equivalencias`, `AlmacenEquivalenciaSeeder`, fuera de `DatabaseSeeder`).
Exclusiones en `config/legacy.php` (2999, 68602). Runbook `docs/runbooks/icg-stock-a-bodegas.md`; historial en `docs/changelog.md`.
