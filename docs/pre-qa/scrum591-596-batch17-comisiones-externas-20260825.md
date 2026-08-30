# Pre-QA + Visual Review — Batch 17 SCRUM-591→596 (Comisiones Externas, continuación) — 2026-08-25

Despacho consolidado (Visual Reviewer + Pre-QA fusionados en un solo pase, mismo criterio ya
formalizado 2026-08-15). Verificación en vivo contra `dev.atlanticerp.ai` recién desplegado — no local,
para probar el flujo real de extremo a extremo (backend + frontend + Postgres real). Usuarios
reales: `mbekhar@illuminations.com.pa` (Mark Approver real, contraseña real distinta del default
email) y `conta@illuminations.com.pa` (Felix, lider_admin_contab, password = email). Playwright CLI
(`atlanticerp-frontend/e2e/preqa-batch17-scrum591-596-20260825.spec.ts`, spec permanente).

## Resumen de veredicto

**LIMPIO tras 1 hallazgo real (CRÍTICO) encontrado y corregido en el momento** — mismo loop
completo de siempre (PM reasigna → mitigación → re-check completo antes de cerrar), sin diferir
nada. Los 7 REQ (514→519 + ajuste de puede_decidir_porcentaje) verificados en vivo, con capturas.

## Fixture necesaria antes de poder verificar

Ningún proyecto real en `dev.atlanticerp.ai` tenía cobro 100% al momento de arrancar esta verificación
(todos "Aún no generada") — Batch 16/17 solo pueden ejercitarse sobre un proyecto en
`pendiente_factura_arquitecto`. Se completó el cobro de `PED-2026-0019` (factura F-0001, saldo
$5,737.31, proyecto de "[PREQA-B12] Arquitecto"/"[PREQA-B12] Subcliente Correccion" — datos de
prueba de una sesión de Pre-QA anterior no relacionada, Batch 12 de Notas Crédito) vía el flujo
real de Cobros (`+ Registrar cobro` → cliente → factura → referencia → confirmar) **+ confirmación
manual** (Batch 7, `Marcar como confirmado` — un cobro sin confirmar no cuenta para el saldo de la
factura, gap real de mi primer intento de fixture, corregido sobre la marcha).

Nota aparte, no bloqueante: se encontró que `pipeline_card_id=215` (mismo arquitecto) aparece
**duplicado** en `arquitectos[].proyectos[]` del response de `summary()` — root cause no
investigada a fondo, pero la estructura del JOIN de `rawProjects()` no cambió en este batch
respecto a Batch 16, así que es preexistente (probablemente 2 `Order`/`Invoice` de la data de
prueba `[PREQA-B12]` de Notas Crédito). No afecta ningún REQ de este batch — documentado para una
futura limpieza de datos de prueba o investigación de causa raíz, no ticket abierto.

## Checklist funcional verificado en vivo

| REQ | Escenario | Resultado |
|---|---|---|
| REQ-508→512 | Proyecto pasa a "Pendiente de factura" tras cobro 100% | ✅ confirmado (`comisión generada`/`pendiente de factura` recalculados: $658.73 al 10% inicial) |
| REQ-519 | Modal de detalle: monto, cobro, estado, RUC, % comisión, comisión/impuesto/total, cuenta de pago, cuenta de cobro | ✅ todos presentes y con el valor correcto |
| REQ-514 RN1 | Comprobante de retención NO aparece con régimen `con_itbms` | ✅ confirmado (`getByText('Comprobante de retención')` → 0 matches) |
| REQ-516 RN3 | Felix propone 12% con motivo | ✅ persistido server-side (confirmado al reabrir el modal en un login posterior) |
| REQ-516 RN4 | Mark aprueba el % pendiente, **sin cerrar el modal** | ✅ — ver hallazgo CRÍTICO abajo |
| REQ-511 | Comisión/impuesto/total recalculados con el % aprobado (12%) | ✅ $790.48 / $55.33 / $845.81 (6,587.31 × 12% = 790.4772, redondeado) |
| REQ-517 RN1 | Cuenta de pago resuelta al default "Banco General — Cuenta Corriente" sin asignación explícita | ✅ |
| REQ-517 | Mark (`management`) ve la cuenta de pago como texto, no como `<select>` editable | ✅ (roster angosto de edición confirmado, Mark no está en él) |
| REQ-513/515 RN2 | "Marcar como pagado" deshabilitado sin cuenta de cobro | ✅ (ya confirmado también por 2 tests de backend con HTTP real) |
| REQ-513 | Felix sube cuenta de cobro, aparece en la fila Y en el modal sin recargar | ✅ |
| REQ-515 | "Marcar como pagado" habilitado tras subir cuenta de cobro → estado pasa a "Pagada" | ✅ |
| REQ-515 RN3 | Tras pagada: cuenta de pago pasa a texto estático, "Marcar pagado"/"Recordar" desaparecen | ✅ |
| REQ-516 RN4 (ajuste) | `puede_decidir_porcentaje` en `summary()` — `true` para Mark, `false` para Felix | ✅ verificado vía API directa |

## Hallazgo CRÍTICO encontrado y corregido en el momento

**Modal de detalle con datos congelados tras una mutación exitosa.** Al proponer un % de comisión
desde el modal (Felix), el backend persistía el cambio correctamente (confirmado leyendo la fila
directo de `architect_commissions` vía Eloquent), pero el modal — todavía abierto, sin cerrarlo —
seguía mostrando "% de comisión: 10%" sin ningún banner de "pendiente de aprobación". Root cause:
`ComisionesExternasPage.tsx` guardaba una **copia completa** del proyecto/arquitecto en el momento
de abrir el modal (`detailTarget: {architect, project}`); la mutación invalidaba la query de
`summary()` y React Query sí volvía a pedir datos frescos, pero el objeto guardado en
`detailTarget` nunca se actualizaba con esos datos frescos — el modal seguía renderizando el
snapshot congelado del momento de apertura.

Esto habría afectado **todas** las acciones nuevas de este batch dentro del modal: proponer/decidir
%, subir cuenta de cobro/comprobante de retención, cambiar cuenta de pago, marcar como pagado — el
usuario habría tenido que cerrar y reabrir el modal después de CADA acción para ver el resultado,
o peor, habría asumido que la acción falló silenciosamente.

**Fix** (commit `db9e41a`, atlanticerp-frontend): `detailTargetId` ahora guarda solo `{architectId,
pipelineCardId}`; `architect`/`project` se derivan de `summary` en cada render
(`summary?.arquitectos.find(...)`). Cuando `summary` se refresca tras cualquier mutación, el modal
automáticamente recibe los props frescos. Re-verificado en vivo tras el redeploy: proponer % →
aprobar (Mark) → subir cuenta de cobro → marcar pagado, los 4 pasos reflejados en el MISMO modal
sin cerrarlo ninguna vez — capturas en `atlanticerp-frontend/e2e/screenshots/batch17-*.png` (no
commiteadas, evidencia local de esta sesión). Test de regresión agregado:
`ComisionesExternasPage.test.tsx` → "REQ-519 modal de detalle refleja mutaciones sin
cerrar/reabrir".

Por qué no lo agarró la suite de tests antes de este Pre-QA: `ArchitectCommissionDetailModal.test.tsx`
monta el modal directo con props fijos (nunca ejercita el flujo real de apertura+mutación+refetch
de la página contenedora) — un caso real de que el mock por componente aislado no sustituye la
verificación de extremo a extremo contra datos reales.

## Ambiente y evidencia

- `dev.atlanticerp.ai`, desplegado 2026-08-25 (`docker compose … up -d --build`, 2 corridas —la primera
  bloqueada por un test preexistente no relacionado con este batch, ver nota abajo).
- Spec permanente: `atlanticerp-frontend/e2e/preqa-batch17-scrum591-596-20260825.spec.ts` (4 tests, todos
  verdes contra el estado final de `dev.atlanticerp.ai`).
- Capturas: `atlanticerp-frontend/e2e/screenshots/batch17-*.png` (locales, no commiteadas — mismo
  criterio que el resto de sesiones de Pre-QA de este proyecto).

## Nota operativa — gate de test.sh en el deploy bloqueado por un test preexistente

`infra/deploy-manual.sh dev` (atlanticerp-backend) corre la suite completa antes de tocar el servidor
(`set -e`) — `Tests\Feature\VentasDiseno\QuoteControllerTest::test_versions_lista_confirmadas_con_numero_y_etapa_de_tarjeta`
falló 2 veces seguidas, en el mismo punto exacto (2501/2624), con contaminación de orden entre
tests de la suite completa (pasa aislado y en el archivo completo, confirmado 2 veces). No
relacionado con Batch 17 (módulo `VentasDiseno`, sin tocar). El script se detiene con `set -e`
antes de llegar al deploy remoto real — se completó el deploy corriendo manualmente por SSH los
mismos comandos del script (PHPStan + suite ya verificados aparte). Pendiente separado, no de este
batch: investigar la contaminación de orden real de `QuoteControllerTest` para que
`deploy-manual.sh` deje de bloquearse en corridas futuras.
