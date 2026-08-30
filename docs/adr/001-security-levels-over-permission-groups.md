# ADR-001 — Security Levels en lugar de Permission Groups
**Date:** 2026-06-20  
**Status:** Accepted  
**Deciders:** Luis García (product), Arquitecto

---

## Context

El spec original de User Management (2026-06-17) incluía **Permission Groups** como modelo de control de acceso:
- Tabla `permission_groups` con un array JSON de permission strings
- Cada usuario pertenece a exactamente un grupo
- `permissions` en el JWT se cargaba del grupo asignado

Durante la implementación quedó claro que Permission Groups y Security Levels coexistían con roles solapantes y semántica confusa:
- Los roles ya expresan capacidades laborales (`designer`, `supervisor`, etc.)
- Los Security Levels ya tenían un array `permissions` JSON
- Los Permission Groups duplicaban esa lógica sin añadir valor real

Además, la guardia jerárquica (un actor solo puede gestionar usuarios de nivel inferior) requería un campo numérico de nivel — que los Permission Groups no proveían.

---

## Decision

**Eliminar Permission Groups completamente.** Usar Security Levels como único primitivo de control de acceso, complementado con `extra_permissions` por usuario para grants individuales.

### Modelo adoptado

```
effectivePermissions(user) = unique(securityLevel.permissions ∪ user.extra_permissions)
```

- **Security Level** (1–10): define el conjunto base de permisos. Jerárquico: Level 10 tiene todo.
- **extra_permissions**: array JSONB en `users`. Permite grants individuales sin subir el nivel del usuario. Solo superadmin (`superadmin.all`) puede asignarlos.
- **Guardia jerárquica**: actor en Nivel N solo puede gestionar usuarios en Niveles 1..N–1.

---

## Consequences

### Positivo
- Un solo primitivo de acceso en lugar de dos (grupos + niveles)
- La jerarquía numérica habilita la guardia de gestión sin lógica adicional
- `extra_permissions` cubre los casos de excepción sin complejidad extra
- Menos tablas, menos superficie de ataque, menos código

### Negativo / trade-offs
- No hay concepto de "grupo de permisos" compartido — si muchos usuarios necesitan el mismo conjunto extra, hay que asignarlo individualmente (solución: subir su security level en cambio)
- Los permisos en el JWT se vuelven "stale" si se cambia el security level de un usuario hasta que vuelva a hacer login (misma limitación que antes con los grupos)

### Eliminado
- Tabla `permission_groups`
- Columna `permission_group_id` en `users`
- `PermissionGroup` model, service, controller, requests, seeder
- Ruta `/api/groups` y `routes/groups.php`
- Frontend: `GroupsPage`, `GroupFormModal`, `groupsApi.ts`
- i18n: sección `groups`, `nav.groups`, `users.form.permissionGroup`

### Migrado
- `extra_permissions jsonb NOT NULL DEFAULT '[]'` en `users`
- Vocabulario de permisos actualizado a 11 strings module-scoped (ver spec)
- `AuthController` y `MfaController` llaman a `user->effectivePermissions()` en lugar de leer del grupo
