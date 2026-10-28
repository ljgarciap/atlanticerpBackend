# Manual técnico — Compras

<!-- nav-manuales:inicio -->
> **Navegación:** [Índice de manuales](README.md) · ← [Servicios](servicios.md) · [Bodega](bodega.md) →
> **Módulos relacionados:** [Bodega](bodega.md) · [Servicios](servicios.md) · [Admin & Contabilidad](admin-contabilidad.md)
<!-- nav-manuales:fin -->

> **Para quién:** un desarrollador que nunca ha tocado este módulo y necesita entenderlo y modificarlo con seguridad.
> **Base del manual:** backend `atlanticerp-backend` rama `dev` (commit `f801a945`, 2026-10-12) y frontend `atlanticerp-frontend` `origin/dev` (commit `95cc675`, 2026-10-12).
> **Cómo se verifica:** `python3 scripts/manual-coverage.py compras` comprueba que cada pieza del módulo esté nombrada aquí. Mide presencia, no calidad; lo escrito se contrasta contra el código en la revisión.
> **Documentos hermanos:** diagrama de arquitectura `docs/architecture/diagramas/compras.md`, ERD `docs/architecture/erd/compras.md`, API `docs/api/exports/openapi/compras.json`, diseños `docs/architecture/compras-sustitutos-pagos-agencias-reclamos-reportes.md`, `compras-bodega-batch-20260816.md`, `compras-oc-pdf-ingles-diseno.md`.

---

## 1. Propósito y alcance

Compras gestiona todo lo que Atlantic le compra a sus proveedores, de punta a punta:

| Área | Qué resuelve |
|---|---|
| **Proveedores** | Alta, edición, calificación y datos bancarios (con lectura asistida por IA de una imagen). |
| **Órdenes de compra** | Crear la orden, aprobarla, avanzarla por sus estados, generar el PDF, enviarla por correo y, a pedido, en inglés. |
| **Logística** | Seguimiento del envío: etapas con fechas, contenedor y naviera, checklist de documentos, validación con IA de la confirmación del proveedor. |
| **Ingreso de mercancía** | Registrar lo que llegó contra una orden, con costeo, ingreso parcial y detección automática de la factura con IA. |
| **Inventario** | Catálogo de productos con costos, stock por bodega, familias y reposición. |
| **Pagos a proveedores** | Registrar pagos con comprobante, "Enviar a pago" y cambio de monto con aprobación. |
| **Garantías y reclamos** | Reclamos a proveedor por mercancía defectuosa, incompleta o incorrecta. |
| **Sustitutos** | Buscar un producto sustituto (catálogo, foto o ficha técnica con IA) y decidir su aprobación por margen. |
| **Agencias de liquidación** | Agencias aduanales de las órdenes Zona Libre. |
| **Zona Libre de Colón** | Decidir las solicitudes de orden que genera Bodega. |
| **Reportes e Inicio** | Seis paneles de reportes y la pantalla de inicio. |

**Quiénes lo usan.** `lider_compras` (Lidercompras) es el rol operativo. "Aprobador" aprueba lo que requiere su visto bueno y se configura en `ComprasSettings.primary_approver_user_id`, no por correo ni por nombre. Gerencia (`gerencia.view`) decide sustitutos de bajo margen. `lider_operaciones` tiene acceso angosto (sustitutos y lectura). El vendedor/diseñador entra solo a Logística, a sus propios sustitutos y a inventario restringido.

**Qué no es Compras.** El catálogo de productos (`CatalogProduct`) vive en el módulo Ventas & Diseño; Compras lo reutiliza y le agrega campos. Las bodegas y el stock físico viven en Bodega; Compras llama a `KardexService` de Bodega para mover stock. La facturación a clientes es de Admin & Contabilidad.

---

## 2. Mapa del código

### 2.1 Backend (`atlanticerp-backend/app/Modules/Compras/`)

| Carpeta | Contenido |
|---|---|
| `Http/Controllers/` | 17 controladores, uno por área (ver 6). |
| `Http/Requests/` | 16 Form Requests con las reglas de validación. Todos devuelven `authorize() = true`: **la autorización vive en el middleware `permission:` de las rutas**, no aquí. |
| `Models/` | 19 modelos Eloquent (ver 3). |
| `Services/` | 15 servicios con la lógica reutilizable. |
| `Jobs/` | 5 jobs de Horizon (cola). |
| `Events/` | 7 eventos de dominio. |
| `Mail/` | `PurchaseOrderMail`. |
| `Support/` | Utilidades: `OrderMoneyVisibility`, `ReportPeriod`, `TranslationException`, `TranslationGuard`. |

Fuera de la carpeta del módulo pero parte de él:

| Archivo | Para qué |
|---|---|
| `routes/compras.php` | Todas las rutas, montadas bajo `api/compras` en `bootstrap/app.php` con el grupo `api` (incluye autenticación JWT). |
| `config/compras.php` | Parámetros técnicos de la traducción con IA (modelo, tiempos, tope de tokens). No son reglas de negocio. |
| `database/migrations/tenant/2026_07_20_100000_create_compras_providers_table.php` y siguientes | Esquema del módulo (unas 50 migraciones; ver 3.4). |
| `database/seeders/ZonaLibreProviderSeeder.php`, `LafayetteLightingProviderSeeder.php`, `DemoProviderAssignmentSeeder.php`, `SpecialPermissionSeeder.php` | Datos base y permisos angostos. |
| `app/Modules/Bodega/Listeners/ReleaseOrdersAwaitingStock.php` | Escucha `PurchaseOrderReceived` y libera pedidos de Bodega "sin stock". |
| `app/Modules/Servicios/Listeners/SyncInsumoReservationOnPurchaseOrderReceived.php` | Escucha el mismo evento para la Reserva Servicios. |
| `app/Providers/AppServiceProvider.php` | Registra los listeners y el limitador `oc-traduccion`. |
| `app/Shared/Support/AprobacionGate.php`, `ApprovalsPendingService.php` | Aprobaciones pendientes que Gerencia y el Inicio de Compras comparten. |

### 2.2 Frontend (`atlanticerp-frontend/src/`)

| Ruta | Contenido |
|---|---|
| `pages/compras/` | 15 páginas, una por pantalla (ver 8). |
| `components/compras/` | 20 componentes reutilizables (ver 8.2). |
| `api/comprasApi.ts` | Un único objeto `comprasApi` con una función por endpoint, agrupado por área. |
| `hooks/useCompras.ts` | Hooks de TanStack Query: un `useQuery` por lectura y un `useMutation` por escritura, con invalidación de claves `compras/...`. |
| `types/compras.ts` | Todos los tipos TypeScript del módulo (~1200 líneas). |
| `utils/orderStatusLabel.ts`, `utils/searchSuggestionsCompras.ts` | Etiquetas de estado y sugerencias de búsqueda. |
| `pages/InventarioPage.tsx` | Pantalla de Inventario; vive fuera de `pages/compras/` pero es de Compras. |
| `components/ComprasSettingsPanel.tsx` | Panel de Ajustes de Compras. |
| `i18n/locales/{es,en}/compras.json` | Textos del módulo (~1160 líneas por idioma). |

### 2.3 Cómo viaja una petición

```
Navegador → React (página → hook useCompras → comprasApi → axios con JWT)
         → nginx /api/compras/... → Laravel (grupo `api`: AuthenticateJwt)
         → middleware `permission:...` (CheckPermission, OR de permisos separados por coma)
         → Controller → Form Request (validación) → Model / Service
         → Resource en el propio controlador (métodos format*/formatSummary/formatDetail)
```

El controlador obtiene el usuario con `resolveUser($request)` leyendo `request->attributes['jwt']['sub']`. Los permisos efectivos viajan en el JWT (`permissions`); varios controladores los leen de ahí y no de `$user->hasPermission()` (ver 7.3, trampa v1/v2).

---

## 3. Modelo de datos

Esquema PostgreSQL por tenant: `{tenant}_compras` (para el tenant `atlantic`, `atlantic_compras`). Todos los modelos sobrescriben `getTable()` para anteponer ese esquema según `Tenant::current()`. El ERD completo (columnas y llaves) está en `docs/architecture/erd/compras.md`; aquí va el sentido de cada tabla y sus reglas.

### 3.1 Tablas

| Tabla | Modelo | Qué guarda |
|---|---|---|
| `providers` | `Provider` | Proveedor. Único campo obligatorio de dominio: `name`. `category` (locales, zona_libre, china, europa, online, latinoamerica) determina `origin` (`local` solo para "locales"; el resto `internacional`). Bloques planos de contacto (`contact_*`, `whatsapp`, `phone`, `email`) y bancarios (`bank_*`, más la imagen de respaldo `bank_image_*`). `tax_id` y `tax_id_dv` (RUC y dígito verificador). |
| `purchase_orders` | `PurchaseOrder` | Cabecera de la orden. `status`, `modality` (`directo`/`zona_libre`), envío (`shipping_type`, `who_pays_shipping`, `shipping_cost`), fechas estimada y real, contenedor y naviera, aprobación de Aprobador (`requires_primary_approval`, `approved_by`, `approved_at`), pagos (`payment_requested_at`, `pending_amount_change`, `amount_change_requested_by`), remanente (`pending_remainder_status`), fechas por etapa (`ordenado_at`, `en_transito_at`, `en_aduana_at`, `en_transito_local_at` y las `remainder_*_at`), `origin_module`, `liquidation_agency_id`. |
| `purchase_order_lines` | `PurchaseOrderLine` | Línea: `catalog_product_id`, `quantity`, `unit_cost`, `subtotal`, costo adicional (`additional_cost_percent`/`_amount`/`_type`), `sales_project_id` (nulo = "Ninguno (stock)", se asigna **por línea**, nunca se hereda de la orden), `stock_state_at_order` (estado de stock del producto en el momento de crear la orden; alimenta el reporte "Oportunidad de compra"). |
| `purchase_order_documents` | `PurchaseOrderDocument` | Checklist de documentos del envío. `category` es un catálogo fijo: factura_comercial, declaracion_nacionalizacion, permiso_importacion, bl, confirmacion_proveedor, otro. Archivo en S3. |
| `purchase_order_payments` | `PurchaseOrderPayment` | Pago registrado. **Inmutable**: sin `updated_at`, nunca se edita ni se borra; con comprobante en S3. |
| `purchase_order_receipts` | `PurchaseOrderReceipt` | Ingreso de mercancía (cabecera): factura adjunta y 4 totales de costeo (importación, flete, manejo, otros) solo para proveedores no locales. |
| `purchase_order_receipt_lines` | `PurchaseOrderReceiptLine` | Línea de ingreso: cantidad, costo, bodega destino, `itbms_amount` (solo proveedor local), prorrateos de costos, y `confirmed_at` (**nulo = "Por ingresar"**, aún no cuenta como stock). |
| `claims` | `PurchaseOrderClaim` | Reclamo. `status` (`en_revision`/`resuelto`), `expected_resolution` (reposicion, nota_credito_favor, nota_credito_reembolso, envio_repuestos, negado), código visible `R-` más (1000 + id). Campo `service_ticket_id` reservado para el vínculo con Servicios; hoy no se consulta. |
| `claim_lines` | `PurchaseOrderClaimLine` | Producto afectado dentro de un reclamo y su cantidad. |
| `claim_photos` | `PurchaseOrderClaimPhoto` | Fotos de evidencia (S3). Sin `updated_at`. |
| `replacement_requests` | `ReplacementRequest` | Solicitud de sustituto: producto original, producto propuesto, proyecto de venta, `margin_percent`, `status` (pendiente, pendiente_gerencia, aprobada, rechazada), quién pidió/aprobó/rechazó y `generated_order_id`. |
| `liquidation_agencies` | `LiquidationAgency` | Agencia aduanal. Incluye 4 campos de seguimiento de pago **cargados a mano** (`pending_payment_amount`, `paid_amount`, `last_payment_date`, `next_payment_date`); no es un historial de pagos. |
| `liquidation_agency_contacts` | `LiquidationAgencyContact` | Contactos de la agencia (1:N). Regla: no puede quedar sin ninguno. |
| `settings` | `ComprasSettings` | Fila única (`id = 1`) de parámetros; ver 4. |
| `bodega_zona_libre_requests` | `BodegaZonaLibreRequest` | Solicitud de orden a Zona Libre creada por Bodega y resuelta por Compras. Vive aquí por decisión de ubicación del diseño (ADR-SCRUM433-445). |
| `bodega_zona_libre_request_lines` | `BodegaZonaLibreRequestLine` | Líneas; `unit_cost_snapshot` congela el costo del producto. |
| `bodega_zona_libre_folio_counters` | `ZonaLibreFolioCounter` | Contador atómico del número de solicitud (`ZonaLibreFolioService::next()`). |
| `purchase_order_translations` | `PurchaseOrderTranslation` | Versión guardada de una orden en otro idioma (hoy solo `en`); único por (orden, idioma). |
| `translation_cache` | `TranslationCache` | Caché de traducción por texto, `sha256` del texto normalizado, compartida entre órdenes. |

Tablas de otros esquemas que Compras usa: `auth.users`, `bodega.warehouses`, `legacy.proveedores`, `servicios.insumo_purchase_requests`, y de Ventas & Diseño `catalog_products`, `catalog_product_providers` (pivote de proveedores múltiples, SCRUM-1126) y `sales_projects`.

### 3.2 Estados de la orden (`PurchaseOrder::STATUS_*`)

```
Proveedor internacional:  por_aprobar → pendiente_liquidar → ordenado → en_transito → en_aduana → en_transito_local → recibido
Proveedor local:          por_aprobar → pendiente_liquidar → ordenado → en_transito_local → recibido
```

- La secuencia la calcula `statusSequence()` según `provider.origin`; `nextStatus()` devuelve la siguiente y `hasReachedStage($etapa)` dice si ya se pasó por ella (devuelve `false`, nunca lanza, si la etapa no aplica).
- `pendiente_liquidar` es **un solo estado interno**, pero su texto cambia: "Pendiente" si la modalidad es `directo` y "Por liquidar" si es `zona_libre`. Esa traducción vive solo en el frontend (`utils/orderStatusLabel.ts`); no hay equivalente en PHP.
- Una orden nace en `por_aprobar` solo si requiere aprobación de Aprobador (envío aéreo pagado por Atlantic). Si no, nace directo en `pendiente_liquidar`.
- Ejes aparte del estado: estado de pago (`pendiente`/`parcial`/`completo`, calculado por `paymentStatus()` contra `total_amount`), recepción (`pendiente`/`parcial`/`completo`, por `receptionSummary()`), y remanente (`ordenado`, `salio_de_origen`, `en_transito`, `en_aduana`, `en_transito_local`).
- Las fechas de cada etapa se guardan en las columnas de `STAGE_DATE_COLUMNS` (y `REMAINDER_STAGE_DATE_COLUMNS` para el remanente). `recibido` no tiene columna propia: reutiliza `actual_arrival_date`, que se llena sola con la fecha de hoy y **nunca se escribe a mano**.

### 3.3 Otros estados

- **Reclamo:** `en_revision` → `resuelto`. "Reportado" en la UI es el mismo conjunto que `en_revision`; no es un estado distinto.
- **Solicitud de sustituto:** `pendiente` (margen ≥ umbral) o `pendiente_gerencia` (margen menor o sin costo para calcularlo) → `aprobada` o `rechazada`. Solo una rechazada puede generar orden del producto original.
- **Solicitud de Zona Libre:** `pendiente` → `aprobada` (crea una orden normal) o `rechazada` (exige motivo).
- **Línea de ingreso:** `confirmed_at` nulo = "Por ingresar"; con fecha = ya es stock real.

### 3.4 Migraciones

Las migraciones del módulo (unas 50) viven en `database/migrations/tenant/` (prefijo de fecha desde `2026_07_20`). Usan la clase base `TenantSchemaMigration` con `$module = 'compras'`. Regla del proyecto: **toda migración nueva la revisa el Arquitecto**. Las migraciones también agregan permisos al catálogo (`compras.settings.configure`, `compras.zona-libre.approve`, `compras.sustitutos.manage`, `compras.limited.view`, `compras.logistica.view`) y otorgan permisos a roles.

---

## 4. Reglas de negocio y parámetros

### 4.1 Parámetros configurables (`ComprasSettings`, pantalla "Ajustes de Compras")

Se editan en `PUT /compras/settings` (permiso `compras.settings.configure`) y se validan en `ComprasSettingsRequest`.

| Parámetro | Para qué | Rango |
|---|---|---|
| `low_rating_threshold` | Umbral de "calificación baja" de proveedores | 0–100 |
| `primary_approver_user_id` | Quién es "Aprobador" para aprobar órdenes y cambios de monto | usuario existente o nulo |
| `claim_attention_days` | Días a partir de los cuales un reclamo abierto pide atención | 1–365 |
| `replacement_margin_threshold` | Margen mínimo (%) para que un sustituto no escale a Gerencia | 0–100 |
| `zona_libre_provider_id` | Proveedor fijo "Zona Libre de Colón" | proveedor existente |
| `similarity_weight_text`, `similarity_weight_price`, `similarity_weight_margin` | Pesos de la fórmula de similitud de sustitutos | 0–100 |
| `similarity_other_category_penalty`, `similarity_min_threshold` | Penalización por otra categoría y umbral mínimo | 0–100 |

Se obtienen con `ComprasSettings::current()`. Si `primary_approver_user_id` es nulo, **nadie queda bloqueado**: el chequeo de "solo Aprobador" se omite.

### 4.2 Reglas por área

**Orden de compra**
- Requiere aprobación de Aprobador si `shipping_type = aereo` **y** `who_pays_shipping = atlantic` (`computeRequiresPrimaryApproval()`).
- `who_pays_shipping` y `shipping_cost` solo existen para envío aéreo; el servidor los anula en cualquier otro tipo aunque el payload los traiga (`normalizeShippingFields()`).
- Mientras la orden esté `por_aprobar` con aprobación requerida y sin aprobar, **no avanza** (`isBlockedByPrimaryApproval()`).
- Solo se puede editar antes de "Ordenado" (estados `por_aprobar` y `pendiente_liquidar`). Editar reemplaza todas las líneas.
- `total_amount` es la suma de `unit_cost × quantity` de las líneas. Los costos adicionales se guardan por línea pero **no se suman al total**.
- Crítica (`isCritical()`): lleva 3 o más días en "Por aprobar", o su llegada estimada ya pasó sin estar recibida. Esa definición se reutiliza en Ver Órdenes, Logística ("Retrasados") y el Inicio.
- Llegar a `recibido` exige que todo lo esperado esté recibido (`GoodsReceiptConfirmationService::isFullyReceived()`) y que no haya remanente pendiente; al llegar, confirma automáticamente las líneas "Por ingresar".

**Pagos**
- Registrar un pago exige comprobante, no puede exceder el saldo, y **no depende de "Enviar a pago" ni de ninguna aprobación**.
- "Enviar a pago" es informativo: marca `payment_requested_at` y dispara `PaymentRequested`.
- Cambiar el monto antes del primer pago queda pendiente de aprobación de Aprobador. Mientras exista un cambio pendiente, **registrar pago y enviar a pago están bloqueados** (422). Si ya hay pagos, no se puede pedir el cambio; y si se registra un pago entre la solicitud y la aprobación, la solicitud queda obsoleta y se descarta.

**Ingreso de mercancía**
- Ninguna línea, sumada a lo ya recibido de esa orden y producto, puede superar lo pedido.
- Si queda faltante y no se eligió el estado del remanente, la respuesta es `requires_pending_status` (sin guardar) para que el frontend lo pregunte.
- Proveedor local: se calcula ITBMS por línea (7 %). No local: los 4 costos del encabezado se prorratean por valor de línea. El costo real queda como costo de referencia del producto.
- Corregir un ingreso (`PUT`) revierte los efectos de stock de las líneas confirmadas y las vuelve a registrar.
- Si la orden ya está `recibido` o el ingreso corregido ya tenía líneas confirmadas, la corrección confirma de inmediato.

**Sustitutos**
- Margen del sustituto menor al umbral (o sin costo) → `pendiente_gerencia`: solo Gerencia decide. Margen mayor o igual → `pendiente`: Gerencia o el vendedor/diseñador responsable del proyecto (nunca solo por haber creado la solicitud).
- **Generar la orden del producto original** es exclusivo del rol `lider_compras` (y superadmin), por `role_key`, no por permiso; solo en solicitudes rechazadas y una sola vez.
- Fórmula de similitud: `peso_texto × sim_texto + peso_precio × sim_precio + peso_margen × sim_margen − penalización (otra categoría)`; descarta lo que quede bajo el umbral. Pesos y umbral salen de `ComprasSettings`.

**Reclamos:** los productos afectados no pueden superar lo pedido; la resolución esperada es uno de 5 valores fijos; "atención" depende de `claim_attention_days`.

**Visibilidad de costos:** quien solo tiene `compras.logistica.view` (vendedor) nunca ve costos ni montos (`OrderMoneyVisibility::hiddenFor()`); el servidor los anula, también el ordenamiento por monto.

### 4.3 Valores fijos en el código (deuda conocida)

El proyecto prohíbe fijar umbrales de negocio en el código. Se encontraron estos en Compras; **no se corrigieron** (ver sección 13):

| Valor | Dónde |
|---|---|
| 3 días para considerar crítica una orden "Por aprobar" | `PurchaseOrder::CRITICAL_DAYS_POR_APROBAR` |
| 7 % de ITBMS para proveedor local | `GoodsReceiptController::ITBMS_RATE_LOCAL` |
| Pesos 40 % a tiempo, 35 % completas, 25 % sin reclamos | `Provider::ratingScore()` |
| Aviso de oportunidad de compra cuando lo reactivo supera 50 % | `ComprasReportsController::purchaseOpportunity()` |

Los límites de uso de la IA (5 validaciones de confirmación por orden por hora, 10 búsquedas de sustituto por usuario por hora, 10 detecciones de factura por usuario por hora, 5 extracciones bancarias por proveedor por hora, 25 candidatos por búsqueda, 6 traducciones por minuto) son controles técnicos de costo, no reglas comerciales.

---

## 5. Flujos principales

### 5.1 Ciclo de vida de una orden

```mermaid
sequenceDiagram
  participant Y as Compras (Lidercompras)
  participant M as Aprobador
  participant API as PurchaseOrderController
  participant L as Logística / Ingreso
  Y->>API: POST /orders (store)
  Note over API: requiere aprobación → por_aprobar; si no → pendiente_liquidar
  M->>API: PATCH /orders/{id}/approve
  Note over API: guarda approved_by; NO cambia el estado
  Y->>API: PATCH /orders/{id}/advance (varias veces)
  Note over API: transacción con lockForUpdate; fija la fecha de cada etapa
  L->>API: POST /goods-receipts (ingreso)
  Y->>API: PATCH /orders/{id}/advance → recibido
  API-->>API: confirma líneas "Por ingresar" y emite PurchaseOrderReceived
```

`advance()` corre dentro de una transacción con `lockForUpdate()`. Hubo un defecto real (5 llamadas concurrentes cuadruplicaban el stock); no se debe quitar ese bloqueo.

### 5.2 Ingreso de mercancía (asistente de 3 pasos)

1. **Paso 0 (opcional):** subir la factura (`POST /goods-receipts/invoice`) dispara `MatchInvoiceToOrderJob`; el frontend consulta el resultado (`GET /goods-receipts/invoice/{jobId}`) y precarga orden y productos.
2. **Paso 1:** elegir la orden (`GET /goods-receipts/eligible-orders`).
3. **Paso 2:** líneas y costeo (`GET /goods-receipts/orders/{orderId}` da los productos esperados).
4. **Guardar:** `POST /goods-receipts`. Si hay faltante sin estado de remanente, responde `requires_pending_status`.
5. **Confirmar:** cada línea se confirma sola al llegar la orden a `recibido`, o a mano (`POST /goods-receipts/lines/{lineId}/confirm`, `.../products/{productId}/confirm-pending`, `PATCH /orders/{id}/confirm-pending-receipts`). Confirmar llama a `KardexService::registrar()` de Bodega, que es el **único** punto de escritura de stock por bodega.

### 5.3 Remanente (entrega parcial)

Si un ingreso deja productos incompletos, `pending_remainder_status` guarda en qué etapa va lo que falta. `PATCH /orders/{id}/advance-remainder` lo avanza por sus propias etapas, independiente del estado de la orden. El enlace "Ingreso de mercancía" solo se muestra desde "En aduana" (o "En tránsito local") y se oculta cuando todo se recibió (`showsGoodsReceiptLink()`).

### 5.4 Sustitutos

Buscar (catálogo sin IA, o foto/ficha con IA) → "Generar solicitud" (calcula el margen y decide `pendiente` o `pendiente_gerencia`) → aprobar o rechazar → si se rechaza, `lider_compras` puede generar la orden del producto original.

### 5.5 Zona Libre

Bodega crea la solicitud (en su módulo) → Compras la aprueba o rechaza en `POST /compras/zona-libre/requests/{id}/approve|reject` con permiso `compras.zona-libre.approve` → al aprobar se crea una `PurchaseOrder` normal en `por_aprobar`, modalidad `zona_libre`, y desde ahí sigue el flujo común. Re-resolver una ya resuelta da 422 (el chequeo está dentro de la transacción con bloqueo).

### 5.6 Orden en inglés (SCRUM-1127)

`POST /orders/{id}/translation` traduce solo las descripciones de las líneas (todo o nada; los fragmentos técnicos como `24V`, `IP67` deben quedar idénticos y `TranslationGuard` lo verifica en código). La traducción queda guardada; PDF y correo en inglés (`lang=en`) la leen sin volver a llamar a la IA. Si la orden cambió después, la versión queda desactualizada (`stale`) y hay que regenerarla.

---
## 6. API

Base: `/api/compras` (dev: `https://dev.atlanticerp.ai/api/compras`). Todas las rutas exigen JWT (`Authorization: Bearer`). La documentación interactiva de dev está en `https://dev.atlanticerp.ai/docs/api` y las colecciones de Postman en `docs/api/exports/postman/compras.postman_collection.json`.

**Convenciones**
- Errores de validación: HTTP 422 con `message` y, cuando aplica, `errors` por campo. Los mensajes de negocio están en español.
- Sin sesión o usuario inexistente: 401. Sin permiso: 403. Recurso inexistente: 404 con `{"message": "Not found"}`.
- Listados paginados: `page`, `per_page` (o `all`), `sort_by`, `sort_dir` y respuesta `{data, meta}`. El ordenamiento está limitado por una lista blanca (`ALLOWED_SORTS`); pedir otra columna no la usa.
- Archivos (comprobantes, documentos, fotos, facturas) viajan como `multipart/form-data` y se guardan en S3 privado; se descargan con URL prefirmada de 15 minutos.
- Las tareas con IA son **asíncronas**: el `POST` devuelve un `job_id` y el cliente consulta un `GET .../{jobId}` hasta que termine (validación de confirmación, extracción bancaria, detección de factura, búsqueda de sustitutos). La excepción es la traducción de la orden, que es síncrona.

### 6.1 Rutas

La tabla se generó con `php artisan route:list --path=api/compras --json` el 2026-10-12 (94 rutas). Cuando cambie `routes/compras.php`, regenerar y actualizar. La columna de permisos es un **OR**: basta uno.

**`/compras/home`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/home/approvals-pending` | `ComprasHomeController@approvalsPending` | `(la comprueba el controlador)` |
| GET | `/compras/home/summary` | `ComprasHomeController@summary` | `compras.read` |

**`/compras/calendar`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/calendar` | `OutlookCalendarController@index` | `compras.read` |

**`/compras/providers`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/providers` | `ProviderController@index` | `compras.read` |
| POST | `/compras/providers` | `ProviderController@store` | `compras.write` |
| GET | `/compras/providers/{id}` | `ProviderController@show` | `compras.read` |
| PUT | `/compras/providers/{id}` | `ProviderController@update` | `compras.edit` |
| POST | `/compras/providers/{id}/bank-image` | `ProviderController@uploadBankImage` | `compras.edit` |
| DELETE | `/compras/providers/{id}/bank-image` | `ProviderController@deleteBankImage` | `compras.edit` |
| GET | `/compras/providers/{id}/bank-image/extraction` | `ProviderController@bankImageExtraction` | `compras.read` |

**`/compras/orders`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/orders` | `PurchaseOrderController@index` | `compras.read,compras.limited.view,compras.logistica.view` |
| POST | `/compras/orders` | `PurchaseOrderController@store` | `compras.write` |
| GET | `/compras/orders/shipment-projects` | `PurchaseOrderController@searchShipmentProjects` | `compras.read,compras.limited.view,compras.logistica.view` |
| GET | `/compras/orders/{id}` | `PurchaseOrderController@show` | `compras.read,bodega.read,compras.limited.view,compras.logistica.view` |
| PUT | `/compras/orders/{id}` | `PurchaseOrderController@update` | `compras.edit` |
| PATCH | `/compras/orders/{id}/advance` | `PurchaseOrderController@advance` | `compras.edit` |
| PATCH | `/compras/orders/{id}/advance-remainder` | `PurchaseOrderController@advanceRemainder` | `compras.edit` |
| PATCH | `/compras/orders/{id}/approve` | `PurchaseOrderController@approve` | `compras.approve` |
| PATCH | `/compras/orders/{id}/approve-amount-change` | `PurchaseOrderPaymentController@approveAmountChange` | `compras.edit` |
| PATCH | `/compras/orders/{id}/confirm-pending-receipts` | `PurchaseOrderController@confirmPendingReceipts` | `compras.edit` |
| PATCH | `/compras/orders/{id}/liquidate` | `PurchaseOrderController@liquidate` | `compras.edit` |
| GET | `/compras/orders/{id}/pdf` | `PurchaseOrderController@pdf` | `compras.read` |
| PATCH | `/compras/orders/{id}/reject-amount-change` | `PurchaseOrderPaymentController@rejectAmountChange` | `compras.edit` |
| PATCH | `/compras/orders/{id}/request-amount-change` | `PurchaseOrderPaymentController@requestAmountChange` | `compras.edit` |
| PATCH | `/compras/orders/{id}/request-payment` | `PurchaseOrderPaymentController@requestPayment` | `compras.edit` |
| POST | `/compras/orders/{id}/send-email` | `PurchaseOrderController@sendEmail` | `compras.write` |
| PATCH | `/compras/orders/{id}/shipping-info` | `PurchaseOrderController@updateShippingInfo` | `compras.edit` |
| POST | `/compras/orders/{id}/translation` | `PurchaseOrderController@translate` | `compras.write` |
| GET | `/compras/orders/{orderId}/documents` | `PurchaseOrderDocumentController@index` | `compras.read,bodega.read,compras.limited.view,compras.logistica.view` |
| POST | `/compras/orders/{orderId}/documents` | `PurchaseOrderDocumentController@store` | `compras.edit` |
| POST | `/compras/orders/{orderId}/documents/{documentId}/validate` | `PurchaseOrderDocumentController@validate` | `compras.edit` |
| GET | `/compras/orders/{orderId}/documents/{documentId}/validation` | `PurchaseOrderDocumentController@validation` | `compras.read` |
| GET | `/compras/orders/{orderId}/payments` | `PurchaseOrderPaymentController@index` | `compras.read` |
| POST | `/compras/orders/{orderId}/payments` | `PurchaseOrderPaymentController@store` | `compras.edit` |

**`/compras/settings`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/settings` | `ComprasSettingsController@show` | `compras.read` |
| PUT | `/compras/settings` | `ComprasSettingsController@update` | `compras.settings.configure` |

**`/compras/approved-projects`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/approved-projects` | `ApprovedProjectController@index` | `compras.read,compras.sustitutos.manage,ventas_diseno.edit` |

**`/compras/reports`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/reports/claims` | `ComprasReportsController@claims` | `compras.read` |
| GET | `/compras/reports/inventory-attention` | `ComprasReportsController@inventoryAttention` | `compras.read` |
| GET | `/compras/reports/liquidation-imports` | `ComprasReportsController@liquidationImports` | `compras.read` |
| GET | `/compras/reports/provider-rating` | `ComprasReportsController@providerRating` | `compras.read` |
| GET | `/compras/reports/purchase-opportunity` | `ComprasReportsController@purchaseOpportunity` | `compras.read` |
| GET | `/compras/reports/purchased-by-period` | `ComprasReportsController@purchasedByPeriod` | `compras.read` |

**`/compras/replacement-requests`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/replacement-requests` | `ReplacementRequestController@index` | `compras.read,compras.sustitutos.manage,ventas_diseno.edit` |
| POST | `/compras/replacement-requests` | `ReplacementRequestController@store` | `compras.write,compras.sustitutos.manage,ventas_diseno.edit` |
| POST | `/compras/replacement-requests/search` | `ReplacementSearchController@store` | `compras.edit,compras.sustitutos.manage` |
| GET | `/compras/replacement-requests/search/{jobId}` | `ReplacementSearchController@show` | `compras.read,compras.sustitutos.manage` |
| POST | `/compras/replacement-requests/{id}/approve` | `ReplacementRequestController@approve` | `compras.write,compras.sustitutos.manage,gerencia.view,ventas_diseno.edit` |
| POST | `/compras/replacement-requests/{id}/generate-order` | `ReplacementRequestController@generateOrder` | `compras.write` |
| POST | `/compras/replacement-requests/{id}/reject` | `ReplacementRequestController@reject` | `compras.write,compras.sustitutos.manage,gerencia.view,ventas_diseno.edit` |

**`/compras/zona-libre`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| POST | `/compras/zona-libre/requests/{id}/approve` | `ComprasZonaLibreRequestController@approve` | `compras.zona-libre.approve` |
| POST | `/compras/zona-libre/requests/{id}/reject` | `ComprasZonaLibreRequestController@reject` | `compras.zona-libre.approve` |

**`/compras/claims`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/claims` | `PurchaseOrderClaimController@index` | `compras.read` |
| POST | `/compras/claims` | `PurchaseOrderClaimController@store` | `compras.write` |
| GET | `/compras/claims/{id}` | `PurchaseOrderClaimController@show` | `compras.read` |
| GET | `/compras/claims/{id}/pdf` | `PurchaseOrderClaimController@pdf` | `compras.read` |
| POST | `/compras/claims/{id}/photos` | `PurchaseOrderClaimController@storePhoto` | `compras.edit` |
| PATCH | `/compras/claims/{id}/resolution` | `PurchaseOrderClaimController@updateResolution` | `compras.edit` |
| PATCH | `/compras/claims/{id}/status` | `PurchaseOrderClaimController@updateStatus` | `compras.edit` |

**`/compras/liquidation-agencies`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/liquidation-agencies` | `LiquidationAgencyController@index` | `compras.read` |
| POST | `/compras/liquidation-agencies` | `LiquidationAgencyController@store` | `compras.write` |
| GET | `/compras/liquidation-agencies/summary` | `LiquidationAgencyController@summary` | `compras.read` |
| GET | `/compras/liquidation-agencies/{id}` | `LiquidationAgencyController@show` | `compras.read` |
| PUT | `/compras/liquidation-agencies/{id}` | `LiquidationAgencyController@update` | `compras.edit` |
| POST | `/compras/liquidation-agencies/{id}/contacts` | `LiquidationAgencyController@storeContact` | `compras.edit` |
| PUT | `/compras/liquidation-agencies/{id}/contacts/{contactId}` | `LiquidationAgencyController@updateContact` | `compras.edit` |
| DELETE | `/compras/liquidation-agencies/{id}/contacts/{contactId}` | `LiquidationAgencyController@destroyContact` | `compras.edit` |

**`/compras/products`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/products` | `CatalogProductSearchController@index` | `compras.read,bodega.read,compras.sustitutos.manage,ventas_diseno.edit` |

**`/compras/inventory`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/inventory` | `InventoryController@index` | `(la comprueba el controlador)` |
| POST | `/compras/inventory` | `InventoryController@store` | `compras.write` |
| GET | `/compras/inventory/check-reference` | `InventoryController@checkReference` | `compras.read` |
| GET | `/compras/inventory/{id}` | `InventoryController@show` | `(la comprueba el controlador)` |
| PUT | `/compras/inventory/{id}` | `InventoryController@update` | `compras.edit` |
| GET | `/compras/inventory/{id}/order-prefill` | `InventoryController@orderPrefill` | `compras.read` |
| POST | `/compras/inventory/{id}/photo` | `InventoryController@uploadPhoto` | `compras.edit` |
| GET | `/compras/inventory/{id}/technical-sheet` | `InventoryController@technicalSheetUrl` | `compras.read,compras.limited.view` |
| POST | `/compras/inventory/{id}/technical-sheet` | `InventoryController@uploadTechnicalSheet` | `compras.edit` |
| PATCH | `/compras/inventory/{id}/toggle-active` | `InventoryController@toggleActive` | `compras.edit` |
| GET | `/compras/inventory/{id}/warehouse-stock` | `InventoryController@warehouseBreakdown` | `(la comprueba el controlador)` |

**`/compras/warehouses`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/warehouses` | `InventoryController@warehouses` | `compras.read,compras.limited.view` |

**`/compras/families`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/families` | `InventoryController@families` | `(la comprueba el controlador)` |
| POST | `/compras/families` | `InventoryController@storeFamily` | `compras.write,compras.edit` |
| GET | `/compras/families/{id}` | `InventoryController@familyDetail` | `(la comprueba el controlador)` |
| POST | `/compras/families/{id}/generate-purchase` | `InventoryController@generateFamilyPurchase` | `compras.edit` |

**`/compras/goods-receipts`**

| Método | Ruta | Controlador@método | Permiso (OR) |
|---|---|---|---|
| GET | `/compras/goods-receipts` | `GoodsReceiptController@index` | `compras.read,compras.limited.view` |
| POST | `/compras/goods-receipts` | `GoodsReceiptController@store` | `compras.edit` |
| GET | `/compras/goods-receipts/eligible-orders` | `GoodsReceiptController@eligibleOrders` | `compras.read` |
| POST | `/compras/goods-receipts/invoice` | `GoodsReceiptInvoiceController@store` | `compras.edit` |
| GET | `/compras/goods-receipts/invoice/{jobId}` | `GoodsReceiptInvoiceController@show` | `compras.read` |
| POST | `/compras/goods-receipts/lines/{lineId}/confirm` | `GoodsReceiptController@confirm` | `compras.edit` |
| GET | `/compras/goods-receipts/orders/{orderId}` | `GoodsReceiptController@orderExpectedProducts` | `compras.read` |
| POST | `/compras/goods-receipts/products/{productId}/confirm-pending` | `GoodsReceiptController@confirmPendingForProduct` | `compras.edit` |
| GET | `/compras/goods-receipts/{id}` | `GoodsReceiptController@show` | `compras.read,compras.limited.view` |
| PUT | `/compras/goods-receipts/{id}` | `GoodsReceiptController@update` | `compras.edit` |

> **Nombre de los parámetros de ruta.** Laravel registra algunas rutas con `{id}` y el generador de OpenAPI las nombra con el argumento del método. Por eso en el OpenAPI y en las colecciones de Postman aparecen como `/compras/orders/{orderId}/request-payment`, `/compras/orders/{orderId}/request-amount-change`, `/compras/orders/{orderId}/approve-amount-change`, `/compras/orders/{orderId}/reject-amount-change` y `/compras/families/{familyId}/generate-purchase`, aunque `route:list` las muestre con `{id}`. Es la misma ruta.

### 6.2 Notas por área

- **`/compras/orders` (index):** filtros `provider_id`, `status`, `created_by`, `sales_project_id`, `search` (id exacto, proveedor o proyecto), `active_shipments`, `payment_pending`, `payment_status` (`sin_enviar`, `por_pagar`, `parcial`, `completo`) y `chip` (`critical`, `zona_libre`). Los chips `critical` y los de estado de pago se evalúan **en PHP** sobre el conjunto ya filtrado (no son columnas), por lo que cargan todas las órdenes que coinciden antes de paginar.
- **`/compras/orders/shipment-projects`** debe declararse **antes** de `/{id}`; si no, Laravel toma "shipment-projects" como un id.
- **`/compras/orders/{id}/translation` (POST):** limitador por usuario `oc-traduccion` (6 por minuto por defecto, `config/compras.php`). Códigos: 503 si la IA falla, 409 si otro usuario la está generando o la versión no existe/está desactualizada.
- **`/compras/orders/{id}/pdf`:** `include_cost` (`0` oculta costos) y `lang` (`es` por defecto, `en` exige traducción vigente).
- **`/compras/orders/{id}/send-email`:** encola `SendPurchaseOrderEmailJob`; responde 202. Exige que el proveedor tenga correo.
- **`/compras/inventory`:** `mode=ventas` permite a quien tiene acceso completo previsualizar el modo restringido. `warehouse_ids[]` filtra por bodegas, `family_id` por familia, `chip` por estado de stock.
- **Rutas sin middleware de permiso a propósito:** `home/approvals-pending`, `inventory` (index, show, warehouse-stock), `families` (index y detalle). El controlador resuelve el acceso (`InventoryController::resolveAccess()`), porque necesita un OR entre `compras.read` y `ventas_diseno.read` que el middleware no puede expresar.


### 6.3 Validaciones (Form Requests)

Cada Form Request valida el cuerpo de una ruta. Todos devuelven `authorize() = true`; el permiso se comprueba en la ruta.

| Form Request | Lo usa | Reglas relevantes |
|---|---|---|
| `StoreProviderRequest` | `POST /compras/providers` | Obligatorios: `name` (único), `category` (una de las 6), `country`, y **al menos un medio de contacto** entre `whatsapp`, `phone` y `email`. Teléfonos con el formato `^\+?[0-9\s-]{7,20}$`. `tax_id` único y `tax_id_dv` de 1 o 2 dígitos. `currency` de 3 letras. |
| `UpdateProviderRequest` | `PUT /compras/providers/{id}` | `name` obligatorio; el resto opcional, con las mismas reglas de formato; `is_active` booleano. |
| `StoreProviderBankImageRequest` | `POST /compras/providers/{id}/bank-image` | Archivo obligatorio, máx. 10 MB, extensiones jpg, jpeg, png, gif, webp. |
| `StorePurchaseOrderRequest` | `POST /compras/orders` | `provider_id` existente; `shipping_type` (aereo, maritimo, terrestre); `who_pays_shipping` (atlantic, cliente); `modality` (directo, zona_libre); al menos una línea; cada línea con `catalog_product_id` (activo) **o** `new_product`; `new_product.reference` única (también contra las líneas hermanas del mismo envío); proyecto de venta solo si es un Pedido Aprobado. |
| `UpdatePurchaseOrderRequest` | `PUT /compras/orders/{id}` | Hereda las reglas de `StorePurchaseOrderRequest`. |
| `StorePurchaseOrderDocumentRequest` | `POST /compras/orders/{orderId}/documents` | `category` de las 6 fijas; archivo obligatorio, máx. 500 MB; extensiones pdf, doc, docx, xls, xlsx, csv, ppt, pptx, imágenes (jpg, jpeg, png, gif, webp, svg, bmp) y archivos comprimidos (zip, rar, 7z). |
| `StorePurchaseOrderPaymentRequest` | `POST /compras/orders/{orderId}/payments` | `amount` numérico, mínimo 0.01; `proof` (comprobante) obligatorio, máx. 20 MB, extensiones pdf, jpg, jpeg, png, gif, webp. |
| `StorePurchaseOrderClaimRequest` | `POST /compras/claims` | `purchase_order_id` existente; al menos una línea con `purchase_order_line_id` y `affected_quantity` ≥ 1; `expected_resolution` una de las 5 fijas (opcional). El tope contra lo pedido lo aplica además el frontend. |
| `StoreReplacementRequestRequest` | `POST /compras/replacement-requests` | `original_catalog_product_id`, `proposed_catalog_product_id` (distinto del original) y `sales_project_id`, que debe ser un Pedido Aprobado (se revalida en el servidor). |
| `SearchSubstituteCandidatesRequest` | `POST /compras/replacement-requests/search` | `original_catalog_product_id`, `family_id` (obligatoria) y archivo de hasta 15 MB (pdf o imagen). |
| `StoreGoodsReceiptInvoiceRequest` | `POST /compras/goods-receipts/invoice` | Archivo de hasta 10 MB; pdf, imágenes, xlsx, xls o csv. |
| `StoreInventoryProductRequest` | `POST /compras/inventory` | Obligatorios: `reference` y `factory_reference` únicas, `name`, `brand`, `barcode`, `price_full`, `cost`, `other_cost`, `reorder_point`, `provider_id`. `provider_ids` opcional (distintos, existentes). `photo_url` pasa por la regla `SafePhotoUrl` (protección contra SSRF). |
| `UpdateInventoryProductRequest` | `PUT /compras/inventory/{id}` | Todo opcional (`sometimes`); además `import_cost`, `freight_cost` y `handling_cost`. |
| `StoreInventoryProductTechnicalSheetRequest` | `POST /compras/inventory/{id}/technical-sheet` | O bien un archivo, o bien un `link` URL (máx. 2048); uno de los dos es obligatorio. |
| `UploadInventoryProductPhotoRequest` | `POST /compras/inventory/{id}/photo` | `photo` obligatoria, máx. 10 MB, extensiones de `CatalogService::PHOTO_EXTENSIONS`. |
| `ComprasSettingsRequest` | `PUT /compras/settings` | Ver 4.1. |

Los ingresos de mercancía, la liquidación, el cambio de monto y el contenedor/naviera se validan con `$request->validate(...)` dentro del propio controlador, no con un Form Request. Por ejemplo, el ingreso exige `lines.*.warehouse_id` con `Warehouse::operativaRule()` y `pending_remainder_status` dentro de `PurchaseOrder::REMAINDER_STATUSES`.

---

## 7. Permisos, roles y acceso

### 7.1 Permisos

| Permiso | Qué abre |
|---|---|
| `compras.read` | Leer el módulo completo. |
| `compras.write` | Crear (proveedores, órdenes, reclamos, solicitudes, productos). |
| `compras.edit` | Modificar y operar (avanzar órdenes, pagos, documentos, ingresos, agencias). |
| `compras.approve` | Aprobar una orden (además, el gate de Aprobador; ver 7.2). |
| `compras.settings.configure` | Ajustes de Compras. |
| `compras.zona-libre.approve` | Aprobar o rechazar solicitudes de Zona Libre de Bodega. Es un permiso **angosto** a propósito; no reutiliza `compras.approve`. |
| `compras.sustitutos.manage` | Pantalla de Sustitutos completa; permiso angosto de Líder de Operaciones. |
| `compras.limited.view` | Lectura angosta: Ver Órdenes, Logística, Ver Registros de Ingreso e Inventario (sin costos ocultos, sin gestión). |
| `compras.logistica.view` | Solo los GET de "Órdenes - Logística y Envío". Es el permiso del vendedor/diseñador; nunca ve costos. |
| `bodega.read` | Puede leer el detalle y documentos de una orden y usar el buscador de productos (Bodega lo necesita). |
| `ventas_diseno.edit` / `ventas_diseno.read` | Vendedor/diseñador: sustitutos de sus propios proyectos e inventario restringido. |
| `gerencia.view` | Decidir sustitutos de bajo margen. |

Los permisos angostos asignados a una persona concreta van en el seeder `SpecialPermissionSeeder` (nunca como concesión manual descubierta durante QA): por ejemplo `lidercompras@test.com` (Lidercompras) recibe `compras.settings.configure` y `compras.zona-libre.approve`, y `lideroperaciones@test.com` recibe `compras.sustitutos.manage` y `compras.limited.view`.

### 7.2 El gate de Aprobador

`approve`, `approve-amount-change` y `reject-amount-change` comparan `request.user.id` con `ComprasSettings.primary_approver_user_id`. Solo ese usuario (o `superadmin.all`) puede resolver; los demás reciben 403. Nótese que `approve-amount-change` y `reject-amount-change` pasan el middleware con `compras.edit`, y la restricción real vive dentro del método.

### 7.3 Trampa: permisos v1 contra v2

AtlanticERP tiene dos sistemas de permisos. Los efectivos en el JWT (`permissions`, calculados por `effectivePermissionsV2()` desde el rol y la visibilidad de módulos) pueden incluir permisos que `$user->hasPermission()` (v1, nivel de seguridad más `extra_permissions`) **no** conoce. Gerencia y Compras tienen acceso real vía v2 sin tener cargado el permiso v1. Por eso `ReplacementRequestController`, `InventoryController::resolveAccess()` y `CatalogProductSearchController` leen `request->attributes['jwt']['permissions']`. **Si escribes un chequeo de permiso nuevo en este módulo, usa el JWT.** Usar `hasPermission()` produce 403 a usuarios que sí ven la pantalla.

### 7.4 Otras restricciones de acceso

- **Visibilidad UAT:** mientras el módulo Compras esté oculto en la etapa de UAT, `InventoryController::resolveAccess()` devuelve acceso nulo a cualquiera que no sea superadmin, incluso con `compras.read` (`UatVisibilityService`).
- **Alcance por proyecto:** quien no tiene acceso completo (`compras.read`/`compras.sustitutos.manage` para leer, `compras.write`/`compras.sustitutos.manage` para crear) solo ve y crea solicitudes de sustitutos de **proyectos de los que es responsable** (`PipelineCard::projectIdsOwnedBy()`). Sin proyectos propios ve una lista vacía, no un 403.
- **Costo oculto** en el buscador de productos para quien solo entra con `ventas_diseno.edit` (`CatalogProductSearchController::hasCostAccess()`).

---

## 8. Frontend

### 8.1 Rutas y pantallas

| Ruta | Página | Permiso (OR) | Qué hace |
|---|---|---|---|
| `/compras` | redirección | — | Lleva a `/compras/inicio`. |
| `/compras/inicio` | `ComprasHomePage` | `compras.read`, `compras.edit`, `compras.approve`, `compras.zona-libre.approve`, `compras.sustitutos.manage`, `ventas_diseno.edit` | Saludo, calendario Outlook, pendientes, órdenes críticas, estado de compras por etapa, por reordenar, resumen del mes y "Aprobaciones pendientes". |
| `/compras/proveedores` | `ProvidersPage` | `compras.read` | Tabla de proveedores, alta (`ProviderFormDrawer`) y detalle de solo lectura que pasa a edición (`ProviderDetailModal`). |
| `/compras/configuracion` | `ComprasSettingsPage` | `compras.settings.configure` | Monta `ComprasSettingsPanel`. |
| `/compras/ordenes` | `OrdersPage` | `compras.read`, `compras.limited.view` | Ver Órdenes: filtros, chips, tabla. |
| `/compras/ordenes/nueva` | `NewPurchaseOrderPage` | `compras.write` | Nueva Orden (flujo por pasos implícitos). Acepta precarga desde Inventario por estado de navegación, no por URL. |
| `/compras/ordenes/:id` | `OrderDetailPage` | `compras.read`, `bodega.read`, `compras.limited.view`, `compras.logistica.view` | Detalle, edición, aprobación, avance, liquidación, PDF, correo, versiones de idioma y pagos. |
| `/compras/logistica` | `LogisticsPage` | `compras.read`, `compras.limited.view`, `compras.logistica.view` | Tarjetas de envío con línea de tiempo, documentos, contenedor/naviera y avance del remanente. |
| `/compras/ingresos` | `GoodsReceiptsPage` | `compras.read`, `compras.limited.view` | Ver registros de ingreso. |
| `/compras/ingresos/nuevo` y `/compras/ingresos/:id/editar` | `GoodsReceiptWizardPage` | `compras.edit` | Asistente de ingreso; con `:id` es modo corrección. |
| `/compras/agencias` y `/compras/agencias/:id` | `AgenciesSummaryPage`, `AgencyDetailPage` | `compras.read` | Resumen y detalle de agencias (contactos y seguimiento de pago manual). |
| `/compras/pagos` | `PaymentsPage` | `compras.read` | Pagos a proveedores: órdenes antes de "Ordenado" y 5 KPIs. |
| `/compras/reclamos` | `ClaimsPage` | `compras.read` | Garantías y reclamos. |
| `/compras/sustitutos` | `ReplacementRequestsPage` | `compras.read`, `compras.sustitutos.manage`, `ventas_diseno.edit` | Solicitudes y búsqueda de sustitutos. |
| `/compras/reportes` | `ReportsPage` | `compras.read` | Seis reportes con selector de período compartido. |
| `/inventario` | `InventarioPage` | `compras.read`, `compras.limited.view`, `ventas_diseno.read` | Inventario (Productos y Familias). |

Los permisos se declaran en `src/App.tsx` con `RequirePermission`, y el menú los refleja en `components/Sidebar.tsx` (claves `compras-*` y `inventario`). Una ruta nueva exige tocar **ambos** archivos.

### 8.2 Componentes (`components/compras/`)

| Componente | Función |
|---|---|
| `OrderLinesEditor` | Editor de líneas de la orden (producto, cantidad, costo, costo adicional en $ o %, proyecto). |
| `NewProductModal` | Alta de un producto nuevo dentro de una orden, con verificación previa de referencia duplicada. |
| `SalesProjectPicker` | Selector de proyecto de venta por línea ("Ninguno (stock)"). |
| `ProviderFormDrawer` | Alta de proveedor; exporta el formulario que reutiliza `ProviderDetailModal`. |
| `ProviderDetailModal` | Detalle del proveedor: solo lectura, editar, guardar o cancelar. |
| `ProviderMultiPicker` | Selector de varios proveedores para un producto; el primero es el principal (`provider_id`) y el resto viaja en `provider_ids` (SCRUM-1126). |
| `ProviderConfirmationCard` y `ProviderConfirmationPanel` | Carga del documento de confirmación del proveedor y resultado de la validación con IA, siempre como sugerencia editable. |
| `OrderLanguageVersions` | Versiones de la orden en español e inglés: generar, ver y descargar. |
| `PurchaseOrderPaymentsPanel` y `PurchaseOrderPaymentsModal` | Pagos de la orden (el panel dentro de un modal). |
| `ShipmentTimeline` | Línea de tiempo del envío con la fecha de cada etapa. |
| `ReceptionBadge` | Etiqueta de recepción, la misma en Ver Órdenes y Logística. |
| `OriginBadge` | Marca "Origen: Servicios" para órdenes generadas por Servicios. |
| `ProjectBreakdownModal` | Desglose de proyectos por línea cuando la orden tiene más de uno. |
| `NewClaimModal` y `ClaimDetailModal` | Alta de reclamo y ficha completa (fotos, historial de 3 etapas, PDF). |
| `InventoryProductsTable` | Tabla de 21 columnas de Inventario, reutilizada en el detalle de familias. |
| `FamilyCombobox` | Combobox de familia que permite crear una nueva escribiendo el nombre. |
| `WarehouseStockModal` | Detalle de stock por bodega de un producto. |

### 8.3 Patrones del frontend

- **Capa de datos:** `comprasApi` (axios) → `useCompras` (TanStack Query). Las claves de caché empiezan por `compras/`: `compras/providers`, `compras/orders`, etc. Una mutación invalida las claves afectadas; si añades una pantalla que muestra un dato derivado, invalídalo en la mutación.
- **Tipos:** todo el contrato está en `types/compras.ts`. Cuando cambie un payload del backend, se actualiza ahí primero.
- **i18n:** namespace `compras`; las cadenas están en `locales/es/compras.json` y `locales/en/compras.json` y siempre se actualizan **las dos**. No se incrustan glifos en las cadenas.
- **Iconografía:** SVG propios de `components/icons/`; nunca emojis.
- **Duplicación deliberada:** algunos ayudantes (por ejemplo el de estado de remanente) están duplicados por página a propósito (convención del módulo), no centralizados.
- **Asistentes:** `NewPurchaseOrderPage` y `GoodsReceiptWizardPage` son "asistentes implícitos": pasos por renderizado condicional, sin índice numérico.
- **Estado de navegación:** la precarga de Nueva Orden desde Inventario usa el `state` de React Router, para no exponer costo ni cantidad en la URL.
- **Gráficos:** `chart.js` con un plugin propio mínimo para el valor sobre las barras (no se agregó `chartjs-plugin-datalabels`).

---

## 9. Eventos, jobs e integraciones

### 9.1 Eventos de dominio (`App\Shared\Contracts\DomainEvent`)

| Evento | Nombre | Se emite en | Consumidores |
|---|---|---|---|
| `PurchaseOrderReceived` | `compras.purchase-order.received` | `PurchaseOrderController::advance()` al llegar a `recibido` | `Bodega\ReleaseOrdersAwaitingStock`, `Servicios\SyncInsumoReservationOnPurchaseOrderReceived` |
| `OrderLiquidationAssigned` | `compras.order.liquidation-assigned` | `PurchaseOrderController::liquidate()` | ninguno (alcance reducido, SCRUM-210) |
| `PaymentRequested` | `compras.payment.requested` | `PurchaseOrderPaymentController::requestPayment()` | **ninguno** |
| `PaymentRegistered` | `compras.payment.registered` | `PurchaseOrderPaymentController::store()` | ninguno |
| `PaymentAmountChangeApproved` | `compras.payment.amount-change-approved` | `approveAmountChange()` | ninguno |
| `ClaimCreated` | `compras.claim.created` | `PurchaseOrderClaimController::store()` | ninguno |
| `ClaimStatusChanged` | `compras.claim.status-changed` | `PurchaseOrderClaimController::updateStatus()` | ninguno |

Los listeners se registran en `AppServiceProvider::boot()` con `Event::listen`. Ver 13 sobre los eventos sin consumidor.

### 9.2 Jobs (Horizon, Redis)

| Job | Para qué | Notas |
|---|---|---|
| `SendPurchaseOrderEmailJob` | Genera el PDF y envía `PurchaseOrderMail` al proveedor | 3 intentos con `backoff()`. |
| `ValidateProviderConfirmationJob` | Compara el documento de confirmación con las líneas de la orden (IA) | Siempre sugerencia, nunca aprueba ni modifica la orden. Audita cada corrida con el hash del documento. |
| `ExtractProviderBankInfoJob` | Lee la imagen bancaria y sugiere los campos | Nunca escribe en `providers`; lo hace el frontend al precargar el formulario. |
| `MatchInvoiceToOrderJob` | Extrae de una factura la referencia, proveedor y líneas | El modelo solo extrae hechos; `InvoiceOrderMatcher` (PHP) resuelve los ids reales, así que ninguna alucinación puede apuntar a una orden ajena. |
| `CompareSubstituteCandidatesJob` | Compara foto/ficha contra hasta 25 candidatos de la misma familia | Tiempo máximo 300 s. Audita cada corrida. |

Los cuatro jobs de IA extienden `App\Modules\AI\Jobs\BaseAiJob`, escriben un registro en `ai_analyses` (tabla compartida; Auditoría → "Uso de IA" lo lee) y usan `tool_choice` forzado para obtener salida estructurada.

### 9.3 Servicios que conviene conocer

| Servicio | Responsabilidad |
|---|---|
| `GoodsReceiptConfirmationService` | **Única fuente** de "¿está todo recibido?" (`isFullyReceived`), de lo recibido por producto y de confirmar líneas (`confirmLine`, `confirmPendingForOrder`). Lo usan `advance()` y el controlador de ingresos. |
| `InventoryKpiService` | Definiciones de "disponible", "por servir", "por ingresar", "en camino" y estados de stock, en SQL (sin hidratar los ~30 000 productos). Lo comparten Inventario, Reportes e Inicio: **nunca duplicar la fórmula**. |
| `PurchaseOrderPdfService` | PDF con dompdf, con o sin costo, en español o inglés. |
| `PurchaseOrderTranslationService` + `TranslationGuard` | Traducción todo-o-nada con caché por texto y candado por orden. |
| `PurchaseOrderDocumentService`, `PurchaseOrderPaymentService`, `PurchaseOrderClaimPhotoService`, `ProviderBankImageService` | Subida a S3 con `CircuitBreaker`; claves distintas por tipo (`compras/{orderId}/payments/`, etc.). |
| `PurchaseOrderClaimPdfService` | PDF del reclamo. |
| `InvoiceSpreadsheetExtractor` | Convierte una factura Excel/CSV en texto para la IA, con tope de filas y columnas. |
| `SubstituteCandidatePreselector` y `SubstituteSimilarityService` | Preselección en SQL de los mejores candidatos y puntuación exacta con la fórmula de similitud. |
| `ZonaLibreFolioService` | Número consecutivo de solicitud, atómico (`INSERT ... ON CONFLICT` más `lockForUpdate`) y siempre dentro de una transacción externa. |
| `ComprasHomeService` | Datos del Inicio: resumen del mes, pendientes, críticas, etapas y por reordenar. |

### 9.4 Integraciones externas

- **S3 privado** para todos los archivos, con `CircuitBreaker` y URL prefirmadas.
- **Claude (Anthropic)** vía `AnthropicClient`: validación de confirmaciones, extracción bancaria, detección de facturas, comparación de sustitutos y traducción. La clave nunca llega al frontend.
- **Outlook / Microsoft Graph** para "Mi calendario" (`OutlookCalendarController`, mismo endpoint compartido con Ventas & Diseño, filtrado por módulo `compras`).
- **Correo** para el envío de la orden.
- **Bodega**: `KardexService::registrar()` (movimientos de stock), `Warehouse::operativaRule()` (bodegas válidas para ingresar), y el evento `PurchaseOrderReceived`.
- **Ventas & Diseño**: `CatalogProduct`, `SalesProject`, `PipelineCard`, `QuoteListService::searchApprovedProjects()`, `CatalogService` (fotos y ficha técnica).

### 9.5 Auditoría

Los modelos con el trait `LogsAuditTrail` (`PurchaseOrder`, `Provider`, `PurchaseOrderDocument`, `PurchaseOrderTranslation`) registran altas, cambios y bajas en `audit_logs`. Las líneas de orden se auditan como parte de la orden. Los eventos de negocio usan `AuditService` con acciones propias: `compras.purchase_order.pdf_downloaded`, `compras.purchase_order.email_sent`, `compras.purchase_order.translation_generated`, `compras.claim.status_changed` y `compras.claim.pdf_downloaded`. Los pagos, en cambio, **no** escriben una acción propia en `AuditService`: su rastro es la fila inmutable de `purchase_order_payments`.

---
## 10. Configuración y datos semilla

**Variables de entorno propias:** `COMPRAS_TRANSLATION_MODEL` (modelo de Claude para traducir; por defecto `claude-haiku-4-5-20251001`). El resto de `config/compras.php` (líneas por llamada, tiempos de espera, tope de tokens, factor de longitud y límite por minuto) son ajustes técnicos con valores fijos en el archivo. `translation_timeout` (25 s) y `translation_total_timeout` (50 s) deben mantenerse **por debajo** de `fastcgi_read_timeout` de nginx (60 s), o nginx corta la petición antes de que el servidor responda con un error claro.

**Seeders** (`database/seeders/`):

| Seeder | Qué hace | Dónde corre |
|---|---|---|
| `ZonaLibreProviderSeeder` | Crea el proveedor fijo "Zona Libre de Colón" (origen internacional a propósito: pasa por aduana) | Todos los entornos |
| `LafayetteLightingProviderSeeder` | Proveedor real de la integración Lafayette Lighting | Todos menos `testing` |
| `DemoProviderAssignmentSeeder`, `DemoReplacementRequestSeeder` | Datos de demostración | Solo `local`, `dev` y `testing`; **nunca** en `qa` ni `production` |
| `SpecialPermissionSeeder` | Permisos angostos por persona (`extra_permissions`), idempotente por fusión | Todos |

La fila única de `settings` la siembra la migración, así que siempre existe. El campo `zona_libre_provider_id` se rellena al sembrar el proveedor de Zona Libre.

Tras recrear o reseedear un entorno desde cero, verifica los permisos reales de las personas clave: los permisos concedidos a mano durante pruebas no vuelven por sí solos.

---

## 11. Pruebas

### 11.1 Cómo correrlas

- **Backend, ciclo rápido:** `infra/test.sh --filter=<Clase o patrón>` (por ejemplo `--filter=PurchaseOrderLifecycleTest`). **Nunca** `docker compose exec laravel php artisan test` directo: el contenedor hornea `APP_ENV=local` y `QUEUE_CONNECTION=redis`, y un job sin `Bus::fake()` se encolaría de verdad sin ningún error visible.
- **PHPStan:** `vendor/bin/phpstan analyse` dentro del contenedor (nivel 8, obligatorio).
- **Frontend:** `npx vitest run <archivo o patrón>`. **Nunca** `npm test` a secas: el script es `vitest` en modo watch y se cuelga sin dar error.
- **Navegador:** Playwright (`npx playwright test`). Los specs que verifican un gate, un flujo de estado o un permiso que ya se rompió una vez no se borran: se promueven a `e2e/`.
- **Gate antes de cualquier push:** `infra/gate.sh` (PHPStan más suite completa de PHPUnit, deja un recibo por commit). El hook `pre-push` lo exige.
- No sembrar la base mientras `infra/test.sh` corre en segundo plano: contamina los conteos.

### 11.2 Pruebas del backend (`tests/Feature/Compras/` y `tests/Unit/Compras/`)

| Área | Clases |
|---|---|
| Órdenes | `PurchaseOrderControllerTest`, `PurchaseOrderLifecycleTest`, `PurchaseOrderLogisticsTest`, `PurchaseOrderPdfTest`, `PurchaseOrderTranslationTest` |
| Pagos | `PurchaseOrderPaymentControllerTest` |
| Documentos y confirmación | `PurchaseOrderDocumentControllerTest`, `ProviderConfirmationValidationTest` |
| Proveedores | `ProviderControllerTest`, `ProviderBankInfoExtractionTest` |
| Ingreso de mercancía | `GoodsReceiptControllerTest`, `GoodsReceiptInvoiceMatchTest` |
| Inventario | `InventoryControllerTest`, `InventoryKpiServiceSqlParityTest`, `InventoryMultiProviderTest`, `CatalogProductSearchControllerTest` |
| Sustitutos | `ReplacementRequestControllerTest`, `SubstituteCandidatePreselectionTest`, `SubstituteSearchTest` |
| Reclamos | `PurchaseOrderClaimControllerTest` |
| Agencias | `LiquidationAgencyControllerTest` |
| Zona Libre | `ComprasZonaLibreRequestControllerTest` |
| Inicio, reportes y ajustes | `ComprasHomeControllerTest`, `ComprasReportsControllerTest`, `ComprasSettingsControllerTest`, `ApprovedProjectControllerTest` |
| Transversales | `AuditTrailModulePrefixTest`, `PrimaryApproverBackfillSeederTest`, `PhotoUrlSsrfGuardTest`, `SearchQueryParamRobustnessTest` |
| Unitarias | `TranslationGuardTest` |

`InventoryKpiServiceSqlParityTest` comprueba que las reglas en SQL coincidan con las reglas en PHP: si cambias una fórmula de stock en un lado, cambia la otra.

### 11.3 Pruebas del frontend

- **Vitest, páginas** (`src/pages/compras/*.test.tsx`): `ClaimsPage`, `ComprasHomePage`, `GoodsReceiptsPage`, `GoodsReceiptWizardPage` (y su prueba de navegación), `LogisticsPage`, `NewPurchaseOrderPage`, `OrderDetailPage`, `OrdersPage`, `PaymentsPage`, `ProvidersPage`, `ReplacementRequestsPage`, `ReportsPage`.
- **Vitest, componentes:** `ClaimDetailModal`, `FamilyCombobox`, `NewClaimModal`, `NewProductModal`, `OrderLanguageVersions`, `ProviderConfirmationCard`, `ProviderDetailModal`, `ProviderFormDrawer`, `PurchaseOrderPaymentsPanel`.
- **Playwright (`e2e/`):** `compras-permission-gates.spec.ts` (matriz de permisos), más specs de Pre-QA que cubren Inicio, navegación, el lote de proveedores/aprobaciones, Inventario de Bodega, sincronización Bodega-Compras, permisos de Sustitutos y el SQL de inventario.

---

## 12. Recetas para modificar el módulo

Antes de cualquier cambio de estructura de datos, eventos o rutas: spec aprobada y revisión del Arquitecto. Después de cualquier cambio: `infra/test.sh`, PHPStan, `npx vitest run` y, si tocas el contrato, regenerar el OpenAPI (`php artisan scramble:export`) y los exports (`python3 scripts/build-api-exports.py` en el espacio de trabajo).

### 12.1 Agregar un campo a la orden
1. Migración en `database/migrations/tenant/` con `TenantSchemaMigration` (`$module = 'compras'`); revisión del Arquitecto.
2. `PurchaseOrder`: agregar a `$fillable`, a `casts()` y al docblock `@property`.
3. `StorePurchaseOrderRequest` y `UpdatePurchaseOrderRequest`: reglas de validación.
4. `PurchaseOrderController`: `store()`/`update()` si se persiste desde el formulario; `formatSummary()` y `formatDetail()` para exponerlo. Si es un monto, decidir si `maskMoney()` debe anularlo para quien no ve costos.
5. Frontend: `types/compras.ts` (`PurchaseOrderPayload`, `PurchaseOrderSummary`/`Detail`), el formulario (`NewPurchaseOrderPage`, `OrderDetailPage`), y las cadenas en **los dos** `compras.json`.
6. Pruebas de backend y de vitest; actualizar ERD y OpenAPI.

### 12.2 Agregar un estado a la orden
1. Constante `STATUS_*`, ubicación en `statusSequence()` (para proveedor local y para internacional) y, si lleva fecha de etapa, entrada en `STAGE_DATE_COLUMNS` con su columna.
2. `advance()`: reglas de bloqueo y efectos (recuerda `lockForUpdate`).
3. Todo lo que cuenta etapas: `ComprasHomeService::estadoCompras()`, `activeShipmentStatuses()`, `isLiquidated()`, `showsGoodsReceiptLink()`, reportes.
4. Frontend: `utils/orderStatusLabel.ts`, `ShipmentTimeline`, los filtros de `OrdersPage` y las claves `orders.status.*` del i18n (es y en).
5. Pruebas de ciclo de vida (`PurchaseOrderLifecycleTest`) para ambas secuencias.

### 12.3 Agregar un endpoint
1. Ruta en `routes/compras.php` con `permission:`. **Las rutas literales van antes de `/{id}`.**
2. Controlador (resuelve usuario con `resolveUser()`), Form Request para validar, y la lógica reutilizable en un servicio si la comparte otra pantalla.
3. Permiso: decide si hace falta uno angosto nuevo (ver 12.4).
4. Frontend: función en `comprasApi`, hook en `useCompras` (con invalidación), tipo en `types/compras.ts`.
5. Prueba de backend con casos de permiso (403) y de validación (422).

### 12.4 Agregar un permiso
1. Migración que lo inserta en el catálogo de permisos (ver `2026_08_18_100000_add_compras_sustitutos_manage_permission_to_catalog.php` como modelo).
2. Asignarlo a quien corresponda en `SpecialPermissionSeeder` (nunca a mano).
3. Middleware de la ruta y, si el controlador lo comprueba, **léelo del JWT** (ver 7.3).
4. Frontend: `RequirePermission` en `App.tsx` y el ítem del `Sidebar`.
5. Añadir un caso a `e2e/compras-permission-gates.spec.ts`.

### 12.5 Agregar un parámetro configurable
Migración sobre `settings`; `$fillable` y `casts()` de `ComprasSettings`; regla en `ComprasSettingsRequest`; `format()` de `ComprasSettingsController`; campo en `ComprasSettingsPanel` y su i18n. **Nunca** una constante en el código: es regla del proyecto.

### 12.6 Agregar una función con IA
1. Job que extienda `BaseAiJob`, con `tool_choice` forzado y un registro en `ai_analyses`.
2. Control de costo: limitador por usuario, orden o proveedor, como en los controladores existentes.
3. Auditar cada corrida con el hash del archivo y lo que devolvió el modelo.
4. El resultado se muestra siempre como **sugerencia editable**; el job nunca aprueba ni escribe datos de negocio.
5. Diseño con el AI Architect y revisión de Cybersecurity antes de programar (ver los ADR existentes: SCRUM211, SCRUM224, SCRUM245, SCRUM819).

---

## 13. Problemas conocidos y deuda

Esta lista es una fotografía de lo observado al escribir el manual (2026-10-12). Nada de esto se corrigió.

**Reglas fijas en el código** (viola la regla de parametrización; ver 4.3): umbral de 3 días de orden crítica, 7 % de ITBMS de proveedor local, pesos de la calificación del proveedor y 50 % del aviso de oportunidad de compra.

**Avisos prometidos y no implementados.** SCRUM-252 dice que "Compras notifica a Aprobador que hay que pagar", pero `PaymentRequested` no tiene ningún consumidor, y tampoco hay una regla de notificación que lo cubra (el motor de reglas de CRM solo observa `Project`, `ProjectActivity` y `ProjectDocument`). Los otros cuatro eventos sin consumidor (`PaymentRegistered`, `PaymentAmountChangeApproved`, `ClaimCreated`, `ClaimStatusChanged`) no tienen un aviso prometido en los tickets revisados. Corregirlo requiere un ticket nuevo.

**Rendimiento.** Varios listados cargan todo en PHP y paginan después: el chip `critical` y los filtros de estado de pago de `PurchaseOrderController::index()`, y los listados de reclamos y de solicitudes de sustitutos (`Pagination::ofArray`). Con volumen alto habrá que moverlos a SQL. `InventoryKpiService` ya pasó a SQL con agregados para no hidratar el catálogo (~30 000 productos).

**Totales.** `total_amount` no suma los costos adicionales por línea (se guardan, pero no entran al total).

**Funcionalidad parcial o diferida**
- `Provider::completePercent()` ("entregas completas", 35 % de la calificación) devuelve siempre `null`; la calificación se renormaliza con los otros dos componentes.
- REQ-114: el pendiente "proveedor sin responder 5+ días" no existe (no hay campo que registre cuándo se envió algo al proveedor ni cuándo respondió).
- `claims.service_ticket_id` está reservado para vincular con Servicios, pero ningún código lo consulta.
- El seguimiento de pago de las agencias es carga manual, sin historial.
- Liquidación de una orden (`liquidate()`) solo asigna la agencia y emite un evento; no cambia el estado ni avisa a nadie.

**Comentarios desactualizados en el código** (el código manda):
- `AgenciesSummaryPage` dice que "liquidada" es haber llegado a "En aduana"; la regla real (`PurchaseOrder::isLiquidated()`) es haber llegado a **Recibido**.
- El comentario de `ReportsPage` dice que REQ-202 no está; el reporte "Oportunidad de compra" sí existe (`purchaseOpportunity`).
- Un comentario de `PurchaseOrder` dice que la derivación del texto de `pendiente_liquidar` "no tiene equivalente en PHP"; es correcto, pero implica que cualquier consumidor de la API debe aplicar la misma regla.

**Trampas de implementación**
- Permisos v1 contra v2 (ver 7.3).
- Rutas literales antes de `/{id}`.
- `stock_quantity` puede ser `NULL`: la recepción de mercancía lo trata con `COALESCE`, y el stock por bodega se escribe **solo** vía `KardexService::registrar()`.
- `catalog_product_id` puede ser `NULL` en líneas cuyo producto se borró; filtra los nulos antes de agrupar.
- Concurrencia: `advance()` y `confirmPendingForOrder()` dependen de `lockForUpdate()`.
- Mientras Compras esté oculto por la etapa de UAT, `InventoryController::resolveAccess()` niega el acceso hasta a quienes tienen `compras.read`.
- Los Form Requests no autorizan (`authorize() = true`); olvidar el middleware `permission:` deja una ruta abierta a cualquier usuario autenticado.
- El frontend local puede estar desfasado respecto a `origin/dev`: antes de documentar o diagnosticar, compara contra el remoto.

---

## 14. Glosario

| Término | Significado |
|---|---|
| **OC** | Orden de compra. |
| **Aprobador** | Usuario aprobador configurado en `primary_approver_user_id`. |
| **Lidercompras** | Líder de Compras (`lider_compras`). |
| **Zona Libre** | Modalidad de orden y proveedor fijo "Zona Libre de Colón"; pasa por aduana y liquidación. |
| **Liquidación / agencia de liquidación** | Gestión aduanal de una orden de Zona Libre. Una orden está "liquidada" al llegar a Recibido. |
| **Remanente** | Lo que falta recibir tras un ingreso parcial, con su propia etapa. |
| **Por ingresar** | Línea de ingreso aún no confirmada (`confirmed_at` nulo); ya llegó físicamente pero no cuenta como stock. |
| **En camino** | Unidades pedidas que todavía no llegaron de ninguna forma. |
| **Por servir** | Unidades con un proyecto asignado en su línea de origen. |
| **Disponible** | Stock menos "por servir" menos lo ubicado en bodegas que excluyen disponibilidad. |
| **Kardex** | Registro de movimientos de stock por bodega, de Bodega. |
| **Sustituto** | Producto propuesto para reemplazar otro; su aprobación depende del margen. |
| **Reserva Servicios** | Stock reservado para Servicios; recibe órdenes generadas automáticamente (`origin_module = servicios`). |
| **Tool choice forzado** | Técnica para obligar a la IA a responder con una estructura fija. |
| **v1 / v2 (permisos)** | Los dos sistemas de permisos coexistentes; ver 7.3. |
| **Gate** | En AtlanticERP, la verificación local previa al push (`infra/gate.sh`). |

<!-- nav-manuales:inicio -->
---
**Navegación:** [Índice de manuales](README.md) · ← [Servicios](servicios.md) · [Bodega](bodega.md) →
<!-- nav-manuales:fin -->
