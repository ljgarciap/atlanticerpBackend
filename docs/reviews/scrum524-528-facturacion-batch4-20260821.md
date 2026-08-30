# Senior Review — Admin&Cont, Facturación Batch 4 (SCRUM-524→528, REQ-447→451)

**Fecha:** 2026-08-21
**Resultado: APROBADO tras 3 fixes propios (contrato + gap real de RN1), mismo pase, checklist re-corrido.**

Último batch de Facturación (ver `docs/architecture/admin-contab-batches.md`). Backend Dev y
Frontend Dev implementaron en paralelo (forks) contra un contrato de API fijado por el Arquitecto
antes del despacho, con 2 dependencias hacia adelante resueltas como infraestructura fundacional
sin escritor real (mismo criterio que el saldo a favor de Batch 3): el estado "Anulada"
(`anulada_at`, hoy sin ninguna factura real llegando ahí — origina en Notas de Crédito, batch
futuro) y el botón "Registrar cobro" (`puede_registrar_cobro`, navega a un placeholder de Cobros
que Batch 5+ reemplaza).

## Gate real (suite completa, tras los 3 fixes)

- Backend: PHPStan Level 8 → `[OK] No errors`. `infra/test.sh` completo → **2457/2457 tests, 9884
  assertions, OK** (incluye 82 tests de `AdminCont`, 2 agregados en este review).
- Frontend: `npm run test -- --run` completo → **116 archivos, 1357/1357 tests, OK** (incluye 29
  en `FacturacionPage.test.tsx`, 1 agregado en este review). `tsc --noEmit` → sin errores.
- `RolesPage.test.tsx` falló una vez bajo suite completa, sin relación con este batch — confirmado
  flaky (pasa 4/4 en aislamiento), no es una regresión real.

## Hallazgos encontrados y corregidos en este mismo review (antes de Pre-QA)

1. **Contrato de API incompleto** (detectado porque Frontend Dev, sin coordinación en vivo,
   documentó explícitamente el desvío en su reporte): `detail()` no exponía `master_client_id`
   (necesario para preseleccionar cliente en REQ-451 RN3) ni `puede_decidir_incobrable` (gate de
   Mark para Aprobar/Rechazar, REQ-448). Agregado en el controller (dependiente del actor vía JWT
   vs. `AdminContFiscalSettings::mark_approver_user_id`, no en el service) + test dedicado
   verificando que Felix nunca lo ve en `true` aunque haya una propuesta pendiente, y Mark sí.
2. **RN1 de REQ-449 incompleta** (encontrado leyendo el diff, no reportado por ninguno de los 2
   forks): el documento fiscal solo mostraba "Pago a", faltaban "Cuenta número" y "Responsable" —
   ambos explícitos en el AC del ticket. Agregado `pagoBlock()`: cuenta = primera cuenta bancaria
   activa (banco + últimos 4 dígitos, nunca el número completo — RN4 de REQ-530 sigue intacta),
   responsable = quien generó la factura (`created_by`), mismo patrón `trim("{$u->first_name}
   {$u->last_name}")` ya usado en Compras. Corregido en backend Y frontend (el on-screen preview
   del modal de detalle también lo mostraba incompleto, ya que las ACs de REQ-449 hablan tanto de
   "vista previa" como de "documento").
3. **Verificación manual de la aritmética de antigüedad (REQ-450):** el test existente de Backend
   Dev solo probaba la exclusión por incobrable, nunca una factura vencida y AÚN cobrable cayendo
   en el rango correcto — verifiqué a mano con `tinker` que `Carbon::diffInDays($today, false)` da
   el signo correcto (positivo = vencida) antes de aprobar, y agregué un test que lo cubre
   explícitamente (factura vencida hace 45 días → rango 31-60, con monto verificado).

## Arquitectura — verificado en código

- **REQ-447 trazabilidad:** Cotización→Factura→Guía de Entrega en el orden correcto; Guía de
  Entrega NUNCA completa sin factura, defendido tanto en backend (`detail()` solo busca el
  `OrderDocument` cuando `$invoice !== null`) como en frontend (doble defensa), con test que siembra
  a propósito un documento "fuera de orden" antes de la factura.
- **REQ-448 cobrabilidad:** `proposeUncollectible()`/`decideUncollectible()` con `lockForUpdate()`
  dentro de transacción — correcto contra condiciones de carrera. RN5 (no proponer 2 veces) y RN1
  (solo sobre facturada vigente) cubiertos. `mark_only` reutilizado tal cual para la decisión, sin
  gate nuevo.
- **REQ-449 documento fiscal:** primer PDF real de Admin&Cont — marca de agua con `position:fixed`
  a página completa, evitando a propósito el gotcha ya documentado de `position:absolute` +
  `min-height` que desbordó 2 veces antes en otras plantillas de este proyecto. Logo vía
  `PdfLogo::dataUri()` (dompdf no renderiza `<svg>` inline) — ambos gotchas conocidos evitados
  correctamente sin que nadie se lo recordara.
- **REQ-450 antigüedad:** `dias_credito_factura` en `AdminContFiscalSettings` en vez de hardcodear
  el plazo de crédito (regla dura del proyecto sobre umbrales de negocio) — los 4 rangos en sí
  (0-30/31-60/61-90/+90) se trataron como convención de reporte fija, no como umbral ajustable,
  criterio razonable dado que es la nomenclatura estándar de antigüedad de cartera.
- **REQ-451:** solo expone `puede_registrar_cobro` (lógica real, ya verificada); la navegación es
  responsabilidad de Frontend contra un placeholder nuevo (`CobrosPlaceholderPage.tsx`, mismo
  patrón que `EstadoCuentaPlaceholderPage.tsx` de Batch 2) — no hay ruta rota ni 404 mientras Cobros
  no exista.

## Re-check tras hallazgo de Pre-QA (mismo día)

Pre-QA encontró 1 MAJOR real: `InvoiceService::list()` (la proyección de fila de la tabla
principal) nunca consideraba `incobrable_pendiente` ni `anulada_at` al calcular `estado` — la
tabla mostraba "Facturada" para una propuesta de incobrable pendiente, violando RN3 de REQ-448
("visible en la fila con una pastilla de estado distinta"); `detail()` (el modal) ya lo exponía
bien, el frontend ya tenía la pastilla lista (`pillFor()`), solo faltaba el campo del lado del
backend. Corregido (commit `625a48e`) + 2 tests que cierran el gap exacto que encontró Pre-QA (fila
de tabla con `incobrable_pendiente=true` y con `estado='anulada'`). De paso, se encontró que el fix
del bloque de pago (`cuenta_pago`/`responsable` en el PDF) había quedado sin commitear pese a estar
en disco — commiteado por separado (`e91c09a`) antes de que se perdiera al pushear.

Gate real re-corrido tras estos 2 fixes: backend 2457/2457 + PHPStan limpio; frontend 116/116
(1357/1357) + tsc limpio.

## Veredicto y despliegue

**Pasada limpia tras 5 fixes propios en total (2 de contrato, 1 de RN1 real, 1 MAJOR de Pre-QA, 1
de higiene de commit), checklist completo re-corrido después de cada uno.** Los 5 tickets
(SCRUM-524→528) pasan a Pre-QA + Visual Review (re-check completo, no solo el hallazgo puntual).
Commits locales en `dev`, sin pushear: backend `9d8ae74`+`52fe7fc`+`625a48e`+`e91c09a`, frontend
`3a39ebd`+`137a104`.
