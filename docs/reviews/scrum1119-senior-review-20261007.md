# Senior Review — SCRUM-1119 (Pedidos solo Compras / Gerencia / superadmin)

Backend `aeb84814`, frontend `7888b2e`. Solo lectura. Resultado: APROBADO CON OBSERVACIONES. No hay bloqueantes.

## BLOQUEANTE
Ninguno.

## IMPORTANTE
1. Botones muertos en PedidosPage para lider_compras. `src/pages/ventas-diseno/PedidosPage.tsx` (~líneas 124-130, frontend) tiene los botones "Catálogo" y "Ver cotizaciones" que navegan a `/ventas-diseno/catalog` y `/ventas-diseno/quotes-list`. Ambas rutas son `RequirePermission ventas_diseno.read` (App.tsx ~228 y ~258). lider_compras no tiene ese permiso, así que el clic redirige en silencio al home (`getHomeRoute`). Es exactamente el tipo de link muerto que se pidió buscar. Corrección sugerida: ocultar ambos botones si el usuario no tiene `ventas_diseno.read` (`usePermission`), o dejarlo como decisión explícita documentada en la spec.
2. Cobertura de test de gate por rol incompleta en backend. `OrdersControllerTest` prueba 403 para vendedor, lider_operaciones, lider_bodega y lider_servicios, pero no para `asistente_compras`, `lider_admin_contab` ni `asistente_administrativa`, que el requisito nombra como "administrativos". La lógica (lista cerrada en `role:`) los bloquea, pero no hay regresión que lo fije. Añadir un dataset al test existente.

## MENOR
1. `routes/ventas-diseno.php:225`: el gate depende solo del claim `role` (base). `EnforceRole` lee `$payload['role']` (`JwtClaimsBuilder:36`, role_key del rol base o, si no hay, `roles()[0]`). Un usuario con rol base distinto y `user_additional_roles` = lider_compras NO pasa. Según CLAUDE.md multi-rol es exclusivo de superadmin, que ya pasa por su rol, así que no hay bloqueo erróneo hoy; solo dejarlo anotado.
2. `verEquipo: true` en `OrdersController::index/show` solo se activa tras el middleware de rol, sin ruta alternativa a `OrdersService` (verificado: solo lo usan `OrdersController` y un comando QA). Lo que abre es el equipo completo a los 3 roles autorizados, que es lo pedido. Observación: `scope=own` explícito sigue filtrando por owner (correcto). Sin fuga.
3. Compras ve a todo el equipo sin el filtro de vendedor (`home.vendors` exige `ventas_diseno.read`, y `useQuery` está `enabled: canSeeTeam` solo management/superadmin, así que no hay 403 ni pantalla rota). Es una limitación funcional ya documentada en la spec §2 bis. Un `owner_ids` enviado a mano por Compras sí lo respeta el backend.
4. Test de frontend de la ruta usa `for` con `cleanup()` manual dentro de un `it`; funciona pero un `it.each` daría fallos más localizados. Estético.
5. Comentario duplicado/desordenado en `Sidebar.tsx` y `App.tsx`: el texto de SCRUM-1119 se antepone al comentario histórico de Batch F ("placeholder hasta Batch F") que ya es obsoleto. Limpiar al pasar.
6. Línea en blanco sobrante antes del `})` de cierre en `Sidebar.test.tsx` (~625).

## Respuestas a los puntos pedidos
1. Otras exposiciones de márgenes/montos de Pedidos: no hallé ninguna. `OrdersService` solo lo consumen `OrdersController` (ahora con gate por rol) y `QaSeedInvoicedComprasCommand` (artisan). `QuoteListService` solo lo cita en un comentario y no calcula margen facturado/cotizado de pedidos. `orders-settings` conserva `ventas_diseno.pricing.configure` (solo umbrales, no datos). Gerencia usa `gerencia.view` y su export no tiene datos de Pedidos. Todos los enlaces del frontend a `/ventas-diseno/pedidos` (GerenciaPage, solo visible a management/superadmin; Sidebar) están bien gateados.
2. `role:` recibe el role_key correcto (`JwtClaimsBuilder:36`; `EnforceRole` compara con `in_array(..., true)`; alias `role` en `bootstrap/app.php:116`, JWT aplicado por el grupo `api`). superadmin tiene role `superadmin` y pasa; el test lo cubre. Sin bloqueos erróneos.
3. `verEquipo:true` no abre más datos de los debidos: solo alcanza a quien pasó el gate de rol.
4. Frontend: único problema real es el hallazgo IMPORTANTE 1. El resto de llamadas de PedidosPage (orders.list, orders.get) son accesibles para lider_compras; settings solo con permiso; vendors solo canSeeTeam.
5. Cobertura: los 3 tests borrados (vendedor solo ve lo propio, no amplía con owner_ids, no ve detalle ajeno) ya no aplican porque el vendedor recibe 403 antes de llegar al servicio; el nuevo test lo cubre incluso con `owner_ids`. Se agregó cobertura de Compras (ve todo + detalle ajeno) y superadmin. El alcance own/team de OrdersService sigue cubierto para management. Falta lo señalado en IMPORTANTE 2.
6. Convenciones: commits en español con formato correcto, sin umbrales de negocio hardcodeados, sin emojis, i18n es/en agregado (`nav.salesOrders`). Sin hallazgos.
