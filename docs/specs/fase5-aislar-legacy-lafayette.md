# Spec — Fase 5: aislar Legacy (ICG) e Integrations/Lafayette del núcleo

**Estado**: BORRADOR — pendiente de las decisiones de Luis (§5) y de su «adelante». No se implementa nada antes.
**Origen**: decisión 5 del análisis de aislamiento (`erp/docs/analisis/aislamiento-nucleo-2026-10-24.md`) y sugerencia del Senior Review de la sync:
`LAFAYETTE_DB_HOST` apunta por defecto a `app.grupolafayette.com`.

## 1. Hechos verificados (2026-10-26)
**Backend**
- `app/Modules/Legacy` (extracción desde el SQL Server de ICG, 98 tests) y `app/Modules/Integrations/Lafayette` (catálogo de solo consulta desde MySQL de un proveedor).
- **Fuera de esos módulos, Legacy se referencia desde**: `AppServiceProvider` (bindings de ~12 contratos de extractores), `routes/legacy.php`, `routes/ventas-diseno.php`
  (`/{subClientId}/legacy-history`), `config/database.php` (conexión `legacy_icg`), `routes/console.php` (schedules), `database/seeders/AlmacenEquivalenciaSeeder.php`
  y **~50 comandos** `app/Console/Commands/Legacy*Command.php`.
- **Lafayette se referencia desde**: `AppServiceProvider`, `config/lafayette.php`, 4 comandos `Lafayette*`, `routes/console.php` (4 schedules por ambiente),
  `routes/bodega.php` (catálogo Zona Libre), `LafayetteLightingProviderSeeder`, y código de núcleo: `Bodega/ZonaLibreCatalogoService`, `Bodega/Warehouse`,
  `Bodega/ProductWarehouseStock`, `VentasDiseno/CatalogProduct`, `Compras/{InventoryController,CatalogProductSearchController,SubstituteCandidatePreselector,InventoryKpiService}`,
  `Auth/{SettingsController,PreferencesService}`, `AdminCont/AdminContLocationController`, `Shared/Services/AuditService`.
- **63 migraciones de tenant** crean/alteran tablas `legacy_*` / `lafayette_*`. Son historia inmutable (440 en total): no se editan ni se borran en esta fase.
- `.env.example` documenta `LEGACY_ICG_*` (base `ILLUMINATION`) y `LAFAYETTE_DB_*` (host real por defecto). `storage/certs/lafayette-ca.pem` (CA pública) viaja con el repo.

**Frontend**: ~20 archivos no-test (`pages/LegacyPagosPage`, `components/legacy/*`, `StockSyncPanel`, `SubClientIcgHistoryModal`, `ZonaLibreCatalogoTable`, `hooks/useLegacyMigration`,
`api/legacyMigrationApi`, entradas en `App.tsx`, `Sidebar.tsx`, `SettingsPage`, `homeRoute`, i18n de `settings`/`bodega`).

## 2. Diseño propuesto (en dos entregas, en este orden)
**5a — Apagable por configuración (aditivo, reversible; la red de seguridad de la 5b)**
1. Dos banderas de configuración, **apagadas por defecto**: `modules.legacy_icg.enabled` y `modules.lafayette.enabled` (`config/modules.php`, env `MODULE_LEGACY_ICG` / `MODULE_LAFAYETTE`).
2. Cuando están apagadas: no se registran los bindings de extractores, ni `routes/legacy.php`, ni las rutas/`legacy-history`/catálogo Zona Libre-Lafayette, ni los comandos `legacy:*` /
   `lafayette:*`, ni sus schedules. El núcleo deja de depender de ellas en tiempo de ejecución.
3. Defaults neutros en `.env.example` y `config/`: sin `app.grupolafayette.com`, sin base `ILLUMINATION`, sin referencias a la VPN/gateway del cliente (solo comentarios de ejemplo).
4. Los puntos del núcleo que hoy tocan `lafayette_*` (Bodega, Compras, VentasDiseno, Warehouse, `AuditService`) se revisan uno a uno: o ya toleran la tabla vacía, o se les pone un guard por bandera.
5. Frontend: sidebar, rutas y pestañas de Legacy/Lafayette se muestran solo si el backend las declara activas (reusar `/auth/me` o un endpoint de capacidades; se define en la implementación).
6. Tests de contrato: (a) con ambas banderas apagadas la suite del núcleo pasa sin las tablas `legacy_*`/`lafayette_*` pobladas; (b) las rutas/comandos no existen apagadas; (c) encendidas, la suite
   de Legacy y la de Lafayette siguen verdes sin cambios.

**5b — Salida física (SOLO si Luis elige «eliminar» en §5-1)**: mover/borrar `app/Modules/Legacy`, `Integrations/Lafayette`, los comandos, tests, config y frontend, y agregar una migración
de tenant que haga `DROP` de las tablas `legacy_*`/`lafayette_*` (**destructiva: confirmación explícita aparte, con conteo previo de filas**). Si Luis elige «paquete opcional», 5b se reemplaza por
extraer a un paquete Composer (`packages/`) con su provider, y las migraciones viejas quedan en el núcleo como historia.

## 3. Fuera de alcance
- Fiscal de Panamá (Fase 6), catálogos de vocabulario (Fase 7), dataset demo (4b/4c).
- Editar o borrar migraciones históricas.
- Sincronizar con binax: esta fase aumenta la distancia con binax (cada archivo movido encarece la próxima sync). Se asume la política ya decidida (solo seguridad y correcciones).
- Zona Libre en sí: el flujo de Zona Libre del núcleo se queda; solo se aísla la **fuente** Lafayette. Ver §5-3.

## 4. Criterios de aceptación
1. Con las banderas apagadas (default), un tenant nuevo corre, migra, siembra y la suite del núcleo pasa sin tocar ICG ni Lafayette; no queda ningún host/credencial de esos sistemas como valor por defecto.
2. Apagado, `php artisan route:list` y `list` no muestran rutas ni comandos `legacy`/`lafayette`; el scheduler no los programa.
3. Encendido, Legacy y Lafayette se comportan exactamente como hoy (suites propias verdes, sin modificaciones).
4. El sidebar y las rutas del frontend de esos módulos no aparecen cuando están apagados; `tsc` y vitest verdes.
5. PHPStan nivel 8 limpio; sin migración destructiva en la 5a.

## 5. Decisiones que necesita Luis
1. **Destino final**: (a) **eliminar** del repo (5b borra código + tablas), (b) **paquete opcional** dentro de `packages/` (se conserva, enchufable), o (c) **solo apagado** (5a y se queda el código dormido).
   *Recomendación del Analista*: 5a ahora y decidir (a)/(b) después con la 5a ya verificada; es reversible y no pierde nada.
2. **Datos existentes**: ¿hay en algún entorno datos reales en `legacy_*` / `lafayette_*` que deban preservarse? (condiciona cualquier `DROP`; en el dev local son datos demo.)
3. **Zona Libre**: el catálogo Lafayette es hoy la fuente del flujo de Zona Libre. ¿Zona Libre queda en el núcleo sin catálogo externo (proveedor configurable por pantalla; ver hallazgo `zona_libre_provider_id`),
   o se trata como variante por país junto con el fiscal (Fase 6)?
4. **Entrega**: ¿2 PR por repo (5a backend + 5a frontend, luego 5b) como las fases anteriores?
