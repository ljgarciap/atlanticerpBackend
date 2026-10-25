# Spec — Fase 2: retirar el enum legado `user_roles` (Fase E del ADR-006)

**Fecha**: 2026-10-24 · **Estado**: APROBADA por Luis (2026-10-24) con las 4 recomendaciones de §6; se implementa el paso 2.1. El paso 2.2 (`DROP TABLE`) sigue requiriendo su confirmación explícita aparte.
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

## 6. Decisiones (respondidas por Luis el 2026-10-24: las 4 recomendaciones)
1. **Registro pendiente = rol de sistema `guest`** (recomendado) vs. columna `registration_status`.
2. **`designer`/`supervisor`/`electrician` (solo cuentas demo): quedan con `role_id` NULL** (recomendado, igual que hoy) vs. mapearlos a un rol real.
3. **Dos pasos (2.1 aditivo, 2.2 borrado en PR aparte tras `dev`)** (recomendado) vs. todo en un solo PR.
4. `GET /users/catalog`: **eliminarlo** y usar `rolesApi` (recomendado) vs. mantenerlo con los roles de la tabla.

## 7. Riesgos
- Quien tuviera hoy acceso **solo** por el parche KAN-11 (`superadmin`/`management` legados sin `role_id`) lo conserva gracias al backfill; si el backfill no los encuentra, perderían acceso → criterio 5 y aborto con mensaje.
- El reset masivo y las alertas de seguridad dependen de identificar superadmins: es el cambio de mayor riesgo; su regresión (criterio 2) es obligatoria antes de mezclar.
- Orden con la Fase 3: esta fase **antes** de renombrar roles, para no tocar dos veces los mismos archivos.

## 8. Resultado del paso 2.1 (2026-10-24)

Rama backend `fase2/retiro-user-roles`, rama frontend `fase2/retiro-user-roles`. La tabla `user_roles` **se conserva** (sin lectores ni escritores en `app/`, `routes/`, `config/` ni seeders); se borra en el paso 2.2.

**Hecho**
- Migración `2026_10_24_500000`: rol de sistema `guest` (inactivo, así no aparece en selectores ni es asignable) + backfill a `role_id` (idempotente; `down()` restaura la fila legada de quien se registró después).
- Migración `2026_10_24_500001`: las reglas de notificación del CRM con destinatario «rol» pasan de `designer` a `vendedor_disenador` (`supervisor`/`electrician` quedan como están, sin equivalente).
- `User`: `roles()`/`hasRole()` salen de `role_id` + roles adicionales; `isGuest()`; `scopeWithRoleKey()`; `roleIds()` sin el fallback KAN-11. `Role::KEY_GUEST`/`KEY_SUPERADMIN`.
- `UserService`: `register()` crea con rol `guest`; `approve($user, $roleId, $levelId, $actorHasSuperadmin)` (el gate de superadmin ahora también aplica al aprobar); `reject`/`listPending` por el rol; `create`/`update` ya no escriben `user_roles`; el rol `guest` no es asignable.
- Superadmins por `roles.key` (no por `user_roles`): `NotifySuperadminsOfAnomaly`, `LegacySyncAlertService`, `AdminPasswordResetService` (+ `operatorIsStillSuperadmin`).
- `AIController`/`ProjectAccess` por `canViewTeamV2('ventas_diseno')` y `superadmin.all` en vez de la clave de rol del JWT; `JwtClaimsBuilder` sin fallback.
- API: `POST /users/{id}/approve` recibe `role_id`; se elimina `GET /users/catalog`; `CreateUserRequest` exige `role_id`; se quitó el campo legado `roles` de create/update.
- Seeders: los 5 que escribían `user_roles` ahora usan `role_id` / `roles.key`. `multi@atlantic.test` pasa a `role_key = management` (antes lo resolvía el fallback KAN-11).
- Frontend: `ApproveUserModal` con `rolesApi` y `role_id`, `usersApi.catalog` eliminado, reglas de notificación con los roles de la tabla.

**Correcciones a lo que decía la spec**
1. **La ruta de registro público ya no existe** (se quitó en la sincronización con binax; `RegisterController` solo expone departamentos y `UserService::register()` no tiene llamadores fuera de los tests). El hallazgo §1.1 («el registro público no puede dar un rol real») era **latente**, no un bug activo. El flujo pendiente→aprobado sigue implementado y probado, pero hoy nada crea registros pendientes. Candidato a retirar del todo en la Fase 4.
2. **Efecto no previsto, corregido**: las reglas de notificación guardaban el rol legado en BD y la UI tenía una lista fija de 5 roles. Se tradujo con la migración `500001` y la UI usa la tabla.
3. El hallazgo de seguridad (§1.2) **sí era real** en cuanto a las alertas: un superadmin creado solo con `role_id` no las recibía. Cubierto por `Fase2RetiroUserRolesTest`.

**Verificación**: PHPStan 0 errores; `Fase2RetiroUserRolesTest` (7 tests: backfill, `down()`, alertas, reset masivo, scope, reglas); Auth+Users+CRM 1067 tests; frontend `tsc` limpio y 248 archivos / 3115 tests; e2e con Playwright: el pendiente no puede entrar, el modal ofrece los 14 roles reales sin `guest`, y la persona aprobada inicia sesión con su rol.

## 9. Pendiente para el paso 2.2 (requiere confirmación explícita de Luis: borra datos)
- `DROP TABLE user_roles` (su `down()` la recrea y repuebla desde `role_id`), eliminar `UserRole`, `User::ROLE_*` y las referencias en migraciones históricas (usan las constantes: dejarlas con literales).
- Quitar `DbAuditLogTriggerTest::test_user_roles_pk_compuesta...` y el trigger de auditoría de esa tabla (`create_db_audit_triggers`).
- **Decidido por Luis (2026-10-24): `register()` y todo el flujo pendiente (`listPending`, `approve`, `reject`, `ApproveUserModal`, pestaña de pendientes, rol de sistema `guest` y sus tests) se retiran en el 2.2**, porque la ruta pública ya no existe y nada crea registros pendientes. Eso implica también quitar `GET /users/pending`, `POST /users/{id}/approve`, `DELETE /users/{id}/reject` y `ApprovalsPendingService` para `user_approval`.
- El 2.2 se implementa **solo después** de mezclar el 2.1 y verificarlo en `dev`, y con la confirmación explícita de Luis para el `DROP TABLE` (que no se da por incluida en esta decisión).

## 10. Resultado del paso 2.2 (2026-10-25) — confirmado por Luis, incluido el `DROP TABLE`

Ramas `fase2/22-drop-user-roles` (backend y frontend).

**Hecho**
- Migración `2026_10_25_100000_drop_user_roles_table_and_guest_role`: ABORTA si alguien con rol legado `superadmin`/`management` no tiene `role_id` (perdería el acceso);
  los pendientes (`guest`) quedan con `role_id = NULL` e inactivos (no se borra a nadie); elimina el rol `guest` y la tabla `user_roles` (su trigger de auditoría cae con ella).
  `down()` recrea tabla (PK compuesta, `CHECK`, trigger `trg_audit_user_roles`) y rol `guest`, y repuebla `user_roles` desde `role_id` para `superadmin`/`management`.
  **No puede restaurar** quién estaba pendiente ni los roles legados sin equivalente (`designer`/`supervisor`/`electrician`).
- Backend: se eliminan `UserRole`, `User::ROLE_*`, `User::isGuest()`, `Role::KEY_GUEST`, `UserService::register/listPending/approve/reject`, `UserController::pending/approve/reject`,
  las rutas `GET /users/pending`, `POST /users/{id}/approve`, `DELETE /users/{id}/reject`, el bloqueo de login por invitado, el bloqueo de impersonación por invitado y el ítem
  `user_approval` de las aprobaciones pendientes. Seeders con `role_key` (sin el campo legado `roles`).
- Frontend: se eliminan `ApproveUserModal`, la pestaña «Pendientes», `usePendingUsers/useApproveUser/useRejectUser`, `usersApi.listPending/approve/reject`, el bloqueo «invitado» de
  la impersonación y las claves i18n correspondientes.

**Hallazgo corregido de paso (seguridad)**: la política «la contraseña inicial (= correo, ver `WelcomeMail`) obliga a cambiarla en el primer ingreso y no caduca» (SCRUM-1034 H7)
solo estaba en `approve()`. `create()` dejaba `must_change_password = false`, o sea que un usuario creado por un administrador conservaba su contraseña inicial (su correo) indefinidamente.
Al retirar `approve()` la política pasa a `create()` (`must_change_password = true`, sin caducidad); `ApprovalTemporaryPasswordTest` se reescribe como `CreatedUserTemporaryPasswordTest`.

**Verificación**: PHPStan 0 errores; tests de la migración (borrado, salvaguarda, `down()`) en `Fase2RetiroUserRolesTest`; Auth+Users+CRM+Gerencia+Integrations 1161 tests; frontend `tsc` limpio
y 247 archivos / 3110 tests; en vivo contra el stack local: tabla borrada, 14 roles, Usuarios sin pestaña «Pendientes», `GET /api/users/pending` → 404.

**Quedan** menciones históricas en migraciones antiguas y comentarios (inmutables) y los `User::ROLE_*` ya no existen en código.
