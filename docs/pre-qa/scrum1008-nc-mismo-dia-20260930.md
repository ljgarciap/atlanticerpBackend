# Pre-QA SCRUM-1008 — fechaEmision de la NC con hora real (dev.atlanticerp.ai, backend fbbf3962)

**Fecha:** 2026-09-30 · **Entorno:** https://dev.atlanticerp.ai (solo). Sin SSH, sin tocar test/prod, sin editar codigo, sin git, sin marcadores ni transicion.
**Config fiscal anotada (Mark):** `pac_provider=msl`, `pac_ambiente=testing`, `primary_approval_threshold=5000`. No se modifico (verificado al final: `msl`/`testing`). Marly prueba SCRUM-987 en este dev.

## Fixture
Factura F-0024 (invoice 33, pedido 62, cliente master 7706 DIGIFACT): creada 2026-09-30 00:01:23 (Panama), `fe_estado=certificado`, CUFE `FE0120...612369`, no anulada, saldo 19.66. Es la factura de reemplazo de la correccion de SCRUM-958.

## Criterio 1 — NC del mismo dia certifica en sandbox: OK
`POST /notas-credito` (Mark, descuento_comercial, monto 1.00, comprobante PNG) a las 01:11:20 -> 201 NC-0013, `estado=aplicada`, `resultado=aplicado_saldo`, subtotal 0.93 + ITBMS 0.07.
- `fe_estado=certificado`, CUFE propio `FE0420000155704849-2-2021-3200022026093000000000130090124847654016` (prefijo FE04).
- Transmision id 7: `/v1/notas-credito`, serie NC-ILU, `numero_fiscal "0013"` (numerico), HTTP 200, 2399 ms, `resultado=certificado`.
- `request_payload.fechaEmision = 2026-09-30T01:11:21-05:00` (hora real, NO T00:00:00); `request_payload.referencia.fecha = 2026-09-30T00:01:23-05:00`. fechaEmision > referencia (+70 min). Con el codigo anterior habria salido `2026-09-30T00:00:00`, anterior a la factura (00:01:23) y con 9026: el escenario reproduce exactamente el bug.
- `fe-preview` de NC-0013: mismo `fechaEmision 01:11:21`, mismo `referencia.fecha` (consistente con lo transmitido).
- `created_at` de la NC = 01:11:21 (= fechaEmision); `fecha` contable = 2026-09-30.

## Sin costo
- `fe-preview` de NC-0011 (`fechaEmision 2026-09-30T00:00:48-05:00`) y NC-0012 (`00:01:23-05:00`), NC anteriores de hoy: ya no muestran T00:00:00; coherente con el `created_at`.
- Regresion Notas de Credito: lista (13 NC, 200), detalle NC-0013 (200, campos completos, `fecha` 2026-09-30), PDF (200 application/pdf).
- Visor: `puede_ver_fe_transmisiones=true` y `GET fe-transmisiones` 200 para Mark; Felix (conta@): `puede_ver_fe_transmisiones=false`, `GET fe-transmisiones` 403, lista de NC 200.
- Sin API key ni xmlFirmado en request/response de la transmision.

## Hallazgos
Ninguno bloqueante.
- INFO: el escenario probado tiene la NC 70 min despues de la factura; el caso del piso `+1 s` (mismo segundo) y el de aprobacion diferida (NC pendiente aprobada dias despues) NO se ejercitaron en sandbox (tope de 1 documento). Los cubren los tests automatizados; el segundo sigue como seguimiento fuera del ticket (consulta a MSL sobre tolerancia de atraso, `fe_emision_at`/SCRUM-1007), segun el re-review.

## Contador sandbox MSL
1 documento gastado (tope 1): NC-0013, numero fiscal NC-ILU 0013, CERTIFICADA. Sin reintentos.

## Datos que quedaron
NC-0013 (1.00, aplicada, descuento comercial sobre F-0024, saldo F-0024 = 18.66), comprobante PNG de 1 px adjunto. `pac_ambiente` sin cambios.

## Veredicto
LIMPIO para el criterio 1 (criterios 2 y 3 son de tests/Senior Review). No transicione ni cree marcadores: la decision es de Luis.
