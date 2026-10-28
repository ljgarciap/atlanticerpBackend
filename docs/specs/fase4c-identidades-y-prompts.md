# Spec — Fase 4c: identidades y prompts genéricos

**Estado**: decisiones de fondo ya aprobadas por Luis (spec Fase 4, §7: renombrar todas las identidades; prompts sin sector). «Procede con 4c» = 2026-10-27.
**Origen**: spec `fase4-dataset-demo-generico.md` §3.4 y §3.5; pendientes del contrato de neutralidad (`TerminosDelClienteAnterior::ARCHIVOS_PENDIENTES_4C`).

## 1. Hechos verificados (medidos hoy)
- 5 prompts de IA fijan el sector («iluminación», «diseño de iluminación (Panamá)»): `SecurityAnalysisJob`, `SummarizeProjectJob`, `DraftMessageJob`, `MonthlyAnalysisJob`, `SuggestActionsJob`.
- La plantilla `commission-account-statement-pdf.blade.php` trae en el encabezado y el pie la razón social «Atlantic Diseño e Iluminación, S.A.», ciudad y dominio fijos.
- Identidades demo con nombre derivado de roles ya renombrados o de fixtures sin rol real. Archivos que las citan (backend / frontend): `vendedordisenador` 28/43, `tecnicoservicios` 24/26,
  `garantiasservicios` 11/6, `ayudantegeneralbodega` 12/5, `designer@atlantic` 184/23, `supervisor@` 21/3, `electrician@` 13/1, `readonly@` 7/3, `multi@atlantic` 16/2.
- Ya existe «Datos de la Empresa» editable (`AdminContCompanyProfile.descripcion_corta`, 500 caracteres, por tenant, con pantalla y auditoría). `GlobalSettingsRegistry` solo admite enteros.

## 2. Diseño
1. **Prompts**: la descripción del negocio sale del texto fijo y se lee de `company_profile.descripcion_corta` del tenant (**cambio respecto a la spec de la Fase 4**, que proponía un ajuste
   nuevo `business_description`: el campo ya existe, es editable y evita extender el registro de ajustes a texto). Vacío → «una empresa». Helper `App\Modules\AI\Support\BusinessProfile::description()`.
   El texto se envía como parte del prompt de **sistema** (de confianza, escrito por superadmin) y se acota a 500 caracteres. Los jobs resuelven el tenant dentro de `BaseAiJob::run()`, así que el
   prompt usa el marcador `{negocio}` que `run()` sustituye después de `makeCurrent()`. `SecurityAnalysisJob` ya tiene el tenant activo y llama al helper.
2. **PDF de estado de cuenta**: el encabezado muestra solo la marca del producto («AtlanticERP») y el pie «Documento generado por AtlanticERP»; fuera razón social, ciudad y dominio.
3. **Identidades** (reemplazo exacto y mecánico, backend y frontend, tests, seeders, e2e y fixture):
   `vendedordisenador[N] → vendedor[N]`, `tecnicoservicios[N] → tecnico[N]`, `garantiasservicios → tecnicogarantias`, `ayudantegeneralbodega[N] → auxiliarbodega[N]`
   (nombres visibles «Vendedor Test N», etc.); `designer@atlantic.test → vendedor@atlantic.test`; `supervisor@ → nivel5@`, `electrician@ → nivel2@`, `readonly@ → nivel1@`, `multi@ → nivel8@`.
   Se verifica antes que no haya colisión con cuentas existentes (`vendedor@`, `nivel*@`). El fixture `identity-permissions.json` se regenera.
4. **Contrato**: se vacía `ARCHIVOS_PENDIENTES_4C` (el contrato falla si un archivo de la lista ya no tiene el término). Se añaden a la lista de prohibidos los correos viejos para que no vuelvan.

## 3. Fuera de alcance
Categorías de producto y almacenes (fases nuevas aún sin confirmar), moneda fija `$` en el PDF (Fase 6 ya trató moneda por región; se anota si el PDF no la usa), dominios `@test.com`/`@atlantic.test` (se conservan).

## 4. Criterios de aceptación
1. Ningún prompt de IA menciona un sector; con `descripcion_corta` vacío dicen «una empresa», con valor lo incluyen.
2. El PDF no contiene razón social, ciudad ni dominio fijos.
3. Los correos viejos no aparecen en ningún archivo versionado (salvo migraciones históricas y docs de specs/informes fechados).
4. PHPStan L8 limpio, `infra/test.sh` completo verde, frontend `tsc` + vitest verdes, test de equivalencia de la Fase 1b verde.
5. `ARCHIVOS_PENDIENTES_4C` vacío.

## 5. Riesgos
- Renombre masivo (~190 archivos de test): mitigado con reemplazo exacto y suites completas, como en las Fases 3 y 4b.
- Un valor de `descripcion_corta` malicioso entra al prompt de sistema: solo lo edita superadmin (permiso ya existente) y se acota a 500 caracteres.
