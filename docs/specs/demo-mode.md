# Spec — Modo Demo (filtro de Catálogo/Inventario/Selectores de producto)

**Módulo:** VentasDiseno (Catálogo) + Bodega (Inventario) + Servicios/Cotización (selector compartido) + Settings
**Estado:** Spec — pendiente de aprobación de Luis
**Analista:** sesión 2026-09-04

---

## Contexto

Muchos productos de `catalog_products` vienen de la migración ICG (`legacy.productos`,
`Illumination/migracion-icg-diseno.md`) o fueron cargados sin ficha completa — sin foto, sin
`name`, o con `price_full = 0`. Al hacer una demo en vivo del CRM a un prospecto, esos productos
incompletos ensucian la pantalla. Se pide un "Modo Demo" que, activado, muestre en Catálogo,
Inventario y los selectores de producto (Cotización, Servicios) únicamente los productos con foto,
nombre y precio válidos.

---

## Decisiones de diseño (confirmadas con Luis, 2026-09-04)

| Decisión | Valor |
|---|---|
| Alcance del toggle | **Personal/de sesión** — cada usuario prende/apaga su propio Modo Demo; no afecta lo que ve el resto del equipo |
| Gate previo | **Doble acción** — el toggle personal solo tiene efecto si un superadmin habilitó Modo Demo a nivel global; si el gate global está apagado, el toggle personal se ignora (no hace falta resetear la preferencia de cada usuario) |
| Módulos cubiertos | Catálogo (VentasDiseno), Inventario (Bodega), selector compartido de producto (Cotización + Servicios) — ver "Puntos de integración" abajo |
| Definición de "valor" | `price_full > 0` (precio único del Catálogo — **no** hay tarifas múltiples a nivel de app; `producto_precios` con 4 tarifas es solo staging de migración ICG, `catalog_products.price_full` ya es un valor único resuelto) |
| Definición de "tiene imagen" | Mismo criterio que `CatalogService::photoUrl()` (`photo_key` si existe, si no `photo_url`) — **no** reimplementar la lógica de foto en el filtro, reusar la misma fuente de verdad que ya usa el resto del Catálogo |
| Definición de "tiene nombre" | `name` no NULL y no cadena vacía tras `trim()` |

---

## Puntos de integración — confirmados en el código, no solo por nombre de pantalla

`catalog_products` es una tabla compartida por 3 endpoints reales, no 4 como se estimó
inicialmente — `ProductLookupController` ya es el buscador **compartido** entre Cotización y
Servicios (ver su propio docblock: *"buscador de productos del Catálogo... reusado también por el
ítem Producto de Cotización"*):

| # | Endpoint | Pantalla(s) que cubre |
|---|---|---|
| 1 | `CatalogProductController::index()` (`GET /catalog-products`) | Catálogo |
| 2 | `BodegaInventoryController` (`CatalogProduct::query()` en su listado) | Inventario |
| 3 | `ProductLookupController::index()` (`GET /servicios/.../lookup/products`) | Selector "Producto" de Cotización (Batch 11, REQ-230) **y** "Productos reclamados/afectados" de Servicios (REQ-247) |

Esto reduce el trabajo real de "4 endpoints a tocar" a 3, y **ninguno requiere tocar
`QuoteItemController`** (ese solo hace `find($id)` sobre un producto ya elegido, no lista/busca).

---

## Modelo de datos — sin migración de schema nueva

Reusa 2 mecanismos ya existentes, mismo patrón que `share_links_expiration_days` /
`uat_hidden_modules`:

**Gate global** — nueva key en `system_settings` (tabla ya existente, `SystemSetting` model):
```
key:   demo_mode_enabled
value: false (default)
```
Se agrega a `PreferencesService::getSystemSettings()` / `updateSystemSettings()`. Expuesto vía los
endpoints ya existentes `GET/PATCH /api/settings/system`.

**Gate personal** — nueva key en `users.preferences` (columna JSONB ya existente):
```
preferences.demo_mode_enabled: false (default)
```
Se agrega a `PreferencesService::getUserPreferences()` / `updateUserPreferences()` /
`mergeWithDefaults()`. Expuesto vía los endpoints ya existentes `GET/PATCH /api/settings/preferences`
(sin permiso especial — cualquier usuario autenticado gestiona su propia preferencia, igual que
el resto de `preferences`).

**Sin tabla ni columna nueva. Sin migración.**

---

## Permiso para el gate global — punto a confirmar

`PATCH /api/settings/system` hoy exige `permission:settings.global` (no `superadmin.all`) —
mismo permiso que ya protege el resto de la pantalla de Configuración donde vivirá este switch.
Recomiendo mantener `settings.global` por consistencia con el resto de esa pantalla, en vez de
exigir `superadmin.all` — a confirmar en Senior Review si el criterio de Luis es más estricto
("solo superadmin, nadie más con `settings.global`").

---

## Scope de filtrado — un solo lugar, reusado en los 3 endpoints

Nuevo método en `CatalogProduct` (o `CatalogService`, a decidir por Backend Dev según convención
del módulo — ya existen métodos de negocio en ambos lados):

```php
public function scopeVisibleForDemoMode(Builder $query, bool $demoModeActive): Builder
{
    if (!$demoModeActive) {
        return $query;
    }

    return $query
        ->where('price_full', '>', 0)
        ->whereRaw("trim(coalesce(name, '')) != ''")
        ->where(function ($q) {
            $q->whereNotNull('photo_key')
              ->orWhere(function ($q2) {
                  $q2->whereRaw("trim(coalesce(photo_url, '')) != ''");
              });
        });
}
```

`$demoModeActive` se calcula una vez por request:
`$globalGate = system_settings.demo_mode_enabled; $personalGate = $user->preferences['demo_mode_enabled'] ?? false; $demoModeActive = $globalGate && $personalGate;`

Se aplica en los 3 controladores identificados arriba, sobre la query ya existente (todos ya
filtran `is_active = true` — el scope de demo mode se encadena ahí mismo).

---

## Auditoría

`AuditService::log()` (patrón ya usado para eventos no-CRUD, ej. `crm.share_link.created`) al
cambiar el **gate global** únicamente (activar/desactivar el toggle personal de cada usuario no se
audita — es una preferencia de UI, no una acción sobre datos de terceros):
```
action: 'settings.demo_mode.enabled' | 'settings.demo_mode.disabled'
```

---

## Frontend

- **Gate global:** switch "Modo Demo" en `SettingsPage.tsx`, sección admin (mismo bloque de
  `share_links_expiration_days`/`documents.max_size_mb`), detrás de `permission:settings.global`.
- **Gate personal:** toggle en `TopBar.tsx`, visible para cualquier usuario autenticado —
  **oculto por completo** (no deshabilitado) cuando el gate global está apagado, para no tener que
  explicarle a cada usuario por qué un switch no responde.
- Badge/indicador sobrio mientras el modo está activo (sin emoji, paleta teal/lima — regla de
  marca del cliente) en Catálogo/Inventario, para que nadie confunda "filtrado por demo" con
  "no hay productos".
- Invalidar cache de TanStack Query de los 3 listados (Catálogo, Inventario, selector de producto)
  al cambiar cualquiera de los 2 gates.
- i18n ES/EN.

---

## Fuera de alcance

- Filtrar por criterios adicionales (ej. categoría, marca) — solo nombre/imagen/precio en esta fase
- Expiración automática del gate global (queda encendido hasta que un superadmin lo apague)
- Aplicar el filtro a reportes/exports (PDF, Excel) — solo a las 3 pantallas/endpoints listados

---

## Criterios de aceptación

```
[ ] Gate global apagado (default) -> ningún usuario ve el catálogo filtrado, aunque tenga su
    preferencia personal en true
[ ] Superadmin (permission:settings.global) activa el gate global -> se registra en audit_logs
    (settings.demo_mode.enabled)
[ ] Con gate global activo, usuario prende su toggle personal -> Catálogo/Inventario/selector de
    producto (Cotización y Servicios) muestran solo productos con name no vacío, foto
    (photo_key o photo_url) y price_full > 0
[ ] Con gate global activo pero toggle personal apagado -> el usuario ve el catálogo completo
[ ] Superadmin apaga el gate global mientras un usuario tiene su toggle personal en true -> ese
    usuario vuelve a ver el catálogo completo sin tener que tocar su propio toggle
[ ] Toggle personal en TopBar no aparece en absoluto si el gate global está apagado
[ ] Producto con photo_key pero photo_url vacío -> cuenta como "tiene imagen" (mismo criterio que
    CatalogService::photoUrl())
[ ] Producto con price_full = 0.00 -> excluido en modo demo
[ ] QuoteItemController (agregar producto ya elegido a una cotización) -> sin cambios, no aplica
    el filtro (solo lista/busca lo filtra, no el alta directa por id)
[ ] PHPStan Level 8 sin errores
```

---

## Tareas para el PM

### Backend
| Tarea | Estimado |
|---|---|
| `demo_mode_enabled` en `PreferencesService` (system_settings + user preferences) + validación | 1 h |
| `scopeVisibleForDemoMode` en `CatalogProduct` | 45 min |
| Aplicar el scope en `CatalogProductController::index`, `BodegaInventoryController`, `ProductLookupController::index` | 2 h |
| Auditoría del gate global (`AuditService::log`) | 30 min |
| Tests: gate global on/off, gate personal on/off, combinaciones, criterio foto/nombre/precio en cada uno de los 3 endpoints | 3 h |

### Frontend
| Tarea | Estimado |
|---|---|
| Switch "Modo Demo" en `SettingsPage.tsx` | 1 h |
| Toggle personal + badge en `TopBar.tsx` (oculto si gate global off) | 2 h |
| Invalidación de cache TanStack Query (Catálogo/Inventario/selector) | 1 h |
| i18n ES/EN | 30 min |

**Total estimado: ~2.5-3 días** (backend ~1 día, frontend ~1 día, gates de Senior Review +
Visual Review/Pre-QA fusionados dado el tamaño del batch).

---

## Referencias

- `app/Modules/VentasDiseno/Models/CatalogProduct.php` — modelo, único `price_full`/`photo_key`/`photo_url`/`name`
- `app/Modules/VentasDiseno/Services/CatalogService.php::photoUrl()` — fuente de verdad de "tiene imagen"
- `app/Modules/Auth/Services/UatVisibilityService.php` — mismo patrón de gate global vía `system_settings`
- `app/Modules/Servicios/Http/Controllers/ProductLookupController.php` — confirma que Cotización y Servicios comparten el mismo picker
- `docs/specs/share-links.md` — spec previa con el mismo patrón de setting global configurable
