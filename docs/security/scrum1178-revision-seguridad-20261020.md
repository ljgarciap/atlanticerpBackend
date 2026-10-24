# Revisión de seguridad — Correo por Amazon SES con rol de instancia y cierre del registro (SCRUM-1177 / SCRUM-1178)

Fecha: 2026-10-20. Rol: Cybersecurity. Alcance: backend `8202c102`…`e88dcde6` y frontend `5126da8`, más la configuración de AWS aplicada (política `atlanticerp-ses-send`, conjunto `atlanticerp-correo`, 4 alarmas). Método: lectura de código, verificación de comportamiento en dev (envíos reales a una bandeja de Atlantic y a Gmail, encabezados) y revisión de la política IAM. Nada se ejecutó contra producción.

## 1. Resultado

0 críticos, 0 altos, **1 medio (corregido en esta revisión)**, 3 bajos (recomendaciones) y 3 informativos.

## 2. Hallazgos

| Id | Severidad | Hallazgo | Estado |
|---|---|---|---|
| M1 | Medio | El correo de restablecer pasó a la cola (SCRUM-1178) y su payload serializa el enlace con el **token en claro**. Ese texto quedaba legible en Redis, en el panel de Horizon (que muestra el payload de los trabajos recientes) y en `failed_jobs` si el envío agotaba sus 4 intentos, mientras el token sigue vigente (1 hora). Antes del cambio el envío era síncrono y no pasaba por ahí. | **Corregido**: `PasswordResetMail` implementa `ShouldBeEncrypted`; el payload viaja cifrado con `APP_KEY`. Prueba: `test_el_payload_de_la_cola_del_correo_de_restablecer_va_cifrado_y_no_deja_el_token_legible`. |
| B1 | Bajo | El rol IAM `atlanticerp-cloudwatch-agent` es **compartido por dev, test y prod**. Quien comprometa una instancia de dev podría enviar correo como `atlanticerp@atlantic.com.pa` con la reputación de producción. Mitigaciones existentes: la política solo permite ese remitente y dos acciones de envío, y hay alarmas de rebotes y quejas. | Recomendación: roles por entorno cuando se rehaga la infraestructura de IAM. No bloquea. |
| B2 | Bajo | Con el respaldo `ses_respaldo`, el usuario y la contraseña de Microsoft 365 **siguen en el `.env` de producción** (el objetivo del ticket era dejar de depender de ellos). | Recomendación: tras un período estable con SES (p. ej. 2 semanas sin `Transport "ses-v2" failed`), pasar `MAIL_MAILER=ses`, retirar las credenciales de M365 del `.env` y rotarlas. Decisión de Luis. |
| B3 | Bajo | La cuenta de SES tiene activa la lista de supresión por rebote y queja: si un correo de una persona rebota una vez, SES **deja de entregarle** en silencio (el restablecimiento "parece enviado" y no llega). | Recomendación: añadir al runbook la consulta y retirada (`aws sesv2 get-suppressed-destination` / `delete-suppressed-destination`) para soporte. |
| I1 | Info | Una vía de alta de cuentas sigue en el código sin ruta: `UserService::register()` (la usan las pruebas y ya no la llama ningún controlador). No es alcanzable por HTTP; `POST /register` responde 404 (con prueba). | Aceptado. Se puede eliminar en una limpieza futura. |
| I2 | Info | El destinatario del restablecimiento solo puede ser el correo de una cuenta existente; ninguna ruta pública permite elegir un destinatario libre, así que SES no sirve de relé de spam. | Sin acción. |
| I3 | Info | `SesRoleTransport` ignora a propósito `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` (claves del bucket) y usa el perfil de instancia, salvo que existan `SES_ACCESS_KEY_ID`/`SES_SECRET_ACCESS_KEY` (solo desarrollo local). Ninguna clave nueva se guarda en el servidor. | Sin acción. |

## 3. Lo que quedó mejor que antes

- **Token fuera del `access.log` de nginx**: viaja en el fragmento `#token=`, que el navegador nunca envía al servidor ni en el encabezado `Referer`. La página lo lee y limpia la URL con `history.replaceState`.
- **Enumeración de cuentas**: la respuesta es idéntica exista o no el correo, y como el envío ahora va a cola ya no hay diferencia de latencia entre "existe" y "no existe" (antes el envío síncrono la delataba). Los límites de intentos por IP y por correo siguen vigentes.
- **Errores de envío**: un fallo al encolar no devuelve 500 ni revela nada; se registra solo el id de usuario y la clase de la excepción, nunca el token ni el correo.
- **Remitente suplantable**: DMARC en `p=reject` con SPF y DKIM alineados (verificado en los encabezados de Gmail: los tres en PASS); el rol solo puede enviar como `atlanticerp@atlantic.com.pa`.
- **Registro público cerrado**: no hay autoalta; las cuentas las crea un administrador. Cuentas pendientes previas: prod 0, test 0, dev 1 (cuenta de prueba de QA).
- **Política IAM de mínimo privilegio**: solo `ses:SendEmail` y `ses:SendRawEmail`, sobre tres recursos concretos, con la condición `ses:FromAddress`.

## 4. Recomendaciones antes de activar producción

1. Desplegar este arreglo (M1) junto con el resto: ya está en el commit que se promueve.
2. Añadir B3 al runbook de soporte.
3. Decidir la fecha para retirar las credenciales de M365 (B2).
4. Verificar en producción, tras activar SES, que el `failed_jobs` no contiene tokens legibles (debe verse cifrado).
