# Runbook — `data:reset-for-launch` (SCRUM-1111) — EJECUTADO Y RETIRADO

> **Estado: ejecutado en producción el 2026-10-06 y retirado del repositorio (SCRUM-1112).** Este documento queda como registro histórico de cómo se hizo; el comando, su servicio, su clasificación
> de tablas y su prueba ya no existen en el código (última versión con el comando: commit `ef435cd9`, tag `v1.39.0.0`; para consultarlo: `git show v1.39.0.0:app/Console/Commands/ResetForLaunchCommand.php`).
> El evento de auditoría `auth.launch_data.reset` (con operador, respaldo y huella) permanece en `audit_logs`. El guard de `CatalogUatTestDataSeeder` (no siembra en `production`) SÍ se conserva.


Limpieza ÚNICA previa al lanzamiento: borra la data de prueba, conserva usuarios, configuración global y los datos de ICG/Lafayette, y reinicia la numeración a 1.
Spec aprobada (qué se conserva y por qué): `docs/specs/limpieza-previa-lanzamiento.md` del repo del workspace.
**Nunca se ejecuta en prod sin dry-run revisado con Luis, respaldo verificado y confirmación explícita de Luis.** El comando se retira del repositorio tras correrlo en prod.

## Qué hace
- **Vacía** (`TRUNCATE … RESTART IDENTITY`, identidad en 1) toda la data transaccional y los contadores de numeración (lista cerrada en `LaunchResetClassification::WIPE`).
- **Limpieza quirúrgica**: borra de `catalog_products`, familias, `sub_clients`/`master_clients`, `providers` e `internal_technicians` solo lo que no viene de ICG, Lafayette ni de un seeder
  (reglas en el servicio; los sub-clientes ICG se reconocen por enlace en `legacy.clientes`, por `SIN-CIF-<código>`, por CIF o por nombre).
- **Reinicia** `catalog_products.stock_quantity` a NULL (el backfill de ICG solo escribe donde está en NULL).
- **Conserva** `audit_logs`, usuarios/roles/permisos, el schema `legacy`, `lafayette_productos`, la configuración global y las tablas `*_bak_scrum990`.
- **Aborta** si hay una tabla sin clasificar, una FK desde una tabla conservada que no se pueda anular, o si el estado cambió entre el dry-run y el apply (huella).
- Todo en UNA transacción: cualquier discrepancia hace ROLLBACK (las secuencias se reinician con `ALTER SEQUENCE … RESTART`, que sí se revierte).

## Cruces con la migración ICG/Lafayette (revisado 2026-10-06)
En prod corre por el scheduler (hora de Panamá): `legacy:sync-incremental-all` a las **21:00** (cadena de 9 entidades, ~1h20, hasta ~22:20; cada entidad es un proceso del SO aparte),
`lafayette:sync-productos` a las **12:00 y 22:00**, y los jobs de **03:00–03:45** (`legacy:check-sync-health`, `security:analyze`, `audit-logs:archive`, `commissions:settle-internal`).
- **Ventana recomendada: entre ~04:00 y ~11:30.** Evita 21:00–22:30, 12:00 y 03:00–03:45.
- Detener el contenedor `scheduler` impide arrancar corridas nuevas, pero **mata** una que ya esté en curso: `legacy.sync_runs` solo registra al terminar (no hay estado «running»), así que antes de aplicar
  hay que comprobar que NO hay proceso en marcha: `docker compose -f infra/docker-compose.prod.yml exec -T scheduler ps aux | grep -E "legacy:|lafayette:" | grep -v grep` debe salir vacío.
- Ninguna entidad del sync escribe en las tablas que se vacían (el schema `legacy` y `lafayette_productos` se conservan). Solo `legacy:sync-incremental-productos` puede crear `catalog_products` nuevos
  (con su fila de Bodega Central) para artículos nuevos de ICG: es migración legítima y no choca con la limpieza.
- El deploy (`db:seed`) tampoco la deshace: los seeders de demostración no corren en `production` y el UAT quedó con guard. No desplegar durante la ventana.
- SCRUM-940 va DESPUÉS de la limpieza y de reanudar el scheduler solo cuando el stock ya está cargado.

## Antes (en este orden)
1. Release con el comando desplegado en el entorno (dev → test → prod) y el guard de `CatalogUatTestDataSeeder` (ya no siembra en `production`).
2. **Respaldo verificado de prod**: `pg_dump` y/o instantánea EBS bajo demanda. Anota su identificador: `--respaldo` lo exige y queda en la auditoría. Es la única reversa.
3. Avisar a quien use la app. Activar el modo mantenimiento: `php artisan down`.
4. Pausar Horizon **desde su propio contenedor** y detener el scheduler (en el contenedor `laravel`, `horizon:pause` responde «No processes to pause» y no hace nada; lo comprobó el ensayo en dev, 2026-10-06):

       docker compose -f infra/docker-compose.prod.yml exec -T horizon php artisan horizon:pause
       docker compose -f infra/docker-compose.prod.yml stop scheduler
       docker compose -f infra/docker-compose.prod.yml exec -T laravel php artisan horizon:status   # debe decir «Horizon is paused»

## Dry-run (solo lee; se puede repetir)
    docker compose -f infra/docker-compose.prod.yml exec -T laravel php artisan data:reset-for-launch --tenant=atlantic

Revisar con Luis: conteos por tabla, lista de borrados quirúrgicos (ids), referencias que se ponen en NULL, y que no haya «bloqueos». Anotar la huella.

## Aplicar (solo con la confirmación de Luis)
Fuera de local/testing exige el **rol de migración** (`DB_MIGRATE_*`, TRUNCATE no lo puede el rol de la aplicación), mantenimiento activo y Horizon sin correr:

    cd /var/www/backend
    DB_MIGRATE_USERNAME=$(grep "^DB_MIGRATE_USERNAME=" .env | cut -d= -f2-)
    DB_MIGRATE_PASSWORD=$(grep "^DB_MIGRATE_PASSWORD=" .env | cut -d= -f2-)
    docker compose -f infra/docker-compose.prod.yml exec -T \
      -e DB_USERNAME="$DB_MIGRATE_USERNAME" -e DB_PASSWORD="$DB_MIGRATE_PASSWORD" laravel \
      php artisan data:reset-for-launch --tenant=atlantic --apply --operador="<nombre>" --respaldo="<id del respaldo>"

Pide escribir `APLICAR production atlantic`. Tras el commit limpia el caché de aplicación (data derivada, no hay tokens revocados en él).

## Después
1. `php artisan up`, `exec -T horizon php artisan horizon:continue` y `start scheduler` (los syncs de ICG/Lafayette conviene reanudarlos después de cargar el stock, siguiente punto).
2. **SCRUM-940** (lo último antes de abrir a usuarios, el stock de ICG cambia a diario):
   `legacy:sync-productos --tenant=atlantic --dry-run` → `legacy:backfill-stock-al4 --tenant=atlantic --dry-run` (revisar) → sin `--dry-run` → `legacy:backfill-stock-bodega-central --tenant=atlantic` (dry-run, luego `--apply --operador=…`).
3. Verificación (spec §6): tablas vaciadas en 0; conservadas con las cifras de la spec; primer número de cada tipo = 1; `UAT-%` = 0 tras un deploy posterior; la app carga cada módulo vacío sin errores.

## Reversa
Solo restaurando el respaldo (ver `restore-postgres.md`); se pierde lo registrado después de él.

## Ensayo en dev (2026-10-06)
`--apply` real en dev con respaldo previo (`pg_dump` verificado con `pg_restore -l`): 107 tablas vaciadas (6.749 filas), 182 productos, 65 sub-clientes, 59 masters, 75 proveedores,
5 técnicos y 3 familias UAT borrados, 2 insumos por cascada, 11.000 `stock_quantity` a NULL. Verificado después con consultas independientes: usuarios, `audit_logs`, catálogo ICG,
`legacy.*` y `lafayette_productos` intactos; secuencias en 1 sin usar; app y Horizon operativos. Hallazgos que ya quedaron resueltos en el código: la cascada de
`insumo_settings` (excepción explícita) y la pausa de Horizon en su contenedor. Nota: el siguiente deploy a dev vuelve a sembrar la data demo y UAT (los seeders de demostración corren en dev).
