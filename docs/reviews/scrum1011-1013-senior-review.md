# Senior Review SCRUM-1011 / SCRUM-1013 (2026-10-01)

Ramas: BE `scrum-1011-1013-be` (2 commits sobre origin/dev), FE `scrum-1011-fe` (1 commit).

## Veredicto
- SCRUM-1011: **APPROVED** (0 bloqueantes; 2 sugerencias BAJO)
- SCRUM-1013: **APPROVED con condicion operativa** (0 bloqueantes; 1 MEDIO operativo, 1 BAJO)

## SCRUM-1011
Verificado leyendo codigo:
- `ClientController::storeSubClient` (ClientController.php ~L129-148): `from_design_context` se lee y se hace `unset` antes de `ReceptorTipo::normalizar` / `SubClient::create`; ademas `SubClient::$fillable` no lo incluye. Sin fuga por mass assignment.
- Sin bandera (default false) se sigue llamando `createLeadCard` (comportamiento SCRUM-79 intacto; PipelineCardModal llama `subClients.create(...)` sin 5to arg, test existente `...toHaveBeenCalledWith(1,'Nueva SA','155-1234-1-2026','81')` sigue pasando).
- `storeMasterClient` (ClientController.php L60-75) solo hace `MasterClient::create`; nunca crea Lead (no requiere cambio).
- Regla `'sometimes','boolean'` en StoreSubClientRequest; FE solo manda la bandera cuando es true. El ClientPicker de NewProjectModal solo se muestra con type==='design' (L224-239), unico llamador con `true`.
- Creacion de Cliente/Subcliente no cambia (se crean y quedan en Clientes); validacion y permisos intactos.

Hallazgos:
- BAJO: no hay test de la ruta con `from_design_context: false` explicito (cubierto por equivalencia con el test existente sin bandera). Opcional.
- BAJO: el docblock de PipelineService ya afirmaba antes que sub-clients no disparaba Lead (era falso); ahora esta corregido. Ok.

Tests: ClientControllerTest + ClientsControllerTest pasan (nuevo `test_1011_crear_subcliente_desde_diseno_no_dispara_tarjeta_lead`, asserta 0 PipelineCard y sub_client existente). FE: NewProjectModal (13) y PipelineCardModal (55) pasan; `tsc --noEmit` limpio.

## SCRUM-1013
(a) Solo lectura: en `routes/compras.php` `compras.limited.view` aparece unicamente en rutas GET (L63, 69, 73, 105, 237, 243, 265, 266); ninguna POST/PUT/PATCH/DELETE lo acepta. `InventoryController::resolveAccess` (L956-966) calcula `canManage` solo con superadmin o `compras.read`, nunca por `limited.view`. OK.
(b) `User::effectivePermissionsV2` (User.php ~L285-330): VIEW_READONLY no emite `.read/.write/.edit`; test nuevo `test_1013_vendedor_disenador_ve_compras_sin_el_permiso_amplio_de_lectura` lo cubre. OK.
(c) Migracion `2026_10_01_110000_*`: inserta solo si no existe fila (no degrada overrides), `down()` borra solo la fila exacta (can_view=1, view_team=false). Idempotente y reversible. No hay choque: las 2026_10_01_1000xx/100x00 del otro equipo tocan bodega/lafayette, tablas distintas; orden 110000 posterior. Lanza RuntimeException si falta el rol (igual que migraciones hermanas).
(d) Efectos colaterales: corridas verdes en tests/Feature/Auth, Users, Compras (468 OK, 1 skip), ClientController(s), InventoryController. Los 4 tests actualizados ajustan conteo 3->4 y compras NONE->READONLY de forma coherente. Nota: la corrida de CRM/Bodega/AdminCont/VentasDiseno completa fue cortada por limite de tiempo (suite larga); se verificaron los archivos que referencian vendedor_disenador mas relevantes (Auth, Users, Compras, VentasDiseno Client*). Recomendar correr suite completa en el gate real (infra/test.sh por el Arquitecto).
(e) Los 10 emails del SpecialPermissionSeeder coinciden 1:1 con los 10 `role_key => 'vendedor_disenador'` de BusinessRoleUserSeeder (conteo 10, verificado). Se conserva `admin_contab.commissions_internal.view`.
PHPStan nivel 8: `[OK] No errors`.

Hallazgos:
- MEDIO (operativo): la migracion no siembra `user_module_menu_visibility`; los 10 vendedores veran en el menu de Compras items que Operaciones tiene ocultos (Inicio, Proveedores, Nueva orden, Agencias, Pagos, Reclamos, Reportes) y que responderan 403. Es cosmetico (el bloqueo real existe) pero hay que configurarlo por el panel en dev/test/prod, igual que SCRUM-771; el docblock lo declara. Conviene dejar el paso en el ticket/Pre-QA.
- BAJO: mientras Compras este oculto por la mascara UAT (UatVisibilityService, `resolveAccess` L944-952), los vendedores no accederan en entornos donde siga oculto; no es defecto, solo expectativa para QA.
- BAJO: el seeder corre en todos los entornos pero solo agrega permisos (idempotente por fusion); los usuarios deben existir (CoreUser/BusinessRole seeder corren antes). OK.
- Aceptado por Luis: la replica exacta abre Ver Ordenes/Ingresos/Inventario (incluido sin ocultar costos, como Operaciones).

## Test conocido TicketBatch3Part2Test::test_buscar_master_clients_filtra_por_nombre
Falla identico (500, SQLSTATE 25P02 transaccion abortada) en worktree limpio de origin/dev con la misma BD aislada: NO es regresion de este diff, es un artefacto del entorno aislado (BD `atlanticerp_wt1011`, probablemente por el termino con acento "Pacifico" / configuracion de la BD). El resto del archivo (10 tests) pasa.

## Comandos ejecutados
- phpunit Auth + Users + ClientController/ClientsController + Compras/InventoryControllerTest: 459 OK / 1803 assertions.
- phpunit tests/Feature/Compras: 468 OK (1 skip).
- phpstan analyse: No errors.
- vitest NewProjectModal + PipelineCardModal: 68 passed; tsc: sin errores.
- worktree be-base y redis desechable eliminados.
