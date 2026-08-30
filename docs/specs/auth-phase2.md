# Spec: Auth Fase 2 — Password Recovery, MFA y Registro Público

**Estado:** Aprobada por Luis — 2026-06-19
**Analista:** Analista  
**Complejidad:** Media-Alta (3 features independientes, 1 shared infrastructure)

---

## Contexto

El módulo Auth tiene: login JWT, refresh tokens, RBAC multi-rol, security levels, user management, preferences, system settings. SMTP Office365 configurado y probado (`atlanticerp@illuminations.com.pa`). Esta fase agrega tres features de seguridad fundamentales.

---

## Feature 1 — Recuperación de Contraseña

### Flujo
1. Usuario en login presiona "¿Olvidaste tu contraseña?"
2. Ingresa su email → `POST /api/auth/forgot-password`
3. Si el email existe: se crea token de 1h y se envía correo con link
4. Si el email NO existe: misma respuesta genérica (no revelar si existe)
5. Usuario hace click en link → página `/reset-password?token=xxx`
6. Ingresa nueva contraseña → `POST /api/auth/reset-password`
7. Token se marca como usado → redirect a login con toast de éxito

### Nuevas tabla
```sql
-- schema: {tenant}_auth
CREATE TABLE password_reset_tokens (
    id          BIGSERIAL PRIMARY KEY,
    email       VARCHAR(255) NOT NULL,
    token_hash  VARCHAR(255) NOT NULL UNIQUE,  -- SHA-256 del token raw
    expires_at  TIMESTAMP NOT NULL,            -- now() + 1 hour
    used_at     TIMESTAMP NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX ON password_reset_tokens (email);
```

### Endpoints
```
POST /api/auth/forgot-password
  body: { email }
  response: 200 { message: "Si el correo existe, recibirás un enlace." }
  — no requiere JWT —

POST /api/auth/reset-password
  body: { token, password, password_confirmation }
  response: 200 { message: "Contraseña actualizada." }
           422 { errors } si token inválido/expirado/usado o passwords no coinciden
  — no requiere JWT —
```

### Correo
- Asunto: "Restablece tu contraseña — AtlanticERP CRM"
- Cuerpo: logo + nombre del usuario + botón "Restablecer contraseña" (link con token raw)
- Link expira en 1 hora, indicarlo en el correo
- Template: blade o Mailable de Laravel con estilo de marca (`#2c8c84`)

### Criterios de aceptación
- [ ] Token expira exactamente a 1h
- [ ] Token usado no puede reutilizarse (409 o 422)
- [ ] Múltiples tokens activos por email → solo el más reciente funciona (invalidar anteriores al crear uno nuevo)
- [ ] Email con email inexistente responde igual que con email existente
- [ ] La nueva contraseña pasa las mismas validaciones que change-password existente

---

## Feature 2 — MFA por Email

### Concepto
Autenticación de dos factores vía código de 6 dígitos enviado al email registrado. Configurable a nivel de tenant (setting global) y a nivel de usuario (preferencia individual, solo cuando el tenant lo permite como opcional).

### Configuración por tenant
Nueva clave en `system_settings`:
```
key: "mfa_mode"
value: "optional"   -- default
```
Valores posibles:
- `"disabled"` — MFA completamente desactivado; el toggle de usuario no aparece
- `"optional"` — Cada usuario decide si activa MFA en su perfil
- `"required"` — Todos los usuarios deben completar MFA en cada login; ignora preferencia de usuario

### Configuración por usuario
Nueva columna en la tabla `users`:
```sql
ALTER TABLE {tenant}_auth.users ADD COLUMN mfa_enabled BOOLEAN NOT NULL DEFAULT FALSE;
```
Solo aplica cuando `mfa_mode = 'optional'`. Si `mfa_mode = 'required'`, esta columna se ignora.

### Flujo de login modificado
```
POST /api/auth/login (credentials)
  → si credenciales inválidas: 401
  → si MFA activo (required O (optional AND user.mfa_enabled)):
      1. Generar código 6 dígitos, guardar hash en mfa_codes (TTL 10min)
      2. Enviar email con código
      3. Retornar: 200 { requires_mfa: true, mfa_challenge_id: "uuid" }
  → si MFA no activo: retornar JWT normal (comportamiento actual)

POST /api/auth/mfa/verify
  body: { mfa_challenge_id, code }
  response: 200 { access_token, refresh_token, user }
           422 si código incorrecto o expirado
           429 si > 5 intentos fallidos para ese challenge_id
  — no requiere JWT —
```

### Nueva tabla
```sql
-- schema: {tenant}_auth
CREATE TABLE mfa_codes (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         BIGINT NOT NULL REFERENCES {tenant}_auth.users(id) ON DELETE CASCADE,
    code_hash       VARCHAR(255) NOT NULL,   -- SHA-256 del código de 6 dígitos
    attempts        SMALLINT NOT NULL DEFAULT 0,
    expires_at      TIMESTAMP NOT NULL,      -- now() + 10 minutes
    used_at         TIMESTAMP NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### Email MFA
- Asunto: "Tu código de verificación — AtlanticERP CRM"
- Cuerpo: código grande, "Expira en 10 minutos. No compartas este código."

### UI — Pantalla MFA (misma card que login)
```
[Logo]
"Verificación de dos factores"
"Hemos enviado un código de 6 dígitos a tu correo."
[Input: código] 
[Botón: Verificar]
[Link: Reenviar código (disponible después de 60s)]
[Link: ← Volver al login]
```

### UI — Perfil de usuario
- Sección "Seguridad" en el drawer de perfil existente
- Toggle "Autenticación de dos factores" (visible solo cuando `mfa_mode = 'optional'`)
- Al activar: confirma con contraseña actual antes de guardar

### UI — Settings globales (superadmin/management)
- Select en la página de Settings: "Autenticación de dos factores"
  - Desactivado / Opcional (recomendado) / Obligatorio para todos

### Criterios de aceptación
- [ ] Con `mfa_mode = "disabled"`: login no pide código; toggle de usuario no aparece en perfil
- [ ] Con `mfa_mode = "optional"` y `user.mfa_enabled = false`: login directo
- [ ] Con `mfa_mode = "optional"` y `user.mfa_enabled = true`: login pide código
- [ ] Con `mfa_mode = "required"`: todos los usuarios piden código independiente de su preferencia
- [ ] Código expirado retorna 422 con mensaje específico
- [ ] Más de 5 intentos fallidos invalida el challenge (429 Too Many Requests)
- [ ] Reenviar código invalida el anterior y genera uno nuevo
- [ ] Cambio de `mfa_mode` en settings se aplica en el siguiente login (no cierra sesiones activas)

---

## Feature 3 — Registro Público (Crear Cuenta) — v1

### Contexto
Primera iteración: registro simple con aprobación manual de admin. Luis confirmó que habrá requisitos adicionales en iteraciones futuras.

### Flujo
1. Desde la página de login: link "¿No tienes cuenta? Crear cuenta"
2. Formulario público `/register` (misma card style que login)
3. Al enviar: cuenta creada con `role=guest`, `security_level_id=1`, `is_active=false`
4. Mensaje de confirmación: "Tu solicitud fue enviada. Un administrador revisará tu cuenta."
5. Admin ve pendientes en `/security/users` → tab "Pendientes"
6. Admin aprueba: selecciona rol + nivel de seguridad → `is_active=true`
7. Admin rechaza: elimina la cuenta pendiente
8. (v1 sin notificación por email al usuario cuando aprueban/rechazan — entra en v2)

### Endpoint público
```
POST /api/auth/register
  body: { first_name, last_name, email, department_id }
  — sin JWT —
  response: 201 { message: "Solicitud enviada correctamente." }
           422 { errors } si email ya existe o campos inválidos
```

### Endpoint público para cargar áreas (department_id)
```
GET /api/auth/departments/public
  — sin JWT —
  response: 200 { data: [{id, name}] }
```
(El endpoint de departments ya existe pero requiere JWT — se agrega variante pública)

### Cambios en User model/BD
- Nueva constante: `ROLE_GUEST = 'guest'`
- Al registrar: `role=guest`, `security_level_id=1` (Read Only), `is_active=false`
- No necesita nueva tabla — se identifica por `is_active=false AND role=guest`

### Endpoint admin — aprobar/rechazar
```
POST /api/auth/users/{id}/approve
  body: { role, security_level_id }
  Requiere: JWT + role superadmin|management
  response: 200 { user }
  — sets is_active=true, updates role and security_level_id —

DELETE /api/auth/users/{id}/reject
  Requiere: JWT + role superadmin|management
  response: 204
  — elimina el usuario pendiente; solo permitido si is_active=false AND role=guest —
```

### UI — Formulario de registro
```
[Logo]
"Crear cuenta"
[Nombre *]   [Apellidos *]
[Correo electrónico *]
[Área (departamento) *]  ← select cargado de /api/auth/departments/public
[Botón: Enviar solicitud]
[Link: ¿Ya tienes cuenta? Iniciar sesión]
```

### UI — Users page: tab "Pendientes"
- Nueva tab en SecurityLayout junto a Usuarios/Grupos/Departamentos/Niveles
- Tabla: Nombre | Email | Área | Fecha solicitud | Acciones (Aprobar | Rechazar)
- Al Aprobar: modal con select de Rol y select de Nivel de Seguridad
- Al Rechazar: confirm dialog → elimina

### Criterios de aceptación
- [ ] Email duplicado retorna 422 con mensaje "Este correo ya está registrado."
- [ ] Usuario registrado NO puede hacer login hasta ser aprobado (401 "Cuenta pendiente de aprobación")
- [ ] Admin puede aprobar con cualquier combinación de rol + nivel permitido por su propio nivel
- [ ] Al rechazar, el usuario es eliminado permanentemente
- [ ] La tab "Pendientes" solo aparece para superadmin y management
- [ ] Formulario de registro es accesible sin token JWT

---

## Cambios en sistema de settings (compartido entre Feature 2 y 3)

### Nuevas claves en `system_settings`
```
mfa_mode: "optional"     -- Feature 2
```

### Migration de datos
```sql
INSERT INTO {tenant}_auth.system_settings (key, value)
VALUES ('mfa_mode', '"optional"')
ON CONFLICT (key) DO NOTHING;
```

### Extensión de `SettingsController` + `PreferencesService`
- GET `/api/auth/settings` → agrega `mfa_mode` a la respuesta
- PUT `/api/auth/settings` → acepta y valida `mfa_mode` (in: disabled, optional, required)
- Solo superadmin y management pueden actualizar settings del sistema

---

## Resumen de cambios técnicos

| Tipo | Qué |
|---|---|
| Migrations | `mfa_enabled` en users, tabla `mfa_codes`, tabla `password_reset_tokens`, seed `mfa_mode` |
| Constante | `User::ROLE_GUEST = 'guest'` |
| Nuevo controller | `ForgotPasswordController`, `MfaController`, `RegisterController` |
| Controller extendido | `SettingsController` (mfa_mode), `UserController` (approve/reject) |
| Service extendido | `PreferencesService` (mfa_mode), nuevo `MfaService`, nuevo `PasswordResetService` |
| Mail | `PasswordResetMail`, `MfaCodeMail` |
| Rutas nuevas | Ver endpoints de cada feature arriba |
| FE routes | `/register`, `/forgot-password`, `/reset-password` (fuera de AppShell) |
| FE componentes | `RegisterPage`, `ForgotPasswordPage`, `ResetPasswordPage`, `MfaStep` (en login), toggle en perfil, settings MFA, tab Pendientes en Users |
