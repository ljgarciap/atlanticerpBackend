# Compras: logística y documentos de la orden — cómo funciona hoy

Parte de la Fase 11. Marcas: **[E]** verificado ejecutándolo (`e2e/flujos/compras-logistica.spec.ts`, 6 pasos), **[C]** leído del código.
Cuentas usadas: Líder de Compras, Líder de Operaciones, Vendedor, Líder de Bodega y Contabilidad.

## 1. Quién entra a «Ver órdenes» y a Logística [E]
- El listado y el detalle los abren **Compras** (`compras.read`), **Operaciones** (`compras.limited.view`) y el **vendedor** (`compras.logistica.view`). Es el mismo endpoint para las pantallas de Ver órdenes y de Logística.
- **Bodega** abre solo el detalle (panel «Por recibir», `bodega.read`), no el listado. **Contabilidad** no entra a ninguno.
- Un id inexistente responde 404 y uno no numérico también (no 500).

## 2. Qué ve cada rol [E]
- El vendedor **nunca ve costos**: `total_amount`, `payable_total`, costos de envío, `unit_cost` y `subtotal` de las líneas salen en `null`, igual que el saldo pendiente de pagos. Regla: tiene `compras.logistica.view` y ningún permiso «de verdad» de Compras/Bodega.
- Compras y Operaciones ven los montos. Bodega los ve en el detalle.
- Ordenar por monto no está disponible para quien no ve costos (revelaría cuánto cuesta cada orden) [C].

## 3. Datos de envío [E]
- `PATCH /compras/orders/{id}/shipping-info` (`compras.edit`) guarda `container_number` (máx. 100) y `carrier` (máx. 255). Vendedor, Operaciones, Bodega y Contabilidad reciben 403; el vendedor sí ve el dato guardado.
- No depende del estado de la orden: se puede editar en cualquier etapa [C].

## 4. Envíos en movimiento y filtros [E]
- `active_shipments=1` lista solo las órdenes de **Ordenado a Recibido**; «Por aprobar» y «Pendiente de liquidar» no son un envío todavía. También `status`, `provider_id`, `created_by`, `sales_project_id`, `search` (número, proveedor o proyecto) y `chip=critical` (3 o más días por aprobar, o llegada estimada vencida) [C].
- `GET /compras/orders/shipment-projects` busca proyectos con líneas de órdenes, sin la compuerta de cotización aprobada; mismos tres roles, Contabilidad 403.

## 5. Documentos de la orden [E]
- Categorías: `factura_comercial`, `declaracion_nacionalizacion`, `permiso_importacion`, `bl`, `confirmacion_proveedor`, `otro`; categoría inválida → 422. Extensiones permitidas y tope de 500 MB [C].
- Solo `compras.edit` sube; los demás 403. Listar: Compras, Operaciones, Bodega y el vendedor.
- **El vendedor solo ve BL y permiso de importación** (los que no traen precios); Operaciones y Compras ven todo.
- La **confirmación del proveedor** no se adjunta mientras la orden está «Por aprobar» (422); el resto de categorías sí.
- Al subir una confirmación se lanza sola la validación con IA contra la orden (límite por orden) [C, no ejercitada].

## Cambios de la Fase 24 (resueltos)
1. **Documentos**: se pueden quitar con baja lógica (`DELETE /compras/orders/{id}/documents/{docId}`, `compras.edit`, motivo ≥10 caracteres). El archivo se conserva y quedan quién y por qué; deja de contar en el checklist. La confirmación del proveedor no se quita si la orden ya tiene pagos. Reemplazar = subir uno nuevo y quitar el anterior.
2. **Datos de envío**: el contenedor sigue el formato ISO 6346 (4 letras + 7 números; se normaliza a mayúsculas sin espacios ni guiones; vacío es válido) y el transportista no puede ser solo números. El formato vive en `config/compras.php`. `GET /compras/orders/{id}/shipping-history` muestra quién, cuándo y el valor anterior (sale de la auditoría; sin montos).
3. **Costos**: Operaciones (`compras.limited.view`) ve la logística como el vendedor: sin montos ni costos y solo los documentos sin precios (BL y permiso de importación). Quien tiene `compras.read`, `bodega.read` o superadmin ve todo.

## Candidatos pendientes
- Sin ejercitar: la validación con IA de la confirmación (depende de un proveedor de IA) y el filtro por proyecto con líneas asignadas.
