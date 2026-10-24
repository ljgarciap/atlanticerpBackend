# Runbook — Copia segura del `.env` de cada entorno (RC-04)

**Por qué:** el `.env` del servidor (APP_KEY, JWT_SECRET, contraseñas de la base y de Redis, claves de AWS y de Anthropic) **no está en ningún respaldo ni en la AMI**. Confirmado el 2026-10-05: **no existía ninguna copia aparte**. Sin él no se reconstruye el servidor, y sin `APP_KEY` los datos cifrados de la base (por ejemplo la clave de Anthropic guardada en la configuración) quedan ilegibles.

**Regla:** solo una persona con credenciales de administrador de AWS (Luis) crea y lee estas copias. **El rol de las instancias no tiene permiso** sobre ellas, a propósito. El contenido nunca se pega en un chat, en Jira ni en un documento.

**Estado (2026-10-05):** **los 3 entornos hechos** (`atlanticerp/{prod,test,dev}/env` de 3333/3395/3445 bytes y `atlanticerp/{prod,test,dev}/infra-env` de 103/150/150 bytes; tamaños verificados). La copia en el gestor de contraseñas de Luis está hecha (confirmado 2026-10-05; SCRUM-1078 cerrado). En los servidores el proyecto vive en `/var/www/backend` (no en el home) y hay **dos** archivos que respaldar: `/var/www/backend/.env` (aplicación) y `/var/www/backend/infra/.env` (variables de Compose). Ambos tienen permisos 664; deberían ser 600.

## 1. Dónde se guarda (dos lugares, a propósito)
1. **AWS Secrets Manager**, un secreto por entorno: `atlanticerp/dev/env`, `atlanticerp/test/env`, `atlanticerp/prod/env` (región us-east-1, cifrado con la clave administrada por AWS). Costo ~0,40 USD por secreto al mes. Admite hasta 64 KB.
2. **Copia fuera de AWS**, en el gestor de contraseñas de Luis (nota segura con el archivo adjunto cifrado). Protege contra la pérdida o el compromiso de la cuenta de AWS.

## 2. Crear la copia (la ejecuta Luis; escribir cada comando con `!` en la sesión, sin mostrar el contenido)
Primero localiza el archivo en el servidor (una sola vez) y comprueba su tamaño sin leerlo:
```bash
ssh -i ~/.ssh/atlanticerp/atlanticerp-key.pem ubuntu@<host-del-entorno> 'ls -l /var/www/backend/.env /var/www/backend/infra/.env 2>/dev/null; wc -c /var/www/backend/.env 2>/dev/null'
```
Crear el secreto directamente desde el servidor, sin pasar por pantalla ni disco local (ajusta la ruta del `.env` a la que hayas confirmado):
```bash
ssh -i ~/.ssh/atlanticerp/atlanticerp-key.pem ubuntu@<host-del-entorno> 'cat /var/www/backend/.env' \
 | aws secretsmanager create-secret --name atlanticerp/<entorno>/env --description "Copia del .env de <entorno> (RC-04)" --secret-string file:///dev/stdin \
   --tags Key=Project,Value=atlanticerp Key=Entorno,Value=<entorno> --query '[Name,VersionId]' --output text
```
Cuando el `.env` cambie, **actualizar el secreto el mismo día** (`aws secretsmanager put-secret-value --secret-id atlanticerp/<entorno>/env --secret-string file:///dev/stdin`) y dejar anotado en el cambio de configuración que se hizo.
Después sube la misma copia al gestor de contraseñas (descargándola solo a un equipo de confianza, sin dejar el archivo en el disco al terminar).

## 3. Comprobar que la copia sirve (sin leerla)
```bash
aws secretsmanager get-secret-value --secret-id atlanticerp/<entorno>/env --query 'SecretString' --output text | wc -c   # debe coincidir con el tamaño del .env real
aws secretsmanager describe-secret --secret-id atlanticerp/<entorno>/env --query '[Name,LastChangedDate]' --output text
```
Un simulacro de recuperación debe incluir el paso de **reconstruir el `.env` desde el secreto** (sección 4); es la única forma de saber que la copia no está vacía ni truncada.

## 4. Restaurar (parte del runbook de recuperación)
```bash
aws secretsmanager get-secret-value --secret-id atlanticerp/<entorno>/env --query SecretString --output text > .env && chmod 600 .env
```
Se ejecuta con credenciales de administrador, en la máquina de destino por una sesión autorizada, nunca en un equipo personal.

## 5. Mantenimiento
- Al rotar cualquier clave, actualizar el secreto y la copia del gestor.
- Revisar en cada simulacro trimestral (próximo: 2026-11-21) que `LastChangedDate` no sea anterior al último cambio de configuración.
- Este documento no contiene secretos; el agente `disaster-recovery` lo cita y se detiene si el secreto no existe.
