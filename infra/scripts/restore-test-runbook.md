# Runbook de recuperación ante desastres (DR) — AtlanticERP

Cubre dos situaciones distintas con el mismo mecanismo de fondo:

- **Simulacro trimestral programado** (sin urgencia, valida que los backups sirven de verdad).
- **Recuperación real ante una eventualidad** (urgente, sigue los mismos pasos técnicos pero con
  decisiones de negocio de por medio: qué ambiente, qué tan viejo un punto de recuperación es
  aceptable, a quién avisar).

Nace de la Fase 2 de la propuesta de logs/backups (aprobada 2026-08-20) — un backup nunca probado
no es una garantía real de recuperación. Ver `memory/project_propuesta_logs_backups_aprobada_20260820.md`
para el detalle completo de qué backups existen hoy (pg_dump→S3 + AWS Backup de EBS, ver ese
archivo o `atlanticerp/CLAUDE.md` § Infraestructura AWS).

## ¿Hace falta un agente especializado para esto?

No — no es un rol nuevo, es un runbook. La recuperación es trabajo de infraestructura AWS pura
(restaurar un volumen EBS, restaurar un dump de Postgres, verificar) — exactamente lo que ya cubren
`devops.md` y `aws-architect.md` del roster existente (ver `../../.claude/agents/`). El patrón del
proyecto para crear un rol nuevo (`pre-qa.md`, `visual-reviewer.md`) fue siempre un gap **recurrente
y ya probado** encontrado en producción real, no un escenario hipotético — DR todavía no tiene ese
historial. Lo que faltaba no era un agente, era este documento: un procedimiento escrito que
cualquiera (Arquitecto/DevOps/AWS Architect, o Luis directamente) pueda seguir sin tener que
reconstruir la lógica de decisión en el momento de un incidente real, que es el peor momento para
improvisar. Si algún día DR falla dos veces por el mismo motivo evitable, ahí sí se justificaría
promoverlo a rol propio — no antes.

## Paso 0 — Clasificar el incidente antes de tocar nada

| Síntoma | Camino |
|---|---|
| Datos mal (borrado accidental, migración que corrompió algo, bug de negocio) pero el servidor responde | **A — Restauración lógica** |
| Servidor perdido/corrupto (EC2 inaccesible, disco dañado, instancia terminada) | **B — Restauración de volumen EBS** |
| Las dos cosas | B primero (reconstruye la máquina), después A si hace falta un punto de datos más fino que el snapshot |

**Quién decide:** Luis o quien esté en el rol Arquitecto en ese momento — nunca una decisión que un
agente tome solo y en silencio. Igual que cualquier `git push`/`ssh`/`aws` de alto riesgo, esto se
declara explícitamente antes de ejecutar, no se asume.

**Antes de restaurar cualquier cosa:** confirmar el alcance real (¿un tenant, un módulo, toda la
BD?) y, si el ambiente todavía responde aunque sea parcialmente, sacar un backup adicional del
estado actual (aunque esté "roto") — sirve para comparar después y es la única red de seguridad si
la restauración misma sale mal.

## Camino A — Restauración lógica (`pg_dump`, S3)

1. Ubicar el dump más reciente (o el punto en el tiempo deseado, dentro de la retención — 14 días
   dev/test, 30 días prod):
   ```bash
   aws s3 ls s3://atlanticerp-<env>/postgres-backups/ --recursive | sort
   ```
2. Bajarlo y restaurarlo **siempre contra un Postgres descartable primero**, nunca directo sobre
   el ambiente real:
   ```bash
   aws s3 cp s3://atlanticerp-<env>/postgres-backups/<archivo>.sql.gz .
   gunzip <archivo>.sql.gz
   docker run --rm -d --name restore-test -e POSTGRES_PASSWORD=test postgres:16-alpine
   sleep 5
   docker exec -i restore-test psql -U postgres -c "CREATE DATABASE atlanticerp_restore_test;"
   docker exec -i restore-test psql -U postgres -d atlanticerp_restore_test < <archivo>.sql
   ```
3. **Criterio de validación antes de promover** (no alcanza con "restauró sin error"):
   - Los schemas del tenant existen (`atlantic_auth`, `atlantic_crm`, `atlantic_servicios`, etc. — ver `Tenant::schemas()`).
   - Conteos de filas razonables en tablas clave (`users`, `projects`, `tickets`) — no vacíos, no
     sospechosamente iguales a un ambiente distinto.
   - Timestamp del dump coincide con la ventana de pérdida de datos aceptada para este incidente.
4. Recién confirmado esto, aplicar contra el ambiente real (con el backup adicional del paso 0 ya
   hecho) y correr un smoke test manual de login + una pantalla core (Pipeline/Tickets) antes de
   avisar que está resuelto.
5. **Rollback si algo sale mal en el paso 4:** restaurar el backup adicional del paso 0 — por eso
   nunca se salta ese paso aunque el ambiente esté "roto".

## Camino B — Restauración de volumen EBS (AWS Backup)

1. Ubicar el recovery point más reciente del volumen afectado:
   ```bash
   aws backup list-recovery-points-by-backup-vault --backup-vault-name atlanticerp-backups \
     --query 'RecoveryPoints[?contains(ResourceArn, `<volume-id>`)].[RecoveryPointArn,CreationDate]' \
     --output table
   ```
2. Iniciar la restauración — esto crea un **volumen EBS nuevo**, nunca pisa el existente:
   ```bash
   aws backup start-restore-job \
     --recovery-point-arn <arn> \
     --iam-role-arn arn:aws:iam::940827433405:role/atlanticerp-aws-backup \
     --metadata '{"newVolumeAvailabilityZone":"<az>"}' \
     --resource-type EBS
   ```
3. Esperar a que el job termine (`aws backup describe-restore-job --restore-job-id <id>`) y
   adjuntar el volumen nuevo a una instancia — la misma si sobrevivió, o una de reemplazo clonada
   de la AMI `atlanticerp-base`.
4. **Criterio de validación antes de promover:** montar el volumen aparte primero (no directo como
   reemplazo del volumen en uso), confirmar que `/data/postgres/pgdata` o `/` tiene el contenido
   esperado, recién ahí hacer el swap final.
5. Reconectar el volumen restaurado a la instancia real, levantar el stack (`docker compose up -d`)
   y correr el mismo smoke test del Camino A antes de avisar que está resuelto.

## Simulacro trimestral (no urgente)

Corre ambos caminos completos contra un entorno de prueba (nunca contra un ambiente real), sin
presión de tiempo — el objetivo es medir cuánto tarda de verdad y encontrar fricciones antes de que
importen. Próxima corrida: **2026-11-21** (actualizar esta fecha cada vez que se ejecute).

## Post-incidente / post-simulacro

- Anotar como memoria de proyecto: qué pasó, qué camino se usó, cuánto tardó de punta a punta,
  cualquier fricción o paso que faltaba documentar acá.
- Si el simulacro/incidente reveló que un paso de este runbook estaba mal o incompleto, corregirlo
  en este mismo archivo antes de cerrar la sesión — no dejarlo como "ya lo sé para la próxima".
- Notificación a Luis: sigue el mismo mecanismo consolidado del Arquitecto (ver "Notificaciones" en
  el `CLAUDE.md` raíz) — un incidente real de este tipo siempre amerita aviso inmediato, no espera
  al resumen de cierre de sesión.
