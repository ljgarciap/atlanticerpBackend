# Senior Review independiente — SCRUM-1120 CotizaYA

Fecha: 2026-10-08. Revisor: Senior Reviewer (solo lectura). Backend `bf6a7f2e`, frontend `3a967aa` (worktree fe1116).
Verificado: `infra/test.sh --filter=CotizaYaTest` pasa (26 tests, 178 aserciones). `route:list` confirma que las 12 rutas hijas (contacts 2, parts 4, items 6) existen tanto bajo `/quotes/{quoteId}` como bajo `/cotizaya/{quoteId}`, con los mismos `permission:` que antes; no se perdió ninguna ruta.

## Veredicto: APROBADO CON OBSERVACIONES

Cero bloqueantes. El aislamiento de lectura (el foco principal) resiste la auditoría. Hay 4 IMPORTANTES que conviene corregir antes del push a `dev` (los 1 y 3 son pequeños), el resto puede ir como seguimiento.

## Lo que se auditó y está bien

- Consumidores del modelo: se revisaron los ~55 archivos que usan `Quote`/`QuoteItem`/`QuotePart`. Las únicas consultas crudas sobre `quotes` son `ArchitectCommissionService` (filtrada con `q.origin = 'comercial'`) y `InvoiceService::quoteIdByQuoteItem` (join `quote_items`/`quote_parts` sobre ítems que vienen de pedidos, es decir comerciales; sin riesgo). No hay `withoutGlobalScope`, `Quote::insert/upsert` ni `DB::table('...quotes')` en `app/`.
- Relaciones y `whereHas`/`with`: pasan por el scope (`Quote` es el modelo raíz), así que `$part->quote`, `$card->quote`, `SalesProject->quotes`, etc. devuelven null/vacío para una CotizaYA fuera del contexto. Gerencia/Home/Orders/OrdersService/QuoteListService/Bodega/AdminCont usan `Quote::` y quedan cubiertos. `CreateOrderFromApprovedQuote` solo se dispara con `QuoteApproved`, que CotizaYA nunca emite.
- Jobs/comandos/colas nunca entran al contexto, así que no ven CotizaYA. `OrphanQuoteCleanupService` tampoco las ve (bien: no las borra).
- Contexto: `enter()` antes del `try`, `leave()` en `finally`, contador con `max(0, ...)`; el middleware cubre todas las rutas del grupo (también las hijas por el closure) y rechaza con 404 un id no-cotizaya. Al revés, `/quotes/{id}/...` da 404 para una CotizaYA porque `Quote::find` está con scope. FPM, no hay Octane en uso.
- Mass-assignment: `origin` está en `$fillable`, pero `CotizaYaController::validateOwnFields` solo deja pasar `cotizaya_customer_name/phone`, `description`, `observations`, `conditions_text`; `master_client_id`, `sub_client_id`, `owner_id` y `origin` no se pueden cambiar por la API (el test `el_cliente_no_se_puede_cambiar_por_la_api` lo cubre). Ningún controlador comercial hace `update($request->all())`.
- Permisos de rutas hijas: `canAccess` (dueño o vista de equipo) en partes, ítems y contactos; `ensureEditable` en partes/ítems.
- Migración: `origin` string(20) default `comercial` (NOT NULL), CHECK `comercial|cotizaya`, índice; no toca filas. Seeder: idempotente, no pisa `cotizaya_settings`, busca el maestro por `lower(name)` con `orderBy id` y reutiliza el primer subcliente, así que en prod toma 15089/15091 sin crear ni modificar nada; se salta en `testing`; `tax_id` = ''.
- Folio: serie y tabla propias, `INSERT ... ON CONFLICT DO NOTHING` + `lockForUpdate()` dentro de la transacción; no toca `quote_folio_counters`.
- PDF: Blade escapa con `{{ }}`; sin Subcliente/RUC/Proyecto para CotizaYA.

## Hallazgos

### IMPORTANTE

**I-1. Los contactos de una CotizaYA siguen siendo editables después de Generar (viola A10).**
`app/Modules/VentasDiseno/Http/Controllers/QuoteContactController.php:30` (`store`) y `:62` (`destroy`) no llaman a `ensureEditable`/no revisan `confirmed_at` (partes e ítems sí). Escenario: el vendedor genera `CotizaYA-2026-0007`, descarga el PDF, luego hace `DELETE /cotizaya/{id}/contacts/{c}` o `POST .../contacts` y el documento "fijo" cambia (el PDF regenerado muestra otros contactos). En Cotizaciones era tolerable, pero en CotizaYA el documento queda fijado por spec. Fix: rechazar con 422 si `$quote->confirmed_at !== null` (solo para origen cotizaya o general, igual que partes/ítems) y un test.

**I-2. `generate()` no es seguro ante concurrencia: puede cambiar el folio de una cotización ya generada.**
`app/Modules/VentasDiseno/Services/CotizaYaService.php:57-60`. La verificación `folio !== null` se hace sobre el modelo cargado antes de la transacción y sin bloqueo. Escenario: dos pestañas (o doble petición) llaman a `POST /generate` a la vez; ambas ven `folio = null`, ambas toman folio (`0007` y `0008`), la segunda sobrescribe el folio de la primera: queda un hueco en la serie y el folio que la primera pestaña ya mostró/descargó deja de existir. El test "generar dos veces" es secuencial, no cubre esto. Fix: dentro de la transacción, `Quote::lockForUpdate()->find($id)` (o `$quote->refresh()` con lock) y re-chequear `folio`/`confirmed_at`.

**I-3. El PDF de CotizaYA es accesible a roles de Admin&Cont fuera del alcance definido en la spec.**
La ruta `/cotizaya/{id}/pdf` reutiliza `QuoteController::pdf`, que permite además `canViewFromAdminContab` (`QuoteController.php:408` y `:652`: roles `lider_admin_contab`, `asistente_administrativa`, `management`). La spec dice que solo ven las suyas el vendedor y todas la vista de equipo; `show` de CotizaYA sí lo respeta (`CotizaYaController::canAccess`), pero `pdf` no. Escenario: una asistente administrativa con `ventas_diseno.read` pide `/cotizaya/{id}/pdf` de cualquier vendedor y recibe el PDF con precios y observaciones; además el 403/404 distingue ids. Fix: ruta `pdf` propia en `CotizaYaController` con su `canAccess`, o parámetro que anule `canViewFromAdminContab` en el contexto cotizaya.

**I-4. Ventana de despliegue: el scope hace que el código nuevo rompa TODAS las consultas de `Quote` hasta que corra la migración.**
`CotizaYaOriginScope` añade `quotes.origin <> 'cotizaya'` a cada consulta; el CD hace `up -d --build` y después `migrate`. Entre ambos pasos (segundos a minutos) Cotizaciones, Pipeline, Home, Gerencia, Pedidos y facturación responden 500 (`column origin does not exist`), y el observer consulta `cotizaya_settings` (tabla inexistente) en cada alta de Quote/PipelineCard/SalesProject/SubClient, lo que además aborta transacciones abiertas en Postgres. No es un bug de lógica, pero conviene decidirlo explícitamente: desplegar en horario bajo, o proteger el scope/observer con `Schema::hasColumn` cacheado (no recomendado), o aplicar la migración antes de reiniciar. Para prod, que el Arquitecto lo deje anotado en el plan de promoción.

### MENOR

- **M-1. Borradores huérfanos sin salida.** El borrador se crea al agregar la primera partida (`CotizaYaFormPage.tsx` `ensureDraft`); si el usuario sale, queda una fila `origin=cotizaya`, `folio=null`, que el listado oculta (`CotizaYaController.php:75` `whereNotNull('folio')`), la UI no permite retomar, y `OrphanQuoteCleanupService` (`:44`) no limpia porque el scope las esconde. Se acumulan para siempre. Sugerencia: comando/limpieza propio con `CotizaYaContext::run`, o listar borradores propios con acción "continuar/eliminar".
- **M-2. Generar parcial.** `generate` en el frontend encadena update, N contactos y generate; si falla a la mitad el borrador queda con datos parciales (reintentar es idempotente, así que no corrompe). El doble clic está protegido por `disabled={isPending}`. Aceptable; se podría mover contactos al `update` del backend en una sola transacción.
- **M-3. Observer: bypasses y falsos positivos menores.** (a) `CotizaYaClientExclusivityObserver.php:54` solo bloquea SubClient nuevos; reasignar un subcliente existente al maestro CotizaYA por `update` pasa. (b) `QuoteService::duplicate` (`:444`) copia `master_client_id` de la fuente: "usar como base" de una de las 6 cotizaciones históricas con cliente CotizaYA ahora da 422 "El cliente CotizaYA solo se puede usar en CotizaYA" (coherente con el espíritu, pero no está documentado en la spec que dice que los registros existentes "siguen funcionando"). (c) `Quote::insert`/query builder lo esquivan (no hay usos hoy). (d) Rendimiento: 1-2 consultas a `cotizaya_settings` por cada alta de Quote/PipelineCard/SalesProject/SubClient (y ninguna en updates sin cambio de cliente); despreciable, pero se podría memoizar por request.
- **M-4. Otros selectores/listados del flujo comercial aún ofrecen el cliente.** Solo se filtraron `masterClients` y `subClients` de `ClientController`. `ClientsService` (pantalla Clientes), `SubClientTaxDataService`, `Servicios\ClientLookupService`, `AdminCont\PaymentService`/`AbonoService` siguen listándolo. La spec limita A12 a Cotizaciones/Pipeline/proyectos y el observer cubre esos, pero Servicios (tickets) podría asociarlo sin bloqueo. Confirmar con Luis si el alcance debe ampliarse.
- **M-5. "Diseñador a cargo" no es elegible.** La spec (A2) lo lista como campo obligatorio; el backend fija `owner_id = usuario actual` y el formulario solo lo muestra de lectura. Si Gerencia crea la cotización queda como diseñador. Confirmar que es la intención.
- **M-6. `cotizaya_settings` sin restricción de fila única ni FK.** Si se borra/renombra el maestro, queda colgando (el contexto responde `configured=false`; las cotizaciones quedan con `master_client_id` null por `nullOnDelete` y el PDF muestra "—" o el nombre libre). Riesgo bajo, sin pantalla de borrado.
- **M-7. Descarga de PDF sin manejo de error** (`cotizaYaPdf.ts`/`CotizaYaDetailPage.tsx` `handlePdf` usa `try/finally` sin `catch`): un 403/404 produce un rechazo de promesa no capturado y ningún mensaje al usuario. El PDF de un borrador (folio null) se puede pedir por API y baja como `Cotizacion-.pdf`.
- **M-8. Clave de caché compartida.** El formulario y el detalle usan `['ventas-diseno-quote', id]` con `cotizaya.get`; Cotizaciones usa la misma familia de claves con `quotes.get`. Los ids son únicos en la tabla, así que no hay colisión real hoy, pero es frágil: conviene un prefijo `['ventas-diseno-cotizaya', id]` (hay que adaptar las invalidaciones de `QuotePartCard`).
- **M-9. Información por 404.** El middleware responde 404 antes de evaluar `permission:`, de modo que un usuario sin permiso ve 404 en vez de 403 (no filtra datos, solo existencia de id/origen).

### Huecos de pruebas

- Falta un test del camino de producción del seeder: maestro `COTIZAYA`/`CotizaYA` y subcliente ya existentes, comprobar que `ensureClient()` los reutiliza sin duplicar ni modificar (el seeder se salta en `testing`, así que solo `ensureClient()` se prueba y siempre desde cero).
- Falta cubrir un vendedor contra el CotizaYA de otro por las rutas hijas (`parts`, `items`, `contacts`, `pdf`); solo se prueba `show` (403).
- Falta una prueba de que el contexto se apaga tras una excepción dentro de la request (el test actual solo verifica el caso feliz), y una de que Home/Gerencia/Pedidos/comisiones no cuentan una CotizaYA (solo se prueba el modelo y el listado de Cotizaciones; la spec P2 pedía un test por consumidor relevante).
- Falta test de contactos/generate concurrente (I-1, I-2).
- Frontend: sin test de fallo a mitad de Generar ni del error de PDF.

## Resumen para el Arquitecto

Corregir antes de pushear: I-1, I-2, I-3 (cambios pequeños y localizados) y dejar decidido I-4 en el plan de despliegue. M-1, M-4 y M-5 requieren decisión de Luis o ticket de seguimiento. Tras corregir I-1..I-3 con sus tests, no hace falta una nueva ronda completa; basta una re-verificación puntual.
