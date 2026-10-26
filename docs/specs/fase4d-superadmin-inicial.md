# Spec — Fase 4d: alta inicial de superadmin (sin cuentas sembradas con contraseña = correo)

**Estado**: decisiones de Luis registradas (§5); pendiente su «adelante» para implementar.
**Origen**: hallazgo abierto de la 4a. `DatabaseSeeder` corre `CoreUserSeeder` y `BusinessRoleUserSeeder` en TODOS los entornos (bloque «núcleo»).
Siembran ~39 cuentas `*@test.com` con **contraseña = correo** (`password_hash => $data['email']`, `must_change_password` en falso). En producción eso es
un acceso público con credenciales adivinables, incluido nivel 10 (`superadmin@test.com`).

## 1. Hechos verificados
- `CoreUserSeeder`: 7 cuentas (3 superadmin nivel 10, 4 gerencia nivel 9), contraseña = correo, solo crea si no existe.
- `BusinessRoleUserSeeder`: el resto de identidades por rol, mismo patrón.
- `tenant:create {slug} {name}` crea schemas y migra; **no** crea ningún usuario. Un tenant nuevo sin seeders queda sin forma de entrar.
- Seeders del núcleo que buscan usuarios por correo y **salen sin error si no existen** (`PrimaryApproverBackfill`, `AdminContPrimaryApproverBackfill`,
  `UserModuleVisibilityGrant`, `InternalTechnician`, `WarehouseAssistantMapping`, `VentasDisenoSalesGoals`, `RoleBackfill`): se confirma por test en la implementación.
  Consecuencia: sin usuarios sembrados, `primary_approver_user_id` queda NULL (las aprobaciones de umbral alto quedan bloqueadas hasta que el superadmin lo configure en Ajustes).
- La política de contraseñas ya existe: `must_change_password`, cambio obligatorio en el primer ingreso (H7), `password_changed_at` invalida tokens.

## 2. Diseño propuesto
1. **Comando `tenant:create-superadmin {slug} {email} [--name=] [--password=]`**: crea un usuario con rol `superadmin`, nivel 10, `must_change_password = true`.
   Sin `--password`, genera una contraseña aleatoria de 20+ caracteres y la **muestra una sola vez** en la terminal (no se registra en logs ni en BD en claro). Falla si el
   tenant no existe o el correo ya existe. Idempotente: nunca resetea a un usuario existente.
2. **`DatabaseSeeder`**: `CoreUserSeeder` y `BusinessRoleUserSeeder` pasan del bloque núcleo al bloque demo (`local`, `dev`, `testing`), junto con los seeders que dependen de sus
   correos. En `production`/`qa` el núcleo ya no crea ningún usuario. `TestDatabaseSeeder` no cambia (testing sigue sembrando todo).
3. **Primer arranque**: `tenant:create` imprime la instrucción «ejecute `tenant:create-superadmin`». No lo ejecuta solo (decisión de §5-1).
4. **Tests de contrato**: (a) el seed completo bajo `production` no deja ningún usuario; (b) ningún seeder del núcleo asigna `password_hash` igual al correo; (c) el comando: crea,
   obliga cambio de contraseña, no pisa existentes, rechaza tenant inexistente, no escribe la contraseña en logs.
5. **Documentación**: pasos de alta de un entorno nuevo (`tenant:create` → `db:seed` → `tenant:create-superadmin` → primer ingreso) en el README del backend.

## 3. Fuera de alcance
- Renombrar identidades demo (`*@test.com`) — es la 4c.
- Recuperación de contraseña por correo del superadmin (ya existe el flujo normal de la app).
- Despliegue real / secretos (decisión de infraestructura pendiente).

## 4. Criterios de aceptación
1. Con `APP_ENV=production`, `php artisan db:seed` no crea ningún usuario.
2. `tenant:create-superadmin atlantic admin@empresa.com` permite ingresar con la contraseña mostrada y obliga a cambiarla.
3. En `local/dev/testing` el comportamiento actual no cambia (misma suite verde, sin tocar tests existentes salvo los que fijaban el bloque del seeder).
4. Ningún lugar del código versionado fija una contraseña igual al correo fuera del bloque demo (test de contrato).

## 5. Decisiones de Luis (2026-10-25)
1. **Comando aparte**: `tenant:create-superadmin`; `tenant:create` solo imprime la instrucción.
2. **Contraseña aleatoria mostrada una sola vez**, cambio obligatorio al primer ingreso; `--password` disponible para scripts.
3. **`qa` también sin usuarios**: solo `local`, `dev` y `testing` reciben usuarios demo; `qa` y `production` se dan de alta con el comando.

## 6. Resultado de la implementación (2026-10-25)
- `DatabaseSeeder`: `CoreUserSeeder`, `BusinessRoleUserSeeder` y los 7 seeders que buscan usuarios por correo (`PrimaryApproverBackfill`, `AdminContPrimaryApproverBackfill`,
  `RoleBackfill`, `VentasDisenoSalesGoals`, `UserModuleVisibilityGrant`, `InternalTechnician`, `WarehouseAssistantMapping`) pasaron al bloque demo (`local`/`dev`/`testing`), usuarios primero.
  El núcleo (`production` y `qa`) ya no crea ningún usuario.
- `tenant:create-superadmin {slug} {email}` (`CreateSuperadminCommand`): rol `superadmin`, nivel 10, `must_change_password = true`, contraseña de 24 caracteres mostrada una sola vez
  (o `--password`, mínimo 12, que no se imprime); rechaza tenant inexistente, correo inválido, contraseña corta y usuario existente; exige `db:seed` previo (rol y nivel). Opciones
  `--first-name`/`--last-name` en vez de `--name`. `tenant:create` imprime el siguiente paso, no crea usuarios.
- Tests: `CreateSuperadminCommandTest` (6) y 2 nuevos en `DatabaseSeederTiersTest` (ningún seeder fuera de demo asigna `password_hash`; el seed bajo `production` no recrea cuentas demo).
  Mutación verificada: con el `DatabaseSeeder` anterior fallan 2 de 5.
- **Efecto a conocer**: sin usuarios sembrados, `primary_approver_user_id` queda en NULL hasta que el superadmin lo configure en Ajustes (los umbrales altos quedan bloqueados mientras tanto).
