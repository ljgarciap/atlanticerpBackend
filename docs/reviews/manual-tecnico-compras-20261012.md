# Senior Review — Manual técnico de Compras (piloto)

2026-10-12 · Revisó: Senior Reviewer (contraste por muestreo contra el código) · Documento: `docs/manuales-tecnicos/compras.md`

## Método
1. Cobertura de presencia con `scripts/manual-coverage.py compras`: 253 de 253 elementos nombrados.
2. Muestra de afirmaciones concretas del manual contrastadas con el código fuente (valores, rutas, nombres, límites y reglas).

## Resultado de la muestra
Se contrastó una muestra de unas treinta afirmaciones concretas (valores, rutas, nombres, límites, reglas). La mayoría coincidía con el código; estas 6 no y se corrigieron en este mismo repaso:

| # | Afirmación inicial | Corrección |
|---|---|---|
| 1 | Evento `ClaimStatusChanged` = `compras.claim.status_changed` | El nombre real es `compras.claim.status-changed` (la acción de auditoría sí usa guion bajo). |
| 2 | Pagos y `compras.claim.created` aparecían como acciones de `AuditService` | Esos nombres son de eventos de dominio. Las acciones de auditoría reales son cinco; los pagos no escriben una acción propia. |
| 3 | "Auditan los modelos con `LogsAuditTrail` (órdenes, proveedores y otros)" | Son exactamente cuatro: `PurchaseOrder`, `Provider`, `PurchaseOrderDocument`, `PurchaseOrderTranslation`. |
| 4 | "52 migraciones" | El conteo mezclaba migraciones de Servicios; se reemplazó por "unas 50" con la ruta de la primera. |
| 5 | "~1160 claves" de i18n | Eran líneas del archivo, no claves. |
| 6 | Límites de IA: faltaba la detección de facturas (10 por usuario por hora) y la lista de extensiones de documentos | Añadidos. |

## Hallazgos de contenido que el manual deja registrados (no se corrigieron)
Ver sección 13 del manual: cuatro umbrales de negocio fijos en el código, un aviso prometido (SCRUM-252) sin consumidor, listados que paginan en PHP, `total_amount` sin costos adicionales y tres comentarios desactualizados.

## Límites de esta revisión
- Se verificó por muestreo; **no** se leyó cada línea de los 6 000 de los controladores.
- El flujo de pantallas del frontend se documentó a nivel de página, componente y hook; el detalle de cada formulario no se recorrió campo por campo.
- El frontend se leyó de `origin/dev` (95cc675). El clon local estaba desfasado.
- No se ejecutó ninguna prueba: es documentación.

## Veredicto
Aprobado como piloto de plantilla. La plantilla y el método de cobertura se pueden replicar en los otros 8 módulos una vez que Luis valide el formato.
