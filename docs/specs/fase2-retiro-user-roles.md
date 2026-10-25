# Spec — Fase 2: retirar el enum legado `user_roles` (Fase E del ADR-006)

**Fecha**: 2026-10-24 · **Estado**: BORRADOR — pendiente de aprobación de Luis (no se implementa sin ella).
**Contexto**: Luis autorizó la Fase E «dentro del aislamiento» (análisis `docs/analisis/aislamiento-nucleo-2026-10-24.md`, decisión 6). La parte
**destructiva** (borrar la tabla) necesita además confirmación explícita aparte (regla del workspace) — ver §4, paso 2.2.

## 1. Problema
Conviven dos mecanismos de roles:
- **Nuevo (ADR-006)**: `users.role_id` (rol base) + `user_additional_roles`, roles como datos (`roles`, 14 filas), permisos por `security_level`.
- **Legado**: tabla `user_roles` (strings: `superadmin`, `management`, `designer`, `supervisor`, `electrician`, `guest`) y las constantes `User::ROLE_*`.

El legado ya no decide acceso (salvo el parche KAN-11), pero sigue vivo y causa fallas reales:

1. **El registro público no puede dar un rol real.** `UserService::approve()` solo escribe `user_roles` y el endpoint solo acepta los 5 strings legados
   (`UserController::approve`, `catalog`). Quien se registra y es aprobado **no recibe `role_id`**: ninguno de los 14 roles reales es asignable por esa vía.
   Hoy solo funciona porque `User::roleIds()` resuelve `superadmin`/`management` contra `roles.key` (KAN-11); `designer`/`supervisor`/`electrician` no tienen equivalente y quedan sin acceso.
2. **Tres notificaciones críticas buscan superadmins solo por `user_roles`** (`NotifySuperadminsOfAnomaly`, `LegacySyncAlertService`, y el filtro de
   `AdminPasswordResetService`): un superadmin creado desde la pantalla de usuarios (con `role_id`, sin fila en `user_roles`) **no recibe alertas de seguridad**,
   y un reset masivo podría **incluirlo** (la exclusión de superadmins se calcula con `user_roles`). Es una falla de seguridad latente, no solo deuda.
3. **Bloquea la Fase 3 (roles genéricos)**: el código compara claves de rol literales (`'management'`, `'superadmin'`) vía `User::ROLE_*`; renombrar roles lo rompería.
4. Dos escrituras por usuario (`role_id` y `user_roles`) que pueden divergir; cinco seeders escriben directo a `user_roles`.

## 2. Inventario medido (sobre `master`, 2026-10-24)
**Producción**
| Uso | Dónde | Cambio |
|---|---|---|
| Relación/consulta `userRoles`, `roles()`, `hasRole()` | `User.php` (+ `roleIds()` con el parche KAN-11), `UserService` (create/update/approve/reject/register/listPending/list), `AuthController` (bloqueo de invitados), `ImpersonationService`, `ApprovalsPendingService`, `EvaluateNotificationRulesListener` | leer `role_id`/`additionalRoles` |
| Buscar superadmins por `user_roles` | `NotifySuperadminsOfAnomaly`, `LegacySyncAlertService`, `AdminPasswordResetService` (l.124) | `roles.is_superadmin` vía `role_id` y roles adicionales |
| `JwtClaimsBuilder` | fallback `$user->roles()[0]` para el claim `role` | quitar el fallback |
| `User::ROLE_*` como claves de rol | `AIController` (3), `ProjectAccess`, `UserController` (catálogo y `approve`), `CreateUserRequest`/`UpdateUserRequest` (validación) | permisos (`platform.management`, `canViewTeamV2`) o `role_id` |
| API | `GET /users/catalog` (`roles: string[]` legados), `POST /users/{id}/approve` (`role: string`), payload `roles` | ver §3.4 |
| Seeders que escriben `user_roles` | `AuthUserSeeder`, `CoreUserSeeder`, `CrmDemoSeeder`, `QaTicketsBatch20260725Seeder`, `VentasDisenoDemoSeeder` | escribir solo `role_id` |
**Tests**: 15 archivos referencian `user_roles`/`UserRole`/`ROLE_*`/`hasRole`. **Frontend**: `ApproveUserModal` (catálogo de roles), `usersApi` (`roles: string[]`), `impersonationEligibility` (`roles.includes('guest')`).
**Migraciones históricas**: ~12 mencionan `user_roles`/`ROLE_*` (históricas, inmutables: no se tocan; las que usan constantes se deben dejar con literales).

## 3. Diseño propuesto
### 3.1 Un solo mecanismo: `role_id` + `user_additional_roles`
Toda lectura de «qué rol tiene» sale de `role_id`/roles adicionales; el claim `role` del JWT sigue siendo `roles.key` del rol base.

### 3.2 «Registro pendiente»: rol de sistema `guest` (recomendado)
Hoy «pendiente» = fila `user_roles.role='guest'` + `is_active=false`. Se reemplaza por un **rol de sistema `guest`** en la tabla `roles`
(`is_system=true`, sin visibilidad de módulos ni permisos, **no asignable** desde los selectores de la UI):
- `register()` crea el usuario con `role_id = guest`; `listPending`/`reject`/bloqueo de login/impersonación comparan contra ese rol.
- `approve($user, $roleId, $securityLevelId)` cambia `role_id` al rol elegido (y, si lo hay, usa su `default_security_level_id` como sugerencia).
- Ventaja: reutiliza el mecanismo existente, 1:1 con la semántica actual, sin columnas nuevas. Alternativa descartada: columna `registration_status` (más cambios de esquema y de consultas).

### 3.3 Migración de datos (reversible)
Migración de tenant, en este orden y dentro de transacción:
1. Crear el rol `guest` si falta.
2. **Backfill**: a todo usuario con `role_id IS NULL` y fila en `user_roles`: `guest`→rol `guest`; `superadmin`→rol `superadmin`; `management`→rol `management`;
   `designer`/`supervisor`/`electrician` (sin equivalente en el catálogo, solo cuentas demo) → **`role_id` queda NULL** (sin acceso, igual que hoy; se documenta, no se inventa un rol).
3. Aborta con mensaje claro si algún usuario activo quedaría sin rol y tenía acceso real antes (no debería ocurrir; se verifica y se informa, nunca se ignora).
`down()` reconstruye `user_roles` desde `role_id` (mapeo inverso para `guest`/`superadmin`/`management`).

### 3.4 Contrato de API
- `POST /users/{id}/approve`: body `{ role_id, security_level_id }` (antes `{ role: string, … }`). Validación contra `roles` (activos, no `guest`, y el gate existente `assertRoleAssignable` para roles superadmin).
- `GET /users/catalog`: deja de existir o devuelve los roles asignables de la tabla `roles` (el frontend ya tiene `rolesApi`; se prefiere eliminar el endpoint y usar `rolesApi`).
- Payload de usuario: `roles: string[]` pasa a ser la lista de **claves** (`role.key` + adicionales), mismo nombre de campo para no romper el frontend.
- `CreateUserRequest`/`UpdateUserRequest`: se quita la validación del enum legado `role`.

### 3.5 Claves de rol literales → permisos
`AIController` (alcance gerencial de IA) y `ProjectAccess` pasan de `in_array($role, [ROLE_SUPERADMIN, ROLE_MANAGEMENT])` a `canViewTeamV2('ventas_diseno')` /
`platform.management`, como ya se hizo en CRM (Fase 3 de la auditoría). Se eliminan las constantes `User::ROLE_*` al final.

## 4. Fases de entrega (dos pasos para que el borrado quede aislado)
**2.1 — Dejar de usar `user_roles` (aditivo, la tabla se conserva)**: migración de datos de §3.3; todo el código de §2 lee/escribe `role_id`; seeders y tests
migrados; frontend (`ApproveUserModal`, `usersApi`, `impersonationEligibility`). `user_roles` queda **sin lectores ni escritores** pero intacta, así el paso se revierte con un `git revert` sin pérdida.
**2.2 — Borrar la tabla y las constantes** (PR aparte, **tras verificarlo en `dev`**): `DROP TABLE user_roles` (su `down()` la recrea y repuebla desde `role_id`) y eliminar `User::ROLE_*`/`UserRole`.
**Requiere tu confirmación explícita antes de implementarse.** Hoy no hay datos de producción reales (no existe EC2), pero la regla del workspace aplica igual.

## 5. Criterios de aceptación
1. Registro público → aprobación por un superadmin con `role_id` = cualquier rol real **asignable** (probado con `vendedor_disenador` y `lider_bodega`): la persona entra con los permisos de ese rol.
2. Un superadmin creado solo con `role_id` **recibe** las alertas de seguridad y de sincronización, y un reset masivo **lo excluye** (tests de regresión de los 3 sitios).
3. Invitados (`guest`): no inician sesión, no son impersonables, aparecen en pendientes; aprobar los saca de ahí; rechazar los borra.
4. Cero lecturas/escrituras a `user_roles` y a `User::ROLE_*` fuera de migraciones históricas (verificado por grep en CI o test).
5. El backfill es idempotente y su `down()` restaura `user_roles`; probado en una BD con datos mezclados (con y sin `role_id`).
6. PHPStan L8 limpio; suite completa del backend y del frontend en verde; Playwright CLI: registro → aprobación → login de la persona aprobada.

## 6. Decisiones que necesito de Luis
1. **Registro pendiente = rol de sistema `guest`** (recomendado) vs. columna `registration_status`.
2. **`designer`/`supervisor`/`electrician` (solo cuentas demo): quedan con `role_id` NULL** (recomendado, igual que hoy) vs. mapearlos a un rol real.
3. **Dos pasos (2.1 aditivo, 2.2 borrado en PR aparte tras `dev`)** (recomendado) vs. todo en un solo PR.
4. `GET /users/catalog`: **eliminarlo** y usar `rolesApi` (recomendado) vs. mantenerlo con los roles de la tabla.

## 7. Riesgos
- Quien tuviera hoy acceso **solo** por el parche KAN-11 (`superadmin`/`management` legados sin `role_id`) lo conserva gracias al backfill; si el backfill no los encuentra, perderían acceso → criterio 5 y aborto con mensaje.
- El reset masivo y las alertas de seguridad dependen de identificar superadmins: es el cambio de mayor riesgo; su regresión (criterio 2) es obligatoria antes de mezclar.
- Orden con la Fase 3: esta fase **antes** de renombrar roles, para no tocar dos veces los mismos archivos.
