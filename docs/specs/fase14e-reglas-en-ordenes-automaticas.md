# Fase 14e — Reglas de aprobación también en las órdenes que se crean solas

**Estado**: decisiones de §5 tomadas por Luis (2026-10-30); **implementada** (§8), pendiente de mezclar. Origen: el «Abierto» de §8 de `fase14-parametrizacion.md` (14a). No se implementa sin su «adelante».

## 1. Objetivo
Las reglas de aprobación de órdenes de compra (14a) solo se evalúan en «Nueva orden» (`PurchaseOrderController`). Otros dos flujos crean órdenes por su cuenta y se las saltan, así que una regla por monto (p. ej. «más de $5.000 requiere aprobación») no los alcanza. Que la regla valga igual en todo lo que crea una orden, salvo donde exista otro control propio.

## 2. Hallazgos (medidos sobre `master`)
| Flujo | Dónde | Hoy |
|---|---|---|
| Nueva orden | `PurchaseOrderController::store/update` → `applyApprovalRule()` | Evalúa las reglas con el total a pagar y fija estado inicial. |
| **Insumos de Servicios** (REQ-274) | `InsumoService::requestPurchase` | Nace `pendiente_liquidar`, `requires_primary_approval = false`, sin envío. Nunca evalúa. |
| **Reposición por familia** | `InventoryController::generateFamilyPurchase` | Nace `por_aprobar` con `requires_primary_approval = false` (no bloquea: `isBlockedByPrimaryApproval()` es falso), una orden por proveedor. El estado «Por aprobar» no corresponde a ninguna aprobación real y a los 3 días figura como crítica. |
| Zona Libre | `ComprasZonaLibreRequestController` | Nace `por_aprobar`, `false`. Tiene su propia aprobación (la solicitud la decide quien tiene `compras.zona-libre.approve`, con aprobador/suplente) antes de crear la orden. |

Con la regla sembrada (aéreo pagado por la empresa) ni Insumos ni Reposición cambian: no capturan tipo de envío. El hueco solo aparece al crear una regla por monto o por pagador.

## 3. Diseño propuesto
- Mover `applyApprovalRule` del controlador a un servicio compartido (`PurchaseApprovalRules::applyTo(order, initialStatus)`), con el mismo comportamiento de 14a (primera regla activa; total a pagar; al crear fija «Por aprobar» si la requiere).
- **Insumos**: tras crear la orden y su línea, llamar al servicio. Si la regla la requiere, nace `por_aprobar` y bloqueada hasta que el aprobador la apruebe; la solicitud de insumo sigue `pendiente` y el listener de recepción no cambia.
- **Reposición por familia**: ídem por cada orden creada (después de guardar las líneas y el total). Si no la requiere, nace `pendiente_liquidar` en vez de `por_aprobar` (el estado deja de mentir).
- **Zona Libre**: sin cambio (decisión 3).
- Auditoría: ya cubierta por `LogsAuditTrail`. Sin esquema nuevo ni migración.

## 4. Criterios de aceptación
1. Con una regla «monto ≥ X», una solicitud de insumo cuyo total llega a X crea la orden en `por_aprobar` con `requires_primary_approval = true`; por debajo, `pendiente_liquidar` sin aprobación.
2. Ídem para cada orden de «generar compra de una familia».
3. Una orden de insumo pendiente de aprobar no avanza hasta que el aprobador (o suplente) la aprueba; al aprobarla sigue el ciclo normal y, al recibirla, reserva como siempre.
4. Con solo la regla sembrada no cambia ningún estado de Insumos ni de Reposición, salvo el de la Reposición sin aprobación (decisión 2).
5. Nueva orden y Zona Libre se comportan igual que hoy (tests existentes sin cambios).
6. PHPStan nivel 8 limpio, suites de Compras y Servicios en verde.

## 5. Decisiones de Luis (2026-10-30)
1. **Insumos**: las reglas de aprobación **sí aplican** a las órdenes internas de reserva. ✅
2. **Reposición por familia**: las que no requieren aprobación **nacen `pendiente_liquidar`** (ya no «Por aprobar»). ✅
3. **Zona Libre**: se **retira** del núcleo (Fase 14f), así que queda fuera de esta fase y no se toca aquí. ✅
4. **Entrega**: 1 PR de backend (propuesta, sin frontend).

## 6. Riesgos
- Una solicitud de insumo grande puede quedar esperando aprobación y retrasar la reserva de Servicios: es el efecto buscado, pero conviene avisar al Líder de Servicios (la tarjeta de la solicitud ya muestra la orden y su estado).
- Cambiar el estado inicial de la Reposición altera los contadores de «Por aprobar» del inicio de Compras (bajan).

## 7. No-alcance
Frontend, nuevos permisos, evaluación al editar una orden ya creada (queda como en 14a) y recálculo en bloque de órdenes existentes.

## 8. Resultado
- **Servicio compartido**: `PurchaseApprovalRules::applyTo(order, initialStatus)` (antes un método privado de `PurchaseOrderController`) evalúa con el total a pagar y fija `requires_primary_approval`; con `initialStatus` pasa la orden a «Por aprobar» si la requiere. «Nueva orden» delega en él sin cambiar su comportamiento.
- **Insumos** (`InsumoService::requestPurchase`) y **reposición por familia** (`InventoryController::generateFamilyPurchase`) lo llaman tras guardar líneas y total. La reposición ya nace `pendiente_liquidar` y solo pasa a «Por aprobar» (y bloqueada) si la regla lo exige.
- **Efecto con la regla sembrada** (aéreo pagado por la empresa): ninguno en Insumos; en reposición, el estado inicial cambia a `pendiente_liquidar`.
- **Verificación**: 3 tests nuevos (`InsumoReservaTest`, 2 en `InventoryControllerTest`); suites de Compras/Servicios afectadas en verde y PHPStan 0.
- **Nota de pruebas**: el servicio guarda las reglas en memo por petición; un test que crea una regla después de evaluar debe llamar a `forget()` (en producción la memo se descarta sola).
