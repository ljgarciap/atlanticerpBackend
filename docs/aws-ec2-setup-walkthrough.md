# AWS EC2 Setup Walkthrough — CRM Illumination
**Autor:** AWS Architect
**Fecha:** 2026-06-15
**Aplica a:** Entorno de producción (`crm-illumination-prod`) y QA (`crm-illumination-qa`)
**Región:** us-east-1

> Para QA repetir desde el Paso 3 usando el nombre `crm-illumination-qa`
> y tipo de instancia `t3.small` con volumen de datos de 30GB.

---

## Prerequisitos

Antes de empezar, DevOps necesita en su máquina local:
- Acceso a la consola AWS (usuario IAM con permisos EC2 + S3 + CloudWatch + Backup)
- AWS CLI instalado: `aws --version`
- Cliente SSH disponible

---

## Paso 1 — Configurar AWS CLI local

```bash
aws configure
# AWS Access Key ID:     [la del usuario IAM]
# AWS Secret Access Key: [la del usuario IAM]
# Default region:        us-east-1
# Default output format: json

# Verificar
aws sts get-caller-identity
```

---

## Paso 2 — Crear Key Pair (una sola vez para el proyecto)

Consola AWS → EC2 → Key Pairs → **Create key pair**

```
Nombre:  crm-illumination-key
Tipo:    RSA
Formato: .pem
```

Descargar el archivo `.pem` y protegerlo:

```bash
chmod 400 ~/crm-illumination-key.pem
# Guardar en lugar seguro — si se pierde, no hay acceso SSH
```

---

## Paso 3 — Crear Security Group

EC2 → Security Groups → **Create security group**

```
Nombre:      crm-illumination-prod-sg
Descripción: CRM Illumination Production
VPC:         default
```

Reglas de entrada:

| Tipo  | Puerto | Origen         | Propósito                     |
|-------|--------|----------------|-------------------------------|
| SSH   | 22     | Tu IP /32      | Acceso administrativo (solo tu IP, nunca 0.0.0.0/0) |
| HTTP  | 80     | 0.0.0.0/0      | Tráfico web + redirect HTTPS  |
| HTTPS | 443    | 0.0.0.0/0      | Tráfico web seguro            |

Reglas de salida: dejar por defecto (todo el tráfico permitido).

> **Importante:** Si cambia tu IP, actualizar la regla de SSH antes de intentar conectar.

---

## Paso 4 — Lanzar la instancia EC2

EC2 → **Launch instance**

```
Nombre:         crm-illumination-prod
AMI:            Ubuntu Server 24.04 LTS (64-bit x86)
Tipo:           t3.small
Key pair:       crm-illumination-key
Security group: crm-illumination-prod-sg

Almacenamiento:
  Volumen raíz: 30 GB — tipo gp3 — cifrado: sí
```

Expandir **Advanced details**:
- Auto-recovery: **Enable**

Lanzar instancia y anotar el **Instance ID**.

---

## Paso 5 — Crear y adjuntar volumen EBS de datos

EC2 → Volumes → **Create volume**

```
Tipo:                     gp3
Tamaño:                   50 GB  (QA: 30 GB)
Zona de disponibilidad:   MISMA que la instancia (ej: us-east-1a)
Cifrado:                  sí
Nombre (tag):             crm-illumination-prod-data
```

Una vez creado:
- Actions → **Attach volume**
- Seleccionar instancia `crm-illumination-prod`
- Device name: `/dev/xvdf`
- Attach

---

## Paso 6 — Asignar Elastic IP

EC2 → Elastic IPs → **Allocate Elastic IP address**

```
Network border group: us-east-1
```

Una vez asignada:
- Actions → **Associate Elastic IP address**
- Seleccionar instancia `crm-illumination-prod`
- Associate

**Anotar la IP asignada → entregar al contacto DNS.**

---

## Paso 7 — Conectarse a la instancia

```bash
ssh -i ~/crm-illumination-key.pem ubuntu@<elastic-ip>
```

---

## Paso 8 — Configurar el volumen de datos

```bash
# Verificar que el volumen está adjunto
lsblk
# Debe aparecer xvdf de 50G

# Formatear — SOLO la primera vez, nunca repetir si ya tiene datos
sudo mkfs.ext4 /dev/xvdf

# Crear directorio de montaje
sudo mkdir -p /data/postgres

# Montar el volumen
sudo mount /dev/xvdf /data/postgres

# Configurar montaje automático al reiniciar
echo '/dev/xvdf /data/postgres ext4 defaults,nofail 0 2' | sudo tee -a /etc/fstab

# Dar permisos
sudo chmod 755 /data/postgres

# Verificar
df -h | grep data
```

---

## Paso 9 — Instalar Docker

```bash
# Actualizar paquetes
sudo apt update && sudo apt upgrade -y

# Instalar Docker (script oficial)
curl -fsSL https://get.docker.com | sudo sh

# Agregar usuario ubuntu al grupo docker
sudo usermod -aG docker ubuntu

# Instalar plugin Docker Compose
sudo apt install -y docker-compose-plugin

# Habilitar Docker al inicio
sudo systemctl enable docker
sudo systemctl start docker

# Cerrar sesión y volver a entrar para que el grupo tome efecto
exit
ssh -i ~/crm-illumination-key.pem ubuntu@<elastic-ip>

# Verificar
docker --version
docker compose version
docker run hello-world
```

---

## Paso 10 — Instalar herramientas adicionales

```bash
sudo apt install -y git awscli curl unzip certbot
```

---

## Paso 11 — Configurar acceso GitHub desde el servidor

```bash
# Generar SSH key para GitHub
ssh-keygen -t ed25519 -C "desarrollo@example.test" -f ~/.ssh/github_key -N ""

# Ver la clave pública
cat ~/.ssh/github_key.pub
# Copiar esta clave y agregarla en GitHub:
# github.com → Settings → SSH and GPG keys → New SSH key

# Configurar SSH para usar esta clave con GitHub
cat >> ~/.ssh/config << 'EOF'
Host github.com
  HostName github.com
  User git
  IdentityFile ~/.ssh/github_key
EOF

# Probar conexión
ssh -T git@github.com
# Respuesta esperada: Hi desarrollo@example.test! You've successfully authenticated...
```

---

## Paso 12 — Clonar repositorios

```bash
sudo mkdir -p /var/www
sudo chown ubuntu:ubuntu /var/www
cd /var/www

git clone git@github.com:Grupo-Lafayette/atlanticerp-backend.git backend
git clone git@github.com:Grupo-Lafayette/atlanticerp-frontend.git frontend

# Directorio para archivos estáticos del frontend (Nginx los sirve desde aquí)
mkdir -p /var/www/static
```

---

## Paso 13 — Configurar variables de entorno

```bash
cd /var/www/backend
cp .env.example .env
nano .env
# Completar: APP_KEY, DB_PASSWORD, JWT_SECRET, STORAGE_*, etc.

# Generar APP_KEY
docker compose -f infra/docker-compose.prod.yml run --rm laravel php artisan key:generate --show
# Copiar el resultado en APP_KEY del .env
```

---

## Paso 14 — Primer arranque

```bash
cd /var/www/backend

# Construir y levantar todos los contenedores
docker compose -f infra/docker-compose.prod.yml up -d --build

# Verificar que todos están healthy
docker compose -f infra/docker-compose.prod.yml ps

# Correr migraciones
docker compose -f infra/docker-compose.prod.yml exec laravel php artisan migrate --force

# Crear primer tenant
docker compose -f infra/docker-compose.prod.yml exec laravel php artisan tenant:create atlantic

# Verificar logs si algo falla
docker compose -f infra/docker-compose.prod.yml logs laravel
```

---

## Paso 15 — SSL con Let's Encrypt

> Ejecutar DESPUÉS de que el DNS apunte a la Elastic IP y haya propagado.

```bash
# Detener nginx para liberar el puerto 80
docker compose -f infra/docker-compose.prod.yml stop nginx
```

**Prod** (`atlanticerp.ai`):
```bash
sudo certbot certonly --standalone \
  -d atlanticerp.ai \
  -d www.atlanticerp.ai \
  --email desarrollo@example.test \
  --agree-tos \
  --non-interactive
# Certificados en: /etc/letsencrypt/live/atlanticerp.ai/
```

**QA** (`test.atlanticerp.ai`):
```bash
sudo certbot certonly --standalone \
  -d test.atlanticerp.ai \
  --email desarrollo@example.test \
  --agree-tos \
  --non-interactive
# Certificados en: /etc/letsencrypt/live/test.atlanticerp.ai/
```

**Dev** (`dev.atlanticerp.ai`):
```bash
sudo certbot certonly --standalone \
  -d dev.atlanticerp.ai \
  --email desarrollo@example.test \
  --agree-tos \
  --non-interactive
# Certificados en: /etc/letsencrypt/live/dev.atlanticerp.ai/
```

```bash
# DevOps monta los certificados en docker-compose.*.yml (volumen en nginx)
# Reiniciar nginx
docker compose -f infra/docker-compose.prod.yml start nginx

# Configurar renovación automática
echo "0 3 * * * root certbot renew --quiet --deploy-hook 'docker compose -f /var/www/backend/infra/docker-compose.prod.yml restart nginx'" | sudo tee /etc/cron.d/certbot-renew
```

---

## Paso 16 — Activar backup automático

```bash
# El script viene en el repo (T-07)
# Solo registrar el cron
(crontab -l 2>/dev/null; echo "0 2 * * * /var/www/backend/infra/scripts/backup-postgres.sh >> /var/log/backup.log 2>&1") | crontab -

# Probar manualmente antes de confiar en el cron
/var/www/backend/infra/scripts/backup-postgres.sh
```

---

## Paso 17 — Configurar CloudWatch Alarms

Consola AWS → CloudWatch → Alarms → **Create alarm**

### Alarma de CPU alta

```
Métrica:    EC2 → Per-Instance Metrics → CPUUtilization
Instancia:  crm-illumination-prod
Condición:  > 75% durante 2 períodos de 5 minutos
Acción:     SNS → crear topic "crm-alerts" → suscribir email de Luis
Nombre:     crm-prod-cpu-high
```

### Alarma de Auto Recovery

```
Métrica:    EC2 → Per-Instance Metrics → StatusCheckFailed_System
Instancia:  crm-illumination-prod
Condición:  >= 1 durante 2 períodos de 1 minuto
Acción:     EC2 Action → Recover this instance
Nombre:     crm-prod-auto-recovery
```

---

## Paso 18 — Configurar AWS Backup para EBS

Consola AWS → AWS Backup → **Create backup plan**

```
Nombre del plan:  crm-illumination-backup

Regla:
  Nombre:       daily-backup
  Frecuencia:   Daily
  Hora:         03:00 UTC
  Retención:    28 días

Recursos asignados:
  Resource type: EBS
  Volume ID:     [ID del volumen crm-illumination-prod-data]
```

---

## Paso 19 — EventBridge para apagado automático de QA

> Solo aplicar al entorno QA, nunca a producción.

Consola AWS → EventBridge → Rules → **Create rule**

**Regla 1 — Apagar QA a las 7pm (L–V)**
```
Nombre:   crm-qa-stop
Schedule: cron(0 23 ? * MON-FRI *)   # 7pm EST = 23:00 UTC
Target:   EC2 StopInstances → instancia crm-illumination-qa
```

**Regla 2 — Encender QA a las 8am (L–V)**
```
Nombre:   crm-qa-start
Schedule: cron(0 12 ? * MON-FRI *)   # 8am EST = 12:00 UTC
Target:   EC2 StartInstances → instancia crm-illumination-qa
```

> IAM: EventBridge necesita un rol con permiso `ec2:StopInstances` y `ec2:StartInstances`.
> Crear rol `eventbridge-ec2-scheduler` con esa policy antes de crear las reglas.

---

## Checklist final antes de entregar la IP al DNS

```
□ Instancia corriendo — estado "running" en consola
□ Elastic IP asignada y anotada
□ docker compose ps → todos los contenedores en estado "healthy"
□ curl http://<elastic-ip>/health → 200 OK
□ php artisan tenant:create atlantic → sin errores
□ Backup script probado manualmente → archivo en S3
□ CloudWatch alarms activas y visibles en consola
□ AWS Backup plan configurado con el volumen correcto
□ SSH funcionando desde la máquina de cada miembro del equipo
□ IP entregada al contacto DNS
```

**Una vez el DNS propague → ejecutar Paso 15 (SSL).**

---

## Referencia rápida — comandos frecuentes en el servidor

```bash
# Ver estado de contenedores
cd /var/www/backend && docker compose -f infra/docker-compose.prod.yml ps

# Ver logs en tiempo real
docker compose -f infra/docker-compose.prod.yml logs -f laravel

# Reiniciar un contenedor específico
docker compose -f infra/docker-compose.prod.yml restart nginx

# Correr migraciones manualmente
docker compose -f infra/docker-compose.prod.yml exec laravel php artisan migrate --force

# Abrir shell en el contenedor de Laravel
docker compose -f infra/docker-compose.prod.yml exec laravel bash

# Ver jobs en cola
docker compose -f infra/docker-compose.prod.yml exec laravel php artisan horizon:status
```
