# Flujo: recuperación de clave y segundo factor (MFA)

Continuación de [Transversales](transversales.md) (§7 «sin ejercitar»). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 y ampliado el 2026-11-06 con la aplicación autenticadora (`npm run e2e:flujos`, archivo `mfa-recuperacion-clave.spec.ts`, 8 pasos). El usuario de prueba se crea en cada corrida con un correo propio.

**En local los correos no salen:** `MAIL_MAILER=log` deja el código y el enlace en `storage/logs/laravel.log` del contenedor (la cola de Horizon los escribe unos segundos después). El e2e los lee de ahí (`E2E_MAIL_LOG_CMD` lo reemplaza) y limpia los topes con `cache:clear` antes de empezar (`E2E_THROTTLE_RESET_CMD`), porque «olvidé mi contraseña» admite 5 solicitudes por IP cada 15 minutos.

## 1. «Olvidé mi contraseña» (`POST auth/forgot-password`, `auth/reset-password`)
- Responde **200 con el mismo mensaje** exista o no el correo; un formato inválido, 422 [E].
- El enlace llega como `…/reset-password#token=<64 hex>` (el token va en el fragmento, no queda en el log de nginx) y dura **1 hora** [E][C]. Un segundo pedido **anula** el enlace anterior [E].
- Reglas de la clave nueva: 8 caracteres o más, mayúscula, minúscula y número, confirmación igual; un enlace inventado, vencido o ya usado da 422 con el mismo mensaje [E]. Distinta del correo [C].
- Al restablecer: el enlace queda gastado, la clave vieja deja de servir, la nueva entra, se **revocan los refresh tokens** del usuario y se avisa al dueño por correo [E][C].
- Una cuenta recién creada (pendiente de activación) usa este mismo camino para elegir su clave; al elegirla se cierra el cambio obligatorio [E]. Las cuentas sin acceso (técnico, repartidor, personal de bodega) no reciben enlace y la respuesta es idéntica [C].
- Topes (sin bloqueo permanente, el cupo vuelve solo): 5 solicitudes por IP cada 15 min y 3 por correo cada hora, contando también los correos inexistentes. Al pasarse: **429** `too_many_attempts` con `retry_after` [E].

## 2. Activar el segundo factor
- El segundo factor por defecto es un **código de 6 dígitos enviado al correo**, vigente 10 minutos [C]. Desde la Fase 27c también existe la **aplicación autenticadora** con códigos de respaldo (§7).
- `POST auth/mfa/enable {password}` pide la clave vigente (equivocada, 422) y envía el código; no activa nada todavía [E].
- `POST auth/mfa/enable/confirm {mfa_challenge_id, code}`: el formato (6 dígitos) y el código se validan (422); con el correcto queda `mfa_enabled: true`; el mismo código no se vuelve a aceptar [E].
- Un reto generado por una sesión no lo confirma otra: 403 «La verificación no corresponde a tu sesión» [E].

## 3. Ingresar con MFA
- El login correcto responde `requires_mfa: true` y un `mfa_challenge_id`, **sin token**; el código llega al correo [E].
- `POST auth/mfa/resend` entrega un reto nuevo y **anula el anterior**; `POST auth/mfa/verify` con el código correcto entrega token, refresh token y el usuario; un código usado no sirve de nuevo [E].
- Topes: reenvíos 3 cada 10 min por usuario, códigos enviados por logins 5 cada 10 min, errores 10 por IP cada minuto [C].
- **Desactivar** (Fase 27a): pide la clave vigente y un código; con correo, el que se envía tras `POST auth/mfa/disable`; con aplicación, el de la aplicación o un respaldo (§7) [E].
- El reto responde `mfa_method` (`email` o `totp`); con `totp` no se envía correo y no hay reenvío [E].

## 4. Intentos equivocados
- Cinco códigos equivocados sobre el mismo reto lo agotan (el quinto avisa «Demasiados intentos»; el sexto, «inválido o expirado»); reenviar da un reto nuevo [E].
- Además hay un **tope por usuario de 5 errores cada 10 minutos** que reenviar no reinicia: pasado ese tope, verificar o confirmar responde **429** con `retry_after` aunque el código sea el bueno [E].

## 5. Reiniciar el MFA de otra persona
`POST admin/security/users/{id}/mfa/reset` (Fase 27a): superadmin y Gerencia (`settings.global`); Gerencia no reinicia a un superadmin ni a sí misma (403); otra vez sobre la misma cuenta, 422 «no tiene MFA activo»; usuario inexistente, 404; la cuenta vuelve a entrar sin código, el reinicio **limpia los topes** del §4, queda auditado y se avisa por correo [E]. Con aplicación autenticadora, el reinicio también **borra el secreto y los respaldos** y vuelve el método a `email` [E].

## 6. Modo global (`PATCH settings/system {mfa_mode}`)
- Valores `disabled`, `optional` (por defecto) y `required`; cualquier otro, 422. Lo cambian quienes tienen `settings.global` (Gerencia y superadmin); el vendedor, 403 [E].
- En **obligatorio** todos reciben código al entrar, aunque no lo hayan activado, y `mfa/status` lo informa; al volver a opcional entran directo [E]. `disabled` solo oculta el auto-servicio: quien ya lo activó lo conserva [C].

## 7. Aplicación autenticadora y códigos de respaldo (Fase 27c)
- **Método por persona**: `email` (por defecto) o `totp` (RFC 6238: SHA-1, 6 dígitos, paso de 30 s, tolerancia ±1 paso, calculado sin dependencias externas y comprobado contra los vectores del RFC). La política global (`mfa_mode`) no cambia: `required` exige un segundo factor de cualquiera de los dos tipos [C].
- **Alta**: `POST auth/mfa/totp/setup {password}` (clave equivocada, 422) devuelve el secreto, la URI `otpauth://` y el QR (PNG en data URI); no activa nada y guarda el secreto como **pendiente** (cifrado). `POST auth/mfa/totp/confirm {code}`: un código malo da 422 y no activa; con el bueno queda `mfa_method: totp` y llegan **10 códigos de respaldo** (`XXXXX-XXXXX`), la única vez que se ven. Con el correo ya activo, el alta exige además la prueba del método vigente (`POST auth/mfa/proof {password}` envía el código por correo; se manda con `mfa_challenge_id` y `code` a `totp/setup`) [E].
- **Ingreso**: el login responde `requires_mfa` y `mfa_method: totp` **sin enviar correo** (sigue funcionando aunque el correo falle); reenviar da 422. `mfa/verify` acepta el código de la aplicación o un respaldo (con o sin guion, en cualquier caso) [E].
- **Anti-repetición**: un paso de 30 s ya aceptado, o uno anterior, no vuelve a servir (actualización atómica de `mfa_totp_last_step`). Consecuencia práctica: tras confirmar el alta hay que esperar al siguiente código de la aplicación para entrar [E].
- **Respaldos**: un respaldo sirve una sola vez (atómico; en BD solo su huella HMAC-SHA256), cada uso queda en la auditoría con cuántos quedan, `mfa/status` informa `backup_codes_remaining` [E].
- **Acciones sensibles** (desactivar, regenerar respaldos): piden la clave **y** el código de la aplicación (o un respaldo); con código malo, 422 y nada cambia. `POST auth/mfa/backup-codes/regenerate {password, code}` entrega 10 nuevos e invalida los anteriores [E].
- **Topes**: los mismos de §4 (por reto, por usuario y por IP); la activación, la desactivación y la regeneración tienen cada una su tope por usuario, separado del de ingreso [E].
- **Secreto**: cifrado en BD (cast `encrypted`), fuera de `$hidden`, de las respuestas posteriores y de la auditoría (`LogsAuditTrail` lo excluye); los eventos auditados son `security.mfa.totp_enabled`, `totp_disabled`, `backup_regenerated` y `backup_used`, sin secreto ni códigos [E].
- **Recuperación**: quien pierde el teléfono y los respaldos pide el reinicio a Gerencia o superadmin (§5) [E].
- **Interfaz**: Ajustes → verificación en dos pasos (alta con QR y clave tecleable, lista de respaldos con copiar/descargar y confirmación de que se guardaron, regenerar, desactivar) y el paso de ingreso con «usar un código de respaldo» [C].

## Candidatos a cambio o mejora
1. ~~**Confirmar con la sesión equivocada gasta el código**: la comprobación de pertenencia ocurre después de validar el código, que queda usado; la persona dueña del reto recibe «inválido o expirado» y debe pedir otro. Exige tener el reto y el código, así que el riesgo es bajo. [E]~~ **Resuelto: Fase 27a** (el desafío ajeno ya no gasta el código).
2. ~~**Desactivar el MFA no pide clave ni código**: una sesión robada basta para quitar el segundo factor. [E]~~ **Resuelto: Fase 27a** (desactivar pide clave y código por correo).
3. ~~**El tope por usuario también bloquea la reactivación** y el reinicio por admin no lo limpia: tras cinco errores la persona espera 10 minutos aun con soporte de por medio. [E]~~ **Resuelto: Fase 27a** (el reinicio por admin limpia los topes y la reactivación no se bloquea por el tope de ingreso).
4. ~~**El segundo factor es el mismo buzón que la recuperación de clave**: quien controla el correo restablece la clave y recibe el código, así que el MFA por correo no protege contra el robo del buzón. Sin aplicación autenticadora ni códigos de respaldo; si el correo falla (503), nadie entra. [E][C]~~ **Resuelto: Fase 27c** (aplicación autenticadora con respaldos; con ella el correo ya no es el segundo factor ni hace falta para entrar). Quien siga con el método por correo conserva el riesgo.
5. ~~**El texto del correo de activación dice «iniciar sesión en AtlanticERP CRM»** (se reutiliza el de ingreso, y menciona un CRM que ya no existe como módulo aparte). [E]~~ **Resuelto: Fase 27a** (texto del correo corregido a «AtlanticERP»).
6. ~~**Gerencia cambia la política global de MFA pero no reinicia el MFA de una persona**: puede volverlo obligatorio para todos sin poder ayudar a quien se quede sin acceso. [E]~~ **Resuelto: Fase 27a** (Gerencia puede reiniciar el MFA de otra persona (no de superadmin ni propio), con auditoría y aviso por correo).
7. ~~**Los topes de «olvidé mi contraseña» son por IP**: una oficina entera detrás de la misma IP comparte 5 solicitudes cada 15 minutos. [C]~~ **Resuelto: Fase 27a** (tope por IP subido a 20 cada 15 min; se conservan 3 por correo por hora).
8. **Sin ejercitar**: la pantalla (formularios y mensajes del navegador), la caducidad real del enlace y del código (1 hora y 10 minutos) y la entrega real del correo (en local solo el log). [C]
9. **Abiertos de la 27c**: (a) cifrado y huella de respaldos dependen de `APP_KEY`: rotarla invalida los secretos TOTP y los respaldos (hay que reiniciar el MFA de quien lo use; leer un secreto que ya no se descifra hoy rompería el ingreso de esa persona); (b) no se avisa por correo cuando alguien activa o desactiva su aplicación, ni cuando se gasta un respaldo (solo auditoría); (c) cada ingreso correcto con TOTP crea una fila de reto en `mfa_codes` (como el correo) y no hay limpieza periódica; (d) `mfa/proof` envía un correo por llamada (sin tope propio, igual que `disable` antes). [C]
