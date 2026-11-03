# Fase 19 — El CRM como módulo propio con visibilidad por rol

**Estado: APROBADA e implementada (2026-11-03).** Decisión sobre Contabilidad y Servicios: **no entran por defecto**. Origen: cobertura del CRM (`docs/flujos/crm.md`, candidato 2). Decisión de Luis (2026-11-03): crear el módulo `crm` propio (no reutilizar `ventas_diseno`).

## 1. Problema
Los permisos `crm.read/write/edit/delete` (+ `reports.read/export`) viven en el escape hatch JSONB de **todos** los niveles de seguridad (1–10, 15–18) y `crm` **no es un módulo** (`modules` solo trae `ventas_diseno, compras, bodega, servicios, admin_contab, gerencia, operaciones`). Resultado medido: cualquier rol —Técnico, Líder de Compras, Bodega…— lee y escribe en el CRM por API aunque su menú no lo muestre; el Técnico creó un proyecto y borró un contacto ajeno. Es el mismo patrón de la Fase 2: un permiso fino en un nivel compartido **no se filtra por `role_module_visibility`** (solo lo hace la matriz gruesa `{módulo}.view/edit/approve`).

## 2. Diseño
1. **Módulo `crm`** (tabla `modules`, Fase 1 del esquema de permisos): fila nueva `crm` / «CRM», con su orden; `ModuleCatalog::keys()` ya lee la tabla, así que el catálogo lo recoge sin deploy.
2. **Visibilidad por rol** (`role_module_visibility`, `module = 'crm'`): la migración la **siembra copiando la de `ventas_diseno`** (hoy Vendedor `can_view=2/can_view_team=false` y Gerencia `2/true`), de modo que no cambia quién ve el CRM en el menú —el Sidebar ya lo tratan como parte de Ventas & Diseño—. Después es **configurable** por el superadmin en Ajustes → Roles, igual que cualquier módulo.
3. **Rutas**: todo el grupo `/api/crm/*` (menos `settings`, que sigue exclusivo del superadmin) exige además **`crm.view`** (la matriz gruesa del módulo nuevo, que sí se filtra por visibilidad). Los `crm.write/edit/delete` actuales se conservan como el *qué puede hacer*; `crm.view` es el *si entra*.
4. **Equipo**: «ver al equipo» en el CRM (hoy `canViewTeamV2('ventas_diseno')`) pasa a `canViewTeamV2('crm')`; la migración copia el valor de `ventas_diseno`, así que el comportamiento inicial es idéntico. `ventas_diseno.view_team` deja de gobernar `reports/team`.
5. **Frontend**: el Sidebar y las guardas de las pantallas del CRM leen `crm.view` en lugar de `ventas_diseno`; el contrato de equivalencia (Fase 1b) se amplía con los permisos reales del seed.
6. **Contabilidad y Servicios** (hoy llegan al CRM por el escape hatch de su nivel): **pierden el acceso por defecto** al no estar en la visibilidad sembrada. Si el negocio los quiere dentro, el superadmin les concede el módulo en Ajustes — sin deploy. **Decisión abierta §5.**

## 3. Fuera de alcance
- Cambiar qué hace cada permiso de escritura (`crm.projects.delete` sigue solo en el nivel 9).
- El vocabulario del CRM (etapas/tipos propios) y los candidatos de etapas libres y contactos globales.

## 4. Criterios de aceptación
1. `GET /api/crm/projects` y el resto de rutas del CRM responden **403** a un rol sin visibilidad del módulo (Técnico, Líder de Compras, Bodega…), con o sin `crm.*`; 200 a Vendedor, Gerencia y superadmin.
2. Un rol al que el superadmin concede el módulo `crm` entra sin deploy; al quitárselo, deja de entrar.
3. El alcance de equipo del CRM sigue igual para Gerencia y no cambia para el Vendedor.
4. La migración es aditiva y reversible (`down()` borra el módulo y sus filas de visibilidad); ningún dato del CRM se toca.
5. Tests por rol (matriz de acceso), contrato de no-fuga sobre niveles compartidos, suites completas verdes, `npm run e2e:flujos` verde y el catálogo `crm.md` al día (candidato 2 resuelto).

## 5. Decisiones abiertas (para Luis)
1. **Contabilidad y Servicios**: ¿entran al CRM por defecto (sembrar su visibilidad) o solo si el superadmin se lo concede? Recomendado: **no por defecto** (privilegio mínimo); hoy usan el CRM «por accidente».
2. Los overrides **por usuario** de Ventas & Diseño no se copian (Contabilidad tenía uno): el CRM se concede aparte. El alcance de Gerencia (`gerencia_scope`) **no** abre el CRM: quien lo necesite recibe el módulo `crm` (con `can_view_team` si debe ver al equipo) desde Ajustes.

## 6. Plan
Backend (módulo + visibilidad + rutas + equipo + tests) y frontend (Sidebar/guardas + equivalencia + e2e) en un PR por repo, backend primero; Senior Review y Pre-QA de permisos antes de mezclar. Nada se sube sin «sube» ni se mezcla sin «mezcla #N».
