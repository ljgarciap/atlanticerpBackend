# Senior Review y Pre-QA de permisos — Fase 19: el CRM como módulo propio (2026-11-03)

Ramas: backend `fase19/modulo-crm`, frontend `fase19/modulo-crm`. Spec: `docs/specs/fase19-modulo-crm.md`. **Aprobada, 0 bloqueantes.**

## Qué se revisó
- Migración `2026_11_13_100000`: inserta el módulo `crm` en `modules` y **copia `role_module_visibility` de `ventas_diseno`** (Vendedor `2/false`, Gerencia `2/true`). **No** copia los overrides por usuario (`user_module_visibility`): son concesiones personales de Ventas & Diseño (Contabilidad tenía una) y el CRM se concede aparte. Idempotente; `down()` borra el módulo y sus filas de visibilidad.
- `bootstrap/app.php`: el grupo `/api/crm/*` exige `crm.view` (la matriz gruesa del módulo, que **sí** se filtra por visibilidad de rol). `crm.read/write/edit/delete` del escape hatch siguen siendo el «qué puede hacer» pero ya no bastan para entrar. `reports/team` exige `crm.view_team`. Los 14 usos de `canViewTeamV2('ventas_diseno')` del módulo CRM pasaron a `'crm'` (el alcance de equipo se siembra idéntico).
- Frontend: rutas `/crm/dashboard` y `/crm/projects` exigen `crm.view`; el menú ofrece Dashboard CRM y Lista de Proyectos solo con ese permiso; fixture de identidades al día (+`crm.view`/`crm.view_team` a Vendedor y Gerencia).

## Pre-QA de permisos por rol (e2e `crm.spec.ts` paso 1 + `CrmModuleAccessTest`)
| Rol | `/crm/projects` | `/crm/reports/team` | `/crm/settings` |
|---|---|---|---|
| Vendedor | 200 | 403 | 403 |
| Gerencia | 200 | 200 | 403 |
| Contabilidad | **403** | 403 | 403 |
| Líder de Compras / Bodega / Servicios / Operaciones, Técnico | **403** | 403 | 403 |
| Superadmin | 200 | 200 | 200 |

Escrituras sin el módulo (Técnico: crear proyecto, crear y borrar contacto) → 403, antes 201/204. Conceder el módulo a un rol abre el acceso y quitarlo lo cierra (test con la fila de visibilidad).

## Hallazgos
1. **(Corregido durante la revisión)** El primer intento hacía que el alcance de Gerencia (`gerencia_scope`) concediera `crm.view`; dejaba al titular con el CRM pero sin `view_team` en los controladores (inconsistente). Se retiró: el CRM se concede como módulo, también a quien tenga el alcance de Gerencia.
2. **(Aceptado, decisión de Luis)** Contabilidad, Servicios, Compras, Bodega y Técnico **pierden el acceso por API al CRM**; el superadmin lo concede por rol o usuario en Ajustes, sin deploy.
3. **(Aceptado)** Una BD con datos conserva su `role_module_visibility`: la migración copia la visibilidad **vigente** de `ventas_diseno` al aplicarse; un cambio posterior en Ventas & Diseño no se refleja en `crm` (son módulos independientes a partir de ahí).
4. **(Abierto)** El nombre del permiso en el catálogo de «permisos adicionales» no incluye `crm.view`: se otorga por visibilidad de módulo, no como permiso adicional suelto.

## Verificación
Backend: suite completa 5224 tests (8 fallos de aserciones que fijaban «7 módulos»/«3 filas»; actualizadas a 8/4, y verificadas junto al test de Bodega, que pasó en su corrida aislada), PHPStan 0. Frontend: 247 archivos / 2928 tests, `tsc` limpio. e2e: CRM (8 pasos), Ventas & Diseño (11) y humo por rol (24) en verde con la BD migrada.
