# Spec — SCRUM-1007: reintento manual de certificación ("Recertificar")

**Fecha:** 2026-09-30 · **Estado:** BORRADOR con recomendaciones — decisiones D1-D3 de Luis al final · **Rol:** Analista + Arquitecto
**Origen:** hallazgo MEDIO del Pre-QA de SCRUM-958. Una factura o NC en `rechazado` / `error_configuracion` no tiene forma de reintentarse: NC-0009 y NC-0012 de dev quedaron rechazadas para siempre. Es prerrequisito práctico de SCRUM-1009 (retener el reemplazo hasta que la NC de anulación certifique).

## Hechos verificados en el código
- `FiscalCertificationService` tiene `certificarFactura()` / `certificarNotaCredito()`; ambos, con la FE apagada o el tipo deshabilitado, **sobrescriben `fe_estado = 'no_aplica'`**. Un reintento NO puede reutilizarlos tal cual: borraría el estado (y la evidencia) de un documento rechazado. Hay que entrar por el `certificar()` privado, que ya centraliza ensamblado + proveedor + registro de transmisión + `persistirResultado()`.
- `persistirResultado()` es el **único** punto que escribe el resultado (estado, CUFE, QR, XML, `fe_detalle`) y agenda el polling; un reintento que pase por ahí hereda todo, incluido el registro en `fe_transmisiones`.
- MSL confirmó que un documento rechazado **no consume el número**: se puede reenviar el mismo `numero` (`numeroFiscal` es determinista a partir del folio).
- `primary_approver_only` + el flag `$isMark` de los controladores de detalle ya modelan "solo Mark/superadmin" (`AdminContFiscalSettings::actorEsMarkOSuperadmin`).
- No hay `Cache::lock` en el código de AdminCont; Redis está disponible (circuitos, colas).
- Un `9022` ("referencia interna ya enviada") indica que MSL ya tiene ese número certificado (p. ej. timeout tras un envío exitoso): reenviar no ayuda, hace falta consultar el estado. Fuera de alcance (ver abajo).

## Diseño
**Backend**
1. `FiscalCertificationService::recertificar(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): void` (público). Precondiciones, cada una con excepción de dominio y mensaje en español (HTTP 409 con `codigo` estable):
   - `fe_estado` ∈ {`rechazado`, `error_configuracion`}; nunca `certificado`, `pendiente` ni `no_aplica` (`estado_no_reintentable`).
   - FE activa: `pac_ambiente ≠ apagado`, proveedor `msl` y el tipo de documento habilitado (`fe_apagada`) — **sin tocar el estado del documento**.
   - Factura no anulada (`anulada_at` nulo). NC solo en `estado = aplicada` (no `pendiente_aprobacion` ni `rechazada`).
   - Reusa el `certificar()` privado (mismo builder, mismo `persistirResultado()`, misma transmisión registrada con `origen = 'reintento_manual'`).
2. **Idempotencia / doble clic:** `Cache::lock("fe-recertificar:{tipo}:{id}", 90)`; si no se obtiene ⇒ 409 `en_curso`. Dentro del lock se **relee** el modelo y se revalida el estado (el segundo usuario ve `certificado` y recibe `estado_no_reintentable`). No se usa un estado intermedio en BD (un `pendiente` transitorio dispararía el polling y quedaría huérfano si el proceso muere).
3. **Endpoints** (bajo `primary_approver_only`, `throttle:6,1` por usuario):
   - `POST /api/admin-contab/invoices/{order}/fe-recertificar` (`invoice_id` opcional, como `fe-preview`).
   - `POST /api/admin-contab/notas-credito/{creditNote}/fe-recertificar`.
   - Respuesta 200: `{ fe_estado, fe_detalle, cufe, mensaje }` con el resultado real (puede seguir en `rechazado` con un motivo nuevo; eso NO es un error HTTP).
4. **Auditoría:** `AuditService::log('admin_cont.fe_documento.recertificado', entityType: 'invoice'|'credit_note', entityId, oldValues: fe_estado anterior, newValues: fe_estado nuevo, metadata: {tipo, http_status})`, constante nueva en `AuditService`. Sin payload ni datos del receptor.
5. **Flag para la UI:** `puede_recertificar` en `detail()` y `show()` (junto a `puede_ver_fe_transmisiones`): `isMark` y estado reintentable, no anulada / NC aplicada y FE activa. Deny-by-default como `fe_detalle`.
6. **Contrato con SCRUM-1009:** como el reintento pasa por `persistirResultado()`, cuando una NC de anulación de una corrección pase a `certificado` por reintento, el oyente que 1009 defina (liberar la factura de reemplazo) se dispara igual que en la certificación inicial. Esta spec no lo implementa.

**Frontend**
- En `FiscalElectronicStatusBadge` (o junto a él en los dos modales), botón **"Reintentar certificación"** visible solo con `puede_recertificar`. Abre un diálogo de confirmación con lenguaje claro ("Se enviará de nuevo el documento a la DGI mediante MSL; si ya fue aceptado no se duplicará…" — ajustar al alcance real) y estado "Enviando…" (la llamada puede tardar ~15 s por el timeout de MSL). Al terminar: invalidar el detalle, y mostrar el resultado (certificado con CUFE, o rechazado con el motivo nuevo).
- Errores 409 con el mensaje del backend. i18n es/en, sin emojis, texto como texto (motivo de un tercero).

## Fuera de alcance (seguimientos)
- ~~"Sincronizar con MSL"~~ **Ya NO está fuera de alcance para `pendiente`** (D2: botón "Actualizar estado", ver diseño ampliado). Sigue fuera de alcance recuperar el CUFE de un documento certificado cuya respuesta se perdió: la API de MSL no lo permite (spike) — pregunta abierta para MSL.
- Reintento automático programado; reintento masivo; recertificar una NC `pendiente_aprobacion`.
- `fe_emision_at` (instante de emisión persistido) — ver SCRUM-1008; solo si MSL confirma un límite de atraso.

## Decisiones de Luis (2026-09-30)
- **D1 — Quién puede reintentar:** Mark y superadmin.
- **D2 — SÍ se incluye "Actualizar estado" para documentos `pendiente`** (distinto de la recomendación original, que lo dejaba para un ticket aparte). Ver "Hallazgo del spike" y el diseño ampliado abajo.
- **D3 — Límite de intentos:** solo `throttle` (6/min por usuario), sin tope por documento.

## Hallazgo del spike (2026-09-30, 4 consultas GET de solo lectura al sandbox de MSL, sin gastar documentos)
`GET /v1/documentos/{serie}/{numero}` responde **HTTP 404 `{"ok":false,"error":"Documento no encontrado en la cola"}` tanto para documentos que NUNCA se enviaron (`ILU/9999999`, `NC-ILU/9999999`) como para documentos ya CERTIFICADOS por el camino directo (`ILU/0011`, `NC-ILU/0011`, certificados con 200)**. Es decir: ese endpoint solo conoce los documentos que pasaron por la cola de reintento de MSL (respuestas 202); un documento certificado directamente NO se puede consultar por ahí. Consecuencias:
1. El adapter hoy mapea ese 404 a `error_configuracion` (`mapCertificacionResponse`, `default`), y `PollMslDocumentEstadoJob` lo trata como "error de configuración, requiere intervención manual" y **termina sin persistir**: un documento `pendiente` que deje de estar en la cola quedaría `pendiente` para siempre (hueco preexistente).
2. Un 404 es ambiguo: "MSL nunca lo recibió" (p. ej. circuito abierto/timeout: el estado `pendiente` con `errorTransporte`) **o** "ya fue certificado directo y ya no está en cola". No se distinguen.
3. **Recuperar el CUFE de un documento certificado cuya respuesta se perdió NO es posible por API**: la consulta da 404 y un reenvío da `9022`. Pregunta abierta para MSL (ver abajo).

## Diseño ampliado de "Actualizar estado" (D2)
Un solo botón para `pendiente`: **"Actualizar estado"** (Mark/superadmin), con la misma infraestructura (lock, auditoría, throttle, transmisión registrada con `origen = 'consulta_manual'` y `operacion = consultar_estado`):
1. `GET` estado. Si MSL responde `CERTIFICADO` o `RECHAZADO` ⇒ `persistirResultado()` (CUFE/QR/XML o rechazo con su detalle).
2. Si responde `PENDIENTE` ⇒ "sigue en cola en MSL"; `persistirResultado()` vuelve a agendar el polling (ya lo hace para `pendiente`).
3. Si responde **404 "no encontrado en la cola"** ⇒ MSL no lo tiene en cola: o nunca lo recibió o ya lo procesó. Se **reenvía** (`recertificar`, POST): 200 ⇒ certificado; 422 ⇒ rechazado con motivo; **`9022` ⇒ mensaje especial: "MSL indica que ese número ya fue certificado (9022); el CUFE no se puede recuperar por API, contactar a MSL"**, sin duplicar nada ante la DGI (MSL/DGI bloquean el duplicado). Como el reenvío es la acción irreversible, la confirmación de la UI lo explica.
4. Si el GET falla por transporte (circuito abierto/timeout) ⇒ 200 con "MSL no disponible, reintenta más tarde" sin cambiar el estado ni reenviar.
5. Para `rechazado` / `error_configuracion` el botón sigue siendo **"Reintentar certificación"** (POST directo, sin GET previo).

## Seguimientos que salen de esto (no bloquean 1007)
- **Polling**: distinguir en `PollMslDocumentEstadoJob` el 404 "no está en la cola" del error de configuración real (401/403), para que un `pendiente` huérfano no quede colgado en silencio (puede resolverse con este mismo botón o con un ticket aparte).
- **Preguntas para MSL:** (a) ¿cuánto tiempo permanece un documento consultable en la cola tras certificarse? (b) ¿cómo se recupera el CUFE/XML de un documento certificado cuya respuesta se perdió? (c) ¿el 404 "no encontrado en la cola" es el comportamiento esperado para documentos certificados directo?

## Criterios de aceptación
1. Un documento `rechazado` o `error_configuracion`, corregida la causa, se recertifica desde la UI y queda `certificado` (verificado en el sandbox con un documento) y deja su registro en `fe_transmisiones` (`origen = reintento_manual`) y en `audit_logs`.
2. Imposible reintentar `certificado`, `pendiente`, `no_aplica`, facturas anuladas ni NC no aplicadas (409, sin llamar a MSL); sin permiso ⇒ 403.
3. Con `pac_ambiente = apagado` (o proveedor no wireado) el reintento no llama a MSL, explica por qué y **no modifica** `fe_estado`/`fe_detalle`.
4. Doble clic o dos usuarios a la vez: una sola llamada a MSL (test de concurrencia con el lock) y el segundo recibe 409.
5. Un rechazo que persiste devuelve 200 con el nuevo `fe_detalle`; `error_configuracion` nunca se muestra como "rechazado".
6. "Actualizar estado" sobre un `pendiente`: si MSL responde CERTIFICADO/RECHAZADO se persiste; si sigue en cola, se re-agenda el polling; si responde 404 "no encontrado en la cola", se reenvía y el resultado se persiste (200/422) o, ante `9022`, se muestra el mensaje especial sin sobrescribir el CUFE; si MSL no está disponible no se cambia nada. Solo Mark/superadmin, con lock, auditoría y registro en `fe_transmisiones` (`origen = consulta_manual`).
7. Imposible "Actualizar estado" sobre documentos que no están `pendiente`; con FE apagada no llama a MSL.
8. PHPStan L8, suite completa y vitest verdes; Pre-QA en dev con un documento rechazado (NC-0009 o NC-0012, que hoy siguen rechazadas) y, sin costo de documentos, "Actualizar estado" contra un `pendiente` simulado con `Http::fake` en los tests (el sandbox no permite provocar un 202).

**Esfuerzo:** ~1,5-2 días (backend 1-1,25; frontend 0,5-0,75). **Riesgo:** bajo-medio (acción fiscal manual sobre una integración externa; mitigado con lock, auditoría y permiso acotado). **Orden:** después de SCRUM-1008 (ya corregido) y antes de SCRUM-1009.
