# Pre-QA — SCRUM-930: Corrección y mejora UAT — Estado de Cuenta

2026-09-20. Ticket en Jira: `To Do` (código completo en el working tree de `atlanticerp-backend`/
`atlanticerp-frontend`, sin commitear/pushear todavía — Senior Review aprobado el mismo día,
`docs/reviews/scrum930-senior-review-20260920.md`). Diseño: `docs/adr/ADR-SCRUM930-estado-de-cuenta-correcciones.md`.
Sin mockup adjunto en Jira — Visual Review no aplica (confirmado).

Stack local Docker (no `dev.atlanticerp.ai`), Playwright CLI contra `http://localhost:5173`
(frontend, vía `webServer` de `playwright.config.ts`) y `http://localhost:8090` (API vía nginx —
puerto real del mapeo actual del compose local, no el 80 documentado en la skill). Login real:
Felix (`lider_admin_contab`, `contabilidad@test.com` / misma contraseña, `password=email`).

## Fixtures sembrados (dato real insuficiente en el entorno local)

El entorno local solo tenía 1 factura en toda la base (`F-0001`, cliente sintético de un Pre-QA
anterior, SCRUM-858). Insuficiente para ejercer los 4 buckets de antigüedad, las 4 filas
no-factura y el escenario de familia con guía en otro `order_id`. Sembrado a mano vía Eloquent
(tinker, mismo master client `id=5`, mismo pedido raíz `id=9`/`F-0001`, respetando FKs reales —
no INSERT crudo):

- **orderB (id 10)** — hermano de la familia, con `OrderDocument` tipo `guia_entrega` (SIN
  factura propia). Objetivo: la factura `F-0001` (`completo`, `order_id=9`) debe resolver
  `guia_entrega_order_id=10` (≠ su propio `order_id`), probando el caso "familia con varias
  entregas" que el ADR marca como el motivo real de `resolveGuiaEntrega()`.
- **orderC/invC (id 11/6)** — `parcial`, `created_at` −45 días (bucket 31-60) + nota de crédito
  `descuento_comercial` aplicada (fila `tipo=descuento`).
- **orderD/invD (id 12/7)** — `parcial`, `created_at` −75 días (bucket 61-90) + cobro confirmado
  parcial (`AdminContPayment`+`AdminContPaymentInvoice`, fila `tipo=cobro`).
- **orderE/invE (id 13/8)** — `parcial`, `created_at` −120 días (bucket 91+), `anulada_at` −100
  días + nota `anulacion_completa` (fila `tipo=factura` 91+ **y** fila `tipo=anulacion`).
- **orderF/invF (id 14/9)** — familia propia, sin tarjeta de pipeline (`sales_project_id=null`):
  sin cotización vinculada, sin guía — fila de factura con AMBAS celdas de documento en "—".
- **bank_account (id 1)** — no había ninguna cuenta bancaria activa en el entorno (punto 2 no
  era verificable sin esto): `Banco General`, `04-01-99-123456-4321`, activa.

Verificado además con `GET /api/admin-contab/account-statement?master_client_id=5` (curl directo)
antes de tocar el navegador, para separar "el payload está mal" de "la UI lo pinta mal" — el
payload salió exactamente como predice el ADR en los 8 casos de arriba.

## Los 6 puntos — resultado

1. **Guía de Entrega muestra el número real.** `PED-2026-PREQA858-134424-G`, nunca "Sí" — ninguna
   fila del `body` contiene el string "Sí" (aserción explícita, cero coincidencias). **OK.**
2. **Número de cuenta sin máscara.** Con una cuenta bancaria real cargada, `04-01-99-123456-4321`
   se ve completo en el bloque de datos de pago; cero apariciones de `**` en toda la pantalla.
   Confirmado que sigue siendo una no-regresión (SCRUM-857/928), como predice el ADR. **OK.**
3. **Columna "Antigüedad de cartera".** Inmediatamente después de "Saldo" en el `<thead>`
   (verificado por índice, no por inspección visual). Los 4 buckets (`0–30`, `31–60`, `61–90`,
   `+91 días`) aparecen con datos reales de `created_at` distintos, calculados desde creación (no
   vencimiento) — confirmado indirectamente: la factura 91+ (creada hace 120 días) NO se ve
   afectada por su fecha de vencimiento/crédito, coherente con el diseño. Filas no-factura
   (`cobro`, `descuento`, `anulacion`) muestran "—", no heredan el bucket de la factura de origen.
   **OK.**
4. **Los 4 documentos son clickeables y abren el modal correcto.**
   - Factura (`F-0001`) → `InvoiceDetailModal` con el `order_number`/número correctos. OK.
   - Nota de Crédito (fila "Descuento" de `F-930-C`) → `DetalleNotaCreditoModal` con el número de
     la nota (`NC-930-DESC-...`) visible. OK.
   - Cotización (`COT-2026-PREQA858-...`) → `QuoteViewerModal` con el folio correcto. OK.
   - Guía de Entrega → **CRÍTICO, ver abajo.** El modal abre pero se queda en "Cargando..." para
     siempre — nunca muestra el documento.
   - Filas sin guía/cotización (`F-930-F`) se ven como texto plano "—", no botones rotos. OK.
5. **"Estado de Cuenta" en el Sidebar, entre "Inicio" y "Facturación".** Orden confirmado por
   índice de los labels reales del `<nav>` (no solo visualmente). Un clic abre una ventana nueva
   (`window.open`, confirmado con `context.waitForEvent('page')`) sin cliente preseleccionado
   (input de búsqueda vacío) y **sin navegar la pestaña original** (`page.url()` no cambia).
   Submenús del resto de items no se tocaron (no auditado exhaustivamente, fuera del riesgo real
   de este cambio — es un ítem nuevo agregado al array, no una reescritura del resto). **OK.**
6. **Botón "Status de Pedidos" a la izquierda de "Cerrar Ventana".** Ambos visibles en el popup.
   "Status de Pedidos" navega de verdad a `/bodega/pedidos/status` dentro del popup. "Cerrar
   Ventana" cierra la ventana de verdad (`window.close()`, confirmado con evento `close` de
   Playwright — mismo mecanismo que ya usaba el botón de `FacturacionPage`, sin cambios). **OK.**

## Hallazgo — CRÍTICO

**Guía de Entrega, abierta desde Estado de Cuenta, se queda cargando para siempre — 403 silencioso
para el rol que realmente usa esta pantalla.**

Repro:
1. Login como `contabilidad@test.com` (Felix, `lider_admin_contab` — uno de los 4 roles con
   acceso completo a Admin&Contab, `ADMIN_CONTAB_FULL_ACCESS_ROLES` en
   `atlanticerp-frontend/src/components/Sidebar.tsx:392`).
2. Estado de Cuenta → cliente con una factura que tiene guía de entrega → clic en el número de
   guía (columna "Guía de Entrega").
3. El modal `GuiaEntregaModal` abre, encabezado "Guía de Entrega" visible, pero el cuerpo se queda
   en "Cargando..." para siempre. Sin mensaje de error, sin botón de reintentar.
4. Causa raíz confirmada con `page.on('response', ...)`:
   `GET /api/bodega/orders/{orderId}` → **403** (`No tienes permiso para realizar esta acción.`).

**Por qué pasa:**
- `GuiaEntregaModal.tsx` (reusado tal cual por SCRUM-930, sin tocarlo — así lo dice el ADR) llama
  `useOrderDetail(orderId)` (`atlanticerp-frontend/src/hooks/useBodega.ts:220-226`), que pega contra
  `GET /api/bodega/orders/{id}` → `OrderBoardController::show()`
  (`atlanticerp-backend/routes/bodega.php:113`, `Route::get('/{id}', ...)->middleware('permission:bodega.read')`).
  Felix (y `asistente_administrativa`, el otro rol no-management con acceso completo a Admin&Contab)
  **no tienen `bodega.read`** en su JWT (confirmado: su payload de login no incluye ningún
  `bodega.*`).
- `GuiaEntregaModal.tsx:85-86` — `{isLoading || !order ? <p>Cargando...</p> : ...}` — cuando
  TanStack Query pasa a `isError` (tras agotar sus 3 reintentos default contra un 403, que no es
  transitorio), `isLoading` se apaga pero `order` (=`data`) sigue `undefined`, así que la condición
  `!order` sigue siendo verdadera y el componente se queda mostrando "Cargando..." para siempre —
  nunca hay una rama de error. Mismo patrón de "falla silenciosa sin feedback visual" que
  SCRUM-63 (2026-07-14, el precedente que motivó este rol).
- **Esto es nuevo por SCRUM-930, no una regresión preexistente**: antes de este ticket,
  `GuiaEntregaModal` solo se abría desde pantallas de Bodega (donde el usuario ya tiene
  `bodega.read` por construcción). SCRUM-930 lo cablea por primera vez desde Admin&Contab
  (`EstadoCuentaPage.tsx:347`), un módulo cuyos roles principales no tienen ese permiso.

**Por qué no lo arreglo yo mismo (bloquea el batch, no es "documentado, sigo con el próximo"):**
`OrderBoardController::show()` devuelve `$this->board->detail($order)` — el detalle COMPLETO del
tablero de Bodega (picking, asignaciones, posiblemente costos), no solo lo que
`GuiaEntregaModal`/`GuiaEntregaModal` necesita mostrar. Agregar `admin_contab.read` como OR al
middleware de esa ruta abriría ese detalle completo a cualquier usuario de Admin&Contab, no solo
la guía — superficie más ancha de la que este ticket pide. El proyecto ya tiene el patrón correcto
para esto (`OrderStatusController::resolveAccess()`, ver comentario en
`atlanticerp-backend/routes/bodega.php:105-111`: "no se puede expresar 'bodega.read O ventas_diseno.read
restringido' con un solo string") — este caso es análogo, pero decidir el endpoint/alcance correcto
(¿un `resolveAccess()` en `OrderBoardController::show()`? ¿un endpoint nuevo, angosto, solo para
datos de guía, expuesto también bajo `admin_contab.read`?) es una decisión de diseño, no un
one-liner. Necesita al Arquitecto/PM antes de tocarlo.

**Archivos/líneas relevantes para quien lo retome:**
- `atlanticerp-backend/routes/bodega.php:113` — middleware `permission:bodega.read` en la ruta que
  `GuiaEntregaModal` termina llamando.
- `atlanticerp-backend/app/Modules/Bodega/Http/Controllers/OrderBoardController.php:49-57` — `show()`,
  devuelve detalle completo del tablero.
- `atlanticerp-frontend/src/components/GuiaEntregaModal.tsx:85-86` — el `isLoading || !order` que se
  queda en "Cargando..." sin rama de error (arreglo independiente y sí trivial: agregar un
  `isError` que muestre un mensaje, en vez de nada — recomendado igual aunque se resuelva el
  permiso, para que un 403/500 real en el futuro no vuelva a quedar mudo).
- `atlanticerp-frontend/src/hooks/useBodega.ts:220-226` — `useOrderDetail()`, el hook que dispara la
  request.

**Impacto:** bloquea el criterio de aceptación 4 del ticket ("El número de Guía de Entrega... es
clickeable y al hacer clic abre el modal de detalle de esa Guía de Entrega") para el usuario real
de esta pantalla. No bloquea los otros 3 documentos (Factura/Nota Crédito/Cotización), que sí
abren correctamente para Felix.

## Lo que sí funciona (confirmado en vivo, no solo leído)

- Punto 1 (número de guía real, nunca "Sí") — factura `completo` con familia de 2 pedidos,
  `guia_entrega_order_id` distinto del `order_id` de la factura, resuelto y mostrado bien.
- Punto 2 (cuenta sin máscara) — con y sin cuenta bancaria cargada, nunca asteriscos.
- Punto 3 (Antigüedad de cartera) — posición de columna, 4 buckets con datos reales, filas
  no-factura en "—", cálculo desde `created_at` (no vencimiento).
- Punto 4 — Factura, Nota de Crédito y Cotización clickeables, abren el documento CORRECTO (no
  uno de otra fila) — verificado por número/folio, no solo "un modal se abrió".
- Punto 4 (parcial) — filas sin documento vinculado (`F-930-F`, sin guía ni cotización) se ven
  como texto plano, nunca un botón que abre un modal vacío.
- Punto 5 — orden exacto del menú (Inicio → Estado de Cuenta → Facturación), abre en ventana
  nueva sin cliente preseleccionado, no navega la pestaña original.
- Punto 6 — "Status de Pedidos" navega de verdad; "Cerrar Ventana" cierra de verdad.
- Buscador sin preselección — cliente inexistente da 0 resultados sin crash; "Buscar" sin
  seleccionar cliente muestra error inline (nunca `window.alert()`).
- Doble clic en el ítem del Sidebar — abre 2 ventanas (comportamiento idéntico al botón
  preexistente de `FacturacionPage`, mismo `window.open()`, no es una regresión de este ticket).
- Cero iconografía de emoji en cualquier elemento nuevo de esta pantalla (columna nueva, ítem de
  menú, botón) — aserción explícita contra todo el texto de la pantalla, no solo inspección visual.
- Vista agrupada por proyecto no se rompe con una fila sin proyecto (`F-930-F`, `sales_project_id
  = null` → cae en el grupo "Sin proyecto", sin excepción ni pantalla en blanco).

## Veredicto (primera pasada, 2026-09-20 ~09:50)

**No pasa.** 1 hallazgo CRÍTICO (guía de entrega, permiso cruzado Bodega↔Admin&Contab). El ticket
se queda en `Dev Testing` — no se transiciona a `QA`. Bloquea el cierre de este batch hasta que se
decida el enfoque de fix (ver arriba) y se corra de nuevo el checklist completo (no solo el punto
4) — regla dura del proyecto, no es válido "documentado, sigo con el próximo".

## Re-verificación tras el fix (2026-09-20 ~10:15)

El Arquitecto resolvió el hallazgo replicando el patrón ya establecido y revisado de
`OrderStatusController::resolveAccess()` en `OrderBoardController::show()` (mismo
`ADMIN_CONTAB_ROLES = ['lider_admin_contab','asistente_administrativa','management']`, mismo
chequeo de `UatVisibilityService::isModuleHidden`), quitando el middleware
`permission:bodega.read` de `GET /api/bodega/orders/{id}` en `routes/bodega.php` y moviendo el gate
al controller. Defensa en profundidad agregada en `GuiaEntregaModal.tsx` (rama `isError` real —
antes un 403 se veía idéntico a "Cargando..." para siempre).

Volví a correr el checklist **completo**, no solo el punto 4:

- **Fixtures reconstruidos desde cero.** El `infra/test.sh` de esta misma sesión hizo
  `migrate:fresh` sobre el Postgres local compartido (gotcha ya documentado en la skill
  `atlanticerp-local-dev`, "no es fresh vs. dev... sembrar fixtures manuales SIEMPRE después del último
  `test.sh`") y se llevó los fixtures de la primera pasada. Reconstruidos con un cliente/familia
  nuevos (`master_client_id` nuevo), mismos 8 escenarios que la primera vez.
- **6/6 specs Playwright en verde** contra `localhost:5173`/`8090`, login real como Felix — incluida
  la prueba específica que antes fallaba: clic en Guía de Entrega ahora carga y muestra el pedido
  CORRECTO de la familia (confirmado por texto distintivo del pedido hermano, no solo "un modal se
  abrió"). Los otros 5 puntos siguen limpios.
- **Verificado independientemente, no solo confiado en el reporte del Arquitecto:**
  - `docker exec infra-laravel-1 vendor/bin/phpstan analyse app/Modules/Bodega/Http/Controllers/OrderBoardController.php --level=8` → `[OK] No errors`.
  - `infra/test.sh` completo (sin nada más corriendo en paralelo) → **3599/3599 verde**.
  - `npx tsc --noEmit` → limpio. `npx vitest run` completo → **1899/1899 verde**.
  - `GET /api/bodega/orders/{orderId}` con el token de Felix → `200` (antes `403`), confirmado por
    curl directo antes de tocar el navegador.
  - Re-corridos también los 2 specs permanentes que Senior Review había ajustado por la colisión de
    nombre accesible del Sidebar (`preqa-batch-895-897-550-881-882-20260911.spec.ts`,
    `preqa-batch-scrum850-855-856-858-860-20260908.spec.ts`): mismos 2 fallos ya documentados por
    Senior Review como preexistentes y sin relación con este ticket (fixtures de otra sesión que no
    persisten tras un `migrate:fresh`, y un caso de la pestaña "Inactivos" de otra pantalla) — nada
    nuevo, nada causado por este fix.

**Veredicto final: pasada limpia y concluyente.** Transicionado a `QA` en Jira (comentario con este
mismo resumen posteado y verificado con `jira_get_issue_comments`, no solo el ack del tool —
gotcha conocido de este workspace).

## Specs de Playwright de esta pasada

`preqa-scrum930-estado-cuenta-scratch.spec.ts`, `preqa-scrum930-punto5-punto6.spec.ts`,
`preqa-scrum930-edge-cases.spec.ts` en `atlanticerp-frontend/e2e/` — todavía **no promovidos a un nombre
permanente único** porque siguen sembrando su propio fixture con un cliente nuevo cada corrida en
vez de reusar uno fijo (útil para esta pasada adversarial, pero no es el patrón de los specs
permanentes del proyecto). El hallazgo de esta ronda (gate de permisos silencioso, mismo patrón que
SCRUM-63) es del tipo que el proyecto sí promueve a test permanente — decisión de quien haga el
commit final si conviene reescribirlos contra un fixture fijo antes de promoverlos, o dejarlos
como están.
