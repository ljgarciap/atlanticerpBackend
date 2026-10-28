# Spec — Fase 10: infraestructura de producción en AWS

**Estado**: BORRADOR (rol AWS Architect + Cybersecurity) — decisiones de Luis en §6 (AWS, `us-east-1`, piloto sin Multi-AZ); dominio, presupuesto y cuenta de AWS siguen abiertos (§7). **No se crea ningún recurso** sin su aprobación explícita (todo comando `aws`/`terraform apply` pide aprobación individual).

## 1. Punto de partida (medido en el repo)
- `infra/docker-compose.prod.yml` ya describe un **EC2 dedicado con Docker Compose**: `postgres:16-alpine` y `redis:7-alpine` como contenedores del mismo host, `laravel` + `horizon` + `nginx`, frontend servido desde `/var/www/static`, certificados en `/etc/letsencrypt`, agente de CloudWatch y logrotate (carpetas `infra/cloudwatch-agent`, `infra/logrotate`).
- Rutas absolutas del host (`/data/postgres/pgdata`, etc.): el EC2 destino **debe ser exclusivo** de AtlanticERP (incidente de aislamiento de 2026-08-30, ver `Atlantic/CLAUDE.md`).
- Dependencias de la aplicación: PostgreSQL 16 con las extensiones `pg_trgm` y `unaccent` (rol de migración con `CREATE EXTENSION`), Redis (colas de Horizon, caché), almacenamiento S3 para archivos (`FILESYSTEM_DISK=s3`, `AWS_BUCKET`) y un bucket aparte para auditoría inmutable (`AWS_AUDIT_IMMUTABLE_BUCKET`), correo saliente (hoy `MAIL_MAILER=log`).
- Los jobs `deploy-*` de GitHub Actions siguen con `if: false &&`; el Environment `prod` ya exige revisor (`ljgarciap`). Los scripts `infra/deploy-manual.sh` apuntan a hosts marcador a propósito.

## 2. Opciones evaluadas
| | A. EC2 + RDS (recomendada para el primer lanzamiento) | B. ECS Fargate + RDS + ElastiCache | C. EC2 «todo en uno» (lo que describe el compose hoy) |
|---|---|---|---|
| Cambio sobre lo existente | Mínimo: el compose conserva `laravel`/`horizon`/`nginx`; solo la BD pasa a RDS | Alto: imágenes en ECR, definiciones de tareas, balanceador, pipelines nuevos | Ninguno |
| Backups y parches de BD | Gestionados (RDS: copias automáticas, PITR, parches) | Gestionados | A cargo nuestro (disco del EC2) |
| Operación | Un host que parchear, con la BD fuera | Sin hosts; más piezas que aprender | El riesgo operativo mayor: BD y app en el mismo disco |
| Costo (orden de magnitud, **a validar con la calculadora de AWS**) | Medio (1 EC2 + 1 RDS pequeño) | Más alto (Fargate + ALB + RDS + ElastiCache) | Bajo, pero con riesgo |
| Cuándo | Piloto / pocos clientes | Crecimiento, varios entornos, despliegues sin tiempo de inactividad | No recomendada para producción con datos reales |

**Recomendación**: **A** para salir a producción (menor cambio, BD gestionada y con copias), con **B** como evolución cuando haga falta escalar o desplegar sin interrupción. Redis puede seguir como contenedor en el primer lanzamiento (las colas son reintentables); pasar a ElastiCache cuando la disponibilidad lo exija.

## 3. Diseño propuesto (opción A)
1. **Red**: VPC con 2 zonas de disponibilidad; subredes públicas (balanceador) y privadas (EC2 y RDS). **Sin acceso SSH público**: administración por SSM Session Manager.
2. **Cómputo**: 1 EC2 dedicado (tamaño a confirmar con una prueba de carga: `infra/loadtest`), en subred privada, tras un **Application Load Balancer** con certificado de **ACM** (requiere dominio). Docker Compose para `laravel`, `horizon`, `nginx`, `redis`.
3. **Base de datos**: **RDS PostgreSQL 16** (almacenamiento cifrado, copias automáticas ≥ 14 días, snapshot manual antes de cada migración mayor, Multi-AZ opcional según objetivo de disponibilidad). Grupo de parámetros con `pg_trgm` y `unaccent` permitidos; rol de migración separado (`DB_MIGRATE_USERNAME`) y rol de aplicación sin `CREATE EXTENSION`.
4. **Archivos**: bucket S3 para documentos/fotos con *Block Public Access*, cifrado y versionado; bucket de auditoría con **Object Lock** (inmutable). Acceso solo por el rol de instancia (IAM de mínimo privilegio, nada de llaves de acceso en `.env`).
5. **Secretos y configuración**: SSM Parameter Store o Secrets Manager (cadena de conexión, `APP_KEY`, claves de JWT, SMTP); nada de secretos en el repo ni en el host en claro.
6. **Observabilidad y alarmas**: logs a CloudWatch (agente ya previsto), alarmas de CPU/disco/memoria, espacio libre de RDS, errores 5xx del balanceador y fallos de Horizon; aviso por SNS.
7. **Seguridad (Cybersecurity)**: grupos de seguridad cerrados (RDS solo desde el EC2, jamás `0.0.0.0/0`), WAF opcional delante del balanceador, TLS 1.2+, cabeceras ya cubiertas por nginx, revisión de la política IAM antes de aplicar.
8. **Despliegue**: reactivar los jobs `deploy-*` (hoy `if: false &&`) contra el host real, con el Environment `prod` ya protegido por revisor; migraciones con respaldo previo obligatorio (la migración `2026_10_26_200000` es irreversible).
9. **Infraestructura como código**: Terraform (o CloudFormation) en un repositorio/carpeta aparte, con `plan` revisado por Luis antes de cualquier `apply`.

## 4. Fuera de alcance
Multi-región, CDN para el frontend (CloudFront; se evalúa después), adaptador real de la DIAN y validación de `digifact` con credenciales reales, correo transaccional (SES) — se tratan en sus propias fases.

## 5. Criterios de aceptación
1. Un entorno de **pruebas** (no producción) levantado con IaC y destruible, con la aplicación sirviendo por HTTPS, migraciones y `tenant:create` ejecutados, y una restauración de RDS comprobada desde un snapshot.
2. Ningún recurso con acceso público salvo el balanceador; revisión de IAM y grupos de seguridad aprobada por Cybersecurity.
3. Costos mensuales estimados con la calculadora y aprobados por Luis antes del primer `apply`.
4. Los jobs de deploy reactivados solo tras el punto 1, con aprobación del revisor de `prod`.

## 6. Decisiones de Luis (2026-10-29)
1. **Hosting**: AWS (EC2 + RDS). ✅
2. **Región**: `us-east-1` (N. Virginia). ✅
3. **Disponibilidad**: **piloto, tolera mantenimiento** → 1 EC2 y 1 RDS **sin Multi-AZ**; la recuperación es por copia/snapshot (RPO/RTO a medir en la restauración de prueba del criterio 1). Multi-AZ y ECS Fargate quedan como evolución. ✅
4. **Dominio**: **todavía no** → bloquea el certificado ACM y el balanceador (HTTPS). Mientras no exista, solo se puede preparar la red, la base y el almacenamiento; no se publica nada.

## 7. Decisiones que siguen abiertas (Luis)
1. **Dominio** y quién administra el DNS (desbloquea ACM + ALB).
2. **Presupuesto mensual** objetivo, para dimensionar EC2/RDS.
3. **Cuenta de AWS**: quién la crea y qué usuarios/roles de mínimo privilegio se entregan (nada se aplica desde esta sesión sin credenciales que Luis entregue y apruebe).
