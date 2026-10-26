# Spec — Fase 5b: eliminar Legacy (ICG) e Integrations/Lafayette del repo

**Estado**: 5b-1 MEZCLADA (§7). 5b-2 IMPLEMENTADA (§8), pendiente de merge.
**Origen**: Fase 5a (mezclada) dejó ambos módulos apagados por defecto. Esta fase borra el código dormido.

## 1. Hechos verificados (2026-10-26)
- Código: `app/Modules/Legacy` + `app/Modules/Integrations/Lafayette` = **240 archivos PHP**; **136 archivos de test** bajo rutas `*egacy*`/`*afayette*`; 53 comandos; `config/legacy.php`, `config/lafayette.php`, conexiones `legacy_icg` y
  `lafayette_lighting` en `config/database.php`, `storage/certs/lafayette-ca.pem`, `routes/legacy.php`.
- Referencias desde el núcleo (tras la 5a, todas condicionadas por bandera): `AppServiceProvider` (registro de los providers), `PreferencesService`/`SettingsController` (`lafayette_guarda_min_ratio`),
  `Bodega/ZonaLibreCatalogoService` + `ZonaLibreCatalogoController` + ruta `zona-libre/productos`, ruta `legacy-history` de VentasDiseno, `AuditService` (3 constantes), seeders sin llamador
  (`LafayetteLightingProviderSeeder`, `AlmacenEquivalenciaSeeder`).
- **71 migraciones de tenant** mencionan legacy/lafayette; ~60 crean/alteran tablas `legacy_*`/`lafayette_*`. **11 tocan tablas del núcleo**: `catalog_products` (`external_source`, `external_synced_at`, `search_text`,
  foto), `bodega_orders` (campos «gemelo ICG»), `warehouses` (`solo_consulta`, `mostrar_sin_stock`, trigger que rechaza stock en bodegas de solo consulta), `servicios_tickets`, `catalog_pdf_exports`.
- Frontend: ~20 archivos no-test (`pages/Legacy*`, `components/legacy/*`, `StockSyncPanel`, `SubClientIcgHistoryModal`, `ZonaLibreCatalogoTable`, `hooks/useLegacyMigration`, `api/legacyMigrationApi`, rutas, menú, i18n) y 6 specs e2e `preqa-*`.
- Sin datos reales en `legacy_*`/`lafayette_*` (solo demo local), decisión de Luis en la 5a.

## 2. Diseño propuesto (dos entregas)
**5b-1 — Código (reversible con `git revert`)**: borrar los dos módulos, sus 53 comandos, 136 tests, configs, conexiones, certificado, rutas, `ZonaLibreCatalogoService`/controller/ruta, `lafayette_guarda_min_ratio`, constantes de
auditoría y seeders huérfanos; quitar los providers de `AppServiceProvider`, `phpstan-bootstrap.php` y las banderas `flags.legacy_icg/lafayette` + `config/modules.php`; frontend: páginas, componentes, hooks, api, rutas, menú, i18n y los 6 e2e.
Las tablas **no se tocan** en esta entrega (quedan huérfanas pero inofensivas); las migraciones históricas siguen en su sitio.

**5b-2 — Esquema (DESTRUCTIVA, requiere confirmación explícita aparte)**: una migración de tenant NUEVA que haga `DROP TABLE IF EXISTS` de las tablas `legacy_*`/`lafayette_*` (con conteo de filas previo en el log y aborto si hay filas en un entorno
distinto de local/dev/testing, salvo `--force` explícito). `down()` recrea solo la estructura, nunca los datos. Las migraciones antiguas NO se borran ni se editan: un tenant nuevo las ejecuta y la nueva migración las limpia después.

## 3. Lo destructivo y su confirmación
Solo la 5b-2 borra datos (`DROP TABLE`). Antes de escribirla se necesita el «sí» explícito de Luis a esa migración concreta; la 5b-1 puede mezclarse sin tocar la base.

## 4. Decisiones abiertas para Luis
1. **Columnas del núcleo** (`catalog_products.external_*`, `bodega_orders` gemelo ICG, `warehouses.solo_consulta`/trigger): ¿se **conservan** (inofensivas, evitan una migración riesgosa sobre tablas vivas) o se **retiran** en la 5b-2?
   *Recomendación del Analista*: conservar `solo_consulta` y su trigger (protege cualquier bodega de consulta futura) y retirar solo lo exclusivo de ICG/Lafayette en una fase posterior, no en esta.
2. **Zona Libre «solo consulta»**: sin el catálogo externo, la bodega de Zona Libre queda como bodega de solo consulta vacía. ¿Se **conserva** el concepto o se convierte en una bodega operativa normal?
3. **Esquema para tenants nuevos**: ¿se acepta que un tenant nuevo cree y luego borre ~30 tablas (migraciones antiguas + 5b-2), o se prefiere «aplanar» las migraciones de Legacy/Lafayette? *Recomendación*: aceptarlo; aplanar reescribe historia inmutable y es otra fase.
4. **Entrega**: ¿2 PR por repo (5b-1 backend+frontend; luego 5b-2 backend)?

## 5. Criterios de aceptación (5b-1)
1. No queda ninguna referencia a `Modules\Legacy`, `Integrations\Lafayette`, `legacy_icg` ni `lafayette_lighting` en `app/`, `routes/`, `config/`, `bootstrap/` ni `src/` (grep).
2. Suite backend y frontend verdes, PHPStan nivel 8 limpio; `artisan list` sin `legacy:`/`lafayette:`.
3. Un tenant nuevo (`tenant:create`) migra y siembra sin errores.
4. La pantalla de Bodegas con la bodega de Zona Libre sigue abriendo (según §4-2).

## 6. Decisiones de Luis (2026-10-26)
1. **Columnas del núcleo: se RETIRAN en la 5b-2** (`catalog_products.external_*`/`search_text` de origen externo, campos «gemelo ICG» de `bodega_orders`, `warehouses.solo_consulta` y su trigger/función). La migración nueva las quita con
   verificación previa (aborta si alguna tiene datos en un entorno distinto de local/dev/testing).
2. **Bodega «Zona Libre» de consulta externa: se ELIMINA** (tiene nombre propio y nace de la consulta externa; afecta el diseño). Se borra su fila de `warehouses` en la 5b-2 (aborta si algo la referencia) y desaparece del
   frontend (`BodegasPage`, `warehouses.ts`, badge y aviso). El flujo de **solicitudes** de Zona Libre (Compras/Bodega, proveedor fijo) NO se toca: es del núcleo (decisión de la 5a).
3. **Esquema de tenants nuevos**: se acepta crear y luego borrar las tablas (sin aplanar migraciones).
4. **`DROP` autorizado** (5b-2) y entrega en **2 PR por repo**: 5b-1 (código) primero, luego 5b-2 (migración destructiva).

## 7. Resultado de la 5b-1 (2026-10-26)
- **Backend** (rama `fase5b1/eliminar-codigo`): borrados `app/Modules/Legacy` y `Integrations/Lafayette` (240 archivos), 136 tests, 53 comandos, `config/{legacy,lafayette,modules}.php`, conexiones `legacy_icg`/`lafayette_lighting`, `routes/legacy.php`,
  `legacy-history`, catálogo externo de Zona Libre (servicio, controlador, ruta, limitador), `lafayette_guarda_min_ratio`, constantes de auditoría de Legacy/Lafayette, banderas `flags.legacy_icg/lafayette`, certificado, 2 seeders huérfanos,
  `infra/legacy-sync*.sh`, y los drivers `sqlsrv`/`pdo_mysql` del Dockerfile (la imagen construye). Outlook (`Integrations/Outlook`) se conserva.
- **Se conserva a propósito hasta la 5b-2**: el concepto `solo_consulta` (columna, scopes, guard, excepción, trigger), la constante de auditoría `LEGACY_CARGAR_PEDIDOS_GEMELOS` y los campos «gemelo ICG» (los lee el reporte de gemelos de Admin&Cont),
  `origin = 'icg_legacy'`, y todas las migraciones históricas. `WarehouseController::show()` de una bodega de solo consulta devuelve KPIs en cero.
- **Frontend**: borradas las páginas/componentes/hooks/api/tipos de ICG (Migración, Vista ICG, Pagos ICG, Historial ICG, StockSyncPanel), el catálogo de Zona Libre, `lib/modules` y 7 specs e2e; claves i18n retiradas; la bodega de solo consulta muestra un aviso.
- **Verificación**: backend 5104 tests en la corrida completa con 3 fallos esperados (dependían del catálogo/TTL borrados), corregidos y re-corridos (684 de Bodega/Auth/VentasDiseno en verde); PHPStan 0 errores. Frontend 234 archivos / 2951 tests, `tsc` y build limpios.
- Informe del Senior Review: `docs/reviews/fase5b1-eliminar-codigo-2026-10-26.md` (0 bloqueantes).

## 8. Resultado de la 5b-2 (2026-10-26) — el alcance completo que Luis confirmó
- **Migración** `2026_10_26_200000_retire_legacy_and_lafayette_schema` (IRREVERSIBLE: `down()` lanza error y pide restaurar un respaldo): quita el trigger/función de solo consulta, la bodega «Zona Libre» de consulta externa,
  `warehouses.solo_consulta`/`mostrar_sin_stock`, los campos «gemelo ICG» de `orders` (`origin`, `icg_*`, `quote_id`, `sub_client_id`, índices y CHECK), `quotes.origin`, `catalog_products.external_*`, `lafayette_productos`, `lafayette_sync_runs`,
  las 32 tablas de `{tenant}_legacy` y el ajuste `lafayette_guarda_min_ratio`. **El schema `{tenant}_legacy` queda VACÍO**: `Tenant::schemas()`, `GrantAppRoleCommand` y las migraciones históricas aún lo usan; se retira cuando se aplanen las migraciones.
  `catalog_products.search_text` se CONSERVA (lo usa la búsqueda del catálogo de Compras).
- **Guardas**: en cualquier entorno aborta si algo referencia la bodega de consulta (stock, movimientos, ubicaciones, pedidos…); fuera de local/dev/testing aborta además si alguna tabla de Legacy/Lafayette tiene filas, si hay pedidos/cotizaciones gemelas
  o productos con `external_source`. Tu base local tenía 0 filas en todo eso.
- **Código**: fuera el concepto `solo_consulta` (scopes `operativas`/`enBodegaOperativa`, guard y excepción, ramas de `WarehouseController`/`WarehouseLocationController`/`WarehouseSaleRulesController`); `Warehouse::operativaRule()` pasó a `existsRule()`;
  fuera el reporte «Pedidos gemelos» de Admin&Cont (2 endpoints, servicios, resource), `IcgLegacyOriginScope`, `Quote::withIcgLegacy()`, `Order` sin campos gemelos, `OrderService::createIcgTwinForDispatch`, `ReceptorFiscalGuard::evaluarGemelo` y las ramas de gemelo de
  `InvoiceService`/`InvoiceBillingPlanner`. Un filtro SQL crudo `q.origin = 'comercial'` de `ArchitectCommissionService` lo detectó la suite.
- **Tests**: 7 archivos borrados (gemelos, solo consulta, filas residuales) y 6 adaptados; el test de «8 bodegas» pasó a 7.
- **Frontend**: `BodegasPage` sin vista de solo consulta, fuera `lib/warehouses`, `PhysicalWarehouse.solo_consulta`, `quote.origin` y las claves i18n; 234 archivos / 2944 tests, `tsc` y build limpios.
- Informe del Senior Review: `docs/reviews/fase5b2-drop-esquema-2026-10-26.md`.
