# Revisión — Fase 27c, aplicación autenticadora y códigos de respaldo (2026-11-06)

Revisión propia en el hilo, con la lente de Seguridad (no un agente independiente). Spec: `docs/specs/fase27-sesion-y-segundo-factor.md` (§2, §8).

**Veredicto: aprobada, 0 bloqueantes.** Un hallazgo menor corregido durante la revisión (ver «Corregido»).

## Superficie revisada
- **Algoritmo (`App\Shared\Auth\Totp`):** RFC 6238 / 4226 con SHA-1 y 6 dígitos, el formato que entienden las aplicaciones. Probado contra los vectores del RFC; el secreto es de 160 bits (`random_bytes(20)`). La comparación usa `hash_equals` y recorre los tres pasos de la tolerancia sin cortar al primero, así el tiempo no revela cuál acertó.
- **Repetición:** un paso solo se acepta si es mayor que `mfa_totp_last_step`, y la actualización es condicional en SQL (`UPDATE … WHERE last_step IS NULL OR last_step < paso`): dos peticiones en paralelo con el mismo código no pasan las dos. Consecuencia conocida: tras confirmar el alta hay que esperar al siguiente código para entrar.
- **Respaldos:** 10 códigos de 10 caracteres sobre 31 símbolos (≈ 50 bits), un solo uso (consumo atómico con `UPDATE … WHERE used_at IS NULL`), en BD solo su HMAC-SHA256 con `APP_KEY`. Regenerar invalida los anteriores dentro de una transacción. La fuerza bruta la frena el mismo tope de 5 errores por usuario cada 10 min más el de IP.
- **Secreto:** cast `encrypted`, fuera de `$hidden` y de la auditoría (`User::auditableAttributes` excluye los tres campos); el secreto pendiente solo pasa a activo al confirmar con un código válido. Se devuelve una vez, en `totp/setup`, y la prueba lo busca en `status`, `me`, `proof` y en todo `audit_logs`.
- **Acciones sensibles:** alta, desactivación y regeneración exigen la clave y la prueba del método vigente; con el correo activo, cambiar a la aplicación exige además el código del correo (no basta una sesión robada). Cada acción tiene su propio tope por usuario (`enable`, `disable`, `regenerate`), separado del de ingreso, y el reinicio por un administrador los limpia.
- **Ingreso:** con aplicación no se envía correo (sigue funcionando con el correo caído); el reto de TOTP no se puede reenviar ni resolver por la vía del correo (su `code_hash` es un valor al azar que nadie conoce). Un reto de correo sigue exigiendo 6 dígitos.
- **Reinicio por Gerencia/superadmin:** borra secreto pendiente y activo, último paso y respaldos, y devuelve el método a `email`. Las reglas de la 27a (Gerencia no reinicia a un superadmin ni a sí misma) no cambian.
- **Sin permisos nuevos:** el conteo de `PermissionCatalogTest` no se mueve; no hay riesgo de fuga por nivel compartido.

## Corregido en la revisión
- `regenerateBackupCodes` borraba y creaba sin transacción: un fallo a medias dejaba a la persona sin respaldos. Ahora va en `DB::transaction`.

## Verificación
Backend: `TotpTest` (vectores del RFC) y `MfaTotpTest` (22 casos) más los tests de MFA y reinicio existentes (118 en el grupo), PHPStan 0 errores y la suite completa. Frontend: tsc limpio, 257 archivos / 2973 tests. e2e `mfa-recuperacion-clave` 8 de 8 contra el stack local. En un navegador real (Playwright): alta con QR y clave tecleable, lista de respaldos con la casilla «ya los guardé», estado con respaldos restantes, ingreso con el código de la aplicación y con un respaldo, y reinicio por el superadmin.

## Sugerencias abiertas (no bloquean)
1. **Rotar `APP_KEY`** invalida los secretos TOTP (el cast no descifra y el ingreso de esa persona fallaría) y los respaldos (su HMAC usa la misma clave). Antes de rotarla hay que reiniciar el MFA de quien use la aplicación o planear una rotación con doble clave.
2. No se avisa por correo cuando alguien activa o desactiva su aplicación, ni cuando se gasta un respaldo (solo auditoría).
3. Cada ingreso correcto con TOTP crea una fila de reto en `mfa_codes` (igual que el correo) y no hay limpieza periódica de los vencidos.
4. `POST auth/mfa/proof` envía un correo por llamada (con correo activo) sin tope propio, igual que `disable` antes; el tope de errores no lo cubre porque no cuenta envíos.
5. Pendiente de la fase 27 que no entra aquí: la aprobación de un segundo operador para «Ver como usuario».
