# SCRUM-990 — Senior Review, ronda 3 (delta)

Alcance: solo lo nuevo desde la ronda 2. Lectura estática del código y de los tests (no se ejecutó ninguna suite; la corrida completa en verde la aportó el equipo).
Backend: 27fd1d9f, 3f6261b1, 43e9b90d, f868a1d8, 8ff70eb1, e95cd917, 07dce061. Frontend: 604faa8, d316395, 1731185.

## Veredicto

- atlanticerp-backend: APROBADO (0 bloqueantes).
- atlanticerp-frontend: APROBADO (0 bloqueantes).
- Puede pasar a push a dev (gate de push de Luis sigue vigente). Los hallazgos de abajo son sugerencias no bloqueantes.

## 1. N1 — lock_timeout / statement_timeout (3f6261b1) — OK

- `LafayetteAislarCatalogoCommand.php:915-918`: ambos `SET LOCAL` son las dos primeras sentencias dentro de `DB::transaction`, antes de `bloquearTablas()` (:1033, un único `LOCK TABLE a, b, ... IN SHARE ROW EXCLUSIVE MODE`; no hay otro `LOCK`, advisory lock ni `lockForUpdate` en el comando). `SET LOCAL` solo vive dentro de la transacción y se revierte con ella; nada queda en la sesión.
- Expiración: la excepción 55P03/57014 sale de `DB::transaction` (rollback completo) y se traduce en `ejecutar()` con `sqlState()` recorriendo `getPrevious()` hasta el `PDOException`. Mensaje claro y `FAILURE`. El test con una segunda conexión PDO reteniendo `ROW EXCLUSIVE` y `--lock-timeout=1` comprueba tiempo < 10 s y `foto()` idéntica (nada aplicado).
- Defaults seguros: 5 s / 600 s, validados como enteros 1..86400 en `handle()` antes de tocar la BD; la interpolación es de `(int)*1000`, sin inyección posible.
- `--operador` obligatorio con `--apply` (:119), validado antes de abrir nada. El dry-run no lo exige ni bloquea. Test cubre el rechazo y que no cambia nada. Los tests existentes lo inyectan por defecto en `ArmaEscenarioLafayette::limpiar()`.
- 🟡 Sugerencia: dentro de un test con transacción externa, `SET LOCAL` persiste hasta el fin de esa transacción; en producción no aplica (sin transacción externa). Sin acción.

## 2. Umbral de la guarda (43e9b90d) — OK

- Migración 100400 (`database/migrations/tenant/`, `TenantSchemaMigration`, módulo `auth`): `UPDATE ... WHERE key=... AND updated_by IS NULL AND value='0.5'::jsonb`. Idempotente (segunda corrida no encuentra 0,5), no pisa 0,7 ni un 0,5 puesto desde la pantalla (`updated_by` informado: cubierto por test), reversible (`down` solo revierte `0.9` con `updated_by IS NULL`). Es carpeta tenant, por lo que `tenants:artisan migrate` la corre en todos los tenants existentes. En tenants nuevos, 100300 (editada para sembrar 0,9) deja la fila ya en 0,9 y 100400 es no-op. Editar 100300 en sitio es seguro: los dos caminos (ya aplicada con 0,5 / aún no aplicada) quedan en 0,9.
- `SettingsController.php`: `sometimes|numeric|min:0.5|max:1` (rechaza 0,49, 0, -1, 1,01, 'abc'). El 403 se evalúa con `superadmin.all` del JWT (mismo criterio que `CheckPermission`) y solo cuando la clave viene en el cuerpo; el resto de ajustes sigue funcionando con `settings.global` (test: el mismo designer con `settings.global` recibe 200 en `share_links_expiration_days` y 403 en la clave del umbral). Un PATCH mixto con la clave del umbral y sin superadmin devuelve 403 sin aplicar nada (correcto, falla antes del servicio).
- `PreferencesService.php:228-248`: `updateOrCreate` con `updated_by`, `round(,4)`, auditoría `settings.updated` con old/new solo si cambió; el old se lee antes de escribir. El GET expone el valor.
- Uso real: `LafayetteProductoLoader.php:52` lee `LafayetteSettings::guardaMinRatio()` (float, fila `system_settings` con fallback `config('lafayette.guarda_extraccion_min_ratio')` = 0,9, acotado a [0,1]). Hay test de comportamiento (el mismo 75 % activa o no la guarda según 0,5/0,9) y 07dce061 fija el 0,5 explícito en el escenario del producto desaparecido, sin depender del default.
- 🟡 Orden de evaluación: un usuario con `settings.global` sin superadmin que envíe un valor inválido recibe 422 antes que 403 (la validación corre primero). Irrelevante en seguridad (no escribe nada); solo cosmético.
- 🟡 `GET /settings/system` devuelve el umbral a cualquier `settings.global` (lectura). Aceptable: el dato no es sensible.

## 3. Precio por permiso (f868a1d8) — OK

- Único lugar donde se lee `precio_5` hacia una respuesta HTTP: `ZonaLibreCatalogoService::listar()/formatRow()` (grep en `app/`: el resto son sincronizador/modelo/extractor/comando de limpieza). No hay otro endpoint ni export de `lafayette_productos`.
- Sin permiso: el SELECT usa `NULL AS precio_5` (no consulta la columna), `formatRow` devuelve `precio: null`, `sort=precio` responde 422 antes de consultar (el orden filtraría el precio). El `search` (`CatalogProduct::applySearch`) y el `rank` no usan precio. `disponible` no revela precio. Sin caché de la respuesta paginada (el resumen cacheado no lleva precio).
- Permiso: `compras.read` o `superadmin.all` desde `jwt.permissions`, exactamente la misma fuente que `CheckPermission` (incluye el puente de vocabulario `compras.read` de `effectivePermissionsV2`). Superadmin lo tiene (test).
- Contrato: `meta.puede_ver_precio` es la ÚLTIMA clave de `meta` (`Pagination::meta() + [...]`, orden `categorias, total_catalogo, ultima_sincronizacion, puede_ver_precio`); el test compara `array_keys` exactos con y sin permiso, verifica que la clave `precio` sigue en la fila con `null`, que el payload serializado no contiene el precio real y que la consulta no menciona `precio_5`.
- 🟡 Sugerencia (preexistente, fuera de este delta): `InventoryController.php:213-217` (filtro por bodega de Compras) no usa `enBodegaOperativa()`; si alguien enviara el id de Zona Libre, devolvería productos con fila residual que la tabla no puede mostrar. No filtra precio ni datos de Lafayette; cubrirlo en un ticket de seguimiento.

## 4. A1 y docblock (27fd1d9f, 8ff70eb1) — OK

- `InventoryController::warehouseBreakdown` (:485-489) aplica `->enBodegaOperativa()` (scope `ProductWarehouseStock::scopeEnBodegaOperativa`, subconsulta a `Warehouse::operativas()`), consistente con `InventoryKpiService:427` y `BodegaInventoryController`. Test nuevo del endpoint `/api/compras/inventory/{id}/warehouse-stock` con residual de 100 unidades: solo devuelve `Bodega Central`.
- 🟡 `AdminContLocationController.php:13-15`: el docblock quedó con un paréntesis de cierre sobrante ("... al de Bodega) con las ubicaciones"). Solo estético; el contenido (6 bodegas operativas, Zona Libre fuera) es correcto.
- Runbook (`docs/runbooks/lafayette-aislar-catalogo.md`): consistente con el rango 0,5..1,0 y con la limitación real (recorte >50 % requiere corrida `success` de referencia).

## 5. Frontend — OK

- Contrato campo a campo contra el controlador: `ZONA_LIBRE_META_KEYS` con `puede_ver_precio` al final; espejo de 422 con `page` 1..10000 y `sort=precio` sin permiso; tipo `ZonaLibreCatalogoMeta.puede_ver_precio: boolean`.
- `ZonaLibreCatalogoTable.tsx:116-117`: `showPrice = data?.meta.puede_ver_precio === true` (se asume "no" hasta tener respuesta). Sin permiso desaparecen el encabezado ordenable, la celda, la tarjeta móvil (`grid-cols-1`), y el esqueleto y el vacío usan 7 columnas. El estado de orden inicial es `referencia` y el botón de precio solo existe con `showPrice`, así que nunca se envía `sort=precio` sin permiso; hay test que pulsa todas las demás columnas y verifica los parámetros enviados. Efecto menor: con permiso el esqueleto inicial es de 7 columnas y la columna Precio aparece al llegar la respuesta (salto de layout aceptado y documentado en el comentario).
- Plural: `resultsCount_one/_other` (en) y `_one/_many/_other` (es), con test sobre una instancia real de i18next (1 producto, 0 productos, 1000000 de productos). `many` en es es válido para `Intl.PluralRules`.
- Campo del umbral (`SettingsPage.tsx:235-247`, `LafayetteGuardaRatioInput`): solo `isSuperadmin` (= `superadmin.all`); rango 0,5-1,0 inclusivo idéntico al backend; 403 -> "solo superadmin", 422 con error en la clave -> mensaje de rango, otros -> genérico; tras guardar toma el objeto completo del PATCH. Tests: render solo superadmin, extremos inclusivos, valores fuera de rango sin llamada, 422/403/500, limpieza del error.
- i18n `settings.json` es/en: mismas 7 claves nuevas. `bodega.json`: la clave `_many` adicional en es es la categoría de plural legítima (no es una diferencia de traducción). Sin emojis en los diffs (grep) y los e2e añaden la comprobación existente (13).
- Playwright 1731185: mock de permiso de precio (7 vs 8 columnas, tarjeta móvil, sin `sort=precio`), mock de `/api/settings/system` con 422/403, y tres casos del campo (superadmin con rango y extremos; `settings.global` sin el campo). Reutiliza los textos reales de los JSON de i18n, por lo que una clave renombrada rompe el spec.
- Comprobación de mutación (razonada, no ejecutada): si `showPrice` pasara a `true` fijo, fallan los tests "SIN permiso: 7 columnas" (8 encabezados, y `$` en el contenedor); si el campo del umbral se mostrara a `settings.global`, falla el test de ausencia; si se quitara la validación de rango, fallan los `it.each` de valores fuera de rango (`updateSystemSettings` no debe llamarse). Los tests son de comportamiento, no solo de cobertura.
- 🟡 `SettingsPage.tsx` (~línea 736): al insertar el bloque nuevo quedó huérfano arriba el comentario "SCRUM-746 — mismo patrón que ShareLinksExpirationInput..." que pertenece a `SessionInactivityHoursInput` (ahora separado por ~80 líneas). Mover el comentario. Cosmético.
- 🟡 Un `<input type="number">` con texto inválido queda vacío y "Guardar" reenvía el valor vigente sin error (mismo comportamiento que los demás campos de la página; está documentado en el test).

## Riesgos de regresión sobre lo aprobado en la ronda 2

Ninguno detectado. Los cambios del backend son aditivos (parámetro `$conPrecio` con default `true`, clave nueva en `meta` al final, clave nueva en `system settings`); los consumidores existentes del contrato (frontend y espejo de pruebas) están actualizados en el mismo delta. Los pasos operativos pendientes (no son hallazgos): correr `tenants:artisan migrate` en dev/test/prod para aplicar 100400 y que la fila quede en 0,9.

## Bloqueantes: 0 — Sugerencias: 6 (todas cosméticas o de seguimiento)
