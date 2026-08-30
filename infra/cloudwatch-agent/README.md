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
