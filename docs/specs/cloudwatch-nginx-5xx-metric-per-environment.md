# Spec: Métrica `Nginx5xxCount` compartida entre dev/test/prod — separar por entorno

**Date**: 2026-09-28
**Requested by**: Luis
**Status**: Done — aplicado y verificado en vivo 2026-09-28 (los 3 metric filters y las 3 alarmas
ya apuntan a su métrica propia). Pendiente solo el criterio de aceptación #3 (provocar un 5xx real
en un solo entorno y confirmar aislamiento) — requiere generar tráfico de prueba, diferido.
**Project**: AtlanticERP — infraestructura AWS (CloudWatch), no toca `atlanticerp-backend`/`atlanticerp-frontend`

## Problem
Las 3 alarmas `atlanticerp-{dev,test,prod}-nginx-5xx-high` envían notificaciones casi
idénticas y simultáneas ante cualquier pico de errores 5xx, sin importar en
qué entorno ocurrió realmente. Ejemplo real (2026-09-28): las 3 alarmas
mostraron el mismo datapoint (`3.0` a las `2026-09-28T02:04:00Z`) al mismo
segundo, para un correo cuyo asunto decía específicamente "prod".

## Root cause (confirmado con `aws logs describe-metric-filters` y
`aws cloudwatch list-metrics`, no es hipótesis)
Existen 3 Metric Filters de CloudWatch Logs, uno por log group de entorno,
correctos en su origen:

| Metric Filter | Log group origen |
|---|---|
| `atlanticerp-prod-nginx-5xx` | `/atlanticerp/prod` |
| `atlanticerp-test-nginx-5xx` | `/atlanticerp/test` |
| `atlanticerp-dev-nginx-5xx` | `/atlanticerp/dev` |

Los 3 tienen el mismo `filterPattern` (matchea `status_code=5*`) — correcto,
cada uno lee el log de su propio entorno. El problema es que los 3 publican
su `metricTransformations` al **mismo** namespace+nombre de métrica, **sin
ninguna dimensión**: `{"metricName": "Nginx5xxCount", "metricNamespace":
"AtlanticERP"}`, sin `dimensions`.

CloudWatch identifica una métrica únicamente por `namespace + nombre +
dimensiones`. Sin dimensión, los 3 filtros escriben al mismo stream — un 5xx
en dev suma al mismo contador que uno en prod. Las 3 alarmas (`atlanticerp-dev-...`,
`atlanticerp-test-...`, `atlanticerp-prod-...`) leen ese único stream compartido, así que
las 3 se disparan y resuelven en lockstep, y ninguna distingue de verdad el
entorno de origen pese a que el nombre de la alarma lo sugiere.

Contraejemplo dentro del mismo proyecto que confirma que el patrón correcto
ya existe en otro lado: `disk_used_percent`/`mem_used_percent` (publicadas
por el agente CloudWatch estándar, no por Metric Filters) sí llevan
`InstanceId` como dimensión y por lo tanto sí son 3 series independientes,
una por EC2 (`i-0cb9e416fd772d11f`=prod, `i-0fa441803052781d4`=test,
`i-0b4b35205d325fe81`=dev).

## Impacto / alcance
- **Afecta:** únicamente la alarma de errores 5xx (`Nginx5xxCount`). Las
  alarmas de CPU/disco/memoria usan métricas nativas del agente, ya
  correctamente separadas por instancia — fuera de alcance, no tocar.
- **Severidad del bug de instrumentación:** no bloquea nada operativo (las
  alarmas siguen avisando), pero degrada la confiabilidad de la señal —
  hoy es imposible saber, solo con el correo, si un 5xx fue en prod o en un
  entorno de menor criticidad, lo que puede llevar a sobre-reaccionar a un
  problema de `dev`/`test` como si fuera de `prod`, o mezclar la lectura de
  un incidente real de prod con ruido de otro entorno.
- **Fuera de alcance de este ticket:** investigar la causa puntual de los 5
  errores 5xx del 2026-09-28 01:57 UTC (requiere leer `access.log`/
  `laravel.log` de prod directamente — bloqueado para el agente en esta
  sesión, lo hace Luis aparte si decide seguirlo).

## Diseño propuesto (Arquitecto)
Reemplazar el nombre de métrica único por uno propio por entorno, en vez de
depender de una dimensión (evita cualquier duda sobre si el campo
`dimensions` de un metric filter acepta un valor literal fijo o solo un
campo capturado del patrón — con nombre de métrica distinto no hay
ambigüedad, y es el mismo mecanismo ya usado por los propios metric filters,
solo con un nombre por entorno):

- `atlanticerp-dev-nginx-5xx` → metricName `Nginx5xxCount-dev`
- `atlanticerp-test-nginx-5xx` → metricName `Nginx5xxCount-test`
- `atlanticerp-prod-nginx-5xx` → metricName `Nginx5xxCount-prod`

Y actualizar cada alarma para apuntar a la métrica de su propio entorno
(`atlanticerp-dev-nginx-5xx-high` → `Nginx5xxCount-dev`, etc.), mismo threshold
(`>=5` en 5 min), mismo SNS `atlanticerp-alerts`.

**Alternativa descartada:** agregar una dimensión (`Environment=prod`) en
vez de renombrar la métrica — técnicamente más "limpio" pero depende de que
el campo `dimensions` de un metric filter acepte un valor estático (no
verificado en este stack); el cambio de nombre logra el mismo resultado sin
esa duda, al costo de que la métrica histórica (`Nginx5xxCount` sin separar)
queda huérfana — aceptable, no hay dashboards que dependan de esos datos
crudos hoy.

## Acceptance criteria
- [ ] Existen 3 metric filters, uno por log group (`/atlanticerp/dev`,
      `/atlanticerp/test`, `/atlanticerp/prod`), cada uno publicando a un nombre de
      métrica **distinto** (`Nginx5xxCount-{env}`), mismo `filterPattern`
      que hoy.
- [ ] Cada una de las 3 alarmas (`atlanticerp-{env}-nginx-5xx-high`) apunta a la
      métrica de su propio entorno — verificado con
      `aws cloudwatch describe-alarms` mostrando `MetricName` distinto por
      alarma.
- [ ] Provocar (o esperar) un 5xx real en **un solo** entorno (ej. dev, vía
      una request que fuerce un 500) y confirmar que **solo** la alarma de
      ese entorno pasa a `ALARM` — las otras 2 permanecen en `OK`. Este es
      el criterio que prueba que el bug quedó resuelto, no solo que la
      config cambió.
- [ ] El correo de alarma sigue llegando por el mismo SNS `atlanticerp-alerts`,
      sin cambios en destinatarios ni formato.
- [ ] La métrica vieja sin dimensionar (`Nginx5xxCount`) puede quedar de
      baja sin borrarla activamente (las métricas de CloudWatch expiran
      solas sin datos nuevos, no se cobra por una métrica inactiva) — no
      hace falta un paso de limpieza explícito.
- [ ] `infra/cloudwatch-agent/README.md` (o un README nuevo específico de
      metric filters/alarmas si no existe hoy — no están documentados ahí
      actualmente) se actualiza para dejar constancia del nombre de métrica
      por entorno, evitando que alguien vuelva a crear un filtro nuevo sin
      dimensión/nombre propio en el futuro.

## Riesgos / consideraciones
- Cambiar el nombre de la métrica corta la continuidad histórica de
  `Nginx5xxCount` — no hay dashboards ni reportes que dependan de esa serie
  hoy (confirmado: no aparece referenciada en `docs/` ni en el frontend de
  Gerencia), así que es un costo aceptado, no un riesgo real.
- Mientras se aplica el cambio (breve ventana), es posible que la alarma
  vieja y la nueva coexistan — no rompe nada, en el peor caso hay una
  alarma huérfana sin datos que eventualmente queda en `INSUFFICIENT_DATA`
  y se puede borrar después de confirmar que la nueva funciona.
- No requiere tocar `atlanticerp-backend`/`atlanticerp-frontend` ni desplegar código —
  es 100% configuración AWS (metric filters + alarmas), vía consola o CLI.

## Decisiones pendientes de Luis
- Aprobar el diseño propuesto (rename de métrica) sobre la alternativa de
  dimensión — ya resuelta por el Arquitecto salvo objeción explícita.
- Ninguna decisión de negocio involucrada — es un fix de instrumentación
  puro.

## Ejecución (2026-09-28)
Aplicado vía AWS CLI, `put-metric-filter`/`put-metric-alarm` in-place sobre los mismos 3
`filterName`/`AlarmName` (sin crear recursos huérfanos):
- `atlanticerp-{dev,test,prod}-nginx-5xx` → `metricName: Nginx5xxCount-{env}` (mismo `filterPattern`)
- `atlanticerp-{dev,test,prod}-nginx-5xx-high` → `MetricName: Nginx5xxCount-{env}` (mismo
  threshold/period/statistic/SNS `atlanticerp-alerts`)

Verificado con `describe-alarms`: las 3 alarmas muestran `MetricName` distinto, las 3 en `OK`
(esperado — las métricas nuevas todavía no tienen datapoints, `TreatMissingData: notBreaching`).
La métrica vieja compartida `AtlanticERP/Nginx5xxCount` queda huérfana sin limpieza activa (por diseño,
ver "Riesgos" arriba). Documentado en `infra/cloudwatch-agent/README.md`.

**Pendiente real:** el criterio de aceptación #3 (provocar un 5xx en un solo entorno y confirmar
que solo esa alarma dispara) no se ejecutó — requiere generar tráfico de prueba real contra un
entorno, diferido para cuando ocurra el próximo 5xx orgánico o Luis decida forzar uno.
