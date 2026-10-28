# Senior Review — Admin&Cont, Estado de Cuenta Batch 9 (SCRUM-534→538, REQ-457→461)

**Fecha:** 2026-08-23
**Resultado: APROBADO. Decisión de esquema (Responsable) escalada a Luis antes de implementar (ver
abajo), sin hallazgos bloqueantes en la implementación resultante.**

Batch 9 de Admin&Cont, cierre de "Estado de Cuenta" (ver `docs/architecture/admin-contab-batches.md`)
— tarjeta de saldo (REQ-457), bloque de datos de pago (REQ-458), nota automática de contexto
(REQ-459), tabla de movimientos (REQ-460) y apertura automática desde Facturación (REQ-461). Batch 8
(ya cerrado, `docs/reviews/scrum529-533-estadocuenta-batch8-20260823.md`) dejó el shell (buscador +
encabezado) sin ninguno de estos datos — este batch completa el resto del mockup.

## Gate real (suite completa)

- Backend: PHPStan Level 8 (app completa) → **7 errores, los mismos 7 preexistentes de
  `App\Modules\Gerencia\Services\GerenciaHomeService.php`** (ver "Nota de entorno" abajo) — ninguno
  en código tocado por este batch, confirmado grepeando el output completo por `AdminCont`.
  `infra/test.sh` completo → **2530/2530 tests, 10228 assertions, OK** (27 tests en
  `AdminContAccountStatementControllerTest`, ~20 nuevos de este batch sobre los 7 de Batch 8).
- Frontend: `npx vitest run` completo → **124 archivos, 1432/1432 tests, OK** (21 tests en
  `EstadoCuentaPage.test.tsx`, 11 nuevos de este batch sobre los 10 de Batch 8). `tsc --noEmit` →
  sin errores.
- Verificación visual en vivo con **Playwright CLI** contra `localhost:5173` (regla dura del
  proyecto) — 6 escenarios corridos, los 6 verdes. Ver Pre-QA/Visual Review para el detalle.

## Nota de entorno — PHPStan preexistente en Gerencia, fuera de alcance de este batch

Los 7 errores de PHPStan en `GerenciaHomeService.php` (nullsafe innecesario + shape de retorno de
`pendingApprovalsSection()`) existen desde antes de este batch, pertenecen a otro equipo
(módulo Gerencia), y son la causa ya conocida de que el deploy de Batch 8 esté en espera (ver
memoria del proyecto). No se tocan acá — corregirlos es responsabilidad del equipo dueño de ese
módulo. `infra/deploy-manual.sh` corre PHPStan sobre la app completa antes de desplegar, así que
Batch 9 queda en la misma cola de espera que Batch 8 hasta que ese fix llegue.

## Decisión de esquema escalada a Luis — "Responsable" (REQ-458 RN3)

REQ-458 RN3 exige que el bloque de datos de pago (cuenta bancaria + responsable de administración)
sea **idéntico entre Facturación y Estado de Cuenta** — un dato a nivel EMPRESA, no por factura ni
por cliente. La implementación existente de Facturación (`InvoiceService::pagoBlock()`) usaba
`created_by` de la factura individual, lo que rompe la consistencia exigida en cuanto dos facturas
distintas tienen `created_by` distinto (o cuando se agregan a nivel de Estado de Cuenta, que no
tiene "una factura" de la cual leer `created_by`). Se presentaron 2 opciones a Luis (campo nuevo en
`fiscal_settings` / mecanismo alternativo) — eligió **campo nuevo en `fiscal_settings`**
(`responsable_administracion_user_id`, nullable), mismo patrón singleton ya usado para
`nombre_comercial`/`regimen_tributario`.

`InvoiceService::paymentBlock()` (extraído como método estático, reusado tal cual por
`AccountStatementService::detail()`) es ahora la única fuente de este bloque — corrige de paso una
inconsistencia latente que ya existía en la Facturación ya desplegada (el PDF fiscal real mostraba
un "responsable" distinto por factura, nunca revisado hasta ahora porque ningún ticket anterior
comparaba explícitamente 2 pantallas del mismo dato). Test existente de Facturación actualizado
(`test_detail_muestra_trazabilidad_completa_con_guia_de_entrega`) para reflejar el nuevo
comportamiento — antes esperaba `'Contabilidad'` (el creador de la factura de prueba), ahora `null` porque
la factura de prueba no setea `responsable_administracion_user_id` en `fiscal_settings`.

## Diseño de `AccountStatementService::detail()` — un solo cómputo server-side

`detail()` reemplaza `generate()` como método del controller (`generate()` queda solo para uso
interno de `detail()`, ya no expuesto). Construye:

- `movements()` (privado) — un único array cronológico de filas débito (facturas abiertas, activas)
  + crédito (pagos confirmados aplicados), con **saldo corrido calculado una sola vez** sobre el
  conjunto completo que aplica al filtro (`master_client_id` + opcionalmente `sales_project_id` +
  `desde`/`hasta`). La vista "Agrupado por proyecto" del frontend es una re-agrupación puramente de
  presentación sobre este mismo array — cada fila conserva su `saldo` ya calculado (el saldo
  corrido es siempre GLOBAL respecto al filtro activo, no recalculado por sub-grupo visual;
  confirmado en vivo, ver Pre-QA).
- `contextNote()` (privado) — REQ-459 RN2, prioridad fija: factura vencida >90 días sin ningún abono
  → mensaje de vencida; `saldo < 0` → saldo a favor; Notas de Crédito (nunca true todavía, el módulo
  no existe); `saldo === 0` → cuenta completada; si nada aplica, `null`. Usa
  `fiscal_settings.dias_credito_factura`, misma fórmula de vencimiento que
  `InvoiceService::agingBuckets()` — no una constante nueva.
- `proyectos_count` — solo se calcula cuando `sales_project_id === null` (RN3, "N proyectos" en el
  frontend combina esto con la nota de `contextNote()` cuando aplica).

PHPStan detectó y forzó corregir 2 errores reales durante el desarrollo (no llegaron a este Review):
acceso a `->created_at` en un `AdminContPayment|null` sin narrow previo, y un `array_values()`
redundante sobre un resultado ya-lista tras `usort()`.

## `AccountStatementExportService::toExcel()` — extendido, no reescrito

Recibe ahora el array completo de `detail()`. Si trae `saldo`, agrega filas de
Saldo/Pago a/Cuenta número/Responsable al bloque de encabezado ya existente de Batch 8; si
`movimientos` no está vacío, agrega una segunda tabla (Fecha/Proyecto/N° Factura/Concepto/
N° Cotización/Guía de Entrega/Débito/Crédito/Saldo) 2 filas debajo. Mismas columnas visibles en
pantalla (RN2 de REQ-456, ya validado en Batch 8) — extensión, no una tabla nueva con criterio
distinto.

## Frontend — REQ-461 apertura automática (RN1/RN2)

`useSearchParams` + `useEffect` al montar: si `master_client_id` está en la URL, dispara
`generateMutation.mutate` automáticamente y, si tiene éxito, precompleta el buscador
(`setClientQuery(data.cliente)`); si falla (cliente inexistente/sin acceso), falla en silencio (RN2
— nunca un error visible al abrir desde un link, vuelve al estado vacío normal) y limpia el query
param (`setSearchParams({}, { replace: true })`) para que un refresh de la página no reintente.
`FacturacionPage.tsx::openEstadoCuenta` (ya existente desde Batch 2) no necesitó ningún cambio — ya
mandaba el parámetro, solo faltaba que esta pantalla lo leyera.

## Hallazgo propio, corregido antes de este Review

Al escribir la tabla de movimientos en pantalla omití inicialmente la columna "Guía de Entrega" (sí
presente en el mockup y en el Excel) — detectado en mi propio desarrollo, no en Pre-QA, agregado a
`MovementsHead`/`MovementRow` antes de correr el gate completo.
