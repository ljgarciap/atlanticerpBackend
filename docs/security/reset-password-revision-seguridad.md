# Revisión de seguridad — Reset administrativo de contraseñas (individual y masivo)

Fecha: 2026-10-01. Rol: Cybersecurity. Alcance: ramas `feat-reset-password-be` (3 commits sobre una base anterior a `origin/dev`)
y `feat-reset-password-fe`. Método: lectura de código + 5 tests descartables corridos en BD (`atlanticerp_cspw`) y Redis
(`redis-cspw`) desechables, ya borrados junto con el archivo de test. Nada se ejecutó contra dev/test/prod ni contra el checkout principal.

Diseño aprobado por Luis (no se discute): contraseña = correo en los 3 entornos incluida producción, masivo por defecto a todos
los activos, `must_change_password` con 403 `password_change_required`, API solo superadmin con throttle 6/min, comando con
simulación por defecto y confirmación escrita, auditoría sin contraseñas. Lo que sigue evalúa el riesgo y propone mitigaciones
compatibles con ese diseño.

---

## 1. Modelo de amenazas (breve)

| Activo | Qué se protege |
|---|---|
| Cuentas de ~32 personas reales (superadmins, Admin&Cont, Bodega, Gerencia) | confidencialidad e integridad: quien entra actúa con los permisos de la víctima (incluido dinero: comisiones, arqueos, FE) |
| `audit_logs` | evidencia forense; la atribución al usuario real es lo que sirve contra fraude (Epic SCRUM-788) |

| Actor | Capacidad realista |
|---|---|
| A1 Insider (cualquier empleado con cuenta) | conoce los correos de todos (patrón corporativo, firma de correos, Jira). Es el atacante más probable y más barato |
| A2 Externo | enumera correos por OSINT/filtraciones; no tiene cuenta |
| A3 Poseedor de un token robado (access o refresh) | sesión previa de una víctima o de un superadmin |
| A4 Operador con shell | acceso ssh al EC2; ya puede hacer cualquier cosa sobre la BD (aceptado) |

Cadenas de ataque que el reset habilita o agrava: (T1) toma de cuenta en la ventana reset -> primer ingreso; (T2) persistencia de
una sesión previa a través del reset; (T3) uso del reset como herramienta de toma de cuentas por quien tenga un token de superadmin;
(T4) borrado de rastro/atribución; (T5) bloqueo del legítimo (DoS de cuenta).

---

## 2. Resumen de hallazgos

| # | Sev. | Hallazgo | Bloquea |
|---|---|---|---|
| H1 | ALTO | Los access tokens emitidos ANTES del reset siguen válidos y pueden fijar la contraseña (y recuperar acceso completo) | MERGE |
| H2 | CRÍTICO | Ventana de toma de cuenta sin ningún control compensatorio (sin vencimiento, sin prueba de posesión del correo, sin aviso, sin throttle) | USO EN PRODUCCIÓN (merge permitido) |
| H3 | ALTO | Un token de superadmin robado/degradado puede resetear a todos: el rol del JWT no se revalida y no hay re-autenticación para una operación masiva | recomendado antes de prod |
| H4 | MEDIO | `/auth/login`, `/auth/refresh`, `/auth/mfa/verify`, `/auth/mfa/resend`, `/auth/forgot-password` sin throttle; el MFA se puede forzar por fuerza bruta | recomendado antes de prod (bloquea que MFA sea mitigación válida de H2) |
| H5 | MEDIO | El masivo por defecto alcanza a todos los superadmins a la vez | recomendado antes de prod |
| H6 | MEDIO | Auditoría: el cambio de contraseña (el evento de la toma) no tiene evento propio; sin aviso al afectado; CLI sin atribución real | posterior |
| H7 | MEDIO | Altas por UI/registro/aprobación ya nacen con contraseña = correo y SIN `must_change_password` (preexistente) | posterior |
| H8 | BAJO | El throttle `6,1` es por IP, se comparte con otras rutas y corre antes de autenticar; no limita lo que importa | posterior |
| H9 | BAJO | Masivo no atómico; la confirmación solo liga el N, no el conjunto | posterior |
| H10 | BAJO | Flujo "olvidé mi contraseña": no limpia el flag, no invalida tokens, permite correo como clave | posterior |
| H11 | BAJO | Frontend: el guard es solo UX (correcto, el backend manda); tokens en localStorage (preexistente) | posterior |

---

## 3. Hallazgos en detalle

### H1 — ALTO — Tokens previos al reset siguen vivos y toman la cuenta (T2/T5) — BLOQUEANTE de merge

Escenario: Ana tiene una sesión abierta (o su access token fue robado, o la sesión quedó en un equipo compartido). El admin resetea
su cuenta precisamente para cortar ese acceso. El poseedor del token viejo llama `PUT /api/auth/password` con
`current_password = <correo de Ana>` (público) y fija su propia contraseña: pasa por la excepción del middleware, y Ana queda
bloqueada. Peor: cuando Ana (o el atacante) cambia la contraseña, el flag baja a `false` y el token viejo vuelve a tener acceso
completo hasta que expire (`session_timeout_min`, ~600 min).

Evidencia:
- `app/Shared/Http/Middleware/AuthenticateJwt.php:63-80` solo compara `is_active` y `must_change_password` contra la BD; no compara
  `iat` del token con ningún campo de cambio de credencial.
- `app/Shared/Auth/JwtService.php` (`encode`) agrega `iat`, pero nada lo consume.
- `AuthController.php:569-574` (`changePassword`) y `AdminPasswordResetService.php:74` revocan solo refresh tokens, no access tokens.
- CONFIRMADO con test descartable: token de `designer` emitido antes del reset -> reset por superadmin -> `PUT /api/auth/password`
  con el correo como contraseña = 204 -> login legítimo con el correo = 401 -> el token viejo sigue dando 200 en
  `GET /api/auth/mfa/status`. El propio `MustChangePasswordTest::tokenAntesDelReset()` ya ejercita ese camino como comportamiento
  esperado (líneas 31-41 y `test_cambiar_la_contrasena_limpia_el_indicador_y_libera_las_rutas`).

Mitigación mínima (no implementada): columna `password_reset_at TIMESTAMPTZ NULL` en `auth.users`, seteada solo por
`AdminPasswordResetService::resetUser()`. En `AuthenticateJwt` (ya se hace una query por PK; agregar la columna a
`first([...])`) responder 401 si `payload.iat < password_reset_at->timestamp`. Los tokens emitidos tras el reset (login con el
correo) tienen `iat` posterior, así que el flujo normal no cambia; el cambio de contraseña propio tampoco cambia (no hay que reemitir
tokens en Ajustes). Alternativa más general (invalida también en cambio y en "olvidé mi contraseña"): `password_changed_at`, pero
exige reemitir token tras cambiar la contraseña en todos los flujos; el frontend de la pantalla obligatoria ya hace relogin.
Cuidar la granularidad de `iat` (segundos): comparar con `<` y registrar el timestamp truncado.
Validación: test con token previo -> 401 en `/auth/me`, `/auth/password` y rutas normales; login tras el reset -> 200.

### H2 — CRÍTICO — Ventana de toma de cuenta sin control compensatorio (T1/T5) — BLOQUEANTE para usar en producción

Escenario concreto: tras un masivo en producción a las 18:00, cualquier empleado (A1) o externo con la lista de correos (A2) hace
`POST /api/auth/login {email, password: email}` contra cualquier compañero. Entra al instante, abre `PUT /api/auth/password` y
fija su contraseña (o simplemente usa la cuenta hasta que el dueño ingrese; el 403 solo bloquea las rutas, no el cambio de contraseña).
Cuentas de personas de vacaciones o que no ingresan en días quedan abiertas indefinidamente. Con ~30 cuentas reseteadas
a la vez, el atacante elige la de mayor valor (Admin&Cont, Gerencia, un superadmin). Para el atacante es UN intento por cuenta: ningún
throttle por intentos lo detiene. El dueño legítimo, al fallar su login, ni siquiera sabe qué pasó (no hay aviso).

Evidencia:
- No hay vencimiento: `AdminPasswordResetService.php:67-80` solo setea hash + flag; el login (`AuthController.php:104-111`) acepta
  la contraseña = correo sin límite de tiempo.
- No hay prueba de posesión del correo: el login con `mfa_enabled = false` y `mfa_mode != required` emite JWT directo
  (`MfaService.php:22-31`).
- No hay aviso al usuario (ningún `Mail::` en `AdminPasswordResetService`).
- `docs/runbooks/reset-password.md` (sección "Riesgo en producción") reconoce la ventana pero solo propone mitigación procedimental.
- La mitigación ya existente de MFA solo protege a quien tenga `mfa_enabled` (o si producción tuviera `mfa_mode = required`;
  NO pude verificarlo, ver sección 6).

Mitigaciones compatibles con "contraseña = correo" (de más a menos efectiva):
1. Prueba de posesión del correo en el primer ingreso: tratar `must_change_password = true` como "MFA requerido" en
   `MfaService::isRequired()`, de modo que el login con la contraseña temporal envíe un código de 6 dígitos al correo y solo el dueño
   del buzón avance. Reutiliza `generateChallenge()`/`verify()`. Requiere cerrar H4 (throttle de `/mfa/verify` y `/mfa/resend`) o
   la mitigación es forzable. Si el correo falla ya hay manejo (503 `mfa_delivery_failed`).
2. Vencimiento de la contraseña temporal: `password_reset_expires_at` (p. ej. 24-72 h). Pasado el plazo, el login con la contraseña
   temporal responde 401 y la persona usa "olvidé mi contraseña" (que prueba posesión del correo) o un nuevo reset puntual.
3. Aviso por correo al afectado en cada reset y en cada cambio de contraseña ("tu contraseña fue restablecida por X; si no esperabas
   esto avisa") y alerta interna cuando una cuenta con `must_change_password = true` cambia contraseña desde una IP/UA distintos a los
   del login previo.
4. Operación (ver sección 8): reset individual justo antes de que la persona ingrese, nunca masivo sin 1 o 2; ventana corta y verificada.
5. Interruptor de seguridad: bandera de configuración (p. ej. setting global) para habilitar el reset en producción, apagada por
   defecto, que el superadmin enciende para la ventana y apaga después. No existe hoy.

Recomendación de Cybersecurity: 1 + 3 como mínimo antes de ejecutar el masivo en producción; 2 si 1 no se acepta.

### H3 — ALTO — Token de superadmin robado o rol degradado puede resetear a todos (T3/T4) — recomendado antes de prod

Escenario: A3 con un access token de superadmin (robado de localStorage vía XSS, o de un superadmin degradado/desactivado a
medias) llama `POST /api/users/reset-passwords` con `dry_run:false` y el `confirm` calculado en el mismo flujo: todas las cuentas
quedan con contraseña = correo y el atacante inicia sesión como cada víctima (sin MFA donde no exista). Resultado: persistencia que
sobrevive a la expiración del token y a la revocación del token original, y atribución lavada: los eventos posteriores quedan a nombre
de las víctimas, no del superadmin (el reset sí queda con el `user_id` del superadmin, pero el daño posterior no).

Evidencia:
- `EnforceRole.php:19-27` decide por el claim `role` del JWT; `JwtClaimsBuilder.php:36` lo fija al emitir. El único dato que
  `AuthenticateJwt` revalida es `is_active` (SCRUM-823). Un rol retirado no se refleja hasta que el token expire.
- CONFIRMADO con test descartable: superadmin al que se le borra el rol y `role_id` sigue recibiendo 200 en
  `POST /api/users/{id}/reset-password` con su token previo.
- `confirm = RESETEAR <N>` (`AdminPasswordResetController.php:62`) se obtiene del propio `dry_run` (misma sesión): no es un segundo
  factor, solo previene un clic accidental.
- Nota sobre el gate: `role:superadmin` evalúa SOLO el rol base del JWT (`role->key`, o `roles()[0]` de legado, sin orden definido);
  un usuario con `superadmin.all` por nivel o con superadmin solo como rol adicional recibe 403 (falla cerrado: correcto).

Mitigación: en `AdminPasswordResetController`, revalidar contra BD que el actor sigue siendo superadmin (rol base con
`is_superadmin`) y que `is_active` y no `security_blocked_at`; exigir re-autenticación (`operator_password` en el body, verificado
con `Hash::check`) para `dry_run:false` del masivo y, idealmente, del individual; limitar el masivo a un limitador nombrado por usuario
y bajo (p. ej. 3/hora). Para el masivo en producción, que quede registrado en `audit_logs` el `ip_address` y exigir que el actor tenga MFA activo.

### H4 — MEDIO — Sin throttle en login/refresh/MFA; MFA forzable — recomendado antes de prod

Escenario: (a) con el masivo, el atacante no necesita adivinar (ver H2), pero el mismo hueco permite credential stuffing sin freno
sobre el resto de cuentas; (b) si se adopta MFA como mitigación de H2, el código de 6 dígitos tiene 5 intentos por challenge, pero
`POST /api/auth/mfa/resend` solo pide el `mfa_challenge_id`, invalida el anterior y emite otro, sin límite: 10^6/5 = 200.000
challenges promedio -> forzable (y manda 200.000 correos a la víctima, ruido que sí alerta).

Evidencia: `routes/auth.php:9-21` y `bootstrap/app.php:26-31` (grupo `api` sin `throttleApi()`; `grep` de `throttle` en `app/`, `routes/`,
`bootstrap/`, `config/` solo encuentra rutas ajenas al login); `MfaCode.php:57-60` (`attempts >= 5`); `MfaService.php:103-115` (`resend`).
CONFIRMADO con test descartable: 40 logins fallidos consecutivos -> 40 x 401, ninguno 429, y el login correcto posterior = 200 (sin bloqueo
de cuenta). Defensa existente parcial: CrowdSec en el host (en prod en modo observe, escenarios de bruteforce no verificados) y el
análisis diario `SecurityAnalysisJob` (detecta después, no previene).

Mitigación: limitadores nombrados (patrón ya usado en `AppServiceProvider.php:132-143`) por IP+correo para login (p. ej. 10/5 min) y por
`mfa_challenge_id`/IP para verify/resend (resend máx. 3 por challenge raíz y cooldown); tras N fallos por correo, backoff por cuenta.
Todo con respuesta indistinguible para no enumerar.

### H5 — MEDIO — El masivo incluye por defecto a todos los superadmins

`resolveTargets()` (`AdminPasswordResetService.php:35-52`) toma todo `is_active = true` salvo exclusiones y, por defecto, al operador
(`AdminPasswordResetController.php:48`, `include_self=false`). Quedan todos los demás superadmins (y cuentas de servicio, si existen)
con contraseña = correo; son las cuentas de mayor valor (pueden asignar permisos, desbloquear usuarios, borrar demos). No hay garantía
de que quede alguna cuenta administrativa intacta ni de que las que se resetean tengan MFA.
Mitigación: excluir por defecto a rol superadmin (y `manage_users`) del masivo, con opt-in explícito (`include_superadmins`), reseteándolos
de a uno y verificando MFA; avisar en la UI (modal) cuántos superadmins/`manage_users` incluye el conjunto calculado.

### H6 — MEDIO — Auditoría y detección

Lo que está bien (verificado): ni `audit_logs` ni `db_audit_log` guardan contraseñas/hashes del reset.
`User::auditableAttributes()` (`User.php:150-163`) excluye `password_hash`; el trigger de BD excluye `password_hash`
(`2026_09_14_100002_fix_db_audit_log_exclude_password_hash.php`). El `$user->save()` del reset genera un evento automático
`auth.user.updated` cuyos `old_values/new_values` solo llevan `must_change_password` (sin hash). El evento propio
`auth.user.password_reset` lleva solo `operador/origen/modo` (`AdminPasswordResetService.php:76-81`); el resumen `..._bulk` lleva
`count` y filtros, sin correos ni hashes. Contraseña temporal = correo: no hay secreto nuevo que filtrar. La salida del comando
imprime solo correos y cantidades. La inmutabilidad (REQ-634) es a nivel de rol de BD (`GrantAppRoleCommand.php:50,79`,
`REVOKE UPDATE, DELETE` a `atlanticerp_app`): protege solo si el runtime de la app y el comando CLI corren con `atlanticerp_app`;
NO lo pude verificar (ver sección 6).

Lo que falta:
1. El evento de la toma de cuenta (`PUT /api/auth/password`, `AuthController.php:569-574`) no emite evento propio; solo queda el
   `auth.user.updated` automático (cambio de flag true -> false). `AuditService::AUTH_PASSWORD_RESET` existe como constante pero el reset
   usa un string literal distinto (`auth.user.password_reset`): agregar constantes y un `auth.password_changed` con IP/UA, y que
   `SecurityAnalysisJob` trate "cambio de contraseña con flag activo desde IP distinta" y "login con contraseña temporal" como señal.
2. Sin aviso por correo al afectado (ver H2.3).
3. CLI: `--operador` es texto libre (`UsersResetPasswordCommand.php:127`) y `user_id` queda nulo (consola, sin JWT): cualquiera con shell
   puede firmar como otra persona. Aceptable en términos de amenaza (A4 ya controla la BD), pero la evidencia queda débil: registrar en
   `metadata` el usuario del SO (`posix_getpwuid`/`SUDO_USER`/`SSH_CONNECTION`), host y `APP_ENV`, y exigir que `--operador` coincida con un
   superadmin existente (por correo) para que la auditoría referencie un `user_id` real.
4. `AuditService::log()` traga excepciones (`AuditService.php:~175-190`): el reset podría completarse sin evento si el INSERT falla. Dentro de
   `DB::transaction` en Postgres un INSERT fallido aborta la transacción, así que probablemente falla cerrado, pero no lo verifiqué.

### H7 — MEDIO (recomendación, preexistente) — Altas nuevas con contraseña = correo y sin flag

`UserService.php:123` (`create`) y `:333` (`register`) fijan `password_hash = email` sin `must_change_password`; `approve()` activa la cuenta
sin tocarlo; `CoreUserSeeder` solo crea. Es el mismo riesgo de H2 pero permanente: cada usuario nuevo queda con contraseña pública hasta
que ingresa por primera vez, sin forzar el cambio. No es parte de la spec; se reporta como recomendación: setear
`must_change_password = true` en `create()` y en `approve()` (y para cuentas ya existentes que todavía tengan hash de su correo, una
verificación puntual `Hash::check($email, password_hash)` en un comando de solo lectura para medir cuántas son).

### H8 — BAJO — Throttle: por IP, compartido y previo a la autenticación

`throttle:6,1` usa la firma `dominio|IP` (`vendor/laravel/framework/.../ThrottleRequests.php:222-228`), porque el guard JWT propio no define
`$request->user()`; el comentario de `AppServiceProvider.php:132-135` ya documenta ese comportamiento y el motivo del limitador por
usuario `fe-accion`. Consecuencias: (a) la clave no lleva prefijo de ruta, así que todas las rutas con `throttle:N,M` por defecto
(`ai` 5/min, links públicos 30/min, FE 6/min) comparten el contador por IP; (b) el límite corre antes de `AuthenticateJwt`: peticiones sin
sesión desde la misma IP (NAT de la oficina) consumen el cupo del superadmin; (c) un solo POST masivo ya resetea a todos, así que 6/min
no limita el daño real. No lo evaden con X-Forwarded-For porque no hay `trustProxies` configurado (`grep`), pero no verifiqué la IP que ve
PHP detrás de nginx en los entornos reales.
Mitigación: limitador nombrado por `sub` del JWT (patrón `fe-accion`) con límite bajo y distinto para individual y masivo.

### H9 — BAJO — Atomicidad y confirmación ligada solo al N

- `resetMany()` (`AdminPasswordResetService.php:91-106`) hace una transacción por usuario; una excepción a mitad deja un subconjunto reseteado
  y sin evento resumen. Con decenas de usuarios es viable envolver todo en una transacción o registrar el resumen en `finally` con
  `reset_count`/`failed`.
- `resetUser()` revisa `is_active` sobre el modelo ya cargado (`:62`); un usuario desactivado entre la resolución y el bucle igual se resetea.
- API: el N comparado y el conjunto aplicado son la misma colección (`AdminPasswordResetController.php:48,62,69`): no hay TOCTOU entre
  confirmación y aplicación. Sí lo hay entre `dry_run` y apply (el operador ve una lista, el servidor recalcula; si cambió el conjunto con el
  mismo N, aplica a otros). Mitigación opcional: ligar la frase a un hash de ids (`RESETEAR 31 a1b2c3`) o mandar `expected_ids` y comparar.
- Verificado OK: comparación estricta `trim($confirm) !== 'RESETEAR N'` (case-sensitive), `confirm` máx. 100; el N lo calcula el servidor; en CLI
  `--yes` solo en `local`/`testing` (`UsersResetPasswordCommand.php:66-70`; un `APP_ENV=local` mal puesto en un servidor lo habilitaría:
  comprobar `APP_ENV` en los 3 servidores) y `--operador` obligatorio con `--apply` (`:72`).

### H10 — BAJO — "Olvidé mi contraseña"

`PasswordResetService::reset()` (`PasswordResetService.php:69-78`): no limpia `must_change_password` (CONFIRMADO: queda `true`; el usuario debe
cambiarla otra vez), no prohíbe la contraseña igual al correo, no invalida access tokens. La regla "nueva != correo y != actual" vive solo en
`changePassword` (`AuthController.php:562-567`). Mitigación: centralizar la regla de contraseña en un servicio usado por ambos flujos y limpiar
el flag al completar el reset por correo (el dueño demostró posesión del buzón).

### H11 — BAJO — Frontend

- Guard sin evasión real: `RequireAuth.tsx:12-13` muestra `ForcePasswordChangePage` si `user.must_change_password === true`, dato que viene
  del login y de `localStorage` (`authStore.ts:134-135`, clave `atlanticerp-auth-v3`, persiste `user` y `refreshToken`). Quien edite el flag a `false` solo
  ve el shell vacío: cada llamada recibe 403 `password_change_required` y el interceptor (`authApi.ts`, bloque nuevo de `status === 403`) vuelve a
  marcarlo. El backend es la autoridad (`AuthenticateJwt.php:74-79`). Límite no verificado: llamadas fuera de la instancia axios `api` (si las hubiera)
  no disparan el interceptor.
- Los tokens: el refresh token persiste en localStorage (preexistente); un XSS lo roba 30 días. Tras el reset queda revocado.
- Contraseñas: `ForcePasswordChangePage` mantiene los valores solo en el estado de react-hook-form y los pasa a `authApi.changePassword`/`login`;
  no hay `console.*` ni escrituras a storage en los archivos nuevos (único `localStorage` es `removeItem('atlanticerp_theme')`). El modal masivo solo
  maneja la frase de confirmación. Tras cambiar la contraseña se hace relogin (el refresh anterior quedó revocado); si hay MFA cae al token de acceso
  actual hasta que expire (limitación funcional, no de seguridad).
- XSS: nombres y correos (el nombre llega por registro público, texto libre) se interpolan con i18n con `escapeValue: false`
  (`src/i18n/index.ts:46`), seguro solo porque los modales los renderizan como texto de React; en los archivos nuevos no hay `Trans` con HTML ni
  `dangerouslySetInnerHTML`. Mantener esa disciplina; no verifiqué el componente `Toaster` (recibe `t(...{name})`).
- CSRF: no aplica (JWT por header).
- El reset individual se confirma con un clic (sin frase escrita): coherente con la spec; con H3 sería el lugar para pedir la contraseña del operador.

---

## 4. Respuestas puntuales a los puntos pedidos

1. Ventana de toma de cuenta: ver H1 y H2. Sí puede entrar y fijar su contraseña (`PUT /api/auth/password` con `current_password = correo`);
   un token previo al reset sigue válido; el middleware NO invalida tokens previos.
2. MFA: el reset NO toca `mfa_enabled` (ningún acceso en `AdminPasswordResetService`). Un usuario con MFA activo sigue protegido en la ventana (el
   login con el correo devuelve `requires_mfa` y el código va al buzón del dueño), salvo por la fuerza bruta de H4. Correcto no tocar MFA: resetearlo
   quitaría la protección. `POST /auth/mfa/disable` solo exige JWT (`MfaController.php:170`); dentro de la ventana está bloqueado por el 403, pero tras una
   toma el atacante puede apagarlo (preexistente: pedir contraseña/código).
3. Autorización: `role:superadmin` por rol base del JWT (ver H3), fallo cerrado para multirol adicional o `superadmin.all` sin rol. No hay escalada de rutas:
   las dos rutas están antes de cualquier `/{id}` y `whereNumber('id')` (`routes/users.php:11-14`); el POST masivo no choca con ninguna ruta `/{id}` POST; los
   tests de la rama cubren 401/403 para management y designer. Superadmin puede resetear a otro superadmin (H5) y a sí mismo (individual) sin guardia. Enumeración:
   404/422 solo visibles para superadmin (aceptable). El login responde 401 uniforme.
4. Fuerza bruta: H4 y H8.
5. Confirmación y operador: H9 y H6.3.
6. Auditoría y fuga: H6. Logs de Laravel: no hay `Log::` con contraseñas en los archivos nuevos (grep); no pude revisar los logs reales.
7. Revocación: refresh tokens sí (`AdminPasswordResetService.php:74`); access tokens no (H1); SCRUM-823 sigue funcionando (inactivo -> 401, probado por
   `test_un_usuario_inactivo_sigue_recibiendo_401`); `security_blocked_at` no se toca, y el login lo sigue rechazando (`AuthController.php:107-108`); sin embargo
   `MfaController::verify` no revalida `security_blocked_at` (solo `is_active`): preexistente, BAJO.
8. Ver H7 y H10. Nueva != correo y != actual está del lado servidor (`AuthController.php:562-567`) y duplicada en el frontend.
9. Frontend: H11.
10. Producción: sección 8.

---

## 5. Lo que verifiqué de verdad

- Lectura completa del diff de ambas ramas, de `AuthController` (login, refresh, me, changePassword), `AuthenticateJwt`, `EnforceRole`, `JwtService`,
  `JwtClaimsBuilder`, `MfaService`/`MfaController`/`MfaCode`, `PasswordResetService`, `ForgotPasswordController`, `UserService` (create/register),
  `AuditService`, `LogsAuditTrail`, `User::auditableAttributes`, migración y trigger de `db_audit_log`, `GrantAppRoleCommand`, `routes/*`, `bootstrap/app.php`,
  `ThrottleRequests` del vendor, y en el frontend `RequireAuth`, `ForcePasswordChangePage`, `ResetPasswordModal`, `BulkResetPasswordModal`, `authStore`, `authApi`.
- Cinco tests descartables corridos en BD/Redis aislados (`atlanticerp_cspw`, `redis-cspw`): login sin throttle ni bloqueo (40 fallos, todos 401); token previo al
  reset toma la cuenta y sobrevive al cambio; token de superadmin degradado sigue pasando el gate; superadmin resetea a otro superadmin y 404 en id inexistente;
  "olvidé mi contraseña" deja el flag en `true`. Los 5 pasaron (confirmaron el comportamiento descrito). Archivo de test, BD y contenedor eliminados;
  `git status` del worktree backend limpio salvo este informe.
- La contraseña/hashes no aparecen en `audit_logs` (ORM) ni en `db_audit_log` (trigger) por código.

## 6. Lo que NO pude verificar

- Valor real de `mfa_mode` (`optional`/`required`) y cuántos usuarios tienen `mfa_enabled` en dev/test/prod (no consulté ningún entorno).
- Que el runtime de la app y `docker compose exec laravel php artisan ...` usen el rol `atlanticerp_app` (inmutabilidad real de `audit_logs`, REQ-634) y no `atlanticerp`.
- Qué IP ve PHP detrás de nginx en los entornos reales (alcance del throttle por IP) ni si CrowdSec (observe en prod) cubre bruteforce de login.
- `APP_ENV` real de los 3 servidores (relevante para `--yes`).
- Cuántas cuentas hoy ya tienen la contraseña = correo (H7).
- Comportamiento de `Toaster` ante HTML en `name`; llamadas fuera de axios en el frontend.
- Que `AuditService::log` dentro de la transacción falle cerrado.
- No ejecuté los tests propios de la rama ni las suites completas (solo los 5 descartables).
- Aviso de proceso: la rama backend está por detrás de `origin/dev` (el diff contra `origin/dev` muestra el borrado de `SharedFamiliesSeeder` y su test, que NO
  son cambios de esta rama sino de la base atrasada). Integrar con merge/rebase, nunca con un push que pise `dev`.

---

## 7. Handoff

- Backend Dev (vía PM): H1 (`password_reset_at` + chequeo de `iat`), H2.1/H2.2/H2.3 (prueba de posesión por correo o TTL; aviso), H3 (revalidar superadmin en BD,
  contraseña del operador, limitador por usuario), H4 (limitadores login/refresh/MFA), H5 (excluir superadmins del masivo por defecto), H7 (flag en altas),
  H9/H10 (atomicidad, regla de contraseña centralizada).
- AWS Architect/DevOps: confirmar `APP_ENV` y rol de BD del runtime y del `exec`; CrowdSec: escenario de bruteforce de `/api/auth/login` en bloqueo real (con
  allowlist de la oficina) cuando prod salga de `--observe`.
- AI Architect: incluir "login con contraseña temporal" y "cambio de contraseña con `must_change_password` desde IP nueva" en el contrato de `SecurityAnalysisJob`.
- Senior Reviewer: revisar H1/H3 como cambios de auth antes del merge; el cierre de H1 debe llevar test de regresión (token previo -> 401).
- Luis (decisión): H2 es un riesgo real aun con el diseño aprobado; el reset masivo en producción debería esperar a H1 + (H2.1 o H2.2) + H2.3, o ejecutarse
  solo de forma individual y con aviso. No hay remediación destructiva que ejecutar.

## 8. Controles operativos para el masivo en producción

1. No ejecutarlo hasta cerrar H1 y H2 (mínimo: prueba de posesión por correo o TTL, y aviso). Antes: probar el procedimiento completo en test con el N real.
2. Ventana corta y acordada, fuera de horario de oficina pero con la gente avisada por un canal distinto al correo reseteado (el aviso debe llegar antes del reset,
   indicando que ingresen y cambien la contraseña en las siguientes X horas y que desconfíen de cualquier otra instrucción).
3. Tandas pequeñas (p. ej. 5-8 personas, empezando por las más críticas que estén disponibles para ingresar de inmediato) en vez de las ~30 de golpe; superadmins
   y Admin&Cont de a uno, con la persona frente a la pantalla.
4. Doble confirmación humana: segunda persona (Luis/CTO) revisa la simulación (lista y N) antes del `--apply`; dejar la simulación archivada en el ticket.
5. Verificación posterior inmediata y periódica (cada hora durante la ventana): consulta de solo lectura de usuarios con `must_change_password = true` y sin login
   posterior al reset; revisar `audit_logs` por `auth.login` desde IP no habituales de esas cuentas y por `auth.user.updated` de cambio de flag; si alguna cuenta
   cambió de contraseña y la persona no fue, tratarlo como incidente (desactivar, revocar, nuevo reset individual en presencia).
6. Vencimiento: pasado el plazo, quien siga con el flag se resetea individualmente (o se desactiva temporalmente) en vez de dejarla abierta indefinidamente.
7. Registrar en el ticket: quién ejecutó, el N, la hora y la verificación; el `--operador` debe ser el nombre real.

---

## 9. Ronda 2 — re-verificación de H1, H5 y M1 (commit 6668bc34 backend, 451e8a1 frontend)

Alcance: solo lectura del código + pruebas descartables en BD y Redis propios (borrados al terminar; el archivo de prueba temporal se eliminó y no queda en el diff). La suite `tests/Feature/Auth` completa del worktree pasa: 362 tests, 1365 aserciones.

### 9.1 H1 — CERRADO (con matices que no bloquean)

Evidencia: `app/Shared/Http/Middleware/AuthenticateJwt.php:66-84` (una sola query `first(['is_active','must_change_password','password_reset_at'])`, comparación `iat < password_reset_at`); `AdminPasswordResetService.php` `resetUser()` (`password_reset_at = now()` y revocación de refresh dentro de la misma transacción); `JwtService.php:41-50` (`iat = time()`, entero, lo fija el servidor al firmar).

Probado con tokens firmados a mano (reset en T con fracción .5):

| Token | Resultado |
|---|---|
| `iat = T-1` | 401 |
| `iat = T` (mismo segundo, antes o después de la fracción) | 401 |
| `iat = T+1` | 200 |
| sin `iat` | 401 (falla cerrada: se toma como 0) |
| `iat` no numérico | 401 |
| `iat` futuro | 401 (la librería firebase/php-jwt lo rechaza antes) |

(a) Escenario original (token previo + `PUT /api/auth/password` con `current_password` = correo): cerrado, el middleware responde 401 antes de llegar al controlador. Caminos que emiten un access token nuevo:
- `login()`: exige la contraseña vigente; no parte de una sesión previa.
- `refresh()`: el refresh previo queda revocado dentro de la transacción del reset. Carrera analizada (refresh en vuelo que revoca el viejo e inserta el nuevo justo antes de que el reset revoque todos): el access token que saldría tendría `iat` = `floor(time())`, y el middleware rechaza todo `iat` del mismo segundo que el reset. Para colarse, el `encode` tendría que ocurrir en un segundo posterior al reset estando el refresh detenido más de la fracción restante (hasta 1 s). Riesgo práctico despreciable. El refresh nuevo, además, quedaría revocado por el reset si este lo ve; si no lo ve, el access token resultante sigue sin poder usarse en el mismo segundo.
- `switchRole()` y `selectRole()`: `switch-role` pasa por el middleware (un token previo recibe 401 antes). `select-role` solo acepta un selection token (5 min) que no se emite desde `login()` desde ADR-006 Fase C, sin llamador real.
- `mfa/verify`: ver N3 (reto MFA pendiente).

Reloj: `password_reset_at` lo fija PHP (`now()` de Carbon), no la BD, y `iat` también lo fija PHP (`time()`); ambos corren en el mismo host/contenedor por entorno (una EC2 por entorno), así que no hay skew entre PHP y Postgres que importe. El flooring del `iat` a segundos juega a favor de la defensa (siempre conservador). Un reset lanzado por el comando (`artisan` en el contenedor) y la API usan el mismo reloj de host.

(b) Una sola query por PK, una sola lectura de fila: `is_active`, `must_change_password` y `password_reset_at` salen del mismo snapshot, sin ventana de lectura inconsistente entre ellos. Sigue habiendo una segunda query en `GET /auth/me` (solo ese endpoint). Sin regresión de costo respecto a SCRUM-823.

(c) Login dentro del mismo segundo del reset: recibe un token con `iat = T` y la siguiente petición da 401; basta reintentar al segundo siguiente. Como solo un superadmin (con throttle) puede disparar el reset y el efecto dura menos de 1 s por cuenta reseteada, no es una vía de denegación selectiva aprovechable. Aceptable. Un matiz: en un reset MASIVO de N usuarios, cada usuario tiene su propio `password_reset_at`, por lo que la ventana es por usuario.

Observaciones no bloqueantes sobre H1:
- Cambio de contraseña propio (`changePassword`) NO pone `password_reset_at` (confirmado en el diff); revoca refresh tokens pero deja vivos los access tokens ya emitidos hasta que expiren (por defecto hasta 600 min). Esto es previo a esta rama.
- Flujo "olvidé mi contraseña" (`PasswordResetService::reset`) NO invalida access tokens ya emitidos, solo revoca refresh tokens. Es exactamente el caso en que el dueño perdió el control de la cuenta. Recomendación (N4): una columna genérica `tokens_valid_after` (o reutilizar `password_reset_at` con otro nombre) que se actualice en el reset administrativo, en el flujo "olvidé" y en `changePassword`; en `changePassword` hay que devolver un token nuevo al usuario para que no se desloguee a sí mismo. Prioridad media-baja, no bloquea.

### 9.2 H5 — CERRADO para el diseño pedido; hallazgo nuevo N1 de alcance residual

Evidencia: `AdminPasswordResetService.php` `resolveTargets()`/`excludeSuperadmins()` (rol base `role_id` en roles con `is_superadmin`, rol adicional `user_additional_roles`, fila legacy `user_roles.role = 'superadmin'`); `AdminPasswordResetController::resetMany` calcula `$targets` una sola vez con `$includeSuperadmins` y usa ese mismo `$targets` tanto para `dry_run` como para el `count` de `RESETEAR <N>` y para `resetMany(...)`, de modo que el conjunto es idéntico por construcción; el comando hace lo mismo (`resolverMasivo()` con el flag). `include_self` se resuelve aparte (`$includeSelf ? null : $actorId`) y se combina bien con `include_superadmins`: el propio actor superadmin solo entra con AMBOS flags. `--include-superadmins` solo con `--all-active` (validado en el comando, línea ~66). Los subqueries `NOT IN` no tienen riesgo de NULL (`user_id` es NOT NULL en `user_additional_roles` y en la PK de `user_roles`).

N1 — MEDIO — el masivo sigue alcanzando a cuentas con poder equivalente a superadmin que no tienen el rol. Probado: un usuario con `security_level` 10 (permisos `superadmin.all`) y otro con `extra_permissions = ['superadmin.all']`, ambos sin rol superadmin, SÍ quedan dentro del masivo por defecto. `User::hasPermission()` y `CheckPermission` los tratan como bypass total, así que son cuentas de máximo privilegio que reciben contraseña = correo junto con todos los demás. No pasan el gate `role:superadmin`, por lo que no pueden disparar el reset, pero sí son una cuenta de alto valor en la ventana H2. Remediación (Backend Dev): en `excludeSuperadmins()` excluir también a quien tenga `superadmin.all` en `extra_permissions` o cuyo nivel de seguridad lo incluya (nivel 10). Esfuerzo bajo. Si el equipo decide que "superadmin" es solo el rol, debe quedar documentado en el runbook; hoy el docblock dice "el mismo conjunto que hace pasar el gate `role:superadmin` y los bypass de permisos", lo que no es del todo cierto por este punto.

H3 sigue ABIERTO (no se corrigió por instrucción). Un atacante con token de superadmin robado puede pasar `include_superadmins: true` en la API, o llamar al reset individual (`POST /api/users/{id}/reset-password`) contra cada superadmin, sin ningún paso adicional. Lo que cambió: (agrava) H1 hace ahora que el reset invalide las sesiones de cada víctima, incluyendo la del superadmin legítimo, de modo que un reset masivo malicioso también cierra de golpe la sesión de todos (denegación de servicio y toma, aunque eso es una señal visible de detección); (atenúa) el valor por defecto ya no incluye superadmins, así que un clic accidental o un script sin parámetros no los toca, y los superadmins afectados quedan con `must_change_password`; (no cambia) el throttle `6,1` por IP y la auditoría `auth.user.password_reset_bulk` con `filtros.include_superadmins`, que ahora permite detectar el uso del flag en retrospectiva. Sin re-autenticación para la operación, H3 sigue siendo el riesgo de mayor impacto tras H2.

### 9.3 M1 — CERRADO

Backend: `routes/users.php` mantiene `role:superadmin` (EnforceRole compara el claim `role`, que es el rol base o `roles()[0]` legacy). Frontend: `UsersPage.tsx:30` `canResetPasswords = authUser?.role === 'superadmin'` alimenta tanto `UserTable` (`canResetPassword`) como el botón del masivo (líneas 170 y 466). Mismo criterio en ambos lados: no hay botón visible sin permiso real ni permiso real sin botón. Un nivel 10 sin rol superadmin recibe 403 y no ve botones (test existente). Nota: un usuario con rol adicional superadmin pero rol base distinto no pasa el gate (el claim `role` es el base), y tampoco ve el botón: consistente.

### 9.4 Regresiones

Ninguna encontrada. Middleware allowlist sigue exacta por método+ruta (`GET api/auth/me`, `PUT api/auth/password`); auditoría sin hashes ni contraseñas (el comando y el servicio no los registran); throttle `6,1` intacto; la revocación inmediata de SCRUM-823 se conserva (`$row === null || is_active !== true` da 401 como antes). Guarda de refresh silencioso del frontend: solo evita cerrar una sesión nueva cuando falla un refresh del token viejo; no concede acceso, es UX.

Hallazgos nuevos menores:
- N3 — BAJO — el reset administrativo no invalida (a) retos MFA ya emitidos, que seguirían canjeables en `mfa/verify` y emitirían un token con `iat` posterior (requieren además el código enviado al correo del usuario), ni (b) tokens de "olvidé mi contraseña" pendientes (1 h, requieren acceso al buzón). Ambos exigen control del correo, que en ese caso ya da la toma completa; coste de cerrarlo: `used_at = now()` sobre ambos en `resetUser()`. Recomendado, no bloqueante.
- N4 — BAJO/MEDIO — invalidación de access tokens en "olvidé" y `changePassword` (ver 9.1).
- N5 — BAJO — el chequeo H1 solo corre si `sub` del token es entero/numérico (misma condición que SCRUM-823, preexistente); los tokens los firma el servidor, así que no es explotable sin el secreto JWT.

### 9.5 Estado final H1..H11

| ID | Hallazgo | Estado tras ronda 2 |
|---|---|---|
| H1 | Tokens previos al reset vivos | CERRADO |
| H2 | Ventana de toma de cuenta (contraseña = correo) sin control compensatorio | ABIERTO (CRÍTICO, decisión de alcance) |
| H3 | Token de superadmin robado resetea a todos, sin re-auth | ABIERTO (ALTO); H5 lo atenúa solo frente a accidentes |
| H4 | Sin throttle en login/refresh/MFA | ABIERTO (MEDIO) |
| H5 | Masivo alcanza por defecto a superadmins | CERRADO para rol base/adicional/legacy; residual N1 (nivel 10 / `superadmin.all` por permisos) ABIERTO MEDIO |
| H6 | Auditoría/aviso al afectado | ABIERTO (MEDIO) |
| H7 | Altas nuevas con contraseña = correo sin flag (preexistente) | ABIERTO |
| H8 | Throttle por IP, previo a autenticar | ABIERTO (BAJO) |
| H9 | Masivo no atómico, confirmación solo liga el N | ABIERTO (BAJO) |
| H10 | "Olvidé mi contraseña" | PARCIAL: limpia el flag y rechaza contraseña = correo (cerrado); no invalida access tokens (abierto, ver N4) |
| H11 | Frontend guard solo UX / localStorage | ACEPTADO (sin acción) |
| M1 | Gate `role:superadmin` alineado con frontend | CERRADO |
| N1 | Nivel 10 / `superadmin.all` no excluidos del masivo | ABIERTO (MEDIO) |
| N3 | Retos MFA y tokens "olvidé" pendientes sobreviven al reset | ABIERTO (BAJO) |
| N4 | Access tokens no invalidados en "olvidé"/changePassword | ABIERTO (BAJO/MEDIO) |

### 9.6 Veredicto

(a) Merge a dev/test: APROBADO desde seguridad. H1 y H5 quedan cerrados de verdad, sin regresiones, y los 362 tests de Auth pasan. Con la salvedad de que N1 se corrija en el mismo ciclo o se registre como deuda con dueño (esfuerzo bajo). En dev/test no hay riesgo práctico: las cuentas son internas y de prueba.

(b) Uso en producción: NO AUTORIZADO todavía para un reset real. Estrictamente necesarios antes del primer reset en prod, en este orden mínimo:
1. H2 (CRÍTICO): control compensatorio en la ventana contraseña = correo. Mínimo viable: que el estado "reseteado" caduque (p. ej. login rechazado si `must_change_password` y `password_reset_at` tiene más de N horas, con N configurable en `system_settings`, no hardcodeado) y entrega fuera de banda a cada persona; mejor aún, sustituir contraseña = correo por una contraseña temporal aleatoria enviada por un canal distinto. Sin esto, cualquiera que conozca un correo corporativo puede tomar la cuenta.
2. H3: re-autenticación reciente (contraseña actual o MFA) para el masivo y para cualquier reset a un superadmin; es la defensa frente al token robado.
3. H4: throttle por cuenta/IP en login, refresh y MFA (necesario para que el MFA cuente como mitigación de H2 y para frenar la toma masiva).
4. N1: excluir nivel 10 / `superadmin.all` del masivo (rápido, puede ir junto con 1).
Hasta que 1-3 estén en producción, el único uso aceptable es operativo y acotado: reset individual (no masivo) en ventana controlada con el afectado avisado y conectado por otro canal, empezando por cuentas con MFA activo, y monitoreando `auth.user.password_reset*` en `audit_logs`. H6 (aviso al afectado), N3 y N4 pueden ir después. Este es un riesgo real, no una formalidad: no recomiendo un reset masivo en prod con el estado actual.

## 10. Cierre de H2/H3/H4 (decisiones de Luis, 2026-10-02)

Implementado en la rama `fix-reset-h234` (backend) sobre `origin/dev` `9b8285f1`. Los números de estado salen de los tests
(`PasswordResetExpirationTest`, `PasswordNoticeMailTest`, `PasswordResetReauthTest`, `AuthThrottleTest`).

| Hallazgo | Decisión | Estado | Valores por defecto |
|---|---|---|---|
| H2 — ventana de toma de cuenta | Caducidad configurable de la contraseña temporal (login y token vivo -> 401 `password_reset_expired`) + aviso por correo al afectado + interruptor del reset | **Mitigado** (la ventana se acota y se delata; no se elimina) | `password_reset_expiration_hours` = 48 (1..720); `password_reset_enabled` apagado en producción |
| H3 — rol del JWT sin revalidar / sin re-autenticación | `operator_password` en el masivo y en el reset de cuentas de máximo privilegio; rol del operador revalidado en BD en cada llamada; límite por usuario | **Cerrado** | 5 contraseñas de operador erróneas en 15 min -> 429; 20 peticiones/min por usuario |
| H4 — sin throttle en login/refresh/MFA | Límites por IP y por correo (solo fallos en login/refresh/MFA), tope de reenvíos y contador MFA por usuario que no se reinicia al reenviar | **Cerrado** | ver tabla del runbook (`config/throttle.php`) |

Notas de diseño:
- La caducidad solo se evalúa con el cambio obligatorio pendiente y `password_reset_at` fijado; en `AuthenticateJwt` solo se consulta el
  ajuste en ese caso, así que no agrega queries a las demás peticiones.
- El masivo aplicado exige también la confirmación escrita `RESETEAR <N>`; el orden de validaciones es: rol del operador en BD,
  interruptor, confirmación, contraseña del operador.
- El mecanismo MFA mantenía 5 intentos **por reto** y cada reenvío creaba un reto con el contador en 0 (código de 6 dígitos
  forzable con reenvíos ilimitados). Ahora hay además un tope por **usuario** en la ventana que el reenvío no reinicia, y los
  reenvíos están limitados.
- La respuesta `429` del login por correo es idéntica para correos existentes e inexistentes (test explícito).

Lo que sigue abierto (sin cambios): N3, N4 y los hallazgos menores H6-H9; el riesgo residual de H2 en producción
(contraseña temporal = correo conocido) sigue exigiendo ventana controlada, aviso previo y reset individual.


## 11. Ronda 3 (Cybersecurity, 2026-10-02) — verificación del cierre de H2, H3 y H4

Alcance: diff `origin/dev..fix-reset-h234` (backend, 6 commits) y `fe-h234` (frontend, 4 commits). Solo lectura sobre código; se ejecutaron 5 tests
descartables (BD `atlanticerp_cs3` y Redis `redis-cs3` propios, ya borrados; el archivo de prueba NO queda en el diff). Las cifras "PROBE" salen de esas
pruebas. Los 362+ tests de Auth del implementador no se repitieron.

### 11.1 Veredicto corto

H2, H3 y H4 quedan MITIGADOS de verdad en lo que el diseño aprobado prometía, sin regresiones en H1, allowlist, auditoría ni la query única de
`AuthenticateJwt`. No hay bloqueante para merge a dev/test. Para el primer reset real en producción hay 2 hallazgos MEDIO que conviene cerrar o aceptar
por escrito (R3-1 y R3-2) y 2 verificaciones operativas obligatorias (R3-3 IP real y CACHE_STORE). El diseño aprobado deja 2 huecos que no son errores de
implementación sino de diseño y los marco sin suavizar (R3-1, R3-6).

### 11.2 Hallazgos nuevos (bloqueantes y altos primero; ninguno es BLOQUEANTE de merge)

**R3-1 — MEDIO (residual de H3, de diseño) — el reset individual de personas NORMALES sin contraseña equivale a un masivo sin contraseña.**
`AdminPasswordResetController.php:53-56` solo pide `operator_password` si `isTopPrivilege()`; el límite es `password-reset-admin` 20/min por usuario
(`config/throttle.php`, `AppServiceProvider.php`). PROBE A: con un token de superadmin, 25 resets individuales seguidos de personas normales sin contraseña
dieron 20 x 200 y 5 x 429; es decir, toda la plantilla (32 personas) se resetea en 2 minutos. El masivo exige `RESETEAR N` + contraseña, pero el
mismo efecto se obtiene en bucle por la ruta individual. Daño con token robado: toma de cuentas de toda la organización (login con contraseña = correo
dentro de la ventana, cambio de contraseña por el atacante = persistencia que SOBREVIVE a revocar el token robado, impersonación de quienes tienen
`approve_large_amounts`/aprobaciones de Admin&Cont). Lo único que queda del lado defensivo es el aviso por correo (detecta, no previene) y la ventana
de 48 h. Aceptable solo si se acepta explícitamente que el token robado de superadmin puede tomar cuentas normales. Remediación (Backend Dev, esfuerzo
bajo): pedir `operator_password` también en el individual a partir del N-ésimo reset sin contraseña por operador (p. ej. 3 por 15 min, contador en
RateLimiter) o bajar `per_minute` de las dos rutas y añadir un tope por hora (p. ej. 10/h) por operador.

**R3-2 — MEDIO (H4, denegación de servicio selectiva) — cualquiera que conozca un correo puede bloquearle el login Y la recuperación.**
PROBE B: 5 logins fallidos (cualquier IP) contra el correo de la víctima y su login correcto posterior desde otra IP devuelve 429; 3 solicitudes de
"olvidé mi contraseña" y la cuarta da 429. Causas: `AuthController.php:101-106` aplica el tope por correo ANTES de verificar la credencial y la clave
`login:email:` no incluye la IP; `ForgotPasswordController.php` cuenta todas las solicitudes (3/h por correo). Coste del atacante: 5 peticiones/min y
3/h, indefinidamente (no hay bloqueo permanente, pero se renueva solo). Impacto en la ventana H2: un atacante puede mantener bloqueado a un afectado
hasta que su temporal caduque (48 h) y le sale la cuenta "caducada" sin camino de autoservicio (queda el reset por superadmin o el CLI). Si la víctima
es el único superadmin, solo el CLI en el servidor. El diseño aprobado dijo "sin bloqueo permanente"; esto es una DoS temporal renovable. Remediación
(esfuerzo bajo-medio): clave por par (correo + IP) con tope bajo (5/min) MÁS un tope global por correo mucho más alto (p. ej. 30/15 min) que solo
dispare el 429; así el atacante no bloquea al dueño desde otra IP salvo con 30 intentos. Para "olvidé": no consumir el cupo por correo cuando el
solicitante ya agotó su cupo por IP. Y documentar en el runbook el procedimiento de recuperación si el único superadmin queda bloqueado/caducado.

**R3-3 — MEDIO/OPERATIVO (H4) — la IP del limitador depende de `REMOTE_ADDR` real; verificar en cada entorno antes de confiar en los límites.**
No hay `trustProxies` en `bootstrap/app.php` (grep sin resultados): Laravel ignora `X-Forwarded-For`, por tanto NO es falsificable por cabecera (bien).
Nginx habla FastCGI y `fastcgi_params` pasa `$remote_addr`. Riesgo inverso: si Docker entrega el tráfico (p. ej. IPv6 por `userland-proxy`, o un
ALB/CloudFront futuro) con la IP del gateway/proxy, todos los usuarios comparten UN cupo (`login:ip:` 10 fallos/min y `refresh:ip:` 30/min para toda
la empresa = DoS global con 10 peticiones). Acciones: (a) DevOps confirma en dev/test/prod que `request()->ip()` es la IP pública del cliente (el
`access.log` de nginx, `$remote_addr`, ya lo usa CrowdSec); (b) IPv6: el limitador usa la dirección completa, un atacante con un /64 rota 2^64
direcciones y esquiva el tope por IP (queda el tope por correo; el password spraying distribuido no lo frena): normalizar a /64 si el servidor
publica AAAA; (c) tras un masivo, los refresh tokens revocados provocan 1 refresh fallido por pestaña por persona: 30 personas detrás de la IP de
la oficina pueden agotar `refresh` 30/min y `login` ip 10/min con typos; hacer el masivo por tandas y avisar. No es un fallo del código sino de
calibración: documentar los valores como configurables por `.env` (ya lo son).

**R3-4 — BAJO (H4) — spam de códigos MFA con credencial correcta.** PROBE C: 15 logins correctos seguidos de un usuario con MFA generaron 15 correos MFA
y ningún 429, porque `AuthThrottle` cuenta solo fallos y `generateChallenge()` no tiene tope. En la ventana H2 la contraseña de todos los afectados
es su correo (conocida), así que cualquiera puede inundar de correos a una persona (y a sus 3 reenvíos/10 min, que sí están limitados). Remediación:
tope de retos MFA emitidos por usuario (reutilizar `mfa_resend.*`). Esfuerzo bajo.

**R3-5 — BAJO (H3/H4) — contadores "comprobar y luego golpear" no son atómicos.** `AdminPasswordResetController::reauthenticate` (l.142-152),
`MfaService::verify` (l.82-95) y `login` consultan `tooManyAttempts` y solo después registran el fallo. Una ráfaga paralela pasa el chequeo N veces
antes de que el primer `hit` se refleje. Con el límite de rutas (20/min) el máximo de intentos de contraseña de operador por ráfaga es ~20 en lugar de
5 (y 10 en MFA por IP); no cambia el orden de magnitud. Remediación opcional: `RateLimiter::hit` previo y `clear` al acertar (o `Cache::lock`).

**R3-6 — BAJO/MEDIO (H2/H3, de diseño) — el interruptor y la caducidad no están protegidos contra el token robado.** `PUT /api/admin/global-settings/{key}`
solo exige `permission:superadmin.all` desde el claim del JWT (`routes/admin.php:15`), sin `operator_password` ni revalidación en BD. Un token robado
enciende `password_reset_enabled` en producción y sube `password_reset_expiration_hours` hasta 720 (30 días, ensanchando la ventana H2 15 veces);
queda auditado (`SETTINGS_UPDATED` con valores viejo/nuevo, `GlobalSettingsController.php:68`) pero sin alerta. Quien tenga `superadmin.all` por nivel/
`extra_permissions` sin ser rol superadmin también puede cambiarlos (aunque no puede resetear). Es decir: el interruptor frena accidentes y operación
fuera de ventana, NO frena al atacante con token de superadmin; la defensa real contra ese atacante es H3 y es parcial (R3-1). Remediación: exigir
`operator_password` en el PUT de estas dos claves (y revalidar el rol en BD) y alertar por SNS/CloudWatch a `atlanticerp-alerts` cuando cambie `password_reset_enabled`
a 1 en producción; bajar `max` de caducidad (p. ej. 168 h). Esfuerzo bajo.

**R3-7 — BAJO (operativo/H9) — los avisos se envían de forma síncrona dentro de la petición.** `PasswordNoticeMail` usa `Queueable` pero NO `ShouldQueue`;
`PasswordNotifier::send` hace `Mail::to()->send()` en línea. En masivo son N envíos SMTP (Office365) dentro de una sola petición HTTP: un
SMTP lento o con timeout puede superar el timeout de nginx/PHP y dejar el masivo a medias (agrava H9: no hay transacción global), y el fallo de cada
envío solo se registra (clase del error, sin datos, bien). Preexistente y relacionado: `PasswordResetService::sendResetLink` también es síncrono, así
que "olvidé mi contraseña" responde más lento para correos existentes que inexistentes (enumeración por tiempos, el cuerpo y el código sí son idénticos).
Remediación: `->queue()` (Horizon ya existe) con `afterCommit`. Para el primer reset real: tandas de máximo 10 personas.

**R3-8 — INFO (preexistente) — enumeración por tiempos en login.** `AuthController.php:119-125`: con `! $user ||` en cortocircuito no se ejecuta
`Hash::check` para correos inexistentes (bcrypt omitido). PROBE E no es concluyente (en testing el coste de bcrypt es mínimo), pero el código lo
garantiza en producción: ~100-250 ms de diferencia medible. Mitigado en parte por los límites H4 (5/min por correo, 10/min por IP). Remediación: `Hash::check`
contra un hash señuelo cuando no hay usuario. Esfuerzo bajo, no bloquea.

**R3-9 — INFO — cuenta caducada y enumeración.** `password_reset_expired` solo se devuelve con credencial correcta (la temporal = correo), así que quien
adivina `correo` como contraseña aprende, tras vencer, que esa cuenta existía y fue reseteada. Es el mismo conocimiento que ya tenía (necesita la
contraseña correcta); aceptado. Credencial incorrecta devuelve el 401 genérico (test existente + revisado `:124-146`).

**R3-10 — INFO — `PUT /auth/password` sin límite de intentos para `current_password`** (preexistente, `AuthController.php:~595`): un token robado es un oráculo de
la contraseña actual. Solo relevante con token ya robado; sugerencia de bajo coste: reutilizar `AuthThrottle` con clave por usuario.

**R3-11 — INFO — `password_reset_at` NULL con flag.** PROBE D2: usuario con `must_change_password=true` y `password_reset_at` NULL entra 400 días después (la
caducidad exige ambos campos, `PasswordResetPolicy.php:70-77`). El único código que pone el flag es `AdminPasswordResetService::resetUser` (grep de
`must_change_password` en app/ y database/: el resto lo lee o lo limpia), que siempre fija ambos en la misma transacción. Solo se llega a ese estado con
SQL/tinker manual. Aceptado; añadir al runbook "no activar el flag a mano".

**R3-12 — INFO — frontend: `reset.mutate({operatorPassword})`** deja la contraseña en `mutation.variables` del MutationCache de TanStack Query (memoria, hasta el gcTime
por defecto de 5 min tras desmontar). No se persiste en localStorage/sessionStorage ni se loguea (revisado `ResetPasswordModal.tsx`, `BulkResetPasswordModal.tsx`,
`authApi.ts`); se limpia el estado local en cada respuesta y al cerrar. Aceptado; opcional `gcTime: 0` en esas dos mutaciones.

### 11.3 Confirmaciones con evidencia

1. **H2 caducidad cierra la ventana.** Login con la temporal vencida: 401 `password_reset_expired` sin token ni refresh, y solo con credencial correcta
   (`AuthController.php:141-160`; chequeo anterior a MFA, así que un usuario con MFA tampoco avanza). Token vivo emitido antes del vencimiento:
   `AuthenticateJwt.php:87` devuelve 401 `password_reset_expired` ANTES de la allowlist (`:95`), de modo que `PUT /api/auth/password` con `current=correo` ya
   no es posible tras vencer (test `test_un_token_vivo_de_una_cuenta_caducada_recibe_401_en_cualquier_ruta`). Refresh de esa sesión devuelve un token que
   el middleware vuelve a rechazar: sin bypass. Ajuste manipulado: valores `abc`, `-5`, `99999999`, `0`, `1e3`, `48.9`, vacío => 48 h efectivas
   (PROBE D; `PasswordResetPolicy::expirationHours` valida 1..720 y cae al default). Relojes: `password_reset_at` es `timestamptz(6)`, `expiresAt`
   y `isPast` usan el reloj de la app (misma fuente que escribe `now()`); sin deriva por zona. Sin bloqueo permanente: otro reset administrativo,
   "olvidé mi contraseña" (limpia el flag; test existente) o CLI reabren la cuenta, aunque dependen del correo y de R3-2.
2. **Correo de aviso.** Destinatario = el correo REGISTRADO de la cuenta (nunca un valor de la petición), sin enlaces ni contraseñas, asunto fijo por
   tipo, nombre escapado con `{{ }}` en la vista blade (`password-notice.blade.php`), sin cabeceras construidas con datos del usuario. No permite enumerar
   (solo se envía desde flujos autenticados o con token de "olvidé"). Si el atacante controla el buzón de la víctima el aviso le llega a él, pero ese
   atacante ya tiene la cuenta; el aviso cumple su función contra el caso realista (toma con la contraseña = correo) porque el cambio de contraseña del atacante
   notifica al dueño real (`AuthController.php:~620`).
3. **Interruptor.** Único consumo de `enabled()`: API individual (`:39`), masivo aplicado (`:92`) y comando con `--apply` (`UsersResetPasswordCommand.php:99`). No hay otro
   llamador de `resetUser/resetMany` (grep): ni seeders, ni jobs, ni tinker lo protegen (tinker/CLI implican shell en el servidor, fuera del modelo del
   token robado). El default dinámico depende SOLO de `app()->environment('production')`; confirmar que `APP_ENV=production` en `atlanticerp.ai` (CLAUDE.md lo
   declara; `qa` en test). Un valor en `global_settings` lo reemplaza (por diseño, solo vía el PUT de superadmin.all, auditado). El caché de 5 min se invalida en el PUT
   (`Cache::forget`); un cambio directo en BD tarda hasta 5 min.
4. **H3.** Orden de validaciones (masivo): rol del operador en BD -> simulación -> interruptor -> confirmación -> límite de intentos -> `Hash::check`
   (`AdminPasswordResetController.php:68-104,131-157`): el límite se evalúa antes de comparar, no hay intentos gratis (salvo la carrera R3-5). Límite por usuario
   (`pwreset-reauth:u{id}`), no por IP: rotar IP no ayuda; cambiar de usuario objetivo tampoco (clave del operador). Degradar a un superadmin con token vivo bloquea
   TODAS las rutas de reset (`guardOperator` está en `reset` y `resetMany`, compara el rol base de BD con el claim `role`). "Máximo privilegio" es consistente: `isTopPrivilege`
   usa `excludeSuperadmins()`, el mismo filtro que el masivo, (rol base/adicional, legacy, nivel 10, `superadmin.all` en nivel o `extra_permissions`) => N1 cerrado.
   La contraseña del operador no llega a auditoría (`metadata` solo guarda `reautenticado: bool`), ni a `Log::` (grep), ni a excepciones (validación JSON, la clave
   no está en el estado que Laravel refleja). Daño residual del token robado sobre personas normales: ver R3-1.
5. **H4.** 429 idéntico para correos existentes e inexistentes (mismo camino, el golpe se registra en ambos casos). Variantes de mayúsculas/espacios comparten
   cupo (`AuthThrottle::emailKey`); el alias `+` es otra cuenta y se limita por separado, lo esperado. MFA: el contador por usuario (`mfa:verify:user:{id}`, 5/600 s)
   NO se reinicia al reenviar y se limpia solo al acertar; reenvíos 3/600 s por usuario; los retos expiran (TTL del reto) y `attempts` por reto se mantiene. Brute force
   residual de 6 dígitos: ~5 intentos/10 min/usuario (≈720/día de 1.000.000) ⇒ despreciable. "Olvidé": 3/h por correo y 5/15 min por IP cuentan TODAS las
   solicitudes; el spam a terceros queda en 3 correos/h por víctima (rotando IP, ~72/día). Driver de caché: `.env.example` y CLAUDE.md usan `redis`;
   `config/cache.php` cae a `database` si falta la variable (también compartido, no rompe el límite) y solo `phpunit.xml`/`.env.testing` usan `array`.
   No pude leer el `.env` real de los servidores: DevOps debe confirmar `CACHE_STORE=redis` en dev/test/prod (si fuera `array`, no habría ningún límite).
6. **Regresiones.** H1: `AuthenticateJwt.php:78-85` intacto. Allowlist: `GET /api/auth/me` y `PUT /api/auth/password` (`:121-127`). Query única por
   petición: la lectura del ajuste solo ocurre con flag + `password_reset_at` y viene de Redis (5 min), sin SQL adicional para el resto. Auditoría: sin hashes ni
   contraseñas; `User::auditableAttributes()` sigue excluyendo `password_hash`. SCRUM-823 (revocación) intacto. Límites no rompen flujos legítimos: login
   correcto no consume cupo y limpia el del correo; refresh válido no consume; MFA correcto limpia el contador. Frontend: la contraseña del operador vive
   solo en estado del componente, `type="password"`, `autoComplete="current-password"` (aceptable: es su propia contraseña), se vacía en cada respuesta y al
   cerrar; 401 `password_reset_expired` limpia la sesión sin bucle de refresh (`authApi.ts:67-74`); cuenta atrás desde `retry_after` con marca absoluta de
   tiempo; mensajes del login/olvidé no distinguen cuentas existentes; todo React-escapado, sin `dangerouslySetInnerHTML` en lo nuevo.

### 11.4 Estado final H1..H11 + N

| ID | Hallazgo | Estado Ronda 3 |
|---|---|---|
| H1 | Tokens previos al reset vivos | CERRADO (sin regresión) |
| H2 | Ventana contraseña = correo | PARCIAL-ACOTADO: caducidad + aviso + interruptor cierran la ventana permanente; la ventana de 48 h sigue existiendo por diseño (riesgo residual aceptado: R3-2 la puede alargar como DoS, R3-6 ensancharla con token robado) |
| H3 | Token de superadmin robado resetea a todos | PARCIAL: masivo y cuentas de máximo privilegio CERRADOS; personas normales siguen alcanzables en bucle (R3-1, MEDIO) |
| H4 | Sin throttle login/refresh/MFA | CERRADO en lo pedido; residuales R3-2 (DoS selectivo, MEDIO), R3-3 (verificar IP real, operativo), R3-4/R3-5 (BAJO) |
| H5 | Masivo alcanza superadmins | CERRADO |
| H6 | Auditoría y aviso al afectado | CERRADO (aviso en reset y cambio + audit; R3-6 pide alerta activa) |
| H7 | Altas nuevas con contraseña = correo sin flag | ABIERTO (preexistente, fuera de este cambio) |
| H8 | Throttle por IP previo a autenticar | CERRADO (límite por usuario operador; límites de login por IP/correo) |
| H9 | Masivo no atómico | ABIERTO (agravado por envío síncrono, R3-7; mitigar con tandas) |
| H10 | "Olvidé mi contraseña" | PARCIAL (aviso + límites + limpia flag; N4 abierto) |
| H11 | Guard frontend solo UX | ACEPTADO |
| M1 | Gate role:superadmin alineado | CERRADO |
| N1 | Nivel 10 / superadmin.all fuera del masivo | CERRADO |
| N2 | (no figura en este informe) | n/a |
| N3 | Retos MFA y tokens "olvidé" sobreviven al reset | ABIERTO (BAJO; los selection tokens ya no los emite el login) |
| N4 | Access tokens no invalidados en olvidé/changePassword | ABIERTO (BAJO/MEDIO) |
| N5 | Chequeo H1 solo si sub es numérico | ABIERTO (BAJO, sin explotación sin el secreto JWT) |
| R3-1..R3-12 | Hallazgos nuevos de esta ronda | R3-1, R3-2, R3-3 MEDIO; R3-6 BAJO/MEDIO; R3-4, R3-5, R3-7 BAJO; R3-8..R3-12 INFO |

### 11.5 Veredicto

(a) Merge a dev/test: APROBADO desde seguridad. En dev/test el mailer es Mailpit y las cuentas son internas; ningún hallazgo es bloqueante. R3-1 a R3-7
pueden entrar como tickets de seguimiento con dueño; R3-1 y R3-2 son los que recomiendo en el mismo ciclo (esfuerzo bajo cada uno).

(b) Primer reset real en producción: CONDICIONADO. Orden mínimo de controles restantes:
1. DevOps (verificación, 10 min, sin cambios): en prod `CACHE_STORE=redis`, `APP_ENV=production`, y `request()->ip()` real (R3-3); confirmar que `password_reset_enabled` aparece
   apagado en Configuración global antes de empezar y que `atlanticerp-alerts` está suscrito (confirmaciones pendientes del 2026-08-31).
2. Backend (antes de prod, recomendado): R3-1 (tope de resets individuales sin contraseña o contraseña a partir del N-ésimo) y R3-2 (clave correo+IP). Si no se
   hacen, aceptar por escrito el riesgo de token robado de superadmin y de bloqueo selectivo durante la ventana.
3. Ventana operativa: un superadmin (nunca el único: tener 2 con acceso al CLI) enciende `password_reset_enabled` solo durante la ventana, con la caducidad en 48 h o menos
   (24 h recomendado) y la apaga al terminar; anunciar el horario a los afectados por otro canal y que estén conectados.
4. Tandas de máximo 10 personas (R3-7, H9, y para no agotar `refresh`/`login` por IP de la oficina, R3-3), empezando por cuentas con MFA y por un reset
   individual de prueba con un afectado en línea; entre tandas verificar `auth.user.password_reset*` y `auth.login` en `audit_logs`.
5. Verificación al cerrar: cada afectado recibió el aviso, ningún `auth.user.password_reset` fuera de ventana, cero cuentas con `must_change_password` vencido sin atender (reset
   de rescate individual), interruptor apagado de nuevo y registro del cambio en la auditoría de ajustes.
6. Hasta que se cierren R3-1/R3-6, no hacer el reset masivo de todas las cuentas en una sola operación aunque pida contraseña: usar tandas individuales con el afectado presente.

## 12. Respuesta del equipo a la ronda 3 (Backend, 2026-10-02)

Decisiones de Luis aplicadas: R3-1 "tope por hora y luego contraseña", R3-6 "contraseña solo para interruptor y caducidad".

| Hallazgo | Corrección | Dónde |
|---|---|---|
| M1/R3-7 correo síncrono | `PasswordNoticeMail` es `ShouldQueue` y `PasswordNotifier` usa `Mail::queue`; el masivo ya no depende del SMTP | `Mail/PasswordNoticeMail.php`, `Services/PasswordNotifier.php` |
| M2/R3-4 MFA 422 y sin tope de envíos | `MfaThrottledException` → `429` con `retry_after` en `verify` y `enable/confirm`; tope configurable `mfa_send` (5 por usuario en 10 min) en los logins correctos con MFA | `MfaService`, `MfaController`, `AuthController`, `config/throttle.php` |
| R3-1 reset individual sin contraseña | tope `password_reset_free_per_hour` (ajuste global, 5 por defecto); pasado el tope exige `operator_password`; cuentas de máximo privilegio siempre | `OperatorReauth::withinFreeQuota`, `AdminPasswordResetController` |
| R3-6 interruptor/caducidad sin contraseña | `PUT` de `password_reset_enabled` y `password_reset_expiration_hours` exige `operator_password` (mismo tope por usuario); el listado expone `requires_reauth` | `GlobalSettingsController`, `GlobalSettingsRegistry::requiresReauth` |
| Atomicidad de contadores | `AuthThrottle::reservar()` incrementa ANTES de evaluar y las operaciones correctas devuelven el cupo (`devolver`/`limpiar`); aplicado a login, refresh, MFA, olvidé mi contraseña, reenvío MFA y re-autenticación del operador | `Support/AuthThrottle.php` |
| R3-2, R3-3 | aceptados y documentados + checklist DevOps | `docs/runbooks/reset-password.md` |

Cybersecurity no repitió la ronda sobre este delta (decisión de Luis); lo cubren los tests de `AuthThrottleTest`,
`PasswordResetReauthTest`, `PasswordNoticeMailTest` y `PasswordResetExpirationTest`.
