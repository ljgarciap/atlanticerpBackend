# Pre-QA SCRUM-1009 — Corrección de datos: reemplazo retenido hasta que la NC certifique (dev.atlanticerp.ai) — 2026-09-30

Backend 7a990ca0, frontend 9887cdd (ya desplegados a dev). Entorno: solo https://dev.atlanticerp.ai. Config fiscal leída, NO modificada: `pac_provider=msl`, `pac_ambiente=testing`, factura y NC habilitadas, umbral Aprobador 5000. Sin SSH, sin SQL, sin push/deploy, sin cambios en Jira.
Cuentas: Aprobador (mbekhar@), Contabilidad (conta@).

## Gasto de documentos en el sandbox de MSL (tope 4, gastados 3, 0 rechazos)
| # | Documento | Resultado | Número fiscal |
|---|---|---|---|
| 1 | F-0025 (invoice 34, pedido 65, 19.66, DIGIFACT) | certificada | ILU 0025 |
| 2 | NC-0014 (id 14, NC de anulación de la corrección ITBMS 7% -> 0%) | certificada, HTTP 200 | NC-ILU 0014 |
| 3 | F-0026 (invoice 35, reemplazo, 18.37 exento) | certificada, HTTP 200 | ILU 0026 |
Queda 1 documento sin usar (no se gastó porque no había forma de provocar el estado "NC rechazada" sin forzar un rechazo con datos inválidos, vetado).

## Resultado por criterio

### 1. D1 — corrección sobre factura con NC aplicada: OK en API, MEDIO en UI
- `POST /notas-credito/correccion/preview` sobre F-0024 (invoice 33, tiene NC-0013 aplicada/certificada), motivos `itbms` y `fecha`: 422 `La factura F-0024 ya tiene notas de crédito (NC-0013) — no se puede corregir. Resuélvelas primero, o acredita el saldo y emite una factura nueva.`
- `POST /notas-credito/correccion` (multipart con comprobante) sobre F-0024: mismo 422. Nada se anuló (F-0024 `es_anulada=false`, NC-0013 intacta, sin transmisiones nuevas).
- Correcciones previas sobre F-0025 ya anulada: 404 (no 422; ver BAJO-2), no duplica nada.
- Corrección sobre el reemplazo F-0026 (sin NC propia): el preview procede (correcto, punto "a confirmar" de la spec).
- **UI**: en el modal de Corrección de datos el usuario NO ve el mensaje claro. Al pulsar "Revisar y continuar" sobre F-0024 la pantalla de revisión muestra solo "No se pudo cargar la vista previa. Vuelve e inténtalo de nuevo." y el botón de confirmar deshabilitado (captura `atlanticerp-frontend/e2e/.tmp/s1009-d1-f0024-es.png`). Ver MEDIO-1.

### 2. Flujo feliz con corrección de ITBMS (F-0025 -> NC-0014 + F-0026): OK
- Corrección por API (Aprobador) 7% -> 0%, 18.37 < umbral: 201, NC-0014 `aplicada` de inmediato, `factura_nueva_numero=F-0026`.
- F-0025: `es_anulada=true`, `fe_estado=certificado` (su CUFE no se toca). F-0026 es la única vigente del pedido 65, `certificado`, CUFE propio FE01...260090129191621295.
- Payload enviado de la NC-0014 (visor, transmisión id 12): `items=[{precioUnitario 18.37, tasaITBMS "01" (7%, tasa original)}]`, `pagos=[{tipo 01, monto 19.66}]`, `referencia.cufe` = CUFE de F-0025, `referencia.fecha` = emisión de F-0025, `fechaEmision` = 12:06:14. Σ ítems (18.37 + 7% = 19.66) == Σ pagos (19.66) == total original de F-0025 (19.66). MSL/DGI aceptó (200, `estado CERTIFICADO`, serie NC-ILU, número 0014). Punto B de la spec: CUADRA, no hay hallazgo.
- Orden: transmisión NC-0014 a las 12:06:16 (id 12), F-0026 a las 12:06:17 (id 13). La NC certifica antes de que el reemplazo se envíe. Una sola transmisión de F-0026 (no hay doble certificación).
- Payload del reemplazo F-0026: ítem 18.37 con `tasaITBMS "00"`, `pagos` 18.37, cuadra.
- Nunca dos facturas vigentes: F-0025 anulada + NC certificada; F-0026 vigente.
- Visor "Ver transmisiones al PAC" (Aprobador): visible y con payload/respuesta; Contabilidad: `puede_ver_fe_transmisiones=false`, GET fe-transmisiones -> 403 en NC y en factura; `accion_fe` null para Contabilidad.
- Observación BAJA: el visor del pedido 65 sin parámetro muestra solo la transmisión de la factura vigente (F-0026); la de la anulada F-0025 se alcanza con `?invoice_id=34`. No es defecto de este ticket.

### 3. Estado retenido `en_espera_nc`, badge y aviso: PARCIAL (verificado por lectura + aviso real sobre NC-0012)
- NO pude provocar el estado real `en_espera_nc` (requiere una NC de corrección rechazada por la DGI sobre una original certificada; forzarlo con datos inválidos estaba vetado y cuesta un documento). Sin cubrir en vivo: badge "En espera de la nota de anulación" en el reemplazo, retención real, liberación por reintento/polling, la carrera. Revisados por lectura: `FiscalElectronicStatusBadge.tsx` (estilo sky propio, ícono reloj, sin emojis, variantes dark), i18n es/en `enEsperaNc`, y tests automáticos (Senior Review cita 4 tests nuevos de backend + vitest del badge).
- Aviso "La factura original figura anulada en AtlanticERP pero sigue vigente ante la DGI..." SÍ verificado en vivo sobre NC-0012 (corrección, `fe_estado=rechazado`, dato de SCRUM-958): visible para Aprobador (con botón "Reintentar certificación" y visor) y para Contabilidad (aviso visible, sin botón ni visor). NO aparece en NC-0014 (certificada) ni en NC-0013 (descuento normal). Ver BAJO-1 sobre NC-0012.
- Ojo: NO reintenté NC-0012 (podría gastar/alterar datos; ya se sabe que VALIDATE_B606_BALANCE la rechaza).

### 4. Regresión: OK
- NC normal (NC-0013 descuento, NC-0011) detalle idéntico: sin aviso, certificada, visor Aprobador.
- Listado de NC y detalle; detalle de factura F-0026 (Certificada, CUFE, Ver QR, visor, sin acciones inesperadas): sin errores de consola (solo los 422 esperados del preview de F-0024).
- Contabilidad no ve visor ni `fe_detalle` accionable (403 en endpoints del visor).
- FE apagada: no se cambió el ambiente (verificado `testing`); cubierto solo por tests automáticos.

### 5. Tema claro/oscuro y es/en: OK para el aviso (el badge en_espera_nc no se vio en vivo)
Aviso sobre NC-0012 capturado en es claro, en claro, es oscuro y en oscuro (`e2e/.tmp/s1009a-*.png`): legible, tono sky informativo, sin emojis, ícono SVG, textos es/en correctos. Modo oscuro forzado agregando la clase `dark` al `<html>`.

## Hallazgos
| Sev | Hallazgo |
|---|---|
| MEDIO-1 | D1 sin mensaje en la UI: el modal de Corrección muestra el genérico "No se pudo cargar la vista previa" en vez del 422 claro del backend (`RevisionPreviaCorreccionModal.tsx:115` usa siempre `previewError`, ignora `error.response.data.message`). Spec A: "el modal de Corrección de datos lo muestra antes de confirmar". Criterio 1 del ticket incumplido en la UI. Bloquea nada se anula, pero el usuario no sabe por qué. Fix chico: mostrar el `message` del 422. Además el selector de facturas del modal sigue ofreciendo F-0024 (con NC) — opcional filtrarla. |
| BAJO-1 | En NC-0012 (corrección legacy, rechazada, cuyo reemplazo F-0024 YA se certificó antes de este cambio) el aviso dice que el reemplazo "se emitirá automáticamente al certificarse", lo cual es falso para ese dato. Solo afecta datos previos a 1009 (no hay en prod con FE apagada). |
| BAJO-2 | Corrección sobre una factura ya anulada devuelve 404 con traza de depuración (dev, APP_DEBUG), no un 422 claro. Sin impacto en datos. |
| BAJO-3 | Preexistente (Batch 12): etiqueta cruda `notasCredito.detalle.facturaNueva` en el detalle de la NC de corrección (falta la clave i18n es/en; el campo es justo el de la factura de reemplazo). |

## Sin cubrir
- Retención real (`en_espera_nc`), badge en vivo, liberación por reintento/polling y carrera CAS: solo tests automáticos + lectura.
- Aprobación diferida (`decide()`, sobre umbral con reemplazo que certifica al aprobar): no ejecutado (costaría 2 documentos más); solo apagado en SCRUM-958.
- FE apagada y combinación NC deshabilitada/factura habilitada: solo tests automáticos.
- Original sin CUFE (B1 del Senior Review): no ejercitado en vivo.

## Datos que dejé en dev
- Pedido 65 (PED-2026-0045): F-0025 (anulada), NC-0014 (certificada), F-0026 (certificada, vigente).
- Sin otros cambios. Specs sin commitear: `atlanticerp-frontend/e2e/_preqa_scrum1009_ui.spec.ts`, `_preqa_scrum1009_det.spec.ts`, `_preqa_scrum1009_aviso.spec.ts`, `atlanticerp-frontend/_preqa_dev.config.ts`; capturas en `atlanticerp-frontend/e2e/.tmp/s1009*`.

---
# RE-VERIFICACIÓN (frontend 8fd0655, backend 7a990ca0) — 0 documentos nuevos gastados (total sigue en 3/4)

| # | Verificación (sin gasto) | Resultado |
|---|---|---|
| 1 | D1 en la UI sobre F-0024 (Aprobador), es/en x claro/oscuro | OK en las 4 variantes: el modal muestra "La factura F-0024 ya tiene notas de crédito (NC-0013) — no se puede corregir. Resuélvelas primero, o acredita el saldo y emite una factura nueva." y ya no el genérico; confirmar deshabilitado. Capturas `atlanticerp-frontend/e2e/.tmp/s1009r-d1-{es,en}-{light,dark}.png`. Observación BAJA: el mensaje sale en español también con la UI en inglés (texto fijo del backend). |
| 1b | 422 de submit | Submit no es alcanzable en la UI para F-0024 (el preview ya lo bloquea); por código `onError` del modal de registro ahora usa `response.data.message`; por API el submit sigue dando el mismo 422 (probado antes, backend sin cambios). Sin verificar en navegador. |
| 2 | Detalle NC-0014 y NC-0012 | OK: etiqueta "Factura de reemplazo" visible, sin clave cruda (`notasCredito.detalle…`) en NC-0014, NC-0012 (Aprobador y Contabilidad). Aviso "sigue vigente ante la DGI": NC-0012 Aprobador sí (con botón Reintentar y visor), NC-0012 Contabilidad sí (sin botón ni visor); NC-0014/NC-0013/NC-0011 no lo muestran. |
| 3 | Regresión | NC-0013, NC-0011, NC-0014 (Aprobador: visor sí, sin botón; Contabilidad: sin visor, sin botón), F-0026 (Certificada) sin errores de consola. F-0025 es anulada y no aparece en la vista plana de facturación (comportamiento esperado; verificada por API en la pasada anterior). |
| 4 | Corregir factura ya anulada (F-0025) | 404 "No query results for model [AdminContInvoice] 34". Con APP_DEBUG=false Laravel devuelve un 404 genérico ("Not Found") sin nombre de clase ni traza; aceptable: no se modifica nada, la factura anulada no es corregible, y el modal de la UI solo ofrece facturas vigentes. Solo se puede llegar por API directa. En dev (APP_DEBUG=true) filtra clase/traza, igual que el resto de 404 preexistentes. Un 422 con mensaje sería más amable, no bloqueante. |

## Veredicto final: LIMPIO con reservas (sin hallazgos CRÍTICO/MEDIO abiertos)
MEDIO-1 y BAJO-3 cerrados. Abiertos BAJOS no bloqueantes: BAJO-1 (aviso sobre NC-0012 legacy), BAJO-2 (404 por API), mensaje D1 siempre en español.

## Sigue SIN cubrir en vivo (riesgo aceptado, decisión de Luis / solo tests automáticos y lectura)
- `en_espera_nc`: badge en el reemplazo, retención real, liberación por reintento/polling y carrera CAS (requiere NC de corrección rechazada por la DGI sobre original certificada).
- `decide()` (aprobación diferida sobre umbral con certificación de NC + reemplazo).
- FE apagada, NC deshabilitada con factura habilitada, y original sin CUFE.
- Submit real del 422 D1 desde el navegador.
