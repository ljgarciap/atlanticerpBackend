# Spec Analista — SCRUM-928 → 935 · Correcciones y mejoras UAT Admin&Contabilidad

Analista, 2026-09-18. Reportadas por Gerencia Test, épica SCRUM-331. Los criterios de aceptación
literales viven en cada ticket de Jira — este documento NO los duplica: registra las decisiones
tomadas, el alcance técnico real verificado contra el código, y los casos negativos que Pre-QA
debe intentar romper.

## Decisiones cerradas (Luis, 2026-09-18, "procede" sobre las recomendaciones del Arquitecto)

| # | Ticket | Decisión |
|---|---|---|
| D-1 | 929 | Se reemplaza la regla "1 factura activa por pedido" del ADR SCRUM-875/876 (C-7). ADR nuevo lo supera: `ADR-SCRUM929-facturacion-parcial-o-completa.md`. |
| D-2 | 929 | "Facturar pedido completo" factura el **restante no facturado** mientras quede saldo; si no hay parciales previas, es el total. Nunca excede el total de la cotización. |
| D-3 | 929 | Una entrega es facturable cuando llega a `POR_DESPACHAR` (regla actual, sin cambio). |
| D-4 | 929 | Comisiones (internas y externas) se calculan **por factura sobre su monto proporcional**; la suma por proyecto es igual a la del pedido completo. |
| D-5 | 930 | El número de guía se **deriva** del folio del pedido: `#PED-2026-0075-G`; en pedido con entregas parciales `-G1`, `-G2`… por `sequence_in_family`. No se numera `OrderDocument`. |
| D-6 | 928 | Nunca se muestra `****XXXX` en documentos al cliente. Sin `numero_cuenta_completo` cargado, el campo muestra "—". DV=81 y número de cuenta se cargan por seeder idempotente **solo si están vacíos**. |
| D-7 | 928/934 | DV obligatorio **solo al crear** cliente/subcliente (existentes sin DV se toleran; backfill aparte). "Facturado del día" = facturas creadas ese día; "pasado a pedido" = cotizaciones convertidas a pedido ese día. Día = `America/Panama`. |

**Dato de la cuenta bancaria (resuelto 2026-09-18):** Luis aportó el número completo y pidió que viva
en un seeder, no en una variable de entorno (`AdminContBankAccountSeeder`, mismo patrón que
`AdminContFiscalIdentitySeeder`). Siembra las 4 cuentas que tiene `test` (Banco General 4554 con su
número completo, Banistmo tarjeta 9034, y Bancolombia 4534 y Davivienda 0032 inactivas). Crea las que
falten (prod no tenía ninguna), rellena el número de Banco General si está vacío y nunca pisa un
número cargado, ni reactiva una cuenta desactivada, ni duplica. El orden importa: la Factura usa la
cuenta activa más antigua. El mecanismo por variable de entorno (`ADMIN_CONTAB_BANK_ACCOUNT_NUMBER`)
se eliminó.

## Hallazgos verificados en el código (base de la spec)

- 928.1: `resources/views/admin-cont/invoice-pdf.blade.php:67` ya imprime `RUC {ruc}-{dv}` cuando
  `fiscal_settings.dv` no está vacío → es dato, no código.
- 928.3 / 930.2: `InvoiceService::paymentBlock()` ya usa `numero_cuenta_completo` (SCRUM-857) y solo
  cae a `****ultimos_4_digitos` si está vacío. `EstadoCuentaPage.tsx:282` lee `statement.numero_cuenta`
  sin máscara propia → el fallback del backend es la única fuente del asterisco.
- 928.2: el RUC vive en `SubClient.tax_id`. La columna `sub_clients.tax_id_dv` existe (migración
  `2026_09_17_190000`, nullable) pero no está en `$fillable` ni en ningún request/formulario.
- 929: `AdminContInvoice.order_id` = tarjeta raíz, `monto = card->amount`, índice único parcial
  `invoices_order_id_active_unique (order_id) WHERE anulada_at IS NULL`.
- 930.1: `AccountStatementService::movements()` devuelve `guia_entrega` como bool.
- 935: los exports usan `number_format(...)` → texto, no celdas numéricas.

## Criterios adicionales del Analista (además de los del ticket)

**928** — El DV no se pide en el modal si el usuario elige un subcliente existente (solo en alta).
Validación: 1–2 dígitos numéricos. El DV capturado aparece en el detalle del cliente.

**929** — Ver ADR. Casos negativos obligatorios para Pre-QA:
1. Parcial + "pedido completo" simultáneos (2 requests concurrentes) → exactamente una gana.
2. "Pedido completo" cuando ya hay parcial(es) → factura solo el restante; suma = total exacto al centavo.
3. Después de "pedido completo" (o de facturar todas las entregas) → API rechaza cualquier factura
   adicional aunque la UI esté manipulada (no solo botón deshabilitado).
4. Entrega única → sin cambio (una factura, todos los productos).
5. Factura anulada libera saldo facturable de esa entrega.
6. Redondeo con 3 entregas y ITBMS: última factura absorbe el residual; Σ = total de cotización.
7. Pedidos existentes (facturas previas) quedan como `completo`; ninguna opción parcial aparece.
8. Comisión por proyecto con N facturas = comisión con 1 factura completa.
9. Faltante (entrega parcial en Bodega, con tarjeta de Faltante o de Revisión de Inventario): la
   entrega original factura solo `qty_delivered`; el remanente se factura con su propia entrega.
   Si el remanente nunca se despacha, no se cobra (no hay residual sobre mercadería que no salió).
   Incluye el caso de Revisión de Inventario "mixto" (ítems que se mueven a otra tarjeta) — el
   vínculo a la cotización y el precio deben sobrevivir a esa división.
10. Pedido con ítems sin vínculo a la cotización (creado antes del cambio): solo admite "pedido
    completo"; la API rechaza facturación parcial aunque se fuerce el request.
11. Producto repetido en 2 partes de la cotización o ítem personalizado (sin `catalog_product_id`):
    cada línea se factura contra su propio ítem, sin mezclarse.

**930** — Cada número de documento clickeable abre su modal sin salir de la pantalla. Si una fila no
tiene guía (entrega no despachada), la celda muestra "—", no un link roto.

**931/932/933** — Si la combinación Cliente+Proyecto+Cotización tiene **N facturas** (consecuencia
de 929), se muestra un selector de factura; nunca se toma "la primera". Cambiar Cliente Master limpia
Proyecto y Cotización.

**934** — Los indicadores cuadran con la suma de la tabla de movimientos del mismo día; un arqueo
generado a las 10:00 y regenerado a las 15:00 muestra valores distintos (estado "al momento de crear").

**935** — Celdas numéricas reales (sumables en Excel), formato `$#,##0.00`; la columna "Media"
desaparece de Excel **y** de la tabla en pantalla en los 2 reportes indicados; totales incluidos.

## Fuera de alcance

Backfill de DV para clientes existentes · facturación electrónica PAC (decisión MSL vs Digifact
sigue pendiente del cliente; solo se cuida que el mapper pueda leer líneas de factura) · numeración
propia de guías.
