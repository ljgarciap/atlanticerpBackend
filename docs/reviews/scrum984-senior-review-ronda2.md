# Senior Review SCRUM-984 — Ronda 2

Revision independiente del estado actual (backend 0d876d7c + e6ab031a + dd215fc8; frontend 8fd0655 solo 984, 92fb0c5, be8b115).

## Parte 1 — Backend

Verificacion: `infra/test.sh --filter='Biblioteca|PermissionCatalogTest'` 61 tests / 360 aserciones OK. PHPStan full-project (1G) sin errores.
Ademas: el escaner se replico en un script aparte y se corrio contra 10 PDFs reales locales (Word/Chrome/otros, 78 KB a 28 MB): 0 falsos positivos.

### Hallazgos

- **ALTO — IAM sin `s3:GetObjectVersion`.** `S3LibraryStore.php:143-152` (`readRange` con `VersionId`) y `:195-211` (`CopySource ...?versionId=`) exigen `s3:GetObjectVersion`; la policy de `docs/runbooks/biblioteca-s3-aprovisionamiento.md:129` y `docs/architecture/biblioteca-manuales-s3-diseno.md:92` solo da `GetObject/PutObject/DeleteObject/Abort.../ListMultipartUploadParts`. Con bucket versionado, el primer `readRange` de finalize (magic bytes) responde 403 AccessDenied y TODA subida real termina en 500; los tests no lo ven porque usan `FakeLibraryStore`. Fix: agregar `s3:GetObjectVersion` (sobre `library/*`) a las policies de los 3 usuarios `atlanticerp-library-{env}` (y al runbook/diseno; NO agregar `DeleteObjectVersion`), y probar un finalize real contra dev antes de Pre-QA. No ejecute AWS: hay que verificarlo contra lo ya aprovisionado.
- **MEDIO — Bypass del escaner por delimitadores PDF omitidos.** `PdfActiveContentScanner.php:45` usa el lookahead `[\s/\[\]<>()%]|$`. `\s` de PCRE no incluye NUL (0x00) y faltan `{` `}`, que en PDF son whitespace/delimitadores. Comprobado: `/S/JavaScript\0/JS\0(x)` y `/S/JavaScript}` devuelven limpio (con `\f` si detecta). Fix: lookahead `[\x00\s/\[\]<>(){}%]|$` y un test con `\0` y `}`. El limite de object streams comprimidos ya esta documentado en config; con eso y este arreglo el escaner sigue siendo barato, no un antivirus (aceptable para un uploader con `manuales.manage`).
- **MEDIO — Sin manejo de errores de S3 tras validar (version inexistente / AccessDenied).** `LibraryService.php:188-208`: `readRange`/`copy` lanzan `S3Exception` (NoSuchVersion, 403, 5xx) que sube como 500; el pending y el registro de upload quedan, el cupo no se libera hasta expirar el TTL, y el usuario solo ve un error generico. Fix: en `finalizeLocked` capturar la excepcion de S3 (via el contrato, p. ej. `LibraryStoreException`) y convertirla en `LibraryRejectedException` con `discardPending` cuando sea NoSuchVersion; 503/reintentable cuando sea transitorio. Agregar test con un fake que lance.
- BAJO — Falso positivo posible `PdfActiveContentScanner.php:45`: un `/URI (https://sitio/JS/...)` sin comprimir (los enlaces de Word van en claro) coincide con `/JS` + `/` (probado). Improbable en los 7 manuales, pero rechazaria un manual con un enlace asi. Los 10 PDFs reales probados dieron limpio, asi que se acepta el riesgo; si aparece un caso, la salida es exigir contexto (`/S\s*/JavaScript`, `/JS\s*[(<]`) en vez de solo el nombre. Al menos que el mensaje de rechazo oriente al usuario.
- BAJO — `config/biblioteca.php` `pdf_scan_max_bytes` 100 MB vs `max_pdf_mb` hasta 1024 en `PUT /config`: un PDF mayor a 100 MB solo se escanea en el primer tramo (las actualizaciones incrementales van al final). Fix: rechazar en finalize cuando `size > pdf_scan_max_bytes` (o escanear todo); hoy el default de 50 MB lo cubre.
- BAJO — Fallo abierto si PCRE falla: `preg_match === 1` y `(string) preg_replace_callback` tratan `false/null` como "limpio" (`:45`, `:60`). Fix: si `preg_last_error() !== PREG_NO_ERROR` rechazar.
- BAJO — `database/seeders/SpecialPermissionSeeder.php:70-73`: el comentario y la entrada de Gerencia1 se insertaron entre el comentario de `gerencia2` y su entrada; el comentario de Zona Libre ahora queda encima de Gerencia1. Cosmetico, mover la entrada.
- BAJO — Tests: no hay test de `S3LibraryStore::copy` (formato `CopySource`, `CopySourceIfMatch` sin versionado) ni del camino "version inexistente"; `test_finalize_repetido...` (577) solo prueba el 404 del segundo finalize. Sugerido: test con `Aws\MockHandler` que verifique el `CopySource` generado.

### Verificado OK (independiente)
1. Contrato backend: `init` espera `module,name,filename,content_type,size_bytes` (`BibliotecaController.php:102-108`); `finalize` 201 shape publico; `url` devuelve `{url,expires_at}`; `list` array plano; `config` = `toApi()`.
2. CopySource: `bucket/` + `rawurlencode(clave)` con `%2F`→`/` + `?versionId=` URL-encoded; es el formato del SDK. Sin versionado cae a `CopySourceIfMatch` con el ETag entrecomillado (correcto). Content-Type canonico con `MetadataDirective=REPLACE`. Falta el permiso IAM (ALTO arriba) y el manejo de error.
3. Cuota atomica: `Cache::add` + `increment` + comparar; `decrement` al pasar el tope; `presignPut` fallido libera (test); `forgetUpload` usa `pull` (un finalize repetido no decrementa dos veces); `releasePending` no baja de 0. Rutas que no liberan (`rejected_missing`, 5xx) dejan el upload reintentable y el contador expira con el TTL de la clave (`ttl+600`, no se renueva: no puede bloquear "para siempre", maximo ~40 min con los defaults). Ventana teorica de INCR sobre clave recien expirada sin TTL: despreciable, se recomienda `Cache::put` si `increment` devuelve 1.
4. Rate limiters `biblioteca-lectura` (60) / `biblioteca-escritura` (20): por `sub` decodificado y firmado, IP solo de respaldo; mismo patron que `fe-accion`; `GET /config` no lleva throttle (aceptable, permiso angosto).
5. GET y PUT `/config`: misma forma (`toApi()`), los derivados `max_*_bytes` y `modules` son solo lectura; PUT audita viejo/nuevo con `BIBLIOTECA_CONFIG_UPDATED`; `library_max_pending_uploads=5` sembrado por migracion; PUT solo `superadmin.all`.
6. Sin keys propias => `isConfigured()` false => 503 (test).
7. `PermissionCatalogTest` actualizado coherentemente (13 modulos / 33 permisos) y verde; migracion tenant con `insertOrIgnore` e `down()` simetrico; Gerencia1 en el seeder por persona (idempotente, test).
8. Tests: prueban comportamiento con un `FakeLibraryStore` versionado (re-PUT entre validar y copiar, Content-Type canonico, token partido en dos bloques, cupo tras rechazo, lock concurrente, limiter por usuario). No hay AWS real en PHPUnit, correcto.

### Veredicto backend: **DEVUELTO**
Un ALTO (permiso IAM que rompe la subida real) y dos MEDIO (bypass por NUL/llaves, errores de S3 sin manejar). Son arreglos chicos; tras ellos re-correr el filtro `Biblioteca` y probar una subida real en dev.

## Parte 2 — Frontend (8fd0655 solo lo del 984, 92fb0c5, be8b115)

Verificacion: vitest `lib/manuales`, `ManualesPage`, `components/manuales` 51/51 OK; `tsc --noEmit` sin errores; Playwright `e2e/manuales.spec.ts` 13/13 OK (mocks con page.route, sin depender de ningun entorno).

### Contrato campo por campo contra BibliotecaController
- `init` request: `module,name,filename,content_type,size_bytes` (`ManualesAdminPanel.tsx:122-128`, `types/manuales.ts:39-46`) = los `required` de `BibliotecaController.php:102-108`. Ya no hay `mime`.
- `init` response: `upload_id,url,headers,expires_at` = `LibraryService::initUpload`. El PUT usa `init.headers` (Content-Type canonico del servidor), no el del navegador.
- `finalize`: POST `/biblioteca/uploads/{id}/finalize`, 201 con `Manual`; 404 (subida expirada/ajena) y 422 (rechazo) clasificados en `lib/manuales.ts:119-129`.
- `url`: `GET /biblioteca/{id}/url?accion=ver|descargar` -> `{url,expires_at}`. `list`: array plano (acepta `{data}`). `delete`: 204.
- `config` (GET): el front solo usa `max_pdf_bytes`, `max_video_bytes`, `allowed_extensions` y `modules`, que siguen en `LibrarySettings::toApi()`; no depende de ninguna clave removida (`library_*` del PUT no se consumen; no hay pantalla de edicion de config, correcto, es solo superadmin por API).

### e2e: realmente falla ante la desalineacion
`e2e/manuales.spec.ts` (be8b115): el mock de `/uploads` devuelve 422 si falta cualquiera de los 5 campos requeridos (un `mime` en lugar de `content_type` lo dispara) Y el test de subida completa afirma con `toEqual` el body exacto del init, el PUT a S3 (metodo, URL, Content-Type, bytes) y la URL del finalize. Reintroducir `mime` rompe ambas cosas. Los dos casos 422 (cuota en init, contenido rechazado en finalize) verifican mensaje del backend y ausencia de "Reintentar". (No mute el codigo: revision de solo lectura; el razonamiento se verifico leyendo el mock.)

### Hallazgos
- MEDIO — `ManualesAdminPanel.tsx:126`: `content_type: file.type || mimeFromExtension(...)`. El backend exige igualdad exacta con el mime canonico de la extension (`LibraryService::assertMime`). `file.type` depende del navegador/SO (p. ej. `.webm` solo audio como `audio/webm`, `.mp4` como `video/x-m4v`, o valores raros en Windows): 422 "mime_mismatch" sin remedio para el usuario. No aporta nada enviar `file.type` porque el PUT ya usa el header canonico del servidor. Fix: enviar siempre `mimeFromExtension(fileExtension(file.name))`.
- BAJO — Cada "Reintentar" tras un fallo del PUT hace un `init` nuevo (`:122`) y deja un cupo de pendientes reservado hasta que expire (`ttl+600` s en backend); 5 reintentos fallidos agotan `library_max_pending_uploads` y el usuario queda con un 422 sin Reintentar por unos 40 min. Mitigacion: si el `init` ya existe y no vencio, reintentar solo el PUT/finalize con el mismo `upload_id`.
- BAJO — `lib/manuales.ts:127-128`: el 422 `finalize_in_progress` (doble clic/concurrente) se clasifica como no reintentable; es transitorio. Basta que `retryable` sea true para ese caso (o deshabilitar el boton mientras `busy`, que ya se hace: riesgo bajo).
- BAJO — Trazabilidad: lo del 984 en `Sidebar.tsx` (`IcoBookOpen`, seccion Manuales) quedo dentro de `8fd0655`, cuyo mensaje es solo de SCRUM-1009. Al pushear, documentarlo en el ticket (aislar el commit ya no es practico si 1009 ya esta mezclado).
- BAJO — Sugerido: e2e cubre el happy path con `application/pdf`; agregar un caso con `.mp4` sin `file.type` para fijar el fallback.

### Veredicto frontend: **APROBADO**
El CRITICO de la ronda 1 esta corregido y blindado por tests que fallan ante la desalineacion. Los hallazgos son MEDIO/BAJO no bloqueantes (recomendado arreglar el `content_type`).

## Veredicto global
Backend **DEVUELTO** (ALTO: falta `s3:GetObjectVersion` en las policies IAM; MEDIO: bypass por NUL/`{}` y errores de S3 sin manejar). Frontend **APROBADO**. No pasar a Pre-QA hasta cerrar el ALTO y verificar una subida real en dev.
