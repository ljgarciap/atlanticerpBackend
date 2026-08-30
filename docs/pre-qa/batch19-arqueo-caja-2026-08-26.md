# Pre-QA + Visual Review fusionados — Batch 19 Admin&Cont, Arqueo/Flujo de Caja parte 2

**Fecha:** 2026-08-26 · **Tickets:** SCRUM-602→606 (REQ-525→529) · **Entorno:** LOCAL únicamente
(`localhost:5173` vía Vite, proxeando `/api` a `infra-nginx-1:8090` → `infra-laravel-1`) — código
commiteado sin push en `dev` de ambos repos (`atlanticerp-backend@05b1920`, `atlanticerp-frontend@3c4e82c`),
todavía no desplegado a `dev.atlanticerp.ai`.

Diseño de referencia: `atlanticerp-backend/docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md`.
Mockup: `4G__Admin_Contabilidad_ArqueoCaja.html` (mismo mockup que Batch 18 — este batch cubre lo
que Batch 18 dejó fuera: cerrar, cola de atrasados, historial+aprobación, retención, ver/descargar).

Credenciales usadas — **LOCAL**, password = email para todos (distinto de dev/test.atlanticerp.ai, donde
Mark/Daniela usan `B1n4X_2026?`): Felix (`conta@illuminations.com.pa`), Yaneth
(`asistente@illuminations.com.pa`), Mark (`mbekhar@illuminations.com.pa`), Daniela
(`daniela@illuminations.com.pa`).

## Resumen ejecutivo

| Ticket | REQ | Veredicto |
|---|---|---|
| SCRUM-602 | 525 — Ajustes/Retención | Limpio |
| SCRUM-603 | 526 — Cerrar arqueo del día | Limpio |
| SCRUM-604 | 527 — Bloqueo secuencial de atrasados | Limpio |
| SCRUM-605 | 528 — Historial + aprobación de Gerencia | Limpio |
| SCRUM-606 | 529 — Ver detalle / descargar resumen | Limpio |

**PASA.** 1 hallazgo real (MENOR, iconografía SCRUM-56) encontrado y corregido en la misma sesión,
recheck limpio confirmado. Suite automatizada completa (backend + frontend, scopeada al batch) y
14/14 escenarios adversariales de Playwright en verde tras el fix (1 test duplicado se dejó
`skip` a propósito, cubierto por otros 2). Sin hallazgos CRÍTICOS ni MEDIOS.

## Qué se probó

Automatizado (ya existente en el repo, corrido de nuevo para confirmar el estado real del batch):
- `infra/test.sh --filter=AdminContCashPositionControllerTest` → 31/31 OK (incluye 15 tests
  específicos de Batch 19: retención en el arqueo, cerrar, doble cierre, guard de inmutabilidad,
  bloqueo secuencial ×2, historial+contador, gate de management, approve exclusivo Mark, doble
  aprobación, `puede_aprobar` en sus 3 variantes, export PDF activo+historial).
- `infra/test.sh --filter=AdminContPaymentControllerTest` → 35/35 OK (incluye
  `test_retention_attachment_sube_y_permite_reemplazar` y
  `test_retention_attachment_rechaza_cobro_con_otro_metodo`).
- `npx vitest run ArqueoCajaPage` → 18/18 OK.
- `npx tsc --noEmit` → limpio.

Adversarial nuevo (spec ad-hoc `atlanticerp-frontend/e2e/_preqa_batch19_arqueo_parte2.spec.ts`, corrido
contra el stack local real con datos sembrados a propósito vía tinker — DB local estaba vacía de
`payments`/`daily_cash_counts`, se sembró un arqueo de ayer sin cerrar + un cobro con retención
fechado ayer para poder forzar los escenarios de REQ-527/525/526/528, y se limpió al terminar):

1. **REQ-527** — arqueo activo es el atrasado (ayer), no hoy, banner explícito con ambas fechas.
2. **REQ-525 RN1/RN2** — sección "Ajustes/Retención" visible solo porque hay un cobro con ese
   método, estado "Pendiente" correcto.
3. **REQ-526 RN2** — el modal de cierre avisa "1 retención(es) sin la constancia del cliente
   todavía — igual se puede cerrar, la constancia se sube después" y el botón de confirmar sigue
   habilitado (no bloquea).
4. **REQ-525** — subir constancia vía input de archivo real (PDF), queda reflejada con el nombre
   del archivo.
5. **REQ-525 — negativo, API directa**: `POST /admin-contab/payments/{id}/retention-attachment`
   contra un cobro con método "Transferencia" (no retención) → **422** con mensaje claro
   (`"Este cobro no fue registrado con método "Retención de impuestos"."`); contra el cobro con
   retención real → **201** con URL presignada de S3 real (bucket `atlanticerp-dev`, local S3 conectado
   de verdad). Confirmado por curl directo, no solo Playwright.
6. **REQ-526/REQ-527 RN3** — cerrar el arqueo atrasado (ya sin aviso de retención pendiente, se
   subió en el paso 4) avanza automáticamente al arqueo de hoy — el banner de atrasado desaparece
   sin recargar la página.
7. **REQ-529** — "Descargar resumen" del arqueo activo dispara una descarga real de PDF (>500
   bytes, no vacío/corrupto).
8. **REQ-528** — el panel de historial muestra el arqueo recién cerrado con el aviso "1 arqueo(s)
   pendiente(s) de aprobación de Gerencia" (contador correcto).
9. **REQ-528 RN2 — negativo, UI + API**: Yaneth (`asistente_administrativa`) no ve el botón
   "Aprobar" en el detalle, y `POST /cash-position/history/{id}/approve` contra ese mismo id
   devuelve **403** con su token real.
10. **REQ-528 RN2 — negativo, API**: Daniela (`management`, Gerencia pero no es Mark) también
    recibe **403** al intentar aprobar vía API directa — confirma que el gate es "Mark
    específicamente", no "cualquier rol management".
11. **REQ-528 Escenario 2** — Mark ve el botón "Aprobar" (los otros 3 roles no lo ven, incluida
    Gerencia-no-Mark), lo usa, y el arqueo queda "Aprobado por Mark Bekhar" tanto en la fila del
    historial como en el detalle.
12. **REQ-528 RN** — doble aprobación vía API (sobre un arqueo ya `aprobado`) → **409**, no
    aprueba silenciosamente dos veces.
13. **REQ-529 escenario 2** — "Descargar resumen" desde el detalle del historial (ya aprobado)
    también genera un PDF real no vacío.
14. **Iconografía (SCRUM-56)** — scan de texto renderizado de toda la pantalla contra un rango
    Unicode de emoji/dingbats. Encontró 1 hallazgo real (ver abajo), corregido, recheck limpio.

**No verificado / diferido:**
- **Concurrencia real en `close()`/`approve()`** (dos requests simultáneos): el guard existe
  (`lockForUpdate()` + chequeo de estado dentro de la transacción, ver `DailyCashCountService`) y
  el escenario secuencial (doble click / doble llamada) está cubierto por PHPUnit + el test 12 de
  arriba, pero no se forzó una carrera real de dos conexiones concurrentes — no se considera
  necesario dado el volumen (1 cierre/día, acción humana explícita, mismo criterio que el ADR usa
  para justificar no usar una tabla de folio dedicada).
- **`arqueo_tracking_start_date` editable por Mark** (el piso del escaneo de `activeDate()`): no
  se tocó `PUT /fiscal-settings` en esta sesión — la lógica de `activeDate()` en sí se ejerció
  indirectamente (el piso resolvió correctamente a la fecha más vieja con fila `abierto`), pero el
  camino de "Mark fija el piso a mano" no se probó de punta a punta.
- **Múltiples arqueos atrasados en cadena** (3+ días sin cerrar seguidos): se probó con exactamente
  1 día atrasado. El código escanea día por día sin tope explícito documentado más que "semanas
  desde el go-live" (ver ADR) — razonable para el volumen real, no se forzó el caso extremo.

## Hallazgo — MENOR (SCRUM-56, iconografía) — CORREGIDO

**Qué:** `historial.aprobadoPor` en ambos locales (`es`/`en`) traía el glyph `✓` **embebido en el
string de i18n** (`"✓ Aprobado por {{nombre}}"` / `"✓ Approved by {{nombre}}"`), pese a que los 2
puntos donde se usa (`HistorialArqueosPanel`, fila de la tabla; `DetalleArqueoModal`, estado ya
aprobado) ya renderizan el ícono real por separado (`<IcoCheck size={10|13} />`, SVG propio del
proyecto, no una librería externa). Resultado visible: el badge/párrafo mostraba el checkmark
duplicado — el SVG de `IcoCheck` **y** el carácter `✓` de la traducción, uno al lado del otro.

Esto es exactamente el antipatrón que la regla de iconografía del proyecto prohíbe de forma
explícita y con ese mismo ejemplo literal: *"Los strings de i18n nunca llevan el glyph embebido
(ej. "✓ Done") — el icono se renderiza aparte del texto en el componente, para que no vuelva a
colarse vía traducción."* (`Illumination/atlanticerp/CLAUDE.md`, sección Iconografía). No es un emoji de
color (🚀🔥✨), pero la regla del cliente cubre este patrón explícitamente, con este mismo ejemplo.

**Evidencia:** `docs/visual-review/screenshots/batch19-mark-aprobado.png` (post-fix, un solo
checkmark — el SVG).

**Fix aplicado** (`atlanticerp-frontend`, commit local sin push):
- `src/i18n/locales/es/adminContab.json` → `"aprobadoPor": "Aprobado por {{nombre}}"`.
- `src/i18n/locales/en/adminContab.json` → `"aprobadoPor": "Approved by {{nombre}}"`.
- Sin cambios de código en `ArqueoCajaPage.tsx` — el `<IcoCheck />` ya estaba correctamente
  separado del texto, solo la traducción tenía el glyph de más.

**Verificación del fix:**
- `npx vitest run ArqueoCajaPage` → 18/18 OK (sin dependencia del string viejo).
- `npx tsc --noEmit` → limpio.
- Recheck en vivo del escenario 11 (Mark aprueba) — el badge/párrafo ahora muestra un solo
  checkmark (el SVG `IcoCheck`), sin duplicado. Test de iconografía de Playwright, que había
  fallado por este mismo motivo antes del fix, pasa limpio después.
- Se escaneó el resto del subárbol `arqueoCaja` de ambos locales por el mismo patrón (regex sobre
  todo el JSON) — sin otras ocurrencias.

## Confirmación del diseño (ADR punto 9 — gate de export)

Se verificó en vivo que el gate asimétrico documentado en el ADR es real y funciona como está
descrito, no es un bug: `GET /cash-position/daily-count/export` (arqueo activo) usa el gate
angosto de REQ-524 (Felix+Yaneth), y `GET /cash-position/history/{id}/export` (historial) usa el
gate amplio (+Mark/Gerencia) — confirmado indirectamente porque Mark nunca ve el arqueo activo en
absoluto (REQ-524 lo excluye de la pantalla completa, no solo del botón), y sí pudo descargar el
resumen desde el historial (test 13). Sigue marcado en el ADR como "pendiente de confirmar con
Luis" — no se tocó, es una decisión de diseño explícita, no un hallazgo.

## Checklist funcional del mockup (alcance REQ-525→529)

- **REQ-525:** sección "Ajustes / Retención" (tabla Cliente/Motivo/Monto/Constancia/acción),
  condicional a que exista al menos un cobro con retención ese día; botón "Subir
  constancia"/"Reemplazar"; input de archivo (jpg/png/pdf, límite de tamaño validado
  server-side).
- **REQ-526:** botón "Cerrar arqueo del día"; modal con fecha + total neto + aviso condicional de
  retención pendiente; confirmar pasa a "pendiente_aprobacion".
- **REQ-527:** banner de atrasado con ambas fechas cuando el arqueo activo no es hoy; avance
  automático tras cerrar.
- **REQ-528:** panel "Historial de arqueos cerrados" (N°/Fecha/Total neto/Aprobación/Realizado
  por/Ver); badge de contador de pendientes; botón "Aprobar" exclusivo de Mark dentro del detalle.
- **REQ-529:** modal de detalle de solo lectura (totales, observación general, retenciones, estado
  de aprobación); botón "Descargar resumen" tanto en el arqueo activo como en el detalle del
  historial.

Todos presentes y funcionales — ninguna variante de layout relevante (el frontend usa
tarjetas/badges Tailwind en vez de la tabla HTML cruda del mockup, mismo criterio ya aceptado en
Batch 18, sin pérdida de funcionalidad ni de campos).

## Archivos tocados en esta sesión

- `atlanticerp-frontend/src/i18n/locales/es/adminContab.json` — fix (glyph embebido).
- `atlanticerp-frontend/src/i18n/locales/en/adminContab.json` — fix (glyph embebido).
- `atlanticerp-frontend/e2e/_preqa_batch19_arqueo_parte2.spec.ts` — spec adversarial nuevo (prefijo `_`,
  no destinado a quedar como test permanente — a diferencia de los `preqa-batch*.spec.ts` ya
  promovidos del proyecto, este corrió contra datos sembrados a mano en local, no autosembrados,
  así que no es re-ejecutable sin preparación previa de la BD; se deja en el repo como evidencia
  del escenario cubierto, mismo criterio que los specs `_preqa796_*` ya presentes).
- `atlanticerp-backend/docs/visual-review/screenshots/batch19-*.png` — evidencia (10 capturas).
- `atlanticerp-backend/docs/pre-qa/batch19-arqueo-caja-2026-08-26.md` — este reporte.

## Siguiente paso

Sin bloqueantes. Los 5 tickets (SCRUM-602→606) están en condición de pasar de `Dev Testing` a
`QA` — falta transicionarlos en Jira (fuera del alcance de este gate) y, dado que el batch no está
desplegado a `dev.atlanticerp.ai` todavía, coordinar el deploy antes de que QA formal (marly) pueda
probarlo en el entorno real.
