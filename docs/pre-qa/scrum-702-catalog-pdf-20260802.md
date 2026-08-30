# Pre-QA — SCRUM-702 (REQ-622): "Enviar a cliente" — PDF dinámico de Catálogo sin stock

Fecha: 2026-08-02
Entorno: dev.atlanticerp.ai (ya pusheado y desplegado, commits `8195e0d` backend / `269cc81` frontend)
Suite: `atlanticerp-frontend/e2e/preqa-scrum702-catalog-pdf-20260802.spec.ts` (7/7 passed)

## Resumen

Pasada limpia. No se encontraron hallazgos CRÍTICOS ni MEDIOS bloqueantes. Se ejercitaron los 4
criterios de aceptación del ticket, sus dos reglas de negocio de mayor riesgo (RN4 — "completo"
ignora filtros/selección activa; permiso a nivel de endpoint, no solo UI) y los escenarios
adicionales de ruptura del protocolo Pre-QA (doble clic, producto inactivo, rol sin permiso).

## Escenario de ruptura intentado → resultado

| # | Escenario | Resultado |
|---|---|---|
| 1 | Modo completo con filtro de texto activo en pantalla ("QA-", 82→32 visibles) | PDF descargado con **82** productos (el total real, no los 32 filtrados) — confirma RN4 en el camino visual más obvio de romper. |
| 2 | Modo completo con 2 productos marcados por checkbox pero clic en "Enviar catálogo completo" (no "seleccionados") | PDF con **82** productos — confirma que ni el frontend manda `product_ids` en este modo ni el backend los respetaría si llegaran (test unitario ya cubre el segundo caso; esto confirma el camino end-to-end real). |
| 3 | "Enviar seleccionados" con 0 productos marcados | Botón queda `disabled` (verificado con assertion Playwright + captura), no se puede disparar el request. |
| 4 | "Enviar seleccionados" con 3 productos marcados | PDF con **exactamente 3** productos, ninguno de más ni de menos. |
| 5 | Doble clic rápido en "Enviar catálogo completo" | Solo **1** descarga disparada — el segundo clic golpea el botón ya `disabled` por el `loading` state (`Button.tsx`: `disabled={disabled || loading}`). |
| 6 | Usuario autenticado sin `ventas_diseno.read` (carlos@illuminations.com.pa, rol `tecnico_servicios`) llamando `POST /api/ventas-diseno/catalog/send-pdf` directo | **403** — bloqueo real de middleware, no solo botón oculto. En navegador, la pantalla de Catálogo ni siquiera carga contenido para este rol (queda en blanco bajo "Ventas & Diseño · Inicio"). |
| 7 | Producto marcado `is_active=false` en BD (SPOT-EMP-011, id 4) — sembrado a mano vía SSH y revertido al terminar | No aparece en el listado filtrado ni en el PDF de modo completo (81 productos, no 82). Confirmado y revertido: `is_active=true` de nuevo al cerrar el test. |

## Inspección directa del PDF (no solo content-type — se abrió el archivo con `pdftotext`)

- **RN1 (sin stock/disponibilidad):** ningún PDF generado por este mecanismo contiene los campos
  `disponible`/`en_camino`/cantidad de stock. Nota informativa no bloqueante: 5-6 productos de
  fixtures QA de otros módulos (Bodega) tienen la palabra "stock" **dentro de su propio texto de
  descripción** (ej. `"[QA-BOD-406] Perfil LED Empotrable 2m (demo sin stock)"`, `"Verify
  stock_state_at_order"`) — esto es texto libre preexistente en datos de prueba de otro
  ticket/módulo, no un campo de disponibilidad inyectado por `CatalogPdfService`. No es un
  incumplimiento de RN1 (que habla del campo estructurado, no de substrings en descripciones
  ajenas), pero vale la pena que quien limpia fixtures de QA lo tenga en el radar si esos
  productos llegan a un cliente real.
- **RN2/RN6 (specs sin adjuntar archivo):** producto `QA-FICHA-01` (único con `technical_spec`
  poblado en dev) renderiza su tabla completa (Voltaje 120V, Potencia 8W, Tipo de casquillo E27,
  Temperatura de color 3000K, Flujo luminoso 800lm) — sin ningún link/adjunto al documento real de
  ficha técnica.
- **Layout — producto sin foto:** los 82 productos de dev no tienen `photo_url` cargada; los 82
  renderizan el placeholder "Sin imagen" sin romper el layout de la tarjeta.
- **Layout — producto sin ficha técnica:** los 81 productos restantes (sin `technical_spec`) no
  muestran tabla de specs y no dejan un hueco/espacio roto en la tarjeta.
- Precio, referencia, marca y descripción presentes y con formato correcto (`$XX.XX`) en todos los
  casos observados.

## Lo que sí funciona
- RN4 (completo ignora filtros Y selección activa) — end-to-end, no solo a nivel de servicio.
- RN5 (seleccionados = exactamente N) — exacto, sin productos de más ni de menos.
- Gate de permiso `ventas_diseno.read` — real a nivel de endpoint (403), no cosmético.
- Prevención de doble descarga vía disabled/loading state.
- Producto inactivo nunca se filtra al PDF, ni en modo completo.
- Specs de ficha técnica reflejadas como datos (RN2/RN6), nunca el archivo adjunto.
- Placeholders de foto/specs faltantes no rompen el layout de la tarjeta del PDF.

## Veredicto
**Pasada limpia.** Transiciono el ticket a `QA` y dejo el marcador de Pre-QA
(`~/.claude-preqa-markers/SCRUM-702`).
