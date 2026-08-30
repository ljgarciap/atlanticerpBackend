# Senior Review — Batch A4, "Status de Pedidos" (SCRUM-407→413), frontend

**Fecha:** 2026-07-23
**Alcance:** `atlanticerp-frontend`, rama `dev` (ya pusheada a `origin/dev`), 2 commits:
- `af9fc65` — "feat(bodega): build Order Status screen (SCRUM-407→413, Batch A4)" (Frontend Dev,
  nunca revisado)
- `5736702` — "fix(bodega): allow ventas_diseno.read into the shared Order Status route" (fix
  propio del Arquitecto/Senior Reviewer — el gate de la ruta solo aceptaba `bodega.read`,
  bloqueando a Ventas & Diseño pese a que REQ-342 exige acceso compartido)

Contrato de backend ya aprobado (`docs/reviews/batch-a4-status-pedidos-backend-2026-07-23.md` en
`atlanticerp-backend`, 🟢). Archivos nuevos/modificados leídos completos, no solo diff:
`src/pages/OrderStatusPage.tsx`, `src/pages/OrderStatusPage.test.tsx`,
`src/components/OrderStatusDetailModal.tsx`, `src/components/OrderStatusDetailModal.test.tsx`,
`src/types/bodega.ts`, `src/api/bodegaApi.ts`, `src/hooks/useBodega.ts`,
`src/i18n/locales/{es,en}/bodega.json`, `src/App.tsx`, `src/pages/PedidosPage.tsx`,
`src/pages/ventas-diseno/PipelinePage.tsx`, `src/i18n/locales/{es,en}/ventasDiseno.json`.

## Veredicto: 🟢 Aprobado

Sin blockers. 3 suggestions menores, ninguna condición para pasar a Pre-QA.

---

## Resultado real de verificación (corrido yo mismo, no asumido del reporte previo)

**`npm run test -- --run` (suite completa):**
```
Test Files  56 passed (56)
     Tests  472 passed (472)
```
Incluye los tests nuevos de `OrderStatusPage.test.tsx` (5) y `OrderStatusDetailModal.test.tsx` (7).

**`npx tsc --noEmit`:** sin salida — limpio.

**`npm run build`:** `tsc && vite build` completa sin errores (solo el warning preexistente de
tamaño de chunk >500kB, no relacionado con este batch).

---

## Foco de revisión — resultado punto por punto

### 1. Nombres de campo — coinciden exactamente con el contrato

`src/types/bodega.ts` (`OrderStatusRow`, `OrderStatusItem`, `OrderStatusDetail`,
`OrderStatusDocument`) usa literalmente los mismos nombres del contrato de backend:
`order_id`, `order_number`, `quote_number`, `subcliente`, `fecha_pedido`, `vendedor`,
`proyecto`, `disenador`, `cliente`, `contacto`, `telefono`, `fecha_estatus`,
`catalog_product_id`, `reference`, `factory_reference`, `description`, `imagen`,
`solicitada`, `entregado`, `pendiente`, `document_id`, `url` — sin traducir ni renombrar
ningún campo. `bodegaApi.ts`/`useBodega.ts` pasan estos tipos tal cual, sin mapeo intermedio.
No encontré ningún mismatch ES/EN esta vez (el bug real de este mismo batch, según memoria,
ya estaba resuelto en el commit revisado).

Nota sin hallazgo: `factory_reference` está tipado pero no se renderiza en la tabla de ítems del
modal (solo se muestra `reference` bajo la etiqueta "Referencia pública"). No es una omisión de
contrato — el campo está disponible en el tipo por si se necesita más adelante — y no hay
ningún criterio de aceptación visible que exija mostrarlo en pantalla, así que no lo marco como
hallazgo.

### 2. Fix de permisos — `RequirePermission`

`src/App.tsx:84-91` — la firma cambió de `permission: string` a `permission: string | string[]`,
normalizando internamente a array (`Array.isArray(permission) ? permission : [permission]`) y
evaluando `required.some(p => user.permissions.includes(p))` (OR, no AND — correcto para el caso
pedido). Confirmé que **todos** los demás 30 usos de `RequirePermission` en el archivo siguen
pasando un string simple sin ningún cambio de comportamiento — el `Array.isArray` los deja
funcionando exactamente igual que antes (el string se envuelve en un array de 1 elemento y el
`.some()` sobre 1 elemento es equivalente al `.includes()` anterior). La ruta
`/bodega/pedidos/status` (línea 255) ahora pasa `['bodega.read', 'ventas_diseno.read']` — Bodega
o Ventas & Diseño entran, cualquier otro perfil (sin `superadmin.all`) sigue redirigido a
`/dashboard`.

### 3. Botón de entrada en `PipelinePage.tsx` (Ventas & Diseño)

`src/pages/ventas-diseno/PipelinePage.tsx:83-88` navega a la misma ruta
(`/bodega/pedidos/status`) que el botón ya existente en `PedidosPage.tsx:92-94`. Usa su propia
clave i18n `ventasDiseno:kanban.actions.orderStatus` ("Status de pedidos"/"Order status" en
`ventasDiseno.json`), distinta de `bodega:pedidos.actions.orderStatus` que usa el botón de
Bodega — no hay reuso accidental de traducción entre módulos.

### 4. Solo lectura — confirmado en ambos componentes

`OrderStatusPage.tsx`: la tabla de listado solo tiene `onClick` para abrir el modal de detalle,
sin ningún botón de edición/eliminación. `OrderStatusDetailModal.tsx`: los únicos controles son
"Cerrar" y "Ver/Descargar" (que dispara la generación del documento, no una mutación de datos del
pedido). Test explícito lo cubre: `OrderStatusDetailModal.test.tsx` — "no muestra ningún control
de edición (solo lectura para todos los perfiles)" verifica ausencia de `role="textbox"` y de
texto "guardar"/"editar".

### 5. Regla SCRUM-56 — sin emoji

`IcoChevronLeft`, `IcoDownload`, `IcoImage`, `IcoClose` son los 4 íconos usados en las 2 pantallas
nuevas, los 4 ya existentes en `src/components/icons/index.tsx` (SVG outline, `stroke=currentColor`)
— no se agregó ningún ícono nuevo ni se importó ninguna librería externa. Revisé también
`bodega.json` (es/en) — ningún string de i18n lleva un glyph embebido; el emoji 📄 que traía el
texto original del ticket de Jira no aparece en ningún lado del código.

### 6. Manejo de `pendiente` — Escenario 2 (pedido completo) cubierto con test real

`OrderStatusDetailModal.tsx` renderiza `item.pendiente` tal cual llega del backend (ya consolidado,
sin recalcular). El test "pedido completo → pendiente 0 en todas las líneas, sin ningún estado
visual especial" (`OrderStatusDetailModal.test.tsx`) arma 2 ítems con `solicitada === entregado`
(`pendiente: 0`) y afirma que las 2 celdas `data-testid="order-status-item-pending"` muestran "0"
— no es solo el caso general con residuo pendiente, hay un test dedicado al escenario de entrega
completa.

### 7. Documento — modelado como mutation, con efecto lateral

`useOrderStatusDocument()` (`useBodega.ts`) usa `useMutation`, no `useQuery` — comentario explícito
en el código: "GET con efecto lateral (genera el documento en el backend) — se consume como
mutation, mismo criterio que `documentDownloadUrl`". `OrderStatusDetailModal.tsx` dispara
`documentMutation.mutate(orderId, { onSuccess: ({ url }) => window.open(url, '_blank', ...) })`
al clic del botón "Ver/Descargar" — nunca automático, nunca cacheado como query. Test
"el botón Ver/Descargar dispara la generación del documento con el order_id correcto y abre la
URL" lo verifica end-to-end contra el mock del hook.

### 8. Tests/tsc/build corridos de cero

Ver sección de arriba — los 3 corrieron limpios sobre el estado actual del repo (incluyendo mi
propio fix de permisos encima del commit de Frontend Dev).

---

## 🟡 Suggestions (no bloqueantes)

**S1 — Sin test unitario/e2e dedicado para el gate `RequirePermission` en sí.** El fix de
permisos (`5736702`) corrige exactamente el tipo de bug que en este proyecto motivó la regla de
"un gate que se rompió una vez se promueve a e2e permanente" (`feedback_e2e_no_desechar.md`) —
acá se atrapó en review antes de llegar a QA/producción, pero no hay ningún test (unitario de
`App.tsx` ni e2e de Playwright) que afirme "un usuario con solo `ventas_diseno.read` llega a
`/bodega/pedidos/status`" ni "un usuario con ninguno de los 2 permisos es redirigido a
`/dashboard`". No es una regresión de cobertura (no existía ningún test de `App.tsx`/rutas antes
de este batch tampoco), pero dado que este mismo commit es la prueba de que el gate se puede
romper fácilmente al copiar un patrón de un solo permiso, recomendaría agregar un test antes de
que se toque `RequirePermission` de nuevo.

**S2 — Buscador sin debounce.** `OrderStatusPage.tsx` dispara `useOrderStatusList(search)` en
cada `onChange` del input, sin debounce — mismo patrón exacto que `PedidosPage.tsx` (tampoco lo
tiene), así que no es una regresión de este batch ni una desviación del precedente ya establecido.
Queda como la misma deuda transversal de UX ya conocida, no específica de esta pantalla.

**S3 — `factory_reference` tipado pero no mostrado en el modal.** Ver nota en el punto 1 — no es
un hallazgo, solo dejarlo anotado por si el ticket original (Jira) esperaba mostrarlo también
junto a "Referencia pública" y no se verificó contra el adjunto/mockup en esta revisión (el foco
pedido era nombres de campo, no cobertura de UI contra el mockup completo).

---

## Notas adicionales verificadas (sin hallazgos)

- `OrderStatusPage.tsx` está intencionalmente fuera del `Sidebar` (documentado en el docblock del
  archivo) — se entra solo desde los botones de `PedidosPage`/`PipelinePage`, consistente con el
  diseño aprobado.
- Los 30 usos preexistentes de `RequirePermission` con `permission` string simple siguen
  compilando y pasando tsc sin cambios de tipo — no hace falta migrar ninguno a array.
- El commit `2627102` ("test(bodega): ignore Playwright scratch output...") queda fuera de alcance
  de este review — no toca ningún archivo de Batch A4, es la promoción de un e2e de un batch
  anterior (SCRUM-400).

## Siguiente paso

Sin blockers — puede pasar a Pre-QA.
