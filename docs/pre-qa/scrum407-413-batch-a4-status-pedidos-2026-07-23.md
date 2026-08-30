# Pre-QA — Batch A4, "Status de Pedidos" (SCRUM-407→413)

**Fecha:** 2026-07-23
**Alcance:** `atlanticerp-backend` HEAD `3f63e77` (rama `dev`, pusheado), `atlanticerp-frontend` HEAD `2627102`
(rama `dev`, pusheado) — ambos confirmados como HEAD real al momento de empezar. Ambos Senior
Review previos en 🟢 (`docs/reviews/batch-a4-status-pedidos-backend-2026-07-23.md` y
`-frontend-2026-07-23.md`).

**Entorno de verificación:** stack Docker local (`infra-laravel-1`, `infra-postgres-1`,
`infra-nginx-1` puerto 8090), mismo código que `dev.atlanticerp.ai`. Verificación de API con `curl`
directo contra usuarios reales (no mocks) y verificación de UI con Playwright headless real contra
el dev server Vite (`localhost:5173`, mismo checkout que el build servido por nginx).

## Veredicto: PASADA LIMPIA — los 7 tickets pasan a `QA`

Sin hallazgos CRÍTICOS. Un hallazgo MEDIO no bloqueante (imagen de producto ausente en el PDF),
ya señalado por ambos Senior Reviewers como no-bloqueante y consistente con precedente existente —
ver detalle abajo.

---

## Paso 0 — permisos angostos y valores paramétricos

No aplica en sentido estricto (no hay una persona puntual con capacidad especial en este batch),
pero el criterio central de SCRUM-412 (`bodega.read` para Bodega, `ventas_diseno.read` para
Ventas & Diseño, filtro por `owner_id`) sí depende de permisos por rol — verificado que **ambos
ya están realmente concedidos en el entorno** vía `RoleModuleVisibility` (rol `lider_bodega` →
`bodega.view/.read/.view_team`, rol `vendedor_disenador` → `ventas_diseno.view/.read`), confirmado
con un login real (no grant manual, no `extra_permissions` ad-hoc) contra `almacen@atlantic.com.pa`
y `neil.quiel@atlantic.com.pa`. No hay hallazgo acá.

## Datos sembrados para esta pasada (vía tinker, `Order`/`PipelineCard` reales)

| Fixture | order_number | owner | Detalle |
|---|---|---|---|
| Familia A (2 tarjetas) | `PREQA-FAM2-9247` (root id 128, hija id 129) | Neil Quiel (`neil.quiel@atlantic.com.pa`) | Root: producto compartido 6/6 entregado. Hija: mismo producto 4/0 + producto propio 2/2. Total esperado: solicitada 10, entregado 6, pendiente 4 (producto compartido) — sin duplicar el producto compartido en 2 líneas. |
| Familia B (3 tarjetas) | `PREQA-FAM3-7805` (root id 130, hijas 131/132) | Neil Quiel | 3 tarjetas, mismo producto, 3+3+4 solicitadas = 3+3+4 entregadas → 100% entregado, pendiente debe ser 0. |
| Pedido de otro vendedor | `PREQA-FEFI-8660` (id 133) | Maria F. Bonvini (`fefi@atlantic.com.pa`) | Para el escenario adversarial de ownership cruzado. |

## Resultado real de verificación (corrido yo mismo)

### SCRUM-407 (REQ-337) — Acceso solo vía botón

- Botón "Status de pedidos" en `PedidosPage` navega a `/bodega/pedidos/status` — confirmado con
  Playwright real (`e2e/preqa-scrum407-413-status-pedidos.spec.ts:16`).
- "← Volver a Pedidos" regresa a `/bodega/pedidos` — confirmado, misma prueba.
- Extra (no pedido en el ticket original, pero mencionado en el batch): botón nuevo en
  `PipelinePage` (Ventas & Diseño) navega al **mismo** `/bodega/pedidos/status` — confirmado con
  Playwright real, login como Neil (vendedor real), prueba separada.
- La pantalla no tiene entrada en el Sidebar — confirmado por inspección de `Sidebar.tsx`/`App.tsx`
  (ninguna ruta bajo `/bodega/pedidos/status` aparece en la navegación).

**Sin hallazgos.**

### SCRUM-408 (REQ-338) — Buscador + tabla principal

- Orden de columnas confirmado real en el DOM: N° pedido, N° cotización, Subcliente, Fecha de
  pedido, Vendedor (RN1) — Playwright, comparando índices de aparición en el header renderizado.
- Búsqueda por N° de pedido filtra a 1 sola fila — confirmado con `PREQA-FAM2` → 1 fila.
- **Escenario de consolidación (el más importante del batch)** — Familia A (2 tarjetas, root
  entregada + hija pendiente) aparece como **UNA sola fila** en la tabla, tanto por API
  (`GET /bodega/orders/status?search=PREQA-FAM2` → `total: 1`) como en la UI real (Playwright,
  `tbody tr` count = 1). Confirmado también para Familia B (3 tarjetas) — también 1 sola fila.

**Sin hallazgos.**

### SCRUM-409/410 (REQ-339/340) — Modal detalle: datos generales + tabla de productos

- Clic en la fila de Familia A abre el modal con Proyecto/Diseñador/Cliente/Contacto/Teléfono
  reales de la cotización/proyecto (no capturados a mano) — confirmado visualmente
  (`e2e/.tmp/scrum409-410-detail-modal.png`) y por API.
- **Fecha de Estatus = fecha real del día de la consulta** (`2026-07-23`, coincide con la fecha
  actual del sistema) — confirmado en API (`now()->toDateString()`) y en UI.
- **Cálculo de pendiente, consolidado a través de la familia, SIN duplicar el producto
  compartido:** producto presente en ambas tarjetas → solicitada=10 (6+4), entregado=6 (6+0),
  pendiente=4 — UNA sola línea, no dos. Producto solo en la hija → solicitada=2, entregado=2,
  pendiente=0, línea separada (no se mezcla con el otro producto). Total: 2 líneas en la tabla del
  modal, no 3 ni 4 — confirmado por API y por Playwright (`order-status-item-row` count = 2).
- **Escenario 2 (familia de 3 tarjetas, completamente entregada):** Pendiente = 0 en la única
  línea consolidada (10 solicitadas = 10 entregadas a través de las 3 tarjetas) — confirmado por
  API y Playwright (`order-status-item-pending` = "0").
- **Reintento del caso adversarial de 3+ tarjetas** (pedido explícito de Luis): confirmado con la
  Familia B sembrada a propósito con 3 tarjetas (no 2) — el código de `FamilyService::members()`
  no tiene ningún límite de cardinalidad y el resultado consolidado fue correcto.

**Sin hallazgos.**

### SCRUM-411 (REQ-341) — Botón "Ver/Descargar"

- El botón dispara `POST`-like `GET .../document`, respuesta real 200 con `document_id`, `order_id`
  y una URL S3 presignada real — confirmado con Playwright (captura de la respuesta de red real,
  no mockeada) y con `curl` directo.
- **Descargué el PDF real generado** (no solo leí el Blade) y lo inspeccioné visualmente: paleta
  100% verde/teal institucional (`#5BA5A0`/`#E3F0EE`/`#3D7E7A`), **sin ningún rojo** — confirma RN1
  explícitamente.
- El documento contiene exactamente los mismos datos que el modal de detalle (proyecto, diseñador,
  cliente, contacto, teléfono, fecha de estatus, y ambas líneas de producto con
  solicitada/entregado/pendiente idénticos al modal) — confirmado comparando el PDF descargado
  contra la respuesta JSON del modal para la misma familia.
- RN2 (imprimible/descargable): es un PDF real descargable vía URL firmada, mismo mecanismo que
  Guía de Entrega — confirmado.

🟡 **MEDIO, no bloqueante** — el PDF no incluye la columna "Imagen" que sí muestra el modal (la
descripción original del ticket menciona explícitamente "la tabla de productos con imagen"), y no
tiene un título en mayúsculas "ESTATUS DE PEDIDO" separado (usa el mismo patrón "Pedido {número}" +
subtítulo pequeño que ya usa Guía de Entrega). Ambos Senior Reviewers ya señalaron esto como
no-bloqueante (frontend S3, backend nota de precedente) — **reproduce exactamente el patrón ya
aceptado de Guía de Entrega** (que tampoco tiene imágenes ni título en mayúsculas), probablemente
por la misma limitación técnica de dompdf con imágenes remotas. No bloqueo el batch por esto — lo
dejo anotado para que PM decida si vale la pena una mejora futura transversal a ambos documentos
(Guía de Entrega + Estatus de Pedido), no específica de este ticket.

### SCRUM-412 (REQ-342) — Acceso compartido con Ventas & Diseño

- **Ownership real, verificado con usuarios reales, no solo escondido en la UI:**
  - Neil (vendedor real, `ventas_diseno.read`) — su lista solo trae sus 2 pedidos (Familia A y B),
    **nunca** el pedido de Fefi (otro vendedor) — confirmado por API y Playwright.
  - Neil intenta acceder al pedido de Fefi por **id directo** (manipulando la URL de la API, no
    solo el botón) → **403 real** (`show()` y también `document()`, este último cierra
    explícitamente el gap S1 que el Senior Review de backend había dejado como sugerencia de
    cobertura de test) — no un 404 que filtre existencia, un 403 real después de resolver el
    pedido.
  - Prueba inversa: Fefi intenta acceder al pedido de Neil por id directo → también 403.
  - Bodega (Esteban, `bodega.read`) ve **todos** los pedidos sin filtrar, incluyendo el de Fefi, y
    puede generar el documento de un pedido que no es "suyo" (Bodega no tiene owner) — confirmado.
- RN3 (solo consulta): confirmado en UI real que no hay ningún control de edición visible para
  Ventas & Diseño (ni inputs de texto editables fuera del buscador, ni palabras
  "guardar"/"editar"/"eliminar" en el DOM) — Playwright.
- Botón de entrada desde `PipelinePage` navega al mismo lugar que el de `PedidosPage` — confirmado
  (ver SCRUM-407 arriba).
- **Foco de ruptura — rol sin ninguno de los 2 permisos:** logueado como `supervisor@atlantic.test`
  (rol `supervisor`, sin `bodega.read` ni `ventas_diseno.read`) — la API devuelve 403 real en
  `GET /bodega/orders/status`, y en la UI el gate de ruta (`RequirePermission`) redirige a
  `/dashboard` de verdad (confirmado con captura de pantalla, el usuario aterriza en el Dashboard
  real, no en una pantalla en blanco ni con datos parciales).

**Sin hallazgos.**

### SCRUM-413 (REQ-343) — Misma fuente de datos, sin desfase

- El listado y el detalle leen `Order`/`PipelineCard` en vivo, sin ninguna tabla de caché
  intermedia — confirmado por lectura de código (`OrderStatusService` no cachea nada) y por el
  hecho de que los fixtures sembrados directamente en la base aparecen inmediatamente en la
  pantalla sin ningún paso de sincronización manual.
- No se requiere que Bodega mantenga datos duplicados — confirmado, no hay ningún campo de Status
  de Pedidos que se edite independientemente de Pedidos (RN3 de 412 ya lo confirma: sin edición en
  ningún lado de esta pantalla).

**Sin hallazgos.**

---

## Foco adicional de ruptura (Paso 3 del rol)

- **Recarga a mitad de flujo** (modal de detalle abierto) — no rompe, sin errores de JS
  (`page.on('pageerror')` vacío), la tabla sigue visible y usable tras el reload.
- **Búsqueda vacía / solo espacios** — no rompe (sin 500), no filtra de más.
- **Doble clic en una fila** — no abre 2 modales apilados (un solo botón "Cerrar" visible), no
  duplica llamadas de detalle de forma descontrolada.
- **Rol sin permiso** — cubierto arriba (SCRUM-412), bloqueo real de API + redirect real de UI.
- **Regla SCRUM-56 (sin emoji)** — confirmado por grep visual real sobre el texto renderizado de
  la pantalla y el modal, ningún emoji.

## Lo que sí funciona (resumen)

Navegación bidireccional Pedidos ↔ Status (ambos botones de entrada, Bodega y Ventas & Diseño),
orden de columnas, búsqueda, consolidación de familia (2 y 3 tarjetas, sin duplicar productos
compartidos), cálculo de pendiente por producto (parcial y 100% entregado), Fecha de Estatus =
hoy, ownership real bidireccional con 403 verificado por API directa (incluyendo el endpoint de
documento), Bodega ve todo, solo-lectura real para Ventas & Diseño, documento PDF verde
institucional con los mismos datos del modal, permiso ausente bloquea de verdad en API y UI,
resiliencia a reload/doble-clic/búsqueda vacía, sin iconografía de emoji.

## Smoke test promovido a e2e permanente

`atlanticerp-frontend/e2e/preqa-scrum407-413-status-pedidos.spec.ts` (14 tests) — cubre navegación
compartida, consolidación de familia, ownership cruzado, gate de permisos y los focos de ruptura
de esta pasada. Se promueve porque cubre exactamente el tipo de gate (permisos + consolidación de
familia) que ya se rompió una vez en otros batches de este mismo proyecto.

## Decisión

Los 7 tickets (SCRUM-407 a 413) pasan a `QA`. El hallazgo MEDIO de imagen ausente en el PDF queda
documentado en el comentario de SCRUM-411 para que PM decida si se aborda como mejora transversal
(junto con Guía de Entrega) — no bloquea el batch.
