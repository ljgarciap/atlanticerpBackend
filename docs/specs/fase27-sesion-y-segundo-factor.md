# Fase 27 — Sesión y segundo factor (MFA)

Estado: **borrador para aprobar (2026-11-06)**. Origen: candidatos 1 a 8 de `docs/flujos/mfa-recuperacion-clave.md`, 3, 5 y 7 de `docs/flujos/transversales.md`, y 7 de `transversales-administracion.md`. Nada se implementa sin el «adelante» de Luis.

## 1. Decisiones de Luis (2026-11-06)
1. **Reinicio del MFA ajeno**: lo puede hacer **Gerencia además de superadmin**. Regla de seguridad añadida por el Arquitecto (revisable): Gerencia no reinicia el MFA de un superadmin ni el suyo propio; todo reinicio queda auditado y avisa por correo a la persona afectada.
2. **Desactivar el MFA propio** exige la clave vigente **y** un código nuevo enviado al correo (o de la aplicación, ver 4).
3. **Topes de «olvidé mi contraseña»**: se mantiene el tope por IP pero sube de 5 a **20** cada 15 min; se conserva **3 por correo cada hora**.
4. **Aplicación autenticadora (TOTP) con códigos de respaldo: incluida** en esta fase.

## 2. Alcance, en entregas (un PR por repositorio por entrega)

### 27a — Arreglos pequeños del MFA por correo
| # | Cambio | Criterio de aceptación |
|---|---|---|
| a1 | `confirmEnable`: comprobar que el reto pertenece a la sesión **antes** de validar/gastar el código (candidato 1) | con un reto ajeno responde 403 y el código sigue vigente para su dueño |
| a2 | `disable` pide `password` + `code` de un reto nuevo (candidato 2). Dos pasos como `enable`: pedir (envía código) y confirmar | sin clave o con código malo, 422 y el MFA sigue activo; sesión robada sin clave no puede desactivar |
| a3 | El tope por usuario deja de bloquear la **activación** (candidato 3) y el reinicio por admin lo **limpia** | tras reiniciar, la persona puede reactivar sin esperar la ventana |
| a4 | Reinicio por Gerencia (decisión 1) con la regla de arriba (candidato 6) | Gerencia reinicia a un vendedor (200); a un superadmin o a sí misma, 403; queda en auditoría y llega el aviso |
| a5 | Texto del correo de código: quitar «AtlanticERP CRM» (candidato 5) y de los otros pies de correo | ningún correo menciona «CRM»; el contrato de textos lo comprueba |
| a6 | Tope por IP de «olvidé mi contraseña» a 20/15 min (candidato 7) | 20 solicitudes pasan, la 21 da 429; el tope por correo sigue en 3/h |

### 27b — Higiene de niveles, roles y «Ver como usuario» (transversales 3, 5; administración 7)
| # | Cambio | Criterio |
|---|---|---|
| b1 | Nombres de nivel de seguridad **únicos** y respetar el `level` pedido (o rechazar si está ocupado) | duplicado → 422; `level` libre se respeta |
| b2 | Motivo de «Ver como usuario»: lista de motivos como catálogo por empresa (`impersonation_reason`) con «Otro» + texto obligatorio | un motivo fuera del catálogo sin texto → 422; el registro de auditoría guarda clave y texto |
| b3 | Un rol propio **sin ninguna visibilidad** muestra aviso al guardarlo (no se bloquea) | la respuesta trae `warnings[rol_sin_visibilidad]` y la pantalla lo muestra |

### 27c — Aplicación autenticadora (TOTP) y códigos de respaldo (candidato 4)
**Diseño** (Arquitecto):
- **Método por persona**: `email` (hoy) o `totp`. La política global (`mfa_mode`) no cambia: `required` sigue exigiendo un segundo factor, de cualquiera de los dos tipos.
- **Biblioteca**: RFC 6238 estándar (SHA-1, 6 dígitos, 30 s, tolerancia ±1 paso). Se evalúa `spomky-labs/otphp` o `pragmarx/google2fa` y se verifica compatibilidad con PHP 8.4/Laravel antes de instalar (regla del workspace). El secreto se guarda **cifrado** (cast `encrypted`), nunca en claro ni en el JWT ni en auditoría.
- **Alta**: `POST auth/mfa/totp/setup {password}` → secreto + URI `otpauth://` (el frontend dibuja el QR); `POST auth/mfa/totp/confirm {code}` activa y devuelve **10 códigos de respaldo** (un solo uso, se muestran una vez; en BD solo su hash).
- **Ingreso**: el reto del login indica `method: totp|email`; `mfa/verify` acepta el código TOTP o un código de respaldo (que se gasta). Un paso TOTP no se acepta dos veces (anti-repetición). Los mismos topes de la Fase 27a aplican (por reto, por usuario y por IP).
- **Cambio de método y desactivación**: piden clave + código del método vigente (o un código de respaldo). Regenerar los códigos de respaldo pide lo mismo e invalida los anteriores.
- **Recuperación**: quien pierde el dispositivo y los respaldos pide el reinicio a Gerencia/superadmin (27a, a4); el reinicio borra secreto y respaldos.
- **Correo caído (503)**: con TOTP la persona entra aunque el correo falle; con método email sigue igual que hoy.
- **Migración** (tenant, aditiva y reversible): `mfa_method` (default `email`), `mfa_totp_secret` (nullable, cifrado), `mfa_totp_last_step` (nullable), tabla `auth_mfa_backup_codes (user_id, code_hash, used_at)`.
- **Frontend**: pantalla de seguridad con elegir método, QR, entrada del código y lista de respaldos descargable/copiable; la pantalla de ingreso pide «código de la aplicación» o «código de respaldo» según el reto.

Criterios de aceptación 27c: (1) alta completa con un código válido y 10 respaldos; (2) ingreso con TOTP correcto entra, con código repetido en el mismo paso, no; (3) un respaldo sirve una vez; (4) el secreto nunca aparece en respuestas posteriores, logs ni auditoría; (5) desactivar o regenerar sin código válido, 422; (6) reinicio de Gerencia/superadmin borra todo y la persona vuelve a entrar sin segundo factor; (7) usuarios con método email no cambian de comportamiento.

## 3. Fuera de alcance
- Llaves de seguridad (WebAuthn/passkeys) y SMS.
- Correo real en producción (decisión de fondo aparte: el MFA por correo y la activación lo necesitan, TOTP no).

## 4. Riesgos y verificación
- **Bloqueo de cuentas** al exigir clave+código para desactivar: mitigado por el reinicio de Gerencia/superadmin. Verificar con Playwright CLI el flujo completo antes del PR.
- Cambios en Auth: correr la **suite completa** (`infra/test.sh`) antes de abrir cada PR (lección de la Fase 12c). Pasar por Senior Review y Cybersecurity (superficie de seguridad nueva: secretos, repetición, topes).
- Contratos: `PermissionCatalogTest` fija el conteo a mano; esta fase **no** agrega permisos (reusa `superadmin.all`/`settings.global`), por lo que no debería moverse; si se agrega alguno, tener cuidado con la fuga por nivel compartido.

## 5. Orden propuesto
27a → 27b → 27c, cada una con su PR de backend y de frontend, informe de revisión en `docs/reviews/` y e2e (`mfa-recuperacion-clave.spec.ts` ampliado). 27c la más grande (varios días).
