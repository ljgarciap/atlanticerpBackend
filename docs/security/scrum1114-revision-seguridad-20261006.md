# SCRUM-1114 — Revisión de seguridad independiente (rol Cybersecurity)

Fecha: 2026-10-06. Alcance: sincronización de stock de ICG por delta (backend + `StockSyncPanel.tsx`). Solo lectura de código: no se ejecutó nada (ni tests, ni AWS, ni ssh). Referencias: OWASP Top 10 2021 (A01, A03, A04, A05, A08, A09) y registro de la épica SCRUM-959 (`docs/security/INFORME-SEGURIDAD-EPICA-959.md`, en la raíz `atlanticerp/`, no en `atlanticerp-backend/`).

## Veredicto: APROBADO CON CONDICIONES para pasar a Pre-QA

No hay Críticos. Hay 1 Alto (S-01) y 4 Medios que deben resolverse o aceptarse explícitamente (Luis) antes de **activar `activo` en prod**; ninguno impide que Pre-QA ejercite la funcionalidad en dev. Condiciones de salida: ver sección final.

## Lo que está bien (verificado)
- Autorización: las 2 rutas (`routes/legacy.php:46-49`) llevan `role:superadmin` (`EnforceRole`, compara el claim `role` del JWT con `in_array` estricto, 401/403 correctos). No hay ids en la URL: sin IDOR posible. El tenant sale del claim `tenant` del JWT validado (`AuthenticateJwt.php:48-64`) y `StockSyncSettings::getTable()` apunta al schema del tenant actual: sin acceso entre tenants.
- Mass-assignment: el controlador usa `$request->validated()` (`StockSyncSettingsController.php:31`); `baseline_inicializada_at` no está en `rules()`, por lo tanto no es editable por API aunque sí esté en `$fillable` (el comando de baseline la escribe). `correos_alerta` rechaza `null` y no-strings.
- XSS: la vista del correo usa `{{ }}` (escapado) en todas las variables (`legacy-sync-alert.blade.php:21-25`); el panel React renderiza todo como texto JSX, sin `dangerouslySetInnerHTML` (`StockSyncPanel.tsx`, verificado con grep). `circuit_breaker`/`error` se pintan como texto.
- CRLF/header injection: el asunto solo interpola `entorno`, `titulo` y `entidad` (valores fijos del código, `LegacySyncAlertMail.php:33`); los destinatarios pasan por `email:rfc` y Symfony Mailer rechaza saltos de línea en cabeceras.
- SQL contra ICG: constante, solo SELECT, sin entrada de usuario (`StockAl4Extractor.php:32`). El resto de SQL crudo (`StockDeltaService`, comandos) interpola solo nombres de tabla derivados de modelos y enteros casteados.
- Auditoría de ajustes: `LogsAuditTrail` en el modelo registra old/new de cada cambio de `activo`, umbrales y `correos_alerta` con usuario, IP y request_id (`AuditService::log` toma el JWT). Cada corrida real, la baseline y la reversa escriben `audit_logs` con `operador`, entorno, tenant y `forzada`.
- Integridad: aplicación por producto en transacción con `lockForUpdate` y re-chequeo de la base (`StockDeltaService.php:200-209`); movimientos vía `KardexService` con referencia `legacy.sync_run`+id; respuesta vacía de ICG = fallo, no "todo a 0"; reversa lógica por contramovimientos (no borra historial).
- Latido: `TENANT` validado con `case` (`legacy-sync-heartbeat.sh:34`), `$1` de `edad()` viene de una lista fija, solo se leen 2 claves del `.env` (región y APP_ENV, nunca se imprimen), `</dev/null` presente, solo `aws cloudwatch put-metric-data`.

## Hallazgos

### S-01 — Alto — Los ajustes del sync evaden el flujo de aprobación y la detección de fraude de Bodega (OWASP A04/A09)
- Archivos: `StockDeltaService.php:211-221` (kardex `Ajuste`, `realizadoPor = null`); `SecurityAnalysisJob::flagBodegaLargeAdjustments` solo lee `AdjustmentRequestLine` (ajustes con solicitud/aprobación), no `inventory_movements`.
- Escenario: una persona con escritura en la tabla `STOCKS` de ICG (o con acceso a un cliente ICG) sube AL4 de un producto de alto valor y lo retira físicamente; el sync lo refleja en AtlanticERP como un `Ajuste` sin solicitud, sin aprobador, sin usuario y sin pasar por la regla `bodega_ajuste_grande`, `madrugada` ni la de correlación con reubicaciones. El resultado contable de inventario es un ajuste "invisible" para la telemetría REQ-635. Hoy lo único que lo frena es el circuit breaker (S-02), pensado para detectar un error masivo, no un fraude a escala de unos pocos productos de alto valor (p. ej. +1.900 unidades en un producto con tope de 2.000).
- Mitigación (regla): (a) añadir a REQ-635 una regla determinista sobre `inventory_movements` con `referencia_entidad_tipo='legacy.sync_run'` que marque deltas por producto >= `fraude_ajuste_grande_unidades` (parámetro ya existente) y por valor (cantidad x costo) sobre un umbral paramétrico; (b) el resumen del correo/pantalla debe listar los N mayores deltas (ya existe `muestra_mayores`; incluirlo también en el aviso de corrida aplicada con deltas sobre umbral); (c) no tratar el sync como actor sin identidad: dejar `realizado_por` = identidad de servicio documentada o el `operador` en la referencia de texto.

### S-02 — Medio — Los umbrales pueden dejarse efectivamente sin límite y su cambio no avisa a nadie (A04/A09)
- Archivos: `StockSyncSettingsRequest.php:25-27` (pct hasta 100; por producto hasta 10.000.000; total hasta 100.000.000); `StockDeltaService.php:173` (`>` estricto: con 100 % el breaker nunca dispara).
- Escenario (fraude interno o cuenta superadmin comprometida): subir los 3 umbrales a sus máximos, esperar la corrida nocturna y dejar pasar un delta masivo; después restaurarlos. Cada PUT queda en `audit_logs`, pero nada notifica a los demás superadmins y el análisis diario no tiene regla sobre `legacy.stock_sync_settings.updated`. Mismo vector con `activo`.
- Mitigación: (a) correo inmediato a todos los superadmins (no solo `correos_alerta`) ante cualquier cambio de `activo`/umbrales/`correos_alerta`, con valores antes/después y quién; (b) tope máximo de validación coherente con el negocio (p. ej. pct <= 50, y por producto/total derivados del catálogo real, parametrizados en una tabla de superadmin, no en el Request); (c) regla en `SecurityAnalysisJob` que marque "umbral subido > X % y corrida con delta en las 24 h siguientes"; (d) valorar doble confirmación (segundo superadmin) para subir umbrales en prod.

### S-03 — Medio — `correos_alerta` sirve para exfiltrar los avisos a un dominio externo (A01/A04)
- Archivos: `StockSyncSettingsRequest.php:28-29`, `LegacySyncAlertService.php:112,119-140`.
- Escenario: un superadmin comprometido (o XSS en otra pantalla que haga PUT con su token) agrega hasta 20 correos externos. Desde ese momento reciben, sin ser usuarios, el motivo del breaker, conteos, nombre de entorno y comando manual, y los motivos de fallo (ver S-07). Además `Mail::to($lista)` pone a todos los destinatarios en el mismo `To:`, de modo que los externos ven los correos personales de los superadmins.
- Mitigación: (a) allowlist de dominios en una tabla paramétrica (dominios corporativos aprobados), validada en el servidor; (b) un envío por destinatario (o `bcc`) para no revelar la lista; (c) alta de un destinatario externo = aviso a los superadmins (cubierto por S-02a); (d) contenido del aviso a externos sin detalle técnico (solo "revisar la pantalla"). `email:rfc` admite formas poco comunes (comentarios, comillas); preferir `email:rfc,strict` o añadir `dns` según política.

### S-04 — Medio — Comandos manuales: atribución débil y bypass de controles sin aviso (A09/A08)
- Archivos: `LegacySyncIncrementalStockCommand.php:85-92,138,206-208` (`user_id => null`, `operador` texto libre; `--operador` además salta el interruptor `activo`; `--force` salta el breaker); `LegacyInitStockBaselineCommand.php:144,159-173` (`--force` aplica con inconsistencias y marca la baseline); `LegacyRevertStockSyncRunCommand.php:157-166`.
- Escenario: quien tenga shell en el servidor ejecuta `--operador="Aldo" --force` y aplica un delta masivo suplantando a otra persona; el registro dice "Aldo", sin usuario de SO, host, IP de la sesión ssh ni usuario de AtlanticERP. No se envía aviso por correo en una corrida forzada o manual (solo se avisa si fue retenida/falla).
- Mitigación: (a) añadir al metadata de auditoría `posix_getuid/USER`, `SSH_CONNECTION`/hostname y `SUDO_USER` leídos del entorno (no confiables pero útiles) y `git sha` desplegado; (b) aviso por correo a superadmins siempre que corra con `--force`, `--operador` o `--apply` (baseline/reversa), con totales; (c) el acceso ssh a prod ya está gateado por política de Luis: documentar en el runbook que `--operador` debe ser la persona real y cruzarlo con el log de ssh/SSM; (d) el panel React debe mostrar `origen=manual`+`forzada` (hoy solo "manual") y el operador.

### S-05 — Medio — Las alertas fallan en silencio y el latido no cubre "retenida/fallida de forma persistente" (A09)
- Archivos: `LegacySyncAlertService.php:101-115`; `legacy-sync-heartbeat.sh:41-47`.
- Escenario: (1) `Cache::add` marca la clave de deduplicación ANTES de enviar: si el SMTP (Office365) falla, la excepción se traga y no se reintenta hasta el día siguiente (clave 20 h). (2) Si Redis cae, `Cache::add` lanza y el `catch` lo traga: ningún correo. (3) Una segunda corrida retenida o fallida el mismo día (de otro motivo) no avisa. (4) El latido mide la edad de la última corrida con cualquier status (según su propio encabezado): un sync que queda `held_for_review` o `failed` cada noche refresca la métrica y la alarma de CloudWatch nunca dispara; solo queda el correo, que es el canal frágil de (1)-(3). Fail-open total de la alerta.
- Mitigación: (a) escribir la clave de dedupe solo tras envío exitoso (o dedupe por motivo, no por día); (b) ante fallo de envío o de caché, escalar a un canal secundario independiente (log nivel error con alarma CloudWatch Logs por patrón `legacy-sync-alert.envio_fallido`, o SNS cuando se apruebe `sns:Publish`); (c) añadir una segunda métrica del latido "horas desde la última corrida `success`" para `stock`, además de la de "última corrida cualquiera"; (d) la ausencia de destinatarios ya registra warning: convertirlo en error con alarma.

### S-06 — Baja — Reversa: marca la corrida como revertida aunque haya errores parciales
- Archivo: `LegacyRevertStockSyncRunCommand.php:81-85,153-155`. Si algunos productos fallan (`$errores`), igual se escribe `resumen.revertida` y la próxima ejecución se niega ("ya fue revertida"), dejando los productos fallidos sin forma soportada de reintento. Además el update de la base usa un entero casteado (sin inyección). Mitigación: marcar `revertida` solo con 0 errores o registrar los ids pendientes y permitir reintento de solo esos; auditar también los intentos con errores.

### S-07 — Baja — Mensajes de excepción en resumen, API y correos (A05/A09)
- Archivos: `LegacySyncIncrementalStockCommand.php:115,125,157-ish,fallar()`, `StockDeltaService.php:238` (`$e->getMessage()` guardado en `sync_runs.resumen`, devuelto por `GET /migracion/stock-sync` y enviado por correo). Un error de conexión a ICG (SQL Server tras el gateway VPN) o de Postgres puede traer nombre/host de servidor, nombre de BD o fragmentos de SQL. No verificado qué contiene en la práctica. Solo superadmin lo ve por API, pero llega a `correos_alerta` (S-03). Mitigación: guardar el mensaje completo solo en el log; en `resumen`/correo usar un código de causa genérico ("fallo de conexión a ICG", "error al aplicar") y recortar longitud.

### S-08 — Baja — Script del latido: permisos de ejecución e IAM (A05)
- Archivo: `legacy-sync-heartbeat.sh` + runbook (cron como root). (a) El cron ejecuta como root un script dentro del checkout `/var/www/backend`, que el despliegue reescribe con `git reset --hard`; si el usuario de deploy (o quien empuje a `dev`/`test`/`master`) controla ese archivo, tiene ejecución como root en el host. No verificado el propietario del directorio. (b) `PutMetricData` no admite restricción por recurso, pero sí condición `cloudwatch:namespace = AtlanticERP`; el runbook afirma que "el rol ya lo tiene" sin precisar el alcance: una credencial de instancia robada permitiría publicar métricas falsas (edad baja) y silenciar la alarma. (c) `docker exec` obliga a que el usuario del cron tenga acceso al socket de Docker (equivale a root). Mitigación: copiar el script a una ruta propiedad de root (755) en el despliegue o ejecutar el cron con un usuario dedicado; acotar la política IAM con la condición de namespace; verificar IMDSv2 obligatorio en las instancias (ver hallazgos de infraestructura de la épica).

### S-09 — Baja — Concurrencia y reglas de las corridas manuales
- Archivo: `LegacySyncIncrementalStockCommand.php` no usa un bloqueo de comando (ni `withoutOverlapping` visible en este archivo; en el scheduler no verificado). Dos corridas simultáneas (cron + manual) computan el plan sobre la misma base; el re-chequeo de base por producto evita el doble aplicado (bien), pero ambas registran `sync_runs` y el informe de una será inexacto. Mitigación: lock de aplicación (`Cache::lock`/`flock`) por tenant para el comando; si Redis cae, fallar cerrado.

### S-10 — Informativo
- I-1: inmutabilidad del kardex: no encontré (grep) triggers ni REVOKE sobre `inventory_movements`; la reversa es lógica, pero un rol con UPDATE directo en Postgres puede alterar el historial. Alineado con REQ-634 solo para `audit_logs`; considerar extenderlo a `inventory_movements` (no verificado en BD real).
- I-2: la conexión `legacy_icg` debería ser un usuario SQL Server con solo SELECT sobre `STOCKS`; no verificable desde el repo.
- I-3: los destinatarios del aviso se calculan por `user_roles.role = superadmin` y la ruta exige el claim `role = superadmin`: un usuario con rol adicional superadmin recibe el aviso pero no puede ver la pantalla; inconsistencia menor, sin impacto de seguridad.
- I-4: la validación del frontend (regex de correo, rangos) es solo conveniencia; la autoridad es el `FormRequest` (correcto).
- I-5: `dry-run` no registra auditoría ni corrida; imprime conteos por consola (sin secretos). No se detectaron secretos, tokens ni contraseñas en logs, correos ni `resumen`.

## Telemetría que detectaría el abuso (hoy vs. propuesta)
| Abuso | Hoy | Falta |
|---|---|---|
| Subir umbrales / apagar `activo` | `audit_logs` `legacy.stock_sync_settings.updated` (pasivo) | Aviso activo a superadmins + regla de análisis (S-02) |
| Delta masivo vía sync | Circuit breaker + correo si retenida | Regla REQ-635 sobre movimientos `legacy.sync_run` por producto y valor (S-01) |
| `--force`/`--operador` por ssh | `audit_logs` `legacy.sync_stock_delta` con `forzada` | Aviso inmediato + origen real (S-04) |
| Correos externos | `audit_logs` (pasivo) | Allowlist + aviso (S-03) |
| Sync parado/retenido persistente | Correo (frágil) + latido (solo "última corrida") | S-05 |

## Filas candidatas para `INFORME-SEGURIDAD-EPICA-959.md` (sección 3.1, NO editado)
| ID | Sev. | Hallazgo | Origen | Estado | Ticket |
|---|---|---|---|---|---|
| S-1114-01 | Alta | Los ajustes `Ajuste` generados por el sync de stock de ICG no pasan por solicitud/aprobación ni por las reglas REQ-635 (`bodega_ajuste_grande`, madrugada, correlación); `realizado_por` nulo | scrum1114-revision-seguridad-20261006 | Abierto — condición para activar en prod | SCRUM-1114 (seguimiento) |
| S-1114-02 | Media | Umbrales del circuit breaker sin tope práctico (pct 100, 10M, 100M) y sin aviso activo al cambiar `activo`/umbrales/correos | idem | Abierto | idem |
| S-1114-03 | Media | `correos_alerta` sin allowlist de dominio; destinatarios visibles entre sí en `To:`; vector de exfiltración de avisos | idem | Abierto | idem |
| S-1114-04 | Media | Comandos `--operador`/`--force`/`--apply` con `user_id` nulo, operador en texto libre, sin origen SO/ssh ni aviso por correo | idem | Abierto | idem |
| S-1114-05 | Media | Alertas fail-silent (dedupe antes de enviar, Redis/SMTP caído sin canal secundario) y latido que mide "última corrida cualquiera", no "última exitosa" | idem | Abierto | idem |
| S-1114-06 | Baja | Reversa marca `revertida` aun con errores parciales | idem | Abierto | idem |
| S-1114-07 | Baja | `getMessage()` de excepciones (ICG/Postgres) en `resumen`, API y correos | idem | Abierto | idem |
| S-1114-08 | Baja | Cron del latido como root desde el checkout; IAM `PutMetricData` sin condición de namespace | idem | Abierto | idem |
| S-1114-09 | Baja | Sin lock de comando por tenant en `legacy:sync-incremental-stock` | idem | Abierto | idem |
| S-1114-10 | Informativa | Kardex sin inmutabilidad a nivel de rol Postgres (extender REQ-634); usuario ICG de solo SELECT sin verificar | idem | Por verificar | idem |

## Condiciones para el paso a Pre-QA y a producción
- Para Pre-QA (dev): ninguna bloqueante. Pre-QA debe probar como casos negativos: PUT con rol no superadmin (403), `correos_alerta` con CRLF/21 elementos/duplicados, `baseline_inicializada_at` en el cuerpo (ignorado), umbral fuera de rango, y comprobar que S-02/S-03/S-04 se registran como hallazgos conocidos.
- Para activar `activo` en prod: cerrar S-01 y S-02 (o aceptación explícita y documentada de Luis), y S-05(a)/(b). S-03, S-04, S-06 a S-09 pueden quedar como tickets de seguimiento con fecha.
