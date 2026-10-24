# Runbook — sincronización de stock de ICG por delta (SCRUM-1114, épica SCRUM-749)

Spec y diseño: `docs/specs/scrum1114-icg-stock-delta-paralelo.md`.

## Qué hace
Mientras ICG y AtlanticERP operan en paralelo, **AtlanticERP es la fuente de verdad** y de ICG solo se importa su **variación** de AL4 (Bodega Central) desde la última lectura:

    delta = round(AL4_hoy) − legacy.productos.stock_al4_aplicado        (puede ser positivo o negativo)

Ejemplo: base 100, ICG pasa a 90 (delta −10), AtlanticERP movió −5 → AtlanticERP muestra 95 y la corrida lo deja en **85**. Con ICG en 120 (delta +20) quedaría en 115.
Cada delta se registra con `KardexService` como movimiento `Ajuste` en Bodega Central (referencia `legacy.sync_run` + id de la corrida) y mueve a la vez `product_warehouse_stock` y `catalog_products.stock_quantity`.
Se usa `Ajuste` y no `Entrada`/`Salida` a propósito: el KPI "Mayor rotación" de Bodega cuenta todo movimiento `Salida` y las ventas de ICG lo inflarían.

Reglas (decididas por Luis 2026-10-06): sin doble registro (cada movimiento físico va en un solo sistema); el delta es la diferencia neta de AL4 sin distinguir el origen; los negativos se permiten y se reportan;
un producto sin stock en AtlanticERP parte de 0.
Solo toca productos ligados a ICG, **activos y no descatalogados**; Lafayette no participa. Un producto con base nula (nunca cargado) y stock en AtlanticERP queda **retenido** y se reporta (nunca se suma a ciegas).

## Piezas
| Pieza | Qué es |
|---|---|
| `legacy:init-stock-baseline` | Inicializa la línea base (`stock_al4_aplicado`) UNA vez por entorno. Dry-run por defecto; `--apply --operador="Nombre"`. |
| `legacy:sync-incremental-stock` | Décimo paso de `legacy:sync-incremental-all` (después de `productos`). Aplica por defecto; `--dry-run`, `--force`, `--operador`. |
| `legacy:revert-stock-sync-run {id}` | Reversa de una corrida con contramovimientos. Dry-run por defecto. |
| `legacy.stock_sync_settings` | Fila única: `activo`, `baseline_inicializada_at`, umbrales del circuit breaker (con topes de validación: 50 % / 50.000 por producto / 500.000 en total), `min_filas_icg_pct` (guard de respuesta parcial de ICG, 80 %), `horas_aviso_apagado` (48), `correos_alerta`. Pantalla: Migración ICG → "Sincronización de stock (AL4)" (superadmin). |
| `legacy.sync_runs` (`entidad='stock'`) | Una fila por corrida; la columna `resumen` (JSON) es el informe de divergencia. |
| `infra/scripts/legacy-sync-heartbeat.sh` | Latido a CloudWatch desde el host (ver "Avisos"). |

## Activación por entorno (en este orden)
1. Desplegar (migraciones `2026_10_06_100000/1/2`). `activo = false` y sin línea base: la cadena nocturna no cambia (el paso responde "desactivado").
2. `php artisan legacy:init-stock-baseline --tenant=atlantic` (dry-run). Revisar: cuántos productos, de dónde sale la base (`stock_al4` / sin fila / reconstruida) y **que "No cuadran" sea 0**.
   En prod, medido el 2026-10-06: 11.000 `round(stock_al4)` = `stock_quantity`, 0 diferencias, 0 movimientos de kardex desde la carga.
   Si algo no cuadra, `--apply` se niega: resolver la causa; `--force` solo sabiendo que el primer delta lo desajustará.
3. `… --apply --operador="Nombre"`. Marca `baseline_inicializada_at` (sin ella el sync se niega a correr).
4. `php artisan legacy:sync-incremental-stock --tenant=atlantic --dry-run`. Leer el informe completo. La primera corrida real captura todo lo que ICG movió desde la carga; con muchos días de deriva puede superar los umbrales.
5. Ajustar los umbrales en la pantalla de superadmin con lo que mostró el dry-run (los valores iniciales —30 %, 2.000 por producto, 30.000 en total— son provisorios).
6. **Primera corrida real, supervisada:** `… --operador="Nombre"` (corre aunque `activo` esté apagado, `origen = manual`). Si se retiene por un umbral y el motivo está revisado: `--force`. Verificar el informe y que "No cuadran" siga igual o mejor.
7. Encender `activo` en la pantalla de superadmin: desde ahí corre sola en la cadena nocturna.
8. Instalar el latido y las alarmas (siguiente sección).
Orden de entornos: dev → test → prod. En prod la cadena corre a las 21:00 (ICG ya sin actividad: la lectura es una foto consistente).

## Cómo lanzar un comando manual por ssh (para que la auditoría guarde quién fue)
`--operador` es texto libre. Dentro de `docker compose exec` la sesión ssh no llega sola (el proceso ve el usuario y el hostname del CONTENEDOR), así que reenvíe el usuario y el origen explícitamente:

    ssh <servidor> 'cd /var/www/backend && docker compose -f infra/docker-compose.<qa|prod>.yml exec -T \
      -e OPERATOR_OS_USER="$USER" -e SSH_CONNECTION="$SSH_CONNECTION" laravel \
      php artisan legacy:sync-incremental-stock --tenant=atlantic --operador="Nombre" --dry-run'

`audit_logs` y el correo de aviso guardan entonces `usuario_so`/`host` (el proceso), `usuario_ssh` y `origen_ssh` (solo la IP de origen). Son datos declarados por quien lanza el comando, no una prueba: sirven para cruzar con el log de ssh del servidor.

## Informe de divergencia (`sync_runs.resumen`, y la pantalla)
Elegibles, omitidos (inactivos), sin cambio, **con delta** (y cuántos parten de 0), unidades que entraron/salieron, mayor delta en un producto, retenidos (con ids), elegibles sin fila en AL4 (se toman como 0),
productos que quedaron en **negativo** (con ids) y **no cuadran** (desglose por bodega ≠ `stock_quantity`, con ids). Solo informa: no corrige nada.
**Divergencia de AtlanticERP respecto de ICG** (`resumen.divergencia_atlanticerp`, también en la pantalla de superadmin y en el dry-run): cuántos productos tienen en Bodega Central un valor distinto del AL4 vivo de ICG (`Central − línea base`), cuántos con más y cuántos con menos (y las unidades). AtlanticERP es la fuente de verdad, así que NO se corrige: un producto con 456 en AtlanticERP e ICG en 0 se queda en 456 y, cuando ICG se mueva (p. ej. −10), pasa a 446; la diferencia contra ICG se conserva. La diferencia no cambia al aplicar un delta (mueve Central y la base por igual), por eso es la misma en dry-run y después de aplicar.
Cada diferencia se contrasta con el **kardex propio de AtlanticERP en Bodega Central** (todo movimiento que no sea del sync ni de su reversa, que se cancelan): queda **explicada** si es igual a la suma de esos movimientos. Tres grupos: `explicados_por_kardex` (la suma iguala la diferencia: p. ej. AtlanticERP vendió 100 por su cuenta y está 100 por debajo de ICG), `con_residuo` (hay movimientos pero no alcanzan: ICG 0, AtlanticERP 454 y una sola salida de pedido deja 457 sin explicar) y `sin_movimientos` (stock de origen desconocido: datos de prueba o un alta fuera del kardex). `unidades_sin_explicar` suma el residuo absoluto de todos, y la lista de mayores va ordenada por residuo. Los movimientos de OTRA bodega no cuentan (la comparación es de Central). En prod, un residuo distinto de 0 merece revisión: es stock de AtlanticERP que no viene de ICG ni del kardex.

## Corrida retenida (`held_for_review`)
Se supera un umbral (% de productos con delta, unidades por producto o unidades totales). No se aplicó nada y se avisó por correo. Revisar el informe (`--dry-run`), decidir y, si es legítimo, aplicar con
`legacy:sync-incremental-stock --tenant=atlantic --operador="Nombre" --force`, o ajustar el umbral. Una respuesta VACÍA de ICG no se trata como "todo en 0": es un fallo de la corrida. Una respuesta PARCIAL (menos del `min_filas_icg_pct` % de las filas de la última corrida buena) también queda retenida.
No se puede activar el sync (API ni pantalla) hasta que la línea base esté inicializada, y nada de stock corre en paralelo: sync, línea base y reversa comparten un candado por tenant.

## Avisos (si la migración no corre, hay que enterarse)
0. **Eventos operativos (siempre, sin deduplicar):** un cambio del interruptor o de un umbral (quién y desde qué IP) y toda corrida, línea base o reversa lanzada a mano o con `--force` avisan por correo con el usuario real del servidor (`--operador` es texto libre). Quedan también en `audit_logs` con usuario del SO, host y origen ssh.
1. **Correo** (`LegacySyncAlertService`): un correo individual por destinatario (nadie ve a los demás) a todos los superadmins activos + `correos_alerta` (pantalla de superadmin), con deduplicación por entidad+tipo+día que se marca solo tras un envío exitoso. `LEGACY_ALERT_ALLOWED_DOMAINS` (opcional) restringe los dominios de `correos_alerta`. Cubre corrida retenida, fallida, saltada (health-check, también de las 9 entidades
   anteriores) y "sync de stock desactivado en producción más de 48 h después de su primera corrida".
2. **Health-check** (`legacy:check-sync-health`): la entidad `stock` está incluida; con `activo = false` no se considera "saltada" (para eso existe el aviso de desactivado).
3. **Latido externo** (cubre que el scheduler, Horizon o el host estén caídos — en ese caso ni la corrida ni su chequeo ocurren): `infra/scripts/legacy-sync-heartbeat.sh` corre en el cron del HOST cada 30 min y publica en el namespace `AtlanticERP` (dimensión `Environment`)
   `LegacySyncLastRunAgeHours` (la cadena arrancó) y `LegacySyncLastSuccessAgeHours` (la última corrida EXITOSA; sube si corre pero falla o queda retenida cada noche), y lo mismo `LegacySyncStock…` una vez que `stock` corrió. **Alarmar sobre las `…LastSuccessAgeHours`**, no sobre `LastRun`.
   **INSTALADO EN PRODUCCIÓN el 2026-10-06 (SCRUM-1114)** — cron `/etc/cron.d/atlanticerp-legacy-heartbeat` (root, `*/30 * * * *`, bitácora `/var/log/atlanticerp-legacy-heartbeat.log`, mismo formato que `atlanticerp-wal-archiving`) y dos alarmas de CloudWatch en `us-east-1`, con el mismo estilo que las de WAL
   (`Maximum`, `treat-missing-data=breaching`) y aviso a `atlanticerp-alerts` tanto al disparar como al recuperarse:
   - `atlanticerp-prod-legacy-sync-stale` → `LegacySyncLastSuccessAgeHours` (productos, primera entidad de la cadena), `Environment=production`.
   - `atlanticerp-prod-legacy-sync-stock-stale` → `LegacySyncStockLastSuccessAgeHours`.
   Umbral **30 h** (la cadena es diaria a las 21:00: la edad llega a ~24 h antes de la siguiente; si una noche falla, la alarma salta hacia las 03:00 del día siguiente), período **3600 s**, 1 período de evaluación (con 2 puntos por hora, un solo latido perdido no dispara).
   Con `treat-missing-data=breaching`, si el cron, el host o la conexión mueren, la falta de datos también dispara la alarma. No necesita permisos IAM nuevos (el rol `atlanticerp-cloudwatch-agent` de la instancia ya publica métricas, igual que `ship-wal.sh`).

       # alarma de prod (la de stock es igual con --metric-name LegacySyncStockLastSuccessAgeHours y nombre atlanticerp-prod-legacy-sync-stock-stale)
       aws cloudwatch put-metric-alarm --region us-east-1 --alarm-name atlanticerp-prod-legacy-sync-stale --namespace AtlanticERP \
         --metric-name LegacySyncLastSuccessAgeHours --dimensions Name=Environment,Value=production \
         --statistic Maximum --period 3600 --evaluation-periods 1 --threshold 30 \
         --comparison-operator GreaterThanThreshold --treat-missing-data breaching \
         --alarm-actions arn:aws:sns:us-east-1:940827433405:atlanticerp-alerts --ok-actions arn:aws:sns:us-east-1:940827433405:atlanticerp-alerts

   Estado: ambas en `OK` tras el primer período de evaluación (sin falsos positivos). **Dev y test NO tienen el latido instalado ni alarmas**: su cadena es semanal, así que si se instalan hay que usar umbral ~200 h y la dimensión `Environment` de cada uno (`dev` / `qa`).
   **Si se apaga la sincronización a propósito (cutover):** la edad de stock seguirá subiendo y `atlanticerp-prod-legacy-sync-stock-stale` dispararía; bórrela (`aws cloudwatch delete-alarms --alarm-names atlanticerp-prod-legacy-sync-stock-stale`). Si además se retira todo el sync de ICG, borre también la de productos y el cron.
4. **Teléfono (SMS):** pendiente. La app no tiene proveedor de SMS/WhatsApp; SNS puede enviarlos a un número suscrito, pero hoy `atlanticerp-alerts` solo tiene correos, la app no tiene `sns:Publish` y no está verificado que SNS entregue SMS
   a números de Panamá ni su costo. Si se aprueba: un tema separado de alta severidad (no `atlanticerp-alerts`, que recibe las 12 alarmas) suscrito a ese número y la misma alarma del latido apuntando a él. Evaluación a cargo del AWS Architect.

## Reversa de una corrida
    php artisan legacy:revert-stock-sync-run {id} --tenant=atlantic                              # dry-run
    php artisan legacy:revert-stock-sync-run {id} --tenant=atlantic --apply --operador="Nombre"
**Apague `activo` antes de revertir** (con el sync encendido la próxima corrida reaplicaría el delta mientras ICG conserve el valor; el comando se niega). Registra un contramovimiento `Ajuste` por cada movimiento de la corrida (referencia `legacy.sync_run_reversa`) y devuelve la base a su valor anterior. Un producto con una corrida POSTERIOR no se revierte (se lista para
decisión manual) y una corrida ya revertida no se revierte de nuevo. Es una reversa lógica: el historial de kardex conserva ambos movimientos. Es reintentable (idempotente por movimiento): tras una caída a mitad, repetir `--apply` no revierte dos veces lo ya revertido; la corrida solo se marca `revertida` cuando todo se revirtió sin error ni omitidos (si no, `reversa_parcial`).

## Cutover (el día que ICG se congela)
> **2026-10-14 — sincronización automática detenida por decisión de Luis** (ICG ya no se usa): se quitaron de `routes/console.php` la cadena `legacy:sync-incremental-all` (prod 21:00, qa y dev los domingos) y `legacy:check-sync-health`. Los comandos siguen disponibles a mano. Pendiente en prod tras el despliegue (lo ejecuta Luis, requiere AWS/SSH): borrar las alarmas `atlanticerp-prod-legacy-sync-stale` y `atlanticerp-prod-legacy-sync-stock-stale` (si no, saltan hacia 30 h después de la última corrida) y retirar el cron `/etc/cron.d/atlanticerp-legacy-heartbeat`.

1. Confirmar con Bodega que ya nadie registra movimientos en ICG.
2. Esperar una corrida completa (o `legacy:sync-incremental-stock --operador="Nombre"` a mano) y verificar un informe con 0 deltas.
3. Apagar `activo` en la pantalla de superadmin y, si corresponde, retirar el paso de `LegacySyncIncrementalAllCommand` y borrar las alarmas del latido de stock (`aws cloudwatch delete-alarms`). Dejar constancia en `docs/changelog.md`.
   Atención: con `activo = false` en producción, pasadas 48 h el health-check avisa "sync desactivado"; retire la entidad `stock` de `ENTITIES` (o espere a retirar el paso) para que no avise tras el cutover.

## Gotchas
- Los productos nuevos de ICG nacen con la base = su stock inicial (`CatalogProductLoader`), así que su primer delta es 0.
- `stock_al4` (staging) NO es la base: el incremental de productos solo lo refresca cuando cambia el hash del catálogo.
- Los fraccionarios de ICG se redondean a entero antes de calcular el delta (no hay deriva por decimales).
- En una prueba con `$this->artisan()` repetido el comando es la MISMA instancia: el extractor falso debe mutarse, no reemplazarse.
