# Fase 12c — activación de cuenta por enlace (resultado)

Estado: **implementada** (backend; frontend en el PR hermano). Spec madre: `fase12-seguridad-y-separacion-de-funciones.md` §2 y §5-5.

## Decisión de Luis (2026-10-30)
Una cuenta nueva o reiniciada ya **no tiene clave utilizable**: antes era el propio correo (y no caducaba), así que quien conociera el correo entraba como la persona hasta que esta la cambiara. Vigencia del enlace: **7 días, reenviable**. El reinicio por superadmin (individual y masivo) hace lo mismo que el alta.

## Cómo funciona
- `UserService::create` y `AdminPasswordResetService::resetUser` guardan una clave aleatoria que nadie conoce (`Str::random(64)`), dejan `must_change_password = true` y llaman a `PasswordResetService::sendActivationLink`.
- El enlace reutiliza el mecanismo de «restablecer contraseña» (tabla de tokens con hash SHA-256, un solo uso, token en el fragmento `#token=` para que no llegue a los logs, página `/reset-password`, endpoint `POST /api/auth/reset-password`). Emitir uno nuevo anula los anteriores de esa cuenta. Cuentas inactivas o sin acceso (`NoLoginAccounts`) no reciben enlace.
- Vigencia = ajuste global `password_reset_expiration_hours` (1 a 720 h, editable por superadmin en Configuración global → Global); su valor por defecto pasó de **48 a 168**.
- Correo: `AccountActivationMail` (cola, cifrado en la cola, reintentos, idioma del perfil). En local `MAIL_MAILER=log`: el enlace queda en el log.
- Reenvío: `POST /api/users/{id}/activation-link` (permiso de gestión de usuarios; 422 si la cuenta ya eligió su clave) y `pending_activation` en la lista de usuarios.
- Soporte y e2e sin correo: `php artisan users:activation-link {slug} {email}` imprime un enlace nuevo (no envía nada ni toca la clave).

## Qué cambió y qué no
- Retirados `WelcomeMail` y su vista, y `PasswordNotifier::notifyAdminReset` (el aviso «tu contraseña pasó a ser tu correo»).
- **Las cuentas de demostración sembradas conservan clave = correo** (solo local/dev/testing; `qa` y `production` no siembran usuarios). Las cuentas reiniciadas ANTES de este cambio también: la caducidad de la clave temporal (H2) sigue protegiéndolas y sus tests quedaron como estaban, ahora con esas cuentas armadas a mano.
- `tenant:create-superadmin` no cambia: ya genera una contraseña aleatoria mostrada una sola vez.

## Abierto
- Producción necesita un proveedor de correo real: con `MAIL_MAILER=log` nadie recibe el enlace (el soporte lo obtiene con el comando).
- El correo de bienvenida ya no menciona el nombre de la aplicación en el asunto «CRM»; el texto del enlace es genérico. Conviene revisarlo con quien escriba los textos finales.
