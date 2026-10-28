# SCRUM-1119 — Pedidos visible solo para Compras y Gerencia

**Ticket:** SCRUM-1119 (reporta qa.qa, prioridad High, épica SCRUM-325, etiquetas permisos/seguridad). **Estado:** spec aprobada por Luis (2026-10-07), con el alcance ampliado de §2 bis.
**Decisiones ya tomadas por Luis:** (1) acceso únicamente para `lider_compras`, `management` y `superadmin`; pierden el acceso `lider_operaciones`, `lider_bodega`, `lider_servicios`, `vendedor_disenador` y el resto. (2) Se amplía el alcance para que Compras de verdad pueda usar Pedidos (§2 bis).

## 1. Defecto

La vista Pedidos muestra márgenes cotizado/facturado, montos y estado de compra. Hoy:
- Menú (`Sidebar.tsx:335`): solo se oculta a `lider_admin_contab` y `asistente_administrativa`; el resto de roles con Ventas y Diseño lo ve.
- Ruta (`App.tsx:236`): solo exige `ventas_diseno.read`, que los vendedores necesitan para el Pipeline.
- API (`routes/ventas-diseno.php:224`): `GET /orders` y `GET /orders/{id}` exigen solo `ventas_diseno.read`. Ocultar la pantalla sin cerrar la API deja los datos legibles.

## 2. Diseño

**Backend:** las dos rutas de `orders` (listado y detalle) quedan restringidas por rol a `lider_compras`, `management`, `superadmin`; los demás reciben 403. Se usa el middleware de rol existente, sin permiso nuevo ni migración. `orders-settings` no cambia (ya exige `ventas_diseno.pricing.configure`).

**Frontend:**
- `Sidebar.tsx`: `canSeePedidosTab` pasa a ser true solo para esos 3 roles.
- `App.tsx`: la ruta se envuelve en `RequireRole roles={['lider_compras','management','superadmin']}`; quien entra por URL es redirigido a su inicio.

**Sin cambios:** el resto de vistas de Ventas y Diseño, los enlaces de Gerencia hacia Pedidos (Gerencia conserva el acceso), el cálculo de pedidos.

## 2 bis. Hallazgo: Compras hoy no puede ver Pedidos

`lider_compras` no tiene `ventas_diseno.read` (la ruta y la API lo exigían), el ítem de menú vive en la sección Ventas y Diseño que Compras no ve, y el listado se acota a `owner_id = yo` cuando no hay vista de equipo en el módulo (lista vacía). Cambios agregados:
- Backend: el gate de las rutas `orders` es solo de rol (sin `ventas_diseno.read`); `OrdersController` pasa `verEquipo: true` a `OrdersService`, que da alcance de equipo completo a todo el que llega (las rutas ya filtraron por rol).
- Frontend: entrada "Pedidos de venta" en la sección Compras solo para `lider_compras`; Gerencia y superadmin mantienen la de Ventas y Diseño. Compras no ve el selector de vendedores (su endpoint exige `ventas_diseno.read`).

## 3. Criterios de aceptación

- **A1.** `vendedor_disenador` no ve "Pedidos" en el menú.
- **A2.** `vendedor_disenador` en `/ventas-diseno/pedidos` es redirigido a su inicio.
- **A3.** `GET /orders` y `GET /orders/{id}` responden 403 a `vendedor_disenador`, `lider_operaciones`, `lider_bodega`, `lider_servicios`.
- **A4.** `lider_compras`, `management` y `superadmin` ven el ítem, acceden a la ruta y reciben 200 en ambos endpoints.
- **A5.** Sin regresión: Pipeline, Cotizaciones, Reportes y Clientes siguen igual para vendedor; los enlaces de Gerencia a Pedidos siguen funcionando.
- **A6.** Los tests existentes de `OrdersControllerTest` se ajustan al nuevo gate sin perder cobertura del alcance Mías/Equipo.

## 4. Desglose

| # | Tarea | Dueño |
|---|---|---|
| P1 | Gate de rol en las rutas `orders` + tests (A3, A4, A6) | Backend |
| P2 | Menú + `RequireRole` en la ruta + tests (A1, A2, A4) | Frontend |
| P3 | Gate local en ambos repos + Senior Review independiente | Arquitecto |
| P4 | Pre-QA en dev (A5, URL directa y API directa con token de vendedor) | Pre-QA |
| P5 | dev → test → prod, cada paso con OK de Luis; Jira y documentación | Arquitecto |

## 5. Riesgos

- Operaciones, Bodega y Servicios pierden un acceso que hoy tienen: avisar a Qa al comentar el ticket.
- Verificar que ninguna otra pantalla consuma `/orders` fuera de `PedidosPage` (hoy solo `ventasDisenoApi.ts:588-591`).
