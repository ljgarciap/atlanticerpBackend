# Manual técnico — Servicios

<!-- nav-manuales:inicio -->
> **Navegación:** [Índice de manuales](README.md) · ← [CRM](crm.md) · [Compras](compras.md) →
> **Módulos relacionados:** [Ventas & Diseño](ventas-diseno.md) · [Bodega](bodega.md) · [Compras](compras.md)
<!-- nav-manuales:fin -->

> **Para quién:** un desarrollador que nunca ha tocado este módulo y necesita entenderlo y modificarlo con seguridad.
> **Base del manual:** backend `atlanticerp-backend` rama `dev` (2026-10-12) y frontend `atlanticerp-frontend` `origin/dev` (commit `95cc675`, 2026-10-12).
> **Cómo se verifica:** `python3 scripts/manual-coverage.py servicios --frontend <ruta>` comprueba que cada pieza del módulo esté nombrada aquí. Mide presencia, no calidad.
> **Documentos hermanos:** `app/Modules/Servicios/CLAUDE.md` (modelo de datos de la Fase 4, parcialmente desactualizado), diagrama `docs/architecture/diagramas/servicios.md`, ERD `docs/architecture/erd/servicios.md`, diseño `docs/architecture/servicios-fase4-diseno.md` y plan `docs/architecture/servicios-fase4-batches.md`.

---

## 1. Propósito y alcance

Servicios gestiona **tickets de servicio técnico** de Atlantic: instalaciones, garantías, reclamos y retrofits. Cubre desde que se reporta el ticket hasta que se cierra, con el equipo técnico, las herramientas y los insumos.

| Área | Qué hace |
|---|---|
| **Tickets** | Alta, agenda, cambio de estado, cancelación, productos, adjuntos y PDF. |
| **Informe de Inspección** | Hallazgos, materiales, fotos y firmas por ticket; modo formulario o archivo subido; versión móvil. |
| **Hoja de Reclamo** | Equivalente del informe para tickets de tipo Reclamo. |
| **Cotización de Servicio** | Ítems de producto, mano de obra y subcontratado; envío al cliente y decisión (aprobar o rechazar). |
| **Técnicos internos** | Perfil, agenda, estado calculado, comisión mensual. |
| **Técnicos externos** | Subcontratistas con tarifa e historial de tarifas. |
| **Herramientas** | Inventario con código, estado, asignación, solicitudes de compra y kardex. |
| **Insumos** | "Reserva Servicios": stock de insumos en una bodega propia, con mínimo y solicitud de compra. |
| **Inicio, Reportes y Ajustes** | Indicadores, panorama del mes, biblioteca, metas y parámetros. |

**Regla de oro:** el cierre de un ticket depende de dos denormalizaciones del propio ticket (`quote_status` e `inspection_report_status`); cualquier cambio en cotización o informe debe mantenerlas al día (sección 4.2).

---

## 2. Mapa del código

### 2.1 Backend (`atlanticerp-backend/app/Modules/Servicios/`)

**Controladores** (`Http/Controllers/`)

| Archivo | Para qué |
|---|---|
| `TicketController` | Lista, tablero, estadísticas, detalle, alta, edición, agenda, estado, cancelación, productos, adjuntos y PDF. |
| `InspectionReportController` | Informe de Inspección: campos, guardado, fotos, subida de archivo, modo móvil y PDF. |
| `ClaimSheetController` | Hoja de Reclamo: ver, guardar y PDF. |
| `ServiceQuoteController` | Cotización de un ticket: crear, guardar, enviar, decidir, historial y documento. |
| `ServiceQuoteItemController` | Ítems de la cotización. |
| `InternalTechnicianController` | Técnicos internos: lista, alta, edición, baja, agenda, estadísticas, comisión. |
| `ExternalTechnicianController` | Técnicos externos: lista, alta, estado, tarifa. |
| `TechnicianController` | Lista de técnicos internos asignables a un ticket. |
| `ToolController` | Herramientas, solicitudes de compra y kardex. |
| `InsumoController` | Insumos de la Reserva Servicios. |
| `HomeController` | Indicadores del Inicio y meta de instalaciones. |
| `ReportsController` | Reportes del módulo. |
| `ServiciosSettingsController` | Parámetros del módulo. |
| `ClientLookupController` | Búsqueda de clientes y proyectos de Ventas & Diseño al crear un ticket. |
| `ProductLookupController` | Búsqueda de productos del catálogo al crear un ticket. |

**Form Requests** (`Http/Requests/`): `StoreTicketRequest`, `UpdateTicketRequest`, `ScheduleTicketRequest`, `CancelTicketRequest`, `SaveInspectionReportRequest`, `SaveClaimSheetRequest`, `SaveServiceQuoteRequest`, `StoreServiceQuoteItemRequest`, `UpdateServiceQuoteItemRequest`, `DecideServiceQuoteRequest`, `StoreInternalTechnicianRequest`, `UpdateInternalTechnicianRequest`, `StoreExternalTechnicianRequest`, `StoreCommissionCaptureRequest`.

**Modelos** (`Models/`, 24): `Ticket`, `TicketProduct`, `TicketAttachment`, `TicketRescheduleHistory`, `TicketFolioCounter`, `InspectionReport`, `InspectionReportMaterial`, `InspectionReportPhoto`, `InspectionReportFolioCounter`, `ClaimSheet`, `ServiceQuote`, `ServiceQuoteItem`, `ServiceQuoteFolioCounter`, `InternalTechnician`, `ExternalTechnician`, `ExternalTechnicianRateHistory`, `CommissionCapture`, `Tool`, `ToolMovement`, `ToolCodeCounter`, `ToolPurchaseRequest`, `InsumoSetting`, `InsumoPurchaseRequest`, `ServiciosSetting`.

**Servicios** (`Services/`, 27)

| Servicio | Para qué |
|---|---|
| `TicketService` | Ciclo de vida del ticket (749 líneas). |
| `TicketFolioService` | Folio `PREFIJO-AÑO-NNNN` atómico. |
| `TicketAttachmentService` | Adjuntos en S3 con límites configurables. |
| `TicketPdfService` | PDF del ticket. |
| `InspectionReportService` | Informe de Inspección (602 líneas). |
| `InspectionReportFolioService`, `InspectionReportPdfService` | Folio y PDF del informe. |
| `ClaimSheetService`, `ClaimSheetPdfService` | Hoja de Reclamo y su PDF. |
| `ServiceQuoteService` | Cotización de servicio (737 líneas). |
| `ServiceQuoteFolioService`, `ServiceQuotePdfService`, `ServiceQuotePdfLayoutEstimator` | Folio, PDF y estimador de paginación. |
| `InternalTechnicianService` | Perfil, estadísticas, agenda y rutas de hoy. |
| `TechnicianStatusService` | Estado calculado del técnico. |
| `TechnicianSpecialtyService` | Qué técnico atiende qué tipo de ticket. |
| `ExternalTechnicianService` | Subcontratistas y su historial de tarifas. |
| `CommissionCalculationService`, `CommissionCaptureService` | Comisión mensual del técnico. |
| `ToolService`, `ToolCodeService` | Herramientas, kardex y códigos. |
| `InsumoService` | Insumos de la Reserva Servicios. |
| `HomeService`, `InstallationGoalService`, `ReportsService` | Lectura agregada y meta de instalaciones. |
| `ServiciosSettingsService` | Parámetros del módulo. |
| `ClientLookupService` | Búsquedas contra Ventas & Diseño. |

**Eventos** (`Events/`): `TicketCreated`, `TicketStageChanged`, `ServiceQuoteCreated`, `ServiceQuoteSent`, `ServiceQuoteApproved`, `ServiceQuoteRejected`, `InspectionReportCompleted`.
**Listener** (`Listeners/`): `SyncInsumoReservationOnPurchaseOrderReceived`.
**Excepciones** (`Exceptions/`): `ClaimSheetAccessDeniedException`, `ClaimSheetLockedException`, `ExternalTechnicianDeactivationRequiresConfirmationException`, `InspectionReportAccessDeniedException`, `ServiceQuoteAccessDeniedException`, `ServiceQuoteActionBlockedException`, `TicketAttachmentLimitExceededException`, `TicketCloseBlockedException`, `ToolPurchaseRequestAlreadyReceivedException`.

### 2.2 Frontend (`atlanticerp-frontend/src/`)

Páginas en `pages/servicios/`, componentes en `components/servicios/`, cliente HTTP `api/serviciosApi.ts`, tipos `types/servicios.ts`. Detalle en la sección 8.

---

## 3. Modelo de datos

Esquema PostgreSQL `{tenant}_servicios`. Diccionario de columnas en `docs/architecture/erd/servicios.md` (24 tablas).

| Tabla | Para qué |
|---|---|
| `tickets` | Núcleo. `numero` (`INS`, `GAR`, `REC`, `RET` + año + consecutivo), `tipo`, `subtipo`, `tipo_instalacion` (`internal` o `subcontracted`), `estado`, `quote_status`, `inspection_report_status`, `internal_technician_id` (id de **usuario**, no del perfil), `scheduled_at`, `scheduled_ends_at`, `visit_count`, `closed_at`, `requerimientos_especiales` (JSON), `sales_project_id` (opcional). |
| `ticket_products` | Productos reclamados; `cantidad_reclamo` se captura al crear, recibida y pendiente avanzan después. |
| `ticket_attachments` | Adjuntos privados en S3 (URL firmada de 15 min). |
| `ticket_reschedule_history` | Cada reagendamiento con su motivo obligatorio. |
| `ticket_folio_counters` | Contador por (prefijo, año) con `lockForUpdate()`. |
| `inspection_reports` | Un informe por ticket: `modo` (`formulario` o `archivo_subido`), `estado` (`pending`, `completed`), hallazgos, conclusión, firmas. |
| `inspection_report_materials`, `inspection_report_photos`, `inspection_report_folio_counters` | Materiales usados, fotos por categoría y folio. |
| `claim_sheets` | Hoja de Reclamo: `diagnostico` y `estado`. |
| `service_quotes`, `service_quote_items`, `service_quote_folio_counters` | Cotizaciones (varias por ticket, solo la última activa) e ítems de tipo `product`, `labor` o `subcontracted`. |
| `internal_technicians` | Perfil: `user_id` **nullable**, `especialidad` (`general` o `warranty`), `color`, `working_hours` (fijo, no editable). |
| `external_technicians`, `external_technician_rate_history` | Subcontratistas y bitácora inmutable de tarifas. |
| `commission_captures` | Captura mensual de los criterios de comisión por técnico. |
| `tools`, `tool_movements`, `tool_code_counters`, `tool_purchase_requests` | Herramientas, kardex (`ingreso`, `damaged`, `lost`, `worn`), códigos y solicitudes. |
| `insumo_settings`, `insumo_purchase_requests` | Qué productos trackea Servicios con su mínimo, y el puente hacia la orden de compra real. |
| `servicios_settings` | Parámetros clave-valor (sección 4.4). |

**Referencias a otros esquemas:** `tickets.sales_project_id` → Ventas & Diseño; `ticket_products.catalog_product_id` → catálogo; el stock de insumos vive en `bodega.product_warehouse_stock` (no hay tabla de stock propia).

**Invariantes:** el estado del técnico **nunca** se guarda; `tarifa_visible` solo oculta, no cambia el cálculo; el historial de tarifas es inmutable.

---

## 4. Reglas de negocio y parámetros

### 4.1 Estados del ticket

`reported → scheduled → on_site → resolved → closed`, más `cancelled` (con motivo obligatorio). El cambio de estado y la cancelación son de `lider_servicios` y `superadmin`.

- Al entrar a `on_site`, `visit_count` suma 1 (base del indicador "Resuelto en 1ra visita").
- La primera vez que llega a `resolved` o `closed`, se fija `closed_at` y no se mueve más.
- Cada cambio dispara `TicketStageChanged`.

### 4.2 Gate de cierre

`TicketService::assertCloseAllowed` bloquea `resolved` y `closed` con `TicketCloseBlockedException` (422) si:
- `inspection_report_status` no es `completed` ni `not_applicable`: "falta completar el informe de inspección".
- `quote_status` no es `approved` ni `not_applicable`: "falta aprobar la cotización".

`cancelled` nunca pasa por este gate. Estados iniciales (`deriveInitialStatuses`):

| Tipo | Cotización | Informe |
|---|---|---|
| Instalación, subtipo `installation` | `not_applicable` | `not_applicable` |
| Reclamo | `pending` | `not_applicable` |
| Cualquier otro (garantía, retrofit, inspección) | `pending` | `pending` |

Subtipos válidos: instalación → `installation`, `inspection`; garantía → `warranty_generic`, `replacement_inspection`; reclamo y retrofit no llevan subtipo.

### 4.3 Agenda y especialidad

`TicketService::schedule` exige que el técnico exista y que `TechnicianSpecialtyService::matches` lo permita: **garantías solo las atiende `garantias_servicios`; los demás tipos, `tecnico_servicios`**. Reagendar exige motivo. Un técnico eliminado (soft delete) deja de ser asignable.

**Estado del técnico** (`TechnicianStatusService`): `Disponible`, `En ruta` (visita que empieza dentro de la próxima hora), `Ocupado` o `Fuera`, calculado en cada request con la agenda de hoy y la hora de Panamá. Una visita sin fin estimado usa una duración supuesta solo para el cálculo.

**Baja de un técnico** (`deletionBlockers`): se bloquea si tiene tickets abiertos (`reported`, `scheduled`, `on_site`) o herramientas asignadas.

### 4.4 Parámetros (`servicios_settings`, clave-valor con defaults en código)

| Clave | Default |
|---|---|
| `sin_agendar_umbral_dias` | 3 |
| `sin_responder_umbral_dias` | 5 |
| `adjuntos_max_archivos` / `adjuntos_max_size_mb` | 10 / 15 |
| `sla_dias_claim` / `warranty` / `installation` / `retrofit` | 2 / 3 / 7 / 10 |
| `itbms_percent` | 7 |
| Peso de comisión: calidad, satisfacción, SLA, puntualidad, actitud | 25 / 25 / 25 / 15 / 10 |
| Monto máximo por criterio | 100 / 100 / 100 / 60 / 40 (tope mensual = suma = 300) |
| `commission_satisfaccion_umbral_100` / `_60` | 4.5 / 3.5 |
| `commission_sla_umbral_100_pct` / `_60_pct` | 95 / 85 |
| `commission_puntualidad_max_incidencias_50pct` | 1 |
| `commission_actitud_escala_max` | 5 |
| Meta de instalaciones: valor manual / meses de historia / crecimiento % | 36 / 3 / 10 |

El texto de Condiciones de la cotización también vive aquí. Se editan en `/servicios/ajustes` (`role:superadmin,management`).

### 4.5 Comisión del técnico

`CommissionCalculationService` convierte cada criterio a un porcentaje: satisfacción (promedio ≥ 4.5 → 100, ≥ 3.5 → 60, si no 0), SLA (% de tickets del mes cerrados dentro de los días hábiles de su tipo: ≥ 95 → 100, ≥ 85 → 60, si no 0), puntualidad (0 incidencias → 100, hasta el máximo configurado → 50, más → 0) y actitud (calificación ÷ escala máxima × 100). Calidad llega de la captura manual. `totalPonderado` aplica cada porcentaje al monto máximo de su criterio y devuelve también el peso en el desglose; con **licencia médica**, puntualidad queda en 0 y su monto se reparte entre los otros cuatro en proporción a sus propios montos. Los días hábiles son lunes a viernes, sin feriados.

### 4.6 Meta de instalaciones (`InstallationGoalService`)

Orden: (1) override manual de Gerencia para ese mes; (2) si hay menos meses de historial que los configurados, el valor manual (36); (3) si no, el promedio de instalaciones completadas de los N meses anteriores por (1 + crecimiento/100).

### 4.7 Cotización de servicio

Estados `draft → sent → approved | rejected`. Solo se crea una nueva si no hay ninguna o la última está `rejected`. Se bloquea con `ServiceQuoteActionBlockedException` si el informe no está completo o `not_applicable`, si se edita una que no es borrador, si se envía sin ítems, o si se decide una que no está `sent`. Cada paso actualiza `tickets.quote_status`. Solo `lider_servicios` o `superadmin` decide.

### 4.8 Herramientas e insumos

- Estados de herramienta: `good`, `damaged`, `worn`, `lost`. Dañada y perdida atribuyen responsable; desgaste y buen estado no.
- Cambiar estado genera movimiento en el kardex; reasignar no (solo actualiza `assigned_since`).
- Recibir una solicitud de compra de herramientas crea las unidades y es idempotente (`ToolPurchaseRequestAlreadyReceivedException`).
- Insumos: el disponible sale de la bodega "Reserva Servicios"; `ok` si disponible ≥ mínimo, si no `bajo_minimo`. El botón "Solicitar" nunca se bloquea por ese estado.
- El **consumo** de insumos se dispara desde `InspectionReportService` al completar el informe.

---

## 5. Flujos principales

### 5.1 Ticket de extremo a extremo
1. `POST /tickets` (`StoreTicketRequest`): `TicketService::create` asigna folio, snapshot del cliente (derivado del proyecto), productos, estados iniciales y dispara `TicketCreated`.
2. `PATCH /tickets/{id}/agendar` asigna técnico y fecha.
3. El técnico completa el informe (o la hoja de reclamo) y la cotización.
4. `PATCH /tickets/{id}/quote/decidir` aprueba.
5. `PATCH /tickets/{id}/estado` a `resolved` o `closed`, validado por el gate.

### 5.2 Informe de Inspección
Solo `lider_servicios`, `superadmin` o el técnico asignado al ticket (`InspectionReportAccessDeniedException`). Los campos dependen del tipo y subtipo (`fieldsForTicket`). `save` guarda hallazgos, materiales, firmas y marca `completed`; `saveArchivoMode` acepta un archivo con firmas. La firma del cliente se sube antes de abrir la transacción. Dispara `InspectionReportCompleted`. El informe sigue editable después de completado.

### 5.3 Hoja de Reclamo
Igual al informe, pero **queda bloqueada tras `completed`** (`ClaimSheetLockedException`). Solo para tickets de tipo Reclamo; `garantias_servicios` no escribe.

### 5.4 Cotización
`startNew` → `save` e ítems → `send` ("Enviar al cliente") → `decide`. Si se rechaza se habilita una nueva. `recommendedObservations` precarga la observación inicial desde el campo marcado `quote_recommendation` del informe.

### 5.5 Insumos y Compras
Al recibirse una orden de compra de Compras, `SyncInsumoReservationOnPurchaseOrderReceived` reubica el stock en la Reserva Servicios (la entrada genérica ya la genera el flujo de Recepción de Compras).

### 5.6 Desactivar técnico externo
Con proyectos activos responde 409 (`ExternalTechnicianDeactivationRequiresConfirmationException`) y el frontend reintenta con `confirm: true`.

---

## 6. API

Prefijo `/api`. Permisos en OR. Tabla generada con `scripts/manual-routes-table.py servicios` desde `route:list` el 2026-10-12 (83 rutas). Validaciones: el Form Request del mismo nombre que la acción (sección 2.1); varios endpoints validan en línea.

**`/servicios/calendar`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/calendar` | `OutlookCalendarController@index` | `servicios.read` |

**`/servicios/external-technicians`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/external-technicians` | `ExternalTechnicianController@index` | `role:superadmin,lider_servicios,management,tecnico_servicios,garantias_servicios` |
| POST | `/servicios/external-technicians` | `ExternalTechnicianController@store` | `role:superadmin,lider_servicios,management` |
| GET | `/servicios/external-technicians/{id}` | `ExternalTechnicianController@show` | `role:superadmin,lider_servicios,management,tecnico_servicios,garantias_servicios` |
| PATCH | `/servicios/external-technicians/{id}/estado` | `ExternalTechnicianController@updateEstado` | `role:superadmin,lider_servicios,management` |
| PATCH | `/servicios/external-technicians/{id}/tarifa` | `ExternalTechnicianController@updateTarifa` | `role:superadmin,lider_servicios,management` |

**`/servicios/home`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/home/approvals-pending` | `HomeController@approvalsPending` | `servicios.read` |
| GET | `/servicios/home/estado-tickets` | `HomeController@estadoTickets` | `servicios.read` |
| PUT | `/servicios/home/installation-goal` | `HomeController@updateInstallationGoal` | `role:superadmin,management` |
| GET | `/servicios/home/summary` | `HomeController@summary` | `servicios.read` |

**`/servicios/insumos`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/insumos` | `InsumoController@index` | `servicios.read` |
| POST | `/servicios/insumos` | `InsumoController@store` | `role:superadmin,lider_servicios` |
| POST | `/servicios/insumos/{id}/solicitar` | `InsumoController@storePurchaseRequest` | `role:superadmin,lider_servicios` |

**`/servicios/internal-technicians`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/internal-technicians` | `InternalTechnicianController@index` | `servicios.read` |
| POST | `/servicios/internal-technicians` | `InternalTechnicianController@store` | `role:superadmin,lider_servicios` |
| GET | `/servicios/internal-technicians/agenda` | `InternalTechnicianController@agenda` | `servicios.read` |
| GET | `/servicios/internal-technicians/sla-settings` | `InternalTechnicianController@slaSettings` | `servicios.read` |
| PATCH | `/servicios/internal-technicians/sla-settings` | `InternalTechnicianController@updateSlaSettings` | `role:superadmin,management` |
| GET | `/servicios/internal-technicians/stats` | `InternalTechnicianController@stats` | `servicios.read` |
| PUT | `/servicios/internal-technicians/{id}` | `InternalTechnicianController@update` | `role:superadmin,lider_servicios` |
| DELETE | `/servicios/internal-technicians/{id}` | `InternalTechnicianController@destroy` | `role:superadmin,lider_servicios` |
| GET | `/servicios/internal-technicians/{id}/commission` | `InternalTechnicianController@commission` | `role:superadmin,management,tecnico_servicios,garantias_servicios` |
| GET | `/servicios/internal-technicians/{id}/commission-capture` | `InternalTechnicianController@commissionCapture` | `role:superadmin,lider_servicios,management` |
| PUT | `/servicios/internal-technicians/{id}/commission-capture` | `InternalTechnicianController@saveCommissionCapture` | `role:superadmin,lider_servicios,management` |
| GET | `/servicios/internal-technicians/{id}/visitas-hoy` | `InternalTechnicianController@visitsToday` | `servicios.read` |

**`/servicios/lookup`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/lookup/master-clients` | `ClientLookupController@masterClients` | `role:superadmin,lider_servicios,management,vendedor_disenador` |
| GET | `/servicios/lookup/master-clients/{masterClientId}/sub-clients` | `ClientLookupController@subClients` | `role:superadmin,lider_servicios,management,vendedor_disenador` |
| GET | `/servicios/lookup/products` | `ProductLookupController@index` | `role:superadmin,lider_servicios,management,vendedor_disenador` |
| GET | `/servicios/lookup/sub-clients/{subClientId}/projects` | `ClientLookupController@projects` | `role:superadmin,lider_servicios,management,vendedor_disenador` |

**`/servicios/quotes`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/quotes` | `ServiceQuoteController@globalIndex` | `servicios.read` |

**`/servicios/reportes`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/reportes/biblioteca` | `ReportsController@biblioteca` | `servicios.read` |
| GET | `/servicios/reportes/comision-carlos-vergara` | `ReportsController@comisionCarlosVergara` | `role:superadmin,management,tecnico_servicios,garantias_servicios` |
| GET | `/servicios/reportes/completados-anio` | `ReportsController@completadosAnio` | `servicios.read` |
| GET | `/servicios/reportes/distribucion-tecnico` | `ReportsController@distribucionTecnico` | `servicios.read` |
| GET | `/servicios/reportes/distribucion-tipo` | `ReportsController@distribucionTipo` | `servicios.read` |
| GET | `/servicios/reportes/instalaciones-cotizadas-vs-realizadas` | `ReportsController@instalacionesCotizadasVsRealizadas` | `servicios.read` |
| GET | `/servicios/reportes/panorama-mes` | `ReportsController@panoramaMes` | `servicios.read` |

**`/servicios/settings`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/settings` | `ServiciosSettingsController@index` | `role:superadmin,management` |
| PATCH | `/servicios/settings` | `ServiciosSettingsController@update` | `role:superadmin,management` |

**`/servicios/technicians`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/technicians/internal` | `TechnicianController@internal` | `servicios.read` |

**`/servicios/tickets`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/tickets` | `TicketController@index` | `servicios.read` |
| POST | `/servicios/tickets` | `TicketController@store` | `role:superadmin,lider_servicios,management,vendedor_disenador` |
| GET | `/servicios/tickets/attachments/{attachmentId}/url` | `TicketController@attachmentUrl` | `servicios.read` |
| GET | `/servicios/tickets/board` | `TicketController@board` | `servicios.read` |
| GET | `/servicios/tickets/stats` | `TicketController@stats` | `servicios.read` |
| GET | `/servicios/tickets/{id}` | `TicketController@show` | `servicios.read` |
| PATCH | `/servicios/tickets/{id}` | `TicketController@update` | `role:superadmin,lider_servicios` |
| PATCH | `/servicios/tickets/{id}/agendar` | `TicketController@schedule` | `role:superadmin,lider_servicios` |
| POST | `/servicios/tickets/{id}/attachments` | `TicketController@storeAttachment` | `role:superadmin,lider_servicios,management,vendedor_disenador` |
| PATCH | `/servicios/tickets/{id}/cancelar` | `TicketController@cancel` | `role:superadmin,lider_servicios` |
| GET | `/servicios/tickets/{id}/claim-sheet` | `ClaimSheetController@show` | `servicios.read` |
| PUT | `/servicios/tickets/{id}/claim-sheet` | `ClaimSheetController@save` | `role:superadmin,lider_servicios,tecnico_servicios` |
| GET | `/servicios/tickets/{id}/claim-sheet/pdf` | `ClaimSheetController@pdf` | `servicios.read` |
| PATCH | `/servicios/tickets/{id}/estado` | `TicketController@updateStage` | `role:superadmin,lider_servicios` |
| GET | `/servicios/tickets/{id}/inspection-report` | `InspectionReportController@show` | `servicios.read` |
| PUT | `/servicios/tickets/{id}/inspection-report` | `InspectionReportController@save` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| GET | `/servicios/tickets/{id}/inspection-report/fields` | `InspectionReportController@fields` | `servicios.read` |
| POST | `/servicios/tickets/{id}/inspection-report/mobile` | `InspectionReportController@saveMobile` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| GET | `/servicios/tickets/{id}/inspection-report/pdf` | `InspectionReportController@pdf` | `servicios.read` |
| GET | `/servicios/tickets/{id}/inspection-report/pdf/blank` | `InspectionReportController@pdfBlank` | `role:superadmin,lider_servicios,tecnico_servicios` |
| POST | `/servicios/tickets/{id}/inspection-report/photos` | `InspectionReportController@storePhoto` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| POST | `/servicios/tickets/{id}/inspection-report/upload` | `InspectionReportController@saveArchivoMode` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| GET | `/servicios/tickets/{id}/pdf` | `TicketController@pdf` | `servicios.read` |
| POST | `/servicios/tickets/{id}/products` | `TicketController@storeProduct` | `role:superadmin,lider_servicios` |
| PATCH | `/servicios/tickets/{id}/products/{productId}` | `TicketController@updateProduct` | `role:superadmin,lider_servicios` |
| DELETE | `/servicios/tickets/{id}/products/{productId}` | `TicketController@destroyProduct` | `role:superadmin,lider_servicios` |
| GET | `/servicios/tickets/{id}/quote` | `ServiceQuoteController@show` | `servicios.read` |
| POST | `/servicios/tickets/{id}/quote` | `ServiceQuoteController@store` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| PUT | `/servicios/tickets/{id}/quote` | `ServiceQuoteController@save` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| PATCH | `/servicios/tickets/{id}/quote/decidir` | `ServiceQuoteController@decide` | `role:superadmin,lider_servicios` |
| PATCH | `/servicios/tickets/{id}/quote/enviar` | `ServiceQuoteController@send` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| POST | `/servicios/tickets/{id}/quote/items` | `ServiceQuoteItemController@store` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| PATCH | `/servicios/tickets/{id}/quote/items/{itemId}` | `ServiceQuoteItemController@update` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| DELETE | `/servicios/tickets/{id}/quote/items/{itemId}` | `ServiceQuoteItemController@destroy` | `role:superadmin,lider_servicios,tecnico_servicios,garantias_servicios` |
| GET | `/servicios/tickets/{id}/quote/{quoteId}/document` | `ServiceQuoteController@document` | `servicios.read` |
| GET | `/servicios/tickets/{id}/quotes` | `ServiceQuoteController@history` | `servicios.read` |

**`/servicios/tools`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/servicios/tools` | `ToolController@index` | `servicios.read` |
| GET | `/servicios/tools/movements` | `ToolController@movements` | `role:superadmin,lider_servicios,management` |
| GET | `/servicios/tools/purchase-requests` | `ToolController@purchaseRequests` | `servicios.read` |
| POST | `/servicios/tools/purchase-requests` | `ToolController@storePurchaseRequest` | `role:superadmin,lider_servicios` |
| POST | `/servicios/tools/purchase-requests/{id}/recibir` | `ToolController@receivePurchaseRequest` | `role:superadmin,lider_servicios` |
| PATCH | `/servicios/tools/{id}/asignacion` | `ToolController@updateAsignacion` | `role:superadmin,lider_servicios` |
| PATCH | `/servicios/tools/{id}/estado` | `ToolController@updateEstado` | `role:superadmin,lider_servicios` |

**Nombre del parámetro en OpenAPI:** las rutas de Informe de Inspección y Hoja de Reclamo se declaran con `{id}`, pero los controladores reciben `$ticketId`, así que la documentación OpenAPI las publica con ese nombre. Es el mismo recurso:

- `/servicios/tickets/{ticketId}/claim-sheet`
- `/servicios/tickets/{ticketId}/claim-sheet/pdf`
- `/servicios/tickets/{ticketId}/inspection-report`
- `/servicios/tickets/{ticketId}/inspection-report/fields`
- `/servicios/tickets/{ticketId}/inspection-report/mobile`
- `/servicios/tickets/{ticketId}/inspection-report/pdf`
- `/servicios/tickets/{ticketId}/inspection-report/pdf/blank`
- `/servicios/tickets/{ticketId}/inspection-report/photos`
- `/servicios/tickets/{ticketId}/inspection-report/upload`

---

## 7. Permisos, roles y acceso

Este módulo usa **roles** en la mayoría de las escrituras y el permiso `servicios.read` para lecturas.

| Rol | Puede |
|---|---|
| `superadmin`, `lider_servicios` | Todo: agendar, cambiar estado, cancelar, decidir cotización, gestionar técnicos, herramientas e insumos. |
| `management` | Crear tickets, técnicos externos, captura de comisión, ajustes y meta. |
| `vendedor_disenador` | Crear tickets y adjuntos; búsquedas de clientes y productos. |
| `tecnico_servicios` | Informe, hoja de reclamo, cotización de los tickets que tiene asignados; su comisión. |
| `garantias_servicios` | Igual que el técnico, salvo la hoja de reclamo. |

El ownership por ticket (solo el técnico asignado) lo aplican `assertCanEdit` de cada servicio, no la ruta. Los permisos del JWT vienen de `JwtClaimsBuilder`; `vendedor_disenador` vive en el Nivel 6 (ver CLAUDE.md del módulo).

---

## 8. Frontend

Rutas en `App.tsx`: `/servicios/inicio` (`HomePage`), `/servicios/tickets` (`TicketsPage`), `/servicios/tickets/externos` (`ExternalTechniciansPage`), `/servicios/tecnicos` (`InternalTechniciansPage`), `/servicios/insumos-herramientas` (`ToolsAndSuppliesPage`), `/servicios/reportes` (`ReportsPage`), `/servicios/ajustes` (`ServiciosSettingsPage`), `/servicios/cotizaciones` (`ServiceQuotesHistoryPage`), `/servicios/tools/kardex` (`ToolKardexPage`) y `/servicios/tickets/:ticketId/inspection-report/movil` (`InspectionReportMobilePage`). `/servicios` redirige a `/servicios/inicio`.

**Componentes** (`components/servicios/`)
- Tickets: `TicketBoard`, `TicketTable`, `TicketFiltersBar`, `TicketStatCards`, `TicketIndicators`, `TicketCreateModal`, `TicketDetailModal`, `TicketScheduleModal`, `TicketCancelModal`, `TicketStatusSelect`, `RequirementsChecklist`, `TechnicianBadge`.
- Documentos: `InspectionReportModal`, `ClaimSheetModal`, `ServiceQuoteModal`.
- Técnicos internos: `InternalTechnicianCard`, `InternalTechnicianStatCards`, `InternalTechnicianCreateModal`, `InternalTechnicianDetailModal`, `InternalTechnicianDeleteConfirmModal`, `InternalTechnicianAgendaView`, `InternalTechnicianVisitsModal`, `InternalTechnicianCommissionModal`, `InternalTechnicianCommissionResultModal`.
- Técnicos externos: `ExternalTechnicianTable`, `ExternalTechnicianCreateModal`, `ExternalTechnicianDetailModal`.
- Herramientas e insumos: `ToolsPanel`, `ToolTable`, `ToolCreateModal`, `ToolEstadoSelect`, `ToolAssignmentSelect`, `ToolPurchaseRequestModal`, `ToolReceiveButton`, `InsumosPanel`, `InsumoTable`, `InsumoCreateModal`, `InsumoRequestModal`, `InsumosPendientesPanel`.
- Inicio: `ServiciosHomeHeader`, `HomeMonthlyIndicators`, `HomePendingPanel`, `HomeRoutesPanel`, `EstadoTicketsPanel`, `ServiciosSinResponderPanel`, `ServiciosMyCalendarPanel`, `InstallationGoalModal`.
- Reportes: `ReportsPeriodSelect`, `ReportsPanoramaCards`, `ReportsInstallationsCards`, `ReportsTechnicianBars`, `ReportsTypeDonut`, `ReportsYearlyChart`, `ReportsLibraryTable`, `ReportsCommissionSection`.
- Compartidos: `ServiciosSearchPickerModal`, `ServiceQuotePdfViewerModal`, `SignaturePad`, `ComingSoonPanel`.

Fuera de la carpeta: `components/ServiciosSettingsPanel.tsx` (pestañas de ajustes) y `components/TechnicalSpecModal.tsx` (ficha técnica, compartida). HTTP en `api/serviciosApi.ts`, tipos en `types/servicios.ts`.

---

## 9. Eventos, jobs e integraciones

| Elemento | Detalle |
|---|---|
| `TicketCreated` (`servicios.ticket.created`), `TicketStageChanged` (`servicios.ticket.stage-changed`) | Sin listener. |
| `ServiceQuoteCreated`, `ServiceQuoteSent`, `ServiceQuoteApproved`, `ServiceQuoteRejected` (`servicios.quote.*`) | Sin listener. |
| `InspectionReportCompleted` (`servicios.inspection-report.completed`) | Sin listener; el consumo de insumos se hace dentro de `InspectionReportService`, no por evento. |
| `SyncInsumoReservationOnPurchaseOrderReceived` | Escucha `PurchaseOrderReceived` de Compras, registrado en `AppServiceProvider`. |
| Ventas & Diseño | Búsqueda de clientes, proyectos y productos (`ClientLookupService`, `ProductLookupController`). |
| Bodega | Stock de la Reserva Servicios y kardex. |
| Compras | Órdenes de compra de insumos y herramientas. |
| Outlook | `GET /servicios/calendar`. |
| S3 | Adjuntos, fotos, firmas y archivos del informe. |
| Auditoría | Trait `LogsAuditTrail` en los modelos de escritura. |

---

## 10. Configuración y datos semilla

- **Seeders:** `InternalTechnicianSeeder` (los cuatro técnicos reales vinculados por `user_id`, idempotente, todos los entornos) y `ServiciosDemoSeeder` (demo, solo `local`, `dev` y `testing`).
- **Parámetros:** tabla `servicios_settings`; sin fila se usa el default del código.
- **Bodega "Reserva Servicios":** debe existir; `InsumoService::reservaWarehouseId` y `bodegaCentralWarehouseId` la resuelven.
- **Variables:** las de S3 del `.env` general.

---

## 11. Pruebas

`tests/Feature/Servicios/` y `tests/Unit/Servicios/`. Ejecutar con `infra/test.sh --filter=Servicios`.

| Tema | Archivos |
|---|---|
| Tickets | `TicketFlowTest`, `TicketBatch2Test`, `TicketBatch3Test`, `TicketBatch3Part2Test`, `TicketBatch4Test`, `TicketPdfAttachmentsTest`, `TicketProductProjectFilterTest`, `TechnicianControllerTest` |
| Informe y cotización | `InspectionReportBatch8Test`, `InspectionReportInsumoConsumptionTest`, `InspectionReportPdfLayoutTest`, `ServiceQuoteBatch11Test`, `ServiceQuoteBatch12Test`, `ServiceQuotePdfLayoutEstimatorTest` |
| Técnicos | `ExternalTechnicianBatch5Test`, `InternalTechnicianBatch6Test`, `InternalTechnicianBatch7Test`, `InternalTechnicianEditDeleteTest` |
| Herramientas e insumos | `ToolsBatch13Part1Test`, `ToolMovementsEndpointTest`, `InsumoReservaTest` |
| Pantallas y transversales | `HomeControllerTest`, `ReportsControllerTest`, `ServiciosBatch10Test`, `ProductLookupDemoModeTest`, `ProductLookupSqlTest`, `AuditActionsScrum791Test`, `LogsAuditTrailTest`, `PdfLogoRegressionTest` |

Frontend: `npx vitest run src/components/servicios src/pages/servicios`.

---

## 12. Recetas para modificar el módulo

- **Agregar un tipo de ticket:** constantes en `Ticket` (`TIPOS`, `PREFIX_BY_TIPO`, subtipos), `deriveInitialStatuses`, `TechnicianSpecialtyService`, `ServiciosSettingsService` (nuevo `sla_dias_*`), `fieldsForTicket` del informe y los selectores del frontend.
- **Cambiar el gate de cierre:** `TicketService::assertCloseAllowed` y las listas `*_ALLOWING_CLOSE` de `Ticket`; mantener `quote_status` e `inspection_report_status` sincronizados.
- **Un parámetro nuevo:** clave y default en `ServiciosSettingsService`, entrada en `all`/`update`, y el panel `ServiciosSettingsPanel`. Nunca constante en el cálculo.
- **Un criterio de comisión nuevo:** `CRITERIOS`, pesos y montos por defecto, `convert*` y `totalPonderado`, la captura y el modal de comisión.
- **Un campo nuevo del informe:** el catálogo de `fieldsForTicket` por tipo/subtipo; marcar `quote_recommendation` si debe precargar la cotización.
- **Un consumidor de eventos:** crear el listener y registrarlo en `AppServiceProvider` (hoy ninguno de los siete eventos tiene uno, salvo el de Compras).

---

## 13. Problemas conocidos y deuda

1. **Los siete eventos propios se disparan sin listener:** `TicketCreated`, `TicketStageChanged`, `ServiceQuoteCreated`, `ServiceQuoteSent`, `ServiceQuoteApproved`, `ServiceQuoteRejected` e `InspectionReportCompleted`.
2. **Documentación interna desactualizada:** `app/Modules/Servicios/CLAUDE.md` describe hasta el Batch 6 y marca como "stub" cosas ya implementadas.
3. **`tickets.internal_technician_id` guarda el id del usuario**, no el del perfil `internal_technicians`, aunque el nombre sugiera lo contrario; un perfil sin `user_id` nunca es asignable.
4. **Días hábiles sin feriados** en SLA y comisión (lunes a viernes).
5. **Servicios grandes:** `TicketService` (749), `ServiceQuoteService` (737) e `InspectionReportService` (602).
6. **Dos rutas sin rol:** `GET /servicios/calendar` (endpoint compartido con otros módulos) y `home/approvals-pending` dependen solo de `servicios.read`.
7. **Reglas de escritura por rol, no por permiso:** el cambio de roles exige tocar `routes/servicios.php`.

---

## 14. Glosario

| Término | Significado |
|---|---|
| **INS / GAR / REC / RET** | Prefijos de folio: instalación, garantía, reclamo, retrofit. |
| **Gate de cierre** | Validación de cotización e informe antes de resolver o cerrar. |
| **Informe de Inspección** | Documento técnico de hallazgos del ticket. |
| **Hoja de Reclamo** | Documento del ticket de tipo Reclamo; se bloquea al completarse. |
| **Reserva Servicios** | Bodega de insumos de Servicios. |
| **Kardex** | Historial de movimientos de herramientas. |
| **SLA** | Días hábiles máximos para resolver según el tipo. |
| **Licencia médica** | Excluye puntualidad de la comisión y reparte su monto. |
| **Lider de Servicios** | Rol `lider_servicios`, hoy Aldo (antes Aaron). |

<!-- nav-manuales:inicio -->
---
**Navegación:** [Índice de manuales](README.md) · ← [CRM](crm.md) · [Compras](compras.md) →
<!-- nav-manuales:fin -->
