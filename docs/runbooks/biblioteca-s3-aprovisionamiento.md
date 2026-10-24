# Runbook D1 — Aprovisionar el bucket compartido `atlanticerp-library` (SCRUM-984)

Fuente de diseño: `docs/architecture/biblioteca-manuales-s3-diseno.md`. Esto es el paso a paso ejecutable.

**Estado (actualizado 2026-09-30): EJECUTADO, pasos 0 a 8 aplicados; paso 9 (CloudTrail) DIFERIDO.**
- Pasos 0-8 corridos por Claude Code con aprobación por comando de Luis, perfil `default` de la cuenta `940827433405`.
- `ADMIN_ARN` usado en la bucket policy (paso 8): `arn:aws:iam::940827433405:user/luis.g@grupolafayette.com` (usuario IAM, ARN estable; no una sesión temporal).
- Pre-chequeos: `atlanticerp-library` estaba libre (404), no hay Block Public Access a nivel cuenta, no existían usuarios `atlanticerp-library-*`, y las 3 EC2 de AtlanticERP ya usan el instance profile `atlanticerp-cloudwatch-agent` (migrar a rol de instancia sigue siendo la deuda futura).
- Aplicados y verificados: versionado, SSE-S3, Block Public Access (4 flags), Object Ownership BucketOwnerEnforced, lifecycle (`pending-staging-2d` para `library/_pending/` y `todo-el-bucket`: versiones no vigentes 90 días, limpieza de delete markers, abort de multipart a 2 días), CORS de los 4 orígenes, política IAM `atlanticerp-library-access`, usuarios `atlanticerp-library-{dev,test,prod}` con claves en `~/atlanticerp-library-{env}.key` (permiso 600, pendientes de llevar al `.env` de cada servidor en D2) y la bucket policy (solo TLS + Deny de `DeleteObjectVersion`, versionado, lifecycle y borrado del bucket/policy para todo el que no sea el ADMIN_ARN).
- Verificación posterior con la clave de `atlanticerp-library-dev`: escribe/lee en `library/_pending/`, hace borrado lógico (delete marker), y recibe AccessDenied al listar la raíz, al apagar el versionado y al borrar definitivamente una versión (`explicit deny in a resource-based policy`). CORS responde para `https://dev.atlanticerp.ai` y no para orígenes ajenos. Quedó un archivo de prueba `library/_pending/prueba-d1.txt` que el lifecycle expira solo (el objeto a los 2 días).
- **Paso 9 diferido:** la cuenta no tiene ningún trail de CloudTrail, así que los data events del bucket exigirían crear uno (más un bucket de logs). Decisión de Luis: diferirlo; los borrados igual quedan en `audit_logs` de la app y el versionado permite recuperarlos 90 días.
- **Política IAM v2 (2026-09-30, hallazgo N-1):** se publicó la versión 2 de `atlanticerp-library-access` (`create-policy-version --set-as-default`) agregando el statement `ReadPendingVersionsForCopy` (`s3:GetObjectVersion` sobre `library/_pending/*`). Sin él, el finalize (que lee y copia por `VersionId`) daba AccessDenied. Verificado con la clave de dev: `head-object` por versionId, `get-object` por rango y `copy-object` con `CopySource?versionId=` + `MetadataDirective=REPLACE` funcionan; `delete-object --version-id` sigue en AccessDenied por la bucket policy. La v1 queda como no default. Ver sección 6.
- **Decisiones de Luis (2026-09-30):** la lectura de todos los manuales por todo usuario autenticado es decisión suya; el riesgo residual N-4 (el escáner de PDF no ve nombres dentro de object streams comprimidos) queda ACEPTADO como control anti-error, con ClamAV como siguiente paso si crece el riesgo.
- **Lifecycle (verificado contra AWS el 2026-09-30 al aplicarlo):** en `library/_pending/` el **objeto** expira a los **2 días** (regla `pending-staging-2d`, `Expiration Days 2`) y sus versiones **no vigentes** (noncurrent) al **1 día**; en el resto del bucket (lo publicado) las versiones no vigentes expiran a los **90 días** (regla `todo-el-bucket`). Las dos reglas coinciden sobre las versiones no vigentes de `_pending/` y S3 aplica la que vence antes (1 día). Ver sección 4.
- Nota de sintaxis: el borrado definitivo de una versión es `aws s3api delete-object --version-id ...` (no existe `delete-object-version` en el CLI).

**Texto original del runbook (comandos):** todo comando `aws` de este documento es de alto riesgo: se corre uno por uno,
con aprobación explícita de Luis, por una persona con un perfil AWS de administrador (el mismo que usa DevOps hoy).
Nunca en modo autónomo. Las variables de la sección 0 y los dos datos marcados `<...>` hay que completarlos antes.

Las claves de acceso que genera el paso 7 NO se pegan en chats, tickets ni commits: van de la terminal directo
al `.env` del servidor correspondiente (D2).

## 0. Variables y pre-chequeos (solo lectura)

```bash
export AWS_PROFILE=<perfil-admin-de-devops>     # el mismo con el que se administra atlanticerp-dev/test/prod
export AWS_REGION=us-east-1
export BUCKET=atlanticerp-library
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export ADMIN_ARN=$(aws sts get-caller-identity --query Arn --output text)   # <- revisar: debe ser el ARN ESTABLE del admin (usuario o rol), no una sesión temporal assumed-role/.../sesion

# 0.1 ¿El nombre está libre? (404 = libre; 403 = lo tiene OTRA cuenta -> usar atlanticerp-manuales-library)
aws s3api head-bucket --bucket "$BUCKET"

# 0.2 ¿Block Public Access o SCP a nivel cuenta que interfiera?
aws s3control get-public-access-block --account-id "$ACCOUNT_ID"

# 0.3 ¿Ya existen los usuarios? (debe salir vacío)
aws iam list-users --query "Users[?starts_with(UserName,'atlanticerp-library')].UserName"

# 0.4 ¿Las EC2 tienen instance profile? (informativo, para la deuda de migrar a rol)
aws ec2 describe-instances --filters "Name=tag:Name,Values=*atlanticerp*" \
  --query "Reservations[].Instances[].[Tags[?Key=='Name']|[0].Value,IamInstanceProfile.Arn]" --output text

# 0.5 ¿Hay un trail de CloudTrail activo? (para el paso 9)
aws cloudtrail describe-trails --query "trailList[].[Name,HomeRegion,IsMultiRegionTrail]" --output text
```

## 1. Crear el bucket (ACLs deshabilitadas desde el origen)

```bash
aws s3api create-bucket --bucket "$BUCKET" --object-ownership BucketOwnerEnforced
```
(En us-east-1 no se pasa `--create-bucket-configuration`.)

## 2. Bloquear todo acceso público

```bash
aws s3api put-public-access-block --bucket "$BUCKET" --public-access-block-configuration \
  BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
```

## 3. Versionado y cifrado

```bash
aws s3api put-bucket-versioning --bucket "$BUCKET" --versioning-configuration Status=Enabled

aws s3api put-bucket-encryption --bucket "$BUCKET" --server-side-encryption-configuration \
  '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"},"BucketKeyEnabled":false}]}'
```

## 4. Lifecycle

```bash
cat > /tmp/library-lifecycle.json <<'JSON'
{
  "Rules": [
    {
      "ID": "pending-staging-2d",
      "Status": "Enabled",
      "Filter": { "Prefix": "library/_pending/" },
      "Expiration": { "Days": 2 },
      "NoncurrentVersionExpiration": { "NoncurrentDays": 1 }
    },
    {
      "ID": "todo-el-bucket",
      "Status": "Enabled",
      "Filter": { "Prefix": "" },
      "NoncurrentVersionExpiration": { "NoncurrentDays": 90 },
      "Expiration": { "ExpiredObjectDeleteMarker": true },
      "AbortIncompleteMultipartUpload": { "DaysAfterInitiation": 2 }
    }
  ]
}
JSON
aws s3api put-bucket-lifecycle-configuration --bucket "$BUCKET" --lifecycle-configuration file:///tmp/library-lifecycle.json
```
La ventana de recuperación de un borrado es de 90 días (versión no vigente).

Qué expira y cuándo (verificado contra AWS el 2026-09-30 al aplicarlo):
- `library/_pending/`: el **objeto** expira a los **2 días** (`pending-staging-2d`, `Expiration Days 2`) y sus versiones **no vigentes** al **1 día** (`NoncurrentDays 1`).
- Resto del bucket (lo publicado): versiones **no vigentes** a los **90 días** (`todo-el-bucket`), más limpieza de delete markers huérfanos y abort de multipart a los 2 días.
- Las dos reglas se solapan sobre las versiones no vigentes de `_pending/` (1 día y 90 días): S3 aplica la que vence antes, o sea 1 día. Los 90 días rigen solo para lo publicado.

## 5. CORS

```bash
cat > /tmp/library-cors.json <<'JSON'
{
  "CORSRules": [
    {
      "AllowedOrigins": ["https://dev.atlanticerp.ai", "https://test.atlanticerp.ai", "https://atlanticerp.ai", "https://www.atlanticerp.ai"],
      "AllowedMethods": ["GET", "HEAD", "PUT"],
      "AllowedHeaders": ["Content-Type", "Content-Length", "Range", "Content-MD5", "x-amz-checksum-sha256", "x-amz-meta-*"],
      "ExposeHeaders": ["ETag", "Content-Range", "Accept-Ranges", "Content-Length", "Content-Type"],
      "MaxAgeSeconds": 3000
    }
  ]
}
JSON
aws s3api put-bucket-cors --bucket "$BUCKET" --cors-configuration file:///tmp/library-cors.json
```
Sin `localhost` a propósito: local no recibe credenciales de la biblioteca.

## 6. Política IAM compartida por los 3 usuarios (v2 vigente)

La v1 (solo `ListLibraryPrefix` y `ObjectsInLibrary`) se creó en D1, pero el finalize lee y copia por `VersionId`,
y en un bucket versionado eso exige `s3:GetObjectVersion` (hallazgo N-1). Por eso hay una **v2**, ya publicada y default
desde el 2026-09-30. Para crear el entorno desde cero, usar directamente la v2:

```bash
cat > /tmp/library-iam-policy.json <<JSON
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "ListLibraryPrefix", "Effect": "Allow", "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::${BUCKET}",
      "Condition": { "StringLike": { "s3:prefix": ["library/*", "library"] } } },
    { "Sid": "ObjectsInLibrary", "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:AbortMultipartUpload", "s3:ListMultipartUploadParts"],
      "Resource": "arn:aws:s3:::${BUCKET}/library/*" },
    { "Sid": "ReadPendingVersionsForCopy", "Effect": "Allow",
      "Action": "s3:GetObjectVersion",
      "Resource": "arn:aws:s3:::${BUCKET}/library/_pending/*" }
  ]
}
JSON
aws iam create-policy --policy-name atlanticerp-library-access --policy-document file:///tmp/library-iam-policy.json
```

Para actualizar una política v1 ya existente (lo que se hizo el 2026-09-30):
```bash
aws iam create-policy-version --policy-arn "arn:aws:iam::${ACCOUNT_ID}:policy/atlanticerp-library-access" \
  --policy-document file:///tmp/library-iam-policy.json --set-as-default
aws iam list-policy-versions --policy-arn "arn:aws:iam::${ACCOUNT_ID}:policy/atlanticerp-library-access"   # v2 IsDefaultVersion=True
```
El permiso adicional va SOLO sobre `library/_pending/*`: es lo único que se lee por versión. El contenido publicado se sirve con
`GetObject` de la versión vigente. Cambios de IAM: aprobación por comando de Luis, nunca en modo autónomo.

## 7. Tres usuarios dedicados y sus claves

```bash
POLICY_ARN="arn:aws:iam::${ACCOUNT_ID}:policy/atlanticerp-library-access"
umask 077
for ENV in dev test prod; do
  aws iam create-user --user-name "atlanticerp-library-${ENV}" --tags Key=proyecto,Value=atlanticerp Key=entorno,Value=${ENV}
  aws iam attach-user-policy --user-name "atlanticerp-library-${ENV}" --policy-arn "$POLICY_ARN"
  aws iam create-access-key --user-name "atlanticerp-library-${ENV}" \
    --query 'AccessKey.[AccessKeyId,SecretAccessKey]' --output text > "$HOME/atlanticerp-library-${ENV}.key"
done
ls -l "$HOME"/atlanticerp-library-*.key      # permisos 600; NO abrir en el chat
```
Las keys quedan en `~/atlanticerp-library-{dev,test,prod}.key`. En D2 cada una va al `.env` de su servidor y luego se borra el archivo local
(ese borrado es un `rm`, lo aprueba Luis en su momento).

## 8. Bucket policy de protección (aplicar DESPUÉS del paso 7)

```bash
cat > /tmp/library-bucket-policy.json <<JSON
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "SoloTLS", "Effect": "Deny", "Principal": "*", "Action": "s3:*",
      "Resource": ["arn:aws:s3:::${BUCKET}", "arn:aws:s3:::${BUCKET}/*"],
      "Condition": { "Bool": { "aws:SecureTransport": "false" } } },
    { "Sid": "SoloAdminBorraDefinitivoOCambiaConfig", "Effect": "Deny", "Principal": "*",
      "Action": ["s3:DeleteObjectVersion", "s3:PutBucketVersioning", "s3:PutLifecycleConfiguration",
                 "s3:DeleteBucket", "s3:DeleteBucketPolicy", "s3:PutBucketPolicy"],
      "Resource": ["arn:aws:s3:::${BUCKET}", "arn:aws:s3:::${BUCKET}/*"],
      "Condition": { "ArnNotEquals": { "aws:PrincipalArn": "${ADMIN_ARN}" } } }
  ]
}
JSON
cat /tmp/library-bucket-policy.json      # REVISAR que ADMIN_ARN sea el correcto antes de aplicar
aws s3api put-bucket-policy --bucket "$BUCKET" --policy file:///tmp/library-bucket-policy.json
```
Aplicar con un `ADMIN_ARN` equivocado bloquea a DevOps para cambiar la config; el root de la cuenta siempre puede borrar la bucket policy
para recuperarse. El vencimiento de versiones por lifecycle lo ejecuta S3 y no lo afecta esta política.

## 9. CloudTrail data events solo de este bucket (opcional, recomendado)

Requiere el nombre de un trail existente del paso 0.5 (`<TRAIL>`).
```bash
aws cloudtrail put-event-selectors --trail-name <TRAIL> --advanced-event-selectors '[
  {"Name":"Eventos de datos atlanticerp-library","FieldSelectors":[
    {"Field":"eventCategory","Equals":["Data"]},
    {"Field":"resources.type","Equals":["AWS::S3::Object"]},
    {"Field":"resources.ARN","StartsWith":["arn:aws:s3:::atlanticerp-library/"]}]}]'
```
Ojo: `put-event-selectors` REEMPLAZA los selectores actuales del trail. Antes: `aws cloudtrail get-event-selectors --trail-name <TRAIL>`
y agregar el nuevo al arreglo existente si ya hay otros, para no apagar los que ya existen.

## 10. Verificación posterior

```bash
aws s3api get-bucket-versioning --bucket "$BUCKET"                       # Status: Enabled
aws s3api get-public-access-block --bucket "$BUCKET"                     # 4 flags true
aws s3api get-bucket-lifecycle-configuration --bucket "$BUCKET"
aws s3api get-bucket-cors --bucket "$BUCKET"
aws s3api get-bucket-policy --bucket "$BUCKET" --query Policy --output text | python3 -m json.tool

# Preflight CORS desde un origen permitido y desde uno NO permitido (este no debe devolver Access-Control-Allow-Origin)
curl -sI -X OPTIONS "https://${BUCKET}.s3.amazonaws.com/library/x" \
  -H "Origin: https://dev.atlanticerp.ai" -H "Access-Control-Request-Method: PUT" | grep -i access-control
curl -sI -X OPTIONS "https://${BUCKET}.s3.amazonaws.com/library/x" \
  -H "Origin: https://ejemplo.com" -H "Access-Control-Request-Method: PUT" | grep -i access-control
```
Pruebas negativas con las credenciales de `atlanticerp-library-dev` (perfil aparte, sin pegar la key en el chat):
- `aws s3api delete-object --key <clave> --version-id <ID>` (borrado definitivo de una versión; `delete-object-version` no existe en el CLI) debe dar AccessDenied (bucket policy).
- `aws s3 ls s3://atlanticerp-library/` (sin prefijo `library/`) debe dar AccessDenied.
- Subir y leer `library/_pending/prueba.txt` debe funcionar.
- **Camino del finalize (lo que exige la política v2):** con la misma clave, `head-object --version-id`, `get-object --version-id --range bytes=0-3` y
  `copy-object --copy-source "atlanticerp-library/library/_pending/<clave>?versionId=<ID>" --metadata-directive REPLACE --content-type <mime>` deben funcionar.
  La verificación de D1 original NO ejercía este camino y por eso no detectó la falta de `GetObjectVersion` (N-1). Los archivos de prueba llevan el prefijo `zz-prueba-` (sección 13).

## 11. Rollback

1. Quitar `AWS_LIBRARY_BUCKET` del `.env` del entorno afectado y reiniciar: los endpoints responden 503, el resto de AtlanticERP sigue igual.
2. Desactivar la key: `aws iam update-access-key --user-name atlanticerp-library-<env> --access-key-id <ID> --status Inactive`.
3. Recuperar un objeto borrado por error (dentro de 90 días): el admin elimina el delete marker con `aws s3api delete-object --bucket "$BUCKET" --key <clave> --version-id <ID-del-marker>`.
4. El bucket no se elimina mientras tenga versiones; no hay costo relevante por dejarlo vacío.

## Orden de despliegue por entorno
Bucket + IAM + policy una sola vez (pasos 1-9) -> `.env` de dev y deploy a dev -> Pre-QA -> test -> prod, con `infra/deploy-manual.sh`.
Después del paso 7, B5 (carga inicial de los 7 PDFs) se corre desde dev.

## 12. Alarma de tamaño del bucket (sugerida, NO creada)

Mitiga el hallazgo M-1: el PUT prefirmado no impone el tope de tamaño, así que conviene enterarse si el bucket crece fuera de lo esperable
(por ejemplo subidas sin finalizar). Es una métrica diaria de S3 (`BucketSizeBytes`, dimensión `StorageType=StandardStorage`), por eso el período es de 1 día.
Comando sugerido (alto riesgo: pasa por aprobación de Luis como el resto; `<SNS_ARN>` es el tópico `atlanticerp-alerts` ya existente):

```bash
aws cloudwatch put-metric-alarm --alarm-name atlanticerp-library-bucket-size \
  --namespace AWS/S3 --metric-name BucketSizeBytes \
  --dimensions Name=BucketName,Value=atlanticerp-library Name=StorageType,Value=StandardStorage \
  --statistic Average --period 86400 --evaluation-periods 1 \
  --threshold 21474836480 --comparison-operator GreaterThanThreshold \
  --treat-missing-data notBreaching --alarm-actions <SNS_ARN>
```
Umbral sugerido: 20 GB (~5 veces el uso esperado de 4 GB con 7 PDFs y ~20 videos). Ajustarlo si el catálogo de videos crece de verdad.

## 13. Regla para archivos de prueba en el bucket compartido (hallazgo M-4, decisión de Luis 2026-09-30)

dev, test y prod comparten el mismo catálogo (caché de 60 s por entorno): todo lo que se suba desde dev o test lo ve prod. Luis aceptó el riesgo
(no se separan prefijos ni se quitan permisos de escritura a dev/test) con esta disciplina:
- Todo archivo de prueba lleva el prefijo de nombre visible **`zz-prueba-`** (por ejemplo `zz-prueba-manual-crm`), para reconocerlo de un vistazo y que quede al final de cualquier orden alfabético.
- **Se borra al cerrar cada Pre-QA** (desde la UI con `manuales.manage` o el endpoint DELETE). El borrado es lógico y recuperable 90 días.
- Nada de archivos de 500 MB de prueba salvo el smoke puntual del tope de video, y se borra en la misma sesión.
- Antes de cerrar un Pre-QA de Biblioteca, verificar que el listado no contenga ningún `zz-prueba-*`.
