# Spec — SCRUM-1001: inmutabilidad de `fe_transmisiones` a nivel de base de datos

**Fecha:** 2026-09-29 · **Estado:** DECIDIDA por Luis 2026-09-29 — lista para Backend Dev · **Rol:** Analista + Arquitecto
**Origen:** Senior Review de SCRUM-987 (sugerencia S3). Hoy el "append-only" de `admin_contab.fe_transmisiones` lo impone solo el modelo Eloquent (`AdminContFeTransmision`); `query()->update()/delete()` y `DB::table()` lo saltan.

## Hallazgos verificados en el código (esta sesión)
1. **Ya existe el mecanismo y el precedente.** `App\Console\Commands\GrantAppRoleCommand` (`tenant:grant-app-role`, SCRUM-810 / REQ-634) da al rol de runtime `atlanticerp_app` (NOSUPERUSER) `SELECT, INSERT, UPDATE, DELETE` en todas las tablas de cada schema y luego revoca `UPDATE, DELETE` sobre `{tenant}_auth.audit_logs` (`revokeAuditLogsWrite`) y `{tenant}_auth.db_audit_log` (`revokeDbAuditLogWrite`, SCRUM-916). Corre en **cada deploy**, después de `tenants:artisan migrate` (`infra/deploy-manual.sh`), y es idempotente.
2. **`atlanticerp_app` es el usuario de runtime real en dev, test y prod** (pasos 1-2-4-5 de REQ-634 completos: dev/test 2026-08-30, prod 2026-08-31, ver `docs/architecture/bodega-telemetria-fraude-diseno.md`). Un `REVOKE` tiene efecto hoy. *(Verificar con `has_table_privilege()` antes de cerrar, no asumirlo.)*
3. **`TRUNCATE` ya está fuera del alcance de `atlanticerp_app`:** el `GRANT` general es solo `SELECT, INSERT, UPDATE, DELETE`; `TRUNCATE` es un privilegio aparte que ese rol nunca recibe. El ticket lo listaba de más.
4. **Los tests locales corren como `atlanticerp` (superuser)**, así que un `REVOKE` para `atlanticerp_app` no afecta `RefreshDatabase` ni `FeTransmisionesTest`.
5. **`TransversalTestDataCleanupService` no se ve afectado:** solo actúa sobre tablas con FK a `auth.users`; `fe_transmisiones` no tiene columna de usuario. (Ya excluye explícitamente `audit_logs` por el mismo motivo.)
6. Capas que hoy tiene `audit_logs` (ADR-SCRUM815): (1) `REVOKE` al rol de runtime, (2) archivado a S3 antes de purgar, (3) backup inmutable S3 Object Lock, solo prod. `fe_transmisiones` no se purga (decisión de Luis: retención sin purga), así que la capa 2 no aplica.

## Opciones
| | Qué protege | Qué no protege | Costo |
|---|---|---|---|
| **A. `REVOKE UPDATE, DELETE` a `atlanticerp_app` en `GrantAppRoleCommand`** (recomendada) | Cualquier bug o inyección en la app, `tinker`/`DB::table`, credencial de la app comprometida | Un superuser (`atlanticerp`, usado solo por migraciones/deploy) | ~1 h: un método + un test. Mismo patrón que `audit_logs` |
| B. Trigger `BEFORE UPDATE OR DELETE OR TRUNCATE` que aborta | Igual que A, pero también frena al dueño de la tabla | Un superuser puede deshabilitar el trigger; añade una función PL/pgSQL y mantenimiento por tenant | Medio; sin ganancia real sobre A |
| C. Extender los triggers de `db_audit_log` (SCRUM-916) a esta tabla | Detección (queda rastro de quién alteró), no prevención | Solo cubren `auth` hoy; suman PII duplicada del payload | Alto; rechazado |

## Recomendación (A) — diseño
- En `GrantAppRoleCommand::handle()`, junto a las otras dos revocaciones, agregar `revokeFeTransmisionesWrite($slug)`: `REVOKE UPDATE, DELETE ON "{slug}_admin_contab".fe_transmisiones FROM atlanticerp_app`.
- **Guardia de existencia:** si la tabla todavía no existe en ese tenant (deploy en que la migración aún no corrió), no fallar: `to_regclass('"{slug}_admin_contab".fe_transmisiones') IS NOT NULL`.
- Test en `tests/Feature/Auth/GrantAppRoleCommandTest.php`: tras correr el comando, `has_table_privilege('atlanticerp_app', '..fe_transmisiones', 'UPDATE'|'DELETE')` = false y `'INSERT'|'SELECT'` = true.
- Sin cambios de frontend, sin migración nueva, sin cambio de deploy (ya corre el comando en cada deploy).
- Verificación posterior en dev, test y prod con `has_table_privilege()` (solo lectura por SSH; requiere tu aprobación individual del prompt).
- Actualizar el comentario de "Regla #7" del servicio de limpieza y la lista de tablas inmutables en `docs/architecture/bodega-telemetria-fraude-diseno.md`.

## Decisiones que necesito de Luis
1. **D1 — ¿Apruebas la opción A?** (Recomendado: sí.)
2. **D2 — ¿Extender el backup inmutable S3 Object Lock (SCRUM-815, solo prod) a `fe_transmisiones`?** Recomendado: **no por ahora** (volumen bajo, payloads con PII del receptor, y hoy no hay pedido del CTO); reevaluar si Cybersecurity lo exige para la cadena de custodia fiscal.

## Criterios de aceptación
1. Con el rol de la app, `UPDATE`/`DELETE` sobre `fe_transmisiones` fallan con `SQLSTATE 42501` aunque se use `DB::table()` o SQL crudo; `INSERT`/`SELECT` siguen funcionando.
2. El comando es idempotente y no falla si la tabla aún no existe en algún tenant.
3. Test automatizado del privilegio; PHPStan Level 8 limpio; suite completa verde.
4. Verificado con `has_table_privilege()` en dev, test y prod.

**Esfuerzo:** ~1-2 h. **Riesgo:** bajo (aditivo; rollback = `GRANT UPDATE, DELETE` manual). **Agente:** Backend Dev + verificación DevOps.

## Decisiones tomadas (Luis, 2026-09-29)
- **D1 — Opción A aprobada:** `REVOKE UPDATE, DELETE` a `atlanticerp_app` en `GrantAppRoleCommand`.
- **D2 — Sin backup inmutable S3** para `fe_transmisiones` por ahora (se reevalúa si Cybersecurity lo exige).
