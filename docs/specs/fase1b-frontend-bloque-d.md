# Spec — Fase 1b: el frontend deja de decidir por NOMBRE de rol (bloque D, lado frontend)

**Fecha**: 2026-10-24 · **Estado**: APROBADA por Luis e IMPLEMENTADA 2026-10-24 (ver «Resultado de la implementación» al final). Las 3 decisiones de §6 se aprobaron tal como se recomendaron.
**Contexto**: continúa `fase1-limpieza-nucleo.md` (bloque D). El backend ya no tiene ningún `role:` en rutas; el frontend sí.

## 1. Problema
Las pantallas del frontend se protegen y se muestran comparando `user.role` contra claves de rol fijas
(`'management'`, `'lider_compras'`, ...). Es el mismo acoplamiento que la Fase 1 quitó del backend. Con la Fase 3
(roles genéricos/renombrados) cualquier renombre rompería accesos en silencio, y un rol nuevo configurado por datos
nunca vería esas pantallas aunque tenga el permiso.

No es un hueco de seguridad (el backend aplica el permiso real); es deuda de configurabilidad y riesgo de divergencia.

## 2. Alcance medido (corrige la estimación anterior)
El conteo previo («71 `RequireRole`, 12 en `Sidebar`, ~17 archivos») contaba menciones en comentarios. Real, sobre `master`:

| Lugar | Qué hay |
|---|---|
| `src/App.tsx` | **30** elementos `<RequireRole>` + la definición (línea ~133) y su prop `orGerenciaScope` |
| `src/components/Sidebar.tsx` | 8 comparaciones: `canSeeSecurity` (l.132), `isLiderCompras` (l.158), `isServiciosLimited` (l.398), `canViewServiciosSettings` (l.399), `ADMIN_CONTAB_FULL_ACCESS_ROLES` (l.458), 2 × `=== 'superadmin'` (l.679, l.712) |
| `src/components/SecurityLayout.tsx` | `isDepartmentsRole` (l.20) |
| `src/pages/servicios/ToolsAndSuppliesPage.tsx` | `canViewToolKardex(role)` |
| `src/components/servicios/ReportsCommissionSection.tsx` | `isManagement` (l.59) |
| `src/pages/GerenciaPage.tsx` | `isSuperadmin` por rol (l.179) |
| `src/lib/homeRoute.ts` | comentarios; verificar si decide por rol |
| Tests | `Sidebar.test.tsx` y los que mockean `user.role` |

Ya correctos (sin cambio): `UserFormDrawer`, `SettingsPage`, `FraudSettingsPanel`, `BodegaSettingsPanel` usan `usePermission('superadmin.all')`.

## 3. Diseño propuesto
`RequireRole` pasa a ser un **alias de `RequirePermission`** y se elimina; el frontend lee `user.permissions`
(que ya incluye el escape hatch por nivel y `ApplyGerenciaScope`). Mapeo de las 30 guardas, por conjunto de roles:

| Guardas | Roles actuales | Permiso nuevo en `RequirePermission` | Nota |
|---|---|---|---|
| 20 (Admin & Contab) | superadmin, lider_admin_contab, asistente_administrativa, lider_operaciones, management | `admin_contab.records.manage` | Equivale exactamente: la migración `2026_10_23_300000` da `records.manage` a Operaciones; superadmin entra por `superadmin.all`. |
| 3 + 1 + 1 (Gerencia) | management, superadmin (+ `orGerenciaScope` en `/crm/dashboard`) | `platform.management` | `ApplyGerenciaScope` ya le suma este permiso a quien tiene `gerencia_scope`, así que **`orGerenciaScope` se elimina**. |
| 1 (`/ventas-diseno/pedidos`) | lider_compras, management, superadmin (+ scope) | `ventas_diseno.orders.access` | Mismo caso; la migración `2026_10_24_200000` lo asigna a ambos niveles. |
| 3 | solo superadmin | `superadmin.all` | |
| 1 (Kardex de herramientas) | lider_servicios, management, superadmin | **a decidir (ver §6.1)** | |

Componentes sueltos: `canSeeSecurity` → `platform.management` (más los `security.*` que ya exige);
`canViewServiciosSettings` → `platform.management`; `isLiderCompras` → derivar de módulos/permisos
(`compras.read` sin `ventas_diseno.read`), no del nombre; `isServiciosLimited`, `ADMIN_CONTAB_FULL_ACCESS_ROLES`,
`isManagement`, `isSuperadmin` → su permiso equivalente (`admin_contab.records.manage`, `platform.management`, `superadmin.all`).

## 4. Criterios de aceptación
1. Cero ocurrencias de `RequireRole`, `orGerenciaScope` y de comparaciones `user.role ===`/`.includes(user.role)` en `src/` (fuera de tests de compatibilidad).
2. **Test de equivalencia** (el entregable principal): para cada una de las 14 identidades sembradas (una por rol del catálogo + un usuario con `gerencia_scope`) y cada ruta protegida, el resultado permitido/redirigido **antes** y **después** es idéntico. Se genera la tabla "antes" desde el árbol actual y se congela como fixture; el test falla ante cualquier diferencia.
3. Los mismos permisos que usa el frontend existen en el JWT de cada identidad (test contra la salida real de `effectivePermissionsV2()`, no contra un mock).
4. Suite frontend completa y `tsc --noEmit` en verde; CI en verde.
5. Verificación visual con **Playwright CLI** contra el stack local: login con 4 identidades (gerencia, asistente administrativa, vendedor, técnico de servicios) y captura del menú de cada una antes/después.

## 5. Fuera de alcance
Renombrar roles (Fase 3), Fase E del ADR-006 (Fase 2), tocar permisos del backend salvo lo que decida §6.1.

## 6. Decisiones abiertas
1. **Kardex de herramientas**: el backend no expone una ruta de kardex con permiso propio que yo haya encontrado;
   hoy la gate es solo de frontend (`lider_servicios`+`management`+`superadmin`). Opciones: (a) permiso fino nuevo
   `servicios.tools.kardex.view` por nivel (con chequeo de fuga: `lider_servicios` ya tiene nivel propio, 14);
   (b) reusar un permiso existente de herramientas. Recomiendo (a). Requiere migración de backend pequeña.
2. **`ReportsCommissionSection`**: además de `isManagement`, el acceso depende de ser el técnico con `has_bonus_plan`
   (ver hallazgo de Review: nombre de persona en endpoint/identificadores). Propongo resolverlo junto con 1b:
   gate por `servicios.technicians.commission.view_own` + `platform.management`, y renombrar
   `comision-carlos-vergara` → `comision-tecnico` en backend y frontend (cambio de contrato de API, coordinado en un solo PR par).
3. ¿Un PR por repo, o 1b en dos PR (guards de `App.tsx` primero, componentes sueltos después)? Recomiendo dos, por el tamaño de revisión.

## 7. Riesgos
- Un permiso que el frontend usa pero el JWT no emite deja a alguien fuera sin error visible → mitigado por el criterio 3.
- `Sidebar.test.tsx` y otros tests mockean `user.role`; hay que migrarlos a `permissions`, no solo ajustar asserts.
- Orden con la Fase 3: hacer 1b **antes** de renombrar roles, para que el renombre no toque el frontend.

## 8. Resultado de la implementación (2026-10-24)

Rama backend `fase1b/frontend-bloque-d`; frontend 2 PR apilados: `fase1b/guards-app` (A) y `fase1b/frontend-bloque-d` (B, sobre A).

**Lo que difirió del borrador**
1. **El alcance real fue mayor que el medido en §2.** La búsqueda original no veía los helpers `canX(role)` que reciben el rol como parámetro. Además de las 30 guardas y las ~12 comparaciones listadas, había ~20 más (Servicios: `TicketsPage`, `HomePage`, `InternalTechniciansPage`, `ExternalTechniciansPage`, `TicketDetailModal`, `ToolsPanel`, `InsumosPanel`, `HomeMonthlyIndicators`, `InternalTechnicianCommissionModal`, `ServiciosSettingsPanel`; Admin & Contab: `ArqueoCajaPage`, `ComisionesExternasPage`, `ReportesPage`, `SolicitudesReversoPanel`; `ReplenishmentPage`, `CatalogPage`). Todas migradas.
2. **`orGerenciaScope` no se eliminó sin más** (corrige §3): `ApplyGerenciaScope` solo añade `platform.management` / `ventas_diseno.orders.access` a la petición EN EL SERVIDOR, no al `user.permissions` que ve el frontend. Se reemplazó por «`platform.management` **o** `ventas_diseno.gerencia_scope`» (`GUARD.GERENCIA_O_ALCANCE`, `GUARD.PEDIDOS`).
3. **Mapeo de los helpers sueltos**: `tickets.manage` (agendar, herramientas, insumos, alta de técnico), `tickets.create`, `field_ops.admin` (técnicos externos, captura de comisión), `settings.configure` (ajustes, meta del mes, SLA), `claim_sheets.manage` (plantilla en blanco), `field_work.edit` (informe asignado), `admin_contab.operations/records/oversight.manage`, `ventas_diseno.orders.access`.
4. **§6.1** → migración `2026_10_24_400000` (permiso `servicios.tools.kardex.view` a `lider_servicios` y `management`, con chequeo de fuga). **§6.2** → `/comision-carlos-vergara` ahora es `/comision-tecnico` (backend y frontend). **§6.3** → dos PR por repo.

**Excepciones que SIGUEN decidiendo por rol a propósito** (el backend las decide por rol; igualarlas en la UI evita mostrar botones que terminan en 403):
- `UsersPage`: reset de contraseñas y «Ver como usuario». La ruta pide `permission:superadmin.all`, pero `AdminPasswordResetService::operatorIsStillSuperadmin` revalida en BD el ROL BASE `superadmin` (H3 del threat model).
- `BodegaOrdenesZonaLibrePage.canEdit`: el controlador (`BodegaZonaLibreRequestController::update`) decide por `role_key === 'lider_bodega'`.
Convertirlas requiere cambiar primero el backend (ver §9).

**Verificación**
- Test de equivalencia `src/lib/accessGuards.equivalence.test.ts`: 14 identidades reales del seed + 1 con alcance por persona, 6 guardas de ruta y 10 capacidades. Coinciden todas salvo usuarios cuyo ROL no concuerda con sus PERMISOS efectivos (la Gerencia 5 de nivel restringido y usuarios demo `*@atlantic.test`); en todas la regla nueva coincide con lo que ya aplica el backend. Están enumeradas y el test falla ante cualquier otra, o si una desaparece.
- Frontend: `tsc` limpio, 247 archivos / 3110 tests (cada PR verifica por separado). Backend: 110 tests de Servicios (matriz de permisos + reportes) y PHPStan 0 errores.
- Playwright CLI contra el stack local (5 identidades: Gerencia, Asistente Administrativa, Vendedor/Diseñador, Técnico y Líder de Servicios), build de `master` vs build nuevo: menús idénticos (15/21/13/17/17 ítems) y acceso por URL directa idéntico en 5 rutas protegidas.

## 9. Pendientes que salieron de la 1b
- Backend: `BodegaZonaLibreRequestController` y `AdminPasswordResetService` aún deciden por `role_key` (ver excepciones). Si se quiere cerrar del todo, hay que darles permiso propio antes de tocar el frontend.
- Nombres de personas que siguen en el código: endpoint `/reports/felix-commission` y `FelixCommissionCard` (Admin & Contab), y el texto i18n «Esta pantalla es exclusiva de Mark» (`CompanyProfilePage`/Fiscal). Entran en la Fase 4 (demo genérica) o antes si Luis lo pide.
- `usersApi.isMaxPrivilegeUser` inspecciona los roles del usuario OBJETIVO (elegibilidad de impersonación), no un gate de acceso: se dejó.
- Lección de proceso: el nginx de desarrollo monta `atlanticerp-frontend/dist` por inodo; reemplazar el directorio (`mv`/`rm`) deja al contenedor sirviendo el build viejo. Copiar el contenido EN el mismo directorio (`rsync -a --delete origen/ dist/`) o reiniciar el contenedor.
