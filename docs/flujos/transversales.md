# Flujo transversal: usuarios, acceso, permisos, «Ver como usuario», niveles y notificaciones

Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-10-29 con las cuentas demo; el usuario de prueba se crea y se desactiva en cada corrida.

## 1. Alta de usuario
`POST users {first_name, last_name, email, role_id, security_level_id, …}` [E].
- Solo quien tiene `platform.management` + `security.users` (Gerencia y superadmin); el vendedor, 403. El correo es único (422) y el nivel de seguridad debe existir y estar activo (422) [E].
- **No devuelve ninguna contraseña**: la clave inicial es el propio correo (decisión del 2026-10-05, ver `WelcomeMail`), `must_change_password = true` y sin plazo de caducidad [E][C].

## 2. Primer ingreso
- El login con el correo funciona (200) y entrega token, pero **toda la API responde 403 `password_change_required`** salvo `/auth/me` (que informa `must_change_password: true`) y el cambio de clave [E].
- `PUT auth/password {current_password, new_password, new_password_confirmation}`: mínimo 8 caracteres, con mayúscula y número, confirmación igual, distinta de la actual, actual correcta; 204 al cambiar [E].
- Tras cambiarla, la clave vieja da 401, la nueva 200 y la API responde [E].

## 3. Permisos adicionales
`extra_permissions` de un usuario: claves del catálogo de permisos (422 si no existe) [E].
- **Solo superadmin los asigna.** Gerencia puede editar al usuario, pero lo que envíe en `extra_permissions` **se descarta en silencio** (el `PUT` responde 200 y el campo queda `[]`): es una protección contra la escalada de privilegios [E][C].
- Asignado por superadmin, se guarda y **viaja en el token del siguiente ingreso** (`permissions`); quitarlo lo vacía [E].

## 4. Desactivar y reiniciar
- `PATCH users/{id}/status {is_active}`: Gerencia lo hace; al desactivar, **el token vigente deja de servir al instante** (401) y no se puede ingresar [E].
- `POST users/{id}/reset-password`: **solo superadmin** (Gerencia 403); la clave vuelve a ser el correo y exige cambio de nuevo [E].

## 5. «Ver como usuario» (observación de solo lectura)
`POST users/{id}/impersonate {reason, operator_password}` [E].
- Solo superadmin; motivo de **al menos 10 caracteres**; pide la **contraseña del propio operador** (sin ella 422 `operator_password_required`; incorrecta, `operator_reauth_failed`) [E].
- La sesión dura **30 minutos**. `/auth/me` informa al observado y la sesión (actor, objetivo, vencimiento). Lee (200) pero **toda escritura responde 403 `impersonation_read_only`** [E].
- `POST auth/impersonation/stop` (204) invalida el token de observación; la sesión queda en `GET admin/security/impersonations` con su motivo [E].

## 6. Niveles de seguridad
`POST security-levels {name, level}` (Gerencia/superadmin; vendedor 403); `PATCH …/status` los desactiva. No se borran, y no hay endpoint de detalle por id (405) [E].

## 7. Notificaciones
`GET notifications` lista las del usuario (p. ej. «El pedido … guardó su entrega en Bodega y está listo para facturar», con su enlace); `POST notifications/read-all` (200); marcar una ajena o inexistente → 404 [E]. En el demo hay **0 reglas de notificación**: los avisos del sistema salen sin reglas [E].

## Candidatos a cambio o mejora
1. **La clave inicial es el correo del usuario**, conocida por quien lo crea y repetida al reiniciar; el cambio obligatorio la acota, pero un enlace de activación de un solo uso sería más seguro (política decidida el 2026-10-05). [E][C]
2. **Asignar permisos adicionales sin ser superadmin responde 200 y no hace nada**: debería rechazarse (403/422) o la pantalla debería ocultar el campo. [E]
3. **Los niveles de seguridad admiten nombres repetidos**, e ignoran el número `level` pedido (asignan el siguiente libre). [E]
4. **Sin borrado de niveles ni de usuarios**: solo se desactivan (acumulan registros de prueba). [E]
5. **El motivo de «Ver como usuario» es texto libre**; no hay lista de motivos ni aprobación de un segundo operador. [E]
6. **Reiniciar la clave no avisa al usuario** (el correo local solo se registra en el log). [C]
7. **Sin ejercitar**: MFA (activar, verificar, desactivar), «olvidé mi contraseña» y su restablecimiento por correo, edición del perfil, departamentos, roles y visibilidad por módulo, reglas de aprobación de Gerencia, reglas de notificación, ajustes globales y de IA, catálogos administrables (cubiertos por el humo). [C]
