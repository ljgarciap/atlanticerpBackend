# Runbook — Gate local, promoción y deploy manual (backend)

> Vigente desde 2026-10-03 (ADR-009: `docs/adr/ADR-009-gate-local-con-recibo.md` en este repo).
> El frontend tiene su propia versión corta: `atlanticerp-frontend/docs/ci-cd-gate-local.md`. **Un release completo
> necesita los dos repos**: cada uno tiene su gate, su hook y su script de deploy.

## 1. Qué cambió y por qué

El plan Free de GitHub de la organización incluye **2.000 minutos de Actions al mes** y se agotaron en
agosto y septiembre (los jobs ni arrancaban). Además, el mismo commit corría su suite completa tres veces
(`dev`, `test`, `master`). Ahora:

| Rama | Qué hace Actions al empujar | Duración aprox. |
|---|---|---|
| `dev` | **Solo deploy** (sin CI) + verificación `/up` y API | ~1–2 min |
| `test` | **Solo deploy** (sin CI) + verificación | ~1–2 min |
| `master` | **CI completo** (PHPStan + PHPUnit) y luego deploy | ~25 min |
| `hotfix/**`, PR a `master` | CI completo (sin deploy) | ~23 min |
| Cambios solo en `docs/**` o `*.md` | **Nada** | — |

La verificación completa la hace **cada desarrollador, antes de empujar, con `infra/gate.sh`**. El gate deja
un *recibo* por commit y un hook de git (`pre-push`) **impide empujar a `dev`/`test`/`master` sin recibo**.

## 2. Preparación (una sola vez por clon)

```bash
cd atlanticerp-backend
git config core.hooksPath .githooks        # activa el hook pre-push (NO es automático al clonar)
git config core.hooksPath                  # debe imprimir: .githooks
```

Requisitos para correr el gate: el stack Docker local arriba (`docker compose -f infra/docker-compose.yml up -d`),
con el contenedor `infra-laravel-1` montando **este** repo. (Otro proyecto, `atlanticerp-backend`, usa el mismo
nombre de proyecto Compose `infra`: si ese stack está arriba, el gate se detiene con un mensaje claro.)

Para el deploy manual además: la llave SSH `~/.ssh/atlanticerp/atlanticerp-key.pem` (pídasela a Luis; `chmod 400`).

## 3. Flujo normal de trabajo

```bash
# 1) Commitear el trabajo (el gate exige que el árbol de código sea EXACTAMENTE el commit)
git add … && git commit -m "tipo(scope): descripción"

# 2) Correr el gate (PHPStan nivel 8 + suite completa; ~8-12 min). Deja el recibo del commit.
infra/gate.sh

# 3) Empujar a dev → Actions despliega en ~1-2 min
git push origin dev

# 4) Promover (CTO valida en dev → test; el cliente valida en test → master). Se empuja el MISMO commit:
git fetch origin
git push origin origin/dev:test            # → test.atlanticerp.ai   (el commit ya tiene recibo: pasa solo)
git push origin origin/dev:master          # → atlanticerp.ai: Actions corre el CI completo y luego despliega (~25 min)
```

Reglas:
- Promover siempre con `origin/<rama>:<destino>` (referencias remotas), **nunca** con ramas locales: las locales
  `test`/`master` suelen quedar atrás.
- Un commit de **solo documentación** (`docs/**`, `*.md`) encima de uno con recibo **hereda** el recibo: no
  hace falta volver a correr el gate.
- Tras llegar a producción: tag de versión en **ambos** repos (proceso en `atlanticerp/docs/release-process.md`, carpeta de
  documentación del workspace que acompaña a los dos repos).

## 4. Qué hace el gate y cómo resolver sus errores

`infra/gate.sh` (opciones: `--force` repite aunque haya recibo; `--check [sha]` solo consulta, exit 0/1):

| Paso | Falla con… | Qué hacer |
|---|---|---|
| 1. Árbol = commit | `hay cambios de código sin commitear` / `archivos de código sin seguir` | Commitee o descarte esos archivos. Un test que pasa por un archivo olvidado es justo lo que un CI limpio atrapaba. Solo cuentan `app routes database config tests resources bootstrap`, `composer.*`, `phpunit.xml`, `phpstan.neon`, `.github`; los demás archivos (informes, ruido) se ignoran. |
| 2. Contenedor | `infra-laravel-1 no está corriendo o no monta …` | Levante el stack de ESTE repo; apague el de `atlanticerp-backend` si comparte el nombre `infra`. |
| 3. PHPStan | errores de análisis estático | Corríjalos (`docker compose -f infra/docker-compose.yml exec -T laravel vendor/bin/phpstan analyse`). |
| 4. PHPUnit | tests fallidos | Corrija el código. Si un test falla solo a veces, ver §7. |

El recibo queda en `~/.atlanticerp-gate/atlanticerp-backend/<sha>` (por máquina; se puede borrar sin riesgo: solo obliga a
volver a correr el gate). **No corra el gate mientras otra sesión usa el mismo Docker/base de pruebas**:
`infra/test.sh` borra los esquemas de tenant de la base de pruebas.

## 5. Si el hook bloquea el push

```
pre-push: BLOQUEADO — <sha> no tiene recibo verde del gate local para empujar a dev.
```
Corra `infra/gate.sh` con ese commit en `HEAD` y reintente. **No use `git push --no-verify`.**

Escape explícito, solo en emergencias aprobadas por Luis (queda en la salida del push; anótelo en el ticket):
```bash
ATLANTICERP_SKIP_GATE=1 git push origin dev
```

## 6. Deploy manual (cuando Actions no tiene minutos o está caído)

**Cómo saber que es la cuota:** en GitHub → Actions las corridas salen en rojo **sin ningún paso ejecutado**
(el runner nunca arrancó). Para ver el consumo del mes:

```bash
gh api "organizations/Grupo-Lafayette/settings/billing/usage/summary?year=2026&month=10" \
  --jq '.usageItems[]|select(.sku=="actions_linux")|"\(.grossQuantity) min usados de 2000"'
```
El tope de gasto (para permitir exceso a US$ 0,006/min) se configura en GitHub → Settings → Billing → Spending limits.

**Procedimiento** (en cada repo; el backend y el frontend tienen su propio script):

```bash
cd atlanticerp-backend
git fetch origin
git checkout <dev|test|master> && git merge --ff-only origin/<dev|test|master>   # HEAD debe ser igual a origin/<rama>
infra/deploy-manual.sh <dev|test|prod>
```
- El script reutiliza el recibo del gate (si el commit ya lo tiene, **no vuelve a correr los tests**: 1-2 min). Si
  no hay recibo, corre el gate completo antes de tocar el servidor. `--force-gate` lo repite siempre.
- Replica exactamente los pasos del job de Actions (checkout forzado de la rama, `composer install`, build de
  Docker, migraciones landlord y de tenant, seed, reload de nginx, `horizon:terminate`) y termina verificando `/up`.
- Haga lo mismo en `atlanticerp-frontend` (construye `dist/` localmente y lo sube por `rsync`).
- Orden recomendado: `dev` → `test` → `prod`, y un entorno a la vez.
- Para volver a la normalidad no hace falta nada: el siguiente push dispara Actions con normalidad.

## 7. Tests intermitentes (flaky)

- Si un test del CI de `master` falla y en local pasa, **no empuje de nuevo a ciegas**: abra el log
  (`gh run view <id> --log-failed`), identifique el test y relance solo el job fallido: `gh run rerun <id> --failed`.
- Un test que falla a veces es una falla real hasta que se demuestre lo contrario: registre un ticket con el
  nombre del test, el log y qué se intentó. Ejemplo resuelto: `InvoiceConcurrencyTest` fallaba por el **orden
  de borrado en su limpieza** (la raíz de la familia de pedidos antes que sus entregas violaba
  `orders_pipeline_card_root_uq`) y, al abortar la limpieza, dejaba filas sueltas que rompían otros tests.
- Los tests de concurrencia real (`tests/Feature/*/Concurrency`) hacen *commit* de verdad en la base: su
  `tearDown()` debe limpiar todo y cada paso de limpieza debe ser independiente.

## 8. Qué NO hacer

- No edite `.github/workflows/*` ni `.githooks/*` sin revisión del Arquitecto/DevOps: un error deja sin deploy a
  todos los entornos.
- No empuje con `--force` a `dev`/`test`/`master`.
- No use `git stash` en este repo: se comparte con otras sesiones y hay stashes ajenos.
- No dé por bueno un deploy sin ver el verificador final (`/up` = 200 y la API = 401 sin token).

## 9. Referencias

- ADR-009 — decisión y alternativas descartadas: `docs/adr/ADR-009-gate-local-con-recibo.md`
- Proceso de release y hotfix: `atlanticerp/docs/release-process.md` (workspace)
- Workflow: `.github/workflows/ci-backend.yml` · Hook: `.githooks/pre-push` · Gate: `infra/gate.sh` · Deploy manual: `infra/deploy-manual.sh`
