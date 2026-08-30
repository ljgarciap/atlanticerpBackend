# Threat Model — SCRUM-746 (Sesión expira por inactividad consecutiva, no absoluta)

**Date:** 2026-08-13
**Owner:** Cybersecurity
**Status:** Gate obligatorio antes de asignar a PM/Backend Dev — ver `docs/architecture/scrum742-743-746-747-748-diseno.md` sección SCRUM-746
**Inputs de origen:** `docs/specs/scrum742-743-746-747-748-analista-spec.md` (sección SCRUM-746),
`docs/architecture/scrum742-743-746-747-748-diseno.md` (sección SCRUM-746), código real:
`app/Shared/Auth/JwtService.php`, `app/Modules/Auth/Models/RefreshToken.php`,
`app/Shared/Http/Middleware/AuthenticateJwt.php`, `config/jwt.php`,
`app/Modules/Auth/Http/Controllers/AuthController.php`,
`app/Modules/Auth/Services/PreferencesService.php`, `bootstrap/app.php`,
`atlanticerp-frontend/src/api/authApi.ts`, `atlanticerp-frontend/src/store/authStore.ts`.

---

## Resumen del cambio

El ticket pide que la sesión expire por **inactividad consecutiva** (default 10h configurable),
no por duración fija desde el login. El diseño propuesto por el Arquitecto: agregar
`last_activity_at` a `refresh_tokens`, actualizarlo en cada request autenticado exitoso (vía
`AuthenticateJwt` o un middleware que lo envuelva), y comparar `now() - last_activity_at` contra
un valor configurable en `auth.system_settings` en vez de (o además de) el `exp` absoluto del JWT.

## Hallazgo previo (no es una pregunta de la lista, pero condiciona la respuesta a casi todas): el mecanismo de "actividad" propuesto no puede funcionar sin un cambio de scope en frontend que el diseño actual no incluye

Verificado en código, no es una suposición:

1. `JwtService::encode()` (línea 39-52) sigue firmando el **access token** con un `exp` absoluto
   desde el momento de la emisión — `now() + expirationMs`. `AuthenticateJwt::handle()` decodifica
   ese JWT con `firebase/php-jwt`, que **lanza `ExpiredException` y lo trata como inválido (401)
   apenas pasa el `exp`**, sin mirar nunca `last_activity_at`. El diseño dice "comparar
   `now()-last_activity_at` ... en vez de la expiración absoluta actual basada en `exp`" pero no
   dice **dónde** se aplica ese reemplazo. Si se aplica solo en el flujo de `refresh_tokens`
   (como sugiere el desglose PM, tocando `AuthenticateJwt`/`JwtService`), el access token en sí
   **sigue muriendo por su propio `exp`** — sin tocarlo, nada cambia para el usuario.
2. Ya existe hoy un mecanismo *distinto y no mencionado en el diseño*: `session_timeout_min`
   (`PreferencesService`, `SystemSetting`), preferencia por-usuario con fallback a un default de
   organización, rango válido **15–240 minutos (4h máx)**. Es este valor el que hoy se pasa como
   `expirationMs` a `JwtService::encode()` en `login()`, `refresh()`, `selectRole()` y
   `switchRole()` (`AuthController.php:150,225,386,445`). Es decir: **hoy ya existe un "timeout de
   sesión" configurable, pero es absoluto desde la emisión del token, tope 4h**, y el diseño de
   SCRUM-746 no lo menciona ni lo reconcilia con las 10h de inactividad pedidas.
3. `POST /api/auth/refresh` — el endpoint que rota el refresh token y reemitiría el access
   token — **existe en el backend pero no tiene ningún caller en el frontend actual**. Confirmado
   por grep en `atlanticerp-frontend/src`: `authApi.refresh` está definida (`authApi.ts:91-93`) pero
   nunca se invoca desde ningún componente/hook. `authStore`/`TopBar.tsx` solo usan
   `refreshToken` para el logout manual (`authApi.logout`). El interceptor de `authApi.ts`
   (línea 24-39) es puramente **reactivo**: si un request devuelve 401, limpia la sesión y muestra
   el modal de "sesión expirada" — no intenta refrescar antes.

**Consecuencia concreta:** tal como está descrito hoy el diseño (batch 746-1 a 746-6, todos
backend salvo 746-6 que solo "verifica que el flujo de logout/401 existente se reusa"), el
resultado observable para el usuario **no cambia**: la sesión sigue muriendo cada
`session_timeout_min` (máx. 4h) sin importar actividad, porque nada dispara `/auth/refresh` antes
de que el access token expire. Para que la sesión dure de verdad hasta 10h de inactividad, hace
falta **una pieza de frontend que hoy no está en el desglose**: un mecanismo de refresh silencioso
(antes de que el access token expire, mientras haya actividad real) que llame a
`POST /auth/refresh` — y es justamente esa llamada la que, bajo el nuevo diseño, debería tocar
`last_activity_at` y ser el punto real donde se aplica el gate de inactividad (no
`AuthenticateJwt`, que valida un JWT ya firmado con su propio `exp` corto).

Esto **no es una decisión de negocio** (el "qué" ya lo definió Luis: 10h de inactividad) — es un
gap de alcance técnico dentro del diseño ya aprobado. Lo marco acá porque cambia dónde vive la
superficie de ataque nueva (ver Hallazgo 1 abajo): si el punto de "touch" es `/auth/refresh`
(llamado por un timer de frontend) en vez de cada request a `/api/*`, el análisis de abuso es
distinto — hay que decidirlo antes de implementar, no después.

---

## Superficie de ataque nueva

Con el mecanismo tal como quedaría definido (asumiendo que se resuelve el gap de arriba agregando
el refresh silencioso en frontend, que es la única forma de que el ticket cumpla su propio
criterio de aceptación):

- **Antes de SCRUM-746:** un token robado (access o refresh) tiene una ventana de abuso acotada y
  conocida: el access token muere en ≤4h desde su emisión pase lo que pase; el refresh token
  muere a los `REFRESH_TOKEN_EXPIRATION_DAYS` (30 días) **desde la última rotación** — ya hoy la
  rotación resetea el absoluto a +30 días en cada uso (`AuthController::issueRefreshToken`,
  `expires_at: now()->addDays($days)` en cada llamada), así que el refresh token *ya* es
  técnicamente una ventana rodante, no fija desde el login original — pero en la práctica de hoy
  nunca se ejercita porque el frontend no lo llama proactivamente (ver arriba).
- **Después de SCRUM-746 (con el refresh silencioso que el ticket necesita para funcionar):** un
  atacante que robe el **refresh token** (ya vive en `localStorage`, `authStore.ts:108`
  `partialize: s => ({ user: s.user, refreshToken: s.refreshToken })` — expuesto a XSS igual que
  hoy, riesgo preexistente no introducido por este ticket) puede mantener la sesión viva
  **indefinidamente**, sin límite superior, con solo generar una request autenticada — propia o
  vía el token robado — cada menos de `session_inactivity_hours` (10h default). Esto convierte un
  robo puntual de credencial en una compromisión de duración potencialmente ilimitada, en vez de
  acotada a 30 días como hoy (aunque hoy esos 30 días tampoco eran un tope real por el mismo
  mecanismo de rotación — la diferencia es que SCRUM-746 lo activa y lo formaliza a propósito,
  volviéndolo un comportamiento garantizado y por lo tanto explotable con confianza, no un efecto
  colateral de código sin usar).

---

## Hallazgos

### 1. Sesión indefinida para un token robado — mientras haya tráfico (cualquiera lo genere)
**Severidad: ALTA**

Responde a la pregunta 1 del brief. No es un vector "nuevo" en el sentido de que requiera un
token inválido — el atacante sigue necesitando el token válido de por sí, como hoy. El cambio real
es que hoy esa validez muere sola en ≤4h (access) / rota pero eventualmente el usuario legítimo
inicia sesión de nuevo y el token viejo queda revocado; con SCRUM-746 tal como lo pide el ticket
(sin techo absoluto, ver Hallazgo 3), **nada fuerza nunca una re-autenticación real** mientras
haya actividad — legítima o del propio atacante manteniéndola viva.

**Mitigación recomendada:**
- El "touch" de `last_activity_at` debe vivir en el punto donde se **valida el refresh token**
  (`/auth/refresh`), no en cada request a `/api/*` — reduce la superficie de qué cuenta como
  "prueba de actividad" a un solo endpoint bien vigilado, en vez de ~todos los endpoints del
  sistema.
- Con eso resuelto, cualquier detección de anomalías ya existente (`SecurityAnalysisJob`, Epic 11,
  `docs/security/THREAT-MODEL-atlanticerp.md`) debería poder alimentarse de las rotaciones de refresh
  token igual que hoy se alimenta de `auth.login` — no hace falta instrumentación nueva, pero sí
  confirmar que `audit_logs` ya registra la rotación (hoy `issueRefreshToken()` no loggea nada en
  `AuditService`, a diferencia de `login()`/`logout()` que sí — gap preexistente, agregar
  `auth.token_refreshed` como evento auditable es una mejora barata que se puede incluir en este
  mismo ticket sin ampliar su alcance de negocio).

### 2. TOCTOU en la actualización de `last_activity_at` bajo requests concurrentes
**Severidad: MEDIA**

Responde a la pregunta 2. El riesgo real no es de performance (múltiples UPDATEs concurrentes a la
misma fila son un problema de lock contention, no de seguridad) — es que la implementación más
obvia ("leer `last_activity_at`, comparar contra el umbral, si pasa: rechazar; si no: actualizar a
`now()`") sea un **check-then-act en dos pasos separados**. Bajo aislamiento por snapshot de
Postgres, dos requests concurrentes que llegan justo en el borde del umbral de inactividad podrían
ambas leer un `last_activity_at` todavía "vigente" (ninguna ve el update de la otra hasta que
comitea), y ambas terminan extendiendo una sesión que, en el momento en que llegó al menos una de
las dos requests, ya debería haber sido rechazada. El efecto práctico es un "resucitado" de sesión
por una ventana de milisegundos — impacto bajo por sí solo, pero compone con el Hallazgo 1 (ventana
de vida indefinida) si el patrón se repite sistemáticamente.

**Mitigación recomendada:** una sola sentencia atómica, no lectura+escritura separadas:
```sql
UPDATE refresh_tokens
SET last_activity_at = now()
WHERE id = :id
  AND revoked = false
  AND last_activity_at > now() - make_interval(hours => :threshold)
RETURNING id;
```
Si `RETURNING` no devuelve fila, la sesión ya expiró por inactividad — rechazar con 401 en esa
misma operación, sin un SELECT previo. Esto también resuelve gratis la pregunta de qué versión de
`session_inactivity_hours` aplica si cambió a mitad de sesión (siempre lee el valor vigente al
momento de la comparación, nunca el que estaba al loguearse — cumple el criterio de aceptación 4
del ticket).

**Hallazgo adyacente, mismo módulo, preexistente (no introducido por SCRUM-746, pero se vuelve más
relevante al tocar este código):** `AuthController::refresh()` (línea 193-238) valida
`$stored->isValid()` y luego hace `$stored->update(['revoked' => true])` como dos pasos separados,
sin `WHERE revoked = false` en el UPDATE ni bloqueo. Dos requests concurrentes con el mismo refresh
token (replay accidental de un doble-click, o un atacante corriendo el token robado en paralelo al
usuario legítimo) pueden ambas pasar `isValid()` antes de que cualquiera marque `revoked = true`,
resultando en **dos refresh tokens hijos válidos emitidos a partir de uno solo** — rompe la
invariante de rotación de un solo hijo activo por token. No es parte del scope de SCRUM-746, pero
como Backend Dev va a tocar este mismo archivo para el gate de inactividad, recomiendo corregirlo
en el mismo PR (mismo patrón de `UPDATE ... WHERE revoked = false RETURNING`) — bajo costo,
adyacente, y el reviewer ya va a tener el contexto fresco.

### 3. Ausencia de techo absoluto — tensión real con la práctica de seguridad, ticket lo prohíbe explícitamente
**Severidad: sin calificar — requiere decisión de Luis, no la resuelvo yo**

Responde a la pregunta 3. Repasé la "Regla definitiva" de la spec del Analista (SCRUM-746): el
ticket pide expresamente que el contador sea de inactividad pura, que cambiar la config aplique
"sin depender del login original", y no menciona ningún límite superior — la lectura más directa
es que el negocio **no quiere** un techo absoluto tipo "7 días desde el login pase lo que pase".

Desde la práctica de seguridad estándar (OWASP Session Management Cheat Sheet y equivalentes), un
timeout de inactividad **sin** un timeout absoluto de sesión es una desviación conocida — el
patrón recomendado es tener ambos, justamente para acotar el escenario del Hallazgo 1 (token
robado + tráfico sostenido = sesión sin fin). No lo presento como bloqueante ni lo doy por resuelto
en ningún sentido: es una **tensión real entre lo que pide el ticket y una práctica de seguridad
estándar**, y el brief de este threat model fue explícito en pedir que no la asuma resuelta.

**Punto para Luis:** ¿el rechazo a un techo absoluto es intencional y informado (aceptando el
trade-off del Hallazgo 1), o es que el ticket nunca consideró ese ángulo porque lo escribió
negocio pensando en la molestia de "me saca la sesión mientras trabajo", no en el caso de abuso de
un token robado? Si la respuesta es "sí, sin techo, a propósito" — aceptable, lo dejo aprobado con
esa decisión explícita registrada acá. Si nunca se consideró, mi recomendación sería un techo alto
y no intrusivo (ej. 30 días, alineado al `REFRESH_TOKEN_EXPIRATION_DAYS` que ya existe hoy) —
suficientemente largo para no chocar nunca con un usuario real activo a diario, y sin necesidad de
UI/aviso nuevo, solo como defensa en profundidad silenciosa.

### 4. Middleware compartido — rutas públicas no quedan expuestas por el cambio, pero solo si se implementa dentro de `AuthenticateJwt`
**Severidad: BAJA (condicional a la implementación)**

Responde a la pregunta 4. Verificado en `bootstrap/app.php`: `AuthenticateJwt` se agrega
globalmente al grupo `api` (línea 95, `appendToGroup`), y las rutas públicas
(`/api/auth/login`, `/refresh`, `/logout`, `/health`, `/public/share/{token}`,
`/public/catalog/uat-placeholder/{reference}`) se excluyen explícitamente con
`withoutMiddleware(AuthenticateJwt::class)` **a nivel de grupo de rutas**, no ruta por ruta.

Si la lógica nueva (touch de `last_activity_at`, gate de inactividad) se agrega **dentro** de
`AuthenticateJwt::handle()` o de un middleware que lo envuelve y se registra en el mismo punto
(como sugiere el diseño), hereda automáticamente esa misma exclusión — cero riesgo de que una ruta
pública quede afectada.

**El riesgo real es de implementación, no de diseño:** si en el camino se decide meter la lógica
como un middleware **nuevo y separado**, agregado independientemente al grupo `api`
(`appendToGroup('api', TrackActivity::class)`), ese middleware necesitaría **su propia lista de
exclusiones**, duplicando la de `AuthenticateJwt` — y una futura ruta pública nueva (como
`/public/catalog/uat-placeholder/{reference}`, agregada hace apenas unos días para SCRUM-745) que
alguien recuerde excluir de `AuthenticateJwt` pero olvide excluir del middleware nuevo. Nota
además: `/auth/refresh` y `/auth/logout` son rutas **públicas** (sin JWT — el refresh token es la
credencial ahí, no el Authorization header) — si el "touch" real termina viviendo en
`AuthController::refresh()` (como recomiendo en el Hallazgo 1), esto no aplica en absoluto, porque
no pasa por `AuthenticateJwt` de ningún modo — otra razón más para preferir esa ubicación sobre un
middleware nuevo de alcance amplio.

**Mitigación recomendada:** implementar el touch dentro de `AuthenticateJwt` (si aplica a cada
request) o dentro de `AuthController::refresh()` (si aplica solo ahí, mi recomendación del
Hallazgo 1) — nunca como middleware nuevo con lista de exclusión propia.

### 5. Validación de rango en `session_inactivity_hours` — riesgo de bypass/DoS por config inválida
**Severidad: MEDIA**

Responde a la pregunta 5. El patrón ya existente (`share_links_expiration_days`,
`PreferencesService::updateSystemSettings`, línea 119-129) valida `min:1, max:365` antes de
persistir — buen precedente a copiar literal. Riesgos concretos si no se replica esa validación
para `session_inactivity_hours`:
- **0 o negativo:** con la mitigación atómica del Hallazgo 2 (`last_activity_at > now() -
  make_interval(hours => 0)`), un valor de `0` o negativo haría que la condición nunca se cumpla —
  todo usuario quedaría deslogueado en la siguiente request tras cualquier `last_activity_at`
  pasado, incluyendo el del propio login. Efecto: **DoS total de autenticación** para el tenant
  (nadie puede quedarse logueado ni un segundo) hasta que un superadmin revierta el valor —
  el propio superadmin que lo cambió también queda afectado en su próxima request, así que
  depende de que alguien entre con una sesión todavía viva en otra pestaña/dispositivo para
  revertirlo, o de acceso directo a BD.
- **Valor absurdamente alto (ej. `999999`):** no es un bypass de por sí (ya no hay techo absoluto,
  ver Hallazgo 3), pero sí sería una forma de que un actor con acceso a `system_settings` (hoy
  gateado a quien pueda llamar `updateSystemSettings` — confirmar el permiso real en
  `routes/settings.php`, no relevado en este research) desactive de facto el timeout de
  inactividad sin que quede registrado como "sin timeout", ofuscando la intención del cambio en
  logs/auditoría.

**Mitigación recomendada:** validar rango explícito en `PreferencesService`, mismo patrón que
`share_links_expiration_days` — sugerido `min:1, max:720` (30 días, coherente con el
`REFRESH_TOKEN_EXPIRATION_DAYS` default y con la recomendación del Hallazgo 3 si Luis opta por
alinear el techo). Confirmar además — no asumido acá — qué permiso protege
`PUT /api/settings` / `updateSystemSettings` hoy, para que quede claro que este valor no es
editable por cualquier rol.

### 6. Logout explícito vs. sesión mantenida viva por actividad
**Severidad: BAJA, con una corrección necesaria**

Responde a la pregunta 6. `AuthController::logout()` (línea 262-282) revoca el refresh token
(`revoked = true`) — eso es correcto y suficiente: una vez revocado, `RefreshToken::isValid()`
(que ya chequea `! $this->revoked`) lo rechaza en cualquier intento posterior de refresh,
incluyendo el touch de `last_activity_at` si ese touch vive en `/auth/refresh` como recomiendo. No
hay ventana donde "actividad" resucite un token ya revocado, siempre que la condición `revoked =
false` esté en el mismo WHERE atómico del Hallazgo 2 (ya lo está en el diseño de esa mitigación).

**Único punto a verificar en implementación:** el **access token** ya emitido antes del logout
sigue siendo válido (firmado, `exp` no vencido) hasta que expire por sí solo — esto es el
comportamiento de hoy también (el comentario del propio código lo documenta: "el JWT de acceso
expira por sí solo", línea 243), no algo que SCRUM-746 empeore. Pero si el touch de actividad
termina viviendo en `AuthenticateJwt` (cada request a `/api/*`, la opción que NO recomiendo en el
Hallazgo 1) en vez de en `/auth/refresh`, hay que confirmar que ese middleware **no** reactiva
nada del lado de `refresh_tokens` para un access token cuyo refresh token ya fue revocado — hoy
`AuthenticateJwt` ni siquiera mira `refresh_tokens`, así que agregarle ese lookup es meterle una
dependencia nueva a una ruta caliente del sistema; otro argumento más a favor de la ubicación en
`/auth/refresh`.

---

## Recomendaciones concretas para Backend/Frontend Dev antes de implementar

1. **Resolver primero el gap de alcance** (sección arriba, no es una de las 6 preguntas pero
   condiciona todo lo demás): decidir explícitamente si el "touch" de `last_activity_at` vive en
   `/auth/refresh` (mi recomendación) o en cada request de `/api/*` vía `AuthenticateJwt`. Esto
   determina si hace falta agregar un mecanismo de refresh silencioso en frontend (necesario en
   ambos casos para que el ticket cumpla su propio criterio de aceptación, dado que hoy
   `authApi.refresh` no tiene ningún caller) — sin esa pieza, el ticket queda sin efecto
   observable para el usuario real, pase lo que pase en backend.
2. **Actualización atómica** (`UPDATE ... WHERE ... RETURNING`), nunca SELECT-luego-UPDATE —
   Hallazgo 2.
3. **Validar rango** en el nuevo setting (`min:1, max:720` sugerido, o el que Luis confirme según
   el Hallazgo 3) con el mismo patrón ya usado para `share_links_expiration_days` — Hallazgo 5.
4. **Loggear la rotación de refresh token** (`auth.token_refreshed`) en `AuditService`, igual que
   ya se loggea `auth.login`/`auth.failed` — barato, y le da a `SecurityAnalysisJob` (Epic 11)
   visibilidad sobre el patrón nuevo de sesiones de larga duración sin trabajo adicional de
   detección. No es requisito del ticket, pero es la pieza mínima para poder auditar el Hallazgo 1
   después del hecho.
5. **Corregir de paso la race de rotación preexistente** en `AuthController::refresh()`
   (`WHERE revoked = false` en el UPDATE de revocación) — mismo archivo, mismo PR, bajo costo —
   Hallazgo 2 (adyacente).
6. Implementar el touch/gate dentro de `AuthenticateJwt` o `AuthController::refresh()` según lo
   decidido en el punto 1 — **nunca** como middleware nuevo registrado por separado en
   `bootstrap/app.php` — Hallazgo 4.
7. Confirmar qué permiso protege `updateSystemSettings`/`PUT /api/settings` antes de asumir que
   `session_inactivity_hours` solo lo puede tocar un superadmin — no relevado en este research,
   Backend Dev debe confirmarlo al implementar.

## Puntos que requieren decisión de Luis (no los resuelvo yo)

1. **Hallazgo 3 — techo absoluto de sesión.** El ticket, tal como está escrito, pide que no exista
   ninguno. Estándar de seguridad recomienda tener uno (ej. 30 días) como defensa en profundidad
   contra un refresh token robado + tráfico sostenido indefinido (Hallazgo 1). Necesito que Luis
   confirme si esto fue una decisión consciente o si nunca se planteó ese ángulo al escribir el
   ticket — la recomendación de Cybersecurity, si se pregunta, es agregar un techo alto y silencioso
   (sin UI/aviso, 30 días), pero la decisión de negocio es de Luis, no mía.
2. **Alcance del ticket — pieza de frontend faltante.** El diseño técnico actual (desglose PM,
   746-1 a 746-6) no incluye construir el mecanismo de refresh silencioso que el ticket necesita
   para tener efecto real (ver sección de arriba). Esto es más una nota para el Arquitecto/PM que
   para Luis directamente, pero lo marco acá porque cambia el tamaño real del batch — recomiendo
   que el Arquitecto lo confirme y ajuste el desglose antes de asignar a Frontend Dev, no que se
   descubra a mitad de implementación.

---

## Veredicto

**No hay ningún bloqueante que impida avanzar con el diseño.** Es aprobable con las mitigaciones
de esta sección aplicadas antes o durante la implementación (atomicidad del update, validación de
rango, ubicación del touch en `/auth/refresh`, logging de auditoría). El único punto que sí
necesita una respuesta de Luis antes de cerrar el diseño por completo es el del techo absoluto
(Hallazgo 3/Punto 1) — no bloquea que Backend Dev empiece la migración y el resto de la
infraestructura (746-1, 746-4), pero sí debería resolverse antes de cerrar 746-3 (la lógica de
comparación de expiración), porque cambia esa lógica según la respuesta.
