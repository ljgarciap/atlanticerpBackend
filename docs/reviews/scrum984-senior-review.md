# Senior Review SCRUM-984 — Manuales de Usuario / biblioteca S3

## Parte 1 — Backend (commit 0d876d7c)

Verificación: `infra/test.sh --filter='Biblioteca|PermissionCatalogTest'` 43 tests / 246 aserciones OK; PHPStan full-project (1G) sin errores.

Veredicto parcial backend: **APROBADO** (sin CRÍTICO/ALTO).

### Lo verificado OK
- Gate en ruta: `manuales.manage` en /config, /uploads, /finalize, DELETE; PUT /config con `permission:superadmin.all`; lectura/URL para cualquier autenticado (grupo `api` aplica JWT). `manuales.manage` está en catálogo (migración, sort 102) y Gerencia1 (`gerencia@test.com`, existe en CoreUserSeeder) en SpecialPermissionSeeder.
- Topes/formatos/TTL/cuota en `auth.system_settings` (LibrarySettings + migración con seeds; `library_max_pending_uploads=5`); constantes en config/biblioteca.php son solo técnicas (módulos, mime, catálogo 60 s). Cambios de config quedan auditados por los triggers DB de `system_settings`.
- `copyObject` del SDK directo (S3LibraryStore::copy), nunca `Storage::copy`. Claves generadas por el servidor (uuid + ext de lista blanca), nombre saneado solo en sidecar, Content-Type derivado de la extensión, Content-Disposition con filename ASCII + filename*. HeadObject + tamaño + magic bytes en finalize, pending descartado en rechazo. 503 sin bucket vía middleware. Auditoría de subida/borrado/descarga (entity_id string 100, uuid cabe).
- Listado S3: paginador `ListObjectsV2`, prefijo por módulo validado con `Rule::in`; sin `get()` de catálogo de productos. Sin emojis.

### Hallazgos
- MEDIO — `BibliotecaController.php:62-76` vs `LibrarySettings::all()`: `GET /config` devuelve `max_pdf_bytes/max_video_bytes/allowed_extensions/modules` pero `PUT /config` devuelve claves `library_*` (MB, segundos). Dos formas distintas para el mismo recurso; el frontend no debe reutilizar la respuesta del PUT como si fuera la del GET (verificar en Parte 2). Fix: que PUT devuelva la misma forma que GET (o invalide la query).
- BAJO — `LibraryService.php:281-297`: en cache miss se hace un `GetObject` secuencial por sidecar (N+1) y `delete()` fuerza `readSidecars(null)` completo. Aceptable con catálogo de 7–decenas de archivos; documentar el límite y considerar ListObjects + paralelismo si crece.
- BAJO — `LibraryService.php:454-459`: contador de pendientes con `Cache::add`+`increment` tiene TTL fijo desde la primera subida (no se renueva) y no es atómico con el chequeo de `assertPendingQuota`; carrera menor, cuota de 5 es blanda. Un finalize rechazado por `rejected_missing` no libera cupo hasta que expire.
- BAJO — `finalizeUpload` sin lock: dos finalize concurrentes del mismo uploadId podrían copiar dos veces (mismo destino, idempotente). Sin impacto real.
- BAJO — Huérfanos en `_pending/` dependen de la regla de lifecycle del bucket (runbook), no hay limpieza en código; confirmar que la regla esté en el aprovisionamiento.
- BAJO — `BibliotecaSeedManualesCommand` no ejecutado (correcto); `--dry-run` no valida que el bucket sea accesible más allá de `isConfigured()`.

## Parte 2 — Frontend (8fd0655 solo lo del 984 + 92fb0c5)

Verificación: vitest `lib/manuales`, `ManualesPage`, `components/manuales` 40/40 OK; `tsc --noEmit` sin errores. Sin emojis ni glyphs en i18n/TSX (iconos de `icons/index.tsx`, SVG propios).

### Hallazgos
- **CRÍTICO** — `src/components/manuales/ManualesAdminPanel.tsx:119` (+ `src/types/manuales.ts:43`): `initUpload` envía `mime`, pero el backend exige `content_type` (`BibliotecaController::initUpload`, `'content_type' => required`). Toda subida real responde 422 y la UI solo muestra "No se pudo subir el archivo" (catch genérico, línea 137-140). No lo detectan los tests: `ManualesAdminPanel.test.tsx:119` afirma el campo `mime` y el e2e (`e2e/manuales.spec.ts:75`) mockea `/uploads` sin validar el cuerpo. El doc de contrato no fija el nombre (ambiguo), pero la fuente de verdad es el backend. Fix: renombrar a `content_type` en el tipo, el panel, el test unitario y agregar al mock e2e una aserción del body.
- MEDIO — `8fd0655` mezcla el 984 con SCRUM-1009 bajo un mensaje que solo menciona SCRUM-1009 (`fix(admin-contab)...`). Trazabilidad/revert de 984 dificultados; al pushear, aislar o documentar (el 92fb0c5 sí va por separado).
- MEDIO — `ManualesAdminPanel.tsx:137-140`: cualquier error de init/PUT/finalize cae en un mensaje único; los 422 del backend traen mensaje útil (cuota de pendientes, formato, tamaño, magic bytes) y se descartan. Fix: mostrar `err.response.data.message` cuando exista (y no ofrecer "Reintentar" en rechazos 422 de finalize, que el backend ya borró el pending).
- BAJO — `ManualCard.tsx:54-58`: `window.open` tras un `await` puede ser bloqueado como popup (Safari); hay toast de respaldo, aceptable. Alternativa: abrir la ventana en blanco sincrónicamente y asignar `location`.
- BAJO — `ManualesPage.tsx:30`: el catálogo se trae completo y se filtra en cliente (el backend soporta `modulo/tipo`); válido con catálogo chico, anotar límite.
- BAJO — `lib/manuales.ts:58,85`: `DEFAULT_EXTENSIONS` y `mimeFromExtension` duplican la lista técnica del backend (las extensiones sí se toman de `/config` en `allowed_extensions`; el tope no está hardcodeado — correcto). Sin impacto.

### Contrato backend/frontend (resumen)
OK: `GET /biblioteca` array plano (el api acepta también `{data}`), `Manual` = `{id,name,module,type,size_bytes,created_at}`, módulo `admin_contabilidad`, `GET /config` forma `max_*_bytes/allowed_extensions/modules`, `GET /{id}/url?accion=ver|descargar`, finalize 201 `Manual`, DELETE 204. Gate `manuales.manage` en ruta y en `ManualesPage` (panel ni se monta sin permiso; `/config` solo se pide desde el panel). Desajuste: `content_type`/`mime` (CRÍTICO arriba).

## Veredicto final: **DEVUELTO**
Backend listo; frontend bloqueado por el CRÍTICO `mime` vs `content_type` (la subida no funciona en ningún entorno real). Tras el fix, re-correr vitest `manuales` y e2e `manuales.spec.ts`, y verificar una subida real contra dev antes de Pre-QA.
