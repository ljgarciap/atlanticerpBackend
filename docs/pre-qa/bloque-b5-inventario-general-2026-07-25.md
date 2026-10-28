# Pre-QA — Bloque B5 "Conteos generales de inventario" (SCRUM-460→466)

**Fecha:** 2026-07-25/26
**Reviewer:** Pre-QA (subagente real, contexto aislado)
**Alcance:** Senior Review (diff de 8 commits, backend+frontend) + Pre-QA adversarial en vivo
(curl directo contra `localhost:8090`, tinker para fixtures/verificación DB, Playwright contra
`localhost:5173` para lo que curl no puede probar).
**Nota sobre este archivo:** `atlanticerp-backend/docs/` está gitignored completo en este repo — no se
puede commitear. Igual que en los Pre-QA previos de Bodega (`docs/pre-qa/bloque-b2...`,
`bloque-b3...`, `bloque-b4...`), queda como referencia local; la fuente de verdad persistente es
el comentario en cada ticket de Jira.

---

## Resultado general

**5 de 7 tickets pasan limpio. 2 hallazgos corregidos en esta misma sesión (loop cerrado). 1
hallazgo de diseño documentado como pendiente explícito para Luis/Arquitecto (no autofix).**

| Ticket | Resultado |
|---|---|
| SCRUM-460 (REQ-390) | Pasada limpia |
| SCRUM-461 (REQ-391) | Pasada limpia |
| SCRUM-462 (REQ-392) | Pasada limpia |
| SCRUM-463 (REQ-393) | Pasada limpia |
| SCRUM-464 (REQ-394) | Pasada limpia + 1 observación de diseño (no bloqueante para este ticket puntual, ver abajo) |
| SCRUM-465 (REQ-395) | **CRÍTICO encontrado y CORREGIDO** (columna "Diferencias encontradas") |
| SCRUM-466 (REQ-396) | Pasada limpia |

---

## Fixes aplicados en esta sesión

1. **`atlanticerp-backend` commit `5cc0ec5`** — `GeneralCountController::index()` no exponía
   `diferencias_encontradas` (RN3 de SCRUM-465 exige la columna). Agregado `withCount` constreñido
   (`lines as diferencias_count`, `diferencia != 0`), sin N+1. Compartido vía `formatRequest()`
   con `show()`/`evaluate()`/`submit()`/`approve()`/`reject()`/`apply()`. Test nuevo agregado
   (`test_index_expone_diferencias_encontradas_por_fila`). 23/23 tests de
   `GeneralCountControllerTest` en verde, 254/254 tests de todo el módulo Bodega en verde (sin
   regresión), PHPStan Level 8 limpio.
2. **`atlanticerp-frontend` commit `cc2c117`** — `BodegaInventarioGeneralPage.tsx` renderizaba un `—`
   fijo en la celda de "Diferencias encontradas" (el propio commit del dev lo flageaba inline como
   limitación conocida, nunca se resolvió). Ahora renderiza `row.diferencias_encontradas` real.
   Tipo `GeneralCountRow` actualizado, 6 fixtures de test actualizados + 1 test nuevo verificando
   que la celda muestra el número real y NO el placeholder viejo. 15/15 tests del archivo, 561/561
   de toda la suite frontend, `tsc --noEmit` limpio, `npm run build` limpio.

Ambos verificados end-to-end contra la app real corriendo en Docker local (curl directo al
endpoint antes/después del fix backend, Playwright contra la UI real después del fix frontend).

---

## Detalle por ticket

### SCRUM-460 (REQ-390) — Iniciar conteo, selector de bodega y carga de productos

**Escenarios de ruptura intentados:**
- Crear conteo sin `warehouse_id` → 422 con error de validación. ✅
- Verificar que solo productos con `ProductWarehouseStock.quantity > 0` en la bodega elegida
  entran al conteo (no el catálogo completo) → confirmado con 2 productos sembrados (uno con
  stock en Bodega Central, otro sin stock ahí) — solo el que tenía stock apareció. ✅
- `cantidad_sistema` poblado desde la primera carga (fix `124295e`, ya en `dev` antes de esta
  sesión) → confirmado vía `GET /{id}` inmediatamente tras `POST /` sin llamar evaluar. ✅

**CRÍTICO:** ninguno.
**MEDIO:** ninguno directo de este ticket (ver observación de diseño en REQ-394 abajo, que
comparte la causa raíz de "seleccionar bodega crea un registro server-side de inmediato").
**Lo que sí funciona:** precarga correcta filtrada por `quantity > 0`; `total_productos` en la
respuesta de creación coincide con las líneas reales creadas; `cantidad_sistema` visible desde el
primer render.

---

### SCRUM-461 (REQ-391) — Aviso de cruce con solicitud cíclica pendiente

**Escenarios de ruptura intentados:**
- Producto CON `AdjustmentRequestLine` pendiente en la misma bodega → `tiene_cruce_pendiente:
  true` + `cruce_fecha` con la fecha real de esa solicitud (sembrada con fecha 2026-06-26,
  devuelta correctamente). ✅
- Producto SIN cruce → `tiene_cruce_pendiente: false`, `cruce_fecha: null`. ✅
- Cruce respeta `warehouse_id` (una solicitud pendiente de OTRA bodega no genera aviso) — cubierto
  por test unitario existente (`test_submit_no_toca_solicitud_de_ajuste_de_otra_bodega`),
  verificado leyendo el código del criterio de cruce (`crossedAdjustmentFecha()`), no re-ejecutado
  en vivo por ser redundante con el test ya verde.

**CRÍTICO:** ninguno. **MEDIO:** ninguno.
**Lo que sí funciona:** señal de cruce + fecha exacta, scoping por bodega.

---

### SCRUM-462 (REQ-392) — Evaluar, cálculo de diferencias

**Escenarios de ruptura intentados:**
- Evaluar con 1 de 2 líneas (falta una cantidad_contada) → 422 "Debe indicar la cantidad contada
  de todos los productos del conteo", estado NO avanza (`pendiente_evaluacion` intacto). ✅
- Evaluar con un `line.id` ajeno al conteo (id inventado) → mismo 422 (RN1 exige set exacto de
  ids, ni de más ni de menos). ✅
- `cantidad_contada` negativa → 422 de validación (`min:0`). ✅
- Reevaluar (simular "editar tras evaluar") con valores corregidos → recalcula TODAS las líneas
  de cero, sin mezclar el valor viejo (probado dos veces seguidas con distintos valores, el
  segundo resultado fue el único que quedó persistido). ✅
- Evaluar un conteo ya en `pendiente_aprobacion` (ya enviado) → 422 "Este conteo ya fue enviado a
  aprobación y no se puede volver a evaluar" — no se puede reabrir tras enviar. ✅

**CRÍTICO:** ninguno. **MEDIO:** ninguno.
**Lo que sí funciona:** RN1 (todas las líneas) y RN2 (reevaluación limpia) sólidos en backend;
frontend oculta la columna Diferencia localmente al editar (`setEvaluated(false)`), consistente
con el mecanismo aunque no persiste el estado intermedio al backend (ver nota de diseño en
REQ-394 sobre el estado del panel no persistiendo).

---

### SCRUM-463 (REQ-393) — Enviar a aprobación, validaciones y manejo de cruce

**Escenarios de ruptura intentados:**
- `submit()` sin haber evaluado (`pendiente_evaluacion`) → 422 "Debe evaluar el conteo antes de
  enviarlo a aprobación", estado no avanza. ✅
- Conteo evaluado con **todas** las diferencias en 0 (RN2: "no es obligatorio tener diferencias")
  → `submit()` igual devuelve 200 y pasa a `pendiente_aprobacion` — se registra como referencia
  histórica aunque no haya ninguna diferencia real. ✅ (probado con 2 líneas, ambas diferencia=0)
- Cancelar el modal de cruce en el frontend (`CruceConfirmModal.onCancel`) → no llama
  `submit.mutate` en absoluto (verificado leyendo el código: `onCancel={() =>
  setCruceLines(null)}`, sin efecto secundario de red) y cubierto por el test unitario existente
  del archivo `.test.tsx`. No se re-ejecutó en Playwright por ser exactamente el camino que el
  test unitario ya prueba con mocks — riesgo bajo de falso verde ahí.
- Confirmar el modal de cruce → sí llama `submit`, que en la misma transacción ejecuta el cruce
  (ver REQ-394 abajo para el detalle de timing).

**CRÍTICO:** ninguno. **MEDIO:** ninguno.
**Lo que sí funciona:** RN1 (gate de evaluación previa), RN2 (registro histórico sin
diferencias), RN3 (cancelar no envía nada) — los tres confirmados.

---

### SCRUM-464 (REQ-394) — El conteo general reemplaza la solicitud cíclica al ENVIARSE

**Este era el criterio más fácil de implementar mal (timing) — verificado en base de datos, no
solo en la UI, como pedía explícitamente el encargo.**

**Escenarios de ruptura intentados:**
- Sembrada una `AdjustmentRequestLine` pendiente sobre el mismo producto+bodega. Evaluada +
  reevaluada la línea del conteo para traer de vuelta la diferencia que cruza. **Verificado en
  tinker INMEDIATAMENTE DESPUÉS de `submit()` (antes de que Aprobador tocara nada)**: la
  `AdjustmentRequestLine` y su `AdjustmentRequest` contenedora YA estaban borradas, con
  `GeneralCountRequest.resuelto_at` todavía `NULL` (prueba de que el borrado ocurrió en el envío,
  no en la aprobación). ✅ **RN1 confirmado con evidencia de base de datos, no solo de API.**
  - Bonus: sembrada una segunda `AdjustmentRequestLine` pendiente en la bodega **Merma** (no la
    del conteo) → `submit()` NO la tocó (existe test unitario para esto, no re-verificado en vivo
    por redundancia).

**Observación de diseño (no bloqueante para este ticket, informar a Luis/Arquitecto):** si Aprobador
**rechaza** un conteo general que ya consumió (borró) una `AdjustmentRequestLine` cruzada, esa
solicitud cíclica queda perdida de forma permanente — no hay manera de recuperarla ni de saber
que existió (se verificó en vivo: tras rechazar el conteo #26 de la sesión, la línea de ajuste que
había cruzado seguía borrada). El RN1 de REQ-394 dice explícitamente que el propósito es "que Aprobador
nunca vea dos pendientes contradictorios al mismo tiempo" — el ADR (`Decidido, Luis 2026-07-25`)
no contempla qué pasa si el conteo que reemplazó la solicitud es luego rechazado. Ninguno de los 7
tickets pide reversión en ese caso, así que no lo trato como hallazgo bloqueante de este batch —
lo dejo como observación para que Luis decida si es un gap a cerrar en un ticket futuro (ej.
"restaurar la solicitud cíclica si el conteo que la reemplazó es rechazado") o un trade-off
aceptado tal cual está.

**CRÍTICO:** ninguno. **MEDIO:** la observación de arriba (no bloqueante, informativa).
**Lo que sí funciona:** timing exacto — el reemplazo ocurre en `submit()`, confirmado contra la
base de datos real, no solo la respuesta HTTP.

---

### SCRUM-465 (REQ-395) — Bandeja de conteos generales

**CRÍTICO ENCONTRADO Y CORREGIDO EN ESTA SESIÓN — "Diferencias encontradas" (RN3).**
RN3 exige explícitamente las columnas "Bodega, Fecha, Realizado por, Productos contados,
**Diferencias encontradas**, Estado, Acción". El mockup adjunto (`3H__Bodega_InventarioGeneral.html`)
puebla esa columna con números reales por fila (2, 1, 1 en sus 3 filas de ejemplo). Verificado
contra la API real ANTES del fix: `GET /api/bodega/general-counts` nunca incluía ese campo — el
frontend (commit `d607eeb`) lo sabía y dejaba `—` fijo con un comentario inline reconociendo la
limitación, pero nunca lo escaló como bloqueante ni lo resolvió. Corregido en esta sesión (ver
"Fixes aplicados" arriba) — verificado de nuevo tras el fix: la API ahora devuelve
`diferencias_encontradas` con el valor correcto por fila (2, 0, 1 sobre 3 conteos reales
sembrados con diferentes combinaciones), y la UI real (Playwright, `localhost:5173`) renderiza el
número, no el placeholder.

**Otros escenarios de ruptura intentados:**
- Rechazar sin motivo (`motivo_rechazo` ausente) → 422 "El campo motivo rechazo es obligatorio",
  estado no avanza. ✅
- Rechazar con motivo vacío (`""`) → mismo 422 (el backend trata cadena vacía como ausente). ✅
- Filtro por chip de estado (`?estado=rechazada`) → solo devuelve filas de ese estado; sin filtro
  (`todas`/ausente) devuelve todo. ✅
- **Gate de aprobación — dos variantes, no solo una:**
  1. Usuario SIN el permiso `bodega.approve` en absoluto (`almacen@atlantic.com.pa`) intenta
     `approve`/`reject` vía curl directo → **403 a nivel de MIDDLEWARE** (`permission:bodega.approve`
     bloquea antes de llegar al controller). ✅
  2. Usuario CON `bodega.approve` concedido pero que NO es el `mark_approver_user_id` configurado
     (grant temporal a `gerencia@atlantic.test`, revertido al cierre) → pasa el middleware,
     pero `resolveMarkAccess()` responde 403 "Solo Aprobador puede aprobar o rechazar esta solicitud".
     ✅ Esta es la variante que realmente prueba el gate de negocio (no solo el de permisos), y
     coincide con el mismo mecanismo de `AdjustmentRequestController`/`RelocationRequestController`.
  3. Confirmado además que el candado (`IcoLock`) es SVG propio, nunca el emoji `🔒` del mockup
     (regla SCRUM-56) — visto en el código fuente, no solo en el visual review previo.
- Aprobar/Rechazar/Aplicar sobre un conteo ya `rechazada` → los tres devuelven 422 (estado
  terminal, sin re-apertura posible). No estaba explícitamente en los criterios pero es un
  comportamiento razonable y consistente con el resto del sistema.

**CRÍTICO:** "Diferencias encontradas" ausente — **CORREGIDO** (ver arriba).
**MEDIO:** ninguno adicional (el patrón de "approve sin guard de doble-click ni onError" en
`CountsTrayPanel` existe también en `BodegasPage.tsx`/reubicación — no es una regresión de B5,
es consistente con el resto de Bodega; no lo elevo como hallazgo de este batch).
**Lo que sí funciona (tras el fix):** las 7 columnas de RN3 completas y con datos reales; 4 chips
(RN4); acción por fila según estado (RN5); gate de Aprobador verificado en sus DOS variantes (sin el
permiso, y con el permiso pero sin ser Aprobador).

---

### SCRUM-466 (REQ-396) — Realizar ajuste, aplicar conteo aprobado al inventario

**Escenarios de ruptura intentados:**
- `apply()` sobre un conteo NO aprobado (`pendiente_aprobacion`) → 422 "El conteo debe estar
  aprobado para realizar el ajuste". ✅
- **Doble-click real, no simulado secuencialmente**: disparados 2 `POST .../apply` CONCURRENTES
  (jobs de shell en paralelo, no uno-tras-otro) sobre el mismo conteo aprobado. Resultado: 1
  request 200, 1 request 422 ("El ajuste de este conteo ya fue aplicado"). Verificado en base de
  datos: **exactamente 0 movimientos de Kardex** para ese conteo (todas sus líneas tenían
  diferencia=0 en ese caso de prueba) y, en un segundo conteo con diferencias reales, **exactamente
  1 movimiento por línea con diferencia != 0**, nunca duplicado. El `lockForUpdate()` +
  doble-chequeo de `aplicado_at` dentro de la transacción serializa correctamente incluso bajo
  concurrencia real (no solo llamadas secuenciales, que es lo único que cubre el test unitario
  existente). ✅
- Aplicar con diferencias reales (`+3`/`-3` sobre 2 productos) → `ProductWarehouseStock.quantity`
  actualizado exactamente a los valores contados (10→13, 20→17), confirmado en base de datos, no
  solo en la respuesta HTTP. ✅
- Verificado que "Aprobar"/"Rechazar" (SCRUM-465) **nunca** tocan el Kardex ni `aplicado_at` —
  solo `apply()` lo hace, confirmando el desacople de dos pasos que es la decisión de diseño
  central de este bloque (ADR). ✅

**CRÍTICO:** ninguno. **MEDIO:** ninguno.
**Lo que sí funciona:** idempotencia real bajo concurrencia (no solo secuencial), aplicación
correcta al Kardex y al stock, desacople aprobar/aplicar sólido.

---

## Hallazgo adicional fuera del alcance de un ticket puntual — drafts huérfanos

**No bloqueante para ninguno de los 7 tickets (ningún AC lo prohíbe explícitamente), pero
documentado porque es un patrón de datos real y reproducible que vale la pena que Luis/Arquitecto
vean.**

Cada vez que se selecciona una bodega en el panel "Nuevo conteo general", el frontend llama
`POST /general-counts` DE INMEDIATO (antes de evaluar o enviar nada). El estado del panel
(`countId`, qué bodega quedó seleccionada) es 100% local de React — no se persiste en URL ni
localStorage. Confirmado con Playwright real: seleccionar una bodega, y luego recargar la página
SIN evaluar ni enviar, dejó un conteo huérfano (`pendiente_evaluacion`) visible para siempre en el
chip "Todas" de la bandeja — 1 fila antes del reload abandonado, 2 después de un solo ciclo
seleccionar+recargar. No hay endpoint para cancelar/borrar un draft, y la fila queda sin ninguna
acción disponible en la columna "Acción" (guion fijo). En producción, cualquier reload accidental
o cambio de opinión sobre qué bodega contar deja basura permanente en el historial.

Esto no es un bug de ningún AC específico de los 7 tickets (ninguno prohíbe explícitamente drafts
sin terminar en el historial), pero sí es una decisión de producto/diseño pendiente: ¿debería
"Todas" excluir `pendiente_evaluacion`/`evaluado`? ¿Debería haber un endpoint de cancelación? ¿Debería
reutilizarse un draft existente en vez de crear uno nuevo cada vez que se cambia la bodega
seleccionada? Ninguna opción está autorizada por el ADR actual, así que no la implemento — la dejo
como pendiente explícito para escalar a Luis/Arquitecto.

---

## Resumen de reglas duras del proyecto verificadas

- Sin emoji de UI en ningún elemento renderizado (candado `IcoLock`, check `IcoCheck`, gráfico
  `IcoBarChart`) — los únicos glyphs 📊/🔒 encontrados en el código están dentro de comentarios
  que citan el mockup original, nunca en JSX ni en los JSON de i18n (verificado con regex Unicode
  sobre ambos).
- Sin `alert()`/`confirm()`/`prompt()` nativos — el modal de cruce y el de rechazo son componentes
  React propios.
- Sin umbral/margen de negocio hardcodeado en el controller (solo constantes de enum de estado).
- Gate de Aprobador verificado en sus dos variantes reales (sin permiso / con permiso pero no-Aprobador),
  igual que `AdjustmentRequestController`/`RelocationRequestController`.
