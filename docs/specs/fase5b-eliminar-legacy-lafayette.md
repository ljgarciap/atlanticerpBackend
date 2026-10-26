# Spec — Fase 5b: eliminar Legacy (ICG) e Integrations/Lafayette del repo

**Estado**: BORRADOR — decisión de destino ya tomada por Luis (2026-10-26: «eliminar del repo»); faltan las decisiones de §4 y la confirmación explícita del `DROP` (§3).
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
