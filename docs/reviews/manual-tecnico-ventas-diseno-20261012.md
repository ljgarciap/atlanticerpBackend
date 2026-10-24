# Senior Review — Manual técnico de Ventas & Diseño

2026-10-12 · Documento: `docs/manuales-tecnicos/ventas-diseno.md` · Misma plantilla y método.

## Cobertura
360 de 360 elementos nombrados: 34 controladores, 27 Form Requests, 29 modelos, 30 servicios, 1 job, 2 eventos, 5 clases de soporte, 5 excepciones, 1 observer, 34 tablas, 97 rutas, 42 piezas de frontend y 53 archivos de prueba. La cobertura mide presencia, no calidad.

## Muestra contrastada con el código
Transiciones de etapa y sus requisitos (`PipelineCard`), edición por etapa y permiso, estado del documento, validaciones de generar, fórmulas de precio por tipo, valores sembrados de parámetros y tramos de comisión (migraciones), clasificación de Pedidos, flujo de "Pasar a Pedido", rutas y permisos (`route:list`), guardas del frontend (`App.tsx`), dispatch y conexión del job del Catálogo, limitador `tax-lookup`.

## Correcciones hechas en el repaso
| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | Los eventos se llaman `ventas_diseno.quote.*` | Son `ventasdiseno.quote.generated` y `ventasdiseno.quote.confirmed`. |
| 2 | Las rutas UAT del Catálogo quedaban "sin confirmar" | La foto vive en `bootstrap/app.php` (`/public/catalog/uat-placeholder/{reference}`) y la limpieza en `routes/admin.php`. |
| 3 | Un ítem acepta los cinco tipos de precio | `QuoteItemRequest` excluye `partner` en el override del ítem. |
| 4 | Excepción de margen "por permiso" sin nombre | Es `ventas_diseno.override_min_margin`. |
| 5 | Dashboard solo con permiso | Las rutas exigen además rol `superadmin` o `management`. |
| 6 | `CatalogController` encola el job | Lo encola `CatalogPdfExportService`. |
| 7 | Lead automático siempre al crear cliente | Solo desde la pantalla Clientes; Cotización lo omite (`$skipLeadCard`). |
| 8 | Umbrales 3/7/21 del CRM aplican aquí | Dato del manual de CRM no verificado en este módulo; se retiró y se apuntó a `utils/urgencyUtils.ts`. |
| 9 | Plantilla del PDF sin ruta | Está en `resources/views/ventas-diseno/quote-pdf.blade.php`. |

## Hallazgos de contenido que el manual deja registrados (no se corrigieron)
- `STAGNANT_THRESHOLD_DAYS = 15` fijo en código y reutilizado por Gerencia.
- `QuoteGenerated` y `QuoteConfirmed` sin listener; `approvalsPending` siempre vacío.
- CRUD de `calendar-events` sin uso en el frontend.
- Controladores de 650 a 940 líneas con validación en línea.
- Servicios de limpieza de datos de prueba dentro del código de producción.
- Documento `docs/architecture/ventas-diseno.md` desactualizado.

## Límites
No se ejecutó la aplicación ni se corrieron las pruebas. No se leyó línea a línea `QuoteController`, `QuoteItemController`, `CatalogProduct` ni `TestDataCleanupService`; sus reglas finas se tomaron de docblocks y firmas. No se revisaron las vistas del frontend más allá de rutas, guardas y nombres de componentes. El frontend se leyó de `origin/dev` (commit `95cc675`).
