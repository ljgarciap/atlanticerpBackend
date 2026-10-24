# Senior Review — Reset administrativo de contraseñas (individual y masivo)

Fecha: 2026-10-01. Revisor: Senior Reviewer (independiente). Sin ticket Jira (spec R1-R5 aprobada por Luis).
Backend: rama `feat-reset-password-be` (3 commits sobre `897045a`). Frontend: rama `feat-reset-password-fe` (1 commit, `c1962ef`).

## Veredicto

- **Backend: Approved with suggestions.** Sin CRITICO ni ALTO. 1 MEDIO que conviene decidir antes de QA (gate `role:superadmin` vs permiso `superadmin.all` que usa el frontend), el resto BAJO.
- **Frontend: Approved with suggestions.** Sin CRITICO ni ALTO. Solo BAJOS.
- Integracion backend-frontend: contrato coincide (ver abajo). El MEDIO M1 es la unica grieta entre ambos lados.

## Hallazgos

### MEDIO

**M1. El gate de la API es `role:superadmin` (claim `role` del JWT = solo el rol base) pero el frontend muestra el boton con `superadmin.all`.**
- Backend: `routes/users.php:11` (`role:superadmin`); `app/Shared/Http/Middleware/EnforceRole.php` compara solo `$payload['role']`; `JwtClaimsBuilder.php:36` pone en `role` unicamente el rol base (`role->key`).
- `User::effectivePermissionsV2()` (`app/Models/User.php`, ~l.283-288) devuelve `['superadmin.all']` si CUALQUIERA de los roles (base o `user_additional_roles`) tiene `is_superadmin`.
- Frontend: `src/pages/security/UsersPage.tsx:26` (`actorHasSuperadmin = permissions.includes('superadmin.all')`) y `UserTable.tsx` muestran las acciones.
- Consecuencia: un superadmin cuyo rol base no es `superadmin` (superadmin via rol adicional, o nivel 10 sin rol base superadmin) ve el boton y el modal, y recibe 403 al confirmar. Falla cerrado (no hay escalada), pero la UX es inconsistente y el modal masivo ya cargo el flujo.
- Recomendacion: usar `permission:superadmin.all` en la ruta (es el patron que usa `/anomalies/{id}/confirm`), o bien alinear el frontend al claim `role`. No pude verificar con datos reales si existe hoy algun usuario en esa situacion; ningun test cubre multirol.

### BAJO

**B1. `Auth/me` hace una query extra** (`AuthController.php:~369`, `User::where(...)->value('must_change_password')`) aunque `AuthenticateJwt` ya leyo la fila. Es 1 query por PK y solo en /me; se podria guardar el valor en `$request->attributes`. No bloquea.

**B2. El flujo "olvide mi contrasena" no limpia `must_change_password`** (`PasswordResetService.php:69`, sin modificar, verificado con diff). Un usuario reseteado que usa el enlace de correo fija su clave pero sigue obligado a cambiarla (la pantalla le pide la clave actual, que acaba de definir). Es seguro pero molesto; sugerido limpiar el flag ahi tambien (el usuario ya eligio contrasena propia por un canal verificado).

**B3. Usuarios nuevos siguen naciendo con contrasena = correo SIN `must_change_password`** (`UserService.php:123` y `:333`). Fuera de la spec R1-R5; solo lo dejo anotado porque es el mismo riesgo que el runbook describe para el reset.

**B4. Reset masivo no atomico.** `AdminPasswordResetService::resetMany` hace una transaccion por usuario. Un fallo a la mitad deja un subconjunto reseteado y sin el resumen `auth.user.password_reset_bulk` (si la excepcion sube). Los eventos individuales si quedan. Aceptable para el volumen (~32 usuarios), documentarlo en el runbook.

**B5. Confirmacion del comando atada solo al conteo N, no al conjunto** (a diferencia de `lafayette:aislar-catalogo`, que usa huella del plan). Entre la simulacion y el `--apply` el conjunto puede cambiar con el mismo N. Riesgo bajo; el comando imprime la lista antes de pedir confirmacion.

**B6. Auditoria por CLI:** `user_id` queda nulo y `ip_address`/`http_method`/`http_path` salen del request sintetico de artisan (ruido). El actor real solo esta en `metadata.operador`, texto libre no verificable. Cumple la spec; se recomienda dejarlo explicito en el runbook.

**B7. Sin tests para:** throttle 429, superadmin multirol (M1), bordes de la allowlist del middleware (barra final, query string, `HEAD`), y que `PUT /api/auth/password` con `must_change_password` real funcione con token emitido por login (si lo cubre: `test_con_cambio_obligatorio_me_y_cambiar_contrasena_pasan`, usa token previo al reset). Razonado a mano, sin bypass: la comparacion usa `$request->path()` ya normalizado (sin query ni barra final) y `isMethod`; `_method=PUT` por POST apunta a la misma ruta permitida; `HEAD` queda bloqueado (mas estricto, correcto).

**B8. Throttle `throttle:6,1`** se clavea por IP (el JWT no es `auth()->user()`): superadmins detras de la misma IP comparten cupo, y el flujo masivo gasta 2 peticiones (dry_run + apply) mas el recalculo del paso 2 (3). Suficiente, pero con 429 faltaria margen si se repite 2 veces.

**B9. Reset individual a uno mismo permitido** (la UI muestra el boton en la propia fila, sin aviso). El masivo lo evita por defecto con `include_self=false`. Sugerido ocultar o avisar en la fila propia.

**F1 (frontend). Pluralizacion:** `bulkResetPassword.resultCount` produce "1 contraseñas" / "1 passwords were reset" (`es/en security.json:208`). Mismo backlog de pluralizacion ya conocido en CLAUDE.md.

**F2 (frontend). Carrera teorica de refresh silencioso** tras `PUT /auth/password`: si el access token esta a menos de 3 min de expirar, `handleSilentRefresh` (`authApi.ts`, `silentTokenRefresh.ts`) intenta refrescar con un refresh token ya revocado y dispara `clearAuth + sessionExpired` en paralelo al relogin de `ForcePasswordChangePage`. Probabilidad baja, preexistente en el flujo de cambio de contrasena; podria excluirse `/auth/password` de la senal de actividad.

**F3 (frontend). Con MFA activo** el relogin automatico se omite y se sigue con el access token viejo, cuyo refresh token esta revocado; la sesion terminara en "sesion expirada" al vencer el access token. Esta documentado en el comentario del componente y es un fallback razonable.

## Lo que verifique y esta bien

- Servicio `AdminPasswordResetService`: contrasena = correo (hasheada por el cast), flag true, revoca solo los refresh tokens del usuario, rechaza inactivos, no toca `is_active` ni `security_blocked_at`; auditoria sin password/hash (`User::auditableAttributes` excluye `password_hash`; el test recorre old/new/metadata buscando hash, `$2y$`, la clave y `password_hash`).
- Comando: simulacion no escribe; `--apply` sin `--operador` falla antes de tocar el tenant; `--yes` solo en local/testing; confirmacion `RESETEAR <ENTORNO> <N>` y `PRODUCCION`; correo inexistente/inactivo falla; exactamente una de `--email`/`--all-active`; `--except` solo con masivo; stdin sin respuesta => `ask()` nulo => no aplica. Mismo mecanismo `ask()` que `lafayette:aislar-catalogo`.
- API: rutas `POST /reset-passwords` y `POST /{id}/reset-password` (con `whereNumber`) declaradas antes del grupo `/{id}`, sin colision; dry_run no escribe; `confirm` se compara con `!==` contra `RESETEAR <N>` calculado en el servidor (distingue mayusculas); `include_self=false` por defecto; `except_ids` validado entero >=1; 401 sin token, 403 otros roles, 404, 422 inactivo.
- Middleware `AuthenticateJwt`: una sola query (`first(['is_active','must_change_password'])`) en lugar de `value('is_active')`; fila nula/inactiva sigue dando 401 (SCRUM-823 intacto, hay test); allowlist exacta `GET api/auth/me` y `PUT api/auth/password`; logout/refresh son publicos y no pasan por el middleware.
- `changePassword`: nueva != correo (insensible a mayusculas) y != actual, limpia el flag, revoca refresh tokens; el chequeo de clave actual va primero.
- Migracion `2026_10_02_100000_add_must_change_password_to_auth_users`: schema por `TenantSchemaMigration` (`{tenant}_auth.users`), `boolean default false` (ADD COLUMN con default constante = solo metadatos en PG >= 11, sin reescritura), `down()` reversible, timestamp unico (no hay otra migracion `2026_10_02_*` en `origin/dev`). Sin `hasColumn` como el resto de las 373 migraciones tenant (ninguna lo usa), idempotencia via tabla de migraciones.
- `PasswordResetService.php` NO esta modificado respecto a `origin/dev` (`git diff` vacio). El diff contra `origin/dev` muestra ademas borrados de `SharedFamiliesSeeder*` solo porque `origin/dev` avanzo 2 commits (SCRUM-1010) despues de la base; contra el merge-base el diff son exactamente 17 archivos del alcance.
- Seeders sin cambios (create-only). Commits en español con formato `tipo(scope)`. Sin umbrales de negocio hardcodeados. Iconos: solo `IcoClose` del set propio; sin emojis en es/en.
- Frontend: `RequireAuth` solo renderiza `ForcePasswordChangePage` en lugar de toda la app (sin navegacion, sin redireccion: no hay bucle); el interceptor 403 solo marca el flag de forma idempotente; logout funciona (`authApi.logout` traga errores y luego `clearAuth`); confirmacion exacta en el modal masivo, estados de carga/error 403/429/422 `confirmation_mismatch`, N tomado del backend tras recalcular con las exclusiones; `role="dialog"`, `aria-modal`, `aria-labelledby`, foco inicial, Escape deshabilitado mientras hay peticion; i18n es/en con paridad exacta de claves (script).
- Contrato BE-FE: `must_change_password` en `login`/`me`, `dry_run` => `{data:{count,users:[{id,email,name}]}}`, apply => `{data:{reset_count}}`, individual => `{data:{id,email,must_change_password}}`, `code: confirmation_mismatch`, `code: password_change_required`. Coinciden con `usersApi.ts`, `authApi.ts` y los tipos.

## Comandos ejecutados y resultados

- `git -C be-pw log/diff origin/dev` y `git diff 897045a HEAD --stat`: 17 archivos, 3 commits.
- BD y Redis desechables propios (`atlanticerp_srpw`, `redis-srpw` puerto 6395; ambos eliminados al terminar): `phpunit tests/Feature/Auth` => **OK (347 tests, 1288 assertions)**.
- `php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G --no-progress` => **[OK] No errors**.
- Frontend `npx tsc --noEmit` => sin errores. `npx vitest run src/components/security src/pages src/api src/store src/components/RequireAuth.test.tsx` => **96 archivos, 1223 tests, todos pasan** (cubre los 3 modales/pagina nuevos, RequireAuth, authApi, authStore, UsersPage, UserTable).
- Script de paridad de claves i18n es/en y busqueda de emojis en `auth.json`/`security.json` => sin diferencias ni emojis.

## No verificado / fuera de alcance de esta revision

- No se ejecuto la suite completa de backend ni de frontend (solo `tests/Feature/Auth` y el subconjunto de vitest indicado); el gate real previo a push sigue pendiente.
- No se probo contra una BD con el catalogo real ni se probo en navegador (Playwright); no corresponde a esta revision sin permiso de levantar el stack.
- M1 no se comprobo con datos reales (no hay lectura de BD de dev/test/prod).
- El comando no se ejecuto contra un `.env` real ni por SSH.
