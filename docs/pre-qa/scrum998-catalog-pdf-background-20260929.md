# Pre-QA — SCRUM-998 (PDF de Catálogo: PDF plano hasta un máximo configurable, ZIP de volúmenes en segundo plano si se excede)

Fecha: 2026-09-29 (UTC) · Entorno: dev.atlanticerp.ai · Backend origin/dev **113f977** (verificado por SSH) · Frontend e40aeda.
Commits bajo prueba: `91832d4` (feature), `007a791` (máximo de fotos, 20 en paralelo, job de 2 h) y `113f977` (reintento único de fotos), los tres salidos de este Pre-QA salvo el primero.
Catálogo real de dev: 29.630 activos; 10.928 con `photo_url` (8.441 de `lafayette.app.enxcloud.com`, proveedor externo, y 2.483 del bucket S3 propio).
Diseño y decisiones de Luis: `docs/specs/scrum998-catalog-pdf-background.md`. Alcance del Pre-QA acordado con Luis: exportación completa **sin fotos** y las fotos aparte con una muestra.

Método: JWT temporales por tinker (usuarios reales `fefi@` dueño, `mbekhar@` ajeno, `luis.garcia@` superadmin, `almacen@` sin permiso); peticiones HTTPS por nginx local del EC2; el job REAL de Horizon en la cola `pdf` de la conexión `redis-long` con S3 real (bucket privado de dev); exportación completa por CLI con `Http::fake` para las fotos e instrumentación por volumen (memoria, disco temporal, tiempo); Playwright CLI headless (NO Claude in Chrome); suite completa en una base aislada; todo lo creado se borró al terminar.

## Paso 0
- Permisos angostos: ninguno. Gate `ventas_diseno.read` para pedir y consultar; la consulta es además solo del solicitante o de un superadmin. Editar umbrales: `superadmin.all`.
- Valores paramétricos: el máximo de productos, el máximo de fotos, el tamaño de volumen y la retención del ZIP viven en `CrmSettingsService` con pantalla de superadmin (Configuración global → CRM), con rangos validados. Constantes técnicas (no de negocio): tope duro de 1.000 productos en el camino síncrono, 20 conexiones y plazos de las fotos, 5.000 `product_ids`.
- Dependencias de UI en otros tickets: ninguna. Datos: cubren los extremos (600 sin foto, 60 con foto propia, 100+ con foto externa intermitente, catálogo completo).

## (a) PDF plano hasta el máximo
| Caso | Resultado |
|---|---|
| 500 productos sin fotos | 200 `application/pdf`, 6,5 s, 1,4 MB |
| 60 con foto (bucket propio, precarga en paralelo) | 200 en 2,6 s (antes ~44 s), 29 KB por foto |
| 150 con foto del bucket propio (≤ 200) | 200 plano en 6,7 s |
| 100 con foto del proveedor externo | 200 en 9,9-15,6 s (antes 21 s con 10 conexiones, ~73 s en serie): 90, 101 y 99 de 100 imágenes embebidas |
| 220 con foto (> máximo de 200 fotos) | 202 en 0,54 s |
| 600 sin fotos (> 500) | 202 en 0,81 s, 2 volúmenes |

## (b) Job real de Horizon + S3 + descarga
`POST send-pdf` con 600 productos → **202** con `export_id` (uuid; el id interno y la clave de S3 nunca se exponen) → el job real en `supervisor-pdf` (`redis-long:pdf`) pasó a `done` en **10 s** → `GET pdf-exports/{uuid}` devolvió `download_url` (presigned de 15 min) → la URL bajó un **ZIP con 2 PDF válidos** (`%PDF`) → aviso in-app al solicitante.

## (c) Exportación completa del catálogo (29.630 productos, sin fotos, CLI con memory_limit 768M)
| Métrica | Valor |
|---|---|
| Volúmenes | 60 de 500 productos |
| Tiempo total | **467 s (7,8 min)**, ~7,7 s por volumen |
| Memoria | 51 MB (vol. 1) → 100 MB (vol. 60); pico **176 MB** (dompdf acumula ~0,8 MB por volumen); límite 768 MB |
| ZIP | **84,6 MB** en S3 |
| Disco temporal | pico 84 MB; sin restos al terminar |
| Otro endpoint durante la corrida | 87 de 87 respuestas 200, p50 344 ms, máx. 1.080 ms (una CPU saturada) |
Estimado con fotos (no medido, decisión de Luis): ~10,9 mil fotos a ~35-83 ms efectivos ≈ 6-15 min más de descarga; el job tiene 2 h.

## (d) Permisos y validaciones — 22 de 22 en la batería de API
Otra persona con permiso de lectura → **404** (igual que inexistente); superadmin → 200; sin token → 401; sin `ventas_diseno.read` → 403; uuid mal formado/inexistente → 404; `product_ids` de 5.001 → 422; modo inválido → 422; `seleccionados` vacío → 422; `send-pdf` sin token → 401. Umbrales: `GET /crm/settings` con los 4 defaults (500 / 200 / 500 / 7); rangos inválidos → 422; un no-superadmin → 403.

## (e) Purga programada
`catalog-pdf:purge-exports` sobre una exportación vencida: fila y objeto de S3 borrados; también cerró las 2 exportaciones restantes de la limpieza final (S3 en 0 objetos). Agendado a las 04:00 (`schedule:list`).

## (f) UI real (Playwright, `fefi@`/`mbekhar@`) — 4 de 4
Spec permanente `atlanticerp-frontend/e2e/preqa-scrum998-catalogo-zip-20260929.spec.ts` (necesita `E2E_EXPORT_ID` de una exportación terminada; se salta sin ella): el enlace del aviso abre el Catálogo con el banner "Tu catálogo está listo: 600 productos en 2 volúmenes (2.4 MB en un ZIP)" y el botón descarga el **ZIP real de S3** (`Catalogo-Atlantic-…zip`); pedir el catálogo completo con 202 muestra el aviso "demasiado grande para un solo PDF" y el banner sin descargar ningún PDF; el aviso in-app está en la campana y su enlace abre la descarga; una exportación ajena se muestra como no encontrada. Sin 5xx.

## (g) Backend y frontend
- 24 tests nuevos del backend en `CatalogPdfExportTest` (umbrales, 202 vs PDF plano, job, ZIP, idempotencia, fallo, permisos, purga, fotos y reintento), corridos junto con los de ajustes de CRM y Catálogo existentes. Suite completa en base aislada: **4.057 OK** (1 omitido, ya existente); PHPStan Level 8 sobre todo el proyecto limpio.
- Frontend: vitest completo **2.088 OK** (186 archivos), `tsc` limpio; tests nuevos de `sendPdf` (200 vs 202, cuerpo del 202 leído como Blob), de la página (aviso, banner, descarga con URL recién pedida, fallida, 404, cerrar) y del panel de ajustes.

## Hallazgos (todos corregidos y verificados en este mismo Pre-QA)
1. **MEDIO — un PDF plano de ≤ 500 productos con muchas fotos toparía el timeout de 60 s de nginx.** 100 fotos del proveedor externo tardaron 21 s con 10 conexiones; 500 productos que todos tengan foto serían ~100 s. **Corrección:** máximo de fotos configurable del PDF plano (`catalog_pdf_max_single_photos`, default 200); si se excede va al ZIP en segundo plano (verificado: 220 con foto → 202).
2. **MEDIO — el proveedor de fotos es intermitente y una foto caída se perdía.** Con 20 conexiones una corrida dio 23 de 100 `cURL error 28` (conexión agotada a los 2 s) y la siguiente ninguna, con las mismas URLs (que en serie responden todas 200 `image/jpeg`). **Corrección:** un solo reintento de lo transitorio (excepción/timeout, 5xx, 408, 429) con 10 conexiones y plazos de 5/10 s; un 404 no se reintenta. Verificado: el mismo subconjunto pasó de 68 a 90 de 100 fotos embebidas, y otros dos subconjuntos llegaron a 99 y 100.
3. **BAJO — margen del job.** 60 minutos era ajustado para el peor caso con fotos; ahora 2 h (`$timeout` 7.200 s, supervisor `pdf` 7.200 s, `retry_after` de `redis-long` 7.800 s, mayor que el timeout para que Horizon no libere el job a otro worker mientras corre).

## Observaciones (no bloquean)
- **Contención de la suite local:** el gate de `infra/deploy-manual.sh` usa `infra/test.sh`, que borra los schemas de la base compartida `atlanticerp`; con otra sesión corriendo tests a la vez, un deploy falló con 36 errores y 34 fallos (tablas que desaparecen, ninguno de este lote) y hubo que reintentar cuando la base estaba libre (pasó a la primera vez). Sin cambios del código; queda como fricción de proceso entre sesiones paralelas.
- ~10 % de las fotos del proveedor externo siguen sin embeberse en momentos de mala respuesta del proveedor (quedan como "Sin imagen", el PDF no se frena).
- El Horizon real se ejercitó con una exportación pequeña (600 productos, 10 s); la exportación completa de 60 volúmenes se midió por CLI con las mismas clases (sin la cola).

## No verificado
Exportación completa **con** las ~10,9 mil fotos (Luis eligió no golpear al proveedor en el Pre-QA); test y prod (mismo código, volumen de catálogo casi igual: 29.555 y 29.506 activos; el deploy agrega migración, conexión de cola `redis-long` y el supervisor `pdf`, todo verificable al desplegar); 30 minutos sin 5xx tras el deploy.

## Lo que sí funciona
PDF plano con el layout y la RN1 de siempre, umbral por productos y por fotos, 202 + job real de Horizon + ZIP en S3 con URL firmada, permisos (solo el solicitante o superadmin), purga, aviso in-app con enlace, banner y descarga en la UI, umbrales editables por superadmin con rangos, exportación completa de 60 volúmenes en 7,8 min con 176 MB de pico, precarga de fotos en paralelo con reintento (~3-7 veces más rápida que en serie), limpieza total de lo creado.

## Veredicto
**Pasada limpia y concluyente**: los tres hallazgos se corrigieron, se pushearon a `dev` y se verificaron en vivo; sin hallazgos abiertos. Listo para pasar a `QA`.
