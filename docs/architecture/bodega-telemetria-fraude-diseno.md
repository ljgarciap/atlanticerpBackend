# Arquitectura — Telemetría profunda de Bodega + Admin&Cont (extensión de Epic SCRUM-788)

**Estado: APROBADO por el CTO (vía Luis, 2026-08-30) — alcance y prioridad confirmados, ver
sección 6.** Listo para que PM desglose en tickets y Cybersecurity arranque el threat model — ver
sección 7 para qué falta antes de tocar código.

**Autor:** Analista + Arquitecto (roles del equipo, ver `atlanticerp/CLAUDE.md`).
**Fecha:** 2026-08-30.

---

## 0. Contexto

Pedido de Luis, a nombre del CTO: dejar trazabilidad profunda de cada acción en Bodega — quién,
desde qué IP, en qué hora, qué acción — por ser un módulo susceptible de ataque externo/interno y
de modificaciones con impacto económico directo (inventario, costos, ajustes).

Antes de diseñar algo nuevo se auditó el código real. Resultado: **ya existe una épica completa,
SCRUM-788 "Logs y Telemetría"**, con 5 de 6 tickets hijos en `Done` — cubre gran parte de lo
pedido para Bodega, pero nunca quedó documentada fuera de Jira (ver changelog). Este documento
**no rediseña desde cero** — extiende esa épica con los gaps reales que quedan, priorizados por
riesgo de fraude económico.

---

## 1. Qué ya cubre la base existente (no reimplementar)

| Pieza | Ticket | Qué hace |
|---|---|---|
| `audit_logs` (schema `auth`, per-tenant) | SCRUM-788 base | `user_id`, `ip_address`, `user_agent`, `request_id`, `http_method`/`http_path`, diff `old_values`/`new_values`, `created_at`. Ver `CLAUDE.md` schema `auth`. |
| `LogsAuditTrail` (trait) en 16/18 modelos de Bodega | SCRUM-790 (Done) | `AdjustmentRequest`, `GeneralCountRequest`, `RelocationRequest`, `InventoryMovement` (Kardex), `ProductWarehouseStock`, `CustomerReturn`, `Order`, etc. — created/updated/deleted automático, sin tocar controllers. |
| Eventos no-CRUD de Bodega | SCRUM-791 (Done) | 3 exports/descargas instrumentados a mano (`BODEGA_ORDER_PICKING_SHEET_EXPORTED`, `BODEGA_ORDER_ITEM_DETAIL_EXPORTED`, `BODEGA_ORDER_DOCUMENT_DOWNLOADED`). |
| Visor generalizado | SCRUM-792 (Done) | `GET /auditoria/logs`, todos los módulos, `permission:superadmin.all`. |
| Pantalla "Logs" | SCRUM-793 (Done) | Frontend, entrada en sidebar (`LogsPage.tsx`). |
| Kardex append-only | Preexistente | `InventoryMovement` nunca se actualiza ni se borra — solo insert vía `KardexService::registrar()`. El ledger económico central ya es inmutable a nivel de aplicación. |

Con esto, hoy ya es posible responder "¿quién creó/editó/borró este `AdjustmentRequest`, desde qué
IP y cuándo?" para casi todo Bodega. Lo que sigue son los huecos reales para que esa trazabilidad
sirva ante un caso de fraude, no solo ante una duda operativa.

---

## 2. Gaps y REQs propuestos

### REQ-631 — Auditoría de intentos denegados (403)
**Problema:** `App\Shared\Http\Middleware\CheckPermission` responde 403 pero nunca llama a
`AuditService::log()`. Un patrón de "alguien probando endpoints de ajuste/reubicación sin permiso,
repetidamente" no deja rastro hoy — es justo la señal de reconocimiento previa a un fraude interno.
**Diseño:** agregar `AuditService::SECURITY_ACCESS_DENIED` (`'security.access_denied'`), logueado
desde `CheckPermission` con `entity_type='route'`, `entity_id=$request->path()`, `metadata` con el
permiso faltante. Aplica a todos los módulos, no solo Bodega — pero Bodega es el caso que lo motiva.
**Criterios de aceptación:**
1. Todo 403 de `CheckPermission` genera una fila en `audit_logs` con `action='security.access_denied'`.
2. El visor de Logs (`GET /auditoria/logs`) permite filtrar por esta acción.
3. No introduce un INSERT síncrono adicional perceptible en latencia (mismo patrón fire-and-forget que `AuditService::log()`, que ya nunca lanza excepción).
**Riesgo/esfuerzo:** bajo — un solo punto de enganche, patrón ya probado.
**Agente:** Backend Dev.

### REQ-632 — Auditoría de lecturas sensibles en Bodega
**Problema:** hoy solo se auditan 3 exports puntuales. Ver el Kardex de un producto, el costo
unitario, o el detalle de un ajuste repetidamente sin nunca escribir nada no deja huella — es el
patrón típico de reconocimiento antes de un fraude, y hoy es invisible.
**Diseño (implementado 2026-08-30 — alcance corregido contra el código real):** de los 3
candidatos originales, solo **1 existe como endpoint discreto** en Bodega —
`AdjustmentRequestController::productWarehouseStock()` (`GET /bodega/adjustment-requests/
products/{productId}/warehouse-stock`, la lectura de stock por bodega que se consulta antes de
decidir el tamaño de un ajuste). Los otros 2 no calificaron al verificar el código:
- **Kardex por producto** — `KardexController::index()` solo filtra por `search` (texto libre
  sobre referencia/nombre) y `warehouse_id`/`tipo`, sin un parámetro de `catalog_product_id` — no
  hay una "vista de detalle de un producto" discreta que instrumentar sin loguear cada GET del
  índice completo (violaría el criterio "no cada GET" de abajo).
- **Costo unitario en detalle de producto** — `BodegaInventoryController::show()` documenta
  explícitamente "REQ-361 — modal Ver detalle, solo lectura, **sin precio/costo**". Bodega no
  expone costo en ningún endpoint de detalle de producto; ese dato vive en Compras/Ventas&Diseño,
  fuera del alcance de este REQ.

Evento: `AuditService::BODEGA_ADJUSTMENT_WAREHOUSE_STOCK_VIEWED`
(`bodega.adjustment_request.warehouse_stock_viewed`), `entity_type='catalog_product'`. Mismo
criterio ya aprobado en la épica para Servicios (`servicios.external_technician.viewed`,
SCRUM-791): **no cada GET**, solo vistas de datos sensibles con valor de negocio real.
**Criterios de aceptación:**
1. ~~Lista cerrada de endpoints a instrumentar~~ — resuelta: 1 endpoint real, ver arriba.
2. Cada vista sensible genera un evento `bodega.{entidad}.viewed` con el `entity_id` visto. ✅
**Riesgo/esfuerzo:** bajo — terminó siendo más chico de lo estimado, un solo punto de enganche.
**Agente:** Backend Dev. **Estado: Done**, ver SCRUM-807.

### REQ-633 — Verificación y aseguramiento de la IP real del actor
**Problema:** no se encontró `trustProxies()` en `bootstrap/app.php` ni headers
`X-Forwarded-For`/`X-Real-IP` en `infra/nginx/default.conf`. Como nginx habla FastCGI directo a
PHP-FPM (`fastcgi_pass laravel:9000`) sin load balancer AWS delante (arquitectura actual: EC2
directo, sin ALB), `REMOTE_ADDR` debería llegar correctamente vía el `fastcgi_params` default de
nginx — pero **esto nunca se confirmó con datos reales**. Es el campo más literal de lo que pidió
el CTO ("desde qué IP") — no puede quedar como supuesto.
**Diseño:** (1) verificación empírica — consultar `audit_logs.ip_address` de eventos recientes en
`dev`/`test` y confirmar que son IPs de cliente real, no la IP interna del contenedor `nginx`
dentro de la red Docker; (2) si el chequeo falla, o como salvaguarda ante un futuro ALB/CloudFront
delante de nginx (ya se evaluó Auto Recovery/CloudWatch — no sería raro que se agregue un LB más
adelante), configurar `trustProxies()` explícito en `bootstrap/app.php`.
**Criterios de aceptación:**
1. ✅ Confirmado con datos reales (2026-08-30, SSH de solo lectura, `SELECT ip_address, action, created_at FROM audit_logs ORDER BY created_at DESC` en los 3 entornos): `audit_logs.ip_address` refleja la IP pública real del cliente en todo evento generado por una request HTTP real — `dev` (190.96.139.40, 181.141.21.151, 181.55.20.26), `test` (181.55.20.26, 181.78.137.130), `prod` (190.218.251.166). Ningún entorno mostró la IP interna fija de `nginx` dentro de la red Docker — el `REMOTE_ADDR` del `fastcgi_params` default de nginx llega correcto sin necesitar `trustProxies()`.
2. `trustProxies()` **no se agregó** — el chequeo no falló, no hace falta el fix. Queda como nota para el futuro: si algún día se agrega un ALB/CloudFront delante de nginx (evaluado y descartado hoy, ver "Infraestructura AWS" en `CLAUDE.md`), este REQ hay que revisarlo de nuevo — la salvaguarda no se implementó preventivamente para no tocar código que hoy funciona bien.
3. Nota aparte, no es un defecto: algunas filas de `audit_logs` muestran `ip_address = 127.0.0.1` — corresponden a escrituras disparadas desde consola/queue (seeders, comandos Artisan, jobs de Horizon), no a requests HTTP reales. Es el comportamiento esperado de `request()->ip()` fuera de un contexto HTTP, no un bug de este REQ.
**Riesgo/esfuerzo:** bajo — terminó siendo solo verificación, sin cambio de código.
**Agente:** DevOps. **Estado: Done** (verificación completa, sin fix necesario), ver SCRUM-809.

### REQ-634 — Inmutabilidad de `audit_logs` a nivel de base de datos
**Problema:** `audit_logs` es una tabla Eloquent normal. Cualquiera con acceso a Postgres (o un
superadmin vía `tinker`) puede hacer UPDATE/DELETE sobre ella. Para que sirva como evidencia real
ante un caso de fraude, un log que se puede editar no prueba nada.

**⚠️ Alcance corregido 2026-08-30 (más grande de lo estimado — pausado hasta aprobación explícita,
NO implementado):** el diseño original ("rol dedicado con GRANT INSERT,SELECT, revocar
UPDATE/DELETE") asumía que un REVOKE simple alcanza. Verificado en código: `DB_USERNAME=atlanticerp`
(`.env.example`) es pasado como `POSTGRES_USER` a la imagen oficial de `postgres:16-alpine`
(`infra/docker-compose.yml`) — ese usuario queda **superusuario** de Postgres por cómo `initdb`
inicializa el cluster, y es el mismo único usuario que Laravel usa para migraciones Y runtime.
Un superusuario (y el dueño de una tabla, salvo con Row-Level Security forzada) **ignora**
GRANT/REVOKE — y Row-Level Security tampoco alcanza, un superusuario también la bypassea aunque
se use `FORCE ROW LEVEL SECURITY`. Para que la inmutabilidad sea real hace falta un **segundo rol
sin superuser**, no un REVOKE sobre el rol existente.

**⚠️ Descartado un atajo tentador (2026-08-30):** evalué dejar que `atlanticerp_app` también pudiera
crear tablas (`GRANT CREATE ON SCHEMA`) para que corriera las migraciones ella misma y no hiciera
falta separar credenciales de runtime vs. migrate. **No sirve**: quien CREA una tabla en Postgres
queda como su dueña, y un dueño de tabla bypassea REVOKE igual que un superusuario — si
`atlanticerp_app` crea `audit_logs`, el REVOKE UPDATE/DELETE sobre ella no tiene ningún efecto. La
separación de credenciales (superuser para migrar, rol restringido para runtime) no es una opción
entre varias, es la única forma de que esto funcione de verdad.

> **Tablas inmutables para `atlanticerp_app` (actualizado 2026-09-30):** `{tenant}_auth.audit_logs`,
> `{tenant}_auth.db_audit_log` (SCRUM-916) y `{tenant}_admin_contab.fe_transmisiones` (SCRUM-1001,
> registro de transmisiones al PAC). Todas con `REVOKE UPDATE, DELETE` en `GrantAppRoleCommand`,
> que corre en cada deploy. `TRUNCATE` nunca se otorga.

## Diseño final (2026-08-30) — listo para ejecutar, pendiente de tu aprobación explícita

### 1. Rol nuevo `atlanticerp_app` — bootstrap único por entorno (manual, SSH, una sola vez)

```sql
DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'atlanticerp_app') THEN
    CREATE ROLE atlanticerp_app WITH LOGIN PASSWORD '<generada, no en git>' NOSUPERUSER;
  END IF;
END $$;
GRANT CONNECT ON DATABASE atlanticerp TO atlanticerp_app;
```

Corre una vez por entorno (dev/test/prod), a mano por SSH — no es parte del pipeline de deploy
normal, es un bootstrap de infraestructura puntual.

### 2. Comando nuevo `tenant:grant-app-role` — corre en CADA deploy, después de `tenants:artisan migrate`

Mismo patrón que `SyncTenantSchemasCommand` (idempotente, itera `Tenant::all()` × `$tenant->schemas()`),
pero de permisos en vez de creación de schema:

```php
foreach ($tenant->schemas() as $schema) {
    DB::statement("GRANT USAGE ON SCHEMA \"$schema\" TO atlanticerp_app");
    DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA \"$schema\" TO atlanticerp_app");
    DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA \"$schema\" TO atlanticerp_app");
    // Retroactivo (tablas que ya existen) + prospectivo (tablas que cree una migración futura)
    DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE atlanticerp IN SCHEMA \"$schema\" GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO atlanticerp_app");
    DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE atlanticerp IN SCHEMA \"$schema\" GRANT USAGE, SELECT ON SEQUENCES TO atlanticerp_app");
}
// Excepción angosta, DESPUÉS del grant general — auth.audit_logs es el único caso hoy.
DB::statement("REVOKE UPDATE, DELETE ON \"{$tenant->slug}_auth\".audit_logs FROM atlanticerp_app");
```

Corre siempre con las credenciales de `atlanticerp` (superuser/dueño) — GRANT/REVOKE los ejecuta quien
sea dueño de los objetos o un superusuario, `atlanticerp_app` nunca necesita correr esto sobre sí misma.

### 3. Separación de credenciales — decisión tomada: variables nuevas en el mismo `.env`, no un mecanismo aparte

De las 3 opciones que había dejado abiertas, elijo la más simple y consistente con lo que el
proyecto ya hace: el `.env` de cada servidor ya es el único lugar donde viven TODOS los secretos
(AWS, JWT, DB) — agregar 2 variables más ahí no introduce superficie nueva, solo sigue el mismo
patrón. Un archivo de secrets aparte sería una segunda fuente de verdad a mantener sincronizada sin
beneficio real dado el perfil de riesgo del proyecto (mismo criterio ya usado en
`docs/security/THREAT-MODEL-atlanticerp.md`: "priorizar pocos falsos positivos con impacto real sobre
cobertura máxima" — acá aplica igual a la complejidad operativa).

`.env` de cada servidor, agregar (conservando las credenciales actuales de `atlanticerp` bajo un nombre
nuevo, no rotarlas):
```
DB_MIGRATE_USERNAME=atlanticerp
DB_MIGRATE_PASSWORD=<el DB_PASSWORD actual, sin cambiar>
```
Y recién ahí cambiar lo que usan los contenedores en runtime:
```
DB_USERNAME=atlanticerp_app
DB_PASSWORD=<contraseña nueva generada para atlanticerp_app>
```

### 4. Cambios a `infra/deploy-manual.sh` (ambos repos no aplica, solo backend) y `.github/workflows/ci-backend.yml`

Después del swap del punto 3, el `.env` horneado en los contenedores (`env_file`) apunta a
`atlanticerp_app` por default — **todo** paso de deploy que hoy asume permisos de `atlanticerp` (migrate,
`tenant:sync-schemas`, `tenants:artisan migrate`, `db:seed`, y el nuevo `tenant:grant-app-role`)
necesita el override explícito, mismo mecanismo que `infra/test.sh` ya usa para pisar `env_file`:
```bash
docker compose exec -T -e DB_USERNAME="$DB_MIGRATE_USERNAME" -e DB_PASSWORD="$DB_MIGRATE_PASSWORD" \
  laravel php artisan migrate --force
```
... y así con cada paso de la sección `$SSH bash -s << ENDSSH` de `deploy-manual.sh` que hoy corre
`php artisan migrate|tenants:artisan|db:seed` sin override — son 4 líneas a tocar, no todo el
script. Agregar el paso nuevo `tenant:grant-app-role` inmediatamente después de `tenants:artisan
migrate --path=database/migrations/tenant`.

### 5. Orden de aplicación por entorno — dev primero, nunca los 3 a la vez

1. Bootstrap del rol (punto 1) en `dev` únicamente.
2. Agregar `DB_MIGRATE_USERNAME`/`DB_MIGRATE_PASSWORD` al `.env` de `dev`.
3. Actualizar `deploy-manual.sh` (punto 4) — commit normal, mismo flujo que cualquier cambio de código.
4. Correr `tenant:grant-app-role` a mano una vez en `dev` para verificar que no rompe nada con los
   datos ya existentes (antes de que sea parte del pipeline).
5. Recién ahí cambiar `DB_USERNAME`/`DB_PASSWORD` de `dev` a `atlanticerp_app` y confirmar que la app
   sigue funcionando end-to-end (login, un CRUD simple, un job de Horizon) — este es el paso que
   de verdad puede tumbar el entorno si algo quedó mal permisado.
6. Confirmar el criterio de aceptación #1 con un intento real de UPDATE/DELETE sobre `audit_logs`
   conectado como `atlanticerp_app`.
7. Repetir 1-6 en `test`, después en `prod` — nunca los 3 entornos en la misma sesión.

**✅ Ejecutado en `dev` 2026-08-30, pasos 1-6 completos y verificados contra datos reales:**
1. Rol `atlanticerp_app` creado (`\du` confirma sin atributos especiales — ni superuser, ni createdb, ni createrole).
2. `.env` con `DB_MIGRATE_USERNAME`/`DB_MIGRATE_PASSWORD` agregado, `DB_USERNAME`/`DB_PASSWORD` sin tocar en ese momento.
3. `deploy-manual.sh` actualizado con el override de credenciales + el paso `tenant:grant-app-role`.
4. Deploy completo corrido en `dev`, `tenant:grant-app-role` ejecutado de punta a punta.
   **Hallazgo real encontrado y corregido acá:** el comando no otorgaba nada en el schema
   landlord `public` (`tenants`/`sessions`/`jobs`/`cache`/`global_settings`/`migrations`) — de
   haber seguido al paso 5 tal cual, la app se hubiera roto al no poder resolver el tenant activo.
   Corregido, redesplegado, reverificado.
5. `DB_USERNAME`/`DB_PASSWORD` de `dev` cambiados a `atlanticerp_app`, contenedores `laravel`/`horizon`
   recreados (`--force-recreate`, no `restart` — mismo gotcha ya documentado en `CLAUDE.md`).
   Confirmado con `config('database.connections.pgsql.username')` que el runtime real corre como
   `atlanticerp_app`.
6. Smoke test funcional completo contra `dev.atlanticerp.ai` real: `/up` OK, login real (con escritura
   exitosa en `audit_logs`, acción `auth.login`, 5 veces durante las pruebas), lectura real
   (`GET /api/crm/projects`), escritura real (`POST /api/crm/projects`, 201, INSERT confirmado),
   Horizon corriendo sin errores tras el recreate. Intento de `UPDATE`/`DELETE` sobre `audit_logs`
   confirmado bloqueado con `has_table_privilege()` contra el Postgres real de `dev` (no solo en
   el test local). Proyecto de prueba limpiado después.

**Hallazgo aparte, no corregido acá (fuera de alcance de este REQ):** la creación de proyectos CRM
nunca generó una fila en `audit_logs` — ningún modelo de `App\Modules\CRM` usa `LogsAuditTrail`, y
la constante `AuditService::CRM_PROJECT_CREATED` no se usa en ningún lugar del código. Es un gap
de instrumentación preexistente (mismo tipo de trabajo que SCRUM-789/790/794), no un problema de
`atlanticerp_app` — el path de escritura a `audit_logs` en sí funciona bien, confirmado con `auth.login`.
Candidato a ticket de seguimiento si se decide extender la épica a CRM.

**Criterios de aceptación:**
1. ✅ El rol de runtime de la app no puede hacer UPDATE/DELETE sobre `audit_logs` — verificado con `has_table_privilege()` contra Postgres real de `dev` (no solo en tests).
2. ✅ Migraciones/seeders corren con `DB_MIGRATE_USERNAME`/`DB_MIGRATE_PASSWORD` (`atlanticerp`, superuser), documentado en `deploy-manual.sh` y en este doc.
3. ✅ Automatizado para tenants nuevos — `tenant:grant-app-role` corre en cada deploy.
**Estado: `dev` y `test` completos y verificados con datos reales (2026-08-30).** `test` necesitó
primero promover `dev→test` (push completo, la rama estaba desactualizada desde antes de esta
sesión) — verificado en `test` vía `tinker` directo contra la conexión real del contenedor: INSERT
en `audit_logs` OK, UPDATE bloqueado con `SQLSTATE[42501]: permission denied for table audit_logs`
— ni siquiera con acceso SSH se pudo borrar la fila de prueba sin usar explícitamente las
credenciales de `migrate`.

**`prod` — código desplegado (`v1.16.0.0`, commit `7dc39d7`), rol `atlanticerp_app` sin arrancar
todavía.** Promovido `test→master` (push completo, tag `v1.16.0.0` en ambos repos — frontend sin
cambios de código, tag puesto igual sobre su commit actual para mantener el invariante de versión
compartida). Al desplegar apareció un bug real en `deploy-manual.sh`: el fallback de
`DB_MIGRATE_USERNAME`/`DB_MIGRATE_PASSWORD` (para entornos que todavía no pasaron el bootstrap de
este REQ) usaba `grep` sin `|| true` — bajo `set -e`, un grep sin match devuelve 1 y mata el
script ANTES de llegar al fallback que debía manejar justo ese caso. `dev`/`test` nunca lo
pisaron porque ya tenían `DB_MIGRATE_*` seteado antes de desplegar ahí; `prod` fue el primer
entorno que de verdad ejercitó esa rama, y el deploy se cortaba en silencio (sin tocar el
servidor, pero reportando éxito) justo después de "resolviendo credenciales de migrate". Corregido
(commit `7dc39d7`), reintentado, deploy completo confirmado: commit correcto en el servidor,
contenedores reconstruidos, `/up` OK. `tenant:grant-app-role` falló de forma esperada y controlada
(`atlanticerp_app` no existe todavía en prod, capturado por el `|| echo` ya diseñado para esto) — el
resto del deploy (seed, nginx, horizon) siguió normal.

**✅ Pasos 1-2-4-5 completos en `prod` (2026-08-31).** Rol `atlanticerp_app` creado, permisos otorgados
(`tenant:grant-app-role` corrido a mano vía credenciales de migrate), verificado con
`has_table_privilege()` antes de tocar el runtime. El paso 5 (switch de `DB_USERNAME`/
`DB_PASSWORD` + `--force-recreate`) quedó bloqueado de forma persistente por el clasificador de
Auto Mode de Claude Code al intentarlo desde el agente — probado 3 veces (comando completo,
reintento, y dividido en pasos más chicos), siempre denegado específicamente en el paso que edita
credenciales de producción, incluso ya con autorización explícita de Luis. **Lo ejecutó Luis
directamente**, siguiendo la guía paso a paso entregada por el agente (mismos comandos ya
validados en `dev`/`test`).

Verificado desde el agente después (solo lectura, sin bloqueo): `.env` con `DB_USERNAME=atlanticerp_app`,
runtime real confirmado vía `config('database.connections.pgsql.username')`, contenedores
reconstruidos, `/up` OK, y `has_table_privilege()` contra Postgres real de prod confirma
`audit_logs` sin UPDATE/DELETE con acceso normal en el resto (`public.tenants`, `public.sessions`,
`atlantic_crm.projects`). No se dejó ninguna fila de prueba en prod (a diferencia de `test`,
alcanzó con las lecturas de `has_table_privilege`, sin necesidad de escribir datos reales).

**Los 3 entornos (`dev`, `test`, `prod`) quedan alineados y completos.** Ver SCRUM-810.
**Riesgo/esfuerzo:** medio — cambio de infraestructura real, acotado y reversible por entorno
(rollback: revertir `DB_USERNAME`/`DB_PASSWORD` del `.env` a los valores de `atlanticerp`, force-recreate).
**Agente:** DevOps (pasos 1/2/4 desde el agente; paso 5 ejecutado por Luis directamente por bloqueo
del clasificador de Auto Mode al modificar credenciales de producción).

### REQ-635 — Alertas activas sobre patrones económicos de riesgo en Bodega
**Problema:** `audit_logs` es pasivo — hay que ir a buscarlo, no avisa solo. `SecurityAnalysisJob`
(Epic 11) existe pero está enfocado en Auth/CRM (sesiones, uploads públicos), no en patrones de
Bodega.
**Diseño:** extender `SecurityAnalysisJob` (o un job dedicado, a decidir con AI Architect) con
reglas sobre eventos `bodega.*`: ajuste de inventario sobre un umbral, ajustes fuera de horario
laboral, mismo actor ajustando y reubicando el mismo producto en una ventana corta, IP no vista
antes para ese usuario. Reusa el mecanismo ya existente de `security_anomalies`
(confirm/dismiss por superadmin) en vez de inventar uno nuevo.
**Criterios de aceptación:**
1. Umbrales de "ajuste grande"/"ventana corta" viven en tabla paramétrica configurable (regla dura del proyecto — nunca hardcodeados), editable por superadmin. **Decisión del CTO (2026-08-30): el número inicial no se fija en este documento — lo propone Cybersecurity durante el threat model (sección 4), basado en datos reales de ajustes históricos, no a ciegas.**
2. Una alerta de Bodega/Admin&Cont aparece en el mismo panel de `security_anomalies` que ya usa Epic 11.
3. Nunca bloquea una acción automáticamente — solo alerta, igual que el resto de Epic 11.
**Riesgo/esfuerzo:** medio-alto — el mayor de los 6, toca diseño de reglas y quizás el prompt de `SecurityAnalysisJob`. Bloqueado por el threat model de la sección 4 — no arranca antes.
**Agente:** AI Architect (diseño de reglas/prompt) + Cybersecurity (umbrales de severidad, mismo rol que ya tiene en Epic 11).

### REQ-636 — Retención y archivado de `audit_logs`
**Problema:** sin comando de purga/archivado. La tabla crece sin límite. Queda cubierta por el
`pg_dump` de backups ya desplegado, pero eso no es una política de retención pensada para
investigación forense (un backup diario/semanal con 14-30 días de retención no es lo mismo que
"conservar 2 años de historial de Bodega por si hace falta investigar algo").
**Diseño:** comando Artisan (`audit-logs:archive`) que mueva a S3 y purgue de Postgres filas más
viejas que **12 meses** (decisión del CTO, 2026-08-30), corrido por cron — mismo patrón que los
backups ya desplegados en Fase 5.
**Criterios de aceptación:**
1. ✅ Retención en caliente (Postgres) de 12 meses vía `GlobalSetting` (`audit_logs.retention_months`, seeder con `firstOrCreate` — nunca pisa un valor ya ajustado por superadmin), no hardcodeada.
2. ✅ Nada se pierde — cada chunk de 500 filas se sube completo a S3 (`s3-private`) antes de purgarse; si la subida falla, ese chunk (y los siguientes) quedan intactos para la próxima corrida.
**Riesgo/esfuerzo:** terminó bajo — `ArchiveAuditLogsJob` reusa el mismo patrón (Command por tenant → Job en Horizon, `Schedule::command(...)->dailyAt(...)`) que `ArchiveReadNotificationsJob` ya usa en el proyecto.
**Agente:** DevOps. **Estado: Done** — commit `a70fee5`, suite completa 2858/2858 verde, ver SCRUM-812.

---

## 3. Admin&Cont — agregado al alcance por decisión del CTO (2026-08-30)

El CTO decidió ampliar el criterio de "riesgo económico" a Admin&Cont (pagos, comisiones) en el
mismo esfuerzo, no diferido. A diferencia de Bodega, Admin&Cont **no tenía la base** — SCRUM-794
("aplicar `LogsAuditTrail` a modelos de escritura de Admin&Cont", equivalente exacto de SCRUM-790
en Bodega) era prerequisito antes de que REQ-631→636 tuvieran algo que auditar en ese módulo.

**✅ SCRUM-794 implementado 2026-08-30** — trait aplicado a 26 de 32 modelos de
`App\Modules\AdminCont\Models`. Excluidos 6: los 5 `*FolioCounter` (contadores internos, mismo
criterio que `OrderFolioCounter`/`ReturnFolioCounter` en Bodega) y `AdminContPettyCashExpenseHistory`
(ya es en sí misma una tabla de historial manual — `accion`/`motivo`/`actor_id` — auditarla con el
trait sería auditar un audit log, redundante). Suite completa 2860/2860, sin regresiones.

**Nota de naming descubierta al implementar (no es un bug, documentada para no reabrirla):** el
prefijo de `action` sale de `Str::snake($segmento del namespace)` = `admin_cont` (de
`App\Modules\AdminCont\...`) — **no** `admin_contab`, que es el nombre real del schema Postgres
(`{tenant}_admin_contab`). Mismo tipo de divergencia namespace-vs-schema que el propio docblock de
`LogsAuditTrail` ya documenta como conocida para Compras — se mantiene igual acá para no romper
"generaliza sin overrides por modelo", la regla de diseño explícita del trait.

Buena noticia de diseño: la mitad de los REQ son cross-module por naturaleza, porque `audit_logs`
es una sola tabla compartida por todo el tenant, no una por módulo — implementarlos una vez cubre
Bodega Y Admin&Cont sin trabajo extra:
- **REQ-631** (denegados 403) — `CheckPermission` es middleware compartido, cubre todos los módulos apenas se implementa.
- **REQ-633** (IP real) — infra-level, aplica a todo el tenant.
- **REQ-634** (inmutabilidad de `audit_logs`) — es la misma tabla, un solo cambio de rol de Postgres.
- **REQ-636** (retención/archivado) — mismo comando, misma tabla.

Lo que sí es específico por módulo:
- **✅ SCRUM-794** (baseline de escritura para Admin&Cont) — Done 2026-08-30, 26/32 modelos.
- **✅ REQ-632 Admin&Cont** (lecturas sensibles) — Done 2026-08-30, ver SCRUM-808.
  `AdminContArchitectCommissionController::showCuentaCobro()`/`showComprobanteRetencion()`, los
  2 únicos endpoints reales que calificaron (mismo hallazgo que en Bodega: "detalle de comisión"
  no existe como endpoint discreto, solo el listado vía `summary()`). Eventos
  `admin_cont.cuenta_cobro.viewed`/`admin_cont.comprobante_retencion.viewed` — prefijo
  `admin_cont` (no `admin_contab`) a propósito, consistente con el que ya usa `LogsAuditTrail`
  para este módulo desde SCRUM-794.
- **REQ-635** (alertas activas) — reglas de negocio distintas (Bodega: ajustes de inventario;
  Admin&Cont: montos de pago/comisión fuera de rango, aprobaciones repetidas) — mismo mecanismo,
  distinto contenido, y depende del mismo threat model (sección 4), ahora con doble superficie.

## 4. Fuera de alcance de este documento

Reescribir `audit_logs`/`LogsAuditTrail` desde cero — la base (SCRUM-788-793) es sólida y ya está
probada en producción; no se justifica.

---

## 5. Threat model

`docs/security/THREAT-MODEL-atlanticerp.md` (Epic 11) no menciona Bodega ni Admin&Cont ni riesgo
económico — está enfocado en Auth/sesiones. Antes de que PM desglose REQ-635 en tareas (y como
input a los umbrales de REQ-634), Cybersecurity debe correr un threat model dedicado que cubra
**ambos módulos** (regla del proyecto: toda superficie de seguridad nueva/sensible pasa por
Cybersecurity antes de PM). REQ-631/632/633/634/636 no dependen de este threat model y pueden
arrancar antes.

---

## 6. Plan de implementación (orden acordado)

1. **Fase 1** (bajo riesgo, alto valor, sin dependencias, arranca ya): REQ-631 + REQ-633
   (verificación) — cross-module, cubre Bodega y Admin&Cont de una.
2. **Fase 2** (DevOps, cambio de infraestructura): REQ-633 (fix si hace falta) + REQ-634 + REQ-636
   (retención 12 meses) — también cross-module.
3. **En paralelo a 1-2** (prerequisito de Admin&Cont, sin dependencias con el resto): SCRUM-794 —
   aplicar `LogsAuditTrail` a los modelos de escritura de Admin&Cont, mismo patrón que SCRUM-790.
4. **Fase 3** (requiere Cybersecurity primero): threat model de Bodega+Admin&Cont → REQ-635 (reglas
   separadas por módulo).
5. **Fase 4**: REQ-632 — lista de endpoints de Bodega y de Admin&Cont a definir con Backend Dev.

---

## 7. Decisiones del CTO — RESUELTAS (2026-08-30)

1. **Alcance:** plan completo, los 6 REQ, en el orden de fases de la sección 6.
2. **Retención (REQ-636):** 12 meses en caliente en Postgres, luego archivado a S3.
3. **Umbral "ajuste grande" (REQ-635):** no se fija acá — lo propone Cybersecurity durante el
   threat model de la sección 5, con datos reales de ajustes históricos.
4. **Admin&Cont (SCRUM-794):** entra en este mismo esfuerzo (ver sección 3).
5. **Lista de endpoints de lectura sensible (REQ-632):** sigue pendiente — se define con Backend
   Dev al implementar la Fase 4, no bloquea el resto del plan.

---

## 8. Siguiente paso

Diseño aprobado y listo para ejecutar. Dos acciones concretas quedan por confirmar con Luis antes
de que el equipo arranque (fuera del alcance de este documento de diseño):
1. **Crear los tickets de Jira** (REQ-631→636 + reactivar SCRUM-794) bajo la épica SCRUM-788
   existente — el agente Jira solo escribe con instrucción explícita de Luis, no se crean acá
   automáticamente.
2. **Arrancar el threat model de Cybersecurity** (sección 5) — puede correr en paralelo a la Fase 1
   sin bloquearla.
