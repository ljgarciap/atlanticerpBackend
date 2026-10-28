# Spec — SCRUM-1002: validar RUC/Cédula (DGI vía MSL) y editar los datos fiscales de un SubCliente

**Fecha:** 2026-09-29 · **Estado:** DECISIONES TOMADAS por Luis 2026-09-29 (queda D4 parcial: RUC reales de clientes para el Pre-QA) — lista para Backend/Frontend Dev · **Rol:** Analista + Arquitecto
**Origen:** pedido de Luis (2026-09-28) tras los rechazos 9032 por RUC/DV inventados en las pruebas de SCRUM-958.

## Estado actual (verificado en el código)
- `sub_clients.tax_id`, `tax_id_dv` y `business_name` se fijan **solo al crear** (`StoreClientSubClientRequest` / `StoreSubClientRequest`: `tax_id` obligatorio, `tax_id_dv` obligatorio `^\d{1,2}$` desde SCRUM-928, no retroactivo: filas viejas pueden tener DV nulo). La única edición posible de un SubCliente es la categoría (`PATCH /clients/sub-clients/{id}/category`).
- El **receptor de la factura electrónica sale de `sub_clients`** en vivo (`FiscalDocumentBuilder::receptorDeSubClient`), no de la cotización. El detalle de factura y el PDF también leen `cliente_ruc` en vivo de `subClient->tax_id` (`InvoiceService.php:1009`). **Consecuencia:** editar el RUC de un cliente cambia retroactivamente el PDF de facturas ya certificadas y ya no coincidiría con el XML fiscal firmado.
- `PacProviderContract::validarReceptor()` existe (MSL y Digifact) pero **ningún endpoint ni pantalla lo usa**. `MslPacAdapter::validarReceptor()` hoy devuelve `valido`, `nombre`, `dv`, `tipoRuc` y descarta `estado`.
- `SubClient` ya usa `LogsAuditTrail` (cambios quedan en `audit_logs` con valores anterior/nuevo).
- `PacProviderFactory::make()` con `pac_ambiente = apagado` **cae al sandbox** (solo `production` va a producción); el interruptor "apagado" está pensado como cortafuegos para certificar, y Pre-QA verificó que en ese modo no hay llamadas a MSL.
- Documentación pública de MSL (`/referencia` §6): `GET /v1/validar-ruc?ruc=&tipo=01&ambiente=pruebas|produccion`; responde `valido`, `nombre`, `dv`, `tipoRuc` (1 natural · 2 jurídico), `estado` (ejemplo con `"I"`), y acepta RUC o cédula (`8-123-456`). El nombre oficial puede diferir de la abreviatura (hoy devuelve "DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA"; el ejemplo de la guía decía "S.A.").

## Riesgos que el diseño debe cubrir
1. **Registro distinto por ambiente:** el sandbox puede no conocer RUC reales de clientes de Atlantic (`ambiente=pruebas` vs `produccion`). Hay que probarlo con 1-2 RUC reales antes de prometer la UX en dev/test.
2. **Circuito compartido:** `validarReceptor()` usa el mismo circuito `msl_fe_<ambiente>` que la certificación. Una ráfaga de consultas fallidas desde la UI podría abrir el circuito y frenar facturas. → circuito propio para consultas.
3. **Validar no debe bloquear:** MSL caído, sin API key o `apagado` ⇒ se puede crear el cliente igual.
4. **Cambiar un RUC ya usado en documentos fiscales** (ver arriba) ⇒ regla de bloqueo/aviso.
5. `estado` del contribuyente: el significado de `"I"` no está documentado (¿inactivo?). Mostrar como aviso, no bloquear; preguntar a MSL.

## Diseño propuesto
**A. Validación (sin cambiar datos)**
- `POST /api/ventas-diseno/clients/validate-tax-id` `{ tax_id }` → `{ disponible: bool, valido: bool|null, nombre, dv, tipo_ruc, estado, mensaje }`. Permiso `ventas_diseno.write` (el mismo del alta), `throttle` (propuesta 20/min por usuario). Nunca 5xx por fallas de MSL: `disponible=false` + mensaje.
- Servicio nuevo `TaxIdValidationService` sobre `PacProviderFactory`, con **circuito propio** `msl_lookup_<ambiente>`; respeta `apagado` (ver D1).
- `MslPacAdapter::validarReceptor()` debe exponer `estado` en `FiscalPartyLookupResult` (campo opcional, sin romper Digifact).
- Registro en `fe_transmisiones`: **no** (no es una transmisión de documento); sí un log estructurado sin el RUC completo.
- No se expone la API key ni la respuesta cruda al navegador.

**B. UI (alta y edición)** — `CreateClientModal` y el modal de edición:
- Botón "Validar RUC" junto a RUC/DV. Si es válido: completa el DV y **propone** la razón social (el usuario confirma; nunca pisa en silencio). Si no es válido o `estado` no activo: aviso visible, no bloquea el guardado. Si no está disponible: "No se pudo validar ahora; puedes guardar y validar después". Muestra "validado con la DGI hoy HH:MM (hora de Panamá)".

**C. Edición de datos fiscales de un SubCliente**
- `PATCH /api/ventas-diseno/clients/sub-clients/{id}/tax-data` `{ business_name, tax_id, tax_id_dv }`, mismas reglas de validación que el alta, permiso angosto nuevo `ventas_diseno.edit.client_tax_data` sembrado por `SpecialPermissionSeeder` (regla del proyecto: permisos angostos a una persona van al seeder, nunca grants manuales).
- **Regla de bloqueo:** 409 si el SubCliente tiene facturas o NC con `fe_estado` `certificado` o `pendiente` (el PDF y el XML dejarían de coincidir). Alternativa mayor, fuera de alcance: congelar un snapshot del receptor en `invoices` al certificar.
- Auditoría con `LogsAuditTrail` (valores anterior/nuevo) — ya disponible.

**D. Fuera de alcance:** validar en cada factura (el RUC no cambia entre facturas); revalidación masiva de clientes existentes (posible comando `clients:revalidate-tax-ids --dry-run` como ticket aparte, ver D5); snapshot del receptor por factura.

## Decisiones que necesito de Luis
- **D1 — Interruptor "Apagado":** ¿la validación de RUC también se apaga? Recomendado: **sí** (mantiene la garantía "apagado = cero llamadas a MSL"); el botón muestra "Validación no disponible".
- **D2 — ¿Quién puede editar RUC/DV/razón social?** Propongo el permiso angosto `ventas_diseno.edit.client_tax_data` y concederlo a: Aprobador y Contabilidad (`contabilidad@test.com`). ¿Otros (Vendedores/Diseñadores sí o no)?
- **D3 — Regla de bloqueo:** ¿409 solo con documentos certificados/pendientes (recomendado), o con cualquier factura emitida, o edición libre con bitácora?
- **D4 — Dos RUC reales de clientes de Atlantic (uno jurídico, uno persona natural con cédula)** para probar el sandbox de MSL antes de construir; y confirmar con MSL: qué significa `estado="I"`, si el sandbox conoce RUC reales, y cómo se calcula el DV de una cédula (hoy el DV es obligatorio).
- **D5 — ¿Ticket aparte para revalidar los clientes existentes?** (solo lectura, con reporte de discrepancias; no modifica datos).

## Criterios de aceptación
1. RUC válido ⇒ DV y razón social propuesta; inválido o `estado` no activo ⇒ aviso sin bloquear; MSL caído/sin key/apagado ⇒ mensaje claro y el alta sigue funcionando.
2. El endpoint no filtra la API key ni la respuesta cruda; 403 sin permiso; `throttle` probado.
3. Las consultas usan un circuito propio: 5 fallos de validación **no** abren el circuito de certificación (test).
4. Edición: 403 sin el permiso angosto; 409 si hay documentos certificados/pendientes; audit_logs registra antes/después; el permiso se siembra por `SpecialPermissionSeeder` y aparece en el JWT.
5. PHPStan Level 8, suite completa y vitest verdes; Pre-QA en dev con RUC real (D4).

**Esfuerzo:** medio (~2-3 días: backend 1,5; frontend 1). **Dependencias:** D1-D4. **Orden:** primero este ticket; SCRUM-1003 reutiliza el endpoint de edición.

## Decisiones tomadas (Luis, 2026-09-29)
- **D1 — La validación respeta "Apagado":** en ese modo no se llama a MSL; el botón muestra "Validación no disponible".
- **D2 — Quién edita RUC/DV/razón social: Aprobador, Contabilidad y Ventas & Diseño** (distinto de la propuesta original, que era solo Aprobador y Contabilidad).
  *Implicaciones a resolver por Backend Dev/Arquitecto al implementar:* (a) "Ventas & Diseño" es un módulo/rol amplio, no una persona: los permisos angostos por persona van a `SpecialPermissionSeeder`, pero un grupo de roles se concede en el catálogo de permisos del rol (mecanismo v2, ADR-006); definir cuál, sin dar el permiso a niveles que no lo necesitan. (b) Más editores = más superficie de error fiscal: se mantienen como mitigaciones el bloqueo 409 (D3), la auditoría antes/después y el aviso visible de "esto cambia el RUC con el que se factura". (c) Reflejarlo en el JWT/`modules` y en `Sidebar`/UI solo donde aplique.
- **D3 — Bloqueo (409) solo si el cliente tiene facturas o NC con `fe_estado` certificado o pendiente.** Con facturas en Apagado/rechazadas se puede editar.
- **D4 (parcial) — Se arranca con el RUC de la guía de MSL** (`155704849-2-2021`, Digifact) para desarrollo y pruebas en dev. **Pendiente de Luis:** dos RUC reales de clientes de Atlantic (uno jurídico, una cédula) antes del Pre-QA, para confirmar si el sandbox de MSL conoce RUC reales; y las preguntas a MSL (`estado="I"`, DV de cédula, si el sandbox conoce RUC reales).
- **D5 — Ticket aparte para revalidar clientes existentes** (solo lectura, `clients:revalidate-tax-ids --dry-run`): creado (ver Jira).
