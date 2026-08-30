# QA Report — User Management (Security Levels + extra_permissions)
**Date:** 2026-06-20  
**Tested by:** QA Agent  
**Branch:** dev  
**Commits:** Tasks #1–#6 + 2 fix commits del Senior Reviewer

---

## Automated checks

| Check | Result |
|---|---|
| `php artisan test` (Docker) | ✅ 242 tests, 1231 assertions |
| `phpstan analyse --level=8` | ✅ 0 errors |
| `tsc --noEmit` (frontend) | ✅ 0 errors |

---

## Acceptance criteria

| # | Criterio | Input / Verificación | Esperado | Actual | Resultado |
|---|---|---|---|---|---|
| AC-01 | `extra_permissions jsonb NOT NULL DEFAULT '[]'` en `atlantic_auth.users` | `information_schema.columns` | type=jsonb, default='[]'::jsonb | type=jsonb default='[]'::jsonb | ✅ |
| AC-02 | 10 niveles con vocabulario de 11 permisos module-scoped | `security_levels` table | Level 1: 1 perm, Level 10: 11 perms incl. `superadmin.all` | Level 1: 1 perm, Level 10: 11 perms ✓ | ✅ |
| AC-03 | `permission_groups` y `permission_group_id` eliminados | `information_schema.tables/columns` | Tabla y columna no existen | Ambos dropped | ✅ |
| AC-04 | `effectivePermissions()` = union deduplicada de level.permissions + extra_permissions | Superadmin con extra perm duplicada | Sin duplicados | `array_unique` confirma dedup | ✅ |
| AC-05 | `hasPermission()` verifica contra effectivePermissions() | `hasPermission('crm.read')` en superadmin | true | true | ✅ |
| AC-06 | Guardia jerárquica: actor Nivel 5 bloqueado para crear en Nivel 5 | `create(data, actorLevel:5, superadmin:false)` con target nivel 5 | DomainException | `DomainException: No puedes crear usuarios en el nivel 5 — tu nivel es 5.` | ✅ |
| AC-06b | Actor Nivel 5 puede crear en Nivel 4 | `create(data, actorLevel:5, superadmin:false)` con target nivel 4 | User creado | User created id=288 | ✅ |
| AC-07 | `superadmin.all` bypassa la guardia jerárquica | `create(data, actorLevel:3, superadmin:true)` con target nivel 8 | User creado | User created id=289 | ✅ |
| AC-08 | Solo superadmin puede persistir `extra_permissions` | non-superadmin envía `['superadmin.all']` | extra_permissions=[] (silenciado) | `[]` | ✅ |
| AC-08b | Superadmin puede persistir `extra_permissions` | superadmin envía `['crm.read','ai.use']` | extra_permissions guardado | `["crm.read","ai.use"]` | ✅ |
| AC-09 | Respuesta de usuario incluye `extra_permissions` | GET/POST /api/users | campo presente | presente en response | ✅ |
| AC-10 | FE: no hay ruta `/security/groups` ni tab de grupos | `grep` en src/ | sin referencias | 0 matches | ✅ |
| AC-11 | FE: campo `extra_permissions` visible solo para superadmin | `{isSuperadmin && ...}` en UserFormDrawer | gate correcto | gate doble: UI + payload | ✅ |
| AC-12 | PHPStan Level 8 sin errores | `phpstan analyse` | 0 errors | 0 errors | ✅ |

---

## Edge cases validados por tests automatizados

| Scenario | Test | Resultado |
|---|---|---|
| Usuario sin autenticar | `test_listar_usuarios_sin_autenticar_retorna_401` | ✅ 401 |
| Email duplicado | `test_crear_usuario_email_duplicado_retorna_422` | ✅ 422 |
| Roles vacíos | `test_crear_usuario_sin_roles_retorna_422` | ✅ 422 |
| Rol inválido | `test_crear_usuario_rol_invalido_retorna_422` | ✅ 422 |
| Rol `designer` intenta crear usuario | `test_crear_usuario_con_rol_designer_retorna_403` | ✅ 403 |
| Rol `designer` intenta actualizar usuario | `test_actualizar_usuario_con_rol_designer_retorna_403` | ✅ 403 |
| Rol `electrician` intenta cambiar estado | `test_cambiar_estado_con_rol_electrician_retorna_403` | ✅ 403 |
| Management intenta asignar `extra_permissions` | `test_management_no_puede_asignar_extra_permissions` | ✅ silenciado |
| Superadmin asigna `extra_permissions` | `test_superadmin_puede_asignar_extra_permissions` | ✅ guardado |
| Respuesta incluye `extra_permissions` | `test_respuesta_usuario_incluye_extra_permissions` | ✅ presente |

---

## Issues encontrados durante QA

| # | Descripción | Acción |
|---|---|---|
| QA-01 | `$actorHasSuperadmin` faltaba en `use()` del closure `update()` — PHPStan Level 8 error | Corregido en commit `60e3b6f` antes de aprobar |

---

## Pendientes (no bloqueantes, trackeados)

- Task #10: `extra_permissions.*` sin validación `in:` contra catálogo
- Task #11: `actorContext()` hace query extra al DB por cada mutación

---

## Veredicto

**✅ APROBADO** — Todos los acceptance criteria pasan. 242 tests verdes. PHPStan Level 8 sin errores. TypeScript sin errores.
