# Fase 26b — Contabilidad «Solo ver, no mover» en el Pipeline

**Estado:** APROBADA (Luis eligió la opción A, 2026-11-04) e IMPLEMENTADA. **Decisión de Luis (2026-11-04):** Contabilidad ve el Pipeline y pasa proyectos aprobados a Pedido, pero no crea, edita ni mueve tarjetas.

## 1. Situación actual (verificada en el código)

- `ventas_diseno.pipeline.edit` abre en el Pipeline: crear tarjeta (`POST /pipeline`), editar, cambiar etapa, archivos, asociados, contactos, entregas por producto y solicitar pase a pedido; además 4 rutas de vinculación (`master-clients`, `sub-clients`, GET/POST).
- Hoy Contabilidad lo recibe **por persona** (`extra_permissions`): `UserModuleVisibilityGrantSeeder::EXTRA_PERMISSIONS` en el demo y `FixtureSpecialPermissionSeeder` en tests. **No hay migración ni nivel de seguridad que lo conceda**, así que no hay riesgo de fuga por niveles compartidos.
- `PipelineCard::isEditableBy` limita a Contabilidad a las tarjetas que ella creó; `canAccess` le da alcance de equipo dentro del Pipeline por `pipeline.edit`.
- `pipeline.read_all` (lectura de todo el Pipeline) y `pass_to_order` se conservan.

## 2. Cambio propuesto

1. **Demo:** quitar `ventas_diseno.pipeline.edit` de `UserModuleVisibilityGrantSeeder::EXTRA_PERMISSIONS['contabilidad@test.com']` (queda `pipeline.read_all`, `edit.client_tax_data`, `admin_contab.edit.client_itbms_exemption`).
2. **Entornos ya sembrados:** migración de tenant de datos que quita esa clave del `extra_permissions` de los usuarios con rol `lider_admin_contab`. Idempotente; `down()` no la restaura (no se sabe a quién se concedió a propósito). Solo toca ese rol; una concesión individual a otra persona no se modifica.
3. **No se toca** el permiso del catálogo ni las rutas: `pipeline.edit` sigue existiendo para que el superadmin se lo conceda a quien quiera (mecanismo ortogonal intacto). Cambia quién lo tiene por defecto, no el mecanismo.
4. **Efecto colateral a confirmar (ver §5):** sin `pipeline.edit`, Contabilidad pierde las 4 rutas de vinculación («Nuevo proyecto»), el alta de cliente maestro/subcliente desde ese flujo y el alcance de equipo (`scope=team`) derivado de `pipeline.edit`; conserva el alcance por `pipeline.read_all` (`userCanSeeTeam`).

## 3. Pruebas

- `UserModuleVisibilityGrantSeederTest`: Contabilidad ya no recibe `pipeline.edit` (ajustar líneas 71 y 75).
- `PipelineEditPermissionTest` / `PipelineCanEditTest`: hoy simulan a Contabilidad *con* el permiso. Pasan a ser pruebas de «cualquier usuario con `pipeline.edit`» (concesión individual), y se agrega el caso nuevo: Contabilidad por defecto → GET 200 con `can_edit=false`, `POST/PUT/PATCH` del Pipeline 403, `pass-to-order` 200, `scope=team` 200.
- `FixtureSpecialPermissionSeeder`: se quita `pipeline.edit` del fixture de Contabilidad (los tests que lo necesiten lo conceden explícitamente).
- Nueva: migración quita la clave solo a `lider_admin_contab` y no a otro rol.
- e2e `ventas-diseno` paso 5: Contabilidad ya no crea ni edita «su» proyecto → `POST /pipeline` 403 y `PUT` 403; Gerencia sigue editando. Fixture `identity-permissions.json` (frontend) regenerado/ajustado a mano para esa identidad.
- Frontend: la pantalla ya lee `can_edit` de la tarjeta; solo se verifica que Contabilidad no vea los botones de crear/mover (Playwright, antes/después).

## 4. Riesgos

- Contabilidad deja de poder dar de alta un proyecto desde Admin & Contab. Los clientes fiscales siguen editándose por `edit.client_tax_data`.
- Migración de datos: afecta solo a `extra_permissions`; verificar con consulta previa cuántos usuarios `lider_admin_contab` lo tienen.

## 5. Decisión abierta

¿Contabilidad conserva la **vinculación mínima** (crear cliente/subcliente desde el flujo de proyecto) aunque no cree tarjetas?
- **A (recomendada):** no; «solo ver» literal. Si se necesita, el superadmin concede `pipeline.edit` por persona.
- **B:** crear un permiso angosto `ventas_diseno.pipeline.link_clients` solo para las 4 rutas de vinculación. Añade un permiso al catálogo (requiere revisar fuga de nivel si se asigna por rol).

## 6. Criterios de aceptación

1. Contabilidad por defecto: lee todo el Pipeline y pasa a Pedido; toda escritura del Pipeline responde 403.
2. Quien reciba `pipeline.edit` por concesión individual conserva el comportamiento actual.
3. Suite completa del backend y del frontend en verde; e2e `ventas-diseno` actualizado.

## 7. Resultado

- Decisión de §5: **A** (sin vinculación mínima; `pipeline.edit` por concesión individual del superadmin).
- Hecho: seeder demo sin `pipeline.edit` para Contabilidad; migración `2026_11_17_100000` (solo rol `lider_admin_contab`, idempotente); test de la migración; `PipelineEditPermissionTest` verifica además POST de tarjeta y de cliente maestro = 403 y `scope=team` = 200.
- Desviación de §3: el fixture heredado `FixtureSpecialPermissionSeeder` **se conserva** con `pipeline.edit` (docblock actualizado): esos tests describen el comportamiento de quien reciba el permiso por concesión; el estado por defecto lo cubre el test de «sin pipeline.edit».
- Aviso: el demo no concede `ventas_diseno.pass_to_order` a Contabilidad (solo el fixture de tests); darlo por defecto sería otra decisión.
- Verificado: 52 tests dirigidos en verde; falta suite completa (CI).
