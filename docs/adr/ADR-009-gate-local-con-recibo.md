# ADR-009 — CI/CD: gate local con recibo; Actions solo para producción

**Date:** 2026-10-03
**Status:** Implemented — desplegado en dev el 2026-10-03: backend 52 s (CI omitido), frontend 105 s (build + deploy, tests omitidos); verificación posterior OK
**Deciders:** Luis (decisión), Arquitecto + DevOps (diseño)
**Copia versionada:** `atlanticerp-backend/docs/adr/ADR-009-gate-local-con-recibo.md` (esta carpeta del workspace no está en git)

---

## Contexto

El 2-oct-2026 un deploy tardó ~25 minutos. Medido, el deploy en sí son **40 s** (backend) y **13 s**
(frontend); el resto es la suite de pruebas del CI (PHPUnit 18 min + PHPStan 4 min; Vitest 7 min).

Además, el plan **Free** de la organización `Grupo-Lafayette` incluye **2.000 min/mes** de Actions y
se agotaron: agosto y septiembre cerraron exactamente en 2.000, y octubre llevaba 1.084 min en dos días
(821 backend + 263 frontend). Cuando se acaba la cuota los jobs ni arrancan: de las últimas 150
corridas, 107 (backend) y 113 (frontend) fallaron **sin ejecutar un solo paso**, y hubo que desplegar a
mano con `infra/deploy-manual.sh`. Exceso: US$ 0,006 por minuto de Linux.

La causa del gasto: **el mismo commit corría su suite completa tres veces** (push a `dev`, `test` y
`master`; las promociones son avances rápidos del mismo SHA) y hasta los commits que solo suben informes
(`docs/`) disparaban los 23 minutos. Antes de cada push ya corremos en local la suite completa, PHPStan y
el Pre-QA, así que el CI de `dev`/`test` repetía una verificación ya hecha.

## Decisión

1. **Gate local con recibo.** `infra/gate.sh` (en cada repo) corre la verificación completa una vez
   (backend: PHPStan nivel 8 + suite; frontend: tsc + build + Vitest) y escribe un recibo por SHA en
   `~/.atlanticerp-gate/<repo>/<sha>`. Falla si el árbol de trabajo no es el commit (código modificado o sin
   seguir: un test que pasa por un archivo olvidado es lo que un CI limpio atrapaba) y, en el backend, si
   el contenedor `laravel` no monta ese repo (colisión conocida del nombre de proyecto `infra`).
2. **Hook `pre-push`** (`.githooks/pre-push`, activar con `git config core.hooksPath .githooks`): exige un
   recibo verde del SHA para empujar a `dev`, `test` o `master`. Una promoción (`git push origin
   origin/dev:test`) empuja el mismo SHA y pasa sola. Un push que solo toca `docs/` o `*.md` no necesita
   recibo. Escape explícito y visible: `ATLANTICERP_SKIP_GATE=1`.
3. **GitHub Actions:**
   - `dev` y `test`: **solo deploy**, sin CI. En el frontend sigue el *build* (~1 min) porque produce el
     `dist/` que se sube.
   - `master`: **CI completo y luego deploy** (última barrera antes de producción). También en ramas
     `hotfix/**`, PRs a `master` y `workflow_dispatch`.
   - Los pushes que solo tocan `docs/**` o `**.md` no disparan el workflow.
   - Cada deploy termina verificando el sitio (`/up` = 200 y la API = 401 sin token) con reintentos.
   - La `concurrency` pasa de **workflow** a **job**: con `cancel-in-progress` a nivel de workflow, un
     segundo push cancelaría un deploy a medias (migraciones o `rsync --delete` a medio aplicar). El CI se
     cancela; los deploys se encolan y nunca se cancelan.
4. **Deploy manual** (`infra/deploy-manual.sh <env>`, respaldo cuando Actions no tiene minutos): llama a
   `gate.sh`, que reutiliza el recibo verde del commit; queda en 1–2 minutos. `--force-gate` repite todo.

## Consecuencias

**A favor**
- `dev`/`test`: de ~25 min a ~1–2 min. Minutos de Actions: −70 % a −80 % (la suite corre una vez por
  entrega, en `master`, y no una vez por commit y por entorno).
- La verificación deja de depender de si el mes alcanza.

**En contra / a vigilar**
- `master` sigue tardando ~25 min (el CI completo está ahí). Para una urgencia: `infra/deploy-manual.sh prod`.
- La carrera intermitente de `InvoiceConcurrencyTest` ahora puede frenar producción en vez de `dev`:
  hay que arreglarla.
- El gate depende de la disciplina local: lo hace cumplir el hook, pero `--no-verify` o `ATLANTICERP_SKIP_GATE=1`
  lo saltan. El agente Sandbox (push directo a `dev`) pasa por el mismo hook.
- La verificación en un runner limpio ya no ocurre antes de `dev`/`test`: se compensa con el chequeo de
  árbol limpio del gate y con el CI limpio de `master`.
- Los recibos viven en la máquina de quien empuja; no se comparten entre máquinas.

## Alternativas descartadas
- **Reutilizar el CI de `dev` al promover** (saltar tests si el SHA ya pasó): conserva el CI en `dev`
  (~23 min por push) y su gasto de minutos; Luis prefirió quitarlo de `dev`/`test`.
- **Pagar el exceso de minutos** (~US$ 0,006/min): complemento válido, no sustituto; se recomienda un tope
  bajo de gasto para no quedar sin Actions en una urgencia.
- **Tests en paralelo / shards**: el esquema por tenant y los tests de concurrencia necesitan una base por
  proceso; los shards gastan más minutos. Fuera de alcance.
- **Quitar la cobertura (`pcov`)**: Luis estimó que el ahorro es poco relevante; se deja como está
  (el CI solo corre ya en `master`).
