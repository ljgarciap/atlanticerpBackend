# CloudWatch Agent — Fase 1 (propuesta de logs/backups aprobada 2026-08-20)

Config real desplegada el 2026-08-21 en las 3 EC2 (`atlanticerp-dev`/`atlanticerp-test`/`atlanticerp-prod`). Estos
archivos son la fuente de verdad versionada — el archivo real en cada host vive en
`/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json` y no se sincroniza solo,
copiarlo a mano si se edita acá.

Requiere el instance profile IAM `atlanticerp-cloudwatch-agent` (`CloudWatchAgentServerPolicy`) ya
asociado a la instancia — ver `memory/project_propuesta_logs_backups_aprobada_20260820.md` para
el detalle completo de los recursos AWS creados (log groups, alarmas, SNS).

## Instalar/actualizar en una instancia

```bash
curl -s -o /tmp/amazon-cloudwatch-agent.deb \
  https://amazoncloudwatch-agent.s3.amazonaws.com/ubuntu/amd64/latest/amazon-cloudwatch-agent.deb
sudo dpkg -i -E /tmp/amazon-cloudwatch-agent.deb

sudo cp amazon-cloudwatch-agent.<env>.json /opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json
sudo /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl \
  -a fetch-config -m ec2 -s -c file:/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json
```

Recolecta métricas (`disk_used_percent` en `/` y `/data/postgres`, `mem_used_percent`, namespace
custom `AtlanticERP`) y tails de `storage/logs/laravel.log` + `/var/log/nginx-crowdsec/access.log`
hacia `/atlanticerp/<env>` en CloudWatch Logs.

## Metric Filters de 5xx (no forman parte del JSON del agente — viven como recursos AWS aparte)

Cada log group `/atlanticerp/<env>` tiene un Metric Filter propio (`atlanticerp-<env>-nginx-5xx`) que cuenta
líneas con `status_code=5*` del access log y alimenta la alarma `atlanticerp-<env>-nginx-5xx-high`
(`>=5 en 5 min`, SNS `atlanticerp-alerts`). **Corregido 2026-09-28**: los 3 filtros publicaban al mismo
`AtlanticERP/Nginx5xxCount` sin ninguna dimensión — CloudWatch los trataba como una sola métrica
compartida, así que un 5xx en cualquier entorno disparaba las 3 alarmas a la vez, sin que el
nombre de la alarma dijera nada real sobre en qué entorno ocurrió. Ahora cada filtro publica a un
nombre de métrica propio: `Nginx5xxCount-dev` / `Nginx5xxCount-test` / `Nginx5xxCount-prod`.

**Regla para cualquier Metric Filter nuevo compartido entre los 3 entornos**: el nombre de métrica
(o una dimensión estática verificada) siempre debe distinguir el entorno de origen — nunca un
`metricName` desnudo igual en los 3 `put-metric-filter`. Ver
`docs/specs/cloudwatch-nginx-5xx-metric-per-environment.md` para el detalle del incidente y el
diseño.
