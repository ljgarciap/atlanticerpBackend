# Flujo: notas de crédito y anulaciones (Admin & Contab)

Continuación de [venta a cobro](cadena-venta-a-cobro.md): qué pasa con el dinero cuando una factura ya cobrada se corrige. Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-10-28 contra el stack local, sobre la factura y el cobro del flujo anterior.

## Actores
- **Contabilidad** (permiso `admin_contab.records.manage`): registra notas de crédito y solicita anulaciones [E].
- **Aprobador primario** (`primary_approver_only`; en el demo, `gerencia3@test.com`): único que decide. Otra cuenta de Gerencia **no** lo es: recibe 403 («Esta pantalla es exclusiva del aprobador configurado») [E].

## 1. Notas de crédito
`POST admin-contab/notas-credito` (multipart, el comprobante es siempre obligatorio) [E][C].
| Campo | Regla |
|---|---|
| `tipo` | `descuento_comercial`, `devolucion_mercancia` o `anulacion_completa` [C] |
| `subtipo_anulacion` | solo anulación completa: `cancelado` o `correccion` [E] |
| `factura_origen_id`, `master_client_id`, `cliente`, `motivo` | obligatorios [E] |
| `monto` | ≥ 0,01; no puede superar lo acreditable restante de la factura (422) [E] |
| `resultado` | `aplicado_saldo`, `devuelto` o `saldo_favor`; obligatorio cuando hay excedente sobre el saldo pendiente (una factura ya cobrada siempre lo tiene) [E] |
| `cuenta_bancaria_salida_id` | obligatoria si `resultado = devuelto` [E] |
| `comprobante` | archivo jpg/png/pdf ≤ 10 MB; sin él, 422 [E] |
- El importe se descompone en subtotal e ITBMS (50,00 = 46,73 + 3,27) [E]. Folio `NC-nnnn` [E].
- **Aplicación inmediata o pendiente**: si el monto no supera el umbral (`fiscal_settings.primary_approval_threshold`, 5.000 por defecto) la nota queda `aplicada` al registrarse; probado con 10, 30, 50 y 400 [E]. **Toda anulación completa** queda `pendiente_aprobacion` sin importar el monto [E][C].
- Anulación completa: el `monto` enviado se **ignora** (el campo es obligatorio igual); se acredita lo que falta de la factura [E].
- Decisión: `PUT notas-credito/{id}/decision {approve, motivo_rechazo}`. Rechazar sin motivo → 422; decidir dos veces → 422 «ya no está pendiente» [E].
- Efecto en el dinero: una nota con `resultado = saldo_favor` genera saldo a favor del cliente (aparece en `saldo-favor` como «Descuento comercial» / «Anulación de factura») [E]; con `devuelto` sale dinero de la cuenta indicada y no suma saldo [E].

## 2. Anular un cobro
1. `POST admin-contab/payments/{id}/anular {motivo}` crea una **solicitud** `solicitada`; sin motivo, 422. El cobro sigue `confirmado` [E].
2. `PUT admin-contab/reversal-requests/{id}/decision {aprobar, motivo_rechazo}` solo el aprobador; rechazar exige motivo [E].
3. Al aprobar, la solicitud pasa a `ejecutada`, el cobro a `anulado` (con fecha y motivo) y **la factura se reabre** con su saldo completo [E].
- Desaplicar una aplicación no necesita aprobación; anular un cobro o devolver dinero al cliente sí [C].

## 3. Posición del cliente tras el ejemplo (cuadra)
Factura 518,38; pagos 518,38 + 50; notas: 50 + 400 (a saldo a favor), 30 (devuelta) y 38,38 (anulación); cobro de 518,38 anulado.
- Saldo a favor disponible **538,38** y factura abierta **518,38** → neto a favor **20** = lo realmente pagado (50) menos lo devuelto (30) [E].
- La factura reabre en bruto y el crédito sigue disponible: hay que **compensarlos** (`POST compensaciones`) para dejarlos en cero.

## Candidatos a cambio o mejora
1. **`monto` obligatorio pero ignorado** en la anulación completa: confunde en la captura. [E]
2. **El listado de saldo a favor no expone los identificadores de origen** (solo `referencia` «NC-0001»), pero compensar exige `source_type` y `source_id` por línea; no pude ejercitar la compensación sin otra fuente. [E]
3. **`credit_balance` de `open-invoices` y `saldo-favor` no coinciden** (488,38 frente a 538,38 en el ejemplo; antes, 0 frente a 272,85): cada pantalla suma orígenes distintos. [E]
4. **Tras anular un cobro, la factura aparece abierta por el total** aunque ya esté acreditada por notas: el neto es correcto pero la lectura bruta engaña; podría proponerse la compensación automática. [E]
5. **El umbral de aprobación (5.000) vive en Configuración fiscal con el nombre `primary_approval_threshold`** y lo comparten notas de crédito y compras: cambiarlo en un sitio afecta al otro. [C]
6. **Una sola cuenta aprueba todo**: si el aprobador falta no hay quien decida anulaciones ni notas; no hay suplente configurable. [E][C]
7. **Sin ejercitar**: nota de corrección con nueva factura (`correccion`), devolución de mercancía vinculada a Bodega, solicitud de devolución al cliente, incobrables, abonos. [C]
