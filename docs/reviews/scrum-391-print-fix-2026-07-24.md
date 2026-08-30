# Senior Review — SCRUM-391 (REQ-321), fix del hallazgo CRÍTICO de Visual Reviewer (falta imprimir consolidado de picking)

**Fecha:** 2026-07-24
**Alcance:** 1 commit puntual, `atlanticerp-frontend` — re-revisión previa a volver a pasar por Visual Reviewer/Pre-QA,
no un review de batch completo (el batch "A3-cierre", `b833e55..14f6aaf`, ya tuvo su propio 🟢 en la sesión anterior).
- `atlanticerp-frontend` `728403a` — "fix(bodega): add print action to consolidated picking modal (SCRUM-391)"

**Contexto:** Visual Reviewer encontró que el modal "Imprimir picking del día" (`ConsolidatedPickingModal.tsx`)
no tenía ninguna forma real de imprimir — el mockup (`modalWave`, `3A__Bodega_Pedidos.html`) sí lo pedía.
El fix agrega un botón "Imprimir" en el footer (`window.print()`) más una regla CSS "print only this element"
para que la hoja impresa muestre solo la tabla consolidada, no el sidebar/topbar/tablero de fondo.

## Veredicto: 🟢 Aprobado

Implementación correcta y sin regresiones. El patrón `body.printing-modal` + `.print-target` está bien
aislado del resto de los flujos de impresión existentes del proyecto (`QuotePage`, `ReportsPage`,
`QuoteViewerModal`), el cleanup del `useEffect` que agrega la clase al `body` es correcto (sin fuga si el
componente se desmonta), el botón reusa `IcoPrinter` ya existente (regla SCRUM-56, sin emoji) y el string
i18n nuevo sigue el patrón del resto del archivo (sin glyph embebido). Suite completa de frontend en verde
(519/519) y `npm run build` (`tsc && vite build`) sin errores.

Un solo punto 🟡 (no bloqueante, ver abajo): no se agregó un test nuevo para el botón de imprimir — de
hecho `ConsolidatedPickingModal` no tiene ningún archivo de test, ni antes ni después de este commit. No es
una regresión de este fix puntual, pero queda registrado para quien lo levante a futuro.

---

## Verificación por punto pedido

### 1. Patrón `body.printing-modal` + `print-target` — fugas de cleanup
`ConsolidatedPickingModal.tsx:59-62`:
```tsx
useEffect(() => {
  document.body.classList.add('printing-modal')
  return () => document.body.classList.remove('printing-modal')
}, [])
```
Dependencias `[]` — corre una sola vez al montar, limpia una sola vez al desmontar. Cubre tanto el cierre
normal (botón "Cerrar"/Escape, que desmonta el componente vía el `onClose` del padre) como cualquier
desmontaje por navegación — React garantiza que el cleanup de un `useEffect` corre siempre al desmontar el
componente, con o sin acción explícita del usuario. No hay ningún `return` temprano antes del `useEffect`
que pudiera saltarse el registro (el componente no tiene guard clauses tempranas). Sin objeciones.

### 2. Condición de disabled del botón "Imprimir"
`ConsolidatedPickingModal.tsx:155`: `disabled={selectedPickerId === null || items.length === 0}`.
Confirmé los 3 estados relevantes:
- Sin picker seleccionado (`selectedPickerId === null`) → deshabilitado. Correcto, no hay nada que imprimir.
- Picker seleccionado pero `isLoading` (fetch en curso, `consolidated` aún `undefined`) → `items` cae a
  `[]` por el fallback (`consolidated?.items ?? []`), por lo tanto también deshabilitado mientras carga.
  Comportamiento correcto y no buscado explícitamente por el ticket, pero evita el caso borde de imprimir
  una tabla vacía si el usuario hace clic muy rápido antes de que resuelva el query.
- Picker seleccionado, carga resuelta, `items.length === 0` (mensaje `emptyItems`) → deshabilitado.
  Correcto, coincide con el estado vacío ya renderizado en pantalla.

Sin objeciones — la condición cubre los 3 casos reales sin necesitar un estado derivado adicional.

### 3. Convención del proyecto (SCRUM-56, iconografía)
`IcoPrinter` ya existía en `src/components/icons/index.tsx:381` (no se creó ícono nuevo) — se agregó solo al
import (`IcoClose, IcoPrinter`). Reuso correcto del set propio, sin emoji, consistente con la regla de
cliente. El wrapper `className="!flex !items-center !gap-1.5"` en el `Button` no es un patrón ad-hoc: es
idéntico al ya usado en `PipelineCardModal.tsx:309` (`Button variant="secondary"` + `IcoPencil` + texto,
mismo SCRUM-78) — mismo criterio de icon+texto en botón secundario en todo el proyecto.

### 4. i18n — string nuevo
`bodega.json` (es/en), key `pedidos.pickingModal.print` → `"Imprimir"` / `"Print"`. Sigue el patrón de las
keys hermanas del mismo objeto (`emptyItems`, `ordersCount`, etc.): sin glyph embebido, el ícono se renderiza
aparte del texto en el JSX (`<IcoPrinter size={14} /> {t('bodega:pedidos.pickingModal.print')}`), tal como
exige la regla del `CLAUDE.md` de AtlanticERP. JSON válido en ambos locales (verificado con parseo directo), key
en la misma posición relativa en ambos archivos.

### 5. Tests
No hay test nuevo para el botón de imprimir. Confirmé que `ConsolidatedPickingModal` no tiene ningún archivo
`.test.tsx` — ni antes de este commit ni después (`find` sobre el repo solo encuentra el `.tsx` del
componente). El commit se apoya exclusivamente en la suite preexistente, que sigue en verde (519/519) porque
no ejercita este componente en absoluto. Ver 🟡 #1 abajo — no lo considero bloqueante dado que el cambio es
un botón de baja complejidad (disabled + `window.print()`, sin lógica de negocio ni transformación de datos),
pero sí una deuda de cobertura preexistente que este commit no empeora pero tampoco cierra.

### 6. Regresión — CSS `@media print` nuevo vs. flujos de impresión existentes
Este es el punto que más vale la pena documentar en detalle, porque un review superficial se lo salta:

Los otros 3 lugares del proyecto que imprimen (`QuotePage.tsx:494`, `ReportsPage.tsx:172`, y
`QuoteDocument.tsx` vía `QuoteViewerModal.tsx`) usan **solo** utilidades `print:` de Tailwind
(`print:hidden`, `print:border-0`, etc.), que compilan a `@media print { ... }` **global**, sin ningún
gate de clase en `body`. La regla nueva agregada en `src/index.css` es:
```css
@media print {
  body.printing-modal * { visibility: hidden; }
  body.printing-modal .print-target,
  body.printing-modal .print-target * { visibility: visible; }
  body.printing-modal .print-target { position: absolute; inset: 0; background: #fff; }
}
```
Los tres selectores están **calificados con `body.printing-modal`** — sin esa clase presente en el `body`,
ninguna de estas tres reglas hace match con nada, `visibility` no se toca en absoluto. Y esa clase solo se
agrega mientras `ConsolidatedPickingModal` está montado (punto 1 arriba). Como ninguno de los otros 3 flujos
de impresión (`QuotePage`, `ReportsPage`, `QuoteViewerModal`/`QuoteDocument`) agrega esa clase al `body`, la
regla nueva es un no-op para ellos — sus propias reglas `print:hidden` de Tailwind siguen siendo las únicas
que aplican, exactamente como antes de este commit. Confirmé además que `QuoteViewerModal.tsx:31` no tiene
botón de imprimir propio (oculta todo el modal con `print:hidden` — la impresión real de una cotización
sucede desde `QuotePage`, no desde el visor), así que tampoco hay una ruta donde ambos "mundos" (Tailwind
`print:` vs. `body.printing-modal`) puedan solaparse en el mismo momento de impresión.
Sin objeciones — el aislamiento es correcto y deliberado (el propio código documenta el porqué en el
comentario de `index.css`).

### 7. Build
`npm run build` (`tsc && vite build`): sin errores. Warning de tamaño de chunk (`>500 kB`) preexistente, no
relacionado a este cambio.

---

## 🟡 Observaciones (no bloqueantes)

1. **Sin test nuevo para el botón de imprimir**, y `ConsolidatedPickingModal` sigue sin ningún archivo de
   test propio (deuda preexistente, no introducida por este commit). Dado que el componente ya usa
   `data-testid` de forma consistente (`picker-chips`, `picking-items-table`, etc.), un test que cubra al
   menos la condición de disabled (sin picker → disabled; con picker + items → habilitado) sería barato de
   agregar si se decide cerrar esta deuda antes de promover el batch a `test`. No lo marco 🔴 porque el
   cambio es de baja complejidad (sin lógica de negocio) y no reduce cobertura que existiera antes.

---

## Resultado de tests/herramientas

- **Frontend — `npx vitest run`** (suite completa): 59 archivos, 519/519 tests — OK (no incluye ningún test
  de `ConsolidatedPickingModal`, que no tiene archivo de test)
- **Frontend — `npm run build`** (`tsc && vite build`): sin errores (solo warning preexistente de tamaño de
  chunk, no relacionado a este cambio)

## Archivos revisados completos (no solo el diff)

- `atlanticerp-frontend/src/components/ConsolidatedPickingModal.tsx`
- `atlanticerp-frontend/src/index.css`
- `atlanticerp-frontend/src/i18n/locales/es/bodega.json`
- `atlanticerp-frontend/src/i18n/locales/en/bodega.json`
- `atlanticerp-frontend/src/components/icons/index.tsx` (contexto — confirmar que `IcoPrinter` ya existía)
- `atlanticerp-frontend/src/components/PipelineCardModal.tsx` (contexto — confirmar precedente del patrón `!flex !items-center !gap-1.5`)
- `atlanticerp-frontend/src/components/QuoteDocument.tsx`, `QuoteViewerModal.tsx`, `src/pages/crm/ReportsPage.tsx`,
  `src/pages/ventas-diseno/QuotePage.tsx` (contexto — confirmar no-interferencia con los otros flujos de impresión)

## Siguiente paso

Listo para volver a pasar por Visual Reviewer (confirmar contra el mockup `modalWave`) y luego Pre-QA. No
requiere otra vuelta de Senior Review antes de eso.
