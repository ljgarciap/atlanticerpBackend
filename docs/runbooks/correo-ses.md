# Runbook — Correo de la aplicación por Amazon SES (SCRUM-1178)

Última actualización: 2026-10-20. Quien lo ejecute en producción puede no ser quien lo construyó: este documento es autosuficiente.

## Qué hay construido

- Mailer `ses`: transporte propio `ses-role` (`App\Shared\Mail\SesRoleTransport`). Usa **el rol IAM de la instancia** (`atlanticerp-cloudwatch-agent`, compartido por dev/test/prod) y **ignora** `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` del entorno (son del bucket S3 y no tienen permiso de SES).
- Mailer `ses_respaldo`: failover `ses` → `smtp` (el SMTP actual, Microsoft 365 en prod).
- El correo de restablecer contraseña va en cola (`ShouldQueue`; 4 intentos; esperas 30/120/300 s). El token viaja en el fragmento `#token=` (no en `?token=`, que quedaba en el `access.log` de nginx). El idioma del correo del enlace y del aviso de «contraseña cambiada» sigue `preferences.language` del perfil (es/en).
- Registro público cerrado (SCRUM-1177): no existe `POST /register` ni la pantalla; las cuentas las crea un administrador.

## AWS ya aplicado (región us-east-1, cuenta 940827433405)

- Política en línea `atlanticerp-ses-send` del rol `atlanticerp-cloudwatch-agent`: `ses:SendEmail` y `ses:SendRawEmail` sobre `identity/atlanticerp@atlantic.com.pa`, `identity/atlantic.com.pa` y `configuration-set/atlanticerp-correo`, con la condición `ses:FromAddress = atlanticerp@atlantic.com.pa`.
  Reversión: `aws iam delete-role-policy --role-name atlanticerp-cloudwatch-agent --policy-name atlanticerp-ses-send`.
- Conjunto de configuración `atlanticerp-correo`: métricas de reputación y destino de eventos a CloudWatch (envíos, entregas, rebotes, quejas, rechazos).
- Alarmas hacia el tema SNS `atlanticerp-alerts`: `atlanticerp-ses-rebotes-tasa-alta` (≥3 %), `atlanticerp-ses-quejas-tasa-alta` (≥0,05 %), `atlanticerp-ses-rebotes-conteo` (≥3 en 1 h), `atlanticerp-ses-queja-conteo` (≥1 en 5 min).
- SES con acceso de producción, DKIM del dominio en SUCCESS, DMARC `p=reject` (verificado en Gmail: SPF, DKIM y DMARC en PASS).

## Regla: SES real solo en producción

Dev y test **siempre enviaron a Mailpit** (`MAIL_HOST=mailpit`): por diseño ahí no sale correo a personas reales. Activar SES en esos entornos permitiría enviar órdenes de compra o avisos reales desde datos de prueba. Se probó el 2026-10-20 y **se revirtió por decisión de Luis**: dev y test quedan en `MAIL_MAILER=smtp`. QA lee los correos en Mailpit. No activar SES en dev/test sin la orden explícita de Luis. (Test además tiene `MAIL_FROM_ADDRESS=hello@atlanticerp.ai`, que SES rechaza.)

## Plan para producción

Cada paso requiere el OK explícito de Luis. Regla de Luis: el cambio en prod debe tener certeza de no romper el correo actual.

1. **Leer** (solo lectura) el `.env` de prod: `MAIL_MAILER` (esperado `smtp`), `MAIL_HOST` (`smtp.office365.com`) y **`MAIL_FROM_ADDRESS`, que debe ser `atlanticerp@atlantic.com.pa`** (con otra dirección la política no autoriza el envío). Confirmar la política: `aws iam get-role-policy --role-name atlanticerp-cloudwatch-agent --policy-name atlanticerp-ses-send`.
2. **Promover y desplegar el código** (`test → master`; frontend primero y luego backend; `infra/deploy-manual.sh prod` en cada repo). No cambia el correo: el mailer sigue en `smtp`. Es un push completo: también viaja todo lo demás que haya en `test`.
3. **Probar el envío por SES sin cambiar el mailer**, desde el contenedor de prod por tinker:
   `Mail::mailer('ses')->raw('Prueba SES prod', fn($m) => $m->to('<buzón de Luis>')->subject('Prueba SES AtlanticERP prod'));`
   Debe responder sin excepción y llegar. Repetir con un Gmail y revisar SPF/DKIM/DMARC.
4. **Activar**: en el `.env` de prod (copia previa `.env.bak-ses-AAAAMMDD`; el cambio lo ejecuta Luis con un script) poner `MAIL_MAILER=ses_respaldo` y `SES_CONFIGURATION_SET=atlanticerp-correo`, y **recrear los contenedores**:
   `cd /var/www/backend/infra && docker compose -f docker-compose.prod.yml up -d laravel horizon`
   **No basta editar el `.env`**: los contenedores leen el `.env` al crearse y el `MAIL_MAILER` ya presente en su proceso gana sobre el archivo. Hay unos segundos de corte. Verificar: `php artisan tinker --execute="echo config('mail.default');"` debe decir `ses_respaldo`.
5. **Verificar** con un restablecimiento de contraseña y un código MFA reales: Horizon en DONE, sin `failed_jobs` nuevos, sin «Transport "ses-v2" failed» en `storage/logs/laravel.log`, y métricas `Send`/`Delivery` del conjunto `atlanticerp-correo` en CloudWatch.
6. **Validación de QA en producción con SES** (decisión de Luis 2026-10-20: la revisión de QA del correo se hace en prod, no en test): al desplegar, pedir a QA (Marly) que pruebe con SES real un restablecimiento de contraseña y un código MFA, y que confirme llegada, idioma y enlace. No dar por cerrado SCRUM-1178 sin esa validación.
7. **Reversión inmediata**: `MAIL_MAILER=smtp` en el `.env`, recrear `laravel` y `horizon`.

Si SES falla en producción, el respaldo envía por Microsoft 365 solo; el fallo queda en `laravel.log`.

## Gotchas

- El SDK de AWS prefiere las claves del entorno al rol de la instancia: por eso existe `SesRoleTransport`.
- SES autoriza contra la identidad de la **dirección** antes que contra el dominio: la política lista ambas.
- Con un conjunto de configuración, la política debe incluir también el ARN del conjunto.
- El envío "ENVIADO" por tinker solo prueba que SES lo aceptó; hay que confirmar la llegada.
- Revisar los comandos SSH con `< /dev/null` en cada `docker compose exec -T` dentro de scripts.
- **Lista de supresión de SES**: si el correo de una persona rebotó o se marcó como spam, SES deja de entregarle en silencio y el restablecimiento "parece enviado" sin llegar. Consultar: `aws sesv2 get-suppressed-destination --email-address <correo> --region us-east-1`; retirar (solo con motivo): `aws sesv2 delete-suppressed-destination --email-address <correo> --region us-east-1`.
- El correo de restablecer viaja cifrado en la cola (`ShouldBeEncrypted`): en Horizon y en `failed_jobs` no se ve el token en claro. Revisión de seguridad: `docs/security/scrum1178-revision-seguridad-20261020.md`.
