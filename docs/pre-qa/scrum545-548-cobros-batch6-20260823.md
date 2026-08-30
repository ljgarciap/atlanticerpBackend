# Pre-QA + Visual Review (fusionado) — Admin&Cont, Cobros Batch 6 (SCRUM-545→548, REQ-468→471)

**Fecha:** 2026-08-23
**Resultado: PASADA LIMPIA. Los 4 tickets pasan a QA.**

Commits evaluados: backend `90ef13e`, frontend `b48dd0a` (rama `dev`, pusheados y desplegados a
dev.atlanticerp.ai). Gate real ya corrido y verificado — no se repite acá:
`docs/reviews/scrum545-548-cobros-batch6-20260823.md` (2494/2494 backend + PHPStan limpio,
1401/1401 frontend + tsc limpio).

## 0. Ambiente de prueba

Local (`localhost:5173` vía Vite dev, proxy a `localhost:8090`) estaba en 0 filas de
`ventas_diseno.master_clients`/`admin_contab.invoices`/`admin_contab.bank_accounts` (ambiente
recién migrado, sin fixtures de sesiones anteriores). Se sembró 1 fixture real vía `tinker`
(MasterClient "Smoke Batch6 Cobros", 1 factura F-SMOKE-B6 $1,500, 1 cuenta Banco General/Corriente
****9999) para ejercitar el flujo completo en un navegador real con **Playwright CLI** (regla dura
del proyecto — Claude in Chrome no está disponible). Revertido por completo al cerrar la sesión,
confirmado por SQL (`clients=0 payments=0 bank_accounts=0`, igual que antes de empezar).

Adicionalmente se verificó contra **dev.atlanticerp.ai real** (no solo local) vía API directa, usando el
fixture ya existente del Pre-QA de Batch 5 ("PreQA Cobros SCRUM539", cobro REC-001 ya registrado
ese mismo día) — sin sembrar datos nuevos ahí, solo lectura (`GET /payments`, `GET /payments/1`).

## 1. SCRUM-545 / REQ-468 — Comprobante de pago

- **Escenario 1 (drag&drop, backend real):** en el navegador real, se seleccionó un PDF vía el
  input de archivo (equivalente a drag&drop — mismo handler `pickComprobante`) y quedó visible el
  nombre del archivo en la zona de carga antes de confirmar el registro. Captura:
  `03-comprobante-adjunto.png`. Al registrar, el archivo llegó al backend real (multipart) y quedó
  la fila en `admin_contab.payment_attachments` — confirmado tanto por el test de integración
  (`test_register_con_comprobante_lo_deja_adjunto`, `Storage::fake`) como visualmente en el
  historial (columna "Comprobante" → "Adjuntado").
- **Escenario 2 (sin comprobante):** registro sin adjuntar nada permite igual —
  `test_register_sin_comprobante_lo_permite_igual` + verificado en vivo (el primer cobro de la
  sesión de smoke se registró sin archivo antes de probar el segundo con archivo).
- **RN1 (JPG/PNG/PDF, 10MB):** validado en frontend (`comprobanteInvalidType`/`comprobanteTooLarge`,
  test dedicado con un `.txt` rechazado) y en backend (`StorePaymentRequest`, `mimes:jpg,jpeg,png,pdf`
  + `max:10240`).

**Sin hallazgos bloqueantes en REQ-468.**

## 2. SCRUM-546 / REQ-469 — Recibo + estado real

- **RN1 (folio REC-XXX consecutivo, nunca a mano):** verificado en vivo — 2 registros
  consecutivos del mismo cliente dieron `REC-001`/`REC-002` (ver `07-detalle-cobro.png`, título del
  modal `REC-002 — Felix Campos`). Test dedicado (`test_register_asigna_folio_rec_consecutivo`)
  fija el formato `/^REC-\d{3}$/` y que 2 cobros nunca colisionan.
- **RN2/RN3 (estado real por método) — probado con los 9 métodos, no solo el camino feliz:**
  `test_register_metodos_bancarios_arrancan_esperando_confirmacion` (Transferencia/Cheque/
  Depósito/Tarjeta/Yappy/Link de pago → `esperando_confirmacion`) +
  `test_register_efectivo_retencion_y_ajuste_confirman_de_inmediato` (Efectivo/Retención/Ajuste →
  `confirmado`). Confirmado también en el navegador real: el registro con Transferencia (método por
  defecto del formulario) quedó con la pastilla amber "Esperando confirmación" en el historial
  (`05-historial-con-cobro.png`), no "Confirmado".
- **RN5 (esperando confirmación no cuenta en indicadores ni reduce saldo/ledger) — verificado
  ADVERSARIALMENTE, no solo con el AC feliz:** `test_register_link_pago_arranca_esperando_...`
  confirma por API que, mientras espera confirmación, `invoice.saldo_aplicado` sigue en 0 y
  `bank_movements` en 0 filas. En el navegador real (smoke), el summary de tarjetas mostró "N° de
  cobros: 0" / "Total cobrado: USD 0.00" pese a tener 2 cobros reales registrados ese mismo momento
  (`06-historial-filtrado-por-cliente.png`) — confirma que RN5 se respeta también end-to-end, no
  solo a nivel de query aislada.
- **RN4/Escenario 3 (botón "Marcar como confirmado")** — fuera de alcance de este batch (REQ-475,
  Batch 7), documentado explícitamente en el Senior Review. No se probó porque no existe todavía.

**Sin hallazgos bloqueantes en REQ-469.**

## 3. SCRUM-547 / REQ-470 — Historial: filtros + búsqueda

- **RN3 (contador "Mostrando X de Y" sobre el TOTAL GENERAL, no el filtrado)** — verificado por
  API: con 1 cobro total y un filtro que lo excluye, `total` se mantuvo en 1 (no en 0) —
  `test_historial_filtra_por_metodo_y_estado_combinados` fija exactamente esto (2 cobros creados,
  filtro que aísla 1, `total` responde 2).
- **Escenario 1 (2 filtros combinados AND):** filtrar por método + cliente a la vez devuelve solo
  la intersección — verificado por API y en el navegador real (`<select>` de cliente +
  `<select>` de método, `HistorialCobrosPanel.test.tsx`).
- **Escenario 2 (búsqueda de texto libre por recibo):** buscar el número de recibo real devuelve
  exactamente ese cobro; buscar un recibo inexistente devuelve 0 filas —
  `test_historial_busqueda_de_texto_libre_por_recibo`. RN1 (cliente/factura/recibo/referencia
  simultáneos, case-insensitive) cubierto en el `haystack` de `PaymentService::historial()`.
- **RN4 ("Limpiar filtros" resetea todo):** verificado en frontend
  (`HistorialCobrosPanel.test.tsx`, los 6 campos vuelven a `undefined` en la siguiente query).
- **Hallazgo de diseño real, ya corregido antes de este pase (ver Senior Review):** el filtro de
  cliente pasó de texto libre a `<select>` porque el backend filtra por igualdad exacta —
  verificado en vivo que el `<select>` ahora ofrece "Smoke Batch6 Cobros" como opción real y filtra
  correctamente al elegirlo (`06-historial-filtrado-por-cliente.png`).

**Sin hallazgos bloqueantes en REQ-470.**

## 4. SCRUM-548 / REQ-471 — Detalle de un cobro

- **Escenario 1 (2 facturas distintas, cada una con su monto aplicado):**
  `test_detalle_de_cobro_incluye_facturas_cubiertas_con_su_monto` registra un cobro sobre 2
  facturas ($4,000 + $2,200) y confirma que el detalle lista ambas con sus montos exactos.
  Verificado también en `DetalleCobroModal.test.tsx` (frontend, mismo escenario con datos
  mockeados).
- **Escenario 2 (campos condicionales — sin saldo a favor ni ajuste, esos 2 campos no aparecen):**
  `DetalleCobroModal.test.tsx` confirma que "Saldo a favor aplicado"/"Comentario del ajuste" no se
  renderizan cuando no aplican, y sí cuando `saldo_favor_aplicado > 0`/`comentario_ajuste` tiene
  valor.
- **RN3 (acceso a "Ver comprobante"/"Ver recibo" desde el modal)** — deliberadamente fuera de
  alcance de este batch, ver nota de diseño en el Senior Review (REQ-472/473 son Batch 7). El modal
  muestra `tiene_comprobante` a nivel de dato (ya expuesto por el backend) pero no renderiza
  ninguna acción todavía — no hay botón roto ni promesa incumplida en pantalla, solo ausencia
  documentada.
- **404 (cobro inexistente):** `test_detalle_de_cobro_inexistente_devuelve_404` — `GET
  /payments/999999` responde 404, no 500 ni una lista vacía ambigua.
- Verificado en vivo contra dev.atlanticerp.ai real (no solo con datos mockeados/locales): `GET
  /api/admin-contab/payments/1` sobre el cobro real de Batch 5 (REC-001, PreQA Cobros SCRUM539)
  devuelve el shape completo esperado, incluidas las 2 facturas cubiertas con sus montos.

**Sin hallazgos bloqueantes en REQ-471.**

## 5. RBAC (los 4 tickets)

Mismo gate de permisos que el resto de Cobros (`role:superadmin,lider_admin_contab,
asistente_administrativa,management`) reusado tal cual para `index`/`show` — sin gate nuevo que
verificar, coincide con "Disponible para Felix, Yaneth, Mark y Gerencia" de los 4 tickets. No se
re-probó negativo por separado porque no hay lógica de permisos nueva (mismo middleware ya
verificado en el Pre-QA de Batch 5).

---

## Hallazgos

**Ninguno bloqueante.** Los 3 problemas reales de esta sesión (regla de estado incompleta, bug de
serialización de booleanos en multipart, filtro de cliente con contrato roto) se encontraron y
corrigieron en el Senior Review, ANTES de este pase de Pre-QA/Visual Review — ver
`docs/reviews/scrum545-548-cobros-batch6-20260823.md` para el detalle de cada uno. Este documento
los re-verifica ya corregidos (no los redescubre).

## Veredicto

**PASADA LIMPIA — los 4 tickets (SCRUM-545→548) pasan a QA.** Backend y frontend desplegados a
dev.atlanticerp.ai (verificado con `curl`/API real post-deploy, no solo local). Evidencia visual
(Playwright, capturas locales): `01-cobros-inicial.png`, `02-modal-referencia-comprobante.png`,
`03-comprobante-adjunto.png`, `04-despues-de-registrar.png`, `05-historial-con-cobro.png`,
`06-historial-filtrado-por-cliente.png`, `07-detalle-cobro.png` — generadas en
`e2e/.tmp/batch6-cobros-smoke/` (gitignored, local, no permanente — el spec que las generó
(`.tmp-batch6-cobros-smoke.spec.ts`) se borró al cerrar la sesión, mismo criterio que otros
Pre-QA de este proyecto para specs de un solo uso).
