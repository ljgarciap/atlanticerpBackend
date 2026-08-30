# Pre-QA — Solicitud de ajuste (Bodega)

**Tickets:** SCRUM-428, 429, 430, 446, 447, 448, 449, 450 (REQ-358/359/360/376/377/378/379/380)
**Fecha:** 2026-07-21
**Entorno:** local (Docker Compose, `infra/docker-compose.yml`) + Vite dev server `localhost:5173`
**Método:** ejecución real — API directa (curl, incl. requests concurrentes) + navegador real (Playwright, spec temporal borrado al terminar)

## CRÍTICO

### 1. Race condition — aprobar una línea puede aplicar el ajuste de inventario más de una vez
`AdjustmentRequestController::approve()` lee la línea, valida `isPending()` fuera de cualquier lock, y recién dentro de la transacción llama a `KardexService::registrar()` y actualiza `estado`. No hay `lockForUpdate()` ni re-chequeo atómico del estado dentro de la transacción.

**Repro:** se creó una solicitud con línea Pendiente ("Sumar 3" a Showroom Obarrio) y se dispararon 5 `POST /api/bodega/adjustment-requests/lines/{id}/approve` concurrentes con el token de Mark:
- 3 de 5 requests devolvieron `200` (debería ser exactamente 1, los otros 4 deberían fallar con "ya fue resuelta").
- El stock del producto en esa bodega pasó de `NULL` a `9` en vez de `3` (3 × 3).
- Se crearon 3 `InventoryMovement` para la misma aprobación (`movement count = 3`).

Esto viola directamente REQ-379 Escenario 1 (la aprobación debe aplicar el ajuste exactamente una vez) y es un riesgo real de corrupción de stock — un doble click, un reintento de red, o dos pestañas del mismo Mark aprobando casi simultáneo corrompen el inventario real sin ningún error visible para el usuario (2 de los 3 "duplicados" sí devuelven 200 con la data ya "Aprobada", parece éxito).

**Fix sugerido (para quien lo tome):** `SELECT ... FOR UPDATE` sobre la línea dentro de la transacción (o un `UPDATE ... WHERE estado = 'Pendiente'` atómico) antes de registrar el movimiento de Kardex, re-chequeando `affected rows === 1` antes de proceder.

### 2. SCRUM-428 (REQ-358) — falta el punto de entrada "Solicitar ajuste" desde un producto
El criterio dice explícitamente: *"Desde cualquier producto, el botón 'Solicitar ajuste' abre un modal con el producto ya fijo (no editable)"*. Ese botón no existe en ninguna pantalla:
- `BodegasPage.tsx` — la columna "Acción" de la tabla de productos por bodega solo tiene un botón "Reubicar" **deshabilitado** (`disabled`), no hay "Solicitar ajuste".
- `CatalogPage.tsx` (Ventas & Diseño) — sin ninguna referencia a "ajuste".
- Grep de `"Solicitar ajuste"` en todo `src/` no devuelve ningún resultado.

La única forma de llegar al modal es por el punto de entrada alternativo de SCRUM-446 ("+ Nueva solicitud"), donde el producto se elige libremente por búsqueda — nunca queda fijo desde una fila de producto ya existente. El controller confirma esto en su propio docblock (`searchProducts` está documentado como "selector de 'Nueva solicitud'", singular, no dos entradas). SCRUM-428 es uno de los 8 tickets del batch — su criterio literal no está cubierto.

## MEDIO

### 3. RN1 (428) — el selector de bodega no muestra "la cantidad actual en cada una"
El criterio del modal exige que el selector de bodega muestre, para el producto en cuestión, la cantidad actual en cada una de las 7 bodegas. En la implementación actual el `<select>` solo lista los nombres (`Bodega Central`, `Bodega Zona Libre`, ...) — confirmado en código (`useWarehousesList`/`bodegaApi.warehouses.list()` solo trae `{id, name, responsable, capacidad_pct, modo_detalle}`, sin ninguna cantidad por producto) y visualmente en captura de pantalla en vivo.

### 4. RN5 (428) — falta la advertencia de unidades comprometidas al "Restar"
El criterio: si tipo=Restar y el producto tiene unidades comprometidas a un pedido en curso, debe mostrarse una advertencia (aunque se puede enviar igual). Se seleccionó tipo "Restar" en vivo sobre CRISTAL-IMPERIAL y no aparece ningún texto de advertencia — no hay ningún string de i18n ni lógica en el componente para esto (grep de "comprometid|pedido en curso|advertencia" en `src/` no encuentra nada relacionado a esta pantalla).

### 5. Backend no valida bodegas duplicadas dentro de la misma solicitud
El frontend impide re-seleccionar una bodega ya agregada (RN3, confirmado que funciona), pero el endpoint `POST /adjustment-requests` no tiene una regla `distinct` sobre `lines.*.warehouse_id`. Probado directamente vía API: una solicitud con 2 líneas para la **misma** bodega (warehouse_id=6, una Sumar y otra Restar) fue aceptada con `201`, creando 2 líneas para la misma bodega. No es explotable hoy desde la UI tal como está, pero es un hueco real de validación server-side de una regla que hoy solo se sostiene en el cliente.

### 6. Escenario 2 (428) — el bloqueo de evidencia ocurre en el envío final, no al "agregar" la línea
El texto literal del criterio ("cuando se presiona agregar, entonces el sistema lo impide") sugiere un paso de confirmación por línea. La implementación real no tiene ese paso: "+ Agregar bodega a la solicitud" siempre agrega una línea vacía editable in-place, y la validación de evidencia obligatoria solo corre al presionar "Enviar solicitud" (confirmado en vivo — mensaje "Completa todos los campos de cada línea, incluida la evidencia." aparece recién al enviar, no al agregar). El resultado de negocio (no se puede enviar sin evidencia) sí se cumple — esto es una discrepancia de UX/redacción de criterio, no una regla de negocio rota. Vale la pena que Analista/Arquitecto confirmen que la intención coincide con la UX actual.

## Lo que sí funciona (verificado en vivo, no solo leído)

- **RN2/RN4** — carrito multi-línea: cada línea tiene su propio tipo/cantidad/motivo/evidencia; se puede editar o quitar antes de enviar.
- **RN3** — agregar una bodega la saca del selector; quitar esa línea la hace reaparecer. Verificado en ambas direcciones en navegador real.
- **SCRUM-429 Escenario 1** — enviar una solicitud con 2 líneas crea exactamente 2 registros Pendiente independientes (1 `AdjustmentRequest` + 2 `AdjustmentRequestLine`).
- **SCRUM-430 Escenario 1 + PERMISOS** — `gerencia@atlantic.test` (tiene `bodega.approve` pero NO es Mark) recibe `403 "Solo Mark puede aprobar o rechazar esta solicitud."` tanto en approve como en reject — el gate es server-side real, no solo un botón escondido. `resuelto_por`/`resolvedBy` queda registrado correctamente en ambos casos.
- **SCRUM-447 RN1/Escenario 1** — una solicitud de 2 bodegas aparece como 2 filas totalmente independientes en el historial, cada una con su propio estado (una quedó Pendiente mientras su hermana ya estaba Aprobada). Verificado en navegador con datos reales.
- Chips de filtro (Todas/Pendientes/Aprobadas/Rechazadas) filtran correctamente contra la API.
- **SCRUM-448 RN1** — rechazar sin motivo bloqueado (`422`, "El campo motivo rechazo es obligatorio.") vía API y en UI.
- **SCRUM-448 RN2** — una línea ya Aprobada o Rechazada no admite una segunda acción: reintentar approve o reject sobre ella devuelve `422 "Esta solicitud ya fue resuelta."` (probado approve→approve y approve→reject).
- **SCRUM-449 Escenario 1** — Mark aprueba una línea "Sumar 1" y el stock real de esa bodega sube exactamente en 1 (verificado 15→16 vía consulta directa a `ProductWarehouseStock` antes/después).
- **SCRUM-449 Escenario 2** — rechazar una línea no modifica el inventario en absoluto (verificado stock idéntico antes/después).
- Validaciones de formulario: motivo solo-espacios bloqueado (`TrimStrings` + `required` → 422), evidencia faltante bloqueada (422), extensión inválida (`.exe`) bloqueada por la regla `extensions:` (422, coincide con la convención del proyecto de no usar `mimes:`), `cantidad=0` bloqueada por `min:1`.
- Designer (sin `bodega.approve`) recibe `403` a nivel de middleware de ruta, ni siquiera llega al controller.
- Sin iconografía de emoji en ninguna pantalla — cumple la regla de marca de Atlantic.
- SCRUM-450 — badge de rol en TopBar coherente con el resto de la app (`GERENCIA`, `VENDEDOR/DISEÑADOR`), nada específico de esta pantalla roto.

## Estado y próximos pasos

Batch **NO pasa** a QA todavía — 2 hallazgos CRÍTICO bloquean el cierre (regla dura de Pre-QA, ver `.claude/agents/pre-qa.md`). Ninguno de los dos es un fix de una línea:
- El race condition requiere un cambio de diseño de concurrencia (lock/atomicidad) en `approve()`.
- El punto de entrada faltante de SCRUM-428 requiere decidir dónde vive el botón "Solicitar ajuste" por producto (¿en `BodegasPage.tsx`? ¿en el catálogo de Ventas & Diseño?) — es una decisión de UX/Arquitecto, no algo que yo deba resolver.

Notificando a PM para reasignación. Cuando ambos se corrijan, se debe volver a correr el checklist completo (no solo estos 2 puntos) antes de transicionar a QA — en particular re-confirmar el race condition con el mismo test de 5 requests concurrentes como regresión.

---

## Pasada 2 — re-verificación (subagente Pre-QA nuevo, contexto aislado de la pasada 1)

**Fix aplicado antes de esta pasada:**
- `approve()`/`reject()`: `lockForUpdate()` + re-chequeo de `isPending()` movidos DENTRO de la transacción (antes vivían afuera, sin lock).
- `lines.*.warehouse_id` ahora tiene la regla `distinct` (hallazgo #5).
- Nuevo endpoint `GET /bodega/adjustment-requests/products/{id}/warehouse-stock`: cantidad por bodega (RN1) + `por_servir` (RN5), consumido por el modal.
- Hallazgo #2 (SCRUM-428 sin botón desde un producto) y #6 (evidencia al enviar, no al agregar) se dejaron como decisiones documentadas, no como bugs a corregir ahora.

**Resultado: PASADA LIMPIA — pasa a QA.**

- **Race condition (hallazgo #1): CONFIRMADO CORREGIDO**, verificado de forma independiente (subagente nuevo, no vio cómo se corrigió):
  - 5 concurrentes → 1 de 5 aplica (200), 4 devuelven 422. Stock correcto.
  - 10 concurrentes → 1 de 10 aplica. Stock correcto.
  - 3 approve + 3 reject simultáneos sobre la misma línea → solo 1 de 6 gana, estado final consistente.
  - Doble clic real en navegador (Playwright): el frontend SÍ dispara 2 requests (el botón no se deshabilita a tiempo entre los 2 clics), pero el backend absorbe la carrera igual — a lo sumo 1 de 2 responde 200. **Promovido a test permanente:** `atlanticerp-frontend/e2e/preqa-solicitud-ajuste-doble-aprobar.spec.ts`.
  - Suite PHPUnit: 14/14 tests de `AdjustmentRequestControllerTest` en verde, incluye el caso de regresión secuencial.
- **Hallazgo #5 (duplicados): CONFIRMADO CORREGIDO** — 422 "tiene un valor duplicado" vía API directa.
- **RN1 (cantidad por bodega): CONFIRMADO CORREGIDO** — verificado en navegador, el `<option>` muestra la cantidad real dinámica (ej. "Bodega Central (23 unidades)").
- **RN5 (advertencia Restar): código correcto, no ejercitado con datos reales** — este entorno local no tenía ningún producto con `por_servir > 0` sembrado (requiere una cadena Provider→Order→Line→Project completa). Recomendado que QA lo verifique en `dev.atlanticerp.ai`, que sí tiene datos reales de Compras.
- Reconfirmado sin regresión: multi-bodega (2 filas independientes), gerencia (no-Mark) bloqueada con 403, cantidad 0/negativa rechazada, motivo en blanco rechazado, reload a mitad del modal no rompe nada.
- Hallazgos #2 y #6 no se re-reportaron — ya están documentados como decisiones aceptadas, no bugs.

**Cierre del loop:** CRÍTICO encontrado → corregido → re-verificado por un subagente Pre-QA independiente (no el mismo que encontró el problema) → pasada limpia. Pasa a `QA`.
