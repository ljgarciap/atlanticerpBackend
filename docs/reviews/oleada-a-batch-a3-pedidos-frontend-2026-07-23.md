# Senior Review — Oleada A / Batch A3 (SCRUM-329, "Pedidos: tablero frontend")

**Fecha:** 2026-07-23
**Repo revisado:** `atlanticerp-frontend`, branch `dev` (no pusheado) — 3 commits: `9c7a415` (scaffolding
API/types/hooks), `5b145ec` (feat: tablero completo), `bcb3a64` (tests). 11 tickets: SCRUM-375, 376,
379, 380, 381, 382, 397, 400, 402, 404, 405. Consume el backend de Batch A2
(`docs/reviews/oleada-a-batch-a2-pedidos-backend-2026-07-22.md`) más el fix puntual `945752a`
(mismo repo, `atlanticerp-backend`) que llegó **durante** esta sesión de frontend.

## Veredicto: 🔴 Requiere cambios (un blocker, acotado y de bajo esfuerzo)

El tablero, filtros, panel de carga, `OrderCardTile` y ambos modales están bien construidos,
correctamente gateados como solo-lectura, y sin ninguna violación de la regla de iconografía
(SCRUM-56). Build y suite completos, en verde (ver "Tests"). Hay **un blocker real y concreto**:
el fix de backend `945752a` (que llegó a mitad de esta tarea, ver más abajo) usa nombres de campo
distintos a los que el frontend ya tenía tipados/consumidos — el gap que Frontend Dev documentó
correctamente como "pendiente" en esta misma sesión **sigue sin cerrarse**, solo cambió de forma:
ya no es "el backend no manda el dato", es "el backend manda el dato con otro nombre".

---

## 🔴 Blocker — `customer_contact`/`delivery_address` nunca se van a pintar: mismatch de nombre de campo con el backend ya corregido

`atlanticerp-backend@945752a` (`OrderBoardService::detail()`) agrega los 3 campos que este batch pedía,
pero los serializa así:

```php
'contacto_cliente'  => $order->customer_contact,
'direccion_entrega' => $order->delivery_address,
...
'factory_reference'  => $product?->factory_reference,   // dentro de cada item
```

El frontend (`src/types/bodega.ts:207-213`, escrito ANTES de que existiera `945752a`) tipa y
consume los nombres del atributo Eloquent en inglés, no los nombres de serialización reales:

```ts
export interface OrderDetail extends OrderCard {
  ...
  customer_contact?: string | null   // el JSON real trae `contacto_cliente`
  delivery_address?: string | null   // el JSON real trae `direccion_entrega`
}
```

No hay ninguna capa de transformación snake↔camel ni ES↔EN en `bodegaApi.ts`/`authApi.ts` (ambos
hacen `.then(r => r.data)` directo, sin mapeo) — confirmado leyendo los dos archivos completos.
Consecuencia: `order.customer_contact` y `order.delivery_address` son `undefined` **siempre**, en
`OrderDetailModal.tsx:78,83` y en `GuiaEntregaModal.tsx:90,98` (este último además usa
`order.customer_contact` para "Nombre de contacto"). Las 3 UI ya construidas para mostrar este dato
van a seguir mostrando el fallback `"No disponible todavía (pendiente de backend)"` para siempre,
aunque el backend ya lo mande — el texto del fallback en sí ya quedó desactualizado.

`factory_reference` es distinto: el nombre de serialización SÍ coincide (`factory_reference` en
ambos lados), pero `OrderItemDetail` (`types/bodega.ts:181-192`) no declara el campo, y
`OrderDetailModal.tsx:127` no lo lee — la celda "Ref. fábrica" está *hardcodeada* a un guion
(`<td className="px-3 py-2 text-slate-400">—</td>`), no a `item.factory_reference ?? '—'`. Aunque
se agregue el campo al tipo, la tabla seguiría mostrando "—" sin tocar el JSX de esa celda.

**Por qué es bloqueante y no un simple "ya lo hará otro batch":** el gap ya estaba documentado
(correctamente) como pendiente de backend en el código de esta misma sesión — pero el backend ya
lo resolvió (`945752a`, mismo día). Dejarlo así no es "diferir a otro batch", es no cerrar un loop
que el propio Frontend Dev abrió y cuya mitad ya está lista del lado del servidor. Los 3 tests
nuevos (`OrderDetailModal.test.tsx`, `GuiaEntregaModal.test.tsx`) tampoco lo habrían detectado: en
ningún caso el fixture `detail()` popula `customer_contact`/`delivery_address`/`factory_reference`
con un valor real — solo se ejercita la rama de fallback, nunca la de éxito.

**Sugerencia de fix (bajo esfuerzo, sin tocar backend — sus tests ya fijan esos 2 nombres como
contrato):**
1. `types/bodega.ts` — renombrar en `OrderDetail`: `customer_contact` → `contacto_cliente`,
   `delivery_address` → `direccion_entrega` (mantenerlos opcionales está bien, es el nombre lo que
   está mal). Agregar `factory_reference: string | null` a `OrderItemDetail`.
2. `OrderDetailModal.tsx:78,83` y `GuiaEntregaModal.tsx:90,98` — actualizar las referencias a los
   nuevos nombres de campo.
3. `OrderDetailModal.tsx:127` — reemplazar la celda hardcodeada por
   `{item.factory_reference ?? '—'}`.
4. Añadir al menos un caso en `OrderDetailModal.test.tsx`/`GuiaEntregaModal.test.tsx` que popule
   estos 3 campos con un valor real y assert que se renderizan (no solo el camino de fallback) —
   es exactamente el tipo de test que habría atrapado este mismatch antes de este review.
5. Revisar de paso el copy de `detailModal.notAvailable` ("...pendiente de backend") — ya no
   describe la situación real una vez aplicado el fix de arriba; puede quedar en un
   "No disponible" genérico.

Ningún cambio de contrato de backend requerido — `945752a` ya está pusheado con tests que fijan
`contacto_cliente`/`direccion_entrega`/`factory_reference` como los nombres correctos; el ajuste es
enteramente del lado frontend.

---

## Verificaciones puntuales pedidas

**`OrderCardTile` — branching por etapa (7 stages):** bien factorizado. `STAGES_WITH_GUIDE`
(lista de 3 etapas) + `isTerminal` (bool) + `actionLabelKey()` (una función de 2 líneas que
resuelve el único caso irregular, "Por despachar" con/sin repartidor) — nada de un condicional
gigante. El botón de acción real queda `disabled` con `title="comingSoon"` en las 6 etapas no
terminales, consistente con el alcance del batch.

**`GuiaEntregaModal` — sin precios, detección de firma:**
- Ninguna columna de la tabla de artículos (`publicRef`/`item`/`quantity`) ni de los campos de
  encabezado incluye monto/precio — confirmado leyendo el archivo completo. Hay un disclaimer
  explícito (`guideModal.noPrices`) y un test (`GuiaEntregaModal.test.tsx:65-71`) que además
  verifica que no aparezca ningún `$` en el DOM renderizado.
- La detección de "ya firmada" (`order.stage === 'entregado'`) **no es frágil** — verificado
  contra `DeliveryService::registerSignedGuide()` (`atlanticerp-backend`,
  `app/Modules/Bodega/Services/DeliveryService.php:386-406`): esa es la ÚNICA transición que mueve
  `Despachado → Entregado`, y lo hace en la misma transacción que sube el documento firmado y setea
  `courier_id` — no existe un campo `signed_at` ni ningún otro estado independiente que sea más
  "correcto" de leer. El docblock del componente (líneas 14-27) documenta exactamente este
  razonamiento — correcto y sin fragilidad real.

**Reuso de `useBodega.ts` / TanStack Query:** sin duplicación. `useOrdersBoard`,
`useOrdersBoardOptions`, `useAssistantLoad`, `useOrderDetail`, `useOrderDocumentDownloadUrl` siguen
el mismo patrón `useQuery`/`useMutation` que el resto del archivo (Warehouses/Kardex/Adjustment
Requests) — mismos nombres de `queryKey`, mismo criterio de `enabled`/`staleTime`. No hay un
`useEffect`+`fetch` paralelo en ningún componente nuevo.

**Iconografía (SCRUM-56):** grep sobre los 19 archivos del diff (`\p{Emoji}` en rangos
`U+1F300-1FAFF`, `U+2600-27BF`, `U+2B00-2BFF`) no encontró ningún emoji real usado como icono. Los
únicos hits fueron el carácter `→` (flecha tipográfica, no emoji) en 2 comentarios de código y en
la traducción `viewItems`/`hideItems` ("Ver detalle de artículos →") — no es un icono de UI, es
parte del string y no compite con la regla (que prohíbe emoji tipo 🚀🔥✨, no glifos tipográficos).
Todos los iconos nuevos (`IcoPrinter`, `IcoTruck`) y reusados (`IcoAlertTriangle`, `IcoCheck`,
`IcoChevronDown/Right`, `IcoDownload`, `IcoClose`) son SVG outline `stroke=currentColor`,
consistentes con el resto de `components/icons/`.

---

## 🟡 Sugerencias (no bloqueantes)

1. **`IcoTruck` sin usar** (`components/icons/index.tsx`) — se agregó en este batch pero ningún
   componente lo importa (`grep` confirma cero usos fuera de su propia definición). No es dañino,
   pero es código muerto; usarlo en el botón "Asignar Repartidor"/"Despachar" de
   `OrderCardTile` (hoy sin icono) sería una mejora visual natural, o si no, quitarlo.
2. **`GuiaEntregaModal` — Teléfono/Correo de contacto siempre en blanco.** El ticket pide 3 campos
   separados (nombre/teléfono/correo) pero `customer_contact` en el modelo es un solo string ya
   formateado (`OrderService::formatContact()`, ver docblock del componente) — por diseño, los
   campos "Teléfono"/"Correo" están hardcodeados a `value={null}` y van a mostrar "No disponible"
   siempre, no solo hasta que el backend "alcance". Documentado y es una decisión consciente, pero
   vale la pena que quede explícito para Luis/QA que esos 2 campos son estructuralmente
   inalcanzables con el modelo actual (no un "todavía"), para que no se levante como hallazgo de
   QA por sorpresa.
3. **`AssistantLoadPanel` — umbral de desbalance (65%) hardcodeado como constante en componente**
   (`PedidosPage.tsx:229`). El docblock inline ya anticipa la regla global de no-hardcodeo de
   umbrales de negocio y argumenta por qué no aplica acá (no hay número de aceptación en el
   ticket, es un juicio de UX de esta sesión, no un valor de negocio parametrizable) — de acuerdo
   con el razonamiento, pero al no ser un criterio de aceptación firmado por Luis, cualquier
   ajuste futuro de este número requiere volver a tocar código; si en algún momento se convierte
   en un criterio real de negocio, debe migrar a la tabla paramétrica como cualquier otro umbral.
4. **Filtro de Vendedor solo client-side** (`PedidosPage.tsx:50-53`) — correctamente documentado
   como decisión deliberada (el board no expone `seller_id`), pero vale una nota de backlog: si el
   volumen de pedidos crece, este filtro deja de reflejar "AND real contra todo el universo" y pasa
   a ser "AND sobre la página ya filtrada por el backend" — hoy es lo mismo porque `/bodega/orders`
   no pagina (trae todo), pero si algún día se pagina, este filtro se rompe silenciosamente.

## 🟢 Aprobado

- Header con contador real (`board?.total`) y botón "Status de pedidos" correctamente stubbeado
  como placeholder navegable (no un 404, no un botón muerto) — `OrderStatusPage.tsx` existe, está
  ruteado y gateado con el mismo permiso `bodega.read`.
- Picking del día y las 6 acciones de transición de etapa: todas visibles, correctamente
  etiquetadas por etapa, y `disabled` con tooltip "Próximamente" — ninguna cablea a una acción
  falsa o rota. Verificado componente por componente (`OrderCardTile`, botón "Imprimir picking del
  día" en `PedidosPage`).
- Filtros + 4 chips combinables (AND real contra backend para search/asistente/cliente/chip,
  vendedor client-side por la limitación documentada arriba) con test dedicado que verifica la
  combinación en la misma llamada (`PedidosPage.test.tsx:94-104`).
- Tabla de items del modal de detalle arranca colapsada (RN1), verificado con test explícito de
  toggle.
- `i18n` — paridad completa `en`/`es` en `bodega.json` (verificado programáticamente, mismas
  claves en ambos árboles) y en `common.json` (`nav.pedidos`).

---

## Tests

Corridos localmente contra el working tree en `dev` (no pusheado):

```
npm run build      → tsc (typecheck) + vite build — limpio, sin errores. Bundle >500kB
                       (warning preexistente de code-splitting, no de este batch).
npx vitest run     → 54 test files, 457 tests — TODOS pasan.
```

457 coincide exactamente con lo reclamado (435 antes + 22 nuevos: 6 `PedidosPage.test.tsx` + 9
`OrderCardTile.test.tsx` + 3 `OrderDetailModal.test.tsx` + 4 `GuiaEntregaModal.test.tsx` = 22).

---

## Siguiente paso

PM reasigna a Frontend Dev el blocker de arriba (paso 1-5 de la sugerencia de fix) — es un cambio
acotado a nombres de campo + una celda de tabla + 1-2 tests nuevos, no un rediseño. Con eso
corregido, este batch queda listo para Pre-QA.

---

## Re-revisión (2026-07-23, commit ce5dc8e)

Verificación independiente del fix `ce5dc8e` ("fix(bodega): align field names with backend
contract for contact/address/factory ref"), sin confiar en el mensaje de commit — diff completo
leído (`git show ce5dc8e`) más el estado actual completo de los 5 archivos tocados.

**1. `src/types/bodega.ts`** — `OrderDetail` (líneas 210-214) ahora declara
`contacto_cliente: string | null` y `direccion_entrega: string | null` (ya no opcionales `?:`,
pasan a requeridos — correcto, el backend siempre los serializa, sea con valor o `null`).
`OrderItemDetail` (línea 185) agrega `factory_reference: string | null`. Los 3 nombres coinciden
byte a byte con lo que serializa `OrderBoardService::detail()` en `945752a`. El docblock
(líneas 203-208) se actualizó para reflejar la realidad (nombres en español, consistente con el
resto del payload) en vez de seguir describiendo un gap ya cerrado.

**2. `OrderDetailModal.tsx`** — línea 71 lee `order.direccion_entrega`, línea 76 lee
`order.contacto_cliente`; ninguna referencia residual a los nombres viejos. La celda antes
hardcodeada a `—` (línea 119) ahora es `{item.factory_reference ?? '—'}` — confirmado que ya no es
un literal, lee el campo real con fallback correcto. Docblock (líneas 13-19) actualizado, ya no
describe un gap inexistente.

**3. `GuiaEntregaModal.tsx`** — línea 87 lee `order.direccion_entrega`, línea 95 lee
`order.contacto_cliente`. Docblock (líneas 18-23) también actualizado, y deja explícita la
limitación real y permanente (Teléfono/Correo separados no existen, `contacto_cliente` es un solo
string ya formateado por `OrderService::formatContact()`) — coincide con la sugerencia 🟡 #2 del
primer pase, que pedía justamente dejar esto explícito.

**4. Grep de nombres viejos, repo completo** (`customer_contact`/`delivery_address`, `src/**/*.ts*`):
el único hit de `customer_contact` es el propio docblock de `bodega.ts:204`, y ahí es intencional —
describe el atributo Eloquent real del lado backend (`Order.customer_contact`), no un campo que el
frontend consuma; no es un leftover. Todos los demás hits de `delivery_address` están en
`src/types/ventasDiseno.ts` y sus componentes (`ClientDetailModal.tsx`, `CreateClientModal.tsx` y
sus tests) — módulo Ventas & Diseño, campo propio y no relacionado a Bodega/Pedidos, confirmado
leyendo el contexto de cada hit (es el campo de dirección de un sub-cliente, no del pedido). Cero
referencias residuales relevantes.

**5. Tests nuevos — populan valores reales, no solo el fallback:**
- `OrderDetailModal.test.tsx` — nuevo caso "REQ-332 — muestra contacto, dirección de entrega y
  referencia de fábrica cuando el backend los entrega": fixture con
  `contacto_cliente: 'Juan Pérez (6000-1234)'`, `direccion_entrega: 'Av. Balboa, Ciudad de Panamá'`
  y `items[0].factory_reference: 'FAB-9001'` (no `null`). Los 3 `expect(screen.getByText(...))`
  assertions verifican que el valor real aparece en el DOM (incluye expandir la tabla de items con
  `fireEvent.click(screen.getByTestId('toggle-items'))` antes de buscar `'FAB-9001'` — correcto,
  la tabla arranca colapsada por RN1). El fixture base (`detail()`) sigue poniendo estos 3 campos
  en `null`/vacío para no romper los tests de fallback existentes — separación limpia entre el
  camino "sin dato" (ya cubierto antes) y el camino "con dato" (nuevo, antes ausente).
- `GuiaEntregaModal.test.tsx` — caso equivalente "REQ-330 — muestra contacto y dirección de entrega
  cuando el backend los entrega", mismos valores de fixture, 2 assertions de texto real en pantalla.
- Ambos casos son el test exacto que el primer pase señaló como faltante (punto 4 de la sugerencia
  de fix) — cierran el gap real: antes ningún test ejercitaba la rama de éxito.

**6. Verificación ejecutada yo mismo, desde `atlanticerp-frontend/`:**
```
npx tsc --noEmit    → limpio, sin errores.
npx vitest run      → 54 test files, 459 tests — TODOS pasan (459/459, +2 sobre los 457 del
                       primer pase, exactamente los 2 casos nuevos de los puntos 5a/5b).
npm run build       → tsc + vite build, limpio. Mismo warning preexistente de chunk >500kB
                       (909.54 kB gzip), no relacionado a este fix, ya señalado en el primer pase.
```

No se encontró ningún hallazgo nuevo. El fix cierra el blocker del primer pase de forma completa:
nombres de tipo alineados exactamente con el contrato real de `945752a`, ambos modales leyendo
los campos correctos, celda hardcodeada reemplazada por el valor real con fallback, y cobertura de
test que ejercita la rama de éxito (no solo fallback) en los dos componentes.

### Veredicto: 🟢 Aprobado

Listo para Pre-QA.
