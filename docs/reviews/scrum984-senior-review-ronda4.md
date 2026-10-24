# SCRUM-984 — Senior Review, ronda 4 (comprobación puntual)

Alcance: backend `e2e0da24` (código) y `e36c7575` (docs), contra los hallazgos de `scrum984-senior-review-ronda3.md`. Solo lectura.
Pruebas: `infra/test.sh --filter='Biblioteca|PermissionCatalogTest'` = OK (106 tests, 512 aserciones; sin otro test.sh en `ps`). PHPStan nivel 8 (`--memory-limit=1G`) = `[OK] No errors`.

## Estado de los hallazgos de la ronda 3

### ALTO-1 (DELETE) — CERRADO
- `BibliotecaController.php:149` captura `LibraryStorageUnavailableException|LibraryObjectChangedException` y responde 503 con `storage_unavailable`, ya neutro en es/en ("Intenta de nuevo en unos segundos").
- `LibraryService::delete()` (`:326-364`): sidecar primero (`:340`), `invalidateCatalog()` (`:346`), objeto después con captura (`:350-354`), auditoría al final (`:356-358`).
- Fallo total (sidecar): nada cambia, sin auditoría, `biblioteca.storage_unavailable` con `step=sidecar`; el ítem sigue visible y el reintento funciona.
- Fallo parcial (sidecar borrado, objeto no): el catálogo se invalida antes, el ítem desaparece, se audita con `new_values.object_delete_failed`, log `biblioteca.orphan_object` y 503. Es un comportamiento correcto: lo invisible e inocuo es el objeto, nunca un ítem roto visible. No toca cupo ni locks (delete no los usa), así que no queda nada colgado. El reintento da 404 porque el ítem ya no existe: coherente.
- Tests: fallo total, parcial (con catálogo caliente, auditoría y log), orden (`deleteLog`), `LibraryObjectChanged` -> 503 y recuperación tras fallo total. Ejercen comportamiento real.

### MEDIO-1 (tope max_pdf_mb) — CERRADO
`BibliotecaController.php:71` usa `maxPdfMbCeiling()` (= `pdf_scan_max_bytes`/1 MB = 100) y `LibrarySettings::maxBytesFor('pdf')` (`:66`) hace `min(guardado, tope del escáner)`. Tests: 101 -> 422, 100 -> OK, tope dinámico 60/61, y valor guardado 200 -> `max_pdf_bytes` = 100 MB y un init de 150 MB da 422 en `size_bytes`. Video no se ve afectado.

### MEDIO-2 (translate) — CERRADO
`S3LibraryStore.php:250`: "objeto cambiado" solo para `NoSuchVersion|NoSuchKey|PreconditionFailed` o HTTP 412; NoSuchBucket, otro 404, AccessDenied y 400 -> `LibraryStorageUnavailableException` conservando el código AWS en el mensaje. Data provider con los 4 casos sobre `copy` y `readRange`, más el caso positivo NoSuchKey.

### MEDIO-3 (logging) — CERRADO
`logStorageFailure()` (`:633`) solo emite claves fijas: `upload_id`/`file_id`, `user_id`, `operation`, `step`/`key` (clave S3 interna), `aws_code`, `http_status`, `aws_request_id`. No incluye mensaje de la excepción previa, URL prefirmada, credenciales ni cuerpo de respuesta. Hay tests de `finalize` (error) y de limpieza del pending (warning) con `Log::spy()` y `withArgs` sobre contexto real: son tests genuinos (ver BAJO-2 sobre la aserción de "sin secretos").

### Docs — CERRADO
Runbook (§3, §4 y nota del solape) y diseño (tabla de dos reglas, §5 y línea 58) dicen lo mismo: `pending-staging-2d` = objeto a 2 días y no vigentes a 1 día en `_pending/`; `todo-el-bucket` = no vigentes a 90 días, delete markers huérfanos y multipart a 2 días. Coincide con los comentarios de `LibraryService`. Sin contradicciones (el valor real lo declara verificado contra AWS el 2026-09-30; yo no ejecuto aws).

## Hallazgos nuevos (ninguno bloqueante)
- **BAJO-1** `LibraryService.php:350-358`: el objeto huérfano de un borrado parcial queda como versión vigente fuera de `_pending/`, donde no hay regla `Expiration`; ningún lifecycle lo limpia. Es invisible y solo se detecta por el log/auditoría `object_delete_failed`. Anotar en el runbook que el operador lo borra a mano (o un barrido futuro); no afecta al usuario.
- **BAJO-2** `BibliotecaControllerTest.php` (test de logging de finalize): la aserción "sin secretos" solo busca la cadena `secret` en el contexto; es débil. La garantía real está en el código (claves fijas). Además no hay test de reintento tras el borrado parcial (el 404) y `test_un_valor_ya_guardado...` tiene un `assertNotSame('', $slug)` sin valor. Cosmético.
- **BAJO-3** `delete()`: si `delete(sidecar)` tuvo éxito en S3 pero la respuesta falló (timeout), se responde 503 sin `invalidateCatalog()`; el listado cacheado puede mostrar el ítem hasta el TTL (el `find(fresh:true)` del reintento da 404). Ventana ínfima y autocorrectiva.
- **BAJO-4** `docs/architecture/biblioteca-manuales-s3-diseno.md:42` aún dice "borra objeto + sidecar"; ahora es sidecar y luego objeto. Retoque de texto.

## Veredicto
- **Backend: APROBADO.** ALTO-1 y MEDIO-1/2/3 quedaron cerrados con tests reales; suite y PHPStan en verde.
- **Frontend `e144418`: APROBADO** (ronda 3, sin cambios).
- **El ticket puede pasar a push a dev, deploy y prueba de subida real** (que además validará la política IAM v2 contra S3 real). Los BAJO pueden ir en un seguimiento. El push sigue sujeto al gate de aprobación de Luis.
