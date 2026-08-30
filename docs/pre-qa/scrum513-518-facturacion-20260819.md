# Visual Review + Pre-QA (fusionado) — Admin&Cont, Facturación (SCRUM-513→518, REQ-436→441)

**Fecha:** 2026-08-19
**Resultado: LIMPIO tras 1 fix (mismo día, re-check completo tras el fix) — pasa a QA.**

Spec de referencia: 6 tickets de Jira (SCRUM-513→518) + mockup adjunto en cada uno
(`4B__Admin_Contabilidad_Facturacion.html`).

Primer batch que introduce el concepto de "entrega facturable" — sin tabla propia, mapeado a
`Bodega\Models\Order`. Ver `docs/reviews/scrum513-518-facturacion-20260819.md` para el detalle de
las 3 reconciliaciones de spec (mockup vs. REQ vs. nota RESUELTO de REQ-441) que fundamentan las
decisiones de abajo.

---

## 1ª pasada — 1 hallazgo CRÍTICO (seguridad/integridad de datos)

### CRÍTICO — `confirm()`/`preview()` no validaban la etapa de Bodega del pedido

`InvoiceService::missingRequirements()` solo chequeaba cotización + monto>0 + cliente (RN2 de
REQ-441) — nunca validaba que el `Order` hubiera llegado a una etapa facturable de Bodega
(`por_despachar`/`despachado`/`entregado`). La UI nunca ofrece "Generar factura" para un pedido
todavía en `asignado`/`picking_pendiente`/`en_picking`/`packing` (esos ni siquiera aparecen en la
lista), pero un `order_id` válido de un pedido en esas etapas SÍ podía facturarse llamando
`POST /api/admin-contab/invoices` directo — permitiendo facturar una entrega que Bodega todavía ni
empezó a preparar.

**Fix aplicado** (mismo día, por el Arquitecto directamente): `InvoiceService::FACTURABLE_STAGES`
(constante compartida con `summary()`) + chequeo nuevo en `missingRequirements()` con mensaje
`'La entrega todavía no llega a la etapa "Por despachar" de Bodega'`. Test de regresión agregado
(`test_preview_rechaza_una_entrega_que_todavia_no_llega_a_por_despachar`).

## Verificado en vivo (Playwright real contra `dev.atlanticerp.ai` + SQL directo) — sin más hallazgos

- Flujo completo con datos reales: fixture sembrado por tinker (Order `por_despachar` + Quote +
  PipelineCard con `amount`), "Generar factura" → vista previa (`USD 4,500.00`, cotización
  `COT-DEMO-0001`) → confirmar → factura `F-0001` real. Sin errores de consola en ningún paso.
- Las 4 tarjetas de resumen se actualizaron en vivo tras confirmar (`pendientes_facturar` 1→0,
  `facturadas_mes` 0→1, `monto_facturado_mes` $0→$4,500) — RN4 REQ-437 confirmado con datos reales,
  no solo con el test unitario.
- Verificado por SQL directo: `bodega.orders.invoice_ready = true` y
  `admin_contab.invoices.numero = 'F-0001'` para el mismo `order_id` — el evento
  `admin_contab.invoice.created` → `MarkOrderInvoiceReady` funciona end-to-end, no solo mockeado en
  tests.
- RN1 REQ-440 (clic en cliente no expande/colapsa el grupo): confirmado en el componente real
  (`stopPropagation`) y con test dedicado.
- RN1 REQ-439 (agrupado por defecto) y RN4 (filtros persisten al cambiar de vista): confirmado.
- Doble facturación de la misma entrega → 422 real con mensaje "ya tiene una factura generada".
- Rol sin acceso (`designer`) → 403 real en `POST /invoices`.

## Fidelidad visual contra el mockup — divergencias documentadas, no CRÍTICAS

El mockup modela "hitos" múltiples por proyecto (anticipos previos a cualquier despacho, columna
"Guía de Entrega" por fila) — fuera de alcance de este batch por decisión del Arquitecto (ver
Senior Review). La pantalla real cubre el mismo layout general (encabezado, 4 tarjetas, tabs,
toggle, filtros, tabla agrupada/plana, wizard, vista previa) con "1 entrega = 1 pedido de Bodega"
en vez de "hitos". No se considera CRÍTICO porque ningún REQ 436→441 pide el modelo de hitos —
son las tarjetas/tabs/toggle/filtros/botones los que sí están, completos.

## Regresión — suites completas

- Backend: PHPStan Level 8 → 0 errores. `infra/test.sh` → **2383/2383 OK** (incluye 22 tests de
  Facturación).
- Frontend: `npm run test -- --run` → **1314/1314 OK** (incluye 7 tests de
  `FacturacionPage.test.tsx`). `tsc --noEmit` → sin errores.
- Gate de `infra/deploy-manual.sh` (backend + frontend) → limpio, ambos desplegados.

## Veredicto y despliegue

**Pasada limpia tras 1 fix CRÍTICO, mismo pase, checklist completo re-corrido después del fix.**
Los 6 tickets (SCRUM-513→518) pasan a `QA`.

Pusheado a `origin/dev` (backend `6eba07b`, frontend `8a6553d`) el 2026-08-19 con aprobación
explícita de Luis. Desplegado vía `infra/deploy-manual.sh dev` en ambos repos — `dev.atlanticerp.ai/up` y
`dev.atlanticerp.ai/` responden OK. Disponible para validación del CTO en dev.
