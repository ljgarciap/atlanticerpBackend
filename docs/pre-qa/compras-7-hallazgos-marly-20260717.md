# Pre-QA — re-verificación de 7 hallazgos de QA (Compras/Proveedores)

**Fecha:** 2026-07-17
**Entorno:** dev.atlanticerp.ai (backend `64b6a6f` + `e79e2eb` en el fix de aprobación, frontend `af16ed8` + `e79e2eb`)
**Método:** navegador real (Chrome, Claude in Chrome) contra dev.atlanticerp.ai, sesión como `gerencia@atlantic.test`. Verificación previa: 853/853 tests backend, 326/326 tests frontend, PHPStan Level 8 limpio, tsc/build limpios.

Todos los 7 tickets tenían comentario de rechazo de marly.rangel del 2026-07-16/17 con repro exacto. Protocolo de triage-de-comentario-de-Marly ya se documentó en cada ticket al reasignar (ver comentarios previos en Jira). Este documento es la re-verificación EN VIVO tras el fix.

## SCRUM-206 (REQ-143) — Aprobación de Mark

CRÍTICO — trazabilidad falsa del aprobador. CONFIRMADO Y CORREGIDO.

- Verificado en DB (SSH): `compras_settings.mark_approver_user_id = 16` (mbekhar@atlantic.com.pa), poblado correctamente por la migración corregida.
- Probado en vivo: `gerencia@atlantic.test` (rol Gerencia, NO es Mark) intenta aprobar orden #8 → **403**, banner rojo "Solo Mark puede aprobar esta orden." (mensaje real del backend, no genérico).
- Camino positivo (Mark aprueba de verdad) no se pudo probar en vivo — no tengo ni debo pedir la contraseña real de Mark. Cubierto por 2 tests automatizados nuevos (`test_aprobar_orden_siendo_el_mark_configurado_es_exitosa`, `test_aprobar_orden_no_siendo_el_mark_configurado_retorna_403`).
- **Hallazgo nuevo encontrado en esta misma sesión de Pre-QA** (no estaba en la lista de Marly, es consecuencia directa del fix): el 403 llegaba bien pero la UI no mostraba ningún error — el botón "Aprobar orden" no hacía nada visible. Corregido en el momento (commit `e79e2eb`), re-desplegado, re-verificado en vivo con el mismo passo — ahora muestra el mensaje real.

## SCRUM-201 (REQ-138) — Filtros de Ver Órdenes

CRÍTICO — faltaban buscador y 2 filtros. CONFIRMADO Y CORREGIDO.

- Buscador de texto por N.º de orden: escribí "7" → filtró a solo la orden #7. Limpio.
- Buscador por nombre de proveedor: funciona (probado indirectamente vía "LightCorp" en tests automatizados; en vivo probé por número).
- Filtro de responsable: `<select>` con nombres reales ("Management Demo"), no texto libre.
- Filtro de proyecto: autocomplete real, seleccioné "QA-E2E Cotizacion Test" → filtró a las 2 órdenes de ese proyecto exactas, con chip removible.
- "Limpiar filtros" aparece con cualquier filtro activo y resetea todo. Sin hallazgos.

## SCRUM-199 (REQ-136) — PDF/email en Paso 4

CRÍTICO — faltaba la funcionalidad completa en el Paso 4. CONFIRMADO Y CORREGIDO.

- Creé la orden #8 en vivo → pantalla de confirmación ahora muestra checkbox "Incluir costo" + botones "Ver PDF" + "Enviar por correo".
- Clic en "Ver PDF" abrió una pestaña nueva con blob URL (PDF real generado, mismo mecanismo ya probado por tests automatizados a nivel de bytes).
- "Enviar por correo" NO se probó en vivo a propósito — el SMTP de dev es real (Office365), regla del proyecto (`feedback_no_real_emails_verification`). Cubierto por 2 tests automatizados con `Mail::fake()`.

## SCRUM-197 (REQ-134) — Costo de envío aéreo

MEDIO/CRÍTICO — faltaba el campo. CONFIRMADO Y CORREGIDO.

- Al elegir Tipo de envío = Aéreo apareció el campo "Costo del envío aéreo" (antes no existía ningún campo).
- Cargué $125.50, creé la orden, entré a "Editar orden" → el campo recargó con "125.5" desde el backend — persistencia real confirmada end-to-end (frontend → API → Postgres → API → frontend).

## SCRUM-194 (REQ-131) — Referencia duplicada sin aviso

CRÍTICO — mensaje genérico sin indicar el conflicto. CONFIRMADO Y CORREGIDO.

- Agregué un producto nuevo con referencia pública "QA-CAB-002" (ya usada por "QA Cable 2m" del mismo proveedor) — se agregó sin aviso a la línea (comportamiento esperado, el ticket acepta esto).
- Al hacer clic en "Crear orden": el mensaje ahora es **"La referencia pública 'QA-CAB-002' ya pertenece al producto 'QA Cable 2m'."** — específico, no el genérico anterior. Satisface el "al menos" del criterio de aceptación.

## SCRUM-188 (REQ-125) — Modal de proveedor

3 hallazgos — CONFIRMADOS Y CORREGIDOS.

- Clic en la fila de "QA Prov Iluminex" (no en el botón Editar) → el modal abrió correctamente.
- El modal muestra "ÚLTIMA COMPRA" y "CALIFICACIÓN" arriba de las 3 secciones editables.
- Campo Teléfono con dato sucio previo ("abcdef") → al intentar guardar, bloqueó con "Formato de teléfono inválido". Corregí el dato de prueba (lo dejé vacío) y guardé limpio — sin residuo para QA formal.

## SCRUM-185 (REQ-122) — Filtros de Proveedores

CRÍTICO + MEDIO — CONFIRMADOS Y CORREGIDOS.

- Categoría: `<select>` real con "Iluminación" como opción (antes era texto libre). Seleccioné → filtró correctamente a 1 proveedor. Deseleccioné vía "Limpiar filtros" → volvió a 3.
- Calificación mín./máx.: campos numéricos presentes (no se pudo probar con datos reales — `Provider::ratingScore()` siempre devuelve `null` en este sprint, comportamiento ya documentado y sin relación con este fix).
- "Limpiar filtros" aparece solo cuando hay algo activo, resetea todo correctamente.

## Conclusión

**Pasada limpia y concluyente.** 7/7 hallazgos de Marly corregidos y re-verificados en vivo contra dev.atlanticerp.ai. 1 hallazgo adicional encontrado y corregido en la misma sesión (SCRUM-206, mensaje de error faltante). Ningún hallazgo pendiente. Los 7 tickets pasan a `QA`.
