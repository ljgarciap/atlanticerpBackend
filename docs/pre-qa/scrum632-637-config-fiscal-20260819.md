# Visual Review + Pre-QA (fusionado) — Admin&Cont, Configuración Fiscal (SCRUM-632→637, REQ-555→560)

**Fecha:** 2026-08-19
**Entorno:** Docker local (`atlanticerp-backend/infra/docker-compose.yml`) + Vite dev server, y luego
verificación post-deploy real contra `dev.atlanticerp.ai`.
**Resultado: LIMPIO tras 2 fixes propios (mismo día, re-check completo tras cada fix) — pasa a QA.**

Spec de referencia: 6 tickets de Jira (SCRUM-632→637) + mockup adjunto en SCRUM-632
(`4K__Admin_Contabilidad_Config_Fiscal.html`, attachment 11365) — primer batch de la épica
Admin&Cont (SCRUM-331) con mockup real para comparar.

Suite Playwright: `atlanticerp-frontend/e2e/preqa-scrum632-637-config-fiscal-20260819.spec.ts` — se
conserva como test permanente del gate de Mark (mismo criterio que otros batches: un
gate/flujo de permiso que ya se verificó adversarialmente se promueve, no se descarta).

---

## 1ª pasada — 2 hallazgos MAYOR, 1 MENOR

### MAYOR #1 — Falta el badge "Estado de conexión" en el panel Facturación Electrónica

El mockup muestra un `status-badge` "Conectado" en el header del panel, y REQ-557 lo describe
explícito como contenido del panel ("el estado de conexión al Proveedor Autorizado Calificado...
y el ambiente activo" — dos cosas distintas). Ni el modelo, ni el contrato de API, ni el frontend
tenían ningún campo de estado de conexión — solo existía `pac_ambiente`. Ningún criterio
Given/When/Then de REQ-557 lo ejercita literalmente, pero es un elemento de panel pedido y
mockeado, completamente ausente — deviación del contrato de API que definió el Arquitecto al
dispatchar el batch, no de los devs.

**Fix aplicado** (mismo día): nueva columna `pac_connection_status` (string, default `'conectado'`,
informativa, mismo tratamiento que `pac_last_sync_at` — nunca editable ni en modo edición) +
migración `2026_08_19_100012_add_pac_connection_status_to_admin_contab_fiscal_settings_table.php` +
badge nuevo en `FiscalConfigPage.tsx` junto al de Ambiente.

### MAYOR #2 — Formulario "+ Agregar tasa" pedía 3 campos (Nombre/Descripción/Porcentaje); RN3 de REQ-558 solo pide 2

RN3 es textual: "tanto el porcentaje como la descripción de uso son obligatorios" — el mockup y el
escenario de aceptación ("Mark agrega una tasa nueva de 10% para 'Servicios de hospedaje'") tampoco
mencionan un nombre separado.

**Fix aplicado** (mismo día): `nombre` pasa a opcional/nullable en `StoreItbmsRateRequest` y en la
migración `2026_08_19_100013_make_nombre_nullable_on_admin_contab_itbms_rates_table.php`. El input
"Nombre" se quitó del formulario de alta. Tabla y modal de detalle usan `rate.nombre ?? rate.descripcion`
como label — las 3 tasas base (que sí tienen `nombre` fijo, ej. "7% General") no cambian.

### MENOR — Falta el banner informativo del mockup ("Solo Mark puede editar esta pantalla...")

Decorativo, sin pérdida de funcionalidad. **No se corrigió** — queda pendiente a propósito, no
bloqueante.

---

## Re-check tras los 2 fixes — íntegro, contra stack real

- `pac_connection_status`: confirmado en GET, ignorado por PUT aunque el cliente mande otro valor
  (curl). Badge visible en Playwright real, sin ninguna rama de modo edición — más estricto incluso
  que "Última sincronización".
- Creación de tasa solo con `{descripcion, porcentaje}` → `201` real (antes `422`). Form sin campo
  "Nombre" confirmado por label en Playwright, no solo por código. Tasas base sin cambios.
- Sin regresión: gate de Mark (403 GET/PUT para no-Mark, `superadmin.all` NO bypassea), defensa en
  profundidad (`pac_last_sync_at`/`mark_approver_user_id` inmunes a un PUT que intenta colarlos),
  modo lectura/edición con confirmación, tasas base sin botón eliminar, alta siempre `activa=false`,
  toggle de detalle de tasa reflejado en tabla sin cerrar el modal, retención a proveedores con nota
  aclaratoria.
- `e2e/preqa-scrum632-637-config-fiscal-20260819.spec.ts` → 2/2 OK.

## Checklist de verificación por ticket

| Ticket | Escenario | Resultado |
|---|---|---|
| SCRUM-632 (REQ-555) | Solo-lectura por defecto, "Editar" desbloquea todo salvo "Última sincronización", confirmación al guardar | OK |
| SCRUM-632 (REQ-555) | Acceso exclusivo de Mark — otro usuario (incl. superadmin) ve "Acceso restringido", 403 real en GET/PUT | OK |
| SCRUM-633 (REQ-556) | Datos fiscales de la empresa, régimen tributario con exactamente 2 opciones | OK |
| SCRUM-634 (REQ-557) | Panel PAC: proveedor fijo, ambiente con contraste fuerte, última sincronización solo lectura, 2 toggles de documento | OK |
| SCRUM-634 (REQ-557) | Badge "Estado de conexión" | **MAYOR detectado y corregido** |
| SCRUM-635 (REQ-558) | 3 tasas base sin botón eliminar, solo activar/desactivar | OK |
| SCRUM-635 (REQ-558) | Alta de tasa: solo porcentaje+descripción obligatorios | **MAYOR detectado y corregido** |
| SCRUM-635 (REQ-558) | Tasa nueva nace "Desactivada" siempre, aunque el cliente mande `activa:true` | OK |
| SCRUM-636 (REQ-559) | Toggle de detalle funcional solo en modo edición, reflejo inmediato en tabla | OK |
| SCRUM-637 (REQ-560) | Toggle de retención a proveedores + nota aclaratoria, editable solo en modo edición | OK |

## Regresión — suites completas tras ambos fixes

- Backend: `infra/test.sh --filter=AdminCont` → 19/19 OK. PHPStan (`app/Modules/AdminCont`) → 0 errores.
- Frontend: `npx vitest run --run FiscalConfig` → 10/10 OK. `tsc --noEmit` limpio.
- Suite completa (corrida por Senior Review antes de estos 2 fixes, no repetida entera después por
  ser un fix acotado a este módulo): backend 2339/2339, frontend 1283/1283.

## Archivos tocados en el re-check (además de los del batch original)

- `atlanticerp-backend/database/migrations/tenant/2026_08_19_100012_add_pac_connection_status_to_admin_contab_fiscal_settings_table.php`
- `atlanticerp-backend/database/migrations/tenant/2026_08_19_100013_make_nombre_nullable_on_admin_contab_itbms_rates_table.php`
- `atlanticerp-backend/app/Modules/AdminCont/Http/Requests/StoreItbmsRateRequest.php`
- `atlanticerp-frontend/src/pages/admin-contab/FiscalConfigPage.tsx` (+ `ItbmsRateDetailModal.tsx`)
- `atlanticerp-frontend/src/types/adminContab.ts`

## Veredicto y despliegue

**Pasada limpia tras 2 fixes propios, mismo pase, checklist completo re-corrido después de los
fixes.** Los 6 tickets (SCRUM-632→637) pasan a `QA`.

Pusheado a `origin/dev` (backend `61c3be3`, frontend `db069a5`) el 2026-08-19 con aprobación
explícita de Luis. CI/CD automático caído (cuota de GitHub Actions agotada) — desplegado vía
`infra/deploy-manual.sh dev` en ambos repos: 1285/1285 tests frontend en el gate del script,
`dev.atlanticerp.ai/up` y `dev.atlanticerp.ai/` responden OK. Disponible para validación del CTO en dev.
