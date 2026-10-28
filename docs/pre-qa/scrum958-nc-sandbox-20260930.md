# Pre-QA SCRUM-958 — Notas de credito y correccion de datos (dev.atlanticerp.ai)

**Fecha:** 2026-09-29/30 · **Entorno:** https://dev.atlanticerp.ai (solo). Sin SSH, sin Docker local, sin tocar test/prod.
**Config fiscal original anotada al empezar:** `pac_provider=msl`, `pac_ambiente=testing`, `primary_approval_threshold=5000`, `pac_doc_factura_habilitado=true`, `pac_doc_nota_credito_habilitado=true`, `serie_fiscal=ILU`.
**Tope de sandbox (Luis): 4 documentos nuevos certificados/intentados.** Contador al final del documento.

## Fixtures creados (por API real, flujo [persona] -> Bodega)
Cliente master 7706 / subcliente DIGIFACT 7710 (RUC real). El precio unitario del cotizador se pisa con el del catalogo (18.37), asi que las facturas grandes se hicieron con cantidad 300 (total 5896.77 > umbral 5000).
- Pedidos 65 (PED-0045, 1 u, sin facturar), 66 (F-0018, 19.66), 67 (PED-0047, 1 u, sin facturar), 68 (F-0019, 19.66): fixtures chicos (mi primer intento de precio grande fue ignorado por el catalogo).
- Pedidos "Grande A/B/C" = 69 (F-0020), 70 (F-0021), 71 (sin facturar aun; reservado para sandbox doc 4).

## Bloque A — Umbral / aprobacion / rechazo en modo APAGADO (`pac_ambiente=apagado`, cero costo de sandbox)
| Escenario de ruptura | Resultado |
|---|---|
| Correccion sin comprobante (Contabilidad) | 422 "comprobante obligatorio" |
| Contabilidad registra correccion sobre F-0020 (5896.77 > 5000) | 201 NC-0005 `pendiente_aprobacion`; F-0020 NO anulada; sin factura nueva; `fe_estado` de la nota = null; transmisiones vacias |
| Segunda correccion sobre la misma factura mientras hay una pendiente | 422 "ya tiene una correccion pendiente de aprobacion de Aprobador" |
| Contabilidad `GET notas-credito/5` | `puede_aprobar_rechazar=false`, `puede_ver_fe_transmisiones=false` |
| Aprobador `GET notas-credito/5` | `puede_aprobar_rechazar=true`, `puede_ver_fe_transmisiones=true` |
| Contabilidad `PUT decision` (approve true) | 403 "exclusiva de Aprobador" |
| Aprobador aprueba NC-0005 en apagado | 200 `aplicada`; F-0020 `es_anulada=true`; factura nueva F-0022 (`factura_nueva_numero`) con `fe_estado=no_aplica`; ninguna transmision registrada (nota, F-0020, F-0022) |
| Aprobador decide de nuevo una nota ya aplicada | 422 "ya no esta pendiente" |
| Rechazo sin `motivo_rechazo` (NC-0006 sobre F-0021) | 422 |
| Aprobador rechaza NC-0006 con motivo | 200 `rechazada`; F-0021 sigue vigente (no anulada), sin factura nueva, sin transmisiones |
| Correccion bajo umbral en apagado (F-0018, 19.66) | 201 NC-0007 `aplicada` de inmediato; factura nueva F-0023 `no_aplica`; sin transmisiones |
| Paso 5: NC descuento comercial (NC-0008, 1.00) contra F-0016 certificada, en apagado; `fe-preview` | `ok=true`, `ambiente "sin envío"`, serie `NC-ILU`, numero `"0008"`, receptor DIGIFACT, items, pagos 1, `referencia.cufe` = CUFE de F-0016 (`FE0120000155704849-...063271`), `fecha` de la factura origen; `fe_estado=no_aplica`; transmisiones vacias (paso 6: cero llamadas a MSL) |

Nota: NC-0008 (1.00, aplicada en apagado) quedo como descuento real sobre F-0016 (saldo 18.66).

## Bloque B — Sandbox (`pac_ambiente=testing`, proveedor `msl`)

### Doc 1 (intento 1/4) — NC descuento comercial 5.00 contra F-0016, registrada 2026-09-29 22:56 (Panama): RECHAZADA por MSL/DGI
- `POST notas-credito` -> 201 NC-0009 `aplicada` (la nota se crea igual, sin bloquearse); `fe_estado=rechazado`, sin CUFE.
- Registro de transmision id 3: `/v1/notas-credito`, `provider msl`, `ambiente pruebas`, `serie NC-ILU`, `numero_fiscal "0009"`, `http_status 422`, `resultado rechazado`, 627 ms. Payload correcto en forma: `numero` numerico "0009", receptor DIGIFACT, item sintetico (4.67 + ITBMS 7% = 5.00), `pagos` 5, `referencia.cufe` = CUFE de F-0016, `referencia.fecha = 2026-09-29T21:08:51-05:00` (= la `fechaEmision` enviada al certificar F-0016), `fechaEmision = 2026-09-29T00:00:00-05:00`. Sin API key ni xmlFirmado en request/response.
- Respuesta de MSL (textual): `9026 - Falló la validación de algunos escenarios. ... Verifique la fecha referenciada ... FE_ValidacionFechaReferenciada / Verificación de Fecha referenciada / 9026 / FE_VERIFICACION_EVENTOS`. El campo `mensaje` de MSL agrega el texto generico "El numero 0009 ya fue registrado ante la DGI para este emisor/sucursal/punto (aunque cambie la serie). Use un numero nuevo." (es el texto fijo de MSL para 9026; la validacion que falla segun `detalles` es la de la FECHA REFERENCIADA, no la unicidad).
- **Hipotesis de causa (no confirmada aun):** la NC lleva `fechaEmision` = fecha de la nota a las 00:00 (`AdminContCreditNote.fecha` es `date`, `->toIso8601String()`), mientras la factura de origen se emitio ese mismo dia a las 21:08:51. La NC quedaria "emitida antes" que el documento que referencia. Si es asi, toda NC registrada el MISMO dia de la factura falla. Se verifica repitiendo pasada la medianoche (NC con fecha 2026-09-30 > factura 2026-09-29 21:08). Alternativa: que F-0016 (certificada en el punto viejo) no sirva de referencia en el punto nuevo (no distinguible sin otra factura certificada).
- Consecuencia sistemica ya observable: una certificacion rechazada NO tiene reintento (no existe endpoint/accion de recertificar); la nota queda aplicada con `fe_estado=rechazado` para siempre.

### Paso 8 (sin costo) — F-0016 en UI (Playwright, Aprobador)
Badge "Certificada" presente, CUFE `FE0120000155704849-...063271` visible, link "Ver QR" (`target=_blank`) abre `dgi-fep-test.mef.gob.pa:40001` ("Consultar Facturas Por QR", portal de pruebas de la DGI). OK.

### Paso 16, primera parte, en sandbox y sin costo de documento
Con `pac_ambiente=testing`, correccion por UI (Aprobador) sobre F-0021 (5896.77 > 5000; factura sin certificar, `no_aplica`, para no gastar documentos): la pantalla de revision previa muestra "supera el umbral de aprobacion de Aprobador - NO se genera ningun documento fiscal todavia"; `POST correccion` 201 -> NC-0010 `pendiente_aprobacion`, `fe_estado=null`, cero transmisiones, F-0021 NO anulada, sin factura nueva. Aprobador rechaza (`approve=false` + motivo) -> `rechazada`, cero transmisiones, F-0021 intacta.

### Doc 2 (intento 2/4) — NC descuento 5.00 contra F-0016, registrada 2026-09-30 00:00 Panama: CERTIFICADA (paso 9 OK)
Repetido pasada la medianoche (fecha de la NC 2026-09-30 > emision de F-0016 el 2026-09-29 21:08:51): `POST notas-credito` -> NC-0011 `aplicada`, `fe_estado=certificado`, CUFE propio `FE0420000155704849-2-2021-3200022026093000000000110090122075979818` (prefijo FE04 de NC, distinto al de la factura), `referencia.cufe` = CUFE de F-0016 (FE01...160020121586063271). Registro de transmision id 4: `/v1/notas-credito`, `numero "0011"` (solo digitos), `serie NC-ILU`, HTTP 200, 2402 ms, `pagos` 5.00; sin API key ni xmlFirmado. UI: detalle de la NC muestra "Certificada", CUFE, "Ver QR", factura de origen F-0016 y el visor con payload/respuesta. **Esto confirma la hipotesis del Doc 1** y demuestra que F-0016 (certificada en el punto viejo) SI sirve de referencia en el punto nuevo. Consume el numero de NC 0011 del sandbox. Una NC parcial no anula la factura (F-0016 siguio vigente).

### Docs 3 y 4 (intentos 3 y 4/4) — "Corrección de datos" sobre F-0016 por UI (Aprobador), monto 19.66 < umbral 5000
- Paso 13: `POST correccion` 201 -> NC-0012 `aplicada` de inmediato (sin pasar por `decide()`); la pantalla de revision previa NO mostro el aviso de umbral (correcto).
- Paso 14: F-0016 `es_anulada=true` (anulada_at); NC-0012 `factura_nueva_numero=F-0024`; F-0024 es la factura vigente del pedido 62.
- Paso 15: F-0024 (invoice 33) `fe_estado=certificado` por su cuenta, sin accion manual: CUFE propio `FE0120000155704849-2-2021-3200022026093000000000240090122867612369` (distinto al de F-0016), transmision id 6 `/v1/facturas`, `numero "0024"`, `fechaEmision 2026-09-30T00:01:23-05:00`, HTTP 200, 767 ms. Badge "Certificada", CUFE y visor visibles en UI.
- **PERO la NC-0012 fue RECHAZADA (intento 3, transmision id 5, HTTP 422)**: `9026 ... El monto de la Nota de Credito (19.66) excede el saldo disponible (14.66) de la Factura Electronica referenciada ... FE_ValidacionEventosRechazos / VALIDATE_B606_BALANCE`. Causa: la NC-0011 (5.00) ya habia acreditado parte de F-0016 ante la DGI, y la NC de correccion acredita el total completo de la factura sin descontar lo ya acreditado. Resultado: F-0016 quedo anulada en AtlanticERP pero VIGENTE ante la DGI (su anulacion fiscal fue rechazada) y F-0024 SI quedo certificada: la misma venta esta facturada dos veces fiscalmente. Ver Hallazgo 2.

## Contador de documentos del sandbox de MSL (tope 4/4, AGOTADO)
| # | Documento | Resultado | Numero fiscal consumido |
|---|---|---|---|
| 1 | NC-0009 (descuento 5.00 vs F-0016, mismo dia) | rechazada 9026 fecha referenciada | ninguno |
| 2 | NC-0011 (descuento 5.00 vs F-0016, dia siguiente) | CERTIFICADA | NC-ILU 0011 |
| 3 | NC-0012 (correccion, anulacion de F-0016) | rechazada 9026 saldo (B606_BALANCE) | ninguno |
| 4 | F-0024 (factura de reemplazo) | CERTIFICADA | ILU 0024 |
Adicionales de F-0016/F-0017 de la corrida previa (SCRUM-987): ILU 0016 certificada. Total en esta corrida: 4 intentos, 2 numeros consumidos (NC 0011, factura 0024).

## Hallazgos
1. **CRITICO — Toda NC registrada el MISMO DIA que la factura que referencia es rechazada por la DGI (9026 `FE_ValidacionFechaReferenciada`, "Verifique la fecha referenciada").** Criterio incumplido: paso 9 ("NC contra una factura recien certificada -> CUFE propio"), que es justo el flujo natural de prueba y de uso. Reproduccion: facturar y certificar en sandbox un dia D a las 21:08:51, registrar NC-0009 el mismo dia D -> 422; repetir el dia D+1 (NC-0011) -> certificada. Causa (lectura de codigo, `FiscalDocumentBuilder::paraNotaCredito`): `fechaEmision` de la NC = `AdminContCreditNote.fecha` (columna date) -> `2026-09-29T00:00:00-05:00`, anterior a `referencia.fecha` = emision de la factura (`2026-09-29T21:08:51-05:00`). Evidencia: transmisiones id 3 (rechazada) y id 4 (certificada) del NC-0009/NC-0011. Nota: el texto `mensaje` de MSL dice "el numero 0009 ya fue registrado ante la DGI" pero es el texto fijo de MSL para 9026; el detalle real es la fecha referenciada. Afecta tambien la NC de "Corrección de datos" hecha el mismo dia de la factura (el caso mas comun: corregir apenas se factura). No corregido por mi.
2. **CRITICO (integridad fiscal) — "Corrección de datos" sobre una factura que ya tiene una NC parcial certificada: la NC de correccion acredita el total completo, la DGI la rechaza por saldo (VALIDATE_B606_BALANCE), pero AtlanticERP igual anula la factura original y certifica la de reemplazo.** Resultado: F-0016 vigente ante la DGI + F-0024 certificada = doble facturacion fiscal; en AtlanticERP la nota figura `aplicada` con badge "Rechazada por la DGI" y sin ninguna accion de remediacion. Reproduccion: NC-0011 (5.00, certificada) sobre F-0016, luego correccion de fecha sobre F-0016 -> NC-0012 rechazada 9026, F-0024 certificada (transmisiones id 5 y 6). Criterio incumplido: pasos 13-15 (la factura nueva depende de una anulacion fiscal valida de la original). Sugerencia (no aplicada): acreditar solo el saldo disponible o bloquear la correccion si la factura ya tiene NC; y/o no certificar la factura de reemplazo si la NC fue rechazada.
3. **MEDIO — Una certificacion rechazada no tiene camino de reintento** (no hay endpoint ni boton de recertificar en `routes/admin-contab.php` ni en la UI de facturacion/notas): NC-0009 y NC-0012 quedan `rechazado` para siempre aunque la causa se corrija (p. ej. pasada la medianoche NC-0009 seria valida). Agrava los hallazgos 1 y 2.
4. **BAJO/INFO — la unicidad "sin serie" de la DGI (9026 numero repetido) comparte espacio de numeracion entre facturas y NC de un mismo punto**: hoy la NC-ILU usa `numero = folio NC` (0009, 0011, 0012...) y la factura `numero = folio F` (0016, 0024...). No colisionaron en esta corrida, pero cuando la numeracion de NC alcance la de facturas ya emitidas en el punto (o viceversa) MSL/DGI rechazara por 9026; conviene que Luis confirme con MSL si el espacio es compartido entre tipos de documento.

## Pasos del protocolo — resultado
| Paso | Resultado |
|---|---|
| 1-8 | ya validados en la corrida previa (SCRUM-987); paso 8 reconfirmado hoy en UI: badge Certificada, CUFE, "Ver QR" abre el portal de pruebas de la DGI |
| 5 y 6 (NC en apagado contra factura certificada, sin llamadas a MSL) | OK: NC-0008, `fe-preview` con `referencia.cufe` correcto, `no_aplica`, cero transmisiones |
| 9 (NC con CUFE propio y referencia correcta) | OK solo cuando la NC no es del mismo dia (NC-0011); FALLA el mismo dia (Hallazgo 1) |
| 10 (vaciar API key) | NO EJECUTADO: diferido a Luis (requiere SSH/.env, lista dura) |
| 11 (202/PENDIENTE) | NO EJECUTABLE: el sandbox no permite provocarlo; cubierto solo por tests automatizados |
| 13 (correccion bajo umbral queda aplicada de inmediato) | OK |
| 14 (original anulada + factura nueva vinculada) | OK |
| 15 (factura nueva certificada con CUFE propio) | OK (F-0024), pero con NC de anulacion rechazada (Hallazgo 2) |
| 16 primera parte (sobre umbral -> `pendiente_aprobacion`, nada se certifica) | OK, en apagado (NC-0005/0006) y en sandbox (NC-0010, sin gastar documentos, sobre factura no certificada); rechazo de Aprobador no certifica nada; Contabilidad 403 en decision, `puede_aprobar_rechazar=false` |
| 16 segunda parte (al aprobar, recien ahi se certifica la factura de reemplazo) | NO CUBIERTO POR EL TOPE (costaria 2 documentos mas: NC + reemplazo). En apagado la aprobacion genero F-0022 `no_aplica` sin transmisiones. Decision de Luis |
| Visor "Ver transmisiones al PAC" | OK: id 3/4/5/6 con `numero` numerico, `referencia.cufe` en NC, HTTP 200/422, sin API key ni xmlFirmado; visible en UI para Aprobador en NC-0011, NC-0012 y F-0024 |

## Veredicto
**HALLAZGOS BLOQUEANTES** (2 CRITICOS: NC del mismo dia rechazada por la DGI; correccion sobre factura con NC previa deja doble facturacion fiscal) + 1 MEDIO (sin reintento). No corresponde pasar a QA. Lo demas del protocolo (umbral, aprobacion/rechazo, permisos de Contabilidad, visor, anulacion y factura vinculada, certificacion de la NC dia siguiente) funciona.

## Estado que quedo en dev
- `pac_ambiente` original: `testing` (proveedor `msl`). Cambie a `apagado` para el bloque A y volvi a `testing`; verificado por API al final (`msl`/`testing`, umbral 5000 intacto).
- Fixtures: pedidos 65 (sin facturar), 67 (sin facturar), 71 "Grande C" (sin facturar, reservado para un doc que el tope no dejo usar), 66/68/69/70 facturados (F-0018..F-0021). Facturas nuevas: F-0022 (reemplazo de F-0020, apagado), F-0023 (reemplazo de F-0018, apagado), F-0024 (reemplazo de F-0016, certificada en sandbox). NC-0005..NC-0012. F-0016 anulada; F-0020 anulada; F-0018 anulada.
- Spec de Playwright sin commitear: `atlanticerp-frontend/e2e/_preqa_scrum958_nc.spec.ts`; capturas en `atlanticerp-frontend/e2e/.tmp/s958-*.png`. Scripts auxiliares en el scratchpad de la sesion.
- No hice git add/commit/push, no SSH, no marcadores, SCRUM-958 sigue `In Progress`.
