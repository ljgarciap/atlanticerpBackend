# Senior Review — Admin&Cont, Cuentas Bancarias (SCRUM-607→611, REQ-530→534)

**Fecha:** 2026-08-19
**Resultado: APROBADO. Sin hallazgos.**

Batch 1 del cuerpo principal de la épica Admin&Cont (ver `docs/architecture/admin-contab-batches.md`)
— primera pantalla que usa el sistema estándar de roles del proyecto en vez del gate exclusivo de
Aprobador de Configuración Fiscal/Datos de la Empresa.

## Gate real (suite completa)

- Backend: PHPStan Level 8 → `[OK] No errors`. `infra/test.sh` → **2376/2376 tests, 9552
  assertions, OK**.
- Frontend: `npx vitest run` → **115 archivos, 1305/1305 tests, OK**.

## Arquitectura — verificado en código

- Gates `role:` correctos en `routes/admin-contab.php`: general
  (`superadmin,lider_admin_contab,asistente_administrativa,management`) vs. el más angosto de
  REQ-534 (sin `management` — "Disponible para Contabilidad y Asistente" únicamente, textual del ticket).
- RN4 de REQ-530 (nunca capturar número de cuenta completo): confirmado, no existe ningún
  campo/columna para eso en ningún lado del código.
- RN2 de REQ-534 (solo cuentas activas asignables): reforzado a nivel de validación server-side
  (`AssignBankMovementAccountRequest.php:30`, `Rule::exists('bank_accounts','id')->where('activa', true)`)
  — más robusto que depender de que el frontend filtre bien el selector.
- Visibilidad del módulo (`role_module_visibility`) ya sembrada por ADR-006 para
  gerencia/lider_admin_contab/asistente_administrativa — no hizo falta seeding nuevo.
- `admin_contab.view` (usado por el frontend como gate de entrada) vs `admin_contab.read` (usado
  por el middleware del backend): confirmado en `User.php` que `{modulo}.view` emite también
  `{modulo}.read` en el bridge de compatibilidad — la elección del Frontend Dev es correcta.
- "Eliminadas" modelada como lista separada de cuentas (no filtro de la tabla de movimientos):
  cubre RN1 de REQ-532 vía el modal de detalle con `movimientos_count`, sin pérdida de
  funcionalidad.

## Limitación conocida y aceptada (no es un hallazgo)

El panel de Movimientos depende de datos que Cobros/Notas Crédito/Comisiones Internas/Externas
generarán — ninguno de esos módulos existe todavía (son batches futuros del mismo plan). La tabla
`admin_contab_bank_movements` es el catálogo fundacional que esos módulos alimentarán vía Eloquent
directo cuando se implementen. Hoy legítimamente está vacía en cualquier ambiente real, verificado
con datos de fixture en tests.

## Veredicto

Listo para Visual Review (mockup adjunto en SCRUM-607: `4H__Admin_Contabilidad_CuentasBancarias.html`)
y Pre-QA. Ver `docs/pre-qa/scrum607-611-cuentas-bancarias-20260819.md` para el resultado de ese
gate y el estado final de despliegue.
