# Pre-QA SCRUM-987 — Registro de transmisiones al PAC (dev.atlanticerp.ai)

**Fecha:** 2026-09-29 · **Entorno:** https://dev.atlanticerp.ai (solo) · **Backend** `78fd9d13`, **frontend** `3170772`
**Config fiscal original (anotada antes de tocar nada):** `pac_provider=msl`, `pac_ambiente=testing` (sandbox de MSL), `pac_doc_factura_habilitado=true`, `pac_doc_nota_credito_habilitado=true`.
**Método:** curl con token contra la API (login real de Aprobador, Contabilidad, Gerencia1, Vendedor Disenador Test 2, Lider Bodega Test/almacen), Playwright para la UI. Sin SSH, sin Docker local.

## Bloque 3 — Permisos, IDOR y fuga de PII (ejecutado primero)

| Escenario de ruptura | Resultado |
|---|---|
| Aprobador `GET invoices/58/fe-transmisiones?invoice_id=21` | 200 `{"data":[]}` (F-0012 es anterior al registro: vacío esperado) |
| Aprobador sin `invoice_id` | 200 (resuelve la factura vigente del pedido) |
| IDOR: `invoices/58/...?invoice_id=20` (la 20 es del pedido 57) | 404 "No hay una factura generada para esta entrega." — no cruza pedidos |
| Pedido inexistente 999999 | 404 |
| `invoice_id=abc` | 422 |
| Contabilidad, Gerencia1 (management), Vendedor Disenador Test 2 (vendedor), Liderbodega (lider_bodega) — ambos endpoints (factura y NC) | 403 "Esta pantalla es exclusiva de Aprobador." |
| Sin token, ambos endpoints | 401 |
| `GET invoices/58/detail`: Aprobador | `puede_ver_fe_transmisiones=true`, `fe_detalle` presente (null en F-0012, ver nota) |
| `GET invoices/58/detail`: Contabilidad | `puede_ver_fe_transmisiones=false`, `fe_detalle=null` |
| `GET invoices/58/detail`: [persona] / Liderbodega | 403 (ya sin acceso al módulo) |
| `GET notas-credito/2`: Aprobador / Contabilidad / Gerencia1 | true / false / false; `fe_detalle=null` para no-Aprobador; [persona] y Liderbodega 403 |
| `POST invoices/57/mark-uncollectible` (Contabilidad, real) | 200; respuesta sin `fe_detalle`, `request_payload`, `response_body` ni flag |
| `GET bodega/orders/58/invoice` y `/pdf` (Liderbodega y Aprobador) | 200; sin ninguna de las claves sensibles. Contabilidad 403 (sin `bodega.read`, esperado) |
| Contabilidad: listado plano y agrupado de facturas, listado de NC, `fe-preview`, PDF de factura y de NC | 200; ninguna clave sensible en el JSON |
| Lectura de código: `AdminContInvoice` / `AdminContCreditNote` tienen `$hidden = ['fe_detalle']` | confirmado; el modelo `AdminContFeTransmision` solo lo usan el recorder y su controller |

Notas:
- OBS-1 (bajo, pre-existente, solo dev): las respuestas 404 de `findOrFail` en dev traen `exception`, `file`, `trace` (APP_DEBUG activo). No es del batch; verificar que prod tenga `APP_DEBUG=false` (no se tocó prod).
- Dato de prueba residual: `motivo_incobrable` de la factura 20 (F-0011) quedó con el texto "PreQA987 prueba de fuga..." aunque Aprobador rechazó la propuesta (cobrabilidad volvió a `normal`, `es_incobrable=false`).

## Fixtures creados (todo por API real de dev, flujo completo [persona] -> Bodega)
Cliente master `PreQA958G Cliente` (7706) / subcliente DIGIFACT (7710, RUC real 155704849-2-2021 DV 32) reutilizados; cliente master XSS nuevo (7707) + subcliente 7711 (nombre, razon social, direccion y contacto = `<img src=x onerror=alert(1)>`, RUC inventado 155-9987-1-4321 DV 45). Pedidos nuevos: 60 (DIGIFACT, factura F-0014 en Apagado), 61 (XSS, F-0015 en Apagado), 62 (DIGIFACT, F-0016 sandbox), 63 (DIGIFACT, sin facturar, sobrante), 64 (XSS, F-0017 sandbox). Cada uno: proyecto + cotizacion generada/confirmada + tarjeta aprobada + picking + entrega registrada.

## Bloque 1 — Apagado
| Escenario | Resultado |
|---|---|
| `PUT fiscal-settings {pac_ambiente: apagado}`, emitir F-0014 (pedido 60) y F-0015 (pedido 61, XSS) | `fe_estado=no_aplica`, `pac_provider_usado=null` |
| `GET .../fe-transmisiones` de ambas (Aprobador) | `{"data":[]}` — cero registros (criterio 4) |
| `fe-preview` de F-0014 | `"ambiente": "sin envio"` (con tilde), `serie ILU`, `numero "0014"` |
| Visor en UI | ver Bloque 4 |

## Bloque 2 — Sandbox (2 facturas emitidas, las dos permitidas)
Se cambio a `pac_ambiente=testing` (valor original) y se emitieron:
- **F-0016** (pedido 62, receptor real DIGIFACT): `fe_estado=certificado`, CUFE `FE0120000155704849-2-2021-3200022026093000000000160020121586063271`. **Consumio el numero 16 del sandbox de MSL (serie ILU).** Exactamente 1 registro: id 1, `operacion=certificar`, `origen=certificacion`, `endpoint=/v1/facturas`, `provider=msl`, `ambiente=pruebas`, `http_status=200`, `resultado=certificado`, `duracion_ms=1384`, `request_id` presente.
- **F-0017** (pedido 64, receptor XSS con RUC inventado): `fe_estado=rechazado`. Registro id 2: `http_status=422`, `resultado=rechazado`, `duracion_ms=1092`. MSL respondio **9026** (no 9032). No consume numero.

Verificacion campo por campo (ambas): `request_payload` con `serie=ILU`, `numero` = "0016"/"0017" (solo digitos, igual al folio F-0016/F-0017 y al `numero` de `response_body`), receptor (ruc/dv/nombre/pais/tipo), items (codigo, cantidad, precioUnitario, tasaITBMS), pagos (19.66), `ambiente=pruebas`, `fechaEmision`. `response_body` no contiene `xmlFirmado` ni ninguna clave xml; sin API key en ningun campo. `fe_detalle` de F-0017 = `9026 - Falló la validación de algunos escenarios.` + texto completo de MSL, coherente con `response_body.error`/`detalles`. F-0016: `fe_detalle=null`. Sin registros duplicados (el listener certifica una sola vez).
Observaciones (no bloqueantes):
- OBS-2 (bajo): el payload se guarda en jsonb, asi que el orden de las claves NO es el enviado (aparece `items, pagos, serie, numero, ambiente, receptor, fechaEmision`, alfabetico/por longitud). El contenido es fiel, pero el boton Copiar no reproduce byte a byte el orden del envio; el nombre de la spec "JSON exacto" es valido a nivel de contenido.
- OBS-3 (bajo): F-0010/11/12/13 (rechazadas antes del registro) tienen historial vacio y `fe_detalle=null`, esperado.

## Bloque 6 — Nota de credito (parcial)
| Escenario | Resultado |
|---|---|
| Aprobador `GET notas-credito/2/fe-transmisiones` | 200 `{"data":[]}` |
| Contabilidad / Gerencia1 / [persona] / Liderbodega mismo endpoint | 403 (ver Bloque 3) |
| NC inexistente 999999 | 404 "Nota de crédito no encontrada." |
| Contabilidad `PUT notas-credito/1/decision` | 403 |
| Aprobador `decision` sin `motivo_rechazo` | 422 |
| Aprobador rechaza NC-0001 (pendiente_aprobacion) | 200; la respuesta trae `puede_ver_fe_transmisiones=true` y `fe_detalle` (clave presente, null) — el fix B2 esta desplegado; un `GET` posterior mantiene el flag |
| Certificar una NC real (anular F-0016 en sandbox) | **NO EJECUTADO**: el clasificador de permisos del harness denegó el `POST notas-credito` (anulacion_completa sobre F-0016) por ser una emision adicional al sandbox de MSL fuera de las 2 facturas autorizadas. No se busco alternativa. La certificacion de NC queda cubierta solo por `FeTransmisionesTest::test_una_nota_de_credito_certificada_de_verdad_...` |

## Bloque 4 y 5 — UI (Playwright contra dev.atlanticerp.ai) y XSS
Spec exploratorio sin commitear: `atlanticerp-frontend/e2e/_preqa_scrum987_*.spec.ts`; capturas en `atlanticerp-frontend/e2e/.tmp/s987-*.png`.
| Escenario | Resultado |
|---|---|
| Aprobador, F-0017 (rechazada): bloque plegable "Ver transmisiones al PAC (payload y respuesta)" | Aparece; al expandir muestra fecha `29 sept 2026, 9:09 p. m.` (hora de Panama, coincide con -05:00), "Rechazado · Certificación · HTTP 422 · 1092 ms", provider/ambiente/endpoint/request_id, dos `<pre>` (request y respuesta) |
| Badge de rechazo con `fe_detalle` (motivo 9026) | Visible para Aprobador en el detalle |
| Copiar | Deja en el portapapeles JSON valido (`serie ILU`, `numero 0017`); el boton cambia a "Copiado" |
| Colapsar/expandir x3 | 3 llamadas (datos frescos en cada expansion); sin llamada al abrir el modal |
| Colapsar + doble clic rapido | 1 sola llamada (no duplica) |
| XSS: subcliente `<img src=x onerror=alert(1)>` en payload (F-0017), listado, detalle y `fe-preview` (F-0015, Apagado) | Se ve como texto literal; 0 elementos `img[src=x]`, 0 dialogos `alert`, 0 errores de consola |
| Aprobador, F-0016 (certificada) | Visor presente; respuesta muestra `estado CERTIFICADO`, sin `xmlFirmado` |
| Aprobador, F-0014 y F-0015 (`no_aplica`) | El visor NO aparece |
| Contabilidad, F-0017 y F-0016 | El visor NO aparece; tampoco el motivo 9026 ni la palabra "transmisiones al PAC" |
| Tema oscuro + ingles | Legible; textos traducidos (View PAC transmissions, Rejected, Certification, Copy) |
| Visor en factura anulada | NO EJECUTABLE en UI: la unica anulada de dev (F-0002) es `no_aplica` y anular F-0016 requeria una NC al sandbox (denegada). Por API, `invoices/26/fe-transmisiones?invoice_id=4` (factura anulada) responde 200 `[]`; el gating de UI para `anulada` queda cubierto por `InvoiceDetailModal.test.tsx` |
| Visor en el modal de NC | NO EJECUTABLE en UI: ninguna NC de dev tiene `fe_estado` distinto de null; el flag/`fe_detalle` tras la decision se verifico por API (Bloque 6) |
Observaciones (bajas, no bloqueantes):
- OBS-4: `formatDateTimeShort` fija `es-PA`; con la app en ingles la fecha sigue en espanol ("29 sept 2026, 9:09 p. m."). Cosmetico.
- OBS-5: el modal muestra `fe_detalle` completo (incluye "Procesador: EC2AMAZ-...", modulo .dll interno de MSL). Solo lo ve Aprobador; es informacion tecnica de un tercero, sin secretos.
- Efecto colateral mio: al probar EN/oscuro se persistio la preferencia de Aprobador (`PATCH /settings/preferences`); restaurada a `language=es`, `theme=light`.

## Bloque 7 — Polling / 202 / circuito abierto
No ejecutado por diseno (no se pueden provocar en el sandbox real): quedan cubiertos por `FeTransmisionesTest` (suite automatizada del batch). Sin SSH.

## Bloque 8 — Regresion
Aprobador y Contabilidad: Facturacion (agrupada y plana), detalle de factura (F-0016), Cobros, Notas de credito, Estado de cuenta, Configuracion Fiscal (Contabilidad recibe 403 en `fiscal-settings`, ya era exclusiva de Aprobador) cargan sin errores de consola/pageerror ni respuestas >=400 inesperadas. Vista previa de generar factura (pedido PED-2026-0043, sin guardar) funciona para ambos. PDF de factura y de NC: 200 `%PDF`. `fe-preview` factura: 200 (Apagado: `"sin envio"`; sandbox previo a emitir no medido). Lista plana/agrupada/NC de Contabilidad sin claves sensibles.

## Bloque 9 — Migraciones
Comprobado por comportamiento: `fe_detalle` (detalle devuelve la clave, F-0017 la tiene poblada) y `fe_transmisiones` (endpoints 200, 2 registros creados con todos los campos, incluidos `request_id` y `duracion_ms`).

## Veredicto
**LIMPIO — sin hallazgos CRITICOS ni MEDIOS bloqueantes.** Reservas de cobertura (no de defecto): (a) certificacion real de una NC y su visor en UI, (b) visor en factura anulada en UI, (c) polling/202/circuito abierto. (a) y (b) no se ejecutaron (la emision de una NC al sandbox fue denegada por el harness; no se forzo); (c) por diseno. El ticket SCRUM-987 sigue esperando a MSL: NO se transiciono Jira ni se crearon marcadores.

Observaciones bajas: OBS-1 (trace de excepcion en 404 de dev), OBS-2 (orden de claves jsonb), OBS-3 (historial vacio en F-0010..0013), OBS-4 (fecha siempre es-PA), OBS-5 (fe_detalle con datos tecnicos de MSL).

### Datos de prueba que quedaron en dev
- Clientes: master 7707 y subcliente 7711 con nombre XSS; contacto 68 (XSS). Proyectos/tarjetas/cotizaciones aprobadas: `PreQA987 Proyecto A` (card 350, COT-2026-0105), `PreQA987 XSS`, `PreQA987 Sandbox B`, `PreQA987 Sandbox C`, `PreQA987 XSS2` (cotizaciones 316-320, archivos dummy.pdf firmados en S3 dev).
- Pedidos Bodega 60-64 (PED-2026-0040..0044); el 63 quedo sin facturar.
- Facturas: F-0014 (id 23) y F-0015 (id 24) `no_aplica`; F-0016 (id 25) certificada en sandbox; F-0017 (id 26) rechazada 9026. Registros `fe_transmisiones` ids 1 y 2 (append-only, no se pueden borrar desde la app).
- NC-0001 quedo `rechazada` (motivo "PreQA987 verificacion visor tras decision"); antes estaba pendiente_aprobacion.
- Factura 20 (F-0011): `motivo_incobrable` con texto de prueba (propuesta rechazada, sin efecto).
- Numeros del sandbox de MSL consumidos: **16 (F-0016, certificada)**. El 17 fue rechazado (9026), no consumido.
- `pac_ambiente` original: `testing` (proveedor `msl`). Restaurado y verificado por API (`msl`/`testing`; solo cambio `updated_at`). Estuvo en `apagado` entre el Bloque 1 y el Bloque 2.
- Specs sin commitear: `e2e/_preqa_scrum987_fe_transmisiones.spec.ts`, `e2e/_preqa_scrum987_dark.spec.ts`, `e2e/_preqa_scrum987_regresion.spec.ts` (los `.spec` y `.tmp` no se agregaron a git).
