# SCRUM-984 — Revisión de seguridad (Biblioteca de Manuales, S3 compartido)

Revisor: Cybersecurity. Fecha: 2026-09-30. Solo lectura, sin cambios de código ni comandos aws.
Alcance: backend 0d876d7c, frontend 8fd0655 (archivos de manuales), diseño AWS y runbook D1.
Este archivo NO se commitea. Se escribe por partes.

## Veredicto

- Sin hallazgos CRITICO. Un hallazgo funcional ALTO (bloquea Pre-QA, no es de seguridad pura). Dos MEDIO que conviene cerrar antes de subir a prod.
- Bloquea el paso a Pre-QA: SI, solo por H-1 (contrato FE/BE roto, toda subida responde 422).
- Bloquea el paso a prod: M-1 y M-2 (correcciones baratas) y la decision de Luis sobre M-4.

---

## Parte 1 — Hallazgos

### H-1 [ALTO, funcional, BLOQUEA Pre-QA] El frontend envia `mime`, el backend exige `content_type`
- FE: `atlanticerp-frontend/src/types/manuales.ts` (ManualUploadInitRequest.mime) y `src/components/manuales/ManualesAdminPanel.tsx:119` (`mime: file.type || ...`).
- BE: `app/Modules/Biblioteca/Http/Controllers/BibliotecaController.php:92` (`'content_type' => required`).
- Escenario: toda subida desde la UI falla con 422 en /biblioteca/uploads. Los tests de ambos lados pasan porque cada uno mockea al otro (mismo patron de shape mismatch ya documentado en memoria).
- Mitigacion: alinear el nombre del campo (un lado) y agregar un test de contrato o un spec Playwright real contra dev. Ademas el FE no deberia decidir el mime: el BE ya lo compara con la extension, asi que basta con enviar `file.type` tal cual o derivarlo de la extension.

### M-1 [MEDIO] `Content-Length` (y `Content-Type`) NO quedan firmados en el PUT prefirmado — el tope de tamano no lo impone S3
- `app/Modules/Biblioteca/Services/S3LibraryStore.php:110-125` (presignPut) y `docs/architecture/biblioteca-manuales-s3-diseno.md` seccion 1 ("S3 responde 403 SignatureDoesNotMatch").
- Evidencia: `vendor/aws/aws-sdk-php/src/Signature/SignatureV4.php:47-51` (content-length en `getHeaderBlacklist`) y `:84-88` (content-type en `getPresignHeaderDenyList`), SDK 3.386.1. Ambos headers se excluyen de SignedHeaders en una URL prefirmada. La premisa del diseno es falsa.
- Escenario: un usuario con `manuales.manage` (o una sesion robada de Daniela/superadmin) pide una URL declarando 1 KB y sube hasta 5 GB con cualquier Content-Type. Hasta 5 objetos pendientes por ventana. El objeto ocupa almacenamiento y egreso hasta que el lifecycle lo expire (2 dias) — el finalize lo rechazaria, pero el atacante simplemente no llama finalize. Los tests no lo detectan porque usan FakeLibraryStore.
- Impacto real: bajo (2 personas con permiso, costo en centavos), pero el control documentado no existe.
- Mitigacion (elegir una): (a) presigned POST con policy `content-length-range 1..max`, `eq $Content-Type` y `eq $key` (aws-sdk-php `PostObjectV4`), unica forma de que S3 imponga el tope; cambia putFileToS3 a FormData; (b) aceptar el residual, corregir el doc/comentarios para no afirmar que se firma, y agregar la alarma `BucketSizeBytes` ya sugerida en el diseno. Recomiendo (a) si el costo de front es chico; (b) es defendible con dos usuarios de confianza. Registrar la decision.

### M-2 [MEDIO] TOCTOU entre validacion y copia en `finalizeUpload`
- `app/Modules/Biblioteca/Services/LibraryService.php:148-171`.
- Escenario: la URL de PUT sigue vigente (hasta 30 min) mientras corre finalize. El cliente sube un PDF valido, llama finalize, y entre `head`/`readBytes` (l.148-159) y `copy` (l.171) re-sube otro contenido a la misma clave `_pending`. La copia publica un objeto que nunca paso la validacion de tamano ni de magic bytes (por ejemplo 5 GB, o un archivo no PDF servido como application/pdf/video/mp4 forzado).
- Mitigacion: que `head()` devuelva `ETag` y `VersionId` (versionado esta habilitado) y usar `CopySourceVersionId` (o `CopySourceIfMatch`) en `copyObject`, con `readBytes` tambien atado a ese ETag/versionId. Ademas un `head` del destino tras copiar comparando `ContentLength`. Copia con `MetadataDirective=REPLACE` y `ContentType` de la extension (hoy el Content-Type almacenado lo elige el cliente, ver B-3).

### M-3 [MEDIO] PDF con contenido activo (JS, /Launch, /EmbeddedFile) distribuido a todos los usuarios
- Sin control en finalize (`LibraryService.php:159`, solo `%PDF-`). Visualizacion en pestana nueva: `ManualCard.tsx` handleOpen (`window.open`); descarga: handleDownload.
- Escenario: cuenta con `manuales.manage` comprometida (objetivo atractivo: solo 2 cuentas, una de nivel 9) sube un PDF malicioso; los ~32 usuarios lo abren/descargan. En pestana, el visor del navegador (PDFium/PDF.js) corre en el origen de S3, sin acceso al origen de la app ni a su JWT: riesgo bajo. Al descargar y abrir en Acrobat/lectores de escritorio el riesgo es mayor (JS, exploits del parser).
- Mitigacion: (1) escaneo barato en finalize: leer el archivo por streaming y rechazar si contiene `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile`, `/RichMedia`, `/OpenAction` (falsos positivos aceptables para manuales; parametrizable); (2) opcional, ClamAV como paso posterior (esfuerzo mayor, no pedido en el ticket); (3) el flujo ya exige permiso angosto y auditoria. No bloquea.

### M-4 [MEDIO, requiere decision de Luis] Bucket compartido: dev/test pueden escribir sobre el catalogo que ve prod
- `docs/runbooks/biblioteca-s3-aprovisionamiento.md` seccion 6 (politica IAM identica para los 3 usuarios) y diseno seccion 2.
- Escenarios: (a) dev/test son menos endurecidos (usuarios demo, Pre-QA con Playwright, CrowdSec en enforcing solo parcialmente): un compromiso de la EC2 de dev permite a `atlanticerp-library-dev` escribir sidecars y PDFs que el catalogo de prod listara y serviran a usuarios reales (cadena de suministro cruzada entre entornos). (b) Practico y mas probable: el smoke del paso 4 del diseno ("subir PDF y video de 500 MB") y el Pre-QA en dev dejan basura visible en prod a los 60 s. (c) Borrado cruzado: mitigado por versionado + Deny de DeleteObjectVersion.
- Mitigacion: decision 2 del diseno — quitar `s3:PutObject` y `s3:DeleteObject` a `atlanticerp-library-dev` y `-test` (solo Get/List; subir y borrar se prueba solo en prod con un archivo marcado y luego se borra), o como minimo quitar `DeleteObject`. Si se necesita probar subida en dev, usar un prefijo separado (`library-dev/`) que prod no lista, con politica distinta. Mientras no se decida: no correr Pre-QA de subida contra el bucket real sin prefijo de prueba y borrar lo subido.

---
(continua en Parte 2)

## Parte 2 — Hallazgos BAJO y observaciones

### B-1 [BAJO] Rate limit por IP, no por usuario; posible cupo global detras de nginx
- `routes/biblioteca.php:13-24` (`throttle:N,1`). El guard JWT propio no define `user()`, asi que Laravel limita por `$request->ip()` (mismo problema ya documentado en AppServiceProvider para `fe-accion`). Si no hay TrustProxies/real-ip configurado (no encontre `trustProxies` ni `X-Forwarded-For` en app/bootstrap/config), la IP es la del contenedor nginx: los 20/min de subida/borrado serian compartidos por todos los usuarios y la IP de `audit_logs` seria la del proxy. Verificar en dev.
- Mitigacion: limitador con nombre (`RateLimiter::for('biblioteca-escritura')`) por `sub` del JWT como `fe-accion`, y confirmar IP real.

### B-2 [BAJO] Cambios de configuracion no se auditan
- `BibliotecaController.php:63-84` (PUT /config): solo guarda `updated_by`. Subir topes a 1024/5000 MB o TTL a 4 h es un cambio de postura de seguridad. Mitigacion: `AuditService::log` con old/new (hay constantes por dominio; agregar `BIBLIOTECA_CONFIG_UPDATED`). Las ediciones quedan acotadas: extensiones solo de la lista de `config/biblioteca.php` (no se puede habilitar html/svg por API).

### B-3 [BAJO] Content-Type almacenado lo elige el cliente
- `S3LibraryStore.php:139-146` (copyObject sin `MetadataDirective`/`ContentType`): el objeto final hereda el Content-Type del PUT (no firmado, ver M-1). No es explotable hoy porque el GET prefirmado fuerza `ResponseContentType` y `ResponseContentDisposition` (firmados, l.127-137) y el mime sale de la extension (`LibraryService.php:75`). Defensa en profundidad: `MetadataDirective=REPLACE` + ContentType canonico.

### B-4 [BAJO] Cuota de pendientes aproximada (ya declarada) y finalize sin exclusion mutua
- `LibraryService.php:446-468`: check-then-increment no atomico (solicitudes paralelas superan el maximo), el contador expira `ttl+600` desde el primer `add` (no se refresca) y un finalize doble concurrente decrementa dos veces. Impacto acotado por el rate limit y por `_pending` con lifecycle. Mitigacion: `Cache::lock` por `upload_id` en finalize y `increment` condicionado al maximo.

### B-5 [BAJO] Sidecar confiado sin re-sanear, sin consistencia tipo/extension
- `LibraryService.php:323-330` (`isValidSidecar`) valida id UUID, modulo, extension y tipo, pero no re-aplica `sanitizeName` ni que `type` coincida con `ext` (pdf vs mp4). La clave S3 se reconstruye del contenido del sidecar (`objectKey`, l.349), no de su ruta, asi que no hay path traversal; el riesgo es un sidecar escrito por otro entorno con codigo distinto. React escapa el texto (sin `dangerouslySetInnerHTML` en src/components/manuales ni ManualesPage). Mitigacion: `sanitizeName` al leer y validar `type === config(extensions.$ext.type)`.

### B-6 [BAJO] Catalogo cacheado 60 s permite emitir URL de un archivo recien borrado
- `LibraryService.php:65` (`find(fresh:false)`). Tras un borrado en otro entorno, prod puede emitir una URL durante 60 s; el GET devolvera 404 por el delete marker (la clave no lleva versionId), asi que es solo ruido. Informativo.

### B-7 [BAJO] Fallback de credenciales del SDK
- `S3LibraryStore.php:22-25,176-190`: `isConfigured()` solo mira el bucket. Si faltan `AWS_LIBRARY_ACCESS_KEY_ID/SECRET`, el SDK usa la cadena por defecto (variables `AWS_ACCESS_KEY_ID` del proceso o instance profile) en vez de fallar. Deberia exigir key y secret para considerarse configurado (o decidir explicitamente migrar a rol). Es coherente con el 503 fail-safe.

### B-8 [BAJO] Saneo de nombre: faltan U+2028/2029 y zero-width
- `LibraryService.php:414`: no se eliminan U+2028/U+2029 ni U+200B/U+200C/U+200D. Sin impacto de XSS o de Content-Disposition (el ASCII fallback reemplaza todo lo no `[A-Za-z0-9._ -]`, l.429, y el `filename*` va con `rawurlencode`). Solo spoofing visual de nombres.

### B-9 [BAJO, decision de negocio] Lectura abierta a todo usuario autenticado
- `routes/biblioteca.php:13,22`: cualquier rol (guest, electricista) lista y abre todos los manuales, incluidos Admin&Contabilidad y Gerencia, aunque el Sidebar oculte esos modulos. No hay IDOR por `{id}` (UUID validado, catalogo unico) ni por `upload_id` (se compara `user_id` con el actor, l.142, responde 404). Confirmar con Luis que los manuales de Admin&Cont/Gerencia no contienen umbrales o procedimientos sensibles; si los tienen, filtrar por permiso de modulo en `list`/`presignedUrl`.

### B-10 [BAJO, operativo] Politica de bucket con `ArnNotEquals` y ADMIN_ARN de sesion
- Runbook secc. 8 (l.154-158): si `ADMIN_ARN` es un ARN `assumed-role/.../sesion`, el Deny bloquea a DevOps. El propio runbook lo advierte; usar el ARN estable del rol/usuario o `ArnNotLike` con comodin del rol. Agregar al Deny `s3:PutBucketCORS`, `s3:PutBucketPublicAccessBlock`, `s3:PutEncryptionConfiguration` es defensa en profundidad (los usuarios de app no tienen esos permisos IAM hoy).

---

## Parte 3 — Checklist del doc de diseno AWS (estado)

| Control | Estado |
|---|---|
| Clave S3 generada solo por servidor; traversal | OK: `uuid` + extension de lista blanca + modulo de lista blanca (`LibraryService.php:108,349-357`, `config/biblioteca.php`). `finalize` solo acepta `upload_id` del cache del mismo usuario |
| Nombre visible saneado / XSS / Content-Disposition | OK con B-8. Nombre nunca en clave. Disposition con fallback ASCII + `filename*` (l.426-432); FE renderiza como texto |
| Lista blanca ext + mime | OK (`assertExtension`, `assertMime`; svg/html/exe cubiertos por tests) |
| Magic bytes | Parcial: `%PDF-` en offset 0, `ftyp` en 4-8, EBML en 0 (`:434-442`). Suficiente para no-polyglot trivial; no detecta contenido activo (M-3). Polyglot PDF+HTML inofensivo porque se sirve con Content-Type forzado y desde otro origen |
| Tope de tamano / cliente miente | FALLA parcial: declarado validado y re-verificado con HeadObject (`:154`), pero el PUT no impone el tope (M-1) |
| TOCTOU finalize->copy | FALLA (M-2) |
| Rate limit y cuota | Parcial (B-1, B-4) |
| Autorizacion | OK: `manuales.manage` en ruta (POST/DELETE/GET config), `superadmin.all` en PUT config; acceso denegado se audita (CheckPermission). Tests cubren 401, usuario normal, upload ajeno |
| URLs prefirmadas | OK: TTL 15/60 min parametrizable con tope 3600/14400 s, ResponseContentType/Disposition forzados y firmados. URL no se escribe en audit_logs ni en logs (metadata solo modulo/tipo); se entrega por JSON, no pasa por nginx como query |
| Auditoria | OK subida/borrado/descarga con usuario, IP y entity_id uuid (`AuditService.php` constantes; args en orden correcto). Falta config (B-2); "ver" no se audita (aceptable) |
| Fallo seguro sin bucket | OK: `EnsureLibraryConfigured` -> 503, test incluido (ver B-7 para credenciales) |
| Bucket compartido | Ver M-4; versionado + Deny DeleteObjectVersion correctos en diseno (bucket ya creado; confirmar aplicacion de policy y lifecycle `_pending/` 2 dias porque M-1 depende de el) |
| CORS | OK: solo 4 origenes https, GET/HEAD/PUT, sin localhost. Verificar con el preflight negativo del runbook secc. 10 |
| Credenciales | OK: `.env.example` solo claves vacias, nada hardcodeado; `throw=true`; usuario IAM dedicado por entorno |
| PDF con JS al abrir en pestana | M-3; `win.opener = null` aplicado (ManualCard), origen S3 distinto al de la app |
| Dependencias | OK: sin cambios en composer.json/lock ni package.json. aws-sdk-php 3.386.1 ya presente |
| Comando seed | OK: directorio local por operador, glob `*.pdf`, aplica mismo saneo/magic bytes; sin red ni entrada de usuario |

## Parte 4 — Handoff

- Backend Dev: H-1, M-2, B-2, B-3, B-4, B-5, B-7 (y M-1 segun decision).
- Frontend Dev: H-1 y, si se elige POST policy, `putFileToS3`.
- AWS Architect / DevOps: M-4 (politica IAM por entorno), B-10, confirmar lifecycle `_pending/` y bloqueo publico/CORS con los comandos de verificacion del runbook; CloudTrail data events recomendados (paso 9).
- Senior Reviewer: revisar M-1/M-2 junto al resto del cambio; este review cubre la parte de seguridad.
- Luis (decision, no se remedia solo): M-1 opcion (a) vs (b); M-4 permisos de dev/test; B-9 visibilidad de manuales por modulo.
- Nada de lo hallado exige remediacion destructiva ni de alto impacto.
