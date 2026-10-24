# Inventario de endpoints GET que escriben (condición C6 — SCRUM-1158, «Ver como usuario»)

**Fecha:** 2026-10-19 · **Origen:** `docs/security/THREAT-MODEL-impersonacion.md` §5, condición C6 · **Entrega a:** Senior Reviewer.

## Por qué importa
El modo solo lectura de «Ver como usuario» se apoya en el método HTTP: todo lo que no sea GET/HEAD/OPTIONS responde 403. Un GET que escribe algo se escapa de esa regla y, además, el autor que registraría (`sub`) sería el usuario suplantado. Hay que saber cuáles son.

## Método y límites
Se recorrieron las **379 rutas GET de `api/*`** (377 con controlador propio en el barrido dinámico de la sección E) (`php artisan route:list --json`; 377 con controlador propio, las 2 restantes son Swagger). Para cada método de controlador se buscaron escrituras directas (`create`, `save`, `update`, `delete`, `insert`, `Storage::…->put`, `dispatch`, `Cache::put`, etc.) y llamadas a servicios con verbos de escritura; las llamadas a servicios se cruzaron por nombre con los métodos de servicio que sí escriben y los candidatos se revisaron a mano.

**Límites:** es un análisis estático heurístico. Una escritura a dos o más niveles de profundidad dentro de servicios, o detrás de un nombre de método genérico, podría no aparecer. Por eso la defensa no depende solo de este inventario: cualquier GET nuevo que escriba debe usar el middleware `no_impersonation` (ver abajo) o, mejor, ser un POST.

## Resultado

### A. GET que escriben de verdad — 0 (las 2 que había pasaron a POST en SCRUM-1180)
| Ruta | Qué escribe | Estado |
|---|---|---|
| ~~`GET /api/bodega/returns/{id}/delivery-guide`~~ → **`POST`** | Crea una fila en `bodega.order_documents` (`OrderDocumentService::generateGuiaEntrega`, con el usuario como autor) y sube el PDF a S3 | **Resuelto (SCRUM-1180)**: ahora es POST, mismo permiso `bodega.read` y misma respuesta `{url}`. El GET viejo responde 405 |
| ~~`GET /api/bodega/returns/{id}/form`~~ → **`POST`** | Sube el formulario PDF a S3 (`CustomerReturnDocumentService::generateFormulario`); sin fila en BD | **Resuelto (SCRUM-1180)**: igual |

Mientras fueron GET estaban negadas en la sesión con `no_impersonation` (SCRUM-1158). Como POST, el middleware global de solo lectura (`BlockWritesWhenImpersonating`) las bloquea por método con 403 `impersonation_read_only`, y se retiró el alias de las dos rutas. El alias `no_impersonation` y la clase `DenyWhenImpersonating` **siguen registrados pero sin uso**: quedan como red de seguridad para el próximo GET que escriba (tienen su propia prueba). Semántica de repetición, sin cambios: cada llamada genera un objeto S3 nuevo (clave con fecha y UUID) y, en la guía, un `order_documents` nuevo; no hay deduplicación.

### B. GET que solo escriben en `audit_logs` — 14 (aceptados por diseño)
Descargas, exportaciones y vistas sensibles que dejan un evento de auditoría. Es lo esperado: con la condición C1 ese evento queda a nombre del **actor real** y con el suplantado y la sesión en la metadata.
`AdminContArchitectCommissionController@showComprobanteRetencion` · `@showCuentaCobro` · `AdminContPedidosGemelosController@excel` · `AdjustmentRequestController@productWarehouseStock` · `OrderDeliveryController@downloadDocument` · `OrderExportController@itemDetail` · `@pickingSheet` · `PurchaseOrderClaimController@pdf` · `PurchaseOrderController@pdf` · `ReportsController@export` · `ExternalTechnicianController@show` · `ClaimSheetController@pdf` · `InspectionReportController@pdf` · `TicketController@pdf` · `ServiceQuoteController@document`.
También cae aquí el rastro `security.access_denied` de `CheckPermission` en cualquier GET sin permiso.

### C. Falsos positivos descartados tras revisarlos
- **PDF y Excel (`generate`, `toExcel`)**: los generadores usados por los GET de PDF y exportaciones no persisten nada (el `->save()` de `toExcel` es el escritor de PhpSpreadsheet hacia la salida). Los `generate` que sí escriben (`QuoteService::generate`, `CotizaYaService::generate`, que asignan folio) son otras clases y solo se llaman desde POST.
- **`InventoryController@index → load`**: es `Model::load()` de relaciones de Eloquent, no el cargador de fotos.
- **`reject` / `resolve` / `applyChip` / `applyTextSearch`**: nombres homónimos de servicios que escriben; en los GET son filtros o lecturas.
- **`AuditLogController@entityTypes` (`Cache::remember`)**: clave de caché compartida, no por usuario; inocua.
- **`HealthController@deep` (escribe una clave de Redis)**: ruta pública sin JWT, no alcanzable con un token de impersonación.

### D. Escrituras internas del propio mecanismo
`AuthenticateJwt` puede cerrar la fila de `impersonation_sessions` (vencida, actor degradado, objetivo bloqueado). Es la única escritura que ocurre en una request GET de una sesión de impersonación; solo toca `ended_at`, `ended_reason` y `ended_by` (las columnas que el rol de la app sí puede actualizar, condición C4) y se audita al actor.

### E. Hallazgo del Pre-QA (MEDIO-2) y barrido dinámico — 2026-10-19
El Pre-QA encontró con `log_statement=mod` una escritura que el análisis estático no vio, porque ocurre dentro de un servicio a dos niveles: **`GET /api/ventas-diseno/home/summary`** congela un snapshot de comisión de mes cerrado (`admin_contab.commission_month_snapshots`) al calcular la comisión (`CommissionOrderService::percentForVendorMonth`). La ruta es la pantalla de inicio de la persona observada, así que **no se niega** (`no_impersonation` rompería «Ver como usuario»): con el claim `act` calcula y devuelve **lo mismo** (mes cerrado = `fijo: true`) **sin persistir**; sin `act` queda idéntico. Mismo patrón, corregido igual, en `CommissionService::cohortFor` (`ventas_diseno.commission_cohorts`, reporte de comisiones de Ventas & Diseño). Todas las rutas que llegan a esos métodos quedan cubiertas: `percentForVendorMonth` tiene la única escritura de snapshots de Admin&Cont.

El barrido dinámico posterior encontró otra familia, de menor gravedad: los servicios de ajustes de módulo **crean de forma perezosa la fila con el valor por defecto en la primera lectura** (`admin_contab_settings`, `bodega_settings`, `crm_settings`, `servicios_settings`). Son valores por defecto idempotentes, no datos de usuario, pero rompían igualmente la promesa de solo lectura; con `act` ahora devuelven el mismo valor por defecto sin crear la fila (`JwtActor::currentRequestIsImpersonating()`), y la primera lectura real la crea como siempre.

**Método del barrido** (se repite con el script de la sección «Cómo repetirlo»): stack aislado `f1158` recién sembrado, Postgres con `log_statement=mod`, un superadmin inicia «Ver como» con **16 objetivos** (un usuario por cada rol de negocio distinto más los demo `supervisor`, `electrician` y `readonly`) y pide las **377 rutas GET** de `api/*` (parámetros de ruta sustituidos por `1`, sin query string). Para cada petición se asignan por ventana de tiempo las sentencias `INSERT/UPDATE/DELETE` que Postgres registró. Resultado **antes** de los últimos arreglos: las únicas escrituras fuera de `audit_logs`, `db_audit_log` e `impersonation_sessions` (el cierre de la sesión) fueron las filas por defecto de los 4 `*_settings` de arriba, ya corregidas. Resultado **después**: ver la línea de abajo.

**Barrido final (código ya corregido, base recién sembrada):** 16 objetivos × 377 rutas = 6.032 peticiones (4.309 × 403, 1.107 × 200, 414 × 404, 138 × 422, 48 × 503, 11 × 401, 5 × 500). Postgres registró **2.634 sentencias DML en total y 0 fuera de `audit_logs`, `db_audit_log` e `impersonation_sessions`**. Los 403/404/422 son rutas sin permiso del rol observado o con parámetro `1` inexistente; los 503 son `/biblioteca` (sin S3 en el stack); los 5 × 500 son `ai/analyses/{uuid}` con `1` (Z-01/Z-02, previo); el 401 es `GET /compras/families/{id}`, que responde igual con un login normal.

**Límites del barrido (dicho con franqueza):** (1) los parámetros de ruta valen `1`, así que las rutas `.../{id}` que exigen un registro real o un UUID responden 404/500 sin llegar a la lógica de escritura (los 500 vistos son `ai/analyses/{uuid}` con `1`, el patrón ya conocido Z-01/Z-02, SCRUM-1039); (2) sin datos sembrados que activen la rama (por ejemplo un mes cerrado con pedidos), una escritura condicional no se dispara — por eso la comisión se cubre además con pruebas de servicio que sí fabrican esos datos; (3) las rutas que dependen de S3 o de integraciones externas (`/biblioteca`, 3 respuestas 503 por objetivo) no se ejercitan. Otras observaciones: `GET /compras/families/{id}` responde 401 de forma idéntica con un login normal (comportamiento previo del controlador, no de «Ver como»).

**Barrido tras SCRUM-1180 (las 2 rutas ya son POST):** mismo método, base recién sembrada y `log_statement=mod`; 16 objetivos × **375 rutas GET** (2 menos que antes: `form` y `delivery-guide` ya no son GET) = 6.000 peticiones (4.276 × 403, 1.108 × 200, 414 × 404, 138 × 422, 48 × 503, 11 × 401, 5 × 500, las mismas causas ajenas de arriba). Postgres registró **2.601 sentencias DML y 0 fuera de `audit_logs`, `db_audit_log` e `impersonation_sessions`**. `log_statement` se devolvió a `none` al terminar.

### Cómo repetirlo
Stack aislado con `ALTER SYSTEM SET log_statement='mod'` + recarga; script de barrido (inicia «Ver como» con un usuario por rol, pide cada GET y asigna a cada petición las sentencias DML de la ventana de tiempo) en la carpeta de trabajo de la sesión de 2026-10-19; el criterio de aceptación es **cero sentencias fuera de `audit_logs`, `db_audit_log` e `impersonation_sessions`**.

## Recomendaciones
1. ~~Pasar a POST `delivery-guide` y `form`~~ — **hecho en SCRUM-1180** (backend y frontend). No queda ninguna ruta con `no_impersonation`.
2. Regla de revisión: todo GET nuevo que cree filas, suba a S3, encole un Job o incremente un contador debe ser POST; si no puede serlo, `->middleware('no_impersonation')`.
3. Opcional: una prueba de arquitectura que falle cuando el cuerpo de un método de controlador enrutado por GET contenga las escrituras directas listadas arriba, para que el inventario no envejezca.
