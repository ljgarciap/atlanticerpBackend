# Pre-QA SCRUM-1139 — Ajuste de permisos (Félix / asistente / líder de operaciones)

Fecha: 2026-10-14. Entorno: dev.atlanticerp.ai (BE `02f25559`, FE `626bd34`). Estado: **COMPLETADA (parcial, ver "No cubierto") — veredicto PASA con observaciones**. Actualizado tras liberar la IP de dev.

## Bloqueo de la pasada
Tras ~25 GET espaciados 2,2 s (23 contra `/ventas-diseno/*`, 15 de ellos 403 intencionales, más el primer intento de la matriz de Admin&Cont), la IP de Pre-QA quedó inaccesible para dev.atlanticerp.ai (timeout de conexión a nivel de red, no HTTP; test.atlanticerp.ai y otros sitios responden). Es el patrón CrowdSec ya documentado (`feedback_preqa_write_matrix_burst_bans_ip_crowdsec`). No se intentó evadirlo. Más de 90 min sin recuperación. Quedaron sin ejecutar: Playwright (UI), la matriz de Admin&Cont (asistente/operaciones/Félix), las escrituras de Félix en el Pipeline, y la prueba del guardado desde la pantalla de usuarios. Hace falta que el coordinador/DevOps libere la IP (decisión de allowlist/`cscli decisions delete`) o que se repita desde otra IP.

## Lo verificado (en vivo, dev)
Efectivos reales por login (la contraseña = email funcionó, sin `must_change_password` ni MFA):
- Félix: modules solo `admin_contab` (view/view_team/edit true; approve false); resto de módulos false. Permisos: `ventas_diseno.pipeline.edit`, `ventas_diseno.edit.approved`, `pipeline.read_all`, `pass_to_order`, `edit.conditions`, `edit.client_tax_data`, `receptor_fiscal`, `receptor_consumidor_final` (este último es de SCRUM-1140). NO tiene `ventas_diseno.edit/read/write/approve`, `override_min_margin` ni `select_partner_price`. OK criterio 1/4.
- Asistente: sec 1, `admin_contab` view/view_team (edit false), permisos `admin_contab.read/view/view_team`, `crm.read` y `+admin_contab.edit.receptor_fiscal` (único añadido). OK criterio 2.
- Operaciones (Mariam): `admin_contab` view/view_team true, edit false; `+receptor_fiscal`; conserva `compras.limited.view`, `compras.sustitutos.manage`, `compras.view`, `operaciones.*`; compras/operaciones view sin cambio. OK criterio 3/4.

API de Félix (200/403), dev:
- 200: `GET /pipeline`, `/pipeline/7`, `/pipeline/7/product-delivery-assignments`, `/master-clients?search=a`, `/sub-clients?master_client_id=7724`.
- 403: `/quotes` (lista), `/clients`, `/clients/{id}`, `/sub-clients/{id}/contacts`, `/sub-clients/{id}/legacy-history`, `/orders`, `/orders/{id}`, `/catalog-products`, `/catalog`, `/cotizaya`, `/projects`, `/dashboard/summary`, `/home/summary`, `/calendar-events`, `/sales-commission-tiers`, `/pricing-settings`. OK: sin escalada a clientes/pedidos/catálogo.

Estática (origin/dev):
- `routes/ventas-diseno.php`: el OR `pipeline.edit` está solo en `/pipeline/*` y en 4 rutas master/sub-clients (GET/POST); `/clients`, contactos, historial ICG, categoría, cotizaciones, pedidos y catálogo siguen exigiendo `read/write/edit`.
- `routes/admin-contab.php`: toda línea `role:` con `asistente_administrativa` incluye `lider_operaciones`; los 3 grupos sin la asistente (`cash-position/export`, `cash-position/real`, `reports/felix-commission`) quedan sin operaciones. Controladores `QuoteController`, `OrderStatusController`, `OrderBoardController` ya suman `lider_operaciones` (M1 de la Senior Review corregido). FE: no quedan arrays `asistente_administrativa` sin `lider_operaciones` salvo comentarios.
- M2 de la Senior Review corregido: migración `2026_10_14_350000_add_pipeline_edit_and_receptor_permissions_to_catalog.php`; todos los extras de Félix, asistente y operaciones tienen fila de catálogo en alguna migración (verificado por grep clave a clave), por lo que el guardado desde usuarios no debería dar 422 (sin probar en vivo).
- `UserModuleVisibilityGrantSeeder`: solo crea si no hay fila, no toca otros módulos/usuarios; `db:seed --force` no pisa `extra_permissions` (fusión). Sin probar un reseed en vivo.
- Gate de Actions/local en verde para los SHA desplegados: BE phpunit 5525 tests, phpstan ok; FE vitest 2787, tsc/build ok (`~/.atlanticerp-gate/`).

## Hallazgos
### Crítico / Alto
Ninguno detectado en lo ejecutado.

### Medio
- **M-1 (decisión de Luis/PM, no bug de código nuevo): Félix abre cotizaciones por API.** `GET /ventas-diseno/quotes/1` y `/quotes/1/pdf` responden 200 a Félix (por rol `lider_admin_contab` vía `canViewFromAdminContab`, SCRUM-930, preexistente, necesario para el visor de cotización desde Cobros). El criterio 1 dice "NO debe ver ni abrir cotizaciones". La lista y la pantalla de Cotizaciones sí dan 403, pero el detalle/PDF por id es abierto. Ahora se extiende también a operaciones (espejo M1). Confirmar con Luis que se acepta como parte del acceso de Admin&Cont; si no, habría que acotar al contexto de factura/cobro.
- **M-2: `GET /clients/fiscal-directory` responde 200 a Félix** (permiso `edit.client_tax_data`, SCRUM-1002, preexistente, no de este ticket). Es una lectura de clientes más allá de la excepción aceptada (master/sub-clients); confirmar que es aceptable.

### Bajo
- B-1: Pre-QA (esta pasada) provocó un ban de IP en dev con ~25 llamadas en ~1 min (la matriz en segundo plano más los 23 GET no respetaron una pausa mayor); la regla pedía ≥2 s y se usó 2,2 s, pero aun así el umbral de CrowdSec se superó con 403 repetidos. Para futuras pasadas: ráfagas de 403 <= 5 por minuto o desde tests locales.
- B-2: dev solo tiene una tarjeta de Pipeline (id 7, fixture E2E, dueño Félix), así que no se pudo ejercitar edición de tarjetas ajenas del equipo sin sembrar datos. Antes de QA, sembrar al menos una tarjeta de otro vendedor en etapa Propuesta y una Aprobada (hecho no verificado).

## Pendiente de ejecutar (cuando dev vuelva a responder)
1. Playwright como Félix: sidebar (solo Pipeline + Admin&Cont), rutas por URL `/ventas-diseno/cotizaciones`, `/clientes`, `/pedidos`, `/catalogo` (esperado redirect/403), «Nuevo proyecto» (vincular/crear master y subcliente), mover etapas incluida Aprobado, editar tarjeta Aprobada; como asistente y operaciones: sidebar de Admin&Cont, exclusiones (Posición de caja real/export, Comisión de Félix), botón de la UI vs backend; Mariam abre visor de cotización/pedido desde Cobros.
2. Matriz GET de Admin&Cont con los 3 usuarios (script en el scratchpad, `matrix.py`): esperado asistente = operaciones; 403 en `cash-position/real`, `cash-position/export`, `reports/felix-commission` para ambos; 200 para Félix.
3. Escrituras de Félix en una tarjeta propia de prueba `[PREQA-1139]`: crear, mover a Aprobado, editar Aprobado, 403 en `POST /quotes`, `POST /clients`, `PUT /sub-clients/{id}/contacts`.
4. Un vendedor real (regresión de Pipeline) y un usuario solo-`read_all` simulado (tests locales ya cubren).
5. `PUT /users/{felix|asistente|operaciones}` desde superadmin con sus extras actuales -> 200 (no ejecutado: requiere cuenta superadmin real).

## Candidato a e2e permanente
Smoke "Félix: sidebar sin Cotizaciones/Clientes/Pedidos/Catálogo, y Nuevo proyecto funciona" (gate de permiso angosto ya roto una vez en SCRUM-1128). No agregado.

## Segunda pasada (IP liberada) — resultados

### Matriz GET de Admin&Cont, 70 rutas x 3 usuarios (asistente, Mariam, Félix)
- Asistente y Mariam idénticos en las 70 rutas (mismo código en cada una).
- `cash-position/real`, `cash-position/export`, `reports/felix-commission`: 403 para asistente y Mariam; Félix 200/422 (pasa el rol). OK exclusiones.
- Resto 200 o 422 (faltan parámetros) igual para los tres; los 403 de `/settings`-prefijos de mi matriz son idénticos para los tres (artefacto de prefijo, no diferencia).

### Escrituras de Félix en dev (datos `[PREQA-1139]`)
- `POST /pipeline` lead: 201. `POST /master-clients`: 201. `POST /sub-clients` (receptor_tipo 01): 201. Vincular master/sub a una tarjeta ajena: 200.
- Tarjeta de OTRO vendedor (Vendedor Disenador Test 2, creada por él): Félix la ve con `scope=team` (37 tarjetas), la edita (`PUT`) 200, agrega contacto 201 y la mueve Lead -> Diseño 200. Los gates de negocio responden 422 (falta contacto, no saltar etapas, Cotización solo vía cotización real), nunca 403.
- Negativas de Félix: `POST /quotes`, `POST /clients`, `POST /sub-clients/{id}/contacts`, `PATCH /clients/sub-clients/{id}/category`: 403. Asistente `PUT /pipeline/{id}` y `POST /master-clients`: 403. Mariam `PATCH /pipeline/{id}/stage` y `POST /pipeline`: 403.
- Mover a Aprobado y editar una tarjeta Aprobada con `edit.approved`: NO probado en vivo (sembrar una tarjeta Aprobada exige ssh de escritura, denegado; el gate real de Aprobado pide archivos y fecha). Cubierto por `PipelineEditPermissionTest` (gate local verde); riesgo bajo.

### UI (Playwright headless contra dev)
- Félix: `/ventas-diseno/pipeline` carga con «+ Nuevo Proyecto» (modal Lead/Diseño con contactos, funciona) y sin «+ Nueva cotización». Por URL directa `/ventas-diseno/quotes-list`, `/clients`, `/pedidos`, `/catalog`, `/cotizaya`, `/home` redirigen a `/admin-contab/inicio` (sin acceso). Sidebar con Pipeline y Admin&Cont, sin Cotizaciones/Clientes/Pedidos.
- Asistente: Cobros, Notas de crédito y Reportes cargan; `/admin-contab/posicion-caja` la manda al inicio (vista excluida); `/ventas-diseno/*` redirige a inicio.
- Mariam: Cobros, Notas de crédito y Reportes cargan; `/admin-contab/posicion-caja` la manda a `/compras/ordenes` (su inicio); `/ventas-diseno/*` redirige. Visor: `GET /quotes/1` y `/quotes/1/pdf` 200 (aceptado por Luis).
- Vendedor (Vendedor Disenador Test 2): Pipeline, Cotizaciones y Clientes cargan igual que antes; ve «+ Nuevo Proyecto» y «+ Nueva cotización» (sin regresión).

### Aceptado por Luis (ya no es hallazgo)
Félix y Mariam abren `GET /quotes/{id}` y su PDF (acceso de Admin&Cont por rol); Félix abre `GET /clients/fiscal-directory` (Mariam no: 403, no tiene `edit.client_tax_data`, correcto). Mover tarjetas a cualquier etapa, incluida Aprobado, permitido para Félix.

### Hallazgos finales
- Crítico / Alto: ninguno.
- Medio: ninguno (M-1 y M-2 reclasificados como aceptados).
- Bajo: (1) Pipeline muestra a Félix el botón «Catálogo», que lleva a una ruta sin acceso (redirige al inicio de Admin&Cont): botón muerto, ocultarlo con el mismo criterio que «Nueva cotización». (2) Pre-QA dejó datos de prueba en dev que no pudo borrar (ssh de escritura denegado): pipeline_cards 37 y 38, sales_projects 35 y 36, contacto 33, master client 7739 y sub client 7750, todos `[PREQA-1139]` (la 38 pertenece a Vendedor Disenador Test 2). Borrarlos por quien tenga acceso. (3) Pre-QA provocó un ban de IP de ~90 min antes (ráfaga de 403).

### No cubierto (con riesgo)
- `PUT /users/{id}` desde superadmin con los extras de Félix/asistente/Mariam: sin credencial superadmin; riesgo bajo, cada extra tiene fila de catálogo (grep por clave) y `Scrum1139PermissionCatalogTest` + gate verde.
- Mover a Aprobado y editar tarjeta Aprobada en vivo (arriba): riesgo bajo.
- Visor de pedido (Bodega) de Mariam desde Cobros y `db:seed --force` recreando/pisando datos en vivo: cubiertos solo por tests y lectura de seeders (idempotentes por fusión / solo-si-no-existe).
- Escalada vía rutas de otros módulos (Compras/Bodega) para Félix/asistente: no se barrió el resto de módulos, solo Ventas&Diseno y Admin&Cont.

## Veredicto final
**PASA con observaciones.** Sin bloqueantes; los pendientes son de bajo riesgo y no deben frenar el paso a test. Candidato a e2e permanente (no agregado): Félix sin Cotizaciones/Clientes/Pedidos por URL y con «Nuevo Proyecto» funcionando.
