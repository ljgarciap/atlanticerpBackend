# Fase 7c — Etapas del pipeline de Ventas como catálogo (2026-10-27)

**Estado**: BORRADOR del Analista, pendiente de decisión de Luis (§5). No se implementa sin su «adelante». Continúa `fase7-catalogos.md` (§6, 7c).

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
**Opción B, en 2 sub-fases con 2 PR por repo cada una (como la 7a/7b)**: 7c-1 = catálogo + `categoria` + migración del ENUM + backend leyendo `categoria` (sin cambio visible); 7c-2 = frontend (tablero, modales, reportes) leyendo etiqueta/color/orden. Aplicar **solo a Ventas & Diseño**; CRM se deja como está (ver §5, decisión 2). Principio de la 7b: la lógica pregunta por **categoría/rol de la etapa**, nunca por su clave; las 6 claves de sistema se siembran con las categorías que hoy tienen implícitas.

## 5. Decisiones que necesita de Luis
1. **Alcance**: ¿A, B (recomendada) o C?
2. **CRM**: ¿se deja con su vocabulario propio (recomendado: es otro módulo y otro embudo), se incluye con un dominio `crm_stage` aparte, o se unifica con Ventas & Diseño?
3. **Compuertas**: ¿se aceptan como fijas en 7c (cada etapa de sistema conserva su compuerta; las propias, ninguna)?
4. **Idioma**: una etiqueta por entrada (idioma de la empresa), igual que la 7a/7b.

## 6. Criterios de aceptación (si se aprueba B)
Panamá sin cambio visible con los datos por defecto; migración aditiva y reversible; ningún `stage === 'approved'` / `in_array(stage, [...])` literal fuera de la fachada (grep de cierre, como en la 7b); una etapa propia aparece en el tablero, en el cambio de etapa y en reportes con su etiqueta/color; permisos sin cambio (CRUD bajo `superadmin.all`, sin permiso nuevo); tests de equivalencia con los datos por defecto + un caso de etapa propia; suite completa verde; verificación visual con Playwright CLI antes/después.

## 7. Riesgos
- Etapas propias en el medio del flujo lineal rompen `STAGE_ORDER` (`array_search`+1): la compuerta debe pasar a leer el orden del catálogo.
- Las comisiones (AdminCont) y «pasar a pedido» dependen de `approved`: deben leer `categoria = ganada` y no permitir que una etapa propia se marque «ganada» sin definir qué compuertas exige (decisión de negocio → Luis).
- `lost` no editable y `approved` con edición restringida son reglas por clave: se conservan como categorías de sistema.
- 90 tests del backend asumen las claves: se adaptan, no se borran.
