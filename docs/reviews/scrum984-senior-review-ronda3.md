# SCRUM-984 — Senior Review, ronda 3 (delta)

Alcance: backend `48b5f4f5` + `86ae22d2`, frontend `e144418`. Solo lectura, sin cambios de código.
Pruebas: `infra/test.sh --filter='Biblioteca|PermissionCatalogTest'` = OK (93 tests, 449 aserciones); `npx vitest run src/components/manuales src/lib/manuales` = OK (56 tests). No había otra corrida de test.sh en `ps`.

## Backend

### 1. PdfActiveContentScanner — OK
- `NAME_END = (?![^\x00\s\/\[\]<>(){}%])` detecta `/JavaScript\0`, `/Launch{`, `/JS\0(`, fin de datos, `%`, y escapes `#00`/`#61` (se decodifican antes). No marca `/JSON`, `/JSX`, `/JavaScriptish` (tests de data provider, líneas ~690-725).
- Falla cerrado: `search()` devuelve false -> `[scan-error]`; tamaño > `pdf_scan_max_bytes` -> `[too-large-to-scan]`; ambos mapeados a `rejected_unscannable` (422) y auditados.
- Tamaños: `pdf_scan_max_bytes` = 100 MB, `library_max_pdf_mb` por defecto = 50 MB (LibrarySettings::DEFAULTS). Un manual legítimo de 50 MB pasa (7 bloques de 8 MB).
- Costuras de prueba: `search()` protegido (solo subclase, no hay binding en contenedor) y `S3LibraryStore::withClient()` público pero nadie lo llama en app/ (solo tests). No abren nada en producción.

### Hallazgos
- **MEDIO-1** `BibliotecaController.php:69` — `max_pdf_mb` admite hasta 1024 pero el escáner rechaza todo PDF > 100 MB (`config/biblioteca.php`). Si superadmin pone p. ej. 200, el usuario sube 150 MB enteros y el finalize lo rechaza con "no se pudo verificar". Fix: `max:100` en la regla de validación (o derivar el tope de `pdf_scan_max_bytes`) y, preferible, mismo mensaje en init (`assertSize`).
- **MEDIO-2** `S3LibraryStore.php` `translate()` — `in_array($e->getStatusCode(), [404, 412])` es demasiado amplio: un 404 `NoSuchBucket` (config errónea) en readRange/copy/putJson se clasifica como "objeto cambió", se descarta el pending, se libera el cupo y el usuario ve "vuelve a subirlo", sin reintento posible y sin señal de causa real. Fix: limitar a códigos `NoSuchVersion|NoSuchKey|PreconditionFailed` (y 412); todo 404 con otro código -> `LibraryStorageUnavailableException`.
- **MEDIO-3** `LibraryService.php` `finalizeLocked()` (catch de `LibraryStorageUnavailableException`) — solo se audita (`audit_logs`), no se loguea. Un 403 `AccessDenied` (política IAM mal aplicada, caso real ya ocurrido con N-1) se vuelve 503 "reintenta" indefinido sin señal para el operador (CloudWatch/laravel.log). Fix: `Log::error('biblioteca.storage_unavailable', ['upload_id'=>..., 'code'=>$e->getMessage(), 'previous'=>$e->getPrevious()?->getMessage()])` en ese catch (y en `deletePendingQuietly`, como `warning`).
- **BAJO-1** `PdfActiveContentScanner::scan()` — el fin de ventana cuenta como "fin de datos": un `/JS` justo al final de un bloque de 8 MB seguido de `ON` en el siguiente produce falso positivo de `/JSON`. Probabilidad ínfima, falla en el lado seguro. Sin acción (o anotar).
- **BAJO-2** `LibraryService::delete()` ver punto 3.

### 2. LibraryService / S3LibraryStore — OK con los MEDIO de arriba
- Mapeo correcto: versión inexistente/cambiada -> `rejectUpload` (limpia pending, `forgetUpload` libera cupo una sola vez vía `Cache::pull`, audita) -> 422 `rejected_changed`. `StorageUnavailable` -> se conserva pending y registro del upload (cupo), audita y 503 reintentable.
- Excepciones inesperadas no se tragan (solo `deletePendingQuietly` captura, y solo las dos de dominio, tras publicar o rechazar). `CredentialsException` u otras no-`AwsException` siguen llegando como 500.
- Cupo: INCR en init, DECR únicamente en `forgetUpload` (pull -> una vez) y en fallo de presign. Rutas nuevas consistentes, sin desbalance. Reintento tras copia parcial reutiliza `finalKey` (id = upload_id), sobrescribe sin duplicar.
- Lock: `finally { $lock->release(); }` cubre todas las salidas. 409 `{message, code:'finalize_in_progress'}` correcto y ubicado antes de `LibraryRejectedException` (clases hermanas, sin herencia).

### 3. DELETE /api/biblioteca/{id} — **ALTO-1, corregir ahora**
`BibliotecaController::destroy()` no captura `LibraryStorageUnavailableException` (ni `LibraryObjectChangedException`): con S3 caído o AccessDenied responde 500 genérico y, peor, `LibraryService::delete()` borra primero el objeto y luego el sidecar; si falla el segundo queda un ítem en el catálogo apuntando a un objeto ya con delete marker (visible pero roto) y sin `invalidateCatalog()`/auditoría.
Fix exacto:
1. `LibraryService::delete()`: invertir el orden — `delete(sidecarKey)` primero, luego `delete(objectKey)`; un fallo a medias deja un objeto invisible (inocuo, reintentable) y no un ítem visible roto. Llamar `invalidateCatalog()` y `audit` tras ambos.
2. `BibliotecaController::destroy()`:
```php
try {
    if (! $this->service->delete($id, $this->actor($request))) {
        return response()->json(['message' => trans('biblioteca.not_found')], 404);
    }
} catch (LibraryStorageUnavailableException|LibraryObjectChangedException) {
    return response()->json(['message' => trans('biblioteca.storage_unavailable')], 503);
}
return response()->json(null, 204);
```
3. Test: FakeLibraryStore que lanza `StorageUnavailable` en `delete` -> 503, el ítem sigue en el catálogo y no hay auditoría `file.deleted`. El texto `storage_unavailable` dice "finalizar": ajustar a un mensaje neutro ("Intenta de nuevo") para que sirva también al borrado.

### 5. Docs 86ae22d2 — OK
Política v2 correcta: statement `ReadPendingVersionsForCopy`, `s3:GetObjectVersion` solo sobre `library/_pending/*`; el contenido publicado se sirve con GetObject vigente; la bucket policy sigue negando `DeleteObjectVersion`. Coherente con lo que hace `copy()` (CopySource `?versionId=`). Sin afirmaciones falsas detectadas.
- **BAJO-3** El runbook dice que del solape de lifecycle "aplica la más corta (1 día)", pero la regla se llama `pending-staging-2d` y `LibraryService::deletePendingQuietly` documenta "2 días". No pude verificar contra AWS (prohibido); confirmar el valor real y unificar el texto.

## Frontend e144418

### 4. Resultado — OK (sin bloqueantes)
- PUT: `{ 'Content-Type': canonicalMimeFor(file.name), ...session.init.headers }`: el mime canónico es el piso y un `Content-Type` firmado por el backend lo pisa (mismo nombre de cabecera exacto que emite `presignPut`: `Content-Type`). Test lo cubre (línea ~267).
- Sesión: `key = name|size|lastModified|module|nombre` -> no reutiliza URL de otro archivo/módulo/nombre; `pickFile` y `resetForm` la anulan; si expira en < 60 s o no trae fecha, init nuevo; `putDone` -> solo finalize; 403 de S3, 404 del finalize y todo no reintentable la limpian; éxito la limpia. No queda estado colgado.
- 409 o `code: 'finalize_in_progress'` reintentable, sin depender del texto; 404 del finalize -> init nuevo. Tests prueban comportamiento real (URL reutilizada, cupo no gastado, solo finalize repetido).
- Texto de borrado honesto en es y en ("dejará de estar disponible"), coherente con borrado lógico.
- **BAJO-4** `ManualesAdminPanel.tsx` — si el usuario cambia módulo o nombre tras un error, se hace un init nuevo y la subida anterior queda en cupo hasta su TTL (máx. 5 pendientes). Aceptable; opcional: avisar o liberar.
- **BAJO-5** Un `S3PutError` 4xx distinto de 403 (p. ej. 400) sigue marcándose reintentable con la misma URL. Inocuo (el usuario ve el error), sin acción.

## Veredictos
- **Backend: DEVUELTO** por ALTO-1 (DELETE devuelve 500 y puede dejar un ítem roto visible). Fix pequeño y acotado (arriba). MEDIO-1/2/3 se recomienda corregirlos en el mismo commit; no bloquean solos.
- **Frontend: APROBADO.**

**Push a dev / deploy / prueba de subida real:** NO todavía. Tras corregir ALTO-1 (y preferible MEDIO-1/2/3) con su test, basta una re-verificación puntual del delta; entonces sí puede pasar a push a dev, deploy y la prueba de subida real (que también validará la política IAM v2 contra S3 real).
