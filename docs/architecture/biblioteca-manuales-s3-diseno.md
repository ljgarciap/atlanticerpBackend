# Biblioteca de Manuales (SCRUM-984) — Diseño de infraestructura AWS

Estado: PROPUESTA, pendiente de aprobación de Luis. Autor: AWS Architect. Fecha: 2026-09-30.
Alcance: solo diseño. No se creó ni modificó ningún recurso AWS. DevOps ejecuta tras aprobación.

## 0. Contexto y decisiones ya tomadas (no se re-litigan)

- Un solo bucket S3 nuevo compartido por dev.atlanticerp.ai, test.atlanticerp.ai y atlanticerp.ai (us-east-1, una EC2 t3.small por entorno).
- Catálogo sin tabla de BD: clave `library/{modulo}/{tipo}/{uuid}.{ext}` + JSON sidecar por archivo. Listado por prefijo (ListObjectsV2), caché ~60 s en Redis por entorno.
- Video hasta 500 MB, subida directa del navegador con URL prefirmada, reproducción con `<video>` + Range, descarga permitida en PDF y video.
- Escritura: superadmin + Daniela (`manuales.manage`). Lectura: cualquier usuario autenticado (autorización siempre en el backend, que emite las URLs).
- Módulos: crm, ventas_diseno, compras, bodega, servicios, admin_contabilidad, gerencia. `AWS_AUDIT_IMMUTABLE_BUCKET` no se toca.

## 1. Bucket

| Aspecto | Decisión |
|---|---|
| Nombre | `atlanticerp-library` (si está tomado globalmente: `atlanticerp-manuales-library`; no verificado, ver sección 7) |
| Región | us-east-1 |
| Object Ownership | Bucket owner enforced (ACLs deshabilitadas). El backend NUNCA envía ACL ni `visibility` |
| Block Public Access | Los 4 flags en ON. Sin bucket policy pública |
| Cifrado | SSE-S3 (AES256) por defecto. No KMS: el contenido es material de capacitación, KMS agrega costo y obliga a firmar headers `x-amz-server-side-encryption-aws-kms-key-id` en PUT prefirmado y dar `kms:GenerateDataKey` al firmante |
| Versionado | ENABLED (es la red de seguridad del borrado compartido entre entornos, ver sección 2) |
| TLS | Bucket policy con Deny si `aws:SecureTransport=false` |
| Lectura | SOLO URLs prefirmadas de S3. Sin CloudFront (justificación abajo) |

### Lifecycle

Dos reglas, verificadas contra AWS el 2026-09-30 al aplicarlas (`get-bucket-lifecycle-configuration`):

| Regla | Alcance | Qué expira |
|---|---|---|
| `pending-staging-2d` | `library/_pending/` | El **objeto** a los **2 días** (`Expiration: Days 2`) y sus versiones **no vigentes** (noncurrent) al **1 día** (`NoncurrentVersionExpiration: NoncurrentDays 1`). |
| `todo-el-bucket` | Todo el bucket (el resto, o sea lo publicado) | Versiones **no vigentes** a los **90 días** (ventana de recuperación de borrados), limpieza de delete markers huérfanos (`ExpiredObjectDeleteMarker`) y abort de multipart incompleto a los 2 días. |

- En `library/_pending/` las dos reglas coinciden sobre las versiones no vigentes (1 día y 90 días): S3 aplica el vencimiento que se cumple antes, así que ahí rigen el 1 día para las versiones no vigentes y los 2 días para el objeto. Los 90 días rigen para el contenido publicado (fuera de `_pending/`).
- Un objeto de `_pending/` que nunca se finaliza (o cuya limpieza falló) desaparece solo a los 2 días; sin sidecar es invisible para el catálogo.
- Sin transición a IA/Glacier: el volumen es de unos pocos GB, el ahorro es de centavos y penaliza las lecturas.

### Borrado lógico

Con versionado, `DeleteObject` (sin versionId) solo crea un delete marker; el objeto sigue recuperable 90 días. El backend borra objeto + sidecar (dos delete markers). Restaurar = eliminar el delete marker, lo hace un admin AWS (DevOps), no la app. No hay "papelera" en la UI en esta fase.

### Flujo de subida (recomendado, patrón staging)

1. Backend valida permiso, módulo, tipo, extensión, mime declarado y tamaño declarado; genera `uuid`.
2. Emite PUT prefirmado a `library/_pending/{uuid}.{ext}`. **`Content-Type` y `Content-Length` NO quedan firmados** (ver la corrección del tope abajo).
3. El navegador sube directo a S3 (no pasa por nginx/PHP: no aplican `client_max_body_size` ni límites de PHP).
4. Finalize (endpoint del backend): `HeadObject` (tamaño real, content-type), lectura de los primeros bytes por Range (magic bytes: `%PDF-`, `ftyp` para mp4, cabecera EBML para webm), y si pasa: CopyObject server-side a `library/{modulo}/{tipo}/{uuid}.{ext}` + PutObject del sidecar. Si no pasa: DeleteObject del pending.
5. Un pending sin finalize queda invisible (el catálogo lo define el sidecar) y expira solo en 2 días.

Importante: usar el cliente `S3Client::copyObject` directo, NO `Storage::copy()` de Flysystem (dispara `GetObjectAcl`, que no concedemos; hallazgo ya registrado en memoria del proyecto).

**Corrección del tope de tamaño (hallazgo M-1 de Cybersecurity, decisión de Luis 2026-09-30).** La versión original de este documento afirmaba que firmar `Content-Length` hacía que S3 respondiera 403 `SignatureDoesNotMatch` si el cliente enviaba otro tamaño. **Es falso con aws-sdk-php 3.386.1:** `SignatureV4` excluye `Content-Length` (`getHeaderBlacklist`) y `Content-Type` (`getPresignHeaderDenyList`) de los headers firmados de una URL prefirmada, y un PUT prefirmado tampoco admite `content-length-range` (eso es solo de POST policy). S3 por sí solo NO impone el tope: alguien con `manuales.manage` podría declarar 1 KB y subir hasta 5 GB con cualquier Content-Type a `_pending/`.

Luis decidió **aceptar y mitigar** (no cambiar a presigned POST). Lo que hace cumplir el tope en realidad:
1. **`finalize`** re-verifica con `HeadObject` que el tamaño real coincida con el declarado y no supere el tope; si no, borra el pending y rechaza. Además lee los magic bytes y escanea contenido activo atado a la `VersionId` validada (nada re-subido después se publica).
2. **Lifecycle de `library/_pending/`**: el objeto expira a los 2 días (regla `pending-staging-2d`), así que un objeto enorme que nunca se finaliza desaparece solo.
3. **Cuota de pendientes por usuario** (`library_max_pending_uploads`, default 5, reserva atómica) y **rate limit por usuario** acotan cuántos objetos pendientes puede acumular una cuenta.
4. Solo 2 personas (superadmin + Daniela) tienen el permiso `manuales.manage`, y cada subida/borrado queda en `audit_logs`.
5. **Alarma de tamaño del bucket** (CloudWatch `BucketSizeBytes`, ver runbook §12) para detectar el residuo de subidas sin finalizar.

El Content-Type que el cliente fije en el PUT tampoco es de fiar: al copiar a la clave final, `finalize` lo reemplaza (`MetadataDirective=REPLACE`) por el tipo canónico de la extensión validada, y las descargas igual fuerzan `ResponseContentType`. Alternativa descartada por ahora: presigned POST con `content-length-range 1..N` (S3 sí lo haría cumplir, pero cambia el flujo de subida en backend y frontend); se reconsidera si el riesgo residual deja de ser aceptable.

Multipart: para v1 un PUT único (S3 acepta hasta 5 GB) con progreso por XHR. Si hay redes inestables, fase 2: multipart con partes de 16 MB prefirmadas (UploadPart, TTL 60 min) y finalize con `CompleteMultipartUpload`; el tamaño total solo se puede verificar en el finalize (HeadObject) — por eso el finalize es obligatorio en ambos caminos.

### Por qué NO CloudFront (ahora)

- Las URLs prefirmadas de S3 soportan `Range` de forma nativa (206 Partial Content), así que el seek en `<video>` funciona sin CDN.
- Costo de egreso: S3 $0.09/GB vs CloudFront $0.085/GB: prácticamente igual. Lo que CloudFront daría es 1 TB/mes gratis; hoy el tráfico esperado está muy por debajo (sección 5) y además aplican 100 GB/mes de egreso gratis a nivel cuenta.
- Acceso privado con CloudFront exigiría URLs/cookies firmadas (key group, par de claves en Secrets Manager, rotación) o pasar todo por el backend: más piezas para 32 usuarios internos.
- Disparador para reconsiderar: egreso sostenido > 150 GB/mes o quejas de latencia de video (usuarios en Panamá, latencia a us-east-1 aceptable).

## 2. IAM

### Recomendación: 3 usuarios IAM dedicados (uno por entorno), NO reutilizar los actuales, NO rol de instancia todavía

| Usuario | Se instala en | Motivo |
|---|---|---|
| `atlanticerp-library-dev` | .env de dev.atlanticerp.ai | Atribución por entorno en CloudTrail (quién borró qué) |
| `atlanticerp-library-test` | .env de test.atlanticerp.ai | Idem |
| `atlanticerp-library-prod` | .env de atlanticerp.ai | Idem |

- Reutilizar los usuarios/keys actuales obligaría a ampliar sus políticas (hoy acotadas a atlanticerp-dev/test/prod) y mezclaría auditoría. Usuarios nuevos = cero cambio sobre lo que funciona hoy; el rollback es desactivar las keys.
- Variables separadas (`AWS_LIBRARY_ACCESS_KEY_ID/SECRET`), el disco `s3-library` no hereda `AWS_ACCESS_KEY_ID`.
- Rol de instancia (IAM instance profile) es técnicamente mejor (sin keys estáticas) y es la migración recomendada a futuro: basta dejar `key`/`secret` en null y el SDK usa la cadena por defecto. No se elige ahora porque no se verificó si las EC2 tienen instance profile y cambiarlo implica tocar las 3 instancias. Deuda registrada.

### Política (idéntica para los 3 usuarios) — versión 2, vigente desde 2026-09-30

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "ListLibraryPrefix", "Effect": "Allow", "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::atlanticerp-library",
      "Condition": { "StringLike": { "s3:prefix": ["library/*", "library"] } } },
    { "Sid": "ObjectsInLibrary", "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject",
                 "s3:AbortMultipartUpload", "s3:ListMultipartUploadParts"],
      "Resource": "arn:aws:s3:::atlanticerp-library/library/*" },
    { "Sid": "ReadPendingVersionsForCopy", "Effect": "Allow",
      "Action": "s3:GetObjectVersion",
      "Resource": "arn:aws:s3:::atlanticerp-library/library/_pending/*" }
  ]
}
```

**Por qué existe el tercer statement (hallazgo N-1 de Cybersecurity, ronda 2).** El arreglo de M-2 hace que finalize lea (`readRange` con `VersionId`, HeadObject) y copie (`CopySource=...?versionId=`) atado a la versión exacta que validó. En un bucket versionado eso exige `s3:GetObjectVersion`, que la política v1 no tenía: toda subida real habría terminado en AccessDenied (500). Los tests no lo veían porque usan un store simulado. Se acota a `library/_pending/*`: es lo único que se lee por versión; el contenido publicado se sirve con `GetObject` de la versión vigente.

- Política publicada con `aws iam create-policy-version --set-as-default` (v2, default) el 2026-09-30, con aprobación por comando de Luis. La v1 sigue listada como no default.
- Verificado con la clave de `atlanticerp-library-dev`: `head-object` por `versionId` OK, `get-object` por rango OK, `copy-object` con `CopySource?versionId=` + `MetadataDirective=REPLACE` OK, y `delete-object --version-id` sigue dando AccessDenied por la bucket policy (`explicit deny in a resource-based policy`).

Sin `s3:*`, sin `GetObjectAcl/PutObjectAcl`, sin `DeleteObjectVersion`, sin permisos de bucket (policy, CORS, versioning, lifecycle). Las URLs prefirmadas heredan estos permisos: el firmante necesita GetObject/PutObject.

### Riesgo: dev/test pueden borrar objetos que usa prod

Es inherente al bucket compartido (decisión tomada). Mitigación en capas:

1. Versionado: el DeleteObject solo deja un delete marker, recuperable 90 días.
2. Bucket policy: Deny `s3:DeleteObjectVersion` y `s3:PutBucketVersioning`/`PutLifecycleConfiguration`/`DeleteBucket*` para todo principal excepto el usuario/rol admin de DevOps (`Condition: StringNotEquals aws:PrincipalArn`). Así ningún usuario de aplicación puede borrar definitivamente ni apagar el versionado.
3. Usuarios separados por entorno: CloudTrail (eventos de administración no cubren objetos; ver punto 5) y `audit_logs` de la app registran quién borró y desde qué entorno.
4. Borrado solo con permiso `manuales.manage` (superadmin + Daniela) en los 3 entornos; el QA de dev/test no borra por accidente contenido de prod salvo que esas dos personas lo hagan.
5. Opcional, recomendado si Luis quiere más control: CloudTrail data events solo para este bucket (~$0.10 por 100k eventos, centavos al mes) para reconstruir borrados.
6. Alternativa más estricta (decisión de Luis): quitar `s3:DeleteObject` a `atlanticerp-library-dev` y `-test`, de modo que borrar solo se pueda desde prod. Costo: el borrado no se puede probar en dev/test (la UI debe manejar AccessDenied con un mensaje claro). Mi recomendación: aceptar el riesgo residual con capas 1-4 (el delete lógico basta para este caso: el impacto es recuperable) y activar esta opción solo si Luis prefiere cero riesgo.

MFA Delete no aplica (solo lo puede activar el root y es incompatible con lifecycle de versiones).

## 3. CORS del bucket

```json
[
  {
    "AllowedOrigins": ["https://dev.atlanticerp.ai", "https://test.atlanticerp.ai",
                       "https://atlanticerp.ai", "https://www.atlanticerp.ai"],
    "AllowedMethods": ["GET", "HEAD", "PUT"],
    "AllowedHeaders": ["Content-Type", "Content-Length", "Range", "Content-MD5",
                       "x-amz-checksum-sha256", "x-amz-meta-*"],
    "ExposeHeaders": ["ETag", "Content-Range", "Accept-Ranges", "Content-Length", "Content-Type"],
    "MaxAgeSeconds": 3000
  }
]
```

- `ETag` es obligatorio expuesto para multipart (fase 2). `Content-Range`/`Accept-Ranges` para el seek de video y pdf.js.
- `<video src>` e `<iframe>/<object>` de PDF no requieren CORS; sí lo requieren fetch/XHR (subida PUT, pdf.js).
- No se agrega `http://localhost:*`: el entorno local NO recibe credenciales de la biblioteca (se prueba en dev). Si Luis quiere que local suba al bucket compartido, hay que agregar el origen y otra key; no recomendado (escribiría contenido real desde laptops).
- Cuidado: el navegador no permite setear `Content-Length` manualmente, lo envía solo; se lista por si una librería lo incluye.

### TTL de URLs prefirmadas (valores técnicos, configurables, ver sección 4)

| Uso | TTL | Razón |
|---|---|---|
| Ver PDF, descargar PDF/video | 15 min | Igual que `s3-private`; la validez se evalúa al iniciar la request |
| Reproducir video | 60 min | `<video>` reusa la misma URL en cada Range/seek; si expira a mitad, el front debe pedir una nueva al recibir error |
| Subir (PUT único) | 30 min | 500 MB en redes lentas; se evalúa al iniciar el PUT |
| Subir parte multipart (fase 2) | 60 min | |

Headers de respuesta forzados al firmar el GET: `ResponseContentType` derivado de la extensión validada (nunca del valor almacenado), `ResponseContentDisposition`: `inline` para ver, `attachment; filename*=UTF-8''...` (nombre saneado y codificado) para descargar. `X-Content-Type-Options: nosniff` no es configurable en S3, por eso se fuerza el content-type.

## 4. Variables de entorno y disco (solo especificación, no implementado)

`.env` de cada EC2 (secretos solo ahí, nunca en el repo ni en compose):

```
AWS_LIBRARY_BUCKET=atlanticerp-library
AWS_LIBRARY_ACCESS_KEY_ID=...        # usuario atlanticerp-library-{env}
AWS_LIBRARY_SECRET_ACCESS_KEY=...
AWS_LIBRARY_REGION=us-east-1
```

`.env.example`: agregar las 4 con valores vacíos y región por defecto.

Disco a agregar en `config/filesystems.php`:

```php
's3-library' => [
    'driver'                  => 's3',
    'key'                     => env('AWS_LIBRARY_ACCESS_KEY_ID'),
    'secret'                  => env('AWS_LIBRARY_SECRET_ACCESS_KEY'),
    'region'                  => env('AWS_LIBRARY_REGION', 'us-east-1'),
    'bucket'                  => env('AWS_LIBRARY_BUCKET'),
    'root'                    => 'library',
    'endpoint'                => env('AWS_ENDPOINT'),   // solo si hay MinIO local
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw'                   => true,    // la biblioteca debe fallar ruidoso, no devolver false
    'report'                  => true,
    // NUNCA 'visibility' => 'public' (bucket owner enforced rechaza ACLs)
],
```

Notas para Backend Dev:
- Con `root=library` las claves lógicas son `{modulo}/{tipo}/{uuid}.{ext}`; el prefijo físico sigue siendo `library/...` (coincide con la política IAM). `_pending` queda en `library/_pending/`.
- Tope de tamaño de video/PDF y TTLs: NO hardcodear. Regla del proyecto (umbrales configurables sin deploy): guardarlos en `auth.system_settings` (patrón `share_links_expiration_days`) con CRUD/vista de superadmin; defaults 500 MB video, PDF propuesto 50 MB (a confirmar por negocio), TTLs de la tabla anterior.
- Caché Redis del listado: clave con prefijo del entorno, invalidar al subir/borrar/renombrar; aceptar hasta 60 s de desfase entre entornos (subida en prod, vista en dev).
- Si `AWS_LIBRARY_BUCKET` no está definido, los endpoints responden 503 con mensaje claro (esto además es el interruptor de rollback).
- El listado lee N sidecars en paralelo solo en cache miss (unos 27 GET), despreciable.

## 5. Costos mensuales estimados (us-east-1, precios públicos, sin Savings Plans)

Supuestos: 7 PDFs x 5 MB = 35 MB; 20 videos x 200 MB = 4 GB; total ~4 GB; versiones no vigentes +50% = ~6 GB. 32 usuarios reales. Escenario base: 300 reproducciones/mes x 150 MB transferidos promedio (no siempre se ve completo) = 45 GB. Escenario alto: 1.000 reproducciones x 200 MB = 200 GB, crecimiento a 10 GB de almacenamiento.

| Concepto | Base | Alto |
|---|---|---|
| Almacenamiento S3 Standard ($0.023/GB) | ~$0.14 | ~$0.35 |
| Requests (PUT/GET/LIST, Range incluidos) | < $0.10 | < $0.30 |
| Egreso a internet ($0.09/GB) | $0 (45 GB dentro de los 100 GB/mes gratis de la cuenta) | ~$9 (100 GB facturables) |
| CloudTrail data events (opcional) | ~$0.05 | ~$0.20 |
| **Total** | **< $1/mes** | **~$10/mes** |

Sin free tier de egreso (si ya está consumido por otros servicios de la cuenta): base ~$4, alto ~$18. No hay costo fijo (sin CloudFront, sin KMS, sin NAT). Impacto en el total actual (~$45/mes): despreciable.

## 6. Plan de despliegue y rollback

Orden (DevOps ejecuta; cada comando `aws` pasa por aprobación nativa de Luis):

1. Aprobación de Luis de este documento y de las 3 decisiones de la sección 8.
2. Crear una sola vez: bucket (nombre verificado con `head-bucket`), BPA ON, Ownership enforced, versionado, SSE-S3, lifecycle, CORS, bucket policy (TLS + Deny DeleteObjectVersion/config). Probar con un objeto de prueba y borrarlo.
3. Crear 3 usuarios IAM + política + access keys; guardar cada key directo en el .env de su EC2 (nunca por chat ni repo).
4. Dev: agregar `AWS_LIBRARY_*` al .env, desplegar backend/frontend con `infra/deploy-manual.sh` (frontend primero; el script borra BD/dist locales, no correrlo con Pre-QA en curso; GitHub Actions ha fallado por facturación). Recrear contenedores con `--force-recreate` al tocar .env. Smoke: subir PDF y video de 500 MB, seek de video, descarga, borrado lógico, restauración de un delete marker. Pre-QA.
5. Test: mismas variables, despliegue `dev -> test` completo (push completo, regla vigente), smoke corto. Verificar que ve lo subido desde dev (bucket compartido) tras <= 60 s.
6. Prod: tras aprobación del cliente (flujo habitual `test -> master`); smoke con un PDF; verificar en CloudTrail que el principal es `atlanticerp-library-prod`.
7. Suscribir el checklist de Cybersecurity (abajo) antes del paso 4.

Rollback:
- Aplicación: quitar `AWS_LIBRARY_BUCKET` del .env y recrear el contenedor (endpoints responden 503, resto de AtlanticERP intacto) o revertir el release.
- Credenciales comprometidas o mal uso: desactivar la key del usuario del entorno afectado (`update-access-key --status Inactive`), rotar.
- Datos: borrados accidentales se recuperan quitando el delete marker (90 días). Un PutObject sobrescribiendo una clave UUID es imposible en práctica (UUID nuevo por subida).
- Bucket: no se elimina en rollback (está vacío de riesgo y cuesta centavos); si hiciera falta hay que vaciar todas las versiones, lo cual exige el principal admin (la policy lo impide al resto).

### Checklist para Cybersecurity

- Validar server-side ANTES de emitir la URL: permiso `manuales.manage`, módulo y tipo contra lista blanca, extensión en lista blanca (pdf, mp4, webm; rechazar mov/svg/html/zip), mime declarado coherente con la extensión, tamaño declarado <= tope configurable.
- Clave S3 100% generada por el servidor (`uuid` + extensión de la lista blanca). El nombre visible del usuario vive solo en el sidecar, se sanea (longitud, caracteres de control, `..`, `/`, `\`, nulos, unicode bidi) y se escapa al renderizar y en `Content-Disposition`. Nunca se usa en una clave ni en una ruta.
- `Content-Type` y `Content-Length` NO quedan firmados en el PUT prefirmado (ver corrección en §1, M-1); el finalize re-verifica con HeadObject + magic bytes + escaneo de contenido activo, atado a la `VersionId` validada, y borra si no coincide. El tope real no depende del cliente.
- Staging `_pending` + lifecycle: nada sin finalize es visible; evitar que alguien suba y luego referencie claves ajenas (el finalize solo acepta uuid emitidos a ese usuario, guardar en Redis con TTL).
- Rate limit en emisión de URLs de subida (evitar llenar el bucket: 500 MB por request); cuota de pendientes por usuario.
- Modo de fallo: si el sidecar es manipulado no hay XSS (renderizado como texto); PDF servido desde dominio de S3 (origen distinto al de la app), con content-type forzado.
- Auditoría: registrar subida, renombre, borrado y descarga vía `AuditService` con IP y usuario.
- Rotación de las 3 keys (90 días) y decisión futura de instance profile.
- Revisar la política Deny del bucket y que ningún usuario de app pueda modificar CORS/policy/versionado.
- El Anthropic/otros secretos no aplican; ningún secreto va en la imagen ni en logs.

## 7. Riesgos abiertos y lo que NO pude verificar

No ejecuté ningún comando `aws` (ni de lectura), para no disparar aprobaciones; lo siguiente queda sin verificar y DevOps debe comprobarlo antes del paso 2:

1. Disponibilidad global del nombre `atlanticerp-library`.
2. Si existe Block Public Access a nivel de cuenta y si hay políticas SCP/Organizations que restrinjan creación de buckets o usuarios IAM.
3. Si las EC2 tienen instance profile (condiciona la migración futura a rol).
4. Nombres y políticas exactas de los usuarios IAM actuales (se diseñó para no tocarlos).
5. Si el free tier de 100 GB/mes de egreso ya lo consumen otros servicios de la cuenta (afecta la sección 5 en $4-$8).
6. Si CloudTrail ya está activo en la cuenta y su retención.
7. Desfase de caché entre entornos (hasta 60 s) es un comportamiento esperado, no un bug.
8. Riesgo residual de borrado cruzado dev/test -> prod (mitigado, no eliminado).
9. Seek de video sobre presigned URL depende de que el archivo tenga `moov atom` al inicio (faststart); recomendar a quien suba exportar mp4 con faststart, si no el inicio de reproducción será lento. Validación de códec no se hace en servidor.
10. Sin transcodificación: un video en un códec no soportado por el navegador (ej. HEVC en Chrome) no se verá; limitar a mp4 H.264/AAC y webm.

## 8. Necesita decisión de Luis

1. Aprobar bucket compartido único con 3 usuarios IAM dedicados (recomendado) vs reutilizar keys actuales.
2. Borrado: aceptar delete lógico + versionado 90 días en los 3 entornos (recomendado) vs quitar DeleteObject a dev/test.
3. Sin CloudFront en esta fase (recomendado), con disparador documentado.
4. Tope de PDF (propuesto 50 MB) y formatos de video permitidos (mp4/webm) — decisión de negocio.
5. CloudTrail data events del bucket (~$0.05-0.20/mes) sí/no.

### Decisiones de Luis registradas (2026-09-30)

- **Lectura de todos los manuales por todo usuario autenticado** (incluidos los de Admin & Contabilidad y Gerencia): decisión de Luis; los manuales son material de capacitación, no datos. No se filtra por acceso al módulo.
- **Riesgo residual N-4 ACEPTADO:** el escáner de contenido activo de PDF no ve nombres dentro de object streams comprimidos. Se acepta como control anti-error, no anti-adversario (solo 2 personas pueden subir y el PDF se abre en el origen de S3, no en el de la app). Siguiente paso si el riesgo crece: ClamAV.
- **M-1 (Content-Length no firmado en el PUT prefirmado)** aceptado y mitigado con finalize + lifecycle de `_pending/` + cuota de pendientes; **M-4 (catálogo compartido dev/test/prod)** aceptado con prefijo de prueba `zz-prueba-` y limpieza al cerrar cada Pre-QA.

## 9. Checklist de handoff para DevOps

- [ ] Ejecutar secciones 1-3 (recomendado Terraform; en su defecto AWS CLI con aprobación por comando).
- [ ] Generar y entregar keys por canal seguro a los 3 .env (secretos manuales, no se replican solos).
- [ ] `.env.example` con las 4 variables nuevas.
- [ ] Backend Dev: disco `s3-library`, endpoints emitir-URL/finalize/listar/borrar, settings parametrizables, pruebas con S3 fake (`Storage::fake`) y smoke real en dev.
- [ ] Healthcheck: no se requiere nuevo; los endpoints responden 503 si falta configuración.
- [ ] Alarma opcional CloudWatch: `BucketSizeBytes` > 50 GB (aviso de crecimiento anómalo) hacia SNS `atlanticerp-alerts`.
- [ ] ADR: si se aprueba, convertir este documento en `docs/adr/ADR-00X-biblioteca-manuales-s3.md` (Tech Writer).
