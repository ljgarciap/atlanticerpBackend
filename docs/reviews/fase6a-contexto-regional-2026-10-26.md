# Senior Review — Fase 6a: contexto regional por tenant

**Fecha**: 2026-10-26 · **Spec**: `docs/specs/fase6-fiscal-por-pais.md` (§6-§7) · **Veredicto**: APROBADO, 0 bloqueantes.

## Qué se revisó
- Migración landlord aditiva con defaults de Panamá (los tenants existentes no cambian). Fuente de verdad en el tenant (landlord), no en el esquema del tenant: evita consultas por activación y el riesgo de abortar transacciones de Postgres.
- `SwitchTenantRegionTask`: aplica y restaura la zona de PHP y de la sesión de Postgres; un valor inválido cae a la zona de la instalación. `TenantRegion` es el único punto de lectura (sin tenant: `config('app.timezone')`).
- Sustitución de las constantes `BUSINESS_TIMEZONE` y literales `America/Panama` (Servicios, Auth) sin cambio de comportamiento con el default; los dos usos de `config('app.timezone', 'America/Panama')` ya leían la zona de la aplicación y ahora reflejan la del tenant.
- Perfil de empresa: lee/escribe el tenant; la validación pasó de `in:America/Panama` a `timezone:all`; país restringido al catálogo; moneda ISO de 3 letras.
- Frontend: `lib/region` + store; los formateadores leen el contexto en CADA llamada (nada se captura al cargar el módulo); sin sesión o con sesión anterior rige Panamá.

## Verificación
- Backend: suite completa 5064 tests (+11 nuevos) sin fallos; PHPStan 0; los tests de zona (Servicios, Auth, perfil) pasan sin tocar sus expectativas salvo las dos que fijaban «solo America/Panama» y «moneda no editable» (ahora lo contrario, por decisión de la spec).
- `TenantRegionTest` comprueba, sobre un tenant real: defaults, `tenant:set-region` (CO/US, afinar, solo lo pasado, inválidos), zona de PHP y de Postgres al activar/soltar, y `region` en login y `/auth/me`.
- Frontend: 235 archivos / 2950 tests, `tsc` y build limpios; el cambio de zona se prueba con `formatTimeShort`/`formatDateTimeShort` y el store.

## Hallazgos (sin bloqueantes)
1. **Hora local en la BD**: cambiar la zona de un tenant con datos reinterpreta los timestamps guardados. Mitigado con aviso en `tenant:set-region` y la nota de la spec; no se bloquea (decisión de negocio).
2. **Tests y landlord**: la fila de `tenants` no entra en la transacción de RefreshDatabase; se restaura en `FeatureTestCase::tearDown` (una sola actualización condicional).
3. **Pendientes**: sin UI para el catálogo de países (se edita en `config/regions.php`); `formatMoney` sin símbolo por estándar del cliente; algunas pantallas muestran moneda como texto fijo (`B/.`) — se revisan en 6b/6c si aparecen.
4. Los jobs en cola con tenant (`tenants:artisan`/Horizon) activan el tenant y por tanto su zona; no se encontró código que dependa de una zona global distinta.
