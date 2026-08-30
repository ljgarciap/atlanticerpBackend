# Senior Review — Admin&Cont, Notas Crédito y Devoluciones Batch 10 (SCRUM-553→558, REQ-476→481)

**Fecha:** 2026-08-24
**Resultado: APROBADO tras reconciliar 2 mismatches reales de contrato Backend↔Frontend, gate completo re-corrido después.**

Batch 10 abre el grupo más grande de la épica Admin&Cont (22 tickets, ver
`docs/architecture/admin-contab-batches.md`) — Notas Crédito y Devoluciones. Encabezado + botón
"+ Nueva nota" (REQ-476), 5 tarjetas de indicadores del mes (REQ-477), formulario dinámico
Cliente+Tipo de nota (REQ-478), subtipo de Anulación completa + pregunta de regreso de mercancía a
Bodega (REQ-479), bloque de Corrección de datos con tratamiento ITBMS actual/correcto y fecha
bloqueada a hoy (REQ-480), y componente de solo lectura de Devolución de mercancía listo para
recibir datos precargados por props (REQ-481 — sin entry point real todavía, esa cola de Bodega es
REQ-491/Batch 12). Sin submit real: factura de origen, excedente, monto/desglose ITBMS del
documento, comprobante y el POST en sí son Batch 11 (REQ-482→487) — el botón "Registrar nota" queda
deshabilitado a propósito, no es un hallazgo.

Implementado con Backend Dev y Frontend Dev despachados en paralelo (fork, cada uno en su propio
repo — sin conflicto de `isolation:worktree`, no comparten repo). El primer intento de Backend Dev
terminó en 11s sin ningún tool call real (0 cambios en el repo) — se detectó verificando `git
status` directo en vez de confiar en el reporte del fork, y se resumió con instrucción explícita de
implementar de verdad.

## Hallazgo de arquitectura, resuelto antes de despachar (decisión de Luis)

Ningún dato en el proyecto registraba qué tasa de ITBMS se aplicó a una factura —
`admin_contab.invoices` solo guardaba `monto` total, y `Quote::ITBMS_RATE` (Ventas&Diseño) era una
constante hardcodeada al 7%, nunca conectada a `admin_contab.itbms_rates` (Configuración Fiscal),
pese a que el Modelo de Datos del cliente la define como "fuente única de verdad" para Facturación,
Notas Crédito, Comisiones Externas y CxP de Compras. Luis eligió conectar todo al catálogo real
(en vez de un alcance mínimo con el 7% seguir hardcodeado) — ver `ItbmsRateResolver` y el diff de
`Quote::itbms()`. Deuda gemela documentada y **no tocada** (fuera de alcance): `GoodsReceiptController
::ITBMS_RATE_LOCAL` en Compras.

## 2 mismatches reales de contrato encontrados en Pre-QA (navegador real, no solo por lectura de código)

Backend y Frontend definieron el contrato de los 2 endpoints de lectura de forma independiente, sin
reconciliar antes de que cada fork terminara. Ambos se manifestaron como requests reales rotos en
el navegador (no solo una discrepancia de tipos):

1. **Path del resumen del mes:** frontend llamaba `GET /admin-contab/notas-credito/resumen-mes`
   (404 real) — backend expone `GET /admin-contab/notas-credito/summary` (consistente con
   `/invoices/summary` y `/payments/summary` del resto del módulo). Corregido en el frontend
   (`adminContabApi.ts`, commit `258660a`).
2. **Shape del resumen del mes:** backend devolvía `total_acreditado_mes`/`cantidad_notas_mes`/
   `nota_promedio_mes`; frontend esperaba `total_acreditado`/`numero_notas`/`nota_promedio`.
   Corregido en el backend (`CreditNoteService::summary()`, commit `98842b1`) — más barato que tocar
   el componente+test+mock del frontend en 4 lugares distintos.

El endpoint `GET /admin-contab/notas-credito/itbms-rates` (agregado durante este mismo Pre-QA — el
backend original solo tenía `GET /facturas/{order}/itbms-treatment`, que requiere un `order_id` que
el frontend no tiene todavía en este batch) sí coincidía en shape una vez creado
(`id`/`nombre`/`descripcion`/`porcentaje`/`es_base`, reusando `ItbmsRateResolver::alternativesTo(null)`).

## Gate real (suite completa, corrida 2 veces — antes y después de la reconciliación)

- Backend: PHPStan Level 8 → `[OK] No errors`. `infra/test.sh` completo → **2536/2536 tests, 10259
  assertions, OK** (incluye 6 tests nuevos de `AdminContCreditNoteControllerTest`).
- Frontend: `npm run test -- --run` completo → **126 archivos, 1443/1443 tests, OK** (incluye
  `RegistrarNotaCreditoModal.test.tsx`/`NotasCreditoPage.test.tsx` nuevos). `npx tsc --noEmit` →
  sin errores. `npm run lint`/ESLint sigue sin instalarse (gap preexistente, no de este batch).

Ver `docs/pre-qa/scrum553-558-notas-credito-batch10-20260824.md` para la verificación en navegador
real (Playwright CLI, local) contra los criterios de aceptación, incluidos los negativos.
