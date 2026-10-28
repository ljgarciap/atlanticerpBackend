# Flujo: venta a cobro

De un cliente nuevo a una factura cobrada. Cruza cuatro módulos. Marcas: **[E]** ejecutado, **[C]** leído del código, **[M]** de un manual heredado (ver `README.md`). Verificado el 2026-10-28 contra el stack local con cuentas demo por rol.

## Resumen en una línea
Cliente → subcliente → proyecto/tarjeta de pipeline → cotización (partes y líneas, validar, generar, confirmar) → etapas del pipeline con compuertas → «Pasar a pedido» (solo Gerencia) → pedido de Bodega (asignado → picking → packing → por despachar → despachado → entregado) → factura interna (completa o por entrega) → cobro aplicado y confirmado → saldo a favor si sobró dinero.

## 1. Clientes y proyecto (Ventas & Diseño)
| Paso | Endpoint | Rol que lo hizo | Resultado |
|---|---|---|---|
| Cliente maestro | `POST ventas-diseno/master-clients` `{name}` | Vendedor | 201 [E] |
| Subcliente | `POST ventas-diseno/sub-clients` `{master_client_id, business_name, tax_id, tax_id_dv, receptor_tipo}` | Vendedor | 201; sin `tax_id` responde 422 «El RUC o cédula es obligatorio» [E] |
| Contacto del subcliente | `POST sub-clients/{id}/contacts` `{name, role, email|phone}` | Vendedor | 201; `role` solo admite `client`, `architect`, `other`; exige correo o teléfono [E][C] |
| Tarjeta de pipeline | `POST ventas-diseno/pipeline` `{type: lead|design, name, sub_client_id}` | Vendedor | 201; `design` exige subcliente; el proyecto de venta nace con la tarjeta [E][C] |

## 2. Cotización
1. `POST ventas-diseno/quotes {from_pipeline_card_id}` crea el borrador heredando cliente, subcliente y proyecto [E].
2. Partes: `POST quotes/{id}/parts {name}`. Líneas: `POST …/parts/{part}/items` con `catalog_product_id` + `quantity` (precio y costo salen del catálogo) o una línea libre `{reference, description, quantity, unit_price, cost}` [E].
3. Contactos de la cotización: se eligen entre los del subcliente (`POST quotes/{id}/contacts {sub_client_contact_id}`) [E].
4. `GET quotes/{id}/validate` lista lo que falta: en una cotización vacía faltaban «Descripción, Contacto, Fecha de entrega, Ítems» [E].
5. `PUT quotes/{id}` fija descripción, `delivery_type` (`single|partial|tbd`), `delivery_dates`, `price_type` [E].
6. `POST quotes/{id}/generate` asigna folio (`COT-2026-0001`) y fecha de generación; sin los datos obligatorios responde 422 con la lista de faltantes [E].
7. `POST quotes/{id}/confirm` marca `confirmed_at`, deja `document_status = sent`, **mueve la tarjeta a «Cotización»**, copia el monto (491,13) y los contactos a la tarjeta [E].
8. `GET quotes/{id}/pdf` entrega un PDF (~0,9 MB) [E].
- **Cálculo**: 3 × 100 + 2 × 120 = 540; con tipo de precio «proyecto» (−15 %) el subtotal es 459,00; ITBMS 7 % = 32,13; total 491,13 [E]. El descuento, los divisores y el margen mínimo salen de `pricing_settings` (configurable) [C].
- **Tipos de precio**: `public`, `project`, `special`, `partner`, `premium`; una línea puede tener el suyo [M].
- **Versiones**: `duplicate` crea una versión nueva solo desde una cotización confirmada con la tarjeta en Lead, Diseño o Cotización [M].

## 3. Pipeline y compuertas
Las etapas son un catálogo por empresa (Fase 7c); las de sistema son `lead → design → quote → proposal → approved` (+ `lost`) [C].
| Transición probada | Resultado |
|---|---|
| quote → approved (saltar) | 422 «No se puede saltear etapas» [E] |
| quote → proposal sin m² | 422 «Falta la superficie trabajada (m²)» [E] |
| quote → proposal con `PUT worked_area_m2` | 200 [E] |
| proposal → approved sin archivos | 422 «Falta el archivo de la cotización firmada» [E] |
| proposal → approved con `signed_quote`, `approval_proof` y fecha de entrega | 200 (lo ejecutó el Vendedor) [E] |
- Archivos: `POST pipeline/{id}/files` multipart `{type: design|proposal|signed_quote|approval_proof|photo, file}`; descarga con URL firmada (200 con firma, 403 sin firma o alterada) [E].
- Diseño → Cotización solo se logra creando la cotización real, no con el endpoint genérico de etapa [M].
- **Pasar a pedido**: `POST pipeline/{id}/pass-to-order`. El Vendedor recibe 403; Gerencia lo ejecuta (200) y una segunda ejecución responde 409 «ya fue pasado a pedido» [E].

## 4. Compras y existencias (alimenta el picking)
Los productos del demo no tienen stock, y el picking exige saldo:
1. `POST compras/orders {provider_id, modality, lines[]}` → estado `pendiente_liquidar` [E].
2. `PATCH compras/orders/{id}/advance` repetido: `pendiente_liquidar → ordenado → en_transito → en_aduana → en_transito_local`; el siguiente (`recibido`) responde 422 «falta registrar el Ingreso de Mercancía» [E].
3. `POST compras/goods-receipts {purchase_order_id, lines[{catalog_product_id, quantity, unit_cost, warehouse_id}]}` crea el ingreso; **el stock no sube todavía** (0) [E].
4. `POST compras/goods-receipts/lines/{id}/confirm` sube el stock (+10 en Bodega Central) [E].
5. `advance` → `recibido` [E].
- Una OC por debajo del umbral del aprobador primario no se bloquea (`requires_primary_approval: false`) [E].

## 5. Pedido de Bodega
El pase a pedido crea la tarjeta `PED-2026-0001` en etapa **asignado**, ya con la asistente de bodega mapeada, la fecha comprometida del pipeline y los productos de la cotización [E].
| Etapa | Acción (endpoint) | Quién | Notas |
|---|---|---|---|
| asignado → picking_pendiente | `assign-picker {picker_id}` | Asistente de Bodega | un segundo intento responde 422 «no está en la etapa Asignado» [E] |
| → en_picking | `start-picking` | Jefe, asistentes o picker asignado | el transporte recibe 403 [E]; un auxiliar **no asignado** pudo iniciarlo (ver candidato 7) |
| hoja | `GET/PATCH picking-sheet` `{items[{order_item_id, qty_picked, allocations[{warehouse_id, qty_picked}]}]}` | Picker | con saldo 0 responde 422 «No hay saldo suficiente en «Bodega Central»…» [E] |
| → packing | `complete-picking {items[]}` | Picker | lo no encontrado abre una **tarjeta de Revisión de Inventario** [E] |
| revisión | `resolve-inventory-review {decisions[{order_item_id, found}]}` sobre la tarjeta de revisión | Asistente | «no encontrado» la convierte en tarjeta **faltante** (etapa asignado) que espera existencias [E] |
| packing → por_despachar | `register-delivery {items[{order_item_id, qty_delivered, motivo}]}` | Asistente | registra lo que sale y genera la guía [E][C] |
| repartidor | `assign-courier {courier_id}` | **Solo el Jefe de Bodega** (la asistente recibe 403) | [E] |
| → despachado | `dispatch` | Asistente | [E] |
| → entregado | `register-signed-guide` multipart `{delivered_by_courier_id, received_by_name, file}` | Transporte | [E] |
Etapas: `asignado → picking_pendiente → en_picking → packing → por_despachar → despachado → entregado` [E]. Desde `por_despachar` el pedido ya se puede facturar [C].

## 6. Factura interna
Sin proveedor fiscal (default `none`, Fase 6b) se emite un **documento interno**: no se transmite y `receptor_validado_at` queda vacío [E].
- `POST admin-contab/invoices/preview {order_ids[], modo}` y `POST admin-contab/invoices {order_ids[], modo}`. Dos modos: `completo` (por defecto) y `entrega` [E].
- **`completo`** factura toda la cotización (491,13, incluidos 2 artículos que no se entregaron) y **cierra el pedido** con restante 0 [E]. Es elección del usuario según el ADR de facturación parcial o completa [C].
- **`entrega`** factura solo lo entregado (3 × 85 + ITBMS = **272,85**), tipo `parcial`, y deja restante 218,28 [E].
- Numeración: `F-0002` (contador `invoice_folio_counters`) [E].
- Listados: `pending` exige `sales_project_id`; `summary` informa pendientes de entrega y de facturar y el facturado del mes [E].

## 7. Cobro
- Requiere cuenta bancaria (`POST admin-contab/bank-accounts {banco, ultimos_4_digitos}`) y la bandera `cobros_v2_activo` encendida [E].
- `POST admin-contab/payments/cobro {master_client_id, monto_recibido, metodo_pago, bank_account_id, aplicaciones[{invoice_id, monto}]}` crea un recibo `REC-nnn` en `esperando_confirmacion` [E].
- Sin `aplicaciones`, el recibo queda con `saldo_sin_aplicar` = monto. Con ellas, `total_facturas` = monto aplicado [E].
- `PUT payments/{id}/confirm` lo pasa a `confirmado` (lo hizo Contabilidad); las facturas abiertas del cliente pasaron a `[]` [E].
- Un recibo sin aplicar, una vez confirmado, aparece como **saldo a favor** (272,85, origen «Cobro a cuenta») en `GET admin-contab/saldo-favor` [E].

## Candidatos a cambio o mejora
1. **Dos campos de estado en la cotización**: `status` sigue en `draft` y `document_status` pasa a `sent` al confirmar; conviven con significados distintos. [E]
2. **Roles de contacto fijos** (`client`, `architect`, `other`) en validación: es vocabulario que podría ser un catálogo por empresa (como los de la Fase 7). [C]
3. **Facturar «completo» cobra artículos no entregados sin ninguna advertencia** (`advertencias: []`), aunque exista una tarjeta faltante abierta. Es una elección permitida por diseño, pero el aviso falta. [E]
4. **El contador de folios no se reconcilia con las facturas existentes**: una factura insertada fuera del servicio hace que el siguiente alta falle con 500 (violación de unicidad). Relevante para importaciones y restauraciones. [E]
5. **`cobros_v2_activo` nace apagado**: es una migración gradual heredada. En Atlantic (sin historial) el concepto nuevo debería ser el único y la bandera, deuda a retirar (como las banderas de la Fase 5). [E]
6. **Dependencias de arranque sin guía**: sin cuenta bancaria no se cobra; sin ajustes de archivos no se aprueba un proyecto. Falta una lista de verificación de primera puesta en marcha en la aplicación. [E]
7. **Quién puede iniciar el picking**: el mensaje habla del «Picker asignado», pero un auxiliar de bodega no asignado pudo iniciarlo. Por confirmar con un caso controlado. [E, por confirmar]
8. **Saldo a favor en dos lugares**: `open-invoices` informa `credit_balance: 0` mientras `saldo-favor` informa 272,85 para el mismo cliente. [E]
9. **Cifra «disponible» de la hoja de picking**: muestra el disponible *para el pedido* (3) y no el saldo libre (7) con 10 en bodega y 3 reservadas; la etiqueta confunde. [E]
10. **Manual heredado de Ventas & Diseño envejecido**: describe las etapas del pipeline como fijas (hoy son un catálogo), nombra al aprobador por su nombre propio y el repositorio conserva un manual del módulo Soporte, ya eliminado. [C]
11. **Documentación con nombres de personas reales**: los documentos de especificación, revisiones y ADR conservan cientos de menciones de nombres de personas reales (el contrato de neutralidad solo cubre código, tests y mocks). [C]
12. **Vendedor y Gerencia en el pase a pedido**: es una decisión de producto que solo Gerencia pueda pasar a pedido; el vendedor no tiene forma de pedir que se pase. [E]
