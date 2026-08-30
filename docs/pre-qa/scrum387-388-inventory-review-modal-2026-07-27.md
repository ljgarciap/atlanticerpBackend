# Pre-QA — SCRUM-387/388 (REQ-317/318, Modal de Revisión de Inventario) + SCRUM-385 (columnas de la Hoja de Picking)

**Fecha:** 2026-07-27
**Reviewer:** Pre-QA
**Alcance:** Senior Review (lectura del diff backend `d6555a2` + frontend `b3e9766`/`8318ed3`) +
Pre-QA adversarial en vivo (Docker local, Playwright contra `localhost:5173`/`8090`, tinker para
fixtures/verificación DB, PHPUnit para el módulo Bodega).
**Por qué existe este batch:** marly.rangel rebotó SCRUM-387/388 el 2026-07-26 ("no puedo probarlo
por UI, el botón está DESHABILITADO"). La causa real era más profunda que "deshabilitado":
`OrderCardTile.tsx` llamaba SIEMPRE `start-picking` para toda tarjeta `picking_pendiente`, incluso
las de `order_type=revision_inventario` — habría disparado la mutación equivocada de haber estado
habilitado. Este batch corrige eso y agrega el modal que faltaba.
**Nota:** `atlanticerp-backend/docs/` está gitignored — este archivo queda como referencia local, la
fuente de verdad persistente es el comentario en Jira.

---

## Resultado general

**SCRUM-387 y SCRUM-388: pasada limpia tras 1 CRÍTICO encontrado y corregido en la misma sesión
(loop cerrado). SCRUM-385: 1 CRÍTICO encontrado y corregido — las 2 columnas que el ticket pedía
("Ref. fábrica"/"Observación") nunca llegaron al frontend pese a que el backend ya las exponía.**

| Ticket | Resultado |
|---|---|
| SCRUM-387 (REQ-317, picking parcial → tarjeta de revisión) | Pasada limpia — confirmado end-to-end por UI real (nunca se había probado así) |
| SCRUM-388 (REQ-318, modal de Revisión de Inventario) | Pasada limpia — RN1/RN2/RN3 confirmados por UI real, 3 rupturas bloqueadas correctamente |
| SCRUM-385 (REQ-315, columnas Ref. fábrica/Observación) | CRÍTICO encontrado y corregido en esta sesión |

---

## CRÍTICO — SCRUM-385: "Ref. fábrica" y "Observación" nunca se cablearon en el frontend

El backend (`atlanticerp-backend@d6555a2`, título del propio commit: "expose factory reference and add
picking notes to picking sheet (REQ-315)") agregó `factory_reference` y `picking_notes` a
`GET .../picking-sheet` y aceptó `items.*.picking_notes` (`nullable|string|max:500`) en el
`PATCH`. Pero **ningún commit de este batch tocó `PickingSheetModal.tsx`** — el componente seguía
con el docblock original documentando el gap como "conocido y aceptado" ("se omite en vez de
inventar el dato"), sin las 2 columnas, y el `PickingSheetItem`/`PickingSheetItemPayload` del
frontend ni siquiera tenían los campos en el tipo. El test unitario promovido incluso afirmaba
explícitamente la ausencia: `it('REQ-315 RN1 — sin columna "Ref. fábrica"...')`. El propio brief
del Arquitecto para este batch asumía que las columnas "ya las agrega este batch" — Pre-QA
confirmó que solo el backend las agregó.

**Corregido en esta sesión** (`atlanticerp-frontend`, commit `e7c87f2`):
- `PickingSheetItem`/`PickingSheetItemPayload` (`types/bodega.ts`) — agregados `factory_reference`
  y `picking_notes`.
- `PickingSheetModal.tsx` — columna "Ref. fábrica" (solo lectura, fallback `—`) y columna
  "Observación" (input de texto, `maxLength=500`, mismo gate `editable` que "Alistada", persiste
  vía el `PATCH` existente al perder el foco, muestra el 422 real inline por fila si el backend lo
  rechaza).
- i18n (`es`/`en` `bodega.json`) — claves `columns.factoryRef`/`columns.observations`.
- Tests unitarios actualizados: se reescribió el test que afirmaba la ausencia de la columna, se
  agregaron 4 tests nuevos (columna presente con fallback, persistencia al perder foco, límite de
  500 caracteres, 422 real mostrado inline, modo solo lectura). 643/643 tests de frontend en verde,
  `tsc --noEmit` limpio, PHPStan Level 8 sin errores (backend no tocado).
- E2E promovido `preqa-scrum383-386-picking-flow.spec.ts` — la aserción `not.toContainText('Ref.
  fábrica')` (que codificaba el gap como comportamiento esperado) se corrigió a `toContainText`
  para ambas columnas; el resto del flujo (asignar picker → iniciar picking → completar → reabrir
  en modo solo lectura) sigue pasando sin regresión.
- Confirmado por UI real contra Docker local (fixture Order #42 "PED-E2E-PICK-01", 2 ítems): las 2
  columnas aparecen en la Hoja de Picking, "Observación" es editable solo mientras `stage =
  en_picking` y persiste correctamente.

---

## CRÍTICO (ya corregido, referencia) — botón `picking_pendiente` disparaba la mutación equivocada

Este era el hallazgo original que motivó el batch (ver reporte de la sesión 2026-07-26 en
`docs/pre-qa/scrum383-386-picking-flow-2026-07-26.md` y el commit `b3e9766`) — confirmado
corregido en esta sesión, ver checklist de ruptura abajo.

---

## Checklist de ruptura (Paso 3) — todo verificado por UI real, Docker local

| Prueba | Resultado |
|---|---|
| Tarjeta `picking_pendiente` NORMAL (`order_type=pedido`) sigue mostrando "Iniciar/Continuar Picking", nunca "Revisar Inventario" | OK — sin regresión |
| Rol Picker (`ayudante_general_bodega`): botón "Revisar Inventario" nunca aparece; SÍ ve "Iniciar Picking" (es su trabajo real, `BodegaRoles::CAN_PICK` lo incluye a propósito) | OK — confirmado por diseño, no es un hallazgo |
| Rol Picker: `POST resolve-inventory-review` directo con JWT real → 403 | OK |
| Rol Courier/Repartidor (`transporte`): botón "Revisar Inventario" nunca aparece | OK |
| Rol Courier: `POST resolve-inventory-review` directo con JWT real → 403 | OK |
| SCRUM-387 — picking parcial (qty_picked=0 de lo pedido) genera automáticamente la tarjeta de Revisión, vinculada a la misma familia | OK — confirmado por UI real, nunca antes verificado así |
| RN1 (todo encontrado) — la tarjeta pasa directo a "En picking" sin volver a la cola | OK |
| RN2 (nada encontrado) — la tarjeta pasa a "Asignado" + badge "Sin stock" | OK |
| RN3 (mixto) — genera 2 tarjetas nuevas (En picking + Asignado/sin stock), familia queda en 3 partes (original + las 2 nuevas), **sin recargar la página manualmente** (invalidación de query funciona) | OK |
| Dejar una fila en "Pendiente" y confirmar | Bloquea con mensaje claro, no deja pasar |
| Marcar "Sí hay" con ubicación vacía | Bloquea antes de pegarle al backend |
| Marcar "Sí hay" con ubicación de solo espacios en blanco | Bloquea (trim en frontend) |
| Doble clic/doble submit en "Confirmar revisión" (mixto) | Un solo `POST` real sale (verificado interceptando la red) — el botón se deshabilita correctamente tras el primer clic |
| Recargar con el modal de Revisión abierto a mitad de llenado | El modal no persiste el borrador (esperable), pero la tarjeta sigue intacta y se puede reabrir limpio |
| Observación con 501+ caracteres | Bloqueado del lado del cliente (`maxLength=500`), coincide con el `max:500` del backend |

---

## Lo que sí funciona (sin cambios)

- `InventoryReviewModal` reusa `GET picking-sheet` y postea a `resolve-inventory-review` sin
  endpoints nuevos, exactamente como documentó el batch.
- El campo "¿Dónde se encontró?" se limpia correctamente si el usuario cambia de "Sí hay" a otra
  opción antes de confirmar.
- El gate `canOperatePicking` (`modules.bodega.view_team`) sigue funcionando igual que para
  "Asignar Picker" — comportamiento consistente, sin duplicar lógica.
- `ResolveInventoryReviewResponse` (fix `8318ed3`) — forma correcta, aunque no se lee en runtime
  todavía (documentado a propósito para el próximo dev).

---

## Fixtures usados (Docker local, `atlantic_bodega.orders`)

Sembrados/reconstruidos a mano vía tinker durante esta sesión — IDs reales de esta corrida,
cambian si se resetea el schema de tenant (`infra/test.sh` comparte Postgres con el Docker local):
- `PED-PREQA387B-02`/`-03`/`-04`/`-05` — usados para RN2/RN1/control de regresión/rol
  respectivamente (ver docblock de `e2e/preqa-scrum387-388-inventory-review.spec.ts` para el
  detalle completo y los IDs vigentes al momento de cerrar esta sesión).
- `PED-PREQA387F-01` — usado para RN3 (mixto), familia resuelta en 3 tarjetas.
- `PED-E2E-PICK-01` (id 42) — reconstruido para que `preqa-scrum383-386-picking-flow.spec.ts`
  (spec permanente pre-existente) siguiera corriendo tras el reset de schema.

Se dejan como dato de prueba local — no afectan `dev.atlanticerp.ai`/`test.atlanticerp.ai`. `infra/test.sh`
los volverá a borrar en la próxima corrida de la suite de PHPUnit.

---

## Decisión

**SCRUM-387 y SCRUM-388 → `QA`** (vuelven a QA, ya habían estado ahí y marly los rebotó).
**SCRUM-385 → se reabre el hallazgo vía comentario** (el ticket ya estaba en `QA`/cerrado con las
columnas faltantes — Pre-QA lo corrigió en el momento, no hace falta reabrir el status en Jira si
ya está en un estado posterior a Dev Testing, pero se deja comentario explícito documentando el
gap encontrado y cerrado para que quede trazado).
