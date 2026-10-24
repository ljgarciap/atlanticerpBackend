# Runbook — Reset administrativo de contraseñas

Devuelve cuentas a **contraseña = su correo** y obliga a cambiarla al ingresar. Existe porque los seeders
(`CoreUserSeeder`, `BusinessRoleUserSeeder`, `UserService`) solo crean la contraseña al **crear** al usuario y nunca la
tocan después (a propósito: un deploy no debe pisar una contraseña que la persona eligió). Cuando alguien la cambió y
hace falta volver a una conocida, se usa este mecanismo.

## Qué hace, por usuario
1. Contraseña = correo del usuario (guardado con hash bcrypt, como cualquier contraseña).
2. `must_change_password = true`: el login lo informa (`user.must_change_password`) y el middleware `AuthenticateJwt`
   solo deja `GET /api/auth/me` y `PUT /api/auth/password`; cualquier otra ruta responde `403` con
   `code: password_change_required`. Cambiar la contraseña limpia el indicador. La nueva no puede ser igual al correo ni
   a la anterior.
3. Revoca todos sus refresh tokens y **invalida los access tokens ya emitidos**: guarda `password_reset_at` y
   `AuthenticateJwt` responde `401` a todo token cuyo `iat` sea anterior a ese instante (también en
   `GET /api/auth/me` y `PUT /api/auth/password`). Así una sesión previa al reset, o un token robado, no puede cambiar la
   contraseña usando el correo como contraseña actual. Solo el reset administrativo lo toca: el cambio de contraseña
   propio no invalida el token en uso. Tras un reset, quien ingresa con su correo recibe un token nuevo válido.
4. Deja un evento de auditoría `auth.user.password_reset` (quién lo hizo, por comando o API, individual o masivo) y, en
   masivo, un resumen `auth.user.password_reset_bulk`. **Nunca** se registran ni se imprimen contraseñas ni hashes.

No toca `is_active` (un reset no reactiva cuentas inactivas: se saltan) ni `security_blocked_at` (un bloqueo de
seguridad no se levanta con un reset).

## Comando

```bash
# Simulación (por defecto): lista a quién alcanzaría, NO cambia nada
php artisan users:reset-password --email=persona@dominio.com
php artisan users:reset-password --all-active
php artisan users:reset-password --all-active --except=a@x.com,b@x.com
php artisan users:reset-password --all-active --include-superadmins   # incluye superadmin (por defecto quedan fuera)

# Aplicar (pide confirmación escrita y exige --operador)
php artisan users:reset-password --email=persona@dominio.com --apply --operador="Nombre Apellido"
php artisan users:reset-password --all-active --except=a@x.com --apply --operador="Nombre Apellido"
```

- Exactamente una de `--email=` (individual) o `--all-active` (masivo). `--except=` y `--include-superadmins` solo con `--all-active`.
- El masivo **no incluye a los superadmin**, entendido como cualquier cuenta con bypass total: rol base o adicional `superadmin`, security level 10 (o con `superadmin.all`) y `superadmin.all` en permisos extra. Un reset general no debe dejar a todas
  las cuentas de máximo privilegio con contraseña = correo a la vez. Se incluyen solo con `--include-superadmins`. El
  reset individual de un superadmin puntual (`--email=`) sigue permitido y queda auditado.
- La confirmación se escribe tal cual: `RESETEAR <ENTORNO> <N>`, con `ENTORNO` = `APP_ENV` en mayúsculas (`DEV`, `QA`, ...) y
  `PRODUCCION` en producción; `N` = cuántos usuarios se resetean (el comando lo muestra). Una confirmación distinta no
  aplica nada.
- `--yes` (omite la confirmación) solo funciona en `local` y `testing`; en cualquier otro entorno se rechaza.
- `--tenant=` (por defecto `atlantic`).

### Por ssh, sin TTY
La confirmación se entrega por stdin con `SHELL_INTERACTIVE=1` (mismo patrón que `lafayette:aislar-catalogo`). Cada
`docker compose exec -T` dentro de un script ssh necesita `< /dev/null` explícito salvo el que usa stdin:

```bash
printf "RESETEAR DEV 3\n" | docker compose -f infra/docker-compose.qa.yml exec -T -e SHELL_INTERACTIVE=1 laravel \
  php artisan users:reset-password --all-active --except=superadmin2@test.com --apply --operador="Nombre Apellido"
```

Corre la simulación primero, copia el `N` que muestra y úsalo en la confirmación. Para el entorno usa la etiqueta de
`APP_ENV` (dev → `DEV`, test/qa → `QA`, producción → `PRODUCCION`); si dudas, la pregunta del comando muestra el texto exacto.

## API (solo superadmin)
Ambos endpoints exigen JWT con rol `superadmin` y tienen un límite de peticiones por minuto por usuario (`THROTTLE_PASSWORD_RESET_ADMIN_PER_MINUTE`, 20 por defecto).

- `POST /api/users/{id}/reset-password` → `200 {"data": {"id", "email", "must_change_password": true}}`; `404` si no
  existe; `422` si el usuario está inactivo.
- `POST /api/users/reset-passwords` con `{"dry_run": bool, "except_ids": [int], "include_self": bool, "include_superadmins": bool, "confirm": "RESETEAR <N>"}`:
  - `dry_run: true` → `200 {"data": {"count": N, "users": [{"id","email","name"}]}}` sin cambiar nada.
  - Sin `dry_run`, `confirm` debe ser exactamente `RESETEAR <N>` (N = lo que resuelve el servidor) o `422` con
    `code: confirmation_mismatch`. Aplica y responde `200 {"data": {"reset_count": N}}`.
  - Por defecto **no** incluye a quien lo ejecuta (para no cerrar su propia sesión); `include_self: true` lo incluye.
  - Por defecto **no** incluye a ningún superadmin; `include_superadmins: true` los incluye (mismo criterio en `dry_run` y
    al aplicar; el `N` de la confirmación es el del conjunto resuelto). Un superadmin que se ejecuta a sí mismo necesita
    `include_self` **y** `include_superadmins`.
- Ambos exigen el **rol base** `superadmin` del JWT (`role:superadmin`), más estricto que el permiso `superadmin.all`: un
  usuario de nivel 10 o con rol adicional superadmin pero otro rol base recibe `403` (falla cerrado).

En la UI: Seguridad > Usuarios (acción por fila y botón masivo).

## Riesgo en producción (leer antes de usarlo)
Con contraseña = correo, entre el reset y el primer ingreso de cada persona **cualquiera que conozca su correo
podría entrar y fijarle una contraseña**. El cambio obligatorio reduce el daño pero no cierra esa ventana. Por eso, en
producción:
- Ejecútalo en una ventana controlada y avisa antes a las personas para que ingresen y cambien la contraseña enseguida.
- Prefiere el reset **individual** justo cuando la persona va a ingresar; el masivo solo con una lista acotada
  (`--except`) y avisando.
- Revisa después quién sigue con `must_change_password = true` y no ha ingresado.
- La confirmación pide la palabra `PRODUCCION` a propósito.

**Controles que ya existen (cierre de H2/H3/H4):** caducidad de la contraseña temporal, aviso por correo al afectado,
interruptor del reset (apagado por defecto en producción), re-autenticación del operador y límites de intentos en login,
refresh, MFA y "olvidé mi contraseña" (secciones de abajo). **Riesgo que sigue vivo:** mientras la contraseña temporal
sea el correo y no haya vencido, quien conozca el correo puede entrar y fijar su propia contraseña; la caducidad y el aviso
acotan y delatan esa ventana, no la eliminan. Por eso en producción sigue valiendo: ventana controlada, aviso previo a las
personas y reset individual justo antes de que la persona ingrese.

## Caducidad de la contraseña temporal (H2)
- Ajuste global `password_reset_expiration_hours` (1 a 720 horas, **48 por defecto**). Se edita en Configuración global
  (`PUT /api/admin/global-settings/password_reset_expiration_hours`, `{"value": 72, "operator_password": "…"}`): **cambiarla exige
  la contraseña del operador** (R3-6) y el cambio queda auditado.
- Una cuenta con el cambio obligatorio pendiente cuya contraseña temporal es más antigua que ese plazo queda **caducada**:
  el login responde `401 {"code": "password_reset_expired"}` (sin emitir token) y un token vivo recibe el mismo `401`.
- Cómo se recupera: otro reset administrativo, o "Olvidé mi contraseña" (que además limpia el cambio obligatorio).
- Solo aplica a cuentas con un reset administrativo pendiente; quien no lo tiene nunca caduca.

## Interruptor del reset (S2)
- Ajuste global `password_reset_enabled` (0/1; la API también acepta `true`/`false`). Si no hay valor guardado, está
  **apagado en producción** (`APP_ENV=production`) y encendido en el resto de entornos.
- Apagado: el comando con `--apply` falla con un mensaje claro y la API responde `403 {"code": "password_reset_disabled"}`
  al aplicar (individual y masivo con `dry_run:false`). La **simulación sigue funcionando** (comando y `dry_run:true`).
- Encenderlo solo durante la ventana acordada: `PUT /api/admin/global-settings/password_reset_enabled` con
  `{"value": 1, "operator_password": "…"}` y volver a apagarlo con `{"value": 0, "operator_password": "…"}` al terminar.
  **Cambiar el interruptor exige la contraseña del operador** (R3-6, igual que la caducidad; los demás ajustes globales no la
  piden): un token de superadmin robado no puede encenderlo ni alargar la caducidad por sí solo. Cada cambio queda auditado.

## Aviso por correo al afectado (S2)
- Al restablecer su contraseña (con el plazo de vencimiento) y cada vez que **cambia** su contraseña (cambio propio u
  "olvidé mi contraseña"), la persona recibe un correo ("si no fuiste tú, avisa al administrador"). Idioma según sus
  preferencias (es/en, español por defecto). **Nunca** incluye contraseñas.
- Los avisos van a **cola** (`Mail::queue`, worker de Horizon): el reset masivo no depende del SMTP síncrono ni queda a medias
  si el servidor de correo tarda o falla (R3-7). Un fallo al encolar o enviar no deshace la operación (se registra sin datos
  sensibles). Si los correos no salen, revisar el worker de colas y `failed_jobs`, no el reset.
- En **dev y test** el correo no sale a personas reales: va a Mailpit (contenedor `infra-mailpit-1`, interfaz web del
  contenedor) y es ahí donde se ve.

## Re-autenticación del operador (H3)
- La API exige `operator_password` (la contraseña actual del operador) para **aplicar el masivo**, para resetear a una
  **cuenta de máximo privilegio** (rol superadmin, nivel 10 o `superadmin.all`), para cambiar el interruptor y la caducidad en
  Configuración global (R3-6) y para un reset individual de una persona normal **una vez superado el tope por hora** (R3-1).
  No se pide en la simulación.
- **Tope de resets individuales sin contraseña (R3-1):** hasta `password_reset_free_per_hour` (ajuste global, **5 por defecto**,
  0 = siempre pide contraseña) resets individuales de personas normales por hora y por operador; del siguiente en adelante la
  API exige `operator_password` hasta que se libere la ventana de 1 hora. Evita encadenar resets individuales sin contraseña con
  un token robado.
- Errores: `422 operator_password_required` (falta), `422 operator_reauth_failed` (incorrecta).
- En **cada** llamada de reset el rol del operador se revalida contra la BD: si ya no es superadmin (o está inactivo) el
  token emitido antes no sirve (`403 operator_role_revoked`).
- Las contraseñas de operador incorrectas se limitan **por usuario**: por defecto 5 fallos en 15 minutos y luego
  `429 too_many_attempts` (con `retry_after`) aunque la siguiente sea correcta. El cupo se **reserva antes de comparar** la
  contraseña (incremento atómico): una ráfaga paralela no multiplica los intentos. El límite general de peticiones a estas
  rutas también es por usuario, no por IP.
- El comando por ssh **no** pide contraseña: quien tiene shell del servidor ya tiene esa autoridad; conserva la
  confirmación escrita y `--operador`. La auditoría registra `reautenticado` (nunca la contraseña).

## Límites de intentos de autenticación (H4)
Valores en `config/throttle.php` (todos configurables por variable de entorno `THROTTLE_*`). Respuesta `429`
`{"code": "too_many_attempts", "retry_after": segundos}` + cabecera `Retry-After`. **No hay bloqueo permanente de cuentas**:
el cupo vuelve solo al vencer la ventana.

| Ruta | Qué cuenta | Tope por defecto |
|---|---|---|
| `POST /api/auth/login` | solo intentos fallidos | 10/min por IP y 5/min por correo normalizado |
| `POST /api/auth/refresh` | solo refresh tokens inválidos | 30/min por IP |
| `POST /api/auth/mfa/verify` | solo códigos erróneos | 10/min por IP y 5 por usuario en 10 min (reenviar **no** lo reinicia); ambos topes responden `429` con `retry_after` |
| `POST /api/auth/mfa/resend` | cada reenvío | 3 por usuario en 10 min |
| `POST /api/auth/login` con MFA activo | cada código enviado por un login correcto | 5 por usuario en 10 min (`THROTTLE_MFA_SEND_*`) |
| `POST /api/auth/forgot-password` | todas las solicitudes (existan o no los correos) | 5 por 15 min por IP y 3 por hora por correo |

Todos los contadores se incrementan **antes** de evaluar la petición (atómico) y las operaciones correctas devuelven su cupo,
así que una ráfaga paralela no multiplica los intentos y solo cuentan los fallos. Un login correcto no consume cupo y libera el contador de ese correo, así que las personas detrás de una misma IP no se
bloquean al abrir sesión a la vez. La respuesta no revela si un correo existe. Contrapartida aceptada: quien conozca un
correo puede provocar un `429` de un minuto en su login (denegación temporal, no toma de cuenta).

## Riesgos aceptados (documentados, no corregidos)
- **R3-2 — bloqueo temporal por correo conocido:** quien conozca el correo de alguien puede agotar su tope de login
  (5/min) o de "olvidé mi contraseña" (3/hora) y bloquearle temporalmente el acceso o la recuperación. Es la contrapartida
  aceptada de limitar por correo (la alternativa, no limitar por correo, deja fuerza bruta distribuida por IP). No es toma de
  cuenta y no hay bloqueo permanente: el cupo vuelve solo. Si ocurre durante la ventana de un reset, el reset individual por
  CLI/API sigue disponible y "olvidé mi contraseña" vuelve a funcionar al vencer la ventana.
- **R3-3 — la IP real debe llegar al limitador:** los topes por IP dependen de que `request()->ip()` sea la IP del cliente
  (nginx/proxy de confianza configurado) y de que `CACHE_STORE=redis` (con `array` o `file` los contadores no se comparten entre
  procesos y el límite no se aplica). Verificarlo en cada entorno antes de confiar en los límites (checklist de abajo).

## Checklist previa al primer reset real en producción (DevOps + Backend + Luis)
**1. DevOps verifica en prod (sin cambiar nada):**
- `APP_ENV=production` y `CACHE_STORE=redis` en el `.env` efectivo del contenedor `laravel` (y que Redis responda).
- `request()->ip()` devuelve la IP del cliente (no la del proxy/nginx): comprobarlo con un `429` de prueba desde una IP
  conocida o revisando `audit_logs.ip_address` de un login reciente.
- El interruptor aparece **apagado**: `GET /api/admin/global-settings` → `password_reset_enabled.value = 0`.
- Las suscripciones SNS de alertas (`atlanticerp-alerts`) están **confirmadas** (ninguna en `PendingConfirmation`).
- El worker de colas (Horizon) está activo: los avisos por correo se envían desde la cola.

**2. Backend/Luis:** R3-1 y R3-6 ya están cerrados en código; R3-2 queda aceptado (sección anterior) salvo que Luis indique lo contrario por escrito.

**3. Ventana controlada:** dos superadmins con acceso al CLI; encender el interruptor solo durante la ventana (pide contraseña);
fijar la caducidad en 24–48 h.

**4. Tandas:** máximo **10 personas** por tanda, empezando por cuentas con MFA; revisar `audit_logs`
(`auth.user.password_reset`, intentos fallidos, `auth.failed`) entre tandas antes de seguir.

**5. Cierre:** confirmar que **no queda ninguna cuenta caducada sin atender** (`must_change_password = true` con
`password_reset_at` vencido) y **apagar el interruptor** (`{"value": 0, "operator_password": "…"}`).

## Verificación
- Simulación: el listado coincide con lo esperado y no hay cambios en BD.
- Tras aplicar: la persona ingresa con su correo como contraseña, ve la pantalla de cambio obligatorio y, al cambiarla,
  el indicador queda en `false`.
- Auditoría: `auth.user.password_reset` por cada usuario (sin contraseñas ni hashes en `old_values`/`new_values`/`metadata`).
