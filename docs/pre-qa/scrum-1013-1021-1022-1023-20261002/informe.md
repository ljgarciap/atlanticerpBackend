# Pre-QA SCRUM-1013 (rebote) / 1021 / 1022 / 1023 — 2026-10-02

Entorno: stack local (nginx :8090, Vite :5173), rama `dev`, commits locales sin push (backend d47036eb; frontend c3143ae, 6ecc956). Playwright CLI + API. Visual Review no aplica (sin mockup).
Las 2 migraciones (2026_10_02_120000 y 2026_10_02_120100) ya estaban aplicadas al tenant local y `SpecialPermissionSeeder` ya había concedido los permisos; lo volví a correr (idempotente). **Vendedor REAL usado: vendedordisenador@test.com** (los 10 reales tienen `admin_contab.commissions_internal.view`, `compras.logistica.view`, `bodega.warehouses.view` y ninguno `compras.limited.view`). Para la prueba "token sin permisos nuevos" le quité temporalmente los 2 permisos nuevos a Milena y los restauré (verificado). Fixtures sembrados (3 productos PQA13 con costo, stock, 1 orden de compra con contenedor/naviera/montos, 2 filas de Zona Libre Lafayette) borrados al terminar. Capturas en `capturas/`. Barrido de seguridad: las 183 rutas `api/compras` + `api/bodega` (todos los métodos).

## Veredicto por ticket
| Ticket | Veredicto |
|---|---|
| SCRUM-1013 (rebote) | **NO LIMPIO**: 1 ALTO (costo unitario de producto expuesto en el detalle de orden) + 1 ALTO (KPI `total_value` del Inventario sin enmascarar) + 1 MEDIO (detalle de orden / Inicio de Compras con botones). El enmascarado de costo/margen por fila y el menú SÍ están bien. |
| SCRUM-1021 | LIMPIO |
| SCRUM-1022 | LIMPIO |
| SCRUM-1023 | LIMPIO |

## 1. Seguridad (prioridad 1)
**Inventario del vendedor** (UI `/inventario` y API `/api/compras/inventory`, `/{id}`, `/families`):
- Filas y detalle: `restricted=true`, sin `cost`, `margin_percent`, `import/freight/handling/other/cost_total`, `factory_reference` ni `provider_id`; sí `price_full` (precio de venta). Familias: `restricted=true`. Con token SIN los permisos nuevos el comportamiento es idéntico (el enmascarado ya no depende de `compras.limited.view`). Operaciones y Líder de Compras (no restringidos) siguen viendo costo y margen: sin cambio.
- **HALLAZGO ALTO H1 — `kpis.total_value` se devuelve al vendedor.** `GET /api/compras/inventory` → `kpis.total_value: 4000` con `restricted:true` (precio de venta × stock; la UI lo oculta, la API no). Origen: `InventoryController::index` (línea ~255, `'kpis' => $this->kpis()`) ignora `$restricted`; `InventoryKpiService` líneas ~85-95. Contrasta con `familyDetail`/`families` que sí ponen `total_value => null` cuando `restricted`. Preexistente (no lo introduce este cambio) pero el criterio pedía "sin total_value". Fix chico: `'kpis' => $restricted ? [...sin total_value] : $this->kpis()`.
- **HALLAZGO ALTO H2 — el costo unitario real sale por el detalle de orden.** `GET /api/compras/orders/{id}` (permitido por `compras.logistica.view`) devuelve `lines[].unit_cost` y `lines[].subtotal` (55.50 / 555.00 para un producto cuyo `cost` de catálogo es 55.5) y la pantalla `/compras/ordenes/{id}` (alcanzable desde Logística) muestra la columna "Costo unitario". Es el costo de compra de cada producto, justo lo que el enmascarado de Inventario busca ocultar. Origen: `PurchaseOrderController::show/format` (líneas con `unit_cost`) y el gate de ruta `routes/compras.php` `orders/{id}` (`compras.logistica.view` agregado en d47036eb). Decisión de Arquitecto/Daniela: o se ocultan `unit_cost`/`subtotal`/`additional_cost_*` de las líneas para quien solo tiene `compras.logistica.view`, o se acepta explícitamente.
- **Inventario de campos de dinero que ve el vendedor** (idéntico a Operaciones):
  - `GET /api/compras/orders` (por orden): `total_amount`, `shipping_cost`, `currency`, `payment_status`, `paid_amount`, `payment_requested_at`, `last_payment_date`; además proveedor, estados y fechas; `filters.providers`.
  - `GET /api/compras/orders/{id}`: lo anterior más `who_pays_shipping`, `pending_amount_change`, `amount_change_requested_by`, `approved_by_name`, `notes`, y por línea `unit_cost`, `subtotal`, `additional_cost_percent/amount/type`, `reference`, `factory_reference`, cantidades. `documents` solo lectura.
  - `GET /api/compras/products` (buscador de sustitutos, vía `ventas_diseno.edit`, preexistente por decisión de Luis SCRUM-818): `price_full`, `margin_percent` (el `cost` va null, pero con precio y margen el costo se deduce por fórmula).
  - `GET /api/bodega/zona-libre/productos`: `precio` null para el vendedor (`puede_ver_precio:false`); `disponible` sí.
- **Rutas Compras/Bodega más allá de lo acordado**: con token de vendedor solo devuelven 200: `inventory`, `inventory/{id}`, `families`, `compras/orders`, `orders/{id}`, `orders/{id}/documents`, `orders/shipment-projects`, `bodega/warehouses`, `warehouses/{id}`, `zona-libre/productos`, y las heredadas por `ventas_diseno.*`/`edit` (preexistentes): `compras/products`, `approved-projects`, `replacement-requests` (GET), `home/approvals-pending` (vacío), `bodega/orders/status` (Status de Pedidos propio). providers, goods-receipts, settings, home/summary, payments, claims, reports, agencies, kardex, orders de Bodega, relocations, adjustment-requests, kardex, etc.: **403**.
- **Escrituras**: 100% de las escrituras de Compras y Bodega con token de vendedor dan 403, sin ningún 500, EXCEPTO `POST /api/compras/replacement-requests` (devolvió 422 al enviar cuerpo vacío = pasó la compuerta; gate `compras.write,compras.sustitutos.manage,ventas_diseno.edit`). Es la solicitud de sustituto de Comparación de Referencias (preexistente, ya anotada por el Arquitecto en 1013); `approve`/`reject` quedan protegidos dentro del controlador (`canDecide`). Observación MEDIO: confirmar que es intencional.
- **Token SIN permisos nuevos**: 403 en `compras/orders`, `orders/{id}`, `orders/{id}/documents`, `bodega/warehouses`, `warehouses/{id}`, `zona-libre/productos`. PASA.

## 2. SCRUM-1013 menú y pantallas
| Prueba | Resultado |
|---|---|
| Menú vendedor (Compras · Inventario): Inventario, Comparación de Referencias, Órdenes (→ Logística y Envío); Bodega: Bodegas; sin Inicio/Nueva orden/Proveedores/Agencias/Pagos/Garantías/Reportes/Ver Órdenes/Ingresos. Ventas & Diseño, Servicios, Admin&Contab (Comisiones), CRM intactos | PASA (`a-menu-vendedor.png`) |
| Logística: lista y detalle; Número de contenedor y Naviera `readOnly`, sin botones de avance/documentos/acciones (idéntico a Operaciones; Líder de Compras sí edita y ve "Completar etapa actual") | PASA (`b-logistica-*.png`) |
| URLs directas del vendedor a `/compras/proveedores`, `/ordenes`, `/ordenes/nueva`, `/ingresos`, `/agencias`, `/pagos`, `/reclamos`, `/reportes`, `/configuracion`, `/inventario` de Compras: redirigen a `/ventas-diseno/home`; Bodega (home, pedidos, kardex, inventario, devoluciones, configuración, reportes, solicitud de ajuste, órdenes Zona Libre, rutas del día): redirigen | PASA |
| **MEDIO M1 — `/compras/inicio` carga para el vendedor** con los botones "Proveedores", "+ Nueva orden de compra", tarjetas de retrasados/bajo stock y 2 llamadas 403 (`calendar`, `home/summary`). No es el "redirige o bloquea" pedido. Origen: ruta `/compras/inicio` en `App.tsx` admite `ventas_diseno.edit` (ya anotado por el Arquitecto como observación). | HALLAZGO MEDIO (`c-compras-inicio-vendedor.png`) |
| **MEDIO M2 — `/compras/ordenes/{id}` (detalle de orden, alcanzable desde Logística) muestra al vendedor los botones "Subir confirmación del proveedor", "Pagos a Proveedores", "Ver Órdenes" y los montos** (los POST/PUT dan 403, pero el botón se ve). Contradice "sin botones de acción". Ver también H2 (costo unitario). | HALLAZGO MEDIO (`c-orden-detalle-vendedor.png`) |
| Operaciones no cambia: conserva `compras.limited.view` + `compras.sustitutos.manage`; su menú de Compras completo en local (la ocultación por usuario de dev no existe en la base local) y sus campos de contenedor/naviera ahora solo lectura (decisión aprobada) | PASA |
| Observación BAJO: `/compras/logistica` pide `/compras/providers?per_page=all` y recibe 403 (filtro "Todos los proveedores" para el vendedor). Funciona igual que en Operaciones. | — |

## 3. SCRUM-1021 Bodegas — LIMPIO
Vendedor: ve "Bodegas" (único ítem de Bodega), TODAS las bodegas incluida "Bodega Zona Libre · Solo consulta" y el inventario de cada una; sin "Administrar ubicaciones", sin "Solicitudes de reubicación", sin "Reubicar" en tablas (0). API: `bodega/zona-libre/productos` devuelve las filas Lafayette (con `precio:null`), `warehouses/{id}` 200. Escrituras de Bodega 403. Líder de Bodega (`bodega.read`) ve exactamente lo de antes: botones superiores y 3 "Reubicar" (`d-bodegas-*.png`). Resto de Bodega (pedidos, kardex, home) redirige. Zona Libre sin sincronizar muestra el mensaje de catálogo vacío.

## 4. SCRUM-1022 / 1023 anchos del buscador — LIMPIO
Ancho real del `<input>` (getBoundingClientRect), con filtros activos (Inventario: Bodegas=1 marcada; el filtro de Familia no tiene opciones en la base local):
| Viewport | Compras→Inventario | Bodega→Bodegas | Bodega→Ver Inventario (referencia) |
|---|---|---|---|
| 768 | 448 px | 448 px | 448 px |
| 1280 | 448 px | 448 px | 448 px |
| 1440 | 448 px | 448 px | 448 px |
| 1920 | 448 px | 448 px | 448 px |
No se encoge al sumar filtros; buscador y botón "Buscar" en su fila (`e-inventario-*.png`, `e-bodegas-*.png`). Búsqueda y filtros funcionan como antes (lista 3 productos; vendedor sin costos).

## 5. Regresión
Vendedor: Ventas & Diseño (inicio, lista de cotizaciones, nueva cotización), Pipeline, Comparación de Referencias, Inventario restringido, Servicios: cargan sin 4xx (el 500 puntual de `servicios/tickets/stats` fue una carrera de inserción de un setting al primer acceso, ajena a este cambio; reintento 200). Gerencia, Técnico de Servicios, Admin&Contab, Líder de Bodega: menús sin cambios. EN y modo oscuro: sin claves sin traducir en el menú nuevo ("Warehouses"), Logística y Bodegas (`h-en-oscuro-*.png`).

## 6. Sesiones y tokens
- Access token: **3600 s (1 h)** (`JWT_EXPIRATION_MS=3600000`); refresh token 30 días.
- **El refresh SÍ re-lee permisos**: con un token viejo + refresh después de quitar permisos en BD, el token nuevo ya no traía `compras.logistica.view` ni `bodega.warehouses.view`. Un access token viejo conserva sus permisos (incluido `compras.limited.view`) hasta expirar.
- Conclusión para producción: tras desplegar, los vendedores dejan de ver costos como máximo 1 hora después (o en el siguiente refresh/login, lo que ocurra primero). La migración corre sola en el deploy.

## Lo que sí funciona
Enmascarado por fila de costo/margen/desglose en Inventario (lista, detalle, familias) tanto con como sin los permisos nuevos; 403 en todo Compras/Bodega no acordado y en todas las escrituras (salvo la solicitud de sustituto preexistente); menú exacto del vendedor; Logística y Bodegas en solo lectura; Zona Libre visible sin precio; buscadores a 448 px en 768-1920; Operaciones y Líder de Bodega sin cambios.

---

# RONDA 2 — re-verificación tras H1/H2/M2 (backend 076a49d8, frontend OrderDetailPage), 2026-10-02

Mismo entorno y vendedor real (milena.e). Fixtures re-sembrados (3 productos con costo, 3 órdenes: en tránsito con monto/flete/pago parcial/cambio de monto pendiente/factura comercial, por aprobar y ordenado/Zona Libre; 1 fila Lafayette) y borrados al terminar (0 restantes; Milena con sus 3 permisos). Capturas nuevas `r2-*.png`.

## Veredicto ronda 2
| Ticket | Veredicto |
|---|---|
| SCRUM-1013 | **NO LIMPIO por 1 hallazgo ALTO indirecto (H3: documentos de la orden)**. H1, H2 y M2 corregidos. |
| SCRUM-1021 | LIMPIO |
| SCRUM-1022 | LIMPIO |
| SCRUM-1023 | LIMPIO |

## Verificación de lo corregido
- **H1 PASA**: `GET /api/compras/inventory` del vendedor → `restricted:true`, `kpis.total_value: null`; filas y detalle sin `cost`, `margin_percent` ni desglose; familias `restricted:true`. Operaciones y Líder de Compras siguen con `total_value: 4000` y todos los costos.
- **H2 PASA (barrido completo)**. Con montos conocidos (12345.67, 890.12, 777.77, 5000.25, 55.5, 555, 4321.99, 999.11, 150.5) y búsqueda de esos valores en el JSON crudo de TODOS los GET accesibles al vendedor (orders list con `payment_pending=1`, `chip=critical`, `chip=zona_libre`, `payment_status=por_pagar|pagado_parcial`, `active_shipments`, `status`, `search`, `sort_by=total_amount` asc/desc; `orders/{id}` de las 3 órdenes; `documents`; `shipment-projects`; `inventory` list/detalle/warehouse-stock; `families`; `bodega/warehouses`, `warehouses/6`, `zona-libre/productos`; `bodega/orders/status`): ningún valor monetario residual. `total_amount`, `shipping_cost`, `paid_amount`, `pending_amount_change`, `total_pending_balance` y `payment_kpis` vienen null; por línea `unit_cost`, `subtotal`, `additional_cost_*` null. `sort_by=total_amount` se ignora sin 500 (el orden del vendedor es idéntico asc/desc y no ordena por monto; el de Líder de Compras/Operaciones/superadmin sí ordena). La orden sigue mostrando producto, cantidad, referencias, estado, fechas, proyecto, contenedor y naviera.
- **M2 PASA**: `/compras/ordenes/{id}` del vendedor sin columnas Costo unitario/Subtotal, sin total, sin "Pagos a Proveedores", sin "Ver Orden (PDF)", sin tarjeta de confirmación del proveedor ni "Subir confirmación" (solo "Ver Órdenes"; `r2-orden-vendedor.png`). Operaciones y Líder de Compras (con compras.read) ven todo como antes: montos, costo unitario, total $12,345.67, Pagos, PDF, y el Líder también "Avanzar a" (`r2-orden-*.png`).

## Hallazgo nuevo
**H3 — ALTO (canal indirecto): los documentos de la orden exponen costos.** El vendedor ve en Logística la sección "Documentos del envío" con enlaces descargables ("Factura comercial", y por categoría también declaración de nacionalización, permiso de importación, BL, confirmación del proveedor): `GET /api/compras/orders/{id}/documents` (gate `compras.logistica.view`) devuelve una URL prefirmada de S3 de 15 min por documento. Una factura comercial o una confirmación del proveedor traen precios unitarios y totales; sirve para deducir el costo exactamente igual que `unit_cost`, anulando la decisión de Luis de ocultar costos/montos. Repro: milena.e → `/compras/logistica` → abrir una orden con "Factura comercial" → clic en el enlace (`r2-logistica-docs-vendedor.png`), o `GET /api/compras/orders/{id}/documents` con su token. Archivos probables: `routes/compras.php` línea del grupo `{orderId}/documents` (`compras.logistica.view`) y `PurchaseOrderDocumentController::index`. Opciones: ocultar la lista/URLs para quien `hidesMoney`, o limitar a categorías no financieras (bl, permiso de importación). Decisión de Arquitecto/Luis.

## Otros canales indirectos (BAJO / ya conocidos)
- `margin_percent` + `price_full` en el buscador de sustitutos (`/compras/products`, Comparación de Referencias) permiten calcular el costo total (precio 100, margen 39.5% → costo 60.5). Decisión previa de Luis (SCRUM-818); solo se anota.
- `payment_status` ("parcial"), `last_payment_date`, `amount_change_requested_by`, `requires_primary_approval` (indica que la orden supera el umbral de aprobación de Mark) siguen visibles: no son montos, pero informan estado de pago/tamaño relativo.
- `/api/compras/inventory` sigue mostrando `en_camino`/`por_ingresar` y proveedor (cantidades, no costos).

## Checklist completo repetido
- **Seguridad 183 rutas** (compras + bodega, todos los métodos) con el vendedor: mismo resultado que la ronda 1, sin ningún 200 nuevo (161 × 403, 11 × 200 acordados/heredados, 10 × 404 por id inexistente, 1 × 422 = `POST replacement-requests` preexistente de Comparación). Segunda pasada de TODAS las escrituras contra ids reales (orders/488, inventory/158865, warehouses/6): 81 × 403 y solo `replacement-requests` (+ approve/reject 404 sin datos); ningún 500.
- **Menú**: Inventario, Comparación de Referencias, Órdenes (→ Logística y Envío) y Bodega → Bodegas; nada más de Compras ni Bodega. **Rutas directas** a proveedores/órdenes/ingresos/agencias/pagos/reportes y a todo Bodega salvo Bodegas redirigen (igual que ronda 1; `/compras/inicio` sigue cargando — M1, preexistente de Ventas & Diseño, aceptado como observación).
- **Logística** del vendedor: lista y detalle, contenedor y naviera solo lectura, sin botones; sin dinero en pantalla.
- **1021**: Bodegas sin "Administrar ubicaciones", "Solicitudes de reubicación" ni "Reubicar"; Zona Libre visible con su tabla y `precio` oculto; Líder de Bodega igual que antes.
- **1022/1023**: input del buscador 448 px a 768/1280/1440/1920 en Compras→Inventario (con filtro de Bodegas), Bodega→Bodegas y Bodega→Ver Inventario.
- Inventario UI del vendedor sin costo, margen ni valor total.

---

# RONDA 3 — re-verificación de H3 (backend e2565ce1), 2026-10-02

Fixtures: 1 orden con documentos de las 6 categorías + 1 producto con ficha técnica; borrados al terminar (0 restantes; Milena con sus 3 permisos). Capturas `r3-logistica-docs-*.png`.

## Veredicto ronda 3
| Ticket | Veredicto |
|---|---|
| SCRUM-1013 | **LIMPIO** (H1, H2, H3 y M2 corregidos; solo quedan observaciones) |
| SCRUM-1021 | LIMPIO |
| SCRUM-1022 | LIMPIO |
| SCRUM-1023 | LIMPIO |

## H3 verificado
- `GET /api/compras/orders/1/documents` con milena.e → solo `bl` y `permiso_importacion` (con su URL prefirmada); no aparecen `factura_comercial`, `confirmacion_proveedor`, `declaracion_nacionalizacion` ni `otro`, ni sus nombres de archivo ni URLs. `?category=factura_comercial` y `category[]=…&include=all` no cambian el resultado (el filtro del servidor es incondicional).
- Pantalla Logística del vendedor: "Documentos del envío" muestra solo BL y Permiso de importación; ambos enlaces llevan a URLs prefirmadas válidas (`r3-logistica-docs-vendedor.png`).
- Sin otra vía a un documento filtrado: `GET .../documents/{id}/validation` 403, `POST .../documents` 403, `POST .../{id}/validate` 403, `PUT/DELETE/GET .../documents/{id}`, `/download` y `/url` no existen (404); orden inexistente 404; el detalle de orden no incluye documentos.
- Operaciones, Líder de Compras (compras.read), Líder de Bodega (bodega.read) y superadmin siguen viendo las 6 categorías con URL; el Líder de Compras además conserva "Validar" y "(subido)".

## Otros canales hacia archivos o PDF con precios (vendedor)
- `GET /api/compras/orders/{id}/pdf` 403; PDF de reclamos 403; `GET /api/compras/inventory/{id}/technical-sheet` 403; `bodega/orders/{id}/documents/{docId}/download`, `bodega/orders/{id}/invoice` y `/invoice/pdf`, `bodega/returns/{id}/signed-document` 403 (gate `bodega.read`); `compras/goods-receipts/invoice/{job}` 403.
- Barrido de TODos los GET de la API (360 rutas, ids ficticios) con el token del vendedor buscando URLs prefirmadas y campos de costo en respuestas 200: solo `compras/orders/{id}/documents` (ya filtrado), `ventas-diseno/catalog/{id}/technical-sheet` (ficha técnica de producto de Ventas & Diseño, prevista para el vendedor; contenido técnico) y `compras/products` (`margin_percent`, decisión previa de Luis SCRUM-818). `bodega/orders/status/{id}/document` es el documento del pedido propio del vendedor (de venta, no de compra).
- Observaciones indirectas (BAJO): (a) un BL o un permiso de importación real puede declarar valor de la mercadería (CIF/valor declarado); la regla se basa en la categoría, no en el contenido, así que conviene confirmar con Luis/Daniela que esas dos categorías no llevan valores; (b) lo anotado en la ronda 2 (margen + precio de venta en Comparación de Referencias, `payment_status`/`last_payment_date`/`requires_primary_approval`).
- Sin relación con este cambio: `GET /api/ai/analyses/{id}` da 500 con un id no UUID y `/api/biblioteca*` da 503 (biblioteca no disponible en local); ambos preexistentes.

## Barrido de rutas vs ronda 2
183 rutas de `api/compras` y `api/bodega` con el vendedor: ningún 200 nuevo y ningún 500. Los únicos cambios de código de estado (4) son rutas ya acordadas que pasaron de 404 a 200 porque ahora existen la orden y el producto de prueba (`inventory/{id}`, `inventory/{id}/warehouse-stock`, `orders/{id}`, `orders/{id}/documents`). Todas las escrituras (84 contra ids reales): 81 × 403; solo `POST replacement-requests` sigue pasando la compuerta (422) y approve/reject 404 sin datos — preexistente de Comparación de Referencias.
