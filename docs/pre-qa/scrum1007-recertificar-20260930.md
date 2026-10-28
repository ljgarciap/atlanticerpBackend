# Pre-QA SCRUM-1007 — Reintentar certificación / Actualizar estado (FE) — dev.atlanticerp.ai — 2026-09-30

Backend 5cd6cd69, frontend 1abbf79. Entorno: solo https://dev.atlanticerp.ai. Configuración Fiscal leída (no modificada): `pac_provider=msl`, `pac_ambiente=testing`, factura y NC habilitadas.
Cuentas: Aprobador (gerencia3@test.com), Contabilidad (contabilidad@test.com). Documentos: NC-0009 (id 9), NC-0012 (id 12), NC-0011 (11), NC-0013 (13), NC-0008 (8, no_aplica); F-0024 (invoice 33) y F-0016 (invoice 25), ambas del pedido 62.

## Paso 1 — permisos y bloqueos (sin costo)

| Escenario de ruptura | Resultado |
|---|---|
| `accion_fe` como Aprobador: NC-0009 / NC-0012 | `reintentar` (OK) |
| `accion_fe` Aprobador: NC-0011, NC-0013 certificadas, NC-0008 no_aplica, F-0024, F-0016 | null (OK) |
| `accion_fe` como Contabilidad en todas | null; `puede_ver_fe_transmisiones=false` (OK) |
| POST sin token | 401 (OK) |
| POST como Contabilidad (NC y factura) | 403 "exclusiva de Aprobador" (OK) |
| reintentar NC-0011, NC-0013, F-0024 | 409 `estado_no_reintentable` (OK), sin llamar a MSL |
| actualizar-estado sobre NC-0009, NC-0012, F-0024 (rechazadas/certificada) | 409 `estado_no_actualizable` (OK) |
| id inexistente NC / pedido / factura inexistente (`invoice_id=999999`) | 404 (OK) |
| `invoice_id` de otra familia | 404 en detalle (OK) |
| id no numérico | 404 de ruta (OK) |

Observación BAJA (preexistente, dev con debug): el 404 de `/invoices/999999/fe-reintentar` devuelve `No query results for model [App\Modules\Bodega\Models\Order] 999999`, y el detalle de factura ajena devuelve `exception`/`file`. Filtra nombres de clase; en dev por APP_DEBUG, no evaluado en test/prod.

## Incidente de entorno (resuelto)
Tras ~25 requests 4xx seguidos desde 181.55.21.154, dev dejó de responder (patrón CrowdSec, test respondía). Coordinador lo levantó; la segunda pasada respetó espaciado (max 6 4xx/60 s salvo la prueba del 429, que pidió 7 POST).

## Paso 2 — NC-0012 por la UI (sin gastar número)
- Botón "Reintentar certificación" visible para Aprobador (Actualizar estado: no). Confirmación explícita (grupo "Confirma la acción fiscal", texto de reenvío, Confirmar/Cancelar). Al confirmar: "Enviando…" visible. Resultado: `La DGI rechazó el documento; revisa el motivo.` (role=status, texto plano).
- Respuesta `POST /notas-credito/12/fe-reintentar` = 200 `{accion:reenviado, fe_estado:rechazado}` (no 4xx/5xx). `fe_detalle` actualizado (9026 VALIDATE_B606_BALANCE, "monto 19.66 excede el saldo disponible 14.66", fecha del detalle 07:17:58, antes 07:17:05). Estado sigue `rechazado`, botón sigue ofrecido.
- Visor / `fe-transmisiones`: nuevos registros `origen=reintento_manual`, `operacion=certificar`, HTTP 422, ambiente pruebas (ids 8 y siguiente; el original id 5 `certificacion`). Se envió 2 veces (una en el primer intento del script, que cortó por timeout del test, y otra en EN+oscuro): ambas rechazadas, ningún número fiscal gastado.

## Paso 3 — NC-0009: EL documento autorizado
- Reintento por UI a las 07:18:18 (hora Panamá). `POST /notas-credito/9/fe-reintentar` = 200 `{accion:reenviado, fe_estado:certificado, mensaje:"Documento certificado ante la DGI.", codigo:null}`.
- CUFE `FE0420000155704849-2-2021-3200022026093000000000090090128653626899` (prefijo FE04 OK), QR de dgi-fep-test.mef.gob.pa; `fe_detalle` null; `accion_fe` null (Aprobador) tras certificar. El mensaje "Documento certificado ante la DGI." quedó visible aunque el botón desapareció (componente montado). Contabilidad ve `certificado`, sin acción.
- Transmisión guardada id 10: `origen=reintento_manual`, HTTP 200, `numero="0009"` (cadena de dígitos, mismo formato que el envío original), serie `NC-ILU`, `referencia.cufe` = CUFE de F-0016 (FE01…3271), `referencia.fecha` 2026-09-29T21:08:51-05:00.
- DATO CLAVE — `fechaEmision` atrasada: la transmisión trae `fechaEmision = 2026-09-29T22:56:49-05:00` (created_at original de NC-0009, SCRUM-1008 OK: posterior a `referencia.fecha`); el reintento real fue 2026-09-30T07:18:18-05:00. Atraso = 8 h 21 min 29 s (~8,4 h, cruzando de día). **La DGI/MSL (sandbox) ACEPTÓ el documento con esa fecha atrasada: HTTP 200, certificado, sin rechazo por fecha.** Comparación: el primer intento (fecha = 2026-09-29T00:00:00, sin hora) fue el que se rechazó por 9026 "Verifique la fecha referenciada" (bug SCRUM-1008). Solo se probó un atraso de ~8 h en el sandbox (`pruebas`); no hay evidencia sobre atrasos de días.

## Paso 4 — UI y regresión
- Contabilidad: NC-0012 y NC-0009 sin ningún botón de acción y sin visor de transmisiones (badge Rechazada/Certificada visible).
- EN (toggle de la app) + modo oscuro: botón "Retry certification", confirmación "Confirm the fiscal action / This document will be sent again to the DGI through MSL…", Confirm/Cancel; cancelar cierra sin enviar y el botón vuelve. Visor "View PAC transmissions" funciona (muestra Rejected · Certification · HTTP 422). Cero errores de consola. El motivo del fe_detalle sigue en español (texto de un tercero, esperado).
- F-0024 (EN): Certified, CUFE, Ver QR, visor; sin botón de acción. NC-0009 certificada en la lista/detalle con CUFE y sin botón.
- No se ejerció la UI de factura con un documento rechazado (dev no tiene factura rechazada) ni "Actualizar estado" (sin pendiente en dev): cubiertos por FeReintentoTest / vitest.

## Paso 1b — límite 429 (al final)
7 POST de Contabilidad espaciados 2 s a `/notas-credito/11/fe-reintentar`: 403 x6 y **429 "Too Many Attempts." en la 7.ª** (el cupo cuenta también los 403). Aprobador inmediatamente después: 409 `estado_no_reintentable` (NO 429): cupo por usuario confirmado.

## Hallazgos
- BAJO (preexistente, dev con debug): 404 filtra nombre de clase del modelo y, en detalle de factura ajena, `exception`/`file`.
- BAJO (S10/S11 del review, ya anotado): el 429 llega con "Too Many Attempts." en inglés y los mensajes de 200/409 del backend están fijos en español; el 429 en la UI no se ejerció (no se disparó desde el botón). No bloquea.
- Sin hallazgos CRÍTICO ni MEDIO.

## Veredicto: LIMPIO (sin hallazgos bloqueantes)
Cobertura: permisos/401/403/409/404, 429 por usuario, NC-0012 (rechazo persistente con registro `reintento_manual`), NC-0009 certificada, EN, oscuro, Contabilidad, regresión de detalle NC/factura y visor.
NO cubierto: UI de factura rechazada; "Actualizar estado" en vivo; atrasos de `fechaEmision` mayores a ~8 h; comportamiento en producción real de la DGI (solo sandbox).

## Sandbox y datos
- Documentos certificados nuevos en el sandbox de MSL: **1** = NC-0009, número fiscal NC-ILU/0009 (CUFE arriba). Envíos reales totales: 3 (NC-0012 x2 rechazados sin gastar número, NC-0009 x1 certificado).
- Datos que quedaron: NC-0009 pasó de rechazada a certificada (estado final deseado); NC-0012 sigue rechazada (con 2 transmisiones `reintento_manual` nuevas). Spec exploratorio sin commitear: atlanticerp-frontend/e2e/_preqa_scrum1007.spec.ts (capturas en el scratchpad de la sesión).
- `pac_ambiente` no se modificó (`testing`). Nota: un HEAD accidental a test.atlanticerp.ai/up en la pasada anterior (sin datos).
