# Flujo transversal: administración de la plataforma

Continuación de [transversales: usuarios, acceso y permisos](transversales.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-03 con las cuentas demo (`npm run e2e:flujos`, archivo `transversales-administracion.spec.ts`, 6 pasos; la suite completa de flujos, 117 pasos, pasa). Todo lo que el flujo crea lleva «[FLUJO]» y se borra o restaura al terminar; quedan en el demo un departamento desactivado y una orden de compra de prueba.

## 1. Departamentos
`/departments` exige `platform.management` + `security.users` (Gerencia y superadmin); vendedor y Líder de Compras, 403 [E].
- Alta: nombre obligatorio (422) y **único** (422) [E]. Renombrar y desactivar (`PATCH …/status`) [E]. Id inexistente, 404 [E].
- **Se desactiva, no se borra** (no hay ruta de borrado) [E][C].
- `?all=1` es el listado de selectores y **no incluye los desactivados**; el listado paginado de administración (con `search`) sí los muestra con `is_active: false` [E].

## 2. Roles propios y su visibilidad
Todo bajo `/admin/roles`, exclusivo del **superadmin**; Gerencia, 403 [E]. El listado (`GET /roles`) lo lee cualquiera con acceso al catálogo.
- Alta: `key` en minúscula y sin espacios (`^[a-z][a-z0-9_]*$`, 422 si no) y única (422) [E]. Nace `is_system: false`, activo [E].
- Un **rol de sistema no se edita ni se borra** (403) [E]. Un rol propio sin usuarios se borra (204) [E]; con usuarios o asignado como rol adicional de alguien, 403 [C].
- **Visibilidad por módulo** (`GET|PUT roles/{id}/visibility`): módulo del catálogo (si no, 422), `can_view` solo 0, 1 o 2 (422) y `can_view_team`; se guarda y se relee [E].

## 3. Reglas de aprobación de órdenes de compra
`/admin/purchase-approval-rules`, solo superadmin; Gerencia y Líder de Compras, 403 [E]. Es el lugar donde se parametriza qué órdenes piden aprobación (ya no está fija en el código).
- Validaciones: nombre obligatorio, máximo ≥ mínimo, y el pagador del envío **solo aplica al envío aéreo** (422) [E].
- Una regla activa con envío marítimo y rango de 12 345 a 12 346 decide de verdad: la vista previa la devuelve, y una orden marítima de 12 345,50 **nace `por_aprobar`** con `requires_primary_approval` [E]. Fuera del rango no decide; inactiva, tampoco [E]. La primera regla activa que coincide (por `sort_order`) gana [C].
- Borrar la regla (200) y editar un id inexistente (404) [E].
- **Sin huecos (Fase 20d)**: guardar una regla activa que se solapa con otra de igual alcance (mismo envío y pagador) responde igual pero con `warnings` [E]; `GET /admin/purchase-approval-rules/coverage` (solo superadmin) lista por envío y pagador los rangos de monto que ninguna regla activa cubre [E]; el ajuste global `purchase_approval_no_rule` (0 por defecto, grupo «Compras» en Ajustes globales) decide qué pasa con esas órdenes: 0 no piden aprobación, 1 nacen «Por aprobar» [C]. La pantalla muestra los huecos y los solapes.

## 4. Reglas de notificación
`/admin/notification-rules`, solo superadmin [E]. Una regla dispara en el evento `created` o `updated` de un modelo de una lista (`Project`, `ProjectActivity`, …) y notifica por `in_app` y/o `email` a usuarios, roles o a los asignados del proyecto.
- Rechaza (422): modelo fuera de la lista, evento distinto de `created`/`updated`, sin canales o con un canal inválido (`sms`), sin destinatarios, y un campo sin operador [E].
- Operadores válidos: `changed`, `changed_to`, `equals`, `gt`, `lt`, `gte`, `lte` [C]. Se crea, se lista, se edita y se borra [E].
- Solo existe el disparador por evento; el de proximidad de fecha está marcado para una fase futura [C].

## 5. Ajustes globales
`/admin/global-settings`, solo superadmin [E]. Es una **lista blanca con rango** (documentos, retención de notificaciones y auditoría, reinicio de contraseña, «Ver como usuario»); la clave de la IA nunca está en ella [E].
- Clave fuera de la lista, 404; valor sobre el máximo o no numérico, 422; Gerencia, 403 [E]. Cada cambio se audita con el valor anterior y el nuevo [C].
- Los ajustes sensibles (caducidad y interruptor del reinicio de contraseña, los de «Ver como usuario») **exigen la contraseña del operador**: sin ella, error [E][C].

## 6. Perfil de empresa y consumo de IA
- `admin-contab/company-profile`: lo lee y lo edita **solo el aprobador primario** (o su suplente). Otra cuenta de Gerencia y el vendedor, 403 [E]. Valida URL del sitio, país del catálogo y zona horaria válida (422) y guarda la descripción corta, que alimenta los prompts de IA [E].
- `admin/ai/usage` y `admin/ai/pricing`: solo superadmin [E].

## Candidatos a cambio o mejora
(Solo documentados; ninguno se implementó.)
1. **El perfil de empresa es del aprobador primario, no de Gerencia**: otra cuenta de Gerencia no puede ni leerlo. Si el aprobador falta, nadie lo edita salvo el suplente. [E]
2. **Departamentos: sin borrar ni reasignar**; el selector (`all=1`) esconde los desactivados, así que un usuario que ya pertenece a uno desactivado no lo ve en su ficha. [E][C]
3. ~~Una regla de aprobación inactiva o fuera de rango deja pasar sin aprobación sin avisar~~ — **corregido en la Fase 20d**: la pantalla y `coverage` señalan los huecos y `purchase_approval_no_rule` permite exigir aprobación en ellos (por defecto sigue sin pedirla). [E][C]
4. **Las reglas de aprobación se evalúan por orden de `sort_order`**; los solapes de igual alcance ya se avisan (20d) pero no se impiden. [C]
5. **Las reglas de notificación solo cubren eventos de modelos de una lista corta** (CRM); no hay reglas para Compras, Bodega ni Servicios. [C]
6. **Los ajustes globales son solo enteros**: no hay textos ni listas, y cada categoría de ajuste vive en una pantalla distinta (globales, de Compras, de Servicios, de Ventas). [C]
7. ~~**Un rol propio puede quedar sin ninguna visibilidad** (todas las filas en 0) sin avisar. [C]~~ **Resuelto: Fase 27b** (la respuesta trae `warnings[rol_sin_visibilidad]` y la pantalla lo muestra; no bloquea).
8. **Sin ejercitar**: las pantallas del navegador, el aviso real de una regla de notificación (por correo o in-app) y la edición de precios de IA.
