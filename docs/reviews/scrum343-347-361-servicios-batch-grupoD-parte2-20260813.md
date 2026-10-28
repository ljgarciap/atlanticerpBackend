# Pre-QA (backend) — Fase 4 Servicios, Grupo D parte 2 "Reserva Servicios de Insumos" (REQ-273→277/291)

**Fecha:** 2026-08-13
**Tickets:** SCRUM-343/344/345/347/361 (backend). SCRUM-346 (REQ-276) es backend-mínimo: solo
`GET /servicios/tools/movements`, ya cubierto por Batch 13 parte 1.

## Veredicto: PASA LIMPIO tras 1 fix real (cross-module, Compras)

Ver documento completo (Visual Review + Pre-QA fusionados, con el checklist adversarial detallado)
en `atlanticerp-frontend/docs/pre-qa/scrum343-347-361-servicios-batch-grupoD-parte2-20260813.md`. Este
documento registra específicamente el fix de backend, porque toca un módulo (Compras) fuera del
alcance directo de Servicios.

## Hallazgo CRÍTICO — `PurchaseOrder.origin_module` nunca se exponía en la API de Compras

`InsumoService::requestPurchase()` (agregado en este mismo batch, commit `adc8e17`) ya persistía
`origin_module = PurchaseOrder::ORIGIN_SERVICIOS` correctamente. Pero
`PurchaseOrderController::formatSummary()`/`formatDetail()` — código preexistente de Compras, no
tocado por este batch — nunca incluían ese campo en la respuesta JSON de `GET /api/compras/orders`
ni `GET /api/compras/orders/{id}`. Resultado: REQ-274 RN3 ("la orden generada en Compras debe
quedar marcada con Origen: Servicios") y el Acceptance Criteria Escenario 1 ("visible en Compras
con Origen: Servicios") quedaban incumplidos en la práctica — el dato existía en BD pero era
invisible para cualquier consumidor de la API, incluida la UI de Compras.

**Fix:** una línea en `formatSummary()` (`app/Modules/Compras/Http/Controllers/
PurchaseOrderController.php`) — `'origin_module' => $order->origin_module`. `formatDetail()` lo
hereda automáticamente porque compone sobre `formatSummary()`.

**Test agregado:** `tests/Feature/Servicios/InsumoReservaTest.php`,
`test_agregar_insumo_crea_setting_y_dispara_solicitud_en_0_disponible` — se agregó una segunda
mitad al test existente que hace `GET /api/compras/orders/{id}` como usuario de Compras real
(`designer@atlantic.test` con `compras.read`) y verifica `origin_module` en la respuesta
HTTP real, no solo en el modelo Eloquent — para que una futura regresión de "el campo se guarda
pero no se expone" se detecte en este nivel, no solo con una lectura directa de BD.

## Por qué este hallazgo no lo atrapó el desarrollo original

`InsumoService`/`InsumoController`/las migraciones de este batch están completos y bien
documentados (ver docblocks extensos citando cada RN). El gap está en la frontera con un módulo
que este batch no tocó (`PurchaseOrderController` es de Compras, Sprint 2) — el patrón "guardar el
dato correctamente pero no verificar que el consumidor que realmente lo necesita (Compras, no
Servicios) lo reciba" es exactamente el tipo de gap que Pre-QA existe para atrapar: los tests
unitarios/feature de `InsumoReservaTest.php` verificaban `$order->origin_module` contra el modelo
directamente (correcto), nunca contra la respuesta HTTP que consume el usuario real de Compras.

## Verificación de regresión

- PHPStan Level 8, proyecto completo (`--memory-limit=1G`): 0 errores, antes y después del fix.
- `infra/test.sh --filter=Compras` (347 tests, todo el módulo — no solo el archivo tocado): verde,
  para descartar que exponer un campo nuevo rompiera algún snapshot/assertion de forma exacta en
  otra parte de Compras.
- `infra/test.sh --filter=InsumoReservaTest`: 9/9, incluida la assertion nueva.
- Suite completa del backend: 2014/2014.

## Verificación end-to-end real (no solo HTTP síntetico)

Flujo completo ejecutado contra el stack local real (Docker, Postgres compartido con dev), no solo
vía PHPUnit: Aaron solicita un insumo → Lidercompras avanza la orden real por los 5 estados de un
proveedor internacional (LightCorp, único proveedor demo con `provider_id` real) → Ingreso de
Mercancía real → "Recibido" → verificado por consulta directa a `inventory_movements` que se
generaron los 2 movimientos `Reubicación` esperados (`Bodega Central: -5`, `Reserva Servicios:
+5`), en la misma transacción que `InsumoPurchaseRequest.estado → recibida`. Detalle completo y
capturas en el documento de Pre-QA fusionado (frontend).

REQ-291 (Consumo real desde Informe de Inspección) también verificado en vivo invocando
`InspectionReportService::save()` dos veces sobre el mismo ticket con el mismo material vinculado
— confirma que el Consumo se aplica una sola vez (RN3(c)/RN4), no en cada edición. Datos de prueba
revertidos manualmente al terminar.
