# Senior Review independiente — SCRUM-1116 (delta Pre-QA) + PR #7/#8 (proyecto opcional en tickets de Servicios)

Fecha: 2026-10-07. Revisión solo lectura (sin tests ni docker; se confía en los gates verdes reportados).

## Veredictos

| Bloque | Veredicto |
|---|---|
| A — arreglos de las 3 observaciones del Pre-QA de SCRUM-1116 (`1db8ede2` backend, `50874c4` frontend) | **APROBADO** (1 BAJO opcional) |
| B — PR #8 backend (`b598d377..27edf5ce`) + PR #7 frontend (`2e18058..649f6b5`) | **APROBADO CON OBSERVACIONES** (1 MEDIO de pruebas, 2 BAJO) |

---

## BLOQUE A

### Análisis de `hayExcedente` (RegisterCreditNoteRequest.php:49-64)

Nueva condición: `monto_efectivo <= acreditable && monto_efectivo > saldo_pendiente`.

- **No abre hueco.** El caso excluido (`monto > acreditable`) lo rechaza siempre `validarValorAcreditable()` (mismo Request, hook `after`, línea ~130-160) por el campo `monto`, y además `CreditNoteService::register()` (CreditNoteService.php:259-285) repite la misma comparación `round($monto - $acreditable, 2) > 0.0` bajo `lockForUpdate` ANTES de evaluar `resultado` (línea ~290). Por tanto una nota con excedente sin `resultado` solo puede llegar a persistirse si cabe en lo acreditable, y en ese caso el Request sí exige `resultado`; el Service lo vuelve a exigir como red (422 `resultado`).
- **Comparación consistente:** Request y Service usan `round(x - acreditable, 2)` con el mismo servicio (`CreditableBalanceService::valorAcreditableRestante`) y el mismo `saldoPendiente = monto - saldo_aplicado`. Sin diferencias de redondeo ni de definición.
- **Anulación completa:** `montoEfectivo == acreditable`, así que la primera cláusula es siempre verdadera (resta 0) y el comportamiento queda idéntico al anterior; si `acreditable <= 0` se rechaza por `factura_origen_id` (mensajeSinSaldo) en ambos lados.
- **Factura cobrada:** `saldoPendiente < acreditable` (los cobros no reducen lo acreditable). Con monto que cabe en lo acreditable pero supera el saldo por cobrar, `hayExcedente` sigue verdadero y pide `resultado` (cubierto por el test nuevo `test_un_excedente_legitimo_dentro_de_lo_acreditable_sigue_pidiendo_el_resultado` y por `test_excedente_sin_resultado_es_rechazado`).
- **Carrera:** si entre la validación del Request y el bloqueo cambia el acreditable/saldo, el Service es la autoridad (re-valida bajo lock); correcto.
- Monto no numérico / factura inexistente: `montoInput = 0`, `acreditable = 0` → `hayExcedente` falso, y el error lo reportan las reglas `exists`/`numeric`. Sin regresión.

### `correccionContext()` (CreditNoteService.php:1264-1276)

- Consulta única con `orderBy('id')`, distingue por `correccion_pendiente_aprobacion` (columna boolean, cast boolean en el modelo) y conserva el mensaje original cuando hay una corrección pendiente. El bloqueo no cambia (sigue lanzando `CreditNoteStateException` ante cualquier pendiente); solo el texto. Correcto. Test nuevo verifica número de la nota y ausencia de "corrección pendiente".

### Frontend `50874c4` (NotasCreditoPage.tsx:75-78)

- `monto_acreditable` / `notas_previas_total` existen como opcionales en `NotaCreditoDevolucionDetail` y `NotaCreditoFacturaOrigen` (adminContab.ts:1083-1085, 1175-1177, 1326-1327) y el modal los consume (RegistrarNotaCreditoModal.tsx:194-195, 290, 733). `??` prefiere el dato de la devolución y cae a la factura; correcto. El test de página ejercita el camino (aviso/tope visibles). Nota: es un test de humo (`getByTestId('nota-credito-acreditable')`); no afirma el valor del tope, aceptable.

### Hallazgos A

- **BAJO** — CreditNoteService.php:1283: el comentario sigue diciendo "Las `pendiente_aprobacion` ya las cubre `$yaPendiente`", variable renombrada a `$pendientes` en este mismo commit. Fix: actualizar el comentario. No bloquea.

Sin CRÍTICO/ALTO/MEDIO. Los dos tests existentes cambiados (400→300) son legítimos: el escenario original era una nota mayor que su factura, que el ticket prohíbe y ahora fallaría por `monto`; el excedente real se sigue probando con 300 sobre factura cobrada de 300.

---

## BLOQUE B

### Contenido real del diff (solo lo anunciado)

- Backend (6 archivos, +19/-15): `StoreTicketRequest` (`required` → `nullable`), `TicketService::create()` (resuelve proyecto solo si viene id), anotaciones de tipo en `TicketController`/`TicketService`, `TicketFlowTest` (test renombrado), docs (`Servicios/CLAUDE.md`, `servicios-fase4-diseno.md`). Nada más.
- Frontend (3 archivos, +28/-7): `TicketCreateModal.tsx` (`project?.id ?? null`, `canSave` sin proyecto), `types/servicios.ts` (`number | null`), test del modal. Nada más.
- El nombre de la rama (`delete-project-files`) no corresponde al contenido: ninguna ruta/archivo de borrado de archivos de proyecto aparece en el diff contra dev (los merges de `origin/dev` dentro de la rama son vacíos para este alcance). Confirmado.

### Verificaciones

- **Columna nullable:** `database/migrations/tenant/2026_08_02_100001_create_servicios_tickets_table.php:51` — `foreignId('sales_project_id')->nullable()`. Sin migración nueva necesaria. Ya existían tickets legados con `null`, por lo que el código ya debía tolerarlo.
- **Validación:** `['nullable','integer', Rule::exists('sales_projects','id')]` es correcta: `null`/ausente pasa, un id inexistente falla `exists` (422), no entero falla `integer`. `$data['sales_project_id'] ?? null` cubre la clave ausente.
- **Consumidores backend (todos tolerantes a null):**
  - `TicketService::detail()` usa `salesProject?->subClient`, `?->name`; `cliente_master`/`subcliente` caen a `$ticket->cliente` (null) → los PDFs (`ticket-pdf`, `claim-sheet-pdf`, `service-quote-pdf`) imprimen `'—'` (`null === null` en la comparación master/sub queda en la rama de un solo campo).
  - `TicketService` búsqueda (línea 646) `cliente ILIKE` con NULL no rompe (la condición es NULL/falsa y el `OR` con N° ticket sigue funcionando).
  - `HomeService` (148, 187), `InternalTechnicianService` (321, 395), `ExternalTechnicianService` (179), `ServiceQuoteService` (157), `ServiceQuoteController` (285), `ReportsService` (377, 394): solo pasan `$ticket->cliente` a arrays/JSON, sin tipos estrictos ni funciones de string. `ProductLookupController` ya trataba `sales_project_id` como opcional. Evento `TicketCreated` no incluye cliente/proyecto.
  - No hay `orderBy/groupBy` por `cliente` en consumidores de tickets.
- **Frontend:** los tipos ya eran `cliente: string | null` / `cliente_master: string | null`; `TicketDetailModal` usa `?? '—'`; `TicketsPage` filtra con `(ticket.cliente ?? '')`; `TicketBoard` usa `?? undefined` en el title; el buscador de productos del modal ya usaba `project?.id`. Sin `project!` residual.

### Hallazgos B

- **MEDIO — falta el test del caso "proyecto inexistente → 422".** `tests/Feature/Servicios/TicketFlowTest.php` (~líneas 154-167): solo se cubre sin proyecto (201, `sales_project_id` null). Al relajar `required` a `nullable` la regla `exists` es la única barrera contra un id basura; si alguien la quita, ningún test fallaría (antes `required` y el 422 de ausencia lo enmascaraban). Fix: agregar `test_crear_ticket_con_sales_project_id_inexistente_retorna_422` (`sales_project_id => 99999999`, `assertStatus(422)->assertJsonValidationErrors(['sales_project_id'])`) y, idealmente, uno con proyecto válido que afirme que `cliente` se deriva del subcliente (probablemente ya existe en otro test del archivo; verificar). Es un gap de cobertura, no un bug.
- **BAJO** — `TicketTable.tsx:67` renderiza `{ticket.cliente}` sin fallback: un ticket sin proyecto muestra celda vacía (el tablero y el detalle sí muestran "—"). Fix cosmético: `{ticket.cliente ?? '—'}`.
- **BAJO (decisión de producto, informativa)** — En `TicketCreateModal` el usuario puede elegir Cliente Master y Subcliente pero no Proyecto; el backend solo deriva `cliente` del proyecto, así que ese ticket queda sin cliente aunque el usuario lo haya elegido en pantalla (el snapshot se pierde). Es consistente con la decisión aprobada ("sin proyecto el ticket queda sin cliente", documentado en `Servicios/CLAUDE.md`), pero conviene que Luis lo conozca; si no es lo deseado, habría que enviar `sub_client_id`/`master_client_id` (cambio de diseño, no de este PR).
- Residual menor: e2e preexistentes con `sales_project_id` (`e2e/preqa-*`) no fueron revisados uno a uno; ninguno prueba el 422 por ausencia según el nombre, pero conviene confirmar en la corrida de gates.

Sin CRÍTICO/ALTO.

## Acciones sugeridas antes de empujar

1. (Recomendado) Agregar el test 422 por `sales_project_id` inexistente en `TicketFlowTest`.
2. (Opcional) Corregir comentario `$yaPendiente` y el fallback `'—'` en `TicketTable.tsx`.
3. Ninguna de las observaciones exige re-correr Senior Review completo; sí repetir la suite del módulo Servicios tras agregar el test.
