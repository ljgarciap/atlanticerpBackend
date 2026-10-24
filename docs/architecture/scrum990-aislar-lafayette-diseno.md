# SCRUM-990 — Diseño: aislar los productos de Lafayette (Zona Libre) del inventario de Atlantic

Rol: Arquitecto. Estado: BORRADOR PARA APROBACIÓN DE LUIS (no hay implementación, no se ejecutó ningún cambio destructivo).
Insumos: `docs/specs/scrum990-aislar-lafayette.md` (decisiones de Luis resueltas), memoria del proyecto (SCRUM-969/973) y
lecturas de solo conteo/estructura en dev (2026-09-30). **No se consultó test ni prod.**

## 0. Hallazgos que fundamentan el diseño (medidos en dev)

| Hecho | Valor | Consecuencia |
|---|---|---|
| Productos de origen Lafayette en `catalog_products` | 25.816 | 17.815 puros + 8.001 ICG enlazados (= 25.816) |
| Puros (creados por el sync el 2026-09-26; incluye 11 "coexistentes") | 17.815 | No tienen NINGUNA referencia salvo su fila de stock en Zona Libre (0 en familias, kardex, pedidos, cotizaciones, stock de otras bodegas, `stock_quantity`, `cost`). Borrado seguro |
| ICG enlazados (creados el 2026-08-12 por el import de ICG, enlazados a `legacy.productos` el 2026-09-20) | 8.001 | El sync los "vinculó" y los sobrescribe en cada corrida. Se quedan en AtlanticERP |
| De esos 8.001: nombre aún igual a ICG | solo 1.565 | Daño real: `provider_id`, `factory_reference` y `category` pisados en los 8.001; `brand` distinto en 7.990; foto externa en 3.934 |
| Los 4 productos usados por operaciones/cotizaciones de prueba | 4, TODOS del grupo ICG | La limpieza de los puros no toca ningún documento |
| `audit_logs` `catalog_product.updated` | 172.477 filas (de 253.178) | El trait `LogsAuditTrail` del catálogo inundó la auditoría con cada corrida del sync. **Pero guarda `old_values` por campo: es fuente exacta de restauración** (los 8.001 tienen >= 1 evento, media 8,8) |
| Stock por bodega | Zona Libre 25.833 filas / 1.767.865 u; Central 3.357 / 85.402 | Zona Libre pesa 20x el inventario real: cualquier suma sin filtro queda dominada por Lafayette |
| Flujo "Solicitudes Zona Libre" (SCRUM-440) | Compra al proveedor "Zona Libre de Colón" (`ComprasSettings.zona_libre_provider_id`), productos del catálogo de AtlanticERP por proveedor | **No depende** ni del stock de la bodega Zona Libre ni de productos Lafayette |
| Referencias de prueba a la bodega Zona Libre (id 7) | movimientos 8, recepciones de compra 6, devoluciones 1, conteos 1 | Hoy se puede elegir Zona Libre como destino operativo: hace falta bloquearlo |
| Agendado del sync | prod 12:00 y 22:00 todos los días; dev sábado 22:00; test domingo 22:00 | Mientras el código viejo siga desplegado, prod (si tiene credenciales Lafayette) sigue contaminando. **No verificado** |

Causa raíz: `App\Modules\Integrations\Lafayette\Services\CatalogProductLoader` escribe en el catálogo compartido y en
`product_warehouse_stock`, asigna `provider_id` Lafayette a productos ICG sin proveedor (regla "vincular") y los actualiza en cada corrida.
El import de ICG (`App\Modules\Legacy\Services\CatalogProductLoader`) nunca asignó `provider_id`, `category` ni `external_*`, y solo
refresca `name/brand/price_full/cost` de productos que posee; por eso ambos se pisaban.

## 1. Modelo de datos de la tabla nueva

**Decisión:** tabla `{tenant}_bodega.lafayette_productos`, en el schema `bodega`. Motivos: el único consumidor es Bodega > Zona Libre; `bodega`
ya está en `Tenant::schemas()` (no hace falta un schema nuevo ni tocar la creación de tenants); queda fuera de `ventas_diseno`, donde vive el
catálogo que cotiza. Modelo `App\Modules\Integrations\Lafayette\Models\LafayetteProducto` con `getTable()` resolviendo el schema como
`LafayetteSyncRun`. **El modelo NO usa `LogsAuditTrail`** (repetiría la inundación de 172K eventos); la trazabilidad es el resumen de cada
corrida (`AuditService::BODEGA_LAFAYETTE_SYNC_COMPLETED`, ya existe).

| Columna | Tipo | Origen (`vista_productos_lighting`) | Nota |
|---|---|---|---|
| `id` | bigserial PK | — | orden estable de paginación |
| `lafayette_id` | varchar(50) NOT NULL, **UNIQUE** | `id` (ej. `LNP0000001`) | clave de dedup/upsert |
| `referencia` | varchar(100) NULL | `referencia` | sin exclusión: se guarda tal cual viene |
| `descripcion` | text NULL | `descripcion` | idem |
| `marca` | varchar(255) NULL | `marca` | |
| `categoria` | varchar(255) NULL | `categoria` | |
| `url_imagen` | text NULL | `url_imagen` | URL externa, sin re-subir |
| `disponible` | integer NOT NULL default 0 | `disponible` (`max(0, ...)`) | etiqueta UI "Disponible (Lafayette)" |
| `precio_5` | numeric(15,4) NULL | `precio_5` | etiqueta UI "Precio Lafayette"; decisión de Luis 2026-09-25 (reversible) |
| `fuente_actualizado_at` | timestamptz NULL | `cache_updated_at` | fecha del dato en la fuente |
| `fuera_de_fuente_at` | timestamptz NULL | — | se marca si el producto desaparece de la fuente (ver 2) |
| `created_at`, `updated_at` | timestamptz | — | |
| `search_text` | text GENERATED STORED | `lafayette_id + referencia + descripcion + marca` | mismo patrón que `catalog_products.search_text` (`public.f_unaccent(lower(...))`) |

Como el modelo ya no necesita `reference`/`name` obligatorios, **desaparecen las exclusiones del Mapper** (antes 8.012 filas "excluidas" en
las primeras corridas por falta de `referencia`/`descripcion`): el espejo guarda todo lo que la fuente trae (25.816).

**Índices** (con ~25.800 filas, p95 < 500 ms, pico < 64 MB, `memory_limit` 256M):
- `UNIQUE (lafayette_id)` (upsert).
- GIN trigram sobre `search_text` (`public.gin_trgm_ops`), igual que `catalog_products_search_text_trgm_idx`. Reutiliza
  `CatalogProduct::applySearch($query, $term, fuzzyFallback, 'lp.')`, que ya es estático, recibe cualquier builder y prefija `search_text`.
  `relevanceRank()` asume columnas `reference/factory_reference/name`, así que se escribe un `LafayetteProducto::relevanceRank()` propio
  (exacto > prefijo > resto) sobre `referencia/lafayette_id/descripcion`. Refactor opcional posterior: mover ambos a un trait compartido.
- btree parcial `(categoria) WHERE fuera_de_fuente_at IS NULL` para el filtro de categoría y btree `(disponible)` para "solo con stock".
- Listado: `paginate()` sobre la tabla con `LIMIT/OFFSET`, conteo con un solo agregado (`count(*)` + `sum(disponible)`), **nunca `get()` sin límite**.
  Con `per_page` máximo el de `Pagination::params` (ya acotado).

Migración en `database/migrations/tenant/` (NUNCA en `database/migrations/`, error ya cometido en SCRUM-970) extendiendo
`TenantSchemaMigration` con `protected string $module = 'bodega'`. Las extensiones `pg_trgm`/`unaccent` y `public.f_unaccent()` ya existen.

**Indicador de bodega:** nueva columna `warehouses.solo_consulta boolean default false`, backfill `true` para "Bodega Zona Libre", y
**reemplaza a `mostrar_sin_stock`** (SCRUM-973), que solo servía para esta bodega y deja de tener sentido (la tabla nueva siempre lista todo,
también con `disponible = 0`). Mismo patrón que `excluye_disponible`: sin nombres fijos en el código. `mostrar_sin_stock` se elimina en la
migración de limpieza final (ver 8, release E) para que el rollback inmediato siga posible.

## 2. Reescritura del pipeline `app/Modules/Integrations/Lafayette`

Se conservan: `ProductoExtractorContract`, `ProductoExtractor` (un solo `SELECT` de 9 columnas, 0,44 s medido), `LafayetteProductoRow`,
`ExtractedProductos`, `CircuitBreaker('lafayette_lighting')`, el agendado por ambiente (12:00/22:00 prod, sáb dev, dom test) y la conexión
`lafayette_lighting` con el certificado de hoja en `storage/certs`.

| Pieza | Cambio |
|---|---|
| `ProductoMapper` | Mapea a `MappedLafayetteProducto`; **sin exclusiones**; `disponible = max(0, disponible)`. Pura, testeable sin DB |
| `CatalogProductLoader` (Lafayette) | **Se elimina.** Nuevo `LafayetteProductoLoader`: upsert por `lafayette_id` en lotes de 1.000 con `INSERT ... ON CONFLICT (lafayette_id) DO UPDATE SET ... WHERE (campos) IS DISTINCT FROM (EXCLUDED...)`. Solo escribe las filas que cambian (la 2a corrida sin cambios escribe 0 filas: menos WAL y cero inflado). Reaparición: `fuera_de_fuente_at = NULL` |
| Producto que desaparece de la fuente | Tras una corrida COMPLETA y exitosa se marca `fuera_de_fuente_at = now()` (`UPDATE ... WHERE fuera_de_fuente_at IS NULL AND lafayette_id <> ALL(:vistos)`); el listado los oculta por defecto. **Nunca se borra**. Guarda de seguridad: si `extraidos = 0` o cae por debajo del 50 % de la corrida previa, no marca nada y registra `partial` |
| Se eliminan del código | ramas `created/linked/coexisting` sobre `catalog_products`, `upsertStock()` a `product_warehouse_stock`, `resolveProviderId()`, `resolveWarehouseId()`, y toda escritura en `catalog_products`/`warehouses` |
| `LafayetteSyncProductosCommand` | Mismo flujo (extrae → mapea → carga → registra corrida → auditoría). `--dry-run` reporta conteos sin escribir (mismo criterio). `memory_limit` 512M se mantiene; el reporte cambia a: extraídos, insertados, actualizados, sin cambios, fuera de fuente, errores |
| `LafayetteSyncRun` (`ventas_diseno.lafayette_sync_runs`) | Se conserva la tabla y su historial. Migración nueva agrega `sin_cambios` y `fuera_de_fuente`. `vinculados`, `coexistentes` y `excluidos` quedan en 0 hacia adelante (se eliminan en un ticket de limpieza posterior, no ahora, para no destruir historial) |
| Columnas `catalog_products.external_source`, `external_synced_at` | Se eliminan **después** de limpiar los 3 entornos (release E). `CatalogProduct` pierde `@property`, `$fillable` y `casts` de esas dos columnas |
| Provider "Lafayette Lighting" (Compras) | **Se conserva** (`LafayetteLightingProviderSeeder`): Compras puede asignarlo a productos creados a mano. El loader deja de depender de él |

Estado de auditoría: los 172.477 eventos ya escritos **se quedan** (`audit_logs` es inmutable a nivel de rol de Postgres, REQ-634); el cambio evita
que la inundación continúe. La limpieza se ejecuta con el rol de migración (superusuario) y por SQL directo para no generar otros miles de eventos
`deleted`/`updated`; se registra UN evento resumen de auditoría.

## 3. Zona Libre de solo consulta: endpoints y UI

### Estado actual
- `GET /bodega/warehouses` (`WarehouseController::index`) alimenta las pestañas de `BodegasPage.tsx` (804 líneas, tabla de 15 columnas pensada para
  bodegas con ubicación física: Por servir, Por ingresar, En camino, Stock mínimo, Acción "Reubicar", etc.).
- `GET /bodega/warehouses/{id}` (`show`) arma la lista desde `ProductWarehouseStock` de esa bodega; con Zona Libre era lo que agotaba memoria (SCRUM-973/996).
- El frontend identifica Zona Libre **implícitamente** por `modo_detalle === 'pendiente'` (oculta "Reubicar" y la excluye como destino).

### Diseño
1. **`index()`** agrega `solo_consulta` al payload (Zona Libre sigue apareciendo como una bodega más en Vista Bodegas, como pide el ticket).
   El frontend deja de inferirlo por `modo_detalle`.
2. **Endpoint de lectura dedicado** `GET /bodega/zona-libre/productos` (permiso `bodega.read`, igual que el resto de Bodega), parámetros
   `search`, `categoria`, `con_stock` (bool), `page`, `per_page`, `sort`. Respuesta: `{ data: [...], meta }` con el `Pagination::meta` habitual.
   Fila: `lafayette_id`, `referencia`, `descripcion`, `marca`, `categoria`, `url_imagen`, `disponible`, `precio` (= `precio_5`), `fuente_actualizado_at`.
   Oculta `fuera_de_fuente_at IS NOT NULL`. **Incluye productos con `disponible = 0`** (decisión de Luis 2026-09-26: catálogo de lo que el proveedor puede proveer).
3. **`show()`** para una bodega `solo_consulta` no consulta `ProductWarehouseStock`: devuelve `warehouse` y `kpis` (`total_products`, `total_units` = suma
   de `disponible`, `last_synced_at` = `finished_at` de la última corrida `success`/`partial` de `LafayetteSyncRun`) con `data: []`. Así el selector y las
   tarjetas siguen usando el mismo hook y se reemplaza la consulta que agotaba memoria. `responsable`/`capacidad_pct` siguen siendo los de la fila `warehouses`.
4. **Sin ningún endpoint de escritura** sobre la tabla: no hay POST/PUT/PATCH/DELETE bajo `/bodega/zona-libre`. Un test de rutas lo afirma (ver 7).
5. **La fila "Bodega Zona Libre" de `warehouses` se conserva** (con `solo_consulta = true`) para que aparezca como pestaña; **sin filas en
   `product_warehouse_stock`**. Sus locations (`warehouse_locations`) siguen en 0.

### UI (atlanticerp-frontend)
- `types/bodega.ts`: `PhysicalWarehouse.solo_consulta: boolean`; nuevo tipo `ZonaLibreProducto`.
- `api/bodegaApi.ts` + `hooks/useBodega.ts`: `zonaLibre.productos(params)` y `useZonaLibreProductos`.
- `BodegasPage.tsx`: si `data.warehouse.solo_consulta`, renderiza un componente nuevo `ZonaLibreCatalogoTable` en lugar de la tabla de 15 columnas, con:
  aviso permanente "Solo consulta — datos de Lafayette Lighting, no forman parte del inventario de Atlantic" y la fecha de última sincronización;
  columnas Ref. Lafayette, Referencia, Producto, Categoría, Marca, Disponible (Lafayette), Precio Lafayette; buscador con debounce, filtro de categoría,
  interruptor "Solo con disponibilidad"; **sin acciones, sin botón Reubicar, sin Ver detalle**; estado vacío, cargando y error con Reintentar.
  Se ocultan los botones de ubicaciones y solicitudes de reubicación mientras la pestaña activa es de solo consulta.
- Textos en i18n `bodega` es/en; sin emojis; iconos de `components/icons/`. Conviene una spec visual breve de UX/UI (no hay mockup en el ticket).
- Las pantallas Compras > Catálogo y Stock > Inventario y Bodega > Inventario > Ver Inventario **no cambian**: tras la limpieza ya no ven datos de Lafayette.

## 4. Impacto en los consumidores de stock (25 archivos)

Premisa: con la tabla aislada y la limpieza, **no existe ninguna fila de Lafayette en `catalog_products` ni en `product_warehouse_stock`**, así que la
mayoría de los consumidores queda correcta sin tocarse. Se exigen cambios solo donde el código (a) lista todas las bodegas, o (b) acepta un `warehouse_id`
de escritura. Columna "Verificado": L = leí el punto relevante; I = inferido por el conteo de referencias (sin lectura línea a línea).

| Archivo | Veredicto | Cambio mínimo | Verificado |
|---|---|---|---|
| `Bodega/Models/ProductWarehouseStock` | Cambia | `saving` rechaza escritura si la bodega es `solo_consulta` (punto único de control) | L |
| `Bodega/Models/Warehouse` | Cambia | `solo_consulta` reemplaza `mostrar_sin_stock`; `scopeOperativas()` | L |
| `Bodega/Http/Controllers/WarehouseController` | Cambia | `index()` agrega `solo_consulta`; `show()` bifurca a KPIs de catálogo externo; se elimina la rama `mostrar_sin_stock` | L |
| `Bodega/Services/WarehouseStockService` | Cambia | `breakdownForProduct(s)` y el listado de filas recorren `Warehouse::orderBy('id')->get()` (líneas 48 y 77): usar `operativas()` para que no aparezca una fila Zona Libre con 0 en el desglose de cada producto | L |
| `Bodega/Services/BodegaReportsService` | Cambia | línea 143 lista todas las bodegas: usar `operativas()` | L |
| `Compras/Http/Controllers/InventoryController` | Cambia | línea 759 (`/compras/warehouses`, selector de bodegas): `operativas()`. El listado de productos no cambia | L |
| `Bodega/Http/Controllers/RelocationRequestController` | Cambia (menor) | hoy bloquea origen/destino por `modo_detalle = pendiente`; agregar el chequeo por `solo_consulta` (mantener el otro) | L |
| `Bodega/Http/Controllers/GeneralCountController` | Cambia (validación) | `warehouse_id` debe ser bodega operativa (hoy 1 conteo de prueba apunta a Zona Libre) | L |
| `Bodega/Http/Controllers/AdjustmentRequestController` (no estaba en la lista, escribe `warehouse_id`) | Cambia (validación) | idem: líneas solo sobre bodegas operativas | L |
| `Compras/Http/Controllers/GoodsReceiptController` | Cambia (validación) | bodega de ingreso debe ser operativa (hoy 6 recepciones de prueba van a Zona Libre) | I |
| `Compras/Services/GoodsReceiptConfirmationService` | Sin cambio | escribe vía el modelo, protegido por el guard | I |
| `Compras/Http/Requests/StoreInventoryProductRequest` | Cambia (validación) | `warehouse_id` inicial operativo | I |
| `Bodega/Http/Controllers/BodegaInventoryController` | Sin cambio | lee `stock_quantity` y desgloses por bodega; sin filas Zona Libre tras la limpieza | L |
| `Compras/Services/InventoryKpiService` | Sin cambio | `excluye_disponible` ya se aplica; sin filas de Lafayette el Total vuelve a ser el real | L |
| `Bodega/Services/StockReservationService`, `PickingService`, `DeliveryService` | Sin cambio | asignan desde filas de stock existentes; no habrá filas de Zona Libre; el guard lo impide si alguien lo intenta | I |
| `Bodega/Services/InventoryReviewService`, `KardexService` | Sin cambio | los 8 movimientos de prueba contra Zona Libre quedan como historial (ver decisiones) | I |
| `Servicios/Models/InsumoSetting`, `Servicios/Services/InsumoService` | Sin cambio | resuelven Reserva Servicios y Bodega Central por nombre | L |
| `VentasDiseno/Http/Controllers/CatalogUatTestDataCleanupController`, `Services/TestDataCleanupService` | Sin cambio | verificar (en Pre-QA) que no limpien stock por nombre de bodega | I |
| `VentasDiseno/Models/CatalogProduct` | Cambia (release E) | quitar `external_*` | L |
| `Integrations/Lafayette/Services/CatalogProductLoader` | Se elimina | reemplazado por el loader de la tabla nueva | L |
| `Console/Commands/LafayetteSyncProductosCommand` | Se reescribe | ver 2 | L |

Gerencia: ninguno de los 25 archivos es de `Gerencia`; su dashboard agrega datos de otros módulos por sus servicios. Se cubre con una prueba de regresión
(ver 7), no se modificó por lectura. **No verificado**: que ningún servicio de Gerencia sume `product_warehouse_stock` por una vía distinta a las listadas.

Defensa en profundidad opcional (recomendada): trigger `BEFORE INSERT OR UPDATE` en `product_warehouse_stock` que rechaza filas de bodegas con
`solo_consulta = true`. Protege incluso contra SQL directo; no bloquea los `DELETE` de la limpieza.

### Flujo Compras <-> Bodega "Solicitudes Zona Libre" (SCRUM-440)
`BodegaZonaLibreRequest` y sus líneas (`bodega_zona_libre_request_lines.catalog_product_id`, FK `ON DELETE RESTRICT`) eligen productos **del catálogo de AtlanticERP
del proveedor "Zona Libre de Colón"**; `ComprasZonaLibreRequestController` crea la orden de compra a ese proveedor. No lee stock de la bodega Zona Libre ni
productos Lafayette (0 líneas en dev apuntan a productos de origen Lafayette). **Independiente: no requiere cambios.** Ojo de nombres: es la misma palabra
"Zona Libre", pero es una compra a un proveedor, no la bodega de solo consulta.

## 5. Otros usuarios de los productos de Lafayette en `catalog_products`

| Usuario | Qué pasa tras el cambio |
|---|---|
| Ventas & Diseño: buscador de catálogo y cotizaciones | Solo productos de AtlanticERP (decisión de Luis). Las 7 líneas de cotización de dev que usan productos de origen Lafayette son de productos ICG que se quedan (los 4 productos usados están en el grupo ICG), no se rompen |
| Compras: `CatalogProductSearchController` + `SubstituteCandidatePreselector` ("Sustitutos", SCRUM-771) y órdenes de compra por proveedor | **Consecuencia que hay que aprobar:** estos componentes se optimizaron para el catálogo de ~26K productos del proveedor Lafayette. Sin esos productos en el catálogo, el proveedor "Lafayette Lighting" queda sin productos candidatos hasta que se creen a mano. Sin cambio de código |
| `replacement_requests` | 1 referencia a un producto de origen Lafayette en dev (FK `original_catalog_product_id` es `RESTRICT`, `proposed_...` es `SET NULL`). Se revisa en el pre-chequeo de la limpieza |
| `catalog_pdf_exports` (exportación PDF de catálogo) | El catálogo baja de 29.636 a ~11.821 productos; las exportaciones futuras serán más pequeñas. Sin cambio de código |
| Servicios (`service_quote_items`, `ticket_products`, `insumo_*`, `inspection_report_materials`) | 0 referencias a productos de origen Lafayette en dev |
| Integración Legacy/ICG (`legacy.productos.catalog_product_id`, `producto_foto_hashes`) | Sigue enlazando a los 8.001 productos ICG restaurados; los enlaces a filas puras = 0 |

## 6. Plan de LIMPIEZA de datos existentes (destructivo — solo descrito, nada ejecutado)

Regla: todo DELETE/UPDATE masivo requiere confirmación explícita de Luis **por entorno**, respaldo previo y verificación de conteos. Se propone un
comando de una sola vez, `lafayette:aislar-catalogo {--tenant=atlantic} {--dry-run (por defecto)} {--apply}`, en vez de SQL ad hoc: es testeable con
fixtures, transaccional, repetible entre entornos y deja reporte. Se retira del repositorio tras limpiar los 3 entornos (ticket de retiro). Se ejecuta con
las credenciales de migración (`DB_MIGRATE_*`, como ya hace `deploy-manual.sh` para migrate/seed), no con el rol de aplicación.

### 6.1 Clasificación (idéntica a la medida en dev)
- **Puros** = `external_source = 'lafayette_lighting'` y `id` NO está en `legacy.productos.catalog_product_id`. Dev: 17.815.
- **ICG** = `external_source = 'lafayette_lighting'` y enlazados a `legacy.productos`. Dev: 8.001.
- Comprobación dinámica antes de borrar: para cada FK que apunta a `catalog_products` (20 en dev, consultadas de `pg_constraint`; incluye `ON DELETE RESTRICT`/`NO ACTION`
  en `adjustment_requests`, `general_count_request_lines`, `inventory_movements`, `relocation_requests`, `bodega_zona_libre_request_lines` y
  `replacement_requests.original_catalog_product_id`), el comando cuenta referencias de los puros. **Si alguna es > 0 aborta y lista los productos** (en dev es 0, salvo el
  caso de `replacement_requests` que se revisa en el pre-chequeo).

### 6.2 Orden por entorno (primero dev)
1. **Diagnóstico de solo lectura** del entorno (mismos conteos que en dev; para test/prod es obligatorio y previo a todo lo demás).
2. **Respaldo**: `pg_dump -Fc` de los schemas afectados con el rol de migración y copia fuera del servidor (S3 del entorno). Referencia (no ejecutar):
   `docker compose -f infra/docker-compose.qa.yml exec -T postgres pg_dump -U "$DB_MIGRATE_USERNAME" -d "$DB_DATABASE" -Fc -n atlantic_ventas_diseno -n atlantic_bodega -n atlantic_legacy -n atlantic_compras -f /tmp/scrum990-pre-<env>.dump`
   (ajustar nombre de servicio/credenciales al compose real). Más **tablas respaldo dentro de la BD** creadas por el comando dentro de la misma transacción:
   `catalog_products_bak_scrum990` (los 25.816 de origen Lafayette) y `product_warehouse_stock_bak_scrum990` (las 25.833 filas de Zona Libre) — rollback rápido sin restaurar el dump.
3. **Desplegar el código nuevo** (migraciones: tabla, `solo_consulta`, columnas de `lafayette_sync_runs`; pipeline reescrito; guards; endpoint y UI de Zona Libre).
   Desde este momento el sync escribe SOLO en la tabla nueva. El agendado del entorno queda activo.
4. **Poblar la tabla nueva**: correr `lafayette:sync-productos` de inmediato (~2-3 min, 25.816 filas). Verificar `count(lafayette_productos)` = conteo de la fuente
   (`SELECT COUNT(*)` ya validado por Luis: 25.802 el 2026-09-25, 25.816 el 2026-09-26). **Ventana:** entre el deploy y este paso la pestaña Zona Libre está vacía (minutos).
   Decisión: re-sincronizar desde Lafayette en vez de copiar desde `catalog_products`, porque la fuente es la verdad, el dato de esas filas ya estuvo sobrescrito
   y evita arrastrar ediciones.
5. **`--dry-run`** del comando de limpieza: reporta cuántos puros, cuántos ICG, filas de stock a borrar, referencias encontradas. Luis revisa el reporte.
6. **`--apply`** (confirmación explícita). En UNA transacción:
   - (a) crea las tablas respaldo;
   - (b) **restaura los ICG** (6.3);
   - (c) `DELETE FROM product_warehouse_stock WHERE warehouse_id = <Zona Libre>` (dev: 25.833 filas; incluye las 17 filas de productos sin `external_source`);
   - (d) `DELETE FROM catalog_products` de los puros (dev: 17.815; `ON DELETE CASCADE` no arrastra nada relevante, ya comprobado 0 referencias);
   - (e) verificaciones de 6.4; **si alguna falla, ROLLBACK completo**;
   - (f) un evento de auditoría resumen.
7. Correr el sync incremental de ICG (`legacy:sync-incremental-productos`) para que `name/brand/price_full/cost` de los 8.001 vuelvan a los valores vigentes de ICG.
8. Verificación funcional (Pre-QA): pantallas y totales.
9. **Release E** (cuando los 3 entornos estén limpios y aceptados): migración que elimina `catalog_products.external_source/external_synced_at` y `warehouses.mostrar_sin_stock`;
   tras la aceptación, eliminar las tablas `*_bak_scrum990`.

### 6.3 Restauración de los 8.001 productos ICG
**Fuente elegida: `audit_logs`** (evento `ventas_diseno.catalog_product.updated` con `new_values->>'external_source' = 'lafayette_lighting'`, el PRIMERO por
`entity_id`): su `old_values` contiene exactamente los valores que había **justo antes** de que Lafayette tocara el producto, para cada campo que ese evento cambió.
Esto recupera datos que ni el import ICG ni `legacy.productos` pueden reconstruir: la `factory_reference` real (ej. `73018A` del producto 736), la foto de ICG
(`public/legacy-photos/...`), `provider_id` original, etc. Verificado en dev: los 8.001 tienen eventos y el producto de muestra trae `old_values`.

Campos que `CatalogProductLoader::updateExisting` sobrescribe (todos se restauran si el primer evento los cambió): `factory_reference`, `provider_id`, `name`, `brand`,
`photo_url`, `category`, `price_full` (solo si la fuente traía valor) y marca `external_source`/`external_synced_at`. No toca `cost`, `stock_quantity`, `is_active`.

Forma (descriptiva): `WITH primer_enlace AS (SELECT DISTINCT ON (entity_id) entity_id::bigint id, old_values::jsonb ov FROM atlantic_auth.audit_logs WHERE action =
'ventas_diseno.catalog_product.updated' AND (new_values::jsonb)->>'external_source' = 'lafayette_lighting' ORDER BY entity_id, id)` y un `UPDATE catalog_products c SET campo =
CASE WHEN l.ov ? 'campo' THEN <valor de ov> ELSE c.campo END ... , external_source = NULL, external_synced_at = NULL FROM primer_enlace l WHERE c.id = l.id AND c.id IN (ICG)`.
Se distingue "clave ausente" de "valor null" con el operador `?`. Si `old_values` es `json` en vez de `jsonb`, se castea.

Verificaciones de la restauración: (i) `external_source IS NOT NULL` = 0 en `catalog_products`; (ii) `provider_id`, `factory_reference`, `photo_url`, `category` de una muestra de 200
productos coinciden con `old_values`; (iii) `name` de los ICG vuelve a coincidir con `legacy.productos.descripcion` tras el paso 7 (esperado: mayoría, hoy 1.565/8.001);
(iv) el producto 736 recupera `factory_reference = '73018A'` y su foto `legacy-photos`; (v) 0 productos ICG con `provider_id` de "Lafayette Lighting".
Rollback: `UPDATE ... FROM catalog_products_bak_scrum990` (solo estas filas) o restaurar el dump.

### 6.4 Verificación de conteos antes/después (valores de dev)
| Medida | Antes | Después esperado |
|---|---|---|
| `catalog_products` | 29.636 | **11.821** (8.001 ICG + 3.820 sin origen externo) |
| `catalog_products` con `external_source` | 25.816 | **0** |
| `product_warehouse_stock` total | 29.195 | **3.362** |
| Stock Zona Libre | 25.833 filas / 1.767.865 u | **0** |
| Bodega Central | 3.357 / 85.402 | **igual** |
| Showroom Obarrio / Reserva Servicios | 3 / 109 ; 2 / 18 | **igual** |
| `lafayette_productos` | 0 | **25.816** |
| Totales del Compras Inventario (Total, Disponible, por bodega) | registrar antes | **solo cambian por la resta de Zona Libre**; el resto de bodegas idéntico |
| Documentos de prueba (quote_items, order_items, movimientos, conteos, ajustes) | registrar | igual (apuntan a productos ICG) |

### 6.5 Referencias de prueba
- 4 productos de origen Lafayette usados por operaciones/cotizaciones: son ICG, **no se tocan** (se restauran).
- `customer_returns` 1, `general_count_requests` 1, `inventory_movements` 8, `purchase_order_receipt_lines` 6 referencian la **bodega** Zona Libre (id 7): la fila de la
  bodega se conserva, no hay ruptura de FK. Quedan como historial de prueba (el Kardex de esa bodega mostrará los 8 movimientos). Decisión opcional de Luis: anularlos o borrarlos.
- `replacement_requests`: 1 referencia a producto de origen Lafayette; si apunta a un puro como `original_catalog_product_id` (`RESTRICT`), el comando aborta y se decide (dato de prueba).

### 6.6 Test y prod
Solo tras el diagnóstico de solo lectura (paso 1) y la confirmación de Luis. **Riesgo a verificar primero:** prod agenda el sync 2 veces al día; si tiene credenciales
Lafayette, puede estar más contaminada que dev y con documentos reales sobre productos enlazados. Si el diagnóstico encuentra referencias reales sobre los puros, el comando
aborta y se rediseña ese caso antes de tocar nada. Mitigación inmediata opcional (decisión de Luis): pausar el agendado de producción hasta desplegar el pipeline nuevo.

## 7. Pruebas

Lección del proyecto (SCRUM-984, SCRUM-970): los fakes ocultan errores del contrato con el servicio real; por eso cada capa tiene una prueba real además de las simuladas.

1. **Pipeline (PHPUnit, BD real de pruebas)**: Extractor falso que devuelve filas; Loader inserta, actualiza solo lo que cambió (2a corrida sin cambios escribe 0 filas y no altera
   `updated_at`), marca `fuera_de_fuente_at` al desaparecer y lo limpia al reaparecer, respeta la guarda (`extraidos = 0` o < 50 % no marca nada), y **afirma que `catalog_products`,
   `product_warehouse_stock` y `warehouses` no cambian nunca** con miles de filas cargadas.
2. **Contrato con la fuente real**: fixture con ~20 filas reales capturadas de `vista_productos_lighting` en dev (sin secretos) para `mapRow`/Mapper, más una **prueba real contra dev**:
   `lafayette:sync-productos --dry-run` y una corrida real, comparando `count(lafayette_productos)` con `SELECT COUNT(*)` de la fuente. Esta prueba real es criterio de Pre-QA.
3. **Regresión por totalizador con la tabla Lafayette cargada** (p. ej. 2.000 filas sintéticas con `disponible` alto): Compras Inventario (KPIs Total/Disponible/por bodega y listado),
   Bodega Ver Inventario, Vista Bodegas (pestañas operativas), `WarehouseStockService` (sin fila Zona Libre), `BodegaReportsService`, `/compras/warehouses` y Gerencia: los números de Atlantic **no cambian**.
4. **Guards**: escribir stock en una bodega `solo_consulta` lanza excepción (modelo) y la validación rechaza Zona Libre en recepción de compra, conteo general, ajuste, devolución, alta de producto con stock inicial y reubicación.
5. **Endpoints de Zona Libre**: búsqueda con/sin acentos y con typo (fallback), paginación y `meta`, productos con `disponible = 0`, `fuera_de_fuente` oculto, permiso `bodega.read`, 403/401, y **test de rutas: no existe POST/PUT/PATCH/DELETE bajo `/bodega/zona-libre`**.
6. **Comando de limpieza**: fixtures con audit_logs reales de ejemplo (`old_values`) para puros, ICG con campos pisados, referencias bloqueantes (aborta), `--dry-run` no escribe, verificación fallida hace rollback, idempotencia (segunda corrida no hace nada).
7. **Rendimiento con el catálogo completo (25.8K) en dev**: listado sin búsqueda, con búsqueda parcial, con fallback, sin resultados y última página; p95 < 500 ms y pico < 64 MB con `memory_limit` 256M, medido como en SCRUM-995/996 (regla del proyecto); PHPStan nivel 8; `infra/test.sh` completo antes de Senior Review y de cada push.
8. **Frontend**: vitest del componente (tabla, aviso "solo consulta", búsqueda, vacío/error, sin acciones) y **Playwright permanente** `e2e/bodega-zona-libre.spec.ts` (autosembrado, sin IDs fijos, baseURL local): pestaña Zona Libre con aviso y sin botones; las demás pestañas intactas; Compras/Bodega Inventario no muestran productos Lafayette.
9. **Criterios de Pre-QA**: los de la spec más (a) conteos antes/después de 6.4 en dev, (b) ningún total de Atlantic incluye Lafayette, (c) bloqueo real en UI y API de escribir/mover en Zona Libre, (d) los 7 productos de cotización/operación siguen intactos, (e) el sync programado no modifica `catalog_products`, (f) restauración del producto 736.

## 8. Desglose sugerido por rol y orden

| ID | Rol | Tarea | Depende de |
|---|---|---|---|
| B1 | Backend | Migraciones de tenant: `lafayette_productos` (+ `search_text`, índices), `warehouses.solo_consulta` (backfill), columnas nuevas de `lafayette_sync_runs` | — |
| B2 | Backend | Modelo + Mapper + Loader + Command reescritos; eliminar el loader viejo; tests de pipeline y de contrato | B1 |
| B3 | Backend | Guards: `ProductWarehouseStock::saving`, `Warehouse::operativas()` aplicado en `WarehouseStockService`, `BodegaReportsService`, `InventoryController`; validaciones en recepción, conteo, ajuste, devolución, alta de producto y reubicación; trigger opcional | B1 |
| B4 | Backend | `GET /bodega/zona-libre/productos`, `show()`/`index()` ajustados; tests de endpoint, rutas y rendimiento | B1, B2 |
| B5 | Backend | Comando `lafayette:aislar-catalogo` con dry-run/apply/rollback y sus tests | B1, B2 |
| F1 | Frontend (tras spec UX/UI breve) | Tipos, API, hook, `ZonaLibreCatalogoTable`, rama en `BodegasPage`, i18n es/en, vitest | B4 (contrato) |
| F2 | Frontend | Playwright permanente de Zona Libre | F1 |
| D1 | DevOps/datos | Runbook de respaldo, orden de despliegue y verificaciones por entorno; gestionar el agendado | B2 |
| D2 | DevOps/datos | Diagnóstico de solo lectura en test y prod (con aprobación por comando) | — |
| D3 | DevOps/datos | Ejecución por entorno: dev, luego test y prod con confirmación de Luis | B1-B5, F1, D1, D2 |
| T1 | Tech Writer | ADR del aislamiento, actualizar `CLAUDE.md` (sección Lafayette) y manual de Bodega | en paralelo |
| E  | Backend | Release E: eliminar `external_*`, `mostrar_sin_stock` y tablas respaldo; ticket de retiro del comando | D3 en los 3 entornos |

Gates: Senior Review + Cybersecurity (superficie nueva: un endpoint de lectura y un comando destructivo de una sola vez con credenciales de migración), Visual Review + Pre-QA
fusionados (no hay mockup: la spec visual de UX/UI es la referencia), luego QA. Nunca `npm run test`; siempre `infra/test.sh` y `npx vitest run`.

## 9. Riesgos abiertos
1. **Producción puede estar contaminada y se re-contamina 2 veces al día** hasta que se despliegue el pipeline nuevo. No verificado (no se consultó prod).
2. **Ventana sin datos en Zona Libre** entre el deploy y la primera sincronización (minutos): se acota corriendo el sync en el mismo runbook.
3. **Entre el deploy y la limpieza** los datos viejos siguen en `catalog_products`/`product_warehouse_stock`; los totales siguen inflados hasta aplicar el comando. Conviene limpiar inmediatamente después de verificar el sync.
4. **Sustitutos y órdenes de compra del proveedor Lafayette** se quedan sin productos candidatos (consecuencia directa de la decisión de aislar).
5. **`audit_logs` conserva 172.477 eventos de ruido** (inmutable); no se puede podar.
6. **Restauración**: depende de que el primer evento de enlace tenga `old_values` completo; la cobertura se midió (8.001/8.001 con eventos), pero el contenido campo a campo se verifica con la muestra y el reporte de `--dry-run`.
7. **El sync de ICG y el de Lafayette dejan de pelearse** por las mismas filas solo después de la limpieza.
8. **Historial de Kardex**: 8 movimientos de prueba contra la bodega Zona Libre quedan visibles.
9. **Rollback de código**: hasta el release E se puede volver atrás (las columnas `external_*` y `mostrar_sin_stock` siguen existiendo).

## 10. Decisiones que necesitan aprobación de Luis
1. **Sustitutos / OC con proveedor Lafayette**: confirmar que perder esos ~26K candidatos es aceptable (alta manual de los que se usen).
2. **Productos que desaparecen de la fuente**: marcar `fuera_de_fuente_at` y ocultarlos (recomendado) en vez de dejarlos tal cual.
3. **Reemplazar `mostrar_sin_stock` por `solo_consulta`** y el trigger de defensa en profundidad (recomendado).
4. **Comando de limpieza de una sola vez** con restauración desde `audit_logs` y borrado de 17.815 + 25.833 filas en dev, con respaldo previo; y confirmación posterior por cada entorno.
5. **Historial de prueba contra la bodega Zona Libre** (movimientos, recepciones, devolución, conteo): dejarlo o anularlo.
6. **Pausar el agendado de producción** como medida inmediata mientras se despliega (si el diagnóstico confirma contaminación).
7. **Spec visual breve de UX/UI** para la tabla de Zona Libre antes de implementar el frontend.

## 11. Lo que NO se pudo verificar
- Estado de **test y prod** (no se consultaron; el diseño parte de dev).
- Que los servicios de Gerencia no sumen stock por una vía no listada (se cubre con prueba de regresión).
- Lectura línea a línea de los consumidores marcados "I" en la sección 4.
- Que el `old_values` del primer evento de enlace cubra todos los campos pisados en los 8.001 (se midió cobertura de eventos, no el contenido completo).
- Que los objetos `public/legacy-photos/...` de S3 sigan existiendo para restaurar las fotos de ICG.
- Rendimiento del nuevo listado (las cifras son objetivos de diseño, no mediciones).
- Nombres reales del servicio Postgres y credenciales en cada compose para el runbook de respaldo.
