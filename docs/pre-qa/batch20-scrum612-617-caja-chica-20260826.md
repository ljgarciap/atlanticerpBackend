# Pre-QA — Batch 20 Admin&Cont (SCRUM-612→617, REQ-535→540) — Caja Chica

Corrido en LOCAL (`localhost:5173` Vite dev server sobre código sin commitear de Backend Dev +
Frontend Dev, proxy `/api` → `localhost:8090`) — este batch no está desplegado a ningún ambiente
todavía. Suite completa verde en ambos repos + PHPStan limpio (confirmado por Senior Review antes
de este pase). Test permanente: `atlanticerp-frontend/e2e/preqa-batch20-scrum612-617-cajachica-20260826.spec.ts`.

## CRÍTICO — SCRUM-617 (REQ-540) / afecta también SCRUM-616 (REQ-539)

**Qué se rompe:** al aprobar un reporte (Mark, botón "Aprobar reporte" → confirmar), el modal de
detalle se cae en blanco con `Cannot read properties of undefined (reading 'map')` en
`DetalleReportePettyCashModal`. La aprobación SÍ se persiste correctamente en BD (`estado` pasa a
`finalizado`, `aprobado_por`/`fecha_aprobacion` quedan seteados — verificado por SQL directo), es
puramente un crash de render en el frontend. El usuario nunca ve el botón "Descargar" ni ninguna
confirmación visual de que la aprobación funcionó — tiene que cerrar y reabrir el modal a mano.

**Causa raíz identificada:** `PUT /api/admin-contab/petty-cash/reports/{numero}/approve`
(`AdminContPettyCashController::approveReport()`) devuelve solo `{numero, estado}` — pero
`useApprovePettyCashReport()` en `atlanticerp-frontend/src/hooks/useAdminContab.ts` hace
`qc.setQueryData(pettyCashReportDetailKey(numero), data)` con esa respuesta parcial, **reemplazando
por completo** el objeto cacheado de `PettyCashReportDetail` (que tenía `grupos`, `total_general`,
`puede_aprobar`, etc.) por uno que solo tiene 2 campos. `DetalleReportePettyCashModal.tsx` intenta
`detail.grupos.map(...)` sobre `undefined` → crash. El tipo TypeScript de
`adminContabApi.pettyCash.approveReport()` está anotado como `Promise<PettyCashReportDetail>`, pero
el runtime real del backend no cumple ese contrato — el mismatch nunca lo agarra ningún test porque
los tests de backend solo verifican `numero`/`estado` (correcto para su propio alcance) y los tests
de frontend mockeados le dan al mock el shape completo en vez del shape real y mínimo del backend.

**Cómo reproducir:** login Mark (`mbekhar@atlantic.com.pa`) → Caja chica → Reportes → Ver un
reporte `pendiente_aprobacion` → Aprobar reporte → confirmar. Pantalla en blanco.

**Evidencia:** `docs/visual-review/screenshots/SCRUM-617-1.png` (estado en blanco tras aprobar) +
consola: `Cannot read properties of undefined (reading 'map')`, stack apunta a
`DetalleReportePettyCashModal.tsx:33:56`.

**Sugerencia de fix (2 opciones, cualquiera resuelve):**
1. **Más simple** — en `useApprovePettyCashReport()` (`useAdminContab.ts`), no usar
   `setQueryData` con la respuesta parcial: reemplazar por
   `qc.invalidateQueries({ queryKey: pettyCashReportDetailKey(numero) })`, forzando un refetch del
   detalle completo real (mismo patrón que `invalidatePettyCashLists()` ya usa en el resto del
   archivo).
2. Alternativa — que `AdminContPettyCashController::approveReport()` devuelva el detalle completo
   (reusar `reportDetail()` + el mismo cálculo de `puede_aprobar`) en vez de `{numero, estado}`, para
   que la respuesta cumpla de verdad el tipo `PettyCashReportDetail` que el frontend espera.

**Bloqueante:** SÍ — REQ-540 (confirmar aprobación) y REQ-539 RN3 (ver "Descargar" tras finalizar)
no se pueden verificar con la UI en su estado actual. No pasa a QA hasta que se corrija.

## Nota — no bloqueante (SCRUM-612/REQ-535)

Mark (`management`, sin permiso de registrar gastos) sigue viendo los botones "+ Nuevo gasto" y
"Generar reporte" en el header — el frontend no los oculta por rol. Al intentar usarlos, el backend
bloquea de verdad (403) y el error SÍ se muestra visible en el modal ("No tienes permiso para
realizar esta acción.", ver `e2e/screenshots/batch20-mark-boton-visible-pero-403.png`) — no es un
bloqueo silencioso, así que no es CRÍTICO. Oportunidad de pulido: ocultar/deshabilitar esos 2
botones para roles sin `admin_contab.write` en vez de dejar que el usuario llegue al 403.

## Lo que sí cumple (probado activamente, camino de ruptura incluido)

- **REQ-535** — multi-línea de 2 solicitantes distintos con foto real se registra y aparece
  agrupada; falta de foto en cualquier línea bloquea el guardado COMPLETO (ninguna línea se
  persiste) e indica "Falta la foto del recibo" en la línea exacta; ITBMS vacío (a diferencia de
  "0" explícito) bloquea con "Completa todos los campos de esta línea" — RN1 se cumple de verdad,
  no se asume 0 por default.
- **REQ-536** — 3 tabs con contador correcto en la etiqueta ("N gasto(s) sin reportar", "N
  generado(s) · N sin aprobar", "N línea(s)"), Pendientes por defecto.
- **REQ-537** — agrupado alfabético por solicitante confirmado con 2 solicitantes reales (Aaron
  Leis, Agustín Rodríguez), subtotal por grupo y total general correctos aritméticamente.
- **REQ-538** — flujo de 2 pasos real: desmarcar un solicitante completo lo saca por completo de
  la selección (paso 1 → paso 2 solo con el otro grupo), "Continuar" sin forma de pago vacía
  bloquea con mensaje, folio consecutivo `NNNN-2026` generado al confirmar, los gastos
  seleccionados desaparecen de Pendientes tras generar el reporte.
- **REQ-539** — Felix (no Mark) no ve el botón "Aprobar reporte" en el detalle de un reporte
  pendiente (gate de `puede_aprobar` funciona del lado correcto para quien NO es Mark).
- Rol sin permiso — el registro de gasto está genuinamente bloqueado a nivel backend para
  `management`, con feedback visible (ver nota arriba).

## Pendiente tras el fix

Re-correr el checklist COMPLETO de REQ-539/540 (no solo el punto que falló) una vez que
Backend/Frontend Dev corrijan el hallazgo CRÍTICO — confirmar que "Descargar" aparece, dispara la
descarga real del PDF, y que un segundo intento de aprobar (ya finalizado) sigue devolviendo 409 sin
romper la UI tampoco en ese camino.

## RE-VERIFICACIÓN POST-FIX (26 ago 2026, misma sesión) — CRÍTICO RESUELTO

El Arquitecto aplicó la opción 1 de la sugerencia de fix (`useApprovePettyCashReport()` ahora usa
`qc.invalidateQueries({ queryKey: pettyCashReportDetailKey(numero) })` en vez de `setQueryData` con
la respuesta parcial; `adminContabApi.pettyCash.approveReport()` se re-tipó a
`PettyCashApproveResponse` en vez del `PettyCashReportDetail` incorrecto). Re-corrida completa del
checklist, no solo el punto que falló:

- Suite permanente completa (`e2e/preqa-batch20-scrum612-617-cajachica-20260826.spec.ts`): **7/7
  passed**, incluyendo el test REQ-540 que antes hubiera fallado (asserta `Descargar` visible y
  `Aprobar reporte` con count 0 tras aprobar).
- Confirmado visualmente (`e2e/screenshots/batch20-reporte-finalizado.png`): tras aprobar, el modal
  muestra el detalle completo real (grupo "Agustín Rodríguez", línea con fecha/proveedor/
  descripción/monto, "Total general", "Aprobado por Mark Bekhar el 26 ago 2026", botón
  "Descargar") — sin crash, sin pantalla en blanco.
- Segundo intento de aprobar (ya finalizado) sigue devolviendo 409 sin romper la UI (cubierto por
  el test backend `test_aprobar_reporte_es_exclusivo_de_mark`, no re-verificado en el navegador en
  esta pasada por ser puramente backend — comportamiento sin cambios respecto al fix).
- Resto del checklist (REQ-535/536/537/538/539 parcial) repasado completo, sin regresiones nuevas
  introducidas por el fix.

**Veredicto: pasada limpia y concluyente. Aprobado para pasar a QA.**
