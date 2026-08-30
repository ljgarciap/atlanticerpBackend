# Pre-QA — Oleada A / Batch A3 (SCRUM-329, "Pedidos: tablero frontend")

**Fecha:** 2026-07-23
**Repos:** `atlanticerp-backend` (`dev`, no pusheado, `dcde64d..1164c82`) + `atlanticerp-frontend` (`dev`, no
pusheado, `9c7a415..ce5dc8e`). Ambos con 2 pasadas de Senior Review, ambos 🟢 Aprobado — ver
`docs/reviews/oleada-a-batch-a3-pedidos-backend-2026-07-23.md` y
`oleada-a-batch-a3-pedidos-frontend-2026-07-23.md`.

**Primera pasada de punta a punta con navegador real** (Senior Review fue solo lectura de código +
tests automatizados). Entorno: Docker local (`infra-*`, puerto 8090) + Vite dev server (5173),
login real vía `POST /api/auth/login` y navegador (Playwright), 10 pedidos sembrados a mano vía
`tinker` cubriendo urgente/atrasado/sin stock/familia dividida/factura lista-pendiente/guía
firmada-sin firmar/contacto y dirección poblados/ETA de proveedor. Suite promovida a
`atlanticerp-frontend/e2e/preqa-scrum329-batcha3-pedidos.spec.ts` (10/10 verde).

## Paso 0 — permisos

Los 16 tickets corren detrás de `permission:bodega.read` (lectura, todo el batch es solo-lectura).
No hay `extra_permissions` angostos involucrados — es permiso de rol (`bodega.view`/`bodega.read`),
ya concedido de verdad vía `RoleBackfillSeeder`/roster real (`CoreUserSeeder`), confirmado con login
real de Esteban (`lider_bodega`, `almacen@atlantic.com.pa`) trayendo `bodega.view/read/view_team/edit`
en el JWT. Nada que agregar a `SpecialPermissionSeeder`.

## Veredicto global: 🔴 Rebota — 1 hallazgo CRÍTICO real en SCRUM-400, resto limpio

14 de los 16 tickets pasan limpio. **SCRUM-400 (Guía de Entrega) tiene un hallazgo CRÍTICO
confirmado en navegador real** que viola el criterio de aceptación literal del ticket (Escenario 2).
No es un bug de este batch en sentido estricto — es un gap de **modelo de datos heredado de Batch
A2** (ya Senior-Reviewed/aprobado) que Batch A3 solo expone al construir la UI que finalmente lo
ejercita. Por tamaño real del fix (nueva columna + migración + cambio de firma de servicio en 2
repos), **no es "chico"** — no lo corrijo yo, queda documentado como bloqueo explícito, no
diferido sin más.

---

## 🔴 CRÍTICO — SCRUM-400 RN2: la guía firmada muestra el nombre del repartidor, no el de "quien recibió"

**Criterio literal (Jira, Escenario 2):** "Dado que ya se confirmó la guía firmada, cuando se
vuelve a abrir el documento, entonces se ve el nombre de quien recibió con estilo de firma en ese
espacio."

**Lo que pasa de verdad** (verificado con PED-2026-0009, `entregado`, guía ya firmada, navegador
real — ver `e2e/.tmp` del run, texto capturado por el test):

> Firma: **"Firmado y recibido por: Gary Arrocha"**

Gary Arrocha es el **repartidor** (courier) de ese pedido, no el cliente/persona que recibió y
firmó. El frontend (`GuiaEntregaModal.tsx`) muestra `order.repartidor` en el área de firma — pero
el backend (`DeliveryService::registerSignedGuide()`, Batch A2) **nunca captura un nombre de
receptor**: el único dato que persiste sobre quién recibió es el archivo adjunto (foto/PDF de la
guía firmada) + `delivered_by_courier_id`. No existe ninguna columna `received_by_name` (ni
similar) en `orders` ni en `order_documents`.

Con el modelo de datos actual, este criterio **no puede satisfacerse nunca**, sea cual sea el
frontend que se construya encima — el dato simplemente no se captura en ningún punto del flujo
(ni siquiera en el futuro modal "Confirmar Guía Firmada", que tampoco existe todavía, ver más
abajo). Confirmar la guía firmada hoy solo pide `delivered_by_courier_id` + el archivo.

**Por qué no lo corrijo yo:** requiere (a) migración nueva (columna en `orders` u `order_documents`
para el nombre de quien firma), (b) cambiar la firma de `registerSignedGuide()` y su validación en
`OrderDeliveryController`, (c) exponerlo en `OrderBoardService::detail()`, (d) tipar y consumir en
`GuiaEntregaModal.tsx`. Toca contrato ya aprobado de Batch A2 en 2 repos — no es un fix de sesión.

**Bloqueo explícito:** SCRUM-400 no pasa a QA. Requiere que el PM reasigne (probablemente junto con
el futuro ticket de "Confirmar Guía Firmada", que es donde este dato debería capturarse en primer
lugar) y vuelva un checklist completo de Pre-QA antes de reintentar.

## 🟡 MEDIO — SCRUM-400: contacto en la Guía no viene en campos separados (nombre/teléfono/correo)

El ticket pide literalmente "contacto (nombre/teléfono/correo en campos separados)". El modelo de
datos (`Order.customer_contact`) es un solo string ya combinado desde Ventas & Diseño (decisión de
diseño previa, documentada en el propio código: `GuiaEntregaModal.tsx` líneas 22-24). El frontend
ya lo maneja de forma transparente — muestra el string completo en "Nombre de contacto" y dos
campos "Teléfono"/"Correo" con fallback explícito "No disponible todavía (pendiente de backend)",
en vez de fingir que el dato existe. Menor severidad que el hallazgo de arriba porque no muestra un
dato **incorrecto** (como si fuera repartidor cuando no lo es) — solo incompleto, y de forma
honesta. Mismo origen (modelo de datos de un batch anterior) — no se corrige acá, se deja anotado
para cuando se parta `customer_contact` en columnas reales.

## Lo que sí funciona (verificado en navegador real, no solo código)

- **SCRUM-375** — contador real (`10 pedidos activos`, no fijo), ambos botones de encabezado
  presentes y funcionales.
- **SCRUM-376** — panel de carga por asistente correcto (Mariano/Osvaldo, 50/50 con mis fixtures).
  Forcé un desbalance real (70/30 vía `tinker`) y la advertencia
  "Reparto desbalanceado entre asistentes — considera reasignar algunos pedidos." apareció
  correctamente; revertido después a 50/50.
- **SCRUM-379** — Cliente poblado dinámicamente (solo los 4 clientes con pedidos reales, no lista
  fija). Combiné cliente + asistente simultáneamente — AND real, confirmado. Búsqueda "Pacifico"
  filtra correctamente por cliente.
- **SCRUM-380** — los 3 chips filtran correctamente contra datos reales (`atrasados`→PED-2026-0003,
  `sin_stock`→PED-2026-0002, `urgentes`→PED-2026-0001/0008). Doble clic en chip "Urgentes" no
  duplica ni rompe el filtro (toggle idempotente).
- **SCRUM-381** — 7 columnas en el orden exacto (Asignado→Entregado), conteo por columna correcto.
- **SCRUM-382** — ícono ⚠️ visible en tarjeta atrasada (PED-2026-0003); botón de acción rotula
  correctamente según etapa (ej. "Asignar Picker" en Asignado, "Iniciar Picking" en Picking
  pendiente) aunque deshabilitado (mutaciones son de otro batch, correctamente etiquetado
  "Próximamente").
- **SCRUM-397** — fila de factura pasiva correcta: "Esperando a Administración" (PED-2026-0007,
  `invoice_ready=false`) vs. "✓ ver" con link (PED-2026-0006, `invoice_ready=true`). Sin emoji
  (regla SCRUM-56 respetada pese a que el mock de Jira trae ⏳ literal en la descripción).
- **SCRUM-400 RN1** — guía sin firmar muestra correctamente el espacio de firma en blanco/"Pendiente
  de firma" (PED-2026-0008, despachado). Nunca precios en la tabla de artículos.
- **SCRUM-402** — modal "Ver detalle" **ya muestra contacto/dirección/ref. fábrica reales**
  (`Juan Perez - 6000-1234 - juan@pacifico.com`, `Calle 50, Torre Delta, Piso 3, Panama`,
  `FCT-LAMP-0001`) — era el bug que encontró Senior Review (`945752a`, mismatch de nombre de
  campo), **confirmado corregido de verdad en navegador, no solo por lectura de código**. Tabla de
  artículos colapsada por defecto (RN1), se expande solo al clic.
- **SCRUM-404** — botón "Status de pedidos" navega al placeholder sin 404 ni crash.
- **SCRUM-405** — buscador encuentra ambas tarjetas de una familia dividida por su `order_number`
  compartido (`PED-2026-0004`, 2 tarjetas, en etapas distintas).

## Foco de ruptura (Paso 3)

- **Doble clic en filtros/chips** — no acumula ni rompe (chip es un `select`, no un toggle
  acumulativo; verificado).
- **Recargar con el modal "Ver detalle" abierto** — el modal se cierra (no queda "fantasma"), el
  tablero recarga limpio, sin error de consola.
- **Los 6 botones de mutación de etapa** (asignar picker, iniciar/completar picking, registrar
  entrega, asignar repartidor/despachar, confirmar guía firmada) — confirmado con un test que
  intercepta requests de red y fuerza clic vía JS sobre **todos** los botones `disabled` del
  tablero: **cero requests** a cualquiera de los 7 endpoints de mutación (`assign-picker`,
  `start-picking`, `complete-picking`, `register-delivery`, `assign-courier`, `dispatch`,
  `register-signed-guide`). Correctamente deshabilitados a nivel de atributo HTML, no solo de
  estilo.
- **Botón "Status de pedidos"** — no rompe, no 404.
- **Grep visual de emoji** — corrido contra el texto completo de tarjeta + modal + tabla expandida
  con Playwright (regex de rango Unicode de emoji sobre `body.innerText()`): cero coincidencias.
- **Ver bodegas / ETA en tarjeta / Excel export / consolidado del día / Factura con precios** —
  todos correctamente stubbeados: botón "Ver bodegas" visible y deshabilitado ("Próximamente") en
  la tabla expandida del modal; ETA de proveedor solo aparece en el modal cuando `is_sin_stock`
  (no en la tarjeta, fuera de alcance de este batch, confirmado ausente ahí); "Imprimir picking del
  día" deshabilitado con el mismo tooltip; ninguna columna de la Guía de Entrega ni de la tabla de
  ítems muestra precio o valor monetario.

## Marcadores de gate

Creados para los 14 tickets que pasan limpio (los 2 hallazgos de arriba pertenecen ambos a
SCRUM-400, que **no** recibe marcador):

```
SCRUM-375 SCRUM-376 SCRUM-379 SCRUM-380 SCRUM-381 SCRUM-382 SCRUM-397 SCRUM-402 SCRUM-404 SCRUM-405
```

(10 tickets con marcador — los otros 6 del batch de 16 originales no forman parte de los criterios
de aceptación evaluados en esta sesión por no estar en la lista de 16 provista; ver nota de alcance
al pie.)

## Nota de alcance

El batch nominal son 16 tickets; esta sesión de Pre-QA solo tenía criterios de aceptación
extraídos para 11 (SCRUM-375, 376, 379, 380, 381, 382, 397, 400, 402, 404, 405 — coincide
exactamente con el alcance real de los commits de `atlanticerp-frontend` revisados por Senior Review, ver
docblock de `PedidosPage.tsx`). De esos 11, **10 pasan limpio y 1 (SCRUM-400) rebota** por el
hallazgo CRÍTICO de arriba.
