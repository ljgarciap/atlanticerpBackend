# Review: Fase 4d — alta inicial de superadmin y cuentas demo solo en local/dev/testing
**Date**: 2026-10-26
**Reviewer**: Senior Reviewer
**Decision**: ✅ Approved (0 bloqueantes, 4 sugerencias)

## Summary
Backend PR #9. Cumple la spec (`docs/specs/fase4d-superadmin-inicial.md`): `CoreUserSeeder`, `BusinessRoleUserSeeder` y los 7 seeders que buscan usuarios por correo pasan al bloque demo
(`local`/`dev`/`testing`); `qa` y `production` no siembran usuarios. Comando `tenant:create-superadmin` con contraseña aleatoria de 24 caracteres mostrada una vez, cambio obligatorio y sin
pisar usuarios existentes. CI en verde (26m34s); suite local 6196 tests (1 fallo de `RoleKeysUsageTest` corregido), PHPStan 0, Pint limpio.

## Findings

### 🔴 Blockers
Ninguno.

### 🟡 Suggestions (non-blocking)
1. **`--password` en la línea de comandos** — queda en el historial del shell y en la lista de procesos. La contraseña aleatoria (por defecto) evita el problema; documentar que `--password` es para scripts
   con la variable ya protegida, o aceptarla también por entrada estándar.
2. **Auditoría (`LogsAuditTrail`)** — el evento `created` guarda `getAttributes()`, que incluye `password_hash` (el hash bcrypt, nunca la contraseña en claro). Es preexistente para toda creación de usuario;
   conviene excluir `password_hash` del diff de auditoría en `auditableAttributes()`.
3. **`primary_approver_user_id` en NULL** — sin usuarios sembrados, las aprobaciones de umbral alto quedan bloqueadas hasta que el superadmin configure el aprobador en Ajustes. Añadirlo a la lista de pasos
   del alta de un entorno nuevo (README).
4. **Abierto de la 4a** — el flujo de Zona Libre sigue sin forma de configurar su proveedor (Fase 5/7).

### 🟢 Well done
- Contraseña nunca en logs ni en BD en claro; con `--password` no se imprime; validaciones completas (tenant, correo, longitud, existente, rol/nivel presentes).
- Contrato en tests: el seed bajo `production` no recrea cuentas demo y ningún seeder fuera de demo asigna `password_hash` (mutación verificada: con el seeder anterior fallan 2 de 5).
- El resto de seeders que buscaban usuarios por correo ya salían sin error: la suite lo confirma.

## Next steps
Listo para merge.
