# Pre-QA — Ficha técnica de producto (SCRUM-425/426, REQ-355/356)

**Fecha:** 2026-07-26
**Reviewer:** Pre-QA (sesión nueva — el intento anterior se cortó por límite de sesión de la API
antes de producir hallazgos, sin relación a esta feature)
**Alcance:** adversarial en vivo contra el build real servido por nginx en `localhost:8090`
(`npm run build` + docker-compose local, no el dev server de Vite) — Playwright CLI, curl directo
contra el backend, tinker para fixtures.
**Nota sobre este archivo:** `atlanticerp-backend/docs/` está gitignored completo en este repo — no se
puede commitear. Queda como referencia local; la fuente de verdad persistente es el comentario en
Jira.

---

## Resultado general

**PASA LIMPIO — sin hallazgos bloqueantes ni críticos.** Los 7 puntos del checklist verificados
explícitamente, más 2 comprobaciones adicionales (backend probado directo con curl más allá de lo
que la UI permite, y un producto con caracteres especiales creado vía API para confirmar
persistencia/renderizado exactos). 1 hallazgo INFORMATIVO de plataforma, no específico de este
ticket (ver abajo).

| # | Punto del checklist | Resultado |
|---|---|---|
| 1 | Camino feliz (crear con los 12 campos, ver ficha técnica) | ✅ Verificado |
| 2 | Bloqueo — falta 1 campo (frontend + backend directo) | ✅ Verificado |
| 3 | Producto legado (`technical_spec` NULL) | ✅ Verificado |
| 4 | Persistencia real tras F5 | ✅ Verificado |
| 5 | Caracteres especiales / >255 chars | ✅ Verificado |
| 6 | Sin iconografía de emoji (SCRUM-56) | ✅ Verificado |
| 7 | Modo restringido (Ventas & Diseño) | ✅ Verificado, es decisión de diseño confirmada |

---

## Detalle por punto

### 1. Camino feliz
Producto creado desde `Crear producto nuevo` con los 12 campos técnicos completos
(`PREQA-HAPPY-*`, management@illuminations.test). Al reabrir el detalle y pulsar "Ver ficha
técnica", los 12 valores mostrados coinciden exactamente con los ingresados, en el mismo orden y
etiquetas del mockup (Voltaje, Potencia, Tipo de casquillo / socket, Temperatura de color, Flujo
luminoso, Dimensiones, Peso, Material / acabado, Índice de protección (IP), Vida útil estimada,
Garantía, Certificaciones). Screenshot: `atlanticerp-frontend/e2e/.tmp/preqa425-04-ficha-tecnica.png`.

### 2. Bloqueo real
- **Frontend:** probado dejando vacío cada vez un campo distinto (`power` en medio de la lista,
  `certifications` al final) — en ambos casos el botón "Guardar" queda `disabled` (`canSubmit`
  revisa los 12 vía `.every()`, no hay forma de saltarse ninguno desde la UI).
- **Backend directo (curl con `Accept: application/json`, simulando axios del propio front):**
  POST a `/api/compras/inventory` con `technical_spec` sin `power` responde `422`:
  ```json
  {"message":"El campo Potencia es obligatorio.","errors":{"technical_spec.power":["El campo Potencia es obligatorio."]}}
  ```
  Mensaje específico (nombre del campo en español, "Potencia", no el genérico de Laravel con la
  ruta cruda `technical_spec.power`) — cumple REQ-356 RN1 tal cual lo exige el criterio de
  aceptación.

**Hallazgo INFORMATIVO (no bloqueante, no específico de este ticket):** el mismo POST sin el
header `Accept: application/json` (el que un `fetch()` "a mano" desde devtools NO agrega por
default, a diferencia del axios del propio frontend que sí lo fija globalmente en
`src/api/authApi.ts:8`) no devuelve JSON — Laravel responde `302 Found` con `Location:
http://localhost` (sin puerto, host roto). Confirmado que es comportamiento genérico de la
plataforma (Laravel decide JSON vs. redirect según `Accept`), no algo introducido por
`StoreInventoryProductRequest` — cualquier endpoint de la API se comporta igual ante un cliente sin
ese header. No afecta al criterio de aceptación (que habla de la app real, cuyo axios sí manda el
header), pero vale la pena que quede documentado por si alguna vez se integra un cliente externo
(ej. Zapier, un script) que no fije `Accept` explícitamente.

### 3. Producto legado
Sembrado directo en BD vía tinker (`PREQA-LEGACY-001`, `technical_spec: null`, simulando un
producto creado antes de esta feature). El modal de ficha técnica muestra
`"Ficha técnica no disponible para este producto."` — sin campos en blanco, sin `undefined`/`null`
crudo en pantalla, sin error de consola. Confirmado también en modo restringido (punto 7).
Screenshot: `preqa425-07-legacy-empty-state.png`.

### 4. Persistencia tras F5
Producto creado (`PREQA-RELOAD-*`), página recargada por completo (`page.reload()`, no solo
re-render de React), producto reabierto por búsqueda — los 12 valores siguen exactamente iguales.
Confirma que persiste en Postgres (`technical_spec` JSONB), no solo en estado de React en memoria.

### 5. Caracteres especiales / >255 chars
- Enviado un valor de 300 caracteres en `voltage`: el frontend no tiene límite de longitud en el
  input (no hay `maxLength`), así que el submit sí se dispara — el backend lo rechaza con `422` y
  el mensaje específico se muestra en el modal de creación: `"El campo Voltaje no debe ser mayor
  que 255 caracteres."` (mismo mecanismo `createProductErrorMessage()` que ya usa el resto de la
  pantalla). Ningún 500, ningún crash.
- Confirmado por separado (vía POST directo, ya que el caso anterior bloqueó el guardado completo
  antes de poder verificar el resto de campos) que acentos/ñ, comillas dobles, el guión largo "–" y
  el símbolo "®" persisten y se renderizan correctamente en la ficha técnica —
  `PREQA-SPECIALCHARS-1`: `"Ñandú potente"`, `"220V \"nominal\""`, `"3000K–6500K"`,
  `"Aluminio/plástico"`, `"CE, RoHS, UL®"` — todos legibles sin mojibake ni escape crudo visible en
  el modal (`ñ` etc. solo aparece en el JSON crudo de la API, correctamente decodificado por
  React en pantalla).

### 6. Sin iconografía de emoji (SCRUM-56)
Verificado el HTML del botón "Ver ficha técnica" y del modal completo contra un regex de rangos
Unicode de emoji — sin coincidencias. El botón usa `IcoFileText` (SVG outline, `stroke=currentColor`,
mismo set de `atlanticerp-frontend/src/components/icons/`), no un glyph 📄. Cumple la regla de cliente.

### 7. Modo restringido (Ventas & Diseño)
Con `designer@illuminations.test` (rol `vendedor_disenador`, nivel 4), el botón "Ver ficha técnica"
SÍ es visible en el detalle del producto — confirma la decisión de diseño documentada en el código
(`InventarioPage.tsx:309-312`, `InventoryController::format()` línea del comentario SCRUM-425). El
texto del modal de ficha técnica no contiene costo/margen/símbolo `$` en ningún campo — confirmado
que `InventoryController::format()` coloca `technical_spec` en `$base` (fuera del bloque
`if ($restricted) return $base;`), y separado del bloque de costo/margen que sí vive detrás de ese
gate. No hay leak de datos comerciales sensibles junto a los datos técnicos.

---

## Verificación técnica adicional

- Build de frontend regenerado (`npm run build`) antes de empezar — el build servido por nginx
  estaba desactualizado (09:11, anterior al merge de la feature a las 19:13).
- Playwright corrido contra `http://localhost:8090` (nginx + build real), no contra el dev server
  de Vite (`5173`), para validar el mismo artefacto que se desplegaría a `test.atlanticerp.ai`/prod.
- Spec nuevo: `atlanticerp-frontend/e2e/preqa-scrum425-426-technical-spec.spec.ts` (9 tests, 9/9 en
  verde). Cubre los 7 puntos del checklist más el sub-check de backend directo con y sin header
  `Accept`.
- Confirmado en código (no solo en runtime) que `technical_spec` nunca se gatea por rol —
  `app/Modules/Compras/Http/Controllers/InventoryController.php` función `format()`.
- Confirmado en código que `StoreInventoryProductRequest::attributes()` da nombre en español a los
  12 sub-campos (`technical_spec.voltage` → "Voltaje", etc.) — la única razón por la que el mensaje
  de error es específico y legible en vez del genérico de Laravel con la ruta cruda.

**Veredicto final: PASA — avanza de `Dev Testing` a `QA`.**
