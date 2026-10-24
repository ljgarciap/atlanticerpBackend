# ADR-SCRUM815 — Backup inmutable de `audit_logs` (S3 Object Lock)

**Status:** Implemented (2026-09-02) — solo prod.

## Contexto

`audit_logs` ya tiene dos capas de protección: inmutabilidad a nivel de rol Postgres (REQ-634,
SCRUM-810 — `atlanticerp_app` no tiene `UPDATE`/`DELETE` sobre la tabla) y archivado a S3 antes de purgar
filas de más de 12 meses (REQ-636, SCRUM-812, comando `audit-logs:archive`). Ninguna de las dos
protege contra un superuser real de Postgres (acceso de emergencia, credencial comprometida) que
altere filas directamente — pedido explícito del CTO (2026-08-31) para cerrar ese hueco con una
tercera capa, a nivel de backup, independiente de la aplicación.

## Decisión

Bucket S3 nuevo `atlanticerp-prod-audit-immutable` (Object Lock no es retroactivo — no se puede activar
en `atlanticerp-prod`), con:

- **Object Lock, modo Governance, retención default 90 días.** Se descartó Compliance: este canal
  es para investigación forense interna, no un mandato regulatorio externo conocido, y Compliance
  no permite destrabar un lock ni para el root de la cuenta AWS — un riesgo real de quedar sin
  poder corregir un error propio de configuración durante 90 días. Governance protege igual contra
  el escenario real (alguien con `s3:DeleteObject` normal, incluida una credencial de app
  comprometida) y deja una vía de escape controlada (`s3:BypassGovernanceRetention`, nunca
  concedido a ningún rol/usuario por defecto — solo se otorgaría puntual y manual si alguna vez
  hiciera falta).
- **IAM:** policy nueva `atlanticerp-prod-audit-immutable-write-policy`, solo `s3:PutObject` sobre este
  bucket — nunca `s3:DeleteObject` ni el permiso de bypass. Adjuntada al usuario de servicio ya
  existente `atlanticerp-prod-s3` (mismo que usa la app para `atlanticerp-prod`) en vez de crear una credencial
  nueva: el canal de escritura corre desde el mismo proceso/host que ya tiene acceso de lectura
  completo a Postgres, así que separar la credencial de escritura a S3 no agrega un límite real
  contra el actor que este diseño busca frenar (alguien con superuser de Postgres) — sí lo agrega
  el propio Object Lock del lado de S3, que es independiente de qué credencial de IAM escribió el
  objeto.
- **Mecanismo de escritura:** `infra/scripts/backup-audit-logs-immutable.sh` — dump diario
  (`pg_dump -t '*.audit_logs'`, cubre todos los schemas de tenant sin enumerarlos) vía cron en el
  host EC2, mismo patrón que `backup-postgres.sh` (Fase 5). Deliberadamente un script de
  infraestructura, no un Job de Laravel — la garantía de esta capa depende de estar desacoplada de
  la aplicación, no de reutilizar su runtime.
- **Retención real aplicada dos veces:** el bucket tiene default retention de 90 días (por si el
  script algún día no pasa el flag), y el script además pasa `--object-lock-retain-until-date`
  explícito por objeto (90 días) — belt-and-suspenders. Una lifecycle rule de expiración a los 90
  días (`atlanticerp-audit-logs-immutable-retention-90d`) limpia los objetos ya destrabados, mismo
  patrón que la retención de `postgres-backups/` en `backup-postgres.sh`.
- **Alcance: solo prod.** Mismo criterio ya aplicado el 2026-08-31 al resto de la política de
  backups (AWS Backup de EBS pasó a ser solo-prod ese mismo día) — dev/test no tienen datos que no
  estén también en la app real de esos entornos, y se recrean desde la AMI base si hiciera falta.

## Pendiente

- Programar el cron en el host de prod (`30 3 * * * .../backup-audit-logs-immutable.sh >>
  /var/log/atlanticerp-backup-audit.log 2>&1`, mismo host que `backup-postgres.sh`) — requiere acceso SSH
  a producción, confirmado puntualmente antes de aplicarlo.
- `AWS_AUDIT_IMMUTABLE_BUCKET=atlanticerp-prod-audit-immutable` debe quedar exportado en el entorno del
  cron de prod (ver `.env.example`).
