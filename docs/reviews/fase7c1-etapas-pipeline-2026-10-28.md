# Senior Review — Fase 7c-1: etapas del pipeline como catálogo (backend) (2026-10-28)

**Alcance**: backend `fase7c1/etapas-infra` + `fase7c1/etapas-consumidores` (PR 1 y PR 2 apilados; la spec va en `fase7c/spec-etapas-pipeline`).
**Veredicto**: APROBADO tras corregir 4 hallazgos durante la revisión. 0 bloqueantes abiertos. El frontend (7c-2) queda pendiente.

## Qué se revisó
- **Equivalencia con los datos por defecto**: la siembra reproduce las 6 etapas, el orden lineal y las categorías (`approved` = ganada, `lost` = perdida, el resto abiertas). Los 90 archivos de test que asumen las claves de sistema pasan sin cambios.
- **Lógica por categoría y posición, no por clave**: grep de cierre **sin exclusiones** (ver hallazgo 3) sobre `app/`. Quedan solo las claves de sistema como constantes de `PipelineStages` (las anclas de las compuertas), los comandos de siembra que escriben claves válidas como datos y las etapas de pedidos de Bodega (`Order::STAGE_*`, otro vocabulario).
- **Compuertas**: ahora cuelgan de la etapa de DESTINO (entrar a Cotización / Propuesta / Aprobado) y salir de Lead; con las 6 de sistema es idéntico a los pares de antes, y una etapa propia intercalada hereda la compuerta correcta (`PipelineCustomStageFlowTest`).
- **Restricción de Luis**: una etapa propia es siempre `abierta` (`ganada`/`perdida` son `system_only`), solo cabe entre Lead y Aprobado y no pisa el orden de una de sistema; las 6 de sistema no se reordenan ni cambian de categoría (`PipelineStageCatalogTest`).
- **Permisos**: sin permiso nuevo; el CRUD sigue bajo `superadmin.all`.
- **Migraciones**: libera el CHECK de `pipeline_cards.stage` (igual que `architects.type` en la 7a) y siembra el dominio. La `down` del ENUM solo es segura mientras ninguna tarjeta esté en una etapa propia.
- **CRM**: no se tocó (decisión de Luis); mantiene su vocabulario `lead/diseno/cotizacion/propuesta/cerrado/perdido`.

## Hallazgos corregidos durante la revisión
1. **Rendimiento (el más serio).** `Quote::documentStatus()` pasó a llamar a `PipelineStages::isAtOrAfter()`, que consultaba el catálogo (2 consultas por llamada), y la lista de cotizaciones lo llama por **cada fila**; igual el forecast (por tarjeta) y la lista de clientes. Antes eran 0 consultas. Se añadió `CatalogCache` (memo `scoped`: por petición y por job de la cola, mismo patrón que `CrmSettingsService`/`ComprasThresholds`; clave por tenant; toda escritura de `CatalogService` la invalida). Test: 50 preguntas repetidas = 0 consultas. **Esto cierra la sugerencia abierta «sin caché» de la 7b** y beneficia a `TicketTypes`.
2. **Bug del forecast.** Una tarjeta *aprobada* se contaba en el bucket de Propuesta (`anchorOf` mira «en o antes» y Aprobado viene después). Lo detectaron 2 tests existentes; ahora solo entran etapas abiertas.
3. **Omisión del barrido.** `GerenciaHomeService` tenía 10 lecturas de la etapa de tarjeta y el primer barrido las excluyó por filtrar el nombre «Gerencia». Lo encontró el grep de cierre sin exclusiones.
4. **Export CSV de proyectos.** Es una respuesta *streamed*: su cierre corre sin contexto de tenant y no puede consultar el catálogo. Las etiquetas se resuelven antes de transmitir.

## Decisiones de negocio tomadas por defecto (visibles para Luis)
- **Forecast**: una etapa propia hereda la probabilidad de la última etapa de sistema anterior (Cotización o Propuesta); antes de Cotización no pondera, igual que Diseño.
- **«Propuestas pendientes de aprobar»** (dashboard, comisión de propuestas, Gerencia): incluyen las propias que van después de Propuesta.
- **Cotización congelada** desde Propuesta en adelante (no desde «cualquier etapa posterior a Cotización»).

## Sugerencias abiertas (no bloquean)
1. **7c-2 (frontend)**: tablero, modales y reportes siguen con las 6 etapas fijas; el selector de `categoria` debe ofrecer solo `abierta` en una etapa propia (el backend ya rechaza `ganada`/`perdida`).
2. `PipelineStageRules` valida contra la BD (no la memo) a propósito: son escrituras de administración.
3. Una etapa propia sin etiqueta traducida usa la que escribió la empresa (una etiqueta por entrada, igual que la 7a/7b).
4. Quedan literales de etapas de sistema en comandos de siembra (`BodegaSeedUatProjectsCommand`, `AdminContSeedRebotesQaCommand`): son datos válidos, no lógica.

## Verificación
PHPStan L8 sin errores. Suite completa antes de la memo: **5141 tests, 0 fallos**. Con la memo: catálogos y etapas 50/50; la suite completa se re-ejecuta (cambio transversal).
