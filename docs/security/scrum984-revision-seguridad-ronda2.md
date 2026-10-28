# SCRUM-984 — Re-verificación de seguridad, ronda 2

Revisor: Cybersecurity. Fecha: 2026-09-30. Solo lectura: sin cambios de código, sin commits, sin comandos aws, sin tocar .env.
Alcance: backend e6ab031a (sobre 0d876d7c; dd215fc8 = docs), frontend be8b115 (más 92fb0c5 y 8fd0655), runbook `docs/runbooks/biblioteca-s3-aprovisionamiento.md` y diseño `docs/architecture/biblioteca-manuales-s3-diseno.md`.
Método: lectura del código real, y para el escáner de PDF una prueba local con PHP (script en scratchpad, no en el repo) contra la clase real.
Este archivo NO se commitea. Se escribe por partes.

## Veredicto

- Los hallazgos de la ronda 1 quedaron cerrados en código (H-1, M-2, M-3 con residual, B-1 a B-5, B-7, B-8, B-10).
- Hallazgo NUEVO ALTO (funcional, BLOQUEA Pre-QA): N-1. La política IAM desplegada no concede `s3:GetObjectVersion`, y el arreglo de M-2 lee y copia por `VersionId`. Con el bucket versionado, todo finalize real daría AccessDenied (500). Los tests no lo ven porque usan `FakeLibraryStore`.
- Hallazgo NUEVO MEDIO: N-2. El escáner de PDF se evade con un byte NUL (o `{`/`}`) después del nombre, sin comprimir nada. Arreglo de una línea. No bloquea Pre-QA, sí conviene antes de prod.
- Sin hallazgos CRITICO. Ninguna remediación destructiva ni de alto impacto en código; N-1 exige un cambio de IAM (alto riesgo, lo aprueba y ejecuta Luis/DevOps, yo no lo ejecuto).

---

## Parte 1 — Estado de los hallazgos de la ronda 1

| ID | Estado | Sev. | Bloquea Pre-QA | Nota |
|---|---|---|---|---|
| H-1 | CERRADO | (ALTO) | No | Ver 1.1 |
| M-1 | ACEPTADO (decisión de Luis) | MEDIO | No | Documentación y mitigación existen; alarma de tamaño sugerida pero NO creada. Ver 1.6 |
| M-2 | CERRADO en código, inoperante en real hasta cerrar N-1 | MEDIO | Sí, por N-1 | Ver 1.2 |
| M-3 | CERRADO con residual (ver N-2, N-3, N-4) | MEDIO | No | Ver 1.3 |
| M-4 | ACEPTADO (decisión de Luis) | MEDIO | No | Runbook sec. 13 existe. Ver 1.6 |
| B-1 | CERRADO | BAJO | No | Ver 1.5 |
| B-2 | CERRADO | BAJO | No | Ver 1.5 |
| B-3 | CERRADO | BAJO | No | Ver 1.2 |
| B-4 | CERRADO con residual (N-5, N-6) | BAJO | No | Ver 1.4 |
| B-5 | CERRADO | BAJO | No | Ver 1.5 |
| B-6 | sin cambio (informativo) | BAJO | No | Sin acción |
| B-7 | CERRADO | BAJO | No | Ver 1.5 |
| B-8 | CERRADO (residual cosmético) | BAJO | No | Ver 1.5 |
| B-9 | ABIERTO (decisión de negocio, no listada entre las aceptadas) | BAJO | No | Lectura abierta a todo usuario autenticado; confirmar con Luis |
| B-10 | CERRADO | BAJO | No | `ADMIN_ARN` = usuario IAM estable `luis.g@...` (runbook, estado). Sin cambios pendientes |

### 1.1 H-1 — contrato `content_type` — CERRADO
- BE: `app/Modules/Biblioteca/Http/Controllers/BibliotecaController.php:108` exige `content_type`; `LibraryService.php:451-456` lo compara con el mime canónico de la extensión.
- FE: `src/types/manuales.ts:43-44` (`content_type`), `src/components/manuales/ManualesAdminPanel.tsx:126` (`file.type || mimeFromExtension(...)`).
- e2e `e2e/manuales.spec.ts:92-98` el mock rechaza con 422 si falta cualquiera de `module,name,filename,content_type,size_bytes`; `:307-310` afirma el body exacto del init, el PUT a S3 y el finalize. Es la mitad de contrato que faltaba.
- Residual (BAJO, sin bloquear): el e2e sigue siendo un mock del backend; el contrato real solo se prueba en el smoke contra dev. Además el FE manda `file.type` del navegador y el BE exige igualdad exacta con el canónico: un navegador que informe `application/x-pdf` o `video/mp4;codecs=...` recibe un 422 legítimo pero molesto. Mitigación: enviar siempre `mimeFromExtension(ext)` (el BE ya valida la extensión y el magic byte). Que el Pre-QA incluya una subida real completa en dev (init, PUT, finalize).

### 1.2 M-2 (TOCTOU) y B-3 (Content-Type) — CERRADO en código
Preguntas del encargo, respondidas:
- ¿Puede un re-PUT entre validación y copia publicar contenido no validado? No, con el bucket versionado. `head()` devuelve `VersionId`+`ETag` (`S3LibraryStore.php:135-140`); `LibraryService.php:187-188` ata `readRange` (magic bytes, escáner) a esa versión (`S3LibraryStore.php:150-152`) y `copy()` usa `CopySource ...?versionId=` (`:201`). Un re-PUT crea una versión nueva que nunca se lee ni se copia. El test `:494` lo cubre con el fake.
- ¿Y si el bucket tiene versionado y se sube una versión nueva? Es exactamente el caso anterior: la versión nueva queda como versión vigente de `_pending/`, el finalize ya validó y copió la anterior. La versión validada pasa a no vigente y el lifecycle la elimina (verificar que gane la regla de 1 día, ver 3.4).
- Sin versionado (`version_id` nulo) el arreglo degrada a `CopySourceIfMatch`=ETag (`:203-205`): la copia falla si el objeto cambió. La lectura de magic bytes y escaneo quedaría sin atar, pero como la copia exige el ETag de lo que midió `head`, no se puede publicar algo distinto. Un 412 de S3 saldría como 500 (no hay catch de `S3Exception` en el finalize), no como 422: cosmético. Con el versionado ya habilitado en `atlanticerp-library` esta rama no se ejerce.
- ¿Content-Type final canónico? Sí. `LibraryService.php:208` pasa el mime de la extensión y `S3LibraryStore.php:206-209` aplica `MetadataDirective=REPLACE` + `ContentType`. Efecto adicional útil: REPLACE también descarta `Content-Disposition`, `Content-Encoding`, `Cache-Control` y `x-amz-meta-*` que el cliente pudiera haber añadido al PUT (tampoco van firmados). Test `:515`. Verificación posterior del tamaño copiado en `:210-215`.
- El PUT prefirmado solo puede escribir `_pending/{uuid}.{ext}` (clave generada por servidor, `LibraryService.php:116`); la clave final nunca es escribible por URL prefirmada.

**M-2 no funciona en el bucket real hasta cerrar N-1** (ver Parte 2).

### 1.3 M-3 — `PdfActiveContentScanner` — CERRADO con residual
Archivo: `app/Modules/Biblioteca/Services/PdfActiveContentScanner.php`; tokens en `config/biblioteca.php:36`.
Prueba local con la clase real (bloque de 4096 bytes para forzar cortes):

| Caso | Resultado |
|---|---|
| `/JavaScript`, `/JS (`, `/Launch` en claro | detectado |
| Escape `/J#61vaScript` | detectado (decodifica `#xx`, l.40 y l.54-60) |
| Token partido entre dos bloques (`/Java`+`Script`) | detectado |
| Escape partido en el corte (`/J#61vaScript` a caballo) | detectado |
| `/JS` justo al final del bloque | detectado (el `$` del lookahead lo atrapa; posible falso positivo con `/JSON` exactamente en el corte, fail-closed, aceptable) |
| `/JSON` benigno | no marcado (correcto) |
| **`/Launch` + byte NUL, `/JavaScript\0/JS\0(`** | **NO detectado (N-2)** |
| **`/Launch{`, `/Launch}`** | **NO detectado (N-2)** |
| Nombres dentro de un `/ObjStm` comprimido | NO detectado (N-4, límite declarado) |

Cobertura de los 7 tokens pedidos (`/JavaScript /JS /Launch /EmbeddedFile /RichMedia /SubmitForm /ImportData`): todos presentes. Solape de 64 bytes (l.17 y l.48): el token más largo con todas sus letras escapadas mide 37 caracteres (`/EmbeddedFile` como `#xx`), cabe. Decodifica el tail crudo junto con el bloque nuevo, sin doble decodificación.
DoS por tamaño: no es un riesgo. Medí 8 MB de `#41` repetido (peor caso del callback): 0,32 s y 13 MB de pico; los 7 `preg_match` sobre ese buffer no se perciben. Memoria por iteración: unos 4 bloques de 8 MB. Con 100 MB son 13 GET Range secuenciales; el costo dominante es la red a S3, dentro de la petición HTTP bajo lock (600 s) y limitado a 20 finalize/min por usuario con 2 usuarios con permiso.

### 1.4 B-4 — cuota de pendientes y lock — CERRADO con residual
- Reserva atómica: `LibraryService.php:505-514` (`add` + `increment` y comparar; si se pasa, `decrement` y rechazo). Dos solicitudes paralelas ya no superan el máximo con Redis (`CACHE_STORE=redis`; con un driver no atómico no valdría). Se libera si falla la firma (`:121-124`, test `:665`) y solo una vez por upload vivo (`forgetUpload`, `:525-530`, `Cache::pull`).
- Lock por `upload_id` (`:154-162`, TTL 600 s, liberado en `finally`): un finalize concurrente recibe 422 "en curso" (test `:586`); uno repetido no decrementa dos veces (test `:577`).
- ¿Bloqueo permanente por desbalance? En operación normal NO. Un upload abandonado deja el contador hasta que este expira (`ttl+600` s = 40 min con el TTL por defecto, contado desde el primer `add`, no se refresca). El máximo es configurable 1..50 (defecto 5). Casos borde, todos BAJO, en N-5 y N-6 de la Parte 2.

### 1.5 Resto de controles verificados
- **B-1 rate limit por usuario — CERRADO.** `app/Providers/AppServiceProvider.php:148-156` (limitadores `biblioteca-lectura` 60/min y `biblioteca-escritura` 20/min, clave `u{sub}`); `routes/biblioteca.php:13,18,20,22,24` los aplica a lectura y a POST/finalize/DELETE. `JwtService::decode` (`app/Shared/Auth/JwtService.php:80-92`) verifica firma y expiración, así que un `sub` no se puede falsificar para agotar el cupo de otro; token inválido cae al respaldo por IP. nginx usa `fastcgi_pass` (`infra/nginx/default.conf`), por lo que `REMOTE_ADDR` es la IP vista por nginx; no hay `TrustProxies`. Test `:631`. Nota: la clave no incluye el tenant (un solo tenant hoy; revisar si aparece otro).
- **B-2 `PUT /config` auditado — CERRADO.** `BibliotecaController.php:64-100` escribe `BIBLIOTECA_CONFIG_UPDATED` con valores viejos y nuevos (`AuditService.php`, constante nueva). Test `:482`. Rango validado, extensiones solo de la lista de `config/biblioteca.php`. (`AuditService::log` no lanza excepción por diseño; un fallo de auditoría es silencioso.)
- **B-7 credenciales — CERRADO.** `S3LibraryStore.php:29-34` exige bucket, key y secret; `:246` siempre pasa credenciales explícitas, nunca la cadena por defecto. Sin `AWS_LIBRARY_ACCESS_KEY_ID/SECRET` => `EnsureLibraryConfigured` responde 503. Test `:651`.
- **B-5 sidecar — CERRADO.** `LibraryService.php:339-347` re-sanea el nombre al leer y `:380-388` exige que `type` coincida con la extensión y `id` UUID. Test `:617`. La clave S3 se reconstruye del contenido validado, no de la ruta del sidecar.
- **B-8 saneo — CERRADO.** `LibraryService.php:472` elimina U+2028/2029, U+200B-200D, U+FEFF, bidi y controles. Residual cosmético (spoofing visual, sin impacto de inyección): U+2060, U+00AD y U+061C no se eliminan.
- **Autorización:** sin cambios respecto a la ronda 1 (`manuales.manage` en POST/finalize/DELETE/GET config, `superadmin.all` en PUT config).

### 1.6 Decisiones aceptadas por Luis (solo confirmar que existen)
- **M-1 (Content-Length no firmado):** documentado en el diseño sec. 1 (corrección del tope), en el contrato `Contracts/LibraryStore.php` (docblock de `presignPut`) y en `S3LibraryStore.php:171`. Contención real: finalize compara el tamaño real (`LibraryService.php:180`) y borra; lifecycle `pending-staging-2d` aplicado (runbook, estado). La alarma `BucketSizeBytes` (runbook sec. 12) está "sugerida, NO creada": la documentación lo dice con honestidad, pero la mitigación de detección sigue sin existir. Opcional y barato crearla (aprueba Luis).
- **M-4 (catálogo compartido):** runbook sec. 13 existe (prefijo `zz-prueba-`, borrar al cerrar cada Pre-QA, verificar listado vacío de `zz-prueba-*`). Mitigación procedimental, no técnica. Recomiendo que el Pre-QA de Biblioteca lo incluya como paso de cierre explícito.

---

## Parte 2 — Hallazgos NUEVOS

### N-1 [ALTO, funcional, BLOQUEA Pre-QA] La política IAM no concede `s3:GetObjectVersion`; el arreglo de M-2 la necesita
- Código: `S3LibraryStore.php:150-152` (`GetObject` con `VersionId`) y `:201` (`CopySource ...?versionId=`). Política desplegada: runbook sec. 6 y diseño sec. 3 (l.92-99): `ObjectsInLibrary` = `GetObject, PutObject, DeleteObject, AbortMultipartUpload, ListMultipartUploadParts`. No hay ninguna mención de `GetObjectVersion` en app, config, docs ni infra (grep).
- Por qué importa: en S3 leer o copiar una versión concreta exige `s3:GetObjectVersion`, aparte de `s3:GetObject`. El bucket está versionado, así que `head()` siempre devuelve `VersionId` y el finalize siempre usa la ruta por versión. Resultado esperado en dev: el primer `readRange` del finalize da 403 AccessDenied => 500, el archivo queda en `_pending` (lo barre el lifecycle), el cupo del usuario queda ocupado hasta que expire el contador y NINGUNA subida se puede completar. El runbook verificó con la clave de dev solo escritura/lectura/borrado lógico en `_pending` sin versión, que es justo lo que no cubre. Los tests usan `FakeLibraryStore`, por eso pasan.
- Mitigación (un cambio de IAM, alto riesgo, lo decide y ejecuta Luis/DevOps; yo no ejecuto nada de aws): crear una nueva versión de `atlanticerp-library-access` que añada `s3:GetObjectVersion` acotado a `arn:aws:s3:::atlanticerp-library/library/_pending/*` (lo único que el finalize lee por versión; no hace falta en las claves finales). La bucket policy existente deniega `DeleteObjectVersion`, no `GetObjectVersion`, así que no interfiere. Actualizar runbook sec. 6 y diseño sec. 3. Verificación: smoke de subida completa en dev (init, PUT, finalize) con un PDF de prueba `zz-prueba-...`, y negativa: con la clave de dev, `get-object --version-id` sobre una clave fuera de `_pending/` debe seguir dando AccessDenied.
- Alternativa descartada: quitar el `VersionId` del código y confiar en `CopySourceIfMatch`; reabre el hueco de M-2 en la lectura.

### N-2 [MEDIO, no bloquea Pre-QA, arreglar antes de prod] El escáner se evade con NUL, `{` o `}` tras el nombre
- `PdfActiveContentScanner.php:43`: el lookahead exige `[\s/\[\]<>()%]` o fin de bloque. En PDF son espacio en blanco también el NUL (0x00) y delimitadores `{` `}`. PCRE `\s` no incluye NUL y el conjunto omite las llaves.
- Escenario demostrado con la clase real: `/S /Launch\0/F (cmd.exe)`, `/S /JavaScript\0/JS\0(app.alert(1))` y `/Launch{` devuelven "limpio". Un lector PDF los interpreta igual, y no requiere comprimir ni conocer ObjStm. Quien use una cuenta con `manuales.manage` comprometida evade el control con un byte.
- Mitigación: que la condición sea "el siguiente byte NO es un carácter regular": `(?![^\x00\t\n\f\r ()<>\[\]{}\/%])`; o eliminar el lookahead y aceptar falsos positivos tipo `/JSON` (en manuales no deberían aparecer). Añadir tests con NUL, `{` y `}` (hoy solo hay espacio, `(` y partición entre bloques).

### N-3 [BAJO] El escáner falla abierto en tres condiciones
- (a) PDF mayor que `pdf_scan_max_bytes` (100 MB, `PdfActiveContentScanner.php:31` y `config/biblioteca.php:38`): el resto no se lee y el resultado es "limpio". El tope de PDF es configurable por superadmin hasta 1024 MB (`BibliotecaController.php:67`). Con el defecto de 50 MB no ocurre.
- (b) Si `preg_match` devuelve `false` (error de PCRE) el código lo trata como "sin coincidencia" (`:43`); si `preg_replace_callback` falla, `(string) null` deja la ventana vacía (`:56`).
- (c) Un fallo de lectura de S3 a mitad del escaneo lanza excepción (500), no marca limpio: correcto.
- Mitigación: rechazar (o no aceptar en `PUT /config`) un `max_pdf_mb` superior al tope de escaneo; en el escáner devolver un hallazgo si `preg_*` falla o si `size > limit`.

### N-4 [BAJO, riesgo residual ACEPTABLE con condiciones] Nombres dentro de object streams comprimidos no se ven
- Límite ya declarado en `config/biblioteca.php:33-35`. Un PDF con `/ObjStm` FlateDecode (p. ej. `qpdf --object-streams=generate`, o un PDF optimizado con Acrobat) oculta `/JavaScript`, `/Launch` y similares; lo comprobé con un flujo comprimido: no se detecta. Es el caso esperable de un atacante que sabe lo que hace.
- Por qué es aceptable para v1: el control es anti-error y anti-oportunista, no anti-adversario. Compensaciones existentes: solo 2 cuentas pueden subir (Gerencia1 y superadmin), cada subida y descarga queda en `audit_logs`, versionado con recuperación de 90 días, el visor del navegador corre en el origen de S3 sin acceso al JWT de la app. El riesgo real queda en quien descarga y abre con un lector de escritorio.
- Condiciones para aceptarlo: (1) documentarlo como "no sustituye antivirus" (ya lo dice el docblock; que el diseño y el manual de usuario no lo vendan como garantía); (2) registrar el siguiente paso (ClamAV o rechazo parametrizado de `/ObjStm`; evaluar con los 7 PDFs reales cuántos usan ObjStm antes de activarlo); (3) que Gerencia1 suba solo PDFs generados por ella o por fuentes de confianza.

### N-5 [BAJO] `finalize` lee el registro del upload fuera del lock
- `LibraryService.php:148-160`. Un segundo finalize que leyó `$upload` antes de que el primero lo consumiera puede, si el cliente volvió a hacer PUT a `_pending` con la URL aún vigente, revalidar completo (tamaño, magic bytes, escaneo) y volver a publicar el mismo `id` con contenido distinto y nueva auditoría. No hay bypass de validación (se vuelve a validar todo), solo reescritura del mismo id por el mismo usuario autorizado. Mitigación: leer `Cache::get` dentro del lock.

### N-6 [BAJO] Borde de la cuota en Redis: clave sin TTL y subcontado
- `LibraryService.php:507-514`. Si la clave expira entre `Cache::add` e `increment` (o entre `increment` y `decrement`), Redis crea el contador sin TTL (o en -1): queda sin expiración. Si el usuario abandona subidas en ese estado, el bloqueo es permanente hasta que alguien borre la clave o finalice un upload vivo. Ventana de milisegundos, con 2 usuarios: improbable.
- Además: como el TTL no se refresca, el contador puede expirar con uploads aún vivos; el finalize de uno viejo libera un cupo que ya era de uno nuevo y se puede superar el máximo (acotado por 20/min). Un `finalize` con "no encontrado en `_pending`" (`:175-177`) tampoco libera el cupo: se libera al expirar el contador.
- Mitigación: script Lua (o `Cache::put` con TTL cuando `increment` devuelve 1) y refrescar el TTL en cada reserva; liberar cupo también en `rejected_missing`. No urgente.

---

## Parte 3 — Coherencia entre lo documentado y el código

1. **IAM vs código (N-1):** documentación y política dicen "GetObject/PutObject/DeleteObject"; el código pide además `GetObjectVersion`. Incoherencia real, bloquea.
2. **Runbook sec. 12 vs diseño:** ambos presentan la alarma de tamaño como mitigación de M-1; la alarma no está creada. Coherente con "NO creada", pero el texto de M-1 en el diseño (l.220) habla de control de tamaño sin mencionar que la detección depende de esa alarma. Sugiero una línea.
3. **Lifecycle solapado (verificar, no hallazgo):** `library/_pending/` está cubierto por dos reglas con `NoncurrentDays` 1 y 90. Conviene comprobar con `get-bucket-lifecycle-configuration` (solo lectura) que en `_pending/` gane la de 1 día; si aplicara la de 90, los pendientes rechazados (p. ej. 5 GB o un PDF hostil) permanecen 90 días como versión no vigente. Costo, no explotación (lo lee solo el admin).
4. **Estado "ejecutado" del runbook:** la verificación posterior con la clave de dev no ejercitó lectura ni copia por versión. Añadir ese paso al runbook sec. 10 (ver N-1).
5. **Sidecar y bucket compartido:** el código re-sanea y valida tipo/extensión (B-5), consistente con el riesgo M-4 aceptado.
6. **Bucket policy:** `ADMIN_ARN` es un usuario IAM estable; coherente con la advertencia del runbook. Si Luis pasa a SSO/rol, el Deny por `ArnNotEquals` lo bloquearía; usar `ArnNotLike` con el rol entonces.
7. **Diseño sec. 3 sobre credenciales:** el diseño aún habla de migrar a rol de instancia como deuda; el código ya no permite la cadena por defecto (B-7). Sin conflicto, pero si se migra a rol habrá que cambiar `isConfigured()` a propósito.
8. CloudTrail de datos del bucket diferido por Luis (runbook, estado): compensación actual = `audit_logs` de la app + versionado. Aceptado.

---

## Parte 4 — Handoff y bloqueos

- **Bloquea Pre-QA:** N-1 (IAM `s3:GetObjectVersion` + smoke de subida completa en dev). Nada más bloquea Pre-QA.
- **Antes de prod (no bloquea Pre-QA):** N-2 (lookahead del escáner + tests); N-3 (fail-open del escáner y tope de `max_pdf_mb`); confirmar el lifecycle (Parte 3.3).
- **Opcionales / Senior Reviewer:** N-5, N-6, enviar siempre `mimeFromExtension` desde el FE (1.1), alarma de tamaño (1.6).
- **AWS Architect / DevOps / Luis:** N-1 (cambio de IAM, alto riesgo, aprobación de Luis; yo no lo ejecuto), alarma opcional, verificación de lifecycle en modo lectura.
- **Backend Dev:** N-2, N-3, N-5, N-6. **Frontend Dev:** envío de `mimeFromExtension` (opcional).
- **Pre-QA:** incluir subida real completa en dev con `zz-prueba-*` y borrado al cierre (runbook sec. 13); casos negativos: PDF con `/Launch\0`, PDF con JS en claro (debe rechazar), PDF de 51 MB (tope), finalize doble, dos URLs PUT sobre la misma clave.
- **Luis (decisión):** B-9 (visibilidad de manuales por módulo); aceptación formal del riesgo residual N-4.
- Nada de lo hallado exige remediación destructiva; ninguna escalada urgente.
