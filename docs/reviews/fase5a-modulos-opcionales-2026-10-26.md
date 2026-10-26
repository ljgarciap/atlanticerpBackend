# Senior Review — Fase 5a: Legacy (ICG) y Lafayette apagables por configuración

**Fecha**: 2026-10-26 · **Spec**: `docs/specs/fase5-aislar-legacy-lafayette.md` (decisiones de Luis en §6) · **Veredicto**: APROBADO, 0 bloqueantes.

## Qué se revisó
- `config/modules.php` (`MODULE_LEGACY_ICG`, `MODULE_LAFAYETTE`, apagados por defecto) y los dos providers nuevos (`LegacyServiceProvider`, `LafayetteServiceProvider`), que concentran
  bindings de extractores, el observer de `SubClient` y el registro de comandos. `AppServiceProvider` solo los registra si la bandera está encendida.
- 53 comandos movidos de `app/Console/Commands` a `Modules/Legacy/Console/Commands` y `Modules/Integrations/Lafayette/Console/Commands` (namespace + referencias en tests); sin esto el autodescubrimiento de
  Laravel los registraba siempre.
- Rutas (`/api/legacy`, `legacy-history`, `zona-libre/productos`) y schedules de Lafayette condicionados; las solicitudes de Zona Libre y `zona-libre/provider` siguen en el núcleo.
- `flags.legacy_icg` / `flags.lafayette` en los claims del usuario (dato visual, mismo criterio que `cobros_v2`); `lafayette_guarda_min_ratio` solo se lee/escribe con el módulo encendido.
- `ComprasSettings`: `zona_libre_provider_id` editable por API (validado contra `providers`) y por pantalla (selector en Ajustes de Compras). Resuelve el hallazgo de la 4a.
- Defaults neutros: sin `app.grupolafayette.com`, sin base `ILLUMINATION`. `.github/workflows/*`: `if: false &&` en los 3 jobs de deploy de cada repo.

## Verificación
- Backend: `infra/test.sh` completo **6203 tests, 0 fallos** (1 omitido preexistente); PHPStan nivel 8 limpio; Pint limpio en los archivos nuevos/tocados con Pint previo.
- Contrato nuevo `tests/Feature/Modules/OptionalModulesTest` (5 casos): arranca `artisan` en proceso aparte con las banderas apagadas/encendidas y comprueba rutas, comandos y schedules (apagados no existen; el núcleo de Zona Libre sí).
  Comprobado a mano: `artisan list` da 0 comandos `legacy:`/`lafayette:` apagado y 53 encendido.
- Frontend: 249 archivos / 3119 tests, `tsc` limpio; con el stack local (módulos apagados) y Playwright CLI: el superadmin no ve «Migración» y `/migracion/dashboard` redirige al inicio.
- La suite corre con ambas banderas ENCENDIDAS (`phpunit.xml`, `force="true"`): las suites de Legacy y Lafayette no cambiaron.

## Hallazgos
Sin bloqueantes. Sugerencias / abiertos:
1. **Banderas fijadas al construir la caché**: las rutas se registran según `config()` al arrancar; con `route:cache` la decisión queda congelada en el momento de cachear. Cambiar una bandera exige regenerar las cachés (documentado aquí; hoy el deploy no cachea rutas).
2. **Specs e2e históricos** (`e2e/preqa-*vista-icg*`, `*dashboard-migracion*`, `*pagos-icg*`, `*legacy-documento-ficha*`, `*migracion-superadmin-only*`) esperan ver Migración/ICG; con el módulo apagado fallarían. No corren en CI; correrlos solo con `MODULE_LEGACY_ICG=true`.
3. **Sesiones abiertas antes del cambio** no traen `flags.legacy_icg/lafayette`: con el módulo encendido deben reingresar para ver el menú (apagado es el caso por defecto, sin efecto).
4. **Dev local**: el `.env` real no define las banderas, así que los módulos quedan apagados en el stack local; para trabajar sobre ICG/Lafayette hay que poner `MODULE_*=true` a mano.
5. Las 63 migraciones `legacy_*`/`lafayette_*` siguen creando sus tablas en todo tenant nuevo (decisión de §2: historia inmutable). Eliminarlas es la 5b, con confirmación de `DROP` aparte.
6. `LafayetteLightingProviderSeeder` y `AlmacenEquivalenciaSeeder` siguen sin llamador (4a); se borran con la 5b si Luis elige eliminar.
