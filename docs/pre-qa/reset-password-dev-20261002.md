# Pre-QA — Reset administrativo de contraseñas (individual y masivo) — dev.atlanticerp.ai — 2026-10-02

Entorno: https://dev.atlanticerp.ai (backend 5c39649e, frontend 451e8a1). Operador: superadmin real `luis.garcia@` solo para las llamadas de administración. Objetivos: demos `designer@` (id 48) y `readonly@` (id 52). Ritmo respetado (>= 2 s entre requests, tandas con pausa de 60 s, sin bans ni timeouts reales). Ninguna contraseña ni token en este informe.

## Resultado por criterio

| Criterio | Escenario de ruptura intentado | Resultado | Evidencia |
|---|---|---|---|
| A reset individual | Demo OK; token readonly; sin token; id inexistente; inactivo | PASS | 200 `{id,email,must_change_password:true}`; readonly 403; sin token 401; id 999999 404; inactivo (id 58) 422 "usuario inactivo"; login con email=password OK y `user.must_change_password=true`; la contraseña fija anterior deja de entrar (401) |
| B enforcement | /me, /compras/inventory, /users, /ventas-diseno/clients con flag; evasión | PASS | /me 200 con flag true; 3 rutas 403 `password_change_required`; `/auth/me/`, `?x=1` -> 200 (misma ruta /me, no es evasión); `POST /auth/me` 405; `X-HTTP-Method-Override`/`X-Original-URL` sobre /users -> 403; `/auth/me%2F..%2Fusers` 404 |
| C cambio de contraseña | nueva=correo, nueva=actual, débil, actual incorrecta, válida | PASS | nueva=correo 422 (errors.new_password; ver nota 1); débil 422; actual incorrecta 422; nueva=actual 422 "igual a la actual"; válida 204; /me flag false; login con la nueva OK, con el correo 401; refresh token previo -> 401 |
| D H1 tokens previos | T1 antes del reset; /me y PUT password con T1 tras reset | PASS | T1 /me 200 antes; tras reset (+3 s) /me 401, PUT /auth/password 401; refresh token previo 401; login nuevo posterior OK y válido |
| E masivo API | dry_run, defaults, superadmins, confirm, payloads, 403 | PASS | dry_run 200 `{count,users[{id,email,name}]}` sin cambios (login demos sin flag después); por defecto count 39 sin superadmins ni nivel 10 (45/46/47 demo y 11/12/13/18 reales fuera); include_self sin include_superadmins = 39; include_superadmins=true 45; ambos 46 (solo dry_run, nunca aplicado); confirm "RESETEAR 5"/"resetear 2"/ausente -> 422 `confirmation_mismatch` sin cambios; con `except_ids` dry_run = 2 demos (48, 52); apply "RESETEAR 2" -> 200 `reset_count:2`, ambas con flag y tokens previos 401; supervisor sin afectar; readonly 403; sin token 401; dry_run "banana", except_ids "x", arrays/objetos raros, confirm array -> 422 por campo, sin 500 |
| F rate limit | 7 llamadas rápidas | PASS | 404 x6 y 7a = 429 (retry-after 59); tras 65 s vuelve a 404 |
| G1 UI demo reseteada | pantalla obligatoria, URL directa, validaciones, logout, cambio | PASS | Pantalla "Debes cambiar tu contraseña" sin sidebar/nav (0 aside/nav); goto /security/users, /compras, /home siguen mostrándola; vacío/débil/no coincide/actual incorrecta con mensajes por campo en español; logout -> /login; cambio -> app normal y flag limpio; 390 px sin scroll horizontal. Capturas `fe-pw/screenshots/preqa-resetpw-g1-*.png` |
| G2 UI superadmin | acciones, modales, confirmación | PASS | Botón "Restablecer contraseñas…" y "Restablecer contraseña" por fila; modal individual con nombre, correo, advertencia y auditoría; masivo: "Incluir mi propia cuenta" y "Incluir cuentas de superadministrador" desmarcados, calcula 39 con lista de correos; Aplicar deshabilitado con vacío, "RESETEAR 1", minúsculas y "RESETEAR 39 " (espacio) y habilitado solo con "RESETEAR 39" exacto (NO se hizo clic); 390 px sin scroll horizontal. `preqa-resetpw-g2-*.png` |
| G3 UI no superadmin | supervisor, multi (ve Usuarios), readonly | PASS | 0 botones masivo / 0 por fila (supervisor y readonly son redirigidos a /settings, multi ve /security/users sin botones) |
| H regresión | login sin flag; forgot-password | PASS | supervisor/multi/designer/readonly entran normal; forgot-password con correo inexistente 200 mensaje genérico, sin 500 |
| I seguridad visible | enumeración, hashes | PASS | login con correo inexistente y con correo real + contraseña errónea dan el mismo 401 "Credenciales inválidas."; ningún payload (login user, reset, masivo) contiene hash ni contraseña |

## Hallazgos

- BAJO 1 — Mensaje de new_password == correo: el backend responde "El formato del campo new password no es válido." porque el correo nunca cumple la complejidad (sin mayúscula/número), así que la regla "igual al correo" no se puede ejercitar aislada con cuentas demo; el cliente tiene su propio mensaje (`forceChange.sameAsEmail`) pero con correo en minúsculas la validación débil se muestra primero. Resultado final correcto (422), solo mensaje genérico.
- BAJO 2 — Mensajes de validación con nombre de campo en inglés/snake ("new password", "dry run", "except ids") y 404 de reset con "Not found" en inglés; la UI los mapea para los campos del formulario.
- BAJO 3 (dev) — Respuestas 404/405 de rutas inexistentes exponen `exception`, `file` y `line` (APP_DEBUG probablemente activo en dev). No verificado en test/prod; confirmar que prod lo tiene apagado.
- Observación — En Seguridad > Usuarios el buscador no filtró por el correo escrito en esa captura (primera fila era otra persona); fuera del alcance de este ticket, no se actuó sobre esa fila (el modal se cerró sin confirmar).
- Sin hallazgos CRÍTICO, ALTO ni MEDIO.

## No ejercitado / límites
- No se aplicó masivo con `include_superadmins:true` (por instrucción). Solo dry_run.
- Confirmación de que el modal individual realmente restablece por UI no se ejercitó (se probó por API); el modal solo se abrió y se cerró.
- Errores de consola: solo 2 `403` en la carga de la pantalla de readonly (rutas sin permiso, esperado). Sin 5xx.
- La regla "nueva == correo" a nivel backend no es aislable (ver BAJO 1).

## Estado final de cuentas demo tocadas
- designer@ (48) y readonly@ (52): restablecidas a la contraseña fija del seeder vía flujo de cambio de contraseña (UI/PUT); verificado por login: entran con la fija, `must_change_password=false`, y NO entran con su correo.
- supervisor@ y multi@: sin cambios, login con la fija OK, flag false.
- Ninguna persona real fue reseteada ni autenticada (salvo el operador luis.garcia@). Ningún dato nuevo creado.

## Veredicto
Pasada limpia y concluyente para los criterios A-I ejercitados; solo hallazgos BAJO (no bloqueantes). Apto para pasar a QA desde el punto de vista de este gate. El riesgo residual H2 (sin vencimiento de la contraseña temporal ni login con límite de intentos) ya está documentado en el runbook y no es parte de este gate.

Spec Playwright: `/Users/lgarcia/Documents/GitHub/Softclass/Illumination/atlanticerp/.worktrees/fe-pw/e2e/preqa-resetpw-dev-20261002.spec.ts` (baseURL explícita dev.atlanticerp.ai). Helper de API temporal en el scratchpad de la sesión.
