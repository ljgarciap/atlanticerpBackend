# Senior Review — Admin&Cont, Estado de Cuenta Batch 8 (SCRUM-529→533, REQ-452→456)

**Fecha:** 2026-08-23
**Resultado: APROBADO. Decisión de esquema escalada a Luis antes de implementar (ver abajo), sin
hallazgos bloqueantes en la implementación resultante.**

Batch 8 de Admin&Cont, primero de "Estado de Cuenta" (ver `docs/architecture/admin-contab-batches.md`)
— encabezado y cierre de ventana (REQ-452), panel "Buscar estado de cuenta" (REQ-453), estado
vacío inicial (REQ-454), encabezado de resultado (REQ-455), descargar/imprimir (REQ-456). Batch 9
(REQ-457→461, sin empezar) agrega la tarjeta de saldo, el bloque de datos de pago, la nota
automática de contexto, la tabla de movimientos y la apertura automática desde Facturación — este
batch es deliberadamente solo el "shell": buscador + encabezado, sin saldo ni movimientos todavía
(esos datos no existen como agregado en ningún endpoint hasta Batch 9).

## Gate real (suite completa)

- Backend: PHPStan Level 8 (app completa) → `[OK] No errors`. `infra/test.sh` completo →
  **2515/2515 tests, 10167+ assertions, OK** (12 tests nuevos de
  `AdminContAccountStatementControllerTest`; 2 fallos de `ClienteFusionLoaderTest` en la corrida
  completa se confirmaron como pollution de otra sesión activa en el mismo Postgres compartido —
  ver "Nota de entorno" abajo — 7/7 verde en corrida aislada, sin relación con este batch).
- Frontend: `npx vitest run` completo → **124 archivos, 1421/1421 tests, OK** (10 tests nuevos de
  `EstadoCuentaPage.test.tsx`). `tsc --noEmit` → sin errores.

## Decisión de esquema escalada a Luis (antes de escribir código)

REQ-455 RN1/RN2 pide mostrar en el encabezado la tarifa, el régimen fiscal (Con/Sin ITBMS) y los
términos de pago (Neto 30, Contado, etc.) "vigentes actualmente" de cada cliente. Investigación
previa a implementar: la tarifa ya existe (`ventas_diseno.master_clients.default_price_type`),
pero régimen fiscal y términos de pago **no existían como atributo por cliente en ningún lado del
sistema** — el "régimen tributario" que sí existe (`admin_contab.fiscal_settings.regimen_tributario`)
es de la EMPRESA, no del cliente. Se presentaron 3 opciones a Luis (nueva tabla en AdminCont /
agregar campos a `MasterClient` en VentasDiseno / placeholder hardcodeado) — eligió **nueva tabla
en AdminCont** (`client_billing_profiles`), sin pantalla de CRUD propia todavía (ningún ticket la
pidió) — se edita por seeder/tinker hasta que un batch futuro pida esa UI, mismo criterio que
`bank_accounts.ultimos_4_digitos` en Batch 5 de Cobros.

`AdminContClientBillingProfile::forMasterClient()` devuelve un default razonable (Con ITBMS / Neto
30) sin persistirlo cuando no existe fila propia — cubre automáticamente cualquier cliente nuevo o
existente sin fila, sin depender de un seeder de backfill que quede desactualizado.

## Diseño de los endpoints nuevos

- `GET /account-statement/clients` — reusa `PaymentService::searchMasterClients()` tal cual
  (inyectado en `AccountStatementService`), mismo buscador que Cobros (REQ-462).
- `GET /account-statement/projects?master_client_id=` — REQ-453 RN1, `SalesProject::whereHas('subClient',
  ...)` — `sales_projects` no tiene FK directa a `master_client_id`, solo a `sub_client_id`
  (confirmado antes de escribir código, ver investigación previa).
- `GET /account-statement?master_client_id=&sales_project_id=` — REQ-455. Valida que el proyecto
  pertenezca de verdad al cliente elegido (`AccountStatementException` → 422) — defensa en
  profundidad, el selector del frontend ya solo ofrece los proyectos del cliente seleccionado.
- `GET /account-statement/export` (Excel, PhpSpreadsheet, mismo patrón que `InvoiceExportService`)
  — REQ-456 RN2. Hoy solo exporta el encabezado (sin tabla de movimientos, que es Batch 9) —
  documentado explícitamente en el docblock de `AccountStatementExportService` para que extenderlo
  en Batch 9 sea el paso natural.
- **PDF/impresión — decisión de diseño explícita, no un descuido:** REQ-456 Escenario 1 dice
  literalmente "se abre el diálogo de impresión del navegador con el documento formateado igual
  que en pantalla" — a diferencia del recibo de Cobros (Batch 7, PDF generado server-side vía
  dompdf), acá el propio criterio de aceptación pide `window.print()` del lado del cliente. Sin
  endpoint de PDF en el backend — clases `print:hidden` de Tailwind (`display:none`, no
  `visibility:hidden`) ocultan buscador/cierre/menú de export al imprimir, evitando el bug real ya
  documentado en `GuiaEntregaModal.test.tsx` (SCRUM-395, `visibility:hidden` paginaba de fondo
  contenido oculto) — acá no aplica igual porque es una página standalone sin nada más detrás.

## Frontend — vista SECUNDARIA sin navegación principal (RN1 REQ-452)

`EstadoCuentaPlaceholderPage.tsx` (dentro de `AppShell`, con sidebar/topbar) se reemplaza por
completo por `EstadoCuentaPage.tsx`, movida al grupo de rutas `FocusedViewShell` (mismo patrón que
`/servicios/tools/kardex`, SCRUM-359) — nunca lleva el menú de navegación principal, se abre en
pestaña nueva desde Facturación (`FacturacionPage.tsx::openEstadoCuenta`, ya existente desde Batch
2, sin cambios). El botón "Cerrar ventana" usa `window.close()`, igual que Kardex.

**REQ-461 (apertura automática desde Facturación) es Batch 9, no este** — la página no lee
`master_client_id` de la query string todavía, aunque `FacturacionPage.tsx` ya lo manda; mientras
tanto el botón abre la pantalla en el estado vacío en vez de preseleccionado. Decisión explícita de
alcance, no un olvido — evita adelantar trabajo de un ticket no aprobado.

## Nota de entorno — sesión concurrente en el mismo checkout/Postgres

Durante esta sesión, otra sesión activa en el mismo checkout local (mismo patrón ya documentado en
memoria — otro equipo/otra sesión comparte máquina) hizo `git stash` + `git pull --ff-only origin
dev` sobre cambios sin commitear de este batch (mensaje del stash: "preservado antes de build de
deploy"), y aparentemente corrió sus propios tests contra el mismo Postgres en paralelo — de ahí
los 2 fallos de `ClienteFusionLoaderTest` en la corrida completa, no reproducibles en aislamiento.
El stash se recuperó sin pérdida (contenido verificado línea por línea contra lo ya escrito). Sin
impacto final en este batch, documentado acá por transparencia.
