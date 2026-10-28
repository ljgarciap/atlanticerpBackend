# Desglose PM — Bloque B3 "Zona Libre de Colón" (SCRUM-433→445, REQ-363→375)

**Fuente de diseño:** `docs/adr/ADR-SCRUM433-445-zona-libre-colon.md` (aprobado por Luis, 2026-07-24)
**Mockups:** `3C__Bodega_NuevaOrdenZL.html` (formulario), `3D__Bodega_OrdenesZL.html` (bandeja)
**Épico:** SCRUM-329 (Bodega)

Este documento desglosa el ADR en tareas atómicas para Backend Dev y Frontend Dev. No define
fechas ni estimados — eso no es información del PM. No implementa nada — cada tarea la ejecuta el
dev asignado, leyendo primero el ADR y los archivos de referencia listados.

---

## Paquete Backend (1 Backend Dev, en orden de dependencia)

### B-1. Migraciones (tenant, módulo `compras` salvo donde se indica)
**Cierra:** base de SCRUM-433, 436, 438, 439, 440
**Depende de:** nada — primera tarea de la cadena
**Referencias de patrón:**
- `database/migrations/tenant/2026_07_20_100009_add_mark_approver_to_compras_settings_table.php`
  (patrón exacto para agregar `zona_libre_provider_id` a `compras_settings`: `foreignId(...)
  ->nullable()->constrained('providers')->nullOnDelete()`, mismo bloque `TenantSchemaMigration`)
- `database/migrations/tenant/2026_07_20_100005_add_compras_settings_configure_permission_to_catalog.php`
  (patrón exacto para insertar el permiso nuevo en `permission_catalog`)
- Migraciones de `ReplacementRequest` (buscar `create_replacement_requests_table` y la de sus
  líneas) como plantilla de forma de tabla para `bodega_zona_libre_requests` +
  `bodega_zona_libre_request_lines`

**Tareas:**
1. `create_bodega_zona_libre_requests_table` — columnas según ADR sección 1: `status`
   (pendiente|aprobada|rechazada), `requested_by`, `approved_by`, `rejected_by`,
   `rejection_reason`, `generated_order_id` (FK nullable → `purchase_orders`, `nullOnDelete`),
   `shipping_type` (default `terrestre`), `estimated_arrival_date`, `order_number`
   (**decidir tipo/unicidad junto con el punto abierto de numeración, ver sección 4**), `notes`,
   timestamps. Módulo `compras` (mismo módulo que `ReplacementRequest`, no `bodega` — el modelo
   vive en `App\Modules\Compras\Models` según ADR, aunque lo cree Bodega).
2. `create_bodega_zona_libre_request_lines_table` — `bodega_zona_libre_request_id` (FK, cascade),
   `catalog_product_id` (FK), `quantity`, `unit_cost_snapshot`, timestamps.
3. `add_zona_libre_provider_id_to_compras_settings_table` — `foreignId('zona_libre_provider_id')
   ->nullable()->constrained('providers')->nullOnDelete()`.
4. `add_zona_libre_approve_permission_to_catalog` — insertar `compras.zona-libre.approve` en
   `permission_catalog` (módulo `compras`), siguiendo el docblock ya usado para
   `compras.settings.configure` (permiso angosto, no atado a ningún `security_level`, grant
   individual vía `extra_permissions` a Lidercompras — o a quien Luis designe como aprobador — más
   `superadmin.all` que ya lo bypassa).

### B-2. Seeder del Provider "Zona Libre de Colón"
**Cierra:** SCRUM-433
**Depende de:** B-1 (tabla `providers` ya existe, solo falta el registro)
**Detalle:** nuevo seeder (o entrada en un seeder existente de Providers) que crea el `Provider`
"Zona Libre de Colón" con los datos visibles en el mockup (`Ana Torres · +507 6555-9090 · Calle
Terranova, Zona Libre de Colón` — confirmar con Luis si esos datos de contacto son reales o
placeholder de mockup antes de sembrarlos) y setea `compras_settings.zona_libre_provider_id` con
su ID. Idempotente (create-if-not-exists por nombre), corre en todos los entornos — mismo criterio
que `CoreUserSeeder`.

### B-3. Modelos `BodegaZonaLibreRequest` + `BodegaZonaLibreRequestLine`
**Cierra:** base de SCRUM-436, 439, 440
**Depende de:** B-1
**Referencia:** `app/Modules/Compras/Models/ReplacementRequest.php` — mismo patrón: constantes
`STATUS_*`, `getTable()` calificado por tenant (`{$tenant->slug}_compras....`), relaciones
`BelongsTo` a `Provider`/`User`/`PurchaseOrder`, `casts()` para los campos numéricos/fecha.

### B-4. Controller Bodega: `BodegaZonaLibreRequestController`
**Cierra:** SCRUM-434, 435, 436, 437, 438, 439, 442, 443, 444, 445
**Depende de:** B-3
**Rutas (`routes/bodega.php`, prefijo `zona-libre`):**
- `GET  /bodega/zona-libre/requests` — bandeja (SCRUM-442/443), soporta `?status=` para los 4
  chips (Todas/pendiente/aprobada/rechazada). Formato de fila: N° orden, proveedor (siempre "Zona
  Libre de Colón"), fecha creada, resumen de productos (nombre si 1 línea, "N productos" si
  varios), monto total, llegada estimada, estado, acción disponible según estado.
- `POST /bodega/zona-libre/requests` — crear (SCRUM-434→439): valida al menos 1 línea con
  `quantity > 0` (RN1 de REQ-364/366 — bloquear 0 tanto al agregar como al editar), asigna
  `order_number` consecutivo (ver punto abierto), estado inicial `pendiente`.
- `GET  /bodega/zona-libre/requests/{id}` — detalle, incluye `rejection_reason` cuando aplica
  (SCRUM-444, botón "Ver motivo").
- `POST /bodega/zona-libre/requests/{id}/remind` — SCRUM-445, dispara email real a Lidercompras (o al
  usuario configurado como aprobador — reusar el mismo mecanismo de mail ya usado en el proyecto,
  ej. el que dispara `OrderService::notifyNoAssistant()` o el flow de Outlook real ya integrado en
  SCRUM-66/177, no craft SMTP nuevo).

Middleware: `permission:bodega.write` para creación/remind, `permission:bodega.read` para
lectura — mismo patrón que `adjustment-requests` en el mismo archivo de rutas.

**Regla dura del proyecto a respetar acá:** SCRUM-435/436 ("producto agregado desaparece de la
lista disponible, reaparece si se quita, sin recargar") es responsabilidad del Frontend
(sincronización de estado local del carrito) — el backend solo necesita exponer qué productos
están disponibles del proveedor (vía el endpoint de catálogo, punto abierto #2) y aceptar/rechazar
líneas por `catalog_product_id`.

### B-5. Controller Compras: decisión de Lidercompras
**Cierra:** SCRUM-440
**Depende de:** B-3, B-4 (mismo modelo)
**Referencia exacta:** `ReplacementRequestController::generateOrder()` — mismo mecanismo: al
aprobar, crea un `PurchaseOrder::create([...])` completamente normal con `status =
STATUS_POR_APROBAR` y **`modality = PurchaseOrder::MODALITY_ZONA_LIBRE`** (esta constante ya
existe en `PurchaseOrder.php`, ver hallazgo en sección 4 — no hay que crearla), y guarda el
`id` resultante en `generated_order_id`. Al rechazar, exige `rejection_reason` (nunca automático,
ADR sección 3) y nunca crea `PurchaseOrder`.

**Rutas (`routes/compras.php`, prefijo `zona-libre/requests`):**
- `POST /compras/zona-libre/requests/{id}/approve` — permiso `compras.zona-libre.approve`
- `POST /compras/zona-libre/requests/{id}/reject` — mismo permiso, `reason` requerido en el
  request

**RN de SCRUM-440 a validar en el controller:** una solicitud ya `aprobada`/`rechazada` no se
puede volver a aprobar/rechazar (idempotencia de estado); Bodega no puede editar
(`PUT`/`DELETE` no existen en las rutas de B-4 a propósito) una solicitud que ya salió de
`pendiente` — reflejar esto también como test negativo (B-6).

### B-6. Tests
**Cierra:** cobertura de todos los anteriores
**Depende de:** B-3, B-4, B-5
- Feature test `BodegaZonaLibreRequestControllerTest` — camino feliz (crear, listar, filtrar por
  estado, detalle, remind) **y camino de ruptura**: crear con 0 líneas (rechazado), crear con
  `quantity <= 0` en una línea (rechazado), listar con `bodega.read` pero sin `bodega.write`
  (bloqueado en creación).
- Feature test `ComprasZonaLibreRequestControllerTest` — approve genera `PurchaseOrder` con
  `modality = zona_libre` y `status = por_aprobar`; reject exige `reason`, nunca genera
  `PurchaseOrder`; approve/reject sobre una solicitud ya resuelta → 422/409, no doble-procesa;
  approve/reject sin `compras.zona-libre.approve` → 403.
- Correr con `infra/test.sh` (nunca `docker compose exec` directo, ver nota del CLAUDE.md del
  proyecto) + `vendor/bin/phpstan analyse` (Level 8, sin errores nuevos).

---

## Paquete Frontend (1 Frontend Dev, arranca cuando el contrato de API de B-4/B-5 esté cerrado)

### F-1. Página "Nueva Orden — Zona Libre" (mockup `3C__Bodega_NuevaOrdenZL.html`)
**Cierra:** SCRUM-433, 434, 435, 436, 437, 438, 439, 441
**Depende de:** contrato de `POST/GET /bodega/zona-libre/requests` cerrado (B-4)
**Reuso confirmado:**
- `OrderLinesEditor` (`atlanticerp-frontend/src/components/compras/OrderLinesEditor.tsx`) para el
  carrito de líneas (SCRUM-436) — props `providerId`/`draft`/`onChange` ya existentes.
- `OrderItemWarehousesModal.tsx` (`atlanticerp-frontend/src/components/`) para "Ver bodegas"
  (SCRUM-437) — mismo modal usado en SCRUM-403, sin lógica nueva.

**Piezas:**
1. Panel "Proveedor" — sin selector, muestra "Zona Libre de Colón" fijo + aviso visible (mockup:
   banner ámbar "Bodega solo puede generar órdenes del proveedor Zona Libre de Colón..."). El
   nombre/ID del proveedor viene del backend (`compras_settings.zona_libre_provider_id`
   resuelto), **nunca hardcodeado en el frontend**.
2. Buscador + tabla de productos del proveedor (SCRUM-434) — consume el endpoint de catálogo
   filtrado por `provider_id` (punto abierto #2, sección abajo). Input a 0 por defecto, botón
   "Agregar a la orden" deshabilitado/bloqueado con cantidad 0 (RN1).
3. Sincronización sin recargar (SCRUM-435): al agregar una línea, ese producto desaparece de la
   tabla de disponibles; al quitarla del carrito, reaparece — estado local (no round-trip al
   servidor entre cada agregar/quitar).
4. Carrito de líneas (SCRUM-436) vía `OrderLinesEditor` — columnas Producto/Ref. fábrica/Ref.
   pública/Cantidad/Costo/Subtotal, Editar/Quitar, total recalculado. Bloquear edición a 0/valor
   inválido (RN1).
5. Detalles del envío (SCRUM-438) — Modalidad "Zona Libre" fija y deshabilitada (`<select
   disabled>` en el mockup), Tipo de envío default Terrestre pero editable (select con
   aéreo/marítimo/terrestre), Llegada estimada (date picker).
6. Guardar orden (SCRUM-439) — valida al menos 1 línea antes de habilitar el botón, muestra
   errores del backend si la validación server-side falla.
7. Panel de confirmación (SCRUM-441) — tras guardar: número de orden + "Estado: Pendiente por
   aprobar de Lidercompras (Compras)" + botón "Ir a Ver Órdenes" (navega a F-2) + link "Crear una
   nueva" que **reinicia el formulario sin salir de la pantalla** (no un `location.reload()` como
   en el mockup — reset de estado React).

### F-2. Página "Bandeja — Órdenes Zona Libre" (mockup `3D__Bodega_OrdenesZL.html`)
**Cierra:** SCRUM-442, 443, 444, 445
**Depende de:** contrato de `GET /bodega/zona-libre/requests` (con filtro `status`) cerrado (B-4)
**No reusar** `usePurchaseOrders`/`OrdersPage` como bloque — confirmado en el ADR: esta bandeja
muestra solicitudes que nunca llegan a ser `PurchaseOrder` mientras están `pendiente`/`rechazada`.
Hook nuevo `useZonaLibreRequests` (mismo patrón de TanStack Query que el resto de Bodega).

**Piezas:**
1. Tabla: N° orden / Proveedor / Fecha creada / Productos (nombre si 1, "N productos" si varios,
   ya resuelto por el backend en B-4) / Monto / Llegada estimada / Estado / Acción.
2. Botón "+ Nueva orden de compra" → navega a F-1.
3. 4 chips de filtro mutuamente excluyentes (SCRUM-443): Todas / Por aprobar / Aprobadas /
   Rechazadas — mismo patrón visual de chip ya usado en Pipeline (`.chip`/`.chip.active`).
4. Acción por estado (SCRUM-444):
   - `pendiente` → botón "Recordar" → llama `POST .../remind` (SCRUM-445), toast de
     confirmación.
   - `aprobada` → sin botón, solo texto "Sigue el flujo normal de Compras" (opcionalmente linkear
     al detalle del `PurchaseOrder` real vía los componentes ya existentes de Ver Órdenes, sin
     duplicar esa UI — sugerido en el ADR, no obligatorio para cerrar el ticket).
   - `rechazada` → botón "Ver motivo" → modal o panel con `rejection_reason`.

### F-3. i18n
**Cierra:** transversal a F-1/F-2
Strings nuevos en el/los archivo(s) de i18n del módulo Bodega — sin glyphs de emoji embebidos en
ningún string (regla SCRUM-56, ver punto siguiente).

### F-4. Iconografía — regla SCRUM-56 (nota, no un ticket separado, aplica dentro de F-1/F-2)
El mockup HTML usa emoji como placeholders visuales (🔒 en el banner de proveedor fijo, 🔖 en los
banners informativos "pendiente por aprobar"/"pendiente de integración", 🔔 en el botón Recordar,
✓/✕ en los toasts/alerts de ejemplo). **Ninguno de estos emoji va a la implementación real** — el
cliente prohibió iconografía de emoji en toda la UI (ver "Iconografía" en el `CLAUDE.md` del
proyecto). Usar los componentes de `atlanticerp-frontend/src/components/icons/` (SVG outline,
`stroke=currentColor`) para cada uno de esos casos, o directamente texto sin icono donde el resto
del sistema ya lo resuelve así (ej. SCRUM-75/78 reemplazó un emoji del mockup por `IcoPencil`).

---

## Orden de trabajo sugerido

```
B-1 (migraciones + permiso)
  └─▶ B-2 (seeder Provider) ──┐
  └─▶ B-3 (modelos)            │
        └─▶ B-4 (controller Bodega)   ◄── contrato de API cerrado acá
        └─▶ B-5 (controller Compras)  ◄── puede ir en paralelo con B-4 (mismo modelo, distinto
                                           controller/módulo, sin archivos compartidos)
              └─▶ B-6 (tests, cubre B-3/4/5)

Frontend arranca cuando B-4 (y el contrato de B-4 en particular, que es lo único que consume
Bodega) está cerrado — no necesita esperar a B-5/B-6 para empezar F-1/F-2, aunque sí necesita que
B-1/B-2 ya estén corridas en el entorno local (Provider sembrado) para probar contra datos reales.

F-1 (Nueva Orden) y F-2 (Bandeja) pueden ir en paralelo entre sí si el Frontend Dev decide
dividir su propio tiempo así — ambas dependen del mismo contrato, no una de la otra. F-1 linkea a
F-2 al guardar (SCRUM-441) y F-2 linkea a F-1 (botón "+ Nueva orden") — dependencia de navegación,
no de implementación: cualquiera de las dos puede construirse primero con un route stub para la
otra.
```

---

## Puntos abiertos para confirmar con Backend Dev al implementar

El ADR señala 2 puntos como no resueltos a propósito ("Backend Dev confirma esto al implementar").
Investigación adicional de este desglose encontró contexto real para ambos — se deja como
**decisión de Backend Dev**, no resuelto acá:

**1. Generador de número consecutivo (`order_number`, REQ-369 RN2):**
- `PurchaseOrder` **no tiene ningún campo `order_number`** (confirmado por grep — se identifica
  solo por `id`), así que no hay nada que "reusar" de ahí directamente.
- Sí existen dos precedentes de un patrón *Folio + tabla de contador* ya usado en el repo:
  `App\Modules\VentasDiseno\Services\QuoteFolioService` (`ventas_diseno_quote_folio_counters`) y
  `App\Modules\Bodega\Services\OrderFolioService` (`bodega_order_folio_counters`, usado por
  `OrderService::store()` vía `$this->folioService->next()`).
- Decisión de Backend Dev: crear un tercer `FolioService`/tabla de contador dedicado a
  `bodega_zona_libre_requests` (siguiendo ese mismo patrón), o resolverlo con una secuencia
  Postgres nativa sobre la tabla nueva si no hace falta el mismo nivel de control que Folio
  ofrece (reinicio anual, prefijo, etc. — confirmar si Zona Libre necesita algo de eso, el
  mockup solo muestra un número plano incremental).

**2. Endpoint de búsqueda de catálogo filtrado por `provider_id` (SCRUM-434):**
- **Ya existe** un endpoint genérico reusable: `GET /compras/products` →
  `CatalogProductSearchController::index()` (`routes/compras.php:137`), que ya filtra por
  `?provider_id=` y expone `cost` (necesario acá, a diferencia del buscador de Ventas & Diseño
  que lo oculta a propósito).
- **Gap real:** ese endpoint está gateado con `middleware('permission:compras.read')` — Bodega
  crea estas órdenes bajo `bodega.write`/`bodega.read`, no `compras.read`, así que el usuario de
  Bodega típico no pasaría ese middleware tal cual.
- Ya existe precedente en el mismo repo para resolver exactamente este tipo de choque sin
  duplicar lógica: `InventoryController::resolveAccess()` (usado en las rutas de
  `routes/compras.php` bajo `inventory/`) chequea el acceso **dentro** del controller (OR de 2
  permisos), en vez de expresarlo en el middleware de la ruta — porque Laravel no soporta un OR
  de 2 `permission:` en la misma ruta.
- Decisión de Backend Dev: (a) replicar ese patrón en `CatalogProductSearchController` para
  aceptar también `bodega.read`, o (b) agregar una ruta delgada en `routes/bodega.php` que
  reusa el mismo controller/lógica bajo `permission:bodega.read`, sin tocar la ruta de Compras.
  Ambas evitan crear un endpoint nuevo desde cero — la ADR ya descarta esa opción.

---

## Notas adicionales para Backend Dev (no bloqueantes, contexto de apoyo)

- El campo `modality` con la constante `PurchaseOrder::MODALITY_ZONA_LIBRE` **ya existe** en
  `PurchaseOrder.php` (viene de REQ-153, sprint2 Logística) — B-5 solo necesita setearlo al
  crear, no crear la constante.
- `AdjustmentRequest`/`AdjustmentRequestLine` (Solicitud de Ajuste) viven en
  `App\Modules\Bodega\Models`, no en `Compras` — a diferencia de lo que dice el ADR para
  `BodegaZonaLibreRequest` (que sí especifica `App\Modules\Compras\Models`, siguiendo a
  `ReplacementRequest`). Confirmar con el Arquitecto si esa asimetría de módulo es intencional
  antes de crear el archivo, dado que hay 2 precedentes apuntando a 2 módulos distintos.
