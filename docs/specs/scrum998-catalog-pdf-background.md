# SCRUM-998 — PDF de catálogo con muchos productos: PDF plano hasta un tamaño máximo, volúmenes en ZIP en segundo plano si se excede

**Date**: 2026-09-29
**Ticket**: SCRUM-998 (auditoría SCRUM-995, sitio E) · Feature afectada: SCRUM-702 / REQ-622 (`POST /api/ventas-diseno/catalog/send-pdf`)
**Status**: **APROBADO por Luis 2026-09-29** (defaults 500 / 500 / 7 días, aviso solo `in_app`, precarga de fotos en paralelo), listo para PM — en implementación
**Relacionada**: `scrum995-catalog-hydration-audit.md`

## Problema
`CatalogPdfService::generate('completo')` muere con `Allowed memory size of 268435456 bytes exhausted` (pico 242 MB) con el catálogo real de
Lafayette (29.630 productos activos): 500 opaco en PHP-FPM. La medición de abajo muestra además que el problema no es solo del catálogo completo:
con `max_execution_time = 30` (PHP-FPM) un PDF con pocas decenas de fotos, o con ~1.500 productos, ya se corta.

## Mediciones (dev, catálogo real, servicio actual, `memory_limit=1024M` para ver el techo real)

Sin fotos:

| Productos | Tiempo | Pico de memoria | PDF | Páginas |
|---|---|---|---|---|
| 100 | 1,2 s | 26 MB | 1,0 MB | ~17 |
| 500 | 6,1 s | 84 MB | 1,4 MB | ~84 |
| 2.000 | 45,2 s | **299 MB** | 3,1 MB | ~334 |

Crecimiento más que lineal en tiempo (×4 en filas, ×7,4 en tiempo) y ~0,15 MB por producto (dompdf mantiene todo el árbol hasta renderizar). Extrapolado
a 29.630 productos: ~5.000 páginas, ~4,4 GB y horas de CPU en un solo documento.

Con fotos remotas (`photo_url`, `isRemoteEnabled` desde SCRUM-844): 50 fotos → 36,5 s, 3,5 MB; 200 fotos → 146,9 s, 10,5 MB. ≈ **0,73 s por foto** (dompdf
las descarga en serie) y ≈ 52 KB por foto embebida. ~10.9K de los ~29.6K productos activos tienen foto (todas `photo_url` legacy remota) y casi ninguno tiene
ficha técnica, así que la tarjeta es chica (foto, referencia, marca/nombre, precio).

Límites reales del entorno: PHP-FPM `max_execution_time = 30 s`. Solo está instalado dompdf (`barryvdh/laravel-dompdf` 3.1.2 / `dompdf/dompdf` 3.1.5): sin
librería de PDF en streaming ni de unión de PDFs (FPDI, `pdfunite`).

## Decisión de Luis (2026-09-29)
El caso de "catálogo completo" es remoto. Se fija un **tamaño máximo para generar un PDF plano** (como hoy, síncrono) y **solo si se excede** se usa la solución
de **volúmenes en ZIP en segundo plano**. Ese máximo es un umbral de negocio: **va en una tabla paramétrica configurable** (regla del proyecto), no como constante.

## Diseño propuesto (el Arquitecto lo somete a aprobación de Luis)

**1. Parámetros configurables** en `CrmSettingsService` / `CrmSettingsController` (el mismo patrón `intSetting()` de SCRUM-944/945, con su pantalla de
configuración para superadmin; agregar los campos al panel existente):
- `catalog_pdf_max_single_products` — máximo de productos para un PDF plano síncrono. **Default 500** (medido: 6,1 s, 84 MB).
- `catalog_pdf_volume_size` — productos por volumen del ZIP. **Default 500** (mismo criterio de tanda).
- `catalog_pdf_export_retention_days` — días que se conserva un ZIP generado. **Default 7**.

**2. `POST /api/ventas-diseno/catalog/send-pdf`** (`mode=completo|seleccionados`, mismo request y permiso `ventas_diseno.read`): cuenta los productos a incluir
sin hidratar (`COUNT`, o `count(product_ids)`).
- `N <= catalog_pdf_max_single_products` → **igual que hoy**: descarga del PDF (200), mismo layout, misma RN1.
- `N > catalog_pdf_max_single_products` → crea un registro de exportación y encola el job; responde **202** `{export_id, status: "queued", total_products, volumes}`.
- `seleccionados` acota además `product_ids` a un máximo razonable (validación 422) para no armar `whereIn` gigantes.

**3. Precarga de fotos en paralelo** en `CatalogPdfService` (sirve a ambos caminos): antes de renderizar una tanda se descargan sus fotos con `Http::pool`
(concurrencia ~10, timeout de 3 s por foto) y se embeben como data URI; una foto que falla o tarda se reemplaza por el recuadro "Sin imagen" en vez de
frenar el PDF. Baja las ~0,73 s por foto en serie a ~0,07 s efectivos y evita el corte de 30 s también en el camino síncrono. Es un cambio de comportamiento
acotado (foto lenta = "Sin imagen") que conviene que Luis confirme.

**4. Job `GenerateCatalogPdfVolumesJob`** (Horizon, cola dedicada `pdf`, `memory_limit` 512 MB y timeout propios, idempotente por `export_id`,
`backoff()` [5 s, 30 s, 120 s]): recorre el catálogo activo con `chunkById(catalog_pdf_volume_size)` (nunca `get()->all()`), genera **un PDF por tanda** con
el mismo servicio y layout (título "Catálogo de productos — volumen i de n"), los guarda en S3 (`ventas-diseno/catalog-exports/{uuid}/…-vol-NN.pdf`, privado),
arma un ZIP con `ZipArchive` desde archivos temporales, lo sube y marca la exportación `done`. Ante un fallo marca `failed` con un mensaje seguro (sin trazas)
y **avisa igual**: nunca queda un enlace roto. Un job repetido sobre una exportación `done` no hace nada. Estimado con el catálogo real: ~60 volúmenes;
6 s por volumen sin fotos, unos 15-20 s con fotos precargadas en paralelo → del orden de 15-20 min en total.

**5. Tabla `ventas_diseno.catalog_pdf_exports`** (migración revisada por el Arquitecto): `id`, `uuid`, `requested_by` (FK `auth.users`), `mode`, `product_ids`
(JSONB, solo `seleccionados`), `total_products`, `volumes`, `status` (`queued|running|done|failed`), `storage_key`, `size_bytes`, `error_message`,
`created_at`, `started_at`, `finished_at`, `expires_at`. `GET /api/ventas-diseno/catalog/pdf-exports/{id}`: estado y, si `done`, una presigned URL de 15 min;
solo el solicitante o superadmin (403/404 para cualquier otro). Un comando programado borra archivos y filas vencidas según `catalog_pdf_export_retention_days`.

**6. Aviso al terminar** con `NotificationService::send()` (canal `in_app`, `source` propio, `cta_url` a la descarga), al solicitante; en `failed` el aviso lleva
el motivo. Reusa el módulo de notificaciones existente (campana del TopBar).

**7. Frontend** (Catálogo): si `send-pdf` responde 202 muestra "Se está generando el catálogo en varios volúmenes; te avisaremos cuando esté listo" y no
intenta descargar; el enlace de la notificación abre la descarga. Campos nuevos en el panel de configuración CRM.

## Ajustes del Pre-QA (2026-09-29, medidos en dev con el catálogo real)

El diseño aprobado se mantuvo; el Pre-QA agregó tres ajustes y midió la exportación completa:

1. **Máximo de fotos del PDF plano** (`catalog_pdf_max_single_photos`, default 200, configurable por superadmin, 1-500). Cada foto remota es una
   descarga: 100 fotos del proveedor externo tardaron 21 s con 10 conexiones, y un PDF plano de 500 productos que todos tengan foto tocaría el timeout
   de 60 s de nginx. El PDF plano exige productos <= máximo **y** fotos <= máximo; si no, va al ZIP en segundo plano.
2. **Precarga con reintento.** El proveedor de fotos (lafayette.app.enxcloud.com, 8.441 de las ~10,9 mil) es **intermitente**: con 20 conexiones una corrida
   dio 23 de 100 `cURL error 28` (conexión agotada a los 2 s) y la siguiente ninguna, con las mismas URLs (que en serie responden todas 200). Ahora: primera
   pasada con 20 conexiones (`connectTimeout` 3 s, `timeout` 6 s) y **un solo reintento** de lo transitorio (excepción de conexión/timeout, 5xx, 408, 429) con
   10 conexiones y plazos de 5/10 s; un 404 o una imagen inválida no se reintenta y queda como "Sin imagen".
3. **Job con más margen**: `$timeout` de 2 h (supervisor `pdf` 7.200 s; `retry_after` de `redis-long` 7.800 s). Estimado con fotos: ~8 min de render + ~15 min de fotos.

Exportación completa **sin fotos** en dev (29.630 productos, 60 volúmenes, `run()` por CLI con `Http::fake` para las fotos): **467 s** (7,8 min), memoria de 51 MB
(volumen 1) a 100 MB (volumen 60) con pico de **176 MB** (límite 768 MB; dompdf acumula ~0,8 MB por volumen), ZIP de **84,6 MB**, disco temporal máximo de 84 MB y
sin restos al terminar; un endpoint ajeno midió 87 de 87 respuestas 200 durante la corrida (p50 344 ms, máx 1.080 ms).

## Criterios de aceptación
1. `send-pdf` con `N <= máximo` devuelve el PDF plano como hoy (200), con tiempo < 25 s y pico < 128 MB medidos con 500 productos con y sin fotos.
2. Con `N > máximo` (incluido el catálogo completo de ~30K) responde 202 en < 1 s, nunca 500, y el job termina con un ZIP descargable de ~60 volúmenes.
3. El job respeta su `memory_limit`/timeout, no bloquea otras colas de Horizon y no hidrata más que una tanda (test de regresión con conteo de modelos).
4. RN1 se cumple en cada volumen y en el PDF plano (ningún dato de stock/disponibilidad/costo/margen; test sobre el contenido).
5. Fallo del job → estado `failed` + aviso con motivo; un job repetido no duplica archivos; el ZIP y las presigned URLs son privados y solo del solicitante.
6. Los umbrales se leen de `CrmSettingsService` (nunca constantes), editables por superadmin, con validación de rango y sus tests.
7. Una foto que falla o tarda se reemplaza por "Sin imagen" sin frenar el PDF; con las fotos precargadas un volumen de 500 productos con ~37 % de fotos tarda < 30 s.
8. Medido en dev/test con el catálogo real: tiempo total, pico por tanda, tamaño del ZIP.

## Decisiones de Luis sobre los puntos abiertos (2026-09-29, resueltas)
- Defaults 500 / 500 / 7 días, ajustables luego desde la pantalla de configuración.
- Aviso solo `in_app` (sin correo).
- Precarga de fotos en paralelo aprobada (foto lenta o que falla = "Sin imagen").
