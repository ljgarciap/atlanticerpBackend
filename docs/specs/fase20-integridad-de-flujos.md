# Fase 20 — Integridad de flujos (Servicios, Compras y Bodega)

**Estado**: BORRADOR, pendiente de aprobación de Luis. Origen: los candidatos 1–4 de la cobertura de flujos del 2026-11-03 (`docs/flujos/servicios-informe-inspeccion.md`, `compras-familias.md`, `bodega-asistentes-llegadas.md`, `transversales-administracion.md`). Nada de esto se implementa sin la aprobación de §5.

## 1. Objetivo
Cerrar cuatro huecos de integridad encontrados al ejecutar los flujos de punta a punta: un documento firmado que se puede modificar, una compra que se duplica, un conteo físico que no controla el inventario y una regla de aprobación que puede dejar pasar órdenes sin avisar.

## 2. Hallazgos (medidos sobre `master`, todos reproducidos con el e2e)
- **A. Firma del informe móvil.** `POST …/inspection-report/mobile` rechaza reemplazar una firma ya confirmada (409), pero `PUT …/inspection-report` y `POST …/upload` no revisan `firma_cliente_imagen`: el contenido ya firmado por el cliente se puede reescribir. El archivo adjunto también se pisa. [C]
- **B. Generar compra por familia.** `POST families/{id}/generate-purchase` (1) pide `max(1, reorden − existencias)`, o sea al menos una unidad aunque el producto no necesite compra; (2) no mira lo que ya viene en camino ni lo «por ingresar», así que repetirlo crea otra orden igual; (3) omite en silencio los productos sin proveedor y responde solo con los ids de orden. [E]
- **C. Conteo de Bodega vs. Compras.** Bodega reporta lo contado (`bodega_confirmed_quantity`) pero `confirmLine()` suma al inventario `line.quantity`, lo que Compras ingresó: con 6 contadas de 10 el stock subió 10. Además, tras reportar 6, los 4 restantes ya no se pueden reportar (la línea queda marcada). [E]
- **D. Reglas de aprobación de compras.** `PurchaseApprovalRules::decide()` devuelve «sin regla» cuando ninguna coincide y entonces la orden **no pide aprobación**. Un rango mal escrito (por ejemplo 0–999 y 5 000–∞) deja pasar las órdenes de 1 000 a 4 999 sin aviso; tampoco se avisa de reglas que se solapan. [E][C]

## 3. Diseño propuesto
- **A. Informe firmado = inmutable.** `save()` y `saveArchivoMode()` responden 409 «Este informe ya fue firmado por el cliente y no puede modificarse» cuando `firma_cliente_imagen` no es nulo, igual que la ruta móvil. Las fotos se siguen pudiendo agregar. Sin esquema nuevo.
- **B. Compra de familia con necesidad real.**
  - Necesidad = `reorden − (existencias + en camino + por ingresar)`; solo entran los productos con necesidad > 0, con proveedor y activos.
  - La respuesta añade `omitidos: [{product_id, name, motivo}]` con motivo `sin_proveedor`, `sin_necesidad` o `ya_en_camino`.
  - Si no queda ningún producto, 422 con el desglose en lugar de una lista vacía.
  - Repetir la acción no duplica: lo ya pedido cuenta como «en camino».
- **C. El conteo de Bodega manda sobre lo que entra.** Al confirmar Compras una línea que Bodega ya reportó con menos unidades, entran al inventario **las unidades contadas** y el resto queda como una línea nueva «Por ingresar» que Bodega puede reportar cuando llegue. Si Bodega no reportó nada, no cambia nada (Compras confirma como hoy). Migración: ninguna (se crea una línea hija con los campos existentes); el Kardex registra la diferencia en el texto de referencia.
- **D. Reglas que no dejan huecos.**
  - Al crear o editar una regla, la respuesta incluye `warnings` por solapes con otra regla activa de igual alcance.
  - `GET /admin/purchase-approval-rules/coverage` calcula, por tipo de envío y pagador, los rangos de monto sin regla; la pantalla los muestra como aviso.
  - Ajuste global nuevo `purchase_approval_no_rule` (0 = sin aprobación, el comportamiento actual; 1 = exige aprobación). Por defecto 0, para no cambiar lo que hoy funciona.

## 4. Criterios de aceptación
1. Un informe con firma confirmada responde 409 a `PUT` y a `POST …/upload`; las fotos se pueden agregar; el informe sin firma se edita igual que hoy.
2. «Generar compra» en una familia sin necesidad real responde 422 con el desglose; con necesidad crea las órdenes con cantidades correctas, lista los omitidos y, repetida, no crea una segunda orden.
3. Con 6 de 10 reportadas por Bodega, al confirmar Compras el stock sube 6, queda una línea «Por ingresar» de 4 reportable después, y el Kardex lo deja escrito. Sin reporte de Bodega, el stock sube igual que hoy.
4. Guardar una regla que se solapa devuelve el aviso; `coverage` señala el hueco del ejemplo; con `purchase_approval_no_rule = 1` una orden que no coincide con ninguna regla nace «Por aprobar».
5. Tests de API por cada punto (los e2e de flujos que hoy documentan el comportamiento viejo se actualizan), PHPStan 0, suites completas en verde.

## 5. Decisiones para Luis
1. **A.** ¿Informe firmado inmutable sin excepción (propuesta), o con «reabrir» del Líder y motivo, como en los tickets?
2. **B.** ¿Una compra repetida se bloquea por completo mientras haya algo en camino (propuesta), o se permite con una confirmación explícita?
3. **C.** ¿Entran las unidades contadas por Bodega (propuesta), o se mantiene lo ingresado por Compras y solo se avisa de la diferencia?
4. **D.** ¿El valor por defecto de «sin regla» queda en «no pide aprobación» (propuesta, conserva lo actual) o pasa a «pide aprobación» (más seguro, cambia el comportamiento)?
5. **Entrega**: 1 PR por ítem y por repo (A, B, C, D), como en la Fase 13; backend primero. ¿De acuerdo?

## 6. Riesgos
- **C** toca el Kardex y el inventario: es el ítem de mayor riesgo; va con pruebas de concurrencia (ya hubo un incidente de líneas confirmadas dos veces) y con revisión del Senior Reviewer.
- **B** cambia una respuesta de API (`omitidos`); el frontend debe mostrarla.
- **D** con `purchase_approval_no_rule = 1` podría frenar órdenes que hoy fluyen; por eso el defecto es 0.

## 7. No-alcance
Los demás candidatos de la cobertura (Kardex de materiales del informe, mapeo de asistentes, departamentos, perfil de empresa), rediseñar el flujo de recepción o cambiar quién aprueba.
