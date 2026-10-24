# Diseño (Arquitecto) — F6 de SCRUM-1136: stock de ICG → `bodega.*`

- **Estado:** PROPUESTO 2026-10-15, pendiente de aprobación de Luis. Spec: `docs/specs/icg-stock-a-bodega-f6.md` (decisiones 1–6 cerradas, mediciones en su sección 9).
- **Principio:** carga única, reanudable y reversible de `legacy.stock_almacen` hacia `bodega.product_warehouse_stock` + Kardex, sin tocar Bodega Central ni la lógica de Bodega.
  Sin migraciones de esquema (la medición descartó cambiar `quantity` a decimal: 1 sola fila fraccionaria).

## 1. Piezas
| Pieza | Responsabilidad |
|---|---|
| `SeederEquivalenciasAlmacen` (idempotente, solo-si-no-existe, todos los entornos) | Siembra `legacy.almacen_equivalencias` para AL2→Showroom Obarrio, B1→Showroom Cliente, B2→Showroom SM, ALX→Merma, ALY→Reclamos y Devoluciones, ALR→Reserva Servicios como `confirmado`; `warehouse_id` por **nombre** (si falta o es ambiguo, el seeder falla y no siembra). |
| `legacy:crear-articulos-sin-catalogo {--apply --operador=}` | Crea inactivos en `catalog_products` los activos de ICG con stock ≠ 0 en un almacén mapeado y sin catálogo resoluble (42 de B2 en dev; recalcular por entorno). Reutiliza `CatalogProductLoader` y el patrón de `legacy:crear-descatalogados-inactivos`. Orden: **antes** de la carga de stock. |
| `StockBodegaLoader` (servicio) | Lee `stock_almacen` por almacén confirmado, resuelve producto (`COALESCE(p.catalog_product_id, e.catalog_product_id)` vía `consultaConCatalogoResuelto`), aplica exclusiones y redondeo, inserta por lotes de 500. |
| `legacy:cargar-stock-bodegas {--tenant --almacen=* --apply --operador= --lote=}` | Dry-run por defecto; una transacción por almacén; ON CONFLICT DO NOTHING; verificación antes/después; ROLLBACK si discrepa; evento de auditoría con los ids sembrados. |
| `legacy:verify-stock-bodegas` | Solo lectura: esperado (tras exclusiones) vs `SUM(quantity)` por bodega. |
| `legacy:revertir-stock-bodegas {--apply}` | Borra filas y movimientos de la carga solo si no hay movimientos posteriores en ese (producto, bodega). |

## 2. Decisiones técnicas (dentro del diseño, las toma el equipo)
- **Cantidad:** `round(stock)` (half-up). Negativos tal cual (decisión de Luis). 0 → no se crea fila.
- **Exclusiones:** artículo 2999 y servicio 68602 → lista de revisión en la salida, sin fila; `CODALMACEN` vacío; almacenes no confirmados; bodega `solo_consulta` o Bodega Central como destino aborta.
- **Kardex:** se escribe con `KardexService::registrar()` (tipo `Ajuste`, referencia «Carga inicial desde ICG (SCRUM-1136 F6) — almacén XXX», `saldo_inicial = 0`).
  Riesgo R2: `registrar()` tiene guardas para reubicaciones con saldo negativo (SCRUM-837). Si rechaza cantidad negativa con saldo 0, el loader escribe stock y Kardex con SQL directo en la misma transacción
  (mismo resultado, mismas columnas) en vez de relajar la guarda del servicio. **Se resuelve con tests en F6b antes de cualquier corrida.**
- **No pisa:** `(catalog_product_id, warehouse_id)` existente → se cuenta «ya existía» y no se toca. Locks: `INSERT … ON CONFLICT DO NOTHING`; sin `lockForUpdate` masivo.
- **Auditoría:** `LogsAuditTrail` de `ProductWarehouseStock` no debe generar 4 000 filas de `audit_logs` fila por fila; el loader escribe un solo evento resumen (mismo criterio que SCRUM-1016) y desactiva el trait durante el lote.
- **Memoria/tiempo:** ~4 130 filas + 42 productos: lotes de 500, sin riesgo para el host de 2 GB.

## 3. Orden de ejecución por entorno (dev → test → prod)
1. Seeder de equivalencias. 2. `legacy:crear-articulos-sin-catalogo` (dry-run → revisión de Luis → `--apply`). 3. `legacy:cargar-stock-bodegas` dry-run → revisión → `--apply`.
4. `legacy:verify-stock-bodegas` = OK. 5. Cotejo con el Excel del 13-oct (AL2/B1/B2). **Prod:** backup verificado previo y aviso a Daniela/Mark del impacto en reportes (~1,1 M a costo).
Los `--apply` y las corridas largas por ssh los lanza Luis o van con nohup (ver `feedback_corridas_largas_por_ssh_desacopladas_20261015`); `DELETE` de la reversa en prod requiere su confirmación.

## 4. Fases y tareas (para el PM)
- **F6a** ✔ Medición (spec sección 9).
- **F6b** Backend Dev: seeder, `crear-articulos-sin-catalogo`, loader, 3 comandos, tests con fixtures sin red (incl. R2: negativos en Kardex; idempotencia; rollback por discrepancia; destino prohibido; descatalogado equivalente suma sobre el activo).
  Gate: `infra/test.sh` completo + PHPStan (`--memory-limit=1G`) + Senior Review.
- **F6c** dev: dry-run → `--apply` → verify → Pre-QA (criterio 10 de la spec) + prueba de reversa.
- **F6d** test y prod por el flujo normal (`dev → test` push completo; deploy y corridas bajo el gate habitual).
- **Runbook** corto `docs/runbooks/icg-stock-a-bodegas.md` (carga y reversa).

## 5. Fuera de alcance / seguimiento
Segunda pasada (A1, ALM, AL1, AL3, negativos de esos); ticket para mostrar el stock de los 83+42 inactivos en Bodega («con stock»); inventario físico de ajuste; depuración de `legacy.movimientos`.

## 6. Qué necesito de Luis
Aprobar este diseño y el orden de ejecución (en particular: que `crear-articulos-sin-catalogo` y la carga se corran como dos pasos separados, cada uno con su dry-run revisado).
