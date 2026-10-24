# Runbook — Cambio obligatorio de la contraseña inicial (`users:require-password-change`)

**Para qué:** las cuentas nacen con la contraseña inicial = su correo (diseño del onboarding). Antes del lanzamiento no se sabe cuándo entrará cada persona, así que **no se usa el reset administrativo** (`users:reset-password`, que caduca a las 48 h). Este comando marca "debe cambiarla al primer ingreso" **sin caducidad**: quien entre con la contraseña inicial, sea cuando sea, es obligado a elegir la suya.

## Qué hace y qué no
- Alcanza a las cuentas **activas** cuya contraseña es exactamente su correo y que **no** tienen ya el cambio obligatorio.
- Solo pone `must_change_password = true`. **No** cambia la contraseña, **no** pone `password_reset_at` (sin caducidad), **no** cierra sesiones y **no** envía correo.
- Una sesión ya abierta de una cuenta marcada recibe la pantalla de cambio obligatorio en su siguiente petición (usa `--except` para quien lo ejecuta).
- Audita `auth.user.password_change_required` por cuenta y `auth.user.password_change_required_bulk` con el resumen. Nunca imprime contraseñas ni hashes.

## Uso
```bash
# Simulación (por defecto): lista a quién alcanzaría, no cambia nada
php artisan users:require-password-change
php artisan users:require-password-change --except=superadmin2@test.com

# Aplicar (exige --operador y confirmación escrita "MARCAR <ENTORNO> <N>"; PRODUCCION en producción)
php artisan users:require-password-change --apply --operador="Nombre Apellido" --except=...
```
Por ssh sin TTY, la confirmación entra por stdin con `SHELL_INTERACTIVE=1`:
```bash
printf "MARCAR PRODUCCION 24\n" | docker exec -i -e SHELL_INTERACTIVE=1 infra-laravel-1 \
  php artisan users:require-password-change --apply --operador="Nombre Apellido"
```
Corre la simulación primero y usa el `N` que muestra. `--yes` solo funciona en local y testing.

## Cuentas nuevas
`UserService::approve` ya marca el cambio obligatorio sin caducidad (SCRUM-1034): este comando es para las cuentas **que ya existían** (seeders).

## Riesgo que sigue vivo hasta el lanzamiento
Mientras una persona no haya entrado, su contraseña sigue siendo el correo: quien lo adivine y llegue a prod antes que ella puede entrar primero y fijar la suya. El cambio obligatorio no lo evita; solo garantiza que la contraseña inicial no sobreviva al primer ingreso. Si prod es accesible públicamente antes del lanzamiento, valorar restringir el acceso (lista de IP o autenticación HTTP) hasta ese día.
