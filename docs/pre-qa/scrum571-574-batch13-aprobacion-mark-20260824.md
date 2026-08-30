# Visual Review + Pre-QA (fusionado) — Admin&Cont, Notas Crédito Batch 13 (SCRUM-571→574, REQ-494→497)

**Fecha:** 2026-08-24/25
**Entorno:** stack local Docker (`infra/docker-compose.yml`, nginx `localhost:8090`) + Vite dev
server (`localhost:5173`) — no `dev.atlanticerp.ai`. Datos sembrados con prefijo `[PREQA-B13]`: fixtures
de `Order`/`AdminContInvoice` vía tinker (mismo criterio que
`AdminContCreditNoteControllerTest::makeInvoicedOrder()` — plumbing Quote→Order→Invoice fuera de
alcance de este batch) + `register()`/`decide()` ejercitados por HTTP real (`curl` con JWT de Felix/
Mark) y por la UI real (Playwright).

**Resultado: 1 hallazgo CRÍTICO real encontrado y corregido en el momento (commit local pendiente
de push) + 1 hallazgo MEDIO no bloqueante documentado para el Arquitecto + 1 nota de documentación
(credencial de despacho incorrecta, no un bug de producto). Con la corrección aplicada, pasada
limpia y concluyente de los 4 REQ, incluidos los 3 focos explícitos del Senior Review — batch queda
en condiciones de pasar a QA.**

Spec de referencia: SCRUM-571→574 (REQ-494→497) en Jira + mockup adjunto (`4D__Admin_Contabilidad_
Notas_Credito.html`, adjunto de SCRUM-571 — el Senior Review decía "sin mockup adjunto"; sí lo hay,
mismo archivo compartido de Notas Crédito ya usado en Batches 10-12, revisado igual) + ADR
(`docs/adr/ADR-SCRUM571-574-batch13-aprobacion-mark-comprobante-documento.md`) + Senior Review
(`docs/reviews/scrum571-574-batch13-aprobacion-mark-20260824.md`).

Suite Playwright: `atlanticerp-frontend/e2e/preqa-scrum571-574-batch13-20260824.spec.ts` — 9 tests, todos
en verde tras las correcciones de este documento, corridos contra el stack local.

---

## Paso 0 — nota de entorno (no bloqueante, corregido en el camino)

El prompt de despacho traía una contraseña específica para Mark (`B1n4X_2026?`) marcada como
"excepción al patrón default", con la advertencia de que un despacho previo se había colgado en el
login. Verificado con `Hash::check()` contra la BD real del stack local: esa contraseña **no es
válida** — el usuario Mark (`mbekhar@atlantic.com.pa`, id 6, coincide con
`fiscal_settings.mark_approver_user_id`) sigue con el password default (su propio email), igual que
el resto del roster. No es un bug de código — es una desactualización de la nota de despacho/
memoria del proyecto. Recomendación: corregir esa nota en `memory/` o `reference_ssh_dev.md` para
que el próximo despacho no vuelva a perder tiempo en esto.

Segunda causa real del colgado original (ya resuelta acá, documentada para que no se repita):
el Vite dev server que ya estaba corriendo tenía la caché de dependencias optimizadas corrupta
(`504 Outdated Optimize Dep` en consola, página en blanco sin ningún error de React) — nada que
ver con credenciales. Se resolvió matando el proceso, borrando `node_modules/.vite` y arrancando
de nuevo (`nohup npm run dev &`). Con ambas causas resueltas, el resto de la pasada corrió sin
ningún login fallido.

---

## Hallazgo CRÍTICO — "Destino del monto" en el documento formal muestra la clave i18n cruda, no el texto (REQ-496 RN1)

**Corregido en el momento** (regla del proyecto — fix chico, un array de 3 líneas).

`DocumentoNotaCreditoModal.tsx`, constante `RESULTADO_KEY`, mapeaba `resultado` a
`'documentoModal.resultado.aplicadoSaldo'` / `'...devuelto'` / `'...saldoFavor'` — sin el prefijo
`notasCredito.` que **toda otra clave de este mismo componente sí usa** (ej.
`t('notasCredito.documentoModal.title')`, misma línea de arriba). La clave real en
`i18n/locales/es/adminContab.json` vive en `notasCredito.documentoModal.resultado.*`. Como la clave
pasada a `t()` no existía en ese namespace, i18next devolvía el string crudo tal cual, y el campo
"Destino del monto" (REQ-496 RN1, dato obligatorio del documento) mostraba literalmente
`documentoModal.resultado.aplicadoSaldo` en pantalla — para los 3 valores posibles de `resultado`,
es decir siempre que ese campo se muestra.

Reproducido en navegador real: captura antes del fix (`Destino del monto: documentoModal.resultado.
aplicadoSaldo`), confirmado también con `curl` (el JSON de `show()` sí trae `resultado: "aplicado_
saldo"` correcto — el bug es puramente de mapeo de i18n en el frontend, no de datos).

**Por qué la suite mockeada (1493 tests, todos en verde en el Senior Review) no lo detectó:**
`DocumentoNotaCreditoModal.test.tsx` mockea `useTranslation` para que `t(key)` devuelva la propia
`key` sin resolverla contra el diccionario real — un patrón usado en todo el frontend para aislar
componentes de i18n. Ningún test de ese archivo aseguraba el texto renderido de "Destino del
monto", así que la ruta rota nunca se ejercitó. Solo se vio corriendo la app real (Playwright).

**Fix aplicado:** agregado el prefijo `notasCredito.` a los 3 valores de `RESULTADO_KEY`.
**Regresión cubierta:** 3 tests nuevos parametrizados en `DocumentoNotaCreditoModal.test.tsx`
(uno por valor de `resultado`) que aseguran la clave completa esperada y la ausencia del string
sin prefijo — para que una regresión futura al prefijo faltante rompa en la suite mockeada
también, no solo en un E2E.

**Re-verificación tras el fix:**
- `npx vitest run DocumentoNotaCreditoModal` → 6/6 verde (3 preexistentes + 3 nuevos).
- `npx vitest run admin-contab` (scope del módulo completo) → 17 archivos, 187 tests, todos verdes.
- `npx tsc --noEmit` → limpio.
- Playwright (`REQ-496 RN3`, ver checklist abajo) → verde, con aserción explícita de que el texto
  crudo `documentoModal.resultado.` NO aparece en el modal y que sí aparece el texto humano
  ("Aplicado al saldo pendiente" / "Devuelto al cliente" / "Dejado como saldo a favor").

Archivo modificado: `atlanticerp-frontend/src/components/admin-contab/DocumentoNotaCreditoModal.tsx`
(+ test). Sin cambios de backend. **Pendiente:** push a `dev` (lo hace el Arquitecto al cerrar el
batch, junto con el resto).

---

## Hallazgo MEDIO (no bloqueante) — botones Aprobar/Rechazar visibles para actores que NO son Mark

REQ-494 RN1 es explícito: *"Solo el usuario con rol Mark puede aprobar o rechazar una nota
pendiente — ningún otro rol, ni siquiera 'Gerencia' en general, tiene este permiso."* El backend
cumple esto correctamente (`EnsureIsMarkApprover`, 403 con mensaje claro "Esta pantalla es
exclusiva de Mark." — verificado por API real y en navegador, ver captura). Pero
`CreditNoteService::show()` calcula `puede_aprobar_rechazar` **solo por estado** de la nota
(`estado === pendiente_aprobacion`), sin considerar quién es el actor — a diferencia del patrón ya
establecido en el mismo epic para la misma regla de negocio: `AdminContInvoiceController::detail()`
(Facturación, incobrables) calcula `puede_decidir_incobrable` resolviendo la identidad real
(`mark_approver_user_id` o `superadmin.all`) para que el frontend solo muestre el botón a Mark.

**Efecto real, confirmado en navegador:** Felix (líder Admin&Cont, NO Mark) ve los botones
"Aprobar"/"Rechazar" en cualquier nota pendiente, igual que Mark. Si los usa, el backend bloquea
correctamente y muestra un mensaje inline claro ("Esta pantalla es exclusiva de Mark.") — **no es
un fallo silencioso ni un problema de seguridad** (ver captura `felix-click-aprobar-result.png`
tomada durante la verificación). Es una inconsistencia de UX/patrón: la misma regla de negocio
("exclusivo de Mark") se resuelve distinto en dos pantallas del mismo batch/epic, y el usuario real
(Felix, Yaneth, Gerencia) ve dos botones de acción que van a fallar siempre para su rol, en vez de
no verlos.

No lo clasifico CRÍTICO porque no hay pérdida de funcionalidad del mockup ni bypass de seguridad —
el mockup mismo tampoco distingue por actor (es una demo estática sin sesión real). Pero lo reporto
explícitamente porque el patrón ya existe resuelto en el mismo batch/epic (Facturación) y el propio
checklist de Pre-QA pide verificar "rol sin permiso — ¿bloquea de verdad o solo esconde el botón?".
Queda a criterio del Arquitecto si amerita un ticket de seguimiento (mismo criterio que
`puede_decidir_incobrable`: computar la identidad server-side en `show()` y exponer algo como
`puede_aprobar_rechazar_actor`) o se acepta como está.

---

## Checklist verificado (REQ-494→497 + focos explícitos del Senior Review)

| Foco | Verificación | Resultado |
|---|---|---|
| REQ-494 RN1 — "Anulación completa, monto bajo" SIEMPRE pendiente_aprobacion | NC-0007 ($200, muy bajo el umbral $5,000) registrada por API real → `estado: pendiente_aprobacion` confirmado por API y en UI ("Pendiente de aprobación") | ✅ |
| REQ-494 RN1 — exclusividad de Mark (backend) | Felix intenta `PUT .../decision` → 403 "Esta pantalla es exclusiva de Mark." (API y UI, con mensaje inline visible) | ✅ (ver hallazgo MEDIO sobre visibilidad del botón) |
| REQ-494 RN2 — bloqueo de aprobación sin comprobante (Anulación completa/monto alto) | Nota pendiente sin comprobante (creada directo en BD para forzar el estado, ya que `register()` lo exige desde el alta) → Mark intenta aprobar → 422 "...requiere comprobante de soporte adjunto antes de poder aprobarse." | ✅ |
| REQ-494 RN2 — Devolución de mercancía NUNCA exige comprobante | NC-0008 (devolución, $8,000 > umbral, SIN comprobante) → Mark aprueba sin problema → `estado: aplicada`, `tiene_comprobante: false` | ✅ (API y UI) |
| REQ-494 RN3 — aprobar registra quién y cuándo | NC-0011 aprobada por Mark → `aprobado_por: "Mark Bekhar"`, `fecha_decision` presente, visible en UI | ✅ |
| REQ-494 RN4 — motivo obligatorio al rechazar | Rechazo sin `motivo_rechazo` → 422 "El campo motivo rechazo es obligatorio."; con motivo → 200, `estado: rechazada`, `motivo_rechazo` guardado | ✅ |
| REQ-494 RN5 — rechazo no genera factura ni mueve dinero | NC-0010 rechazada, sin `factura_nueva_numero`, sin movimiento bancario/saldo a favor generado | ✅ |
| REQ-495 — Ver comprobante (PDF/imagen) | NC-0009 (imagen JPG): `<img>` real, URL presignada S3 verificada por `curl` (devuelve `content-type: image/jpeg`, bytes reales del JPG subido); NC-0007 (PDF): `mime_type: application/pdf` correcto | ✅ |
| REQ-495 RN2 — sin comprobante, mensaje explícito | NC-0008 (sin comprobante): "Esta nota no tiene ningún comprobante adjunto.", sin `<img>` roto | ✅ |
| REQ-496 RN1 — documento con todos los datos | PDF real (`GET .../pdf`) contiene estado, cliente, tipo, motivo, motivo de rechazo, desglose — confirmado con `pdftotext` | ✅ |
| REQ-496 RN2 — factura relacionada clicable desde el documento | Botón "Ver factura [numero]" en el modal de documento, descarga la factura vía `invoices.downloadPdf()` | ✅ |
| REQ-496 RN3 — nota rechazada muestra "Rechazada", nunca aplicada | NC-0010: PDF y modal en pantalla muestran "RECHAZADA"/"Rechazada" explícito | ✅ |
| REQ-497 RN1 — factura relacionada correcta, formato completo | `factura_origen_id` (PK, 40) vs `factura_origen_order_id` (41) confirmados DISTINTOS con datos reales; `GET /invoices/40/detail` (PK equivocado) resuelve **silenciosamente a la factura de OTRO cliente** (sin 404) — confirma que el bug que el Senior Review reconcilió era real y grave; `GET /invoices/41/detail` (order_id correcto) resuelve la factura correcta; descarga en UI confirma el archivo correcto (`factura-[PREQA-B13]-F-23924.pdf`) | ✅ |
| REQ-495 mime_type (defecto reconciliado en Senior Review) | Confirmado para jpg y pdf, ambos con vista previa correcta en `<img>`/`<iframe>` | ✅ |

---

## Gate ejecutado en esta pasada

- Backend: sin cambios de código — no se re-corrió `infra/test.sh` completo (el Senior Review ya
  lo corrió limpio, 2560 tests, tras sus 2 reconciliaciones; ningún archivo de `atlanticerp-backend` se
  tocó en esta sesión de Pre-QA).
- Frontend: `npx tsc --noEmit` limpio. `npx vitest run admin-contab` — 17 archivos, **187 tests,
  todos verdes** (incluye los 3 nuevos de esta sesión). `npx playwright test
  e2e/preqa-scrum571-574-batch13-20260824.spec.ts` — **9/9 verde**.
- No se corrió la suite Vitest/Playwright completa del repo (fuera del scope tocado, mismo criterio
  de "ciclo interno" del proyecto) — el Arquitecto puede correr la suite completa como parte del
  gate real antes del push si lo prefiere, dado que sí hubo un cambio de código real (aunque
  mínimo) en esta sesión.

## Deuda de proceso observada (no de este batch, reportada para visibilidad)

Worktrees huérfanos de los forks Backend Dev/Frontend Dev de este mismo batch, ya mergeados a
`dev` (commits `661483d`/`f115769` son ancestros de `HEAD` en ambos repos) pero nunca limpiados:
`atlanticerp-backend/.claude/worktrees/agent-a11979057d37056a6`,
`atlanticerp-frontend/.claude/worktrees/agent-ac833faabb91f8f68`. Regla del `CLAUDE.md` raíz ("limpiar su
worktree/rama en el mismo momento... no dejarlo para una próxima auditoría") — señalado para que el
Arquitecto los limpie (`git worktree remove` + `git branch -D`), no es bloqueante de este batch.

## Datos de prueba sembrados (quedan en el stack local, prefijo `[PREQA-B13]`)

6 clientes/facturas fixture + 7 notas de crédito (NC-0007→NC-0013, ids 26-32) en el schema
`atlantic_admin_contab` local. No se limpiaron — mismo criterio que sesiones de Pre-QA
anteriores (datos locales, sin impacto en `dev`/`test`/`prod`).

## Veredicto

**Limpio, con 1 fix aplicado en el momento** (regla del proyecto: hallazgo chico se corrige acá
mismo y se vuelve a correr el checklist completo — ya hecho, ver tabla arriba, todo verde tras el
fix). El hallazgo MEDIO (visibilidad de botones para no-Mark) queda documentado como no bloqueante,
a criterio del Arquitecto. Los 4 tickets (SCRUM-571→574) están en condiciones de pasar de `Dev
Testing` a `QA` — transición y push la ejecuta el Arquitecto al cerrar el batch, según el flujo del
proyecto.
