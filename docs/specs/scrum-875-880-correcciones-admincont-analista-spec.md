# Spec Analista — SCRUM-875 → 880 · Correcciones Admin&Contabilidad

**Fecha**: 2026-09-09
**Solicitado por**: Luis (correcciones reportadas por Gerencia Test)
**Estado**: Draft — pendiente de aprobación de Luis y diseño del Arquitecto
**Proyecto**: AtlanticERP CRM (Illumination) — épica SCRUM-331 (Admin&Contabilidad), salvo SCRUM-875 (SCRUM-329, Bodega)

Reportera de los 6 tickets: **Gerencia Test**. Todos en `To Do`, sin comentarios, asignados a Luis,
creados el 2026-09-09. Ninguno tiene código implementado (verificado por `git branch --all` /
`git worktree list` / `git log` en ambos repos — sin ramas ni commits relacionados).

SCRUM-875 fue incorporado al alcance por decisión explícita de Luis (2026-09-09) por ser
dependencia de validación de SCRUM-876.

---

## Decisiones transversales cerradas con Luis (2026-09-09)

### Batch C — SCRUM-875 + 876
| # | Tema | Decisión |
|---|---|---|
| C-1 | Alcance | 875 + 876 en el mismo batch. 875 primero (Bodega arma la familia de entregas), 876 después (Facturación la trata como un pedido). Un solo ciclo de gate. |
| C-2 | Reparto de mercancía entre entregas parciales (875) | Cada tarjeta de entrega nace con **todos los ítems** del pedido; Bodega define cuánto va en cada entrega **durante el picking**. |
| C-3 | Estado de factura por tarjeta hermana (876) | **Cálculo en vivo** vía helper `AdminContInvoice::forOrder()`. Se deja de escribir la columna `Order.invoice_ready` por fila. |
| C-4 | Comisiones externas dobles ya pagadas | **Hacia adelante.** Quedan en BD, desaparecen de la vista al corregir el join. Se documenta para QA, sin ticket de conciliación. |
| C-5 | Identidad de "pedido" | La **tarjeta raíz de la familia** (`Order.familyRootId()`). Respaldado por el índice único `1 raíz por pipeline_card`. |
| C-6 | Disparo de la factura | Manual. La lista "pendientes-facturar" muestra **1 línea por pedido**; el usuario sigue apretando "Generar factura". |
| C-7 | Monto de la factura única | Monto **total del pedido** (`pipelineCard.amount`), sin prorrateo por entrega. |
| C-8 | Datos ya duplicados en dev/test | **Solo corregir hacia adelante.** Sin migración de limpieza. |

### Batch D — SCRUM-877 parte 1 (notas de crédito en Estado de Cuenta)
| # | Tema | Decisión |
|---|---|---|
| D-1 | Nota con resultado `saldo_favor` | Crédito por el **monto completo** de la nota → el saldo puede quedar a favor (negativo). |
| D-2 | Notas `pendiente_aprobacion` | **No se muestran.** Solo las `aplicada`. |
| D-3 | Fecha para el saldo corrido | **Fecha de aprobación/decisión de Mark** (`fecha_decision`, o fecha de registro si fue aplicación inmediata). Mismo criterio que el Arqueo de Caja. |
| D-4 | Descuento comercial / cortesía | Crédito por el **monto completo** de la nota. |
| D-5 | Anulación completa | Fila "Anulación" que revierte el débito → efecto neto cero, factura visible. |
| D-6 | Devolución de mercancía con excedente devuelto en efectivo | Crédito = **solo la parte que redujo la factura**; el efectivo devuelto es neutro al saldo. |

**Regla resultante:** el crédito mostrado = monto completo de la nota, **excepto** cuando parte de
ese monto se devolvió en efectivo al cliente (ahí solo la porción que redujo la factura).

### Batch E — SCRUM-879 (comisiones en Flujo de Caja)
| # | Tema | Decisión |
|---|---|---|
| E-1 | Comisión interna — mecanismo | **Job automático al cierre de mes** (sin acción humana). |
| E-2 | Comisión externa sin cuenta bancaria | Crear **siempre** el `bank_movement`, `bank_account_id` posible null. |
| E-3 | Movimiento sin cuenta | Cuenta igual en saldo de bancos y Vista Real desde el día del pago. Asignar la cuenta es solo categorización. |
| E-4 | Dónde aparecen las comisiones pagadas | **Solo** Flujo de Caja (Vista Real) + Cuentas Bancarias → Movimientos. El "Arqueo N° X" descargable **no cambia**. |
| E-5 | Fecha del movimiento de comisión interna | **Día 1 del mes siguiente** al mes de cierre. |
| E-6 | Cuenta bancaria de comisión interna | **Sin cuenta asignada** + flujo "Seleccionar cuenta" (igual que externas). |
| E-7 | Alcance del backfill | **Desde fecha de corte** = arranque del módulo Flujo de Caja / Arqueo (Batch 18). No todo el histórico. |
| E-8 | Entornos del backfill | **dev + test** dentro del batch; **prod en ventana coordinada** con Luis, con verificación antes/después. |

### Batch B — SCRUM-878
| # | Tema | Decisión |
|---|---|---|
| B-1 | Sección "Conciliación — Retenciones con constancia: X de Y" | **Incluir**, con datos reales del arqueo. |

### Batch A — SCRUM-880
Sin decisiones de negocio abiertas.

---

## Batches y secuencia

| Batch | Tickets | Tamaño | Secuencia |
|---|---|---|---|
| **A** | SCRUM-880 + SCRUM-877 parte 2 | XS | En paralelo con B, sin dependencias |
| **B** | SCRUM-878 | M | En paralelo con A |
| **C** | SCRUM-875 → SCRUM-876 | L | Camino crítico. 875 antes que 876. |
| **D** | SCRUM-877 parte 1 | M-L | Después de C (comparte el hilo factura → estado de cuenta) |
| **E** | SCRUM-879 | L | Después de C (el join de comisiones lo toca C) |

Cada batch cierra con Senior Review + Visual Review + Pre-QA fusionados (regla del proyecto).

---
---

# SCRUM-880 — Orden de columnas del reporte "Ventas por medio de pago"

**Tipo:** Story · **Parent:** SCRUM-331 · **Prioridad:** Highest · **Batch:** A
**Labels:** Admin&Contabilidad, Reporte_Ventas_MedioPago

## Problem
En el reporte "Mensual por cliente — ventas por medio de pago" (Admin & Contabilidad → Reportes),
las columnas de método de pago que van **después de la columna "Total"** aparecen en un orden que
no coincide con el que negocio espera. No hay error de cálculo — solo posición.

## Solution summary
Reordenar las columnas de método de pago posteriores a "Total" al orden pedido por negocio, sin
tocar el orden de la constante compartida `AdminContPayment::METODOS` (la usan Arqueo, Cobros y
otros). Se introduce un orden propio del reporte, aplicado en la pantalla y en el Excel.

## Users and roles
Felix, Yaneth, Mark, Gerencia (lectura de Reportes, `permission:admin_contab.read`). Sin diferencias
de permiso para este cambio.

## Contexto (código actual)
- `atlanticerp-backend/app/Modules/AdminCont/Services/PaymentMethodSalesReportService.php` — arma cada fila
  iterando `AdminContPayment::METODOS`.
- `atlanticerp-backend/app/Modules/AdminCont/Services/PaymentMethodSalesExportService.php` — usa
  `AdminContPayment::METODOS` en 3 lugares (encabezados, filas, fila de totales).
- `atlanticerp-frontend/src/pages/admin-contab/VentasMedioPagoPage.tsx:19` — array `METODOS` con el orden
  de columnas de la tabla en pantalla.
- Orden actual: Transferencia, Cheque, Efectivo, **Tarjeta, Depósito bancario, Yappy, Link de pago**,
  Retención de impuestos, Ajustes de cuenta.

## Comportamiento esperado
Columnas **después de "Total"**, en este orden exacto:
1. Transferencia
2. Cheque
3. Efectivo
4. Yappy
5. Link de pago
6. Tarjeta
7. Depósito bancario
8. Retención de impuestos
9. Ajustes de cuenta

(Solo cambian de posición Tarjeta y Depósito bancario, que pasan a después de Yappy y Link de pago.)

## Acceptance criteria
- [ ] En pantalla, las columnas después de "Total" quedan en el orden: Transferencia, Cheque, Efectivo, Yappy, Link de pago, Tarjeta, Depósito bancario, Retención de impuestos, Ajustes de cuenta.
- [ ] Las columnas **antes de "Total"** (Fecha, Cliente, N° Documento, Base Imponible, ITBMS, Total) permanecen exactamente en su orden y posición actuales.
- [ ] El Excel descargable (`/reports/payment-method-sales/export`) refleja el mismo orden nuevo, en encabezados, filas de datos y fila "Total del período".
- [ ] Ningún valor calculado cambia: cada monto sigue cayendo en la misma columna de método, solo cambia dónde está esa columna.
- [ ] La constante compartida `AdminContPayment::METODOS` **no se modifica** (verificable: cualquier otra pantalla que la use — Arqueo de Caja, etc. — no cambia su orden de métodos).

## Edge cases y errores
- Reporte sin filas (ningún cobro en el período): las columnas vacías siguen en el orden nuevo.
- Método sin ningún monto en todo el período: la columna aparece igual, con ceros, en su posición nueva.
- No hay PDF de este reporte — solo pantalla y Excel.

## Out of scope
- Cualquier cambio a los cálculos, al resumen superior, a la hoja "Pendientes" del Excel, o al orden de columnas de otros reportes.
- Renombrar métodos o etiquetas.

## Open questions
Ninguna.

## References
- `PaymentMethodSalesReportService.php`, `PaymentMethodSalesExportService.php`, `VentasMedioPagoPage.tsx`
- `AdminContPayment::METODOS` / `METODO_LABELS` (`app/Modules/AdminCont/Models/AdminContPayment.php`)

---
---

# SCRUM-877 parte 2 — Subtotales desalineados en el Estado de Cuenta

**Tipo:** Story (parte 2 de SCRUM-877) · **Parent:** SCRUM-331 · **Prioridad:** Highest · **Batch:** A
**Labels:** Admin&Contabilidad, Estado_de_Cuenta, Facturacion

## Problem
En la fila "Subtotal del proyecto" de la tabla del Estado de Cuenta (vista "Agrupado por proyecto"),
los tres valores (Débito, Crédito, Saldo) no quedan alineados debajo de sus columnas. Confusión
visual: a simple vista los montos del subtotal no caen bajo "Débito", "Crédito" y "Saldo".

## Solution summary
Corregir el `colSpan` de la celda de etiqueta del subtotal para que los 3 valores caigan bajo sus
columnas, tanto en la vista plana como en la agrupada (que tiene una columna "Proyecto" extra).
Corrección puramente visual, sin tocar cálculos.

## Users and roles
Felix, Yaneth, Mark, Gerencia (lectura de Estado de Cuenta).

## Contexto (código actual)
`atlanticerp-frontend/src/pages/admin-contab/EstadoCuentaPage.tsx:403` — la fila de subtotal usa
`<td colSpan={4}>` fijo, pero las columnas previas a "Débito" son **5** en vista plana y **6** en
vista agrupada (con columna "Proyecto"). Por eso los 3 valores caen corridos 1-2 columnas.

El PDF (`account-statement-pdf.blade.php`) **no tiene** fila de subtotal — lista los movimientos
planos con saldo corrido. Este defecto es solo de la pantalla.

## Acceptance criteria
- [ ] En la fila "Subtotal del proyecto", el valor de Débito queda alineado exactamente bajo la columna "Débito".
- [ ] El valor de Crédito queda alineado bajo la columna "Crédito".
- [ ] El valor de Saldo queda alineado bajo la columna "Saldo".
- [ ] La alineación es correcta tanto en "Vista plana" como en "Agrupado por proyecto".
- [ ] No cambia ningún valor calculado del subtotal, solo su posición.

## Edge cases y errores
- Proyecto con un solo movimiento: el subtotal sigue alineado.
- Proyecto sin movimientos en el rango de fechas: no aplica (no se dibuja la fila).

## Out of scope
- El PDF del Estado de Cuenta (no tiene esta fila; la parte de notas de crédito en el PDF es de la parte 1, Batch D).
- Cualquier cambio de cálculo.

## Open questions
Ninguna.

## References
- `EstadoCuentaPage.tsx` (componente `MovementsTable` / fila subtotal, ~línea 400-406)

---
---

# SCRUM-878 — Documento descargable "Resumen del Arqueo"

**Tipo:** Story · **Parent:** SCRUM-331 · **Prioridad:** Highest · **Batch:** B
**Labels:** Admin&Contabilidad, Arqueo_Caja · **REQ-529**

## Problem
El documento que se descarga desde **Arqueo de Caja → Descargar resumen** (modal "Arqueo N° X")
se genera con una estructura simple que no sigue el estándar de documentos del sistema ni el
mockup de referencia: sin logo ni pie de página estándar, totales arriba como texto plano, dos
tablas separadas, métodos de pago en minúscula, sin columna de Observaciones, datos del arqueo
en una sola línea corrida.

## Solution summary
Rediseñar el cuerpo del documento siguiendo el mockup (`REPORTE MOCKUP.png`): bloque "Resumen" con
líneas separadas, una única tabla de movimientos del día con encabezado de color y columnas
Concepto · Medio · Observaciones · Monto, y un recuadro destacado al final con los totales. El
encabezado y pie de página usan el **estándar ya definido** para los descargables del sistema
(logo Atlantic + datos reales de la empresa + numeración), **no** los del mockup. Se incluye
la sección "Conciliación" con "Retenciones con constancia: X de Y".

## Users and roles
Felix, Yaneth (`role:superadmin,lider_admin_contab,asistente_administrativa`). Mark/Gerencia no
llegan a esta pantalla (mismo gate que el resto de `cash-position/daily-count`).

## Contexto (código actual)
- `atlanticerp-backend/app/Modules/AdminCont/Services/DailyCashCountSummaryPdfService.php` — 24 líneas,
  carga el blade `admin-cont.daily-cash-count-summary-pdf`.
- `atlanticerp-backend/resources/views/admin-cont/daily-cash-count-summary-pdf.blade.php` — template actual.
- Datos de origen: `DailyCashCountService::forDate()` — ya devuelve `cobros[]` (con `observacion`,
  `metodo_pago`, `concepto`, `monto`), `notas_credito[]` (con `observacion`, `concepto`, `monto`),
  `retenciones[]` (con `constancia` = null o archivo), `total_cobrado`, `total_notas_credito`,
  `total_neto`, `observacion_general`. `present()` agrega `numero`, `estado`, `cerrado_por`,
  `cerrado_at`, `aprobado_por`, `aprobado_at`.
- Estándar de encabezado/pie ya existente (SCRUM-857/858): `App\Shared\Support\PdfLogo::dataUri()` +
  `App\Modules\AdminCont\Support\PdfFooterText::forFiscal()`, tal como los usan
  `AccountStatementPdfService` y `PaymentReceiptPdfService`.
- Datos reales de la empresa: `AdminContFiscalSettings::current()` / `AdminContCompanyProfile::current()`.

## Comportamiento esperado

### Encabezado y pie de página — estándar del sistema (NO el del mockup)
- Encabezado: logo Atlantic + "ATLANTIC" + línea divisoria (mismo componente que
  `account-statement-pdf.blade.php`). **No** la barra oscura "FORMATO DE ARQUEO DE CAJA DIARIO".
- Pie: `PdfFooterText::forFiscal()` (nombre + dirección + datos reales) + "Página X de Y". **No**
  "Documento generado por AtlanticERP".
- Bloque de datos de la empresa: los reales de Configuración Fiscal (ATLANTIC USA CORP, etc.),
  nunca el placeholder del mockup ("Atlantic Diseño e Iluminación, S.A.").

### Cuerpo del documento (según mockup), en este orden:
1. **Bloque "RESUMEN"** — líneas separadas:
   - `Fecha: [fecha del arqueo]`
   - `Estado: Realizado por [nombre] a las [HH:MM]` (cuando ya está cerrado)
   - `Aprobado por Gerencia — [nombre] el [fecha]` (cuando aprobado), o `Pendiente de aprobación de Gerencia (Mark)` (cuando cerrado sin aprobar), o `Arqueo aún no cerrado` (cuando abierto).
2. **Sección "CONCILIACIÓN"** — `Retenciones con constancia: X de Y` donde `Y` = cantidad de
   retenciones del arqueo (`retenciones[]`) y `X` = cuántas de ellas tienen `constancia !== null`.
   Si `Y = 0`: mostrar `Retenciones con constancia: 0 de 0` (no se omite la sección).
3. **Observación general** (si existe) — bloque de texto.
4. **Una única tabla "Movimientos del día"** — encabezado de columna con fondo teal oscuro + texto
   blanco, columnas: **Concepto · Medio · Observaciones · Monto**.
   - Todos los cobros del día: `Concepto` = "Cobro [N° recibo]" (o el concepto que ya arma
     `forDate()`), `Medio` = etiqueta legible del método (`AdminContPayment::METODO_LABELS`, ej.
     "Transferencia", "Link de pago"), `Observaciones` = la observación de esa fila o "—", `Monto`.
   - Todas las notas de crédito / devoluciones del día: en la **misma tabla**, `Medio` = "—",
     `Observaciones` = la observación de esa fila o "—", `Monto`.
5. **Recuadro destacado con los totales** (fondo diferenciado), **después** de la tabla, en este orden:
   - `Total cobrado` = `total_cobrado`
   - `Notas crédito y devoluciones` = `-total_notas_credito`, **en rojo, como valor negativo**
   - `Total neto del día` = `total_neto`, **en negrita, resaltado como dato principal**

## Acceptance criteria
- [ ] El documento usa el encabezado estándar (logo + "ATLANTIC" + línea), sin la barra oscura "FORMATO DE ARQUEO DE CAJA DIARIO" del mockup.
- [ ] El documento usa el pie de página estándar (datos reales de la empresa + "Página X de Y"), sin "Documento generado por AtlanticERP".
- [ ] El bloque de datos de la empresa muestra los datos reales de Configuración Fiscal, no el nombre/dirección del mockup.
- [ ] La información de Fecha, quién realizó el arqueo (y a qué hora) y la aprobación de Gerencia se muestra en un bloque "Resumen" con líneas separadas, no como texto corrido.
- [ ] Existe una sección "Conciliación" con "Retenciones con constancia: X de Y", con X = retenciones con constancia adjunta e Y = total de retenciones del arqueo.
- [ ] Los movimientos del día (cobros, notas de crédito, devoluciones) aparecen en **una sola tabla**, con columnas Concepto, Medio, Observaciones, Monto, y encabezado de columna con fondo de color.
- [ ] La columna "Medio" muestra la etiqueta legible del método ("Transferencia", "Link de pago"), no el valor crudo en minúscula ("transferencia").
- [ ] La columna "Observaciones" muestra la observación por fila cuando existe, o "—".
- [ ] Los totales aparecen **después** de la tabla, agrupados en un recuadro visualmente destacado: Total cobrado, luego "Notas crédito y devoluciones" en rojo negativo, luego "Total neto del día" en negrita.
- [ ] El documento se genera igual para un arqueo activo, uno pendiente de aprobación y uno aprobado (cambia solo la línea de Estado del bloque Resumen).
- [ ] No se usa ningún carácter emoji/glyph como icono (regla de iconografía del cliente) — el `✓` actual de la tabla de retenciones se reemplaza por texto o un SVG del set propio.

## Edge cases y errores
- Arqueo sin cobros y sin notas de crédito: la tabla muestra una fila "Sin movimientos este día"; el recuadro de totales muestra ceros.
- Arqueo aún no cerrado: línea de Estado = "Arqueo aún no cerrado"; el resto del documento se genera igual.
- Retención sin constancia: cuenta en `Y` pero no en `X`.
- `observacion_general` vacía: no se dibuja ese bloque.

## Out of scope
- Cualquier cambio a los datos o cálculos de `DailyCashCountService::forDate()` (totales, qué cuenta como cobro del día, etc.).
- Agregar comisiones pagadas a este documento (decisión E-4: el "Arqueo N° X" no cambia de alcance).
- La pantalla en vivo del Arqueo de Caja — solo el documento descargable.
- El Excel/otros formatos — este documento es PDF.

## Open questions
Ninguna.

## References
- `DailyCashCountSummaryPdfService.php`, `daily-cash-count-summary-pdf.blade.php`
- Estándar: `account-statement-pdf.blade.php`, `PdfLogo`, `PdfFooterText`
- `DailyCashCountService::forDate()` / `present()`
- Mockup: `REPORTE MOCKUP.png` (Jira SCRUM-878, adjunto 12097) · Actual: `REPORTE ATLANTICERP.png` (12096) · `arqueo-1.pdf` (12095)

---
---

# SCRUM-875 — Los pedidos con Fecha de Entrega Parcial no se dividen en Bodega

**Tipo:** Story · **Parent:** SCRUM-329 · **Prioridad:** Medium · **Batch:** C (va primero)
**Labels:** Bodega, Pedidos

## Problem
Cuando desde **Ventas** se crea un pedido con **Fecha de entrega Parcial** e ingresando 2 o más
fechas, el pedido que llega a **Bodega → Pedidos** no se divide: el sistema toma solo la fecha más
próxima y crea una única tarjeta. Las demás fechas parciales se pierden. Esto además impide validar
SCRUM-876 por la vía que reportó Daniela.

## Solution summary
Al crearse el pedido de Bodega desde una cotización aprobada, si el pedido tiene modo de entrega
"parcial" con 2 o más fechas, se crea una **familia de tarjetas**: una tarjeta por cada fecha
parcial, todas con el mismo `order_number`, enlazadas por `family_id` a la tarjeta raíz, ordenadas
de la fecha más cercana a la más lejana. Cada tarjeta nace con **todos los ítems del pedido**; la
distribución de cantidades por entrega se define durante el picking (fuera del alcance de este
ticket, ver Out of scope). El modo "Fecha de entrega Única" no cambia.

## Users and roles
Automático (sin usuario). Consumido por: Asistentes de Bodega, Jefe de Bodega (tablero "Pedidos"),
Ventas & Diseño (visibilidad de sus pedidos en "Status de Pedidos").

## Contexto (código actual)
- `atlanticerp-backend/app/Modules/Bodega/Services/OrderService.php::createFromApprovedQuote()` — crea
  **un solo `Order`** con `committed_delivery_date = $card->deliveryDates->min('date')`. Nunca
  itera `deliveryDates`.
- `PipelineCard->deliveryDates` (`ventas_diseno.pipeline_card_delivery_dates` o similar) — ya
  guarda N fechas. **A confirmar por el Arquitecto/Backend:** dónde vive el flag de modo
  "parcial vs única" (¿columna en `pipeline_cards`? ¿se infiere de `deliveryDates->count() > 1`?).
- Modelo de familia ya existente (REQ-319): `Order.family_id` (raíz = null), `sequence_in_family`,
  `total_in_family`, `order_number` compartido. Helpers: `Order::familyRootId()`,
  `FamilyService::members()` / `attach()` / `completionBadge()`.
- Patrón de creación de miembro de familia ya probado: `PickingService::createInventoryReviewCard()`
  — `Order::create([... 'family_id' => $order->familyRootId() ...])` seguido de
  `FamilyService::attach()` (nunca `family_id` en un `update()` posterior — lo rechaza el índice
  único parcial `orders_pipeline_card_root_uq`), evento `OrderSplit`.
- "Status de Pedidos" (`OrderStatusService` / `OrderStatusController`) ya opera a nivel de raíz
  (`resolveRoot`, `detailPayload($root)`) — no requiere cambios para el seguimiento de entregas.

## Comportamiento esperado
- Pedido con modo "parcial" y **N ≥ 2** fechas → llegan a Bodega **N tarjetas** en `Asignado`:
  - Todas con el mismo `order_number`.
  - Tarjeta raíz: `family_id = null`, `sequence_in_family = 1`, `total_in_family = N`.
  - Tarjetas hijas: `family_id = raíz`, `sequence_in_family = 2..N`, `total_in_family = N`.
  - `committed_delivery_date` de cada tarjeta = su fecha parcial correspondiente.
  - Orden por fecha ascendente: `sequence_in_family = 1` es la fecha más próxima.
  - Cada tarjeta nace con **la lista completa de ítems del pedido** (mismo set que hoy hereda de la
    cotización), con `qty_requested` = la cantidad completa de la cotización.
- Pedido con modo "parcial" y **1 sola** fecha, o modo "única": **1 sola tarjeta**, comportamiento
  idéntico al actual (sin familia, `family_id = null`, `total_in_family = null`).
- Se dispara `OrderCreated` por cada tarjeta (mismo criterio que hoy) y `OrderSplit` para registrar
  la división (mismo patrón que `PickingService`).
- El mapeo vendedor → asistente y la notificación al Jefe de Bodega por pedido sin asistente
  funcionan por cada tarjeta igual que hoy (no perder ningún pedido, REQ-308).

## Acceptance criteria
- [ ] Un pedido creado desde Ventas con Fecha de entrega Parcial y **2 fechas** llega a Bodega dividido en **2 tarjetas**, cada una con su fecha parcial correspondiente en `committed_delivery_date`.
- [ ] Un pedido con Fecha de entrega Parcial y **3 o 4 fechas** llega dividido en la **misma cantidad** de tarjetas, cada una con su fecha.
- [ ] Las tarjetas divididas quedan ordenadas de la fecha más cercana a la más lejana (`sequence_in_family` ascendente = fecha ascendente).
- [ ] Ninguna de las fechas parciales seleccionadas en Ventas se pierde; todas quedan reflejadas en su tarjeta.
- [ ] Todas las tarjetas de la familia comparten el mismo `order_number`.
- [ ] La tarjeta raíz tiene `family_id = null`; las demás tienen `family_id` = id de la raíz; todas tienen `total_in_family = N`.
- [ ] Cada tarjeta nace con la lista completa de ítems del pedido.
- [ ] Un pedido con Fecha de entrega Parcial y **una sola** fecha llega como **una sola tarjeta** (sin familia).
- [ ] Un pedido con Fecha de entrega **Única** llega como una sola tarjeta — comportamiento sin cambios respecto de hoy.
- [ ] "Status de Pedidos" muestra la familia como un solo pedido con el detalle de sus N entregas y el avance de cada una.
- [ ] El badge de completitud de familia (`FamilyService::completionBadge()`) funciona: todas las tarjetas muestran "pendiente" hasta que **todas** llegan a Por Despachar, y ahí pasan a "entrega completa".

## Edge cases y errores
- Cotización sin ítems (caso REQ-308, no debería pasar): se crean las N tarjetas igual, sin ítems, con log (mismo criterio que hoy para la tarjeta única).
- Vendedor sin `WarehouseAssistantMapping`: las N tarjetas se crean en `Asignado` con `assistant_id = null` y se notifica al Jefe de Bodega (mismo criterio actual, una notificación por tarjeta o una consolidada — decisión del Arquitecto).
- Dos fechas parciales iguales: se crean 2 tarjetas con la misma fecha (no se deduplica — negocio puede haber querido 2 entregas el mismo día). A confirmar con Luis si molesta.
- Modo "parcial" seleccionado pero 0 fechas ingresadas: se trata como "única" con la fecha por defecto / sin fecha (mismo comportamiento que hoy). No romper.
- Falla a mitad de la creación de la familia (N-ésimo `Order::create()` falla): toda la creación revierte (transacción) — no puede quedar una familia a medias.

## Out of scope
- **Cómo se reparten las cantidades por entrega durante el picking** — decisión C-2 dice "Bodega
  ajusta en picking", pero el mecanismo de UI/flujo para que el picker diga "esta entrega cubre X
  de Y unidades" es un ticket/spec aparte. Este ticket solo garantiza que las N tarjetas existan
  con la fecha correcta y todos los ítems.
- Editar las fechas parciales una vez creado el pedido.
- Cambiar de modo "única" a "parcial" (o viceversa) después de aprobada la cotización.
- La lógica de facturación de esas entregas (eso es SCRUM-876).

## Open questions
- [Arquitecto/Backend] ¿Dónde vive el flag de modo de entrega "parcial vs única"? ¿Columna en `pipeline_cards` o se infiere de `deliveryDates->count()`?
- [Luis] Dos fechas parciales idénticas: ¿se permiten 2 entregas el mismo día, o se deduplica a 1?

## References
- `OrderService::createFromApprovedQuote()`, `OrderService::createItemsFromQuote()`
- `FamilyService`, `PickingService::createInventoryReviewCard()` (patrón a replicar)
- `Order` model (family_id / sequence_in_family / familyRootId)
- `OrderStatusService` / `OrderStatusController`
- REQ-319 (Sistema de Familia de Entregas), `docs/specs/scrum-129-131-familias-stock.md`
- Ticket SCRUM-875 (sin adjuntos)

---
---

# SCRUM-876 — Una factura por pedido, no una por entrega parcial

**Tipo:** Story · **Parent:** SCRUM-331 · **Prioridad:** Highest · **Batch:** C (después de 875)
**Labels:** Admin&Contabilidad, Facturacion

## Problem
Cuando un pedido se divide en entregas parciales (2, 3, 4 o más tarjetas de Bodega), **cada
tarjeta que llega a "Por Despachar" genera su propia línea "pendiente-facturar"** en
Administración, y por lo tanto su propia factura. Un pedido de 3 entregas → 3 facturas, cada una
**por el monto total del pedido** (`pipelineCard.amount` es el total, no el de la entrega). El
cliente aparece debiendo 3× el pedido, y solo la tarjeta facturada muestra la factura — las
hermanas quedan "sin factura asociada".

Confirmado en la captura de Daniela: F-0004 + F-0005, mismo COT-2026-0153, USD 87.42 c/u → saldo
de 174.84 (el doble). También causa la comisión externa duplicada de SCRUM-879 (2 líneas de
USD 9.05 para el mismo PED-2026-0070).

## Solution summary
La facturación pasa a operar a nivel de **pedido (familia de tarjetas)** en vez de por tarjeta
individual. La lista "pendientes-facturar" muestra **una línea por pedido**, que aparece en cuanto
la **primera** entrega llega a "Por Despachar". Al generar la factura (acción manual, sin cambio),
se crea **una sola factura** por el **monto total del pedido**, asociada a la tarjeta raíz de la
familia. Todas las tarjetas hermanas de Bodega muestran esa misma factura. Ninguna entrega
posterior genera una factura adicional. El seguimiento de entregas parciales (qué se entregó, qué
falta) sigue funcionando por tarjeta en "Status de Pedidos".

## Users and roles
Felix, Yaneth, Mark, Gerencia (`role:superadmin,lider_admin_contab,asistente_administrativa,management`
en `invoices`). Usuarios de Bodega (`permission:bodega.read`) leen la factura asociada de cada
tarjeta vía `OrderInvoiceController`.

## Contexto (código actual)
- `atlanticerp-backend/app/Modules/AdminCont/Services/InvoiceService.php`:
  - `list()` / `pendingForProject()` / `summary()` iteran **cada `Order`** en `FACTURABLE_STAGES`
    → N líneas por pedido partido.
  - `confirm(Order)` crea `AdminContInvoice` con `order_id` = esa entrega; guard =
    `where('order_id', $locked->id)`. Monto = `card->amount` (total del pedido).
  - `detail()` / `proposeUncollectible()` / `decideUncollectible()` — todos por `order_id` directo.
- `atlanticerp-backend/app/Modules/Bodega/Listeners/MarkOrderInvoiceReady.php` — marca `invoice_ready = true`
  solo en el `Order` facturado.
- `atlanticerp-backend/app/Modules/Bodega/Http/Controllers/OrderInvoiceController.php` — `show()`/`pdf()`
  buscan `AdminContInvoice::where('order_id', $order->id)` directo.
- `atlanticerp-backend/app/Modules/AdminCont/Services/ArchitectCommissionService.php::allProjects()` —
  `leftJoin orders o ON o.pipeline_card_id = pc.id` (fan-out por familia) `leftJoin invoices inv ON
  inv.order_id = o.id` → N líneas de comisión por pedido.
- Índice único parcial `invoices_order_id_active_unique ON (order_id) WHERE anulada_at IS NULL`
  ("a lo sumo una factura activa por `order_id`").
- Índice único parcial `orders_pipeline_card_root_uq ON (pipeline_card_id) WHERE family_id IS NULL`
  → **1 raíz por `pipeline_card`**. En la práctica: pedido ≡ pipeline card ≡ familia.
- `atlanticerp-frontend/src/pages/admin-contab/FacturacionPage.tsx` — `FactRow` por `entry.order_id`,
  "Generar factura" pasa `entry.order_id`.
- `atlanticerp-frontend/src/components/InvoiceStatusModal.tsx` (Bodega) — consume
  `GET /api/bodega/orders/{id}/invoice`.
- `blockingReasonsForDispatch()` **ya no** exige factura para despachar (SCRUM-860) — el despacho
  no está bloqueado por esto.

## Comportamiento esperado

### Llave de familia
- La factura se asocia siempre a la **tarjeta raíz** de la familia: `invoice.order_id =
  $order->familyRootId()`.
- Se agrega un helper `AdminContInvoice::forOrder(int|Order $order)` que resuelve cualquier
  `order_id` a su raíz antes de consultar. Todos los `AdminContInvoice::where('order_id', ...)` del
  código pasan por este helper.
- El índice único parcial existente no se modifica: con la factura siempre en la raíz, sigue
  garantizando "1 factura activa por pedido".

### Lista de pendientes-facturar (`InvoiceService::list()` / `pendingForProject()` / `summary()`)
- Se agrupan las tarjetas por familia (`familyRootId()`). **Una fila por pedido**, representada por
  la raíz.
- Una familia entra a "pendiente-facturar" en cuanto **cualquier** miembro está en `POR_DESPACHAR`
  y no hay factura activa en la raíz.
- Estado del pedido: `pendiente-facturar` (sin factura activa), `facturada` (factura activa en la
  raíz), `anulada` (solo factura anulada en la raíz).
- El payload de cada fila incluye el detalle de entregas del pedido: `[{sequence_in_family, stage,
  order_id}]`.
- `summary().pendientes_facturar` cuenta **familias**, no tarjetas.

### Generar factura (`InvoiceService::confirm()`)
- Recibe el `order_id` de la raíz (el frontend ya solo tiene esos). Defensa: normaliza cualquier
  `order_id` a su raíz.
- Guard: `AdminContInvoice::forOrder($root)->whereNull('anulada_at')->exists()` → si ya hay factura
  activa para el pedido, error "Este pedido ya tiene una factura generada".
- Crea **una** `AdminContInvoice` con `order_id = $root->id`, `monto = card->amount` (total del
  pedido), `sales_project_id` / `master_client_id` / `cliente` / `quote_id` como hoy.
- Dispara `InvoiceCreated(orderId = $root->id)`.

### Bodega — factura asociada por tarjeta (`OrderInvoiceController::show()` / `pdf()`)
- `show($id)`: resuelve la raíz, busca `AdminContInvoice::forOrder($id)->whereNull('anulada_at')`.
  `invoice_ready` se calcula en vivo (`$invoice !== null`), **no** se lee de la columna.
- **Todas** las tarjetas de la familia devuelven el **mismo** `invoice_numero` y el mismo detalle.
- `MarkOrderInvoiceReady` deja de escribir `Order.invoice_ready` (decisión C-3). El listener puede
  quedar como no-op o eliminarse (decisión del Arquitecto); el estado se calcula en vivo.

### Comisiones externas (`ArchitectCommissionService::allProjects()`)
- El join a `orders` se restringe a la raíz: `... AND o.family_id IS NULL`.
- El join a `invoices` se restringe a la activa: `... AND inv.anulada_at IS NULL`.
- Resultado: **una fila de comisión por pedido**. Corrige de paso la comisión duplicada de SCRUM-879.

### Seguimiento de entregas parciales
- "Status de Pedidos" (`OrderStatusService`) sigue mostrando el avance por tarjeta (qué se entregó,
  qué falta) — sin cambios funcionales, ya opera a nivel de raíz.
- El Estado de Cuenta refleja el avance del pedido asociado a la única factura (no muestra facturas
  duplicadas por las entregas).

### Frontend
- `FacturacionPage`: la lista viene deduplicada del backend (1 fila por pedido). La fila muestra el
  avance de entregas del pedido ("Entrega X de Y · [etapa]"). El preview muestra un aviso "Este
  pedido tiene N entregas parciales — se generará una sola factura por el total" cuando aplica.
- `InvoiceStatusModal` (Bodega): abrir el modal desde cualquier tarjeta hermana muestra la factura
  del pedido.

## Acceptance criteria
- [ ] Un pedido dividido en 2, 3, 4 o más entregas parciales genera **una sola factura** en Administración.
- [ ] La línea "pendiente-facturar" del pedido aparece cuando la **primera** entrega llega a "Por Despachar".
- [ ] La factura se genera con la acción manual "Generar factura" sobre esa única línea; el monto es el **total del pedido** (`pipelineCard.amount`).
- [ ] Las entregas parciales que lleguen después a "Por Despachar" (el mismo día o en fechas distintas) **no** generan una línea ni una factura adicional.
- [ ] Intentar generar una segunda factura para un pedido que ya tiene factura activa da un error claro ("Este pedido ya tiene una factura generada").
- [ ] **Ninguna** tarjeta de entrega parcial en Bodega queda sin factura asociada: todas las tarjetas del pedido muestran una factura.
- [ ] **La factura asociada es la misma** en todas las tarjetas del pedido — nunca aparecen facturas distintas entre tarjetas del mismo pedido.
- [ ] El modal de factura en Bodega (`InvoiceStatusModal`) muestra la factura correctamente al abrirse desde cualquier tarjeta hermana, no solo desde la que se facturó.
- [ ] "Status de Pedidos" sigue reflejando correctamente qué entregas del pedido están completas y cuáles pendientes.
- [ ] El Estado de Cuenta del cliente muestra **una sola** factura por el pedido, sin duplicados por entregas parciales.
- [ ] En Comisiones Externas, un pedido partido genera **una sola** línea de comisión (no una por entrega).
- [ ] El comportamiento es correcto tanto cuando todas las entregas llegan al mismo tiempo a "Por Despachar" como cuando llegan en fechas distintas.
- [ ] Un pedido **no partido** (una sola entrega) sigue funcionando exactamente igual que hoy: una línea, una factura, un número.
- [ ] Los agregados de facturación (cartera por cobrar, aging, libro de facturas, reporte de ventas por medio de pago, KPIs de Gerencia, flujo de caja) dejan de doble-contar los pedidos partidos.

## Edge cases y errores
- Familia creada por **falta de stock en picking** (no solo por fechas de Ventas): mismo tratamiento — una factura por familia.
- Entrega hermana que llega a "Por Despachar" **después** de que la factura ya existe: la tarjeta muestra la factura del pedido (cálculo en vivo, sin depender de un marcado por-fila).
- Pedido facturado y luego una nota de crédito de **anulación completa**: la factura de la raíz queda `anulada_at` no null; el pedido vuelve a `pendiente-facturar` para toda la familia (mismo criterio que hoy para "Corrección de datos", que deja anulada + reemplazo).
- "Corrección de datos" sobre un pedido partido: la factura anulada + su reemplazo, ambas en la raíz — el índice único parcial lo permite (solo una activa).
- Marcar incobrable / proponer incobrable: opera sobre la factura de la raíz (`forOrder()`).
- Registrar una nota de crédito desde "Notas Crédito y Devoluciones": el `order_id` que recibe se normaliza a la raíz (`CreditNoteService::invoiceForOrder()` vía `forOrder()`).
- Facturas duplicadas **ya existentes** en dev/test (F-0004 + F-0005…): **no se corrigen** (decisión C-8). Una cuelga de la raíz (visible), la otra de una hermana (huérfana). Los saldos inflados de esos clientes de prueba no se recalculan — QA debe validar con pedidos **nuevos**.

## Out of scope
- Migración de limpieza de las facturas duplicadas existentes (decisión C-8).
- Conciliación de las comisiones externas dobles ya pagadas (decisión C-4) — quedan en BD, salen de la vista.
- Renombrar la columna `invoices.order_id` a `order_root_id` (seguimiento cosmético opcional, otro batch).
- Facturación parcial real (una factura por entrega con monto prorrateado) — explícitamente NO es esto; es una factura por el total.
- El reparto de cantidades por entrega en el picking (eso es el Out of scope de SCRUM-875).

## Open questions
- [Arquitecto] Confirmar en Senior Review la lista completa de call sites de `AdminContInvoice::where('order_id', ...)` migrados a `forOrder()` (InvoiceService, CreditNoteService, OrderInvoiceController, InvoiceBookService, controllers).
- [Arquitecto/Backend] Verificar que `CommissionOrderService` (comisiones internas) no fan-out por familia; si joina `orders`/`invoices` por fila, aplicar el mismo `family_id IS NULL` / `anulada_at IS NULL`.
- [Backend] Revisar que las fixtures de test que asumen "1 order = 1 invoice" sigan verdes (los pedidos no partidos no cambian).

## References
- `InvoiceService.php`, `AdminContInvoice.php`, `MarkOrderInvoiceReady.php`, `OrderInvoiceController.php`
- `ArchitectCommissionService::allProjects()`
- `Order::familyRootId()`, `FamilyService`
- Migración `2026_08_24_100002_relax_order_id_unique_on_admin_contab_invoices_table.php`
- Diseño técnico completo del Arquitecto: (esta sesión, 2026-09-09 — pendiente de persistir como ADR)
- Capturas Jira SCRUM-876 (adjunto 12086), SCRUM-877 (12086/estado de cuenta), SCRUM-879 (12100/comisiones)

---
---

# SCRUM-877 parte 1 — Estado de Cuenta no refleja notas de crédito, devoluciones ni anulaciones

**Tipo:** Story · **Parent:** SCRUM-331 · **Prioridad:** Highest · **Batch:** D (después de C)
**Labels:** Admin&Contabilidad, Cobros, Estado_de_Cuenta, Facturacion, Notas_Credito_Devoluciones
**REQ-457 / REQ-460**

## Problem
El Estado de Cuenta por Cliente solo registra facturas (Débito) y pagos/cobros (Crédito). **No
muestra** descuentos, devoluciones de mercancía, anulaciones de facturas ni nada de lo registrado
en el módulo "Notas Crédito y Devoluciones". El saldo mostrado no es confiable: no resta los
descuentos/devoluciones/anulaciones que sí existen en el sistema. Riesgo de decisiones de cobro
incorrectas (saldo sobrestimado).

## Solution summary
`AccountStatementService::movements()` incorpora, además de facturas y cobros confirmados, las
**notas de crédito aplicadas** (`estado = aplicada`) sobre facturas del cliente, como filas de
crédito que entran al saldo corrido con la fecha de aprobación de Mark. Las facturas **anuladas**
(hoy excluidas) vuelven a mostrarse con una fila de anulación que revierte su débito (neto cero).
Todo esto aplica tanto en la pantalla como en el PDF descargable. Se corrige además la alineación
del subtotal (ver SCRUM-877 parte 2, Batch A).

## Users and roles
Felix, Yaneth, Mark, Gerencia (lectura de Estado de Cuenta, `permission:admin_contab.read`).

## Contexto (código actual)
- `atlanticerp-backend/app/Modules/AdminCont/Services/AccountStatementService.php::movements()` —
  construye filas solo de: Débito = `AdminContInvoice.monto` de facturas con `anulada_at IS NULL`;
  Crédito = `payment_invoices.monto_aplicado` de pagos `estado = confirmado`. El docblock ya
  anticipa: *"Notas de crédito... cuando exista [el módulo], se agregan acá con el mismo shape de
  fila"*.
- `atlanticerp-backend/app/Modules/AdminCont/Models/AdminContCreditNote.php`:
  - `tipo`: `descuento_comercial`, `devolucion_mercancia`, `anulacion_completa`
  - `resultado`: `aplicado_saldo`, `devuelto`, `saldo_favor`
  - `estado`: `aplicada`, `pendiente_aprobacion`, `rechazada`
  - `monto`, `subtotal`, `itbms`, `factura_origen_id`, `fecha`, `fecha_decision`
- `CreditNoteService::applyToBalance()` — en los 3 resultados incrementa
  `factura_origen.saldo_aplicado += min(nota.monto, saldoPendiente)`. El excedente
  (`nota.monto - saldoPendiente`): si `devuelto` → `bank_movement` (efectivo al cliente); si
  `saldo_favor` → `creditBalance->add()`; si `aplicado_saldo` → excedente 0.
- `AccountStatementService::movements()` calcula Crédito desde `payment_invoices`, **no** desde
  `invoice.saldo_aplicado` → el efecto de la nota es invisible hoy.
- `atlanticerp-backend/resources/views/admin-cont/account-statement-pdf.blade.php` — tabla de movimientos
  plana con saldo corrido, sin fila de subtotal.
- `atlanticerp-frontend/src/pages/admin-contab/EstadoCuentaPage.tsx` — `MovementsTable`, vista plana /
  agrupada por proyecto con subtotal.
- Módulo "Notas Crédito y Devoluciones": `AdminContCreditNoteController`, pantalla en
  `atlanticerp-frontend/src/pages/admin-contab/` — captura de referencia `Notas Credito y Devoluciones
  sobre Facturas.png` (Jira, adjunto 12091).

## Comportamiento esperado

### Filas nuevas en `movements()` — notas de crédito
Para cada `AdminContCreditNote` con `estado = aplicada` cuya `factura_origen` pertenece al cliente
(y al proyecto, si hay filtro):

| tipo / resultado | Fila que se agrega | Monto del crédito | Efecto en saldo |
|---|---|---|---|
| `descuento_comercial` (cualquier resultado) | tipo "Descuento" (o "Nota de crédito"), crédito | **monto completo** de la nota | reduce el saldo |
| `devolucion_mercancia` + resultado `devuelto` | tipo "Devolución", crédito | **solo `min(nota.monto, saldo_pendiente_de_la_factura_al_aplicarse)`** | reduce el saldo; el excedente devuelto en efectivo es neutro |
| `devolucion_mercancia` + resultado `aplicado_saldo` o `saldo_favor` | tipo "Devolución", crédito | **monto completo** de la nota | reduce el saldo (a favor si excede) |
| cualquier tipo + resultado `saldo_favor` | crédito | **monto completo** de la nota | reduce el saldo; puede quedar a favor (negativo) |
| `anulacion_completa` | ver abajo (fila de anulación) | = `monto` de la factura de origen | revierte el débito de esa factura → neto cero |

- **Fecha de la fila** (para el saldo corrido cronológico): `fecha_decision` si existe; si no
  (aplicación inmediata sin pasar por aprobación de Mark), `fecha`. Mismo criterio que
  `DailyCashCountService::creditNotesReturnedOn()`.
- **Columna "N° Factura"**: el número de la **factura de origen** (para que el lector cruce la nota
  con su factura). **Columna "Concepto"**: "Descuento", "Devolución" o "Anulación" según el tipo.
  **Columna "N° Cotización"**: la de la factura de origen. **Guía de entrega**: "—".
- Solo notas `estado = aplicada`. Las `pendiente_aprobacion` y `rechazada` **no** aparecen.

### Facturas anuladas
- Las facturas con `anulada_at IS NOT NULL` **vuelven a incluirse** en `movements()` (hoy se
  excluyen con `whereNull('anulada_at')`).
- Se muestran como: la fila de **Factura** original (Débito por su `monto`, fecha de emisión) **+**
  una fila de **Anulación** (Crédito por el mismo `monto`, con la fecha de la nota de crédito de
  anulación — `fecha_decision ?? fecha`).
- Efecto neto en el saldo corrido: **cero** para esa factura.
- La factura de reemplazo que genera "Corrección de datos" (si existe) entra normal como una
  factura más (Débito).

### Saldo y nota de contexto
- El `saldo` del encabezado (`detail()`) sigue siendo `totalDebito - totalCredito` de los
  movimientos — ahora con las notas de crédito incluidas, refleja el saldo real.
- `saldo_a_favor = saldo < 0` — puede activarse por una nota `saldo_favor` que exceda lo debido.
- La `contextNote` (`AccountStatementService::contextNote()`): la condición "Cuenta completada y
  pagada en su totalidad" (RN2c, hoy "nunca true todavía") ahora puede darse legítimamente cuando
  una nota de crédito lleva el saldo a 0 — el texto ya está, solo hay que dejar de asumir que no
  aplica.

### PDF y pantalla
- Todo lo anterior aplica **tanto** en la pantalla (`EstadoCuentaPage.tsx`) **como** en el PDF
  descargable (`account-statement-pdf.blade.php`).
- El PDF gana las mismas filas nuevas (Descuento / Devolución / Anulación) en su tabla de
  movimientos, con el saldo corrido recalculado.

## Acceptance criteria
- [ ] El Estado de Cuenta muestra, además de facturas (Débito) y pagos (Crédito), las notas de crédito por **devolución de mercancía** aplicadas, como Crédito.
- [ ] El Estado de Cuenta muestra los **descuentos comerciales / cortesía** aplicados a facturas, como Crédito por el monto completo de la nota.
- [ ] El Estado de Cuenta muestra las **anulaciones de facturas**: la factura original (Débito) más una fila de Anulación (Crédito por el mismo monto), con efecto neto cero en el saldo.
- [ ] Una nota de crédito cuyo excedente va a **saldo a favor** del cliente se refleja como Crédito por el monto completo, y el saldo puede quedar a favor (negativo).
- [ ] Una devolución de mercancía donde parte del monto se **devolvió en efectivo** al cliente se refleja solo por la parte que redujo la factura; el excedente en efectivo no aparece como crédito.
- [ ] Las notas de crédito **pendientes de aprobación** de Mark **no** aparecen en el Estado de Cuenta.
- [ ] Las notas de crédito **rechazadas** no aparecen.
- [ ] Cada nota de crédito entra al saldo corrido con la **fecha de aprobación/decisión** de Mark (o la de registro si fue aplicación inmediata).
- [ ] Todos estos movimientos coinciden con lo registrado en el módulo "Notas Crédito y Devoluciones" — sin discrepancias entre ambos módulos.
- [ ] El **saldo actual** del encabezado refleja el efecto de todos estos movimientos (facturas, pagos, notas de crédito, devoluciones, descuentos, anulaciones).
- [ ] Todo lo anterior se ve igual en la **vista en pantalla** y en el **PDF descargable** del Estado de Cuenta.
- [ ] El orden cronológico y el saldo corrido son correctos: las filas se ordenan por fecha y el saldo acumulado se recalcula incluyendo las notas.
- [ ] Un cliente sin ninguna nota de crédito ve el Estado de Cuenta exactamente igual que hoy.

## Edge cases y errores
- Nota de crédito sobre una factura de un pedido partido: la factura de origen es la de la raíz (post SCRUM-876) — la nota se cruza con esa.
- Nota de crédito por más del saldo pendiente de la factura, resultado `devuelto`: crédito mostrado = saldo pendiente que había; el resto (efectivo devuelto) no se muestra.
- Nota de crédito por más del saldo pendiente, resultado `saldo_favor`: crédito mostrado = monto completo; el saldo del cliente puede quedar negativo.
- Factura anulada **sin** nota de crédito de anulación asociada (caso de datos viejos / "Corrección de datos" antes de Batch 12): mostrar la factura con una pastilla "Anulada" y su fila de reversa fechada en `anulada_at`. A confirmar con el Arquitecto qué fecha usar si no hay nota.
- Filtro por rango de fechas: una nota cuya fecha de decisión cae fuera del rango no aparece, aunque su factura de origen sí (mismo criterio que hoy para cobros).
- Nota de crédito de tipo `anulacion_completa` con subtipo `correccion` (Corrección de datos): la factura original se anula y hay una factura nueva de reemplazo — mostrar ambas (original con reversa, reemplazo como débito normal).

## Out of scope
- La alineación del subtotal (SCRUM-877 parte 2, Batch A).
- Reconciliar el `saldo` del Estado de Cuenta con el ledger `AdminContCustomerCreditBalance` (saldo a favor acumulado del cliente) — este ticket solo suma las notas que afectan facturas, no rehace el modelo de saldo a favor.
- Cambiar cómo el módulo "Notas Crédito y Devoluciones" registra o aprueba notas.
- Mostrar notas de crédito que no están asociadas a ninguna factura del cliente.

## Open questions
- [Arquitecto] Factura anulada sin nota de crédito asociada (datos viejos): ¿qué fecha usa la fila de reversa — `anulada_at`?
- [Arquitecto] ¿La fila de nota de crédito muestra el N° de la nota en algún lado (tooltip / columna), o solo el N° de la factura de origen?

## References
- `AccountStatementService::movements()` / `detail()` / `contextNote()`
- `AdminContCreditNote.php`, `CreditNoteService::applyToBalance()`
- `account-statement-pdf.blade.php`, `EstadoCuentaPage.tsx`
- `DailyCashCountService::creditNotesReturnedOn()` (criterio de fecha)
- Capturas Jira SCRUM-877: `ESTADO DE CUENTA.png` (12090), `Notas Credito y Devoluciones sobre Facturas.png` (12091), `FACTURAS.png` (12088), `Cobros de Facturas.png` (12093), `factura-PED-2026-0070.pdf` (12089)

---
---

# SCRUM-879 — Pagos de comisiones no aparecen en Arqueo de Caja ni Cuentas Bancarias

**Tipo:** Story · **Parent:** SCRUM-331 · **Prioridad:** Highest · **Batch:** E (después de C)
**Labels:** Admin&Contabilidad, Arqueo_Caja, Cuentas_Bancarias · **REQ-534**

## Problem
Al marcar como "Pagada" una **comisión externa** (arquitecto), el pago **no aparece** ni en
**Arqueo de Caja → Vista Real (histórico)** ni en **Cuentas Bancarias → Movimientos**, aunque es
una salida real de dinero. Las **comisiones internas** (vendedores) tienen el mismo problema —
peor: hoy ni siquiera existe un evento de pago (el estado "pagada" se deriva solo de que el mes de
cierre ya pasó).

## Solution summary
1. **Comisión externa**: al marcar "Pagada", crear **siempre** el `bank_movement` de tipo comisión,
   con `bank_account_id` posiblemente null. El listado de Movimientos y el flujo "Seleccionar
   cuenta" (REQ-534, ya existente) lo toman desde ahí.
2. **Comisión interna**: un **job automático** genera, al cierre de cada mes, un `bank_movement`
   por vendedor por el monto de comisión de ese mes, fechado el día 1 del mes siguiente, sin cuenta
   asignada.
3. **Vista Real del Flujo de Caja y saldo de bancos**: dejan de filtrar por cuenta para los
   movimientos de comisión — un movimiento de comisión sin cuenta asignada cuenta igual como salida
   real de dinero.
4. **Backfill**: un comando genera los movimientos faltantes de las comisiones ya pagadas, desde
   la fecha de arranque del módulo Flujo de Caja (Batch 18). Corre en dev/test dentro del batch;
   en prod en ventana coordinada con Luis.

## Users and roles
- Marcar comisión externa como pagada: roster angosto de Comisiones Externas (Felix/Yaneth).
- Asignar cuenta a un movimiento de comisión: Mark/Gerencia (`PUT /bank-movements/{id}/assign-account`, ya existe).
- Ver Cuentas Bancarias / Flujo de Caja: Felix, Yaneth, Mark, Gerencia (con las restricciones ya existentes por vista).
- El job de comisiones internas: sistema (sin usuario).

## Contexto (código actual)
- `atlanticerp-backend/app/Modules/AdminCont/Services/ArchitectCommissionService.php::marcarComoPagada()`
  — crea el `bank_movement` **solo si `$commission->bank_account_id !== null`**. Por eso Arquitecto
  12 (pagado sin cuenta) no generó movimiento.
- `atlanticerp-backend/app/Modules/AdminCont/Services/CommissionOrderService.php::estadoFor()` — comisión
  interna "pagada" = `$mesCierre->lt($currentMonth)`. **No hay acción de pago, fecha, ni registro.**
- `atlanticerp-backend/app/Modules/AdminCont/Models/CommissionMonthSnapshot.php` — `(vendedor_id,
  mes_cierre, total_pedidos, porcentaje, monto_comision)`, creado **de forma perezosa** vía
  `CommissionOrderService::percentForVendorMonth()` la primera vez que se consulta un mes cerrado
  (mismo gotcha que SCRUM-867).
- `atlanticerp-backend/app/Modules/AdminCont/Models/AdminContBankMovement.php` — `TIPO_COMISION` ya
  existe; `SOURCE_ARCHITECT_COMMISSION` ya existe; **falta** un `SOURCE_INTERNAL_COMMISSION`.
- `atlanticerp-backend/app/Modules/AdminCont/Http/Controllers/AdminContBankMovementController.php` —
  `index()` ya devuelve todos los movimientos sin filtrar por cuenta; `assignAccount()` ya permite
  asignar cuenta a un movimiento de `tipo = comision` con `bank_account_id` null (sirve para
  externas e internas).
- `atlanticerp-backend/app/Modules/AdminCont/Services/CashPositionService.php`:
  - `bankBalance()` — filtra `whereIn('bank_account_id', $activeAccountIds)`.
  - `real()` — filtra `whereIn('bank_account_id', $activeAccountIds)`.
  - `netFlowForWindow()` (Reportes → Arqueo de Caja) — filtra `whereIn('bank_account_id', $activeAccountIds)`.
  - `projected()` — ya incluye comisiones internas y externas pendientes (sin cambio).
- Mockup de referencia: `EJEMPLO MOCKUP.png` (Jira SCRUM-879, adjunto 12099) — muestra movimientos
  "Comisión interna pagada — [vendedor] ([mes])" y "Comisión externa pagada — Arq. [nombre]
  ([mes])" con "Sin cuenta asignada" + botón "Seleccionar cuenta".

## Comportamiento esperado

### 1. Comisión externa — `marcarComoPagada()`
- Crear **siempre** el `AdminContBankMovement`:
  - `tipo = TIPO_COMISION`, `source_type = SOURCE_ARCHITECT_COMMISSION`, `source_id = commission.id`
  - `bank_account_id` = `$commission->bank_account_id` (puede ser null)
  - `fecha` = `now()->toDateString()` (fecha del pago)
  - `concepto` = "Comisión arquitecto [nombre] — [N° pedido]"
  - `monto` = el que se paga (mismo cálculo actual: `$montos['total'] ?? $montos['comision']`)
- Idempotencia: no crear un segundo movimiento si ya existe uno para ese `source_id`.

### 2. Comisión interna — job automático
- Un comando programado (ej. `commissions:settle-internal`, diario o mensual — decisión del
  Arquitecto sobre la cadencia; debe correr al menos una vez entre el día 1 y el día 5 de cada mes).
- Para cada mes ya cerrado que aún no fue liquidado:
  - Fuerza la creación de los `CommissionMonthSnapshot` de todos los vendedores con comisión ese
    mes (no depende de que alguien haya abierto la pantalla).
  - Por cada snapshot con `monto_comision > 0`, crea **un** `AdminContBankMovement`:
    - `tipo = TIPO_COMISION`, `source_type = SOURCE_INTERNAL_COMMISSION` (constante nueva),
      `source_id = snapshot.id`
    - `bank_account_id = null`
    - `fecha` = **día 1 del mes siguiente** al `mes_cierre`
    - `concepto` = "Comisión interna [vendedor] ([mes de cierre, ej. jun 2026])"
    - `monto` = `snapshot.monto_comision`
  - Idempotente por `source_id` (no duplica si se re-corre).
- El estado "pagada" que ya deriva `estadoFor()` se mantiene — este job **no cambia** cómo se
  muestra la comisión en la pantalla de Comisiones Internas, solo genera el movimiento de caja.

### 3. Cuentas Bancarias → Movimientos
- Los movimientos de comisión (externa e interna), con o sin cuenta, aparecen en el listado
  (`AdminContBankMovementController::index()` ya no filtra por cuenta — sin cambio de código ahí).
- Un movimiento sin `bank_account_id` muestra "Sin cuenta asignada" + botón "Seleccionar cuenta"
  (frontend). Al asignar cuenta (`assignAccount`, ya existe), el movimiento queda categorizado.
- El tipo se muestra como "Comisión" en la columna Tipo/Concepto (hoy solo aparecen Cobro y Devolución).

### 4. Vista Real del Flujo de Caja + saldo de bancos
- `CashPositionService::real()`, `bankBalance()` y `netFlowForWindow()` incluyen los movimientos de
  `tipo = comision` **aunque `bank_account_id` sea null** (además de los de cuentas activas).
  Concretamente: el criterio pasa de `bank_account_id IN (activas)` a
  `bank_account_id IN (activas) OR (tipo = 'comision' AND bank_account_id IS NULL)`.
- La comisión pagada aparece como **salida** en la Vista Real, en la fecha del pago (externa) o el
  día 1 del mes siguiente (interna).
- La Vista Proyectado no cambia (ya proyecta comisiones pendientes).
- Al asignar la cuenta a un movimiento de comisión, el saldo de esa cuenta lo incorpora; el saldo
  total de bancos no cambia (ya estaba contado).

### 5. Backfill
- Comando (ej. `commissions:backfill-cash-movements`):
  - **Comisiones externas** ya pagadas (`architect_commissions.fecha_pago IS NOT NULL`) desde la
    fecha de corte: crea el movimiento faltante con `fecha = fecha_pago`, `bank_account_id` =
    la cuenta que tenga la comisión (puede ser null).
  - **Comisiones internas** de meses cerrados desde la fecha de corte: fuerza los snapshots y crea
    los movimientos con `fecha = día 1 del mes siguiente`.
  - **Fecha de corte** = fecha de arranque del módulo Flujo de Caja / Arqueo (Batch 18) — el
    Arquitecto define el valor exacto (ej. `arqueo_tracking_start_date` de Configuración Fiscal, o
    una constante del comando).
  - Idempotente (por `source_id`) — se puede re-correr sin duplicar.
- **dev + test**: corre dentro del batch (deploy normal).
- **prod**: corre por separado, en ventana coordinada con Luis, con captura del saldo de bancos y
  del flujo de caja antes y después.

### 6. Comisiones internas — verificación de scope
- Confirmar (Arquitecto/Backend) que el mockup pide 1 movimiento por vendedor por mes (no por
  pedido) — el mockup dice "Comisión interna pagada — [vendedor] ([mes])", lo que calza con
  `CommissionMonthSnapshot` (1 fila por vendedor por mes).

## Acceptance criteria
- [ ] Al marcar una comisión externa como "Pagada" **sin cuenta bancaria asignada**, se crea un movimiento de comisión que aparece en Cuentas Bancarias → Movimientos con "Sin cuenta asignada" y botón "Seleccionar cuenta".
- [ ] Al marcar una comisión externa como "Pagada" **con cuenta asignada**, el movimiento aparece asociado a esa cuenta (comportamiento actual, sin regresión).
- [ ] El movimiento de comisión externa pagada aparece en Arqueo de Caja → Vista Real (histórico), en la fecha del pago, como salida real de dinero.
- [ ] Las comisiones externas **no pagadas** siguen apareciendo solo en la Vista Proyectado, sin mezclarse con la Vista Real.
- [ ] Al cerrar un mes, el job genera un movimiento de salida por cada comisión interna del mes (1 por vendedor), fechado el día 1 del mes siguiente, sin cuenta asignada.
- [ ] Ese movimiento de comisión interna aparece en Cuentas Bancarias → Movimientos y en Arqueo de Caja → Vista Real.
- [ ] Un movimiento de comisión **sin cuenta asignada** cuenta en el saldo de bancos y en la Vista Real desde su fecha; al asignarle una cuenta, esa cuenta lo incorpora sin cambiar el saldo total.
- [ ] El listado de Movimientos muestra el tipo "Comisión" (además de Cobro y Devolución).
- [ ] El job es idempotente: correrlo dos veces no duplica movimientos.
- [ ] `marcarComoPagada()` es idempotente: no crea un segundo movimiento para la misma comisión.
- [ ] El backfill genera los movimientos de las comisiones ya pagadas desde la fecha de corte, con su fecha real, sin duplicar si se re-corre.
- [ ] El backfill corrió y se verificó en dev y test; la corrida en prod queda documentada como paso aparte pendiente de coordinación con Luis.
- [ ] El "Arqueo N° X" descargable (SCRUM-878) **no** incluye comisiones — su alcance no cambia.
- [ ] La pantalla de Comisiones Internas / Externas se ve igual que hoy (el job no cambia cómo se muestra el estado de la comisión).

## Edge cases y errores
- Comisión externa pagada, luego se le asigna cuenta: el movimiento (ya creado con cuenta null) se actualiza vía `assignAccount` — no se crea uno nuevo.
- Comisión externa de un pedido que estaba duplicado por SCRUM-876: tras el fix de 876, la comisión es una sola línea; si ya se había pagado dos veces, el backfill crea el movimiento de la que quede visible (decisión C-4 — la otra queda en BD sin movimiento nuevo).
- Mes cerrado sin ninguna comisión interna: el job no crea nada para ese mes.
- Vendedor con `monto_comision = 0` en un mes: no se crea movimiento.
- Job corre dos veces el mismo mes (cron duplicado / re-deploy): idempotente por `source_id`.
- `commission_month_snapshot` ya existente (creado perezosamente antes de que corra el job): el job lo usa tal cual, no lo recrea.
- Movimiento de comisión con cuenta null en `bankBalance()`: cuenta igual (decisión E-3) — verificar que la Vista Real cierre exactamente en `bankBalance()` (mismo invariante que ya cuida `real()`).
- Comisión interna de un mes anterior a la fecha de corte: **no** se le genera movimiento (ni el job ni el backfill).

## Out of scope
- Cambiar cómo se calcula el monto de comisión interna o externa.
- Una acción manual de "liquidar comisiones internas" (decisión E-1: es automático por job).
- Un setting de "cuenta bancaria por defecto para comisiones" (decisión E-6: van sin cuenta).
- Incluir comisiones en el documento "Arqueo N° X" (decisión E-4).
- Retención / régimen fiscal de comisiones internas (los vendedores no tienen ese tratamiento; se usa `snapshot.monto_comision` tal cual).
- Notificar a alguien cuando el job genera movimientos.

## Open questions
- [Arquitecto] Cadencia exacta del job (`commissions:settle-internal`): ¿diario con guard de fecha, o mensual el día 1? ¿Se registra en `schedule()` de `Console\Kernel` o como Horizon job?
- [Arquitecto] Valor exacto de la "fecha de corte" del backfill (¿`arqueo_tracking_start_date`? ¿una constante?).
- [Arquitecto] Nombre y ubicación de la constante `SOURCE_INTERNAL_COMMISSION` y del `source_id` (¿`commission_month_snapshots.id`?).
- [Backend] Confirmar que `assignAccount` acepta movimientos con `source_type = SOURCE_INTERNAL_COMMISSION` (hoy chequea `tipo === TIPO_COMISION`, que se cumple — verificar que no haya otro guard).

## References
- `ArchitectCommissionService::marcarComoPagada()`
- `CommissionOrderService::estadoFor()` / `percentForVendorMonth()`, `CommissionMonthSnapshot.php`
- `AdminContBankMovement.php` (TIPO_COMISION, SOURCE_*), `AdminContBankMovementController.php`
- `CashPositionService::real()` / `bankBalance()` / `netFlowForWindow()` / `projected()`
- `docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md`, `docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md`
- Capturas Jira SCRUM-879: `EJEMPLO MOCKUP.png` (12099), comisiones actual (12100), cuentas bancarias actual (12098)

---
---

## Quality checklist (feature-spec)
- [x] Cada criterio de aceptación es verificable por QA
- [x] Roles y permisos explícitos por ticket
- [x] Al menos 3 escenarios de error documentados por ticket
- [x] Sección "Out of scope" en cada ticket
- [x] Open questions marcadas con responsable; ninguna bloquea el diseño del Arquitecto (las abiertas son de detalle de implementación)

## Próximos pasos
1. Aprobación de Luis de esta spec.
2. Diseño del Arquitecto por batch (el de C ya está esbozado en la sesión 2026-09-09 — persistir como ADR; D y E necesitan el suyo).
3. PM desglosa en tareas y asigna. Orden sugerido: C → (A ∥ B) → D → E.
