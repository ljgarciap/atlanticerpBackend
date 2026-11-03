# Flujo: recuperación de clave y segundo factor (MFA)

Continuación de [Transversales](transversales.md) (§7 «sin ejercitar»). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 (`npm run e2e:flujos`, archivo `mfa-recuperacion-clave.spec.ts`, 7 pasos). El usuario de prueba se crea en cada corrida con un correo propio.

**En local los correos no salen:** `MAIL_MAILER=log` deja el código y el enlace en `storage/logs/laravel.log` del contenedor (la cola de Horizon los escribe unos segundos después). El e2e los lee de ahí (`E2E_MAIL_LOG_CMD` lo reemplaza) y limpia los topes con `cache:clear` antes de empezar (`E2E_THROTTLE_RESET_CMD`), porque «olvidé mi contraseña» admite 5 solicitudes por IP cada 15 minutos.

## 1. «Olvidé mi contraseña» (`POST auth/forgot-password`, `auth/reset-password`)
- Responde **200 con el mismo mensaje** exista o no el correo; un formato inválido, 422 [E].
- El enlace llega como `…/reset-password#token=<64 hex>` (el token va en el fragmento, no queda en el log de nginx) y dura **1 hora** [E][C]. Un segundo pedido **anula** el enlace anterior [E].
- Reglas de la clave nueva: 8 caracteres o más, mayúscula, minúscula y número, confirmación igual; un enlace inventado, vencido o ya usado da 422 con el mismo mensaje [E]. Distinta del correo [C].
- Al restablecer: el enlace queda gastado, la clave vieja deja de servir, la nueva entra, se **revocan los refresh tokens** del usuario y se avisa al dueño por correo [E][C].
- Una cuenta recién creada (pendiente de activación) usa este mismo camino para elegir su clave; al elegirla se cierra el cambio obligatorio [E]. Las cuentas sin acceso (técnico, repartidor, personal de bodega) no reciben enlace y la respuesta es idéntica [C].
- Topes (sin bloqueo permanente, el cupo vuelve solo): 5 solicitudes por IP cada 15 min y 3 por correo cada hora, contando también los correos inexistentes. Al pasarse: **429** `too_many_attempts` con `retry_after` [E].

## 2. Activar el segundo factor
- El segundo factor es un **código de 6 dígitos enviado al correo**, vigente 10 minutos [C]. No hay aplicación autenticadora ni códigos de respaldo.
- `POST auth/mfa/enable {password}` pide la clave vigente (equivocada, 422) y envía el código; no activa nada todavía [E].
- `POST auth/mfa/enable/confirm {mfa_challenge_id, code}`: el formato (6 dígitos) y el código se validan (422); con el correcto queda `mfa_enabled: true`; el mismo código no se vuelve a aceptar [E].
- Un reto generado por una sesión no lo confirma otra: 403 «La verificación no corresponde a tu sesión» [E].

## 3. Ingresar con MFA
- El login correcto responde `requires_mfa: true` y un `mfa_challenge_id`, **sin token**; el código llega al correo [E].
- `POST auth/mfa/resend` entrega un reto nuevo y **anula el anterior**; `POST auth/mfa/verify` con el código correcto entrega token, refresh token y el usuario; un código usado no sirve de nuevo [E].
- Topes: reenvíos 3 cada 10 min por usuario, códigos enviados por logins 5 cada 10 min, errores 10 por IP cada minuto [C].
- **Desactivar**: `POST auth/mfa/disable` basta con la sesión (sin clave ni código) [E].

## 4. Intentos equivocados
- Cinco códigos equivocados sobre el mismo reto lo agotan (el quinto avisa «Demasiados intentos»; el sexto, «inválido o expirado»); reenviar da un reto nuevo [E].
- Además hay un **tope por usuario de 5 errores cada 10 minutos** que reenviar no reinicia: pasado ese tope, verificar o confirmar responde **429** con `retry_after` aunque el código sea el bueno [E].

## 5. Reiniciar el MFA de otra persona
`POST admin/security/users/{id}/mfa/reset`: **solo superadmin** (Gerencia, 403); otra vez sobre la misma cuenta, 422 «no tiene MFA activo»; usuario inexistente, 404; la cuenta vuelve a entrar sin código y queda auditado [E]. **No limpia el tope por usuario del §4**: la persona no puede volver a activarlo hasta que venza la ventana (429) [E].

## 6. Modo global (`PATCH settings/system {mfa_mode}`)
- Valores `disabled`, `optional` (por defecto) y `required`; cualquier otro, 422. Lo cambian quienes tienen `settings.global` (Gerencia y superadmin); el vendedor, 403 [E].
- En **obligatorio** todos reciben código al entrar, aunque no lo hayan activado, y `mfa/status` lo informa; al volver a opcional entran directo [E]. `disabled` solo oculta el auto-servicio: quien ya lo activó lo conserva [C].

## Candidatos a cambio o mejora
1. **Confirmar con la sesión equivocada gasta el código**: la comprobación de pertenencia ocurre después de validar el código, que queda usado; la persona dueña del reto recibe «inválido o expirado» y debe pedir otro. Exige tener el reto y el código, así que el riesgo es bajo. [E]
2. **Desactivar el MFA no pide clave ni código**: una sesión robada basta para quitar el segundo factor. [E]
3. **El tope por usuario también bloquea la reactivación** y el reinicio por admin no lo limpia: tras cinco errores la persona espera 10 minutos aun con soporte de por medio. [E]
4. **El segundo factor es el mismo buzón que la recuperación de clave**: quien controla el correo restablece la clave y recibe el código, así que el MFA por correo no protege contra el robo del buzón. Sin aplicación autenticadora ni códigos de respaldo; si el correo falla (503), nadie entra. [E][C]
5. **El texto del correo de activación dice «iniciar sesión en AtlanticERP CRM»** (se reutiliza el de ingreso, y menciona un CRM que ya no existe como módulo aparte). [E]
6. **Gerencia cambia la política global de MFA pero no reinicia el MFA de una persona**: puede volverlo obligatorio para todos sin poder ayudar a quien se quede sin acceso. [E]
7. **Los topes de «olvidé mi contraseña» son por IP**: una oficina entera detrás de la misma IP comparte 5 solicitudes cada 15 minutos. [C]
8. **Sin ejercitar**: la pantalla (formularios y mensajes del navegador), la caducidad real del enlace y del código (1 hora y 10 minutos) y la entrega real del correo (en local solo el log). [C]
