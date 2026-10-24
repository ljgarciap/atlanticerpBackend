# Pre-QA (fase de lectura) — fallback de costo por proveedor, en dev

Commit desplegado y verificado en dev: `3b03e90c` (gate local verde: PHPStan 8 + 5.555 tests). Fecha: 2026-10-14. Solo lectura en dev; el apply/revert queda pendiente de autorización.

| Verificación | Resultado |
|---|---|
| `legacy:backfill-costo-proveedor` (dry-run) en dev | A rellenar 1.370: 1.344 por un proveedor + 26 por compra más reciente; 1 sin resolver (id 4744, artículo 60505); 23 con costo 1001 y `cost` nulo no tocados; 2.345 sin costo en ICG no tocados. Σ $255.846,08. Huella `1f9805763b12d2cdca1d72a337210c42` |
| Coherencia con prod (medido antes) | 1.335 / 27 / 23 / 2.348 → mismo orden de magnitud |
| Reimplementación SQL independiente (varios proveedores, proveedor con compra más reciente sin empate) | 27 candidatos, 26 resolubles: coincide con el comando |
| Caso del cliente A691-451471527K (dev id 10710) | Único costo 70:480.06 → quedaría en $480,06 (aún sin aplicar) |
| Sin resolver (artículo 60505: proveedores 7 y 1030, 2 líneas de compra) | Queda vacío por diseño (empate o compras de otros proveedores); lista para Compras |
| Costos ≤ 0 en los de un proveedor | 0 |
| Costo > precio de venta en los de un proveedor | 2 (id 10889 PJ2-P2B-GWH $51,84 vs $32,40; id 1962 TRANSPORTE $78,91 vs $17,78): dato de ICG, no del cambio; el margen saldrá negativo en esas líneas |

## Fase de escritura en dev (autorizada por Luis)

| Prueba | Resultado |
|---|---|
| `--apply --esperado=<huella>` | 1.370 productos aplicados y verificados; evento de auditoría 290885 |
| Cada costo contra el evento de auditoría | 1.370 de 1.370 coinciden; Σ $255.846,08 |
| A691-451471527K | `cost` = 480,06 |
| Activos sin costo tras aplicar | 2.369 = 2.345 (sin costo en ICG) + 23 (costo 1001, `cost` nulo) + 1 (sin resolver) |
| 2.ª corrida | «Nada que aplicar» (idempotente) |
| `--revert=290885` | 1.370 de 1.370 revertidos; A691 vuelve a null; el plan recalculado da la MISMA huella |
| Re-aplicado | 1.370 de nuevo; dev queda con el backfill puesto |
| Reversa de un costo editado a mano después | cubierta por prueba automatizada (`test_revert_deja_en_null_solo_lo_que_sigue_valiendo_lo_aplicado`), no repetida en dev para no tocar datos |

Nota de proceso: una consulta de verificación mía cortó la expansión con `LIMIT 1` y mostró «1 producto»; era un error del SQL, no del backfill, y se repitió bien.

**Pendiente:** verificar en pantalla (Playwright) la partida al agregar el producto; por código el costo se copia de `catalog_products.cost` (`QuoteItemController::resolveItemData`).
**Veredicto de la fase de datos: PASA, sin hallazgos bloqueantes.**
