# Senior Review — documentación interactiva de la API en dev (Scramble)

2026-10-12 · Commit `46349ebd` + corrección · Veredicto: **APROBADO con condiciones** (ver al final).

## Qué se revisó
`ApiDocsServiceProvider` (gate y comando `scramble:cache`), `config/scramble.php` (`servers`), `infra/nginx/entrypoint.sh` y las dos plantillas de nginx, `.github/workflows/ci-backend.yml` (solo el job de dev), `infra/deploy-manual.sh`, `ApiDocsGateTest`, runbook.

## Hallazgos
| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| S1 | Media | `scramble:cache` corría antes de migrar y sembrar: consulta la tabla de tenants y analiza modelos, así que fallaba en una base recién creada | Corregido: ahora corre después del seed |
| C1 | Alta | Permisos del archivo de contraseñas (ver informe de seguridad) | Corregido |
| S2 | Baja | El gate acepta `mixed $user = null` para que Laravel lo evalúe con invitados; correcto, pero depende de que el default sea `null` | Sin cambio, cubierto por `ApiDocsGateTest` |
| S3 | Baja | `up -d --force-recreate nginx` corta nginx unos segundos en cada despliegue de dev | Aceptado, solo dev |

## Verificado
- Los jobs de test y prod y `deploy-manual.sh` para esos entornos conservan `--no-dev` y no ejecutan nada de Scramble.
- Sin la variable, el `entrypoint.sh` deja el snippet vacío: el comportamiento de nginx es el de siempre.
- PHPStan nivel 8 sin errores en los archivos tocados; lógica del `entrypoint.sh` probada en simulación.

## Condiciones antes de dar por cerrado
1. Suite completa y PHPStan vía `infra/gate.sh` (recibo verde).
2. Alta de `API_DOCS_BASIC_AUTH_B64` en el `.env` de dev (la hace quien administra el servidor) y verificación 401/200 descrita en el informe de seguridad.
3. Primera ejecución real de nginx con la regla nueva: no se pudo probar en local (nginx no disponible); queda como verificación en dev.
