# Senior Review — Fase 5b-1: eliminar el código de Legacy (ICG) y Lafayette

**Fecha**: 2026-10-26 · **Spec**: `docs/specs/fase5b-eliminar-legacy-lafayette.md` (§6-§7) · **Veredicto**: APROBADO, 0 bloqueantes.

## Qué se revisó
- Borrado de los dos módulos y de todo lo que solo ellos usaban (comandos, tests, configs, conexiones, rutas, limitador, trait `MensajeSeguroDeExcepcion`, scripts `infra/legacy-sync*.sh`, drivers del Dockerfile). Sin cambios de esquema: ninguna migración se tocó.
- `AppServiceProvider`, `JwtClaimsBuilder`, `PreferencesService`/`SettingsController`, `bootstrap/app.php` y las rutas quedaron sin ninguna referencia a los módulos (grep en `app/ routes/ config/ bootstrap/ tests/`).
- `WarehouseController::show()` de una bodega de solo consulta ya no depende del catálogo: devuelve KPIs en cero. `ProductWarehouseStock` usa una constante de 30 s en vez de `config('lafayette…')`.
- Se restauró la constante de auditoría `LEGACY_CARGAR_PEDIDOS_GEMELOS`: la lee `PedidosGemelosReportService` (Admin&Cont). PHPStan lo detectó.
- Frontend: sin `lib/modules`, sin banderas, sin rutas `/migracion/*`; la bodega de solo consulta muestra un aviso en vez de la tabla del catálogo.

## Verificación
- Backend: suite completa (5104 tests) con 3 fallos esperados —`/zona-libre/productos` 404, `legacy-history` 404 y la config de TTL borrada—; los 3 tests se adaptaron y se re-corrieron junto con todo Bodega/Auth/VentasDiseno (684 tests, verde). PHPStan nivel 8 limpio.
- Frontend: 234 archivos / 2951 tests, `tsc` y `vite build` limpios.
- `docker build` de `infra/php/Dockerfile` sin sqlsrv/pdo_mysql: construye.

## Hallazgos (sin bloqueantes)
1. **Volumen de tests**: de 6203 a 5104 por los 136 archivos de Legacy/Lafayette borrados; esperado.
2. **Migraciones históricas** siguen creando ~30 tablas `legacy_*`/`lafayette_*` y columnas del núcleo en tenants nuevos hasta la 5b-2 (decisión de Luis: crear y luego borrar).
3. **Residuos de comentarios** que mencionan ICG en tests y tipos (`origin: 'icg_legacy'`, `external_source`) se retiran con los campos en la 5b-2.
4. **Entornos con módulos encendidos**: si algún entorno real tenía `MODULE_LEGACY_ICG`/`MODULE_LAFAYETTE` en `true`, esas variables ya no hacen nada (inofensivas, pueden quitarse del `.env`).
