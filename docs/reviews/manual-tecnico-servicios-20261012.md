# Senior Review — Manual técnico de Servicios

2026-10-12 · Documento: `docs/manuales-tecnicos/servicios.md` · Misma plantilla y método.

## Cobertura
290 de 290 elementos nombrados: 15 controladores, 14 Form Requests, 24 modelos, 27 servicios, 7 eventos, 1 listener, 9 excepciones, 24 tablas, 67 rutas, 73 piezas de frontend y 29 archivos de prueba. La cobertura mide presencia, no calidad.

## Muestra contrastada con el código
Estados del ticket y gate de cierre, estados iniciales por tipo y subtipo, regla de especialidad del técnico, bloqueos de baja, valores por defecto de `servicios_settings`, conversiones de comisión y licencia médica, resolución de la meta de instalaciones, ciclo de la cotización, ownership por ticket, disparo de los siete eventos, registro del listener de Compras, rutas y roles (`routes/servicios.php`), rutas del frontend (`App.tsx`).

## Correcciones hechas en el repaso
| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | Comisión: "se multiplica cada porcentaje por su peso" | Se aplica al **monto máximo**; el peso solo se reporta en el desglose. |
| 2 | Conversiones de SLA y puntualidad sin detalle | Se añadieron los umbrales verificados (95/85; 0, ≤ máximo, más). |
| 3 | Las rutas de informe y hoja usan `{ticketId}` | Se declaran con `{id}`; el nombre `{ticketId}` es el de OpenAPI. Se documentan ambos. |
| 4 | "Seis eventos sin consumidor" | Son siete; los siete se disparan sin listener. |

## Hallazgos de contenido que el manual deja registrados (no se corrigieron)
- Los siete eventos propios no tienen consumidor.
- `app/Modules/Servicios/CLAUDE.md` describe hasta el Batch 6 y quedó desactualizado.
- `tickets.internal_technician_id` guarda el id del usuario, no del perfil.
- SLA y comisión cuentan días hábiles sin feriados.
- Servicios grandes (749, 737 y 602 líneas).

## Límites
No se ejecutó la aplicación ni las pruebas. No se leyeron línea a línea `TicketService`, `ServiceQuoteService`, `InspectionReportService`, `ReportsService` ni `HomeService`; sus reglas finas salen de docblocks, firmas y fragmentos. No se revisó el catálogo de campos del informe por tipo ni los textos de los PDF. El frontend se leyó de `origin/dev` (commit `95cc675`); `TechnicalSpecModal` se incluyó por estar en el inventario aunque es compartido.
