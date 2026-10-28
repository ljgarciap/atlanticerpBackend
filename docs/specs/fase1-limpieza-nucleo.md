# Spec: Fase 1 — Limpieza del núcleo (hermanos, personas, umbrales y rutas por rol)

**Date**: 2026-10-24
**Requested by**: Luis
**Status**: Draft (pendiente de aprobación de Luis — incluye cambios destructivos de BD, ver «Aprobación explícita»)
**Project**: Atlantic ERP (`atlanticerp-backend`, `atlanticerp-frontend`)
**Parte de**: `docs/analisis/aislamiento-nucleo-2026-10-24.md` (sección 10, Fase 1)

## Problem
Atlantic es un fork de binax, hecho para un cliente concreto. La política de Luis (2026-10-24) es **generalizar**: ERP
multi-sector y multi-país, sin personas, sin productos del cliente y sin umbrales de negocio fijos en código. Hoy el código
trae cinco productos hermanos del cliente, nombres de personas en tests/e2e/seeders/i18n, constantes de negocio hardcodeadas
y rutas atadas a nombres de rol. Todo eso impide tratar el repo como núcleo genérico y encarece cada fase siguiente
(roles, demo, aislar Legacy, fiscal por país). Es la fase de menor riesgo: **no cambia el comportamiento del núcleo**.

## Solution summary
Cuatro bloques independientes sobre la rama que parte del merge de la sincronización con binax (Fase 0):

- **A. Sacar los productos hermanos**: Lumo, Soporte (widget de tickets externo), Biblioteca/Manuales, CotizaYA y la API de Tickets
  se eliminan de backend y frontend (código, rutas, permisos, tablas, configuración, i18n, tests).
- **B. Limpiar personas**: ningún test, seeder, fixture, e2e ni cadena de i18n depende de una persona. Los tests cuyo asunto es una
  persona se borran; los que verifican comportamiento de un rol y solo llevan el nombre de una persona se renombran al rol.
- **C. Umbrales de negocio a tablas**: las constantes que son reglas de negocio pasan a las tablas de ajustes existentes (o a una nueva
  donde no exista), con valor por defecto sembrado y vista de administración para superadmin.
- **D. Rutas y menús por permiso**: toda ruta y todo menú que decida por **nombre de rol** pasa a decidir por permiso.

## Users and roles
- **superadmin**: administra ajustes (bloque C) y sigue siendo el único con `superadmin.all`.
- **Resto de roles**: su acceso efectivo **no debe cambiar** (esa es la prueba de que la fase es de bajo riesgo). Cualquier cambio en lo que
  un rol puede ver o hacer es un defecto, no un efecto esperado.
- No se crean roles nuevos ni se renombran los existentes (eso es la Fase 3).

## Alcance detallado

### A. Productos hermanos que SALEN
| Pieza | Backend | Frontend | BD / config |
|---|---|---|---|
| **Lumo** (puente de identidad a LUMO) | `app/Modules/Lumo` (2), `routes/lumo.php`, `tests` (1), `config/services.php` clave `lumo` | 15 archivos que lo nombran | permiso `lumo.view` (catálogo + niveles + `extra_permissions`); env `LUMO_*` |
| **Soporte** (widget que emite código al Tickets externo) | `app/Modules/Soporte` (2), `routes/soporte.php`, `tests` (1), `config/services.php` clave `tickets` | el widget de soporte (≈33 archivos nombran «soporte»; ver NO tocar) | env `TICKETS_*` |
| **Biblioteca / Manuales** (biblioteca de documentos en S3) | `app/Modules/Biblioteca` (11), `routes/biblioteca.php`, `EnsureLibraryConfigured`, throttle `biblioteca-lectura`, tests (5) | 12 + 24 archivos («biblioteca», «manuales») | permiso `manuales.manage`; ajustes de biblioteca; env `AWS_LIBRARY_*` |
| **CotizaYA** (Cliente Master/Subcliente y folios COTY) | 33 archivos en `VentasDiseno` (controllers 7, services 5, models 3, scopes 2, support 2), seeders 2, 4 migraciones, 1 ruta | 20 archivos | tablas `cotizaya_settings`, `cotizaya_folio_counters`; columnas `cotizaya_*` de `quotes` y flags relacionados |
| **API de Tickets** (cliente HTTP) | cubierto por Soporte | — | env `TICKETS_*` |

**NO se toca (se confunde por nombre):**
- **«Sesiones de soporte» / «Ver como usuario»** (SCRUM-1158): es seguridad del núcleo, no el widget de Soporte.
- **Tickets de Servicios** (instalación, garantía, retrofit; ~110 archivos de frontend): es un proceso del núcleo, no la API de Tickets.
- **`share_links` de CRM** y la integración **Outlook**: núcleo/adaptador genérico.
- Migraciones históricas ya aplicadas: no se editan ni se borran (ver «Aprobación explícita» para cómo se retira el esquema).

### B. Personas
Regla de clasificación de tests (ejemplos reales; total medido: 155 métodos `test_*` con nombre de persona en 70+ archivos):
- **Se borran** (el asunto es una persona o una concesión por persona): `ContabilidadComoAprobadorVentasDisenoTest`, `JwtClaimsPrimaryApproverFlagTest`,
  `SpecialPermissionSeederTest` (casos por persona), `UserModuleVisibilityGrantSeederTest` (concesiones por persona), tests de
  `BusinessRoleUserSeederTest` sobre personas concretas.
- **Se renombran al rol** (verifican comportamiento de un rol y la persona solo está en el nombre): p. ej. `test_contabilidad_puede_…`
  → `test_lider_admin_contab_puede_…`; en `PipelineEditPermissionTest`, `AdminContInvoiceControllerTest`, `AdminContReportsControllerTest`, etc.
- `FixtureSpecialPermissionSeeder` se **reduce** a concesiones por rol/identidad genérica estrictamente necesarias; si un test solo necesitaba
  una concesión "de persona", el test pasa a crear su propio usuario con el permiso.
- Seeders, e2e (`e2e/*.spec.ts`, 88 archivos con nombres) y cadenas de i18n (es/en) quedan sin nombres; prosa de documentos históricos y
  migraciones ya aplicadas **fuera de alcance**.
- Denylist de verificación (insensible a mayúsculas, palabra completa): los nombres de pila y apellidos de `scripts/upstream-sync/idmap.json`
  más `Gerencia1 Test` y las variantes «Aprobador/Contabilidad» como persona.

### C. Umbrales de negocio a tablas
| Constante hoy | Destino |
|---|---|
| `ITBMS_RATE_LOCAL = 0.07` (`Compras/GoodsReceiptController`) | tabla de tasas `admin_contab.itbms_rates` (ya existe) |
| `DEFAULT_ITBMS_PERCENT = 7` (`Servicios/ServiciosSettingsService`) | misma tabla de tasas |
| `STAGNANT_THRESHOLD_DAYS = 15` (`VentasDiseno/PipelineCard`) | `ventas_diseno` ajustes (pipeline) |
| `STALE_CONTACT_DAYS = 60` (`VentasDiseno/ClientsService`) | `ventas_diseno` ajustes |
| `CRITICAL_DAYS_POR_APROBAR = 3` (`Compras/PurchaseOrder`) | `compras.settings` |
| `DIAS_UMBRAL_COMISION_PENDIENTE = 10` (`AdminCont/PendingAlertsService`) | `admin_contab_settings` |
Las constantes `DEFAULT_*` que **ya** leen de una tabla de ajustes (servicios, bodega, crm) no se tocan. Las constantes técnicas
(`CircuitBreaker`, límites de paginación, `MAX_DAYS` de auditoría) tampoco: no son reglas de negocio.

### D. Rutas y menús por permiso
- Rutas con `role:` por nombre (`routes/ventas-diseno.php` dashboard y `orders`, `routes/gerencia.php`, `routes/users.php`,
  `routes/security-levels.php`, `routes/departments.php`, `routes/admin.php`) pasan a `permission:`. Los permisos nuevos que hagan falta se
  agregan al catálogo y a los niveles de seguridad **con el chequeo de fuga** de la Fase 2 (ningún otro rol comparte el nivel).
- `Sidebar.tsx` y `ToolsPanel.tsx` leen `user.permissions` en vez de comparar nombres de rol.
- `superadmin` se resuelve por `superadmin.all`, no por nombre.

## Acceptance criteria
Cada criterio es verificable por QA con el comando indicado.

**A — Hermanos**
- [ ] `app/Modules/{Lumo,Soporte,Biblioteca}`, `routes/{lumo,soporte,biblioteca}.php` y sus tests no existen; `php artisan route:list` no contiene rutas `/api/lumo`, `/api/soporte` ni `/api/biblioteca`.
- [ ] No queda referencia a `Lumo`, `Soporte\\`, `Biblioteca\\`, `CotizaYa`/`cotizaya` ni a la API de Tickets en `app/`, `routes/`, `config/`, `bootstrap/`, `resources/`, `lang/` (verificado con `grep -rIiE`), salvo migraciones históricas aplicadas.
- [ ] `lumo.view` y `manuales.manage` no están en `permission_catalog`, ni en `security_levels.permissions`, ni en `users.extra_permissions` de ningún tenant; el catálogo total pasa de 56 a 54 permisos y `PermissionCatalogTest` lo refleja.
- [ ] Las variables `LUMO_*`, `TICKETS_*` y `AWS_LIBRARY_*` no están en `.env.example`, `.env.testing`, `config/services.php` ni `config/filesystems.php`.
- [ ] En el frontend no existen páginas, componentes, rutas, textos i18n ni e2e de Lumo, Soporte (widget), Biblioteca/Manuales ni CotizaYA; `tsc --noEmit` y `vitest` en verde.
- [ ] «Sesiones de soporte» («Ver como usuario»), los tickets de Servicios, `share_links` y Outlook **siguen funcionando** (sus tests actuales pasan sin modificar).
- [ ] Las cotizaciones se crean, versionan y consultan sin `cotizaya_*`; los folios de cotización no cambian de formato para los flujos restantes.

**B — Personas**
- [ ] Los archivos de test listados como «se borran» no existen; ningún test restante tiene un nombre de persona en su nombre de clase o método (denylist = 0 coincidencias en `tests/` y en nombres de `describe`/`it` del frontend).
- [ ] Denylist = 0 coincidencias en `database/seeders`, `tests/`, `e2e/`, `src/i18n/locales/{es,en}`, `resources/`, `lang/` (excluye `docs/` y migraciones ya aplicadas).
- [ ] `SpecialPermissionSeeder::GRANTS` sigue vacío y `FixtureSpecialPermissionSeeder` queda **sin** personas ni emails de personas (solo emails genéricos `@test.com`).
- [ ] El conteo de tests no baja por encima de lo borrado a propósito: el informe de cierre lista cada test borrado con su motivo.

**C — Umbrales**
- [ ] `grep -rnE "0\.07|= 7;" app/` no devuelve tasas de ITBMS fuera de seeders/migraciones; las 6 constantes de la tabla ya no existen como valor fijo en código.
- [ ] Cada valor se lee de su tabla; si falta la fila, se usa el valor sembrado por migración (mismo número de hoy) → **comportamiento idéntico** con los datos de ejemplo.
- [ ] Un superadmin puede cambiar cada umbral desde la pantalla de ajustes correspondiente sin deploy, y el cambio se refleja en la siguiente operación (test de feature por cada uno).
- [ ] Un usuario sin permiso de configuración recibe 403 al intentar cambiarlos.

**D — Rutas y menús**
- [ ] `grep -rn "role:" routes/` no devuelve ningún `role:` con nombre de rol (`superadmin` incluido; se admite solo `permission:`).
- [ ] Matriz de equivalencia: para cada rol y cada ruta cambiada, la respuesta (200/403) es **idéntica** antes y después (test de matriz parametrizado generado de las rutas modificadas).
- [ ] `Sidebar.tsx` y `ToolsPanel.tsx` no comparan strings de rol; sus tests existentes pasan y se agrega un test por permiso.
- [ ] Ninguna migración nueva escribe en un `security_level` compartido sin la verificación de fuga (igual que las migraciones `2026_10_23_*`).

**Transversales**
- [ ] PHPStan nivel 8 limpio; `infra/test.sh` completo sin fallos nuevos; `tsc` y `vitest` limpios.
- [ ] El CI de los PR pasa en verde (backend con 2 GB de memoria).
- [ ] Binax sigue intacto (la fase no escribe nunca en `Illumination/`).
- [ ] Migraciones reversibles: `migrate:rollback` de las nuevas funciona (se documenta qué datos no se recuperan).
- [ ] `CLAUDE.md` de Atlantic y `docs/analisis/aislamiento-nucleo-2026-10-24.md` actualizados al cerrar.

## Aprobación explícita requerida (cambios destructivos de BD)
Regla del workspace: *"Hacer cambios destructivos en BD sin confirmación explícita"* está prohibido. Aprobar esta spec = autorizar solo lo siguiente:
1. `DROP` de las tablas `cotizaya_settings` y `cotizaya_folio_counters` y de las columnas `cotizaya_*` de `quotes` (tenant `atlantic`).
2. Borrado de las filas de `lumo.view` y `manuales.manage` en `permission_catalog`, `security_levels.permissions` y `users.extra_permissions`.
3. Borrado de los ajustes de biblioteca (`global_settings` / filas relacionadas) y del módulo de visibilidad que pudiera quedar.
Alcance de datos: el entorno de Atlantic es solo desarrollo (sin producción real); `down()` recrea la estructura **vacía** — los datos de estos
módulos **no se recuperan**. Antes de ejecutar se hace un volcado del schema `atlantic_*` en el scratchpad.

## Edge cases and error scenarios
- Una cotización existente con valores `cotizaya_*`: la migración las descarta; ninguna pantalla ni PDF debe leerlas después (test con una cotización sembrada con esos campos).
- Un usuario conserva `lumo.view` en `extra_permissions`: guardar su perfil tras la migración no debe fallar con 422 por «permiso inexistente» (la limpieza lo borra; hay test).
- Un nivel de seguridad que contenía los permisos retirados: se limpia el JSONB sin tocar los demás permisos del nivel.
- Una ruta cambiada de `role:` a `permission:` que un rol usaba por herencia de nivel: el test de matriz de equivalencia falla antes del merge si el acceso cambió.
- Una constante migrada a tabla sin fila (BD nueva): se siembra el valor por defecto en la misma migración; sin fila no debe lanzar excepción.
- El frontend recibe del backend un permiso retirado en `user.permissions` de un token antiguo: debe ignorarlo sin romper el menú.
- Repetición: re-ejecutar las migraciones y el limpiador es **idempotente** (segunda corrida no falla ni cambia nada).
- Fallo a mitad: cada migración corre en transacción por tenant; un error deja el schema como estaba.

## Out of scope
- Renombrar roles (Fase 3) y retirar `user_roles` (Fase 2, ya autorizada pero separada).
- Dataset demo genérico (Fase 4), aislar Legacy/Lafayette (Fase 5), paquete fiscal por país (Fase 6), catálogos de vocabulario (Fase 7).
- Eliminar términos de iluminación en seeders/e2e/i18n que no sean personas ni hermanos (eso es Fase 4).
- Editar o borrar migraciones históricas; reescribir documentos históricos (`docs/pre-qa`, `docs/reviews`, ADRs).
- Cambiar el comportamiento visible de cualquier módulo del núcleo.
- Sincronizar nuevos commits de binax (solo seguridad y correcciones, a futuro, caso por caso).

## Open questions
- [Luis] ¿Apruebas los 3 puntos destructivos de «Aprobación explícita» tal como están?
- [Luis] ¿`CotizaYA` también incluye el **Cliente Master/Subcliente** compartido o solo el flujo de cotización rápida? (el seeder `CotizaYaClientSeeder` crea un cliente maestro que otros flujos podrían usar).
- [Luis] La **Biblioteca de Manuales** es una biblioteca documental genérica: ¿se elimina igual (decisión actual) o se archiva para un futuro módulo documental?
- [Architect] Permisos nuevos para sustituir los `role:` de `ventas-diseno` dashboard/orders y `gerencia`: nombres y nivel (propuesta: `ventas_diseno.dashboard.view`, `ventas_diseno.orders.manage`, `gerencia.view`).
- [Architect] Ubicación de los 3 umbrales de `ventas_diseno` y `compras` si no hay tabla de ajustes con la columna (migrar una columna vs. tabla clave-valor `global_settings`).
- [Backend] Orden de ejecución dentro de la fase para minimizar conflictos: A → D → C → B (B toca la mayor cantidad de archivos; hacerlo al final con la base ya reducida).

## Plan de ejecución propuesto (para el PM tras la aprobación)
1. A (hermanos), un PR por repo, con un commit por pieza (Lumo, Soporte, Biblioteca, CotizaYA).
2. D (rutas/menús por permiso) con el test de matriz de equivalencia.
3. C (umbrales a tablas) con migración de siembra por umbral.
4. B (personas) en tandas por módulo, con informe de tests borrados/renombrados.
5. Cierre: suite completa, CI, actualización de docs y notificación al Arquitecto.

## References
- Análisis: `docs/analisis/aislamiento-nucleo-2026-10-24.md` (secciones 4, 6, 9, 10)
- Herramientas y lecciones: `scripts/upstream-sync/README.md`; permisos y fugas de nivel: `Atlantic/CLAUDE.md` § «Esquema de permisos»
- Código relacionado: `app/Modules/{Lumo,Soporte,Biblioteca}`, `app/Modules/VentasDiseno/**/*CotizaYa*`, `routes/{ventas-diseno,gerencia,users,security-levels,departments,admin}.php`,
  `src/components/{Sidebar,ToolsPanel}.tsx`, `tests/Support/FixtureSpecialPermissionSeeder.php`
- Sincronización previa: PR `ljgarciap/atlanticerpBackend#1` y `ljgarciap/atlanticerpFrontend#1`
