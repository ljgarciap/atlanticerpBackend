# Runbook — Recuperación de AtlanticERP (base de datos y servidor)

**Estado: PROBADO el 2026-10-05 en un simulacro real (SCRUM-1029, informe 2)**, en una instancia desechable creada desde la AMI. Los tiempos de la sección 6 son medidos. Reemplaza al runbook previo (`infra/scripts/restore-test-runbook.md`), que nunca se había probado y tenía datos desactualizados.

## 0. Antes de tocar nada
1. **Clasificar:** ¿datos mal con el servidor sano (borrado, migración defectuosa) → **Camino A**; ¿servidor o disco perdido → **Camino B** (y después A si hace falta un punto más fino)?
2. **Decide Luis** (o quien tenga el rol de Arquitecto en ese momento). Se declara antes de ejecutar; no se asume.
3. **Si el entorno responde aunque sea parcialmente, sacar un respaldo adicional del estado actual** antes de restaurar nada: es la red de seguridad si la restauración sale mal.
4. **Quién puede restaurar:** el rol de las instancias solo puede *escribir* respaldos. Descargar uno exige **credenciales de administrador de AWS** (Luis): generan un enlace temporal y la instancia lo descarga. Tener estas credenciales localizadas es parte de estar preparado.

## 1. Qué respaldos existen (verificado el 2026-10-05)
| Respaldo | Frecuencia | Retención | Dónde |
|---|---|---|---|
| `pg_dump` comprimido (`atlanticerp_atlanticerp_AAAAMMDD_030001.sql.gz`) | prod diario a las 03:00 UTC; test semanal | prod 90 días, dev/test 30 | `s3://atlanticerp-<env>/postgres-backups/` |
| Instantánea de disco (AWS Backup, bóveda `atlanticerp-backups`) | diaria, **solo prod** | 90 días | AWS Backup |
| AMI `atlanticerp-base` (sistema, Docker, Compose, AWS CLI, certbot, git, llave de GitHub) | única | — | `ami-0a79352db72dd270d` |
| Copia inmutable de `audit_logs` (SCRUM-815) | ver ADR | ver ADR | bucket aparte |

**Pérdida máxima de datos (RPO):** hasta ~24 horas en prod y hasta 7 días en test, porque no hay archivado continuo de la base. Reducirlo es una decisión de Luis (más frecuencia o archivado de WAL).

## 2. Camino A — Restauración lógica (`pg_dump`)
1. **Elegir el respaldo** (el más reciente anterior al daño) y su fecha:
   ```bash
   aws s3 ls s3://atlanticerp-<env>/postgres-backups/ | sort | tail
   ```
2. **Generar un enlace temporal** (administrador; una hora, solo ese objeto) y **descargarlo en la máquina de destino**, nunca en un equipo personal:
   ```bash
   aws s3 presign s3://atlanticerp-<env>/postgres-backups/<archivo>.sql.gz --expires-in 3600
   # en la máquina de destino:
   curl -fL -o respaldo.sql.gz '<enlace>'
   ```
3. **Restaurar SIEMPRE primero contra una base descartable**, nunca directo sobre el entorno real. El respaldo referencia dos roles (`atlanticerp`, dueño de los objetos, y `atlanticerp_app`, que recibe permisos): el usuario del Postgres destino debe ser `atlanticerp` y hay que crear `atlanticerp_app` antes (`CREATE ROLE atlanticerp_app NOLOGIN;`) o la restauración falla en cientos de `GRANT`:
   ```bash
   gunzip -c respaldo.sql.gz | docker exec -i <contenedor-postgres> psql -U <usuario> -d <base_descartable>
   ```
4. **Validar antes de promover** (no basta con "restauró sin error"): existen los esquemas del tenant (`{tenant}_auth`, `_crm`, `_servicios`, `_notifications`, `_ventas_diseno`, `_compras`, `_bodega`, `_admin_contab`, `_gerencia`, `_legacy`); los conteos de tablas clave son razonables (`users`, proyectos, productos de catálogo, facturas migradas); la fecha del respaldo coincide con la ventana de pérdida aceptada.
5. **Aplicar al entorno real** (con el respaldo adicional del paso 0 ya hecho), reiniciar `laravel`, `horizon` y `scheduler`, y comprobar `/up`, `/api/health/deep` y un login.
6. **Reversa:** restaurar el respaldo adicional del paso 0.

## 3. Camino B — Servidor o disco perdido
1. **Instancia de reemplazo desde la AMI** `atlanticerp-base` (t3.small, misma subred y grupo de seguridad que prod, mismo par de llaves), y reasignar la IP elástica.
2. **Datos:** (a) restaurar el volumen desde la instantánea más reciente (AWS Backup crea un volumen **nuevo**, nunca pisa el existente) y adjuntarlo, o (b) si no hay instantánea útil, reconstruir y aplicar el Camino A.
3. **Código y configuración:** clonar los repos (la AMI trae la llave de GitHub), restaurar el `.env` del entorno (**el `.env` no está en ningún respaldo: debe existir una copia segura aparte, en poder de Luis**) y levantar el stack con `docker compose --env-file .env -f infra/docker-compose.prod.yml up -d --build`. Después, como en el deploy: `composer install` en un contenedor aparte (`composer:latest`, no viene en la imagen de PHP), permisos de `storage` y `bootstrap/cache`, `migrate`, `tenant:sync-schemas` y `tenants:artisan "migrate ..."`.
4. **Validar** como en el Camino A, paso 5, y revisar los certificados (`certbot`) y los registros DNS.

## 4. Lo que NO está cubierto por ningún respaldo
- El `.env` del servidor (secretos): copia aparte.
- Los archivos de S3 (documentos y fotos) tienen versionado solo en prod; en dev y test un borrado es definitivo.
- Redis (colas y caché): se reconstruye; los trabajos en cola se pierden.

## 4b. Fricciones encontradas en el simulacro (todas resueltas; evítalas)
1. **Compose busca el `.env` junto al archivo de compose (`infra/`), no en la raíz del repo:** sin `--env-file .env`, `POSTGRES_PASSWORD` queda vacío y Postgres no arranca.
2. **`FRONTEND_URL` y `APP_URL` no pueden apuntar a `localhost`** en un entorno que no sea local: la aplicación lanza una excepción en cada petición (incluido `/up`) para detectar despliegues mal configurados. En una instancia de prueba sin dominio, usar un nombre que no sea `localhost`.
3. **El respaldo es anterior a las migraciones recientes:** hay que correr las migraciones del esquema general y de cada tenant tras restaurar (el simulacro aplicó una en milisegundos).
4. **`composer` no está en la imagen de PHP:** se ejecuta en un contenedor aparte, como hace el deploy.
5. **Cuenta de prueba con cambio obligatorio y contraseña temporal caducada:** el login responde 401 (comportamiento correcto); para verificar el login tras restaurar hay que usar una cuenta sin cambio pendiente.
6. **Direcciones IP:** usar la pública para SSH y la privada para tráfico entre instancias (un error de orden costó 4 minutos al operador).
7. **El rol de las instancias no puede leer los respaldos de S3** (solo escribir): el administrador genera un enlace temporal (`aws s3 presign`, 1 hora) y la instancia lo descarga; así el respaldo nunca pasa por un equipo personal.

## 5. Después de un incidente o simulacro
Anotar qué camino se usó, cuánto tardó de punta a punta, qué fricciones hubo, y corregir este documento **en la misma sesión**. Avisar a Luis de inmediato si fue un incidente real.

## 6. Tiempos medidos (simulacro del 2026-10-05, t3.small, respaldo de test de 63 MB)
**Camino A (base de datos desde `pg_dump`), de una instancia nueva a la aplicación sirviendo datos restaurados:**
| Etapa | Duración medida |
|---|---|
| Lanzar la instancia desde la AMI hasta poder entrar por SSH | ~95 s (arranque completo del sistema 1 min 9 s) |
| Descargar el respaldo (enlace temporal, dentro de AWS) | 5 s |
| Preparar: clonar el repo, generar el `.env` con secretos nuevos | ~20-40 s |
| Postgres y Redis (descargar imágenes y arrancar) | ~22 s |
| **Restaurar la base** (428 MB descomprimidos, 0 errores) | **33 s** |
| Validar esquemas y conteos | 15 s |
| Construir las imágenes y levantar nginx, Laravel y Horizon | ~187-210 s |
| `composer install`, permisos, reinicio, migraciones y seed | ~25 s |
| **Total técnico sin fricciones** | **≈ 6 a 7 minutos** |
| **Total medido de reloj, incluyendo las fricciones de la sección 4b** | **16,6 min (≈ 12,4 min descontando el error de IP del operador)** |

**Camino B (disco de datos de producción desde la instantánea de AWS Backup), con la instancia ya creada:**
| Etapa | Duración medida |
|---|---|
| Restaurar el volumen de 50 GB desde la instantánea (volumen nuevo) | 76 s |
| Adjuntarlo y montarlo | ~35 s |
| Arrancar Postgres sobre ese volumen (recuperación automática del motor) | 4 s |
| Validar conteos contra producción | 10 s |
| **Total** | **143 s ≈ 2,4 min** (más ~95 s si hay que crear la instancia) |

**Pérdida de datos observada (RPO):** el último registro del respaldo restaurado de test (4 de octubre, 03:00 UTC) está ~29 h por detrás de test en vivo; el volumen restaurado de prod (5 de octubre, 04:00 UTC) estaba ~11 h por detrás de prod en vivo. **Peor caso: ~24 h en prod** (un respaldo diario).

## 7. Recuperación automática del servidor (SCRUM-1072)
Desde el 2026-10-05 hay alarmas en dev y prod (`atlanticerp-<entorno>-status-system` y `-status-instance`). Si AWS detecta un fallo de hardware del host, **recupera la instancia sola** (mismos discos e IP elástica) y, si la instancia se cuelga, la **reinicia**; en ambos casos avisa por correo (`atlanticerp-alerts`) al dispararse y al volver a OK. Antes de restaurar nada tras ese aviso: esperar ~5–10 min, comprobar `/up` y `/api/health/deep` y que los contenedores (`restart: unless-stopped`) estén sanos. Probado en dev con el estado de la alarma forzado: reinicio completo en ~40 s; la acción de recuperación se ejecutó pero no migró la instancia sana (un fallo real no se puede simular). Para desactivar: borrar las alarmas (`aws cloudwatch delete-alarms`).

## 8. Recuperación a un instante (PITR) con el archivado de WAL (SCRUM-1073)
**Qué hay en S3** (`s3://<bucket>/postgres-backups/`): `basebackup/base_<fecha>.tar.gz` (copia base semanal, domingo 04:15 UTC) y `wal/<segmento>.gz` (cada segmento de WAL, al menos cada 5 min si hubo actividad). Las dos cosas caducan con la lifecycle rule del bucket (dev/test 30 días, prod 90): la ventana de recuperación a un instante es la retención menos una semana de margen. El rol de las instancias solo escribe; **leer y restaurar exige credenciales de administrador** (enlace temporal con `aws s3 presign`, como en el camino A).

**Procedimiento (siempre sobre una instancia/base desechable primero, nunca directo sobre prod):**
1. Elegir el instante objetivo **en UTC** (justo antes del error) y la última copia base anterior a ese instante.
2. Bajar esa copia base y todos los segmentos de WAL entre ella y el instante; descomprimir la copia en un directorio de datos vacío (`tar -xzf base_*.tar.gz -C <pgdata>`).
3. En `<pgdata>`: crear `recovery.signal` y añadir a `postgresql.auto.conf`:
   `restore_command = 'gunzip -c /wal-archive/%f.gz > %p'`, `recovery_target_time = '<AAAA-MM-DD HH:MM:SS+00>'` y `recovery_target_action = 'promote'`. Montar los segmentos descargados en `/wal-archive` y dar permisos 700 al directorio.
4. Arrancar `postgres:16-alpine` sobre ese directorio y esperar `archive recovery complete` (el aviso de que falta `00000001.history.gz` es normal). Comprobar `select pg_is_in_recovery()` = f.
5. Validar como en la sección 4/6 (conteos, última fila, `migrate`/`tenants:artisan migrate`, `/up`, `/api/health/deep`, login) y, solo con aprobación de Luis, promover.

**Probado el 2026-10-05 en local (contenedor aislado):** tras borrar 100 filas y añadir una errónea, la restauración al instante anterior devolvió 100 filas previas + 50 posteriores + 0 erróneas. **Probado con los archivos reales de dev (2026-10-05):** copia base de 181 MB + 5 segmentos de WAL bajados de S3 en 8 s, descomprimidos en 2 s y recuperados (`archive recovery complete`) en pocos segundos en un contenedor local; los datos coinciden con dev en vivo (47 usuarios, 256.166 registros de auditoría, misma última fecha). La copia base de dev tardó 48 s en generarse y subirse. Falta repetirlo con la base de prod y en el simulacro del 2026-11-21.

**Monitoreo:** `ship-wal.sh` publica en CloudWatch (namespace `AtlanticERP`, dimensión `Environment`) `WalArchivePending` y `WalArchiveFailing`; las alarmas avisan si el archivado se atasca (el disco raíz se llenaría).

**Notas de operación:** el usuario de Postgres no se llama `postgres` (es el de `POSTGRES_USER` del contenedor): usarlo en `psql -U`. El archivado deja los segmentos en `/data/wal-archive` del disco raíz del host hasta que `ship-wal.sh` (cron, cada minuto) los sube; alarmas `atlanticerp-<entorno>-wal-pending` (>12 segmentos o sin datos 15 min) y `-wal-failing`. Un segmento forzado con `pg_switch_wal()` pesó ~5 MB comprimido en dev.
