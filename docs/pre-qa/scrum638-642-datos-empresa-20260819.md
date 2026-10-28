# Visual Review + Pre-QA (fusionado) — Admin&Cont, Datos de la Empresa (SCRUM-638→642, REQ-561→565)

**Fecha:** 2026-08-19
**Entorno:** Docker local + Vite dev server, re-check final contra `dev.atlanticerp.ai` post-deploy.
**Resultado: LIMPIO tras 1 fix (mismo día, re-check completo tras el fix) — pasa a QA.**

Spec de referencia: 5 tickets de Jira (SCRUM-638→642) + mockup adjunto en SCRUM-638
(`4L__Admin_Contabilidad_Info_Empresa.html`, attachment 11377).

Suite Playwright: `atlanticerp-frontend/e2e/preqa-scrum638-642-datos-empresa-20260819.spec.ts` — se
conserva como test permanente.

---

## 1ª pasada — 2 hallazgos MAYOR

### MAYOR #1 — Selector "Tipo" al crear ubicación: 2 opciones (Oficina/Otra) vs. 4 del mockup (Bodega/Showroom/Oficina/Otra)

Confirmado en el mockup (líneas 365-369: 4 `<option>`) y en vivo (`select[name="tipo"]` → solo
`['Oficina', 'Otra']`).

**Resuelto sin cambio de código — confirmado por el Arquitecto como decisión deliberada correcta:**
las 7-8 bodegas/showrooms se leen en vivo de `Bodega\Models\Warehouse` (RN1 de REQ-563: "nunca un
nombre alternativo o abreviado distinto"), así que permitir crear manualmente un tipo
"Bodega"/"Showroom" en esta pantalla violaría esa misma regla — el mockup es un prototipo estático
sin esa validación cruzada, la implementación real es más correcta. No bloquea, no se cambia.

### MAYOR #2 — Colisión de `key` de React entre ubicaciones de Bodega y administrativas

Ver detalle completo en `docs/reviews/scrum638-642-datos-empresa-20260819.md` (mismo hallazgo,
detectado independientemente por Senior Review y por este gate). **Fix aplicado** el mismo día:
`key={`${loc.source}-${loc.id}`}` en `CompanyProfilePage.tsx:500` + test de regresión.

---

## Re-check tras el fix — Playwright real + curl

- Ubicaciones de ambas fuentes ("Bodega Central" + una administrativa nueva) coexisten en la tabla
  sin mezclarse tras crear/togglear varias seguidas. "Bodega Central" nunca muestra acción de
  toggle, sin importar cuántas ubicaciones administrativas se creen.
- Test unitario de regresión (`CompanyProfilePage.test.tsx`, ids colisionantes 1/1 entre fuentes)
  → 11/11 en el archivo.
- Sin regresión: gate de Aprobador (403 GET/PUT real para no-Aprobador), defensa en profundidad
  (`razon_social`/`nombre_comercial`/`moneda`/`anio_fiscal` ignorados en PUT), `zona_horaria`
  rechaza cualquier valor ≠ `America/Panama`, alta de ubicación con `tipo:'bodega'` → 422 real,
  `PUT /locations/{warehouse_id}` → 404 real (no se puede togglear una fila de Bodega desde acá).

## Checklist de verificación por ticket

| Ticket | Escenario | Resultado |
|---|---|---|
| SCRUM-638 (REQ-561) | Solo-lectura por defecto, confirmación al guardar | OK |
| SCRUM-638 (REQ-561) | Razón social, nombre comercial, moneda, año fiscal SIEMPRE bloqueados (4 campos probados, no solo 1) | OK |
| SCRUM-639 (REQ-562) | Identidad de marca: logo, descripción corta, sitio web, redes sociales | OK |
| SCRUM-639 (REQ-562) | Razón social refleja el valor real de Configuración Fiscal | OK |
| SCRUM-640 (REQ-563) | Las 7-8 bodegas reales de Bodega, nombre exacto, sin acción de desactivar | OK |
| SCRUM-640 (REQ-563) | Selector de tipo al crear solo Oficina/Otra | OK — decisión deliberada, ver arriba |
| SCRUM-640 (REQ-563) | Colisión de key entre fuentes | **MAYOR detectado y corregido** |
| SCRUM-640 (REQ-563) | Desactivar ubicación administrativa → pestaña Inactivas, no elimina | OK |
| SCRUM-641 (REQ-564) | Alta de contacto con área/email/teléfono, tabs Activos/Inactivos | OK |
| SCRUM-642 (REQ-565) | Moneda/año fiscal bloqueados siempre; zona horaria única opción hoy | OK |

## Regresión — suites completas

- Backend: PHPStan Level 8 → 0 errores. `infra/test.sh` completo → limpio (1 falla no relacionada,
  confirmada flaky/módulo Servicios, ver Senior Review).
- Frontend: `npx vitest run` completo → 1296/1296 OK (incluye el test de regresión del fix).
- Gate de `infra/deploy-manual.sh` (backend + frontend) → limpio, ambos desplegados.

## Veredicto y despliegue

**Pasada limpia tras 1 fix, mismo pase, checklist completo re-corrido después del fix.** Los 5
tickets (SCRUM-638→642) pasan a `QA`.

Pusheado a `origin/dev` (backend `b26f018`, frontend `f9a2562`) el 2026-08-19 con aprobación
explícita de Luis. Desplegado vía `infra/deploy-manual.sh dev` en ambos repos — `dev.atlanticerp.ai/up` y
`dev.atlanticerp.ai/` responden OK. Disponible para validación del CTO en dev.
