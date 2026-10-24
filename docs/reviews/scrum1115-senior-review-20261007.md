# Senior Review independiente — SCRUM-1115 (nueva versión desde Lead/Diseño)

Fecha: 2026-10-07. Revisión solo lectura (sin tests, gates ni docker).
Alcance: backend `8352dabb`, frontend `4699413` (rama `scrum1115-fe`), spec `docs/specs/scrum1115-nueva-version-desde-diseno.md`.

## Veredicto: APROBADO CON OBSERVACIONES

0 críticos, 0 altos. El cambio es mínimo, coherente con la spec y no abre ningún camino a Cotización sin una cotización real confirmada. Las observaciones no bloquean.

## Verificaciones pedidas

1. RN5 intacta. `PipelineCard::validationErrorForTransitionTo` (Models/PipelineCard.php ~279-287) no se tocó en el commit; el PATCH `design -> quote` sigue devolviendo 422. `duplicate()` solo crea un borrador (no toca la tarjeta) y `confirm()` ya exigía folio + `resolveLinkedCardForConfirm`. El test A8 sí demuestra RN5: recorre `lost -> lead -> design` por la API real, hace el PATCH a `quote`, exige 422, el texto de la regla y que la etapa siga en `design`. No se abre ningún atajo: el único camino a `quote` sigue siendo confirmar una cotización.
2. Confirmar la versión nueva con la tarjeta en Lead/Diseño: `confirm()` -> `resolveLinkedCardForConfirm` (lead/design/quote pasan; proposal/approved/lost se bloquean) -> `advanceCardToQuoteStage` (`currentIndex < quoteIndex` -> `changeStage('quote')`, con `lockForUpdate` en la fila). A2 lo prueba desde Diseño (etapa `quote`, 2 versiones). Bordes:
   - Dos borradores simultáneos / duplicar dos veces: cada duplicate crea un borrador independiente; confirmarlos genera v2 y v3, y `isNewVersion` se calcula bajo el advisory lock. Sin problema. El botón se deshabilita con `isPending` (no hay doble clic).
   - Duplicar sobre una versión vieja: permitido igual que antes (el controller lo documenta); el diálogo del Pipeline siempre usa la última.
   - Tarjeta null: mensaje nuevo "Solo se puede crear una versión nueva... con un proyecto en el Pipeline". `linkedPipelineCard()` devuelve null si `sales_project_id` es null o no hay tarjeta; ambos casos caen en ese mensaje. Antes el mensaje decía "etapa Cotización"; ahora es correcto. Ver M-2: sin test.
   - Cotización de otro dueño: ver M-1.
3. Concurrencia: `duplicate()` no toma lock y lee la etapa fuera de la transacción, pero es solo lectura de la tarjeta y crea un borrador; la compuerta real está en `confirm()`, que re-valida la etapa bajo `pg_advisory_xact_lock(sales_project_id)`. Ver B-1.
4. Gates previos: `proceedToCreateQuote()` se invoca solo al final de `handleCreateQuoteClick`, después de contacto/master/sub (Lead) o superficie/archivo (Diseño). Cubierto por test A9 (sin superficie no hay diálogo). "En blanco" navega exactamente a la URL anterior; sin `latest_quote_id` no hay diálogo (A7).
5. Consistencia BE/FE: backend permite lead/design/quote; `canCreateNewVersion` en `QuoteViewerModal` lo mismo (tabla y caso de 1 versión); el botón "Crear cotización" del modal de tarjeta solo existe en lead/design. Perdido/Propuesta/Aprobado bloqueados en ambos lados.
6. Tests: coherentes con lo que dicen (ver observaciones M-2 y B-3 sobre huecos, no sobre falsos positivos). Conteo de 10 tests del commit verificado.
7. i18n es/en: mismas 6 claves en ambos idiomas. Sin emojis ni iconografía nueva. Commits en español con formato `tipo(scope)`. Sin umbrales de negocio hardcodeados. Ver B-2 (voseo).
8. `latest_quote_id` y `latest_quote_folio` salen de una única consulta (`orderByDesc confirmed_at`, `orderByDesc id`, `first(['id','folio'])`): misma cotización por construcción. El desempate por `id` es además consistente con `versionsFor`. Cubierto por `test_p2_...` (id y folio de la última).

## Hallazgos

### MEDIO

**M-1. Dueño de la tarjeta distinto del dueño de la cotización: 403 con diálogo sin salida clara.**
`QuoteController::duplicate` (línea ~488-510) exige `canAccess` (`canViewTeamV2` o `owner_id === user->id` de la COTIZACIÓN). La tarjeta puede ser de un usuario (owner reasignado, o la cotización la creó otro) que no es dueño de la última cotización y no ve el equipo. Ese usuario abre su tarjeta restaurada, pulsa "Partir de COT-xxxx" y recibe 403 con mensaje genérico `auth.unauthorized`, que `PipelineCardModal` muestra en el diálogo (el botón "En blanco" sigue disponible, así que no queda bloqueado). El chequeo de dueño NO se abre indebidamente (se mantiene cerrado), pero el diálogo lo ofrece de todos modos y `latest_quote_folio` expone el folio de una cotización que ese usuario no puede abrir (`latest_quote_id` ya se exponía antes).
Escenario: vendedora A creó COT-0003; Pipeline reasigna el proyecto a vendedora B; B restaura desde Perdido y pulsa "Partir de".
Fix sugerido (no bloqueante): que el diálogo traduzca el 403 a un texto explícito ("Solo el responsable de la cotización o un supervisor puede partir de ella; empieza en blanco o pídeselo a ...") o que el backend devuelva `latest_quote_folio` solo si el usuario puede acceder a esa cotización. Confirmar con Luis si el responsable de la tarjeta debería poder duplicar (decisión de negocio, no se cambia aquí).

**M-2. Hueco de pruebas en los bordes nuevos del backend.**
`QuoteNewVersionFromDesignTest.php`: no hay test para (a) tarjeta null / `sales_project_id` null (rama nueva de `duplicate`, mensaje nuevo); (b) confirmar la versión nueva desde Lead (A3 solo prueba que duplicate responde 201, la spec dice "lo mismo" para A2; el salto `lead -> quote` de `advanceCardToQuoteStage` se ejercita en otros tests de confirmar, pero no con este flujo); (c) `QuoteConfirmed` con `es_nueva_version = true` (A2 de la spec lo exige y el test solo cuenta 2 confirmadas); (d) cotización de otro dueño sin team view (M-1). El test A10 usa un usuario sin `ventas_diseno.edit`, por lo que el 403 puede venir del middleware de permiso y no de `canAccess`; es aceptable para A10 tal como está escrito.
Fix: agregar esos 3-4 casos, son baratos.

### BAJO

**B-1. Carrera confirm vs. marcar Perdido (preexistente, no introducida).** `resolveLinkedCardForConfirm` toma advisory lock por proyecto pero el PATCH de etapa no lo toma, así que un `confirm` concurrente con "Perdido" puede dejar una cotización confirmada con tarjeta `lost` (`advanceCardToQuoteStage` no la mueve porque `lost` no está en `stageOrder`). Esta tarea amplía algo el uso (más confirmaciones sobre tarjetas recién restauradas), no el riesgo. Backlog, no bloquea.

**B-2. Registro de voz inconsistente en i18n es.** Las 6 claves nuevas usan tuteo ("Puedes", "quieres", "Intenta") mientras las contiguas usan voseo ("Completala", "Cargá", "Revisá", "intentá"). Unificar con el criterio vigente del archivo.

**B-3. Test FE de `QuoteViewerModal` para Perdido/Propuesta/Aprobado** verifica solo ausencia de `useAsBase` en la tabla; no cubre el botón "Nueva versión" de la versión única en esas etapas. Bajo valor, ya cubierto por la misma función `canCreateNewVersion`.

**B-4.** La documentación del docblock del `QuoteService::confirm()` sigue diciendo que nueva versión es "mientras sigue en etapa Cotización" (línea ~346-352); queda levemente desactualizada respecto al nuevo alcance. Cosmético.

## Observaciones sin acción
- Desde Lead, confirmar la versión nueva salta directo `lead -> quote` (sin pasar por Diseño). Es el comportamiento ya existente de "Crear cotización" en blanco desde Lead; no es regresión.
- El diálogo no tiene manejo de Esc ni cierre por fondo; coherente con otros modales de la app.

## Recomendación
Puede pasar a Pre-QA. M-2 conviene resolverlo en este mismo ciclo (tests baratos); M-1 requiere decisión de Luis/UX sobre el dueño.
