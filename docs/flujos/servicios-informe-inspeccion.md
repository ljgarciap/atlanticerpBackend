# Flujo: informe de inspección completo y documentos de la cotización

Continuación de [Servicios: tickets](servicios-tickets.md) (§4 resumió el informe) y [hoja de reclamo y reportes](servicios-reclamo-reportes.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-03 con las cuentas demo (`npm run e2e:flujos`, archivo `servicios-informe-inspeccion.spec.ts`, 6 pasos). No apareció ningún defecto: todo lo ejercitado se comporta como el código lo declara.

## 1. Cuándo existe un informe y qué formulario trae
- Lo generan los tickets de instalación, garantía y retrofit. **Un reclamo no**: `fields`, `show` y `pdf/blank` responden error y `PUT` da 422 (el reclamo usa la hoja de reclamo) [E].
- `GET …/inspection-report/fields` devuelve el formulario dinámico (`key`, `label`, `type`, `options`, `ticket_product_id`). Lo lee cualquiera que vea Servicios, y **cada tipo/subtipo trae su propio conjunto de campos** [E]. Si el ticket tiene productos, se repite la plantilla por producto [C].
- Sin informe, `GET …/inspection-report` responde 404 [E].

## 2. Guardar (modo formulario)
`PUT …/inspection-report`: una sola acción, **no hay borrador**; guardar es completar [C].
- `conclusion` obligatoria y no puede ser solo espacios (422) [E].
- `internal_technician_id` debe atender el tipo del ticket (un técnico de garantías en una instalación, 422) [E]; `catalog_product_id` de un material debe existir (422) [E].
- Escriben solo el **técnico asignado al ticket** o el Líder; vendedor, Gerencia y otro técnico, 403 [E].
- Folio `INF-AAAA-NNNN` en el primer guardado; **reeditar no lo cambia** y el Líder puede reeditar el informe del técnico [E].
- `requiere_seguimiento = false` borra `proximos_pasos`; con `true` se conservan [E]. Una fila de material vacía se descarta sin error [E].
- Deja `tickets.inspection_report_status = completed`, que abre la compuerta de cierre y la de la cotización [E].

## 3. Fotos «antes/después»
`POST …/inspection-report/photos` (multipart `categoria` + `file`).
- Exige que el informe ya exista (422 «Guarda el informe antes…») [E]. Categoría solo `antes` o `despues`; solo imágenes (un PDF, 422) [E].
- Solo quien puede escribir el informe (otro técnico y vendedor, 403) [E].
- El informe devuelve `fotos[]` con `url` y esa URL **se abre** (200, `image/png`) con el almacenamiento local [E].

## 4. Modo «archivo subido»
`POST …/inspection-report/upload` (multipart `archivo`, firmas).
- La primera vez **exige archivo** (422); tipo no permitido (`.exe`), 422; vendedor, 403 [E].
- Con PDF queda `modo = archivo_subido`, `estado = completed`, folio `INF-…`, sin conclusión, y `archivo_adjunto_url` se abre [E].
- Reeditar sin archivo conserva el anterior [E]. Pasa también el ticket a `completed` [E].

## 5. Informe móvil con firma gráfica
`POST …/inspection-report/mobile` (multipart: `payload` JSON + `firma_cliente_imagen` PNG/JPG ≤ 2 MB).
- Rechaza (422) sin firma, con firma que no es imagen, sin conclusión, sin `firma_cliente_nombre` y con `payload` que no es JSON; otro técnico, 403 [E].
- **Nada se guarda hasta confirmar la firma**: tras los intentos fallidos el informe sigue sin existir (404) [E].
- Con todo correcto queda `completed` con la URL de la firma, que se abre [E].
- **Una firma confirmada no se reemplaza, ni por el Líder** (409) [E].

## 6. PDF y documentos
- `GET …/inspection-report/pdf`: lo baja cualquiera que vea Servicios, solo si el informe existe (si no, 404) [E].
- `GET …/pdf/blank` (plantilla por tipo/subtipo): Líder y técnico interno 200; vendedor, Gerencia y técnico de garantías, 403 [E].
- **Cotización**: no se puede generar hasta que el informe esté completado o no aplique (422 con ese mensaje) [E]. Con informe: borrador → ítem → enviar → decidir (rechazada). El historial `…/quotes` la lista y `…/quote/{id}/document` baja el PDF formal para quien lea Servicios; un id inexistente, 404 [E].

## Candidatos a cambio o mejora
(Solo documentados; ninguno se implementó.)
1. **El consumo de materiales de la Reserva se aplica solo en el primer guardado** (`applyConsumption = isNew`): si el técnico corrige después un material o su cantidad, el Kardex no se ajusta. [C]
2. **Guardar de nuevo como formulario borra el archivo adjunto** (`archivo_adjunto = null`), y guardar en modo archivo borra hallazgos, conclusión y seguimiento, sin aviso. [C]
3. **Una firma confirmada en el móvil se protege solo en esa ruta**: `PUT …/inspection-report` de escritorio no comprueba `firma_cliente_imagen` y sigue pudiendo cambiar el contenido ya firmado. [C]
4. **Las fotos no se pueden borrar ni reemplazar** (no hay ruta) y se pueden seguir añadiendo con el informe completado. [C]
5. **No hay borrador**: guardar equivale a completar, y desbloquea el cierre del ticket aunque el informe esté a medias salvo la conclusión. [C]
6. **Los formularios por tipo/subtipo están fijos en el código** (`STATIC_FIELDS_BY_TIPO`), no en un catálogo por empresa. [C]
7. **Sin ejercitar**: el flujo en el navegador (modal, lienzo de firma), HEIC en fotos y el consumo de la Reserva con `catalog_product_id` real.
