# Contrato de API — Bloque B3 "Zona Libre de Colón" (SCRUM-433→445)

**Fuente de verdad para Frontend Dev.** Backend completo (B-1→B-6 del desglose de PM), tests +
PHPStan Level 8 en verde. Ver `docs/adr/ADR-SCRUM433-445-zona-libre-colon.md` para el diseño y
`docs/backlog/bloque-b3-desglose-20260724.md` para el desglose de tareas.

---

## Decisiones tomadas al implementar (los 2 puntos abiertos que dejó el desglose de PM)

### 1. Generador de `order_number`

Se creó un **tercer FolioService** (`App\Modules\Compras\Services\ZonaLibreFolioService`), mismo
mecanismo exacto que `QuoteFolioService`/`OrderFolioService` (tabla contadora de una sola fila +
`INSERT ... ON CONFLICT DO NOTHING` + `lockForUpdate()` dentro de una transacción — atómico bajo
concurrencia, sin colisiones). Diferencia deliberada: **sin la dimensión de año ni prefijo de
texto** — el mockup (`3D__Bodega_OrdenesZL.html`) solo muestra un número plano incremental, no un
folio tipo `PED-2026-0001`. `order_number` es un **integer** consecutivo (1, 2, 3, …), no un
string. El frontend debe renderizarlo tal cual (ej. `"Orden #3"`), sin asumir formato de texto.

### 2. Endpoint de catálogo por proveedor (elección entre las 2 opciones que dejó abiertas el PM)

Se descartó replicar `InventoryController::resolveAccess()` (esa lógica existe para un caso
distinto: ocultar costo en modo restringido). En su lugar se descubrió que
`App\Shared\Http\Middleware\CheckPermission` **ya soporta un OR de permisos separados por coma**
en el string del middleware (`permission:compras.read,bodega.read`) — mismo mecanismo ya usado en
`GET /compras/orders/{id}`. Se extendió el middleware de la ruta existente en vez de crear una
ruta nueva o duplicar el controller:

```diff
- Route::get('/products', [CatalogProductSearchController::class, 'index'])->middleware('permission:compras.read');
+ Route::get('/products', [CatalogProductSearchController::class, 'index'])->middleware('permission:compras.read,bodega.read');
```

Bodega **sí** ve el campo `cost` en la respuesta de este endpoint (igual que Compras) — confirmado
en el desglose de PM que es necesario para armar el carrito, a diferencia del buscador de Ventas &
Diseño que lo oculta a propósito.

---

## Endpoints — lado Bodega (`/api/bodega/zona-libre`)

Todas requieren JWT válido. `bodega.read` para lectura, `bodega.write` para escritura.

### `GET /api/bodega/zona-libre/requests`

Bandeja (3D). Query param opcional `status` (`pendiente` | `aprobada` | `rechazada`; ausente o
`todas` = sin filtro).

```json
{
  "data": [
    {
      "id": 7,
      "order_number": 3,
      "provider_name": "Zona Libre de Colón",
      "created_at": "2026-07-24T15:00:00+00:00",
      "products_summary": "3 productos",
      "total_amount": 245.5,
      "estimated_arrival_date": "2026-08-10",
      "shipping_type": "terrestre",
      "status": "pendiente",
      "requested_by_name": "Nombre Apellido",
      "generated_order_id": null
    }
  ]
}
```

- `products_summary`: nombre del producto si la solicitud tiene 1 sola línea, o `"N productos"`
  si tiene varias — ya resuelto por el backend, el frontend no necesita recalcularlo.
- `provider_name`: siempre resuelto desde `compras_settings.zona_libre_provider_id` — nunca
  hardcodear "Zona Libre de Colón" en el frontend, puede venir `null` si el superadmin borró la
  configuración (caso borde, mostrar "—" o similar).

### `POST /api/bodega/zona-libre/requests`

Crea la solicitud (3C). `bodega.write`.

**Request:**
```json
{
  "shipping_type": "terrestre",
  "estimated_arrival_date": "2026-08-10",
  "notes": "Opcional",
  "lines": [
    { "catalog_product_id": 42, "quantity": 5 },
    { "catalog_product_id": 43, "quantity": 2 }
  ]
}
```

- `shipping_type`: opcional, `aereo|maritimo|terrestre`, default `terrestre` si se omite.
- `estimated_arrival_date`: opcional, formato fecha ISO.
- `lines`: **requerido, mínimo 1**. Cada línea requiere `catalog_product_id` (debe existir en el
  catálogo y pertenecer al proveedor Zona Libre configurado — el backend revalida esto server-side,
  no confía en que el frontend ya filtró el buscador) y `quantity` **entero > 0**.
- El backend calcula `unit_cost_snapshot` desde `CatalogProduct.cost` — el frontend **nunca** manda
  costo en el payload de creación.

**Respuesta 201** — mismo shape que el detalle (ver `GET .../requests/{id}` abajo), incluye
`order_number` recién asignado y `lines` con el detalle completo.

**Errores:**
- `422` — `lines` vacío, `quantity <= 0`, o algún `catalog_product_id` no pertenece al proveedor
  Zona Libre configurado (mensaje: `"Uno o más productos no pertenecen al proveedor Zona Libre de
  Colón."`), o no hay proveedor configurado (`"No hay un proveedor de Zona Libre configurado."`).
- `403` — sin `bodega.write` (incluye un actor solo-Compras, que no tiene permisos de Bodega).

### `GET /api/bodega/zona-libre/requests/{id}`

Detalle. `bodega.read`.

```json
{
  "id": 7,
  "order_number": 3,
  "provider_name": "Zona Libre de Colón",
  "created_at": "2026-07-24T15:00:00+00:00",
  "products_summary": "3 productos",
  "total_amount": 245.5,
  "estimated_arrival_date": "2026-08-10",
  "shipping_type": "terrestre",
  "status": "rechazada",
  "requested_by_name": "Nombre Apellido",
  "generated_order_id": null,
  "notes": "Opcional",
  "rejection_reason": "Precio fuera de rango",
  "approved_by_name": null,
  "rejected_by_name": "Yirena Teng",
  "lines": [
    {
      "id": 15,
      "catalog_product_id": 42,
      "reference": "REF-001",
      "description": "Bombillo LED 12W",
      "quantity": 5,
      "unit_cost_snapshot": 8.5,
      "subtotal": 42.5
    }
  ]
}
```

`rejection_reason` viene `null` salvo `status = "rechazada"` — botón "Ver motivo" del mockup 3D lee
este campo directo del detalle, no hace falta un endpoint aparte.

### `POST /api/bodega/zona-libre/requests/{id}/remind`

Botón "Recordar" (3D). `bodega.write`. Sin body. Envía email + notificación in-app al rol
`lider_compras` (Yirena) vía el `NotificationService` ya existente del proyecto.

- **200** — `{ "message": "Recordatorio enviado." }`
- **422** — la solicitud ya no está `pendiente`, o no hay ningún usuario activo con el rol
  `lider_compras` configurado.

---

## Endpoints — lado Compras (`/api/compras/zona-libre/requests`)

Requieren el permiso angosto **`compras.zona-libre.approve`** (nunca `compras.approve`, que es un
concepto distinto — aprobación de flete aéreo de Mark).

### `POST /api/compras/zona-libre/requests/{id}/approve`

Sin body. Genera un `PurchaseOrder` real (`modality = "zona_libre"`, `status = "por_aprobar"`),
copia las líneas, y marca la solicitud `aprobada` con `generated_order_id` apuntando a la orden
recién creada. De ahí en más la orden sigue el flujo normal de Compras (Ver Órdenes, Logística,
Pagos, Ingreso de Mercancía) — ningún endpoint nuevo hace falta para eso, es una `PurchaseOrder`
indistinguible de cualquier otra.

- **200** — `{ "order_id": 88 }`
- **422** — la solicitud ya fue resuelta (`aprobada`/`rechazada`), no tiene líneas, o no hay
  proveedor Zona Libre configurado.
- **403** — sin `compras.zona-libre.approve` (incluye un actor solo-Bodega).
- **404** — id inexistente.

### `POST /api/compras/zona-libre/requests/{id}/reject`

**Request:**
```json
{ "rejection_reason": "Precio fuera de rango" }
```

`rejection_reason` **requerido**, string no vacío. Nunca genera `PurchaseOrder`.

- **200** — `{ "message": "Solicitud rechazada." }`
- **422** — falta `rejection_reason`, o la solicitud ya fue resuelta.
- **403** — sin `compras.zona-libre.approve`.

---

## Endpoint reusado — catálogo de productos por proveedor

`GET /api/compras/products?provider_id={id}&search={texto}` (`CatalogProductSearchController`,
ya existente, ver decisión #2 arriba). Bodega lo usa para armar el buscador del formulario 3C,
filtrando por el `provider_id` de Zona Libre (resuelto vía `GET /api/compras/settings` →
`zona_libre_provider_id`, o expuesto directamente si Frontend prefiere leerlo de un endpoint
propio — no existe hoy un endpoint dedicado "provider de zona libre", el frontend arma la llamada
con el id que ya tenga disponible del contexto de Ajustes/Home).

```json
{
  "fuzzy": false,
  "data": [
    {
      "id": 42, "reference": "REF-001", "factory_reference": "FAB-9",
      "description": "Bombillo LED 12W", "brand": "LuxTech", "photo_url": null,
      "price_full": 20, "cost": 8.5, "stock_quantity": 100, "reorder_point": 10
    }
  ]
}
```

## "Ver bodegas" (REQ-437)

**Reusa el endpoint ya existente** de Ver Inventario de Bodega — sin ruta nueva:

```
GET /api/bodega/inventory/{catalog_product_id}/warehouse-breakdown
```

(`BodegaInventoryController::warehouseBreakdown`, ya construido en Bloque B2, gateado
`bodega.read`). Confirmado reusable tal cual para este contexto — mismo criterio que "Ver bodegas"
en Solicitud de Ajuste (SCRUM-403).

---

## Permiso nuevo

`compras.zona-libre.approve` — angosto, individual vía `extra_permissions` (no atado a ningún
`security_level`). Ya concedido a Yirena Teng (`gerencia2@atlantic.com.pa`) vía
`SpecialPermissionSeeder`, corre en todos los entornos.

## Modelo de datos (referencia)

```
bodega_zona_libre_requests (schema compras)
  id, order_number (int, único), status (pendiente|aprobada|rechazada),
  requested_by, approved_by, rejected_by, rejection_reason,
  generated_order_id (FK -> purchase_orders, null hasta aprobar),
  shipping_type (default terrestre), estimated_arrival_date, notes, timestamps

bodega_zona_libre_request_lines
  id, bodega_zona_libre_request_id, catalog_product_id, quantity, unit_cost_snapshot, timestamps

compras_settings.zona_libre_provider_id — FK nullable a providers, sembrado por
  ZonaLibreProviderSeeder (Provider "Zona Libre de Colón", corre en todos los entornos)
```
