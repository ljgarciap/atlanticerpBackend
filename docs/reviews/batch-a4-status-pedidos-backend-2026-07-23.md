# Senior Review — Batch A4, "Status de Pedidos" (SCRUM-407→413)

**Fecha:** 2026-07-23
**Alcance:** `git diff 3bbb60e~1..3f63e77` en `atlanticerp-backend`, branch `dev` (no pusheado) — 4 commits:
`3bbb60e` (relación `SalesProject::quotes()`), `a2f42fa` (pantalla Status de Pedidos, REQ-337 a 340
y 342), `ea6b793` (documento "Estatus de Pedido", REQ-341), `3f63e77` (12 tests + helper). Todos los
demás commits locales por delante de `origin/dev` (Batch A3 y sus fixes) quedan fuera de alcance —
ya tenían su propio review (`oleada-a-batch-a3-pedidos-backend-2026-07-23.md`).

Archivos nuevos/modificados, todos leídos completos (no solo diff):
`app/Modules/Bodega/Http/Controllers/OrderStatusController.php` (nuevo, 152 líneas),
`app/Modules/Bodega/Services/OrderStatusService.php` (nuevo, 289 líneas),
`app/Modules/Bodega/Services/OrderDocumentService.php` (+41 líneas, método nuevo),
`resources/views/bodega/estatus-pedido-pdf.blade.php` (nuevo, 86 líneas),
`app/Modules/VentasDiseno/Models/SalesProject.php` (+13 líneas, relación nueva),
`routes/bodega.php` (+12 líneas), `tests/Feature/Bodega/OrderStatusControllerTest.php` (nuevo, 320
líneas, 12 tests), `tests/Feature/Bodega/Concerns/BodegaOrderTestHelpers.php` (+48 líneas,
`makeOrderWithPipelineCard()`).

## Veredicto: 🟢 Aprobado

Reporte de Backend Dev verificado como preciso en todos sus puntos — no encontré discrepancias
entre lo reportado y el código real. Sin blockers. Dos gaps de cobertura de test (no de código) y
una nota de consistencia de idioma, ninguno bloqueante. Suite completa y PHPStan Level 8 corridos
por mí, ambos limpios.

---

## Resultado real de verificación (no confío en el reporte, corrí todo yo mismo)

**`infra/test.sh` (suite completa):**
```
PHPUnit 11.5.55 — 1171 tests, 4807 assertions
OK (1171 tests, 4807 assertions)
```
Incluye los 12 tests nuevos de `OrderStatusControllerTest`, todos en verde. Corrida sobre los
contenedores Docker ya levantados (`infra-laravel-1`, `infra-postgres-1`, etc.), sin tocar
`docker compose exec` directo.

**`vendor/bin/phpstan analyse` (Level 8, 342 archivos):**
```
[OK] No errors
```

---

## Foco de revisión — resultado punto por punto

### 1. Gate de permisos dentro del controller, no en la ruta

Confirmado real y correctamente replicado. `routes/compras.php:138-140` documenta el patrón
original (`InventoryController::resolveAccess()`, líneas 533-557) para el mismo problema exacto:
"OR de 2 permisos" no expresable con un solo string de `permission:`. `OrderStatusController`
(líneas 108-129) replica la misma forma de leer `$request->attributes->get('jwt')['permissions']`,
el mismo filtro `array_filter(..., 'is_string')`, y la misma prioridad (`superadmin.all` o
`bodega.read` → acceso total; si no, `ventas_diseno.read` → restringido a `$user->id`; si ninguno →
`null` = 403). Además, `CheckPermission` middleware (usado en el resto de rutas `permission:`) lee
el JWT exactamente de la misma forma — no hay una fuente de verdad distinta para el permiso.

Escenario adversarial pedido explícitamente — vendedor A pide el detalle de un pedido de vendedor
B por id directo — **probado, no solo leído**: `test_vendedor_ve_solo_sus_propios_pedidos`
(`OrderStatusControllerTest.php:201-230`) hace exactamente eso y afirma `assertStatus(403)`
(línea 224), y el test pasó en la corrida real de arriba. No hay 404 que filtre existencia: el 404
solo se devuelve cuando el id no existe en absoluto (`resolveRoot()` retorna `null`,
`OrderStatusController.php:52-55`); un id que sí existe pero no es del actor devuelve 403
(`canAccessOrder()`, líneas 131-140, evaluado DESPUÉS de resolver el root pero ANTES de devolver
cualquier dato) — exactamente el contrato pedido.

### 2. Consolidación de familia con 3+ tarjetas

El código es correcto para cualquier tamaño de familia: `FamilyService::members()`
(`FamilyService.php:20-29`) usa `Order::where('id', $rootId)->orWhere('family_id', $rootId)`, que
no tiene límite de 2 — cualquier cantidad de hijas con `family_id = $rootId` se incluye. El agregado
en `OrderStatusService::consolidatedItems()` (líneas 189-220) opera sobre `$members->flatMap(...)`
sin asumir cardinalidad.

🟡 **Gap de test, no de código:** los dos tests que ejercitan consolidación
(`test_lista_consolida_familia_en_una_sola_fila` y
`test_detalle_consolida_pendiente_por_producto_a_traves_de_la_familia`) solo crean raíz+1 hija (2
tarjetas). Ningún test del batch prueba explícitamente una familia de 3+ tarjetas, pese a que el
foco de esta revisión lo pedía. Dado que `FamilyService::members()` es genérico y ya usado igual en
`OrderBoardService`/`completionBadge()` (sin código especial para "2"), el riesgo real es bajo, pero
recomendaría agregar un caso de 3 tarjetas (ej. picking parcial en 2 pasos + revisión de inventario)
antes de que este service se toque de nuevo, para blindar contra una futura regresión que sí asuma
2.

### 3. Anti N+1 — folio de cotización batcheado

Confirmado real, no solo leído. `bulkQuoteFolioBySalesProject()`
(`OrderStatusService.php:252-277`) hace 1 sola query (`Quote::whereIn('sales_project_id', ...)`)
para todos los proyectos del listado, agrupa en PHP y resuelve el folio por proyecto en memoria —
mismo patrón ya establecido por `OrderBoardService::bulkSupplierEtaByProduct()` (citado en el
docblock de la línea 244).

El test de regresión (`test_listado_no_escala_en_queries_de_cotizacion_con_mas_pedidos`,
`OrderStatusControllerTest.php:291-319`) crea 5 pedidos con 5 proyectos/cotizaciones distintas,
habilita `DB::enableQueryLog()`, cuenta las queries que tocan la tabla `quotes` y afirma
`assertSame(1, $quoteQueries, ...)`. **Corrí este test yo mismo** (incluido en la corrida completa
de arriba) — pasó. Nombre de owner (`vendedor`) no requiere batching aparte: viene de
`pipelineCard.owner`, ya eager-cargado en el `with()` de `list()` (línea 53), no hay query adicional
por fila ahí tampoco.

### 4. Cálculo de pendiente por producto — consolidado, no duplicado

Confirmado. `consolidatedItems()` agrupa por `catalog_product_id` (o por
`reference_snapshot|description_snapshot` para ítems personalizados sin producto de catálogo, para
no mezclar 2 ítems custom distintos en una sola línea) ANTES de sumar — un producto que aparece en 2
tarjetas de la familia entra al mismo grupo y produce UNA sola línea con `solicitada`/`entregado`
sumados de ambas tarjetas, `pendiente = max(0, solicitada - entregado)`. Verificado con test real
(`test_detalle_consolida_pendiente_por_producto_a_traves_de_la_familia`): mismo `catalog_product_id`
en raíz (6/6) e hija (4/0) → una sola línea con `solicitada=10`, `entregado=6`, `pendiente=4`. Pasó.

### 5. `disenador`/`vendedor` — misma fuente de dato

Confirmado sin lectura inconsistente. Ambos endpoints llaman al mismo helper `fullName()`
(`OrderStatusService.php:279-288`) sobre `$pipelineCard->owner` — `rowPayload()` (línea 115, listado)
y `detailPayload()` (línea 166, detalle) leen literalmente el mismo `PipelineCard.owner_id` vía la
misma relación Eloquent (`PipelineCard::owner()`, `belongsTo(User::class, 'owner_id')`). El docblock
de la línea 162-165 documenta explícitamente por qué las 2 etiquetas son el mismo dato.

### 6. Documento — paleta verde + adjunto a la familia raíz

Ambos confirmados por lectura directa del archivo, sin inferencia:
- `estatus-pedido-pdf.blade.php:6-11` usa `#5BA5A0`/`#E3F0EE`/`#3D7E7A` (paleta institucional) — no
  hay ningún color rojo en el Blade. El comentario del archivo documenta explícitamente la decisión
  de no replicar el rojo del ejemplo del cliente (RN1).
- `OrderDocumentService::generateEstatusPedido()` recibe `Order $order` ya resuelto por
  `OrderStatusService::resolveRoot()` en el controller (`OrderStatusController.php:81,90-91`) —
  nunca resuelve el root de nuevo ni acepta la tarjeta hija — y `$order->documents()->create(...)`
  (línea 75-80) crea el `OrderDocument` con `order_id` = el id de esa raíz. Confirmado también por
  test: `test_genera_documento_estatus_pedido_con_tipo_correcto` pide el documento por el id de una
  tarjeta HIJA y afirma `$document->order_id === $root->id` (línea 282) — pasó.

---

## 🟡 Suggestions (no bloqueantes)

**S1 — Sin test de ownership sobre el endpoint `document`.** Los 3 endpoints
(`index`/`show`/`document`) comparten exactamente el mismo guard (`resolveAccess()` +
`canAccessOrder()`), y ese guard ya está probado a fondo vía `show()`
(`test_vendedor_ve_solo_sus_propios_pedidos`). El riesgo real de que `document()` tenga un
comportamiento distinto es bajo porque es literalmente el mismo código compartido, pero un test
explícito de "vendedor A no puede generar el documento de un pedido de vendedor B" cerraría el caso
sin depender de que nadie recuerde que el guard es compartido si algún día se refactoriza.

**S2 — Nombres de campo del payload en español (`subcliente`, `vendedor`, `proyecto`, `disenador`,
`cliente`, `contacto`, `telefono`, `fecha_pedido`, `fecha_estatus`, `solicitada`, `entregado`,
`pendiente`).** Técnicamente en tensión con la regla de memoria "todo en inglés" (commits, tablas,
columnas, variables — `feedback_commits_idioma.md`), pero **esto no es una desviación nueva de este
batch**: reproduce exactamente el vocabulario ya establecido por
`OrderBoardService::cardPayload()` (`proyecto`, `cliente`, `vendedor`,
`fecha_entrega_comprometida`, etc.), que es anterior a esta regla y ya está en producción/dev en
el resto de la pantalla de Pedidos. Corregir el idioma solo en este batch produciría un contrato
de API inconsistente dentro de la misma familia de endpoints (`/orders`, `/orders/status`). Queda
como deuda de todo el módulo Bodega, no de este ticket puntual — anotarlo si en algún momento se
decide unificar el idioma del contrato completo de Pedidos.

**S3 — Listado sin paginar.** `list()` no pagina (`Order::whereNull('family_id')->...->get()`, sin
límite), documentado explícitamente en el código (línea 246: "sin paginar, mismo criterio que el
tablero Kanban"). Es consistente con el precedente ya establecido y aceptado del tablero Kanban de
Pedidos, así que no lo marco como blocker de este batch — pero es la misma deuda ya registrada en
`feedback_pagination_sorting_policy.md` para otras pantallas, y esta pantalla nueva la hereda tal
cual. Mencionarlo para cuando se aborde esa deuda de forma transversal.

---

## Notas adicionales verificadas (sin hallazgos)

- Sin fuga de datos comerciales sensibles: `consolidatedItems()` no expone `price_full`/`cost` en
  ningún campo del payload de items — coherente con que Ventas & Diseño puede ver sus propios
  pedidos por este endpoint.
- `OrderDocument::TIPO_ESTATUS_PEDIDO` y la columna `tipo` (string libre, sin `enum` en Postgres) ya
  existían desde Batch A1 (`d0ddae6`) — no son parte de este diff, solo reutilizados.
- `SalesProject::quotes()` apunta correctamente a `Quote.sales_project_id` (columna real, `belongsTo`
  recíproco confirmado en `Quote::salesProject()`).
- `trans('auth.unauthorized')` existe en `lang/es/auth.php` y `lang/en/auth.php` — sin riesgo de
  clave de traducción faltante.
- Diffs de `routes/bodega.php`, `OrderDocumentService.php` y `SalesProject.php` son puramente
  aditivos — sin código muerto, comentarios huérfanos ni cambios colaterales fuera de alcance.

## Siguiente paso

Sin blockers — puede pasar a Pre-QA. Los 2 gaps de test (S1, sección "Gap de test" en punto 2) son
mejoras recomendadas, no condición para avanzar; quedan a criterio del PM si se agregan antes o
después de Pre-QA.
