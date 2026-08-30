# Spec — User Management
**Module:** Auth  
**Status:** Implemented — QA Approved 2026-06-20  
**Original date:** 2026-06-17  
**Last updated:** 2026-06-20 (pivot de PermissionGroups a SecurityLevels + extra_permissions; validación catálogo; JWT security_level)

> **Nota de diseño:** el spec original incluía Permission Groups como modelo de control de acceso.
> Tras análisis, se sustituyeron por Security Levels + extra_permissions.
> Ver ADR `docs/adr/001-security-levels-over-permission-groups.md`.

---

## Scope implementado

- CRUD de usuarios (crear, ver, editar, desactivar)
- 10 Security Levels jerárquicos (1 = Read Only → 10 = Super Admin) con vocabulario de permisos
- `extra_permissions` por usuario: grants individuales sobre el nivel, solo superadmin puede asignarlos
- CRUD de departamentos
- CRUD de security levels (editar nombre/descripción/permisos; nivel 10 bloqueado)
- Nuevo rol `superadmin`
- Multi-rol: el usuario puede tener varios roles, elige uno al login, cambia mid-session
- Cambio de contraseña propia (requiere contraseña actual)
- Contraseña por defecto al crear = email del usuario
- Selector de idioma ES/EN en TopBar, persistido en `localStorage`
- Registro de solicitudes de acceso (flujo pendiente → aprobado/rechazado)

---

## Modelo de datos — `{tenant}_auth`

### `users`

```sql
users (
  id                  BIGSERIAL    PRIMARY KEY,
  first_name          VARCHAR(120) NOT NULL,
  last_name           VARCHAR(120) NOT NULL,
  email               VARCHAR(255) UNIQUE NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  phone               VARCHAR(30),
  department_id       BIGINT       REFERENCES departments(id) ON DELETE SET NULL,
  security_level_id   BIGINT       NOT NULL REFERENCES security_levels(id),
  extra_permissions   JSONB        NOT NULL DEFAULT '[]',
  notes               TEXT,
  preferences         JSONB        NOT NULL DEFAULT '{}',
  is_active           BOOLEAN      NOT NULL DEFAULT true,
  mfa_enabled         BOOLEAN      NOT NULL DEFAULT false,
  created_at          TIMESTAMPTZ,
  updated_at          TIMESTAMPTZ
)
```

### `security_levels`

```sql
security_levels (
  id          BIGSERIAL    PRIMARY KEY,
  level       INTEGER      UNIQUE NOT NULL,  -- 1..10
  name        VARCHAR(100) NOT NULL,
  description TEXT,
  permissions JSONB        NOT NULL DEFAULT '[]',
  is_active   BOOLEAN      NOT NULL DEFAULT true,
  created_at  TIMESTAMPTZ,
  updated_at  TIMESTAMPTZ
)
```

### `user_roles` (pivot)

```sql
user_roles (
  user_id  BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role     VARCHAR(30) NOT NULL CHECK (role IN ('superadmin','management','designer','supervisor','electrician','guest')),
  PRIMARY KEY (user_id, role)
)
```

### `departments`

```sql
departments (
  id         BIGSERIAL    PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  is_active  BOOLEAN      NOT NULL DEFAULT true,
  created_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ  NOT NULL DEFAULT now()
)
```

### `refresh_tokens`

```sql
refresh_tokens (
  id          BIGSERIAL    PRIMARY KEY,
  user_id     BIGINT       NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash  VARCHAR(255) NOT NULL,
  expires_at  TIMESTAMPTZ  NOT NULL,
  revoked     BOOLEAN      NOT NULL DEFAULT false,
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
```

---

## Catálogo de permisos (vocabulario módulo-scoped)

Almacenado como strings en `security_levels.permissions` y `users.extra_permissions`.  
Fuente de verdad en código: `SecurityLevel::PERMISSION_CATALOG` (constante PHP).  
Toda entrada en `extra_permissions` se valida contra este catálogo vía `Rule::in(SecurityLevel::PERMISSION_CATALOG)` en `CreateUserRequest` y `UpdateUserRequest`; valores fuera del catálogo retornan HTTP 422.

| String | Descripción |
|---|---|
| `crm.read` | Ver proyectos CRM |
| `crm.write` | Crear proyectos CRM |
| `crm.edit` | Editar proyectos CRM |
| `crm.delete` | Desactivar proyectos CRM |
| `reports.read` | Ver reportes |
| `reports.export` | Exportar reportes |
| `ai.use` | Usar asistente IA |
| `security.users` | Gestionar usuarios |
| `security.levels` | Gestionar niveles de seguridad |
| `settings.global` | Configuración global del sistema |
| `superadmin.all` | Bypass total — ignora toda otra restricción |

**Level 10 (Super Admin)** recibe los 11 permisos por defecto y no es editable desde la UI.

---

## Permisos efectivos

```
effectivePermissions(user) = unique(securityLevel.permissions ∪ user.extra_permissions)
```

`hasPermission(user, perm)` devuelve `true` si:
- `perm ∈ effectivePermissions(user)`, O
- `'superadmin.all' ∈ effectivePermissions(user)`

---

## Guardia jerárquica de gestión de usuarios

Un actor en **Nivel N** solo puede crear o actualizar usuarios en **Niveles 1..N-1**.

- Intento de crear/actualizar a nivel ≥ N → `DomainException` → HTTP 403
- `superadmin.all` en el JWT del actor bypassa esta restricción
- Llamadas internas (seeders) pasan `actorLevel: null` → guardia desactivada

---

## `extra_permissions` — reglas de acceso

- Solo usuarios con `superadmin.all` pueden asignar `extra_permissions` a otros usuarios
- Si el actor no tiene `superadmin.all`, el campo se ignora silenciosamente (no se devuelve error)
- La validación opera en `UserService::create()` y `UserService::update()` vía el parámetro `$actorHasSuperadmin`

---

## JWT payload

```json
{
  "sub": 1,
  "tenant": "atlantic",
  "email": "user@atlantic.test",
  "role": "management",
  "roles": ["management", "designer"],
  "permissions": ["crm.read", "crm.write", "security.users"],
  "security_level": 8
}
```

- `permissions` = `user.effectivePermissions()` calculado al login / switch-role / refresh.
- `security_level` = entero 1–10 del nivel del actor, añadido al JWT el **2026-06-20**.  
  `UserController::actorContext()` lo lee del payload para la guardia jerárquica; si el claim no existe (tokens anteriores al 2026-06-20), hace fallback a una consulta BD.
- Los tokens no se invalidan al cambiar el nivel del usuario — el cambio es efectivo en el próximo login.

---

## Flujo de login — multi-rol

```
POST /api/auth/login  { email, password }
  ├─ 1 rol → JWT con ese rol → 200
  └─ N roles → { requires_role_selection: true, roles: [...], selection_token } → 200
                   ↓
              POST /api/auth/select-role { selection_token, role }
                   └─ JWT → 200
```

`selection_token` es un JWT de corta vida (5 min) con `user_id` + `roles[]`.

---

## API endpoints

### Auth

| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| POST | `/api/auth/login` | público | Login; retorna JWT o `requires_role_selection` |
| POST | `/api/auth/refresh` | público | Rota refresh token |
| POST | `/api/auth/logout` | público | Revoca refresh token |
| POST | `/api/auth/select-role` | `selection_token` | Finaliza login multi-rol |
| POST | `/api/auth/switch-role` | JWT | Cambia rol activo mid-session |
| PUT | `/api/auth/password` | JWT | Cambio de contraseña propia |
| PATCH | `/api/auth/profile` | JWT | Actualizar perfil propio |
| GET | `/api/auth/me` | JWT | Perfil + roles + permisos |
| GET | `/api/auth/mfa/status` | JWT | Estado MFA |
| POST | `/api/auth/mfa/enable` | JWT | Habilitar MFA |
| POST | `/api/auth/mfa/disable` | JWT | Deshabilitar MFA |
| POST | `/api/auth/mfa/verify` | `mfa_challenge_id` | Verificar código MFA |
| POST | `/api/auth/mfa/resend` | `mfa_challenge_id` | Reenviar código MFA |

### Users

| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| GET | `/api/users` | superadmin, management | Lista paginada con búsqueda |
| POST | `/api/users` | superadmin, management | Crear usuario |
| GET | `/api/users/{id}` | superadmin, management | Ver detalle |
| PUT | `/api/users/{id}` | superadmin, management | Editar usuario |
| PATCH | `/api/users/{id}/status` | superadmin, management | Activar/desactivar |
| GET | `/api/users/catalog` | JWT | Roles disponibles |
| GET | `/api/users/pending` | superadmin, management | Solicitudes de acceso pendientes |
| POST | `/api/users/{id}/approve` | superadmin, management | Aprobar solicitud |
| DELETE | `/api/users/{id}/reject` | superadmin, management | Rechazar solicitud |

**Payload create/update:**
```json
{
  "first_name": "Juan",
  "last_name": "García",
  "email": "juan@atlantic.test",
  "phone": "+507 6000-0000",
  "roles": ["designer"],
  "department_id": 2,
  "security_level_id": 3,
  "extra_permissions": ["reports.export"],
  "notes": "Texto libre"
}
```

`extra_permissions` solo se aplica si el actor tiene `superadmin.all`; de lo contrario se ignora.  
`security_level_id` es opcional en create — por defecto Level 1 (Read Only).

**Respuesta usuario:**
```json
{
  "id": 5,
  "first_name": "Juan",
  "last_name": "García",
  "email": "juan@atlantic.test",
  "phone": null,
  "is_active": true,
  "notes": null,
  "extra_permissions": ["reports.export"],
  "department": { "id": 2, "name": "Design" },
  "security_level": { "id": 3, "level": 3, "name": "Designer" },
  "roles": ["designer"]
}
```

### Security Levels

| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/security-levels` | superadmin, management |
| PUT | `/api/security-levels/{id}` | superadmin |
| PATCH | `/api/security-levels/{id}/toggle` | superadmin |

### Departments

| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/departments` | superadmin, management |
| POST | `/api/departments` | superadmin |
| PUT | `/api/departments/{id}` | superadmin |
| PATCH | `/api/departments/{id}/toggle` | superadmin |

---

## Frontend

### Rutas de seguridad

| Ruta | Componente | Acceso |
|---|---|---|
| `/security/users` | `UsersPage` + `SecurityLayout` | superadmin, management |
| `/security/levels` | `SecurityLevelsPage` + `SecurityLayout` | superadmin, management |
| `/security/departments` | `DepartmentsPage` + `SecurityLayout` | superadmin, management |

`SecurityLayout` renderiza tabs de sub-navegación (Users / Security Levels / Departments).

### UserFormDrawer — campo extra_permissions

Visible **solo si** `user.permissions.includes('superadmin.all')`.  
Renderiza toggles pill para los 11 permisos del catálogo.  
El payload omite `extra_permissions` si el actor no es superadmin (gate doble: UI + API).

---

## Reglas de negocio

1. Contraseña por defecto al crear usuario = email del usuario
2. Guardia jerárquica: actor en Nivel N solo gestiona usuarios en Niveles 1..N-1
3. Solo superadmin (`superadmin.all`) puede asignar `extra_permissions`
4. Level 10 (Super Admin) no es editable desde la UI
5. Un usuario debe tener al menos un rol asignado
6. Las solicitudes de acceso (registro público) entran como `role=guest, is_active=false`

---

## Acceptance criteria (QA aprobado 2026-06-20)

### Backend
- [x] Columna `extra_permissions jsonb NOT NULL DEFAULT '[]'` en `atlantic_auth.users`
- [x] 10 security levels con vocabulario de 11 permisos module-scoped
- [x] `permission_groups` y `permission_group_id` eliminados de BD y codebase
- [x] `effectivePermissions()` = union deduplicada de `level.permissions` + `extra_permissions`
- [x] `hasPermission()` verifica efectivamente + short-circuit `superadmin.all`
- [x] Guardia jerárquica bloquea creación/edición en nivel ≥ actorLevel
- [x] `superadmin.all` bypassa guardia jerárquica
- [x] Solo actor con `superadmin.all` puede persistir `extra_permissions` vía API
- [x] Respuesta de usuarios incluye campo `extra_permissions`
- [x] `extra_permissions.*` validado contra catálogo con `Rule::in(SecurityLevel::PERMISSION_CATALOG)` — valor inválido → 422
- [x] `security_level` embebido en JWT en login, refresh, select-role, switch-role y verify MFA
- [x] `actorContext()` lee `security_level` del JWT (evita query extra); fallback a BD para tokens sin el claim
- [x] PHPStan Level 8: 0 errores
- [x] 242 tests pasando

### Frontend
- [x] No existe ruta `/security/groups` ni tab "Grupos" en SecurityLayout
- [x] Campo `extra_permissions` en UserFormDrawer visible solo si actor tiene `superadmin.all`
- [x] Payload omite `extra_permissions` si actor no es superadmin
- [x] TypeScript: 0 errores (`tsc --noEmit`)
- [x] i18n: claves `nav.groups`, `groups.*`, `users.form.permissionGroup`, `users.form.noGroup` eliminadas

---

## Pendientes (no bloqueantes)

_Ninguno al 2026-06-20. Tasks #10 y #11 implementados e integrados en acceptance criteria._
