# Pre-QA SCRUM-1137 fases 2 y 3 — RUC completo de clientes de ICG (2026-10-14)

Commit probado: `b13d0258` (dev = origin/dev, desplegado en dev.atlanticerp.ai). Sin push, sin `--apply`/`--revert` en ningún entorno, sin escrituras en la BD de dev (solo SELECT por tinker con script por stdin). Sin RUC, nombres ni correos en este informe: solo códigos y conteos.

## Veredicto: PASA (0 críticos, 0 altos, 2 medios no bloqueantes, 6 bajos)

Los 2 medios no bloquean el paso a QA; sí necesitan que PM/Luis los conozcan antes de prod (ver "Pendientes antes de prod").

## Hallazgos

### MEDIO
**M1. Las cotizaciones existentes conservan el RUC truncado; el resto del sistema lee el nuevo.**
- `quotes.ruc` se copia del sub_client al crear la cotización (`QuoteService.php:70,79`) y el PDF de la cotización lee esa copia (`quote-pdf.blade.php:142`). La factura, el PDF de la factura, el libro (`InvoiceBookService.php:99`) y `InvoiceService.php:1082` leen `sub_clients.tax_id` en vivo.
- Resultado tras corregir: una cotización vieja sigue mostrando el RUC de 12 caracteres y la factura de ese mismo pedido muestra el completo. No hay lectura que mezcle ambos valores en una misma pantalla; ningún código compara `quotes.ruc` contra `sub_clients.tax_id`, así que nada se rompe.
- Evidencia en dev: 1 cotización de las 2.982 corregidas, con el RUC viejo; `updated_at` anterior a la corrección (0 cotizaciones tocadas por el apply). Test propio: tras `--apply`, `quotes.ruc` queda igual (verde). Dev tiene 72 cotizaciones; 4 ya diferían de su sub_client (3 por causas previas).
- Es la duda que dejaba abierta el runbook ("cómo se leen después"): respuesta, se leen como copia independiente. En prod habrá más cotizaciones de este tipo.
- Acción: decisión de Luis/PM si basta con documentarlo en el runbook (Contabilidad avisada) o si se agrega una pasada aparte para refrescar `quotes.ruc` de borradores. No es un cambio de este ticket.

**M2. El 70 % de los corregidos queda sin DV; al facturar, la guarda los bloquea hasta que alguien lo ingrese.**
- Dev: 2.982 corregidos vivos; 2.077 sin DV (69,7 %), 905 con DV (ICG solo trae DV pegado en NIF20 en ~19 % de las filas). Ninguno tenía DV antes (0 registros con `tax_id_dv_anterior` no vacío), así que no es una regresión: antes tenían además el RUC truncado, que la DGI no reconoce.
- Camino de facturación (código leído, sin credenciales de MSL para probar en vivo):
  - `ReceptorFiscalGuard::evaluarDatos`: PAC `apagado` (estado actual de dev) deja pasar con aviso `no_validado`, no consulta nada. Con PAC `production`/`testing`, si la DGI reconoce el RUC y devuelve un DV y el cliente no tiene DV, bloquea con estado `dv_distinto` y el mensaje: "El cliente no tiene DV; la DGI indica DV NN." (corregible desde el modal `CorrectInvoiceReceptor`, que exige DV de 1 a 2 dígitos). Si el cliente tiene documentos certificados/pendientes, el modal no se ofrece (edicion_bloqueada).
  - Mappers: MSL omite `dv` cuando está vacío; Digifact no usa DV (solo `TaxID`).
- Riesgo real: operativo, no de datos. La primera factura de cada uno de esos clientes en prod pedirá corregir el DV. Mejor que antes (RUC inexistente para la DGI), pero Contabilidad debe saberlo.
- No verificado: la respuesta real de `validar-ruc` (sin credenciales, y el runbook ya lo deja como pendiente).

### BAJO
- **B-1 Revert puede duplicar un `tax_id`** (confirmado con test): tras `--apply`, si se crea otro sub_client con el CIF truncado, `--revert` restaura el CIF en el primero y quedan 2 con el mismo `tax_id`. No hay índice único. El revert no chequea unicidad (ya anotado como B4 en la Senior Review).
- **B-2 Prefijo por dígitos ignora la agrupación** (confirmado con test): con CIF `12-345-67890` y RUC `1234-567-8901-22` la precondición (b) pasa (compara sin guiones) y corrige. Es teórico (mismo RUC escrito con otro agrupado), no se encontró en dev.
- **B-3 `--csv` sobrescribe un archivo existente** dentro de `storage/app/private` (confirmado: pisó `legacy/otro.csv`). Rutas hostiles (`../`, `/etc/passwd`, `\..\`, prefijo parecido, absoluta fuera) se rechazan todas con exit 1.
- **B-4 El CSV de dry-run con PII queda en dev**: `storage/app/private/legacy/ruc-correcciones-dry.csv` (434 KB, 0644 root, en directorio 0700 root: no legible por otros usuarios). El runbook pide borrarlo tras revisarlo; sigue ahí.
- **B-5 DV de 3 o 4 dígitos**: `TaxIdNormalizer` acepta `DV` de 1 a 4 dígitos, la columna es `varchar(4)` y la guarda/modal exigen 1 a 2. En dev los 917 DV de sub_clients son de 1 o 2 dígitos (todos numéricos): no ocurre hoy.
- **B-6 Verificación de documentos fiscales con datos reales es vacía en dev**: dev tiene 1 factura (sin `fe_estado`) y 0 documentos certificados/pendientes, así que "los certificados no cambiaron" se demuestra por tests y código, no por datos. La primera vez con datos reales será el dry-run de test/prod (revisar cuántos salen con `tiene_documentos_fiscales`).

## Resultados por punto del encargo

1. **Documentos fiscales**: el plan, el apply (dentro de la transacción, con `lockForUpdate`) y el revert usan `SubClientTaxDataService::conDocumentosFiscales` (certificado y pendiente; facturas por pedido raíz -> tarjeta -> sub_client y NC por su factura de origen), el mismo camino que `FiscalDocumentBuilder::receptorParaInvoice`. El Matcher solo lee `sub_clients` (probado: la fila no cambia tras `match()`); `ClienteFusionLoader` solo crea sub_clients nuevos, nunca modifica uno existente. Dev: 2.982 corregidos, 0 con documentos certificados/pendientes, 0 con cualquier factura; intersección con los sub_clients bloqueados = 0.
2. **Cotizaciones**: ver M1.
3. **Facturación electrónica**: ver M2.
4. **Casos negativos** (tests propios + los 31 existentes): `--yes` rechazado en dev/qa/production/staging (y nada escrito); frase de confirmación incorrecta (otro entorno, minúsculas, otro tenant, texto extra) rechazada; huella ausente o vieja aborta; `--dry-run` combinado con `--apply`/`--revert` falla; `--revert` sin operador, con uuid inválido o inexistente falla; ambiguous/unmatched, `ruc_estado = revisar` y origen CIF nunca son candidatos; CIF con espacios o minúsculas/puntos se normaliza y corrige; CIF distinto de 12, RUC que no extiende, DV distinto, compartido, RUC ya usado, dos propuestas al mismo valor: omitidos (tests existentes en verde). Concurrencia: un segundo `aplicar` anidado con el mismo plan corrige 0 y no duplica el registro de reversa (queda 1); el re-chequeo del valor vivo bajo `lockForUpdate` lo evita. Interrupción: cubierta por test existente (excepción, no kill real).
5. **Seguridad/PII**: la consola solo muestra conteos y códigos (probado en dry-run, apply y revert); `mensajeSeguro` corta el mensaje en la primera línea y quita SQL/bindings; los errores por sub_client solo exponen el nombre de la clase. CSV: neutraliza `=`, `+`, `-`, `@`, tab y retorno (test existente). Ningún controlador, ruta ni vista referencia `clientes_raw`/`contactos_cliente_raw` (solo extractores, loaders, comandos y provider). `audit_logs` no tiene filas de las tablas raw (0) y `Cliente` no usa `LogsAuditTrail`. Dev: las 8 columnas excluidas (CVC, PASS, PASSWORDCOMMERCE, NUMTARJETA, FOTOCLIENTE, USUARIO, TIPOTARJETA, TARCADUCIDAD) ausentes en ambas tablas raw y ninguna columna con patrón PASS/TARJ/CVC/FOTO/CLAVE/SECRET. El RUC viejo/nuevo sí queda en `audit_logs` y en `cliente_ruc_correcciones` (son datos fiscales, no secretos; el visor es solo superadmin).
6. **Auditoría**: dev tiene 8.946 filas `ventas_diseno.sub_client.updated` sobre los 2.982 sub_clients = 3 por cada uno (apply, revert, apply), todas con `tax_id` en old y new; 0 sub_clients vivos sin fila de auditoría; eventos `legacy.fix_clientes_ruc` de aplicar/revertir/aplicar con conteos (2.982/0 omitidos/0 errores). Todos los registros de reversa vivos coinciden con el valor actual del sub_client (0 diferencias).
7. **Regresión**: `infra/test.sh --filter='Legacy|SubClientTaxData|ReceptorFiscalGuard|TaxId'` = 809 tests, 5.935 aserciones, OK; `--filter='SubClient|InvoiceReceptor|FiscalDocumentBuilder|ClientTaxData|ReceptorFiscal'` = 135 tests, 644 aserciones, OK. No se cambió código de `app/`, así que no se corrió PHPStan (la Senior Review lo dejó en nivel 8 sin errores). Mi archivo de tests temporal se borró; el árbol de trabajo quedó limpio.
8. **Valores de negocio hardcodeados**: ninguno nuevo. `CIF_ANCHO_ICG = 12` y `CIF_MAX_LENGTH = 12` son el ancho técnico de la columna `CLIENTES.CIF` de ICG, no un umbral de negocio; lotes de 1.000 y el patrón de DV son técnicos. El 20 % del circuit breaker ya existía.

## Estado de datos en dev (solo lectura)
Dos corridas: `f3f8a614` (2.982, revertida completa) y `4f39d019` (2.982, vigente, 0 revertidas). 0 sub_clients corregidos comparten `tax_id` normalizado con otro (hay 67 grupos duplicados previos, ninguno de los corregidos). `receptor_tipo` de los corregidos: todos NULL (contribuyente por defecto).

## Pendientes antes de prod (no bloquean QA)
1. Luis/PM: decidir qué hacer con M1 (documentar, o pasada aparte para `quotes.ruc`).
2. Avisar a Contabilidad de M2 (la primera factura de ~70 % de los corregidos pedirá DV) además del aviso ya previsto sobre el libro.
3. Revisar el CSV de test/prod, en particular el conteo `tiene_documentos_fiscales` (B-6).
4. Borrar `ruc-correcciones-dry.csv` de dev (B-4).
