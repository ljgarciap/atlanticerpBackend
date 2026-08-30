# Senior Review — SCRUM-400 (REQ-330, Guía de Entrega), fix del hallazgo CRÍTICO de Pre-QA (nombre de receptor)

**Fecha:** 2026-07-23
**Alcance:** 2 commits puntuales, uno por repo, re-revisión previa a volver a pasar por Pre-QA —
no un review de batch completo.
- `atlanticerp-backend` `b5ce631` — "fix(bodega): capture recipient name on signed delivery guide (SCRUM-329)"
- `atlanticerp-frontend` `fcb073d` — "fix(bodega): show recipient name in signed guide, pick latest guia_entrega document"

**Contexto:** Pre-QA 2026-07-23 encontró que el área de firma de la Guía de Entrega mostraba el
nombre del repartidor (`order.repartidor`) en vez de quien recibió el pedido, porque
`DeliveryService::registerSignedGuide()` nunca capturaba ese dato. Decisión de Luis: agregar
`received_by_name` a `order_documents` (no a `orders`) en vez de reusar `customer_name`, porque otra
persona en el destino puede ser quien firma.

## Veredicto: 🟢 Aprobado

Ambos commits implementan correctamente lo que se pidió, el contrato de campo entre backend y
frontend coincide byte a byte (`received_by_name`, ver hallazgo de mismatch ES/EN de batches
anteriores — no se repite acá), el bug relacionado que Frontend Dev encontró en el camino (tomar el
primer `guia_entrega` en vez del más reciente) es un bug real, confirmado, y el fix es correcto.
Tests nuevos cubren el caso feliz, el 422 por campo faltante, y el escenario de 2 documentos de la
misma categoría. Suite completa de Bodega (123/123), PHPStan Level 8 sin errores, suite de frontend
completa (460/460, incl. las 6 de `GuiaEntregaModal.test.tsx`) y `npm run build` sin errores.

Dos observaciones 🟡 (no bloqueantes) abajo — quedan como nota para quien re-corra Pre-QA, no
requieren otra vuelta de código antes de eso.

---

## Verificación por punto pedido

### 1. Corrección de la migración
`database/migrations/tenant/2026_07_23_100001_add_received_by_name_to_bodega_order_documents_table.php`
— `received_by_name` string, `nullable()->after('storage_key')`. Correcto: los documentos ya
existentes (guías sin firmar, guías firmadas antes de este fix) no tienen este dato y no deben
fallar ni forzarse a `''`. Nombre de columna en snake_case/inglés, consistente con el resto de la
tabla (`storage_key`, `uploaded_by`). Extiende `TenantSchemaMigration` igual que el resto de
migraciones de `bodega`, `down()` revierte limpio. Sin objeciones.

### 2. Validación
`OrderDeliveryController.php:107` — `'received_by_name' => ['required', 'string', 'max:255']`,
mismo patrón que todo otro campo "nombre" del proyecto (`UpdateProviderRequest`, `CreateUserRequest`,
`StoreProviderRequest`, etc. — ninguno de esos agrega `trim`/regex anti-whitespace tampoco). No es
una regresión introducida por este fix, es el estándar ya existente en el codebase — lo dejo como
observación 🟡, no blocker (ver abajo).

**Otros callers de `registerSignedGuide()`:** confirmado con
`grep -rn "registerSignedGuide"` — el único caller de producción es
`OrderDeliveryController::registerSignedGuide()` (ya actualizado con el 4º parámetro). Los 2 usos en
`tests/Feature/Bodega/DeliveryFlowTest.php` que llaman al endpoint HTTP (no al método directo) ya
fueron actualizados en el mismo commit para mandar `received_by_name`. No queda ningún caller roto
por el cambio de firma.

### 3. Contrato backend↔frontend
Backend expone `documents[].received_by_name` en `OrderBoardService::detail()` (línea 147).
Frontend lee `d.received_by_name` en `GuiaEntregaModal.tsx:157` y lo tipa en
`types/bodega.ts` como `OrderDocumentRef.received_by_name: string | null`. Nombre exacto, mismo
snake_case en ambos lados, tipo compatible (`string|null` en PHP ⇄ `string | null` en TS). Sin
mismatch.

### 4. Fix de "tomar el documento más reciente"
`GuiaEntregaModal.tsx:46-48`:
```ts
const guideDocuments = order?.documents.filter(d => d.tipo === 'guia_entrega') ?? []
const guideDocument = guideDocuments.length > 0
  ? guideDocuments.reduce((latest, d) => (d.id > latest.id ? d : latest))
  : null
```
Confirmé que `id` sí refleja el orden cronológico real acá: `OrderDocument` es append-only
(docblock de `OrderDocument.php:13-15`, `$timestamps = false`), y los dos únicos puntos de creación
de un `guia_entrega` son `OrderDocumentService::generateGuiaEntrega()` (al entrar a "Por
despachar") y `DeliveryService::registerSignedGuide()` (al firmar, dentro de `DB::transaction()`) —
ambos secuenciales para el mismo pedido, nunca concurrentes entre sí, y sin ningún flujo de
reproceso/import que pueda insertar un documento viejo con un id más alto que uno nuevo. `id`
(entero autoincremental de Postgres) es de hecho más robusto que `created_at` para este criterio
específico, porque `created_at` se setea con `now()` a nivel PHP y en teoría dos documentos podrían
compartir el mismo segundo si el flujo cambiara a futuro — `id` no tiene ese riesgo de empate. La
elección es correcta, aunque el comentario en el código no explica por qué se prefirió `id` sobre
`created_at` (ver 🟡 abajo).

### 5. Regresión — ¿otro lugar con el mismo bug potencial?
`grep` de `guia_entrega`/`documents.find`/`documents.filter` en todo `atlanticerp-frontend/src` y de
`TIPO_GUIA_ENTREGA`/`->documents` en `atlanticerp-backend/app/Modules/Bodega` — el único lugar que
consumía `documents` filtrando por `tipo` era `GuiaEntregaModal.tsx`, ya corregido. La descarga
(`downloadDocument`, backend, y `handleDownload()`, frontend) usa `guideDocument.id` — es decir,
tras el fix, descarga automáticamente el documento correcto (el firmado), lo cual también corrige un
bug de descarga que existía en silencio antes de este fix (se hubiera descargado el PDF sin firmar).
No hay otro `.find()`/`firstWhere()` sobre `order_documents` en el módulo Bodega que asuma "el
primero de esta categoría".

### 6. Tests
Backend (`DeliveryFlowTest.php`): caso feliz con `received_by_name` real (`'Ana Torres'`, no
vacío) + aserción explícita de que quedó en la fila correcta; caso `422` sin el campo. Cubre lo
pedido. **No cubre** el caso de string vacío/solo-espacios (ver 🟡 abajo — consistente con que
tampoco lo hace el resto del proyecto para campos de nombre).

Frontend (`GuiaEntregaModal.test.tsx`): el test de RN2 fue reescrito para separar
`repartidor`/`received_by_name` y afirma explícitamente `.not.toHaveTextContent('Juan Pérez')` —
buena práctica, ataca directamente el bug que Pre-QA encontró en vez de solo afirmar el caso feliz.
El segundo test nuevo cubre exactamente el escenario de 2 documentos `guia_entrega` (id 50 sin
firmar/sin nombre, id 99 firmado/con nombre) y confirma que se toma el de mayor id. Ambos casos
reales, no solo el fallback/null.

---

## 🟡 Observaciones (no bloqueantes)

1. **`required|string|max:255` no rechaza un nombre de solo espacios** (`" "` pasa `required` en
   Laravel porque no es una cadena vacía). Es el patrón ya establecido en todo el proyecto para
   campos de nombre — no es una regresión de este fix ni algo que deba resolver este ticket puntual.
   Dejar registrado para una futura pasada de hardening transversal (regla de negocio, no de este
   ticket) si Luis decide que vale la pena una `Rule` custom de "no-blank" compartida.

2. **El comentario de `GuiaEntregaModal.tsx:41-45` explica el "qué" (tomar el de mayor id) pero no
   el "por qué id y no created_at"** — dado que ambos campos viajan en el payload y `created_at` es
   la opción más "obvia" a primera lectura, una línea aclarando que `id` es más robusto ante posibles
   empates de timestamp evitaría que alguien "corrija" esto a `created_at` en el futuro pensando que
   es más semánticamente correcto. Sugerencia de comentario, no de código.

3. **No hay todavía UI de frontend que dispare `POST /register-signed-guide`** (confirmado:
   `bodegaApi.ts` no tiene ningún método hacia ese endpoint, y el comentario de
   `OrderCardTile.tsx:19-22` ya documenta que esa mutación queda "Próximamente", fuera de este
   batch). Esto es alcance pre-existente y ya documentado, no algo que este fix debiera cubrir — pero
   vale la pena que quien re-corra Pre-QA sepa que **no va a poder generar un `received_by_name` real
   navegando la app**; necesita sembrarlo vía tinker/API directa (mismo patrón que otros casos de
   este proyecto con backend adelantado al frontend, ver `MEMORY.md` sesión SCRUM-497) para
   verificar visualmente el campo en el modal.

---

## Resultado de tests/herramientas

- **Backend — `infra/test.sh --filter=DeliveryFlowTest`:** 13/13 tests, 51 assertions — OK
- **Backend — `infra/test.sh --filter=Bodega`** (suite completa del módulo, para descartar
  regresión en `OrderBoardService::detail()`/`cardPayload()`): 123/123 tests, 552 assertions — OK
- **Backend — `vendor/bin/phpstan analyse`** (Level 8): sin errores
- **Frontend — `npm run test -- --run`** (suite completa): 54 archivos, 460/460 tests — OK,
  incluye `GuiaEntregaModal.test.tsx` (6/6)
- **Frontend — `npm run build`** (`tsc && vite build`): sin errores (solo warning pre-existente de
  tamaño de chunk, no relacionado a este cambio)

## Archivos revisados completos (no solo el diff)

- `atlanticerp-backend/app/Modules/Bodega/Http/Controllers/OrderDeliveryController.php`
- `atlanticerp-backend/app/Modules/Bodega/Models/OrderDocument.php`
- `atlanticerp-backend/app/Modules/Bodega/Services/DeliveryService.php`
- `atlanticerp-backend/app/Modules/Bodega/Services/OrderBoardService.php`
- `atlanticerp-backend/database/migrations/tenant/2026_07_23_100001_add_received_by_name_to_bodega_order_documents_table.php`
- `atlanticerp-backend/tests/Feature/Bodega/DeliveryFlowTest.php`
- `atlanticerp-frontend/src/components/GuiaEntregaModal.tsx`
- `atlanticerp-frontend/src/components/GuiaEntregaModal.test.tsx`
- `atlanticerp-frontend/src/types/bodega.ts`
- `atlanticerp-frontend/src/api/bodegaApi.ts` (contexto — confirmar alcance del punto 🟡 #3)
- `atlanticerp-frontend/src/components/OrderCardTile.tsx` (contexto — confirmar alcance del punto 🟡 #3)

## Siguiente paso

Listo para volver a pasar por Pre-QA. No requiere otra vuelta de Senior Review antes de eso.
