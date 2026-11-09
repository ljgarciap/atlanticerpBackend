# Flujo: hoja de reclamo y reportes de Servicios

Continuación de [Servicios: tickets](servicios-tickets.md) y [técnicos, herramientas e insumos](servicios-tecnicos-herramientas.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 con las cuentas demo (`npm run e2e:flujos`, archivo `servicios-reclamo-reportes.spec.ts`, 6 pasos).

## 1. Hoja de reclamo
Solo existe para tickets del tipo **reclamo** (folio `REC-AAAA-NNNN`, sin subtipo). En una instalación, `GET claim-sheet` responde 422; en un ticket inexistente, 404 [E].

- **Ver**: cualquiera que vea Servicios. Si no hay hoja devuelve una precarga `pending` con el reclamante (cliente, contacto, teléfono, dirección, proyecto) tomado del **ticket**, nunca capturado a mano [E].
- **Escribir** (`PUT claim-sheet`, permiso `servicios.claim_sheets.manage`): el Líder de Servicios o el **técnico interno asignado a ese ticket**. El vendedor, el técnico de garantías y Gerencia, 403; un técnico interno que no es el asignado, 403 con mensaje explícito [E].
- Campos: `diagnostico` (obligatorio, de una lista: `defectuoso`, `no_procede`, `uso_inadecuado`, `pendiente_evaluacion`), `firma_responsable` (obligatoria, máx. 255), `descripcion_problema` y `fecha_reclamo` (opcionales). Fuera de la lista, 422 [E].
- **Se guarda completa de una vez**: no hay borrador; el primer guardado la deja `completed` y desde entonces cualquier otro guardado responde **409** «ya fue completada y no puede editarse», incluso del Líder [E]. Guardarla **no cambia el estado del ticket** [E].
- **PDF** (`GET claim-sheet/pdf`): 404 mientras no esté completada; completada, un `application/pdf` descargable por quien ve Servicios, y se audita la descarga [E][C].

## 2. Cerrar un reclamo
`reported → scheduled → on_site → resolved → closed` como cualquier ticket. La compuerta de cierre pide **cotización aprobada** (mensaje «falta aprobar la cotización») aunque la hoja ya esté completada: la hoja **no es compuerta** [E]. Con la cotización aprobada por el Líder (crear, ítem, enviar, decidir) el reclamo resuelve y cierra [E].

## 3. Informe de inspección en blanco
`GET inspection-report/pdf/blank` comparte el permiso de la hoja de reclamo: el Líder y el técnico interno obtienen el PDF; vendedor, Gerencia y técnico de garantías, 403 [E]. Sobre un reclamo no hay informe de inspección (404) [E].

## 4. Reportes (`servicios/reportes/*`)
| Reporte | Quién | Qué trae |
|---|---|---|
| `panorama-mes` | todos los roles de Servicios, vendedor y Gerencia; Contabilidad 403 | completados del mes y del total, % de resueltos a la primera visita, tiempo promedio de resolución, informes pendientes [E] |
| `instalaciones-cotizadas-vs-realizadas` | igual | cantidad y total cotizado (instalaciones con subtipo inspección) frente a lo realizado con cotización aprobada [E][C] |
| `distribucion-tipo`, `distribucion-tecnico` | igual | tickets creados en el mes por tipo / por técnico, con color [E] |
| `completados-anio` | igual | por mes y por tipo, **resueltos y cerrados**; el año en curso llega hasta el mes actual, un año pasado trae 12 meses; cada mes suma sus tipos [E] |
| `biblioteca` | igual | informes de inspección y hojas de reclamo, con búsqueda por folio/cliente, filtro por tipo de documento y estado, y paginación (`meta`) [E] |
| `comision-tecnico` | solo el **propio técnico** y Gerencia; el Líder y el vendedor, 403 | comisión del mes del técnico; `data: null` si no tiene [E] |

- Periodo: `month` 1–12 y `year` desde 2020, si no 422 [E].
- Coherencia: un ticket nuevo suma 1 a su tipo en `distribucion-tipo`; una hoja completada aparece en la biblioteca con su folio, el técnico y la fecha del reclamo [E].

## Candidatos a cambio o mejora
1. ~~**La hoja de reclamo no condiciona el cierre**~~ — **Fase 30**: un reclamo exige la hoja completada. (Original:): se exige cotización aprobada pero no la hoja (un reclamo puede cerrarse sin ella), y completarla no mueve el ticket. [E][C]
2. ~~**Una hoja completada no se puede corregir**~~ — **Fase 30**: `PATCH claim-sheet/reabrir` con motivo (≥ 10), auditado; vuelve a pendiente. (Original:) (ni el Líder): un diagnóstico equivocado obliga a crear otro ticket; falta una reapertura con motivo, como la del ticket. [E]
3. ~~**Sin borrador**~~ — **Fase 30**: `borrador: true`. (Original:): la hoja exige diagnóstico y firma desde el primer guardado. [C]
4. ~~**Los reportes los lee cualquiera que vea Servicios~~ — **Fase 30**: carga por técnico y comisión con permiso `servicios.reports.team` (Líder y Gerencia). (Original:) **, el vendedor incluido**, con la carga por técnico (`distribucion-tecnico`); es decisión de negocio si debe ser así. [E]
5. ~~**`completados-anio` cuenta~~ — **Fase 30**: cada mes trae también `resueltos` y `cerrados`. (Original:) ** tickets «resueltos» y «cerrados»** bajo el nombre «completados». [C]
6. ~~**Un `tipo_documento` desconocido~~ — **Fase 30**: 422. (Original:) ** en la biblioteca devuelve una lista vacía**, no un 422. [E]
7. ~~**La comisión del técnico no la ve el Líder~~ — **Fase 30**: el Líder la ve. (Original:) ** de Servicios**, que es quien administra a los técnicos. [E]
8. **Sin ejercitar**: la pantalla de reportes en el navegador (el informe de inspección completo y el PDF de cotización están en [servicios-informe-inspeccion.md](servicios-informe-inspeccion.md)). [C]
