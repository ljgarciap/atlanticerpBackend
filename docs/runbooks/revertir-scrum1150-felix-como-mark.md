# Revertir SCRUM-1150 — Contabilidad replica a Aprobador en CRM y Ventas & Diseño

Todo el cambio es reversible. Tres niveles, de menor a mayor alcance.

## Qué agregó SCRUM-1150
- Migración `2026_10_15_130000`: columnas `can_edit` y `can_approve` (nulas) en `user_module_visibility`.
- Migración `2026_10_15_140000`: fila `ventas_diseno.gerencia_scope` en `permission_catalog`.
- Datos de Contabilidad (`contabilidad@test.com`): fila de override `ventas_diseno` (ver, equipo, editar, aprobar) y los permisos `select_partner_price`, `override_min_margin` y `gerencia_scope`.
- Código: middleware `gerencia.scope` (solo rutas de CRM y Ventas & Diseño), `User`/`UserService`, `OrderStatusController`, `ProjectService`, seeders y frontend.

Estado ANTERIOR de Contabilidad, verificado por lectura el 2026-10-15 en dev, test y prod: sin filas en `user_module_visibility`; `extra_permissions` sin los 3 permisos de arriba.

## Nivel 1 — Apagar el alcance de Gerencia sin desplegar (inmediato)
Desde la pantalla de usuarios (superadmin) quitar `ventas_diseno.gerencia_scope` a Contabilidad. Desde su próximo inicio de sesión vuelve a su rol normal en CRM y Ventas & Diseño. Lo demás (override de edit/approve, permisos de margen) sigue.

## Nivel 2 — Revertir los datos de Contabilidad (sin desplegar código)
```
php artisan users:revertir-contabilidad-como-mark            # ensayo, no escribe
php artisan users:revertir-contabilidad-como-mark --apply    # ejecuta
```
Quita solo la fila de override y los 3 permisos de SCRUM-1150; no toca nada anterior. Idempotente. El comando acepta `--email`: usarlo siempre con el correo de Contabilidad (apuntado a Aprobador le quitaría permisos legítimos). Contabilidad debe volver a iniciar sesión.
**Ojo:** los seeders recrean estos datos en el siguiente deploy mientras sus entradas sigan en el código; por eso este nivel solo es definitivo junto con el nivel 3.

## Nivel 3 — Revertir el código (definitivo)
1. `git revert` de los commits de SCRUM-1150 en backend y frontend (quita seeders, middleware y gates) y desplegar.
2. Correr el comando del nivel 2 con `--apply` en cada entorno.
3. Si se quiere dejar el esquema como estaba, revertir SOLO las dos migraciones de SCRUM-1150, nunca por cantidad (`--step` revertiría las últimas, que pueden ser otras si después se desplegó algo más). Primero `php artisan tenants:artisan "migrate:status"` para confirmar que `2026_10_15_130000_add_can_edit_can_approve_to_user_module_visibility` y `2026_10_15_140000_add_gerencia_scope_permission_to_catalog` están aplicadas, y luego, una por una:
   `php artisan tenants:artisan "migrate:rollback --path=database/migrations/tenant/2026_10_15_140000_add_gerencia_scope_permission_to_catalog.php"` y lo mismo con la `..._130000_...`. Ambas tienen `down()` y no pierden datos de otras funciones.
4. Verificar: Contabilidad sin fila en `user_module_visibility`, `modules.ventas_diseno` todo `false`, Aprobador sin cambios.
