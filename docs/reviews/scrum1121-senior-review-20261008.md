# Senior Review SCRUM-1121 — Base unificada de asociados (2026-10-08)

Alcance: backend `5253c900` (P1) y `dd56e91a` (P2); frontend `da21d90` (P3). Solo lectura, sin correr suites.

## Veredicto: APROBADO CON OBSERVACIONES (ninguna es bloqueante; hay 6 IMPORTANTE, sobre todo 1-4, que conviene corregir antes del Pre-QA)

Las comisiones de Admin&Cont NO se rompen: `quotes.architect_id` no cambia de significado, `GET /architects` sin `type` sigue devolviendo solo arquitectos, y los consumidores de AdminCont (`ArchitectCommissionService`, `CashPositionService`, `PendingAlertsService`, `AdminContArchitectCommissionController`) leen `Architect` por id, sin tocar las columnas nuevas. `show()` filtra por `quotes.architect_id`, intacto. Migraciones: default `architect`, CHECK, `down()` simétrico, FK con cascade, mismo patrón `table()` que `quote_contacts`, timestamps posteriores a CotizaYA (110000/110001 > 100000/100001).

## BLOQUEANTE
Ninguno.

## IMPORTANTE

1. **Editar un asociado con duplicado histórico queda bloqueado para siempre** — `ArchitectController::update` (~l.190) llama `find(..., includeSimilar:false)` con los 3 identificadores aunque no cambien. La spec admite que ya existen duplicados históricos. Escenario: arquitecto A y B comparten teléfono (dato viejo); Daniela corrige el nombre de A (PUT con name+phone) y recibe 409 `duplicate_exact`, sin forma de arreglarlo. Fix: en `update`, comparar solo los identificadores que CAMBIARON respecto del registro actual (o ignorar los que no cambiaron).

2. **`PUT /architects/{id}` permite cambiar `type` de un arquitecto ya usado en cotizaciones** — `UpdateArchitectRequest` (`type` sometimes). Escenario: se pasa un arquitecto con comisiones a `designer`: desaparece de la pantalla Arquitectos (índice sin `type` = solo architect) y, peor, cada vez que se guarde una cotización con ese `architect_id` `QuoteRequest` (`exists ... where type=architect`) devuelve 422 y la cotización no se puede guardar. Fix: rechazar (422) el cambio de `type` si existe `quotes.architect_id = id` (o no aceptar `type` en update).

3. **`QuoteService::update` re-agrega al arquitecto de la cotización en cada guardado (resurrección)** — `QuoteService.php` ~l.227-228: `syncQuoteArchitect` corre siempre que `$updates !== []`, no solo cuando `architect_id` cambió. Escenario: la cotización tiene `architect_id = X`; alguien quita a X de Asociados (tarjeta o cotización); en el siguiente "Guardar borrador" (el front envía el formulario completo) X vuelve a aparecer en el proyecto. Fix: llamar `syncQuoteArchitect` solo si `array_key_exists('architect_id', $data)` (y cambió) o cuando se asignó/cambió `sales_project_id`; en `create()` está bien. Además, la UI deja quitar de la lista al arquitecto que sigue marcado en el campo «Arquitecto» (comisión) sin aviso: confirmar o bloquear ese caso.

4. **Los endpoints de cotización no respetan el estado de la tarjeta** — `AssociateLinkController::storeForQuote/destroyForQuote` solo validan dueño/equipo; la ruta de tarjeta sí exige `isEditableBy` (Perdido/Aprobado). Escenario: tarjeta Aprobada o Perdida (no editable); el dueño de una cotización del mismo proyecto agrega/quita asociados del proyecto por `/quotes/{id}/associates`, esquivando la regla. Además, como el vínculo es del proyecto, el dueño de la cotización A modifica lo que ve el dueño de la cotización B del mismo proyecto. Fix: en las rutas de cotización, resolver `PipelineCard::where('sales_project_id', ...)` y aplicar `isEditableBy` cuando exista; y considerar bloquear en cotizaciones confirmadas.

5. **Búsqueda sensible a acentos** — `ArchitectController::index` usa `ilike` sobre name/email/document: "perez" no encuentra "Pérez" (A3, autocompletado de nombres en español). El resto del módulo usa `public.f_unaccent(lower(...))`. Fix: `public.f_unaccent(lower(name)) like public.f_unaccent(lower(?))` con el término escapado.

6. **Alta desde el campo «Arquitecto» de QuotePage sin salida ante 409** — `QuotePage.tsx` `createArchitect` usa `ClientPicker.onCreate`, que solo muestra el `message` del servidor. Con `duplicate_exact` no ofrece «Usar este»; con `duplicate_similar` (homónimo legítimo) nunca envía `confirm_similar`, así que es imposible crear un segundo "Juan Pérez" desde ese picker (antes sí se podía). Fix: manejar `err.response.data.duplicates` en el `onCreate` (devolver el existente si es exact; reenviar con `confirm_similar` tras confirmar si es similar), o reutilizar `CreateAssociateModal`.

## MENOR

- **Normalización PHP vs SQL del nombre** — `AssociateDuplicateFinder::stripAccents` cubre solo un subconjunto (á é í ó ú ü ñ ç...); `f_unaccent` de SQL cubre todo (ã, õ, å, š, ø...). "João" (SQL "joao") vs entrada "João" (PHP "joão") no coincide: falso negativo. Fix: normalizar también el parámetro con `public.f_unaccent(lower(?))` en SQL.
- **Valores basura como identificadores** — documento "N/A", "0000", "NA" se normaliza a "na"/"0000" y bloquea (409) a personas distintas; igual con teléfonos de 1-3 dígitos. Considerar un mínimo de longitud tras normalizar o ignorar placeholders.
- **Carrera entre dos altas simultáneas** — no hay índice único (justificable por los duplicados históricos), así que dos POST simultáneos con el mismo correo pasan ambos. Mitigación barata: `pg_advisory_xact_lock` por identificador dentro de una transacción en `store`. Sin ello, el `pending` del front solo cubre el doble clic.
- **Escape de `search`** — `%` y `_` no se escapan en `ilike`/`like` (sin inyección: va por binding), pero "_" o "%" devuelven todo. Usar `addcslashes($term, '%_\\')` como hace `BodegaInventoryController`.
- **Costo** — `ilike '%x%'` y `regexp_replace(...)` sin índices funcionales: seq scan; aceptable con cientos/miles de filas (`limit` 1..50, tope 500 global). Anotar si la base crece. `limit` y `sub_client_id` se pisan (el `limit` explícito gana): inofensivo.
- **PII** — `document` (identificación) ahora lo devuelve `GET /architects` a cualquier `ventas_diseno.read`, y los 409 devuelven datos del existente. Aceptable por la spec (A11), conviene confirmarlo con Luis.
- **`POST /architects` (`write`) crea sin subcliente** — sin límite de frecuencia; riesgo de ruido/duplicados mitigado por el 409 y por `created_by` + auditoría. Sin acción obligatoria.
- **`TestDataCleanupService`** — un `Architect` candidato (por su `sub_client_id` de origen) ahora puede estar vinculado a proyectos vivos de otros clientes; su borrado hace cascade silencioso sobre `sales_project_associates`. Su guard (`guardSubClients`) no mira los pivotes. Comando aún no ejecutado con `--force`; revisar antes de usarlo.
- **Comportamientos por diseño a documentar/avisar** — quitar un asociado desde una cotización lo quita del proyecto y de las demás cotizaciones; al cambiar `sales_project_id` A→B los asociados quedan en A (la cotización pasa a ver los de B); `duplicate()` mantiene los asociados vía proyecto, pero un borrador sin proyecto no copia `quote_associates`. Correcto frente a la spec; el front no avisa en el quitar.
- **Front** — `unsavedHint` (es/en) no se usa; en QuotePage los asociados se guardan contra el proyecto persistido, no el elegido sin guardar en el formulario; `CreateAssociateModal` sin Escape/foco inicial/trap (a11y básica) y la lista de resultados sin `role=listbox`; tras crear no se invalida `['ventas-diseno-architects']` (la pantalla Arquitectos refresca al montar). Si `add` falla tras crear, el asociado queda creado y solo se ve "No se pudo agregar" (reintentable por búsqueda). Voseo ("Indicá/Intentá") consistente con el archivo existente.
- **Tests faltantes** — editar con duplicado histórico (Imp. 1), cambio de `type` con cotizaciones (Imp. 2), resurrección por guardado (Imp. 3), estado de tarjeta vía `/quotes/.../associates` (Imp. 4), búsqueda con acentos, `%`/`_`, homónimo con `confirm_similar` desde el picker de Arquitecto.
- **Convenciones** — sin emojis, sin umbrales de negocio hardcodeados; los 6 tipos están triplicados (modelo, CHECK, front), consistente con la spec ("lista cerrada").

## Confirmado sin hallazgo
- `mergeDraftIntoProject`: transacción, idempotente (sin proyecto o sin borrador no hace nada), `syncWithoutDetaching` sin duplicar.
- CotizaYA: `Quote::find` oculta esas cotizaciones (404) y el detalle devuelve `associates: []`.
- IDOR: dueño/equipo en cotización y tarjeta, `.edit` en rutas; `architect_id` validado con `exists`; multi-tenant por schema (no aplica cruce de tenant).
- `GET /architects?type=` inválido responde 422; sin `type` solo arquitectos.
