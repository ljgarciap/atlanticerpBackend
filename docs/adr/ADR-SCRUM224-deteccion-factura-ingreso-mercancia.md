# ADR: Adjuntar factura y detección automática — Ingreso de Mercancía (SCRUM-224 / REQ-161)

**Status:** Approved con condiciones — Backend Dev puede implementar; ver checklist (algunas
condiciones bloquean el arranque, marcadas explícitamente, el resto bloquea el pase a QA)
**Fecha:** 2026-07-20 (diseño) / 2026-07-20 (revisión de seguridad, mismo día)
**Autor:** AI Architect / Cybersecurity

## Contexto

Paso 1 del wizard de Ingreso de Mercancía: Yirena adjunta la factura/proforma real que llegó del
proveedor, y el sistema debe **detectar automáticamente a qué orden de compra real corresponde y
qué productos esperados confirma** — precargando el Paso 1 (orden) y Paso 2 (líneas) del wizard ya
construido (`GoodsReceiptWizardPage.tsx`).

El propio Excel de requerimientos marca esto "CRÍTICO PARA TECNOLOGÍA" y dice explícitamente que
es **la misma capacidad técnica ya construida para SCRUM-211/REQ-148** (confirmación del
proveedor en Ver Órdenes) — no se debe reconstruir por separado. Este ADR diseña la extensión
mínima necesaria, reusando esa infraestructura tal como existe hoy en el código (confirmado
leyendo `AnthropicClient.php`, `ValidateProviderConfirmationJob.php`, `BaseAiJob.php`,
`PurchaseOrderDocumentController.php` — no asumido desde el ADR original).

Diferencia clave con SCRUM-211: ese Job **ya sabe contra qué orden comparar** (la orden vive en la
URL). Acá el problema es al revés — **no sabemos la orden todavía**, hay que encontrarla. Eso
cambia el diseño: no se le puede pedir al modelo que "elija un ID de orden real" directamente
(superficie de manipulación innecesaria); se le pide que **extraiga datos objetivos del
documento**, y el matching contra órdenes reales se hace de forma determinística en PHP.

## Infraestructura existente a reusar (confirmado leyendo el código)

- **`AnthropicClient::completeWithDocumentTool()`** (`app/Shared/Support/AnthropicClient.php:90-162`)
  — arma el content block (`document` para PDF, `image` para JPG/PNG/WEBP/GIF) + fuerza
  `tool_choice` sobre una tool definida, devuelve `tool_use.input` ya parseado. **Reusar tal
  cual, sin modificar**, para el camino PDF/imagen.
- **`BaseAiJob::runWithDocument()`** (`app/Modules/AI/Jobs/BaseAiJob.php:129-201`) — orquesta
  `AiAnalysis` (pending→running→completed/failed), `CircuitBreaker('anthropic', ...)`, reintentos
  `[5s,30s,120s]`, evento `AnalysisCompleted`. **Reusar tal cual.**
- **Modelo `AiAnalysis`** (`app/Modules/AI/Models/AiAnalysis.php`) — tabla `ai_analyses` ya
  soporta cualquier `entity_type`/`analysis_type` nuevo sin migración (`extra` es `jsonb` libre).
- **Patrón de audit log** (`ValidateProviderConfirmationJob.php:119-128`, constante
  `AuditService::AI_PROVIDER_CONFIRMATION_VALIDATED`) — replicar con una constante nueva.
- **Patrón de rate limit por entidad** (`PurchaseOrderDocumentController.php:100-106`,
  `RateLimiter::hit/tooManyAttempts` con key explícita, no `throttle:` de ruta).
- **`FuzzySearchService`** (`app/Modules/VentasDiseno/Services/FuzzySearchService.php`, ya usado
  por Inventario/Ver Órdenes) — reusar para el matching determinístico texto↔catálogo real.
- **`GoodsReceiptController::orderExpectedProducts()`** (ya existe) — fuente de verdad de qué
  productos espera una orden, para comparar contra lo extraído del documento.

## Decisión

### 1. El modelo EXTRAE datos objetivos del documento — nunca "elige" una orden real

La tool nueva (`report_invoice_data`, forzada vía `tool_choice` igual que SCRUM-211) solo pide
hechos observables en el documento, no un ID de la base de datos:

```json
{
  "name": "report_invoice_data",
  "input_schema": {
    "type": "object",
    "required": ["detected_order_reference", "line_items", "confidence"],
    "properties": {
      "detected_order_reference": {"type": ["string", "null"], "description": "Número de orden/PO tal como aparece en el documento, si aparece"},
      "detected_provider_name":   {"type": ["string", "null"]},
      "line_items": {
        "type": "array",
        "items": {
          "type": "object",
          "required": ["description", "quantity"],
          "properties": {
            "reference":   {"type": ["string", "null"]},
            "description": {"type": "string"},
            "quantity":    {"type": "number"},
            "unit_cost":   {"type": ["number", "null"]}
          }
        }
      },
      "confidence": {"type": "string", "enum": ["alta", "media", "baja"]}
    }
  }
}
```

**Por qué esto y no pasarle la lista de órdenes abiertas para que "elija":** si el modelo recibe
una lista de órdenes reales (IDs, proveedores, montos) como contexto y el documento adjunto
contiene texto manipulado, el peor caso de SCRUM-211 es `matches_order: true` incorrecto sobre
la ÚNICA orden ya conocida (bajo impacto, Yirena la revisa). Acá, si el modelo "elige" un ID de
una lista de órdenes reales, un documento manipulado podría inducirlo a apuntar a una orden
distinta de la real con más confianza aparente. Extraer solo hechos del documento (texto/números
visibles) y hacer el matching en PHP contra la base de datos real elimina esa superficie por
completo — el modelo nunca ve ni referencia IDs reales.

### 2. Matching determinístico en PHP, no en el prompt

Nuevo método (ej. `GoodsReceiptController::matchInvoiceToOrder()` o un service dedicado
`InvoiceOrderMatcher`), reusando el `FuzzySearchService` ya existente:

1. Si `detected_order_reference` no es null: buscar entre las órdenes elegibles (mismo criterio
   que `GET /compras/goods-receipts/eligible-orders`, REQ-162 — excluye "Recibido") una cuyo `id`
   coincida exacto o con fuzzy match numérico.
2. Si no hay match por referencia, y `detected_provider_name` no es null: fuzzy match contra
   proveedores reales (reusar `ProviderController`/`FuzzySearchService`); si resuelve a
   exactamente 1 proveedor con exactamente 1 orden elegible abierta, usar esa orden (heurística
   razonable — evitar ambigüedad si hay 2+ candidatas).
3. Si ninguna de las 2 resuelve a una única orden: `matched_order_id = null` — el wizard no
   precarga nada, se comporta exactamente como hoy (usuario elige la orden a mano). Esto ya
   cumple la RN de "detección parcial" en su caso extremo (detección nula).
4. Con la orden ya resuelta: para cada producto esperado real (`orderExpectedProducts()`), fuzzy
   match contra `line_items.description`/`reference` extraídos — los que coinciden quedan
   marcados `detected: true` con la cantidad extraída; los que no, quedan como hoy (precarga
   REQ-162 estándar, cantidad completa esperada, editable). **Nunca se agregan líneas que no
   estén en lo esperado de la orden** — mismo principio ya vigente en REQ-163 (el buscador de
   agregar producto ya acota a lo esperado).

### 3. Dos caminos de entrada según formato — mismo tool schema

RN pide PDF, imagen **y Excel/CSV**. `completeWithDocumentTool()` es vision-only (PDF/imagen) —
Excel/CSV no se puede mandar como content block de documento.

- **PDF/imagen** (`application/pdf`, `image/png|jpeg|webp|gif` — misma whitelist que
  `ValidateProviderConfirmationJob::SUPPORTED_MEDIA_TYPES`): `completeWithDocumentTool()` tal cual.
- **Excel/CSV** (`.xlsx/.xls/.csv`): extraer el contenido a texto plano (tabla) ANTES de llamar a
  Anthropic — requiere agregar `phpoffice/phpspreadsheet` como dependencia (a confirmar con
  Backend Dev si ya está en `composer.json`; si no, es una librería madura y ya usada en el
  ecosistema PHP para esto, no hace falta evaluar alternativas). El texto extraído se manda por
  un método nuevo y más chico, **`AnthropicClient::completeWithTool()`** (mismo mecanismo de
  `tool_choice` forzado que `completeWithDocumentTool()`, pero sin el content block binario —
  es quitarle una pieza al método existente, no construir uno nuevo desde cero).
- Mismo `report_invoice_data` tool schema para ambos caminos — el matching de PHP (punto 2) no
  necesita saber por cuál de los 2 caminos llegó el dato.

### 4. "Factura ya procesada" — detección de duplicado por hash

RN: si la factura ya fue procesada y la orden resultante ya no está disponible (llegó a
"Recibido"), avisar en vez de reprocesar. Guardar `document_hash` (SHA-256, mismo patrón que
SCRUM-211) en `AiAnalysis.extra` de cada análisis. Antes de lanzar un análisis nuevo: buscar el
`AiAnalysis` más reciente con el mismo `document_hash` y `analysis_type`; si existe y su orden
resultante ya no está en la lista de elegibles (o sea, llegó a "Recibido"), devolver el aviso sin
volver a llamar a Anthropic (ahorra costo real, no solo UX).

### 5. Nunca autoconfirma — ya garantizado por el wizard existente

El resultado solo **precarga** el Paso 1 (orden) y Paso 2 (líneas con badge "detectado de la
factura") de `GoodsReceiptWizardPage.tsx` — el usuario sigue teniendo que revisar y presionar
"Guardar ingreso" para que algo se persista (ya es así hoy, ningún cambio necesario en esa
garantía). Si la detección es PARCIAL (algunas líneas sin match), se muestra un banner explícito
invitando a revisar y completar con el buscador ya existente (REQ-163) — RN cumplida sin
construir nada nuevo del lado de "completar lo que falta".

### 6. Rate limit — por usuario, no por orden

A diferencia de SCRUM-211 (re-validar el mismo documento contra la misma orden ya conocida), acá
no hay una orden todavía cuando se sube el archivo — la key natural es `requested_by` (usuario),
no una entidad todavía inexistente. `RateLimiter::hit/tooManyAttempts` con key
`compras.goods_receipt_invoice.match:{userId}`, límite propuesto 10/hora (más holgado que las 5/hora
por orden de SCRUM-211, porque acá cada intento es potencialmente una factura distinta, no una
revalidación repetida del mismo documento) — número final a confirmar con Luis/Cybersecurity.

### 7. Modelo: Sonnet 5, mismo argumento que SCRUM-211

Comparar productos/cantidades de una factura real tiene el mismo perfil de riesgo económico que
SCRUM-211 (confundir un producto o cantidad = mercancía mal recibida). Mismo volumen bajo (una
factura por ingreso, no un job de alto throughput). Sonnet 5, no Haiku — mismo razonamiento, sin
repetir la discusión completa (ver ADR-SCRUM211).

## Alternativas consideradas

- **Pasarle al modelo la lista de órdenes abiertas y que elija un ID**: descartado, ver punto 1 —
  superficie de manipulación innecesaria cuando el matching determinístico en PHP la elimina
  por completo sin perder funcionalidad.
- **OCR tradicional (Textract/Tesseract) en vez de Claude vision**: mismo argumento que
  ADR-SCRUM211 — cada proveedor manda su propio formato de factura, un parser de reglas fijas no
  escala. Descartado por el mismo motivo ya documentado ahí.
- **No soportar Excel/CSV, solo PDF/imagen**: más simple, pero la RN del cliente lo pide
  explícitamente (mismos formatos que REQ-148) — no se descarta sin confirmar con Luis que el
  cliente acepta reducir el alcance. Queda como pregunta abierta abajo, no decisión tomada.

## Preguntas abiertas para Luis — RESUELTAS (2026-07-20)

1. ~~¿Excel/CSV es alcance real o fase 2?~~ → **Confirmado: alcance completo ahora** (PDF, imagen
   y Excel/CSV), sin recortar respecto a la RN original.
2. ~~Límite de rate-limit~~ → **Confirmado: 10/hora por usuario**, el número propuesto.

## Revisión de Cybersecurity (2026-07-20)

Revisé el diseño contra el mismo modelo de amenazas que ADR-SCRUM211 (prompt injection, contenido
de tercero externo) más las superficies nuevas que este diseño introduce (parsing de Excel/CSV,
matching determinístico, detección de duplicados). `docs/security/THREAT-MODEL-atlanticerp.md` sigue
sin existir en este checkout (mismo hallazgo que ADR-SCRUM211, docs/ fuera de git desde
2026-06-20) — no bloqueante acá tampoco, misma deuda de documentación ya anotada.

**El punto 1 del diseño (el modelo nunca ve ni referencia IDs reales, solo extrae hechos del
documento y el matching es determinístico en PHP) es una mitigación más fuerte que la de
SCRUM-211, no solo equivalente** — ahí el modelo comparaba contra UNA orden ya conocida
(superficie acotada); acá, si se le hubiera dado una lista de órdenes reales para "elegir", un
documento manipulado tendría una superficie de ataque más amplia (inducir la elección de una
orden real específica, no solo confirmar/negar la única conocida). Diseñarlo para que el modelo
nunca vea IDs reales elimina esa clase de riesgo por completo, no la mitiga parcialmente. Aprobado
sin cambios en ese punto.

Encontré 4 gaps que hay que cerrar — 1 bloquea el arranque de Backend Dev, los otros 3 bloquean
el pase a QA (mismo criterio que ADR-SCRUM211):

1. **(Bloquea arranque) Parsing de Excel/CSV es una superficie nueva, no solo una conversión de
   formato.** Un `.xlsx` es un ZIP con XML adentro — parsearlo con una librería que resuelva
   entidades externas (clase de vulnerabilidad XXE) o sin límite de expansión (zip bomb) es una
   superficie real, más aún viniendo de un archivo subido por control interno de Compras pero que
   en la práctica reenvía contenido de un proveedor externo sin revisar. **Requerido antes de
   escribir el parser:** confirmar que la versión de `phpoffice/phpspreadsheet` a usar tiene
   protección XXE por default (las versiones recientes sí, `libxml_disable_entity_loader` ya no
   hace falta desde PHP 8 pero hay que confirmar la config de carga del reader), y aplicar el
   mismo límite de tamaño ANTES de parsear (no después) que el punto 2 de abajo pide para
   PDF/imagen.
2. **Falta límite de tamaño/páginas específico para el camino de IA, distinto del límite genérico
   de upload (500MB, REQ-155).** 500MB es razonable para el checklist de documentos del envío,
   pero mandar un archivo de ese tamaño a Sonnet 5 es un costo real sin techo — SCRUM-211 nunca
   lo necesitó porque las facturas proforma típicas son 1-2 páginas y nadie lo notó, pero acá el
   endpoint es alcanzable apenas con `compras.edit`, sin que exista una orden todavía (barrera más
   baja que SCRUM-211, que ya requería una orden real elegida primero). **Requerido:** un límite
   propio para este endpoint (ej. 10MB o N páginas para PDF, N filas para Excel/CSV) — el número
   exacto queda a criterio de Backend Dev, no hace falta diseñarlo acá, pero debe existir y
   rechazar ANTES de leer el archivo completo en memoria.
3. **El hash de duplicado necesita ser consultable de verdad, no solo estar guardado.** El diseño
   original (punto 4) guarda `document_hash` dentro de `AiAnalysis.extra` (jsonb) — technically
   ahí está, pero buscar "¿existe ya un análisis con este hash?" sobre una columna jsonb sin
   índice implica escanear todos los análisis del tenant en cada subida. **Requerido:** agregar
   `document_hash` como columna propia e indexada en `ai_analyses` (migración simple, no rompe lo
   existente de SCRUM-211 — esa tabla sigue sin usar la columna si no aplica), o extraerla a una
   tabla/índice dedicado si se prefiere no tocar el schema de SCRUM-211.
4. **Rate limit de 10/hora por usuario — confirmar que también protege contra IDOR/enumeración
   de tenant, no solo costo.** Con `compras.edit` (no hay gate más fino), cualquier usuario con
   ese permiso puede subir facturas repetidamente tratando de "adivinar" a qué orden corresponde
   algo por prueba y error, aunque el matching determinístico (punto 2 del diseño) ya limita el
   impacto real (nunca expone datos de una orden que no matchea). No es bloqueante nuevo — el
   límite propuesto ya cubre esto razonablemente — pero **requerido:** loguear en audit_logs
   también los intentos que resultan en `matched_order_id: null` (no solo los matches), para
   poder detectar un patrón de abuso/prueba-y-error después si alguna vez hace falta investigar.

Sobre las 2 preguntas abiertas para Luis (alcance de Excel/CSV, número final de rate-limit): no
son decisiones de seguridad, quedan como estaban — de negocio/alcance, las resuelve Luis.

## Pendiente antes de pasar a Backend Dev
1. ~~Revisión de Cybersecurity~~ — hecho, ver arriba. 1 gap bloquea el arranque (parsing seguro
   de Excel/CSV), 3 bloquean el pase a QA (documentados en el checklist).
2. ~~Respuesta de Luis a las 2 preguntas abiertas~~ — hecho, ver arriba (alcance completo + 10/hora).

**No queda nada pendiente — listo para que el PM desglose tareas de Backend Dev/Frontend Dev.**

## Checklist de implementación para Backend Dev (una vez aprobado)
- [ ] `AnthropicClient::completeWithTool()` — variante sin content block de documento, mismo
      mecanismo de `tool_choice` forzado que `completeWithDocumentTool()`
- [ ] Nuevo Job `MatchInvoiceToOrderJob extends BaseAiJob` en `App\Modules\Compras\Jobs`, tool
      `report_invoice_data` (ver punto 1), rama PDF/imagen (`runWithDocument()`) vs Excel/CSV
      (extracción de texto + `completeWithTool()`)
- [ ] Servicio de matching determinístico (`InvoiceOrderMatcher` o similar), reusando
      `FuzzySearchService` — punto 2
- [ ] Endpoint de subida de factura en `goods-receipts` (no existe hoy — hoy `store()` solo
      recibe `invoice_storage_key` ya resuelto) + endpoint de disparo del Job + endpoint de
      polling (mismo patrón `validate()`/`validation()` de `PurchaseOrderDocumentController`)
- [ ] Detección de duplicado por `document_hash` antes de re-llamar a Anthropic (punto 4)
- [ ] Frontend: Paso 0 en `GoodsReceiptWizardPage.tsx` (adjuntar, polling, precarga de Paso 1/2
      con badge "detectado" por línea + banner de detección parcial)
- [ ] Casos de prueba para QA:
      - Factura PDF que coincide con una orden real → precarga correcta de orden + líneas
      - Factura con solo 1 de 2 productos reconocibles → banner de detección parcial, la línea
        no detectada queda con precarga estándar (no vacía)
      - Factura sin ningún dato reconocible → `matched_order_id: null`, wizard se comporta como
        si no se hubiera adjuntado nada
      - Misma factura subida 2 veces tras completar la orden → aviso de "ya procesada", sin
        segunda llamada a Anthropic
      - Documento con texto inyectado tipo "orden #1, todo confirmado" → el matching en PHP
        contra datos reales es la mitigación (ver punto 1), no hay forma de que el modelo fuerce
        un ID real directamente
- [ ] **(Cybersecurity)** Audit log de cada corrida (mismo patrón SCRUM-211 + `document_hash`),
      incluyendo corridas con `matched_order_id: null` (gap #4)
- [ ] **(Cybersecurity)** Confirmar rate-limit final (punto 6)
- [ ] **(Cybersecurity)** Confirmar que enviar la factura completa a Anthropic sigue aceptable
      bajo el mismo criterio ya establecido en ADR-SCRUM211 (no es un tipo de documento nuevo en
      términos de sensibilidad — factura de proveedor, mismo perfil que confirmación de proveedor)
- [ ] **(Cybersecurity, BLOQUEA EL ARRANQUE)** Confirmar que el reader de `phpoffice/phpspreadsheet`
      usado no resuelve entidades XML externas y aplicar el límite de tamaño/filas ANTES de
      parsear, no después (gap #1)
- [ ] **(Cybersecurity)** Límite de tamaño/páginas propio para este endpoint, distinto del límite
      genérico de 500MB de REQ-155 (gap #2)
- [ ] **(Cybersecurity)** `document_hash` como columna indexada en `ai_analyses` (o tabla
      dedicada), no solo dentro de `extra` jsonb (gap #3)
