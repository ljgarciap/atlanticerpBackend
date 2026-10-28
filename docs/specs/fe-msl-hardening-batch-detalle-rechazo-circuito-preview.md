# Spec — Batch de endurecimiento FE/MSL (sin dependencia de la respuesta de MSL)

**Fecha:** 2026-09-29 · **Estado:** APROBADA por Luis (2026-09-29); C: en `apagado` el preview muestra "sin envío". Senior Review: 1 blocker (`$hidden` de `fe_detalle`) + sugerencias, resueltos.
**Origen:** hallazgos de SCRUM-943 (Senior Review) y SCRUM-987/958 (Pre-QA). Ticket Jira: por crear (o colgar de SCRUM-987 si Luis prefiere).

## Alcance (3 ítems, independientes entre sí)

### A. Persistir el detalle del rechazo del PAC
**Problema:** `fe_estado = rechazado` solo guarda el estado; el motivo (ej. `9026 - Falló la validación de algunos escenarios` + regla `VALIDATE_B07a`) vive solo en `laravel.log`. Diagnosticar exigió SSH.
**Cambio:**
- Migración tenant aditiva/nullable en `admin_contab.invoices` y `credit_notes`: `fe_detalle` TEXT NULL (patrón de `2026_09_17_190002/3`). **Revisión del Arquitecto requerida (regla de migraciones).**
- `FiscalCertificationService::persistirResultado()`: en la rama no-certificado guardar `fe_detalle` = errores (`extractErrores`) + `rawResponse['error']` truncado a 2000 chars; en certificado, limpiar a NULL (reintento exitoso no arrastra el rechazo viejo). Único punto de escritura, cubre certificación inicial y polling.
- Exponer `fe_detalle` en el payload de factura/NC (`InvoiceService` ~L1035 y equivalente de NC) y mostrarlo en `InvoiceDetailModal` / `DetalleNotaCreditoModal` junto al badge, **solo** para usuarios con permiso de ver Configuración Fiscal (hoy exclusivo de Aprobador/superadmin) — el texto de la DGI no se muestra a roles operativos.
- Sin iconografía emoji; texto plano, sin HTML (escapar).
**Criterios de aceptación:**
1. Factura rechazada por el PAC → `fe_detalle` contiene el código y la regla; visible en el detalle para el rol autorizado, oculto para los demás (probado con 2 roles).
2. Recertificación exitosa de un documento antes rechazado → `fe_detalle` = NULL.
3. Estado `error_configuracion` y `pendiente` no rompen (detalle NULL o mensaje propio); la regla "un fallo de FE nunca bloquea la factura" se mantiene (test existente sigue verde).
4. `fe_detalle` nunca supera 2000 chars ni contiene la API key.

### B. Circuit breaker por ambiente
**Problema:** `MslPacAdapter::CIRCUIT_NAME = 'msl_fe'` es único; una caída de MSL producción abriría el circuito del sandbox del mismo entorno y viceversa.
**Cambio:** nombre derivado de `$this->ambiente` → `msl_fe_pruebas` / `msl_fe_produccion` (`breaker()`); sin otros cambios de umbral.
**Criterios:** 1) con circuito de `pruebas` abierto, un adapter `produccion` sigue llamando (y viceversa); 2) comportamiento de umbral/timeout idéntico al actual (tests existentes verdes); 3) las claves Redis viejas `cb:msl_fe:*` quedan huérfanas e inofensivas (nadie las lee; `opened_at` NO expira sola — se puede limpiar con un `DEL cb:msl_fe:opened_at cb:msl_fe:failures` puntual, opcional).

### C. Ambiente correcto en la vista previa del JSON
**Problema:** `FiscalDocumentPreviewService` fuerza `'pruebas'` aunque el interruptor diga Apagado/Producción (Pre-QA lo reportó como detalle menor).
**Decisión de diseño (a confirmar):** mostrar el `ambiente` que **realmente usaría** `pac_ambiente` (`apagado` → `null`/"sin envío"; `testing` → `pruebas`; `production` → `produccion`), **sin** resolver credenciales ni instanciar el adapter (el servicio sigue sin gatear ni llamar al PAC).
**Criterios:** 1) preview en cada uno de los 3 valores de `pac_ambiente` muestra el ambiente correspondiente; 2) sigue sin realizar ninguna llamada HTTP; 3) en `apagado` la UI deja claro que no se enviará.

## Fuera de alcance
Validar RUC al crear/editar cliente, captura de `receptor_tipo`, estrategia de numeración por entorno (dependen de MSL / decisión de negocio → spec aparte).

## Reglas duras / riesgos
- Nada cambia el contrato con MSL ni el comportamiento de certificación: solo persistencia, nombre de circuito y texto de preview.
- Migración aditiva y nullable; reversible (`down` dropColumn). No destructiva.
- Cybersecurity: `fe_detalle` es texto de un tercero → tratarlo como no confiable (escape en UI, truncado). No requiere threat model nuevo.

## Plan de pruebas
Backend: `infra/test.sh --filter="FiscalCertificationService|MslPacAdapter|FiscalDocumentPreview|PollMslDocumentEstado"` + PHPStan L8; suite completa antes de Senior Review. Frontend: vitest de los 2 modales. Pre-QA fusionado con Visual Review (no hay mockup adjunto → solo Pre-QA), en dev.atlanticerp.ai.

## Asignación propuesta (PM)
Backend Dev: A(back) + B + C. Frontend Dev: A(front). Senior Review → Pre-QA → push a `dev` (gate de push individual, no se promueve a test/prod sin orden de Luis).
