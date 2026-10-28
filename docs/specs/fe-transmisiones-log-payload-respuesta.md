# Spec — Registro de transmisiones al PAC (payload enviado + respuesta recibida)

**Fecha:** 2026-09-29 · **Estado:** BORRADOR — pendiente de aprobación de Luis · **Ticket:** colgar de SCRUM-987 (o nuevo, a decidir)
**Origen:** MSL pidió los payloads de las pruebas con falla (correo 2026-09-29) y no existen guardados.

## Problema
- `MslPacAdapter::certificar()` arma el payload en memoria, lo envía y lo descarta; de la respuesta solo persistimos `fe_estado` (+ `fe_detalle`, desde el batch de hoy). No hay forma de saber *qué se envió* ni *qué contestó exactamente* el proveedor.
- La "Vista previa del JSON" (`fe-preview`) **no es evidencia**: recalcula el payload en el momento con los datos vigentes. Si el dato, la tasa o la propia lógica cambian (ya pasó: SCRUM-987 cambió `numero` de `F-0011` a `0011`), la vista previa deja de coincidir con lo enviado. Solo sirve para revisar la estructura antes de enviar.
- Consecuencia: cada consulta del proveedor obliga a reconstruir a mano y a leer `laravel.log` por SSH.

## Alcance

### 1. Tabla `fe_transmisiones` (tenant, `admin_contab`) — append-only, un registro por intento
`id`, `documento_tipo` (`factura`|`nota_credito`), `documento_id`, `provider` (`msl`|`digifact`), `ambiente` (`pruebas`|`produccion`),
`operacion` (`certificar`|`consultar_estado`), `endpoint`, `serie`, `numero_fiscal`,
`request_payload` JSONB NULL (NULL en consultas GET), `http_status` INT NULL (NULL si no hubo respuesta: circuito abierto/timeout),
`response_body` JSONB NULL, `resultado` (`certificado`|`rechazado`|`pendiente`|`error_configuracion`|`sin_respuesta`),
`duracion_ms`, `request_id` (el de AtlanticERP, para cruzar con `laravel.log`), `origen` (`certificacion`|`polling`), `created_at`.
Sin `updated_at`: nunca se edita ni se borra desde la app. Índice por (`documento_tipo`, `documento_id`).
**Aditiva**; migración revisada por el Arquitecto (regla del proyecto).

### 2. Captura
- `FiscalDocumentResult` gana campos opcionales `requestPayload`, `httpStatus`, `duracionMs` (el adapter los completa; no conoce Eloquent).
- `FiscalCertificationService::persistirResultado()` es el único punto que escribe: registra la transmisión en la misma pasada en que persiste `fe_estado`. El polling (`PollMslDocumentEstadoJob`) también registra, con `origen=polling`.
- **Regla dura ya vigente:** un fallo al registrar la transmisión NUNCA bloquea ni revierte la factura/NC (try/catch + `Log::error`).
- `pac_ambiente = apagado`: no se envía nada, no se registra nada (la vista previa sigue siendo la herramienta).
- Saneamiento antes de guardar: se quita `xmlFirmado` de la respuesta (ya se archiva en S3, y es enorme), se redactan las API keys configuradas (mismo método que `fe_detalle`), y un tope de tamaño por campo (propuesta 64 KB) con marca `[truncado]`.

### 3. Lectura (solo Aprobador/superadmin, deny-by-default)
- `GET /api/admin-contab/invoices/{order}/fe-transmisiones?invoice_id=` y `GET /api/admin-contab/notas-credito/{id}/fe-transmisiones`, bajo el middleware `primary_approver_only`.
- Frontend: en el detalle de factura y de NC, para Aprobador, un bloque plegable "Intentos de transmisión" con fecha, resultado, HTTP, y el request/response en JSON con botón "Copiar" (pensado para pegarlo en un correo a soporte del proveedor).

## Datos personales
El payload lleva RUC, nombre, correo y dirección del receptor. Mismo criterio que `fe_detalle`: solo Aprobador/superadmin. No se replica a logs ni a `audit_logs` (evitar duplicar PII).

## Fuera de alcance
Backfill de intentos anteriores (no existen datos; los de dev se reconstruyeron a mano, ver correo a MSL); reintento manual desde la UI; purga.

## Decisiones que necesito de Luis
1. **Retención:** propuesta = conservar sin purga (evidencia fiscal, volumen bajo). ¿Otra política?
2. **Alcance de UI:** propuesta = almacenamiento + endpoints + visor plegable para Aprobador. ¿Solo almacenamiento y endpoint por ahora?
3. **Ticket:** ¿colgar de SCRUM-987 o crear uno nuevo?

## Criterios de aceptación
1. Cada certificación real (factura y NC, sandbox/producción) deja un registro con el payload exacto enviado y la respuesta cruda sin `xmlFirmado`.
2. Cada consulta de estado (polling) deja su registro con `request_payload` NULL.
3. Circuito abierto o timeout deja un registro `sin_respuesta` con `http_status` NULL.
4. Con `apagado` no se crea ningún registro.
5. Un error al escribir el registro no cambia el estado de la factura/NC ni el resultado de la certificación (test).
6. Ningún registro contiene una API key ni `xmlFirmado`; campos por encima del tope quedan truncados y marcados.
7. Los endpoints responden 403 a cualquier actor que no sea Aprobador/superadmin; `fe-transmisiones` no aparece en ningún otro serializador (Bodega, correo, PDF, exportaciones).
8. La suite de Bodega/otros no cambia; PHPStan Level 8 limpio; migración reversible.

## Plan de pruebas / asignación
Backend Dev: migración + modelo + captura + endpoints. Frontend Dev: visor. Senior Review independiente → Pre-QA en dev.atlanticerp.ai (sandbox real) → push a `dev` (gate individual).
