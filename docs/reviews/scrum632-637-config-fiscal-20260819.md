# Senior Review — Admin&Cont, Configuración Fiscal (SCRUM-632→637, REQ-555→560)

**Fecha:** 2026-08-19
**Entorno:** working tree local (atlanticerp-backend + atlanticerp-frontend) al momento de la revisión.
**Resultado: APROBADO.** Sin hallazgos CRÍTICOS ni MAYORES. 2 hallazgos MENORES, no bloqueantes
(ver detalle abajo — ambos coinciden con los 2 MAYOR que Visual Review/Pre-QA sí bloqueó, ver
`docs/pre-qa/scrum632-637-config-fiscal-20260819.md`).

Módulo nuevo: `App\Modules\AdminCont` — greenfield, primer batch de la épica Admin&Cont (SCRUM-331)
que llega a implementación real.

## Gate real (suite completa, no scoped)

- Backend: PHPStan Level 8 → `[OK] No errors`. PHPUnit → **2339/2339 tests, 9410 assertions, OK**
  (suite completa, incluye las 17 nuevas de AdminCont).
- Frontend: `npx vitest run` → **113 archivos, 1283/1283 tests, OK** (incluye las 8 nuevas de
  `FiscalConfigPage.test.tsx`).

## Arquitectura — verificado en código

- Schema `{tenant}_admin_contab` agregado a `Tenant::schemas()` y correctamente wireado en
  `tenant:sync-schemas` (corre antes de `tenants:artisan migrate` en `ci-backend.yml` y en
  `infra/deploy-manual.sh`) — el deploy crea el schema solo, sin paso manual.
- `EnsureIsMarkApprover` aplicado como middleware sobre las 6 rutas del grupo
  (`routes/admin-contab.php`), incluido el GET — cumple RN1 de REQ-555 (nadie más, ni Contabilidad ni
  Gerencia, ve la pantalla). Fail-closed si `mark_approver_user_id` es NULL.
- `AdminContMarkApproverBackfillSeeder` replica el criterio de `MarkApproverBackfillSeeder`
  (backfill-if-null, nunca pisa una reasignación manual de Luis).
- Mass-assignment correctamente cerrado: `pac_last_sync_at`, `pac_provider`,
  `pac_connection_status`, `mark_approver_user_id` nunca llegan a `update()` aunque el cliente los
  mande; `es_base` fuera de `$fillable`; `activa` fuera de `StoreItbmsRateRequest` (siempre nace
  `false`, RN4 de REQ-558).
- Frontend: 403 tratado como estado "acceso restringido" real, no error genérico. "Última
  sincronización" nunca registrado en el form — solo lectura directa de `settings`. Tasas base sin
  botón eliminar. Ambiente PAC con contraste fuerte (badge verde/rojo) en modo lectura (RN2 de
  REQ-557).

## Hallazgos menores (registrados, ya corregidos antes de este documento — ver Pre-QA)

1. `nombre` era obligatorio en `POST /itbms-rates` cuando RN3 de REQ-558 solo exige
   porcentaje+descripción — deviación del contrato de API definido por el Arquitecto al dispatchar
   el batch, no de los devs. Corregido (campo opcional, fallback `nombre ?? descripcion` en UI).
2. Sin feedback visual (toast) si falla `deleteRate` — cosmético, no bloquea ningún criterio de
   aceptación. Sigue pendiente, no bloqueante.

## Fuera de alcance a propósito (correcto, no es un hallazgo)

Integración real con Digifact/PAC (REQ-557 la excluye explícitamente: "no construida en este
mockup, requiere revisión técnica completa antes de implementarse") y cualquier consumidor real de
`retencion_proveedores_activa`/tasas de ITBMS en Compras (esos módulos no existen todavía).

## Veredicto

Listo para Visual Review (mockup adjunto en SCRUM-632: `4K__Admin_Contabilidad_Config_Fiscal.html`)
y Pre-QA antes de push a `dev`. Ver `docs/pre-qa/scrum632-637-config-fiscal-20260819.md` para el
resultado de ese gate (2 hallazgos MAYOR encontrados y corregidos en el mismo día) y el estado
final de despliegue.
