# Spec — Administración de Roles y Permisos (Fase D, ADR-006)

**Módulo:** Auth
**Tickets:** SCRUM-144 (Roles), SCRUM-145 (Permisos)
**Status:** Implementada 2026-07-13 — pendiente Senior Review + push (OK explícito de Luis)
**Fecha:** 2026-07-12 (spec) / 2026-07-13 (implementación)
**Autor:** UX/UI Designer (spec) + Backend Dev + Frontend Dev (implementación) — roles del equipo, ver `atlanticerp/CLAUDE.md`

> **Implementada 2026-07-13.** Todos los criterios de aceptación de esta spec están cumplidos.
> Verificado: 725 tests backend (incl. 6 nuevos), PHPStan Level 8 limpio, 225 tests frontend
> (incl. 9 nuevos: `RolesPage.test.tsx`, `UserFormDrawer.test.tsx` reescrito), `tsc --noEmit`
> limpio, `npm run build` limpio, y smoke test manual en navegador (Playwright, login real contra
> `dev.atlanticerp.ai`-equivalente local) contra las 3 pantallas nuevas + el drawer reescrito, sin
> errores de consola. Ver sección "Implementación" al final de este documento para el detalle
> completo (archivos tocados, decisiones tomadas durante la implementación, gap de backend
> encontrado y corregido). **Nada de esto está commiteado ni pusheado** — mismo gate de siempre
> ([[feedback_push_workflow]]): push solo con OK explícito de Luis, y antes de eso, Senior Review.

> **Corrección de estado (2026-07-12):** la nota de "Fase C sin pushear" que quedó en los
> comentarios de Jira del 2026-07-10 y en `atlanticerp/CLAUDE.md` está desactualizada — el commit
> `f2230cc` (Fase C, cutover de JWT/roles a rol único) se pusheó y desplegó a `dev.atlanticerp.ai` esa
> misma tarde. `dev` y `origin/dev` están sincronizados en `8dbf656`, que incluye `f2230cc`.
> El **backend de Fase D ya está completo** (ver tabla de endpoints abajo) — lo que falta es
> exclusivamente la UI. Esta spec cubre solo eso.

---

## Por qué esta spec existe

SCRUM-144/145 tienen dos criterios de aceptación sin cumplir que son puramente de UI:

- SCRUM-144: *"Es posible crear un nuevo rol o editar la visibilidad de uno existente sin tocar
  código"* — el backend ya lo permite (`RoleController`), no hay pantalla.
- SCRUM-144: *"El listado de usuarios permite filtrar por rol"* — no implementado.
- SCRUM-145: *"Existe una vista donde se pueda ver, por usuario, su rol base + sus excepciones
  (si tiene)"* — no implementada.
- SCRUM-145: la matriz Ver/Crear-Editar/Aprobar por nivel ya tiene endpoints
  (`SecurityLevelController::permissions/updatePermissions`) pero ninguna pantalla la edita.

Además, `UserFormDrawer.tsx` (formulario de alta/edición de usuario) **quedó desalineado por la
Fase C**: sigue mostrando un multi-select de `roles: string[]` (modelo viejo, pre-cutover) en vez
del modelo actual (`role_id` único + `additional_role_ids` gateado a superadmin + los 2 flags
transversales). Esto no es un bug de Fase C — es trabajo de Fase D que nunca se hizo, documentado
como "Tasks 7-8 sin empezar" en la memoria del proyecto.

---

## Backend ya disponible — no se toca en Fase D

| Método | Ruta | Acceso | Uso en esta spec |
|---|---|---|---|
| GET | `/api/roles` | cualquier autenticado | poblar selects de rol (form de usuario, filtro) |
| POST | `/api/roles` | `superadmin.all` | crear rol — `RolesPage` |
| PUT | `/api/roles/{id}` | `superadmin.all` | editar rol — `RoleFormModal` |
| DELETE | `/api/roles/{id}` | `superadmin.all` | borrar rol — `RolesPage` |
| GET | `/api/roles/{id}/visibility` | `superadmin.all` | leer matriz de visibilidad — `RoleVisibilityModal` |
| PUT | `/api/roles/{id}/visibility` | `superadmin.all` | guardar matriz de visibilidad — `RoleVisibilityModal` |
| GET | `/api/security-levels/{id}/permissions` | `superadmin.all` | leer matriz Ver/Editar/Aprobar — `SecurityLevelPermissionsModal` |
| PUT | `/api/security-levels/{id}/permissions` | `superadmin.all` | guardar matriz — `SecurityLevelPermissionsModal` |
| POST/PUT | `/api/users`, `/api/users/{id}` | `security.users` / `superadmin.all` | ya acepta `role_id`, `additional_role_ids` (solo si actor es superadmin) |
| PATCH | `/api/admin/users/{id}/flags` | guardia jerárquica (`UserController::updateFlags`) | `approve_large_amounts`, `manage_users` por persona |

`Role.format()` devuelve `{ id, key, name, is_superadmin, is_system, is_active,
default_security_level_id }`. `RoleModuleVisibility.formatVisibility()` devuelve
`{ module, can_view, can_view_team }` con `can_view ∈ { VIEW_NONE=0, VIEW_READONLY=1, VIEW_FULL=2 }`.
`SecurityLevelModulePermission.formatPermission()` devuelve `{ module, can_edit, can_approve }`.
Catálogo de módulos: `ModuleCatalog::keys()` (backend) — 7 módulos (`ventas_diseno`, `compras`,
`bodega`, `servicios`, `admin_contab`, `gerencia`, `operaciones`).

---

## Pantallas

### 1. Nuevo tab "Roles" en `SecurityLayout`

Agregar a `atlanticerp-frontend/src/components/SecurityLayout.tsx`, junto a Levels/Departments/Alerts.

**Gate distinto al resto de los tabs de admin:** los tabs existentes usan
`isSuperAdmin = user.role === 'superadmin' || user.role === 'management'`, pero los endpoints de
escritura de `/api/roles` exigen `permission:superadmin.all` estrictamente (no `management`). Usar
`user.permissions.includes('superadmin.all')` para mostrar el tab "Roles" — un usuario Gerencia
sin `superadmin.all` no debe ver un tab que le devuelve 403 al primer click.

```
tabs = [
  users, ...(isSuperAdmin ? [levels, departments, alerts, notificationRules] : []),
  ...(hasSuperadminAll ? [roles] : []),
]
```

### 2. `RolesPage` (`/security/roles`) — nueva

Mismo patrón visual que `SecurityLevelsPage.tsx` (tabla en `Card variant="panel"`, sin paginación
— son ~14 filas, no hace falta).

Columnas: **Rol** (`name`), **Clave** (`key`, monoespaciado, gris), **Nivel por defecto**
(`default_security_level_id` → nombre del nivel o "—"), **Sistema** (badge "Sistema" si
`is_system`, no borrable), **Acciones** (Editar → `RoleFormModal`; Visibilidad → `RoleVisibilityModal`;
Borrar, solo si `!is_system`, con confirm dialog igual al patrón de `UsersPage`).

Botón "+ Nuevo rol" arriba a la derecha, igual que "+ Nuevo usuario" en `UsersPage` → abre
`RoleFormModal` en modo creación.

### 3. `RoleFormModal`

Copiar la estructura de `SecurityLevelFormModal.tsx` (mismo `Card variant="modal"`, header con
badge de color, footer Cancelar/Guardar). Campos:

- `key` (solo en creación, `input`, disabled en edición — es inmutable una vez creado)
- `name` (input)
- `default_security_level_id` (select, opciones = `GET /api/security-levels`, incluye "Sin nivel
  por defecto")

Si `is_system`, deshabilitar `key`; el resto sigue editable (a diferencia de `SecurityLevelFormModal`
donde `level === 10` bloquea todo el form — acá los roles de sistema **sí** deben poder ajustar su
nivel por defecto y nombre, solo `key` es inmutable).

### 4. `RoleVisibilityModal`

Matriz módulo × visibilidad, un rol a la vez. Filas = los 7 módulos de `ModuleCatalog`. Por fila:

- Selector de 3 estados para `can_view` (`Ninguno` / `Solo lectura` / `Completo`) — usar el mismo
  patrón de `Button` con `variant="outline"` + `active` en grupo de 3, no un `<select>` (consistente
  con `renderPermButton` de `SecurityLevelFormModal`).
- Checkbox/`Button` toggle para `can_view_team` ("Ve equipo, no solo lo propio") — deshabilitado
  si `can_view === VIEW_NONE`.

Guarda todo el array de filas de una vez (`PUT .../visibility` recibe `rows: [...]` completo, no
fila por fila) — juntar el estado local y mandar un solo `PUT` al hacer clic en "Guardar".

### 5. Sección "Matriz de permisos" en `SecurityLevelsPage`

No es una pantalla nueva — agregar una acción "Permisos" junto a "Editar" en cada fila de
`SecurityLevelsPage.tsx` (mismo lugar que el botón "Editar" ya existente), que abre
`SecurityLevelPermissionsModal`.

Matriz módulo × (`can_edit`, `can_approve`) — 2 checkboxes por fila, 7 filas. Mismo patrón visual
que `RoleVisibilityModal`. Nota: "Ver" (`can_view`) es del **rol**, no del nivel — este modal no lo
toca, solo Crear/Editar y Aprobar, que sí dependen del nivel de seguridad de la persona (defensa en
profundidad: el rol es el techo, el nivel es la capacidad dentro de ese techo).

### 6. `UserFormDrawer.tsx` — reescribir la sección de rol (breaking, no aditivo)

Reemplazar el bloque `Controller name="roles"` (líneas ~184-225 actuales, multi-select) por:

- **Rol base** (`role_id`, select único, requerido) — poblado desde `GET /api/roles`, filtrado a
  `is_active`. Reemplaza por completo el multi-checkbox de `roles: string[]`.
- **Roles adicionales** (`additional_role_ids`, multi-select tipo pill, igual patrón que el
  `extra_permissions` actual) — **visible solo si `isSuperadmin`**, mismo gate que ya existe para
  `extra_permissions` en este mismo archivo (línea 283). Si el actor no es superadmin, el campo ni
  se renderiza ni se manda en el payload (mismo patrón doble gate UI+API que `extra_permissions`).
- **Flags transversales** (`approve_large_amounts`, `manage_users`) — 2 toggles simples,
  visibles para cualquier actor con permiso de gestionar usuarios (no gateados a superadmin, a
  diferencia de `additional_role_ids` y `extra_permissions` — son flags de negocio por persona, no
  un privilegio de plataforma).

`extra_permissions` (el catálogo plano de 11 strings, líneas 282-316 actuales) **se mantiene sin
tocar** — sigue siendo el escape hatch para lo que no encaja en el molde rol+nivel, ya documentado
así en el ADR. No confundir con `additional_role_ids`, que es un mecanismo distinto y nuevo.

**Payload nuevo esperado por el backend** (`UserService::create/update`, ya implementado):
```json
{ "role_id": 3, "additional_role_ids": [7], "extra_permissions": [...] }
```
Endpoint confirmado: `PATCH /api/admin/users/{id}/flags` (`UserController::updateFlags`), ya
implementado — solo falta llamarlo desde la UI.

### 7. Filtro por rol en `UsersPage` / `UserTable`

Agregar un `<select>` de rol junto al buscador existente (mismo alto/ancho que el input de
búsqueda, `flex gap-2` ya existente en `UsersPage.tsx` línea ~154). Opciones desde `GET /api/roles`.
Pasa como query param nuevo a `useUsers({ search, page, per_page, is_active, role_id })` — requiere
que el hook y el endpoint `GET /api/users` acepten `role_id` como filtro (confirmar con Backend Dev,
no verificado en esta spec si ya existe).

Agregar columna "Rol" en `UserTable.tsx` (no revisado en detalle en esta spec — Frontend Dev debe
leer el archivo antes de implementar, mismo patrón de badge que ya usa para status).

### 8. Vista "rol base + excepciones" (SCRUM-145, criterio pendiente)

**No es una pantalla separada** — se resuelve como un bloque de solo-lectura al principio del
`UserFormDrawer` (arriba del campo "Rol base"), visible solo en modo edición (`isEdit`):

```
Rol base: Vendedor/Diseñador
Roles adicionales: (ninguno)
Excepciones: Aprobar montos >$5,000 ✓ · Gestionar usuarios ✗
Permisos extra: reports.export
```

Reusa los mismos datos que ya trae `GET /api/users/{id}` (el payload ya incluye `role_id`,
`additional_role_ids`, `approve_large_amounts`, `manage_users`, `extra_permissions` — ver
`UserController.php:202-206`). No requiere endpoint nuevo, es composición de datos ya disponibles.

---

## Fuera de alcance (Fase D)

- Fase E (limpieza de `user_roles`/enum viejo) — no se toca nada de eso acá.
- CRM sigue usando `hasRole(MANAGEMENT)`/`user_roles` — decisión ya tomada, fuera del catálogo de
  7 módulos, no forma parte de esta spec.
- No se agrega un 4to nivel formal para "Delete" (pregunta 8 del ADR, sigue sin resolver) — la
  matriz de esta spec es Ver/Crear-Editar/Aprobar tal como está implementado hoy en el backend.

## Reuso obligatorio (regla global del workspace)

Antes de escribir componentes nuevos, reusar: `Card` (`variant="panel"|"modal"|"drawer"`), `Button`
(`variant="outline"` + `active` para grupos de selección tipo pill, `variant="accent"` para
positivo, `variant="danger"` para borrar), iconos de `@/components/icons` (nunca emoji — regla de
cliente SCRUM-56), el patrón de paginación ya escrito en `SecurityLevelsPage.tsx`/`UsersPage.tsx`
(copiar tal cual, no reinventar). Colores de nivel (`#64748b`/`#5BA5A0`/`#9fc54d`/`#2a2520` por
rango) ya están en `SecurityLevelsPage.tsx:35` y `SecurityLevelFormModal.tsx:101` — reusar la misma
función, no duplicarla a mano en `RolesPage`.

---

## Criterios de aceptación (cierre de SCRUM-144/145)

- [x] Tab "Roles" visible solo con `superadmin.all`, no con `management` a secas
- [x] `RolesPage`: crear/editar/borrar rol sin tocar código (backend ya lo soporta)
- [x] `RoleVisibilityModal`: editar Ver/Ve-equipo por módulo, por rol
- [x] `SecurityLevelPermissionsModal`: editar Crear-Editar/Aprobar por módulo, por nivel
- [x] `UserFormDrawer`: rol base único (no más multi-checkbox `roles`), roles adicionales gateados
      a superadmin, flags `approve_large_amounts`/`manage_users` editables
- [x] `UsersPage`: filtro por rol funcional
- [x] `UserFormDrawer`: bloque de solo-lectura "rol base + excepciones" visible en modo edición
- [x] i18n: todas las strings nuevas en `es`/`en`, sin glyphs de emoji embebidos (regla SCRUM-56) —
      usa `IcoCheck`/`IcoClose` en vez de ✓/✗ en el bloque de resumen
- [x] Sin regresión: no existía `UserFormDrawer.test.tsx`/`UsersPage.test.tsx` previo (gap de
      cobertura ya presente, no introducido acá) — se agregó cobertura nueva en vez de auditar
      tests viejos que no existían

---

## Implementación (2026-07-13)

### Gap de backend encontrado y corregido (no estaba en la spec original)

`CreateUserRequest` seguía exigiendo `roles: required|array|min:1` (el vocabulario legado,
`user_roles`) a pesar de que `JwtClaimsBuilder` (Fase C) ya resuelve el claim JWT `role` desde
`role_id` cuando está presente — `roles`/`user_roles` solo sigue siendo la fuente real para
usuarios **sin** `role_id` (ej. el flujo de aprobación de registro público,
`UserService::approve()`, que no se tocó). Con `role_id` ahora obligatorio en el form nuevo,
seguir exigiendo `roles` también habría sido redundante y habría obligado al frontend a inventar
un valor. Corregido: `roles` pasó a `sometimes|array|min:1` en `CreateUserRequest` (ya era
`sometimes` en `UpdateUserRequest`), con guard `$data['roles'] ?? []` en
`UserService::create()` (antes crasheaba con "Undefined array key" si se omitía). Test nuevo:
`test_crear_usuario_sin_el_campo_roles_pero_con_role_id_retorna_201`. El test existente
`test_crear_usuario_sin_roles_retorna_422` sigue pasando sin cambios — sigue enviando
`roles: []` explícito (array presente pero vacío), que `sometimes` + `min:1` siguen rechazando.

### Filtro `role_id` en `GET /api/users`

Agregado a `UserService::list()`/`UserController::index()` — coincide contra `role_id` (rol base)
o `additional_role_ids` (rol adicional, `orWhereHas`). 2 tests nuevos.

### Archivos nuevos (frontend)

`src/api/rolesApi.ts`, `src/pages/security/RolesPage.tsx` (+ test),
`src/components/security/RoleFormModal.tsx`, `RoleVisibilityModal.tsx`,
`SecurityLevelPermissionsModal.tsx`, `src/components/security/UserFormDrawer.test.tsx`.

### Archivos modificados (frontend)

`App.tsx` (ruta `/security/roles`), `SecurityLayout.tsx` (tab gateado a `superadmin.all` estricto,
distinto del `isSuperAdmin` laxo de Levels/Departments/Alerts), `levelsApi.ts`/`usersApi.ts`
(tipos + endpoints nuevos), `UserFormDrawer.tsx` (reescritura completa de la sección de rol —
breaking, no aditivo, como marcaba la spec), `UserTable.tsx` (columna Rol con fallback a badges
legados si `role_id` es `null`), `UsersPage.tsx` (`<select>` de filtro), `useUsers.ts` (param
`role_id`), `SecurityLevelsPage.tsx` (botón "Permisos"), `i18n/locales/{es,en}/security.json`.

### Decisión de diseño tomada en el camino (no estaba en la spec)

El toggle de flags (`approve_large_amounts`/`manage_users`) usa un mutation independiente
(`usersApi.updateFlags`, fuera del form principal) — la spec no especificaba si debía ir dentro
del submit del form o ser standalone. Se implementó standalone (igual que
`toggleStatus`/`resetMfa` ya existentes en `UsersPage`) porque el endpoint backend
(`PATCH .../flags`) ya es standalone y separado de `PUT /api/users/{id}`. Efecto secundario: el
prop `user` del drawer queda stale tras el toggle mientras el drawer sigue abierto (React Query
invalida la lista, no el objeto en memoria del drawer) — resuelto con un espejo de estado local
(`localFlags`) sincronizado on-mount/on-user-change, no directamente con `user.approve_large_amounts`.

### Verificado end-to-end en navegador (Playwright, no solo `npm test`)

Login real (`management@illuminations.test`), navegación a `/security/roles` (14 roles listados),
apertura de `RoleFormModal` y `RoleVisibilityModal`, navegación a `/security/levels` y apertura de
`SecurityLevelPermissionsModal`, navegación a `/security/users` (filtro de rol con 4 opciones),
apertura del drawer de edición confirmando "Rol base", "Permisos transversales" y "Roles
adicionales" (gate de superadmin) visibles — 0 errores de consola/página en todo el recorrido.

---

## Siguiente paso

Falta Senior Review y OK explícito de Luis antes de `git commit`/push (regla del proyecto — nunca
saltear Senior Reviewer, push solo con aprobación explícita, ver `feedback_push_workflow.md`).
No se tocó nada de Fase E (limpieza de `user_roles`) ni de CRM (sigue con `hasRole`/`user_roles`,
fuera de alcance por decisión ya tomada de Luis).
