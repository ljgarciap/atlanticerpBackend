# Fase 24 — Logística de Compras: documentos, datos de envío y costos (BORRADOR)

Estado: **borrador, pendiente de aprobación de Luis**. No se implementa nada hasta que apruebe las decisiones de §5.
Origen: candidatos 1–3 de `docs/flujos/compras-logistica.md` (cobertura de la Fase 11).

## 1. Hallazgos verificados en el código
- `POST /compras/orders/{id}/documents` existe; **no hay ruta de baja ni de reemplazo** (`routes/compras.php`, grupo `{orderId}/documents`). La migración original lo dejó así a propósito («sin versionado»).
- `PATCH /compras/orders/{id}/shipping-info` (`compras.edit`) valida solo `container_number` ≤100 y `carrier` ≤255; sin formato.
- `PurchaseOrder` y `PurchaseOrderDocument` ya usan `LogsAuditTrail`: **el historial de quién cambió qué existe en auditoría**, pero no se muestra en la pantalla.
- `OrderMoneyVisibility::hiddenFor` oculta costos solo al vendedor (tiene `compras.logistica.view` y ninguno de `superadmin.all`, `compras.read`, `compras.limited.view`, `bodega.read`). Operaciones tiene `compras.limited.view`, por eso ve montos.

## 2. Alcance
**24a — Documentos**: permitir reemplazar y dar de baja un documento del checklist.
**24b — Datos de envío**: validar formato y mostrar el historial.
**24c — Costos para Operaciones**: según decisión.

## 3. Diseño propuesto
**24a**
- `DELETE /compras/orders/{orderId}/documents/{documentId}` (`compras.edit`): baja lógica (`deleted_at`, `deleted_by`, motivo ≥10 caracteres), el archivo se conserva en el almacenamiento; el checklist deja de contarlo.
- Reemplazar = subir uno nuevo de la misma categoría (el `POST` actual) y dar de baja el anterior; no se crea ruta aparte.
- Bloqueo: no se permite dar de baja la confirmación del proveedor si la orden ya salió de `por_aprobar` hacia pagos (evita borrar la evidencia de una orden ya pagada).
- Auditoría ya cubierta por `LogsAuditTrail`.

**24b**
- Validación: `container_number` formato ISO 6346 (4 letras + 7 dígitos, `^[A-Z]{4}\d{7}$`, normalizado a mayúsculas, sin espacios/guiones); `carrier` ≥2 caracteres, sin solo números. El formato vive en `config/fiscal.php`-style de configuración (no hardcodeado en el controlador) para poder relajarlo por empresa.
- `GET /compras/orders/{id}/shipping-history` (`compras.read`, `compras.limited.view`, `compras.logistica.view`): lista cambios de `container_number`/`carrier` desde la auditoría (quién, cuándo, antes/después).
- Frontend: bloque «Historial» en el panel de logística.

**24c**
- Según la decisión D3.

## 4. Criterios de aceptación
1. Un documento dado de baja no aparece en el checklist ni cuenta para «completo»; su archivo sigue en el almacenamiento y la baja queda auditada con motivo.
2. Un contenedor con formato inválido responde 422; uno válido se guarda en mayúsculas.
3. El historial de envío muestra cada cambio con usuario y fecha; el vendedor ve el historial sin costos (no contiene montos).
4. Sin permiso `compras.edit` no se da de baja ni se cambia el envío (403).
5. Tests de regresión + paso nuevo en `e2e/flujos/compras-logistica.spec.ts`.

## 5. Decisiones abiertas (Luis)
- **D1 — Baja de documentos**: ¿baja lógica con motivo (recomendada) o borrado físico? ¿Quién puede: solo `compras.edit` (recomendado) o además el aprobador primario?
- **D2 — Formato del contenedor**: ¿ISO 6346 estricto (recomendado) o aviso sin bloqueo, porque hay envíos aéreos/courier sin contenedor?
- **D3 — Operaciones y costos**: (a) se queda como hoy (ve costos); (b) se oculta como al vendedor — recomendada si su pantalla es solo de logística; (c) lo decide un ajuste por rol.
- **D4 — Entrega**: un PR por sub-fase (24a, 24b, 24c) en cada repo.

## 6. Riesgos
- 24c(b) cambia lo que ve Operaciones: revisar que no pierda datos que usa para su trabajo (cantidades, fechas) — solo se ocultarían montos.
- La validación de contenedor puede rechazar datos ya guardados al editarlos: solo se valida al guardar un valor cambiado.
