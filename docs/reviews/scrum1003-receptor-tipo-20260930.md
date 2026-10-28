# Senior Review independiente — SCRUM-1003 Tipo de cliente ante la DGI (2026-09-30)

Backend 0535bbda, frontend 6b0bdc2. Solo lectura; WIP ajeno de SCRUM-990 ignorado. No se corrieron suites (revisión estática).

## Veredicto: DEVUELTO (1 bloqueante funcional de punta a punta)

## BLOQUEANTE

### B1. Un SubCliente Consumidor Final no se puede cotizar, y sin cotización no hay pedido ni factura
`app/Modules/VentasDiseno/Models/Quote.php:349`: `validationErrorsForGenerating()` exige `empty($this->ruc)` => error "RUC / Cédula". `QuoteService.php:65` y `:74` prefillan `quote.ruc` con `subClient->tax_id`, que para un `02` es `''` (y `QuotePage.tsx:618/628/661/679/915/922` lo copian tal cual). Resultado: "Guardar y generar"/confirmar cotización (QuoteController:365, QuoteService:282/316) devuelve "falta RUC / Cédula" para todo cliente `02`. La cadena cotización -> pedido -> factura es la única vía por la que se factura a un SubCliente, así que el `02` nunca llegaría a facturarse (el flujo que el ticket habilita). Los tests no lo detectan: el test del PDF crea la factura con el cliente aún `01` y cambia el tipo después; ninguno intenta generar una cotización de un `02`.
Fix: en `validationErrorsForGenerating` (y el equivalente de ServiceQuoteService si aplica) no exigir RUC cuando el SubCliente de la cotización es `02` (cargar `subClient->receptor_tipo`), y que `QuotePage` no lo marque obligatorio ni muestre vacío; el PDF de cotización (`quote-pdf.blade.php:140`) debe mostrar "Consumidor final" en lugar de `RUC: ` vacío. Agregar test: crear cotización para subcliente 02 y generar sin 422.

## Sugerencias (no bloquean)
- S1 `ClientsRevalidateTaxIdsCommand.php:159`: los `02` aparecen en el reporte como `sin_ruc` (ruido/falso hallazgo). Saltar `receptor_tipo === '02'` con resultado `consumidor_final`.
- S2 `tax_id = ''` vs NULL: `InvoiceBookExportService.php:32` (`$d['ruc'] ?? '—'`) y `quote-pdf.blade.php:140` imprimen vacío en vez de "—" porque `''` no es null. `InvoiceService.php:1010`/`InvoiceBookService.php:99,167` podrían devolver `null` para `''` (`filled() ?: null`). `ClienteMatcher::normalizeCif` ya trata `''` como null (sin riesgo de fusión con legacy); no hay índices únicos sobre `sub_clients.tax_id`.
- S3 Riesgo de permisos (no bloqueante, a decisión de Luis): `ventas_diseno.write` (alta) y `ventas_diseno.edit` (PATCH, si el cliente no tiene documentos certificados/pendientes) pueden marcar un cliente como Consumidor Final, es decir, hacer que se facture a la DGI sin identificación. Lo habitual en B2B es 01; considerar restringir el cambio a 02 al permiso angosto `ventas_diseno.edit.client_tax_data` o dejar rastro más visible (ya queda en audit_logs).
- S4 Confirmar con MSL si el consumidor final tiene tope de monto o requisitos extra en la DGI (la GUIA solo pide `tipo` y `nombre`; el payload actual cumple: tipo 02, nombre CONSUMIDOR FINAL, sin ruc/dv/email/dirección).
- S5 Un cliente 01->02 con facturas rechazadas/`no_aplica`/sin estado cambia el receptor de esos documentos al reimprimir (el PDF y el builder leen `sub_clients` en vivo). Mismo criterio aceptado en SCRUM-1002 (D3); anotarlo en el manual.
- S6 `ReceptorTipo::reglas()` valida el formato de `tax_id_dv` aunque con `02` se ignore (un DV basura con 02 da 422): inofensivo.

## Verificado sin hallazgos
1. Integridad: `sub_clients.tax_id` es NOT NULL sin índice único; `''` es consumido sin romper por ClienteMatcher (null), ClientsService búsqueda `ilike`, InvoiceBook (string), PDF de factura (`@elseif` + leyenda), builder (`trim() === ''` ya devolvía consumidorFinal). Los dos únicos lugares que deciden "consumidor final" por vacío (builder y bloqueo del PDF) ahora respetan `receptor_tipo`; un `01` sin RUC sigue bloqueando el PDF (test).
2. Fiscal: `receptorParaInvoice` sirve a factura y NC; con `02` produce `FiscalParty::consumidorFinal()` (tipo 02, nombre CONSUMIDOR FINAL, ruc/dv null), coincide con el ejemplo de GUIA_ILUMINATION (receptor `{tipo:"02", nombre:"CONSUMIDOR FINAL"}`). NULL y `01` sin cambio (test de regresión). El 409 por certificados/pendientes cubre el cambio de tipo (`$cambia` incluye receptor_tipo).
3. Validación: `required_unless:receptor_tipo,02` + `nullable`: ausente, `''` (se vuelve null) o `01` exigen RUC/DV; `'02 '` lo recorta TrimStrings; entero `2` y arrays los rechazan `string`/`in` (422); `02` con RUC/DV enviados se ignoran (`normalizar`). PATCH sin `receptor_tipo` = `01`. Mass-assignment: `receptor_tipo` en `$fillable` solo se alcanza vía datos ya validados/normalizados. Auditoría: LogsAuditTrail registra `receptor_tipo` (test).
5. Frontend: selector explícito sin inferencia, 02 oculta RUC/DV/validador y no manda tax_id/dv, los estados se conservan al alternar 02/01 (state en el componente), validación coherente (`taxDataValid`), directorio y detalle muestran "Consumidor final" (i18n es/en agregado), sin emojis. Alta desde Pipeline (ClientController::storeSubClient) y de cliente/subcliente pasan por `ReceptorTipo::normalizar`. Leyenda del PDF correcta: conserva el nombre real y no imprime "CONSUMIDOR FINAL" (test).
6. Tests: cubren alta/edición/vuelta, rechazo de 03/04, null=01, auditoría, 409, builder 02 vs null/01, PDF con leyenda y regresión de 01 sin RUC; fallarían con el código viejo. Falta: cotización de un 02 (B1) y NC de un 02.

---
# Re-review (backend HEAD "un Consumidor Final se puede cotizar sin RUC...") — VEREDICTO FINAL: APROBADO

- B1 cerrado de punta a punta. `Quote::validationErrorsForGenerating` ya no exige "RUC / Cédula" si `subClient->receptor_tipo === '02'`; es el único punto de validación usado por generar (`QuoteController:366`), guardar-borrador (`QuoteService:282`) y confirmar (`QuoteService:316`), así que los tres quedan cubiertos. `ServiceQuoteService` no valida RUC. `QuoteRequest` ya tiene `ruc` nullable. Barrido de `ruc`/`cliente_ruc`/`tax_id` en AdminCont, Bodega, Servicios y VentasDiseno (servicios, controladores, requests, vistas, lang): las únicas exigencias de RUC vacío eran la del PDF de factura (ya adaptada en el primer commit) y la de la cotización (ahora adaptada); creación de pedido, facturación (planner/parcial), NC, preview FE y PDFs de NC/recibo no leen ni exigen el RUC del cliente. El libro de facturas devuelve null para `''`.
- Contribuyente sin RUC sigue bloqueado (test 422 con "RUC / Cédula"); el test usa `$hierarchy['sub']->update(receptor_tipo...)` y falla con el código viejo para el caso 02.
- PDF de cotización: "Consumidor final" para 02, "—" si el RUC está vacío; `receptor_tipo` ahora viaja en `sub_client` del payload.
- Frontend de cotizaciones: `QuotePage` copia `tax_id` (`''`) sin marcarlo obligatorio ni validar client-side (el único bloqueo es el `missing` del backend, ya resuelto); el campo RUC no lleva asterisco. Sin cambios necesarios en frontend.
- S1 (comando salta los 02 sin consultar a MSL, con test) y S2 (null por `''`) resueltas. S3 queda como decisión de Luis; S4 para Pre-QA con 1 documento del sandbox; S5/S6 aceptadas.
- Nota menor sin bloqueo: `InvoiceService.php:1010` (`cliente_ruc`) sigue devolviendo `''` (el PDF ya lo maneja con `cliente_receptor_tipo`).

---

---
# Permiso Consumidor Final (decisión de Luis 2026-09-30: solo Aprobador y Contabilidad) — VEREDICTO: APROBADO

1. Bypass: `receptor_tipo` solo se escribe en 4 sitios, todos cubiertos: `ClientsController::store`/`::storeSubClient` (-> `ClientsService::createClient/addSubClient`), `ClientController::storeSubClient` (Pipeline, `SubClient::create(normalizar($validated))`) y `SubClientTaxDataService::update` vía `ClientTaxDataController::updateTaxData`. Revisados y sin vía de escritura de `receptor_tipo`: PATCH category (`$subClient->update(['category' => ...])`), `ClienteFusionLoader::promoteOne` (ICG; crea con tipo NULL = 01), QuoteService (no crea subclientes), comandos/seeders de demo (NULL), y no hay `fill($request->all())`; la API solo alcanza el modelo con datos de FormRequest ya validados. Superadmin pasa por `hasPermission`.
2. Orden validación -> permiso: un 03 o datos inválidos dan 422, un 02 válido sin permiso da 403. Lo único que se puede sondear es que el 02 existe como opción, que ya es público en el selector; sin fuga de datos.
3. `User::hasPermission` usa `effectivePermissions()` (security level + `extra_permissions`) y `superadmin.all`; es el mismo mecanismo del Precio Socio y el permiso angosto de Aprobador/Contabilidad lo siembra `SpecialPermissionSeeder`. El frontend lee la misma fuente vía el JWT (`usePermission`, con bypass de superadmin).
4. Coherencia 02 -> 01 -> 02: un vendedor puede dejar de ser consumidor final (ya había identificación que ingresar) pero no volver a marcarlo; no es una escalada, es la regla ("solo Aprobador/Contabilidad marcan 02") aplicada a la transición. Editar la razón social de un 02 existente no cambia el tipo y queda abierto.
5. Tests: los dos nuevos (3 altas y el PATCH) fallan sin la regla (esperan 403 y que nada se haya creado) y los existentes se adaptaron otorgando el permiso. Faltaría cubrir explícitamente la secuencia 02 -> 01 -> 02 -> 403 y superadmin sin extra_permissions (cobertura menor).

## Sugerencias menores
- UX: `ReceptorTipoSelect` deshabilita 02 comparando contra el valor ACTUAL; un usuario sin permiso que cambia un 02 a 01 en el modal ya no puede volver a 02 sin cancelar. Comparar contra el valor original (`subClient.receptor_tipo`).
- El comentario del test dice "no puede devolverlo a Contribuyente sin RUC": el 422 es de validación (falta RUC/DV), no de permiso; ajustar el texto.
