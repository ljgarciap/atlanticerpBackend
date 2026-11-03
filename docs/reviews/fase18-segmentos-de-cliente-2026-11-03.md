# Senior Review — Fase 18: segmentos de cliente como catálogo por empresa (2026-11-03)

Ramas: backend `fase18/segmentos-cliente` (`6ba114c`), frontend `fase18/segmentos-cliente` (`213cae2`). Spec: `docs/specs/fase18-segmentos-de-cliente.md`. **Aprobada, 0 bloqueantes.**

## Qué se revisó
- Dominio `client_segment` (`config/catalogs.php`, `usage` sobre `SubClient.category`: una entrada en uso no se borra).
- Migración `2026_11_12_100000`: quita el CHECK del `enum`, la columna pasa a `string(60)` nullable, siembra los 4 segmentos históricos como entradas de sistema con las mismas claves y etiquetas neutras (es/en), no pisa una entrada ya editada y siembra también cualquier otro valor ya guardado. `down()` borra las entradas y solo restaura el CHECK si todos los valores son los 4 originales.
- Validación: los 4 Form Requests usan `CatalogService::keys('client_segment')` (solo entradas activas).
- Frontend: hook `useClientSegmentOptions` (respaldo a los 4 de siempre), selectores de alta/detalle, filtro y pastilla de la lista; contrato de vocabulario sin las excepciones del segmento.

## Hallazgos
1. **(Aceptado)** Un segmento desactivado no se puede volver a asignar (422), pero los clientes que ya lo tienen lo conservan y la pantalla lo muestra con su etiqueta; el detalle lo agrega a su selector para no vaciarlo al abrir.
2. **(Aceptado)** La clave `b_architect_designer` se conserva (decisión de Luis): es un identificador de la BD/API, no un texto visible.
3. **(Aceptado)** La pastilla de la lista usa la inicial de la etiqueta: un segmento propio debería empezar por una letra («E · Corporativo»); si no, muestra su primer carácter.
4. **(Abierto, menor)** El orden del filtro de la lista sigue el `sort_order` del catálogo; un segmento propio nuevo queda al final.

## Verificación
Backend: suite completa 5217 tests / 0 fallos, PHPStan 0, 4 tests nuevos (`ClientSegmentCatalogTest`). Frontend: 246 archivos / 2926 tests, `tsc` limpio, helpers con test propio. e2e: paso 11 del flujo de Ventas & Diseño (crea un segmento propio, lo asigna, lo filtra y lo borra) y comprobación en navegador real de la lista de clientes (chips neutros, ningún «Arquitecto o Diseñador»).
