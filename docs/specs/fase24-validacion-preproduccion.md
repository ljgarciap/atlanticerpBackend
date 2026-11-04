# Fase 24 — Validación previa a producción (D)

**Estado:** en borrador. Sin credenciales todavía: lo que se entrega es lo que se puede verificar sin ellas.

## 1. Objetivo
Confirmar contra servicios reales lo que hasta hoy solo se probó con respuestas simuladas: el adaptador de Digifact (`DigifactPacAdapter`) y el envío de correo.

## 2. Decisiones de Luis (2026-11-04)
- Aún no hay credenciales de Digifact: se entrega la herramienta de verificación y el procedimiento; Luis (o yo con sus credenciales) lo ejecuta después.
- Correo: se valida con un SMTP de prueba (Mailtrap o similar), no con un buzón real.

## 3. Entregable en esta fase
- Comando `php artisan fiscal:validate-digifact [--ambiente=pruebas] [--ruc=…]`: pide un token nuevo y, con `--ruc`, consulta ese receptor. **Solo lectura**: no certifica ni emite. Producción exige `--allow-production`. No imprime el token ni la clave. Salida 0 si todo respondió bien.
- Tests con respuestas simuladas (`FiscalValidateDigifactCommandTest`).
- El correo ya tiene `php artisan mail:test {destinatario}`.

## 4. Procedimiento cuando haya credenciales (sin git)
1. En el `.env` local: `DIGIFACT_RUC`, `DIGIFACT_USERNAME`, `DIGIFACT_PASSWORD`, `DIGIFACT_BRANCH_CODE` (del sandbox de pruebas).
2. `fiscal:validate-digifact --ruc=<RUC de prueba>` → debe dar «Token obtenido» y un receptor válido. Si el nombre del campo del token o de la consulta no coincide con la respuesta real, el comando lo muestra: ajustar el adaptador y añadir la regresión.
3. En la app: `tenant:set-region`/`admin-contab:set-pac-provider digifact`, ambiente `pruebas`, y certificar una factura y una nota de crédito. Revisar `fe_transmisiones` (payload y respuesta) y qué estado devuelve `normalizeEstado()` — es la parte menos confirmada.
4. Correo: `.env` con el SMTP de prueba (`MAIL_MAILER=smtp`, host/puerto/usuario/clave) → `mail:test destino@…`; luego el enlace de activación de cuenta (`users:activation-link` o alta de usuario) y el envío de una factura.

## 5. Fuera de alcance
Certificar en producción (emite documentos fiscales reales: requiere aprobación individual por transmisión) y el adaptador de la DIAN (Colombia).

## 6. Criterios de aceptación
- El comando falla limpio sin credenciales, no llama a la red en ese caso y nunca expone secretos.
- Con credenciales de pruebas válidas: token y consulta de RUC correctos; certificación de factura y nota de crédito en `certificado` o con errores legibles.
- Hallazgos del sandbox → corrección con regresión, o candidato documentado.
