# Senior Review — Manual técnico de Bodega

2026-10-12 · Documento: `docs/manuales-tecnicos/bodega.md` · Misma plantilla y método que el piloto de Compras.

## Método
1. Cobertura de presencia con `scripts/manual-coverage.py bodega`: 273 de 273 elementos nombrados (controladores, Form Request, modelos, servicios, eventos, soporte, excepciones, listeners, 20 tablas, 78 rutas, 41 piezas de frontend y 45 pruebas).
2. Muestra de afirmaciones concretas contrastadas con el código.

## Resultado de la muestra
Se contrastaron unas treinta afirmaciones (valores por defecto de los once umbrales, transiciones de etapa, límites, roles de cada acción, rutas, estados, nombres). La mayoría coincidía. Se corrigieron en el repaso:

| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | Zona Libre: "pueden modificar `lider_bodega` o `lider_compras`" | El Líder de Compras no se reconoce por rol sino por el permiso `compras.zona-libre.approve`; el código compara el rol de Bodega y ese permiso. |
| 2 | `remind` "envía un recordatorio" | Envía correo y notificación interna, por `NotificationService`, a todos los usuarios activos con rol `lider_compras`. |
| 3 | Las rutas de aprobación de ajuste aparecían solo con `{id}` | El OpenAPI las nombra `{lineId}`; se documentó el alias. |
| 4 | Faltaban las pruebas de concurrencia | Añadidas (`DispatchConcurrencyTest`, `StartPickingConcurrencyTest`, `ResolveInventoryReviewConcurrencyTest`). |

## Hallazgos de contenido que el manual deja registrados (no se corrigieron)
Doce eventos de dominio sin ningún listener; código muerto o conservado (`invoice_ready`, `MarkOrderInvoiceReady`, `mostrar_sin_stock`, estado `reemplazada`); dos controladores/servicios muy largos; acceso inconsistente entre la interfaz de ajustes (`superadmin.all`) y la API (`bodega.settings.configure`); y varios comentarios desactualizados.

## Límites
- Verificación por muestreo; no se leyó línea por línea (`GeneralCountController` tiene casi 900 líneas, `BodegaHomeService` unas 760).
- El flujo de cada pantalla del frontend se documentó a nivel de página, componente y hook, no campo por campo.
- El frontend se leyó de `origin/dev` (95cc675). Los cuatro paneles de reportes y el Home se describieron por sus docblocks, sin recorrer cada fórmula.
- El comportamiento de los comandos `bodega:*` y `lafayette:*` se describe por su firma y docblock; no se ejecutaron.
- No se ejecutó ninguna prueba.

## Veredicto
Aprobado. La plantilla se replicó sin cambios.
