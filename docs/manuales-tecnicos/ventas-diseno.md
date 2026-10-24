# Manual técnico — Ventas & Diseño

<!-- nav-manuales:inicio -->
> **Navegación:** [Índice de manuales](README.md) · ← inicio · [CRM](crm.md) →
> **Módulos relacionados:** [CRM](crm.md) · [Servicios](servicios.md) · [Bodega](bodega.md) · [Admin & Contabilidad](admin-contabilidad.md) · [Factura Electrónica](factura-electronica.md)
<!-- nav-manuales:fin -->

> **Para quién:** un desarrollador que nunca ha tocado este módulo y necesita entenderlo y modificarlo con seguridad.
> **Base del manual:** backend `atlanticerp-backend` rama `dev` (2026-10-12) y frontend `atlanticerp-frontend` `origin/dev` (commit `95cc675`, 2026-10-12).
> **Cómo se verifica:** `python3 scripts/manual-coverage.py ventas-diseno --frontend <ruta>` comprueba que cada pieza del módulo esté nombrada aquí. Mide presencia, no calidad.
> **Documentos hermanos:** diagrama `docs/architecture/diagramas/ventas-diseno.md`, ERD `docs/architecture/erd/ventas-diseno.md`, `docs/architecture/ventas-diseno.md` (parcialmente desactualizado: lista 18 modelos, hoy hay 29), `docs/architecture/ventas-diseno-workflow-diagrama.md`. El módulo no tiene `CLAUDE.md` propio.

---

## 1. Propósito y alcance

Ventas & Diseño es **el CRM comercial real de AtlanticERP**. Ojo con el nombre: el módulo `App\Modules\CRM` (ver su manual) **no** es el CRM visible; la épica CRM (SCRUM-332) se construyó sobre este módulo. La ruta `/crm` del frontend solo redirige a `/ventas-diseno/pipeline`.

| Área | Qué hace |
|---|---|
| **Pipeline** | Tablero de proyectos por etapa (Lead, Diseño, Cotización, Propuesta, Aprobado, Perdido), con contactos, archivos, fechas de entrega, asociados y "Pasar a Pedido". |
| **Cotizaciones** | Documento con partidas e ítems, precios por tipo, descuentos, margen mínimo, folio, PDF y versiones. |
| **CotizaYA** | Cotizador de venta chica, aislado del flujo comercial (folio propio, cliente fijo). |
| **Clientes** | Jerarquía Cliente Master → Subcliente → Proyectos, contactos, datos fiscales (RUC/DV) y validación contra la DGI. |
| **Asociados (Arquitectos)** | Base única de arquitectos, diseñadores, contratistas, ingenieros y consultores. |
| **Catálogo** | Buscador y pantalla de productos (≈30 K), familias, ficha técnica y exportación PDF por volúmenes. |
| **Reportes y comisiones** | Resumen por período, metas, forecast, comisión escalonada de vendedores. |
| **Pedidos** | Cotizaciones aprobadas con margen cotizado contra facturado (solo Compras, Gerencia, superadmin). |
| **Home, Dashboard, Lista de Proyectos, Calendario, Bitácora** | Pantallas de consulta. |

**Regla de oro:** los porcentajes y umbrales de negocio viven en tablas paramétricas (sección 4), nunca como constante. Hay excepciones heredadas listadas en la sección 13.

---

## 2. Mapa del código

### 2.1 Backend (`atlanticerp-backend/app/Modules/VentasDiseno/`)

**Controladores** (`Http/Controllers/`)

| Archivo | Para qué |
|---|---|
| `PipelineController` | Tablero: listar, crear, ver, editar, cambiar etapa, archivos, "Pasar a Pedido" (673 líneas). |
| `PipelineCardContactController` | Contactos propios de la tarjeta. |
| `PipelineCardProductDeliveryController` | Qué productos van en cada fecha de entrega parcial (SCRUM-909). |
| `AssociateLinkController` | Vincula/desvincula asociados a una tarjeta o cotización (SCRUM-1121). |
| `QuoteController` | Cotización: crear, editar, borrador, generar, confirmar, duplicar, versiones, PDF (943 líneas). |
| `QuotePartController` | Partidas de una cotización. |
| `QuoteItemController` | Ítems de una partida: alta individual y masiva, precio, foto (653 líneas). |
| `QuoteContactController` | Contactos vinculados a una cotización (pivote `quote_contacts`). |
| `QuoteListController` | Listado de Cotizaciones. |
| `QuoteConditionsSettingsController` | Texto global de Condiciones. |
| `CotizaYaController` | Cotizaciones de venta chica. |
| `ClientController` | Buscar/crear Cliente Master y Subcliente desde Pipeline (flujo mínimo). |
| `ClientsController` | Pantalla Clientes completa (jerarquía y métricas). |
| `ClientTaxDataController` | Validar RUC/Cédula contra la DGI y editar datos fiscales. |
| `SubClientContactController` | Contactos de un Subcliente. |
| `SalesProjectController` | Buscar/crear proyecto acotado a un Subcliente. |
| `ArchitectController` | Asociados: picker acotado y directorio global. |
| `CatalogController` | Pantalla Catálogo: lista, detalle, ficha técnica, envío de PDF, estado de exportación. |
| `CatalogProductController` | Buscador de productos dentro de Cotización (nunca expone costo). |
| `CatalogProductFamilyController` | Familias del buscador. |
| `CatalogUatPlaceholderPhotoController` | Foto sintética de productos de prueba UAT (pública, con throttle). |
| `CatalogUatTestDataCleanupController` | Borra productos y familias de prueba UAT. |
| `ReportsController`, `ReportsConfigController` | Reportes y su configuración (metas, parámetros). |
| `CommissionsController` | Panel Comisiones y marcar pagada. |
| `SalesCommissionTierController` | Tramos de comisión (solo superadmin). |
| `PricingSettingsController` | Parámetros de Tipo de Precio y margen mínimo. |
| `OrdersController`, `OrdersSettingsController` | Pedidos y su umbral de margen. |
| `ProjectsListController` | Lista de Proyectos (paginada, exporta CSV). |
| `DashboardController` | Dashboard CRM; las rutas exigen rol `superadmin` o `management` además del permiso. |
| `HomeController` | Home del módulo. |
| `CalendarEventController` | CRUD de eventos de calendario (hoy sin uso en el frontend). |
| `AuditLogController` | Bitácora acotada a `ventas_diseno.%`. |

Dos rutas del prefijo viven en **otros módulos**: `/ventas-diseno/calendar` → `OutlookCalendarController` (`app/Modules/Integrations/Outlook/`) y `/ventas-diseno/sub-clients/{subClientId}/legacy-history` → `SubClientHistoryController` (`app/Modules/Legacy/`).

**Form Requests** (`Http/Requests/`): `QuoteRequest`, `QuoteItemRequest`, `StoreQuotePartRequest`, `StorePipelineCardRequest`, `UpdatePipelineCardRequest`, `StorePipelineCardContactRequest`, `UpdateProductDeliveryRequest`, `StoreMasterClientRequest`, `StoreClientRequest`, `StoreClientSubClientRequest`, `StoreSubClientRequest`, `StoreSubClientContactRequest`, `UpdateSubClientCategoryRequest`, `UpdateSubClientTaxDataRequest`, `ValidateTaxIdRequest`, `StoreSalesProjectRequest`, `StoreArchitectRequest`, `UpdateArchitectRequest`, `StoreCalendarEventRequest`, `StoreCatalogTechnicalSheetRequest`, `SendCatalogPdfRequest`, `PricingSettingsRequest`, `OrdersSettingsRequest`, `QuoteConditionsSettingsRequest`, `ReportSettingsRequest`, `SalesGoalRequest`, `ReplaceSalesCommissionTiersRequest`.

**Modelos** (`Models/`, 29): `PipelineCard`, `PipelineCardContact`, `PipelineCardDeliveryDate`, `PipelineCardFile`, `PipelineCardProductDelivery`, `Quote`, `QuotePart`, `QuoteItem`, `QuoteDeliveryDate`, `QuoteFolioCounter`, `QuoteConditionsSettings`, `MasterClient`, `SubClient`, `SubClientContact`, `SalesProject`, `Architect`, `CatalogProduct`, `CatalogProductFamily`, `CatalogPdfExport`, `PricingSettings`, `OrdersSettings`, `ReportSettings`, `SalesGoal`, `SalesCommissionTier`, `CommissionCohort`, `CalendarEvent`, `CotizaYaSettings`, `CotizaYaFolioCounter` y el filtro global `CotizaYaOriginScope` (en `Models/Scopes/`).

**Servicios** (`Services/`, 30)

| Servicio | Para qué |
|---|---|
| `PipelineService` | Crear, editar, cambiar etapa, "Pasar a Pedido", listar con alcance Mías/Equipo. |
| `PipelineFileService` | Archivos de la tarjeta en `s3-private`, URL firmada de 15 min, retención permanente. |
| `ProductDeliveryService` | Asignación de productos a fechas de entrega y su validación al aprobar. |
| `ProjectAssociateService` | Asociados de proyecto y cotización: una sola fuente de verdad. |
| `AssociateDuplicateFinder` | Detecta asociados duplicados antes de crear. |
| `QuoteService` | Ciclo de vida de la cotización (650 líneas). |
| `QuoteFolioService` | Folio `COT-AAAA-NNNN` atómico. |
| `QuoteListService` | Listado de Cotizaciones y proyectos aprobados. |
| `QuoteContactCardSyncService` | Copia contactos de la cotización a la tarjeta (solo agrega). |
| `QuoteItemPhotoService` | Foto de ítems ad-hoc (redimensionada con GD). |
| `QuotePdfService`, `QuotePdfLayoutEstimator` | PDF de la cotización con dompdf y estimador de paginación. |
| `CotizaYaService`, `CotizaYaFolioService`, `CotizaYaClientGuard` | CotizaYA: creación, folio `COTY-AAAA-NNNN` y guarda del cliente fijo. |
| `ClientsService` | Pantalla Clientes y regla de Lead automático. |
| `SubClientTaxDataService` | Edición de razón social, RUC y DV con bloqueo por documentos fiscales. |
| `CatalogService`, `CatalogPdfService`, `CatalogPdfExportService` | Catálogo, PDF y exportación por volúmenes. |
| `FuzzySearchService` | Búsqueda aproximada (la usa el listado de Cotizaciones; el buscador de Catálogo ya no). |
| `ReportsService`, `HomeService`, `DashboardService`, `OrdersService`, `ProjectsListService` | Lectura agregada para cada pantalla. |
| `CommissionService`, `SalesTotalsService` | Comisión escalonada de vendedores. |
| `OrphanQuoteCleanupService`, `TestDataCleanupService` | Limpieza de datos (solo bajo comando, con dry-run). |

**Otros:** `Jobs/GenerateCatalogPdfVolumesJob`, `Events/QuoteConfirmed`, `Events/QuoteGenerated`, `Observers/CotizaYaClientExclusivityObserver`, `Http/Middleware/EnsureCotizaYaContext` (alias `cotizaya.context`), `Exceptions/` (5, sección 5.8) y `Support/` (`CommissionTierRules`, `CotizaYaContext`, `CotizaYaSchema`, `ReceptorTipo`, `SandboxFiscalFixtures`).

### 2.2 Frontend (`atlanticerp-frontend/src/`)

Páginas en `pages/ventas-diseno/` (sección 8), cliente HTTP `api/ventasDisenoApi.ts`, tipos `types/ventasDiseno.ts`. Más páginas de Lista de Proyectos y Dashboard en `pages/crm/`.

---

## 3. Modelo de datos

Esquema PostgreSQL `{tenant}_ventas_diseno`. El diccionario de columnas está en `docs/architecture/erd/ventas-diseno.md` (34 tablas); aquí solo lo que el ERD no explica.

| Tabla | Para qué |
|---|---|
| `master_clients` → `sub_clients` | Jerarquía de clientes. `sub_clients` lleva `tax_id`, `tax_id_dv`, `receptor_tipo` (`01` contribuyente, `02` consumidor final), `category`. |
| `sub_client_contacts` | Directorio compartido de contactos por subcliente. |
| `sales_projects` | Proyecto de un subcliente; lo usan Pipeline y Cotización. |
| `pipeline_cards` | Tarjeta: `stage`, `stage_changed_at`, `owner_id`, `compartido_con` (JSON de ids), `amount`, `worked_area_m2`, `delivery_type`, `order_approved_by/at`, `created_by`. |
| `pipeline_card_contacts`, `pipeline_card_delivery_dates`, `pipeline_card_files`, `pipeline_card_product_deliveries` | Hijas de la tarjeta. Los archivos tienen tipo `design`, `proposal`, `signed_quote`, `approval_proof` o `photo`. |
| `quotes` | Cotización: `folio` (nulo hasta generar), `generated_at`, `confirmed_at`, `origin` (`comercial` o `cotizaya`), `price_type`, `discount_mode`, `architect_id` (el que origina la comisión de Admin & Contabilidad), condiciones congeladas. |
| `quote_parts`, `quote_items` | Partidas e ítems. El ítem copia `unit_price` y `cost` del catálogo al crearse. |
| `quote_delivery_dates`, `quote_contacts` | Fechas de entrega y pivote de contactos. |
| `quote_folio_counters`, `cotizaya_folio_counters` | Contador por año, bloqueado con `lockForUpdate()`. |
| `architects` | Base única de asociados. Pivotes `sales_project_associates` (del proyecto) y `quote_associates` (borrador de cotización sin proyecto). |
| `catalog_products`, `catalog_product_providers`, `catalog_product_families`, `catalog_product_family_items`, `catalog_pdf_exports`, `lafayette_sync_runs` | Catálogo, proveedores, familias, exportaciones y corridas de sincronización con Lafayette. |
| `pricing_settings`, `orders_settings`, `report_settings`, `quote_conditions_settings`, `cotizaya_settings` | **Filas únicas** (`id = 1`) con parámetros. |
| `sales_goals`, `sales_commission_tiers`, `commission_cohorts` | Metas por vendedor, tramos de comisión y cohorte mensual cerrada. |
| `calendar_events` | Eventos nativos (sin uso en pantalla). |

**Invariantes que el código asume:** el folio nunca se reasigna; una cotización con `confirmed_at` no se edita; una tarjeta `lost` no se edita; el cliente CotizaYA no se asocia a nada comercial.

---

## 4. Reglas de negocio y parámetros

### 4.1 Etapas del Pipeline (`PipelineCard::validationErrorForTransitionTo`)

El flujo es **lineal**: `lead → design → quote → proposal → approved`. No se saltan etapas. `lost` se alcanza desde cualquier etapa activa menos `approved`, y desde `lost` solo se vuelve a `lead`.

| Transición | Requisito |
|---|---|
| Lead → Diseño | Al menos 1 contacto, Cliente Master y Subcliente vinculados. |
| Diseño → Cotización | **Solo** generando una cotización real ("Crear cotización"); el endpoint genérico de etapa la rechaza. |
| Cotización → Propuesta | Superficie trabajada (m²). |
| Propuesta → Aprobado | Al menos 1 fecha de entrega, archivo `signed_quote` y `approval_proof`. |
| Cualquiera → Perdido | Sin gate, salvo desde Aprobado (etapa final). |

`isEditableBy`: `lost` nunca; exige `ventas_diseno.edit` (o superadmin); en `approved` exige además `ventas_diseno.edit.approved`. `STAGNANT_THRESHOLD_DAYS = 15` marca una tarjeta estancada.

### 4.2 Cotización

- **Estado del documento** (`Quote::documentStatus`): `draft` (sin `confirmed_at`), `sent` (confirmada) y `approved` (confirmada y la tarjeta está en Propuesta o Aprobado).
- **Generar** (`QuoteService::generate`) valida: Cliente Master, Subcliente, RUC/Cédula, Proyecto, Descripción, Contacto, fecha de entrega (salvo `delivery_type = tbd`), ítems y margen. Asigna folio solo la primera vez y dispara `QuoteGenerated`.
- **Confirmar** (`confirm`) exige folio; marca `confirmed_at`, copia los contactos a la tarjeta, mueve la tarjeta a Cotización y dispara `QuoteConfirmed` (distingue "primera confirmación" de "nueva versión").
- **Duplicar** (`duplicate`) crea una nueva versión solo desde cotizaciones confirmadas y con la tarjeta en Lead, Diseño o Cotización; congela las Condiciones vigentes en ese momento.
- **Tipos de precio** (`QuoteItem::effectiveUnitPrice`): `public` (precio de lista), `project` (lista menos descuento de proyecto), `special` (además descuento adicional), `partner` (`costo / partner_divisor`), `premium` (`costo / premium_divisor`). Sin costo, `partner` y `premium` caen al precio de lista. Un ítem puede tener su propio tipo (`price_type_override`), pero `QuoteItemRequest` solo acepta `public`, `project`, `special` y `premium` (no `partner`; la cotización entera sí puede ser `partner`).
- **Descuento:** `discount_mode` es `line` o `global`.
- **Margen mínimo:** `QuoteItem::violatesMinMargin` compara contra `pricing_settings.min_margin_percent`; quien tenga el permiso `ventas_diseno.override_min_margin` (Mark y David, SCRUM-725) puede generar por debajo del mínimo (`QuoteController::hasMarginOverride`), con advertencia en pantalla (`MarginOverrideWarningModal`).
- **ITBMS:** sale de `ItbmsRateResolver` (Configuración Fiscal de Admin & Contabilidad), ya no es 7 % fijo.

### 4.3 Parámetros configurables y valores sembrados

| Tabla | Campo | Valor inicial |
|---|---|---|
| `pricing_settings` | `project_discount_percent` | 15 |
| | `special_additional_discount_percent` | 5 |
| | `partner_divisor` | 0.85 |
| | `premium_divisor` | 0.65 |
| | `min_margin_percent` | 30 |
| `orders_settings` | `margin_drop_threshold_pts` | 5.00 |
| `report_settings` | `low_sales_threshold` / `low_sales_months` | 20000 / 3 |
| | `quote_probability_percent` / `proposal_probability_percent` | 40 / 70 |
| `sales_commission_tiers` | 0–19 999.99 → 1.5 %; 20 000–34 999.99 → 2 %; 35 000–59 999.99 → 3 %; 60 000–79 999.99 → 4 %; 80 000–119 999.99 → 4.5 %; desde 120 000 → 5 % | |

`CommissionTierRules` valida tramos en centavos enteros: `overlapErrors` (solapamientos, lo mínimo) y `fullSetErrors` (cobertura completa desde 0, sin huecos, un único tramo final abierto). El reemplazo atómico exige el conjunto completo.

### 4.4 Comisión de vendedores

`SalesTotalsService::monthlyApprovedTotal` suma lo Aprobado del mes por vendedor; una tarjeta compartida (`owner_id` + `compartido_con`) reparte su monto en partes iguales. `percentFor` busca el tramo. `CommissionService::cohortFor` crea la cohorte del mes y `markPaid` la marca pagada (permiso `ventas_diseno.commissions.mark_paid`).

### 4.5 Pedidos (`OrdersService::classifyStatus`)

`pendiente` (sin ítems facturados), `igual` (sin variación de margen), `bajo` (el margen facturado cae más que el umbral) o `mejoro`.

### 4.6 Datos fiscales

`ReceptorTipo` permite `01` y `02`. Con `02` no hay RUC ni DV (`tax_id` se guarda vacío). `SubClientTaxDataService::update` se bloquea (409) si el subcliente tiene facturas o notas de crédito `certificado` o `pendiente`.

---

## 5. Flujos principales

### 5.1 Lead a Pedido
1. `POST /pipeline` crea la tarjeta (tipo `lead` o `design`); también nace un Lead automático al crear un cliente desde la pantalla Clientes (REQ-068 en `ClientsService`; el flujo de Cotización lo omite con `$skipLeadCard`, SCRUM-873).
2. Se vinculan cliente, contactos y asociados; se avanza etapa con `PATCH /pipeline/{id}/stage`.
3. "Crear cotización" genera la cotización y mueve la tarjeta a Cotización.
4. Archivos de propuesta/firma/aprobación y fechas de entrega habilitan Aprobado.
5. `POST /pipeline/{id}/pass-to-order` (`PipelineService::passToOrder`): bloquea la fila, exige etapa `approved`, rechaza si ya se pasó (`AlreadyPassedToOrderException`, 409), llama a `OrderService::createFromApprovedQuote` de Bodega y marca `order_approved_by/at`.

### 5.2 Cotización
`POST /quotes` → partidas (`parts`) → ítems (individual, `bulk` desde catálogo o personalizado con foto) → `save-draft` (exige campos obligatorios, 422 con la lista de faltantes) → `generate` → vista previa y `pdf` → `confirm`.

### 5.3 CotizaYA
El middleware `cotizaya.context` activa `CotizaYaContext`; sin él, el filtro `CotizaYaOriginScope` oculta toda cotización `origin = cotizaya`. `CotizaYaController::store` crea el borrador con el cliente fijo, `generate` asigna folio `COTY-AAAA-NNNN`. Nunca crea Lead, proyecto ni tarjeta ni llama a `confirm`. `CotizaYaClientExclusivityObserver` responde 422 si algo del flujo comercial intenta asociar el cliente CotizaYA. `CotizaYaSchema` desactiva filtro y observer mientras el esquema aún no tiene la migración (ventana entre `up -d --build` y `migrate`).

### 5.4 Asociados
Una cotización con proyecto lee y escribe los asociados del proyecto; sin proyecto guarda los suyos (`quote_associates`) y los traslada al asignar proyecto (`mergeDraftIntoProject`). No se puede quitar a quien es el "Arquitecto" de una cotización (`AssociateInUseException`).

### 5.5 Contactos cotización → tarjeta
`QuoteContactCardSyncService` solo agrega. Un contacto "ya está" si coincide por correo, teléfono (dígitos) o nombre + rol sin acentos.

### 5.6 Catálogo PDF
`CatalogController::sendPdf` llama a `CatalogPdfExportService`, que encola `GenerateCatalogPdfVolumesJob` (conexión `redis-long`, cola `pdf`, un solo intento). El estado vive en `catalog_pdf_exports` (`queued → running → done | failed`) y se consulta en `GET /catalog/pdf-exports/{uuid}` (solo el solicitante o superadmin).

### 5.7 Validar RUC
`POST /clients/validate-tax-id` (throttle `tax-lookup`) llama a `validarReceptor` del adaptador MSL. Nunca responde 5xx por falla del PAC: devuelve `disponible: false` con `codigo`.

### 5.8 Excepciones de dominio
`AlreadyPassedToOrderException` (409), `AssociateInUseException`, `QuoteActionBlockedException` (un mensaje humano), `QuoteValidationBlockedException` (lista de faltantes, 422, con rollback) y `SubClientTaxDataLockedException` (409).

---

## 6. API

Prefijo `/api`. Permisos en OR. Tabla generada con `scripts/manual-routes-table.py ventas-diseno` desde `route:list` el 2026-10-12 (125 rutas). Validaciones: el `Form Request` del mismo nombre que la acción, ver sección 2.1.

**`/ventas-diseno/architects`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/architects` | `ArchitectController@index` | `ventas_diseno.read` |
| POST | `/ventas-diseno/architects` | `ArchitectController@store` | `ventas_diseno.write` |
| GET | `/ventas-diseno/architects/{id}` | `ArchitectController@show` | `ventas_diseno.read` |
| PUT | `/ventas-diseno/architects/{id}` | `ArchitectController@update` | `ventas_diseno.edit` |

**`/ventas-diseno/audit-log`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/audit-log` | `AuditLogController@index` | `ventas_diseno.audit` |

**`/ventas-diseno/calendar`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/calendar` | `OutlookCalendarController@index` | `ventas_diseno.read` |

**`/ventas-diseno/calendar-events`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/calendar-events` | `CalendarEventController@index` | `ventas_diseno.read` |
| POST | `/ventas-diseno/calendar-events` | `CalendarEventController@store` | `ventas_diseno.write` |
| PUT | `/ventas-diseno/calendar-events/{id}` | `CalendarEventController@update` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/calendar-events/{id}` | `CalendarEventController@destroy` | `ventas_diseno.edit` |

**`/ventas-diseno/catalog`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/catalog` | `CatalogController@index` | `ventas_diseno.read` |
| GET | `/ventas-diseno/catalog/pdf-exports/{uuid}` | `CatalogController@pdfExport` | `ventas_diseno.read` |
| POST | `/ventas-diseno/catalog/send-pdf` | `CatalogController@sendPdf` | `ventas_diseno.read` |
| GET | `/ventas-diseno/catalog/{id}` | `CatalogController@show` | `ventas_diseno.read` |
| GET | `/ventas-diseno/catalog/{id}/technical-sheet` | `CatalogController@technicalSheetUrl` | `ventas_diseno.read` |
| POST | `/ventas-diseno/catalog/{id}/technical-sheet` | `CatalogController@uploadTechnicalSheet` | `ventas_diseno.read` |

**`/ventas-diseno/catalog-product-families`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/catalog-product-families` | `CatalogProductFamilyController@index` | `ventas_diseno.read` |
| GET | `/ventas-diseno/catalog-product-families/{id}` | `CatalogProductFamilyController@show` | `ventas_diseno.read` |

**`/ventas-diseno/catalog-products`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/catalog-products` | `CatalogProductController@index` | `ventas_diseno.read` |
| GET | `/ventas-diseno/catalog-products/{id}` | `CatalogProductController@show` | `ventas_diseno.read` |

**`/ventas-diseno/clients`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/clients` | `ClientsController@index` | `ventas_diseno.read` |
| POST | `/ventas-diseno/clients` | `ClientsController@store` | `ventas_diseno.write` |
| GET | `/ventas-diseno/clients/fiscal-directory` | `ClientTaxDataController@directory` | `ventas_diseno.edit,ventas_diseno.edit.client_tax_data` |
| PATCH | `/ventas-diseno/clients/sub-clients/{subClientId}/category` | `ClientsController@updateSubClientCategory` | `ventas_diseno.edit` |
| PATCH | `/ventas-diseno/clients/sub-clients/{subClientId}/tax-data` | `ClientTaxDataController@updateTaxData` | `ventas_diseno.edit,ventas_diseno.edit.client_tax_data` |
| POST | `/ventas-diseno/clients/validate-tax-id` | `ClientTaxDataController@validateTaxId` | `ventas_diseno.write,ventas_diseno.edit.client_tax_data · throttle:tax-lookup` |
| GET | `/ventas-diseno/clients/{id}` | `ClientsController@show` | `ventas_diseno.read` |
| GET | `/ventas-diseno/clients/{id}/projects` | `ClientsController@projects` | `ventas_diseno.read` |
| POST | `/ventas-diseno/clients/{id}/sub-clients` | `ClientsController@storeSubClient` | `ventas_diseno.write` |

**`/ventas-diseno/cotizaya`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/cotizaya` | `CotizaYaController@index` | `ventas_diseno.read` |
| POST | `/ventas-diseno/cotizaya` | `CotizaYaController@store` | `ventas_diseno.write` |
| GET | `/ventas-diseno/cotizaya/context` | `CotizaYaController@context` | `ventas_diseno.read` |
| GET | `/ventas-diseno/cotizaya/{id}` | `CotizaYaController@show` | `ventas_diseno.read` |
| PUT | `/ventas-diseno/cotizaya/{id}` | `CotizaYaController@update` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/cotizaya/{id}/generate` | `CotizaYaController@generate` | `ventas_diseno.edit` |
| GET | `/ventas-diseno/cotizaya/{id}/pdf` | `CotizaYaController@pdf` | `ventas_diseno.read` |
| POST | `/ventas-diseno/cotizaya/{quoteId}/contacts` | `QuoteContactController@store` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/cotizaya/{quoteId}/contacts/{contactId}` | `QuoteContactController@destroy` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/cotizaya/{quoteId}/parts` | `QuotePartController@store` | `ventas_diseno.edit` |
| PUT | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}` | `QuotePartController@update` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}` | `QuotePartController@destroy` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}/duplicate` | `QuotePartController@duplicate` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}/items` | `QuoteItemController@store` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}/items/bulk` | `QuoteItemController@bulkStore` | `ventas_diseno.edit` |
| PUT | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}/items/{itemId}` | `QuoteItemController@update` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}/items/{itemId}` | `QuoteItemController@destroy` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}/items/{itemId}/photo` | `QuoteItemController@uploadPhoto` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/cotizaya/{quoteId}/parts/{partId}/items/{itemId}/preview-price` | `QuoteItemController@previewPrice` | `ventas_diseno.edit` |

**`/ventas-diseno/dashboard`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| POST | `/ventas-diseno/dashboard/remind` | `DashboardController@remind` | `ventas_diseno.read` |
| GET | `/ventas-diseno/dashboard/summary` | `DashboardController@summary` | `ventas_diseno.read` |

**`/ventas-diseno/home`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/home/approvals-pending` | `HomeController@approvalsPending` | `ventas_diseno.read` |
| GET | `/ventas-diseno/home/summary` | `HomeController@summary` | `ventas_diseno.read` |
| GET | `/ventas-diseno/home/vendors` | `HomeController@vendors` | `ventas_diseno.read` |

**`/ventas-diseno/master-clients`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/master-clients` | `ClientController@masterClients` | `ventas_diseno.read` |
| POST | `/ventas-diseno/master-clients` | `ClientController@storeMasterClient` | `ventas_diseno.write` |

**`/ventas-diseno/orders`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/orders` | `OrdersController@index` | `role:lider_compras,management,superadmin` |
| GET | `/ventas-diseno/orders/{id}` | `OrdersController@show` | `role:lider_compras,management,superadmin` |

**`/ventas-diseno/orders-settings`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/orders-settings` | `OrdersSettingsController@show` | `ventas_diseno.pricing.configure` |
| PUT | `/ventas-diseno/orders-settings` | `OrdersSettingsController@update` | `ventas_diseno.pricing.configure` |

**`/ventas-diseno/pipeline`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/pipeline` | `PipelineController@index` | `ventas_diseno.read,ventas_diseno.pipeline.read_all` |
| POST | `/ventas-diseno/pipeline` | `PipelineController@store` | `ventas_diseno.write` |
| POST | `/ventas-diseno/pipeline/{cardId}/associates` | `AssociateLinkController@storeForCard` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/pipeline/{cardId}/associates/{architectId}` | `AssociateLinkController@destroyForCard` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/pipeline/{cardId}/contacts` | `PipelineCardContactController@store` | `ventas_diseno.edit` |
| PUT | `/ventas-diseno/pipeline/{cardId}/contacts/{contactId}` | `PipelineCardContactController@update` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/pipeline/{cardId}/contacts/{contactId}` | `PipelineCardContactController@destroy` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/pipeline/{cardId}/files/{fileId}` | `PipelineController@deleteFile` | `ventas_diseno.edit` |
| GET | `/ventas-diseno/pipeline/{cardId}/files/{fileId}/url` | `PipelineController@fileUrl` | `ventas_diseno.read,ventas_diseno.pipeline.read_all` |
| GET | `/ventas-diseno/pipeline/{cardId}/product-delivery-assignments` | `PipelineCardProductDeliveryController@index` | `ventas_diseno.read` |
| PUT | `/ventas-diseno/pipeline/{cardId}/product-delivery-assignments` | `PipelineCardProductDeliveryController@update` | `ventas_diseno.edit` |
| GET | `/ventas-diseno/pipeline/{id}` | `PipelineController@show` | `ventas_diseno.read,ventas_diseno.pipeline.read_all` |
| PUT | `/ventas-diseno/pipeline/{id}` | `PipelineController@update` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/pipeline/{id}/files` | `PipelineController@uploadFile` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/pipeline/{id}/pass-to-order` | `PipelineController@passToOrder` | `ventas_diseno.pass_to_order` |
| PATCH | `/ventas-diseno/pipeline/{id}/stage` | `PipelineController@changeStage` | `ventas_diseno.edit` |

**`/ventas-diseno/pricing-settings`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/pricing-settings` | `PricingSettingsController@show` | `ventas_diseno.pricing.configure` |
| PUT | `/ventas-diseno/pricing-settings` | `PricingSettingsController@update` | `ventas_diseno.pricing.configure` |

**`/ventas-diseno/projects`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/projects` | `ProjectsListController@index` | `ventas_diseno.read` |
| GET | `/ventas-diseno/projects/export` | `ProjectsListController@export` | `ventas_diseno.read` |

**`/ventas-diseno/quote-conditions-settings`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/quote-conditions-settings` | `QuoteConditionsSettingsController@show` | `ventas_diseno.edit.conditions` |
| PUT | `/ventas-diseno/quote-conditions-settings` | `QuoteConditionsSettingsController@update` | `ventas_diseno.edit.conditions` |

**`/ventas-diseno/quotes`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/quotes` | `QuoteListController@index` | `ventas_diseno.read` |
| POST | `/ventas-diseno/quotes` | `QuoteController@store` | `ventas_diseno.write` |
| GET | `/ventas-diseno/quotes/{id}` | `QuoteController@show` | `(la comprueba el controlador)` |
| PUT | `/ventas-diseno/quotes/{id}` | `QuoteController@update` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{id}/confirm` | `QuoteController@confirm` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{id}/duplicate` | `QuoteController@duplicate` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{id}/generate` | `QuoteController@generate` | `ventas_diseno.edit` |
| GET | `/ventas-diseno/quotes/{id}/pdf` | `QuoteController@pdf` | `(la comprueba el controlador)` |
| POST | `/ventas-diseno/quotes/{id}/save-draft` | `QuoteController@saveDraft` | `ventas_diseno.edit` |
| GET | `/ventas-diseno/quotes/{id}/validate` | `QuoteController@validateForGenerating` | `ventas_diseno.read` |
| GET | `/ventas-diseno/quotes/{id}/versions` | `QuoteController@versions` | `ventas_diseno.read` |
| POST | `/ventas-diseno/quotes/{quoteId}/associates` | `AssociateLinkController@storeForQuote` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/quotes/{quoteId}/associates/{architectId}` | `AssociateLinkController@destroyForQuote` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{quoteId}/contacts` | `QuoteContactController@store` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/quotes/{quoteId}/contacts/{contactId}` | `QuoteContactController@destroy` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{quoteId}/parts` | `QuotePartController@store` | `ventas_diseno.edit` |
| PUT | `/ventas-diseno/quotes/{quoteId}/parts/{partId}` | `QuotePartController@update` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/quotes/{quoteId}/parts/{partId}` | `QuotePartController@destroy` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{quoteId}/parts/{partId}/duplicate` | `QuotePartController@duplicate` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{quoteId}/parts/{partId}/items` | `QuoteItemController@store` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{quoteId}/parts/{partId}/items/bulk` | `QuoteItemController@bulkStore` | `ventas_diseno.edit` |
| PUT | `/ventas-diseno/quotes/{quoteId}/parts/{partId}/items/{itemId}` | `QuoteItemController@update` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/quotes/{quoteId}/parts/{partId}/items/{itemId}` | `QuoteItemController@destroy` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{quoteId}/parts/{partId}/items/{itemId}/photo` | `QuoteItemController@uploadPhoto` | `ventas_diseno.edit` |
| POST | `/ventas-diseno/quotes/{quoteId}/parts/{partId}/items/{itemId}/preview-price` | `QuoteItemController@previewPrice` | `ventas_diseno.edit` |

**`/ventas-diseno/reports`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/reports/commissions` | `CommissionsController@summary` | `ventas_diseno.read` |
| PATCH | `/ventas-diseno/reports/commissions/{cohortId}/mark-paid` | `CommissionsController@markPaid` | `ventas_diseno.commissions.mark_paid` |
| GET | `/ventas-diseno/reports/goals` | `ReportsConfigController@goalsIndex` | `ventas_diseno.reports.configure` |
| PUT | `/ventas-diseno/reports/goals/{userId}` | `ReportsConfigController@goalsUpsert` | `ventas_diseno.reports.configure` |
| GET | `/ventas-diseno/reports/settings` | `ReportsConfigController@settingsShow` | `ventas_diseno.read` |
| PUT | `/ventas-diseno/reports/settings` | `ReportsConfigController@settingsUpdate` | `ventas_diseno.reports.configure` |
| GET | `/ventas-diseno/reports/summary` | `ReportsController@summary` | `ventas_diseno.read` |

**`/ventas-diseno/sales-commission-tiers`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/sales-commission-tiers` | `SalesCommissionTierController@index` | `superadmin.all` |
| PUT | `/ventas-diseno/sales-commission-tiers` | `SalesCommissionTierController@replace` | `superadmin.all` |

**`/ventas-diseno/sales-projects`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/sales-projects` | `SalesProjectController@index` | `ventas_diseno.read` |
| POST | `/ventas-diseno/sales-projects` | `SalesProjectController@store` | `ventas_diseno.write` |

**`/ventas-diseno/sub-clients`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/ventas-diseno/sub-clients` | `ClientController@subClients` | `ventas_diseno.read` |
| POST | `/ventas-diseno/sub-clients` | `ClientController@storeSubClient` | `ventas_diseno.write` |
| GET | `/ventas-diseno/sub-clients/{subClientId}/contacts` | `SubClientContactController@index` | `ventas_diseno.read` |
| POST | `/ventas-diseno/sub-clients/{subClientId}/contacts` | `SubClientContactController@store` | `ventas_diseno.edit` |
| PUT | `/ventas-diseno/sub-clients/{subClientId}/contacts/{contactId}` | `SubClientContactController@update` | `ventas_diseno.edit` |
| DELETE | `/ventas-diseno/sub-clients/{subClientId}/contacts/{contactId}` | `SubClientContactController@destroy` | `ventas_diseno.edit` |
| GET | `/ventas-diseno/sub-clients/{subClientId}/legacy-history` | `SubClientHistoryController@index` | `ventas_diseno.read` |
| GET | `/ventas-diseno/sub-clients/{subClientId}/legacy-history/{entidad}/{id}` | `SubClientHistoryController@show` | `ventas_diseno.read` |

Rutas de este módulo fuera del prefijo: `GET /public/catalog/uat-placeholder/{reference}` (`CatalogUatPlaceholderPhotoController`, definida en `bootstrap/app.php`, sin autenticación, con throttle) y `POST /admin/catalog/uat-test-data/cleanup` (`CatalogUatTestDataCleanupController`, `routes/admin.php`, `superadmin.all`).

---

## 7. Permisos, roles y acceso

| Permiso | Qué habilita |
|---|---|
| `ventas_diseno.read` | Leer todo el módulo. |
| `ventas_diseno.write` | Crear tarjetas, cotizaciones, clientes. |
| `ventas_diseno.edit` | Editar, mover etapa, subir archivos. |
| `ventas_diseno.override_min_margin` | Generar cotizaciones por debajo del margen mínimo. |
| `ventas_diseno.edit.approved` | Editar una tarjeta Aprobada (Mark). |
| `ventas_diseno.pipeline.read_all` | Ver el Pipeline de todo el equipo, solo lectura (Félix). |
| `ventas_diseno.pass_to_order` | "Pasar a Pedido". |
| `ventas_diseno.pricing.configure` | Tipos de precio, margen mínimo y umbral de Pedidos. |
| `ventas_diseno.edit.conditions` | Texto global de Condiciones. |
| `ventas_diseno.edit.client_tax_data` | Editar datos fiscales fuera de Ventas (Admin & Contabilidad). |
| `ventas_diseno.commissions.mark_paid` | Marcar comisión pagada. |
| `ventas_diseno.reports.configure` | Metas y parámetros de Reportes. |
| `ventas_diseno.audit` | Bitácora. |
| `superadmin.all` | Tramos de comisión y todo lo demás. |

**Alcance Mías/Equipo:** un Diseñador solo ve lo propio; Gerencia y superadmin ven el equipo. **Pedidos** se gatea por **rol**, no por permiso (SCRUM-1119). **Cotización** `show` y `pdf` las autoriza el controlador (`canAccess`, más lectura desde Admin & Contabilidad). Los permisos nuevos y el puente al vocabulario viejo viven en `JwtClaimsBuilder` y `RoleModuleVisibility` (ver CLAUDE.md del proyecto, ADR-006).

---

## 8. Frontend

Rutas en `App.tsx`:

| Ruta | Página | Guarda |
|---|---|---|
| `/ventas-diseno/home` | `HomePage` | `ventas_diseno.read` |
| `/ventas-diseno/pipeline` | `PipelinePage` | `read` o `pipeline.read_all` |
| `/ventas-diseno/clients` | `ClientsPage` | `read` |
| `/ventas-diseno/architects` | `ArchitectsPage` | `read` |
| `/ventas-diseno/reports` | `ReportsPage` | `read` |
| `/ventas-diseno/quotes-list` | `QuotesListPage` | `read` |
| `/ventas-diseno/quotes/:id?` | `QuotePage` | `write` |
| `/ventas-diseno/pedidos` | `PedidosPage` | rol Compras, Gerencia, superadmin |
| `/ventas-diseno/cotizaya`, `/nueva`, `/:id` | `CotizaYaPage`, `CotizaYaFormPage`, `CotizaYaDetailPage` | read / write / read |
| `/ventas-diseno/audit-log` | `AuditLogPage` | `ventas_diseno.audit` |
| `/ventas-diseno/catalog` | `CatalogPage` | `read` |
| `/crm/dashboard`, `/crm/projects` | Dashboard y Lista de Proyectos (`pages/crm/`) | `read` (+ rol en el dashboard) |

Componentes principales (`src/components/`): `PipelineCardModal` (detalle de la tarjeta), `PipelineFileViewerModal`, `ProductDeliveryAssignmentModal`, `NewProjectModal`, `QuotePartCard` (partidas e ítems, con `QuotePartsApiContext` para reutilizarlo en CotizaYA), `QuoteViewerModal`, `SaveQuoteConfirmModal`, `MarginOverrideWarningModal`, `ClientPicker`, `CreateClientModal`, `ClientDetailModal`, `ClientProjectsModal`, `EditSubClientTaxDataModal`, `ReceptorTipoSelect`, `TaxIdValidator`, `SubClientIcgHistoryModal`, `ArchitectDetailModal`, `PedidoDetailModal`, `CalendarModal`, `MiniCalendarCard`, `HomeItemDetailModal`, `ApprovalsPendingCard`, `CommissionsPanel`, `ReportsConfigPanel` y los paneles de configuración `PricingSettingsPanel`, `OrdersSettingsPanel`, `ConditionsSettingsPanel`, `SalesCommissionTiersPanel` (estos cuatro se montan en la pantalla de configuración global de superadmin).

`pages/ventas-diseno/cotizaYaPdf.ts` descarga el PDF de CotizaYA. Todas las llamadas HTTP salen de `api/ventasDisenoApi.ts`; los tipos, de `types/ventasDiseno.ts`.

---

## 9. Eventos, jobs e integraciones

| Elemento | Detalle |
|---|---|
| `QuoteGenerated` (`ventasdiseno.quote.generated`) | Al asignar folio. **Sin listener.** |
| `QuoteConfirmed` (`ventasdiseno.quote.confirmed`) | Al confirmar. **Sin listener.** |
| `GenerateCatalogPdfVolumesJob` | Cola `pdf`, conexión `redis-long`, 1 intento; activa el tenant por slug. |
| Bodega | `OrderService::createFromApprovedQuote` crea el pedido al "Pasar a Pedido". |
| MSL / DGI | `validarReceptor` para el RUC. |
| Outlook | `/ventas-diseno/calendar` lee la agenda del usuario. |
| S3 | Archivos de tarjeta (`s3-private`), ficha técnica y fotos. |
| Lafayette | Sincronización del catálogo (`lafayette_sync_runs`); la vista no expone `precio_6` (SCRUM-1125). |
| Admin & Contabilidad | `ItbmsRateResolver`, `quotes.architect_id` para comisiones externas. |
| Auditoría | Trait `LogsAuditTrail` en los modelos principales, acciones `ventas_diseno.*`. |

---

## 10. Configuración y datos semilla

- **Seeders:** `VentasDisenoDemoSeeder`, `VentasDisenoSalesGoalsSeeder`, `CatalogProductSeeder`, `CatalogProductFamilySeeder`, `CatalogUatTestDataSeeder`, `Scrum831InventoryTestDataSeeder`, `Scrum845InventoryTestDataSeeder`, `Scrum711SalesForceDemoSeeder` y `QaTicketsBatch20260725Seeder`. Los de demo y UAT solo en `local`, `dev` y `testing`. Las filas únicas de parámetros se siembran en sus migraciones.
- **Comandos:** `catalog-pdf:purge-exports` (agendado diario 04:00, borra exportaciones viejas del Catálogo), `ventas-diseno:cleanup-orphan-quotes` (dry-run por defecto, `--force` para borrar; huérfanas = sin folio y sin ítems) y `ventas_diseno:backfill-pipeline-card-created-by`.
- **Datos fiscales de demo:** `SandboxFiscalFixtures` guarda RUC que el sandbox de MSL sí conoce; la guarda de RUC al facturar (SCRUM-1117) bloquea los demás.
- **Variables:** las de S3, Redis (`redis-long`) y MSL del `.env` general.

---

## 11. Pruebas

`tests/Feature/VentasDiseno/` (51 archivos) y `tests/Unit/VentasDiseno/`. Ejecutar con `infra/test.sh --filter=VentasDiseno`.

| Tema | Archivos |
|---|---|
| Pipeline | `PipelineControllerTest`, `PipelineStageTransitionTest`, `PipelineCanEditTest`, `PipelinePassToOrderTest`, `PipelineFileUploadTest`, `PipelineFileDeleteTest`, `PipelineCardContactControllerTest`, `PipelineCardProductDeliveryControllerTest`, `VentasDisenoBackfillPipelineCardCreatedByCommandTest` |
| Cotización | `QuoteControllerTest`, `QuoteItemControllerTest`, `QuotePartControllerTest`, `QuoteContactControllerTest`, `QuoteListControllerTest`, `QuoteConditionsSettingsControllerTest`, `QuoteNewVersionFromDesignTest`, `QuoteDeliveryToPipelineTest`, `QuoteContactsToPipelineCardTest`, `OrphanQuoteCleanupServiceTest`, `QuotePdfLayoutEstimatorTest`, `QuotePdfSignatureTotalsRenderTest` |
| CotizaYA | `CotizaYaTest` |
| Clientes y asociados | `ClientControllerTest`, `ClientsControllerTest`, `ClientTaxDataTest`, `ReceptorTipoTest`, `ArchitectControllerTest`, `AssociatesBaseTest`, `ProjectAssociatesTest`, `SalesProjectControllerTest` |
| Catálogo | `CatalogControllerTest`, `CatalogListSqlTest`, `CatalogPdfExportTest`, `CatalogProductControllerTest`, `CatalogProductFamilyControllerTest`, `CatalogProductSearchScopeTest`, `CatalogUatTestDataCleanupControllerTest`, `CatalogUatTestDataSeederTest`, `Scrum831InventoryTestDataSeederTest`, `SharedFamiliesSeederTest` |
| Reportes y comisión | `ReportsControllerTest`, `ReportsConfigControllerTest`, `CommissionsControllerTest`, `SalesCommissionTierControllerTest`, `PricingSettingsControllerTest`, `VentasDisenoSalesGoalsSeederTest` |
| Pantallas | `HomeControllerTest`, `DashboardControllerTest`, `OrdersControllerTest`, `ProjectsListControllerTest`, `CalendarEventControllerTest`, `AuditLogControllerTest`, `TestDataCleanupServiceTest` |

Frontend: `npx vitest run <patrón>` y los specs Playwright de `e2e/`.

---

## 12. Recetas para modificar el módulo

- **Agregar un tipo de precio:** ampliar la regla `in` de `QuoteRequest` y `QuoteItemRequest`, el `match` de `QuoteItem::effectiveUnitPrice`, el campo en `PricingSettings` (migración + `PricingSettingsRequest` + panel) y el selector de `QuotePartCard`.
- **Agregar una etapa:** `PipelineCard::STAGE_ORDER`, `validationErrorForTransitionTo`, `DashboardService` (`STAGES`), `ReportsService`, columnas de `PipelinePage` y los tipos.
- **Cambiar un requisito de Aprobado:** `validationErrorForTransitionTo` y `ProductDeliveryService::validationErrorForApproval`; actualizar `PipelineStageTransitionTest`.
- **Un parámetro nuevo de negocio:** tabla de fila única (copiar `orders_settings`), Request, controlador y panel; nunca constante.
- **Cambiar tramos de comisión:** desde la pantalla de superadmin; no editar la migración.
- **Cambiar el PDF de la cotización:** la vista Blade `resources/views/ventas-diseno/quote-pdf.blade.php` y `QuotePdfLayoutEstimator` (el bloque final debe seguir envuelto en `page-break-inside: avoid`).
- **Exponer un campo del producto en el buscador:** nunca `cost`; revisar `CatalogService` y `CatalogProductController`.

---

## 13. Problemas conocidos y deuda

1. **Umbrales fijos** que ignoran la configuración: `STAGNANT_THRESHOLD_DAYS = 15` y los umbrales de urgencia de fechas del frontend (`utils/urgencyUtils.ts`), ambos sin parametrizar.
2. **Eventos sin consumidor:** `QuoteGenerated` y `QuoteConfirmed`.
3. **`/ventas-diseno/calendar-events`** (CRUD completo, tabla `calendar_events`) quedó sin uso desde que el calendario lee Outlook.
4. **`HomeController::approvalsPending`** devuelve siempre lista vacía (ningún tipo de aprobación pertenece al módulo).
5. **`docs/architecture/ventas-diseno.md`** está desactualizado frente al código (29 modelos reales).
6. **Controladores grandes:** `QuoteController` (943), `PipelineController` (673) y `QuoteItemController` (653) mezclan validación inline y lógica; los `validate()` en línea no pasan por Form Request.
7. **Dos "Arquitecto":** `quotes.architect_id` (origina comisión) no es lo mismo que los asociados del proyecto (SCRUM-1121).
8. **Servicios de limpieza de datos de prueba** (`TestDataCleanupService`, `CatalogUatTestDataCleanupController`) siguen en el código de producción; el segundo borra en la base real.
9. **Gerencia reutiliza `PipelineCard::STAGNANT_THRESHOLD_DAYS`** (`GerenciaHomeService`): cambiar el 15 afecta también al tablero ejecutivo.

---

## 14. Glosario

| Término | Significado |
|---|---|
| **Pipeline / tarjeta** | Tablero y cada proyecto en él (`pipeline_cards`). |
| **Lead** | Primera etapa; nace manualmente o al crear un cliente. |
| **Cliente Master / Subcliente** | Casa matriz y entidad facturable (lleva RUC). |
| **Asociado** | Arquitecto, diseñador, contratista, ingeniero o consultor (`architects`). |
| **Partida** | Agrupación de ítems dentro de una cotización. |
| **Folio** | `COT-AAAA-NNNN` (comercial) o `COTY-AAAA-NNNN` (CotizaYA). |
| **Confirmar** | Registrar oficialmente la cotización (`confirmed_at`). |
| **Versión** | Duplicado de una cotización confirmada. |
| **Tipo de precio** | `public`, `project`, `special`, `partner`, `premium`. |
| **Cohorte** | Cierre mensual de comisión de un vendedor. |
| **Pasar a Pedido** | Crear el pedido de Bodega desde una tarjeta Aprobada. |
| **CotizaYA** | Cotizador de venta chica aislado del flujo comercial. |
| **DV** | Dígito verificador del RUC. |

<!-- nav-manuales:inicio -->
---
**Navegación:** [Índice de manuales](README.md) · ← inicio · [CRM](crm.md) →
<!-- nav-manuales:fin -->
