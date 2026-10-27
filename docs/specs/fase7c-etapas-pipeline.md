# Fase 7c — Etapas del pipeline de Ventas como catálogo (2026-10-27)

**Estado**: diseño aprobado por Luis (2026-10-27, §5). **7c-1 (backend) IMPLEMENTADA (§8)**; 7c-2 (frontend) pendiente de su «adelante». Continúa `fase7-catalogos.md` (§6, 7c).

## 1. Objetivo
Que las etapas del embudo de Ventas & Diseño dejen de ser constantes repetidas en el código para que cada empresa pueda llamarlas, ordenarlas y colorearlas a su negocio (y, según la opción elegida, añadir etapas propias).

## 2. Hallazgos (medidos sobre `master`, 2026-10-27) — corrigen lo que decía la spec de la 7
1. **Son dos vocabularios distintos, no uno.** (a) Ventas & Diseño: `pipeline_cards.stage` = `lead, design, quote, proposal, approved, lost` (inglés). (b) Módulo CRM: `crm_projects.etapa` = `lead, diseno, cotizacion, propuesta, cerrado, perdido` (español, con su propio `OPEN_STAGES`/`CLOSED_STAGES` y su propio tablero). No comparten datos ni código.
2. **No son etiquetas: cada etapa lleva reglas de negocio.** `PipelineCard::gateError()` (líneas ~340–395) fija un flujo **lineal sin saltos** y una compuerta por transición: salir de `lead` exige contacto + cliente master + subcliente; `design→quote` solo ocurre al generar una cotización real; `quote→proposal` exige superficie (m²); `proposal→approved` exige fecha de entrega + cotización firmada + comprobante de aprobación.
3. **Consecuencias aguas abajo atadas a la clave:** `approved` = edición restringida (`ventas_diseno.edit.approved`), «ganada» en dashboards y reportes, base de comisiones (AdminCont: `CommissionOrderService`, `ArchitectCommissionService`), de totales de ventas y de «pasar a pedido»; `lost` = no editable; `proposal` = propuestas abiertas (dashboard, comisión de propuestas, estancadas); `lead..proposal` = «activas».
4. **Alcance medido**: Ventas & Diseño ~15 archivos de `app` (PipelineCard, PipelineController/Service, QuoteService, Dashboard/Reports/Home/Clients/Orders/Commission/SalesTotals…) + 2 servicios de AdminCont + 3 comandos de siembra; frontend ~24 archivos que mencionan etapas (la mayoría solo tipan) y 7 componentes de tablero/modales; **90 archivos de test del backend y 17 del frontend** tocan etapas.
5. **El esquema bloquea añadir etapas**: `pipeline_cards.stage` y `crm_projects.etapa` son `ENUM` (CHECK) — igual que `architects.type` en la 7a, hay que liberarlo en una migración.

## 3. Opciones
- **A. Presentación** — las 6 etapas siguen siendo de sistema (clave y reglas intactas); el catálogo `pipeline_stage` solo aporta **etiqueta, color y orden visual** por empresa. Riesgo bajo; no permite etapas nuevas.
- **B. Presentación + categoría + etapas intermedias sin compuerta** — A, más la propiedad `categoria` (`abierta|ganada|perdida`) que reemplaza los `in_array(..., ['approved'])` por «¿es ganada?», y la posibilidad de **insertar etapas propias entre las de sistema** (p. ej. «Visita técnica» entre Diseño y Cotización) que heredan «abierta» y no tienen compuerta propia. Libera el ENUM. Riesgo medio.
- **C. Flujo configurable** (transiciones y requisitos por transición como datos). Es un motor de flujo de trabajo: fuera de la 7c; si se quiere, fase propia con su diseño.

## 4. Recomendación del Analista
**Opción B, en 2 sub-fases con 2 PR por repo cada una (como la 7a/7b)**: 7c-1 = catálogo + `categoria` + migración del ENUM + backend leyendo `categoria` (sin cambio visible); 7c-2 = frontend (tablero, modales, reportes) leyendo etiqueta/color/orden. Aplicar **solo a Ventas & Diseño**; CRM se deja como está (§5, decisión 2). Principio de la 7b: la lógica pregunta por **categoría/rol de la etapa**, nunca por su clave; las 6 claves de sistema se siembran con las categorías que hoy tienen implícitas.

## 5. Decisiones de Luis (2026-10-27)
1. **Alcance: opción B** — presentación (etiqueta, color, orden) + `categoria` (`abierta|ganada|perdida`) + etapas propias intermedias sin compuerta.
2. **CRM: se deja como está** con su vocabulario propio; la 7c solo toca Ventas & Diseño.
3. **Compuertas: fijas en las etapas de sistema**; las etapas propias no tienen compuerta.
4. **Idioma: una etiqueta por entrada** (idioma de la empresa), como la 7a/7b; el i18n es/en queda de respaldo para las 6 claves de sistema.

## 6. Criterios de aceptación (si se aprueba B)
Panamá sin cambio visible con los datos por defecto; migración aditiva y reversible; ningún `stage === 'approved'` / `in_array(stage, [...])` literal fuera de la fachada (grep de cierre, como en la 7b); una etapa propia aparece en el tablero, en el cambio de etapa y en reportes con su etiqueta/color; permisos sin cambio (CRUD bajo `superadmin.all`, sin permiso nuevo); tests de equivalencia con los datos por defecto + un caso de etapa propia; suite completa verde; verificación visual con Playwright CLI antes/después.

## 7. Riesgos
- Etapas propias en el medio del flujo lineal rompen `STAGE_ORDER` (`array_search`+1): la compuerta debe pasar a leer el orden del catálogo.
- Las comisiones (AdminCont) y «pasar a pedido» dependen de `approved`: deben leer `categoria = ganada` y no permitir que una etapa propia se marque «ganada» sin definir qué compuertas exige (decisión de negocio → Luis).
- `lost` no editable y `approved` con edición restringida son reglas por clave: se conservan como categorías de sistema.
- 90 tests del backend asumen las claves: se adaptan, no se borran.

## 8. Resultado de la 7c-1 — backend (2026-10-28)
- **Dominio `pipeline_stage`** (`config/catalogs.php`): props `categoria` (`abierta|ganada|perdida`; `ganada` y `perdida` son `system_only`) y `color`; `locked_when_system` = `sort_order` y `categoria`; reglas propias `PipelineStageRules` (una etapa propia va entre Lead y Aprobado, sin pisar el orden de una de sistema; por defecto justo después de Propuesta). `CatalogService` gana `system_only`, `locked_when_system` y reglas de dominio (`CatalogDomainRules`).
- **Migraciones** `2026_10_28_100000` (libera el CHECK de `pipeline_cards.stage`) y `…100001` (siembra las 6 etapas en pasos de 100: lead 100, design 200, quote 300, proposal 400, approved 500, lost 900).
- **Fachada** `PipelineStages` (`linear`, `open`, `won`, `lost`, `isWon`, `isLost`, `isAtOrAfter`, `isBefore`, `openAtOrAfter`, `anchorOf`, `label`, `labels`). Compuertas del modelo colgadas de la etapa de DESTINO. ~20 archivos migrados (Pipeline, Quote, Dashboard, Reports, Clients, Commission, Home, Orders, SalesTotals, ProjectsList, AdminCont ×2, Gerencia). Retiradas `PipelineCard::NON_EDITABLE_STAGE`/`RESTRICTED_EDIT_STAGE`.
- **`CatalogCache`** (memo `scoped` por petición/job) para que preguntar por una etapa o un tipo dentro de un bucle no consulte la BD.
- Informe: `docs/reviews/fase7c1-etapas-pipeline-2026-10-28.md`. Pendiente: **7c-2 (frontend)**, con su «adelante».
