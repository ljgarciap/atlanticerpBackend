# Visual Review + Pre-QA (fusionado) — Admin&Cont, Cuentas Bancarias (SCRUM-607→611, REQ-530→534)

**Fecha:** 2026-08-19
**Resultado: LIMPIO tras 1 fix (mismo día, re-check completo tras el fix) — pasa a QA.**

Spec de referencia: 5 tickets de Jira (SCRUM-607→611) + mockup adjunto en SCRUM-607
(`4H__Admin_Contabilidad_CuentasBancarias.html`, attachment 11315).

Primera pantalla del cuerpo principal de Admin&Cont con roles múltiples (Aprobador/Gerencia, Contabilidad
`lider_admin_contab`, Asistente `asistente_administrativa`) en vez del gate exclusivo de Aprobador de los
2 batches anteriores.

---

## 1ª pasada — 1 hallazgo MAYOR

### MAYOR — Falta "Moneda" en la tab de cada cuenta activa (RN3 REQ-531)

El mockup (líneas 453-457) muestra la tab en 2 líneas, con un subtítulo `****{dígitos} · {moneda}`.
La implementación (`BankAccountsPage.tsx`, `accountLabel()` línea 34-36 usada en línea 152) solo
producía `"{banco} — {tipo} ****{dígitos}"` — moneda ausente por completo de la tab (sí presente en
el modal de detalle y en la lista de "Eliminadas"). RN3 es explícita: "Cada pestaña de cuenta
activa muestra el nombre del banco, el tipo, los últimos 4 dígitos y la moneda."

**Fix aplicado** (mismo día, por el Arquitecto directamente): tab ahora renderiza
`{accountLabel(account, t)} · {account.moneda}`. Se agregó test de regresión y se ajustaron 2 tests
existentes que hacían match exacto contra el texto viejo de la tab.

## Verificado en vivo (curl real + Playwright real) — sin más hallazgos

- Gate exclusivo de Aprobador NO aplica acá (a propósito) — verificado que Contabilidad y Aprobador ambos acceden a
  la pantalla general, pero solo Contabilidad/Asistente pueden `PUT .../assign-account` (REQ-534) — Aprobador real
  → 403; Aprobador → `POST /bank-accounts` → 201 (sí incluido en el gate general, correcto).
- RN4 REQ-530 (nunca número completo): confirmado en código y en la respuesta real de la API.
- Defaults al crear (tipo_cuenta=corriente, moneda=USD, activa=true) — verificado con `POST` real.
- RN1 REQ-534 (solo comisión sin cuenta): reintentar asignar sobre un movimiento ya asignado → 422
  real, verificado con Contabilidad.
- RN2 REQ-534 (solo cuenta activa): asignar una cuenta `activa:false` → 422 real.
- `GET /bank-accounts?activa=true` excluye cuentas inactivas — verificado real.
- Tras el fix: creación real de cuenta vía UI en Playwright → tab muestra la moneda, auto-switch a
  la tab nueva (RN3 REQ-530) confirmado en el mismo flujo.

## Regresión — suites completas

- Backend: PHPStan Level 8 → 0 errores. `infra/test.sh` → 2376/2376 OK (incluye 54 tests de
  AdminCont del batch).
- Frontend: `npx vitest run` → 1305/1305 OK (incluye 10 tests de `BankAccountsPage.test.tsx`).
- Gate de `infra/deploy-manual.sh` (backend + frontend) → limpio, ambos desplegados.

## Veredicto y despliegue

**Pasada limpia tras 1 fix, mismo pase, checklist completo re-corrido después del fix.** Los 5
tickets (SCRUM-607→611) pasan a `QA`.

Pusheado a `origin/dev` (backend `f29f2f6`, frontend `5292729`) el 2026-08-19 con aprobación
explícita de Luis. Desplegado vía `infra/deploy-manual.sh dev` en ambos repos — `dev.atlanticerp.ai/up`
y `dev.atlanticerp.ai/` responden OK. Disponible para validación del CTO en dev.
