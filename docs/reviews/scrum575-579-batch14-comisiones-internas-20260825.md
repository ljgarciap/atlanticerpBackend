# Senior Review — Batch 14 Comisiones Internas (SCRUM-575→579, REQ-498→502)

**Fecha:** 2026-08-25. **Reviewer:** Arquitecto (mismo hilo, no subagente — reconciliación de 2
forks paralelos, Backend Dev + Frontend Dev, sobre `docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md`).

## Resumen

Ambos devs entregaron implementaciones completas y bien testeadas de forma independiente sobre el
mismo ADR. La reconciliación encontró 3 categorías de hallazgos reales, todos corregidos antes de
pushear — ninguno por no seguir el diseño, todos por el gap esperable de paralelizar sobre un ADR
todavía no verificado en código real (mismo patrón que Batch 13, ver
`docs/reviews/scrum571-574-batch13-aprobacion-mark-20260824.md`).

## Hallazgos

### 1. CRÍTICO — fuga de permisos: el grant a vendedores desbloqueaba TODO Admin&Cont

La migración `grant_admin_contab_readonly_view_to_vendedor_disenador` usaba
`can_view=VIEW_FULL(2)` en `role_module_visibility`. `User::effectivePermissionsV2()` bridgea
`VIEW_FULL` a `{module}.read` sin distinción de pantalla — eso le daba a los 10 vendedores
`admin_contab.read` completo: Facturación, Cobros, Notas Crédito, Estado de Cuenta, Cuentas
Bancarias, no solo Comisiones Internas. Confirmado por 2 tests preexistentes que empezaron a
fallar (`AdminContAccountStatementControllerTest`/`AdminContBankAccountControllerTest`,
`test_*_rechaza_a_un_rol_sin_visibilidad_del_modulo`).

Mismo bug, mismo fix, que SCRUM-771 ya encontró y corrigió para Compras
(`compras.limited.view`, 2026-08-18) — replicado exacto:
- `VIEW_READONLY(1)` en vez de `VIEW_FULL` — deja el módulo navegable sin `.read`.
- Permiso angosto nuevo `admin_contab.commissions_internal.view` en `permission_catalog`
  (migración `add_admin_contab_commissions_internal_view_permission`), otorgado por persona a los
  10 vendedores reales vía `SpecialPermissionSeeder` (mismo criterio que `compras.limited.view` →
  `operaciones@atlantic.com.pa`).
- Rutas `/tiers` y `/` (summary) con OR (`permission:admin_contab.read,admin_contab.commissions_internal.view`);
  `/export` y `/vendors` quedan estrictos en `admin_contab.read` (Felix/Mark/Gerencia únicamente).

3 tests preexistentes actualizados para reflejar el nuevo estado real (no bugs, expectativas
desactualizadas): `PermissionCatalogTest` (10→11 módulos, 29→30 permisos), `RoleControllerTest`,
`RolesSeedTest` (2→3 filas de `role_module_visibility` para `vendedor_disenador`).

### 2. Gap de contrato — Backend/Frontend no coincidían en la forma del JSON

Backend anidaba `tarjetas`/`banner` y usaba nombres distintos por campo
(`vendedor`/`total_pedidos`/`fijo`/`pendiente`/`pedidos_mes`) que el Frontend, ya construido sobre
`types/adminContab.ts` (`vendedor_nombre`/`total_pedidos_mes`/`porcentaje_fijo`/`pendiente_cobro`/
`groups[]`). Reconciliado aplanando y renombrando el lado backend (el motor de cálculo ya estaba
correcto y testeado — se tocó solo la serialización):
- `buildSummary()` — campos planos, agregado `vendedores_con_pedidos_mes`.
- `summaryForVendor()` — nombres alineados, `pedidos_mes` envuelto en `groups: [{mes, porcentaje,
  porcentaje_fijo, arrastrado: false, pedidos}]` (un solo grupo — Batch 14 no agrupa arrastrados
  por mes en la UI, REQ-505 es Batch 15).
- Cada pedido enriquecido a la forma `CommissionOrder` completa (`fecha_pedido`, `fecha_factura`,
  `fecha_cobro_completo` — este último un proxy razonable, el cobro que llevó a 100% de esa
  factura, no trackeado como evento propio en ningún lado; `es_abono_parcial`, `monto_comision`,
  `es_estimado`) — antes solo traía las columnas crudas del join.
- Endpoint nuevo `GET .../vendors` (Felix/Mark/Gerencia) — el frontend ya lo esperaba para el
  selector de vendedor, no existía en el backend original.
- `export`: el backend leía `formato`, el frontend manda `format` — nunca matcheaba, exportaba
  PDF siempre sin importar el formato elegido.
- **`CommissionExportService::toExcel()` y la vista `commissions-internal-pdf.blade.php` seguían
  con los nombres de campo viejos tras el reshape — se corrigieron en el mismo pase. Sin
  cobertura propia de test para el camino feliz de exportar (solo el 403 del vendedor) — agregada
  (`test_export_pdf_exitoso_para_felix`/`test_export_excel_exitoso_para_felix`), habría llegado a
  Pre-QA como un 500 en el primer export real.**

### 3. Menor — 2 bugs de PHPStan Level 8 y typing

- Migración `commission_month_snapshots`: constraint unique sin nombre explícito, el autogenerado
  (prefijado con el schema completo del tenant) superaba los 63 bytes de Postgres y truncaba
  colisionando con otra constraint del mismo schema — mismo gotcha ya documentado en el checklist
  de pre-flight del CLAUDE.md del proyecto. Nombrada explícitamente.
- `pendingAutoPayBanner()` — ya corregido por Backend Dev antes de commitear (bug real que
  encontraron ellos mismos revisando a mano, sin poder correr el suite desde su worktree, ver
  nota de limitación abajo).
- 6 errores de PHPStan (nullable de `createFromFormat()`, tipos genéricos de `Collection` sin
  especificar, nullsafe innecesario) — todos corregidos, `--memory-limit=1G` limpio.

## Limitación operativa confirmada

Backend Dev reportó no poder correr `infra/test.sh` ni PHPStan desde su worktree — el volumen de
Docker (`../:/var/www/html`) monta el checkout principal, no el worktree aislado, y el worktree no
tiene `vendor/` instalado. Confirmado real: el merge a este checkout fue necesario para correr el
gate real por primera vez. Documentado para el próximo batch con forks paralelos — el gate real
(suite completa + PHPStan) solo puede correr después de mergear al checkout principal, nunca desde
dentro de un worktree de un dispatch.

## Gate final

Backend: PHPStan Level 8 limpio, suite completa de `infra/test.sh` sin fallos (incluye los 3 tests
preexistentes reconciliados). Frontend: `tsc --noEmit` limpio, 1504/1504 tests vitest (sin cambios
necesarios del lado frontend — toda la reconciliación de contrato se hizo en el backend).

**Siguiente paso:** push a `dev` con aprobación explícita, deploy manual, Pre-QA/Visual Review
fusionado, comentario+transición de los 5 tickets a QA.
