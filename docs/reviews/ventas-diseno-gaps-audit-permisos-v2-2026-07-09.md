# Review: Gaps de In Progress + Historial y Auditoría (REQ-053) + ADR-006 Fase B

**Date**: 2026-07-09
**Reviewer**: Senior Reviewer
**Commits revisados**:
- `atlanticerp-backend`: `56174cc`, `a3e2482`, `c64411b`
- `atlanticerp-frontend`: `b8e601e`, `21f98ba`
**Decisión**: ✅ **Approved** (con 1 nota para Fase C, no bloqueante ahora)

## Summary

Tres piezas distintas en un mismo batch. Las reviso por separado porque tienen perfiles de riesgo muy distintos:

1. **Gaps de In Progress** (REQ-010, REQ-057, REQ-070, REQ-054/055) — cambios chicos, bien acotados, cierran huecos ya documentados en tickets anteriores. Sin objeciones.
2. **REQ-053 (Historial y auditoría)** — la pieza más interesante arquitectónicamente: en vez de threadear `AuditService::log()` a mano en ~25 puntos de escritura, se resolvió con un trait (`LogsAuditTrail`) que engancha eventos de Eloquent. Es la decisión correcta — mecánica, sin superficie para "olvidarse un endpoint", y consistente con `AuditService` ya existente de Epic 11. Aprobado, con 2 sugerencias no bloqueantes.
3. **ADR-006 Fase B** — el trabajo de más cuidado: algoritmo nuevo de permisos, **pero deliberadamente sin wirear a nada real**. Verifiqué específicamente que no toca `hasPermission()`/JWT/middleware — confirmado, es código muerto desde el punto de vista de runtime hasta Fase C. Aprobado. Encontré un gap real que hay que anotar como prerequisito de Fase C (ver Blockers — no bloquea esto, bloquea el *siguiente* paso).

Corrí la suite completa dos veces (antes y después de revisar) — 640/640 backend, 200/200 frontend, PHPStan Level 8 limpio ambas veces. Leí los 27 archivos del diff completos, no solo el diff.

## Findings

### 🔴 Blockers (must fix)

Ninguno para este commit. Ver la nota de Fase C abajo — no bloquea *este* código (que no se ejecuta en producción todavía), pero **sí bloquea aprobar Fase C tal cual está diseñada hoy** sin resolverlo primero.

### 🟡 Suggestions (non-blocking)

1. **`app/Models/User.php:modulesPayload()`/`effectivePermissionsV2()`** — el bypass de superadmin depende de `role->is_superadmin`. Hoy, el bypass REAL de superadmin en producción viene de `security_level.permissions` conteniendo `'superadmin.all'` (v1), no de ningún `role`. Los usuarios reales que hoy son superadmin (Andrés, Luis, etc.) tienen `role_id = NULL`. **Si Fase C cutovea sin antes asignarles `role_id` → rol `superadmin`, estos usuarios perderían acceso a todo bajo el algoritmo v2** (`modulesPayload()` devolvería todo en `false` para un usuario con `role = null`). No es un bug de este código — v2 hace exactamente lo que el ADR pide — es un prerequisito de datos para Fase C que no está escrito en ningún lado todavía. Sugiero agregarlo explícitamente a la lista de prerequisitos de Fase C en el ADR (backfill de `role_id` para los usuarios de plataforma, no solo para los 13 roles de negocio).

2. **`app/Modules/VentasDiseno/Http/Controllers/AuditLogController.php:52,57`** — los filtros `from`/`to` se pasan directo a `whereDate()` sin validar formato. Un valor mal formado (ej. `?from=no-es-una-fecha`) probablemente dispare una excepción de Postgres sin capturar → 500 en vez de un 422 prolijo. No es un riesgo de seguridad (query parametrizada, sin inyección posible), es robustez de input. Fácil de arreglar con una regla `date` en un FormRequest si se retoma este endpoint.

3. **`app/Shared/Support/LogsAuditTrail.php`** — cada `created`/`updated`/`deleted` de los 10 modelos ahora dispara un INSERT síncrono adicional a `audit_logs`, dentro de la misma transacción. Para el volumen actual (equipo chico, uso interno) no es un problema — lo señalo solo para el futuro: si `QuoteItemController` termina recibiendo updates muy frecuentes (autosave por tecla, por ejemplo) esto duplica la escritura por request. No hace falta resolverlo ahora.

### 🟢 Well done

- **Decisión de usar Eloquent model events en vez de threadear manualmente** — evita exactamente la clase de bug que el ticket temía ("TODA acción, no solo un subconjunto"). Verifiqué que cubre los 10 modelos correctos y que el pivot (`quote_contacts`, sin modelo Eloquent propio) se manejó a mano en `QuoteContactController` en vez de forzarlo dentro del trait — la decisión correcta, no una omisión.
- **`SalesTotalsService` extraído a tiempo** — antes de introducir la dependencia circular `HomeService→CommissionService→ReportsService→HomeService`, se detectó y se resolvió extrayendo la lógica compartida a un servicio sin dependencias. Verifiqué el grafo de constructores de los 3 servicios — no hay ciclo.
- **`ClientsService::createLeadCard()` pasado a `public` con la razón documentada inline** — no es un cambio de visibilidad silencioso, el comentario explica exactamente por qué (REQ-068 en sentido inverso) y qué decisión de producto lo motiva.
- **Tests del algoritmo v2 usan fixtures propios, no datos de seed** — decisión correcta dado que `security_level_module_permissions` está vacía en producción hoy; comparar contra datos reales hubiera sido probar contra ceros, no contra el algoritmo.
- **`effectivePermissionsV2()` respeta "el rol es el techo"** — verificado con test dedicado (`test_nivel_no_puede_otorgar_capacidad_en_modulo_que_el_rol_no_alcanza`) que un nivel con `can_edit=true` en un módulo que el rol no ve no filtra el permiso. Esto es exactamente la defensa en profundidad que pide la Decisión 7 del ADR, y está probada, no solo documentada.
- **Gate de permiso correcto en el endpoint nuevo** — `GET /ventas-diseno/audit-log` exige `ventas_diseno.audit` vía middleware de ruta, no un chequeo manual en el controller (mismo patrón que el resto del módulo).

## Next steps

**Ready for QA** — en el sentido de que el código en sí está limpio, testeado y sin bugs bloqueantes. Pero corresponde señalar (no es parte de este review, es un recordatorio de proceso): ninguna pieza de Ventas & Diseño pasó QA formal todavía — este batch se suma a un backlog de QA pendiente más grande (~82 tickets Dev Testing). Ver consolidado del daily.

Para Fase C del ADR-006 específicamente: no avanzar sin resolver la Suggestion #1 de arriba (backfill de `role_id` para usuarios de plataforma) además de los prerequisitos de negocio que el propio ADR ya lista.
