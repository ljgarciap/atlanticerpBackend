# Spec — Fase 3: catálogo de roles genérico (sin vocabulario del cliente anterior)

**Fecha**: 2026-10-25 · **Estado**: decisiones de §7 RESPONDIDAS por Luis (2026-10-25); pendiente su «adelante» para implementar (incluye confirmar el cambio de datos de §3.1).
**Contexto**: decisión 5 de Luis (2026-10-24, `docs/analisis/aislamiento-nucleo-2026-10-24.md` §9 y §11): «renombrar los roles a términos genéricos».
La Fase 2 dejó `role_id` como único mecanismo, así que este renombre ya no toca el enum legado.

## 1. Problema
Los roles son **datos** (`roles.key`, `roles.name`), pero su vocabulario viene del cliente de iluminación: `vendedor_disenador` («Vendedor/Diseñador»),
`ayudante_general_bodega`, `tecnico_servicios`/`garantias_servicios` (nombres de ese negocio). Un ERP multi-sector no debería mostrar «Diseñador» ni «Garantías de Servicios»
a un cliente de otro rubro, y esas claves además salen en el JWT (`role`), en la API y en cada test/seeder.

Hoy esas claves están **enlazadas por literal** en código de producción (no solo en datos), así que un renombre por la UI de Roles rompería funciones. Esta fase fija el catálogo
y hace el renombre de una vez, con las claves centralizadas para que el siguiente renombre sea de una línea.

## 2. Alcance medido (sobre `master`, 2026-10-25)
Solo **4 de los 14 roles** necesitan renombre; el resto ya es genérico (`superadmin`, `management`, `lider_*`, `asistente_*`, `transporte`).

| Rol actual | Propuesta (clave / nombre ES / nombre EN) |
|---|---|
| `vendedor_disenador` | `vendedor` / Vendedor / Salesperson |
| `ayudante_general_bodega` | `auxiliar_bodega` / Auxiliar de Bodega / Warehouse Assistant (Aide) |
| `tecnico_servicios` | `tecnico` / Técnico / Technician |
| `garantias_servicios` | `tecnico_garantias` / Técnico de Garantías / Warranty Technician |
| los otros 10 | sin cambio (nombres ya genéricos) |

**Código de producción con la clave literal (hay que cambiarlo):**
| Clave | Sitios (lógica, no comentarios) |
|---|---|
| `vendedor_disenador` | 4 consultas `Role::where('key','vendedor_disenador')` = «el roster de vendedores»: `AdminContHomeController`, `AdminContInternalCommissionController`, `PendingAlertsService`, `BodegaSeedUatProjectsCommand` |
| `ayudante_general_bodega` | `BodegaRoles::PICKER` (de ahí cuelga `CAN_PICK`) |
| `tecnico_servicios` / `garantias_servicios` | `TechnicianController::INTERNAL_TECHNICIAN_ROLE_KEYS`, `TechnicianSpecialtyService` (2 constantes), `ClaimSheetService`, `InspectionReportService`, `ServiceQuoteService` |
Más ~12 lecturas del claim `role` del JWT (`$jwt['role']`) en Bodega, Ventas & Diseño, AdminCont y Servicios que comparan contra claves.

**Datos guardados con la clave**: `roles.key`/`roles.name`; `notification_rules.recipient_value` (reglas por rol); las 14 etiquetas de `common:roles.*` en i18n (es/en).
**Tests y fixtures** (referencias por rol, no por nombre de persona): backend 6–40 archivos por rol renombrado (`lider_bodega` no cambia; `vendedor_disenador` 40 archivos/118 menciones, `tecnico_servicios` 22/71,
`garantias_servicios` 6/14, `ayudante_general_bodega` 7/15); frontend ~11 archivos de test + fixture `src/test/fixtures/identity-permissions.json`; e2e ~13+18+3+4 specs.
**Documentación**: `erp/docs/manuals/vendedor-disenador.md` (y los demás manuales que nombren los roles).
**Migraciones históricas**: ~40 mencionan las claves; **no se tocan** (inmutables). Corren antes que la migración nueva en una BD recién creada, así que siguen funcionando.

## 3. Diseño propuesto
### 3.1 Migración de datos (reversible)
Una migración de tenant, dentro de transacción: `UPDATE roles SET key, name` para los 4 roles (la `key` es única y `users.role_id` apunta por id, así que **ningún usuario cambia de rol**);
traduce `notification_rules.recipient_value` (mismo patrón que la migración 500001 de la Fase 2); `down()` revierte las mismas 4 filas y el valor de las reglas.
Aborta con mensaje claro si ya existe un rol con la clave destino (p. ej. alguien creó `tecnico` por la UI).

### 3.2 Claves centralizadas: `RoleKeys`
Una clase de constantes (`App\Modules\Auth\Support\RoleKeys`) con las 14 claves. Todo literal de rol en código de producción pasa a usarla (los ~9 sitios de §2); `BodegaRoles` y
las constantes de `TechnicianSpecialtyService` dejan de duplicar el string. Un test falla si aparece un literal de clave de rol fuera de `RoleKeys` (evita que vuelva a pasar).

### 3.3 Claim `role` del JWT y sesiones abiertas — **reingreso obligado** (decisión de Luis)
Los permisos viajan en el JWT y no dependen de la clave, así que el acceso no cambia. Pero ~12 lecturas del claim `role` comparan contra claves: un token **emitido antes** del renombre trae la clave vieja y esas lecturas
(tablero de Bodega, estado de pedidos, cotizaciones, caja de AdminCont, técnicos externos) no coincidirán hasta que la persona vuelva a iniciar sesión. **No habrá tabla de alias**: se despliega avisando que las sesiones abiertas deben reingresar
(el token vive poco; verificar el TTL real de `JwtService`). Un test confirma que un token emitido con la clave vieja falla *de forma segura* (sin acceso de más) en esas lecturas.

### 3.4 Frontend
- Etiquetas `common:roles.*` (es/en): claves nuevas y quitar las legadas (`designer`, `supervisor`, `electrician`, `guest`, que ya no existen).
- `Role` ya es `string` (sin unión): no hay tipo que migrar. TopBar y UserTable muestran la etiqueta por clave con `defaultValue` al nombre del rol; **se deja de duplicar** y se usa `roles.name` de la API como fuente
  (i18n solo como respaldo), para que renombrar por CRUD funcione sin deploy.
- Regenerar el fixture de identidades desde el backend y renombrar en tests/e2e.

### 3.5 Seeders, tests y docs
Seeders con las claves nuevas (vía `RoleKeys`); tests renombrados mecánicamente (`scripts/upstream-sync` ya tiene el patrón de reemplazo exacto); `erp/docs/manuals/vendedor-disenador.md` → `vendedor.md`.

## 4. Fuera de alcance
- Nombres de **personas/identidades demo** (`vendedordisenador@test.com`, «Vendedor Disenador Test»): Fase 4 (dataset demo genérico). Los emails seguirán derivando de la etiqueta vieja hasta entonces.
- Renombrar **módulos** (`ventas_diseno`, `admin_contab`, `servicios`), niveles de seguridad (hueco en el 12) y permisos: fases 4-7.
- Dejar de decidir por clave de rol en código (convertir esos ~9 sitios a permisos): mejora aparte; aquí solo se centralizan.

## 5. Criterios de aceptación
1. Tras la migración, los 14 roles existen con el catálogo de §2, **todos los usuarios conservan su rol** (mismo `role_id`) y sus permisos efectivos no cambian (test de equivalencia sobre el seed).
2. Cero literales de clave de rol vieja o nueva en código de producción fuera de `RoleKeys` (test automático) y cero menciones de las 4 claves viejas en tests, seeders, e2e y fixtures (salvo migraciones históricas).
3. La migración es idempotente, aborta si la clave destino ya existe y su `down()` restaura claves, nombres y reglas de notificación.
4. Un token emitido antes del renombre **no** obtiene acceso de más en las lecturas del claim (falla cerrado); tras reingresar todo funciona.
5. La UI muestra el nombre del rol desde la API: renombrar un rol por la pantalla de Roles se refleja sin tocar i18n.
6. PHPStan L8 limpio; suites completas de backend y frontend en verde; Playwright CLI: login con un Vendedor, un Técnico y un Auxiliar de Bodega y verificación de que menú y rutas son idénticos al «antes».

## 6. Entrega
Un PR por repo, backend primero (la migración y `RoleKeys`), frontend después. Antes de implementar: spec aprobada → Senior Review → QA, y confirmar que el `UPDATE` de claves
(cambio de datos) cuenta con tu confirmación explícita, aunque es reversible.

## 7. Decisiones (respondidas por Luis el 2026-10-25)
1. **Catálogo**: los 4 renombres de §2 con los nombres propuestos (`vendedor`, `auxiliar_bodega`, `tecnico`, `tecnico_garantias`). ✅ recomendado
2. **Alcance**: renombrar **claves y nombres**. ✅ recomendado
3. **`management`/`superadmin`**: se mantiene la separación. ✅ recomendado
4. **Tokens**: **reingreso obligado**, sin ventana de alias (no era la recomendación; implica avisar y un test de «falla cerrado», ver §3.3).

## 8. Riesgos
- Un literal de clave olvidado en una ruta rara degrada en silencio una función (p. ej. picking sin picker): lo cubre el test de §5.2 y el equivalente de permisos de §5.1.
- Si alguien creó roles propios por la UI con claves destino (`tecnico`), la migración aborta: se resuelve a mano antes.
- Es una fase **mecánica pero ancha** (~150 archivos de test): se hace con el reemplazo exacto de `scripts/upstream-sync` y se verifica con la suite completa, no a ojo.
